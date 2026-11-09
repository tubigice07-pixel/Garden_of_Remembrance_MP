<?php
/**
 * Installment Plan & Payment Schedule Helper
 * Garden of Remembrance Memorial Park
 */

if (!function_exists('get_installment_rate')) {
    /**
     * Map installment term to official monthly fee
     *
     * 12 Months: ₱7,226.00 / mo
     * 24 Months: ₱4,495.00 / mo
     * 36 Months: ₱3,336.00 / mo
     * 48 Months: ₱2,772.00 / mo
     * 60 Months: ₱2,446.00 / mo
     */
    function get_installment_rate(int $term_months, float $plot_price = 85000.00): float {
        $term_months = max(1, $term_months);
        $downpayment = round($plot_price * 0.20, 2);
        $remaining = round($plot_price - $downpayment, 2);
        return round($remaining / $term_months, 2);
    }
}

if (!function_exists('get_all_installment_rates')) {
    function get_all_installment_rates(float $plot_price = 85000.00): array {
        $downpayment = round($plot_price * 0.20, 2);
        $remaining = round($plot_price - $downpayment, 2);
        $terms = [
            12 => '12 Months (1 Year)',
            24 => '24 Months (2 Years)',
            36 => '36 Months (3 Years)',
            48 => '48 Months (4 Years)',
            60 => '60 Months (5 Years)'
        ];
        $result = [];
        foreach ($terms as $t => $lbl) {
            $mFee = round($remaining / $t, 2);
            $result[$t] = [
                'term_months' => $t,
                'label' => $lbl,
                'monthly_fee' => $mFee,
                'downpayment' => $downpayment,
                'total_cost' => round($plot_price, 2),
                'initial_payment' => $downpayment,
            ];
        }
        return $result;
    }
}

if (!function_exists('calculate_installment_plan')) {
    function calculate_installment_plan(int $term_months, int $num_lots = 1, float $plot_price = 85000.00): array {
        $term_months = max(1, $term_months);
        $num_lots = max(1, $num_lots);
        $total_plot_price = round($plot_price * $num_lots, 2);
        // 20% downpayment of plot price (excluding cemetery service)
        $downpayment = round($total_plot_price * 0.20, 2);
        $remaining_principal = round($total_plot_price - $downpayment, 2);
        $monthly_fee = round($remaining_principal / $term_months, 2);

        return [
            'term_months' => $term_months,
            'num_lots' => $num_lots,
            'downpayment' => $downpayment,
            'monthly_fee' => $monthly_fee,
            'total_plan_cost' => $total_plot_price,
            'initial_down_payment' => $downpayment,
            'projected_remaining_balance' => $remaining_principal,
        ];
    }
}

if (!function_exists('ensure_installment_tables')) {
    /**
     * Self-healing creator for payment_schedules and booking_payment_schedule tables.
     */
    function ensure_installment_tables(mysqli $conn): bool {
        if (!$conn) return false;
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS `payment_schedules` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `contract_id` int(11) NOT NULL,
              `installment_number` int(11) NOT NULL DEFAULT 1,
              `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
              `monthly_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
              `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
              `remaining_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
              `term_months` int(11) NOT NULL DEFAULT 12,
              `status` enum('pending','paid','overdue') NOT NULL DEFAULT 'pending',
              `due_date` date NOT NULL,
              `user_email` varchar(255) NOT NULL,
              `paid_at` datetime DEFAULT NULL,
              `payment_method` varchar(50) DEFAULT NULL,
              `transaction_reference` varchar(100) DEFAULT NULL,
              `reminder_sent_at` datetime DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_user` (`user_id`),
              KEY `idx_contract` (`contract_id`),
              KEY `idx_status_due` (`status`,`due_date`),
              KEY `idx_user_email` (`user_email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $conn->query("CREATE TABLE IF NOT EXISTS `booking_payment_schedule` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `booking_id` int(11) NOT NULL,
              `installment_number` int(11) NOT NULL,
              `due_date` date NOT NULL,
              `amount_due` decimal(10,2) NOT NULL,
              `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
              `status` varchar(20) NOT NULL DEFAULT 'UNPAID',
              `paid_at` datetime DEFAULT NULL,
              `payment_method` varchar(50) DEFAULT NULL,
              `transaction_reference` varchar(100) DEFAULT NULL,
              `gcash_reference_number` varchar(100) DEFAULT NULL,
              `payment_receipt_id` int(11) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_booking` (`booking_id`),
              KEY `idx_bps_tx_ref` (`transaction_reference`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return true;
        } catch (Throwable $e) {
            error_log('ensure_installment_tables error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('generate_contract_payment_schedule')) {
    /**
     * Generate or synchronize payment schedule records for a booking contract.
     *
     * @param int $contract_id The booking ID
     * @param mysqli $conn Active database connection
     * @param bool $initial_paid Whether the 1st installment is confirmed paid
     * @param string|null $payment_method e.g. 'GCash', 'Cash on Site'
     * @param string|null $reference Transaction reference number
     * @return bool True on success
     */
    function generate_contract_payment_schedule(
        int $contract_id, 
        mysqli $conn, 
        bool $initial_paid = false, 
        ?string $payment_method = null, 
        ?string $reference = null
    ): bool {
        if ($contract_id <= 0 || !$conn) {
            return false;
        }

        ensure_installment_tables($conn);

        try {
        // Fetch contract details
        $stmt = $conn->prepare("SELECT id, user_id, customer_email, email, total_amount, plot_price, payment_plan, payment_term, num_lots, created_at, preferred_date, amount_paid FROM tbl_bookings WHERE id = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('i', $contract_id);
        $stmt->execute();
        $b = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$b) return false;

        $payment_plan = strtolower(trim((string)($b['payment_plan'] ?? 'full')));
        if ($payment_plan !== 'installment') {
            return false; // Only installment bookings have amortization schedules
        }

        $term_months = (int)($b['payment_term'] ?? 12);
        if ($term_months <= 0) $term_months = 12;

        $num_lots = max(1, (int)($b['num_lots'] ?? 1));
        $plot_price = (float)($b['plot_price'] ?? 85000.00);
        if ($plot_price <= 0) $plot_price = 85000.00;
        $plan = calculate_installment_plan($term_months, $num_lots, $plot_price);

        $user_id = (int)($b['user_id'] ?? 1);
        $user_email = strtolower(trim((string)($b['customer_email'] ?? $b['email'] ?? '')));
        if (empty($user_email)) {
            // Lookup user email
            $uRes = $conn->query("SELECT email FROM tbl_user WHERE user_id = $user_id LIMIT 1");
            if ($uRes && ($uRow = $uRes->fetch_assoc())) {
                $user_email = strtolower(trim((string)$uRow['email']));
            }
        }

        $total_amount = $plan['total_plan_cost'];
        $monthly_amount = $plan['monthly_fee'];
        $start_date = !empty($b['created_at']) ? strtotime($b['created_at']) : time();

        // Preserve existing contract total_amount or populate if missing
        if ((float)$b['total_amount'] <= 0) {
            $upTotal = $conn->prepare("UPDATE tbl_bookings SET total_amount = ? WHERE id = ?");
            if ($upTotal) {
                $upTotal->bind_param('di', $total_amount, $contract_id);
                $upTotal->execute();
                $upTotal->close();
            }
        } else {
            $total_amount = (float)$b['total_amount'];
        }

        // Check if schedules already exist in payment_schedules
        $existing = 0;
        $chk = $conn->query("SELECT COUNT(*) AS c FROM payment_schedules WHERE contract_id = $contract_id");
        if ($chk && ($cRow = $chk->fetch_assoc())) {
            $existing = (int)$cRow['c'];
        }

        if ($existing === 0) {
            // Generate full schedule for 1..term_months (Installments start next month on exact day, following initial downpayment)
            for ($i = 1; $i <= $term_months; $i++) {
                $due_timestamp = strtotime("+ " . $i . " month", $start_date);
                $due_date = date('Y-m-d', $due_timestamp);

                $is_first = ($i === 1);
                $is_paid = ($is_first && $initial_paid);

                $paid_amt = $is_paid ? $monthly_amount : 0.00;
                $rem_bal = $total_amount - ($is_paid ? $monthly_amount : 0.00);
                $status = $is_paid ? 'paid' : (strtotime($due_date) < strtotime(date('Y-m-d')) ? 'overdue' : 'pending');
                $paid_at = $is_paid ? date('Y-m-d H:i:s') : null;
                $p_method = $is_paid ? ($payment_method ?: 'GCash') : null;
                $p_ref = $is_paid ? ($reference ?: null) : null;

                $ins = $conn->prepare("INSERT INTO payment_schedules 
                    (user_id, contract_id, installment_number, total_amount, monthly_amount, paid_amount, remaining_balance, term_months, status, due_date, user_email, paid_at, payment_method, transaction_reference) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($ins) {
                    $ins->bind_param(
                        'iiiddddissssss',
                        $user_id,
                        $contract_id,
                        $i,
                        $total_amount,
                        $monthly_amount,
                        $paid_amt,
                        $rem_bal,
                        $term_months,
                        $status,
                        $due_date,
                        $user_email,
                        $paid_at,
                        $p_method,
                        $p_ref
                    );
                    $ins->execute();
                    $ins->close();
                }

                // Synchronize into legacy booking_payment_schedule for legacy dashboard views
                $insLegacy = $conn->prepare("INSERT INTO booking_payment_schedule 
                    (booking_id, installment_number, due_date, amount_due, amount_paid, status, paid_at, payment_method, transaction_reference) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($insLegacy) {
                    $legacy_status = strtoupper($status);
                    $legacy_p_method = $p_method ?: '';
                    $legacy_p_ref = $p_ref ?: '';
                    $insLegacy->bind_param(
                        'iisddssss',
                        $contract_id,
                        $i,
                        $due_date,
                        $monthly_amount,
                        $paid_amt,
                        $legacy_status,
                        $paid_at,
                        $legacy_p_method,
                        $legacy_p_ref
                    );
                    $insLegacy->execute();
                    $insLegacy->close();
                }
            }
        } elseif ($initial_paid) {
            // Update 1st installment to paid if not yet marked
            $up1 = $conn->prepare("UPDATE payment_schedules SET status = 'paid', paid_amount = ?, paid_at = NOW(), payment_method = ?, transaction_reference = ? WHERE contract_id = ? AND installment_number = 1 AND status != 'paid'");
            if ($up1) {
                $p_method = $payment_method ?: 'GCash';
                $up1->bind_param('dssi', $monthly_amount, $p_method, $reference, $contract_id);
                $up1->execute();
                $up1->close();
            }

            // Sync legacy
            $upLeg = $conn->prepare("UPDATE booking_payment_schedule SET status = 'PAID', amount_paid = ?, paid_at = NOW(), payment_method = ?, transaction_reference = ? WHERE booking_id = ? AND installment_number = 1 AND status != 'PAID'");
            if ($upLeg) {
                $p_method = $payment_method ?: 'GCash';
                $upLeg->bind_param('dssi', $monthly_amount, $p_method, $reference, $contract_id);
                $upLeg->execute();
                $upLeg->close();
            }

            // Recalculate remaining balances on all schedule records for this contract
            update_contract_remaining_balances($contract_id, $conn);
        }

            return true;
        } catch (Throwable $e) {
            error_log("generate_contract_payment_schedule error for contract #$contract_id: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('update_contract_remaining_balances')) {
    /**
     * Recalculates and updates remaining_balance across all schedule records and tbl_bookings.
     */
    function update_contract_remaining_balances(int $contract_id, mysqli $conn): array {
        $totalPaid = 0.00;
        $totalAmount = 0.00;
        $termMonths = 12;

        // Sum confirmed paid payments from payment_receipts
        $prRes = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) AS total_receipt_paid FROM payment_receipts WHERE booking_id = $contract_id AND UPPER(COALESCE(payment_status, '')) IN ('PAID', 'VERIFIED')");
        $receiptPaid = ($prRes && ($prRow = $prRes->fetch_assoc())) ? (float)$prRow['total_receipt_paid'] : 0.00;

        // Sum confirmed paid schedules
        $res = $conn->query("SELECT SUM(paid_amount) AS total_paid, MAX(total_amount) AS total_amt, MAX(term_months) AS t_months FROM payment_schedules WHERE contract_id = $contract_id");
        if ($res && ($row = $res->fetch_assoc())) {
            $schedPaid = (float)($row['total_paid'] ?? 0.00);
            $totalAmount = (float)($row['total_amt'] ?? 0.00);
            $termMonths = (int)($row['t_months'] ?? 12);
        } else {
            $schedPaid = 0.00;
        }

        $totalPaid = max($receiptPaid, $schedPaid);

        if ($totalAmount <= 0) {
            $bRes = $conn->query("SELECT total_amount, payment_term, num_lots FROM tbl_bookings WHERE id = $contract_id");
            if ($bRes && ($bRow = $bRes->fetch_assoc())) {
                $pTerm = (int)($bRow['payment_term'] ?? 12);
                $nLots = max(1, (int)($bRow['num_lots'] ?? 1));
                $calc = calculate_installment_plan($pTerm, $nLots);
                $totalAmount = $calc['total_plan_cost'];
                $termMonths = $pTerm;
            }
        }

        $remainingBalance = max(0.00, round($totalAmount - $totalPaid, 2));

        // Update all schedules for this contract with current remaining balance
        $up = $conn->prepare("UPDATE payment_schedules SET remaining_balance = ? WHERE contract_id = ?");
        if ($up) {
            $up->bind_param('di', $remainingBalance, $contract_id);
            $up->execute();
            $up->close();
        }

        // Update tbl_bookings: set remaining_balance and enforce payment_status = 'PAID' when fully paid
        if ($remainingBalance <= 0.001 || ($totalAmount > 0 && $totalPaid >= $totalAmount)) {
            $remainingBalance = 0.00;
            $upBk = $conn->prepare("UPDATE tbl_bookings SET amount_paid = ?, total_amount = ?, remaining_balance = 0.00, payment_status = 'PAID' WHERE id = ?");
            if ($upBk) {
                $upBk->bind_param('ddi', $totalPaid, $totalAmount, $contract_id);
                $upBk->execute();
                $upBk->close();
            }
        } else {
            $upBk = $conn->prepare("UPDATE tbl_bookings SET amount_paid = ?, total_amount = ?, remaining_balance = ? WHERE id = ?");
            if ($upBk) {
                $upBk->bind_param('dddi', $totalPaid, $totalAmount, $remainingBalance, $contract_id);
                $upBk->execute();
                $upBk->close();
            }
        }

        return [
            'total_amount' => $totalAmount,
            'total_paid' => $totalPaid,
            'remaining_balance' => $remainingBalance,
            'term_months' => $termMonths,
        ];
    }
}

if (!function_exists('record_installment_payment')) {
    /**
     * Records a payment toward an installment (next pending or specific schedule_id).
     */
    function record_installment_payment(
        int $contract_id, 
        float $amount, 
        string $payment_method, 
        string $reference, 
        mysqli $conn,
        ?int $schedule_id = null
    ): array {
        if ($contract_id <= 0 || $amount <= 0 || !$conn) {
            return ['success' => false, 'message' => 'Invalid payment parameters.'];
        }

        // Find target schedule record
        $target = null;
        if ($schedule_id && $schedule_id > 0) {
            $stmt = $conn->prepare("SELECT * FROM payment_schedules WHERE id = ? AND contract_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $schedule_id, $contract_id);
                $stmt->execute();
                $target = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        }

        if (!$target) {
            // Grab the next pending/overdue installment in sequence
            $stmt = $conn->prepare("SELECT * FROM payment_schedules WHERE contract_id = ? AND status != 'paid' ORDER BY installment_number ASC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $contract_id);
                $stmt->execute();
                $target = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        }

        if (!$target) {
            return ['success' => false, 'message' => 'All installments for this contract are already paid!'];
        }

        $sched_id = (int)$target['id'];
        $inst_num = (int)$target['installment_number'];

        // Mark schedule as paid
        $up = $conn->prepare("UPDATE payment_schedules SET status = 'paid', paid_amount = ?, paid_at = NOW(), payment_method = ?, transaction_reference = ? WHERE id = ?");
        if ($up) {
            $up->bind_param('dssi', $amount, $payment_method, $reference, $sched_id);
            $up->execute();
            $up->close();
        }

        // Sync legacy table
        $upLeg = $conn->prepare("UPDATE booking_payment_schedule SET status = 'PAID', amount_paid = ?, paid_at = NOW(), payment_method = ?, transaction_reference = ? WHERE booking_id = ? AND installment_number = ?");
        if ($upLeg) {
            $upLeg->bind_param('dssii', $amount, $payment_method, $reference, $contract_id, $inst_num);
            $upLeg->execute();
            $upLeg->close();
        }

        // Record individual transaction into payment_receipts without overwriting prior installments
        $user_id = (int)$target['user_id'];
        $isGcash = (stripos($payment_method, 'gcash') !== false);
        $gcashRef = $isGcash ? $reference : null;
        $txRef = $reference;

        $rcpt = $conn->prepare("INSERT INTO payment_receipts 
            (booking_id, user_id, payment_schedule_id, installment_number, amount_paid, payment_method, gcash_reference_number, transaction_reference, payment_date, payment_status, created_at, verified_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'PAID', NOW(), NOW())");
        $payment_id = 0;
        if ($rcpt) {
            $rcpt->bind_param('iiiidsss', $contract_id, $user_id, $sched_id, $inst_num, $amount, $payment_method, $gcashRef, $txRef);
            $rcpt->execute();
            $payment_id = (int)$rcpt->insert_id;
            $rcpt->close();

            $rcptNumber = sprintf("RCPT-%s-%04d", date('Ymd'), $payment_id);
            $conn->query("UPDATE payment_receipts SET receipt_number = '$rcptNumber' WHERE id = $payment_id");
        }

        // Recalculate remaining balance
        $summary = update_contract_remaining_balances($contract_id, $conn);

        // Dispatch in-system notification and payment receipt email
        if (file_exists(__DIR__ . '/../includes/notification_helper.php')) {
            require_once __DIR__ . '/../includes/notification_helper.php';
            if (function_exists('notify_payment_approved')) {
                notify_payment_approved($conn, (int)$contract_id, (float)$amount, (string)$reference, (string)$payment_method);
            }
        }

        return [
            'success' => true,
            'contract_id' => $contract_id,
            'installment_number' => $inst_num,
            'amount_paid' => $amount,
            'remaining_balance' => $summary['remaining_balance'],
            'total_paid' => $summary['total_paid'],
            'total_amount' => $summary['total_amount'],
        ];
    }
}

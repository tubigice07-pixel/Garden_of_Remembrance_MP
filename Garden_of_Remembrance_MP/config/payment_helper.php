<?php
/**
 * config/payment_helper.php
 * 
 * Centralized Payment, Receipt, and GCash Transaction Engine
 * Garden of Remembrance Memorial Park
 */

require_once __DIR__ . '/../connection.php';
if (file_exists(__DIR__ . '/installment_helper.php')) {
    require_once __DIR__ . '/installment_helper.php';
}

if (!function_exists('generate_official_receipt_number')) {
    /**
     * Generates a unique, standardized receipt number: RCPT-YYYYMMDD-XXXX
     */
    function generate_official_receipt_number(int $payment_id, ?string $date = null): string {
        $ts = $date ? strtotime($date) : time();
        $dStr = date('Ymd', $ts ?: time());
        return sprintf("RCPT-%s-%04d", $dStr, $payment_id);
    }
}

if (!function_exists('check_duplicate_payment_submission')) {
    /**
     * Checks if a GCash reference has already been submitted for a booking.
     * Returns true if duplicate found.
     */
    function check_duplicate_payment_submission(mysqli $conn, int $booking_id, string $gcash_reference, int $exclude_id = 0): bool {
        $cleanRef = trim($gcash_reference);
        if (empty($cleanRef)) return false;

        $stmt = $conn->prepare("SELECT id, payment_status FROM payment_receipts 
            WHERE booking_id = ? 
            AND (UPPER(TRIM(gcash_reference_number)) = UPPER(?) OR UPPER(TRIM(receipt_number)) = UPPER(?))
            AND id != ? 
            AND UPPER(COALESCE(payment_status, '')) != 'REJECTED'
            LIMIT 1");
        if (!$stmt) return false;

        $stmt->bind_param('issi', $booking_id, $cleanRef, $cleanRef, $exclude_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $isDup = ($res && $res->num_rows > 0);
        $stmt->close();

        return $isDup;
    }
}

if (!function_exists('record_payment_transaction')) {
    /**
     * Records an individual payment transaction in payment_receipts.
     * Never overwrites prior transactions.
     */
    function record_payment_transaction(mysqli $conn, array $data): array {
        $booking_id = (int)($data['booking_id'] ?? 0);
        $user_id = (int)($data['user_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $method = trim((string)($data['payment_method'] ?? 'GCash'));
        $status = strtoupper(trim((string)($data['payment_status'] ?? 'PENDING')));
        $gcash_ref = trim((string)($data['gcash_reference_number'] ?? ''));
        $tx_ref = trim((string)($data['transaction_reference'] ?? ($gcash_ref ?: '')));
        $proof = trim((string)($data['proof_filename'] ?? ''));
        $schedule_id = !empty($data['payment_schedule_id']) ? (int)$data['payment_schedule_id'] : null;
        $inst_num = !empty($data['installment_number']) ? (int)$data['installment_number'] : null;
        $payment_date = !empty($data['payment_date']) ? $data['payment_date'] : date('Y-m-d H:i:s');

        if ($booking_id <= 0 || $amount <= 0) {
            return ['success' => false, 'message' => 'Invalid booking ID or payment amount.'];
        }

        // Prevent duplicate verified or pending payments with identical GCash reference on same booking
        if (!empty($gcash_ref) && check_duplicate_payment_submission($conn, $booking_id, $gcash_ref)) {
            return ['success' => false, 'message' => 'This GCash reference (' . htmlspecialchars($gcash_ref) . ') has already been submitted for this booking.'];
        }

        // Fetch user_id from booking if missing
        if ($user_id <= 0) {
            $bStmt = $conn->query("SELECT user_id FROM tbl_bookings WHERE id = $booking_id LIMIT 1");
            if ($bStmt && ($bRow = $bStmt->fetch_assoc())) {
                $user_id = (int)($bRow['user_id'] ?? 0);
            }
        }

        // Insert new record into payment_receipts (Our authoritative transactions table)
        $ins = $conn->prepare("INSERT INTO payment_receipts 
            (booking_id, user_id, payment_schedule_id, installment_number, amount_paid, payment_method, gcash_reference_number, transaction_reference, payment_date, payment_status, proof_filename, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        if (!$ins) {
            return ['success' => false, 'message' => 'Database error: ' . $conn->error];
        }

        $ins->bind_param('iiiidssssss', 
            $booking_id, $user_id, $schedule_id, $inst_num, $amount, 
            $method, $gcash_ref, $tx_ref, $payment_date, $status, $proof
        );

        if (!$ins->execute()) {
            $err = $ins->error;
            $ins->close();
            return ['success' => false, 'message' => 'Failed to record transaction: ' . $err];
        }

        $payment_id = (int)$ins->insert_id;
        $ins->close();

        // Assign clean official receipt number
        $rcptNumber = generate_official_receipt_number($payment_id, $payment_date);
        $conn->query("UPDATE payment_receipts SET receipt_number = '" . $conn->real_escape_string($rcptNumber) . "' WHERE id = $payment_id");

        // If status is PAID (e.g. gateway or cash on site confirmed immediately), update schedules and totals
        if ($status === 'PAID') {
            apply_verified_payment_effects($conn, $payment_id);
        }

        // Audit log
        $action = ($status === 'PAID') ? 'PAYMENT_RECEIVED' : 'PAYMENT_SUBMITTED';
        $logDetails = "Payment #{$payment_id} ({$method}) of ₱" . number_format($amount, 2) . " submitted for Booking #{$booking_id}. Status: {$status}.";
        if (!empty($gcash_ref)) {
            $logDetails .= " GCash Ref: {$gcash_ref}.";
        }
        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, ?, NOW())");
        if ($logStmt) {
            $logStmt->bind_param('ssi', $action, $logDetails, $user_id);
            $logStmt->execute();
            $logStmt->close();
        }

        return [
            'success' => true,
            'payment_id' => $payment_id,
            'receipt_number' => $rcptNumber,
            'gcash_reference_number' => $gcash_ref,
            'amount' => $amount,
            'status' => $status
        ];
    }
}

if (!function_exists('apply_verified_payment_effects')) {
    /**
     * Applies the verified status of a payment to booking schedules and balances.
     */
    function apply_verified_payment_effects(mysqli $conn, int $payment_id): void {
        $pRes = $conn->query("SELECT * FROM payment_receipts WHERE id = $payment_id LIMIT 1");
        if (!$pRes || !($p = $pRes->fetch_assoc())) return;

        $booking_id = (int)$p['booking_id'];
        $schedule_id = !empty($p['payment_schedule_id']) ? (int)$p['payment_schedule_id'] : 0;
        $inst_num = !empty($p['installment_number']) ? (int)$p['installment_number'] : 0;
        $amount = (float)$p['amount_paid'];
        $method = $p['payment_method'];
        $ref = !empty($p['gcash_reference_number']) ? $p['gcash_reference_number'] : $p['transaction_reference'];

        // 1. If linked to an installment schedule, mark that schedule as paid
        if ($schedule_id > 0) {
            $upSch = $conn->prepare("UPDATE payment_schedules SET status = 'paid', paid_amount = ?, paid_at = NOW(), payment_method = ?, transaction_reference = ? WHERE id = ?");
            if ($upSch) {
                $upSch->bind_param('dssi', $amount, $method, $ref, $schedule_id);
                $upSch->execute();
                $upSch->close();
            }
        } elseif ($inst_num > 0) {
            $upSch2 = $conn->prepare("UPDATE payment_schedules SET status = 'paid', paid_amount = ?, paid_at = NOW(), payment_method = ?, transaction_reference = ? WHERE contract_id = ? AND installment_number = ?");
            if ($upSch2) {
                $upSch2->bind_param('dssii', $amount, $method, $ref, $booking_id, $inst_num);
                $upSch2->execute();
                $upSch2->close();
            }
        }

        // Also sync legacy booking_payment_schedule
        if ($inst_num > 0) {
            $upLeg = $conn->prepare("UPDATE booking_payment_schedule SET status = 'PAID', amount_paid = ?, paid_at = NOW(), payment_method = ?, transaction_reference = ? WHERE booking_id = ? AND installment_number = ?");
            if ($upLeg) {
                $upLeg->bind_param('dssii', $amount, $method, $ref, $booking_id, $inst_num);
                $upLeg->execute();
                $upLeg->close();
            }
        }

        // 2. Recalculate and update booking total amount_paid strictly from confirmed payments
        recalculate_booking_payment_totals($booking_id, $conn);
    }
}

if (!function_exists('recalculate_booking_payment_totals')) {
    /**
     * Accurately calculates amount_paid, remaining_balance, and payment_status strictly from verified payments.
     *
     * Rule:
     * - Total Paid = SUM(valid payment transactions for the booking)
     * - Remaining Balance = Booking Total - Total Paid
     * - If Total Paid == 0 => PENDING
     * - If Total Paid > 0 AND Total Paid < Booking Total => PARTIAL
     * - If Total Paid >= Booking Total => PAID
     */
    function recalculate_booking_payment_totals($booking_id, $conn = null): array {
        if ($booking_id instanceof mysqli) {
            $tmpConn = $booking_id;
            $booking_id = (int)$conn;
            $conn = $tmpConn;
        } else {
            $booking_id = (int)$booking_id;
        }

        @$conn->set_charset('utf8mb4');

        // Calculate sum of confirmed payments in payment_receipts
        $totRes = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) AS verified_total FROM payment_receipts WHERE booking_id = $booking_id AND UPPER(COALESCE(payment_status, '')) IN ('PAID', 'VERIFIED')");
        $verified_total = 0.00;
        if ($totRes && ($tRow = $totRes->fetch_assoc())) {
            $verified_total = (float)$tRow['verified_total'];
        }

        // Get booking total_amount and details
        $bRes = $conn->query("SELECT total_amount, plot_price, service_price, interment_fee, payment_plan, payment_term, num_lots, payment_type, original_payment_method, payment_status, payment_proof_filename FROM tbl_bookings WHERE id = $booking_id LIMIT 1");
        $total_amount = 0.00;
        $pay_type = null;
        $orig_method = null;
        if ($bRes && ($b = $bRes->fetch_assoc())) {
            $total_amount = (float)($b['total_amount'] ?? 0);
            if ($total_amount <= 0) {
                $total_amount = (float)($b['plot_price'] ?? 0) + (float)($b['service_price'] ?? 0) + (float)($b['interment_fee'] ?? 0);
            }
            if ($total_amount <= 0 && strtolower(trim((string)$b['payment_plan'])) === 'installment') {
                $plan = calculate_installment_plan((int)($b['payment_term'] ?? 12), (int)($b['num_lots'] ?? 1));
                $total_amount = $plan['total_plan_cost'];
            }
            $pay_type = $b['payment_type'];
            $orig_method = $b['original_payment_method'] ?: $b['payment_type'];
        }

        $remaining_balance = max(0.00, round($total_amount - $verified_total, 2));

        // Authoritative payment_status derivation
        if ($total_amount <= 0) {
            $payment_status = ($verified_total > 0) ? 'PAID' : 'PENDING';
        } else {
            if ($verified_total >= ($total_amount - 0.009)) {
                $payment_status = 'PAID';
            } elseif ($verified_total > 0) {
                $payment_status = 'PARTIAL';
            } else {
                $curPayStatus = strtoupper(trim((string)($b['payment_status'] ?? '')));
                $hasProof = !empty($b['payment_proof_filename']);
                $chkVer = $conn->query("SELECT id FROM payment_receipts WHERE booking_id = $booking_id AND (UPPER(COALESCE(payment_status, '')) = 'FOR VERIFICATION' OR (proof_filename IS NOT NULL AND proof_filename != '')) LIMIT 1");
                $receiptHasProof = ($chkVer && $chkVer->num_rows > 0);

                if ($curPayStatus === 'REJECTED') {
                    $payment_status = 'REJECTED';
                } elseif ($curPayStatus === 'FOR VERIFICATION' || $hasProof || $receiptHasProof) {
                    $payment_status = 'FOR VERIFICATION';
                } else {
                    $payment_status = 'PENDING';
                }
            }
        }

        // Update tbl_bookings
        $up = $conn->prepare("UPDATE tbl_bookings SET amount_paid = ?, remaining_balance = ?, payment_status = ?, total_amount = ? WHERE id = ?");
        if ($up) {
            $up->bind_param('ddsdi', $verified_total, $remaining_balance, $payment_status, $total_amount, $booking_id);
            $up->execute();
            $up->close();
        }

        // Populate original_payment_method if missing
        if (!empty($pay_type) && empty($b['original_payment_method'])) {
            $escOrig = $conn->real_escape_string($pay_type);
            $conn->query("UPDATE tbl_bookings SET original_payment_method = '{$escOrig}' WHERE id = $booking_id");
        }

        // If installment, also update remaining_balance across payment_schedules
        $upSched = $conn->prepare("UPDATE payment_schedules SET remaining_balance = ? WHERE contract_id = ?");
        if ($upSched) {
            $upSched->bind_param('di', $remaining_balance, $booking_id);
            $upSched->execute();
            $upSched->close();
        }

        return [
            'total_amount' => $total_amount,
            'amount_paid' => $verified_total,
            'total_paid' => $verified_total,
            'remaining_balance' => $remaining_balance,
            'payment_status' => $payment_status
        ];
    }
}

if (!function_exists('allocate_office_installment_payment')) {
    /**
     * Chronologically applies an office payment to installment schedules
     * without destroying existing schedules or altering pre-calculated monthly amounts.
     */
    function allocate_office_installment_payment(
        mysqli $conn,
        int $contract_id,
        float $amount,
        string $payment_method,
        string $reference,
        string $payment_date,
        int $payment_receipt_id
    ): void {
        if ($contract_id <= 0 || $amount <= 0 || !$conn) return;

        if (function_exists('ensure_installment_tables')) {
            ensure_installment_tables($conn);
        }

        // Fetch unpaid or partially paid schedules in ascending order
        $stmt = $conn->prepare("SELECT id, installment_number, monthly_amount, paid_amount, status 
            FROM payment_schedules 
            WHERE contract_id = ? AND status != 'paid' 
            ORDER BY installment_number ASC");
        if (!$stmt) return;

        $stmt->bind_param('i', $contract_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $schedules = [];
        while ($row = $res->fetch_assoc()) {
            $schedules[] = $row;
        }
        $stmt->close();

        $unallocated = $amount;

        foreach ($schedules as $sched) {
            if ($unallocated <= 0.001) break;

            $sId = (int)$sched['id'];
            $iNum = (int)$sched['installment_number'];
            $monthly = (float)$sched['monthly_amount'];
            $curPaid = (float)$sched['paid_amount'];
            $needed = max(0.00, round($monthly - $curPaid, 2));

            if ($needed <= 0.001) {
                continue;
            }

            if ($unallocated >= $needed) {
                $apply = $needed;
                $newStatus = 'paid';
                $unallocated -= $needed;
            } else {
                $apply = $unallocated;
                $newStatus = 'pending';
                $unallocated = 0.00;
            }

            $newPaidAmt = round($curPaid + $apply, 2);

            // Update payment_schedules
            $upS = $conn->prepare("UPDATE payment_schedules SET status = ?, paid_amount = ?, paid_at = ?, payment_method = ?, transaction_reference = ? WHERE id = ?");
            if ($upS) {
                $upS->bind_param('sdsssi', $newStatus, $newPaidAmt, $payment_date, $payment_method, $reference, $sId);
                $upS->execute();
                $upS->close();
            }

            // Sync legacy booking_payment_schedule
            $upBPS = $conn->prepare("UPDATE booking_payment_schedule SET status = ?, amount_paid = ?, paid_at = ?, payment_method = ?, transaction_reference = ?, payment_receipt_id = ? WHERE booking_id = ? AND installment_number = ?");
            if ($upBPS) {
                $legStatus = strtoupper($newStatus);
                $upBPS->bind_param('sdsssiii', $legStatus, $newPaidAmt, $payment_date, $payment_method, $reference, $payment_receipt_id, $contract_id, $iNum);
                $upBPS->execute();
                $upBPS->close();
            }
        }
    }
}

if (!function_exists('record_office_payment')) {
    /**
     * Admin/Staff action: Record an office payment against an existing booking.
     * Supports Cash / Office, Check, Bank Transfer, POS/Card.
     * Preserves original booking payment method (e.g. GCash).
     * Strictly enforces overpayment prevention (amount <= remaining_balance).
     * Updates payment schedules and booking totals authoritatively.
     * Writes structured audit log in tbl_logs matching Section 8 requirements.
     */
    function record_office_payment(mysqli $conn, array $data): array {
        $booking_id = (int)($data['booking_id'] ?? 0);
        $amount = round((float)($data['amount'] ?? 0), 2);
        $payment_method = trim((string)($data['payment_method'] ?? 'Cash / Office'));
        if (empty($payment_method)) {
            $payment_method = 'Cash / Office';
        }
        $or_number = trim((string)($data['or_number'] ?? ''));
        $ref_number = trim((string)($data['reference_number'] ?? ''));
        $notes = trim((string)($data['notes'] ?? ''));
        $payment_date = !empty($data['payment_date']) ? trim($data['payment_date']) : date('Y-m-d H:i:s');
        if (strlen($payment_date) === 10) {
            $payment_date .= ' ' . date('H:i:s');
        }
        $admin_id = (int)($data['admin_id'] ?? 1);
        $admin_name = trim((string)($data['admin_name'] ?? 'Admin'));

        if ($booking_id <= 0) {
            return ['success' => false, 'message' => 'Invalid booking ID.'];
        }

        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Payment amount must be greater than zero.'];
        }

        if (empty($or_number)) {
            return ['success' => false, 'message' => 'OR / Receipt number is required.'];
        }

        // Fetch existing booking
        $bStmt = $conn->prepare("SELECT id, user_id, total_amount, amount_paid, remaining_balance, payment_status, payment_plan, payment_term, num_lots, payment_type, original_payment_method, plot_price, service_price, interment_fee, status FROM tbl_bookings WHERE id = ? LIMIT 1");
        if (!$bStmt) {
            return ['success' => false, 'message' => 'Database query failed: ' . $conn->error];
        }
        $bStmt->bind_param('i', $booking_id);
        $bStmt->execute();
        $bRes = $bStmt->get_result();
        $booking = $bRes ? $bRes->fetch_assoc() : null;
        $bStmt->close();

        if (!$booking) {
            return ['success' => false, 'message' => "Booking #{$booking_id} not found."];
        }

        // Total amount determination
        $total_amount = (float)($booking['total_amount'] ?? 0);
        if ($total_amount <= 0) {
            $total_amount = (float)($booking['plot_price'] ?? 0) + (float)($booking['service_price'] ?? 0) + (float)($booking['interment_fee'] ?? 0);
        }
        if ($total_amount <= 0 && strtolower(trim((string)$booking['payment_plan'])) === 'installment') {
            $plan = calculate_installment_plan((int)($booking['payment_term'] ?? 12), (int)($booking['num_lots'] ?? 1));
            $total_amount = $plan['total_plan_cost'];
        }

        // Sum current valid transactions
        $prRes = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM payment_receipts WHERE booking_id = $booking_id AND UPPER(COALESCE(payment_status, '')) IN ('PAID', 'VERIFIED')");
        $previous_paid = 0.00;
        if ($prRes && ($prRow = $prRes->fetch_assoc())) {
            $previous_paid = (float)$prRow['total_paid'];
        }

        $previous_balance = max(0.00, round($total_amount - $previous_paid, 2));

        // 12. PREVENT OVERPAYMENT
        if ($amount > ($previous_balance + 0.009)) {
            return [
                'success' => false,
                'message' => 'Payment exceeds the remaining balance of ₱' . number_format($previous_balance, 2) . '.'
            ];
        }

        // Preserve original payment method if not already stored
        $orig_method = $booking['original_payment_method'];
        if (empty($orig_method)) {
            $orig_method = $booking['payment_type'] ?: 'GCash';
            $escOrig = $conn->real_escape_string($orig_method);
            $conn->query("UPDATE tbl_bookings SET original_payment_method = '{$escOrig}' WHERE id = $booking_id");
        }

        $user_id = (int)($booking['user_id'] ?? 0);
        $tx_ref = !empty($ref_number) ? $ref_number : $or_number;

        // Insert payment transaction into payment_receipts
        $ins = $conn->prepare("INSERT INTO payment_receipts 
            (booking_id, user_id, amount_paid, payment_method, gcash_reference_number, transaction_reference, or_number, receipt_number, notes, payment_date, payment_status, recorded_by, recorded_by_name, verified_by, verified_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PAID', ?, ?, ?, ?, NOW())");
        
        if (!$ins) {
            return ['success' => false, 'message' => 'Database prepare failed: ' . $conn->error];
        }

        // Generate temporary placeholder for receipt_number, will update with standardized RCPT format
        $tempRcpt = 'TEMP-' . time();
        $gcashRefForOffice = (stripos($payment_method, 'gcash') !== false) ? $tx_ref : null;

        $ins->bind_param(
            'iidsssssssisis',
            $booking_id,
            $user_id,
            $amount,
            $payment_method,
            $gcashRefForOffice,
            $tx_ref,
            $or_number,
            $tempRcpt,
            $notes,
            $payment_date,
            $admin_id,
            $admin_name,
            $admin_id,
            $payment_date
        );

        if (!$ins->execute()) {
            $err = $ins->error;
            $ins->close();
            return ['success' => false, 'message' => 'Failed to record office payment: ' . $err];
        }

        $payment_id = (int)$ins->insert_id;
        $ins->close();

        // Assign standardized receipt number
        $rcptNumber = generate_official_receipt_number($payment_id, $payment_date);
        $conn->query("UPDATE payment_receipts SET receipt_number = '" . $conn->real_escape_string($rcptNumber) . "' WHERE id = $payment_id");

        // If installment booking, allocate this office payment across unpaid/pending installment schedules
        if (strtolower(trim((string)$booking['payment_plan'])) === 'installment') {
            allocate_office_installment_payment($conn, $booking_id, $amount, $payment_method, $tx_ref, $payment_date, $payment_id);
        }

        // Recalculate totals strictly from valid transactions
        $newTotals = recalculate_booking_payment_totals($booking_id, $conn);

        // If booking was pending and payment was made, update booking status to approved
        if (strtolower(trim((string)($booking['status'] ?? ''))) === 'pending') {
            $conn->query("UPDATE tbl_bookings SET status = 'approved' WHERE id = $booking_id");
        }

        // 8. ADMIN LOGS (Exact format requested in prompt)
        $summaryText = "Office payment of ₱" . number_format($amount, 2) . " recorded for Booking #{$booking_id} ({$payment_method}" . (!empty($or_number) ? ", OR: {$or_number}" : "") . "). " .
            "Previous paid: ₱" . number_format($previous_paid, 2) . ", New paid: ₱" . number_format($newTotals['amount_paid'], 2) . ", " .
            "Previous balance: ₱" . number_format($previous_balance, 2) . ", New balance: ₱" . number_format($newTotals['remaining_balance'], 2) . ", " .
            "Status: {$newTotals['payment_status']}. Recorded by {$admin_name} (ID: {$admin_id}).";

        $auditText = $summaryText . "\n\n" .
            "Booking ID: #{$booking_id}\n" .
            "Payment Method: {$payment_method}\n" .
            "Amount: ₱" . number_format($amount, 2) . "\n" .
            "OR Number: {$or_number}\n" .
            "Previous Paid: ₱" . number_format($previous_paid, 2) . "\n" .
            "New Paid: ₱" . number_format($newTotals['amount_paid'], 2) . "\n" .
            "Previous Balance: ₱" . number_format($previous_balance, 2) . "\n" .
            "New Balance: ₱" . number_format($newTotals['remaining_balance'], 2) . "\n" .
            "Payment Status: {$newTotals['payment_status']}\n" .
            "Recorded By: {$admin_name}\n" .
            "Date/Time: {$payment_date}";

        $logAction = 'Office Payment Recorded';
        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, ?, NOW())");
        if ($logStmt) {
            $logStmt->bind_param('ssi', $logAction, $auditText, $admin_id);
            $logStmt->execute();
            $logStmt->close();
        }

        // Trigger notification if helper is present
        if (file_exists(__DIR__ . '/../includes/notification_helper.php')) {
            require_once __DIR__ . '/../includes/notification_helper.php';
            if (function_exists('notify_payment_approved')) {
                notify_payment_approved($conn, $booking_id, $amount, $or_number, $payment_method);
            }
        }

        return [
            'success' => true,
            'message' => 'Office payment successfully recorded.',
            'payment_id' => $payment_id,
            'receipt_number' => $rcptNumber,
            'or_number' => $or_number,
            'booking_id' => $booking_id,
            'amount' => $amount,
            'previous_paid' => $previous_paid,
            'new_paid' => $newTotals['amount_paid'],
            'previous_balance' => $previous_balance,
            'new_balance' => $newTotals['remaining_balance'],
            'payment_status' => $newTotals['payment_status'],
            'total_amount' => $newTotals['total_amount'],
            'original_payment_method' => $orig_method,
            'actual_payment_method' => $payment_method
        ];
    }
}


if (!function_exists('verify_and_approve_payment')) {
    /**
     * Admin/Staff action: Verifies and approves a pending payment.
     */
    function verify_and_approve_payment(mysqli $conn, int $payment_id, int $admin_id): array {
        $pRes = $conn->query("SELECT * FROM payment_receipts WHERE id = $payment_id LIMIT 1");
        if (!$pRes || !($p = $pRes->fetch_assoc())) {
            return ['success' => false, 'message' => "Payment #{$payment_id} not found."];
        }

        $curStatus = strtoupper(trim((string)$p['payment_status']));
        if ($curStatus === 'PAID') {
            return ['success' => false, 'message' => "Payment #{$payment_id} is already verified and marked as PAID."];
        }

        // Update payment_receipts status to PAID
        $up = $conn->prepare("UPDATE payment_receipts SET payment_status = 'PAID', verified_by = ?, verified_at = NOW(), rejection_reason = NULL WHERE id = ?");
        if (!$up) {
            return ['success' => false, 'message' => 'Database update error: ' . $conn->error];
        }
        $up->bind_param('ii', $admin_id, $payment_id);
        $up->execute();
        $up->close();

        // Apply effects (mark schedule paid, update balances)
        apply_verified_payment_effects($conn, $payment_id);

        $booking_id = (int)$p['booking_id'];
        $amount = (float)$p['amount_paid'];
        $method = $p['payment_method'];
        $gcash_ref = $p['gcash_reference_number'] ?: $p['receipt_number'];

        // Also update tbl_bookings status to approved if it was pending
        $conn->query("UPDATE tbl_bookings SET status = 'approved' WHERE id = $booking_id AND status = 'pending'");

        // Audit log in tbl_logs
        $logAction = 'PAYMENT_VERIFIED';
        $logDetails = "Payment #{$payment_id} ({$method}) of ₱" . number_format($amount, 2) . " verified and approved by Staff/Admin #{$admin_id} for Booking #{$booking_id}. Ref: {$gcash_ref}";
        $stmtLog = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, ?, NOW())");
        if ($stmtLog) {
            $stmtLog->bind_param('ssi', $logAction, $logDetails, $admin_id);
            $stmtLog->execute();
            $stmtLog->close();
        }

        // Notify user
        if (file_exists(__DIR__ . '/../includes/notification_helper.php')) {
            require_once __DIR__ . '/../includes/notification_helper.php';
            if (function_exists('notify_payment_approved')) {
                notify_payment_approved($conn, $booking_id, $amount, (string)$gcash_ref, (string)$method);
            }
        }

        return [
            'success' => true,
            'message' => "Payment #{$payment_id} successfully verified and marked as PAID.",
            'payment_id' => $payment_id,
            'receipt_number' => $p['receipt_number']
        ];
    }
}

if (!function_exists('reject_payment_transaction')) {
    /**
     * Admin/Staff action: Rejects an invalid or fraudulent payment proof/reference.
     */
    function reject_payment_transaction(mysqli $conn, int $payment_id, int $admin_id, string $reason): array {
        $pRes = $conn->query("SELECT * FROM payment_receipts WHERE id = $payment_id LIMIT 1");
        if (!$pRes || !($p = $pRes->fetch_assoc())) {
            return ['success' => false, 'message' => "Payment #{$payment_id} not found."];
        }

        $up = $conn->prepare("UPDATE payment_receipts SET payment_status = 'REJECTED', verified_by = ?, verified_at = NOW(), rejection_reason = ? WHERE id = ?");
        if (!$up) {
            return ['success' => false, 'message' => 'Database update error: ' . $conn->error];
        }
        $cleanReason = trim($reason) ?: 'Payment proof or GCash reference could not be verified.';
        $up->bind_param('isi', $admin_id, $cleanReason, $payment_id);
        $up->execute();
        $up->close();

        $booking_id = (int)$p['booking_id'];
        $amount = (float)$p['amount_paid'];
        $gcash_ref = $p['gcash_reference_number'] ?: $p['receipt_number'];

        // Audit log in tbl_logs
        $logAction = 'PAYMENT_REJECTED';
        $logDetails = "Payment #{$payment_id} of ₱" . number_format($amount, 2) . " rejected by Staff/Admin #{$admin_id} for Booking #{$booking_id}. Reason: {$cleanReason}. Ref: {$gcash_ref}";
        $stmtLog = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, ?, NOW())");
        if ($stmtLog) {
            $stmtLog->bind_param('ssi', $logAction, $logDetails, $admin_id);
            $stmtLog->execute();
            $stmtLog->close();
        }

        // Notify user via in-system notification
        if (file_exists(__DIR__ . '/../includes/notification_helper.php')) {
            require_once __DIR__ . '/../includes/notification_helper.php';
            if (function_exists('create_user_notification')) {
                $uId = (int)$p['user_id'];
                $msg = "Your payment submission of ₱" . number_format($amount, 2) . " for Booking #{$booking_id} was rejected. Reason: {$cleanReason}";
                create_user_notification($conn, $uId, 'Payment Verification Rejected', $msg, 'PAYMENT_REJECTED', $booking_id);
            }
        }

        return [
            'success' => true,
            'message' => "Payment #{$payment_id} has been marked as REJECTED.",
            'payment_id' => $payment_id
        ];
    }
}

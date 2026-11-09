<?php
require_once __DIR__ . '/config/payment_settings.php';
require_once __DIR__ . '/includes/formatters.php';
include 'connection.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

$user_id = (int)($_SESSION['user_id'] ?? ($_SESSION['admin_id'] ?? 0));
$current_role = isset($_SESSION['role']) ? strtoupper(trim((string) $_SESSION['role'])) : '';

if ($user_id > 0 && $current_role !== 'ADMIN' && isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
    $rCheck = $conn->query("SELECT role FROM tbl_user WHERE user_id = $user_id LIMIT 1");
    if ($rCheck && ($rRow = $rCheck->fetch_assoc())) {
        if (strtoupper(trim((string)$rRow['role'])) === 'ADMIN') {
            $_SESSION['role'] = 'ADMIN';
            $current_role = 'ADMIN';
        }
    }
}

if ($user_id <= 0 || $current_role !== 'ADMIN') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Session expired or unauthorized. Please log in again.']);
        exit;
    }
    header("Location: admin_login.php");
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? ($_SESSION['admin_id'] ?? 0));
$_SESSION['user_id'] = $user_id;
if (!$conn || !($conn instanceof mysqli) || $conn->connect_errno) { die("<div style='font-family:sans-serif;padding:30px;max-width:600px;margin:50px auto;border:1px solid #fecaca;border-radius:10px;background:#fef2f2;color:#991b1b;text-align:center;'><h2 style='margin-top:0;'>Database Temporarily Unavailable</h2><p>Unable to connect to MySQL database.</p><p><small>" . htmlspecialchars($lastConnErr ?? '') . "</small></p></div>"); }

$uRes = @$conn->query("SELECT first_name, last_name, email, profile_picture FROM tbl_user WHERE user_id = $user_id"); 
$user = ($uRes && ($uRow = $uRes->fetch_assoc())) ? $uRow : ['first_name' => 'Admin', 'last_name' => '', 'email' => '', 'profile_picture' => ''];

// Synchronize sales_reports table only if explicitly requested or if table is empty
if (function_exists('sync_sales_report_table') && (!empty($_GET['sync_reports']) || !empty($_GET['sync']))) {
    sync_sales_report_table($conn);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['verify_payment', 'reject_payment'], true)) {
    header('Content-Type: application/json');
    $payment_id = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
    $booking_id = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

    if ($payment_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Payment ID.']);
        exit;
    }

    if ($_POST['action'] === 'verify_payment') {
        
        $stmtVP = $conn->prepare("UPDATE payment_receipts SET payment_status = 'PAID', verified_by = ?, verified_at = NOW() WHERE id = ?");
        if ($stmtVP) {
            $stmtVP->bind_param('ii', $user_id, $payment_id);
            $stmtVP->execute();
            $stmtVP->close();
        }

        
        $pRes = $conn->query("SELECT booking_id, payment_schedule_id, installment_number, amount_paid, gcash_reference_number FROM payment_receipts WHERE id = $payment_id LIMIT 1");
        if ($pRes && ($pRow = $pRes->fetch_assoc())) {
            $bId = (int)$pRow['booking_id'];
            $schId = (int)($pRow['payment_schedule_id'] ?? 0);
            $instNum = (int)($pRow['installment_number'] ?? 0);
            $pAmt = (float)$pRow['amount_paid'];
            $gRef = $pRow['gcash_reference_number'] ?? '';

            if ($schId > 0) {
                $conn->query("UPDATE payment_schedules SET status = 'paid', paid_amount = $pAmt, paid_at = NOW() WHERE id = $schId");
            }
            if ($instNum > 0 && $bId > 0) {
                $conn->query("UPDATE booking_payment_schedule SET status = 'PAID', amount_paid = $pAmt, paid_at = NOW() WHERE booking_id = $bId AND installment_number = $instNum");
            }

            
            if (file_exists(__DIR__ . '/config/payment_helper.php')) {
                require_once __DIR__ . '/config/payment_helper.php';
                recalculate_booking_payment_totals($bId, $conn);
            }
            if (file_exists(__DIR__ . '/config/installment_helper.php')) {
                require_once __DIR__ . '/config/installment_helper.php';
                if (function_exists('update_contract_remaining_balances')) {
                    update_contract_remaining_balances($bId, $conn);
                }
            }

            
            $logMsg = "Payment #{$payment_id} verified for Booking #{$bId}. Amount: ₱" . number_format($pAmt, 2);
            $lStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('PAYMENT_VERIFIED', ?, ?, NOW())");
            if ($lStmt) { $lStmt->bind_param('si', $logMsg, $user_id); $lStmt->execute(); $lStmt->close(); }

            
            if (file_exists(__DIR__ . '/includes/notification_helper.php')) {
                require_once __DIR__ . '/includes/notification_helper.php';
                if (function_exists('notify_payment_approved')) {
                    notify_payment_approved($conn, $bId, $pAmt, $gRef, 'GCash');
                }
            }
        }

        if (function_exists('record_sales_report_entry') && isset($bId)) {
            record_sales_report_entry($conn, $bId);
        }
        echo json_encode(['success' => true, 'message' => 'Payment marked as verified and approved.']);
        exit;
    } elseif ($_POST['action'] === 'reject_payment') {
        $stmtRP = $conn->prepare("UPDATE payment_receipts SET payment_status = 'REJECTED', rejection_reason = ?, verified_by = ?, verified_at = NOW() WHERE id = ?");
        if ($stmtRP) {
            $stmtRP->bind_param('sii', $reason, $user_id, $payment_id);
            $stmtRP->execute();
            $stmtRP->close();
            if (file_exists(__DIR__ . '/config/payment_helper.php') && !empty($booking_id)) {
                require_once __DIR__ . '/config/payment_helper.php';
                recalculate_booking_payment_totals((int)$booking_id, $conn);
            }
        }

        
        $logMsg = "Payment #{$payment_id} rejected for Booking #{$booking_id}. Reason: " . ($reason ?: 'Unspecified');
        $lStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('PAYMENT_REJECTED', ?, ?, NOW())");
        if ($lStmt) { $lStmt->bind_param('si', $logMsg, $user_id); $lStmt->execute(); $lStmt->close(); }

        if (function_exists('record_sales_report_entry') && isset($booking_id)) {
            record_sales_report_entry($conn, $booking_id);
        }
        echo json_encode(['success' => true, 'message' => 'Payment marked as rejected.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_payment_reminder_history') {
    header('Content-Type: application/json');
    $sql = "SELECT prh.*, 
                   b.customer_name, b.full_name, b.customer_email, b.email AS booking_email, b.section, b.plot,
                   u.first_name, u.last_name, u.email AS user_email,
                   ps.installment_number, ps.monthly_amount, ps.remaining_balance
            FROM payment_reminder_history prh
            LEFT JOIN tbl_bookings b ON b.id = prh.booking_id
            LEFT JOIN tbl_user u ON u.user_id = prh.user_id
            LEFT JOIN payment_schedules ps ON ps.id = prh.schedule_id
            ORDER BY prh.sent_at DESC, prh.id DESC
            LIMIT 100";
    $res = $conn->query($sql);
    $reminders = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $cName = trim($r['customer_name'] ?? $r['full_name'] ?? ($r['first_name'] . ' ' . $r['last_name']));
        if (empty($cName)) $cName = 'Customer #' . $r['user_id'];
        $cEmail = $r['customer_email'] ?? $r['booking_email'] ?? $r['user_email'] ?? 'N/A';
        $reminders[] = [
            'id' => (int)$r['id'],
            'schedule_id' => (int)$r['schedule_id'],
            'booking_id' => (int)$r['booking_id'],
            'customer_name' => $cName,
            'customer_email' => $cEmail,
            'section' => $r['section'] ?? '',
            'plot' => $r['plot'] ?? '',
            'installment_number' => (int)($r['installment_number'] ?? 1),
            'monthly_amount' => (float)($r['monthly_amount'] ?? 0),
            'reminder_type' => $r['reminder_type'],
            'due_date' => $r['due_date'],
            'sent_at' => $r['sent_at']
        ];
    }
    echo json_encode(['success' => true, 'reminders' => $reminders]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dispatch_payment_reminders') {
    $_GET['key'] = 'gor_cron_reminder_2026';
    require_once __DIR__ . '/cron/payment_reminders.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_installment_payments') {
    header('Content-Type: application/json');
    $filter_start = isset($_POST['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['start_date']) ? $_POST['start_date'] : '';
    $filter_end   = isset($_POST['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['end_date']) ? $_POST['end_date'] : '';

    if (empty($filter_start) || empty($filter_end)) {
        $sales_month_filter = isset($_POST['sales_month']) ? $_POST['sales_month'] : date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $sales_month_filter)) {
            $sales_month_filter = date('Y-m');
        }
        $filter_start = $sales_month_filter . '-01';
        $filter_end   = date('Y-m-t', strtotime($filter_start));
    } elseif ($filter_start > $filter_end) {
        $tmp = $filter_start;
        $filter_start = $filter_end;
        $filter_end = $tmp;
    }
    $today = date('Y-m-d');

    if (file_exists(__DIR__ . '/config/installment_helper.php')) {
        require_once __DIR__ . '/config/installment_helper.php';
    }
    if (function_exists('ensure_installment_tables')) {
        ensure_installment_tables($conn);
    }

    $records = [];
    $contracts = [];
    $seenKeys = [];

    // ── 1. Query all installment bookings (contracts) ─────────────────────────
    $bSql = "SELECT b.id AS booking_id,
                    b.user_id,
                    COALESCE(NULLIF(TRIM(b.customer_name), ''), NULLIF(TRIM(b.full_name), ''), CONCAT_WS(' ', u.first_name, u.last_name), 'Customer') AS customer_name,
                    COALESCE(NULLIF(TRIM(b.customer_email), ''), NULLIF(TRIM(b.email), ''), u.email, '') AS customer_email,
                    COALESCE(NULLIF(TRIM(b.contact_number), ''), u.phone_no, '') AS contact_number,
                    b.section,
                    b.plot,
                    b.payment_plan,
                    COALESCE(NULLIF(b.payment_term, 0), 12) AS term_months,
                    b.total_amount,
                    b.amount_paid,
                    b.remaining_balance,
                    b.downpayment,
                    b.status,
                    b.payment_status
             FROM tbl_bookings b
             LEFT JOIN tbl_user u ON u.user_id = b.user_id
             WHERE (
                 LOWER(TRIM(b.payment_plan)) = 'installment'
                 OR b.payment_term > 0
                 OR b.id IN (SELECT DISTINCT contract_id FROM payment_schedules)
                 OR b.id IN (SELECT DISTINCT booking_id FROM payment_receipts WHERE installment_number > 0)
             )
             AND b.status NOT IN ('rejected', 'cancelled')
             ORDER BY customer_name ASC, b.id DESC";

    $bRes = $conn->query($bSql);
    $all_bks = [];
    if ($bRes) {
        while ($bRow = $bRes->fetch_assoc()) {
            $all_bks[] = $bRow;
        }
    }

    // Auto-generate missing schedules if helper exists
    if (function_exists('generate_contract_payment_schedule')) {
        foreach ($all_bks as $b) {
            $bId = (int)$b['booking_id'];
            $chkPs = $conn->query("SELECT COUNT(*) as c FROM payment_schedules WHERE contract_id = $bId");
            if ($chkPs && (int)($chkPs->fetch_assoc()['c'] ?? 0) === 0) {
                @generate_contract_payment_schedule($bId, $conn, ((float)($b['downpayment'] ?? 0) > 0 || (float)($b['amount_paid'] ?? 0) > 0));
            }
        }
    }

    // Pre-fetch all receipts for installment bookings
    $receipts_by_booking = [];
    $prAllRes = $conn->query("SELECT pr.*, 
                                     pr.id AS receipt_id,
                                     pr.gcash_reference_number,
                                     pr.transaction_reference,
                                     pr.receipt_number
                              FROM payment_receipts pr
                              WHERE pr.installment_number IS NOT NULL AND pr.installment_number > 0
                              ORDER BY pr.id ASC");
    if ($prAllRes) {
        while ($pr = $prAllRes->fetch_assoc()) {
            $bId = (int)$pr['booking_id'];
            $instN = (int)$pr['installment_number'];
            if (!isset($receipts_by_booking[$bId])) {
                $receipts_by_booking[$bId] = [];
            }
            $receipts_by_booking[$bId][$instN] = $pr;
        }
    }

    // Pre-fetch all payment schedules
    $schedules_by_booking = [];
    $psAllRes = $conn->query("SELECT * FROM payment_schedules ORDER BY contract_id ASC, installment_number ASC");
    if ($psAllRes) {
        while ($ps = $psAllRes->fetch_assoc()) {
            $bId = (int)$ps['contract_id'];
            if (!isset($schedules_by_booking[$bId])) {
                $schedules_by_booking[$bId] = [];
            }
            $schedules_by_booking[$bId][] = $ps;
        }
    }

    // Fallback: legacy booking_payment_schedule
    $chkLegacy = $conn->query("SHOW TABLES LIKE 'booking_payment_schedule'");
    if ($chkLegacy && $chkLegacy->num_rows > 0) {
        $bpsAllRes = $conn->query("SELECT * FROM booking_payment_schedule ORDER BY booking_id ASC, installment_number ASC");
        if ($bpsAllRes) {
            while ($bps = $bpsAllRes->fetch_assoc()) {
                $bId = (int)$bps['booking_id'];
                if (empty($schedules_by_booking[$bId])) {
                    $schedules_by_booking[$bId][] = [
                        'id' => (int)$bps['id'],
                        'contract_id' => $bId,
                        'user_id' => 0,
                        'installment_number' => (int)$bps['installment_number'],
                        'monthly_amount' => (float)$bps['amount_due'],
                        'paid_amount' => (float)$bps['amount_paid'],
                        'due_date' => $bps['due_date'],
                        'status' => strtolower($bps['status']) === 'paid' ? 'paid' : (strtotime($bps['due_date']) < time() ? 'overdue' : 'pending'),
                        'paid_at' => $bps['paid_at'],
                        'payment_method' => $bps['payment_method'],
                        'transaction_reference' => $bps['transaction_reference'] ?: $bps['gcash_reference_number']
                    ];
                }
            }
        }
    }

    // Build contracts and their detailed installment schedules
    foreach ($all_bks as $b) {
        $bId = (int)$b['booking_id'];
        $schedules = $schedules_by_booking[$bId] ?? [];
        $receipts = $receipts_by_booking[$bId] ?? [];
        $term_months = (int)($b['term_months'] ?: 12);
        $total_amount = (float)($b['total_amount'] ?: 0.0);

        // If no schedules found at all, create synthetic default schedule entries
        if (empty($schedules)) {
            $mFee = $term_months > 0 ? round($total_amount / $term_months, 2) : 0;
            for ($i = 1; $i <= $term_months; $i++) {
                $schedules[] = [
                    'id' => 0,
                    'contract_id' => $bId,
                    'installment_number' => $i,
                    'monthly_amount' => $mFee,
                    'paid_amount' => 0,
                    'due_date' => date('Y-m-d', strtotime("+{$i} month")),
                    'status' => 'pending',
                    'paid_at' => null,
                    'payment_method' => 'GCash',
                    'transaction_reference' => ''
                ];
            }
        }

        $installments = [];
        $paid_count = 0;
        $pending_count = 0;
        $overdue_count = 0;
        $calc_total_paid = 0.0;
        $has_period_activity = false;

        foreach ($schedules as $sch) {
            $instNum = (int)$sch['installment_number'];
            $mAmount = (float)$sch['monthly_amount'];
            $pr = $receipts[$instNum] ?? null;

            $dueDate = !empty($sch['due_date']) ? date('Y-m-d', strtotime($sch['due_date'])) : null;
            $payDate = ($pr && !empty($pr['payment_date'])) ? $pr['payment_date'] : (!empty($sch['paid_at']) ? $sch['paid_at'] : null);
            $paidAmt = $pr ? (float)$pr['amount_paid'] : (float)$sch['paid_amount'];

            // Status determination
            $prStatus = $pr ? strtoupper(trim((string)$pr['payment_status'])) : '';
            $schStatus = strtoupper(trim((string)$sch['status']));

            if ($prStatus === 'PAID' || $schStatus === 'PAID') {
                $status = 'PAID';
            } elseif ($prStatus === 'FOR VERIFICATION' || $prStatus === 'PENDING' || $schStatus === 'FOR VERIFICATION' || $schStatus === 'PENDING_VERIFICATION') {
                $status = 'FOR VERIFICATION';
            } elseif ($prStatus === 'REJECTED' || $schStatus === 'REJECTED') {
                $status = 'REJECTED';
            } elseif ($dueDate && $dueDate < $today) {
                $status = 'OVERDUE';
            } else {
                $status = 'PENDING';
            }

            if ($status === 'PAID') {
                $paid_count++;
                $calc_total_paid += ($paidAmt > 0 ? $paidAmt : $mAmount);
            } elseif ($status === 'FOR VERIFICATION') {
                $pending_count++;
            } elseif ($status === 'OVERDUE') {
                $overdue_count++;
            }

            // Timing label
            $timingType = 'on_time';
            $timingLabel = '';
            if ($dueDate && $payDate) {
                $pDateOnly = date('Y-m-d', strtotime($payDate));
                if ($pDateOnly < $dueDate) {
                    $diff = (int)round((strtotime($dueDate) - strtotime($pDateOnly)) / 86400);
                    $timingLabel = $diff > 0 ? "Early Payment ({$diff}d advance)" : 'Early Payment';
                    $timingType = 'early';
                } elseif ($pDateOnly > $dueDate) {
                    $diff = (int)round((strtotime($pDateOnly) - strtotime($dueDate)) / 86400);
                    $timingLabel = $diff > 0 ? "Late Payment ({$diff}d past due)" : 'Late Payment';
                    $timingType = 'late';
                } else {
                    $timingLabel = 'On-Time Payment';
                }
            } elseif ($status === 'OVERDUE' && $dueDate) {
                $diff = (int)round((strtotime($today) - strtotime($dueDate)) / 86400);
                $timingLabel = $diff > 0 ? "Overdue ({$diff}d late)" : 'Overdue';
                $timingType = 'overdue';
            }

            // Check if active in the requested date range
            $inPeriodPay = ($payDate && date('Y-m-d', strtotime($payDate)) >= $filter_start && date('Y-m-d', strtotime($payDate)) <= $filter_end);
            $inPeriodDue = ($dueDate && $dueDate >= $filter_start && $dueDate <= $filter_end);
            if ($inPeriodPay || $inPeriodDue) {
                $has_period_activity = true;
            }

            $ref = ($pr && !empty($pr['gcash_reference_number'])) 
                ? $pr['gcash_reference_number'] 
                : (($pr && !empty($pr['transaction_reference'])) 
                    ? $pr['transaction_reference'] 
                    : (!empty($sch['transaction_reference']) ? $sch['transaction_reference'] : '—'));

            $proofFn = ($pr && !empty($pr['proof_filename'])) ? $pr['proof_filename'] : '';
            $rcptId = $pr ? (int)$pr['receipt_id'] : 0;
            $rcptNum = ($pr && !empty($pr['receipt_number'])) ? $pr['receipt_number'] : ($rcptId > 0 ? 'RCPT-' . $rcptId : '—');

            $instItem = [
                'installment_number' => $instNum,
                'due_date'           => $dueDate ? date('M d, Y', strtotime($dueDate)) : '—',
                'due_date_raw'       => $dueDate ?: '',
                'monthly_amount'     => number_format($mAmount, 2),
                'paid_amount'        => number_format($paidAmt, 2),
                'sched_status'       => $status,
                'paid_at'            => ($payDate && $payDate !== '-') ? date('M d, Y h:i A', strtotime($payDate)) : '—',
                'paid_at_raw'        => $payDate ?: '',
                'timing_type'        => $timingType,
                'timing_label'       => $timingLabel,
                'reference'          => $ref,
                'has_proof'          => !empty($proofFn),
                'proof_filename'     => $proofFn,
                'receipt_id'         => $rcptId,
                'receipt_number'     => $rcptNum,
                'payment_method'     => ($pr && !empty($pr['payment_method'])) ? $pr['payment_method'] : ($sch['payment_method'] ?? 'GCash'),
                'rejection_reason'   => ($pr && !empty($pr['rejection_reason'])) ? $pr['rejection_reason'] : '',
                'notes'              => ($pr && !empty($pr['notes'])) ? $pr['notes'] : ''
            ];

            $installments[] = $instItem;

            // Also populate flat records array for period matching (backwards compatibility)
            if ($inPeriodPay || $inPeriodDue || ($status === 'OVERDUE' && $dueDate && $dueDate <= $today)) {
                $schedKey = $bId . '_' . $instNum;
                if (!isset($seenKeys[$schedKey])) {
                    $records[] = array_merge($instItem, [
                        'payment_id'        => $rcptId > 0 ? $rcptId : (int)($sch['id'] ?? 0),
                        'booking_id'        => $bId,
                        'customer_name'     => $b['customer_name'],
                        'customer_email'    => $b['customer_email'],
                        'contact_number'    => $b['contact_number'],
                        'section'           => $b['section'] ?: '—',
                        'plot'              => $b['plot'] ?: '—',
                        'term_months'       => $term_months,
                        'total_amount'      => number_format($total_amount, 2),
                        'remaining_balance' => number_format(max(0, $total_amount - $calc_total_paid), 2),
                        'is_receipt'        => $rcptId > 0
                    ]);
                    $seenKeys[$schedKey] = true;
                }
            }
        }

        if ($calc_total_paid <= 0 && (float)($b['amount_paid'] ?? 0) > 0) {
            $calc_total_paid = (float)$b['amount_paid'];
        }

        $rem_balance = (float)($b['remaining_balance'] ?? ($total_amount - $calc_total_paid));
        if ($rem_balance < 0) $rem_balance = 0.0;

        $overall_status = 'ACTIVE';
        if ($rem_balance <= 0 || ($paid_count >= count($installments) && count($installments) > 0)) {
            $overall_status = 'PAID';
        } elseif ($pending_count > 0) {
            $overall_status = 'FOR VERIFICATION';
        } elseif ($overdue_count > 0) {
            $overall_status = 'OVERDUE';
        } else {
            $overall_status = 'ACTIVE';
        }

        $contracts[] = [
            'booking_id'          => $bId,
            'customer_name'       => $b['customer_name'],
            'customer_email'      => $b['customer_email'],
            'contact_number'      => $b['contact_number'],
            'section'             => $b['section'] ?: '—',
            'plot'                => $b['plot'] ?: '—',
            'term_months'         => $term_months,
            'total_amount'        => number_format($total_amount, 2),
            'total_paid'          => number_format($calc_total_paid, 2),
            'remaining_balance'   => number_format($rem_balance, 2),
            'paid_count'          => $paid_count,
            'total_count'         => count($installments) ?: $term_months,
            'pending_count'       => $pending_count,
            'overdue_count'       => $overdue_count,
            'overall_status'      => $overall_status,
            'has_period_activity' => $has_period_activity,
            'installments'        => $installments
        ];
    }

    // Sort contracts: Period activity/pending first, then alphabetical by customer name
    usort($contracts, function($a, $b) {
        // Priority 1: Pending verification
        if ($a['pending_count'] > 0 && $b['pending_count'] <= 0) return -1;
        if ($b['pending_count'] > 0 && $a['pending_count'] <= 0) return 1;

        // Priority 2: Overdue
        if ($a['overdue_count'] > 0 && $b['overdue_count'] <= 0) return -1;
        if ($b['overdue_count'] > 0 && $a['overdue_count'] <= 0) return 1;

        // Priority 3: Alphabetical by customer name
        return strcasecmp($a['customer_name'], $b['customer_name']);
    });

    $inst_label = ($filter_start === $filter_end)
        ? date('M d, Y', strtotime($filter_start))
        : ((date('Y-m-01', strtotime($filter_start)) === $filter_start && date('Y-m-t', strtotime($filter_start)) === $filter_end)
            ? date('F Y', strtotime($filter_start))
            : (date('M d, Y', strtotime($filter_start)) . ' – ' . date('M d, Y', strtotime($filter_end))));

    echo json_encode([
        'success'   => true,
        'month'     => $inst_label,
        'count'     => count($contracts),
        'contracts' => $contracts,
        'records'   => $records
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_transaction_details') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    $booking_id = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
    if ($booking_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
        exit;
    }

    $safeDate = function($val, string $format = 'F d, Y', string $default = 'N/A') {
        if (empty($val) || $val === '0000-00-00' || $val === '0000-00-00 00:00:00' || $val === 'null') {
            return $default;
        }
        $ts = is_numeric($val) ? (int)$val : @strtotime((string)$val);
        return ($ts && $ts > 0) ? date($format, $ts) : $default;
    };

    try {
        $sql = "SELECT 
                    b.id AS booking_id,
                    b.service_type,
                    b.purpose,
                    b.grave_location,
                    b.section,
                    b.plot,
                    b.plot_price,
                    b.total_amount,
                    b.preferred_date,
                    b.contact_number,
                    b.booker_location,
                    b.special_requests,
                    b.payment_type,
                    b.amount_paid,
                    b.reference_number,
                    b.gcash_reference_number,
                    b.payment_proof_filename,
                    b.payment_proof_uploaded_at,
                    b.bank_name,
                    b.status,
                    b.created_at,
                    COALESCE(NULLIF(TRIM(b.customer_name), ''), NULLIF(TRIM(b.full_name), ''), NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), 'Customer') AS customer_name,
                    COALESCE(NULLIF(TRIM(b.customer_email), ''), NULLIF(TRIM(b.email), ''), NULLIF(TRIM(u.email), ''), 'N/A') AS customer_email,
                    pr.payment_date,
                    pr.payment_status,
                    pr.proof_filename AS pr_proof_filename,
                    pr.receipt_number AS pr_receipt_number,
                    pr.amount_paid AS pr_amount_paid
                FROM tbl_bookings b
                LEFT JOIN tbl_user u ON u.user_id = b.user_id
                LEFT JOIN payment_receipts pr ON pr.booking_id = b.id
                WHERE b.id = ? LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }

        $stmt->bind_param('i', $booking_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Booking not found']);
            exit;
        }

        $raw_plot_price = (float)($row['plot_price'] ?? 0);
        $raw_total_amount = (float)($row['total_amount'] ?? 0);

        // Check booking_plots sum & list
        $bpPlotTotal = 0.00;
        $bpPlotsList = [];
        $bpCheck = @$conn->query("SHOW TABLES LIKE 'booking_plots'");
        if ($bpCheck && $bpCheck->num_rows > 0) {
            $bpQ = @$conn->query("SELECT plot_number, price_at_booking FROM booking_plots WHERE booking_id = " . (int)$booking_id);
            if ($bpQ) {
                while ($bpRow = $bpQ->fetch_assoc()) {
                    $bpPlotTotal += (float)($bpRow['price_at_booking'] ?? 0);
                    if (!empty($bpRow['plot_number'])) {
                        $bpPlotsList[] = trim($bpRow['plot_number']);
                    }
                }
            }
        }

        // Sum all verified/paid receipts for this booking
        $sumPaid = 0.00;
        $prCheck = @$conn->query("SHOW TABLES LIKE 'payment_receipts'");
        if ($prCheck && $prCheck->num_rows > 0) {
            $totPaidQ = @$conn->query("SELECT SUM(amount_paid) AS total_paid FROM payment_receipts WHERE booking_id = " . (int)$booking_id . " AND UPPER(COALESCE(payment_status, '')) NOT IN ('REJECTED', 'CANCELLED')");
            if ($totPaidQ && ($tpRow = $totPaidQ->fetch_assoc())) {
                $sumPaid = (float)($tpRow['total_paid'] ?? 0);
            }
        }
        $amount_paid = max($sumPaid, (float)($row['amount_paid'] ?? 0), (float)($row['pr_amount_paid'] ?? 0));

        // Accurate total_price calculation:
        $base_price = max($raw_total_amount, $raw_plot_price, $bpPlotTotal);
        if ($amount_paid > $base_price) {
            $total_price = $amount_paid;
        } else {
            $total_price = ($base_price > 0) ? $base_price : ($amount_paid > 0 ? $amount_paid : 85000.00);
        }

        $booking_status_raw = strtolower(trim((string)($row['status'] ?? 'pending')));
        $is_cancelled = in_array($booking_status_raw, ['cancelled', 'rejected', 'declined', 'expired', 'void'], true);

        if ($is_cancelled) {
            $payment_status = 'CANCELLED';
            $balance = ($amount_paid > 0) ? max(0.00, $total_price - $amount_paid) : $total_price;
        } elseif ($amount_paid >= $total_price && $total_price > 0) {
            $balance = 0.00;
            $payment_status = 'PAID';
        } elseif ($amount_paid > 0 && $amount_paid < $total_price) {
            $balance = max(0.00, $total_price - $amount_paid);
            $payment_status = 'PARTIAL';
        } else {
            $balance = max(0.00, $total_price);
            $payment_status = strtoupper($row['payment_status'] ?? $row['status'] ?? 'PENDING');
        }

        $gcash_info = function_exists('get_gcash_payment_settings') ? get_gcash_payment_settings() : [];
        $proof_file = !empty($row['payment_proof_filename']) ? $row['payment_proof_filename'] : ($row['pr_proof_filename'] ?? null);
        $ref_number = !empty($row['reference_number']) ? $row['reference_number'] : (!empty($row['gcash_reference_number']) ? $row['gcash_reference_number'] : ($row['pr_receipt_number'] ?? 'N/A'));
        $payment_dt = !empty($row['payment_date']) ? $row['payment_date'] : (!empty($row['payment_proof_uploaded_at']) ? $row['payment_proof_uploaded_at'] : null);

        $payments_list = [];
        if ($prCheck && $prCheck->num_rows > 0) {
            $prListStmt = $conn->prepare("SELECT id, payment_schedule_id, installment_number, amount_paid, payment_method, gcash_reference_number, transaction_reference, payment_date, payment_status, proof_filename, receipt_number, rejection_reason FROM payment_receipts WHERE booking_id = ? ORDER BY id ASC");
            if ($prListStmt) {
                $prListStmt->bind_param('i', $booking_id);
                $prListStmt->execute();
                $prListRes = $prListStmt->get_result();
                while ($pItem = $prListRes->fetch_assoc()) {
                    $payments_list[] = [
                        'id' => (int)$pItem['id'],
                        'installment_number' => $pItem['installment_number'] ? (int)$pItem['installment_number'] : null,
                        'amount_paid' => number_format((float)$pItem['amount_paid'], 2),
                        'payment_method' => $pItem['payment_method'] ?: 'GCash',
                        'gcash_reference_number' => $pItem['gcash_reference_number'] ?: ($pItem['transaction_reference'] ?: '—'),
                        'receipt_number' => $pItem['receipt_number'] ?: ('RCPT-' . (int)$pItem['id']),
                        'payment_date' => $safeDate($pItem['payment_date'] ?? null, 'M d, Y h:i A', '—'),
                        'payment_status' => strtoupper($pItem['payment_status'] ?: 'PAID'),
                        'proof_filename' => $pItem['proof_filename'] ?: '',
                        'rejection_reason' => $pItem['rejection_reason'] ?: ''
                    ];
                }
                $prListStmt->close();
            }
        }

        $raw_plot = $row['plot'] ?? ($row['grave_location'] ?? '—');
        if (!empty($bpPlotsList)) {
            $plot_display = implode(', ', $bpPlotsList);
        } else {
            $plot_display = function_exists('format_plot_number') ? format_plot_number($raw_plot, $row['section'] ?? '') : $raw_plot;
        }

        $data = [
            'success' => true,
            'booking_id' => (int)$row['booking_id'],
            'customer_name' => $row['customer_name'] ?? 'Unknown',
            'customer_email' => $row['customer_email'] ?? 'N/A',
            'contact_number' => $row['contact_number'] ?? 'N/A',
            'section' => $row['section'] ?? '—',
            'plot' => $plot_display ?: '—',
            'service_type' => $row['service_type'] ?: ($row['purpose'] ?? 'Reserve Grave'),
            'grave_location' => $row['grave_location'] ?? 'Auto Assigned',
            'total_price' => (floor($total_price) == $total_price) ? number_format($total_price, 0) : number_format($total_price, 2),
            'amount_paid' => (floor($amount_paid) == $amount_paid) ? number_format($amount_paid, 0) : number_format($amount_paid, 2),
            'balance' => (floor(max(0, $balance)) == max(0, $balance)) ? number_format(max(0, $balance), 0) : number_format(max(0, $balance), 2),
            'payment_type' => $row['payment_type'] ?? 'GCash',
            'reference_number' => $ref_number,
            'bank_name' => $row['bank_name'] ?? 'GCash',
            'payment_date' => $payment_dt ? $safeDate($payment_dt, 'F d, Y • h:i A', 'N/A') : 'N/A',
            'payment_status' => $payment_status,
            'booking_date' => $safeDate($row['created_at'] ?? null, 'F d, Y', 'N/A'),
            'preferred_date' => $safeDate($row['preferred_date'] ?? null, 'F d, Y', 'N/A'),
            'booker_location' => $row['booker_location'] ?? 'N/A',
            'special_requests' => $row['special_requests'] ?? 'None',
            'proof_filename' => $proof_file,
            'gcash_account_name' => $gcash_info['account_name'] ?? 'Garden of Remembrance Memorial Park',
            'gcash_number' => $gcash_info['display_number'] ?? ($gcash_info['number'] ?? '0917 823 4567'),
            'status' => $row['status'] ?? 'pending',
            'payments_list' => $payments_list
        ];

        while (ob_get_level()) {
            ob_end_clean();
        }
        echo json_encode($data);
        exit;
    } catch (Throwable $e) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        echo json_encode([
            'success' => false,
            'message' => 'Error loading details: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Parse date filter parameters (preset or custom start/end dates)
$today = date('Y-m-d');
$date_preset = isset($_GET['date_preset']) ? trim($_GET['date_preset']) : '';
$start_date = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date']) ? $_GET['end_date'] : '';

// Backward compatibility with ?sales_month=YYYY-MM
if (empty($start_date) && empty($end_date) && empty($date_preset)) {
    if (isset($_GET['sales_month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['sales_month'])) {
        $sales_month = $_GET['sales_month'];
        $start_date = $sales_month . '-01';
        $end_date = date('Y-m-t', strtotime($start_date));
        $date_preset = ($sales_month === date('Y-m')) ? 'this_month' : 'custom';
    } else {
        $date_preset = 'this_month';
    }
}

// Compute start and end dates from preset if specified and custom dates not explicitly given
if (!empty($date_preset) && $date_preset !== 'custom') {
    switch ($date_preset) {
        case 'today':
            $start_date = $today;
            $end_date = $today;
            $sales_period_label = 'Today (' . date('M d, Y') . ')';
            break;
        case 'this_week':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date = date('Y-m-d', strtotime('sunday this week'));
            $sales_period_label = 'This Week (' . date('M d', strtotime($start_date)) . ' – ' . date('M d, Y', strtotime($end_date)) . ')';
            break;
        case 'this_month':
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
            $sales_period_label = date('F Y');
            break;
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('first day of last month'));
            $end_date = date('Y-m-t', strtotime('last day of last month'));
            $sales_period_label = 'Last Month (' . date('F Y', strtotime($start_date)) . ')';
            break;
        case 'this_quarter':
            $curMonth = (int)date('n');
            $quarter = (int)ceil($curMonth / 3);
            $qStartMonth = ($quarter - 1) * 3 + 1;
            $start_date = date('Y-' . sprintf('%02d', $qStartMonth) . '-01');
            $end_date = date('Y-m-t', strtotime(date('Y-' . sprintf('%02d', $qStartMonth + 2) . '-01')));
            $sales_period_label = "Q{$quarter} " . date('Y') . ' (' . date('M d', strtotime($start_date)) . ' – ' . date('M d, Y', strtotime($end_date)) . ')';
            break;
        case 'this_year':
            $start_date = date('Y-01-01');
            $end_date = date('Y-12-31');
            $sales_period_label = 'Year ' . date('Y');
            break;
        case 'all':
            $start_date = '2020-01-01';
            $end_date = $today;
            $sales_period_label = 'All Time';
            break;
        default:
            $date_preset = 'this_month';
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
            $sales_period_label = date('F Y');
            break;
    }
} else {
    $date_preset = 'custom';
    if (empty($start_date)) $start_date = date('Y-m-01');
    if (empty($end_date)) $end_date = date('Y-m-t');
    if ($start_date > $end_date) {
        $temp = $start_date;
        $start_date = $end_date;
        $end_date = $temp;
    }
    if ($start_date === $end_date) {
        $sales_period_label = ($start_date === $today) ? 'Today (' . date('M d, Y') . ')' : date('M d, Y', strtotime($start_date));
    } elseif (date('Y-m-01', strtotime($start_date)) === $start_date && date('Y-m-t', strtotime($start_date)) === $end_date) {
        $sales_period_label = date('F Y', strtotime($start_date));
    } else {
        $sales_period_label = date('M d, Y', strtotime($start_date)) . ' – ' . date('M d, Y', strtotime($end_date));
    }
}

// Variables for legacy compatibility
$sales_month = date('Y-m', strtotime($start_date));
$sales_month_start = $start_date;
$sales_month_end = $end_date;
$sales_month_label = $sales_period_label;

$monthly_sales_total = 0;
$total_revenue = 0;
$paid_txn_count = 0;
$total_booking_count = 0;
$paid_booking_count = 0;
$pending_payment_count = 0;
$monthly_transactions = [];

// Heal and align tbl_bookings records where total_amount was mistakenly set to downpayment (e.g. 17,000) instead of full plot price (85,000)
@$conn->query("UPDATE tbl_bookings b 
               INNER JOIN (
                   SELECT booking_id, SUM(amount_paid) AS total_paid 
                   FROM payment_receipts 
                   WHERE UPPER(COALESCE(payment_status, '')) NOT IN ('REJECTED', 'CANCELLED') 
                   GROUP BY booking_id
               ) pr_tot ON pr_tot.booking_id = b.id
               SET b.amount_paid = GREATEST(b.amount_paid, pr_tot.total_paid),
                   b.total_amount = GREATEST(b.total_amount, b.plot_price, pr_tot.total_paid),
                   b.remaining_balance = GREATEST(0, GREATEST(b.total_amount, b.plot_price, pr_tot.total_paid) - GREATEST(b.amount_paid, pr_tot.total_paid))
               WHERE pr_tot.total_paid > b.total_amount OR b.plot_price > b.total_amount");

@$conn->query("UPDATE tbl_bookings SET total_amount = plot_price WHERE (total_amount < plot_price OR total_amount <= 0) AND plot_price > 0");
@$conn->query("UPDATE tbl_bookings SET total_amount = amount_paid WHERE amount_paid > total_amount AND amount_paid > 0");
@$conn->query("UPDATE tbl_bookings SET remaining_balance = 0, payment_status = 'PAID' WHERE amount_paid >= total_amount AND total_amount > 0 AND payment_status != 'PAID'");

$month_transactions_sql = "SELECT
                            b.id AS booking_id,
                            b.user_id,
                            b.section,
                            b.plot,
                            b.status AS booking_status,
                            b.total_amount,
                            b.plot_price,
                            b.amount_paid AS booking_amount_paid,
                            b.created_at,
                            b.purpose,
                            b.service_type,
                            b.payment_type,
                            COALESCE(NULLIF(b.reference_number, ''), '') AS b_ref,
                            COALESCE(NULLIF(b.payment_proof_filename, ''), NULLIF(MAX(pr.proof_filename), '')) AS proof_filename,
                            MAX(pr.payment_date) AS payment_date,
                            MAX(pr.payment_status) AS payment_status,
                            MAX(pr.receipt_number) AS pr_receipt_number,
                            MAX(pr.gcash_reference_number) AS pr_gcash_ref,
                            MAX(pr.transaction_reference) AS pr_txn_ref,
                            COALESCE(NULLIF(TRIM(b.customer_name), ''), NULLIF(TRIM(b.full_name), ''), NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), 'Customer') AS customer_name,
                            COALESCE(NULLIF(TRIM(b.customer_email), ''), NULLIF(TRIM(b.email), ''), u.email, '') AS customer_email,
                            COALESCE(NULLIF(TRIM(b.contact_number), ''), u.phone_no, '') AS contact_number,
                            COALESCE(SUM(pr.amount_paid), 0) AS total_payment_received
                         FROM tbl_bookings b
                         LEFT JOIN tbl_user u ON u.user_id = b.user_id
                         LEFT JOIN payment_receipts pr ON pr.booking_id = b.id AND LOWER(TRIM(COALESCE(pr.payment_status, ''))) NOT IN ('rejected', 'cancelled')
                         WHERE (1=1)
                           AND (
                               DATE(b.created_at) BETWEEN ? AND ?
                               OR (pr.payment_date IS NOT NULL AND DATE(pr.payment_date) BETWEEN ? AND ?)
                           )
                         GROUP BY b.id, b.user_id, b.section, b.plot, b.status, b.total_amount, b.plot_price, b.amount_paid, b.created_at, b.purpose, b.service_type, b.payment_type, b.reference_number, b.payment_proof_filename, b.customer_name, b.full_name, u.first_name, u.last_name, b.customer_email, b.email, u.email, b.contact_number, u.phone_no
                         ORDER BY COALESCE(MAX(pr.payment_date), b.created_at) DESC";

$month_transactions_stmt = $conn->prepare($month_transactions_sql);
if ($month_transactions_stmt) {
    $month_transactions_stmt->bind_param('ssss', $sales_month_start, $sales_month_end, $sales_month_start, $sales_month_end);
    $month_transactions_stmt->execute();
    $month_transactions_result = $month_transactions_stmt->get_result();
    while ($row = $month_transactions_result->fetch_assoc()) {
        $raw_plot_price = (float)($row['plot_price'] ?? 0);
        $raw_total_amount = (float)($row['total_amount'] ?? 0);
        $receipt_total = (float)($row['total_payment_received'] ?? 0);
        $booking_paid_total = (float)($row['booking_amount_paid'] ?? 0);
        $effective_amount_paid = max($receipt_total, $booking_paid_total);

        // Ensure total_price is at least plot price or amount paid, never downpayment
        $base_price = max($raw_total_amount, $raw_plot_price);
        if ($effective_amount_paid > $base_price) {
            $total_price = $effective_amount_paid;
        } else {
            $total_price = ($base_price > 0) ? $base_price : ($effective_amount_paid > 0 ? $effective_amount_paid : 85000.00);
        }

        $payment_date = $row['payment_date'] ?? $row['created_at'] ?? null;
        $payment_status = strtoupper((string)($row['payment_status'] ?? $row['booking_status'] ?? 'PENDING'));

        $b_status = strtolower(trim((string)($row['booking_status'] ?? '')));
        $p_status = strtoupper(trim((string)($row['payment_status'] ?? '')));

        // Determine if booking is cancelled/rejected
        $is_cancelled = in_array($b_status, ['cancelled', 'rejected', 'declined', 'expired', 'void'], true) || in_array(strtolower($p_status), ['cancelled', 'rejected']);

        if ($is_cancelled) {
            $payment_status = 'CANCELLED';
            $filter_type = 'cancelled';
        } elseif ($effective_amount_paid >= $total_price && $total_price > 0) {
            $payment_status = 'PAID';
            $filter_type = 'paid';
        } elseif ($effective_amount_paid > 0) {
            $payment_status = 'PARTIAL';
            $filter_type = 'paid';
        } else {
            $payment_status = 'UNPAID';
            $filter_type = 'pending';
        }

        // Only add to monthly sales total if active and payment received > 0
        if (!$is_cancelled && $effective_amount_paid > 0 && $payment_status !== 'UNPAID') {
            $monthly_sales_total += $effective_amount_paid;
            $paid_txn_count++;
        }

        $ref_num = !empty($row['pr_gcash_ref']) ? $row['pr_gcash_ref'] : (!empty($row['pr_txn_ref']) ? $row['pr_txn_ref'] : (!empty($row['b_ref']) ? $row['b_ref'] : '—'));
        $rcpt_num = !empty($row['pr_receipt_number']) ? $row['pr_receipt_number'] : ('RCPT-' . (int)$row['booking_id']);

        $monthly_transactions[] = [
            'booking_id' => (int)($row['booking_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'section' => $row['section'] ?? '—',
            'plot' => format_plot_number($row['plot'] ?? '—', $row['section'] ?? ''),
            'status' => $row['booking_status'] ?? 'pending',
            'payment_status' => $payment_status,
            'filter_type' => $filter_type,
            'total_amount' => $total_price,
            'plot_price' => (float)($row['plot_price'] ?? 0),
            'amount_paid' => $effective_amount_paid,
            'payment_date' => $payment_date,
            'customer_name' => $row['customer_name'] ?? 'Unknown',
            'customer_email' => $row['customer_email'] ?? '',
            'contact_number' => $row['contact_number'] ?? '',
            'service_type' => $row['service_type'] ?: ($row['purpose'] ?? 'Grave Reservation'),
            'purpose' => $row['purpose'] ?? '',
            'payment_type' => $row['payment_type'] ?? 'GCash',
            'reference_number' => $ref_num,
            'receipt_number' => $rcpt_num,
            'proof_filename' => $row['proof_filename'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }
    $month_transactions_stmt->close();
}

// =========================================================================
// ACTION: EXPORT SALES & FINANCIAL REPORT TO CSV
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'export_sales_csv') {
    while (ob_get_level()) { ob_end_clean(); }
    $filename = "Garden_of_Remembrance_Sales_Report_{$start_date}_to_{$end_date}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Microsoft Excel
    
    fputcsv($output, ['Garden of Remembrance Memorial Park']);
    fputcsv($output, ['Official Sales & Financial Report']);
    fputcsv($output, ['Reporting Period:', $sales_period_label . " ({$start_date} to {$end_date})"]);
    fputcsv($output, ['Generated Date:', date('F d, Y h:i A')]);
    fputcsv($output, ['Generated By:', trim(($user['first_name'] ?? 'Admin') . ' ' . ($user['last_name'] ?? ''))]);
    fputcsv($output, []);
    
    fputcsv($output, [
        'Reservation ID',
        'Date',
        'Customer Name',
        'Email Address',
        'Contact Number',
        'Section',
        'Plot',
        'Service / Purpose',
        'Reservation Status',
        'Payment Status',
        'Total Price (PHP)',
        'Amount Paid (PHP)',
        'Balance (PHP)',
        'Payment Method',
        'Reference / GCash No.',
        'Official Receipt No.'
    ]);
    
    $expFilter = isset($_GET['filter']) ? strtolower(trim($_GET['filter'])) : 'all';
    $sumTotal = 0;
    $sumPaid = 0;
    $sumBal = 0;
    $recCount = 0;
    
    foreach ($monthly_transactions as $tx) {
        if ($expFilter === 'paid' && $tx['filter_type'] !== 'paid') continue;
        if ($expFilter === 'pending' && $tx['filter_type'] !== 'pending') continue;
        if ($expFilter === 'cancelled' && $tx['filter_type'] !== 'cancelled') continue;
        if ($expFilter === 'active' && !in_array($tx['filter_type'], ['paid', 'pending'])) continue;
        
        $recCount++;
        $tot = (float)$tx['total_amount'];
        $paid = (float)$tx['amount_paid'];
        $bal = max(0, $tot - $paid);
        $sumTotal += $tot;
        $sumPaid += $paid;
        $sumBal += $bal;
        
        $txDate = !empty($tx['payment_date']) ? date('Y-m-d H:i', strtotime($tx['payment_date'])) : (!empty($tx['created_at']) ? date('Y-m-d H:i', strtotime($tx['created_at'])) : '—');
        
        fputcsv($output, [
            '#' . $tx['booking_id'],
            $txDate,
            $tx['customer_name'],
            $tx['customer_email'],
            $tx['contact_number'],
            $tx['section'],
            $tx['plot'],
            $tx['service_type'],
            strtoupper($tx['status']),
            $tx['payment_status'],
            number_format($tot, 2, '.', ''),
            number_format($paid, 2, '.', ''),
            number_format($bal, 2, '.', ''),
            $tx['payment_type'],
            $tx['reference_number'],
            $tx['receipt_number']
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, [
        'TOTALS (' . $recCount . ' records)',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        number_format($sumTotal, 2, '.', ''),
        number_format($sumPaid, 2, '.', ''),
        number_format($sumBal, 2, '.', ''),
        '',
        '',
        ''
    ]);
    
    fclose($output);
    exit;
}

// =========================================================================
// ACTION: EXPORT INSTALLMENT AMORTIZATION LEDGER TO CSV
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'export_installment_csv') {
    while (ob_get_level()) { ob_end_clean(); }
    $filename = "Garden_of_Remembrance_Installment_Ledger_{$start_date}_to_{$end_date}.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Garden of Remembrance Memorial Park']);
    fputcsv($output, ['Installment Collections & Amortization Schedule Ledger']);
    fputcsv($output, ['Reporting Period:', $sales_period_label . " ({$start_date} to {$end_date})"]);
    fputcsv($output, ['Generated Date:', date('F d, Y h:i A')]);
    fputcsv($output, ['Generated By:', trim(($user['first_name'] ?? 'Admin') . ' ' . ($user['last_name'] ?? ''))]);
    fputcsv($output, []);
    
    fputcsv($output, [
        'Payment ID',
        'Receipt No.',
        'Reservation ID',
        'Customer Name',
        'Email Address',
        'Contact Number',
        'Section & Plot',
        'Installment No.',
        'Payment Term',
        'Monthly Due (PHP)',
        'Amount Paid (PHP)',
        'Due Date',
        'Paid Date',
        'Timing Classification',
        'Payment Method',
        'Reference / GCash No.',
        'Payment Status'
    ]);

    $sqlPR = "SELECT
                pr.id AS receipt_id,
                pr.receipt_number,
                pr.booking_id,
                pr.installment_number,
                pr.amount_paid,
                pr.payment_date,
                pr.payment_status,
                pr.gcash_reference_number,
                pr.transaction_reference,
                pr.payment_method,
                COALESCE(NULLIF(TRIM(b.customer_name), ''), NULLIF(TRIM(b.full_name), ''), CONCAT_WS(' ', u.first_name, u.last_name), 'Customer') AS customer_name,
                COALESCE(NULLIF(TRIM(b.customer_email), ''), NULLIF(TRIM(b.email), ''), u.email, '') AS customer_email,
                COALESCE(NULLIF(TRIM(b.contact_number), ''), u.phone_no, '') AS contact_number,
                b.section,
                b.plot,
                b.payment_term AS term_months,
                COALESCE(ps.monthly_amount, bps.amount_due, pr.amount_paid) AS monthly_amount,
                COALESCE(ps.due_date, bps.due_date) AS due_date
            FROM payment_receipts pr
            LEFT JOIN tbl_bookings b ON b.id = pr.booking_id
            LEFT JOIN tbl_user u ON u.user_id = b.user_id
            LEFT JOIN payment_schedules ps ON (ps.contract_id = pr.booking_id AND ps.installment_number = pr.installment_number)
            LEFT JOIN booking_payment_schedule bps ON (bps.booking_id = pr.booking_id AND bps.installment_number = pr.installment_number)
            WHERE pr.installment_number IS NOT NULL AND pr.installment_number > 0
              AND (
                  DATE(pr.payment_date) BETWEEN '$start_date' AND '$end_date'
                  OR (ps.due_date IS NOT NULL AND DATE(ps.due_date) BETWEEN '$start_date' AND '$end_date')
                  OR (bps.due_date IS NOT NULL AND DATE(bps.due_date) BETWEEN '$start_date' AND '$end_date')
              )
            ORDER BY pr.payment_date DESC, pr.id DESC";

    $instRes = $conn->query($sqlPR);
    $instCount = 0;
    $instTotalPaid = 0;
    $instTotalDue = 0;
    if ($instRes) {
        while ($ir = $instRes->fetch_assoc()) {
            $instCount++;
            $mDue = (float)$ir['monthly_amount'];
            $aPaid = (float)$ir['amount_paid'];
            $instTotalDue += $mDue;
            $instTotalPaid += $aPaid;
            
            $dDate = !empty($ir['due_date']) ? date('Y-m-d', strtotime($ir['due_date'])) : '—';
            $pDate = !empty($ir['payment_date']) ? date('Y-m-d H:i', strtotime($ir['payment_date'])) : '—';
            
            $timing = 'On-Time';
            if ($dDate !== '—' && $pDate !== '—') {
                $pDay = date('Y-m-d', strtotime($ir['payment_date']));
                if ($pDay < $dDate) $timing = 'Early Payment';
                elseif ($pDay > $dDate) $timing = 'Late Payment';
            }
            
            $ref = !empty($ir['gcash_reference_number']) ? $ir['gcash_reference_number'] : (!empty($ir['transaction_reference']) ? $ir['transaction_reference'] : '—');
            $rcpt = !empty($ir['receipt_number']) ? $ir['receipt_number'] : ('RCPT-' . (int)$ir['receipt_id']);
            
            fputcsv($output, [
                '#' . (int)$ir['receipt_id'],
                $rcpt,
                '#' . (int)$ir['booking_id'],
                $ir['customer_name'],
                $ir['customer_email'],
                $ir['contact_number'],
                $ir['section'] . ' ' . $ir['plot'],
                '#' . (int)$ir['installment_number'],
                (int)($ir['term_months'] ?: 12) . ' Months',
                number_format($mDue, 2, '.', ''),
                number_format($aPaid, 2, '.', ''),
                $dDate,
                $pDate,
                $timing,
                $ir['payment_method'] ?: 'GCash',
                $ref,
                strtoupper($ir['payment_status'] ?: 'PAID')
            ]);
        }
    }
    
    fputcsv($output, []);
    fputcsv($output, [
        'TOTALS (' . $instCount . ' records)',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        number_format($instTotalDue, 2, '.', ''),
        number_format($instTotalPaid, 2, '.', ''),
        '',
        '',
        '',
        '',
        '',
        ''
    ]);
    
    fclose($output);
    exit;
}

$total_revenue_sql = "SELECT COALESCE(SUM(pr.amount_paid), 0) as total 
                      FROM payment_receipts pr
                      LEFT JOIN tbl_bookings b ON b.id = pr.booking_id
                      WHERE pr.amount_paid > 0 AND LOWER(TRIM(COALESCE(pr.payment_status, ''))) != 'rejected' AND b.status NOT IN ('cancelled', 'rejected')";
$total_revenue_result = $conn->query($total_revenue_sql);
if ($total_revenue_result) {
    $row = $total_revenue_result->fetch_assoc();
    $total_revenue = (float)($row['total'] ?? 0);
}

$total_bookings_sql = "SELECT COUNT(*) AS total FROM tbl_bookings";
$total_bookings_result = $conn->query($total_bookings_sql);
if ($total_bookings_result) {
    $row = $total_bookings_result->fetch_assoc();
    $total_booking_count = (int)($row['total'] ?? 0);

    $actRes = $conn->query("SELECT SUM(CASE WHEN LOWER(TRIM(status)) NOT IN ('rejected', 'cancelled') THEN 1 ELSE 0 END) AS active_cnt FROM tbl_bookings");
    $active_booking_count = ($actRes && ($actRow = $actRes->fetch_assoc())) ? (int)($actRow['active_cnt'] ?? 0) : $total_booking_count;
    $cancelled_booking_count = max(0, $total_booking_count - $active_booking_count);
}

$paid_bookings_sql = "SELECT COUNT(DISTINCT b.id) AS total 
                      FROM tbl_bookings b
                      LEFT JOIN payment_receipts pr ON pr.booking_id = b.id AND LOWER(TRIM(COALESCE(pr.payment_status, ''))) != 'rejected'
                      WHERE b.status NOT IN ('rejected', 'cancelled')
                      AND (pr.amount_paid > 0 OR b.amount_paid > 0)";
$paid_bookings_result = $conn->query($paid_bookings_sql);
if ($paid_bookings_result) {
    $row = $paid_bookings_result->fetch_assoc();
    $paid_booking_count = (int)($row['total'] ?? 0);
}

$pending_payments_sql = "SELECT COUNT(DISTINCT b.id) AS total 
                         FROM tbl_bookings b
                         WHERE b.status NOT IN ('rejected', 'cancelled')
                         AND b.amount_paid <= 0 AND (SELECT COALESCE(SUM(amount_paid), 0) FROM payment_receipts WHERE booking_id = b.id AND LOWER(TRIM(COALESCE(payment_status, ''))) != 'rejected') <= 0";
$pending_payments_result = $conn->query($pending_payments_sql);
if ($pending_payments_result) {
    $row = $pending_payments_result->fetch_assoc();
    $pending_payment_count = (int)($row['total'] ?? 0);
}

$chart_monthly = [];
$refMonthStart = date('Y-m-01', strtotime($sales_month_end));
for ($i = 5; $i >= 0; $i--) {
    $mKey = date('Y-m', strtotime("$refMonthStart -$i months"));
    $mLabel = date('M Y', strtotime("$refMonthStart -$i months"));
    $chart_monthly[$mKey] = [
        'label' => $mLabel,
        'sales' => 0.0,
        'count' => 0,
        'selected' => ($mKey === date('Y-m', strtotime($sales_month_start)))
    ];
}

$monthly_chart_sql = "SELECT 
    DATE_FORMAT(COALESCE(pr.payment_date, b.created_at), '%Y-%m') AS ym,
    COUNT(DISTINCT b.id) AS booking_count,
    SUM(CASE 
        WHEN (SELECT COALESCE(SUM(amount_paid), 0) FROM payment_receipts WHERE booking_id = b.id AND LOWER(TRIM(COALESCE(payment_status, ''))) != 'rejected') > 0 
        THEN (SELECT COALESCE(SUM(amount_paid), 0) FROM payment_receipts WHERE booking_id = b.id AND LOWER(TRIM(COALESCE(payment_status, ''))) != 'rejected')
        ELSE b.amount_paid 
    END) AS total_sales
FROM tbl_bookings b
LEFT JOIN payment_receipts pr ON pr.booking_id = b.id AND LOWER(TRIM(COALESCE(pr.payment_status, ''))) != 'rejected'
WHERE b.status NOT IN ('rejected', 'cancelled')
GROUP BY ym";

$monthly_chart_res = $conn->query($monthly_chart_sql);
if ($monthly_chart_res) {
    while ($mRow = $monthly_chart_res->fetch_assoc()) {
        $ym = $mRow['ym'];
        if (isset($chart_monthly[$ym])) {
            $chart_monthly[$ym]['sales'] = (float)$mRow['total_sales'];
            $chart_monthly[$ym]['count'] = (int)$mRow['booking_count'];
        }
    }
}

// Generate daily or sub-range chart buckets dynamically based on date range
$chart_daily = [];
$range_days = (int)round((strtotime($sales_month_end) - strtotime($sales_month_start)) / 86400) + 1;

if ($range_days <= 31) {
    $curT = strtotime($sales_month_start);
    $endT = strtotime($sales_month_end);
    while ($curT <= $endT) {
        $dayKey = date('Y-m-d', $curT);
        $chart_daily[$dayKey] = [
            'label' => date('M j', $curT),
            'sales' => 0.0,
            'count' => 0
        ];
        $curT = strtotime('+1 day', $curT);
    }

    foreach ($monthly_transactions as $tx) {
        $txDate = $tx['payment_date'] ?? $tx['created_at'];
        if ($txDate) {
            $dayKey = date('Y-m-d', strtotime($txDate));
            if (isset($chart_daily[$dayKey])) {
                $chart_daily[$dayKey]['sales'] += (float)($tx['amount_paid'] ?? 0);
                $chart_daily[$dayKey]['count']++;
            }
        }
    }
} else {
    // For ranges longer than a month, group buckets by month
    $curT = strtotime(date('Y-m-01', strtotime($sales_month_start)));
    $endT = strtotime(date('Y-m-01', strtotime($sales_month_end)));
    while ($curT <= $endT) {
        $mKey = date('Y-m', $curT);
        $chart_daily[$mKey] = [
            'label' => date('M Y', $curT),
            'sales' => 0.0,
            'count' => 0
        ];
        $curT = strtotime('+1 month', $curT);
    }

    foreach ($monthly_transactions as $tx) {
        $txDate = $tx['payment_date'] ?? $tx['created_at'];
        if ($txDate) {
            $mKey = date('Y-m', strtotime($txDate));
            if (isset($chart_daily[$mKey])) {
                $chart_daily[$mKey]['sales'] += (float)($tx['amount_paid'] ?? 0);
                $chart_daily[$mKey]['count']++;
            }
        }
    }
}

// Generate Section distribution chart for filtered period
$chart_sections = [];
$sec_sales_map = [];
$sec_count_map = [];
foreach ($monthly_transactions as $tx) {
    if (($tx['filter_type'] ?? '') === 'cancelled') continue;
    $sec = strtoupper(trim((string)($tx['section'] ?? '')));
    if (empty($sec) || $sec === '—') $sec = 'SERVICES';
    if (!isset($sec_sales_map[$sec])) {
        $sec_sales_map[$sec] = 0.0;
        $sec_count_map[$sec] = 0;
    }
    $sec_sales_map[$sec] += (float)($tx['amount_paid'] ?? 0);
    $sec_count_map[$sec]++;
}
ksort($sec_sales_map);
foreach ($sec_sales_map as $secCode => $sAmt) {
    $secLabel = (strlen($secCode) === 1) ? "Section $secCode" : ucfirst(strtolower($secCode));
    $chart_sections[] = [
        'label' => $secLabel,
        'sales' => (float)$sAmt,
        'count' => (int)($sec_count_map[$secCode] ?? 0)
    ];
}
if (empty($chart_sections)) {
    $chart_sections = [
        ['label' => 'Section A', 'sales' => 0.0, 'count' => 0],
        ['label' => 'Section B', 'sales' => 0.0, 'count' => 0],
        ['label' => 'Section C', 'sales' => 0.0, 'count' => 0],
        ['label' => 'Section D', 'sales' => 0.0, 'count' => 0],
        ['label' => 'Section E', 'sales' => 0.0, 'count' => 0],
        ['label' => 'Section F', 'sales' => 0.0, 'count' => 0],
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales & Reports - Garden of Remembrance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="includes/admin_header.css">
    <style>
        :root {
            --brand: #7b1113;
            --brand-dark: #610d10;
            --brand-soft: #fdf2f2;
            --accent: #a03a3c;
            --bg: #f8fafc;
            --panel: #ffffff;
            --text: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
        }

        .swal2-container {
            z-index: 100000 !important;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            background: #f8fafc;
            min-height: 100vh;
            margin: 0;
            padding: 0;
            color: var(--text);
        }

        a {
            text-decoration: none;
        }

        .main-container {
            position: relative;
            min-height: 100vh;
            padding-top: 0;
        }

        .content-wrapper {
            max-width: 1280px;
            margin: 0 auto;
            padding: 1.5rem 1.5rem 3rem;
        }

        .page-header {
            text-align: center;
            margin-bottom: 1.5rem;
            padding-top: 0.25rem;
        }

        .page-header h1 {
            color: var(--brand);
            margin: 0 0 0.25rem;
            font-size: 1.75rem;
            line-height: 1.2;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .page-header p {
            margin: 0;
            color: var(--accent);
            font-size: 0.82rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .controls-section {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            margin-bottom: 1.25rem;
        }

        .filter-card {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 0.65rem 1rem;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }

        .filter-form {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.65rem;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .filter-label {
            font-weight: 700;
            font-size: 0.82rem;
            color: #475569;
            margin: 0;
            white-space: nowrap;
        }

        .filter-select {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            padding: 0.38rem 0.65rem;
            font-size: 0.84rem;
            font-weight: 600;
            color: var(--text);
            min-width: 135px;
            cursor: pointer;
        }

        .filter-select:focus,
        .filter-date-control:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(123, 17, 19, 0.12);
            outline: none;
        }

        .filter-date-inputs {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .date-input-wrap {
            display: flex;
            align-items: center;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #ffffff;
            overflow: hidden;
            transition: border-color 0.15s ease;
        }

        .date-input-wrap:focus-within {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(123, 17, 19, 0.12);
        }

        .date-prefix {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--muted);
            background: #f8fafc;
            padding: 0.38rem 0.55rem;
            border-right: 1px solid #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .filter-date-control {
            border: none;
            background: transparent;
            padding: 0.35rem 0.55rem;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text);
            max-width: 135px;
            outline: none;
        }

        .filter-btn-group {
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .btn-filter-apply {
            background-color: var(--brand);
            border: 1px solid var(--brand);
            color: #ffffff;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.82rem;
            padding: 0.38rem 0.95rem;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
        }

        .btn-filter-apply:hover {
            background-color: var(--brand-dark);
            border-color: var(--brand-dark);
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(123, 17, 19, 0.25);
        }

        .btn-filter-reset {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #475569;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.82rem;
            padding: 0.38rem 0.75rem;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
        }

        .btn-filter-reset:hover {
            background-color: #f1f5f9;
            color: var(--brand);
            border-color: #94a3b8;
        }

        .toolbar-actions-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-export {
            background-color: #ffffff;
            border: 1.5px solid #16a34a;
            color: #15803d;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.82rem;
            padding: 0.38rem 0.95rem;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
        }

        .btn-export:hover, .btn-export:focus {
            background-color: #16a34a;
            color: #ffffff;
            border-color: #16a34a;
        }

        .btn-export:hover .text-success,
        .btn-export:focus .text-success {
            color: #ffffff !important;
        }

        .btn-export-sm {
            background-color: #ffffff;
            border: 1px solid #16a34a;
            color: #15803d;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.8rem;
            padding: 4px 12px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-export-sm:hover {
            background-color: #16a34a;
            color: #ffffff;
        }

        .btn-export-sm:hover .text-success {
            color: #ffffff !important;
        }

        .active-filter-banner {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
            padding: 0.45rem 0.85rem;
            background: #fff8f8;
            border: 1px dashed rgba(123, 17, 19, 0.25);
            border-radius: 8px;
        }

        .active-filter-tag {
            font-size: 0.82rem;
            color: var(--brand);
            font-weight: 600;
        }

        .btn-clear-filter {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--brand);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: opacity 0.15s ease;
        }

        .btn-clear-filter:hover {
            text-decoration: underline;
            color: var(--brand-dark);
        }

        .btn-reminders {
            background-color: #ffffff;
            border: 1.5px solid var(--brand);
            color: var(--brand);
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.82rem;
            padding: 0.38rem 0.95rem;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-reminders:hover {
            background-color: var(--brand);
            color: #ffffff;
        }

        .btn-print-invoice {
            background-color: #ffffff;
            border: 1.5px solid var(--brand);
            color: var(--brand);
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.82rem;
            padding: 0.38rem 0.95rem;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-print-invoice:hover {
            background-color: var(--brand);
            color: #ffffff;
        }

        .btn-print-invoice-sm {
            background-color: #ffffff;
            border: 1px solid var(--brand);
            color: var(--brand);
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.8rem;
            padding: 4px 12px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-print-invoice-sm:hover {
            background-color: var(--brand);
            color: #ffffff;
        }

        .btn-installment-payments {
            background-color: #ffffff;
            border: 1px solid #1d4ed8;
            color: #1d4ed8;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.8rem;
            padding: 4px 12px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .btn-installment-payments:hover {
            background-color: #1d4ed8;
            color: #ffffff;
        }

        .btn-invoice-row {
            background-color: rgba(123, 17, 19, 0.08);
            border: 1px solid rgba(123, 17, 19, 0.25);
            color: var(--brand);
            border-radius: 6px;
            font-size: 0.76rem;
            font-weight: 700;
            padding: 4px 10px;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-invoice-row:hover {
            background-color: var(--brand);
            color: #ffffff;
            border-color: var(--brand);
        }

        @media print {
            .no-print, .controls-section, .btn-print-invoice, .btn-print-invoice-sm, .btn-invoice-row, .btn-installment-payments, .chart-actions, .site-navbar, .admin-topbar, .mobile-drawer, .site-footer {
                display: none !important;
            }
            body, .main-container, .content-wrapper {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .sales-chart-card, .transactions-section, .summary-cards {
                box-shadow: none !important;
                border: 1px solid #e2e8f0 !important;
            }
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .card-primary {
            background: var(--panel);
            border: 1px solid var(--border);
            border-top: 3px solid var(--brand);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
            padding: 0.9rem 1rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .card-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
        }

        .card-filterable {
            cursor: pointer;
            position: relative;
            user-select: none;
            transition: all 0.2s ease;
        }

        .card-filterable:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(123, 17, 19, 0.12);
            border-color: rgba(123, 17, 19, 0.4);
        }

        .card-filterable.active {
            border-color: var(--brand) !important;
            background: #fff8f8 !important;
            box-shadow: 0 0 0 2px var(--brand), 0 8px 20px rgba(123, 17, 19, 0.15) !important;
        }

        .card-filter-hint {
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--brand);
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 5px;
            opacity: 0.85;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .txn-tab-btn {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 5px 12px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .txn-tab-btn:hover {
            border-color: var(--brand);
            color: var(--brand);
            background: #fff8f8;
        }

        .txn-tab-btn.active {
            background: var(--brand) !important;
            color: #ffffff !important;
            border-color: var(--brand) !important;
        }

        .txn-tab-btn.active .badge {
            background-color: rgba(255, 255, 255, 0.28) !important;
            color: #ffffff !important;
        }

        .card-label {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--muted);
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 0.35rem;
            line-height: 1.2;
        }

        .card-value {
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1.2;
            color: var(--brand);
            word-break: break-word;
        }

        /* Sales Bar Chart Styling */
        .sales-chart-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: box-shadow 0.15s ease;
        }

        .sales-chart-card:hover {
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        }

        .chart-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.25rem;
            padding-bottom: 0.85rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .chart-title {
            margin: 0;
            color: var(--brand);
            font-weight: 800;
            font-size: 1.15rem;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
        }

        .chart-subtitle {
            margin: 0.2rem 0 0;
            color: var(--muted);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .chart-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .chart-toggle-group {
            background: #f1f5f9;
            border-radius: 8px;
            padding: 3px;
            display: inline-flex;
            border: 1px solid var(--border);
        }

        .chart-toggle-btn {
            border: none;
            background: transparent;
            color: #64748b;
            font-weight: 600;
            font-size: 0.78rem;
            padding: 0.35rem 0.85rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .chart-toggle-btn:hover {
            color: var(--brand);
        }

        .chart-toggle-btn.active {
            background: #ffffff;
            color: var(--brand);
            font-weight: 700;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .chart-stat-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: var(--brand-soft);
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .stat-pill-label {
            color: var(--muted);
            font-weight: 600;
        }

        .stat-pill-value {
            color: var(--brand);
            font-weight: 800;
        }

        .chart-canvas-wrapper {
            position: relative;
            width: 100%;
            height: 310px;
        }

        @media (max-width: 768px) {
            .chart-header-row {
                flex-direction: column;
                align-items: flex-start;
            }
            .chart-actions {
                width: 100%;
                justify-content: space-between;
            }
            .chart-canvas-wrapper {
                height: 250px;
            }
        }

        .transactions-section {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
            padding: 1.25rem;
            overflow: hidden;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .section-header h3 {
            margin: 0;
            color: var(--brand);
            font-weight: 800;
            font-size: 1.15rem;
        }

        .entry-badge {
            background: var(--brand-soft);
            color: var(--brand);
            border: 1px solid #fecaca;
            border-radius: 999px;
            padding: 0.3rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .table-responsive {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid var(--border);
        }

        .transactions-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 860px;
            background: #fff;
            font-size: 0.84rem;
        }

        .transactions-table thead {
            background: #f8fafc;
            border-bottom: 2px solid var(--border);
        }

        .transactions-table th {
            padding: 0.65rem 0.75rem;
            text-align: left;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .transactions-table td {
            padding: 0.65rem 0.75rem;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text);
            vertical-align: middle;
        }

        .transactions-table tbody tr {
            cursor: pointer;
            transition: background-color 0.15s ease;
        }

        .transactions-table tbody tr:hover {
            background-color: #fdf8f8;
        }

        .booking-id {
            font-weight: 700;
            color: var(--brand);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .status-paid {
            background-color: #dcfce7;
            color: #166534;
        }

        .status-partial {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-pending {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .status-unpaid {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .status-cancelled {
            background-color: #f1f5f9;
            color: #475569;
        }

        .empty-state {
            padding: 2.5rem 1rem;
            text-align: center;
            color: var(--muted);
            font-weight: 600;
        }

        .empty-state-icon {
            font-size: 2.2rem;
            margin-bottom: 0.5rem;
            opacity: 0.7;
        }

        .modal-content {
            background: #fff;
            color: #111827;
            border: none;
            border-radius: 1rem;
            box-shadow: 0 16px 36px rgba(17, 24, 39, 0.18);
            overflow: hidden;
        }

        .modal-header {
            background: var(--brand);
            border-bottom: none;
            color: #fff;
            padding: 0.85rem 1.25rem;
        }

        .modal-header .btn-close {
            filter: invert(1);
            opacity: 0.9;
        }

        .detail-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.85rem 1.5rem;
            margin-bottom: 0.75rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }

        .detail-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }

        .detail-value {
            font-size: 0.92rem;
            font-weight: 600;
            color: var(--text);
        }

        @media (max-width: 1100px) {
            .summary-cards {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .content-wrapper {
                padding: 1rem 0.75rem 2rem;
            }

            .summary-cards {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 0.75rem;
            }

            .summary-cards .card-primary:last-child {
                grid-column: span 2;
            }

            .transactions-table {
                min-width: 0 !important;
            }

            .filter-card {
                flex-direction: column;
                align-items: stretch;
                padding: 10px 12px;
                gap: 10px;
            }

            .filter-form {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .filter-group {
                width: 100%;
                justify-content: space-between;
            }

            .filter-select {
                flex: 1;
                min-width: 0;
            }

            .filter-date-inputs {
                width: 100%;
                display: flex;
                gap: 6px;
            }

            .date-input-wrap {
                flex: 1;
            }

            .filter-date-control {
                max-width: none;
                width: 100%;
            }

            .filter-btn-group {
                width: 100%;
                display: flex;
            }

            .btn-filter-apply {
                flex: 2;
                justify-content: center;
            }

            .btn-filter-reset {
                flex: 1;
                justify-content: center;
            }

            .toolbar-actions-group {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 6px;
            }

            .toolbar-actions-group .dropdown {
                grid-column: span 2;
            }

            .toolbar-actions-group .btn-export,
            .toolbar-actions-group .btn-print-invoice,
            .toolbar-actions-group .btn-reminders {
                width: 100%;
                justify-content: center;
                text-align: center;
                font-size: 0.78rem;
                padding: 7px 8px;
                white-space: nowrap;
            }
        }

        @media (max-width: 480px) {
            .summary-cards {
                grid-template-columns: 1fr !important;
            }

            .summary-cards .card-primary:last-child {
                grid-column: span 1;
            }

            .detail-row {
                grid-template-columns: 1fr;
            }
        }

        @keyframes proofModalZoom {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php
        $active_nav = 'sales_reports';
        include __DIR__ . '/includes/admin_header.php';
        ?>

        
        <div class="content-wrapper">
            
            <div class="page-header">
                <h1>Sales & Reports</h1>
                <p>Garden of Remembrance Memorial Park</p>
            </div>

            
            <div class="controls-section">
                <div class="filter-card">
                    <form id="dateFilterForm" method="GET" action="sales_reports.php" class="filter-form">
                        <!-- Period Preset Dropdown -->
                        <div class="filter-group">
                            <label for="date_preset" class="filter-label">
                                <i class="fas fa-calendar-alt text-danger me-1"></i> Period:
                            </label>
                            <select id="date_preset" name="date_preset" class="form-select form-select-sm filter-select" onchange="handlePresetChange(this.value)">
                                <option value="this_month" <?php echo ($date_preset === 'this_month') ? 'selected' : ''; ?>>This Month</option>
                                <option value="today" <?php echo ($date_preset === 'today') ? 'selected' : ''; ?>>Today</option>
                                <option value="this_week" <?php echo ($date_preset === 'this_week') ? 'selected' : ''; ?>>This Week</option>
                                <option value="last_month" <?php echo ($date_preset === 'last_month') ? 'selected' : ''; ?>>Last Month</option>
                                <option value="this_quarter" <?php echo ($date_preset === 'this_quarter') ? 'selected' : ''; ?>>This Quarter</option>
                                <option value="this_year" <?php echo ($date_preset === 'this_year') ? 'selected' : ''; ?>>This Year</option>
                                <option value="all" <?php echo ($date_preset === 'all') ? 'selected' : ''; ?>>All Time</option>
                                <option value="custom" <?php echo ($date_preset === 'custom') ? 'selected' : ''; ?>>Custom Range</option>
                            </select>
                        </div>

                        <!-- Date Range From/To Inputs -->
                        <div class="filter-date-inputs">
                            <div class="date-input-wrap">
                                <span class="date-prefix">From</span>
                                <input type="date" id="start_date" name="start_date" class="form-control form-control-sm filter-date-control" value="<?php echo htmlspecialchars($start_date); ?>" onchange="onManualDateChange()">
                            </div>
                            <div class="date-input-wrap">
                                <span class="date-prefix">To</span>
                                <input type="date" id="end_date" name="end_date" class="form-control form-control-sm filter-date-control" value="<?php echo htmlspecialchars($end_date); ?>" onchange="onManualDateChange()">
                            </div>
                        </div>

                        <!-- Filter Buttons -->
                        <div class="filter-btn-group">
                            <button type="submit" class="btn-filter-apply" title="Apply Date Filter">
                                <i class="fas fa-filter me-1"></i> Apply
                            </button>
                            <button type="button" class="btn-filter-reset" onclick="resetDateFilter()" title="Reset to Current Month">
                                <i class="fas fa-undo-alt me-1"></i> Reset
                            </button>
                        </div>
                    </form>

                    <!-- Right Controls: Export Dropdown, Print Statement, Reminders -->
                    <div class="toolbar-actions-group">
                        <!-- Export Dropdown -->
                        <div class="dropdown">
                            <button class="btn-export dropdown-toggle" type="button" id="exportReportDropdown" data-bs-toggle="dropdown" aria-expanded="false" title="Export Sales & Report Data">
                                <i class="fas fa-file-export me-1 text-success"></i> Export Report
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="exportReportDropdown" style="font-size: 0.84rem; border-radius: 10px; min-width: 250px; padding: 0.5rem 0;">
                                <li><h6 class="dropdown-header text-uppercase fw-bold text-muted" style="font-size: 0.68rem; letter-spacing: 0.05em;">Sales & Reservation Report</h6></li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2 py-2" href="#" onclick="exportReport('csv'); return false;">
                                        <i class="fas fa-file-csv text-success fs-5"></i>
                                        <div>
                                            <div class="fw-bold">Export to CSV / Excel</div>
                                            <small class="text-muted">Spreadsheet of all filtered reservations</small>
                                        </div>
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2 py-2" href="#" onclick="exportReport('pdf'); return false;">
                                        <i class="fas fa-file-pdf text-danger fs-5"></i>
                                        <div>
                                            <div class="fw-bold">Official Statement (PDF / Print)</div>
                                            <small class="text-muted">Formal audit and accounting statement</small>
                                        </div>
                                    </a>
                                </li>
                            </ul>
                        </div>

                        <button type="button" class="btn-print-invoice" onclick="printMonthlyInvoice()" title="Print Official Monthly Sales Invoice & Statement">
                            <i class="fas fa-file-invoice-dollar me-1"></i> Print Invoice
                        </button>
                        <button type="button" class="btn-reminders" onclick="openPaymentRemindersModal()" title="View and send payment reminders">
                            <i class="fas fa-bell me-1"></i> Reminders
                        </button>
                        <a href="monthly_payments.php" class="btn btn-sm btn-outline-danger fw-bold d-inline-flex align-items-center" style="border-radius: 8px; padding: 6px 14px; font-size: 0.84rem; text-decoration: none;" title="View Monthly Payment Records per Account">
                            <i class="fas fa-user-tag me-1"></i> Per-Account Records
                        </a>
                    </div>
                </div>

                <!-- Active Filter Banner / Quick Summary Pill -->
                <div class="active-filter-banner">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="active-filter-tag">
                            <i class="fas fa-calendar-check text-success me-1"></i> Period: <strong><?php echo htmlspecialchars($sales_period_label); ?></strong>
                        </span>
                        <span class="text-muted small">
                            (<?php echo date('M d, Y', strtotime($start_date)); ?> &mdash; <?php echo date('M d, Y', strtotime($end_date)); ?>)
                        </span>
                        <span class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size: 0.75rem;">
                            <?php echo count($monthly_transactions); ?> transactions in period
                        </span>
                    </div>
                    <?php if ($date_preset !== 'this_month'): ?>
                        <a href="sales_reports.php" class="btn-clear-filter" title="Clear filter and view current month">
                            <i class="fas fa-times-circle me-1"></i> Reset to This Month
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            
            <?php
            $count_all = count($monthly_transactions);
            $count_paid = count(array_filter($monthly_transactions, fn($t) => ($t['filter_type'] ?? '') === 'paid'));
            $count_pending = count(array_filter($monthly_transactions, fn($t) => ($t['filter_type'] ?? '') === 'pending'));
            $count_cancelled = count(array_filter($monthly_transactions, fn($t) => ($t['filter_type'] ?? '') === 'cancelled'));
            $count_active = $count_paid + $count_pending;
            ?>
            <div class="summary-cards">
                <div class="card-primary">
                    <div class="card-label">Overall Revenue</div>
                    <div class="card-value"><?php echo formatDisplayCurrency($total_revenue); ?></div>
                </div>
                <div class="card-primary">
                    <div class="card-label"><?php echo htmlspecialchars($sales_month_label); ?> Sales</div>
                    <div class="card-value"><?php echo formatDisplayCurrency($monthly_sales_total); ?></div>
                </div>
                <div class="card-primary card-filterable active" id="cardTotalBookings" data-card-filter="all" onclick="filterTransactions('all')" title="Click to view all reservations in table below">
                    <div class="card-label d-flex justify-content-between align-items-center">
                        <span>Total Reservations</span>
                        <i class="fas fa-list small" style="font-size: 0.68rem; color: var(--brand);"></i>
                    </div>
                    <div class="card-value"><?php echo formatDisplayNumber($total_booking_count); ?></div>
                    <div style="font-size: 0.72rem; color: var(--muted); margin-top: 4px; font-weight: 600;">
                        <?php echo $active_booking_count; ?> Active &bull; <?php echo $cancelled_booking_count; ?> Cancelled
                    </div>
                </div>
                <div class="card-primary card-filterable" id="cardPaidBookings" data-card-filter="paid" onclick="filterTransactions('paid')" title="Click to view paid reservations only">
                    <div class="card-label d-flex justify-content-between align-items-center">
                        <span>Paid Reservations</span>
                        <i class="fas fa-check-circle text-success small" style="font-size: 0.68rem;"></i>
                    </div>
                    <div class="card-value"><?php echo formatDisplayNumber($paid_booking_count); ?></div>
                </div>
                <div class="card-primary card-filterable" id="cardPendingPayments" data-card-filter="pending" onclick="filterTransactions('pending')" title="Click to view pending payments only">
                    <div class="card-label d-flex justify-content-between align-items-center">
                        <span>Pending Payments</span>
                        <i class="fas fa-clock text-warning small" style="font-size: 0.68rem;"></i>
                    </div>
                    <div class="card-value"><?php echo formatDisplayNumber($pending_payment_count); ?></div>
                </div>
            </div>

            
            <div class="sales-chart-card">
                <div class="chart-header-row">
                    <div>
                        <h3 class="chart-title">
                            <i class="fas fa-chart-bar me-2 text-danger"></i>Sales Performance Overview
                        </h3>
                        <p class="chart-subtitle">Revenue trends, daily activity, and section distribution</p>
                    </div>
                    <div class="chart-actions">
                        <div class="chart-toggle-group" role="group" aria-label="Chart view options">
                            <button type="button" class="chart-toggle-btn active" id="btnChartMonthly" onclick="switchSalesChartView('monthly')">
                                <i class="fas fa-calendar-alt me-1"></i> Monthly Trend
                            </button>
                            <button type="button" class="chart-toggle-btn" id="btnChartDaily" onclick="switchSalesChartView('daily')">
                                <i class="fas fa-calendar-day me-1"></i> Daily Breakdown
                            </button>
                            <button type="button" class="chart-toggle-btn" id="btnChartSection" onclick="switchSalesChartView('section')">
                                <i class="fas fa-th-large me-1"></i> By Section
                            </button>
                        </div>
                        <div class="chart-stat-pill">
                            <span class="stat-pill-label"><?php echo htmlspecialchars($sales_month_label); ?>:</span>
                            <span class="stat-pill-value"><?php echo formatDisplayCurrency($monthly_sales_total); ?></span>
                        </div>
                    </div>
                </div>
                <div class="chart-canvas-wrapper">
                    <canvas id="salesBarChart"></canvas>
                </div>
            </div>

            
            <div class="transactions-section">
                <div class="section-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <h3 class="mb-0" id="transactionSectionTitle"><?php echo htmlspecialchars($sales_month_label); ?> All Reservations</h3>
                        <span class="entry-badge" id="transactionEntryBadge"><?php echo count($monthly_transactions); ?> entries</span>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="transaction-filter-tabs d-flex align-items-center gap-1 flex-wrap">
                            <button type="button" class="txn-tab-btn active" data-filter="all" onclick="filterTransactions('all')" title="Show all reservations">
                                <i class="fas fa-list me-1"></i> Total <span class="badge bg-secondary ms-1"><?php echo $count_all; ?></span>
                            </button>
                            <button type="button" class="txn-tab-btn" data-filter="active" onclick="filterTransactions('active')" title="Show active reservations (paid and pending)">
                                <i class="fas fa-shield-alt me-1 text-primary"></i> Active <span class="badge bg-primary ms-1"><?php echo $count_active; ?></span>
                            </button>
                            <button type="button" class="txn-tab-btn" data-filter="paid" onclick="filterTransactions('paid')" title="Show paid reservations only">
                                <i class="fas fa-check-circle me-1 text-success"></i> Paid <span class="badge bg-success ms-1"><?php echo $count_paid; ?></span>
                            </button>
                            <button type="button" class="txn-tab-btn" data-filter="pending" onclick="filterTransactions('pending')" title="Show pending payment reservations only">
                                <i class="fas fa-clock me-1 text-warning"></i> Pending <span class="badge bg-warning text-dark ms-1"><?php echo $count_pending; ?></span>
                            </button>
                            <?php if ($count_cancelled > 0): ?>
                            <button type="button" class="txn-tab-btn" data-filter="cancelled" onclick="filterTransactions('cancelled')" title="Show cancelled reservations only">
                                <i class="fas fa-times-circle me-1 text-danger"></i> Cancelled <span class="badge bg-danger ms-1"><?php echo $count_cancelled; ?></span>
                            </button>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn-export-sm ms-md-2" onclick="exportReport('csv')" title="Export filtered reservations to CSV">
                            <i class="fas fa-file-csv me-1 text-success"></i> Export CSV
                        </button>
                        <button type="button" class="btn-print-invoice-sm ms-2" onclick="printMonthlyInvoice()" title="Print Monthly Sales Invoice Statement">
                            <i class="fas fa-print me-1"></i> Print Invoice
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="transactions-table mobile-card-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Section / Plot</th>
                                <th>Reservation Status</th>
                                <th>Total Price</th>
                                <th>Amount Paid</th>
                                <th>Balance</th>
                                <th>Payment Status</th>
                                <th>Date</th>
                                <th class="text-center">Proof</th>
                                <th class="text-center" style="width: 110px;">Invoice</th>
                            </tr>
                        </thead>
                        <tbody id="transactionsTableBody">
                            <?php if (!empty($monthly_transactions)): ?>
                                <?php foreach ($monthly_transactions as $transaction): ?>
                                    <?php
                                        $booking_id = (int)($transaction['booking_id'] ?? 0);
                                        $customer_name = $transaction['customer_name'] ?? 'Unknown';
                                        $section = $transaction['section'] ?? '—';
                                        $plot = format_plot_number($transaction['plot'] ?? '—', $section);
                                        $raw_tot = (float)($transaction['total_amount'] ?? 0);
                                        $raw_plot = (float)($transaction['plot_price'] ?? 0);
                                        $amount_paid = (float)($transaction['amount_paid'] ?? 0);
                                        $total_price = max($raw_tot, $raw_plot, $amount_paid);
                                        if ($total_price <= 0) $total_price = 85000.00;
                                        $balance = max(0, $total_price - $amount_paid);
                                        $payment_date = $transaction['payment_date'] ?? date('Y-m-d H:i:s');
                                        $proof_filename = $transaction['proof_filename'] ?? null;
                                        $filter_type = $transaction['filter_type'] ?? 'pending';
                                        $b_status_raw = strtolower(trim((string)($transaction['status'] ?? 'pending')));
                                        
                                        $status = strtoupper($transaction['payment_status'] ?? 'PENDING');
                                        $status_class = 'status-' . strtolower(str_replace(' ', '_', $status));

                                        $b_badge_class = 'bg-secondary';
                                        if ($b_status_raw === 'approved') $b_badge_class = 'bg-success';
                                        elseif ($b_status_raw === 'completed') $b_badge_class = 'bg-info text-dark';
                                        elseif ($b_status_raw === 'pending') $b_badge_class = 'bg-warning text-dark';
                                        elseif (in_array($b_status_raw, ['cancelled', 'rejected'])) $b_badge_class = 'bg-danger';
                                    ?>
                                    <tr class="transaction-row" data-filter-type="<?php echo htmlspecialchars($filter_type); ?>" data-booking-id="<?php echo $booking_id; ?>" onclick="openTransactionDetails(<?php echo $booking_id; ?>)">
                                        <td data-label="Customer"><?php echo htmlspecialchars($customer_name); ?></td>
                                        <td data-label="Section / Plot"><?php echo htmlspecialchars($section); ?><?php echo ($plot !== '—') ? ' / ' . htmlspecialchars($plot) : ''; ?></td>
                                        <td data-label="Reservation Status">
                                            <span class="badge <?php echo $b_badge_class; ?>" style="font-size: 0.74rem; text-transform: uppercase; font-weight: 600;">
                                                <?php echo htmlspecialchars($transaction['status'] ?? 'pending'); ?>
                                            </span>
                                        </td>
                                        <td data-label="Total Price"><?php echo formatDisplayCurrency($total_price); ?></td>
                                        <td data-label="Amount Paid"><?php echo formatDisplayCurrency($amount_paid); ?></td>
                                        <td data-label="Balance"><?php echo formatDisplayCurrency($balance); ?></td>
                                        <td data-label="Payment Status">
                                            <?php if ($status === 'PAID'): ?>
                                                <span class="status-badge status-paid"><i class="fas fa-check-circle me-1"></i>PAID</span>
                                            <?php elseif ($status === 'PARTIAL'): ?>
                                                <span class="status-badge status-partial"><i class="fas fa-adjust me-1"></i>PARTIAL</span>
                                            <?php elseif ($status === 'CANCELLED'): ?>
                                                <span class="status-badge status-cancelled" style="background: rgba(220, 38, 38, 0.12); color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.3);"><i class="fas fa-ban me-1"></i>CANCELLED</span>
                                            <?php else: ?>
                                                <span class="status-badge status-unpaid" style="background: rgba(217, 119, 6, 0.12); color: #b45309; border: 1px solid rgba(217, 119, 6, 0.3);"><i class="fas fa-clock me-1"></i>PENDING</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Date"><?php echo htmlspecialchars(date('M d, Y', strtotime($payment_date))); ?></td>
                                        <td data-label="Proof" class="text-center" onclick="event.stopPropagation()">
                                            <?php if (!empty($proof_filename)): ?>
                                                <button type="button" class="btn btn-sm" onclick="adminViewPaymentProof(<?php echo $booking_id; ?>, '<?php echo htmlspecialchars(addslashes($proof_filename), ENT_QUOTES); ?>')" style="background: rgba(123, 17, 19, 0.1); color: #7b1113; border: 1px solid rgba(123, 17, 19, 0.25); border-radius: 6px; font-size: 0.76rem; font-weight: 600; padding: 4px 10px; white-space: nowrap;">
                                                    <i class="fas fa-image me-1"></i> View Proof
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Invoice" class="text-center" onclick="event.stopPropagation()">
                                            <button type="button" class="btn-invoice-row" onclick="printTransactionInvoice(<?php echo $booking_id; ?>)" title="Print Official Invoice for Reservation #<?php echo $booking_id; ?>">
                                                <i class="fas fa-file-invoice me-1"></i> Invoice
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr id="filterEmptyRow" style="display: none;">
                                    <td colspan="10">
                                        <div class="empty-state py-4 text-center">
                                            <div class="empty-state-icon fs-3">🔍</div>
                                            <div class="text-muted mt-2 fw-semibold">No reservations found for the selected filter.</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="empty-state">
                                            <div class="empty-state-icon">📊</div>
                                            <div>No reservations recorded for <?php echo htmlspecialchars($sales_month_label); ?> yet.</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    
    <div class="modal fade" id="transactionDetailsModal" tabindex="-1" aria-labelledby="transactionDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border-radius: 1.25rem; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.15); overflow: hidden;">
                <div class="modal-header" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%); color: #fff; border-bottom: none; padding: 1.2rem 1.5rem;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-receipt text-warning"></i>
                        <h5 class="modal-title fw-bold text-white m-0" id="transactionDetailsModalLabel" style="font-family: 'Inter', sans-serif;">
                            Transaction & Payment Details
                        </h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="modalContent" style="padding: 1.5rem; background: #fafafa; font-family: 'Inter', sans-serif;">
                    <div class="text-center py-5">
                        <div class="spinner-border text-danger" role="status">
                            <span class="visually-hidden">Loading details...</span>
                        </div>
                        <div class="text-muted mt-2 small">Loading transaction details...</div>
                    </div>
                </div>
                <div class="modal-footer" style="background: #ffffff; border-top: 1px solid #eee; padding: 1rem 1.5rem; display: flex; justify-content: space-between;">
                    <div class="d-flex gap-2">
                        <button type="button" id="txPrintInvoiceBtn" class="btn btn-primary" style="background: #7b1113; border: none; border-radius: 8px; font-size: 0.88rem; font-weight: 600; display: none;">
                            <i class="fas fa-file-invoice-dollar me-1"></i> Print Invoice
                        </button>
                        <button type="button" id="txPrintReceiptBtn" class="btn btn-outline-secondary" style="border-radius: 8px; font-size: 0.88rem; display: none;">
                            <i class="fas fa-receipt me-1"></i> Print Receipt
                        </button>
                    </div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px; font-size: 0.88rem;">Close</button>
                </div>
            </div>
        </div>
    </div>

    
    <div id="adminPaymentProofModal" style="display: none; position: fixed; inset: 0; z-index: 99999; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); align-items: center; justify-content: center; padding: 1rem;">
        <div style="background: #ffffff; border-radius: 1.25rem; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); max-width: 840px; width: 100%; max-height: 92vh; display: flex; flex-direction: column; overflow: hidden; animation: proofModalZoom 0.2s ease-out;">
            
            <div style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%); color: #fff; padding: 1.1rem 1.5rem; display: flex; align-items: center; justify-content: space-between; border-bottom: none;">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-receipt text-warning" style="font-size: 1.25rem;"></i>
                    <div>
                        <h5 class="m-0 fw-bold text-white" id="adminProofTitle" style="font-size: 1.05rem; font-family: 'Inter', sans-serif;">Payment Proof Screenshot</h5>
                        <div class="text-white-50 font-monospace small" id="adminProofFilenameText" style="font-size: 0.75rem;"></div>
                    </div>
                </div>
                <button type="button" onclick="closeAdminPaymentProof()" class="btn-close btn-close-white" style="cursor: pointer; opacity: 0.9;" aria-label="Close"></button>
            </div>

            
            <div style="padding: 1.5rem; background: #f8fafc; overflow-y: auto; text-align: center; flex: 1; min-height: 260px; display: flex; align-items: center; justify-content: center; position: relative;">
                <div id="adminProofSpinner" style="display: none;" class="py-4">
                    <div class="spinner-border text-danger" role="status"></div>
                    <div class="text-muted mt-2 small">Loading screenshot proof...</div>
                </div>
                <img id="adminProofImage" src="" alt="Payment Proof" style="max-width: 100%; max-height: 65vh; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); object-fit: contain; display: none; margin: 0 auto;">
            </div>

            
            <div style="background: #ffffff; border-top: 1px solid #e2e8f0; padding: 0.85rem 1.5rem; display: flex; align-items: center; justify-content: space-between;">
                <a id="adminDownloadProofLink" href="#" download class="btn btn-sm" style="background: #7b1113; color: #fff; border-radius: 8px; padding: 8px 18px; font-weight: 600; text-decoration: none;">
                    <i class="fas fa-download me-1"></i> Download Proof
                </a>
                <button type="button" onclick="closeAdminPaymentProof()" class="btn btn-sm btn-secondary" style="border-radius: 8px; padding: 8px 22px; font-weight: 600;">
                    <i class="fas fa-times me-1"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Installment Payment Records Modal -->
    <div class="modal fade" id="installmentPaymentsModal" tabindex="-1" aria-labelledby="installmentPaymentsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border-radius: 14px; overflow: hidden; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
                <div class="modal-header" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%); color: #fff; padding: 1rem 1.5rem; border-bottom: none;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-calendar-alt fs-5"></i>
                        <div>
                            <h5 class="modal-title mb-0 fw-bold" id="installmentPaymentsModalLabel" style="font-size: 1.1rem;">Installment Payment Records</h5>
                            <div class="text-white-50 small" id="installmentPaymentsMonthLabel" style="font-size: 0.78rem;"></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="padding: 0; background: #f8fafc;">
                    <!-- Summary bar -->
                    <div id="installmentPaymentsSummary" style="display: none; padding: 0.85rem 1.5rem; background: #fff; border-bottom: 1px solid #e9ecef;">
                        <div class="d-flex flex-wrap gap-3 align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary" id="instSummTotal" style="font-size: 0.8rem; padding: 6px 12px;">0 accounts</span>
                            </div>
                            <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
                                <div class="input-group input-group-sm" style="max-width: 220px;">
                                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="fas fa-search"></i></span>
                                    <input type="text" id="instSearchInput" class="form-control border-start-0" placeholder="Search customer, plot..." onkeyup="filterInstallmentTable()">
                                </div>
                                <label for="instStatusFilter" class="text-muted small fw-semibold mb-0">Filter:</label>
                                <select id="instStatusFilter" class="form-select form-select-sm" style="width: auto; font-size: 0.82rem; border-radius: 8px;" onchange="filterInstallmentTable()">
                                    <option value="all">All Accounts</option>
                                    <option value="ACTIVE">Active (In Good Standing)</option>
                                    <option value="OVERDUE">With Overdue Dues</option>
                                    <option value="PENDING">Pending Review / Verification</option>
                                    <option value="PAID">Fully Paid</option>
                                </select>
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="exportInstallmentsCsv()" title="Export Installment Ledger to CSV">
                                    <i class="fas fa-file-csv me-1"></i> Export CSV
                                </button>
                            </div>
                        </div>
                    </div>
                    <!-- Loading state -->
                    <div id="installmentPaymentsLoading" class="text-center py-5">
                        <div class="spinner-border text-danger" role="status"><span class="visually-hidden">Loading...</span></div>
                        <div class="text-muted mt-2 small">Loading installment records...</div>
                    </div>
                    <!-- Table -->
                    <div id="installmentPaymentsTableWrap" style="display: none; overflow-x: auto;">
                        <table class="table table-hover mb-0" style="font-size: 0.84rem; font-family: 'Inter', sans-serif;">
                            <thead style="background: #f1f5f9; position: sticky; top: 0; z-index: 2;">
                                <tr style="border-bottom: 2px solid #dee2e6;">
                                    <th style="padding: 0.75rem 0.85rem; white-space: nowrap; font-weight: 700; color: #374151;">Contract #</th>
                                    <th style="padding: 0.75rem 0.85rem; white-space: nowrap; font-weight: 700; color: #374151;">Customer Name</th>
                                    <th style="padding: 0.75rem 0.85rem; white-space: nowrap; font-weight: 700; color: #374151;">Plot Location</th>
                                    <th style="padding: 0.75rem 0.85rem; white-space: nowrap; font-weight: 700; color: #374151; text-align: center;">Payment Plan</th>
                                    <th style="padding: 0.75rem 0.85rem; white-space: nowrap; font-weight: 700; color: #374151;">Total Contract</th>
                                    <th style="padding: 0.75rem 0.85rem; white-space: nowrap; font-weight: 700; color: #374151;">Total Paid</th>
                                    <th style="padding: 0.75rem 0.85rem; white-space: nowrap; font-weight: 700; color: #374151;">Remaining Balance</th>
                                    <th style="padding: 0.75rem 0.85rem; white-space: nowrap; font-weight: 700; color: #374151; text-align: center;">Progress / Dues</th>
                                    <th style="padding: 0.75rem 0.85rem; white-space: nowrap; font-weight: 700; color: #374151; text-align: center;">Status</th>
                                    <th style="padding: 0.75rem 0.85rem; white-space: nowrap; font-weight: 700; color: #374151; text-align: center;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="installmentPaymentsTbody">
                            </tbody>
                        </table>
                        <div id="installmentPaymentsEmpty" style="display: none; padding: 2.5rem; text-align: center;">
                            <div style="font-size: 2rem; margin-bottom: 0.5rem;">📋</div>
                            <div class="fw-semibold text-muted">No installment payment records found for this month.</div>
                            <div class="small text-muted mt-1">Installment records will appear here once bookings with payment plans have submissions or dues.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background: #fff; border-top: 1px solid #e9ecef; padding: 0.85rem 1.5rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px; font-size: 0.88rem; font-weight: 600;"><i class="fas fa-times me-1"></i> Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Installment Payment Review / Details Modal (Approve, Reject, Close) -->
    <div class="modal fade" id="installmentReviewModal" tabindex="-1" aria-labelledby="installmentReviewModalLabel" aria-hidden="true" style="z-index: 1065;">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border-radius: 14px; overflow: hidden; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.25);">
                <div class="modal-header" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%); color: #fff; padding: 1rem 1.5rem; border-bottom: none;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-file-invoice-dollar fs-5"></i>
                        <div>
                            <h5 class="modal-title mb-0 fw-bold" id="installmentReviewModalLabel" style="font-size: 1.1rem;">Installment Payment Details</h5>
                            <div class="text-white-50 small" id="installmentReviewSubLabel" style="font-size: 0.78rem;">Review customer installment submission</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" id="installmentReviewBody" style="background: #f8fafc;">
                    <!-- Dynamically populated review content -->
                </div>
                <div class="modal-footer" id="installmentReviewFooter" style="background: #fff; border-top: 1px solid #e9ecef; padding: 0.85rem 1.5rem; justify-content: space-between;">
                    <!-- Action buttons (Approve, Reject, Close) populated by JS -->
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Reminders Modal -->
    <div class="modal fade" id="paymentRemindersModal" tabindex="-1" aria-labelledby="paymentRemindersModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border-radius: 14px; overflow: hidden; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
                <div class="modal-header" style="background: #7b1113; color: #fff; padding: 1rem 1.5rem;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-bell fs-5"></i>
                        <div>
                            <h5 class="modal-title mb-0 fw-bold" id="paymentRemindersModalLabel" style="font-size: 1.1rem;">Payment Reminders & Dispatch History</h5>
                            <small style="opacity: 0.85; font-size: 0.78rem;">Automated notifications for upcoming and overdue installments</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" style="background: #f8fafc;">
                    <!-- Action Bar -->
                    <div class="p-3 mb-3 rounded shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-2" style="background: #fff; border: 1px solid #e2e8f0;">
                        <div>
                            <div class="fw-bold text-dark"><i class="fas fa-robot text-danger me-1"></i> Reminder Engine: <span class="badge bg-success">CONNECTED</span></div>
                            <div class="text-muted small">Evaluates schedules for due in 7 days, due tomorrow, due today, or overdue.</div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="loadPaymentReminderHistory()">
                                <i class="fas fa-sync-alt me-1"></i> Refresh History
                            </button>
                            <button type="button" class="btn btn-sm text-white" id="btnDispatchReminders" onclick="dispatchPaymentRemindersNow()" style="background: #7b1113; border: none; padding: 6px 14px; font-weight: 600;">
                                <i class="fas fa-paper-plane me-1"></i> Check & Send Due Reminders Now
                            </button>
                        </div>
                    </div>

                    <!-- Dispatch Status Alert Box -->
                    <div id="reminderDispatchAlert" style="display: none;" class="mb-3"></div>

                    <!-- Table of Reminder History -->
                    <div class="card border-0 shadow-sm" style="border-radius: 10px; overflow: hidden;">
                        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                            <span class="fw-bold small text-uppercase text-muted"><i class="fas fa-history me-1"></i> Dispatched Reminders Log</span>
                            <span id="reminderHistoryCountBadge" class="badge bg-secondary">0 entries</span>
                        </div>
                        <div class="table-responsive" style="max-height: 420px;">
                            <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Reservation / Customer</th>
                                        <th>Contact Email</th>
                                        <th>Section / Plot</th>
                                        <th>Due Date / Monthly</th>
                                        <th>Reminder Type</th>
                                        <th>Dispatched At</th>
                                    </tr>
                                </thead>
                                <tbody id="reminderHistoryTableBody">
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">
                                            <div class="spinner-border spinner-border-sm text-danger me-1"></div> Loading reminder history...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top py-2 px-4">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function handlePresetChange(preset) {
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const dd = String(today.getDate()).padStart(2, '0');
            const todayStr = `${yyyy}-${mm}-${dd}`;

            let start = '';
            let end = '';

            const formatDate = (d) => {
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return `${y}-${m}-${day}`;
            };

            if (preset === 'today') {
                start = todayStr;
                end = todayStr;
            } else if (preset === 'this_week') {
                const day = today.getDay(); // 0 is Sun, 1 is Mon
                const diffToMon = (day === 0 ? -6 : 1) - day;
                const mon = new Date(today);
                mon.setDate(today.getDate() + diffToMon);
                const sun = new Date(mon);
                sun.setDate(mon.getDate() + 6);
                start = formatDate(mon);
                end = formatDate(sun);
            } else if (preset === 'this_month') {
                const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
                const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                start = formatDate(firstDay);
                end = formatDate(lastDay);
            } else if (preset === 'last_month') {
                const firstDay = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                const lastDay = new Date(today.getFullYear(), today.getMonth(), 0);
                start = formatDate(firstDay);
                end = formatDate(lastDay);
            } else if (preset === 'this_quarter') {
                const q = Math.floor(today.getMonth() / 3);
                const firstDay = new Date(today.getFullYear(), q * 3, 1);
                const lastDay = new Date(today.getFullYear(), (q + 1) * 3, 0);
                start = formatDate(firstDay);
                end = formatDate(lastDay);
            } else if (preset === 'this_year') {
                start = `${yyyy}-01-01`;
                end = `${yyyy}-12-31`;
            } else if (preset === 'all') {
                start = '2020-01-01';
                end = todayStr;
            } else if (preset === 'custom') {
                const sEl = document.getElementById('start_date');
                if (sEl) sEl.focus();
                return;
            }

            if (start && end) {
                const sEl = document.getElementById('start_date');
                const eEl = document.getElementById('end_date');
                if (sEl) sEl.value = start;
                if (eEl) eEl.value = end;
                const f = document.getElementById('dateFilterForm');
                if (f) f.submit();
            }
        }

        function onManualDateChange() {
            const presetSelect = document.getElementById('date_preset');
            if (presetSelect) {
                presetSelect.value = 'custom';
            }
        }

        function resetDateFilter() {
            window.location.href = 'sales_reports.php';
        }

        function getActiveTransactionFilter() {
            const activeTab = document.querySelector('.txn-tab-btn.active');
            return activeTab ? (activeTab.getAttribute('data-filter') || 'all') : 'all';
        }

        function exportReport(format) {
            const startDate = document.getElementById('start_date') ? document.getElementById('start_date').value : '<?php echo $start_date; ?>';
            const endDate = document.getElementById('end_date') ? document.getElementById('end_date').value : '<?php echo $end_date; ?>';
            const filter = getActiveTransactionFilter();

            if (format === 'csv') {
                let url = 'sales_reports.php?action=export_sales_csv&start_date=' + encodeURIComponent(startDate) + '&end_date=' + encodeURIComponent(endDate) + '&filter=' + encodeURIComponent(filter);
                window.location.href = url;
            } else if (format === 'pdf') {
                let url = 'print_invoice.php?start_date=' + encodeURIComponent(startDate) + '&end_date=' + encodeURIComponent(endDate) + '&filter=' + encodeURIComponent(filter) + '&autoprint=1';
                window.open(url, '_blank');
            }
        }

        function exportInstallmentsCsv() {
            const startDate = document.getElementById('start_date') ? document.getElementById('start_date').value : '<?php echo $start_date; ?>';
            const endDate = document.getElementById('end_date') ? document.getElementById('end_date').value : '<?php echo $end_date; ?>';
            let url = 'sales_reports.php?action=export_installment_csv&start_date=' + encodeURIComponent(startDate) + '&end_date=' + encodeURIComponent(endDate);
            window.location.href = url;
        }

        function printMonthlyInvoice() {
            const startDate = document.getElementById('start_date') ? document.getElementById('start_date').value : '<?php echo $start_date; ?>';
            const endDate = document.getElementById('end_date') ? document.getElementById('end_date').value : '<?php echo $end_date; ?>';
            const filter = getActiveTransactionFilter();
            window.open('print_invoice.php?start_date=' + encodeURIComponent(startDate) + '&end_date=' + encodeURIComponent(endDate) + '&filter=' + encodeURIComponent(filter) + '&autoprint=1', '_blank');
        }

        function updateReports() {
            const f = document.getElementById('dateFilterForm');
            if (f) f.submit();
        }

        function printTransactionInvoice(bookingId) {
            if (!bookingId) return;
            window.open('print_invoice.php?booking_id=' + encodeURIComponent(bookingId) + '&autoprint=1', '_blank');
        }

        function openTransactionDetails(bookingId) {
            const modalEl = document.getElementById('transactionDetailsModal');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            const modalContent = document.getElementById('modalContent');
            const printBtn = document.getElementById('txPrintReceiptBtn');
            const printInvoiceBtn = document.getElementById('txPrintInvoiceBtn');

            modalContent.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-danger" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="text-muted mt-2 small">Loading transaction details...</div>
                </div>
            `;
            if (printBtn) printBtn.style.display = 'none';
            if (printInvoiceBtn) printInvoiceBtn.style.display = 'none';

            fetch('sales_reports.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_transaction_details&booking_id=' + encodeURIComponent(bookingId)
            })
            .then(async response => {
                const text = await response.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    const jsonStart = text.indexOf('{');
                    const jsonEnd = text.lastIndexOf('}');
                    if (jsonStart !== -1 && jsonEnd !== -1 && jsonEnd > jsonStart) {
                        try {
                            return JSON.parse(text.substring(jsonStart, jsonEnd + 1));
                        } catch (e2) {}
                    }
                    console.error('Failed to parse server response:', text);
                    throw new Error('Server returned invalid response');
                }
            })
            .then(data => {
                if (data.success) {
                    const isPaid = data.payment_status === 'PAID';
                    const statusClass = isPaid ? 'background: rgba(16, 185, 129, 0.15); color: #065f46;' : 'background: rgba(245, 158, 11, 0.15); color: #92400e;';

                    let proofHtml = '';
                    if (data.proof_filename) {
                        const safeFn = (data.proof_filename || '').replace(/'/g, "\\'");
                        const proofPreviewUrl = 'view_payment_proof.php?booking_id=' + data.booking_id + '&file=' + encodeURIComponent(data.proof_filename) + '&raw=1';
                        proofHtml = `
                            <div class="mt-3 p-3 bg-white rounded border">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas fa-receipt text-danger" style="font-size: 1.25rem;"></i>
                                        <div>
                                            <div class="fw-bold text-dark" style="font-size: 0.88rem;">Payment Screenshot Proof</div>
                                            <div class="text-muted small font-monospace">${escapeHtml(data.proof_filename)}</div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="adminViewPaymentProof(${data.booking_id}, '${safeFn}')" style="border-radius: 8px; background: #7b1113; border: none; padding: 6px 14px; font-weight: 600;">
                                        <i class="fas fa-expand me-1"></i> Pop Out Proof
                                    </button>
                                </div>
                                <div class="text-center p-2 rounded bg-light border" style="cursor: pointer;" onclick="adminViewPaymentProof(${data.booking_id}, '${safeFn}')" title="Click to pop out proof modal">
                                    <img src="${proofPreviewUrl}" alt="Proof Thumbnail" style="max-height: 180px; max-width: 100%; border-radius: 8px; object-fit: contain; box-shadow: 0 2px 8px rgba(0,0,0,0.08);" onerror="this.parentElement.style.display='none';">
                                    <div class="text-muted mt-1" style="font-size: 0.75rem;"><i class="fas fa-search-plus me-1"></i> Click screenshot to pop out full view</div>
                                </div>
                            </div>
                        `;
                    }

                    let html = `
                        
                        <div class="d-flex justify-content-between align-items-center p-3 mb-3 rounded" style="background: #f7f1ea; border: 1px solid #e5dbcf;">
                            <div>
                                <span class="text-muted small text-uppercase fw-bold">Reservation Record</span>
                                <div class="fs-5 fw-bold text-dark">${escapeHtml(data.customer_name || 'Reservation Details')}</div>
                            </div>
                            <div class="text-end">
                                <span class="badge px-3 py-2" style="${statusClass} font-size: 0.82rem; font-weight: 600; border-radius: 999px;">
                                    Payment: ${escapeHtml(data.payment_status)}
                                </span>
                            </div>
                        </div>

                        <div class="row g-3">
                            
                            <div class="col-md-6">
                                <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: #fff;">
                                    <div class="card-body p-3">
                                        <h6 class="fw-bold mb-3 pb-2 border-bottom" style="color: #7b1113; font-size: 0.9rem;">
                                            <i class="fas fa-user me-1"></i> Customer Information
                                        </h6>
                                        <div class="mb-2">
                                            <div class="text-muted small">Customer Name</div>
                                            <div class="fw-bold text-dark">${escapeHtml(data.customer_name)}</div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="text-muted small">Email Address</div>
                                            <div class="text-dark">${escapeHtml(data.customer_email)}</div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="text-muted small">Contact Number</div>
                                            <div class="text-dark font-monospace">${escapeHtml(data.contact_number)}</div>
                                        </div>
                                        <div>
                                            <div class="text-muted small">Address / Reservation Location</div>
                                            <div class="text-dark small">${escapeHtml(data.booker_location)}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            
                            <div class="col-md-6">
                                <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: #fff;">
                                    <div class="card-body p-3">
                                        <h6 class="fw-bold mb-3 pb-2 border-bottom" style="color: #7b1113; font-size: 0.9rem;">
                                            <i class="fas fa-map-marker-alt me-1"></i> Plot & Service Details
                                        </h6>
                                        <div class="mb-2">
                                            <div class="text-muted small">Service Type</div>
                                            <div class="fw-bold text-dark">${escapeHtml(data.service_type)}</div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="text-muted small">Grave Section & Plot</div>
                                            <div class="fw-bold text-dark">Section ${escapeHtml(data.section)} • Plot ${escapeHtml(data.plot)}</div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="text-muted small">Reservation Date</div>
                                            <div class="text-dark">${escapeHtml(data.booking_date)}</div>
                                        </div>
                                        <div>
                                            <div class="text-muted small">Preferred Date</div>
                                            <div class="text-dark">${escapeHtml(data.preferred_date)}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            
                            <div class="col-12">
                                <div class="card border-0 shadow-sm" style="border-radius: 12px; background: #fff;">
                                    <div class="card-body p-3">
                                        <h6 class="fw-bold mb-3 pb-2 border-bottom d-flex justify-content-between align-items-center" style="color: #7b1113; font-size: 0.9rem;">
                                            <span><i class="fas fa-credit-card me-1"></i> Payment & GCash Information</span>
                                            <span class="badge" style="${statusClass}">${escapeHtml(data.payment_status)}</span>
                                        </h6>

                                        <div class="row g-2 mb-3">
                                            <div class="col-sm-4">
                                                <div class="p-2 rounded bg-light border">
                                                    <div class="text-muted small">Total Price</div>
                                                    <div class="fs-6 fw-bold text-dark">₱${data.total_price}</div>
                                                </div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="p-2 rounded bg-light border">
                                                    <div class="text-muted small">Amount Paid</div>
                                                    <div class="fs-6 fw-bold text-success">₱${data.amount_paid}</div>
                                                </div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="p-2 rounded bg-light border">
                                                    <div class="text-muted small">Balance</div>
                                                    <div class="fs-6 fw-bold ${data.payment_status === 'PAID' ? 'text-success' : 'text-dark'}">₱${data.balance}${data.payment_status === 'PAID' ? ' <span class="badge bg-success-subtle text-success border border-success ms-1" style="font-size:0.65rem;">Fully Settled</span>' : ''}</div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row g-2">
                                            <div class="col-sm-6">
                                                <div class="text-muted small">Payment Method</div>
                                                <div class="fw-bold text-dark"><i class="fas fa-mobile-alt text-primary me-1"></i> ${escapeHtml(data.payment_type)}</div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-muted small">GCash Account Name</div>
                                                <div class="fw-bold text-dark">${escapeHtml(data.gcash_account_name || 'Garden of Remembrance Memorial Park')}</div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-muted small">Configured GCash Number</div>
                                                <div class="fw-bold text-primary font-monospace">${escapeHtml(data.gcash_number || '0917 823 4567')}</div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-muted small">Reference / Transaction Number</div>
                                                <div class="fw-bold text-dark font-monospace bg-light px-2 py-1 rounded d-inline-block">${escapeHtml(data.reference_number)}</div>
                                            </div>
                                            <div class="col-sm-12">
                                                <div class="text-muted small">Payment Date & Time</div>
                                                <div class="text-dark">${escapeHtml(data.payment_date)}</div>
                                            </div>
                                        </div>

                                        ${proofHtml}
                                    </div>
                                </div>
                            </div>

                            
                            <div class="col-12">
                                <div class="card border-0 shadow-sm" style="border-radius: 12px; background: #fff;">
                                    <div class="card-body p-3">
                                        <h6 class="fw-bold mb-3 pb-2 border-bottom d-flex justify-content-between align-items-center" style="color: #7b1113; font-size: 0.9rem;">
                                            <span><i class="fas fa-list-alt me-1"></i> Individual Payment Transactions (Ledger)</span>
                                            <span class="badge bg-secondary font-monospace">${(data.payments_list || []).length} Recorded</span>
                                        </h6>

                                        ${(data.payments_list && data.payments_list.length > 0) ? `
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered align-middle mb-0" style="font-size: 0.8rem;">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Payment ID</th>
                                                            <th>Inst. #</th>
                                                            <th>Amount</th>
                                                            <th>Method</th>
                                                            <th>GCash / Txn Ref</th>
                                                            <th>Official Receipt #</th>
                                                            <th>Date</th>
                                                            <th class="text-center">Status</th>
                                                            <th class="text-center">Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        ${data.payments_list.map(p => {
                                                            const isPaid = p.payment_status === 'PAID';
                                                            const isPending = p.payment_status === 'PENDING';
                                                            const isRejected = p.payment_status === 'REJECTED';
                                                            const statusBadge = isPaid 
                                                                ? '<span class="badge bg-success">PAID</span>' 
                                                                : (isRejected ? '<span class="badge bg-danger">REJECTED</span>' : '<span class="badge bg-warning text-dark">PENDING REVIEW</span>');
                                                            
                                                            let actionBtns = '';
                                                            if (isPending) {
                                                                actionBtns = `
                                                                    <div class="btn-group btn-group-sm">
                                                                        <button type="button" class="btn btn-outline-success py-0 px-2" onclick="adminVerifyPayment(${p.id}, ${data.booking_id})">
                                                                            <i class="fas fa-check"></i> Approve
                                                                        </button>
                                                                        <button type="button" class="btn btn-outline-danger py-0 px-2" onclick="adminRejectPayment(${p.id}, ${data.booking_id})">
                                                                            <i class="fas fa-times"></i> Reject
                                                                        </button>
                                                                    </div>
                                                                `;
                                                            } else {
                                                                actionBtns = `
                                                                    <a href="print_invoice.php?booking_id=${data.booking_id}&payment_id=${p.id}" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Print Individual Invoice">
                                                                        <i class="fas fa-print"></i>
                                                                    </a>
                                                                `;
                                                            }

                                                            return `
                                                                <tr>
                                                                    <td class="font-monospace fw-bold text-secondary">#${p.id}</td>
                                                                    <td class="text-center">${p.installment_number || '—'}</td>
                                                                    <td class="fw-bold text-success">₱${p.amount_paid}</td>
                                                                    <td>${escapeHtml(p.payment_method)}</td>
                                                                    <td class="font-monospace"><small>${escapeHtml(p.gcash_reference_number)}</small></td>
                                                                    <td class="font-monospace text-primary"><small>${escapeHtml(p.receipt_number)}</small></td>
                                                                    <td><small>${escapeHtml(p.payment_date)}</small></td>
                                                                    <td class="text-center">${statusBadge}</td>
                                                                    <td class="text-center">${actionBtns}</td>
                                                                </tr>
                                                            `;
                                                        }).join('')}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ` : `
                                            <div class="text-muted small p-2 bg-light rounded border text-center">
                                                No individual payment transaction rows recorded yet for this booking.
                                            </div>
                                        `}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;

                    modalContent.innerHTML = html;

                    if (printInvoiceBtn) {
                        printInvoiceBtn.style.display = 'inline-flex';
                        printInvoiceBtn.onclick = function() {
                            printTransactionInvoice(data.booking_id);
                        };
                    }

                    if (printBtn) {
                        printBtn.style.display = 'inline-flex';
                        printBtn.onclick = function() {
                            window.location.href = 'payment-success.php?booking_id=' + data.booking_id;
                        };
                    }
                } else {
                    const msg = (data && data.message) ? escapeHtml(data.message) : 'Error loading transaction details.';
                    modalContent.innerHTML = `<div class="alert alert-danger d-flex align-items-center gap-2"><i class="fas fa-exclamation-triangle"></i><span>${msg}</span></div>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                const msg = (error && error.message) ? escapeHtml(error.message) : 'An error occurred while loading details.';
                modalContent.innerHTML = `<div class="alert alert-danger d-flex align-items-center gap-2"><i class="fas fa-exclamation-triangle"></i><span>${msg}</span></div>`;
            });

            modal.show();
        }

        function adminVerifyPayment(paymentId, bookingId) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Approve Payment #' + paymentId + '?',
                    text: 'Are you sure you want to verify and approve Payment #' + paymentId + '?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#198754',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '<i class="fas fa-check me-1"></i> Yes, Approve Payment',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Verifying Payment...',
                            text: 'Please wait while we update the transaction ledger.',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            didOpen: () => { Swal.showLoading(); }
                        });
                        fetch('sales_reports.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=verify_payment&payment_id=' + paymentId + '&booking_id=' + bookingId
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Payment Verified!',
                                    text: 'Payment #' + paymentId + ' has been successfully verified and marked as PAID.',
                                    confirmButtonColor: '#7b1113'
                                }).then(() => {
                                    openTransactionDetails(bookingId);
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Verification Failed',
                                    text: res.message || 'Failed to verify payment',
                                    confirmButtonColor: '#7b1113'
                                });
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            Swal.fire({
                                icon: 'error',
                                title: 'Network Error',
                                text: 'Network error while verifying payment.',
                                confirmButtonColor: '#7b1113'
                            });
                        });
                    }
                });
            } else {
                if (!confirm('Are you sure you want to verify and approve Payment #' + paymentId + '?')) {
                    return;
                }
                fetch('sales_reports.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=verify_payment&payment_id=' + paymentId + '&booking_id=' + bookingId
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert('Payment #' + paymentId + ' has been verified!');
                        openTransactionDetails(bookingId);
                    } else {
                        alert('Error: ' + (res.message || 'Failed to verify payment'));
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Network error while verifying payment.');
                });
            }
        }

        function adminRejectPayment(paymentId, bookingId) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Reject Payment #' + paymentId,
                    text: 'Please enter the reason for rejecting this payment:',
                    input: 'text',
                    inputValue: 'Unverifiable GCash Reference / Invalid Screenshot',
                    inputPlaceholder: 'Reason for rejection...',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '<i class="fas fa-times me-1"></i> Reject Payment',
                    cancelButtonText: 'Cancel',
                    inputValidator: (value) => {
                        if (!value || !value.trim()) {
                            return 'Please provide a reason for rejecting the payment.';
                        }
                    }
                }).then((result) => {
                    if (result.isConfirmed && result.value) {
                        const reason = result.value.trim();
                        Swal.fire({
                            title: 'Rejecting Payment...',
                            text: 'Please wait while we update the transaction ledger.',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            didOpen: () => { Swal.showLoading(); }
                        });
                        fetch('sales_reports.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=reject_payment&payment_id=' + paymentId + '&booking_id=' + bookingId + '&reason=' + encodeURIComponent(reason)
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Payment Rejected',
                                    text: 'Payment #' + paymentId + ' has been marked as rejected.',
                                    confirmButtonColor: '#7b1113'
                                }).then(() => {
                                    openTransactionDetails(bookingId);
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Rejection Failed',
                                    text: res.message || 'Failed to reject payment',
                                    confirmButtonColor: '#7b1113'
                                });
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            Swal.fire({
                                icon: 'error',
                                title: 'Network Error',
                                text: 'Network error while rejecting payment.',
                                confirmButtonColor: '#7b1113'
                            });
                        });
                    }
                });
            } else {
                const reason = prompt('Please enter the reason for rejecting Payment #' + paymentId + ':', 'Unverifiable GCash Reference / Invalid Screenshot');
                if (reason === null) return;
                fetch('sales_reports.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=reject_payment&payment_id=' + paymentId + '&booking_id=' + bookingId + '&reason=' + encodeURIComponent(reason)
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert('Payment #' + paymentId + ' has been marked as rejected.');
                        openTransactionDetails(bookingId);
                    } else {
                        alert('Error: ' + (res.message || 'Failed to reject payment'));
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Network error while rejecting payment.');
                });
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function filterTransactions(filterType) {
            // 1. Update active tab buttons
            document.querySelectorAll('.txn-tab-btn').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-filter') === filterType);
            });

            // 2. Update active summary card styling
            document.querySelectorAll('.card-filterable').forEach(card => {
                card.classList.toggle('active', card.getAttribute('data-card-filter') === filterType);
            });

            // 3. Filter table rows
            const rows = document.querySelectorAll('.transactions-table tbody tr.transaction-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const rowType = row.getAttribute('data-filter-type');
                let show = false;

                if (filterType === 'all') {
                    show = true;
                } else if (filterType === 'active') {
                    show = (rowType === 'paid' || rowType === 'pending');
                } else if (filterType === 'paid') {
                    show = (rowType === 'paid');
                } else if (filterType === 'pending') {
                    show = (rowType === 'pending');
                } else if (filterType === 'cancelled') {
                    show = (rowType === 'cancelled');
                }

                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            // 4. Update entry count badge and header title
            const entryBadge = document.getElementById('transactionEntryBadge');
            if (entryBadge) {
                entryBadge.textContent = visibleCount + (visibleCount === 1 ? ' entry' : ' entries');
            }

            const titleEl = document.getElementById('transactionSectionTitle');
            if (titleEl) {
                let label = 'All Reservations';
                if (filterType === 'active') label = 'Active Reservations';
                else if (filterType === 'paid') label = 'Paid Reservations';
                else if (filterType === 'pending') label = 'Pending Payments';
                else if (filterType === 'cancelled') label = 'Cancelled Reservations';
                titleEl.textContent = '<?php echo htmlspecialchars($sales_month_label); ?> ' + label;
            }

            // 5. Toggle empty row
            const emptyRow = document.getElementById('filterEmptyRow');
            if (emptyRow) {
                emptyRow.style.display = (visibleCount === 0) ? '' : 'none';
            }

            // Smooth scroll to table if triggered from summary cards at top
            const section = document.querySelector('.transactions-section');
            if (section && window.scrollY < section.offsetTop - 150) {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function adminViewPaymentProof(bookingId, filename) {
            const modal = document.getElementById('adminPaymentProofModal');
            const proofImg = document.getElementById('adminProofImage');
            const spinner = document.getElementById('adminProofSpinner');
            const titleEl = document.getElementById('adminProofTitle');
            const fnEl = document.getElementById('adminProofFilenameText');
            const dlLink = document.getElementById('adminDownloadProofLink');

            if (!modal) return;

            if (titleEl) titleEl.textContent = 'Payment Proof Screenshot (Reservation #' + bookingId + ')';
            if (fnEl) fnEl.textContent = filename || '';

            const rawUrl = 'view_payment_proof.php?booking_id=' + bookingId + '&file=' + encodeURIComponent(filename) + '&raw=1';
            const downloadUrl = 'view_payment_proof.php?booking_id=' + bookingId + '&file=' + encodeURIComponent(filename) + '&download=1';

            if (dlLink) {
                dlLink.href = downloadUrl;
                dlLink.setAttribute('download', filename || 'payment_proof_' + bookingId + '.png');
            }

            if (proofImg) {
                proofImg.style.display = 'none';
                if (spinner) spinner.style.display = 'block';

                proofImg.onload = function() {
                    if (spinner) spinner.style.display = 'none';
                    proofImg.style.display = 'block';
                };
                proofImg.onerror = function() {
                    if (spinner) spinner.style.display = 'none';
                    proofImg.style.display = 'none';
                    if (fnEl) fnEl.textContent = 'Could not load proof image (' + filename + ')';
                };
                proofImg.src = rawUrl;
            }

            modal.style.display = 'flex';
        }

        function closeAdminPaymentProof() {
            const modal = document.getElementById('adminPaymentProofModal');
            if (modal) {
                modal.style.display = 'none';
            }
            const proofImg = document.getElementById('adminProofImage');
            if (proofImg) {
                proofImg.src = '';
                proofImg.style.display = 'none';
            }
        }

        // Close proof modal on backdrop click or Escape key
        document.addEventListener('DOMContentLoaded', function() {
            const proofModal = document.getElementById('adminPaymentProofModal');
            if (proofModal) {
                proofModal.addEventListener('click', function(e) {
                    if (e.target === proofModal) {
                        closeAdminPaymentProof();
                    }
                });
            }
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const modal = document.getElementById('adminPaymentProofModal');
                    if (modal && modal.style.display === 'flex') {
                        closeAdminPaymentProof();
                    }
                }
            });
        });

        // Allow Enter key to apply date filter if inputs exist
        ['start_date', 'end_date', 'sales_month'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('keypress', function(event) {
                    if (event.key === 'Enter') {
                        updateReports();
                    }
                });
            }
        });

        // ==========================================
        // SALES BAR CHART CONFIGURATION & LOGIC
        // ==========================================
        const salesChartData = {
            monthly: {
                labels: <?php echo json_encode(array_column(array_values($chart_monthly), 'label')); ?>,
                values: <?php echo json_encode(array_column(array_values($chart_monthly), 'sales')); ?>,
                counts: <?php echo json_encode(array_column(array_values($chart_monthly), 'count')); ?>,
                selected: <?php echo json_encode(array_column(array_values($chart_monthly), 'selected')); ?>
            },
            daily: {
                labels: <?php echo json_encode(array_column(array_values($chart_daily), 'label')); ?>,
                values: <?php echo json_encode(array_column(array_values($chart_daily), 'sales')); ?>,
                counts: <?php echo json_encode(array_column(array_values($chart_daily), 'count')); ?>
            },
            section: {
                labels: <?php echo json_encode(array_column($chart_sections, 'label')); ?>,
                values: <?php echo json_encode(array_column($chart_sections, 'sales')); ?>,
                counts: <?php echo json_encode(array_column($chart_sections, 'count')); ?>
            }
        };

        let currentChartView = 'monthly';
        let salesChartInstance = null;

        function initSalesBarChart() {
            const canvas = document.getElementById('salesBarChart');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');

            const normalGradient = ctx.createLinearGradient(0, 0, 0, 300);
            normalGradient.addColorStop(0, '#7b1113');
            normalGradient.addColorStop(1, '#a03a3c');

            const highlightGradient = ctx.createLinearGradient(0, 0, 0, 300);
            highlightGradient.addColorStop(0, '#8e1518');
            highlightGradient.addColorStop(1, '#5d0c0e');

            const bgColors = (salesChartData.monthly.selected || []).map(isSel => isSel ? highlightGradient : normalGradient);
            const borderColors = (salesChartData.monthly.selected || []).map(isSel => isSel ? '#5a0c0e' : '#7b1113');
            const borderWidths = (salesChartData.monthly.selected || []).map(isSel => isSel ? 2 : 1);

            salesChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: salesChartData.monthly.labels,
                    datasets: [{
                        label: 'Revenue (₱)',
                        data: salesChartData.monthly.values,
                        backgroundColor: bgColors,
                        borderColor: borderColors,
                        borderWidth: borderWidths,
                        borderRadius: 6,
                        borderSkipped: false,
                        maxBarThickness: 48
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(30, 41, 59, 0.95)',
                            titleColor: '#ffffff',
                            bodyColor: '#f8fafc',
                            padding: 12,
                            cornerRadius: 8,
                            titleFont: {
                                family: "'Inter', sans-serif",
                                size: 13,
                                weight: 'bold'
                            },
                            bodyFont: {
                                family: "'Inter', sans-serif",
                                size: 12
                            },
                            callbacks: {
                                label: function(context) {
                                    const val = context.parsed.y || 0;
                                    return ' Revenue: ₱' + val.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                },
                                afterLabel: function(context) {
                                    const count = (salesChartData[currentChartView].counts || [])[context.dataIndex] || 0;
                                    return ' Reservations: ' + count + (count === 1 ? ' transaction' : ' transactions');
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#64748b',
                                font: {
                                    family: "'Inter', sans-serif",
                                    size: 11,
                                    weight: '600'
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#f1f5f9'
                            },
                            ticks: {
                                color: '#64748b',
                                font: {
                                    family: "'Inter', sans-serif",
                                    size: 11
                                },
                                callback: function(value) {
                                    if (value >= 1000000) return '₱' + (value / 1000000).toFixed(1) + 'M';
                                    if (value >= 1000) return '₱' + (value / 1000).toFixed(0) + 'k';
                                    return '₱' + value;
                                }
                            }
                        }
                    }
                }
            });
        }

        function switchSalesChartView(view) {
            if (!salesChartInstance || !salesChartData[view]) return;
            currentChartView = view;

            // Update toggle buttons active class
            document.querySelectorAll('.chart-toggle-btn').forEach(btn => btn.classList.remove('active'));
            if (view === 'monthly') {
                const b = document.getElementById('btnChartMonthly');
                if (b) b.classList.add('active');
            } else if (view === 'daily') {
                const b = document.getElementById('btnChartDaily');
                if (b) b.classList.add('active');
            } else if (view === 'section') {
                const b = document.getElementById('btnChartSection');
                if (b) b.classList.add('active');
            }

            const ctx = document.getElementById('salesBarChart').getContext('2d');
            const normalGradient = ctx.createLinearGradient(0, 0, 0, 300);
            normalGradient.addColorStop(0, '#7b1113');
            normalGradient.addColorStop(1, '#a03a3c');

            const highlightGradient = ctx.createLinearGradient(0, 0, 0, 300);
            highlightGradient.addColorStop(0, '#8e1518');
            highlightGradient.addColorStop(1, '#5d0c0e');

            const dataObj = salesChartData[view];
            salesChartInstance.data.labels = dataObj.labels;
            salesChartInstance.data.datasets[0].data = dataObj.values;

            if (view === 'monthly' && dataObj.selected) {
                salesChartInstance.data.datasets[0].backgroundColor = dataObj.selected.map(isSel => isSel ? highlightGradient : normalGradient);
                salesChartInstance.data.datasets[0].borderColor = dataObj.selected.map(isSel => isSel ? '#5a0c0e' : '#7b1113');
                salesChartInstance.data.datasets[0].borderWidth = dataObj.selected.map(isSel => isSel ? 2 : 1);
                salesChartInstance.data.datasets[0].maxBarThickness = 48;
            } else if (view === 'daily') {
                salesChartInstance.data.datasets[0].backgroundColor = normalGradient;
                salesChartInstance.data.datasets[0].borderColor = '#7b1113';
                salesChartInstance.data.datasets[0].borderWidth = 1;
                salesChartInstance.data.datasets[0].maxBarThickness = 18;
            } else {
                const sectionPalette = [
                    '#7b1113', '#94171a', '#a82c30', '#b91c1c', '#c2410c', '#b45309', '#047857', '#4338ca'
                ];
                salesChartInstance.data.datasets[0].backgroundColor = dataObj.labels.map((_, i) => sectionPalette[i % sectionPalette.length]);
                salesChartInstance.data.datasets[0].borderColor = '#ffffff';
                salesChartInstance.data.datasets[0].borderWidth = 1;
                salesChartInstance.data.datasets[0].maxBarThickness = 56;
            }

            salesChartInstance.update();
        }

        // Initialize bar chart on DOM load
        document.addEventListener('DOMContentLoaded', function() {
            initSalesBarChart();
        });
        function openPaymentRemindersModal() {
            const modalEl = document.getElementById('paymentRemindersModal');
            if (!modalEl) return;
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
            loadPaymentReminderHistory();
        }

        function loadPaymentReminderHistory() {
            const tbody = document.getElementById('reminderHistoryTableBody');
            const badge = document.getElementById('reminderHistoryCountBadge');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-danger me-1"></div> Loading reminder history...</td></tr>';
            }

            fetch('sales_reports.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_payment_reminder_history'
            })
            .then(r => r.json())
            .then(data => {
                if (!tbody) return;
                if (!data.success || !data.reminders || data.reminders.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-inbox me-1"></i> No reminders recorded yet. Click "Check & Send Due Reminders Now" to scan active schedules.</td></tr>';
                    if (badge) badge.textContent = '0 entries';
                    return;
                }

                if (badge) badge.textContent = data.reminders.length + ' entries';

                const badgeMap = {
                    'OVERDUE': 'bg-danger',
                    'DUE_DATE': 'bg-warning text-dark',
                    '1_DAY_BEFORE': 'bg-primary',
                    '7_DAYS_BEFORE': 'bg-info text-dark'
                };

                let rowsHtml = '';
                data.reminders.forEach((r, idx) => {
                    const badgeClass = badgeMap[r.reminder_type] || 'bg-secondary';
                    const plotStr = r.section ? (r.section + ' ' + (r.plot || '')) : '—';
                    const instStr = r.installment_number ? ('Inst #' + r.installment_number) : '';
                    const amtStr = r.monthly_amount > 0 ? ('₱' + parseFloat(r.monthly_amount).toLocaleString(undefined, {minimumFractionDigits: 2})) : '—';
                    
                    rowsHtml += `
                        <tr>
                            <td class="text-muted small">${idx + 1}</td>
                            <td>
                                <div class="fw-bold text-dark">${escapeHtml(r.customer_name)}</div>
                                <div class="small text-muted font-monospace">Reservation #${r.booking_id} ${instStr}</div>
                            </td>
                            <td class="small">${escapeHtml(r.customer_email)}</td>
                            <td class="small fw-semibold">${escapeHtml(plotStr)}</td>
                            <td>
                                <div class="fw-bold text-dark">${amtStr}</div>
                                <div class="small text-muted">Due: ${escapeHtml(r.due_date)}</div>
                            </td>
                            <td>
                                <span class="badge ${badgeClass} text-uppercase px-2 py-1" style="font-size: 0.72rem;">${escapeHtml(r.reminder_type.replace(/_/g, ' '))}</span>
                            </td>
                            <td class="small text-muted font-monospace">${escapeHtml(r.sent_at)}</td>
                        </tr>
                    `;
                });
                tbody.innerHTML = rowsHtml;
            })
            .catch(err => {
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-3 text-danger"><i class="fas fa-exclamation-triangle me-1"></i> Error loading reminders: ' + escapeHtml(err.message) + '</td></tr>';
                }
            });
        }

        function dispatchPaymentRemindersNow() {
            const btn = document.getElementById('btnDispatchReminders');
            const alertBox = document.getElementById('reminderDispatchAlert');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Scanning & Dispatching...';
            }
            if (alertBox) alertBox.style.display = 'none';

            fetch('sales_reports.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=dispatch_payment_reminders'
            })
            .then(r => r.json())
            .then(data => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Check & Send Due Reminders Now';
                }
                if (alertBox) {
                    alertBox.style.display = 'block';
                    if (data.success && data.summary) {
                        const s = data.summary;
                        alertBox.className = 'alert alert-success d-flex align-items-center gap-2 py-2 px-3 small';
                        alertBox.innerHTML = '<div><i class="fas fa-check-circle fs-5 me-1"></i> <strong>Scan Complete!</strong> Evaluated <b>' + s.evaluated + '</b> installment(s). Sent <b>' + s.reminders_sent + '</b> new reminder(s). Skipped <b>' + s.skipped_dup + '</b> duplicate(s).</div>';
                    } else {
                        alertBox.className = 'alert alert-info py-2 px-3 small';
                        alertBox.innerHTML = '<i class="fas fa-info-circle me-1"></i> ' + escapeHtml(data.message || 'Reminders checked successfully.');
                    }
                }
                loadPaymentReminderHistory();
            })
            .catch(err => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Check & Send Due Reminders Now';
                }
                if (alertBox) {
                    alertBox.style.display = 'block';
                    alertBox.className = 'alert alert-danger py-2 px-3 small';
                    alertBox.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i> Dispatch request failed: ' + escapeHtml(err.message);
                }
            });
        }

        // ── Installment Payments Modal ──────────────────────────────────────
        let _instContracts = [];

        function openInstallmentPaymentsModal() {
            const startDate = document.getElementById('start_date') ? document.getElementById('start_date').value : '<?php echo $start_date; ?>';
            const endDate = document.getElementById('end_date') ? document.getElementById('end_date').value : '<?php echo $end_date; ?>';
            const month = document.getElementById('sales_month') ? document.getElementById('sales_month').value : '<?php echo $sales_month; ?>';
            const modalEl = document.getElementById('installmentPaymentsModal');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

            // Reset UI
            document.getElementById('installmentPaymentsLoading').style.display = 'block';
            document.getElementById('installmentPaymentsTableWrap').style.display = 'none';
            document.getElementById('installmentPaymentsSummary').style.display = 'none';
            document.getElementById('installmentPaymentsEmpty').style.display = 'none';
            document.getElementById('installmentPaymentsTbody').innerHTML = '';
            document.getElementById('installmentPaymentsMonthLabel').textContent = '';
            if (document.getElementById('instStatusFilter')) document.getElementById('instStatusFilter').value = 'all';
            if (document.getElementById('instSearchInput')) document.getElementById('instSearchInput').value = '';

            modal.show();

            const postBody = 'action=get_installment_payments&start_date=' + encodeURIComponent(startDate) +
                             '&end_date=' + encodeURIComponent(endDate) +
                             '&sales_month=' + encodeURIComponent(month);

            fetch('sales_reports.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: postBody
            })
            .then(r => r.json())
            .then(data => {
                document.getElementById('installmentPaymentsLoading').style.display = 'none';
                if (!data.success) {
                    document.getElementById('installmentPaymentsTableWrap').style.display = 'block';
                    document.getElementById('installmentPaymentsEmpty').style.display = 'block';
                    return;
                }
                document.getElementById('installmentPaymentsMonthLabel').textContent = data.month || '';
                _instContracts = data.contracts || [];
                renderInstallmentTable(_instContracts);
                document.getElementById('installmentPaymentsSummary').style.display = 'block';
                document.getElementById('installmentPaymentsTableWrap').style.display = 'block';
            })
            .catch(err => {
                document.getElementById('installmentPaymentsLoading').style.display = 'none';
                document.getElementById('installmentPaymentsTableWrap').style.display = 'block';
                document.getElementById('installmentPaymentsTbody').innerHTML = `<tr><td colspan="10" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle me-1"></i> Failed to load: ${escapeHtml(err.message)}</td></tr>`;
            });
        }

        function filterInstallmentTable() {
            const filter = document.getElementById('instStatusFilter')?.value || 'all';
            const search = (document.getElementById('instSearchInput')?.value || '').trim().toLowerCase();

            const filtered = _instContracts.filter(c => {
                // Status filter
                if (filter === 'PAID' && c.overall_status !== 'PAID') return false;
                if (filter === 'ACTIVE' && c.overall_status !== 'ACTIVE') return false;
                if (filter === 'OVERDUE' && c.overdue_count === 0 && c.overall_status !== 'OVERDUE') return false;
                if (filter === 'PENDING' && c.pending_count === 0 && c.overall_status !== 'FOR VERIFICATION') return false;

                // Search filter
                if (search) {
                    const hay = (
                        (c.customer_name || '') + ' ' + 
                        (c.customer_email || '') + ' ' + 
                        (c.contact_number || '') + ' ' + 
                        (c.section || '') + ' ' + 
                        (c.plot || '') + ' ' + 
                        'contract ' + c.booking_id + ' ' + 
                        'booking ' + c.booking_id
                    ).toLowerCase();
                    if (!hay.includes(search)) return false;
                }

                return true;
            });

            renderInstallmentTable(filtered);
        }

        function renderInstallmentTable(contracts) {
            const tbody = document.getElementById('installmentPaymentsTbody');
            const emptyEl = document.getElementById('installmentPaymentsEmpty');
            const summBadge = document.getElementById('instSummTotal');
            if (summBadge) summBadge.textContent = contracts.length + ' account' + (contracts.length !== 1 ? 's' : '');

            if (!contracts || contracts.length === 0) {
                tbody.innerHTML = '';
                if (emptyEl) emptyEl.style.display = 'block';
                return;
            }
            if (emptyEl) emptyEl.style.display = 'none';

            let rows = '';
            contracts.forEach(c => {
                const origIdx = _instContracts.indexOf(c);
                
                let statusBadge = '';
                if (c.pending_count > 0) {
                    statusBadge = `<span class="badge" style="background: rgba(245,158,11,0.18); color: #b45309; border: 1px solid rgba(245,158,11,0.4); font-size: 0.74rem;"><i class="fas fa-clock me-1"></i>FOR REVIEW (${c.pending_count})</span>`;
                } else if (c.overdue_count > 0) {
                    statusBadge = `<span class="badge" style="background: rgba(239,68,68,0.15); color: #dc2626; border: 1px solid rgba(239,68,68,0.35); font-size: 0.74rem;"><i class="fas fa-exclamation-triangle me-1"></i>OVERDUE (${c.overdue_count})</span>`;
                } else if (c.overall_status === 'PAID') {
                    statusBadge = `<span class="badge" style="background: rgba(16,185,129,0.15); color: #065f46; border: 1px solid rgba(16,185,129,0.3); font-size: 0.74rem;"><i class="fas fa-check-circle me-1"></i>FULLY PAID</span>`;
                } else {
                    statusBadge = `<span class="badge" style="background: rgba(13,110,253,0.12); color: #084298; border: 1px solid rgba(13,110,253,0.25); font-size: 0.74rem;"><i class="fas fa-shield-alt me-1"></i>ACTIVE</span>`;
                }

                const progressPercent = c.total_count > 0 ? Math.min(100, Math.round((c.paid_count / c.total_count) * 100)) : 0;

                rows += `
                    <tr class="inst-contract-row" onclick="openCustomerInstallmentDetails(${origIdx})" style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s; cursor: pointer;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <td style="padding: 0.75rem 0.85rem; font-family: monospace; font-size: 0.84rem; color: #7b1113; font-weight: 700;">
                            Contract #${c.booking_id}
                        </td>
                        <td style="padding: 0.75rem 0.85rem;">
                            <div class="fw-bold text-dark" style="font-size: 0.88rem;">${escapeHtml(c.customer_name)}</div>
                            <div class="text-muted small" style="font-size: 0.75rem;">
                                ${c.contact_number ? `<i class="fas fa-phone-alt me-1"></i>${escapeHtml(c.contact_number)}` : ''}
                                ${c.customer_email ? ` &bull; ${escapeHtml(c.customer_email)}` : ''}
                            </div>
                        </td>
                        <td style="padding: 0.75rem 0.85rem; white-space: nowrap;">
                            <span class="fw-semibold text-dark">Section ${escapeHtml(c.section)}</span>
                            <div class="text-muted small" style="font-size: 0.74rem;">Plot ${escapeHtml(c.plot)}</div>
                        </td>
                        <td style="padding: 0.75rem 0.85rem; text-align: center; white-space: nowrap;">
                            <span class="badge bg-secondary-subtle text-secondary border" style="font-size: 0.76rem;">
                                ${c.term_months}-Month Plan
                            </span>
                        </td>
                        <td style="padding: 0.75rem 0.85rem; font-weight: 600; color: #374151;">
                            ₱${c.total_amount}
                        </td>
                        <td style="padding: 0.75rem 0.85rem; font-weight: 700; color: #059669;">
                            ₱${c.total_paid}
                        </td>
                        <td style="padding: 0.75rem 0.85rem; font-weight: 700; color: ${parseFloat((c.remaining_balance || '0').replace(/,/g, '')) > 0 ? '#b91c1c' : '#059669'};">
                            ₱${c.remaining_balance}
                        </td>
                        <td style="padding: 0.75rem 0.85rem; text-align: center;">
                            <div class="d-flex align-items-center justify-content-center gap-1">
                                <span class="badge bg-light text-dark border" style="font-size: 0.76rem;">${c.paid_count} / ${c.total_count} Paid</span>
                            </div>
                            <div class="progress mt-1" style="height: 5px; width: 90px; margin: 0 auto; background: #e2e8f0; border-radius: 99px;">
                                <div class="progress-bar bg-success" style="width: ${progressPercent}%;"></div>
                            </div>
                        </td>
                        <td style="padding: 0.75rem 0.85rem; text-align: center; white-space: nowrap;">
                            ${statusBadge}
                        </td>
                        <td style="padding: 0.75rem 0.85rem; text-align: center; white-space: nowrap;">
                            <button type="button" class="btn btn-sm btn-primary py-1 px-3" style="background: #7b1113; border: none; font-size: 0.78rem; border-radius: 7px; font-weight: 600;" onclick="openCustomerInstallmentDetails(${origIdx}); event.stopPropagation();" title="View all installment dues and payments">
                                <i class="fas fa-list-check me-1"></i>View Details
                            </button>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = rows;
        }

        function openCustomerInstallmentDetails(index) {
            const c = _instContracts[index];
            if (!c) return;

            const modalEl = document.getElementById('installmentReviewModal');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

            const titleEl = document.getElementById('installmentReviewModalLabel');
            const subLabelEl = document.getElementById('installmentReviewSubLabel');
            const bodyEl = document.getElementById('installmentReviewBody');
            const footerEl = document.getElementById('installmentReviewFooter');

            titleEl.innerHTML = `<i class="fas fa-file-invoice-dollar me-2 text-warning"></i>Installment Payment Details — Contract #${c.booking_id}`;
            subLabelEl.textContent = `${c.customer_name} • Section ${c.section} Plot ${c.plot} • ${c.term_months}-Month Plan`;

            const progressPercent = c.total_count > 0 ? Math.min(100, Math.round((c.paid_count / c.total_count) * 100)) : 0;

            // Check if any installment has pending proof needing verification
            const pendingInst = (c.installments || []).find(ins => ins.sched_status === 'FOR VERIFICATION' || (ins.has_proof && ins.sched_status !== 'PAID' && ins.sched_status !== 'REJECTED'));

            let pendingAlertHtml = '';
            if (pendingInst && (pendingInst.sched_status === 'FOR VERIFICATION' || pendingInst.has_proof)) {
                const safeFn = (pendingInst.proof_filename || '').replace(/'/g, "\\'");
                const proofUrl = 'view_payment_proof.php?booking_id=' + c.booking_id + '&file=' + encodeURIComponent(pendingInst.proof_filename) + '&raw=1';
                pendingAlertHtml = `
                    <div class="card border-warning border-2 shadow-sm mb-4" style="background: #fffdf5; border-radius: 12px;">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                <div class="fw-bold text-dark d-flex align-items-center gap-2">
                                    <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending Review</span>
                                    <span>Payment Submitted for Installment #${pendingInst.installment_number}</span>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-success fw-bold" onclick="reviewApprovePayment(${pendingInst.receipt_id}, ${c.booking_id})">
                                        <i class="fas fa-check-circle me-1"></i> Approve Payment
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger fw-bold" onclick="reviewRejectPayment(${pendingInst.receipt_id}, ${c.booking_id})">
                                        <i class="fas fa-times-circle me-1"></i> Reject Payment
                                    </button>
                                </div>
                            </div>
                            <div class="row align-items-center g-3">
                                <div class="col-md-7">
                                    <div class="small text-muted mb-1">GCash / Reference Number: <strong class="text-dark font-monospace">${escapeHtml(pendingInst.reference)}</strong></div>
                                    <div class="small text-muted mb-1">Amount Submitted: <strong class="text-success fs-6">₱${pendingInst.paid_amount}</strong> (Monthly Due: ₱${pendingInst.monthly_amount})</div>
                                    <div class="small text-muted">Submitted Date: <strong>${escapeHtml(pendingInst.paid_at)}</strong></div>
                                </div>
                                <div class="col-md-5 text-end">
                                    ${pendingInst.has_proof ? `
                                        <img src="${proofUrl}" alt="Proof" style="max-height: 110px; border-radius: 8px; border: 1px solid #ddd; cursor: pointer;" onclick="adminViewPaymentProof(${c.booking_id}, '${safeFn}')" title="Click to view full image">
                                        <div class="text-muted small mt-1" style="font-size: 0.72rem;"><i class="fas fa-search-plus me-1"></i>Click image to pop out</div>
                                    ` : '<span class="text-muted small">No screenshot image</span>'}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }

            // Build the complete installment schedule breakdown table
            let instRows = '';
            (c.installments || []).forEach(ins => {
                const isPaid = (ins.sched_status === 'PAID');
                const isPendingVer = (ins.sched_status === 'FOR VERIFICATION');
                const isOverdue = (ins.sched_status === 'OVERDUE');
                const isRejected = (ins.sched_status === 'REJECTED');

                let statusBadge = '';
                if (isPaid) {
                    statusBadge = `<span class="badge" style="background: rgba(16,185,129,0.15); color: #065f46; border: 1px solid rgba(16,185,129,0.3); font-size: 0.72rem;"><i class="fas fa-check-circle me-1"></i>PAID</span>`;
                } else if (isPendingVer) {
                    statusBadge = `<span class="badge" style="background: rgba(245,158,11,0.18); color: #b45309; border: 1px solid rgba(245,158,11,0.4); font-size: 0.72rem;"><i class="fas fa-clock me-1"></i>VERIFICATION</span>`;
                } else if (isOverdue) {
                    statusBadge = `<span class="badge" style="background: rgba(239,68,68,0.15); color: #dc2626; border: 1px solid rgba(239,68,68,0.35); font-size: 0.72rem;"><i class="fas fa-exclamation-triangle me-1"></i>OVERDUE</span>`;
                } else if (isRejected) {
                    statusBadge = `<span class="badge bg-secondary" style="font-size: 0.72rem;"><i class="fas fa-ban me-1"></i>REJECTED</span>`;
                } else {
                    statusBadge = `<span class="badge bg-light text-muted border" style="font-size: 0.72rem;"><i class="fas fa-calendar me-1"></i>PENDING</span>`;
                }

                let proofCell = '<span class="text-muted">—</span>';
                if (ins.has_proof) {
                    const safeFn = (ins.proof_filename || '').replace(/'/g, "\\'");
                    proofCell = `
                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.74rem; border-radius: 6px;" onclick="adminViewPaymentProof(${c.booking_id}, '${safeFn}')" title="View Uploaded Payment Proof">
                            <i class="fas fa-image me-1"></i>Proof
                        </button>
                    `;
                }

                let actionCell = '—';
                if (isPendingVer && ins.receipt_id > 0) {
                    actionCell = `
                        <div class="d-flex gap-1 justify-content-center">
                            <button type="button" class="btn btn-sm btn-success py-0 px-2" style="font-size: 0.72rem;" onclick="reviewApprovePayment(${ins.receipt_id}, ${c.booking_id})" title="Approve Payment">
                                <i class="fas fa-check"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-danger py-0 px-2" style="font-size: 0.72rem;" onclick="reviewRejectPayment(${ins.receipt_id}, ${c.booking_id})" title="Reject Payment">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    `;
                } else if (isPaid && ins.receipt_id > 0) {
                    actionCell = `
                        <a href="print_invoice.php?booking_id=${c.booking_id}&payment_id=${ins.receipt_id}" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.72rem;" title="Print Receipt">
                            <i class="fas fa-print"></i> Receipt
                        </a>
                    `;
                }

                instRows += `
                    <tr style="border-bottom: 1px solid #f1f5f9; ${isPendingVer ? 'background:#fffdf7;' : (isOverdue ? 'background:#fef2f2;' : '')}">
                        <td style="padding: 0.65rem 0.75rem; text-align: center; font-weight: 700; color: #374151;">
                            #${ins.installment_number}
                        </td>
                        <td style="padding: 0.65rem 0.75rem; white-space: nowrap; font-size: 0.82rem; color: #4b5563;">
                            ${escapeHtml(ins.due_date)}
                        </td>
                        <td style="padding: 0.65rem 0.75rem; font-weight: 600; color: #1f2937;">
                            ₱${ins.monthly_amount}
                        </td>
                        <td style="padding: 0.65rem 0.75rem; font-weight: 700; color: ${isPaid ? '#059669' : '#6b7280'};">
                            ${isPaid ? '₱' + ins.paid_amount : '—'}
                        </td>
                        <td style="padding: 0.65rem 0.75rem; text-align: center;">
                            ${statusBadge}
                        </td>
                        <td style="padding: 0.65rem 0.75rem; font-size: 0.78rem;">
                            ${ins.paid_at !== '—' ? `<span class="fw-semibold text-dark">${escapeHtml(ins.paid_at)}</span>` : '<span class="text-muted">—</span>'}
                            ${ins.timing_label && isPaid ? `<div class="text-muted" style="font-size: 0.70rem;">${escapeHtml(ins.timing_label)}</div>` : ''}
                        </td>
                        <td style="padding: 0.65rem 0.75rem; font-family: monospace; font-size: 0.76rem; color: #374151;">
                            ${ins.reference !== '—' ? `<code class="bg-light border px-1 rounded">${escapeHtml(ins.reference)}</code>` : '<span class="text-muted">—</span>'}
                        </td>
                        <td style="padding: 0.65rem 0.75rem; text-align: center;">
                            ${proofCell}
                        </td>
                        <td style="padding: 0.65rem 0.75rem; text-align: center;">
                            ${actionCell}
                        </td>
                    </tr>
                `;
            });

            bodyEl.innerHTML = `
                ${pendingAlertHtml}

                <!-- Customer & Contract Overview Card -->
                <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; background: #fff; border: 1px solid #e2e8f0;">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <h5 class="fw-bold text-dark mb-0">${escapeHtml(c.customer_name)}</h5>
                                    <span class="badge bg-danger text-white fw-bold px-2 py-1" style="font-size: 0.72rem;">Contract #${c.booking_id}</span>
                                </div>
                                <div class="text-muted small d-flex flex-wrap gap-3 mt-1">
                                    <span><i class="fas fa-map-marker-alt text-danger me-1"></i>Section ${escapeHtml(c.section)} &bull; Plot ${escapeHtml(c.plot)}</span>
                                    ${c.contact_number ? `<span><i class="fas fa-phone text-secondary me-1"></i>${escapeHtml(c.contact_number)}</span>` : ''}
                                    ${c.customer_email ? `<span><i class="fas fa-envelope text-secondary me-1"></i>${escapeHtml(c.customer_email)}</span>` : ''}
                                    <span><i class="fas fa-calendar-alt text-secondary me-1"></i>${c.term_months}-Month Plan</span>
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-light text-dark border px-3 py-2 fw-bold" style="font-size: 0.85rem;">
                                    ${c.paid_count} of ${c.total_count} Months Cleared (${progressPercent}%)
                                </span>
                            </div>
                        </div>

                        <div class="row g-3 mt-3 pt-3 border-top">
                            <div class="col-md-4 col-sm-6">
                                <div class="p-3 rounded bg-light border">
                                    <span class="text-muted small d-block">Total Contract Price:</span>
                                    <span class="fw-bold text-dark fs-5">₱${c.total_amount}</span>
                                </div>
                            </div>
                            <div class="col-md-4 col-sm-6">
                                <div class="p-3 rounded bg-light border">
                                    <span class="text-muted small d-block">Total Amount Paid:</span>
                                    <span class="fw-bold text-success fs-5">₱${c.total_paid}</span>
                                </div>
                            </div>
                            <div class="col-md-4 col-sm-12">
                                <div class="p-3 rounded bg-light border">
                                    <span class="text-muted small d-block">Remaining Balance:</span>
                                    <span class="fw-bold text-danger fs-5">₱${c.remaining_balance}</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3">
                            <div class="d-flex justify-content-between text-muted small fw-semibold mb-1">
                                <span>Installment Completion Progress</span>
                                <span>${progressPercent}%</span>
                            </div>
                            <div class="progress" style="height: 9px; border-radius: 99px; background: #e2e8f0;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: ${progressPercent}%;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- All Installment Schedules Table ("nandun na lahat ng babayaran nya") -->
                <div class="card border-0 shadow-sm" style="border-radius: 14px; background: #fff; overflow: hidden; border: 1px solid #e2e8f0;">
                    <div class="card-header bg-light py-3 px-4 d-flex justify-content-between align-items-center border-bottom">
                        <div class="fw-bold text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                            <i class="fas fa-calendar-check text-danger"></i>
                            <span>All Scheduled Installments &amp; Payment Dues (${(c.installments || []).length} Months)</span>
                        </div>
                        <div class="text-muted small">
                            Every scheduled monthly payment for this contract
                        </div>
                    </div>
                    <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                        <table class="table table-hover mb-0" style="font-size: 0.82rem; font-family: 'Inter', sans-serif;">
                            <thead style="background: #f8fafc; position: sticky; top: 0; z-index: 2;">
                                <tr style="border-bottom: 2px solid #e2e8f0;">
                                    <th style="padding: 0.65rem 0.75rem; text-align: center; font-weight: 700; color: #475569;">Inst. #</th>
                                    <th style="padding: 0.65rem 0.75rem; font-weight: 700; color: #475569;">Scheduled Due Date</th>
                                    <th style="padding: 0.65rem 0.75rem; font-weight: 700; color: #475569;">Monthly Due</th>
                                    <th style="padding: 0.65rem 0.75rem; font-weight: 700; color: #475569;">Amount Paid</th>
                                    <th style="padding: 0.65rem 0.75rem; text-align: center; font-weight: 700; color: #475569;">Status</th>
                                    <th style="padding: 0.65rem 0.75rem; font-weight: 700; color: #475569;">Payment Timing / Date</th>
                                    <th style="padding: 0.65rem 0.75rem; font-weight: 700; color: #475569;">Reference #</th>
                                    <th style="padding: 0.65rem 0.75rem; text-align: center; font-weight: 700; color: #475569;">Proof</th>
                                    <th style="padding: 0.65rem 0.75rem; text-align: center; font-weight: 700; color: #475569;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${instRows || '<tr><td colspan="9" class="text-center py-4 text-muted">No installment schedule found for this contract.</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;

            footerEl.innerHTML = `
                <div class="d-flex gap-2">
                    ${c.user_id ? `
                        <a href="monthly_payments.php?user_id=${c.user_id}" target="_blank" class="btn btn-outline-danger" style="border-radius: 8px; font-weight: 600; font-size: 0.85rem;">
                            <i class="fas fa-external-link-alt me-1"></i> Open Amortization Ledger
                        </a>
                    ` : ''}
                    <button type="button" class="btn btn-outline-secondary" onclick="window.print()" style="border-radius: 8px; font-size: 0.85rem;">
                        <i class="fas fa-print me-1"></i> Print Statement
                    </button>
                </div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px; font-weight: 600; font-size: 0.85rem;">
                    <i class="fas fa-times me-1"></i> Close
                </button>
            `;

            modal.show();
        }

        // Backward compatibility
        function openInstallmentReviewModal(index) {
            openCustomerInstallmentDetails(index);
        }

        function reviewApprovePayment(paymentId, bookingId) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Approve Payment #' + paymentId + '?',
                    text: 'Verify and approve this monthly installment payment?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#198754',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '<i class="fas fa-check me-1"></i> Yes, Approve',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Approving Payment...',
                            allowOutsideClick: false,
                            didOpen: () => { Swal.showLoading(); }
                        });
                        fetch('sales_reports.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=verify_payment&payment_id=' + paymentId + '&booking_id=' + bookingId
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Payment Approved!',
                                    text: 'Installment payment #' + paymentId + ' has been marked as PAID.',
                                    confirmButtonColor: '#7b1113'
                                }).then(() => {
                                    const reviewModalEl = document.getElementById('installmentReviewModal');
                                    const reviewModal = bootstrap.Modal.getInstance(reviewModalEl);
                                    if (reviewModal) reviewModal.hide();
                                    openInstallmentPaymentsModal();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Approval Failed',
                                    text: res.message || 'Failed to approve payment',
                                    confirmButtonColor: '#7b1113'
                                });
                            }
                        })
                        .catch(err => {
                            Swal.fire({
                                icon: 'error',
                                title: 'Network Error',
                                text: err.message,
                                confirmButtonColor: '#7b1113'
                            });
                        });
                    }
                });
            } else {
                if (!confirm('Approve payment #' + paymentId + '?')) return;
                fetch('sales_reports.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=verify_payment&payment_id=' + paymentId + '&booking_id=' + bookingId
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert('Payment approved!');
                        const reviewModalEl = document.getElementById('installmentReviewModal');
                        const reviewModal = bootstrap.Modal.getInstance(reviewModalEl);
                        if (reviewModal) reviewModal.hide();
                        openInstallmentPaymentsModal();
                    } else {
                        alert(res.message || 'Failed');
                    }
                });
            }
        }

        function reviewRejectPayment(paymentId, bookingId) {
            const doReject = (reason) => {
                fetch('sales_reports.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=reject_payment&payment_id=' + paymentId + '&booking_id=' + bookingId + '&reason=' + encodeURIComponent(reason)
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'info',
                                title: 'Payment Rejected',
                                text: 'Installment payment #' + paymentId + ' has been marked as REJECTED.',
                                confirmButtonColor: '#7b1113'
                            }).then(() => {
                                const reviewModalEl = document.getElementById('installmentReviewModal');
                                const reviewModal = bootstrap.Modal.getInstance(reviewModalEl);
                                if (reviewModal) reviewModal.hide();
                                openInstallmentPaymentsModal();
                            });
                        } else {
                            alert('Payment rejected.');
                            const reviewModalEl = document.getElementById('installmentReviewModal');
                            const reviewModal = bootstrap.Modal.getInstance(reviewModalEl);
                            if (reviewModal) reviewModal.hide();
                            openInstallmentPaymentsModal();
                        }
                    } else {
                        alert(res.message || 'Failed to reject payment');
                    }
                });
            };

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Reject Payment #' + paymentId + '?',
                    input: 'textarea',
                    inputLabel: 'Reason for rejection',
                    inputPlaceholder: 'State why the payment was rejected (e.g. Invalid reference number, blurry proof)...',
                    inputAttributes: { 'aria-label': 'Reason for rejection' },
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '<i class="fas fa-times me-1"></i> Reject Payment',
                    inputValidator: (value) => {
                        if (!value || !value.trim()) {
                            return 'Please enter a reason for rejection!';
                        }
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        doReject(result.value);
                    }
                });
            } else {
                const reason = prompt('Please enter the reason for rejection:');
                if (reason !== null && reason.trim()) {
                    doReject(reason);
                }
            }
        }
    </script>
</body>
</html>

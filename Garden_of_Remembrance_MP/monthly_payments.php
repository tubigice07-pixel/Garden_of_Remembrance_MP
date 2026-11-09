<?php
/**
 * =========================================================================
 * Garden of Remembrance Memorial Park
 * Monthly Payment Records (Per-Account Ledger)
 * =========================================================================
 * Displays all monthly payment schedules, milestones, collected receipts,
 * and outstanding balances on a per-account basis.
 * 
 * Supports both:
 * - Administrators: Searchable account directory, multi-account switcher,
 *   offline payment recording, proof audit, and printable statement of account.
 * - Customer Accounts: Self-service ledger of personal contracts, payment
 *   history, GCash gateway pay buttons, and receipt downloads.
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/connection.php';
if (file_exists(__DIR__ . '/config/payment_helper.php')) {
    require_once __DIR__ . '/config/payment_helper.php';
}
if (file_exists(__DIR__ . '/config/installment_helper.php')) {
    require_once __DIR__ . '/config/installment_helper.php';
}
if (file_exists(__DIR__ . '/includes/formatters.php')) {
    require_once __DIR__ . '/includes/formatters.php';
}

// Ensure installment tables exist
if (function_exists('ensure_installment_tables')) {
    ensure_installment_tables($conn);
}

// -------------------------------------------------------------------------
// 1. Authentication & Role Authorization
// -------------------------------------------------------------------------
if (empty($_SESSION['user_id'])) {
    header('Location: login.php?auth_required=1');
    exit;
}

$session_uid = (int)$_SESSION['user_id'];
$session_role = strtoupper(trim((string)($_SESSION['role'] ?? '')));

if (empty($session_role)) {
    $rCheck = $conn->query("SELECT role FROM tbl_user WHERE user_id = $session_uid LIMIT 1");
    if ($rCheck && $rRow = $rCheck->fetch_assoc()) {
        $session_role = strtoupper(trim($rRow['role']));
        $_SESSION['role'] = $session_role;
    }
}

$isAdmin = ($session_role === 'ADMIN');
if (!$isAdmin) {
    header('Location: user_dashboard.php');
    exit;
}
$flash_success = '';
$flash_error = '';

// Helper for display currency
if (!function_exists('formatDisplayCurrency')) {
    function formatDisplayCurrency($amount): string {
        return '₱' . number_format((float)$amount, 2);
    }
}

// -------------------------------------------------------------------------
// 2. Handle POST Actions (Admin Record Payment, Send Reminder)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $post_action = trim($_POST['action']);

    // Admin: Manually Record Installment Payment
    if ($post_action === 'record_installment_payment') {
        if (!$isAdmin) {
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => 'Unauthorized action.']));
        }

        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        $contract_id = (int)($_POST['contract_id'] ?? 0);
        $amount_paid = (float)($_POST['amount_paid'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? 'Cash on Site');
        $reference_no = trim($_POST['reference_no'] ?? '');
        $or_number = trim($_POST['or_number'] ?? '');
        $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d H:i:s'));
        $notes = trim($_POST['notes'] ?? '');

        if ($schedule_id <= 0 && $contract_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid schedule or contract ID.']);
            exit;
        }

        if ($amount_paid <= 0) {
            echo json_encode(['success' => false, 'message' => 'Payment amount must be greater than zero.']);
            exit;
        }

        // Fetch schedule row
        $schRow = null;
        if ($schedule_id > 0) {
            $sRes = $conn->query("SELECT * FROM payment_schedules WHERE id = $schedule_id LIMIT 1");
            if ($sRes) $schRow = $sRes->fetch_assoc();
        }

        if (!$schRow && $contract_id > 0) {
            $sRes = $conn->query("SELECT * FROM payment_schedules WHERE contract_id = $contract_id AND status != 'paid' ORDER BY installment_number ASC LIMIT 1");
            if ($sRes) $schRow = $sRes->fetch_assoc();
        }

        if ($schRow) {
            $target_sch_id = (int)$schRow['id'];
            $target_booking_id = (int)$schRow['contract_id'];
            $target_user_id = (int)$schRow['user_id'];
            $target_inst_num = (int)$schRow['installment_number'];

            // Update schedule
            $upSch = $conn->prepare("UPDATE payment_schedules 
                SET status = 'paid', paid_amount = ?, paid_at = ?, payment_method = ?, transaction_reference = ? 
                WHERE id = ?");
            if ($upSch) {
                $upSch->bind_param('dsssi', $amount_paid, $payment_date, $payment_method, $reference_no, $target_sch_id);
                $upSch->execute();
                $upSch->close();
            }

            // Sync legacy booking_payment_schedule if present
            $chkLegacy = $conn->query("SHOW TABLES LIKE 'booking_payment_schedule'");
            if ($chkLegacy && $chkLegacy->num_rows > 0) {
                $upLeg = $conn->prepare("UPDATE booking_payment_schedule 
                    SET status = 'PAID', amount_paid = ?, paid_at = ?, payment_method = ?, transaction_reference = ? 
                    WHERE booking_id = ? AND installment_number = ?");
                if ($upLeg) {
                    $upLeg->bind_param('dsssii', $amount_paid, $payment_date, $payment_method, $reference_no, $target_booking_id, $target_inst_num);
                    $upLeg->execute();
                    $upLeg->close();
                }
            }

            // Create/Record in payment_receipts
            $receipt_id = 0;
            $chkPr = $conn->prepare("SELECT id FROM payment_receipts WHERE booking_id = ? AND installment_number = ? LIMIT 1");
            if ($chkPr) {
                $chkPr->bind_param('ii', $target_booking_id, $target_inst_num);
                $chkPr->execute();
                $rRes = $chkPr->get_result();
                if ($rRes && $rRow = $rRes->fetch_assoc()) {
                    $receipt_id = (int)$rRow['id'];
                }
                $chkPr->close();
            }

            if ($receipt_id > 0) {
                $upPr = $conn->prepare("UPDATE payment_receipts 
                    SET amount_paid = ?, payment_method = ?, transaction_reference = ?, or_number = ?, 
                        payment_date = ?, payment_status = 'PAID', verified_by = ?, verified_at = NOW(), notes = ? 
                    WHERE id = ?");
                if ($upPr) {
                    $upPr->bind_param('dssssisi', $amount_paid, $payment_method, $reference_no, $or_number, $payment_date, $session_uid, $notes, $receipt_id);
                    $upPr->execute();
                    $upPr->close();
                }
            } else {
                $insPr = $conn->prepare("INSERT INTO payment_receipts 
                    (booking_id, user_id, payment_schedule_id, installment_number, amount_paid, payment_method, transaction_reference, or_number, payment_date, payment_status, verified_by, recorded_by, verified_at, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PAID', ?, ?, NOW(), ?)");
                if ($insPr) {
                    $insPr->bind_param('iiiidssssiis', $target_booking_id, $target_user_id, $target_sch_id, $target_inst_num, $amount_paid, $payment_method, $reference_no, $or_number, $payment_date, $session_uid, $session_uid, $notes);
                    $insPr->execute();
                    $receipt_id = $insPr->insert_id;
                    $insPr->close();

                    if ($receipt_id > 0 && function_exists('generate_official_receipt_number')) {
                        $rcptNo = generate_official_receipt_number($receipt_id, $payment_date);
                        $conn->query("UPDATE payment_receipts SET receipt_number = '{$conn->real_escape_string($rcptNo)}' WHERE id = $receipt_id");
                    }
                }
            }

            // Recalculate parent booking balance
            if (function_exists('recalculate_booking_payment_totals')) {
                recalculate_booking_payment_totals($target_booking_id, $conn);
            }

            // Audit log
            $logMsg = "Admin recorded installment #{$target_inst_num} payment of ₱" . number_format($amount_paid, 2) . " for Booking #{$target_booking_id} (Account User #{$target_user_id})";
            $lStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('RECORD_MONTHLY_PAYMENT', ?, ?, NOW())");
            if ($lStmt) {
                $lStmt->bind_param('si', $logMsg, $session_uid);
                $lStmt->execute();
                $lStmt->close();
            }

            echo json_encode(['success' => true, 'message' => "Installment #{$target_inst_num} successfully marked as PAID."]);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Schedule record not found.']);
            exit;
        }
    }

    // Admin: Send Payment Reminder for Specific Installment
    if ($post_action === 'send_payment_reminder') {
        if (!$isAdmin) {
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => 'Unauthorized action.']));
        }

        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        if ($schedule_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid schedule ID.']);
            exit;
        }

        $sRes = $conn->query("SELECT ps.*, b.customer_name, b.customer_email, u.email as user_email_addr, u.first_name, u.last_name 
            FROM payment_schedules ps 
            LEFT JOIN tbl_bookings b ON b.id = ps.contract_id 
            LEFT JOIN tbl_user u ON u.user_id = ps.user_id 
            WHERE ps.id = $schedule_id LIMIT 1");
        
        if ($sRes && ($sRow = $sRes->fetch_assoc())) {
            $destEmail = !empty($sRow['customer_email']) ? $sRow['customer_email'] : (!empty($sRow['user_email_addr']) ? $sRow['user_email_addr'] : $sRow['user_email']);
            $destName = trim(($sRow['first_name'] ?? '') . ' ' . ($sRow['last_name'] ?? '')) ?: ($sRow['customer_name'] ?: 'Customer');
            $instNum = (int)$sRow['installment_number'];
            $dueAmount = (float)$sRow['monthly_amount'];
            $dueDate = $sRow['due_date'];
            $bookingId = (int)$sRow['contract_id'];
            $targetUid = (int)$sRow['user_id'];

            // Log reminder in payment_reminder_history if table exists
            $chkPrh = $conn->query("SHOW TABLES LIKE 'payment_reminder_history'");
            if ($chkPrh && $chkPrh->num_rows > 0) {
                $insPrh = $conn->prepare("INSERT INTO payment_reminder_history (booking_id, user_id, schedule_id, recipient_email, recipient_name, installment_number, amount_due, due_date, status, sent_by, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'SENT', ?, NOW())");
                if ($insPrh) {
                    $insPrh->bind_param('iiissidsi', $bookingId, $targetUid, $schedule_id, $destEmail, $destName, $instNum, $dueAmount, $dueDate, $session_uid);
                    $insPrh->execute();
                    $insPrh->close();
                }
            }

            // Update reminder timestamp on schedule
            $conn->query("UPDATE payment_schedules SET reminder_sent_at = NOW() WHERE id = $schedule_id");

            // Audit log
            $logMsg = "Payment reminder sent to {$destEmail} ({$destName}) for Booking #{$bookingId} Installment #{$instNum} (Due: {$dueDate})";
            $lStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('PAYMENT_REMINDER_SENT', ?, ?, NOW())");
            if ($lStmt) {
                $lStmt->bind_param('si', $logMsg, $session_uid);
                $lStmt->execute();
                $lStmt->close();
            }

            echo json_encode(['success' => true, 'message' => "Payment reminder recorded and queued for {$destName} ({$destEmail})."]);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Installment schedule not found.']);
            exit;
        }
    }
}

// -------------------------------------------------------------------------
// 3. Handle CSV Export for Specific Account
// -------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_account_csv') {
    $export_key = trim((string)($_GET['account_key'] ?? ($_GET['user_id'] ?? ($_GET['booking_id'] ?? ''))));
    if (!$isAdmin && $export_key !== 'u_' . $session_uid && $export_key !== (string)$session_uid) {
        $export_key = 'u_' . $session_uid;
    }

    if (empty($export_key)) {
        die("Invalid account parameter for export.");
    }

    // Resolve customer info
    $account_name = "Account_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', $export_key);
    $account_email = "";
    $account_num = "ACC-" . $export_key;

    if (str_starts_with($export_key, 'u_') || is_numeric($export_key)) {
        $uid = (int)str_replace('u_', '', $export_key);
        $uRes = $conn->query("SELECT first_name, last_name, email, account_number FROM tbl_user WHERE user_id = $uid LIMIT 1");
        if ($uRes && ($uRow = $uRes->fetch_assoc())) {
            $account_name = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? '')) ?: $account_name;
            $account_email = $uRow['email'] ?? '';
            $account_num = !empty($uRow['account_number']) ? $uRow['account_number'] : ('ACC-' . str_pad($uid, 5, '0', STR_PAD_LEFT));
        }
    } elseif (str_starts_with($export_key, 'b_')) {
        $bid = (int)str_replace('b_', '', $export_key);
        $bRes = $conn->query("SELECT customer_name, full_name, customer_email, email FROM tbl_bookings WHERE id = $bid LIMIT 1");
        if ($bRes && ($bRow = $bRes->fetch_assoc())) {
            $account_name = trim(($bRow['customer_name'] ?? '') ?: ($bRow['full_name'] ?? '')) ?: $account_name;
            $account_email = ($bRow['customer_email'] ?? '') ?: ($bRow['email'] ?? '');
            $account_num = 'ACC-' . str_pad($bid, 5, '0', STR_PAD_LEFT);
        }
    }

    $clean_filename = "Monthly_Payments_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', $account_name) . "_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $clean_filename . '"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    // CSV Meta headers
    fputcsv($output, ['Garden of Remembrance Memorial Park - Statement of Monthly Payments']);
    fputcsv($output, ['Account Name:', $account_name]);
    fputcsv($output, ['Account Email:', $account_email]);
    fputcsv($output, ['Account Number:', $account_num]);
    fputcsv($output, ['Export Date:', date('Y-m-d H:i:s')]);
    fputcsv($output, []); // Blank separator row

    // Table Column Headers
    fputcsv($output, [
        'Installment #',
        'Contract / Booking ID',
        'Section & Plot',
        'Scheduled Due Date',
        'Monthly Amount Due (PHP)',
        'Amount Paid (PHP)',
        'Remaining Balance (PHP)',
        'Status',
        'Date Paid',
        'Payment Method',
        'Reference / GCash No.',
        'Receipt / OR Number'
    ]);

    $expWhere = "1=1";
    if (str_starts_with($export_key, 'u_') || is_numeric($export_key)) {
        $uid = (int)str_replace('u_', '', $export_key);
        $expWhere = "ps.user_id = $uid OR ps.contract_id IN (SELECT id FROM tbl_bookings WHERE user_id = $uid)";
    } elseif (str_starts_with($export_key, 'b_')) {
        $bid = (int)str_replace('b_', '', $export_key);
        $expWhere = "ps.contract_id = $bid";
    }

    $expSql = "SELECT ps.*, 
                      b.section, b.plot, b.section_plot, b.total_amount as booking_total,
                      pr.receipt_number, pr.or_number, pr.payment_date as receipt_paid_date, pr.payment_method as pr_method, pr.transaction_reference as pr_ref
               FROM payment_schedules ps
               LEFT JOIN tbl_bookings b ON b.id = ps.contract_id
               LEFT JOIN payment_receipts pr ON (pr.booking_id = ps.contract_id AND pr.installment_number = ps.installment_number)
               WHERE $expWhere
               ORDER BY ps.contract_id ASC, ps.installment_number ASC";
    $expRes = $conn->query($expSql);

    $total_sched_amt = 0;
    $total_sched_paid = 0;

    if ($expRes) {
        while ($r = $expRes->fetch_assoc()) {
            $plotLoc = trim(($r['section'] ? 'Section ' . $r['section'] : '') . ($r['plot'] ? ' Plot ' . $r['plot'] : ($r['section_plot'] ?: '—')));
            $statusStr = strtoupper($r['status']);
            $paidDate = !empty($r['paid_at']) ? $r['paid_at'] : (!empty($r['receipt_paid_date']) ? $r['receipt_paid_date'] : '—');
            $refNum = !empty($r['transaction_reference']) ? $r['transaction_reference'] : (!empty($r['pr_ref']) ? $r['pr_ref'] : '—');
            $rcptNum = !empty($r['or_number']) ? $r['or_number'] : (!empty($r['receipt_number']) ? $r['receipt_number'] : '—');
            $payMethod = !empty($r['payment_method']) ? $r['payment_method'] : (!empty($r['pr_method']) ? $r['pr_method'] : '—');

            $total_sched_amt += (float)$r['monthly_amount'];
            $total_sched_paid += (float)$r['paid_amount'];

            fputcsv($output, [
                'Installment #' . $r['installment_number'],
                'Booking #' . $r['contract_id'],
                $plotLoc,
                $r['due_date'],
                number_format((float)$r['monthly_amount'], 2, '.', ''),
                number_format((float)$r['paid_amount'], 2, '.', ''),
                number_format((float)$r['remaining_balance'], 2, '.', ''),
                $statusStr,
                $paidDate,
                $payMethod,
                $refNum,
                $rcptNum
            ]);
        }
    }

    fputcsv($output, []);
    fputcsv($output, ['TOTALS', '', '', '', number_format($total_sched_amt, 2, '.', ''), number_format($total_sched_paid, 2, '.', ''), number_format(max(0, $total_sched_amt - $total_sched_paid), 2, '.', '')]);

    fclose($output);
    exit;
}

// -------------------------------------------------------------------------
// 4. Fetch All Customer Accounts for Directory / Switcher (Admin View)
// -------------------------------------------------------------------------
$accounts_directory = [];
$accounts_with_installments = 0;
$grand_total_monthly_collected = 0.0;
$grand_total_monthly_receivable = 0.0;

// Ensure tables exist
if (function_exists('ensure_installment_tables')) {
    ensure_installment_tables($conn);
}

// 4.1 Query all bookings from tbl_bookings
$all_bookings = [];
$bSql = "SELECT b.id, b.user_id, b.customer_name, b.full_name, b.customer_email, b.email, b.contact_number,
                b.section, b.plot, b.section_plot, b.payment_plan, b.payment_term, b.total_amount, b.remaining_balance,
                b.downpayment, b.amount_paid, b.created_at, b.status
         FROM tbl_bookings b
         ORDER BY b.id DESC";
$bRes = $conn->query($bSql);
if ($bRes) {
    while ($bRow = $bRes->fetch_assoc()) {
        $all_bookings[] = $bRow;
    }
}

// 4.2 Auto-generate payment schedules for any installment booking that doesn't have schedules yet
if (function_exists('generate_contract_payment_schedule')) {
    foreach ($all_bookings as $bRow) {
        $isInst = (strtolower(trim((string)($bRow['payment_plan'] ?? ''))) === 'installment' || (int)($bRow['payment_term'] ?? 0) > 0);
        if ($isInst && (int)$bRow['id'] > 0) {
            $bId = (int)$bRow['id'];
            $chkPs = $conn->query("SELECT COUNT(*) as c FROM payment_schedules WHERE contract_id = $bId");
            $hasPs = ($chkPs && (int)($chkPs->fetch_assoc()['c'] ?? 0) > 0);
            if (!$hasPs) {
                @generate_contract_payment_schedule($bId, $conn, ((float)($bRow['downpayment'] ?? 0) > 0 || (float)($bRow['amount_paid'] ?? 0) > 0));
            }
        }
    }
}

// 4.3 Query all payment schedules to get totals per contract
$schedules_by_contract = [];
$psRes = $conn->query("SELECT * FROM payment_schedules ORDER BY contract_id ASC, installment_number ASC");
if ($psRes) {
    while ($ps = $psRes->fetch_assoc()) {
        $cId = (int)$ps['contract_id'];
        if (!isset($schedules_by_contract[$cId])) {
            $schedules_by_contract[$cId] = [];
        }
        $schedules_by_contract[$cId][] = $ps;
    }
}

// Also check legacy booking_payment_schedule if payment_schedules is empty for any booking
$bpsChk = $conn->query("SHOW TABLES LIKE 'booking_payment_schedule'");
if ($bpsChk && $bpsChk->num_rows > 0) {
    $bpsQ = $conn->query("SELECT * FROM booking_payment_schedule ORDER BY booking_id ASC, installment_number ASC");
    if ($bpsQ) {
        while ($bps = $bpsQ->fetch_assoc()) {
            $bId = (int)$bps['booking_id'];
            if (empty($schedules_by_contract[$bId])) {
                $schedules_by_contract[$bId][] = [
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

// 4.4 Query registered users from tbl_user
$registered_users = [];
$uRes = $conn->query("SELECT user_id, first_name, middle_name, last_name, suffix, email, phone_no, account_number, role, profile_picture, created_at FROM tbl_user ORDER BY user_id ASC");
if ($uRes) {
    while ($u = $uRes->fetch_assoc()) {
        $registered_users[(int)$u['user_id']] = $u;
    }
}

// 4.5 Build Unified Customer Accounts Directory
// Group bookings by customer identity:
// - If user_id > 0 and user is in tbl_user: group by user_id -> key 'u_{user_id}'
// - Otherwise, group by customer name + email -> key 'b_{id}' or 'cust_{hash}'
$customer_groups = [];

foreach ($all_bookings as $b) {
    $bId = (int)$b['id'];
    $uId = (int)($b['user_id'] ?? 0);
    $cName = trim(($b['customer_name'] ?? '') ?: ($b['full_name'] ?? ''));
    $cEmail = strtolower(trim(($b['customer_email'] ?? '') ?: ($b['email'] ?? '')));
    $cPhone = trim($b['contact_number'] ?? '');
    
    // Check if this booking is an installment contract or has schedules
    $hasSchedules = !empty($schedules_by_contract[$bId]);
    $isInstallment = (strtolower(trim((string)($b['payment_plan'] ?? ''))) === 'installment' || (int)($b['payment_term'] ?? 0) > 0 || $hasSchedules);

    // Determine group key with intelligent user and name matching
    $matchedUser = null;
    if ($uId > 0 && isset($registered_users[$uId])) {
        $uFull = trim(($registered_users[$uId]['first_name'] ?? '') . ' ' . ($registered_users[$uId]['last_name'] ?? ''));
        if (empty($cName) || strcasecmp($uFull, $cName) === 0) {
            $matchedUser = $registered_users[$uId];
        }
    }
    if (!$matchedUser && !empty($cEmail)) {
        foreach ($registered_users as $ru) {
            if (strcasecmp(trim($ru['email'] ?? ''), $cEmail) === 0) {
                $matchedUser = $ru;
                break;
            }
        }
    }
    if (!$matchedUser && !empty($cName)) {
        foreach ($registered_users as $ru) {
            $ruFull = trim(($ru['first_name'] ?? '') . ' ' . ($ru['last_name'] ?? ''));
            if (strcasecmp($ruFull, $cName) === 0) {
                $matchedUser = $ru;
                break;
            }
        }
    }

    if ($matchedUser) {
        $groupKey = 'u_' . (int)$matchedUser['user_id'];
        $uId = (int)$matchedUser['user_id'];
    } elseif (!empty($cName)) {
        $normName = strtolower(preg_replace('/\s+/', ' ', trim($cName)));
        $groupKey = 'cust_' . substr(md5($normName), 0, 10);
    } else {
        $groupKey = 'b_' . $bId;
    }

    if (!isset($customer_groups[$groupKey])) {
        $customer_groups[$groupKey] = [
            'key' => $groupKey,
            'user_id' => $matchedUser ? (int)$matchedUser['user_id'] : (($uId > 0 && isset($registered_users[$uId])) ? $uId : 0),
            'primary_booking_id' => $bId,
            'name' => $matchedUser ? trim(($matchedUser['first_name'] ?? '') . ' ' . ($matchedUser['last_name'] ?? '')) : $cName,
            'email' => !empty($cEmail) ? $cEmail : ($matchedUser['email'] ?? ''),
            'phone' => !empty($cPhone) ? $cPhone : ($matchedUser['phone_no'] ?? ''),
            'section' => $b['section'] ?? '',
            'plot' => $b['plot'] ?? ($b['section_plot'] ?? ''),
            'bookings' => [],
            'created_at' => $b['created_at'] ?? date('Y-m-d H:i:s'),
            'is_installment' => false
        ];
    } else {
        // Consolidate email / phone if available in subsequent bookings
        if (empty($customer_groups[$groupKey]['email']) && !empty($cEmail)) {
            $customer_groups[$groupKey]['email'] = $cEmail;
        }
        if (empty($customer_groups[$groupKey]['phone']) && !empty($cPhone)) {
            $customer_groups[$groupKey]['phone'] = $cPhone;
        }
    }

    if ($isInstallment) {
        $customer_groups[$groupKey]['is_installment'] = true;
    }
    $customer_groups[$groupKey]['bookings'][] = $b;
}

// Also add any registered users in tbl_user who didn't have bookings yet
foreach ($registered_users as $uId => $u) {
    $groupKey = 'u_' . $uId;
    if (!isset($customer_groups[$groupKey])) {
        $uFullName = trim(($u['first_name'] ?? '') . ' ' . ($u['middle_name'] ?? '') . ' ' . ($u['last_name'] ?? '') . ' ' . ($u['suffix'] ?? ''));
        $customer_groups[$groupKey] = [
            'key' => $groupKey,
            'user_id' => $uId,
            'primary_booking_id' => 0,
            'name' => $uFullName ?: ('User #' . $uId),
            'email' => $u['email'] ?? '',
            'phone' => $u['phone_no'] ?? '',
            'section' => '',
            'plot' => '',
            'bookings' => [],
            'created_at' => $u['created_at'] ?? date('Y-m-d H:i:s'),
            'is_installment' => false
        ];
    }
}

// 4.6 Calculate aggregated metrics for each account in $customer_groups
$todayDate = date('Y-m-d');

foreach ($customer_groups as $gKey => $grp) {
    $uId = $grp['user_id'];
    $uObj = ($uId > 0 && isset($registered_users[$uId])) ? $registered_users[$uId] : null;

    $dispName = $grp['name'];
    if ($uObj) {
        $uFullName = trim(($uObj['first_name'] ?? '') . ' ' . ($uObj['middle_name'] ?? '') . ' ' . ($uObj['last_name'] ?? '') . ' ' . ($uObj['suffix'] ?? ''));
        if (!empty($uFullName)) $dispName = $uFullName;
    }
    if (empty($dispName)) $dispName = 'Customer ' . ($grp['primary_booking_id'] ? '#' . $grp['primary_booking_id'] : '');

    $dispEmail = !empty($grp['email']) ? $grp['email'] : ($uObj['email'] ?? '');
    $dispPhone = !empty($grp['phone']) ? $grp['phone'] : ($uObj['phone_no'] ?? '');
    $dispAcctNo = !empty($uObj['account_number']) ? $uObj['account_number'] : ('ACC-' . str_pad($grp['primary_booking_id'] ?: $uId, 5, '0', STR_PAD_LEFT));
    $profilePic = !empty($uObj['profile_picture']) ? $uObj['profile_picture'] : 'uploads/profile_pictures/default_avatar.png';

    $contractIds = [];
    $totalScheds = 0;
    $totalPaid = 0.0;
    $totalMonthlySum = 0.0;
    $overdueCount = 0;
    $nextDueDate = null;
    $plotDesc = '';

    foreach ($grp['bookings'] as $bk) {
        $cId = (int)$bk['id'];
        $contractIds[] = $cId;
        if (empty($plotDesc)) {
            $plotDesc = trim(($bk['section'] ? 'Sec ' . $bk['section'] : '') . ($bk['plot'] ? ' Plot ' . $bk['plot'] : ($bk['section_plot'] ?? '')));
        }

        // Schedules for this contract
        if (!empty($schedules_by_contract[$cId])) {
            foreach ($schedules_by_contract[$cId] as $s) {
                $totalScheds++;
                $mDue = (float)$s['monthly_amount'];
                $pAmt = (float)$s['paid_amount'];
                $sStatus = strtolower(trim((string)$s['status']));
                $dDate = $s['due_date'];

                $totalMonthlySum += $mDue;
                if ($sStatus === 'paid') {
                    $totalPaid += ($pAmt > 0 ? $pAmt : $mDue);
                } else {
                    if ($dDate < $todayDate || $sStatus === 'overdue') {
                        $overdueCount++;
                    }
                    if ($dDate >= $todayDate && ($nextDueDate === null || $dDate < $nextDueDate)) {
                        $nextDueDate = $dDate;
                    }
                }
            }
        } elseif ((float)($bk['total_amount'] ?? 0) > 0) {
            $totalMonthlySum += (float)$bk['total_amount'];
            $totalPaid += (float)($bk['amount_paid'] ?? 0);
        }
    }

    $remBal = max(0.0, $totalMonthlySum - $totalPaid);
    $hasInstallmentData = ($grp['is_installment'] || $totalScheds > 0);

    if ($hasInstallmentData) {
        $accounts_with_installments++;
        $grand_total_monthly_collected += $totalPaid;
        $grand_total_monthly_receivable += $totalMonthlySum;
    }

    $accounts_directory[$gKey] = [
        'key' => $gKey,
        'user_id' => $uId,
        'primary_booking_id' => $grp['primary_booking_id'],
        'contract_ids' => $contractIds,
        'full_name' => $dispName,
        'email' => $dispEmail,
        'phone_no' => $dispPhone,
        'account_number' => $dispAcctNo,
        'role' => $uObj['role'] ?? 'CUSTOMER',
        'profile_picture' => $profilePic,
        'plot_desc' => $plotDesc,
        'contracts_count' => count($contractIds),
        'schedules_count' => $totalScheds,
        'total_paid' => $totalPaid,
        'total_monthly_sum' => $totalMonthlySum,
        'remaining_balance' => $remBal,
        'overdue_count' => $overdueCount,
        'next_due_date' => $nextDueDate,
        'has_installments' => $hasInstallmentData,
        'created_at' => $grp['created_at']
    ];
}

// Sort accounts strictly alphabetically (A to Z) by customer full name
uasort($accounts_directory, function($a, $b) {
    $nameA = trim($a['full_name'] ?? '');
    $nameB = trim($b['full_name'] ?? '');
    $cmp = strcasecmp($nameA, $nameB);
    if ($cmp !== 0) {
        return $cmp;
    }
    return ($a['primary_booking_id'] ?? 0) <=> ($b['primary_booking_id'] ?? 0);
});

// -------------------------------------------------------------------------
// 5. Determine Currently Selected Account
// -------------------------------------------------------------------------
$selected_account_key = '';

if (!$isAdmin) {
    // Non-admin users are strictly confined to their own account
    $selected_account_key = 'u_' . $session_uid;
    if (!isset($accounts_directory[$selected_account_key])) {
        // Fallback search by email
        $uChk = $conn->query("SELECT email FROM tbl_user WHERE user_id = $session_uid LIMIT 1");
        $uMail = ($uChk && $r = $uChk->fetch_assoc()) ? strtolower(trim($r['email'])) : '';
        foreach ($accounts_directory as $k => $acc) {
            if ($acc['user_id'] === $session_uid || (!empty($uMail) && strtolower(trim($acc['email'])) === $uMail)) {
                $selected_account_key = $k;
                break;
            }
        }
    }
} else {
    // Admin switcher
    $reqKey = trim((string)($_GET['account_key'] ?? ''));
    $reqUid = (int)($_GET['user_id'] ?? 0);
    $reqBid = (int)($_GET['booking_id'] ?? ($_GET['id'] ?? ($_GET['account_id'] ?? 0)));

    if (!empty($reqKey) && isset($accounts_directory[$reqKey])) {
        $selected_account_key = $reqKey;
    } elseif ($reqUid > 0 && isset($accounts_directory['u_' . $reqUid])) {
        $selected_account_key = 'u_' . $reqUid;
    } elseif ($reqBid > 0) {
        // Search by booking ID
        foreach ($accounts_directory as $k => $acc) {
            if (in_array($reqBid, $acc['contract_ids']) || $acc['primary_booking_id'] === $reqBid) {
                $selected_account_key = $k;
                break;
            }
        }
        if (empty($selected_account_key) && isset($accounts_directory['b_' . $reqBid])) {
            $selected_account_key = 'b_' . $reqBid;
        }
    } elseif (!empty($_GET['account_key'])) {
        $selected_account_key = $_GET['account_key'];
    }

    // Default selection: If none chosen, pick the first account with installments
    if (empty($selected_account_key) || !isset($accounts_directory[$selected_account_key])) {
        foreach ($accounts_directory as $k => $acc) {
            if ($acc['has_installments']) {
                $selected_account_key = $k;
                break;
            }
        }
        if (empty($selected_account_key) && !empty($accounts_directory)) {
            $selected_account_key = array_key_first($accounts_directory);
        }
    }
}

$selected_account = $accounts_directory[$selected_account_key] ?? null;
$selected_user_id = $selected_account['user_id'] ?? 0;

// -------------------------------------------------------------------------
// 6. Fetch Complete Profile & Financial Records for the Selected Account
// -------------------------------------------------------------------------
$account_user = null;
$account_contracts = [];
$account_schedules = [];
$account_receipts = [];

// Financial Aggregates
$acc_metrics = [
    'total_contracts' => 0,
    'total_contract_value' => 0.0,
    'total_downpayment_paid' => 0.0,
    'total_monthly_amortized' => 0.0,
    'total_monthly_paid' => 0.0,
    'total_remaining_balance' => 0.0,
    'total_installments_count' => 0,
    'paid_installments_count' => 0,
    'pending_installments_count' => 0,
    'overdue_installments_count' => 0,
    'next_due_date' => null,
    'next_due_amount' => 0.0,
    'next_due_installment_num' => 0,
    'next_due_booking_id' => 0,
    'progress_percent' => 0.0
];

if ($selected_account) {
    $selUid = (int)$selected_account['user_id'];
    $account_user = [
        'user_id' => $selUid,
        'full_name' => $selected_account['full_name'],
        'email' => $selected_account['email'],
        'phone_no' => $selected_account['phone_no'],
        'account_number' => $selected_account['account_number'],
        'profile_picture' => $selected_account['profile_picture'],
        'role' => $selected_account['role'],
        'created_at' => $selected_account['created_at']
    ];

    if ($selUid > 0 && isset($registered_users[$selUid])) {
        $account_user = array_merge($account_user, $registered_users[$selUid]);
        $account_user['full_name'] = $selected_account['full_name'];
        $account_user['account_number'] = $selected_account['account_number'];
    }

    // 6.2 Contracts / Bookings for this Account
    $targetContracts = $selected_account['contract_ids'];
    if (empty($targetContracts) && $selected_account['primary_booking_id'] > 0) {
        $targetContracts = [$selected_account['primary_booking_id']];
    }

    if (!empty($targetContracts)) {
        $inBids = implode(',', array_map('intval', $targetContracts));
        $bkQ = $conn->query("
            SELECT b.*, p.lawn_type, p.price as official_plot_price
            FROM tbl_bookings b
            LEFT JOIN tbl_plots p ON (p.section = b.section AND p.plot_x = SUBSTRING_INDEX(b.plot, '-', 1) AND p.plot_y = SUBSTRING_INDEX(b.plot, '-', -1))
            WHERE b.id IN ($inBids)
            ORDER BY b.id DESC
        ");
        if ($bkQ) {
            while ($bRow = $bkQ->fetch_assoc()) {
                $account_contracts[(int)$bRow['id']] = $bRow;
                $acc_metrics['total_contracts']++;
                $acc_metrics['total_contract_value'] += (float)$bRow['total_amount'];
                $acc_metrics['total_downpayment_paid'] += (float)($bRow['downpayment'] ?? 0);
            }
        }
    }

    // 6.3 Payment Receipts for this Account
    $contractKeys = array_keys($account_contracts);
    $inBkReceipts = !empty($contractKeys) ? implode(',', $contractKeys) : '0';
    $rcptSql = "
        SELECT pr.*, u_ver.first_name as verifier_first, u_ver.last_name as verifier_last 
        FROM payment_receipts pr
        LEFT JOIN tbl_user u_ver ON u_ver.user_id = pr.verified_by
        WHERE pr.booking_id IN ($inBkReceipts) " . ($selUid > 0 ? " OR pr.user_id = $selUid" : "") . "
        ORDER BY pr.payment_date DESC, pr.id DESC
    ";
    $rcptRes = $conn->query($rcptSql);
    if ($rcptRes) {
        while ($pr = $rcptRes->fetch_assoc()) {
            $key = $pr['booking_id'] . '_' . $pr['installment_number'];
            $account_receipts[$key] = $pr;
        }
    }

    // 6.4 Monthly Payment Schedules for this Account
    $inContractSql = !empty($contractKeys) ? implode(',', $contractKeys) : '0';
    $schSql = "
        SELECT ps.*, 
               b.section, b.plot, b.section_plot, b.service_type, b.payment_plan, b.payment_term, b.total_amount as booking_total_amount, b.remaining_balance as booking_remaining_balance
        FROM payment_schedules ps
        LEFT JOIN tbl_bookings b ON b.id = ps.contract_id
        WHERE ps.contract_id IN ($inContractSql) " . ($selUid > 0 ? " OR ps.user_id = $selUid" : "") . "
        ORDER BY ps.contract_id ASC, ps.installment_number ASC
    ";
    $schRes = $conn->query($schSql);
    $todayStr = date('Y-m-d');

    if ($schRes && $schRes->num_rows > 0) {
        while ($sch = $schRes->fetch_assoc()) {
            $instNum = (int)$sch['installment_number'];
            $bookingId = (int)$sch['contract_id'];
            $rKey = $bookingId . '_' . $instNum;

            $receipt = $account_receipts[$rKey] ?? null;
            $sch['receipt_number'] = $receipt['receipt_number'] ?? '';
            $sch['or_number'] = $receipt['or_number'] ?? '';
            $sch['proof_filename'] = $receipt['proof_filename'] ?? '';
            $sch['receipt_status'] = $receipt['payment_status'] ?? '';
            $sch['receipt_id'] = $receipt['id'] ?? 0;
            $sch['receipt_date'] = $receipt['payment_date'] ?? null;
            $sch['notes'] = $receipt['notes'] ?? '';

            $dueDate = $sch['due_date'];
            $isPaid = (strtolower($sch['status']) === 'paid');
            $isPendingVerification = (strtolower($sch['status']) === 'pending_verification' || (!empty($receipt['payment_status']) && strtoupper($receipt['payment_status']) === 'FOR VERIFICATION'));
            $isOverdue = (!$isPaid && !$isPendingVerification && ($dueDate < $todayStr || strtolower($sch['status']) === 'overdue'));

            if ($isPaid) {
                $sch['computed_status'] = 'PAID';
                $acc_metrics['paid_installments_count']++;
                $acc_metrics['total_monthly_paid'] += (float)$sch['paid_amount'];
            } elseif ($isPendingVerification) {
                $sch['computed_status'] = 'PENDING VERIFICATION';
                $acc_metrics['pending_installments_count']++;
            } elseif ($isOverdue) {
                $sch['computed_status'] = 'OVERDUE';
                $acc_metrics['overdue_installments_count']++;
                $daysOverdue = (int)round((strtotime($todayStr) - strtotime($dueDate)) / 86400);
                $sch['days_overdue'] = max(1, $daysOverdue);
            } else {
                $sch['computed_status'] = 'PENDING';
                $acc_metrics['pending_installments_count']++;
                $daysLeft = (int)round((strtotime($dueDate) - strtotime($todayStr)) / 86400);
                $sch['days_left'] = max(0, $daysLeft);
            }

            $acc_metrics['total_installments_count']++;
            $acc_metrics['total_monthly_amortized'] += (float)$sch['monthly_amount'];

            if (!$isPaid && empty($acc_metrics['next_due_date']) && $dueDate >= $todayStr) {
                $acc_metrics['next_due_date'] = $dueDate;
                $acc_metrics['next_due_amount'] = (float)$sch['monthly_amount'];
                $acc_metrics['next_due_installment_num'] = $instNum;
                $acc_metrics['next_due_booking_id'] = $bookingId;
            }

            $account_schedules[] = $sch;
        }
    }

    // Fallback: If no payment_schedules found, check legacy booking_payment_schedule
    if (empty($account_schedules) && !empty($contractKeys)) {
        $bpsRes = $conn->query("SELECT bps.*, b.section, b.plot, b.section_plot, b.service_type, b.payment_plan, b.payment_term, b.total_amount as booking_total_amount, b.remaining_balance as booking_remaining_balance
                                FROM booking_payment_schedule bps
                                LEFT JOIN tbl_bookings b ON b.id = bps.booking_id
                                WHERE bps.booking_id IN ($inContractSql)
                                ORDER BY bps.booking_id ASC, bps.installment_number ASC");
        if ($bpsRes) {
            while ($bps = $bpsRes->fetch_assoc()) {
                $instNum = (int)$bps['installment_number'];
                $bookingId = (int)$bps['booking_id'];
                $rKey = $bookingId . '_' . $instNum;
                $receipt = $account_receipts[$rKey] ?? null;

                $dueDate = $bps['due_date'];
                $isPaid = (strtoupper($bps['status']) === 'PAID');
                $isPendingVerification = (!empty($receipt['payment_status']) && strtoupper($receipt['payment_status']) === 'FOR VERIFICATION');
                $isOverdue = (!$isPaid && !$isPendingVerification && ($dueDate < $todayStr || strtoupper($bps['status']) === 'OVERDUE'));

                $statusComp = $isPaid ? 'PAID' : ($isPendingVerification ? 'PENDING VERIFICATION' : ($isOverdue ? 'OVERDUE' : 'PENDING'));

                if ($isPaid) {
                    $acc_metrics['paid_installments_count']++;
                    $acc_metrics['total_monthly_paid'] += (float)$bps['amount_paid'];
                } elseif ($isOverdue) {
                    $acc_metrics['overdue_installments_count']++;
                } else {
                    $acc_metrics['pending_installments_count']++;
                }
                $acc_metrics['total_installments_count']++;
                $acc_metrics['total_monthly_amortized'] += (float)$bps['amount_due'];

                $account_schedules[] = [
                    'id' => (int)$bps['id'],
                    'contract_id' => $bookingId,
                    'installment_number' => $instNum,
                    'monthly_amount' => (float)$bps['amount_due'],
                    'paid_amount' => (float)$bps['amount_paid'],
                    'remaining_balance' => max(0, (float)$bps['amount_due'] - (float)$bps['amount_paid']),
                    'due_date' => $dueDate,
                    'status' => $bps['status'],
                    'computed_status' => $statusComp,
                    'paid_at' => $bps['paid_at'],
                    'payment_method' => $bps['payment_method'],
                    'transaction_reference' => $bps['transaction_reference'] ?: $bps['gcash_reference_number'],
                    'receipt_number' => $receipt['receipt_number'] ?? '',
                    'or_number' => $receipt['or_number'] ?? '',
                    'proof_filename' => $receipt['proof_filename'] ?? '',
                    'receipt_status' => $receipt['payment_status'] ?? '',
                    'receipt_id' => $receipt['id'] ?? 0,
                    'receipt_date' => $receipt['payment_date'] ?? null,
                    'notes' => $receipt['notes'] ?? '',
                    'section' => $bps['section'],
                    'plot' => $bps['plot'],
                    'section_plot' => $bps['section_plot']
                ];
            }
        }
    }

    if (empty($acc_metrics['next_due_date']) && !empty($account_schedules)) {
        foreach ($account_schedules as $s) {
            if ($s['computed_status'] !== 'PAID') {
                $acc_metrics['next_due_date'] = $s['due_date'];
                $acc_metrics['next_due_amount'] = (float)$s['monthly_amount'];
                $acc_metrics['next_due_installment_num'] = (int)$s['installment_number'];
                $acc_metrics['next_due_booking_id'] = (int)$s['contract_id'];
                break;
            }
        }
    }

    $acc_metrics['total_remaining_balance'] = max(0.0, $acc_metrics['total_monthly_amortized'] - $acc_metrics['total_monthly_paid']);
    if ($acc_metrics['total_monthly_amortized'] > 0) {
        $acc_metrics['progress_percent'] = round(($acc_metrics['total_monthly_paid'] / $acc_metrics['total_monthly_amortized']) * 100, 1);
    }
}

// -------------------------------------------------------------------------
// 7. Filter Selected Contract (if specific contract tab clicked)
// -------------------------------------------------------------------------
$filter_contract_id = isset($_GET['contract_id']) ? (int)$_GET['contract_id'] : 0;
$displayed_schedules = $account_schedules;
if ($filter_contract_id > 0) {
    $displayed_schedules = array_filter($account_schedules, fn($s) => (int)$s['contract_id'] === $filter_contract_id);
}

// User Profile Picture
$profile_avatar = 'uploads/profile_pictures/default_avatar.png';
if (!empty($account_user['profile_picture']) && file_exists(__DIR__ . '/' . $account_user['profile_picture'])) {
    $profile_avatar = $account_user['profile_picture'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monthly Payment Records per Account - Garden of Remembrance</title>
    <!-- Stylesheets -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/admin_header.css">

    <style>
        :root {
            --brand: #7b1113;
            --brand-dark: #5a0c0e;
            --brand-light: #94171a;
            --brand-subtle: #fdf2f2;
            --accent-gold: #d97706;
            --bg-canvas: #f8fafc;
            --card-border: #e2e8f0;
            --text-dark: #0f172a;
            --text-muted: #64748b;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-canvas);
            color: var(--text-dark);
            min-height: 100vh;
        }

        .page-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem 1rem 3rem 1rem;
        }

        /* Hero Banner */
        .ledger-hero {
            background: linear-gradient(135deg, #7b1113 0%, #8e1518 50%, #4a080a 100%);
            color: #ffffff;
            border-radius: 16px;
            padding: 1.75rem 2rem;
            margin-bottom: 1.75rem;
            box-shadow: 0 10px 30px rgba(123, 17, 19, 0.22);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .ledger-hero::after {
            content: "";
            position: absolute;
            top: -50px;
            right: -50px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .hero-title {
            font-size: 1.65rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 0.35rem;
        }
        .hero-subtitle {
            font-size: 0.92rem;
            opacity: 0.9;
            max-width: 680px;
            margin-bottom: 0;
        }

        /* Account Selector Bar (Admin) */
        .account-selector-card {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid var(--card-border);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .account-select-wrap {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
            min-width: 280px;
        }
        .account-dropdown {
            font-size: 0.92rem;
            font-weight: 600;
            border-radius: 10px;
            border: 1.5px solid #cbd5e1;
            padding: 0.6rem 1rem;
            color: #1e293b;
            background-color: #fff;
            max-width: 480px;
        }
        .account-dropdown:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(123, 17, 19, 0.15);
        }

        /* Profile & Summary Header */
        .account-profile-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid var(--card-border);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 3px 12px rgba(0,0,0,0.04);
        }
        .profile-avatar-wrap {
            position: relative;
            width: 72px;
            height: 72px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid #ffffff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            flex-shrink: 0;
            background-color: #f1f5f9;
        }
        .profile-avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .account-name-disp {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }
        .account-meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: #f1f5f9;
            color: #475569;
            padding: 0.3rem 0.65rem;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
        }

        /* KPI Metric Cards */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .metric-card {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid var(--card-border);
            padding: 1.15rem 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            position: relative;
            overflow: hidden;
            cursor: pointer;
            user-select: none;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }
        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 22px rgba(123, 17, 19, 0.08);
            border-color: #fca5a5;
        }
        .metric-card:active {
            transform: translateY(0);
        }
        .ledger-row {
            cursor: pointer;
            transition: background-color 0.15s ease;
        }
        .ledger-row:hover {
            background-color: #fef2f2 !important;
        }
        .dir-row {
            cursor: pointer;
            transition: background-color 0.15s ease;
        }
        .dir-row:hover {
            background-color: #fee2e2 !important;
        }
        .metric-label {
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
            margin-bottom: 0.35rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .metric-value {
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--text-dark);
            letter-spacing: -0.02em;
        }
        .metric-hint {
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.35rem;
        }

        /* Contract Tabs */
        .contract-nav-tabs {
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 1.25rem;
            gap: 0.5rem;
            display: flex;
            overflow-x: auto;
            padding-bottom: 2px;
        }
        .contract-tab-link {
            border: none;
            background: transparent;
            color: #64748b;
            font-size: 0.88rem;
            font-weight: 600;
            padding: 0.6rem 1.1rem;
            border-radius: 10px 10px 0 0;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
            transition: all 0.15s ease;
            text-decoration: none;
        }
        .contract-tab-link:hover {
            color: var(--brand);
            background: rgba(123, 17, 19, 0.04);
        }
        .contract-tab-link.active {
            color: var(--brand);
            border-bottom-color: var(--brand);
            font-weight: 700;
            background: #ffffff;
        }

        /* Main Records Table Card */
        .records-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid var(--card-border);
            box-shadow: 0 3px 12px rgba(0,0,0,0.04);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .records-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            background: #ffffff;
        }
        .records-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text-dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Table styles */
        .table-responsive {
            overflow-x: auto;
        }
        .ledger-table {
            width: 100%;
            margin-bottom: 0;
            border-collapse: collapse;
        }
        .ledger-table thead th {
            background-color: #f8fafc;
            color: #475569;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        .ledger-table tbody td {
            padding: 0.95rem 1rem;
            font-size: 0.85rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            color: #1e293b;
        }
        .ledger-table tbody tr:hover {
            background-color: #fbfcfe;
        }

        /* Badges */
        .badge-status {
            padding: 0.35rem 0.65rem;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            white-space: nowrap;
        }
        .badge-status.paid {
            background-color: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .badge-status.pending-ver {
            background-color: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        .badge-status.overdue {
            background-color: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .badge-status.pending {
            background-color: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .badge-status.upcoming {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }

        /* Action Buttons */
        .btn-action {
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-action-primary {
            background-color: var(--brand);
            color: #ffffff;
            border: 1px solid var(--brand);
        }
        .btn-action-primary:hover {
            background-color: var(--brand-dark);
            color: #ffffff;
        }
        .btn-action-gcash {
            background-color: #007dfe;
            color: #ffffff;
            border: 1px solid #007dfe;
        }
        .btn-action-gcash:hover {
            background-color: #0066d6;
            color: #ffffff;
        }
        .btn-action-outline {
            background-color: transparent;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .btn-action-outline:hover {
            background-color: #f8fafc;
            color: var(--brand);
            border-color: var(--brand);
        }

        /* Filter controls */
        .filter-chip-group {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            flex-wrap: wrap;
        }
        .filter-chip {
            padding: 0.35rem 0.8rem;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 600;
            color: #64748b;
            background: #f1f5f9;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
        }
        .filter-chip:hover {
            color: var(--brand);
            background: #f8fafc;
        }
        .filter-chip.active {
            background: var(--brand);
            color: #ffffff;
            border-color: var(--brand);
        }

        /* Progress Bar */
        .amort-progress-track {
            height: 10px;
            background-color: #e2e8f0;
            border-radius: 999px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        .amort-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
            border-radius: 999px;
            transition: width 0.5s ease;
        }

        /* Print Mode Optimization */
        @media print {
            .mobile-toolbar, .admin-topbar, .account-selector-card, .btn-action, .records-filter-wrap, .no-print {
                display: none !important;
            }
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .page-container {
                max-width: 100% !important;
                padding: 0 !important;
            }
            .ledger-hero {
                background: #ffffff !important;
                color: #000000 !important;
                border: 2px solid #000000 !important;
                box-shadow: none !important;
                padding: 1rem !important;
            }
            .ledger-table th, .ledger-table td {
                border-color: #000000 !important;
            }
        }
    </style>
</head>
<body>

<?php
$active_nav = 'monthly_payments';
if ($isAdmin) {
    include __DIR__ . '/includes/admin_header.php';
} else {
    include __DIR__ . '/includes/user_header.php';
}
?>

<div class="page-container">

    <!-- 1. HERO BANNER -->
    <div class="ledger-hero">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <?php if (!$isAdmin): ?>
                    <a href="user_dashboard.php" class="btn btn-sm btn-outline-light mb-3 no-print" style="border-radius: 8px;">
                        <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                    </a>
                <?php else: ?>
                    <a href="sales_reports.php" class="btn btn-sm btn-outline-light mb-3 no-print" style="border-radius: 8px;">
                        <i class="fas fa-arrow-left me-1"></i> Back to Sales Reports
                    </a>
                <?php endif; ?>

                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-warning text-dark fw-bold px-2 py-1" style="font-size: 0.72rem; letter-spacing: 0.04em;">AMORTIZATION LEDGER</span>
                    <?php if ($isAdmin): ?>
                        <span class="badge bg-white text-danger fw-bold px-2 py-1" style="font-size: 0.72rem;">ADMINISTRATIVE AUDIT</span>
                    <?php else: ?>
                        <span class="badge bg-success text-white fw-bold px-2 py-1" style="font-size: 0.72rem;">CUSTOMER ACCOUNT</span>
                    <?php endif; ?>
                </div>

                <h1 class="hero-title">Monthly Payment Records</h1>
                <p class="hero-subtitle">
                    Official installment schedule, amortization breakdown, and verified payment transactions per customer account.
                </p>
            </div>

            <!-- Top Action Buttons -->
            <div class="d-flex gap-2 align-items-center no-print flex-wrap">
                <button type="button" onclick="window.print()" class="btn btn-light fw-bold" style="border-radius: 10px; font-size: 0.88rem;">
                    <i class="fas fa-print me-1 text-danger"></i> Print Statement
                </button>
                <a href="monthly_payments.php?action=export_account_csv&account_key=<?php echo urlencode($selected_account_key); ?>&user_id=<?php echo (int)$selected_user_id; ?>" class="btn btn-outline-light fw-bold" style="border-radius: 10px; font-size: 0.88rem;">
                    <i class="fas fa-file-csv me-1 text-warning"></i> Export Ledger (CSV)
                </a>
            </div>
        </div>
    </div>

    <!-- 2. ADMIN ACCOUNT SELECTOR / DIRECTORY SWITCHER -->
    <?php if ($isAdmin): ?>
    <div class="account-selector-card no-print">
        <div class="account-select-wrap">
            <label for="accountPicker" class="fw-bold text-dark d-flex align-items-center gap-2 mb-0" style="white-space: nowrap;">
                <i class="fas fa-user-circle text-danger fs-5"></i> Select Account:
            </label>
            <select id="accountPicker" class="form-select account-dropdown" onchange="switchAccount(this.value)">
                <?php if (empty($accounts_directory)): ?>
                    <option value="">No Active Accounts Found</option>
                <?php else: ?>
                    <?php foreach ($accounts_directory as $accKey => $acc): ?>
                        <option value="<?php echo htmlspecialchars($accKey); ?>" <?php echo ($accKey === $selected_account_key) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($acc['full_name']); ?> &bull; <?php echo htmlspecialchars($acc['account_number']); ?> <?php echo !empty($acc['plot_desc']) ? '(' . htmlspecialchars($acc['plot_desc']) . ') ' : ''; ?>(<?php echo $acc['schedules_count']; ?> installments<?php echo ($acc['overdue_count'] > 0) ? ', ' . $acc['overdue_count'] . ' overdue' : ''; ?>)
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <button type="button" class="btn btn-sm btn-outline-danger fw-bold d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#accountsDirectoryModal" style="border-radius: 9px; padding: 7px 14px; white-space: nowrap;">
                <i class="fas fa-users"></i> Browse Accounts
            </button>
        </div>

        <!-- Quick Summary Counters for Admin (Clickable shortcuts) -->
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-muted small d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#accountsDirectoryModal" title="Click to browse all customer accounts">
                <strong class="text-dark fs-6"><?php echo $accounts_with_installments; ?></strong> Active Installment Accounts <i class="fas fa-arrow-up-right-from-square text-danger ms-1" style="font-size: 0.72rem;"></i>
            </button>
            <span role="button" class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 user-select-none" onclick="filterRecordsTable('PAID', document.querySelector('.filter-chip[data-filter=\'PAID\']'))" style="font-size: 0.78rem; cursor: pointer;" title="Click to view all Paid installments">
                <i class="fas fa-check-circle me-1"></i> <?php echo formatDisplayCurrency($grand_total_monthly_collected); ?> Collected
            </span>
            <span role="button" class="badge bg-secondary-subtle text-secondary border px-2 py-1 user-select-none" onclick="filterRecordsTable('PENDING', document.querySelector('.filter-chip[data-filter=\'PENDING\']'))" style="font-size: 0.78rem; cursor: pointer;" title="Click to view Upcoming & Due installments">
                Total Due: <?php echo formatDisplayCurrency($grand_total_monthly_receivable); ?>
            </span>
        </div>
    </div>
    <?php endif; ?>

    <!-- 3. SELECTED ACCOUNT PROFILE HEADER -->
    <?php if ($account_user): ?>
    <div class="account-profile-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="profile-avatar-wrap">
                    <img src="<?php echo htmlspecialchars($profile_avatar); ?>" alt="Avatar" class="profile-avatar-img" onerror="this.onerror=null;this.src='cmis_logo.png'">
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h2 class="account-name-disp mb-0"><?php echo htmlspecialchars($account_user['full_name']); ?></h2>
                        <span class="badge bg-maroon text-white fw-bold px-2 py-1" style="background-color: var(--brand); font-size: 0.72rem;">
                            <?php echo htmlspecialchars($account_user['account_number']); ?>
                        </span>
                        <?php if ($acc_metrics['overdue_installments_count'] > 0): ?>
                            <span class="badge bg-danger text-white fw-bold px-2 py-1" style="font-size: 0.72rem;">
                                <i class="fas fa-exclamation-triangle me-1"></i> <?php echo $acc_metrics['overdue_installments_count']; ?> Overdue
                            </span>
                        <?php elseif ($acc_metrics['total_installments_count'] > 0 && $acc_metrics['paid_installments_count'] === $acc_metrics['total_installments_count']): ?>
                            <span class="badge bg-success text-white fw-bold px-2 py-1" style="font-size: 0.72rem;">
                                <i class="fas fa-check-double me-1"></i> Fully Completed
                            </span>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 0.72rem;">
                                <i class="fas fa-shield-alt me-1"></i> Good Standing
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2 mt-1 flex-wrap text-muted small">
                        <span><i class="fas fa-envelope text-secondary me-1"></i> <?php echo htmlspecialchars($account_user['email'] ?? 'No email'); ?></span>
                        <span>&bull;</span>
                        <span><i class="fas fa-phone text-secondary me-1"></i> <?php echo htmlspecialchars($account_user['phone_no'] ?? 'No phone'); ?></span>
                        <span>&bull;</span>
                        <span><i class="fas fa-calendar-check text-secondary me-1"></i> Member Since <?php echo !empty($account_user['created_at']) ? date('M Y', strtotime($account_user['created_at'])) : '—'; ?></span>
                    </div>
                </div>
            </div>

            <!-- Quick Action: Pay Next Due / Upload Proof (Customer View) -->
            <?php if (!$isAdmin && $acc_metrics['next_due_amount'] > 0): ?>
            <div class="d-flex align-items-center gap-2 no-print flex-wrap">
                <a href="gcash_gateway.php?booking_id=<?php echo $acc_metrics['next_due_booking_id']; ?>&installment=<?php echo $acc_metrics['next_due_installment_num']; ?>" class="btn btn-action-gcash shadow-sm py-2 px-3 fw-bold">
                    <i class="fas fa-mobile-alt me-1"></i> Pay Installment #<?php echo $acc_metrics['next_due_installment_num']; ?> (<?php echo formatDisplayCurrency($acc_metrics['next_due_amount']); ?>)
                </a>
                <a href="upload_payment_proof.php?booking_id=<?php echo $acc_metrics['next_due_booking_id']; ?>&installment_number=<?php echo $acc_metrics['next_due_installment_num']; ?>" class="btn btn-action-outline py-2 px-3 fw-bold">
                    <i class="fas fa-receipt me-1"></i> Upload Proof
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Overall Payment Progress Bar -->
        <div class="mt-3 pt-3 border-top">
            <div class="d-flex justify-content-between align-items-center text-muted small fw-semibold">
                <span>
                    Installment Completion: 
                    <strong class="text-dark"><?php echo $acc_metrics['paid_installments_count']; ?> of <?php echo $acc_metrics['total_installments_count']; ?> Monthly Dues Paid</strong>
                </span>
                <span class="text-success fw-bold"><?php echo $acc_metrics['progress_percent']; ?>% Complete</span>
            </div>
            <div class="amort-progress-track">
                <div class="amort-progress-fill" style="width: <?php echo min(100, $acc_metrics['progress_percent']); ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- 4. FINANCIAL METRIC CARDS (Clickable quick filters) -->
    <div class="metrics-grid">
        <div class="metric-card" onclick="filterRecordsTable('all', document.querySelector('.filter-chip[data-filter=\'all\']'))" title="Click to view all scheduled months">
            <div class="metric-label">
                <span>Total Amortization</span>
                <i class="fas fa-file-invoice-dollar text-primary"></i>
            </div>
            <div class="metric-value"><?php echo formatDisplayCurrency($acc_metrics['total_monthly_amortized']); ?></div>
            <div class="metric-hint text-muted">
                <?php echo $acc_metrics['total_installments_count']; ?> total scheduled months &bull; <span class="text-primary fw-semibold">View All</span>
            </div>
        </div>

        <div class="metric-card" onclick="filterRecordsTable('PAID', document.querySelector('.filter-chip[data-filter=\'PAID\']'))" title="Click to filter cleared payments">
            <div class="metric-label">
                <span>Total Amount Paid</span>
                <i class="fas fa-check-circle text-success"></i>
            </div>
            <div class="metric-value text-success"><?php echo formatDisplayCurrency($acc_metrics['total_monthly_paid']); ?></div>
            <div class="metric-hint text-success">
                <?php echo $acc_metrics['paid_installments_count']; ?> installments cleared &bull; <span class="fw-semibold">Filter Paid</span>
            </div>
        </div>

        <div class="metric-card" onclick="filterRecordsTable('PENDING', document.querySelector('.filter-chip[data-filter=\'PENDING\']'))" title="Click to filter remaining dues">
            <div class="metric-label">
                <span>Remaining Balance</span>
                <i class="fas fa-hourglass-half text-warning"></i>
            </div>
            <div class="metric-value text-dark"><?php echo formatDisplayCurrency($acc_metrics['total_remaining_balance']); ?></div>
            <div class="metric-hint text-muted">
                <?php echo max(0, $acc_metrics['total_installments_count'] - $acc_metrics['paid_installments_count']); ?> installments remaining &bull; <span class="text-warning fw-semibold">Filter Due</span>
            </div>
        </div>

        <div class="metric-card" onclick="scrollToNextDue()" title="Click to jump to Next Scheduled Due">
            <div class="metric-label">
                <span>Next Scheduled Due</span>
                <i class="fas fa-calendar-alt text-danger"></i>
            </div>
            <div class="metric-value" style="font-size: 1.25rem;">
                <?php if (!empty($acc_metrics['next_due_date'])): ?>
                    <?php echo date('M d, Y', strtotime($acc_metrics['next_due_date'])); ?>
                <?php else: ?>
                    <span class="text-success" style="font-size: 1.05rem;">No Pending Dues</span>
                <?php endif; ?>
            </div>
            <div class="metric-hint">
                <?php if (!empty($acc_metrics['next_due_date'])): ?>
                    <strong class="text-danger"><?php echo formatDisplayCurrency($acc_metrics['next_due_amount']); ?></strong> (Installment #<?php echo $acc_metrics['next_due_installment_num']; ?>) &bull; <span class="text-danger fw-semibold">Jump to Row &darr;</span>
                <?php else: ?>
                    <span class="text-muted">Account is fully paid</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 5. CONTRACT / PLOT TABS (if customer has multiple bookings) -->
    <?php if (count($account_contracts) > 1): ?>
    <div class="contract-nav-tabs no-print">
        <a href="monthly_payments.php?account_key=<?php echo urlencode($selected_account_key); ?>&contract_id=0" class="contract-tab-link <?php echo ($filter_contract_id === 0) ? 'active' : ''; ?>">
            <i class="fas fa-layer-group me-1"></i> All Contracts Combined (<?php echo count($account_contracts); ?>)
        </a>
        <?php foreach ($account_contracts as $cId => $c): ?>
            <?php
            $plotDisp = trim(($c['section'] ? 'Sec ' . $c['section'] : '') . ($c['plot'] ? ' Plot ' . $c['plot'] : ''));
            ?>
            <a href="monthly_payments.php?account_key=<?php echo urlencode($selected_account_key); ?>&contract_id=<?php echo $cId; ?>" class="contract-tab-link <?php echo ($filter_contract_id === $cId) ? 'active' : ''; ?>">
                <i class="fas fa-monument me-1"></i> Contract #<?php echo $cId; ?> (<?php echo htmlspecialchars($plotDisp ?: 'Plot'); ?>)
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 6. MONTHLY PAYMENT RECORDS TABLE -->
    <div class="records-card">
        <div class="records-header">
            <div class="records-title">
                <i class="fas fa-list-check text-danger"></i>
                <span>Monthly Installment Records</span>
                <span class="badge bg-secondary-subtle text-secondary border ms-2" style="font-size: 0.75rem;" id="recordCountBadge">
                    <?php echo count($displayed_schedules); ?> records
                </span>
            </div>

            <!-- Filters & Search Toolbar -->
            <div class="d-flex align-items-center gap-2 flex-wrap no-print records-filter-wrap">
                <!-- Status Filter Chips -->
                <div class="filter-chip-group">
                    <span class="filter-chip active" data-filter="all" onclick="filterRecordsTable('all', this)">All</span>
                    <span class="filter-chip" data-filter="PAID" onclick="filterRecordsTable('PAID', this)">Paid</span>
                    <span class="filter-chip" data-filter="PENDING" onclick="filterRecordsTable('PENDING', this)">Upcoming</span>
                    <span class="filter-chip" data-filter="OVERDUE" onclick="filterRecordsTable('OVERDUE', this)">Overdue</span>
                    <span class="filter-chip" data-filter="PENDING VERIFICATION" onclick="filterRecordsTable('PENDING VERIFICATION', this)">Verification</span>
                </div>

                <!-- Live Search Box -->
                <div class="input-group input-group-sm" style="max-width: 220px;">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="fas fa-search"></i></span>
                    <input type="text" id="recordsSearchInput" class="form-control border-start-0" placeholder="Search records..." onkeyup="searchRecordsTable(this.value)">
                </div>
            </div>
        </div>

        <!-- Table Content -->
        <div class="table-responsive">
            <table class="ledger-table" id="monthlyLedgerTable">
                <thead>
                    <tr>
                        <th style="width: 60px;">#</th>
                        <th>Contract / Plot</th>
                        <th>Scheduled Due Date</th>
                        <th>Monthly Amount Due</th>
                        <th>Amount Paid</th>
                        <th>Remaining Balance</th>
                        <th>Status</th>
                        <th>Payment Date</th>
                        <th>Payment Method</th>
                        <th>Reference / Receipt</th>
                        <th class="no-print" style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($displayed_schedules)): ?>
                        <tr>
                            <td colspan="11" class="text-center py-5">
                                <div class="text-muted">
                                    <i class="fas fa-file-invoice text-secondary opacity-50 mb-2" style="font-size: 2.5rem;"></i>
                                    <div class="fw-bold fs-6">No Monthly Payment Records Found</div>
                                    <p class="small text-muted mb-0">This account currently has no active monthly installment schedules.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($displayed_schedules as $idx => $sch): ?>
                            <?php
                            $cStatus = $sch['computed_status'];
                            $plotDisp = trim(($sch['section'] ? 'Section ' . $sch['section'] : '') . ($sch['plot'] ? ' Plot ' . $sch['plot'] : ($sch['section_plot'] ?: '—')));
                            $refNo = !empty($sch['transaction_reference']) ? $sch['transaction_reference'] : (!empty($sch['or_number']) ? $sch['or_number'] : ($sch['receipt_number'] ?: '—'));
                            $instData = [
                                'id' => (int)$sch['id'],
                                'contract_id' => (int)$sch['contract_id'],
                                'installment_number' => (int)$sch['installment_number'],
                                'status' => $cStatus,
                                'customer_name' => $account_user['full_name'] ?? '—',
                                'account_number' => $account_user['account_number'] ?? '—',
                                'plot_loc' => $plotDisp,
                                'due_date' => $sch['due_date'],
                                'due_date_formatted' => date('M d, Y (l)', strtotime($sch['due_date'])),
                                'monthly_amount_formatted' => formatDisplayCurrency($sch['monthly_amount']),
                                'paid_amount_formatted' => formatDisplayCurrency($sch['paid_amount']),
                                'balance_formatted' => formatDisplayCurrency($sch['remaining_balance']),
                                'paid_date_formatted' => !empty($sch['paid_at']) ? date('M d, Y h:i A', strtotime($sch['paid_at'])) : (!empty($sch['receipt_date']) ? date('M d, Y h:i A', strtotime($sch['receipt_date'])) : 'Not Paid Yet'),
                                'payment_method' => !empty($sch['payment_method']) ? $sch['payment_method'] : '—',
                                'reference' => $refNo,
                                'or_number' => !empty($sch['or_number']) ? $sch['or_number'] : '—',
                                'notes' => !empty($sch['notes']) ? $sch['notes'] : '',
                                'proof_filename' => !empty($sch['proof_filename']) ? $sch['proof_filename'] : '',
                                'days_left' => $sch['days_left'] ?? 0,
                                'days_overdue' => $sch['days_overdue'] ?? 0
                            ];
                            ?>
                            <tr class="ledger-row" data-status="<?php echo htmlspecialchars($cStatus); ?>" data-booking="<?php echo (int)$sch['contract_id']; ?>" data-installment='<?php echo htmlspecialchars(json_encode($instData), ENT_QUOTES, "UTF-8"); ?>' onclick="handleLedgerRowClick(event, this)" style="cursor: pointer;" title="Click row to view payment details">
                                <td class="fw-bold text-muted">
                                    #<?php echo (int)$sch['installment_number']; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark">Contract #<?php echo (int)$sch['contract_id']; ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($plotDisp); ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold"><?php echo date('M d, Y', strtotime($sch['due_date'])); ?></div>
                                    <small class="text-muted"><?php echo date('l', strtotime($sch['due_date'])); ?></small>
                                </td>
                                <td class="fw-bold text-dark">
                                    <?php echo formatDisplayCurrency($sch['monthly_amount']); ?>
                                </td>
                                <td class="fw-bold <?php echo ($sch['paid_amount'] > 0) ? 'text-success' : 'text-muted'; ?>">
                                    <?php echo formatDisplayCurrency($sch['paid_amount']); ?>
                                </td>
                                <td class="text-muted fw-semibold">
                                    <?php echo formatDisplayCurrency($sch['remaining_balance']); ?>
                                </td>
                                <td>
                                    <?php if ($cStatus === 'PAID'): ?>
                                        <span class="badge-status paid">
                                            <i class="fas fa-check-circle"></i> PAID
                                        </span>
                                    <?php elseif ($cStatus === 'PENDING VERIFICATION'): ?>
                                        <span class="badge-status pending-ver" title="Payment submitted, awaiting admin verification">
                                            <i class="fas fa-clock"></i> FOR VERIFICATION
                                        </span>
                                    <?php elseif ($cStatus === 'OVERDUE'): ?>
                                        <span class="badge-status overdue" title="<?php echo $sch['days_overdue'] ?? 1; ?> day(s) past due">
                                            <i class="fas fa-exclamation-triangle"></i> OVERDUE (<?php echo $sch['days_overdue'] ?? 1; ?>d)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-status upcoming" title="Scheduled due in <?php echo $sch['days_left'] ?? 0; ?> day(s)">
                                            <i class="fas fa-calendar"></i> PENDING
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($sch['paid_at'])): ?>
                                        <div><?php echo date('M d, Y', strtotime($sch['paid_at'])); ?></div>
                                        <small class="text-muted"><?php echo date('h:i A', strtotime($sch['paid_at'])); ?></small>
                                    <?php elseif (!empty($sch['receipt_date'])): ?>
                                        <div><?php echo date('M d, Y', strtotime($sch['receipt_date'])); ?></div>
                                        <small class="text-muted"><?php echo date('h:i A', strtotime($sch['receipt_date'])); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($sch['payment_method'])): ?>
                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($sch['payment_method']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($refNo !== '—'): ?>
                                        <span class="font-monospace small fw-bold text-dark"><?php echo htmlspecialchars($refNo); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="no-print" style="text-align: right; white-space: nowrap;">
                                    <div class="d-inline-flex gap-1">
                                        <!-- View Details (Read-only) -->
                                        <button type="button" class="btn btn-action btn-action-outline btn-sm" onclick="openInstallmentDetailsModal(this)" title="View Payment Details">
                                            <i class="fas fa-eye text-primary"></i> Details
                                        </button>

                                        <!-- View Proof Screenshot if available -->
                                        <?php if (!empty($sch['proof_filename'])): ?>
                                            <button type="button" class="btn btn-action btn-action-outline btn-sm" onclick="viewPaymentProof('<?php echo htmlspecialchars($sch['proof_filename']); ?>', <?php echo (int)$sch['contract_id']; ?>, 'Installment #<?php echo (int)$sch['installment_number']; ?>')" title="View Payment Proof">
                                                <i class="fas fa-image text-primary"></i> Proof
                                            </button>
                                        <?php endif; ?>

                                        <!-- If PAID: View Receipt -->
                                        <?php if ($cStatus === 'PAID'): ?>
                                            <a href="print_invoice.php?booking_id=<?php echo (int)$sch['contract_id']; ?>&installment=<?php echo (int)$sch['installment_number']; ?>" target="_blank" class="btn btn-action btn-action-outline btn-sm" title="View / Print Receipt">
                                                <i class="fas fa-receipt text-success"></i> Receipt
                                            </a>
                                        <?php endif; ?>

                                        <!-- If CUSTOMER and UNPAID: Pay GCash / Upload Proof -->
                                        <?php if (!$isAdmin && ($cStatus === 'PENDING' || $cStatus === 'OVERDUE')): ?>
                                            <a href="gcash_gateway.php?booking_id=<?php echo (int)$sch['contract_id']; ?>&installment=<?php echo (int)$sch['installment_number']; ?>" class="btn btn-action btn-action-gcash btn-sm" title="Pay via GCash">
                                                <i class="fas fa-mobile-alt"></i> Pay GCash
                                            </a>
                                            <a href="upload_payment_proof.php?booking_id=<?php echo (int)$sch['contract_id']; ?>&installment_number=<?php echo (int)$sch['installment_number']; ?>" class="btn btn-action btn-action-outline btn-sm" title="Upload Payment Proof">
                                                <i class="fas fa-upload"></i> Proof
                                            </a>
                                        <?php endif; ?>

                                        <!-- If ADMIN and UNPAID: Record Payment & Send Reminder -->
                                        <?php if ($isAdmin && ($cStatus === 'PENDING' || $cStatus === 'OVERDUE' || $cStatus === 'PENDING VERIFICATION')): ?>
                                            <button type="button" class="btn btn-action btn-action-primary btn-sm" onclick="openRecordPaymentModal(<?php echo (int)$sch['id']; ?>, <?php echo (int)$sch['contract_id']; ?>, <?php echo (int)$sch['installment_number']; ?>, <?php echo (float)$sch['monthly_amount']; ?>, '<?php echo htmlspecialchars(addslashes($account_user['full_name'])); ?>')" title="Record Payment for this Installment">
                                                <i class="fas fa-cash-register"></i> Record
                                            </button>
                                            <button type="button" class="btn btn-action btn-action-outline btn-sm" onclick="sendPaymentReminder(<?php echo (int)$sch['id']; ?>, <?php echo (int)$sch['installment_number']; ?>, this)" title="Send Payment Reminder Email">
                                                <i class="fas fa-bell text-warning"></i> Remind
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>
        <div class="card p-5 text-center shadow-sm border-0 my-4" style="border-radius: 16px; background: #ffffff;">
            <div class="mb-3">
                <i class="fas fa-folder-open text-muted opacity-50" style="font-size: 3.5rem;"></i>
            </div>
            <h4 class="fw-bold text-dark">No Installment Account Selected</h4>
            <p class="text-muted mx-auto" style="max-width: 500px;">
                Please select a customer account from the dropdown above or browse the accounts directory to view their monthly payment schedules and transaction records.
            </p>
            <?php if ($isAdmin): ?>
            <div>
                <button type="button" class="btn btn-outline-danger fw-bold" data-bs-toggle="modal" data-bs-target="#accountsDirectoryModal" style="border-radius: 9px; padding: 8px 18px;">
                    <i class="fas fa-users me-1"></i> Open Accounts Directory
                </button>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<!-- ========================================================================= -->
<!-- MODAL: View Installment Payment Details (READ-ONLY)                        -->
<!-- ========================================================================= -->
<div class="modal fade" id="installmentDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.3);">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%);">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-file-invoice text-warning fs-4"></i>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="detModalTitle">Installment Payment Details</h5>
                        <small class="text-white-50" id="detModalSubtitle">Statement &amp; Transaction Details</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" style="background-color: #f8fafc;">
                <!-- Top Status Banner -->
                <div class="d-flex justify-content-between align-items-center p-3 mb-3 bg-white rounded-3 border">
                    <div>
                        <div class="text-muted small text-uppercase fw-bold">Payment Status</div>
                        <div id="detStatusBadge" class="mt-1"></div>
                    </div>
                    <div class="text-end">
                        <div class="text-muted small text-uppercase fw-bold">Scheduled Due Date</div>
                        <div class="fw-bold text-dark fs-6" id="detDueDate">—</div>
                    </div>
                </div>

                <!-- Financial Breakdown 3-Box Grid -->
                <div class="row g-2 mb-3 text-center">
                    <div class="col-4">
                        <div class="p-3 bg-white rounded-3 border h-100">
                            <div class="text-muted small fw-bold text-uppercase">Amount Due</div>
                            <div class="fw-bold text-dark fs-5 mt-1" id="detAmountDue">₱0.00</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 bg-white rounded-3 border h-100">
                            <div class="text-muted small fw-bold text-uppercase">Amount Paid</div>
                            <div class="fw-bold text-success fs-5 mt-1" id="detAmountPaid">₱0.00</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 bg-white rounded-3 border h-100">
                            <div class="text-muted small fw-bold text-uppercase">Remaining Balance</div>
                            <div class="fw-bold text-danger fs-5 mt-1" id="detBalance">₱0.00</div>
                        </div>
                    </div>
                </div>

                <!-- Account & Transaction Details Card -->
                <div class="bg-white p-3 rounded-3 border mb-3">
                    <h6 class="fw-bold text-dark mb-3 border-bottom pb-2 d-flex align-items-center gap-2">
                        <i class="fas fa-info-circle text-danger"></i> Transaction &amp; Account Breakdown
                    </h6>
                    <div class="row g-3 small">
                        <div class="col-md-6">
                            <div class="text-muted">Customer Name:</div>
                            <div class="fw-bold text-dark" id="detCustomerName">—</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted">Account Number:</div>
                            <div class="fw-bold text-dark font-monospace" id="detAccountNumber">—</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted">Contract / Booking:</div>
                            <div class="fw-bold text-dark" id="detContractId">—</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted">Plot Location:</div>
                            <div class="fw-bold text-dark" id="detPlotLoc">—</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted">Payment Date:</div>
                            <div class="fw-bold text-dark" id="detPaidDate">—</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted">Payment Method:</div>
                            <div class="fw-bold text-dark" id="detPaymentMethod">—</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted">Reference / GCash No.:</div>
                            <div class="fw-bold text-dark font-monospace" id="detReference">—</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted">Official Receipt (OR) #:</div>
                            <div class="fw-bold text-dark font-monospace" id="detOrNumber">—</div>
                        </div>
                        <div class="col-12" id="detNotesWrap" style="display: none;">
                            <div class="text-muted">Notes / Remarks:</div>
                            <div class="fw-semibold text-dark p-2 bg-light rounded" id="detNotes">—</div>
                        </div>
                    </div>
                </div>

                <!-- Payment Proof Screenshot Box (if available) -->
                <div class="bg-white p-3 rounded-3 border" id="detProofWrap" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                            <i class="fas fa-image text-danger"></i> Proof of Payment
                        </h6>
                        <a id="detProofViewBtn" href="#" target="_blank" class="btn btn-sm btn-outline-danger fw-bold py-1 px-2" style="font-size: 0.75rem;">
                            <i class="fas fa-expand me-1"></i> Open Full Image
                        </a>
                    </div>
                    <div class="text-center p-2 bg-light rounded-2 border">
                        <img id="detProofImg" src="" alt="Proof Preview" style="max-height: 220px; max-width: 100%; border-radius: 8px; object-fit: contain;">
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-top d-flex justify-content-between">
                <div>
                    <a id="detReceiptLink" href="#" target="_blank" class="btn btn-sm btn-outline-success fw-bold" style="display: none;">
                        <i class="fas fa-receipt me-1"></i> Print Official Receipt
                    </a>
                </div>
                <button type="button" class="btn btn-sm btn-secondary fw-bold px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: View Payment Proof Screenshot                                      -->
<!-- ========================================================================= -->
<div class="modal fade" id="paymentProofModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.3);">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%);">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-image fs-5 text-warning"></i>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="proofModalTitle">Payment Proof</h5>
                        <small class="text-white-50" id="proofModalSubtitle"></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-4" style="background-color: #f8fafc; min-height: 250px;">
                <div id="proofSpinner" class="py-5">
                    <div class="spinner-border text-danger" role="status"></div>
                    <div class="text-muted small mt-2">Loading screenshot proof...</div>
                </div>
                <img id="proofModalImg" src="" alt="Payment Proof" style="display: none; max-width: 100%; max-height: 65vh; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.12);" onload="document.getElementById('proofSpinner').style.display='none'; this.style.display='block';">
            </div>
            <div class="modal-footer bg-white border-top">
                <a id="proofDownloadBtn" href="#" download class="btn btn-sm btn-outline-danger fw-bold">
                    <i class="fas fa-download me-1"></i> Download Image
                </a>
                <button type="button" class="btn btn-sm btn-secondary fw-bold" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Admin Record Installment Payment                                    -->
<!-- ========================================================================= -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.3);">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%);">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-cash-register fs-5 text-warning"></i>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Record Monthly Payment</h5>
                        <small class="text-white-50" id="recPaySubtitle">Contract Milestone</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="recordPaymentForm" onsubmit="submitRecordPayment(event)">
                <input type="hidden" name="action" value="record_installment_payment">
                <input type="hidden" name="schedule_id" id="rec_schedule_id" value="0">
                <input type="hidden" name="contract_id" id="rec_contract_id" value="0">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">Customer Account</label>
                        <input type="text" id="rec_customer_name" class="form-control form-control-sm fw-bold bg-light" readonly>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Installment #</label>
                            <input type="text" id="rec_inst_display" class="form-control form-control-sm fw-bold bg-light" readonly>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Amount Due (₱)</label>
                            <input type="number" step="0.01" name="amount_paid" id="rec_amount_paid" class="form-control form-control-sm fw-bold" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Payment Method</label>
                            <select name="payment_method" class="form-select form-select-sm" required>
                                <option value="Cash on Site">Cash on Site</option>
                                <option value="GCash">GCash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Check">Check</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Payment Date</label>
                            <input type="datetime-local" name="payment_date" id="rec_payment_date" class="form-control form-control-sm" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Reference / GCash No.</label>
                            <input type="text" name="reference_no" class="form-control form-control-sm" placeholder="e.g. GC123456789">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Official Receipt (OR) #</label>
                            <input type="text" name="or_number" class="form-control form-control-sm" placeholder="e.g. OR-2026-001">
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-bold text-muted text-uppercase">Internal Audit Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Optional remarks..."></textarea>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="recSubmitBtn" class="btn btn-sm btn-danger fw-bold" style="background-color: var(--brand); border-color: var(--brand);">
                        <i class="fas fa-check me-1"></i> Confirm &amp; Mark as Paid
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Admin Accounts Directory & Portfolio Overview                      -->
<!-- ========================================================================= -->
<div class="modal fade" id="accountsDirectoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.3);">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%);">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-users fs-5 text-warning"></i>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Customer Accounts Directory</h5>
                        <small class="text-white-50">Select any account to view its full monthly amortization ledger</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" style="background-color: #f8fafc;">
                <!-- Search toolbar -->
                <div class="p-3 bg-white border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="input-group input-group-sm" style="max-width: 320px;">
                        <span class="input-group-text bg-white text-muted border-end-0"><i class="fas fa-search"></i></span>
                        <input type="text" id="dirSearchInput" class="form-control border-start-0" placeholder="Search by name, email, or account #..." onkeyup="searchAccountsDirectory(this.value)">
                    </div>
                    <div class="text-muted small fw-semibold">
                        Showing <?php echo count($accounts_directory); ?> registered accounts
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="dirTable">
                        <thead style="background-color: #f1f5f9; font-size: 0.72rem; text-transform: uppercase; color: #475569;">
                            <tr>
                                <th class="ps-3">Account / Customer</th>
                                <th>Account #</th>
                                <th>Email &amp; Phone</th>
                                <th class="text-center">Installments</th>
                                <th>Total Amortized</th>
                                <th>Total Paid</th>
                                <th>Balance Due</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody style="font-size: 0.85rem;">
                            <?php foreach ($accounts_directory as $accKey => $acc): ?>
                                <tr class="dir-row" data-text="<?php echo htmlspecialchars(strtolower($acc['full_name'] . ' ' . $acc['email'] . ' ' . $acc['account_number'])); ?>">
                                    <td class="ps-3">
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($acc['full_name']); ?></div>
                                        <small class="text-muted"><?php echo $acc['contracts_count']; ?> contract(s)<?php echo !empty($acc['plot_desc']) ? ' &bull; ' . htmlspecialchars($acc['plot_desc']) : ''; ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($acc['account_number']); ?></span>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($acc['email'] ?: 'No email'); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($acc['phone_no'] ?: '—'); ?></small>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                            <?php echo $acc['schedules_count']; ?> mo.
                                        </span>
                                    </td>
                                    <td class="fw-bold text-dark">
                                        <?php echo formatDisplayCurrency($acc['total_monthly_sum']); ?>
                                    </td>
                                    <td class="fw-bold text-success">
                                        <?php echo formatDisplayCurrency($acc['total_paid']); ?>
                                    </td>
                                    <td class="fw-bold text-danger">
                                        <?php echo formatDisplayCurrency($acc['remaining_balance']); ?>
                                    </td>
                                    <td>
                                        <?php if ($acc['overdue_count'] > 0): ?>
                                            <span class="badge bg-danger text-white">
                                                <i class="fas fa-exclamation-circle me-1"></i> <?php echo $acc['overdue_count']; ?> Overdue
                                            </span>
                                        <?php elseif ($acc['schedules_count'] > 0 && $acc['remaining_balance'] <= 0): ?>
                                            <span class="badge bg-success text-white">
                                                <i class="fas fa-check-circle me-1"></i> Fully Paid
                                            </span>
                                        <?php elseif ($acc['schedules_count'] > 0): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                Active
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border">
                                                No Schedules
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <a href="monthly_payments.php?account_key=<?php echo urlencode($acc['key'] ?? $accKey); ?>" class="btn btn-sm btn-outline-danger fw-bold" style="border-radius: 8px; font-size: 0.78rem;">
                                            <i class="fas fa-folder-open me-1"></i> View Ledger
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light border-top">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- MODAL: Read-Only Installment Payment Details                               -->
<!-- ========================================================================= -->
<div class="modal fade" id="installmentDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.3);">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%);">
                <div class="d-flex align-items-center gap-2">
                    <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-file-invoice-dollar fs-5 text-warning"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="detModalTitle">Installment Payment Details</h5>
                        <small class="text-white-50" id="detModalSubtitle">View Payment Summary</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" style="background-color: #f8fafc;">
                <!-- Summary Card -->
                <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; overflow: hidden;">
                    <div class="card-body p-3 bg-white">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 pb-3 border-bottom">
                            <div>
                                <span class="text-muted small text-uppercase fw-bold">Payment Status</span>
                                <div id="detStatusBadge" class="mt-1"></div>
                            </div>
                            <div class="text-end">
                                <span class="text-muted small text-uppercase fw-bold">Scheduled Due Date</span>
                                <div id="detDueDate" class="fw-bold fs-6 text-dark mt-1"></div>
                            </div>
                        </div>
                        <div class="row g-3 text-center">
                            <div class="col-4">
                                <div class="p-2 rounded bg-light">
                                    <div class="text-muted small">Monthly Due</div>
                                    <div id="detAmountDue" class="fw-bold fs-5 text-dark"></div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 rounded" style="background-color: #ecfdf5;">
                                    <div class="text-muted small">Amount Paid</div>
                                    <div id="detAmountPaid" class="fw-bold fs-5 text-success"></div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 rounded" style="background-color: #fef2f2;">
                                    <div class="text-muted small">Remaining Balance</div>
                                    <div id="detBalance" class="fw-bold fs-5 text-danger"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Breakdown List (Clean Read-Only View, No Editable Inputs) -->
                <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
                    <div class="card-header bg-white border-bottom py-2 px-3 fw-bold text-dark small text-uppercase">
                        <i class="fas fa-info-circle text-primary me-1"></i> Account &amp; Transaction Details
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-striped mb-0" style="font-size: 0.9rem;">
                            <tbody>
                                <tr>
                                    <th class="ps-3 py-2 text-muted fw-semibold" style="width: 38%;">Customer Name</th>
                                    <td class="pe-3 py-2 fw-bold text-dark" id="detCustomerName">—</td>
                                </tr>
                                <tr>
                                    <th class="ps-3 py-2 text-muted fw-semibold">Customer Account #</th>
                                    <td class="pe-3 py-2 font-monospace fw-semibold" id="detAccountNumber">—</td>
                                </tr>
                                <tr>
                                    <th class="ps-3 py-2 text-muted fw-semibold">Booking / Contract</th>
                                    <td class="pe-3 py-2 fw-semibold text-dark" id="detContractId">—</td>
                                </tr>
                                <tr>
                                    <th class="ps-3 py-2 text-muted fw-semibold">Plot Location</th>
                                    <td class="pe-3 py-2 text-dark" id="detPlotLoc">—</td>
                                </tr>
                                <tr>
                                    <th class="ps-3 py-2 text-muted fw-semibold">Date Paid</th>
                                    <td class="pe-3 py-2 text-dark" id="detPaidDate">—</td>
                                </tr>
                                <tr>
                                    <th class="ps-3 py-2 text-muted fw-semibold">Payment Method</th>
                                    <td class="pe-3 py-2 text-dark" id="detPaymentMethod">—</td>
                                </tr>
                                <tr>
                                    <th class="ps-3 py-2 text-muted fw-semibold">GCash / Reference #</th>
                                    <td class="pe-3 py-2 font-monospace text-dark" id="detReference">—</td>
                                </tr>
                                <tr>
                                    <th class="ps-3 py-2 text-muted fw-semibold">Official Receipt (OR) #</th>
                                    <td class="pe-3 py-2 font-monospace text-dark" id="detOrNumber">—</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Notes / Remarks (if any) -->
                <div id="detNotesWrap" class="card border-0 shadow-sm mb-4" style="border-radius: 12px; display: none;">
                    <div class="card-header bg-white border-bottom py-2 px-3 fw-bold text-dark small text-uppercase">
                        <i class="fas fa-sticky-note text-warning me-1"></i> Payment Notes / Remarks
                    </div>
                    <div class="card-body p-3">
                        <p class="mb-0 text-muted" id="detNotes" style="font-size: 0.9rem; white-space: pre-wrap;"></p>
                    </div>
                </div>

                <!-- Proof of Payment Preview (if uploaded) -->
                <div id="detProofWrap" class="card border-0 shadow-sm" style="border-radius: 12px; display: none;">
                    <div class="card-header bg-white border-bottom py-2 px-3 d-flex align-items-center justify-content-between">
                        <span class="fw-bold text-dark small text-uppercase"><i class="fas fa-image text-info me-1"></i> Payment Proof Screenshot</span>
                        <a href="#" id="detProofViewBtn" target="_blank" class="btn btn-xs btn-outline-primary" style="font-size: 0.75rem;">
                            <i class="fas fa-external-link-alt me-1"></i> Open Full View
                        </a>
                    </div>
                    <div class="card-body p-3 text-center bg-light">
                        <img src="" id="detProofImg" alt="Payment Proof" class="img-fluid rounded border shadow-sm" style="max-height: 280px; object-fit: contain;">
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-top d-flex justify-content-between">
                <div>
                    <a href="#" id="detReceiptLink" target="_blank" class="btn btn-sm btn-success fw-semibold" style="display: none;">
                        <i class="fas fa-receipt me-1"></i> Print / Download Receipt
                    </a>
                </div>
                <button type="button" class="btn btn-sm btn-secondary fw-semibold px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Switch active account (Admin view)
function switchAccount(accountKey) {
    if (!accountKey) return;
    window.location.href = 'monthly_payments.php?account_key=' + encodeURIComponent(accountKey);
}

// Open Read-Only Installment Details Modal
function openInstallmentDetailsModal(triggerEl) {
    if (!triggerEl) return;
    var row = (triggerEl.classList && triggerEl.classList.contains('ledger-row')) ? triggerEl : triggerEl.closest('.ledger-row');
    if (!row) return;

    var raw = row.getAttribute('data-installment');
    if (!raw) return;

    var d = JSON.parse(raw);
    var modalEl = document.getElementById('installmentDetailsModal');
    if (!modalEl) return;

    document.getElementById('detModalTitle').textContent = 'Installment #' + d.installment_number + ' Payment Details';
    document.getElementById('detModalSubtitle').textContent = 'Contract #' + d.contract_id + ' • ' + d.customer_name;

    // Status Badge
    var statusHtml = '';
    if (d.status === 'PAID') {
        statusHtml = '<span class="badge-status paid fs-6"><i class="fas fa-check-circle"></i> PAID</span>';
    } else if (d.status === 'PENDING VERIFICATION') {
        statusHtml = '<span class="badge-status pending-ver fs-6"><i class="fas fa-clock"></i> FOR VERIFICATION</span>';
    } else if (d.status === 'OVERDUE') {
        statusHtml = '<span class="badge-status overdue fs-6"><i class="fas fa-exclamation-triangle"></i> OVERDUE (' + (d.days_overdue || 1) + 'd late)</span>';
    } else {
        statusHtml = '<span class="badge-status upcoming fs-6"><i class="fas fa-calendar"></i> PENDING (' + (d.days_left || 0) + 'd left)</span>';
    }
    document.getElementById('detStatusBadge').innerHTML = statusHtml;

    document.getElementById('detDueDate').textContent = d.due_date_formatted || d.due_date;
    document.getElementById('detAmountDue').textContent = d.monthly_amount_formatted;
    document.getElementById('detAmountPaid').textContent = d.paid_amount_formatted;
    document.getElementById('detBalance').textContent = d.balance_formatted;

    document.getElementById('detCustomerName').textContent = d.customer_name;
    document.getElementById('detAccountNumber').textContent = d.account_number;
    document.getElementById('detContractId').textContent = 'Booking #' + d.contract_id;
    document.getElementById('detPlotLoc').textContent = d.plot_loc || '—';
    document.getElementById('detPaidDate').textContent = d.paid_date_formatted || 'Not Paid Yet';
    document.getElementById('detPaymentMethod').textContent = d.payment_method || '—';
    document.getElementById('detReference').textContent = d.reference || '—';
    document.getElementById('detOrNumber').textContent = d.or_number || '—';

    // Notes
    var notesWrap = document.getElementById('detNotesWrap');
    if (d.notes && d.notes.trim() !== '') {
        notesWrap.style.display = 'block';
        document.getElementById('detNotes').textContent = d.notes;
    } else {
        notesWrap.style.display = 'none';
    }

    // Proof Preview
    var proofWrap = document.getElementById('detProofWrap');
    var proofImg = document.getElementById('detProofImg');
    var proofBtn = document.getElementById('detProofViewBtn');
    if (d.proof_filename && d.proof_filename.trim() !== '') {
        var pUrl = 'view_payment_proof.php?booking_id=' + encodeURIComponent(d.contract_id) + '&file=' + encodeURIComponent(d.proof_filename);
        proofImg.src = pUrl;
        proofBtn.href = pUrl;
        proofWrap.style.display = 'block';
    } else {
        proofWrap.style.display = 'none';
    }

    // Receipt print button
    var rcptLink = document.getElementById('detReceiptLink');
    if (d.status === 'PAID') {
        rcptLink.href = 'print_invoice.php?booking_id=' + encodeURIComponent(d.contract_id) + '&installment=' + encodeURIComponent(d.installment_number);
        rcptLink.style.display = 'inline-flex';
    } else {
        rcptLink.style.display = 'none';
    }

    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.show();
}

// Handle click on entire ledger row (Opens READ-ONLY details modal)
function handleLedgerRowClick(event, row) {
    // If the click originated from an active button or link inside actions, allow it to execute
    if (event.target.closest('button, a, input, select, textarea')) {
        return;
    }
    // Otherwise open the clean, read-only Payment Details modal
    openInstallmentDetailsModal(row);
}

// Scroll smoothly to next due or overdue installment row and highlight it
function scrollToNextDue() {
    var overdueRow = document.querySelector('.ledger-row[data-status="OVERDUE"]');
    var pendingRow = document.querySelector('.ledger-row[data-status="PENDING"]');
    var target = overdueRow || pendingRow;
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        target.style.transition = 'background-color 0.4s ease';
        var origBg = target.style.backgroundColor;
        target.style.backgroundColor = '#fef08a';
        setTimeout(function() {
            target.style.backgroundColor = origBg;
        }, 1600);
    } else {
        alert('All installments for this account are fully paid!');
    }
}

// Filter records table by status
function filterRecordsTable(status, chipElement) {
    if (chipElement) {
        document.querySelectorAll('.filter-chip').forEach(function(c) {
            c.classList.remove('active');
        });
        chipElement.classList.add('active');
    }

    var rows = document.querySelectorAll('.ledger-row');
    var visibleCount = 0;

    rows.forEach(function(row) {
        var rowStatus = row.getAttribute('data-status') || '';
        if (status === 'all' || rowStatus.toUpperCase() === status.toUpperCase()) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    var badge = document.getElementById('recordCountBadge');
    if (badge) {
        badge.textContent = visibleCount + ' records';
    }
}

// Live search across records
function searchRecordsTable(term) {
    var filter = term.toLowerCase().trim();
    var rows = document.querySelectorAll('.ledger-row');
    var visibleCount = 0;

    rows.forEach(function(row) {
        var text = row.innerText.toLowerCase();
        if (!filter || text.indexOf(filter) !== -1) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    var badge = document.getElementById('recordCountBadge');
    if (badge) {
        badge.textContent = visibleCount + ' records';
    }
}

// View Payment Proof Modal
function viewPaymentProof(filename, bookingId, label) {
    if (!filename) return;
    var modalEl = document.getElementById('paymentProofModal');
    if (!modalEl) return;

    var titleEl = document.getElementById('proofModalTitle');
    var subEl = document.getElementById('proofModalSubtitle');
    var imgEl = document.getElementById('proofModalImg');
    var spinnerEl = document.getElementById('proofSpinner');
    var dlBtn = document.getElementById('proofDownloadBtn');

    if (titleEl) titleEl.textContent = 'Payment Proof: ' + (label || 'Screenshot');
    if (subEl) subEl.textContent = 'Booking #' + bookingId + ' &bull; ' + filename;

    if (spinnerEl) spinnerEl.style.display = 'block';
    if (imgEl) imgEl.style.display = 'none';

    var proofUrl = 'view_payment_proof.php?booking_id=' + encodeURIComponent(bookingId) + '&file=' + encodeURIComponent(filename);
    if (imgEl) imgEl.src = proofUrl;
    if (dlBtn) dlBtn.href = proofUrl;

    var bsModal = new bootstrap.Modal(modalEl);
    bsModal.show();
}

// Open Record Payment Modal (Admin)
function openRecordPaymentModal(scheduleId, contractId, instNum, monthlyAmount, customerName) {
    var modalEl = document.getElementById('recordPaymentModal');
    if (!modalEl) return;

    document.getElementById('rec_schedule_id').value = scheduleId;
    document.getElementById('rec_contract_id').value = contractId;
    document.getElementById('rec_customer_name').value = customerName;
    document.getElementById('rec_inst_display').value = 'Installment #' + instNum;
    document.getElementById('rec_amount_paid').value = monthlyAmount.toFixed(2);
    document.getElementById('recPaySubtitle').textContent = 'Booking #' + contractId + ' &bull; Installment #' + instNum;

    // Set default payment date to now
    var now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('rec_payment_date').value = now.toISOString().slice(0, 16);

    var bsModal = new bootstrap.Modal(modalEl);
    bsModal.show();
}

// Submit Admin Record Payment
function submitRecordPayment(e) {
    e.preventDefault();
    var form = document.getElementById('recordPaymentForm');
    var submitBtn = document.getElementById('recSubmitBtn');
    if (!form || !submitBtn) return;

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';

    var formData = new FormData(form);

    fetch('monthly_payments.php', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            alert('Success: ' + data.message);
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to record payment.'));
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check me-1"></i> Confirm &amp; Mark as Paid';
        }
    })
    .catch(function(err) {
        console.error(err);
        alert('Server error occurred while recording payment.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-check me-1"></i> Confirm &amp; Mark as Paid';
    });
}

// Send Payment Reminder Email (Admin)
function sendPaymentReminder(scheduleId, instNum, btn) {
    if (!confirm('Are you sure you want to send an official payment reminder for Installment #' + instNum + '?')) {
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    }

    var formData = new FormData();
    formData.append('action', 'send_payment_reminder');
    formData.append('schedule_id', scheduleId);

    fetch('monthly_payments.php', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            alert('Notification: ' + data.message);
        } else {
            alert('Reminder Error: ' + (data.message || 'Unable to send reminder.'));
        }
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-bell text-warning"></i> Remind';
        }
    })
    .catch(function(err) {
        console.error(err);
        alert('Failed to connect to reminder service.');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-bell text-warning"></i> Remind';
        }
    });
}

// Live search in Admin Accounts Directory Modal
function searchAccountsDirectory(term) {
    var filter = term.toLowerCase().trim();
    var rows = document.querySelectorAll('.dir-row');
    rows.forEach(function(row) {
        var text = (row.getAttribute('data-text') || '') + ' ' + row.innerText.toLowerCase();
        if (!filter || text.indexOf(filter) !== -1) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

</body>
</html>

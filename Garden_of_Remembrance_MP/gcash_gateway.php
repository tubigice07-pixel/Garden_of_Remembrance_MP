<?php
include 'connection.php';
require_once __DIR__ . '/config/payment_settings.php';
if (file_exists(__DIR__ . '/config/installment_helper.php')) {
    require_once __DIR__ . '/config/installment_helper.php';
}
require_once __DIR__ . '/config/payment_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    // Guests may pay only for guest bookings created in this same browser session
    // (session allow-list), or with a valid single-booking pay token.
    $guestIds = (isset($_SESSION['guest_booking_ids']) && is_array($_SESSION['guest_booking_ids'])) ? array_map('intval', $_SESSION['guest_booking_ids']) : [];
    $gid = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
    if ($gid <= 0 && isset($_POST['booking_id'])) { $gid = intval($_POST['booking_id']); }
    $guestPaySecret = 'GOR_GUEST_PAY_2026_KEY';
    $pToken = isset($_GET['pay_token']) ? (string)$_GET['pay_token'] : (isset($_POST['pay_token']) ? (string)$_POST['pay_token'] : '');
    $tokenOk = ($gid > 0 && $pToken !== '' && hash_equals(hash_hmac('sha256', (string)$gid, $guestPaySecret), $pToken));
    if (($gid <= 0 || !in_array($gid, $guestIds, true)) && !$tokenOk) {
        header("Location: index.php");
        exit;
    }
}

$session_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_admin = isset($_SESSION['role']) && strtoupper($_SESSION['role']) === 'ADMIN';

$gcash_payment = get_gcash_payment_settings();

if (isset($_GET['source']) && in_array(strtolower($_GET['source']), ['admin','user','guest'], true)) {
    $_SESSION['payment_source'] = strtolower($_GET['source']);
}

$return_url = '';
if (isset($_GET['return_url']) && !empty($_GET['return_url'])) {
    $return_url = (string)$_GET['return_url'];
    $_SESSION['gcash_return_url'] = $return_url;
} elseif (isset($_POST['return_url']) && !empty($_POST['return_url'])) {
    $return_url = (string)$_POST['return_url'];
    $_SESSION['gcash_return_url'] = $return_url;
} elseif (isset($_SESSION['gcash_return_url'])) {
    $return_url = (string)$_SESSION['gcash_return_url'];
}
if (!empty($return_url)) {
    $parsedReturn = parse_url($return_url);
    if (isset($parsedReturn['host']) && isset($_SERVER['HTTP_HOST'])) {
        $currHost = explode(':', $_SERVER['HTTP_HOST'])[0];
        $retHost = explode(':', $parsedReturn['host'])[0];
        if (strtolower($currHost) !== strtolower($retHost)) {
            $return_url = '';
        }
    }
}

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
if ($booking_id <= 0 && isset($_POST['booking_id'])) {
    $booking_id = intval($_POST['booking_id']);
}

$installment_id = isset($_GET['installment_id']) ? intval($_GET['installment_id']) : (isset($_GET['schedule_id']) ? intval($_GET['schedule_id']) : 0);
if ($installment_id <= 0 && isset($_POST['installment_id'])) {
    $installment_id = intval($_POST['installment_id']);
}
if ($installment_id <= 0 && isset($_POST['schedule_id'])) {
    $installment_id = intval($_POST['schedule_id']);
}

$installment_num = isset($_GET['installment']) ? intval($_GET['installment']) : 0;
if ($installment_num <= 0 && isset($_POST['installment'])) {
    $installment_num = intval($_POST['installment']);
}

$is_user_request = (isset($_GET['source']) && strtolower($_GET['source']) === 'user') 
    || (isset($_SESSION['payment_source']) && $_SESSION['payment_source'] === 'user') 
    || (!$is_admin && $session_user_id > 0);

$message = '';
$payment_reference = '';
$payment_success = false;

$booking = null;
if ($booking_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $booking_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $booking = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($booking && !$is_admin && (int)$booking['user_id'] !== $session_user_id) {
    http_response_code(403);
    die("Unauthorized access to this booking.");
}

$total_booking_amount = 0.00;
$total_paid = 0.00;
$remaining_balance = 0.00;
$current_payment_due = 0.00;
$target_schedule_id = null;
$target_installment_number = null;
$is_installment = false;
$is_downpayment_pending = false;
$downpayment_required = 0.00;

if ($booking) {

    $plot_price = floatval($booking['plot_price'] ?? 0);
    if ($plot_price <= 0) {
        $plot_price = 85000.00;
    }
    $services_cost = floatval($booking['service_price'] ?? 0) + floatval($booking['interment_fee'] ?? 0);
    $standard_total = round($plot_price + $services_cost, 2);

    $total_booking_amount = floatval($booking['total_amount'] ?? 0);
    // Self-heal: If total amount was inflated with previous interest markup (e.g. 86,369.60 instead of 85,000.00)
    if ($total_booking_amount <= 0 || ($plot_price > 0 && $total_booking_amount > $standard_total + 0.05)) {
        $total_booking_amount = $standard_total;
        $conn->query("UPDATE tbl_bookings SET total_amount = {$total_booking_amount}, remaining_balance = GREATEST(0.00, {$total_booking_amount} - amount_paid) WHERE id = {$booking_id}");
    }

    $totRes = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) AS verified_total FROM payment_receipts WHERE booking_id = $booking_id AND UPPER(COALESCE(payment_status, '')) IN ('PAID', 'VERIFIED')");
    if ($totRes && ($tRow = $totRes->fetch_assoc())) {
        $total_paid = floatval($tRow['verified_total']);
    }

    $remaining_balance = max(0.00, round($total_booking_amount - $total_paid, 2));

    $is_installment = strtolower(trim((string)($booking['payment_plan'] ?? ''))) === 'installment';

    // Calculate required downpayment for installment plans
    $plot_price = floatval($booking['plot_price'] ?? 0);
    if ($plot_price <= 0) {
        $plot_price = 85000.00;
    }
    $downpayment_required = floatval($booking['downpayment'] ?? 0);
    if ($downpayment_required <= 0 && $is_installment) {
        if (function_exists('calculate_installment_plan')) {
            $pTerm = max(1, (int)($booking['payment_term'] ?? 12));
            $nLots = max(1, (int)($booking['num_lots'] ?? 1));
            $plan = calculate_installment_plan($pTerm, $nLots, $plot_price);
            $downpayment_required = floatval($plan['downpayment'] ?? 0);
        }
        if ($downpayment_required <= 0) {
            $downpayment_required = round($plot_price * 0.20, 2);
        }
    }

    // Downpayment is pending if this is an installment contract and total verified paid is less than required downpayment
    $is_downpayment_pending = ($is_installment && $downpayment_required > 0 && $total_paid < ($downpayment_required - 0.01));

    // When a user is paying a monthly installment, downpayment is not pending
    $is_paying_installment = false;
    if ($is_user_request && $is_installment) {
        if ($installment_num > 0 || $installment_id > 0) {
            $is_paying_installment = true;
        } elseif (isset($_GET['amount']) && floatval($_GET['amount']) > 0 && floatval($_GET['amount']) < ($remaining_balance - 0.05) && abs(floatval($_GET['amount']) - $downpayment_required) > 0.05) {
            $is_paying_installment = true;
        }
    }

    if ($is_paying_installment) {
        $is_downpayment_pending = false;
    }

    // Self-healing: Ensure installment schedules start next month (+ i months) on exact day, following downpayment
    $start_date_ts = !empty($booking['created_at']) ? strtotime($booking['created_at']) : time();
    $createdAtDate = date('Y-m-d', $start_date_ts);
    $chkFirstInst = $conn->query("SELECT id, due_date FROM payment_schedules WHERE contract_id = $booking_id AND installment_number = 1 LIMIT 1");
    if ($chkFirstInst && ($fRow = $chkFirstInst->fetch_assoc())) {
        if ($fRow['due_date'] <= $createdAtDate) {
            $allSch = $conn->query("SELECT id, installment_number FROM payment_schedules WHERE contract_id = $booking_id ORDER BY installment_number ASC");
            if ($allSch) {
                while ($sRow = $allSch->fetch_assoc()) {
                    $iNum = (int)$sRow['installment_number'];
                    $correctDue = date('Y-m-d', strtotime("+ {$iNum} month", $start_date_ts));
                    $conn->query("UPDATE payment_schedules SET due_date = '{$correctDue}' WHERE id = " . (int)$sRow['id']);
                    $conn->query("UPDATE booking_payment_schedule SET due_date = '{$correctDue}' WHERE booking_id = $booking_id AND installment_number = {$iNum}");
                }
            }
        }
    }

    if ($is_downpayment_pending) {
        // Customer must pay the downpayment first; no installment due yet
        $current_payment_due = min(round($downpayment_required - $total_paid, 2), $remaining_balance);
        $target_schedule_id = null;
        $target_installment_number = null;
    } elseif ($is_installment) {
        // Look for specified installment or next pending monthly installment
        if ($installment_num > 0) {
            $chkSpecific = $conn->query("SELECT id, installment_number, monthly_amount, due_date FROM payment_schedules WHERE contract_id = $booking_id AND installment_number = $installment_num LIMIT 1");
            if ($chkSpecific && ($sRow = $chkSpecific->fetch_assoc())) {
                $target_schedule_id = (int)$sRow['id'];
                $target_installment_number = (int)$sRow['installment_number'];
                $current_payment_due = min(floatval($sRow['monthly_amount']), $remaining_balance);
            } else {
                $chkSpecific2 = $conn->query("SELECT id, installment_number, amount_due, amount_paid FROM booking_payment_schedule WHERE booking_id = $booking_id AND installment_number = $installment_num LIMIT 1");
                if ($chkSpecific2 && ($sRow2 = $chkSpecific2->fetch_assoc())) {
                    $target_installment_number = (int)$sRow2['installment_number'];
                    $dueAmt = max(0.00, floatval($sRow2['amount_due']) - floatval($sRow2['amount_paid']));
                    $current_payment_due = min($dueAmt, $remaining_balance);
                }
            }
        }

        if ($current_payment_due <= 0) {
            $chkNext = $conn->query("SELECT id, installment_number, monthly_amount, due_date FROM payment_schedules WHERE contract_id = $booking_id AND status != 'paid' ORDER BY installment_number ASC LIMIT 1");
            if ($chkNext && ($nRow = $chkNext->fetch_assoc())) {
                $target_schedule_id = (int)$nRow['id'];
                $target_installment_number = (int)$nRow['installment_number'];
                $current_payment_due = min(floatval($nRow['monthly_amount']), $remaining_balance);
            } else {
                $chkNext2 = $conn->query("SELECT id, installment_number, amount_due, amount_paid FROM booking_payment_schedule WHERE booking_id = $booking_id AND UPPER(status) != 'PAID' ORDER BY installment_number ASC LIMIT 1");
                if ($chkNext2 && ($nRow2 = $chkNext2->fetch_assoc())) {
                    $target_installment_number = (int)$nRow2['installment_number'];
                    $dueAmt = max(0.00, floatval($nRow2['amount_due']) - floatval($nRow2['amount_paid']));
                    $current_payment_due = min($dueAmt, $remaining_balance);
                } else {
                    $pTerm = max(1, (int)($booking['payment_term'] ?? 12));
                    $nLots = max(1, (int)($booking['num_lots'] ?? 1));
                    if (function_exists('calculate_installment_plan')) {
                        $plan = calculate_installment_plan($pTerm, $nLots, $plot_price);
                        $current_payment_due = min(floatval($plan['monthly_fee']), $remaining_balance);
                    } else {
                        $current_payment_due = $remaining_balance;
                    }
                }
            }
        }

        if ($is_paying_installment && isset($_GET['amount']) && floatval($_GET['amount']) > 0) {
            $current_payment_due = min(floatval($_GET['amount']), $remaining_balance);
        }
    } else {
        $current_payment_due = $remaining_balance;
    }
}

$amount = $current_payment_due > 0 ? $current_payment_due : $remaining_balance;
if ($amount <= 0 && $remaining_balance > 0) {
    $amount = $remaining_balance;
}

if ($is_downpayment_pending) {
    // If downpayment is pending, always default to the downpayment amount
    $amount = $current_payment_due;
    if (isset($_GET['amount']) && floatval($_GET['amount']) > 0) {
        $requestedAmt = floatval($_GET['amount']);
        if ($requestedAmt <= ($remaining_balance + 0.01)) {
            $amount = $requestedAmt;
        }
    } elseif (isset($_POST['amount']) && floatval($_POST['amount']) > 0) {
        $requestedAmt = floatval($_POST['amount']);
        if ($requestedAmt <= ($remaining_balance + 0.01)) {
            $amount = $requestedAmt;
        }
    }
} else {
    if (isset($_GET['amount']) && floatval($_GET['amount']) > 0) {
        $requestedAmt = floatval($_GET['amount']);
        if ($requestedAmt <= ($remaining_balance + 0.01) || $remaining_balance <= 0) {
            $amount = $requestedAmt;
        }
    } elseif (isset($_POST['amount']) && floatval($_POST['amount']) > 0) {
        $requestedAmt = floatval($_POST['amount']);
        if ($requestedAmt <= ($remaining_balance + 0.01) || $remaining_balance <= 0) {
            $amount = $requestedAmt;
        }
    }
}

$merchant_data = [
    'name' => $gcash_payment['account_name'],
    'id' => $gcash_payment['merchant_id'],
    'order' => $booking ? ('ORD-' . $booking['id']) : ('ORD-' . time()),
    'amount' => $amount,
    'currency' => 'PHP',
    'mobile' => $gcash_payment['number'],
    'display_mobile' => $gcash_payment['display_number'],
];

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['process_payment', 'simulate_payment'], true)) {
    if ($booking_id <= 0 || !$booking) {
        $message = 'Invalid booking ID.';
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
    } else {
        $submitted_amount = isset($_POST['amount']) ? floatval($_POST['amount']) : $amount;
        if ($submitted_amount <= 0) {
            $message = 'Invalid payment amount.';
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
        } elseif ($remaining_balance > 0 && $submitted_amount > ($remaining_balance + 0.01)) {
            $message = 'Payment amount (₱' . number_format($submitted_amount, 2) . ') exceeds the remaining balance of ₱' . number_format($remaining_balance, 2) . '.';
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
        }

        $user_ref = isset($_POST['reference_number']) ? trim($_POST['reference_number']) : '';
        $reference = !empty($user_ref) ? $user_ref : ('GC' . date('Ymd') . rand(100000, 999999));
        $booking_user_id = (int)$booking['user_id'];

        if (function_exists('check_duplicate_payment_submission') && check_duplicate_payment_submission($conn, $booking_id, $reference)) {
            $message = 'This GCash reference (' . htmlspecialchars($reference) . ') has already been submitted for this booking.';
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
        }

        $proof_filename = null;
        if (!empty($_FILES['proof_file']['name']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['proof_file'];
            $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($file_ext, $allowed_exts, true) && $file['size'] <= 5 * 1024 * 1024) {
                $target_dir = __DIR__ . '/uploads/payment-proofs/';
                if (!is_dir($target_dir)) {
                    @mkdir($target_dir, 0777, true);
                }
                $new_filename = 'proof_' . $booking_id . '_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
                if (move_uploaded_file($file['tmp_name'], $target_dir . $new_filename)) {
                    $proof_filename = $new_filename;
                }
            }
        }

        $tx_ref = 'TXN-GC-' . $booking_id . '-' . time();
        $stmtPR = $conn->prepare("INSERT INTO payment_receipts 
            (booking_id, user_id, payment_schedule_id, installment_number, amount_paid, payment_method, gcash_reference_number, transaction_reference, payment_date, payment_status, proof_filename, created_at) 
            VALUES (?, ?, ?, ?, ?, 'GCash', ?, ?, NOW(), 'PENDING', ?, NOW())");

        $newReceiptId = 0;
        if ($stmtPR) {
            $stmtPR->bind_param('iiiidsss', $booking_id, $booking_user_id, $target_schedule_id, $target_installment_number, $submitted_amount, $reference, $tx_ref, $proof_filename);
            $stmtPR->execute();
            $newReceiptId = (int)$stmtPR->insert_id;
            $stmtPR->close();

            if ($newReceiptId > 0) {
                $rcptNum = sprintf("RCPT-%s-%04d", date('Ymd'), $newReceiptId);
                $conn->query("UPDATE payment_receipts SET receipt_number = '{$rcptNum}' WHERE id = {$newReceiptId}");
            }
        }

        $bTotal = 0.00;
        $tRes = $conn->query("SELECT total_amount, plot_price FROM tbl_bookings WHERE id = " . intval($booking_id));
        if ($tRes && ($tRow = $tRes->fetch_assoc())) {
            $bTotal = floatval($tRow['total_amount'] ?? ($tRow['plot_price'] ?? 0));
        }
        $isFull = ($bTotal > 0 && $submitted_amount >= ($bTotal - 0.009));
        $newRemaining = max(0.00, $bTotal - $submitted_amount);
        // Do NOT prematurely mark as PAID upon returning from GCash.
        // Payment proof upload and admin verification are strictly required first.
        $newPayStatus = $proof_filename ? 'FOR VERIFICATION' : 'PENDING';

        if (!empty($target_installment_number) || !empty($target_schedule_id)) {
            // Installment payment: Do NOT overwrite tbl_bookings downpayment proof, reference, or reset amount_paid
            if (!empty($target_schedule_id)) {
                $conn->query("UPDATE payment_schedules SET status = 'pending_verification' WHERE id = " . intval($target_schedule_id));
                $conn->query("UPDATE booking_payment_schedule SET status = 'PENDING' WHERE id = " . intval($target_schedule_id));
            }
        } else {
            $stmtUpdate = $conn->prepare("UPDATE tbl_bookings SET 
                payment_type = 'GCash', 
                reference_number = ?, 
                gcash_reference_number = ?, 
                bank_name = 'GCash', 
                amount_paid = 0.00,
                remaining_balance = ?,
                payment_status = ?,
                payment_proof_uploaded_at = NOW()" . 
                ($proof_filename ? ", payment_proof_filename = '{$proof_filename}'" : "") . " 
                WHERE id = ?");
            if ($stmtUpdate) {
                $stmtUpdate->bind_param('ssdsi', $reference, $reference, $newRemaining, $newPayStatus, $booking_id);
                $stmtUpdate->execute();
                $stmtUpdate->close();
            }

            // Also update guest_reservation_requests if this is a guest reservation
            $chkGrr = $conn->query("SHOW TABLES LIKE 'guest_reservation_requests'");
            if ($chkGrr && $chkGrr->num_rows > 0) {
                $stmtGrr = $conn->prepare("UPDATE guest_reservation_requests SET 
                    status = 'Pending',
                    payment_status = ?,
                    payment_amount = ?,
                    payment_date = NOW(),
                    gcash_reference = ?,
                    payment_receipt_id = ?" .
                    ($proof_filename ? ", proof_filename = ?" : "") . "
                    WHERE booking_id = ?");
                if ($stmtGrr) {
                    if ($proof_filename) {
                        $stmtGrr->bind_param('sdsisi', $newPayStatus, $submitted_amount, $reference, $newReceiptId, $proof_filename, $booking_id);
                    } else {
                        $stmtGrr->bind_param('sdsii', $newPayStatus, $submitted_amount, $reference, $newReceiptId, $booking_id);
                    }
                    $stmtGrr->execute();
                    $stmtGrr->close();
                }
            }
        }

        $logChk = $conn->query("SHOW TABLES LIKE 'tbl_logs'");
        if ($logChk && $logChk->num_rows > 0) {
            $logAction = 'PAYMENT_SUBMITTED';
            $logDetails = "GCash payment #{$newReceiptId} of ₱" . number_format($submitted_amount, 2) . " submitted for Reservation #{$booking_id} (PENDING ADMIN VERIFICATION). GCash Ref: {$reference}";
            $lStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, ?, NOW())");
            if ($lStmt) {
                $lStmt->bind_param('ssi', $logAction, $logDetails, $session_user_id);
                $lStmt->execute();
                $lStmt->close();
            }
        }

        if (file_exists(__DIR__ . '/includes/notification_helper.php')) {
            require_once __DIR__ . '/includes/notification_helper.php';
            if (function_exists('create_admin_notification')) {
                create_admin_notification(
                    $conn,
                    'New GCash Payment Submission',
                    "Payment of ₱" . number_format($submitted_amount, 2) . " submitted for Reservation #{$booking_id}. Ref: {$reference}. Pending verification.",
                    'PAYMENT_SUBMITTED',
                    $booking_id
                );
            }
        }

        $payment_success = true;
        $payment_reference = $reference;

        $_SESSION['gcash_tx_ref'] = $reference;
        $_SESSION['booking_id'] = $booking_id;
        $_SESSION['gcash_tx_data'] = [
            'booking_id' => $booking_id,
            'payment_id' => $newReceiptId,
            'amount' => number_format($submitted_amount, 2),
            'reference' => $reference,
            'datetime' => date('Y-m-d H:i:s'),
            'status' => 'PENDING'
        ];

        $isPaymentAdmin = (isset($_GET['source']) && strtolower($_GET['source']) === 'admin') || ((isset($_SESSION['payment_source']) && $_SESSION['payment_source'] === 'admin') && $is_admin);
        $isPaymentUser = (isset($_GET['source']) && strtolower($_GET['source']) === 'user') || ($session_user_id > 0 && $booking && (int)($booking['user_id'] ?? 0) === $session_user_id && !$isPaymentAdmin);
        $isPaymentGuest = !$isPaymentAdmin && !$isPaymentUser && ((isset($_GET['source']) && strtolower($_GET['source']) === 'guest') || !isset($_SESSION['user_id']) || $session_user_id <= 0);

        if (!empty($return_url)) {
            $sep = (strpos($return_url, '?') !== false) ? '&' : '?';
            $redirectUrl = $return_url . $sep . 
                'payment_status=pending_proof' .
                '&gcash_paid=1' .
                '&payment_ref=' . urlencode($reference) . 
                '&payment_amount=' . urlencode(number_format($submitted_amount, 2, '.', '')) . 
                '&payment_id=' . $newReceiptId . 
                '&transaction_ref=' . urlencode($tx_ref) . 
                '&payment_date=' . urlencode(date('M d, Y h:i A'));
            unset($_SESSION['gcash_return_url']);
        } else {
            // Build return URL requiring payment proof upload before verification
            if ($isPaymentAdmin) {
                $redirectUrl = 'dashboard.php?booking_pay_return=1'
                    . '&booking_id=' . $booking_id
                    . '&payment_status=pending_proof'
                    . '&gcash_paid=1'
                    . '&payment_ref=' . urlencode($reference)
                    . '&payment_amount=' . urlencode(number_format($submitted_amount, 2, '.', ''))
                    . '&payment_id=' . $newReceiptId
                    . '&transaction_ref=' . urlencode($tx_ref)
                    . '&payment_date=' . urlencode(date('M d, Y h:i A'));
            } elseif ($isPaymentGuest) {
                $redirectUrl = 'sections/popup.php?guest_pay_return=1&booking_id=' . $booking_id . '&payment_status=pending_proof&gcash_paid=1&payment_ref=' . urlencode($reference) . '&payment_amount=' . urlencode(number_format($submitted_amount, 2, '.', '')) . '&payment_id=' . $newReceiptId;
            } else {
                $redirectUrl = 'user_dashboard.php?booking_id=' . $booking_id
                    . '&payment_status=pending_proof'
                    . '&gcash_paid=1'
                    . '&payment_ref=' . urlencode($reference)
                    . '&payment_amount=' . urlencode(number_format($submitted_amount, 2, '.', ''))
                    . '&payment_id=' . $newReceiptId
                    . '&transaction_ref=' . urlencode($tx_ref)
                    . '&payment_date=' . urlencode(date('M d, Y h:i A'));
            }
        }

        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'reference' => $payment_reference,
                'payment_id' => $newReceiptId,
                'transaction_ref' => $tx_ref,
                'payment_date' => date('M d, Y h:i A'),
                'amount_paid' => $submitted_amount,
                'redirect' => $redirectUrl,
                'return_url' => $return_url,
                'payment_source' => $isPaymentAdmin ? 'admin' : ($isPaymentGuest ? 'guest' : 'user'),
                'message' => 'GCash payment submitted successfully and is pending admin verification.'
            ]);
            exit;
        } else {
            header("Location: " . $redirectUrl);
            exit;
        }
    }
}

$plot_label = $booking ? ($booking['grave_location'] ?? ('Section ' . ($booking['section'] ?? '') . ' ' . ($booking['plot'] ?? ''))) : '';
$service_label = !empty($booking['service_type']) ? $booking['service_type'] : 'Plot Reservation';

$bSec = (string)($booking['section'] ?? '');
$bPlot = (string)($booking['plot'] ?? '');
$bPx = 0;
$bPy = 0;
if (preg_match('/(\d+)\s*[-,\s]\s*(\d+)/', $bPlot, $mP)) {
    $bPx = (int)$mP[1];
    $bPy = (int)$mP[2];
}
if (($bPx <= 0 || $bPy <= 0 || empty($bSec)) && $booking_id > 0) {
    $grrQ = $conn->query("SELECT section, plot_x, plot_y FROM guest_reservation_requests WHERE booking_id = " . intval($booking_id) . " ORDER BY id DESC LIMIT 1");
    if ($grrQ && ($grrRow = $grrQ->fetch_assoc())) {
        if (empty($bSec)) $bSec = (string)($grrRow['section'] ?? '');
        if ($bPx <= 0) $bPx = (int)($grrRow['plot_x'] ?? 0);
        if ($bPy <= 0) $bPy = (int)($grrRow['plot_y'] ?? 0);
    }
}
$guestToken = hash_hmac('sha256', (string)$booking_id, 'GOR_GUEST_PAY_2026_KEY');

$isPaymentAdmin = (isset($_GET['source']) && strtolower($_GET['source']) === 'admin') || ((isset($_SESSION['payment_source']) && $_SESSION['payment_source'] === 'admin') && $is_admin);
$isPaymentUser = (isset($_GET['source']) && strtolower($_GET['source']) === 'user') || ($session_user_id > 0 && $booking && (int)($booking['user_id'] ?? 0) === $session_user_id && !$isPaymentAdmin);
$isPaymentGuest = !$isPaymentAdmin && !$isPaymentUser && ((isset($_GET['source']) && strtolower($_GET['source']) === 'guest') || !isset($_SESSION['user_id']) || $session_user_id <= 0);

if ($isPaymentUser) {
    $back_url = 'user_dashboard.php?user_pay_return=1&payment_status=cancelled&booking_id=' . $booking_id . '&open_booking_modal=1';
} elseif (!empty($return_url)) {
    $cleanReturn = preg_replace('/([?&])booking_fillup_return=[^&]*/i', '$1', $return_url);
    $cleanReturn = preg_replace('/([?&])user_pay_return=[^&]*/i', '$1', $cleanReturn);
    $cleanReturn = preg_replace('/([?&])payment_status=[^&]*/i', '$1', $cleanReturn);
    $cleanReturn = preg_replace('/([?&])booking_id=[^&]*/i', '$1', $cleanReturn);
    $cleanReturn = preg_replace('/[&?]+$/', '', $cleanReturn);
    $cancelSep = (strpos($cleanReturn, '?') !== false) ? '&' : '?';
    $back_url = $cleanReturn . $cancelSep . 'payment_status=cancelled&booking_id=' . $booking_id;
    if ($isPaymentGuest) {
        if (strpos($back_url, 'section=') === false && !empty($bSec)) {
            $back_url .= '&section=' . urlencode($bSec);
        }
        if (strpos($back_url, 'plot_x=') === false && $bPx > 0) {
            $back_url .= '&plot_x=' . $bPx;
        }
        if (strpos($back_url, 'plot_y=') === false && $bPy > 0) {
            $back_url .= '&plot_y=' . $bPy;
        }
        if (strpos($back_url, 'pay_token=') === false && !empty($guestToken)) {
            $back_url .= '&pay_token=' . urlencode($guestToken);
        }
        if (strpos($back_url, 'guest_pay_return=') === false) {
            $back_url .= '&guest_pay_return=1';
        }
    }
} else {
    if ($isPaymentAdmin) {
        $back_url = 'dashboard.php?booking_pay_return=1&payment_status=cancelled&booking_id=' . $booking_id;
    } elseif ($isPaymentGuest) {
        $back_url = 'sections/popup.php?section=' . urlencode($bSec ?: 'A') . '&plot_x=' . $bPx . '&plot_y=' . $bPy . '&guest_pay_return=1&payment_status=cancelled&booking_id=' . $booking_id . '&pay_token=' . urlencode($guestToken);
    } else {
        $back_url = 'user_dashboard.php?user_pay_return=1&payment_status=cancelled&booking_id=' . $booking_id . '&open_booking_modal=1';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>GCash Payment • Garden of Remembrance</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --gcash-blue: #007dfe;
            --gcash-primary: #005ce6;
            --gcash-dark: #0045ad;
            --gcash-light: #f0f7ff;
            --gcash-pill: #e1effe;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --border-subtle: #e2e8f0;
            --border-card: #e5edf7;
            --bg-card: #ffffff;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: radial-gradient(circle at 50% 0%, #e0edff 0%, #edf3fa 55%, #e2eaf5 100%);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 14px;
        }

        .checkout-container {
            background: var(--bg-card);
            border-radius: 26px;
            width: 100%;
            max-width: 460px;
            margin: 0 auto;
            box-shadow: 0 20px 50px -10px rgba(0, 75, 190, 0.16), 0 0 0 1px rgba(0, 75, 190, 0.06);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* Clean Nav Header */
        .checkout-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: #ffffff;
            border-bottom: 1px solid var(--border-subtle);
        }

        .brand-cluster {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-logo {
            height: 26px;
            width: auto;
            display: block;
        }

        .brand-divider {
            width: 1px;
            height: 16px;
            background: #cbd5e1;
        }

        .brand-sub {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 170px;
        }

        .btn-cancel {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .btn-cancel:hover {
            color: #ef4444;
            background: #fef2f2;
        }

        /* Main Vertical Body */
        .checkout-body {
            padding: 20px 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            background: #ffffff;
        }

        /* Amount Hero Banner */
        .amount-hero-card {
            background: linear-gradient(145deg, #007dfe 0%, #005ce6 100%);
            border-radius: 20px;
            padding: 18px 16px;
            color: #ffffff;
            text-align: center;
            box-shadow: 0 8px 24px -4px rgba(0, 92, 230, 0.35);
            position: relative;
            overflow: hidden;
        }

        .amount-hero-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .merchant-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.18);
            backdrop-filter: blur(4px);
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 600;
            margin-bottom: 8px;
            letter-spacing: 0.01em;
        }

        .amount-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            opacity: 0.9;
            font-weight: 700;
        }

        .amount-value {
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1.15;
            margin: 4px 0 8px 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .order-pill-badge {
            display: inline-flex;
            align-items: center;
            background: rgba(0, 0, 0, 0.15);
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* 4 Key Financial Metrics Grid */
        .metrics-card {
            background: #f8fafc;
            border: 1px solid var(--border-subtle);
            border-radius: 16px;
            padding: 12px 14px;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .metric-item {
            background: #ffffff;
            border: 1px solid #edf2f7;
            border-radius: 12px;
            padding: 8px 10px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .metric-label {
            font-size: 0.70rem;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .metric-value {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--text-primary);
        }

        .metric-highlight {
            border-color: #bfdbfe;
            background: #f0f7ff;
        }

        .metric-highlight .metric-label {
            color: var(--gcash-primary);
        }

        .metric-highlight .metric-value {
            color: var(--gcash-primary);
        }

        /* Payment Option Selector */
        .payment-options-card {
            background: #ffffff;
            border: 1px solid var(--border-subtle);
            border-radius: 16px;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .section-sub-heading {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text-primary);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .options-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .option-radio-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1.5px solid var(--border-subtle);
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.84rem;
        }

        .option-radio-label:hover {
            border-color: #93c5fd;
            background: #f8fbff;
        }

        .option-radio-label.selected {
            border-color: var(--gcash-primary);
            background: #eff6ff;
            color: var(--gcash-primary);
            font-weight: 700;
        }

        .option-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .option-amount {
            font-weight: 800;
        }

        .custom-amount-box {
            margin-top: 4px;
            display: none;
        }

        .custom-amount-box.active {
            display: block;
        }

        .input-currency-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .currency-prefix {
            position: absolute;
            left: 12px;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-secondary);
        }

        .form-control-custom {
            width: 100%;
            padding: 9px 12px 9px 28px;
            font-size: 0.95rem;
            font-weight: 700;
            font-family: inherit;
            border: 1.5px solid var(--border-subtle);
            border-radius: 10px;
            outline: none;
            transition: border-color 0.2s;
            color: var(--text-primary);
        }

        .form-control-custom:focus {
            border-color: var(--gcash-primary);
            box-shadow: 0 0 0 3px rgba(0, 92, 230, 0.15);
        }

        .validation-alert {
            font-size: 0.76rem;
            color: #ef4444;
            margin-top: 4px;
            display: none;
        }

        /* GCash Logo Banner */
        .gcash-logo-banner {
            background: linear-gradient(135deg, #e8f1ff 0%, #f0f7ff 100%);
            border: 1.5px solid #bfdbfe;
            border-radius: 20px;
            padding: 18px 16px 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 10px;
        }

        .gcash-logo-banner img {
            height: 52px;
            width: auto;
            object-fit: contain;
            display: block;
        }

        .gcash-logo-banner .gcash-banner-label {
            font-size: 0.80rem;
            color: var(--text-secondary);
            line-height: 1.4;
        }

        /* Account Box (1-Click Copy) */
        .merchant-account-card {
            background: #ffffff;
            border: 1px solid var(--border-subtle);
            border-radius: 16px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .account-details {
            display: flex;
            flex-direction: column;
            gap: 2px;
            overflow: hidden;
        }

        .account-label {
            font-size: 0.72rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 600;
        }

        .account-name {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .account-number {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--gcash-primary);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            letter-spacing: 0.04em;
        }

        .btn-copy-action {
            background: var(--gcash-pill);
            color: var(--gcash-primary);
            border: none;
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .btn-copy-action:hover {
            background: #d0e5fc;
        }

        /* Payment Submission Form */
        .submission-card {
            background: #ffffff;
            border: 1px solid var(--border-subtle);
            border-radius: 16px;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .form-label-small {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-secondary);
        }

        .form-input-text {
            width: 100%;
            padding: 9px 12px;
            font-size: 0.88rem;
            font-family: inherit;
            border: 1.5px solid var(--border-subtle);
            border-radius: 10px;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-input-text:focus {
            border-color: var(--gcash-primary);
        }

        /* Verification Workflow Notice */
        .verification-notice {
            background: #fffbeb;
            border: 1px solid #fef3c7;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 0.76rem;
            color: #92400e;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            line-height: 1.4;
        }

        /* Action & Trust Footer */
        .action-footer {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 4px;
        }

        .btn-pay-now {
            background: linear-gradient(135deg, #007dfe 0%, #005ce6 100%);
            color: #ffffff;
            border: none;
            border-radius: 999px;
            width: 100%;
            padding: 14px 20px;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 6px 18px rgba(0, 92, 230, 0.32);
            transition: all 0.2s ease;
        }

        .btn-pay-now:hover {
            background: linear-gradient(135deg, #006ae6 0%, #004bbb 100%);
            box-shadow: 0 8px 24px rgba(0, 92, 230, 0.42);
            transform: translateY(-1px);
        }

        .btn-pay-now:active {
            transform: translateY(0);
        }

        .btn-pay-now:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .footer-trust {
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        /* Toast */
        .toast-bubble {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            background: #0f172a;
            color: #ffffff;
            padding: 9px 18px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 600;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 9999;
        }
         .toast-bubble.show {
            opacity: 1;
            transform: translateX(-50%) translateY(-4px);
        }

        /* GCash Payment Success Overlay */
        .success-overlay {
            position: fixed;
            inset: 0;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            background: rgba(15, 23, 42, 0.82);
            backdrop-filter: blur(5px);
            z-index: 999999;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 20px 12px;
            box-sizing: border-box;
        }

        .success-overlay .success-modal {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.35);
            max-width: 450px;
            width: 100%;
            margin: auto;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            max-height: calc(100vh - 36px);
        }

        .success-header-bar {
            background: linear-gradient(135deg, #005ce6 0%, #003b99 100%);
            color: #ffffff;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .btn-exit-header {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.3);
            color: #ffffff;
            cursor: pointer;
        }

        .success-top {
            padding: 16px 18px 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            border-bottom: 1px dashed var(--border);
            flex-shrink: 0;
        }

        .check-circle, .receipt-gcash-logo {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: #ffffff;
            display: grid;
            place-items: center;
            margin-bottom: 8px;
            box-shadow: 0 4px 14px rgba(0, 102, 255, 0.15);
            border: 1px solid rgba(0, 102, 255, 0.12);
        }

        .check-circle img, .receipt-gcash-logo img {
            width: 38px;
            height: 38px;
            object-fit: contain;
            display: block;
        }

        .receipt-title {
            font-weight: 800;
            font-size: 1.15rem;
            color: var(--text-primary);
            margin-bottom: 2px;
        }

        .receipt-sub {
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .receipt-content {
            padding: 10px 18px;
            overflow-y: auto;
            max-height: 48vh;
        }

        .receipt-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.86rem;
        }

        .receipt-row:last-child {
            border-bottom: none;
        }

        .meta {
            color: var(--text-secondary);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .value {
            font-weight: 600;
            color: var(--text-primary);
            text-align: right;
            font-size: 0.86rem;
        }

        .receipt-amount {
            font-size: 1.25rem;
            color: var(--gcash-primary);
            font-weight: 800;
        }

        .status-pill {
            background: #e6f7ec;
            color: #16a34a;
            font-weight: 700;
            font-size: 0.76rem;
            padding: 3px 10px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            padding: 12px 18px 16px;
            flex-shrink: 0;
        }

        .btn-action-primary, .btn-action-outline {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: none;
            padding: 10px 14px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-action-primary {
            background: var(--gcash-primary);
            color: #ffffff;
        }

        .btn-action-primary:hover {
            background: var(--gcash-dark);
        }

        .btn-action-outline {
            background: #ffffff;
            color: var(--gcash-primary);
            border: 1.5px solid var(--gcash-primary);
        }

        .btn-action-outline:hover {
            background: var(--gcash-light);
        }

        /* Mobile Adaptation (Tablets & Mobile < 640px) */
        @media (max-width: 640px) {
            body {
                padding: 10px 8px 24px 8px;
                align-items: flex-start;
            }

            .checkout-container {
                border-radius: 18px;
                width: 100%;
                max-width: 100%;
                box-shadow: 0 10px 25px -5px rgba(0, 75, 190, 0.12);
            }

            .checkout-nav {
                padding: 12px 14px;
            }

            .brand-sub {
                max-width: 140px;
                font-size: 0.8rem;
            }

            .checkout-body {
                padding: 16px 14px;
                gap: 14px;
            }

            .amount-value {
                font-size: 1.85rem;
            }

            .metrics-grid {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .metric-card {
                padding: 10px 8px;
            }

            .metric-val {
                font-size: 0.85rem;
            }

            .gcash-logo-banner img {
                height: 42px;
            }

            .btn-pay-now {
                padding: 14px 16px;
                font-size: 1rem;
                min-height: 48px;
            }

            .success-overlay {
                padding: 10px 8px !important;
            }
            .success-overlay[style*="display:none"],
            .success-overlay[style*="display: none"] {
                display: none !important;
            }

            .success-modal {
                margin: auto !important;
                width: 100% !important;
                max-width: 100% !important;
                border-radius: 16px !important;
                max-height: calc(100vh - 20px) !important;
            }

            .success-top {
                padding: 14px 14px 8px !important;
            }

            .receipt-gcash-logo {
                width: 44px !important;
                height: 44px !important;
                margin-bottom: 6px !important;
            }

            .receipt-title {
                font-size: 1.05rem !important;
                margin-bottom: 2px !important;
            }

            .receipt-sub {
                font-size: 0.76rem !important;
            }

            .status-pill {
                font-size: 0.72rem !important;
                padding: 2px 8px !important;
            }

            .receipt-row {
                padding: 5px 0 !important;
                font-size: 0.82rem !important;
            }

            .receipt-amount {
                font-size: 1.15rem !important;
            }

            .actions-grid {
                grid-template-columns: 1fr !important;
                padding: 10px 12px !important;
                gap: 6px !important;
            }

            .btn-action-primary, .btn-action-outline {
                width: 100% !important;
                min-height: 40px !important;
                font-size: 0.86rem !important;
                padding: 8px 12px !important;
            }

            .btn-exit-header {
                padding: 4px 10px !important;
                min-height: 32px !important;
                font-size: 0.82rem !important;
            }
        }
    </style>
</head>
<body>

<div class="checkout-container">

    <div class="checkout-nav">
        <div class="brand-cluster">
            <img src="assets/gcash-logo.png" alt="GCash" class="brand-logo">
            <div class="brand-divider"></div>
            <span class="brand-sub">Garden of Remembrance</span>
        </div>
        <a href="javascript:void(0)" onclick="goBackOrHome()" class="btn-cancel" id="btnCancelGateway" title="Back">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>

    <div class="checkout-body">

        <div class="amount-hero-card">
            <div class="merchant-badge">
                <span>Garden of Remembrance Memorial Park</span>
            </div>
            <div class="amount-label">Payment Amount</div>
            <div class="amount-value" id="heroAmountDisplay">₱<?php echo number_format($merchant_data['amount'], 2); ?></div>
            <div class="order-pill-badge">
                Order #<?php echo $booking ? htmlspecialchars($booking['id']) : 'N/A'; ?>
            </div>
        </div>

        <div class="metrics-card">
            <div class="metrics-grid">
                <div class="metric-item <?php echo (($is_downpayment_pending || $is_installment) && $current_payment_due > 0) ? 'metric-highlight' : ''; ?>">
                    <span class="metric-label"><?php 
                        if ($is_user_request && $is_paying_installment) {
                            echo 'Monthly Due' . ($target_installment_number > 0 ? ' (#' . $target_installment_number . ')' : '');
                        } elseif ($is_downpayment_pending) {
                            echo 'Downpayment Due';
                        } else {
                            echo 'Current Due';
                        }
                    ?></span>
                    <span class="metric-value">₱<?php echo number_format($current_payment_due, 2); ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">Total Reservation</span>
                    <span class="metric-value">₱<?php echo number_format($total_booking_amount, 2); ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">Total Paid</span>
                    <span class="metric-value text-success">₱<?php echo number_format($total_paid, 2); ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">Remaining Balance</span>
                    <span class="metric-value text-danger">₱<?php echo number_format($remaining_balance, 2); ?></span>
                </div>
            </div>
        </div>

        <?php if (!($is_user_request && $is_paying_installment)): ?>
        <div class="payment-options-card">
            <div class="section-sub-heading">
                Choose Payment Amount
            </div>

            <div class="options-group">
                <?php if ($is_downpayment_pending): ?>
                <label class="option-radio-label selected" id="labelOptionDown">
                    <div class="option-left">
                        <input type="radio" name="payment_choice" value="down" checked onchange="handlePaymentChoiceChange('down')">
                        <span>Downpayment Due</span>
                    </div>
                    <span class="option-amount text-primary">₱<?php echo number_format($current_payment_due, 2); ?></span>
                </label>
                <?php if (!$isPaymentGuest): ?>
                <label class="option-radio-label" id="labelOptionFull">
                    <div class="option-left">
                        <input type="radio" name="payment_choice" value="full" onchange="handlePaymentChoiceChange('full')">
                        <span>Full Remaining Balance</span>
                    </div>
                    <span class="option-amount">₱<?php echo number_format($remaining_balance, 2); ?></span>
                </label>
                <?php endif; ?>
                <?php else: ?>
                    <?php if ($is_installment && $current_payment_due > 0 && $current_payment_due < $remaining_balance): ?>
                    <label class="option-radio-label selected" id="labelOptionDue">
                        <div class="option-left">
                            <input type="radio" name="payment_choice" value="due" checked onchange="handlePaymentChoiceChange('due')">
                            <span>Current Installment Due</span>
                        </div>
                        <span class="option-amount text-primary">₱<?php echo number_format($current_payment_due, 2); ?></span>
                    </label>
                    <?php endif; ?>
                    <label class="option-radio-label <?php echo (!$is_installment || $current_payment_due >= $remaining_balance) ? 'selected' : ''; ?>" id="labelOptionFull">
                        <div class="option-left">
                            <input type="radio" name="payment_choice" value="full" <?php echo (!$is_installment || $current_payment_due >= $remaining_balance) ? 'checked' : ''; ?> onchange="handlePaymentChoiceChange('full')">
                            <span>Full Remaining Balance</span>
                        </div>
                        <span class="option-amount">₱<?php echo number_format($remaining_balance, 2); ?></span>
                    </label>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="gcash-logo-banner">
            <img src="assets/gcash-logo.png" alt="GCash">
            <div class="gcash-banner-label">Send payment directly to the GCash number below</div>
        </div>

        <div class="merchant-account-card">
            <div class="account-details">
                <div class="account-label">Send via GCash Number</div>
                <div class="account-name"><?php echo htmlspecialchars($merchant_data['name']); ?></div>
                <div class="account-number"><?php echo htmlspecialchars($merchant_data['display_mobile']); ?></div>
            </div>
            <button type="button" class="btn-copy-action" onclick="copyNumber('<?php echo htmlspecialchars($merchant_data['mobile']); ?>')" title="Copy GCash Number">
                Copy
            </button>
        </div>



        <div class="action-footer">
            <button id="payBtn" type="button" class="btn-pay-now">
                Submit Payment (<span id="btnPayAmount">₱<?php echo number_format($merchant_data['amount'], 2); ?></span>)
            </button>
            <div class="footer-trust">
                256-Bit SSL Encrypted • Secure Cemetery Verification
            </div>
        </div>
    </div>
</div>

<div id="toast" class="toast-bubble">Copied!</div>

<div id="paymentSuccessOverlay" class="success-overlay" style="display:none;">
  <div class="success-modal" id="printableGcashReceipt">
    <div class="success-header-bar" style="display: flex; align-items: center;">
      <div style="display: flex; align-items: center; gap: 8px;">
        <img src="assets/gcash-logo.png" alt="GCash" style="height: 22px; background: #fff; padding: 2px 6px; border-radius: 6px;">
        <span style="font-weight: 700; font-size: 0.95rem; color: #fff;">Payment Receipt</span>
      </div>
    </div>

    <div class="success-top">
      <div class="receipt-gcash-logo">
        <img src="assets/gcash-logo.png" alt="GCash">
      </div>
      <div class="receipt-title">Reservation Successfully Created!</div>
      <div class="receipt-sub">Payment submitted. Receipt ready for download.</div>
      <div class="mt-2">
        <span class="status-pill"><i class="fas fa-check-circle"></i> Status: PENDING VERIFICATION</span>
      </div>
    </div>

    <div class="receipt-content" id="successReceiptContent">
      <div class="receipt-row">
        <span class="meta">Reservation ID</span>
        <span class="value font-monospace">#<?php echo $booking_id; ?></span>
      </div>

      <?php if (!empty($gcash_payment['account_name'] || $gcash_payment['merchant_id'])): ?>
      <div class="receipt-row">
        <span class="meta">Recipient Account</span>
        <span class="value"><?php echo htmlspecialchars($gcash_payment['account_name']); ?></span>
      </div>
      <?php endif; ?>

      <?php if (!empty($gcash_payment['display_number'])): ?>
      <div class="receipt-row">
        <span class="meta">GCash Number</span>
        <span class="value font-monospace text-primary"><?php echo htmlspecialchars($gcash_payment['display_number']); ?></span>
      </div>
      <?php endif; ?>

      <div class="receipt-row">
        <span class="meta">Payment Method</span>
        <span class="value"><i class="fas fa-mobile-alt text-primary me-1"></i> GCash</span>
      </div>

      <div class="receipt-row">
        <span class="meta">Amount Paid</span>
        <span class="receipt-amount font-monospace" id="successAmount">₱<?php echo number_format($amount, 2); ?></span>
      </div>

      <div class="receipt-row">
        <span class="meta">Reference Number</span>
        <span class="value font-monospace text-dark fw-bold" id="successRef">—</span>
      </div>

      <div class="receipt-row">
        <span class="meta">Payment Date & Time</span>
        <span class="value" id="successDate">—</span>
      </div>
    </div>

    <div class="p-3 bg-light text-center border-top" style="font-size: 0.75rem; color: #64748b;">
      <i class="fas fa-info-circle me-1"></i> Save this receipt as proof of payment. Admin verification is pending.
    </div>

    <div class="actions-grid">
      <button type="button" id="downloadGcashReceipt" class="btn-action-primary">
        <i class="fas fa-download"></i> Download Receipt
      </button>
      <button type="button" id="donePaymentBtn" class="btn-action-outline">
        <i class="fas fa-arrow-left"></i> Back
      </button>
    </div>

    <div id="successDownloadNotice" style="display: none; margin: 0 24px 18px; padding: 10px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; color: #065f46; font-size: 0.82rem; text-align: center;">
      <i class="fas fa-check-circle me-1"></i> Receipt downloaded!
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
    const bookingId = <?php echo json_encode($booking_id); ?>;
    let isPaymentCompleted = false;
    window.__gcashPaymentCompleted = false;

    // Handle cancel button click - notify opener if opened in popup
    var cancelBtn = document.getElementById('btnCancelGateway');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function(e) {
            try {
                if (window.opener && !window.opener.closed) {
                    window.opener.postMessage({ type: 'gcash_payment_cancelled', booking_id: bookingId }, '*');
                    window.close();
                    e.preventDefault();
                    return false;
                }
            } catch (ex) {}
            e.preventDefault();
            window.location.href = <?php echo json_encode($back_url); ?>;
            return false;
        });
    }

    // If window is closed before paying, notify opener
    window.addEventListener('beforeunload', function() {
        if (!isPaymentCompleted) {
            try {
                if (window.opener && !window.opener.closed) {
                    window.opener.postMessage({ type: 'gcash_payment_cancelled', booking_id: bookingId }, '*');
                }
            } catch (ex) {}
        }
    });
    const currentDue = <?php echo json_encode($current_payment_due); ?>;
    const totalBooking = <?php echo json_encode($total_booking_amount); ?>;
    const totalPaid = <?php echo json_encode($total_paid); ?>;
    const remainingBalance = <?php echo json_encode($remaining_balance); ?>;
    const downAmount = <?php echo json_encode($is_downpayment_pending ? $current_payment_due : $amount); ?>;
    const isInstallment = <?php echo json_encode($is_installment); ?>;
    const isDownpaymentPending = <?php echo json_encode($is_downpayment_pending); ?>;
    const isPayingMonthly = <?php echo json_encode($is_user_request && $is_paying_installment); ?>;

    let selectedAmount = <?php echo json_encode($amount); ?>;
    let selectedChoice = '<?php echo ($is_user_request && $is_paying_installment) ? 'due' : ($is_downpayment_pending ? 'down' : (($is_installment && $current_payment_due > 0 && $current_payment_due < $remaining_balance) ? 'due' : 'full')); ?>';

    function updateDisplayAmount(amt) {
        selectedAmount = Math.max(0, amt);
        const formatted = '₱' + Number(selectedAmount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('heroAmountDisplay').textContent = formatted;
        document.getElementById('btnPayAmount').textContent = formatted;
    }

    function handlePaymentChoiceChange(choice) {
        selectedChoice = choice;
        const labelDown = document.getElementById('labelOptionDown');
        const labelDue = document.getElementById('labelOptionDue');
        const labelFull = document.getElementById('labelOptionFull');
        const payBtn = document.getElementById('payBtn');

        if (labelDown) labelDown.classList.remove('selected');
        if (labelDue) labelDue.classList.remove('selected');
        if (labelFull) labelFull.classList.remove('selected');

        if (choice === 'down') {
            if (labelDown) labelDown.classList.add('selected');
            updateDisplayAmount(downAmount > 0 ? downAmount : remainingBalance);
            payBtn.disabled = false;
        } else if (choice === 'due') {
            if (labelDue) labelDue.classList.add('selected');
            updateDisplayAmount(currentDue > 0 ? currentDue : remainingBalance);
            payBtn.disabled = false;
        } else if (choice === 'full') {
            if (labelFull) labelFull.classList.add('selected');
            updateDisplayAmount(remainingBalance);
            payBtn.disabled = false;
        }
    }

    function showToast(msg) {
        const t = document.getElementById('toast');
        if (!t) return;
        t.textContent = msg;
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 2000);
    }

    function copyNumber(num) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(num).then(() => showToast('GCash number copied!'));
        } else {
            const ta = document.createElement('textarea');
            ta.value = num;
            ta.style.position = 'fixed';
            ta.style.left = '-999999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast('GCash number copied!');
        }
    }

    document.getElementById('payBtn').addEventListener('click', function() {
        const btn = this;

        if (selectedAmount <= 0) {
            alert('Please select or enter a valid payment amount.');
            return;
        }

        if (remainingBalance > 0 && selectedAmount > (remainingBalance + 0.01)) {
            alert('Payment amount cannot exceed the remaining balance of ₱' + Number(remainingBalance).toFixed(2));
            return;
        }

        var refInputEl = document.getElementById('referenceNumberInput');
        const refVal = refInputEl ? refInputEl.value.trim() : '';
        const proofFileInput = document.getElementById('proofFileInput');

        btn.disabled = true;
        btn.innerHTML = 'Submitting...';

        const fd = new FormData();
        fd.append('action', 'process_payment');
        fd.append('booking_id', bookingId);
        fd.append('amount', selectedAmount.toFixed(2));
        fd.append('reference_number', refVal);
        if (proofFileInput && proofFileInput.files && proofFileInput.files[0]) {
            fd.append('proof_file', proofFileInput.files[0]);
        }
        var urlParams = new URLSearchParams(window.location.search);
        var retUrl = urlParams.get('return_url');
        if (retUrl) {
            fd.append('return_url', retUrl);
        }

        fetch('gcash_gateway.php' + window.location.search, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(r => r.json())
         .then(data => {
            if (data && data.success) {
                isPaymentCompleted = true;
                window.__gcashPaymentCompleted = true;
                // Signal opener / other tabs via localStorage
                try {
                    localStorage.setItem('guest_payment_completed_' + bookingId, JSON.stringify({
                        booking_id: bookingId,
                        amount_paid: selectedAmount,
                        reference: data.reference || '',
                        transaction_reference: data.transaction_ref || '',
                        payment_date: data.payment_date || new Date().toLocaleString(),
                        timestamp: Date.now()
                    }));
                } catch (eLS) {}

                // Populate receipt details in the success modal
                var refDisplay = data.reference || data.transaction_ref || '—';
                var dateDisplay = data.payment_date || new Date().toLocaleString();
                var amountFormatted = '₱' + Number(selectedAmount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                var refEl = document.getElementById('successRef');
                if (refEl) refEl.textContent = refDisplay;
                var dateEl = document.getElementById('successDate');
                if (dateEl) dateEl.textContent = dateDisplay;
                var amtEl = document.getElementById('successAmount');
                if (amtEl) amtEl.textContent = amountFormatted;

                // Hide the checkout page, show the success overlay
                var checkoutContainer = document.querySelector('.checkout-container');
                if (checkoutContainer) checkoutContainer.style.display = 'none';

                var overlay = document.getElementById('paymentSuccessOverlay');
                if (overlay) overlay.style.display = 'flex';

                // Setup download receipt button
                var downloadBtn = document.getElementById('downloadGcashReceipt');
                if (downloadBtn) {
                    downloadBtn.onclick = function() {
                        var receiptEl = document.getElementById('printableGcashReceipt');
                        var actionsGrid = receiptEl ? receiptEl.querySelector('.actions-grid') : null;
                        var closeBtn = receiptEl ? receiptEl.querySelector('#closeSuccessModal') : null;
                        var notice = document.getElementById('successDownloadNotice');

                        var origHtml = downloadBtn.innerHTML;
                        downloadBtn.disabled = true;
                        downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Generating...';

                        if (actionsGrid) actionsGrid.style.display = 'none';
                        if (closeBtn) closeBtn.style.visibility = 'hidden';

                        if (typeof html2canvas === 'function') {
                            html2canvas(receiptEl, {
                                scale: 2,
                                useCORS: true,
                                backgroundColor: '#ffffff'
                            }).then(function(canvas) {
                                if (actionsGrid) actionsGrid.style.display = 'grid';
                                if (closeBtn) closeBtn.style.visibility = 'visible';
                                downloadBtn.disabled = false;
                                downloadBtn.innerHTML = origHtml;

                                var link = document.createElement('a');
                                link.download = 'Receipt_Reservation_<?php echo $booking_id; ?>_' + (refDisplay || 'payment') + '.png';
                                link.href = canvas.toDataURL('image/png');
                                link.click();

                                if (notice) { notice.style.display = 'block'; }
                            }).catch(function(err) {
                                console.error('Download receipt error:', err);
                                if (actionsGrid) actionsGrid.style.display = 'grid';
                                if (closeBtn) closeBtn.style.visibility = 'visible';
                                downloadBtn.disabled = false;
                                downloadBtn.innerHTML = origHtml;
                                alert('Unable to generate receipt image. Please use the Print button in your browser.');
                            });
                        } else {
                            if (actionsGrid) actionsGrid.style.display = 'grid';
                            if (closeBtn) closeBtn.style.visibility = 'visible';
                            downloadBtn.disabled = false;
                            downloadBtn.innerHTML = origHtml;
                            window.print();
                        }
                    };
                }

                 // Back and Close button handlers
                 function returnToBookingReservation() {
                     var paymentSource = (typeof data !== 'undefined' && data && data.payment_source) ? data.payment_source : '<?php echo ($isPaymentAdmin ? 'admin' : ($isPaymentUser ? 'user' : 'guest')); ?>';
                     var refVal = (typeof data !== 'undefined' && data && (data.reference || data.transaction_ref)) ? (data.reference || data.transaction_ref) : '';
                     var amtVal = (typeof selectedAmount !== 'undefined') ? selectedAmount.toFixed(2) : '<?php echo number_format($amount, 2, '.', ''); ?>';
                     var isMonthly = <?php echo json_encode($is_user_request && $is_paying_installment); ?>;
                     var instNum = <?php echo json_encode($target_installment_number ?: $installment_num); ?>;
                     var monthlyExtra = isMonthly ? ('&payment_type=installment' + (instNum ? ('&installment=' + encodeURIComponent(instNum)) : '')) : '';

                     if (paymentSource === 'admin') {
                         window.location.href = 'dashboard.php?booking_pay_return=1&booking_id=' + encodeURIComponent(bookingId) + '&payment_ref=' + encodeURIComponent(refVal);
                     } else if (paymentSource === 'guest') {
                         // Return to popup.php with guest_pay_return so the fillup modal re-opens
                         var urlParams = new URLSearchParams(window.location.search);
                         var retUrl = urlParams.get('return_url') || '';
                         var guestToken = urlParams.get('pay_token') || '';
                         var sec = urlParams.get('section') || '';
                         var px = urlParams.get('plot_x') || '';
                         var py = urlParams.get('plot_y') || '';
                         var base = retUrl ? retUrl : ('sections/popup.php?section=' + encodeURIComponent(sec) + '&plot_x=' + px + '&plot_y=' + py);
                         var sep = (base.indexOf('?') !== -1) ? '&' : '?';
                         window.location.href = base + sep + 'guest_pay_return=1&booking_id=' + encodeURIComponent(bookingId) + '&payment_status=pending_proof&gcash_paid=1&payment_ref=' + encodeURIComponent(refVal) + '&payment_amount=' + encodeURIComponent(amtVal) + (guestToken ? '&pay_token=' + encodeURIComponent(guestToken) : '');
                     } else {
                         window.location.href = 'user_dashboard.php?user_pay_return=1&booking_id=' + encodeURIComponent(bookingId) + '&payment_ref=' + encodeURIComponent(refVal) + '&payment_amount=' + encodeURIComponent(amtVal) + monthlyExtra + '&open_booking_modal=1';
                     }
                 }

                 window.goBackOrHome = function() {
                     var paymentSource = '<?php echo ($isPaymentAdmin ? 'admin' : ($isPaymentUser ? 'user' : 'guest')); ?>';
                     var isMonthly = <?php echo json_encode($is_user_request && $is_paying_installment); ?>;
                     var instNum = <?php echo json_encode($target_installment_number ?: $installment_num); ?>;
                     var monthlyExtra = isMonthly ? ('&payment_type=installment' + (instNum ? ('&installment=' + encodeURIComponent(instNum)) : '')) : '';
                     if (paymentSource === 'admin') {
                         window.location.href = 'dashboard.php';
                     } else if (paymentSource === 'guest') {
                         var urlParams = new URLSearchParams(window.location.search);
                         var retUrl = urlParams.get('return_url') || '';
                         var guestToken = urlParams.get('pay_token') || '';
                         var sec = urlParams.get('section') || '';
                         var px = urlParams.get('plot_x') || '';
                         var py = urlParams.get('plot_y') || '';
                         var base = retUrl ? retUrl : ('sections/popup.php?section=' + encodeURIComponent(sec) + '&plot_x=' + px + '&plot_y=' + py);
                         var sep = (base.indexOf('?') !== -1) ? '&' : '?';
                         window.location.href = base + sep + 'guest_pay_return=1&booking_id=' + encodeURIComponent(bookingId) + (guestToken ? '&pay_token=' + encodeURIComponent(guestToken) : '');
                     } else {
                         window.location.href = 'user_dashboard.php?user_pay_return=1&booking_id=' + encodeURIComponent(bookingId) + monthlyExtra + '&open_booking_modal=1';
                     }
                 };

                 var doneBtn = document.getElementById('donePaymentBtn');
                 if (doneBtn) {
                     doneBtn.onclick = returnToBookingReservation;
                 }

                 var closeModalBtn = document.getElementById('closeSuccessModal');
                 if (closeModalBtn) {
                     closeModalBtn.onclick = returnToBookingReservation;
                 }
            } else {
                alert(data.message || 'Payment submission failed.');
                btn.disabled = false;
                btn.innerHTML = 'Submit Payment (₱' + Number(selectedAmount).toFixed(2) + ')';
            }
        })
        .catch(err => {
            console.error(err);
            alert('Connection error. Please try again.');
            btn.disabled = false;
            btn.innerHTML = 'Submit Payment (₱' + Number(selectedAmount).toFixed(2) + ')';
        });
    });
</script>
</body>
</html>
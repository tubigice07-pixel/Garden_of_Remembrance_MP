<?php

ini_set('display_errors', '0');
error_reporting(0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit;
}

include_once __DIR__ . '/connection.php';
include_once __DIR__ . '/config/payment_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$is_admin = isset($_SESSION['role']) && strtoupper($_SESSION['role']) === 'ADMIN';
$booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;

$reference_input = isset($_POST['gcash_reference']) ? trim($_POST['gcash_reference']) : (isset($_POST['reference_number']) ? trim($_POST['reference_number']) : (isset($_POST['reference']) ? trim($_POST['reference']) : ''));
$is_user_provided_ref = !empty($reference_input);
$gcash_reference = $is_user_provided_ref ? $reference_input : ('PROOF-' . $booking_id . '-' . date('ymdHis') . '-' . strtoupper(substr(uniqid(), -4)));

$payment_method = !empty($_POST['payment_method']) ? trim($_POST['payment_method']) : 'GCash';
if (!$is_admin) {
    // Only GCash is accepted for online payment proof submissions
    $payment_method = 'GCash';
}
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

if ($booking_id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit;
}

$stmt = $conn->prepare("SELECT id, user_id, total_amount, plot_price, status, payment_status, payment_plan FROM tbl_bookings WHERE id = ? LIMIT 1");

if (!$stmt) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$stmt->bind_param('i', $booking_id);
$stmt->execute();
$result = $stmt->get_result();
$booking = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$booking) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Booking record not found']);
    exit;
}

if (!$is_admin && (int)$booking['user_id'] !== $user_id) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access denied. You do not have permission to upload payment proof for this booking.']);
    exit;
}

// Check if booking is already fully paid
$hasExistingProof = !empty($booking['payment_proof_filename']) || !empty($booking['payment_proof']);
$bookingTotals = function_exists('recalculate_booking_payment_totals') ? recalculate_booking_payment_totals($booking_id, $conn) : null;
$remaining_balance = PHP_INT_MAX;

$totAmount = floatval($booking['total_amount'] ?? 0);
if ($totAmount <= 0) {
    $totAmount = floatval($booking['plot_price'] ?? 0) + floatval($booking['service_price'] ?? 0) + floatval($booking['interment_fee'] ?? 0);
}

if ($bookingTotals && isset($bookingTotals['remaining_balance']) && (float)$bookingTotals['remaining_balance'] > 0.009) {
    $remaining_balance = (float)$bookingTotals['remaining_balance'];
} else {
    $chkPaid = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) AS verified_total FROM payment_receipts WHERE booking_id = {$booking_id} AND UPPER(COALESCE(payment_status, '')) IN ('PAID', 'VERIFIED')");
    $paidSoFar = ($chkPaid && $rRow = $chkPaid->fetch_assoc()) ? (float)$rRow['verified_total'] : 0.00;
    $remaining_balance = max(0.00, round($totAmount - $paidSoFar, 2));
}

// If no verified payment proof exists yet on this booking, remaining balance is at least the booking amount
if (!$hasExistingProof && $remaining_balance <= 0.009 && $totAmount > 0) {
    $remaining_balance = $totAmount;
}

// Only block if the booking has verified proof AND remaining balance is truly settled
$is_truly_fully_paid = $hasExistingProof && ($remaining_balance <= 0.009) && (strtoupper(trim((string)($booking['payment_status'] ?? ''))) === 'PAID');

if ($is_truly_fully_paid) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'This booking is already fully paid. No further payments are required.']);
    exit;
}

$booking_owner_id = (int)$booking['user_id'];
$booking_amount = $totAmount;
$user_specified_amount = false;
if (isset($_POST['amount_paid']) && is_numeric($_POST['amount_paid']) && floatval($_POST['amount_paid']) > 0) {
    $booking_amount = floatval($_POST['amount_paid']);
    $user_specified_amount = true;
}

// Reject payment amounts greater than remaining balance
if ($remaining_balance > 0 && $booking_amount > ($remaining_balance + 0.05)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Submitted payment amount (₱' . number_format($booking_amount, 2) . ') exceeds the remaining balance (₱' . number_format($remaining_balance, 2) . ').'
    ]);
    exit;
}

$file_key = isset($_FILES['payment_proof']) ? 'payment_proof' : (isset($_FILES['proof_file']) ? 'proof_file' : null);

if (!$file_key || !isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
    $error_msg = 'No payment proof file uploaded';
    if ($file_key && isset($_FILES[$file_key])) {
        $error_msg = 'File upload error code: ' . $_FILES[$file_key]['error'];
    }
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $error_msg]);
    exit;
}

$file = $_FILES[$file_key];
$allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg', 'application/pdf'];
$allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
$max_size = 10 * 1024 * 1024; // 10MB

if (!is_uploaded_file($file['tmp_name'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid uploaded file']);
    exit;
}

if ($file['size'] > $max_size || $file['size'] <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'File size must be between 1 byte and 10MB limit']);
    exit;
}

$file_type = mime_content_type($file['tmp_name']) ?: '';
$file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($file_type, $allowed_types) || !in_array($file_ext, $allowed_extensions)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Only JPG, JPEG, PNG, WebP images and PDF documents are allowed']);
    exit;
}

if ($file_ext === 'pdf') {
    $fp = @fopen($file['tmp_name'], 'rb');
    $header = $fp ? fread($fp, 5) : '';
    if ($fp) fclose($fp);
    if (strpos($header, '%PDF-') !== 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid or corrupted PDF document']);
        exit;
    }
} else {
    if (function_exists('exif_imagetype') && @exif_imagetype($file['tmp_name']) === false) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid image structure']);
        exit;
    }
    $gis = @getimagesize($file['tmp_name']);
    if ($gis === false || empty($gis[0]) || empty($gis[1])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid image dimensions']);
        exit;
    }
}

// Disguised script / executable tag detection
$sample = @file_get_contents($file['tmp_name'], false, null, 0, 8192);
if ($sample !== false) {
    if (preg_match('/<\?(?:php|=)/i', $sample) || preg_match('/<script\b/i', $sample)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Malicious or suspicious script detected in uploaded file']);
        exit;
    }
}

// Validate receipt filename: Allow downloaded receipts (e.g. Receipt_Booking_338_GC20260925426876.png, Receipt_Booking_338_RCPT-..., etc.) and customer screenshots
$origName = $file['name'] ?? '';
$origBaseName = pathinfo($origName, PATHINFO_FILENAME);

// If user didn't enter a reference number, check if the downloaded receipt filename contains a GCash reference
if (!$is_user_provided_ref && !empty($origBaseName)) {
    if (preg_match('/(GC\d{10,})/i', $origBaseName, $refMatch)) {
        $gcash_reference = strtoupper($refMatch[1]);
        $is_user_provided_ref = true;
    }
}

// Check if the uploaded receipt was issued for a specific booking and prevent cross-booking mistakes
if (preg_match('/(?:^|[_\W])(?:Receipt_)?Booking_?(\d+)/i', $origBaseName, $bMatches)) {
    $fileBookingId = (int)$bMatches[1];
    if ($fileBookingId > 0 && $fileBookingId !== $booking_id) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => "Invalid receipt: Receipt file was issued for Booking #{$fileBookingId}, but you are uploading for Booking #{$booking_id}."
        ]);
        exit;
    }
}

$upload_dir = __DIR__ . '/uploads/payment-proofs';
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Could not create upload directory']);
        exit;
    }
}

$unique_token = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$filename = 'Receipt_Booking_' . $booking_id . '_RCPT-' . date('Ymd') . '-' . $unique_token . '.' . $file_ext;
$filepath = $upload_dir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Failed to save file on server']);
    exit;
}

$existingReceiptId = 0;
if ($is_user_provided_ref) {
    $chkDup = $conn->prepare("SELECT id, proof_filename, payment_status FROM payment_receipts WHERE booking_id = ? AND gcash_reference_number = ? AND payment_status != 'REJECTED' ORDER BY id DESC LIMIT 1");
    if ($chkDup) {
        $chkDup->bind_param('is', $booking_id, $gcash_reference);
        $chkDup->execute();
        $dupRes = $chkDup->get_result();
        if ($dupRes && ($dRow = $dupRes->fetch_assoc())) {
            $existingReceiptId = (int)$dRow['id'];
            $existingProof = trim((string)($dRow['proof_filename'] ?? ''));
            $existingStatus = strtoupper(trim((string)($dRow['payment_status'] ?? '')));
            
            // Only reject if it has already been verified / approved
            if (!empty($existingProof) && in_array($existingStatus, ['PAID', 'VERIFIED', 'APPROVED'])) {
                $chkDup->close();
                http_response_code(409);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'message' => 'This reference number (' . htmlspecialchars($gcash_reference) . ') has already been verified and credited for this booking.'
                ]);
                exit;
            }
        }
        $chkDup->close();
    }
}

$schedule_id = isset($_POST['schedule_id']) ? intval($_POST['schedule_id']) : null;
$installment_num = isset($_POST['installment_number']) ? intval($_POST['installment_number']) : null;

$is_installment = strtolower(trim((string)($booking['payment_plan'] ?? ''))) === 'installment';
$downpayment_required = floatval($booking['downpayment'] ?? 0);
if ($downpayment_required <= 0 && $is_installment) {
    $plot_price = floatval($booking['plot_price'] ?? 85000.00);
    $downpayment_required = round($plot_price * 0.20, 2);
}

$totPaidRes = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) AS verified_total FROM payment_receipts WHERE booking_id = {$booking_id} AND UPPER(COALESCE(payment_status, '')) IN ('PAID', 'VERIFIED')");
$totVerifiedPaid = ($totPaidRes && ($tRow = $totPaidRes->fetch_assoc())) ? floatval($tRow['verified_total']) : 0.0;
$is_downpayment_pending = ($is_installment && $downpayment_required > 0 && $totVerifiedPaid < ($downpayment_required - 0.01));

if ($is_downpayment_pending) {
    $schedule_id = null;
    $installment_num = null;
    if (!$user_specified_amount && ($booking_amount <= 0 || $booking_amount == floatval($booking['total_amount']))) {
        $booking_amount = min(round($downpayment_required - $totVerifiedPaid, 2), floatval($booking['remaining_balance'] ?? $downpayment_required));
    }
} elseif ($is_installment && (!$schedule_id || !$installment_num)) {
    $sRes = $conn->query("SELECT id, installment_number, monthly_amount FROM payment_schedules WHERE contract_id = {$booking_id} AND status != 'paid' ORDER BY installment_number ASC LIMIT 1");
    if ($sRes && ($sRow = $sRes->fetch_assoc())) {
        $schedule_id = (int)$sRow['id'];
        $installment_num = (int)$sRow['installment_number'];
        if (!$user_specified_amount && ($booking_amount <= 0 || $booking_amount == floatval($booking['total_amount']))) {
            $booking_amount = (float)$sRow['monthly_amount'];
        }
    } else {
        $legRes = $conn->query("SELECT id, installment_number, amount_due FROM booking_payment_schedule WHERE booking_id = {$booking_id} AND status != 'PAID' ORDER BY installment_number ASC LIMIT 1");
        if ($legRes && ($lRow = $legRes->fetch_assoc())) {
            $schedule_id = (int)$lRow['id'];
            $installment_num = (int)$lRow['installment_number'];
            if (!$user_specified_amount && ($booking_amount <= 0 || $booking_amount == floatval($booking['total_amount']))) {
                $booking_amount = (float)$lRow['amount_due'];
            }
        }
    }
}

$clean_method = preg_replace('/[^A-Za-z0-9]/', '', $payment_method);
$method_prefix = strtoupper(substr($clean_method, 0, 4)) ?: 'PAY';
$tx_ref = 'TXN-' . $method_prefix . '-' . $booking_id . '-' . time();

$payment_id = $existingReceiptId;
if ($existingReceiptId > 0) {
    // Update existing receipt created by gateway or previous upload
    $upPR = $conn->prepare("UPDATE payment_receipts SET 
        proof_filename = ?, 
        amount_paid = CASE WHEN amount_paid <= 0 THEN ? ELSE amount_paid END, 
        payment_method = ?,
        notes = COALESCE(NULLIF(?, ''), notes),
        payment_status = 'PENDING',
        rejection_reason = NULL
        WHERE id = ?");
    if ($upPR) {
        $upPR->bind_param('sdssi', $filename, $booking_amount, $payment_method, $notes, $existingReceiptId);
        $upPR->execute();
        $upPR->close();
    }
} else {
    $insReceipt = $conn->prepare("INSERT INTO payment_receipts 
        (booking_id, user_id, payment_schedule_id, installment_number, amount_paid, payment_method, gcash_reference_number, transaction_reference, payment_date, payment_status, proof_filename, notes, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'PENDING', ?, ?, NOW())");

    if ($insReceipt) {
        $insReceipt->bind_param('iiiidsssss', $booking_id, $booking_owner_id, $schedule_id, $installment_num, $booking_amount, $payment_method, $gcash_reference, $tx_ref, $filename, $notes);
        $insReceipt->execute();
        $payment_id = (int)$insReceipt->insert_id;
        $insReceipt->close();

        if ($payment_id > 0) {
            $receipt_num = sprintf("RCPT-%s-%04d", date('Ymd'), $payment_id);
            $conn->query("UPDATE payment_receipts SET receipt_number = '{$receipt_num}' WHERE id = {$payment_id}");
        }
    }
}

if (!empty($installment_num) || !empty($schedule_id)) {
    // Installment payment: Do NOT overwrite tbl_bookings downpayment proof or status
    if (!empty($schedule_id)) {
        $conn->query("UPDATE payment_schedules SET status = 'pending_verification' WHERE id = " . (int)$schedule_id);
        $conn->query("UPDATE booking_payment_schedule SET status = 'PENDING' WHERE id = " . (int)$schedule_id);
    }
} else {
    $upBooking = $conn->prepare("UPDATE tbl_bookings SET 
        payment_proof_filename = ?, 
        gcash_reference_number = ?, 
        payment_proof_uploaded_at = NOW(),
        reference_number = COALESCE(reference_number, ?),
        payment_type = CASE WHEN payment_type IS NULL OR payment_type = '' THEN ? ELSE payment_type END,
        payment_status = 'FOR VERIFICATION'
        WHERE id = ?");

    if ($upBooking) {
        $upBooking->bind_param('ssssi', $filename, $gcash_reference, $gcash_reference, $payment_method, $booking_id);
        $upBooking->execute();
        $upBooking->close();
    }

    // Sync guest_reservation_requests if this booking originated from a guest reservation
    $conn->query("UPDATE guest_reservation_requests SET payment_proof_filename = '{$conn->real_escape_string($filename)}', proof_filename = '{$conn->real_escape_string($filename)}', payment_status = 'FOR VERIFICATION' WHERE booking_id = {$booking_id}");
}

$logChk = $conn->query("SHOW TABLES LIKE 'tbl_logs'");
if ($logChk && $logChk->num_rows > 0) {
    $logAction = 'PAYMENT_PROOF_UPLOADED';
    $logDetails = "Payment proof screenshot uploaded for Booking #{$booking_id}. Reference: {$gcash_reference}. Status: FOR VERIFICATION";
    $stmtLog = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, ?, NOW())");
    if ($stmtLog) {
        $stmtLog->bind_param('ssi', $logAction, $logDetails, $user_id);
        $stmtLog->execute();
        $stmtLog->close();
    }

    $bkInfo = $conn->query("SELECT purpose, service_type, section, plot, customer_name, total_amount FROM tbl_bookings WHERE id = " . (int)$booking_id);
    if ($bkInfo && $bRow = $bkInfo->fetch_assoc()) {
        if (strtolower(trim($bRow['purpose'] ?? '')) === 'cemetery_service' || !empty($bRow['service_type'])) {
            $srvType = !empty($bRow['service_type']) ? $bRow['service_type'] : 'Cemetery Service';
            $srvPlot = trim(($bRow['section'] ?? '') . '-' . ($bRow['plot'] ?? ''));
            $cName = !empty($bRow['customer_name']) ? $bRow['customer_name'] : 'Client';
            $srvDetails = "Payment proof uploaded for service: {$srvType} on plot {$srvPlot} | Booking ID: #{$booking_id} | Ref: {$gcash_reference} | Amount: ₱" . number_format((float)($bRow['total_amount'] ?? 200), 2) . " | Status: FOR VERIFICATION";
            $sStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('SERVICE_BOOKING', ?, ?, NOW())");
            if ($sStmt) {
                $sStmt->bind_param('si', $srvDetails, $user_id);
                $sStmt->execute();
                $sStmt->close();
            }
        }
    }

    // Notify admins in real-time that payment proof was uploaded
    try {
        if (file_exists(__DIR__ . '/notification_schema.php')) {
            require_once __DIR__ . '/notification_schema.php';
        } elseif (file_exists(__DIR__ . '/includes/notification_schema.php')) {
            require_once __DIR__ . '/includes/notification_schema.php';
        }
        if (function_exists('notify_administrators')) {
            $isSrvProof = (isset($bRow) && (strtolower(trim($bRow['purpose'] ?? '')) === 'cemetery_service' || !empty($bRow['service_type'])));
            $srvTypeProof = !empty($bRow['service_type']) ? $bRow['service_type'] : 'Cemetery Service';
            $cNameProof = !empty($bRow['customer_name']) ? $bRow['customer_name'] : 'Customer';
            if ($isSrvProof) {
                $pTitle = "Payment Proof: {$srvTypeProof} (#{$booking_id})";
                $pMsg = "{$cNameProof} uploaded payment proof for {$srvTypeProof} (Ref: {$gcash_reference}). Status: FOR VERIFICATION.";
                $pLink = "logs.php?search=" . urlencode((string)$booking_id);
                notify_administrators($conn, $pTitle, $pMsg, $pLink, (int)$booking_id, 'payment');
            } else {
                $pTitle = "Payment Proof Uploaded (#{$booking_id})";
                $pMsg = "{$cNameProof} uploaded payment proof for Booking #{$booking_id} (Ref: {$gcash_reference}). Status: FOR VERIFICATION.";
                $pLink = "dashboard.php#bookings";
                notify_administrators($conn, $pTitle, $pMsg, $pLink, (int)$booking_id, 'payment');
            }
        }
    } catch (Throwable $eProofNotif) {
        error_log("Failed to notify admins of payment proof: " . $eProofNotif->getMessage());
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'Payment proof submitted. Waiting for admin verification.',
    'booking_id' => $booking_id,
    'filename' => $filename,
    'reference_number' => $gcash_reference
]);
?>

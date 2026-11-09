<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
include 'connection.php';
require_once __DIR__ . '/includes/formatters.php';
require_once __DIR__ . '/config/payment_settings.php';
if (file_exists(__DIR__ . '/config/cancellation_helper.php')) {
    require_once __DIR__ . '/config/cancellation_helper.php';
}
if (file_exists(__DIR__ . '/includes/notification_helper.php')) {
    require_once __DIR__ . '/includes/notification_helper.php';
}
if (file_exists(__DIR__ . '/mailer.php')) {
    require_once __DIR__ . '/mailer.php';
}

ensure_service_workflow_columns($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['accept_booking', 'approve_booking', 'reject_booking', 'complete_booking', 'approve_service', 'start_service', 'complete_service', 'reject_service'], true)) {
    ob_start();
    header('Content-Type: application/json');

    $bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
    if ($bookingId <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Booking ID is required']);
        exit;
    }

    if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $adminUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $adminName = 'Admin';
    if (!empty($_SESSION['user_name'])) {
        $adminName = $_SESSION['user_name'];
    } elseif ($adminUserId > 0) {
        $uChk = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = {$adminUserId}");
        if ($uChk && ($uRow = $uChk->fetch_assoc())) {
            $adminName = trim($uRow['first_name'] . ' ' . $uRow['last_name']);
        }
    }

    $bkPlotQ = $conn->query("SELECT id, purpose, service_type, section, plot, plot_id, customer_name, customer_email, user_id, total_amount, amount_paid, remaining_balance, status FROM tbl_bookings WHERE id = " . (int)$bookingId . " LIMIT 1");
    $bkRow = $bkPlotQ ? $bkPlotQ->fetch_assoc() : null;
    $bPurp = strtolower(trim((string)($bkRow['purpose'] ?? '')));
    $bSrvT = strtolower(trim((string)($bkRow['service_type'] ?? '')));
    $isServiceBooking = ($bPurp === 'cemetery_service' || in_array($bSrvT, ['cleaning', 'landscaping', 'maintenance', 'decoration'], true) || !empty($bSrvT));

    // Handle Start Service
    if ($_POST['action'] === 'start_service') {
        $up = $conn->prepare("UPDATE tbl_bookings SET service_status = 'IN PROGRESS' WHERE id = ?");
        if ($up) {
            $up->bind_param("i", $bookingId);
            $up->execute();
            $up->close();
        }
        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('SERVICE_BOOKING', ?, ?, NOW())");
        if ($logStmt) {
            $srvType = !empty($bkRow['service_type']) ? $bkRow['service_type'] : 'Cemetery Service';
            $det = "Service booking #{$bookingId} ({$srvType}) started (status: IN PROGRESS) by {$adminName}";
            $logStmt->bind_param("si", $det, $adminUserId);
            $logStmt->execute();
            $logStmt->close();
        }
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Service booking #' . $bookingId . ' is now IN PROGRESS.', 'status' => 'approved', 'service_status' => 'IN PROGRESS']);
        exit;
    }

    // Handle Reject Service
    if ($_POST['action'] === 'reject_service') {
        $rejectionRemarks = trim((string)($_POST['remarks'] ?? ($_POST['rejection_reason'] ?? '')));
        if (empty($rejectionRemarks)) {
            $rejectionRemarks = 'Service booking rejected by administrator.';
        }

        $up = $conn->prepare("UPDATE tbl_bookings SET status = 'rejected', service_status = 'REJECTED', payment_status = 'REJECTED', remarks = ? WHERE id = ?");
        if ($up) {
            $up->bind_param("si", $rejectionRemarks, $bookingId);
            $up->execute();
            $up->close();
        }
        $upPR = $conn->prepare("UPDATE payment_receipts SET payment_status = 'REJECTED', rejection_reason = ?, verified_by = ?, verified_at = NOW() WHERE booking_id = ? AND (payment_status IS NULL OR UPPER(payment_status) IN ('PENDING', 'FOR VERIFICATION'))");
        if ($upPR) {
            $upPR->bind_param('sii', $rejectionRemarks, $adminUserId, $bookingId);
            $upPR->execute();
            $upPR->close();
        }
        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('SERVICE_BOOKING', ?, ?, NOW())");
        if ($logStmt) {
            $srvType = !empty($bkRow['service_type']) ? $bkRow['service_type'] : 'Cemetery Service';
            $det = "Service booking #{$bookingId} ({$srvType}) rejected by {$adminName}. Reason: {$rejectionRemarks}";
            $logStmt->bind_param("si", $det, $adminUserId);
            $logStmt->execute();
            $logStmt->close();
        }

        // Notify user via in-app notification and email with rejection remarks
        if (function_exists('notify_booking_rejected')) {
            notify_booking_rejected($conn, (int)$bookingId, $rejectionRemarks);
        } elseif (file_exists(__DIR__ . '/mailer.php')) {
            include_once __DIR__ . '/mailer.php';
            if (function_exists('send_booking_status_email')) {
                send_booking_status_email((int)$bookingId, 'rejected', $conn);
            }
        }
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Service booking #' . $bookingId . ' has been rejected.', 'status' => 'rejected', 'service_status' => 'REJECTED', 'remarks' => $rejectionRemarks]);
        exit;
    }

    // Handle Approve / Accept
    if ($_POST['action'] === 'accept_booking' || $_POST['action'] === 'approve_booking' || $_POST['action'] === 'approve_service') {
        $appRes = handle_booking_approval($conn, $bookingId, $adminUserId);
        if (!isset($appRes['status']) && !empty($appRes['success'])) {
            $appRes['status'] = 'approved';
        }
        if ($isServiceBooking || $_POST['action'] === 'approve_service') {
            $conn->query("UPDATE tbl_bookings SET service_status = 'APPROVED' WHERE id = " . (int)$bookingId);
            $appRes['service_status'] = 'APPROVED';
        }
        ob_end_clean();
        echo json_encode($appRes);
        exit;
    }

    // Handle Complete Booking / Complete Service
    if ($_POST['action'] === 'complete_service' || ($_POST['action'] === 'complete_booking' && $isServiceBooking)) {
        // MANDATORY REAL PHOTO UPLOAD FOR SERVICE COMPLETION
        if (!isset($_FILES['service_proof']) || $_FILES['service_proof']['error'] !== UPLOAD_ERR_OK) {
            ob_end_clean();
            echo json_encode([
                'success' => false, 
                'message' => 'Completion photo proof is mandatory to complete a cemetery service. Please upload a valid photo.'
            ]);
            exit;
        }

        $fileTmp = $_FILES['service_proof']['tmp_name'];
        $fileSize = $_FILES['service_proof']['size'];
        $fileOrigName = $_FILES['service_proof']['name'];
        $ext = strtolower(pathinfo($fileOrigName, PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $allowedExts, true)) {
            ob_end_clean();
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid file extension. Only JPG, PNG, and WebP images are allowed for completion proof.'
            ]);
            exit;
        }

        if ($fileSize <= 0 || $fileSize > (5 * 1024 * 1024)) {
            ob_end_clean();
            echo json_encode([
                'success' => false, 
                'message' => 'File size must be between 1 KB and 5 MB.'
            ]);
            exit;
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $fileTmp);
            finfo_close($finfo);
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($fileTmp);
        }
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/pjpeg'];
        if (!empty($mime) && !in_array(strtolower($mime), $allowedMimes, true)) {
            ob_end_clean();
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid image content type. Only JPG, PNG, and WebP images are accepted.'
            ]);
            exit;
        }

        $imgInfo = @getimagesize($fileTmp);
        if ($imgInfo === false) {
            ob_end_clean();
            echo json_encode([
                'success' => false, 
                'message' => 'Uploaded file is not a valid image.'
            ]);
            exit;
        }

        $proofDir = __DIR__ . '/uploads/service-proofs/';
        if (!is_dir($proofDir)) {
            @mkdir($proofDir, 0777, true);
        }

        $safeFilename = 'service_proof_' . $bookingId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath = $proofDir . $safeFilename;

        if (!move_uploaded_file($fileTmp, $destPath)) {
            ob_end_clean();
            echo json_encode([
                'success' => false, 
                'message' => 'Failed to save completion proof image to server directory.'
            ]);
            exit;
        }

        $upStmt = $conn->prepare("UPDATE tbl_bookings SET status = 'completed', service_status = 'COMPLETED', service_completion_proof = ?, service_completed_at = NOW(), service_completed_by = ? WHERE id = ?");
        if (!$upStmt) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . $conn->error]);
            exit;
        }
        $upStmt->bind_param("ssi", $safeFilename, $adminName, $bookingId);
        $upStmt->execute();
        $upStmt->close();

        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('SERVICE_BOOKING', ?, ?, NOW())");
        if ($logStmt) {
            $srvType = !empty($bkRow['service_type']) ? $bkRow['service_type'] : 'Cemetery Service';
            $det = "Service booking #{$bookingId} ({$srvType}) completed with proof photo: {$safeFilename} by {$adminName}";
            $logStmt->bind_param("si", $det, $adminUserId);
            $logStmt->execute();
            $logStmt->close();
        }

        if (file_exists(__DIR__ . '/mailer.php')) {
            include_once __DIR__ . '/mailer.php';
            if (function_exists('send_booking_status_email')) {
                send_booking_status_email((int)$bookingId, 'completed', $conn);
            }
        }

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Service booking #' . $bookingId . ' has been completed successfully with proof photo.',
            'status' => 'completed',
            'service_status' => 'COMPLETED',
            'service_proof' => $safeFilename
        ]);
        exit;
    }

    // Default Complete Booking (for non-service bookings, e.g. plot reservation)
    $rejectionRemarks = '';
    if ($_POST['action'] === 'complete_booking') {
        $newStatus = 'completed';
        $stmt = $conn->prepare("UPDATE tbl_bookings SET status = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $newStatus, $bookingId);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $newStatus = 'rejected';
        $rejectionRemarks = trim((string)($_POST['remarks'] ?? ($_POST['rejection_reason'] ?? '')));
        if (empty($rejectionRemarks)) {
            $rejectionRemarks = 'Booking application rejected by administrator.';
        }
        $stmt = $conn->prepare("UPDATE tbl_bookings SET status = ?, remarks = ?, payment_status = 'REJECTED' WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("ssi", $newStatus, $rejectionRemarks, $bookingId);
            $stmt->execute();
            $stmt->close();
        }
        $upPR = $conn->prepare("UPDATE payment_receipts SET payment_status = 'REJECTED', rejection_reason = ?, verified_by = ?, verified_at = NOW() WHERE booking_id = ? AND (payment_status IS NULL OR UPPER(payment_status) IN ('PENDING', 'FOR VERIFICATION'))");
        if ($upPR) {
            $upPR->bind_param('sii', $rejectionRemarks, $adminUserId, $bookingId);
            $upPR->execute();
            $upPR->close();
        }
        $conn->query("UPDATE guest_reservation_requests SET status = 'Rejected', payment_status = 'REJECTED', admin_notes = '" . $conn->real_escape_string($rejectionRemarks) . "', rejected_by = {$adminUserId}, rejected_at = NOW() WHERE booking_id = " . (int)$bookingId);
    }

    if ($newStatus === 'rejected') {
        // Release plots (single + multi-plot) unless occupied by a deceased record.
        // Payments are preserved - strict NO REFUND policy.
        if (file_exists(__DIR__ . '/config/cancellation_helper.php')) {
            require_once __DIR__ . '/config/cancellation_helper.php';
        }
        $rejReleased = []; $rejKept = [];
        if (function_exists('release_booking_plots')) {
            $relRes = release_booking_plots($conn, (int)$bookingId);
            $rejReleased = $relRes['released'] ?? [];
            $rejKept = $relRes['kept'] ?? [];
        } elseif ($bkRow) {
            $bSec = strtoupper(trim((string)($bkRow['section'] ?? '')));
            $bPlotId = (int)($bkRow['plot_id'] ?? 0);
            $bPlotParts = explode('-', str_replace(',', '-', trim((string)($bkRow['plot'] ?? ''))));
            $bPx = (int)($bPlotParts[0] ?? 0);
            $bPy = (int)($bPlotParts[1] ?? 0);
            if ($bPlotId > 0) {
                $conn->query("UPDATE tbl_plots SET status = 'available' WHERE plot_id = {$bPlotId} AND LOWER(status) = 'reserved'");
            }
            if (!empty($bSec) && $bPx > 0 && $bPy > 0) {
                $decChk = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?");
                if ($decChk) {
                    $decChk->bind_param('sii', $bSec, $bPx, $bPy);
                    $decChk->execute();
                    $hasDec = ((int)($decChk->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
                    $decChk->close();
                    if (!$hasDec) {
                        $conn->query("UPDATE tbl_plots SET status = 'available' WHERE UPPER(TRIM(section)) = '{$conn->real_escape_string($bSec)}' AND plot_x = {$bPx} AND plot_y = {$bPy} AND LOWER(status) = 'reserved'");
                    }
                }
                $conn->query("UPDATE tbl_plot_owners SET active = 0 WHERE UPPER(TRIM(section)) = '{$conn->real_escape_string($bSec)}' AND plot_x = {$bPx} AND plot_y = {$bPy} AND active = 1");
            }
        }
    }

    if ($conn->query("SHOW TABLES LIKE 'tbl_logs'")->num_rows > 0) {
        $logAction = $newStatus === 'approved' ? 'Booking Approved' : ($newStatus === 'completed' ? 'Booking Completed' : 'Booking Rejected');
        $prevBkStatus = $bkRow ? strtoupper((string)($bkRow['status'] ?? 'pending')) : 'PENDING';
        $logTotal = number_format((float)($bkRow['total_amount'] ?? 0), 2);
        $logPaid = number_format((float)($bkRow['amount_paid'] ?? 0), 2);
        $logBal = number_format((float)($bkRow['remaining_balance'] ?? 0), 2);
        $logDetails = ($newStatus === 'rejected')
            ? "Booking ID: #{$bookingId} rejected by {$adminName} (Admin ID: {$adminUserId}). Previous status: {$prevBkStatus}. New status: REJECTED. Reason: {$rejectionRemarks}. Total: ₱{$logTotal}. Payment preserved: ₱{$logPaid}. Remaining: ₱{$logBal}. Refund: NON-REFUNDABLE. Plots released: " . (empty($rejReleased) ? 'none' : implode(', ', $rejReleased)) . ". Plots kept (occupied): " . (empty($rejKept) ? 'none' : implode(', ', $rejKept)) . "."
            : "Booking ID: #{$bookingId} has been {$newStatus}";
        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, ?, NOW())");
        if ($logStmt) {
            $logStmt->bind_param("ssi", $logAction, $logDetails, $adminUserId);
            $logStmt->execute();
            $logStmt->close();
        }

        if ($bkRow && ($bPurp === 'cemetery_service' || !empty($bkRow['service_type']))) {
            $srvType = !empty($bkRow['service_type']) ? $bkRow['service_type'] : 'Cemetery Service';
            $srvPlotSec = trim(($bkRow['section'] ?? '') . '-' . ($bkRow['plot'] ?? ''));
            $srvDetails = "Service booking #{$bookingId} ({$srvType}, Plot: {$srvPlotSec}) status updated to {$newStatus}";
            $srvStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('SERVICE_BOOKING', ?, ?, NOW())");
            if ($srvStmt) {
                $srvStmt->bind_param("si", $srvDetails, $adminUserId);
                $srvStmt->execute();
                $srvStmt->close();
            }
        }
    }

    if ($newStatus === 'rejected') {
        if (function_exists('notify_booking_rejected')) {
            notify_booking_rejected($conn, (int)$bookingId, $rejectionRemarks);
        } elseif (file_exists(__DIR__ . '/mailer.php')) {
            include_once __DIR__ . '/mailer.php';
            if (function_exists('send_booking_status_email')) {
                send_booking_status_email((int)$bookingId, $newStatus, $conn);
            }
        }
    } elseif (file_exists(__DIR__ . '/mailer.php')) {
        include_once __DIR__ . '/mailer.php';
        if (function_exists('send_booking_status_email')) {
            send_booking_status_email((int)$bookingId, $newStatus, $conn);
        }
    }

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Booking #' . $bookingId . ' has been ' . $newStatus . ' successfully.', 'status' => $newStatus, 'remarks' => $rejectionRemarks]);
    exit;
}

$check_suffix = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'suffix'");
if ($check_suffix && $check_suffix->num_rows == 0) {
    $conn->query("ALTER TABLE tbl_deceased ADD COLUMN suffix VARCHAR(50) DEFAULT '' AFTER last_name");
}
$check_edited_by = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'edited_by'");
if ($check_edited_by && $check_edited_by->num_rows == 0) {
    $conn->query("ALTER TABLE tbl_deceased ADD COLUMN edited_by VARCHAR(100) DEFAULT NULL");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = isset($_POST['mode']) ? $_POST['mode'] : 'insert';
    
    if ($mode === 'reserve') {
        
        $first_name = strtoupper(trim($_POST['first_name']));
        $middle_name = strtoupper(trim($_POST['middle_name']));
        $last_name = strtoupper(trim($_POST['last_name']));
        
        $raw_suffix = isset($_POST['suffix']) ? strtoupper(trim($_POST['suffix'])) : '';
        $other_suffix = isset($_POST['suffix_other']) ? strtoupper(trim($_POST['suffix_other'])) : '';
        $suffix = ($raw_suffix === 'OTHER') ? $other_suffix : $raw_suffix;
        $contact_no = trim($_POST['contact_no']);
        
        $contact_no = preg_replace('/[^0-9+\-]/', '', $contact_no);
        $section = strtoupper(trim($_POST['section']));
        $plot_x = isset($_POST['plot_x']) ? intval($_POST['plot_x']) : null;
        $plot_y = isset($_POST['plot_y']) ? intval($_POST['plot_y']) : null;
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        
        
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
        $user_name = 'Unknown';
        if ($user_id > 0) {
            $user_query = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = $user_id");
            if ($user_query && $user_query->num_rows > 0) {
                $user = $user_query->fetch_assoc();
                $user_name = trim($user['first_name'] . ' ' . $user['last_name']);
            }
        }
        
        $details = "Reserved plot {$section}-{$plot_x}-{$plot_y} for {$first_name} {$middle_name} {$last_name} {$suffix}";
        if (!empty($notes)) {
            $details .= " | Notes: {$notes}";
        }
        
        
        $log_stmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, ?, NOW())");
        if ($log_stmt) {
            $log_stmt->bind_param("ssi", 'Reserved By', $details, $user_id);
            $log_stmt->execute();
            $log_stmt->close();
            echo '<div class="alert alert-success">Plot reserved successfully!</div>';
        } else {
            echo '<div class="alert alert-danger">Error reserving plot: ' . htmlspecialchars($conn->error) . '</div>';
        }
    } elseif (isset($_POST['first_name'])) {
    $first_name = strtoupper(trim($_POST['first_name']));
    $middle_name = strtoupper(trim($_POST['middle_name']));
    $last_name = strtoupper(trim($_POST['last_name']));
    
    $raw_suffix = isset($_POST['suffix']) ? strtoupper(trim($_POST['suffix'])) : '';
    $other_suffix = isset($_POST['suffix_other']) ? strtoupper(trim($_POST['suffix_other'])) : '';
    $suffix = ($raw_suffix === 'OTHER') ? $other_suffix : $raw_suffix;
    $bday = $_POST['bday'];
    $born_in = strtoupper(trim($_POST['born_in']));
    $contact_no = trim($_POST['contact_no']);
    
    $contact_no = preg_replace('/[^0-9+\-]/', '', $contact_no);
    $date_died = $_POST['date_died'];
    $interment_date = !empty($_POST['interment_date']) ? $_POST['interment_date'] : $date_died;
    $funeral_service = isset($_POST['funeral_service']) ? strtoupper(trim($_POST['funeral_service'])) : '';
    $section = strtoupper(trim($_POST['section']));
    $plot_x = isset($_POST['plot_x']) ? intval($_POST['plot_x']) : null;
    $plot_y = isset($_POST['plot_y']) ? intval($_POST['plot_y']) : null;    

    $today = date('Y-m-d');
    $error = '';
    if ($bday > $today) {
        $error = 'Birthday cannot be a future date.';
    } elseif ($date_died > $today) {
        $error = 'Date died cannot be a future date.';
    }
    if ($error) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
    } else {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        
        
        $edited_by = 'Unknown';
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            $user_id = intval($_SESSION['user_id']);
            $editor_query = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = $user_id");
            if ($editor_query && $editor_query->num_rows > 0) {
                $editor = $editor_query->fetch_assoc();
                if ($editor && !empty($editor['first_name']) && !empty($editor['last_name'])) {
                    $edited_by = trim($editor['first_name'] . ' ' . $editor['last_name']);
                }
            }
        }

        $mode = isset($_POST['mode']) ? $_POST['mode'] : 'insert';
        
        $has_suffix_col = false;
        if ($colRes = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'suffix'")) {
            $has_suffix_col = $colRes->num_rows > 0;
            $colRes->free();
        }
        
        $has_added_by_col = false;
        if ($colResAB = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'added_by'")) {
            $has_added_by_col = $colResAB->num_rows > 0;
            $colResAB->free();
        }
        $has_edited_by_col = false;
        if ($colResEB = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'edited_by'")) {
            $has_edited_by_col = $colResEB->num_rows > 0;
            $colResEB->free();
        }
        $has_interment_col = false;
        if ($colResInt = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'interment_date'")) {
            $has_interment_col = $colResInt->num_rows > 0;
            $colResInt->free();
        }

        if ($mode === 'update' && isset($_POST['orig_section'], $_POST['orig_plot_x'], $_POST['orig_plot_y'])) {
            
            $orig_section = $_POST['orig_section'];
            $orig_plot_x = intval($_POST['orig_plot_x']);
            $orig_plot_y = intval($_POST['orig_plot_y']);

            if ($has_interment_col) {
                $stmt = $conn->prepare("UPDATE tbl_deceased SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, bday = ?, born_in = ?, contact_no = ?, date_died = ?, interment_date = ?, funeral_service = ?, section = ?, plot_x = ?, plot_y = ?, edited_by = ? WHERE section = ? AND plot_x = ? AND plot_y = ?");
                if ($stmt) {
                    $stmt->bind_param("sssssssssssiissii", $first_name, $middle_name, $last_name, $suffix, $bday, $born_in, $contact_no, $date_died, $interment_date, $funeral_service, $section, $plot_x, $plot_y, $edited_by, $orig_section, $orig_plot_x, $orig_plot_y);
                    $stmt->execute();
                    $stmt->close();
                }
            } elseif ($has_suffix_col) {
                $stmt = $conn->prepare("UPDATE tbl_deceased SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, bday = ?, born_in = ?, contact_no = ?, date_died = ?, funeral_service = ?, section = ?, plot_x = ?, plot_y = ?, edited_by = ? WHERE section = ? AND plot_x = ? AND plot_y = ?");
                if ($stmt) {
                    $stmt->bind_param("ssssssssssiissii", $first_name, $middle_name, $last_name, $suffix, $bday, $born_in, $contact_no, $date_died, $funeral_service, $section, $plot_x, $plot_y, $edited_by, $orig_section, $orig_plot_x, $orig_plot_y);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare("UPDATE tbl_deceased SET first_name = ?, middle_name = ?, last_name = ?, bday = ?, born_in = ?, contact_no = ?, date_died = ?, funeral_service = ?, section = ?, plot_x = ?, plot_y = ?, edited_by = ? WHERE section = ? AND plot_x = ? AND plot_y = ?");
                if ($stmt) {
                    $stmt->bind_param("sssssssssiissii", $first_name, $middle_name, $last_name, $bday, $born_in, $contact_no, $date_died, $funeral_service, $section, $plot_x, $plot_y, $edited_by, $orig_section, $orig_plot_x, $orig_plot_y);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        } else {
            
            
            $count_ok = true;
            $cnt_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_deceased WHERE section = ? AND plot_x = ? AND plot_y = ?");
            if ($cnt_stmt) {
                $cnt_stmt->bind_param("sii", $section, $plot_x, $plot_y);
                $cnt_stmt->execute();
                $cnt_res = $cnt_stmt->get_result();
                $cnt_row = $cnt_res ? $cnt_res->fetch_assoc() : ['c' => 0];
                if (intval($cnt_row['c'] ?? 0) >= 4) {
                    echo '<div class="alert alert-warning">This plot has reached the maximum of 4 deceased. No new record was created.</div>';
                    $count_ok = false;
                }
                $cnt_stmt->close();
            }

            if ($count_ok) {
                
                $intervalCheck = check_plot_interment_interval_eligibility($conn, $section, $plot_x, $plot_y, $interment_date);
                if (!$intervalCheck['is_eligible']) {
                    echo '<div class="alert alert-warning">' . htmlspecialchars($intervalCheck['message']) . '</div>';
                    $count_ok = false;
                }
            }

            if ($count_ok) {
            
            $is_duplicate = false;
            if ($has_suffix_col) {
                $dupe_stmt = $conn->prepare("SELECT deceased_id FROM tbl_deceased
                    WHERE UPPER(TRIM(first_name)) = ?
                      AND UPPER(TRIM(COALESCE(middle_name, ''))) = ?
                      AND UPPER(TRIM(last_name)) = ?
                      AND UPPER(TRIM(COALESCE(suffix, ''))) = ?
                      AND bday = ?
                      AND date_died = ?
                    LIMIT 1");
                if ($dupe_stmt) {
                    $mn = $middle_name !== '' ? $middle_name : '';
                    $sx = $suffix !== '' ? $suffix : '';
                    $dupe_stmt->bind_param("ssssss", $first_name, $mn, $last_name, $sx, $bday, $date_died);
                    $dupe_stmt->execute();
                    $dupe_res = $dupe_stmt->get_result();
                    if ($dupe_res && $dupe_res->num_rows > 0) { $is_duplicate = true; }
                    $dupe_stmt->close();
                }
            } else {
                $dupe_stmt = $conn->prepare("SELECT deceased_id FROM tbl_deceased
                    WHERE UPPER(TRIM(first_name)) = ?
                      AND UPPER(TRIM(COALESCE(middle_name, ''))) = ?
                      AND UPPER(TRIM(last_name)) = ?
                      AND bday = ?
                      AND date_died = ?
                    LIMIT 1");
                if ($dupe_stmt) {
                    $mn = $middle_name !== '' ? $middle_name : '';
                    $dupe_stmt->bind_param("sssss", $first_name, $mn, $last_name, $bday, $date_died);
                    $dupe_stmt->execute();
                    $dupe_res = $dupe_stmt->get_result();
                    if ($dupe_res && $dupe_res->num_rows > 0) { $is_duplicate = true; }
                    $dupe_stmt->close();
                }
            }

            if ($is_duplicate) {
                echo '<div class="alert alert-warning">Deceased person already exists with the same name and dates. No new record was created.</div>';
            } else {
            if ($has_interment_col) {
                
                $stmt = $conn->prepare("INSERT INTO tbl_deceased (first_name, middle_name, last_name, suffix, bday, born_in, contact_no, date_died, interment_date, funeral_service, section, plot_x, plot_y, added_by, edited_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $empty = '';
                    $stmt->bind_param("sssssssssssiiss", $first_name, $middle_name, $last_name, $suffix, $bday, $born_in, $contact_no, $date_died, $interment_date, $funeral_service, $section, $plot_x, $plot_y, $edited_by, $empty);
                    $stmt->execute();
                    $stmt->close();
                }
            } elseif ($has_suffix_col && $has_added_by_col) {
                
                $stmt = $conn->prepare("INSERT INTO tbl_deceased (first_name, middle_name, last_name, suffix, bday, born_in, contact_no, date_died, funeral_service, section, plot_x, plot_y, added_by, edited_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $empty = '';
                    $stmt->bind_param("ssssssssssiiss", $first_name, $middle_name, $last_name, $suffix, $bday, $born_in, $contact_no, $date_died, $funeral_service, $section, $plot_x, $plot_y, $edited_by, $empty);
                    $stmt->execute();
                    $stmt->close();
                }
            } elseif ($has_suffix_col && !$has_added_by_col) {
                
                $stmt = $conn->prepare("INSERT INTO tbl_deceased (first_name, middle_name, last_name, suffix, bday, born_in, contact_no, date_died, funeral_service, section, plot_x, plot_y, edited_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("ssssssssssiis", $first_name, $middle_name, $last_name, $suffix, $bday, $born_in, $contact_no, $date_died, $funeral_service, $section, $plot_x, $plot_y, $edited_by);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO tbl_deceased (first_name, middle_name, last_name, bday, born_in, contact_no, date_died, funeral_service, section, plot_x, plot_y, edited_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("sssssssssiis", $first_name, $middle_name, $last_name, $bday, $born_in, $contact_no, $date_died, $funeral_service, $section, $plot_x, $plot_y, $edited_by);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            }
        }
    }
    }
}
}

$result = $conn->query("SELECT * FROM tbl_deceased ORDER BY date_died DESC");
$total = $result ? $result->num_rows : 0;

$reservationLogs = false; $reservationCount = 0;
$serviceList = []; $serviceCount = 0;
$bookingLogs = false; $bookingCount = 0;

$tblLogs = $conn->query("SHOW TABLES LIKE 'tbl_logs'");
if ($tblLogs && $tblLogs->num_rows > 0) {
    // Purge test_paying_user entries from tbl_logs and guest reservations (only when explicitly requested)
    if (!empty($_GET['force_schema_sync']) || !empty($_GET['cleanup_test_data'])) {
        $conn->query("DELETE FROM tbl_logs WHERE details LIKE '%test_paying_user%' OR action LIKE '%test_paying_user%'");
        if ($conn->query("SHOW TABLES LIKE 'guest_reservation_requests'")->num_rows > 0) {
            $conn->query("DELETE FROM guest_reservation_requests WHERE email LIKE '%test_paying_user%' OR full_name LIKE '%test_paying_user%'");
        }
    }
    
    $sqlReservationLogs = "SELECT l.id, l.action, l.details, l.timestamp, l.user_id,
                          CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))) AS user_name
                     FROM tbl_logs l
                LEFT JOIN tbl_user u ON u.user_id = l.user_id
                        WHERE (l.action IN (
                            'Reserved By',
                            'Updated Reservation',
                            'Reservation Pending',
                            'GUEST_RESERVATION_SUBMITTED',
                            'GUEST_NO_DECEASED_RESERVATION_SUBMITTED',
                            'DECEASED_VERIFICATION_SUBMITTED',
                            'GUEST_RESERVATION_APPROVED',
                            'GUEST_RESERVATION_REJECTED',
                            'GUEST_RESERVATION_CANCELLED',
                            'GUEST_RESERVATION_CANCEL_REQUESTED',
                            'GUEST_RESERVATION_DELETED',
                            'RESERVATION_CANCELLED',
                            'Cancelled Reservation'
                        ) OR l.action LIKE '%RESERVATION%')
                        AND l.details NOT LIKE '%test_paying_user%'
                        AND l.action NOT LIKE '%test_paying_user%'
                     ORDER BY l.timestamp DESC, l.id DESC";
    $reservationLogs = $conn->query($sqlReservationLogs);
    $reservationCount = $reservationLogs ? $reservationLogs->num_rows : 0;
}

if (function_exists('ensure_booking_table_integrity')) {
    ensure_booking_table_integrity($conn);
}

$serviceBookingIds = [];
$tblBookings = $conn->query("SHOW TABLES LIKE 'tbl_bookings'");
if ($tblBookings && $tblBookings->num_rows > 0) {
    $hasBookingPlots = ($conn->query("SHOW TABLES LIKE 'booking_plots'")->num_rows > 0);
    $hasPaymentReceipts = ($conn->query("SHOW TABLES LIKE 'payment_receipts'")->num_rows > 0);

    $multiPlotsSelect = $hasBookingPlots 
        ? "(SELECT GROUP_CONCAT(CONCAT(bp.section, ' - ', bp.plot_number) SEPARATOR ', ') FROM booking_plots bp WHERE bp.booking_id = b.id) AS multi_plots"
        : "NULL AS multi_plots";

    $prSelect = $hasPaymentReceipts
        ? "pr.proof_filename AS pr_proof_file, pr.payment_date AS pr_payment_date, pr.receipt_number AS pr_receipt_number, pr.payment_status AS pr_payment_status, pr.amount_paid AS pr_amount_paid,"
        : "NULL AS pr_proof_file, NULL AS pr_payment_date, NULL AS pr_receipt_number, NULL AS pr_payment_status, NULL AS pr_amount_paid,";

    $prJoin = $hasPaymentReceipts
        ? "LEFT JOIN payment_receipts pr ON pr.id = (SELECT id FROM payment_receipts WHERE booking_id = b.id ORDER BY id DESC LIMIT 1)"
        : "";

    // Connect Booking Logs page to tbl_booking_logs table with FK relationship: tbl_booking_logs.booking_id -> tbl_bookings.id
    $hasBookingLogsTable = ($conn->query("SHOW TABLES LIKE 'tbl_booking_logs'")->num_rows > 0);

    if ($hasBookingLogsTable) {
        $sqlBookingLogs = "SELECT 
                              b.*,
                              bl.id AS log_id,
                              bl.id AS booking_log_id,
                              bl.booking_id,
                              COALESCE(bl.booking_id, b.id) AS id,
                              COALESCE(NULLIF(bl.section, ''), b.section, '—') AS section,
                              COALESCE(NULLIF(bl.contact_number, ''), b.contact_number, '—') AS contact_number,
                              COALESCE(NULLIF(bl.booker_location, ''), b.booker_location, '—') AS booker_location,
                              COALESCE(bl.price, b.total_amount, b.plot_price, 0.00) AS price,
                              bl.timestamp AS log_timestamp,
                              COALESCE(NULLIF(b.customer_name, ''), NULLIF(b.full_name, ''), CONCAT_WS(' ', u.first_name, u.last_name)) AS display_customer_name,
                              COALESCE(NULLIF(b.customer_email, ''), NULLIF(b.email, ''), u.email) AS display_customer_email,
                              {$prSelect}
                              {$multiPlotsSelect}
                           FROM tbl_booking_logs bl
                           LEFT JOIN tbl_bookings b ON b.id = bl.booking_id
                           LEFT JOIN tbl_user u ON u.user_id = b.user_id
                           {$prJoin}
                           ORDER BY bl.id DESC";

        $bookingLogs = @$conn->query($sqlBookingLogs);
    }

    if (!$bookingLogs) {
        $bookingLogs = @$conn->query("SELECT b.*, 
            b.id AS log_id,
            b.id AS booking_id,
            COALESCE(NULLIF(b.customer_name, ''), NULLIF(b.full_name, ''), '') AS display_customer_name,
            COALESCE(NULLIF(b.customer_email, ''), NULLIF(b.email, ''), '') AS display_customer_email,
            NULL AS pr_proof_file, NULL AS pr_payment_date, NULL AS pr_receipt_number, NULL AS pr_payment_status, NULL AS pr_amount_paid,
            NULL AS multi_plots
            FROM tbl_bookings b ORDER BY b.id DESC");
    }
    $bookingCount = $bookingLogs ? $bookingLogs->num_rows : 0;

    $sqlSrvBookings = "SELECT b.*, 
                              COALESCE(NULLIF(b.customer_name, ''), NULLIF(b.full_name, ''), CONCAT_WS(' ', u.first_name, u.last_name), 'Guest') AS display_customer_name,
                              COALESCE(NULLIF(b.customer_email, ''), NULLIF(b.email, ''), u.email, '—') AS display_customer_email,
                              {$prSelect}
                              b.id AS srv_id
                         FROM tbl_bookings b
                    LEFT JOIN tbl_user u ON u.user_id = b.user_id
                    {$prJoin}
                        WHERE LOWER(TRIM(COALESCE(b.purpose, ''))) = 'cemetery_service'
                           OR LOWER(TRIM(COALESCE(b.service_type, ''))) IN ('cleaning', 'landscaping', 'maintenance', 'decoration')
                        ORDER BY b.id DESC";
    $srvRes = @$conn->query($sqlSrvBookings);
    if (!$srvRes) {
        $srvRes = @$conn->query("SELECT b.*,
            COALESCE(NULLIF(b.customer_name, ''), NULLIF(b.full_name, ''), 'Guest') AS display_customer_name,
            COALESCE(NULLIF(b.customer_email, ''), NULLIF(b.email, ''), '—') AS display_customer_email,
            NULL AS pr_proof_file, NULL AS pr_payment_date, NULL AS pr_receipt_number, NULL AS pr_payment_status, NULL AS pr_amount_paid,
            b.id AS srv_id
            FROM tbl_bookings b
            WHERE LOWER(TRIM(COALESCE(b.purpose, ''))) = 'cemetery_service'
               OR LOWER(TRIM(COALESCE(b.service_type, ''))) IN ('cleaning', 'landscaping', 'maintenance', 'decoration')
            ORDER BY b.id DESC");
    }
    if ($srvRes && $srvRes->num_rows > 0) {
        while ($bRow = $srvRes->fetch_assoc()) {
            $serviceBookingIds[(int)$bRow['id']] = true;
            $srvTotalAmt = floatval($bRow['service_price'] ?? ($bRow['total_amount'] ?? 200.00)) ?: 200.00;
            $srvAmtPaid = floatval($bRow['amount_paid'] ?? ($bRow['pr_amount_paid'] ?? 0));
            $serviceList[] = [
                'source' => 'booking',
                'id' => (int)$bRow['id'],
                'booking_id' => (int)$bRow['id'],
                'service_type' => !empty($bRow['service_type']) ? $bRow['service_type'] : 'Cemetery Service',
                'section' => $bRow['section'] ?? '—',
                'plot' => $bRow['plot'] ?? ($bRow['grave_location'] ?? '—'),
                'preferred_date' => $bRow['preferred_date'] ?? '—',
                'contact_number' => $bRow['contact_number'] ?? '—',
                'booker_location' => $bRow['booker_location'] ?? '—',
                'customer_name' => $bRow['display_customer_name'],
                'deceased_name' => !empty($bRow['deceased_name']) ? $bRow['deceased_name'] : '—',
                'customer_email' => $bRow['display_customer_email'],
                'remarks' => !empty($bRow['remarks']) ? $bRow['remarks'] : (!empty($bRow['special_requests']) ? $bRow['special_requests'] : 'None'),
                'total_amount' => $srvTotalAmt,
                'amount_paid' => $srvAmtPaid,
                'payment_type' => $bRow['payment_type'] ?? 'GCash',
                'reference_number' => !empty($bRow['reference_number']) ? $bRow['reference_number'] : (!empty($bRow['gcash_reference_number']) ? $bRow['gcash_reference_number'] : ($bRow['pr_receipt_number'] ?? 'N/A')),
                'payment_date' => !empty($bRow['payment_proof_uploaded_at']) ? $bRow['payment_proof_uploaded_at'] : ($bRow['pr_payment_date'] ?? $bRow['created_at']),
                'payment_proof_filename' => !empty($bRow['payment_proof_filename']) ? $bRow['payment_proof_filename'] : ($bRow['pr_proof_file'] ?? ''),
                'payment_status' => !empty($bRow['payment_status']) ? strtoupper(trim($bRow['payment_status'])) : (($srvTotalAmt > 0 && $srvAmtPaid >= ($srvTotalAmt - 0.009)) ? 'PAID' : (($srvAmtPaid > 0) ? 'PARTIAL' : 'PENDING')),
                'service_status' => !empty($bRow['service_status']) ? strtoupper(trim($bRow['service_status'])) : (strtolower($bRow['status'] ?? '') === 'completed' ? 'COMPLETED' : (strtolower($bRow['status'] ?? '') === 'approved' ? 'APPROVED' : 'PENDING')),
                'service_completion_proof' => $bRow['service_completion_proof'] ?? '',
                'service_completed_at' => $bRow['service_completed_at'] ?? '',
                'service_completed_by' => $bRow['service_completed_by'] ?? '',
                'status' => strtolower($bRow['status'] ?? 'pending'),
                'timestamp' => $bRow['created_at'] ?? date('Y-m-d H:i:s'),
            ];
        }
    }
}

if ($tblLogs && $tblLogs->num_rows > 0) {
    $sqlServiceLogs = "SELECT l.id, l.action, l.details, l.timestamp, l.user_id,
                              CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))) AS user_name
                         FROM tbl_logs l
                    LEFT JOIN tbl_user u ON u.user_id = l.user_id
                        WHERE l.action = 'SERVICE_BOOKING'
                        ORDER BY l.timestamp DESC, l.id DESC";
    $rawServiceLogs = $conn->query($sqlServiceLogs);
    if ($rawServiceLogs && $rawServiceLogs->num_rows > 0) {
        while ($lRow = $rawServiceLogs->fetch_assoc()) {
            $det = $lRow['details'] ?? '';
            $extractedId = null;
            if (preg_match('/(?:Booking|Booking ID)[:\s]*#?(\d+)/i', $det, $m)) {
                $extractedId = (int)$m[1];
            }
            if ($extractedId && isset($serviceBookingIds[$extractedId])) {
                continue;
            }
            $srvType = 'Cemetery Service';
            $srvPlot = '—';
            if (preg_match('/Service booked:\s*([A-Za-z0-9\s_-]+?)(?:\s+for\s+plot\s+([A-Za-z0-9,\s_-]+))?(?:\||$|\()/i', $det, $sm)) {
                $srvType = trim($sm[1]);
                if (!empty($sm[2])) $srvPlot = trim($sm[2]);
            }
            $serviceList[] = [
                'source' => 'log',
                'id' => (int)$lRow['id'],
                'booking_id' => $extractedId ?: (int)$lRow['id'],
                'service_type' => $srvType,
                'section' => '—',
                'plot' => $srvPlot,
                'preferred_date' => '—',
                'contact_number' => '—',
                'booker_location' => '—',
                'customer_name' => trim($lRow['user_name'] ?? '') !== '' ? trim($lRow['user_name']) : 'Guest',
                'deceased_name' => '—',
                'customer_email' => '—',
                'remarks' => $det,
                'total_amount' => 200.00,
                'amount_paid' => 200.00,
                'payment_type' => 'GCash',
                'reference_number' => 'N/A',
                'payment_date' => $lRow['timestamp'],
                'payment_proof_filename' => '',
                'status' => 'approved',
                'timestamp' => $lRow['timestamp'] ?? date('Y-m-d H:i:s'),
            ];
        }
    }
}
$serviceCount = count($serviceList);

// Status counts for Service Logs
$srvCountAll = $serviceCount;
$srvCountPending = 0;
$srvCountApproved = 0;
$srvCountCompleted = 0;
$srvCountRejected = 0;

foreach ($serviceList as $srvItem) {
    $st = strtoupper(trim((string)($srvItem['service_status'] ?? '')));
    if ($st === 'COMPLETED') {
        $srvCountCompleted++;
    } elseif ($st === 'REJECTED') {
        $srvCountRejected++;
    } elseif ($st === 'APPROVED' || $st === 'IN PROGRESS') {
        $srvCountApproved++;
    } else {
        $srvCountPending++;
    }
}

// Status counts for Booking Logs
$bkCounts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'completed' => 0, 'rejected' => 0, 'cancelled' => 0];
if (!empty($hasBookingLogsTable)) {
    $bkCountSql = "SELECT LOWER(TRIM(COALESCE(b.status, 'pending'))) AS st, COUNT(*) AS cnt 
                   FROM tbl_booking_logs bl 
                   LEFT JOIN tbl_bookings b ON b.id = bl.booking_id 
                   GROUP BY LOWER(TRIM(COALESCE(b.status, 'pending')))";
    $bkCountRes = @$conn->query($bkCountSql);
    if ($bkCountRes && $bkCountRes->num_rows > 0) {
        while ($bcRow = $bkCountRes->fetch_assoc()) {
            $stKey = $bcRow['st'];
            if ($stKey === '0' || $stKey === '') $stKey = 'approved';
            $cntVal = (int)$bcRow['cnt'];
            $bkCounts['all'] += $cntVal;
            if (isset($bkCounts[$stKey])) {
                $bkCounts[$stKey] += $cntVal;
            }
        }
    }
}
if ($bkCounts['all'] === 0) {
    $bkCountRes2 = @$conn->query("SELECT LOWER(TRIM(status)) AS st, COUNT(*) AS cnt FROM tbl_bookings GROUP BY LOWER(TRIM(status))");
    if ($bkCountRes2) {
        while ($bcRow = $bkCountRes2->fetch_assoc()) {
            $stKey = $bcRow['st'];
            if ($stKey === '0' || $stKey === '') $stKey = 'approved';
            $cntVal = (int)$bcRow['cnt'];
            $bkCounts['all'] += $cntVal;
            if (isset($bkCounts[$stKey])) {
                $bkCounts[$stKey] += $cntVal;
            }
        }
    }
}
$bkCounts['all'] = (int)$bookingCount;

$gcashSettings = get_gcash_payment_settings();
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deceased Logs - Garden of Remembrance Memorial Park</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/admin_header.css">
    <style>
        :root {
            --bs-primary: #7b1113;
        }
        body {
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            background-color: #f8f9fa;
        }
        .main {
            margin-left: 0;
            padding: 1.5rem 1rem 3rem;
            margin-top: 0;
        }
        .center-content {
            min-height: 50vh;
            margin-top: 40px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
        }
        .bg-primary, .btn-primary, .navbar.bg-primary, .table-primary, .border-primary {
            background-color: #7b1113 !important;
            border-color: #7b1113 !important;
        }
        .btn-primary:hover, .bg-primary:hover {
            background-color: #a03a3c !important;
            border-color: #a03a3c !important;
        }
        .text-primary {
            color: #7b1113 !important;
        }
        .booking-row, .reservation-row {
            cursor: pointer;
        }
        .booking-row:hover td, .reservation-row:hover td {
            background-color: #fff6f6;
        }
        .reservation-row:focus {
            outline: 2px solid #7b1113;
            outline-offset: -2px;
        }
        .booking-detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
        }
        .booking-detail-item {
            border: 1px solid #eee;
            border-radius: 0.5rem;
            padding: 0.75rem;
            background: #fff;
        }
        .booking-detail-label {
            color: #6c757d;
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.2rem;
            text-transform: uppercase;
        }
        .booking-detail-value {
            color: #212529;
            font-weight: 600;
            overflow-wrap: anywhere;
        }
        .card-wrap {
            background: #fff;
            border-radius: 1.25rem;
            box-shadow: 0 2px 16px rgba(160,58,60,0.08);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .search-input {
            border-radius: 2rem;
            border: 1px solid #dee2e6;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
        }
        .search-input:focus {
            border-color: #7b1113;
            box-shadow: 0 0 0 0.2rem rgba(123, 17, 19, 0.25);
        }
        .log-status-filters {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }
        .log-filter-btn {
            background: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #4b5563;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .log-filter-btn:hover {
            border-color: #7b1113;
            color: #7b1113;
            background: #fff8f8;
        }
        .log-filter-btn.active {
            background: #7b1113 !important;
            color: #ffffff !important;
            border-color: #7b1113 !important;
            box-shadow: 0 2px 8px rgba(123, 17, 19, 0.25);
        }
        .log-filter-btn .badge {
            font-size: 0.72rem;
            padding: 3px 7px;
            border-radius: 999px;
            font-weight: 700;
        }
        .log-filter-btn.active .badge {
            background: rgba(255, 255, 255, 0.28) !important;
            color: #ffffff !important;
        }
        .table-responsive {
            border-radius: 0.75rem;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
            box-shadow: 0 2px 8px rgba(160,58,60,0.1);
            max-width: 100%;
        }
        .table {
            margin-bottom: 0;
            width: 100%;
        }
        .table thead th {
            background-color: #7b1113 !important;
            color: #fff !important;
            font-weight: 600;
            font-size: 0.88rem;
            border: none;
            padding: 0.85rem 0.85rem;
            white-space: nowrap;
            vertical-align: middle;
        }
        .table tbody td {
            padding: 0.85rem 0.85rem;
            vertical-align: middle;
            border-color: #f1f3f4;
            font-size: 0.88rem;
            color: #495057;
            white-space: nowrap;
            line-height: 1.4;
        }
        /* Deceased logs table widths - generous sizing so all headers like Section, Plot X, Plot Y are fully visible */
        #deceasedLogsCard .table { min-width: 1350px; }
        #deceasedLogsCard .table th:nth-child(1), #deceasedLogsCard .table td:nth-child(1) { min-width: 110px; }
        #deceasedLogsCard .table th:nth-child(2), #deceasedLogsCard .table td:nth-child(2) { min-width: 110px; }
        #deceasedLogsCard .table th:nth-child(3), #deceasedLogsCard .table td:nth-child(3) { min-width: 110px; }
        #deceasedLogsCard .table th:nth-child(4), #deceasedLogsCard .table td:nth-child(4) { min-width: 70px; text-align: center; }
        #deceasedLogsCard .table th:nth-child(5), #deceasedLogsCard .table td:nth-child(5) { min-width: 110px; }
        #deceasedLogsCard .table th:nth-child(6), #deceasedLogsCard .table td:nth-child(6) { min-width: 120px; }
        #deceasedLogsCard .table th:nth-child(7), #deceasedLogsCard .table td:nth-child(7) { min-width: 120px; }
        #deceasedLogsCard .table th:nth-child(8), #deceasedLogsCard .table td:nth-child(8) { min-width: 110px; }
        #deceasedLogsCard .table th:nth-child(9), #deceasedLogsCard .table td:nth-child(9) { min-width: 150px; }
        #deceasedLogsCard .table th:nth-child(10), #deceasedLogsCard .table td:nth-child(10) { min-width: 90px; text-align: center; }
        #deceasedLogsCard .table th:nth-child(11), #deceasedLogsCard .table td:nth-child(11) { min-width: 80px; text-align: center; }
        #deceasedLogsCard .table th:nth-child(12), #deceasedLogsCard .table td:nth-child(12) { min-width: 80px; text-align: center; }
        #deceasedLogsCard .table th:nth-child(13), #deceasedLogsCard .table td:nth-child(13) { min-width: 150px; }

        /* Reservation logs table responsive widths */
        #reservationLogsCard .table {
            width: 100% !important;
            min-width: unset !important;
            table-layout: auto;
        }
        #reservationLogsCard .table th,
        #reservationLogsCard .table td {
            min-width: unset;
            padding: 0.5rem 0.4rem !important;
            font-size: 0.79rem;
            vertical-align: middle;
        }
        #reservationLogsCard .table thead th {
            font-size: 0.74rem !important;
            padding: 0.55rem 0.4rem !important;
            white-space: nowrap;
        }
        #reservationLogsCard .table th:nth-child(1), #reservationLogsCard .table td:nth-child(1) { width: 9%; min-width: 75px; }
        #reservationLogsCard .table th:nth-child(2), #reservationLogsCard .table td:nth-child(2) { width: 13%; min-width: 100px; }
        #reservationLogsCard .table th:nth-child(3), #reservationLogsCard .table td:nth-child(3) { width: 6%; min-width: 45px; text-align: center; }
        #reservationLogsCard .table th:nth-child(4), #reservationLogsCard .table td:nth-child(4) {
            width: 34%;
            white-space: normal !important;
            word-break: break-word;
            line-height: 1.35;
        }
        #reservationLogsCard .table th:nth-child(5), #reservationLogsCard .table td:nth-child(5) { width: 12%; min-width: 90px; }
        #reservationLogsCard .table th:nth-child(6), #reservationLogsCard .table td:nth-child(6) { width: 11%; min-width: 80px; font-size: 0.74rem; }
        #reservationLogsCard .table th:nth-child(7), #reservationLogsCard .table td:nth-child(7) { width: 15%; min-width: 95px; text-align: center; }

        /* Service logs table widths & clickable pointer */
        #serviceLogsCard .table { min-width: 1100px; }
        #serviceLogsCard .table th, #serviceLogsCard .table td { min-width: 100px; }

        /* Booking logs table responsive widths */
        #bookingLogsCard .table {
            width: 100%;
            min-width: unset;
            table-layout: auto;
        }
        #bookingLogsCard .table th, #bookingLogsCard .table td {
            min-width: unset;
            padding: 0.45rem 0.35rem !important;
            font-size: 0.78rem;
            vertical-align: middle;
        }
        #bookingLogsCard .table thead th {
            font-size: 0.70rem !important;
            padding: 0.5rem 0.35rem !important;
            white-space: nowrap;
        }

        /* Compact styling for Reservation Action buttons */
        .check-res-btn, .check-view-cemetery-btn {
            width: 100% !important;
            max-width: 105px !important;
            min-width: unset !important;
            padding: 3px 6px !important;
            font-size: 0.73rem !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            box-sizing: border-box !important;
            text-align: center !important;
            white-space: nowrap !important;
        }

        .service-row, .booking-row, .reservation-row {
            cursor: pointer;
            transition: background-color 0.15s ease-in-out;
        }
        .service-row:hover, .booking-row:hover, .reservation-row:hover {
            background-color: #f1f5f9 !important;
        }
        
        /* Hover effect for better visibility */
        .table tbody td:hover {
            background-color: #f8f9fa;
            position: relative;
            z-index: 5;
        }
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        .count-badge {
            background: #7b1113;
            color: #fff;
            border-radius: 999px;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .back-btn {
            background: #7b1113;
            color: #fff;
            border: none;
            border-radius: 2rem;
            padding: 0.5rem 1.5rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        .back-btn:hover {
            background: #a03a3c;
            color: #fff;
            transform: translateY(-1px);
        }
        .page-title {
            color: #7b1113;
            font-weight: 600;
            letter-spacing: 1px;
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            text-align: center;
            margin-bottom: 0.5rem;
        }
        .page-subtitle {
            color: #a03a3c;
            font-size: 1.1rem;
            letter-spacing: 0.5px;
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            text-align: center;
            margin-bottom: 2rem;
        }
        
        /* Additional responsive improvements */
        @media (max-width: 768px) {
            /* Main content adjustments */
            .main {
                padding: 1rem 0.5rem;
                margin-top: 0 !important;
            }
            
            .center-content {
                margin-top: 20px;
            }
            
            /* Page titles */
            .page-title {
                font-size: 1.25rem;
                margin-bottom: 0.25rem;
            }
            
            .page-subtitle {
                font-size: 0.9rem;
                margin-bottom: 1rem;
            }
            
            /* Card adjustments */
            .card-wrap {
                padding: 0.75rem;
                margin: 0 0.25rem 1rem 0.25rem;
                max-width: 100% !important;
                border-radius: 0.5rem;
            }
            
            /* Button group */
            .btn-group {
                width: 100%;
                margin-bottom: 1rem;
            }
            
            .btn-group .btn {
                flex: 1;
                font-size: 0.8rem;
                padding: 0.5rem 0.75rem;
            }
            
            /* Search input */
            .search-input {
                font-size: 0.9rem;
                padding: 0.75rem;
                border-radius: 0.5rem;
            }
            
            /* Table improvements for mobile */
            .table-responsive {
                border-radius: 0.5rem;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .table {
                min-width: 800px; /* Ensure table is scrollable but readable */
                font-size: 0.7rem;
                margin-bottom: 0;
            }
            
            .table thead th {
                font-size: 0.65rem;
                padding: 0.4rem 0.2rem;
                white-space: nowrap;
            }
            
            .table tbody td {
                font-size: 0.72rem;
                padding: 0.5rem 0.35rem;
                line-height: 1.3;
                overflow-wrap: anywhere;
                word-break: break-word;
            }
            
            /* Badge adjustments */
            .badge {
                font-size: 0.6rem;
                padding: 0.25rem 0.4rem;
            }
            
            /* Count badge */
            .count-badge {
                font-size: 0.8rem;
                padding: 0.4rem 0.8rem;
            }
            
            /* Back button */
            .back-btn {
                font-size: 0.8rem;
                padding: 0.4rem 1rem;
                width: 100%;
                justify-content: center;
                margin-top: 0.5rem;
            }
            
            /* Header adjustments */
            .card-wrap .d-flex {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 1rem;
            }
            
            .card-wrap h2 {
                font-size: 1.1rem;
                margin-bottom: 0.25rem;
            }
            
            .page-title {
                color: #7b1113;
                font-weight: 600;
                letter-spacing: 1px;
                font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
                text-align: center;
                margin-bottom: 0.5rem;
            }
            .page-subtitle {
                color: #a03a3c;
                font-size: 1.1rem;
                letter-spacing: 0.5px;
                font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
                text-align: center;
                margin-bottom: 2rem;
            }
            
            /* Additional responsive improvements */
            @media (max-width: 768px) {
                .booking-detail-grid {
                    grid-template-columns: 1fr;
                }

                /* Main content adjustments */
                .main {
                    padding: 1rem 0.5rem;
                    margin-top: 0 !important;
                }
                
                .center-content {
                    margin-top: 20px;
                }
                
                /* Page titles */
                .page-title {
                    font-size: 1.25rem;
                    margin-bottom: 0.25rem;
                }
                
                .page-subtitle {
                    font-size: 0.9rem;
                    margin-bottom: 1rem;
                }
                
                /* Card adjustments */
                .card-wrap {
                    padding: 0.75rem;
                    margin: 0 0.25rem 1rem 0.25rem;
                    max-width: 100% !important;
                    border-radius: 0.5rem;
                }
                
                /* Button group */
                .btn-group {
                    width: 100%;
                    margin-bottom: 1rem;
                }
                
                .btn-group .btn {
                    flex: 1;
                    font-size: 0.8rem;
                    padding: 0.5rem 0.75rem;
                }
                
                /* Search input */
                .search-input {
                    font-size: 0.9rem;
                    padding: 0.75rem;
                    border-radius: 0.5rem;
                }
                
                /* Table improvements for mobile */
                .table-responsive {
                    border-radius: 0.5rem;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                }
                
                .table {
                    min-width: 800px; /* Ensure table is scrollable but readable */
                    font-size: 0.7rem;
                    margin-bottom: 0;
                }
                
                .table thead th {
                    font-size: 0.65rem;
                    padding: 0.4rem 0.2rem;
                    white-space: nowrap;
                }
                
                .table tbody td {
                    font-size: 0.72rem;
                    padding: 0.5rem 0.35rem;
                    line-height: 1.3;
                    overflow-wrap: anywhere;
                    word-break: break-word;
                }
                
                /* Badge adjustments */
                .badge {
                    font-size: 0.6rem;
                    padding: 0.25rem 0.4rem;
                }
                
                /* Count badge */
                .count-badge {
                    font-size: 0.8rem;
                    padding: 0.4rem 0.8rem;
                }
                
                /* Back button */
                .back-btn {
                    font-size: 0.8rem;
                    padding: 0.4rem 1rem;
                    width: 100%;
                    justify-content: center;
                    margin-top: 0.5rem;
                }
                
                /* Header adjustments */
                .card-wrap .d-flex {
                    flex-direction: column;
                    align-items: flex-start !important;
                    gap: 1rem;
                }
                
                .card-wrap h2 {
                    font-size: 1.1rem;
                    margin-bottom: 0.25rem;
                }
                
                .card-wrap p {
                    font-size: 0.85rem;
                }
            }
            
            /* Extra small devices */
            @media (max-width: 480px) {
                .table {
                    min-width: 700px;
                    font-size: 0.65rem;
                }
                
                .table thead th,
                .table tbody td {
                    padding: 0.3rem 0.15rem;
                    font-size: 0.6rem;
                }
                
                .page-title {
                    font-size: 1.1rem;
                }
            }
            .swal2-container {
                z-index: 20000 !important;
            }
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body>
<?php
$active_nav = 'logs';
include __DIR__ . '/includes/admin_header.php';
?>
    
    <div class="main">
        <div class="container-fluid center-content">
            <h1 class="page-title mb-3">Garden of Remembrance Memorial Park</h1>
            <div class="w-100 d-flex justify-content-center mb-3" style="max-width: 1400px;">
                <div class="btn-group" role="group" aria-label="Log Sections">
                    <button id="btnDeceased" type="button" class="btn btn-primary">Deceased Logs</button>
                    <button id="btnReservation" type="button" class="btn btn-outline-primary">Reservation Logs</button>
                    <button id="btnService" type="button" class="btn btn-outline-primary">Service Logs</button>
                    <button id="btnBooking" type="button" class="btn btn-outline-primary">Request Logs</button>
                </div>
            </div>
            
            
            <div id="deceasedLogsCard" class="card-wrap w-100" style="max-width: 1400px;">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="mb-1" style="color: #7b1113; font-weight: 600; font-size: 1.5rem;">Deceased Records</h2>
                        <p class="text-muted mb-0">Complete log of all deceased person entries</p>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span class="count-badge">Total: <?php echo (int)$total; ?></span>
                        <a href="dashboard.php" class="back-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
                            </svg>
                            Back to Dashboard
                        </a>
                    </div>
                </div>
                
                
                <div class="mb-4" style="max-width: 520px;">
                    <div class="input-group shadow-sm" style="border-radius: 2rem; overflow: visible; border: 1px solid #d1d5db; background: #fff; position: relative;">
                        
                        <div class="dropdown">
                            <button type="button" class="btn btn-light border-0 px-3 text-muted position-relative" id="logsFilterBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Filter by Year, Previous Dates, &amp; Section" style="background: transparent; border-radius: 2rem 0 0 2rem; height: 38px;">
                                <i class="fas fa-sliders-h"></i>
                                <span id="logsFilterActiveDot" class="filter-active-dot" style="display:none;"></span>
                            </button>

                            
                            <div class="dropdown-menu shadow-lg p-3 border-0" aria-labelledby="logsFilterBtn" style="min-width: 300px; border-radius: 1rem; z-index: 1050;">
                                <div class="d-flex align-items-center justify-content-between mb-2 border-bottom pb-2">
                                    <span class="fw-bold text-dark small"><i class="fas fa-filter text-danger me-1"></i> Filter Logs</span>
                                    <button type="button" class="btn btn-link btn-sm text-danger p-0 text-decoration-none small" onclick="clearLogsFilters()">Reset</button>
                                </div>

                                
                                <div class="mb-2">
                                    <label class="form-label small fw-bold mb-1 text-dark" for="logsYearFilter">
                                        <i class="fas fa-calendar-alt text-danger me-1"></i> Year of Death:
                                    </label>
                                    <input type="number" id="logsYearFilter" class="form-control form-control-sm" placeholder="e.g. 2023" min="1900" max="2099">
                                    <div class="d-flex gap-1 mt-1 flex-wrap">
                                        <?php 
                                        $currentY = (int)date('Y');
                                        foreach ([$currentY, $currentY-1, $currentY-2, $currentY-3, $currentY-5] as $qy): 
                                        ?>
                                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 rounded-pill" style="font-size:0.75rem;" onclick="document.getElementById('logsYearFilter').value='<?= $qy ?>'; applyLogsFilter();"><?= $qy ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                
                                <div class="mb-2">
                                    <label class="form-label small fw-bold mb-1 text-dark">
                                        <i class="fas fa-history text-secondary me-1"></i> Check Previous Dates:
                                    </label>
                                    <div class="row g-1">
                                        <div class="col-6">
                                            <span class="text-muted" style="font-size: 0.72rem;">From:</span>
                                            <input type="date" id="logsDateFromFilter" class="form-control form-control-sm">
                                        </div>
                                        <div class="col-6">
                                            <span class="text-muted" style="font-size: 0.72rem;">To:</span>
                                            <input type="date" id="logsDateToFilter" class="form-control form-control-sm">
                                        </div>
                                    </div>
                                </div>

                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold mb-1" for="logsSectionFilter"><i class="fas fa-layer-group text-danger me-1"></i> Section:</label>
                                    <select id="logsSectionFilter" class="form-select form-select-sm">
                                        <option value="">All Sections</option>
                                        <option value="A">Section A</option>
                                        <option value="B">Section B</option>
                                        <option value="C">Section C</option>
                                        <option value="D">Section D</option>
                                        <option value="E">Section E</option>
                                        <option value="F">Section F</option>
                                    </select>
                                </div>

                                <button type="button" class="btn btn-sm btn-danger w-100 py-1 rounded-pill" onclick="applyLogsFilter(); bootstrap.Dropdown.getInstance(document.getElementById('logsFilterBtn'))?.hide();" style="background-color: #7b1113; border-color: #7b1113;">
                                    <i class="fas fa-check me-1"></i> Apply Filter
                                </button>
                            </div>
                        </div>

                        
                        <input id="tableFilter" type="search" class="form-control border-0 px-2" placeholder="Search by name, section, contact, or any field..." style="box-shadow:none; font-size: 0.9rem; background: transparent; height: 38px;">
                        
                        <span class="input-group-text bg-white border-0 pe-3 text-muted" style="border-radius: 0 2rem 2rem 0; height: 38px;">
                            <i class="fas fa-search"></i>
                        </span>
                    </div>

                    
                    <div id="logsActiveFilterChips" class="d-flex align-items-center gap-1 mt-2 flex-wrap" style="display:none !important; font-size: 0.8rem;">
                    </div>
                </div>
                
                
                <div class="table-responsive" style="max-height: 560px; overflow: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-primary" style="position: sticky; top: 0; z-index: 2;">
                            <tr>
                                <th>First Name</th>
                                <th>Middle Name</th>
                                <th>Last Name</th>
                                <th>Suffix</th>
                                <th>Birthday</th>
                                <th>Born In</th>
                                <th>Contact No.</th>
                                <th>Date Died</th>
                                <th>Funeral Service</th>
                                <th>Section</th>
                                <th>Plot X</th>
                                <th>Plot Y</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="logsTbody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php $counter = 1; while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['first_name'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['middle_name'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['last_name'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['suffix'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['bday'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['born_in'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['contact_no'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['date_died'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['funeral_service'] ?? '') ?></td>
                                        <td><span class="badge bg-primary"><?= htmlspecialchars($row['section'] ?? '') ?></span></td>
                                        <td><?= htmlspecialchars($row['plot_x'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['plot_y'] ?? '') ?></td>
                                        <td>
                                          <?php
                                            $addedBy = isset($row['added_by']) ? trim($row['added_by']) : '';
                                            $editedBy = isset($row['edited_by']) ? trim($row['edited_by']) : '';
                                            if ($editedBy !== '') {
                                                echo 'Updated by ' . htmlspecialchars($editedBy);
                                            } else if ($addedBy !== '') {
                                                echo 'Added by ' . htmlspecialchars($addedBy);
                                            } else {
                                                echo '<span class="text-muted">—</span>';
                                            }
                                          ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="13" class="text-center text-muted py-5">
                                        <div class="d-flex flex-column align-items-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="text-muted mb-3" viewBox="0 0 16 16">
                                                <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                                                <path d="M5.255 5.786a.237.237 0 0 0 .241.247h.825c.138 0 .248-.113.266-.25.09-.656.54-1.134 1.342-1.134.686 0 1.314.343 1.314 1.168 0 .635-.374.927-.965 1.371-.673.489-1.206 1.06-1.168 1.987l.003.217a.25.25 0 0 0 .25.246h.811a.25.25 0 0 0 .25-.25v-.105c0-.718.273-.927 1.01-1.486.609-.463 1.244-.977 1.244-2.056 0-1.511-1.276-2.241-2.673-2.241-1.267 0-2.655.59-2.75 2.286zm1.557 5.763c0 .533.425.927 1.01.927.609 0 1.028-.394 1.028-.927 0-.552-.42-.94-1.029-.94-.584 0-1.009.388-1.009.94z"/>
                                            </svg>
                                            <h5 class="text-muted mb-1">No Records Found</h5>
                                            <p class="text-muted mb-0">No deceased records have been added yet.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div id="reservationLogsCard" class="card-wrap w-100" style="max-width: 1400px; display:none;">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="mb-1" style="color: #7b1113; font-weight: 600; font-size: 1.5rem;">Reservation Logs</h2>
                        <p class="text-muted mb-0">Activity for plot reservations and ownership updates</p>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span class="count-badge">Total: <?php echo (int)$reservationCount; ?></span>
                        <a href="dashboard.php" class="back-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
                            </svg>
                            Back to Dashboard
                        </a>
                    </div>
                </div>

                
                <div class="mb-4">
                    <input id="reservationFilter" type="search" class="form-control search-input" placeholder="Search by action, details, or user...">
                </div>

                
                <div class="table-responsive" style="max-height: 560px; overflow: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-primary" style="position: sticky; top: 0; z-index: 2;">
                            <tr>
                                <th style="width: 9%;">Action</th>
                                <th style="width: 13%;">Reserved Plot</th>
                                <th style="width: 6%;" class="text-center">Old Details</th>
                                <th style="width: 34%;">New Details</th>
                                <th style="width: 12%;">Performed By</th>
                                <th style="width: 11%;">Timestamp</th>
                                <th style="width: 15%;" class="text-center">Action / View</th>
                            </tr>
                        </thead>
                        <tbody id="reservationTbody">
                        <?php if ($reservationLogs && $reservationLogs->num_rows > 0): ?>
                            <?php 
                            function parse_reservation_details($details) {
                                $out = ['prev'=>[], 'curr'=>[], 'plot'=>'', 'section'=>'', 'plot_x'=>'', 'plot_y'=>'', 'raw'=>trim((string)$details)];
                                if (!is_string($details) || $details === '') return $out;

                                
                                if (preg_match('/Section\s+([A-Za-z0-9]+)[,\s]+Plot\s+(\d+)\s*[-_,]\s*(\d+)/i', $details, $pm)) {
                                    $out['section'] = strtoupper(trim($pm[1]));
                                    $out['plot_x'] = trim($pm[2]);
                                    $out['plot_y'] = trim($pm[3]);
                                    $out['plot'] = 'Section ' . $out['section'] . ', Plot ' . $out['plot_x'] . '-' . $out['plot_y'];
                                } elseif (preg_match('/Plot:\s*([A-Za-z0-9]+)\s*[-_]\s*(\d+)\s*[-_]\s*(\d+)/i', $details, $pm)) {
                                    $out['section'] = strtoupper(trim($pm[1]));
                                    $out['plot_x'] = trim($pm[2]);
                                    $out['plot_y'] = trim($pm[3]);
                                    $out['plot'] = 'Section ' . $out['section'] . ', Plot ' . $out['plot_x'] . '-' . $out['plot_y'];
                                } elseif (preg_match('/Section\s+([A-Za-z0-9]+)[,\s]+Plot\s+([0-9\-]+)/i', $details, $pm)) {
                                    $out['section'] = strtoupper(trim($pm[1]));
                                    $pParts = explode('-', trim($pm[2]));
                                    if (count($pParts) >= 2) {
                                        $out['plot_x'] = trim($pParts[0]);
                                        $out['plot_y'] = trim($pParts[1]);
                                    }
                                    $out['plot'] = 'Section ' . $out['section'] . ', Plot ' . trim($pm[2]);
                                } elseif (preg_match('/(?:plot|location)\s*[:=]?\s*([A-Za-z0-9]+)[-_ ](\d+)[-_ ](\d+)/i', $details, $pm)) {
                                    $out['section'] = strtoupper(trim($pm[1]));
                                    $out['plot_x'] = trim($pm[2]);
                                    $out['plot_y'] = trim($pm[3]);
                                    $out['plot'] = 'Section ' . $out['section'] . ', Plot ' . $out['plot_x'] . '-' . $out['plot_y'];
                                } elseif (preg_match('/(?:^|\s)([A-Z])\s+(\d+)-(\d+)(?:\s|$|\|)/i', $details, $pm)) {
                                    $out['section'] = strtoupper(trim($pm[1]));
                                    $out['plot_x'] = trim($pm[2]);
                                    $out['plot_y'] = trim($pm[3]);
                                    $out['plot'] = 'Section ' . $out['section'] . ', Plot ' . $out['plot_x'] . '-' . $out['plot_y'];
                                }

                                
                                $parts = explode('|', $details);
                                $oldStr = '';
                                $newStr = '';
                                foreach ($parts as $p) {
                                    if (stripos($p, 'OLD:') !== false) { $oldStr = trim(substr($p, stripos($p, 'OLD:') + 4)); }
                                    if (stripos($p, 'NEW:') !== false) { $newStr = trim(substr($p, stripos($p, 'NEW:') + 4)); }
                                }
                                $kv = function($s){
                                    $arr = [];
                                    $ignoredKeys = ['PLAN', 'PAYMENT', 'AMOUNT', 'PRICE', 'METHOD', 'GCASH', 'PROOF', 'RECEIPT', 'TRANSACTION', 'REF', 'REFERENCE', 'PAID', 'BALANCE', 'DOWNPAYMENT', 'STATUS', 'VERIFIED', 'VERIFICATION'];
                                    foreach (explode(',', $s) as $pair) {
                                        $pair = trim($pair);
                                        if ($pair === '') continue;
                                        $parts2 = explode('=', $pair, 2);
                                        if (count($parts2) === 2) {
                                            $k = strtoupper(trim($parts2[0]));
                                            if (in_array($k, $ignoredKeys, true)) continue;
                                            $v = trim($parts2[1]);
                                            $arr[$k] = $v;
                                        }
                                    }
                                    return $arr;
                                };
                                if ($oldStr !== '') $out['prev'] = $kv($oldStr);
                                if ($newStr !== '') $out['curr'] = $kv($newStr);

                                
                                if (empty($out['plot'])) {
                                    if (!empty($out['curr']['PLOT'])) {
                                        $out['plot'] = $out['curr']['PLOT'];
                                    } elseif (!empty($out['prev']['PLOT'])) {
                                        $out['plot'] = $out['prev']['PLOT'];
                                    }
                                }

                                if (empty($out['section']) && !empty($out['plot'])) {
                                    if (preg_match('/(?:Section\s+)?([A-Za-z0-9]+)[,\s]+(?:Plot\s+)?(\d+)[-_ ](\d+)/i', $out['plot'], $m)) {
                                        $out['section'] = strtoupper(trim($m[1]));
                                        $out['plot_x'] = trim($m[2]);
                                        $out['plot_y'] = trim($m[3]);
                                    }
                                }

                                return $out;
                            }

                            $formatReservationKv = function($k, $v) {
                                $ignoredKeys = ['PLAN', 'PAYMENT', 'AMOUNT', 'PRICE', 'METHOD', 'GCASH', 'PROOF', 'RECEIPT', 'TRANSACTION', 'REF', 'REFERENCE', 'PAID', 'BALANCE', 'DOWNPAYMENT', 'STATUS', 'VERIFIED', 'VERIFICATION'];
                                if (in_array(strtoupper($k), $ignoredKeys, true)) {
                                    return '';
                                }
                                $labels = [
                                    'OWNER' => 'Owner / Applicant',
                                    'PHONE' => 'Contact No.',
                                    'EMAIL' => 'Email',
                                    'ADDRESS' => 'Address / Location',
                                    'BDAY' => 'Birth Date',
                                    'BOOKING' => 'Booking ID',
                                    'DATE' => 'Preferred Date',
                                    'PLOT' => 'Plot',
                                    'CANCELLED BY' => 'Cancelled By',
                                    'REASON' => 'Cancellation Reason',
                                    'POLICY' => 'Refund Policy',
                                    'PURPOSE' => 'Purpose'
                                ];
                                $displayKey = $labels[$k] ?? ucfirst(strtolower($k));
                                if ($k === 'BOOKING') {
                                    return '<span class="badge bg-light text-dark border me-1">Booking: ' . htmlspecialchars($v) . '</span>';
                                }
                                if ($k === 'CANCELLED BY') {
                                    return '<span class="text-danger small fw-bold">' . htmlspecialchars($displayKey) . ':</span> <span class="fw-bold text-dark">' . htmlspecialchars($v) . '</span>';
                                }
                                if ($k === 'REASON') {
                                    return '<span class="text-muted small">' . htmlspecialchars($displayKey) . ':</span> <span class="fst-italic text-dark">' . htmlspecialchars($v) . '</span>';
                                }
                                if ($k === 'POLICY') {
                                    return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">' . htmlspecialchars($v) . '</span>';
                                }
                                return '<span class="text-muted small">' . htmlspecialchars($displayKey) . ':</span> <span class="fw-semibold text-dark">' . htmlspecialchars($v) . '</span>';
                            };

                            $r = 1; while ($log = $reservationLogs->fetch_assoc()): 
                                $parsed = parse_reservation_details($log['details'] ?? '');
                                $plotDisplay = $parsed['plot'] ?? '';
                                $changedPrev = [];
                                $changedCurr = [];

                                if (!empty($parsed['prev']) || !empty($parsed['curr'])) {
                                    $keys = array_unique(array_merge(array_keys($parsed['prev']), array_keys($parsed['curr'])));
                                    foreach ($keys as $k) {
                                        if ($k === 'PLOT' && !empty($plotDisplay)) continue;
                                        $pv = $parsed['prev'][$k] ?? '';
                                        $cv = $parsed['curr'][$k] ?? '';
                                        if ($pv !== $cv) {
                                            $fPrev = $formatReservationKv($k, $pv);
                                            $fCurr = $formatReservationKv($k, $cv);
                                            if ($pv !== '' && $fPrev !== '') $changedPrev[] = $fPrev;
                                            if ($cv !== '' && $fCurr !== '') $changedCurr[] = $fCurr;
                                        }
                                    }
                                }
                                
                                if (empty($changedPrev) && empty($changedCurr) && isset($log['action'])) {
                                    $det = (string)($log['details'] ?? '');
                                    $actLower = strtolower(trim($log['action']));
                                    
                                    if (preg_match('/reserved\s+for\s+([^,.]+)/i', $det, $m)) {
                                        $owner = trim($m[1]);
                                        if ($owner !== '') {
                                            $parsed['curr']['OWNER'] = $owner;
                                            $f = $formatReservationKv('OWNER', $owner);
                                            if ($f !== '') $changedCurr[] = $f;
                                        }
                                    } elseif (preg_match('/(?:submitted by|request #\d+ for)\s+([^(.,]+)/i', $det, $m)) {
                                        $owner = trim($m[1]);
                                        if ($owner !== '') {
                                            $parsed['curr']['OWNER'] = $owner;
                                            $f = $formatReservationKv('OWNER', $owner);
                                            if ($f !== '') $changedCurr[] = $f;
                                        }
                                    }

                                    if (preg_match('/Cancelled by:\s*([^.]+)/i', $det, $m)) {
                                        $canBy = trim($m[1]);
                                        if ($canBy !== '') { $f = $formatReservationKv('CANCELLED BY', $canBy); if ($f !== '') $changedCurr[] = $f; }
                                    }
                                    if (preg_match('/Reason:\s*([^.]+)/i', $det, $m)) {
                                        $rzn = trim($m[1]);
                                        if ($rzn !== '') { $f = $formatReservationKv('REASON', $rzn); if ($f !== '') $changedCurr[] = $f; }
                                    }
                                    if (stripos($det, 'no refund') !== false) {
                                        $f = $formatReservationKv('POLICY', 'No Refund Issued');
                                        if ($f !== '') $changedCurr[] = $f;
                                    }
                                    if (preg_match('/Purpose:\s*([^.]+)/i', $det, $m)) {
                                        $purp = trim($m[1]);
                                        if ($purp !== '') {
                                            $parsed['curr']['PURPOSE'] = $purp;
                                            $f = $formatReservationKv('PURPOSE', $purp);
                                            if ($f !== '') $changedCurr[] = $f;
                                        }
                                    }
                                    if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $det, $m)) {
                                        $em = trim($m[1]);
                                        if ($em !== '') {
                                            $parsed['curr']['EMAIL'] = $em;
                                            $f = $formatReservationKv('EMAIL', $em);
                                            if ($f !== '') $changedCurr[] = $f;
                                        }
                                    }
                                    if (preg_match('/(09\d{9})/i', $det, $m)) {
                                        $ph = trim($m[1]);
                                        if ($ph !== '') {
                                            $parsed['curr']['PHONE'] = $ph;
                                            $f = $formatReservationKv('PHONE', $ph);
                                            if ($f !== '') $changedCurr[] = $f;
                                        }
                                    }
                                    if (preg_match('/(?:Booking|Request)\s*#([A-Za-z0-9-]+)/i', $det, $m)) {
                                        $bk = trim($m[1]);
                                        if ($bk !== '') {
                                            $parsed['curr']['BOOKING'] = '#' . $bk;
                                            $f = $formatReservationKv('BOOKING', '#' . $bk);
                                            if ($f !== '') $changedCurr[] = $f;
                                        }
                                    }
                                }
                                
                                $ownerPrev = isset($parsed['prev']['OWNER']) ? trim($parsed['prev']['OWNER']) : '';
                                $ownerCurr = isset($parsed['curr']['OWNER']) ? trim($parsed['curr']['OWNER']) : '';
                                $ownerPrevFormatted = $ownerPrev !== '' ? $formatReservationKv('OWNER', $ownerPrev) : '';
                                $ownerCurrFormatted = $ownerCurr !== '' ? $formatReservationKv('OWNER', $ownerCurr) : '';
                                if ($ownerPrev !== '' && $ownerPrevFormatted !== '' && !in_array($ownerPrevFormatted, $changedPrev, true)) {
                                    array_unshift($changedPrev, $ownerPrevFormatted);
                                }
                                if ($ownerCurr !== '' && $ownerCurrFormatted !== '' && !in_array($ownerCurrFormatted, $changedCurr, true)) {
                                    array_unshift($changedCurr, $ownerCurrFormatted);
                                }

                                $prevHtml = !empty($changedPrev) ? implode('<br>', $changedPrev) : '<span class="text-muted">—</span>';
                                $currHtml = !empty($changedCurr) ? implode('<br>', $changedCurr) : '<span class="text-muted">—</span>';

                                // Extract applicant details (available for pending reservations)
                                $applicantName = $ownerCurr ?: ($ownerPrev ?: '');
                                $applicantPhone = $parsed['curr']['PHONE'] ?? ($parsed['prev']['PHONE'] ?? '');
                                $applicantEmail = $parsed['curr']['EMAIL'] ?? ($parsed['prev']['EMAIL'] ?? '');
                                $applicantAddress = $parsed['curr']['ADDRESS'] ?? ($parsed['prev']['ADDRESS'] ?? '');
                                $rawBookingId = '';
                                if (!empty($parsed['curr']['BOOKING'])) {
                                    $rawBookingId = ltrim(trim($parsed['curr']['BOOKING']), '#');
                                } elseif (preg_match('/(?:Booking|Request)\s*#(\d+)/i', $log['details'] ?? '', $bm)) {
                                    $rawBookingId = $bm[1];
                                }

                                // Check plot status, registered plot owner & approval status
                                $isHistoricalPlot = false;
                                $currentPlotStatus = 'Unknown';
                                $isApprovedOwner = false;
                                $ownerName = '';
                                $ownerPhone = '';
                                $ownerEmail = '';
                                $ownerAddress = '';
                                $ownerBooking = !empty($rawBookingId) ? ('#' . $rawBookingId) : '';

                                if (!empty($parsed['section']) && !empty($parsed['plot_x']) && !empty($parsed['plot_y'])) {
                                    $pSecEsc = $conn->real_escape_string($parsed['section']);
                                    $pXEsc = (int)$parsed['plot_x'];
                                    $pYEsc = (int)$parsed['plot_y'];
                                    $plotChk = $conn->query("SELECT status FROM tbl_plots WHERE UPPER(TRIM(section)) = '{$pSecEsc}' AND plot_x = {$pXEsc} AND plot_y = {$pYEsc} LIMIT 1");
                                    if ($plotChk && ($pRow = $plotChk->fetch_assoc())) {
                                        $currentPlotStatus = ucfirst(strtolower(trim((string)$pRow['status'])));
                                        if (strtolower(trim((string)$pRow['status'])) === 'available') {
                                            $isHistoricalPlot = true;
                                        }
                                    }

                                    // 1. Check tbl_plot_owners for active approved owner
                                    $ownChk = $conn->query("SELECT owner_name, first_name, middle_name, last_name, phone_no, address, reserved_at FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = '{$pSecEsc}' AND plot_x = {$pXEsc} AND plot_y = {$pYEsc} AND active = 1 ORDER BY id DESC LIMIT 1");
                                    if ($ownChk && ($oRow = $ownChk->fetch_assoc())) {
                                        $isApprovedOwner = true;
                                        $ownerName = trim((string)$oRow['owner_name']);
                                        $ownerPhone = trim((string)$oRow['phone_no']);
                                        $ownerAddress = trim((string)$oRow['address']);
                                    }

                                    // 2. Check tbl_bookings for an approved booking for this plot
                                    $pStr1 = "{$pXEsc}-{$pYEsc}";
                                    $pStr2 = "{$pXEsc}, {$pYEsc}";
                                    $pStr3 = "{$pXEsc},{$pYEsc}";
                                    $bkChk = $conn->query("SELECT id, customer_name, full_name, contact_number, customer_email, email, booker_location, status FROM tbl_bookings WHERE UPPER(TRIM(section)) = '{$pSecEsc}' AND (plot IN ('{$pStr1}','{$pStr2}','{$pStr3}') OR grave_location IN ('{$pStr1}','{$pStr2}','{$pStr3}')) AND LOWER(TRIM(status)) IN ('approved', 'completed') ORDER BY id DESC LIMIT 1");
                                    if ($bkChk && ($bRow = $bkChk->fetch_assoc())) {
                                        $isApprovedOwner = true;
                                        if (empty($ownerName)) {
                                            $ownerName = trim((string)(!empty($bRow['customer_name']) ? $bRow['customer_name'] : ($bRow['full_name'] ?? '')));
                                        }
                                        if (empty($ownerPhone)) {
                                            $ownerPhone = trim((string)($bRow['contact_number'] ?? ''));
                                        }
                                        if (empty($ownerAddress)) {
                                            $ownerAddress = trim((string)($bRow['booker_location'] ?? ''));
                                        }
                                        if (empty($ownerEmail)) {
                                            $ownerEmail = trim((string)(!empty($bRow['customer_email']) ? $bRow['customer_email'] : ($bRow['email'] ?? '')));
                                        }
                                        if (!empty($bRow['id'])) {
                                            $ownerBooking = '#' . $bRow['id'];
                                        }
                                    }
                                }

                                // 3. Check if specific booking ID was approved
                                if (!empty($rawBookingId) && is_numeric($rawBookingId)) {
                                    $bkCheckById = $conn->query("SELECT id, customer_name, full_name, contact_number, customer_email, email, booker_location, status FROM tbl_bookings WHERE id = " . (int)$rawBookingId . " LIMIT 1");
                                    if ($bkCheckById && ($bRow2 = $bkCheckById->fetch_assoc())) {
                                        $bSt = strtolower(trim((string)($bRow2['status'] ?? '')));
                                        if (in_array($bSt, ['approved', 'completed'], true)) {
                                            $isApprovedOwner = true;
                                            if (empty($ownerName)) {
                                                $ownerName = trim((string)(!empty($bRow2['customer_name']) ? $bRow2['customer_name'] : ($bRow2['full_name'] ?? '')));
                                            }
                                            if (empty($ownerPhone)) {
                                                $ownerPhone = trim((string)($bRow2['contact_number'] ?? ''));
                                            }
                                            if (empty($ownerAddress)) {
                                                $ownerAddress = trim((string)($bRow2['booker_location'] ?? ''));
                                            }
                                            if (empty($ownerEmail)) {
                                                $ownerEmail = trim((string)(!empty($bRow2['customer_email']) ? $bRow2['customer_email'] : ($bRow2['email'] ?? '')));
                                            }
                                            $ownerBooking = '#' . $bRow2['id'];
                                        }
                                    }
                                }

                                // 4. If log action itself is an approval action
                                if (stripos($log['action'] ?? '', 'APPROVED') !== false) {
                                    $isApprovedOwner = true;
                                    if (empty($ownerName) && !empty($applicantName)) $ownerName = $applicantName;
                                    if (empty($ownerEmail) && !empty($applicantEmail)) $ownerEmail = $applicantEmail;
                                    if (empty($ownerPhone) && !empty($applicantPhone)) $ownerPhone = $applicantPhone;
                                }

                                // If approved, apply applicant email/phone as fallback if owner table didn't have email
                                if ($isApprovedOwner) {
                                    if (empty($ownerEmail) && !empty($applicantEmail)) $ownerEmail = $applicantEmail;
                                    if (empty($ownerPhone) && !empty($applicantPhone)) $ownerPhone = $applicantPhone;
                                    if (empty($ownerName) && !empty($applicantName)) $ownerName = $applicantName;
                                    if (strtolower($currentPlotStatus) === 'available') {
                                        $currentPlotStatus = 'Reserved';
                                        $isHistoricalPlot = false;
                                    }
                                }
                            ?>
                                <tr class="reservation-row"
                                    tabindex="0"
                                    role="button"
                                    title="Click to check reservation details"
                                    data-log-id="<?php echo (int)$log['id']; ?>"
                                    data-action="<?php echo htmlspecialchars($log['action'] ?? '', ENT_QUOTES); ?>"
                                    data-section="<?php echo htmlspecialchars($parsed['section'] ?? '', ENT_QUOTES); ?>"
                                    data-plot-x="<?php echo htmlspecialchars($parsed['plot_x'] ?? '', ENT_QUOTES); ?>"
                                    data-plot-y="<?php echo htmlspecialchars($parsed['plot_y'] ?? '', ENT_QUOTES); ?>"
                                    data-plot="<?php echo htmlspecialchars($plotDisplay, ENT_QUOTES); ?>"
                                    data-is-approved="<?php echo $isApprovedOwner ? '1' : '0'; ?>"
                                    data-owner="<?php echo htmlspecialchars($ownerName, ENT_QUOTES); ?>"
                                    data-phone="<?php echo htmlspecialchars($isApprovedOwner ? $ownerPhone : '', ENT_QUOTES); ?>"
                                    data-email="<?php echo htmlspecialchars($isApprovedOwner ? $ownerEmail : '', ENT_QUOTES); ?>"
                                    data-address="<?php echo htmlspecialchars($isApprovedOwner ? $ownerAddress : '', ENT_QUOTES); ?>"
                                    data-booking="<?php echo htmlspecialchars($ownerBooking ?: (!empty($rawBookingId) ? ('#' . $rawBookingId) : ''), ENT_QUOTES); ?>"
                                    data-raw-booking-id="<?php echo htmlspecialchars($rawBookingId, ENT_QUOTES); ?>"
                                    data-applicant-name="<?php echo htmlspecialchars($applicantName, ENT_QUOTES); ?>"
                                    data-applicant-phone="<?php echo htmlspecialchars($applicantPhone, ENT_QUOTES); ?>"
                                    data-applicant-email="<?php echo htmlspecialchars($applicantEmail, ENT_QUOTES); ?>"
                                    data-plot-status="<?php echo htmlspecialchars($currentPlotStatus, ENT_QUOTES); ?>"
                                    data-current-status="<?php echo htmlspecialchars(strtolower($currentPlotStatus), ENT_QUOTES); ?>"
                                    data-is-historical="<?php echo $isHistoricalPlot ? '1' : '0'; ?>"
                                    data-performed-by="<?php echo htmlspecialchars(trim($log['user_name'] ?? '') !== '' ? trim($log['user_name']) : 'Guest', ENT_QUOTES); ?>"
                                    data-timestamp="<?php echo htmlspecialchars($log['timestamp'] ?? '', ENT_QUOTES); ?>"
                                    data-prev-html="<?php echo htmlspecialchars($prevHtml, ENT_QUOTES); ?>"
                                    data-curr-html="<?php echo htmlspecialchars($currHtml, ENT_QUOTES); ?>">
                                    <?php
                                        $actBadgeClass = 'bg-primary';
                                        $actName = strtoupper(trim((string)($log['action'] ?? '')));
                                        if (stripos($actName, 'CANCEL') !== false) {
                                            $actBadgeClass = 'bg-danger';
                                        } elseif (stripos($actName, 'REJECT') !== false) {
                                            $actBadgeClass = 'bg-dark';
                                        } elseif (stripos($actName, 'APPROVED') !== false || $actName === 'RESERVED BY') {
                                            $actBadgeClass = 'bg-success';
                                        } elseif (stripos($actName, 'SUBMITTED') !== false || stripos($actName, 'PENDING') !== false) {
                                            $actBadgeClass = 'bg-warning text-dark';
                                        } elseif (stripos($actName, 'UPDATED') !== false) {
                                            $actBadgeClass = 'bg-info text-dark';
                                        }
                                    ?>
                                    <td style="white-space: normal;"><span class="badge <?php echo $actBadgeClass; ?>" style="white-space: normal; word-break: break-word; text-align: left; line-height: 1.25; font-size: 0.74rem; padding: 4px 6px;"><?php echo htmlspecialchars(str_replace('_', ' ', $log['action'] ?? '')); ?></span></td>
                                    <td>
                                        <?php if (!empty($plotDisplay)): ?>
                                            <div class="d-flex align-items-center flex-wrap gap-1">
                                                <span class="badge" style="background-color: #7b1113; font-size: 0.77rem; font-weight: 600; padding: 4px 7px;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" fill="currentColor" class="me-1" viewBox="0 0 16 16"><path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10zm0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/></svg><?php echo htmlspecialchars($plotDisplay); ?>
                                                </span>
                                                <?php if ($isHistoricalPlot): ?>
                                                    <span class="badge bg-secondary ms-1" style="font-size: 0.68rem; padding: 3px 6px;">Historical Log</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center" style="font-size: 0.78rem;"><?php echo $prevHtml; ?></td>
                                    <td style="white-space: normal !important; word-break: break-word; font-size: 0.76rem; line-height: 1.35;"><?php echo $currHtml; ?></td>
                                    <td data-label="Performed By" style="font-size: 0.78rem; white-space: normal !important; word-break: break-word; overflow-wrap: anywhere; min-width: 85px; max-width: 130px;">
                                        <div class="fw-semibold text-dark" style="word-break: break-word; overflow-wrap: anywhere; line-height: 1.25;">
                                            <?php echo htmlspecialchars(trim($log['user_name'] ?? '')) !== '' ? htmlspecialchars(trim($log['user_name'])) : '<span class="text-muted">Guest</span>'; ?>
                                        </div>
                                    </td>
                                    <td style="font-size: 0.74rem; white-space: nowrap;">
                                        <?php 
                                            $tsVal = trim((string)($log['timestamp'] ?? ''));
                                            if ($tsVal !== '') {
                                                $tsParts = explode(' ', $tsVal, 2);
                                                echo '<div class="fw-semibold">' . htmlspecialchars($tsParts[0]) . '</div>';
                                                if (isset($tsParts[1])) {
                                                    echo '<small class="text-muted" style="font-size: 0.71rem;">' . htmlspecialchars($tsParts[1]) . '</small>';
                                                }
                                            } else {
                                                echo '<span class="text-muted">—</span>';
                                            }
                                        ?>
                                    </td>
                                    <td class="text-center" style="white-space: nowrap;">
                                        <div class="d-flex flex-column align-items-center justify-content-center gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-secondary check-res-btn" title="Click to check reservation details" style="border-radius: 6px;">
                                                <i class="fas fa-eye me-1"></i>Check
                                            </button>
                                            <?php if (!empty($parsed['section']) && !empty($parsed['plot_x']) && !empty($parsed['plot_y'])): ?>
                                                <a href="map.php?section=<?php echo urlencode($parsed['section']); ?>&plot_x=<?php echo urlencode($parsed['plot_x']); ?>&plot_y=<?php echo urlencode($parsed['plot_y']); ?>&highlight_section=1&auto_open=1&from=reservation_logs" 
                                                   class="btn btn-sm btn-danger check-view-cemetery-btn text-white" 
                                                   title="Highlight section and view reserved plot on cemetery map" 
                                                   style="border-radius: 6px; background: #7b1113; border: none;"
                                                   onclick="event.stopPropagation();">
                                                    <i class="fas fa-map-marked-alt me-1"></i>View Cemetery
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-5">
                                    <div class="d-flex flex-column align-items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="text-muted mb-3" viewBox="0 0 16 16">
                                            <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                                            <path d="M5.255 5.786a.237.237 0 0 0 .241.247h.825c.138 0 .248-.113.266-.25.09-.656.54-1.134 1.342-1.134.686 0 1.314.343 1.314 1.168 0 .635-.374.927-.965 1.371-.673.489-1.206 1.06-1.168 1.987l.003.217a.25.25 0 0 0 .25.246h.811a.25.25 0 0 0 .25-.25v-.105c0-.718.273-.927 1.01-1.486.609-.463 1.244-.977 1.244-2.056 0-1.511-1.276-2.241-2.673-2.241-1.267 0-2.655.59-2.75 2.286zm1.557 5.763c0 .533.425.927 1.01.927.609 0 1.028-.394 1.028-.927 0-.552-.42-.94-1.029-.94-.584 0-1.009.388-1.009.94z"/>
                                        </svg>
                                        <h5 class="text-muted mb-1">No Reservation Logs</h5>
                                        <p class="text-muted mb-0">No reservation activities have been recorded yet.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        
        <div id="serviceLogsCard" class="card-wrap w-100" style="max-width: 1400px; display:none;">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1" style="color: #7b1113; font-weight: 600; font-size: 1.5rem;">Service Logs</h2>
                    <p class="text-muted mb-0">Activity for cemetery service bookings</p>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="count-badge" id="serviceLogsCountBadge">Total: <?php echo (int)$serviceCount; ?></span>
                    <a href="dashboard.php" class="back-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                            <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
                        </svg>
                        Back to Dashboard
                    </a>
                </div>
            </div>

            
            <div class="mb-3">
                <input id="serviceFilter" type="search" class="form-control search-input" placeholder="Search by service type, details, or user...">
            </div>

            <!-- Service Status Filter Tabs -->
            <div class="log-status-filters mb-3">
                <button type="button" class="log-filter-btn service-log-filter-btn active" data-status="all" onclick="filterServiceLogs('all')">
                    <i class="fas fa-list"></i> All Services <span class="badge bg-secondary"><?php echo $srvCountAll; ?></span>
                </button>
                <button type="button" class="log-filter-btn service-log-filter-btn" data-status="pending" onclick="filterServiceLogs('pending')">
                    <i class="fas fa-clock text-warning"></i> Pending <span class="badge bg-warning text-dark"><?php echo $srvCountPending; ?></span>
                </button>
                <button type="button" class="log-filter-btn service-log-filter-btn" data-status="approved" onclick="filterServiceLogs('approved')">
                    <i class="fas fa-check-circle text-info"></i> Approved / In Progress <span class="badge bg-info text-dark"><?php echo $srvCountApproved; ?></span>
                </button>
                <button type="button" class="log-filter-btn service-log-filter-btn" data-status="completed" onclick="filterServiceLogs('completed')">
                    <i class="fas fa-check-double text-success"></i> Completed <span class="badge bg-success"><?php echo $srvCountCompleted; ?></span>
                </button>
                <?php if ($srvCountRejected > 0): ?>
                <button type="button" class="log-filter-btn service-log-filter-btn" data-status="rejected" onclick="filterServiceLogs('rejected')">
                    <i class="fas fa-times-circle text-danger"></i> Rejected <span class="badge bg-danger"><?php echo $srvCountRejected; ?></span>
                </button>
                <?php endif; ?>
            </div>

            
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-primary">
                        <tr>
                            <th style="width: 8%;">Booking ID</th>
                            <th style="width: 15%;">Service Type</th>
                            <th style="width: 12%;">Plot & Section</th>
                            <th style="width: 15%;">Client</th>
                            <th style="width: 9%;">Price</th>
                            <th style="width: 11%;">Payment Status</th>
                            <th style="width: 11%;">Service Status</th>
                            <th style="width: 11%;">Date & Time</th>
                            <th style="width: 12%;" class="text-center">Completion Proof</th>
                            <th style="width: 9%;" class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="serviceLogsTbody">
                        <?php if (!empty($serviceList)): ?>
                            <?php foreach ($serviceList as $srv): ?>
                                <?php
                                    $srvId = $srv['booking_id'];
                                    $srvStatus = strtolower($srv['status'] ?? 'pending');
                                    $srvServiceStatus = strtoupper($srv['service_status'] ?? (strtolower($srvStatus) === 'completed' ? 'COMPLETED' : (strtolower($srvStatus) === 'approved' ? 'APPROVED' : 'PENDING')));
                                    $srvTotalAmt = floatval($srv['total_amount'] ?? 200.00);
                                    $srvAmtPaid = floatval($srv['amount_paid'] ?? 0);
                                    if ($srvTotalAmt > 0 && $srvAmtPaid >= ($srvTotalAmt - 0.009)) {
                                        $srvPayStatus = 'PAID';
                                    } elseif ($srvAmtPaid > 0) {
                                        $srvPayStatus = 'PARTIAL';
                                    } else {
                                        $rawSrvStat = !empty($srv['payment_status']) ? strtoupper(trim((string)$srv['payment_status'])) : 'PENDING';
                                        $srvPayStatus = ($rawSrvStat === 'PAID') ? 'PENDING' : $rawSrvStat;
                                    }
                                    $srvSection = $srv['section'] ?? '—';
                                    $srvPlot = $srv['plot'] ?? '—';
                                    $plotFmt = format_plot_number($srvPlot, $srvSection);
                                    $srvType = $srv['service_type'] ?? 'Cemetery Service';
                                    $srvClient = $srv['customer_name'] ?? 'Guest';
                                    $srvContact = $srv['contact_number'] ?? '—';
                                    $srvLocation = $srv['booker_location'] ?? '—';
                                    $srvEmail = $srv['customer_email'] ?? '—';
                                    $srvRemarks = $srv['remarks'] ?? 'None';
                                    $srvPrefDate = $srv['preferred_date'] ?? '—';
                                    $srvPayType = $srv['payment_type'] ?? 'GCash';
                                    $srvRefNum = $srv['reference_number'] ?? 'N/A';
                                    $srvProofFile = $srv['payment_proof_filename'] ?? '';
                                    $srvCompletionProof = $srv['service_completion_proof'] ?? '';
                                    $srvCompletedAt = $srv['service_completed_at'] ?? '';
                                    $srvCompletedBy = $srv['service_completed_by'] ?? '';
                                    $srvPayDate = !empty($srv['payment_date']) ? date('F d, Y • h:i A', strtotime($srv['payment_date'])) : 'N/A';
                                    $srvTimestamp = !empty($srv['timestamp']) ? date('M d, Y h:i A', strtotime($srv['timestamp'])) : '—';
                                ?>
                                <tr class="booking-row service-row"
                                    tabindex="0"
                                    role="button"
                                    style="cursor: pointer;"
                                    title="Click to view details for Service Reservation"
                                    data-booking-id="<?php echo htmlspecialchars($srvId, ENT_QUOTES); ?>"
                                    data-section="<?php echo htmlspecialchars($srvSection, ENT_QUOTES); ?>"
                                    data-plot="<?php echo htmlspecialchars($plotFmt, ENT_QUOTES); ?>"
                                    data-service-type="<?php echo htmlspecialchars($srvType, ENT_QUOTES); ?>"
                                    data-preferred-date="<?php echo htmlspecialchars($srvPrefDate, ENT_QUOTES); ?>"
                                    data-contact-number="<?php echo htmlspecialchars($srvContact, ENT_QUOTES); ?>"
                                    data-booker-location="<?php echo htmlspecialchars($srvLocation, ENT_QUOTES); ?>"
                                    data-special-requests="<?php echo htmlspecialchars($srvRemarks, ENT_QUOTES); ?>"
                                    data-remarks="<?php echo htmlspecialchars($srvRemarks, ENT_QUOTES); ?>"
                                    data-customer-name="<?php echo htmlspecialchars($srvClient, ENT_QUOTES); ?>"
                                    data-deceased-name="<?php echo htmlspecialchars($srv['deceased_name'] ?? '—', ENT_QUOTES); ?>"
                                    data-customer-email="<?php echo htmlspecialchars($srvEmail, ENT_QUOTES); ?>"
                                    data-total-amount="<?php echo number_format($srvTotalAmt, 2); ?>"
                                    data-amount-paid="<?php echo number_format($srvAmtPaid, 2); ?>"
                                    data-balance="<?php echo number_format(max(0, $srvTotalAmt - $srvAmtPaid), 2); ?>"
                                    data-payment-method="<?php echo htmlspecialchars($srvPayType, ENT_QUOTES); ?>"
                                    data-reference-number="<?php echo htmlspecialchars($srvRefNum, ENT_QUOTES); ?>"
                                    data-payment-date="<?php echo htmlspecialchars($srvPayDate, ENT_QUOTES); ?>"
                                    data-payment-status="<?php echo htmlspecialchars($srvPayStatus, ENT_QUOTES); ?>"
                                    data-proof-filename="<?php echo htmlspecialchars($srvProofFile, ENT_QUOTES); ?>"
                                    data-service-status="<?php echo htmlspecialchars($srvServiceStatus, ENT_QUOTES); ?>"
                                    data-completion-proof="<?php echo htmlspecialchars($srvCompletionProof, ENT_QUOTES); ?>"
                                    data-completed-at="<?php echo htmlspecialchars($srvCompletedAt, ENT_QUOTES); ?>"
                                    data-completed-by="<?php echo htmlspecialchars($srvCompletedBy, ENT_QUOTES); ?>"
                                    data-gcash-name="<?php echo htmlspecialchars($gcashSettings['account_name'], ENT_QUOTES); ?>"
                                    data-gcash-number="<?php echo htmlspecialchars($gcashSettings['display_number'], ENT_QUOTES); ?>"
                                    data-service-filter-status="<?php echo htmlspecialchars(strtolower($srvServiceStatus), ENT_QUOTES); ?>"
                                    data-status="<?php echo htmlspecialchars($srvStatus, ENT_QUOTES); ?>">
                                    <td data-label="Booking ID">
                                        <span class="badge bg-primary">#<?php echo htmlspecialchars($srvId); ?></span>
                                    </td>
                                    <td data-label="Service Type" style="max-width: 150px;">
                                        <span class="badge bg-info text-white" style="white-space: normal; word-break: break-word; text-align: left; line-height: 1.4;"><?php echo htmlspecialchars($srvType); ?></span>
                                    </td>
                                    <td data-label="Plot & Section">
                                        <strong>Section <?php echo htmlspecialchars($srvSection); ?></strong>
                                        <?php if ($plotFmt !== '—'): ?>
                                             <br><small class="text-muted">Plot <?php echo htmlspecialchars($plotFmt); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Client">
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($srvClient); ?></div>
                                        <?php if ($srvContact !== '—'): ?>
                                             <small class="text-muted"><i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($srvContact); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Price">
                                        <span class="badge bg-warning text-dark"><?php echo formatDisplayCurrency($srvTotalAmt); ?></span>
                                    </td>
                                    <td data-label="Payment Status">
                                        <?php if ($srvPayStatus === 'PAID'): ?>
                                            <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>PAID</span>
                                        <?php elseif ($srvPayStatus === 'FOR VERIFICATION'): ?>
                                            <span class="badge bg-info text-white" style="white-space: normal; word-break: break-word; line-height: 1.4;"><i class="fas fa-hourglass-half me-1"></i>FOR VERIFICATION</span>
                                        <?php elseif ($srvPayStatus === 'PARTIAL'): ?>
                                            <span class="badge bg-info"><i class="fas fa-adjust me-1"></i>PARTIAL</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>UNPAID</span>
                                        <?php endif; ?>
                                        <?php if ($srvAmtPaid > 0 || $srvPayStatus === 'PAID' || $srvPayStatus === 'PARTIAL' || !empty($srvProofFile)): ?>
                                            <div class="mt-1">
                                                <a href="payment-success.php?booking_id=<?php echo (int)$srvId; ?>&from=service_logs" 
                                                   class="btn btn-sm btn-outline-secondary py-0 px-1" 
                                                   style="font-size: 0.72rem; border-radius: 4px;"
                                                   title="View Receipt"
                                                   onclick="event.stopPropagation();">
                                                    <i class="fas fa-receipt me-1"></i>Receipt
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Service Status">
                                        <?php 
                                        $sBadge = 'bg-warning text-dark';
                                        $sIcon = 'clock';
                                        if ($srvServiceStatus === 'APPROVED') { $sBadge = 'bg-info text-white'; $sIcon = 'check'; }
                                        elseif ($srvServiceStatus === 'IN PROGRESS') { $sBadge = 'bg-primary'; $sIcon = 'spinner fa-spin'; }
                                        elseif ($srvServiceStatus === 'COMPLETED') { $sBadge = 'bg-success'; $sIcon = 'check-double'; }
                                        elseif ($srvServiceStatus === 'REJECTED') { $sBadge = 'bg-danger'; $sIcon = 'times'; }
                                        ?>
                                        <span class="badge <?php echo $sBadge; ?>"><i class="fas fa-<?php echo $sIcon; ?> me-1"></i><?php echo htmlspecialchars($srvServiceStatus); ?></span>
                                    </td>
                                    <td data-label="Date & Time" class="text-muted small">
                                        <?php echo htmlspecialchars($srvTimestamp); ?>
                                    </td>
                                    <td data-label="Completion Proof" class="text-center">
                                        <?php if (!empty($srvCompletionProof)): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success" style="border-radius: 6px; font-size: 0.78rem; padding: 3px 8px;" onclick="event.stopPropagation(); adminViewServiceProof('<?php echo htmlspecialchars($srvCompletionProof, ENT_QUOTES); ?>', <?php echo (int)$srvId; ?>);">
                                                <i class="fas fa-image me-1"></i> View Proof
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small"><i class="fas fa-minus-circle me-1"></i>No photo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Action" class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-primary" style="border-radius: 6px; font-size: 0.78rem; padding: 4px 8px;">
                                            <i class="fas fa-eye me-1"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="serviceEmptyFilterRow" style="display: none;">
                                <td colspan="10" class="text-center text-muted py-5">
                                    <div class="d-flex flex-column align-items-center">
                                        <i class="fas fa-filter text-muted mb-2" style="font-size: 2rem;"></i>
                                        <h6 class="text-muted mb-1">No service records found for this filter</h6>
                                        <p class="text-muted small mb-0">Try selecting another status tab or clearing search.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-5">
                                    <div class="d-flex flex-column align-items-center">
                                        <h5 class="text-muted mb-1">No Service Logs</h5>
                                        <p class="text-muted mb-0">No cemetery service bookings have been recorded yet.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    
    <div id="bookingLogsCard" class="card-wrap w-100" style="max-width: 1400px; display:none;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1" style="color: #7b1113; font-weight: 600; font-size: 1.5rem;">Request Logs</h2>
                <p class="text-muted mb-0">Activity for request submissions and reserves</p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="count-badge" id="bookingLogsCountBadge">Total: <?php echo (int)$bookingCount; ?></span>
                <a href="dashboard.php" class="back-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                        <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
                    </svg>
                    Back to Dashboard
                </a>
            </div>
        </div>

        
        <div class="mb-3">
            <input id="bookingFilter" type="search" class="form-control search-input" placeholder="Search by client name, section, contact, or price...">
        </div>

        <!-- Booking Status Filter Tabs -->
        <div class="log-status-filters mb-3">
            <button type="button" class="log-filter-btn booking-log-filter-btn active" data-status="all" onclick="filterBookingLogs('all')">
                <i class="fas fa-list"></i> All Reservations <span class="badge bg-secondary"><?php echo $bkCounts['all']; ?></span>
            </button>
            <button type="button" class="log-filter-btn booking-log-filter-btn" data-status="pending" onclick="filterBookingLogs('pending')">
                <i class="fas fa-clock text-warning"></i> Pending <span class="badge bg-warning text-dark"><?php echo $bkCounts['pending']; ?></span>
            </button>
            <button type="button" class="log-filter-btn booking-log-filter-btn" data-status="approved" onclick="filterBookingLogs('approved')">
                <i class="fas fa-check-circle text-primary"></i> Approved <span class="badge bg-primary"><?php echo $bkCounts['approved']; ?></span>
            </button>
            <button type="button" class="log-filter-btn booking-log-filter-btn" data-status="completed" onclick="filterBookingLogs('completed')">
                <i class="fas fa-check-double text-success"></i> Completed <span class="badge bg-success"><?php echo $bkCounts['completed']; ?></span>
            </button>
            <?php if ($bkCounts['rejected'] > 0): ?>
            <button type="button" class="log-filter-btn booking-log-filter-btn" data-status="rejected" onclick="filterBookingLogs('rejected')">
                <i class="fas fa-times-circle text-danger"></i> Rejected <span class="badge bg-danger"><?php echo $bkCounts['rejected']; ?></span>
            </button>
            <?php endif; ?>
            <?php if ($bkCounts['cancelled'] > 0): ?>
            <button type="button" class="log-filter-btn booking-log-filter-btn" data-status="cancelled" onclick="filterBookingLogs('cancelled')">
                <i class="fas fa-ban text-secondary"></i> Cancelled <span class="badge bg-secondary"><?php echo $bkCounts['cancelled']; ?></span>
            </button>
            <?php endif; ?>
        </div>

        
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-primary">
                    <tr>
                        <th style="width: 15%;">Client Name</th>
                        <th style="width: 6%; text-align: center;">Section</th>
                        <th style="width: 10%;">Preferred Date</th>
                        <th style="width: 11%;">Contact Number</th>
                        <th style="width: 12%;">Client Location</th>
                        <th style="width: 11%;">Special Requests</th>
                        <th style="width: 9%;">Price</th>
                        <th style="width: 13%;">Payment</th>
                        <th style="width: 13%; text-align: center;">Status</th>
                    </tr>
                </thead>
                <tbody id="bookingLogsTbody">
                    <?php if ($bookingLogs && $bookingLogs->num_rows > 0): ?>
                        <?php while ($row = $bookingLogs->fetch_assoc()): ?>
                            <?php
                                $bkId = (int)($row['booking_id'] ?? $row['id']);
                                $logId = (int)($row['log_id'] ?? 0);
                                $status = strtolower(trim((string)($row['status'] ?? 'pending')));
                                if ($status === '0' || $status === '' || $status === '0.0') {
                                    $status = 'approved';
                                }
                                $safeRemarks = !empty($row['remarks']) ? $row['remarks'] : (!empty($row['special_requests']) ? $row['special_requests'] : 'None');
                                $rawCust = trim((string)($row['display_customer_name'] ?? $row['customer_name'] ?? ($row['full_name'] ?? '')));
                                if ($rawCust !== '') {
                                    $custName = $rawCust;
                                } elseif (!empty($row['customer_email']) || !empty($row['email'])) {
                                    $custName = trim((string)($row['customer_email'] ?: $row['email']));
                                } elseif (!empty($row['deceased_name']) && $row['deceased_name'] !== '—') {
                                    $custName = 'Family of ' . trim((string)$row['deceased_name']);
                                } else {
                                    $custName = 'Client';
                                }
                                $decName = !empty($row['deceased_name']) ? $row['deceased_name'] : '—';
                                $custEmail = !empty($row['display_customer_email']) ? $row['display_customer_email'] : (!empty($row['customer_email']) ? $row['customer_email'] : ($row['email'] ?? '—'));
                                $totalAmt = floatval($row['price'] ?? ($row['total_amount'] ?? ($row['plot_price'] ?? 0)));
                                $amtPaid = floatval($row['amount_paid'] ?? ($row['pr_amount_paid'] ?? 0));
                                $proofFile = !empty($row['payment_proof_filename']) ? $row['payment_proof_filename'] : ($row['pr_proof_file'] ?? '');
                                $refNum = !empty($row['reference_number']) ? $row['reference_number'] : (!empty($row['gcash_reference_number']) ? $row['gcash_reference_number'] : ($row['pr_receipt_number'] ?? 'N/A'));
                                $payDate = !empty($row['payment_proof_uploaded_at']) ? $row['payment_proof_uploaded_at'] : ($row['pr_payment_date'] ?? ($row['log_timestamp'] ?? $row['created_at']));
                                if ($totalAmt > 0 && $amtPaid >= ($totalAmt - 0.009)) {
                                    $computedPayStatus = 'PAID';
                                } elseif ($amtPaid > 0) {
                                    $computedPayStatus = 'PARTIAL';
                                } else {
                                    $rawBkStat = !empty($row['payment_status']) ? strtoupper(trim((string)$row['payment_status'])) : 'PENDING';
                                    $computedPayStatus = ($rawBkStat === 'PAID') ? 'PENDING' : $rawBkStat;
                                }
                                $payStatus = $computedPayStatus;
                                // Installment delinquency computed from the actual payment schedule.
                                $delinq = ['is_installment' => false, 'has_schedule' => false, 'consecutive_unpaid' => 0, 'next_due_date' => null, 'overdue_amount' => 0.00, 'last_payment_date' => null, 'months_until_cancel' => 3];
                                if (strtolower(trim((string)($row['payment_plan'] ?? ''))) === 'installment' && function_exists('get_booking_delinquency')) {
                                    try { $delinq = get_booking_delinquency($conn, $bkId); } catch (Throwable $dqEx) {}
                                }
                                $consecUnpaid = (int)($delinq['consecutive_unpaid'] ?? 0);
                                $dqNextDue = $delinq['next_due_date'] ?? null;
                                $dqOverdueAmt = number_format((float)($delinq['overdue_amount'] ?? 0), 2);
                                $dqLastPay = $delinq['last_payment_date'] ?? null;
                                $dqUntilCancel = (int)($delinq['months_until_cancel'] ?? 3);
                                $dqPayLabel = $payStatus;
                                if (!empty($delinq['is_installment']) && !empty($delinq['has_schedule']) && $consecUnpaid > 0 && !in_array($status, ['cancelled', 'rejected', 'completed'], true)) {
                                    $dqPayLabel = ($consecUnpaid >= 3 ? '3 MONTHS OVERDUE' : 'OVERDUE');
                                }
                            ?>
                            <tr class="booking-row"
                                tabindex="0"
                                role="button"
                                aria-label="View reservation details"
                                data-booking-id="<?php echo htmlspecialchars($bkId, ENT_QUOTES); ?>"
                                data-log-id="<?php echo htmlspecialchars($logId, ENT_QUOTES); ?>"
                                data-section="<?php echo htmlspecialchars($row['section'] ?? '—', ENT_QUOTES); ?>"
                                data-plot="<?php echo htmlspecialchars(!empty($row['multi_plots']) ? $row['multi_plots'] : format_plot_number($row['plot'] ?? ($row['grave_location'] ?? '—'), $row['section'] ?? ''), ENT_QUOTES); ?>"
                                data-service-type="<?php echo htmlspecialchars($row['service_type'] ?: ($row['purpose'] ?? 'Reserve Grave'), ENT_QUOTES); ?>"
                                data-preferred-date="<?php echo htmlspecialchars(!empty($row['preferred_date']) ? $row['preferred_date'] : (!empty($row['log_timestamp']) ? date('Y-m-d', strtotime($row['log_timestamp'])) : '—'), ENT_QUOTES); ?>"
                                data-contact-number="<?php echo htmlspecialchars($row['contact_number'] ?? '—', ENT_QUOTES); ?>"
                                data-booker-location="<?php echo htmlspecialchars($row['booker_location'] ?? '—', ENT_QUOTES); ?>"
                                data-special-requests="<?php echo htmlspecialchars($safeRemarks, ENT_QUOTES); ?>"
                                data-remarks="<?php echo htmlspecialchars($safeRemarks, ENT_QUOTES); ?>"
                                data-customer-name="<?php echo htmlspecialchars($custName, ENT_QUOTES); ?>"
                                data-deceased-name="<?php echo htmlspecialchars($decName, ENT_QUOTES); ?>"
                                data-customer-email="<?php echo htmlspecialchars($custEmail, ENT_QUOTES); ?>"
                                data-total-amount="<?php echo number_format($totalAmt, 2); ?>"
                                data-amount-paid="<?php echo number_format($amtPaid, 2); ?>"
                                data-balance="<?php echo number_format(max(0, $totalAmt - $amtPaid), 2); ?>"
                                data-payment-method="<?php echo htmlspecialchars($row['payment_type'] ?? 'GCash', ENT_QUOTES); ?>"
                                data-reference-number="<?php echo htmlspecialchars($refNum, ENT_QUOTES); ?>"
                                data-payment-date="<?php echo htmlspecialchars($payDate ? date('F d, Y • h:i A', strtotime($payDate)) : 'N/A', ENT_QUOTES); ?>"
                                data-payment-status="<?php echo htmlspecialchars($payStatus, ENT_QUOTES); ?>"
                                data-proof-filename="<?php echo htmlspecialchars($proofFile, ENT_QUOTES); ?>"
                                data-gcash-name="<?php echo htmlspecialchars($gcashSettings['account_name'], ENT_QUOTES); ?>"
                                data-gcash-number="<?php echo htmlspecialchars($gcashSettings['display_number'], ENT_QUOTES); ?>"
                                data-status="<?php echo htmlspecialchars($status, ENT_QUOTES); ?>"
                                data-booking-filter-status="<?php echo htmlspecialchars(strtolower($status), ENT_QUOTES); ?>"
                                data-payment-plan="<?php echo htmlspecialchars(strtolower(trim((string)($row['payment_plan'] ?? 'full'))), ENT_QUOTES); ?>"
                                data-consecutive-unpaid="<?php echo (int)$consecUnpaid; ?>"
                                data-next-due="<?php echo htmlspecialchars($dqNextDue ? date('M d, Y', strtotime($dqNextDue)) : '—', ENT_QUOTES); ?>"
                                data-overdue-amount="<?php echo htmlspecialchars($dqOverdueAmt, ENT_QUOTES); ?>"
                                data-last-payment="<?php echo htmlspecialchars($dqLastPay ? date('M d, Y', strtotime($dqLastPay)) : '—', ENT_QUOTES); ?>"
                                data-months-until-cancel="<?php echo (int)$dqUntilCancel; ?>"
                                data-cancel-reason="<?php echo htmlspecialchars($row['cancellation_reason'] ?? '', ENT_QUOTES); ?>">
                                <td data-label="Client Name">
                                    <div class="fw-bold text-dark text-truncate" style="max-width: 140px; font-size: 0.79rem;" title="<?php echo htmlspecialchars($custName); ?>">
                                        <i class="fas fa-user-circle text-secondary me-1"></i><?php echo htmlspecialchars($custName); ?>
                                    </div>
                                    <?php if ($logId > 0): ?>
                                        <div class="d-flex align-items-center gap-1 mt-0.5">
                                            <span class="text-muted" style="font-size: 0.70rem;">Log #<?php echo htmlspecialchars($logId); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Section" class="text-center"><span class="badge bg-info" style="font-size: 0.72rem; padding: 2px 6px;"><?php echo htmlspecialchars($row['section']); ?></span></td>
                                <td data-label="Preferred Date" style="white-space: nowrap; font-size: 0.74rem;"><?php echo htmlspecialchars(!empty($row['preferred_date']) ? $row['preferred_date'] : (!empty($row['log_timestamp']) ? date('Y-m-d', strtotime($row['log_timestamp'])) : '—')); ?></td>
                                <td data-label="Contact Number" style="white-space: nowrap; font-size: 0.74rem;"><?php echo htmlspecialchars($row['contact_number']); ?></td>
                                <td data-label="Client Location" class="text-truncate" style="max-width: 110px; font-size: 0.76rem;" title="<?php echo htmlspecialchars($row['booker_location'] ?? '—'); ?>"><?php echo htmlspecialchars($row['booker_location'] ?? '—'); ?></td>
                                <td data-label="Remarks / Requests" class="text-truncate" style="max-width: 95px; font-size: 0.76rem;" title="<?php echo htmlspecialchars($safeRemarks); ?>"><?php echo htmlspecialchars($safeRemarks); ?></td>
                                <td data-label="Price"><span class="badge bg-warning text-dark" style="font-size: 0.70rem;"><?php echo formatDisplayCurrency($totalAmt); ?></span></td>
                                <td data-label="Payment">
                                    <?php
                                    $payColClass = 'bg-success';
                                    if ($dqPayLabel === 'OVERDUE' || $dqPayLabel === '3 MONTHS OVERDUE') { $payColClass = 'bg-danger'; }
                                    elseif ($payStatus === 'PENDING' || $payStatus === 'UNPAID') { $payColClass = 'bg-warning text-dark'; }
                                    elseif ($payStatus === 'PARTIAL' || $payStatus === 'FOR VERIFICATION') { $payColClass = 'bg-info text-dark'; }
                                    ?>
                                    <span class="badge <?php echo $payColClass; ?>" style="font-size: 0.68rem; padding: 2px 5px;"><?php echo htmlspecialchars($dqPayLabel); ?></span>
                                    <?php if ($consecUnpaid > 0 && !in_array($status, ['cancelled', 'rejected', 'completed'], true)): ?>
                                        <div class="text-muted mt-1" style="font-size:0.67rem; line-height: 1.25; max-width: 125px;">
                                            <div>Unpaid &times;<?php echo (int)$consecUnpaid; ?></div>
                                            <?php if ($dqNextDue): ?><div class="text-nowrap">Next: <?php echo htmlspecialchars(date('M d, Y', strtotime($dqNextDue))); ?></div><?php endif; ?>
                                            <div class="text-nowrap text-danger">Cancel in <?php echo (int)$dqUntilCancel; ?> mo.</div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($amtPaid > 0 || $payStatus === 'PAID' || $payStatus === 'PARTIAL' || !empty($proofFile)): ?>
                                        <div class="mt-1">
                                            <a href="payment-success.php?booking_id=<?php echo htmlspecialchars($row['id']); ?>&from=booking_logs" 
                                               class="btn btn-sm btn-outline-secondary py-0 px-1" 
                                               style="font-size: 0.68rem; border-radius: 4px;"
                                               title="View Official Receipt"
                                               onclick="event.stopPropagation();">
                                                <i class="fas fa-receipt me-1"></i>Receipt
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status" class="text-center" style="white-space: nowrap;">
                                    <?php 
                                    $badgeClass = 'bg-warning text-dark';
                                    $icon = 'clock';
                                    if ($status === '0' || $status === 0 || $status === 'approved') {
                                        $badgeClass = 'bg-success';
                                        $icon = 'check-circle';
                                        $status = 'approved';
                                    } elseif ($status === 'pending') {
                                        $badgeClass = 'bg-warning text-dark';
                                        $icon = 'clock';
                                    } elseif ($status === 'rejected') {
                                        $badgeClass = 'bg-danger';
                                        $icon = 'times-circle';
                                    } elseif ($status === 'completed') {
                                        $badgeClass = 'bg-primary';
                                        $icon = 'trophy';
                                    } elseif ($status === 'cancelled') {
                                        $badgeClass = 'bg-secondary';
                                        $icon = 'ban';
                                    }
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?>" style="font-size: 0.68rem; padding: 3px 6px;">
                                        <i class="fas fa-<?php echo $icon; ?> me-1"></i>
                                        <?php echo ucfirst($status); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        <tr id="bookingEmptyFilterRow" style="display: none;">
                            <td colspan="9" class="text-center text-muted py-5">
                                <div class="d-flex flex-column align-items-center">
                                    <i class="fas fa-filter text-muted mb-2" style="font-size: 2rem;"></i>
                                    <h6 class="text-muted mb-1">No request records found for this filter</h6>
                                    <p class="text-muted small mb-0">Try selecting another status tab or clearing search.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-5">
                                <div class="d-flex flex-column align-items-center">
                                    <h5 class="text-muted mb-1">No Request Logs</h5>
                                    <p class="text-muted mb-0">No request submissions have been recorded yet.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

        
        <div class="modal fade" id="reservationDetailModal" tabindex="-1" aria-labelledby="reservationDetailModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content" style="border-radius: 1.25rem; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.18); overflow: hidden;">
                    <div class="modal-header" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%); color: #fff; border-bottom: none; padding: 1.2rem 1.5rem;">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fas fa-map-marked-alt text-warning"></i>
                            <h5 class="modal-title fw-bold text-white m-0" id="reservationDetailModalLabel" style="font-family: 'Inter', sans-serif;">
                                Reservation Details & Plot Overview
                            </h5>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="padding: 1.5rem; background: #fafafa; font-family: 'Inter', sans-serif;">
                        
                        <div class="d-flex justify-content-between align-items-center p-3 mb-3 rounded" style="background: #fbf2f2; border: 1px solid #f0d5d5;">
                            <div>
                                <span class="text-muted small text-uppercase fw-bold">Reserved Plot</span>
                                <div class="fs-4 fw-bold text-danger" id="rd_plot_title">Section —</div>
                            </div>
                            <div class="text-end">
                                <span id="rd_action_badge" class="badge px-3 py-2" style="background-color: #7b1113; font-size: 0.85rem; font-weight: 600; border-radius: 999px;">
                                    Reserved By
                                </span>
                                <div class="small text-muted mt-1 font-monospace" id="rd_timestamp">-</div>
                            </div>
                        </div>

                        <div class="row g-3">
                            
                            <div class="col-md-6">
                                <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: #fff;">
                                    <div class="card-body p-3">
                                        <h6 class="fw-bold mb-3 pb-2 border-bottom d-flex justify-content-between align-items-center" style="color: #7b1113; font-size: 0.9rem;">
                                            <span id="rd_owner_card_title"><i class="fas fa-user-shield me-1"></i> Plot Owner</span>
                                            <span id="rd_owner_status_badge" class="badge bg-secondary" style="font-size: 0.72rem;">Pending</span>
                                        </h6>
                                        <div class="mb-2">
                                            <div class="text-muted small">Owner / Full Name</div>
                                            <div class="fw-bold text-dark fs-6" id="rd_owner">-</div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="text-muted small">Contact Number</div>
                                            <div class="text-dark font-monospace fw-semibold" id="rd_phone">-</div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="text-muted small">Email Address</div>
                                            <div class="text-dark" id="rd_email">-</div>
                                        </div>
                                        <div>
                                            <div class="text-muted small">Address / Location</div>
                                            <div class="text-dark small" id="rd_address">-</div>
                                        </div>
                                        <div id="rd_owner_note" class="mt-2"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right: Reservation & Current Plot Info -->
                            <div class="col-md-6">
                                <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: #fff;">
                                    <div class="card-body p-3">
                                        <h6 class="fw-bold mb-3 pb-2 border-bottom" style="color: #7b1113; font-size: 0.9rem;">
                                            <i class="fas fa-file-invoice me-1"></i> Reservation & Plot Information
                                        </h6>
                                        <div class="mb-2">
                                            <div class="text-muted small">Linked Booking ID</div>
                                            <div class="fw-bold text-primary font-monospace" id="rd_booking">-</div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="text-muted small">Current Plot Status</div>
                                            <div class="fw-semibold" id="rd_plot_status">-</div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="text-muted small">Log Classification</div>
                                            <div id="rd_log_classification"><span class="badge bg-secondary">Current Record</span></div>
                                        </div>
                                        <div>
                                            <div class="text-muted small">Performed / Recorded By</div>
                                            <div class="text-dark fw-semibold" id="rd_performed_by" style="word-break: break-word; overflow-wrap: anywhere;">-</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Bottom: Summary of Changes / Logged Values -->
                            <div class="col-12">
                                <div class="card border-0 shadow-sm" style="border-radius: 12px; background: #fff;">
                                    <div class="card-body p-3">
                                        <h6 class="fw-bold mb-3 pb-2 border-bottom" style="color: #7b1113; font-size: 0.9rem;">
                                            <i class="fas fa-list-alt me-1"></i> Reservation Details Summary
                                        </h6>
                                        <div class="row g-2">
                                            <div class="col-md-6" id="rd_old_col" style="display:none;">
                                                <div class="p-2 rounded bg-light border">
                                                    <div class="fw-bold text-muted small mb-1">Previous / Old Values</div>
                                                    <div class="small" id="rd_old_details">—</div>
                                                </div>
                                            </div>
                                            <div class="col-12" id="rd_new_col">
                                                <div class="p-2 rounded border" style="background: #fafafa;">
                                                    <div class="fw-bold text-muted small mb-1">Current / Logged Values</div>
                                                    <div class="small" id="rd_new_details">—</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer justify-content-between" style="background: #ffffff; border-top: 1px solid #eee; padding: 1rem 1.5rem;">
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" id="rd_btn_approve" class="btn btn-success" style="border-radius: 8px; font-size: 0.88rem; display:none;">
                                <i class="fas fa-check-circle me-1"></i> Approve Reservation
                            </button>
                            <button type="button" id="rd_print_receipt" class="btn btn-outline-secondary" style="border-radius: 8px; font-size: 0.88rem; display:none;">
                                <i class="fas fa-print me-1"></i> View Receipt
                            </button>
                        </div>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px; padding: 8px 20px;">Close</button>
                    </div>
                </div>
            </div>
        </div>

        
        <div class="modal fade" id="bookingDetailModal" tabindex="-1" aria-labelledby="bookingDetailModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content" style="border-radius: 1.25rem; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.15); overflow: hidden;">
                    <div class="modal-header" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%); color: #fff; border-bottom: none; padding: 1.2rem 1.5rem;">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fas fa-bookmark text-warning"></i>
                            <h5 class="modal-title fw-bold text-white m-0" id="bookingDetailModalLabel" style="font-family: 'Inter', sans-serif;">
                                Reservation & Payment Details
                            </h5>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="padding: 1.5rem; background: #fafafa; font-family: 'Inter', sans-serif;">
                        
                        <div class="d-flex justify-content-between align-items-center p-3 mb-3 rounded" style="background: #f7f1ea; border: 1px solid #e5dbcf;">
                            <div>
                                <span class="text-muted small text-uppercase fw-bold">Reservation Record</span>
                                <div class="fs-5 fw-bold text-dark font-monospace" id="bd_top_cust">—</div>
                                <span id="bd_top_id" style="display:none;">#0</span>
                            </div>
                            <div class="text-end">
                                <span id="bd_status_pill" class="badge px-3 py-2" style="font-size: 0.82rem; font-weight: 600; border-radius: 999px;">
                                    Status
                                </span>
                                <span id="bd_payment_pill" class="badge px-3 py-2 ms-1" style="font-size: 0.82rem; font-weight: 600; border-radius: 999px;">
                                    Payment
                                </span>
                            </div>
                        </div>

                        <div class="row g-3">
                            
                            <div class="col-md-6">
                                <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: #fff;">
                                    <div class="card-body p-3">
                                        <h6 class="fw-bold mb-3 pb-2 border-bottom" style="color: #7b1113; font-size: 0.9rem;">
                                            <i class="fas fa-user me-1"></i> Client / Buyer Information
                                        </h6>
                                        <div class="mb-2">
                                            <div class="text-muted small">Client / Buyer Name</div>
                                            <div class="fw-bold text-dark" id="bd_customer_name">-</div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="text-muted small">Contact Number</div>
                                            <div class="text-dark font-monospace" id="bd_contact">-</div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="text-muted small">Client Location / Address</div>
                                            <div class="text-dark small" id="bd_location">-</div>
                                        </div>
                                        <div>
                                            <div class="text-muted small">Email Address</div>
                                            <div class="text-dark" id="bd_customer_email">-</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            
                            <div class="col-md-6">
                                <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: #fff;">
                                    <div class="card-body p-3">
                                        <h6 class="fw-bold mb-3 pb-2 border-bottom" style="color: #7b1113; font-size: 0.9rem;">
                                            <i class="fas fa-map-marker-alt me-1"></i> Plot, Deceased & Service Details
                                        </h6>
                                        <div class="mb-2">
                                            <div class="text-muted small">Deceased Name</div>
                                            <div class="fw-bold text-dark" id="bd_deceased_name">-</div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="text-muted small">Grave Section & Plot</div>
                                            <div class="fw-bold text-dark" id="bd_section_plot">-</div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="text-muted small">Service / Purpose</div>
                                            <div class="fw-bold text-dark" id="bd_service_type">-</div>
                                        </div>
                                        <div class="mb-2">
                                            <div class="text-muted small">Preferred Date</div>
                                            <div class="text-dark" id="bd_preferred_date">-</div>
                                        </div>
                                        <div>
                                            <div class="text-muted small">Remarks / Notes</div>
                                            <div class="text-dark small" id="bd_remarks">-</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            
                            <div class="col-12">
                                <div class="card border-0 shadow-sm" style="border-radius: 12px; background: #fff;">
                                    <div class="card-body p-3">
                                        <h6 class="fw-bold mb-3 pb-2 border-bottom d-flex justify-content-between align-items-center" style="color: #7b1113; font-size: 0.9rem;">
                                            <span><i class="fas fa-credit-card me-1"></i> Payment & GCash Information</span>
                                            <span class="badge" id="bd_payment_card_badge">PAID</span>
                                        </h6>

                                        <div class="row g-2 mb-3">
                                            <div class="col-sm-4">
                                                <div class="p-2 rounded bg-light border">
                                                    <div class="text-muted small">Total Price</div>
                                                    <div class="fs-6 fw-bold text-dark" id="bd_total_price">₱0.00</div>
                                                </div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="p-2 rounded bg-light border">
                                                    <div class="text-muted small">Amount Paid</div>
                                                    <div class="fs-6 fw-bold text-success" id="bd_amount_paid">₱0.00</div>
                                                </div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="p-2 rounded bg-light border">
                                                    <div class="text-muted small">Remaining Balance</div>
                                                    <div class="fs-6 fw-bold text-dark" id="bd_balance">₱0.00</div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row g-2">
                                            <div class="col-sm-6">
                                                <div class="text-muted small">Payment Method</div>
                                                <div class="fw-bold text-dark" id="bd_payment_method"><i class="fas fa-mobile-alt text-primary me-1"></i> GCash</div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-muted small">GCash Account Name</div>
                                                <div class="fw-bold text-dark" id="bd_gcash_name">-</div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-muted small">Configured GCash Number</div>
                                                <div class="fw-bold text-primary font-monospace" id="bd_gcash_number">-</div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-muted small">Reference / Transaction Number</div>
                                                <div class="fw-bold text-dark font-monospace bg-light px-2 py-1 rounded d-inline-block" id="bd_reference_number">-</div>
                                            </div>
                                            <div class="col-sm-12">
                                                <div class="text-muted small">Payment Date & Time</div>
                                                <div class="text-dark" id="bd_payment_date">-</div>
                                            </div>
                                        </div>

                                        <div id="bd_proof_container" class="mt-3"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12" id="bd_installment_wrap">
                                <div class="card border-0 shadow-sm" style="border-radius: 12px; background: #fff;">
                                    <div class="card-body p-3">
                                        <h6 class="fw-bold mb-3 pb-2 border-bottom" style="color: #7b1113; font-size: 0.9rem;">
                                            <span><i class="fas fa-calendar-check me-1"></i> Installment Information</span>
                                        </h6>
                                        <div class="row g-2">
                                            <div class="col-sm-4">
                                                <div class="text-muted small">Payment Plan</div>
                                                <div class="fw-bold text-dark" id="bd_payment_plan">-</div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="text-muted small">Next Due Date</div>
                                                <div class="fw-bold text-dark" id="bd_next_due">-</div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="text-muted small">Consecutive Unpaid Months</div>
                                                <div class="fw-bold text-dark" id="bd_consecutive">-</div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="text-muted small">Last Payment Date</div>
                                                <div class="text-dark" id="bd_last_payment">-</div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="text-muted small">Overdue Amount</div>
                                                <div class="fw-bold text-danger" id="bd_overdue_amount">-</div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="text-muted small">Cancellation Threshold</div>
                                                <div class="fw-bold text-dark">3 consecutive unpaid months</div>
                                            </div>
                                            <div class="col-sm-12" id="bd_cancel_reason_wrap" style="display:none;">
                                                <div class="text-muted small">Cancellation Reason</div>
                                                <div class="fw-bold text-danger" id="bd_cancel_reason">-</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="alert alert-warning py-2 px-3 mb-0 small d-flex align-items-start gap-2" style="border-radius: 10px;">
                                    <i class="fas fa-ban mt-1 flex-shrink-0"></i>
                                    <div><strong>No Refund Policy:</strong> All payments are non-refundable. Cancelled bookings keep their payment history for audit.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="background: #ffffff; border-top: 1px solid #eee; padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                        <button type="button" id="bd_print_receipt" class="btn btn-outline-secondary" style="border-radius: 8px; font-size: 0.88rem;">
                            <i class="fas fa-print me-1"></i> View Receipt
                        </button>
                        <div class="d-flex gap-2">
                            <button type="button" id="bd_accept" class="btn btn-success" style="border-radius: 8px; font-size: 0.88rem;"><i class="fas fa-check me-1"></i> Approve</button>
                            <button type="button" id="bd_reject" class="btn btn-danger" style="border-radius: 8px; font-size: 0.88rem;"><i class="fas fa-times me-1"></i> Reject</button>
                            <button type="button" id="bd_complete" class="btn btn-primary" style="border-radius: 8px; font-size: 0.88rem;"><i class="fas fa-check-double me-1"></i> Mark Complete</button>
                            <button type="button" id="bd_start" class="btn btn-info text-white" style="border-radius: 8px; font-size: 0.88rem; display:none;"><i class="fas fa-play me-1"></i> Start Service</button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px; font-size: 0.88rem;">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    
        <!-- Modal: Admin Service Completion Proof Upload -->
        <div class="modal fade" id="adminServiceCompletionModal" tabindex="-1" aria-labelledby="adminServiceCompletionModalLabel" aria-hidden="true" style="z-index: 1075;">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius: 1.25rem; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.25); overflow: hidden;">
                    <div class="modal-header" style="background: linear-gradient(135deg, #198754 0%, #157347 100%); color: #fff; padding: 1.2rem 1.5rem; border-bottom: none;">
                        <h5 class="modal-title fw-bold text-white m-0">
                            <i class="fas fa-clipboard-check text-warning me-2"></i> Complete Cemetery Service
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="adminServiceCompletionForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="complete_service">
                        <input type="hidden" name="booking_id" id="completion_booking_id" value="">
                        <div class="modal-body p-4">
                            <div class="alert alert-info py-2 px-3 small mb-3">
                                <i class="fas fa-info-circle me-1"></i> A <strong>real photo proof</strong> is mandatory to complete a cemetery service. This photo will be visible to the client on their dashboard.
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark small">Booking Details</label>
                                <div class="p-2 bg-light rounded border small text-muted" id="completion_booking_info">
                                    Booking #--
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="admin_service_proof_file" class="form-label fw-semibold text-dark small">Upload Completion Photo <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" id="admin_service_proof_file" name="service_proof" accept="image/jpeg,image/png,image/webp" required onchange="if(this.files && this.files[0] && this.files[0].size > 5*1024*1024){ alert('File size exceeds 5MB limit! Please select a smaller photo.'); this.value=''; }">
                                <div class="form-text small text-muted">Allowed formats: JPG, PNG, WebP (Max 5MB)</div>
                            </div>
                            <div id="admin_service_proof_preview_box" class="text-center mt-3" style="display:none;">
                                <div class="small fw-semibold text-muted mb-1">Photo Preview:</div>
                                <img id="admin_service_proof_preview" src="" alt="Proof Preview" style="max-width: 100%; max-height: 220px; border-radius: 8px; border: 1px solid #ddd; object-fit: contain;">
                            </div>
                        </div>
                        <div class="modal-footer" style="background: #ffffff; border-top: 1px solid #eee; padding: 1rem 1.5rem;">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                            <button type="submit" id="admin_submit_completion_btn" class="btn btn-success" style="border-radius: 8px; font-weight: 600;">
                                <i class="fas fa-check-double me-1"></i> Upload Photo & Complete Service
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal: Admin View Service Completion Proof -->
        <div class="modal fade" id="adminServiceProofModal" tabindex="-1" aria-labelledby="adminServiceProofModalLabel" aria-hidden="true" style="z-index: 1080;">
            <div class="modal-dialog modal-dialog-centered" style="max-width: min(560px, 94vw); margin: 1.5rem auto;">
                <div class="modal-content" style="border-radius: 1.25rem; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.25); overflow: hidden;">
                    <div class="modal-header" style="background: linear-gradient(135deg, #198754 0%, #157347 100%); color: #fff; padding: 1.2rem 1.5rem; border-bottom: none;">
                        <h5 class="modal-title fw-bold text-white m-0">
                            <i class="fas fa-clipboard-check text-warning me-2"></i> Service Completion Proof
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-center" style="padding: 24px; background: #fafafa;">
                        <img id="adminServiceProofImage" src="" alt="Service Completion Proof" style="max-width: 100%; max-height: 520px; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); object-fit: contain;">
                        <div class="mt-2 text-muted small font-monospace" id="adminServiceProofFilenameText"></div>
                    </div>
                    <div class="modal-footer" style="background: #ffffff; border-top: 1px solid #eee; padding: 1rem 1.5rem;">
                        <a id="adminDownloadServiceProofLink" href="" download class="btn btn-success" style="border-radius: 8px; padding: 8px 18px; font-weight: 600;">
                            <i class="fas fa-download me-1"></i> Download Proof Image
                        </a>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px; padding: 8px 18px;">Close</button>
                    </div>
                </div>
            </div>
        </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        // Deceased logs search & section/year/date filters
        var input = document.getElementById('tableFilter');
        var secSelect = document.getElementById('logsSectionFilter');
        var yearInput = document.getElementById('logsYearFilter');
        var dateFromInput = document.getElementById('logsDateFromFilter');
        var dateToInput = document.getElementById('logsDateToFilter');
        var activeChips = document.getElementById('logsActiveFilterChips');
        var tbody = document.getElementById('logsTbody');

        window.setLogsSecFilter = function(sec) {
            if (secSelect) {
                secSelect.value = sec;
                applyLogsFilter();
            }
        };

        window.clearLogsFilters = function() {
            if (secSelect) secSelect.value = '';
            if (yearInput) yearInput.value = '';
            if (dateFromInput) dateFromInput.value = '';
            if (dateToInput) dateToInput.value = '';
            applyLogsFilter();
        };

        window.applyLogsFilter = function() {
            var q = (input?.value || '').toLowerCase().trim();
            var sec = (secSelect?.value || '').toUpperCase().trim();
            var yr = (yearInput?.value || '').trim();
            var dFrom = (dateFromInput?.value || '').trim();
            var dTo = (dateToInput?.value || '').trim();

            var hasFilter = (sec !== '' || yr !== '' || dFrom !== '' || dTo !== '');

            var activeDot = document.getElementById('logsFilterActiveDot');
            if (activeDot) activeDot.style.display = hasFilter ? 'inline-block' : 'none';

            // Active filter chips
            if (activeChips) {
                if (hasFilter) {
                    activeChips.style.setProperty('display', 'flex', 'important');
                    var chipHtml = '<span class="text-muted small">Active:</span>';
                    if (yr) chipHtml += '<span class="badge bg-danger rounded-pill py-1 px-2">Year: ' + yr + '</span>';
                    if (dFrom || dTo) chipHtml += '<span class="badge bg-secondary rounded-pill py-1 px-2">' + (dFrom || 'Start') + ' &rarr; ' + (dTo || 'Now') + '</span>';
                    if (sec) chipHtml += '<span class="badge bg-primary rounded-pill py-1 px-2">Sec ' + sec + '</span>';
                    chipHtml += '<button type="button" class="btn btn-sm btn-link p-0 text-danger text-decoration-none small ms-1" onclick="clearLogsFilters()"><i class="fas fa-times"></i> Clear</button>';
                    activeChips.innerHTML = chipHtml;
                } else {
                    activeChips.style.setProperty('display', 'none', 'important');
                    activeChips.innerHTML = '';
                }
            }

            if (!tbody) return;
            Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function(tr){
                var cells = tr.querySelectorAll('td');
                if (cells.length < 10) return;
                var rowText = '';
                cells.forEach(function(cell) {
                    rowText += (cell.textContent || '').toLowerCase() + ' ';
                });

                var bdayCell = cells[4] ? (cells[4].textContent || '').trim() : '';
                var dateDiedCell = cells[7] ? (cells[7].textContent || '').trim() : '';
                var secCell = cells[9] ? (cells[9].textContent || '').toUpperCase().trim() : '';

                var matchesQ = (q === '' || rowText.indexOf(q) !== -1);
                var matchesSec = (sec === '' || secCell === sec || secCell.indexOf(sec) !== -1);

                var matchesYr = true;
                if (yr !== '') {
                    matchesYr = (dateDiedCell.indexOf(yr) !== -1 || bdayCell.indexOf(yr) !== -1 || rowText.indexOf(yr) !== -1);
                }

                var matchesDate = true;
                if (dFrom !== '' || dTo !== '') {
                    // Normalize dateDied
                    var rowDate = dateDiedCell;
                    if (rowDate && /^\d{4}-\d{2}-\d{2}/.test(rowDate)) {
                        if (dFrom !== '' && rowDate < dFrom) matchesDate = false;
                        if (dTo !== '' && rowDate > dTo) matchesDate = false;
                    } else if (dFrom !== '' || dTo !== '') {
                        // If row has no valid date, exclude
                        matchesDate = false;
                    }
                }

                tr.style.display = (matchesQ && matchesSec && matchesYr && matchesDate) ? '' : 'none';
            });
        };

        if (input) input.addEventListener('input', applyLogsFilter);
        if (secSelect) secSelect.addEventListener('change', applyLogsFilter);
        if (yearInput) yearInput.addEventListener('input', applyLogsFilter);
        if (dateFromInput) dateFromInput.addEventListener('change', applyLogsFilter);
        if (dateToInput) dateToInput.addEventListener('change', applyLogsFilter);
        
        // Tab switching functionality
        var btnDec = document.getElementById('btnDeceased');
        var btnRes = document.getElementById('btnReservation');
        var btnService = document.getElementById('btnService');
        var btnBooking = document.getElementById('btnBooking');
        var decCard = document.getElementById('deceasedLogsCard');
        var resCard = document.getElementById('reservationLogsCard');
        var serviceCard = document.getElementById('serviceLogsCard');
        var bookingCard = document.getElementById('bookingLogsCard');
        
        if (btnDec && btnRes && btnService && btnBooking && decCard && resCard && serviceCard && bookingCard) {
            function persistActiveLogsTab(tabKey, skipHistoryUpdate) {
                try {
                    sessionStorage.setItem('active_logs_tab', tabKey);
                    if (!skipHistoryUpdate && window.history && window.history.replaceState) {
                        var curUrl = new URL(window.location.href);
                        curUrl.searchParams.set('tab', tabKey);
                        window.history.replaceState(null, '', curUrl.toString());
                    }
                } catch(e) {}
            }

            function showDeceased(skipHistory){
                decCard.style.display = '';
                resCard.style.display = 'none';
                serviceCard.style.display = 'none';
                bookingCard.style.display = 'none';
                btnDec.classList.add('btn-primary');
                btnDec.classList.remove('btn-outline-primary');
                btnRes.classList.add('btn-outline-primary');
                btnRes.classList.remove('btn-primary');
                btnService.classList.add('btn-outline-primary');
                btnService.classList.remove('btn-primary');
                btnBooking.classList.add('btn-outline-primary');
                btnBooking.classList.remove('btn-primary');
                persistActiveLogsTab('deceased', Boolean(skipHistory));
                
                if (window.serviceRefreshInterval) {
                    clearInterval(window.serviceRefreshInterval);
                }
            }
            
            function showReservation(skipHistory){
                decCard.style.display = 'none';
                resCard.style.display = '';
                serviceCard.style.display = 'none';
                bookingCard.style.display = 'none';
                btnRes.classList.add('btn-primary');
                btnRes.classList.remove('btn-outline-primary');
                btnDec.classList.add('btn-outline-primary');
                btnDec.classList.remove('btn-primary');
                btnService.classList.add('btn-outline-primary');
                btnService.classList.remove('btn-primary');
                btnBooking.classList.add('btn-outline-primary');
                btnBooking.classList.remove('btn-primary');
                persistActiveLogsTab('reservation', Boolean(skipHistory));
                
                if (window.serviceRefreshInterval) {
                    clearInterval(window.serviceRefreshInterval);
                }
            }
            
            function showService(skipHistory){
                decCard.style.display = 'none';
                resCard.style.display = 'none';
                serviceCard.style.display = '';
                bookingCard.style.display = 'none';
                btnService.classList.add('btn-primary');
                btnService.classList.remove('btn-outline-primary');
                btnDec.classList.add('btn-outline-primary');
                btnDec.classList.remove('btn-primary');
                btnRes.classList.add('btn-outline-primary');
                btnRes.classList.remove('btn-primary');
                btnBooking.classList.add('btn-outline-primary');
                btnBooking.classList.remove('btn-primary');
                persistActiveLogsTab('service', Boolean(skipHistory));
                
                if (window.serviceRefreshInterval) {
                    clearInterval(window.serviceRefreshInterval);
                }
                window.serviceRefreshInterval = setInterval(function(){
                    if (document.querySelector('.modal.show')) return;
                    location.reload();
                }, 30000);
            }
            
            function showBooking(skipHistory){
                decCard.style.display = 'none';
                resCard.style.display = 'none';
                serviceCard.style.display = 'none';
                bookingCard.style.display = '';
                btnBooking.classList.add('btn-primary');
                btnBooking.classList.remove('btn-outline-primary');
                btnDec.classList.add('btn-outline-primary');
                btnDec.classList.remove('btn-primary');
                btnRes.classList.add('btn-outline-primary');
                btnRes.classList.remove('btn-primary');
                btnService.classList.add('btn-outline-primary');
                btnService.classList.remove('btn-primary');
                persistActiveLogsTab('booking', Boolean(skipHistory));
                
                if (window.serviceRefreshInterval) {
                    clearInterval(window.serviceRefreshInterval);
                }
            }
            
            btnDec.addEventListener('click', function() { showDeceased(); });
            btnRes.addEventListener('click', function() { showReservation(); });
            btnService.addEventListener('click', function() { showService(); });
            btnBooking.addEventListener('click', function() { showBooking(); });

            // Auto-activate tab from URL parameter (?tab=service), hash (#service), or sessionStorage
            try {
                var urlParams = new URLSearchParams(window.location.search);
                var storedTab = '';
                try { storedTab = sessionStorage.getItem('active_logs_tab') || ''; } catch(e) {}
                var activeTab = (urlParams.get('tab') || window.location.hash.replace('#', '') || storedTab || '').toLowerCase().trim();
                
                if (activeTab === 'service' || activeTab === 'services') {
                    showService(true);
                } else if (activeTab === 'booking' || activeTab === 'bookings') {
                    showBooking(true);
                } else if (activeTab === 'reservation' || activeTab === 'reservations') {
                    showReservation(true);
                } else {
                    showDeceased(true);
                }

                var autoBookingId = urlParams.get('booking_id') || urlParams.get('id');
                if (autoBookingId) {
                    showBooking();
                    setTimeout(function() {
                        var targetRow = document.querySelector('[data-booking-id="' + autoBookingId + '"]');
                        if (targetRow) {
                            try { targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
                            openBookingDetail(targetRow);
                        }
                    }, 400);
                }

                window.addEventListener('popstate', function() {
                    var curParams = new URLSearchParams(window.location.search);
                    var curStored = '';
                    try { curStored = sessionStorage.getItem('active_logs_tab') || ''; } catch(e) {}
                    var curTab = (curParams.get('tab') || window.location.hash.replace('#', '') || curStored || '').toLowerCase().trim();
                    if (curTab === 'service' || curTab === 'services') showService(true);
                    else if (curTab === 'booking' || curTab === 'bookings') showBooking(true);
                    else if (curTab === 'reservation' || curTab === 'reservations') showReservation(true);
                    else showDeceased(true);
                });
            } catch (e) {}
        }
        
        // Reservation logs search
        var resInput = document.getElementById('reservationFilter');
        var resTbody = document.getElementById('reservationTbody');
        if (resInput && resTbody) {
            resInput.addEventListener('input', function(){
                var q = (resInput.value || '').toLowerCase().trim();
                Array.prototype.forEach.call(resTbody.querySelectorAll('tr'), function(tr){
                    if (q === '') { tr.style.display = ''; return; }
                    var cells = tr.querySelectorAll('td');
                    var rowText = '';
                    cells.forEach(function(cell){ rowText += (cell.textContent || '').toLowerCase() + ' '; });
                    tr.style.display = rowText.indexOf(q) !== -1 ? '' : 'none';
                });
            });
        }
        
        // Service logs filtering & search
        var activeServiceStatus = 'all';

        function filterServiceLogs(status) {
            activeServiceStatus = (status || 'all').toLowerCase();
            document.querySelectorAll('.service-log-filter-btn').forEach(function(btn) {
                btn.classList.toggle('active', btn.getAttribute('data-status') === activeServiceStatus);
            });
            applyServiceFilters();
        }
        window.filterServiceLogs = filterServiceLogs;

        function applyServiceFilters() {
            var serviceInput = document.getElementById('serviceFilter');
            var q = serviceInput ? (serviceInput.value || '').toLowerCase().trim() : '';
            var tbody = document.getElementById('serviceLogsTbody');
            if (!tbody) return;
            var rows = tbody.querySelectorAll('tr.service-row');
            var visibleCount = 0;

            rows.forEach(function(row) {
                var rowStatus = (row.getAttribute('data-service-filter-status') || '').toLowerCase().trim();
                var statusMatch = false;
                if (activeServiceStatus === 'all') {
                    statusMatch = true;
                } else if (activeServiceStatus === 'approved') {
                    statusMatch = (rowStatus === 'approved' || rowStatus === 'in progress');
                } else {
                    statusMatch = (rowStatus === activeServiceStatus);
                }

                var textMatch = true;
                if (q !== '') {
                    var cells = row.querySelectorAll('td');
                    var rowText = '';
                    cells.forEach(function(cell) { rowText += (cell.textContent || '').toLowerCase() + ' '; });
                    textMatch = (rowText.indexOf(q) !== -1);
                }

                var show = statusMatch && textMatch;
                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            var countBadge = document.getElementById('serviceLogsCountBadge');
            if (countBadge) {
                countBadge.textContent = 'Showing: ' + visibleCount + (visibleCount === 1 ? ' entry' : ' entries');
            }

            var emptyRow = document.getElementById('serviceEmptyFilterRow');
            if (emptyRow) {
                emptyRow.style.display = (visibleCount === 0) ? '' : 'none';
            }
        }

        var serviceInput = document.getElementById('serviceFilter');
        if (serviceInput) {
            serviceInput.addEventListener('input', applyServiceFilters);
        }
        
        // Booking logs filtering & search
        var activeBookingStatus = 'all';

        function filterBookingLogs(status) {
            activeBookingStatus = (status || 'all').toLowerCase();
            document.querySelectorAll('.booking-log-filter-btn').forEach(function(btn) {
                btn.classList.toggle('active', btn.getAttribute('data-status') === activeBookingStatus);
            });
            applyBookingFilters();
        }
        window.filterBookingLogs = filterBookingLogs;

        function applyBookingFilters() {
            var bookingInput = document.getElementById('bookingFilter');
            var q = bookingInput ? (bookingInput.value || '').toLowerCase().trim() : '';
            var tbody = document.getElementById('bookingLogsTbody');
            if (!tbody) return;
            var rows = tbody.querySelectorAll('tr.booking-row:not(#bookingEmptyFilterRow)');
            var visibleCount = 0;

            rows.forEach(function(row) {
                var rowStatus = (row.getAttribute('data-booking-filter-status') || row.getAttribute('data-status') || '').toLowerCase().trim();
                var statusMatch = (activeBookingStatus === 'all' || rowStatus === activeBookingStatus);

                var textMatch = true;
                if (q !== '') {
                    var cells = row.querySelectorAll('td');
                    var rowText = '';
                    cells.forEach(function(cell) { rowText += (cell.textContent || '').toLowerCase() + ' '; });
                    textMatch = (rowText.indexOf(q) !== -1);
                }

                var show = statusMatch && textMatch;
                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            var countBadge = document.getElementById('bookingLogsCountBadge');
            if (countBadge) {
                countBadge.textContent = 'Showing: ' + visibleCount + (visibleCount === 1 ? ' entry' : ' entries');
            }

            var emptyRow = document.getElementById('bookingEmptyFilterRow');
            if (emptyRow) {
                emptyRow.style.display = (visibleCount === 0) ? '' : 'none';
            }
        }

        var bookingInput = document.getElementById('bookingFilter');
        if (bookingInput) {
            bookingInput.addEventListener('input', applyBookingFilters);
        }

        // Booking details UI: restore modal handlers and actions
        var bookingModalEl = document.getElementById('bookingDetailModal');
        var bookingModal = bookingModalEl ? new bootstrap.Modal(bookingModalEl) : null;

        function openBookingDetail(row){
            if (!row) return;
            var isService = row.classList.contains('service-row') || (row.getAttribute('data-service-type') && row.getAttribute('data-service-type') !== 'Reserve Grave');
            var srvStatus = (row.getAttribute('data-service-status') || '').toUpperCase();
            var completionProof = row.getAttribute('data-completion-proof') || '';
            var id = row.getAttribute('data-booking-id') || '';
            var status = (row.getAttribute('data-status') || 'pending').toLowerCase();
            var totalAmtNum = parseFloat((row.getAttribute('data-total-amount') || '0').replace(/[^0-9.]/g, '')) || 0;
            var amtPaidNum = parseFloat((row.getAttribute('data-amount-paid') || '0').replace(/[^0-9.]/g, '')) || 0;
            var rawPayStatus = (row.getAttribute('data-payment-status') || 'PENDING').toUpperCase();
            var isFullyPaid = (totalAmtNum > 0 && amtPaidNum >= (totalAmtNum - 0.009));
            var isPartialPaid = (amtPaidNum > 0 && !isFullyPaid);
            var hasPayment = (amtPaidNum > 0);

            var payBadgeText = 'PENDING';
            var payStyle = 'background: rgba(245, 158, 11, 0.15); color: #92400e;';

            if (isFullyPaid) {
                payBadgeText = 'PAID';
                payStyle = 'background: rgba(16, 185, 129, 0.15); color: #065f46;';
            } else if (isPartialPaid) {
                payBadgeText = 'PARTIAL (Downpayment)';
                payStyle = 'background: rgba(13, 110, 253, 0.15); color: #0d6efd;';
            } else if (rawPayStatus === 'OVERDUE' || rawPayStatus === '3 MONTHS OVERDUE') {
                payBadgeText = rawPayStatus;
                payStyle = 'background: rgba(220, 53, 69, 0.15); color: #dc3545;';
            } else if (rawPayStatus === 'UNPAID') {
                payBadgeText = 'UNPAID';
                payStyle = 'background: rgba(245, 158, 11, 0.15); color: #92400e;';
            }

            var topIdEl = document.getElementById('bd_top_id');
            if (topIdEl) topIdEl.textContent = '#' + id;
            var custNameVal = row.getAttribute('data-customer-name') || '-';
            var topCustEl = document.getElementById('bd_top_cust');
            if (topCustEl) topCustEl.textContent = custNameVal;
            document.getElementById('bd_customer_name').textContent = custNameVal;
            document.getElementById('bd_customer_email').textContent = row.getAttribute('data-customer-email') || '-';
            document.getElementById('bd_contact').textContent = row.getAttribute('data-contact-number') || '-';
            document.getElementById('bd_location').textContent = row.getAttribute('data-booker-location') || '-';
            
            document.getElementById('bd_deceased_name').textContent = row.getAttribute('data-deceased-name') || '—';
            document.getElementById('bd_service_type').textContent = row.getAttribute('data-service-type') || 'Reserve Grave';
            document.getElementById('bd_section_plot').textContent = 'Section ' + (row.getAttribute('data-section') || '—') + ' • Plot ' + (row.getAttribute('data-plot') || '—');
            document.getElementById('bd_preferred_date').textContent = row.getAttribute('data-preferred-date') || '-';
            document.getElementById('bd_remarks').textContent = row.getAttribute('data-remarks') || row.getAttribute('data-special-requests') || 'None';

            document.getElementById('bd_total_price').textContent = '₱' + (row.getAttribute('data-total-amount') || '0.00');
            document.getElementById('bd_amount_paid').textContent = '₱' + (row.getAttribute('data-amount-paid') || '0.00');
            document.getElementById('bd_balance').textContent = '₱' + (row.getAttribute('data-balance') || '0.00');
            document.getElementById('bd_payment_method').innerHTML = '<i class="fas fa-mobile-alt text-primary me-1"></i> ' + (row.getAttribute('data-payment-method') || 'GCash');
            document.getElementById('bd_gcash_name').textContent = row.getAttribute('data-gcash-name') || 'Garden of Remembrance';
            document.getElementById('bd_gcash_number').textContent = row.getAttribute('data-gcash-number') || '0917 823 4567';
            document.getElementById('bd_reference_number').textContent = row.getAttribute('data-reference-number') || 'N/A';
            document.getElementById('bd_payment_date').textContent = row.getAttribute('data-payment-date') || 'N/A';

            var statusStyle = status === 'approved' ? 'background: rgba(16, 185, 129, 0.15); color: #065f46;' : (status === 'completed' ? 'background: rgba(59, 130, 246, 0.15); color: #1e40af;' : (status === 'rejected' ? 'background: rgba(239, 68, 68, 0.15); color: #991b1b;' : (status === 'cancelled' ? 'background: rgba(100, 116, 139, 0.18); color: #334155;' : 'background: rgba(245, 158, 11, 0.15); color: #92400e;')));
            
            var statusPill = document.getElementById('bd_status_pill');
            statusPill.style = statusStyle + ' font-size: 0.82rem; font-weight: 600; border-radius: 999px;';
            statusPill.textContent = 'Reservation: ' + status.toUpperCase();

            var startBtn = document.getElementById('bd_start');
            if (isService) {
                if (srvStatus) {
                    statusPill.textContent = 'Service: ' + srvStatus;
                    if (srvStatus === 'APPROVED') {
                        statusPill.style = 'background: rgba(13, 202, 240, 0.18); color: #055160; font-size: 0.82rem; font-weight: 600; border-radius: 999px;';
                    } else if (srvStatus === 'IN PROGRESS') {
                        statusPill.style = 'background: rgba(13, 110, 253, 0.18); color: #084298; font-size: 0.82rem; font-weight: 600; border-radius: 999px;';
                    } else if (srvStatus === 'COMPLETED') {
                        statusPill.style = 'background: rgba(25, 135, 84, 0.18); color: #0f5132; font-size: 0.82rem; font-weight: 600; border-radius: 999px;';
                    } else if (srvStatus === 'REJECTED') {
                        statusPill.style = 'background: rgba(220, 53, 69, 0.18); color: #842029; font-size: 0.82rem; font-weight: 600; border-radius: 999px;';
                    }
                }
                if (startBtn) startBtn.style.display = (srvStatus === 'APPROVED') ? '' : 'none';
            } else {
                if (startBtn) startBtn.style.display = 'none';
            }

            var payPill = document.getElementById('bd_payment_pill');
            payPill.style = payStyle + ' font-size: 0.82rem; font-weight: 600; border-radius: 999px;';
            payPill.textContent = 'Payment: ' + payBadgeText;

            // Installment / delinquency details
            var payPlan = (row.getAttribute('data-payment-plan') || 'full').toLowerCase();
            var instWrap = document.getElementById('bd_installment_wrap');
            if (instWrap) {
                instWrap.style.display = (!isService && payPlan === 'installment') ? '' : 'none';
            }
            var consec = row.getAttribute('data-consecutive-unpaid') || '0';
            var setText = function(id, v) { var e = document.getElementById(id); if (e) e.textContent = v || '-'; };
            setText('bd_payment_plan', payPlan === 'installment' ? 'Installment' : 'Full Payment');
            setText('bd_next_due', row.getAttribute('data-next-due'));
            setText('bd_consecutive', (payPlan === 'installment' ? consec : '0') + (payPlan === 'installment' && parseInt(consec, 10) > 0 ? ' (OVERDUE)' : ''));
            setText('bd_last_payment', row.getAttribute('data-last-payment'));
            var odAmt = row.getAttribute('data-overdue-amount');
            setText('bd_overdue_amount', odAmt && odAmt !== '0.00' ? ('₱' + odAmt) : '₱0.00');
            var cancelReason = row.getAttribute('data-cancel-reason') || '';
            var cancelWrap = document.getElementById('bd_cancel_reason_wrap');
            if (cancelWrap) cancelWrap.style.display = (status === 'cancelled' && cancelReason) ? '' : 'none';
            setText('bd_cancel_reason', cancelReason || '-');

            var cardBadge = document.getElementById('bd_payment_card_badge');
            cardBadge.style = payStyle;
            cardBadge.textContent = payBadgeText;

            // Proof screenshot container
            var proofFilename = row.getAttribute('data-proof-filename');
            var proofContainer = document.getElementById('bd_proof_container');
            if (proofFilename) {
                var proofUrl = 'view_payment_proof.php?booking_id=' + id + '&file=' + encodeURIComponent(proofFilename);
                proofContainer.innerHTML = `
                    <div class="p-3 bg-white rounded border d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-3">
                            <i class="fas fa-image text-primary" style="font-size: 1.5rem;"></i>
                            <div>
                                <div class="fw-bold text-dark" style="font-size: 0.88rem;">Payment Screenshot Proof</div>
                                <div class="text-muted small font-monospace">${escapeHtml(proofFilename)}</div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary" onclick="adminViewPaymentProof(${id}, '${escapeHtml(proofFilename)}')" style="border-radius: 8px; background: #7b1113; border: none;">
                            <i class="fas fa-eye me-1"></i> View Proof
                        </button>
                    </div>
                `;
            } else {
                // No proof uploaded: show nothing (no fake placeholder).
                proofContainer.innerHTML = '';
            }

            // Print / View receipt button (visible as long as payment is recorded or proof is attached)
            var proofFilename = row.getAttribute('data-proof-filename');
            var hasProof = Boolean(proofFilename && String(proofFilename).trim() !== '' && String(proofFilename).trim().toLowerCase() !== 'null' && String(proofFilename).trim() !== '-');
            var isBayadNa = (amtPaidNum > 0) || (rawPayStatus === 'PAID') || (rawPayStatus === 'PARTIAL') || (status === 'approved') || (status === 'confirmed') || (status === 'completed');
            var canViewReceipt = isBayadNa || hasProof;

            var printBtn = document.getElementById('bd_print_receipt');
            if (printBtn) {
                if (canViewReceipt) {
                    printBtn.style.display = '';
                    printBtn.onclick = function() {
                        window.location.href = 'payment-success.php?booking_id=' + id + '&from=booking_logs';
                    };
                } else {
                    printBtn.style.display = 'none';
                    printBtn.onclick = null;
                }
            }


            // Show appropriate action buttons
            var acceptBtn = document.getElementById('bd_accept');
            var rejectBtn = document.getElementById('bd_reject');
            var completeBtn = document.getElementById('bd_complete');
            if (acceptBtn) acceptBtn.style.display = (status === 'pending') ? '' : 'none';
            if (rejectBtn) rejectBtn.style.display = (status === 'pending') ? '' : 'none';
            if (completeBtn) completeBtn.style.display = (status === 'approved') ? '' : 'none';

            // store currently opened booking id
            bookingModalEl.setAttribute('data-current-booking', id);
            if (bookingModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(bookingModalEl).show();
            } else if (bookingModal) {
                bookingModal.show();
            }
        }

        // Attach click and keyboard handlers to booking rows and service rows (including dynamic delegation)
        document.addEventListener('click', function(e){
            var row = e.target.closest('.booking-row, .service-row');
            if (row && !e.target.closest('button[onclick*="adminViewServiceProof"], a.check-view-cemetery-btn')) {
                openBookingDetail(row);
            }
        });
        document.addEventListener('keypress', function(e){
            if (e.key === 'Enter') {
                var row = e.target.closest('.booking-row, .service-row');
                if (row) openBookingDetail(row);
            }
        });

        // Reservation details modal & click handlers
        var resModalEl = document.getElementById('reservationDetailModal');
        var resModal = resModalEl ? (typeof bootstrap !== 'undefined' && bootstrap.Modal ? bootstrap.Modal.getOrCreateInstance(resModalEl) : new bootstrap.Modal(resModalEl)) : null;

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function openReservationDetail(row) {
            if (!row) return;
            var plot = row.getAttribute('data-plot') || '—';
            var action = row.getAttribute('data-action') || 'Reserved By';
            var isApproved = (row.getAttribute('data-is-approved') === '1');
            var owner = row.getAttribute('data-owner') || '';
            var phone = row.getAttribute('data-phone') || '';
            var email = row.getAttribute('data-email') || '';
            var address = row.getAttribute('data-address') || '';
            var booking = row.getAttribute('data-booking') || 'N/A';
            var rawBookingId = row.getAttribute('data-raw-booking-id') || '';
            var applicantName = row.getAttribute('data-applicant-name') || '';
            var applicantPhone = row.getAttribute('data-applicant-phone') || '';
            var applicantEmail = row.getAttribute('data-applicant-email') || '';
            var performedBy = row.getAttribute('data-performed-by') || 'Guest';
            var timestamp = row.getAttribute('data-timestamp') || '—';
            var prevHtml = row.getAttribute('data-prev-html') || '<span class="text-muted">—</span>';
            var currHtml = row.getAttribute('data-curr-html') || '<span class="text-muted">—</span>';

            var elPlot = document.getElementById('rd_plot_title');
            if (elPlot) elPlot.textContent = plot;

            var elAction = document.getElementById('rd_action_badge');
            if (elAction) elAction.textContent = action;

            var elTime = document.getElementById('rd_timestamp');
            if (elTime) elTime.textContent = timestamp;

            var elOwnerTitle = document.getElementById('rd_owner_card_title');
            var elOwnerBadge = document.getElementById('rd_owner_status_badge');
            var elOwnerNote = document.getElementById('rd_owner_note');
            var elBtnApprove = document.getElementById('rd_btn_approve');

            var elOwner = document.getElementById('rd_owner');
            var elPhone = document.getElementById('rd_phone');
            var elEmail = document.getElementById('rd_email');
            var elAddr = document.getElementById('rd_address');

            if (isApproved && (owner || applicantName)) {
                var dispOwner = owner || applicantName;
                var dispPhone = phone || applicantPhone;
                var dispEmail = email || applicantEmail;

                if (elOwnerTitle) elOwnerTitle.innerHTML = '<i class="fas fa-user-check text-success me-1"></i> Registered Plot Owner';
                if (elOwnerBadge) {
                    elOwnerBadge.className = 'badge bg-success';
                    elOwnerBadge.innerHTML = '<i class="fas fa-check-circle me-1"></i>Approved Plot Owner';
                }
                if (elOwner) elOwner.innerHTML = '<span class="text-dark fw-bold">' + escapeHtml(dispOwner) + '</span>';
                if (elPhone) elPhone.innerHTML = dispPhone ? '<span class="font-monospace fw-semibold text-dark">' + escapeHtml(dispPhone) + '</span>' : '<span class="text-muted">—</span>';
                if (elEmail) elEmail.innerHTML = dispEmail ? '<span class="text-dark">' + escapeHtml(dispEmail) + '</span>' : '<span class="text-muted">—</span>';
                if (elAddr) elAddr.innerHTML = address ? '<span class="text-dark small">' + escapeHtml(address) + '</span>' : '<span class="text-muted">—</span>';
                if (elOwnerNote) {
                    elOwnerNote.innerHTML = '<div class="alert alert-success-subtle text-success small p-2 mb-0 border border-success-subtle" style="border-radius: 8px;"><i class="fas fa-check-double me-1"></i> Approved by administrator. Guest applicant details are officially recorded as the Plot Owner.</div>';
                }
                if (elBtnApprove) elBtnApprove.style.display = 'none';
            } else {
                if (elOwnerTitle) elOwnerTitle.innerHTML = '<i class="fas fa-user-clock text-warning me-1"></i> Plot Owner';
                if (elOwnerBadge) {
                    elOwnerBadge.className = 'badge bg-warning text-dark';
                    elOwnerBadge.innerHTML = '<i class="fas fa-clock me-1"></i>Pending Approval';
                }
                if (elOwner) elOwner.innerHTML = '<span class="text-muted fst-italic">None yet (Pending Admin Approval)</span>';
                if (elPhone) elPhone.innerHTML = '<span class="text-muted">—</span>';
                if (elEmail) elEmail.innerHTML = '<span class="text-muted">—</span>';
                if (elAddr) elAddr.innerHTML = '<span class="text-muted">—</span>';
                if (elOwnerNote) {
                    elOwnerNote.innerHTML = '<div class="alert alert-light border border-warning-subtle text-muted small p-2 mb-0" style="border-radius: 8px;"><i class="fas fa-info-circle text-warning me-1"></i> No approved plot owner yet. Once admin approves the reservation, the applicant details will appear here as Plot Owner. Applicant details are shown below in Reservation Details Summary.</div>';
                }
                if (elBtnApprove) {
                    if (rawBookingId) {
                        elBtnApprove.style.display = 'inline-flex';
                        elBtnApprove.onclick = function() {
                            approveReservationModal(row, rawBookingId);
                        };
                    } else {
                        elBtnApprove.style.display = 'none';
                    }
                }
            }

            var elBooking = document.getElementById('rd_booking');
            if (elBooking) elBooking.textContent = booking;

            var currentStatus = row.getAttribute('data-current-status') || 'available';
            var isHist = row.getAttribute('data-is-historical') === '1';

            var elPlotStatus = document.getElementById('rd_plot_status');
            if (elPlotStatus) {
                var statusClass = 'text-success';
                if (currentStatus === 'occupied') statusClass = 'text-danger';
                else if (currentStatus === 'reserved') statusClass = 'text-warning text-dark';
                elPlotStatus.innerHTML = '<span class="fw-bold ' + statusClass + '">' + currentStatus.toUpperCase() + '</span>';
            }

            var elClassification = document.getElementById('rd_log_classification');
            if (elClassification) {
                if (isApproved) {
                    elClassification.innerHTML = '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Approved Reservation (Plot ' + currentStatus.toUpperCase() + ')</span>';
                } else if (isHist) {
                    elClassification.innerHTML = '<span class="badge bg-secondary"><i class="fas fa-history me-1"></i>Historical Log (Plot Available)</span>';
                } else {
                    elClassification.innerHTML = '<span class="badge bg-primary"><i class="fas fa-check-circle me-1"></i>Active / In-Use Plot</span>';
                }
            }

            var elPerf = document.getElementById('rd_performed_by');
            if (elPerf) elPerf.textContent = performedBy;

            var elOld = document.getElementById('rd_old_details');
            var elNew = document.getElementById('rd_new_details');
            var colOld = document.getElementById('rd_old_col');
            var colNew = document.getElementById('rd_new_col');
            if (elOld && colOld && colNew) {
                if (prevHtml && prevHtml.indexOf('—') === -1) {
                    elOld.innerHTML = prevHtml;
                    colOld.style.display = '';
                    colNew.className = 'col-md-6';
                } else {
                    colOld.style.display = 'none';
                    colNew.className = 'col-12';
                }
            }

            if (elNew) elNew.innerHTML = currHtml;

            var rdPrintBtn = document.getElementById('rd_print_receipt');
            var cleanBkId = parseInt(String(booking).replace(/[^0-9]/g, ''), 10);
            if (rdPrintBtn) {
                if (cleanBkId > 0) {
                    rdPrintBtn.style.display = '';
                    rdPrintBtn.onclick = function() {
                        window.location.href = 'payment-success.php?booking_id=' + cleanBkId + '&from=reservation_logs';
                    };
                } else {
                    rdPrintBtn.style.display = 'none';
                    rdPrintBtn.onclick = null;
                }
            }

            if (resModal) resModal.show();
        }

        function approveReservationModal(row, bookingId) {
            if (!bookingId || !confirm('Are you sure you want to approve this reservation? Once approved, the guest details will be assigned as the official Plot Owner.')) return;
            var btn = document.getElementById('rd_btn_approve');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Approving...';
            }

            var formData = new FormData();
            formData.append('action', 'approve_booking');
            formData.append('booking_id', bookingId);

            fetch('logs.php', {
                method: 'POST',
                body: formData
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (res.success) {
                    alert(res.message || 'Reservation approved successfully!');
                    var applicantName = row.getAttribute('data-applicant-name') || '';
                    var applicantPhone = row.getAttribute('data-applicant-phone') || '';
                    var applicantEmail = row.getAttribute('data-applicant-email') || '';
                    
                    row.setAttribute('data-is-approved', '1');
                    if (applicantName) row.setAttribute('data-owner', applicantName);
                    if (applicantPhone) row.setAttribute('data-phone', applicantPhone);
                    if (applicantEmail) row.setAttribute('data-email', applicantEmail);
                    row.setAttribute('data-current-status', 'reserved');
                    row.setAttribute('data-plot-status', 'Reserved');
                    row.setAttribute('data-is-historical', '0');

                    // Immediately re-render modal with guest details at the top!
                    openReservationDetail(row);
                } else {
                    alert('Approval failed: ' + (res.message || 'Unknown error'));
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Approve Reservation';
                    }
                }
            })
            .catch(function(err){
                alert('Request failed: ' + err.message);
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Approve Reservation';
                }
            });
        }

        // Attach click and keyboard handlers to reservation rows and check buttons
        document.addEventListener('click', function(e) {
            var checkBtn = e.target.closest('.check-res-btn');
            if (checkBtn) {
                e.preventDefault();
                e.stopPropagation();
                var row = checkBtn.closest('.reservation-row');
                if (row) openReservationDetail(row);
                return;
            }
        });

        Array.prototype.forEach.call(document.querySelectorAll('.reservation-row'), function(row){
            row.addEventListener('click', function(e){
                openReservationDetail(row);
            });
            row.addEventListener('keypress', function(e){
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openReservationDetail(row);
                }
            });
        });

        function postBookingAction(action){
            var id = bookingModalEl ? bookingModalEl.getAttribute('data-current-booking') : '';
            if (!id) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'warning', title: 'No Booking Selected', text: 'Please select a booking first.', confirmButtonColor: '#7b1113' });
                } else {
                    alert('No booking selected.');
                }
                return;
            }

            var actionTitle = 'Approve Reservation?';
            var actionText = 'Are you sure you want to approve this reservation request? The client will receive an approval confirmation email.';
            var actionBtnText = '<i class="fas fa-check me-1"></i> Yes, Approve';
            var actionBtnColor = '#198754';
            var actionIcon = 'question';

            if (action === 'reject_booking') {
                actionTitle = 'Reject Reservation?';
                actionText = 'Are you sure you want to reject this reservation? Any pending plot reservation will be released back to available.';
                actionBtnText = '<i class="fas fa-times me-1"></i> Yes, Reject';
                actionBtnColor = '#dc3545';
                actionIcon = 'warning';
            } else if (action === 'complete_booking') {
                actionTitle = 'Mark Reservation as Completed?';
                actionText = 'Are you sure you want to mark this service reservation as completed?';
                actionBtnText = '<i class="fas fa-check-double me-1"></i> Yes, Mark Completed';
                actionBtnColor = '#0d6efd';
                actionIcon = 'info';
            }

            var executePost = function(remarksVal) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Processing...',
                        text: 'Please wait while the booking status is being updated.',
                        allowOutsideClick: false,
                        didOpen: function() { Swal.showLoading(); }
                    });
                }

                var params = new URLSearchParams();
                params.append('action', action);
                params.append('booking_id', id);
                if (remarksVal) {
                    params.append('remarks', remarksVal);
                    params.append('rejection_reason', remarksVal);
                }

                fetch('logs.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data) throw new Error('No response received from server.');
                    if (data.success) {
                        var finalStatus = data.status || (action === 'accept_booking' || action === 'approve_booking' ? 'approved' : (action === 'reject_booking' ? 'rejected' : 'completed'));

                        // Update status cell in table row
                        var row = document.querySelector('[data-booking-id="' + id + '"]');
                        if (row) {
                            row.setAttribute('data-status', finalStatus);
                            if (data.remarks) {
                                row.setAttribute('data-remarks', data.remarks);
                            }
                            var cells = row.querySelectorAll('td');
                            if (cells && cells.length > 0) {
                                var statusCell = cells[cells.length - 1];
                                statusCell.innerHTML = getStatusBadge(finalStatus);
                            }
                        }

                        // Update modal status pill if still open
                        var statusPill = document.getElementById('bd_status_pill');
                        if (statusPill) {
                            var statusStyle = finalStatus === 'approved' ? 'background: rgba(16, 185, 129, 0.15); color: #065f46;' : (finalStatus === 'completed' ? 'background: rgba(59, 130, 246, 0.15); color: #1e40af;' : 'background: rgba(239, 68, 68, 0.15); color: #991b1b;');
                            statusPill.style = statusStyle + ' font-size: 0.82rem; font-weight: 600; border-radius: 999px;';
                            statusPill.textContent = 'Booking: ' + finalStatus.toUpperCase();
                        }

                        // Adjust action buttons in modal
                        var acceptBtn = document.getElementById('bd_accept');
                        var rejectBtn = document.getElementById('bd_reject');
                        var completeBtn = document.getElementById('bd_complete');
                        if (acceptBtn) acceptBtn.style.display = (finalStatus === 'pending') ? '' : 'none';
                        if (rejectBtn) rejectBtn.style.display = (finalStatus === 'pending') ? '' : 'none';
                        if (completeBtn) completeBtn.style.display = (finalStatus === 'approved') ? '' : 'none';

                        if (bookingModal) {
                            bookingModal.hide();
                        }

                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'success',
                                title: (finalStatus === 'approved' ? 'Booking Approved!' : (finalStatus === 'rejected' ? 'Booking Rejected' : 'Booking Completed!')),
                                text: data.message || ('Booking #' + id + ' has been ' + finalStatus + ' successfully.'),
                                confirmButtonColor: '#7b1113'
                            });
                        } else {
                            alert(data.message || ('Booking #' + id + ' has been ' + finalStatus + ' successfully.'));
                        }
                    } else {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'error',
                                title: 'Action Failed',
                                text: data.message || 'Unable to update booking status. Please try again.',
                                confirmButtonColor: '#7b1113'
                            });
                        } else {
                            alert('Action failed: ' + (data.message || 'Please try again'));
                        }
                    }
                })
                .catch(function(err) {
                    console.error('Booking action error:', err);
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Network / Server Error',
                            text: 'An error occurred while connecting to the server. Please check your connection or logs and try again.',
                            confirmButtonColor: '#7b1113'
                        });
                    } else {
                        alert('Network error. Please try again.');
                    }
                });
            };

            if (action === 'reject_booking') {
                if (bookingModalEl) {
                    bookingModalEl.removeAttribute('tabindex');
                }
                if (bookingModal) {
                    bookingModal.hide();
                }

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Reject Booking #' + id,
                        text: 'Please enter the reason or remarks for rejecting this booking. This will be sent as a notification and email to the user.',
                        input: 'textarea',
                        inputPlaceholder: 'Type rejection reason/remarks here (e.g. Incomplete payment proof, conflict in schedule)...',
                        inputAttributes: {
                            'aria-label': 'Rejection remarks',
                            'rows': 3
                        },
                        showCancelButton: true,
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: '<i class="fas fa-times me-1"></i> Reject Booking',
                        cancelButtonText: 'Cancel',
                        didOpen: function() {
                            setTimeout(function() {
                                var ta = Swal.getInput();
                                if (ta) {
                                    ta.focus();
                                }
                            }, 150);
                        },
                        willClose: function() {
                            if (bookingModalEl) {
                                bookingModalEl.setAttribute('tabindex', '-1');
                            }
                        }
                    }).then(function(result) {
                        if (result.isConfirmed) {
                            var rText = (result.value || '').trim();
                            executePost(rText || 'Booking application rejected by administrator.');
                        } else {
                            if (bookingModalEl) {
                                bookingModalEl.setAttribute('tabindex', '-1');
                            }
                            if (bookingModal) {
                                bookingModal.show();
                            }
                        }
                    });
                } else {
                    var rReason = prompt('Please enter rejection remarks for Booking #' + id + ':');
                    if (rReason !== null) {
                        executePost(rReason.trim() || 'Booking application rejected by administrator.');
                    } else if (bookingModal) {
                        bookingModal.show();
                    }
                }
            } else if (typeof Swal !== 'undefined') {
                if (bookingModalEl) {
                    bookingModalEl.removeAttribute('tabindex');
                }
                Swal.fire({
                    title: actionTitle,
                    text: actionText,
                    icon: actionIcon,
                    showCancelButton: true,
                    confirmButtonColor: actionBtnColor,
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: actionBtnText,
                    cancelButtonText: 'Cancel',
                    willClose: function() {
                        if (bookingModalEl) {
                            bookingModalEl.setAttribute('tabindex', '-1');
                        }
                    }
                }).then(function(result) {
                    if (result.isConfirmed) {
                        executePost();
                    }
                });
            } else {
                if (confirm(actionText)) {
                    executePost();
                }
            }
        }

        var acceptBtn = document.getElementById('bd_accept');
        var rejectBtn = document.getElementById('bd_reject');
        var completeBtn = document.getElementById('bd_complete');
        if (acceptBtn) acceptBtn.addEventListener('click', function(){ postBookingAction('accept_booking'); });
        if (rejectBtn) rejectBtn.addEventListener('click', function(){ postBookingAction('reject_booking'); });
        if (completeBtn) completeBtn.addEventListener('click', function(){ postBookingAction('complete_booking'); });

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, function(match) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[match];
            });
        }

        function getStatusBadge(status) {
            var normalized = (status || 'pending').toLowerCase();
            var badgeClass = 'bg-warning text-dark';
            var icon = 'clock';

            if (normalized === 'approved') {
                badgeClass = 'bg-success';
                icon = 'check-circle';
            } else if (normalized === 'rejected') {
                badgeClass = 'bg-danger';
                icon = 'times-circle';
            } else if (normalized === 'completed') {
                badgeClass = 'bg-primary';
                icon = 'trophy';
            }

            return '<span class="badge ' + badgeClass + '">' +
                '<i class="fas fa-' + icon + ' me-1"></i>' +
                normalized.charAt(0).toUpperCase() + normalized.slice(1) +
                '</span>';
        }

        function detailItem(label, value) {
            return '<div class="booking-detail-item">' +
                '<span class="booking-detail-label">' + escapeHtml(label) + '</span>' +
                '<div class="booking-detail-value">' + escapeHtml(value || 'N/A') + '</div>' +
                '</div>';
        }

        function adminViewPaymentProof(bookingId, filename) {
            const rawUrl = 'view_payment_proof.php?booking_id=' + bookingId + '&file=' + encodeURIComponent(filename) + '&raw=1';
            const downloadUrl = 'view_payment_proof.php?booking_id=' + bookingId + '&file=' + encodeURIComponent(filename) + '&download=1';
            
            let modal = document.getElementById('adminPaymentProofModal');
            if (!modal) {
                const modalHtml = `
                    <div class="modal fade" id="adminPaymentProofModal" tabindex="-1" aria-labelledby="adminPaymentProofModalLabel" aria-hidden="true" style="z-index: 1075;">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content" style="border-radius: 1.25rem; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.25); overflow: hidden;">
                                <div class="modal-header" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%); color: #fff; padding: 1.2rem 1.5rem; border-bottom: none;">
                                    <h5 class="modal-title fw-bold text-white m-0">
                                        <i class="fas fa-receipt text-warning me-2"></i> Payment Proof Screenshot
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body text-center" style="padding: 24px; background: #fafafa;">
                                    <img id="adminProofImage" src="" alt="Payment Proof" style="max-width: 100%; max-height: 520px; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); object-fit: contain;">
                                    <div class="mt-2 text-muted small font-monospace" id="adminProofFilenameText"></div>
                                </div>
                                <div class="modal-footer" style="background: #ffffff; border-top: 1px solid #eee; padding: 1rem 1.5rem;">
                                    <a id="adminDownloadProofLink" href="" download class="btn btn-primary" style="background: #7b1113; border: none; border-radius: 8px; padding: 8px 18px; font-weight: 600;">
                                        <i class="fas fa-download me-1"></i> Download Image
                                    </a>
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px; padding: 8px 18px;">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                document.body.insertAdjacentHTML('beforeend', modalHtml);
                modal = document.getElementById('adminPaymentProofModal');
            }
            
            const proofImg = document.getElementById('adminProofImage');
            if (proofImg) proofImg.src = rawUrl;
            const downloadLink = document.getElementById('adminDownloadProofLink');
            if (downloadLink) downloadLink.href = downloadUrl;
            const fnText = document.getElementById('adminProofFilenameText');
            if (fnText) fnText.textContent = filename || '';
            
            const bootstrapModal = bootstrap.Modal.getOrCreateInstance(modal);
            bootstrapModal.show();
        }
        window.adminViewPaymentProof = adminViewPaymentProof;

        function adminViewServiceProof(filename, bookingId) {
            if (!filename) return;
            var cleanFn = filename.replace(/^[\\\/]+/, '').replace(/^uploads[\/\\]service-proofs[\/\\]/i, '');
            var rawUrl = 'uploads/service-proofs/' + encodeURIComponent(cleanFn);
            
            var modalEl = document.getElementById('adminServiceProofModal');
            if (modalEl) {
                var imgEl = document.getElementById('adminServiceProofImage');
                if (imgEl) {
                    imgEl.onerror = function() {
                        this.onerror = null;
                        this.src = '../uploads/service-proofs/' + encodeURIComponent(cleanFn);
                    };
                    imgEl.src = rawUrl;
                }
                var dlEl = document.getElementById('adminDownloadServiceProofLink');
                if (dlEl) {
                    dlEl.href = rawUrl;
                    dlEl.setAttribute('download', cleanFn);
                }
                var fnText = document.getElementById('adminServiceProofFilenameText');
                if (fnText) fnText.textContent = 'Service Booking #' + (bookingId || '') + ' — ' + cleanFn;
                
                var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                bsModal.show();
            }
        }
        window.adminViewServiceProof = adminViewServiceProof;

        // Service completion photo preview
        var proofFileInput = document.getElementById('admin_service_proof_file');
        var proofPreviewBox = document.getElementById('admin_service_proof_preview_box');
        var proofPreviewImg = document.getElementById('admin_service_proof_preview');
        if (proofFileInput) {
            proofFileInput.addEventListener('change', function(){
                if (this.files && this.files[0]) {
                    var reader = new FileReader();
                    reader.onload = function(e){
                        if (proofPreviewImg) proofPreviewImg.src = e.target.result;
                        if (proofPreviewBox) proofPreviewBox.style.display = 'block';
                    };
                    reader.readAsDataURL(this.files[0]);
                } else {
                    if (proofPreviewBox) proofPreviewBox.style.display = 'none';
                }
            });
        }

        // Service completion form submission
        var completionForm = document.getElementById('adminServiceCompletionForm');
        if (completionForm) {
            completionForm.addEventListener('submit', function(e){
                e.preventDefault();
                var bId = document.getElementById('completion_booking_id').value;
                var fileInput = document.getElementById('admin_service_proof_file');
                if (!fileInput.files || fileInput.files.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Photo Required',
                        text: 'A real photo proof is mandatory to mark this cemetery service as completed.',
                        confirmButtonColor: '#7b1113'
                    });
                    return;
                }

                var formData = new FormData(completionForm);

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Uploading Proof...',
                        text: 'Please wait while the completion photo is uploaded and service status updated.',
                        allowOutsideClick: false,
                        didOpen: function(){ Swal.showLoading(); }
                    });
                }

                fetch('logs.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (data && data.success) {
                        var compModalEl = document.getElementById('adminServiceCompletionModal');
                        if (compModalEl) {
                            var bsCompModal = bootstrap.Modal.getInstance(compModalEl);
                            if (bsCompModal) bsCompModal.hide();
                        }
                        if (bookingModal) bookingModal.hide();

                        Swal.fire({
                            icon: 'success',
                            title: 'Service Completed!',
                            text: data.message || ('Service booking #' + bId + ' completed successfully with photo proof.'),
                            confirmButtonColor: '#198754'
                        }).then(function(){
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Completion Failed',
                            text: (data && data.message) ? data.message : 'Failed to complete service.',
                            confirmButtonColor: '#7b1113'
                        });
                    }
                })
                .catch(function(err){
                    console.error('Completion error:', err);
                    Swal.fire({
                        icon: 'error',
                        title: 'Server Error',
                        text: 'An error occurred while uploading completion proof.',
                        confirmButtonColor: '#7b1113'
                    });
                });
            });
        }

        // Hook Complete button to completion modal for services
        var origCompleteBtn = document.getElementById('bd_complete');
        if (origCompleteBtn) {
            origCompleteBtn.addEventListener('click', function(e){
                var id = bookingModalEl ? bookingModalEl.getAttribute('data-current-booking') : '';
                var row = document.querySelector('[data-booking-id="' + id + '"]');
                var isService = row && (row.classList.contains('service-row') || (row.getAttribute('data-service-type') && row.getAttribute('data-service-type') !== 'Reserve Grave'));
                if (isService) {
                    e.stopImmediatePropagation();
                    document.getElementById('completion_booking_id').value = id;
                    var srvType = row.getAttribute('data-service-type') || 'Cemetery Service';
                    var srvPlot = row.getAttribute('data-plot') || '';
                    var srvClient = row.getAttribute('data-customer-name') || '';
                    document.getElementById('completion_booking_info').innerHTML = '<strong>#' + id + '</strong> — ' + srvType + ' • ' + srvPlot + ' • Client: ' + srvClient;
                    var compFile = document.getElementById('admin_service_proof_file');
                    if (compFile) compFile.value = '';
                    var pBox = document.getElementById('admin_service_proof_preview_box');
                    if (pBox) pBox.style.display = 'none';

                    var compModalEl = document.getElementById('adminServiceCompletionModal');
                    if (compModalEl) {
                        var bsCompModal = bootstrap.Modal.getOrCreateInstance(compModalEl);
                        bsCompModal.show();
                    }
                }
            }, true);
        }

        // Hook Start Service button
        var startServiceBtn = document.getElementById('bd_start');
        if (startServiceBtn) {
            startServiceBtn.addEventListener('click', function(){
                postBookingAction('start_service');
            });
        }

    });

</script>
</body>
</html>

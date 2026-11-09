<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
require_once __DIR__ . '/connection.php';
$transferPolicyFile = __DIR__ . '/config/transfer_policy.php';
if (is_file($transferPolicyFile)) {
    require_once $transferPolicyFile;
}

if (!function_exists('ensure_remains_transfer_tables')) {
    function ensure_remains_transfer_tables(mysqli $conn, $force = false): void {
        @$conn->query("ALTER TABLE tbl_deceased ADD COLUMN IF NOT EXISTS interment_date DATE DEFAULT NULL");
        @$conn->query("ALTER TABLE tbl_deceased ADD COLUMN IF NOT EXISTS transfer_status VARCHAR(50) DEFAULT 'ORIGINAL'");
        @$conn->query("CREATE TABLE IF NOT EXISTS tbl_deceased_transfers (id INT AUTO_INCREMENT PRIMARY KEY, deceased_id INT NOT NULL, deceased_name VARCHAR(150) NOT NULL, original_section VARCHAR(20) NOT NULL, original_plot_x INT NOT NULL, original_plot_y INT NOT NULL, original_interment_date DATE NULL, destination_section VARCHAR(20) NULL, destination_plot_x INT NULL, destination_plot_y INT NULL, destination_type VARCHAR(50) DEFAULT 'NEW_PLOT', destination_details VARCHAR(255) NULL, transfer_date DATE NOT NULL, reason TEXT NULL, eligibility_at_transfer VARCHAR(50) NULL, years_since_interment DECIMAL(4,1) NULL, recorded_by_id INT NULL, recorded_by_name VARCHAR(100) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
if (!function_exists('calculate_remains_transfer_eligibility')) {
    function calculate_remains_transfer_eligibility($interment_date = null, $is_transferred = false, $as_of_date = null): array {
        if ($is_transferred === true || strtoupper((string)$is_transferred) === 'TRANSFERRED') return ['can_transfer' => false, 'status' => 'TRANSFERRED', 'message' => 'Remains have already been transferred.'];
        if (!$interment_date || $interment_date === '0000-00-00') return ['can_transfer' => false, 'status' => 'NO BURIAL DATE', 'message' => 'No burial date is available.'];
        $from = new DateTime($interment_date); $to = new DateTime($as_of_date ?: 'today');
        $years = (float)$from->diff($to)->days / 365.25;
        return ['can_transfer' => $years >= 8, 'status' => $years >= 8 ? 'ELIGIBLE FOR TRANSFER' : 'NOT YET ELIGIBLE', 'years_elapsed' => round($years, 1), 'message' => $years >= 8 ? 'Eligible for transfer.' : 'Minimum 8-year interval has not passed.'];
    }
}
if (!function_exists('get_genuinely_available_destination_plots')) {
    function get_genuinely_available_destination_plots(mysqli $conn): array {
        $rows = []; $res = @$conn->query("SELECT section, plot_x, plot_y, lawn_type, price FROM tbl_plots WHERE LOWER(status) = 'available' AND UPPER(TRIM(section)) IN ('A','B','C','D','E','F') ORDER BY section, plot_x, plot_y");
        while ($res && ($row = $res->fetch_assoc())) { $row['plot_no'] = $row['section'] . ' ' . $row['plot_x'] . '-' . $row['plot_y']; $row['display_label'] = 'Section ' . $row['section'] . ' Plot ' . $row['plot_x'] . '-' . $row['plot_y']; $rows[] = $row; }
        return $rows;
    }
}

ensure_remains_transfer_tables($conn);

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?auth_required=1');
    exit;
}

$userId = (int)$_SESSION['user_id'];
if (empty($_SESSION['role'])) {
    $rQ = $conn->query("SELECT role FROM tbl_user WHERE user_id = $userId LIMIT 1");
    if ($rQ && $rR = $rQ->fetch_assoc()) {
        $_SESSION['role'] = $rR['role'];
    }
}
$isAdmin = strtoupper(trim((string)($_SESSION['role'] ?? ''))) === 'ADMIN';
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $requestId = (int)($_POST['request_id'] ?? 0);

    if ($isAdmin && in_array($action, ['approve_transfer', 'reject_transfer'], true) && $requestId > 0) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT r.*, d.first_name, d.middle_name, d.last_name, d.suffix, d.date_died, d.interment_date 
                                     FROM plot_transfer_requests r 
                                     JOIN tbl_deceased d ON d.deceased_id = r.deceased_id 
                                     WHERE r.id = ? AND r.status = 'pending' FOR UPDATE");
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$request) {
                throw new Exception('Transfer request was not found or is no longer pending.');
            }

            if ($action === 'approve_transfer') {
                $intermentDate = !empty($request['interment_date']) ? $request['interment_date'] : $request['date_died'];
                $eligibility = calculate_remains_transfer_eligibility($intermentDate);

                if (!$eligibility['can_transfer']) {
                    throw new Exception("Transfer cannot be approved: " . $eligibility['message']);
                }

                $reqSec = strtoupper(trim($request['requested_section']));
                $reqX = (int)$request['requested_plot_x'];
                $reqY = (int)$request['requested_plot_y'];

                $chkDest = $conn->prepare("SELECT status FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? FOR UPDATE");
                $chkDest->bind_param('sii', $reqSec, $reqX, $reqY);
                $chkDest->execute();
                $destPlot = $chkDest->get_result()->fetch_assoc();
                $chkDest->close();

                if (!$destPlot || strtolower(trim((string)$destPlot['status'])) !== 'available') {
                    throw new Exception('The destination plot (Section ' . $reqSec . ' Plot ' . $reqX . '-' . $reqY . ') is no longer available.');
                }

                $chkOcc = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$reqSec}' AND plot_x = {$reqX} AND plot_y = {$reqY}");
                if ($chkOcc && (int)($chkOcc->fetch_assoc()['c'] ?? 0) > 0) {
                    throw new Exception('The destination plot is already occupied.');
                }

                $adminName = 'Administrator';
                $uQ = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = $userId");
                if ($uQ && $uRow = $uQ->fetch_assoc()) {
                    $adminName = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
                }

                $decFullName = trim(($request['first_name'] ?? '') . ' ' . (!empty($request['middle_name']) ? $request['middle_name'] . ' ' : '') . ($request['last_name'] ?? '') . (!empty($request['suffix']) ? ' ' . $request['suffix'] : ''));

                $oldSec = strtoupper(trim($request['current_section']));
                $oldX = (int)$request['current_plot_x'];
                $oldY = (int)$request['current_plot_y'];

                // Determine target client user ID
                $targetUserId = (int)($request['user_id'] ?? 0);
                if ($targetUserId <= 0) {
                    $decUQ = $conn->query("SELECT user_id FROM tbl_deceased WHERE deceased_id = " . (int)$request['deceased_id']);
                    if ($decUQ && ($duRow = $decUQ->fetch_assoc())) {
                        $targetUserId = (int)($duRow['user_id'] ?? 0);
                    }
                }
                if ($targetUserId <= 0) {
                    $uChk = $conn->query("SELECT user_id FROM user_plot_ownership WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY} AND (verification_status IS NULL OR verification_status != 'REJECTED') ORDER BY id DESC LIMIT 1");
                    if ($uChk && ($uRow = $uChk->fetch_assoc())) {
                        $targetUserId = (int)$uRow['user_id'];
                    }
                }
                if ($targetUserId > 0) {
                    $trRoleQ = $conn->query("SELECT role FROM tbl_user WHERE user_id = {$targetUserId} LIMIT 1");
                    if ($trRoleQ && ($trRow = $trRoleQ->fetch_assoc())) {
                        if (strtoupper(trim($trRow['role'] ?? '')) === 'ADMIN') {
                            $targetUserId = 0;
                        }
                    }
                }

                $move = $conn->prepare("UPDATE tbl_deceased SET section = ?, plot_x = ?, plot_y = ?, transfer_status = 'TRANSFERRED', edited_by = ?, user_id = COALESCE(NULLIF(user_id, 0), ?) WHERE deceased_id = ?");
                $move->bind_param('siisii', $reqSec, $reqX, $reqY, $adminName, $targetUserId, $request['deceased_id']);
                if (!$move->execute()) {
                    throw new Exception('Failed to update deceased record location: ' . $conn->error);
                }
                $move->close();

                $conn->query("UPDATE tbl_plots SET status = 'occupied' WHERE UPPER(TRIM(section)) = '{$reqSec}' AND plot_x = {$reqX} AND plot_y = {$reqY}");
                @$conn->query("UPDATE plots SET status = 'Occupied' WHERE UPPER(TRIM(section)) = '{$reqSec}' AND plot_x = {$reqX} AND plot_y = {$reqY}");

                // Fetch destination plot_id
                $destPlotId = null;
                $tpQ = $conn->query("SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = '{$reqSec}' AND plot_x = {$reqX} AND plot_y = {$reqY} LIMIT 1");
                if ($tpQ && ($tpRow = $tpQ->fetch_assoc())) {
                    $destPlotId = (int)$tpRow['plot_id'];
                }

                // Register destination plot in user_plot_ownership for target customer
                if ($targetUserId > 0) {
                    if (function_exists('ensure_user_plot_ownership_table')) {
                        ensure_user_plot_ownership_table($conn);
                    }
                    $transferNotes = "Transferred remains ({$decFullName}) from Section {$oldSec} Plot {$oldX}-{$oldY}";
                    $destUpo = $conn->prepare("INSERT INTO user_plot_ownership 
                        (user_id, section, plot_x, plot_y, plot_id, ownership_status, verification_status, claim_source, deceased_reference, notes, verified_by, verified_at)
                        VALUES (?, ?, ?, ?, ?, 'OWNED', 'VERIFIED', 'ADMIN_ASSIGNED', ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE 
                            ownership_status = 'OWNED',
                            verification_status = 'VERIFIED',
                            deceased_reference = VALUES(deceased_reference),
                            plot_id = COALESCE(VALUES(plot_id), plot_id),
                            notes = VALUES(notes),
                            verified_by = VALUES(verified_by),
                            verified_at = NOW(),
                            updated_at = NOW()");
                    if ($destUpo) {
                        $destUpo->bind_param('isiisssi', $targetUserId, $reqSec, $reqX, $reqY, $destPlotId, $decFullName, $transferNotes, $userId);
                        $destUpo->execute();
                        $destUpo->close();
                    }
                }
                $conn->query("UPDATE user_plot_ownership SET deceased_reference = '{$conn->real_escape_string($decFullName)}' WHERE UPPER(TRIM(section)) = '{$reqSec}' AND plot_x = {$reqX} AND plot_y = {$reqY}");

                // Synchronize tbl_plot_owners for destination plot if table exists
                $chkPoTable = $conn->query("SHOW TABLES LIKE 'tbl_plot_owners'");
                if ($chkPoTable && $chkPoTable->num_rows > 0 && $targetUserId > 0) {
                    $uInfoQ = $conn->query("SELECT first_name, middle_name, last_name, suffix, phone_no, address FROM tbl_user WHERE user_id = {$targetUserId}");
                    if ($uInfoQ && ($uInf = $uInfoQ->fetch_assoc())) {
                        $uFirst = trim($uInf['first_name'] ?? '');
                        $uMiddle = trim($uInf['middle_name'] ?? '');
                        $uLast = trim($uInf['last_name'] ?? '');
                        $uSuffix = trim($uInf['suffix'] ?? '');
                        $uOwnerName = trim(implode(' ', array_filter([$uFirst, $uMiddle, $uLast, $uSuffix])));
                        $uPhone = trim($uInf['phone_no'] ?? '');
                        $uAddr = trim($uInf['address'] ?? '');

                        $poExist = $conn->query("SELECT id FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = '{$reqSec}' AND plot_x = {$reqX} AND plot_y = {$reqY} AND active = 1 LIMIT 1");
                        if ($poExist && $poExist->num_rows > 0) {
                            $conn->query("UPDATE tbl_plot_owners SET reserved_by = {$targetUserId}, owner_name = '{$conn->real_escape_string($uOwnerName)}' WHERE UPPER(TRIM(section)) = '{$reqSec}' AND plot_x = {$reqX} AND plot_y = {$reqY} AND active = 1");
                        } else {
                            $poIns = $conn->prepare("INSERT INTO tbl_plot_owners (section, plot_x, plot_y, owner_name, first_name, middle_name, last_name, suffix, phone_no, address, reserved_by, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                            if ($poIns) {
                                $poIns->bind_param('siisssssssi', $reqSec, $reqX, $reqY, $uOwnerName, $uFirst, $uMiddle, $uLast, $uSuffix, $uPhone, $uAddr, $targetUserId);
                                $poIns->execute();
                                $poIns->close();
                            }
                        }
                    }
                }

                $oldCount = $conn->prepare("SELECT COUNT(*) AS total FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?");
                $oldCount->bind_param('sii', $oldSec, $oldX, $oldY);
                $oldCount->execute();
                $oldRow = $oldCount->get_result()->fetch_assoc();
                $oldCount->close();

                if ((int)($oldRow['total'] ?? 0) === 0) {
                    $conn->query("UPDATE tbl_plots SET status = 'available' WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                    @$conn->query("UPDATE plots SET status = 'Available' WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                    $conn->query("UPDATE tbl_plot_owners SET active = 0 WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                    if ($targetUserId > 0) {
                        $conn->query("DELETE FROM user_plot_ownership WHERE user_id = {$targetUserId} AND UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                    } else {
                        $conn->query("DELETE FROM user_plot_ownership WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                    }
                } else {
                    $remQ = $conn->query("SELECT first_name, middle_name, last_name, suffix FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY} ORDER BY deceased_id ASC LIMIT 1");
                    $remName = '';
                    if ($remQ && ($remRow = $remQ->fetch_assoc())) {
                        $remName = trim(implode(' ', array_filter([$remRow['first_name'], $remRow['middle_name'] ?? '', $remRow['last_name'], $remRow['suffix'] ?? ''])));
                    }
                    $conn->query("UPDATE user_plot_ownership SET deceased_reference = '{$conn->real_escape_string($remName)}' WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                }

                $tIns = $conn->prepare("INSERT INTO tbl_deceased_transfers 
                    (deceased_id, deceased_name, original_section, original_plot_x, original_plot_y, original_interment_date,
                     destination_section, destination_plot_x, destination_plot_y, destination_type, destination_details,
                     transfer_date, reason, eligibility_at_transfer, years_since_interment, recorded_by_id, recorded_by_name, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'NEW_PLOT', NULL, CURDATE(), ?, ?, ?, ?, ?, NOW())");
                if ($tIns) {
                    $yearsElapsed = (float)($eligibility['years_elapsed'] ?? 0);
                    $tReason = 'Approved transfer request #' . $requestId . ': ' . ($request['reason'] ?? '');
                    $tIns->bind_param('issiisiiissdis',
                        $request['deceased_id'], $decFullName, $oldSec, $oldX, $oldY, $intermentDate,
                        $reqSec, $reqX, $reqY,
                        $tReason, $eligibility['status'], $yearsElapsed, $userId, $adminName
                    );
                    $tIns->execute();
                    $tIns->close();
                }

                $logDetails = "Approved transfer request #{$requestId} for {$decFullName} from {$oldSec} {$oldX}-{$oldY} to {$reqSec} {$reqX}-{$reqY}.";
                $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('TRANSFER_APPROVED', ?, ?, NOW())");
                if ($logStmt) {
                    $logStmt->bind_param('si', $logDetails, $userId);
                    $logStmt->execute();
                    $logStmt->close();
                }

                $newStatus = 'approved';
                $message = "Transfer request #{$requestId} for {$decFullName} was approved and completed successfully.";
            } else {
                $newStatus = 'rejected';
                $logDetails = "Rejected transfer request #{$requestId}.";
                $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('TRANSFER_REJECTED', ?, ?, NOW())");
                if ($logStmt) {
                    $logStmt->bind_param('si', $logDetails, $userId);
                    $logStmt->execute();
                    $logStmt->close();
                }
                $message = "Transfer request #{$requestId} has been rejected.";
            }

            $review = $conn->prepare("UPDATE plot_transfer_requests SET status = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
            $review->bind_param('sii', $newStatus, $userId, $requestId);
            $review->execute();
            $review->close();

            if (function_exists('create_system_notification') && !empty($targetUserId) && $targetUserId > 0) {
                if ($newStatus === 'approved') {
                    create_system_notification(
                        $conn,
                        $targetUserId,
                        null,
                        'transfer_approved',
                        'Plot Transfer Approved',
                        "Your request to transfer {$decFullName} to Section {$reqSec} Plot {$reqX}-{$reqY} has been approved. The destination plot is now registered under your account.",
                        'user_dashboard.php'
                    );
                } else {
                    create_system_notification(
                        $conn,
                        $targetUserId,
                        null,
                        'transfer_rejected',
                        'Plot Transfer Request Rejected',
                        "Your request to transfer {$decFullName} was reviewed and rejected by the cemetery administrator.",
                        'transfer_requests.php'
                    );
                }
            }
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            $error = $exception->getMessage();
        }
    } 

    elseif (in_array($action, ['submit_transfer', 'admin_direct_transfer'], true)) {
        $deceasedId = (int)($_POST['deceased_id'] ?? 0);
        $section = strtoupper(trim($_POST['requested_section'] ?? ''));
        $plotX = (int)($_POST['requested_plot_x'] ?? 0);
        $plotY = (int)($_POST['requested_plot_y'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if (empty($section) && !empty($_POST['destination_plot'])) {
            $parts = explode('|', trim($_POST['destination_plot']));
            if (count($parts) >= 3) {
                $section = strtoupper(trim($parts[0]));
                $plotX = (int)$parts[1];
                $plotY = (int)$parts[2];
            }
        }

        if ($deceasedId <= 0) {
            $error = 'Please select a valid deceased record.';
        } elseif (!$isAdmin && !is_user_authorized_for_deceased($conn, $userId, $deceasedId)) {
            $error = 'You are not authorized to request a transfer for this deceased person.';
        } elseif (empty($section) || $plotX <= 0 || $plotY <= 0) {
            $error = 'Please select an available destination plot.';
        } elseif (empty($reason)) {
            $error = 'Please provide the reason for the transfer request.';
        } else {

            $dStmt = $conn->prepare("SELECT * FROM tbl_deceased WHERE deceased_id = ? LIMIT 1");
            $dStmt->bind_param('i', $deceasedId);
            $dStmt->execute();
            $decRow = $dStmt->get_result()->fetch_assoc();
            $dStmt->close();

            if (!$decRow) {
                $error = 'Deceased record not found.';
            } else {
                $intermentDate = !empty($decRow['interment_date']) ? $decRow['interment_date'] : $decRow['date_died'];
                $isTrans = (isset($decRow['transfer_status']) && strtoupper(trim((string)$decRow['transfer_status'])) === 'TRANSFERRED');
                $eligibility = calculate_remains_transfer_eligibility($intermentDate, $isTrans);

                if (!$eligibility['can_transfer']) {
                    $error = "Transfer is not eligible: " . $eligibility['message'];
                } else {

                    $chkDest = $conn->prepare("SELECT status FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?");
                    $chkDest->bind_param('sii', $section, $plotX, $plotY);
                    $chkDest->execute();
                    $destPlot = $chkDest->get_result()->fetch_assoc();
                    $chkDest->close();

                    $destStatus = strtolower(trim((string)($destPlot['status'] ?? '')));
                    $chkOcc = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$section}' AND plot_x = {$plotX} AND plot_y = {$plotY}");
                    $destOccupants = $chkOcc ? (int)($chkOcc->fetch_assoc()['c'] ?? 0) : 0;

                    $isVacantAvailable = ($destStatus === 'available' && $destOccupants === 0);
                    $ownerUserId = (int)($decRow['user_id'] ?? 0);
                    if ($ownerUserId <= 0 && !$isAdmin) {
                        $ownerUserId = (int)$userId;
                    }
                    $isCustomerOwned = false;
                    if ($ownerUserId > 0 && function_exists('is_user_authorized_for_plot')) {
                        $isCustomerOwned = is_user_authorized_for_plot($conn, $ownerUserId, $section, $plotX, $plotY, true);
                    }

                    if (!$isVacantAvailable && (!$isCustomerOwned || $destOccupants >= 4)) {
                        if (!$isCustomerOwned) {
                            $error = 'The selected destination plot does not belong to this account. Remains transfers can only be placed in available plots or plots owned by the registered customer account.';
                        } else {
                            $error = 'The selected destination plot has reached maximum capacity (4 deceased). Please choose another plot.';
                        }
                    } else {
                            if ($isAdmin && $action === 'admin_direct_transfer') {

                                $conn->begin_transaction();
                                try {
                                    $adminName = 'Administrator';
                                    $uQ = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = $userId");
                                    if ($uQ && $uRow = $uQ->fetch_assoc()) {
                                        $adminName = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
                                    }

                                    $decFullName = trim(($decRow['first_name'] ?? '') . ' ' . (!empty($decRow['middle_name']) ? $decRow['middle_name'] . ' ' : '') . ($decRow['last_name'] ?? '') . (!empty($decRow['suffix']) ? ' ' . $decRow['suffix'] : ''));
                                    $oldSec = strtoupper(trim($decRow['section']));
                                    $oldX = (int)$decRow['plot_x'];
                                    $oldY = (int)$decRow['plot_y'];

                                    $targetUserId = (int)($decRow['user_id'] ?? 0);
                                    if ($targetUserId <= 0) {
                                        $uChk = $conn->query("SELECT user_id FROM user_plot_ownership WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY} AND (verification_status IS NULL OR verification_status != 'REJECTED') ORDER BY id DESC LIMIT 1");
                                        if ($uChk && ($uRow = $uChk->fetch_assoc())) {
                                            $targetUserId = (int)$uRow['user_id'];
                                        }
                                    }
                                    if ($targetUserId > 0) {
                                        $trRoleQ = $conn->query("SELECT role FROM tbl_user WHERE user_id = {$targetUserId} LIMIT 1");
                                        if ($trRoleQ && ($trRow = $trRoleQ->fetch_assoc())) {
                                            if (strtoupper(trim($trRow['role'] ?? '')) === 'ADMIN') {
                                                $targetUserId = 0;
                                            }
                                        }
                                    }

                                    $move = $conn->prepare("UPDATE tbl_deceased SET section = ?, plot_x = ?, plot_y = ?, transfer_status = 'TRANSFERRED', edited_by = ?, user_id = COALESCE(NULLIF(user_id, 0), ?) WHERE deceased_id = ?");
                                    $move->bind_param('siisii', $section, $plotX, $plotY, $adminName, $targetUserId, $deceasedId);
                                    $move->execute();
                                    $move->close();

                                    $conn->query("UPDATE tbl_plots SET status = 'occupied' WHERE UPPER(TRIM(section)) = '{$section}' AND plot_x = {$plotX} AND plot_y = {$plotY}");
                                    @$conn->query("UPDATE plots SET status = 'Occupied' WHERE UPPER(TRIM(section)) = '{$section}' AND plot_x = {$plotX} AND plot_y = {$plotY}");

                                    // Destination plot_id
                                    $destPlotId = null;
                                    $tpQ = $conn->query("SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = '{$section}' AND plot_x = {$plotX} AND plot_y = {$plotY} LIMIT 1");
                                    if ($tpQ && ($tpRow = $tpQ->fetch_assoc())) {
                                        $destPlotId = (int)$tpRow['plot_id'];
                                    }

                                    // Register destination plot in user_plot_ownership for target customer
                                    if ($targetUserId > 0) {
                                        if (function_exists('ensure_user_plot_ownership_table')) {
                                            ensure_user_plot_ownership_table($conn);
                                        }
                                        $transferNotes = "Direct remains transfer ({$decFullName}) from Section {$oldSec} Plot {$oldX}-{$oldY}";
                                        $destUpo = $conn->prepare("INSERT INTO user_plot_ownership 
                                            (user_id, section, plot_x, plot_y, plot_id, ownership_status, verification_status, claim_source, deceased_reference, notes, verified_by, verified_at)
                                            VALUES (?, ?, ?, ?, ?, 'OWNED', 'VERIFIED', 'ADMIN_ASSIGNED', ?, ?, ?, NOW())
                                            ON DUPLICATE KEY UPDATE 
                                                ownership_status = 'OWNED',
                                                verification_status = 'VERIFIED',
                                                deceased_reference = VALUES(deceased_reference),
                                                plot_id = COALESCE(VALUES(plot_id), plot_id),
                                                notes = VALUES(notes),
                                                verified_by = VALUES(verified_by),
                                                verified_at = NOW(),
                                                updated_at = NOW()");
                                        if ($destUpo) {
                                            $destUpo->bind_param('isiisssi', $targetUserId, $section, $plotX, $plotY, $destPlotId, $decFullName, $transferNotes, $userId);
                                            $destUpo->execute();
                                            $destUpo->close();
                                        }
                                    }
                                    $conn->query("UPDATE user_plot_ownership SET deceased_reference = '{$conn->real_escape_string($decFullName)}' WHERE UPPER(TRIM(section)) = '{$section}' AND plot_x = {$plotX} AND plot_y = {$plotY}");

                                    // Synchronize tbl_plot_owners for destination plot if table exists
                                    $chkPoTable = $conn->query("SHOW TABLES LIKE 'tbl_plot_owners'");
                                    if ($chkPoTable && $chkPoTable->num_rows > 0 && $targetUserId > 0) {
                                        $uInfoQ = $conn->query("SELECT first_name, middle_name, last_name, suffix, phone_no, address FROM tbl_user WHERE user_id = {$targetUserId}");
                                        if ($uInfoQ && ($uInf = $uInfoQ->fetch_assoc())) {
                                            $uFirst = trim($uInf['first_name'] ?? '');
                                            $uMiddle = trim($uInf['middle_name'] ?? '');
                                            $uLast = trim($uInf['last_name'] ?? '');
                                            $uSuffix = trim($uInf['suffix'] ?? '');
                                            $uOwnerName = trim(implode(' ', array_filter([$uFirst, $uMiddle, $uLast, $uSuffix])));
                                            $uPhone = trim($uInf['phone_no'] ?? '');
                                            $uAddr = trim($uInf['address'] ?? '');

                                            $poExist = $conn->query("SELECT id FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = '{$section}' AND plot_x = {$plotX} AND plot_y = {$plotY} AND active = 1 LIMIT 1");
                                            if ($poExist && $poExist->num_rows > 0) {
                                                $conn->query("UPDATE tbl_plot_owners SET reserved_by = {$targetUserId}, owner_name = '{$conn->real_escape_string($uOwnerName)}' WHERE UPPER(TRIM(section)) = '{$section}' AND plot_x = {$plotX} AND plot_y = {$plotY} AND active = 1");
                                            } else {
                                                $poIns = $conn->prepare("INSERT INTO tbl_plot_owners (section, plot_x, plot_y, owner_name, first_name, middle_name, last_name, suffix, phone_no, address, reserved_by, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                                                if ($poIns) {
                                                    $poIns->bind_param('siisssssssi', $section, $plotX, $plotY, $uOwnerName, $uFirst, $uMiddle, $uLast, $uSuffix, $uPhone, $uAddr, $targetUserId);
                                                    $poIns->execute();
                                                    $poIns->close();
                                                }
                                            }
                                        }
                                    }

                                    $oldCount = $conn->prepare("SELECT COUNT(*) AS total FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?");
                                    $oldCount->bind_param('sii', $oldSec, $oldX, $oldY);
                                    $oldCount->execute();
                                    $oldRow = $oldCount->get_result()->fetch_assoc();
                                    $oldCount->close();

                                    if ((int)($oldRow['total'] ?? 0) === 0) {
                                        $conn->query("UPDATE tbl_plots SET status = 'available' WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                                        @$conn->query("UPDATE plots SET status = 'Available' WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                                        $conn->query("UPDATE tbl_plot_owners SET active = 0 WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                                        if ($targetUserId > 0) {
                                            $conn->query("DELETE FROM user_plot_ownership WHERE user_id = {$targetUserId} AND UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                                        } else {
                                            $conn->query("DELETE FROM user_plot_ownership WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                                        }
                                    } else {
                                        $remQ = $conn->query("SELECT first_name, middle_name, last_name, suffix FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY} ORDER BY deceased_id ASC LIMIT 1");
                                        $remName = '';
                                        if ($remQ && ($remRow = $remQ->fetch_assoc())) {
                                            $remName = trim(implode(' ', array_filter([$remRow['first_name'], $remRow['middle_name'] ?? '', $remRow['last_name'], $remRow['suffix'] ?? ''])));
                                        }
                                        $conn->query("UPDATE user_plot_ownership SET deceased_reference = '{$conn->real_escape_string($remName)}' WHERE UPPER(TRIM(section)) = '{$oldSec}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                                    }

                                    $yearsElapsed = (float)($eligibility['years_elapsed'] ?? 0);
                                    $tIns = $conn->prepare("INSERT INTO tbl_deceased_transfers 
                                        (deceased_id, deceased_name, original_section, original_plot_x, original_plot_y, original_interment_date,
                                         destination_section, destination_plot_x, destination_plot_y, destination_type, destination_details,
                                         transfer_date, reason, eligibility_at_transfer, years_since_interment, recorded_by_id, recorded_by_name, created_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'NEW_PLOT', NULL, CURDATE(), ?, ?, ?, ?, ?, NOW())");
                                    $tIns->bind_param('issiisiiissdis',
                                        $deceasedId, $decFullName, $oldSec, $oldX, $oldY, $intermentDate,
                                        $section, $plotX, $plotY,
                                        $reason, $eligibility['status'], $yearsElapsed, $userId, $adminName
                                    );
                                    $tIns->execute();
                                    $tIns->close();

                                    $logDetails = "Admin performed direct remains transfer for {$decFullName} from {$oldSec} {$oldX}-{$oldY} to {$section} {$plotX}-{$plotY}.";
                                    $conn->query("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('TRANSFER_COMPLETED', '{$conn->real_escape_string($logDetails)}', {$userId}, NOW())");

                                    $conn->commit();
                                    $message = "Remains transfer for {$decFullName} completed successfully to Section {$section} Plot {$plotX}-{$plotY}.";
                                } catch (Throwable $e) {
                                    $conn->rollback();
                                    $error = 'Failed to execute transfer: ' . $e->getMessage();
                                }
                            } else {

                                $insStmt = $conn->prepare("INSERT INTO plot_transfer_requests 
                                    (user_id, deceased_id, current_section, current_plot_x, current_plot_y, requested_section, requested_plot_x, requested_plot_y, reason, status, created_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
                                $cSec = strtoupper(trim($decRow['section']));
                                $cX = (int)$decRow['plot_x'];
                                $cY = (int)$decRow['plot_y'];
                                $insStmt->bind_param('iisiisiis', $userId, $deceasedId, $cSec, $cX, $cY, $section, $plotX, $plotY, $reason);
                                if ($insStmt->execute()) {
                                    $logDetails = "User ID #{$userId} submitted a plot transfer request for Deceased ID #{$deceasedId} to Section {$section}, Plot {$plotX}-{$plotY}";
                                    if ($logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('TRANSFER_REQUEST_SUBMITTED', ?, ?, NOW())")) {
                                        $logStmt->bind_param('si', $logDetails, $userId);
                                        $logStmt->execute();
                                        $logStmt->close();
                                    }
                                    if (function_exists('notify_administrators')) {
                                        notify_administrators($conn, 'New Plot Transfer Request', $logDetails, 'transfer_requests.php');
                                    }
                                    $message = 'Your plot transfer request has been submitted successfully for administrator review.';
                                } else {
                                    $error = 'Unable to submit transfer request: ' . $conn->error;
                                }
                                $insStmt->close();
                            }
                        }
                    }
                }
            }
        }
    }

$availablePlots = get_genuinely_available_destination_plots($conn);
$requests = [];
$completedTransfers = [];
$members = [];

if ($isAdmin) {

    $reqSql = "SELECT r.*, 
                      CONCAT_WS(' ', d.first_name, NULLIF(d.middle_name,''), d.last_name, NULLIF(d.suffix,'')) AS deceased_name, 
                      d.date_died, d.interment_date, d.transfer_status,
                      u.first_name AS user_first, u.last_name AS user_last, u.email AS user_email
               FROM plot_transfer_requests r 
               JOIN tbl_deceased d ON d.deceased_id = r.deceased_id 
               LEFT JOIN tbl_user u ON u.user_id = r.user_id
               ORDER BY (r.status = 'pending') DESC, r.id DESC";
    $reqRes = $conn->query($reqSql);
    while ($reqRes && ($row = $reqRes->fetch_assoc())) {
        $requests[] = $row;
    }

    $cRes = $conn->query("SELECT * FROM tbl_deceased_transfers ORDER BY transfer_date DESC, id DESC");
    while ($cRes && ($cRow = $cRes->fetch_assoc())) {
        $completedTransfers[] = $cRow;
    }

    $allDecRes = $conn->query("SELECT d.deceased_id, d.first_name, d.middle_name, d.last_name, d.suffix, d.date_died, d.interment_date, d.section, d.plot_x, d.plot_y, d.user_id,
                                      u.first_name AS user_first, u.last_name AS user_last, u.email AS user_email
                               FROM tbl_deceased d
                               LEFT JOIN tbl_user u ON u.user_id = d.user_id
                               WHERE (d.transfer_status IS NULL OR UPPER(d.transfer_status) != 'TRANSFERRED')
                               ORDER BY d.date_died ASC");
    $adminDeceasedOptions = [];
    while ($allDecRes && ($dRow = $allDecRes->fetch_assoc())) {
        $adminDeceasedOptions[] = $dRow;
    }
} else {

    $members = get_user_linked_deceased_records($conn, $userId);

    $stmt = $conn->prepare("SELECT r.*, 
                                   CONCAT_WS(' ', d.first_name, NULLIF(d.middle_name,''), d.last_name, NULLIF(d.suffix,'')) AS deceased_name,
                                   d.interment_date, d.date_died
                            FROM plot_transfer_requests r 
                            JOIN tbl_deceased d ON d.deceased_id = r.deceased_id 
                            WHERE r.user_id = ? 
                            ORDER BY r.id DESC");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $uReqRes = $stmt->get_result();
    while ($row = $uReqRes->fetch_assoc()) {
        $requests[] = $row;
    }
    $stmt->close();
}

$currentUserName = 'Customer';
if ($uQ = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = $userId")) {
    if ($uR = $uQ->fetch_assoc()) {
        $currentUserName = trim(($uR['first_name'] ?? '') . ' ' . ($uR['last_name'] ?? ''));
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plot &amp; Remains Transfers - Garden of Remembrance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/admin_header.css">
    <style>
        :root {
            --primary-maroon: #7b1113;
            --primary-maroon-hover: #94171a;
            --primary-maroon-dark: #5e0d0e;
            --bg-page: #f8fafc;
            --card-border: #e2e8f0;
        }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-page);
            color: #1e293b;
        }
        .page-header-container {
            max-width: 1300px;
            margin: 0 auto 1.5rem auto;
            width: 100%;
        }
        .transfer-hero-banner {
            background: linear-gradient(135deg, #7b1113 0%, #8e1518 50%, #5d0c0e 100%);
            color: #ffffff;
            border-radius: 16px;
            padding: 1.6rem 2rem;
            box-shadow: 0 10px 25px rgba(123, 17, 19, 0.22), 0 4px 10px rgba(0, 0, 0, 0.08);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.15);
            margin-bottom: 1.5rem;
            width: 100%;
        }
        .transfer-hero-banner::after {
            content: "";
            position: absolute;
            top: -40px;
            right: -40px;
            width: 160px;
            height: 160px;
            background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .transfer-hero-title {
            color: #ffffff;
            font-weight: 700;
            font-size: 1.65rem;
            letter-spacing: -0.01em;
            margin: 0;
            display: flex;
            align-items: center;
        }
        .transfer-hero-subtitle {
            color: rgba(255, 255, 255, 0.88);
            font-size: 0.92rem;
            font-weight: 400;
            max-width: 650px;
            line-height: 1.4;
            margin: 0;
        }
        @media (max-width: 768px) {
            .transfer-hero-banner {
                padding: 1.25rem 1.15rem;
                border-radius: 14px;
                margin-bottom: 1rem;
            }
            .transfer-hero-title {
                font-size: 1.28rem;
            }
            .transfer-hero-subtitle {
                font-size: 0.85rem;
            }
        }
        .btn-back-dashboard {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: #ffffff !important;
            text-decoration: none !important;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 0.35rem 0.85rem;
            border-radius: 20px;
            transition: all 0.2s ease;
            backdrop-filter: blur(4px);
        }
        .btn-back-dashboard:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.5);
            color: #ffffff !important;
            transform: translateX(-2px);
        }
        .transfer-rule-badge {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #ffffff;
            padding: 0.45rem 0.95rem;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 600;
            backdrop-filter: blur(4px);
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .main-content-container {
            max-width: 1300px;
            margin: 0 auto;
            width: 100%;
        }
        .page-title {
            color: var(--primary-maroon);
            font-weight: 700;
            font-size: 1.6rem;
            letter-spacing: -0.02em;
        }
        .page-subtitle {
            color: #64748b;
            font-size: 0.92rem;
            margin-bottom: 0;
        }
        .nav-tabs {
            border-bottom: 2px solid #e2e8f0;
        }
        .nav-tabs .nav-link {
            color: #475569;
            font-weight: 600;
            border: none;
            border-bottom: 2px solid transparent;
            padding: 0.65rem 1.25rem;
            font-size: 0.92rem;
        }
        .nav-tabs .nav-link.active {
            color: var(--primary-maroon);
            border-bottom: 2px solid var(--primary-maroon);
            background: transparent;
        }
        .card-custom {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            overflow: hidden;
        }
        .info-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1.25rem;
        }
        .info-card-title {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 0.75rem;
        }
        .badge-eligible {
            background-color: #ecfdf5 !important;
            color: #15803d !important;
            border: 1px solid #a7f3d0;
            font-weight: 600;
        }
        .badge-ineligible {
            background-color: #fffbeb !important;
            color: #b45309 !important;
            border: 1px solid #fde68a;
            font-weight: 600;
        }
        .badge-review {
            background-color: #fef2f2 !important;
            color: #b91c1c !important;
            border: 1px solid #fecaca;
            font-weight: 600;
        }
        .badge-transferred {
            background-color: #f1f5f9 !important;
            color: #475569 !important;
            border: 1px solid #cbd5e1;
            font-weight: 600;
        }
        .btn-maroon {
            background-color: var(--primary-maroon);
            border-color: var(--primary-maroon);
            color: #ffffff;
            font-weight: 600;
            padding: 0.65rem 1.25rem;
            border-radius: 8px;
            transition: all 0.15s ease-in-out;
        }
        .btn-maroon:hover:not(:disabled) {
            background-color: var(--primary-maroon-hover);
            border-color: var(--primary-maroon-hover);
            color: #ffffff;
        }
        .btn-maroon:disabled {
            background-color: #94a3b8;
            border-color: #94a3b8;
            cursor: not-allowed;
            opacity: 0.7;
        }
        .table th {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            color: #475569;
            background-color: #f8fafc;
            border-top: none;
            white-space: nowrap;
            padding: 0.5rem 0.55rem;
        }
        .table td {
            font-size: 0.79rem;
            vertical-align: middle;
            padding: 0.45rem 0.55rem;
        }
        .table td .badge {
            font-size: 0.7rem;
        }
        #transfersTable th, #transfersTable td {
            padding: 0.42rem 0.35rem !important;
            vertical-align: middle;
        }
        #transfersTable thead th {
            font-size: 0.69rem !important;
            font-weight: 700;
            letter-spacing: 0.02em;
            color: #475569;
            background-color: #f8fafc;
            border-top: none;
            white-space: nowrap;
        }
        #transfersTable td {
            font-size: 0.76rem !important;
        }
        #transfersTable .plot-badge {
            font-size: 0.69rem;
            padding: 2px 5px;
            font-weight: 500;
            white-space: nowrap;
        }
        #transfersTable .btn-action {
            font-size: 0.68rem;
            padding: 2px 6px;
            line-height: 1.2;
            height: 22px;
            white-space: nowrap;
        }
        .status-pill {
            display: inline-block;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.88rem;
            color: #334155;
            margin-bottom: 0.4rem;
        }
        .form-select, .form-control {
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            font-size: 0.9rem;
            padding: 0.6rem 0.85rem;
        }
        .form-select:focus, .form-control:focus {
            border-color: var(--primary-maroon);
            box-shadow: 0 0 0 3px rgba(123, 17, 19, 0.15);
        }
    </style>
</head>
<body>

<?php
$active_nav = 'transfers';
if ($isAdmin) {
    include __DIR__ . '/includes/admin_header.php';
} else {
    include __DIR__ . '/includes/user_header.php';
}
?>

<div class="py-4">

    <div class="page-header-container px-3 px-md-0">
        <div class="transfer-hero-banner">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <?php if (!$isAdmin): ?>
                    <a href="user_dashboard.php" class="btn-back-dashboard mb-3">
                        <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                    </a>
                    <?php else: ?>
                    <a href="dashboard.php" class="btn-back-dashboard mb-3">
                        <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                    </a>
                    <?php endif; ?>
                    <h1 class="transfer-hero-title mb-2">
                        <i class="fas fa-exchange-alt me-2"></i>Plot &amp; Remains Transfers
                    </h1>
                    <p class="transfer-hero-subtitle">
                        Review customer transfer requests and manage 8–10 year remains transfer records
                    </p>
                </div>
                <div class="mt-2 mt-md-0">
                    <span class="transfer-rule-badge">
                        <i class="fas fa-shield-alt me-1 text-warning"></i> Mandatory 8-Year Interment Rule Active
                    </span>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <div class="main-content-container px-3 px-md-0">
    <?php if ($isAdmin): ?>

        <ul class="nav nav-tabs mb-4" id="adminTransferTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="applications-tab" data-bs-toggle="tab" data-bs-target="#applications-pane" type="button" role="tab">
                    <i class="fas fa-inbox me-1"></i> Customer Transfer Applications 
                    <span class="badge rounded-pill bg-danger ms-1"><?php echo count($requests); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="process-tab" data-bs-toggle="tab" data-bs-target="#process-pane" type="button" role="tab">
                    <i class="fas fa-file-signature me-1"></i> Process / New Transfer
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history-pane" type="button" role="tab">
                    <i class="fas fa-history me-1"></i> Completed Remains Transfer Logs 
                    <span class="badge rounded-pill bg-secondary ms-1"><?php echo count($completedTransfers); ?></span>
                </button>
            </li>
        </ul>

        <div class="tab-content" id="adminTransferTabContent">

            <div class="tab-pane fade show active" id="applications-pane" role="tabpanel">
                <div class="card-custom p-3">

                    <div class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
                        <div class="d-flex align-items-center flex-grow-1" style="max-width: 480px;">
                            <div class="input-group shadow-sm" style="border-radius: 2rem; overflow: visible; border: 1px solid #d1d5db; background: #fff; position: relative;">

                                <div class="dropdown">
                                    <button type="button" class="btn btn-light border-0 px-3 text-muted position-relative" id="transfersFilterBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Filter by Status" style="background: transparent; border-radius: 2rem 0 0 2rem; height: 38px;">
                                        <i class="fas fa-sliders-h"></i>
                                        <span id="transfersFilterActiveDot" class="filter-active-dot" style="display:none;"></span>
                                    </button>

                                    <div class="dropdown-menu shadow-lg p-3 border-0" aria-labelledby="transfersFilterBtn" style="min-width: 260px; border-radius: 1rem; z-index: 1050;">
                                        <div class="d-flex align-items-center justify-content-between mb-2 border-bottom pb-2">
                                            <span class="fw-bold text-dark small"><i class="fas fa-filter text-danger me-1"></i> Filter Status</span>
                                            <button type="button" class="btn btn-link btn-sm text-danger p-0 text-decoration-none small" onclick="resetTransfersFilter()">Reset</button>
                                        </div>

                                        <div class="mb-2">
                                            <label class="form-label small fw-bold mb-1" for="transfersStatusFilter"><i class="fas fa-filter text-danger me-1"></i> Status:</label>
                                            <select id="transfersStatusFilter" class="form-select form-select-sm">
                                                <option value="">All Status</option>
                                                <option value="pending">Pending</option>
                                                <option value="approved">Approved</option>
                                                <option value="rejected">Rejected</option>
                                            </select>
                                        </div>

                                        <button type="button" class="btn btn-sm btn-danger w-100 mt-2 py-1 rounded-pill" onclick="filterTransfersTable(); bootstrap.Dropdown.getInstance(document.getElementById('transfersFilterBtn'))?.hide();" style="background-color: #7b1113; border-color: #7b1113;">
                                            <i class="fas fa-check me-1"></i> Apply Filter
                                        </button>
                                    </div>
                                </div>

                                <input id="transfersSearchInput" type="search" class="form-control border-0 px-2" placeholder="Search applicant, deceased, plot, or reason..." style="box-shadow:none; font-size: 0.9rem; background: transparent; height: 38px;">

                                <button class="btn btn-link text-muted px-2 text-decoration-none" type="button" id="transfersClearBtn" title="Clear Search" style="display: none;" onclick="clearTransfersSearch()">
                                    <i class="fas fa-times"></i>
                                </button>

                                <span class="input-group-text bg-white border-0 pe-3 text-muted" style="border-radius: 0 2rem 2rem 0; height: 38px;">
                                    <i class="fas fa-search"></i>
                                </span>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <span id="transfersFilterCount" class="badge bg-white text-dark border px-2 py-1 small">Total: <strong><?php echo count($requests); ?></strong></span>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="transfersResetBtn" style="display:none; height:26px; font-size:0.75rem;" onclick="resetTransfersFilter()" title="Reset Filters">
                                <i class="fas fa-rotate-left me-1"></i> Reset
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table id="transfersTable" class="table table-hover table-sm align-middle mb-0 w-100" style="table-layout: auto;">
                            <thead>
                                <tr style="text-transform: uppercase;">
                                    <th style="width: 15%;">Customer / Applicant</th>
                                    <th style="width: 10%;">Current Plot</th>
                                    <th style="width: 12%;">Deceased Person</th>
                                    <th style="width: 9%;">Interment Date</th>
                                    <th style="width: 4%; text-align: center;">Yrs</th>
                                    <th style="width: 14%;">Transfer Eligibility</th>
                                    <th style="width: 10%;">Dest. Plot</th>
                                    <th style="width: 9%;">Reason</th>
                                    <th style="width: 7%; text-align: center;">Status</th>
                                    <th style="width: 10%; text-align: center;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($requests)): ?>
                                <?php foreach ($requests as $r): 
                                    $intDate = !empty($r['interment_date']) ? $r['interment_date'] : ($r['date_died'] ?? null);
                                    $isTrans = ($r['status'] === 'approved') || (isset($r['transfer_status']) && strtoupper(trim((string)$r['transfer_status'])) === 'TRANSFERRED');
                                    $elig = calculate_remains_transfer_eligibility($intDate, $isTrans);
                                    $customerName = trim(($r['user_first'] ?? '') . ' ' . ($r['user_last'] ?? ''));
                                    if (empty($customerName)) { $customerName = 'User #' . ($r['user_id'] ?? '—'); }
                                ?>
                                    <tr class="transfer-request-row" data-status="<?php echo htmlspecialchars(strtolower($r['status'])); ?>">
                                        <td>
                                            <div class="fw-semibold text-dark text-truncate" style="max-width: 135px;" title="<?php echo htmlspecialchars($customerName); ?>"><?php echo htmlspecialchars($customerName); ?></div>
                                            <div class="text-muted text-truncate" style="max-width: 135px; font-size: 0.70rem;" title="<?php echo htmlspecialchars($r['user_email'] ?? 'No email'); ?>"><?php echo htmlspecialchars($r['user_email'] ?? 'No email'); ?></div>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <span class="badge bg-light text-dark border plot-badge">
                                                Sec <?php echo htmlspecialchars($r['current_section']); ?> &bull; Plot <?php echo (int)$r['current_plot_x']; ?>-<?php echo (int)$r['current_plot_y']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark text-truncate" style="max-width: 120px;" title="<?php echo htmlspecialchars($r['deceased_name']); ?>"><?php echo htmlspecialchars($r['deceased_name']); ?></div>
                                        </td>
                                        <td class="text-muted" style="white-space: nowrap; font-size: 0.72rem;">
                                            <?php echo !empty($intDate) ? date('M j, Y', strtotime($intDate)) : '—'; ?>
                                        </td>
                                        <td class="fw-semibold text-center text-muted" style="white-space: nowrap; font-size: 0.72rem;">
                                            <?php echo $elig['years_elapsed'] !== null ? $elig['years_elapsed'] . ' yrs' : '—'; ?>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <?php if ($elig['status_code'] === 'eligible'): ?>
                                                <span class="badge badge-eligible py-1 px-1" style="font-size: 0.67rem;" title="<?php echo htmlspecialchars($elig['message']); ?>">
                                                    <i class="fas fa-check-circle me-1"></i> ELIGIBLE (8+ yrs)
                                                </span>
                                            <?php elseif ($elig['status_code'] === 'review_required'): ?>
                                                <span class="badge badge-review py-1 px-1" style="font-size: 0.67rem;" title="<?php echo htmlspecialchars($elig['message']); ?>">
                                                    <i class="fas fa-exclamation-triangle me-1"></i> ADMIN REVIEW (10+y)
                                                </span>
                                            <?php elseif ($elig['status_code'] === 'transferred'): ?>
                                                <span class="badge badge-transferred py-1 px-1" style="font-size: 0.67rem;">
                                                    <i class="fas fa-check-double me-1"></i> TRANSFERRED
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-ineligible py-1 px-1" style="font-size: 0.67rem;" title="<?php echo htmlspecialchars($elig['message']); ?>">
                                                    <i class="fas fa-clock me-1"></i> NOT ELIGIBLE (<8y)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle plot-badge">
                                                Sec <?php echo htmlspecialchars($r['requested_section']); ?> &bull; Plot <?php echo (int)$r['requested_plot_x']; ?>-<?php echo (int)$r['requested_plot_y']; ?>
                                            </span>
                                        </td>
                                        <td style="max-width: 105px;" title="<?php echo htmlspecialchars($r['reason'] ?? ''); ?>">
                                            <span class="d-inline-block text-truncate text-dark fw-medium" style="max-width: 100px; font-size: 0.76rem;">
                                                <?php echo htmlspecialchars($r['reason'] ?? '—'); ?>
                                            </span>
                                        </td>
                                        <td class="text-center" style="white-space: nowrap;">
                                            <?php if ($r['status'] === 'pending'): ?>
                                                <span class="badge bg-warning text-dark px-1 py-1" style="font-size: 0.68rem;">Pending</span>
                                            <?php elseif ($r['status'] === 'approved'): ?>
                                                <span class="badge bg-success px-1 py-1" style="font-size: 0.68rem;">Approved</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger px-1 py-1" style="font-size: 0.68rem;">Rejected</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center" style="white-space: nowrap;">
                                            <?php if ($r['status'] === 'pending'): ?>
                                                <div class="d-flex flex-column gap-1 align-items-center justify-content-center">
                                                    <form method="post" class="m-0 w-100" onsubmit="return confirm('Approve transfer for <?php echo htmlspecialchars(addslashes($r['deceased_name'])); ?> to Section <?php echo htmlspecialchars($r['requested_section']); ?> Plot <?php echo (int)$r['requested_plot_x']; ?>-<?php echo (int)$r['requested_plot_y']; ?>?');">
                                                        <input type="hidden" name="action" value="approve_transfer">
                                                        <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-success w-100 btn-action d-flex align-items-center justify-content-center" <?php echo !$elig['can_transfer'] ? 'disabled title="Cannot approve: Minimum 8-year interval required"' : ''; ?>>
                                                            <i class="fas fa-check me-1"></i> Approve
                                                        </button>
                                                    </form>
                                                    <form method="post" class="m-0 w-100" onsubmit="return confirm('Reject this transfer request?');">
                                                        <input type="hidden" name="action" value="reject_transfer">
                                                        <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger w-100 btn-action d-flex align-items-center justify-content-center">
                                                            <i class="fas fa-times me-1"></i> Reject
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size: 0.72rem;">Reviewed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center py-5 text-muted">
                                        <i class="fas fa-folder-open mb-2" style="font-size: 2rem; opacity: 0.4;"></i>
                                        <div>No customer transfer requests found.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="process-pane" role="tabpanel">
                <div class="card-custom p-4 w-100">
                    <h5 class="fw-bold mb-1" style="color: var(--primary-maroon);">
                        <i class="fas fa-dolly-flatbed me-2"></i>Process Remains Transfer
                    </h5>
                    <p class="text-muted small mb-4">Execute verified remains transfers in compliance with the cemetery's 8–10 year interment interval standard.</p>

                    <form method="post" id="adminProcessTransferForm">
                        <input type="hidden" name="action" value="admin_direct_transfer">

                        <div class="mb-4">
                            <label class="form-label" for="adminDeceasedPicker">Select Deceased Person to Transfer:</label>
                            <select class="form-select" id="adminDeceasedPicker" name="deceased_id" required onchange="updateAdminTransferPreview(this)">
                                <option value="">-- Choose a deceased record --</option>
                                <?php foreach ($adminDeceasedOptions as $dOpt): 
                                    $dName = trim(implode(' ', array_filter([$dOpt['first_name'], $dOpt['middle_name'] ?? '', $dOpt['last_name'], $dOpt['suffix'] ?? ''])));
                                    $cName = trim(($dOpt['user_first'] ?? '') . ' ' . ($dOpt['user_last'] ?? ''));
                                    if (empty($cName)) { $cName = 'Customer Record'; }
                                    $iDate = !empty($dOpt['interment_date']) ? $dOpt['interment_date'] : $dOpt['date_died'];
                                    $eCheck = calculate_remains_transfer_eligibility($iDate);
                                ?>
                                    <option value="<?php echo (int)$dOpt['deceased_id']; ?>"
                                            data-customer="<?php echo htmlspecialchars($cName, ENT_QUOTES); ?>"
                                            data-name="<?php echo htmlspecialchars($dName, ENT_QUOTES); ?>"
                                            data-section="<?php echo htmlspecialchars($dOpt['section'], ENT_QUOTES); ?>"
                                            data-plot-x="<?php echo (int)$dOpt['plot_x']; ?>"
                                            data-plot-y="<?php echo (int)$dOpt['plot_y']; ?>"
                                            data-interment="<?php echo htmlspecialchars($iDate, ENT_QUOTES); ?>"
                                            data-formatted-interment="<?php echo !empty($iDate) ? date('M j, Y', strtotime($iDate)) : 'N/A'; ?>"
                                            data-years="<?php echo htmlspecialchars($eCheck['years_elapsed'] ?? '0', ENT_QUOTES); ?>"
                                            data-status="<?php echo htmlspecialchars($eCheck['status'], ENT_QUOTES); ?>"
                                            data-can-transfer="<?php echo $eCheck['can_transfer'] ? '1' : '0'; ?>"
                                            data-message="<?php echo htmlspecialchars($eCheck['message'], ENT_QUOTES); ?>">
                                        <?php echo htmlspecialchars($dName . ' (Section ' . $dOpt['section'] . ' Plot ' . $dOpt['plot_x'] . '-' . $dOpt['plot_y'] . ') — ' . $eCheck['status']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="info-card mb-3" id="adminInfoCard" style="display: none;">
                            <div class="info-card-title">
                                <i class="fas fa-id-card me-1 text-primary"></i> Customer / Remains Information
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <span class="text-muted small d-block">Customer / Account Holder:</span>
                                    <span class="fw-bold text-dark" id="previewAdminCustomer">—</span>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted small d-block">Current Plot:</span>
                                    <span class="badge bg-light text-dark border px-2 py-1" id="previewAdminPlot">—</span>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted small d-block">Deceased Name:</span>
                                    <span class="fw-bold text-dark" id="previewAdminDeceased">—</span>
                                </div>
                                <div class="col-sm-3 col-6">
                                    <span class="text-muted small d-block">Date of Interment:</span>
                                    <span class="fw-semibold text-secondary" id="previewAdminInterment">—</span>
                                </div>
                                <div class="col-sm-3 col-6">
                                    <span class="text-muted small d-block">Years Since Interment:</span>
                                    <span class="fw-bold text-primary" id="previewAdminYears">—</span>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4" id="adminEligibilityCard" style="display: none;">

                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Choose Available Destination Plot:</label>

                            <div id="adminNoPlotChosenContainer">
                                <button type="button" class="btn d-inline-flex align-items-center gap-2 text-white" id="btnAdminOpenTransferMap" style="background: #7b1113; border-color: #7b1113; border-radius: 9px; font-weight: 600; padding: 0.55rem 1.25rem;">
                                    <i class="fas fa-map-marked-alt fs-5"></i> Choose Destination Plot from Map
                                </button>
                                <div class="text-muted small mt-1.5" style="font-size: 0.8rem;">
                                    Click the button above to open the cemetery map and select an available vacant plot.
                                </div>
                            </div>

                            <input type="hidden" name="destination_plot" id="adminDestPlotSelect" value="">
                            <input type="hidden" name="requested_section" id="adminRequestedSection" value="">
                            <input type="hidden" name="requested_plot_x" id="adminRequestedPlotX" value="">
                            <input type="hidden" name="requested_plot_y" id="adminRequestedPlotY" value="">

                            <div id="adminSelectedPlotCard" class="p-3 rounded-3 border" style="background:#fff8f5; border-color:#ecd9c6 !important; display:none;">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <span class="badge mb-1 px-2 py-1" style="background:#7b1113; color:#fff;"><i class="fas fa-check-circle me-1"></i> AVAILABLE PLOT SELECTED</span>
                                        <div class="fw-bold text-dark fs-6" id="adminSelectedPlotTitle">Section A &bull; Plot 1-1</div>
                                        <div class="small text-muted" id="adminSelectedPlotDetails">Lawn (Regular) &bull; Verified Available for Transfer</div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-danger" id="btnAdminChangePlotOnMap" style="border-radius: 8px;">
                                            <i class="fas fa-map-marked-alt me-1"></i> Change on Map
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAdminClearPlot" style="border-radius: 8px;">
                                            <i class="fas fa-times me-1"></i> Clear
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="adminTransferReason">Reason for Transfer:</label>
                            <textarea class="form-control" id="adminTransferReason" name="reason" rows="3" placeholder="Enter reason or documentation notes for this remains transfer..." required></textarea>
                        </div>

                        <button type="submit" id="adminSubmitTransferBtn" class="btn btn-maroon w-100" disabled>
                            <i class="fas fa-check me-1"></i> Submit Transfer Request
                        </button>
                        <div id="adminSubmitNotice" class="text-muted small text-center mt-2" style="font-size: 0.8rem;">
                            Please select a deceased record above to verify eligibility.
                        </div>
                    </form>
                </div>
            </div>

            <div class="tab-pane fade" id="history-pane" role="tabpanel">
                <div class="card-custom p-3">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Transfer Date</th>
                                    <th>Deceased Name</th>
                                    <th>From Plot</th>
                                    <th>Destination Plot</th>
                                    <th>Eligibility Status</th>
                                    <th>Reason / Remarks</th>
                                    <th>Recorded By</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($completedTransfers)): ?>
                                <?php foreach ($completedTransfers as $c): 
                                    $dest = $c['destination_type'] === 'NEW_PLOT' 
                                        ? 'Section ' . htmlspecialchars($c['destination_section']) . ' &bull; Plot ' . (int)$c['destination_plot_x'] . '-' . (int)$c['destination_plot_y']
                                        : htmlspecialchars($c['destination_type']) . (!empty($c['destination_details']) ? ' (' . htmlspecialchars($c['destination_details']) . ')' : '');
                                ?>
                                    <tr>
                                        <td class="fw-bold text-dark"><?php echo htmlspecialchars(date('M j, Y', strtotime($c['transfer_date']))); ?></td>
                                        <td class="fw-semibold text-dark"><?php echo htmlspecialchars($c['deceased_name']); ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                Section <?php echo htmlspecialchars($c['original_section']); ?> &bull; Plot <?php echo (int)$c['original_plot_x']; ?>-<?php echo (int)$c['original_plot_y']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                <?php echo $dest; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($c['eligibility_at_transfer'] ?? 'N/A'); ?></span>
                                            <?php if (!empty($c['years_since_interment'])): ?>
                                                <span class="small text-muted ms-1">(<?php echo htmlspecialchars($c['years_since_interment']); ?> yrs)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted" style="max-width: 250px;">
                                            <?php echo htmlspecialchars($c['reason'] ?? '—'); ?>
                                        </td>
                                        <td class="small text-secondary">
                                            <i class="fas fa-user-shield me-1"></i> <?php echo htmlspecialchars($c['recorded_by_name'] ?? 'Admin'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-history mb-2" style="font-size: 2rem; opacity: 0.4;"></i>
                                        <div>No completed remains transfers recorded yet.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>

        <div class="w-100">
                <?php if (empty($members)): ?>
                    <div class="card-custom p-5 text-center">
                        <div class="mb-3 text-secondary">
                            <i class="fas fa-user-friends" style="font-size: 3rem; opacity: 0.5;"></i>
                        </div>
                        <h4 class="fw-bold mb-2">No Linked Deceased Records</h4>
                        <p class="text-muted" style="max-width: 500px; margin: 0 auto;">
                            Remains transfer requests can only be submitted for verified deceased family members linked to your account.
                        </p>
                        <div class="mt-4">
                            <a href="user_dashboard.php" class="btn btn-maroon">Return to Dashboard</a>
                        </div>
                    </div>
                <?php else: ?>

                    <?php 
                        $selectedDeceased = null;
                        if (isset($_GET['selected_deceased_id']) && is_numeric($_GET['selected_deceased_id'])) {
                            $reqDecId = (int)$_GET['selected_deceased_id'];
                            foreach ($members as $m) {
                                if ((int)$m['deceased_id'] === $reqDecId) {
                                    $selectedDeceased = $m;
                                    break;
                                }
                            }
                        }

                        if (!$selectedDeceased && !empty($members)) {
                            $selectedDeceased = $members[0];
                            foreach ($members as $m) {
                                if (stripos($m['first_name'], 'victorio') !== false) {
                                    $selectedDeceased = $m;
                                    break;
                                }
                            }
                        }
                    ?>

                    <?php if (count($members) > 1): ?>
                        <div class="card-custom p-3 mb-4">
                            <label class="form-label mb-2 fw-semibold">Select Family Member to Request Transfer For:</label>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($members as $m): 
                                    $mFull = trim(implode(' ', array_filter([$m['first_name'], $m['middle_name'] ?? '', $m['last_name'], $m['suffix'] ?? ''])));
                                    $isSelected = ((int)$m['deceased_id'] === (int)$selectedDeceased['deceased_id']);
                                    $queryArgs = $_GET;
                                    $queryArgs['selected_deceased_id'] = (int)$m['deceased_id'];
                                    $pillUrl = '?' . http_build_query($queryArgs);
                                ?>
                                    <a href="<?php echo htmlspecialchars($pillUrl); ?>" 
                                       class="btn btn-sm <?php echo $isSelected ? 'btn-maroon active shadow-sm' : 'btn-outline-secondary'; ?>"
                                       style="<?php echo $isSelected ? 'background-color: #7b1113 !important; border-color: #7b1113 !important; color: #ffffff !important; font-weight: 600;' : 'background-color: #ffffff; color: #475569; font-weight: 500; border-color: #cbd5e1;'; ?>">
                                        <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($mFull); ?> (Section <?php echo htmlspecialchars($m['section']); ?> Plot <?php echo (int)$m['plot_x']; ?>-<?php echo (int)$m['plot_y']; ?>)
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php
                        $mFull = trim(implode(' ', array_filter([$selectedDeceased['first_name'], $selectedDeceased['middle_name'] ?? '', $selectedDeceased['last_name'], $selectedDeceased['suffix'] ?? ''])));
                        $mInterment = !empty($selectedDeceased['interment_date']) ? $selectedDeceased['interment_date'] : $selectedDeceased['date_died'];
                        $mElig = calculate_remains_transfer_eligibility($mInterment);
                    ?>

                    <div class="card-custom p-4 mb-4">
                        <form method="post" id="userTransferRequestForm">
                            <input type="hidden" name="action" value="submit_transfer">
                            <input type="hidden" name="deceased_id" value="<?php echo (int)$selectedDeceased['deceased_id']; ?>">
                            <input type="hidden" name="requested_section" id="userRequestedSection">
                            <input type="hidden" name="requested_plot_x" id="userRequestedPlotX">
                            <input type="hidden" name="requested_plot_y" id="userRequestedPlotY">

                            <div class="info-card mb-3">
                                <div class="info-card-title">
                                    <i class="fas fa-user-circle me-1 text-primary"></i> Customer / Remains Information
                                </div>
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <span class="text-muted small d-block">Customer:</span>
                                        <span class="fw-bold text-dark text-uppercase"><?php echo htmlspecialchars($currentUserName); ?></span>
                                    </div>
                                    <div class="col-sm-6">
                                        <span class="text-muted small d-block">Current Plot:</span>
                                        <span class="badge bg-light text-dark border px-2 py-1 font-monospace" style="font-size: 0.88rem;">
                                            Section <?php echo htmlspecialchars($selectedDeceased['section']); ?> Plot <?php echo (int)$selectedDeceased['plot_x']; ?>-<?php echo (int)$selectedDeceased['plot_y']; ?>
                                        </span>
                                    </div>
                                    <div class="col-sm-6">
                                        <span class="text-muted small d-block">Deceased Name:</span>
                                        <span class="fw-bold text-dark text-uppercase"><?php echo htmlspecialchars($mFull); ?></span>
                                    </div>
                                    <div class="col-sm-3 col-6">
                                        <span class="text-muted small d-block">Date of Interment:</span>
                                        <span class="fw-semibold text-secondary">
                                            <?php echo !empty($mInterment) ? date('M j, Y', strtotime($mInterment)) : 'N/A'; ?>
                                        </span>
                                    </div>
                                    <div class="col-sm-3 col-6">
                                        <span class="text-muted small d-block">Years Since Interment:</span>
                                        <span class="fw-bold text-primary">
                                            <?php echo $mElig['years_elapsed'] !== null ? $mElig['years_elapsed'] . ' ' . (((float)$mElig['years_elapsed'] == 1.0) ? 'year' : 'years') : 'N/A'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <?php if ($mElig['can_transfer']): ?>
                                    <div class="p-3 rounded-3 border" style="background-color: #f0fdf4; border-color: #bbf7d0 !important;">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="text-success" style="font-size: 1.75rem;">
                                                <i class="fas fa-check-circle"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-success" style="font-size: 1rem;">
                                                    ELIGIBLE FOR TRANSFER (8 years completed)
                                                </div>
                                                <div class="text-muted small mt-1">
                                                    Interment date: <?php echo !empty($mInterment) ? date('F j, Y', strtotime($mInterment)) : 'N/A'; ?> (<?php echo htmlspecialchars($mElig['formatted_interval']); ?> elapsed). 
                                                    This deceased record satisfies the cemetery's 8-year remains movement policy.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="p-3 rounded-3 border" style="background-color: #fffbeb; border-color: #fde68a !important;">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="text-warning" style="font-size: 1.75rem;">
                                                <i class="fas fa-clock"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-danger" style="font-size: 1rem;">
                                                    NOT YET ELIGIBLE FOR TRANSFER
                                                </div>
                                                <div class="text-muted small mt-1">
                                                    Only <?php echo (int)$mElig['years_elapsed']; ?> <?php echo ((int)$mElig['years_elapsed'] === 1) ? 'year' : 'years'; ?> completed since interment. Minimum required interval: 8 years. 
                                                    <?php if (!empty($mElig['eligible_date'])): ?>
                                                        Eligible for transfer on: <strong><?php echo date('F j, Y', strtotime($mElig['eligible_date'])); ?></strong>.
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">
                                    <i class="fas fa-map-marker-alt text-danger me-1"></i> Choose Available Destination Plot:
                                </label>

                                <div id="userNoPlotChosenContainer">
                                    <button type="button" class="btn btn-outline-danger btn-sm fw-bold px-3 py-2 d-inline-flex align-items-center gap-2" id="btnUserOpenTransferMap" style="border-radius: 8px;">
                                        <i class="fas fa-map-marked-alt me-2 text-danger"></i> Choose Destination Plot from Map
                                    </button>
                                    <div class="text-muted small mt-1" style="font-size:0.78rem;">Click to select an available vacant plot directly on the cemetery map.</div>
                                </div>

                                <input type="hidden" id="userDestinationPlot" name="destination_plot" value="">
                                <input type="hidden" id="userRequestedSection" name="requested_section" value="">
                                <input type="hidden" id="userRequestedPlotX" name="requested_plot_x" value="">
                                <input type="hidden" id="userRequestedPlotY" name="requested_plot_y" value="">

                                <div id="userSelectedPlotCard" class="p-2.5 rounded-3 border" style="background:#fff8f5; border-color:#ecd9c6 !important; display:none;">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div>
                                            <span class="badge mb-1 px-2 py-0.5 small" style="background:#7b1113; color:#fff;"><i class="fas fa-check-circle me-1"></i> Selected on Map</span>
                                            <div class="fw-bold text-dark small" id="userSelectedPlotTitle">Section A &bull; Plot 1-1</div>
                                            <div class="text-muted" style="font-size:0.75rem;" id="userSelectedPlotDetails">Lawn (Regular)</div>
                                        </div>
                                        <div class="d-flex gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" id="btnUserChangePlotOnMap" style="font-size:0.78rem; border-radius: 6px;">Change</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="btnUserClearPlot" style="font-size:0.78rem; border-radius: 6px;">Clear</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label" for="userTransferReason">Reason for Transfer:</label>
                                <textarea class="form-control" id="userTransferReason" name="reason" rows="3" placeholder="State the reason for this transfer request (e.g. family consolidation, plot upgrade)..." required></textarea>
                            </div>

                            <button type="submit" class="btn btn-maroon w-100" <?php echo !$mElig['can_transfer'] ? 'disabled' : ''; ?>>
                                <i class="fas fa-paper-plane me-1"></i> Submit Transfer Request
                            </button>
                            <?php if (!$mElig['can_transfer']): ?>
                                <div class="text-danger small text-center mt-2" style="font-size: 0.8rem;">
                                    <i class="fas fa-lock me-1"></i> Transfer request is disabled because this record has not yet reached the 8-year interment requirement.
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php if (!empty($requests)): ?>
                        <div class="card-custom p-4 mb-4">
                            <h5 class="fw-bold mb-3" style="color: var(--primary-maroon);">
                                <i class="fas fa-list-alt me-2"></i>Your Transfer Requests
                            </h5>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Deceased Person</th>
                                            <th>From Plot</th>
                                            <th>Requested Plot</th>
                                            <th>Date Submitted</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($requests as $uReq): ?>
                                        <tr>
                                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($uReq['deceased_name']); ?></td>
                                            <td><span class="badge bg-light text-dark border">Section <?php echo htmlspecialchars($uReq['current_section']); ?> Plot <?php echo (int)$uReq['current_plot_x']; ?>-<?php echo (int)$uReq['current_plot_y']; ?></span></td>
                                            <td><span class="badge bg-primary-subtle text-primary border border-primary-subtle">Section <?php echo htmlspecialchars($uReq['requested_section']); ?> Plot <?php echo (int)$uReq['requested_plot_x']; ?>-<?php echo (int)$uReq['requested_plot_y']; ?></span></td>
                                            <td class="small text-muted"><?php echo htmlspecialchars(date('M j, Y', strtotime($uReq['created_at']))); ?></td>
                                            <td>
                                                <?php if ($uReq['status'] === 'pending'): ?>
                                                    <span class="badge bg-warning text-dark">Under Admin Review</span>
                                                <?php elseif ($uReq['status'] === 'approved'): ?>
                                                    <span class="badge bg-success">Approved &amp; Transferred</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Rejected</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>
        </div>
    <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<div class="modal fade" id="transferMapModal" tabindex="-1" aria-labelledby="transferMapModalLabel" aria-hidden="true" data-bs-keyboard="false" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 920px; width: 92%; margin: 1.5rem auto;">
    <div class="modal-content" style="border-radius:1.25rem;overflow:hidden;border:none;box-shadow:0 10px 40px rgba(0,0,0,0.25);">
      <div class="modal-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%) !important; color:#fff; border-bottom:1px solid rgba(255,255,255,0.15); padding:0.9rem 1.4rem;">
        <div class="d-flex align-items-center gap-3 flex-wrap">
          <h5 class="modal-title m-0 fw-bold" id="transferMapModalLabel" style="color:#fff;">
            <i class="fas fa-map-marked-alt me-2"></i> Choose Destination Cemetery Plot
          </h5>
          <div class="d-flex align-items-center gap-2">
            <label for="transferPopupSectionSelect" class="small mb-0 text-white fw-semibold">Section:</label>
            <select id="transferPopupSectionSelect" class="form-select form-select-sm" style="width: 145px; min-width: 145px; font-weight: 600; border-radius: 8px; padding-left: 12px; padding-right: 34px; background-position: right 0.75rem center; cursor: pointer; color: #1e293b; background-color: #ffffff;">
              <option value="A">Section A</option>
              <option value="B">Section B</option>
              <option value="C">Section C</option>
              <option value="D">Section D</option>
              <option value="E">Section E</option>
              <option value="F">Section F</option>
              <option value="G">Section G</option>
              <option value="H">Section H</option>
              <option value="I">Section I</option>
              <option value="J">Section J</option>
            </select>
          </div>
          <span class="badge px-2.5 py-1 text-uppercase" style="background:#94171a; border: 1px solid rgba(255,255,255,0.25); color:#fff; font-size:0.75rem; letter-spacing:0.5px;">
            <i class="fas fa-hand-pointer me-1"></i> Choose Available Plot
          </span>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0" style="height:72vh; max-height:650px; position:relative; background:#f8f9fa;">
        <iframe id="transferMapIframe" src="" style="width:100%;height:100%;border:0;background:#f8f9fa;"></iframe>
      </div>
    </div>
  </div>
</div>

<script>
function handleUserDestChange(select) {
    var opt = select.options[select.selectedIndex];
    if (opt && opt.dataset) {
        document.getElementById('userRequestedSection').value = opt.dataset.section || '';
        document.getElementById('userRequestedPlotX').value = opt.dataset.x || '';
        document.getElementById('userRequestedPlotY').value = opt.dataset.y || '';

        var val = select.value;
        var uCard = document.getElementById('userSelectedPlotCard');
        if (uCard) {
            if (val && val.indexOf('|') !== -1) {
                var parts = val.split('|');
                var lawnType = opt.dataset.lawnType || 'Lawn (Regular)';
                document.getElementById('userSelectedPlotTitle').innerHTML = 'Section ' + parts[0] + ' &bull; Plot ' + parts[1] + '-' + parts[2];
                document.getElementById('userSelectedPlotDetails').innerHTML = lawnType;
                uCard.style.display = 'block';
            } else {
                uCard.style.display = 'none';
            }
        }
    }
}

function handleAdminDestChange(select) {
    var val = select.value;
    var card = document.getElementById('adminSelectedPlotCard');
    if (!val || val.indexOf('|') === -1) {
        if (card) card.style.display = 'none';
        var aSec = document.getElementById('adminRequestedSection');
        if (aSec) aSec.value = '';
        var aX = document.getElementById('adminRequestedPlotX');
        if (aX) aX.value = '';
        var aY = document.getElementById('adminRequestedPlotY');
        if (aY) aY.value = '';
        return;
    }
    var parts = val.split('|');
    var sec = parts[0];
    var px = parts[1];
    var py = parts[2];
    var opt = select.options[select.selectedIndex];
    var lawnType = (opt && opt.dataset && opt.dataset.lawnType) ? opt.dataset.lawnType : 'Lawn (Regular)';

    var secIn = document.getElementById('adminRequestedSection');
    if (secIn) secIn.value = sec;
    var xIn = document.getElementById('adminRequestedPlotX');
    if (xIn) xIn.value = px;
    var yIn = document.getElementById('adminRequestedPlotY');
    if (yIn) yIn.value = py;

    if (card) {
        document.getElementById('adminSelectedPlotTitle').innerHTML = 'Section ' + sec + ' &bull; Plot ' + px + '-' + py;
        document.getElementById('adminSelectedPlotDetails').innerHTML = lawnType + ' &bull; Selected Destination Plot';
        card.style.display = 'block';
    }
}

// Map integration for Plot Transfer
var transferMapModalEl = document.getElementById('transferMapModal');
var transferMapIframe = document.getElementById('transferMapIframe');
var transferSectionSelect = document.getElementById('transferPopupSectionSelect');
window.__transferTarget = 'admin';

function openTransferMap(target, defaultSection) {
    window.__transferTarget = target || 'admin';
    var sec = defaultSection || 'A';
    var decId = '';

    if (target === 'user') {
        var uSecVal = document.getElementById('userRequestedSection') ? document.getElementById('userRequestedSection').value : '';
        if (uSecVal) sec = uSecVal;
        var uDec = document.querySelector('#userTransferRequestForm input[name="deceased_id"]');
        if (uDec) decId = uDec.value;
    } else {
        var aSecVal = document.getElementById('adminRequestedSection') ? document.getElementById('adminRequestedSection').value : '';
        if (aSecVal) sec = aSecVal;
        var aDec = document.getElementById('adminDeceasedSelect');
        if (aDec) decId = aDec.value;
    }
    window.__transferCurrentDeceasedId = decId;

    if (transferSectionSelect) transferSectionSelect.value = sec;
    if (transferMapIframe) {
        transferMapIframe.src = 'sections/popup.php?selection_mode=transfer&transfer_mode=1&section=' + encodeURIComponent(sec) + (decId ? '&deceased_id=' + encodeURIComponent(decId) : '') + '&t=' + Date.now();
    }
    if (transferMapModalEl && typeof bootstrap !== 'undefined') {
        var modal = bootstrap.Modal.getOrCreateInstance(transferMapModalEl);
        modal.show();
    }
}

if (transferSectionSelect) {
    transferSectionSelect.addEventListener('change', function() {
        var sec = this.value;
        var decId = window.__transferCurrentDeceasedId || '';
        if (transferMapIframe) {
            transferMapIframe.src = 'sections/popup.php?selection_mode=transfer&transfer_mode=1&section=' + encodeURIComponent(sec) + (decId ? '&deceased_id=' + encodeURIComponent(decId) : '') + '&t=' + Date.now();
        }
    });
}

// Event listeners for admin and user map trigger buttons
document.addEventListener('DOMContentLoaded', function() {
    var btnAdminOpen = document.getElementById('btnAdminOpenTransferMap');
    if (btnAdminOpen) {
        btnAdminOpen.addEventListener('click', function() {
            openTransferMap('admin');
        });
    }
    var btnAdminChange = document.getElementById('btnAdminChangePlotOnMap');
    if (btnAdminChange) {
        btnAdminChange.addEventListener('click', function() {
            openTransferMap('admin');
        });
    }
    var btnAdminClear = document.getElementById('btnAdminClearPlot');
    if (btnAdminClear) {
        btnAdminClear.addEventListener('click', function() {
            var sel = document.getElementById('adminDestPlotSelect');
            if (sel) sel.value = '';
            var secIn = document.getElementById('adminRequestedSection');
            if (secIn) secIn.value = '';
            var xIn = document.getElementById('adminRequestedPlotX');
            if (xIn) xIn.value = '';
            var yIn = document.getElementById('adminRequestedPlotY');
            if (yIn) yIn.value = '';
            var card = document.getElementById('adminSelectedPlotCard');
            if (card) card.style.display = 'none';
            var noPlotBox = document.getElementById('adminNoPlotChosenContainer');
            if (noPlotBox) noPlotBox.style.display = 'block';
        });
    }

    var btnUserOpen = document.getElementById('btnUserOpenTransferMap');
    if (btnUserOpen) {
        btnUserOpen.addEventListener('click', function() {
            openTransferMap('user');
        });
    }
    var btnUserChange = document.getElementById('btnUserChangePlotOnMap');
    if (btnUserChange) {
        btnUserChange.addEventListener('click', function() {
            openTransferMap('user');
        });
    }
    var btnUserClear = document.getElementById('btnUserClearPlot');
    if (btnUserClear) {
        btnUserClear.addEventListener('click', function() {
            var sel = document.getElementById('userDestinationPlot');
            if (sel) sel.value = '';
            var secIn = document.getElementById('userRequestedSection');
            if (secIn) secIn.value = '';
            var xIn = document.getElementById('userRequestedPlotX');
            if (xIn) xIn.value = '';
            var yIn = document.getElementById('userRequestedPlotY');
            if (yIn) yIn.value = '';
            var card = document.getElementById('userSelectedPlotCard');
            if (card) card.style.display = 'none';
            var noPlotBox = document.getElementById('userNoPlotChosenContainer');
            if (noPlotBox) noPlotBox.style.display = 'block';
        });
    }

    // Form submission validation: Ensure plot was selected from map
    var adminForm = document.getElementById('adminDirectTransferForm');
    if (adminForm) {
        adminForm.addEventListener('submit', function(e) {
            var sec = document.getElementById('adminRequestedSection') ? document.getElementById('adminRequestedSection').value : '';
            var dest = document.getElementById('adminDestPlotSelect') ? document.getElementById('adminDestPlotSelect').value : '';
            if (!sec && !dest) {
                e.preventDefault();
                alert('Please choose an available destination plot from the cemetery map before submitting.');
                openTransferMap('admin');
                return false;
            }
        });
    }

    var userForm = document.getElementById('userTransferForm') || (document.getElementById('requestModal') ? document.getElementById('requestModal').querySelector('form') : null);
    if (userForm) {
        userForm.addEventListener('submit', function(e) {
            var sec = document.getElementById('userRequestedSection') ? document.getElementById('userRequestedSection').value : '';
            var dest = document.getElementById('userDestinationPlot') ? document.getElementById('userDestinationPlot').value : '';
            if (!sec && !dest) {
                e.preventDefault();
                alert('Please choose an available destination plot from the cemetery map before submitting.');
                openTransferMap('user');
                return false;
            }
        });
    }
});

// Function called when plot is selected from the map
window.applySelectedPlotToTransfer = function(data) {
    if (!data) return;
    var sec = (data.section || '').toUpperCase();
    var px = parseInt(data.plotX || data.plot_x, 10);
    var py = parseInt(data.plotY || data.plot_y, 10);
    if (!sec || isNaN(px) || isNaN(py) || px <= 0 || py <= 0) return;

    var st = (data.status || '').toUpperCase();
    var isAuth = (data.isAuthorized === true || data.is_user_authorized === true);
    if (st && st !== 'AVAILABLE' && !isAuth) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'error',
                title: 'Plot Not Allowed for Transfer',
                text: 'You cannot select this plot. Plot transfer is only allowed to available (vacant) plots or plots owned by the registered customer account.',
                confirmButtonColor: '#7b1113'
            });
        } else {
            alert('You cannot select this plot. Plot transfer is only allowed to available (vacant) plots or plots owned by the registered customer account.');
        }
        return;
    }

    var lawnType = data.lawnType || data.lawn_type || 'Lawn (Regular)';
    var val = sec + '|' + px + '|' + py;

    if (window.__transferTarget === 'user') {
        var userSelect = document.getElementById('userDestinationPlot');
        if (userSelect) {
            userSelect.value = val;
        }
        var uSec = document.getElementById('userRequestedSection');
        if (uSec) uSec.value = sec;
        var uX = document.getElementById('userRequestedPlotX');
        if (uX) uX.value = px;
        var uY = document.getElementById('userRequestedPlotY');
        if (uY) uY.value = py;

        var uPlaceholder = document.getElementById('userNoPlotChosenContainer');
        if (uPlaceholder) uPlaceholder.style.display = 'none';

        var uCard = document.getElementById('userSelectedPlotCard');
        if (uCard) {
            document.getElementById('userSelectedPlotTitle').innerHTML = 'Section ' + sec + ' &bull; Plot ' + px + '-' + py;
            document.getElementById('userSelectedPlotDetails').innerHTML = lawnType + ' &bull; Selected from Cemetery Map';
            uCard.style.display = 'block';
        }
    } else {
        // Admin context
        var adminSelect = document.getElementById('adminDestPlotSelect');
        if (adminSelect) {
            adminSelect.value = val;
        }
        var aSec = document.getElementById('adminRequestedSection');
        if (aSec) aSec.value = sec;
        var aX = document.getElementById('adminRequestedPlotX');
        if (aX) aX.value = px;
        var aY = document.getElementById('adminRequestedPlotY');
        if (aY) aY.value = py;

        var aPlaceholder = document.getElementById('adminNoPlotChosenContainer');
        if (aPlaceholder) aPlaceholder.style.display = 'none';

        var aCard = document.getElementById('adminSelectedPlotCard');
        if (aCard) {
            document.getElementById('adminSelectedPlotTitle').innerHTML = 'Section ' + sec + ' &bull; Plot ' + px + '-' + py;
            document.getElementById('adminSelectedPlotDetails').innerHTML = lawnType + ' &bull; Selected from Cemetery Map';
            aCard.style.display = 'block';
        }
    }

    // Hide the map modal cleanly
    if (transferMapModalEl && typeof bootstrap !== 'undefined') {
        var modalInst = bootstrap.Modal.getInstance(transferMapModalEl);
        if (modalInst) modalInst.hide();
    }
};

window.addEventListener('message', function(event) {
    var d = event.data;
    if (!d) return;
    if (d.type === 'transferPlotSelected' || d.type === 'bookingPlotSelected' || d.action === 'bookPlot') {
        window.applySelectedPlotToTransfer(d);
    }
});

function updateAdminTransferPreview(select) {
    var opt = select.options[select.selectedIndex];
    var infoCard = document.getElementById('adminInfoCard');
    var eligCard = document.getElementById('adminEligibilityCard');
    var submitBtn = document.getElementById('adminSubmitTransferBtn');
    var notice = document.getElementById('adminSubmitNotice');

    if (!opt || !opt.value) {
        infoCard.style.display = 'none';
        eligCard.style.display = 'none';
        submitBtn.disabled = true;
        notice.textContent = 'Please select a deceased record above to verify eligibility.';
        notice.className = 'text-muted small text-center mt-2';
        return;
    }

    // Populate Info Card
    document.getElementById('previewAdminCustomer').textContent = opt.dataset.customer || '—';
    document.getElementById('previewAdminPlot').textContent = 'Section ' + (opt.dataset.section || '—') + ' Plot ' + (opt.dataset.plotX || '—') + '-' + (opt.dataset.plotY || '—');
    document.getElementById('previewAdminDeceased').textContent = opt.dataset.name || '—';
    var yrNum = parseFloat(opt.dataset.years || '0');
    document.getElementById('previewAdminYears').textContent = (opt.dataset.years || '0') + (yrNum === 1 ? ' year' : ' years');
    infoCard.style.display = 'block';

    var canTransfer = opt.dataset.canTransfer === '1';
    var status = opt.dataset.status || '';
    var message = opt.dataset.message || '';

    if (canTransfer) {
        eligCard.innerHTML = `
            <div class="p-3 rounded-3 border" style="background-color: #f0fdf4; border-color: #bbf7d0 !important;">
                <div class="d-flex align-items-center gap-3">
                    <div class="text-success" style="font-size: 1.75rem;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-success" style="font-size: 1rem;">
                            ELIGIBLE FOR TRANSFER (8 years completed)
                        </div>
                        <div class="text-muted small mt-1">
                            ${message}
                        </div>
                    </div>
                </div>
            </div>
        `;
        eligCard.style.display = 'block';
        submitBtn.disabled = false;
        notice.textContent = 'Record is verified eligible for remains transfer.';
        notice.className = 'text-success small text-center mt-2';
    } else {
        eligCard.innerHTML = `
            <div class="p-3 rounded-3 border" style="background-color: #fffbeb; border-color: #fde68a !important;">
                <div class="d-flex align-items-center gap-3">
                    <div class="text-warning" style="font-size: 1.75rem;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-danger" style="font-size: 1rem;">
                            NOT YET ELIGIBLE
                        </div>
                        <div class="text-muted small mt-1">
                            ${message}
                        </div>
                    </div>
                </div>
            </div>
        `;
        eligCard.style.display = 'block';
        submitBtn.disabled = true;
        notice.textContent = 'Transfer submission is blocked until the 8-year minimum interval is fulfilled.';
        notice.className = 'text-danger small text-center mt-2';
    }
}

// Transfer Requests Table Live Search & Filter
function filterTransfersTable() {
    var q = (document.getElementById('transfersSearchInput')?.value || '').toLowerCase().trim();
    var stat = (document.getElementById('transfersStatusFilter')?.value || '').toLowerCase().trim();

    var activeDot = document.getElementById('transfersFilterActiveDot');
    if (activeDot) activeDot.style.display = (stat !== '') ? 'inline-block' : 'none';

    var clearBtn = document.getElementById('transfersClearBtn');
    var resetBtn = document.getElementById('transfersResetBtn');
    var isFiltered = (q !== '' || stat !== '');
    if (clearBtn) clearBtn.style.display = (q !== '') ? 'inline-block' : 'none';
    if (resetBtn) resetBtn.style.display = isFiltered ? 'inline-block' : 'none';

    var pane = document.getElementById('applications-pane');
    if (!pane) return;

    var rows = pane.querySelectorAll('tbody tr.transfer-request-row');
    var visible = 0;
    var total = rows.length;

    rows.forEach(function(row) {
        var text = (row.textContent || '').toLowerCase();
        var rowStat = (row.getAttribute('data-status') || '').toLowerCase();

        var matchesQ = (q === '' || text.indexOf(q) !== -1);
        var matchesStat = (stat === '' || rowStat === stat);

        if (matchesQ && matchesStat) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    var countBadge = document.getElementById('transfersFilterCount');
    if (countBadge) {
        if (isFiltered) {
            countBadge.innerHTML = '<i class="fas fa-filter text-primary me-1"></i>Showing <strong>' + visible + '</strong> of ' + total;
            countBadge.className = 'badge bg-primary text-white border-0 px-2 py-1 small';
        } else {
            countBadge.innerHTML = 'Total: <strong>' + total + '</strong>';
            countBadge.className = 'badge bg-white text-dark border px-2 py-1 small';
        }
    }
}

function clearTransfersSearch() {
    var inp = document.getElementById('transfersSearchInput');
    if (inp) {
        inp.value = '';
        filterTransfersTable();
        inp.focus();
    }
}

function resetTransfersFilter() {
    var inp = document.getElementById('transfersSearchInput');
    var sel = document.getElementById('transfersStatusFilter');
    if (inp) inp.value = '';
    if (sel) sel.value = '';
    filterTransfersTable();
}

document.addEventListener('DOMContentLoaded', function() {
    var inp = document.getElementById('transfersSearchInput');
    var sel = document.getElementById('transfersStatusFilter');
    if (inp) inp.addEventListener('input', filterTransfersTable);
    if (sel) sel.addEventListener('change', filterTransfersTable);
});
</script>
<?php if (function_exists('render_notification_containers')) echo render_notification_containers(); ?>
</body>
</html>

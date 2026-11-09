<?php

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}
require_once __DIR__ . '/connection.php';
if (file_exists(__DIR__ . '/includes/notification_helper.php')) {
    require_once __DIR__ . '/includes/notification_helper.php';
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$role = strtoupper(trim((string)($_SESSION['role'] ?? '')));

if ($user_id > 0 && $role !== 'ADMIN' && isset($conn) && function_exists('is_db_connected') && is_db_connected()) {
    $rCheck = $conn->prepare("SELECT role FROM tbl_user WHERE user_id = ? LIMIT 1");
    if ($rCheck) {
        $rCheck->bind_param('i', $user_id);
        $rCheck->execute();
        $rRes = $rCheck->get_result()->fetch_assoc();
        $rCheck->close();
        if ($rRes && strtoupper(trim((string)$rRes['role'])) === 'ADMIN') {
            $_SESSION['role'] = 'ADMIN';
            $role = 'ADMIN';
        }
    }
}

if ($user_id <= 0 || $role !== 'ADMIN') {
    if ($isPost) {
        ini_set('display_errors', '0');
        error_reporting(0);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'session_expired' => true,
            'message' => 'Your administrator session has ended or expired (signed in as customer in another tab). Please sign in to the Administrator Portal.'
        ]);
        exit;
    }
    header('Location: admin_login.php');
    exit;
}

$admin_id = $user_id;
ensure_user_plot_ownership_table($conn);

if ($isPost) {
    ini_set('display_errors', '0');
    error_reporting(0);
    header('Content-Type: application/json; charset=utf-8');
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === '') {
        echo json_encode(['success' => false, 'message' => 'No action specified in request.']);
        exit;
    }

    if ($action === 'approve_plot_claim') {
        $claim_id = (int)($_POST['claim_id'] ?? 0);
        $admin_notes = trim((string)($_POST['admin_notes'] ?? 'Approved by Administrator'));

        if ($claim_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
            exit;
        }

        $stmt = $conn->prepare("SELECT upo.*, u.first_name, u.last_name FROM user_plot_ownership upo JOIN tbl_user u ON upo.user_id = u.user_id WHERE upo.id = ? LIMIT 1");
        $stmt->bind_param('i', $claim_id);
        $stmt->execute();
        $claim = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$claim) {
            echo json_encode(['success' => false, 'message' => 'Plot claim not found']);
            exit;
        }

        $sec = strtoupper(trim($claim['section']));
        $px = (int)$claim['plot_x'];
        $py = (int)$claim['plot_y'];
        $cUserId = (int)$claim['user_id'];
        $ownerName = trim($claim['first_name'] . ' ' . $claim['last_name']);

        $conn->begin_transaction();
        try {

            $upStmt = $conn->prepare("UPDATE user_plot_ownership SET verification_status = 'VERIFIED', admin_notes = ?, verified_by = ?, verified_at = NOW() WHERE id = ?");
            $upStmt->bind_param('sii', $admin_notes, $admin_id, $claim_id);
            $upStmt->execute();
            $upStmt->close();

            $decCheck = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?");
            $decCheck->bind_param('sii', $sec, $px, $py);
            $decCheck->execute();
            $hasDec = ((int)($decCheck->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
            $decCheck->close();

            $newPlotStatus = $hasDec ? 'occupied' : 'reserved';
            $conn->query("UPDATE tbl_plots SET status = '{$newPlotStatus}' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
            @$conn->query("UPDATE plots SET status = '" . ucfirst($newPlotStatus) . "' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");

            $checkOwnersTbl = $conn->query("SHOW TABLES LIKE 'tbl_plot_owners'");
            if ($checkOwnersTbl && $checkOwnersTbl->num_rows > 0) {
                $plotOwnerStmt = $conn->prepare("INSERT INTO tbl_plot_owners (plot_id, section, plot_x, plot_y, owner_name, active) VALUES (0, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE owner_name = VALUES(owner_name), active = 1");
                if ($plotOwnerStmt) {
                    $plotOwnerStmt->bind_param('siis', $sec, $px, $py, $ownerName);
                    $plotOwnerStmt->execute();
                    $plotOwnerStmt->close();
                }
            }

            $logDetails = "Approved plot claim #{$claim_id} for Section {$sec} Plot {$px}-{$py} by {$ownerName} (User ID #{$cUserId})";
            $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('PLOT_CLAIM_APPROVED', ?, ?, NOW())");
            if ($logStmt) {
                $logStmt->bind_param('si', $logDetails, $admin_id);
                $logStmt->execute();
                $logStmt->close();
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => "Plot Section {$sec} — Plot {$px}-{$py} successfully approved and linked to {$ownerName}."]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Error approving plot claim: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'reject_plot_claim') {
        $claim_id = (int)($_POST['claim_id'] ?? 0);
        $admin_notes = trim((string)($_POST['admin_notes'] ?? 'Rejected by Administrator'));
        $delete_record = (!empty($_POST['delete_record']) && ($_POST['delete_record'] === '1' || $_POST['delete_record'] === 'true'));
        $send_notif = (!isset($_POST['send_notification']) || $_POST['send_notification'] === '1' || $_POST['send_notification'] === 'true');

        if ($claim_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
            exit;
        }

        $claimStmt = $conn->prepare("SELECT upo.*, u.first_name, u.last_name, u.email FROM user_plot_ownership upo JOIN tbl_user u ON upo.user_id = u.user_id WHERE upo.id = ? LIMIT 1");
        $claimStmt->bind_param('i', $claim_id);
        $claimStmt->execute();
        $claim = $claimStmt->get_result()->fetch_assoc();
        $claimStmt->close();

        if (!$claim) {
            echo json_encode(['success' => false, 'message' => 'Plot claim not found.']);
            exit;
        }

        $cUserId = (int)$claim['user_id'];
        $sec = strtoupper(trim($claim['section']));
        $px = (int)$claim['plot_x'];
        $py = (int)$claim['plot_y'];
        $ownerName = trim($claim['first_name'] . ' ' . $claim['last_name']);

        if ($send_notif && function_exists('notify_plot_claim_rejected')) {
            notify_plot_claim_rejected($conn, $cUserId, $sec, $px, $py, $admin_notes, $delete_record);
        } elseif ($send_notif && function_exists('create_system_notification')) {
            $notifTitle = "Plot Claim Rejected (Section {$sec} Plot {$px}-{$py})";
            $notifMsg = "Your claim for Section {$sec} Plot {$px}-{$py} was rejected. Reason: {$admin_notes}." . ($delete_record ? " The record was removed from your account." : "");
            create_system_notification($conn, $cUserId, null, 'plot_claim_rejected', $notifTitle, $notifMsg, 'user_dashboard.php#myPlotsSection');
        }

        if ($delete_record) {
            $delStmt = $conn->prepare("DELETE FROM user_plot_ownership WHERE id = ?");
            if ($delStmt) {
                $delStmt->bind_param('i', $claim_id);
                $delStmt->execute();
                $delStmt->close();

                $logDetails = "Rejected and deleted plot claim #{$claim_id} for Section {$sec} Plot {$px}-{$py} (User: {$ownerName} #{$cUserId}). Reason: {$admin_notes}";
                $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('PLOT_CLAIM_DELETED', ?, ?, NOW())");
                if ($logStmt) {
                    $logStmt->bind_param('si', $logDetails, $admin_id);
                    $logStmt->execute();
                    $logStmt->close();
                }

                echo json_encode(['success' => true, 'message' => "Plot claim for Section {$sec} Plot {$px}-{$py} was rejected and permanently deleted. User has been notified."]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error deleting claim: ' . $conn->error]);
            }
        } else {
            $upStmt = $conn->prepare("UPDATE user_plot_ownership SET verification_status = 'REJECTED', admin_notes = ?, verified_by = ?, verified_at = NOW() WHERE id = ?");
            if ($upStmt) {
                $upStmt->bind_param('sii', $admin_notes, $admin_id, $claim_id);
                $upStmt->execute();
                $upStmt->close();

                $logDetails = "Rejected plot claim #{$claim_id} for Section {$sec} Plot {$px}-{$py} (User: {$ownerName} #{$cUserId}). Reason: {$admin_notes}";
                $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('PLOT_CLAIM_REJECTED', ?, ?, NOW())");
                if ($logStmt) {
                    $logStmt->bind_param('si', $logDetails, $admin_id);
                    $logStmt->execute();
                    $logStmt->close();
                }

                echo json_encode(['success' => true, 'message' => "Plot claim for Section {$sec} Plot {$px}-{$py} was marked as rejected. User has been notified."]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            }
        }
        exit;
    }

    if ($action === 'delete_plot_claim') {
        $claim_id = (int)($_POST['claim_id'] ?? 0);
        $notify_user = (!empty($_POST['notify_user']) && ($_POST['notify_user'] === '1' || $_POST['notify_user'] === 'true'));
        $reason = trim((string)($_POST['reason'] ?? 'Claim record was removed by administrator.'));

        if ($claim_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
            exit;
        }

        $claimStmt = $conn->prepare("SELECT upo.*, u.first_name, u.last_name, u.email FROM user_plot_ownership upo JOIN tbl_user u ON upo.user_id = u.user_id WHERE upo.id = ? LIMIT 1");
        $claimStmt->bind_param('i', $claim_id);
        $claimStmt->execute();
        $claim = $claimStmt->get_result()->fetch_assoc();
        $claimStmt->close();

        if (!$claim) {
            echo json_encode(['success' => false, 'message' => 'Plot claim not found.']);
            exit;
        }

        $cUserId = (int)$claim['user_id'];
        $sec = strtoupper(trim($claim['section']));
        $px = (int)$claim['plot_x'];
        $py = (int)$claim['plot_y'];
        $ownerName = trim($claim['first_name'] . ' ' . $claim['last_name']);

        if ($notify_user && function_exists('notify_plot_claim_rejected')) {
            notify_plot_claim_rejected($conn, $cUserId, $sec, $px, $py, $reason, true);
        }

        $delStmt = $conn->prepare("DELETE FROM user_plot_ownership WHERE id = ?");
        if ($delStmt) {
            $delStmt->bind_param('i', $claim_id);
            $delStmt->execute();
            $delStmt->close();

            $logDetails = "Deleted plot claim #{$claim_id} for Section {$sec} Plot {$px}-{$py} (User: {$ownerName} #{$cUserId})";
            $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('PLOT_CLAIM_DELETED', ?, ?, NOW())");
            if ($logStmt) {
                $logStmt->bind_param('si', $logDetails, $admin_id);
                $logStmt->execute();
                $logStmt->close();
            }

            echo json_encode(['success' => true, 'message' => "Plot record for Section {$sec} Plot {$px}-{$py} has been deleted."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        exit;
    }

    if ($action === 'manual_link_plot') {
        $target_user_id = (int)($_POST['user_id'] ?? 0);
        $sec = strtoupper(trim((string)($_POST['section'] ?? '')));
        $raw_plot = trim((string)($_POST['plot_number'] ?? ''));
        $own_status = in_array(strtoupper(trim($_POST['ownership_status'] ?? '')), ['OWNED', 'RESERVED']) ? strtoupper(trim($_POST['ownership_status'])) : 'OWNED';
        $notes = trim((string)($_POST['notes'] ?? 'Manually linked by Administrator'));

        if ($target_user_id <= 0 || !in_array($sec, ['A', 'B', 'C', 'D', 'E', 'F'])) {
            echo json_encode(['success' => false, 'message' => 'Please select a valid user and section (A-F).']);
            exit;
        }

        $px = 0; $py = 0;
        if (preg_match('/^(\d+)[\s\-_,]+(\d+)$/', $raw_plot, $m)) {
            $px = (int)$m[1]; $py = (int)$m[2];
        } elseif (is_numeric($raw_plot)) {
            $px = (int)$raw_plot; $py = 1;
        }

        if ($px <= 0 || $py <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid plot number format.']);
            exit;
        }

        $chkOwn = $conn->prepare("SELECT id, verification_status FROM user_plot_ownership WHERE user_id = ? AND UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
        $chkOwn->bind_param('isii', $target_user_id, $sec, $px, $py);
        $chkOwn->execute();
        $ownRes = $chkOwn->get_result();
        if ($ownRes && ($ownRow = $ownRes->fetch_assoc())) {
            $existingId = (int)$ownRow['id'];
            $chkOwn->close();

            $upOwn = $conn->prepare("UPDATE user_plot_ownership SET ownership_status = ?, verification_status = 'VERIFIED', admin_notes = ?, verified_by = ?, verified_at = NOW() WHERE id = ?");
            $upOwn->bind_param('ssii', $own_status, $notes, $admin_id, $existingId);
            $upOwn->execute();
            $upOwn->close();

            $decCheck = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
            $hasDec = $decCheck && ((int)($decCheck->fetch_assoc()['c'] ?? 0)) > 0;
            $pStatus = $hasDec ? 'occupied' : 'reserved';
            $conn->query("UPDATE tbl_plots SET status = '{$pStatus}' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
            @$conn->query("UPDATE plots SET status = '" . ucfirst($pStatus) . "' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");

            echo json_encode(['success' => true, 'message' => "Plot Section {$sec} — Plot {$px}-{$py} was already linked to this account and is now verified."]);
            exit;
        }
        $chkOwn->close();

        $insStmt = $conn->prepare("INSERT INTO user_plot_ownership (user_id, section, plot_x, plot_y, ownership_status, verification_status, claim_source, notes, admin_notes, verified_by, verified_at) 
            VALUES (?, ?, ?, ?, ?, 'VERIFIED', 'ADMIN_MANUAL', ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE ownership_status = VALUES(ownership_status), verification_status = 'VERIFIED', admin_notes = VALUES(admin_notes), verified_by = VALUES(verified_by), verified_at = NOW()");
        if ($insStmt) {
            $insStmt->bind_param('isiisssi', $target_user_id, $sec, $px, $py, $own_status, $notes, $notes, $admin_id);
            if ($insStmt->execute()) {
                $insStmt->close();

                $decCheck = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
                $hasDec = $decCheck && ((int)($decCheck->fetch_assoc()['c'] ?? 0)) > 0;
                $pStatus = $hasDec ? 'occupied' : 'reserved';
                $conn->query("UPDATE tbl_plots SET status = '{$pStatus}' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
                @$conn->query("UPDATE plots SET status = '" . ucfirst($pStatus) . "' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");

                $uQ = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = {$target_user_id} LIMIT 1");
                $uRow = $uQ ? $uQ->fetch_assoc() : null;
                $targetName = $uRow ? trim($uRow['first_name'] . ' ' . $uRow['last_name']) : "User #{$target_user_id}";

                $checkOwnersTbl = $conn->query("SHOW TABLES LIKE 'tbl_plot_owners'");
                if ($checkOwnersTbl && $checkOwnersTbl->num_rows > 0) {
                    $plotOwnerStmt = $conn->prepare("INSERT INTO tbl_plot_owners (plot_id, section, plot_x, plot_y, owner_name, active) VALUES (0, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE owner_name = VALUES(owner_name), active = 1");
                    if ($plotOwnerStmt) {
                        $plotOwnerStmt->bind_param('siis', $sec, $px, $py, $targetName);
                        $plotOwnerStmt->execute();
                        $plotOwnerStmt->close();
                    }
                }

                $logDetails = "Manually linked plot Section {$sec} Plot {$px}-{$py} to {$targetName} (User ID #{$target_user_id})";
                $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('PLOT_MANUALLY_LINKED', ?, ?, NOW())");
                if ($logStmt) {
                    $logStmt->bind_param('si', $logDetails, $admin_id);
                    $logStmt->execute();
                    $logStmt->close();
                }

                echo json_encode(['success' => true, 'message' => "Plot Section {$sec} — Plot {$px}-{$py} manually linked to {$targetName} successfully."]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        }
        exit;
    }

    if ($action === 'unlink_plot') {
        $claim_id = (int)($_POST['claim_id'] ?? 0);
        if ($claim_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
            exit;
        }

        $claimQ = $conn->query("SELECT * FROM user_plot_ownership WHERE id = {$claim_id} LIMIT 1");
        $claim = $claimQ ? $claimQ->fetch_assoc() : null;

        if (!$claim) {
            echo json_encode(['success' => false, 'message' => 'Ownership record not found.']);
            exit;
        }

        $sec = strtoupper(trim($claim['section']));
        $px = (int)$claim['plot_x'];
        $py = (int)$claim['plot_y'];

        $conn->query("DELETE FROM user_plot_ownership WHERE id = {$claim_id}");

        $chkOther = $conn->query("SELECT COUNT(*) AS c FROM user_plot_ownership WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py} AND verification_status = 'VERIFIED'");
        $hasOther = $chkOther && ((int)($chkOther->fetch_assoc()['c'] ?? 0)) > 0;

        $chkDec = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
        $hasDec = $chkDec && ((int)($chkDec->fetch_assoc()['c'] ?? 0)) > 0;

        if (!$hasOther && !$hasDec) {
            $conn->query("UPDATE tbl_plots SET status = 'available' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
            @$conn->query("UPDATE plots SET status = 'Available' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
        }

        $logDetails = "Unlinked plot Section {$sec} Plot {$px}-{$py} (Claim #{$claim_id})";
        $conn->query("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('PLOT_UNLINKED', '{$conn->real_escape_string($logDetails)}', {$admin_id}, NOW())");

        echo json_encode(['success' => true, 'message' => "Plot Section {$sec} — Plot {$px}-{$py} has been unlinked."]);
        exit;
    }

    if ($action === 'admin_add_deceased_to_plot') {
        $owner_user_id = (int)($_POST['user_id'] ?? 0);
        $sec = strtoupper(trim($_POST['section'] ?? ''));
        $px = (int)($_POST['plot_x'] ?? 0);
        $py = (int)($_POST['plot_y'] ?? 0);

        $first_name = strtoupper(trim($_POST['first_name'] ?? ''));
        $middle_name = strtoupper(trim($_POST['middle_name'] ?? ''));
        $last_name = strtoupper(trim($_POST['last_name'] ?? ''));
        $suffix = strtoupper(trim($_POST['suffix'] ?? ''));
        $bday = trim($_POST['bday'] ?? '');
        $date_died = trim($_POST['date_died'] ?? '');
        $interment_date = trim($_POST['interment_date'] ?? $date_died);
        $relationship = strtoupper(trim($_POST['relationship'] ?? ''));

        if ($sec === '' || $px <= 0 || $py <= 0 || $first_name === '' || $last_name === '' || $date_died === '') {
            echo json_encode(['success' => false, 'message' => 'Please provide section, plot, and required deceased details (first name, last name, date died).']);
            exit;
        }

        $cntCheck = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
        $cntRow = $cntCheck ? $cntCheck->fetch_assoc() : ['c' => 0];
        if (intval($cntRow['c'] ?? 0) >= 4) {
            echo json_encode(['success' => false, 'message' => 'This plot has reached the maximum capacity of 4 occupants. No additional deceased can be added.']);
            exit;
        }

        $intervalCheck = check_plot_interment_interval_eligibility($conn, $sec, $px, $py, $date_died ?: $interment_date);
        if (!$intervalCheck['is_eligible']) {
            echo json_encode(['success' => false, 'message' => $intervalCheck['message']]);
            exit;
        }

        $decIns = $conn->prepare("INSERT INTO tbl_deceased (first_name, middle_name, last_name, suffix, bday, date_died, interment_date, section, plot_x, plot_y, user_id, relationship_to_account_holder, added_by, edited_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($decIns) {
            $adminName = 'Administrator #' . $admin_id;
            $decIns->bind_param('ssssssssiiisss', $first_name, $middle_name, $last_name, $suffix, $bday, $date_died, $interment_date, $sec, $px, $py, $owner_user_id, $relationship, $adminName, $adminName);
            if ($decIns->execute()) {
                $decIns->close();

                $conn->query("UPDATE tbl_plots SET status = 'occupied' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
                @$conn->query("UPDATE plots SET status = 'Occupied' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");

                $decFullName = trim(implode(' ', array_filter([$first_name, $middle_name, $last_name, $suffix])));
                $conn->query("UPDATE user_plot_ownership SET deceased_reference = '{$conn->real_escape_string($decFullName)}' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");

                $logDetails = "Added deceased {$decFullName} to Section {$sec} Plot {$px}-{$py} for User #{$owner_user_id}";
                $conn->query("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('DECEASED_ASSIGNED', '{$conn->real_escape_string($logDetails)}', {$admin_id}, NOW())");

                echo json_encode(['success' => true, 'message' => "Deceased record for {$decFullName} assigned to Section {$sec} Plot {$px}-{$py} successfully."]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $conn->error]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

$pending_claims = [];
$owner_directory = [];
$all_ownership_records = [];
$all_users = [];

$active_tab = isset($_GET['tab']) ? strtolower(trim($_GET['tab'])) : 'pending';
if (!in_array($active_tab, ['pending', 'owners', 'all'], true)) {
    $active_tab = 'pending';
}

try {

    @$conn->query("DELETE upo FROM user_plot_ownership upo JOIN tbl_user u ON upo.user_id = u.user_id WHERE UPPER(TRIM(u.role)) = 'ADMIN'");

    $pSql = "SELECT upo.*, u.first_name, u.last_name, u.email, COALESCE(u.phone_no, '') AS contact_no 
             FROM user_plot_ownership upo 
             JOIN tbl_user u ON upo.user_id = u.user_id 
             WHERE upo.verification_status = 'PENDING' AND UPPER(TRIM(u.role)) != 'ADMIN'
             ORDER BY upo.created_at DESC";
    $pRes = $conn->query($pSql);
    if ($pRes) {
        while ($pRow = $pRes->fetch_assoc()) {
            $cSec = strtoupper(trim($pRow['section']));
            $cPx = (int)$pRow['plot_x'];
            $cPy = (int)$pRow['plot_y'];
            $cUid = (int)$pRow['user_id'];
            $confQ = $conn->query("SELECT u.first_name, u.last_name FROM user_plot_ownership upo JOIN tbl_user u ON upo.user_id = u.user_id WHERE upo.section = '{$cSec}' AND upo.plot_x = {$cPx} AND upo.plot_y = {$cPy} AND upo.verification_status = 'VERIFIED' AND upo.user_id != {$cUid} LIMIT 1");
            $pRow['has_conflict'] = ($confQ && $confQ->num_rows > 0);
            $pRow['conflict_owner'] = $pRow['has_conflict'] ? $confQ->fetch_assoc() : null;
            $pending_claims[] = $pRow;
        }
    }

    $oSql = "SELECT u.user_id, u.first_name, u.last_name, u.email, COALESCE(u.phone_no, '') AS contact_no,
                    upo.id AS ownership_id, upo.section, upo.plot_x, upo.plot_y, upo.ownership_status, upo.created_at,
                    (SELECT COUNT(*) FROM tbl_deceased d WHERE UPPER(TRIM(d.section)) = UPPER(TRIM(upo.section)) AND d.plot_x = upo.plot_x AND d.plot_y = upo.plot_y) AS deceased_count,
                    (SELECT TRIM(CONCAT_WS(' ', NULLIF(d.first_name,''), NULLIF(d.middle_name,''), NULLIF(d.last_name,''), NULLIF(d.suffix,''))) FROM tbl_deceased d WHERE UPPER(TRIM(d.section)) = UPPER(TRIM(upo.section)) AND d.plot_x = upo.plot_x AND d.plot_y = upo.plot_y LIMIT 1) AS deceased_name
             FROM user_plot_ownership upo
             JOIN tbl_user u ON upo.user_id = u.user_id
             WHERE upo.verification_status = 'VERIFIED' AND UPPER(TRIM(u.role)) != 'ADMIN'
             ORDER BY u.last_name ASC, u.first_name ASC, upo.section ASC, upo.plot_x ASC, upo.plot_y ASC";
    $oRes = $conn->query($oSql);
    if ($oRes) {
        while ($oRow = $oRes->fetch_assoc()) {
            $uid = (int)$oRow['user_id'];
            if (!isset($owner_directory[$uid])) {
                $owner_directory[$uid] = [
                    'user_id' => $uid,
                    'name' => trim($oRow['first_name'] . ' ' . $oRow['last_name']),
                    'email' => $oRow['email'],
                    'contact_no' => $oRow['contact_no'],
                    'plots' => []
                ];
            }
            $owner_directory[$uid]['plots'][] = [
                'ownership_id' => $oRow['ownership_id'],
                'section' => $oRow['section'],
                'plot_x' => $oRow['plot_x'],
                'plot_y' => $oRow['plot_y'],
                'ownership_status' => $oRow['ownership_status'],
                'has_deceased' => ((int)$oRow['deceased_count'] > 0),
                'deceased_name' => trim((string)($oRow['deceased_name'] ?? ''))
            ];
        }
    }

    $allSql = "SELECT upo.*, u.first_name, u.last_name, u.email, COALESCE(u.phone_no, '') AS contact_no,
                      (SELECT COUNT(*) FROM tbl_deceased d WHERE UPPER(TRIM(d.section)) = UPPER(TRIM(upo.section)) AND d.plot_x = upo.plot_x AND d.plot_y = upo.plot_y) AS deceased_count,
                      (SELECT TRIM(CONCAT_WS(' ', NULLIF(d.first_name,''), NULLIF(d.middle_name,''), NULLIF(d.last_name,''), NULLIF(d.suffix,''))) FROM tbl_deceased d WHERE UPPER(TRIM(d.section)) = UPPER(TRIM(upo.section)) AND d.plot_x = upo.plot_x AND d.plot_y = upo.plot_y LIMIT 1) AS deceased_name
               FROM user_plot_ownership upo
               JOIN tbl_user u ON upo.user_id = u.user_id
               WHERE UPPER(TRIM(u.role)) != 'ADMIN'
               ORDER BY upo.created_at DESC, upo.id DESC";
    $allRes = $conn->query($allSql);
    if ($allRes) {
        while ($aRow = $allRes->fetch_assoc()) {
            $all_ownership_records[] = $aRow;
        }
    }

    $uRes = $conn->query("SELECT user_id, first_name, last_name, email FROM tbl_user WHERE role != 'ADMIN' ORDER BY last_name, first_name LIMIT 200");
    if ($uRes) {
        while ($uRow = $uRes->fetch_assoc()) {
            $all_users[] = $uRow;
        }
    }
} catch (Throwable $t) {
    error_log('Claims review query error: ' . $t->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plot Ownership & Claims Review - Garden of Remembrance</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="includes/admin_header.css">
    <style>
        :root {
            --brand-primary: #7b1113;
            --brand-primary-hover: #921719;
            --brand-subtle: #fdf2f2;
        }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            min-height: 100vh;
        }
        .claims-card {
            border: 1px solid #e2e8f0;
            border-radius: 1.25rem;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
            background: #ffffff;
            transition: box-shadow 0.2s ease;
        }
        .claims-card:hover {
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        }
        .icon-badge {
            background: var(--brand-subtle);
            color: var(--brand-primary);
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
        }
        .page-title {
            color: var(--brand-primary);
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .nav-pills .nav-link {
            color: #475569;
            font-weight: 600;
            padding: 0.5rem 1rem;
            border-radius: 0.6rem;
            transition: all 0.15s ease-in-out;
        }
        .nav-pills .nav-link.active {
            background-color: var(--brand-primary);
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(123, 17, 19, 0.25);
        }
        .btn-brand-primary {
            background-color: var(--brand-primary);
            border-color: var(--brand-primary);
            color: #ffffff;
            font-weight: 600;
        }
        .btn-brand-primary:hover, .btn-brand-primary:focus {
            background-color: var(--brand-primary-hover);
            border-color: var(--brand-primary-hover);
            color: #ffffff;
        }
        .table thead th {
            background-color: #f8fafc;
            color: #475569;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 0.75rem 1rem;
            border-bottom: 2px solid #e2e8f0;
        }
        .table tbody td {
            padding: 0.85rem 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }
        .table-all-records {
            min-width: 1200px;
        }
        .badge-plot {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            background: #fdf2f2;
            color: var(--brand-primary);
            border: 1px solid rgba(123, 17, 19, 0.2);
            font-weight: 600;
            font-size: 0.82rem;
            padding: 0.35rem 0.65rem;
            border-radius: 6px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .claims-filter-toolbar {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 0.85rem 1rem;
            margin-bottom: 1.25rem;
        }
        .claims-filter-toolbar .input-group-text {
            background: #ffffff;
            border-color: #cbd5e1;
        }
        .claims-filter-toolbar .form-control,
        .claims-filter-toolbar .form-select {
            border-color: #cbd5e1;
            font-size: 0.875rem;
        }
        .claims-filter-toolbar .form-control:focus,
        .claims-filter-toolbar .form-select:focus {
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 0.2rem rgba(123, 17, 19, 0.15);
        }
        .section-pill {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.2rem 0.65rem;
            transition: all 0.15s ease;
            cursor: pointer;
        }
        .section-pill.active {
            background-color: var(--brand-primary) !important;
            border-color: var(--brand-primary) !important;
            color: #ffffff !important;
        }
    </style>
</head>
<body>

<?php

$active_nav = 'claims';
include __DIR__ . '/includes/admin_header.php';
?>

<main class="container-fluid px-3 px-md-4 py-4" style="max-width: 1400px;">

    <div class="card claims-card" id="plot-claims-section">
        <div class="card-body p-4">

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 border-bottom pb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-badge">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <div>
                        <h4 class="page-title mb-1">
                            Plot Ownership & Claims Review
                        </h4>
                        <div class="text-muted small">Manage multi-plot ownership claims and verified plot holders</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-brand-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#manualLinkPlotModal">
                        <i class="fas fa-plus me-1"></i> Manually Link a Plot
                    </button>
                    <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Dashboard
                    </a>
                </div>
            </div>

            <ul class="nav nav-pills mb-4 gap-2" id="ownershipTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo ($active_tab === 'pending') ? 'active' : ''; ?> btn-sm" id="pending-claims-tab" data-bs-toggle="pill" data-bs-target="#pending-claims" type="button" role="tab" aria-controls="pending-claims" aria-selected="<?php echo ($active_tab === 'pending') ? 'true' : 'false'; ?>">
                        <i class="fas fa-clock me-1"></i> Pending Claims
                        <span class="badge bg-warning text-dark ms-1 rounded-pill" id="pendingCountBadge"><?php echo count($pending_claims); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo ($active_tab === 'owners') ? 'active' : ''; ?> btn-sm" id="owners-directory-tab" data-bs-toggle="pill" data-bs-target="#owners-directory" type="button" role="tab" aria-controls="owners-directory" aria-selected="<?php echo ($active_tab === 'owners') ? 'true' : 'false'; ?>">
                        <i class="fas fa-users me-1"></i> Verified Owners Directory
                        <span class="badge bg-secondary ms-1 rounded-pill" id="directoryCountBadge"><?php echo count($owner_directory); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo ($active_tab === 'all') ? 'active' : ''; ?> btn-sm" id="all-records-tab" data-bs-toggle="pill" data-bs-target="#all-records" type="button" role="tab" aria-controls="all-records" aria-selected="<?php echo ($active_tab === 'all') ? 'true' : 'false'; ?>">
                        <i class="fas fa-list-check me-1"></i> All Plot Ownership Records
                        <span class="badge bg-dark ms-1 rounded-pill" id="allRecordsCountBadge"><?php echo count($all_ownership_records); ?></span>
                    </button>
                </li>
            </ul>

            <div class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
                <div class="d-flex align-items-center flex-grow-1" style="max-width: 480px;">
                    <div class="input-group shadow-sm" style="border-radius: 2rem; overflow: visible; border: 1px solid #d1d5db; background: #fff; position: relative;">

                        <div class="dropdown">
                            <button type="button" class="btn btn-light border-0 px-3 text-muted position-relative" id="claimsFilterMenuBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Filter by Section &amp; Status" style="background: transparent; border-radius: 2rem 0 0 2rem; height: 38px;">
                                <i class="fas fa-sliders-h"></i>
                                <span id="claimsFilterActiveDot" class="filter-active-dot" style="display:none;"></span>
                            </button>

                            <div class="dropdown-menu shadow-lg p-3 border-0" aria-labelledby="claimsFilterMenuBtn" style="min-width: 280px; border-radius: 1rem; z-index: 1050;">
                                <div class="d-flex align-items-center justify-content-between mb-2 border-bottom pb-2">
                                    <span class="fw-bold text-dark small"><i class="fas fa-filter text-danger me-1"></i> Filter Options</span>
                                    <button type="button" class="btn btn-link btn-sm text-danger p-0 text-decoration-none small" onclick="resetClaimsFilters()">Reset</button>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label small fw-bold mb-1" for="claimsSectionFilter"><i class="fas fa-layer-group text-danger me-1"></i> Section:</label>
                                    <select id="claimsSectionFilter" class="form-select form-select-sm">
                                        <option value="">All Sections</option>
                                        <option value="A">Section A</option>
                                        <option value="B">Section B</option>
                                        <option value="C">Section C</option>
                                        <option value="D">Section D</option>
                                        <option value="E">Section E</option>
                                        <option value="F">Section F</option>
                                    </select>
                                </div>

                                <div class="mb-2" id="statusFilterCol">
                                    <label class="form-label small fw-bold mb-1" for="claimsStatusFilter"><i class="fas fa-filter text-primary me-1"></i> Status:</label>
                                    <select id="claimsStatusFilter" class="form-select form-select-sm">
                                        <option value="">All Status</option>
                                        <option value="PENDING">Pending</option>
                                        <option value="VERIFIED">Verified</option>
                                        <option value="REJECTED">Rejected</option>
                                    </select>
                                </div>

                                <button type="button" class="btn btn-sm btn-danger w-100 mt-2 py-1 rounded-pill" onclick="filterClaimsTables(); bootstrap.Dropdown.getInstance(document.getElementById('claimsFilterMenuBtn'))?.hide();" style="background-color: #7b1113; border-color: #7b1113;">
                                    <i class="fas fa-check me-1"></i> Apply Filter
                                </button>
                            </div>
                        </div>

                        <input type="text" id="claimsSearchInput" class="form-control border-0 px-2" placeholder="Search applicant, plot (e.g. Section E 1-1)..." aria-label="Search claims" style="box-shadow:none; font-size: 0.9rem; background: transparent; height: 38px;">

                        <button class="btn btn-link text-muted px-2 text-decoration-none" type="button" id="claimsClearBtn" title="Clear Search" style="display: none;" onclick="clearClaimsSearch()">
                            <i class="fas fa-times"></i>
                        </button>

                        <span class="input-group-text bg-white border-0 pe-3 text-muted" style="border-radius: 0 2rem 2rem 0; height: 38px;">
                            <i class="fas fa-search"></i>
                        </span>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <span id="claimsFilterCount" class="badge bg-white text-dark border px-2 py-1 small">Total: <strong><?php echo count($pending_claims); ?></strong></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="claimsResetFiltersBtn" style="display:none; height:26px; font-size:0.75rem;" onclick="resetClaimsFilters()" title="Reset All Filters">
                        <i class="fas fa-rotate-left me-1"></i> Reset
                    </button>
                </div>
            </div>

            <div class="tab-content" id="ownershipTabContent">

                <div class="tab-pane fade <?php echo ($active_tab === 'pending') ? 'show active' : ''; ?>" id="pending-claims" role="tabpanel" aria-labelledby="pending-claims-tab">
                    <div class="table-responsive rounded-3 border">
                        <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                            <thead class="table-light text-nowrap">
                                <tr>
                                    <th style="width: 40px;">#</th>
                                    <th>Applicant / Account</th>
                                    <th>Claimed Plot</th>
                                    <th>Deceased Reference</th>
                                    <th>Notes & Proof</th>
                                    <th>Source & Conflict</th>
                                    <th>Date</th>
                                    <th class="text-center" style="width: 160px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($pending_claims)): ?>
                                    <?php $pIdx = 1; foreach ($pending_claims as $claim): ?>
                                        <tr id="claim-row-<?php echo (int)$claim['id']; ?>" class="claim-item-row" data-section="<?php echo htmlspecialchars($claim['section']); ?>" data-status="PENDING">
                                            <td class="fw-semibold text-muted"><?php echo $pIdx++; ?></td>
                                            <td>
                                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($claim['first_name'] . ' ' . $claim['last_name']); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($claim['email']); ?></div>
                                                <?php if (!empty($claim['contact_no'])): ?>
                                                    <div class="small text-muted"><i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($claim['contact_no']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge-plot">
                                                    Section <?php echo htmlspecialchars($claim['section']); ?> — Plot <?php echo (int)$claim['plot_x'] . '-' . (int)$claim['plot_y']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($claim['deceased_reference'])): ?>
                                                    <div class="small text-dark fw-semibold"><i class="fas fa-user me-1 text-secondary"></i><?php echo htmlspecialchars($claim['deceased_reference']); ?></div>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-secondary border">No deceased assigned yet</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small text-muted" style="max-width: 220px; word-break: break-word;">
                                                    <?php echo htmlspecialchars($claim['notes'] ?: 'No notes provided'); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary mb-1"><?php echo htmlspecialchars($claim['claim_source']); ?></span>
                                                <?php if (!empty($claim['has_conflict'])): ?>
                                                    <br><span class="badge bg-danger" title="Conflict with <?php echo htmlspecialchars(($claim['conflict_owner']['first_name'] ?? '') . ' ' . ($claim['conflict_owner']['last_name'] ?? '')); ?>"><i class="fas fa-exclamation-triangle me-1"></i> Conflict</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-muted text-nowrap"><?php echo date('M j, Y', strtotime($claim['created_at'])); ?></td>
                                            <td class="text-center text-nowrap">
                                                <button type="button" class="btn btn-sm btn-success me-1" onclick="adminApproveClaim(<?php echo (int)$claim['id']; ?>, '<?php echo htmlspecialchars($claim['section'], ENT_QUOTES); ?>', <?php echo (int)$claim['plot_x']; ?>, <?php echo (int)$claim['plot_y']; ?>)">
                                                    <i class="fas fa-check me-1"></i> Approve
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="adminRejectClaim(<?php echo (int)$claim['id']; ?>, '<?php echo htmlspecialchars($claim['section'], ENT_QUOTES); ?>', <?php echo (int)$claim['plot_x']; ?>, <?php echo (int)$claim['plot_y']; ?>, '<?php echo htmlspecialchars($claim['first_name'] . ' ' . $claim['last_name'], ENT_QUOTES); ?>')">
                                                    <i class="fas fa-times me-1"></i> Reject
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5 text-muted">
                                            <div class="mb-2"><i class="fas fa-check-circle text-success" style="font-size: 2.2rem;"></i></div>
                                            <div class="fw-semibold text-dark">All plot claims have been reviewed! No pending claims.</div>
                                            <div class="small text-muted mt-1">New claims submitted by plot owners will automatically appear here.</div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo ($active_tab === 'owners') ? 'show active' : ''; ?>" id="owners-directory" role="tabpanel" aria-labelledby="owners-directory-tab">
                    <div class="table-responsive rounded-3 border">
                        <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                            <thead class="table-light text-nowrap">
                                <tr>
                                    <th style="width: 40px;">#</th>
                                    <th>Registered Owner</th>
                                    <th>Contact Details</th>
                                    <th>Plots Owned & Deceased Status</th>
                                    <th class="text-center" style="width: 120px;">Total Plots</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($owner_directory)): ?>
                                    <?php $oIdx = 1; foreach ($owner_directory as $owner): 
                                        $ownerSections = array_unique(array_filter(array_map(function($p) { return strtoupper(trim($p['section'] ?? '')); }, $owner['plots'])));
                                    ?>
                                        <tr class="owner-item-row" data-sections="<?php echo htmlspecialchars(implode(',', $ownerSections)); ?>" data-status="VERIFIED">
                                            <td class="fw-semibold text-muted"><?php echo $oIdx++; ?></td>
                                            <td>
                                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($owner['name']); ?></div>
                                                <div class="small text-muted">User ID #<?php echo (int)$owner['user_id']; ?></div>
                                            </td>
                                            <td>
                                                <div><?php echo htmlspecialchars($owner['email']); ?></div>
                                                <?php if (!empty($owner['contact_no'])): ?>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($owner['contact_no']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column gap-2">
                                                    <?php foreach ($owner['plots'] as $pItem): ?>
                                                        <?php 
                                                            $isOccupied = !empty($pItem['has_deceased']);
                                                            $plotLabel = 'Plot ' . htmlspecialchars($pItem['section']) . ' ' . (int)$pItem['plot_x'] . '-' . (int)$pItem['plot_y'];
                                                            $statusText = $isOccupied ? 'Occupied' : 'Purchased';
                                                            $statusBadgeClass = $isOccupied ? 'bg-success' : 'bg-secondary';
                                                            $deceasedText = $isOccupied ? ($pItem['deceased_name'] ? htmlspecialchars($pItem['deceased_name']) : 'Recorded') : 'Not Assigned';
                                                        ?>
                                                        <div class="border rounded p-2 bg-light d-flex flex-wrap align-items-center justify-content-between gap-2">
                                                            <div class="d-flex align-items-center flex-wrap gap-2">
                                                                <span class="fw-bold text-dark"><i class="fas fa-map-marker-alt text-danger me-1"></i><?php echo $plotLabel; ?></span>
                                                                <span class="badge <?php echo $statusBadgeClass; ?>"><?php echo $statusText; ?></span>
                                                                <span class="small text-muted">
                                                                    <strong>Deceased:</strong> 
                                                                    <span class="<?php echo $isOccupied ? 'text-dark fw-semibold' : 'text-secondary fst-italic'; ?>">
                                                                        <?php echo $deceasedText; ?>
                                                                    </span>
                                                                </span>
                                                            </div>
                                                            <div class="d-flex align-items-center gap-1">
                                                                <?php if (!$isOccupied): ?>
                                                                    <button type="button" class="btn btn-xs btn-outline-success py-1 px-2" style="font-size:0.75rem;" 
                                                                            onclick="adminOpenAddDeceasedModal('<?php echo htmlspecialchars($pItem['section'], ENT_QUOTES); ?>', <?php echo (int)$pItem['plot_x']; ?>, <?php echo (int)$pItem['plot_y']; ?>, <?php echo (int)$owner['user_id']; ?>, '<?php echo htmlspecialchars($owner['name'], ENT_QUOTES); ?>')"
                                                                            title="Add Deceased Record">
                                                                        <i class="fas fa-plus me-1"></i> Add Deceased Record
                                                                    </button>
                                                                <?php endif; ?>
                                                                <button type="button" class="btn btn-xs btn-outline-danger py-1 px-2" style="font-size: 0.75rem;" title="Unlink Plot" 
                                                                        onclick="adminUnlinkPlot(<?php echo (int)$pItem['ownership_id']; ?>, '<?php echo htmlspecialchars($pItem['section'], ENT_QUOTES); ?>', <?php echo (int)$pItem['plot_x']; ?>, <?php echo (int)$pItem['plot_y']; ?>)">
                                                                    <i class="fas fa-unlink"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-primary fs-6"><?php echo count($owner['plots']); ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-muted">
                                            <div class="mb-2"><i class="fas fa-users-slash text-secondary" style="font-size: 2rem;"></i></div>
                                            <div class="fw-semibold">No verified plot owners recorded yet.</div>
                                            <div class="small text-muted mt-1">Use "Manually Link a Plot" or approve incoming claims to populate this directory.</div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo ($active_tab === 'all') ? 'show active' : ''; ?>" id="all-records" role="tabpanel" aria-labelledby="all-records-tab">
                    <div class="table-responsive rounded-3 border">
                        <table class="table table-hover align-middle mb-0 table-all-records" style="min-width: 1200px; font-size: 0.85rem;">
                            <thead class="table-light text-nowrap">
                                <tr>
                                    <th style="width: 45px;" class="text-center">#</th>
                                    <th style="min-width: 200px;">Applicant / Account Holder</th>
                                    <th style="min-width: 180px;">Claimed / Linked Plot</th>
                                    <th style="min-width: 200px;">Deceased Reference</th>
                                    <th style="min-width: 140px;">Ownership &amp; Verification</th>
                                    <th style="min-width: 170px;">Source &amp; Notes</th>
                                    <th style="min-width: 130px;">Date Recorded</th>
                                    <th class="text-center" style="min-width: 150px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($all_ownership_records)): ?>
                                    <?php $aIdx = 1; foreach ($all_ownership_records as $rec): ?>
                                        <?php 
                                             $vStatus = strtoupper(trim((string)$rec['verification_status']));
                                             $badgeClass = 'bg-secondary';
                                             if ($vStatus === 'VERIFIED') $badgeClass = 'bg-success';
                                             elseif ($vStatus === 'PENDING') $badgeClass = 'bg-warning text-dark';
                                             elseif ($vStatus === 'REJECTED') $badgeClass = 'bg-danger';

                                             $srcLabel = ($rec['claim_source'] === 'ADMIN_MANUAL') ? 'Admin Manual Link' : (($rec['claim_source'] === 'USER_CLAIM') ? 'Customer Claim' : htmlspecialchars($rec['claim_source']));
                                             $hasDec = ((int)$rec['deceased_count'] > 0);
                                             $decName = !empty($rec['deceased_name']) ? $rec['deceased_name'] : (!empty($rec['deceased_reference']) ? $rec['deceased_reference'] : '');
                                        ?>
                                        <tr class="all-record-row" data-section="<?php echo htmlspecialchars($rec['section']); ?>" data-status="<?php echo $vStatus; ?>">
                                            <td class="fw-semibold text-muted text-center"><?php echo $aIdx++; ?></td>
                                            <td>
                                                <div class="fw-bold text-dark" style="font-size: 0.88rem;"><?php echo htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name']); ?></div>
                                                <div class="small text-muted" style="font-size: 0.78rem;"><?php echo htmlspecialchars($rec['email']); ?></div>
                                                <?php if (!empty($rec['contact_no'])): ?>
                                                    <div class="small text-muted" style="font-size: 0.78rem;"><i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($rec['contact_no']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge-plot">
                                                    <i class="fas fa-map-marker-alt text-danger me-1"></i>Section <?php echo htmlspecialchars($rec['section']); ?> &mdash; Plot <?php echo (int)$rec['plot_x'] . '-' . (int)$rec['plot_y']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($decName)): ?>
                                                    <div class="small text-dark fw-semibold"><i class="fas fa-cross me-1 text-secondary"></i><?php echo htmlspecialchars($decName); ?></div>
                                                    <span class="badge bg-success" style="font-size:0.7rem;">Occupied</span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-secondary border">No deceased assigned</span>
                                                    <span class="badge bg-secondary" style="font-size:0.7rem;">Purchased</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($vStatus); ?></span>
                                                <div class="small text-muted mt-1"><?php echo htmlspecialchars($rec['ownership_status']); ?></div>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border mb-1"><?php echo htmlspecialchars($srcLabel); ?></span>
                                                <?php if (!empty($rec['notes'])): ?>
                                                    <div class="small text-muted" style="max-width: 220px; word-break: break-word;"><?php echo htmlspecialchars($rec['notes']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-muted text-nowrap">
                                                <?php echo date('M j, Y', strtotime($rec['created_at'])); ?>
                                                <div class="text-muted" style="font-size:0.75rem;"><?php echo date('g:i A', strtotime($rec['created_at'])); ?></div>
                                            </td>
                                            <td class="text-center text-nowrap">
                                                <?php if ($vStatus === 'VERIFIED'): ?>
                                                    <?php if (!$hasDec): ?>
                                                        <button type="button" class="btn btn-xs btn-outline-success py-1 px-2 me-1" style="font-size:0.75rem;" 
                                                                onclick="adminOpenAddDeceasedModal('<?php echo htmlspecialchars($rec['section'], ENT_QUOTES); ?>', <?php echo (int)$rec['plot_x']; ?>, <?php echo (int)$rec['plot_y']; ?>, <?php echo (int)$rec['user_id']; ?>, '<?php echo htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name'], ENT_QUOTES); ?>')"
                                                                title="Add Deceased Record">
                                                            <i class="fas fa-plus"></i> Deceased
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-xs btn-outline-danger py-1 px-2" style="font-size:0.75rem;" 
                                                            onclick="adminUnlinkPlot(<?php echo (int)$rec['id']; ?>, '<?php echo htmlspecialchars($rec['section'], ENT_QUOTES); ?>', <?php echo (int)$rec['plot_x']; ?>, <?php echo (int)$rec['plot_y']; ?>)"
                                                            title="Unlink Plot">
                                                        <i class="fas fa-unlink"></i>
                                                    </button>
                                                <?php elseif ($vStatus === 'PENDING'): ?>
                                                    <button type="button" class="btn btn-xs btn-success py-1 px-2 me-1" style="font-size:0.75rem;" onclick="adminApproveClaim(<?php echo (int)$rec['id']; ?>, '<?php echo htmlspecialchars($rec['section'], ENT_QUOTES); ?>', <?php echo (int)$rec['plot_x']; ?>, <?php echo (int)$rec['plot_y']; ?>)" title="Approve Claim">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-xs btn-outline-danger py-1 px-2" style="font-size:0.75rem;" onclick="adminRejectClaim(<?php echo (int)$rec['id']; ?>, '<?php echo htmlspecialchars($rec['section'], ENT_QUOTES); ?>', <?php echo (int)$rec['plot_x']; ?>, <?php echo (int)$rec['plot_y']; ?>, '<?php echo htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name'], ENT_QUOTES); ?>')" title="Reject Claim">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <div class="d-flex align-items-center justify-content-center gap-1">
                                                        <span class="badge bg-danger" style="font-size:0.7rem;">Rejected</span>
                                                        <button type="button" class="btn btn-xs btn-outline-danger py-1 px-2" style="font-size:0.75rem;" onclick="adminDeleteClaim(<?php echo (int)$rec['id']; ?>, '<?php echo htmlspecialchars($rec['section'], ENT_QUOTES); ?>', <?php echo (int)$rec['plot_x']; ?>, <?php echo (int)$rec['plot_y']; ?>)" title="Delete Record Completely">
                                                            <i class="fas fa-trash-alt me-1"></i> Delete
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5 text-muted">
                                            <div class="mb-2"><i class="fas fa-folder-open text-secondary" style="font-size: 2rem;"></i></div>
                                            <div class="fw-semibold">No plot ownership records found yet.</div>
                                            <div class="small text-muted mt-1">When plots are claimed by customers or linked manually by administrators, they will appear here.</div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

</main>

<div class="modal fade" id="manualLinkPlotModal" tabindex="-1" aria-labelledby="manualLinkPlotModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content overflow-hidden border-0 shadow">
            <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff;">
                <h5 class="modal-title fw-bold m-0" id="manualLinkPlotModalLabel">
                    <i class="fas fa-link me-2"></i> Manually Link Plot to Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="manualLinkForm" onsubmit="return handleManualLinkSubmit(event);">
                <div class="modal-body p-4 bg-light">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">Target User Account <span class="text-danger">*</span></label>
                        <select name="user_id" id="manual_link_user_id" class="form-select form-select-sm" required>
                            <option value="" disabled selected>Select registered user</option>
                            <?php foreach ($all_users as $usr): ?>
                                <option value="<?php echo (int)$usr['user_id']; ?>">
                                    <?php echo htmlspecialchars($usr['last_name'] . ', ' . $usr['first_name'] . ' (' . $usr['email'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-sm-5">
                            <label class="form-label small fw-bold text-dark">Section <span class="text-danger">*</span></label>
                            <select name="section" id="manual_link_section" class="form-select form-select-sm" required>
                                <option value="A">Section A</option>
                                <option value="B">Section B</option>
                                <option value="C">Section C</option>
                                <option value="D">Section D</option>
                                <option value="E">Section E</option>
                                <option value="F">Section F</option>
                            </select>
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label small fw-bold text-dark">Plot Number <span class="text-danger">*</span></label>
                            <input type="text" name="plot_number" id="manual_link_plot_number" class="form-control form-control-sm" placeholder="e.g. 10-1" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">Ownership Status</label>
                        <select name="ownership_status" id="manual_link_status" class="form-select form-select-sm">
                            <option value="OWNED">OWNED</option>
                            <option value="RESERVED">RESERVED</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-bold text-dark">Admin Notes</label>
                        <textarea name="notes" id="manual_link_notes" class="form-control form-control-sm" rows="2" placeholder="e.g. Verified deed of sale / office booking"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top py-2 px-3 d-flex justify-content-between">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-brand-primary">
                        <i class="fas fa-check me-1"></i> Link Plot to Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="adminAddDeceasedModal" tabindex="-1" aria-labelledby="adminAddDeceasedModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content overflow-hidden border-0 shadow">
            <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff;">
                <h5 class="modal-title fw-bold m-0" id="adminAddDeceasedModalLabel">
                    <i class="fas fa-user-plus me-2"></i> Add Deceased Record to Plot
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="adminAddDeceasedForm" onsubmit="return handleAdminAddDeceasedSubmit(event);">
                <input type="hidden" name="user_id" id="admin_dec_user_id" value="">
                <input type="hidden" name="section" id="admin_dec_section" value="">
                <input type="hidden" name="plot_x" id="admin_dec_plot_x" value="">
                <input type="hidden" name="plot_y" id="admin_dec_plot_y" value="">

                <div class="modal-body p-4 bg-light">
                    <div class="alert alert-info py-2 px-3 small border-0 mb-3" style="background:#eef6ff; color:#1e429f;">
                        <strong>Target Plot:</strong> <span id="admin_dec_plot_display">Section A Plot 10-3</span><br>
                        <strong>Plot Owner:</strong> <span id="admin_dec_owner_display">Owner Name</span>
                    </div>

                    <div id="admin_dec_interval_status" class="mb-3" style="display: none;"></div>

                    <div class="row g-2 mb-2">
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold text-dark mb-1">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" id="admin_dec_first_name" class="form-control form-control-sm" required oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold text-dark mb-1">Middle Name</label>
                            <input type="text" name="middle_name" id="admin_dec_middle_name" class="form-control form-control-sm" oninput="this.value = this.value.toUpperCase()">
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold text-dark mb-1">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" id="admin_dec_last_name" class="form-control form-control-sm" required oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold text-dark mb-1">Suffix</label>
                            <input type="text" name="suffix" id="admin_dec_suffix" class="form-control form-control-sm" placeholder="e.g. Jr, III" oninput="this.value = this.value.toUpperCase()">
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold text-dark mb-1">Date of Birth</label>
                            <input type="date" name="bday" id="admin_dec_bday" class="form-control form-control-sm" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold text-dark mb-1">Date of Death <span class="text-danger">*</span></label>
                            <input type="date" name="date_died" id="admin_dec_date_died" class="form-control form-control-sm" required max="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-bold text-dark mb-1">Relationship to Owner <span class="text-muted small fw-normal">(Optional)</span></label>
                        <select name="relationship" id="admin_dec_relationship" class="form-select form-select-sm">
                            <option value="">-- Select Relationship (Optional) --</option>
                            <option value="PARENT">Parent</option>
                            <option value="SPOUSE">Spouse</option>
                            <option value="CHILD">Child</option>
                            <option value="RELATIVE">Relative</option>
                            <option value="OTHER">Other</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top py-2 px-3 d-flex justify-content-between">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="admin_dec_submit_btn" class="btn btn-sm btn-success">
                        <i class="fas fa-check-circle me-1"></i> Save Deceased Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="adminRejectClaimModal" tabindex="-1" aria-labelledby="adminRejectClaimModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content overflow-hidden border-0 shadow">
            <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff;">
                <h5 class="modal-title fw-bold m-0" id="adminRejectClaimModalLabel">
                    <i class="fas fa-times-circle me-2"></i> Reject Plot Claim
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="adminRejectClaimForm" onsubmit="return handleAdminRejectSubmit(event);">
                <input type="hidden" name="claim_id" id="reject_claim_id" value="">

                <div class="modal-body p-4 bg-light">
                    <div class="alert alert-secondary py-2 px-3 small border-0 mb-3" style="background: #f1f5f9; color: #1e293b;">
                        <strong>Target Plot:</strong> <span id="reject_plot_display" class="fw-bold text-dark">Section C — Plot 11-1</span><br>
                        <strong>Applicant:</strong> <span id="reject_applicant_display">Fatima Torio</span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">
                            Reason for Rejection <span class="text-danger">*</span>
                        </label>
                        <textarea name="admin_notes" id="reject_admin_notes" class="form-control" rows="3" required placeholder="State why this claim cannot be confirmed..."></textarea>
                        <div class="form-text small">This reason will be included in the in-system notification and email sent to the user.</div>
                    </div>

                    <div class="card border rounded-3 p-3 mb-2 bg-white">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="reject_delete_record" name="delete_record" value="1">
                            <label class="form-check-label fw-bold text-danger" for="reject_delete_record" style="font-size: 0.92rem;">
                                <i class="fas fa-trash-alt me-1"></i> Delete this claim record completely from system
                            </label>
                            <div class="text-muted small ms-4">
                                If checked, the record will be completely removed and will not remain as a rejected plot on the user's dashboard.
                            </div>
                        </div>

                        <div class="form-check ms-0">
                            <input class="form-check-input" type="checkbox" id="reject_send_notif" name="send_notification" value="1" checked>
                            <label class="form-check-label text-dark small" for="reject_send_notif">
                                <i class="fas fa-bell text-warning me-1"></i> Send in-system & email notification to applicant
                            </label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-white border-top py-2 px-3 d-flex justify-content-between">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="reject_submit_btn" class="btn btn-sm btn-danger px-3">
                        <i class="fas fa-times-circle me-1"></i> Confirm Rejection
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="adminUnlinkPlotModal" tabindex="-1" aria-labelledby="adminUnlinkPlotModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 460px;">
        <div class="modal-content overflow-hidden border-0 shadow-lg" style="border-radius: 1rem;">
            <div class="modal-header py-3 px-4 text-white" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%);">
                <h5 class="modal-title fw-bold m-0" id="adminUnlinkPlotModalLabel">
                    <i class="fas fa-unlink me-2"></i> Unlink Plot
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center bg-white">
                <div class="mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 64px; height: 64px; background: #fee2e2; color: #dc2626; font-size: 1.8rem; box-shadow: 0 4px 14px rgba(220, 38, 38, 0.18);">
                        <i class="fas fa-unlink"></i>
                    </span>
                </div>
                <h5 class="fw-bold text-dark mb-2">Unlink Plot Ownership?</h5>
                <p class="text-secondary mb-3" style="font-size: 0.95rem; line-height: 1.55;">
                    Are you sure you want to unlink <strong id="unlink_plot_display" class="text-danger fw-bold">Section A Plot 2-2</strong> from this account?
                </p>
                <div class="card border-0 bg-light p-3 text-start small mb-1 rounded-3">
                    <div class="text-muted mb-1">
                        <i class="fas fa-info-circle text-primary me-2"></i> This will remove this plot assignment from the user's dashboard.
                    </div>
                    <div class="text-muted">
                        <i class="fas fa-history text-secondary me-2"></i> Action will be recorded in the system audit logs.
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-0 py-3 px-4 d-flex justify-content-between">
                <button type="button" class="btn btn-sm btn-secondary px-3 py-2 fw-semibold" data-bs-dismiss="modal" style="border-radius: 0.5rem;">
                    Cancel
                </button>
                <button type="button" id="btnUnlinkConfirmSubmit" class="btn btn-sm btn-danger px-3 py-2 fw-semibold shadow-sm d-inline-flex align-items-center gap-2" style="border-radius: 0.5rem; background: #dc2626; border-color: #dc2626;" onclick="executeUnlinkPlot()">
                    <i class="fas fa-unlink"></i> Unlink Plot
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="claimActionResultModal" tabindex="-1" aria-labelledby="claimActionResultModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
        <div class="modal-content overflow-hidden border-0 shadow-lg" style="border-radius: 1rem;">
            <div id="claimResultHeader" class="modal-header py-3 px-4 text-white" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%);">
                <h5 class="modal-title fw-bold m-0" id="claimActionResultModalLabel">
                    <i id="claimResultIcon" class="fas fa-check-circle me-2"></i>
                    <span id="claimResultTitle">Claim Processed</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" onclick="handleClaimResultClose()"></button>
            </div>
            <div class="modal-body p-4 text-center bg-white">
                <div class="mb-3">
                    <span id="claimResultBadgeIcon" class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 68px; height: 68px; background: #fee2e2; color: #dc2626; font-size: 2rem; box-shadow: 0 4px 14px rgba(220, 38, 38, 0.18);">
                        <i class="fas fa-times-circle"></i>
                    </span>
                </div>
                <h5 id="claimResultHeading" class="fw-bold text-dark mb-2">Claim Marked as Rejected</h5>
                <p id="claimResultMessage" class="text-secondary mb-3" style="font-size: 0.95rem; line-height: 1.55;">
                    Plot claim for Section E Plot 1-1 was marked as rejected. User has been notified.
                </p>
                <div id="claimResultDetailsCard" class="card border-0 bg-light p-3 text-start small mb-1 rounded-3">
                    <div class="d-flex align-items-center mb-1 text-success fw-semibold">
                        <i class="fas fa-bell text-warning me-2"></i> <span id="claimResultNotifStatus">In-system &amp; email notification dispatched to applicant</span>
                    </div>
                    <div class="d-flex align-items-center text-muted">
                        <i class="fas fa-clipboard-check text-primary me-2"></i> Action recorded in system audit logs
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-0 py-3 px-4 justify-content-center">
                <button type="button" class="btn btn-sm px-4 py-2 text-white fw-semibold shadow-sm" style="background: #7b1113; border-color: #7b1113; border-radius: 0.5rem; min-width: 130px;" onclick="handleClaimResultClose()">
                    <i class="fas fa-check me-1"></i> OK
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
var rejectModalObj = null;
var unlinkModalObj = null;
var pendingUnlinkData = null;
var claimResultModalObj = null;
var claimResultShouldReload = false;
var claimResultRedirectUrl = null;

function showClaimResultModal(options) {
    var title = options.title || 'Claim Processed';
    var heading = options.heading || 'Plot Claim Update';
    var message = options.message || '';
    var type = options.type || 'danger'; // 'danger', 'success', 'primary'
    var notifText = options.notifText || 'In-system & email notification dispatched to applicant';

    claimResultShouldReload = !!options.reloadOnClose;
    claimResultRedirectUrl = options.redirectUrl || null;

    var headerEl = document.getElementById('claimResultHeader');
    var titleEl = document.getElementById('claimResultTitle');
    var iconEl = document.getElementById('claimResultIcon');
    var badgeIconEl = document.getElementById('claimResultBadgeIcon');
    var headingEl = document.getElementById('claimResultHeading');
    var msgEl = document.getElementById('claimResultMessage');
    var notifEl = document.getElementById('claimResultNotifStatus');

    if (titleEl) titleEl.textContent = title;
    if (headingEl) headingEl.textContent = heading;
    if (msgEl) msgEl.textContent = message;
    if (notifEl) notifEl.textContent = notifText;

    if (type === 'danger') {
        if (headerEl) headerEl.style.background = 'linear-gradient(135deg, #7b1113 0%, #94171a 100%)';
        if (iconEl) iconEl.className = 'fas fa-times-circle me-2';
        if (badgeIconEl) {
            badgeIconEl.style.background = '#fee2e2';
            badgeIconEl.style.color = '#dc2626';
            badgeIconEl.innerHTML = '<i class="fas fa-times-circle"></i>';
        }
    } else if (type === 'success') {
        if (headerEl) headerEl.style.background = 'linear-gradient(135deg, #065f46 0%, #059669 100%)';
        if (iconEl) iconEl.className = 'fas fa-check-circle me-2';
        if (badgeIconEl) {
            badgeIconEl.style.background = '#d1fae5';
            badgeIconEl.style.color = '#059669';
            badgeIconEl.innerHTML = '<i class="fas fa-check-circle"></i>';
        }
    } else {
        if (headerEl) headerEl.style.background = 'linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%)';
        if (iconEl) iconEl.className = 'fas fa-info-circle me-2';
        if (badgeIconEl) {
            badgeIconEl.style.background = '#dbeafe';
            badgeIconEl.style.color = '#2563eb';
            badgeIconEl.innerHTML = '<i class="fas fa-info-circle"></i>';
        }
    }

    if (!claimResultModalObj) {
        var el = document.getElementById('claimActionResultModal');
        if (el) {
            claimResultModalObj = new bootstrap.Modal(el);
            el.addEventListener('hidden.bs.modal', function() {
                handleClaimResultClose();
            });
        }
    }
    if (claimResultModalObj) claimResultModalObj.show();
}

function handleClaimResultClose() {
    if (claimResultRedirectUrl) {
        window.location.href = claimResultRedirectUrl;
    } else if (claimResultShouldReload) {
        window.location.reload();
    }
}

function parseResponseSafe(r) {
    return r.text().then(function(text) {
        var data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            if (text.indexOf('<!DOCTYPE') !== -1 || text.indexOf('<html') !== -1) {
                return {
                    success: false,
                    session_expired: true,
                    message: 'Your administrator session has ended or expired (signed into customer account in another tab). Please sign in to the Administrator Portal.'
                };
            }
            return {
                success: false,
                message: 'Invalid server response: ' + (text ? text.substring(0, 100) : 'Empty response')
            };
        }
        return data;
    });
}

function adminApproveClaim(claimId, section, px, py) {
    if (!confirm('Approve and verify claim for Section ' + section + ' Plot ' + px + '-' + py + '?')) return;
    var note = prompt('Optional administrator notes / remarks:', 'Approved by Administrator');
    if (note === null) return;

    var fd = new FormData();
    fd.append('action', 'approve_plot_claim');
    fd.append('claim_id', claimId);
    fd.append('admin_notes', note || 'Approved by Administrator');

    fetch('claims_review.php', {
        method: 'POST',
        body: fd
    })
    .then(parseResponseSafe)
    .then(function(res) {
        if (res.session_expired) {
            if (window.GorSessionGuard) { GorSessionGuard.handleSessionExpired(res.message); } else { alert(res.message); window.location.href = 'admin_login.php'; }
            return;
        }
        if (res.success) {
            showClaimResultModal({
                title: 'Plot Claim Approved',
                heading: 'Claim Verified Successfully',
                message: res.message || 'Plot claim approved successfully! The plot is now verified under this owner.',
                type: 'success',
                redirectUrl: 'claims_review.php?tab=owners'
            });
        } else {
            alert('Error: ' + (res.message || 'Failed to approve claim.'));
        }
    })
    .catch(function(err) {
        alert('Request failed: ' + (err.message || 'Unable to connect to server.'));
    });
}

function adminRejectClaim(claimId, section, px, py, applicantName) {
    document.getElementById('reject_claim_id').value = claimId;
    document.getElementById('reject_plot_display').textContent = (section && px && py) ? ('Section ' + section + ' — Plot ' + px + '-' + py) : ('Claim #' + claimId);
    document.getElementById('reject_applicant_display').textContent = applicantName || ('User for Claim #' + claimId);
    document.getElementById('reject_admin_notes').value = 'Unverified records or details could not be confirmed by administrator.';
    document.getElementById('reject_delete_record').checked = false;
    document.getElementById('reject_send_notif').checked = true;

    if (!rejectModalObj) {
        var el = document.getElementById('adminRejectClaimModal');
        if (el) rejectModalObj = new bootstrap.Modal(el);
    }
    if (rejectModalObj) rejectModalObj.show();
}

function handleAdminRejectSubmit(e) {
    e.preventDefault();
    var form = document.getElementById('adminRejectClaimForm');
    var fd = new FormData(form);
    fd.append('action', 'reject_plot_claim');

    var submitBtn = document.getElementById('reject_submit_btn');
    var origHtml = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processing...'; }

    fetch('claims_review.php', {
        method: 'POST',
        body: fd
    })
    .then(parseResponseSafe)
    .then(function(res) {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = origHtml; }
        if (res.session_expired) {
            if (window.GorSessionGuard) { GorSessionGuard.handleSessionExpired(res.message); } else { alert(res.message); window.location.href = 'admin_login.php'; }
            return;
        }
        if (res.success) {
            if (rejectModalObj) rejectModalObj.hide();
            showClaimResultModal({
                title: 'Claim Marked as Rejected',
                heading: 'Plot Claim Rejected',
                message: res.message || 'Plot claim was marked as rejected. User has been notified.',
                type: 'danger',
                notifText: 'In-system & email notification dispatched to applicant',
                reloadOnClose: true
            });
        } else {
            alert('Error: ' + (res.message || 'Failed to reject claim.'));
        }
    })
    .catch(function(err) {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = origHtml; }
        alert('Request failed: ' + (err.message || 'Unable to connect to server.'));
    });
    return false;
}

function adminDeleteClaim(claimId, section, px, py) {
    if (!confirm('Are you sure you want to permanently delete the plot record for Section ' + section + ' Plot ' + px + '-' + py + '? This cannot be undone.')) return;

    var notify = confirm('Send a notification to the user informing them that this plot record was deleted?');

    var fd = new FormData();
    fd.append('action', 'delete_plot_claim');
    fd.append('claim_id', claimId);
    fd.append('notify_user', notify ? '1' : '0');
    if (notify) {
        fd.append('reason', 'Claim record was removed by administrator.');
    }

    fetch('claims_review.php', {
        method: 'POST',
        body: fd
    })
    .then(parseResponseSafe)
    .then(function(res) {
        if (res.session_expired) {
            if (window.GorSessionGuard) { GorSessionGuard.handleSessionExpired(res.message); } else { alert(res.message); window.location.href = 'admin_login.php'; }
            return;
        }
        if (res.success) {
            showClaimResultModal({
                title: 'Plot Claim Record Deleted',
                heading: 'Record Removed',
                message: res.message || 'Plot claim record deleted successfully!',
                type: 'danger',
                reloadOnClose: true
            });
        } else {
            alert('Error: ' + (res.message || 'Failed to delete claim.'));
        }
    })
    .catch(function(err) {
        alert('Request failed: ' + (err.message || 'Unable to connect to server.'));
    });
}

function handleManualLinkSubmit(e) {
    e.preventDefault();
    var form = document.getElementById('manualLinkForm');
    var fd = new FormData(form);
    fd.append('action', 'manual_link_plot');

    var submitBtn = form.querySelector('button[type="submit"]');
    var origHtml = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Linking...'; }

    fetch('claims_review.php', {
        method: 'POST',
        body: fd
    })
    .then(parseResponseSafe)
    .then(function(res) {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = origHtml; }
        if (res.session_expired) {
            if (window.GorSessionGuard) { GorSessionGuard.handleSessionExpired(res.message); } else { alert(res.message); window.location.href = 'admin_login.php'; }
            return;
        }
        if (res.success) {
            var modalEl = document.getElementById('manualLinkPlotModal');
            if (modalEl) {
                var m = bootstrap.Modal.getInstance(modalEl);
                if (m) m.hide();
            }
            showClaimResultModal({
                title: 'Plot Linked Successfully',
                heading: 'Plot Assigned',
                message: res.message || 'Plot linked successfully!',
                type: 'success',
                redirectUrl: 'claims_review.php?tab=owners'
            });
        } else {
            alert('Error: ' + (res.message || 'Failed to link plot.'));
        }
    })
    .catch(function(err) {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = origHtml; }
        alert('Request failed: ' + (err.message || 'Unable to connect to server.'));
    });
    return false;
}

function adminUnlinkPlot(claimId, section, px, py) {
    pendingUnlinkData = { claimId: claimId, section: section, px: px, py: py };
    var plotText = 'Section ' + section + ' Plot ' + px + '-' + py;
    var dispEl = document.getElementById('unlink_plot_display');
    if (dispEl) dispEl.textContent = plotText;

    if (!unlinkModalObj) {
        var el = document.getElementById('adminUnlinkPlotModal');
        if (el) {
            unlinkModalObj = new bootstrap.Modal(el);
        }
    }
    if (unlinkModalObj) {
        unlinkModalObj.show();
    } else if (confirm('Are you sure you want to unlink ' + plotText + ' from this account?')) {
        executeUnlinkPlot();
    }
}

function executeUnlinkPlot() {
    if (!pendingUnlinkData) return;
    var claimId = pendingUnlinkData.claimId;
    var btn = document.getElementById('btnUnlinkConfirmSubmit');
    var origHtml = '';
    if (btn) {
        origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Unlinking...';
    }

    var fd = new FormData();
    fd.append('action', 'unlink_plot');
    fd.append('claim_id', claimId);

    fetch('claims_review.php', {
        method: 'POST',
        body: fd
    })
    .then(parseResponseSafe)
    .then(function(res) {
        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
        if (unlinkModalObj) { unlinkModalObj.hide(); }

        if (res.session_expired) {
            if (window.GorSessionGuard) { GorSessionGuard.handleSessionExpired(res.message); } else { alert(res.message); window.location.href = 'admin_login.php'; }
            return;
        }
        if (res.success) {
            showClaimResultModal({
                title: 'Plot Unlinked',
                heading: 'Ownership Removed',
                message: res.message || 'Plot unlinked successfully!',
                type: 'danger',
                redirectUrl: 'claims_review.php?tab=owners'
            });
        } else {
            showClaimResultModal({
                title: 'Unlink Failed',
                heading: 'Action Incomplete',
                message: res.message || 'Failed to unlink plot.',
                type: 'danger'
            });
        }
    })
    .catch(function(err) {
        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
        if (unlinkModalObj) { unlinkModalObj.hide(); }
        alert('Request failed: ' + (err.message || 'Unable to connect to server.'));
    });
}

function adminOpenAddDeceasedModal(section, px, py, userId, ownerName) {
    document.getElementById('admin_dec_user_id').value = userId;
    document.getElementById('admin_dec_section').value = section;
    document.getElementById('admin_dec_plot_x').value = px;
    document.getElementById('admin_dec_plot_y').value = py;
    var targetDisplay = document.getElementById('admin_dec_target_display') || document.getElementById('admin_dec_plot_display');
    if (targetDisplay) targetDisplay.textContent = 'Section ' + section + ' — Plot ' + px + '-' + py;
    document.getElementById('admin_dec_owner_display').textContent = ownerName + ' (User #' + userId + ')';

    document.getElementById('admin_dec_first_name').value = '';
    document.getElementById('admin_dec_middle_name').value = '';
    document.getElementById('admin_dec_last_name').value = '';
    document.getElementById('admin_dec_suffix').value = '';
    document.getElementById('admin_dec_bday').value = '';
    document.getElementById('admin_dec_date_died').value = '';
    document.getElementById('admin_dec_relationship').value = '';

    var statusBox = document.getElementById('admin_dec_interval_status');
    var submitBtn = document.getElementById('admin_dec_submit_btn');
    if (statusBox) {
        statusBox.style.display = 'block';
        statusBox.className = 'alert alert-info py-2 px-3 small border-0 mb-3';
        statusBox.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Checking plot interment interval eligibility...';
    }
    if (submitBtn) submitBtn.disabled = true;

    fetch('claims_review.php?action=check_plot_interval&section=' + encodeURIComponent(section) + '&plot_x=' + px + '&plot_y=' + py)
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!statusBox) return;
        if (data.can_add) {
            statusBox.className = 'alert alert-success py-2 px-3 small border-0 mb-3';
            statusBox.innerHTML = '<i class="fas fa-check-circle me-1"></i> <strong>Eligible for Additional Deceased:</strong> ' + (data.years_elapsed || '8+') + ' years elapsed since previous interment of ' + (data.latest_name || 'occupant') + ' (' + (data.formatted_interment_date || '') + ').';
            submitBtn.disabled = false;
        } else {
            statusBox.className = 'alert alert-danger py-2 px-3 small border-0 mb-3';
            statusBox.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> <strong>Interment Not Yet Eligible:</strong> Only ' + (data.years_elapsed || '0') + ' years have passed since previous interment. Minimum required interval: 8 years. Eligible on: ' + (data.formatted_eligible_date || 'N/A') + '.';
            submitBtn.disabled = true;
        }
    })
    .catch(function() {
        if (statusBox) statusBox.style.display = 'none';
        if (submitBtn) submitBtn.disabled = false;
    });

    var modalEl = document.getElementById('adminAddDeceasedModal');
    if (modalEl) {
        var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        modal.show();
    }
}

function handleAdminAddDeceasedSubmit(e) {
    e.preventDefault();
    var form = document.getElementById('adminAddDeceasedForm');
    var fd = new FormData(form);
    fd.append('action', 'admin_add_deceased_to_plot');

    var submitBtn = document.getElementById('admin_dec_submit_btn');
    var origHtml = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...'; }

    fetch('claims_review.php', {
        method: 'POST',
        body: fd
    })
    .then(parseResponseSafe)
    .then(function(res) {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = origHtml; }
        if (res.session_expired) {
            if (window.GorSessionGuard) { GorSessionGuard.handleSessionExpired(res.message); } else { alert(res.message); window.location.href = 'admin_login.php'; }
            return;
        }
        if (res.success) {
            var modalEl = document.getElementById('adminAddDeceasedModal');
            if (modalEl) {
                var m = bootstrap.Modal.getInstance(modalEl);
                if (m) m.hide();
            }
            showClaimResultModal({
                title: 'Deceased Record Added',
                heading: 'Plot Occupied',
                message: res.message || 'Deceased record added and plot marked as Occupied!',
                type: 'success',
                redirectUrl: 'claims_review.php?tab=owners'
            });
        } else {
            alert('Error: ' + (res.message || 'Failed to add deceased record.'));
        }
    })
    .catch(function(err) {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = origHtml; }
        alert('Request failed: ' + (err.message || 'Unable to connect to server.'));
    });
    return false;
}

// Interactive Live Filter System for Claims Review
function filterClaimsTables() {
    var query = (document.getElementById('claimsSearchInput')?.value || '').toLowerCase().trim();
    var section = (document.getElementById('claimsSectionFilter')?.value || '').toUpperCase().trim();
    var status = (document.getElementById('claimsStatusFilter')?.value || '').toUpperCase().trim();

    var clearBtn = document.getElementById('claimsClearBtn');
    var resetBtn = document.getElementById('claimsResetFiltersBtn');
    var activeDot = document.getElementById('claimsFilterActiveDot');
    var isFiltered = (query !== '' || section !== '' || status !== '');
    if (clearBtn) clearBtn.style.display = (query !== '') ? 'inline-block' : 'none';
    if (resetBtn) resetBtn.style.display = isFiltered ? 'inline-block' : 'none';
    if (activeDot) activeDot.style.display = (section !== '' || status !== '') ? 'inline-block' : 'none';

    // Find active tab pane
    var activePane = document.querySelector('#ownershipTabContent .tab-pane.active');
    if (!activePane) return;

    var rows = activePane.querySelectorAll('tbody tr:not(.empty-filter-row)');
    var visibleCount = 0;
    var totalCount = 0;

    rows.forEach(function(row) {
        // Skip default empty placeholder rows (like colspan=8)
        if (row.querySelector('td[colspan]')) return;
        totalCount++;

        var rowText = (row.textContent || '').toLowerCase();
        var rowSection = (row.getAttribute('data-section') || row.getAttribute('data-sections') || '').toUpperCase();
        var rowStatus = (row.getAttribute('data-status') || '').toUpperCase();

        var matchesQuery = (query === '' || rowText.indexOf(query) !== -1);
        var matchesSection = (section === '' || rowSection.indexOf(section) !== -1);
        var matchesStatus = (status === '' || rowStatus === status);

        if (matchesQuery && matchesSection && matchesStatus) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    // Dynamic empty row handling
    var existingEmpty = activePane.querySelector('.empty-filter-row');
    if (visibleCount === 0 && totalCount > 0) {
        if (!existingEmpty) {
            var tbody = activePane.querySelector('tbody');
            var colCount = activePane.querySelectorAll('thead th').length || 6;
            var emptyTr = document.createElement('tr');
            emptyTr.className = 'empty-filter-row';
            emptyTr.innerHTML = '<td colspan="' + colCount + '" class="text-center py-4 text-muted">' +
                '<div class="mb-2"><i class="fas fa-search me-2 text-secondary" style="font-size: 1.5rem;"></i></div>' +
                '<div class="fw-semibold">No records match your filter criteria.</div>' +
                '<button type="button" class="btn btn-link btn-sm text-decoration-none p-0 mt-1" onclick="resetClaimsFilters()"><i class="fas fa-times me-1"></i>Clear Filters</button>' +
                '</td>';
            tbody.appendChild(emptyTr);
        }
    } else if (existingEmpty) {
        existingEmpty.remove();
    }

    // Update count display
    var countBadge = document.getElementById('claimsFilterCount');
    if (countBadge) {
        if (isFiltered) {
            countBadge.innerHTML = '<i class="fas fa-filter text-primary me-1"></i>Showing <strong>' + visibleCount + '</strong> of ' + totalCount;
            countBadge.className = 'badge bg-primary text-white border-0 px-2 py-1 small';
        } else {
            countBadge.innerHTML = 'Total: <strong>' + totalCount + '</strong>';
            countBadge.className = 'badge bg-white text-dark border px-2 py-1 small';
        }
    }
}

function clearClaimsSearch() {
    var searchInput = document.getElementById('claimsSearchInput');
    if (searchInput) {
        searchInput.value = '';
        filterClaimsTables();
        searchInput.focus();
    }
}

function resetClaimsFilters() {
    var sInput = document.getElementById('claimsSearchInput');
    var sSec = document.getElementById('claimsSectionFilter');
    var sStat = document.getElementById('claimsStatusFilter');
    if (sInput) sInput.value = '';
    if (sSec) sSec.value = '';
    if (sStat) sStat.value = '';
    filterClaimsTables();
}

function setSectionPill(sec) {
    var sSec = document.getElementById('claimsSectionFilter');
    if (sSec) {
        sSec.value = sec;
        filterClaimsTables();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var urlParams = new URLSearchParams(window.location.search);
    var tab = urlParams.get('tab');
    if (tab === 'owners') {
        var el = document.getElementById('owners-directory-tab');
        if (el) bootstrap.Tab.getOrCreateInstance(el).show();
    } else if (tab === 'all') {
        var el = document.getElementById('all-records-tab');
        if (el) bootstrap.Tab.getOrCreateInstance(el).show();
    }

    // Bind search & filter inputs
    var searchInput = document.getElementById('claimsSearchInput');
    var secFilter = document.getElementById('claimsSectionFilter');
    var statFilter = document.getElementById('claimsStatusFilter');

    if (searchInput) searchInput.addEventListener('input', filterClaimsTables);
    if (secFilter) secFilter.addEventListener('change', filterClaimsTables);
    if (statFilter) statFilter.addEventListener('change', filterClaimsTables);

    // Re-filter when tabs change
    var tabs = document.querySelectorAll('#ownershipTab button[data-bs-toggle="pill"]');
    tabs.forEach(function(t) {
        t.addEventListener('shown.bs.tab', function() {
            filterClaimsTables();
        });
    });

    // Run initial filter
    filterClaimsTables();
});
</script>

<script src="includes/session_tab_guard.js"></script>
<script>
// Tab-aware session guard — this is an admin-only page (same home as dashboard)
if (window.GorSessionGuard) {
    GorSessionGuard.init('dashboard');
}
</script>

</body>
</html>


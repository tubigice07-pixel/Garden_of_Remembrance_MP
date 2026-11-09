<?php
ob_start();

$profileDebug = !empty($_GET['debug']) && $_GET['debug'] === '1';
if ($profileDebug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

register_shutdown_function(function () use ($profileDebug) {
    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($error && in_array($error['type'], $fatalTypes, true)) {
        error_log('profile.php fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        if ($profileDebug && !headers_sent()) {
            http_response_code(500);
            echo '<pre style="white-space:pre-wrap;font:14px monospace;padding:20px;background:#fee2e2;color:#991b1b;">'
                . htmlspecialchars($error['message'] . "\nFile: " . $error['file'] . ':' . $error['line'], ENT_QUOTES, 'UTF-8')
                . '</pre>';
        }
    }
});

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$userInactivityTimeout = 300; // 5 minutes (300 seconds)
if (!empty($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > $userInactivityTimeout)) {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain']);
        }
        @session_destroy();
        header("Location: login.php?session_expired=1");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

require_once __DIR__ . '/connection.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?auth_required=1');
    exit;
}

if (!function_exists('is_db_connected') || !is_db_connected()) {
    http_response_code(503);
    global $servername, $dbname, $lastConnErr, $lastConnNo;
    $safeHost = htmlspecialchars((string)($servername ?? ''));
    $safeDb = htmlspecialchars((string)($dbname ?? ''));
    $safeErr = htmlspecialchars((string)($lastConnErr ?? ''));
    $safeNo = (int)($lastConnNo ?? 0);
    $debugInfo = "";
    if ($profileDebug) {
        $debugInfo = "<div class='mt-3 text-start p-3 bg-light rounded text-muted font-monospace small border'>"
            . "<strong>Host:</strong> {$safeHost}<br>"
            . "<strong>Database:</strong> {$safeDb}<br>"
            . "<strong>Error [{$safeNo}]:</strong> {$safeErr}"
            . "</div>";
    }
    exit("<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>Database Offline</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'></head><body class='bg-light d-flex align-items-center justify-content-center min-vh-100 p-3'><div class='card shadow-sm p-4 text-center' style='max-width:480px;border-radius:1rem;'><h4 class='text-danger fw-bold mb-2'><i class='fas fa-database me-2'></i>Database Connection Offline</h4><p class='text-secondary small mb-3'>Unable to connect to cemetery database ({$safeDb}). Please verify credentials or refresh shortly.</p>{$debugInfo}<a href='user_dashboard.php' class='btn btn-primary btn-sm mt-2'>Return to Dashboard</a></div></body></html>");
}

$user_id = intval($_SESSION['user_id']);

try {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $check_profile_column = @$conn->query("SHOW COLUMNS FROM tbl_user LIKE 'profile_picture'");
        if ($check_profile_column && $check_profile_column->num_rows === 0) {
            @$conn->query("ALTER TABLE tbl_user ADD COLUMN profile_picture VARCHAR(255) NULL");
        }
        $check_account_column = @$conn->query("SHOW COLUMNS FROM tbl_user LIKE 'account_number'");
        if ($check_account_column && $check_account_column->num_rows === 0) {
            @$conn->query("ALTER TABLE tbl_user ADD COLUMN account_number VARCHAR(20) DEFAULT NULL");
        }
        $check_gender_column = @$conn->query("SHOW COLUMNS FROM tbl_user LIKE 'gender'");
        if ($check_gender_column && $check_gender_column->num_rows === 0) {
            @$conn->query("ALTER TABLE tbl_user ADD COLUMN gender VARCHAR(10) NULL DEFAULT NULL AFTER birthday");
        }
    }
} catch (Throwable $colEx) {
    error_log('Profile column check notice: ' . $colEx->getMessage());
}

$user = null;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
    $user_stmt = $conn->prepare("SELECT user_id, first_name, middle_name, last_name, suffix, birthday, gender, email, password, phone_no, profile_picture, account_number, role, email_verified FROM tbl_user WHERE user_id = ? LIMIT 1");
    if ($user_stmt) {
        $user_stmt->bind_param('i', $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user = $user_result ? $user_result->fetch_assoc() : null;
        $user_stmt->close();
    }
    if (!$user) {
        $fb_stmt = $conn->prepare("SELECT user_id, first_name, last_name, email, phone_no, role FROM tbl_user WHERE user_id = ? LIMIT 1");
        if ($fb_stmt) {
            $fb_stmt->bind_param('i', $user_id);
            $fb_stmt->execute();
            $fb_res = $fb_stmt->get_result();
            $user = $fb_res ? $fb_res->fetch_assoc() : null;
            $fb_stmt->close();
        }
    }
}

if (!$user) {
    header('Location: logout.php');
    exit;
}

if (isset($user['role']) && strtoupper(trim((string)$user['role'])) === 'ADMIN') {
    header('Location: admin_profile.php');
    exit;
}

$errors = [];
$success = '';
$upload_path = 'uploads/profile_pictures/';
$default_avatar = 'uploads/profile_pictures/default_avatar.png';
$current_picture = $default_avatar;
if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/' . $user['profile_picture'])) {
    $current_picture = $user['profile_picture'];
}

$linked_deceased = [];
if (function_exists('get_user_linked_deceased_records')) {
    $linked_deceased = get_user_linked_deceased_records($conn, $user_id);
} else {
    $decStmt = $conn->prepare("SELECT deceased_id, first_name, middle_name, last_name, suffix, relationship_to_account_holder, section, plot_x, plot_y, bday, date_died FROM tbl_deceased WHERE user_id = ?");
    if ($decStmt) {
        $decStmt->bind_param('i', $user_id);
        $decStmt->execute();
        $decResult = $decStmt->get_result();
        while ($row = $decResult->fetch_assoc()) {
            $linked_deceased[] = $row;
        }
        $decStmt->close();
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    if (isset($_POST['action']) && $_POST['action'] === 'delete_account') {
        $confirm_email = trim($_POST['confirm_email'] ?? '');
        if (strtolower($confirm_email) !== strtolower(trim($user['email']))) {
            $errors[] = 'Account deletion cancelled: the entered email does not match your account email (' . htmlspecialchars($user['email']) . ').';
        } else {
            $conn->begin_transaction();
            try {
                $userEmail = trim((string)($user['email'] ?? ''));
                $userFullName = trim(implode(' ', array_filter([
                    trim((string)($user['first_name'] ?? '')),
                    trim((string)($user['middle_name'] ?? '')),
                    trim((string)($user['last_name'] ?? '')),
                    trim((string)($user['suffix'] ?? ''))
                ])));

                $unlinkStmt = $conn->prepare("UPDATE tbl_deceased SET user_id = NULL, relationship_to_account_holder = NULL WHERE user_id = ?");
                if ($unlinkStmt) {
                    $unlinkStmt->bind_param('i', $user_id);
                    $unlinkStmt->execute();
                    $unlinkStmt->close();
                }

                $checkTr = $conn->query("SHOW TABLES LIKE 'plot_transfer_requests'");
                if ($checkTr && $checkTr->num_rows > 0) {
                    $delTransferStmt = $conn->prepare("DELETE FROM plot_transfer_requests WHERE user_id = ?");
                    if ($delTransferStmt) {
                        $delTransferStmt->bind_param('i', $user_id);
                        $delTransferStmt->execute();
                        $delTransferStmt->close();
                    }
                }

                if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/' . $user['profile_picture']) && $user['profile_picture'] !== $default_avatar) {
                    @unlink(__DIR__ . '/' . $user['profile_picture']);
                }

                $reservedPlots = [];
                $bookingIds = [];

                $checkBk = $conn->query("SHOW TABLES LIKE 'tbl_bookings'");
                if ($checkBk && $checkBk->num_rows > 0) {
                    $bkgStmt = $conn->prepare("SELECT id, section, plot, plot_x, plot_y FROM tbl_bookings WHERE user_id = ? OR LOWER(TRIM(customer_email)) = ? OR LOWER(TRIM(email)) = ?");
                    if ($bkgStmt) {
                        $lowerEmail = strtolower($userEmail);
                        $bkgStmt->bind_param('iss', $user_id, $lowerEmail, $lowerEmail);
                        $bkgStmt->execute();
                        $res = $bkgStmt->get_result();
                        while ($row = $res->fetch_assoc()) {
                            $bookingIds[] = (int)$row['id'];
                            $sec = strtoupper(trim((string)($row['section'] ?? '')));
                            $px = (int)($row['plot_x'] ?? 0);
                            $py = (int)($row['plot_y'] ?? 0);
                            if (($px === 0 || $py === 0) && !empty($row['plot'])) {
                                if (preg_match('/(?:[A-Za-z]-)?(\d{1,3})-(\d{1,3})/', $row['plot'], $m)) {
                                    $px = (int)$m[1];
                                    $py = (int)$m[2];
                                }
                            }
                            if ($sec !== '' && $px > 0 && $py > 0) {
                                $reservedPlots[$sec . '_' . $px . '_' . $py] = [
                                    'section' => $sec,
                                    'plot_x' => $px,
                                    'plot_y' => $py
                                ];
                            }
                        }
                        $bkgStmt->close();
                    }
                }

                $checkUpo = $conn->query("SHOW TABLES LIKE 'user_plot_ownership'");
                if ($checkUpo && $checkUpo->num_rows > 0) {
                    $upoStmt = $conn->prepare("SELECT section, plot_x, plot_y FROM user_plot_ownership WHERE user_id = ?");
                    if ($upoStmt) {
                        $upoStmt->bind_param('i', $user_id);
                        $upoStmt->execute();
                        $res = $upoStmt->get_result();
                        while ($row = $res->fetch_assoc()) {
                            $sec = strtoupper(trim((string)($row['section'] ?? '')));
                            $px = (int)($row['plot_x'] ?? 0);
                            $py = (int)($row['plot_y'] ?? 0);
                            if ($sec !== '' && $px > 0 && $py > 0) {
                                $reservedPlots[$sec . '_' . $px . '_' . $py] = [
                                    'section' => $sec,
                                    'plot_x' => $px,
                                    'plot_y' => $py
                                ];
                            }
                        }
                        $upoStmt->close();
                    }
                }

                $checkPo = $conn->query("SHOW TABLES LIKE 'tbl_plot_owners'");
                if ($checkPo && $checkPo->num_rows > 0) {
                    $poStmt = $conn->prepare("SELECT section, plot_x, plot_y FROM tbl_plot_owners WHERE reserved_by = ?");
                    if ($poStmt) {
                        $poStmt->bind_param('i', $user_id);
                        $poStmt->execute();
                        $res = $poStmt->get_result();
                        while ($row = $res->fetch_assoc()) {
                            $sec = strtoupper(trim((string)($row['section'] ?? '')));
                            $px = (int)($row['plot_x'] ?? 0);
                            $py = (int)($row['plot_y'] ?? 0);
                            if ($sec !== '' && $px > 0 && $py > 0) {
                                $reservedPlots[$sec . '_' . $px . '_' . $py] = [
                                    'section' => $sec,
                                    'plot_x' => $px,
                                    'plot_y' => $py
                                ];
                            }
                        }
                        $poStmt->close();
                    }
                }

                $checkGrr = $conn->query("SHOW TABLES LIKE 'guest_reservation_requests'");
                if ($checkGrr && $checkGrr->num_rows > 0) {
                    $lowerEmail = strtolower($userEmail);
                    $grrStmt = $conn->prepare("SELECT booking_id, section, plot_x, plot_y FROM guest_reservation_requests WHERE user_id = ? OR LOWER(TRIM(email)) = ?");
                    if ($grrStmt) {
                        $grrStmt->bind_param('is', $user_id, $lowerEmail);
                        $grrStmt->execute();
                        $res = $grrStmt->get_result();
                        while ($row = $res->fetch_assoc()) {
                            if (!empty($row['booking_id'])) {
                                $bookingIds[] = (int)$row['booking_id'];
                            }
                            $sec = strtoupper(trim((string)($row['section'] ?? '')));
                            $px = (int)($row['plot_x'] ?? 0);
                            $py = (int)($row['plot_y'] ?? 0);
                            if ($sec !== '' && $px > 0 && $py > 0) {
                                $reservedPlots[$sec . '_' . $px . '_' . $py] = [
                                    'section' => $sec,
                                    'plot_x' => $px,
                                    'plot_y' => $py
                                ];
                            }
                        }
                        $grrStmt->close();
                    }
                }

                $bookingIds = array_unique(array_filter($bookingIds));

                if (!empty($bookingIds)) {
                    $bList = implode(',', $bookingIds);
                    $checkBps = $conn->query("SHOW TABLES LIKE 'booking_payment_schedule'");
                    if ($checkBps && $checkBps->num_rows > 0) {
                        $conn->query("DELETE FROM booking_payment_schedule WHERE booking_id IN ($bList)");
                    }
                    $checkPay = $conn->query("SHOW TABLES LIKE 'tbl_payments'");
                    if ($checkPay && $checkPay->num_rows > 0) {
                        $conn->query("DELETE FROM tbl_payments WHERE booking_id IN ($bList) OR user_id = $user_id");
                    }
                    $checkRec = $conn->query("SHOW TABLES LIKE 'payment_receipts'");
                    if ($checkRec && $checkRec->num_rows > 0) {
                        $conn->query("DELETE FROM payment_receipts WHERE booking_id IN ($bList) OR user_id = $user_id");
                    }
                    $checkSale = $conn->query("SHOW TABLES LIKE 'tbl_sales'");
                    if ($checkSale && $checkSale->num_rows > 0) {
                        $conn->query("DELETE FROM tbl_sales WHERE booking_id IN ($bList) OR user_id = $user_id");
                    }
                }
                $checkPay = $conn->query("SHOW TABLES LIKE 'tbl_payments'");
                if ($checkPay && $checkPay->num_rows > 0) {
                    $conn->query("DELETE FROM tbl_payments WHERE user_id = $user_id");
                }
                $checkRec = $conn->query("SHOW TABLES LIKE 'payment_receipts'");
                if ($checkRec && $checkRec->num_rows > 0) {
                    $conn->query("DELETE FROM payment_receipts WHERE user_id = $user_id");
                }
                $checkSale = $conn->query("SHOW TABLES LIKE 'tbl_sales'");
                if ($checkSale && $checkSale->num_rows > 0) {
                    $conn->query("DELETE FROM tbl_sales WHERE user_id = $user_id");
                }

                $checkClaimReq = $conn->query("SHOW TABLES LIKE 'claims_requests'");
                if ($checkClaimReq && $checkClaimReq->num_rows > 0) {
                    $conn->query("DELETE FROM claims_requests WHERE user_id = $user_id");
                }
                $checkPlotClaim = $conn->query("SHOW TABLES LIKE 'plot_claims'");
                if ($checkPlotClaim && $checkPlotClaim->num_rows > 0) {
                    $conn->query("DELETE FROM plot_claims WHERE user_id = $user_id");
                }

                $checkNotif = $conn->query("SHOW TABLES LIKE 'notifications'");
                if ($checkNotif && $checkNotif->num_rows > 0) {
                    if (!empty($bookingIds)) {
                        $bList = implode(',', $bookingIds);
                        $conn->query("DELETE FROM notifications WHERE user_id = $user_id OR booking_id IN ($bList)");
                    } else {
                        $conn->query("DELETE FROM notifications WHERE user_id = $user_id");
                    }
                }

                if ($checkUpo && $checkUpo->num_rows > 0) {
                    $conn->query("DELETE FROM user_plot_ownership WHERE user_id = $user_id");
                }

                if ($checkPo && $checkPo->num_rows > 0) {
                    $conn->query("DELETE FROM tbl_plot_owners WHERE reserved_by = $user_id");
                    foreach ($reservedPlots as $rp) {
                        $rSec = $conn->real_escape_string($rp['section']);
                        $rX = (int)$rp['plot_x'];
                        $rY = (int)$rp['plot_y'];

                        $conn->query("DELETE FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = '{$rSec}' AND plot_x = {$rX} AND plot_y = {$rY}");
                    }
                }

                if ($checkGrr && $checkGrr->num_rows > 0) {
                    $lowerEmail = strtolower($userEmail);
                    $delGrr = $conn->prepare("DELETE FROM guest_reservation_requests WHERE user_id = ? OR LOWER(TRIM(email)) = ?");
                    if ($delGrr) {
                        $delGrr->bind_param('is', $user_id, $lowerEmail);
                        $delGrr->execute();
                        $delGrr->close();
                    }
                    if (!empty($bookingIds)) {
                        $bList = implode(',', $bookingIds);
                        $conn->query("DELETE FROM guest_reservation_requests WHERE booking_id IN ($bList)");
                    }
                    foreach ($reservedPlots as $rp) {
                        $rSec = $conn->real_escape_string($rp['section']);
                        $rX = (int)$rp['plot_x'];
                        $rY = (int)$rp['plot_y'];
                        $conn->query("DELETE FROM guest_reservation_requests WHERE UPPER(TRIM(section)) = '{$rSec}' AND plot_x = {$rX} AND plot_y = {$rY}");
                    }
                }

                if ($checkBk && $checkBk->num_rows > 0) {
                    $lowerEmail = strtolower($userEmail);
                    $delBk = $conn->prepare("DELETE FROM tbl_bookings WHERE user_id = ? OR LOWER(TRIM(customer_email)) = ? OR LOWER(TRIM(email)) = ?");
                    if ($delBk) {
                        $delBk->bind_param('iss', $user_id, $lowerEmail, $lowerEmail);
                        $delBk->execute();
                        $delBk->close();
                    }
                }

                $checkPlots = $conn->query("SHOW TABLES LIKE 'tbl_plots'");
                if ($checkPlots && $checkPlots->num_rows > 0) {
                    foreach ($reservedPlots as $rp) {
                        $rSec = $conn->real_escape_string($rp['section']);
                        $rX = (int)$rp['plot_x'];
                        $rY = (int)$rp['plot_y'];

                        $checkDec = $conn->query("SELECT deceased_id FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$rSec}' AND plot_x = {$rX} AND plot_y = {$rY} LIMIT 1");
                        if (!$checkDec || $checkDec->num_rows === 0) {
                            $conn->query("UPDATE tbl_plots SET status = 'available' WHERE UPPER(TRIM(section)) = '{$rSec}' AND plot_x = {$rX} AND plot_y = {$rY}");
                        }
                    }
                }

                $checkLogs = $conn->query("SHOW TABLES LIKE 'tbl_logs'");
                if ($checkLogs && $checkLogs->num_rows > 0) {

                    $conn->query("DELETE FROM tbl_logs WHERE user_id = $user_id");

                    if (!empty($bookingIds)) {
                        foreach ($bookingIds as $bId) {
                            $conn->query("DELETE FROM tbl_logs WHERE details LIKE '%#{$bId}%' OR details LIKE '%Booking ID: #{$bId}%'");
                        }
                    }

                    if (!empty($userEmail)) {
                        $escEmail = $conn->real_escape_string($userEmail);
                        $conn->query("DELETE FROM tbl_logs WHERE (action IN ('GUEST_RESERVATION_SUBMITTED','GUEST_RESERVATION_APPROVED','GUEST_RESERVATION_REJECTED','GUEST_ACCOUNT_CREATED','Reserved By','Updated Reservation','Booking Approved','Booking Completed','Booking Rejected','PLOT_CLAIM_SUBMITTED','PLOT_CLAIM_APPROVED','SERVICE_BOOKING') OR action LIKE '%Reservation%') AND details LIKE '%{$escEmail}%'");
                    }

                    if (!empty($userFullName) && strlen($userFullName) > 3) {
                        $escName = $conn->real_escape_string($userFullName);
                        $conn->query("DELETE FROM tbl_logs WHERE (action IN ('GUEST_RESERVATION_SUBMITTED','GUEST_RESERVATION_APPROVED','GUEST_RESERVATION_REJECTED','GUEST_ACCOUNT_CREATED','Reserved By','Updated Reservation','Booking Approved','Booking Completed','Booking Rejected','PLOT_CLAIM_SUBMITTED','PLOT_CLAIM_APPROVED') OR action LIKE '%Reservation%') AND details LIKE '%{$escName}%'");
                    }

                    foreach ($reservedPlots as $rp) {
                        $rSec = $conn->real_escape_string($rp['section']);
                        $rX = (int)$rp['plot_x'];
                        $rY = (int)$rp['plot_y'];
                        $conn->query("DELETE FROM tbl_logs WHERE (action IN ('Reserved By','Updated Reservation','GUEST_RESERVATION_SUBMITTED','GUEST_RESERVATION_APPROVED') OR action LIKE '%Reservation%') AND details LIKE '%Section {$rSec}%' AND (details LIKE '%{$rX}-{$rY}%' OR details LIKE '%Plot {$rX}-{$rY}%')");
                    }

                    $secList = !empty($reservedPlots) ? implode(', ', array_keys($reservedPlots)) : 'none';
                    $auditDetails = "User Account #{$user_id} ({$userEmail}) was deleted. All associated plot reservations ({$secList}) and reservation logs have been automatically removed and released back to available.";
                    $auditStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('USER_ACCOUNT_DELETED', ?, 0, NOW())");
                    if ($auditStmt) {
                        $auditStmt->bind_param('s', $auditDetails);
                        $auditStmt->execute();
                        $auditStmt->close();
                    }
                }

                $delUserStmt = $conn->prepare("DELETE FROM tbl_user WHERE user_id = ? LIMIT 1");
                if (!$delUserStmt) {
                    throw new Exception('Database error preparing user deletion: ' . $conn->error);
                }
                $delUserStmt->bind_param('i', $user_id);
                if (!$delUserStmt->execute()) {
                    throw new Exception('Failed to delete user account: ' . $delUserStmt->error);
                }
                $delUserStmt->close();

                $conn->commit();

                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $params["path"], $params["domain"],
                        $params["secure"], $params["httponly"]
                    );
                }
                session_destroy();

                header('Location: login.php?deleted=1');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'Failed to delete account: ' . $e->getMessage();
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $raw_suffix = trim($_POST['suffix'] ?? '');
        $other_suffix = trim($_POST['suffix_other'] ?? '');
        $suffix = strtoupper($raw_suffix === 'OTHER' ? $other_suffix : $raw_suffix);
        $gender = ucfirst(strtolower(trim($_POST['gender'] ?? '')));
        $phone_no = trim($_POST['phone_no'] ?? '');

        $account_number = trim(preg_replace('/\D/', '', $_POST['account_number'] ?? ''));
        if ($first_name === '') {
            $errors[] = 'First name is required.';
        }
        if ($last_name === '') {
            $errors[] = 'Last name is required.';
        }
        if ($gender !== '' && $gender !== 'Male' && $gender !== 'Female') {
            $errors[] = 'Please select a valid gender (Male or Female).';
        }
        if ($phone_no !== '' && !preg_match('/^09[0-9]{9}$/', preg_replace('/[^0-9]/', '', $phone_no))) {
            $errors[] = 'Phone number must be an 11-digit mobile number starting with 09 (e.g. 09XXXXXXXXX).';
        }
        if ($account_number !== '' && !preg_match('/^[0-9]{11}$/', $account_number)) {
            $errors[] = 'Account number must be exactly 11 digits.';
        }
        if ($account_number !== '' && empty($errors)) {
            $check_account_stmt = $conn->prepare("SELECT user_id FROM tbl_user WHERE account_number = ? AND user_id != ? LIMIT 1");
            if ($check_account_stmt) {
                $check_account_stmt->bind_param('si', $account_number, $user_id);
                $check_account_stmt->execute();
                $check_account_result = $check_account_stmt->get_result();
                if ($check_account_result && $check_account_result->num_rows > 0) {
                    $errors[] = 'That account number is already in use by another account.';
                }
                $check_account_stmt->close();
            }
        }

        if (empty($errors)) {
            $update_stmt = $conn->prepare("UPDATE tbl_user SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, gender = ?, phone_no = ?, account_number = ? WHERE user_id = ? LIMIT 1");
            $update_stmt->bind_param('sssssssi', $first_name, $middle_name, $last_name, $suffix, $gender, $phone_no, $account_number, $user_id);
            if ($update_stmt->execute()) {
                $success = 'Profile details updated successfully.';
                $user['first_name'] = $first_name;
                $user['middle_name'] = $middle_name;
                $user['last_name'] = $last_name;
                $user['suffix'] = $suffix;
                $user['gender'] = $gender;
                $user['phone_no'] = $phone_no;
                $user['account_number'] = $account_number;
                $_SESSION['first_name'] = $first_name;
            } else {
                $errors[] = 'Unable to update profile. Please try again later.';
            }
            $update_stmt->close();
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password)) {
            $errors[] = 'Please enter your current password.';
        } elseif (empty($user['password']) || !password_verify($current_password, $user['password'])) {
            $errors[] = 'The current password you entered is incorrect.';
        }

        if (empty($new_password)) {
            $errors[] = 'Please enter a new password.';
        } elseif (strlen($new_password) < 8) {
            $errors[] = 'Password must be at least 8 characters in length.';
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            $errors[] = 'Password must contain at least one uppercase letter (A-Z).';
        } elseif (!preg_match('/[a-z]/', $new_password)) {
            $errors[] = 'Password must contain at least one lowercase letter (a-z).';
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            $errors[] = 'Password must contain at least one number (0-9).';
        } elseif (!preg_match('/[^A-Za-z0-9]/', $new_password)) {
            $errors[] = 'Password must contain at least one symbol or special character (e.g. !@#$%^&*).';
        } elseif ($new_password !== $confirm_password) {
            $errors[] = 'The new password and confirmation password do not match.';
        }

        if (empty($errors)) {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $passStmt = $conn->prepare("UPDATE tbl_user SET password = ? WHERE user_id = ? LIMIT 1");
            if ($passStmt) {
                $passStmt->bind_param('si', $new_hash, $user_id);
                if ($passStmt->execute()) {
                    $success = 'Your password has been changed securely and updated.';
                    $user['password'] = $new_hash;

                    $logChk = $conn->query("SHOW TABLES LIKE 'tbl_logs'");
                    if ($logChk && $logChk->num_rows > 0) {
                        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('USER_PASSWORD_CHANGE', 'User updated account password', ?, NOW())");
                        if ($logStmt) {
                            $logStmt->bind_param('i', $user_id);
                            $logStmt->execute();
                            $logStmt->close();
                        }
                    }
                } else {
                    $errors[] = 'Failed to update password. Please try again.';
                }
                $passStmt->close();
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'upload_picture' && isset($_FILES['profile_picture'])) {
        if (!is_dir(__DIR__ . '/' . $upload_path)) {
            mkdir(__DIR__ . '/' . $upload_path, 0755, true);
        }

        $file = $_FILES['profile_picture'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed. Please try again.';
        } else {
            if ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = 'File size must be 5MB or less.';
            }
            $allowed = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!array_key_exists($mime_type, $allowed)) {
                $errors[] = 'Only JPG, JPEG, PNG and WEBP images are allowed.';
            }

            if (empty($errors)) {
                $extension = $allowed[$mime_type];
                $filename = $user_id . '_' . time() . '.' . $extension;
                $destination = $upload_path . $filename;
                $destination_path = __DIR__ . '/' . $destination;

                if (move_uploaded_file($file['tmp_name'], $destination_path)) {
                    chmod($destination_path, 0644);
                    if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/' . $user['profile_picture']) && $user['profile_picture'] !== $default_avatar) {
                        @unlink(__DIR__ . '/' . $user['profile_picture']);
                    }
                    $update_stmt = $conn->prepare("UPDATE tbl_user SET profile_picture = ? WHERE user_id = ? LIMIT 1");
                    $update_stmt->bind_param('si', $destination, $user_id);
                    if ($update_stmt->execute()) {
                        $success = 'Profile picture updated successfully.';
                        $user['profile_picture'] = $destination;
                        $current_picture = $destination;
                    } else {
                        $errors[] = 'Unable to save profile picture. Please try again.';
                        @unlink($destination_path);
                    }
                    $update_stmt->close();
                } else {
                    $errors[] = 'Unable to move uploaded file. Please check folder permissions.';
                }
            }
        }
    }
}

$full_name = trim(implode(' ', array_filter([$user['first_name'] ?? '', $user['middle_name'] ?? '', $user['last_name'] ?? '', $user['suffix'] ?? ''])));
$display_picture = (!empty($user['profile_picture']) && file_exists(__DIR__ . '/' . $user['profile_picture'])) ? $user['profile_picture'] : $default_avatar;

$user_bookings_count = 0;
$bStmt = $conn->prepare("SELECT COUNT(*) AS total FROM tbl_bookings WHERE user_id = ?");
if ($bStmt) {
    $bStmt->bind_param('i', $user_id);
    $bStmt->execute();
    $bRes = $bStmt->get_result();
    if ($bRow = $bRes->fetch_assoc()) $user_bookings_count = (int)$bRow['total'];
    $bStmt->close();
}
$linked_deceased = function_exists('get_user_linked_deceased_records') ? get_user_linked_deceased_records($conn, $user_id) : [];
if (!is_array($linked_deceased)) {
    $linked_deceased = [];
}
if (empty($linked_deceased)) {
    $dStmt = @$conn->prepare("SELECT * FROM tbl_deceased WHERE user_id = ? ORDER BY deceased_id DESC");
    if ($dStmt) {
        $dStmt->bind_param('i', $user_id);
        $dStmt->execute();
        $dRes = $dStmt->get_result();
        while ($dRow = $dRes->fetch_assoc()) {
            $linked_deceased[] = $dRow;
        }
        $dStmt->close();
    }
}

$user_plots = function_exists('get_user_owned_plots') ? get_user_owned_plots($conn, $user_id) : [];
if (!is_array($user_plots)) {
    $user_plots = [];
}

$user_deceased_count = count($linked_deceased);
$user_plots_count = count($user_plots);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Garden of Remembrance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bs-primary: #7b1113;
            --bs-primary-dark: #610d10;
            --bs-primary-light: #8e1518;
            --admin-accent: #a03a3c;
            --admin-header-gradient: linear-gradient(135deg, #7b1113 0%, #8e1518 50%, #5d0c0e 100%);
            --card-radius: 1.25rem;
        }

        html, body {
            margin: 0 !important;
            padding: 0 !important;
            padding-top: 0 !important;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f5f7fb;
            color: #212529;
            min-height: 100vh;
        }

        /* Profile Layout */
        .profile-page-container {
            max-width: 1040px;
            margin: 1.5rem auto 3rem;
            padding: 0 1rem;
        }

        .profile-hero-card {
            background: var(--admin-header-gradient);
            border-radius: var(--card-radius);
            color: #ffffff;
            padding: 2.25rem 2rem;
            margin-bottom: 1.75rem;
            box-shadow: 0 10px 30px rgba(123, 17, 19, 0.18);
            position: relative;
            overflow: hidden;
        }

        .profile-hero-card::after {
            content: '';
            position: absolute;
            right: -30px;
            bottom: -30px;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,0.08), transparent 70%);
            pointer-events: none;
        }

        .profile-avatar-wrap {
            position: relative;
            width: 104px;
            height: 104px;
            min-width: 104px;
            min-height: 104px;
            max-width: 104px;
            max-height: 104px;
            aspect-ratio: 1 / 1;
            flex-shrink: 0;
            display: inline-block;
            margin: 0 auto;
        }

        .profile-avatar-img {
            width: 104px !important;
            height: 104px !important;
            min-width: 104px !important;
            min-height: 104px !important;
            max-width: 104px !important;
            max-height: 104px !important;
            aspect-ratio: 1 / 1 !important;
            border-radius: 50% !important;
            object-fit: cover !important;
            display: block !important;
            border: 3.5px solid rgba(255, 255, 255, 0.95);
            background: #ffffff;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
        }

        .profile-avatar-badge {
            position: absolute;
            bottom: 0px;
            right: 0px;
            background: #7b1113;
            color: #ffffff;
            border: 2px solid #ffffff;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            transition: transform 0.2s ease;
        }

        .profile-avatar-badge:hover {
            transform: scale(1.1);
            background: #5d0c0e;
        }

        .pill-badge {
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.28);
            color: #ffffff;
            padding: 5px 14px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            backdrop-filter: blur(4px);
        }

        /* Metric Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.75rem;
        }

        .metric-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 1.15rem 1.25rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.2s ease;
        }

        .metric-card:hover {
            transform: translateY(-2px);
        }

        .metric-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }

        .metric-val {
            font-size: 1.45rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
        }

        .metric-lbl {
            font-size: 0.78rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* Navigation Tabs */
        .profile-nav-tabs {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 1.5rem;
            overflow-x: auto;
            white-space: nowrap;
            padding-bottom: 2px;
        }

        .profile-tab-btn {
            background: none;
            border: none;
            padding: 10px 18px;
            font-size: 0.92rem;
            font-weight: 600;
            color: #64748b;
            border-radius: 10px 10px 0 0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            position: relative;
            transition: all 0.2s ease;
        }

        .profile-tab-btn:hover {
            color: #7b1113;
            background: #ffffff;
        }

        .profile-tab-btn.active {
            color: #7b1113;
            font-weight: 700;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-bottom: 2px solid #ffffff;
            margin-bottom: -2px;
            box-shadow: 0 -2px 6px rgba(0,0,0,0.02);
        }

        /* Content Card */
        .content-card {
            background: #ffffff;
            border-radius: var(--card-radius);
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
            padding: 1.75rem 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 768px) {
            .content-card {
                padding: 1.25rem 1.25rem;
            }
            .profile-hero-card {
                padding: 1.5rem 1.25rem;
            }
        }

        @media (max-width: 576px) {
            .profile-page-container {
                padding: 0 0.5rem 1.5rem !important;
            }
            .profile-hero-card {
                padding: 1.25rem 0.9rem !important;
                border-radius: 16px !important;
                margin-bottom: 1rem !important;
            }
            .profile-hero-card h1 {
                font-size: 1.35rem !important;
                margin-bottom: 3px !important;
                line-height: 1.25 !important;
            }
            .profile-hero-card .opacity-90 {
                font-size: 0.82rem !important;
                margin-bottom: 0.65rem !important;
                word-break: break-all;
                gap: 4px !important;
            }
            .pill-badge {
                font-size: 0.72rem !important;
                padding: 4px 10px !important;
            }
            .profile-avatar-wrap {
                width: 88px !important;
                height: 88px !important;
                min-width: 88px !important;
                min-height: 88px !important;
                max-width: 88px !important;
                max-height: 88px !important;
                margin-bottom: 0.35rem !important;
            }
            .profile-avatar-img {
                width: 88px !important;
                height: 88px !important;
                min-width: 88px !important;
                min-height: 88px !important;
                max-width: 88px !important;
                max-height: 88px !important;
                border-width: 3px !important;
            }
            .profile-avatar-badge {
                width: 28px !important;
                height: 28px !important;
                bottom: -2px !important;
                right: -2px !important;
            }
            .metrics-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 0.6rem !important;
                margin-bottom: 1rem !important;
            }
            .metric-card {
                padding: 0.75rem 0.85rem !important;
                border-radius: 12px !important;
                gap: 0.65rem !important;
            }
            .metric-icon {
                width: 38px !important;
                height: 38px !important;
                font-size: 1.1rem !important;
                border-radius: 10px !important;
            }
            .metric-val {
                font-size: 1.2rem !important;
            }
            .metric-lbl {
                font-size: 0.72rem !important;
            }
            .content-card {
                padding: 1rem 0.85rem !important;
                border-radius: 14px !important;
            }
        }

        .card-header-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 0.9rem;
            margin-bottom: 1.5rem;
        }

        .card-header-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #7b1113;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        /* Detail List Item */
        .detail-item-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .detail-label {
            font-size: 0.82rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .detail-value {
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
        }

        .form-label {
            font-weight: 600;
            font-size: 0.88rem;
            color: #334155;
            margin-bottom: 0.35rem;
        }

        .form-control, .form-select {
            border-radius: 0.6rem;
            border: 1px solid #cbd5e1;
            padding: 0.65rem 0.9rem;
            font-size: 0.92rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: #7b1113;
            box-shadow: 0 0 0 0.25rem rgba(123, 17, 19, 0.15);
        }

        /* Password Strength Meter & Security Checklist */
        .password-strength-container {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
        }
        .strength-bar-track {
            display: flex;
            gap: 5px;
            height: 6px;
            background: transparent;
            margin-bottom: 0.55rem;
        }
        .strength-segment {
            flex: 1;
            height: 100%;
            background: #e2e8f0;
            border-radius: 999px;
            transition: background-color 0.3s ease;
        }
        .strength-segment.active-weak { background-color: #ef4444; }
        .strength-segment.active-medium { background-color: #f59e0b; }
        .strength-segment.active-strong { background-color: #10b981; }
        .criteria-item {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.78rem;
            color: #64748b;
            transition: color 0.2s ease;
        }
        .criteria-item i {
            font-size: 0.65rem;
            color: #cbd5e1;
            transition: all 0.2s ease;
        }
        .criteria-item.met {
            color: #15803d;
            font-weight: 600;
        }
        .criteria-item.met i {
            color: #16a34a;
        }
        .password-match-indicator {
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 0.35rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            min-height: 1.2rem;
        }
        .password-match-indicator.matching { color: #16a34a; }
        .password-match-indicator.mismatch { color: #dc2626; }

        .btn-primary {
            background-color: #7b1113;
            border-color: #7b1113;
            font-weight: 600;
            border-radius: 0.6rem;
            padding: 0.65rem 1.75rem;
        }

        .btn-primary:hover, .btn-primary:focus {
            background-color: #610d10;
            border-color: #610d10;
        }

        .btn-outline-primary {
            color: #7b1113;
            border-color: #7b1113;
            font-weight: 600;
            border-radius: 0.6rem;
        }

        .btn-outline-primary:hover {
            background-color: #7b1113;
            border-color: #7b1113;
            color: #ffffff;
        }

        /* Danger Zone Card */
        .danger-card {
            background: #fffafa;
            border: 1px solid #fecaca;
            border-radius: 1.25rem;
            padding: 1.5rem 1.75rem;
            margin-top: 2rem;
        }

        .danger-card-title {
            color: #dc2626;
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        /* Deceased record badge item */
        .deceased-record-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>

    <?php
    $is_admin = isset($_SESSION['role']) && strtoupper(trim((string)$_SESSION['role'])) === 'ADMIN';
    $dash_url = $is_admin ? 'dashboard.php' : 'user_dashboard.php';
    ?>

    <header style="background: linear-gradient(135deg, #7b1113 0%, #8e1518 50%, #5d0c0e 100%); padding: 0.65rem 1.25rem; box-shadow: 0 4px 18px rgba(0,0,0,0.16); position: sticky; top: 0; z-index: 1040;">
      <div style="height: 48px; display: flex; align-items: center; justify-content: space-between; max-width: 1040px; margin: 0 auto; width: 100%;">
        <a href="<?php echo $dash_url; ?>" style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none;">
          <img src="cmis_logo.png" alt="Garden of Remembrance Logo" style="height: 36px; width: auto; object-fit: contain;">
          <span style="color: #ffffff !important; font-weight: 700 !important; font-size: clamp(0.85rem, 3.2vw, 1.05rem) !important; white-space: nowrap !important; letter-spacing: 0.01em !important;">Garden of Remembrance</span>
        </a>
        <a href="<?php echo $dash_url; ?>" class="btn btn-sm btn-outline-light d-inline-flex align-items-center gap-1" style="border-radius: 20px; font-size: 0.8rem; font-weight: 600; padding: 5px 14px; border-color: rgba(255,255,255,0.4);">
          <i class="fas fa-arrow-left"></i> <span class="d-none d-sm-inline">Back to Dashboard</span><span class="d-inline d-sm-none">Back</span>
        </a>
      </div>
    </header>

    <main class="profile-page-container">

        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div class="small text-muted" style="font-size: 0.8rem;">
                <i class="fas fa-user-circle me-1 text-danger"></i> <span>Manage your personal profile and account credentials</span>
            </div>
            <span class="badge bg-secondary text-uppercase fw-semibold" style="letter-spacing:0.04em; font-size: 0.72rem;"><?php echo $is_admin ? 'Admin Account' : 'User Account'; ?></span>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-4" role="alert" style="border-radius: 0.75rem;">
                <i class="fas fa-check-circle fs-5 me-2"></i>
                <div><?php echo htmlspecialchars($success); ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert" style="border-radius: 0.75rem;">
                <div class="d-flex align-items-center mb-1">
                    <i class="fas fa-exclamation-triangle fs-5 me-2"></i>
                    <strong>Please resolve the following:</strong>
                </div>
                <ul class="mb-0 ps-4">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <section class="profile-hero-card">
            <div class="d-flex align-items-center gap-3 gap-md-4 flex-wrap flex-md-nowrap justify-content-center justify-content-md-start">
                <div class="profile-avatar-wrap m-0">
                    <img src="<?php echo htmlspecialchars($display_picture); ?>" alt="Profile avatar" class="profile-avatar-img" onerror="this.onerror=null;this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'%237b1113\'><path d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/></svg>'">
                    <label for="avatar_input" class="profile-avatar-badge" title="Change Photo">
                        <i class="fas fa-camera text-white" style="font-size: 0.85rem;"></i>
                    </label>
                </div>

                <form id="avatarForm" method="POST" enctype="multipart/form-data" style="display:none;">
                    <input type="hidden" name="action" value="upload_picture">
                    <input type="file" name="profile_picture" id="avatar_input" accept="image/jpeg,image/jpg,image/png,image/webp" onchange="document.getElementById('avatarForm').submit();">
                </form>

                <div class="text-center text-md-start">
                    <h1 class="fw-bold mb-1 text-white" style="font-size: 1.75rem; letter-spacing: -0.02em;"><?php echo htmlspecialchars($full_name ?: 'User Profile'); ?></h1>
                    <div class="opacity-90 mb-3 d-flex align-items-center gap-2 flex-wrap justify-content-center justify-content-md-start" style="font-size: 0.95rem;">
                        <span><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($user['email']); ?></span>
                        <?php if (!empty($user['email_verified'])): ?>
                            <span class="badge bg-success" style="font-size: 0.72rem;"><i class="fas fa-check"></i> Verified</span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 flex-wrap justify-content-center justify-content-md-start">
                        <span class="pill-badge">
                            <i class="fas fa-user-shield text-warning"></i> Role: Registered Client
                        </span>
                        <span class="pill-badge">
                            <i class="fas fa-id-badge text-info"></i> Account #: <?php echo htmlspecialchars($user['account_number'] ?: 'Not Assigned'); ?>
                        </span>
                        <?php if (!empty($user['suffix'])): ?>
                            <span class="pill-badge">
                                <i class="fas fa-tag"></i> Suffix: <?php echo htmlspecialchars($user['suffix']); ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($user['phone_no'])): ?>
                            <span class="pill-badge">
                                <i class="fas fa-phone-alt text-success"></i> <?php echo htmlspecialchars($user['phone_no']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="metrics-grid">
            <div class="metric-card">
                <div class="metric-icon" style="background:#e0f2fe; color:#0284c7;">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div>
                    <div class="metric-val"><?php echo number_format($user_bookings_count); ?></div>
                    <div class="metric-lbl">Book</div>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-icon" style="background:#dcfce7; color:#16a34a;">
                    <i class="fas fa-cross"></i>
                </div>
                <div>
                    <div class="metric-val"><?php echo number_format($user_deceased_count); ?></div>
                    <div class="metric-lbl">Linked Deceased</div>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-icon" style="background:#fef3c7; color:#d97706;">
                    <i class="fas fa-map-marker-alt"></i>
                </div>
                <div>
                    <div class="metric-val"><?php echo number_format($user_plots_count); ?></div>
                    <div class="metric-lbl">Grave Plots</div>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-icon" style="background:#f3e8ff; color:#9333ea;">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div>
                    <div class="metric-val" style="font-size: 1.15rem; color: #16a34a;"><i class="fas fa-check-circle me-1"></i>Active</div>
                    <div class="metric-lbl">Account Status</div>
                </div>
            </div>
        </section>

        <div class="profile-nav-tabs" role="tablist">
            <button type="button" class="profile-tab-btn active" onclick="switchTab('overview', this)">
                <i class="fas fa-id-badge"></i> Account Overview
            </button>
            <button type="button" class="profile-tab-btn" onclick="switchTab('edit', this)">
                <i class="fas fa-user-edit"></i> Edit Profile
            </button>
            <button type="button" class="profile-tab-btn" onclick="switchTab('security', this)">
                <i class="fas fa-key"></i> Password &amp; Security
            </button>
            <button type="button" class="profile-tab-btn" onclick="switchTab('deceased', this)">
                <i class="fas fa-cross"></i> Linked Deceased (<?php echo $user_deceased_count; ?>)
            </button>
        </div>

        <!-- TAB 1: OVERVIEW -->
        <div id="tab-overview" class="content-card tab-pane-content">
            <div class="card-header-line">
                <h3 class="card-header-title">
                    <i class="fas fa-user-circle"></i> User Credentials &amp; Details
                </h3>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="switchTab('edit', document.querySelectorAll('.profile-tab-btn')[1])">
                    <i class="fas fa-edit me-1"></i> Edit Info
                </button>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-user me-1"></i> Full Name</span>
                        <span class="detail-value"><?php echo htmlspecialchars($full_name ?: 'Not Provided'); ?></span>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-envelope me-1"></i> Email Address</span>
                        <span class="detail-value text-primary"><?php echo htmlspecialchars($user['email']); ?></span>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-phone me-1"></i> Contact Number</span>
                        <span class="detail-value"><?php echo htmlspecialchars($user['phone_no'] ?: 'None specified'); ?></span>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-tag me-1"></i> Suffix</span>
                        <span class="detail-value"><?php echo htmlspecialchars($user['suffix'] ?: 'No Suffix'); ?></span>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-venus-mars me-1"></i> Gender</span>
                        <span class="detail-value"><?php echo htmlspecialchars($user['gender'] ?: 'Not Specified'); ?></span>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-id-card me-1"></i> Account Number</span>
                        <span class="detail-value"><?php echo htmlspecialchars($user['account_number'] ?: 'Not Assigned'); ?></span>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-shield-alt me-1"></i> System Role</span>
                        <span class="badge bg-danger px-2 py-1">REGISTERED CLIENT</span>
                    </div>
                </div>

                <div class="col-md-12">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-lock me-1"></i> Authentication Status</span>
                        <span class="badge bg-success px-2 py-1"><i class="fas fa-check-shield me-1"></i> Active &amp; Protected</span>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="small text-muted">
                    <i class="fas fa-shield-check text-success me-1"></i> Logged in as authorized account owner.
                </div>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i> Logout Session
                </a>
            </div>
        </div>

        <!-- TAB 2: EDIT PROFILE -->
        <div id="tab-edit" class="content-card tab-pane-content" style="display:none;">
            <div class="card-header-line">
                <h3 class="card-header-title">
                    <i class="fas fa-user-edit"></i> Modify Profile Information
                </h3>
            </div>

            <form method="POST" action="profile.php">
                <input type="hidden" name="action" value="update_profile">

                <div class="row g-3 mb-3">
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="first_name">First Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" autocomplete="given-name" required>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="middle_name">Middle Name</label>
                        <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($user['middle_name'] ?? ''); ?>" autocomplete="additional-name">
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="last_name">Last Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" autocomplete="family-name" required>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="suffix">Suffix</label>
                        <?php 
                        $curSuffix = strtoupper(trim((string)($user['suffix'] ?? '')));
                        $stdSuffixes = ['JR.', 'SR.', 'II', 'III', 'IV', 'V'];
                        $isOther = !empty($curSuffix) && !in_array($curSuffix, $stdSuffixes, true);
                        ?>
                        <select class="form-select" id="suffix" name="suffix" onchange="toggleSuffixOther(this, 'suffix_other')">
                            <option value="" <?php echo empty($curSuffix) ? 'selected' : ''; ?>>No Suffix</option>
                            <option value="JR." <?php echo ($curSuffix === 'JR.' || $curSuffix === 'JR') ? 'selected' : ''; ?>>JR.</option>
                            <option value="SR." <?php echo ($curSuffix === 'SR.' || $curSuffix === 'SR') ? 'selected' : ''; ?>>SR.</option>
                            <option value="II" <?php echo $curSuffix === 'II' ? 'selected' : ''; ?>>II</option>
                            <option value="III" <?php echo $curSuffix === 'III' ? 'selected' : ''; ?>>III</option>
                            <option value="IV" <?php echo $curSuffix === 'IV' ? 'selected' : ''; ?>>IV</option>
                            <option value="V" <?php echo $curSuffix === 'V' ? 'selected' : ''; ?>>V</option>
                            <option value="OTHER" <?php echo $isOther ? 'selected' : ''; ?>>OTHER</option>
                        </select>
                        <input type="text" class="form-control mt-1" id="suffix_other" name="suffix_other" value="<?php echo $isOther ? htmlspecialchars($curSuffix) : ''; ?>" style="<?php echo $isOther ? 'display:block;' : 'display:none;'; ?>" oninput="this.value = this.value.toUpperCase()">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="gender">Gender</label>
                        <?php $curGender = ucfirst(strtolower(trim((string)($user['gender'] ?? '')))); ?>
                        <select class="form-select" id="gender" name="gender">
                            <option value="">Not Specified</option>
                            <option value="Male" <?php echo ($curGender === 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($curGender === 'Female') ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label" for="email_display">Email / Gmail Address</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span>
                            <input type="email" class="form-control bg-light" id="email_display" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                        </div>
                        <small class="text-muted">Registered Gmail address is tied to your account credentials.</small>
                    </div>
                    <div class="col-md-6 col-lg-5">
                        <label class="form-label" for="phone_no">Phone / Contact Number</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="fas fa-phone text-muted"></i></span>
                            <input type="tel" class="form-control" id="phone_no" name="phone_no" value="<?php echo htmlspecialchars($user['phone_no'] ?? ''); ?>" maxlength="11" minlength="11" pattern="09[0-9]{9}" oninput="var val=this.value.replace(/[^0-9]/g,''); if(val.indexOf('639')===0) val='09'+val.substring(3); else if(val.indexOf('9')===0) val='09'+val.substring(1); else if(val.length>=2 && val.charAt(0)==='0' && val.charAt(1)!=='9') val='0'; else if(val.length===1 && val!=='0') val='09'; this.value=val.slice(0,11);" autocomplete="tel">
                        </div>
                        <small class="text-muted">Contact number for reservation updates.</small>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label" for="account_number">11-Digit Account Number</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="fas fa-id-card text-muted"></i></span>
                            <input type="text" class="form-control" id="account_number" name="account_number" value="<?php echo htmlspecialchars($user['account_number'] ?? ''); ?>" maxlength="11" pattern="[0-9]{11}" inputmode="numeric" autocomplete="off">
                        </div>
                        <small class="text-muted">Must be an 11-digit numeric account ID.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="role_display">Role</label>
                        <input type="text" class="form-control bg-light" id="role_display" value="User Account" disabled>
                    </div>
                </div>

                <div class="d-flex gap-2 flex-wrap mt-4 pt-2 border-top">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Changes</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="switchTab('overview', document.querySelectorAll('.profile-tab-btn')[0])">Cancel</button>
                </div>
            </form>
        </div>

        <!-- TAB 3: PASSWORD & SECURITY -->
        <div id="tab-security" class="content-card tab-pane-content" style="display:none;">
            <div class="card-header-line">
                <h3 class="card-header-title">
                    <i class="fas fa-key"></i> Password &amp; Security Management
                </h3>
            </div>
            <p class="text-muted small mb-3">
                Keep your account secure with a strong password. Must contain uppercase &amp; lowercase letters, at least one number, and one symbol.
            </p>

            <form method="POST" action="profile.php" onsubmit="return validateUserPasswordForm();">
                <input type="hidden" name="action" value="change_password">

                <div class="row g-3 mb-3">
                    <div class="col-md-12">
                        <label class="form-label" for="user_current_password">Current Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="user_current_password" name="current_password" required placeholder="Enter your current password">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePass('user_current_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="user_new_password">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="user_new_password" name="new_password" required minlength="8" placeholder="Create a strong password" autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePass('user_new_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="user_confirm_password">Confirm New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="user_confirm_password" name="confirm_password" required minlength="8" placeholder="Re-enter new password" autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePass('user_confirm_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-12 mt-2">
                        <div class="password-strength-container p-3">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="small fw-bold text-dark"><i class="fas fa-shield-alt text-danger me-1"></i> Password Strength:</span>
                                <span class="badge bg-secondary px-2 py-1" id="userStrengthBadge">Not Entered</span>
                            </div>
                            <div class="strength-bar-track">
                                <div class="strength-segment" id="userSeg1"></div>
                                <div class="strength-segment" id="userSeg2"></div>
                                <div class="strength-segment" id="userSeg3"></div>
                            </div>
                            <div class="row g-2">
                                <div class="col-sm-6 criteria-item" id="userCritLen">
                                    <i class="fas fa-circle"></i> <span>At least 8 characters</span>
                                </div>
                                <div class="col-sm-6 criteria-item" id="userCritCase">
                                    <i class="fas fa-circle"></i> <span>Upper &amp; lowercase letters</span>
                                </div>
                                <div class="col-sm-6 criteria-item" id="userCritNum">
                                    <i class="fas fa-circle"></i> <span>At least 1 number (0-9)</span>
                                </div>
                                <div class="col-sm-6 criteria-item" id="userCritSym">
                                    <i class="fas fa-circle"></i> <span>At least 1 symbol (!@#$%^&amp;*)</span>
                                </div>
                            </div>
                        </div>
                        <div class="password-match-indicator" id="userMatchIndicator"></div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3 pt-3 border-top">
                    <button type="submit" class="btn btn-danger px-4 fw-bold">
                        <i class="fas fa-key me-1"></i> Update Password
                    </button>
                </div>
            </form>

            <div class="danger-card">
                <div class="danger-card-title">
                    <i class="fas fa-exclamation-triangle text-danger"></i> Danger Zone — Delete Account
                </div>
                <p class="text-muted mb-3" style="font-size: 0.92rem; line-height: 1.6;">
                    Permanently delete your user account from the system. This will remove your personal credentials, and <strong>automatically release all your reserved plots back to available</strong> on the cemetery plot maps and database, while purging associated reservation logs. Any linked deceased records will remain safely preserved in cemetery archives.
                </p>
                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                    <i class="fas fa-trash-alt me-1"></i> Delete Account
                </button>
            </div>
        </div>

        <!-- TAB 4: LINKED DECEASED -->
        <div id="tab-deceased" class="content-card tab-pane-content" style="display:none;">
            <div class="card-header-line">
                <h3 class="card-header-title">
                    <i class="fas fa-cross"></i> Linked Deceased Record(s)
                </h3>
                <a href="user_dashboard.php?open=booking" class="btn btn-sm btn-outline-danger">
                    <i class="fas fa-plus me-1"></i> Book Cemetery Service
                </a>
            </div>

            <?php if (!empty($linked_deceased)): ?>
                <div class="row g-3">
                    <?php foreach ($linked_deceased as $dec): ?>
                        <?php 
                        $decFullName = trim(implode(' ', array_filter([$dec['first_name'] ?? '', $dec['middle_name'] ?? '', $dec['last_name'] ?? '', $dec['suffix'] ?? ''])));
                        $hasPlot = (!empty($dec['plot_x']) && !empty($dec['plot_y']) && strtoupper($dec['section']) !== 'UNASSIGNED');
                        ?>
                        <div class="col-12">
                            <div class="deceased-record-card">
                                <div>
                                    <h5 class="fw-bold mb-1" style="color: #7b1113; font-size: 1.05rem;">
                                        <i class="fas fa-cross me-1 text-secondary"></i> <?php echo htmlspecialchars($decFullName); ?>
                                    </h5>
                                    <div class="small text-muted">
                                        <strong>Grave Address:</strong> 
                                        <?php if ($hasPlot): ?>
                                            <span class="badge bg-success"><?php echo htmlspecialchars(strtoupper($dec['section']) . ' — Plot ' . $dec['plot_x'] . '-' . $dec['plot_y']); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Unassigned Plot</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div>
                                    <a href="user_dashboard.php?open=booking" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-calendar-plus me-1"></i> Reserve Service
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-info-circle me-1"></i> No deceased family member is currently linked to this user account.
                </div>
            <?php endif; ?>
        </div>
    </main>

    <div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-labelledby="deleteAccountModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 1.25rem; overflow: hidden; border: none; box-shadow: 0 15px 50px rgba(0,0,0,0.25);">
                <div class="modal-header bg-danger text-white py-3">
                    <h5 class="modal-title fw-bold" id="deleteAccountModalLabel">
                        <i class="fas fa-exclamation-triangle me-2"></i> Delete Account Confirmation
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="profile.php">
                    <input type="hidden" name="action" value="delete_account">
                    <div class="modal-body p-4">
                        <div class="alert alert-danger py-2 mb-3" style="font-size: 0.9rem;">
                            <strong>Warning:</strong> This action is permanent and cannot be undone.
                        </div>
                        <p class="text-secondary mb-3" style="font-size: 0.92rem; line-height: 1.6;">
                            Are you sure you want to permanently delete your account?
                        </p>
                        <ul class="text-muted small mb-3 ps-3" style="line-height: 1.8;">
                            <li>Your personal profile credentials and session will be deleted immediately.</li>
                            <li><strong>Plot Reservations:</strong> All your plot reservations will be automatically deleted and released back to available on plot maps and the cemetery database.</li>
                            <li><strong>Logs &amp; Bookings:</strong> Associated reservation logs and booking records will be automatically cleared.</li>
                            <li>Linked deceased family records will be safely preserved in the cemetery database.</li>
                            <li>Your Gmail address (<strong class="text-dark"><?php echo htmlspecialchars($user['email']); ?></strong>) will become available for new registrations.</li>
                        </ul>
                        <div class="mb-3">
                            <label class="form-label small fw-bold" for="confirm_email">
                                Please type your email (<span class="text-danger"><?php echo htmlspecialchars($user['email']); ?></span>) to confirm:
                            </label>
                            <input type="email" class="form-control" id="confirm_email" name="confirm_email" placeholder="<?php echo htmlspecialchars($user['email']); ?>" required autocomplete="off">
                        </div>
                    </div>
                    <div class="modal-footer bg-light py-3 px-4">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger fw-semibold" id="confirmDeleteBtn">
                            <i class="fas fa-trash-alt me-1"></i> Yes, Permanently Delete My Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function switchTab(tabId, btn) {
            document.querySelectorAll('.profile-tab-btn').forEach(function(b) {
                b.classList.remove('active');
            });
            document.querySelectorAll('.tab-pane-content').forEach(function(p) {
                p.style.display = 'none';
            });

            if (btn) btn.classList.add('active');
            const pane = document.getElementById('tab-' + tabId);
            if (pane) pane.style.display = 'block';
        }

        function togglePass(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                if (icon) { icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
            } else {
                input.type = 'password';
                if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
            }
        }

        function toggleSuffixOther(sel, otherId) {
            const other = document.getElementById(otherId);
            if (!other) return;
            if (sel.value === 'OTHER') {
                other.style.display = 'block';
                other.focus();
            } else {
                other.style.display = 'none';
                other.value = '';
            }
        }

        // Live Password Strength and Match Evaluator for User Profile
        const userNewPass = document.getElementById('user_new_password');
        const userConfPass = document.getElementById('user_confirm_password');
        const userBadge = document.getElementById('userStrengthBadge');
        const userSeg1 = document.getElementById('userSeg1');
        const userSeg2 = document.getElementById('userSeg2');
        const userSeg3 = document.getElementById('userSeg3');
        const userMatchInd = document.getElementById('userMatchIndicator');

        const uCritLen = document.getElementById('userCritLen');
        const uCritCase = document.getElementById('userCritCase');
        const uCritNum = document.getElementById('userCritNum');
        const uCritSym = document.getElementById('userCritSym');

        function updateCrit(el, isMet) {
            if (!el) return;
            const icon = el.querySelector('i');
            if (isMet) {
                el.classList.add('met');
                if (icon) icon.className = 'fas fa-check-circle text-success';
            } else {
                el.classList.remove('met');
                if (icon) icon.className = 'fas fa-circle text-muted opacity-50';
            }
        }

        function evaluateUserStrength(pwd) {
            if (!pwd || pwd.length === 0) {
                updateCrit(uCritLen, false);
                updateCrit(uCritCase, false);
                updateCrit(uCritNum, false);
                updateCrit(uCritSym, false);
                if (userSeg1) userSeg1.className = 'strength-segment';
                if (userSeg2) userSeg2.className = 'strength-segment';
                if (userSeg3) userSeg3.className = 'strength-segment';
                if (userBadge) {
                    userBadge.className = 'badge bg-secondary px-2 py-1';
                    userBadge.textContent = 'Not Entered';
                }
                return;
            }

            const hasLen = pwd.length >= 8;
            const hasCase = /[A-Z]/.test(pwd) && /[a-z]/.test(pwd);
            const hasNum = /[0-9]/.test(pwd);
            const hasSym = /[^A-Za-z0-9]/.test(pwd);

            updateCrit(uCritLen, hasLen);
            updateCrit(uCritCase, hasCase);
            updateCrit(uCritNum, hasNum);
            updateCrit(uCritSym, hasSym);

            let score = 0;
            if (hasLen) score++;
            if (hasCase) score++;
            if (hasNum) score++;
            if (hasSym) score++;
            if (pwd.length >= 12) score++;

            if (userSeg1) userSeg1.className = 'strength-segment';
            if (userSeg2) userSeg2.className = 'strength-segment';
            if (userSeg3) userSeg3.className = 'strength-segment';

            if (!hasLen || score <= 2) {
                if (userSeg1) userSeg1.classList.add('active-weak');
                if (userBadge) {
                    userBadge.className = 'badge bg-danger px-2 py-1';
                    userBadge.textContent = 'Weak';
                }
            } else if (score === 3 || score === 4) {
                if (userSeg1) userSeg1.classList.add('active-medium');
                if (userSeg2) userSeg2.classList.add('active-medium');
                if (userBadge) {
                    userBadge.className = 'badge bg-warning text-dark px-2 py-1';
                    userBadge.textContent = 'Medium';
                }
            } else {
                if (userSeg1) userSeg1.classList.add('active-strong');
                if (userSeg2) userSeg2.classList.add('active-strong');
                if (userSeg3) userSeg3.classList.add('active-strong');
                if (userBadge) {
                    userBadge.className = 'badge bg-success px-2 py-1';
                    userBadge.textContent = 'Strong';
                }
            }
        }

        function evaluateUserMatch() {
            if (!userConfPass || !userMatchInd) return;
            const p1 = userNewPass ? userNewPass.value : '';
            const p2 = userConfPass.value;

            if (!p2 || p2.length === 0) {
                userMatchInd.innerHTML = '';
                userMatchInd.className = 'password-match-indicator';
                return;
            }

            if (p1 === p2) {
                userMatchInd.className = 'password-match-indicator matching';
                userMatchInd.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match';
            } else {
                userMatchInd.className = 'password-match-indicator mismatch';
                userMatchInd.innerHTML = '<i class="fas fa-times-circle"></i> Passwords do not match';
            }
        }

        if (userNewPass) {
            userNewPass.addEventListener('input', function() {
                evaluateUserStrength(this.value);
                evaluateUserMatch();
            });
        }

        if (userConfPass) {
            userConfPass.addEventListener('input', evaluateUserMatch);
        }

        function validateUserPasswordForm() {
            const p1 = userNewPass ? userNewPass.value : '';
            const p2 = userConfPass ? userConfPass.value : '';

            if (p1.length < 8) {
                alert('New password must be at least 8 characters long.');
                return false;
            }
            if (!/[A-Z]/.test(p1) || !/[a-z]/.test(p1)) {
                alert('Password must contain both uppercase and lowercase letters.');
                return false;
            }
            if (!/[0-9]/.test(p1)) {
                alert('Password must contain at least one number (0-9).');
                return false;
            }
            if (!/[^A-Za-z0-9]/.test(p1)) {
                alert('Password must contain at least one symbol or special character (e.g. !@#$%^&*).');
                return false;
            }
            if (p1 !== p2) {
                alert('New password and confirmation password do not match.');
                return false;
            }
            return true;
        }

        <?php if (isset($_POST['action']) && $_POST['action'] === 'change_password'): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const secBtn = document.querySelectorAll('.profile-tab-btn')[2];
                if (secBtn) switchTab('security', secBtn);
            });
        <?php elseif (isset($_POST['action']) && $_POST['action'] === 'update_profile' && !empty($errors)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const editBtn = document.querySelectorAll('.profile-tab-btn')[1];
                if (editBtn) switchTab('edit', editBtn);
            });
        <?php endif; ?>
    </script>
    <script src="includes/session_tab_guard.js"></script>
    <script>
    if (window.GorSessionGuard) {
        GorSessionGuard.init('user_dashboard');
    }
    </script>
</body>
</html>

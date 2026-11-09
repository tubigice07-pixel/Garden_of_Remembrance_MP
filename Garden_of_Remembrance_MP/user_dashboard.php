<?php
$dashboardDebug = !empty($_GET['debug']) && $_GET['debug'] === '1';
if ($dashboardDebug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
register_shutdown_function(function () use ($dashboardDebug) {
    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($error && in_array($error['type'], $fatalTypes, true)) {
        error_log('user_dashboard.php fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        if ($dashboardDebug && !headers_sent()) {
            http_response_code(500);
            echo '<pre style="white-space:pre-wrap;font:14px monospace;padding:20px;">'
                . htmlspecialchars($error['message'] . "\n" . $error['file'] . ':' . $error['line'], ENT_QUOTES, 'UTF-8')
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
$connectionFile = __DIR__ . '/connection.php';
$paymentSettingsFile = __DIR__ . '/config/payment_settings.php';
$notificationComponentFile = is_file(__DIR__ . '/includes/notification_component.php') ? __DIR__ . '/includes/notification_component.php' : __DIR__ . '/notification_component.php';
$notificationSchemaFile = is_file(__DIR__ . '/includes/notification_schema.php') ? __DIR__ . '/includes/notification_schema.php' : __DIR__ . '/notification_schema.php';
$applicationBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$transferRequestsUrl = ($applicationBasePath === '' ? '' : $applicationBasePath . '/') . 'transfer_requests.php';

if (is_file($connectionFile)) {
    require_once $connectionFile;
}
if (is_file($paymentSettingsFile)) {
    require_once $paymentSettingsFile;
}
if (is_file($notificationComponentFile)) {
    require_once $notificationComponentFile;
}
if (is_file($notificationSchemaFile)) {
    require_once $notificationSchemaFile;
}

if (!function_exists('format_plot_number')) {
    function format_plot_number($plot, $section = '') {
        $raw = trim((string)$plot);
        $sec = strtoupper(trim((string)$section));
        if ($raw === '' && $sec === '') {
            return '—';
        }
        if (preg_match('/^(?:([A-Za-z0-9]+)-)?(\d{3})(\d{3})$/', $raw, $matches)) {
            return strtoupper($matches[1] ?? ($sec ?: 'A')) . '- ' . (int)$matches[2] . ',' . (int)$matches[3];
        }
        if (preg_match('/^(?:([A-Za-z0-9]+)[-_\s]+)?(\d+)[-_,\s]+(\d+)$/', $raw, $matches)) {
            return strtoupper($matches[1] ?? ($sec ?: 'A')) . '- ' . (int)$matches[2] . ',' . (int)$matches[3];
        }
        return $sec !== '' && $raw !== '' ? $sec . '- ' . $raw : ($raw ?: $sec);
    }
}

$gcash_payment = function_exists('get_gcash_payment_settings') ? get_gcash_payment_settings() : [];

if (empty($_SESSION['user_id'])) {
    header("Location: login.php?auth_required=1");
    exit;
}

// Admin accounts may also view user_dashboard when opened in a separate tab

if (!function_exists('is_db_connected') || !is_db_connected()) {
    http_response_code(503);
    exit("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>Service Temporarily Unavailable</h2><p>Database connection is momentarily offline. Please refresh in a moment.</p><p><a href='index.php'>Return to Homepage</a></p></div>");
}

$user_id = (int)$_SESSION['user_id'];
$user = null;

try {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $uStmt = @$conn->prepare("SELECT user_id, first_name, last_name, email, phone_no, profile_picture, account_number, role FROM tbl_user WHERE user_id = ? LIMIT 1");
        if ($uStmt) {
            $uStmt->bind_param('i', $user_id);
            $uStmt->execute();
            $uRes = $uStmt->get_result();
            $user = $uRes ? $uRes->fetch_assoc() : null;
            $uStmt->close();
        }
    }
} catch (Throwable $uEx) {
    error_log('User dashboard user query error: ' . $uEx->getMessage());
}

if (!$user) {
    try {
        if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
            $uStmt2 = @$conn->prepare("SELECT user_id, first_name, last_name, email, role FROM tbl_user WHERE user_id = ? LIMIT 1");
            if ($uStmt2) {
                $uStmt2->bind_param('i', $user_id);
                $uStmt2->execute();
                $uRes2 = $uStmt2->get_result();
                $user = $uRes2 ? $uRes2->fetch_assoc() : null;
                $uStmt2->close();
            }
        }
    } catch (Throwable $uEx2) {}
}

if (!$user && !empty($_SESSION['email'])) {
    try {
        if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
            $sEmail = strtolower(trim((string)$_SESSION['email']));
            $eStmt = @$conn->prepare("SELECT user_id, first_name, last_name, email, role FROM tbl_user WHERE LOWER(TRIM(email)) = ? LIMIT 1");
            if ($eStmt) {
                $eStmt->bind_param('s', $sEmail);
                $eStmt->execute();
                $eRes = $eStmt->get_result();
                $user = $eRes ? $eRes->fetch_assoc() : null;
                $eStmt->close();
                if ($user) {
                    $user_id = (int)$user['user_id'];
                    $_SESSION['user_id'] = $user_id;
                }
            }
        }
    } catch (Throwable $eEx) {}
}

if (!$user) {

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain']);
    }
    session_destroy();
    header("Location: login.php?auth_required=1");
    exit;
}

if (empty($_SESSION['role'])) {
    $_SESSION['role'] = strtoupper(trim((string)($user['role'] ?? 'USER')));
}

// Allow admin to view user portal in multi-tab scenario

$returnBookingData = null;
if (!empty($_GET['booking_id']) && (!empty($_GET['booking_fillup_return']) || !empty($_GET['user_pay_return']) || !empty($_GET['open_booking']) || !empty($_GET['open_booking_modal']))) {
    $rBkId = (int)$_GET['booking_id'];
    if ($rBkId > 0 && isset($conn) && $conn instanceof mysqli) {
        $rStmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? AND user_id = ? LIMIT 1");
        if ($rStmt) {
            $rStmt->bind_param('ii', $rBkId, $user_id);
            $rStmt->execute();
            $rRes = $rStmt->get_result();
            $returnBookingData = $rRes ? $rRes->fetch_assoc() : null;
            $rStmt->close();
        }
        if ($returnBookingData) {
            $isLatestMonthly = false;
            $latestInstallmentNum = 0;
            $rcptQ = $conn->query("SELECT id, installment_number, payment_schedule_id, created_at FROM payment_receipts WHERE booking_id = {$rBkId} ORDER BY id DESC LIMIT 1");
            if ($rcptQ && ($rcRow = $rcptQ->fetch_assoc())) {
                if (!empty($rcRow['installment_number']) && (int)$rcRow['installment_number'] > 0) {
                    $isLatestMonthly = true;
                    $latestInstallmentNum = (int)$rcRow['installment_number'];
                }
            }
            $returnBookingData['is_monthly_payment'] = $isLatestMonthly;
            $returnBookingData['installment_number'] = $latestInstallmentNum;

            $bpList = [];
            if ($conn->query("SHOW TABLES LIKE 'booking_plots'")->num_rows > 0) {
                $bpStmt = $conn->prepare("SELECT bp.*, tp.lawn_type, tp.price FROM booking_plots bp LEFT JOIN tbl_plots tp ON ((bp.plot_id > 0 AND bp.plot_id = tp.plot_id) OR (bp.plot_x = tp.plot_x AND bp.plot_y = tp.plot_y AND UPPER(TRIM(bp.section)) = UPPER(TRIM(tp.section)))) WHERE bp.booking_id = ?");
                if ($bpStmt) {
                    $bpStmt->bind_param('i', $rBkId);
                    $bpStmt->execute();
                    $bpRes = $bpStmt->get_result();
                    while ($bpRow = $bpRes->fetch_assoc()) {
                        $bpList[] = $bpRow;
                    }
                    $bpStmt->close();
                }
            }
            // If booking_plots was empty, synthesize from tbl_bookings row
            if (empty($bpList) && !empty($returnBookingData['plot'])) {
                $rawP = trim((string)$returnBookingData['plot']);
                $secP = strtoupper(trim((string)($returnBookingData['section'] ?? 'A')));
                $pxP = 0; $pyP = 0;
                if (preg_match('/^(\d+)[\s\-_,]+(\d+)$/', $rawP, $mP)) {
                    $pxP = (int)$mP[1]; $pyP = (int)$mP[2];
                } elseif (is_numeric($rawP)) {
                    $pxP = (int)$rawP; $pyP = 1;
                }
                $bpList[] = [
                    'plot_id' => 0,
                    'section' => $secP,
                    'plot_x' => $pxP,
                    'plot_y' => $pyP,
                    'plot_number' => $rawP,
                    'lawn_type' => $returnBookingData['plot_type'] ?? 'Regular',
                    'price' => (float)($returnBookingData['plot_price'] ?? ($returnBookingData['total_amount'] ?? 85000))
                ];
            }
            $returnBookingData['_booking_plots'] = $bpList;
        }
    }
}

// AJAX: Fetch specific booking details for user booking details modal
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_user_booking_details') {
    header('Content-Type: application/json; charset=utf-8');
    $bkId = (int)($_POST['booking_id'] ?? 0);
    if ($bkId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
        exit;
    }
    $isAdminFlag = ($is_admin ? 1 : 0);
    $stmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? AND (user_id = ? OR ? = 1) LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('iii', $bkId, $user_id, $isAdminFlag);
        $stmt->execute();
        $res = $stmt->get_result();
        $bRow = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    } else {
        $bRow = null;
    }
    if (!$bRow) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }

    $pRef = $bRow['reference_number'] ?? '';
    $pDate = $bRow['created_at'] ?? '';
    $pAmt = (float)($bRow['amount_paid'] ?? 0);
    $rcptStmt = $conn->prepare("SELECT * FROM payment_receipts WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
    if ($rcptStmt) {
        $rcptStmt->bind_param('i', $bkId);
        $rcptStmt->execute();
        $rcptRes = $rcptStmt->get_result();
        if ($rcptRes && ($rcptRow = $rcptRes->fetch_assoc())) {
            if (!empty($rcptRow['reference_number'])) $pRef = $rcptRow['reference_number'];
            if (!empty($rcptRow['payment_date'])) $pDate = $rcptRow['payment_date'];
            if ((float)($rcptRow['amount_paid'] ?? 0) > 0) $pAmt = (float)$rcptRow['amount_paid'];
        }
        $rcptStmt->close();
    }

    echo json_encode([
        'success' => true,
        'booking' => $bRow,
        'latest_payment' => [
            'reference' => $pRef,
            'date' => $pDate,
            'amount' => $pAmt
        ]
    ]);
    exit;
}

$claim_feedback = null;
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'claim_plot') {
    $claim_sec = strtoupper(trim((string)($_POST['section'] ?? '')));
    $raw_plot = trim((string)($_POST['plot_number'] ?? ''));
    $claim_dec = trim((string)($_POST['deceased_reference'] ?? ''));
    $claim_notes = trim((string)($_POST['notes'] ?? ''));

    if (!in_array($claim_sec, ['A', 'B', 'C', 'D', 'E', 'F'])) {
        $claim_feedback = ['type' => 'danger', 'message' => 'Please select a valid section (Section A to F).'];
    } else {
        $px = 0; $py = 0;
        if (preg_match('/^(\d+)[\s\-_,]+(\d+)$/', $raw_plot, $m)) {
            $px = (int)$m[1]; $py = (int)$m[2];
        } elseif (is_numeric($raw_plot)) {
            $px = (int)$raw_plot; $py = 1;
        }

        if ($px <= 0 || $py <= 0) {
            $claim_feedback = ['type' => 'danger', 'message' => 'Please enter a valid plot number (e.g. 10-1 or 10).'];
        } else {

            $dupStmt = $conn->prepare("SELECT id, verification_status FROM user_plot_ownership WHERE user_id = ? AND UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
            $dupStmt->bind_param('isii', $user_id, $claim_sec, $px, $py);
            $dupStmt->execute();
            $dupRes = $dupStmt->get_result();
            if ($dupRes && $dupRes->num_rows > 0) {
                $dupRow = $dupRes->fetch_assoc();
                $claim_feedback = ['type' => 'warning', 'message' => "You have already registered Section {$claim_sec} — Plot {$px}-{$py} (Status: {$dupRow['verification_status']})."];
                $dupStmt->close();
            } else {
                $dupStmt->close();

                $confStmt = $conn->prepare("SELECT u.first_name, u.last_name FROM user_plot_ownership upo JOIN tbl_user u ON upo.user_id = u.user_id WHERE UPPER(TRIM(upo.section)) = ? AND upo.plot_x = ? AND upo.plot_y = ? AND upo.verification_status = 'VERIFIED' AND upo.user_id != ? LIMIT 1");
                $confStmt->bind_param('siii', $claim_sec, $px, $py, $user_id);
                $confStmt->execute();
                $confRes = $confStmt->get_result();
                $note_extra = '';
                if ($confRes && ($confRow = $confRes->fetch_assoc())) {
                    $note_extra = " [Note: Conflicting claim with verified owner {$confRow['first_name']} {$confRow['last_name']}]";
                }
                $confStmt->close();

                $full_notes = trim($claim_notes . $note_extra);
                $claim_src = 'DASHBOARD_CLAIM';
                $ver_stat = 'PENDING';
                $own_stat = 'OWNED';

                $insStmt = $conn->prepare("INSERT INTO user_plot_ownership (user_id, section, plot_x, plot_y, ownership_status, verification_status, claim_source, deceased_reference, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE notes = CONCAT(COALESCE(notes,''), ' | Resubmitted claim: ', VALUES(notes))");
                if ($insStmt) {
                    $insStmt->bind_param('isiisssss', $user_id, $claim_sec, $px, $py, $own_stat, $ver_stat, $claim_src, $claim_dec, $full_notes);
                    if ($insStmt->execute()) {
                        $logDetails = "User ID #{$user_id} submitted a claim for Section {$claim_sec}, Plot {$px}-{$py}" . (!empty($claim_dec) ? " (Deceased: {$claim_dec})" : "");
                        if ($logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('PLOT_CLAIM_SUBMITTED', ?, ?, NOW())")) {
                            $logStmt->bind_param('si', $logDetails, $user_id);
                            $logStmt->execute();
                            $logStmt->close();
                        }
                        if (function_exists('notify_administrators')) {
                            notify_administrators($conn, 'New Plot Claim Submitted', 'A user submitted a claim for Section ' . $claim_sec . ', Plot ' . $px . '-' . $py . '.', 'claims_review.php');
                        }
                        $claim_feedback = ['type' => 'success', 'message' => "Plot Section {$claim_sec} — Plot {$px}-{$py} has been submitted for administrator verification."];
                    } else {
                        $claim_feedback = ['type' => 'danger', 'message' => 'Database error submitting claim: ' . $conn->error];
                    }
                    $insStmt->close();
                }
            }
        }
    }
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'user_delete_rejected_claim') {
    $claim_id = (int)($_POST['claim_id'] ?? 0);
    if ($claim_id > 0) {
        $delStmt = $conn->prepare("DELETE FROM user_plot_ownership WHERE id = ? AND user_id = ? AND verification_status = 'REJECTED'");
        if ($delStmt) {
            $delStmt->bind_param('ii', $claim_id, $user_id);
            if ($delStmt->execute() && $delStmt->affected_rows > 0) {
                $claim_feedback = ['type' => 'success', 'message' => 'The rejected plot record has been removed from your account.'];
            } else {
                $claim_feedback = ['type' => 'warning', 'message' => 'Plot record could not be removed or was already deleted.'];
            }
            $delStmt->close();
        }
    }
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'user_add_deceased') {
    $sec = strtoupper(trim((string)($_POST['section'] ?? '')));
    $px = (int)($_POST['plot_x'] ?? 0);
    $py = (int)($_POST['plot_y'] ?? 0);
    $fn = strtoupper(trim((string)($_POST['first_name'] ?? '')));
    $mn = strtoupper(trim((string)($_POST['middle_name'] ?? '')));
    $ln = strtoupper(trim((string)($_POST['last_name'] ?? '')));
    $suf = strtoupper(trim((string)($_POST['suffix'] ?? '')));
    if ($suf === 'OTHER') {
        $suf = strtoupper(trim((string)($_POST['suffix_other'] ?? '')));
    }
    $suf = strip_tags($suf);
    $bday = trim((string)($_POST['bday'] ?? ''));
    $died = trim((string)($_POST['date_died'] ?? ''));
    $rel = strtoupper(trim((string)($_POST['relationship'] ?? '')));

    $today = date('Y-m-d');
    if (!empty($bday) && $bday > $today) {
        $claim_feedback = ['type' => 'danger', 'message' => 'Date of birth cannot be a future date.'];
    } elseif (!empty($died) && $died > $today) {
        $claim_feedback = ['type' => 'danger', 'message' => 'Date of death cannot be a future date.'];
    } elseif (!empty($bday) && !empty($died) && $died < $bday) {
        $claim_feedback = ['type' => 'danger', 'message' => 'Date of death cannot be earlier than date of birth.'];
    } elseif (!$sec || $px <= 0 || $py <= 0 || !$fn || !$ln || !$died) {
        $claim_feedback = ['type' => 'danger', 'message' => 'First name, last name, and date of death are required.'];
    } else {
        $isOwner = function_exists('is_user_authorized_for_plot') 
            ? is_user_authorized_for_plot($conn, $user_id, $sec, $px, $py) 
            : false;

        if (!$isOwner) {
            $ownChk = $conn->prepare("SELECT id FROM user_plot_ownership WHERE user_id = ? AND UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND verification_status IN ('VERIFIED', 'PENDING') LIMIT 1");
            if ($ownChk) {
                $ownChk->bind_param('isii', $user_id, $sec, $px, $py);
                $ownChk->execute();
                $ownChkRes = $ownChk->get_result();
                $isOwner = ($ownChkRes && $ownChkRes->num_rows > 0);
                $ownChk->close();
            }
        }

        if (!$isOwner) {
            $claim_feedback = ['type' => 'danger', 'message' => 'You do not have verified authorization for this plot.'];
        } else {
            $bdayVal = !empty($bday) ? $bday : null;
            $diedVal = !empty($died) ? $died : null;

            // Check if deceased is already registered on this plot
            $dupDec = $conn->prepare("SELECT deceased_id FROM tbl_deceased WHERE UPPER(TRIM(first_name)) = ? AND UPPER(TRIM(last_name)) = ? AND UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
            $isDupDec = false;
            if ($dupDec) {
                $dupDec->bind_param('sssii', $fn, $ln, $sec, $px, $py);
                $dupDec->execute();
                $isDupDec = ($dupDec->get_result()->num_rows > 0);
                $dupDec->close();
            }

            if ($isDupDec) {
                $claim_feedback = ['type' => 'warning', 'message' => "Deceased record for {$fn} {$ln} is already recorded on Section {$sec} Plot {$px}-{$py}."];
            } else {

            $cntCheck = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
            $cntRow = $cntCheck ? $cntCheck->fetch_assoc() : ['c' => 0];
            if (intval($cntRow['c'] ?? 0) >= 4) {
                $claim_feedback = ['type' => 'danger', 'message' => 'This plot has reached the maximum capacity of 4 occupants. No additional deceased can be added.'];
            } else {

                $intervalCheck = check_plot_interment_interval_eligibility($conn, $sec, $px, $py, $diedVal);
                if (!$intervalCheck['is_eligible']) {
                    $claim_feedback = ['type' => 'danger', 'message' => $intervalCheck['message']];
                } else {
                    $decIns = $conn->prepare("INSERT INTO tbl_deceased (first_name, middle_name, last_name, suffix, bday, date_died, interment_date, section, plot_x, plot_y, user_id, relationship_to_account_holder, added_by, edited_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($decIns) {
                        $userBooker = 'User #' . $user_id;
                        $intermentVal = $diedVal;
                        $decIns->bind_param('ssssssssiiisss', $fn, $mn, $ln, $suf, $bdayVal, $diedVal, $intermentVal, $sec, $px, $py, $user_id, $rel, $userBooker, $userBooker);
                        if ($decIns->execute()) {
                            $new_dec_id = $conn->insert_id;
                            $decIns->close();

                            @$conn->query("UPDATE tbl_plots SET status = 'occupied' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
                            @$conn->query("UPDATE plots SET status = 'Occupied' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");

                            $fullName = trim($fn . ' ' . $ln . ' ' . $suf);
                            $upd = $conn->prepare("UPDATE user_plot_ownership SET ownership_status = 'OWNED', deceased_reference = ? WHERE user_id = ? AND section = ? AND plot_x = ? AND plot_y = ?");
                            $upd->bind_param('sisis', $fullName, $user_id, $sec, $px, $py);
                            $upd->execute();
                            $upd->close();

                            if (empty($_SESSION['deceased_id'])) {
                                $_SESSION['deceased_id'] = $new_dec_id;
                            }

                            $claim_feedback = ['type' => 'success', 'message' => "Deceased record for {$fullName} has been added to Section {$sec} Plot {$px}-{$py}!"];
                        } else {
                            $claim_feedback = ['type' => 'danger', 'message' => 'Database error: ' . $conn->error];
                            $decIns->close();
                        }
                    }
                }
            }
        }
    }
}
}

if (function_exists('sync_user_existing_cemetery_records')) {
    sync_user_existing_cemetery_records($conn, $user_id, true);
}

$my_plots = function_exists('get_user_owned_plots') ? get_user_owned_plots($conn, $user_id) : [];

$family_deceased = function_exists('get_user_linked_deceased_records') ? get_user_linked_deceased_records($conn, $user_id) : [];
$max_allowed_user_plots = 2; // Strict limit: Maximum 2 plots for new grave reservation
$user_owned_plots_keys = [];
if (!empty($my_plots)) {
    foreach ($my_plots as $mp) {
        $sec = strtoupper(trim($mp['section'] ?? ''));
        $x = (int)($mp['plot_x'] ?? 0);
        $y = (int)($mp['plot_y'] ?? 0);
        if ($sec && $x > 0 && $y > 0) {
            $user_owned_plots_keys[] = "{$sec}_{$x}_{$y}";
        }
    }
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$profile_image = 'uploads/profile_pictures/default_avatar.png';
if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/' . $user['profile_picture'])) {
    $profile_image = $user['profile_picture'];
}

$booking_stats = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'completed' => 0
];

try {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $uEmail = !empty($user['email']) ? $conn->real_escape_string(strtolower(trim((string)$user['email']))) : '';
        $hasSourceCol = false;
        $chkCol = @$conn->query("SHOW COLUMNS FROM tbl_bookings LIKE 'source'");
        if ($chkCol && $chkCol->num_rows > 0) {
            $hasSourceCol = true;
        }
        $bSourceCond = $hasSourceCol ? "AND (source != 'admin' OR source IS NULL" . ($uEmail !== '' ? " OR LOWER(TRIM(customer_email)) = '$uEmail'" : "") . ")" : "";
        $bSourceCondB = $hasSourceCol ? "AND (b.source != 'admin' OR b.source IS NULL" . ($uEmail !== '' ? " OR LOWER(TRIM(b.customer_email)) = '$uEmail'" : "") . ")" : "";

        $booking_query = @$conn->query("SELECT COUNT(*) as total FROM tbl_bookings WHERE user_id = $user_id $bSourceCond");
        if ($booking_query && ($row = $booking_query->fetch_assoc())) {
            $booking_stats['total'] = (int)$row['total'];
        }

        $pending_query = @$conn->query("SELECT COUNT(*) as pending FROM tbl_bookings WHERE user_id = $user_id AND status = 'pending' $bSourceCond");
        if ($pending_query && ($row = $pending_query->fetch_assoc())) {
            $booking_stats['pending'] = (int)$row['pending'];
        }

        $approved_query = @$conn->query("SELECT COUNT(*) as approved FROM tbl_bookings WHERE user_id = $user_id AND status = 'approved' $bSourceCond");
        if ($approved_query && ($row = $approved_query->fetch_assoc())) {
            $booking_stats['approved'] = (int)$row['approved'];
        }

        $completed_query = @$conn->query("SELECT COUNT(*) as completed FROM tbl_bookings WHERE user_id = $user_id AND status = 'completed' $bSourceCond");
        if ($completed_query && ($row = $completed_query->fetch_assoc())) {
            $booking_stats['completed'] = (int)$row['completed'];
        }
    }
} catch (Throwable $bStatEx) {
    error_log('User dashboard stats query error: ' . $bStatEx->getMessage());
}

$recent_bookings = [];
try {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $uEmail = !empty($user['email']) ? $conn->real_escape_string(strtolower(trim((string)$user['email']))) : '';
        $hasSourceCol = false;
        $chkCol = @$conn->query("SHOW COLUMNS FROM tbl_bookings LIKE 'source'");
        if ($chkCol && $chkCol->num_rows > 0) {
            $hasSourceCol = true;
        }
        $bSourceCondB = $hasSourceCol ? "AND (b.source != 'admin' OR b.source IS NULL" . ($uEmail !== '' ? " OR LOWER(TRIM(b.customer_email)) = '$uEmail'" : "") . ")" : "";

        $bookings_query = @$conn->query("SELECT b.*, 
            COALESCE(pr.proof_filename, b.payment_proof_filename) AS payment_proof_filename, 
            COALESCE(pr.payment_date, b.payment_proof_uploaded_at) AS payment_proof_uploaded_at, 
            COALESCE(pr.gcash_reference_number, b.gcash_reference_number) AS gcash_reference_number,
            COALESCE(pr.amount_paid, b.amount_paid, 0) AS receipt_amount_paid,
            pr.receipt_number AS official_receipt_number,
            pr.id AS payment_id,
            pr.payment_status AS receipt_payment_status,
            pr.payment_method AS receipt_payment_method
            FROM tbl_bookings b
            LEFT JOIN payment_receipts pr ON pr.id = (
                SELECT id FROM payment_receipts 
                WHERE booking_id = b.id 
                ORDER BY CASE WHEN (installment_number IS NULL OR installment_number = 0) THEN 0 ELSE 1 END ASC, id ASC LIMIT 1
            )
            WHERE b.user_id = $user_id $bSourceCondB
            ORDER BY b.id DESC LIMIT 50");
        if (!$bookings_query) {
            $bookings_query = @$conn->query("SELECT b.* FROM tbl_bookings b WHERE b.user_id = $user_id $bSourceCondB ORDER BY b.id DESC LIMIT 50");
        }
        if ($bookings_query) {
            while ($row = $bookings_query->fetch_assoc()) {
                $recent_bookings[] = $row;
            }
        }
    }
} catch (Throwable $bEx) {
    error_log('User dashboard bookings query error: ' . $bEx->getMessage());
}

$installmentHelperFile = __DIR__ . '/config/installment_helper.php';
if (is_file($installmentHelperFile)) {
    require_once $installmentHelperFile;
}

$payment_plans = [];
$schedule_tables_ok = true;

try {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $planQuery = @$conn->query(
            "SELECT id, section, plot, grave_location, total_amount, amount_paid AS amount_paid_raw, payment_plan, payment_term, num_lots, status
             FROM tbl_bookings
             WHERE user_id = $user_id AND payment_plan = 'installment' AND LOWER(TRIM(COALESCE(status, ''))) NOT IN ('cancelled', 'rejected') $bSourceCond
             ORDER BY id DESC"
        );
        if ($planQuery) {
            while ($bk = $planQuery->fetch_assoc()) {
                $bk_id = (int)$bk['id'];
                $bk['schedule'] = [];

                try {

                    $schQuery = @$conn->query(
                        "SELECT id, installment_number, due_date, monthly_amount AS amount_due, paid_amount, remaining_balance, status, paid_at, payment_method, transaction_reference
                         FROM payment_schedules
                         WHERE contract_id = $bk_id
                         ORDER BY installment_number ASC"
                    );
                    if ($schQuery && $schQuery->num_rows > 0) {
                        while ($inst = $schQuery->fetch_assoc()) {
                            $bk['schedule'][] = $inst;
                        }
                    } else {

                        $legQuery = @$conn->query(
                            "SELECT id, installment_number, due_date, amount_due, amount_paid, status, paid_at, payment_method, transaction_reference
                             FROM booking_payment_schedule
                             WHERE booking_id = $bk_id
                             ORDER BY installment_number ASC"
                        );
                        if ($legQuery && $legQuery->num_rows > 0) {
                            while ($inst = $legQuery->fetch_assoc()) {
                                $bk['schedule'][] = $inst;
                            }
                        } elseif (function_exists('generate_contract_payment_schedule')) {

                            @generate_contract_payment_schedule($bk_id, $conn, false);
                            $schQuery2 = @$conn->query(
                                "SELECT id, installment_number, due_date, monthly_amount AS amount_due, paid_amount, remaining_balance, status, paid_at, payment_method, transaction_reference
                                 FROM payment_schedules
                                 WHERE contract_id = $bk_id
                                 ORDER BY installment_number ASC"
                            );
                            if ($schQuery2) {
                                while ($inst = $schQuery2->fetch_assoc()) {
                                    $bk['schedule'][] = $inst;
                                }
                            }
                        }
                    }
                } catch (Throwable $schErr) {
                    error_log('Schedule query error: ' . $schErr->getMessage());
                }

                $bk['payments'] = [];
                $bk_verified_paid = 0.00;
                $bk_pending_paid = 0.00;
                $bk_receipts_by_inst = [];
                $sched_id_to_num = [];
                if (!empty($bk['schedule'])) {
                    foreach ($bk['schedule'] as $sItem) {
                        if (!empty($sItem['id']) && !empty($sItem['installment_number'])) {
                            $sched_id_to_num[(int)$sItem['id']] = (int)$sItem['installment_number'];
                        }
                    }
                }
                try {
                    $payQuery = @$conn->query(
                        "SELECT id, booking_id, payment_schedule_id, installment_number, amount_paid, payment_method, gcash_reference_number, transaction_reference, 
                                payment_date, payment_status, proof_filename, receipt_number, rejection_reason
                         FROM payment_receipts
                         WHERE booking_id = $bk_id
                         ORDER BY id DESC"
                    );
                    if ($payQuery && $payQuery->num_rows > 0) {
                        while ($pRow = $payQuery->fetch_assoc()) {
                            $bk['payments'][] = $pRow;
                            $pSt = strtoupper(trim((string)($pRow['payment_status'] ?? '')));
                            $pAmt = (float)($pRow['amount_paid'] ?? 0);
                            $instNum = !empty($pRow['installment_number']) ? (int)$pRow['installment_number'] : 0;
                            if ($instNum <= 0 && !empty($pRow['payment_schedule_id']) && isset($sched_id_to_num[(int)$pRow['payment_schedule_id']])) {
                                $instNum = $sched_id_to_num[(int)$pRow['payment_schedule_id']];
                            }
                            if ($instNum > 0 && !isset($bk_receipts_by_inst[$instNum])) {
                                $bk_receipts_by_inst[$instNum] = $pRow;
                            }
                            if (in_array($pSt, ['PAID', 'VERIFIED'], true)) {
                                $bk_verified_paid += $pAmt;
                            } elseif (in_array($pSt, ['PENDING', 'FOR VERIFICATION'], true) || (!empty($pRow['proof_filename']) && $pSt !== 'REJECTED')) {
                                $bk_pending_paid += $pAmt;
                            }
                        }
                    }
                } catch (Throwable $pEx) {
                    error_log('Payment transactions query error: ' . $pEx->getMessage());
                }

                // Check schedule for confirmed paid installments
                $sched_paid = 0.00;
                if (!empty($bk['schedule'])) {
                    foreach ($bk['schedule'] as $chkInst) {
                        if (strtoupper(trim((string)($chkInst['status'] ?? ''))) === 'PAID') {
                            $sched_paid += (float)($chkInst['paid_amount'] ?? ($chkInst['amount_due'] ?? 0));
                        }
                    }
                }

                $real_paid = max($bk_verified_paid, $sched_paid);
                $plan_total = (float)$bk['total_amount'];
                if ($plan_total <= 0) {
                    $plan_total = (float)($bk['plot_price'] ?? 0);
                }
                $real_balance = max(0.00, round($plan_total - $real_paid, 2));

                $bk['amount_paid'] = $real_paid;
                $bk['remaining_balance'] = $real_balance;
                $bk['total_amount'] = $plan_total;
                $bk['pending_amount'] = $bk_pending_paid;
                $bk['receipts_by_inst'] = $bk_receipts_by_inst;

                // Self-heal corrupted database record if amount_paid was mistakenly set to total_amount without verified receipts
                $rawDbPaid = (float)($bk['amount_paid_raw'] ?? 0);
                if ($rawDbPaid >= ($plan_total - 0.01) && $real_paid < ($plan_total - 0.01) && $plan_total > 0) {
                    @$conn->query("UPDATE tbl_bookings SET amount_paid = {$real_paid}, remaining_balance = {$real_balance}, payment_status = 'FOR VERIFICATION' WHERE id = $bk_id");
                }

                $payment_plans[] = $bk;
            }
        }
    }
} catch (Throwable $planEx) {
    error_log('Payment plans query error: ' . $planEx->getMessage());
    $schedule_tables_ok = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>try{if(window.sessionStorage)sessionStorage.setItem('gor_tab_home','user_dashboard');}catch(e){}</script>
    <title>User Dashboard - Garden of Remembrance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="notification_component.css">
    <link rel="stylesheet" href="includes/notification_component.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    (function() {
      var _nativeAlert = window.alert;
      window.alert = function(msg) {
        if (typeof Swal !== 'undefined') {
          var text = String(msg == null ? '' : msg);
          var title = 'Notice';
          var icon = 'info';
          if (/error|failed|cannot|denied|restricted|invalid|not allowed|please enter|must be|required/i.test(text)) {
            title = /error|failed/i.test(text) ? 'Notice' : 'Attention';
            icon = 'warning';
          } else if (/success|completed|updated|booked|added|settled/i.test(text)) {
            title = 'Success';
            icon = 'success';
          }
          Swal.fire({
            icon: icon,
            title: title,
            text: text,
            confirmButtonColor: '#7b1113'
          });
        } else {
          _nativeAlert(msg);
        }
      };
    })();
    </script>
    <style>
        :root {
            --bs-primary: #7b1113;
            --admin-primary: #7b1113;
            --admin-primary-dark: #610d10;
            --admin-primary-light: #8e1518;
            --admin-accent: #a03a3c;
            --admin-header-gradient: linear-gradient(135deg, #7b1113 0%, #8e1518 50%, #5d0c0e 100%);
        }

        .swal2-container {
            z-index: 999999 !important;
        }

        /* Scoped styling for Upload Proof Modal containment */
        #uploadProofModal .modal-dialog {
            max-width: min(580px, 94vw) !important;
            margin: 1.5rem auto !important;
        }
        #uploadProofModal .modal-content {
            border-radius: 16px !important;
            overflow: hidden !important;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2), 0 8px 10px -6px rgba(0, 0, 0, 0.2) !important;
        }
        #uploadProofModal .modal-body {
            overflow-x: hidden !important;
        }
        #uploadProofModal select.form-select {
            max-width: 100% !important;
            text-overflow: ellipsis !important;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f5f7fb;
            color: #212529;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        /* Topbar Header matching Admin Dashboard design */
        .admin-topbar {
            position: sticky;
            top: 0;
            left: 0;
            right: 0;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            z-index: 1040;
            background: var(--admin-header-gradient);
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.16), inset 0 -1px 0 rgba(255, 255, 255, 0.1);
            padding: 0 1.25rem !important;
        }

        .admin-topbar-inner {
            max-width: 100% !important;
            width: 100% !important;
            box-sizing: border-box !important;
            margin: 0 auto;
            height: 60px;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 0.5rem;
        }

        .admin-brand {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            padding: 2px 0;
            flex-shrink: 0;
            min-width: 0;
        }

        .admin-brand-logo {
            height: 38px;
            width: auto;
            max-width: 160px;
            object-fit: contain;
            display: block;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.18));
        }

        .admin-brand-title {
            color: #ffffff;
            font-weight: 700;
            font-size: 0.95rem;
            letter-spacing: 0.01em;
            white-space: nowrap;
            display: inline-block;
            line-height: 1.2;
        }

        .admin-nav {
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 4px !important;
            flex-wrap: nowrap !important;
            flex-shrink: 1 !important;
            min-width: 0 !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .admin-nav .topnav-link {
            color: #ffffff !important;
            text-decoration: none !important;
            font-weight: 600;
            font-size: 0.78rem;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            position: relative;
            padding: 6px 11px;
            border-radius: 18px;
            transition: all 0.2s ease;
            white-space: nowrap !important;
            display: inline-flex !important;
            align-items: center !important;
            cursor: pointer;
            box-sizing: border-box !important;
            flex-shrink: 0;
        }

        .admin-nav .topnav-link:hover {
            color: #ffffff !important;
            background: rgba(255, 255, 255, 0.18);
            transform: translateY(-1px);
        }

        .admin-nav .topnav-link.active {
            color: #ffffff !important;
            background: rgba(255, 255, 255, 0.25);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.35), 0 2px 8px rgba(0, 0, 0, 0.12);
            font-weight: 700;
        }

        .admin-nav .topnav-link.text-logout {
            color: #ffcccc !important;
            background: rgba(0, 0, 0, 0.22) !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            padding: 6px 12px !important;
            font-weight: 700 !important;
            flex-shrink: 0 !important;
            margin-left: 5px !important;
            white-space: nowrap !important;
            display: inline-flex !important;
            align-items: center !important;
            box-sizing: border-box !important;
        }

        .admin-nav .topnav-link.text-logout:hover {
            color: #ffffff !important;
            background: rgba(220, 53, 69, 0.6) !important;
            border-color: rgba(220, 53, 69, 0.9) !important;
        }

        @media (max-width: 1380px) {
            .admin-topbar { padding: 0 0.75rem !important; }
            .admin-topbar-inner { gap: 0.3rem !important; }
            .admin-brand-logo { height: 32px !important; }
            .admin-brand-title { font-size: 0.86rem !important; }
            .admin-nav { gap: 3px !important; }
            .admin-nav .topnav-link { padding: 5px 8px !important; font-size: 0.72rem !important; }
            .admin-nav .topnav-link.text-logout { padding: 5px 10px !important; font-size: 0.72rem !important; }
        }

        @media (max-width: 1200px) {
            .admin-topbar { padding: 0 0.5rem !important; }
            .admin-brand-logo { height: 28px !important; }
            .admin-brand-title { font-size: 0.78rem !important; }
            .admin-nav { gap: 2px !important; }
            .admin-nav .topnav-link { padding: 4px 6px !important; font-size: 0.65rem !important; }
            .admin-nav .topnav-link.text-logout { padding: 4px 8px !important; font-size: 0.65rem !important; }
        }

        /* Mobile Toolbar & Drawer matching Admin */
        .mobile-toolbar {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1050;
            background: var(--admin-header-gradient);
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
            padding: 0 12px;
        }

        .mobile-toolbar-inner {
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .mobile-menu-btn {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.25);
            color: #fff;
            font-size: 1.25rem;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .mobile-brand-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            min-width: 0;
            flex: 1;
            justify-content: center;
        }

        .mobile-toolbar-title {
            color: #ffffff;
            font-size: 0.95rem;
            font-weight: 700;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            letter-spacing: 0.02em;
        }

        .mobile-logo {
            height: 36px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            display: block;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.18));
        }

        .mobile-profile-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.35);
            overflow: hidden;
            background: rgba(255,255,255,0.15);
            flex-shrink: 0;
            transition: all 0.2s ease;
        }

        .mobile-profile-btn:hover {
            border-color: #ffffff;
            transform: scale(1.05);
        }

        .mobile-profile-avatar {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .mobile-drawer-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1060;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .mobile-drawer-overlay.active {
            display: block;
            opacity: 1;
        }

        .mobile-drawer {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 290px;
            max-width: 85vw;
            background: #ffffff;
            z-index: 1070;
            box-shadow: 4px 0 25px rgba(0,0,0,0.2);
            transform: translateX(-100%);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            padding: 1.25rem 1rem;
        }

        .mobile-drawer.active {
            transform: translateX(0);
        }

        .mobile-drawer-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 1rem;
        }

        .mobile-drawer-title {
            font-weight: 700;
            color: #7b1113;
            font-size: 1.1rem;
        }

        .mobile-drawer-close {
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            font-size: 1.25rem;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #64748b;
        }

        .mobile-drawer-nav {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
        }

        .mobile-drawer-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 10px;
            color: #334155;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.92rem;
            transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
        }

        .mobile-drawer-nav a .nav-icon {
            font-size: 1.15rem;
            width: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            flex-shrink: 0;
            color: #475569;
        }

        .mobile-drawer-nav a:hover {
            background: #fdf2f2;
            color: #7b1113;
            transform: translateX(2px);
        }

        .mobile-drawer-nav a:hover .nav-icon {
            color: #7b1113;
        }

        .mobile-drawer-nav a.active {
            background: #fdf2f2 !important;
            color: #7b1113 !important;
            font-weight: 700;
            box-shadow: none !important;
        }

        .mobile-drawer-nav a.active .nav-icon {
            color: #7b1113 !important;
        }

        .mobile-drawer-nav a.drawer-logout-link {
            color: #dc2626;
            margin-top: 0.5rem;
            border-top: 1px dashed #f1f5f9;
            padding-top: 12px;
        }

        .mobile-drawer-nav a.drawer-logout-link .nav-icon {
            color: #dc2626;
        }

        .mobile-drawer-nav a.drawer-logout-link:hover {
            background: #fef2f2;
            color: #b91c1c;
        }

        .mobile-drawer-nav a.drawer-logout-link:hover .nav-icon {
            color: #b91c1c;
        }

        .mobile-drawer-footer {
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
            font-size: 0.8rem;
            color: #94a3b8;
            text-align: center;
        }

        /* Profile Mini Card in Drawer */
        .drawer-profile-card {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.85rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 1rem;
            text-decoration: none;
            color: inherit;
        }

        .drawer-profile-card:hover {
            background: #f1f5f9;
        }

        .drawer-profile-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #7b1113;
        }

        .drawer-profile-arrow {
            color: #94a3b8;
            font-size: 1.25rem;
            margin-left: auto;
            flex-shrink: 0;
            font-weight: bold;
        }

        /* Hero Banner Section */
        .hero-banner {
            text-align: center;
            padding: 2.25rem 1rem 1.5rem;
        }

        .hero-banner h1 {
            color: #7b1113;
            font-weight: 700;
            font-size: 1.85rem;
            margin-bottom: 0.35rem;
            letter-spacing: -0.01em;
        }

        .hero-banner .hero-tagline {
            color: #a03a3c;
            font-size: 1.05rem;
            font-weight: 500;
            margin-bottom: 1.25rem;
        }

        .user-welcome-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #ffffff;
            padding: 0.5rem 1.25rem;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            font-size: 0.92rem;
            font-weight: 600;
            color: #334155;
        }

        /* Main Container */
        .dashboard-container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 0 1rem 3rem;
        }

        /* Stat Cards matching Admin Stat Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(0,0,0,0.1);
        }

        .stat-card.total-card {
            border-left: 4px solid #7b1113;
        }

        .stat-card.pending-card {
            border-left: 4px solid #ffc107;
        }

        .stat-card.approved-card {
            border-left: 4px solid #28a745;
        }

        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .stat-icon.total-icon {
            background: #fdf2f2;
            color: #7b1113;
        }

        .stat-icon.pending-icon {
            background: #fffbeb;
            color: #d97706;
        }

        .stat-icon.approved-icon {
            background: #f0fdf4;
            color: #16a34a;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.1;
            margin-bottom: 0.2rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: #64748b;
            font-weight: 500;
        }

        /* Section Cards */
        .dashboard-section-card {
            background: #ffffff;
            border-radius: 1.25rem;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
            padding: 1.75rem 2rem;
            margin-bottom: 2rem;
        }

        .section-title {
            color: #7b1113;
            font-weight: 700;
            font-size: 1.18rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 0.75rem;
        }

        .section-title-text {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        /* Card-Based Booking UI */
        .booking-card {
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.25s ease;
            margin-bottom: 1.25rem;
        }

        .booking-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.07);
        }

        .booking-info-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
            padding: 0.85rem 1rem;
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 10px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            min-width: 0;
        }

        .info-label {
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            font-weight: 600;
        }

        .info-value {
            font-size: 0.92rem;
            color: #1e293b;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Installment Plan Card */
        .installment-card {
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.25s ease;
            border-left: 4px solid #7b1113;
            margin-bottom: 1.25rem;
        }

        /* My Bookings Modal & Tabs Styling */
        #myBookingsModal .modal-dialog {
            max-width: min(1240px, 96vw) !important;
        }
        #myBookingsModal .modal-content {
            border-radius: 1.25rem !important;
            overflow: hidden !important;
        }
        #myBookingsModal .dashboard-section-card {
            border: 1px solid #e2e8f0;
            box-shadow: none;
            margin-bottom: 1.25rem;
            padding: 1.25rem 1.5rem;
        }
        #myBookingsModalTabs .nav-link {
            color: #475569;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        #myBookingsModalTabs .nav-link.active {
            background-color: #7b1113 !important;
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(123, 17, 19, 0.25);
        }
        #myBookingsModalTabs .nav-link.active .badge {
            background: rgba(255, 255, 255, 0.25) !important;
            color: #ffffff !important;
        }
        .portal-service-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .portal-service-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.08) !important;
        }

        .installment-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.07);
        }

        .installment-metric-box {
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 54px;
        }
        .installment-metric-box .metric-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            line-height: 1.2;
            margin-bottom: 3px;
        }
        .installment-metric-box .metric-value {
            font-size: 1rem;
            line-height: 1.2;
        }

        /* Consolidated Installments Summary & Plot Pills */
        .installment-summary-card {
            background: linear-gradient(135deg, #fdfbf7 0%, #ffffff 100%);
            border: 1px solid #e2e8f0;
            border-left: 4px solid #7b1113 !important;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        .plot-plan-pills .nav-link {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            color: #334155;
            border-radius: 12px;
            padding: 8px 14px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 0.85rem;
            cursor: pointer;
        }
        .plot-plan-pills .nav-link:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #7b1113;
            transform: translateY(-1px);
        }
        .plot-plan-pills .nav-link.active {
            background: #7b1113 !important;
            color: #ffffff !important;
            border-color: #7b1113 !important;
            box-shadow: 0 4px 12px rgba(123, 17, 19, 0.22);
        }
        .plot-plan-pills .nav-link.active .plot-pill-subtitle,
        .plot-plan-pills .nav-link.active .plot-pill-status,
        .plot-plan-pills .nav-link.active i {
            color: #ffcccc !important;
        }
        .plot-pill-title {
            font-size: 0.88rem;
            line-height: 1.25;
            color: inherit;
        }
        .plot-pill-subtitle {
            font-size: 0.72rem;
            color: #64748b;
            line-height: 1.2;
        }

        /* Deceased member item */
        .deceased-member-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            transition: all 0.2s ease;
        }

        .deceased-member-item:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 2.75rem;
            color: #94a3b8;
            margin-bottom: 1rem;
            display: block;
        }

        /* Buttons */
        .btn-primary {
            background-color: #7b1113;
            border-color: #7b1113;
            font-weight: 600;
            border-radius: 0.6rem;
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

        /* Universal Viewport-Safe Responsive Modals */
        body.modal-open {
            overflow: hidden !important;
        }
        .modal {
            overflow-x: hidden !important;
            overflow-y: hidden !important;
        }
        .modal-dialog:not(.modal-fullscreen) {
            max-height: 92vh !important;
            margin-top: 1.5rem !important;
            margin-bottom: 1.5rem !important;
        }
        .modal-dialog:not(.modal-fullscreen) .modal-content {
            max-height: 90vh !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
            border-radius: 1.25rem;
            border: none;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }
        .modal-header {
            flex-shrink: 0 !important;
            background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important;
            color: #ffffff !important;
            border-radius: 1.25rem 1.25rem 0 0;
            border-bottom: 1px solid rgba(255,255,255,0.15) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
        }
        .modal-header .modal-title,
        .modal-header h1,
        .modal-header h2,
        .modal-header h3,
        .modal-header h4,
        .modal-header h5,
        .modal-header h6,
        .modal-title {
            color: #ffffff !important;
            font-weight: 700 !important;
        }
        .modal-header p,
        .modal-header small,
        .modal-header span:not(.badge),
        .modal-header div:not(.btn-close):not(.custom-modal-close) {
            color: rgba(255, 255, 255, 0.92) !important;
        }

        /* Global Popping Modal Close Button */
        .modal-header .btn-close,
        .modal-header .close,
        .btn-close.custom-modal-close,
        .custom-modal-close {
            z-index: 1060 !important;
            position: relative !important;
            width: 34px !important;
            height: 34px !important;
            min-width: 34px !important;
            min-height: 34px !important;
            max-width: 34px !important;
            max-height: 34px !important;
            aspect-ratio: 1 / 1 !important;
            flex: 0 0 34px !important;
            flex-shrink: 0 !important;
            flex-grow: 0 !important;
            border-radius: 50% !important;
            background-color: #ffffff !important;
            background-image: none !important;
            border: 2px solid rgba(255, 255, 255, 0.95) !important;
            color: #7b1113 !important;
            padding: 0 !important;
            margin: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            opacity: 1 !important;
            filter: none !important;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.32) !important;
            cursor: pointer !important;
            transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1) !important;
            box-sizing: border-box !important;
            line-height: 1 !important;
        }

        .modal-header .btn-close::before,
        .modal-header .close::before,
        .btn-close.custom-modal-close::before,
        .custom-modal-close::before {
            content: "×" !important;
            font-family: Arial, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important;
            font-size: 24px !important;
            font-weight: 900 !important;
            color: #7b1113 !important;
            line-height: 1 !important;
            display: block !important;
            margin-top: -2px !important;
            text-align: center !important;
            pointer-events: none !important;
        }

        .modal-header .btn-close::after,
        .modal-header .close::after,
        .btn-close.custom-modal-close::after,
        .custom-modal-close::after {
            display: none !important;
            content: none !important;
        }

        .modal-header .btn-close:hover,
        .modal-header .close:hover,
        .btn-close.custom-modal-close:hover,
        .custom-modal-close:hover {
            background-color: #f8fafc !important;
            color: #94171a !important;
            transform: scale(1.12) rotate(90deg) !important;
            box-shadow: 0 5px 16px rgba(0, 0, 0, 0.45) !important;
            border-color: #ffffff !important;
            opacity: 1 !important;
        }
        .modal-footer {
            flex-shrink: 0 !important;
            border-top: 1px solid #e2e8f0 !important;
        }
        .modal-dialog:not(.modal-fullscreen) .modal-body {
            overflow-y: auto !important;
            overflow-x: hidden !important;
            flex: 1 1 auto !important;
            max-height: calc(90vh - 130px) !important;
        }
        .modal-dialog form {
            display: flex !important;
            flex-direction: column !important;
            flex: 1 1 auto !important;
            min-height: 0 !important;
            max-height: 100% !important;
            overflow: hidden !important;
        }

        @media (max-width: 991px) {
            .booking-info-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 0.75rem;
            }
        }

        @media (max-width: 768px) {
            .admin-topbar {
                display: none !important;
            }
            .mobile-toolbar {
                display: block !important;
            }
            body {
                padding-top: 60px !important;
            }
            .dashboard-section-card {
                padding: 1.25rem 1.25rem;
            }
            #plotSelectionModal .modal-dialog {
                max-width: 100vw !important;
                margin: 0.25rem !important;
                height: calc(100vh - 0.5rem) !important;
            }
            #plotSelectionModal .modal-content {
                height: 100% !important;
                border-radius: 12px !important;
            }
            #plotSelectionModal .modal-body {
                padding: 0.5rem !important;
                overflow-y: auto !important;
            }
            #plotSelectionFrame {
                min-height: 65vh !important;
                height: 65vh !important;
            }
            #userPlotsCheckboxesContainer {
                max-height: 250px !important;
            }
            .user-plot-service-checkbox {
                width: 20px !important;
                height: 20px !important;
            }
            .swal2-popup {
                width: 95% !important;
                max-width: 440px !important;
                padding: 1.25rem 0.85rem !important;
                border-radius: 16px !important;
            }
            .swal2-actions {
                flex-direction: column-reverse !important;
                gap: 0.5rem !important;
                width: 100% !important;
            }
            .swal2-actions button {
                width: 100% !important;
                margin: 0 !important;
                min-height: 44px !important;
            }
        }

        @media (max-width: 576px) {
            /* General text & spacing scale for mobile */
            body {
                font-size: 0.86rem !important;
            }

            /* Hero Banner (Image 5) */
            .hero-banner {
                padding: 1.25rem 0.75rem !important;
            }
            .hero-banner h1 {
                font-size: 1.2rem !important;
                line-height: 1.25 !important;
                margin-bottom: 3px !important;
            }
            .hero-tagline {
                font-size: 0.78rem !important;
                margin-bottom: 8px !important;
            }
            .user-welcome-badge {
                font-size: 0.74rem !important;
                padding: 4px 10px !important;
                max-width: 100% !important;
                white-space: normal !important;
                word-break: break-word !important;
                display: inline-flex !important;
                flex-wrap: wrap !important;
                justify-content: center !important;
                gap: 4px !important;
            }

            /* Stats Grid (Image 5) - Fits all 3 cards in one compact row */
            .stats-grid {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 6px !important;
                margin-bottom: 1.25rem !important;
            }
            .stat-card {
                padding: 10px 4px !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                text-align: center !important;
                border-radius: 12px !important;
                min-width: 0 !important;
            }
            .stat-icon {
                width: 32px !important;
                height: 32px !important;
                font-size: 0.88rem !important;
                margin-bottom: 4px !important;
            }
            .stat-value {
                font-size: 1.2rem !important;
                line-height: 1.1 !important;
            }
            .stat-label {
                font-size: 0.65rem !important;
                line-height: 1.15 !important;
                margin-top: 2px !important;
                white-space: nowrap !important;
                text-overflow: ellipsis !important;
                overflow: hidden !important;
                max-width: 100% !important;
            }

            /* Dashboard section cards */
            .dashboard-section-card {
                padding: 0.9rem 0.75rem !important;
                border-radius: 14px !important;
                margin-bottom: 1.25rem !important;
            }
            .section-title {
                flex-wrap: wrap !important;
                gap: 8px !important;
                align-items: center !important;
                justify-content: space-between !important;
            }
            .section-title-text {
                font-size: 0.92rem !important;
            }
            .section-title button, .section-title a {
                font-size: 0.76rem !important;
                padding: 5px 9px !important;
                white-space: nowrap !important;
            }

            /* Booking Cards & Payments (Images 1 & 3) */
            .booking-card, .installment-card {
                padding: 0.85rem 0.75rem !important;
                border-radius: 12px !important;
                overflow: hidden !important;
            }
            .installment-metric-box {
                padding: 5px 2px !important;
                min-height: 48px !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: center !important;
                overflow: hidden !important;
            }
            .installment-metric-box .metric-label {
                font-size: 0.62rem !important;
                line-height: 1.15 !important;
                margin-bottom: 2px !important;
                white-space: nowrap !important;
                text-overflow: ellipsis !important;
                overflow: hidden !important;
            }
            .installment-metric-box .metric-value {
                font-size: clamp(0.72rem, 3.1vw, 0.84rem) !important;
                line-height: 1.15 !important;
                letter-spacing: -0.02em !important;
                white-space: nowrap !important;
                text-overflow: ellipsis !important;
                overflow: hidden !important;
            }
            .booking-info-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.65rem;
                font-size: 0.78rem !important;
            }
            .payment-proof-card {
                padding: 0.85rem 0.75rem !important;
            }
            .payment-proof-card .d-flex.justify-content-between {
                flex-wrap: wrap !important;
                gap: 6px !important;
                align-items: flex-start !important;
            }
            .payment-proof-card .badge {
                max-width: 100% !important;
                white-space: normal !important;
                text-align: left !important;
                font-size: 0.72rem !important;
                padding: 4px 8px !important;
                line-height: 1.3 !important;
                word-break: break-word !important;
            }
            .payment-proof-card code {
                font-size: 0.76rem !important;
                max-width: 100% !important;
                word-break: break-all !important;
                white-space: normal !important;
                display: inline-block !important;
            }
            .booking-card .small.d-flex.justify-content-between {
                font-size: 0.78rem !important;
                flex-wrap: wrap !important;
                gap: 4px !important;
            }
            .booking-card .table {
                font-size: 0.75rem !important;
            }
            .booking-card .table th, 
            .booking-card .table td {
                padding: 5px 6px !important;
                white-space: nowrap !important;
                font-size: 0.74rem !important;
            }

            /* Modals General (Prevents horizontal overflow and clipping) */
            .modal-dialog {
                margin: 8px auto !important;
                max-width: calc(100% - 16px) !important;
                width: calc(100% - 16px) !important;
            }
            .modal-content {
                border-radius: 14px !important;
                max-height: 94vh !important;
                overflow-y: auto !important;
            }
            .modal-header {
                padding: 10px 14px !important;
            }
            .modal-title {
                font-size: 0.92rem !important;
            }
            .modal-body {
                padding: 12px 10px !important;
            }
            .modal-footer {
                padding: 10px 12px !important;
            }

            /* Paid Transaction Receipt Modal (Image 4) */
            #paidTransactionModal .modal-body {
                padding: 8px 6px !important;
            }
            #paidTransactionModal .modal-body > div {
                padding: 12px 10px !important;
                border-radius: 12px !important;
            }
            #paidTransactionModal #ptAmount {
                font-size: 1.45rem !important;
                line-height: 1.2 !important;
            }
            #paidTransactionModal .border-bottom {
                padding-top: 6px !important;
                padding-bottom: 6px !important;
            }
            #paidTransactionModal .d-flex.justify-content-between {
                font-size: 0.78rem !important;
                line-height: 1.45 !important;
                gap: 6px !important;
                padding: 2px 0 !important;
            }
            #paidTransactionModal .d-flex.justify-content-between > span:first-child {
                flex-shrink: 0 !important;
                font-size: 0.75rem !important;
            }
            #paidTransactionModal .d-flex.justify-content-between > span:last-child {
                word-break: break-word !important;
                text-align: right !important;
                font-size: 0.78rem !important;
            }
            #paidTransactionModal .modal-footer {
                flex-direction: column !important;
                gap: 6px !important;
                padding: 10px 12px !important;
            }
            #paidTransactionModal .modal-footer button,
            #paidTransactionModal .modal-footer a {
                width: 100% !important;
                justify-content: center !important;
                text-align: center !important;
                font-size: 0.82rem !important;
                padding: 8px 12px !important;
                min-height: 38px !important;
            }
            #paidTransactionModal .modal-footer > div {
                width: 100% !important;
                flex-direction: column !important;
                gap: 6px !important;
            }

            /* Form Elements inside Modals */
            .form-label {
                font-size: 0.78rem !important;
                margin-bottom: 3px !important;
            }
            .form-control, .form-select {
                font-size: 0.82rem !important;
                padding: 6px 9px !important;
                min-height: 36px !important;
            }
            #userSubmitBookingBtn {
                width: 100% !important;
                padding-top: 10px !important;
                padding-bottom: 10px !important;
                font-size: 0.95rem !important;
            }
            .gcash-payment-card {
                padding: 10px 8px !important;
            }
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .nav-tabs .nav-link {
                padding: 0.4rem 0.55rem !important;
                font-size: 0.78rem !important;
            }
        }
    </style>
</head>
<body>

    <div class="mobile-toolbar">
        <div class="mobile-toolbar-inner">
            <button type="button" class="mobile-menu-btn" id="mobileMenuButton" aria-label="Open navigation menu">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            </button>
            <a href="user_dashboard.php" class="mobile-brand-link" aria-label="Garden of Remembrance">
                <img src="cmis_logo.png" alt="Memorial Park Logo" class="mobile-logo">
                <span class="mobile-toolbar-title">Garden of Remembrance</span>
            </a>
            <div class="d-flex align-items-center gap-1">
                <?php if (function_exists('render_mobile_notification_bell')) echo render_mobile_notification_bell(); ?>
                <a href="profile.php" class="mobile-profile-btn" aria-label="My Profile" title="My Profile">
                    <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="User Avatar" class="mobile-profile-avatar" onerror="this.onerror=null;this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'%23ffffff\'><path d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/></svg>'">
                </a>
            </div>
        </div>
    </div>
    <div class="mobile-drawer-overlay" id="mobileDrawerOverlay"></div>
    <aside class="mobile-drawer" id="mobileDrawer" aria-hidden="true">
        <div class="mobile-drawer-header">
            <a href="profile.php" class="d-flex align-items-center gap-2 text-decoration-none overflow-hidden me-2" style="flex: 1; min-width: 0;" title="View Profile">
                <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="Profile Avatar" class="drawer-profile-avatar" style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 2px solid #7b1113; flex-shrink: 0;" onerror="this.onerror=null;this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'%237b1113\'><path d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/></svg>'">
                <div class="overflow-hidden" style="min-width: 0;">
                    <div class="fw-bold text-truncate" style="font-size: 0.95rem; color: #7b1113; line-height: 1.25;"><?php echo htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])); ?></div>
                    <div class="small text-muted text-truncate" style="font-size: 0.8rem; line-height: 1.25;"><?php echo htmlspecialchars($user['email']); ?></div>
                </div>
            </a>
            <button type="button" class="mobile-drawer-close flex-shrink-0" id="mobileDrawerClose" aria-label="Close navigation menu">×</button>
        </div>

        <nav class="mobile-drawer-nav">
            <a href="user_dashboard.php" class="active"><span class="nav-icon"><i class="fas fa-home"></i></span> <span>Dashboard</span></a>
            <a href="#" data-bs-toggle="modal" data-bs-target="#myBookingsModal"><span class="nav-icon"><i class="fas fa-calendar-check"></i></span> <span>Reservations</span></a>
            <a href="map.php"><span class="nav-icon"><i class="fas fa-map"></i></span> <span>Cemetery Map</span></a>
            <a href="<?php echo htmlspecialchars($transferRequestsUrl, ENT_QUOTES, 'UTF-8'); ?>"><span class="nav-icon"><i class="fas fa-exchange-alt"></i></span> <span>Plot Transfer</span></a>
            <a href="profile.php"><span class="nav-icon"><i class="fas fa-user-circle"></i></span> <span>My Profile</span></a>
            <a href="user_about.php"><span class="nav-icon"><i class="fas fa-info-circle"></i></span> <span>About</span></a>
            <a href="logout.php" class="drawer-logout-link"><span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span> <span>Logout</span></a>
        </nav>
        <div class="mobile-drawer-footer">Garden of Remembrance Memorial Park</div>
    </aside>

    <header class="admin-topbar">
        <div class="admin-topbar-inner">
            <a href="user_dashboard.php" class="admin-brand" aria-label="Garden of Remembrance Memorial Park">
                <img src="cmis_logo.png" alt="Garden of Remembrance Logo" class="admin-brand-logo">
                <span class="admin-brand-title">Garden of Remembrance</span>
            </a>
            <nav class="admin-nav" aria-label="Main Navigation">
                <a href="user_dashboard.php" class="topnav-link active"><i class="fas fa-home me-1"></i> DASHBOARD</a>
                <a href="#" class="topnav-link" data-bs-toggle="modal" data-bs-target="#myBookingsModal"><i class="fas fa-calendar-check me-1"></i> RESERVATIONS</a>
                <a href="map.php" class="topnav-link"><i class="fas fa-map me-1"></i> MAP</a>
                <a href="<?php echo htmlspecialchars($transferRequestsUrl, ENT_QUOTES, 'UTF-8'); ?>" class="topnav-link"><i class="fas fa-exchange-alt me-1"></i> PLOT TRANSFER</a>
                <a href="profile.php" class="topnav-link"><i class="fas fa-user-circle me-1"></i> MY PROFILE</a>
                <a href="user_about.php" class="topnav-link"><i class="fas fa-info-circle me-1"></i> ABOUT</a>
                <?php if (function_exists('render_desktop_notification_bell')) echo render_desktop_notification_bell(); ?>
                <a href="logout.php" class="topnav-link text-logout"><i class="fas fa-sign-out-alt me-1"></i> LOGOUT</a>
            </nav>
        </div>
    </header>

    <section class="hero-banner">
        <div class="container">
            <h1>Garden of Remembrance Memorial Park</h1>
            <div class="hero-tagline">Guiding Path To Your Loved Ones</div>
            <div>
                <div class="user-welcome-badge">
                    <i class="fas fa-user-circle text-danger"></i> Welcome, <?php echo htmlspecialchars($user['first_name']); ?>!
                    <span class="text-muted fw-normal">| <?php echo htmlspecialchars($user['email']); ?></span>
                </div>
            </div>
        </div>
    </section>

    <main class="dashboard-container">
        <?php if (!empty($claim_feedback)): ?>
            <div class="alert alert-<?php echo htmlspecialchars($claim_feedback['type']); ?> alert-dismissible fade show mb-4 shadow-sm" role="alert">
                <i class="fas fa-info-circle me-2"></i> <?php echo htmlspecialchars($claim_feedback['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card total-card" style="cursor: pointer;" onclick="openMyBookingsModal('bookings', 'all');" title="Click to view all bookings">
                <div class="stat-icon total-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo $booking_stats['total']; ?></div>
                    <div class="stat-label">Total Reservations</div>
                </div>
            </div>
            <div class="stat-card pending-card" style="cursor: pointer;" onclick="openMyBookingsModal('bookings', 'pending');" title="Click to view pending reservations">
                <div class="stat-icon pending-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo $booking_stats['pending']; ?></div>
                    <div class="stat-label">Pending Reservations</div>
                </div>
            </div>
            <div class="stat-card approved-card" style="cursor: pointer;" onclick="openMyBookingsModal('bookings', 'approved');" title="Click to view approved reservations">
                <div class="stat-icon approved-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo $booking_stats['approved']; ?></div>
                    <div class="stat-label">Approved Reservations</div>
                </div>
            </div>
            <div class="stat-card" style="cursor: pointer; border-left: 4px solid #7b1113;" onclick="openMyBookingsModal('plots');" title="Click to view your cemetery plots">
                <div class="stat-icon" style="background: #fdf2f8; color: #7b1113;">
                    <i class="fas fa-monument"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo count($my_plots); ?></div>
                    <div class="stat-label">My Cemetery Plots</div>
                </div>
            </div>
        </section>

        <!-- Customer Portal Services Hub (Dashboard View) -->
        <section class="dashboard-section-card mt-4">
            <div class="section-title">
                <div class="section-title-text">
                    <i class="fas fa-th-large text-danger"></i> Customer Portal Services
                </div>
                <div>
                    <button type="button" class="btn btn-sm btn-primary" onclick="openMyBookingsModal('bookings')">
                        <i class="fas fa-calendar-check me-1"></i> Open My Reservations
                    </button>
                </div>
            </div>

            <div class="row g-3 py-2">
                <div class="col-md-6 col-lg-3">
                    <div class="card h-100 border rounded-3 p-3 shadow-sm portal-service-card bg-white">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 46px; height: 46px; background: rgba(123,17,19,0.1); color: #7b1113; font-size: 1.25rem;">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">Reservations</h6>
                                <span class="small text-muted"><?php echo $booking_stats['total']; ?> Reservation<?php echo $booking_stats['total'] === 1 ? '' : 's'; ?></span>
                            </div>
                        </div>
                        <p class="small text-muted mb-3 flex-grow-1">View reservation contracts, payment schedules, digital invoices, and verify transaction receipts.</p>
                        <button type="button" class="btn btn-sm btn-outline-danger w-100 fw-semibold" onclick="openMyBookingsModal('bookings')">
                            <i class="fas fa-external-link-alt me-1"></i> View Reservations
                        </button>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="card h-100 border rounded-3 p-3 shadow-sm portal-service-card bg-white">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 46px; height: 46px; background: rgba(22,101,52,0.1); color: #166534; font-size: 1.25rem;">
                                <i class="fas fa-monument"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">My Cemetery Plots</h6>
                                <span class="small text-muted"><?php echo count($my_plots); ?> Registered Plot<?php echo count($my_plots) === 1 ? '' : 's'; ?></span>
                            </div>
                        </div>
                        <p class="small text-muted mb-3 flex-grow-1">Manage owned memorial plots, check verification status, and view interred family records.</p>
                        <button type="button" class="btn btn-sm btn-outline-success w-100 fw-semibold" onclick="openMyBookingsModal('plots')">
                            <i class="fas fa-external-link-alt me-1"></i> View Plots
                        </button>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="card h-100 border rounded-3 p-3 shadow-sm portal-service-card bg-white">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 46px; height: 46px; background: rgba(30,64,175,0.1); color: #1e40af; font-size: 1.25rem;">
                                <i class="fas fa-map-marked-alt"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">Cemetery Map</h6>
                                <span class="small text-muted">Interactive Grounds</span>
                            </div>
                        </div>
                        <p class="small text-muted mb-3 flex-grow-1">Explore Sections A through F, check vacant grave plots, and pinpoint grave coordinates in real-time.</p>
                        <a href="map.php" class="btn btn-sm btn-outline-primary w-100 fw-semibold">
                            <i class="fas fa-map me-1"></i> Explore Map
                        </a>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="card h-100 border rounded-3 p-3 shadow-sm portal-service-card bg-white">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 46px; height: 46px; background: rgba(147,51,234,0.1); color: #7e22ce; font-size: 1.25rem;">
                                <i class="fas fa-calendar-plus"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">New Reservation</h6>
                                <span class="small text-muted">Reserve Now</span>
                            </div>
                        </div>
                        <p class="small text-muted mb-3 flex-grow-1">Reserve a new memorial grave lot or request burial and cemetery maintenance services.</p>
                        <button type="button" class="btn btn-sm btn-primary w-100 fw-semibold" data-bs-toggle="modal" data-bs-target="#bookingModal">
                            <i class="fas fa-plus-circle me-1"></i> Reserve Plot
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- ======================================================== -->
    <!-- MY BOOKINGS & CEMETERY PLOTS MODAL                       -->
    <!-- ======================================================== -->
    <div class="modal fade" id="myBookingsModal" tabindex="-1" aria-labelledby="myBookingsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width: 1200px;">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden;">
                <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-calendar-check fs-5"></i>
                        <h5 class="modal-title fw-bold m-0" id="myBookingsModalLabel">Reservations & Cemetery Plots</h5>
                    </div>
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <button type="button" class="btn btn-sm btn-light text-danger fw-bold shadow-sm" onclick="switchToNewBookingModal()" style="border-radius: 8px;">
                            <i class="fas fa-plus-circle me-1"></i> New Reservation
                        </button>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                
                <div class="modal-body p-4 bg-light">
                    <!-- Nav Tabs inside modal -->
                    <ul class="nav nav-pills nav-fill mb-4 p-1.5 bg-white rounded-3 border shadow-sm" id="myBookingsModalTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active fw-bold py-2" id="modal-bookings-tab" data-bs-toggle="pill" data-bs-target="#modal-bookings-panel" type="button" role="tab">
                                <i class="fas fa-file-invoice me-1"></i> Reservations
                                <span class="badge rounded-pill bg-light text-dark border ms-1" style="font-size: 0.78rem;"><?php echo count($recent_bookings); ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link fw-bold py-2" id="modal-plots-tab" data-bs-toggle="pill" data-bs-target="#modal-plots-panel" type="button" role="tab">
                                <i class="fas fa-monument me-1"></i> My Cemetery Plots
                                <span class="badge rounded-pill bg-light text-dark border ms-1" style="font-size: 0.78rem;"><?php echo count($my_plots); ?></span>
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="myBookingsModalTabContent">
                        <!-- TAB 2: MY CEMETERY PLOTS -->
                        <div class="tab-pane fade" id="modal-plots-panel" role="tabpanel">
                            <section class="dashboard-section-card" id="myPlotsSection">
            <div class="section-title">
                <div class="section-title-text">
                    <i class="fas fa-monument text-danger"></i> My Cemetery Plots
                    <span class="badge rounded-pill bg-light text-dark border ms-2" style="font-size: 0.8rem;"><?php echo count($my_plots); ?> Plot<?php echo count($my_plots) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="d-flex gap-2">
                    <a href="my_bookings.php?tab=plots" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-external-link-alt me-1"></i> View All Plots
                    </a>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#claimPlotModal">
                        <i class="fas fa-plus-circle me-1"></i> Claim / Link Another Plot
                    </button>
                </div>
            </div>

            <?php if (!empty($claim_feedback)): ?>
                <div class="alert alert-<?php echo htmlspecialchars($claim_feedback['type']); ?> alert-dismissible fade show my-3" role="alert">
                    <i class="fas <?php echo $claim_feedback['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> me-2"></i>
                    <?php echo htmlspecialchars($claim_feedback['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($my_plots)): ?>
                <div class="row g-3">
                    <?php foreach ($my_plots as $p): ?>
                        <?php
                        $secName = strtoupper(htmlspecialchars($p['section']));
                        $px = (int)$p['plot_x'];
                        $py = (int)$p['plot_y'];
                        $plotNumberFormatted = $px . '-' . $py;
                        $ownStatus = strtoupper($p['ownership_status'] ?? 'OWNED');
                        $verStatus = strtoupper($p['verification_status'] ?? 'PENDING');
                        $hasDeceased = !empty($p['has_deceased']);
                        ?>
                        <div class="col-md-6 col-xl-4">
                            <div class="card h-100 border rounded-3 shadow-sm plot-card" style="transition: all 0.2s ease;">
                                <div class="card-header bg-white border-bottom pt-3 pb-2 px-3 d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="fw-bold text-dark" style="font-size: 1.05rem;">
                                            <i class="fas fa-map-marker-alt text-danger me-1"></i> Section <?php echo $secName; ?> — Plot <?php echo $plotNumberFormatted; ?>
                                        </span>
                                    </div>
                                    <div>
                                        <?php if ($verStatus === 'REJECTED'): ?>
                                            <span class="badge bg-danger" style="font-size: 0.72rem; letter-spacing: 0.03em;">REJECTED</span>
                                        <?php else: ?>
                                            <span class="badge <?php echo $ownStatus === 'RESERVED' ? 'bg-info text-dark' : 'bg-primary'; ?>" style="font-size: 0.72rem; letter-spacing: 0.03em;">
                                                <?php echo $ownStatus; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body p-3 d-flex flex-column justify-content-between">
                                    <div>

                                        <div class="d-flex align-items-center mb-3">
                                            <span class="small text-muted me-2">Verification:</span>
                                            <?php if ($verStatus === 'VERIFIED'): ?>
                                                <span class="badge bg-success" style="font-size: 0.76rem;"><i class="fas fa-check-circle me-1"></i> Verified</span>
                                            <?php elseif ($verStatus === 'REJECTED'): ?>
                                                <span class="badge bg-danger" style="font-size: 0.76rem;"><i class="fas fa-times-circle me-1"></i> Rejected</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark" style="font-size: 0.76rem;"><i class="fas fa-clock me-1"></i> Pending Verification</span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($verStatus === 'REJECTED'): ?>
                                            <div class="alert alert-danger py-2 px-3 small border-0 mb-3" style="background: #fef2f2; border-left: 4px solid #dc2626 !important;">
                                                <div class="fw-bold text-danger mb-1"><i class="fas fa-times-circle me-1"></i> Claim Rejected by Administrator</div>
                                                <div class="text-dark"><strong>Reason:</strong> <?php echo htmlspecialchars($p['admin_notes'] ?: 'Unverified ownership records or details could not be confirmed.'); ?></div>
                                                <div class="text-muted mt-2 small">This plot is not registered under your account. You can remove this rejected record from your dashboard below.</div>
                                            </div>
                                        <?php else: ?>

                                            <div class="mb-3">
                                                <div class="small fw-semibold text-muted text-uppercase mb-1" style="font-size: 0.72rem; letter-spacing: 0.05em;">Deceased Record</div>
                                                <?php if ($hasDeceased && !empty($p['deceased_list'])): ?>
                                                    <?php foreach ($p['deceased_list'] as $dItem): ?>
                                                        <div class="p-2 mb-1 rounded bg-light border">
                                                            <div class="fw-bold text-dark" style="font-size: 0.92rem; color: #7b1113 !important;">
                                                                <i class="fas fa-user text-danger me-1"></i> <?php echo htmlspecialchars($dItem['full_name']); ?>
                                                            </div>
                                                            <?php if (!empty($dItem['interment_date']) || !empty($dItem['date_died'])): ?>
                                                                <div class="text-muted" style="font-size: 0.78rem;">
                                                                    Burial / Interment: <?php echo htmlspecialchars($dItem['interment_date'] ?: $dItem['date_died']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <div class="p-2 rounded bg-light border text-muted" style="font-size: 0.82rem;">
                                                        <div class="fw-semibold text-secondary">
                                                            <i class="fas fa-seedling text-success me-1"></i> Owned Plot — No Deceased Record Yet
                                                        </div>
                                                        <div class="small mt-1 text-secondary">
                                                            This plot is registered under your account and ready for future use or burial service reservation.
                                                        </div>

                                                        <?php if (!empty($p['deceased_reference'])): ?>
                                                            <div class="small text-muted mt-1">
                                                                <strong>Ref:</strong> <?php echo htmlspecialchars($p['deceased_reference']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="pt-2 border-top d-flex gap-2 justify-content-between align-items-center flex-wrap">
                                        <a href="sections/popup.php?section=<?php echo urlencode($secName); ?>&plot_x=<?php echo $px; ?>&plot_y=<?php echo $py; ?>&search_highlight=1&auto_open=1&from=user_dashboard" class="btn btn-sm btn-outline-secondary">
                                            <i class="fas fa-map-marked-alt me-1"></i> View Cemetery
                                        </a>
                                        <div class="d-flex gap-1">
                                            <?php if ($verStatus === 'REJECTED'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="userDeleteRejectedClaim(<?php echo (int)$p['id']; ?>, '<?php echo htmlspecialchars($secName, ENT_QUOTES); ?>', <?php echo $px; ?>, <?php echo $py; ?>)">
                                                    <i class="fas fa-trash-alt me-1"></i> Delete Claim Record
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-primary" onclick="startPlotBooking('<?php echo htmlspecialchars($secName, ENT_QUOTES); ?>', <?php echo $px; ?>, <?php echo $py; ?>, <?php echo $hasDeceased ? (int)$p['deceased_list'][0]['deceased_id'] : '0'; ?>, 'cemetery_service')">
                                                    <i class="fas fa-calendar-plus me-1"></i> Reserve Service
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state p-4 text-center border rounded-3 bg-light">
                    <i class="fas fa-monument fa-3x text-muted mb-3"></i>
                    <h6 class="fw-bold text-secondary">No Cemetery Plots Registered Yet</h6>
                    <p class="text-muted small mb-3">If you already own or have reserved plots at Garden of Remembrance, click below to link them to your account.</p>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#claimPlotModal">
                        <i class="fas fa-plus-circle me-1"></i> Claim Your Plot
                    </button>
                </div>
            <?php endif; ?>
        </section>
        </div> <!-- Close #modal-plots-panel -->

        <!-- TAB 1: MY BOOKINGS & RESERVATIONS -->
        <div class="tab-pane fade show active" id="modal-bookings-panel" role="tabpanel">
        <section class="dashboard-section-card" id="recentBookingsSection">
            <div class="section-title">
                <div class="section-title-text">
                    <i class="fas fa-file-invoice text-danger"></i> Reservations
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap mb-3 p-2.5 bg-white rounded-3 border align-items-center shadow-sm">
                <span class="small fw-bold text-muted me-1"><i class="fas fa-filter text-danger me-1"></i> Quick Filter:</span>
                <button type="button" class="btn btn-sm btn-danger active filter-booking-btn py-1 px-3 rounded-pill" onclick="filterModalBookings('all', this)">All (<?php echo count($recent_bookings); ?>)</button>
                <button type="button" class="btn btn-sm btn-outline-secondary filter-booking-btn py-1 px-3 rounded-pill" onclick="filterModalBookings('pending', this)">Pending</button>
                <button type="button" class="btn btn-sm btn-outline-secondary filter-booking-btn py-1 px-3 rounded-pill" onclick="filterModalBookings('approved', this)">Approved</button>
                <button type="button" class="btn btn-sm btn-outline-secondary filter-booking-btn py-1 px-3 rounded-pill" onclick="filterModalBookings('paid', this)">Paid</button>
            </div>

            <?php if (!empty($recent_bookings)): ?>
                <?php foreach ($recent_bookings as $booking): ?>
                    <?php

                    $plot_price = (float)($booking['plot_price'] ?? 0);
                    $service_price = (float)($booking['service_price'] ?? 0);
                    $interment_fee = (float)($booking['interment_fee'] ?? 0);
                    $total_amount_field = (float)($booking['total_amount'] ?? 0);

                    $booking_total = $total_amount_field;
                    if ($booking_total <= 0) {
                        $booking_total = $plot_price + $service_price + $interment_fee;
                    }
                    $amount_paid = max((float)($booking['amount_paid'] ?? 0), (float)($booking['receipt_amount_paid'] ?? 0));
                    if ($booking_total <= 0 && $amount_paid > 0) {
                        $booking_total = $amount_paid;
                    }
                    $remaining_balance = max(0, $booking_total - $amount_paid);

                    $status = strtolower(trim($booking['status'] ?? 'pending'));
                    $status_badge_class = ($status === 'completed') ? 'bg-success' : (($status === 'approved') ? 'bg-primary' : (($status === 'rejected') ? 'bg-danger' : (($status === 'cancelled') ? 'bg-secondary' : 'bg-warning text-dark')));

                    $payment_status_db = strtoupper(trim((string)($booking['payment_status'] ?? '')));
                    $receipt_status_db = strtoupper(trim((string)($booking['receipt_payment_status'] ?? '')));
                    $service_status_db = strtoupper(trim((string)($booking['service_status'] ?? 'PENDING')));
                    $service_proof_file = trim((string)($booking['service_completion_proof'] ?? ''));

                    $proof_file = $booking['payment_proof_filename'] ?? '';
                    $proof_date = $booking['payment_proof_uploaded_at'] ?? '';
                    $gcash_ref = !empty($booking['gcash_reference_number']) ? $booking['gcash_reference_number'] : (!empty($booking['reference_number']) ? $booking['reference_number'] : '');
                    $is_receipt_verified = in_array($receipt_status_db, ['PAID', 'COMPLETED', 'SETTLED', 'VERIFIED', 'APPROVED'], true);
                    $is_booking_pay_verified = in_array($payment_status_db, ['PAID', 'COMPLETED', 'SETTLED', 'VERIFIED'], true);
                    $is_status_paid = in_array($status, ['paid', 'completed'], true);
                    $has_proof_file = !empty($proof_file);
                    $is_rejected = ($payment_status_db === 'REJECTED' || strtoupper(trim((string)($booking['receipt_payment_status'] ?? ''))) === 'REJECTED');

                    // If a receipt exists but is still PENDING without proof, do NOT treat as fully paid!
                    if ($receipt_status_db === 'PENDING' && !$has_proof_file && !$is_booking_pay_verified && !$is_status_paid) {
                        $is_fully_paid = false;
                        $amount_paid = (float)($booking['amount_paid'] ?? 0);
                        $remaining_balance = max(0, $booking_total - $amount_paid);
                    } else {
                        $is_fully_paid = ($amount_paid >= $booking_total && $booking_total > 0 && ($is_booking_pay_verified || $is_receipt_verified || $is_status_paid)) 
                            || ($remaining_balance <= 0 && $amount_paid > 0 && ($is_booking_pay_verified || $is_receipt_verified || $is_status_paid)) 
                            || $is_booking_pay_verified
                            || $is_receipt_verified
                            || $is_status_paid;
                    }

                    if ($is_fully_paid) {
                        $remaining_balance = 0;
                        if ($amount_paid <= 0 && $booking_total > 0) {
                            $amount_paid = $booking_total;
                        }
                    }
                    $is_for_verification = (!$is_fully_paid && !$is_rejected && ($payment_status_db === 'FOR VERIFICATION' || $receipt_status_db === 'FOR VERIFICATION' || ($has_proof_file && $payment_status_db !== 'PENDING')));
                    $is_unpaid = (!$is_fully_paid && !$is_for_verification && !$is_rejected);

                    if ($is_fully_paid) {
                        $payment_badge_class = 'bg-success text-white';
                        $payment_status_label = 'PAID';
                    } elseif ($is_rejected) {
                        $payment_badge_class = 'bg-danger text-white';
                        $payment_status_label = 'REJECTED';
                    } elseif ($is_for_verification) {
                        $payment_badge_class = 'bg-warning text-dark';
                        $payment_status_label = 'FOR VERIFICATION';
                    } else {
                        $payment_badge_class = 'bg-danger text-white';
                        $payment_status_label = 'UNPAID';
                    }

                    $svc_badge_map = [
                        'PENDING' => 'bg-warning text-dark',
                        'APPROVED' => 'bg-info text-white',
                        'IN PROGRESS' => 'bg-primary text-white',
                        'COMPLETED' => 'bg-success text-white',
                        'REJECTED' => 'bg-danger text-white',
                        'CANCELLED' => 'bg-secondary text-white'
                    ];
                    $svc_badge_class = $svc_badge_map[$service_status_db] ?? 'bg-secondary text-white';

                    $official_or = !empty($booking['official_receipt_number']) ? $booking['official_receipt_number'] : '';
                    $payment_method_label = !empty($booking['receipt_payment_method']) ? $booking['receipt_payment_method'] : (!empty($booking['payment_type']) ? ucwords($booking['payment_type']) : 'GCash');
                    $paid_date_display = !empty($proof_date) ? $proof_date : (!empty($booking['created_at']) ? $booking['created_at'] : date('Y-m-d H:i:s'));
                    $paid_ref = !empty($booking['gcash_reference_number']) ? $booking['gcash_reference_number'] : (!empty($booking['reference_number']) ? $booking['reference_number'] : '');
                    $user_display_name = !empty($booking['customer_name']) ? trim($booking['customer_name']) : (!empty($booking['full_name']) ? trim($booking['full_name']) : (!empty($user['first_name']) ? trim($user['first_name'] . ' ' . ($user['last_name'] ?? '')) : 'Customer'));
                    ?>
                    <div class="booking-card" id="bookingCard_<?php echo (int)$booking['id']; ?>" data-status="<?php echo htmlspecialchars($status); ?>" data-payment-status="<?php echo htmlspecialchars(strtolower($payment_status_label)); ?>" data-service-status="<?php echo htmlspecialchars(strtolower($service_status_db)); ?>">

                        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
                            <div>
                                <h5 class="fw-bold mb-0" style="color: #7b1113; font-size: 1.12rem;">
                                    Reservation
                                </h5>
                                <span class="text-muted small"><i class="fas fa-calendar-alt me-1"></i> <?php echo date('M j, Y', strtotime($booking['preferred_date'] ?: $booking['created_at'])); ?></span>
                            </div>
                            <div class="d-flex gap-1 flex-wrap">
                                <span class="badge <?php echo $payment_badge_class; ?> px-2 py-1 text-uppercase fw-semibold" style="letter-spacing: 0.04em; font-size: 0.78rem;">
                                    <i class="fas <?php echo $is_fully_paid ? 'fa-check-circle' : ($is_for_verification ? 'fa-hourglass-half' : 'fa-clock'); ?> me-1"></i>
                                    Payment: <?php echo $payment_status_label; ?>
                                </span>
                                <?php if ($booking['purpose'] === 'cemetery_service' || !empty($booking['service_type'])): ?>
                                    <span class="badge <?php echo $svc_badge_class; ?> px-2 py-1 text-uppercase fw-semibold" style="letter-spacing: 0.04em; font-size: 0.78rem;">
                                        <i class="fas <?php echo $service_status_db === 'COMPLETED' ? 'fa-check-double' : 'fa-tools'; ?> me-1"></i>
                                        Service: <?php echo $service_status_db; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($status === 'rejected' || $service_status_db === 'REJECTED'): ?>
                            <div class="alert alert-danger py-2 px-3 mb-3 small d-flex align-items-start gap-2 border-danger-subtle" style="background-color: #fef2f2; border-radius: 8px; color: #991b1b;">
                                <i class="fas fa-exclamation-circle text-danger mt-1 flex-shrink-0"></i>
                                <div>
                                    <strong class="d-block mb-1">Reservation Application Rejected</strong>
                                    <span><strong>Remarks / Reason:</strong> <?php echo !empty($booking['remarks']) ? htmlspecialchars($booking['remarks']) : 'Application could not be approved at this time. Please contact administration for assistance.'; ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($status === 'cancelled'): ?>
                            <?php $cancelReasonTxt = trim((string)($booking['cancellation_reason'] ?? '')) !== '' ? $booking['cancellation_reason'] : 'Reservation cancelled.'; ?>
                            <div class="alert alert-secondary py-2 px-3 mb-3 small d-flex align-items-start gap-2" style="background-color: #f1f5f9; border-radius: 8px; color: #334155; border: 1px solid #cbd5e1;">
                                <i class="fas fa-ban mt-1 flex-shrink-0"></i>
                                <div>
                                    <strong class="d-block mb-1">Reservation Cancelled</strong>
                                    <span class="d-block"><strong>Reason:</strong> <?php echo htmlspecialchars($cancelReasonTxt); ?></span>
                                    <?php if (!empty($booking['cancelled_by'])): ?>
                                        <span class="d-block"><strong>Cancelled By:</strong> <?php echo htmlspecialchars($booking['cancelled_by']); ?></span>
                                    <?php endif; ?>
                                    <span class="d-block mt-1">Total Paid (preserved): <strong class="text-success">₱<?php echo number_format($amount_paid, 2); ?></strong> • Refund: <strong class="text-danger">NON-REFUNDABLE</strong></span>
                                    <span class="d-block mt-1">Payments made are non-refundable according to the cemetery's policy. Payment history remains visible below.</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="booking-info-grid mb-3">
                            <div class="info-item">
                                <span class="info-label">Section</span>
                                <span class="info-value"><?php echo htmlspecialchars($booking['section'] ?: 'N/A'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Location</span>
                                <span class="info-value text-truncate" title="<?php echo htmlspecialchars('Section ' . $booking['section'] . ' • Plot ' . format_plot_number($booking['plot'], $booking['section'])); ?>">
                                    <?php echo htmlspecialchars('Section ' . $booking['section'] . ' • Plot ' . format_plot_number($booking['plot'], $booking['section'])); ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Contact</span>
                                <span class="info-value"><?php echo htmlspecialchars($booking['contact_number'] ?: 'N/A'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Amount</span>
                                <span class="info-value text-danger fw-bold">₱<?php echo number_format($booking_total, 2); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Payment Status</span>
                                <span class="info-value">
                                    <span class="badge <?php echo $payment_badge_class; ?> px-2 py-1" style="font-size: 0.78rem;">
                                        <?php echo $payment_status_label; ?> <?php if ($is_fully_paid) echo '(₱' . number_format($amount_paid, 2) . ')'; ?>
                                    </span>
                                </span>
                            </div>
                            <?php if ($booking['purpose'] === 'cemetery_service' || !empty($booking['service_type'])): ?>
                            <div class="info-item">
                                <span class="info-label">Service Status</span>
                                <span class="info-value">
                                    <span class="badge <?php echo $svc_badge_class; ?> px-2 py-1" style="font-size: 0.78rem;">
                                        <?php echo $service_status_db; ?>
                                    </span>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                            <?php if (!empty($booking['section'])): 
                                $bSec = trim($booking['section']);
                                $bPlot = trim($booking['plot'] ?? '');
                                $plotParts = preg_split('/[-_,\s]+/', preg_replace('/^[A-Za-z]+[-_\s]*/', '', $bPlot));
                                $bPx = !empty($plotParts[0]) ? trim($plotParts[0]) : '';
                                $bPy = !empty($plotParts[1]) ? trim($plotParts[1]) : '';
                                $mapUrl = "sections/popup.php?section=" . urlencode($bSec) . "&plot=" . urlencode($bPlot);
                                if ($bPx && $bPy) {
                                    $mapUrl .= "&plot_x=" . urlencode($bPx) . "&plot_y=" . urlencode($bPy);
                                }
                                $mapUrl .= "&search_highlight=1&auto_open=1&from=user_booking";
                            ?>
                                <a href="<?php echo htmlspecialchars($mapUrl); ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-map-marked-alt me-1"></i> View Cemetery
                                </a>
                            <?php endif; ?>

                            <?php 
                            $purpose = $booking['purpose'] ?? 'reserve_grave';
                            if ($purpose === 'reserve_grave') $purpose_text = 'Reserve New Grave';
                            elseif ($purpose === 'add_deceased') $purpose_text = 'Add Additional Deceased';
                            elseif ($purpose === 'cemetery_service') $purpose_text = 'Cemetery Service (' . htmlspecialchars($booking['service_type'] ?? 'Service') . ')';
                            else $purpose_text = ucwords(str_replace('_', ' ', $purpose));
                            ?>

                            <button type="button" class="btn btn-sm btn-primary" onclick="openUserBookingSummaryModal(<?php echo (int)$booking['id']; ?>)">
                                <i class="fas fa-file-invoice me-1"></i> View Reservation
                            </button>

                            <button type="button" class="btn btn-sm btn-outline-primary toggle-details-btn" data-bs-toggle="collapse" data-bs-target="#bookingDetails_<?php echo (int)$booking['id']; ?>" aria-expanded="false">
                                <span class="toggle-text">Details <i class="fas fa-chevron-down ms-1"></i></span>
                            </button>
                        </div>

                        <script>
                        window.userBookingsData = window.userBookingsData || {};
                        window.userBookingsData[<?php echo (int)$booking['id']; ?>] = <?php echo json_encode([
                            'id' => (int)$booking['id'],
                            'purpose' => $purpose_text,
                            'section' => $booking['section'] ?? 'A',
                            'plot' => $booking['plot'] ?? '',
                            'location' => 'Section ' . ($booking['section'] ?: 'N/A') . ' • Plot ' . format_plot_number($booking['plot'], $booking['section']),
                            'customer' => $user_display_name ?? 'Customer',
                            'date' => date('M j, Y h:i A', strtotime($booking['created_at'] ?? 'now')),
                            'method' => $payment_method_label ?? 'GCash',
                            'reference' => $paid_ref ?: ($official_or ?: ($booking['reference_number'] ?: ('BK-' . $booking['id']))),
                            'amount_paid' => number_format((float)($amount_paid ?? 0), 2),
                            'total_amount' => number_format((float)($booking_total ?? 0), 2),
                            'remaining_balance' => number_format((float)($remaining_balance ?? 0), 2),
                            'status' => $booking['status'] ?? 'pending',
                            'map_url' => $mapUrl ?? ''
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                        </script>

                        <div class="collapse booking-card-expanded" id="bookingDetails_<?php echo (int)$booking['id']; ?>">
                            <div class="p-3 mt-3 bg-light rounded border">

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <div class="p-3 bg-white rounded border h-100">
                                            <div class="fw-bold mb-2 text-secondary" style="font-size: 0.88rem;">
                                                <i class="fas fa-file-invoice me-1 text-danger"></i> Booking Details
                                            </div>
                                            <div class="small text-muted mb-1">
                                                <strong>Purpose / Service:</strong> 
                                                <span class="text-dark">
                                                    <?php echo $purpose_text; ?>
                                                </span>
                                            </div>
                                            <div class="small text-muted mb-1">
                                                <strong>Client Address:</strong> 
                                                <span class="text-dark"><?php echo htmlspecialchars($booking['booker_location'] ?: 'N/A'); ?></span>
                                            </div>
                                            <div class="small text-muted mb-1">
                                                <strong>Transaction Reference:</strong>
                                                <?php if (!empty($booking['reference_number'])): ?>
                                                    <code class="bg-light border px-2 py-0 rounded text-dark"><?php echo htmlspecialchars($booking['reference_number']); ?></code>
                                                <?php else: ?>
                                                    <span class="text-muted">Not Generated</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small text-muted">
                                                <strong>Special Requests:</strong> 
                                                <span class="text-dark"><?php echo !empty($booking['special_requests']) ? htmlspecialchars($booking['special_requests']) : 'No special requests'; ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="p-3 bg-white rounded border h-100">
                                            <div class="fw-bold mb-2 text-secondary" style="font-size: 0.88rem;">
                                                <i class="fas fa-coins me-1 text-danger"></i> Financial Summary
                                            </div>
                                            <?php if ($plot_price > 0): ?>
                                                <div class="small d-flex justify-content-between mb-1">
                                                    <span class="text-muted">Grave/Plot Price:</span>
                                                    <span class="fw-semibold text-dark">₱<?php echo number_format($plot_price, 2); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($service_price > 0): ?>
                                                <div class="small d-flex justify-content-between mb-1">
                                                    <span class="text-muted">Service Fee:</span>
                                                    <span class="fw-semibold text-dark">₱<?php echo number_format($service_price, 2); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($interment_fee > 0): ?>
                                                <div class="small d-flex justify-content-between mb-1">
                                                    <span class="text-muted">Interment Fee:</span>
                                                    <span class="fw-semibold text-dark">₱<?php echo number_format($interment_fee, 2); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="small d-flex justify-content-between mb-1 pt-1 border-top">
                                                <span class="text-muted fw-bold">Total Reservation Amount:</span>
                                                <span class="fw-bold text-dark">₱<?php echo number_format($booking_total, 2); ?></span>
                                            </div>
                                            <?php if (($booking['purpose'] ?? '') !== 'cemetery_service' && ($booking['payment_plan'] ?? '') === 'installment'): ?>
                                            <div class="small d-flex justify-content-between mb-1">
                                                <span class="text-muted">20% Downpayment Required:</span>
                                                <span class="fw-bold text-primary">₱<?php echo number_format(round($booking_total * 0.20, 2), 2); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <div class="small d-flex justify-content-between mb-1">
                                                <span class="text-muted">Amount Paid:</span>
                                                <span class="fw-bold text-success">₱<?php echo number_format($amount_paid, 2); ?></span>
                                            </div>
                                            <div class="small d-flex justify-content-between pt-1 border-top">
                                                <span class="text-muted fw-bold">Remaining Balance:</span>
                                                <?php if ($remaining_balance <= 0 && $amount_paid > 0): ?>
                                                    <span class="fw-bold text-success">₱0.00 (Fully Paid)</span>
                                                <?php elseif ($remaining_balance <= 0): ?>
                                                    <span class="fw-bold text-muted">₱0.00</span>
                                                <?php else: ?>
                                                    <span class="fw-bold text-danger">₱<?php echo number_format($remaining_balance, 2); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php
                                $isPlotPurchase = ($booking['purpose'] === 'reserve_grave' || $booking['booking_type'] === 'another_plot' || $booking['booking_type'] === 'plot_purchase' || (float)($booking['plot_price'] ?? 0) > 0);
                                $cardPlotsQuery = $conn->query("SELECT section, plot_number, lawn_type, price_at_booking, status FROM booking_plots WHERE booking_id = " . (int)$booking['id']);
                                if ($cardPlotsQuery && $cardPlotsQuery->num_rows > 0):
                                ?>

                                <div class="p-3 mb-3 bg-white rounded border">
                                    <div class="fw-bold mb-2 text-secondary" style="font-size: 0.88rem;">
                                        <i class="fas fa-layer-group me-1 text-danger"></i> <?php echo $isPlotPurchase ? 'Reserved Plots' : 'Grave / Plot Location'; ?> (<?php echo $cardPlotsQuery->num_rows; ?> Plot<?php echo $cardPlotsQuery->num_rows > 1 ? 's' : ''; ?>)
                                    </div>
                                    <div class="table-responsive" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                                        <table class="table table-sm table-bordered align-middle mb-0" style="font-size: 0.78rem;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="white-space: nowrap;">Plot #</th>
                                                    <th style="white-space: nowrap;">Section</th>
                                                    <th style="white-space: nowrap;">Lawn Type</th>
                                                    <th style="white-space: nowrap;">Plot Price</th>
                                                    <th style="white-space: nowrap;">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($cp = $cardPlotsQuery->fetch_assoc()): ?>
                                                    <?php
                                                    $plotPriceVal = (float)$cp['price_at_booking'];
                                                    $displayPlotPrice = ($isPlotPurchase && $plotPriceVal > 0)
                                                        ? ('₱' . number_format($plotPriceVal, 2))
                                                        : '<span class="text-muted">₱0.00 <small class="text-secondary">(Owned Plot)</small></span>';
                                                    ?>
                                                    <tr>
                                                        <td class="fw-bold text-primary"><?php echo htmlspecialchars($cp['plot_number']); ?></td>
                                                        <td>Section <?php echo htmlspecialchars($cp['section']); ?></td>
                                                        <td><?php echo htmlspecialchars($cp['lawn_type']); ?></td>
                                                        <td class="fw-bold text-dark"><?php echo $displayPlotPrice; ?></td>
                                                        <td><span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><?php echo strtoupper($cp['status']); ?></span></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (!$is_fully_paid && (strtolower($booking['payment_type'] ?? '') === 'gcash' || strtolower($booking['bank_name'] ?? '') === 'gcash' || empty($booking['payment_type']))): ?>
                                <div class="gcash-payment-card p-3 rounded mb-3 bg-white border" style="border-left: 3px solid #7b1113 !important;">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="fw-bold text-danger" style="font-size: 0.88rem;">
                                            <i class="fas fa-wallet me-1"></i> GCash Payment Details
                                        </div>
                                        <?php if ($remaining_balance > 0 && !$is_fully_paid): ?>
                                            <div class="d-flex gap-1">
                                                <button type="button" class="btn btn-sm btn-primary py-0 px-2" onclick="showGcashQrModal(<?php echo (int)$booking['id']; ?>, '<?php echo number_format($remaining_balance, 2); ?>', '<?php echo htmlspecialchars(addslashes($gcash_payment['account_name'])); ?>', '<?php echo htmlspecialchars(addslashes($gcash_payment['display_number'])); ?>', '')" style="font-size: 0.78rem;">
                                                    <i class="fas fa-credit-card me-1"></i> Pay via GCash
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="row g-2 small text-secondary">
                                        <div class="col-sm-6">
                                            <span class="text-muted">Account Name:</span>
                                            <div class="fw-semibold text-dark"><?php echo htmlspecialchars($gcash_payment['account_name']); ?></div>
                                        </div>
                                        <div class="col-sm-6">
                                            <span class="text-muted">GCash Number:</span>
                                            <div class="d-flex align-items-center gap-2 mt-1">
                                                <code class="bg-light border px-2 py-1 rounded text-dark fw-bold" style="font-size: 0.92rem;"><?php echo htmlspecialchars($gcash_payment['display_number']); ?></code>
                                                <button type="button" class="btn btn-sm btn-outline-secondary copy-gcash-btn py-0 px-2" data-gcash="<?php echo htmlspecialchars($gcash_payment['display_number']); ?>" style="font-size: 0.78rem; height: 26px;">
                                                    <i class="fas fa-copy me-1"></i> Copy
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($is_fully_paid || $amount_paid > 0): ?>
                                <!-- PAID TRANSACTION & PROOF CARD -->
                                <div class="payment-proof-card p-3 rounded bg-white border mb-2" style="border-left: 3px solid #198754 !important;">
                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                        <div class="fw-bold text-success" style="font-size: 0.85rem;">
                                            <i class="fas fa-check-circle me-1"></i> Paid Transaction &amp; Payment Proof
                                        </div>
                                        <span class="badge <?php echo $is_fully_paid ? 'bg-success' : 'bg-primary'; ?> text-white text-wrap text-start" style="font-size: 0.72rem; max-width: 100%; white-space: normal; line-height: 1.3;">
                                            <i class="fas <?php echo $is_fully_paid ? 'fa-check-double' : 'fa-receipt'; ?> me-1"></i> <?php echo $is_fully_paid ? 'Paid in Full (₱' . number_format($amount_paid, 2) . ')' : 'Payment Recorded (₱' . number_format($amount_paid, 2) . ')'; ?>
                                        </span>
                                    </div>

                                    <div class="p-2 mb-3 bg-light rounded border">
                                        <div class="row g-2 small">
                                            <div class="col-sm-6">
                                                <span class="text-muted d-block">Official Receipt / Reference:</span>
                                                <code class="bg-white border px-2 py-1 rounded text-dark fw-bold" style="font-size: 0.88rem; display: inline-block;">
                                                    <?php echo htmlspecialchars($official_or ?: ($paid_ref ?: ('BK-' . $booking['id']))); ?>
                                                </code>
                                            </div>
                                            <div class="col-sm-6">
                                                <span class="text-muted d-block">Payment Method &amp; Date:</span>
                                                <div class="fw-semibold text-dark mt-1">
                                                    <span class="badge bg-primary text-white me-1"><?php echo htmlspecialchars($payment_method_label); ?></span>
                                                    <?php echo date('M j, Y h:i A', strtotime($paid_date_display)); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <div class="small text-muted">
                                            <i class="fas fa-shield-alt text-success me-1"></i> Verified GCash payment. You can view or download the receipt below.
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <button type="button" class="btn btn-sm btn-success text-white" onclick="openPaidTransactionModal(<?php echo (int)$booking['id']; ?>)">
                                                <i class="fas fa-receipt me-1"></i> View Paid Transaction
                                            </button>
                                            <?php if ($has_proof_file): ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="viewPaymentProof(<?php echo (int)$booking['id']; ?>, '<?php echo htmlspecialchars($proof_file); ?>')">
                                                    <i class="fas fa-image me-1"></i> View Payment Proof
                                                </button>
                                            <?php endif; ?>
                                            <a href="payment-success.php?booking_id=<?php echo (int)$booking['id']; ?><?php echo !empty($booking['payment_id']) ? ('&payment_id=' . (int)$booking['payment_id']) : ''; ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Download or print official receipt">
                                                <i class="fas fa-download me-1"></i> Download Receipt
                                            </a>
                                            <?php if ($service_status_db === 'COMPLETED' && !empty($service_proof_file)): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success" onclick="viewServiceProofModal('<?php echo htmlspecialchars($service_proof_file); ?>', '<?php echo (int)$booking['id']; ?>', '<?php echo htmlspecialchars(addslashes($booking['service_type'] ?? 'Service')); ?>')">
                                                    <i class="fas fa-camera me-1"></i> View Completion Proof
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <script>
                                window.userBookingsData = window.userBookingsData || {};
                                window.userBookingsData[<?php echo (int)$booking['id']; ?>] = <?php echo json_encode([
                                    'id' => (int)$booking['id'],
                                    'payment_id' => (int)($booking['payment_id'] ?? 0),
                                    'reference' => $paid_ref ?: ($official_or ?: ('BK-' . $booking['id'])),
                                    'or_number' => $official_or,
                                    'amount_paid' => number_format($amount_paid, 2),
                                    'method' => $payment_method_label,
                                    'date' => date('M j, Y h:i A', strtotime($paid_date_display)),
                                    'purpose' => $purpose_text,
                                    'location' => 'Section ' . ($booking['section'] ?: 'N/A') . ' • Plot ' . format_plot_number($booking['plot'], $booking['section']),
                                    'customer' => $user_display_name,
                                    'proof_file' => $proof_file,
                                    'status' => $is_fully_paid ? 'PAID' : 'PARTIAL'
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                                </script>

                                <?php elseif ($is_rejected): ?>
                                <!-- PAYMENT PROOF REJECTED CARD (Allows Resubmission) -->
                                <div class="payment-proof-card p-3 rounded bg-white border mb-2" style="border-left: 3px solid #dc3545 !important;">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="fw-bold text-danger" style="font-size: 0.88rem;">
                                            <i class="fas fa-times-circle me-1"></i> Payment Proof Rejected — Resubmission Required
                                        </div>
                                        <span class="badge bg-danger text-white" style="font-size: 0.72rem;">
                                            REJECTED
                                        </span>
                                    </div>
                                    <div class="p-2 mb-3 bg-light rounded border">
                                        <div class="small text-muted mb-1">
                                            Your previous payment proof submission could not be verified by the administrator. Please upload a clear and valid GCash receipt screenshot to continue.
                                        </div>
                                        <?php if (!empty($gcash_ref)): ?>
                                            <div class="small"><strong>Previous Ref:</strong> <code class="bg-white border px-1 rounded text-dark"><?php echo htmlspecialchars($gcash_ref); ?></code></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-end gap-2 flex-wrap">
                                        <button type="button" class="btn btn-sm btn-danger text-white px-3" onclick="openUploadProofModal(<?php echo (int)$booking['id']; ?>, '<?php echo htmlspecialchars(addslashes($booking['grave_location'] ?: ('Booking #' . $booking['id']))); ?>', '<?php echo number_format($remaining_balance > 0 ? $remaining_balance : $booking_total, 2, '.', ''); ?>')">
                                            <i class="fas fa-redo me-1"></i> Resubmit Payment Proof
                                        </button>
                                    </div>
                                </div>

                                <?php elseif ($is_for_verification): ?>
                                <!-- PAYMENT PROOF SUBMITTED - FOR VERIFICATION CARD -->
                                <div class="payment-proof-card p-3 rounded bg-white border mb-2" style="border-left: 3px solid #ffc107 !important;">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="fw-bold text-warning-emphasis" style="font-size: 0.88rem;">
                                            <i class="fas fa-hourglass-half me-1"></i> Payment Proof Submitted — For Verification
                                        </div>
                                        <span class="badge bg-warning text-dark" style="font-size: 0.72rem;">
                                            <i class="fas fa-clock me-1"></i> Verification Pending
                                        </span>
                                    </div>
                                    <div class="p-2 mb-3 bg-light rounded border">
                                        <div class="small text-muted mb-1">
                                            Your proof of payment has been uploaded and submitted for administrator verification.
                                        </div>
                                        <?php if (!empty($gcash_ref)): ?>
                                            <div class="small"><strong>GCash Reference:</strong> <code class="bg-white border px-1 rounded text-dark"><?php echo htmlspecialchars($gcash_ref); ?></code></div>
                                        <?php endif; ?>
                                        <?php if (!empty($proof_date)): ?>
                                            <div class="small text-muted mt-1">Uploaded: <?php echo date('M j, Y h:i A', strtotime($proof_date)); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <div class="small text-muted">
                                            <i class="fas fa-info-circle me-1"></i> Admin will verify your payment and issue your official receipt shortly.
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <?php if ($has_proof_file): ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="viewPaymentProof(<?php echo (int)$booking['id']; ?>, '<?php echo htmlspecialchars($proof_file); ?>')">
                                                    <i class="fas fa-image me-1"></i> View Submitted Proof
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openUploadProofModal(<?php echo (int)$booking['id']; ?>, '<?php echo htmlspecialchars(addslashes($booking['grave_location'] ?: ('Booking #' . $booking['id']))); ?>', '<?php echo number_format($remaining_balance, 2, '.', ''); ?>')">
                                                <i class="fas fa-redo me-1"></i> Update Proof
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <?php elseif (!in_array($status, ['cancelled', 'rejected', 'declined', 'canceled'], true)): ?>
                                <!-- UNPAID / PROOF OF PAYMENT CARD (Active Bookings Only) -->
                                <?php 
                                $downpayment_required = round($booking_total * 0.20, 2);
                                ?>
                                <div class="payment-proof-card p-3 rounded bg-white border text-center" style="border-left: 4px solid #dc3545 !important;">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="fw-bold text-danger" style="font-size: 0.9rem;">
                                            <i class="fas fa-file-invoice-dollar me-1"></i> Payment &amp; Proof of Payment
                                        </div>
                                        <span class="badge bg-danger text-white px-2 py-1" style="font-size: 0.72rem;">UNPAID</span>
                                    </div>

                                    <div class="p-3 mb-3 bg-light rounded border">
                                        <div class="row g-2 justify-content-center text-center">
                                            <div class="col-6 col-md-3">
                                                <span class="text-muted small d-block">Total Price</span>
                                                <strong class="text-dark fs-6">₱<?php echo number_format($booking_total, 2); ?></strong>
                                            </div>
                                            <?php if (($booking['purpose'] ?? '') !== 'cemetery_service' && ($booking['payment_plan'] ?? '') === 'installment'): ?>
                                            <div class="col-6 col-md-3">
                                                <span class="text-muted small d-block">20% Downpayment Required</span>
                                                <strong class="text-primary fs-6">₱<?php echo number_format($downpayment_required, 2); ?></strong>
                                            </div>
                                            <?php endif; ?>
                                            <div class="col-6 col-md-3">
                                                <span class="text-muted small d-block">Amount Paid</span>
                                                <strong class="text-success fs-6">₱<?php echo number_format($amount_paid, 2); ?></strong>
                                            </div>
                                            <div class="col-6 col-md-3">
                                                <span class="text-muted small d-block">Remaining Balance</span>
                                                <strong class="text-danger fs-6">₱<?php echo number_format($remaining_balance > 0 ? $remaining_balance : $booking_total, 2); ?></strong>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-center gap-3 mt-2 pt-2 border-top small text-secondary flex-wrap">
                                            <span><strong>Payment Method:</strong> <?php echo htmlspecialchars($payment_method_label); ?></span>
                                            <span><strong>Payment Status:</strong> <span class="badge bg-danger text-white">UNPAID</span></span>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-center align-items-center flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-primary px-3" onclick="showGcashQrModal(<?php echo (int)$booking['id']; ?>, '<?php echo number_format($remaining_balance > 0 ? $remaining_balance : $booking_total, 2); ?>', '<?php echo htmlspecialchars(addslashes($gcash_payment['account_name'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($gcash_payment['display_number'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($gcash_payment['qr_image'] ?? 'gcashqr1.jpg')); ?>')">
                                            <i class="fas fa-credit-card me-1"></i> Pay via GCash
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger px-3" onclick="openUploadProofModal(<?php echo (int)$booking['id']; ?>, '<?php echo htmlspecialchars(addslashes($booking['grave_location'] ?: ('Booking #' . $booking['id']))); ?>', '<?php echo number_format($remaining_balance > 0 ? $remaining_balance : $booking_total, 2, '.', ''); ?>')">
                                            <i class="fas fa-upload me-1"></i> Upload Proof of Payment
                                        </button>
                                    </div>
                                </div>
                                <?php else: ?>
                                <!-- CANCELLED / REJECTED BOOKING STATUS CARD -->
                                <div class="payment-proof-card p-3 rounded bg-light border text-center mb-2" style="border-left: 4px solid #6c757d !important;">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="fw-bold text-secondary" style="font-size: 0.9rem;">
                                            <i class="fas fa-ban me-1"></i> Booking <?php echo ucfirst($status); ?>
                                        </div>
                                        <span class="badge bg-secondary text-white px-2 py-1" style="font-size: 0.72rem;"><?php echo strtoupper($status); ?></span>
                                    </div>
                                    <div class="p-2 bg-white rounded border text-start small text-muted">
                                        <i class="fas fa-info-circle me-1 text-secondary"></i> This booking application has been <?php echo htmlspecialchars($status); ?>. No further payments or proof of payment are required. Any reserved plots have been released.
                                        <?php if (!empty($booking['cancellation_reason'])): ?>
                                            <div class="mt-2 text-dark"><strong>Cancellation Reason:</strong> <?php echo htmlspecialchars($booking['cancellation_reason']); ?></div>
                                        <?php elseif (!empty($booking['remarks'])): ?>
                                            <div class="mt-2 text-dark"><strong>Admin Remarks:</strong> <?php echo htmlspecialchars($booking['remarks']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if (in_array($status, ['pending', 'approved'], true)): ?>
                                <div class="d-flex justify-content-center mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-danger px-3" onclick="userCancelBooking(<?php echo (int)$booking['id']; ?>)">
                                        <i class="fas fa-times-circle me-1"></i> Cancel Booking
                                    </button>
                                </div>
                                <div class="form-text text-center" style="font-size:0.72rem;">Paid amounts are non-refundable if you cancel.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h6 class="fw-bold text-dark mb-1">No Recent Reservations</h6>
                    <p class="text-muted mb-3">You don't have any reservations in your account yet.</p>
                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookingModal">
                            <i class="fas fa-plus me-1"></i> Make a Reservation
                        </button>
                        <a href="map.php" class="btn btn-outline-primary">
                            <i class="fas fa-map me-1"></i> View Cemetery Map
                        </a>
                    </div>
            <?php endif; ?>
        </section>

        <?php if ($schedule_tables_ok): ?>
        <section class="dashboard-section-card" id="paymentPlansSection">
            <div class="section-title">
                <div class="section-title-text">
                    <i class="fas fa-credit-card text-danger"></i> Installment Payment Plans
                </div>
            </div>
            <?php if (!empty($payment_plans)): ?>
                <?php
                // Consolidated calculations across all installment plans
                $combined_total_contract = 0;
                $combined_total_paid = 0;
                $combined_remaining_balance = 0;
                $combined_total_dues = 0;
                $combined_paid_dues = 0;
                $plan_count = count($payment_plans);

                foreach ($payment_plans as $idx => &$cPlan) {
                    $p_total = (float)$cPlan['total_amount'];
                    $p_paid = (float)$cPlan['amount_paid'];
                    $p_bal = max(0, $p_total - $p_paid);

                    $t_inst = count($cPlan['schedule']) > 0 ? count($cPlan['schedule']) : (int)($cPlan['payment_term'] ?: 12);
                    $p_cnt = 0;
                    $p_pend_cnt = 0;
                    if (!empty($cPlan['schedule'])) {
                        foreach ($cPlan['schedule'] as $chk_inst) {
                            $c_num = (int)($chk_inst['installment_number'] ?? 0);
                            $c_rcpt = $cPlan['receipts_by_inst'][$c_num] ?? null;
                            $c_st = strtoupper(trim($chk_inst['status'] ?? ''));
                            $c_rcpt_st = $c_rcpt ? strtoupper(trim((string)($c_rcpt['payment_status'] ?? ''))) : '';
                            $c_paid_amt = (float)($chk_inst['paid_amount'] ?? ($chk_inst['amount_paid'] ?? 0));
                            if ($c_st === 'PAID' || $c_paid_amt > 0 || in_array($c_rcpt_st, ['PAID', 'VERIFIED'], true)) {
                                $p_cnt++;
                            } elseif (in_array($c_rcpt_st, ['PENDING', 'FOR VERIFICATION'], true) || (!empty($c_rcpt['proof_filename']) && $c_rcpt_st !== 'REJECTED')) {
                                $p_pend_cnt++;
                            }
                        }
                    }
                    $cPlan['_computed_paid_count'] = $p_cnt;
                    $cPlan['_computed_pending_count'] = $p_pend_cnt;
                    $cPlan['_computed_total_inst'] = $t_inst;
                    $cPlan['_computed_balance'] = $p_bal;
                    $cPlan['_progress_percent'] = $t_inst > 0 ? round(($p_cnt / $t_inst) * 100) : ($p_bal <= 0.01 && $p_paid > 0 ? 100 : 0);

                    $combined_total_contract += $p_total;
                    $combined_total_paid += $p_paid;
                    $combined_remaining_balance += $p_bal;
                    $combined_total_dues += $t_inst;
                    $combined_paid_dues += $p_cnt;
                }
                unset($cPlan);

                $combined_progress = $combined_total_dues > 0 ? round(($combined_paid_dues / $combined_total_dues) * 100) : ($combined_remaining_balance <= 0.01 && $combined_total_paid > 0 ? 100 : 0);
                ?>

                <!-- 1. CONSOLIDATED OVERVIEW (PINAGSAMA) -->
                <div class="installment-summary-card mb-3 p-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2 pb-2 border-bottom">
                        <div>
                            <span class="text-uppercase fw-bold text-muted" style="font-size: 0.70rem; letter-spacing: 0.05em;">Consolidated Installments Summary</span>
                            <h6 class="fw-bold mb-0 text-dark" style="font-size: 0.96rem;">
                                <i class="fas fa-layer-group text-danger me-1"></i> Total Account Installments (<?php echo $plan_count; ?> Plot<?php echo $plan_count > 1 ? 's' : ''; ?>)
                            </h6>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($combined_remaining_balance <= 0.01 && $combined_total_paid > 0 && $combined_paid_dues >= $combined_total_dues): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 0.75rem;">
                                    <i class="fas fa-check-circle me-1"></i> All Plans Fully Settled
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1" style="font-size: 0.75rem;">
                                    <i class="fas fa-clock me-1"></i> Overall Balance: ₱<?php echo number_format($combined_remaining_balance, 2); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row g-2 text-center installment-metrics-row mb-2">
                        <div class="col-4">
                            <div class="installment-metric-box p-1 p-sm-2 rounded bg-white border">
                                <div class="metric-label text-muted small">Combined Total Plan</div>
                                <div class="metric-value fw-bold text-dark">₱<?php echo number_format($combined_total_contract, 2); ?></div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="installment-metric-box p-1 p-sm-2 rounded bg-white border">
                                <div class="metric-label text-muted small">Combined Total Paid</div>
                                <div class="metric-value fw-bold text-success">₱<?php echo number_format($combined_total_paid, 2); ?></div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="installment-metric-box p-1 p-sm-2 rounded border" style="background: #fff5f5; border-color: #fecaca !important;">
                                <div class="metric-label text-danger small fw-semibold">Combined Balance</div>
                                <div class="metric-value fw-bold text-danger">₱<?php echo number_format($combined_remaining_balance, 2); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between small text-muted mb-1" style="font-size: 0.74rem;">
                        <span><strong><?php echo $combined_paid_dues; ?> of <?php echo $combined_total_dues; ?></strong> overall installment dues completed</span>
                        <span class="fw-bold text-dark"><?php echo $combined_progress; ?>%</span>
                    </div>
                    <div class="progress" style="height: 6px; border-radius: 3px; background: #e2e8f0;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $combined_progress; ?>%;" aria-valuenow="<?php echo $combined_progress; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>

                <!-- 2. PLOT SELECTOR (PARA HINDI MADAMI TIGNAN) -->
                <?php if ($plan_count > 1): ?>
                <div class="plot-plan-selector-wrap mb-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="small fw-bold text-secondary text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                            <i class="fas fa-map-marker-alt me-1 text-danger"></i> Select Plot to View Schedule &amp; Make Payment
                        </span>
                        <span class="badge bg-light text-muted border" style="font-size: 0.70rem;">Individual Plot Payment</span>
                    </div>
                    <ul class="nav nav-pills plot-plan-pills gap-2 flex-wrap" id="installmentPlotsTab" role="tablist">
                        <?php foreach ($payment_plans as $idx => $pTab): ?>
                            <?php 
                            $isFirst = ($idx === 0);
                            $pBal = $pTab['_computed_balance'];
                            $pPaidCnt = $pTab['_computed_paid_count'];
                            $pTotInst = $pTab['_computed_total_inst'];
                            $isSettled = ($pBal <= 0.01 && $pTab['amount_paid'] > 0 && $pPaidCnt >= $pTotInst);
                            ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link plot-pill-btn <?php echo $isFirst ? 'active' : ''; ?>" 
                                        id="plan-tab-<?php echo (int)$pTab['id']; ?>" 
                                        data-bs-toggle="pill" 
                                        data-bs-target="#plan-pane-<?php echo (int)$pTab['id']; ?>" 
                                        type="button" 
                                        role="tab" 
                                        aria-controls="plan-pane-<?php echo (int)$pTab['id']; ?>" 
                                        aria-selected="<?php echo $isFirst ? 'true' : 'false'; ?>">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas fa-map-pin"></i>
                                        <div class="text-start">
                                            <div class="fw-bold plot-pill-title">Section <?php echo htmlspecialchars($pTab['section']); ?> • Plot <?php echo htmlspecialchars(format_plot_number($pTab['plot'], $pTab['section'])); ?></div>
                                            <div class="plot-pill-subtitle">
                                                Booking #<?php echo (int)$pTab['id']; ?> • 
                                                <?php if ($isSettled): ?>
                                                    <span class="text-success fw-bold plot-pill-status"><i class="fas fa-check-circle"></i> Settled</span>
                                                <?php else: ?>
                                                    <span class="plot-pill-status">₱<?php echo number_format($pBal, 2); ?> bal</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- 3. INDIVIDUAL PLOT PAYMENT PANES (HIWALAY ANG PAYMENT) -->
                <div class="tab-content" id="installmentPlotsTabContent">
                    <?php foreach ($payment_plans as $idx => $plan): ?>
                        <?php
                        $isFirst = ($idx === 0 || $plan_count === 1);
                        $plan_total = (float)$plan['total_amount'];
                        $plan_paid = (float)$plan['amount_paid'];
                        $plan_balance = $plan['_computed_balance'];
                        $paid_count = $plan['_computed_paid_count'];
                        $pending_count = $plan['_computed_pending_count'] ?? 0;
                        $total_installments = $plan['_computed_total_inst'];
                        $progress_percent = $plan['_progress_percent'];
                        $is_fully_settled = ($plan_balance <= 0.01 && $plan_paid > 0 && $paid_count >= $total_installments);
                        ?>
                        <div class="tab-pane fade <?php echo $isFirst ? 'show active' : ''; ?>" 
                             id="plan-pane-<?php echo (int)$plan['id']; ?>" 
                             role="tabpanel" 
                             aria-labelledby="plan-tab-<?php echo (int)$plan['id']; ?>">
                            
                            <div class="installment-card mb-2">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                    <div>
                                        <h5 class="fw-bold mb-0" style="color: #7b1113; font-size: 1.10rem;">
                                            Booking #<?php echo (int)$plan['id']; ?>
                                        </h5>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle mt-1" style="font-size: 0.75rem;">
                                            <i class="fas fa-calendar-week me-1"></i> <?php echo (int)($plan['payment_term'] ?: 12); ?>-Month Installment Plan
                                        </span>
                                    </div>
                                    <div class="text-sm-end">
                                        <span class="text-muted small">Plot Location:</span>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars('Section ' . $plan['section'] . ' • Plot ' . format_plot_number($plan['plot'], $plan['section'])); ?></div>
                                    </div>
                                </div>

                                <div class="row g-1 g-sm-2 text-center mb-3 installment-metrics-row">
                                    <div class="col-4">
                                        <div class="installment-metric-box p-1 p-sm-2 rounded bg-light border">
                                            <div class="metric-label text-muted small">Total Plan</div>
                                            <div class="metric-value fw-bold text-dark">₱<?php echo number_format($plan_total, 2); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="installment-metric-box p-1 p-sm-2 rounded bg-light border">
                                            <div class="metric-label text-muted small">Total Paid</div>
                                            <div class="metric-value fw-bold text-success">₱<?php echo number_format($plan_paid, 2); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="installment-metric-box p-1 p-sm-2 rounded border" style="background: #fff5f5; border-color: #fecaca !important;">
                                            <div class="metric-label text-danger small fw-semibold">Remaining Balance</div>
                                            <div class="metric-value fw-bold text-danger">₱<?php echo number_format($plan_balance, 2); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="d-flex align-items-center justify-content-between small text-muted mb-1">
                                        <span>
                                            <strong><?php echo $paid_count; ?> of <?php echo $total_installments; ?></strong> payments completed
                                            <?php if ($pending_count > 0): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1" style="font-size: 0.72rem;">
                                                    <i class="fas fa-hourglass-half me-1"></i><?php echo $pending_count; ?> under review
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="fw-bold text-dark"><?php echo $progress_percent; ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 8px; border-radius: 4px; background: #e2e8f0;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $progress_percent; ?>%;" aria-valuenow="<?php echo $progress_percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <span class="small text-muted">
                                        <i class="fas fa-info-circle me-1 text-primary"></i> 
                                        <?php if ($is_fully_settled): ?>
                                            <span class="text-success fw-semibold">All installment payments settled for this plot.</span>
                                        <?php elseif ($pending_count > 0 && $paid_count === 0): ?>
                                            <span class="text-warning-emphasis fw-semibold">Payment for Installment #1 submitted &amp; currently under review.</span>
                                        <?php elseif ($pending_count > 0): ?>
                                            <span class="text-warning-emphasis fw-semibold"><?php echo $pending_count; ?> installment payment submitted &amp; currently under review.</span>
                                        <?php else: ?>
                                            <span>Pay individual monthly dues or settle remaining balance below.</span>
                                        <?php endif; ?>
                                    </span>
                                    <button type="button" class="btn btn-sm btn-outline-primary toggle-schedule-btn" data-bs-toggle="collapse" data-bs-target="#scheduleCollapse_<?php echo (int)$plan['id']; ?>" aria-expanded="false">
                                        <span class="schedule-toggle-text">View Payment Schedule &amp; History <i class="fas fa-chevron-down ms-1"></i></span>
                                    </button>
                                </div>

                                <div class="collapse mt-3" id="scheduleCollapse_<?php echo (int)$plan['id']; ?>">
                                    <div class="p-3 bg-light rounded border">
                                        <h6 class="fw-bold text-secondary mb-3" style="font-size: 0.88rem;">
                                            <i class="fas fa-list-ol me-1 text-danger"></i> Monthly Installment Schedule (Booking #<?php echo (int)$plan['id']; ?>)
                                        </h6>
                                        <?php if (!empty($plan['schedule'])): ?>
                                            <?php
                                            $first_unpaid_inst_num = null;
                                            foreach ($plan['schedule'] as $chk_inst) {
                                                $chk_num = (int)($chk_inst['installment_number'] ?? 0);
                                                $chk_rcpt = $plan['receipts_by_inst'][$chk_num] ?? null;
                                                $c_status = strtoupper(trim($chk_inst['status'] ?? 'UNPAID'));
                                                $c_rcpt_st = $chk_rcpt ? strtoupper(trim((string)($chk_rcpt['payment_status'] ?? ''))) : '';
                                                $c_paid_amt = (float)($chk_inst['paid_amount'] ?? ($chk_inst['amount_paid'] ?? 0));
                                                $c_isPaid = ($c_status === 'PAID' || $c_paid_amt > 0 || in_array($c_rcpt_st, ['PAID', 'VERIFIED'], true));
                                                $c_hasProof = (!empty($chk_rcpt['proof_filename']));
                                                $c_isPending = (!$c_isPaid && (in_array($c_rcpt_st, ['PENDING', 'FOR VERIFICATION'], true) || ($c_hasProof && $c_rcpt_st !== 'REJECTED')));
                                                
                                                if (!$c_isPaid && !$c_isPending && $first_unpaid_inst_num === null) {
                                                    $first_unpaid_inst_num = $chk_num;
                                                }
                                            }
                                            // Fallback if all remaining are pending
                                            if ($first_unpaid_inst_num === null) {
                                                foreach ($plan['schedule'] as $chk_inst) {
                                                    $chk_num = (int)($chk_inst['installment_number'] ?? 0);
                                                    $chk_rcpt = $plan['receipts_by_inst'][$chk_num] ?? null;
                                                    $c_status = strtoupper(trim($chk_inst['status'] ?? 'UNPAID'));
                                                    $c_rcpt_st = $chk_rcpt ? strtoupper(trim((string)($chk_rcpt['payment_status'] ?? ''))) : '';
                                                    $c_isPaid = ($c_status === 'PAID' || in_array($c_rcpt_st, ['PAID', 'VERIFIED'], true));
                                                    if (!$c_isPaid) {
                                                        $first_unpaid_inst_num = $chk_num;
                                                        break;
                                                    }
                                                }
                                            }
                                            ?>
                                            <div class="table-responsive d-none d-md-block">
                                                <table class="table table-sm table-bordered align-middle mb-0 bg-white" style="font-size: 0.85rem;">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th style="width: 50px;" class="text-center">#</th>
                                                            <th>Due Date</th>
                                                            <th class="text-end">Amount Due</th>
                                                            <th class="text-center" style="width: 120px;">Status</th>
                                                            <th class="text-center" style="width: 160px;">Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($plan['schedule'] as $inst): ?>
                                                            <?php
                                                            $inst_num = (int)($inst['installment_number'] ?? 0);
                                                            $inst_receipt = $plan['receipts_by_inst'][$inst_num] ?? null;
                                                            $inst_status = strtoupper(trim($inst['status'] ?? 'UNPAID'));
                                                            $inst_rcpt_st = $inst_receipt ? strtoupper(trim((string)($inst_receipt['payment_status'] ?? ''))) : '';
                                                            $inst_paid_amt = (float)($inst['paid_amount'] ?? ($inst['amount_paid'] ?? 0));
                                                            $isPaid = ($inst_status === 'PAID' || $inst_paid_amt > 0 || in_array($inst_rcpt_st, ['PAID', 'VERIFIED'], true));
                                                            $hasProof = (!empty($inst_receipt['proof_filename']));
                                                            $isPending = (!$isPaid && (in_array($inst_rcpt_st, ['PENDING', 'FOR VERIFICATION'], true) || ($hasProof && $inst_rcpt_st !== 'REJECTED')));
                                                            $isRejected = (!$isPaid && $inst_rcpt_st === 'REJECTED');
                                                            $isCurrentDue = (!$isPaid && !$isPending && $inst_num === $first_unpaid_inst_num);
                                                            ?>
                                                            <tr>
                                                                <td class="text-center fw-bold text-muted"><?php echo $inst_num; ?></td>
                                                                <td><i class="fas fa-calendar-alt me-1 text-muted"></i> <?php echo date('M j, Y', strtotime($inst['due_date'])); ?></td>
                                                                <td class="text-end fw-semibold">₱<?php echo number_format((float)$inst['amount_due'], 2); ?></td>
                                                                <td class="text-center">
                                                                    <?php if ($isPaid): ?>
                                                                        <span class="badge bg-success px-2 py-1" style="font-size: 0.72rem;">
                                                                            <i class="fas fa-check me-1"></i> Paid
                                                                        </span>
                                                                    <?php elseif ($isPending): ?>
                                                                        <span class="badge bg-warning text-dark px-2 py-1" style="font-size: 0.72rem;">
                                                                            <i class="fas fa-hourglass-half me-1"></i> Under Review
                                                                        </span>
                                                                    <?php elseif ($isRejected): ?>
                                                                        <span class="badge bg-danger px-2 py-1" style="font-size: 0.72rem;">
                                                                            <i class="fas fa-times-circle me-1"></i> Rejected
                                                                        </span>
                                                                    <?php elseif ($isCurrentDue): ?>
                                                                        <span class="badge bg-danger px-2 py-1" style="font-size: 0.72rem;">
                                                                            <i class="fas fa-exclamation-circle me-1"></i> Current Due
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-secondary px-2 py-1" style="font-size: 0.72rem;">
                                                                            <i class="fas fa-clock me-1"></i> Upcoming
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="text-center">
                                                                    <?php if ($isPaid): ?>
                                                                        <span class="text-muted small"><i class="fas fa-check-circle text-success me-1"></i> Settled</span>
                                                                    <?php elseif ($isPending): ?>
                                                                        <div class="d-flex align-items-center justify-content-center gap-1">
                                                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle py-1 px-2" style="font-size: 0.72rem;">
                                                                                <i class="fas fa-file-invoice me-1"></i> Proof Submitted
                                                                            </span>
                                                                            <?php if ($hasProof): ?>
                                                                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.74rem; border-radius: 5px;" onclick="viewPaymentProof(<?php echo (int)$plan['id']; ?>, '<?php echo htmlspecialchars(addslashes($inst_receipt['proof_filename'])); ?>')" title="View Submitted Proof">
                                                                                    <i class="fas fa-image"></i>
                                                                                </button>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php elseif ($isRejected): ?>
                                                                        <div class="d-flex gap-1 justify-content-center">
                                                                            <button type="button" class="btn btn-sm btn-danger py-0 px-2 fw-semibold" style="font-size: 0.76rem; border-radius: 6px;" onclick="openUploadProofModal(<?php echo (int)$plan['id']; ?>, 'Installment #<?php echo $inst_num; ?>', '<?php echo number_format((float)$inst['amount_due'], 2, '.', ''); ?>', <?php echo $inst_num; ?>, <?php echo (int)($inst['id'] ?? 0); ?>)" title="Re-upload proof of payment">
                                                                                <i class="fas fa-redo me-1"></i> Re-upload Proof
                                                                            </button>
                                                                        </div>
                                                                    <?php elseif ($isCurrentDue): ?>
                                                                        <div class="d-flex gap-1 justify-content-center">
                                                                            <a href="gcash_gateway.php?booking_id=<?php echo (int)$plan['id']; ?>&amount=<?php echo number_format((float)$inst['amount_due'], 2, '.', ''); ?>&installment=<?php echo $inst_num; ?>&source=user" class="btn btn-sm btn-primary py-0 px-2 fw-semibold" style="font-size: 0.76rem; border-radius: 6px;">
                                                                                <i class="fas fa-credit-card me-1"></i> Pay Due
                                                                            </a>
                                                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fw-semibold" style="font-size: 0.76rem; border-radius: 6px;" onclick="openUploadProofModal(<?php echo (int)$plan['id']; ?>, 'Installment #<?php echo $inst_num; ?>', '<?php echo number_format((float)$inst['amount_due'], 2, '.', ''); ?>', <?php echo $inst_num; ?>, <?php echo (int)($inst['id'] ?? 0); ?>)" title="Upload proof of payment">
                                                                                <i class="fas fa-upload"></i>
                                                                            </button>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-light text-muted border px-2 py-1" style="font-size: 0.72rem;" title="Please settle earlier installment first">
                                                                            <i class="fas fa-lock me-1"></i> Locked
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="d-md-none">
                                                <?php foreach ($plan['schedule'] as $inst): ?>
                                                    <?php
                                                    $inst_num = (int)($inst['installment_number'] ?? 0);
                                                    $inst_receipt = $plan['receipts_by_inst'][$inst_num] ?? null;
                                                    $inst_status = strtoupper(trim($inst['status'] ?? 'UNPAID'));
                                                    $inst_rcpt_st = $inst_receipt ? strtoupper(trim((string)($inst_receipt['payment_status'] ?? ''))) : '';
                                                    $inst_paid_amt = (float)($inst['paid_amount'] ?? ($inst['amount_paid'] ?? 0));
                                                    $isPaid = ($inst_status === 'PAID' || $inst_paid_amt > 0 || in_array($inst_rcpt_st, ['PAID', 'VERIFIED'], true));
                                                    $hasProof = (!empty($inst_receipt['proof_filename']));
                                                    $isPending = (!$isPaid && (in_array($inst_rcpt_st, ['PENDING', 'FOR VERIFICATION'], true) || ($hasProof && $inst_rcpt_st !== 'REJECTED')));
                                                    $isRejected = (!$isPaid && $inst_rcpt_st === 'REJECTED');
                                                    $isCurrentDue = (!$isPaid && !$isPending && $inst_num === $first_unpaid_inst_num);
                                                    ?>
                                                    <div class="p-2 mb-2 bg-white rounded border d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <div class="fw-bold text-dark" style="font-size: 0.88rem;">Payment #<?php echo $inst_num; ?></div>
                                                            <div class="small text-muted"><i class="fas fa-calendar-alt me-1"></i> <?php echo date('M j, Y', strtotime($inst['due_date'])); ?></div>
                                                        </div>
                                                        <div class="text-end">
                                                            <div class="fw-bold text-dark" style="font-size: 0.9rem;">₱<?php echo number_format((float)$inst['amount_due'], 2); ?></div>
                                                            <?php if ($isPaid): ?>
                                                                <span class="badge bg-success" style="font-size: 0.68rem;">Paid</span>
                                                            <?php elseif ($isPending): ?>
                                                                <div class="d-flex align-items-center justify-content-end gap-1 mt-1">
                                                                    <span class="badge bg-warning text-dark" style="font-size: 0.68rem;"><i class="fas fa-hourglass-half me-1"></i> Under Review</span>
                                                                    <?php if ($hasProof): ?>
                                                                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" style="font-size: 0.70rem;" onclick="viewPaymentProof(<?php echo (int)$plan['id']; ?>, '<?php echo htmlspecialchars(addslashes($inst_receipt['proof_filename'])); ?>')" title="View Submitted Proof">
                                                                            <i class="fas fa-image"></i>
                                                                        </button>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php elseif ($isRejected): ?>
                                                                <div class="d-flex justify-content-end mt-1">
                                                                    <button type="button" class="btn btn-sm btn-danger py-0 px-2 fw-semibold d-inline-block" style="font-size: 0.72rem; border-radius: 5px;" onclick="openUploadProofModal(<?php echo (int)$plan['id']; ?>, 'Installment #<?php echo $inst_num; ?>', '<?php echo number_format((float)$inst['amount_due'], 2, '.', ''); ?>', <?php echo $inst_num; ?>, <?php echo (int)($inst['id'] ?? 0); ?>)">
                                                                        Re-upload Proof
                                                                    </button>
                                                                </div>
                                                            <?php elseif ($isCurrentDue): ?>
                                                                <div class="d-flex gap-1 justify-content-end mt-1">
                                                                    <a href="gcash_gateway.php?booking_id=<?php echo (int)$plan['id']; ?>&amount=<?php echo number_format((float)$inst['amount_due'], 2, '.', ''); ?>&installment=<?php echo $inst_num; ?>&source=user" class="btn btn-sm btn-primary py-0 px-2 fw-semibold d-inline-block" style="font-size: 0.72rem; border-radius: 5px;">
                                                                        Pay Due
                                                                    </a>
                                                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fw-semibold d-inline-block" style="font-size: 0.72rem; border-radius: 5px;" onclick="openUploadProofModal(<?php echo (int)$plan['id']; ?>, 'Installment #<?php echo $inst_num; ?>', '<?php echo number_format((float)$inst['amount_due'], 2, '.', ''); ?>', <?php echo $inst_num; ?>, <?php echo (int)($inst['id'] ?? 0); ?>)">
                                                                        Upload Proof
                                                                    </button>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="d-flex justify-content-end mt-1">
                                                                    <span class="badge bg-light text-muted border px-2 py-1" style="font-size: 0.68rem;" title="Please settle earlier installment first">
                                                                        <i class="fas fa-lock me-1"></i> Locked
                                                                    </span>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="small text-muted">No schedule records available.</div>
                                        <?php endif; ?>

                                        <div class="mt-4 pt-3 border-top">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h6 class="fw-bold text-secondary mb-0" style="font-size: 0.88rem;">
                                                    <i class="fas fa-receipt me-1 text-danger"></i> Payment Transactions History
                                                </h6>
                                                <?php if ($plan_balance > 0.01): ?>
                                                <a href="gcash_gateway.php?booking_id=<?php echo (int)$plan['id']; ?>&source=user" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.76rem;">
                                                    <i class="fas fa-plus-circle me-1"></i> Make a Payment
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($plan['payments'])): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm table-bordered align-middle mb-0 bg-white" style="font-size: 0.82rem;">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th style="width: 70px;">Payment ID</th>
                                                                <th>Inst. #</th>
                                                                <th>Amount Paid</th>
                                                                <th>Method</th>
                                                                <th>GCash / Txn Ref</th>
                                                                <th>Payment Date</th>
                                                                <th class="text-center">Status</th>
                                                                <th class="text-center" style="width: 120px;">Proof / Receipt</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($plan['payments'] as $pTx): ?>
                                                                <?php 
                                                                $pStatus = strtoupper(trim($pTx['payment_status'] ?? 'PAID'));
                                                                $badgeCls = ($pStatus === 'PAID') ? 'bg-success' : (($pStatus === 'REJECTED') ? 'bg-danger' : 'bg-warning text-dark');
                                                                $pRef = !empty($pTx['gcash_reference_number']) ? $pTx['gcash_reference_number'] : (!empty($pTx['transaction_reference']) ? $pTx['transaction_reference'] : '—');
                                                                ?>
                                                                <tr>
                                                                    <td class="font-monospace fw-bold text-secondary">#<?php echo (int)$pTx['id']; ?></td>
                                                                    <td class="text-center"><?php echo !empty($pTx['installment_number']) ? (int)$pTx['installment_number'] : '—'; ?></td>
                                                                    <td class="fw-bold text-success">₱<?php echo number_format((float)$pTx['amount_paid'], 2); ?></td>
                                                                    <td><?php echo htmlspecialchars($pTx['payment_method'] ?? 'GCash'); ?></td>
                                                                    <td class="font-monospace"><small><?php echo htmlspecialchars($pRef); ?></small></td>
                                                                    <td><?php echo date('M d, Y h:i A', strtotime($pTx['payment_date'])); ?></td>
                                                                    <td class="text-center">
                                                                        <span class="badge <?php echo $badgeCls; ?> px-2 py-1" style="font-size: 0.72rem;">
                                                                            <?php echo htmlspecialchars($pStatus); ?>
                                                                        </span>
                                                                        <?php if (!empty($pTx['rejection_reason'])): ?>
                                                                            <div class="text-danger small mt-1" style="font-size: 0.7rem;"><?php echo htmlspecialchars($pTx['rejection_reason']); ?></div>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="text-center">
                                                                        <div class="d-flex gap-1 justify-content-center">
                                                                            <?php if (!empty($pTx['proof_filename'])): ?>
                                                                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" title="View Screenshot" onclick="viewPaymentProof(<?php echo (int)$plan['id']; ?>, '<?php echo htmlspecialchars($pTx['proof_filename']); ?>')">
                                                                                    <i class="fas fa-image"></i>
                                                                                </button>
                                                                            <?php endif; ?>
                                                                            <a href="print_invoice.php?booking_id=<?php echo (int)$plan['id']; ?>&payment_id=<?php echo (int)$pTx['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Print Invoice / Receipt">
                                                                                <i class="fas fa-print"></i>
                                                                            </a>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php else: ?>
                                                <div class="p-2 bg-white rounded border text-muted small">
                                                    No recorded payment transactions yet for this installment plan.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-credit-card"></i>
                    <h6 class="fw-bold text-dark mb-1">No Installment Plans</h6>
                    <p class="text-muted mb-0">You don't have any active installment payment plans.</p>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
                        </div> <!-- close #modal-bookings-panel -->
                    </div> <!-- close #myBookingsModalTabContent -->
                </div> <!-- close modal-body -->
                <div class="modal-footer bg-white border-top py-2.5 px-4 justify-content-between">
                    <span class="text-muted small"><i class="fas fa-shield-alt text-success me-1"></i> Garden of Remembrance Memorial Park Client Portal</span>
                    <button type="button" class="btn btn-outline-secondary btn-sm px-4" data-bs-dismiss="modal" style="border-radius: 8px;">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Global functions for My Bookings Modal
    window.openMyBookingsModal = function(targetTab, filterStatus) {
        var modalEl = document.getElementById('myBookingsModal');
        if (!modalEl) return;

        // Switch to designated tab
        if (targetTab === 'plots') {
            var plotsTabBtn = document.getElementById('modal-plots-tab');
            if (plotsTabBtn && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                var tabTrigger = bootstrap.Tab.getOrCreateInstance(plotsTabBtn);
                tabTrigger.show();
            }
        } else {
            var bookingsTabBtn = document.getElementById('modal-bookings-tab');
            if (bookingsTabBtn && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                var tabTrigger = bootstrap.Tab.getOrCreateInstance(bookingsTabBtn);
                tabTrigger.show();
            }
        }

        // Apply quick filter if specified
        if (filterStatus) {
            window.filterModalBookings(filterStatus);
        }

        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            bsModal.show();
        }
    };

    window.filterModalBookings = function(status, btnEl) {
        var filterBtns = document.querySelectorAll('.filter-booking-btn');
        filterBtns.forEach(function(b) {
            b.classList.remove('btn-danger', 'active');
            b.classList.add('btn-outline-secondary');
        });
        if (btnEl) {
            btnEl.classList.remove('btn-outline-secondary');
            btnEl.classList.add('btn-danger', 'active');
        } else {
            filterBtns.forEach(function(b) {
                var txt = (b.textContent || '').toLowerCase();
                if (status === 'all' && txt.includes('all')) {
                    b.classList.remove('btn-outline-secondary');
                    b.classList.add('btn-danger', 'active');
                } else if (status && txt.includes(status.toLowerCase())) {
                    b.classList.remove('btn-outline-secondary');
                    b.classList.add('btn-danger', 'active');
                }
            });
        }

        var cards = document.querySelectorAll('#recentBookingsSection .booking-card');
        cards.forEach(function(card) {
            var bStatus = (card.getAttribute('data-status') || '').toLowerCase();
            var pStatus = (card.getAttribute('data-payment-status') || '').toLowerCase();
            var sStatus = (card.getAttribute('data-service-status') || '').toLowerCase();

            var matches = false;
            if (!status || status === 'all') {
                matches = true;
            } else if (status === 'pending') {
                matches = (bStatus === 'pending' || pStatus.includes('verification') || pStatus.includes('unpaid') || sStatus === 'pending');
            } else if (status === 'approved') {
                matches = (bStatus === 'approved' || bStatus === 'completed' || sStatus === 'approved' || sStatus === 'completed');
            } else if (status === 'paid') {
                matches = (pStatus === 'paid' || bStatus === 'completed');
            } else {
                matches = (bStatus === status || pStatus === status || sStatus === status);
            }

            if (matches) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    };

    window.__returnToMyBookings = false;
    window.__returnToMyBookingsTab = 'bookings';

    window.switchToNewBookingModal = function() {
        window.__returnToMyBookings = true;
        window.__returnToMyBookingsTab = 'bookings';

        var backBtnText = document.getElementById('bookingModalBackBtnText');
        if (backBtnText) {
            backBtnText.textContent = 'Back to Reservations';
        }

        var myBookingsEl = document.getElementById('myBookingsModal');
        if (myBookingsEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            var bsMyBookings = bootstrap.Modal.getInstance(myBookingsEl);
            if (bsMyBookings) {
                bsMyBookings.hide();
            }
        }
        setTimeout(function() {
            var bookingModalEl = document.getElementById('bookingModal');
            if (bookingModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var bsBooking = bootstrap.Modal.getOrCreateInstance(bookingModalEl);
                bsBooking.show();
            }
        }, 300);
    };

    // Auto open modal based on URL query
    document.addEventListener('DOMContentLoaded', function() {
        // Ensure plot pill buttons switch smoothly
        document.addEventListener('click', function(ev) {
            var pillBtn = ev.target.closest('.plot-pill-btn');
            if (!pillBtn) return;
            var targetSelector = pillBtn.getAttribute('data-bs-target');
            if (!targetSelector) return;
            var targetPane = document.querySelector(targetSelector);
            if (!targetPane) return;
            
            var allPills = document.querySelectorAll('.plot-pill-btn');
            allPills.forEach(function(p) {
                p.classList.remove('active');
                p.setAttribute('aria-selected', 'false');
            });
            pillBtn.classList.add('active');
            pillBtn.setAttribute('aria-selected', 'true');

            var tabContent = targetPane.closest('.tab-content');
            if (tabContent) {
                var allPanes = tabContent.querySelectorAll('.tab-pane');
                allPanes.forEach(function(pane) {
                    pane.classList.remove('show', 'active');
                });
            }
            targetPane.classList.add('show', 'active');
        });
        try {
            var urlParams = new URLSearchParams(window.location.search);
            var openParam = urlParams.get('open');
            var tabParam = urlParams.get('tab');
            if (openParam === 'my_bookings' || openParam === 'bookings') {
                openMyBookingsModal('bookings');
            } else if (openParam === 'my_plots' || openParam === 'plots' || tabParam === 'plots') {
                openMyBookingsModal('plots');
            }
        } catch (e) {
            console.error('Error parsing URL params:', e);
        }

        var bookingModalEl = document.getElementById('bookingModal');
        if (bookingModalEl) {
            bookingModalEl.addEventListener('hidden.bs.modal', function () {
                if (window.__returnToMyBookings) {
                    var targetTab = window.__returnToMyBookingsTab || 'bookings';
                    window.__returnToMyBookings = false;
                    window.__returnToMyBookingsTab = null;
                    setTimeout(function() {
                        if (typeof openMyBookingsModal === 'function') {
                            openMyBookingsModal(targetTab);
                        }
                    }, 200);
                }
            });
        }
    });

    function showUserWarning(title, text) {
        window.__isBookingSubmitting = false;
        var submitBtn = document.querySelector('#bookingForm button[type="submit"]') || document.getElementById('userSubmitBookingBtn');
        if (submitBtn) { submitBtn.disabled = false; }
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'warning', title: title, text: text, confirmButtonColor: '#7b1113' });
        } else {
            alert(title + ': ' + text);
        }
    }
    window.showUserWarning = showUserWarning;

    window.openCemeteryTermsModal = function(e, type) {
        if (e && typeof e.preventDefault === 'function') {
            e.preventDefault();
            e.stopPropagation();
        }
        if (typeof e === 'string' && !type) {
            type = e;
        }

        var modalEl = document.getElementById('cemeteryTermsModal');
        if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            modalEl.style.zIndex = '1085';
            var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            bsModal.show();
            setTimeout(function() {
                var backdrops = document.querySelectorAll('.modal-backdrop');
                if (backdrops.length > 1) {
                    backdrops[backdrops.length - 1].style.zIndex = '1080';
                }
            }, 50);

            modalEl.addEventListener('hidden.bs.modal', function () {
                var bm = document.getElementById('bookingModal');
                if (bm && (bm.classList.contains('show') || bm.style.display === 'block')) {
                    document.body.classList.add('modal-open');
                    document.body.style.overflow = 'hidden';
                }
            }, { once: true });
            return;
        }

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: '<span style="color:#7b1113; font-weight:700;"><i class="fas fa-file-contract text-warning me-2"></i>Terms & Agreements</span>',
                html: '<div class="text-start small p-2" style="max-height:60vh; overflow-y:auto; line-height:1.6; color:#334155;">' +
                      '<div class="p-3 bg-light rounded border mb-2"><h6 class="fw-bold text-dark mb-1"><i class="fas fa-landmark text-primary me-2"></i>1. Plot Rights & Cemetery Authority</h6><p class="mb-0">A burial plot purchase or reservation conveys exclusive perpetual interment rights under the rules and regulations of Garden of Remembrance Memorial Park. Fee simple title to the cemetery grounds and facilities remains perpetually with the memorial park administration.</p></div>' +
                      '<div class="p-3 bg-light rounded border mb-2"><h6 class="fw-bold text-dark mb-1"><i class="fas fa-money-check-alt text-success me-2"></i>2. Payment, Installments & Verification</h6><p class="mb-0">All bookings are subject to administrator verification. Cash and online payments must provide legitimate transaction references. For installment contracts, the mandatory downpayment and agreed monthly amortizations must be satisfied on schedule to keep the reservation active.</p></div>' +
                      '<div class="p-3 bg-light rounded border mb-2" style="border-left: 4px solid #dc3545 !important;"><h6 class="fw-bold text-danger mb-1"><i class="fas fa-calendar-times text-danger me-2"></i>3. Three (3) Months Non-Payment Cancellation Policy</h6><p class="mb-0"><strong>Automatic Cancellation:</strong> If a client fails to pay or settles no payment for three (3) consecutive months (90 days of non-payment or overdue installment amortizations), the reservation will be automatically cancelled. The reserved plot will be immediately forfeited, released, and made available for other buyers. In accordance with memorial park policy, all previous payments and reservation fees are strictly non-refundable.</p></div>' +
                      '<div class="p-3 bg-light rounded border mb-2"><h6 class="fw-bold text-dark mb-1"><i class="fas fa-cross text-danger me-2"></i>4. Interment & Layering (Patong) Regulations</h6><p class="mb-0">Secondary interment (layering / patong) requires registered owner or legal family consent and compliance with sanitary interval standards (8-10 years interval or approved vault-sealed tiering). Capacity cannot exceed 4 occupants per standard lawn lot.</p></div>' +
                      '<div class="p-3 bg-light rounded border mb-2"><h6 class="fw-bold text-dark mb-1"><i class="fas fa-seedling text-success me-2"></i>5. Park Maintenance, Markers & Decorum</h6><p class="mb-0">General landscaping, mowing, and structural maintenance are conducted exclusively by authorized cemetery personnel. Gravestones, markers, and decorations must conform to park design guidelines. Visitors must observe park visiting hours, cleanliness, and peace at all times.</p></div>' +
                      '<div class="p-3 bg-light rounded border"><h6 class="fw-bold text-dark mb-1"><i class="fas fa-user-shield text-info me-2"></i>6. Data Privacy Act Compliance</h6><p class="mb-0">All personal information collected is handled in strict compliance with the Data Privacy Act of 2012 (R.A. 10173) and will be utilized strictly for cemetery booking, reservation records, and official communications.</p></div>' +
                      '</div>',
                confirmButtonText: 'I Understand and Agree',
                confirmButtonColor: '#7b1113',
                width: 650
            });
        }
    };

    function handleBookingSubmit(e) {
        if (e && typeof e.preventDefault === 'function') e.preventDefault();

        if (window.__isBookingSubmitting) {
            console.warn('Booking submission already in progress.');
            return false;
        }
        window.__isBookingSubmitting = true;

        var section = document.getElementById('section') ? document.getElementById('section').value : '';
        var plot = document.getElementById('plot') ? document.getElementById('plot').value : '';
        var serviceType = document.getElementById('serviceType') ? document.getElementById('serviceType').value : '';
        var preferredDate = document.getElementById('preferredDate') ? document.getElementById('preferredDate').value : '';
        var contactNumber = document.getElementById('contactNumber') ? document.getElementById('contactNumber').value : '';
        var bookerLocation = document.getElementById('bookerLocation') ? document.getElementById('bookerLocation').value : '';
        var fullName = document.getElementById('fullName') ? document.getElementById('fullName').value.trim() : '';
        var rawClientSuffix = (document.getElementById('clientSuffix') ? document.getElementById('clientSuffix').value : '').trim();
        var clientSuffixOtherVal = (document.getElementById('clientSuffixOther') ? document.getElementById('clientSuffixOther').value : '').trim();
        if (rawClientSuffix === 'OTHER' && !clientSuffixOtherVal) {
            showUserWarning('Suffix Required', 'Please specify your custom suffix.');
            return false;
        }
        var clientSuffix = rawClientSuffix === 'OTHER' ? clientSuffixOtherVal : rawClientSuffix;
        if (clientSuffix && fullName && !fullName.toUpperCase().endsWith(clientSuffix.toUpperCase())) {
            fullName = fullName.trim() + ' ' + clientSuffix;
        }
        var emailAddress = document.getElementById('emailAddress') ? document.getElementById('emailAddress').value : '';
        var specialRequests = document.getElementById('specialRequests') ? document.getElementById('specialRequests').value : '';
        var graveLocation = document.getElementById('grave_location') ? document.getElementById('grave_location').value : '';
        var bookingPurpose = document.getElementById('bookingPurpose') ? document.getElementById('bookingPurpose').value : 'reserve_grave';
        var deceasedSelect = document.getElementById('bookingDeceased');
        var deceasedId = deceasedSelect ? deceasedSelect.value : '';
        var bookingType = document.getElementById('bookingType') ? document.getElementById('bookingType').value : 'existing_plot_service';
        if (bookingPurpose === 'reserve_grave') {
            bookingType = 'another_plot';
            var bTypeEl = document.getElementById('bookingType');
            if (bTypeEl) bTypeEl.value = 'another_plot';
        }

        if (!section || !plot) { 
            window.__isBookingSubmitting = false;
            showUserWarning('Plot Required', 'Please choose a grave plot before submitting the booking.'); 
            return false; 
        }
        if (!fullName) { 
            window.__isBookingSubmitting = false;
            showUserWarning('Name Required', 'Please enter your full name.'); 
            return false; 
        }
        if (!emailAddress) { 
            window.__isBookingSubmitting = false;
            showUserWarning('Email Required', 'Please enter your email address.'); 
            return false; 
        }

        var termsCb = document.getElementById('userBookingTermsAgreement');
        if (termsCb && !termsCb.checked) {
            window.__isBookingSubmitting = false;
            showUserWarning('Terms & Agreement Required', 'Please read and agree to the Cemetery Terms and Agreements before submitting your booking.');
            if (termsCb.focus) termsCb.focus();
            return false;
        }

        var rawDecSuffix = (document.getElementById('decSuffixSelect') ? document.getElementById('decSuffixSelect').value : '').trim();
        var decSuffixOtherVal = (document.getElementById('decSuffixOther') ? document.getElementById('decSuffixOther').value : '').trim();
        if (bookingPurpose === 'add_deceased' && rawDecSuffix === 'OTHER' && !decSuffixOtherVal) {
            showUserWarning('Deceased Suffix Required', 'Please specify the custom suffix for the deceased.');
            return false;
        }
        var decSuffix = rawDecSuffix === 'OTHER' ? decSuffixOtherVal : rawDecSuffix;
        var newDecFullName = '';

        // Date of birth and date of death validation
        var todayStr = new Date().toISOString().split('T')[0];
        var decBdayVal = document.getElementById('decBday') ? document.getElementById('decBday').value : '';
        var decDateDiedVal = document.getElementById('decDateDied') ? document.getElementById('decDateDied').value : '';
        if (decBdayVal && decBdayVal > todayStr) {
            showUserWarning('Invalid Birth Date', 'Deceased date of birth cannot be in the future.');
            return false;
        }
        if (decDateDiedVal && decDateDiedVal > todayStr) {
            showUserWarning('Invalid Date of Death', 'Deceased date of death cannot be in the future.');
            return false;
        }
        if (decBdayVal && decDateDiedVal && decDateDiedVal < decBdayVal) {
            showUserWarning('Invalid Dates', 'Deceased date of death cannot be earlier than date of birth.');
            return false;
        }

        if (bookingPurpose === 'add_deceased') {
            newDecFullName = document.getElementById('decFullName') ? document.getElementById('decFullName').value.trim() : '';
            if (decSuffix && newDecFullName && !newDecFullName.toUpperCase().endsWith(decSuffix.toUpperCase())) {
                newDecFullName = newDecFullName + ' ' + decSuffix;
            }
            var newDecRelationship = document.getElementById('decRelationship') ? document.getElementById('decRelationship').value : '';
            var newDecIntermentDate = document.getElementById('decIntermentDate') ? document.getElementById('decIntermentDate').value : '';

            if (!newDecFullName) {
                showUserWarning('Deceased Name Required', 'Please enter the full name of the new deceased to be added.');
                return false;
            }
            if (!newDecIntermentDate) {
                showUserWarning('Interment Date Required', 'Please select the date of interment for the new deceased.');
                return false;
            }
            if (window.isCurrentPlotEligible === false) {
                showUserWarning('Not Eligible for Additional Interment', 'This plot is not eligible for another interment yet. The previous interment requires a minimum 8-year interval, or the plot has reached its maximum occupant limit.');
                return false;
            }
        }

        var checkedServices = [];
        document.querySelectorAll('.cemetery-service-checkbox:checked').forEach(function(cb) {
            checkedServices.push(cb.value);
        });

        var selectedPlotsArray = [];
        var checkedPlotCheckboxes = document.querySelectorAll('.user-plot-service-checkbox:checked');
        var allPlotCheckboxes = document.querySelectorAll('.user-plot-service-checkbox');
        if (bookingPurpose === 'cemetery_service') {
            if (allPlotCheckboxes.length > 0) {
                if (checkedPlotCheckboxes.length === 0) {
                    showUserWarning('Plot Required', 'Please select at least one linked plot to service.');
                    return false;
                }
                checkedPlotCheckboxes.forEach(function(cb) {
                    selectedPlotsArray.push({
                        section: (cb.dataset.section || '').toUpperCase().trim(),
                        plot_x: parseInt(cb.dataset.plotX || '0', 10),
                        plot_y: parseInt(cb.dataset.plotY || '0', 10),
                        plot_number: cb.dataset.plotNo || ''
                    });
                });
            }
            if (checkedServices.length === 0 && !serviceType) { 
                showUserWarning('Service Required', 'Please select at least one cemetery service.'); 
                return false; 
            }
            if (checkedServices.length > 0) {
                serviceType = checkedServices.join(', ');
            }
            if (!preferredDate) { 
                showUserWarning('Date Required', 'Please select a preferred date.'); 
                return false; 
            }
            if (!contactNumber || !/^09\d{9}$/.test(contactNumber)) { 
                showUserWarning('Contact Number Required', 'Please enter a valid 11-digit contact number starting with 09 (e.g. 09XXXXXXXXX).'); 
                return false; 
            }
            if (!bookerLocation) { 
                showUserWarning('Location Required', 'Please enter your location/address.'); 
                return false; 
            }
        } else if (bookingPurpose === 'reserve_grave') {
            if (window.__userSelectedPlotsList && window.__userSelectedPlotsList.length > 0) {
                selectedPlotsArray = window.__userSelectedPlotsList.map(function(p) {
                    return {
                        section: (p.section || '').toUpperCase().trim(),
                        plot_x: parseInt(p.plot_x, 10),
                        plot_y: parseInt(p.plot_y, 10),
                        plot_number: p.plot_number || ((p.section || '') + '-' + p.plot_x + '-' + p.plot_y),
                        lawn_type: p.lawn_type || 'Regular',
                        price: parseFloat(p.price || (p.lawn_type === 'Premium' ? 89000 : 85000))
                    };
                });
            } else {
                var pXVal = document.getElementById('plot_x') ? document.getElementById('plot_x').value : '';
                var pYVal = document.getElementById('plot_y') ? document.getElementById('plot_y').value : '';
                var pSecVal = document.getElementById('section') ? document.getElementById('section').value : '';
                if (pXVal && pYVal && pSecVal) {
                    selectedPlotsArray = [{
                        section: pSecVal.toUpperCase().trim(),
                        plot_x: parseInt(pXVal, 10),
                        plot_y: parseInt(pYVal, 10),
                        plot_number: (document.getElementById('plot') ? document.getElementById('plot').value : '') || (pSecVal + '-' + pXVal + '-' + pYVal),
                        lawn_type: (document.getElementById('lawn_type') ? document.getElementById('lawn_type').value : '') || 'Regular',
                        price: parseFloat((document.getElementById('plot_price') ? document.getElementById('plot_price').value : '') || 85000)
                    }];
                }
            }

            if (selectedPlotsArray.length === 0) {
                showUserWarning('Plot Required', 'Please select at least one plot from the map.');
                return false;
            }
            var maxAllowed = 2;
            if (selectedPlotsArray.length > maxAllowed) {
                showUserWarning('Maximum Plots Exceeded', 'You can reserve up to 2 plots only for a new grave reservation.');
                return false;
            }
        }

        var paymentPlan = document.getElementById('paymentPlan') ? document.getElementById('paymentPlan').value : 'full';
        var paymentTermSel = document.getElementById('paymentTerm') ? document.getElementById('paymentTerm').value : '';
        if (paymentPlan === 'installment' && !paymentTermSel) {
            showUserWarning('Installment Term Required', 'Please select an installment term.');
            return false;
        }

        var rawPaymentMethod = document.getElementById('paymentMethod') ? document.getElementById('paymentMethod').value : 'gcash';
        var paymentMethod = rawPaymentMethod && rawPaymentMethod.toLowerCase() === 'gcash' ? 'GCash' : 'GCash';

        var proofFileInput = document.getElementById('bookingProofFile');
        var proofRefInput = document.getElementById('bookingProofRef');
        var proofRefVal = proofRefInput ? proofRefInput.value.trim() : '';

        // Pay Now: skip ref/proof requirement — booking is created first, payment happens in the gateway.
        var payNowDirect = (window.__payNowDirect === true);
        window.__payNowDirect = false;

        if (!payNowDirect) {
            if (!proofRefVal) {
                showUserWarning('GCash Reference Required', 'Please enter your GCash payment reference number before submitting.');
                if (proofRefInput) proofRefInput.focus();
                return false;
            }

            if (!proofFileInput || !proofFileInput.files || !proofFileInput.files[0]) {
                showUserWarning('Payment Proof Required', 'Please attach your GCash payment screenshot / receipt proof before submitting.');
                if (proofFileInput) proofFileInput.focus();
                return false;
            }
        }

        var paymentTerm = paymentTermSel;
        var plotPrice = document.getElementById('plot_price') ? document.getElementById('plot_price').value : '0';
        var plotType = document.getElementById('plot_type') ? document.getElementById('plot_type').value : 'Standard';
        var plotX = document.getElementById('plot_x') ? document.getElementById('plot_x').value : '';
        var plotY = document.getElementById('plot_y') ? document.getElementById('plot_y').value : '';
        var lawnType = document.getElementById('lawn_type') ? document.getElementById('lawn_type').value : '';
        var bookingStatus = document.getElementById('booking_status') ? document.getElementById('booking_status').value : 'Available';
        var purpose = document.getElementById('bookingPurpose') ? document.getElementById('bookingPurpose').value : 'reserve_grave';
        var intermentFee = document.getElementById('interment_fee') ? document.getElementById('interment_fee').value : '0';
        var servicePrice = document.getElementById('service_price') ? document.getElementById('service_price').value : '0';
        var occupantCount = document.getElementById('occupant_count') ? document.getElementById('occupant_count').value : '0';
        var maxOccupants = document.getElementById('max_occupants') ? document.getElementById('max_occupants').value : '4';

        if (purpose === 'reserve_grave') {
            deceasedId = '';
            if (selectedPlotsArray.length > 0) {
                var primPlot = selectedPlotsArray[0];
                section = (primPlot.section || section || '').toUpperCase().trim();
                plotX = String(primPlot.plot_x !== undefined ? primPlot.plot_x : (primPlot.plotX !== undefined ? primPlot.plotX : plotX));
                plotY = String(primPlot.plot_y !== undefined ? primPlot.plot_y : (primPlot.plotY !== undefined ? primPlot.plotY : plotY));
                plot = primPlot.plot_number || (plotX + '-' + plotY);
                graveLocation = (section ? section + ' ' : '') + plot;
            }
        }

        var formData = new FormData();
        formData.append('action', 'submit_booking');
        formData.append('source', 'user');
        formData.append('service_type', serviceType);
        if (checkedServices.length > 0) {
            checkedServices.forEach(function(s) {
                formData.append('service_types[]', s);
            });
        }
        if (selectedPlotsArray.length > 0) {
            formData.append('selected_plots_json', JSON.stringify(selectedPlotsArray));
            formData.append('num_lots', selectedPlotsArray.length);
        }
        formData.append('section', section);
        formData.append('preferred_date', preferredDate);
        formData.append('contact_number', contactNumber);
        formData.append('booker_location', bookerLocation);
        formData.append('special_requests', specialRequests);
        formData.append('plot', plot);
        formData.append('plot_x', plotX);
        formData.append('plot_y', plotY);
        formData.append('plot_type', plotType);
        formData.append('plot_price', plotPrice);
        formData.append('grave_location', graveLocation);
        formData.append('lawn_type', lawnType);
        formData.append('booking_status', bookingStatus);
        formData.append('full_name', fullName);
        formData.append('client_suffix', clientSuffix);
        if (rawClientSuffix === 'OTHER') {
            formData.append('client_suffix_other', clientSuffixOtherVal);
        }
        formData.append('dec_suffix', decSuffix);
        if (rawDecSuffix === 'OTHER') {
            formData.append('dec_suffix_other', decSuffixOtherVal);
        }
        formData.append('email', emailAddress);
        formData.append('user_id', '<?php echo $user_id; ?>');
        formData.append('payment_method', paymentMethod);
        formData.append('payment_plan', paymentPlan);
        formData.append('payment_term', paymentTerm);
        formData.append('purpose', purpose);
        formData.append('deceased_id', deceasedId);
        formData.append('booking_type', bookingType);
        formData.append('interment_fee', intermentFee);
        formData.append('service_price', servicePrice);
        formData.append('occupant_count', occupantCount);
        formData.append('max_occupants', maxOccupants);

        var bDraftInput = document.getElementById('bookingDraftId');
        if (bDraftInput && bDraftInput.value) {
            formData.append('booking_id', bDraftInput.value);
        }

        var proofFileInput = document.getElementById('bookingProofFile');
        if (proofFileInput && proofFileInput.files && proofFileInput.files[0]) {
            formData.append('payment_proof', proofFileInput.files[0]);
        }
        var proofRefInput = document.getElementById('bookingProofRef');
        if (proofRefInput && proofRefInput.value.trim()) {
            formData.append('payment_reference', proofRefInput.value.trim());
        }

        if (purpose === 'add_deceased') {
            formData.append('dec_full_name', newDecFullName || (document.getElementById('decFullName') ? document.getElementById('decFullName').value.trim() : ''));
            formData.append('dec_suffix', decSuffix);
            formData.append('dec_relationship', document.getElementById('decRelationship') ? document.getElementById('decRelationship').value : '');
            formData.append('dec_interment_date', document.getElementById('decIntermentDate') ? document.getElementById('decIntermentDate').value : '');
            formData.append('dec_bday', document.getElementById('decBday') ? document.getElementById('decBday').value : '');
            formData.append('dec_date_died', document.getElementById('decDateDied') ? document.getElementById('decDateDied').value : '');
            formData.append('dec_notes', document.getElementById('decNotes') ? document.getElementById('decNotes').value : '');
        }

        ['decFirst','decMiddle','decLast','decSuffix','decBday','decDateDied','decBornIn','decContact','decFuneral'].forEach(function(id){
            var el = document.getElementById(id);
            if (el && el.value) formData.append(el.name || id, el.value);
        });

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function cleanupAllBackdrops() {
            var modalEl = document.getElementById('bookingModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                var modalInst = bootstrap.Modal.getInstance(modalEl);
                if (modalInst) modalInst.hide();
            }
            document.querySelectorAll('.modal-backdrop').forEach(function(b) { b.remove(); });
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }

        var submitBtn = document.querySelector('#bookingForm button[type="submit"]') || document.getElementById('userSubmitBookingBtn');
        var originalText = submitBtn ? submitBtn.innerHTML : 'Submit Reservation';
        if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...'; }

        // Always save complete booking draft state to sessionStorage & localStorage
        var draftObj = {};
        try {
            var formElements = document.querySelectorAll('#bookingForm input, #bookingForm select, #bookingForm textarea');
            formElements.forEach(function(el) {
                if (el.name && el.type !== 'file' && el.type !== 'password') {
                    if (el.type === 'checkbox') {
                        draftObj[el.name] = el.checked;
                    } else {
                        draftObj[el.name] = el.value;
                    }
                }
            });
            var spCurrent = (window.__userSelectedPlotsList && window.__userSelectedPlotsList.length > 0) ? window.__userSelectedPlotsList : (selectedPlotsArray || []);
            draftObj._selected_plots_json = JSON.stringify(spCurrent);
            draftObj._purpose = purpose;
            draftObj._service_types = [];
            document.querySelectorAll('.cemetery-service-checkbox:checked').forEach(function(cb) {
                draftObj._service_types.push(cb.value);
            });
            sessionStorage.setItem('gor_user_booking_draft', JSON.stringify(draftObj));
            try { localStorage.setItem('gor_user_booking_draft', JSON.stringify(draftObj)); } catch(eLoc) {}
        } catch (eDraft) {
            console.error('Error saving booking draft:', eDraft);
        }

        // Cleanly close the booking form modal immediately so it doesn't obstruct the processing modal
        cleanupAllBackdrops();

        var isGcashSelected = (String(paymentMethod).toLowerCase() === 'gcash');

        // Always show Processing Modal immediately upon submission
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: payNowDirect ? 'Redirecting to GCash...' : 'Processing Reservation...',
                html: '<div class="py-3 text-center">' +
                        '<div class="mb-3">' +
                          '<div class="spinner-border ' + (payNowDirect ? 'text-primary' : 'text-danger') + '" style="width: 3.5rem; height: 3.5rem; border-width: 4px;' + (payNowDirect ? ' color: #007dfe !important;' : '') + '" role="status"></div>' +
                        '</div>' +
                        '<h5 class="fw-bold text-dark mb-1">Please wait a moment</h5>' +
                        '<p class="text-muted mb-3" style="font-size: 0.92rem;">' +
                          (payNowDirect ? 'Securing your plot reservation and preparing GCash checkout...' : (isGcashSelected ? 'Securing your plot reservation and preparing GCash checkout...' : 'Securing your plot reservation and recording your booking...')) +
                        '</p>' +
                        (payNowDirect ? '<div class="progress" style="height: 6px; border-radius: 4px; overflow: hidden; background-color: #e9ecef;"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%; background-color: #007dfe;"></div></div>' : '') +
                      '</div>',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                showCancelButton: false
            });
        }

        fetch('booking_process.php', {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            return response.text().then(function(text) {
                var cleanText = (text || '').trim();
                var parsedData = null;
                try {
                    parsedData = JSON.parse(cleanText);
                } catch (e) {
                    var b1 = cleanText.indexOf('{');
                    var b2 = cleanText.lastIndexOf('}');
                    if (b1 !== -1 && b2 > b1) {
                        try {
                            parsedData = JSON.parse(cleanText.substring(b1, b2 + 1));
                        } catch (e2) {}
                    }
                }
                if (!response.ok) {
                    if (parsedData && parsedData.message) {
                        throw new Error(parsedData.message);
                    }
                    throw new Error('Server returned an unexpected error (' + response.status + '). Please try again or contact support.');
                }
                if (parsedData) {
                    return parsedData;
                }
                throw new Error('Invalid response from server: ' + text);
            });
        })
        .then(function(data) {
            window.__isBookingSubmitting = false;
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; }
            if (!data || !data.success) {
                throw new Error(data && data.message ? data.message : 'Unable to create booking.');
            }

            var bookingId = Number(data.booking_id || 0) || 0;
            var amount = Number(data.initial_payment || data.amount || data.price || data.total_amount || plotPrice || 0) || 0;
            var isGcash = String(data.payment_method || paymentMethod).toLowerCase() === 'gcash';
            var displayAmountFormatted = '₱' + Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            cleanupAllBackdrops();

            if (isGcash && !data.has_payment_proof) {
                // Build return URL so gateway sends user back to the booking fillup form to upload proof
                var userReturnBase = window.location.protocol + '//' + window.location.host + window.location.pathname;
                var userGatewayUrl = 'gcash_gateway.php?booking_id=' + encodeURIComponent(bookingId) + '&amount=' + encodeURIComponent(Number(amount).toFixed(2)) + '&source=user&return_url=' + encodeURIComponent(userReturnBase);
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Redirecting to GCash...',
                        html: '<div class="py-3 text-center">' +
                                '<div class="mb-3">' +
                                  '<div class="spinner-border text-primary" style="width: 3.5rem; height: 3.5rem; border-width: 4px; color: #007dfe !important;" role="status"></div>' +
                                '</div>' +
                                '<h5 class="fw-bold text-dark mb-1">Booking #' + bookingId + ' Created!</h5>' +
                                '<p class="text-muted mb-3" style="font-size: 0.92rem;">Redirecting you to the secure GCash payment gateway (' + displayAmountFormatted + ')...</p>' +
                                '<div class="progress" style="height: 6px; border-radius: 4px; overflow: hidden; background-color: #e9ecef;">' +
                                  '<div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%; background-color: #007dfe;"></div>' +
                                '</div>' +
                              '</div>',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        timer: 1600
                    });
                }
                setTimeout(function() {
                    window.location.href = userGatewayUrl;
                }, 1300);
                return;
            }

            var paymentPlanLabel = (paymentPlan === 'installment') ? ('Installment (' + (paymentTerm || 12) + ' months)') : 'Full Payment';
            var methodDisplay = isGcash ? 'GCash' : (paymentMethod === 'bank_transfer' ? 'Bank Transfer' : 'CASH / OFFICE');
            var paymentMsg = data.has_payment_proof
                ? 'Your payment proof has been attached and submitted for verification. Our team will verify and update your records shortly.'
                : ((paymentPlan === 'installment') 
                    ? ('Initial 1st month installment of <strong class="text-success">' + displayAmountFormatted + '</strong> due on site.')
                    : ('Payment of <strong class="text-success">' + displayAmountFormatted + '</strong> due on site.'));

            var proofBadgeHtml = data.has_payment_proof
                ? '<div class="alert alert-success d-flex align-items-center py-2 px-3 mb-3 border-0 bg-success bg-opacity-10 text-success">' +
                    '<i class="fas fa-check-circle me-2 fs-5"></i>' +
                    '<div><strong>Payment proof attached!</strong> Reference: <code>' + escapeHtml(data.proof_reference || 'Attached') + '</code>.</div>' +
                  '</div>'
                : '';

            if (typeof Swal !== 'undefined') {
                cleanupAllBackdrops();
                Swal.fire({
                    icon: 'success',
                    title: 'Booking Submitted Successfully!',
                    html: '<div class="text-start mt-2" style="font-size: 0.95rem; line-height: 1.6;">' +
                            '<div class="alert alert-success d-flex align-items-center py-2 px-3 mb-3 border-0 bg-success bg-opacity-10 text-success">' +
                              '<i class="fas fa-calendar-check me-2 fs-5"></i>' +
                              '<div>Your booking <strong>#' + bookingId + '</strong> has been recorded!</div>' +
                            '</div>' +
                            proofBadgeHtml +
                            '<div class="card border-0 bg-light p-3 mb-3">' +
                              '<div class="d-flex justify-content-between mb-1"><span class="text-muted">Client:</span><span class="fw-bold text-dark">' + escapeHtml(fullName) + '</span></div>' +
                              '<div class="d-flex justify-content-between mb-1"><span class="text-muted">Plot / Service:</span><span class="fw-bold text-dark">Section ' + escapeHtml(section) + ' - ' + escapeHtml(plot || 'Auto') + '</span></div>' +
                              '<div class="d-flex justify-content-between mb-1"><span class="text-muted">Payment Option:</span><span class="fw-semibold text-dark">' + paymentPlanLabel + '</span></div>' +
                              '<div class="d-flex justify-content-between mb-1"><span class="text-muted">Payment Method:</span><span class="badge bg-secondary">' + escapeHtml(methodDisplay) + '</span></div>' +
                              '<hr class="my-2">' +
                              '<div class="d-flex justify-content-between align-items-center"><span class="fw-bold text-dark">' + (data.has_payment_proof ? 'Amount Paid:' : 'Amount Due on Site:') + '</span><span class="fs-5 fw-bold text-success">' + displayAmountFormatted + '</span></div>' +
                            '</div>' +
                            '<div class="p-2 border rounded bg-white small text-muted">' +
                              '<i class="fas fa-info-circle text-primary me-1"></i> <strong>Notice:</strong> ' + paymentMsg +
                            '</div>' +
                          '</div>',
                    confirmButtonText: '<i class="fas fa-check me-2"></i>View Dashboard',
                    confirmButtonColor: '#7b1113',
                    allowOutsideClick: false,
                    allowEscapeKey: true
                }).then(function() {
                    try { sessionStorage.removeItem('gor_user_booking_draft'); } catch(e) {}
                    window.location.href = 'user_dashboard.php';
                });
            } else {
                try { sessionStorage.removeItem('gor_user_booking_draft'); } catch(e) {}
                alert('Booking #' + bookingId + ' submitted successfully! Amount: ' + displayAmountFormatted);
                window.location.href = 'user_dashboard.php';
            }
        })
        .catch(function(error) {
            window.__isBookingSubmitting = false;
            console.error('Booking submission error:', error);
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; }
            cleanupAllBackdrops();
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Booking Submission Failed',
                    text: error.message || 'An error occurred while processing your booking. Please try again.',
                    confirmButtonColor: '#7b1113',
                    confirmButtonText: 'Try Again'
                }).then(function() {
                    var modalEl = document.getElementById('bookingModal');
                    if (modalEl && typeof bootstrap !== 'undefined') {
                        var modalInst = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                        if (modalInst) modalInst.show();
                    }
                });
            } else {
                alert('Booking Error: ' + error.message);
            }
        });
        return false;
    }
    window.handleBookingSubmit = handleBookingSubmit;

    window.userPayNowViaGcash = function(e) {
        if (e && typeof e.preventDefault === 'function') e.preventDefault();
        // Skip ref/proof requirement: booking is created first, payment happens in the gateway.
        window.__payNowDirect = true;
        if (typeof handleBookingSubmit === 'function') return handleBookingSubmit(e);
        if (typeof window.handleBookingSubmit === 'function') return window.handleBookingSubmit(e);
        return false;
    };

    window.userCancelBooking = function(bookingId) {
        bookingId = parseInt(bookingId, 10) || 0;
        if (bookingId <= 0) return false;
        var doCancel = function() {
            var fd = new FormData();
            fd.append('action', 'cancel_booking');
            fd.append('booking_id', bookingId);
            fetch('booking_process.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.success) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'success', title: 'Booking Cancelled', text: data.message || 'Booking cancelled. Payments made are non-refundable.', confirmButtonColor: '#7b1113' }).then(function() { window.location.reload(); });
                    } else {
                        alert(data.message || 'Booking cancelled.');
                        window.location.reload();
                    }
                } else {
                    var msg = (data && data.message) ? data.message : 'Unable to cancel booking.';
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Cancellation Failed', text: msg, confirmButtonColor: '#7b1113' });
                    } else { alert(msg); }
                }
            })
            .catch(function() {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Network Error', text: 'Please try again.', confirmButtonColor: '#7b1113' });
                } else { alert('Network error. Please try again.'); }
            });
        };
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Cancel Booking #' + bookingId + '?',
                html: 'The reservation will be released.<br><strong>Payments made are NON-REFUNDABLE</strong> and will remain in your payment history.',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Cancel Booking',
                cancelButtonText: 'Keep Booking'
            }).then(function(res) { if (res && res.isConfirmed) doCancel(); });
        } else if (confirm('Cancel Booking #' + bookingId + '? Payments made are NON-REFUNDABLE.')) {
            doCancel();
        }
        return false;
    };
    </script>

    <div class="modal fade" id="bookingModal" tabindex="-1" aria-labelledby="bookingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden;">
                <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff;">
                    <h5 class="modal-title fw-bold m-0" id="bookingModalLabel">
                        <i class="fas fa-calendar-plus me-2"></i> <span id="bookingModalTitleText">New Reservation</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 bg-white">
                    <form id="bookingForm" method="POST" onsubmit="return false;">
                        <div class="row g-3">

                            <div class="col-12">
                                <label class="form-label fw-bold text-secondary text-uppercase small mb-1" style="letter-spacing: 0.5px;">Purpose of Reservation</label>
                                <select class="form-select form-select-lg fw-semibold" id="bookingPurpose" name="purpose" style="border: 2px solid #7b1113; border-radius: 10px; font-size: 1rem;">
                                    <option value="reserve_grave">Reserve New Grave</option>
                                    <option value="cemetery_service">Cemetery Service</option>
                                </select>
                                <small class="text-muted" id="purposeHint"></small>
                            </div>

                            <?php if (!empty($my_plots)): ?>
                            <div class="col-12" id="userOwnedPlotsDropdownBlock" style="display: none;">
                                <label class="form-label fw-bold small text-muted mb-1">Select From Your Linked Plots</label>
                                <select class="form-select" id="userOwnedPlotsSelect">
                                    <option value="">-- Choose from your linked plots --</option>
                                    <?php foreach ($my_plots as $mp): ?>
                                        <?php 
                                        $mpSec = strtoupper(trim($mp['section']));
                                        $mpX = (int)$mp['plot_x'];
                                        $mpY = (int)$mp['plot_y'];
                                        $decList = $mp['deceased_list'] ?? [];
                                        $mpOcc = count($decList);
                                        $decNames = [];
                                        foreach ($decList as $dl) {
                                            $decNames[] = $dl['full_name'];
                                        }
                                        $decNamesStr = implode(', ', $decNames);
                                        $mpDecId = !empty($decList) ? ((int)($decList[0]['deceased_id'] ?? 0)) : 0;
                                        ?>
                                        <option value="<?php echo $mpSec; ?>_<?php echo $mpX; ?>_<?php echo $mpY; ?>"
                                                data-section="<?php echo $mpSec; ?>"
                                                data-plot-x="<?php echo $mpX; ?>"
                                                data-plot-y="<?php echo $mpY; ?>"
                                                data-plot-no="<?php echo $mpSec . '-' . $mpX . '-' . $mpY; ?>"
                                                data-deceased-name="<?php echo htmlspecialchars($decNamesStr, ENT_QUOTES); ?>"
                                                data-deceased-id="<?php echo $mpDecId > 0 ? $mpDecId : ''; ?>"
                                                data-occupants="<?php echo $mpOcc; ?>"
                                                data-deceased-json="<?php echo htmlspecialchars(json_encode($decList), ENT_QUOTES); ?>">
                                            Section <?php echo $mpSec; ?> • Plot <?php echo $mpX; ?>-<?php echo $mpY; ?> <?php echo $mpOcc > 0 ? '(Occupant' . ($mpOcc > 1 ? 's: ' : ': ') . htmlspecialchars($decNamesStr) . ')' : '(Owned Plot)'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12" id="userOwnedPlotsMultiBlock" style="display: none;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label fw-bold small text-dark mb-0">
                                        <i class="fas fa-layer-group text-primary me-1"></i> Select Linked Plots for Service
                                    </label>
                                    <div class="d-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="userSelectAllPlotsBtn" style="font-size: 0.75rem;">
                                            <i class="fas fa-check-double me-1"></i> Select All
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="userDeselectAllPlotsBtn" style="font-size: 0.75rem;">
                                            <i class="fas fa-times me-1"></i> Deselect All
                                        </button>
                                    </div>
                                </div>
                                <div class="small text-muted mb-2">Pick which linked plot(s) to clean or service. Service fee applies per plot selected.</div>
                                <div id="userPlotsCheckboxesContainer" class="p-2 bg-white rounded border d-flex flex-column gap-2" style="max-height: 200px; overflow-y: auto;">
                                    <?php foreach ($my_plots as $idx => $mp): ?>
                                        <?php 
                                        $mpSec = strtoupper(trim($mp['section']));
                                        $mpX = (int)$mp['plot_x'];
                                        $mpY = (int)$mp['plot_y'];
                                        $decList = $mp['deceased_list'] ?? [];
                                        $mpOcc = count($decList);
                                        $decNames = [];
                                        foreach ($decList as $dl) {
                                            $decNames[] = $dl['full_name'];
                                        }
                                        $decNamesStr = implode(', ', $decNames);
                                        $chkId = "userPlotCb_" . $mpSec . "_" . $mpX . "_" . $mpY;
                                        ?>
                                        <div class="p-2 rounded border bg-light d-flex align-items-center justify-content-between">
                                            <div class="form-check mb-0 d-flex align-items-center">
                                                <input class="form-check-input user-plot-service-checkbox me-2" type="checkbox" id="<?php echo $chkId; ?>"
                                                       value="<?php echo $mpSec; ?>_<?php echo $mpX; ?>_<?php echo $mpY; ?>"
                                                       data-section="<?php echo $mpSec; ?>"
                                                       data-plot-x="<?php echo $mpX; ?>"
                                                       data-plot-y="<?php echo $mpY; ?>"
                                                       data-plot-no="<?php echo $mpSec . '-' . $mpX . '-' . $mpY; ?>"
                                                       data-deceased-name="<?php echo htmlspecialchars($decNamesStr, ENT_QUOTES); ?>"
                                                       data-occupants="<?php echo $mpOcc; ?>"
                                                       <?php echo $idx === 0 ? 'checked' : ''; ?>>
                                                <label class="form-check-label fw-semibold small text-dark cursor-pointer mb-0" for="<?php echo $chkId; ?>">
                                                    Section <?php echo $mpSec; ?> • Plot <?php echo $mpX; ?>-<?php echo $mpY; ?>
                                                    <?php if ($mpOcc > 0): ?>
                                                        <span class="text-muted fw-normal small">(<?php echo htmlspecialchars($decNamesStr); ?>)</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary-subtle text-secondary border fw-normal small">Owned Plot</span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle small">Plot #<?php echo $mpX . '-' . $mpY; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Multi-Plot Selection for Reserving New Graves -->
                            <div class="col-12" id="userMultiPlotsCardBlock">
                                <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0 !important;">
                                    <div class="p-3 d-flex flex-wrap align-items-center justify-content-between gap-2" style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                                        <div class="d-flex align-items-center gap-2">
                                            <label class="form-label fw-bold mb-0 text-secondary text-uppercase small" style="letter-spacing: 0.5px;">Selected Grave Plots</label>
                                            <span class="badge bg-primary px-2 py-1 small" id="userPlotsCountBadge">0 Plots Selected</span>
                                        </div>
                                        <button type="button" class="btn btn-outline-danger btn-sm fw-semibold" id="chooseGravePlotBtn" style="border-radius: 8px;">
                                            <i class="fas fa-plus-circle me-1"></i> Add / Choose Plot from Map
                                        </button>
                                    </div>
                                    <div class="p-3 bg-white">
                                        <div class="table-responsive">
                                            <table class="table table-bordered align-middle mb-0 small" id="userSelectedPlotsTable">
                                                <thead class="table-light">
                                                    <tr class="text-muted" style="font-size: 0.8rem;">
                                                        <th style="width: 5%;">#</th>
                                                        <th style="width: 20%;">Section</th>
                                                        <th style="width: 25%;">Plot Number</th>
                                                        <th style="width: 20%;">Lawn Type</th>
                                                        <th style="width: 20%;">Price</th>
                                                        <th style="width: 10%; text-align: center;">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="userSelectedPlotsTableBody">
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-3">
                                                            <i class="fas fa-info-circle me-1"></i> No plots selected yet. Click <strong>Add / Choose Plot from Map</strong> to select plots.
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-1">
                                            <div class="small text-muted" id="userMultiPlotNotice">
                                                <i class="fas fa-layer-group text-primary me-1"></i> You can reserve up to <strong class="text-dark" id="userMaxPlotsAllowedText">2</strong> plots for a new grave reservation.
                                            </div>
                                            <div class="small fw-bold text-dark">
                                                Subtotal: <span class="text-danger fs-6" id="userPlotsSubtotalText">₱0.00</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Single Plot Display for Add Deceased or Existing Plot Service -->
                            <div class="col-12" id="selectedPlotCardBlock" style="display: none;">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label fw-bold mb-0 text-secondary text-uppercase small" style="letter-spacing: 0.5px;">Registered Grave Plot</label>
                                </div>
                                <div class="p-3 rounded border" id="selectedPlotDisplayCard" style="background:#f8fafc; border-color:#e2e8f0;">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Section</label>
                                            <input type="text" class="form-control fw-semibold" id="selectedSectionDisplay" value="" readonly style="background:#fff;">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Plot Number</label>
                                            <input type="text" class="form-control fw-semibold text-primary" id="selectedPlotDisplay" value="" readonly style="background:#fff;">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Lawn Type</label>
                                            <input type="text" class="form-control" id="selectedLawnTypeDisplay" value="" readonly style="background:#fff;">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Status</label>
                                            <input type="text" class="form-control fw-bold" id="selectedStatusDisplay" value="" readonly style="background:#fff;">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small text-muted mb-1">Occupants</label>
                                            <input type="text" class="form-control" id="selectedOccupantsDisplay" value="" readonly style="background:#fff;">
                                        </div>

                                        <div class="col-12" id="lockedPlotNotice" style="display: none;">
                                            <div class="p-2 rounded bg-white border border-primary text-primary small d-flex align-items-center">
                                                <i class="fas fa-lock me-2 fs-6"></i>
                                                <span><strong>Locked Grave Plot:</strong> This is the plot where the new deceased will be added. Plot selection is locked.</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12" id="addDeceasedEligibilityBlock" style="display: none;">
                                <div class="p-3 rounded border mb-1" style="background: #fdfbf7; border-color: #ecd9c6;">
                                    <h6 class="fw-bold text-danger mb-3 d-flex align-items-center" style="letter-spacing: 0.5px;">
                                        <i class="fas fa-user-clock me-2"></i> Existing Deceased &amp; Eligibility
                                    </h6>
                                    <div class="row g-2 small mb-3">
                                        <div class="col-md-6">
                                            <span class="text-muted d-block">Existing Deceased:</span>
                                            <strong class="fs-6 text-dark" id="existingDeceasedNameDisplay">—</strong>
                                        </div>
                                        <div class="col-md-6">
                                            <span class="text-muted d-block">Previous Interment Date:</span>
                                            <strong class="fs-6 text-dark" id="existingIntermentDateDisplay">—</strong>
                                        </div>
                                        <div class="col-md-6">
                                            <span class="text-muted d-block">Years Since Interment:</span>
                                            <strong class="fs-6 text-dark" id="existingYearsSinceDisplay">—</strong>
                                        </div>
                                        <div class="col-md-6">
                                            <span class="text-muted d-block">Eligible for Next Interment:</span>
                                            <strong class="fs-6 text-dark" id="existingEligibleDateDisplay">—</strong>
                                        </div>
                                    </div>

                                    <div id="eligibilityStatusBox" class="alert mb-0 py-2 px-3 small border-0">
                                        <i class="fas fa-spinner fa-spin me-1"></i> Checking 8-year interval eligibility...
                                    </div>
                                </div>
                            </div>

                            <div class="col-12" id="bookingDeceasedBlock" style="display: none;">
                                <label class="form-label fw-bold">Select Deceased Record</label>
                                <select class="form-select" id="bookingDeceased" name="deceased_id">
                                    <option value="">Select Deceased Record</option>
                                    <?php foreach ($family_deceased as $member): ?>
                                        <option value="<?php echo (int)$member['deceased_id']; ?>" data-section="<?php echo htmlspecialchars(strtoupper($member['section']), ENT_QUOTES); ?>" data-plot-x="<?php echo (int)$member['plot_x']; ?>" data-plot-y="<?php echo (int)$member['plot_y']; ?>">
                                            <?php echo htmlspecialchars(trim(implode(' ', array_filter([$member['first_name'], $member['middle_name'], $member['last_name'], $member['suffix']]))) . ' — ' . (($member['plot_x'] > 0 && $member['plot_y'] > 0 && strtoupper($member['section']) !== 'UNASSIGNED') ? strtoupper($member['section']) . ' ' . $member['plot_x'] . '-' . $member['plot_y'] : 'Not Assigned')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted" id="bookingDeceasedHint">Only deceased records officially linked to your account are available for booking.</small>
                            </div>

                            <div class="col-12" id="bookingTypeContainer" style="display: none;">
                                <select class="form-select" name="booking_type" id="bookingType">
                                    <option value="existing_plot_service" selected>Service for existing plot</option>
                                    <option value="another_plot">Reserve another plot</option>
                                    <option value="other_service">Other cemetery service</option>
                                </select>
                            </div>

                            <div class="col-12" id="newDeceasedInfoBlock" style="display: none;">
                                <div class="p-3 rounded border" style="background: #fffaf0; border-color: #f0e2cd;">
                                    <h6 class="fw-bold text-dark mb-3 d-flex align-items-center" style="letter-spacing: 0.5px;">
                                        <i class="fas fa-user-plus text-danger me-2"></i> New Deceased Information
                                    </h6>
                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <label class="form-label small fw-bold text-dark mb-1">Full Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="decFullName" name="dec_full_name" placeholder="e.g. JUAN DELA CRUZ" oninput="syncDeceasedNameInputs(this.value)">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small fw-bold text-dark mb-1">Suffix</label>
                                            <select class="form-select" id="decSuffixSelect" name="dec_suffix">
                                                <option value="">No Suffix</option>
                                                <option value="JR.">JR.</option>
                                                <option value="SR.">SR.</option>
                                                <option value="I">I</option>
                                                <option value="II">II</option>
                                                <option value="III">III</option>
                                                <option value="IV">IV</option>
                                                <option value="V">V</option>
                                                <option value="VI">VI</option>
                                                <option value="OTHER">Other Suffix</option>
                                            </select>
                                            <input type="text" class="form-control form-control-sm mt-1" id="decSuffixOther" name="dec_suffix_other" placeholder="Specify suffix (e.g. VII)" style="display:none;" oninput="this.value = this.value.toUpperCase()">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Date of Birth</label>
                                            <input type="date" class="form-control" id="decBday" name="dec_bday" max="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-dark mb-1">Relationship <span class="text-muted small fw-normal">(Optional)</span></label>
                                            <select class="form-select" id="decRelationship" name="dec_relationship">
                                                <option value="">-- Select Relationship (Optional) --</option>
                                                <option value="PARENT">Parent</option>
                                                <option value="SPOUSE">Spouse</option>
                                                <option value="CHILD">Child</option>
                                                <option value="RELATIVE">Relative</option>
                                                <option value="OTHER">Other</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-dark mb-1">Date of Interment <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" id="decIntermentDate" name="dec_interment_date" min="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted mb-1">Date of Death</label>
                                            <input type="date" class="form-control" id="decDateDied" name="dec_date_died" max="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small text-muted mb-1">Notes / Instructions</label>
                                            <textarea class="form-control" id="decNotes" name="dec_notes" rows="2" placeholder="Any special requests or interment instructions..."></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <input type="hidden" id="decFirst" name="dec_first_name">
                            <input type="hidden" id="decMiddle" name="dec_middle_name">
                            <input type="hidden" id="decLast" name="dec_last_name">
                            <input type="hidden" id="decSuffix" name="dec_suffix">

                            <div class="col-12" id="cemeteryServiceFields" style="display:none;">
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label class="form-label fw-bold">Select Cemetery Services <span class="text-muted fw-normal">(Select one or more)</span></label>
                                        <div id="cemeteryServicesCheckboxes" class="p-2 bg-white rounded border" style="max-height: 180px; overflow-y: auto;">
                                            <div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i> Loading available services...</div>
                                        </div>
                                        <input type="hidden" id="serviceType" name="service_type" value="">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Preferred Date</label>
                                        <input type="date" class="form-control" id="preferredDate" name="preferred_date">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Special Requests (Optional)</label>
                                        <textarea class="form-control" id="specialRequests" name="special_requests" rows="2" placeholder="Any specific requirements..."></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold">Client Full Name</label>
                                <input type="text" class="form-control" id="fullName" name="full_name" value="<?php echo htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])); ?>" placeholder="Enter client full name" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-bold">Suffix</label>
                                <select class="form-select" id="clientSuffix" name="client_suffix">
                                    <option value="" <?php echo empty($user['suffix']) ? 'selected' : ''; ?>>No Suffix</option>
                                    <option value="JR." <?php echo (strtoupper($user['suffix'] ?? '') === 'JR.' || strtoupper($user['suffix'] ?? '') === 'JR') ? 'selected' : ''; ?>>JR.</option>
                                    <option value="SR." <?php echo (strtoupper($user['suffix'] ?? '') === 'SR.' || strtoupper($user['suffix'] ?? '') === 'SR') ? 'selected' : ''; ?>>SR.</option>
                                    <option value="I" <?php echo strtoupper($user['suffix'] ?? '') === 'I' ? 'selected' : ''; ?>>I</option>
                                    <option value="II" <?php echo strtoupper($user['suffix'] ?? '') === 'II' ? 'selected' : ''; ?>>II</option>
                                    <option value="III" <?php echo strtoupper($user['suffix'] ?? '') === 'III' ? 'selected' : ''; ?>>III</option>
                                    <option value="IV" <?php echo strtoupper($user['suffix'] ?? '') === 'IV' ? 'selected' : ''; ?>>IV</option>
                                    <option value="V" <?php echo strtoupper($user['suffix'] ?? '') === 'V' ? 'selected' : ''; ?>>V</option>
                                    <option value="VI" <?php echo strtoupper($user['suffix'] ?? '') === 'VI' ? 'selected' : ''; ?>>VI</option>
                                    <option value="OTHER">Other</option>
                                </select>
                                <input type="text" class="form-control form-control-sm mt-1" id="clientSuffixOther" name="client_suffix_other" placeholder="Specify suffix" style="display:none;" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Client Email Address</label>
                                <input type="email" class="form-control" id="emailAddress" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" placeholder="Enter client email" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Contact Number</label>
                                <input type="tel" class="form-control" id="contactNumber" name="contact_number" value="<?php echo htmlspecialchars($user['phone_no'] ?? ''); ?>" placeholder="09XXXXXXXXX" maxlength="11" minlength="11" pattern="09[0-9]{9}" title="Enter 11-digit mobile number starting with 09">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">Your Location / Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="bookerLocation" name="booker_location" placeholder="Enter your address" data-ph-address-selector="true">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold">Payment Method</label>
                                <select class="form-select" id="paymentMethod" name="payment_method">
                                    <option value="gcash" selected>GCash</option>
                                </select>
                            </div>

                            <div class="col-md-6" id="paymentPlanContainer">
                                <label class="form-label fw-bold">Payment Plan</label>
                                <select class="form-select" id="paymentPlan" name="payment_plan">
                                    <option value="full">Full Payment</option>
                                    <option value="installment" id="paymentPlanOptInstallment">Installment</option>
                                </select>
                            </div>

                            <div class="col-12" id="installmentOptions" style="display:none;">
                                <div class="p-3 rounded border" style="background:#fffbeb; border-color:#fef3c7;">
                                    <label class="form-label fw-bold">Installment Term</label>
                                    <select class="form-select" id="paymentTerm" name="payment_term">
                                        <option value="">Select installment term</option>
                                        <option value="12" data-monthly="7226.00">12 Months (1 Year) — ₱7,226.00 / mo</option>
                                        <option value="24" data-monthly="4495.00">24 Months (2 Years) — ₱4,495.00 / mo</option>
                                        <option value="36" data-monthly="3336.00">36 Months (3 Years) — ₱3,336.00 / mo</option>
                                        <option value="48" data-monthly="2772.00">48 Months (4 Years) — ₱2,772.00 / mo</option>
                                        <option value="60" data-monthly="2446.00">60 Months (5 Years) — ₱2,446.00 / mo</option>
                                    </select>

                                    <div id="userInstallmentBreakdown" class="mt-3 p-3 rounded bg-white border" style="display:none;">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span class="text-muted">Monthly Amortization:</span>
                                            <strong class="text-primary" id="userMonthlyFee">₱7,226.00 / mo</strong>
                                        </div>
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span class="text-muted">Total Contract Price:</span>
                                            <strong class="text-dark" id="userTotalPlanCost">₱86,712.00</strong>
                                        </div>
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span class="text-muted">Initial Payment Required Today (1st Month):</span>
                                            <strong class="text-success" id="userInitialPayment">₱7,226.00</strong>
                                        </div>
                                        <div class="d-flex justify-content-between small">
                                            <span class="text-muted">Projected Remaining Balance:</span>
                                            <strong class="text-danger" id="userProjectedBalance">₱79,486.00</strong>
                                        </div>
                                    </div>
                                    <div class="small text-muted mt-2">
                                        <i class="fas fa-info-circle me-1"></i>
                                        A 20% downpayment is required upon booking confirmation to secure the plot. Monthly amortization will apply to the remaining 80% balance.
                                    </div>
                                </div>
                            </div>

                            <input type="hidden" name="section" id="section" value="">
                            <input type="hidden" name="row" id="row" value="">
                            <input type="hidden" name="plot" id="plot" value="">
                            <input type="hidden" name="plot_x" id="plot_x" value="">
                            <input type="hidden" name="plot_y" id="plot_y" value="">
                            <input type="hidden" name="grave_location" id="grave_location" value="">
                            <input type="hidden" name="plot_type" id="plot_type" value="Standard">
                            <input type="hidden" name="plot_price" id="plot_price" value="0">
                            <input type="hidden" name="lawn_type" id="lawn_type" value="">
                            <input type="hidden" name="booking_status" id="booking_status" value="">
                            <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                            <input type="hidden" name="interment_fee" id="interment_fee" value="0">
                            <input type="hidden" name="service_price" id="service_price" value="0">
                            <input type="hidden" name="occupant_count" id="occupant_count" value="0">
                            <input type="hidden" name="max_occupants" id="max_occupants" value="4">
                            <input type="hidden" name="selected_plots_json" id="selected_plots_json" value="[]">
                            <input type="hidden" name="num_lots" id="num_lots" value="1">
                            <input type="hidden" name="booking_id" id="bookingDraftId" value="">

                            <div class="col-12">
                                <div class="p-3 rounded border" style="background:#f8fafc; border-color:#e2e8f0;">
                                    <div class="fw-bold mb-2 text-secondary">Price Summary</div>
                                    <div class="row g-2 small">
                                        <div class="col-md-6 d-flex justify-content-between">
                                            <span class="text-muted">Grave/Plot Price:</span>
                                            <span class="fw-bold text-dark" id="sumGravePrice">₱0.00</span>
                                        </div>
                                        <div class="col-md-6 d-flex justify-content-between">
                                            <span class="text-muted">Interment Fee:</span>
                                            <span class="fw-bold text-dark" id="sumIntermentFee">₱0.00</span>
                                        </div>
                                        <div class="col-md-6 d-flex justify-content-between">
                                            <span class="text-muted">Service Fee:</span>
                                            <span class="fw-bold text-dark" id="sumServiceFee">₱0.00</span>
                                        </div>
                                        <div class="col-md-6 d-flex justify-content-between">
                                            <span class="text-muted fw-bold" id="sumTotalLabel">Total Amount:</span>
                                            <span class="fw-bold text-danger fs-6" id="sumTotal">₱0.00</span>
                                        </div>
                                        <div class="col-md-12 justify-content-between pt-1 border-top" id="downpaymentRequiredRow" style="display: none !important;">
                                            <span class="text-primary fw-bold">20% Downpayment Required:</span>
                                            <span class="fw-bold text-primary fs-6" id="sumDownpayment">₱0.00</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- GCash Downpayment & Proof of Payment Upload Card -->
                            <div class="col-12" id="userGcashPaymentContainer">
                                <div class="p-3 rounded border" style="background: linear-gradient(135deg, #f0fdf4 0%, #e0f2fe 100%); border-color: #bae6fd;">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="fas fa-mobile-alt text-primary me-2 fs-5"></i>
                                        <h6 class="mb-0 fw-bold text-dark">GCash Payment & Proof Submission</h6>
                                        <span class="badge bg-primary ms-auto" id="userGcashPaymentBadge">Full Payment</span>
                                    </div>
                                    <p class="text-muted small mb-3">
                                        Please send payment to our official GCash account number below, then provide the reference number and screenshot proof before submitting your booking.
                                    </p>
                                    <div class="row g-3 align-items-center mb-3">
                                        <div class="col-auto">
                                            <img src="assets/gcash-logo.png" alt="GCash Logo" class="p-1 rounded shadow-sm bg-white border" style="width: 75px; height: 75px; object-fit: contain;" onerror="this.src='gcashqr1.jpg';">
                                        </div>
                                        <div class="col">
                                            <div class="fw-bold text-dark">Garden of Remembrance Memorial Park</div>
                                            <div class="text-secondary small">GCash Account Number: <strong class="text-primary fs-6">0917-823-4567</strong></div>
                                            <div class="text-muted small mt-1">
                                                Amount to pay: <strong class="text-success" id="userGcashPayAmountText">₱0.00</strong>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-dark mb-1">
                                                GCash Reference Number <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" id="bookingProofRef" name="payment_reference" placeholder="e.g. 100293847291">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-dark mb-1">
                                                Proof of Payment Screenshot <span class="text-danger">*</span>
                                            </label>
                                            <input type="file" class="form-control" id="bookingProofFile" name="payment_proof" accept="image/jpeg,image/png,image/webp,application/pdf">
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-primary w-100 mt-3 fw-bold" onclick="userPayNowViaGcash(event)">
                                        <i class="fas fa-wallet me-1"></i> Pay Now — proceed to GCash
                                    </button>
                                    <div class="form-text text-center" style="font-size:0.72rem;">No proof yet? Click Pay Now — booking is saved first, then the GCash gateway opens for the full amount due.</div>
                                </div>
                            </div>

                            <div class="col-12 mt-3">
                                <div class="p-3 rounded border" style="background: #fdfbf7; border-color: #f1e4d3 !important;">
                                    <div class="form-check d-flex align-items-start gap-2 mb-0">
                                        <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" id="userBookingTermsAgreement" name="terms_agreement" required style="cursor: pointer; width: 1.15em; height: 1.15em; border-color: #7b1113;">
                                        <div class="small text-dark" style="line-height: 1.45;">
                                            <label class="form-check-label mb-0" for="userBookingTermsAgreement" style="cursor: pointer;">
                                                I have read, understood, and agree to the 
                                            </label>
                                            <a href="javascript:void(0)" class="fw-bold text-decoration-underline" style="color: #7b1113; position: relative; z-index: 10; cursor: pointer;" onclick="event.preventDefault(); event.stopPropagation(); openCemeteryTermsModal(event, 'booking'); return false;">Terms and Agreements</a> 
                                            <label class="form-check-label mb-0" for="userBookingTermsAgreement" style="cursor: pointer;">
                                                and Cemetery Rules of <strong>Garden of Remembrance Memorial Park</strong>, including payment schedules, interment policies, and plot ownership regulations. <span class="text-danger">*</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 text-center mt-3 d-flex justify-content-center gap-2 flex-wrap">
                                <button type="button" class="btn btn-outline-secondary px-4 py-2 fw-semibold" data-bs-dismiss="modal" style="border-radius: 8px;">
                                    <i class="fas fa-arrow-left me-1"></i> <span id="bookingModalBackBtnText">Back to Reservations</span>
                                </button>
                                <button type="submit" id="userSubmitBookingBtn" class="btn btn-primary px-5 py-2 fw-semibold" onclick="handleBookingSubmit(event); return false;" style="border-radius: 8px;">
                                    <i class="fas fa-check-circle me-1"></i> Submit Reservation
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Cemetery Terms and Agreements Modal (User Dashboard) -->
    <div class="modal fade" id="cemeteryTermsModal" tabindex="-1" aria-labelledby="cemeteryTermsModalLabel" aria-hidden="true" style="z-index: 1065;">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: min(650px, 94vw);">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff;">
                    <h5 class="modal-title fw-bold m-0" id="cemeteryTermsModalLabel">
                        <i class="fas fa-file-contract me-2 text-warning"></i> <span id="cemeteryTermsTitle">Terms & Agreements</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 bg-light small" style="line-height: 1.6; color: #334155;">
                    <div class="p-3 bg-white rounded border mb-3 shadow-sm">
                        <h6 class="fw-bold text-dark mb-2"><i class="fas fa-landmark text-primary me-2"></i>1. Plot Rights & Cemetery Authority</h6>
                        <p class="mb-0">A burial plot purchase or reservation conveys exclusive perpetual interment rights under the rules and regulations of Garden of Remembrance Memorial Park. Fee simple title to the cemetery grounds and facilities remains perpetually with the memorial park administration.</p>
                    </div>

                    <div class="p-3 bg-white rounded border mb-3 shadow-sm">
                        <h6 class="fw-bold text-dark mb-2"><i class="fas fa-money-check-alt text-success me-2"></i>2. Payment, Installments & Verification</h6>
                        <p class="mb-0">All bookings are subject to administrator verification. Cash and online payments must provide legitimate transaction references. For installment contracts, the mandatory downpayment and agreed monthly amortizations must be satisfied on schedule to keep the reservation active.</p>
                    </div>

                    <div class="p-3 bg-white rounded border mb-3 shadow-sm" style="border-left: 4px solid #dc3545 !important;">
                        <h6 class="fw-bold text-danger mb-2"><i class="fas fa-calendar-times text-danger me-2"></i>3. Three (3) Months Non-Payment Cancellation Policy</h6>
                        <p class="mb-0"><strong>Automatic Cancellation:</strong> If a client fails to pay or settles no payment for three (3) consecutive months (90 days of non-payment or overdue installment amortizations), the reservation will be automatically cancelled. The reserved plot will be immediately forfeited, released, and made available for other buyers. In accordance with memorial park policy, all previous payments and reservation fees are strictly non-refundable.</p>
                    </div>

                    <div class="p-3 bg-white rounded border mb-3 shadow-sm">
                        <h6 class="fw-bold text-dark mb-2"><i class="fas fa-cross text-danger me-2"></i>4. Interment & Layering (Patong) Regulations</h6>
                        <p class="mb-0">Secondary interment (layering / patong) requires registered owner or legal family consent and compliance with sanitary interval standards (8-10 years interval or approved vault-sealed tiering). Capacity cannot exceed 4 occupants per standard lawn lot.</p>
                    </div>

                    <div class="p-3 bg-white rounded border mb-3 shadow-sm">
                        <h6 class="fw-bold text-dark mb-2"><i class="fas fa-seedling text-success me-2"></i>5. Park Maintenance, Markers & Decorum</h6>
                        <p class="mb-0">General landscaping, mowing, and structural maintenance are conducted exclusively by authorized cemetery personnel. Gravestones, markers, and decorations must conform to park design guidelines. Visitors must observe park visiting hours, cleanliness, and peace at all times.</p>
                    </div>

                    <div class="p-3 bg-white rounded border shadow-sm">
                        <h6 class="fw-bold text-dark mb-2"><i class="fas fa-user-shield text-info me-2"></i>6. Data Privacy Act Compliance</h6>
                        <p class="mb-0">All personal information collected is handled in strict compliance with the Data Privacy Act of 2012 (R.A. 10173) and will be utilized strictly for cemetery booking, reservation records, and official communications.</p>
                    </div>
                </div>
                <div class="modal-footer bg-white py-2 px-4 d-flex justify-content-between">
                    <span class="text-muted small"><i class="fas fa-shield-alt text-success me-1"></i> Official Park Policy</span>
                    <button type="button" class="btn btn-secondary px-4 fw-semibold" data-bs-dismiss="modal">I Understand</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="plotSelectionModal" tabindex="-1" aria-labelledby="plotSelectionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header d-flex justify-content-between align-items-center" style="background:#7b1113;color:#fff;">
                    <h5 class="modal-title fw-bold m-0" id="plotSelectionModalLabel">
                        <i class="fas fa-map-marked-alt me-2"></i> Choose Grave Plot
                    </h5>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-warning btn-sm fw-bold px-3 shadow-sm" onclick="finishPlotSelectionAndReturnToBooking()">
                            <i class="fas fa-check-circle me-1"></i> Done Selecting <span id="mapSelectedPlotsCountBadge" class="badge bg-dark ms-1" style="display:none;">0</span>
                        </button>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body p-0 bg-light">
                    <div class="container-fluid h-100 py-3">
                        <div class="row g-3 h-100">
                            <div class="col-lg-3">
                                <div class="card h-100 shadow-sm border-0" style="border-radius: 12px;">
                                    <div class="card-body">
                                        <h6 class="fw-bold text-danger">Cemetery Section</h6>
                                        <select class="form-select mb-3" id="plotSectionSelect"></select>
                                        <hr>
                                        <div class="small text-muted" style="line-height: 1.7;">
                                            <p class="mb-2"><strong>Tip:</strong> Click on any plot to select it for your booking reservation.</p>
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <span class="badge bg-success">Available</span> Available for reservation
                                            </div>
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <span class="badge bg-secondary">Reserved</span> Currently reserved
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-danger">Occupied</span> Occupied grave
                                            </div>
                                        </div>
                                        <div class="mt-3 p-3 bg-white rounded border shadow-sm">
                                            <div class="fw-bold small text-dark mb-2 d-flex justify-content-between align-items-center">
                                                <span><i class="fas fa-check-square me-1 text-danger"></i> Selected Plots:</span>
                                                <span class="badge bg-primary" id="mapPlotsSelectedBadge">0 / <?php echo (int)$max_allowed_user_plots; ?></span>
                                            </div>
                                            <div id="mapPlotsSelectedList" class="small text-muted py-1" style="max-height:160px;overflow-y:auto;">
                                                No plots selected yet. Click any available plot on the map to add it.
                                            </div>
                                            <button type="button" class="btn btn-primary btn-sm w-100 mt-2 fw-semibold" onclick="finishPlotSelectionAndReturnToBooking()">
                                                <i class="fas fa-arrow-left me-1"></i> Return to Booking Form
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-9">
                                <div class="card h-100 shadow-sm border-0 overflow-hidden" style="border-radius: 12px;">
                                    <div class="card-body p-0">
                                        <iframe id="plotSelectionFrame" src="sections/popup.php?selection_mode=booking&section=A" style="width:100%;height:100%;min-height:72vh;border:0;"></iframe>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="claimPlotModal" tabindex="-1" aria-labelledby="claimPlotModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content overflow-hidden border-0 shadow">
                <div class="modal-header" style="background: #7b1113; color: #ffffff;">
                    <h5 class="modal-title fw-bold text-white m-0" id="claimPlotModalLabel">
                        <i class="fas fa-monument me-2"></i> Claim / Link a Cemetery Plot
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="user_dashboard.php">
                    <input type="hidden" name="action" value="claim_plot">
                    <div class="modal-body p-4 bg-light">
                        <div class="alert alert-info py-2 px-3 small border-0 shadow-xs mb-3">
                            <i class="fas fa-info-circle me-1"></i> Already purchased or reserved another plot? Submit its details below. All claims are securely reviewed and verified by park administrators.
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-sm-5">
                                <label class="form-label small fw-bold text-dark">Section <span class="text-danger">*</span></label>
                                <select name="section" class="form-select form-select-sm" required>
                                    <option value="" disabled selected>Select</option>
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
                                <input type="text" name="plot_number" class="form-control form-control-sm" placeholder="e.g. 10-1 or 10" required>
                                <div class="form-text" style="font-size: 0.72rem;">Enter coordinates like 10-1 or row number.</div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark">Deceased Reference <span class="text-muted fw-normal">(Optional)</span></label>
                            <input type="text" name="deceased_reference" class="form-control form-control-sm" placeholder="Name of interred deceased (if any), or leave blank">
                            <div class="form-text" style="font-size: 0.72rem;">Leave blank if this plot is reserved for future family use.</div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-bold text-dark">Proof of Purchase / Notes <span class="text-muted fw-normal">(Optional)</span></label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="e.g. Certificate of Ownership #, receipt number, or date acquired"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer bg-white border-top py-2 px-3 d-flex justify-content-between">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-check-circle me-1"></i> Submit Claim for Verification
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Paid Transaction & Official Receipt Modal -->
    <div class="modal fade" id="paidTransactionModal" tabindex="-1" aria-labelledby="paidTransactionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 500px;">
            <div class="modal-content overflow-hidden border-0 shadow-lg" style="border-radius: 20px;">
                <div class="modal-header py-3 px-4 text-white" style="background: linear-gradient(135deg, #005ce6 0%, #003e99 100%);">
                    <div class="d-flex align-items-center gap-2">
                        <div style="background: rgba(255,255,255,0.2); width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-check-circle text-white fs-5"></i>
                        </div>
                        <div>
                            <h6 class="modal-title fw-bold m-0 text-white" id="paidTransactionModalLabel">GCash Payment Receipt</h6>
                            <small class="text-white text-opacity-75" style="font-size: 0.75rem;">Official Paid Transaction Slip</small>
                        </div>
                    </div>
                </div>
                <div class="modal-body p-4 bg-light" id="paidTransactionPrintArea">
                    <div class="bg-white p-4 rounded-4 shadow-sm border" style="border: 1px solid #e2e8f0 !important;">
                        <div class="text-center pb-3 border-bottom border-2 border-dashed">
                            <div class="d-inline-flex align-items-center justify-content-center mb-2" style="width: 56px; height: 56px; border-radius: 50%; background: #dcfce7; color: #16a34a;">
                                <i class="fas fa-check fs-3"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-1">Payment Successful</h5>
                            <div class="text-muted small mb-2">Garden of Remembrance Memorial Park</div>
                            <div class="fw-extrabold text-success" id="ptAmount" style="font-size: 2.2rem; font-weight: 800; letter-spacing: -0.02em;">
                                ₱0.00
                            </div>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill" style="font-size: 0.75rem;">
                                <i class="fas fa-mobile-alt me-1"></i> Paid via <span id="ptMethod">GCash</span>
                            </span>
                        </div>

                        <div class="py-3 border-bottom border-2 border-dashed small" style="line-height: 1.9;">
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">GCash Reference No:</span>
                                <span class="fw-bold text-dark font-monospace" id="ptReference">-</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Official Receipt No:</span>
                                <span class="fw-semibold text-secondary" id="ptReceiptNo">-</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Booking Reference:</span>
                                <span class="fw-bold text-dark" id="ptBookingId">-</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Purpose / Service:</span>
                                <span class="fw-semibold text-dark text-end" id="ptService">-</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Cemetery Location:</span>
                                <span class="fw-semibold text-dark text-end" id="ptLocation">-</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Customer Name:</span>
                                <span class="fw-semibold text-dark" id="ptCustomer">-</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Payment Date &amp; Time:</span>
                                <span class="text-secondary" id="ptDateTime">-</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Payment Status:</span>
                                <span class="badge bg-success text-white">PAID IN FULL</span>
                            </div>
                        </div>

                        <div id="ptProofImageSection" class="pt-3 text-center" style="display: none;">
                            <div class="text-muted small fw-bold mb-2 text-start"><i class="fas fa-image me-1"></i> Attached Payment Screenshot:</div>
                            <img id="ptProofImage" src="" alt="Proof Screenshot" class="img-fluid rounded border shadow-sm" style="max-height: 220px; object-fit: contain; cursor: pointer;">
                            <div class="mt-2">
                                <a id="ptProofDownload" href="" download class="btn btn-sm btn-outline-primary rounded-pill py-1 px-3 small">
                                    <i class="fas fa-download me-1"></i> Download Screenshot File
                                </a>
                            </div>
                        </div>

                        <div class="text-center pt-3 text-muted small" style="font-size: 0.72rem;">
                            <i class="fas fa-lock me-1 text-success"></i> Electronic payment verified by Garden of Remembrance CMIS
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white d-flex justify-content-between flex-wrap gap-2 p-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">
                        Close
                    </button>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" id="ptPrintBtn">
                            <i class="fas fa-print me-1"></i> Print Slip
                        </button>
                        <a id="ptFullReceiptLink" href="#" target="_blank" class="btn btn-primary btn-sm rounded-pill px-3 text-white" style="background: #7b1113; border-color: #7b1113;">
                            <i class="fas fa-download me-1"></i> Download Official Receipt
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- User Booking Details & Summary Modal -->
    <div class="modal fade" id="userBookingSummaryModal" tabindex="-1" aria-labelledby="userBookingSummaryModalLabel" aria-hidden="true" style="z-index: 1060;">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 540px; width: 94%; margin: 1.5rem auto;">
            <div class="modal-content overflow-hidden border-0 shadow-lg" style="border-radius: 20px;">
                <div class="modal-header py-3 px-4 text-white" style="background: linear-gradient(135deg, #7b1113 0%, #4a0a0b 100%);">
                    <div class="d-flex align-items-center gap-2">
                        <div style="background: rgba(255,255,255,0.2); width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-bookmark text-warning fs-5"></i>
                        </div>
                        <div>
                            <h6 class="modal-title fw-bold m-0 text-white" id="userBookingSummaryModalLabel">Reservation Summary</h6>
                            <small class="text-white text-opacity-75" style="font-size: 0.75rem;">Official Cemetery Reservation Record</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4 bg-light" id="userBookingSummaryBody">
                    <div class="bg-white p-3 p-sm-4 rounded-4 shadow-sm border mb-3" style="border: 1px solid #e2e8f0 !important;">
                        <!-- Header Status Badge -->
                        <div class="text-center pb-3 border-bottom border-2 border-dashed">
                            <div class="d-inline-flex align-items-center justify-content-center mb-2" id="ubsStatusIconWrapper" style="width: 52px; height: 52px; border-radius: 50%; background: #fef3c7; color: #d97706;">
                                <i class="fas fa-clock fs-3" id="ubsStatusIcon"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-1" id="ubsTitleText">Reservation Details<span id="ubsIdDisplay" style="display: none;">—</span></h5>
                            <div class="mt-1 mb-2">
                                <span class="badge bg-warning text-dark px-3 py-1 rounded-pill fw-bold" id="ubsStatusBadge" style="font-size: 0.78rem;">
                                    PENDING VERIFICATION
                                </span>
                            </div>
                            <div class="text-muted small" id="ubsStatusSubtext" style="font-size: 0.8rem; line-height: 1.4;">
                                Reservation received. Admin verification is currently pending.
                            </div>
                        </div>

                        <!-- Booking Details List -->
                        <div class="py-3 border-bottom border-2 border-dashed small" style="line-height: 2;">
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Purpose / Service:</span>
                                <span class="fw-bold text-dark text-end" id="ubsPurpose">—</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Cemetery Location:</span>
                                <span class="fw-semibold text-dark text-end text-primary" id="ubsLocation">—</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Customer Name:</span>
                                <span class="fw-semibold text-dark" id="ubsCustomer">—</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Date Created:</span>
                                <span class="text-secondary" id="ubsDate">—</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Payment Method:</span>
                                <span class="badge bg-primary text-white" id="ubsMethod">GCash</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Payment Reference:</span>
                                <span class="fw-bold text-dark font-monospace" id="ubsReference">—</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Amount Paid:</span>
                                <span class="fw-bold text-success" id="ubsAmountPaid" style="font-size: 1.05rem;">₱0.00</span>
                            </div>
                            <div class="d-flex justify-content-between" id="ubsBalanceRow">
                                <span class="text-muted">Remaining Balance:</span>
                                <span class="fw-semibold text-danger" id="ubsBalance">₱0.00</span>
                            </div>
                        </div>

                        <!-- Admin Notice -->
                        <div class="p-2 mt-3 rounded bg-light border text-muted small d-flex align-items-start gap-2" style="font-size: 0.76rem;">
                            <i class="fas fa-shield-alt text-success mt-1"></i>
                            <div>
                                Your reservation has been recorded in the cemetery database. Once your GCash transaction is confirmed by the office, your plot will be formally confirmed.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-white d-flex justify-content-between flex-wrap gap-2 p-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">
                        Close
                    </button>
                    <div class="d-flex gap-2 flex-wrap">
                        <a id="ubsMapBtn" href="#" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                            <i class="fas fa-map-marked-alt me-1"></i> View on Map
                        </a>
                        <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 text-white" id="ubsDoneBtn" data-bs-dismiss="modal" style="background: #7b1113; border-color: #7b1113;">
                            <i class="fas fa-check me-1"></i> Got it
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="paymentProofModal" tabindex="-1" aria-labelledby="paymentProofModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content overflow-hidden border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%); color: #ffffff;">
                    <h5 class="modal-title fw-bold text-white m-0" id="paymentProofModalLabel">
                        <i class="fas fa-receipt me-2"></i> Payment Proof
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-4 bg-light">
                    <img id="proofImage" src="" alt="Payment Proof" style="max-width: 100%; max-height: 520px; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); object-fit: contain; margin: 0 auto; display: block;">
                    <iframe id="proofPdfFrame" src="" style="width: 100%; height: 500px; border: none; border-radius: 12px; display: none;"></iframe>
                </div>
                <div class="modal-footer bg-white">
                    <a id="downloadProofLink" href="" download class="btn btn-primary" style="background: #7b1113; border-color: #7b1113;">
                        <i class="fas fa-download me-1"></i> Download File
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Service Completion Proof Modal -->
    <div class="modal fade" id="serviceProofModal" tabindex="-1" aria-labelledby="serviceProofModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: min(540px, 94vw); width: 94%; margin: 1.5rem auto;">
            <div class="modal-content overflow-hidden border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header" style="background: linear-gradient(135deg, #198754 0%, #157347 100%); color: #ffffff;">
                    <h5 class="modal-title fw-bold text-white m-0" id="serviceProofModalLabel">
                        <i class="fas fa-camera me-2"></i> <span id="serviceProofTitle">Service Completion Proof</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-3 p-sm-4 bg-light" style="overflow-x: hidden;">
                    <div id="serviceProofErrorBox" class="alert alert-warning py-2 px-3 small border-0 mb-3 text-start" style="display:none; border-radius: 10px;">
                        <div class="d-flex align-items-center mb-1">
                            <i class="fas fa-exclamation-triangle me-2 text-warning fs-5"></i>
                            <strong>Photo Temporarily Unavailable</strong>
                        </div>
                        <div class="text-muted">The completion photo proof could not be previewed directly. You can use the button below to download the file directly.</div>
                    </div>
                    <div class="d-flex align-items-center justify-content-center" style="min-height: 180px;">
                        <img id="serviceProofImg" src="" alt="Service Completion Proof" style="max-width: 100%; max-height: 480px; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); object-fit: contain; margin: 0 auto; display: block;">
                    </div>
                </div>
                <div class="modal-footer bg-white d-flex justify-content-between">
                    <a id="serviceProofDownload" href="" download class="btn btn-success fw-bold px-3">
                        <i class="fas fa-download me-1"></i> Download Photo
                    </a>
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Interactive Upload Proof of Payment Modal -->
    <div class="modal fade" id="uploadProofModal" tabindex="-1" aria-labelledby="uploadProofModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered" style="max-width: min(580px, 94vw); width: 94%; margin: 1.5rem auto;">
            <div class="modal-content overflow-hidden border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%); color: #ffffff;">
                    <h5 class="modal-title fw-bold m-0" id="uploadProofModalLabel">
                        <i class="fas fa-file-invoice-dollar me-2 text-warning"></i> Upload Proof of Payment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="uploadProofForm" enctype="multipart/form-data">
                    <input type="hidden" id="proofScheduleId" name="schedule_id" value="">
                    <input type="hidden" id="proofInstallmentNumber" name="installment_number" value="">
                    <div class="modal-body p-3 p-sm-4 bg-light" style="overflow-x: hidden;">
                        <div class="alert alert-info py-2 px-3 small border-0 mb-3 d-flex align-items-center" style="background: #eff6ff; color: #1e40af; border-radius: 8px;">
                            <i class="fas fa-info-circle me-2 fs-5 flex-shrink-0"></i>
                            <div>Upload your GCash payment receipt screenshot. Our administration will verify and credit your payment.</div>
                        </div>

                        <div class="mb-3">
                            <label for="proofBookingSelect" class="form-label fw-bold text-dark small mb-1">Select Booking / Contract <span class="text-danger">*</span></label>
                            <select class="form-select" id="proofBookingSelect" name="booking_id" style="max-width: 100%; word-break: break-word;" required>
                                <option value="">-- Select a booking --</option>
                                <?php if (!empty($recent_bookings)): ?>
                                    <?php foreach ($recent_bookings as $bItem): ?>
                                        <?php
                                        $bTotal = (float)($bItem['total_amount'] ?? ($bItem['plot_price'] ?? 0));
                                        $bPaid = (float)($bItem['amount_paid'] ?? 0);
                                        $bReceiptStatus = strtoupper(trim((string)($bItem['receipt_payment_status'] ?? '')));
                                        $bPayStatus = strtoupper(trim((string)($bItem['payment_status'] ?? '')));
                                        $bIsVerified = in_array($bPayStatus, ['PAID', 'COMPLETED', 'SETTLED', 'VERIFIED'], true) || in_array($bReceiptStatus, ['PAID', 'COMPLETED', 'SETTLED', 'VERIFIED', 'APPROVED'], true);
                                        if ($bIsVerified) {
                                            $bPaid = max($bPaid, (float)($bItem['receipt_amount_paid'] ?? 0));
                                        }
                                        $bBal = max(0, $bTotal - $bPaid);
                                        $bIsFullyPaid = $bIsVerified && (($bBal <= 0 && $bPaid > 0) || in_array($bPayStatus, ['PAID', 'COMPLETED', 'SETTLED', 'VERIFIED'], true));
                                        $locDesc = !empty($bItem['grave_location']) ? $bItem['grave_location'] : ('Section ' . ($bItem['section'] ?? '') . ' Plot ' . ($bItem['plot'] ?? ''));

                                        $bBalFmt = $bIsFullyPaid ? 'PAID - Settled' : ('Bal: ₱' . number_format($bBal, 2));

                                        // Format a clean, non-overflowing location label for dropdowns
                                        $locClean = trim($locDesc);
                                        if (preg_match('/\((\d+)\s+plots?\)/i', $locClean, $pm)) {
                                            $plotCount = $pm[1];
                                            $secName = !empty($bItem['section']) ? strtoupper(trim(preg_replace('/^SECTION\s*/i', '', $bItem['section']))) : '';
                                            if ($secName === '' && preg_match('/Section\s+([A-Za-z])/i', $locClean, $sm)) {
                                                $secName = strtoupper(trim($sm[1]));
                                            }
                                            preg_match_all('/(\d{1,3}\s*[-,\s]\s*\d{1,3})/', $locClean, $allPlots);
                                            $plotList = '';
                                            if (!empty($allPlots[0])) {
                                                $cleanPairs = array_map(function($p) { return preg_replace('/\s+/', '', str_replace(',', '-', $p)); }, array_slice($allPlots[0], 0, 3));
                                                $plotList = implode(', ', $cleanPairs);
                                                if (count($allPlots[0]) > 3) $plotList .= '...';
                                            }
                                            $shortLoc = (!empty($secName) ? "Sec {$secName} " : '') . "({$plotCount} Plots" . (!empty($plotList) ? ": {$plotList}" : '') . ")";
                                        } else {
                                            $shortLoc = preg_replace('/^Section\s+/i', 'Sec ', $locClean);
                                            $shortLoc = preg_replace('/Plot\s+/i', '', $shortLoc);
                                        }
                                        if (mb_strlen($shortLoc) > 38) {
                                            $shortLoc = mb_substr($shortLoc, 0, 35) . '...';
                                        }
                                        ?>
                                        <option value="<?php echo (int)$bItem['id']; ?>" data-balance="<?php echo number_format($bBal, 2, '.', ''); ?>" data-location="<?php echo htmlspecialchars($locDesc); ?>" data-is-paid="<?php echo $bIsFullyPaid ? '1' : '0'; ?>" data-has-proof="<?php echo !empty($bItem['payment_proof_filename']) ? '1' : '0'; ?>" title="<?php echo htmlspecialchars("Booking #{$bItem['id']} — {$locDesc} ({$bBalFmt})"); ?>">
                                            Booking #<?php echo (int)$bItem['id']; ?> — <?php echo htmlspecialchars($shortLoc); ?> (<?php echo $bBalFmt; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label for="proofPaymentMethod" class="form-label fw-bold text-dark small mb-1">Payment Method</label>
                                <select class="form-select form-select-sm" id="proofPaymentMethod" name="payment_method">
                                    <option value="GCash" selected>GCash</option>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label for="proofAmountPaid" class="form-label fw-bold text-dark small mb-1">Amount Paid (₱) <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white">₱</span>
                                    <input type="number" step="0.01" min="1" class="form-control" id="proofAmountPaid" name="amount_paid" placeholder="0.00" required>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="proofReferenceNumber" class="form-label fw-bold text-dark small mb-1">
                                Reference / Transaction Number <span class="text-muted fw-normal">(Optional)</span>
                            </label>
                            <input type="text" class="form-control form-control-sm" id="proofReferenceNumber" name="gcash_reference" placeholder="e.g. 10023948195 (leave blank to auto-generate)">
                            <div class="text-muted small mt-1" style="font-size: 0.76rem;">Found on your receipt or SMS confirmation.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small mb-1">Payment Proof File / Screenshot <span class="text-danger">*</span></label>
                            <div class="upload-dropzone p-3 bg-white text-center rounded border" id="proofDropzone" style="border: 2px dashed #cbd5e1 !important; cursor: pointer; transition: border-color 0.2s;">
                                <input type="file" id="proofModalFileInput" name="payment_proof" accept="image/jpeg,image/png,image/webp,application/pdf" style="display: none;" required>
                                <div id="dropzonePrompt">
                                    <i class="fas fa-cloud-upload-alt text-danger fa-2x mb-2"></i>
                                    <div class="fw-semibold text-dark small">Click to browse or drag & drop proof here</div>
                                    <div class="text-muted small" style="font-size: 0.76rem;">Supports JPG, PNG, WebP and PDF up to 10MB</div>
                                </div>
                                <div id="dropzonePreview" style="display: none;">
                                    <div class="d-flex align-items-center justify-content-between p-2 rounded bg-light border">
                                        <div class="d-flex align-items-center gap-2 overflow-hidden" style="max-width: 80%;">
                                            <img id="modalProofPreviewImg" src="" alt="Proof Preview" style="width: 48px; height: 48px; object-fit: cover; border-radius: 6px; border: 1px solid #ddd; display: none;">
                                            <i id="modalProofPdfIcon" class="fas fa-file-pdf text-danger fa-2x" style="display: none;"></i>
                                            <div class="text-start overflow-hidden small">
                                                <div class="fw-bold text-dark text-truncate" id="modalProofFileName">proof.png</div>
                                                <div class="text-muted" id="modalProofFileSize">0 KB</div>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" id="modalProofClearBtn" style="font-size: 0.78rem;">
                                            <i class="fas fa-times me-1"></i> Change
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label for="proofNotes" class="form-label fw-bold text-dark small mb-1">Notes / Remarks <span class="text-muted fw-normal">(Optional)</span></label>
                            <textarea class="form-control form-control-sm" id="proofNotes" name="notes" rows="2" placeholder="Any additional payment details or instructions..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer bg-white border-top">
                        <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-danger text-white px-4 fw-bold" id="proofSubmitBtn" style="background: #7b1113; border-color: #7b1113;">
                            <i class="fas fa-upload me-1"></i> Submit Payment Proof
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    window.startPlotBooking = function(section, plotX, plotY, deceasedId, purpose) {
        var bModal = document.getElementById('bookingModal');
        if (!bModal) return;
        var modalInst = bootstrap.Modal.getOrCreateInstance(bModal);

        if (typeof deceasedId === 'string' && !purpose) {
            purpose = deceasedId;
            deceasedId = 0;
        }
        deceasedId = Number(deceasedId) || 0;

        var secField = document.getElementById('section');
        var plotField = document.getElementById('plot');
        var pxField = document.getElementById('plot_x');
        var pyField = document.getElementById('plot_y');
        var bTypeField = document.getElementById('bookingType');
        var bPurpose = document.getElementById('bookingPurpose');
        var decSel = document.getElementById('bookingDeceased');

        if (secField) secField.value = section || '';
        if (plotField) plotField.value = (plotX && plotY) ? (plotX + '-' + plotY) : '';
        if (pxField) pxField.value = plotX || '';
        if (pyField) pyField.value = plotY || '';
        var graveLoc = document.getElementById('grave_location');
        if (graveLoc) graveLoc.value = (section && plotX && plotY) ? (section + ' ' + plotX + '-' + plotY) : '';

        var secDisplay = document.getElementById('selectedSectionDisplay');
        var plotDisplay = document.getElementById('selectedPlotDisplay');
        var statusDisplay = document.getElementById('selectedStatusDisplay');
        if (secDisplay) secDisplay.value = section || '';
        if (plotDisplay) plotDisplay.value = (plotX && plotY) ? (plotX + '-' + plotY) : '';
        if (statusDisplay) statusDisplay.value = deceasedId > 0 ? 'Occupied' : 'Owned';

        // Select the matching plot in the user owned plots dropdown if present
        var userOwnedSel = document.getElementById('userOwnedPlotsSelect');
        if (userOwnedSel && section && plotX && plotY) {
            userOwnedSel.value = section + '_' + plotX + '_' + plotY;
        }

        if (decSel) {
            decSel.value = deceasedId > 0 ? String(deceasedId) : '';
        }

        if (deceasedId > 0 && decSel) {
            decSel.value = String(deceasedId);
            if (bTypeField) bTypeField.value = 'existing_plot_service';
            if (bPurpose) bPurpose.value = 'cemetery_service';
        } else {
            if (bTypeField) bTypeField.value = 'existing_plot_service';
            if (bPurpose) bPurpose.value = (purpose === 'reserve_grave') ? 'reserve_grave' : 'cemetery_service';
        }

        if (typeof updatePurposeUI === 'function') updatePurposeUI();
        window.__returnToMyBookings = true;
        window.__returnToMyBookingsTab = 'plots';
        var backBtnText = document.getElementById('bookingModalBackBtnText');
        if (backBtnText) {
            backBtnText.textContent = 'Back to My Plots';
        }
        var myBookingsEl = document.getElementById('myBookingsModal');
        if (myBookingsEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            var bsMyBookings = bootstrap.Modal.getInstance(myBookingsEl);
            if (bsMyBookings) {
                bsMyBookings.hide();
            }
        }
        modalInst.show();
    };
    window.startFamilyBooking = function(deceasedId, section, plotX, plotY) {
        window.startPlotBooking(section, plotX, plotY, deceasedId, 'cemetery_service');
    };

    window.userDeleteRejectedClaim = function(claimId, section, px, py) {
        if (!confirm('Remove rejected claim for Section ' + section + ' Plot ' + px + '-' + py + ' from your account?')) return;

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'user_dashboard.php#myPlotsSection';

        var actInput = document.createElement('input');
        actInput.type = 'hidden';
        actInput.name = 'action';
        actInput.value = 'user_delete_rejected_claim';
        form.appendChild(actInput);

        var idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'claim_id';
        idInput.value = claimId;
        form.appendChild(idInput);

        document.body.appendChild(form);
        form.submit();
    };

    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }
    window.escapeHtml = escapeHtml;

    // Navigation and Mobile Drawer functionality
    (function() {
        var mobileMenuButton = document.getElementById('mobileMenuButton');
        var mobileDrawer = document.getElementById('mobileDrawer');
        var mobileDrawerClose = document.getElementById('mobileDrawerClose');
        var mobileDrawerOverlay = document.getElementById('mobileDrawerOverlay');

        function openMobileDrawer() {
            if (!mobileDrawer || !mobileDrawerOverlay) return;
            mobileDrawer.classList.add('active');
            mobileDrawerOverlay.classList.add('active');
            mobileDrawer.setAttribute('aria-hidden', 'false');
        }

        function closeMobileDrawer() {
            if (!mobileDrawer || !mobileDrawerOverlay) return;
            mobileDrawer.classList.remove('active');
            mobileDrawerOverlay.classList.remove('active');
            mobileDrawer.setAttribute('aria-hidden', 'true');
        }

        if (mobileMenuButton) mobileMenuButton.addEventListener('click', openMobileDrawer);
        if (mobileDrawerClose) mobileDrawerClose.addEventListener('click', closeMobileDrawer);
        if (mobileDrawerOverlay) mobileDrawerOverlay.addEventListener('click', closeMobileDrawer);

        document.querySelectorAll('.mobile-drawer-nav a').forEach(function(link) {
            link.addEventListener('click', closeMobileDrawer);
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') closeMobileDrawer();
        });

        document.addEventListener('show.bs.modal', function() {
            closeMobileDrawer();
        });
    })();

    // Payment proof viewer
    function viewPaymentProof(bookingId, filename) {
        if (!filename || filename === 'null' || filename === 'undefined' || filename.trim() === '') {
            if (typeof openPaidTransactionModal === 'function') {
                return openPaidTransactionModal(bookingId);
            }
        }
        var modalEl = document.getElementById('paymentProofModal');
        if (!modalEl) return;
        var imageUrl = 'view_payment_proof.php?booking_id=' + bookingId + '&file=' + encodeURIComponent(filename);
        var proofImg = document.getElementById('proofImage');
        var proofPdf = document.getElementById('proofPdfFrame');
        var downloadLink = document.getElementById('downloadProofLink');
        var isPdf = (filename || '').toLowerCase().endsWith('.pdf');

        if (isPdf) {
            if (proofImg) proofImg.style.display = 'none';
            if (proofPdf) {
                proofPdf.src = imageUrl;
                proofPdf.style.display = 'block';
            }
        } else {
            if (proofPdf) {
                proofPdf.style.display = 'none';
                proofPdf.src = '';
            }
            if (proofImg) {
                proofImg.src = imageUrl;
                proofImg.style.display = 'block';
            }
        }
        if (downloadLink) downloadLink.href = imageUrl + '&download=1';
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
    window.viewPaymentProof = viewPaymentProof;

    function viewServiceProofModal(proofFile, bookingId, serviceType) {
        if (!proofFile || proofFile.trim() === '') {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'info', title: 'No Proof Available', text: 'No completion proof photo has been uploaded for this service.' });
            } else {
                alert('No completion proof photo has been uploaded for this service.');
            }
            return;
        }
        var modalEl = document.getElementById('serviceProofModal');
        if (!modalEl) return;
        var imgEl = document.getElementById('serviceProofImg');
        var titleEl = document.getElementById('serviceProofTitle');
        var downloadEl = document.getElementById('serviceProofDownload');
        var errBox = document.getElementById('serviceProofErrorBox');
        var cleanFile = proofFile.replace(/^[\\\/]+/, '').replace(/^uploads[\/\\]service-proofs[\/\\]/i, '');
        var fullPath = 'uploads/service-proofs/' + encodeURIComponent(cleanFile);

        if (titleEl) titleEl.textContent = 'Service Completion Proof — Booking #' + bookingId + ' (' + (serviceType || 'Service') + ')';
        if (errBox) errBox.style.display = 'none';

        if (imgEl) {
            imgEl.style.display = 'block';
            imgEl.onerror = function() {
                if (!this.dataset.triedFallback) {
                    this.dataset.triedFallback = '1';
                    this.src = '../uploads/service-proofs/' + encodeURIComponent(cleanFile);
                    return;
                }
                this.style.display = 'none';
                if (errBox) errBox.style.display = 'block';
            };
            imgEl.onload = function() {
                this.style.display = 'block';
                if (errBox) errBox.style.display = 'none';
            };
            imgEl.dataset.triedFallback = '';
            imgEl.src = fullPath;
        }

        if (downloadEl) {
            downloadEl.href = fullPath;
            downloadEl.setAttribute('download', cleanFile);
        }

        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
    window.viewServiceProofModal = viewServiceProofModal;

    // Paid transaction & official GCash voucher viewer
    function openPaidTransactionModal(bookingId) {
        var b = (window.userBookingsData && window.userBookingsData[bookingId]) ? window.userBookingsData[bookingId] : null;
        var modalEl = document.getElementById('paidTransactionModal');
        if (!modalEl) return;

        if (b) {
            var elAmt = document.getElementById('ptAmount');
            var elRef = document.getElementById('ptReference');
            var elRcpt = document.getElementById('ptReceiptNo');
            var elBId = document.getElementById('ptBookingId');
            var elSvc = document.getElementById('ptService');
            var elLoc = document.getElementById('ptLocation');
            var elCust = document.getElementById('ptCustomer');
            var elDt = document.getElementById('ptDateTime');
            var elMtd = document.getElementById('ptMethod');

            if (elAmt) elAmt.textContent = '₱' + b.amount_paid;
            if (elRef) elRef.textContent = b.reference || ('GC-' + b.id);
            if (elRcpt) elRcpt.textContent = b.or_number || 'N/A';
            if (elBId) elBId.textContent = 'Booking #' + b.id;
            if (elSvc) elSvc.textContent = b.purpose || 'Cemetery Booking';
            if (elLoc) elLoc.textContent = b.location || 'N/A';
            if (elCust) elCust.textContent = b.customer || 'Customer Name';
            if (elDt) elDt.textContent = b.date || '';
            if (elMtd) elMtd.textContent = b.method || 'GCash';

            var proofSec = document.getElementById('ptProofImageSection');
            var proofImg = document.getElementById('ptProofImage');
            var proofDl = document.getElementById('ptProofDownload');
            if (b.proof_file && b.proof_file !== 'null' && b.proof_file.trim() !== '') {
                var imgUrl = 'view_payment_proof.php?booking_id=' + b.id + '&file=' + encodeURIComponent(b.proof_file);
                if (proofImg) {
                    proofImg.src = imgUrl;
                    proofImg.onclick = function() {
                        viewPaymentProof(b.id, b.proof_file);
                    };
                }
                if (proofDl) proofDl.href = imgUrl + '&download=1';
                if (proofSec) proofSec.style.display = 'block';
            } else {
                if (proofSec) proofSec.style.display = 'none';
            }

            var fullReceiptLink = document.getElementById('ptFullReceiptLink');
            if (fullReceiptLink) {
                fullReceiptLink.href = 'payment-success.php?booking_id=' + b.id + (b.payment_id ? '&payment_id=' + b.payment_id : '');
            }
        }

        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
    window.openPaidTransactionModal = openPaidTransactionModal;

    // Print slip handler
    var ptPrintBtn = document.getElementById('ptPrintBtn');
    if (ptPrintBtn && !ptPrintBtn.__attached) {
        ptPrintBtn.__attached = true;
        ptPrintBtn.addEventListener('click', function() {
            var printArea = document.getElementById('paidTransactionPrintArea');
            if (!printArea) return;
            var printContents = printArea.innerHTML;
            var printWin = window.open('', '_blank', 'width=650,height=750');
            if (!printWin) {
                window.print();
                return;
            }
            printWin.document.write('<!DOCTYPE html><html><head><title>GCash Payment Receipt</title>');
            printWin.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">');
            printWin.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">');
            printWin.document.write('<style>body{padding:25px;background:#ffffff;font-family:system-ui,-apple-system,sans-serif;} .border-dashed{border-style:dashed !important;} .btn{display:none !important;}</style>');
            printWin.document.write('</head><body>');
            printWin.document.write(printContents);
            printWin.document.write('</body></html>');
            printWin.document.close();
            printWin.focus();
            setTimeout(function() {
                printWin.print();
                printWin.close();
            }, 600);
        });
    }

    // Open Upload Proof Modal helper
    window.openUploadProofModal = function(bookingId, locationText, balance, installmentNumber, scheduleId, prefillRef) {
        var modalEl = document.getElementById('uploadProofModal');
        if (!modalEl) return;

        // ── Single-upload enforcement ──────────────────────────────────────────
        // Check if a proof has already been uploaded for this booking.
        // If so, show an informational alert instead of opening the modal.
        if (bookingId && !installmentNumber) {
            var bSelect0 = document.getElementById('proofBookingSelect');
            var existingOpt = bSelect0 ? bSelect0.querySelector('option[value="' + bookingId + '"]') : null;
            var hasProof = existingOpt ? (existingOpt.getAttribute('data-has-proof') === '1') : false;
            if (hasProof) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'info',
                        title: 'Proof Already Submitted',
                        html:
                            '<div style="text-align:left;font-size:0.95rem;line-height:1.7;">' +
                            '<div class="alert alert-info d-flex align-items-start py-2 px-3 mb-3 border-0" style="background:#eff6ff;border-radius:8px;color:#1e40af;">' +
                                '<i class="fas fa-check-circle me-2 fs-5 flex-shrink-0 mt-1" style="color:#3b82f6;"></i>' +
                                '<div>A payment proof has <strong>already been submitted</strong> for Booking #' + bookingId + '. You cannot upload a second proof for the same booking.</div>' +
                            '</div>' +
                            '<p class="small text-muted mb-0">If your previous proof was rejected or you need to resubmit, please contact the cemetery administration directly.</p>' +
                            '</div>',
                        confirmButtonColor: '#7b1113',
                        confirmButtonText: 'OK'
                    });
                } else {
                    alert('A payment proof has already been submitted for Booking #' + bookingId + '. You cannot upload a second proof.');
                }
                return; // Do not open the modal
            }
        }
        // ──────────────────────────────────────────────────────────────────────

        var bSelect = document.getElementById('proofBookingSelect');
        if (bSelect) {
            if (bookingId) {
                var targetOpt = bSelect.querySelector('option[value="' + bookingId + '"]');
                if (targetOpt) {
                    targetOpt.disabled = false;
                } else {
                    targetOpt = document.createElement('option');
                    targetOpt.value = String(bookingId);
                    targetOpt.textContent = 'Booking #' + bookingId + (locationText ? (' — ' + locationText) : '');
                    if (balance) targetOpt.setAttribute('data-balance', balance);
                    bSelect.appendChild(targetOpt);
                }
                bSelect.value = String(bookingId);
            } else if (!bSelect.value && bSelect.options.length > 1) {
                for (var i = 1; i < bSelect.options.length; i++) {
                    if (!bSelect.options[i].disabled) {
                        bSelect.selectedIndex = i;
                        break;
                    }
                }
            }
        }

        var amountInput = document.getElementById('proofAmountPaid');
        if (amountInput) {
            if (balance !== undefined && balance !== null && balance !== '') {
                var cleanBal = String(balance).replace(/[^0-9.]/g, '');
                amountInput.value = cleanBal;
            } else if (bSelect && bSelect.selectedOptions && bSelect.selectedOptions[0]) {
                var optBal = bSelect.selectedOptions[0].getAttribute('data-balance');
                if (optBal) amountInput.value = optBal;
            }
        }

        var schedInput = document.getElementById('proofScheduleId');
        if (schedInput) schedInput.value = scheduleId || '';

        var instInput = document.getElementById('proofInstallmentNumber');
        if (instInput) instInput.value = installmentNumber || '';

        var refInput = document.getElementById('proofReferenceNumber');
        if (refInput) refInput.value = prefillRef || '';

        var notesInput = document.getElementById('proofNotes');
        if (notesInput) {
            if (installmentNumber) {
                notesInput.value = 'Payment for Installment #' + installmentNumber;
            } else {
                notesInput.value = '';
            }
        }

        // Reset file preview
        var fileInput = document.getElementById('proofModalFileInput');
        if (fileInput) fileInput.value = '';
        var promptBox = document.getElementById('dropzonePrompt');
        var previewBox = document.getElementById('dropzonePreview');
        if (promptBox) promptBox.style.display = 'block';
        if (previewBox) previewBox.style.display = 'none';

        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    };

    // Live preview & Form submit handlers
    document.addEventListener('DOMContentLoaded', function() {
        // 1. Upload Proof Modal: Dynamic balance sync
        var pSelect = document.getElementById('proofBookingSelect');
        var pAmount = document.getElementById('proofAmountPaid');
        if (pSelect && pAmount) {
            pSelect.addEventListener('change', function() {
                var opt = this.selectedOptions ? this.selectedOptions[0] : null;
                if (opt && (opt.disabled || opt.getAttribute('data-is-paid') === '1')) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'info',
                            title: 'Booking Fully Paid',
                            text: 'This booking is already fully settled. No additional payment proofs are required.',
                            confirmButtonColor: '#7b1113'
                        });
                    }
                    pSelect.value = '';
                    pAmount.value = '';
                    return;
                }
                if (opt && opt.getAttribute('data-balance')) {
                    pAmount.value = opt.getAttribute('data-balance');
                }
            });
        }

        // 3. Upload Proof Modal: Dropzone & File preview
        var dropzone = document.getElementById('proofDropzone');
        var modalFileInput = document.getElementById('proofModalFileInput');
        var modalPrompt = document.getElementById('dropzonePrompt');
        var modalPreview = document.getElementById('dropzonePreview');
        var modalImg = document.getElementById('modalProofPreviewImg');
        var modalPdf = document.getElementById('modalProofPdfIcon');
        var modalName = document.getElementById('modalProofFileName');
        var modalSize = document.getElementById('modalProofFileSize');
        var modalClear = document.getElementById('modalProofClearBtn');

        function renderModalProofPreview(file) {
            if (!file) return;
            if (modalName) modalName.textContent = file.name;
            if (modalSize) modalSize.textContent = (file.size / 1024).toFixed(1) + ' KB';
            var isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
            if (isPdf) {
                if (modalImg) modalImg.style.display = 'none';
                if (modalPdf) modalPdf.style.display = 'inline-block';
            } else {
                if (modalPdf) modalPdf.style.display = 'none';
                if (modalImg) {
                    var r = new FileReader();
                    r.onload = function(e) {
                        modalImg.src = e.target.result;
                        modalImg.style.display = 'block';
                    };
                    r.readAsDataURL(file);
                }
            }
            if (modalPrompt) modalPrompt.style.display = 'none';
            if (modalPreview) modalPreview.style.display = 'block';
        }

        if (dropzone && modalFileInput) {
            dropzone.addEventListener('click', function(e) {
                if (!modalClear || (e.target !== modalClear && !modalClear.contains(e.target))) {
                    modalFileInput.click();
                }
            });

            ['dragenter', 'dragover'].forEach(function(evt) {
                dropzone.addEventListener(evt, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.style.borderColor = '#7b1113';
                    dropzone.style.backgroundColor = '#fff5f5';
                });
            });

            ['dragleave', 'drop'].forEach(function(evt) {
                dropzone.addEventListener(evt, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.style.borderColor = '#cbd5e1';
                    dropzone.style.backgroundColor = '#ffffff';
                });
            });

            dropzone.addEventListener('drop', function(e) {
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
                    modalFileInput.files = e.dataTransfer.files;
                    renderModalProofPreview(e.dataTransfer.files[0]);
                }
            });

            modalFileInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    renderModalProofPreview(this.files[0]);
                }
            });
        }

        if (modalClear) {
            modalClear.addEventListener('click', function(e) {
                e.stopPropagation();
                if (modalFileInput) modalFileInput.value = '';
                if (modalPrompt) modalPrompt.style.display = 'block';
                if (modalPreview) modalPreview.style.display = 'none';
                if (modalImg) { modalImg.src = ''; modalImg.style.display = 'none'; }
                if (modalPdf) modalPdf.style.display = 'none';
            });
        }

        // 4. Upload Proof Form AJAX Submission
        var uploadForm = document.getElementById('uploadProofForm');
        var proofBtn = document.getElementById('proofSubmitBtn');
        if (uploadForm) {
            uploadForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                var bId = document.getElementById('proofBookingSelect') ? document.getElementById('proofBookingSelect').value : '';
                if (!bId) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'warning', title: 'Select Booking', text: 'Please select which booking this payment is for.' });
                    } else {
                        alert('Please select a booking.');
                    }
                    return;
                }

                if (!modalFileInput || !modalFileInput.files || !modalFileInput.files[0]) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'warning', title: 'File Required', text: 'Please select a proof of payment file or screenshot to upload.' });
                    } else {
                        alert('Please attach your payment proof file.');
                    }
                    return;
                }

                var originalBtnHtml = proofBtn ? proofBtn.innerHTML : 'Submit Payment Proof';
                if (proofBtn) {
                    proofBtn.disabled = true;
                    proofBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Uploading...';
                }

                var fd = new FormData(uploadForm);
                fd.set('payment_proof', modalFileInput.files[0]);

                try {
                    var res = await fetch('upload_payment_proof.php', {
                        method: 'POST',
                        body: fd
                    });
                    var data = await res.json();
                    if (!res.ok || !data || !data.success) {
                        throw new Error(data && data.message ? data.message : 'Upload failed. Please try again.');
                    }

                    // Hide modal
                    var mInst = bootstrap.Modal.getInstance(document.getElementById('uploadProofModal'));
                    if (mInst) mInst.hide();

                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Proof Uploaded Successfully!',
                            html: '<div class="text-start mt-2">' +
                                    '<p class="mb-2 fw-semibold text-dark">Payment proof submitted. Waiting for admin verification.</p>' +
                                    '<p class="small text-muted mb-0">Booking #' + escapeHtml(bId) + ' (Reference: <code>' + escapeHtml(data.reference_number || '') + '</code>) is now awaiting admin review. Once verified and approved, your payment and reservation will be confirmed.</p>' +
                                  '</div>',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#7b1113'
                        }).then(function() {
                            window.location.href = 'user_dashboard.php?view_booking=' + encodeURIComponent(bId);
                        });
                    } else {
                        alert('Payment proof submitted. Waiting for admin verification.');
                        window.location.href = 'user_dashboard.php?view_booking=' + encodeURIComponent(bId);
                    }
                } catch (err) {
                    console.error('Proof upload error:', err);
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Upload Failed',
                            text: err.message || 'An error occurred while uploading. Please try again.',
                            confirmButtonColor: '#7b1113'
                        });
                    } else {
                        alert('Error: ' + (err.message || 'Upload failed'));
                    }
                } finally {
                    if (proofBtn) {
                        proofBtn.disabled = false;
                        proofBtn.innerHTML = originalBtnHtml;
                    }
                }
            });
        }
    });

    // Attach form listener on DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function() {
        var bookingFormEl = document.getElementById('bookingForm');
        if (bookingFormEl) {
            bookingFormEl.addEventListener('submit', function(e) {
                e.preventDefault();
                if (typeof window.handleBookingSubmit === 'function') {
                    window.handleBookingSubmit(e);
                }
                return false;
            });
        }
    });

    window.startFamilyBooking = function(deceasedId, section, plotX, plotY) {
        var select = document.getElementById('bookingDeceased');
        if (select) select.value = String(deceasedId);
        var type = document.getElementById('bookingType');
        if (type) type.value = 'existing_plot_service';
        var purpose = document.getElementById('bookingPurpose');
        if (purpose) purpose.value = 'cemetery_service';
        var sectionField = document.getElementById('section');
        var xField = document.getElementById('plot_x');
        var yField = document.getElementById('plot_y');
        if (sectionField) sectionField.value = section;
        if (xField) xField.value = plotX;
        if (yField) yField.value = plotY;
        var frame = document.getElementById('plotSelectionFrame');
        if (frame) frame.src = 'sections/popup.php?selection_mode=booking&section=' + encodeURIComponent(section) + '&current_plot_x=' + encodeURIComponent(plotX) + '&current_plot_y=' + encodeURIComponent(plotY) + '&current_plot_only=1';
        if (typeof updatePurposeUI === 'function') updatePurposeUI();
        var modal = document.getElementById('bookingModal');
        if (modal && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(modal).show();
    };

    document.addEventListener('DOMContentLoaded', function() {
        var bookingModal = document.getElementById('bookingModal');
        var plotSelectionModal = document.getElementById('plotSelectionModal');
        var choosePlotBtn = document.getElementById('chooseGravePlotBtn');
        var plotSectionSelect = document.getElementById('plotSectionSelect');
        var plotFrame = document.getElementById('plotSelectionFrame');
        var serviceSelect = document.getElementById('serviceType');
        var preferredDateInput = document.getElementById('preferredDate');
        var sectionField = document.getElementById('section');
        var plotField = document.getElementById('plot');
        var plotXField = document.getElementById('plot_x');
        var plotYField = document.getElementById('plot_y');
        var graveLocationField = document.getElementById('grave_location');
        var plotPriceField = document.getElementById('plot_price');
        var plotTypeField = document.getElementById('plot_type');
        var lawnTypeField = document.getElementById('lawn_type');
        var bookingStatusField = document.getElementById('booking_status');
        var selectedSectionDisplay = document.getElementById('selectedSectionDisplay');
        var selectedPlotDisplay = document.getElementById('selectedPlotDisplay');
        var selectedLawnTypeDisplay = document.getElementById('selectedLawnTypeDisplay');
        var selectedStatusDisplay = document.getElementById('selectedStatusDisplay');
        var selectedOccupantsDisplay = document.getElementById('selectedOccupantsDisplay');

        window.__userSelectedPlotsList = [];
        var isMobileBookingDevice = (window.innerWidth <= 768) || /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        window.maxUserPlots = 2;
        window.userOwnedPlotsKeys = <?php echo json_encode($user_owned_plots_keys); ?>;

        window.renderUserSelectedPlotsTable = function() {
            var tbody = document.getElementById('userSelectedPlotsTableBody');
            if (!tbody) return;

            var list = window.__userSelectedPlotsList || [];
            var countBadge = document.getElementById('userPlotsCountBadge');
            var subtotalDisplay = document.getElementById('userPlotsSubtotalText');
            var jsonInput = document.getElementById('selected_plots_json');
            var numLotsInput = document.getElementById('num_lots');
            var chooseBtn = document.getElementById('chooseGravePlotBtn');

            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3"><i class="fas fa-info-circle me-1"></i> No plots selected yet. Click <strong>Add / Choose Plot from Map</strong> to select plots.</td></tr>';
                if (countBadge) countBadge.textContent = '0 Plots Selected';
                if (subtotalDisplay) subtotalDisplay.textContent = '₱0.00';
                if (jsonInput) jsonInput.value = '[]';
                if (numLotsInput) numLotsInput.value = '1';
                if (chooseBtn) {
                    chooseBtn.disabled = false;
                    chooseBtn.classList.remove('disabled');
                    chooseBtn.removeAttribute('title');
                }
                if (typeof updateInstallmentLabels === 'function') {
                    updateInstallmentLabels(0, 1);
                }
                if (typeof updatePriceSummary === 'function') {
                    updatePriceSummary();
                }
                return;
            }

            var subtotal = 0;
            var html = '';
            list.forEach(function(p, idx) {
                var priceNum = Number(p.price || (p.lawn_type === 'Premium' ? 89000 : 85000));
                subtotal += priceNum;
                var plotDisplay = (p.plot_x && p.plot_y) ? (p.plot_x + '-' + p.plot_y) : (p.plot_number || '-');
                html += '<tr>' +
                    '<td class="fw-bold text-muted">' + (idx + 1) + '</td>' +
                    '<td class="fw-bold">Section ' + (p.section || '') + '</td>' +
                    '<td class="fw-bold text-primary">' + plotDisplay + '</td>' +
                    '<td>Lawn (' + (p.lawn_type || 'Regular') + ')</td>' +
                    '<td class="fw-bold text-dark">₱' + priceNum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</td>' +
                    '<td class="text-center">' +
                        '<button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 fw-semibold" onclick="removeUserPlotFromBooking(' + idx + ')">' +
                            '<i class="fas fa-trash-alt me-1"></i> Remove' +
                        '</button>' +
                    '</td>' +
                '</tr>';
            });

            tbody.innerHTML = html;

            if (countBadge) {
                countBadge.textContent = list.length + (list.length === 1 ? ' Plot Selected' : ' Plots Selected');
            }
            if (subtotalDisplay) {
                subtotalDisplay.textContent = '₱' + subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            if (jsonInput) {
                jsonInput.value = JSON.stringify(list);
            }
            if (numLotsInput) {
                numLotsInput.value = list.length;
            }

            var first = list[0];
            if (sectionField) sectionField.value = first.section || '';
            if (plotField) plotField.value = (first.plot_x && first.plot_y) ? (first.plot_x + '-' + first.plot_y) : (first.plot_number || '');
            if (plotXField) plotXField.value = first.plot_x || '';
            if (plotYField) plotYField.value = first.plot_y || '';
            if (graveLocationField) graveLocationField.value = (first.section || '') + ' ' + (first.plot_x || '') + '-' + (first.plot_y || '');
            if (plotPriceField) plotPriceField.value = subtotal;
            if (plotTypeField) plotTypeField.value = 'Lawn (' + (first.lawn_type || 'Regular') + ')';
            if (lawnTypeField) lawnTypeField.value = first.lawn_type || 'Regular';
            if (bookingStatusField) bookingStatusField.value = first.status || 'Available';

            if (chooseBtn) {
                if (list.length >= window.maxUserPlots) {
                    chooseBtn.disabled = true;
                    chooseBtn.classList.add('disabled');
                    chooseBtn.setAttribute('title', 'Maximum of 2 plots reached for a new grave reservation.');
                } else {
                    chooseBtn.disabled = false;
                    chooseBtn.classList.remove('disabled');
                    chooseBtn.removeAttribute('title');
                }
            }

            if (typeof updateInstallmentLabels === 'function') {
                updateInstallmentLabels(subtotal, list.length);
            }
            if (typeof updatePriceSummary === 'function') {
                updatePriceSummary();
            }

            // Also update the selection panel in the map modal
            var mapBadge = document.getElementById('mapPlotsSelectedBadge');
            var mapList = document.getElementById('mapPlotsSelectedList');
            var mapHeaderBadge = document.getElementById('mapSelectedPlotsCountBadge');
            var maxAllowedPlots = window.maxUserPlots || 2;
            if (mapBadge) mapBadge.textContent = list.length + ' / ' + maxAllowedPlots;
            if (mapHeaderBadge) {
                mapHeaderBadge.textContent = list.length;
                mapHeaderBadge.style.display = list.length > 0 ? 'inline-block' : 'none';
            }
            if (mapList) {
                if (list.length === 0) {
                    mapList.innerHTML = '<span class="text-muted">No plots selected yet. Click any available plot on the map to add it.</span>';
                } else {
                    var itemsHtml = '';
                    list.forEach(function(p, i) {
                        var pName = (p.plot_x && p.plot_y) ? (p.plot_x + '-' + p.plot_y) : (p.plot_number || '-');
                        itemsHtml += '<div class="d-flex justify-content-between align-items-center py-1 border-bottom">' +
                            '<span><strong class="text-danger">' + (i+1) + '.</strong> Section ' + escapeHtml(p.section) + ' (' + escapeHtml(pName) + ')</span>' +
                            '<button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" title="Remove plot" onclick="window.removeUserPlotFromBooking(' + i + ')"><i class="fas fa-times"></i></button>' +
                            '</div>';
                    });
                    mapList.innerHTML = itemsHtml;
                }
            }
        };

        window.removeUserPlotFromBooking = function(idx) {
            if (!window.__userSelectedPlotsList) return;
            window.__userSelectedPlotsList.splice(idx, 1);
            window.renderUserSelectedPlotsTable();
        };

        window.finishPlotSelectionAndReturnToBooking = function() {
            var pModal = document.getElementById('plotSelectionModal');
            var bModal = document.getElementById('bookingModal');
            if (pModal) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var inst = bootstrap.Modal.getInstance(pModal) || bootstrap.Modal.getOrCreateInstance(pModal);
                    if (inst) inst.hide();
                }
                var cBtn = pModal.querySelector('[data-bs-dismiss="modal"]');
                if (cBtn) cBtn.click();
            }
            if (bModal && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                setTimeout(function() {
                    bootstrap.Modal.getOrCreateInstance(bModal).show();
                }, 200);
            }
        };

        // Check if URL explicitly has open=booking or booking=1 (without direct plot selection)
        var urlSearch = new URLSearchParams(window.location.search);
        var hasBookingParam = (urlSearch.get('open') === 'booking' || urlSearch.get('booking') === '1') && !urlSearch.has('section') && !urlSearch.has('plot_x');
        if (hasBookingParam) {
            try {
                var cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.delete('open');
                cleanUrl.searchParams.delete('booking');
                history.replaceState({}, '', cleanUrl.pathname + (cleanUrl.search ? cleanUrl.search : ''));
            } catch(e) {}
            if (bookingModal && typeof bootstrap !== 'undefined') {
                setTimeout(function() {
                    bootstrap.Modal.getOrCreateInstance(bookingModal).show();
                }, 250);
            }
        }

        // Ensure booking modal is never ghost-displayed when navigating back (bfcache or back button)
        window.addEventListener('pageshow', function(event) {
            var isBfCache = event.persisted || (window.performance && window.performance.navigation && window.performance.navigation.type === 2);
            if (isBfCache) {
                var currentUrl = new URLSearchParams(window.location.search);
                var isExplicitBooking = currentUrl.get('booking') === '1' || currentUrl.get('open') === 'booking';
                if (!isExplicitBooking) {
                    var bModalEl = document.getElementById('bookingModal');
                    if (bModalEl && typeof bootstrap !== 'undefined') {
                        var inst = bootstrap.Modal.getInstance(bModalEl);
                        if (inst) inst.hide();
                    }
                    document.querySelectorAll('.modal-backdrop').forEach(function(b) { b.remove(); });
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }
            }
        });

        var viewBookingId = new URLSearchParams(window.location.search).get('view_booking');
        var curHash = window.location.hash;
        if (viewBookingId) {
            var bCard = document.getElementById('bookingCard_' + viewBookingId);
            var bDetails = document.getElementById('bookingDetails_' + viewBookingId);
            if (bCard) {
                setTimeout(function() {
                    bCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    bCard.style.outline = '3px solid #7b1113';
                    bCard.style.outlineOffset = '2px';
                    bCard.style.borderRadius = '12px';
                    setTimeout(function() { bCard.style.outline = ''; }, 3500);
                }, 300);
            }
            if (bDetails && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
                bootstrap.Collapse.getOrCreateInstance(bDetails).show();
            }
        } else if (curHash === '#recentBookingsSection') {
            var rSec = document.getElementById('recentBookingsSection');
            if (rSec) {
                setTimeout(function() {
                    rSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 200);
            }
        } else if (curHash === '#myPlotsSection') {
            var pSec = document.getElementById('myPlotsSection');
            if (pSec) {
                setTimeout(function() {
                    pSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 200);
            }
        }

        // Fetch cemetery services & populate checkboxes
        var svcContainer = document.getElementById('cemeteryServicesCheckboxes');
        fetch('api_booking.php?action=get_services')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success || !Array.isArray(data.services) || data.services.length === 0) {
                    if (svcContainer) svcContainer.innerHTML = '<div class="text-muted small p-2">No services available at this time.</div>';
                    return;
                }
                if (svcContainer) {
                    svcContainer.innerHTML = '';
                    data.services.forEach(function(service, idx) {
                        var item = document.createElement('div');
                        item.className = 'form-check py-1 px-2 border-bottom d-flex align-items-center justify-content-between';

                        var leftDiv = document.createElement('div');
                        leftDiv.className = 'd-flex align-items-center gap-2';

                        var chk = document.createElement('input');
                        chk.type = 'checkbox';
                        chk.className = 'form-check-input cemetery-service-checkbox m-0';
                        chk.id = 'cem_svc_' + (service.id || idx);
                        chk.value = service.service_name;
                        chk.dataset.price = Number(service.price);

                        var lbl = document.createElement('label');
                        lbl.className = 'form-check-label small fw-semibold cursor-pointer mb-0 text-dark';
                        lbl.htmlFor = chk.id;
                        lbl.textContent = service.service_name;

                        leftDiv.appendChild(chk);
                        leftDiv.appendChild(lbl);

                        var priceBadge = document.createElement('span');
                        priceBadge.className = 'badge bg-light text-primary border small font-monospace';
                        priceBadge.textContent = '₱' + Number(service.price).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                        item.appendChild(leftDiv);
                        item.appendChild(priceBadge);
                        svcContainer.appendChild(item);

                        chk.addEventListener('change', function() {
                            var selected = [];
                            document.querySelectorAll('.cemetery-service-checkbox:checked').forEach(function(c) {
                                selected.push(c.value);
                            });
                            var hiddenSvc = document.getElementById('serviceType');
                            if (hiddenSvc) hiddenSvc.value = selected.join(', ');
                            updatePriceSummary();
                        });
                    });
                }
                updatePriceSummary();
            })
            .catch(function(err) {
                console.error('Service load error', err);
                if (svcContainer) svcContainer.innerHTML = '<div class="text-danger small p-2">Failed to load cemetery services.</div>';
            });

        // Suffix "OTHER" dropdown toggles
        var clientSuffixEl = document.getElementById('clientSuffix');
        var clientSuffixOtherEl = document.getElementById('clientSuffixOther');
        if (clientSuffixEl && clientSuffixOtherEl) {
            clientSuffixEl.addEventListener('change', function() {
                if (this.value === 'OTHER') {
                    clientSuffixOtherEl.style.display = 'block';
                    clientSuffixOtherEl.required = true;
                    clientSuffixOtherEl.focus();
                } else {
                    clientSuffixOtherEl.style.display = 'none';
                    clientSuffixOtherEl.required = false;
                    clientSuffixOtherEl.value = '';
                }
            });
        }

        var decSuffixEl = document.getElementById('decSuffixSelect');
        var decSuffixOtherEl = document.getElementById('decSuffixOther');
        if (decSuffixEl && decSuffixOtherEl) {
            decSuffixEl.addEventListener('change', function() {
                if (this.value === 'OTHER') {
                    decSuffixOtherEl.style.display = 'block';
                    decSuffixOtherEl.required = true;
                    decSuffixOtherEl.focus();
                } else {
                    decSuffixOtherEl.style.display = 'none';
                    decSuffixOtherEl.required = false;
                    decSuffixOtherEl.value = '';
                }
            });
        }

        var usrDecSuffixEl = document.getElementById('usr_dec_suffix');
        var usrDecSuffixOtherEl = document.getElementById('usr_dec_suffix_other');
        if (usrDecSuffixEl && usrDecSuffixOtherEl) {
            usrDecSuffixEl.addEventListener('change', function() {
                if (this.value === 'OTHER') {
                    usrDecSuffixOtherEl.style.display = 'block';
                    usrDecSuffixOtherEl.required = true;
                    usrDecSuffixOtherEl.focus();
                } else {
                    usrDecSuffixOtherEl.style.display = 'none';
                    usrDecSuffixOtherEl.required = false;
                    usrDecSuffixOtherEl.value = '';
                }
            });
        }

        // Direct booking check from URL
        var urlParams = new URLSearchParams(window.location.search);
        var directBooking = urlParams.get('booking') === '1' || urlParams.get('open') === 'booking';
        var directPurpose = (urlParams.get('purpose') || '').trim();
        var directSection = (urlParams.get('section') || '').toUpperCase().trim();
        var directPlotX = (urlParams.get('plot_x') || '').trim();
        var directPlotY = (urlParams.get('plot_y') || '').trim();

        if (directBooking && directSection && directPlotX && directPlotY) {
            if (directPurpose && bookingPurpose) {
                bookingPurpose.value = directPurpose;
                if (typeof updatePurposeUI === 'function') updatePurposeUI();
            }
            try {
                var cleanDirectUrl = new URL(window.location.href);
                cleanDirectUrl.searchParams.delete('booking');
                cleanDirectUrl.searchParams.delete('open');
                cleanDirectUrl.searchParams.delete('purpose');
                history.replaceState({}, '', cleanDirectUrl.pathname + (cleanDirectUrl.search ? cleanDirectUrl.search : ''));
            } catch(e) {}

            var hasDirectModalShown = false;
            var applyPlotData = function(data) {
                var sec = (data && data.section) || directSection;
                var px = (data && data.plot_x) || directPlotX;
                var py = (data && data.plot_y) || directPlotY;
                var displayPlot = sec + '- ' + px + ',' + py;
                var chosenPrice = Number((data && (data.lot_price || data.price)) || 85000);
                var chosenLawn = (data && data.lawn_type) || 'Regular';

                window.__userSelectedPlotsList = [{
                    section: sec,
                    plot_x: px,
                    plot_y: py,
                    plot_number: px + '-' + py,
                    lawn_type: chosenLawn,
                    price: chosenPrice,
                    status: (data && data.status) || 'Available'
                }];

                if (sectionField) sectionField.value = sec;
                if (plotField) plotField.value = displayPlot;
                if (plotXField) plotXField.value = px;
                if (plotYField) plotYField.value = py;
                if (graveLocationField) graveLocationField.value = sec + ' ' + px + '-' + py;
                if (plotPriceField) plotPriceField.value = chosenPrice;
                if (plotTypeField) plotTypeField.value = (data && data.plot_type) || 'Lawn';
                if (lawnTypeField) lawnTypeField.value = chosenLawn;
                if (bookingStatusField) bookingStatusField.value = (data && data.status) || 'Available';
                if (document.getElementById('interment_fee')) document.getElementById('interment_fee').value = (data && data.interment_fee) || 38000;
                if (document.getElementById('occupant_count')) document.getElementById('occupant_count').value = (data && data.occupant_count) || 0;
                if (document.getElementById('max_occupants')) document.getElementById('max_occupants').value = (data && data.max_occupants) || 4;
                if (selectedSectionDisplay) selectedSectionDisplay.value = sec;
                if (selectedPlotDisplay) selectedPlotDisplay.value = displayPlot;
                if (selectedLawnTypeDisplay) selectedLawnTypeDisplay.value = chosenLawn;
                if (selectedStatusDisplay) selectedStatusDisplay.value = (data && data.status) || 'Available';
                if (selectedOccupantsDisplay) selectedOccupantsDisplay.value = ((data && data.occupant_count) || 0) + '/' + ((data && data.max_occupants) || 4);
                if (typeof window.renderUserSelectedPlotsTable === 'function') window.renderUserSelectedPlotsTable();
                if (typeof updatePriceSummary === 'function') updatePriceSummary();
                if (bookingModal && !hasDirectModalShown) {
                    hasDirectModalShown = true;
                    setTimeout(function() {
                        bootstrap.Modal.getOrCreateInstance(bookingModal).show();
                    }, 150);
                }
            };

            // Pre-fill immediately with known URL params so plot is NEVER lost
            applyPlotData(null);

            // Then try to refine with live status from server (using dual-compatible popup endpoint)
            var popupUrl = (window.location.pathname.indexOf('/sections/') !== -1) ? 'popup.php' : 'sections/popup.php';
            fetch(popupUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
                body: new URLSearchParams({
                    ajax_request: 'get_plot_booking_status',
                    section: directSection,
                    plot_x: directPlotX,
                    plot_y: directPlotY
                }).toString()
            }).then(function(response) {
                if (!response.ok) return null;
                return response.json();
            }).then(function(selection) {
                if (selection && selection.success) {
                    applyPlotData(selection);
                }
            }).catch(function(err) {
                // Fallback already populated above; silently log
                console.warn('Live plot status check skipped:', err);
            });
        } else if (directBooking) {
            try {
                var cleanDirectUrl = new URL(window.location.href);
                cleanDirectUrl.searchParams.delete('booking');
                cleanDirectUrl.searchParams.delete('open');
                cleanDirectUrl.searchParams.delete('purpose');
                history.replaceState({}, '', cleanDirectUrl.pathname + (cleanDirectUrl.search ? cleanDirectUrl.search : ''));
            } catch(e) {}
            setTimeout(function() {
                var bModalEl = document.getElementById('bookingModal');
                if (bModalEl && typeof bootstrap !== 'undefined') {
                    bootstrap.Modal.getOrCreateInstance(bModalEl).show();
                }
            }, 150);
        }

        if (preferredDateInput) {
            var today = new Date();
            var minDate = today.toISOString().split('T')[0];
            var maxDate = new Date(today.getFullYear() + 5, today.getMonth(), today.getDate());
            preferredDateInput.min = minDate;
            preferredDateInput.max = maxDate.toISOString().split('T')[0];
        }

        if (plotSectionSelect) {
            ['A','B','C','D','E','F','G','H','I','J'].forEach(function(section) {
                var option = document.createElement('option');
                option.value = section;
                option.textContent = 'Section ' + section;
                plotSectionSelect.appendChild(option);
            });
            if (!sectionField || !sectionField.value) {
                plotSectionSelect.value = 'A';
            }
        }

        function refreshPlotSelection(sectionName) {
            if (!plotFrame) return;
            var section = sectionName || 'A';
            plotFrame.src = 'sections/popup.php?selection_mode=booking&section=' + encodeURIComponent(section);
        }

        if (choosePlotBtn && plotSelectionModal) {
            choosePlotBtn.addEventListener('click', function() {
                var selectedSection = (sectionField && sectionField.value) ? sectionField.value : (plotSectionSelect ? plotSectionSelect.value : 'A');
                if (plotSectionSelect) plotSectionSelect.value = selectedSection;
                refreshPlotSelection(selectedSection);
                bootstrap.Modal.getOrCreateInstance(plotSelectionModal).show();
            });
        }

        if (plotSectionSelect) {
            plotSectionSelect.addEventListener('change', function() {
                refreshPlotSelection(this.value);
            });
        }

        function updateInstallmentLabels(price, numPlots) {
            var termSel = document.getElementById('paymentTerm');
            if (!termSel) return;
            var count = Math.max(1, numPlots || (window.__userSelectedPlotsList && window.__userSelectedPlotsList.length > 0 ? window.__userSelectedPlotsList.length : 1));
            var isPrem = Number(price) >= 89000;
            var baseRates = isPrem ? {
                12: 6052,
                24: 3766,
                36: 2794,
                48: 2322,
                60: 2049
            } : {
                12: 5781,
                24: 3596,
                36: 2669,
                48: 2218,
                60: 1957
            };
            Array.from(termSel.options).forEach(function(opt) {
                var v = opt.value;
                if (baseRates[v]) {
                    var monthly = baseRates[v] * count;
                    var yrs = v === '12' ? '1 Year' : (v === '24' ? '2 Years' : (v === '36' ? '3 Years' : (v === '48' ? '4 Years' : '5 Years')));
                    opt.textContent = v + ' Months (' + yrs + ') — ₱' + monthly.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '/mo' + (count > 1 ? ' (' + count + ' plots)' : '');
                }
            });
        }

        window.updateBookingPurposeAvailability = function(plotStatus) {
            var purposeSel = document.getElementById('bookingPurpose');
            if (!purposeSel) return;

            // Ensure both Reserve New Grave and Cemetery Service options always exist in DOM
            if (!window.__userReserveGraveOption) {
                window.__userReserveGraveOption = purposeSel.querySelector('option[value="reserve_grave"]');
            }
            if (!window.__userCemeteryServiceOption) {
                window.__userCemeteryServiceOption = purposeSel.querySelector('option[value="cemetery_service"]');
            }
            if (window.__userReserveGraveOption && !purposeSel.querySelector('option[value="reserve_grave"]')) {
                purposeSel.insertBefore(window.__userReserveGraveOption, purposeSel.firstChild);
            }
            if (window.__userCemeteryServiceOption && !purposeSel.querySelector('option[value="cemetery_service"]')) {
                purposeSel.appendChild(window.__userCemeteryServiceOption);
            }
            // NEVER automatically change purposeSel.value away from reserve_grave
            if (typeof updatePurposeUI === 'function') updatePurposeUI();
        };

        window.applySelectedPlotToBooking = function(selection) {
            if (!selection) return;

            var curPurpose = bookingPurpose ? bookingPurpose.value : 'reserve_grave';
            var sec = String(selection.section || '').toUpperCase().trim();
            var px = selection.plotX !== undefined ? selection.plotX : (selection.plot_x !== undefined ? selection.plot_x : '');
            var py = selection.plotY !== undefined ? selection.plotY : (selection.plot_y !== undefined ? selection.plot_y : '');
            var pNo = selection.plotNo || (px && py ? (px + '-' + py) : '');
            var pKey = sec + '_' + px + '_' + py;
            var isUserOwned = Array.isArray(window.userOwnedPlotsKeys) && window.userOwnedPlotsKeys.indexOf(pKey) !== -1;
            var statusNorm = String(selection.status || 'AVAILABLE').trim().toUpperCase();

            if (curPurpose === 'reserve_grave') {
                // If plot is NOT AVAILABLE:
                if (statusNorm !== 'AVAILABLE') {
                    // Do NOT close map modal and do NOT switch back to booking modal
                    // Keep user on the map so they can immediately click another plot

                    if (isUserOwned) {
                        showUserWarning(
                            'Your Registered Plot',
                            'Section ' + sec + ', Plot ' + pNo + ' is already registered to your account. To reserve maintenance or cleaning for this plot, please select "Cemetery Service" from the Purpose dropdown.'
                        );
                    } else if (statusNorm === 'RESERVED') {
                        showUserWarning(
                            'Plot Reserved by Someone Else',
                            'Section ' + sec + ', Plot ' + pNo + ' is currently reserved under another client\'s account. You cannot select or reserve this plot.'
                        );
                    } else {
                        showUserWarning(
                            'Plot Owned by Another Family',
                            'Section ' + sec + ', Plot ' + pNo + ' is occupied and owned by another family. You cannot select or reserve this plot.'
                        );
                    }

                    // Keep purpose on reserve_grave - NEVER switch automatically to cemetery service!
                    if (bookingPurpose) bookingPurpose.value = 'reserve_grave';
                    return;
                }

                // Plot IS AVAILABLE:
                if (!window.__userSelectedPlotsList) window.__userSelectedPlotsList = [];

                // Check duplicate - silently ignore if already in list, do not pop up warning modal
                var isDuplicate = window.__userSelectedPlotsList.some(function(p) {
                    return String(p.section).toUpperCase().trim() === sec && String(p.plot_x) === String(px) && String(p.plot_y) === String(py);
                });
                if (isDuplicate) {
                    return;
                }

                var maxAllowed = 2;
                if (window.__userSelectedPlotsList.length >= maxAllowed) {
                    showUserWarning('Limit Reached', 'You can reserve up to 2 plots only for a new grave reservation.');
                    return;
                }

                var pLawn = selection.lawnType || selection.lawn_type || 'Regular';
                var pPrice = Number(selection.lotPrice || selection.price || (pLawn === 'Premium' ? 89000 : 85000));

                window.__userSelectedPlotsList.push({
                    section: sec,
                    plot_x: px,
                    plot_y: py,
                    plot_number: pNo,
                    lawn_type: pLawn,
                    price: pPrice,
                    status: 'AVAILABLE'
                });

                window.renderUserSelectedPlotsTable();
                if (bookingPurpose) bookingPurpose.value = 'reserve_grave';

                // Keep user directly on the cemetery map so they can select plots seamlessly
                // No popup dialogs or automatic modal closing when adding available plots
            } else {
                // For Cemetery Service / Add Deceased: verify ownership
                if (!isUserOwned) {
                    // Do NOT close map modal and do NOT switch back to booking modal
                    // Keep user on the map so they can select their own plot

                    if (statusNorm === 'AVAILABLE') {
                        showUserWarning(
                            'Plot Is Vacant',
                            'Section ' + sec + ', Plot ' + pNo + ' is currently vacant. To reserve this plot, please change your booking purpose to "Reserve a New Grave".'
                        );
                    } else if (statusNorm === 'RESERVED') {
                        showUserWarning(
                            'Plot Reserved by Someone Else',
                            'Section ' + sec + ', Plot ' + pNo + ' is reserved under another client\'s account. You cannot request cemetery services on a plot that does not belong to you.'
                        );
                    } else {
                        showUserWarning(
                            'Plot Owned by Another Family',
                            'Section ' + sec + ', Plot ' + pNo + ' is owned by another family. You cannot request cemetery services on a plot that is not registered under your account.'
                        );
                    }
                    return;
                }
                if (sectionField) sectionField.value = selection.section || '';
                if (plotField) plotField.value = selection.plotNo || '';
                if (plotXField) plotXField.value = selection.plotX || '';
                if (plotYField) plotYField.value = selection.plotY || '';
                if (graveLocationField) graveLocationField.value = selection.graveLocation || '';
                var chosenPrice = Number(selection.lotPrice || selection.price || 85000);
                if (plotPriceField) plotPriceField.value = chosenPrice;
                if (plotTypeField) plotTypeField.value = selection.plotType || 'Lawn';
                if (lawnTypeField) lawnTypeField.value = selection.lawnType || 'Lawn (Regular)';
                if (bookingStatusField) bookingStatusField.value = selection.status || 'Available';
                document.getElementById('interment_fee') && (document.getElementById('interment_fee').value = selection.intermentFee || 38000);
                document.getElementById('occupant_count') && (document.getElementById('occupant_count').value = selection.occupantCount || 0);
                document.getElementById('max_occupants') && (document.getElementById('max_occupants').value = selection.maxOccupants || 4);
                if (selectedSectionDisplay) selectedSectionDisplay.value = selection.section || '';
                if (selectedPlotDisplay) selectedPlotDisplay.value = selection.plotNo || '';
                if (selectedLawnTypeDisplay) selectedLawnTypeDisplay.value = selection.lawnType || 'Lawn (Regular)';
                if (selectedStatusDisplay) selectedStatusDisplay.value = selection.status || 'Available';
                if (selectedOccupantsDisplay) selectedOccupantsDisplay.value = (selection.occupantCount !== undefined ? selection.occupantCount : 0) + '/' + (selection.maxOccupants || 4);
                updateInstallmentLabels(chosenPrice, 1);

                // Return to booking form for single-plot service selection
                window.finishPlotSelectionAndReturnToBooking();
            }
        };

        var lastHandledPlotTime = 0;
        var lastHandledPlotKey = '';
        window.addEventListener('message', function(event) {
            var data = event.data;
            if (typeof data === 'string') {
                try { data = JSON.parse(data); } catch (e) {}
            }
            if (!data || data.type !== 'bookingPlotSelected') return;
            var now = Date.now();
            var pKey = (data.section || '') + '_' + (data.plotX || data.plot_x) + '_' + (data.plotY || data.plot_y);
            if (pKey === lastHandledPlotKey && (now - lastHandledPlotTime) < 1000) {
                return;
            }
            lastHandledPlotKey = pKey;
            lastHandledPlotTime = now;
            window.applySelectedPlotToBooking(data);
        });

        var bookingPurpose = document.getElementById('bookingPurpose');
        var deceasedSelect = document.getElementById('bookingDeceased');
        var bookingTypeField = document.getElementById('bookingType');
        if (deceasedSelect) {
            deceasedSelect.addEventListener('change', function() {
                if (bookingTypeField) bookingTypeField.value = this.value ? 'existing_plot_service' : 'another_plot';
                var option = this.options[this.selectedIndex];
                if (option && this.value) {
                    var sectionValue = (option.dataset.section || '').toUpperCase().trim();
                    var plotXValue = option.dataset.plotX || '';
                    var plotYValue = option.dataset.plotY || '';

                    if (sectionField) sectionField.value = sectionValue;
                    if (plotXField) plotXField.value = plotXValue;
                    if (plotYField) plotYField.value = plotYValue;
                    if (plotField) plotField.value = (plotXValue && plotYValue) ? (plotXValue + '-' + plotYValue) : '';
                    var graveLoc = document.getElementById('grave_location');
                    if (graveLoc) graveLoc.value = (sectionValue && plotXValue && plotYValue) ? (sectionValue + ' ' + plotXValue + '-' + plotYValue) : '';

                    var secDisp = document.getElementById('selectedSectionDisplay');
                    var plotDisp = document.getElementById('selectedPlotDisplay');
                    var statusDisp = document.getElementById('selectedStatusDisplay');
                    if (secDisp) secDisp.value = sectionValue;
                    if (plotDisp) plotDisp.value = (plotXValue && plotYValue) ? (plotXValue + '-' + plotYValue) : '';
                    if (statusDisp) statusDisp.value = 'Occupied';

                    var userOwnedSel = document.getElementById('userOwnedPlotsSelect');
                    if (userOwnedSel && sectionValue && plotXValue && plotYValue) {
                        userOwnedSel.value = sectionValue + '_' + plotXValue + '_' + plotYValue;
                    }

                    var frame = document.getElementById('plotSelectionFrame');
                    if (frame) frame.src = 'sections/popup.php?selection_mode=booking&section=' + encodeURIComponent(sectionValue) + '&current_plot_x=' + encodeURIComponent(plotXValue) + '&current_plot_y=' + encodeURIComponent(plotYValue) + '&current_plot_only=1';
                }
            });
        }
        if (bookingTypeField) {
            bookingTypeField.addEventListener('change', function() {
                var purpose = document.getElementById('bookingPurpose');
                if (!purpose) return;
                if (this.value === 'another_plot') {
                    purpose.value = 'reserve_grave';
                    var selectedSection = (sectionField && sectionField.value) ? sectionField.value : (plotSectionSelect ? plotSectionSelect.value : 'A');
                    refreshPlotSelection(selectedSection);
                }
                if (this.value === 'existing_plot_service' || this.value === 'other_service') purpose.value = 'cemetery_service';
                updatePurposeUI();
            });
        }

        window.fetchCheckIntermentInterval = function(sec, px, py) {
            if (!sec || !px || !py) return;

            var statusBox = document.getElementById('eligibilityStatusBox');
            var existingNameEl = document.getElementById('existingDeceasedNameDisplay');
            var prevDateEl = document.getElementById('existingIntermentDateDisplay');
            var yearsSinceEl = document.getElementById('existingYearsSinceDisplay');
            var nextEligibleEl = document.getElementById('existingEligibleDateDisplay');
            var occDisplay = document.getElementById('selectedOccupantsDisplay');
            var submitBtn = document.querySelector('#bookingForm button[type="submit"]') || document.getElementById('userSubmitBookingBtn');

            if (statusBox) {
                statusBox.className = 'alert alert-info mb-0 py-2 px-3 small border-0';
                statusBox.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Checking 8-year interval eligibility...';
            }

            fetch('api_booking.php?action=check_interment_interval&section=' + encodeURIComponent(sec) + '&plot_x=' + encodeURIComponent(px) + '&plot_y=' + encodeURIComponent(py))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        if (statusBox) {
                            statusBox.className = 'alert alert-danger mb-0 py-2 px-3 small border-0';
                            statusBox.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> ' + (data.message || 'Error checking plot status.');
                        }
                        window.isCurrentPlotEligible = false;
                        return;
                    }

                    if (data.all_occupants && Array.isArray(data.all_occupants) && data.all_occupants.length > 0) {
                        if (existingNameEl) {
                            if (data.all_occupants.length === 1) {
                                var sOcc = data.all_occupants[0];
                                existingNameEl.innerHTML = escapeHtml(sOcc.full_name);
                            } else {
                                var occHtml = '<div class="mt-1 d-flex flex-column gap-1">';
                                data.all_occupants.forEach(function(occ, idx) {
                                    occHtml += '<div class="p-1 px-2 rounded bg-white border small d-flex justify-content-between align-items-center">' +
                                        '<span><span class="badge bg-secondary me-1">#' + (idx + 1) + '</span> <strong>' + escapeHtml(occ.full_name) + '</strong></span>' +
                                        (occ.interment_date ? '<span class="badge bg-light text-secondary border">Interred: ' + escapeHtml(occ.interment_date) + '</span>' : '') +
                                        '</div>';
                                });
                                occHtml += '</div>';
                                existingNameEl.innerHTML = occHtml;
                            }
                        }
                    } else if (existingNameEl) {
                        existingNameEl.textContent = data.latest_name || 'None Recorded';
                    }
                    if (prevDateEl) prevDateEl.textContent = data.formatted_interment_date || 'N/A';
                    if (yearsSinceEl) yearsSinceEl.textContent = (data.years_elapsed !== null && data.years_elapsed !== undefined) ? (data.years_elapsed + ' year(s)') : 'N/A';
                    
                    var eligibleDateDisplay = data.formatted_eligible_date || data.eligible_after_date || '';
                    if (nextEligibleEl) {
                        if (data.is_full) {
                            nextEligibleEl.textContent = 'Plot Full (Max 4)';
                            nextEligibleEl.className = 'fs-6 fw-bold text-danger';
                        } else if (!data.is_eligible) {
                            nextEligibleEl.textContent = eligibleDateDisplay || 'Ineligible (8-Year Interval)';
                            nextEligibleEl.className = 'fs-6 fw-bold text-danger';
                        } else {
                            nextEligibleEl.textContent = eligibleDateDisplay || 'Immediate (Eligible)';
                            nextEligibleEl.className = 'fs-6 fw-bold text-success';
                        }
                    }
                    if (occDisplay) occDisplay.value = (data.occupant_count || 1) + ' / ' + (data.max_occupants || 4);

                    if (data.interment_fee !== undefined) {
                        var feeInput = document.getElementById('interment_fee');
                        if (feeInput) feeInput.value = data.interment_fee;
                    }

                    if (data.is_full) {
                        window.isCurrentPlotEligible = false;
                        if (statusBox) {
                            statusBox.className = 'alert alert-danger mb-0 py-2 px-3 small border-0';
                            statusBox.innerHTML = '<div class="fw-bold"><i class="fas fa-ban me-1"></i> Maximum occupants reached for this plot.</div><div class="small">Occupancy: ' + data.occupant_count + '/' + data.max_occupants + '. No additional deceased can be added.</div>';
                        }
                        if (submitBtn) submitBtn.disabled = true;
                    } else if (!data.is_eligible) {
                        window.isCurrentPlotEligible = false;
                        if (statusBox) {
                            statusBox.className = 'alert alert-warning mb-0 py-2 px-3 small border-0';
                            statusBox.innerHTML = '<div class="fw-bold"><i class="fas fa-clock me-1"></i> Additional interment is not yet eligible.</div><div class="small">An 8-year minimum interval is required from the previous interment (' + (data.formatted_interment_date || '') + '). Eligible for Next Interment: <strong>' + (eligibleDateDisplay || 'After 8-10 years') + '</strong>.</div>';
                        }
                        if (submitBtn) submitBtn.disabled = true;
                    } else {
                        window.isCurrentPlotEligible = true;
                        if (statusBox) {
                            statusBox.className = 'alert alert-success mb-0 py-2 px-3 small border-0';
                            statusBox.innerHTML = '<div class="fw-bold"><i class="fas fa-check-circle me-1"></i> ✓ Eligible for Additional Interment</div><div class="small">The minimum 8-year interval has been met (' + (data.years_elapsed || 8) + ' years elapsed) and plot capacity is available.</div>';
                        }
                        if (submitBtn) submitBtn.disabled = false;
                    }

                    updatePriceSummary();
                })
                .catch(function(err) {
                    console.error('Error checking interment interval:', err);
                });
        };

        var userOwnedPlotSelect = document.getElementById('userOwnedPlotsSelect');
        if (userOwnedPlotSelect) {
            userOwnedPlotSelect.addEventListener('change', function() {
                var opt = this.options[this.selectedIndex];
                if (!opt || !opt.value) return;
                var sec = opt.dataset.section || '';
                var px = opt.dataset.plotX || '';
                var py = opt.dataset.plotY || '';
                var decName = opt.dataset.deceasedName || '';
                var occ = opt.dataset.occupants || '1';

                if (sectionField) sectionField.value = sec;
                if (plotField) plotField.value = px + '-' + py;
                if (plotXField) plotXField.value = px;
                if (plotYField) plotYField.value = py;
                var graveLoc = document.getElementById('grave_location');
                if (graveLoc) graveLoc.value = sec + ' ' + px + '-' + py;

                var secDisp = document.getElementById('selectedSectionDisplay');
                var plotDisp = document.getElementById('selectedPlotDisplay');
                var statusDisp = document.getElementById('selectedStatusDisplay');
                var occDisp = document.getElementById('selectedOccupantsDisplay');
                if (secDisp) secDisp.value = sec;
                if (plotDisp) plotDisp.value = px + '-' + py;
                if (statusDisp) statusDisp.value = decName ? 'Occupied' : 'Owned';
                if (occDisp) occDisp.value = occ + ' / 4';

                if (opt.dataset.deceasedJson) {
                    try {
                        var decList = JSON.parse(opt.dataset.deceasedJson || '[]');
                        if (Array.isArray(decList) && decList.length > 0) {
                            if (statusDisp) statusDisp.value = 'Occupied (' + decList.length + ' occupant' + (decList.length > 1 ? 's' : '') + ')';
                            if (occDisp) occDisp.value = decList.length + ' / 4';
                        }
                    } catch(e) {}
                }

                var decId = opt.dataset.deceasedId || '';
                var decSelect = document.getElementById('bookingDeceased');
                if (decSelect) {
                    decSelect.value = decId;
                }

                if (typeof window.updateBookingPurposeAvailability === 'function') {
                    window.updateBookingPurposeAvailability(decName ? 'OCCUPIED' : 'RESERVED');
                }

                var bPurpose = document.getElementById('bookingPurpose');
                if (bPurpose && bPurpose.value === 'add_deceased') {
                    window.fetchCheckIntermentInterval(sec, px, py);
                }
            });
        }

        function syncCemeteryServicePlots() {
            var checkedBoxes = document.querySelectorAll('.user-plot-service-checkbox:checked');
            var secDisp = document.getElementById('selectedSectionDisplay');
            var plotDisp = document.getElementById('selectedPlotDisplay');
            var statusDisp = document.getElementById('selectedStatusDisplay');
            var occDisp = document.getElementById('selectedOccupantsDisplay');

            if (checkedBoxes.length === 0) {
                if (secDisp) secDisp.value = '';
                if (plotDisp) plotDisp.value = 'None Selected';
                if (statusDisp) statusDisp.value = 'Please check at least 1 linked plot';
                if (occDisp) occDisp.value = '—';
                if (sectionField) sectionField.value = '';
                if (plotField) plotField.value = '';
                if (plotXField) plotXField.value = '';
                if (plotYField) plotYField.value = '';
                var graveLoc = document.getElementById('grave_location');
                if (graveLoc) graveLoc.value = '';
                updatePriceSummary();
                return;
            }

            var sections = [];
            var plotNumbers = [];
            var totalOccupants = 0;

            checkedBoxes.forEach(function(cb) {
                var s = (cb.dataset.section || '').toUpperCase().trim();
                var px = cb.dataset.plotX || '';
                var py = cb.dataset.plotY || '';
                var pNo = cb.dataset.plotNo || (s + '-' + px + '-' + py);
                var occ = parseInt(cb.dataset.occupants || '0', 10);

                if (s && sections.indexOf(s) === -1) sections.push(s);
                plotNumbers.push(pNo);
                totalOccupants += occ;
            });

            var firstCb = checkedBoxes[0];
            if (sectionField) sectionField.value = firstCb.dataset.section || '';
            if (plotField) plotField.value = plotNumbers.join(', ');
            if (plotXField) plotXField.value = firstCb.dataset.plotX || '';
            if (plotYField) plotYField.value = firstCb.dataset.plotY || '';

            var count = checkedBoxes.length;
            var graveLoc = document.getElementById('grave_location');
            if (graveLoc) {
                graveLoc.value = 'Section ' + sections.join('/') + ' • Plots: ' + plotNumbers.join(', ') + ' (' + count + ' plot' + (count > 1 ? 's' : '') + ')';
            }

            if (secDisp) secDisp.value = sections.join(', ');
            if (plotDisp) plotDisp.value = plotNumbers.join(', ') + ' (' + count + ' plot' + (count > 1 ? 's selected' : ' selected') + ')';
            if (statusDisp) statusDisp.value = count > 1 ? (count + ' Linked Plots Selected') : 'Linked Plot';
            if (occDisp) occDisp.value = totalOccupants + ' total occupant(s) across selected plots';

            updatePriceSummary();
        }
        window.syncCemeteryServicePlots = syncCemeteryServicePlots;

        document.querySelectorAll('.user-plot-service-checkbox').forEach(function(cb) {
            cb.addEventListener('change', function() {
                var bPurpose = document.getElementById('bookingPurpose');
                if (bPurpose && bPurpose.value === 'cemetery_service') {
                    syncCemeteryServicePlots();
                }
            });
        });

        var userSelectAllBtn = document.getElementById('userSelectAllPlotsBtn');
        if (userSelectAllBtn) {
            userSelectAllBtn.addEventListener('click', function() {
                document.querySelectorAll('.user-plot-service-checkbox').forEach(function(cb) {
                    cb.checked = true;
                });
                syncCemeteryServicePlots();
            });
        }

        var userDeselectAllBtn = document.getElementById('userDeselectAllPlotsBtn');
        if (userDeselectAllBtn) {
            userDeselectAllBtn.addEventListener('click', function() {
                document.querySelectorAll('.user-plot-service-checkbox').forEach(function(cb) {
                    cb.checked = false;
                });
                syncCemeteryServicePlots();
            });
        }

        function syncDeceasedNameInputs(val) {
            var f = document.getElementById('decFirst');
            var m = document.getElementById('decMiddle');
            var l = document.getElementById('decLast');
            var s = document.getElementById('decSuffix');
            if (!val) {
                if (f) f.value = '';
                if (m) m.value = '';
                if (l) l.value = '';
                if (s) s.value = '';
                return;
            }
            var parts = val.trim().split(/\s+/);
            if (parts.length === 1) {
                if (f) f.value = parts[0];
                if (m) m.value = '';
                if (l) l.value = '';
                if (s) s.value = '';
            } else if (parts.length === 2) {
                if (f) f.value = parts[0];
                if (m) m.value = '';
                if (l) l.value = parts[1];
                if (s) s.value = '';
            } else {
                var suffixes = ['JR', 'JR.', 'SR', 'SR.', 'II', 'III', 'IV', 'V'];
                var lastPart = parts[parts.length - 1].toUpperCase();
                var suffix = '';
                if (suffixes.indexOf(lastPart) !== -1) {
                    suffix = parts.pop();
                }
                if (f) f.value = parts[0] || '';
                if (l) l.value = parts[parts.length - 1] || '';
                if (m) m.value = parts.slice(1, -1).join(' ') || '';
                if (s) s.value = suffix;
            }
        }
        window.syncDeceasedNameInputs = syncDeceasedNameInputs;

        function updatePurposeUI() {
            var purpose = bookingPurpose ? bookingPurpose.value : 'reserve_grave';
            var block = document.getElementById('deceasedFieldsBlock');
            var deceasedBlock = document.getElementById('bookingDeceasedBlock');
            var deceasedHint = document.getElementById('bookingDeceasedHint');
            var svcBlock = document.getElementById('cemeteryServiceFields');
            var hint = document.getElementById('purposeHint');
            var titleSpan = document.getElementById('bookingModalTitleText');
            var choosePlotBtn = document.getElementById('chooseGravePlotBtn');
            var lockedPlotNotice = document.getElementById('lockedPlotNotice');
            var eligBlock = document.getElementById('addDeceasedEligibilityBlock');
            var newDecBlock = document.getElementById('newDeceasedInfoBlock');
            var userOwnedBlock = document.getElementById('userOwnedPlotsDropdownBlock');
            var userOwnedMultiBlock = document.getElementById('userOwnedPlotsMultiBlock');
            var submitBtn = document.querySelector('#bookingForm button[type="submit"]') || document.getElementById('userSubmitBookingBtn');

            var isAddDec = purpose === 'add_deceased';
            var isCemService = purpose === 'cemetery_service';
            var isReserve = purpose === 'reserve_grave';

            var userMultiPlotsCard = document.getElementById('userMultiPlotsCardBlock');
            var selectedPlotCard = document.getElementById('selectedPlotCardBlock');

            if (isAddDec) {
                if (titleSpan) titleSpan.textContent = 'Book Additional Deceased';
                if (choosePlotBtn) choosePlotBtn.style.display = 'none';
                if (userMultiPlotsCard) userMultiPlotsCard.style.display = 'none';
                if (selectedPlotCard) selectedPlotCard.style.display = 'block';
                if (lockedPlotNotice) lockedPlotNotice.style.display = 'block';
                if (eligBlock) eligBlock.style.display = 'block';
                if (newDecBlock) newDecBlock.style.display = 'block';
                if (userOwnedBlock) userOwnedBlock.style.display = 'block';
                if (userOwnedMultiBlock) userOwnedMultiBlock.style.display = 'none';
                if (deceasedBlock) deceasedBlock.style.display = 'none';
                if (svcBlock) svcBlock.style.display = 'none';
                if (hint) hint.textContent = 'Add another deceased to an occupied grave. Minimum 8-year interval required. Interment fee applies.';

                var curSec = sectionField ? sectionField.value : '';
                var curPx = plotXField ? plotXField.value : '';
                var curPy = plotYField ? plotYField.value : '';
                if (curSec && curPx && curPy) {
                    window.fetchCheckIntermentInterval(curSec, curPx, curPy);
                }
            } else if (isReserve) {
                if (titleSpan) titleSpan.textContent = 'Book Reservation';
                if (choosePlotBtn) choosePlotBtn.style.display = '';
                if (userMultiPlotsCard) userMultiPlotsCard.style.display = 'block';
                if (selectedPlotCard) selectedPlotCard.style.display = 'none';
                if (lockedPlotNotice) lockedPlotNotice.style.display = 'none';
                if (eligBlock) eligBlock.style.display = 'none';
                if (newDecBlock) newDecBlock.style.display = 'none';
                if (userOwnedBlock) userOwnedBlock.style.display = 'none';
                if (userOwnedMultiBlock) userOwnedMultiBlock.style.display = 'none';
                if (deceasedBlock) {
                    deceasedBlock.style.display = 'none';
                    var dSel = document.getElementById('bookingDeceased');
                    if (dSel) dSel.value = '';
                }
                if (svcBlock) svcBlock.style.display = 'none';
                if (hint) hint.textContent = 'Reserve new grave plot(s). Grave/plot price will be charged.';
                var bTypeEl = document.getElementById('bookingType');
                if (bTypeEl) bTypeEl.value = 'another_plot';
                window.isCurrentPlotEligible = true;
                if (submitBtn) submitBtn.disabled = false;
            } else {
                if (titleSpan) titleSpan.textContent = 'Reserve Cemetery Service';
                var bTypeEl = document.getElementById('bookingType');
                if (bTypeEl) bTypeEl.value = 'existing_plot_service';
                if (choosePlotBtn) choosePlotBtn.style.display = 'none';
                if (userMultiPlotsCard) userMultiPlotsCard.style.display = 'none';
                if (lockedPlotNotice) {
                    lockedPlotNotice.style.display = 'block';
                    var noticeSpan = lockedPlotNotice.querySelector('span');
                    if (noticeSpan) {
                        noticeSpan.innerHTML = '<strong>Registered Grave Plot:</strong> Cemetery services can only be reserved for your registered plot(s). Plot selection from map is disabled.';
                    }
                }
                if (eligBlock) eligBlock.style.display = 'none';
                if (newDecBlock) newDecBlock.style.display = 'none';

                // For Cemetery Service, if user has linked plots, show multi-selection checkboxes
                if (userOwnedBlock) userOwnedBlock.style.display = 'none';
                var hasMultiPlots = userOwnedMultiBlock && userOwnedMultiBlock.querySelectorAll('.user-plot-service-checkbox').length > 0;
                if (hasMultiPlots) {
                    userOwnedMultiBlock.style.display = 'block';
                    if (selectedPlotCard) selectedPlotCard.style.display = 'none';
                    if (deceasedBlock) deceasedBlock.style.display = 'none';
                    syncCemeteryServicePlots();
                } else {
                    if (userOwnedMultiBlock) userOwnedMultiBlock.style.display = 'none';
                    if (selectedPlotCard) selectedPlotCard.style.display = 'block';
                    if (deceasedBlock) deceasedBlock.style.display = 'block';
                }

                if (svcBlock) svcBlock.style.display = 'block';
                if (hint) hint.textContent = 'Request a cemetery service for your plot. Applicable service fee will be charged.';
                window.isCurrentPlotEligible = true;
                if (submitBtn) submitBtn.disabled = false;
            }

            // Installment plan & installment term are ONLY for Reserve Grave (plot purchases).
            // For Cemetery Service, remove installment option and installment term entirely.
            var pPlanSelect = document.getElementById('paymentPlan');
            var instOpt = pPlanSelect ? (document.getElementById('paymentPlanOptInstallment') || pPlanSelect.querySelector('option[value="installment"]')) : null;
            var instOptionsWrap = document.getElementById('installmentOptions');
            var pTermSelect = document.getElementById('paymentTerm');

            if (isCemService) {
                if (instOpt) instOpt.style.display = 'none';
                if (pPlanSelect) pPlanSelect.value = 'full';
                if (instOptionsWrap) instOptionsWrap.style.display = 'none';
                if (pTermSelect) {
                    pTermSelect.value = '';
                    pTermSelect.required = false;
                }
            } else {
                if (instOpt) instOpt.style.display = '';
            }

            ['serviceType','preferredDate','contactNumber','bookerLocation'].forEach(function(id){
                var f = document.getElementById(id);
                if (f) f.required = isCemService;
            });

            updatePriceSummary();
        }

        if (bookingPurpose) bookingPurpose.addEventListener('change', updatePurposeUI);
        var bModalInit = document.getElementById('bookingModal');
        if (bModalInit) {
            bModalInit.addEventListener('show.bs.modal', updatePurposeUI);
        }
        updatePurposeUI();

        var paymentPlanSelect = document.getElementById('paymentPlan');
        var installmentWrap = document.getElementById('installmentOptions');
        var paymentTermSelect = document.getElementById('paymentTerm');
        function syncPaymentPlan() {
            var purpose = bookingPurpose ? bookingPurpose.value : 'reserve_grave';
            var isCemService = (purpose === 'cemetery_service');
            if (isCemService && paymentPlanSelect && paymentPlanSelect.value === 'installment') {
                paymentPlanSelect.value = 'full';
            }
            var isInstallment = (!isCemService && paymentPlanSelect && paymentPlanSelect.value === 'installment');
            if (installmentWrap) installmentWrap.style.display = isInstallment ? '' : 'none';
            if (paymentTermSelect) paymentTermSelect.required = !!isInstallment;
            updatePriceSummary();
        }
        if (paymentPlanSelect) paymentPlanSelect.addEventListener('change', syncPaymentPlan);
        if (paymentTermSelect) paymentTermSelect.addEventListener('change', updatePriceSummary);
        syncPaymentPlan();

        function updatePriceSummary() {
            var purpose = bookingPurpose ? bookingPurpose.value : 'reserve_grave';
            var gravePrice = Number(document.getElementById('plot_price').value || 0);
            var iFee = Number(document.getElementById('interment_fee').value || 0);

            // Sum all checked cemetery services
            var sPrice = 0;
            var checkedSvcCount = 0;
            document.querySelectorAll('.cemetery-service-checkbox:checked').forEach(function(cb) {
                sPrice += Number(cb.dataset.price || 0);
                checkedSvcCount++;
            });
            if (checkedSvcCount === 0) {
                var serviceOpt = document.getElementById('serviceType');
                if (serviceOpt && serviceOpt.selectedOptions && serviceOpt.selectedOptions.length) {
                    sPrice = Number(serviceOpt.selectedOptions[0].dataset.price || 0);
                }
            }

            var g = 0, i = 0, s = 0;
            var numSelectedPlots = 1;
            if (purpose === 'reserve_grave') {
                if (window.__userSelectedPlotsList && window.__userSelectedPlotsList.length > 0) {
                    g = 0;
                    window.__userSelectedPlotsList.forEach(function(p) {
                        g += Number(p.price || (p.lawn_type === 'Premium' ? 89000 : 85000));
                    });
                    numSelectedPlots = window.__userSelectedPlotsList.length;
                } else {
                    g = gravePrice;
                    numSelectedPlots = 1;
                }
                i = 0;
                s = sPrice;
            } else if (purpose === 'add_deceased') {
                g = 0; // Grave/plot price is ₱0.00 for adding deceased
                i = iFee > 0 ? iFee : 38000;
                s = sPrice;
            } else {
                g = 0;
                i = 0;
                var checkedPlots = document.querySelectorAll('.user-plot-service-checkbox:checked');
                var allPlotCbs = document.querySelectorAll('.user-plot-service-checkbox');
                if (allPlotCbs.length > 0) {
                    numSelectedPlots = checkedPlots.length;
                }
                s = sPrice * numSelectedPlots;
            }

            document.getElementById('interment_fee').value = i;
            document.getElementById('service_price') && (document.getElementById('service_price').value = s);

            var fmt = function(n) { return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
            var el = function(id) { return document.getElementById(id); };
            if (purpose === 'reserve_grave' && numSelectedPlots > 1) {
                el('sumGravePrice') && (el('sumGravePrice').innerHTML = fmt(g) + ' <span class="text-muted fw-normal">(' + numSelectedPlots + ' plots)</span>');
            } else {
                el('sumGravePrice') && (el('sumGravePrice').textContent = fmt(g));
            }
            el('sumIntermentFee') && (el('sumIntermentFee').textContent = fmt(i));
            if (purpose === 'cemetery_service' && numSelectedPlots > 1) {
                el('sumServiceFee') && (el('sumServiceFee').innerHTML = fmt(s) + ' <span class="text-muted fw-normal">(' + fmt(sPrice) + ' × ' + numSelectedPlots + ' plots)</span>');
            } else {
                el('sumServiceFee') && (el('sumServiceFee').textContent = fmt(s));
            }

            var pPlan = paymentPlanSelect ? paymentPlanSelect.value : 'full';
            var pTerm = paymentTermSelect ? Number(paymentTermSelect.value) : 0;
            var totalAmount = g + i + s;
            var isInstallmentPlan = (purpose === 'reserve_grave' && pPlan === 'installment');
            var downpaymentRequired = isInstallmentPlan ? Math.round(g * 0.20) : 0;
            el('sumDownpayment') && (el('sumDownpayment').textContent = fmt(downpaymentRequired));

            var downpaymentRow = document.getElementById('downpaymentRequiredRow');
            if (downpaymentRow) {
                downpaymentRow.style.setProperty('display', isInstallmentPlan ? 'flex' : 'none', 'important');
            }

            var gcashBadge = document.getElementById('userGcashPaymentBadge');
            if (gcashBadge) {
                gcashBadge.textContent = isInstallmentPlan ? '20% Downpayment Required' : 'Full Payment';
            }

            var breakdownEl = document.getElementById('userInstallmentBreakdown');
            if (pPlan === 'installment' && pTerm > 0 && purpose === 'reserve_grave') {
                var downpaymentAmt = Math.round(g * 0.20);
                var remBal = Math.max(0, g - downpaymentAmt);
                var monthly = pTerm > 0 ? Math.round(remBal / pTerm) : 0;
                var totalCost = g;
                var initPay = downpaymentAmt; // 20% downpayment required today

                if (breakdownEl) breakdownEl.style.display = 'block';
                var uMonthly = document.getElementById('userMonthlyFee');
                var uTotal = document.getElementById('userTotalPlanCost');
                var uInit = document.getElementById('userInitialPayment');
                var uBal = document.getElementById('userProjectedBalance');

                if (uMonthly) uMonthly.textContent = fmt(monthly) + ' / mo' + (numSelectedPlots > 1 ? ' (' + numSelectedPlots + ' plots)' : '');
                if (uTotal) uTotal.textContent = fmt(totalCost);
                if (uInit) uInit.textContent = fmt(initPay);
                if (uBal) uBal.textContent = fmt(remBal);

                el('sumTotalLabel') && (el('sumTotalLabel').textContent = 'Initial Payment Due Today (20% Downpayment):');
                el('sumTotal') && (el('sumTotal').textContent = fmt(initPay));
                var gcashTextEl = document.getElementById('userGcashPayAmountText');
                if (gcashTextEl) {
                    gcashTextEl.textContent = fmt(initPay) + ' (20% Downpayment)';
                }
            } else {
                if (breakdownEl) breakdownEl.style.display = 'none';
                el('sumTotalLabel') && (el('sumTotalLabel').textContent = 'Total Amount:');
                el('sumTotal') && (el('sumTotal').textContent = fmt(totalAmount));
                var gcashTextEl = document.getElementById('userGcashPayAmountText');
                if (gcashTextEl) {
                    gcashTextEl.textContent = fmt(totalAmount);
                }
            }
        }
        document.getElementById('serviceType') && document.getElementById('serviceType').addEventListener('change', updatePriceSummary);
        window.updatePurposeUI = updatePurposeUI;
        window.updatePriceSummary = updatePriceSummary;

        // Copy GCash number button handler with visual feedback
        document.querySelectorAll('.copy-gcash-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var num = btn.getAttribute('data-gcash') || '';
                if (!num) return;
                var cleanNum = num.replace(/\s+/g, '');
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(cleanNum).then(function() {
                        showCopyFeedback(btn);
                    }).catch(function() {
                        fallbackCopyText(cleanNum, btn);
                    });
                } else {
                    fallbackCopyText(cleanNum, btn);
                }
            });
        });

        function fallbackCopyText(text, btn) {
            var tempInput = document.createElement('input');
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            try {
                document.execCommand('copy');
                showCopyFeedback(btn);
            } catch (err) {
                console.error('Fallback copy failed', err);
            }
            document.body.removeChild(tempInput);
        }

        function showCopyFeedback(btn) {
            var origHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check me-1"></i> Copied!';
            btn.classList.add('btn-success', 'text-white');
            btn.classList.remove('btn-outline-secondary');
            setTimeout(function() {
                btn.innerHTML = origHtml;
                btn.classList.remove('btn-success', 'text-white');
                btn.classList.add('btn-outline-secondary');
            }, 2000);
        }

        // Toggle Details / Schedule Buttons chevron rotation & text update
        document.querySelectorAll('.toggle-details-btn').forEach(function(btn) {
            var targetId = btn.getAttribute('data-bs-target');
            if (!targetId) return;
            var targetEl = document.querySelector(targetId);
            if (!targetEl) return;
            targetEl.addEventListener('show.bs.collapse', function() {
                var txt = btn.querySelector('.toggle-text');
                if (txt) txt.innerHTML = 'Hide Details <i class="fas fa-chevron-up ms-1"></i>';
            });
            targetEl.addEventListener('hide.bs.collapse', function() {
                var txt = btn.querySelector('.toggle-text');
                if (txt) txt.innerHTML = 'View Details <i class="fas fa-chevron-down ms-1"></i>';
            });
        });

        document.querySelectorAll('.toggle-schedule-btn').forEach(function(btn) {
            var targetId = btn.getAttribute('data-bs-target');
            if (!targetId) return;
            var targetEl = document.querySelector(targetId);
            if (!targetEl) return;
            targetEl.addEventListener('show.bs.collapse', function() {
                var txt = btn.querySelector('.schedule-toggle-text');
                if (txt) txt.innerHTML = 'Hide Payment Schedule <i class="fas fa-chevron-up ms-1"></i>';
            });
            targetEl.addEventListener('hide.bs.collapse', function() {
                var txt = btn.querySelector('.schedule-toggle-text');
                if (txt) txt.innerHTML = 'View Payment Schedule <i class="fas fa-chevron-down ms-1"></i>';
            });
        });

        // Contact Number auto-prefix 09 handler
        var uContactInput = document.getElementById('contactNumber');
        if (uContactInput) {
            function sanitizeUserContact(val) {
                var digits = String(val || '').replace(/\D/g, '');
                if (!digits) return '';
                if (digits.startsWith('639')) digits = '09' + digits.substring(3);
                else if (digits.startsWith('9')) digits = '09' + digits.substring(1);
                else if (digits.startsWith('0') && digits.length >= 2 && digits.charAt(1) !== '9') digits = '09' + digits.substring(2);
                else if (!digits.startsWith('09')) digits = '09' + digits;
                return digits.slice(0, 11);
            }

            uContactInput.addEventListener('focus', function() {
                if (!this.value || this.value.trim() === '') this.value = '09';
                else this.value = sanitizeUserContact(this.value);
            });

            uContactInput.addEventListener('input', function() {
                var digits = this.value.replace(/\D/g, '');
                if (!digits) { this.value = '09'; return; }
                this.value = sanitizeUserContact(this.value);
            });

            uContactInput.addEventListener('keydown', function(e) {
                if ((e.key === 'Backspace' || e.key === 'Delete') && (this.selectionStart <= 2 && this.selectionEnd <= 2)) {
                    e.preventDefault();
                    this.setSelectionRange(2, 2);
                }
            });

            uContactInput.addEventListener('blur', function() {
                if (this.value === '09' && !this.hasAttribute('required')) this.value = '';
                else if (this.value) this.value = sanitizeUserContact(this.value);
            });

            if (uContactInput.value) {
                uContactInput.value = sanitizeUserContact(uContactInput.value);
            }
        }
    });

    // Quick GCash QR Scan Modal
    function showGcashQrModal(bookingId, amountStr, accountName, gcashNumber, qrImage) {
        var modalEl = document.getElementById('userGcashQrModal');
        if (!modalEl) return;
        var bId = document.getElementById('userQrBookingId');
        if (bId) bId.textContent = '#' + bookingId;
        var amt = document.getElementById('userQrAmount');
        if (amt) amt.textContent = '₱' + (amountStr || '0.00');
        var acc = document.getElementById('userQrAccountName');
        if (acc) acc.textContent = accountName || 'Garden of Remembrance';
        var num = document.getElementById('userQrNumber');
        if (num) num.textContent = gcashNumber || '';
        var img = document.getElementById('userQrImg');
        if (img) img.src = qrImage || 'gcashqr1.jpg';
        var payL = document.getElementById('userQrPayLink');
        if (payL) {
            var cleanAmt = String(amountStr || '0').replace(/,/g, '');
            var userReturnBase = window.location.protocol + '//' + window.location.host + window.location.pathname;
            payL.href = 'gcash_gateway.php?booking_id=' + encodeURIComponent(bookingId) + '&amount=' + encodeURIComponent(cleanAmt) + '&source=user&return_url=' + encodeURIComponent(userReturnBase);
        }
        var dlL = document.getElementById('userQrDownloadLink');
        if (dlL) {
            dlL.href = qrImage || 'gcashqr1.jpg';
        }
        try {
            var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        } catch (e) {
            try {
                var modal = new bootstrap.Modal(modalEl);
                modal.show();
            } catch (e2) {
                modalEl.classList.add('show');
                modalEl.style.display = 'block';
            }
        }
    }

    function userOpenAddDeceasedModal(section, px, py) {
        document.getElementById('usr_dec_section').value = section;
        document.getElementById('usr_dec_plot_x').value = px;
        document.getElementById('usr_dec_plot_y').value = py;
        document.getElementById('usr_dec_plot_display').textContent = 'Section ' + section + ' Plot ' + px + '-' + py;

        document.getElementById('usr_dec_first_name').value = '';
        document.getElementById('usr_dec_middle_name').value = '';
        document.getElementById('usr_dec_last_name').value = '';
        document.getElementById('usr_dec_suffix').value = '';
        var usrDecOther = document.getElementById('usr_dec_suffix_other');
        if (usrDecOther) {
            usrDecOther.value = '';
            usrDecOther.style.display = 'none';
            usrDecOther.required = false;
        }
        document.getElementById('usr_dec_bday').value = '';
        document.getElementById('usr_dec_date_died').value = '';
        document.getElementById('usr_dec_relationship').value = '';

        var statusBox = document.getElementById('usr_dec_interval_status');
        var submitBtn = document.getElementById('usr_dec_submit_btn');
        if (statusBox) {
            statusBox.style.display = 'block';
            statusBox.className = 'alert alert-secondary py-2 px-3 small border-0 mb-3';
            statusBox.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Checking 8-year interval eligibility...';
        }
        if (submitBtn) submitBtn.disabled = true;

        fetch('api_booking.php?action=check_interment_interval&section=' + encodeURIComponent(section) + '&plot_x=' + encodeURIComponent(px) + '&plot_y=' + encodeURIComponent(py))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!statusBox || !submitBtn) return;
            if (!data.has_previous_interment) {
                statusBox.className = 'alert alert-success py-2 px-3 small border-0 mb-3';
                statusBox.innerHTML = '<i class="fas fa-check-circle me-1"></i> <strong>Plot Available for Initial Interment:</strong> No previous deceased records on this plot.';
                submitBtn.disabled = false;
            } else if (data.is_eligible) {
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

        var modalEl = document.getElementById('userAddDeceasedModal');
        if (modalEl) {
            var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        var addDecForm = document.getElementById('userAddDeceasedForm');
        if (addDecForm) {
            addDecForm.addEventListener('submit', function(e) {
                var bday = document.getElementById('usr_dec_bday') ? document.getElementById('usr_dec_bday').value : '';
                var dDied = document.getElementById('usr_dec_date_died') ? document.getElementById('usr_dec_date_died').value : '';
                var todayStr = new Date().toISOString().split('T')[0];
                var suffixVal = document.getElementById('usr_dec_suffix') ? document.getElementById('usr_dec_suffix').value : '';
                var suffixOtherVal = document.getElementById('usr_dec_suffix_other') ? document.getElementById('usr_dec_suffix_other').value.trim() : '';

                if (suffixVal === 'OTHER' && !suffixOtherVal) {
                    e.preventDefault();
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'warning', title: 'Suffix Required', text: 'Please specify the custom suffix for the deceased.', confirmButtonColor: '#7b1113' });
                    } else {
                        alert('Please specify the custom suffix for the deceased.');
                    }
                    var sO = document.getElementById('usr_dec_suffix_other');
                    if (sO) sO.focus();
                    return false;
                }
                if (bday && bday > todayStr) {
                    e.preventDefault();
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'warning', title: 'Invalid Birth Date', text: 'Date of birth cannot be in the future.', confirmButtonColor: '#7b1113' });
                    } else {
                        alert('Date of birth cannot be in the future.');
                    }
                    return false;
                }
                if (dDied && dDied > todayStr) {
                    e.preventDefault();
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'warning', title: 'Invalid Date of Death', text: 'Date of death cannot be in the future.', confirmButtonColor: '#7b1113' });
                    } else {
                        alert('Date of death cannot be in the future.');
                    }
                    return false;
                }
                if (bday && dDied && dDied < bday) {
                    e.preventDefault();
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'warning', title: 'Invalid Dates', text: 'Date of death cannot be earlier than date of birth.', confirmButtonColor: '#7b1113' });
                    } else {
                        alert('Date of death cannot be earlier than date of birth.');
                    }
                    return false;
                }
            });
        }
    });
    </script>

    <div class="modal fade" id="userGcashQrModal" tabindex="-1" aria-labelledby="userGcashQrModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
            <div class="modal-content" style="border-radius: 18px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 15px 30px rgba(0,0,0,0.1);">
                <div class="modal-header border-0 pb-0 pt-3 px-3">
                    <img src="assets/gcash-logo.png" alt="GCash" style="height: 22px; width: auto;">
                </div>
                <div class="modal-body text-center px-4 py-3">
                    <div class="text-muted small text-uppercase fw-semibold" style="letter-spacing: 0.05em; font-size: 0.75rem;">Total Amount to Pay</div>
                    <div id="userQrAmount" class="fw-bold fs-2 text-primary my-1" style="letter-spacing: -0.02em;">₱0.00</div>
                    <div class="small text-secondary mb-3">Booking <span id="userQrBookingId"></span></div>

                    <div class="p-3 my-2 rounded-3 bg-light border text-center">
                        <img src="assets/gcash-logo.png" alt="GCash" style="height: 42px; width: auto; object-fit: contain;" class="mb-2">
                        <div class="small text-muted fw-semibold">Official GCash Checkout</div>
                    </div>

                    <div class="p-2 rounded bg-light border text-start mb-2" style="font-size: 0.82rem;">
                        <div class="fw-semibold text-dark text-truncate" id="userQrAccountName">Garden of Remembrance</div>
                        <div class="text-primary font-monospace fw-bold" id="userQrNumber"></div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 pb-3 px-4 flex-column gap-2">
                    <a id="userQrPayLink" href="gcash_gateway.php" class="btn btn-primary w-100 py-2 fw-semibold" style="border-radius: 10px;">
                        <i class="fas fa-credit-card me-1"></i> Proceed to GCash Payment
                    </a>
                    <button type="button" class="btn btn-outline-secondary btn-sm w-100 py-1.5" data-bs-dismiss="modal" style="border-radius: 10px;">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="userAddDeceasedModal" tabindex="-1" aria-labelledby="userAddDeceasedModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content overflow-hidden border-0 shadow" style="border-radius: 1.25rem;">
                <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff;">
                    <h5 class="modal-title fw-bold m-0" id="userAddDeceasedModalLabel">
                        <i class="fas fa-user-plus me-2"></i> Add Deceased Record to Plot
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="userAddDeceasedForm" method="POST">
                    <input type="hidden" name="action" value="user_add_deceased">
                    <input type="hidden" name="section" id="usr_dec_section" value="">
                    <input type="hidden" name="plot_x" id="usr_dec_plot_x" value="">
                    <input type="hidden" name="plot_y" id="usr_dec_plot_y" value="">

                    <div class="modal-body p-4 bg-light">
                        <div class="alert alert-info py-2 px-3 small border-0 mb-3" style="background:#eef6ff; color:#1e429f;">
                            <strong>Your Plot:</strong> <span id="usr_dec_plot_display">Section A Plot 10-3</span><br>
                            <span class="small text-muted">Enter the details of the deceased loved one for this cemetery plot.</span>
                        </div>

                        <div id="usr_dec_interval_status" class="mb-3" style="display: none;"></div>

                        <div class="row g-2 mb-2">
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold text-dark mb-1">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" id="usr_dec_first_name" class="form-control form-control-sm" required oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold text-dark mb-1">Middle Name</label>
                                <input type="text" name="middle_name" id="usr_dec_middle_name" class="form-control form-control-sm" oninput="this.value = this.value.toUpperCase()">
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold text-dark mb-1">Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" id="usr_dec_last_name" class="form-control form-control-sm" required oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold text-dark mb-1">Suffix</label>
                                <select name="suffix" id="usr_dec_suffix" class="form-select form-select-sm">
                                    <option value="">No Suffix</option>
                                    <option value="JR.">JR.</option>
                                    <option value="SR.">SR.</option>
                                    <option value="I">I</option>
                                    <option value="II">II</option>
                                    <option value="III">III</option>
                                    <option value="IV">IV</option>
                                    <option value="V">V</option>
                                    <option value="VI">VI</option>
                                    <option value="OTHER">Other</option>
                                </select>
                                <input type="text" name="suffix_other" id="usr_dec_suffix_other" class="form-control form-control-sm mt-1" placeholder="Specify suffix (e.g. VII)" style="display:none;" oninput="this.value = this.value.toUpperCase()">
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold text-dark mb-1">Date of Birth</label>
                                <input type="date" name="bday" id="usr_dec_bday" class="form-control form-control-sm" max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold text-dark mb-1">Date of Death <span class="text-danger">*</span></label>
                                <input type="date" name="date_died" id="usr_dec_date_died" class="form-control form-control-sm" required max="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-bold text-dark mb-1">Relationship to You <span class="text-muted small fw-normal">(Optional)</span></label>
                            <select name="relationship" id="usr_dec_relationship" class="form-select form-select-sm">
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
                        <button type="submit" id="usr_dec_submit_btn" class="btn btn-sm btn-success">
                            <i class="fas fa-check-circle me-1"></i> Save Deceased Record
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php if (function_exists('render_notification_containers')) echo render_notification_containers(); ?>

<script>
// Global handler for all GCash payment links on User Dashboard
document.addEventListener('click', function(e) {
    var link = e.target.closest('a[href*="gcash_gateway.php"]');
    if (!link) return;
    if (typeof Swal === 'undefined') return;
    e.preventDefault();
    var href = link.href;

    var openModal = document.querySelector('.modal.show');
    if (openModal && typeof bootstrap !== 'undefined') {
        try {
            var mInst = bootstrap.Modal.getInstance(openModal);
            if (mInst) mInst.hide();
        } catch (eM) {}
    }

    Swal.fire({
        title: 'Redirecting to GCash...',
        html: '<div class="py-3 text-center">' +
                '<div class="mb-3">' +
                  '<div class="spinner-border text-primary" style="width: 3.5rem; height: 3.5rem; border-width: 4px; color: #007dfe !important;" role="status"></div>' +
                '</div>' +
                '<h5 class="fw-bold text-dark mb-1">GCash Payment Gateway</h5>' +
                '<p class="text-muted mb-3" style="font-size: 0.92rem;">Redirecting you to the secure GCash checkout page...</p>' +
                '<div class="progress" style="height: 6px; border-radius: 4px; overflow: hidden; background-color: #e9ecef;">' +
                  '<div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%; background-color: #007dfe;"></div>' +
                '</div>' +
              '</div>',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        timer: 1600
    });
    setTimeout(function() {
        window.location.href = href;
    }, 1200);
});

// --- user_pay_return: Return from GCash gateway for user booking ---
// When user clicks "Back to Booking & Upload Proof" from the GCash success screen,
// they are brought back to the UPLOAD PROOF MODAL (#uploadProofModal) with the
// booking pre-selected and the GCash reference pre-filled — ready to attach their receipt.
function openUserBookingSummaryModal(bookingId) {
    function populateAndShow(d) {
        var idEl = document.getElementById('ubsIdDisplay');
        if (idEl) idEl.textContent = d.id;
        var purpEl = document.getElementById('ubsPurpose');
        if (purpEl) purpEl.textContent = d.purpose;
        var locEl = document.getElementById('ubsLocation');
        if (locEl) locEl.textContent = d.location;
        var custEl = document.getElementById('ubsCustomer');
        if (custEl) custEl.textContent = d.customer;
        var dtEl = document.getElementById('ubsDate');
        if (dtEl) dtEl.textContent = d.date;
        var methEl = document.getElementById('ubsMethod');
        if (methEl) methEl.textContent = d.method || 'GCash';
        var refEl = document.getElementById('ubsReference');
        if (refEl) refEl.textContent = d.reference;
        var amtEl = document.getElementById('ubsAmountPaid');
        if (amtEl) amtEl.textContent = '₱' + d.amount_paid;
        
        var balRow = document.getElementById('ubsBalanceRow');
        if (balRow) {
            if (parseFloat(String(d.remaining_balance).replace(/,/g, '')) > 0) {
                balRow.style.display = 'flex';
                var balEl = document.getElementById('ubsBalance');
                if (balEl) balEl.textContent = '₱' + d.remaining_balance;
            } else {
                balRow.style.display = 'none';
            }
        }

        var badge = document.getElementById('ubsStatusBadge');
        var icon = document.getElementById('ubsStatusIcon');
        var iconWrap = document.getElementById('ubsStatusIconWrapper');
        var subtext = document.getElementById('ubsStatusSubtext');
        var st = (d.status || 'pending').toLowerCase();
        
        if (badge && icon && iconWrap && subtext) {
            if (st === 'confirmed' || st === 'approved') {
                badge.className = 'badge bg-success text-white px-3 py-1 rounded-pill fw-bold';
                badge.textContent = 'CONFIRMED / ACTIVE';
                icon.className = 'fas fa-check-circle fs-3 text-success';
                iconWrap.style.background = '#dcfce7';
                iconWrap.style.color = '#15803d';
                subtext.textContent = 'Your reservation is confirmed! Your cemetery plot is officially reserved.';
            } else if (st === 'cancelled') {
                badge.className = 'badge bg-danger text-white px-3 py-1 rounded-pill fw-bold';
                badge.textContent = 'CANCELLED';
                icon.className = 'fas fa-times-circle fs-3 text-danger';
                iconWrap.style.background = '#fee2e2';
                iconWrap.style.color = '#b91c1c';
                subtext.textContent = 'This reservation was cancelled.';
            } else {
                badge.className = 'badge bg-warning text-dark px-3 py-1 rounded-pill fw-bold';
                badge.textContent = 'PENDING VERIFICATION';
                icon.className = 'fas fa-clock fs-3 text-warning';
                iconWrap.style.background = '#fef3c7';
                iconWrap.style.color = '#d97706';
                subtext.textContent = 'Reservation received. Admin verification is currently pending.';
            }
        }

        var mapBtn = document.getElementById('ubsMapBtn');
        if (mapBtn) {
            if (d.map_url) {
                mapBtn.href = d.map_url;
                mapBtn.style.display = 'inline-block';
            } else {
                mapBtn.style.display = 'none';
            }
        }

        var modalEl = document.getElementById('userBookingSummaryModal');
        if (modalEl && typeof bootstrap !== 'undefined') {
            var m = bootstrap.Modal.getOrCreateInstance(modalEl);
            m.show();
        }
    }

    var data = (window.userBookingsData && window.userBookingsData[bookingId]) || null;
    if (!data && window.__returnBookingData && (String(window.__returnBookingData.id) === String(bookingId))) {
        var rbd = window.__returnBookingData;
        var rTotal = parseFloat(rbd.total_amount || 0);
        var rPaid = parseFloat(rbd.amount_paid || 0);
        var rBal = Math.max(0, rTotal - rPaid);
        data = {
            id: rbd.id,
            purpose: rbd.purpose === 'reserve_grave' ? 'Reserve New Grave' : (rbd.purpose || 'Cemetery Reservation'),
            section: rbd.section || 'A',
            plot: rbd.plot || '',
            location: 'Section ' + (rbd.section || 'A') + ' • Plot ' + (rbd.plot || ''),
            customer: rbd.customer_name || 'Customer',
            date: rbd.created_at || 'Just now',
            method: 'GCash',
            reference: rbd.payment_reference || ('BK-' + bookingId),
            amount_paid: Number(rPaid).toLocaleString('en-US', {minimumFractionDigits: 2}),
            total_amount: Number(rTotal).toLocaleString('en-US', {minimumFractionDigits: 2}),
            remaining_balance: Number(rBal).toLocaleString('en-US', {minimumFractionDigits: 2}),
            status: rbd.status || 'pending',
            map_url: 'cemetery_map.php?section=' + encodeURIComponent(rbd.section || 'A') + '&plot=' + encodeURIComponent(rbd.plot || '') + '&search_highlight=1&auto_open=1'
        };
    }
    
    if (data) {
        populateAndShow(data);
    } else {
        var fd = new FormData();
        fd.append('action', 'get_user_booking_details');
        fd.append('booking_id', bookingId);
        fetch('user_dashboard.php', { method: 'POST', body: fd })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                if (res && res.success && res.booking) {
                    var b = res.booking;
                    var lp = res.latest_payment || {};
                    var total = parseFloat(b.total_amount || 0);
                    var paid = parseFloat(lp.amount || b.amount_paid || 0);
                    var bal = Math.max(0, total - paid);
                    var fetchedData = {
                        id: b.id,
                        purpose: b.purpose === 'reserve_grave' ? 'Reserve New Grave' : (b.purpose || 'Cemetery Reservation'),
                        section: b.section || 'A',
                        plot: b.plot || '',
                        location: 'Section ' + (b.section || 'A') + ' • Plot ' + (b.plot || ''),
                        customer: b.customer_name || 'Customer',
                        date: b.created_at || 'Recently',
                        method: b.payment_method || 'GCash',
                        reference: lp.reference || b.payment_reference || ('BK-' + b.id),
                        amount_paid: Number(paid).toLocaleString('en-US', {minimumFractionDigits: 2}),
                        total_amount: Number(total).toLocaleString('en-US', {minimumFractionDigits: 2}),
                        remaining_balance: Number(bal).toLocaleString('en-US', {minimumFractionDigits: 2}),
                        status: b.status || 'pending',
                        map_url: 'cemetery_map.php?section=' + encodeURIComponent(b.section || 'A') + '&plot=' + encodeURIComponent(b.plot || '') + '&search_highlight=1&auto_open=1'
                    };
                    populateAndShow(fetchedData);
                } else {
                    var detailEl = document.getElementById('bookingDetails_' + bookingId);
                    if (detailEl && typeof bootstrap !== 'undefined') {
                        var collapse = bootstrap.Collapse.getOrCreateInstance(detailEl, { toggle: false });
                        collapse.show();
                        detailEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            })
            .catch(function(err) {
                console.warn('Could not fetch booking details:', err);
            });
    }
}
window.openUserBookingSummaryModal = openUserBookingSummaryModal;

(function() {
    function userEscapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
        });
    }

    window.__returnBookingData = <?php echo json_encode($returnBookingData); ?>;

    document.addEventListener('DOMContentLoaded', function() {
        var urlP = new URLSearchParams(window.location.search);
        var isFillupReturn = (urlP.get('booking_fillup_return') === '1');
        var isUserPayReturn = (urlP.get('user_pay_return') === '1');
        if (!isFillupReturn && !isUserPayReturn) return;

        var bkId      = urlP.get('booking_id')     || '';
        var payRef    = urlP.get('payment_ref')    || '';
        var payAmount = urlP.get('payment_amount') || '';
        var payStatus = urlP.get('payment_status') || '';
        if (!bkId) return;

        // Clean the URL so a refresh doesn't re-trigger
        try { history.replaceState({}, '', window.location.pathname); } catch(e) {}

        if (payStatus === 'cancelled') {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: '<span style="color:#856404;">Payment Cancelled</span>',
                    html:
                        '<div style="text-align:left;font-size:0.95rem;line-height:1.7;">' +
                        '<div class="alert alert-warning d-flex align-items-center py-2 px-3 mb-3 border-0" style="background:#fff3cd;border-radius:8px;">' +
                            '<i class="fas fa-exclamation-triangle me-2 fs-5 text-warning"></i>' +
                            '<div>GCash payment was cancelled for <strong>Booking #' + userEscapeHtml(bkId) + '</strong>.</div>' +
                        '</div>' +
                        '<div class="p-3 bg-light rounded border text-muted small">' +
                            'You can continue your booking or settle payment anytime from your dashboard.' +
                        '</div>' +
                        '</div>',
                    confirmButtonColor: '#7b1113',
                    confirmButtonText: 'OK'
                });
            }
            return;
        }

        if (!isFillupReturn && isUserPayReturn) {
            // User paid from dashboard or gateway return
            try {
                sessionStorage.removeItem('gor_user_booking_draft');
                if (bkId) localStorage.removeItem('gor_user_booking_draft_' + bkId);
                localStorage.removeItem('gor_user_booking_draft');
            } catch(e) {}

            var isMonthlyPayment = (urlP.get('payment_type') === 'installment') 
                || (urlP.get('is_monthly') === '1') 
                || (Number(urlP.get('installment') || 0) > 0)
                || (window.__returnBookingData && window.__returnBookingData.is_monthly_payment);

            var instNum = urlP.get('installment') || (window.__returnBookingData ? window.__returnBookingData.installment_number : '') || '';

            if (isMonthlyPayment) {
                var instText = instNum ? ('Payment #' + userEscapeHtml(instNum)) : 'Monthly Payment';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: '<span style="color:#166534;font-size:1.35rem;font-weight:800;">🎉 Thank You for Your Payment!</span>',
                        html:
                            '<div style="text-align:center;font-size:0.95rem;line-height:1.6;color:#374151;">' +
                            '<div class="badge bg-success text-white px-3 py-1 rounded-pill mb-3 fw-bold" style="font-size:0.85rem;">' + instText + ' Submitted</div>' +
                            '<p class="mb-3 text-muted">Your payment proof for <strong>Booking #' + userEscapeHtml(bkId) + '</strong> (' + instText + ') has been received.</p>' +
                            '<div class="alert alert-info d-flex align-items-center py-2 px-3 text-start border-0 rounded-3 mb-0" style="background:#eff6ff;color:#1e40af;font-size:0.85rem;">' +
                                '<i class="fas fa-bell me-2 fs-5 text-primary flex-shrink-0"></i>' +
                                '<div><strong>Notice:</strong> Your payment for the month will be processed and checked by the admin to ensure your payment is clear and legit. You will receive an email notification once approved.</div>' +
                            '</div>' +
                            '</div>',
                        confirmButtonColor: '#7b1113',
                        confirmButtonText: 'View Reservation Details',
                        allowOutsideClick: false
                    }).then(function() {
                        openUserBookingSummaryModal(bkId);
                    });
                } else {
                    openUserBookingSummaryModal(bkId);
                }
                return;
            }

            // Initial booking flow
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: '<span style="color:#166534;font-size:1.35rem;font-weight:800;">🎉 Thank You for Booking!</span>',
                    html:
                        '<div style="text-align:center;font-size:0.95rem;line-height:1.6;color:#374151;">' +
                        '<div class="badge bg-success text-white px-3 py-1 rounded-pill mb-3 fw-bold" style="font-size:0.85rem;">Booking Completed</div>' +
                        '<p class="mb-3 text-muted">Your booking reservation record and GCash payment proof have been received for <strong>Booking #' + userEscapeHtml(bkId) + '</strong>.</p>' +
                        '<div class="alert alert-info d-flex align-items-center py-2 px-3 text-start border-0 rounded-3 mb-0" style="background:#eff6ff;color:#1e40af;font-size:0.85rem;">' +
                            '<i class="fas fa-bell me-2 fs-5 text-primary flex-shrink-0"></i>' +
                            '<div><strong>Notification:</strong> The cemetery office is currently verifying your payment and reservation. You will receive an email notification once approved.</div>' +
                        '</div>' +
                        '</div>',
                    confirmButtonColor: '#7b1113',
                    confirmButtonText: 'View Reservation Details',
                    allowOutsideClick: false
                }).then(function() {
                    openUserBookingSummaryModal(bkId);
                });
            } else {
                openUserBookingSummaryModal(bkId);
            }
            return;
        }

        // Ensure booking draft storage is cleared
        try {
            sessionStorage.removeItem('gor_user_booking_draft');
            if (bkId) localStorage.removeItem('gor_user_booking_draft_' + bkId);
            localStorage.removeItem('gor_user_booking_draft');
        } catch(e) {}

        // Ensure bookingModal is NEVER opened
        var modalEl = document.getElementById('bookingModal');
        if (modalEl && typeof bootstrap !== 'undefined') {
            var bModal = bootstrap.Modal.getInstance(modalEl);
            if (bModal) bModal.hide();
        }
        return;

        var draftIdInput = document.getElementById('bookingDraftId');
        if (!draftIdInput) {
            draftIdInput = document.createElement('input');
            draftIdInput.type = 'hidden';
            draftIdInput.name = 'booking_id';
            draftIdInput.id = 'bookingDraftId';
            var formEl = document.getElementById('bookingForm');
            if (formEl) formEl.appendChild(draftIdInput);
        }
        draftIdInput.value = bkId;

        // Ensure Payment Method is set to GCash and show card
        var gcashRadio = document.getElementById('paymentMethodGcash');
        if (gcashRadio) {
            gcashRadio.checked = true;
            gcashRadio.dispatchEvent(new Event('change'));
        }
        var gcashCard = document.getElementById('userGcashPaymentCard');
        if (gcashCard) gcashCard.style.display = 'block';

        var proofRefInput = document.getElementById('bookingProofRef');
        if (proofRefInput && payRef) {
            proofRefInput.value = payRef;
        }

        function setBookingFieldValue(nameOrId, val) {
            if (val === undefined || val === null || val === '') return;
            var el = document.getElementById(nameOrId) || document.querySelector('#bookingForm [name="' + nameOrId + '"]');
            if (!el) return;
            if (el.type === 'checkbox') {
                el.checked = Boolean(val);
            } else if (el.type !== 'file') {
                el.value = val;
            }
        }

        var draft = null;
        if (rawDraft) {
            try { draft = JSON.parse(rawDraft); } catch(eD) {}
        }

        // Restore scalar inputs from draft
        if (draft) {
            for (var key in draft) {
                if (draft.hasOwnProperty(key) && key.indexOf('_') !== 0) {
                    setBookingFieldValue(key, draft[key]);
                }
            }
        }

        // Fallback / supplement from database if any fields are still blank
        if (dbData) {
            setBookingFieldValue('fullName', dbData.full_name || dbData.customer_name);
            setBookingFieldValue('full_name', dbData.full_name || dbData.customer_name);
            setBookingFieldValue('emailAddress', dbData.email || dbData.customer_email);
            setBookingFieldValue('email', dbData.email || dbData.customer_email);
            setBookingFieldValue('contactNumber', dbData.contact_number);
            setBookingFieldValue('contact_number', dbData.contact_number);
            setBookingFieldValue('preferredDate', dbData.preferred_date);
            setBookingFieldValue('preferred_date', dbData.preferred_date);
            setBookingFieldValue('bookerLocation', dbData.booker_location);
            setBookingFieldValue('booker_location', dbData.booker_location);
            setBookingFieldValue('specialRequests', dbData.special_requests);
            setBookingFieldValue('special_requests', dbData.special_requests);
            setBookingFieldValue('section', dbData.section);
            setBookingFieldValue('plot_price', dbData.plot_price);
            setBookingFieldValue('plot', dbData.plot);
        }

        // Determine and set Purpose
        var finalPurpose = (draft && draft._purpose) || (dbData && dbData.purpose) || 'reserve_grave';
        var pSelect = document.getElementById('bookingPurpose');
        if (pSelect) {
            pSelect.value = finalPurpose;
            if (typeof window.updatePurposeUI === 'function') {
                window.updatePurposeUI();
            } else {
                pSelect.dispatchEvent(new Event('change'));
            }
        }

        // Restore Payment Plan & Term
        var curPlan = (draft && draft.payment_plan) || (dbData && dbData.payment_plan) || 'full';
        setBookingFieldValue('paymentPlan', curPlan);
        var planEl = document.getElementById('paymentPlan');
        if (planEl) planEl.dispatchEvent(new Event('change'));

        var curTerm = (draft && draft.payment_term) || (dbData && dbData.payment_term) || '';
        if (curTerm) {
            setBookingFieldValue('paymentTerm', curTerm);
            var termEl = document.getElementById('paymentTerm');
            if (termEl) termEl.dispatchEvent(new Event('change'));
        }

        // Restore Cemetery Services if applicable
        var svcTypes = (draft && draft._service_types) || [];
        if (dbData && dbData.service_type) {
            var dbSvcs = String(dbData.service_type).split(/,\s*/);
            dbSvcs.forEach(function(s) { if (svcTypes.indexOf(s) === -1) svcTypes.push(s); });
        }
        if (svcTypes.length > 0) {
            setTimeout(function() {
                document.querySelectorAll('.cemetery-service-checkbox').forEach(function(cb) {
                    if (svcTypes.indexOf(cb.value) !== -1) cb.checked = true;
                });
                if (typeof updateCemeteryServicePrice === 'function') updateCemeteryServicePrice();
            }, 300);
        }

        // ── RESTORE SELECTED PLOTS (For Reserve New Grave & all bookings) ──
        var restoredPlots = [];

        // 1. Check draft
        if (draft && draft._selected_plots_json && draft._selected_plots_json !== '[]') {
            try {
                var dp = JSON.parse(draft._selected_plots_json);
                if (Array.isArray(dp) && dp.length > 0) {
                    restoredPlots = dp;
                }
            } catch(ePl) {}
        }

        // 2. Check dbData._booking_plots
        if (restoredPlots.length === 0 && dbData && Array.isArray(dbData._booking_plots) && dbData._booking_plots.length > 0) {
            restoredPlots = dbData._booking_plots.map(function(bp) {
                return {
                    plot_id: parseInt(bp.plot_id, 10) || 0,
                    section: (bp.section || '').toUpperCase().trim(),
                    plot_x: parseInt(bp.plot_x, 10) || 0,
                    plot_y: parseInt(bp.plot_y, 10) || 0,
                    plot_number: bp.plot_number || ((bp.plot_x && bp.plot_y) ? (bp.plot_x + '-' + bp.plot_y) : (bp.plot || '-')),
                    lawn_type: bp.lawn_type || 'Regular',
                    price: parseFloat(bp.price || bp.price_at_booking || bp.plot_price || 85000)
                };
            });
        }

        // 3. Fallback: Synthesize from single plot in dbData
        if (restoredPlots.length === 0 && dbData && (dbData.plot || dbData.section)) {
            var rawPlotStr = String(dbData.plot || '').trim();
            var secStr = String(dbData.section || 'A').toUpperCase().trim();
            var px = 0, py = 0;
            var m = rawPlotStr.match(/(\d+)[\s\-_,]+(\d+)/);
            if (m) { px = parseInt(m[1], 10); py = parseInt(m[2], 10); }
            restoredPlots = [{
                plot_id: 0,
                section: secStr,
                plot_x: px,
                plot_y: py,
                plot_number: rawPlotStr || (px + '-' + py),
                lawn_type: dbData.plot_type || 'Regular',
                price: parseFloat(dbData.plot_price || dbData.total_amount || 85000)
            }];
        }

        if (restoredPlots.length > 0) {
            window.__userSelectedPlotsList = restoredPlots;
            window.userSelectedPlots = restoredPlots;
            var spInput = document.getElementById('selected_plots_json');
            if (spInput) spInput.value = JSON.stringify(restoredPlots);
            if (typeof window.renderUserSelectedPlotsTable === 'function') {
                window.renderUserSelectedPlotsTable();
            }
        }

        // Pre-fill amount display in GCash card
        var gcashAmtText = document.getElementById('userGcashPayAmountText');
        if (gcashAmtText && (payAmount || (dbData && dbData.total_amount))) {
            var finalAmt = payAmount || dbData.total_amount;
            gcashAmtText.textContent = '₱' + Number(finalAmt).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // Show a helpful green alert directly above the proof input in the booking form
        setTimeout(function() {
            var gcashCardEl = document.getElementById('userGcashPaymentCard');
            if (gcashCardEl) {
                var existingBanner = document.getElementById('userBookingGcashPaidBanner');
                if (!existingBanner) {
                    existingBanner = document.createElement('div');
                    existingBanner.id = 'userBookingGcashPaidBanner';
                }
                existingBanner.className = 'alert alert-success d-flex align-items-start gap-2 mb-3 p-3 border-0 shadow-sm';
                existingBanner.style.cssText = 'background:#ecfdf5;color:#065f46;border:1.5px solid #a7f3d0 !important;border-radius:12px;';
                existingBanner.innerHTML = 
                    '<i class="fas fa-check-circle fs-4 text-success flex-shrink-0 mt-1"></i>' +
                    '<div>' +
                        '<div class="fw-bold text-success fs-6">GCash Payment Completed! (Ref: ' + userEscapeHtml(payRef || 'Submitted') + ')</div>' +
                        '<div class="small mt-1 text-secondary">Your reservation details for Reservation #' + userEscapeHtml(bkId) + ' are saved. Please attach your payment receipt screenshot below and click <strong>Submit Reservation</strong> to finalize.</div>' +
                    '</div>';
                gcashCardEl.insertBefore(existingBanner, gcashCardEl.firstChild);
            }

            var proofInput = document.getElementById('bookingProofFile');
            if (proofInput) {
                proofInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                proofInput.focus();
                proofInput.classList.add('border-success', 'shadow-sm');
            }

            var subBtn = document.getElementById('userSubmitBookingBtn');
            if (subBtn) {
                subBtn.classList.add('btn-success');
                subBtn.classList.remove('btn-primary');
                subBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Submit Reservation & Proof';
            }
        }, 300);
    });
})();
</script>
<script src="includes/session_tab_guard.js"></script>
<script>
// Tab-aware session guard — this tab is the user dashboard
if (window.GorSessionGuard) {
    GorSessionGuard.init('user_dashboard');
}
</script>
<script src="assets/js/ph_address_selector.js"></script>
</body>
</html>

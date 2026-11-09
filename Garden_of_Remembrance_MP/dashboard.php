<?php
if (!empty($_GET['debug']) || isset($_GET['debug'])) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

register_shutdown_function(function () {
    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($error && in_array($error['type'], $fatalTypes, true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<div style="background:#fef2f2;color:#991b1b;padding:25px;margin:20px auto;border-radius:12px;font-family:system-ui,sans-serif;border:2px solid #f87171;max-width:850px;box-shadow:0 10px 25px rgba(0,0,0,0.1);">';
        echo '<h3 style="margin-top:0;color:#b91c1c;">Dashboard Error Encountered</h3>';
        echo '<p style="font-size:15px;margin-bottom:8px;"><strong>Error:</strong> ' . htmlspecialchars($error['message']) . '</p>';
        echo '<p style="font-size:13px;color:#6b7280;margin-top:0;"><strong>File:</strong> ' . htmlspecialchars($error['file']) . ' on line ' . $error['line'] . '</p>';
        if (strpos($error['message'], 'require') !== false || strpos($error['message'], 'include') !== false) {
            echo '<div style="margin-top:15px;padding:12px;background:#fff;border-radius:8px;border:1px solid #fca5a5;color:#1f2937;font-size:13px;">';
            echo '<strong>Fix:</strong> A required file or subfolder is missing on your live server. Please make sure the <code>config/</code> and <code>includes/</code> folders are completely uploaded to <code>/htdocs/</code> in FileZilla.';
            echo '</div>';
        }
        echo '</div>';
    }
});

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$adminInactivityTimeout = 300; // 5 minutes (300 seconds)
if (!empty($_SESSION['user_id']) && strtoupper(trim((string)($_SESSION['role'] ?? ''))) === 'ADMIN') {
    if (isset($_SESSION['admin_last_activity']) && (time() - (int)$_SESSION['admin_last_activity'] > $adminInactivityTimeout)) {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain']);
        }
        @session_destroy();
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'session_expired' => true, 'message' => 'Session expired due to inactivity. Please log in again.']);
            exit;
        }
        header("Location: admin_login.php?session_expired=1");
        exit;
    }
    $_SESSION['admin_last_activity'] = time();
}

if (file_exists(__DIR__ . '/config/payment_settings.php')) {
    require_once __DIR__ . '/config/payment_settings.php';
}
if (file_exists(__DIR__ . '/config/transfer_policy.php')) {
    require_once __DIR__ . '/config/transfer_policy.php';
}
if (file_exists(__DIR__ . '/connection.php')) {
    require_once __DIR__ . '/connection.php';
}
if (file_exists(__DIR__ . '/config/payment_helper.php')) {
    require_once __DIR__ . '/config/payment_helper.php';
}
if (file_exists(__DIR__ . '/config/pricing_policy.php')) {
    require_once __DIR__ . '/config/pricing_policy.php';
}
if (file_exists(__DIR__ . '/config/installment_helper.php')) {
    require_once __DIR__ . '/config/installment_helper.php';
}


try {
    if (function_exists('is_db_connected') && is_db_connected() && function_exists('ensure_remains_transfer_tables')) {
        ensure_remains_transfer_tables($conn);
    }
} catch (Throwable $tEx) {
    error_log('Notice: Remains transfer tables check: ' . $tEx->getMessage());
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$adminUid = (int)($_SESSION['admin_user_id'] ?? 0);
$sessionUid = $adminUid > 0 ? $adminUid : (int)($_SESSION['user_id'] ?? 0);
$adminRole = isset($_SESSION['admin_role']) ? strtoupper(trim((string)$_SESSION['admin_role'])) : '';
$current_role = $adminRole === 'ADMIN' ? 'ADMIN' : (isset($_SESSION['role']) ? strtoupper(trim((string) $_SESSION['role'])) : '');

if ($sessionUid > 0 && $current_role !== 'ADMIN' && isset($conn) && function_exists('is_db_connected') && is_db_connected()) {
    $rCheck = $conn->prepare("SELECT role FROM tbl_user WHERE user_id = ? LIMIT 1");
    if ($rCheck) {
        $rCheck->bind_param('i', $sessionUid);
        $rCheck->execute();
        $rRes = $rCheck->get_result()->fetch_assoc();
        $rCheck->close();
        if ($rRes && strtoupper(trim((string)$rRes['role'])) === 'ADMIN') {
            $_SESSION['role'] = 'ADMIN';
            $current_role = 'ADMIN';
        }
    }
}

if ($sessionUid <= 0) {
    if ($isPost) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'session_expired' => true, 'message' => 'Session expired. Please log in again.']);
        exit;
    }
    header("Location: admin_login.php?auth_required=1&next=dashboard.php");
    exit;
}

if ($current_role !== 'ADMIN') {
    if ($isPost) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'session_expired' => true, 'message' => 'Administrator access required (signed in as customer in another tab).']);
        exit;
    }
    header('Location: admin_login.php?auth_required=1&next=dashboard.php');
    exit;
}

if (!function_exists('is_db_connected') || !is_db_connected()) {
    http_response_code(503);
    exit("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>Administrator Portal Temporarily Unavailable</h2><p>Database connection is momentarily offline. Please refresh in a moment.</p></div>");
}

// Server-side 3-month non-payment check (runs on admin page loads; cron covers scheduling).
// Cancels installment bookings with 3+ consecutive unpaid past-due installments.
// Payments are preserved; NO REFUND. Idempotent.
if (!$isPost) {
    try {
        if (file_exists(__DIR__ . '/config/cancellation_helper.php')) {
            require_once __DIR__ . '/config/cancellation_helper.php';
        }
        if (function_exists('auto_cancel_overdue_bookings')) {
            auto_cancel_overdue_bookings($conn, 'SYSTEM/AUTOMATIC');
        }
    } catch (Throwable $acEx) {
        error_log('Notice: auto-cancel check: ' . $acEx->getMessage());
    }
}

$user_id = (int)$_SESSION['user_id'];
$user = null;

try {
    $uStmt = $conn->prepare("SELECT first_name, last_name, email, profile_picture FROM tbl_user WHERE user_id = ? LIMIT 1");
    if ($uStmt) {
        $uStmt->bind_param('i', $user_id);
        $uStmt->execute();
        $uRes = $uStmt->get_result();
        $user = $uRes ? $uRes->fetch_assoc() : null;
        $uStmt->close();
    }
} catch (Throwable $uEx) {
    error_log('Admin user fetch error: ' . $uEx->getMessage());
}

$profile_image = 'uploads/profile_pictures/default_avatar.png';
if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/' . $user['profile_picture'])) {
    $profile_image = $user['profile_picture'];
}

$sales_month = isset($_GET['sales_month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['sales_month']) ? $_GET['sales_month'] : date('Y-m');
$sales_month_start = $sales_month . '-01';
$sales_month_end = date('Y-m-t', strtotime($sales_month_start));
$sales_month_label = date('F Y', strtotime($sales_month_start));

$monthly_sales_total = 0;
$paid_txn_count = 0;
$approved_booking_count = 0;
$pending_payment_count = 0;
$proof_submission_count = 0;
$monthly_transactions = [];

try {
    $paid_sql = "SELECT pr.booking_id, pr.user_id, pr.amount_paid, pr.payment_method, pr.payment_date, pr.payment_status, pr.proof_filename,
                    b.section, b.plot, b.status, b.total_amount,
                    COALESCE(NULLIF(TRIM(b.customer_name), ''), NULLIF(TRIM(b.full_name), ''), NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), 'Customer') AS customer_name
                 FROM payment_receipts pr
                 LEFT JOIN tbl_bookings b ON b.id = pr.booking_id
                 LEFT JOIN tbl_user u ON u.user_id = pr.user_id
                 WHERE pr.payment_date >= ? AND pr.payment_date < DATE_ADD(?, INTERVAL 1 MONTH)
                   AND LOWER(TRIM(COALESCE(pr.payment_status, ''))) != 'rejected'
                   AND LOWER(TRIM(COALESCE(b.status, ''))) NOT IN ('cancelled', 'rejected')
                 ORDER BY pr.payment_date DESC";

    $paid_stmt = $conn->prepare($paid_sql);
    if ($paid_stmt) {
        $paid_stmt->bind_param('ss', $sales_month_start, $sales_month_start);
        $paid_stmt->execute();
        $paid_result = $paid_stmt->get_result();
        while ($row = $paid_result->fetch_assoc()) {
            $amount = (float)($row['amount_paid'] ?? 0);
            $monthly_sales_total += $amount;
            $paid_txn_count++;
            $monthly_transactions[] = $row;
        }
        $paid_stmt->close();
    }

    if ($paid_txn_count === 0) {
        $fallback_sql = "SELECT pr.booking_id AS booking_id, pr.user_id, pr.amount_paid, pr.payment_method, pr.payment_date AS payment_date,
                                b.status, b.section, b.plot, b.total_amount,
                                COALESCE(NULLIF(TRIM(b.customer_name), ''), NULLIF(TRIM(b.full_name), ''), NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), 'Customer') AS customer_name,
                                pr.proof_filename AS proof_filename
                         FROM payment_receipts pr
                         LEFT JOIN tbl_bookings b ON b.id = pr.booking_id
                         LEFT JOIN tbl_user u ON u.user_id = pr.user_id
                         WHERE pr.amount_paid > 0 AND pr.payment_date IS NOT NULL
                           AND LOWER(TRIM(COALESCE(pr.payment_status, ''))) != 'rejected'
                           AND LOWER(TRIM(COALESCE(b.status, ''))) NOT IN ('cancelled', 'rejected')
                           AND DATE(pr.payment_date) >= ? AND DATE(pr.payment_date) <= ?";
        $fallback_stmt = $conn->prepare($fallback_sql);
        if ($fallback_stmt) {
            $fallback_stmt->bind_param('ss', $sales_month_start, $sales_month_end);
            $fallback_stmt->execute();
            $fallback_result = $fallback_stmt->get_result();
            while ($row = $fallback_result->fetch_assoc()) {
                $amount = (float)($row['amount_paid'] ?? 0);
                if ($amount > 0) {
                    $monthly_sales_total += $amount;
                    $paid_txn_count++;
                    $monthly_transactions[] = $row;
                }
            }
            $fallback_stmt->close();
        }
    }

    $approved_booking_count = 0;
    $approved_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tbl_bookings WHERE status IN ('approved','completed')");
    if ($approved_stmt) {
        $approved_stmt->execute();
        $approved_result = $approved_stmt->get_result();
        if ($row = $approved_result->fetch_assoc()) {
            $approved_booking_count = (int)$row['total'];
        }
        $approved_stmt->close();
    }

    $pending_payment_count = 0;
    $pending_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tbl_bookings WHERE amount_paid <= 0 AND status NOT IN ('rejected','cancelled')");
    if ($pending_stmt) {
        $pending_stmt->execute();
        $pending_result = $pending_stmt->get_result();
        if ($row = $pending_result->fetch_assoc()) {
            $pending_payment_count = (int)$row['total'];
        }
        $pending_stmt->close();
    }

    $proof_submission_count = 0;
    $proof_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM payment_receipts WHERE proof_filename IS NOT NULL AND TRIM(proof_filename) <> ''");
    if ($proof_stmt) {
        $proof_stmt->execute();
        $proof_result = $proof_stmt->get_result();
        if ($row = $proof_result->fetch_assoc()) {
            $proof_submission_count = (int)$row['total'];
        }
        $proof_stmt->close();
    }
} catch (Throwable $sEx) {
    error_log('Dashboard sales summary query error: ' . $sEx->getMessage());
}

$monthly_transaction_rows = $monthly_transactions;

$admin_bookings = [];
$admin_booking_counts = [
    'all' => 0,
    'pending' => 0,
    'approved' => 0,
    'completed' => 0,
    'rejected' => 0
];
try {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $chkSrc = @$conn->query("SHOW COLUMNS FROM `tbl_bookings` LIKE 'source'");
        if ($chkSrc && $chkSrc->num_rows === 0) {
            @$conn->query("ALTER TABLE `tbl_bookings` ADD COLUMN `source` VARCHAR(20) DEFAULT 'user'");
        }
        $ab_sql = "SELECT b.*, 
                          u.first_name AS u_first, u.last_name AS u_last, u.email AS u_email, u.phone_no AS u_phone,
                          pr.proof_filename AS pr_proof_file, pr.payment_date AS pr_payment_date, pr.receipt_number AS pr_receipt_no, pr.amount_paid AS pr_amt_paid
                   FROM tbl_bookings b
                   LEFT JOIN tbl_user u ON u.user_id = b.user_id
                   LEFT JOIN payment_receipts pr ON pr.booking_id = b.id
                   ORDER BY b.id DESC LIMIT 250";
        $ab_res = $conn->query($ab_sql);
        if ($ab_res) {
            while ($bRow = $ab_res->fetch_assoc()) {
                $bSt = strtolower(trim((string)($bRow['status'] ?? 'pending')));
                $admin_booking_counts['all']++;
                if (isset($admin_booking_counts[$bSt])) {
                    $admin_booking_counts[$bSt]++;
                }
                $admin_bookings[] = $bRow;
            }
        }
    }
} catch (Throwable $abEx) {
    error_log('Admin bookings query notice: ' . $abEx->getMessage());
}

if (!function_exists('getSectionBasePrice')) {
    function getSectionBasePrice($section) {
        global $conn;
        $sec = strtoupper(trim((string)$section));
        if (isset($conn) && $conn instanceof mysqli) {
            $stmt = $conn->prepare("SELECT base_price FROM sections WHERE UPPER(TRIM(section_name)) = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $sec);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $p = (float)($row['base_price'] ?? 0);
                    if ($p > 0) {
                        $stmt->close();
                        return $p;
                    }
                }
                $stmt->close();
            }
        }
        return 85000.00; 
    }
}

if (!function_exists('getPlotPrice')) {
    function getPlotPrice($section, $plot_x, $plot_y) {
        global $conn;
        $sec = strtoupper(trim((string)$section));
        $px = (int)$plot_x;
        $py = (int)$plot_y;

        if (isset($conn) && $conn instanceof mysqli) {
            $stmt = $conn->prepare("SELECT price FROM tbl_plots WHERE section = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("sii", $sec, $px, $py);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $p = (float)($row['price'] ?? 0);
                    if ($p > 0) {
                        $stmt->close();
                        return $p;
                    }
                }
                $stmt->close();
            }
        }

        if (file_exists(__DIR__ . '/config/pricing_policy.php')) {
            require_once __DIR__ . '/config/pricing_policy.php';
        }
        if (function_exists('get_plot_official_pricing')) {
            $official = get_plot_official_pricing($sec, $px, $py);
            return (float)($official['lot_price'] ?? 85000.00);
        }
        return 85000.00;
    }
}

if (!function_exists('getPlotType')) {
    function getPlotType($section, $plot_x, $plot_y) {
        $sec = strtoupper(trim((string)$section));
        $px = (int)$plot_x;
        $py = (int)$plot_y;
        if (file_exists(__DIR__ . '/config/pricing_policy.php')) {
            require_once __DIR__ . '/config/pricing_policy.php';
        }
        if (function_exists('is_corner_premium_plot') && is_corner_premium_plot($sec, $px, $py)) {
            return 'Premium';
        }
        return 'Regular';
    }
}

if (!function_exists('validatePreferredDate')) {
    function validatePreferredDate($preferred_date) {

        if (preg_match('/^\d{6}/', $preferred_date)) {
            return ['valid' => false, 'message' => '6-digit years are not allowed! Year must be exactly 4 digits (e.g., 2024).'];
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $preferred_date)) {
            return ['valid' => false, 'message' => 'Preferred date must be in YYYY-MM-DD format with exactly 4 digits for the year.'];
        }

        $date_parts = explode('-', $preferred_date);
        $year = (int)$date_parts[0];
        $month = (int)$date_parts[1];
        $day = (int)$date_parts[2];

        if (strlen($date_parts[0]) !== 4) {
            return ['valid' => false, 'message' => 'Year must be exactly 4 digits (e.g., 2024), found ' . strlen($date_parts[0]) . ' digits.'];
        }

        if ($date_parts[0] > 3000) {
            return ['valid' => false, 'message' => 'Year ' . $date_parts[0] . ' is invalid. Year must be between 1900 and 2100.'];
        }

        if ($year < 1900 || $year > 2100) {
            return ['valid' => false, 'message' => 'Year must be between 1900 and 2100.'];
        }

        if (!checkdate($month, $day, $year)) {
            return ['valid' => false, 'message' => 'Invalid date. Please enter a valid calendar date.'];
        }

        try {
            $preferredDateTime = new DateTime($preferred_date);
            $today = new DateTime();
            $today->setTime(0, 0, 0); 
            $preferredDateTime->setTime(0, 0, 0); 

            if ($preferredDateTime < $today) {

                $todayYear = (int)$today->format('Y');
                $todayMonth = (int)$today->format('n');
                $todayDay = (int)$today->format('j');

                if ($year < $todayYear) {
                    return ['valid' => false, 'message' => 'Year ' . $year . ' is in the past. Please select ' . $todayYear . ' or a future year.'];
                } elseif ($year == $todayYear && $month < $todayMonth) {
                    $monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                                  'July', 'August', 'September', 'October', 'November', 'December'];
                    return ['valid' => false, 'message' => $monthNames[$month - 1] . ' ' . $year . ' is in the past. Please select ' . $monthNames[$todayMonth - 1] . ' ' . $todayYear . ' or a future date.'];
                } elseif ($year == $todayYear && $month == $todayMonth && $day < $todayDay) {
                    return ['valid' => false, 'message' => 'Day ' . $day . ' of ' . $month . '/' . $year . ' is in the past. Please select today (' . $todayDay . '/' . $month . '/' . $year . ') or a future date.'];
                } else {
                    return ['valid' => false, 'message' => 'Preferred date cannot be in the past. Please select today or a future date.'];
                }
            }

            $maxFuture = new DateTime();
            $maxFuture->add(new DateInterval('P5Y')); 
            if ($preferredDateTime > $maxFuture) {
                return ['valid' => false, 'message' => 'Preferred date cannot be more than 5 years in the future.'];
            }

        } catch (Exception $e) {
            return ['valid' => false, 'message' => 'Invalid date format.'];
        }

        return ['valid' => true, 'message' => 'Date is valid.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_deceased_transfer') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtoupper(trim((string)$_SESSION['role'])) !== 'ADMIN') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Only authenticated administrators can record remains transfers.']);
        exit;
    }

    $deceased_id = intval($_POST['deceased_id'] ?? 0);
    $transfer_date = trim($_POST['transfer_date'] ?? date('Y-m-d'));
    $destination_type = trim($_POST['destination_type'] ?? 'NEW_PLOT');
    $destination_section = strtoupper(trim($_POST['destination_section'] ?? ''));
    $destination_plot_x = intval($_POST['destination_plot_x'] ?? 0);
    $destination_plot_y = intval($_POST['destination_plot_y'] ?? 0);
    $destination_details = trim($_POST['destination_details'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if ($deceased_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid deceased record.']);
        exit;
    }

    $dStmt = $conn->prepare("SELECT * FROM tbl_deceased WHERE deceased_id = ? LIMIT 1");
    if (!$dStmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    $dStmt->bind_param('i', $deceased_id);
    $dStmt->execute();
    $dec = $dStmt->get_result()->fetch_assoc();
    $dStmt->close();

    if (!$dec) {
        echo json_encode(['success' => false, 'message' => 'Deceased record not found.']);
        exit;
    }

    $interment_date = !empty($dec['interment_date']) ? $dec['interment_date'] : $dec['date_died'];
    $eligibility = calculate_remains_transfer_eligibility($interment_date, false, $transfer_date);

    if (!$eligibility['can_transfer']) {
        echo json_encode(['success' => false, 'message' => $eligibility['message']]);
        exit;
    }

    if ($destination_type === 'NEW_PLOT') {
        if (empty($destination_section) || $destination_plot_x <= 0 || $destination_plot_y <= 0) {
            echo json_encode(['success' => false, 'message' => 'Please select a destination plot from the cemetery map.']);
            exit;
        }

        $chkDest = $conn->prepare("SELECT status FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?");
        $chkDest->bind_param('sii', $destination_section, $destination_plot_x, $destination_plot_y);
        $chkDest->execute();
        $destPlot = $chkDest->get_result()->fetch_assoc();
        $chkDest->close();

        $destStatus = strtolower(trim((string)($destPlot['status'] ?? '')));
        $chkOcc = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$destination_section}' AND plot_x = {$destination_plot_x} AND plot_y = {$destination_plot_y}");
        $destOccupants = $chkOcc ? (int)($chkOcc->fetch_assoc()['c'] ?? 0) : 0;

        $isVacantAvailable = ($destStatus === 'available' && $destOccupants === 0);
        $ownerUserId = (int)($dec['user_id'] ?? 0);
        $isCustomerOwned = false;
        if ($ownerUserId > 0 && function_exists('is_user_authorized_for_plot')) {
            $isCustomerOwned = is_user_authorized_for_plot($conn, $ownerUserId, $destination_section, $destination_plot_x, $destination_plot_y, true);
        }

        if (!$isVacantAvailable && (!$isCustomerOwned || $destOccupants >= 4)) {
            if (!$isCustomerOwned) {
                echo json_encode([
                    'success' => false,
                    'message' => "The selected plot (Section {$destination_section} Plot {$destination_plot_x}-{$destination_plot_y}) is occupied or reserved and does not belong to this customer's account. Remains transfers can only be placed into available (vacant) plots or plots owned by the registered customer account."
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => "The selected destination plot (Section {$destination_section} Plot {$destination_plot_x}-{$destination_plot_y}) has reached maximum capacity (4 deceased). Please choose another plot."
                ]);
            }
            exit;
        }
    }

    $admin_id = (int)$_SESSION['user_id'];
    $admin_name = 'Administrator';
    $uQ = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = $admin_id");
    if ($uQ && $uRow = $uQ->fetch_assoc()) {
        $admin_name = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
    }

    $dec_fullname = trim(($dec['first_name'] ?? '') . ' ' . (!empty($dec['middle_name']) ? $dec['middle_name'] . ' ' : '') . ($dec['last_name'] ?? '') . (!empty($dec['suffix']) ? ' ' . $dec['suffix'] : ''));

    $conn->begin_transaction();
    try {
        $orig_sec = $dec['section'] ?? '';
        $orig_x = (int)($dec['plot_x'] ?? 0);
        $orig_y = (int)($dec['plot_y'] ?? 0);
        $years_elapsed = (float)($eligibility['years_elapsed'] ?? 0);

        $tIns = $conn->prepare("INSERT INTO tbl_deceased_transfers 
            (deceased_id, deceased_name, original_section, original_plot_x, original_plot_y, original_interment_date,
             destination_section, destination_plot_x, destination_plot_y, destination_type, destination_details,
             transfer_date, reason, eligibility_at_transfer, years_since_interment, recorded_by_id, recorded_by_name, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        if (!$tIns) {
            throw new Exception("Database prepare failed: " . $conn->error);
        }
        $tIns->bind_param('issiisiiisssssdis',
            $deceased_id, $dec_fullname, $orig_sec, $orig_x, $orig_y, $interment_date,
            $destination_section, $destination_plot_x, $destination_plot_y, $destination_type, $destination_details,
            $transfer_date, $reason, $eligibility['status'], $years_elapsed, $admin_id, $admin_name
        );
        if (!$tIns->execute()) {
            throw new Exception("Failed to insert transfer record: " . $tIns->error);
        }
        $transfer_record_id = $conn->insert_id;
        $tIns->close();

        if ($destination_type === 'NEW_PLOT' && !empty($destination_section) && $destination_plot_x > 0 && $destination_plot_y > 0) {
            $dest_sec = strtoupper(trim($destination_section));
            $dest_x = (int)$destination_plot_x;
            $dest_y = (int)$destination_plot_y;

            $targetUserId = (int)($dec['user_id'] ?? 0);
            if ($targetUserId <= 0 && !empty($orig_sec) && $orig_x > 0 && $orig_y > 0) {
                $uChk = $conn->query("SELECT user_id FROM user_plot_ownership WHERE UPPER(TRIM(section)) = UPPER(TRIM('{$orig_sec}')) AND plot_x = {$orig_x} AND plot_y = {$orig_y} AND (verification_status IS NULL OR verification_status != 'REJECTED') ORDER BY id DESC LIMIT 1");
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

            $upDec = $conn->prepare("UPDATE tbl_deceased SET section = ?, plot_x = ?, plot_y = ?, transfer_status = 'TRANSFERRED', edited_by = ?, user_id = COALESCE(NULLIF(user_id, 0), ?) WHERE deceased_id = ?");
            if ($upDec) {
                $upDec->bind_param('siisii', $destination_section, $destination_plot_x, $destination_plot_y, $admin_name, $targetUserId, $deceased_id);
                $upDec->execute();
                $upDec->close();
            }

            $conn->query("UPDATE tbl_plots SET status = 'occupied' WHERE UPPER(TRIM(section)) = UPPER(TRIM('{$destination_section}')) AND plot_x = {$destination_plot_x} AND plot_y = {$destination_plot_y}");
            @$conn->query("UPDATE plots SET status = 'Occupied' WHERE UPPER(TRIM(section)) = UPPER(TRIM('{$destination_section}')) AND plot_x = {$destination_plot_x} AND plot_y = {$destination_plot_y}");

            // Destination plot_id
            $destPlotId = null;
            $tpQ = $conn->query("SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = '{$dest_sec}' AND plot_x = {$dest_x} AND plot_y = {$dest_y} LIMIT 1");
            if ($tpQ && ($tpRow = $tpQ->fetch_assoc())) {
                $destPlotId = (int)$tpRow['plot_id'];
            }

            // Register destination plot in user_plot_ownership for target customer
            if ($targetUserId > 0) {
                if (function_exists('ensure_user_plot_ownership_table')) {
                    ensure_user_plot_ownership_table($conn);
                }
                $transferNotes = "Transferred remains ({$dec_fullname}) from Section {$orig_sec} Plot {$orig_x}-{$orig_y}";
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
                    $destUpo->bind_param('isiisssi', $targetUserId, $dest_sec, $dest_x, $dest_y, $destPlotId, $dec_fullname, $transferNotes, $admin_id);
                    $destUpo->execute();
                    $destUpo->close();
                }
            }
            $conn->query("UPDATE user_plot_ownership SET deceased_reference = '{$conn->real_escape_string($dec_fullname)}' WHERE UPPER(TRIM(section)) = '{$dest_sec}' AND plot_x = {$dest_x} AND plot_y = {$dest_y}");

            // Synchronize tbl_plot_owners for destination plot
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

                    $poExist = $conn->query("SELECT id FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = '{$dest_sec}' AND plot_x = {$dest_x} AND plot_y = {$dest_y} AND active = 1 LIMIT 1");
                    if ($poExist && $poExist->num_rows > 0) {
                        $conn->query("UPDATE tbl_plot_owners SET reserved_by = {$targetUserId}, owner_name = '{$conn->real_escape_string($uOwnerName)}' WHERE UPPER(TRIM(section)) = '{$dest_sec}' AND plot_x = {$dest_x} AND plot_y = {$dest_y} AND active = 1");
                    } else {
                        $poIns = $conn->prepare("INSERT INTO tbl_plot_owners (section, plot_x, plot_y, owner_name, first_name, middle_name, last_name, suffix, phone_no, address, reserved_by, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                        if ($poIns) {
                            $poIns->bind_param('siisssssssi', $dest_sec, $dest_x, $dest_y, $uOwnerName, $uFirst, $uMiddle, $uLast, $uSuffix, $uPhone, $uAddr, $targetUserId);
                            $poIns->execute();
                            $poIns->close();
                        }
                    }
                }
            }
        } else {
            $upDec = $conn->prepare("UPDATE tbl_deceased SET transfer_status = 'TRANSFERRED', edited_by = ? WHERE deceased_id = ?");
            if ($upDec) {
                $upDec->bind_param('si', $admin_name, $deceased_id);
                $upDec->execute();
                $upDec->close();
            }
        }

        if (!empty($orig_sec) && $orig_x > 0 && $orig_y > 0) {
            $chkOld = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_deceased WHERE section = ? AND plot_x = ? AND plot_y = ?");
            if ($chkOld) {
                $chkOld->bind_param('sii', $orig_sec, $orig_x, $orig_y);
                $chkOld->execute();
                $cOld = $chkOld->get_result()->fetch_assoc()['c'] ?? 0;
                $chkOld->close();
                if ($cOld == 0) {
                    $conn->query("UPDATE tbl_plots SET status = 'available' WHERE UPPER(TRIM(section)) = UPPER(TRIM('{$orig_sec}')) AND plot_x = {$orig_x} AND plot_y = {$orig_y} AND status = 'occupied'");
                    @$conn->query("UPDATE plots SET status = 'Available' WHERE UPPER(TRIM(section)) = UPPER(TRIM('{$orig_sec}')) AND plot_x = {$orig_x} AND plot_y = {$orig_y} AND status = 'Occupied'");
                    $conn->query("UPDATE tbl_plot_owners SET active = 0 WHERE UPPER(TRIM(section)) = UPPER(TRIM('{$orig_sec}')) AND plot_x = {$orig_x} AND plot_y = {$orig_y}");
                    if (!empty($targetUserId) && $targetUserId > 0) {
                        $conn->query("DELETE FROM user_plot_ownership WHERE user_id = {$targetUserId} AND UPPER(TRIM(section)) = UPPER(TRIM('{$orig_sec}')) AND plot_x = {$orig_x} AND plot_y = {$orig_y}");
                    } else {
                        $conn->query("DELETE FROM user_plot_ownership WHERE UPPER(TRIM(section)) = UPPER(TRIM('{$orig_sec}')) AND plot_x = {$orig_x} AND plot_y = {$orig_y}");
                    }
                } else {
                    $remQ = $conn->query("SELECT first_name, middle_name, last_name, suffix FROM tbl_deceased WHERE UPPER(TRIM(section)) = UPPER(TRIM('{$orig_sec}')) AND plot_x = {$orig_x} AND plot_y = {$orig_y} ORDER BY deceased_id ASC LIMIT 1");
                    $remName = '';
                    if ($remQ && ($remRow = $remQ->fetch_assoc())) {
                        $remName = trim(implode(' ', array_filter([$remRow['first_name'], $remRow['middle_name'] ?? '', $remRow['last_name'], $remRow['suffix'] ?? ''])));
                    }
                    $conn->query("UPDATE user_plot_ownership SET deceased_reference = '{$conn->real_escape_string($remName)}' WHERE UPPER(TRIM(section)) = UPPER(TRIM('{$orig_sec}')) AND plot_x = {$orig_x} AND plot_y = {$orig_y}");
                }
            }
        }

        $logDetails = "Remains Transfer recorded for {$dec_fullname} (ID #{$deceased_id}) from Section {$orig_sec} Plot {$orig_x}-{$orig_y} on {$transfer_date}. Status: {$eligibility['status']} ({$years_elapsed} yrs). Reason: {$reason}";
        $stmtLog = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('REMAINS_TRANSFER', ?, ?, NOW())");
        if ($stmtLog) {
            $stmtLog->bind_param('si', $logDetails, $admin_id);
            $stmtLog->execute();
            $stmtLog->close();
        }

        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => "Remains transfer successfully recorded for {$dec_fullname}!",
            'transfer_id' => $transfer_record_id,
            'eligibility' => $eligibility
        ]);
    } catch (Exception $ex) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error recording transfer: ' . $ex->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_deceased_transfers') {
    header('Content-Type: application/json');
    $deceased_id = intval($_POST['deceased_id'] ?? 0);
    $transfers = [];

    if ($deceased_id > 0) {
        $stmt = $conn->prepare("SELECT * FROM tbl_deceased_transfers WHERE deceased_id = ? ORDER BY transfer_date DESC, id DESC");
        if ($stmt) {
            $stmt->bind_param('i', $deceased_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $transfers[] = $row;
            }
            $stmt->close();
        }
    } else {
        $res = $conn->query("SELECT * FROM tbl_deceased_transfers ORDER BY transfer_date DESC, id DESC LIMIT 50");
        while ($res && ($row = $res->fetch_assoc())) {
            $transfers[] = $row;
        }
    }

    echo json_encode(['success' => true, 'transfers' => $transfers]);
    exit;
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_plot_claim') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || strtoupper(trim((string)($_SESSION['role'] ?? ''))) !== 'ADMIN') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $claim_id = (int)($_POST['claim_id'] ?? 0);
    $admin_notes = trim((string)($_POST['admin_notes'] ?? 'Approved by Administrator'));
    $admin_id = (int)$_SESSION['user_id'];

    if ($claim_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
        exit;
    }

    ensure_user_plot_ownership_table($conn);

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

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_plot_claim') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || strtoupper(trim((string)($_SESSION['role'] ?? ''))) !== 'ADMIN') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $claim_id = (int)($_POST['claim_id'] ?? 0);
    $admin_notes = trim((string)($_POST['admin_notes'] ?? 'Rejected by Administrator'));
    $admin_id = (int)$_SESSION['user_id'];

    if ($claim_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
        exit;
    }

    ensure_user_plot_ownership_table($conn);

    $upStmt = $conn->prepare("UPDATE user_plot_ownership SET verification_status = 'REJECTED', admin_notes = ?, verified_by = ?, verified_at = NOW() WHERE id = ?");
    if ($upStmt) {
        $upStmt->bind_param('sii', $admin_notes, $admin_id, $claim_id);
        $upStmt->execute();
        $upStmt->close();

        $logDetails = "Rejected plot claim #{$claim_id}. Reason: {$admin_notes}";
        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('PLOT_CLAIM_REJECTED', ?, ?, NOW())");
        if ($logStmt) {
            $logStmt->bind_param('si', $logDetails, $admin_id);
            $logStmt->execute();
            $logStmt->close();
        }

        echo json_encode(['success' => true, 'message' => 'Plot claim has been rejected.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    exit;
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_link_plot') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || strtoupper(trim((string)($_SESSION['role'] ?? ''))) !== 'ADMIN') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $target_user_id = (int)($_POST['user_id'] ?? 0);
    $sec = strtoupper(trim((string)($_POST['section'] ?? '')));
    $raw_plot = trim((string)($_POST['plot_number'] ?? ''));
    $own_status = in_array(strtoupper(trim($_POST['ownership_status'] ?? '')), ['OWNED', 'RESERVED']) ? strtoupper(trim($_POST['ownership_status'])) : 'OWNED';
    $notes = trim((string)($_POST['notes'] ?? 'Manually linked by Administrator'));
    $admin_id = (int)$_SESSION['user_id'];

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

    ensure_user_plot_ownership_table($conn);

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

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unlink_plot') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || strtoupper(trim((string)($_SESSION['role'] ?? ''))) !== 'ADMIN') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $claim_id = (int)($_POST['claim_id'] ?? 0);
    $admin_id = (int)$_SESSION['user_id'];

    if ($claim_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
        exit;
    }

    ensure_user_plot_ownership_table($conn);

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

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_add_deceased_to_plot') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || strtoupper(trim((string)($_SESSION['role'] ?? ''))) !== 'ADMIN') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $admin_id = (int)$_SESSION['user_id'];
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

if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'get_available_adjacent_plot') {
    header('Content-Type: application/json; charset=utf-8');
    $sec = strtoupper(trim((string)($_REQUEST['section'] ?? '')));
    $px = (int)($_REQUEST['plot_x'] ?? 0);
    $py = (int)($_REQUEST['plot_y'] ?? 0);

    if (empty($sec) || $px <= 0 || $py <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid section or coordinates.']);
        exit;
    }

    $adj = find_next_available_adjacent_plot($conn, $sec, $px, $py);
    if ($adj) {
        echo json_encode([
            'success' => true,
            'section' => $adj['section'],
            'plot_x' => $adj['plot_x'],
            'plot_y' => $adj['plot_y'],
            'plot_no' => $adj['plot_no'],
            'status' => 'AVAILABLE',
            'lawn_type' => 'Lawn (Regular)'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No available adjacent plot found in section.']);
    }
    exit;
}

if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'add_deceased') {
    @ob_clean();
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    if (!isset($_SESSION['user_id']) || strtoupper(trim((string)($_SESSION['role'] ?? ''))) !== 'ADMIN') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator authorization required to register plot records.']);
        exit;
    }

    if (file_exists(__DIR__ . '/config/installment_helper.php')) {
        require_once __DIR__ . '/config/installment_helper.php';
    }

    try {
        $record_type = strtolower(trim($_POST['record_type'] ?? 'with_deceased'));
        if (!in_array($record_type, ['purchase_only', 'with_deceased'], true)) {
            $record_type = 'with_deceased';
        }

        if ($record_type === 'purchase_only') {
            if (empty($_POST['section']) || !isset($_POST['plot_x']) || !isset($_POST['plot_y'])) {
                throw new Exception("Missing required plot location.");
            }
            $section = strtoupper(trim($_POST['section']));
            $plot_x = intval($_POST['plot_x']);
            $plot_y = intval($_POST['plot_y']);

            $booker_name = strtoupper(trim($_POST['booker_name'] ?? ''));
            if ($booker_name === '') {
                throw new Exception("Client / Buyer name is required for recording plot purchase.");
            }

            $buyer_email = strtolower(trim($_POST['buyer_email'] ?? ($_POST['customer_email'] ?? ($_POST['email'] ?? ''))));
            if ($buyer_email === '' || !filter_var($buyer_email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("A valid Client Email Address is required so that purchase details and receipt can be sent.");
            }

            $contact_no = trim($_POST['buyer_contact'] ?? ($_POST['contact_no'] ?? ''));
            $payment_plan = ucfirst(strtolower(trim($_POST['payment_plan'] ?? 'Cash')));
            if (!in_array($payment_plan, ['Cash', 'Installment'], true)) {
                $payment_plan = 'Cash';
            }
            $payment_term = ($payment_plan === 'Installment') ? max(1, intval($_POST['payment_term'] ?? 12)) : 0;
            $total_amount = floatval($_POST['total_amount'] ?? 85000.00);
            $amount_paid = floatval($_POST['amount_paid'] ?? 0.00);
            $payment_status = strtoupper(trim($_POST['payment_status'] ?? 'PAID'));
            if ($payment_status !== 'HAS DUES') {
                $payment_status = 'PAID';
            }
            if ($payment_status === 'PAID' && $amount_paid < $total_amount && $total_amount > 0) {
                $amount_paid = $total_amount;
            }
            $monthly_due = ($payment_plan === 'Installment' && $payment_term > 0) ? round($total_amount / $payment_term, 2) : 0.00;

            $conn->autocommit(FALSE);

            $plot_id_val = 0;
            $tpCheck = $conn->prepare("SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND ((plot_x = ? AND plot_y = ?) OR (plot_x = ? AND plot_y = ?)) LIMIT 1");
            if ($tpCheck) {
                $tpCheck->bind_param('siiii', $section, $plot_x, $plot_y, $plot_y, $plot_x);
                $tpCheck->execute();
                $tpRes = $tpCheck->get_result();
                $existingTp = $tpRes ? $tpRes->fetch_assoc() : null;
                $tpCheck->close();
                if ($existingTp) {
                    $plot_id_val = (int)$existingTp['plot_id'];
                    $tpUp = $conn->prepare("UPDATE tbl_plots SET status = 'purchased' WHERE plot_id = ?");
                    if ($tpUp) {
                        $tpUp->bind_param('i', $plot_id_val);
                        $tpUp->execute();
                        $tpUp->close();
                    }
                } else {
                    $tpIns = $conn->prepare("INSERT INTO tbl_plots (section, plot_x, plot_y, status) VALUES (?, ?, ?, 'purchased')");
                    if ($tpIns) {
                        $tpIns->bind_param('sii', $section, $plot_x, $plot_y);
                        $tpIns->execute();
                        $plot_id_val = (int)$conn->insert_id;
                        $tpIns->close();
                    }
                }
            }

            if (($chkPlots = @$conn->query("SHOW TABLES LIKE 'plots'")) && $chkPlots->num_rows > 0) {
                $pUp = $conn->prepare("UPDATE plots SET status = 'Purchased' WHERE UPPER(TRIM(section)) = ? AND ((plot_x = ? AND plot_y = ?) OR (plot_x = ? AND plot_y = ?))");
                if ($pUp) {
                    $pUp->bind_param('siiii', $section, $plot_x, $plot_y, $plot_y, $plot_x);
                    $pUp->execute();
                    $pUp->close();
                }
            }

            $plotHyphen = $plot_x . '-' . $plot_y;
            $plotPad6 = sprintf('%03d%03d', $plot_x, $plot_y);
            $pType = ($payment_plan === 'Installment') ? 'Installment' : 'Cash on Site';
            $bkStatus = 'approved';
            $booking_id = 0;

            $chkColsTrans = [
                'source' => "VARCHAR(20) DEFAULT 'user'",
                'section_plot' => "VARCHAR(20) DEFAULT NULL",
                'customer_name' => "VARCHAR(255) DEFAULT NULL",
                'customer_email' => "VARCHAR(255) DEFAULT NULL",
                'full_name' => "VARCHAR(255) DEFAULT NULL",
                'email' => "VARCHAR(255) DEFAULT NULL",
                'plot_id' => "INT(11) DEFAULT NULL",
                'purpose' => "VARCHAR(30) DEFAULT 'plot_purchase'",
                'booking_type' => "VARCHAR(40) DEFAULT 'plot_purchase'",
                'payment_plan' => "VARCHAR(20) DEFAULT 'Cash'",
                'payment_term' => "INT(11) DEFAULT NULL",
                'total_amount' => "DECIMAL(10,2) DEFAULT 0.00",
                'plot_price' => "DECIMAL(10,2) DEFAULT 0.00",
                'plot_type' => "VARCHAR(50) DEFAULT NULL"
            ];
            foreach ($chkColsTrans as $cName => $cDef) {
                $cChk = @$conn->query("SHOW COLUMNS FROM tbl_bookings LIKE '{$cName}'");
                if ($cChk && $cChk->num_rows === 0) {
                    @$conn->query("ALTER TABLE tbl_bookings ADD COLUMN `{$cName}` {$cDef}");
                }
            }

            $bkCheck = $conn->prepare("SELECT id FROM tbl_bookings WHERE UPPER(TRIM(section)) = ? AND (plot = ? OR plot = ?) AND status NOT IN ('cancelled', 'rejected') ORDER BY id DESC LIMIT 1");
            if ($bkCheck) {
                $bkCheck->bind_param('sss', $section, $plotHyphen, $plotPad6);
                $bkCheck->execute();
                $bkRes = $bkCheck->get_result();
                $existingBooking = $bkRes ? $bkRes->fetch_assoc() : null;
                $bkCheck->close();

                if ($existingBooking) {
                    $booking_id = (int)$existingBooking['id'];
                    $upBk = $conn->prepare("UPDATE tbl_bookings SET service_type = 'plot_purchase', customer_name = ?, full_name = ?, customer_email = ?, email = ?, contact_number = ?, payment_plan = ?, payment_term = ?, total_amount = ?, amount_paid = ?, payment_type = ?, purpose = 'plot_purchase', deceased_id = NULL, deceased_name = NULL, source = 'admin', plot_id = ?, status = ? WHERE id = ?");
                    if ($upBk) {
                        $upBk->bind_param('ssssssidssisi',
                            $booker_name, $booker_name, $buyer_email, $buyer_email, $contact_no,
                            $payment_plan, $payment_term, $total_amount, $amount_paid, $pType,
                            $plot_id_val, $bkStatus, $booking_id
                        );
                        $upBk->execute();
                        $upBk->close();
                    }
                } else {
                    $insBk = $conn->prepare("INSERT INTO tbl_bookings (service_type, grave_location, plot_type, section, plot, section_plot, plot_price, preferred_date, contact_number, booker_location, payment_type, amount_paid, status, user_id, deceased_id, customer_name, full_name, customer_email, email, deceased_name, purpose, payment_plan, payment_term, total_amount, plot_id, booking_type, source, created_at) VALUES ('plot_purchase', ?, 'Lawn', ?, ?, ?, ?, NOW(), ?, 'Memorial Park Office', ?, ?, ?, ?, NULL, ?, ?, ?, ?, NULL, 'plot_purchase', ?, ?, ?, ?, 'plot_purchase', 'admin', NOW())");
                    if (!$insBk) {
                        if (function_exists('ensure_booking_table_integrity')) {
                            ensure_booking_table_integrity($conn);
                        }
                        $insBk = $conn->prepare("INSERT INTO tbl_bookings (service_type, grave_location, plot_type, section, plot, section_plot, plot_price, preferred_date, contact_number, booker_location, payment_type, amount_paid, status, user_id, deceased_id, customer_name, full_name, customer_email, email, deceased_name, purpose, payment_plan, payment_term, total_amount, plot_id, booking_type, source, created_at) VALUES ('plot_purchase', ?, 'Lawn', ?, ?, ?, ?, NOW(), ?, 'Memorial Park Office', ?, ?, ?, ?, NULL, ?, ?, ?, ?, NULL, 'plot_purchase', ?, ?, ?, ?, 'plot_purchase', 'admin', NOW())");
                    }
                    if (!$insBk) {
                        throw new Exception("Prepare tbl_bookings failed: " . $conn->error);
                    }
                    $gLoc = $section . ' ' . $plot_x . '-' . $plot_y;
                    $secPlot = $section . '-' . $plot_x;
                    $cUserId = (int)($_SESSION['user_id'] ?? 0);
                    $uCheck = $conn->query("SELECT user_id FROM tbl_user WHERE user_id = $cUserId LIMIT 1");
                    if (!$uCheck || $uCheck->num_rows === 0) {
                        $adminUserRow = $conn->query("SELECT user_id FROM tbl_user WHERE UPPER(TRIM(role)) = 'ADMIN' LIMIT 1")->fetch_assoc();
                        $cUserId = $adminUserRow ? (int)$adminUserRow['user_id'] : 76;
                    }
                    $insBk->bind_param('ssssdsdsisssssidi',
                        $gLoc, $section, $plotHyphen, $secPlot, $total_amount,
                        $contact_no, $pType, $amount_paid, $bkStatus, $cUserId,
                        $booker_name, $booker_name, $buyer_email, $buyer_email,
                        $payment_plan, $payment_term, $total_amount, $plot_id_val
                    );
                    if (!$insBk->execute()) {
                        throw new Exception("Execute tbl_bookings failed: " . $insBk->error);
                    }
                    $booking_id = $conn->insert_id;
                    $insBk->close();
                    if ($booking_id > 0 && function_exists('record_booking_log')) {
                        record_booking_log($conn, $booking_id, $section, $contact_no, 'Memorial Park Office', $total_amount);
                    }
                }
            }

            // Sync booking_plots
            if ($booking_id > 0) {
                $bpChk = @$conn->query("SHOW TABLES LIKE 'booking_plots'");
                if ($bpChk && $bpChk->num_rows > 0) {
                    $bpEx = $conn->prepare("SELECT id FROM booking_plots WHERE booking_id = ? LIMIT 1");
                    if ($bpEx) {
                        $bpEx->bind_param('i', $booking_id);
                        $bpEx->execute();
                        $hasBp = $bpEx->get_result()->num_rows > 0;
                        $bpEx->close();
                        if (!$hasBp) {
                            $bpIns = $conn->prepare("INSERT INTO booking_plots (booking_id, section, plot_x, plot_y, plot_id) VALUES (?, ?, ?, ?, ?)");
                            if ($bpIns) {
                                $bpIns->bind_param('isiii', $booking_id, $section, $plot_x, $plot_y, $plot_id_val);
                                $bpIns->execute();
                                $bpIns->close();
                            }
                        }
                    }
                }
            }

            if ($payment_plan === 'Installment' && $booking_id > 0 && function_exists('generate_contract_payment_schedule')) {
                generate_contract_payment_schedule($booking_id, $conn, ($payment_status === 'PAID' || $amount_paid > 0), $pType);
            }

            if (function_exists('ensure_user_plot_ownership_table')) {
                ensure_user_plot_ownership_table($conn);
            }
            $cUserId = (int)($_SESSION['user_id'] ?? 0);
            $cRole = strtoupper(trim((string)($_SESSION['role'] ?? '')));
            if ($cRole !== 'ADMIN' && $cUserId > 0) {
                $upoUpsert = $conn->prepare("INSERT INTO user_plot_ownership (user_id, section, plot_x, plot_y, ownership_status, verification_status, claim_source, deceased_reference, notes, verified_at) VALUES (?, ?, ?, ?, 'PURCHASED', 'VERIFIED', 'CUSTOMER_REGISTRATION', NULL, 'Plot purchase registered', NOW()) ON DUPLICATE KEY UPDATE ownership_status = 'PURCHASED', verification_status = 'VERIFIED', updated_at = NOW()");
                if ($upoUpsert) {
                    $upoUpsert->bind_param('isii', $cUserId, $section, $plot_x, $plot_y);
                    $upoUpsert->execute();
                    $upoUpsert->close();
                }
            }

            $log_sql = "INSERT INTO tbl_logs (action, details, timestamp, user_id) VALUES (?, ?, NOW(), ?)";
            if ($log_stmt = $conn->prepare($log_sql)) {
                $action = "PLOT_PURCHASE";
                $termText = ($payment_plan === 'Installment' && $payment_term > 0) ? " ($payment_term Months Installment)" : " (Cash Full Payment)";
                $details = "Recorded plot purchase for: " . $booker_name . " in Section " . $section . ", Plot " . $plot_x . "-" . $plot_y . $termText;
                $log_stmt->bind_param("ssi", $action, $details, $cUserId);
                $log_stmt->execute();
                $log_stmt->close();
            }

            $conn->commit();
            $conn->autocommit(TRUE);

            // Send confirmation email to client
            $emailSent = false;
            $mailerPath = file_exists(__DIR__ . '/mailer.php') ? (__DIR__ . '/mailer.php') : (__DIR__ . '/../mailer.php');
            if (file_exists($mailerPath)) {
                require_once $mailerPath;
                if (function_exists('send_plot_purchase_confirmation_email') && $booking_id > 0) {
                    $emailSent = @send_plot_purchase_confirmation_email($booking_id, $conn);
                }
            }

            $emailNotice = $emailSent ? " Confirmation email sent to {$buyer_email}." : "";
            @ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Plot purchase recorded successfully for ' . $booker_name . '!' . $emailNotice,
                'email_sent' => $emailSent
            ]);
            exit;
        }

        $required_fields = ['first_name', 'last_name', 'bday', 'date_died', 'born_in', 'contact_no', 'section', 'plot_x', 'plot_y'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Please fill in all required deceased fields.");
            }
        }

        $first_name = strtoupper(trim($_POST['first_name']));
        $middle_name = strtoupper(trim($_POST['middle_name'] ?? ''));
        $last_name = strtoupper(trim($_POST['last_name']));
        $suffix = strtoupper(trim($_POST['suffix'] ?? ''));
        if ($suffix === 'OTHER' && !empty($_POST['suffix_other'])) {
            $suffix = strtoupper(trim($_POST['suffix_other']));
        }
        $bday = trim($_POST['bday']);
        $date_died = trim($_POST['date_died']);
        $born_in = strtoupper(trim($_POST['born_in']));
        $contact_no = trim($_POST['contact_no']);
        $clean_contact = preg_replace('/\D+/', '', $contact_no);
        if (!preg_match('/^09\d{9}$/', $clean_contact)) {
            echo json_encode(['success' => false, 'message' => 'Family contact number must be an 11-digit mobile number starting with 09']);
            exit;
        }
        $contact_no = $clean_contact;
        $funeral_service = strtoupper(trim($_POST['funeral_service'] ?? ''));
        $section = strtoupper(trim($_POST['section']));
        $plot_x = intval($_POST['plot_x']);
        $plot_y = intval($_POST['plot_y']);
        $booker_name = strtoupper(trim($_POST['booker_name'] ?? ''));
        if ($booker_name === '') {
            $booker_name = trim($first_name . ' ' . $last_name);
        }

        $payment_plan = ucfirst(strtolower(trim($_POST['payment_plan'] ?? 'Cash')));
        if (!in_array($payment_plan, ['Cash', 'Installment'], true)) {
            $payment_plan = 'Cash';
        }
        $payment_term = ($payment_plan === 'Installment') ? max(1, intval($_POST['payment_term'] ?? 12)) : 0;
        $total_amount = floatval($_POST['total_amount'] ?? 85000.00);
        $amount_paid = floatval($_POST['amount_paid'] ?? 0.00);
        $payment_status = strtoupper(trim($_POST['payment_status'] ?? 'PAID'));
        if ($payment_status !== 'HAS DUES') {
            $payment_status = 'PAID';
        }
        if ($payment_status === 'PAID' && $amount_paid < $total_amount && $total_amount > 0) {
            $amount_paid = $total_amount;
        }
        $monthly_due = ($payment_plan === 'Installment' && $payment_term > 0) ? round($total_amount / $payment_term, 2) : 0.00;

        $cntCheck = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$section}' AND plot_x = {$plot_x} AND plot_y = {$plot_y}");
        $cntRow = $cntCheck ? $cntCheck->fetch_assoc() : ['c' => 0];
        if (intval($cntRow['c'] ?? 0) >= 4) {
            throw new Exception("This plot has reached the maximum capacity of 4 occupants.");
        }

        if (intval($cntRow['c'] ?? 0) > 0 && function_exists('check_plot_interment_interval_eligibility')) {
            $intervalCheck = check_plot_interment_interval_eligibility($conn, $section, $plot_x, $plot_y, $date_died);
            if (!$intervalCheck['is_eligible']) {
                throw new Exception($intervalCheck['message']);
            }
        }

        $conn->autocommit(FALSE);

        $added_by = (int)($_SESSION['user_id'] ?? 0);
        $plotHyphen = $plot_x . '-' . $plot_y;
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name . ' ' . $suffix);

        $existingPurchasedBooking = null;
        $bkpStmt = $conn->prepare("SELECT id FROM tbl_bookings WHERE UPPER(TRIM(section)) = ? AND (plot = ? OR plot = ?) AND (service_type = 'plot_purchase' OR purpose = 'plot_purchase') AND LOWER(status) NOT IN ('cancelled', 'rejected') ORDER BY id DESC LIMIT 1");
        if ($bkpStmt) {
            $bkpStmt->bind_param('sss', $section, $plotHyphen, $plotHyphen);
            $bkpStmt->execute();
            $bkpRes = $bkpStmt->get_result();
            if ($bkpRes && ($bkpRow = $bkpRes->fetch_assoc())) {
                $existingPurchasedBooking = $bkpRow;
            }
            $bkpStmt->close();
        }

        $hasSuffixCol = false;
        $colChk = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'suffix'");
        if ($colChk && $colChk->num_rows > 0) { $hasSuffixCol = true; }

        if ($hasSuffixCol) {
            $insert_sql = "INSERT INTO tbl_deceased (first_name, middle_name, last_name, suffix, bday, date_died, born_in, contact_no, funeral_service, section, plot_x, plot_y, added_by, edited_by, booker_name, payment_plan, payment_term, payment_status, total_amount, amount_paid, monthly_due) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            if (!$insert_stmt) { throw new Exception("Prepare failed: " . $conn->error); }
            $insert_stmt->bind_param("ssssssssssiissssisddd", $first_name, $middle_name, $last_name, $suffix, $bday, $date_died, $born_in, $contact_no, $funeral_service, $section, $plot_x, $plot_y, $added_by, $added_by, $booker_name, $payment_plan, $payment_term, $payment_status, $total_amount, $amount_paid, $monthly_due);
        } else {
            $insert_sql = "INSERT INTO tbl_deceased (first_name, middle_name, last_name, bday, date_died, born_in, contact_no, funeral_service, section, plot_x, plot_y, added_by, edited_by, booker_name, payment_plan, payment_term, payment_status, total_amount, amount_paid, monthly_due) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            if (!$insert_stmt) { throw new Exception("Prepare failed: " . $conn->error); }
            $insert_stmt->bind_param("sssssssssiissssisddd", $first_name, $middle_name, $last_name, $bday, $date_died, $born_in, $contact_no, $funeral_service, $section, $plot_x, $plot_y, $added_by, $added_by, $booker_name, $payment_plan, $payment_term, $payment_status, $total_amount, $amount_paid, $monthly_due);
        }

        if (!$insert_stmt->execute()) {
            throw new Exception("Failed to add deceased person: " . $insert_stmt->error);
        }
        $deceased_id = $conn->insert_id;
        $insert_stmt->close();

        $tpUp = $conn->prepare("UPDATE tbl_plots SET status = 'occupied' WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?");
        if ($tpUp) {
            $tpUp->bind_param('sii', $section, $plot_x, $plot_y);
            $tpUp->execute();
            $tpUp->close();
        }
        if (($chkPlots = @$conn->query("SHOW TABLES LIKE 'plots'")) && $chkPlots->num_rows > 0) {
            $pUp = $conn->prepare("UPDATE plots SET status = 'Occupied' WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?");
            if ($pUp) {
                $pUp->bind_param('sii', $section, $plot_x, $plot_y);
                $pUp->execute();
                $pUp->close();
            }
        }

        $chkColsBurial = [
            'source' => "VARCHAR(20) DEFAULT 'user'",
            'section_plot' => "VARCHAR(20) DEFAULT NULL",
            'customer_name' => "VARCHAR(255) DEFAULT NULL",
            'customer_email' => "VARCHAR(255) DEFAULT NULL",
            'full_name' => "VARCHAR(255) DEFAULT NULL",
            'email' => "VARCHAR(255) DEFAULT NULL",
            'deceased_name' => "VARCHAR(255) DEFAULT NULL",
            'deceased_id' => "INT(11) DEFAULT NULL",
            'purpose' => "VARCHAR(30) DEFAULT 'add_deceased'",
            'booking_type' => "VARCHAR(40) DEFAULT 'existing_plot_service'",
            'payment_plan' => "VARCHAR(20) DEFAULT 'full'",
            'payment_term' => "INT(11) DEFAULT NULL",
            'total_amount' => "DECIMAL(10,2) DEFAULT 0.00",
            'plot_price' => "DECIMAL(10,2) DEFAULT 0.00",
            'plot_type' => "VARCHAR(50) DEFAULT NULL"
        ];
        foreach ($chkColsBurial as $cName => $cDef) {
            $cChk = @$conn->query("SHOW COLUMNS FROM tbl_bookings LIKE '{$cName}'");
            if ($cChk && $cChk->num_rows === 0) {
                @$conn->query("ALTER TABLE tbl_bookings ADD COLUMN `{$cName}` {$cDef}");
            }
        }

        $pType = ($payment_plan === 'Installment') ? 'Installment' : 'Cash on Site';
        $bkStatus = 'approved';

        if ($existingPurchasedBooking) {
            $bId = (int)$existingPurchasedBooking['id'];
            $upBk = $conn->prepare("UPDATE tbl_bookings SET deceased_name = ?, deceased_id = ?, service_type = 'burial', source = 'admin', status = 'approved' WHERE id = ?");
            if ($upBk) {
                $upBk->bind_param('sii', $full_name, $deceased_id, $bId);
                $upBk->execute();
                $upBk->close();
            }
        } else {
            $insBk = $conn->prepare("INSERT INTO tbl_bookings (service_type, grave_location, plot_type, section, plot, section_plot, plot_price, preferred_date, contact_number, booker_location, payment_type, amount_paid, status, user_id, deceased_id, customer_name, full_name, deceased_name, purpose, payment_plan, payment_term, total_amount, booking_type, source, created_at) VALUES ('burial', ?, 'Lawn', ?, ?, ?, ?, ?, ?, 'Memorial Park Office', ?, ?, ?, ?, ?, ?, ?, ?, 'add_deceased', ?, ?, ?, 'existing_plot_service', 'admin', NOW())");
            if (!$insBk) {
                if (function_exists('ensure_booking_table_integrity')) {
                    ensure_booking_table_integrity($conn);
                }
                $insBk = $conn->prepare("INSERT INTO tbl_bookings (service_type, grave_location, plot_type, section, plot, section_plot, plot_price, preferred_date, contact_number, booker_location, payment_type, amount_paid, status, user_id, deceased_id, customer_name, full_name, deceased_name, purpose, payment_plan, payment_term, total_amount, booking_type, source, created_at) VALUES ('burial', ?, 'Lawn', ?, ?, ?, ?, ?, ?, 'Memorial Park Office', ?, ?, ?, ?, ?, ?, ?, ?, 'add_deceased', ?, ?, ?, 'existing_plot_service', 'admin', NOW())");
            }
            if ($insBk) {
                $gLoc = $section . ' ' . $plot_x . '-' . $plot_y;
                $secPlot = $section . '-' . $plot_x;
                $prefDate = !empty($date_died) ? $date_died : date('Y-m-d');
                $cUserId = (int)($_SESSION['user_id'] ?? 0);
                $uCheck = $conn->query("SELECT user_id FROM tbl_user WHERE user_id = $cUserId LIMIT 1");
                if (!$uCheck || $uCheck->num_rows === 0) {
                    $adminUserRow = $conn->query("SELECT user_id FROM tbl_user WHERE UPPER(TRIM(role)) = 'ADMIN' LIMIT 1")->fetch_assoc();
                    $cUserId = $adminUserRow ? (int)$adminUserRow['user_id'] : 76;
                }
                $insBk->bind_param('ssssdsssdsiissssid',
                    $gLoc, $section, $plotHyphen, $secPlot, $total_amount, $prefDate, $contact_no,
                    $pType, $amount_paid, $bkStatus, $cUserId, $deceased_id,
                    $booker_name, $booker_name, $full_name,
                    $payment_plan, $payment_term, $total_amount
                );
                $insBk->execute();
                $newBId = $conn->insert_id;
                $insBk->close();
                if ($newBId > 0 && function_exists('record_booking_log')) {
                    record_booking_log($conn, $newBId, $section, $contact_no, 'Memorial Park Office', $total_amount);
                }

                if ($payment_plan === 'Installment' && $newBId > 0 && function_exists('generate_contract_payment_schedule')) {
                    generate_contract_payment_schedule($newBId, $conn, ($payment_status === 'PAID' || $amount_paid > 0), $pType);
                }
            }
        }

        $log_sql = "INSERT INTO tbl_logs (action, details, timestamp, user_id) VALUES (?, ?, NOW(), ?)";
        if ($log_stmt = $conn->prepare($log_sql)) {
            $action = "ADD_DECEASED";
            $details = "Added deceased person: " . $full_name . " to Section " . $section . ", Plot " . $plot_x . "-" . $plot_y;
            $log_stmt->bind_param("ssi", $action, $details, $added_by);
            $log_stmt->execute();
            $log_stmt->close();
        }

        $conn->commit();
        $conn->autocommit(TRUE);

        @ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Deceased person added successfully!'
        ]);
        exit;
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
            $conn->autocommit(TRUE);
        }
        @ob_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_booking') {

    header('Content-Type: application/json');

    ob_start();

    error_reporting(E_ALL);
    ini_set('display_errors', 0); 

    try {

        $chkCols = [
            'plot_id' => "INT(11) DEFAULT NULL",
            'deceased_id' => "INT(11) DEFAULT NULL",
            'booking_type' => "VARCHAR(40) DEFAULT 'general'",
            'deceased_name' => "VARCHAR(255) DEFAULT NULL",
            'customer_name' => "VARCHAR(255) DEFAULT NULL",
            'customer_email' => "VARCHAR(255) DEFAULT NULL",
            'full_name' => "VARCHAR(255) DEFAULT NULL",
            'email' => "VARCHAR(255) DEFAULT NULL",
            'remarks' => "TEXT DEFAULT NULL",
            'purpose' => "VARCHAR(30) DEFAULT 'reserve_grave'",
            'payment_plan' => "VARCHAR(20) DEFAULT 'full'",
            'payment_term' => "INT(11) DEFAULT NULL",
            'interment_fee' => "DECIMAL(10,2) DEFAULT 0.00",
            'service_price' => "DECIMAL(10,2) DEFAULT 0.00",
            'total_amount' => "DECIMAL(10,2) DEFAULT 0.00",
            'num_lots' => "INT(11) DEFAULT 1",
            'area' => "VARCHAR(50) DEFAULT 'Lawn'",
            'source' => "VARCHAR(20) DEFAULT 'user'",
            'section_plot' => "VARCHAR(20) DEFAULT NULL"
        ];
        foreach ($chkCols as $colName => $colDef) {
            $cRes = $conn->query("SHOW COLUMNS FROM `tbl_bookings` LIKE '{$colName}'");
            if ($cRes && $cRes->num_rows === 0) {
                $conn->query("ALTER TABLE `tbl_bookings` ADD COLUMN `{$colName}` {$colDef}");
            }
        }

        $grave_location = $_POST['grave_location'] ?? $_POST['graveLocation'] ?? '';
        $service_type = $_POST['service_type'] ?? $_POST['serviceType'] ?? '';
        $section = $_POST['section'] ?? '';
        $purpose = $_POST['purpose'] ?? 'reserve_grave';
        $plot = $_POST['plot'] ?? '';
        $preferred_date = $_POST['preferred_date'] ?? $_POST['preferredDate'] ?? '';
        $contact_number = $_POST['contact_number'] ?? $_POST['contactNumber'] ?? '';
        $booker_location = $_POST['booker_location'] ?? $_POST['bookerLocation'] ?? '';
        $special_requests = $_POST['special_requests'] ?? $_POST['specialRequests'] ?? ($_POST['remarks'] ?? '');
        $remarks = $_POST['remarks'] ?? $special_requests;
        $customer_name = $_POST['customer_name'] ?? $_POST['full_name'] ?? ($_POST['booker_name'] ?? '');
        $customer_suffix = trim((string)($_POST['customer_suffix'] ?? ($_POST['client_suffix'] ?? '')));
        $deceased_name = $_POST['deceased_name'] ?? $_POST['deceasedName'] ?? '';
        $deceased_suffix = trim((string)($_POST['deceased_suffix'] ?? ($_POST['dec_suffix'] ?? '')));
        $customer_email = $_POST['customer_email'] ?? $_POST['email'] ?? '';

        $grave_location = trim((string)$grave_location);
        $service_type = trim((string)$service_type);
        $section = trim((string)$section);
        $plot = trim((string)$plot);
        $preferred_date = trim((string)$preferred_date);
        $contact_number = trim((string)$contact_number);
        $booker_location = trim((string)$booker_location);
        $special_requests = trim((string)$special_requests);
        $remarks = trim((string)$remarks);
        if (empty($special_requests) && !empty($remarks)) {
            $special_requests = $remarks;
        }
        if (empty($remarks) && !empty($special_requests)) {
            $remarks = $special_requests;
        }
        $purpose = trim((string)$purpose);
        $customer_name = trim((string)$customer_name);
        $deceased_name = trim((string)$deceased_name);
        $customer_email = trim((string)$customer_email);

        if (!empty($customer_suffix) && !empty($customer_name)) {
            if (!preg_match('/\b' . preg_quote($customer_suffix, '/') . '$/i', $customer_name)) {
                $customer_name .= ' ' . $customer_suffix;
            }
        }
        if (!empty($deceased_suffix) && !empty($deceased_name)) {
            if (!preg_match('/\b' . preg_quote($deceased_suffix, '/') . '$/i', $deceased_name)) {
                $deceased_name .= ' ' . $deceased_suffix;
            }
        }

        if (empty($grave_location) && !empty($section)) {
          $grave_location = !empty($plot) ? ($section . ' ' . $plot) : ($section . ' - Auto Assigned');
        }

        $contact_number = preg_replace('/\D+/', '', $contact_number);
        if (!empty($contact_number) && !preg_match('/^09\d{9}$/', $contact_number)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Contact number must be an 11-digit mobile number starting with 09.']);
            exit;
        }
        $purpose = in_array($purpose, ['reserve_grave', 'add_deceased', 'cemetery_service'], true) ? $purpose : 'reserve_grave';

        if ($purpose === 'cemetery_service' && empty($service_type)) {
          $service_type = 'Maintenance';
        }

        if (!$conn || $conn->connect_error) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Database connection failed. Please contact administrator.']);
            exit;
        }

        if ((empty($plot) || strtoupper($plot) === 'AUTO' || empty($_POST['plot_x']) || empty($_POST['plot_y'])) && !empty($section)) {
            $autoPlotQ = $conn->prepare("SELECT plot_id, section, plot_x, plot_y, price, lawn_type FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND LOWER(status) = 'available' ORDER BY plot_x ASC, plot_y ASC LIMIT 1");
            if ($autoPlotQ) {
                $secUpperAuto = strtoupper(trim($section));
                $autoPlotQ->bind_param('s', $secUpperAuto);
                $autoPlotQ->execute();
                $autoPlotRes = $autoPlotQ->get_result();
                if ($autoPlotRow = $autoPlotRes->fetch_assoc()) {
                    $plotRow = $autoPlotRow;
                    $plot_id = (int)$autoPlotRow['plot_id'];
                    $plot_x = (int)$autoPlotRow['plot_x'];
                    $plot_y = (int)$autoPlotRow['plot_y'];
                    $plot = $section . '- ' . $plot_x . ',' . $plot_y;
                    $grave_location = $section . ' ' . $plot_x . '-' . $plot_y;
                }
                $autoPlotQ->close();
            }
        }

        if (empty($plot) || strtoupper($plot) === 'AUTO') {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'No available plots found in Section ' . htmlspecialchars($section) . '. Please select another section or choose a plot from the map.']);
            exit;
        }

        $current_user_id = (int)($_SESSION['user_id'] ?? 0);
        if ($current_user_id > 0 && empty($customer_name)) {
            $user_stmt = $conn->prepare("SELECT first_name, last_name, email FROM tbl_user WHERE user_id = ? LIMIT 1");
            if ($user_stmt) {
                $user_stmt->bind_param('i', $current_user_id);
                $user_stmt->execute();
                $user_row = $user_stmt->get_result()->fetch_assoc();
                $user_stmt->close();
                if ($user_row) {
                    $customer_name = trim(($user_row['first_name'] ?? '') . ' ' . ($user_row['last_name'] ?? ''));
                    if (empty($customer_email)) {
                        $customer_email = $user_row['email'] ?? '';
                    }
                }
            }
        }

        $req_dec_id = isset($_POST['deceased_id']) ? (int)$_POST['deceased_id'] : 0;
        if ($req_dec_id > 0 && function_exists('is_user_authorized_for_deceased')) {
            if (!is_user_authorized_for_deceased($conn, $current_user_id, $req_dec_id)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'You are not authorized to book this deceased record.']);
                exit;
            }
        }

        $plot_id = isset($_POST['plot_id']) ? (int)$_POST['plot_id'] : 0;
        $plot_x = isset($_POST['plot_x']) && $_POST['plot_x'] !== '' ? (int)$_POST['plot_x'] : 0;
        $plot_y = isset($_POST['plot_y']) && $_POST['plot_y'] !== '' ? (int)$_POST['plot_y'] : 0;

        $plotRow = null;
        if ($plot_id > 0) {
            $pStmt = $conn->prepare("SELECT plot_id, section, plot_x, plot_y, lawn_type, price, status FROM tbl_plots WHERE plot_id = ? LIMIT 1");
            if ($pStmt) {
                $pStmt->bind_param('i', $plot_id);
                $pStmt->execute();
                $plotRow = $pStmt->get_result()->fetch_assoc();
                $pStmt->close();
            }
        }

        if (!$plotRow && !empty($section) && $plot_x > 0 && $plot_y > 0) {
            $pStmt = $conn->prepare("SELECT plot_id, section, plot_x, plot_y, lawn_type, price, status FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
            if ($pStmt) {
                $pStmt->bind_param('sii', $section, $plot_x, $plot_y);
                $pStmt->execute();
                $plotRow = $pStmt->get_result()->fetch_assoc();
                $pStmt->close();
            }
        }

        if (!$plotRow && !empty($plot) && strtoupper($plot) !== 'AUTO') {
            $plot_parts = preg_split('/[-_ ,]+/', $plot);
            if (count($plot_parts) >= 3) {
                $p_sec = $plot_parts[0];
                $p_x = (int)$plot_parts[1];
                $p_y = (int)$plot_parts[2];
                $pStmt = $conn->prepare("SELECT plot_id, section, plot_x, plot_y, lawn_type, price, status FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
                if ($pStmt) {
                    $pStmt->bind_param('sii', $p_sec, $p_x, $p_y);
                    $pStmt->execute();
                    $plotRow = $pStmt->get_result()->fetch_assoc();
                    $pStmt->close();
                }
            }
        }

        $selected_plots_raw = trim((string)($_POST['selected_plots_json'] ?? ''));
        $selected_plots = [];
        if (!empty($selected_plots_raw)) {
            $decoded = json_decode($selected_plots_raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                $selected_plots = $decoded;
            }
        }

        $plot_price = (float)($_POST['plot_price'] ?? 0);
        $posted_lot_type = trim((string)($_POST['lot_type'] ?? ($_POST['lawn_type'] ?? '')));
        $lot_type = (strcasecmp($posted_lot_type, 'Premium') === 0) ? 'Premium' : 'Regular';
        $area = trim((string)($_POST['area'] ?? 'Lawn'));
        
        // Canonical Rule: Number of distinct selected plots = Number of lots
        if (!empty($selected_plots)) {
            $distinctKeys = [];
            foreach ($selected_plots as $sp_item) {
                $sSec = strtoupper(trim((string)($sp_item['section'] ?? $section)));
                $sX = (int)($sp_item['plot_x'] ?? 0);
                $sY = (int)($sp_item['plot_y'] ?? 0);
                $distinctKeys[$sSec . '-' . $sX . '-' . $sY] = true;
            }
            $num_lots = count($distinctKeys);
        } else {
            $num_lots = 1;
        }
        $num_lots = max(1, min(2, $num_lots));

        if (count($selected_plots) > 2 || $num_lots > 2) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'message' => 'Only a maximum of 2 plots can be reserved in a single booking.'
            ]);
            exit;
        }

        if (!empty($selected_plots)) {
            $seenPlotKeys = [];
            foreach ($selected_plots as $sp_check) {
                $sp_sec_c = strtoupper(trim((string)($sp_check['section'] ?? $section)));
                $sp_x_c = (int)($sp_check['plot_x'] ?? 0);
                $sp_y_c = (int)($sp_check['plot_y'] ?? 0);
                $k = $sp_sec_c . '-' . $sp_x_c . '-' . $sp_y_c;
                if (isset($seenPlotKeys[$k])) {
                    ob_end_clean();
                    echo json_encode([
                        'success' => false,
                        'message' => 'Cannot add the same plot twice in the same booking.'
                    ]);
                    exit;
                }
                $seenPlotKeys[$k] = true;
            }
        }

        // Enforce purpose eligibility:
        // 'add_deceased' and 'cemetery_service' are ONLY allowed on plots that are either RESERVED, PURCHASED, or OCCUPIED (or have existing deceased/occupants).
        // If plot is AVAILABLE (vacant / no deceased / no reservation), ONLY 'reserve_grave' is allowed!
        if (in_array($purpose, ['add_deceased', 'cemetery_service'], true)) {
            $targetPlotsToCheck = !empty($selected_plots) ? $selected_plots : ($plotRow ? [$plotRow] : []);
            if (empty($targetPlotsToCheck) && !empty($section) && $plot_x > 0 && $plot_y > 0) {
                $targetPlotsToCheck = [['section' => $section, 'plot_x' => $plot_x, 'plot_y' => $plot_y]];
            }
            foreach ($targetPlotsToCheck as $tPlot) {
                $tSec = strtoupper(trim((string)($tPlot['section'] ?? $section)));
                $tPx = (int)($tPlot['plot_x'] ?? 0);
                $tPy = (int)($tPlot['plot_y'] ?? 0);
                if (!empty($tSec) && $tPx > 0 && $tPy > 0) {
                    $chkStatusStmt = $conn->prepare("SELECT status, (SELECT COUNT(*) FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND (plot = ? OR (plot_x = ? AND plot_y = ?))) AS occ_count FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
                    if ($chkStatusStmt) {
                        $plotCoordStr = $tPx . ',' . $tPy;
                        $chkStatusStmt->bind_param('ssiisii', $tSec, $plotCoordStr, $tPx, $tPy, $tSec, $tPx, $tPy);
                        $chkStatusStmt->execute();
                        $chkRes = $chkStatusStmt->get_result()->fetch_assoc();
                        $chkStatusStmt->close();
                        if ($chkRes) {
                            $dbStatus = strtolower(trim((string)$chkRes['status']));
                            $occCount = (int)$chkRes['occ_count'];
                            if ($dbStatus === 'available' && $occCount === 0) {
                                ob_end_clean();
                                echo json_encode([
                                    'success' => false,
                                    'message' => "Plot {$tSec}-{$tPx},{$tPy} is currently AVAILABLE. 'Add Deceased' and 'Cemetery Service' can only be performed on plots that are already RESERVED or OCCUPIED. Please choose 'Reserve New Grave' instead."
                                ]);
                                exit;
                            }
                        }
                    }
                }
            }
        }

        $payment_plan = trim(strtolower((string)($_POST['payment_plan'] ?? ($_POST['payment_option'] ?? 'full'))));
        if ($payment_plan !== 'installment') {
            $payment_plan = 'full';
        }
        $payment_term = ($payment_plan === 'installment' && !empty($_POST['payment_term'])) ? (int)$_POST['payment_term'] : null;

        $interment_fee = (float)($_POST['interment_fee'] ?? 0);
        $deceased_entries_raw = trim((string)($_POST['deceased_entries_json'] ?? ''));
        $deceased_entries = [];
        if (!empty($deceased_entries_raw)) {
            $decoded_dec = json_decode($deceased_entries_raw, true);
            if (is_array($decoded_dec) && !empty($decoded_dec)) {
                $deceased_entries = $decoded_dec;
            }
        }
        if ($purpose === 'add_deceased' && !empty($deceased_entries)) {
            $interment_fee = round(count($deceased_entries) * 38000.00, 2);
            $dec_name_parts = [];
            foreach ($deceased_entries as $de) {
                $dn = trim(($de['first_name'] ?? '') . ' ' . ($de['middle_name'] ?? '') . ' ' . ($de['last_name'] ?? '') . ' ' . ($de['suffix'] ?? ''));
                $dp = (!empty($de['section']) && !empty($de['plot_x']) && !empty($de['plot_y'])) ? " ({$de['section']} {$de['plot_x']},{$de['plot_y']})" : '';
                if (!empty($dn)) $dec_name_parts[] = $dn . $dp;
            }
            if (!empty($dec_name_parts)) {
                $deceased_name = implode(', ', $dec_name_parts);
            }
        }

        if (!empty($selected_plots)) {
            $total_selected_price = 0.0;
            $plot_labels = [];
            $loc_labels = [];
            $hasAnyPremium = false;
            foreach ($selected_plots as $idx => &$sp) {
                $sp_sec = strtoupper(trim((string)($sp['section'] ?? $section)));
                $sp_px = (int)($sp['plot_x'] ?? 0);
                $sp_py = (int)($sp['plot_y'] ?? 0);
                $isPlotCorner = (function_exists('is_corner_premium_plot') && is_corner_premium_plot($sp_sec, $sp_px, $sp_py));
                $sp_type = (strcasecmp($lot_type, 'Premium') === 0 || $isPlotCorner || strcasecmp(trim((string)($sp['lawn_type'] ?? '')), 'Premium') === 0) ? 'Premium' : 'Regular';
                if ($sp_type === 'Premium') $hasAnyPremium = true;
                $sp_price = ($sp_type === 'Premium') ? 89000.00 : 85000.00;
                $sp['price'] = $sp_price;
                $sp['section'] = $sp_sec;
                $sp['plot_x'] = $sp_px;
                $sp['plot_y'] = $sp_py;
                $sp['lawn_type'] = $sp_type;
                $sp_num = trim((string)($sp['plot_number'] ?? ($sp_sec . '- ' . $sp_px . ',' . $sp_py)));
                $sp['plot_number'] = $sp_num;
                $total_selected_price += $sp_price;
                $plot_labels[] = $sp_num;
                $loc_labels[] = $sp_sec . ' ' . $sp_px . '-' . $sp_py;
            }
            unset($sp);

            $plot_price = $total_selected_price;
            $first_p = $selected_plots[0];
            $section = $first_p['section'];
            $plot_x = $first_p['plot_x'];
            $plot_y = $first_p['plot_y'];
            $lot_type = $hasAnyPremium ? 'Premium' : 'Regular';
            $plot_type = $area . ' (' . $lot_type . ')';
            $plot = implode(', ', $plot_labels);
            $grave_location = implode(', ', $loc_labels);
            if (empty($plot_id) && !empty($first_p['plot_id'])) {
                $plot_id = (int)$first_p['plot_id'];
            }
        } elseif ($plotRow) {
            $plot_id = (int)$plotRow['plot_id'];
            $section = $plotRow['section'] ?? $section;
            $plot_x = (int)$plotRow['plot_x'];
            $plot_y = (int)$plotRow['plot_y'];
            $isCorner = (function_exists('is_corner_premium_plot') && is_corner_premium_plot($section, $plot_x, $plot_y));
            $isDbPremium = (strcasecmp(trim((string)($plotRow['lawn_type'] ?? '')), 'Premium') === 0 || (float)($plotRow['price'] ?? 0) >= 89000);
            if ($isCorner || $isDbPremium || strcasecmp($posted_lot_type, 'Premium') === 0) {
                $lot_type = 'Premium';
                $plot_price = 89000.00;
            } else {
                $lot_type = 'Regular';
                $plot_price = 85000.00;
            }
            $plot_type = $area . ' (' . $lot_type . ')';
            $plot = $section . '- ' . $plot_x . ',' . $plot_y;
            $grave_location = $section . ' ' . $plot_x . '-' . $plot_y;
            $num_lots = 1;
        }

        if ($plot_price <= 0) {
            if (file_exists(__DIR__ . '/config/pricing_policy.php')) {
                require_once __DIR__ . '/config/pricing_policy.php';
            }
            if (function_exists('get_plot_official_pricing')) {
                $official = get_plot_official_pricing($section, $plot_x, $plot_y);
                $plot_price = (float)($official['lot_price'] ?? 85000.00);
            } else {
                $plot_price = 85000.00;
            }
        }
        if (empty($selected_plots)) {
            if (strcasecmp($lot_type, 'Premium') === 0 && $plot_price < 89000.00 && strcasecmp($area, 'Lawn') === 0) {
                $plot_price = 89000.00;
            } elseif (strcasecmp($lot_type, 'Regular') === 0 && strcasecmp($area, 'Lawn') === 0 && $plot_price <= 0) {
                $plot_price = 85000.00;
            }
        }

        $service_price = 0.0;

        if ($purpose === 'cemetery_service') {
          if (empty($service_type)) $service_type = 'Maintenance';
          $svc = $conn->prepare("SELECT price FROM services WHERE service_name = ? LIMIT 1");
          if ($svc) {
            $svc->bind_param('s', $service_type);
            $svc->execute();
            $srv = $svc->get_result();
            if ($srv && ($r = $srv->fetch_assoc())) {
              $service_price = (float)$r['price'];
            }
            $svc->close();
          }
          if ($service_price <= 0) {
            $service_price = 200.00;
          }
        }

        if (empty($customer_name)) {
          ob_end_clean();
          echo json_encode(['success' => false, 'message' => 'Please enter Client / Buyer Name.']);
          exit;
        }
        if (empty($section)) {
          ob_end_clean();
          echo json_encode(['success' => false, 'message' => 'Please select a valid plot from the map.']);
          exit;
        }
        if (empty($preferred_date)) {
          $preferred_date = date('Y-m-d');
        }
        if (empty($contact_number)) {
          $contact_number = '09000000000';
        }
        if (empty($booker_location)) {
          $booker_location = 'Memorial Park Walk-in / Office';
        }

        $user_id = (int)($_SESSION['user_id'] ?? 0);
        if ($user_id > 0) {
            $uChk = $conn->prepare("SELECT user_id FROM tbl_user WHERE user_id = ? LIMIT 1");
            if ($uChk) {
                $uChk->bind_param('i', $user_id);
                $uChk->execute();
                $uChkRes = $uChk->get_result();
                if (!$uChkRes || $uChkRes->num_rows === 0) {
                    $user_id = 0;
                }
                $uChk->close();
            }
        }
        if ($user_id <= 0) {
            $uRes = $conn->query("SELECT user_id FROM tbl_user WHERE UPPER(role) = 'ADMIN' ORDER BY user_id ASC LIMIT 1");
            if (!$uRes || $uRes->num_rows === 0) {
                $uRes = $conn->query("SELECT user_id FROM tbl_user ORDER BY user_id ASC LIMIT 1");
            }
            $user_id = ($uRes && ($uRow = $uRes->fetch_assoc())) ? (int)$uRow['user_id'] : 0;
        }
        $source = strtolower((string)($_POST['source'] ?? 'user'));
        if (!in_array($source, ['admin', 'user'], true)) {
            $source = (!empty($_SESSION['role']) && strtoupper($_SESSION['role']) === 'ADMIN') ? 'admin' : 'user';
        }

        $payment_method = isset($_POST['payment_method']) ? trim((string)$_POST['payment_method']) : '';
        $normalized_payment = strtolower($payment_method);
        if ($normalized_payment === 'gcash') {
            $payment_method = 'GCash';
        } else {
            $payment_method = 'CASH / OFFICE';
        }

        if (file_exists(__DIR__ . '/config/installment_helper.php')) {
            require_once __DIR__ . '/config/installment_helper.php';
        }

        $total_amount = 0.0;
        $initial_payment = 0.0;
        $monthly_fee = 0.0;

        if ($purpose === 'reserve_grave') {
          if ($payment_plan === 'installment' && $payment_term > 0) {
            $planCalc = calculate_installment_plan((int)$payment_term, max(1, (int)$num_lots));
            $total_amount = $planCalc['total_plan_cost'];
            $initial_payment = $planCalc['initial_down_payment'];
            $monthly_fee = $planCalc['monthly_fee'];
          } else {
            $total_amount = round(floatval($plot_price) + floatval($interment_fee) + floatval($service_price), 2);
            $initial_payment = $total_amount;
          }
        } elseif ($purpose === 'add_deceased') {
          $calc_fee = ($interment_fee > 0) ? $interment_fee : (!empty($deceased_entries) ? (count($deceased_entries) * 38000.00) : 38000.00);
          $total_amount = round(floatval($calc_fee) + floatval($service_price), 2);
          $initial_payment = $total_amount;
        } elseif ($purpose === 'cemetery_service') {
          $total_amount = round(floatval($service_price > 0 ? $service_price : 200.00), 2);
          $initial_payment = $total_amount;
        }

        if ($total_amount <= 0 && $purpose !== 'cemetery_service') {
            $total_amount = round(floatval($plot_price) + floatval($interment_fee) + floatval($service_price), 2);
            $initial_payment = $total_amount;
        }
        if ($total_amount <= 0 && $purpose !== 'cemetery_service') {
            $total_amount = 85000.00;
            $initial_payment = 85000.00;
        }

        if (function_exists('ensure_booking_table_integrity')) {
            ensure_booking_table_integrity($conn);
        } elseif (function_exists('ensure_service_workflow_columns')) {
            ensure_service_workflow_columns($conn);
        }
        $source_val = 'admin';
        $full_name = $customer_name;
        $email_value = $customer_email;
        $payment_term_int = ($payment_plan === 'installment' && !empty($payment_term)) ? (int)$payment_term : 0;
        $booking_id = 0;
        $prepErr = '';

        $stmt = $conn->prepare("INSERT INTO tbl_bookings 
            (service_type, grave_location, section, plot, plot_type, plot_price, 
             preferred_date, contact_number, booker_location, special_requests, remarks, 
             payment_type, purpose, user_id, plot_id, customer_name, customer_email, 
             full_name, email, deceased_name, interment_fee, service_price, total_amount, 
             payment_plan, payment_term, num_lots, area, status, source, payment_status, service_status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 'UNPAID', 'PENDING')");
        if (!$stmt) {
            $prepErr = $conn->error ?: 'Database prepare failed';
            if (function_exists('ensure_booking_table_integrity')) {
                ensure_booking_table_integrity($conn);
            }
            $stmt = $conn->prepare("INSERT INTO tbl_bookings 
                (service_type, grave_location, section, plot, plot_type, plot_price, 
                 preferred_date, contact_number, booker_location, special_requests, remarks, 
                 payment_type, purpose, user_id, plot_id, customer_name, customer_email, 
                 full_name, email, deceased_name, interment_fee, service_price, total_amount, 
                 payment_plan, payment_term, num_lots, area, status, source, payment_status, service_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 'UNPAID', 'PENDING')");
        }

        if ($stmt) {
            $bind_result = $stmt->bind_param(
                "sssssdsssssssiisssssdddsiiss",
                $service_type,
                $grave_location,
                $section,
                $plot,
                $plot_type,
                $plot_price,
                $preferred_date,
                $contact_number,
                $booker_location,
                $special_requests,
                $remarks,
                $payment_method,
                $purpose,
                $user_id,
                $plot_id,
                $customer_name,
                $customer_email,
                $full_name,
                $email_value,
                $deceased_name,
                $interment_fee,
                $service_price,
                $total_amount,
                $payment_plan,
                $payment_term_int,
                $num_lots,
                $area,
                $source_val
            );
            if ($bind_result && $stmt->execute()) {
                $booking_id = (int)$conn->insert_id;
                $stmt->close();
            } else {
                $prepErr = $stmt->error ?: ($conn->error ?: 'Execution failed');
                $stmt->close();
                $stmt = null;
            }
        }

        // Resilient Fallback: Dynamically match existing columns in tbl_bookings
        if ($booking_id <= 0) {
            $adminBkData = [
                'service_type'    => $service_type,
                'grave_location'  => $grave_location,
                'section'         => $section,
                'plot'            => $plot,
                'section_plot'    => $plot,
                'plot_type'       => $plot_type,
                'plot_price'      => (float)$plot_price,
                'preferred_date'  => $preferred_date,
                'contact_number'  => $contact_number,
                'booker_location' => $booker_location,
                'special_requests'=> $special_requests,
                'remarks'         => $remarks,
                'payment_type'    => $payment_method,
                'purpose'         => $purpose,
                'user_id'         => (int)$user_id,
                'plot_id'         => $plot_id > 0 ? (int)$plot_id : null,
                'customer_name'   => $customer_name,
                'customer_email'  => $customer_email,
                'full_name'       => $full_name,
                'email'           => $email_value,
                'deceased_name'   => $deceased_name,
                'interment_fee'   => (float)$interment_fee,
                'service_price'   => (float)$service_price,
                'total_amount'    => (float)$total_amount,
                'payment_plan'    => $payment_plan,
                'payment_term'    => (int)$payment_term_int,
                'num_lots'        => (int)$num_lots,
                'area'            => $area,
                'status'          => 'pending',
                'source'          => $source_val,
                'payment_status'  => 'UNPAID',
                'service_status'  => 'PENDING'
            ];

            $colRes = $conn->query("SHOW COLUMNS FROM `tbl_bookings`");
            if ($colRes) {
                $existingCols = [];
                while ($c = $colRes->fetch_assoc()) {
                    $existingCols[strtolower($c['Field'])] = true;
                }

                $insCols = [];
                $insVals = [];
                foreach ($adminBkData as $k => $v) {
                    if (isset($existingCols[strtolower($k)])) {
                        $insCols[] = "`$k`";
                        if ($v === null) {
                            $insVals[] = "NULL";
                        } elseif (is_numeric($v)) {
                            $insVals[] = $v;
                        } else {
                            $insVals[] = "'" . $conn->real_escape_string((string)$v) . "'";
                        }
                    }
                }

                if (!empty($insCols)) {
                    $dynSql = "INSERT INTO `tbl_bookings` (" . implode(', ', $insCols) . ") VALUES (" . implode(', ', $insVals) . ")";
                    if ($conn->query($dynSql)) {
                        $booking_id = (int)$conn->insert_id;
                    } else {
                        $prepErr = $conn->error ?: 'Fallback query failed';
                    }
                }
            }
        }

        if ($booking_id <= 0) {
            ob_end_clean();
            $finalMsg = !empty($prepErr) ? $prepErr : ($conn->error ?: 'Could not prepare or execute booking record');
            echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . $finalMsg]);
            exit;
        }

        if ($booking_id > 0) {
            if (function_exists('record_booking_log')) {
                record_booking_log($conn, $booking_id, $section, $contact_number, $booker_location, $total_amount);
            }
            $amount_paid_value = 0.00;

            $isConfirmedAdminBooking = in_array(strtolower(trim((string)($adminBkData['status'] ?? 'pending'))), ['approved', 'confirmed', 'reserved', 'completed']);
            $bpStatus = $isConfirmedAdminBooking ? 'reserved' : 'pending';

            $bpStmt = $conn->prepare("INSERT INTO booking_plots (booking_id, plot_id, section, plot_x, plot_y, plot_number, lawn_type, price_at_booking, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '{$bpStatus}', NOW())");
            if (!empty($selected_plots)) {
                foreach ($selected_plots as $sp) {
                    $sp_plot_id = (int)($sp['plot_id'] ?? 0);
                    $sp_sec = strtoupper(trim((string)($sp['section'] ?? $section)));
                    $sp_px = (int)($sp['plot_x'] ?? 0);
                    $sp_py = (int)($sp['plot_y'] ?? 0);
                    $sp_num = trim((string)($sp['plot_number'] ?? ($sp_sec . '- ' . $sp_px . ',' . $sp_py)));
                    $sp_type = trim((string)($sp['lawn_type'] ?? 'Regular'));
                    $sp_price = (float)($sp['price'] ?? ($sp_type === 'Premium' ? 89000.00 : 85000.00));
                    if ($sp_plot_id <= 0 && !empty($sp_sec) && $sp_px > 0 && $sp_py > 0) {
                        $fStmt = $conn->prepare("SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
                        if ($fStmt) {
                            $fStmt->bind_param('sii', $sp_sec, $sp_px, $sp_py);
                            $fStmt->execute();
                            $fRes = $fStmt->get_result()->fetch_assoc();
                            if ($fRes) $sp_plot_id = (int)$fRes['plot_id'];
                            $fStmt->close();
                        }
                    }
                    if ($bpStmt) {
                        $bpStmt->bind_param('iisiissd', $booking_id, $sp_plot_id, $sp_sec, $sp_px, $sp_py, $sp_num, $sp_type, $sp_price);
                        $bpStmt->execute();
                    }
                    if ($isConfirmedAdminBooking) {
                        if ($sp_plot_id > 0) {
                            $conn->query("UPDATE tbl_plots SET status = 'reserved' WHERE plot_id = " . (int)$sp_plot_id);
                        } elseif (!empty($sp_sec) && $sp_px > 0 && $sp_py > 0) {
                            $conn->query("UPDATE tbl_plots SET status = 'reserved' WHERE UPPER(TRIM(section)) = '{$conn->real_escape_string($sp_sec)}' AND plot_x = {$sp_px} AND plot_y = {$sp_py}");
                        }
                    }
                }
            } elseif ($purpose === 'reserve_grave' || !empty($section)) {
                $sp_num = !empty($plot) ? $plot : ($section . '- ' . $plot_x . ',' . $plot_y);
                $sp_type = $lot_type;
                $sp_price = $plot_price;
                if ($bpStmt) {
                    $bpStmt->bind_param('iisiissd', $booking_id, $plot_id, $section, $plot_x, $plot_y, $sp_num, $sp_type, $sp_price);
                    $bpStmt->execute();
                }
                if ($isConfirmedAdminBooking) {
                    if ($plot_id > 0) {
                        $conn->query("UPDATE tbl_plots SET status = 'reserved' WHERE plot_id = " . (int)$plot_id);
                    } elseif (!empty($section) && $plot_x > 0 && $plot_y > 0) {
                        $conn->query("UPDATE tbl_plots SET status = 'reserved' WHERE UPPER(TRIM(section)) = '{$conn->real_escape_string($section)}' AND plot_x = {$plot_x} AND plot_y = {$plot_y}");
                    }
                }
            }
            if ($bpStmt) $bpStmt->close();

            // Record deceased person(s) into tbl_deceased and update target plot statuses
            if ($purpose === 'add_deceased') {
                $decListToProcess = [];
                if (!empty($deceased_entries)) {
                    $decListToProcess = $deceased_entries;
                } elseif (!empty($_POST['first_name']) || !empty($_POST['deceased_first_name'])) {
                    $decListToProcess[] = [
                        'first_name' => $_POST['first_name'] ?? $_POST['deceased_first_name'],
                        'middle_name' => $_POST['middle_name'] ?? $_POST['deceased_middle_name'] ?? '',
                        'last_name' => $_POST['last_name'] ?? $_POST['deceased_last_name'] ?? '',
                        'suffix' => $_POST['suffix'] ?? $_POST['deceased_suffix'] ?? '',
                        'bday' => $_POST['bday'] ?? $_POST['deceased_birthday'] ?? null,
                        'date_died' => $_POST['date_died'] ?? $_POST['deceased_date_died'] ?? null,
                        'interment_date' => $_POST['interment_date'] ?? $_POST['deceased_interment_date'] ?? null,
                        'relationship' => $_POST['relationship'] ?? $_POST['deceased_relationship'] ?? '',
                        'section' => $section,
                        'plot_x' => $plot_x,
                        'plot_y' => $plot_y
                    ];
                }

                $adminName = !empty($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Admin';
                foreach ($decListToProcess as $dec_item) {
                    $d_fn = strtoupper(trim((string)($dec_item['first_name'] ?? '')));
                    $d_mn = strtoupper(trim((string)($dec_item['middle_name'] ?? '')));
                    $d_ln = strtoupper(trim((string)($dec_item['last_name'] ?? '')));
                    $d_suf = strtoupper(trim((string)($dec_item['suffix'] ?? '')));
                    $d_bday = !empty($dec_item['bday']) ? $dec_item['bday'] : null;
                    $d_died = !empty($dec_item['date_died']) ? $dec_item['date_died'] : null;
                    $d_interment = !empty($dec_item['interment_date']) ? $dec_item['interment_date'] : $d_died;
                    $d_rel = strtoupper(trim((string)($dec_item['relationship'] ?? '')));
                    $d_sec = strtoupper(trim((string)($dec_item['section'] ?? $section)));
                    $d_px = (int)($dec_item['plot_x'] ?? $plot_x);
                    $d_py = (int)($dec_item['plot_y'] ?? $plot_y);

                    if (!empty($d_fn) && !empty($d_ln)) {
                        $decStmt = $conn->prepare("INSERT INTO tbl_deceased 
                            (first_name, middle_name, last_name, suffix, bday, date_died, interment_date, section, plot_x, plot_y, user_id, relationship_to_account_holder, added_by, edited_by, booker_name, payment_plan, payment_status, total_amount, amount_paid) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Cash', 'PAID', 38000.00, 38000.00)");
                        if ($decStmt) {
                            $decStmt->bind_param('ssssssssiiissss', 
                                $d_fn, $d_mn, $d_ln, $d_suf, 
                                $d_bday, $d_died, $d_interment, 
                                $d_sec, $d_px, $d_py, 
                                $user_id, $d_rel, $adminName, $adminName, $customer_name
                            );
                            $decStmt->execute();
                            $new_deceased_id = (int)$conn->insert_id;
                            $decStmt->close();

                            if ($new_deceased_id > 0) {
                                $plotIdForDec = 0;
                                $pIdQ = $conn->query("SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = '{$d_sec}' AND plot_x = {$d_px} AND plot_y = {$d_py} LIMIT 1");
                                if ($pIdQ && ($pIdRow = $pIdQ->fetch_assoc())) {
                                    $plotIdForDec = (int)$pIdRow['plot_id'];
                                }
                                @$conn->query("INSERT INTO plot_deceased_mapping (plot_id, deceased_id, burial_date, created_at) VALUES ({$plotIdForDec}, {$new_deceased_id}, " . ($d_interment ? "'{$d_interment}'" : "NULL") . ", NOW())");
                            }
                        }

                        @$conn->query("UPDATE tbl_plots SET status = 'occupied' WHERE UPPER(TRIM(section)) = '{$d_sec}' AND plot_x = {$d_px} AND plot_y = {$d_py}");
                        @$conn->query("UPDATE plots SET status = 'Occupied' WHERE UPPER(TRIM(section)) = '{$d_sec}' AND plot_x = {$d_px} AND plot_y = {$d_py}");

                        $dFullName = trim($d_fn . ' ' . $d_mn . ' ' . $d_ln . ' ' . $d_suf);
                        $dLogDetails = "Added Deceased: {$dFullName} to Section {$d_sec} Plot {$d_px}-{$d_py} | Booking ID: #{$booking_id} | Booker: {$customer_name}";
                        $conn->query("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('DECEASED_ADDED', '" . $conn->real_escape_string($dLogDetails) . "', {$user_id}, NOW())");
                    }
                }
            }

            if ($payment_method === 'GCash') {
                $amount_paid_value = 0.00;
                $update_stmt = $conn->prepare("UPDATE tbl_bookings SET payment_type = ?, amount_paid = 0.00, status = 'pending', bank_name = 'GCash' WHERE id = ?");
                if ($update_stmt) {
                    $update_stmt->bind_param('si', $payment_method, $booking_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            } else {
                $amount_paid_value = 0.00;
                $update_stmt = $conn->prepare("UPDATE tbl_bookings SET payment_type = 'CASH / OFFICE', amount_paid = 0.00, status = 'pending', bank_name = 'CASH / OFFICE' WHERE id = ?");
                if ($update_stmt) {
                    $update_stmt->bind_param('i', $booking_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }

            $log_action = 'Booking Submitted';
            $log_details = "Booking ID: {$booking_id} | Section: {$section} | Location: {$grave_location} | Preferred Date: {$preferred_date} | Contact: {$contact_number} | Booker: {$customer_name} | Deceased: {$deceased_name} | Price: ₱" . number_format($total_amount, 2) . " | Payment: {$payment_method}";
            $log_stmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, ?, NOW())");
            if ($log_stmt) {
                $log_stmt->bind_param("ssi", $log_action, $log_details, $user_id);
                $log_stmt->execute();
                $log_stmt->close();
            }

            if ($purpose === 'cemetery_service' || !empty($service_type)) {
                $srvType = !empty($service_type) ? $service_type : 'Cemetery Service';
                $srvDetails = "Service booked: {$srvType} for plot {$section}-{$grave_location} | Booking ID: #{$booking_id} | Client: {$customer_name} | Price: ₱" . number_format($total_amount, 2) . " | Payment: {$payment_method}";
                $srvStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('SERVICE_BOOKING', ?, ?, NOW())");
                if ($srvStmt) {
                    $srvStmt->bind_param("si", $srvDetails, $user_id);
                    $srvStmt->execute();
                    $srvStmt->close();
                }
            }

            if ($purpose === 'reserve_grave') {
                $resPlotStr = (!empty($section) && $plot_x > 0 && $plot_y > 0) 
                    ? "Section {$section}, Plot {$plot_x}-{$plot_y}" 
                    : (!empty($grave_location) ? $grave_location : "Section {$section} {$plot}");
                $planLabel = ($payment_plan === 'installment' && $payment_term > 0) 
                    ? "{$payment_term} Months Installment" 
                    : "Full Payment";
                $resDetails = "{$resPlotStr} reservation submitted for {$customer_name} | Pending Admin Approval | OWNER={$customer_name}, PHONE={$contact_number}, EMAIL={$customer_email}, ADDRESS={$booker_location}, BOOKING=#{$booking_id}";
                $resStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('Reservation Pending', ?, ?, NOW())");
                if ($resStmt) {
                    $resStmt->bind_param("si", $resDetails, $user_id);
                    $resStmt->execute();
                    $resStmt->close();
                }
            }

            if ($payment_plan === 'installment' && $payment_term_int > 0 && $purpose === 'reserve_grave') {
                try {
                    if (function_exists('generate_contract_payment_schedule')) {
                        generate_contract_payment_schedule((int)$booking_id, $conn, false);
                    }
                } catch (Throwable $schedEx) {
                    error_log("Notice: Payment schedule generation: " . $schedEx->getMessage());
                }
            }

            if (file_exists(__DIR__ . '/mailer.php')) {
                try {
                    include_once __DIR__ . '/mailer.php';
                    if (function_exists('send_booking_confirmation_email')) {
                        send_booking_confirmation_email($booking_id, $conn);
                    }
                } catch (Throwable $mailEx) {
                    error_log("Notice: Email confirmation delivery: " . $mailEx->getMessage());
                }
            }

            ob_end_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Booking submitted successfully!',
                'booking_id' => $booking_id,
                'plot_price' => $plot_price,
                'total_amount' => $total_amount,
                'downpayment' => round($total_amount * 0.20, 2),
                'initial_payment' => $initial_payment,
                'monthly_fee' => $monthly_fee,
                'amount' => ($payment_plan === 'installment' ? $initial_payment : $total_amount),
                'payment_plan' => $payment_plan,
                'payment_term' => $payment_term,
                'payment_method' => $payment_method,
                'source' => $source,
                'customer_name' => $customer_name,
                'deceased_name' => $deceased_name,
                'remarks' => $remarks,
                'customer_email' => $customer_email,
                'receipt_number' => isset($receipt_num) ? $receipt_num : null
            ]);
        } else {
            ob_end_clean();
            $error_msg = (!empty($prepErr)) ? $prepErr : ((isset($stmt) && $stmt instanceof mysqli_stmt && $stmt->error) ? $stmt->error : ($conn->error ?: 'Unknown database error'));
            echo json_encode(['success' => false, 'message' => 'Error submitting booking: ' . $error_msg]);
        }
        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            try { @$stmt->close(); } catch (Throwable $closeEx) { /* already closed - safe to ignore */ }
        }

    } catch (Throwable $e) {
        ob_end_clean();
        error_log("Booking submission exception: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_booking') {
    header('Content-Type: application/json');
    ob_start();

    try {
        $booking_id = intval($_POST['booking_id'] ?? 0);

        if (empty($booking_id)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Booking ID is required']);
            exit;
        }

        $admin_user_id = $_SESSION['user_id'] ?? 1;
        $res = handle_booking_approval($conn, $booking_id, $admin_user_id);

        ob_end_clean();
        echo json_encode($res);

    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_booking') {
    header('Content-Type: application/json');
    ob_start();

    try {
        $booking_id = $_POST['booking_id'] ?? 0;

        if (empty($booking_id)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Booking ID is required']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE tbl_bookings SET status = 'rejected', payment_status = 'REJECTED' WHERE id = ?");
        if (!$stmt) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Database prepare failed']);
            exit;
        }

        // Capture pre-rejection values for the audit log (payments are preserved - NO REFUND).
        $prevRow = null;
        $prevQ = $conn->query("SELECT user_id, customer_name, full_name, total_amount, amount_paid, remaining_balance, status, payment_status FROM tbl_bookings WHERE id = " . (int)$booking_id . " LIMIT 1");
        if ($prevQ) { $prevRow = $prevQ->fetch_assoc(); }
        $prevStatus = $prevRow ? (string)($prevRow['status'] ?? 'pending') : 'pending';
        $rejTotal = number_format((float)($prevRow['total_amount'] ?? 0), 2);
        $rejPaid = number_format((float)($prevRow['amount_paid'] ?? 0), 2);
        $rejBal = number_format((float)($prevRow['remaining_balance'] ?? 0), 2);
        $rejUserName = trim((string)(!empty($prevRow['customer_name']) ? $prevRow['customer_name'] : ($prevRow['full_name'] ?? '')));

        $stmt->bind_param("i", $booking_id);
        if (!$stmt->execute()) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to update booking']);
            exit;
        }
        $stmt->close();

        $rejReason = trim((string)($_POST['reason'] ?? $_POST['remarks'] ?? ''));
        if ($rejReason === '') $rejReason = 'No reason specified';
        $admin_user_id = (int)($_SESSION['user_id'] ?? 1);

        $upPR = $conn->prepare("UPDATE payment_receipts SET payment_status = 'REJECTED', rejection_reason = ?, verified_by = ?, verified_at = NOW() WHERE booking_id = ? AND (payment_status IS NULL OR UPPER(payment_status) IN ('PENDING', 'FOR VERIFICATION'))");
        if ($upPR) {
            $upPR->bind_param('sii', $rejReason, $admin_user_id, $booking_id);
            $upPR->execute();
            $upPR->close();
        }
        $conn->query("UPDATE guest_reservation_requests SET status = 'Rejected', payment_status = 'REJECTED', admin_notes = '" . $conn->real_escape_string($rejReason) . "', rejected_by = {$admin_user_id}, rejected_at = NOW() WHERE booking_id = " . (int)$booking_id);

        if (file_exists(__DIR__ . '/config/cancellation_helper.php')) {
            require_once __DIR__ . '/config/cancellation_helper.php';
        }
        $rejReleased = []; $rejKept = [];
        if (function_exists('release_booking_plots')) {
            $relRes = release_booking_plots($conn, (int)$booking_id);
            $rejReleased = $relRes['released'] ?? [];
            $rejKept = $relRes['kept'] ?? [];
        }

        $log_stmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, ?, NOW())");
        $log_action = 'Booking Rejected';
        $admin_user_id = (int)($_SESSION['user_id'] ?? 1);
        $adminName = 'Admin';
        $aQ = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = {$admin_user_id} LIMIT 1");
        if ($aQ && ($aRow = $aQ->fetch_assoc())) { $adminName = trim($aRow['first_name'] . ' ' . $aRow['last_name']); }
        $log_details = "Booking #{$booking_id} rejected by {$adminName} (Admin ID: {$admin_user_id}). Previous status: " . strtoupper($prevStatus) . ". New status: REJECTED. Rejection reason: {$rejReason}. " .
            "Client: " . ($rejUserName !== '' ? $rejUserName : 'n/a') . ". Total: ₱{$rejTotal}. Payment preserved: ₱{$rejPaid}. Remaining: ₱{$rejBal}. Refund: NON-REFUNDABLE. " .
            "Plots released: " . (empty($rejReleased) ? 'none' : implode(', ', $rejReleased)) . ". Plots kept (occupied): " . (empty($rejKept) ? 'none' : implode(', ', $rejKept)) . ".";

        $log_stmt->bind_param("ssi", $log_action, $log_details, $admin_user_id);
        $log_stmt->execute();
        $log_stmt->close();
        $rejectReason = $rejReason;

        $rejectReason = trim((string)($_POST['reason'] ?? $_POST['remarks'] ?? ''));
        if (file_exists(__DIR__ . '/includes/notification_helper.php')) {
            require_once __DIR__ . '/includes/notification_helper.php';
            if (function_exists('notify_booking_rejected')) {
                notify_booking_rejected($conn, (int)$booking_id, $rejectReason);
            }
        } elseif (file_exists(__DIR__ . '/mailer.php')) {
            include_once __DIR__ . '/mailer.php';
            if (function_exists('send_booking_status_email')) {
                send_booking_status_email((int)$booking_id, 'Rejected', $conn);
            }
        }

        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Booking rejected successfully']);

    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_booking_details') {
    header('Content-Type: application/json');
    $booking_id = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
    if ($booking_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT b.*,
               CONCAT_WS(' ', u.first_name, u.last_name) AS customer_fullname,
               u.email AS user_email,
               u.phone_no AS user_phone
        FROM tbl_bookings b
        LEFT JOIN tbl_user u ON u.user_id = b.user_id
        WHERE b.id = ? LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $b = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($b) {

            $calc = recalculate_booking_payment_totals($booking_id, $conn);
            $total_amt = (float)$calc['total_amount'];
            $amt_paid = (float)$calc['amount_paid'];
            $remaining_bal = (float)$calc['remaining_balance'];
            $payment_status = $calc['payment_status'];

            $payment_history = [];
            $prStmt = $conn->prepare("SELECT id, amount_paid, payment_method, gcash_reference_number, transaction_reference, or_number, receipt_number, notes, payment_date, payment_status, recorded_by, recorded_by_name, verified_by, verified_at, proof_filename FROM payment_receipts WHERE booking_id = ? ORDER BY id ASC");
            if ($prStmt) {
                $prStmt->bind_param('i', $booking_id);
                $prStmt->execute();
                $prRes = $prStmt->get_result();
                $pIdx = 1;
                while ($pr = $prRes->fetch_assoc()) {
                    $pr['payment_index'] = $pIdx++;
                    $pr['formatted_amount'] = number_format((float)$pr['amount_paid'], 2);
                    $pr['formatted_date'] = $pr['payment_date'] ? date('F j, Y • h:i A', strtotime($pr['payment_date'])) : 'N/A';
                    $pr['display_method'] = !empty($pr['payment_method']) ? $pr['payment_method'] : 'Cash / Office';
                    $pr['display_ref'] = !empty($pr['or_number']) ? $pr['or_number'] : (!empty($pr['receipt_number']) ? $pr['receipt_number'] : ($pr['gcash_reference_number'] ?: ($pr['transaction_reference'] ?: '—')));
                    $pr['display_recorder'] = !empty($pr['recorded_by_name']) ? $pr['recorded_by_name'] : 'Admin';
                    $payment_history[] = $pr;
                }
                $prStmt->close();
            }

            $installment_schedules = [];
            if (strtolower(trim((string)($b['payment_plan'] ?? ''))) === 'installment') {
                $schStmt = $conn->prepare("SELECT id, installment_number, due_date, monthly_amount, paid_amount, remaining_balance, status, paid_at, payment_method, transaction_reference FROM payment_schedules WHERE contract_id = ? ORDER BY installment_number ASC");
                if ($schStmt) {
                    $schStmt->bind_param('i', $booking_id);
                    $schStmt->execute();
                    $schRes = $schStmt->get_result();
                    while ($sch = $schRes->fetch_assoc()) {
                        $sch['formatted_due'] = number_format((float)$sch['monthly_amount'], 2);
                        $sch['formatted_paid'] = number_format((float)$sch['paid_amount'], 2);
                        $sch['formatted_due_date'] = $sch['due_date'] ? date('M j, Y', strtotime($sch['due_date'])) : '—';
                        $installment_schedules[] = $sch;
                    }
                    $schStmt->close();
                }
            }

            $gcash_info = get_gcash_payment_settings();
            $latestPr = !empty($payment_history) ? end($payment_history) : null;
            $proof_file = !empty($b['payment_proof_filename']) ? $b['payment_proof_filename'] : ($latestPr['proof_filename'] ?? '');
            $ref_number = !empty($b['reference_number']) ? $b['reference_number'] : (!empty($b['gcash_reference_number']) ? $b['gcash_reference_number'] : ($latestPr['display_ref'] ?? 'N/A'));
            $payment_date = !empty($b['payment_proof_uploaded_at']) ? $b['payment_proof_uploaded_at'] : ($latestPr['payment_date'] ?? $b['created_at']);

            $status = strtolower($b['status'] ?? 'pending');
            $orig_method = !empty($b['original_payment_method']) ? $b['original_payment_method'] : ($b['payment_type'] ?: 'GCash');

            echo json_encode([
                'success' => true,
                'booking' => [
                    'id' => $b['id'],
                    'customer_name' => (!empty(trim($b['customer_name'] ?? ''))) ? trim($b['customer_name']) : ((!empty(trim($b['full_name'] ?? ''))) ? trim($b['full_name']) : (!empty(trim($b['customer_fullname'] ?? '')) ? trim($b['customer_fullname']) : 'Customer')),
                    'deceased_name' => $b['deceased_name'] ?: '—',
                    'remarks' => $b['remarks'] ?: ($b['special_requests'] ?: 'None'),
                    'email' => (!empty(trim($b['customer_email'] ?? ''))) ? trim($b['customer_email']) : ((!empty(trim($b['email'] ?? ''))) ? trim($b['email']) : (!empty(trim($b['user_email'] ?? '')) ? trim($b['user_email']) : '—')),
                    'contact_number' => (!empty(trim($b['contact_number'] ?? ''))) ? trim($b['contact_number']) : (!empty(trim($b['user_phone'] ?? '')) ? trim($b['user_phone']) : '—'),
                    'section' => $b['section'] ?: '—',
                    'plot' => $b['plot'] ?: ($b['grave_location'] ?: '—'),
                    'grave_location' => $b['grave_location'] ?: '—',
                    'service_type' => $b['service_type'] ?: 'Reserve Grave',
                    'purpose' => $b['purpose'] ?: 'reserve_grave',
                    'booking_type' => $b['booking_type'] ?: 'general',
                    'payment_plan' => $b['payment_plan'] ?: 'full',
                    'payment_term' => (int)($b['payment_term'] ?? 0),
                    'preferred_date' => $b['preferred_date'] ? date('F j, Y', strtotime($b['preferred_date'])) : '—',
                    'booker_location' => $b['booker_location'] ?: '—',
                    'special_requests' => $b['special_requests'] ?: ($b['remarks'] ?: 'None'),
                    'payment_type' => $b['payment_type'] ?: 'GCash',
                    'original_payment_method' => $orig_method,
                    'total_amount' => number_format($total_amt, 2),
                    'raw_total_amount' => $total_amt,
                    'amount_paid' => number_format($amt_paid, 2),
                    'raw_amount_paid' => $amt_paid,
                    'balance' => number_format($remaining_bal, 2),
                    'raw_balance' => $remaining_bal,
                    'reference_number' => $ref_number ?: 'N/A',
                    'payment_status' => $payment_status,
                    'status' => $status,
                    'payment_date' => $payment_date ? date('F j, Y • h:i A', strtotime($payment_date)) : 'N/A',
                    'proof_filename' => $proof_file,
                    'gcash_account_name' => $gcash_info['account_name'],
                    'gcash_number' => $gcash_info['display_number'],
                    'is_guest_booking' => (!empty($b['is_guest_booking']) || empty($b['user_id']) || (int)$b['user_id'] === 0) ? 1 : 0,
                    'user_id' => (int)($b['user_id'] ?? 0),
                    'payment_history' => $payment_history,
                    'installment_schedules' => $installment_schedules
                ]
            ]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Booking record not found']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_office_payment') {
    header('Content-Type: application/json');
    ob_start();

    $booking_id = intval($_POST['booking_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'Cash / Office');
    $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d H:i:s'));
    $or_number = trim($_POST['or_number'] ?? '');
    $reference_number = trim($_POST['reference_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $admin_id = (int)($_SESSION['user_id'] ?? 1);

    $admin_name = 'Admin';
    $uQ = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = $admin_id LIMIT 1");
    if ($uQ && ($uR = $uQ->fetch_assoc())) {
        $aName = trim(($uR['first_name'] ?? '') . ' ' . ($uR['last_name'] ?? ''));
        if (!empty($aName)) {
            $admin_name = $aName;
        }
    }

    $res = record_office_payment($conn, [
        'booking_id' => $booking_id,
        'amount' => $amount,
        'payment_method' => $payment_method,
        'payment_date' => $payment_date,
        'or_number' => $or_number,
        'reference_number' => $reference_number,
        'notes' => $notes,
        'admin_id' => $admin_id,
        'admin_name' => $admin_name
    ]);

    ob_end_clean();
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_booking') {
    header('Content-Type: application/json');
    ob_start();
    $booking_id = intval($_POST['booking_id'] ?? 0);

    if (empty($booking_id)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Booking ID is required']);
        exit;
    }

    $admin_user_id = $_SESSION['user_id'] ?? 1;
    $res = handle_booking_approval($conn, $booking_id, $admin_user_id);
    ob_end_clean();
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_json') {
    header('Content-Type: application/json');
    ob_start();
    try {
        echo json_encode(['success' => true, 'message' => 'JSON test successful', 'timestamp' => date('Y-m-d H:i:s')]);
    } catch (Exception $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'JSON test failed: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_plot_info') {
    header('Content-Type: application/json');
    $section = strtoupper(trim($_POST['section'] ?? ''));
    $plot = trim($_POST['plot'] ?? '');

    if (empty($section) || empty($plot)) {
        echo json_encode(['success' => false, 'message' => 'Section and plot are required']);
        exit;
    }

    $plot_parts = explode('-', $plot);
    $plot_x = (int)($plot_parts[0] ?? 0);
    $plot_y = (int)($plot_parts[1] ?? 0);

    $check = is_plot_owned_or_reserved($conn, $section, $plot_x, $plot_y);
    $is_available = !$check['is_taken'];
    $status_text = $check['status'];

    $plot_price = getPlotPrice($section, $plot_x, $plot_y);
    $plot_type = getPlotType($section, $plot_x, $plot_y);

    echo json_encode([
        'success' => true,
        'available' => $is_available,
        'status' => ucfirst(strtolower($status_text)),
        'plot_price' => $plot_price,
        'plot_type' => $plot_type,
        'owner_name' => $check['owner_name'],
        'message' => $is_available ? 'Plot is available' : ('Plot is already ' . strtolower($status_text))
    ]);
    exit;
}

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_plot_deceased') {
    $section = $_POST['section'] ?? '';
    $plot = $_POST['plot'] ?? '';
    if (empty($section) || empty($plot)) {
      echo json_encode(['success' => false, 'message' => 'Section and plot are required']);
      exit;
    }

    $parts = explode('-', $plot);
    $plot_x = $parts[0] ?? '';
    $plot_y = $parts[1] ?? '';

    $stmt = $conn->prepare("SELECT deceased_id, first_name, middle_name, last_name, bday, date_died, contact_no, section, plot_x, plot_y FROM tbl_deceased WHERE section = ? AND plot_x = ? AND plot_y = ? ORDER BY deceased_id ASC");
    if (!$stmt) {
      echo json_encode(['success' => false, 'message' => 'Database error preparing statement']);
      exit;
    }
    $stmt->bind_param('sss', $section, $plot_x, $plot_y);
    $stmt->execute();
    $result = $stmt->get_result();
    $deceased_list = [];
    while ($row = $result->fetch_assoc()) {
      $full_name = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
      $deceased_list[] = [
        'deceased_id' => $row['deceased_id'],
        'full_name' => $full_name,
        'first_name' => $row['first_name'] ?? '',
        'middle_name' => $row['middle_name'] ?? '',
        'last_name' => $row['last_name'] ?? '',
        'bday' => $row['bday'],
        'date_died' => $row['date_died'],
        'contact_no' => $row['contact_no']
      ];
    }
    $stmt->close();

    if (!empty($deceased_list)) {
      echo json_encode([
        'success' => true,
        'deceased' => $deceased_list[0],
        'deceased_list' => $deceased_list
      ]);
    } else {
      echo json_encode(['success' => false, 'message' => 'No deceased found']);
    }
    exit;
  }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_plots') {
    $section = $_POST['section'] ?? '';

    if (empty($section)) {
        echo json_encode(['success' => false, 'message' => 'Section is required']);
        exit;
    }

    $grid_config = [
        'A' => ['rows' => 8, 'cols' => 10, 'total' => 80],
        'B' => ['rows' => 6, 'cols' => 8, 'total' => 48],
        'C' => ['rows' => 5, 'cols' => 6, 'total' => 30],
        'D' => ['rows' => 4, 'cols' => 6, 'total' => 24],
        'E' => ['rows' => 4, 'cols' => 9, 'total' => 36],
        'F' => ['rows' => 3, 'cols' => 6, 'total' => 18]
    ];

    $config = $grid_config[$section] ?? ['rows' => 4, 'cols' => 6, 'total' => 24];

    $occupied_plots = [];
    $reserved_plots = [];
    $stmt = $conn->prepare("SELECT plot_x, plot_y FROM tbl_deceased WHERE UPPER(TRIM(section)) = ?");
    $stmt->bind_param("s", $section);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $occupied_plots[] = ['plot_x' => (int)$row['plot_x'], 'plot_y' => (int)$row['plot_y']];
    }
    $stmt->close();

    $ownStmt = $conn->prepare("SELECT plot_x, plot_y FROM user_plot_ownership WHERE UPPER(TRIM(section)) = ? AND verification_status = 'VERIFIED'");
    if ($ownStmt) {
        $ownStmt->bind_param("s", $section);
        $ownStmt->execute();
        $oRes = $ownStmt->get_result();
        while ($oRow = $oRes->fetch_assoc()) {
            $px = (int)$oRow['plot_x'];
            $py = (int)$oRow['plot_y'];
            $alreadyOcc = false;
            foreach ($occupied_plots as $occ) {
                if ($occ['plot_x'] === $px && $occ['plot_y'] === $py) { $alreadyOcc = true; break; }
            }
            if (!$alreadyOcc) {
                $reserved_plots[] = ['plot_x' => $px, 'plot_y' => $py];
            }
        }
        $ownStmt->close();
    }
    $taken_count = count($occupied_plots) + count($reserved_plots);

    echo json_encode([
        'success' => true,
        'section' => $section,
        'plots' => $occupied_plots,
        'reserved_plots' => $reserved_plots,
        'grid_config' => $config,
        'total_plots' => $config['total'],
        'occupied_plots' => count($occupied_plots),
        'reserved_plots_count' => count($reserved_plots),
        'available_plots' => max(0, $config['total'] - $taken_count),
        'base_price' => getSectionBasePrice($section)
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'get_section_info') {
    header('Content-Type: application/json');

    $section = $_POST['section'] ?? '';

    if (empty($section)) {
        echo json_encode(['success' => false, 'message' => 'Section is required']);
        exit;
    }

    $occupied_query = $conn->prepare("SELECT COUNT(*) as count FROM tbl_deceased WHERE section = ?");
    $occupied_query->bind_param('s', $section);
    $occupied_query->execute();
    $occupied_result = $occupied_query->get_result();
    $occupied_count = $occupied_result->fetch_assoc()['count'];

    $plots_query = $conn->prepare("SELECT plot_x, plot_y FROM tbl_deceased WHERE section = ?");
    $plots_query->bind_param('s', $section);
    $plots_query->execute();
    $plots_result = $plots_query->get_result();

    $occupied_plots = [];
    while ($row = $plots_result->fetch_assoc()) {
        $occupied_plots[] = $row['plot_x'] . '-' . $row['plot_y'];
    }

    echo json_encode([
        'success' => true,
        'occupied_count' => $occupied_count,
        'occupied_plots' => $occupied_plots
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_confirmation_email') {
    $booking_id = $_POST['booking_id'] ?? '';

    if (empty($booking_id)) {
        echo json_encode(['success' => false, 'message' => 'Booking ID is required']);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT b.*, u.first_name, u.last_name, u.email AS user_email,
               pr.proof_filename AS payment_proof_filename,
               pr.payment_date AS payment_proof_uploaded_at,
               pr.receipt_number AS gcash_reference_number
        FROM tbl_bookings b
        LEFT JOIN tbl_user u ON b.user_id = u.user_id
        LEFT JOIN payment_receipts pr ON pr.booking_id = b.id
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }

    $to = !empty(trim($booking['customer_email'] ?? '')) ? trim($booking['customer_email']) : (!empty(trim($booking['email'] ?? '')) ? trim($booking['email']) : (!empty(trim($booking['user_email'] ?? '')) ? trim($booking['user_email']) : ''));
    $recipient_name = !empty(trim($booking['customer_name'] ?? '')) ? trim($booking['customer_name']) : (!empty(trim($booking['full_name'] ?? '')) ? trim($booking['full_name']) : trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? '')));
    if (empty($recipient_name)) {
        $recipient_name = 'Customer';
    }
    $subject = "Booking Confirmation - Garden of Remembrance";
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .header { background: #7b1113; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .booking-details { background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0; }
            .detail-row { margin: 8px 0; }
            .detail-label { font-weight: bold; width: 150px; display: inline-block; }
            .footer { background: #f1f3f4; padding: 15px; text-align: center; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>🌹 Garden of Remembrance</h1>
            <h2>Booking Confirmation</h2>
        </div>
        <div class='content'>
            <p>Dear " . htmlspecialchars($recipient_name) . ",</p>
            <p>Thank you for your booking. Here are your reservation details:</p>

            <div class='booking-details'>
                <div class='detail-row'>
                    <span class='detail-label'>Booking ID:</span>
                    #" . $booking_id . "
                </div>
                <div class='detail-row'>
                    <span class='detail-label'>Section:</span>
                    " . htmlspecialchars($booking['section']) . "
                </div>
                <div class='detail-row'>
                    <span class='detail-label'>Plot:</span>
                    " . htmlspecialchars($booking['plot']) . "
                </div>
                <div class='detail-row'>
                    <span class='detail-label'>Grave Location:</span>
                    " . htmlspecialchars($booking['grave_location']) . "
                </div>
                <div class='detail-row'>
                    <span class='detail-label'>Preferred Date:</span>
                    " . date('F j, Y', strtotime($booking['preferred_date'])) . "
                </div>
                <div class='detail-row'>
                    <span class='detail-label'>Contact Number:</span>
                    " . htmlspecialchars($booking['contact_number']) . "
                </div>
                <div class='detail-row'>
                    <span class='detail-label'>Payment Method:</span>
                    " . ucfirst(htmlspecialchars($booking['payment_type'])) . "
                </div>
                <div class='detail-row'>
                    <span class='detail-label'>Amount Paid:</span>
                    ₱" . number_format($booking['amount_paid'], 2) . "
                </div>";

    if (!empty($booking['reference_number'])) {
        $message .= "
                <div class='detail-row'>
                    <span class='detail-label'>Reference Number:</span>
                    <code style='background:#f5f5f5;padding:2px 6px;border-radius:3px;'>" . htmlspecialchars($booking['reference_number']) . "</code>
                </div>";
    }

    $proof_file = $booking['payment_proof_filename'] ?? '';
    $proof_date = $booking['payment_proof_uploaded_at'] ?? '';
    $gcash_ref = $booking['gcash_reference_number'] ?? '';

    if (!empty($proof_file)) {
        $message .= "
                <div class='detail-row' style='background:#f9f9f9;padding:10px;border-radius:6px;border-left:3px solid #0a6cff;margin-top:10px;'>
                    <div style='font-weight:600;color:#1565c0;margin-bottom:8px;'>
                        <i class='fas fa-receipt'></i> Payment Proof
                    </div>
                    <div style='font-size:0.9rem;'>
                        <div style='margin-bottom:6px;'>
                            <strong>GCash Reference:</strong><br>
                            <code style='background:#fff;padding:2px 6px;border-radius:3px;'>" . htmlspecialchars($gcash_ref) . "</code>
                        </div>
                        <div style='margin-bottom:6px;'>
                            <strong>Uploaded:</strong> " . date('M j, Y H:i', strtotime($proof_date)) . "
                        </div>
                        <button type='button' class='btn btn-sm btn-outline-primary' onclick='adminViewPaymentProof(" . $booking['id'] . ", \"" . htmlspecialchars($proof_file) . "\")'>
                            <i class='fas fa-image'></i> View Payment Proof
                        </button>
                    </div>
                </div>";
    } else if (!empty($booking['amount_paid']) && $booking['amount_paid'] > 0) {
        $message .= "
                <div class='detail-row' style='background:#f9f9f9;padding:10px;border-radius:6px;border-left:3px solid #ff9800;margin-top:10px;'>
                    <div style='font-weight:600;color:#ff9800;'>
                        <i class='fas fa-info-circle'></i> No payment proof uploaded
                    </div>
                </div>";
    }

    if (!empty($booking['bank_name'])) {
        $message .= "
                <div class='detail-row'>
                    <span class='detail-label'>Bank Name:</span>
                    " . htmlspecialchars($booking['bank_name']) . "
                </div>";
    }

    if (!empty($booking['special_requests'])) {
        $message .= "
                <div class='detail-row'>
                    <span class='detail-label'>Special Requests:</span>
                    " . nl2br(htmlspecialchars($booking['special_requests'])) . "
                </div>";
    }

    $message .= "
            </div>

            <p>Please keep this confirmation for your records. If you have any questions or need to make changes, please contact us.</p>

            <p>Best regards,<br>Garden of Remembrance Team</p>
        </div>
        <div class='footer'>
            <p>© " . date('Y') . " Garden of Remembrance Memorial Park. All rights reserved.</p>
            <p>This is an automated message. Please do not reply to this email.</p>
        </div>
    </body>
    </html>";

    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8" . "\r\n";
    $headers .= "From: Garden of Remembrance <noreply@gardenofremembrance.com>" . "\r\n";

    if (mail($to, $subject, $message, $headers)) {
        echo json_encode(['success' => true, 'message' => 'Confirmation email sent successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error sending confirmation email']);
    }
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>try{if(window.sessionStorage)sessionStorage.setItem('gor_tab_home','dashboard');}catch(e){}</script>
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="includes/admin_header.css">
    <link rel="stylesheet" href="notification_component.css">
    <link rel="stylesheet" href="includes/notification_component.css">
  <style>
    :root {
      --bs-primary: #7b1113;
    }

    /* Modal white styles */
    .modal-content {
      background-color: #ffffff !important;
      color: #000000 !important;
      border-radius: 1.25rem;
      box-shadow: 0 4px 20px rgba(160,58,60,0.15);
      border: none;
      max-height: 90vh;
      overflow-y: auto;
    }

    /* Fix modal z-index to appear in front of page content */
    .modal {
      z-index: 1055 !important;
    }

    .modal-backdrop {
      z-index: 1050 !important;
    }

    .modal-dialog {
      z-index: 1056 !important;
    }

    .modal.show {
      z-index: 1055 !important;
    }

    /* SweetAlert2 must ALWAYS be above all modals and backdrops */
    .swal2-container {
      z-index: 999999 !important;
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

    /* Cemetery Map Styles */
    .cemetery-map {
      background: #f8f9fa;
      border-radius: 12px;
      padding: 1.5rem;
      border: 1px solid #e9ecef;
    }

    .map-header h6 {
      color: #7b1113;
      font-weight: 600;
      margin: 0;
    }

    .map-legend {
      background: white;
      padding: 0.75rem;
      border-radius: 8px;
      border: 1px solid #dee2e6;
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .legend-box {
      width: 20px;
      height: 20px;
      border-radius: 4px;
      border: 1px solid #dee2e6;
    }

    .legend-box.available-plot {
      background: #d4edda;
    }

    /* Map tile styles (used by admin map modal) */
    .sections-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.75rem; }
    .section-tile { background: #fff; border: 2px solid rgba(0,0,0,0.06); padding: 1rem; text-align: center; border-radius: 8px; cursor: pointer; transition: transform .18s ease, box-shadow .18s ease; }
    .section-tile:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
    .plots-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(56px,1fr)); gap: 6px; margin-top: 0.75rem; }
    .plot-cell { padding: 8px; border-radius: 6px; text-align: center; font-size: 0.85rem; cursor: pointer; transition: transform .12s ease; }
    .plot-cell.available { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
    .plot-cell.occupied { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; cursor: pointer; }

    .legend-box.occupied-plot {
      background: #f8d7da;
      border-color: #f5c6cb;
    }

    .legend-box.pathway {
      background: #e2e3e5;
      border-color: #ced4da;
    }

    .legend-box.reserved-plot {
      background: #fff3cd;
      border-color: #ffeaa7;
    }

    .legend-box.premium-plot {
      background: linear-gradient(135deg, #fff3cd, #ffeaa7);
      border-color: #ffc107;
    }

    .map-container {
      background: white;
      padding: 1rem;
      border-radius: 8px;
      border: 1px solid #dee2e6;
      overflow-x: auto;
    }

    .section-map {
      display: grid;
      gap: 2px;
      margin: 0 auto;
      width: fit-content;
    }

    .map-plot {
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-weight: 600;
      border-radius: 4px;
      cursor: default;
      transition: all 0.2s ease;
      border: 1px solid #dee2e6;
      position: relative;
    }

    .map-plot.available-plot {
      background: #d4edda;
      color: #155724;
      border-color: #c3e6cb;
    }

    .map-plot.available-plot:hover {
      background: #c3e6cb;
      transform: scale(1.1);
      z-index: 10;
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }

    .map-plot.occupied-plot {
      background: #f8d7da;
      color: #721c24;
      border-color: #f5c6cb;
    }

    .map-plot.pathway {
      background: #e2e3e5;
      color: #6c757d;
      border-color: #ced4da;
      font-size: 14px;
    }

    .map-plot.reserved-plot {
      background: #fff3cd;
      color: #856404;
      border-color: #ffeaa7;
    }

    .map-plot.premium-plot {
      background: linear-gradient(135deg, #fff3cd, #ffeaa7);
      color: #856404;
      border-color: #ffc107;
      box-shadow: 0 0 0 1px rgba(255, 193, 7, 0.25);
    }

    .map-plot.premium-plot:hover {
      background: linear-gradient(135deg, #ffeaa7, #ffd43b);
      transform: scale(1.1);
      z-index: 10;
      box-shadow: 0 2px 8px rgba(255, 193, 7, 0.3);
    }

    /* Stat Cards matching User Dashboard */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.25rem;
      margin-bottom: 1.75rem;
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
      text-align: left;
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

    .stat-card.info-metric-card {
      border-left: 4px solid #0284c7;
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
      margin-bottom: 0;
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

    .stat-icon.info-metric-icon {
      background: #e0f2fe;
      color: #0284c7;
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

    /* Enhanced Info Cards */
    .info-card {
      background: white;
      border-radius: 12px;
      padding: 1.5rem;
      border: 1px solid #e9ecef;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
      height: 100%;
    }

    .info-title {
      color: #7b1113;
      font-weight: 600;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .info-icon {
      font-size: 1.2rem;
    }

    .info-content p {
      margin-bottom: 0.5rem;
      color: #495057;
    }

    /* Enhanced Note Section */
    .note-content {
      display: flex;
      align-items: flex-start;
      gap: 1rem;
      background: linear-gradient(135deg, #e7f3ff, #f8f9fa);
      border-radius: 12px;
      padding: 1.5rem;
      border: 1px solid #b3d9ff;
    }

    .note-icon {
      font-size: 1.5rem;
      color: #0066cc;
      margin-top: 0.25rem;
    }

    .note-text {
      flex: 1;
    }

    .note-text ul {
      margin: 0;
      padding-left: 1.5rem;
      color: #495057;
    }

    .note-text li {
      margin-bottom: 0.5rem;
    }

    .map-info {
      background: white;
      padding: 1rem;
      border-radius: 8px;
      border: 1px solid #dee2e6;
    }

    .stat-box {
      padding: 0.75rem;
      background: #f8f9fa;
      border-radius: 8px;
      border: 1px solid #e9ecef;
    }

    .stat-number {
      font-size: 1.5rem;
      font-weight: 700;
      margin-bottom: 0.25rem;
    }

    .stat-label {
      font-size: 0.875rem;
      color: #6c757d;
      font-weight: 500;
    }

    .map-note {
      background: #e7f3ff;
      padding: 0.75rem;
      border-radius: 8px;
      border: 1px solid #b3d9ff;
    }

    /* Responsive adjustments for map */
    @media (max-width: 768px) {
      .section-map {
        grid-template-columns: repeat(auto-fit, minmax(30px, 1fr)) !important;
        grid-template-rows: repeat(auto-fit, minmax(30px, 1fr)) !important;
      }

      .map-plot {
        font-size: 9px;
        border-radius: 3px;
      }

      .stat-number {
        font-size: 1.25rem;
      }

      .legend-item {
        margin-bottom: 0.25rem;
      }
    }

    /* Mobile modal adjustments */
    @media (max-width: 768px) {
      .modal-dialog {
        margin: 10px;
        max-height: calc(100vh - 20px);
      }

      .modal-content {
        max-height: calc(100vh - 20px);
        border-radius: 0.75rem;
      }

      .modal-body {
        max-height: calc(100vh - 140px);
        overflow-y: auto;
        padding: 1rem;
      }

      .modal-header {
        padding: 0.75rem 1rem;
      }

      .modal-lg .modal-body {
        padding: 0.75rem;
      }

      .modal-lg .card {
        margin-bottom: 0.5rem;
      }

      .modal-lg .card-body {
        padding: 0.75rem;
      }

      .modal-lg .card-title {
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
      }

      .modal-lg .card-text {
        font-size: 0.8rem;
        margin-bottom: 0;
      }

      /* Table header styling - keep headers strictly in one line */
      .table thead th,
      .mobile-card-table thead th {
        white-space: nowrap !important;
        vertical-align: middle !important;
        word-break: keep-all !important;
      }

      .table thead th i,
      .mobile-card-table thead th i {
        display: inline-block !important;
        vertical-align: middle !important;
      }

      .table thead th span,
      .mobile-card-table thead th span {
        white-space: nowrap !important;
      }

      /* Compact table on mobile */
      .table {
        font-size: 0.8rem;
        margin-bottom: 0;
      }

      .table th,
      .table td {
        padding: 4px 6px;
        vertical-align: middle;
      }
    }

    .modal-header {
      background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important;
      color: #ffffff !important;
      border-bottom: 1px solid rgba(255, 255, 255, 0.15) !important;
    }

    .modal-header .modal-title,
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

    .modal-body {
      background-color: #ffffff !important;
      color: #1e293b !important;
    }

    .modal-footer {
      background-color: #ffffff !important;
      color: #1e293b !important;
      border-top: 1px solid #dee2e6;
    }

    .form-label {
      color: #000000 !important;
    }

    .form-control {
      background-color: #ffffff !important;
      color: #000000 !important;
      border-color: #ced4da;
    }

    .form-control:focus {
      background-color: #ffffff !important;
      color: #000000 !important;
      border-color: #7b1113;
      box-shadow: 0 0 0 0.2rem rgba(123, 17, 19, 0.25);
    }
    html, body {
      margin: 0 !important;
      padding: 0 !important;
    }
    body {
      font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
      background-color: #f8f9fa;
      margin: 0 !important;
      padding: 0 !important;
    }
    .main {
      margin-left: 0;
      padding: 2rem 1.5rem 3rem;
      margin-top: 0 !important;
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
    /* Table headers single line enforcement */
    .table thead th,
    .mobile-card-table thead th {
      white-space: nowrap !important;
      vertical-align: middle !important;
      word-break: keep-all !important;
    }
    .table thead th i,
    .mobile-card-table thead th i {
      display: inline-block !important;
      vertical-align: middle !important;
    }
    .table thead th span,
    .mobile-card-table thead th span {
      white-space: nowrap !important;
    }
    .btn-primary:hover, .bg-primary:hover {
      background-color: #a03a3c !important;
      border-color: #a03a3c !important;
    }
    .text-primary {
      color: #7b1113 !important;
    }
    /* Blink highlight for search results */
    @keyframes rowBlink {
      0% { background-color: transparent; box-shadow: none; }
      50% { background-color: #fff7d6; box-shadow: inset 0 0 0 4px rgba(33,150,243,0.18); }
      100% { background-color: transparent; box-shadow: none; }
    }
    .blink-once {
      animation: rowBlink 1.2s ease-in-out 0s 1;
    }

    /* Dashboard Hero, Search & Card Styles */
    .hero-heading {
      color: #7b1113;
      font-weight: 700;
      letter-spacing: 0.5px;
      font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
      text-align: center;
      font-size: clamp(1.35rem, 4vw, 1.95rem);
      margin-bottom: 0.35rem;
    }

    .hero-subheading {
      color: #a03a3c;
      font-size: clamp(0.92rem, 2.5vw, 1.12rem);
      letter-spacing: 0.3px;
      font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
      text-align: center;
    }

    .dashboard-search-box {
      width: 100%;
      max-width: 460px;
      margin: 0 auto;
    }

    .search-capsule-wrapper {
      background: #ffffff;
      border: 1px solid #d1d5db;
      border-radius: 2rem;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      transition: all 0.2s ease;
      overflow: visible;
      position: relative;
    }

    .search-capsule-wrapper:focus-within {
      border-color: #7b1113;
      box-shadow: 0 4px 16px rgba(123, 17, 19, 0.15);
    }

    .btn-search-filter {
      border: none;
      background: transparent;
      color: #64748b;
      padding: 0.55rem 0.85rem 0.55rem 1rem;
      font-size: 0.95rem;
      cursor: pointer;
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      border-radius: 2rem 0 0 2rem;
    }

    .btn-search-filter:hover, .btn-search-filter:focus {
      color: #7b1113;
      background: #fdf2f2;
    }

    .filter-active-dot {
      position: absolute;
      top: 6px;
      right: 7px;
      width: 8px;
      height: 8px;
      background-color: #dc2626;
      border-radius: 50%;
      border: 1.5px solid #ffffff;
      animation: pulse-dot 2s infinite;
    }

    .search-capsule-wrapper .form-control.search-field {
      border: none !important;
      border-radius: 0 !important;
      box-shadow: none !important;
      font-size: 0.95rem;
      padding: 0.55rem 0.6rem;
      flex: 1;
      background: transparent;
    }

    /* Suppress duplicate native browser clear button on search inputs */
    .search-capsule-wrapper .form-control.search-field::-webkit-search-cancel-button,
    .search-capsule-wrapper .form-control.search-field::-webkit-search-decoration,
    .search-capsule-wrapper .form-control.search-field::-webkit-search-results-button,
    .search-capsule-wrapper .form-control.search-field::-webkit-search-results-decoration,
    input[type="search"]::-webkit-search-cancel-button,
    input[type="search"]::-webkit-search-decoration,
    input[type="search"]::-webkit-search-results-button,
    input[type="search"]::-webkit-search-results-decoration {
      -webkit-appearance: none !important;
      appearance: none !important;
      display: none !important;
      margin: 0 !important;
      padding: 0 !important;
    }

    .btn-clear-search {
      border: none;
      background: transparent;
      padding: 0 0.5rem;
      font-size: 0.85rem;
      color: #94a3b8;
      display: flex;
      align-items: center;
      text-decoration: none;
      cursor: pointer;
    }
    .btn-clear-search:hover {
      color: #1e293b;
    }

    .search-capsule-wrapper .btn-search {
      border-radius: 0 2rem 2rem 0 !important;
      padding: 0 1.2rem;
      min-width: 48px;
      min-height: 44px;
      background-color: #7b1113 !important;
      border: none !important;
      color: #ffffff !important;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background-color 0.2s ease;
    }

    .search-capsule-wrapper .btn-search:hover {
      background-color: #a03a3c !important;
    }

    .filter-popover-menu {
      animation: fadeInPopover 0.15s ease-out;
    }

    @keyframes fadeInPopover {
      from { opacity: 0; transform: translateY(-6px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .deceased-card-container {
      width: 100%;
      max-width: 1180px;
      margin: 2.25rem auto 0;
    }

    @media (min-width: 992px) {
      .deceased-card-container .table-responsive {
        overflow-x: visible !important;
      }
    }

    .view-map-pill-btn {
      border-radius: 2rem;
      padding: 0.5rem 1.5rem;
      font-weight: 500;
      letter-spacing: 0.5px;
      gap: 0.5rem;
      display: inline-flex;
      align-items: center;
      background-color: #7b1113 !important;
      border-color: #7b1113 !important;
      color: #ffffff !important;
      transition: all 0.2s ease;
    }

    .view-map-pill-btn:hover {
      background-color: #a03a3c !important;
      border-color: #a03a3c !important;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(123, 17, 19, 0.25);
    }

    /* Mobile Responsive Dashboard Adjustments */
    @media (max-width: 768px) {
      .main {
        padding: 1.25rem 0.75rem 2.5rem !important;
        margin-top: 0 !important;
      }

      .center-content {
        margin-top: 15px !important;
        min-height: auto !important;
      }

      .deceased-card-container {
        margin-top: 1.5rem !important;
      }

      .deceased-card-container .card {
        border-radius: 1rem !important;
      }

      .deceased-card-container .card-body {
        padding: 1rem 0.85rem !important;
      }

      .modal-dialog {
        margin: 0.75rem;
        max-width: calc(100% - 1.5rem);
      }

      .modal-body {
        padding: 1rem;
      }

      #popupSectionModal .modal-dialog {
        max-width: 100vw !important;
        margin: 0.25rem !important;
        height: calc(100vh - 0.5rem) !important;
      }
      #popupSectionModal .modal-content {
        height: 100% !important;
        border-radius: 12px !important;
      }
      #popupSectionModal .modal-header {
        flex-wrap: wrap !important;
        gap: 0.5rem !important;
        padding: 0.75rem !important;
      }
      #popupSectionModal .modal-body {
        height: calc(100vh - 120px) !important;
        min-height: 420px !important;
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
  </style>
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
</head>
<body>

<?php
$demo_gc = get_gcash_payment_settings();
?>

<script>
// Client-side date validation function (globally accessible) - MOVED HERE FIRST
function validatePreferredDateClient(dateString) {
    var input = document.getElementById('preferredDate');

    // First, check if the input looks like it could have more than 4 digits in year
    if (dateString.length > 10) {
        showDateError('Date must be exactly 10 characters in YYYY-MM-DD format with 4-digit year.');
        return false;
    }

    // Check if date is in YYYY-MM-DD format (exactly 4 digits for year)
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateString)) {
        if (dateString.length === 10) {
            showDateError('Date must be in YYYY-MM-DD format with exactly 4 digits for the year (e.g., 2024-03-16).');
        } else {
            showDateError('Date must be exactly 10 characters: YYYY-MM-DD (4 digits for year).');
        }
        return false;
    }

    // Parse date components
    var dateParts = dateString.split('-');
    var yearString = dateParts[0];
    var year = parseInt(dateParts[0]);
    var month = parseInt(dateParts[1]);
    var day = parseInt(dateParts[2]);

    // Strict validation: ensure year is exactly 4 digits
    if (yearString.length !== 4) {
        showDateError('Year must be exactly 4 digits (e.g., 2024), not ' + yearString.length + ' digits.');
        return false;
    }

    // Additional check: ensure no leading zeros that would suggest more digits were intended
    if (yearString.startsWith('0') && yearString.length === 4) {
        showDateError('Year cannot start with zero. Please enter a valid 4-digit year (e.g., 2024).');
        return false;
    }

    // Check year range
    if (year < 1900 || year > 2100) {
        showDateError('Year must be between 1900 and 2100, not ' + year + '.');
        return false;
    }

    // Check if it's a valid date
    var date = new Date(year, month - 1, day);
    if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
        showDateError('Invalid date. Please enter a valid calendar date.');
        return false;
    }

    // Enhanced past date validation with specific messages
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    date.setHours(0, 0, 0, 0);

    if (date < today) {
        var todayYear = today.getFullYear();
        var todayMonth = today.getMonth() + 1; // JavaScript months are 0-based
        var todayDay = today.getDate();

        if (year < todayYear) {
            showDateError('Year ' + year + ' is in the past. Please select ' + todayYear + ' or a future year.');
        } else if (year === todayYear && month < todayMonth) {
            var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                              'July', 'August', 'September', 'October', 'November', 'December'];
            showDateError(monthNames[month - 1] + ' ' + year + ' is in the past. Please select ' + monthNames[todayMonth - 1] + ' ' + todayYear + ' or a future date.');
        } else if (year === todayYear && month === todayMonth && day < todayDay) {
            showDateError('Day ' + day + ' of ' + month + '/' + year + ' is in the past. Please select today (' + todayDay + '/' + month + '/' + year + ') or a future date.');
        } else {
            showDateError('Preferred date cannot be in the past. Please select today or a future date.');
        }
        return false;
    }

    // Check if date is too far in the future
    var maxFuture = new Date();
    maxFuture.setFullYear(maxFuture.getFullYear() + 5);
    maxFuture.setHours(0, 0, 0, 0);

    if (date > maxFuture) {
        showDateError('Preferred date cannot be more than 5 years in the future.');
        return false;
    }

    // Clear any error if validation passes
    input.classList.remove('is-invalid');
    var feedback = input.parentNode.querySelector('.invalid-feedback');
    if (feedback) {
        feedback.remove();
    }

    return true;
}

// Function to show date validation error (globally accessible) - MOVED HERE TOO
function showDateError(message) {
    var input = document.getElementById('preferredDate');
    input.classList.add('is-invalid');

    // Remove any existing error message
    var existingFeedback = input.parentNode.querySelector('.invalid-feedback');
    if (existingFeedback) {
        existingFeedback.remove();
    }

    // Add new error message
    var feedback = document.createElement('div');
    feedback.className = 'invalid-feedback';
    feedback.style.display = 'block';
    feedback.textContent = message;
    input.parentNode.appendChild(feedback);

    // Focus the input
    input.focus();
}
</script>

<?php
$active_nav = 'dashboard';
include __DIR__ . '/includes/admin_header.php';
?>

<div class="modal fade" id="contactModal" tabindex="-1" aria-labelledby="contactModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="contactModalLabel">Contact</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p><strong>Email:</strong> richardjamestolibas@gmail.com</p>
        <p><strong>Phone:</strong> 0960-529-5663 / 0928-864-2520</p>
        <p><strong>Address:</strong> Garden of Remembrance Memorial Park, De Guzman Street, Barangay Palamis, Alaminos City, Pangasinan 2404, Philippines</p>
        <p><strong>Office Hours:</strong> Monday – Friday: 8:00 AM – 5:00 PM | Saturday: 8:00 AM – 3:00 PM (Sunday: CLOSED)</p>
      </div>
    </div>
  </div>
</div>

<?php
if (file_exists(__DIR__ . '/includes/admin_announcement_modal.php')) {
    include_once __DIR__ . '/includes/admin_announcement_modal.php';
} elseif (file_exists(__DIR__ . '/admin_announcement_modal.php')) {
    include_once __DIR__ . '/admin_announcement_modal.php';
}
?>

<script>
// Expose the global handler used by the inline button.
function handleBookingSubmit(event) {
    if (event && typeof event.preventDefault === 'function') {
        event.preventDefault();
    }

    var fn = (typeof submitBooking === 'function') ? submitBooking : (window.submitBooking || null);
    if (typeof fn === 'function') {
        return fn();
    }

    alert('Booking submission is unavailable right now. Please refresh the page and try again.');
    return false;
}

window.handleBookingSubmit = handleBookingSubmit;

function showNotification(message, type) {
    type = type || 'info';
    var existingNotifications = document.querySelectorAll('.notification-toast');
    existingNotifications.forEach(function(notification) {
      notification.remove();
    });

    var notification = document.createElement('div');
    notification.className = 'notification-toast';
    notification.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;min-width:300px;padding:1rem 1.5rem;border-radius:0.5rem;box-shadow:0 4px 12px rgba(0,0,0,0.15);display:flex;align-items:center;gap:0.75rem;animation:slideIn 0.3s ease-out;font-weight:500;';

    var colors = {
      success: 'background:linear-gradient(135deg, #28a745, #20c997); color:white;',
      error: 'background:linear-gradient(135deg, #dc3545, #c82333); color:white;',
      warning: 'background:linear-gradient(135deg, #ffc107, #e0a800); color:#212529;',
      info: 'background:linear-gradient(135deg, #17a2b8, #138496); color:white;'
    };
    var icons = {
      success: '✅',
      error: '❌',
      warning: '⚠️',
      info: 'ℹ️'
    };

    notification.style.cssText += colors[type] || colors.info;
    notification.innerHTML = '<span style="font-size:1.25rem;">' + (icons[type] || icons.info) + '</span>' +
      '<div><div>' + message + '</div><small style="opacity:0.9;">' + new Date().toLocaleTimeString() + '</small></div>';

    document.body.appendChild(notification);

    setTimeout(function() {
      if (notification.parentNode) {
        notification.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(function() {
          if (notification.parentNode) {
            notification.remove();
          }
        }, 300);
      }
    }, 5000);

    notification.addEventListener('click', function() {
      notification.style.animation = 'slideOut 0.3s ease-out';
      setTimeout(function() {
        if (notification.parentNode) {
          notification.remove();
        }
      }, 300);
    });
}

<?php
$sections_summary = [];
$dash_kpi_plots = ['total' => 0, 'available' => 0, 'occupied' => 0, 'reserved' => 0, 'occupancy_rate' => 0];
$dash_kpi_deceased = ['total' => 0, 'this_year' => 0, 'this_month' => 0];
$dash_kpi_bookings = ['total' => 0, 'confirmed' => 0, 'pending' => 0, 'cancelled' => 0, 'total_paid' => 0.0, 'total_balance' => 0.0, 'total_sales' => 0.0];
$dash_kpi_users = ['total' => 0, 'admins' => 0, 'clients' => 0];
$dash_pending_alerts = ['bookings' => 0, 'guest_requests' => 0, 'claims' => 0, 'transfers' => 0];
$dash_chart_sections = ['labels' => [], 'available' => [], 'occupied' => [], 'reserved' => [], 'total' => []];
$dash_chart_monthly = ['labels' => [], 'bookings' => [], 'revenue' => []];
$dash_chart_services = ['plot_sales' => 0, 'services' => 0];
$dash_recent_bookings = [];
$dash_recent_deceased = [];

try {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $sec_query = $conn->query("SELECT s.section_name, s.lawn_type, s.capacity, s.base_price,
            (SELECT COUNT(*) FROM tbl_plots p WHERE UPPER(TRIM(p.section)) = UPPER(TRIM(s.section_name))) AS total_plots,
            (SELECT COUNT(*) FROM tbl_plots p WHERE UPPER(TRIM(p.section)) = UPPER(TRIM(s.section_name)) AND LOWER(p.status) = 'available') AS available_plots,
            (SELECT COUNT(*) FROM tbl_plots p WHERE UPPER(TRIM(p.section)) = UPPER(TRIM(s.section_name)) AND LOWER(p.status) = 'occupied') AS occupied_plots,
            (SELECT COUNT(*) FROM tbl_plots p WHERE UPPER(TRIM(p.section)) = UPPER(TRIM(s.section_name)) AND LOWER(p.status) = 'reserved') AS reserved_plots
          FROM sections s ORDER BY s.section_name ASC");

        if ($sec_query) {
            while ($sec_row = $sec_query->fetch_assoc()) {
                $sec_name = strtoupper(trim($sec_row['section_name']));
                $sections_summary[$sec_name] = [
                    'section' => $sec_name,
                    'lawn_type' => $sec_row['lawn_type'] ?: ('Lawn Type ' . $sec_name),
                    'base_price' => (float)$sec_row['base_price'],
                    'total' => (int)$sec_row['total_plots'] ?: (int)$sec_row['capacity'],
                    'available' => (int)$sec_row['available_plots'],
                    'occupied' => (int)$sec_row['occupied_plots'],
                    'reserved' => (int)$sec_row['reserved_plots']
                ];
                $dash_chart_sections['labels'][] = 'Section ' . $sec_name;
                $dash_chart_sections['available'][] = (int)$sec_row['available_plots'];
                $dash_chart_sections['occupied'][] = (int)$sec_row['occupied_plots'];
                $dash_chart_sections['reserved'][] = (int)$sec_row['reserved_plots'];
                $dash_chart_sections['total'][] = (int)$sec_row['total_plots'] ?: (int)$sec_row['capacity'];
            }
        }

        // 1. Plot Inventory KPIs
        $qPlots = $conn->query("SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN LOWER(TRIM(status)) = 'available' THEN 1 ELSE 0 END) AS available,
            SUM(CASE WHEN LOWER(TRIM(status)) = 'occupied' THEN 1 ELSE 0 END) AS occupied,
            SUM(CASE WHEN LOWER(TRIM(status)) = 'reserved' THEN 1 ELSE 0 END) AS reserved
            FROM tbl_plots");
        if ($qPlots && $rP = $qPlots->fetch_assoc()) {
            $dash_kpi_plots['total'] = (int)($rP['total'] ?? 0);
            $dash_kpi_plots['available'] = (int)($rP['available'] ?? 0);
            $dash_kpi_plots['occupied'] = (int)($rP['occupied'] ?? 0);
            $dash_kpi_plots['reserved'] = (int)($rP['reserved'] ?? 0);
            if ($dash_kpi_plots['total'] > 0) {
                $dash_kpi_plots['occupancy_rate'] = round((($dash_kpi_plots['occupied'] + $dash_kpi_plots['reserved']) / $dash_kpi_plots['total']) * 100, 1);
            }
        }

        // 2. Deceased Interments KPIs
        $qDec = $conn->query("SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN YEAR(COALESCE(NULLIF(interment_date, '0000-00-00'), date_died)) = YEAR(NOW()) THEN 1 ELSE 0 END) AS this_year,
            SUM(CASE WHEN YEAR(COALESCE(NULLIF(interment_date, '0000-00-00'), date_died)) = YEAR(NOW()) AND MONTH(COALESCE(NULLIF(interment_date, '0000-00-00'), date_died)) = MONTH(NOW()) THEN 1 ELSE 0 END) AS this_month
            FROM tbl_deceased");
        if ($qDec && $rD = $qDec->fetch_assoc()) {
            $dash_kpi_deceased['total'] = (int)($rD['total'] ?? 0);
            $dash_kpi_deceased['this_year'] = (int)($rD['this_year'] ?? 0);
            $dash_kpi_deceased['this_month'] = (int)($rD['this_month'] ?? 0);
        }

        // 3. Bookings & Financial KPIs
        $qBk = $conn->query("SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN LOWER(TRIM(status)) IN ('approved', 'confirmed', 'completed') THEN 1 ELSE 0 END) AS confirmed,
            SUM(CASE WHEN LOWER(TRIM(status)) = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN LOWER(TRIM(status)) IN ('cancelled', 'rejected') THEN 1 ELSE 0 END) AS cancelled,
            COALESCE(SUM(amount_paid), 0) AS total_paid,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) IN ('approved', 'confirmed', 'completed', 'pending') THEN remaining_balance ELSE 0 END), 0) AS total_balance,
            COALESCE(SUM(total_amount), 0) AS total_sales
            FROM tbl_bookings");
        if ($qBk && $rB = $qBk->fetch_assoc()) {
            $dash_kpi_bookings['total'] = (int)($rB['total'] ?? 0);
            $dash_kpi_bookings['confirmed'] = (int)($rB['confirmed'] ?? 0);
            $dash_kpi_bookings['pending'] = (int)($rB['pending'] ?? 0);
            $dash_kpi_bookings['cancelled'] = (int)($rB['cancelled'] ?? 0);
            $dash_kpi_bookings['total_paid'] = (float)($rB['total_paid'] ?? 0);
            $dash_kpi_bookings['total_balance'] = (float)($rB['total_balance'] ?? 0);
            $dash_kpi_bookings['total_sales'] = (float)($rB['total_sales'] ?? 0);
        }
        $dash_pending_alerts['bookings'] = $dash_kpi_bookings['pending'];

        // 4. User Accounts KPIs
        $qUsr = $conn->query("SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN UPPER(TRIM(role)) = 'ADMIN' THEN 1 ELSE 0 END) AS admins,
            SUM(CASE WHEN UPPER(TRIM(role)) != 'ADMIN' OR role IS NULL THEN 1 ELSE 0 END) AS clients
            FROM tbl_user");
        if ($qUsr && $rU = $qUsr->fetch_assoc()) {
            $dash_kpi_users['total'] = (int)($rU['total'] ?? 0);
            $dash_kpi_users['admins'] = (int)($rU['admins'] ?? 0);
            $dash_kpi_users['clients'] = (int)($rU['clients'] ?? 0);
        }

        // 5. Operational Alerts
        $qGrr = $conn->query("SELECT COUNT(*) AS c FROM guest_reservation_requests WHERE LOWER(TRIM(status)) = 'pending'");
        if ($qGrr && $rGrr = $qGrr->fetch_assoc()) {
            $dash_pending_alerts['guest_requests'] = (int)($rGrr['c'] ?? 0);
        }
        $qClm = $conn->query("SELECT COUNT(*) AS c FROM user_plot_ownership WHERE LOWER(TRIM(verification_status)) = 'pending'");
        if ($qClm && $rClm = $qClm->fetch_assoc()) {
            $dash_pending_alerts['claims'] = (int)($rClm['c'] ?? 0);
        }
        $qTrf = $conn->query("SELECT COUNT(*) AS c FROM transfer_requests WHERE LOWER(TRIM(status)) = 'pending'");
        if ($qTrf && $rTrf = $qTrf->fetch_assoc()) {
            $dash_pending_alerts['transfers'] = (int)($rTrf['c'] ?? 0);
        }

        // 6. Monthly Booking & Revenue Trends (Last 6 Months)
        $qMo = $conn->query("SELECT 
            DATE_FORMAT(COALESCE(booking_date, created_at, NOW()), '%b %Y') AS month_label,
            YEAR(COALESCE(booking_date, created_at, NOW())) AS yr,
            MONTH(COALESCE(booking_date, created_at, NOW())) AS mo,
            COUNT(*) AS b_count,
            COALESCE(SUM(amount_paid), 0) AS m_revenue
            FROM tbl_bookings
            WHERE COALESCE(booking_date, created_at) >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY yr, mo, month_label
            ORDER BY yr ASC, mo ASC");
        if ($qMo && $qMo->num_rows > 0) {
            while ($rM = $qMo->fetch_assoc()) {
                $dash_chart_monthly['labels'][] = $rM['month_label'];
                $dash_chart_monthly['bookings'][] = (int)$rM['b_count'];
                $dash_chart_monthly['revenue'][] = (float)$rM['m_revenue'];
            }
        }
        if (empty($dash_chart_monthly['labels'])) {
            for ($i = 5; $i >= 0; $i--) {
                $dash_chart_monthly['labels'][] = date('M Y', strtotime("-$i month"));
                $dash_chart_monthly['bookings'][] = 0;
                $dash_chart_monthly['revenue'][] = 0;
            }
        }

        // 7. Service Types Breakdown
        $qSrv = $conn->query("SELECT 
            SUM(CASE WHEN LOWER(TRIM(COALESCE(purpose, ''))) = 'cemetery_service' OR LOWER(TRIM(COALESCE(service_type, ''))) IN ('cleaning', 'landscaping', 'maintenance', 'decoration') THEN 1 ELSE 0 END) AS srv_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(purpose, ''))) != 'cemetery_service' AND (service_type IS NULL OR LOWER(TRIM(service_type)) NOT IN ('cleaning', 'landscaping', 'maintenance', 'decoration')) THEN 1 ELSE 0 END) AS plot_count
            FROM tbl_bookings");
        if ($qSrv && $rS = $qSrv->fetch_assoc()) {
            $dash_chart_services['services'] = (int)($rS['srv_count'] ?? 0);
            $dash_chart_services['plot_sales'] = (int)($rS['plot_count'] ?? 0);
        }

        // 8. Recent 5 Bookings
        $qRB = $conn->query("SELECT id, COALESCE(NULLIF(customer_name,''), full_name, 'Guest') AS customer_name, section, plot, total_amount, amount_paid, status, COALESCE(booking_date, created_at, NOW()) AS b_date, service_type, purpose 
            FROM tbl_bookings ORDER BY id DESC LIMIT 5");
        if ($qRB) {
            while ($rowRB = $qRB->fetch_assoc()) {
                $dash_recent_bookings[] = $rowRB;
            }
        }

        // 9. Recent 5 Deceased Interments
        $qRD = $conn->query("SELECT deceased_id, CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(middle_name,''), NULLIF(last_name,''), NULLIF(suffix,'')) AS full_name, section, plot_x, plot_y, COALESCE(NULLIF(interment_date, '0000-00-00'), date_died) AS burial_date 
            FROM tbl_deceased ORDER BY deceased_id DESC LIMIT 5");
        if ($qRD) {
            while ($rowRD = $qRD->fetch_assoc()) {
                $dash_recent_deceased[] = $rowRD;
            }
        }
    }
} catch (Throwable $secEx) {
    error_log('Dashboard analytics data query notice: ' . $secEx->getMessage());
}
?>
<script>
window.__sectionsData = <?= json_encode($sections_summary) ?>;

function updateSectionInfo() {
    var secSelect = document.getElementById('section');
    var section = secSelect ? String(secSelect.value || '').toUpperCase().trim() : '';
    var sectionInfoDiv = document.getElementById('sectionInfo');
    if (!sectionInfoDiv) return;

    if (section && window.__sectionsData && window.__sectionsData[section]) {
        var info = window.__sectionsData[section];
        var priceFormatted = '₱' + Number(info.base_price || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        sectionInfoDiv.innerHTML = '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">' +
            '<div>' +
                '<div class="d-flex align-items-center gap-2">' +
                    '<h6 class="mb-0 fw-bold" style="color:#7b1113;">Section ' + info.section + ' — ' + info.lawn_type + '</h6>' +
                    '<span class="badge bg-success">' + info.available + ' Available</span>' +
                '</div>' +
                '<div class="small text-muted mt-1">' +
                    '<span class="me-3">📦 Total Plots: <strong>' + info.total + '</strong></span>' +
                    '<span class="me-3">🔴 Occupied: <strong>' + info.occupied + '</strong></span>' +
                    '<span>🟡 Reserved: <strong>' + info.reserved + '</strong></span>' +
                '</div>' +
            '</div>' +
            '<div class="text-end">' +
                '<div class="small text-muted" style="font-size:0.75rem;">Standard Price</div>' +
                '<div class="fw-bold text-success fs-6">' + priceFormatted + '</div>' +
            '</div>' +
        '</div>';
    } else if (section) {
        sectionInfoDiv.innerHTML = '<div class="d-flex justify-content-between align-items-center">' +
            '<div><strong style="color:#7b1113;">Section ' + section + '</strong><br><small class="text-muted">Section selected for booking.</small></div>' +
            '<span class="badge bg-success">Ready</span>' +
        '</div>';
    } else {
        sectionInfoDiv.innerHTML = '<small class="text-muted">Select a section to see availability and pricing</small>';
    }
}
</script>

<div class="modal fade" id="bookingModal" tabindex="-1" aria-labelledby="bookingModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden;">

      <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff; border-bottom: none;">
        <div class="d-flex align-items-center gap-3">
          <div style="background: rgba(255,255,255,0.15); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">📅</div>
          <div>
            <h5 class="modal-title fw-bold m-0" id="bookingModalLabel" style="font-family: 'Inter', sans-serif; font-size: 1.2rem; letter-spacing: -0.2px;">
              Admin Book Reservation
            </h5>
            <div style="font-size: 0.82rem; color: rgba(255,255,255,0.85);">Cemetery Management and Grave Mapping System</div>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-4" style="background: #f8fafc;">
        <form id="bookingForm" method="POST" onsubmit="return false;" novalidate>

          <input type="hidden" name="action" value="submit_booking">
          <input type="hidden" name="source" value="admin">
          <input type="hidden" name="plot_id" id="plot_id" value="">
          <input type="hidden" name="plot" id="plot" value="AUTO">
          <input type="hidden" name="plot_x" id="plot_x" value="">
          <input type="hidden" name="plot_y" id="plot_y" value="">
          <input type="hidden" name="grave_location" id="grave_location" value="">
          <input type="hidden" name="plot_price" id="plot_price" value="85000">
          <input type="hidden" name="service_price" id="service_price" value="0">
          <input type="hidden" name="interment_fee" id="interment_fee" value="38000">
          <input type="hidden" name="plot_section" id="plot_section" value="A">
          <input type="hidden" name="special_requests" id="specialRequests" value="">
          <input type="hidden" name="selected_plots_json" id="selected_plots_json" value="[]">

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; overflow: hidden; border: 1px solid #e2e8f0 !important;">
            <div class="p-3 d-flex flex-wrap align-items-center justify-content-between gap-2" style="background: #f1f5f9; border-bottom: 1px solid #e2e8f0;">
              <div class="d-flex align-items-center gap-2">
                <span class="badge px-3 py-2 fs-6 fw-bold" id="selectedSectionBadge" style="background: #7b1113; color: #fff;">Section A</span>
                <span class="badge bg-primary px-3 py-2 fs-6 fw-bold" id="selectedPlotsCountBadge">1 Plot Selected</span>
              </div>
              <div class="d-flex align-items-center gap-3">
                <div>
                  <span class="text-muted small">Plot Subtotal: </span>
                  <strong class="text-dark fs-6" id="selectedPlotsSubtotalDisplay">₱85,000.00</strong>
                </div>
                <div>
                  <span class="text-muted small">Base Rate: </span>
                  <strong class="text-dark fs-6" id="selectedSectionBasePrice">₱85,000.00</strong>
                </div>
              </div>
            </div>

            <div class="p-3 bg-white">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <h6 class="fw-bold m-0 text-secondary text-uppercase" style="font-size: 0.8rem; letter-spacing: 0.5px;">
                  <i class="fas fa-layer-group text-primary me-1"></i> Selected Plots
                </h6>
                <span class="small text-muted" id="multiPlotHelperText">Click "Choose / Add Plot from Map" to add multiple plots</span>
              </div>

              <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0 small">
                  <thead style="background: #f8fafc;">
                    <tr class="text-muted">
                      <th style="width: 5%;">#</th>
                      <th style="width: 15%;">Section</th>
                      <th style="width: 25%;">Plot Number</th>
                      <th style="width: 20%;">Lawn Type</th>
                      <th style="width: 15%;">Plot Price</th>
                      <th style="width: 10%;">Status</th>
                      <th style="width: 10%; text-align: center;">Action</th>
                    </tr>
                  </thead>
                  <tbody id="selectedPlotsTableBody">
                    <tr>
                      <td class="fw-bold">1</td>
                      <td class="fw-bold" id="selectedPlotSection">A</td>
                      <td class="fw-bold text-primary" id="selectedPlotNo">1,1</td>
                      <td id="selectedPlotLawnType">Lawn (Regular)</td>
                      <td class="fw-bold text-dark" id="selectedPlotPriceDisplay">₱85,000.00</td>
                      <td><span id="selectedPlotStatus" class="badge bg-success px-2 py-1">AVAILABLE</span></td>
                      <td class="text-center">
                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 fw-semibold" onclick="removePlotFromBooking(0)">
                          <i class="fas fa-trash-alt me-1"></i> Remove
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3 pt-2 border-top">
                <div class="d-flex align-items-center gap-2">
                  <button type="button" id="selectPlotBtn" class="btn btn-primary btn-sm px-3 fw-semibold">
                    <i class="fas fa-plus-circle me-1"></i> Choose / Add Plot from Map
                  </button>
                  <button type="button" id="clearPlotBtn" class="btn btn-outline-secondary btn-sm px-2" onclick="clearAllPlotsFromBooking()">
                    <i class="fas fa-redo-alt me-1"></i> Clear Plots
                  </button>
                </div>
                <div class="d-flex align-items-center gap-2" style="display:none !important;">
                  <input type="hidden" id="section" name="section" value="A">
                </div>
              </div>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-3" style="display:none !important; border-radius: 1rem; border: 1px solid #e2e8f0 !important;">
            <div class="card-body p-3">
              <h6 class="fw-bold mb-3 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                <span class="text-primary">🏛️</span> Property / Lot Details
              </h6>

              <div class="row g-3">

                <div class="col-md-4">
                  <label class="form-label small fw-bold text-muted mb-1 text-uppercase">Lot Type</label>
                  <div class="btn-group w-100" role="group" aria-label="Lot Type">
                    <input type="radio" class="btn-check" name="lot_type" id="lotTypeRegular" value="Regular" checked autocomplete="off">
                    <label class="btn btn-outline-secondary py-2 fw-semibold small" for="lotTypeRegular">Regular</label>

                    <input type="radio" class="btn-check" name="lot_type" id="lotTypePremium" value="Premium" autocomplete="off">
                    <label class="btn btn-outline-secondary py-2 fw-semibold small" for="lotTypePremium">Premium</label>
                  </div>
                  <div class="form-text small text-muted">Regular: ₱85,000 | Premium: ₱89,000</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label small fw-bold text-muted mb-1 text-uppercase">Area</label>
                  <div class="btn-group w-100" role="group" aria-label="Area">
                    <input type="radio" class="btn-check" name="area" id="areaLawn" value="Lawn" checked autocomplete="off">
                    <label class="btn btn-outline-secondary py-2 fw-semibold small active" for="areaLawn">Lawn</label>
                  </div>
                  <div class="form-text small text-muted">Standard Lawn area</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label small fw-bold text-muted mb-1 text-uppercase">Number of Lots</label>
                  <div class="btn-group w-100" role="group" aria-label="Number of Lots">
                    <input type="radio" class="btn-check" name="num_lots" id="numLots1" value="1" autocomplete="off">
                    <label class="btn btn-outline-secondary py-2 fw-semibold small" for="numLots1">1 Lot</label>

                    <input type="radio" class="btn-check" name="num_lots" id="numLots2" value="2" checked autocomplete="off">
                    <label class="btn btn-outline-secondary py-2 fw-semibold small" for="numLots2">2 Lots</label>
                  </div>
                  <div class="form-text small text-muted">Quantity of adjacent lots to reserve</div>
                </div>
              </div>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important;">
            <div class="card-body p-3">
              <h6 class="fw-bold mb-3 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                <span class="text-success">💳</span> Payment Option
              </h6>

              <div class="row g-3 align-items-center">
                <div class="col-md-5">
                  <div class="btn-group w-100" role="group" aria-label="Payment Plan">
                    <input type="radio" class="btn-check" name="payment_option" id="payFull" value="full" checked autocomplete="off">
                    <label class="btn btn-outline-success py-2 fw-semibold small" for="payFull">
                      <i class="fas fa-check-circle me-1"></i> Full Payment
                    </label>

                    <input type="radio" class="btn-check" name="payment_option" id="payInstallment" value="installment" autocomplete="off">
                    <label class="btn btn-outline-primary py-2 fw-semibold small" id="payInstallmentLabel" for="payInstallment">
                      <i class="fas fa-calendar-alt me-1"></i> Installment Plan
                    </label>
                  </div>
                </div>

                <div class="col-md-7" id="installmentControls" style="display: none;">
                  <label class="form-label small fw-bold text-muted mb-1 text-uppercase">Payment Term (Amortization)</label>
                  <select id="paymentTerm" name="payment_term" class="form-select form-select-sm fw-semibold">
                    <option value="12" selected>12 Months (1 Year) — ₱7,226.00 / mo</option>
                    <option value="24">24 Months (2 Years) — ₱4,495.00 / mo</option>
                    <option value="36">36 Months (3 Years) — ₱3,336.00 / mo</option>
                    <option value="48">48 Months (4 Years) — ₱2,772.00 / mo</option>
                    <option value="60">60 Months (5 Years) — ₱2,446.00 / mo</option>
                  </select>
                  <div class="d-flex justify-content-between align-items-center mt-1">
                    <span class="small text-muted">Monthly Amortization:</span>
                    <strong class="text-primary" id="installmentMonthlyDisplay">₱7,226.00 / mo</strong>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important;">
            <div class="card-body p-3">
              <h6 class="fw-bold mb-3 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                <span class="text-danger">👤</span> Client Information
              </h6>

              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label small fw-semibold mb-1">Client / Buyer Full Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm" id="customerName" name="customer_name" placeholder="e.g., Juan Dela Cruz" required>
                  <div class="invalid-feedback small">Client name is required.</div>
                </div>

                <div class="col-md-2">
                  <label class="form-label small fw-semibold mb-1">Client Suffix</label>
                  <select class="form-select form-select-sm" id="customerSuffix" name="customer_suffix">
                    <option value="">No Suffix</option>
                    <option value="JR.">JR.</option>
                    <option value="SR.">SR.</option>
                    <option value="II">II</option>
                    <option value="III">III</option>
                    <option value="IV">IV</option>
                    <option value="V">V</option>
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label small fw-semibold mb-1">Contact Number <span class="text-danger">*</span></label>
                  <input type="tel" class="form-control form-control-sm" id="contactNumber" name="contact_number" placeholder="09XXXXXXXXX" maxlength="11" minlength="11" pattern="09[0-9]{9}" required oninput="var val=this.value.replace(/[^0-9]/g,''); if(val.indexOf('639')===0) val='09'+val.substring(3); else if(val.indexOf('9')===0) val='09'+val.substring(1); else if(val.length>=2 && val.charAt(0)==='0' && val.charAt(1)!=='9') val='0'; else if(val.length===1 && val!=='0') val='09'; this.value=val.slice(0,11);">
                  <div class="invalid-feedback small">Please provide a valid 11-digit mobile number starting with 09.</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label small fw-semibold mb-1">Preferred Date <span class="text-danger">*</span></label>
                  <input type="date" class="form-control form-control-sm" id="preferredDate" name="preferred_date" required>
                  <div class="invalid-feedback small">Please select a valid date.</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label small fw-semibold mb-1">Associated Deceased Name <span class="text-muted">(Optional)</span></label>
                  <input type="text" class="form-control form-control-sm" id="deceasedName" name="deceased_name" placeholder="e.g., Maria Dela Cruz">
                </div>

                <div class="col-md-2">
                  <label class="form-label small fw-semibold mb-1">Deceased Suffix</label>
                  <select class="form-select form-select-sm" id="deceasedSuffix" name="deceased_suffix">
                    <option value="">No Suffix</option>
                    <option value="JR.">JR.</option>
                    <option value="SR.">SR.</option>
                    <option value="II">II</option>
                    <option value="III">III</option>
                    <option value="IV">IV</option>
                    <option value="V">V</option>
                  </select>
                </div>

                <div class="col-12">
                  <label class="form-label small fw-semibold mb-1">Client Address / Location <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm" id="bookerLocation" name="booker_location" placeholder="e.g., Brgy. Poblacion, Alaminos City, Pangasinan" data-ph-address-selector="true" required>
                  <div class="invalid-feedback small">Address is required.</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label small fw-semibold mb-1">Purpose</label>
                  <select class="form-select form-select-sm" id="bookingPurpose" name="purpose">
                    <option value="reserve_grave" selected>Reserve New Grave</option>
                    <option value="add_deceased">Add Additional Deceased (Min 2, Max 4)</option>
                    <option value="cemetery_service">Cemetery Service</option>
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label small fw-semibold mb-1">Payment Method</label>
                  <select class="form-select form-select-sm" id="paymentMethod" name="payment_method">
                    <option value="CASH / OFFICE" selected>CASH / OFFICE</option>
                    <option value="GCash">GCash Payment</option>
                  </select>
                </div>

                <div class="col-12" id="adminServiceTypeContainer" style="display:none;">
                  <div class="p-3 rounded border" style="background: #fffaf0; border-color: #f0e2cd;">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                      <label class="form-label small fw-bold text-dark mb-0 d-flex align-items-center gap-1">
                        <i class="fas fa-concierge-bell text-danger"></i> Cemetery Service Type <span class="text-danger">*</span>
                      </label>
                      <span class="badge bg-success px-2 py-1 fw-bold" id="adminServicePriceBadge">₱200.00 Flat Rate</span>
                    </div>
                    <select class="form-select form-select-sm mb-1" id="adminServiceType" name="service_type">
                      <option value="Maintenance" data-price="200.00" selected>Maintenance — ₱200.00</option>
                      <option value="Cleaning" data-price="200.00">Cleaning — ₱200.00</option>
                      <option value="Landscaping" data-price="200.00">Landscaping — ₱200.00</option>
                      <option value="Decoration" data-price="200.00">Decoration — ₱200.00</option>
                    </select>
                    <small class="text-muted"><i class="fas fa-info-circle me-1"></i>All cemetery services are set to a fixed rate of ₱200.00.</small>
                  </div>
                </div>

                <div class="col-12" id="adminAddDeceasedContainer" style="display:none;">
                  <div class="p-3 rounded border mb-1" style="background: #fdfbf7; border-color: #ebd9c8;">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2 pb-2 border-bottom" style="border-color: #ebd9c8 !important;">
                      <div>
                        <label class="form-label small fw-bold text-dark mb-0 d-flex align-items-center gap-1">
                          <i class="fas fa-users text-danger"></i> Deceased Person Information &amp; Plot Assignment
                        </label>
                        <small class="text-muted d-block" style="font-size: 0.76rem;">Select which plot each deceased person belongs to. Add separate entries for multiple deceased.</small>
                      </div>
                      <button type="button" class="btn btn-sm btn-outline-primary fw-semibold" id="adminAddAnotherDeceasedBtn" onclick="window.addAnotherDeceasedCard()">
                        <i class="fas fa-user-plus me-1"></i> Add Additional Deceased
                      </button>
                    </div>

                    <!-- Dynamic Deceased Cards Container -->
                    <div id="adminDeceasedCardsContainer" class="d-flex flex-column gap-3">
                      <!-- Cards dynamically rendered by JavaScript -->
                    </div>
                  </div>
                </div>

                <input type="hidden" id="service_price" name="service_price" value="200.00">

                <!-- Admin GCash Payment Details & Proof Upload Container -->
                <div class="col-12" id="adminGcashPaymentContainer" style="display: none;">
                  <div class="p-3 rounded border shadow-sm" style="background: #f0f7ff; border-color: #bcdbfc !important;">
                    <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom border-primary-subtle">
                      <div class="fw-bold text-primary small d-flex align-items-center gap-1">
                        <i class="fas fa-mobile-alt"></i> GCash Payment &amp; Verification
                      </div>
                      <span class="badge bg-primary px-2 py-1">Merchant Verified</span>
                    </div>

                    <div class="row g-2 align-items-center mb-2">
                      <div class="col-auto">
                        <img src="assets/gcash-logo.png" alt="GCash Logo" class="p-1 rounded shadow-sm bg-white border" style="width: 75px; height: 75px; object-fit: contain;" onerror="this.src='gcashqr1.jpg';">
                      </div>
                      <div class="col">
                        <div class="small fw-bold text-dark">Garden of Remembrance Memorial Park</div>
                        <div class="small text-secondary mb-1">GCash Account: <strong class="text-dark">0917-823-4567</strong></div>
                        <div class="small text-muted" style="font-size: 0.78rem;">
                          Please send payment to the official GCash account number above. Attach the screenshot proof and enter reference number below, or click Pay Now to proceed to the GCash gateway.
                        </div>
                      </div>
                    </div>

                    <div class="row g-2">
                      <div class="col-md-6">
                        <label class="form-label small fw-bold text-dark mb-1">GCash Reference Number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="adminGcashRef" name="payment_reference" placeholder="e.g. 100293847291">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small fw-bold text-dark mb-1">Proof of Payment / Screenshot <span class="text-danger">*</span></label>
                        <input type="file" class="form-control form-control-sm" id="adminGcashProof" name="payment_proof" accept="image/jpeg,image/png,image/webp,application/pdf">
                      </div>
                    </div>

                    <button type="button" class="btn btn-primary w-100 mt-3 fw-bold" onclick="adminPayNowViaGcash(event)">
                      <i class="fas fa-wallet me-1"></i> Pay Now — proceed to GCash
                    </button>
                    <div class="form-text text-center" style="font-size:0.72rem;">Submits this booking, then opens the GCash gateway to pay the full amount due.</div>
                  </div>
                </div>

                <div class="col-12">
                  <label class="form-label small fw-semibold mb-1">Remarks / Notes</label>
                  <textarea class="form-control form-control-sm" id="bookingRemarks" name="remarks" rows="2" placeholder="Enter any notes, special requests, or admin instructions..."></textarea>
                </div>
              </div>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-4" style="border-radius: 1rem; background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #fff;">
            <div class="card-body p-4">
              <div class="d-flex align-items-center justify-content-between pb-3 mb-3 border-bottom border-secondary">
                <h6 class="fw-bold m-0 d-flex align-items-center gap-2" style="font-size: 1.05rem; letter-spacing: -0.2px;">
                  📋 Reservation Summary
                </h6>
                <span class="badge bg-primary px-3 py-1 fw-bold" id="summaryBadgePlan">Full Payment</span>
              </div>

              <div class="row g-2 small text-light mb-3">
                <div class="col-6 col-md-3">
                  <div class="text-secondary small text-uppercase fw-semibold">Selected Plot</div>
                  <div class="fw-bold fs-6 text-white" id="summaryPlot">1,1</div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="text-secondary small text-uppercase fw-semibold">Lot Type</div>
                  <div class="fw-bold fs-6 text-white" id="summaryLotType">Regular</div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="text-secondary small text-uppercase fw-semibold">Area</div>
                  <div class="fw-bold fs-6 text-white" id="summaryArea">Lawn</div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="text-secondary small text-uppercase fw-semibold">Number of Lots</div>
                  <div class="fw-bold fs-6 text-white" id="summaryNumLots">1</div>
                </div>
                <div class="col-12 mt-2 pt-2 border-top border-secondary" id="adminDownpaymentRow" style="display: none;">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small">Required Downpayment (20%):</span>
                    <strong class="text-success" id="summaryDownpayment">₱17,000.00</strong>
                  </div>
                </div>
                <div class="col-12 mt-1 pt-1 border-top border-secondary" id="summaryInstallmentRow" style="display:none;">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-warning">Amortization Term:</span>
                    <strong class="text-warning" id="summaryInstallmentDetails">12 Months @ ₱7,226.00 / mo</strong>
                  </div>
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small">Total Contract Price:</span>
                    <strong class="text-white" id="summaryTotalContractPrice">₱86,712.00</strong>
                  </div>
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="text-secondary small">Projected Remaining Balance:</span>
                    <strong class="text-info" id="summaryProjectedBalance">₱79,486.00</strong>
                  </div>
                </div>
              </div>

              <div class="d-flex flex-wrap align-items-center justify-content-between pt-2 border-top border-secondary">
                <div class="text-uppercase fw-bold text-secondary" style="letter-spacing: 0.5px;" id="summaryPriceLabel">Total Price:</div>
                <div class="fs-3 fw-bold text-success" id="summaryTotalPrice">₱85,000.00</div>
              </div>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 0.85rem; border: 1px solid #e2e8f0 !important; background: #fff;">
            <div class="p-3">
              <div class="form-check d-flex align-items-start gap-2 mb-0">
                <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" id="adminBookingTermsAgreement" name="admin_terms_agreement" required style="cursor: pointer; width: 1.15em; height: 1.15em; border-color: #7b1113;">
                <label class="form-check-label small text-dark" for="adminBookingTermsAgreement" style="cursor: pointer; line-height: 1.45;">
                  <strong>Admin Policy & Terms Acknowledgement:</strong> I confirm that the client has been briefed on and agreed to the 
                  <a href="javascript:void(0)" class="fw-bold text-decoration-underline" style="color: #7b1113;" onclick="openAdminCemeteryTermsModal()">Terms and Agreements</a> 
                  of Garden of Remembrance Memorial Park, including payment terms, interment rights, and maintenance rules. <span class="text-danger">*</span>
                </label>
              </div>
            </div>
          </div>

          <div id="adminBookingAlert" class="alert alert-danger py-2 small mb-3" style="display:none;"></div>

          <div class="text-center">
            <button id="adminSubmitBookingBtn" type="button" class="btn btn-primary btn-lg px-5 py-3 fw-bold shadow" onclick="if(typeof handleBookingSubmit==='function'){handleBookingSubmit(event);}else if(typeof window.handleBookingSubmit==='function'){window.handleBookingSubmit(event);}else if(typeof submitBooking==='function'){submitBooking();}else if(typeof window.submitBooking==='function'){window.submitBooking();}" style="border-radius: 2rem; letter-spacing: 0.5px; background: #7b1113; border-color: #7b1113; min-width: 260px;">
              <i class="fas fa-check-circle me-2"></i> Submit Reservation
            </button>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>

<!-- Admin Cemetery Terms and Agreements Modal -->
<div class="modal fade" id="adminCemeteryTermsModal" tabindex="-1" aria-labelledby="adminCemeteryTermsModalLabel" aria-hidden="true" style="z-index: 1075;">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: min(650px, 94vw);">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
      <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff;">
        <h5 class="modal-title fw-bold m-0" id="adminCemeteryTermsModalLabel">
          <i class="fas fa-file-contract me-2 text-warning"></i> Cemetery Terms, Conditions & Policy Agreements
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 bg-light small" style="line-height: 1.6; color: #334155;">
        <div class="p-3 bg-white rounded border mb-3 shadow-sm">
          <h6 class="fw-bold text-dark mb-2"><i class="fas fa-landmark text-primary me-2"></i>1. Plot Rights & Perpetual Interment</h6>
          <p class="mb-0">A burial plot purchase grants perpetual interment rights strictly under memorial park guidelines. Title to all real property and grounds remains with Garden of Remembrance Memorial Park.</p>
        </div>

        <div class="p-3 bg-white rounded border mb-3 shadow-sm">
          <h6 class="fw-bold text-dark mb-2"><i class="fas fa-money-check-alt text-success me-2"></i>2. Official Price Schedule & Amortization</h6>
          <p class="mb-0">Downpayment (20%) is required upon reservation confirmation. The remaining 80% balance is amortized monthly over the designated contract term (12, 24, 36, 48, or 60 months). Official receipts (OR) must be issued for all cash and online payments.</p>
        </div>

        <div class="p-3 bg-white rounded border mb-3 shadow-sm" style="border-left: 4px solid #dc3545 !important;">
          <h6 class="fw-bold text-danger mb-2"><i class="fas fa-calendar-times text-danger me-2"></i>3. Three (3) Months Non-Payment Cancellation Policy</h6>
          <p class="mb-0"><strong>Automatic Cancellation:</strong> If a client fails to pay or settles no payment for three (3) consecutive months (90 days of non-payment or overdue installment amortizations), the reservation will be automatically cancelled. The reserved plot will be immediately forfeited, released, and made available for other buyers. In accordance with memorial park policy, all previous payments and reservation fees are strictly non-refundable.</p>
        </div>

        <div class="p-3 bg-white rounded border mb-3 shadow-sm">
          <h6 class="fw-bold text-dark mb-2"><i class="fas fa-cross text-danger me-2"></i>4. Interment & Multi-Layering (Patong)</h6>
          <p class="mb-0">Secondary interment (layering / patong) requires registered owner or legal family consent and compliance with sanitary interval standards (8-10 years interval or approved vault-sealed tiering). Capacity cannot exceed 4 occupants per standard lawn lot.</p>
        </div>

        <div class="p-3 bg-white rounded border mb-3 shadow-sm">
          <h6 class="fw-bold text-dark mb-2"><i class="fas fa-seedling text-success me-2"></i>5. Grounds Maintenance & Headstones</h6>
          <p class="mb-0">Maintenance, excavation, and turf care are performed exclusively by authorized park personnel. Monument markers, flat headstones, and adornments must conform strictly to section guidelines.</p>
        </div>

        <div class="p-3 bg-white rounded border shadow-sm">
          <h6 class="fw-bold text-dark mb-2"><i class="fas fa-user-shield text-info me-2"></i>6. Data Privacy Act (R.A. 10173)</h6>
          <p class="mb-0">All client information is collected and processed with utmost confidentiality for official cemetery registry and record-keeping.</p>
        </div>
      </div>
      <div class="modal-footer bg-white py-2 px-4 d-flex justify-content-between">
        <span class="text-muted small"><i class="fas fa-shield-alt text-success me-1"></i> Park Management Regulations</span>
        <button type="button" class="btn btn-secondary px-4 fw-semibold" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
// Official Price Sheet Amortization Table (80% remaining principal amortized after 20% downpayment)
var OFFICIAL_AMORTIZATION = {
  'Regular': {
    12: 5781,
    24: 3596,
    36: 2669,
    48: 2218,
    60: 1957
  },
  'Premium': {
    12: 6052,
    24: 3766,
    36: 2794,
    48: 2322,
    60: 2049
  }
};

function calculateAdminPrice() {
  var section = (document.getElementById('section') ? document.getElementById('section').value : 'A') || 'A';
  var lotTypeEl = document.querySelector('input[name="lot_type"]:checked');
  var lotType = lotTypeEl ? lotTypeEl.value : 'Regular';
  var areaEl = document.querySelector('input[name="area"]:checked');
  var area = areaEl ? areaEl.value : 'Lawn';
  var numLotsEl = document.querySelector('input[name="num_lots"]:checked');
  var numLotsLimit = numLotsEl ? Number(numLotsEl.value) : 1;
  // Actual count for pricing/summary. Do NOT overwrite the num_lots radio here:
  // the radio is the user-selected limit (1 or 2), not the current count.
  var numLots = numLotsLimit;
  if (window.__selectedPlotsList && window.__selectedPlotsList.length > 0) {
    numLots = window.__selectedPlotsList.length;
  }
  if (!numLots || numLots < 1) numLots = 1;
  if (!numLotsLimit || numLotsLimit < 1) numLotsLimit = 1;

  var purpose = document.getElementById('bookingPurpose') ? document.getElementById('bookingPurpose').value : 'reserve_grave';
  var isCemService = (purpose === 'cemetery_service');

  // Toggle Cemetery Service dropdown in admin booking modal
  var adminSvcCont = document.getElementById('adminServiceTypeContainer');
  if (adminSvcCont) {
    adminSvcCont.style.display = isCemService ? 'block' : 'none';
  }

  // Installment plan & installment term are removed if purpose is Cemetery Service
  var payInstallmentRadio = document.getElementById('payInstallment');
  var payInstallmentLabel = document.getElementById('payInstallmentLabel');
  var payFullRadio = document.getElementById('payFull');

  if (isCemService) {
    if (payInstallmentRadio) {
      payInstallmentRadio.disabled = true;
      payInstallmentRadio.style.display = 'none';
    }
    if (payInstallmentLabel) payInstallmentLabel.style.display = 'none';
    if (payFullRadio) payFullRadio.checked = true;
  } else {
    if (payInstallmentRadio) {
      payInstallmentRadio.disabled = false;
      payInstallmentRadio.style.display = '';
    }
    if (payInstallmentLabel) payInstallmentLabel.style.display = '';
  }

  var paymentPlanEl = document.querySelector('input[name="payment_option"]:checked');
  var paymentPlan = isCemService ? 'full' : (paymentPlanEl ? paymentPlanEl.value : 'full');
  var paymentTermEl = document.getElementById('paymentTerm');
  var paymentTerm = paymentTermEl ? Number(paymentTermEl.value) : 12;

  // Base price lookup according to official price sheet (All cemetery plots are Lawn)
  var basePricePerLot = (lotType === 'Premium') ? 89000 : 85000;

  var intermentFee = 38000;
  var totalAmount = 0;

  if (purpose === 'reserve_grave') {
    var plotSubtotal = 0;
    if (window.__selectedPlotsList && window.__selectedPlotsList.length > 0) {
      window.__selectedPlotsList.forEach(function(p) {
        plotSubtotal += Number(p.price || (p.lawn_type === 'Premium' ? 89000 : 85000));
      });
      numLots = window.__selectedPlotsList.length;
    } else {
      plotSubtotal = basePricePerLot * numLots;
    }
    totalAmount = plotSubtotal;
  } else if (purpose === 'add_deceased') {
    var decCards = document.querySelectorAll('#adminDeceasedCardsContainer .admin-deceased-card');
    var decCount = (decCards && decCards.length > 0) ? decCards.length : 1;
    intermentFee = 38000 * decCount;
    totalAmount = intermentFee;
    // Reset service_price to 0 so stale cemetery_service value doesn't carry over
    if (document.getElementById('service_price')) {
      document.getElementById('service_price').value = '0.00';
    }
  } else if (purpose === 'cemetery_service') {
    var adminSvcEl = document.getElementById('adminServiceType') || document.getElementById('serviceType');
    var selectedOpt = adminSvcEl && adminSvcEl.selectedOptions ? adminSvcEl.selectedOptions[0] : null;
    var svcPriceVal = selectedOpt ? Number(selectedOpt.dataset.price || selectedOpt.getAttribute('data-price') || 200) : 200;
    if (isNaN(svcPriceVal) || svcPriceVal <= 0) svcPriceVal = 200;
    totalAmount = svcPriceVal;
    if (document.getElementById('service_price')) {
      document.getElementById('service_price').value = totalAmount.toFixed(2);
    }
  } else {
    totalAmount = Number(document.getElementById('service_price') ? document.getElementById('service_price').value : 200) || 200;
  }

  // Update hidden inputs
  if (document.getElementById('plot_price')) {
    document.getElementById('plot_price').value = totalAmount.toFixed(2);
  }
  if (document.getElementById('interment_fee')) {
    document.getElementById('interment_fee').value = intermentFee.toFixed(2);
  }

  // Monthly installment calculation (20% Downpayment required, 80% amortized)
  var reqDownpayment = (purpose === 'reserve_grave') ? Math.round(totalAmount * 0.20) : 0;
  var totalMonthly = 0;
  if (window.__selectedPlotsList && window.__selectedPlotsList.length > 0) {
    window.__selectedPlotsList.forEach(function(p) {
      var pType = (p.lawn_type === 'Premium' || p.lawnType === 'Premium') ? 'Premium' : 'Regular';
      var m = (OFFICIAL_AMORTIZATION[pType] && OFFICIAL_AMORTIZATION[pType][paymentTerm]) 
        ? OFFICIAL_AMORTIZATION[pType][paymentTerm] 
        : Math.round((Number(p.price || 85000) * 0.80) / paymentTerm);
      totalMonthly += m;
    });
  } else {
    var monthlyPerLot = (OFFICIAL_AMORTIZATION[lotType] && OFFICIAL_AMORTIZATION[lotType][paymentTerm]) 
      ? OFFICIAL_AMORTIZATION[lotType][paymentTerm] 
      : Math.round((basePricePerLot * 0.80) / paymentTerm);
    totalMonthly = monthlyPerLot * numLots;
  }

  var totalContractCost = (paymentPlan === 'installment' && purpose === 'reserve_grave')
    ? (reqDownpayment + (totalMonthly * paymentTerm))
    : totalAmount;
  var initialPaymentToday = (paymentPlan === 'installment' && purpose === 'reserve_grave')
    ? reqDownpayment
    : totalAmount;
  var projectedRemaining = Math.max(0, totalContractCost - initialPaymentToday);

  // Toggle Installment controls display
  var instControls = document.getElementById('installmentControls');
  var instRow = document.getElementById('summaryInstallmentRow');
  var badgePlan = document.getElementById('summaryBadgePlan');

  if (paymentPlan === 'installment' && !isCemService) {
    if (instControls) instControls.style.display = 'block';
    if (instRow) instRow.style.display = 'block';
    if (badgePlan) {
      badgePlan.textContent = 'Installment Plan';
      badgePlan.className = 'badge bg-warning text-dark px-3 py-1 fw-bold';
    }
  } else {
    if (instControls) instControls.style.display = 'none';
    if (instRow) instRow.style.display = 'none';
    if (badgePlan) {
      badgePlan.textContent = 'Full Payment';
      badgePlan.className = 'badge bg-primary px-3 py-1 fw-bold';
    }
  }

  // Update dynamic option texts in paymentTerm select (20% Downpayment)
  if (paymentTermEl) {
    var terms = [12, 24, 36, 48, 60];
    var labels = { 12: '1 Year', 24: '2 Years', 36: '3 Years', 48: '4 Years', 60: '5 Years' };
    Array.from(paymentTermEl.options).forEach(function(opt) {
      var t = Number(opt.value);
      var mTotal = 0;
      if (window.__selectedPlotsList && window.__selectedPlotsList.length > 0) {
        window.__selectedPlotsList.forEach(function(p) {
          var pType = (p.lawn_type === 'Premium' || p.lawnType === 'Premium') ? 'Premium' : 'Regular';
          var m = (OFFICIAL_AMORTIZATION[pType] && OFFICIAL_AMORTIZATION[pType][t]) ? OFFICIAL_AMORTIZATION[pType][t] : Math.round((Number(p.price || 85000) * 0.80) / t);
          mTotal += m;
        });
      } else {
        var m = (OFFICIAL_AMORTIZATION[lotType] && OFFICIAL_AMORTIZATION[lotType][t]) ? OFFICIAL_AMORTIZATION[lotType][t] : Math.round((basePricePerLot * 0.80) / t);
        mTotal = m * numLots;
      }
      opt.textContent = t + ' Months (' + (labels[t] || '') + ') — ₱' + mTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' / mo';
    });
  }

  var instMonthlyDisplay = document.getElementById('installmentMonthlyDisplay');
  if (instMonthlyDisplay) {
    instMonthlyDisplay.textContent = '₱' + totalMonthly.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' / mo';
  }

  var summaryInstallmentDetails = document.getElementById('summaryInstallmentDetails');
  if (summaryInstallmentDetails) {
    summaryInstallmentDetails.textContent = paymentTerm + ' Months @ ₱' + totalMonthly.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' / mo';
  }

  var summaryTotalContractPrice = document.getElementById('summaryTotalContractPrice');
  if (summaryTotalContractPrice) {
    summaryTotalContractPrice.textContent = '₱' + totalContractCost.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  var summaryProjectedBalance = document.getElementById('summaryProjectedBalance');
  if (summaryProjectedBalance) {
    summaryProjectedBalance.textContent = '₱' + projectedRemaining.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  // Update Summary Card
  var plotInput = document.getElementById('plot');
  var plotVal = (plotInput && plotInput.value && plotInput.value !== 'AUTO') ? plotInput.value : '1,1';
  if (window.formatPlotNumber) {
    plotVal = window.formatPlotNumber(plotVal, section);
  }
  if (window.__selectedPlotsList && window.__selectedPlotsList.length > 0) {
    plotVal = window.__selectedPlotsList.map(function(p) {
      return (p.plot_x && p.plot_y) ? (p.plot_x + ',' + p.plot_y) : (window.formatPlotNumber ? window.formatPlotNumber(p.plot_number, p.section) : (p.plot_number || '1,1'));
    }).join(', ');
  }
  var summaryPlot = document.getElementById('summaryPlot');
  if (summaryPlot) summaryPlot.textContent = plotVal;

  var summaryLotType = document.getElementById('summaryLotType');
  if (summaryLotType) {
    var displayLotType = lotType;
    if (window.__selectedPlotsList && window.__selectedPlotsList.length > 0) {
      var firstT = window.__selectedPlotsList[0].lawn_type || lotType;
      var sameAll = window.__selectedPlotsList.every(function(p) { return (p.lawn_type || lotType) === firstT; });
      displayLotType = sameAll ? firstT : 'Mixed';
    }
    summaryLotType.textContent = displayLotType;
  }

  var summaryArea = document.getElementById('summaryArea');
  if (summaryArea) summaryArea.textContent = area;

  var summaryNumLots = document.getElementById('summaryNumLots');
  if (summaryNumLots) summaryNumLots.textContent = numLots;

  var adminDownpaymentRow = document.getElementById('adminDownpaymentRow');
  var isInstallment = (paymentPlan === 'installment' && purpose === 'reserve_grave');
  if (adminDownpaymentRow) {
    adminDownpaymentRow.style.display = isInstallment ? 'block' : 'none';
  }
  var summaryDownpayment = document.getElementById('summaryDownpayment');
  if (summaryDownpayment) {
    summaryDownpayment.textContent = '₱' + reqDownpayment.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  var summaryPriceLabel = document.getElementById('summaryPriceLabel');
  var summaryTotalPrice = document.getElementById('summaryTotalPrice');
  if (paymentPlan === 'installment' && purpose === 'reserve_grave') {
    if (summaryPriceLabel) summaryPriceLabel.textContent = 'Initial Payment (20% Downpayment):';
    if (summaryTotalPrice) {
      summaryTotalPrice.textContent = '₱' + initialPaymentToday.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
  } else if (purpose === 'add_deceased') {
    var decCards = document.querySelectorAll('#adminDeceasedCardsContainer .admin-deceased-card');
    var decCount = (decCards && decCards.length > 0) ? decCards.length : 1;
    if (summaryPriceLabel) summaryPriceLabel.textContent = 'Total Interment Fee (' + decCount + ' Deceased):';
    if (summaryTotalPrice) {
      summaryTotalPrice.textContent = '₱' + totalAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
  } else {
    if (summaryPriceLabel) summaryPriceLabel.textContent = 'Total Price:';
    if (summaryTotalPrice) {
      summaryTotalPrice.textContent = '₱' + totalAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
  }

  var selectedSectionBasePrice = document.getElementById('selectedSectionBasePrice');
  if (selectedSectionBasePrice) {
    selectedSectionBasePrice.textContent = '₱' + basePricePerLot.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  var selectedSectionBadge = document.getElementById('selectedSectionBadge');
  if (selectedSectionBadge) {
    selectedSectionBadge.textContent = 'Section ' + section;
  }

  var selectedPlotsSubtotalDisplay = document.getElementById('selectedPlotsSubtotalDisplay');
  if (selectedPlotsSubtotalDisplay) {
    selectedPlotsSubtotalDisplay.textContent = '₱' + (purpose === 'reserve_grave' ? totalAmount : basePricePerLot * numLots).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
}
window.calculateAdminPrice = calculateAdminPrice;

window.__adjacentPlotCache = window.__adjacentPlotCache || {};
window.__selectedPlotsList = window.__selectedPlotsList || [];

window.syncLotTypeRadioFromPlots = function() {
  var list = window.__selectedPlotsList || [];
  if (!list.length) return;
  var firstType = list[0].lawn_type || 'Regular';
  var allSame = list.every(function(p) { return (p.lawn_type || 'Regular') === firstType; });
  var typeToCheck = allSame ? firstType : (list[list.length - 1].lawn_type || firstType);
  var r = document.querySelector('input[name="lot_type"][value="' + typeToCheck + '"]');
  if (r) r.checked = true;
};

window.adminPayNowViaGcash = function(e) {
  if (e && typeof e.preventDefault === 'function') e.preventDefault();
  var pm = document.getElementById('paymentMethod');
  if (pm) pm.value = 'GCash';
  // Skip ref/proof requirement: booking is created first, payment happens in the gateway.
  window.__payNowDirect = true;
  if (typeof handleBookingSubmit === 'function') return handleBookingSubmit(e);
  if (typeof window.handleBookingSubmit === 'function') return window.handleBookingSubmit(e);
  if (typeof submitBooking === 'function') return submitBooking();
  if (typeof window.submitBooking === 'function') return window.submitBooking();
  return false;
};

window.renderSelectedPlotsTable = function() {
  var tbody = document.getElementById('selectedPlotsTableBody');
  if (!tbody) {
    var table = document.querySelector('#bookingModal table');
    if (table) tbody = table.querySelector('tbody');
  }
  if (!tbody) return;

  var list = window.__selectedPlotsList || [];
  var countBadge = document.getElementById('selectedPlotsCountBadge');
  var subtotalDisplay = document.getElementById('selectedPlotsSubtotalDisplay');
  var jsonInput = document.getElementById('selected_plots_json');

  if (list.length === 0) {
    tbody.innerHTML = 
      '<tr>' +
        '<td colspan="7" class="text-center text-muted py-3">' +
          '<i class="fas fa-info-circle me-1"></i> No plots selected. Click <strong>Choose / Add Plot from Map</strong> below to select plots.' +
        '</td>' +
      '</tr>';
    if (countBadge) countBadge.textContent = '0 Plots Selected';
    if (subtotalDisplay) subtotalDisplay.textContent = '₱0.00';
    if (jsonInput) jsonInput.value = '[]';
    return;
  }

  var subtotal = 0;
  var html = '';
  list.forEach(function(p, idx) {
    var priceNum = Number(p.price || (p.lawn_type === 'Premium' ? 89000 : 85000));
    subtotal += priceNum;
    var statusBadge = '<span class="badge ' + (p.status === 'AVAILABLE' ? 'bg-success' : 'bg-secondary') + ' px-2 py-1">' + (p.status || 'AVAILABLE') + '</span>';
    var plotDisplayName = (p.plot_x && p.plot_y) ? (p.plot_x + ',' + p.plot_y) : (window.formatPlotNumber ? window.formatPlotNumber(p.plot_number, p.section) : (p.plot_number || '1,1'));

    html += 
      '<tr>' +
        '<td class="fw-bold text-muted">' + (idx + 1) + '</td>' +
        '<td class="fw-bold">' + p.section + '</td>' +
        '<td class="fw-bold text-primary">' + plotDisplayName + '</td>' +
        '<td>Lawn (' + (p.lawn_type || 'Regular') + ')</td>' +
        '<td class="fw-bold text-dark">₱' + priceNum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</td>' +
        '<td>' + statusBadge + '</td>' +
        '<td class="text-center">' +
          '<button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 fw-semibold" onclick="removePlotFromBooking(' + idx + ')">' +
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

  // Update primary plot hidden inputs from first plot
  var firstPlot = list[0];
  var plotInput = document.getElementById('plot');
  var graveLocInput = document.getElementById('grave_location');
  var plotIdInput = document.getElementById('plot_id');
  var plotXInput = document.getElementById('plot_x');
  var plotYInput = document.getElementById('plot_y');

  if (plotInput) plotInput.value = (firstPlot.plot_x && firstPlot.plot_y) ? (firstPlot.plot_x + ',' + firstPlot.plot_y) : (firstPlot.plot_number || '1,1');
  if (graveLocInput) graveLocInput.value = firstPlot.grave_location || (firstPlot.section + ' ' + firstPlot.plot_x + '-' + firstPlot.plot_y);
  if (plotIdInput) plotIdInput.value = firstPlot.plot_id || '';
  if (plotXInput) plotXInput.value = firstPlot.plot_x || '';
  if (plotYInput) plotYInput.value = firstPlot.plot_y || '';

  var selectPlotBtn = document.getElementById('selectPlotBtn');
  if (selectPlotBtn) {
    if (list.length >= 2) {
      selectPlotBtn.classList.add('disabled');
      selectPlotBtn.setAttribute('title', 'Maximum of 2 plots per booking allowed');
    } else {
      selectPlotBtn.classList.remove('disabled');
      selectPlotBtn.removeAttribute('title');
    }
  }

  // Synchronize purpose dropdown options based on effective status of selected plots
  if (typeof window.updateBookingPurposeAvailability === 'function') {
    window.updateBookingPurposeAvailability(window.getEffectivePlotsStatus ? window.getEffectivePlotsStatus() : 'AVAILABLE');
  }

  // Synchronize target plot options in any open deceased cards
  if (typeof window.syncAllDeceasedPlotDropdowns === 'function') {
    window.syncAllDeceasedPlotDropdowns();
  }
};

window.getEffectivePlotsStatus = function() {
  var list = window.__selectedPlotsList || [];
  if (list.length === 0) {
    return 'AVAILABLE';
  }
  // Check if any selected plot is occupied or has occupants
  var hasOccupied = list.some(function(p) {
    var st = String(p.status || '').toUpperCase();
    var occ = Number(p.occupantCount !== undefined ? p.occupantCount : (p.occupants_count || 0));
    return st === 'OCCUPIED' || occ > 0;
  });
  if (hasOccupied) return 'OCCUPIED';

  // Check if any selected plot is reserved or purchased
  var hasReserved = list.some(function(p) {
    var st = String(p.status || '').toUpperCase();
    return st === 'RESERVED' || st === 'PURCHASED' || st === 'TAKEN';
  });
  if (hasReserved) return 'RESERVED';

  return 'AVAILABLE';
};

window.updateBookingPurposeAvailability = function(plotStatus) {
  var purposeSelect = document.getElementById('bookingPurpose');
  if (!purposeSelect) return;

  // Cache template option elements so they can be added/removed cleanly
  if (!window.__adminReserveGraveOption) {
    window.__adminReserveGraveOption = purposeSelect.querySelector('option[value="reserve_grave"]');
  }
  if (!window.__adminAddDeceasedOption) {
    window.__adminAddDeceasedOption = purposeSelect.querySelector('option[value="add_deceased"]');
  }
  if (!window.__adminCemeteryServiceOption) {
    window.__adminCemeteryServiceOption = purposeSelect.querySelector('option[value="cemetery_service"]');
  }

  var reserveOpt = window.__adminReserveGraveOption;
  var addDecOpt = window.__adminAddDeceasedOption;
  var serviceOpt = window.__adminCemeteryServiceOption;

  var statusUpper = '';
  if (plotStatus && typeof plotStatus === 'string') {
    statusUpper = plotStatus.trim().toUpperCase();
  } else if (typeof window.getEffectivePlotsStatus === 'function') {
    statusUpper = window.getEffectivePlotsStatus();
  } else {
    statusUpper = 'AVAILABLE';
  }

  // Count max occupants if occupied
  var list = window.__selectedPlotsList || [];
  var highestOccupants = 0;
  var maxAllowedOccupants = 4;
  list.forEach(function(p) {
    var occ = Number(p.occupantCount !== undefined ? p.occupantCount : (p.occupants_count || 0));
    if (occ > highestOccupants) highestOccupants = occ;
    if (p.maxOccupants) maxAllowedOccupants = Number(p.maxOccupants);
  });

  if (statusUpper === 'AVAILABLE' || statusUpper === '') {
    // -------------------------------------------------------------
    // AVAILABLE (Empty / Vacant / Wala pang laman):
    // Only "Reserve New Grave" is available!
    // "Add Additional Deceased" and "Cemetery Service" are strictly disabled & hidden.
    // -------------------------------------------------------------
    if (reserveOpt && !purposeSelect.querySelector('option[value="reserve_grave"]')) {
      purposeSelect.insertBefore(reserveOpt, purposeSelect.firstChild);
    }
    if (reserveOpt) {
      reserveOpt.disabled = false;
      reserveOpt.hidden = false;
      reserveOpt.style.display = '';
    }
    if (addDecOpt) {
      addDecOpt.disabled = true;
      addDecOpt.hidden = true;
      addDecOpt.style.display = 'none';
      if (addDecOpt.parentNode) addDecOpt.parentNode.removeChild(addDecOpt);
    }
    if (serviceOpt) {
      serviceOpt.disabled = true;
      serviceOpt.hidden = true;
      serviceOpt.style.display = 'none';
      if (serviceOpt.parentNode) serviceOpt.parentNode.removeChild(serviceOpt);
    }

    purposeSelect.value = 'reserve_grave';

  } else if (statusUpper === 'RESERVED' || statusUpper === 'PURCHASED') {
    // -------------------------------------------------------------
    // RESERVED: Plot is already reserved!
    // "Reserve New Grave" is removed completely.
    // "Add Additional Deceased" and "Cemetery Service" are enabled.
    // -------------------------------------------------------------
    if (reserveOpt && reserveOpt.parentNode) {
      reserveOpt.parentNode.removeChild(reserveOpt);
    }
    if (addDecOpt) {
      if (!purposeSelect.querySelector('option[value="add_deceased"]')) {
        purposeSelect.appendChild(addDecOpt);
      }
      addDecOpt.disabled = false;
      addDecOpt.hidden = false;
      addDecOpt.style.display = '';
    }
    if (serviceOpt) {
      if (!purposeSelect.querySelector('option[value="cemetery_service"]')) {
        purposeSelect.appendChild(serviceOpt);
      }
      serviceOpt.disabled = false;
      serviceOpt.hidden = false;
      serviceOpt.style.display = '';
    }

    if (purposeSelect.value === 'reserve_grave' || !purposeSelect.value) {
      purposeSelect.value = 'add_deceased';
    }

  } else if (statusUpper === 'OCCUPIED') {
    // -------------------------------------------------------------
    // OCCUPIED (May laman na):
    // "Reserve New Grave" is removed completely.
    // "Cemetery Service" is enabled.
    // "Add Additional Deceased" enabled unless full capacity.
    // -------------------------------------------------------------
    if (reserveOpt && reserveOpt.parentNode) {
      reserveOpt.parentNode.removeChild(reserveOpt);
    }
    if (serviceOpt) {
      if (!purposeSelect.querySelector('option[value="cemetery_service"]')) {
        purposeSelect.appendChild(serviceOpt);
      }
      serviceOpt.disabled = false;
      serviceOpt.hidden = false;
      serviceOpt.style.display = '';
    }

    if (highestOccupants >= maxAllowedOccupants) {
      if (addDecOpt) {
        addDecOpt.disabled = true;
        addDecOpt.hidden = true;
        addDecOpt.style.display = 'none';
        if (addDecOpt.parentNode) addDecOpt.parentNode.removeChild(addDecOpt);
      }
      purposeSelect.value = 'cemetery_service';
    } else {
      if (addDecOpt) {
        if (!purposeSelect.querySelector('option[value="add_deceased"]')) {
          var sOptInDom = purposeSelect.querySelector('option[value="cemetery_service"]');
          if (sOptInDom) {
            purposeSelect.insertBefore(addDecOpt, sOptInDom);
          } else {
            purposeSelect.appendChild(addDecOpt);
          }
        }
        addDecOpt.disabled = false;
        addDecOpt.hidden = false;
        addDecOpt.style.display = '';
      }
      if (purposeSelect.value === 'reserve_grave' || !purposeSelect.value) {
        purposeSelect.value = 'add_deceased';
      }
    }
  }

  if (typeof togglePurposeSections === 'function') togglePurposeSections();
  if (typeof updatePurposeUI === 'function') updatePurposeUI();
};

window.removePlotFromBooking = function(idx) {
  if (!window.__selectedPlotsList) return;
  window.__selectedPlotsList.splice(idx, 1);
  window.renderSelectedPlotsTable();
  if (typeof window.syncLotTypeRadioFromPlots === 'function') {
    window.syncLotTypeRadioFromPlots();
  }
  window.calculateAdminPrice();
  var remainingStatus = (typeof window.getEffectivePlotsStatus === 'function') ? window.getEffectivePlotsStatus() : 'AVAILABLE';
  if (typeof window.updateBookingPurposeAvailability === 'function') {
    window.updateBookingPurposeAvailability(remainingStatus);
  }
};

window.clearAllPlotsFromBooking = function() {
  window.__selectedPlotsList = [];
  window.renderSelectedPlotsTable();
  window.calculateAdminPrice();
  if (typeof window.updateBookingPurposeAvailability === 'function') {
    window.updateBookingPurposeAvailability('AVAILABLE');
  }
};

window.formatPlotNumber = function(plot, section) {
  if (!plot && !section) return '—';
  var raw = String(plot || '').trim();
  var sec = String(section || '').trim().toUpperCase();

  // Match 6-digit or padded e.g. 010001 or 001001 or A-010001
  var m = raw.match(/^(?:([A-Za-z0-9]+)[-_\s]+)?(\d{3})(\d{3})$/);
  if (m) {
    var x = parseInt(m[2], 10);
    var y = parseInt(m[3], 10);
    return x + ',' + y;
  }

  // Match formats like A- 1,1 or A-1,1 or A-1-1 or 1-1 or 1,1
  m = raw.match(/^(?:([A-Za-z0-9]+)[-_\s]+)?(\d+)[-_,\s]+(\d+)$/);
  if (m) {
    var x = parseInt(m[2], 10);
    var y = parseInt(m[3], 10);
    return x + ',' + y;
  }

  // If section prefix exists e.g. "A- 1,1" -> "1,1"
  raw = raw.replace(new RegExp('^' + sec + '[-_\\s]*', 'i'), '').trim();

  return raw || '1,1';
};

window.__isAdmin = true;
window.applySelectedPlotToBooking = function(data, shouldOpenModal) {
  if (!data) return;

  if (window.__openedFromAddDeceasedModal) {
    if (typeof window.applySelectedPlotToAddDeceased === 'function') {
      window.applySelectedPlotToAddDeceased(data);
    }
    return;
  }

  var plotId = data.plot_id || data.plotId || '';
  var section = (data.section || 'A').toUpperCase();
  var plotX = data.plotX || data.plot_x || '';
  var plotY = data.plotY || data.plot_y || '';
  var plotNo = (plotX && plotY) ? (plotX + ',' + plotY) : (data.plotNo || data.plot_no || '1,1');
  if (window.formatPlotNumber) {
    plotNo = window.formatPlotNumber(plotNo, section);
  }
  var lawnType = data.lawnType || data.lawn_type || 'Regular';
  if (lawnType.indexOf('Premium') !== -1) lawnType = 'Premium';
  else lawnType = 'Regular';
  var isPremium = (lawnType === 'Premium') || (Number(data.price || data.lot_price || 0) >= 89000);
  var price = Number(data.price || data.lot_price || data.base_price || (isPremium ? 89000 : 85000));
  var intermentFee = Number(data.intermentFee || data.interment_fee || 38000);
  var occupantCount = (data.occupantCount !== undefined) ? data.occupantCount : ((data.occupant_count !== undefined) ? data.occupant_count : 0);
  var maxOccupants = data.maxOccupants || data.max_occupants || 4;
  var status = (data.status || 'AVAILABLE').toUpperCase();
  var graveLocation = data.graveLocation || data.grave_location || (section && plotX && plotY ? (section + ' ' + plotX + '-' + plotY) : '');

  // Update Purpose options based on plot status
  window.updateBookingPurposeAvailability(status);

  // Availability validation feedback
  var purposeSelect = document.getElementById('bookingPurpose');
  if (status === 'OCCUPIED') {
    if (occupantCount >= maxOccupants) {
      if (purposeSelect) {
        purposeSelect.value = 'cemetery_service';
        if (typeof togglePurposeSections === 'function') togglePurposeSections();
        purposeSelect.dispatchEvent(new Event('change'));
      }
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          icon: 'info',
          title: 'Plot At Full Capacity (' + occupantCount + '/' + maxOccupants + ')',
          text: 'Plot ' + plotNo + ' is at maximum capacity (Min 2, Max 4). Reservation purpose switched to Cemetery Service.',
          confirmButtonColor: '#7b1113',
          timer: 3500,
          timerProgressBar: true
        });
      }
    } else {
      if (purposeSelect) {
        purposeSelect.value = 'add_deceased';
        if (typeof togglePurposeSections === 'function') togglePurposeSections();
        purposeSelect.dispatchEvent(new Event('change'));
      }
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          icon: 'info',
          title: 'Occupied Plot Selected',
          text: 'Plot ' + plotNo + ' has ' + occupantCount + '/' + maxOccupants + ' occupants. Booking purpose set to Add Additional Deceased.',
          confirmButtonColor: '#7b1113',
          timer: 3000,
          timerProgressBar: true
        });
      }
    }
  } else if (status === 'RESERVED') {
    if (purposeSelect) {
      purposeSelect.value = 'add_deceased';
      if (typeof togglePurposeSections === 'function') togglePurposeSections();
      purposeSelect.dispatchEvent(new Event('change'));
    }
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        icon: 'info',
        title: 'Reserved Plot Selected',
        text: 'Plot ' + plotNo + ' is already reserved. "Reserve New Grave" is disabled. You can now Add Deceased or request Cemetery Service.',
        confirmButtonColor: '#7b1113',
        timer: 3000,
        timerProgressBar: true
      });
    }
  } else if (status === 'PURCHASED') {
    if (purposeSelect) {
      purposeSelect.value = 'add_deceased';
      if (typeof togglePurposeSections === 'function') togglePurposeSections();
      purposeSelect.dispatchEvent(new Event('change'));
    }
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        icon: 'info',
        title: 'Purchased Plot Selected',
        text: 'Plot ' + plotNo + ' is purchased. "Reserve New Grave" is disabled. You can now Add Deceased or request Cemetery Service.',
        confirmButtonColor: '#7b1113',
        timer: 3000,
        timerProgressBar: true
      });
    }
  } else {
    // AVAILABLE
    if (purposeSelect) {
      purposeSelect.value = 'reserve_grave';
      if (typeof togglePurposeSections === 'function') togglePurposeSections();
      purposeSelect.dispatchEvent(new Event('change'));
    }
  }

  window.__selectedPlotsList = window.__selectedPlotsList || [];

  var popupModalEl = document.getElementById('popupSectionModal');
  var bookingModalEl = document.getElementById('bookingModal');

  function hideAdminPopupModal() {
    if (popupModalEl) {
      if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        try {
          var pModal = bootstrap.Modal.getInstance(popupModalEl) || bootstrap.Modal.getOrCreateInstance(popupModalEl);
          if (pModal) pModal.hide();
        } catch (e) {}
      }
      try {
        var closeBtn = popupModalEl.querySelector('[data-bs-dismiss="modal"]');
        if (closeBtn) closeBtn.click();
      } catch (e) {}
      popupModalEl.classList.remove('show');
      popupModalEl.style.display = 'none';
      popupModalEl.setAttribute('aria-hidden', 'true');
      popupModalEl.removeAttribute('aria-modal');
    }
  }

  // Duplicate check
  var isDup = window.__selectedPlotsList.some(function(p) {
    if (plotId && p.plot_id && String(p.plot_id) === String(plotId)) return true;
    return (p.section === section && String(p.plot_x) === String(plotX) && String(p.plot_y) === String(plotY));
  });

  if (isDup) {
    return;
  }

  // Determine allowed limit from num_lots radio
  var numLotsRadio = document.querySelector('input[name="num_lots"]:checked');
  var maxAllowedLots = (numLotsRadio && numLotsRadio.value === '2') ? 2 : 1;

  // Check lot limit
  if (maxAllowedLots === 1) {
    // 1 lot mode: if already has 1 or more, replace with this selected plot
    window.__selectedPlotsList = [];
  } else {
    // 2 lots mode: strictly reject 3 or more plots
    if (window.__selectedPlotsList.length >= 2) {
      hideAdminPopupModal();
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          icon: 'warning',
          title: 'Maximum 2 Lots Only',
          text: 'You selected 2 Lots. The system will not accept 3 or more lots for this booking.',
          confirmButtonColor: '#7b1113'
        });
      } else {
        alert('Maximum 2 Lots allowed. It will not accept 3 or more lots.');
      }
      if (shouldOpenModal !== false && bookingModalEl && typeof bootstrap !== 'undefined') {
        setTimeout(function() {
          var bModal = bootstrap.Modal.getOrCreateInstance(bookingModalEl);
          bModal.show();
        }, 200);
      }
      return;
    }
  }

  // Use plot's natural type from map (Premium if corner/89000, else Regular).
  // Do NOT use the currently checked lot_type radio here — otherwise a Premium
  // plot would be stored as Regular when Regular is selected, and vice versa.
  var naturalType = isPremium ? 'Premium' : 'Regular';
  var effectivePrice = (naturalType === 'Premium') ? 89000 : 85000;

  // Add plot to list
  window.__selectedPlotsList.push({
    plot_id: plotId,
    section: section,
    plot_x: plotX,
    plot_y: plotY,
    plot_number: plotNo,
    lawn_type: naturalType,
    price: effectivePrice,
    occupantCount: occupantCount,
    maxOccupants: maxOccupants,
    status: status,
    grave_location: graveLocation
  });

  // Also sync single input fields for backward compatibility
  var pInput = document.getElementById('plot');
  if (pInput) pInput.value = (plotX && plotY) ? (plotX + ',' + plotY) : plotNo;
  var pIdInput = document.getElementById('plot_id');
  if (pIdInput && plotId) pIdInput.value = plotId;
  var pXInput = document.getElementById('plot_x');
  if (pXInput && plotX) pXInput.value = plotX;
  var pYInput = document.getElementById('plot_y');
  if (pYInput && plotY) pYInput.value = plotY;
  var pNoDisp = document.getElementById('selectedPlotNo');
  if (pNoDisp) pNoDisp.textContent = plotNo;
  var pPriceInput = document.getElementById('plot_price');
  if (pPriceInput) pPriceInput.value = effectivePrice.toFixed(2);

  // Do NOT touch num_lots radio here. It is the user-selected limit (1 or 2).
  // Previously this forced the radio back to 1 after the first pick, making
  // it impossible to add a 2nd plot. Keep the user's 2-Lots choice intact.

  // Sync Lot Type radio from plot's natural type so UI matches table details
  if (typeof window.syncLotTypeRadioFromPlots === 'function') {
    window.syncLotTypeRadioFromPlots();
  } else {
    var lotRadio = document.querySelector('input[name="lot_type"][value="' + naturalType + '"]');
    if (lotRadio) lotRadio.checked = true;
  }

  // Section Select & Hidden Field sync
  var secSel = document.getElementById('section');
  if (secSel && section) secSel.value = section;
  var plotSec = document.getElementById('plot_section');
  if (plotSec) plotSec.value = section;

  var selectedSectionBadge = document.getElementById('selectedSectionBadge');
  if (selectedSectionBadge) selectedSectionBadge.textContent = 'Section ' + section;

  renderSelectedPlotsTable();
  calculateAdminPrice();

  // Close the section map modal cleanly
  hideAdminPopupModal();

  // Reopen / show the Admin Booking modal cleanly after popup closes
  if (shouldOpenModal !== false && bookingModalEl && typeof bootstrap !== 'undefined') {
    setTimeout(function() {
      var bookingModal = bootstrap.Modal.getOrCreateInstance(bookingModalEl);
      bookingModal.show();
    }, 200);
  }
};

document.addEventListener('DOMContentLoaded', function() {
  var bookingModalEl = document.getElementById('bookingModal');
  var sectionSelect = document.getElementById('section');
  var preferredDateInput = document.getElementById('preferredDate');
  var selectPlotBtn = document.getElementById('selectPlotBtn');
  var changePlotBtn = document.getElementById('changePlotBtn');
  var clearPlotBtn = document.getElementById('clearPlotBtn');
  var adminSubmitBtn = document.getElementById('adminSubmitBookingBtn');
  if (adminSubmitBtn) {
    adminSubmitBtn.addEventListener('click', function(e) {
      if (typeof handleBookingSubmit === 'function') {
        handleBookingSubmit(e);
      } else if (typeof window.handleBookingSubmit === 'function') {
        window.handleBookingSubmit(e);
      } else if (typeof submitBooking === 'function') {
        submitBooking();
      } else if (typeof window.submitBooking === 'function') {
        window.submitBooking();
      }
    });
  }

  // Wire up lot_type change to immediately update selected plots' lawn_type and price
  document.querySelectorAll('input[name="lot_type"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
      var newType = this.value === 'Premium' ? 'Premium' : 'Regular';
      var newPrice = newType === 'Premium' ? 89000 : 85000;
      if (window.__selectedPlotsList && window.__selectedPlotsList.length > 0) {
        window.__selectedPlotsList.forEach(function(p) {
          p.lawn_type = newType;
          p.price = newPrice;
        });
        renderSelectedPlotsTable();
      }
      calculateAdminPrice();
    });
  });

  // Wire up num_lots change to enforce 1 lot vs 2 lots
  document.querySelectorAll('input[name="num_lots"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
      var maxLots = Number(this.value) === 2 ? 2 : 1;
      if (window.__selectedPlotsList && window.__selectedPlotsList.length > maxLots) {
        window.__selectedPlotsList = window.__selectedPlotsList.slice(0, maxLots);
        renderSelectedPlotsTable();
      }
      calculateAdminPrice();
    });
  });

  // Wire up area & payment_option recalculation
  document.querySelectorAll('input[name="area"], input[name="payment_option"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
      calculateAdminPrice();
    });
  });

  var paymentTermSelect = document.getElementById('paymentTerm');
  if (paymentTermSelect) {
    paymentTermSelect.addEventListener('change', function() {
      calculateAdminPrice();
    });
  }

  var bookingPurposeSelect = document.getElementById('bookingPurpose');
  if (bookingPurposeSelect) {
    bookingPurposeSelect.addEventListener('change', function() {
      var effStatus = (typeof window.getEffectivePlotsStatus === 'function') ? window.getEffectivePlotsStatus() : 'AVAILABLE';
      if (effStatus === 'AVAILABLE' && this.value !== 'reserve_grave') {
        this.value = 'reserve_grave';
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'warning',
            title: 'Option Not Available',
            text: 'Available/unoccupied plots can only be reserved for "Reserve New Grave". "Add Deceased" and "Cemetery Service" are only allowed for reserved or occupied plots.',
            confirmButtonColor: '#7b1113'
          });
        }
      }
      calculateAdminPrice();
    });
  }

  // Hook modal show event to enforce purpose options whenever modal opens
  if (bookingModalEl) {
    bookingModalEl.addEventListener('show.bs.modal', function() {
      var effStatus = (typeof window.getEffectivePlotsStatus === 'function') ? window.getEffectivePlotsStatus() : 'AVAILABLE';
      if (typeof window.updateBookingPurposeAvailability === 'function') {
        window.updateBookingPurposeAvailability(effStatus);
      }
      if (typeof window.calculateAdminPrice === 'function') {
        window.calculateAdminPrice();
      }
    });
  }

  var adminServiceTypeSelect = document.getElementById('adminServiceType');
  if (adminServiceTypeSelect) {
    adminServiceTypeSelect.addEventListener('change', function() {
      calculateAdminPrice();
    });
  }

  var adminPayMethodSelect = document.getElementById('paymentMethod');
  if (adminPayMethodSelect) {
    var toggleAdminGcashUI = function() {
      var gBox = document.getElementById('adminGcashPaymentContainer');
      if (gBox) {
        gBox.style.display = (adminPayMethodSelect.value === 'GCash') ? 'block' : 'none';
      }
    };
    adminPayMethodSelect.addEventListener('change', toggleAdminGcashUI);
    toggleAdminGcashUI();
  }

  function setPopupModalMode(mode, sectionName) {
    var bookingHdr = document.getElementById('adminPopupSectionBookingHeader');
    var viewHdr = document.getElementById('adminPopupSectionViewHeader');
    var viewTitle = document.getElementById('adminPopupSectionViewTitle');
    var modalHdr = document.querySelector('#popupSectionModal .modal-header');
    var mBody = document.querySelector('#popupSectionModal .modal-body');

    if (mode === 'booking') {
      if (modalHdr) modalHdr.style.setProperty('display', 'flex', 'important');
      if (bookingHdr) bookingHdr.style.setProperty('display', 'flex', 'important');
      if (viewHdr) viewHdr.style.setProperty('display', 'none', 'important');
      if (mBody) mBody.style.height = 'calc(95vh - 58px)';
    } else {
      // In View Mode: The embedded map in popup.php already has its own map header and close button.
      // Hide the outer modal header so only the map's single close button is visible.
      if (modalHdr) modalHdr.style.setProperty('display', 'none', 'important');
      if (bookingHdr) bookingHdr.style.setProperty('display', 'none', 'important');
      if (viewHdr) viewHdr.style.setProperty('display', 'none', 'important');
      if (mBody) mBody.style.height = '94vh';
      if (viewTitle && sectionName) {
        viewTitle.textContent = 'Cemetery Map - Section ' + sectionName.toUpperCase();
      }
    }
  }
  window.setPopupModalMode = setPopupModalMode;
  window.__openedFromBookingModal = false;

  if (selectPlotBtn) {
    selectPlotBtn.addEventListener('click', function() {
      window.__openedFromBookingModal = true;
      if (typeof window.setPopupModalMode === 'function') window.setPopupModalMode('booking');
      var sectionSelect = document.getElementById('section');
      var section = (sectionSelect && sectionSelect.value) ? sectionSelect.value : 'A';
      var iframe = document.getElementById('popupSectionIframe');
      var popupModalEl = document.getElementById('popupSectionModal');
      var bookingModalEl = document.getElementById('bookingModal');
      if (!iframe || !popupModalEl) return;

      var adminSectionSel = document.getElementById('adminPopupSectionSelect');
      if (adminSectionSel) adminSectionSel.value = section;
      var url = 'sections/popup.php?selection_mode=booking&section=' + encodeURIComponent(section) + '&source=admin&is_admin=1';
      iframe.src = url + '&t=' + Date.now();

      // Cleanly hide booking modal first so backdrops don't collide
      if (bookingModalEl && typeof bootstrap !== 'undefined') {
        var bModal = bootstrap.Modal.getInstance(bookingModalEl);
        if (bModal) bModal.hide();
      }

      setTimeout(function() {
        var pModal = bootstrap.Modal.getOrCreateInstance(popupModalEl);
        pModal.show();
      }, 250);
    });
  }

  var popupSectionModalEl = document.getElementById('popupSectionModal');
  if (popupSectionModalEl) {
    popupSectionModalEl.addEventListener('hidden.bs.modal', function() {
      var iframe = document.getElementById('popupSectionIframe');
      if (iframe) iframe.src = 'about:blank';

      // Only reopen booking modal IF popupSectionModal was opened from booking modal!
      if (window.__openedFromBookingModal) {
        window.__openedFromBookingModal = false;
        var bookingModalEl = document.getElementById('bookingModal');
        if (bookingModalEl && !bookingModalEl.classList.contains('show')) {
          setTimeout(function() {
            var bModal = bootstrap.Modal.getOrCreateInstance(bookingModalEl);
            bModal.show();
          }, 150);
        }
      } else if (window.__openedFromTransferModal) {
        window.__openedFromTransferModal = false;
        var transModalEl = document.getElementById('transferRemainsModal');
        if (transModalEl && !transModalEl.classList.contains('show')) {
          setTimeout(function() {
            var tModal = bootstrap.Modal.getOrCreateInstance(transModalEl);
            tModal.show();
          }, 150);
        }
      } else if (window.__openedFromAddDeceasedModal) {
        window.__openedFromAddDeceasedModal = false;
        var addModalEl = document.getElementById('addDeceasedModal');
        if (addModalEl && !addModalEl.classList.contains('show')) {
          setTimeout(function() {
            var aModal = bootstrap.Modal.getOrCreateInstance(addModalEl);
            aModal.show();
          }, 150);
        }
      }
    });
  }

  var adminSectionSel = document.getElementById('adminPopupSectionSelect');
  if (adminSectionSel) {
    adminSectionSel.addEventListener('change', function() {
      var sec = this.value || 'A';
      var secSelect = document.getElementById('section');
      if (secSelect) secSelect.value = sec;
      var iframe = document.getElementById('popupSectionIframe');
      if (iframe) {
        var mode = window.__openedFromTransferModal ? 'transfer&transfer_mode=1' : 'booking';
        iframe.src = 'sections/popup.php?selection_mode=' + mode + '&section=' + encodeURIComponent(sec) + '&source=admin&is_admin=1&t=' + Date.now();
      }
    });
  }

  var sectionSelectEl = document.getElementById('section');
  if (sectionSelectEl) {
    sectionSelectEl.addEventListener('change', function() {
      var sVal = (this.value || 'A').toUpperCase();
      var adminSecSel = document.getElementById('adminPopupSectionSelect');
      if (adminSecSel) adminSecSel.value = sVal;
      if (window.__selectedPlotData && window.__selectedPlotData.section !== sVal) {
        window.__selectedPlotData.section = sVal;
      }
      calculateAdminPrice();
    });
  }

  // Automatic two-way iframe load listener to sync adminPopupSectionSelect with active section
  var popupSectionIframeEl = document.getElementById('popupSectionIframe');
  if (popupSectionIframeEl) {
    popupSectionIframeEl.addEventListener('load', function() {
      try {
        var src = popupSectionIframeEl.contentWindow.location.href || popupSectionIframeEl.src || '';
        var m = src.match(/[?&]section=([A-Za-z])/i);
        if (m && m[1]) {
          var sVal = m[1].toUpperCase();
          var adminSecSel = document.getElementById('adminPopupSectionSelect');
          if (adminSecSel) adminSecSel.value = sVal;
          var bSecSel = document.getElementById('section');
          if (bSecSel && bSecSel.value !== sVal) bSecSel.value = sVal;
        }
      } catch (e) {
        var m2 = (popupSectionIframeEl.src || '').match(/[?&]section=([A-Za-z])/i);
        if (m2 && m2[1]) {
          var sVal2 = m2[1].toUpperCase();
          var adminSecSel2 = document.getElementById('adminPopupSectionSelect');
          if (adminSecSel2) adminSecSel2.value = sVal2;
        }
      }
    });
  }

  if (changePlotBtn) {
    changePlotBtn.addEventListener('click', function() {
      var selectPlot = document.getElementById('selectPlotBtn');
      if (selectPlot) selectPlot.click();
    });
  }

  if (clearPlotBtn) {
    clearPlotBtn.addEventListener('click', function() {
      window.__selectedPlotData = null;
      var plotInput = document.getElementById('plot');
      if (plotInput) plotInput.value = 'AUTO';
      var plotIdInput = document.getElementById('plot_id');
      if (plotIdInput) plotIdInput.value = '';
      var plotXInput = document.getElementById('plot_x');
      if (plotXInput) plotXInput.value = '';
      var plotYInput = document.getElementById('plot_y');
      if (plotYInput) plotYInput.value = '';
      var plotPrice = document.getElementById('plot_price');
      if (plotPrice) plotPrice.value = '85000.00';
      calculateAdminPrice();
    });
  }

  window.addEventListener('message', function(event) {
    var data = event.data;
    if (typeof data === 'string') {
      try { data = JSON.parse(data); } catch (e) {}
    }
    if (!data || (data.type !== 'bookingPlotSelected' && data.type !== 'transferPlotSelected' && data.type !== 'plotSelected')) return;
    if (window.__openedFromAddDeceasedModal) {
      if (typeof window.applySelectedPlotToAddDeceased === 'function') {
        window.applySelectedPlotToAddDeceased(data);
      }
      return;
    }
    if (typeof window.applySelectedPlotToBooking === 'function') {
      window.applySelectedPlotToBooking(data, true);
    }
  });

  if (preferredDateInput) {
    if (!preferredDateInput.value) {
      try { preferredDateInput.value = new Date().toISOString().split('T')[0]; } catch (e) {}
    }
    preferredDateInput.addEventListener('change', function() {
      calculateAdminPrice();
    });
  }

  if (sectionSelect) {
    sectionSelect.addEventListener('change', function() {
      var sec = this.value || 'A';
      var plotInput = document.getElementById('plot');
      if (!plotInput || plotInput.value === 'AUTO' || !plotInput.value) {
        var pNo = document.getElementById('selectedPlotNo');
        if (pNo) pNo.textContent = '1,1';
      }
      var badge = document.getElementById('selectedSectionBadge');
      if (badge) badge.textContent = 'Section ' + sec;
      var selSec = document.getElementById('selectedPlotSection');
      if (selSec) selSec.textContent = sec;
      calculateAdminPrice();
    });
  }

  // Initial calculation
  calculateAdminPrice();
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value).replace(/[&<>"']/g, function(m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
  }
  window.escapeHtml = escapeHtml;

  document.querySelectorAll('.topnav-link[href="#contact"]').forEach(function(link) {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      var modal = new bootstrap.Modal(document.getElementById('contactModal'));
      modal.show();
    });
  });

  // Row click to show details modal (ignore clicks on buttons)
  document.querySelectorAll('table tbody tr.result-row').forEach(function(tr) {
    tr.addEventListener('click', function(e) {
      if (e.target.closest('button')) return;
      // Blink first
      var cls = 'blink-once';
      tr.classList.remove(cls);
      void tr.offsetWidth; // restart animation
      tr.classList.add(cls);
      // After a short delay, open section popup with plot highlighted
      setTimeout(function(){
        window.__openedFromBookingModal = false;
        var section = (tr.getAttribute('data-section') || '').trim();
        if (typeof window.setPopupModalMode === 'function') window.setPopupModalMode('view', section);
        var px = tr.getAttribute('data-plot-x') || '';
        var py = tr.getAttribute('data-plot-y') || '';
        if (!section) return;
        var base = 'sections/popup.php?section=' + encodeURIComponent(section) + '&plot_x=' + encodeURIComponent(px) + '&plot_y=' + encodeURIComponent(py);
        var url = base + (base.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
        var iframe = document.getElementById('popupSectionIframe');
        if (!iframe) return;
        var adminSecSel = document.getElementById('adminPopupSectionSelect');
        if (adminSecSel && section) adminSecSel.value = section.toUpperCase();
        try { iframe.contentWindow.scrollTo(0, 0); } catch (e) {}
        iframe.src = '';
        iframe.addEventListener('load', function onLoadOnce() {
          iframe.removeEventListener('load', onLoadOnce);
          try { iframe.contentWindow.scrollTo(0, 0); } catch (e) {}
        });
        iframe.src = url;
        var modal = new bootstrap.Modal(document.getElementById('popupSectionModal'));
        modal.show();
      }, 280);
    });
  });

  // Check URL query or hash to auto-open booking modal if navigated from other pages
  var urlParams = new URLSearchParams(window.location.search);
  var directBooking = urlParams.get('booking') === '1' || urlParams.get('open') === 'booking' || window.location.hash === '#booking' || window.location.hash === '#bookingModal';
  var directSection = urlParams.get('section') || '';
  var directPlotX = urlParams.get('plot_x') || '';
  var directPlotY = urlParams.get('plot_y') || '';

  if (directBooking) {
    var bookingModalEl = document.getElementById('bookingModal');
    if (bookingModalEl && window.bootstrap && bootstrap.Modal) {
      setTimeout(function() {
        var initialModal = bootstrap.Modal.getOrCreateInstance(bookingModalEl);
        initialModal.show();
      }, 200);
    }
    if (directSection && directPlotX && directPlotY) {
      fetch('sections/popup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          ajax_request: 'get_plot_booking_status',
          section: directSection,
          plot_x: directPlotX,
          plot_y: directPlotY
        })
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data && data.success) {
          window.applySelectedPlotToBooking(data, true);
        }
      })
      .catch(function(err) { console.error('Error pre-loading plot for booking:', err); });
    }
  }

  function normalizeAdminPaymentMethod(rawValue) {
    var value = (rawValue || '').toString().trim().toLowerCase();
    if (value === 'gcash') return 'GCash';
    return 'Cash on Site';
  }
  window.normalizeAdminPaymentMethod = normalizeAdminPaymentMethod;

  window.openAdminCemeteryTermsModal = function() {
    var modalEl = document.getElementById('adminCemeteryTermsModal');
    if (!modalEl) return;
    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.show();
  };

  // Booking form submission
  function submitBooking() {
    var form = document.getElementById('bookingForm');
    if (!form) return false;
    if (window.__adminBookingSubmitting) return false;

    var alertBox = document.getElementById('adminBookingAlert');
    if (alertBox) { alertBox.style.display = 'none'; alertBox.textContent = ''; }

    var customerName = (document.getElementById('customerName') ? document.getElementById('customerName').value : '').trim();
    var customerSuffix = (document.getElementById('customerSuffix') ? document.getElementById('customerSuffix').value : '').trim();
    if (customerSuffix && customerName && !customerName.toUpperCase().endsWith(customerSuffix.toUpperCase())) {
      customerName += ' ' + customerSuffix;
    }
    var contactNumber = (document.getElementById('contactNumber') ? document.getElementById('contactNumber').value : '').trim();
    var preferredDate = (document.getElementById('preferredDate') ? document.getElementById('preferredDate').value : '').trim();
    var bookerLocation = (document.getElementById('bookerLocation') ? document.getElementById('bookerLocation').value : '').trim();
    var section = (document.getElementById('section') ? document.getElementById('section').value : '').trim();
    var plot = (document.getElementById('plot') ? document.getElementById('plot').value : 'AUTO').trim();

    function showInlineError(msg, focusId) {
      if (alertBox) {
        alertBox.textContent = msg;
        alertBox.style.display = 'block';
      }
      if (typeof Swal !== 'undefined') {
        Swal.fire({ icon: 'warning', title: 'Required Field', text: msg, confirmButtonColor: '#7b1113' });
      } else {
        alert(msg);
      }
      if (focusId && document.getElementById(focusId)) {
        document.getElementById(focusId).focus();
      }
      return false;
    }

    if (!customerName) return showInlineError('Please enter Client / Buyer Full Name.', 'customerName');
    if (!contactNumber || !/^09\d{9}$/.test(contactNumber)) return showInlineError('Please enter a valid 11-digit mobile number starting with 09 (e.g., 09XXXXXXXXX).', 'contactNumber');
    if (!preferredDate) return showInlineError('Please select a Preferred Date.', 'preferredDate');
    if (!bookerLocation) return showInlineError('Please enter Client Address / Location.', 'bookerLocation');
    if (!section) return showInlineError('Please select a Section.', 'section');
    if (!plot || plot === 'AUTO') {
      return showInlineError('Please click "Choose Plot from Map" to choose an available plot.', 'selectPlotBtn');
    }

    var termsCb = document.getElementById('adminBookingTermsAgreement');
    if (termsCb && !termsCb.checked) {
      return showInlineError('Please confirm and check the Cemetery Terms & Agreements acknowledgement before submitting the booking.', 'adminBookingTermsAgreement');
    }

    var purpose = (document.getElementById('bookingPurpose') ? document.getElementById('bookingPurpose').value : 'reserve_grave');
    var isCemService = (purpose === 'cemetery_service');

    var effStatus = (typeof window.getEffectivePlotsStatus === 'function') ? window.getEffectivePlotsStatus() : 'AVAILABLE';
    if (effStatus === 'AVAILABLE' && (purpose === 'add_deceased' || purpose === 'cemetery_service')) {
      return showInlineError('The selected plot is AVAILABLE (vacant). "Add Deceased" and "Cemetery Service" can only be performed on plots that are already RESERVED or OCCUPIED. Please choose "Reserve New Grave".', 'bookingPurpose');
    }
    var lotTypeEl = document.querySelector('input[name="lot_type"]:checked');
    var lotType = lotTypeEl ? lotTypeEl.value : 'Regular';
    var areaEl = document.querySelector('input[name="area"]:checked');
    var area = areaEl ? areaEl.value : 'Lawn';
    var numLotsEl = document.querySelector('input[name="num_lots"]:checked');
    var numLots = numLotsEl ? Number(numLotsEl.value) : 1;
    var paymentPlanEl = document.querySelector('input[name="payment_option"]:checked');
    var paymentPlan = isCemService ? 'full' : (paymentPlanEl ? paymentPlanEl.value : 'full');
    var paymentTerm = (!isCemService && document.getElementById('paymentTerm') && paymentPlan === 'installment') ? Number(document.getElementById('paymentTerm').value) : null;
    var paymentMethod = (document.getElementById('paymentMethod') ? document.getElementById('paymentMethod').value : 'Cash on Site');
    var deceasedName = (document.getElementById('deceasedName') ? document.getElementById('deceasedName').value : '').trim();
    var deceasedSuffix = (document.getElementById('deceasedSuffix') ? document.getElementById('deceasedSuffix').value : '').trim();
    if (deceasedSuffix && deceasedName && !deceasedName.toUpperCase().endsWith(deceasedSuffix.toUpperCase())) {
      deceasedName += ' ' + deceasedSuffix;
    }
    var remarks = (document.getElementById('bookingRemarks') ? document.getElementById('bookingRemarks').value : '').trim();
    var plotId = (document.getElementById('plot_id') ? document.getElementById('plot_id').value : '');
    var plotX = (document.getElementById('plot_x') ? document.getElementById('plot_x').value : '');
    var plotY = (document.getElementById('plot_y') ? document.getElementById('plot_y').value : '');
    var plotPriceVal = Number(document.getElementById('plot_price') ? document.getElementById('plot_price').value : 85000);
    var adminSvcEl = document.getElementById('adminServiceType') || document.getElementById('serviceType');
    var adminSvcOpt = adminSvcEl && adminSvcEl.selectedOptions ? adminSvcEl.selectedOptions[0] : null;
    var defaultSvcPrice = adminSvcOpt ? Number(adminSvcOpt.dataset.price || 200) : 200;
    var servicePriceVal = Number(document.getElementById('service_price') ? document.getElementById('service_price').value : defaultSvcPrice);
    if (isCemService && (!servicePriceVal || servicePriceVal <= 0)) servicePriceVal = 200;
    var intermentFeeVal = Number(document.getElementById('interment_fee') ? document.getElementById('interment_fee').value : 38000) || 38000;
    var hasSelectedPlots = (window.__selectedPlotsList && window.__selectedPlotsList.length > 0);
    if (hasSelectedPlots) {
      numLots = window.__selectedPlotsList.length;
    }

    var totalAmount = 0;
    var initialPayment = 0;
    var monthlyFee = 0;

    if (purpose === 'reserve_grave') {
      if (hasSelectedPlots) {
        var plotSubtotal = window.__selectedPlotsList.reduce(function(acc, p) {
          return acc + Number(p.price || (p.lawn_type === 'Premium' ? 89000 : 85000));
        }, 0);
        if (paymentPlan === 'installment' && paymentTerm) {
          monthlyFee = 0;
          window.__selectedPlotsList.forEach(function(p) {
            var pType = (p.lawn_type === 'Premium' || p.lawnType === 'Premium') ? 'Premium' : 'Regular';
            var m = (OFFICIAL_AMORTIZATION[pType] && OFFICIAL_AMORTIZATION[pType][paymentTerm]) 
              ? OFFICIAL_AMORTIZATION[pType][paymentTerm] 
              : Math.round((Number(p.price || 85000) * 0.80) / paymentTerm);
            monthlyFee += m;
          });
          var downpaymentVal = Math.round(plotSubtotal * 0.20);
          totalAmount = downpaymentVal + (monthlyFee * paymentTerm);
          initialPayment = downpaymentVal;
        } else {
          totalAmount = plotSubtotal;
          initialPayment = plotSubtotal;
        }
      } else {
        var calculatedPlotPrice = plotPriceVal * numLots;
        if (paymentPlan === 'installment' && paymentTerm) {
          var monthlyPerLot = (OFFICIAL_AMORTIZATION[lotType] && OFFICIAL_AMORTIZATION[lotType][paymentTerm]) 
            ? OFFICIAL_AMORTIZATION[lotType][paymentTerm] 
            : Math.round((plotPriceVal * 0.80) / paymentTerm);
          monthlyFee = monthlyPerLot * numLots;
          var downpaymentVal = Math.round(calculatedPlotPrice * 0.20);
          totalAmount = downpaymentVal + (monthlyFee * paymentTerm);
          initialPayment = downpaymentVal;
        } else {
          totalAmount = calculatedPlotPrice;
          initialPayment = totalAmount;
        }
      }
    } else if (purpose === 'add_deceased') {
      totalAmount = intermentFeeVal;
      initialPayment = totalAmount;
    } else {
      totalAmount = servicePriceVal;
      initialPayment = totalAmount;
    }

    // GCash validation if payment method is GCash
    var payNowDirect = (window.__payNowDirect === true);
    window.__payNowDirect = false;
    if (paymentMethod === 'GCash' && !payNowDirect) {
      var gcashProofInput = document.getElementById('adminGcashProof');
      var gcashRefInput = document.getElementById('adminGcashRef');
      if (!gcashProofInput || !gcashProofInput.files || !gcashProofInput.files[0]) {
        return showInlineError('Please attach a proof of payment screenshot for GCash downpayment.', 'adminGcashProof');
      }
      if (!gcashRefInput || !gcashRefInput.value.trim()) {
        return showInlineError('Please enter the GCash reference number.', 'adminGcashRef');
      }
    }

    var formData = new FormData();
    formData.append('action', 'submit_booking');
    formData.append('source', 'admin');
    formData.append('customer_name', customerName);
    formData.append('customer_suffix', customerSuffix);
    formData.append('contact_number', contactNumber);
    formData.append('preferred_date', preferredDate);
    formData.append('booker_location', bookerLocation);
    formData.append('deceased_name', deceasedName);
    formData.append('deceased_suffix', deceasedSuffix);
    formData.append('remarks', remarks);
    formData.append('special_requests', remarks);
    formData.append('section', section);
    formData.append('plot', plot);
    formData.append('plot_id', plotId);
    formData.append('plot_x', plotX);
    formData.append('plot_y', plotY);
    formData.append('grave_location', section + ' ' + (plot || 'Auto'));
    formData.append('purpose', purpose);
    formData.append('selected_plots_json', JSON.stringify(window.__selectedPlotsList || []));
    if (purpose === 'add_deceased') {
      var decCards = document.querySelectorAll('#adminDeceasedCardsContainer .admin-deceased-card');
      var deceasedEntries = [];
      var validationError = null;

      if (decCards && decCards.length > 0) {
        decCards.forEach(function(card, idx) {
          if (validationError) return;

          var plotSel = card.querySelector('.admin-dec-target-plot');
          var plotVal = plotSel ? plotSel.value : '';
          var parts = plotVal ? plotVal.split(':') : [];
          var cSec = parts[0] || section;
          var cPx = parts[1] || plotX;
          var cPy = parts[2] || plotY;

          var fn = (card.querySelector('.dec-first-name') ? card.querySelector('.dec-first-name').value : '').trim();
          var mn = (card.querySelector('.dec-middle-name') ? card.querySelector('.dec-middle-name').value : '').trim();
          var ln = (card.querySelector('.dec-last-name') ? card.querySelector('.dec-last-name').value : '').trim();
          var suf = (card.querySelector('.dec-suffix') ? card.querySelector('.dec-suffix').value : '').trim();
          var bday = (card.querySelector('.dec-bday') ? card.querySelector('.dec-bday').value : '').trim();
          var died = (card.querySelector('.dec-date-died') ? card.querySelector('.dec-date-died').value : '').trim();
          var interment = (card.querySelector('.dec-interment-date') ? card.querySelector('.dec-interment-date').value : '').trim() || died;
          var rel = (card.querySelector('.dec-relationship') ? card.querySelector('.dec-relationship').value : '').trim();

          if (!fn || !ln || !died) {
            validationError = {
              msg: 'Please enter the required deceased person details (First Name, Last Name, Date of Death) for Deceased Person #' + (idx + 1) + '.',
              el: card.querySelector('.dec-first-name')
            };
            return;
          }

          deceasedEntries.push({
            card_index: idx + 1,
            target_plot: plotVal,
            section: cSec,
            plot_x: cPx,
            plot_y: cPy,
            first_name: fn,
            middle_name: mn,
            last_name: ln,
            suffix: suf,
            bday: bday,
            date_died: died,
            interment_date: interment,
            relationship: rel
          });
        });
      } else {
        var decFirst = (document.getElementById('admin_dec_first_name') ? document.getElementById('admin_dec_first_name').value : '').trim();
        var decMid = (document.getElementById('admin_dec_middle_name') ? document.getElementById('admin_dec_middle_name').value : '').trim();
        var decLast = (document.getElementById('admin_dec_last_name') ? document.getElementById('admin_dec_last_name').value : '').trim();
        var decSuf = (document.getElementById('admin_dec_suffix') ? document.getElementById('admin_dec_suffix').value : '').trim();
        var decBday = (document.getElementById('admin_dec_birthday') ? document.getElementById('admin_dec_birthday').value : '').trim();
        var decDied = (document.getElementById('admin_dec_date_died') ? document.getElementById('admin_dec_date_died').value : '').trim();
        var decInterment = (document.getElementById('admin_dec_interment_date') ? document.getElementById('admin_dec_interment_date').value : '').trim() || decDied;
        var decRel = (document.getElementById('admin_dec_relationship') ? document.getElementById('admin_dec_relationship').value : '').trim();

        if (!decFirst || !decLast || !decDied) {
          return showInlineError('Please enter the required deceased person details (First Name, Last Name, Date of Death).', 'admin_dec_first_name');
        }
        deceasedEntries.push({
          card_index: 1,
          target_plot: section + ':' + plotX + ':' + plotY,
          section: section,
          plot_x: plotX,
          plot_y: plotY,
          first_name: decFirst,
          middle_name: decMid,
          last_name: decLast,
          suffix: decSuf,
          bday: decBday,
          date_died: decDied,
          interment_date: decInterment,
          relationship: decRel
        });
      }

      if (validationError) {
        if (validationError.el && typeof validationError.el.focus === 'function') validationError.el.focus();
        return showInlineError(validationError.msg, validationError.el ? validationError.el.id : '');
      }

      if (deceasedEntries.length === 0) {
        return showInlineError('Please add at least one deceased person record.', 'bookingPurpose');
      }

      formData.append('deceased_entries_json', JSON.stringify(deceasedEntries));

      var firstEntry = deceasedEntries[0];
      formData.append('first_name', firstEntry.first_name);
      formData.append('middle_name', firstEntry.middle_name);
      formData.append('last_name', firstEntry.last_name);
      formData.append('suffix', firstEntry.suffix);
      formData.append('deceased_first_name', firstEntry.first_name);
      formData.append('deceased_middle_name', firstEntry.middle_name);
      formData.append('deceased_last_name', firstEntry.last_name);
      formData.append('deceased_suffix', firstEntry.suffix);

      var formattedNames = deceasedEntries.map(function(d) {
        var fullName = [d.first_name, d.middle_name, d.last_name, d.suffix].filter(Boolean).join(' ');
        var plotPart = (d.section && d.plot_x && d.plot_y) ? (' (' + d.section + ' ' + d.plot_x + ',' + d.plot_y + ')') : '';
        return fullName + plotPart;
      }).join(', ');
      formData.append('deceased_name', formattedNames);

      formData.append('bday', firstEntry.bday);
      formData.append('deceased_birthday', firstEntry.bday);
      formData.append('date_died', firstEntry.date_died);
      formData.append('deceased_date_died', firstEntry.date_died);
      formData.append('interment_date', firstEntry.interment_date);
      formData.append('deceased_interment_date', firstEntry.interment_date);
      formData.append('relationship', firstEntry.relationship);
      formData.append('deceased_relationship', firstEntry.relationship);

      intermentFeeVal = 38000 * deceasedEntries.length;
      // For add_deceased, total is ONLY the interment fee — do NOT include servicePriceVal
      totalAmount = intermentFeeVal;
      initialPayment = totalAmount;
    }
    if (isCemService) {
      var sType = (adminSvcEl && adminSvcEl.value) ? adminSvcEl.value : 'Maintenance';
      formData.append('service_type', sType);
    }
    formData.append('lot_type', lotType);
    formData.append('area', area);
    formData.append('num_lots', numLots);
    formData.append('numLots', numLots);
    formData.append('payment_option', paymentPlan);
    formData.append('payment_plan', paymentPlan);
    if (paymentTerm) formData.append('payment_term', paymentTerm);
    formData.append('payment_method', paymentMethod);
    formData.append('plot_price', plotPriceVal.toFixed(2));
    formData.append('service_price', servicePriceVal.toFixed(2));
    formData.append('interment_fee', intermentFeeVal.toFixed(2));
    formData.append('total_amount', totalAmount.toFixed(2));
    formData.append('initial_payment', initialPayment.toFixed(2));

    var adminProofEl = document.getElementById('adminGcashProof');
    if (adminProofEl && adminProofEl.files && adminProofEl.files[0]) {
      formData.append('payment_proof', adminProofEl.files[0]);
    }
    var adminRefEl = document.getElementById('adminGcashRef');
    if (adminRefEl && adminRefEl.value.trim()) {
      formData.append('payment_reference', adminRefEl.value.trim());
      formData.append('gcash_reference', adminRefEl.value.trim());
    }

    function escapeHtml(str) {
      if (str === null || str === undefined) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    // Cleanly close modal and eradicate any backdrop blockers
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

    var submitBtn = document.getElementById('adminSubmitBookingBtn');
    var originalHtml = submitBtn ? submitBtn.innerHTML : 'Submit Reservation';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Submitting Reservation...';
    }
    window.__adminBookingSubmitting = true;

    // Immediately close booking modal and show Processing Modal
    cleanupAllBackdrops();

    var isGcashSelected = (paymentMethod === 'GCash');

    if (typeof Swal !== 'undefined') {
      Swal.fire({
        title: (payNowDirect || isGcashSelected) ? 'Redirecting to GCash...' : 'Processing Reservation...',
        html: '<div class="py-3 text-center">' +
                '<div class="mb-3">' +
                  '<div class="spinner-border ' + ((payNowDirect || isGcashSelected) ? 'text-primary' : 'text-danger') + '" style="width: 3.5rem; height: 3.5rem; border-width: 4px;' + ((payNowDirect || isGcashSelected) ? ' color: #007dfe !important;' : '') + '" role="status"></div>' +
                '</div>' +
                '<h5 class="fw-bold text-dark mb-1">Please wait a moment</h5>' +
                '<p class="text-muted mb-3" style="font-size: 0.92rem;">' +
                  (isGcashSelected ? 'Saving booking records and preparing GCash checkout...' : 'Saving booking records and securing the plot reservation...') +
                '</p>' +
                ((payNowDirect || isGcashSelected) ? '<div class="progress" style="height: 6px; border-radius: 4px; overflow: hidden; background-color: #e9ecef;"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%; background-color: #007dfe;"></div></div>' : '') +
              '</div>',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        showCancelButton: false
      });
    }

    fetch('dashboard.php', {
      method: 'POST',
      body: formData
    })
    .then(function(res) {
      return res.text().then(function(text) {
        if (!res.ok) throw new Error('Server error ' + res.status + ': ' + text);
        try {
          return JSON.parse(text);
        } catch (e) {
          throw new Error('Invalid JSON response from server: ' + text);
        }
      });
    })
    .then(function(data) {
      if (!data || !data.success) {
        throw new Error((data && data.message) ? data.message : 'Unable to create booking.');
      }

      var bookingId = data.booking_id || data.id || '';
      var checkoutAmount = (paymentPlan === 'installment') ? (Number(data.initial_payment) || initialPayment) : (Number(data.amount) || totalAmount);
      var displayAmountFormatted = '₱' + checkoutAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      var totalContractFormatted = '₱' + totalAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

      cleanupAllBackdrops();

      if (paymentMethod === 'GCash') {
        // Build return URL so gateway sends admin back to this form showing PAID status
        var returnBase = window.location.protocol + '//' + window.location.host + window.location.pathname;
        var returnUrl = returnBase + '?booking_pay_return=1&booking_id=' + encodeURIComponent(bookingId);
        var gatewayUrl = 'gcash_gateway.php?booking_id=' + encodeURIComponent(bookingId) + '&amount=' + encodeURIComponent(checkoutAmount.toFixed(2)) + '&source=admin&return_url=' + encodeURIComponent(returnUrl);
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            title: 'Redirecting to GCash...',
            html: '<div class="py-3 text-center">' +
                    '<div class="mb-3">' +
                      '<div class="spinner-border text-primary" style="width: 3.5rem; height: 3.5rem; border-width: 4px; color: #007dfe !important;" role="status"></div>' +
                    '</div>' +
                    '<h5 class="fw-bold text-dark mb-1">Booking #' + bookingId + ' Created!</h5>' +
                    '<p class="text-muted mb-3" style="font-size: 0.92rem;">Transferring to GCash payment gateway (' + displayAmountFormatted + ')...</p>' +
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
          window.location.href = gatewayUrl;
        }, 1300);
        return;
      }

      // Cash on Site Flow: Show Rich Successful Modal
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          icon: 'success',
          title: 'Booking Successfully Created!',
          html: '<div class="text-start mt-2" style="font-size: 0.95rem; line-height: 1.6;">' +
                  '<div class="alert alert-success d-flex align-items-center py-2 px-3 mb-3 border-0 bg-success bg-opacity-10 text-success">' +
                    '<i class="fas fa-check-circle me-2 fs-5"></i>' +
                    '<div>Booking <strong>#' + bookingId + '</strong> recorded in system.</div>' +
                  '</div>' +
                  '<div class="card border-0 bg-light p-3 mb-3">' +
                    '<div class="d-flex justify-content-between mb-1"><span class="text-muted">Client / Buyer:</span><span class="fw-bold text-dark">' + escapeHtml(customerName) + '</span></div>' +
                    (deceasedName ? ('<div class="d-flex justify-content-between mb-1"><span class="text-muted">Deceased:</span><span class="fw-bold text-dark">' + escapeHtml(deceasedName) + '</span></div>') : '') +
                    '<div class="d-flex justify-content-between mb-1"><span class="text-muted">Plot:</span><span class="fw-bold text-dark">Section ' + escapeHtml(section) + ' (' + escapeHtml(plot) + ')</span></div>' +
                    '<div class="d-flex justify-content-between mb-1"><span class="text-muted">Property:</span><span class="fw-semibold text-dark">' + escapeHtml(area) + ' (' + escapeHtml(lotType) + ') &bull; ' + numLots + ' lot(s)</span></div>' +
                    '<div class="d-flex justify-content-between mb-1"><span class="text-muted">Plan:</span><span class="fw-semibold text-dark">' + (paymentPlan === 'installment' ? ('Installment (' + paymentTerm + ' mos)') : 'Full Payment') + '</span></div>' +
                    '<div class="d-flex justify-content-between mb-1"><span class="text-muted">Payment Method:</span><span class="badge bg-secondary">Cash on Site</span></div>' +
                    '<hr class="my-2">' +
                    (paymentPlan === 'installment' 
                      ? ('<div class="d-flex justify-content-between align-items-center mb-1"><span class="fw-bold text-dark">Initial Payment Due:</span><span class="fs-5 fw-bold text-success">' + displayAmountFormatted + '</span></div>' +
                         '<div class="d-flex justify-content-between text-muted small"><span>Total Contract:</span><span>' + totalContractFormatted + '</span></div>')
                      : ('<div class="d-flex justify-content-between align-items-center"><span class="fw-bold text-dark">Total Amount Due:</span><span class="fs-5 fw-bold text-success">' + totalContractFormatted + '</span></div>')) +
                  '</div>' +
                  '<div class="p-2 border rounded bg-white small text-muted">' +
                    '<i class="fas fa-info-circle text-primary me-1"></i> Status is set to <span class="badge bg-warning text-dark">Pending Payment</span>. Client will settle payment on site at the administration counter.' +
                  '</div>' +
                '</div>',
          confirmButtonColor: '#7b1113',
          confirmButtonText: '<i class="fas fa-check me-2"></i>Done',
          allowOutsideClick: true,
          allowEscapeKey: true
        }).then(function() {
          window.location.reload();
        });
      } else {
        alert('Booking #' + bookingId + ' created successfully! Due today: ' + displayAmountFormatted);
        window.location.reload();
      }
    })
    .catch(function(err) {
      console.error('Admin booking submit error:', err);
      if (alertBox) {
        alertBox.textContent = err.message || 'Booking submission failed.';
        alertBox.style.display = 'block';
      }
      cleanupAllBackdrops();
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          icon: 'error',
          title: 'Booking Submission Failed',
          text: err.message || 'An error occurred while saving the booking.',
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
        alert('Booking failed: ' + (err.message || 'Unknown error'));
      }
    })
    .finally(function() {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHtml;
      }
      window.__adminBookingSubmitting = false;
    });

    return false;
  }
  window.submitBooking = submitBooking;

  // Smooth scrolling function for navigation
  function scrollToSection(sectionId) {
    const element = document.getElementById(sectionId);
    if (element) {
      element.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    }
    return false;
  }

  // Add CSS animations
  var style = document.createElement('style');
  style.textContent = `
    @keyframes slideIn {
      from {
        transform: translateX(100%);
        opacity: 0;
      }
      to {
        transform: translateX(0);
        opacity: 1;
      }
    }

    @keyframes slideOut {
      from {
        transform: translateX(0);
        opacity: 1;
      }
      to {
        transform: translateX(100%);
        opacity: 0;
      }
    }
  `;
  document.head.appendChild(style);

  function acceptBooking(bookingId) {
      if (confirm('Are you sure you want to accept this booking?')) {
          fetch('dashboard.php', {
              method: 'POST',
              headers: {
                  'Content-Type': 'application/x-www-form-urlencoded',
              },
              body: 'action=accept_booking&booking_id=' + bookingId
          })
          .then(response => response.json())
          .then(data => {
              if (data.success) {
                  alert('Booking accepted successfully!');
                  location.reload();
              } else {
                  alert('Error accepting booking: ' + data.message);
              }
          })
          .catch(error => {
              console.error('Error:', error);
              alert('Error accepting booking');
          });
      }
  }

  function rejectBooking(bookingId) {
      if (confirm('Are you sure you want to reject this booking?')) {
          fetch('dashboard.php', {
              method: 'POST',
              headers: {
                  'Content-Type': 'application/x-www-form-urlencoded',
              },
              body: 'action=reject_booking&booking_id=' + bookingId
          })
          .then(response => response.json())
          .then(data => {
              if (data.success) {
                  alert('Booking rejected successfully!');
                  location.reload();
              } else {
                  alert('Error rejecting booking: ' + data.message);
              }
          })
          .catch(error => {
              console.error('Error:', error);
              alert('Error rejecting booking');
          });
      }
  }

  // Show booking success modal
  function showBookingSuccessModal() {
    var modalHtml = `
      <div class="modal fade" id="bookingSuccessModal" tabindex="-1" aria-labelledby="bookingSuccessModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #28a745, #20c997); color: white; border-radius: 0.5rem 0.5rem 0 0;">
              <h5 class="modal-title" id="bookingSuccessModalLabel">
                <span style="font-size: 1.5rem;">🎉</span> Thank You for Booking!
              </h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center" style="padding: 2rem;">
              <div class="mb-4">
                <div style="font-size: 4rem; color: #28a745;">📅</div>
                <h4 class="mt-3 mb-2">Booking Successful!</h4>
                <p class="text-muted">Your reservation has been successfully submitted.</p>
              </div>
              <div class="alert alert-info d-flex align-items-center" role="alert">
                <span style="font-size: 1.5rem; margin-right: 1rem;">📱</span>
                <div class="text-start">
                  <strong>SMS Confirmation Sent!</strong><br>
                  <small>An SMS confirmation will be sent to your phone number shortly.</small>
                </div>
              </div>
              <div class="alert alert-success d-flex align-items-center" role="alert">
                <span style="font-size: 1.5rem; margin-right: 1rem;">📧</span>
                <div class="text-start">
                  <strong>Email Confirmation Sent!</strong><br>
                  <small>You will receive an email with your booking details.</small>
                </div>
              </div>
              <div class="alert alert-warning d-flex align-items-center" role="alert">
                <span style="font-size: 1.5rem; margin-right: 1rem;">⏰</span>
                <div class="text-start">
                  <strong>Next Steps:</strong><br>
                  <small>Our staff will contact you within 24 hours to confirm your booking.</small>
                </div>
              </div>
            </div>
            <div class="modal-footer border-0">
              <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>
    `;

    // Remove any existing success modal
    var existingModal = document.getElementById('bookingSuccessModal');
    if (existingModal) {
      existingModal.remove();
    }

    // Add the modal to the page
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    // Show the modal
    var modal = new bootstrap.Modal(document.getElementById('bookingSuccessModal'));
    modal.show();

    // Remove modal from DOM when hidden
    document.getElementById('bookingSuccessModal').addEventListener('hidden.bs.modal', function() {
      this.remove();
    });
  }

  // Grave location search function
  function searchGraveLocation() {
    var searchInput = document.getElementById('graveLocation');
    var resultsDiv = document.getElementById('graveSearchResults');
    var searchTerm = searchInput.value.trim();

    if (searchTerm.length < 1) {
      resultsDiv.innerHTML = '';
      return;
    }

    // Show loading
    resultsDiv.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div> Searching...</div>';

    var formData = new FormData();
    formData.append('action', 'search_grave');
    formData.append('search_term', searchTerm);

    fetch('dashboard.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success && data.results.length > 0) {
        var html = '<div class="list-group">';
        data.results.forEach(function(person) {
          html += '<a href="#" class="list-group-item list-group-item-action" onclick="selectGraveLocation(\'' + person.location + '\', \'' + person.name + '\')">';
          html += '<div class="d-flex justify-content-between align-items-center">';
          html += '<div>';
          html += '<h6 class="mb-1">' + person.name + '</h6>';
          html += '<small class="text-muted">' + person.location + '</small>';
          html += '</div>';
          html += '<span class="badge bg-primary rounded-pill">Select</span>';
          html += '</div>';
          html += '</a>';
        });
        html += '</div>';
        resultsDiv.innerHTML = html;
      } else {
        resultsDiv.innerHTML = '<div class="alert alert-info">No deceased persons found for this location.</div>';
      }
    })
    .catch(error => {
      console.error('Error:', error);
      resultsDiv.innerHTML = '<div class="alert alert-danger">Error searching. Please try again.</div>';
    });
  }

  // Select grave location function
  function selectGraveLocation(location, name) {
    document.getElementById('graveLocation').value = location;
    document.getElementById('graveSearchResults').innerHTML = '';

    // Show selected person info
    var infoDiv = document.getElementById('graveSearchResults');
    infoDiv.innerHTML = '<div class="alert alert-success"><strong>Selected:</strong> ' + name + ' - ' + location + '</div>';

    // Auto-hide after 3 seconds
    setTimeout(function() {
      infoDiv.innerHTML = '';
    }, 3000);
  }

  // Add real-time search on input
  document.addEventListener('DOMContentLoaded', function() {
    var graveInput = document.getElementById('graveLocation');
    if (graveInput) {
      var searchTimeout;
      graveInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
          if (graveInput.value.trim().length >= 2) {
            searchGraveLocation();
          } else {
            document.getElementById('graveSearchResults').innerHTML = '';
          }
        }, 500);
      });
    }
  });

// Send confirmation email
function sendConfirmationEmail(bookingId) {
  // Get booking details for email
  fetch('dashboard.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: 'action=send_confirmation_email&booking_id=' + bookingId
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      console.log('Confirmation email sent successfully');
    } else {
      console.log('Error sending email:', data.message);
    }
  })
  .catch(error => {
    console.error('Error sending confirmation email:', error);
  });
}

  // Payment handling functions
  function updatePaymentDetails() {
    var paymentType = document.getElementById('paymentType').value;
    var referenceDiv = document.getElementById('referenceNumberDiv');
    var bankDiv = document.getElementById('bankNameDiv');

    // Show/hide relevant fields based on payment type
    if (paymentType === 'gcash' || paymentType === 'bank_transfer') {
      referenceDiv.style.display = 'block';
      bankDiv.style.display = paymentType === 'bank_transfer' ? 'block' : 'none';
    } else {
      referenceDiv.style.display = 'none';
      bankDiv.style.display = 'none';
    }

    calculateBalance();
  }

  function updateBookingSummary() {
    var section = document.getElementById('section').value;
    var plot = document.getElementById('plot').value;
    var summaryDiv = document.getElementById('bookingSummary');

    if (section && plot) {
      var plotOption = document.querySelector('#plot option[value="' + plot + '"]');
      if (plotOption) {
        var price = plotOption.getAttribute('data-price') || 0;
        var type = plotOption.getAttribute('data-type') || 'Standard';

        summaryDiv.innerHTML = `
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div><strong>Section:</strong> ${section.toUpperCase()}</div>
              <div><strong>Plot:</strong> ${plot}</div>
              <div><strong>Type:</strong> ${type}</div>
              <div><strong>Price:</strong> ₱${parseFloat(price).toLocaleString()}</div>
            </div>
          </div>
        `;
      }
    } else {
      summaryDiv.innerHTML = '<small class="text-muted">Select a section and plot to see details</small>';
    }
  }

  function calculateBalance() {
    // This function is no longer needed since payment is removed
    updateBookingSummary();
  }

  function generateReceipt() {
    var bookingData = {
      section: document.getElementById('section').value,
      plot: document.getElementById('plot').value,
      plotPrice: document.getElementById('plotPrice')?.value || 0,
      preferredDate: document.getElementById('preferredDate').value,
      contactNumber: document.getElementById('contactNumber').value,
      specialRequests: document.getElementById('specialRequests').value,
      bookingDate: new Date().toLocaleDateString(),
      bookingTime: new Date().toLocaleTimeString()
    };

    // Generate receipt HTML
    var receiptHTML = `
      <div style="max-width: 400px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; font-family: Arial, sans-serif;">
        <div style="text-align: center; margin-bottom: 20px;">
          <h2 style="margin: 0; color: #7b1113;">GARDEN OF REMEMBRANCE</h2>
          <p style="margin: 5px 0; color: #666;">Booking Receipt</p>
        </div>
        <div style="border-top: 2px dashed #7b1113; padding-top: 15px;">
          <div><strong>Receipt #:</strong> ${generateReceiptNumber()}</div>
          <div><strong>Date:</strong> ${bookingData.bookingDate}</div>
          <div><strong>Time:</strong> ${bookingData.bookingTime}</div>
        </div>
        <div style="margin: 15px 0;">
          <div><strong>Section:</strong> ${bookingData.section}</div>
          <div><strong>Plot:</strong> ${bookingData.plot}</div>
          <div><strong>Plot Price:</strong> ₱${parseFloat(bookingData.plotPrice).toFixed(2)}</div>
          <div><strong>Preferred Date:</strong> ${bookingData.preferredDate}</div>
          <div><strong>Contact:</strong> ${bookingData.contactNumber}</div>
        </div>
        ${bookingData.specialRequests ? `
        <div style="margin: 15px 0;">
          <strong>Special Requests:</strong>
          <p style="margin: 5px 0; font-style: italic;">${bookingData.specialRequests}</p>
        </div>
        ` : ''}
        <div style="text-align: center; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #7b1113;">
          <p style="margin: 0; color: #666; font-size: 12px;">Thank you for your booking!</p>
          <p style="margin: 5px 0 0 0; color: #666; font-size: 12px;">This receipt is automatically generated</p>
        </div>
      </div>
    `;

    // Open receipt in new window for printing
    var receiptWindow = window.open('', '_blank');
    receiptWindow.document.write(receiptHTML);
    receiptWindow.document.close();
    receiptWindow.print();
  }

  function generateReceiptNumber() {
    var date = new Date();
    var year = date.getFullYear();
    var month = String(date.getMonth() + 1).padStart(2, '0');
    var day = String(date.getDate()).padStart(2, '0');
    var random = Math.floor(Math.random() * 10000).toString().padStart(4, '0');
    return `GR${year}${month}${day}${random}`;
  }

  // Update plots based on selected section
  function updatePlots() {
    var section = document.getElementById('section').value;
    var plotSelect = document.getElementById('plot');
    var plotGrid = document.getElementById('plotGrid');

    // Clear current plots and grid
    plotSelect.innerHTML = '<option value="">Select Plot</option>';
    plotGrid.style.display = 'none';

    if (section) {
      // Show loading state
      plotSelect.innerHTML = '<option value="">Loading plots...</option>';
      plotGrid.style.display = 'block';
      plotGrid.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div> Loading plot grid...</div>';

      // Define grid configuration and pricing for each section
      var gridConfig = {
        'A': { rows: 8, cols: 10, total: 80, basePrice: 5000, premiumPrice: 6000 },
        'B': { rows: 6, cols: 8, total: 48, basePrice: 3500, premiumPrice: 4200 },
        'C': { rows: 5, cols: 6, total: 30, basePrice: 3500, premiumPrice: 4200 },
        'D': { rows: 4, cols: 6, total: 24, basePrice: 5000, premiumPrice: 6000 },
        'E': { rows: 4, cols: 9, total: 36, basePrice: 5000, premiumPrice: 6000 },
        'F': { rows: 3, cols: 6, total: 18, basePrice: 3500, premiumPrice: 4200 }
      };

      var config = gridConfig[section] || { rows: 4, cols: 6, total: 24, basePrice: 3500, premiumPrice: 4200 };

      // Fetch occupied plots from database
      fetch('dashboard.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_plots&section=' + encodeURIComponent(section)
      })
      .then(response => response.json())
      .then(data => {
        // Clear and reset dropdown
        plotSelect.innerHTML = '<option value="">Select Plot</option>';

        var occupied_plots = [];
        if (data.plots && data.plots.length > 0) {
          data.plots.forEach(function(plot) {
            occupied_plots.push(plot.plot_x + '-' + plot.plot_y);
          });
        }

        // Create plot grid visualization
        createPlotGrid(section, data);

        // Always populate dropdown with ALL plots with pricing information
        var availableCount = 0;
        var occupiedCount = 0;

        // Define premium plots for this section
        var premiumPlots = getPremiumPlots(section);

        for (var row = 1; row <= config.rows; row++) {
          for (var col = 1; col <= config.cols; col++) {
            var plotKey = col + '-' + row;
            var isOccupied = occupied_plots.includes(plotKey);
            var isPremium = premiumPlots.includes(plotKey);
            var price = isPremium ? config.premiumPrice : config.basePrice;

            var option = document.createElement('option');
            option.value = plotKey;
            option.setAttribute('data-price', price);
            option.setAttribute('data-type', isPremium ? 'Premium' : 'Standard');

            if (isOccupied) {
              option.textContent = 'Plot ' + plotKey + ' (Row ' + row + ', Column ' + col + ') - OCCUPIED';
              option.disabled = true;
              option.style.color = '#999';
              occupiedCount++;
            } else {
              option.textContent = 'Plot ' + plotKey + ' (Row ' + row + ', Column ' + col + ') - ' + (isPremium ? 'Premium' : 'Standard') + ' - ₱' + price.toLocaleString();
              availableCount++;
            }

            plotSelect.appendChild(option);
          }
        }

        // Add pricing summary at the end
        var pricingOption = document.createElement('option');
        pricingOption.value = '';
        pricingOption.disabled = true;
        pricingOption.textContent = '--- Pricing: ₱' + config.basePrice.toLocaleString() + ' (Standard), ₱' + config.premiumPrice.toLocaleString() + ' (Premium) ---';
        pricingOption.style.fontWeight = 'bold';
        pricingOption.style.color = '#7b1113';
        plotSelect.appendChild(pricingOption);

        // Add summary option
        var summaryOption = document.createElement('option');
        summaryOption.value = '';
        summaryOption.disabled = true;
        summaryOption.textContent = '--- ' + availableCount + ' available, ' + occupiedCount + ' occupied ---';
        summaryOption.style.fontWeight = 'bold';
        summaryOption.style.color = '#666';
        plotSelect.appendChild(summaryOption);

        console.log('Section ' + section + ': ' + config.total + ' total plots, ' + availableCount + ' available, ' + occupiedCount + ' occupied');
        console.log('Pricing: ₱' + config.basePrice + ' (Standard), ₱' + config.premiumPrice + ' (Premium)');

        // Update reservation summary when plot is selected
        plotSelect.addEventListener('change', updateBookingSummary);
      })
      .catch(error => {
        console.error('Error fetching plots:', error);

        // Fallback: populate with all plots without database check
        plotSelect.innerHTML = '<option value="">Select Plot</option>';

        var premiumPlots = getPremiumPlots(section);

        for (var row = 1; row <= config.rows; row++) {
          for (var col = 1; col <= config.cols; col++) {
            var plotKey = col + '-' + row;
            var isPremium = premiumPlots.includes(plotKey);
            var price = isPremium ? config.premiumPrice : config.basePrice;

            var option = document.createElement('option');
            option.value = plotKey;
            option.setAttribute('data-price', price);
            option.setAttribute('data-type', isPremium ? 'Premium' : 'Standard');
            option.textContent = 'Plot ' + plotKey + ' (Row ' + row + ', Column ' + col + ') - ' + (isPremium ? 'Premium' : 'Standard') + ' - ₱' + price.toLocaleString();
            plotSelect.appendChild(option);
          }
        }

        // Still create grid without occupied data
        createPlotGrid(section, {plots: []});
      });
    }
  }

  // Create plot grid visualization
  function createPlotGrid(section, data) {
    var plotGrid = document.getElementById('plotGrid');
    var occupied_plots = [];

    // Extract occupied plots from data
    if (data.plots && data.plots.length > 0) {
      data.plots.forEach(function(plot) {
        occupied_plots.push(plot.plot_x + '-' + plot.plot_y);
      });
    }

    // Define grid dimensions for each section (based on actual section maps)
    var gridConfig = {
      'A': { rows: 8, cols: 10 },    // Section A: 8 rows × 10 columns = 80 plots
      'B': { rows: 6, cols: 8 },     // Section B: 6 rows × 8 columns = 48 plots  
      'C': { rows: 5, cols: 6 },     // Section C: 5 rows × 6 columns = 30 plots
      'D': { rows: 4, cols: 6 },     // Section D: 4 rows × 6 columns = 24 plots
      'E': { rows: 4, cols: 9 },     // Section E: 4 rows × 9 columns = 36 plots
      'F': { rows: 3, cols: 6 }      // Section F: 3 rows × 6 columns = 18 plots
    };

    var config = gridConfig[section] || { rows: 4, cols: 6 };
    var premium_plots = getPremiumPlots(section);

    // Create grid HTML
    var gridHTML = '<div class="plot-grid-legend mb-2">';
    gridHTML += '<div class="legend-item"><div class="legend-box available"></div> Available</div>';
    gridHTML += '<div class="legend-item"><div class="legend-box occupied"></div> Occupied</div>';
    gridHTML += '<div class="legend-item"><div class="legend-box premium"></div> Premium</div>';
    gridHTML += '</div>';

    // Add section info
    gridHTML += '<div class="mb-2"><strong>Section ' + section + '</strong> - ' + (config.rows * config.cols) + ' total plots (' + config.rows + ' rows × ' + config.cols + ' columns)</div>';

    gridHTML += '<div class="plot-grid" style="grid-template-columns: repeat(' + config.cols + ', 35px); grid-template-rows: repeat(' + config.rows + ', 35px);">';

    // Generate all plots for the section
    for (var row = 1; row <= config.rows; row++) {
      for (var col = 1; col <= config.cols; col++) {
        var plotKey = col + '-' + row;
        var isOccupied = occupied_plots.includes(plotKey);
        var isPremium = premium_plots.includes(plotKey);

        var cellClass = 'plot-cell';
        if (isOccupied) {
          cellClass += ' occupied';
        } else {
          cellClass += ' available';
        }
        if (isPremium) {
          cellClass += ' premium';
        }

        var onclick = isOccupied ? '' : 'onclick="selectPlotFromGrid(\'' + plotKey + '\')"';
        var title = isOccupied ? 'Plot ' + plotKey + ' - Occupied' : 'Plot ' + plotKey + ' - Click to select';

        gridHTML += '<div class="' + cellClass + '" ' + onclick + ' title="' + title + '">' + col + '-' + row + '</div>';
      }
    }

    gridHTML += '</div>';
    gridHTML += '<small class="text-muted mt-2 d-block">Click on any available plot to select it</small>';

    plotGrid.innerHTML = gridHTML;
    plotGrid.style.display = 'block';
  }

  // Get premium plots for section (corner plots based on actual grid layouts)
  function getPremiumPlots(section) {
    var premium = {
      'A': ['1-1', '1-2', '10-1', '10-2'],           // Section A: 8×10 grid - corners at columns 1 and 10
      'B': ['1-1', '1-2', '8-1', '8-2'],              // Section B: 6×8 grid - corners at columns 1 and 8
      'C': ['1-1', '1-2', '6-1', '6-2'],              // Section C: 5×6 grid - corners at columns 1 and 6
      'D': ['1-1', '1-2', '6-1', '6-2'],              // Section D: 4×6 grid - corners at columns 1 and 6
      'E': ['1-1', '1-2', '9-1', '9-2'],              // Section E: 4×9 grid - corners at columns 1 and 9
      'F': ['1-1', '1-2', '6-1', '6-2']               // Section F: 3×6 grid - corners at columns 1 and 6
    };
    return premium[section] || [];
  }

  // Select plot from grid
  function selectPlotFromGrid(plotKey) {
    var section = document.getElementById('section').value;
    var plotSelect = document.getElementById('plot');

    // Update dropdown selection
    plotSelect.value = plotKey;

    // Update plot info
    updatePlotInfo();

    // Update grid selection visual
    updateGridSelection(plotKey);
  }

  // Update grid selection visual
  function updateGridSelection(selectedPlot) {
    var cells = document.querySelectorAll('.plot-cell');
    cells.forEach(function(cell) {
      cell.classList.remove('selected');
    });

    var selectedCell = document.querySelector('.plot-cell[title*="' + selectedPlot + '"]');
    if (selectedCell) {
      selectedCell.classList.add('selected');
    }
  }

  // Update plot information display
  function updatePlotInfo() {
    var section = document.getElementById('section').value;
    var plot = document.getElementById('plot').value;
    var plotInfoDiv = document.getElementById('plotInfo');

    if (!section || !plot) {
      plotInfoDiv.innerHTML = '<small class="text-muted">Select a section and plot to see availability and pricing</small>';
      return;
    }

    // Show loading state
    plotInfoDiv.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div> Checking availability...</div>';

    // Fetch plot information
    fetch('dashboard.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: 'action=get_plot_info&section=' + encodeURIComponent(section) + '&plot=' + encodeURIComponent(plot)
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        var availableClass = data.available ? 'text-success' : 'text-danger';
        var availableText = data.available ? 'Available' : (data.status || 'Occupied');
        var badgeClass = data.available ? 'bg-success' : 'bg-danger';

        // Add hidden input for plot price for payment calculation
        var plotPriceInput = document.getElementById('plotPrice');
        if (!plotPriceInput) {
          plotPriceInput = document.createElement('input');
          plotPriceInput.type = 'hidden';
          plotPriceInput.id = 'plotPrice';
          plotPriceInput.name = 'plotPrice';
          plotPriceInput.value = data.plot_price;
          document.getElementById('bookingForm').appendChild(plotPriceInput);
        } else {
          plotPriceInput.value = data.plot_price;
        }

        plotInfoDiv.innerHTML = `
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="fw-semibold">Section ${section}, Plot ${plot}</div>
              <div class="${availableClass}">${availableText}</div>
              <div class="text-muted small">Type: ${data.plot_type}</div>
            </div>
            <div class="text-end">
              <div class="badge ${badgeClass}">₱${parseFloat(data.plot_price).toLocaleString()}</div>
            </div>
          </div>
        `;

        // Update payment summary if payment method is selected
        calculateBalance();
      } else {
        plotInfoDiv.innerHTML = '<div class="text-danger">' + (data.message || 'Error loading plot information') + '</div>';
      }
    })
    .catch(error => {
      console.error('Error fetching plot info:', error);
      plotInfoDiv.innerHTML = '<div class="text-danger">' + (error && error.message ? error.message : 'Error loading plot information') + '</div>';
    });
  }

</script>

<div class="modal fade" id="viewDeceasedModal" tabindex="-1" aria-labelledby="viewDeceasedModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="viewDeceasedModalLabel">Plot Location</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <div class="mb-3">
          <i class="fas fa-map-marker-alt fa-3x text-danger"></i>
        </div>
        <h6 class="text-primary mb-2" id="view_full_name"></h6>
        <div class="badge bg-dark mb-3">
          Section <span id="view_section"></span> - Plot <span id="view_plot"></span>
        </div>
        <button type="button" class="btn btn-success btn-lg w-100" id="startNavigationBtn">
          <i class="fas fa-route me-2"></i>Navigate to Plot
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="navigationModal" tabindex="-1" aria-labelledby="navigationModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="navigationModalLabel">Navigation Ready</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center py-4">
        <div class="mb-3">
          <i class="fas fa-mobile-alt fa-4x text-success"></i>
        </div>
        <h6 class="mb-3">Ready to navigate to the plot</h6>
        <p class="text-muted mb-4">Click below to open real-time navigation on your mobile device</p>
        <button type="button" class="btn btn-success btn-lg" id="beginNavigationBtn">
          <i class="fas fa-location-arrow me-2"></i>Start Real-time Navigation
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="addDeceasedModal" tabindex="-1" aria-labelledby="addDeceasedModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-height: 92vh; margin: 1.5rem auto;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;">

      <div class="modal-header py-3 px-4 flex-shrink-0" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff; border-bottom: none;">
        <div class="d-flex align-items-center gap-3">
          <div style="background: rgba(255,255,255,0.15); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;"><i class="fas fa-user-plus text-white"></i></div>
          <div>
            <h5 class="modal-title fw-bold m-0 text-white" id="addDeceasedModalLabel" style="font-family: 'Inter', sans-serif; font-size: 1.2rem; letter-spacing: -0.2px;">
              Add Deceased Record / Plot Purchase
            </h5>
            <div style="font-size: 0.82rem; color: rgba(255,255,255,0.85);">Cemetery Management and Grave Mapping System — Record Registration</div>
          </div>
        </div>
        <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="addDeceasedForm" class="d-flex flex-column flex-grow-1 overflow-hidden" style="min-height: 0; max-height: 100%;">
        <div class="modal-body p-4" style="background: #f8fafc; overflow-y: auto; flex: 1 1 auto; max-height: calc(90vh - 145px);">

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; overflow: hidden; border: 1px solid #e2e8f0 !important;">
            <div class="p-3 d-flex flex-wrap align-items-center justify-content-between gap-2" style="background: #f1f5f9; border-bottom: 1px solid #e2e8f0;">
              <div class="d-flex align-items-center gap-2">
                <span class="badge px-3 py-2 fs-6 fw-bold" id="addDecSectionBadge" style="background: #7b1113; color: #fff;">Section A</span>
                <span class="text-muted small fw-semibold" id="addDecPlotDisplay">Plot Coordinates: Not selected yet</span>
              </div>
              <span class="badge bg-warning text-dark px-3 py-1" id="addDecStatusBadge">Plot Purchase</span>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #fed7aa !important; background: #fffaf0;">
            <div class="card-body p-3">
              <label class="form-label fw-bold text-dark small mb-2 d-flex align-items-center gap-2">
                <i class="fas fa-clipboard-check text-warning"></i> Registration Workflow Type <span class="text-danger">*</span>
              </label>
              <div class="row g-2">
                <div class="col-md-6">
                  <div class="form-check p-2 border rounded text-center h-100 d-flex align-items-center justify-content-center" id="wrap_type_purchase_only" style="cursor:pointer; background: #fef3c7; border-color: #f59e0b !important;">
                    <input class="form-check-input ms-0 me-2" type="radio" name="record_type" id="type_purchase_only" value="purchase_only" checked onchange="toggleRecordType()">
                    <label class="form-check-label fw-bold text-dark small" for="type_purchase_only" style="cursor:pointer;">
                      <i class="fas fa-receipt text-warning me-1"></i> Plot Purchase Only<br>
                      <span class="text-muted" style="font-size: 0.75rem; font-weight: normal;">Sold/Purchased with NO deceased assigned yet</span>
                    </label>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="form-check p-2 border rounded text-center h-100 d-flex align-items-center justify-content-center" id="wrap_type_with_deceased" style="cursor:pointer; background: #f8fafc; border-color: #cbd5e1 !important;">
                    <input class="form-check-input ms-0 me-2" type="radio" name="record_type" id="type_with_deceased" value="with_deceased" onchange="toggleRecordType()">
                    <label class="form-check-label fw-bold text-dark small" for="type_with_deceased" style="cursor:pointer;">
                      <i class="fas fa-cross text-danger me-1"></i> With Deceased Record<br>
                      <span class="text-muted" style="font-size: 0.75rem; font-weight: normal;">Plot purchased and assign deceased immediately</span>
                    </label>
                  </div>
                </div>
              </div>
              <div class="alert alert-info py-2 px-3 mt-2 mb-0 small d-flex align-items-center gap-2" id="recordTypeNotice" style="border-radius: 8px;">
                <i class="fas fa-info-circle"></i>
                <span id="recordTypeNoticeText"><strong>Plot Purchase Only:</strong> Deceased details are optional/not required. The plot will be registered under the buyer and marked as <strong>Purchased</strong>.</span>
              </div>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important;">
            <div class="card-body p-3">
              <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                  <span class="text-success">📍</span> Cemetery Plot Assignment
                </h6>
                <span class="text-muted small">Select an available plot directly from the digital map</span>
              </div>

              <!-- Hidden plot coordinates inputs for form submission -->
              <input type="hidden" id="add_dec_plot_x" name="plot_x" value="">
              <input type="hidden" id="add_dec_plot_y" name="plot_y" value="">

              <div class="row g-3 align-items-center mt-0">
                <div class="col-md-4">
                  <label for="plot_section" class="form-label small fw-semibold mb-1">Section <span class="text-danger">*</span></label>
                  <select class="form-select form-select-sm fw-bold" id="plot_section" name="section" required onchange="updateDashPlotSummary()">
                    <option value="A" selected>Section A</option>
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

                <div class="col-md-8">
                  <label class="form-label small fw-semibold mb-1 d-block">Plot Map Selection <span class="text-danger">*</span></label>

                  <!-- State 1: No plot chosen yet -->
                  <div id="addDecNoPlotChosen" class="d-flex align-items-center justify-content-between p-2.5 rounded-3 border bg-light flex-wrap gap-2">
                    <div class="small text-muted d-flex align-items-center gap-2">
                      <i class="fas fa-map-pin text-danger fs-6"></i>
                      <span>No plot selected yet. Click to choose on map.</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary fw-semibold px-3 py-1.5 rounded-pill shadow-xs" id="btnAdminOpenAddDecMap" onclick="openAddDeceasedMapPicker()">
                      <i class="fas fa-map-marked-alt me-1"></i> Choose Plot Map
                    </button>
                  </div>

                  <!-- State 2: Plot chosen from map -->
                  <div id="addDecSelectedPlotCard" class="p-2.5 rounded-3 border shadow-xs" style="background: #f0fdf4; border-color: #86efac !important; display: none;">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                      <div>
                        <div class="d-flex align-items-center gap-2 mb-0.5">
                          <span class="badge bg-success px-2 py-0.5 small fw-bold">
                            <i class="fas fa-check-circle me-1"></i> Selected on Map
                          </span>
                          <span class="fw-bold text-dark small" id="addDecSelectedPlotTitle">Section A • Column 1, Row 1</span>
                        </div>
                        <div class="text-muted" style="font-size: 0.78rem;" id="addDecSelectedPlotSub">Available Cemetery Plot</div>
                      </div>
                      <div class="d-flex gap-1.5">
                        <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2.5 rounded-pill fw-semibold" onclick="openAddDeceasedMapPicker()" style="font-size: 0.8rem;">
                          <i class="fas fa-map-marked-alt me-1"></i> Change Plot Map
                        </button>
                      </div>
                    </div>
                  </div>

                </div>
              </div>
            </div>
          </div>

          <div id="deceasedPersonalInfoCard" class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important; display: none;">
            <div class="card-body p-3">
              <h6 class="fw-bold mb-3 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                <span class="text-danger">👤</span> Personal Information
              </h6>
              <div class="row g-3">
                <div class="col-md-4">
                  <label for="first_name" class="form-label small fw-semibold mb-1">First Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="first_name" name="first_name" placeholder="e.g., JUAN" oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="col-md-4">
                  <label for="middle_name" class="form-label small fw-semibold mb-1">Middle Name</label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="middle_name" name="middle_name" placeholder="e.g., SANTOS" oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="col-md-4">
                  <label for="last_name" class="form-label small fw-semibold mb-1">Last Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="last_name" name="last_name" placeholder="e.g., DELA CRUZ" oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="col-md-4">
                  <label for="suffix" class="form-label small fw-semibold mb-1">Suffix</label>
                  <select class="form-select form-select-sm" id="suffix" name="suffix">
                    <option value="">No Suffix</option>
                    <option value="JR.">JR.</option>
                    <option value="SR.">SR.</option>
                    <option value="II">II</option>
                    <option value="III">III</option>
                    <option value="IV">IV</option>
                    <option value="V">V</option>
                    <option value="OTHER">OTHER</option>
                  </select>
                  <input type="text" class="form-control form-control-sm mt-2" id="suffix_other" name="suffix_other" placeholder="Enter custom suffix" style="display:none;" oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="col-md-4">
                  <label for="bday" class="form-label small fw-semibold mb-1">Birthday <span class="text-danger">*</span></label>
                  <input type="date" class="form-control form-control-sm" id="bday" name="bday">
                </div>
                <div class="col-md-4">
                  <label for="born_in" class="form-label small fw-semibold mb-1">Born In (Birthplace) <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="born_in" name="born_in" placeholder="e.g., ALAMINOS CITY" oninput="this.value = this.value.toUpperCase()">
                </div>
              </div>
            </div>
          </div>

          <div id="deceasedDemiseCard" class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important; display: none;">
            <div class="card-body p-3">
              <h6 class="fw-bold mb-3 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                <span class="text-primary">🕊️</span> Demise & Funeral Information
              </h6>
              <div class="row g-3">
                <div class="col-md-6">
                  <label for="date_died" class="form-label small fw-semibold mb-1">Date Died <span class="text-danger">*</span></label>
                  <input type="date" class="form-control form-control-sm" id="date_died" name="date_died">
                </div>
                <div class="col-md-6">
                  <label for="interment_date" class="form-label small fw-semibold mb-1">
                    Date of Interment / Burial <span class="text-muted small" style="font-size:0.75rem;">(Transfer eligibility)</span>
                  </label>
                  <input type="date" class="form-control form-control-sm" id="interment_date" name="interment_date">
                </div>
                <div class="col-md-6">
                  <label for="funeral_service" class="form-label small fw-semibold mb-1">Funeral Service <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="funeral_service" name="funeral_service" placeholder="Enter funeral service name" oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="col-md-6">
                  <label for="contact_no" class="form-label small fw-semibold mb-1">Family Contact Number <span class="text-danger">*</span></label>
                  <input type="tel" class="form-control form-control-sm" id="contact_no" name="contact_no" inputmode="numeric" maxlength="11" minlength="11" pattern="09[0-9]{9}" placeholder="09XXXXXXXXX" oninput="var val=this.value.replace(/[^0-9]/g,''); if(val.indexOf('639')===0) val='09'+val.substring(3); else if(val.indexOf('9')===0) val='09'+val.substring(1); else if(val.length>=2 && val.charAt(0)==='0' && val.charAt(1)!=='9') val='0'; else if(val.length===1 && val!=='0') val='09'; this.value=val.slice(0,11);">
                </div>
              </div>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important;">
            <div class="card-body p-3">
              <div class="d-flex align-items-center justify-content-between mb-3">
                <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                  <span class="text-success">💳</span> Client & Payment Details
                </h6>
                <span class="badge px-3 py-1" id="addDecPaymentBadge" style="background:#198754; color:#fff; font-size: 0.8rem;">Paid in Full</span>
              </div>

              <div class="row g-3">

                <div class="col-md-5">
                  <label for="add_booker_name" class="form-label small fw-semibold mb-1">
                    Client / Buyer Full Name <span class="text-danger">*</span>
                  </label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="add_booker_name" name="booker_name" placeholder="e.g., JUAN DELA CRUZ" required oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="col-md-4">
                  <label for="add_buyer_email" class="form-label small fw-semibold mb-1">
                    Client Email Address <span class="text-danger">*</span>
                  </label>
                  <input type="email" class="form-control form-control-sm" id="add_buyer_email" name="buyer_email" placeholder="e.g., client@gmail.com" required>
                  <div class="form-text small text-muted" style="font-size:0.75rem;"><i class="fas fa-envelope-open-text text-primary me-1"></i>Official purchase receipt will be emailed here.</div>
                </div>
                <div class="col-md-3">
                  <label for="add_buyer_contact" class="form-label small fw-semibold mb-1">
                    Buyer Contact Number
                  </label>
                  <input type="tel" class="form-control form-control-sm" id="add_buyer_contact" name="buyer_contact" inputmode="numeric" maxlength="11" minlength="11" pattern="09[0-9]{9}" placeholder="09XXXXXXXXX" oninput="var val=this.value.replace(/[^0-9]/g,''); if(val.indexOf('639')===0) val='09'+val.substring(3); else if(val.indexOf('9')===0) val='09'+val.substring(1); else if(val.length>=2 && val.charAt(0)==='0' && val.charAt(1)!=='9') val='0'; else if(val.length===1 && val!=='0') val='09'; this.value=val.slice(0,11);">
                </div>

                <div class="col-md-6">
                  <label class="form-label small fw-semibold mb-1">Payment Plan <span class="text-danger">*</span></label>
                  <div class="d-flex gap-2">
                    <div class="form-check flex-fill p-2 border rounded text-center" style="cursor:pointer; background:#e8f5e9; border-color:#198754 !important;" id="wrap_plan_cash">
                      <input class="form-check-input ms-0 me-2" type="radio" name="payment_plan" id="plan_cash" value="Cash" checked onchange="toggleAddDeceasedPaymentPlan()">
                      <label class="form-check-label fw-bold text-dark small" for="plan_cash" style="cursor:pointer;">
                        <i class="fas fa-money-bill-wave text-success me-1"></i> Cash (Full)
                      </label>
                    </div>
                    <div class="form-check flex-fill p-2 border rounded text-center" style="cursor:pointer; background:#f8fafc;" id="wrap_plan_installment">
                      <input class="form-check-input ms-0 me-2" type="radio" name="payment_plan" id="plan_installment" value="Installment" onchange="toggleAddDeceasedPaymentPlan()">
                      <label class="form-check-label fw-bold text-dark small" for="plan_installment" style="cursor:pointer;">
                        <i class="fas fa-calendar-alt text-primary me-1"></i> Installment
                      </label>
                    </div>
                  </div>
                </div>

                <div class="col-md-6" id="addDecTermContainer" style="display:none;">
                  <label for="add_payment_term" class="form-label small fw-semibold mb-1">Installment Term (Months) <span class="text-danger">*</span></label>
                  <select class="form-select form-select-sm fw-bold" id="add_payment_term" name="payment_term" onchange="recalculateAddDeceasedPayment()">
                    <option value="12" selected>12 Months (1 Year)</option>
                    <option value="24">24 Months (2 Years)</option>
                    <option value="36">36 Months (3 Years)</option>
                    <option value="48">48 Months (4 Years)</option>
                    <option value="60">60 Months (5 Years)</option>
                  </select>
                  <div class="small text-muted mt-1" id="addDecMonthlyHint">Est. Amortization: <strong class="text-primary" id="addDecMonthlyDisplay">₱7,226.00 / mo</strong></div>
                </div>

                <div class="col-md-6">
                  <label class="form-label small fw-semibold mb-1">Payment Status <span class="text-danger">*</span></label>
                  <select class="form-select form-select-sm fw-bold" id="add_payment_status" name="payment_status" onchange="handlePaymentStatusChange()">
                    <option value="PAID" selected>🟢 Paid Now / Paid in Full</option>
                    <option value="HAS DUES">🟡 Has Dues / Ongoing Installment</option>
                  </select>
                </div>

                <div class="col-md-6">
                  <label for="add_total_amount" class="form-label small fw-semibold mb-1">Total Plot Price (₱) <span class="text-danger">*</span></label>
                  <input type="number" step="0.01" class="form-control form-control-sm fw-bold text-dark" id="add_total_amount" name="total_amount" value="85000.00" required oninput="recalculateAddDeceasedPayment()">
                </div>

                <div class="col-md-6">
                  <label for="add_amount_paid" class="form-label small fw-semibold mb-1">Amount Paid (₱) <span class="text-danger">*</span></label>
                  <input type="number" step="0.01" class="form-control form-control-sm fw-bold text-success" id="add_amount_paid" name="amount_paid" value="85000.00" required oninput="recalculateAddDeceasedPayment()">
                </div>

                <div class="col-12">
                  <div class="p-2 rounded d-flex justify-content-between align-items-center" style="background:#f1f5f9; border:1px solid #cbd5e1;">
                    <span class="small fw-semibold text-muted">Remaining Balance / Dues:</span>
                    <span class="fw-bold" id="addDecBalanceDisplay" style="color:#198754; font-size:1rem;">₱0.00 (Fully Paid)</span>
                  </div>
                  <input type="hidden" id="add_monthly_due" name="monthly_due" value="0.00">
                </div>
              </div>
            </div>
          </div>

        </div>

        <div class="modal-footer d-flex justify-content-between px-4 py-3 flex-shrink-0" style="background:#fff; border-top: 1px solid #e2e8f0; z-index: 10;">
          <button type="button" class="btn btn-outline-secondary px-4 py-2 fw-semibold" data-bs-dismiss="modal" style="border-radius: 2rem;">
            <i class="fas fa-times me-1"></i> Cancel
          </button>
          <button type="button" id="addDecSubmitBtn" class="btn btn-primary px-5 py-2 fw-bold shadow" onclick="submitAddDeceased()" style="border-radius: 2rem; background: #d97706; border-color: #d97706; min-width: 220px;">
            <i class="fas fa-check-circle me-2"></i> Save Plot Purchase
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="newDeceasedModal" style="display:none;"></div>

<script>
// Logic for Add Deceased / Plot Purchase Modal on Admin Dashboard
function updateDashPlotSummary() {
  var form = document.getElementById('addDeceasedForm');
  var secEl = form ? form.querySelector('[name="section"]') : document.getElementById('plot_section');
  var pxEl = form ? form.querySelector('[name="plot_x"]') : document.getElementById('add_dec_plot_x');
  var pyEl = form ? form.querySelector('[name="plot_y"]') : document.getElementById('add_dec_plot_y');
  var secVal = secEl ? secEl.value : 'A';
  var pxVal = pxEl ? pxEl.value : '';
  var pyVal = pyEl ? pyEl.value : '';

  var secBadge = document.getElementById('addDecSectionBadge');
  if (secBadge) secBadge.textContent = 'Section ' + secVal.toUpperCase();
  var plotDisp = document.getElementById('addDecPlotDisplay');
  if (plotDisp) {
    if (pxVal && pyVal) {
      plotDisp.textContent = 'Plot Coordinates: Column ' + pxVal + ', Row ' + pyVal;
    } else {
      plotDisp.textContent = 'Plot Coordinates: Not selected yet';
    }
  }

  var titleEl = document.getElementById('addDecSelectedPlotTitle');
  if (pxVal && pyVal && titleEl) {
    titleEl.textContent = 'Section ' + secVal.toUpperCase() + ' • Plot ' + pxVal + ',' + pyVal + ' (Column ' + pxVal + ', Row ' + pyVal + ')';
  }
}

window.openAddDeceasedMapPicker = function() {
  window.__openedFromAddDeceasedModal = true;
  window.__openedFromBookingModal = false;
  window.__openedFromTransferModal = false;

  var form = document.getElementById('addDeceasedForm');
  var secSelect = form ? form.querySelector('[name="section"]') : document.getElementById('plot_section');
  var sec = (secSelect && secSelect.value) ? secSelect.value : 'A';

  if (typeof window.setPopupModalMode === 'function') window.setPopupModalMode('booking');

  var adminSectionSel = document.getElementById('adminPopupSectionSelect');
  if (adminSectionSel) adminSectionSel.value = sec;

  var iframe = document.getElementById('popupSectionIframe');
  var popupModalEl = document.getElementById('popupSectionModal');
  var addModalEl = document.getElementById('addDeceasedModal');

  if (iframe) {
    iframe.src = 'sections/popup.php?selection_mode=booking&section=' + encodeURIComponent(sec) + '&source=admin&is_admin=1&t=' + Date.now();
  }

  // Cleanly hide addDeceasedModal first so backdrops don't collide
  if (addModalEl && typeof bootstrap !== 'undefined') {
    var aModal = bootstrap.Modal.getInstance(addModalEl);
    if (aModal) aModal.hide();
  }

  setTimeout(function() {
    if (popupModalEl && typeof bootstrap !== 'undefined') {
      var pModal = bootstrap.Modal.getOrCreateInstance(popupModalEl);
      pModal.show();
    }
  }, 250);
};

window.applySelectedPlotToAddDeceased = function(data) {
  if (!data) return;
  var sec = (data.section || 'A').toUpperCase();
  var px = parseInt(data.plot_x || data.plotX || 1, 10);
  var py = parseInt(data.plot_y || data.plotY || 1, 10);
  var lawnType = data.lawnType || data.lawn_type || 'Regular';
  var status = (data.status || 'AVAILABLE').toUpperCase();

  // Validate status
  if (status !== 'AVAILABLE') {
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        icon: 'warning',
        title: 'Plot Not Available',
        text: 'Section ' + sec + ' Plot ' + px + ',' + py + ' is marked as ' + status + '. Only Available plots can be selected.',
        confirmButtonColor: '#7b1113'
      });
    } else {
      alert('Section ' + sec + ' Plot ' + px + ',' + py + ' is marked as ' + status + '. Only Available plots can be selected.');
    }
    return;
  }

  // Update hidden inputs in addDeceasedForm
  var form = document.getElementById('addDeceasedForm');
  if (form) {
    var secSelect = form.querySelector('[name="section"]');
    if (secSelect) secSelect.value = sec;
    var xIn = form.querySelector('[name="plot_x"]');
    if (xIn) xIn.value = px;
    var yIn = form.querySelector('[name="plot_y"]');
    if (yIn) yIn.value = py;
  }

  // Update visual cards & badges
  var secBadge = document.getElementById('addDecSectionBadge');
  if (secBadge) secBadge.textContent = 'Section ' + sec;
  var plotDisp = document.getElementById('addDecPlotDisplay');
  if (plotDisp) plotDisp.textContent = 'Plot Coordinates: Column ' + px + ', Row ' + py;

  var noPlotBox = document.getElementById('addDecNoPlotChosen');
  var cardSelected = document.getElementById('addDecSelectedPlotCard');
  var titleEl = document.getElementById('addDecSelectedPlotTitle');
  var subEl = document.getElementById('addDecSelectedPlotSub');

  if (titleEl) titleEl.textContent = 'Section ' + sec + ' • Plot ' + px + ',' + py + ' (Column ' + px + ', Row ' + py + ')';
  if (subEl) subEl.textContent = lawnType + ' Lawn • Available for Assignment';
  if (noPlotBox) noPlotBox.style.display = 'none';
  if (cardSelected) cardSelected.style.display = 'block';

  // Natural price sync
  var naturalPrice = (lawnType.indexOf('Premium') !== -1 || Number(data.price || 0) >= 89000) ? 89000 : 85000;
  var totalAmountInput = document.getElementById('add_total_amount');
  if (totalAmountInput && (!totalAmountInput.value || parseFloat(totalAmountInput.value) <= 0 || parseFloat(totalAmountInput.value) === 85000 || parseFloat(totalAmountInput.value) === 89000)) {
    totalAmountInput.value = naturalPrice.toFixed(2);
    if (typeof recalculateAddDeceasedPayment === 'function') {
      recalculateAddDeceasedPayment();
    }
  }

  // Hide popupSectionModal and re-open addDeceasedModal
  window.__openedFromAddDeceasedModal = false;
  var popupModalEl = document.getElementById('popupSectionModal');
  if (popupModalEl && typeof bootstrap !== 'undefined') {
    var pModal = bootstrap.Modal.getInstance(popupModalEl);
    if (pModal) pModal.hide();
  }

  var addModalEl = document.getElementById('addDeceasedModal');
  if (addModalEl && typeof bootstrap !== 'undefined') {
    setTimeout(function() {
      var aModal = bootstrap.Modal.getOrCreateInstance(addModalEl);
      aModal.show();
    }, 200);
  }
};

function toggleRecordType() {
  var isPurchaseOnly = document.getElementById('type_purchase_only') && document.getElementById('type_purchase_only').checked;
  var cardPersonal = document.getElementById('deceasedPersonalInfoCard');
  var cardDemise = document.getElementById('deceasedDemiseCard');
  var noticeText = document.getElementById('recordTypeNoticeText');
  var wrapPurchase = document.getElementById('wrap_type_purchase_only');
  var wrapDeceased = document.getElementById('wrap_type_with_deceased');
  var submitBtn = document.getElementById('addDecSubmitBtn');
  var statusBadge = document.getElementById('addDecStatusBadge');

  var deceasedReqInputs = ['first_name', 'last_name', 'bday', 'born_in', 'date_died', 'contact_no', 'funeral_service'];

  if (isPurchaseOnly) {
    if (cardPersonal) cardPersonal.style.display = 'none';
    if (cardDemise) cardDemise.style.display = 'none';
    if (wrapPurchase) {
      wrapPurchase.style.background = '#fef3c7';
      wrapPurchase.style.borderColor = '#f59e0b';
    }
    if (wrapDeceased) {
      wrapDeceased.style.background = '#f8fafc';
      wrapDeceased.style.borderColor = '#cbd5e1';
    }
    if (noticeText) {
      noticeText.innerHTML = '<strong>Plot Purchase Only:</strong> Deceased details are optional/not required. The plot will be registered under the buyer and marked as <strong>Purchased</strong>.';
    }
    if (statusBadge) {
      statusBadge.className = 'badge bg-warning text-dark px-3 py-1';
      statusBadge.textContent = 'Plot Purchase';
    }
    if (submitBtn) {
      submitBtn.innerHTML = '<i class="fas fa-check-circle me-2"></i> Save Plot Purchase';
      submitBtn.style.background = '#d97706';
      submitBtn.style.borderColor = '#d97706';
    }
    deceasedReqInputs.forEach(function(id) {
      var el = document.getElementById(id);
      if (el) el.removeAttribute('required');
    });
  } else {
    if (cardPersonal) cardPersonal.style.display = 'block';
    if (cardDemise) cardDemise.style.display = 'block';
    if (wrapDeceased) {
      wrapDeceased.style.background = '#fee2e2';
      wrapDeceased.style.borderColor = '#ef4444';
    }
    if (wrapPurchase) {
      wrapPurchase.style.background = '#f8fafc';
      wrapPurchase.style.borderColor = '#cbd5e1';
    }
    if (noticeText) {
      noticeText.innerHTML = '<strong>With Deceased Record:</strong> Please provide deceased person and burial details. The plot will be registered as <strong>Occupied</strong>.';
    }
    if (statusBadge) {
      statusBadge.className = 'badge bg-danger px-3 py-1';
      statusBadge.textContent = 'Burial Record';
    }
    if (submitBtn) {
      submitBtn.innerHTML = '<i class="fas fa-check-circle me-2"></i> Submit Deceased Record';
      submitBtn.style.background = '#7b1113';
      submitBtn.style.borderColor = '#7b1113';
    }
    deceasedReqInputs.forEach(function(id) {
      var el = document.getElementById(id);
      if (el) el.setAttribute('required', 'required');
    });
  }
}

function toggleAddDeceasedPaymentPlan() {
  var isInstallment = document.getElementById('plan_installment') && document.getElementById('plan_installment').checked;
  var termContainer = document.getElementById('addDecTermContainer');
  var wrapCash = document.getElementById('wrap_plan_cash');
  var wrapInst = document.getElementById('wrap_plan_installment');

  if (termContainer) {
    termContainer.style.display = isInstallment ? 'block' : 'none';
  }
  if (wrapCash && wrapInst) {
    if (isInstallment) {
      wrapInst.style.borderColor = '#0d6efd';
      wrapInst.style.background = '#e7f1ff';
      wrapCash.style.borderColor = '#dee2e6';
      wrapCash.style.background = '#f8fafc';
    } else {
      wrapCash.style.borderColor = '#198754';
      wrapCash.style.background = '#e8f5e9';
      wrapInst.style.borderColor = '#dee2e6';
      wrapInst.style.background = '#f8fafc';
    }
  }
  recalculateAddDeceasedPayment();
}

function handlePaymentStatusChange() {
  var status = document.getElementById('add_payment_status') ? document.getElementById('add_payment_status').value : 'PAID';
  var tot = parseFloat(document.getElementById('add_total_amount') ? document.getElementById('add_total_amount').value : 0) || 0;
  var paidEl = document.getElementById('add_amount_paid');
  if (status === 'PAID') {
    if (paidEl) paidEl.value = tot.toFixed(2);
  } else {
    if (paidEl && parseFloat(paidEl.value) >= tot && tot > 0) {
      paidEl.value = (tot * 0.2).toFixed(2);
    }
  }
  recalculateAddDeceasedPayment();
}

function recalculateAddDeceasedPayment() {
  var isInstallment = document.getElementById('plan_installment') && document.getElementById('plan_installment').checked;
  var term = parseInt(document.getElementById('add_payment_term') ? document.getElementById('add_payment_term').value : 12, 10) || 12;
  var tot = parseFloat(document.getElementById('add_total_amount') ? document.getElementById('add_total_amount').value : 85000) || 0;
  var paid = parseFloat(document.getElementById('add_amount_paid') ? document.getElementById('add_amount_paid').value : 0) || 0;
  var balance = Math.max(0, tot - paid);

  var interestRate = 0.02;
  var monthlyDue = 0;
  if (isInstallment && term > 0) {
    monthlyDue = (tot / term) + (tot * (interestRate / 12));
  }
  var mDueInput = document.getElementById('add_monthly_due');
  if (mDueInput) mDueInput.value = monthlyDue.toFixed(2);

  var mDisp = document.getElementById('addDecMonthlyDisplay');
  if (mDisp) mDisp.textContent = '₱' + monthlyDue.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' / mo';

  var balDisp = document.getElementById('addDecBalanceDisplay');
  var badge = document.getElementById('addDecPaymentBadge');
  var statusSel = document.getElementById('add_payment_status');

  if (balance <= 0 && tot > 0) {
    if (balDisp) {
      balDisp.style.color = '#198754';
      balDisp.textContent = '₱0.00 (Fully Paid)';
    }
    if (badge) {
      badge.style.background = '#198754';
      badge.textContent = 'Paid in Full';
    }
    if (statusSel && statusSel.value !== 'PAID' && paid >= tot) {
      statusSel.value = 'PAID';
    }
  } else {
    if (balDisp) {
      balDisp.style.color = '#d97706';
      balDisp.textContent = '₱' + balance.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (Remaining Due)';
    }
    if (badge) {
      badge.style.background = '#d97706';
      badge.textContent = 'Has Dues';
    }
    if (statusSel && statusSel.value === 'PAID') {
      statusSel.value = 'HAS DUES';
    }
  }
}

function submitAddDeceased() {
  const form = document.getElementById('addDeceasedForm');
  if (!form) return;

  const px = form.querySelector('[name="plot_x"]') ? form.querySelector('[name="plot_x"]').value.trim() : '';
  const py = form.querySelector('[name="plot_y"]') ? form.querySelector('[name="plot_y"]').value.trim() : '';
  if (!px || !py || parseInt(px, 10) < 1 || parseInt(py, 10) < 1) {
    alert('Please click "Choose Plot Map" to select an available cemetery plot.');
    return;
  }

  const isPurchaseOnly = document.getElementById('type_purchase_only') && document.getElementById('type_purchase_only').checked;

  const booker = (document.getElementById('add_booker_name').value || '').trim();
  if (!booker) {
    alert('Please enter the Client / Buyer Full Name.');
    document.getElementById('add_booker_name').focus();
    return;
  }
  const email = (document.getElementById('add_buyer_email') ? document.getElementById('add_buyer_email').value : '').trim();
  if (isPurchaseOnly && (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))) {
    alert('Please enter a valid Client Email Address to receive the plot purchase confirmation and receipt.');
    if (document.getElementById('add_buyer_email')) document.getElementById('add_buyer_email').focus();
    return;
  }
  const tot = parseFloat(document.getElementById('add_total_amount').value) || 0;
  if (tot <= 0) {
    alert('Please enter a valid plot price.');
    document.getElementById('add_total_amount').focus();
    return;
  }

  if (!isPurchaseOnly) {
    // Validate deceased fields
    const fn = (document.getElementById('first_name').value || '').trim();
    const ln = (document.getElementById('last_name').value || '').trim();
    if (!fn || !ln) {
      alert('Please enter First Name and Last Name of the deceased person.');
      return;
    }
    const birthday = document.getElementById('bday').value;
    const dateDied = document.getElementById('date_died').value;
    const today = new Date().toISOString().split('T')[0];

    if (birthday && birthday > today) {
      alert('Birth date cannot be in the future.');
      return;
    }
    if (dateDied && dateDied > today) {
      alert('Death date cannot be in the future.');
      return;
    }
    if (birthday && dateDied && dateDied < birthday) {
      alert('Death date cannot be before birth date.');
      return;
    }

    const contactVal = (document.getElementById('contact_no') ? document.getElementById('contact_no').value : '').replace(/\D/g, '');
    if (!/^09\d{9}$/.test(contactVal)) {
      alert('Family Contact Number must be an 11-digit mobile number starting with 09 (e.g. 09XXXXXXXXX).');
      if (document.getElementById('contact_no')) document.getElementById('contact_no').focus();
      return;
    }
  }

  const buyerContactVal = (document.getElementById('add_buyer_contact') ? document.getElementById('add_buyer_contact').value : '').replace(/\D/g, '');
  if (buyerContactVal && !/^09\d{9}$/.test(buyerContactVal)) {
    alert('Buyer Contact Number must be an 11-digit mobile number starting with 09 (e.g. 09XXXXXXXXX).');
    if (document.getElementById('add_buyer_contact')) document.getElementById('add_buyer_contact').focus();
    return;
  }

  const formData = new FormData(form);
  formData.append('ajax_request', 'add_deceased');

  const submitBtn = document.getElementById('addDecSubmitBtn');
  const origBtnText = submitBtn ? submitBtn.innerHTML : 'Save';
  if (submitBtn) {
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Saving...';
    submitBtn.disabled = true;
  }

  fetch('dashboard.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      const modalEl = document.getElementById('addDeceasedModal');
      const bsModal = bootstrap.Modal.getInstance(modalEl);
      if (bsModal) bsModal.hide();
      alert(isPurchaseOnly ? 'Plot purchase recorded successfully!' : 'Deceased person added successfully!');
      window.location.reload();
    } else {
      alert('Error: ' + (data.message || 'Failed to save record.'));
    }
  })
  .catch(err => {
    console.error('Submission error:', err);
    alert('An error occurred during submission. Please try again.');
  })
  .finally(() => {
    if (submitBtn) {
      submitBtn.innerHTML = origBtnText;
      submitBtn.disabled = false;
    }
  });
}

document.addEventListener('DOMContentLoaded', function() {
  // Suffix toggle handler
  var sSel = document.getElementById('suffix');
  var sOth = document.getElementById('suffix_other');
  if (sSel && sOth) {
    sSel.addEventListener('change', function() {
      if (this.value === 'OTHER') { sOth.style.display = 'block'; }
      else { sOth.style.display = 'none'; sOth.value = ''; }
    });
  }
  // Date max limit
  var today = new Date().toISOString().split('T')[0];
  var bEl = document.getElementById('bday');
  var dEl = document.getElementById('date_died');
  if (bEl) bEl.setAttribute('max', today);
  if (dEl) dEl.setAttribute('max', today);
  // Family & Booking Contact number helper ensuring numbers ALWAYS start with 09
  function attachFamilyContactAuto09(el) {
    if (!el) return;
    function sanitize(val) {
      let digits = String(val || '').replace(/\D/g, '');
      if (!digits) return '';
      if (digits.startsWith('639')) digits = '09' + digits.substring(3);
      else if (digits.startsWith('9')) digits = '09' + digits.substring(1);
      else if (digits.startsWith('0') && digits.length >= 2 && digits.charAt(1) !== '9') digits = '09' + digits.substring(2);
      else if (!digits.startsWith('09')) digits = '09' + digits;
      return digits.slice(0, 11);
    }
    el.setAttribute('pattern', '09[0-9]{9}');
    el.setAttribute('maxlength', '11');
    el.setAttribute('minlength', '11');
    el.setAttribute('placeholder', '09XXXXXXXXX');
    el.setAttribute('title', 'Enter 11-digit mobile number starting with 09');

    el.addEventListener('focus', function() {
      if (!this.value || this.value.trim() === '') this.value = '09';
      else this.value = sanitize(this.value);
    });
    el.addEventListener('input', function() {
      let digits = this.value.replace(/\D/g, '');
      if (!digits) { this.value = '09'; return; }
      this.value = sanitize(this.value);
    });
    el.addEventListener('keydown', function(e) {
      if ((e.key === 'Backspace' || e.key === 'Delete') && (this.selectionStart <= 2 && this.selectionEnd <= 2)) {
        e.preventDefault();
        this.setSelectionRange(2, 2);
      }
    });
    el.addEventListener('blur', function() {
      if (this.value === '09' && !this.hasAttribute('required')) this.value = '';
      else if (this.value) this.value = sanitize(this.value);
    });
    if (el.value) el.value = sanitize(el.value);
  }

  attachFamilyContactAuto09(document.getElementById('contact_no'));
  attachFamilyContactAuto09(document.getElementById('edit_contact_no'));
  attachFamilyContactAuto09(document.getElementById('add_buyer_contact'));
  attachFamilyContactAuto09(document.getElementById('contactNumber'));

  const addModalEl = document.getElementById('addDeceasedModal');
  if (addModalEl) {
    addModalEl.addEventListener('show.bs.modal', function() {
      const c = document.getElementById('contact_no');
      if (c && (!c.value || c.value.trim() === '')) {
        c.value = '09';
      }
    });
  }

  // Forward any clicks targeting legacy newDeceasedModal to addDeceasedModal
  document.querySelectorAll('[data-bs-target="#newDeceasedModal"]').forEach(function(btn) {
    btn.setAttribute('data-bs-target', '#addDeceasedModal');
  });

  updateDashPlotSummary();
  toggleRecordType();
  recalculateAddDeceasedPayment();
});
</script>

<div class="modal fade" id="editDeceasedModal" tabindex="-1" aria-labelledby="editDeceasedModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-height: 90vh;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;">

      <div class="modal-header py-3 px-4 flex-shrink-0" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff; border-bottom: none;">
        <div class="d-flex align-items-center gap-3">
          <div style="background: rgba(255,255,255,0.15); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;"><i class="fas fa-user-edit text-white"></i></div>
          <div>
            <h5 class="modal-title fw-bold m-0" id="editDeceasedModalLabel" style="font-family: 'Inter', sans-serif; font-size: 1.2rem; letter-spacing: -0.2px;">
              Edit Deceased Record
            </h5>
            <div style="font-size: 0.82rem; color: rgba(255,255,255,0.85);">Cemetery Management and Grave Mapping System — Update Information</div>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form method="POST" action="logs.php" class="d-flex flex-column flex-grow-1 overflow-hidden" style="min-height: 0; max-height: 100%;">
        <div class="modal-body p-4" style="background: #f8fafc; overflow-y: auto; flex: 1 1 auto; max-height: calc(90vh - 145px);">
          <input type="hidden" name="mode" value="update">
          <input type="hidden" id="orig_section" name="orig_section">
          <input type="hidden" id="orig_plot_x" name="orig_plot_x">
          <input type="hidden" id="orig_plot_y" name="orig_plot_y">

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; overflow: hidden; border: 1px solid #e2e8f0 !important;">
            <div class="p-3 d-flex flex-wrap align-items-center justify-content-between gap-2" style="background: #f1f5f9; border-bottom: 1px solid #e2e8f0;">
              <div class="d-flex align-items-center gap-2">
                <span class="badge px-3 py-2 fs-6 fw-bold" id="dashEditDecSectionBadge" style="background: #7b1113; color: #fff;">Section A</span>
                <span class="text-muted small fw-semibold" id="dashEditDecPlotDisplay">Plot details</span>
              </div>
              <span class="badge bg-warning text-dark px-3 py-1 fw-bold"><i class="fas fa-edit me-1"></i>Editing Mode</span>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important;">
            <div class="card-body p-3">
              <h6 class="fw-bold mb-3 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                <span class="text-danger">👤</span> Personal Information
              </h6>

              <div class="row g-3">
                <div class="col-md-4">
                  <label for="edit_first_name" class="form-label small fw-semibold mb-1">First Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="edit_first_name" name="first_name" required>
                </div>
                <div class="col-md-4">
                  <label for="edit_middle_name" class="form-label small fw-semibold mb-1">Middle Name</label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="edit_middle_name" name="middle_name">
                </div>
                <div class="col-md-4">
                  <label for="edit_last_name" class="form-label small fw-semibold mb-1">Last Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="edit_last_name" name="last_name" required>
                </div>
                <div class="col-md-4">
                  <label for="edit_suffix" class="form-label small fw-semibold mb-1">Suffix</label>
                  <select class="form-select form-select-sm" id="edit_suffix" name="suffix">
                    <option value="">No Suffix</option>
                    <option value="JR.">JR.</option>
                    <option value="SR.">SR.</option>
                    <option value="II">II</option>
                    <option value="III">III</option>
                    <option value="IV">IV</option>
                    <option value="V">V</option>
                    <option value="OTHER">OTHER</option>
                  </select>
                  <input type="text" class="form-control form-control-sm mt-2 text-uppercase" id="edit_suffix_other" name="suffix_other" placeholder="Enter custom suffix" style="display:none;">
                  <script>
                  document.addEventListener('DOMContentLoaded', function() {
                    var sel = document.getElementById('edit_suffix');
                    var other = document.getElementById('edit_suffix_other');
                    function sync() {
                      if (sel && sel.value === 'OTHER') { other.style.display = 'block'; }
                      else if (other) { other.style.display = 'none'; other.value = ''; }
                    }
                    if (sel) { sel.addEventListener('change', sync); }
                  });
                  </script>
                </div>
                <div class="col-md-4">
                  <label for="edit_bday" class="form-label small fw-semibold mb-1">Birthday <span class="text-danger">*</span></label>
                  <input type="date" class="form-control form-control-sm" id="edit_bday" name="bday" required>
                </div>
                <div class="col-md-4">
                  <label for="edit_born_in" class="form-label small fw-semibold mb-1">Born In <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="edit_born_in" name="born_in" required>
                </div>
              </div>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important;">
            <div class="card-body p-3">
              <h6 class="fw-bold mb-3 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                <span class="text-primary">🕊️</span> Demise & Funeral Information
              </h6>

              <div class="row g-3">
                <div class="col-md-6">
                  <label for="edit_date_died" class="form-label small fw-semibold mb-1">Date Died <span class="text-danger">*</span></label>
                  <input type="date" class="form-control form-control-sm" id="edit_date_died" name="date_died" required>
                </div>
                <div class="col-md-6">
                  <label for="edit_contact_no" class="form-label small fw-semibold mb-1">Family Contact Number <span class="text-danger">*</span></label>
                  <input type="tel" class="form-control form-control-sm" id="edit_contact_no" name="contact_no" inputmode="numeric" maxlength="11" minlength="11" pattern="09[0-9]{9}" placeholder="09XXXXXXXXX" title="Enter 11-digit mobile number starting with 09" oninput="var val=this.value.replace(/[^0-9]/g,''); if(val.indexOf('639')===0) val='09'+val.substring(3); else if(val.indexOf('9')===0) val='09'+val.substring(1); else if(val.length>=2 && val.charAt(0)==='0' && val.charAt(1)!=='9') val='0'; else if(val.length===1 && val!=='0') val='09'; this.value=val.slice(0,11);" required>
                </div>
                <div class="col-12">
                  <label for="edit_funeral_service" class="form-label small fw-semibold mb-1">Funeral Service <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="edit_funeral_service" name="funeral_service" placeholder="Enter funeral service name" required>
                </div>
              </div>
            </div>
          </div>

          <input type="hidden" id="edit_section" name="section">
          <input type="hidden" id="edit_plot_x" name="plot_x">
          <input type="hidden" id="edit_plot_y" name="plot_y">
        </div>

        <div class="modal-footer d-flex justify-content-between px-4 py-3 flex-shrink-0" style="background:#fff; border-top: 1px solid #e2e8f0; z-index: 10;">
          <button type="button" class="btn btn-outline-secondary px-4 py-2 fw-semibold" data-bs-dismiss="modal" style="border-radius: 2rem;">
            <i class="fas fa-times me-1"></i> Cancel
          </button>
          <button type="submit" class="btn btn-primary px-5 py-2 fw-bold shadow" style="border-radius: 2rem; background: #7b1113; border-color: #7b1113; min-width: 220px;">
            <i class="fas fa-save me-2"></i> Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
  .text-uppercase {
    text-transform: uppercase;
  }
  /* Universal Viewport-Safe Responsive Modals */
  body.modal-open {
    overflow: hidden !important;
  }
  .modal {
    overflow-x: hidden !important;
    overflow-y: hidden !important;
  }
  .modal-dialog:not(#popupSectionModal .modal-dialog) {
    max-height: 92vh !important;
    margin-top: 1.5rem !important;
    margin-bottom: 1.5rem !important;
  }
  .modal-dialog:not(#popupSectionModal .modal-dialog) .modal-content {
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    border-radius: 1.25rem !important;
  }
  .modal-header {
    flex-shrink: 0 !important;
  }
  .modal-footer {
    flex-shrink: 0 !important;
    border-top: 1px solid #e2e8f0 !important;
  }
  .modal-body:not(#popupSectionModal .modal-body) {
    overflow-y: auto !important;
    overflow-x: hidden !important;
    flex: 1 1 auto !important;
    max-height: calc(90vh - 130px) !important;
  }
  #popupSectionModal .modal-dialog {
    max-width: 1140px !important;
    width: 95% !important;
    max-height: none !important;
  }
  #popupSectionModal .modal-content {
    max-height: 95vh !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
  }
  #popupSectionModal .modal-body {
    flex: 1 1 auto !important;
    height: calc(95vh - 58px) !important;
    max-height: none !important;
    overflow: hidden !important;
    padding: 0 !important;
  }

  /* Form wrapper inside modals */
  .modal-dialog form {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    max-height: 100% !important;
    overflow: hidden !important;
  }
  #newDeceasedModal .modal-body,
  #editDeceasedModal .modal-body {
    overflow-y: auto !important;
    flex: 1 1 auto !important;
    max-height: calc(90vh - 145px) !important;
    padding-bottom: 1.5rem !important;
  }
  #newDeceasedModal .modal-footer,
  #editDeceasedModal .modal-footer {
    flex-shrink: 0 !important;
    background: #ffffff !important;
    border-top: 1px solid #e2e8f0 !important;
    z-index: 10 !important;
    padding: 0.85rem 1.5rem !important;
  }

  /* Plot Grid Styles */
  .plot-grid {
    display: grid;
    gap: 4px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #dee2e6;
  }

  .plot-cell {
    width: 35px;
    height: 35px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
  }

  .plot-cell:hover {
    transform: scale(1.05);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  }

  .plot-cell.available {
    background: #d4edda;
    border-color: #c3e6cb;
    color: #155724;
  }

  .plot-cell.occupied {
    background: #f8d7da;
    border-color: #f5c6cb;
    color: #721c24;
    cursor: not-allowed;
  }

  .plot-cell.selected {
    background: #7b1113;
    border-color: #5a0d0f;
    color: white;
    transform: scale(1.1);
    box-shadow: 0 4px 8px rgba(123, 17, 19, 0.3);
  }

  .plot-cell.premium {
    background: linear-gradient(135deg, #fff3cd, #ffeaa7);
    border-color: #ffc107;
    color: #856404;
    font-weight: 700;
  }

  .plot-cell.premium.available {
    background: linear-gradient(135deg, #d1ecf1, #bee5eb);
    border-color: #0dcaf0;
    color: #0c5460;
  }

  .plot-grid-legend {
    display: flex;
    gap: 15px;
    margin-bottom: 10px;
    font-size: 12px;
  }

  .legend-item {
    display: flex;
    align-items: center;
    gap: 5px;
  }

  .legend-box {
    width: 16px;
    height: 16px;
    border-radius: 2px;
    border: 1px solid #ced4da;
  }

  .legend-box.available {
    background: #d4edda;
    border-color: #c3e6cb;
  }

  .legend-box.occupied {
    background: #f8d7da;
    border-color: #f5c6cb;
  }

  .legend-box.premium {
    background: linear-gradient(135deg, #fff3cd, #ffeaa7);
    border-color: #ffc107;
  }

  /* Admin Overview & Analytics Styles */
  .dash-overview-section {
    margin-bottom: 2.25rem;
  }
  .dash-welcome-card {
    background: linear-gradient(135deg, #7b1113 0%, #94171a 55%, #560a0c 100%);
    color: #ffffff;
    border-radius: 1.25rem;
    padding: 1.85rem 2rem;
    box-shadow: 0 10px 30px rgba(123, 17, 19, 0.18);
    position: relative;
    overflow: hidden;
    margin-bottom: 1.5rem;
  }
  .dash-welcome-card::after {
    content: "";
    position: absolute;
    top: -50px;
    right: -40px;
    width: 220px;
    height: 220px;
    background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
    border-radius: 50%;
    pointer-events: none;
  }
  .kpi-card {
    background: #ffffff;
    border-radius: 1.15rem;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.035);
    padding: 1.35rem 1.25rem;
    transition: transform 0.22s ease, box-shadow 0.22s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }
  .kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 24px rgba(123, 17, 19, 0.08);
    border-color: #cbd5e1;
  }
  .kpi-icon-bubble {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    flex-shrink: 0;
  }
  .kpi-val {
    font-size: 1.85rem;
    font-weight: 800;
    color: #1e293b;
    letter-spacing: -0.5px;
    line-height: 1.2;
  }
  .analytics-card {
    background: #ffffff;
    border-radius: 1.15rem;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.035);
    overflow: hidden;
    height: 100%;
    display: flex;
    flex-direction: column;
  }
  .analytics-card-header {
    padding: 1rem 1.35rem;
    border-bottom: 1px solid #f1f5f9;
    background: #fafbfc;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .analytics-card-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  .analytics-card-body {
    padding: 1.35rem;
    position: relative;
    flex: 1;
  }
  .chart-canvas-container {
    position: relative;
    width: 100%;
  }
  .section-mini-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 1rem;
    padding: 1.1rem;
    transition: all 0.2s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }
  .section-mini-card:hover {
    border-color: #7b1113;
    box-shadow: 0 8px 20px rgba(123, 17, 19, 0.08);
    transform: translateY(-2px);
  }
  .dash-alert-strip {
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border: 1px solid #fde68a;
    border-radius: 1rem;
    padding: 1rem 1.35rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
  }
  .dash-quick-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.55rem 1.15rem;
    border-radius: 2rem;
    font-size: 0.84rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
    border: 1px solid transparent;
  }
  .dash-quick-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  }
  .dash-view-tab-btn {
    padding: 0.6rem 1.4rem;
    font-weight: 600;
    font-size: 0.9rem;
    border-radius: 2rem;
    border: 1px solid transparent;
    transition: all 0.2s ease;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
  }
  .dash-view-tab-btn.active {
    background: #7b1113;
    color: #ffffff !important;
    box-shadow: 0 4px 14px rgba(123, 17, 19, 0.25);
  }
  .dash-view-tab-btn:not(.active) {
    background: #ffffff;
    color: #475569 !important;
    border-color: #cbd5e1;
  }
  .dash-view-tab-btn:not(.active):hover {
    background: #f8fafc;
    color: #7b1113 !important;
    border-color: #94a3b8;
  }
</style>

<div class="main">
  <div class="container center-content">

    <!-- ======================================================= -->
    <!-- EXECUTIVE ADMIN OVERVIEW & DATA ANALYTICS HUB -->
    <!-- ======================================================= -->
    <div class="dash-overview-section" id="overviewSection">

      <!-- Top Welcome & System Status Bar -->
      <div class="dash-welcome-card">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
          <div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="badge bg-white text-danger fw-bold px-2 py-1" style="font-size:0.75rem; letter-spacing:0.5px;">ADMINISTRATIVE CONTROL</span>
              <span class="badge bg-success bg-opacity-75 text-white fw-semibold px-2 py-1" style="font-size:0.75rem;"><i class="fas fa-check-circle me-1"></i>System Online</span>
            </div>
            <h3 class="fw-bold mb-1 text-white" style="letter-spacing:-0.3px;">
              Welcome back, <?php echo htmlspecialchars($admin_display_name ?: 'Administrator'); ?>!
            </h3>
            <p class="mb-0 text-white-50 small">
              Garden of Remembrance Memorial Park CMIS &bull; Comprehensive System Overview &amp; Real-Time Data Analytics
            </p>
          </div>

          <!-- Quick Action Buttons -->
          <div class="d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="dash-quick-btn btn btn-light text-danger shadow-sm" data-bs-toggle="modal" data-bs-target="#bookingModal">
              <i class="fas fa-calendar-plus text-danger"></i> New Reservation
            </button>
            <button type="button" class="dash-quick-btn btn btn-outline-light text-white" data-bs-toggle="modal" data-bs-target="#addDeceasedModal">
              <i class="fas fa-user-plus"></i> Add Deceased
            </button>
            <button type="button" class="dash-quick-btn btn btn-outline-light text-white" data-bs-toggle="modal" data-bs-target="#announcementModal">
              <i class="fas fa-bullhorn"></i> Broadcast
            </button>
            <a href="deceased_records.php" class="dash-quick-btn btn btn-outline-light text-white">
              <i class="fas fa-cross"></i> Deceased Records
            </a>
            <a href="map.php" class="dash-quick-btn btn btn-outline-light text-white">
              <i class="fas fa-map-marked-alt"></i> Digital Map
            </a>
            <a href="sales_reports.php" class="dash-quick-btn btn btn-outline-light text-white">
              <i class="fas fa-chart-line"></i> Sales Reports
            </a>
          </div>
        </div>
      </div>

      <!-- Attention / Pending Operational Alerts Strip (if any pending items) -->
      <?php 
      $totalPendingAlerts = ($dash_pending_alerts['bookings'] ?? 0) 
                          + ($dash_pending_alerts['guest_requests'] ?? 0) 
                          + ($dash_pending_alerts['claims'] ?? 0) 
                          + ($dash_pending_alerts['transfers'] ?? 0);
      if ($totalPendingAlerts > 0): 
      ?>
        <div class="dash-alert-strip">
          <div class="d-flex align-items-center gap-2">
            <span class="fs-5 text-warning"><i class="fas fa-exclamation-triangle"></i></span>
            <div>
              <strong class="text-dark small">Action Required:</strong>
              <span class="text-secondary small ms-1">You have <?php echo $totalPendingAlerts; ?> operational item(s) awaiting administrative review.</span>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if (($dash_pending_alerts['bookings'] ?? 0) > 0): ?>
              <a href="sales_reports.php?filter_status=pending" class="badge bg-danger text-white text-decoration-none py-2 px-3 fw-semibold">
                <i class="fas fa-calendar-clock me-1"></i> <?php echo $dash_pending_alerts['bookings']; ?> Pending Reservation(s)
              </a>
            <?php endif; ?>
            <?php if (($dash_pending_alerts['guest_requests'] ?? 0) > 0): ?>
              <a href="guest_reservations.php" class="badge bg-warning text-dark text-decoration-none py-2 px-3 fw-semibold">
                <i class="fas fa-user-clock me-1"></i> <?php echo $dash_pending_alerts['guest_requests']; ?> Guest Request(s)
              </a>
            <?php endif; ?>
            <?php if (($dash_pending_alerts['claims'] ?? 0) > 0): ?>
              <a href="claims_review.php" class="badge bg-primary text-white text-decoration-none py-2 px-3 fw-semibold">
                <i class="fas fa-id-card me-1"></i> <?php echo $dash_pending_alerts['claims']; ?> Ownership Claim(s)
              </a>
            <?php endif; ?>
            <?php if (($dash_pending_alerts['transfers'] ?? 0) > 0): ?>
              <a href="transfer_requests.php" class="badge bg-info text-dark text-decoration-none py-2 px-3 fw-semibold">
                <i class="fas fa-exchange-alt me-1"></i> <?php echo $dash_pending_alerts['transfers']; ?> Transfer Request(s)
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- 4 Core Executive KPI Cards -->
      <div class="row g-3 mb-4">
        <!-- KPI 1: Plot Inventory -->
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div>
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-uppercase fw-bold text-muted small" style="font-size:0.75rem; letter-spacing:0.5px;">Cemetery Plots</span>
                <div class="kpi-icon-bubble" style="background:#ecfdf5; color:#059669;">
                  <i class="fas fa-layer-group"></i>
                </div>
              </div>
              <div class="kpi-val"><?php echo number_format($dash_kpi_plots['total']); ?></div>
              <div class="small text-muted mt-1 mb-2">
                <span class="text-success fw-bold"><?php echo number_format($dash_kpi_plots['available']); ?> Available</span> &bull; <?php echo number_format($dash_kpi_plots['occupied']); ?> Occupied
              </div>
            </div>
            <div>
              <div class="progress" style="height: 8px; border-radius: 4px; background: #e2e8f0;" title="<?php echo $dash_kpi_plots['occupancy_rate']; ?>% Occupied/Reserved">
                <?php 
                $pctOcc = $dash_kpi_plots['total'] ? round(($dash_kpi_plots['occupied'] / $dash_kpi_plots['total']) * 100) : 0;
                $pctRes = $dash_kpi_plots['total'] ? round(($dash_kpi_plots['reserved'] / $dash_kpi_plots['total']) * 100) : 0;
                $pctAvail = 100 - $pctOcc - $pctRes;
                ?>
                <div class="progress-bar bg-danger" style="width: <?php echo $pctOcc; ?>%;" title="Occupied: <?php echo $pctOcc; ?>%"></div>
                <div class="progress-bar bg-warning" style="width: <?php echo $pctRes; ?>%;" title="Reserved: <?php echo $pctRes; ?>%"></div>
                <div class="progress-bar bg-success" style="width: <?php echo $pctAvail; ?>%;" title="Available: <?php echo $pctAvail; ?>%"></div>
              </div>
              <div class="d-flex justify-content-between align-items-center small text-muted mt-1" style="font-size:0.72rem;">
                <span>Occupancy Rate</span>
                <span class="fw-bold text-dark"><?php echo $dash_kpi_plots['occupancy_rate']; ?>%</span>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 2: Deceased Registry -->
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div>
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-uppercase fw-bold text-muted small" style="font-size:0.75rem; letter-spacing:0.5px;">Deceased Interred</span>
                <div class="kpi-icon-bubble" style="background:#fee2e2; color:#b91c1c;">
                  <i class="fas fa-users"></i>
                </div>
              </div>
              <div class="kpi-val"><?php echo number_format($dash_kpi_deceased['total']); ?></div>
              <div class="small text-muted mt-1 mb-2">
                <span class="text-danger fw-bold">+<?php echo number_format($dash_kpi_deceased['this_year']); ?></span> interments this year (<?php echo date('Y'); ?>)
              </div>
            </div>
            <div>
              <div class="d-flex align-items-center justify-content-between pt-2 border-top small text-muted" style="font-size:0.75rem;">
                <span>This Month: <strong><?php echo number_format($dash_kpi_deceased['this_month']); ?></strong></span>
                <a href="deceased_records.php" class="text-danger fw-semibold text-decoration-none">View Registry &rarr;</a>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 3: Booking Applications -->
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div>
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-uppercase fw-bold text-muted small" style="font-size:0.75rem; letter-spacing:0.5px;">Total Reservations</span>
                <div class="kpi-icon-bubble" style="background:#eff6ff; color:#2563eb;">
                  <i class="fas fa-file-invoice"></i>
                </div>
              </div>
              <div class="kpi-val"><?php echo number_format($dash_kpi_bookings['total']); ?></div>
              <div class="small text-muted mt-1 mb-2">
                <span class="text-success fw-bold"><?php echo number_format($dash_kpi_bookings['confirmed']); ?> Approved</span> &bull; 
                <span class="text-warning fw-bold"><?php echo number_format($dash_kpi_bookings['pending']); ?> Pending</span>
              </div>
            </div>
            <div>
              <div class="d-flex align-items-center justify-content-between pt-2 border-top small text-muted" style="font-size:0.75rem;">
                <span>Clients: <strong><?php echo number_format($dash_kpi_users['clients']); ?></strong></span>
                <a href="sales_reports.php" class="text-primary fw-semibold text-decoration-none">View All &rarr;</a>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 4: Financial Revenue Collections -->
        <div class="col-xl-3 col-md-6">
          <div class="kpi-card">
            <div>
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-uppercase fw-bold text-muted small" style="font-size:0.75rem; letter-spacing:0.5px;">Collections / Sales</span>
                <div class="kpi-icon-bubble" style="background:#fef3c7; color:#d97706;">
                  <i class="fas fa-peso-sign"></i>
                </div>
              </div>
              <div class="kpi-val text-success" style="font-size:1.6rem;">
                ₱<?php echo number_format($dash_kpi_bookings['total_paid'], 2); ?>
              </div>
              <div class="small text-muted mt-1 mb-2">
                Receivables: <strong>₱<?php echo number_format($dash_kpi_bookings['total_balance'], 2); ?></strong>
              </div>
            </div>
            <div>
              <div class="d-flex align-items-center justify-content-between pt-2 border-top small text-muted" style="font-size:0.75rem;">
                <span>Total Volume: <strong>₱<?php echo number_format($dash_kpi_bookings['total_sales'], 2); ?></strong></span>
                <a href="sales_reports.php" class="text-success fw-semibold text-decoration-none">Reports &rarr;</a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Data Analytics Charts - Row 1 -->
      <div class="row g-3 mb-4">
        <!-- Chart 1: Section Occupancy & Plot Status Breakdown (Grouped Bar Chart) -->
        <div class="col-lg-8">
          <div class="analytics-card">
            <div class="analytics-card-header">
              <h6 class="analytics-card-title">
                <i class="fas fa-chart-bar text-danger"></i> Section Plot Capacity &amp; Occupancy Breakdown
              </h6>
              <span class="badge bg-light text-dark border small">Sections A &ndash; F</span>
            </div>
            <div class="analytics-card-body">
              <div class="chart-canvas-container" style="height: 290px;">
                <canvas id="chartSectionOccupancy"></canvas>
              </div>
            </div>
          </div>
        </div>

        <!-- Chart 2: Plot Status Distribution (Doughnut Chart) -->
        <div class="col-lg-4">
          <div class="analytics-card">
            <div class="analytics-card-header">
              <h6 class="analytics-card-title">
                <i class="fas fa-chart-pie text-danger"></i> Plot Status Allocation
              </h6>
              <span class="badge bg-light text-dark border small">Total Plots</span>
            </div>
            <div class="analytics-card-body d-flex flex-column align-items-center justify-content-center">
              <div class="chart-canvas-container" style="height: 230px; max-width: 250px;">
                <canvas id="chartPlotStatus"></canvas>
              </div>
              <div class="d-flex justify-content-around w-100 mt-3 pt-2 border-top text-center">
                <div>
                  <div class="small text-muted" style="font-size:0.72rem;">Available</div>
                  <strong class="text-success"><?php echo number_format($dash_kpi_plots['available']); ?></strong>
                </div>
                <div>
                  <div class="small text-muted" style="font-size:0.72rem;">Occupied</div>
                  <strong class="text-danger"><?php echo number_format($dash_kpi_plots['occupied']); ?></strong>
                </div>
                <div>
                  <div class="small text-muted" style="font-size:0.72rem;">Reserved</div>
                  <strong class="text-warning"><?php echo number_format($dash_kpi_plots['reserved']); ?></strong>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Data Analytics Charts - Row 2 -->
      <div class="row g-3 mb-4">
        <!-- Chart 3: Monthly Booking & Revenue Trend (Dual Axis / Area Line Chart) -->
        <div class="col-lg-7">
          <div class="analytics-card">
            <div class="analytics-card-header">
              <h6 class="analytics-card-title">
                <i class="fas fa-chart-line text-primary"></i> Monthly Reservation Volume &amp; Collections Growth
              </h6>
              <span class="badge bg-light text-secondary border small">Past 6 Months</span>
            </div>
            <div class="analytics-card-body">
              <div class="chart-canvas-container" style="height: 260px;">
                <canvas id="chartMonthlyTrend"></canvas>
              </div>
            </div>
          </div>
        </div>

        <!-- Chart 4: Service & Purpose Breakdown -->
        <div class="col-lg-5">
          <div class="analytics-card">
            <div class="analytics-card-header">
              <h6 class="analytics-card-title">
                <i class="fas fa-concierge-bell text-warning"></i> Reservation Type Distribution
              </h6>
              <span class="badge bg-light text-secondary border small">Lot vs Services</span>
            </div>
            <div class="analytics-card-body d-flex flex-column align-items-center justify-content-center">
              <div class="chart-canvas-container" style="height: 200px; max-width: 220px;">
                <canvas id="chartServicesBreakdown"></canvas>
              </div>
              <div class="d-flex justify-content-around w-100 mt-3 pt-2 border-top text-center">
                <div>
                  <div class="small text-muted" style="font-size:0.72rem;">Plot Lot Purchases</div>
                  <strong class="text-primary"><?php echo number_format($dash_chart_services['plot_sales'] ?? 0); ?></strong>
                </div>
                <div>
                  <div class="small text-muted" style="font-size:0.72rem;">Cemetery Services</div>
                  <strong class="text-warning"><?php echo number_format($dash_chart_services['services'] ?? 0); ?></strong>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Section Capacities & Status Grid (Sections A through F) -->
      <div class="card border-0 shadow-sm mb-4" style="border-radius: 1.15rem; background: #ffffff;">
        <div class="card-header bg-white py-3 px-4 d-flex align-items-center justify-content-between" style="border-bottom: 1px solid #f1f5f9;">
          <div>
            <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
              <i class="fas fa-th text-danger"></i> Section Capacities &amp; Live Availability Grid
            </h6>
            <small class="text-muted">Real-time status overview across all Memorial Park designated sections</small>
          </div>
          <a href="map.php" class="btn btn-sm btn-outline-danger px-3 py-1 fw-semibold rounded-pill">
            <i class="fas fa-map me-1"></i> Digital Map
          </a>
        </div>
        <div class="card-body p-3">
          <div class="row g-3">
            <?php foreach ($sections_summary as $secName => $secData): 
              $sTotal = (int)($secData['total'] ?? 0);
              $sAvail = (int)($secData['available'] ?? 0);
              $sOcc = (int)($secData['occupied'] ?? 0);
              $sRes = (int)($secData['reserved'] ?? 0);
              $sRate = $sTotal > 0 ? round((($sOcc + $sRes) / $sTotal) * 100) : 0;
            ?>
              <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="section-mini-card">
                  <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                      <span class="badge fw-bold px-2 py-1" style="background:#7b1113; color:#fff; font-size:0.85rem;">
                        Section <?php echo $secName; ?>
                      </span>
                      <small class="text-muted fw-bold"><?php echo $sRate; ?>%</small>
                    </div>
                    <div class="small fw-semibold text-truncate mb-1" title="<?php echo htmlspecialchars($secData['lawn_type']); ?>">
                      <?php echo htmlspecialchars($secData['lawn_type']); ?>
                    </div>
                    <div class="text-success fw-bold small mb-2">
                      ₱<?php echo number_format($secData['base_price'], 2); ?>
                    </div>

                    <div class="progress mb-2" style="height: 6px; border-radius: 3px; background:#e2e8f0;">
                      <div class="progress-bar bg-danger" style="width: <?php echo $sTotal ? round(($sOcc/$sTotal)*100) : 0; ?>%;"></div>
                      <div class="progress-bar bg-warning" style="width: <?php echo $sTotal ? round(($sRes/$sTotal)*100) : 0; ?>%;"></div>
                      <div class="progress-bar bg-success" style="width: <?php echo $sTotal ? round(($sAvail/$sTotal)*100) : 0; ?>%;"></div>
                    </div>

                    <div class="small text-muted" style="font-size:0.75rem; line-height: 1.4;">
                      <div class="d-flex justify-content-between">
                        <span>Available:</span> <strong class="text-success"><?php echo $sAvail; ?></strong>
                      </div>
                      <div class="d-flex justify-content-between">
                        <span>Occupied:</span> <strong class="text-danger"><?php echo $sOcc; ?></strong>
                      </div>
                      <div class="d-flex justify-content-between">
                        <span>Reserved:</span> <strong class="text-warning"><?php echo $sRes; ?></strong>
                      </div>
                    </div>
                  </div>

                  <div class="mt-3 pt-2 border-top text-center">
                    <a href="sections/popup.php?section=<?php echo urlencode($secName); ?>" class="btn btn-outline-secondary btn-sm w-100 py-0" style="font-size:0.75rem; border-radius:4px;">
                      <i class="fas fa-eye me-1"></i> Section Map
                    </a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Recent Operational Activity Feeds (Two Columns) -->
      <div class="row g-3 mb-4">
        <!-- Left: Recent 5 Bookings -->
        <div class="col-lg-7">
          <div class="analytics-card">
            <div class="analytics-card-header">
              <h6 class="analytics-card-title">
                <i class="fas fa-clock text-primary"></i> Recent Reservation Applications
              </h6>
              <a href="sales_reports.php" class="btn btn-sm btn-link text-danger p-0 fw-semibold text-decoration-none">View All Reservations &rarr;</a>
            </div>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                  <tr>
                    <th>Customer Name</th>
                    <th>Plot / Address</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($dash_recent_bookings)): ?>
                    <?php foreach ($dash_recent_bookings as $b): 
                      $bStat = strtolower(trim((string)($b['status'] ?? 'pending')));
                      $bBadgeClass = 'bg-secondary';
                      if (in_array($bStat, ['approved', 'confirmed', 'completed'])) $bBadgeClass = 'bg-success';
                      elseif ($bStat === 'pending') $bBadgeClass = 'bg-warning text-dark';
                      elseif (in_array($bStat, ['cancelled', 'rejected'])) $bBadgeClass = 'bg-danger';
                    ?>
                      <tr>
                        <td class="fw-semibold text-truncate" style="max-width: 140px;" title="<?php echo htmlspecialchars($b['customer_name']); ?>">
                          <?php echo htmlspecialchars($b['customer_name']); ?>
                        </td>
                        <td>
                          <span class="badge bg-light text-dark border">
                            Sec <?php echo htmlspecialchars($b['section'] ?? 'A'); ?> &bull; <?php echo htmlspecialchars($b['plot'] ?? '-'); ?>
                          </span>
                        </td>
                        <td class="fw-bold text-success">
                          ₱<?php echo number_format((float)($b['total_amount'] ?? 0), 2); ?>
                        </td>
                        <td>
                          <span class="badge <?php echo $bBadgeClass; ?> px-2 py-1 text-uppercase" style="font-size:0.7rem;">
                            <?php echo htmlspecialchars($b['status'] ?? 'pending'); ?>
                          </span>
                        </td>
                        <td class="text-muted" style="font-size:0.75rem;">
                          <?php echo date('M d, Y', strtotime($b['b_date'])); ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="5" class="text-center text-muted py-3">No recent reservations recorded yet.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Right: Recent 5 Deceased Interments -->
        <div class="col-lg-5">
          <div class="analytics-card">
            <div class="analytics-card-header">
              <h6 class="analytics-card-title">
                <i class="fas fa-cross text-danger"></i> Recent Interments
              </h6>
              <a href="deceased_records.php" class="btn btn-sm btn-link text-danger p-0 fw-semibold text-decoration-none">View Registry &rarr;</a>
            </div>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                  <tr>
                    <th>Deceased Name</th>
                    <th>Plot Location</th>
                    <th>Interment Date</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($dash_recent_deceased)): ?>
                    <?php foreach ($dash_recent_deceased as $dec): ?>
                      <tr>
                        <td class="fw-semibold text-truncate" style="max-width: 150px;">
                          <?php echo htmlspecialchars($dec['full_name']); ?>
                        </td>
                        <td>
                          <span class="badge bg-light text-dark border">
                            Sec <?php echo htmlspecialchars($dec['section']); ?> &bull; <?php echo (int)$dec['plot_x']; ?>,<?php echo (int)$dec['plot_y']; ?>
                          </span>
                        </td>
                        <td class="text-muted" style="font-size:0.75rem;">
                          <?php echo (!empty($dec['burial_date']) && $dec['burial_date'] !== '0000-00-00') ? date('M d, Y', strtotime($dec['burial_date'])) : '—'; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="3" class="text-center text-muted py-3">No deceased records found.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

    </div>
    <!-- /dash-overview-section -->

  </div>
</div>

<script>
// Service booking function - globally accessible
function bookService(serviceType) {
  alert('Booking ' + serviceType + ' service. You will be redirected to select a plot location.');

  // Direct navigation to map with service parameter
  window.location.href = 'map.php?service=' + serviceType;
}

document.addEventListener('DOMContentLoaded', function() {
  // Minimalist plus sign options
  var plusBtn = document.getElementById('plusOptionsBtn');
  var plusMenu = document.getElementById('plusOptionsMenu');
  if (plusBtn && plusMenu) {
    plusBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      plusMenu.style.display = plusMenu.style.display === 'block' ? 'none' : 'block';
    });
    // Hide menu when clicking outside
    document.addEventListener('click', function(e) {
      if (!plusMenu.contains(e.target) && e.target !== plusBtn) {
        plusMenu.style.display = 'none';
      }
    });
  }

  // Handle View button: desktop uses original popup, mobile uses navigation
  document.querySelectorAll('.view-popup-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var tr = btn.closest('tr');
      var section = tr ? (tr.getAttribute('data-section') || '') : '';
      var plotX = tr ? (tr.getAttribute('data-plot-x') || '') : '';
      var plotY = tr ? (tr.getAttribute('data-plot-y') || '') : '';
      var firstName = tr ? (tr.getAttribute('data-first-name') || '') : '';
      var middleName = tr ? (tr.getAttribute('data-middle-name') || '') : '';
      var lastName = tr ? (tr.getAttribute('data-last-name') || '') : '';

      // Check if on mobile
      var isMobile = window.innerWidth <= 768;

      if (isMobile) {
        // Mobile: Use navigation flow
        document.getElementById('view_full_name').textContent = (firstName + ' ' + middleName + ' ' + lastName).replace(/\s+/g, ' ').trim();
        document.getElementById('view_section').textContent = section;
        document.getElementById('view_plot').textContent = plotX + '-' + plotY;

        // Store navigation data
        window.currentNavigationData = {
          section: section,
          plotX: plotX,
          plotY: plotY,
          firstName: firstName,
          middleName: middleName,
          lastName: lastName
        };

        // Show view modal for mobile navigation
        var viewModal = new bootstrap.Modal(document.getElementById('viewDeceasedModal'));
        viewModal.show();
      } else {
        // Desktop: Use original popup method - show immediately
        window.__openedFromBookingModal = false;
        if (typeof window.setPopupModalMode === 'function') window.setPopupModalMode('view', section);
        var base = 'sections/popup.php?section=' + encodeURIComponent(section) + '&plot_x=' + encodeURIComponent(plotX) + '&plot_y=' + encodeURIComponent(plotY);
        var url = base + (base.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
        var iframe = document.getElementById('popupSectionIframe');
        if (!iframe) return;
        var adminSecSel = document.getElementById('adminPopupSectionSelect');
        if (adminSecSel && section) adminSecSel.value = section.toUpperCase();
        iframe.src = url;
        var modal = new bootstrap.Modal(document.getElementById('popupSectionModal'));
        modal.show();
      }
    });
  });

  // Handle Start Navigation button
  var startNavBtn = document.getElementById('startNavigationBtn');
  if (startNavBtn) {
    startNavBtn.addEventListener('click', function() {
      // Close view modal
      var viewModalEl = document.getElementById('viewDeceasedModal');
      if (viewModalEl) {
        var viewModal = bootstrap.Modal.getInstance(viewModalEl);
        if (viewModal) viewModal.hide();
      }

      // Show navigation modal
      setTimeout(function() {
        var navModalEl = document.getElementById('navigationModal');
        if (navModalEl) {
          var navModal = new bootstrap.Modal(navModalEl);
          navModal.show();
        }
      }, 300);
    });
  }

  // Handle Begin Real-time Navigation button
  var beginNavBtn = document.getElementById('beginNavigationBtn');
  if (beginNavBtn) {
    beginNavBtn.addEventListener('click', function() {
      var data = window.currentNavigationData;
      if (!data) return;

      // Close navigation modal
      var navModalEl = document.getElementById('navigationModal');
      if (navModalEl) {
        var navModal = bootstrap.Modal.getInstance(navModalEl);
        if (navModal) navModal.hide();
      }

      // Open map.php in new tab with navigation parameters
      var mapUrl = 'map.php';
      var params = [];
      if (data.section) params.push('section=' + encodeURIComponent(data.section));
      if (data.plotX) params.push('plot_x=' + encodeURIComponent(data.plotX));
      if (data.plotY) params.push('plot_y=' + encodeURIComponent(data.plotY));
      params.push('navigate=true');
      if (params.length) mapUrl += '?' + params.join('&');

      // Open in new tab for mobile navigation
      window.open(mapUrl, '_blank');
    });
  }

  // Edit button populate + show modal
  document.querySelectorAll('.edit-deceased-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var m = new bootstrap.Modal(document.getElementById('editDeceasedModal'));
      document.getElementById('edit_first_name').value = btn.getAttribute('data-first-name') || '';
      document.getElementById('edit_middle_name').value = btn.getAttribute('data-middle-name') || '';
      document.getElementById('edit_last_name').value = btn.getAttribute('data-last-name') || '';
      // Populate suffix
      var suffixVal = (btn.getAttribute('data-suffix') || '').toUpperCase();
      var sel = document.getElementById('edit_suffix');
      var other = document.getElementById('edit_suffix_other');
      if (sel) {
        var options = ['JR.','SR.','II','III','IV','V'];
        if (suffixVal && options.indexOf(suffixVal) !== -1) {
          sel.value = suffixVal;
          if (other) { other.style.display = 'none'; other.value = ''; }
        } else if (suffixVal) {
          sel.value = 'OTHER';
          if (other) { other.style.display = 'block'; other.value = suffixVal; }
        } else {
          sel.value = '';
          if (other) { other.style.display = 'none'; other.value = ''; }
        }
      }
      document.getElementById('edit_bday').value = btn.getAttribute('data-bday') || '';
      document.getElementById('edit_born_in').value = btn.getAttribute('data-born-in') || '';
      document.getElementById('edit_contact_no').value = btn.getAttribute('data-contact-no') || '';
      document.getElementById('edit_date_died').value = btn.getAttribute('data-date-died') || '';
      document.getElementById('edit_funeral_service').value = btn.getAttribute('data-funeral-service') || '';
      var secVal = btn.getAttribute('data-section') || '';
      var pxVal = btn.getAttribute('data-plot-x') || '';
      var pyVal = btn.getAttribute('data-plot-y') || '';
      document.getElementById('edit_section').value = secVal;
      document.getElementById('edit_plot_x').value = pxVal;
      document.getElementById('edit_plot_y').value = pyVal;
      document.getElementById('orig_section').value = secVal;
      document.getElementById('orig_plot_x').value = pxVal;
      document.getElementById('orig_plot_y').value = pyVal;
      var secBadge = document.getElementById('dashEditDecSectionBadge');
      if (secBadge) secBadge.textContent = 'Section ' + (secVal ? secVal.toUpperCase() : 'A');
      var plotDisp = document.getElementById('dashEditDecPlotDisplay');
      if (plotDisp) plotDisp.textContent = 'Plot Coordinates: Column ' + (pxVal || '-') + ', Row ' + (pyVal || '-');
      m.show();
    });
  });

  // Clear iframe src on modal close for cleanup
  var popupModal = document.getElementById('popupSectionModal');
  if (popupModal) {
    var clearPopupIframe = function() {
      var iframe = document.getElementById('popupSectionIframe');
      if (iframe) {
        iframe.src = 'about:blank';
      }
    };
    popupModal.addEventListener('hidden.bs.modal', clearPopupIframe);
  }
});
</script>

  <div class="modal fade" id="popupSectionModal" tabindex="-1" aria-labelledby="popupSectionModalLabel" aria-hidden="true" data-bs-keyboard="false" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered">
      <div class="modal-content" style="border-radius:1.25rem;overflow:hidden;border:none;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div class="modal-header d-flex justify-content-between align-items-center" style="background:#7b1113;color:#fff;border-bottom:1px solid rgba(255,255,255,0.15);">
          <!-- When in Booking Mode -->
          <div id="adminPopupSectionBookingHeader" class="d-flex align-items-center gap-3">
            <h5 class="modal-title m-0 fw-bold" id="popupSectionModalLabel" style="color:#fff;">
              <span style="font-size:1.3rem;margin-right:0.3rem;">🗺️</span> Choose Grave Plot
            </h5>
            <div class="d-flex align-items-center gap-2">
              <label for="adminPopupSectionSelect" class="small mb-0 text-white fw-semibold">Section:</label>
              <select id="adminPopupSectionSelect" class="form-select form-select-sm" style="width: auto; font-weight: 600;">
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
          </div>
          <!-- When in View / Search Mode -->
          <div id="adminPopupSectionViewHeader" class="align-items-center gap-2" style="display:none !important;">
            <h5 class="modal-title m-0 fw-bold" id="popupSectionModalViewLabel" style="color:#fff;">
              <span style="font-size:1.3rem;margin-right:0.3rem;">🗺️</span> <span id="adminPopupSectionViewTitle">Cemetery Map</span>
            </h5>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-0" style="height:calc(95vh - 58px);">
          <iframe id="popupSectionIframe" src="" style="width:100%;height:100%;border:0;min-height:500px;background:#f8f9fa;"></iframe>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Booking approval functions
    function approveBooking(bookingId) {
      if (confirm('Are you sure you want to approve this booking?')) {
        fetch('dashboard.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: 'action=approve_booking&booking_id=' + bookingId
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert('Booking approved successfully!');
            location.reload();
          } else {
            alert('Error: ' + (data.message || 'Please try again.'));
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred. Please try again.');
        });
      }
    }

    function rejectBooking(bookingId) {
      if (confirm('Are you sure you want to reject this booking?')) {
        fetch('dashboard.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: 'action=reject_booking&booking_id=' + bookingId
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert('Booking rejected successfully!');
            location.reload();
          } else {
            alert('Error: ' + (data.message || 'Please try again.'));
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred. Please try again.');
        });
      }
    }
  </script>

  <script>
    function populateServices() {
      const serviceSelect = document.getElementById('serviceType');
      if (!serviceSelect && !document.getElementById('adminServiceType')) return;
      fetch('api_booking.php?action=get_services')
        .then(r => r.json())
        .then(data => {
          if (!data.success) {
            console.error('Error loading services:', data.message);
            return;
          }
          var targets = [document.getElementById('serviceType'), document.getElementById('adminServiceType')];
          targets.forEach(function(sel) {
            if (!sel) return;
            var curVal = sel.value;
            sel.innerHTML = '';
            data.services.forEach(function(service) {
              var opt = document.createElement('option');
              opt.value = service.service_name;
              opt.dataset.price = Number(service.price || 200);
              opt.textContent = `${service.service_name} — ₱${Number(service.price || 200).toFixed(2)}`;
              if (curVal && curVal === service.service_name) {
                opt.selected = true;
              } else if (!curVal && service.service_name.toLowerCase() === 'maintenance') {
                opt.selected = true;
              }
              sel.appendChild(opt);
            });
          });
        })
        .catch(error => {
          console.error('Failed to load services:', error);
        });
    }

    function updatePurposeUI() {
      const purpose = document.getElementById('bookingPurpose') ? document.getElementById('bookingPurpose').value : 'reserve_grave';
      const serviceSelect = document.getElementById('serviceType');
      const purposeHint = document.getElementById('purposeHint');
      const isCemeteryService = purpose === 'cemetery_service';

      if (serviceSelect) {
        serviceSelect.required = isCemeteryService;
      }
      var svcContainer = document.getElementById('serviceTypeContainer');
      if (svcContainer) {
        svcContainer.style.display = isCemeteryService ? 'block' : 'none';
      }

      if (purposeHint) {
        if (purpose === 'reserve_grave') {
          purposeHint.textContent = 'Booking a new grave. Service is optional.';
        } else if (purpose === 'add_deceased') {
          purposeHint.textContent = 'Adding additional deceased. Service may be requested separately.';
        } else if (purpose === 'cemetery_service') {
          purposeHint.textContent = 'Cemetery service selected. Please choose a service type.';
        }
      }
    }

    function renderPlotGrid(plots) {
      const plotGrid = document.getElementById('plotGrid');
      if (!plotGrid) return;
      if (!Array.isArray(plots) || plots.length === 0) {
        plotGrid.innerHTML = '<div class="text-muted">No plots available to display.</div>';
        return;
      }
      plotGrid.innerHTML = '';
      const grid = document.createElement('div');
      grid.style.display = 'grid';
      grid.style.gridTemplateColumns = 'repeat(auto-fit, minmax(70px, 1fr))';
      grid.style.gap = '8px';
      plots.forEach(plot => {
        const card = document.createElement('div');
        card.className = 'plot-cell ' + (plot.status === 'available' ? 'available' : 'occupied');
        card.textContent = plot.plot_no.replace(/^.*?-/, '');
        card.title = `${plot.plot_no} — ${plot.status}`;
        grid.appendChild(card);
      });
      plotGrid.appendChild(grid);
    }

    function updateBookingSummary() {
      const sectionSelect = document.getElementById('section');
      const serviceSelect = document.getElementById('serviceType');
      const plotPriceInput = document.getElementById('plot_price');
      const preferredDateInput = document.getElementById('preferredDate');
      const bookingSummary = document.getElementById('bookingSummary');
      if (!bookingSummary) return;

      const section = sectionSelect ? sectionSelect.value : '';
      const service = serviceSelect ? serviceSelect.value : '';
      const plotNo = document.getElementById('plot') ? (document.getElementById('plot').value || '') : '';
      const servicePrice = serviceSelect && serviceSelect.selectedOptions[0] ? Number(serviceSelect.selectedOptions[0].dataset.price || 0) : 0;
      const plotPrice = plotPriceInput ? Number(plotPriceInput.value || 0) : 0;
      const preferredDate = preferredDateInput ? preferredDateInput.value : '';
      const total = plotPrice + servicePrice;

      bookingSummary.innerHTML = `
        <div><strong>Section:</strong> ${section || 'Not selected'}</div>
        <div><strong>Selected Plot:</strong> ${plotNo || 'Not selected'}</div>
        <div><strong>Service:</strong> ${service || 'Not selected'}</div>
        <div><strong>Plot Price:</strong> ₱${(Math.floor(plotPrice) === plotPrice) ? Number(plotPrice).toLocaleString('en-US') : plotPrice.toFixed(2)}</div>
        <div><strong>Service Price:</strong> ₱${(Math.floor(servicePrice) === servicePrice) ? Number(servicePrice).toLocaleString('en-US') : servicePrice.toFixed(2)}</div>
        <div><strong>Total:</strong> ₱${(Math.floor(total) === total) ? Number(total).toLocaleString('en-US') : total.toFixed(2)}</div>
        <div><strong>Date:</strong> ${preferredDate || 'Not selected'}</div>
      `;
    }

    function updateSectionInfo() {
      const sectionSelect = document.getElementById('section');
      const section = sectionSelect ? sectionSelect.value : '';
      const sectionInfo = document.getElementById('sectionInfo');
      const plotGrid = document.getElementById('plotGrid');
      const bookingSummary = document.getElementById('bookingSummary');
      const plotPriceInput = document.getElementById('plot_price');
      const selectedPlot = document.getElementById('plot') ? document.getElementById('plot').value : '';

      if (!section) {
        if (sectionInfo) sectionInfo.innerHTML = '<small class="text-muted">Select a section to see availability and pricing</small>';
        if (plotGrid) { plotGrid.style.display = 'none'; plotGrid.innerHTML = ''; }
        if (bookingSummary) bookingSummary.innerHTML = '<small class="text-muted">Select a section to see details</small>';
        if (plotPriceInput) plotPriceInput.value = '0';
        return;
      }

      const sectionData = {
        'A': { basePrice: 85000, availability: 'Estimated availability: 10+' },
        'B': { basePrice: 85000, availability: 'Estimated availability: 8+' },
        'C': { basePrice: 85000, availability: 'Estimated availability: 12+' },
        'D': { basePrice: 85000, availability: 'Estimated availability: 9+' },
        'E': { basePrice: 85000, availability: 'Estimated availability: 7+' },
        'F': { basePrice: 85000, availability: 'Estimated availability: 11+' },
        'G': { basePrice: 85000, availability: 'Estimated availability: 10+' },
        'H': { basePrice: 85000, availability: 'Estimated availability: 10+' },
        'I': { basePrice: 85000, availability: 'Estimated availability: 10+' },
        'J': { basePrice: 85000, availability: 'Estimated availability: 10+' }
      }[section] || { basePrice: 85000, availability: 'Availability unknown' };

      if (sectionInfo) {
        sectionInfo.innerHTML = `
          <div><strong>Section ${section}</strong></div>
          <div>${sectionData.availability}</div>
          <div><strong>Base price:</strong> ₱${(Math.floor(sectionData.basePrice) === sectionData.basePrice) ? Number(sectionData.basePrice).toLocaleString('en-US') : Number(sectionData.basePrice).toFixed(2)}</div>
        `;
      }

      if (plotGrid) {
        plotGrid.style.display = 'none';
        plotGrid.innerHTML = '';
      }
      if (bookingSummary) {
        bookingSummary.innerHTML = `
          <div><strong>Section:</strong> ${section}</div>
          <div><strong>Estimated price:</strong> ₱${(Math.floor(sectionData.basePrice) === sectionData.basePrice) ? Number(sectionData.basePrice).toLocaleString('en-US') : Number(sectionData.basePrice).toFixed(2)}</div>
        `;
      }
      if (plotPriceInput && (!selectedPlot || selectedPlot === 'AUTO')) {
        plotPriceInput.value = Number(sectionData.basePrice).toFixed(2);
      }
      if (typeof updateBookingSummary === 'function') updateBookingSummary();
    }

    document.addEventListener('DOMContentLoaded', function() {
      const sectionSelect = document.getElementById('section');
      const serviceSelect = document.getElementById('serviceType');
      const preferredDateInput = document.getElementById('preferredDate');

      if (serviceSelect) {
        populateServices();
        serviceSelect.addEventListener('change', updateBookingSummary);
      }

      // Populate inline service selector (used in serviceDetails)
      var serviceSelectInline = document.getElementById('serviceSelectInline');
      if (serviceSelectInline) {
        fetch('api_booking.php?action=get_services')
          .then(r => r.json())
          .then(data => {
            if (!data.success) return;
            data.services.forEach(s => {
              var opt = document.createElement('option');
              opt.value = s.service_name;
              opt.dataset.price = Number(s.price || 0);
              opt.textContent = s.service_name + ' — ₱' + Number(s.price || 0).toFixed(2);
              serviceSelectInline.appendChild(opt);
            });
            serviceSelectInline.addEventListener('change', function() {
              var price = 0;
              if (this.selectedOptions && this.selectedOptions[0]) price = Number(this.selectedOptions[0].dataset.price || 0);
              document.getElementById('servicePriceDisplay').textContent = '₱' + price.toFixed(2);
              document.getElementById('service_price').value = price.toFixed(2);
              updateBookingSummary();
            });
          });
      }

      // Purpose-based UI toggles
      function togglePurposeSections() {
        var purpose = document.getElementById('bookingPurpose') ? document.getElementById('bookingPurpose').value : 'reserve_grave';
        var reserve = document.getElementById('reserveDetails');
        var addDec = document.getElementById('addDeceasedDetails');
        var adminAddDec = document.getElementById('adminAddDeceasedContainer');
        var svc = document.getElementById('serviceDetails');
        if (reserve) reserve.style.display = (purpose === 'reserve_grave') ? 'block' : 'none';
        if (addDec) addDec.style.display = (purpose === 'add_deceased') ? 'block' : 'none';
        if (adminAddDec) adminAddDec.style.display = (purpose === 'add_deceased') ? 'block' : 'none';
        if (svc) svc.style.display = (purpose === 'cemetery_service') ? 'block' : 'none';
        // serviceType container remains shown only when cemetery_service
        var svcContainer = document.getElementById('serviceTypeContainer'); if (svcContainer) svcContainer.style.display = (purpose === 'cemetery_service') ? 'block' : 'none';
        var adminSvcCont = document.getElementById('adminServiceTypeContainer'); if (adminSvcCont) adminSvcCont.style.display = (purpose === 'cemetery_service') ? 'block' : 'none';

        if (purpose === 'add_deceased') {
          if (typeof window.initOrSyncDeceasedCards === 'function') {
            window.initOrSyncDeceasedCards();
          }
          if (typeof window.checkAllDeceasedCardsInterval === 'function') {
            window.checkAllDeceasedCardsInterval();
          }
        } else {
          var submitBtn = document.getElementById('adminSubmitBookingBtn');
          if (submitBtn) submitBtn.disabled = false;
        }
      }

      // =========================================================================
      // DYNAMIC DECEASED PERSONS & PLOT ASSIGNMENT LOGIC (ADMIN BOOKING)
      // =========================================================================
      window.getAvailableDeceasedTargetPlots = function() {
        var list = window.__selectedPlotsList || [];
        if (list.length > 0) {
          return list.map(function(p, i) {
            var sec = String(p.section || 'A').toUpperCase();
            var px = p.plot_x || '';
            var py = p.plot_y || '';
            var pNo = (px && py) ? (px + ',' + py) : (p.plot_number || (i + 1));
            var key = sec + ':' + px + ':' + py;
            var label = 'Plot ' + (i + 1) + ': Section ' + sec + ' — ' + pNo + ' (' + (p.status || 'OCCUPIED') + ')';
            return {
              key: key,
              section: sec,
              plot_x: px,
              plot_y: py,
              plot_id: p.plot_id || '',
              plot_number: pNo,
              label: label,
              status: p.status || 'OCCUPIED'
            };
          });
        }
        var sec = (document.getElementById('section') ? document.getElementById('section').value : 'A').toUpperCase();
        var px = document.getElementById('plot_x') ? document.getElementById('plot_x').value : '';
        var py = document.getElementById('plot_y') ? document.getElementById('plot_y').value : '';
        var plotVal = document.getElementById('plot') ? document.getElementById('plot').value : '';
        if ((!px || !py) && plotVal) {
          var parts = plotVal.split(/[,-]/);
          if (parts.length >= 2) { px = parts[0].trim(); py = parts[1].trim(); }
        }
        var pNo = (px && py) ? (px + ',' + py) : (plotVal || '1,1');
        var key = sec + ':' + px + ':' + py;
        return [{
          key: key,
          section: sec,
          plot_x: px,
          plot_y: py,
          plot_id: document.getElementById('plot_id') ? document.getElementById('plot_id').value : '',
          plot_number: pNo,
          label: 'Section ' + sec + ' — ' + pNo,
          status: 'OCCUPIED'
        }];
      };

      window.createDeceasedCardHtml = function(cardIndex, preferredPlotKey) {
        var plots = window.getAvailableDeceasedTargetPlots();
        var optionsHtml = '';
        plots.forEach(function(pl, i) {
          var isSelected = (preferredPlotKey && preferredPlotKey === pl.key) || (!preferredPlotKey && i === 0);
          optionsHtml += '<option value="' + pl.key + '"' + (isSelected ? ' selected' : '') + '>' + pl.label + '</option>';
        });

        var cardNum = cardIndex + 1;
        var todayDate = new Date().toISOString().split('T')[0];

        var html = 
          '<div class="admin-deceased-card border rounded p-3 bg-white shadow-sm" data-card-index="' + cardIndex + '" style="border-color: #d1d5db !important;">' +
            '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2 pb-2 border-bottom">' +
              '<div class="d-flex flex-wrap align-items-center gap-2">' +
                '<span class="badge bg-primary px-2 py-1 card-num-badge fw-bold">Deceased Person #' + cardNum + '</span>' +
                '<label class="form-label small fw-bold text-dark mb-0 ms-1">Assigned Plot:</label>' +
                '<select class="form-select form-select-sm admin-dec-target-plot fw-bold text-primary" style="min-width: 220px; width: auto;" onchange="window.onDeceasedCardPlotChange(this)">' +
                  optionsHtml +
                '</select>' +
              '</div>' +
              '<div class="d-flex align-items-center gap-2">' +
                '<span class="badge bg-secondary px-2 py-1 card-occupants-badge">Occupants: -/4</span>' +
                '<button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 card-remove-btn" onclick="window.removeDeceasedCard(this)" title="Remove this deceased person" style="display:none;">' +
                  '<i class="fas fa-trash-alt me-1"></i> Remove' +
                '</button>' +
              '</div>' +
            '</div>' +

            '<!-- Status and Interval Box -->' +
            '<div class="card-interval-status alert alert-info py-2 px-3 small border-0 mb-2" style="border-radius: 6px;">' +
              '<i class="fas fa-spinner fa-spin me-1"></i> Checking plot interment interval eligibility...' +
            '</div>' +

            '<!-- Existing Occupants Details Box -->' +
            '<div class="card-existing-occupants p-2 mb-2 bg-light rounded border small" style="display:none;">' +
              '<div class="fw-bold text-dark mb-1"><i class="fas fa-monument text-secondary me-1"></i> Current Plot Occupants:</div>' +
              '<div class="card-existing-occupants-list text-secondary"></div>' +
            '</div>' +

            '<!-- Form Inputs -->' +
            '<div class="card-inputs-container">' +
              '<div class="row g-2 mb-1">' +
                '<div class="col-md-6">' +
                  '<label class="form-label small fw-semibold mb-1">First Name <span class="text-danger">*</span></label>' +
                  '<input type="text" class="form-control form-control-sm dec-first-name" required oninput="this.value = this.value.toUpperCase()">' +
                '</div>' +
                '<div class="col-md-6">' +
                  '<label class="form-label small fw-semibold mb-1">Middle Name</label>' +
                  '<input type="text" class="form-control form-control-sm dec-middle-name" oninput="this.value = this.value.toUpperCase()">' +
                '</div>' +
                '<div class="col-md-6">' +
                  '<label class="form-label small fw-semibold mb-1">Last Name <span class="text-danger">*</span></label>' +
                  '<input type="text" class="form-control form-control-sm dec-last-name" required oninput="this.value = this.value.toUpperCase()">' +
                '</div>' +
                '<div class="col-md-6">' +
                  '<label class="form-label small fw-semibold mb-1">Suffix</label>' +
                  '<select class="form-select form-select-sm dec-suffix">' +
                    '<option value="">No Suffix</option>' +
                    '<option value="JR.">JR.</option>' +
                    '<option value="SR.">SR.</option>' +
                    '<option value="I">I</option>' +
                    '<option value="II">II</option>' +
                    '<option value="III">III</option>' +
                    '<option value="IV">IV</option>' +
                    '<option value="V">V</option>' +
                  '</select>' +
                '</div>' +
                '<div class="col-md-4">' +
                  '<label class="form-label small fw-semibold mb-1">Date of Birth <span class="text-danger">*</span></label>' +
                  '<input type="date" class="form-control form-control-sm dec-bday" max="' + todayDate + '">' +
                '</div>' +
                '<div class="col-md-4">' +
                  '<label class="form-label small fw-semibold mb-1">Date of Death <span class="text-danger">*</span></label>' +
                  '<input type="date" class="form-control form-control-sm dec-date-died" max="' + todayDate + '" onchange="window.onDeceasedCardDateChange(this)">' +
                '</div>' +
                '<div class="col-md-4">' +
                  '<label class="form-label small fw-semibold mb-1">Interment / Burial Date <span class="text-danger">*</span></label>' +
                  '<input type="date" class="form-control form-control-sm dec-interment-date" onchange="window.onDeceasedCardDateChange(this)">' +
                '</div>' +
                '<div class="col-md-6">' +
                  '<label class="form-label small fw-semibold mb-1">Relationship to Client <span class="text-muted fw-normal">(Optional)</span></label>' +
                  '<select class="form-select form-select-sm dec-relationship">' +
                    '<option value="">-- Select Relationship (Optional) --</option>' +
                    '<option value="PARENT">Parent</option>' +
                    '<option value="SPOUSE">Spouse</option>' +
                    '<option value="CHILD">Child</option>' +
                    '<option value="RELATIVE">Relative</option>' +
                    '<option value="OTHER">Other</option>' +
                  '</select>' +
                '</div>' +
              '</div>' +
            '</div>' +
          '</div>';

        return html;
      };

      window.initOrSyncDeceasedCards = function() {
        var container = document.getElementById('adminDeceasedCardsContainer');
        if (!container) return;

        var existingCards = container.querySelectorAll('.admin-deceased-card');
        if (existingCards.length === 0) {
          var plots = window.getAvailableDeceasedTargetPlots();
          var firstKey = plots.length > 0 ? plots[0].key : '';
          container.innerHTML = window.createDeceasedCardHtml(0, firstKey);
          var firstCard = container.querySelector('.admin-deceased-card');
          if (firstCard) window.checkCardInterval(firstCard);
        } else {
          window.syncAllDeceasedPlotDropdowns();
        }
        window.updateDeceasedCardRemoveButtons();
      };

      window.addAnotherDeceasedCard = function() {
        var container = document.getElementById('adminDeceasedCardsContainer');
        if (!container) return;

        var cards = container.querySelectorAll('.admin-deceased-card');
        var newIdx = cards.length;

        // Choose preferred plot:
        // If 2 plots are selected, card 0 gets plot 0 and card 1 gets plot 1
        var plots = window.getAvailableDeceasedTargetPlots();
        var preferredKey = '';
        if (plots.length > newIdx) {
          preferredKey = plots[newIdx].key;
        } else if (plots.length > 0) {
          preferredKey = plots[0].key;
        }

        var tempDiv = document.createElement('div');
        tempDiv.innerHTML = window.createDeceasedCardHtml(newIdx, preferredKey);
        var newCard = tempDiv.firstElementChild;
        container.appendChild(newCard);

        window.updateDeceasedCardRemoveButtons();
        window.checkCardInterval(newCard);

        if (typeof window.calculateAdminPrice === 'function') {
          window.calculateAdminPrice();
        }
      };

      window.removeDeceasedCard = function(btn) {
        var card = btn ? btn.closest('.admin-deceased-card') : null;
        if (!card) return;
        var container = document.getElementById('adminDeceasedCardsContainer');
        var cards = container ? container.querySelectorAll('.admin-deceased-card') : [];
        if (cards.length <= 1) return;

        card.remove();
        window.updateDeceasedCardRemoveButtons();

        if (typeof window.calculateAdminPrice === 'function') {
          window.calculateAdminPrice();
        }
        window.evaluateAdminSubmitButtonEligibility();
      };

      window.updateDeceasedCardRemoveButtons = function() {
        var container = document.getElementById('adminDeceasedCardsContainer');
        if (!container) return;
        var cards = container.querySelectorAll('.admin-deceased-card');
        var canRemove = (cards.length > 1);

        cards.forEach(function(card, idx) {
          card.setAttribute('data-card-index', idx);
          var badge = card.querySelector('.card-num-badge');
          if (badge) badge.textContent = 'Deceased Person #' + (idx + 1);
          var rmBtn = card.querySelector('.card-remove-btn');
          if (rmBtn) rmBtn.style.display = canRemove ? 'inline-block' : 'none';
        });
      };

      window.syncAllDeceasedPlotDropdowns = function() {
        var container = document.getElementById('adminDeceasedCardsContainer');
        if (!container) return;
        var cards = container.querySelectorAll('.admin-deceased-card');
        if (cards.length === 0) return;

        var plots = window.getAvailableDeceasedTargetPlots();
        cards.forEach(function(card, idx) {
          var sel = card.querySelector('.admin-dec-target-plot');
          if (!sel) return;
          var currentVal = sel.value;
          var newHtml = '';
          var foundCurrent = false;

          plots.forEach(function(pl, pIdx) {
            var isSel = (currentVal === pl.key);
            if (isSel) foundCurrent = true;
            newHtml += '<option value="' + pl.key + '">' + pl.label + '</option>';
          });

          sel.innerHTML = newHtml;
          if (foundCurrent) {
            sel.value = currentVal;
          } else {
            if (plots[idx]) {
              sel.value = plots[idx].key;
            } else if (plots.length > 0) {
              sel.value = plots[0].key;
            }
          }
          window.checkCardInterval(card);
        });
      };

      window.onDeceasedCardPlotChange = function(selectEl) {
        var card = selectEl ? selectEl.closest('.admin-deceased-card') : null;
        if (card) {
          window.checkCardInterval(card);
        }
      };

      window.onDeceasedCardDateChange = function(inputEl) {
        var card = inputEl ? inputEl.closest('.admin-deceased-card') : null;
        if (card) {
          window.checkCardInterval(card);
        }
      };

      window.checkCardInterval = function(cardEl) {
        if (!cardEl) return;
        var plotSel = cardEl.querySelector('.admin-dec-target-plot');
        var plotVal = plotSel ? plotSel.value : '';
        var parts = plotVal ? plotVal.split(':') : [];
        var sec = parts[0] || '';
        var px = parts[1] || '';
        var py = parts[2] || '';

        var statusBox = cardEl.querySelector('.card-interval-status');
        var occBox = cardEl.querySelector('.card-existing-occupants');
        var occList = cardEl.querySelector('.card-existing-occupants-list');
        var badgeEl = cardEl.querySelector('.card-occupants-badge');

        if (!sec || !px || !py) {
          if (statusBox) {
            statusBox.className = 'alert alert-warning py-2 px-3 small border-0 mb-2';
            statusBox.innerHTML = '<i class="fas fa-info-circle me-1"></i> Please choose or select an occupied plot to check occupants and interval.';
          }
          cardEl.dataset.isEligible = 'false';
          window.evaluateAdminSubmitButtonEligibility();
          return;
        }

        var intermentDateInput = cardEl.querySelector('.dec-interment-date');
        var diedDateInput = cardEl.querySelector('.dec-date-died');
        var targetDate = (intermentDateInput ? intermentDateInput.value : '') || (diedDateInput ? diedDateInput.value : '');

        var url = 'api_booking.php?action=check_interment_interval&section=' + encodeURIComponent(sec) + '&plot_x=' + encodeURIComponent(px) + '&plot_y=' + encodeURIComponent(py);
        if (targetDate) url += '&as_of_date=' + encodeURIComponent(targetDate);

        if (statusBox) {
          statusBox.className = 'alert alert-info py-2 px-3 small border-0 mb-2';
          statusBox.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Checking plot occupants and 8-year interval eligibility...';
        }

        fetch(url)
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (!data || !data.success) {
              if (statusBox) {
                statusBox.className = 'alert alert-danger py-2 px-3 small border-0 mb-2';
                statusBox.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> ' + (data && data.message ? data.message : 'Error verifying plot interval.');
              }
              cardEl.dataset.isEligible = 'false';
              window.evaluateAdminSubmitButtonEligibility();
              return;
            }

            var occCount = (data.occupants_count !== undefined) ? data.occupants_count : ((data.all_occupants && data.all_occupants.length) || 0);
            if (badgeEl) badgeEl.textContent = 'Occupants: ' + occCount + '/4';

            if (data.all_occupants && Array.isArray(data.all_occupants) && data.all_occupants.length > 0) {
              if (occBox && occList) {
                occBox.style.display = 'block';
                var html = '<div class="d-flex flex-column gap-1 mt-1">';
                data.all_occupants.forEach(function(occ, idx) {
                  var isLatest = (occ.full_name === data.latest_name);
                  var burDate = occ.interment_date || occ.date_died || 'Unknown';
                  html += '<div class="p-1 rounded ' + (isLatest ? 'bg-warning bg-opacity-25 border border-warning fw-semibold' : 'bg-light border') + ' px-2 d-flex justify-content-between align-items-center">';
                  html += '<span><i class="fas fa-user text-muted me-1"></i>' + (occ.full_name || 'Occupant ' + (idx + 1)) + (isLatest ? ' <span class="badge bg-danger ms-1" style="font-size:0.68rem;">Latest Interred</span>' : '') + '</span>';
                  html += '<span class="text-muted" style="font-size:0.75rem;">Buried: ' + burDate + '</span>';
                  html += '</div>';
                });
                html += '</div>';
                occList.innerHTML = html;
              }
            } else {
              if (occBox) occBox.style.display = 'none';
            }

            if (occCount >= 4) {
              if (statusBox) {
                statusBox.className = 'alert alert-danger py-2 px-3 small border-0 mb-2';
                statusBox.innerHTML = '<i class="fas fa-ban me-1"></i> <strong>Plot Capacity Reached (4/4):</strong> Maximum capacity of 4 occupants has been reached on this plot. No additional deceased can be added.';
              }
              cardEl.dataset.isEligible = 'false';
            } else if (!data.has_previous_interment || data.is_eligible) {
              if (statusBox) {
                statusBox.className = 'alert alert-success py-2 px-3 small border-0 mb-2';
                var msg = '<i class="fas fa-check-circle me-1"></i> <strong>Eligible for Additional Deceased:</strong> ';
                if (data.has_previous_interment) {
                  msg += 'Required 8-year interval satisfied (' + (data.years_elapsed || '8+') + ' years elapsed since last interment of ' + (data.latest_name || 'occupant') + '). Capacity: ' + occCount + '/4.';
                } else {
                  msg += 'Plot is available for initial interment (Capacity: ' + occCount + '/4).';
                }
                statusBox.innerHTML = msg;
              }
              cardEl.dataset.isEligible = 'true';
            } else {
              if (statusBox) {
                statusBox.className = 'alert alert-danger py-2 px-3 small border-0 mb-2';
                statusBox.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> <strong>Interment Not Yet Eligible:</strong> Only ' + (data.years_elapsed || 0) + ' years have passed since previous burial of ' + (data.latest_name || 'occupant') + ' (' + (data.formatted_interment_date || '') + '). At least 8 years interval is required.<br><span class="fw-bold text-danger">Next Eligible Date: ' + (data.formatted_eligible_date || 'N/A') + '</span>';
              }
              cardEl.dataset.isEligible = 'false';
            }

            window.evaluateAdminSubmitButtonEligibility();
          })
          .catch(function(err) {
            console.error('Error checking card plot interval:', err);
            if (statusBox) {
              statusBox.className = 'alert alert-warning py-2 px-3 small border-0 mb-2';
              statusBox.innerHTML = '<i class="fas fa-info-circle me-1"></i> Unable to verify interval eligibility at this moment. You may still proceed if authorized.';
            }
            cardEl.dataset.isEligible = 'true';
            window.evaluateAdminSubmitButtonEligibility();
          });
      };

      window.evaluateAdminSubmitButtonEligibility = function() {
        var purpose = document.getElementById('bookingPurpose') ? document.getElementById('bookingPurpose').value : 'reserve_grave';
        var submitBtn = document.getElementById('adminSubmitBookingBtn');
        if (!submitBtn) return;
        if (purpose !== 'add_deceased') {
          submitBtn.disabled = false;
          return;
        }

        var cards = document.querySelectorAll('#adminDeceasedCardsContainer .admin-deceased-card');
        if (!cards || cards.length === 0) {
          submitBtn.disabled = false;
          return;
        }

        var anyIneligible = false;
        cards.forEach(function(c) {
          if (c.dataset.isEligible === 'false') {
            anyIneligible = true;
          }
        });
        submitBtn.disabled = anyIneligible;
      };

      window.checkAllDeceasedCardsInterval = function() {
        var cards = document.querySelectorAll('#adminDeceasedCardsContainer .admin-deceased-card');
        if (cards.length === 0) {
          window.initOrSyncDeceasedCards();
        } else {
          cards.forEach(function(c) {
            window.checkCardInterval(c);
          });
        }
      };
      window.checkAdminAddDeceasedInterval = window.checkAllDeceasedCardsInterval;

      // initialize
      if (typeof window.updateBookingPurposeAvailability === 'function') {
        window.updateBookingPurposeAvailability(window.getEffectivePlotsStatus ? window.getEffectivePlotsStatus() : 'AVAILABLE');
      }
      togglePurposeSections();
      var purposeSelectEl = document.getElementById('bookingPurpose');
      if (purposeSelectEl) purposeSelectEl.addEventListener('change', function(){
        var effStatus = (typeof window.getEffectivePlotsStatus === 'function') ? window.getEffectivePlotsStatus() : 'AVAILABLE';
        if (effStatus === 'AVAILABLE' && this.value !== 'reserve_grave') {
          this.value = 'reserve_grave';
        }
        togglePurposeSections();
        updateBookingSummary();
      });

      // Installment calculations
      document.querySelectorAll('input[name="payment_option"]').forEach(function(r){ r.addEventListener('change', function(){
        var termControls = document.getElementById('installmentControls');
        var monthlyDiv = document.getElementById('reserveMonthly');
        if (this.value === 'installment') { if (termControls) termControls.style.display='block'; if (monthlyDiv) monthlyDiv.style.display='block'; }
        else { if (termControls) termControls.style.display='none'; if (monthlyDiv) monthlyDiv.style.display='none'; }
        computeReservePricing();
      }); });
      document.getElementById('paymentTerm') && document.getElementById('paymentTerm').addEventListener('change', computeReservePricing);
      document.getElementById('numLots') && document.getElementById('numLots').addEventListener('change', computeReservePricing);

      function computeReservePricing() {
        var plotPrice = Number(document.getElementById('plot_price') ? document.getElementById('plot_price').value : 0) || 0;
        var lots = Number(document.getElementById('numLots') ? document.getElementById('numLots').value : 1) || 1;
        var total = plotPrice * lots;
        var reserveTotalEl = document.getElementById('reserveTotal');
        if (reserveTotalEl) {
          reserveTotalEl.textContent = '₱' + total.toFixed(2);
        }
        if (typeof calculateAdminPrice === 'function') {
          calculateAdminPrice();
        }
        if (typeof updateBookingSummary === 'function') {
          updateBookingSummary();
        }
      }
    });
    // Admin Booking & Payment Details Modal Handler
    function viewBookingDetails(bookingId) {
      if (!bookingId) return;

      let modalEl = document.getElementById('adminBookingDetailsModal');
      if (!modalEl) {
        const modalHtml = `
          <div class="modal fade" id="adminBookingDetailsModal" tabindex="-1" aria-labelledby="adminBookingDetailsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
              <div class="modal-content" style="border-radius: 1.25rem; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.15); overflow: hidden;">
                <div class="modal-header" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%); color: #fff; border-bottom: none; padding: 1.2rem 1.5rem;">
                  <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-file-invoice-dollar text-warning"></i>
                    <h5 class="modal-title fw-bold m-0 text-white" id="adminBookingDetailsModalLabel" style="font-family: 'Inter', sans-serif;">
                      Booking & Payment Information
                    </h5>
                  </div>
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="adminBookingDetailsBody" style="padding: 1.5rem; background: #fafafa; font-family: 'Inter', sans-serif;">
                  <div class="text-center py-5">
                    <div class="spinner-border text-danger" role="status">
                      <span class="visually-hidden">Loading details...</span>
                    </div>
                    <div class="text-muted mt-2 small">Loading booking & payment details...</div>
                  </div>
                </div>
                <div class="modal-footer" id="adminBookingDetailsFooter" style="background: #ffffff; border-top: 1px solid #eee; padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                  <div>
                    <button type="button" id="adminReceiptBtn" class="btn btn-outline-secondary" style="border-radius: 8px; font-size: 0.88rem; display: none;">
                      <i class="fas fa-print me-1"></i> View / Print Receipt
                    </button>
                  </div>
                  <div class="d-flex gap-2" id="adminBookingActionBtns">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px; font-size: 0.88rem;">Close</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        modalEl = document.getElementById('adminBookingDetailsModal');
      }

      const modalBody = document.getElementById('adminBookingDetailsBody');
      const actionBtns = document.getElementById('adminBookingActionBtns');
      const receiptBtn = document.getElementById('adminReceiptBtn');

      modalBody.innerHTML = `
        <div class="text-center py-5">
          <div class="spinner-border text-danger" role="status">
            <span class="visually-hidden">Loading details...</span>
          </div>
          <div class="text-muted mt-2 small">Loading booking & payment details...</div>
        </div>
      `;

      const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
      bsModal.show();

      const formData = new FormData();
      formData.append('action', 'get_booking_details');
      formData.append('booking_id', bookingId);

      fetch('dashboard.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
      .then(r => r.json())
      .then(data => {
        if (!data.success || !data.booking) {
          modalBody.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(data.message || 'Failed to load booking details.')}</div>`;
          return;
        }

        const b = data.booking;
        const isPaid = (b.payment_status === 'PAID');
        const isPartial = (b.payment_status === 'PARTIAL');
        const statusBadgeClass = isPaid ? 'background: rgba(16, 185, 129, 0.15); color: #065f46; border: 1px solid #10b981;' : (isPartial ? 'background: rgba(245, 158, 11, 0.15); color: #92400e; border: 1px solid #f59e0b;' : 'background: rgba(239, 68, 68, 0.12); color: #991b1b; border: 1px solid #ef4444;');
        const bookingStatusClass = b.status === 'approved' ? 'background: rgba(16, 185, 129, 0.15); color: #065f46;' : (b.status === 'completed' ? 'background: rgba(59, 130, 246, 0.15); color: #1e40af;' : (b.status === 'rejected' ? 'background: rgba(239, 68, 68, 0.15); color: #991b1b;' : 'background: rgba(245, 158, 11, 0.15); color: #92400e;'));

        let proofHtml = '';
        if (b.proof_filename) {
          proofHtml = `
            <div class="mt-3 p-3 bg-white rounded border d-flex align-items-center justify-content-between">
              <div class="d-flex align-items-center gap-3">
                <i class="fas fa-image text-primary" style="font-size: 1.5rem;"></i>
                <div>
                  <div class="fw-bold text-dark" style="font-size: 0.88rem;">Payment Screenshot Proof</div>
                  <div class="text-muted small font-monospace">${escapeHtml(b.proof_filename)}</div>
                </div>
              </div>
              <button type="button" class="btn btn-sm btn-primary" onclick="adminViewPaymentProof(${b.id}, '${escapeHtml(b.proof_filename)}')" style="border-radius: 8px; background: #7b1113; border: none;">
                <i class="fas fa-eye me-1"></i> View Proof
              </button>
            </div>
          `;
        }

        const guestBanner = b.is_guest_booking ? `
          <div class="alert alert-warning border d-flex align-items-center gap-2 mb-3" style="background: #fffbeb; border-color: #fef3c7; color: #92400e; border-radius: 10px;">
            <i class="fas fa-user-clock fs-4 flex-shrink-0"></i>
            <div>
              <strong>Guest Reservation Request</strong><br>
              <span class="small">The guest has not completed their profile yet. When you click <strong>Approve</strong>, the system verifies plot availability and emails a secure Complete Profile link to <strong>${escapeHtml(b.email)}</strong>.</span>
            </div>
          </div>
        ` : '';

        // Render Payment History rows
        let paymentHistoryHtml = '';
        if (b.payment_history && b.payment_history.length > 0) {
          paymentHistoryHtml = `
            <div class="table-responsive mt-2">
              <table class="table table-sm table-bordered align-middle mb-0" style="font-size: 0.82rem;">
                <thead style="background: #f8fafc;">
                  <tr class="text-muted">
                    <th style="width: 85px;">Payment #</th>
                    <th>Amount Paid</th>
                    <th>Actual Method</th>
                    <th>OR / Receipt No.</th>
                    <th>Payment Date</th>
                    <th>Recorded By</th>
                    <th style="width: 80px;" class="text-center">Status</th>
                  </tr>
                </thead>
                <tbody>
                  ${b.payment_history.map(p => `
                    <tr>
                      <td class="fw-bold text-dark">Payment #${p.payment_index}</td>
                      <td class="fw-bold text-success font-monospace">₱${p.formatted_amount}</td>
                      <td>
                        <span class="badge bg-light text-dark border">
                          <i class="fas ${p.display_method.toLowerCase().includes('gcash') ? 'fa-mobile-alt text-primary' : 'fa-money-bill-wave text-success'} me-1"></i>
                          ${escapeHtml(p.display_method)}
                        </span>
                      </td>
                      <td class="font-monospace fw-semibold">${escapeHtml(p.display_ref)}</td>
                      <td class="text-muted">${escapeHtml(p.formatted_date)}</td>
                      <td class="small text-muted">${escapeHtml(p.display_recorder)}</td>
                      <td class="text-center">
                        <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #065f46; border: 1px solid #10b981;">
                          ${escapeHtml(p.payment_status || 'PAID')}
                        </span>
                      </td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>
          `;
        } else {
          paymentHistoryHtml = `
            <div class="p-3 bg-light rounded border text-muted small text-center my-2">
              <i class="fas fa-info-circle me-1"></i> No payment transactions recorded yet. Outstanding balance is <strong>₱${b.total_amount}</strong>.
            </div>
          `;
        }

        // Render Installment schedule rows if applicable
        let installmentScheduleHtml = '';
        if (b.installment_schedules && b.installment_schedules.length > 0) {
          installmentScheduleHtml = `
            <div class="mt-3 pt-3 border-top">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-bold text-dark" style="font-size: 0.88rem;">
                  <i class="fas fa-calendar-alt text-primary me-1"></i> Installment Amortization Schedule (${b.installment_schedules.length} Months)
                </div>
                <span class="text-muted small">Remaining: <strong class="text-dark">₱${b.balance}</strong></span>
              </div>
              <div class="table-responsive" style="max-height: 200px; overflow-y: auto;">
                <table class="table table-sm table-bordered align-middle text-center mb-0" style="font-size: 0.78rem;">
                  <thead class="table-light sticky-top">
                    <tr>
                      <th>Inst. #</th>
                      <th>Due Date</th>
                      <th>Monthly Due</th>
                      <th>Paid Amount</th>
                      <th>Status</th>
                      <th>Payment Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${b.installment_schedules.map(sch => `
                      <tr>
                        <td class="fw-bold">Month #${sch.installment_number}</td>
                        <td>${escapeHtml(sch.formatted_due_date)}</td>
                        <td class="font-monospace">₱${sch.formatted_due}</td>
                        <td class="font-monospace text-success fw-bold">₱${sch.formatted_paid}</td>
                        <td>
                          <span class="badge ${sch.status === 'paid' ? 'bg-success' : (sch.paid_amount > 0 ? 'bg-warning text-dark' : 'bg-secondary')}">
                            ${sch.status.toUpperCase()}
                          </span>
                        </td>
                        <td>${sch.paid_at ? escapeHtml(sch.paid_at.substring(0, 10)) : '—'}</td>
                      </tr>
                    `).join('')}
                  </tbody>
                </table>
              </div>
            </div>
          `;
        }

        modalBody.innerHTML = `

          <div class="d-flex justify-content-between align-items-center p-3 mb-3 rounded" style="background: #f7f1ea; border: 1px solid #e5dbcf;">
            <div>
              <span class="text-muted small text-uppercase fw-bold">Reservation Record</span>
              <div class="fs-5 fw-bold text-dark">${escapeHtml(b.customer_name || 'Reservation Details')}</div>
            </div>
            <div class="text-end">
              <span class="badge px-3 py-2" style="${bookingStatusClass} font-size: 0.82rem; font-weight: 600; border-radius: 999px;">
                Reservation: ${escapeHtml(b.status.toUpperCase())}
              </span>
              <span class="badge px-3 py-2 ms-1" style="${statusBadgeClass} font-size: 0.82rem; font-weight: 600; border-radius: 999px;">
                Payment: ${escapeHtml(b.payment_status)}
              </span>
            </div>
          </div>

          ${guestBanner}

          <div class="row g-3">

            <div class="col-md-6">
              <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: #fff;">
                <div class="card-body p-3">
                  <h6 class="fw-bold mb-3 pb-2 border-bottom" style="color: #7b1113; font-size: 0.9rem;">
                    <i class="fas fa-user me-1"></i> Client / Buyer Information
                  </h6>
                  <div class="mb-2">
                    <div class="text-muted small">Client / Buyer Name</div>
                    <div class="fw-bold text-dark">${escapeHtml(b.customer_name)}</div>
                  </div>
                  <div class="mb-2">
                    <div class="text-muted small">Contact Number</div>
                    <div class="text-dark font-monospace">${escapeHtml(b.contact_number)}</div>
                  </div>
                  <div class="mb-2">
                    <div class="text-muted small">Client Location / Address</div>
                    <div class="text-dark small">${escapeHtml(b.booker_location)}</div>
                  </div>
                  <div>
                    <div class="text-muted small">Email Address</div>
                    <div class="text-dark">${escapeHtml(b.email)}</div>
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
                    <div class="fw-bold text-dark">${escapeHtml(b.deceased_name || '—')}</div>
                  </div>
                  <div class="mb-2">
                    <div class="text-muted small">Grave Section & Plot</div>
                    <div class="fw-bold text-dark">Section ${escapeHtml(b.section)} • Plot ${escapeHtml(formatPlotNumber(b.plot, b.section))}</div>
                  </div>
                  <div class="mb-2">
                    <div class="text-muted small">Service / Purpose</div>
                    <div class="fw-bold text-dark">${escapeHtml(b.service_type || b.purpose)}</div>
                  </div>
                  <div class="mb-2">
                    <div class="text-muted small">Preferred Date</div>
                    <div class="text-dark">${escapeHtml(b.preferred_date)}</div>
                  </div>
                  <div>
                    <div class="text-muted small">Remarks / Notes</div>
                    <div class="text-dark small">${escapeHtml(b.remarks || b.special_requests || 'None')}</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-12">
              <div class="card border-0 shadow-sm" style="border-radius: 12px; background: #fff;">
                <div class="card-body p-3">
                  <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <h6 class="fw-bold mb-0" style="color: #7b1113; font-size: 0.95rem;">
                      <i class="fas fa-credit-card me-1"></i> Payment & Accounting Information
                    </h6>
                    <div class="d-flex align-items-center gap-2">
                      <span class="badge px-3 py-2" style="${statusBadgeClass}">${escapeHtml(b.payment_status)}</span>
                      ${b.raw_balance > 0 ? `
                        <button type="button" class="btn btn-sm btn-warning fw-bold shadow-sm" onclick="openRecordOfficePaymentModal(${b.id}, ${b.raw_balance}, '${escapeHtml(b.customer_name)}', '${escapeHtml(b.original_payment_method || b.payment_type || 'GCash')}')" style="border-radius: 8px; font-size: 0.82rem; padding: 6px 14px; background: #fbbf24; border: 1px solid #d97706; color: #78350f;">
                          <i class="fas fa-cash-register me-1"></i> Record Office Payment
                        </button>
                      ` : ''}
                    </div>
                  </div>

                  <div class="row g-2 mb-3">
                    <div class="col-sm-4">
                      <div class="p-2 rounded bg-light border">
                        <div class="text-muted small">Total Reservation Amount</div>
                        <div class="fs-6 fw-bold text-dark font-monospace">₱${b.total_amount}</div>
                      </div>
                    </div>
                    <div class="col-sm-4">
                      <div class="p-2 rounded bg-light border">
                        <div class="text-muted small">Total Amount Paid</div>
                        <div class="fs-6 fw-bold text-success font-monospace">₱${b.amount_paid}</div>
                      </div>
                    </div>
                    <div class="col-sm-4">
                      <div class="p-2 rounded bg-light border">
                        <div class="text-muted small">Remaining Balance</div>
                        <div class="fs-6 fw-bold ${b.raw_balance > 0 ? 'text-danger' : 'text-dark'} font-monospace">₱${b.balance}</div>
                      </div>
                    </div>
                  </div>

                  <div class="row g-3 mb-2">
                    <div class="col-sm-6">
                      <div class="text-muted small">Original Selected Payment Method</div>
                      <div class="fw-bold text-dark">
                        <i class="fas ${b.original_payment_method && b.original_payment_method.toLowerCase().includes('gcash') ? 'fa-mobile-alt text-primary' : 'fa-money-bill-wave text-success'} me-1"></i>
                        ${escapeHtml(b.original_payment_method || b.payment_type || 'GCash')}
                      </div>
                    </div>
                    <div class="col-sm-6">
                      <div class="text-muted small">Payment Plan</div>
                      <div class="fw-bold text-dark text-capitalize font-monospace">
                        ${escapeHtml(b.payment_plan || 'full')} ${b.payment_term ? '(' + b.payment_term + ' Months)' : ''}
                      </div>
                    </div>
                    <div class="col-sm-6">
                      <div class="text-muted small">Configured GCash Account</div>
                      <div class="fw-bold text-dark">${escapeHtml(b.gcash_account_name)} • <span class="font-monospace text-primary">${escapeHtml(b.gcash_number)}</span></div>
                    </div>
                    <div class="col-sm-6">
                      <div class="text-muted small">Latest Reference / OR No.</div>
                      <div class="fw-bold text-dark font-monospace bg-light px-2 py-1 rounded d-inline-block">${escapeHtml(b.reference_number)}</div>
                    </div>
                  </div>

                  <div class="mt-3 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <div class="fw-bold text-dark" style="font-size: 0.88rem;">
                        <i class="fas fa-history text-primary me-1"></i> Actual Payment History (${b.payment_history ? b.payment_history.length : 0})
                      </div>
                      ${b.raw_balance > 0 ? `
                        <button type="button" class="btn btn-outline-warning btn-sm fw-semibold" onclick="openRecordOfficePaymentModal(${b.id}, ${b.raw_balance}, '${escapeHtml(b.customer_name)}', '${escapeHtml(b.original_payment_method || b.payment_type || 'GCash')}')" style="font-size: 0.78rem; border-radius: 6px;">
                          <i class="fas fa-plus me-1"></i> Record Payment
                        </button>
                      ` : ''}
                    </div>
                    ${paymentHistoryHtml}
                  </div>

                  ${installmentScheduleHtml}

                  ${proofHtml}
                </div>
              </div>
            </div>
          </div>
        `;

        // Configure footer actions
        if (receiptBtn) {
          receiptBtn.style.display = 'inline-block';
           receiptBtn.onclick = function() {
            window.location.href = 'payment-success.php?booking_id=' + b.id;
          };
        }

        let buttonsHtml = '';
        if (b.raw_balance > 0) {
          buttonsHtml += `<button type="button" class="btn btn-warning btn-sm fw-bold text-dark" onclick="openRecordOfficePaymentModal(${b.id}, ${b.raw_balance}, '${escapeHtml(b.customer_name)}', '${escapeHtml(b.original_payment_method || b.payment_type || 'GCash')}')" style="border-radius: 8px; padding: 6px 14px; background: #fbbf24; border: 1px solid #d97706;"><i class="fas fa-cash-register me-1"></i> Record Office Payment</button>`;
        }
        if (b.status === 'pending') {
          const approveLabel = b.is_guest_booking ? '<i class="fas fa-paper-plane me-1"></i> Approve & Send Account Link' : '<i class="fas fa-check me-1"></i> Approve Booking';
          buttonsHtml += `<button type="button" class="btn btn-success btn-sm" onclick="approveBooking(${b.id})" style="border-radius: 8px; padding: 6px 14px;">${approveLabel}</button>`;
          buttonsHtml += `<button type="button" class="btn btn-danger btn-sm" onclick="rejectBooking(${b.id})" style="border-radius: 8px; padding: 6px 14px;"><i class="fas fa-times me-1"></i> Reject</button>`;
        }
        buttonsHtml += `<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal" style="border-radius: 8px; padding: 6px 14px;">Close</button>`;
        actionBtns.innerHTML = buttonsHtml;
      })
      .catch(err => {
        console.error(err);
        modalBody.innerHTML = '<div class="alert alert-danger mb-0">Network error while fetching booking details.</div>';
      });
    }

    // Modal and submission handler for Recording Office Payments (Cash / Office, Check, Bank Transfer, etc.)
    function openRecordOfficePaymentModal(bookingId, remainingBalance, customerName, originalMethod) {
      if (!bookingId) return;
      const remBal = parseFloat(remainingBalance) || 0;

      let modalEl = document.getElementById('recordOfficePaymentModal');
      if (!modalEl) {
        const modalHtml = `
          <div class="modal fade" id="recordOfficePaymentModal" tabindex="-1" aria-labelledby="recordOfficePaymentModalLabel" aria-hidden="true" style="z-index: 1080;">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content" style="border-radius: 1.25rem; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2); overflow: hidden;">
                <div class="modal-header" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%); color: #fff; padding: 1.1rem 1.4rem; border-bottom: none;">
                  <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-cash-register text-warning fs-5"></i>
                    <h5 class="modal-title fw-bold text-white m-0" id="recordOfficePaymentModalLabel" style="font-family: 'Inter', sans-serif;">
                      Record Office Payment
                    </h5>
                  </div>
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="recordOfficePaymentForm" onsubmit="handleRecordOfficePaymentSubmit(event)">
                  <div class="modal-body p-4" style="background: #fafafa;">
                    <input type="hidden" id="rop_booking_id" name="booking_id" value="">
                    <input type="hidden" id="rop_max_balance" value="0">

                    <div class="p-3 mb-3 rounded" style="background: #f7f1ea; border: 1px solid #e5dbcf;">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small text-muted text-uppercase fw-bold">Booking Reference</span>
                        <span class="font-monospace fw-bold text-dark" id="rop_booking_ref">#0</span>
                      </div>
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small text-muted">Customer Name:</span>
                        <strong class="text-dark" id="rop_customer_name">—</strong>
                      </div>
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small text-muted">Original Selected Method:</span>
                        <span class="badge bg-light text-dark border" id="rop_orig_method">GCash</span>
                      </div>
                      <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                        <span class="small fw-bold text-danger">Remaining Balance:</span>
                        <span class="fs-5 fw-bold text-danger font-monospace" id="rop_remaining_display">₱0.00</span>
                      </div>
                    </div>

                    <div class="mb-3">
                      <label class="form-label small fw-bold text-dark mb-1">Payment Method <span class="text-danger">*</span></label>
                      <select class="form-select form-select-sm" id="rop_payment_method" name="payment_method" required style="border-radius: 8px;">
                        <option value="Cash / Office" selected>Cash / Office</option>
                        <option value="Check">Check</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="POS / Card">POS / Card</option>
                      </select>
                      <div class="text-muted" style="font-size: 0.75rem;">Selected payment method for this specific transaction.</div>
                    </div>

                    <div class="mb-3">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-bold text-dark mb-0">Amount Paid (₱) <span class="text-danger">*</span></label>
                        <button type="button" class="btn btn-link p-0 text-decoration-none small text-primary fw-semibold" onclick="fillFullOfficePayment()">
                          Pay Full Balance
                        </button>
                      </div>
                      <div class="input-group input-group-sm">
                        <span class="input-group-text font-monospace fw-bold bg-white">₱</span>
                        <input type="number" step="0.01" min="0.01" class="form-control font-monospace fw-bold text-success fs-6" id="rop_amount" name="amount" placeholder="0.00" required oninput="validateOfficePaymentAmount()">
                      </div>
                      <div id="rop_overpay_warning" class="text-danger small mt-1 fw-bold" style="display: none;">
                        <i class="fas fa-exclamation-triangle me-1"></i> Payment exceeds the remaining balance.
                      </div>
                    </div>

                    <div class="row g-2 mb-3">
                      <div class="col-sm-6">
                        <label class="form-label small fw-bold text-dark mb-1">OR / Receipt Number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm font-monospace" id="rop_or_number" name="or_number" placeholder="e.g. OR-001" required style="border-radius: 8px;">
                      </div>
                      <div class="col-sm-6">
                        <label class="form-label small fw-bold text-dark mb-1">Reference No. (Optional)</label>
                        <input type="text" class="form-control form-control-sm font-monospace" id="rop_ref_number" name="reference_number" placeholder="Optional check/bank ref" style="border-radius: 8px;">
                      </div>
                    </div>

                    <div class="mb-3">
                      <label class="form-label small fw-bold text-dark mb-1">Payment Date & Time <span class="text-danger">*</span></label>
                      <input type="datetime-local" class="form-control form-control-sm" id="rop_payment_date" name="payment_date" required style="border-radius: 8px;">
                    </div>

                    <div class="mb-2">
                      <label class="form-label small fw-bold text-dark mb-1">Notes / Remarks</label>
                      <textarea class="form-control form-control-sm" id="rop_notes" name="notes" rows="2" placeholder="e.g. Paid in cash personally at office counter" style="border-radius: 8px;"></textarea>
                    </div>

                    <div class="small text-muted mt-2 d-flex align-items-center gap-1">
                      <i class="fas fa-user-shield text-secondary"></i>
                      <span>Recorded By: <strong>Current Admin/Staff account</strong> (logged automatically)</span>
                    </div>
                  </div>
                  <div class="modal-footer" style="background: #ffffff; border-top: 1px solid #eee; padding: 1rem 1.4rem;">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal" style="border-radius: 8px; padding: 6px 14px;">Cancel</button>
                    <button type="submit" id="rop_submit_btn" class="btn btn-primary btn-sm fw-bold" style="border-radius: 8px; padding: 6px 16px; background: #7b1113; border: none;">
                      <i class="fas fa-check-circle me-1"></i> Save & Record Payment
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        modalEl = document.getElementById('recordOfficePaymentModal');
      }

      document.getElementById('rop_booking_id').value = bookingId;
      document.getElementById('rop_max_balance').value = remBal;
      document.getElementById('rop_booking_ref').textContent = '#' + bookingId;
      document.getElementById('rop_customer_name').textContent = customerName || 'Customer';
      document.getElementById('rop_orig_method').textContent = originalMethod || 'GCash';
      document.getElementById('rop_remaining_display').textContent = '₱' + remBal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

      const amtInput = document.getElementById('rop_amount');
      amtInput.value = remBal > 0 ? remBal.toFixed(2) : '';
      amtInput.max = remBal.toFixed(2);

      const warnEl = document.getElementById('rop_overpay_warning');
      if (warnEl) warnEl.style.display = 'none';

      // Pre-fill current local timestamp for payment date
      const now = new Date();
      now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
      const nowIso = now.toISOString().slice(0, 16);
      document.getElementById('rop_payment_date').value = nowIso;

      document.getElementById('rop_or_number').value = '';
      document.getElementById('rop_ref_number').value = '';
      document.getElementById('rop_notes').value = '';

      const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
      bsModal.show();
    }

    function fillFullOfficePayment() {
      const maxBal = parseFloat(document.getElementById('rop_max_balance').value) || 0;
      const amtInput = document.getElementById('rop_amount');
      if (amtInput && maxBal > 0) {
        amtInput.value = maxBal.toFixed(2);
        validateOfficePaymentAmount();
      }
    }

    function validateOfficePaymentAmount() {
      const maxBal = parseFloat(document.getElementById('rop_max_balance').value) || 0;
      const amtInput = document.getElementById('rop_amount');
      const val = parseFloat(amtInput ? amtInput.value : 0) || 0;
      const warnEl = document.getElementById('rop_overpay_warning');
      const submitBtn = document.getElementById('rop_submit_btn');

      if (val > (maxBal + 0.009)) {
        if (warnEl) {
          warnEl.textContent = 'Payment exceeds the remaining balance of ₱' + maxBal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '.';
          warnEl.style.display = 'block';
        }
        if (submitBtn) submitBtn.disabled = true;
        return false;
      } else {
        if (warnEl) warnEl.style.display = 'none';
        if (submitBtn) submitBtn.disabled = false;
        return true;
      }
    }

    function handleRecordOfficePaymentSubmit(e) {
      e.preventDefault();
      if (!validateOfficePaymentAmount()) {
        return;
      }

      const form = document.getElementById('recordOfficePaymentForm');
      const submitBtn = document.getElementById('rop_submit_btn');
      const bookingId = document.getElementById('rop_booking_id').value;

      if (!bookingId) {
        alert('Invalid booking reference.');
        return;
      }

      const fd = new FormData(form);
      fd.append('action', 'record_office_payment');

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Recording...';
      }

      fetch('dashboard.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      })
      .then(r => r.json())
      .then(data => {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Save & Record Payment';
        }

        if (data.success) {
          const modalEl = document.getElementById('recordOfficePaymentModal');
          if (modalEl) {
            const bs = bootstrap.Modal.getInstance(modalEl);
            if (bs) bs.hide();
          }

          if (typeof Swal !== 'undefined') {
            Swal.fire({
              icon: 'success',
              title: 'Office Payment Recorded!',
              html: `<strong>OR Number:</strong> ${escapeHtml(data.or_number || '')}<br>` +
                    `<strong>Amount Paid:</strong> ₱${Number(data.amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}<br>` +
                    `<strong>Remaining Balance:</strong> ₱${Number(data.new_balance || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}<br>` +
                    `<strong>Payment Status:</strong> <span class="badge bg-success">${escapeHtml(data.payment_status || 'PAID')}</span>`,
              confirmButtonColor: '#7b1113'
            });
          } else {
            alert('Office payment recorded successfully! OR: ' + (data.or_number || ''));
          }

          // Refresh the booking details modal to immediately display the new payment transaction and recalculations
          viewBookingDetails(bookingId);
        } else {
          if (typeof Swal !== 'undefined') {
            Swal.fire({
              icon: 'error',
              title: 'Payment Recording Failed',
              text: data.message || 'Unable to record payment.',
              confirmButtonColor: '#7b1113'
            });
          } else {
            alert('Error: ' + (data.message || 'Unable to record payment.'));
          }
        }
      })
      .catch(err => {
        console.error(err);
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Save & Record Payment';
        }
        alert('Network error while recording office payment.');
      });
    }

    // Admin Payment Proof Modal with User Dashboard Theme
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
                  <h5 class="modal-title fw-bold text-white m-0" style="font-family: 'Inter', sans-serif;">
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

    // Remains Transfer Modal Functionality
    document.addEventListener('DOMContentLoaded', function() {
      // Transfer destination type toggle
      const destTypeSelect = document.getElementById('trans_destination_type');
      const plotFields = document.getElementById('trans_plot_fields_wrapper');
      const detailsField = document.getElementById('trans_details_wrapper');

      if (destTypeSelect) {
        destTypeSelect.addEventListener('change', function() {
          if (this.value === 'NEW_PLOT') {
            if (plotFields) plotFields.style.display = 'block';
            if (detailsField) detailsField.style.display = 'none';
          } else {
            if (plotFields) plotFields.style.display = 'none';
            if (detailsField) detailsField.style.display = 'block';
          }
        });
      }

      // Transfer buttons click handler
      document.querySelectorAll('.transfer-remains-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
          const deceasedId = this.getAttribute('data-deceased-id') || '';
          const fullName = this.getAttribute('data-full-name') || '';
          const section = this.getAttribute('data-section') || '';
          const plotX = this.getAttribute('data-plot-x') || '';
          const plotY = this.getAttribute('data-plot-y') || '';
          const bday = this.getAttribute('data-bday') || '';
          const dateDied = this.getAttribute('data-date-died') || '';
          const intermentDate = this.getAttribute('data-interment-date') || '';
          const status = this.getAttribute('data-status') || '';
          const interval = this.getAttribute('data-interval') || '';
          const message = this.getAttribute('data-message') || '';
          const isTransferred = this.getAttribute('data-is-transferred') === '1';

          document.getElementById('trans_deceased_id').value = deceasedId;
          document.getElementById('trans_deceased_name').textContent = fullName;
          document.getElementById('trans_current_plot').textContent = 'Section ' + section + ' Plot ' + plotX + '-' + plotY;
          document.getElementById('trans_bday').textContent = bday || 'N/A';
          document.getElementById('trans_date_died').textContent = dateDied || 'N/A';
          document.getElementById('trans_interment_date').textContent = intermentDate || 'N/A';

          const badge = document.getElementById('trans_eligibility_badge');
          const banner = document.getElementById('trans_eligibility_banner');
          const msgEl = document.getElementById('trans_eligibility_message');

          if (badge) {
            badge.textContent = status + (interval ? ' (' + interval + ')' : '');
            if (status === 'ELIGIBLE FOR TRANSFER') {
              badge.style.backgroundColor = '#15803d';
              badge.style.color = '#fff';
              banner.style.backgroundColor = '#f0fdf4';
              banner.style.borderColor = '#bbf7d0';
            } else if (status === 'FOR ADMIN REVIEW') {
              badge.style.backgroundColor = '#b91c1c';
              badge.style.color = '#fff';
              banner.style.backgroundColor = '#fef2f2';
              banner.style.borderColor = '#fecaca';
            } else if (status === 'TRANSFERRED') {
              badge.style.backgroundColor = '#64748b';
              badge.style.color = '#fff';
              banner.style.backgroundColor = '#f8fafc';
              banner.style.borderColor = '#cbd5e1';
            } else {
              badge.style.backgroundColor = '#d97706';
              badge.style.color = '#fff';
              banner.style.backgroundColor = '#fffbeb';
              banner.style.borderColor = '#fde68a';
            }
          }
          if (msgEl) msgEl.textContent = message;

          // Reset inputs and destination plot
          document.getElementById('trans_confirm_check').checked = false;
          document.getElementById('trans_reason').value = '';
          document.getElementById('trans_dest_section').value = '';
          document.getElementById('trans_dest_plot_x').value = '';
          document.getElementById('trans_dest_plot_y').value = '';
          var sCard = document.getElementById('trans_selected_plot_card');
          var nBox = document.getElementById('trans_no_plot_chosen');
          if (sCard) sCard.style.display = 'none';
          if (nBox) nBox.style.display = 'block';

          const alertBox = document.getElementById('trans_alert_box');
          if (alertBox) {
            alertBox.style.display = 'none';
            alertBox.innerHTML = '';
          }

          const transModal = new bootstrap.Modal(document.getElementById('transferRemainsModal'));
          transModal.show();
        });
      });

      // Transfer Destination Map trigger buttons
      var btnOpenTransMap = document.getElementById('btnAdminOpenTransferMap');
      if (btnOpenTransMap) {
        btnOpenTransMap.addEventListener('click', function() {
          openTransferDestinationMap();
        });
      }
      var btnChangeTransMap = document.getElementById('btnAdminChangeTransferPlot');
      if (btnChangeTransMap) {
        btnChangeTransMap.addEventListener('click', function() {
          openTransferDestinationMap();
        });
      }
      var btnClearTransMap = document.getElementById('btnAdminClearTransferPlot');
      if (btnClearTransMap) {
        btnClearTransMap.addEventListener('click', function() {
          document.getElementById('trans_dest_section').value = '';
          document.getElementById('trans_dest_plot_x').value = '';
          document.getElementById('trans_dest_plot_y').value = '';
          var sCard = document.getElementById('trans_selected_plot_card');
          var nBox = document.getElementById('trans_no_plot_chosen');
          if (sCard) sCard.style.display = 'none';
          if (nBox) nBox.style.display = 'block';
        });
      }

      // Transfer Form Submission via AJAX
      const transForm = document.getElementById('transferRemainsForm');
      if (transForm) {
        transForm.addEventListener('submit', function(e) {
          e.preventDefault();

          const alertBox = document.getElementById('trans_alert_box');
          const destType = document.getElementById('trans_destination_type').value;
          if (destType === 'NEW_PLOT') {
            const sec = document.getElementById('trans_dest_section').value;
            const px = document.getElementById('trans_dest_plot_x').value;
            const py = document.getElementById('trans_dest_plot_y').value;
            if (!sec || !px || !py) {
              if (alertBox) {
                alertBox.style.display = 'block';
                alertBox.className = 'alert alert-warning mt-3';
                alertBox.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> Please choose an available destination plot from the cemetery map.';
              }
              openTransferDestinationMap();
              return false;
            }
          }

          const btnSubmit = document.getElementById('btnSubmitTransfer');
          const originalBtnHtml = btnSubmit.innerHTML;

          btnSubmit.disabled = true;
          btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';

          const formData = new FormData(transForm);

          fetch('dashboard.php', {
            method: 'POST',
            body: formData
          })
          .then(res => res.json())
          .then(data => {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = originalBtnHtml;

            if (data.success) {
              if (alertBox) {
                alertBox.style.display = 'block';
                alertBox.className = 'alert alert-success mt-3';
                alertBox.innerHTML = '<i class="fas fa-check-circle me-1"></i> ' + escapeHtml(data.message);
              }
              setTimeout(function() {
                window.location.reload();
              }, 1200);
            } else {
              if (alertBox) {
                alertBox.style.display = 'block';
                alertBox.className = 'alert alert-danger mt-3';
                alertBox.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> ' + escapeHtml(data.message || 'Transfer failed.');
              }
            }
          })
          .catch(err => {
            console.error(err);
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = originalBtnHtml;
            if (alertBox) {
              alertBox.style.display = 'block';
              alertBox.className = 'alert alert-danger mt-3';
              alertBox.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i> Network error occurred.';
            }
          });
        });
      }

      // View History button in transfer modal
      const btnHist = document.getElementById('btnOpenHistoryFromTransfer');
      if (btnHist) {
        btnHist.addEventListener('click', function() {
          const deceasedId = document.getElementById('trans_deceased_id').value;
          openTransferHistoryModal(deceasedId);
        });
      }
    });

    window.openTransferDestinationMap = function(defaultSection) {
      window.__openedFromTransferModal = true;
      window.__openedFromBookingModal = false;
      var sec = defaultSection || document.getElementById('trans_dest_section').value || 'A';
      var iframe = document.getElementById('popupSectionIframe');
      var popupModalEl = document.getElementById('popupSectionModal');
      var transModalEl = document.getElementById('transferRemainsModal');

      if (typeof window.setPopupModalMode === 'function') window.setPopupModalMode('booking');
      var adminSectionSel = document.getElementById('adminPopupSectionSelect');
      if (adminSectionSel) adminSectionSel.value = sec;

      var transDecId = document.getElementById('trans_deceased_id') ? document.getElementById('trans_deceased_id').value : '';
      if (iframe) {
        iframe.src = 'sections/popup.php?selection_mode=transfer&transfer_mode=1&section=' + encodeURIComponent(sec) + (transDecId ? '&deceased_id=' + encodeURIComponent(transDecId) : '') + '&source=admin&is_admin=1&t=' + Date.now();
      }

      // Hide transfer modal temporarily so backdrops don't collide
      if (transModalEl && typeof bootstrap !== 'undefined') {
        var tModal = bootstrap.Modal.getInstance(transModalEl);
        if (tModal) tModal.hide();
      }

      setTimeout(function() {
        if (popupModalEl && typeof bootstrap !== 'undefined') {
          var pModal = bootstrap.Modal.getOrCreateInstance(popupModalEl);
          pModal.show();
        }
      }, 250);
    };

    window.applySelectedPlotToTransfer = function(data) {
      if (!data) return;
      var sec = (data.section || '').toUpperCase();
      var px = parseInt(data.plotX || data.plot_x, 10);
      var py = parseInt(data.plotY || data.plot_y, 10);
      if (!sec || isNaN(px) || isNaN(py)) return;

      var st = (data.status || '').toUpperCase();
      var isAuth = Boolean(data.isAuthorized === true || data.is_plot_owned_by_customer === true);
      if (st && st !== 'AVAILABLE' && !isAuth) {
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'error',
            title: 'Plot Not Allowed for Transfer',
            text: 'You cannot select Section ' + sec + ' Plot ' + px + '-' + py + '. It is occupied or reserved and does not belong to this customer\'s account. Plot transfers can only be placed in available (vacant) plots or plots owned by the registered customer account.',
            confirmButtonColor: '#7b1113'
          });
        } else {
          alert('You cannot select Section ' + sec + ' Plot ' + px + '-' + py + '. Plot transfers can only be placed in available (vacant) plots or plots owned by the registered customer account.');
        }
        return;
      }

      var lawnType = data.lawnType || data.lawn_type || 'Regular';
      var secIn = document.getElementById('trans_dest_section');
      var xIn = document.getElementById('trans_dest_plot_x');
      var yIn = document.getElementById('trans_dest_plot_y');
      if (secIn) secIn.value = sec;
      if (xIn) xIn.value = px;
      if (yIn) yIn.value = py;

      var noPlotBox = document.getElementById('trans_no_plot_chosen');
      var card = document.getElementById('trans_selected_plot_card');
      var title = document.getElementById('trans_selected_plot_title');
      var details = document.getElementById('trans_selected_plot_details');

      if (title) title.innerHTML = 'Section ' + sec + ' &bull; Plot ' + px + '-' + py;
      if (details) details.innerHTML = lawnType + ' &bull; Verified Selected Destination';
      if (noPlotBox) noPlotBox.style.display = 'none';
      if (card) card.style.display = 'block';

      // Hide popupSectionModal and reopen transferRemainsModal
      var popupModalEl = document.getElementById('popupSectionModal');
      var transModalEl = document.getElementById('transferRemainsModal');

      window.__openedFromTransferModal = false;
      if (popupModalEl && typeof bootstrap !== 'undefined') {
        var pModal = bootstrap.Modal.getInstance(popupModalEl);
        if (pModal) pModal.hide();
      }

      setTimeout(function() {
        if (transModalEl && typeof bootstrap !== 'undefined') {
          var tModal = bootstrap.Modal.getOrCreateInstance(transModalEl);
          tModal.show();
        }
      }, 250);
    };

    window.addEventListener('message', function(event) {
      var data = event.data;
      if (typeof data === 'string') {
        try { data = JSON.parse(data); } catch(e) {}
      }
      if (data && (data.type === 'transferPlotSelected' || (data.type === 'bookingPlotSelected' && window.__openedFromTransferModal))) {
        if (typeof window.applySelectedPlotToTransfer === 'function') {
          window.applySelectedPlotToTransfer(data);
        }
      }
    });

    // Helper: Open Transfer History Modal
    function openTransferHistoryModal(deceasedId) {
      const histModal = new bootstrap.Modal(document.getElementById('viewTransferHistoryModal'));
      histModal.show();

      const container = document.getElementById('transferHistoryContent');
      container.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary mb-2"></div><div>Loading transfer logs...</div></div>';

      const fd = new FormData();
      fd.append('action', 'get_deceased_transfers');
      if (deceasedId) fd.append('deceased_id', deceasedId);

      fetch('dashboard.php', {
        method: 'POST',
        body: fd
      })
      .then(res => res.json())
      .then(data => {
        if (data.success && data.transfers && data.transfers.length > 0) {
          let html = `
            <div class="table-responsive bg-white rounded shadow-sm p-2">
              <table class="table table-hover table-sm align-middle mb-0" style="font-size: 0.85rem;">
                <thead class="table-light">
                  <tr>
                    <th>Date</th>
                    <th>Deceased</th>
                    <th>From Plot</th>
                    <th>Destination</th>
                    <th>Eligibility At Transfer</th>
                    <th>Reason</th>
                    <th>Recorded By</th>
                  </tr>
                </thead>
                <tbody>
          `;
          data.transfers.forEach(t => {
            const dest = t.destination_type === 'NEW_PLOT' 
              ? `Section ${escapeHtml(t.destination_section)} ${escapeHtml(t.destination_plot_x)}-${escapeHtml(t.destination_plot_y)}`
              : (escapeHtml(t.destination_type) + (t.destination_details ? ` (${escapeHtml(t.destination_details)})` : ''));

            html += `
              <tr>
                <td class="fw-semibold">${escapeHtml(t.transfer_date)}</td>
                <td>${escapeHtml(t.deceased_name)}</td>
                <td>Section ${escapeHtml(t.original_section)} ${escapeHtml(t.original_plot_x)}-${escapeHtml(t.original_plot_y)}</td>
                <td class="text-primary fw-semibold">${dest}</td>
                <td><span class="badge bg-secondary">${escapeHtml(t.eligibility_at_transfer || 'N/A')}</span></td>
                <td class="small">${escapeHtml(t.reason || '—')}</td>
                <td class="small text-muted">${escapeHtml(t.recorded_by_name || 'Admin')}</td>
              </tr>
            `;
          });
          html += '</tbody></table></div>';
          container.innerHTML = html;
        } else {
          container.innerHTML = '<div class="alert alert-info mb-0 text-center py-3"><i class="fas fa-info-circle me-1"></i> No transfer records found for this deceased person.</div>';
        }
      })
      .catch(err => {
        console.error(err);
        container.innerHTML = '<div class="alert alert-danger mb-0">Failed to load transfer history.</div>';
      });
    }

    function escapeHtml(value) {
      return String(value || '').replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
      });
    }
  </script>

  <div class="modal fade" id="transferRemainsModal" tabindex="-1" aria-labelledby="transferRemainsModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content" style="border-radius: 1.25rem; border: none; box-shadow: 0 12px 40px rgba(0,0,0,0.2); overflow: hidden;">
        <div class="modal-header" style="background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%); color: #fff; padding: 1.1rem 1.5rem; border-bottom: none;">
          <div class="d-flex align-items-center gap-2">
            <span style="font-size: 1.3rem;">⚰️</span>
            <div>
              <h5 class="modal-title fw-bold text-white m-0" id="transferRemainsModalLabel" style="font-size: 1.15rem;">
                Record Deceased Remains Transfer
              </h5>
              <div class="small text-white-50" style="font-size: 0.8rem;">8–10 Year Remains Transfer Eligibility & History</div>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <form id="transferRemainsForm" method="POST">
          <input type="hidden" name="action" value="record_deceased_transfer">
          <input type="hidden" id="trans_deceased_id" name="deceased_id" value="">

          <div class="modal-body p-4" style="background: #f8fafc;">

            <div class="card border-0 shadow-sm mb-3" style="border-radius: 12px; background: #ffffff;">
              <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2 pb-2 border-bottom">
                  <div>
                    <span class="text-muted small text-uppercase fw-semibold" style="letter-spacing: 0.5px;">Deceased Person</span>
                    <h5 class="fw-bold text-dark mb-0" id="trans_deceased_name">—</h5>
                  </div>
                  <div class="text-end">
                    <span class="text-muted small d-block">Current Plot</span>
                    <span class="badge bg-primary fs-6 px-2 py-1" id="trans_current_plot">—</span>
                  </div>
                </div>

                <div class="row g-2 small">
                  <div class="col-sm-4">
                    <span class="text-muted">Birth Date:</span>
                    <div class="fw-semibold text-dark" id="trans_bday">—</div>
                  </div>
                  <div class="col-sm-4">
                    <span class="text-muted">Date Died:</span>
                    <div class="fw-semibold text-dark" id="trans_date_died">—</div>
                  </div>
                  <div class="col-sm-4">
                    <span class="text-muted">Date Interred / Buried:</span>
                    <div class="fw-semibold text-dark" id="trans_interment_date">—</div>
                  </div>
                </div>

                <div class="mt-3 p-3 rounded-3" id="trans_eligibility_banner" style="background: #f1f5f9; border: 1px solid #cbd5e1;">
                  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
                    <span class="fw-bold text-dark" style="font-size: 0.88rem;">Remains Transfer Eligibility:</span>
                    <span class="badge" id="trans_eligibility_badge" style="font-size: 0.82rem;">EVALUATING</span>
                  </div>
                  <div class="small text-muted" id="trans_eligibility_message">
                    Standard policy rule requires 8–10 years resting interval before transfer.
                  </div>
                </div>
              </div>
            </div>

            <div class="card border-0 shadow-sm" style="border-radius: 12px; background: #ffffff;">
              <div class="card-body p-3">
                <h6 class="fw-bold mb-3 pb-2 border-bottom" style="color: #7b1113; font-size: 0.92rem;">
                  <i class="fas fa-route me-1"></i> Transfer Movement Details
                </h6>

                <div class="row g-3">
                  <div class="col-md-6">
                    <label for="trans_transfer_date" class="form-label small fw-semibold">Transfer / Movement Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control form-control-sm" id="trans_transfer_date" name="transfer_date" required value="<?php echo date('Y-m-d'); ?>">
                  </div>

                  <div class="col-md-6">
                    <label for="trans_destination_type" class="form-label small fw-semibold">Destination Type <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" id="trans_destination_type" name="destination_type" required>
                      <option value="NEW_PLOT">Transfer to New Cemetery Plot</option>
                    </select>
                  </div>

                  <div id="trans_plot_fields_wrapper" class="col-12">
                    <div class="p-3 rounded bg-light border">
                      <label class="form-label small fw-semibold d-block mb-2">
                        <i class="fas fa-map-marker-alt text-danger me-1"></i> New Grave Plot Location <span class="text-danger">*</span>
                      </label>

                      <!-- Hidden fields populated by Map Selection -->
                      <input type="hidden" id="trans_dest_section" name="destination_section" value="">
                      <input type="hidden" id="trans_dest_plot_x" name="destination_plot_x" value="">
                      <input type="hidden" id="trans_dest_plot_y" name="destination_plot_y" value="">

                      <!-- Prompt / Trigger Button -->
                      <div id="trans_no_plot_chosen">
                        <button type="button" class="btn btn-outline-danger btn-sm fw-bold px-3 py-2 d-inline-flex align-items-center gap-2" id="btnAdminOpenTransferMap" style="border-radius: 8px;">
                          <i class="fas fa-map-marked-alt text-danger me-1"></i> Choose Destination Plot from Map
                        </button>
                        <div class="text-muted small mt-1.5" style="font-size: 0.8rem;">
                          Click to select an available vacant plot or registered plot directly on the cemetery map.
                        </div>
                      </div>

                      <!-- Selected Plot Card -->
                      <div id="trans_selected_plot_card" class="p-2.5 rounded-3 border" style="background: #fff8f5; border-color: #ecd9c6 !important; display: none;">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                          <div>
                            <span class="badge mb-1 px-2 py-0.5 small" style="background: #7b1113; color: #fff;">
                              <i class="fas fa-check-circle me-1"></i> Selected on Map
                            </span>
                            <div class="fw-bold text-dark small" id="trans_selected_plot_title">Section A &bull; Plot 1-1</div>
                            <div class="text-muted" style="font-size: 0.78rem;" id="trans_selected_plot_details">Lawn (Regular) &bull; Selected Destination Plot</div>
                          </div>
                          <div class="d-flex gap-1.5">
                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" id="btnAdminChangeTransferPlot" style="font-size: 0.8rem; border-radius: 6px;">
                              <i class="fas fa-redo me-1"></i> Change
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="btnAdminClearTransferPlot" style="font-size: 0.8rem; border-radius: 6px;">
                              <i class="fas fa-times me-1"></i> Clear
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div id="trans_details_wrapper" class="col-12" style="display: none;">
                    <label for="trans_destination_details" class="form-label small fw-semibold">Destination Facility / Details</label>
                    <input type="text" class="form-control form-control-sm" id="trans_destination_details" name="destination_details" placeholder="Enter columbarium niche number, receiving memorial park, or cemetery name">
                  </div>

                  <div class="col-12">
                    <label for="trans_reason" class="form-label small fw-semibold">Reason for Transfer / Administrative Remarks <span class="text-danger">*</span></label>
                    <textarea class="form-control form-control-sm" id="trans_reason" name="reason" rows="2" placeholder="State the reason (e.g. Family request after 8+ years resting interval, plot consolidation, exhumation permit #...)" required></textarea>
                  </div>

                  <div class="col-12">
                    <div class="form-check small p-2 rounded bg-light border">
                      <input class="form-check-input ms-0 me-2" type="checkbox" id="trans_confirm_check" required>
                      <label class="form-check-label text-dark fw-semibold" for="trans_confirm_check">
                        I confirm that administrator authorization and necessary permits are verified to record this remains transfer.
                      </label>
                    </div>
                  </div>
                </div>

                <div id="trans_alert_box" class="mt-3" style="display: none;"></div>
              </div>
            </div>
          </div>

          <div class="modal-footer d-flex justify-content-between align-items-center" style="background: #ffffff; border-top: 1px solid #e2e8f0; padding: 0.9rem 1.5rem;">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnOpenHistoryFromTransfer" style="border-radius: 8px;">
              <i class="fas fa-history me-1"></i> View Past History
            </button>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px; padding: 6px 14px;">Cancel</button>
              <button type="submit" class="btn btn-sm btn-primary" id="btnSubmitTransfer" style="background: #7b1113; border: none; border-radius: 8px; padding: 6px 16px; font-weight: 600;">
                <i class="fas fa-check-circle me-1"></i> Record Transfer
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="viewTransferHistoryModal" tabindex="-1" aria-labelledby="viewTransferHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content" style="border-radius: 1.25rem; border: none; box-shadow: 0 12px 40px rgba(0,0,0,0.2); overflow: hidden;">
        <div class="modal-header" style="background: #1e293b; color: #fff; padding: 1.1rem 1.5rem; border-bottom: none;">
          <div class="d-flex align-items-center gap-2">
            <span style="font-size: 1.25rem;">📜</span>
            <h5 class="modal-title fw-bold text-white m-0" id="viewTransferHistoryModalLabel">
              Remains Transfer History Log
            </h5>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-3" style="background: #f8fafc; max-height: 70vh; overflow-y: auto;">
          <div id="transferHistoryContent">
            <div class="text-center py-4 text-muted">
              <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
              <div>Loading transfer history logs...</div>
            </div>
          </div>
        </div>
        <div class="modal-footer" style="background: #ffffff; border-top: 1px solid #e2e8f0; padding: 0.8rem 1.5rem;">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px;">Close</button>
        </div>
      </div>
    </div>
  </div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.location.hash === '#plot-claims-section' || window.location.search.indexOf('open=claims') !== -1) {
        window.location.href = 'claims_review.php';
    }

    document.querySelectorAll('a[href*="#plot-claims-section"]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            window.location.href = 'claims_review.php';
        });
    });

    // Global handler for all GCash payment links on Admin Dashboard
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
                    '<p class="text-muted mb-3" style="font-size: 0.92rem;">Transferring to GCash payment checkout...</p>' +
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

    // --- booking_pay_return: Return from GCash gateway for admin booking ---
    // When admin completes payment in gcash_gateway.php, they are redirected back here
    // with booking_pay_return=1. We open the result in a clean Swal confirmation modal
    // showing the payment was successful and allowing the admin to confirm/view the booking.
    (function() {
        var urlP = new URLSearchParams(window.location.search);
        if (urlP.get('booking_pay_return') !== '1') return;
        var bkId        = urlP.get('booking_id')    || '';
        var payRef      = urlP.get('payment_ref')   || '';
        var payAmount   = urlP.get('payment_amount') || '';
        var payDate     = urlP.get('payment_date')   || '';
        var payStatus   = urlP.get('payment_status') || '';
        if (!bkId) return;

        // Clean the URL so a refresh doesn't re-trigger
        try { history.replaceState({}, '', window.location.pathname); } catch(e) {}

        if (payStatus === 'cancelled' || payStatus !== 'completed' || !payRef) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: '<span style="color:#856404;">Payment Not Completed</span>',
                    html:
                        '<div style="text-align:left;font-size:0.95rem;line-height:1.7;">' +
                        '<div class="alert alert-warning d-flex align-items-center py-2 px-3 mb-3 border-0" style="background:#fff3cd;border-radius:8px;">' +
                            '<i class="fas fa-exclamation-triangle me-2 fs-5 text-warning"></i>' +
                            '<div>GCash payment was not completed for <strong>Booking #' + escapeHtml(bkId) + '</strong>.</div>' +
                        '</div>' +
                        '<div class="p-3 bg-light rounded border text-muted small">' +
                            'The booking record is saved with status <span class="badge bg-warning text-dark">Pending Payment</span>. The client or admin can settle payment later via GCash or at the office counter.' +
                        '</div>' +
                        '</div>',
                    confirmButtonColor: '#7b1113',
                    confirmButtonText: 'Understood'
                });
            }
            return;
        }

        // Show a prominent success notification ONLY IF COMPLETED
        var amtDisplay = payAmount ? ('₱' + parseFloat(payAmount).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})) : '(amount on record)';
        var refDisplay = payRef || '—';
        var dateDisplay = payDate || new Date().toLocaleString();

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'success',
                title: '<span style="color:#155724;">GCash Payment Received!</span>',
                html:
                    '<div style="text-align:left;font-size:0.95rem;line-height:1.7;">' +
                    '<div class="alert alert-success d-flex align-items-center py-2 px-3 mb-3 border-0" style="background:#d1e7dd;border-radius:8px;">' +
                        '<i class="fas fa-check-circle me-2 fs-5 text-success"></i>' +
                        '<div><strong>Booking #' + escapeHtml(bkId) + '</strong> — payment submitted successfully via GCash and is <strong>pending admin verification</strong>.</div>' +
                    '</div>' +
                    '<div style="background:#f0f7ff;border:1px solid #bcdbfc;border-radius:10px;padding:14px 16px;">' +
                        '<div class="d-flex justify-content-between mb-1"><span class="text-muted">GCash Reference:</span><strong class="font-monospace text-primary">' + escapeHtml(refDisplay) + '</strong></div>' +
                        '<div class="d-flex justify-content-between mb-1"><span class="text-muted">Amount Submitted:</span><strong class="text-success">' + escapeHtml(amtDisplay) + '</strong></div>' +
                        '<div class="d-flex justify-content-between"><span class="text-muted">Payment Date:</span><span>' + escapeHtml(dateDisplay) + '</span></div>' +
                    '</div>' +
                    '<div class="mt-3 small text-muted"><i class="fas fa-info-circle text-primary me-1"></i>Once verified by admin, the booking status will be updated to <strong>PAID</strong> in the records.</div>' +
                    '</div>',
                confirmButtonColor: '#7b1113',
                confirmButtonText: '<i class="fas fa-list me-2"></i>View All Bookings',
                showCancelButton: true,
                cancelButtonText: 'Close',
                cancelButtonColor: '#6c757d',
                allowOutsideClick: true,
                customClass: { popup: 'shadow-lg' }
            }).then(function(res) {
                if (res.isConfirmed) {
                    // Scroll to the bookings table / reservations section
                    var tableSection = document.querySelector('.reservations-table, #reservations, .table-responsive, table');
                    if (tableSection) tableSection.scrollIntoView({ behavior: 'smooth' });
                }
            });
        } else {
            alert('GCash payment received for Booking #' + bkId + '!\nReference: ' + refDisplay + '\nAmount: ' + amtDisplay + '\n\nPayment is pending admin verification.');
        }
    })();
});
</script>
<script>
// ==========================================
// ADMIN DASHBOARD DATA ANALYTICS CHARTS (Chart.js)
// ==========================================
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart === 'undefined') {
        console.warn('Chart.js not loaded');
        return;
    }

    // Chart 1: Section Occupancy & Plot Capacity
    var ctxSec = document.getElementById('chartSectionOccupancy');
    if (ctxSec) {
        new Chart(ctxSec.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($dash_chart_sections['labels']); ?>,
                datasets: [
                    {
                        label: 'Available',
                        data: <?php echo json_encode($dash_chart_sections['available']); ?>,
                        backgroundColor: '#10b981',
                        borderRadius: 6,
                        maxBarThickness: 32
                    },
                    {
                        label: 'Occupied',
                        data: <?php echo json_encode($dash_chart_sections['occupied']); ?>,
                        backgroundColor: '#7b1113',
                        borderRadius: 6,
                        maxBarThickness: 32
                    },
                    {
                        label: 'Reserved',
                        data: <?php echo json_encode($dash_chart_sections['reserved']); ?>,
                        backgroundColor: '#f59e0b',
                        borderRadius: 6,
                        maxBarThickness: 32
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            font: { family: "'Inter', sans-serif", size: 12, weight: '600' }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            afterLabel: function(context) {
                                var dataset = context.dataset;
                                var total = 0;
                                for (var i = 0; i < context.chart.data.datasets.length; i++) {
                                    total += Number(context.chart.data.datasets[i].data[context.dataIndex] || 0);
                                }
                                var pct = total > 0 ? Math.round((context.parsed.y / total) * 100) : 0;
                                return 'Share: ' + pct + '% of section total';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: false,
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 20 },
                        grid: { color: '#f1f5f9' }
                    }
                }
            }
        });
    }

    // Chart 2: Plot Status Distribution (Doughnut)
    var ctxPlot = document.getElementById('chartPlotStatus');
    if (ctxPlot) {
        new Chart(ctxPlot.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Available', 'Occupied', 'Reserved'],
                datasets: [{
                    data: [
                        <?php echo (int)$dash_kpi_plots['available']; ?>,
                        <?php echo (int)$dash_kpi_plots['occupied']; ?>,
                        <?php echo (int)$dash_kpi_plots['reserved']; ?>
                    ],
                    backgroundColor: ['#10b981', '#7b1113', '#f59e0b'],
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = <?php echo (int)$dash_kpi_plots['total']; ?>;
                                var val = ctx.parsed;
                                var pct = total > 0 ? Math.round((val / total) * 100) : 0;
                                return ' ' + ctx.label + ': ' + val.toLocaleString() + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // Chart 3: Monthly Booking & Revenue Trends
    var ctxMo = document.getElementById('chartMonthlyTrend');
    if (ctxMo) {
        var gradRev = ctxMo.getContext('2d').createLinearGradient(0, 0, 0, 260);
        gradRev.addColorStop(0, 'rgba(123, 17, 19, 0.22)');
        gradRev.addColorStop(1, 'rgba(123, 17, 19, 0.01)');

        new Chart(ctxMo.getContext('2d'), {
            data: {
                labels: <?php echo json_encode($dash_chart_monthly['labels']); ?>,
                datasets: [
                    {
                        type: 'line',
                        label: 'Collections (₱)',
                        data: <?php echo json_encode($dash_chart_monthly['revenue']); ?>,
                        borderColor: '#7b1113',
                        backgroundColor: gradRev,
                        fill: true,
                        tension: 0.35,
                        yAxisID: 'yRev',
                        borderWidth: 2.5,
                        pointBackgroundColor: '#7b1113',
                        pointRadius: 4
                    },
                    {
                        type: 'bar',
                        label: 'Reservation Count',
                        data: <?php echo json_encode($dash_chart_monthly['bookings']); ?>,
                        backgroundColor: '#3b82f6',
                        borderRadius: 4,
                        yAxisID: 'yBk',
                        maxBarThickness: 28
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            font: { family: "'Inter', sans-serif", size: 12, weight: '600' }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                if (ctx.dataset.yAxisID === 'yRev') {
                                    return ' Collections: ₱' + Number(ctx.parsed.y).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
                                }
                                return ' Reservations: ' + ctx.parsed.y + ' application(s)';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false }
                    },
                    yRev: {
                        type: 'linear',
                        position: 'left',
                        beginAtZero: true,
                        ticks: {
                            callback: function(v) {
                                return '₱' + (v >= 1000 ? (v/1000).toFixed(0) + 'k' : v);
                            }
                        },
                        grid: { color: '#f1f5f9' }
                    },
                    yBk: {
                        type: 'linear',
                        position: 'right',
                        beginAtZero: true,
                        grid: { display: false },
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    }

    // Chart 4: Service & Purpose Breakdown
    var ctxSrv = document.getElementById('chartServicesBreakdown');
    if (ctxSrv) {
        new Chart(ctxSrv.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Plot Lot Purchases', 'Cemetery Services'],
                datasets: [{
                    data: [
                        <?php echo (int)($dash_chart_services['plot_sales'] ?? 0); ?>,
                        <?php echo (int)($dash_chart_services['services'] ?? 0); ?>
                    ],
                    backgroundColor: ['#2563eb', '#f59e0b'],
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            font: { family: "'Inter', sans-serif", size: 11, weight: '500' }
                        }
                    }
                }
            }
        });
    }
});
</script>
<script src="includes/session_tab_guard.js"></script>
<script>
// Tab-aware session guard — this tab is the admin dashboard
if (window.GorSessionGuard) {
    GorSessionGuard.init('dashboard');
}
</script>
<script src="assets/js/ph_address_selector.js"></script>
</body>
</html>

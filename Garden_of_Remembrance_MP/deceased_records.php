<?php
/**
 * =========================================================================
 * Garden of Remembrance Memorial Park
 * Deceased Persons Registry & Grave Locator
 * =========================================================================
 * Dedicated page for managing deceased records, burial registry,
 * grave locations, remains transfer eligibility (8-10 years policy),
 * and cemetery digital mapping.
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/connection.php';
if (file_exists(__DIR__ . '/config/transfer_policy.php')) {
    require_once __DIR__ . '/config/transfer_policy.php';
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
    $rCheck = $conn->query("SELECT role, first_name, last_name FROM tbl_user WHERE user_id = $session_uid LIMIT 1");
    if ($rCheck && $rRow = $rCheck->fetch_assoc()) {
        $session_role = strtoupper(trim($rRow['role']));
        $_SESSION['role'] = $session_role;
    }
}

$isAdmin = in_array($session_role, ['ADMIN', 'STAFF']);
if (!$isAdmin) {
    header('Location: login.php?unauthorized=1');
    exit;
}

$active_nav = 'deceased';

// Helper for remains transfer eligibility fallback
if (!function_exists('calculate_remains_transfer_eligibility')) {
    function calculate_remains_transfer_eligibility($interment_date = null, $is_transferred = false, $as_of_date = null): array {
        if ($is_transferred) {
            return [
                'can_transfer' => false,
                'status' => 'TRANSFERRED',
                'status_code' => 'transferred',
                'badge_style' => 'background-color:#475569; color:#fff;',
                'message' => 'Remains have already been transferred.',
                'years_elapsed' => null,
                'formatted_interval' => 'Completed'
            ];
        }
        if (empty($interment_date) || $interment_date === '0000-00-00') {
            return [
                'can_transfer' => false,
                'status' => 'FOR ADMIN REVIEW',
                'status_code' => 'review',
                'badge_style' => 'background-color:#b91c1c; color:#fff;',
                'message' => 'No interment date recorded. Administrative verification required.',
                'years_elapsed' => null,
                'formatted_interval' => 'N/A'
            ];
        }
        $refDate = $as_of_date ? new DateTime($as_of_date) : new DateTime();
        $iDate = new DateTime($interment_date);
        $diff = $iDate->diff($refDate);
        $years = $diff->y;
        $months = $diff->m;
        $formatted = $years . 'y ' . $months . 'm';

        if ($years >= 5) {
            return [
                'can_transfer' => true,
                'status' => 'ELIGIBLE FOR TRANSFER',
                'status_code' => 'eligible',
                'badge_style' => 'background-color:#15803d; color:#fff;',
                'message' => 'Meets policy requirements (' . $formatted . ' resting period).',
                'years_elapsed' => $years,
                'formatted_interval' => $formatted
            ];
        }
        return [
            'can_transfer' => false,
            'status' => 'INELIGIBLE (< 5 YRS)',
            'status_code' => 'ineligible',
            'badge_style' => 'background-color:#d97706; color:#fff;',
            'message' => 'Requires minimum 5-8 years interval (' . $formatted . ' elapsed).',
            'years_elapsed' => $years,
            'formatted_interval' => $formatted
        ];
    }
}

// -------------------------------------------------------------------------
// 2. Handle POST Actions (Edit Deceased, Transfer Remains)
// -------------------------------------------------------------------------
$alert_msg = '';
$alert_type = '';

// Handle Record Deceased Transfer via POST (AJAX or regular)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_deceased_transfer') {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    $deceased_id = intval($_POST['deceased_id'] ?? 0);
    $transfer_date = trim($_POST['transfer_date'] ?? date('Y-m-d'));
    $destination_type = trim($_POST['destination_type'] ?? 'NEW_PLOT');
    $destination_section = strtoupper(trim($_POST['destination_section'] ?? ''));
    $destination_plot_x = intval($_POST['destination_plot_x'] ?? 0);
    $destination_plot_y = intval($_POST['destination_plot_y'] ?? 0);
    $destination_details = trim($_POST['destination_details'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    $response = ['success' => false, 'message' => ''];

    if ($deceased_id <= 0) {
        $response['message'] = 'Please select a valid deceased record.';
    } else {
        $dStmt = $conn->prepare("SELECT * FROM tbl_deceased WHERE deceased_id = ? LIMIT 1");
        if ($dStmt) {
            $dStmt->bind_param('i', $deceased_id);
            $dStmt->execute();
            $dec = $dStmt->get_result()->fetch_assoc();
            $dStmt->close();

            if (!$dec) {
                $response['message'] = 'Deceased record not found.';
            } else {
                $interment_date = !empty($dec['interment_date']) ? $dec['interment_date'] : $dec['date_died'];
                $eligibility = calculate_remains_transfer_eligibility($interment_date, false, $transfer_date);

                if (!$eligibility['can_transfer']) {
                    $response['message'] = $eligibility['message'];
                } elseif ($destination_type === 'NEW_PLOT' && (empty($destination_section) || $destination_plot_x <= 0 || $destination_plot_y <= 0)) {
                    $response['message'] = 'Please select a destination plot from the cemetery map.';
                } else {
                    $orig_section = strtoupper(trim($dec['section']));
                    $orig_px = (int)$dec['plot_x'];
                    $orig_py = (int)$dec['plot_y'];

                    // Update deceased record
                    $upStmt = $conn->prepare("UPDATE tbl_deceased SET section = ?, plot_x = ?, plot_y = ?, transfer_status = 'TRANSFERRED', edited_by = ? WHERE deceased_id = ?");
                    $editorName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Administrator';
                    $upStmt->bind_param('siisi', $destination_section, $destination_plot_x, $destination_plot_y, $editorName, $deceased_id);
                    
                    if ($upStmt->execute()) {
                        $upStmt->close();

                        // Update plots status
                        $conn->query("UPDATE tbl_plots SET status = 'occupied' WHERE UPPER(TRIM(section)) = '{$destination_section}' AND plot_x = {$destination_plot_x} AND plot_y = {$destination_plot_y}");

                        // Check remaining occupants at origin plot
                        $chkOrig = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$orig_section}' AND plot_x = {$orig_px} AND plot_y = {$orig_py}");
                        $origCount = $chkOrig ? (int)($chkOrig->fetch_assoc()['c'] ?? 0) : 0;
                        if ($origCount === 0) {
                            $conn->query("UPDATE tbl_plots SET status = 'available' WHERE UPPER(TRIM(section)) = '{$orig_section}' AND plot_x = {$orig_px} AND plot_y = {$orig_py}");
                        }

                        // Log activity
                        $decFullName = trim($dec['first_name'] . ' ' . ($dec['middle_name'] ?? '') . ' ' . $dec['last_name']);
                        $logDetails = "Remains of {$decFullName} transferred from Section {$orig_section} Plot {$orig_px}-{$orig_py} to Section {$destination_section} Plot {$destination_plot_x}-{$destination_plot_y}. Reason: {$reason}";
                        $conn->query("INSERT INTO tbl_logs (action, details, timestamp, user_id) VALUES ('REMAINS_TRANSFERRED', '" . $conn->real_escape_string($logDetails) . "', NOW(), {$session_uid})");

                        $response['success'] = true;
                        $response['message'] = 'Remains transfer recorded successfully!';
                    } else {
                        $response['message'] = 'Database update failed: ' . $conn->error;
                        $upStmt->close();
                    }
                }
            }
        }
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    } else {
        $alert_type = $response['success'] ? 'success' : 'danger';
        $alert_msg = $response['message'];
    }
}

// Handle Edit Deceased Submission
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_deceased') {
    $deceased_id = intval($_POST['deceased_id'] ?? 0);
    $first_name = strtoupper(trim($_POST['first_name'] ?? ''));
    $middle_name = strtoupper(trim($_POST['middle_name'] ?? ''));
    $last_name = strtoupper(trim($_POST['last_name'] ?? ''));
    $suffix = strtoupper(trim($_POST['suffix'] ?? ''));
    if ($suffix === 'OTHER') {
        $suffix = strtoupper(trim($_POST['suffix_other'] ?? ''));
    }
    $bday = trim($_POST['bday'] ?? '');
    $born_in = strtoupper(trim($_POST['born_in'] ?? ''));
    $contact_no = trim($_POST['contact_no'] ?? '');
    $date_died = trim($_POST['date_died'] ?? '');
    $funeral_service = strtoupper(trim($_POST['funeral_service'] ?? ''));
    $section = strtoupper(trim($_POST['section'] ?? ''));
    $plot_x = intval($_POST['plot_x'] ?? 0);
    $plot_y = intval($_POST['plot_y'] ?? 0);
    $editorName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Administrator';

    if (empty($first_name) || empty($last_name) || empty($date_died)) {
        $alert_type = 'danger';
        $alert_msg = 'Please fill in all required fields (First Name, Last Name, Date Died).';
    } else {
        $has_suffix_col = false;
        $colCheck = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'suffix'");
        if ($colCheck && $colCheck->num_rows > 0) $has_suffix_col = true;

        if ($has_suffix_col) {
            $upSql = "UPDATE tbl_deceased SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, bday = ?, born_in = ?, contact_no = ?, date_died = ?, funeral_service = ?, section = ?, plot_x = ?, plot_y = ?, edited_by = ? WHERE deceased_id = ?";
            $up = $conn->prepare($upSql);
            if ($up) {
                $up->bind_param("sssssssssiissi", $first_name, $middle_name, $last_name, $suffix, $bday, $born_in, $contact_no, $date_died, $funeral_service, $section, $plot_x, $plot_y, $editorName, $deceased_id);
                $up->execute();
                $up->close();
                $alert_type = 'success';
                $alert_msg = "Deceased record for {$first_name} {$last_name} updated successfully!";
                $conn->query("INSERT INTO tbl_logs (action, details, timestamp, user_id) VALUES ('DECEASED_UPDATED', 'Updated deceased record #{$deceased_id} ({$first_name} {$last_name}) in Section {$section} Plot {$plot_x}-{$plot_y}', NOW(), {$session_uid})");
            }
        } else {
            $upSql = "UPDATE tbl_deceased SET first_name = ?, middle_name = ?, last_name = ?, bday = ?, born_in = ?, contact_no = ?, date_died = ?, funeral_service = ?, section = ?, plot_x = ?, plot_y = ?, edited_by = ? WHERE deceased_id = ?";
            $up = $conn->prepare($upSql);
            if ($up) {
                $up->bind_param("ssssssssiissi", $first_name, $middle_name, $last_name, $bday, $born_in, $contact_no, $date_died, $funeral_service, $section, $plot_x, $plot_y, $editorName, $deceased_id);
                $up->execute();
                $up->close();
                $alert_type = 'success';
                $alert_msg = "Deceased record for {$first_name} {$last_name} updated successfully!";
                $conn->query("INSERT INTO tbl_logs (action, details, timestamp, user_id) VALUES ('DECEASED_UPDATED', 'Updated deceased record #{$deceased_id} ({$first_name} {$last_name}) in Section {$section} Plot {$plot_x}-{$plot_y}', NOW(), {$session_uid})");
            }
        }
    }
}

// -------------------------------------------------------------------------
// 3. Statistical Metrics for Header KPI
// -------------------------------------------------------------------------
$stat_total_deceased = 0;
$stat_transferred = 0;
$stat_this_year = 0;
$stat_eligible = 0;

try {
    $qTotal = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased");
    if ($qTotal && $r = $qTotal->fetch_assoc()) $stat_total_deceased = (int)$r['c'];

    $qTrans = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(transfer_status)) = 'TRANSFERRED'");
    if ($qTrans && $r = $qTrans->fetch_assoc()) $stat_transferred = (int)$r['c'];

    $currentYear = (int)date('Y');
    $qYear = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE YEAR(COALESCE(NULLIF(interment_date, '0000-00-00'), date_died)) = $currentYear");
    if ($qYear && $r = $qYear->fetch_assoc()) $stat_this_year = (int)$r['c'];

    $qElig = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE (transfer_status IS NULL OR UPPER(TRIM(transfer_status)) != 'TRANSFERRED') AND DATEDIFF(NOW(), COALESCE(NULLIF(interment_date,'0000-00-00'), date_died)) >= (5 * 365.25)");
    if ($qElig && $r = $qElig->fetch_assoc()) $stat_eligible = (int)$r['c'];
} catch (Throwable $e) {
    error_log("Deceased stats error: " . $e->getMessage());
}

// -------------------------------------------------------------------------
// 4. Query Deceased Records (Search & Filters)
// -------------------------------------------------------------------------
$filterYear = trim($_GET['year'] ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
$filterSec = strtoupper(trim($_GET['section'] ?? ''));
$filterElig = strtolower(trim($_GET['eligibility'] ?? ''));
$searchQ = trim($_GET['q'] ?? '');

$where_conditions = [];

if (!empty($searchQ)) {
    $q = preg_replace('/\s+/', ' ', $searchQ);
    $escaped_q = $conn->real_escape_string($q);
    $q_like = '%' . $escaped_q . '%';

    $q_conds = [];
    if (preg_match('/^(?:section\s*)?([a-z])[\s\-_]+(?:plot\s*)?(\d+)[\s,\-_]+(\d+)$/i', $q, $m)) {
        $matchSec = strtoupper($m[1]);
        $matchPx = (int)$m[2];
        $matchPy = (int)$m[3];
        $q_conds[] = "(UPPER(TRIM(section)) = '$matchSec' AND plot_x = $matchPx AND plot_y = $matchPy)";
    } elseif (preg_match('/^(?:plot\s*)?(\d+)[\s,\-_]+(\d+)$/i', $q, $m)) {
        $matchPx = (int)$m[1];
        $matchPy = (int)$m[2];
        $q_conds[] = "(plot_x = $matchPx AND plot_y = $matchPy)";
    } elseif (preg_match('/^section\s+([a-z])$/i', $q, $m)) {
        $matchSec = strtoupper($m[1]);
        $q_conds[] = "(UPPER(TRIM(section)) = '$matchSec')";
    }

    $q_conds[] = "LOWER(TRIM(CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(middle_name,''), NULLIF(last_name,''), NULLIF(suffix,'')))) LIKE LOWER('$q_like')";
    $q_conds[] = "LOWER(CONCAT('Section ', section, ' Plot ', plot_x, '-', plot_y)) LIKE LOWER('$q_like')";
    $q_conds[] = "LOWER(CONCAT(section, ' ', plot_x, '-', plot_y)) LIKE LOWER('$q_like')";
    $q_conds[] = "LOWER(CONCAT(section, ' ', plot_x, ',', plot_y)) LIKE LOWER('$q_like')";

    $search_terms = explode(' ', $q);
    if (count($search_terms) > 1) {
        $word_conds = [];
        foreach ($search_terms as $term) {
            $term = trim($term);
            if ($term === '') continue;
            $term_like = '%' . $conn->real_escape_string($term) . '%';
            $word_conds[] = "(LOWER(first_name) LIKE LOWER('$term_like') OR LOWER(middle_name) LIKE LOWER('$term_like') OR LOWER(last_name) LIKE LOWER('$term_like'))";
        }
        if (!empty($word_conds)) {
            $q_conds[] = "(" . implode(' AND ', $word_conds) . ")";
        }
    }
    $where_conditions[] = "(" . implode(' OR ', $q_conds) . ")";
}

if (!empty($filterYear) && is_numeric($filterYear)) {
    $escYear = (int)$filterYear;
    $where_conditions[] = "(YEAR(date_died) = $escYear OR YEAR(interment_date) = $escYear OR YEAR(bday) = $escYear)";
}

if (!empty($filterDateFrom)) {
    $escFrom = $conn->real_escape_string($filterDateFrom);
    $where_conditions[] = "(COALESCE(NULLIF(interment_date, '0000-00-00'), date_died) >= '$escFrom')";
}

if (!empty($filterDateTo)) {
    $escTo = $conn->real_escape_string($filterDateTo);
    $where_conditions[] = "(COALESCE(NULLIF(interment_date, '0000-00-00'), date_died) <= '$escTo')";
}

if (!empty($filterSec)) {
    $escSec = $conn->real_escape_string($filterSec);
    $where_conditions[] = "UPPER(TRIM(section)) = '$escSec'";
}

if (!empty($filterElig)) {
    if ($filterElig === 'transferred') {
        $where_conditions[] = "UPPER(TRIM(COALESCE(transfer_status, ''))) = 'TRANSFERRED'";
    } elseif ($filterElig === 'eligible') {
        $where_conditions[] = "(UPPER(TRIM(COALESCE(transfer_status, ''))) != 'TRANSFERRED' AND DATEDIFF(NOW(), COALESCE(NULLIF(interment_date,'0000-00-00'), date_died)) >= (5 * 365.25))";
    } elseif ($filterElig === 'ineligible') {
        $where_conditions[] = "(UPPER(TRIM(COALESCE(transfer_status, ''))) != 'TRANSFERRED' AND (interment_date IS NULL OR DATEDIFF(NOW(), COALESCE(NULLIF(interment_date,'0000-00-00'), date_died)) < (5 * 365.25)))";
    }
}

$where = !empty($where_conditions) ? "WHERE " . implode(' AND ', $where_conditions) : "";
$sql = "SELECT *, TRIM(CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(middle_name,''), NULLIF(last_name,''))) AS full_name, CONCAT('Section ', section, ' Plot ', plot_x, '-', plot_y) AS grave_address FROM tbl_deceased $where ORDER BY last_name ASC, first_name ASC LIMIT 250";

$recordsResult = null;
try {
    $recordsResult = $conn->query($sql);
} catch (Throwable $e) {
    error_log("Records query failed: " . $e->getMessage());
}

$hasActiveFilters = !empty($searchQ) || !empty($filterYear) || !empty($filterDateFrom) || !empty($filterDateTo) || !empty($filterSec) || !empty($filterElig);
$recordsCount = $recordsResult ? $recordsResult->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Deceased Persons Registry & Grave Locator | Garden of Remembrance</title>
  <link rel="icon" type="image/png" href="cmis_logo.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="includes/admin_header.css">
  <style>
    :root {
      --theme-maroon: #7b1113;
      --theme-maroon-dark: #5c0d0f;
      --theme-maroon-light: #94171a;
      --theme-gold: #f59e0b;
    }
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background-color: #f1f5f9;
      color: #1e293b;
      margin: 0;
      padding: 0;
    }

    /* Page Hero Banner */
    .registry-hero {
      background: linear-gradient(135deg, #7b1113 0%, #94171a 50%, #5d0c0e 100%);
      color: #ffffff;
      padding: 2.2rem 1.5rem;
      border-radius: 1.25rem;
      margin-bottom: 2rem;
      box-shadow: 0 10px 25px rgba(123, 17, 19, 0.18);
    }
    .hero-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      background: rgba(255, 255, 255, 0.15);
      border: 1px solid rgba(255, 255, 255, 0.25);
      border-radius: 50px;
      padding: 0.35rem 0.85rem;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.5px;
      color: #fef08a;
    }

    /* Metric KPI Cards */
    .metric-card {
      background: #ffffff;
      border-radius: 1rem;
      padding: 1.25rem;
      border: 1px solid #e2e8f0;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
      display: flex;
      align-items: center;
      gap: 1rem;
      transition: all 0.2s ease;
    }
    .metric-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(0,0,0,0.08);
    }
    .metric-icon {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      flex-shrink: 0;
    }

    /* Search Capsule */
    .dashboard-search-box {
      width: 100%;
      max-width: 580px;
      margin: 0 auto;
    }
    .search-capsule-wrapper {
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 2.5rem;
      box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
      transition: all 0.2s ease;
      overflow: visible;
      position: relative;
    }
    .search-capsule-wrapper:focus-within {
      border-color: #7b1113;
      box-shadow: 0 4px 20px rgba(123, 17, 19, 0.18);
    }
    .btn-search-filter {
      border: none;
      background: transparent;
      color: #64748b;
      padding: 0.7rem 1.1rem;
      font-size: 1rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      border-radius: 2.5rem 0 0 2.5rem;
      transition: all 0.2s ease;
    }
    .btn-search-filter:hover, .btn-search-filter:focus {
      color: #7b1113;
      background: #fdf2f2;
    }
    .filter-active-dot {
      position: absolute;
      top: 8px;
      right: 9px;
      width: 9px;
      height: 9px;
      background-color: #dc2626;
      border-radius: 50%;
      border: 2px solid #ffffff;
    }
    .search-capsule-wrapper .form-control.search-field {
      border: none !important;
      border-radius: 0 !important;
      box-shadow: none !important;
      font-size: 0.95rem;
      padding: 0.7rem 0.8rem;
      flex: 1;
      background: transparent;
    }
    .search-capsule-wrapper .btn-search {
      border-radius: 0 2.5rem 2.5rem 0 !important;
      padding: 0 1.4rem;
      min-width: 52px;
      min-height: 48px;
      background-color: #7b1113 !important;
      border: none !important;
      color: #ffffff !important;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background-color 0.2s ease;
    }
    .search-capsule-wrapper .btn-search:hover {
      background-color: #94171a !important;
    }
    .btn-clear-search {
      border: none;
      background: transparent;
      padding: 0 0.6rem;
      font-size: 0.9rem;
      color: #94a3b8;
      display: flex;
      align-items: center;
      text-decoration: none;
      cursor: pointer;
    }
    .btn-clear-search:hover {
      color: #1e293b;
    }

    /* Pill Buttons */
    .view-map-pill-btn {
      border-radius: 2rem;
      padding: 0.55rem 1.6rem;
      font-weight: 600;
      letter-spacing: 0.3px;
      display: inline-flex;
      align-items: center;
      background-color: #7b1113 !important;
      border-color: #7b1113 !important;
      color: #ffffff !important;
      transition: all 0.2s ease;
      box-shadow: 0 2px 6px rgba(123, 17, 19, 0.2);
    }
    .view-map-pill-btn:hover {
      background-color: #94171a !important;
      color: #ffffff !important;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(123, 17, 19, 0.3);
    }

    /* Deceased Table Card */
    .deceased-card-container {
      width: 100%;
      margin: 1.5rem auto 3rem;
    }
    .deceased-table-card {
      background: #ffffff;
      border-radius: 1.25rem;
      border: 1px solid #e2e8f0;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
      overflow: hidden;
    }
    .deceased-table thead th {
      background-color: #eff6ff;
      color: #1e3a8a;
      font-weight: 700;
      font-size: 0.82rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 0.9rem 1rem;
      border-bottom: 2px solid #dbeafe;
    }
    .deceased-table tbody td {
      padding: 0.85rem 1rem;
      vertical-align: middle;
      font-size: 0.88rem;
    }
    .deceased-table tbody tr:hover {
      background-color: #f8fafc;
    }

    /* Minimalist Plus Options Menu */
    .plus-option {
      transition: background 0.15s ease;
    }
    .plus-option:hover {
      background-color: #fef2f2;
      color: #7b1113 !important;
    }

    /* Modals Responsive */
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
      border-radius: 1.25rem !important;
    }
    #popupSectionModal .modal-body {
      flex: 1 1 auto !important;
      height: calc(95vh - 58px) !important;
      max-height: none !important;
      overflow: hidden !important;
      padding: 0 !important;
    }
  </style>
</head>
<body>

<?php include __DIR__ . '/includes/admin_header.php'; ?>

<main class="container-fluid px-3 px-md-4 py-4" style="max-width: 1400px;">

  <?php if (!empty($alert_msg)): ?>
    <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show shadow-sm" role="alert" style="border-radius: 1rem;">
      <i class="fas <?php echo ($alert_type === 'success') ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> me-2"></i>
      <?php echo htmlspecialchars($alert_msg); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <!-- Hero Header Banner -->
  <div class="registry-hero">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <div class="hero-badge mb-2">
          <i class="fas fa-certificate"></i> CEMETERY RECORD REGISTRY
        </div>
        <h2 class="fw-bold mb-2 text-white" style="letter-spacing: -0.5px;">
          <i class="fas fa-cross me-2 opacity-75"></i>Deceased Persons Registry &amp; Grave Locator
        </h2>
        <p class="mb-0 text-white-50" style="max-width: 780px; font-size: 0.95rem;">
          Official repository of interred individuals at Garden of Remembrance Memorial Park. Search deceased occupants, inspect plot coordinates, evaluate remains transfer eligibility (8–10 years resting rule), and view plots on the digital map.
        </p>
      </div>

      <!-- Quick Action Buttons -->
      <div class="d-flex flex-wrap align-items-center gap-2">
        <button type="button" class="btn btn-light text-danger fw-bold shadow-sm px-3 py-2 rounded-pill" data-bs-toggle="modal" data-bs-target="#addDeceasedModal">
          <i class="fas fa-user-plus text-danger me-1"></i> Add Deceased
        </button>
        <a href="map.php" class="btn btn-outline-light text-white fw-semibold px-3 py-2 rounded-pill">
          <i class="fas fa-map-marked-alt me-1"></i> Digital Map
        </a>
        <button type="button" class="btn btn-outline-light text-white fw-semibold px-3 py-2 rounded-pill" data-bs-toggle="modal" data-bs-target="#announcementModal">
          <i class="fas fa-bullhorn me-1"></i> Broadcast
        </button>
      </div>
    </div>
  </div>

  <!-- Statistical KPI Summary -->
  <div class="row g-3 mb-4">
    <div class="col-xl-3 col-sm-6">
      <div class="metric-card">
        <div class="metric-icon" style="background:#fee2e2; color:#b91c1c;">
          <i class="fas fa-users"></i>
        </div>
        <div>
          <span class="text-uppercase fw-bold text-muted" style="font-size:0.75rem; letter-spacing:0.5px;">Total Deceased</span>
          <div class="fs-4 fw-bold text-dark"><?php echo number_format($stat_total_deceased); ?></div>
          <small class="text-muted">Recorded interments</small>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-sm-6">
      <div class="metric-card">
        <div class="metric-icon" style="background:#fef3c7; color:#d97706;">
          <i class="fas fa-calendar-check"></i>
        </div>
        <div>
          <span class="text-uppercase fw-bold text-muted" style="font-size:0.75rem; letter-spacing:0.5px;">Burials This Year</span>
          <div class="fs-4 fw-bold text-dark"><?php echo number_format($stat_this_year); ?></div>
          <small class="text-muted">Interred in <?php echo date('Y'); ?></small>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-sm-6">
      <div class="metric-card">
        <div class="metric-icon" style="background:#dcfce7; color:#15803d;">
          <i class="fas fa-check-double"></i>
        </div>
        <div>
          <span class="text-uppercase fw-bold text-muted" style="font-size:0.75rem; letter-spacing:0.5px;">Transfer Eligible</span>
          <div class="fs-4 fw-bold text-success"><?php echo number_format($stat_eligible); ?></div>
          <small class="text-muted">Met 5+ yrs resting rule</small>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-sm-6">
      <div class="metric-card">
        <div class="metric-icon" style="background:#f1f5f9; color:#475569;">
          <i class="fas fa-exchange-alt"></i>
        </div>
        <div>
          <span class="text-uppercase fw-bold text-muted" style="font-size:0.75rem; letter-spacing:0.5px;">Transferred Remains</span>
          <div class="fs-4 fw-bold text-dark"><?php echo number_format($stat_transferred); ?></div>
          <small class="text-muted">Moved to new plots/ossuary</small>
        </div>
      </div>
    </div>
  </div>

  <!-- Search & Filter Bar Capsule -->
  <form class="dashboard-search-box position-relative" method="GET" action="deceased_records.php" id="dashboardSearchForm">
    <div class="search-capsule-wrapper d-flex align-items-center">

      <!-- Filter Dropdown Trigger -->
      <div class="dropdown">
        <button type="button" class="btn btn-search-filter position-relative" id="deceasedFilterDropdownBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Click to filter by year, section, dates, or eligibility">
          <i class="fas fa-sliders-h"></i>
          <?php if (!empty($filterYear) || !empty($filterDateFrom) || !empty($filterDateTo) || !empty($filterSec) || !empty($filterElig)): ?>
            <span class="filter-active-dot"></span>
          <?php endif; ?>
        </button>

        <div class="dropdown-menu dropdown-menu-start shadow-lg p-3 border-0" aria-labelledby="deceasedFilterDropdownBtn" style="min-width: 320px; border-radius: 1.25rem; z-index: 1050;">
          <div class="d-flex align-items-center justify-content-between mb-2 border-bottom pb-2">
            <span class="fw-bold text-dark small"><i class="fas fa-filter text-danger me-1"></i> Filter Registry</span>
            <?php if ($hasActiveFilters): ?>
              <a href="deceased_records.php" class="small text-danger text-decoration-none fw-semibold">Reset All</a>
            <?php endif; ?>
          </div>

          <!-- Filter: Year -->
          <div class="mb-2">
            <label class="form-label small fw-bold mb-1 text-dark" for="filterYearInput">
              <i class="fas fa-calendar-alt text-danger me-1"></i> Year of Death / Burial:
            </label>
            <input type="number" name="year" id="filterYearInput" class="form-control form-control-sm" placeholder="e.g. 2024" min="1900" max="2099" value="<?php echo htmlspecialchars($filterYear); ?>">
            <div class="d-flex gap-1 mt-1 flex-wrap">
              <?php 
              $quickYears = [date('Y'), date('Y')-1, date('Y')-2, date('Y')-3, date('Y')-5];
              foreach ($quickYears as $qy): 
              ?>
                <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 rounded-pill" style="font-size:0.75rem;" onclick="document.getElementById('filterYearInput').value='<?php echo $qy; ?>';"><?php echo $qy; ?></button>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Filter: Date Range -->
          <div class="mb-2">
            <label class="form-label small fw-bold mb-1 text-dark">
              <i class="fas fa-history text-secondary me-1"></i> Check Previous Dates:
            </label>
            <div class="row g-1">
              <div class="col-6">
                <span class="text-muted" style="font-size: 0.72rem;">From:</span>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
              </div>
              <div class="col-6">
                <span class="text-muted" style="font-size: 0.72rem;">To:</span>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filterDateTo); ?>">
              </div>
            </div>
          </div>

          <!-- Filter: Section -->
          <div class="mb-2">
            <label class="form-label small fw-bold mb-1 text-dark">
              <i class="fas fa-layer-group text-secondary me-1"></i> Section:
            </label>
            <select name="section" class="form-select form-select-sm">
              <option value="">All Sections</option>
              <?php foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $s): ?>
                <option value="<?php echo $s; ?>" <?php echo ($filterSec === $s) ? 'selected' : ''; ?>>Section <?php echo $s; ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Filter: Transfer Eligibility -->
          <div class="mb-3">
            <label class="form-label small fw-bold mb-1 text-dark">
              <i class="fas fa-exchange-alt text-secondary me-1"></i> Transfer Eligibility:
            </label>
            <select name="eligibility" class="form-select form-select-sm">
              <option value="">All Statuses</option>
              <option value="eligible" <?php echo ($filterElig === 'eligible') ? 'selected' : ''; ?>>Eligible for Transfer (5+ Yrs)</option>
              <option value="ineligible" <?php echo ($filterElig === 'ineligible') ? 'selected' : ''; ?>>Ineligible (&lt; 5 Yrs)</option>
              <option value="transferred" <?php echo ($filterElig === 'transferred') ? 'selected' : ''; ?>>Already Transferred</option>
            </select>
          </div>

          <button type="submit" class="btn btn-sm btn-danger w-100 fw-semibold py-1.5 rounded-pill" style="background-color: #7b1113; border-color: #7b1113;">
            <i class="fas fa-check me-1"></i> Apply Filters
          </button>
        </div>
      </div>

      <!-- Text Search Input -->
      <input class="form-control search-field" type="search" name="q" id="dashboardSearchQ" value="<?php echo htmlspecialchars($searchQ); ?>" placeholder="Search deceased person by name or plot (e.g., Dela Cruz, C 1-2)..." aria-label="Search deceased" autocomplete="off">

      <!-- Clear Search Button -->
      <a href="deceased_records.php" class="btn-clear-search" id="dashboardSearchClearBtn" title="Clear Search &amp; Filters" style="<?php echo $hasActiveFilters ? 'display:flex;' : 'display:none;'; ?>">
        <i class="fas fa-times"></i>
      </a>

      <!-- Search Submit Button -->
      <button class="btn btn-search" type="submit" aria-label="Submit search">
        <i class="fas fa-search"></i>
      </button>
    </div>

    <!-- Active Filters Tags -->
    <?php if ($hasActiveFilters): ?>
      <div class="d-flex justify-content-center align-items-center gap-1.5 mt-2 flex-wrap" style="font-size: 0.82rem;">
        <span class="text-muted small fw-semibold">Active:</span>
        <?php if (!empty($searchQ)): ?>
          <span class="badge bg-dark rounded-pill py-1 px-2.5">Keyword: "<?php echo htmlspecialchars($searchQ); ?>"</span>
        <?php endif; ?>
        <?php if (!empty($filterYear)): ?>
          <span class="badge bg-danger rounded-pill py-1 px-2.5">Year: <?php echo htmlspecialchars($filterYear); ?></span>
        <?php endif; ?>
        <?php if (!empty($filterDateFrom) || !empty($filterDateTo)): ?>
          <span class="badge bg-secondary rounded-pill py-1 px-2.5"><?php echo htmlspecialchars($filterDateFrom ?: 'Start'); ?> &rarr; <?php echo htmlspecialchars($filterDateTo ?: 'Now'); ?></span>
        <?php endif; ?>
        <?php if (!empty($filterSec)): ?>
          <span class="badge bg-danger rounded-pill py-1 px-2.5">Section <?php echo htmlspecialchars($filterSec); ?></span>
        <?php endif; ?>
        <?php if (!empty($filterElig)): ?>
          <span class="badge bg-warning text-dark rounded-pill py-1 px-2.5"><?php echo ucfirst(htmlspecialchars($filterElig)); ?></span>
        <?php endif; ?>
        <a href="deceased_records.php" class="badge bg-light text-danger border rounded-pill text-decoration-none py-1 px-2.5 shadow-sm" title="Clear all filters"><i class="fas fa-times me-1"></i>Clear All</a>
      </div>
    <?php endif; ?>
  </form>

  <!-- Quick Action Pill Buttons -->
  <div class="d-flex justify-content-center mt-3 gap-2 flex-wrap">
    <a href="map.php" class="btn view-map-pill-btn">
      <i class="fas fa-map-marked-alt me-2"></i>
      View Map
    </a>
    <button type="button" id="broadcastAnnouncementBtn" class="btn btn-outline-danger px-4 py-2 fw-semibold rounded-pill" style="border-color: #7b1113; color: #7b1113;" data-bs-toggle="modal" data-bs-target="#announcementModal">
      <i class="fas fa-bullhorn me-2"></i>
      Broadcast Announcement
    </button>
  </div>

  <!-- Deceased Records Table Card -->
  <div class="deceased-card-container">
    <div class="deceased-table-card">
      <div class="p-3 p-md-4">
        
        <!-- Table Header & Controls -->
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
          
          <!-- Plus Menu -->
          <div class="position-relative">
            <button id="plusOptionsBtn" class="btn btn-light border-0 p-0 d-flex align-items-center justify-content-center shadow-sm" type="button" style="width:38px;height:38px;border-radius:50%;color:#7b1113;background:#fee2e2;" title="Add new record / Options">
              <i class="fas fa-plus" style="font-size: 1.15rem;"></i>
            </button>
            <div id="plusOptionsMenu" style="display:none;position:absolute;top:46px;left:0;min-width:220px;background:#fff;border-radius:0.75rem;box-shadow:0 6px 20px rgba(160,58,60,0.18);padding:0.5rem 0;z-index:20;border:1px solid #fee2e2;">
              <a href="#" class="d-flex align-items-center px-3 py-2 text-decoration-none text-dark plus-option" data-bs-toggle="modal" data-bs-target="#addDeceasedModal">
                <i class="fas fa-user-plus text-danger me-2"></i> Add Deceased Record
              </a>
              <a href="#" class="d-flex align-items-center px-3 py-2 text-decoration-none text-dark plus-option" data-bs-toggle="modal" data-bs-target="#announcementModal">
                <i class="fas fa-bullhorn text-danger me-2"></i> Broadcast Announcement
              </a>
              <a href="map.php" class="d-flex align-items-center px-3 py-2 text-decoration-none text-dark plus-option">
                <i class="fas fa-map-marked-alt text-danger me-2"></i> Digital Map
              </a>
            </div>
          </div>

          <!-- Centered Title -->
          <h5 class="m-0 fw-bold text-center flex-grow-1" style="color: #7b1113; font-size: 1.25rem;">
            <i class="fas fa-users text-danger me-2"></i>List of Deceased Person
          </h5>

          <!-- Record Counter Badge -->
          <div>
            <span class="badge bg-light text-dark border px-3 py-2 fw-semibold" style="font-size: 0.82rem;">
              <i class="fas fa-list-ol text-primary me-1"></i> <?php echo $recordsCount; ?> Record(s) Found
            </span>
          </div>
        </div>

        <!-- Table View -->
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0 deceased-table">
            <thead>
              <tr>
                <th style="width: 55px; text-align: center;"><i class="fas fa-hashtag"></i></th>
                <th><i class="fas fa-user me-1"></i> Fullname</th>
                <th><i class="fas fa-map-marker-alt me-1"></i> Grave Address</th>
                <th><i class="fas fa-calendar-alt me-1"></i> Burial Date</th>
                <th><i class="fas fa-exchange-alt me-1"></i> Transfer Eligibility</th>
                <th style="min-width: 250px; text-align: center;"><i class="fas fa-tools me-1"></i> Action</th>
              </tr>
            </thead>
            <tbody>
              <?php
              if ($recordsResult && $recordsResult->num_rows > 0) {
                $i = 1;
                while ($row = $recordsResult->fetch_assoc()) {
                  $intermentDate = !empty($row['interment_date']) ? $row['interment_date'] : $row['date_died'];
                  $isTransferred = isset($row['transfer_status']) && strtoupper(trim((string)$row['transfer_status'])) === 'TRANSFERRED';
                  $eligibility = calculate_remains_transfer_eligibility($intermentDate, $isTransferred);

                  $dataRowAttrs = ' class="result-row" '
                    . ' data-deceased-id="' . (int)$row['deceased_id'] . '"'
                    . ' data-full-name="' . htmlspecialchars($row['full_name']) . '"'
                    . ' data-first-name="' . htmlspecialchars($row['first_name']) . '"'
                    . ' data-middle-name="' . htmlspecialchars($row['middle_name']) . '"'
                    . ' data-last-name="' . htmlspecialchars($row['last_name']) . '"'
                    . ' data-suffix="' . htmlspecialchars(isset($row['suffix']) ? $row['suffix'] : '') . '"'
                    . ' data-section="' . htmlspecialchars($row['section']) . '"'
                    . ' data-plot-x="' . htmlspecialchars($row['plot_x']) . '"'
                    . ' data-plot-y="' . htmlspecialchars($row['plot_y']) . '"'
                    . ' data-bday="' . htmlspecialchars($row['bday']) . '"'
                    . ' data-born-in="' . htmlspecialchars(isset($row['born_in']) ? $row['born_in'] : '') . '"'
                    . ' data-contact-no="' . htmlspecialchars(isset($row['contact_no']) ? $row['contact_no'] : '') . '"'
                    . ' data-date-died="' . htmlspecialchars($row['date_died']) . '"'
                    . ' data-interment-date="' . htmlspecialchars($intermentDate) . '"'
                    . ' data-funeral-service="' . htmlspecialchars(isset($row['funeral_service']) ? $row['funeral_service'] : '') . '"'
                    . ' data-transfer-status="' . htmlspecialchars($row['transfer_status'] ?? 'ORIGINAL') . '"'
                    . ' data-eligibility-status="' . htmlspecialchars($eligibility['status']) . '"'
                    . ' data-eligibility-interval="' . htmlspecialchars($eligibility['formatted_interval']) . '"';

                  $popupUrl = 'sections/popup.php?section=' . urlencode($row['section']) . '&plot_x=' . urlencode($row['plot_x']) . '&plot_y=' . urlencode($row['plot_y']);
                  $editDataAttrs = '
                    data-deceased-id="' . (int)$row['deceased_id'] . '"
                    data-first-name="' . htmlspecialchars($row['first_name']) . '"
                    data-middle-name="' . htmlspecialchars($row['middle_name']) . '"
                    data-last-name="' . htmlspecialchars($row['last_name']) . '"
                    data-suffix="' . htmlspecialchars(isset($row['suffix']) ? $row['suffix'] : '') . '"
                    data-bday="' . htmlspecialchars($row['bday']) . '"
                    data-born-in="' . htmlspecialchars(isset($row['born_in']) ? $row['born_in'] : '') . '"
                    data-contact-no="' . htmlspecialchars(isset($row['contact_no']) ? $row['contact_no'] : '') . '"
                    data-date-died="' . htmlspecialchars($row['date_died']) . '"
                    data-interment-date="' . htmlspecialchars($intermentDate) . '"
                    data-funeral-service="' . htmlspecialchars(isset($row['funeral_service']) ? $row['funeral_service'] : '') . '"
                    data-section="' . htmlspecialchars($row['section']) . '"
                    data-plot-x="' . htmlspecialchars($row['plot_x']) . '"
                    data-plot-y="' . htmlspecialchars($row['plot_y']) . '"
                  ';
              ?>
                <tr <?php echo $dataRowAttrs; ?>>
                  <td class="text-center fw-bold text-muted"><?php echo $i++; ?></td>
                  <td>
                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['full_name']); ?></div>
                    <?php if (!empty($row['funeral_service'])): ?>
                      <small class="text-muted"><i class="fas fa-church me-1"></i><?php echo htmlspecialchars($row['funeral_service']); ?></small>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge bg-light text-dark border px-2 py-1 fw-semibold">
                      <i class="fas fa-map-pin text-danger me-1"></i><?php echo htmlspecialchars($row['grave_address']); ?>
                    </span>
                  </td>
                  <td class="small">
                    <?php if (!empty($intermentDate) && $intermentDate !== '0000-00-00'): ?>
                      <div class="fw-semibold text-dark"><?php echo date('M d, Y', strtotime($intermentDate)); ?></div>
                      <small class="text-muted">Interment</small>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge py-1 px-2.5" style="<?php echo $eligibility['badge_style']; ?>" title="<?php echo htmlspecialchars($eligibility['message']); ?>">
                      <?php echo htmlspecialchars($eligibility['status']); ?>
                      <?php if ($eligibility['years_elapsed'] !== null): ?>
                        <span style="opacity:0.85; font-size:0.75rem;">(<?php echo htmlspecialchars($eligibility['formatted_interval']); ?>)</span>
                      <?php endif; ?>
                    </span>
                  </td>
                  <td style="white-space: nowrap; text-align: center;">
                    <button type="button" class="btn btn-sm btn-outline-primary view-popup-btn me-1 rounded-pill px-2.5" data-popup-url="<?php echo htmlspecialchars($popupUrl); ?>" title="View on Map">
                      <i class="fas fa-eye me-1"></i> View
                    </button>
                    <button type="button" class="btn btn-sm btn-primary edit-deceased-btn me-1 rounded-pill px-2.5" <?php echo $editDataAttrs; ?> title="Edit Record" style="background:#7b1113; border-color:#7b1113;">
                      <i class="fas fa-edit me-1"></i> Edit
                    </button>
                    <button type="button" class="btn btn-sm btn-success transfer-remains-btn rounded-pill px-2.5"
                            data-deceased-id="<?php echo (int)$row['deceased_id']; ?>"
                            data-full-name="<?php echo htmlspecialchars($row['full_name']); ?>"
                            data-section="<?php echo htmlspecialchars($row['section']); ?>"
                            data-plot-x="<?php echo htmlspecialchars($row['plot_x']); ?>"
                            data-plot-y="<?php echo htmlspecialchars($row['plot_y']); ?>"
                            data-bday="<?php echo htmlspecialchars($row['bday']); ?>"
                            data-date-died="<?php echo htmlspecialchars($row['date_died']); ?>"
                            data-interment-date="<?php echo htmlspecialchars($intermentDate); ?>"
                            data-status="<?php echo htmlspecialchars($eligibility['status']); ?>"
                            data-status-code="<?php echo htmlspecialchars($eligibility['status_code']); ?>"
                            data-interval="<?php echo htmlspecialchars($eligibility['formatted_interval']); ?>"
                            data-message="<?php echo htmlspecialchars($eligibility['message']); ?>"
                            data-is-transferred="<?php echo $isTransferred ? '1' : '0'; ?>"
                            title="Transfer Remains">
                      <i class="fas fa-exchange-alt me-1"></i> Transfer
                    </button>
                  </td>
                </tr>
              <?php
                }
              } else {
                echo '<tr class="empty-row"><td colspan="6" class="text-center text-muted py-5">';
                echo '<i class="fas fa-search fa-2x mb-2 text-secondary opacity-50 d-block"></i>';
                echo 'No deceased person found matching your search or filter criteria.<br>';
                echo '<a href="deceased_records.php" class="btn btn-sm btn-outline-danger mt-2 rounded-pill px-3">Clear filters</a>';
                echo '</td></tr>';
              }
              ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>

</main>

<!-- ======================================================= -->
<!-- MODAL 1: VIEW DECEASED (MOBILE NAVIGATION) -->
<!-- ======================================================= -->
<div class="modal fade" id="viewDeceasedModal" tabindex="-1" aria-labelledby="viewDeceasedModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem;">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title fw-bold" id="viewDeceasedModalLabel"><i class="fas fa-map-marker-alt me-2"></i>Grave Plot Location</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center p-4">
        <div class="mb-3">
          <i class="fas fa-monument fa-3x text-danger"></i>
        </div>
        <h5 class="text-dark fw-bold mb-2" id="view_full_name"></h5>
        <div class="badge bg-dark fs-6 px-3 py-2 mb-3">
          Section <span id="view_section"></span> &bull; Plot <span id="view_plot"></span>
        </div>
        <p class="text-muted small mb-4">You can navigate directly to this grave plot using the digital GPS map.</p>
        <button type="button" class="btn btn-success btn-lg w-100 rounded-pill fw-bold" id="startNavigationBtn">
          <i class="fas fa-route me-2"></i>Navigate to Plot
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ======================================================= -->
<!-- MODAL 2: NAVIGATION READY (MOBILE) -->
<!-- ======================================================= -->
<div class="modal fade" id="navigationModal" tabindex="-1" aria-labelledby="navigationModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem;">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title fw-bold" id="navigationModalLabel"><i class="fas fa-location-arrow me-2"></i>Navigation Ready</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center py-4 px-3">
        <div class="mb-3">
          <i class="fas fa-mobile-alt fa-3x text-success"></i>
        </div>
        <h5 class="fw-bold mb-2">Ready to Navigate to Plot</h5>
        <p class="text-muted small mb-4">Click below to open real-time turn-by-turn guidance on your mobile device.</p>
        <button type="button" class="btn btn-success btn-lg px-4 rounded-pill fw-bold" id="beginNavigationBtn">
          <i class="fas fa-location-arrow me-2"></i>Begin Real-time Navigation
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ======================================================= -->
<!-- MODAL 3: MAP POPUP (DESKTOP & TRANSFER PLOT PICKER) -->
<!-- ======================================================= -->
<div class="modal fade" id="popupSectionModal" tabindex="-1" aria-labelledby="popupSectionModalLabel" aria-hidden="true" data-bs-keyboard="false" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content" style="border-radius:1.25rem;overflow:hidden;border:none;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
      <div class="modal-header d-flex justify-content-between align-items-center" style="background:#7b1113;color:#fff;border-bottom:1px solid rgba(255,255,255,0.15);">
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
            </select>
          </div>
        </div>
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

<!-- ======================================================= -->
<!-- MODAL 4: EDIT DECEASED RECORD -->
<!-- ======================================================= -->
<div class="modal fade" id="editDeceasedModal" tabindex="-1" aria-labelledby="editDeceasedModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-height: 90vh;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;">
      <div class="modal-header py-3 px-4 flex-shrink-0" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff; border-bottom: none;">
        <div class="d-flex align-items-center gap-3">
          <div style="background: rgba(255,255,255,0.15); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;"><i class="fas fa-user-edit text-white"></i></div>
          <div>
            <h5 class="modal-title fw-bold m-0" id="editDeceasedModalLabel" style="font-size: 1.15rem;">
              Edit Deceased Record
            </h5>
            <div style="font-size: 0.8rem; color: rgba(255,255,255,0.85);">Update Personal &amp; Burial Information</div>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form method="POST" action="deceased_records.php" class="d-flex flex-column flex-grow-1 overflow-hidden">
        <input type="hidden" name="action" value="edit_deceased">
        <input type="hidden" id="edit_deceased_id" name="deceased_id" value="">

        <div class="modal-body p-4" style="background: #f8fafc; overflow-y: auto; flex: 1 1 auto;">
          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important;">
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
                </div>
                <div class="col-md-4">
                  <label for="edit_bday" class="form-label small fw-semibold mb-1">Birthday</label>
                  <input type="date" class="form-control form-control-sm" id="edit_bday" name="bday">
                </div>
                <div class="col-md-4">
                  <label for="edit_born_in" class="form-label small fw-semibold mb-1">Born In</label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="edit_born_in" name="born_in">
                </div>
              </div>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important;">
            <div class="card-body p-3">
              <h6 class="fw-bold mb-3 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                <span class="text-primary">🕊️</span> Demise &amp; Funeral Information
              </h6>
              <div class="row g-3">
                <div class="col-md-6">
                  <label for="edit_date_died" class="form-label small fw-semibold mb-1">Date Died <span class="text-danger">*</span></label>
                  <input type="date" class="form-control form-control-sm" id="edit_date_died" name="date_died" required>
                </div>
                <div class="col-md-6">
                  <label for="edit_contact_no" class="form-label small fw-semibold mb-1">Family Contact Number</label>
                  <input type="tel" class="form-control form-control-sm" id="edit_contact_no" name="contact_no" placeholder="09XXXXXXXXX">
                </div>
                <div class="col-12">
                  <label for="edit_funeral_service" class="form-label small fw-semibold mb-1">Funeral Service</label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="edit_funeral_service" name="funeral_service" placeholder="Enter funeral service provider">
                </div>
              </div>
            </div>
          </div>

          <input type="hidden" id="edit_section" name="section">
          <input type="hidden" id="edit_plot_x" name="plot_x">
          <input type="hidden" id="edit_plot_y" name="plot_y">
        </div>

        <div class="modal-footer d-flex justify-content-between px-4 py-3 flex-shrink-0" style="background:#fff; border-top: 1px solid #e2e8f0;">
          <button type="button" class="btn btn-outline-secondary px-4 py-2 fw-semibold rounded-pill" data-bs-dismiss="modal">
            <i class="fas fa-times me-1"></i> Cancel
          </button>
          <button type="submit" class="btn btn-primary px-5 py-2 fw-bold shadow rounded-pill" style="background: #7b1113; border-color: #7b1113;">
            <i class="fas fa-save me-2"></i> Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ======================================================= -->
<!-- MODAL 5: RECORD DECEASED REMAINS TRANSFER -->
<!-- ======================================================= -->
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
            <div class="small text-white-50" style="font-size: 0.8rem;">8–10 Year Remains Transfer Eligibility &amp; Movement</div>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="transferRemainsForm" method="POST" action="deceased_records.php">
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

                    <input type="hidden" id="trans_dest_section" name="destination_section" value="">
                    <input type="hidden" id="trans_dest_plot_x" name="destination_plot_x" value="">
                    <input type="hidden" id="trans_dest_plot_y" name="destination_plot_y" value="">

                    <div id="trans_no_plot_chosen">
                      <button type="button" class="btn btn-outline-danger btn-sm fw-bold px-3 py-2 d-inline-flex align-items-center gap-2 rounded-pill" id="btnAdminOpenTransferMap">
                        <i class="fas fa-map-marked-alt text-danger me-1"></i> Choose Destination Plot from Map
                      </button>
                      <div class="text-muted small mt-1.5" style="font-size: 0.8rem;">
                        Click to select an available vacant plot directly on the cemetery map.
                      </div>
                    </div>

                    <div id="trans_selected_plot_card" class="p-2.5 rounded-3 border" style="background: #fff8f5; border-color: #ecd9c6 !important; display: none;">
                      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                          <span class="badge mb-1 px-2 py-0.5 small" style="background: #7b1113; color: #fff;">
                            <i class="fas fa-check-circle me-1"></i> Selected on Map
                          </span>
                          <div class="fw-bold text-dark small" id="trans_selected_plot_title">Section A &bull; Plot 1-1</div>
                          <div class="text-muted" style="font-size: 0.78rem;" id="trans_selected_plot_details">Selected Destination Plot</div>
                        </div>
                        <div class="d-flex gap-1.5">
                          <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 rounded-pill" id="btnAdminChangeTransferPlot" style="font-size: 0.8rem;">
                            <i class="fas fa-redo me-1"></i> Change
                          </button>
                          <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 rounded-pill" id="btnAdminClearTransferPlot" style="font-size: 0.8rem;">
                            <i class="fas fa-times me-1"></i> Clear
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="col-12">
                  <label for="trans_reason" class="form-label small fw-semibold">Reason for Transfer / Administrative Remarks <span class="text-danger">*</span></label>
                  <textarea class="form-control form-control-sm" id="trans_reason" name="reason" rows="2" placeholder="State reason (e.g. Family request after 8+ years resting interval, plot consolidation...)" required></textarea>
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
          <a href="logs.php?tab=transfers" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
            <i class="fas fa-history me-1"></i> View Transfer Logs
          </a>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary rounded-pill px-4 fw-semibold" id="btnSubmitTransfer" style="background: #7b1113; border: none;">
              <i class="fas fa-check-circle me-1"></i> Record Transfer
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ======================================================= -->
<!-- MODAL 6: ADD DECEASED RECORD / PLOT PURCHASE -->
<!-- ======================================================= -->
<div class="modal fade" id="addDeceasedModal" tabindex="-1" aria-labelledby="addDeceasedModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-height: 92vh; margin: 1.5rem auto;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;">
      <div class="modal-header py-3 px-4 flex-shrink-0" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff; border-bottom: none;">
        <div class="d-flex align-items-center gap-3">
          <div style="background: rgba(255,255,255,0.15); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;"><i class="fas fa-user-plus text-white"></i></div>
          <div>
            <h5 class="modal-title fw-bold m-0 text-white" id="addDeceasedModalLabel" style="font-size: 1.2rem;">
              Add Deceased Record / Plot Purchase
            </h5>
            <div style="font-size: 0.82rem; color: rgba(255,255,255,0.85);">Register New Occupant or Process Direct Plot Purchase</div>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="addDeceasedForm" class="d-flex flex-column flex-grow-1 overflow-hidden">
        <div class="modal-body p-4" style="background: #f8fafc; overflow-y: auto; flex: 1 1 auto;">
          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important;">
            <div class="card-body p-3">
              <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                  <span class="text-success">📍</span> Grave Plot Location
                </h6>
                <span class="text-muted small">Select an available plot directly from the digital map</span>
              </div>

              <!-- Hidden plot coordinates inputs for form submission -->
              <input type="hidden" id="plot_x" name="plot_x" value="">
              <input type="hidden" id="plot_y" name="plot_y" value="">

              <div class="row g-3 align-items-center mt-0">
                <div class="col-md-4">
                  <label for="plot_section" class="form-label small fw-semibold mb-1">Section <span class="text-danger">*</span></label>
                  <select class="form-select form-select-sm fw-bold" id="plot_section" name="section" required>
                    <option value="A" selected>Section A</option>
                    <option value="B">Section B</option>
                    <option value="C">Section C</option>
                    <option value="D">Section D</option>
                    <option value="E">Section E</option>
                    <option value="F">Section F</option>
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

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important;">
            <div class="card-body p-3">
              <h6 class="fw-bold mb-3 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                <span class="text-danger">👤</span> Deceased Person Details
              </h6>
              <div class="row g-3">
                <div class="col-md-4">
                  <label for="first_name" class="form-label small fw-semibold mb-1">First Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="first_name" name="first_name" required>
                </div>
                <div class="col-md-4">
                  <label for="middle_name" class="form-label small fw-semibold mb-1">Middle Name</label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="middle_name" name="middle_name">
                </div>
                <div class="col-md-4">
                  <label for="last_name" class="form-label small fw-semibold mb-1">Last Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="last_name" name="last_name" required>
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
                  </select>
                </div>
                <div class="col-md-4">
                  <label for="bday" class="form-label small fw-semibold mb-1">Birthday <span class="text-danger">*</span></label>
                  <input type="date" class="form-control form-control-sm" id="bday" name="bday" required>
                </div>
                <div class="col-md-4">
                  <label for="born_in" class="form-label small fw-semibold mb-1">Born In <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="born_in" name="born_in" required>
                </div>
                <div class="col-md-6">
                  <label for="date_died" class="form-label small fw-semibold mb-1">Date Died <span class="text-danger">*</span></label>
                  <input type="date" class="form-control form-control-sm" id="date_died" name="date_died" required>
                </div>
                <div class="col-md-6">
                  <label for="interment_date" class="form-label small fw-semibold mb-1">Burial / Interment Date</label>
                  <input type="date" class="form-control form-control-sm" id="interment_date" name="interment_date">
                </div>
                <div class="col-md-6">
                  <label for="contact_no" class="form-label small fw-semibold mb-1">Contact Number <span class="text-danger">*</span></label>
                  <input type="tel" class="form-control form-control-sm" id="contact_no" name="contact_no" placeholder="09XXXXXXXXX" required>
                </div>
                <div class="col-md-6">
                  <label for="funeral_service" class="form-label small fw-semibold mb-1">Funeral Service <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="funeral_service" name="funeral_service" placeholder="e.g. St. Peter" required>
                </div>
              </div>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important;">
            <div class="card-body p-3">
              <h6 class="fw-bold mb-3 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                <span class="text-warning">💳</span> Client / Buyer Details
              </h6>
              <div class="row g-3">
                <div class="col-md-6">
                  <label for="add_booker_name" class="form-label small fw-semibold mb-1">Client Full Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="add_booker_name" name="booker_name" required>
                </div>
                <div class="col-md-6">
                  <label for="add_buyer_contact" class="form-label small fw-semibold mb-1">Buyer Contact Number</label>
                  <input type="tel" class="form-control form-control-sm" id="add_buyer_contact" name="buyer_contact" placeholder="09XXXXXXXXX">
                </div>
                <div class="col-md-6">
                  <label for="add_total_amount" class="form-label small fw-semibold mb-1">Plot Price (₱) <span class="text-danger">*</span></label>
                  <input type="number" class="form-control form-control-sm" id="add_total_amount" name="total_amount" value="85000" min="1" step="0.01" required>
                </div>
                <div class="col-md-6">
                  <label for="add_payment_status" class="form-label small fw-semibold mb-1">Payment Status</label>
                  <select class="form-select form-select-sm" id="add_payment_status" name="payment_status">
                    <option value="Paid in Full">Paid in Full</option>
                    <option value="Downpayment">Downpayment</option>
                    <option value="Reserved">Reserved</option>
                  </select>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer d-flex justify-content-between px-4 py-3 flex-shrink-0" style="background:#fff; border-top: 1px solid #e2e8f0;">
          <button type="button" class="btn btn-outline-secondary px-4 py-2 fw-semibold rounded-pill" data-bs-dismiss="modal">
            Cancel
          </button>
          <button type="button" class="btn btn-primary px-5 py-2 fw-bold shadow rounded-pill" id="addDecSubmitBtn" onclick="submitAddDeceasedOnRegistry()" style="background: #7b1113; border-color: #7b1113;">
            <i class="fas fa-save me-2"></i> Save Deceased Record
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ======================================================= -->
<!-- MODAL 7: BROADCAST ANNOUNCEMENT MODAL -->
<!-- ======================================================= -->
<?php 
if (file_exists(__DIR__ . '/includes/admin_announcement_modal.php')) {
    include_once __DIR__ . '/includes/admin_announcement_modal.php';
} elseif (file_exists(__DIR__ . '/admin_announcement_modal.php')) {
    include_once __DIR__ . '/admin_announcement_modal.php';
}
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Plus sign options menu toggle
  var plusBtn = document.getElementById('plusOptionsBtn');
  var plusMenu = document.getElementById('plusOptionsMenu');
  if (plusBtn && plusMenu) {
    plusBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      plusMenu.style.display = (plusMenu.style.display === 'block') ? 'none' : 'block';
    });
    document.addEventListener('click', function(e) {
      if (!plusMenu.contains(e.target) && e.target !== plusBtn) {
        plusMenu.style.display = 'none';
      }
    });
  }

  // Handle View button (desktop iframe or mobile modal)
  document.querySelectorAll('.view-popup-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var tr = btn.closest('tr');
      var section = tr ? (tr.getAttribute('data-section') || '') : '';
      var plotX = tr ? (tr.getAttribute('data-plot-x') || '') : '';
      var plotY = tr ? (tr.getAttribute('data-plot-y') || '') : '';
      var firstName = tr ? (tr.getAttribute('data-first-name') || '') : '';
      var middleName = tr ? (tr.getAttribute('data-middle-name') || '') : '';
      var lastName = tr ? (tr.getAttribute('data-last-name') || '') : '';

      var isMobile = window.innerWidth <= 768;

      if (isMobile) {
        document.getElementById('view_full_name').textContent = (firstName + ' ' + middleName + ' ' + lastName).replace(/\s+/g, ' ').trim();
        document.getElementById('view_section').textContent = section;
        document.getElementById('view_plot').textContent = plotX + '-' + plotY;

        window.currentNavigationData = {
          section: section,
          plotX: plotX,
          plotY: plotY,
          firstName: firstName,
          middleName: middleName,
          lastName: lastName
        };

        var viewModal = new bootstrap.Modal(document.getElementById('viewDeceasedModal'));
        viewModal.show();
      } else {
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

  // Mobile navigation button triggers
  var startNavBtn = document.getElementById('startNavigationBtn');
  if (startNavBtn) {
    startNavBtn.addEventListener('click', function() {
      var viewModal = bootstrap.Modal.getInstance(document.getElementById('viewDeceasedModal'));
      if (viewModal) viewModal.hide();
      setTimeout(function() {
        var navModal = new bootstrap.Modal(document.getElementById('navigationModal'));
        navModal.show();
      }, 300);
    });
  }

  var beginNavBtn = document.getElementById('beginNavigationBtn');
  if (beginNavBtn) {
    beginNavBtn.addEventListener('click', function() {
      var data = window.currentNavigationData;
      if (!data) return;
      var navModal = bootstrap.Modal.getInstance(document.getElementById('navigationModal'));
      if (navModal) navModal.hide();

      var mapUrl = 'map.php';
      var params = [];
      if (data.section) params.push('section=' + encodeURIComponent(data.section));
      if (data.plotX) params.push('plot_x=' + encodeURIComponent(data.plotX));
      if (data.plotY) params.push('plot_y=' + encodeURIComponent(data.plotY));
      params.push('navigate=true');
      if (params.length) mapUrl += '?' + params.join('&');
      window.open(mapUrl, '_blank');
    });
  }

  // Populate & open Edit Deceased Modal
  document.querySelectorAll('.edit-deceased-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var m = new bootstrap.Modal(document.getElementById('editDeceasedModal'));
      document.getElementById('edit_deceased_id').value = btn.getAttribute('data-deceased-id') || '';
      document.getElementById('edit_first_name').value = btn.getAttribute('data-first-name') || '';
      document.getElementById('edit_middle_name').value = btn.getAttribute('data-middle-name') || '';
      document.getElementById('edit_last_name').value = btn.getAttribute('data-last-name') || '';

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

      var secBadge = document.getElementById('dashEditDecSectionBadge');
      if (secBadge) secBadge.textContent = 'Section ' + (secVal ? secVal.toUpperCase() : 'A');
      var plotDisp = document.getElementById('dashEditDecPlotDisplay');
      if (plotDisp) plotDisp.textContent = 'Plot Coordinates: Column ' + (pxVal || '-') + ', Row ' + (pyVal || '-');

      m.show();
    });
  });

  // Transfer remains buttons trigger
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
      if (alertBox) alertBox.style.display = 'none';

      const transModal = new bootstrap.Modal(document.getElementById('transferRemainsModal'));
      transModal.show();
    });
  });

  // Transfer Destination Map trigger
  var btnOpenTransMap = document.getElementById('btnAdminOpenTransferMap');
  if (btnOpenTransMap) {
    btnOpenTransMap.addEventListener('click', openTransferDestinationMap);
  }
  var btnChangeTransMap = document.getElementById('btnAdminChangeTransferPlot');
  if (btnChangeTransMap) {
    btnChangeTransMap.addEventListener('click', openTransferDestinationMap);
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

  // Clear iframe src on modal close
  var popupModal = document.getElementById('popupSectionModal');
  if (popupModal) {
    popupModal.addEventListener('hidden.bs.modal', function() {
      var iframe = document.getElementById('popupSectionIframe');
      if (iframe) iframe.src = 'about:blank';
      if (window.__openedFromTransferModal) {
        var transModalEl = document.getElementById('transferRemainsModal');
        if (transModalEl) {
          var tModal = bootstrap.Modal.getOrCreateInstance(transModalEl);
          tModal.show();
        }
        window.__openedFromTransferModal = false;
      } else if (window.__openedFromAddDeceasedModal) {
        var addModalEl = document.getElementById('addDeceasedModal');
        if (addModalEl) {
          var aModal = bootstrap.Modal.getOrCreateInstance(addModalEl);
          aModal.show();
        }
        window.__openedFromAddDeceasedModal = false;
      }
    });
  }

  // Search input typing listener for clear button
  var sInput = document.getElementById('dashboardSearchQ');
  var sClear = document.getElementById('dashboardSearchClearBtn');
  if (sInput && sClear) {
    sInput.addEventListener('input', function() {
      if (this.value.trim().length > 0) {
        sClear.style.display = 'flex';
      } else {
        sClear.style.display = 'none';
      }
    });
  }
});

function openTransferDestinationMap() {
  window.__openedFromTransferModal = true;
  var sec = document.getElementById('trans_dest_section').value || 'A';
  var iframe = document.getElementById('popupSectionIframe');
  var popupModalEl = document.getElementById('popupSectionModal');
  var transModalEl = document.getElementById('transferRemainsModal');

  var adminSectionSel = document.getElementById('adminPopupSectionSelect');
  if (adminSectionSel) adminSectionSel.value = sec;

  var transDecId = document.getElementById('trans_deceased_id') ? document.getElementById('trans_deceased_id').value : '';
  if (iframe) {
    iframe.src = 'sections/popup.php?selection_mode=transfer&transfer_mode=1&section=' + encodeURIComponent(sec) + (transDecId ? '&deceased_id=' + encodeURIComponent(transDecId) : '') + '&source=admin&is_admin=1&t=' + Date.now();
  }

  if (transModalEl) {
    var tModal = bootstrap.Modal.getInstance(transModalEl);
    if (tModal) tModal.hide();
  }

  setTimeout(function() {
    if (popupModalEl) {
      var pModal = bootstrap.Modal.getOrCreateInstance(popupModalEl);
      pModal.show();
    }
  }, 250);
}

function openAddDeceasedMapPicker() {
  window.__openedFromAddDeceasedModal = true;
  window.__openedFromTransferModal = false;
  var secSelect = document.getElementById('plot_section');
  var sec = secSelect ? secSelect.value : 'A';
  var iframe = document.getElementById('popupSectionIframe');
  var popupModalEl = document.getElementById('popupSectionModal');
  var addModalEl = document.getElementById('addDeceasedModal');

  var adminSectionSel = document.getElementById('adminPopupSectionSelect');
  if (adminSectionSel) adminSectionSel.value = sec;

  if (iframe) {
    iframe.src = 'sections/popup.php?selection_mode=booking&section=' + encodeURIComponent(sec) + '&source=admin&is_admin=1&t=' + Date.now();
  }

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
}

window.applySelectedPlotToAddDeceased = function(data) {
  if (!data) return;
  var sec = (data.section || 'A').toUpperCase();
  var px = parseInt(data.plot_x || data.plotX || 1, 10);
  var py = parseInt(data.plot_y || data.plotY || 1, 10);
  var lawnType = data.lawnType || data.lawn_type || 'Regular';
  var status = (data.status || 'AVAILABLE').toUpperCase();

  if (status !== 'AVAILABLE') {
    alert('Section ' + sec + ' Plot ' + px + ',' + py + ' is ' + status + '. Only Available plots can be selected.');
    return;
  }

  var form = document.getElementById('addDeceasedForm');
  if (form) {
    var secSelect = form.querySelector('[name="section"]');
    if (secSelect) secSelect.value = sec;
    var xIn = form.querySelector('[name="plot_x"]');
    if (xIn) xIn.value = px;
    var yIn = form.querySelector('[name="plot_y"]');
    if (yIn) yIn.value = py;
  }

  var noPlotBox = document.getElementById('addDecNoPlotChosen');
  var cardSelected = document.getElementById('addDecSelectedPlotCard');
  var titleEl = document.getElementById('addDecSelectedPlotTitle');
  var subEl = document.getElementById('addDecSelectedPlotSub');

  if (titleEl) titleEl.textContent = 'Section ' + sec + ' • Plot ' + px + ',' + py + ' (Column ' + px + ', Row ' + py + ')';
  if (subEl) subEl.textContent = lawnType + ' Lawn • Available for Assignment';
  if (noPlotBox) noPlotBox.style.display = 'none';
  if (cardSelected) cardSelected.style.display = 'block';

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

// Receive message from map iframe for plot selection
window.addEventListener('message', function(event) {
  var data = event.data;
  if (!data) return;
  if (typeof data === 'string') {
    try { data = JSON.parse(data); } catch(e) {}
  }
  if (data.type === 'plotSelected' || data.action === 'plot_selected' || data.type === 'bookingPlotSelected' || data.type === 'transferPlotSelected') {
    if (window.__openedFromAddDeceasedModal) {
      if (typeof window.applySelectedPlotToAddDeceased === 'function') {
        window.applySelectedPlotToAddDeceased(data);
      }
      return;
    }
    if (window.__openedFromTransferModal) {
      var sec = (data.section || 'A').toUpperCase();
      var px = parseInt(data.plot_x || data.plotX || 1, 10);
      var py = parseInt(data.plot_y || data.plotY || 1, 10);

      document.getElementById('trans_dest_section').value = sec;
      document.getElementById('trans_dest_plot_x').value = px;
      document.getElementById('trans_dest_plot_y').value = py;

      document.getElementById('trans_selected_plot_title').textContent = 'Section ' + sec + ' \u2022 Plot ' + px + '-' + py;
      var sCard = document.getElementById('trans_selected_plot_card');
      var nBox = document.getElementById('trans_no_plot_chosen');
      if (sCard) sCard.style.display = 'block';
      if (nBox) nBox.style.display = 'none';

      var popupModalEl = document.getElementById('popupSectionModal');
      if (popupModalEl) {
        var pModal = bootstrap.Modal.getInstance(popupModalEl);
        if (pModal) pModal.hide();
      }
    }
  }
});

// Submit Add Deceased on Registry
function submitAddDeceasedOnRegistry() {
  const form = document.getElementById('addDeceasedForm');
  if (!form) return;

  const px = form.querySelector('[name="plot_x"]') ? form.querySelector('[name="plot_x"]').value.trim() : '';
  const py = form.querySelector('[name="plot_y"]') ? form.querySelector('[name="plot_y"]').value.trim() : '';
  if (!px || !py || parseInt(px, 10) < 1 || parseInt(py, 10) < 1) {
    alert('Please click "Choose Plot Map" to select an available cemetery plot.');
    return;
  }

  const fn = (document.getElementById('first_name').value || '').trim();
  const ln = (document.getElementById('last_name').value || '').trim();
  const bday = document.getElementById('bday').value;
  const dateDied = document.getElementById('date_died').value;
  const booker = (document.getElementById('add_booker_name').value || '').trim();

  if (!fn || !ln) {
    alert('Please enter First Name and Last Name of the deceased person.');
    return;
  }
  if (!dateDied) {
    alert('Please select the Date Died.');
    return;
  }
  if (!booker) {
    alert('Please enter Client / Buyer Full Name.');
    return;
  }

  const formData = new FormData(form);
  formData.append('ajax_request', 'add_deceased');
  formData.append('record_type', 'with_deceased');

  const submitBtn = document.getElementById('addDecSubmitBtn');
  if (submitBtn) {
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
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
      alert('Deceased person and plot record added successfully!');
      window.location.reload();
    } else {
      alert('Error: ' + (data.message || 'Failed to save record.'));
      if (submitBtn) {
        submitBtn.innerHTML = '<i class="fas fa-save me-2"></i> Save Deceased Record';
        submitBtn.disabled = false;
      }
    }
  })
  .catch(err => {
    console.error(err);
    alert('An unexpected network error occurred.');
    if (submitBtn) {
      submitBtn.innerHTML = '<i class="fas fa-save me-2"></i> Save Deceased Record';
      submitBtn.disabled = false;
    }
  });
}
</script>

<script src="includes/session_tab_guard.js"></script>
<script>
if (window.GorSessionGuard) {
  GorSessionGuard.init('deceased_records');
}
</script>
</body>
</html>

<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$userRole = strtoupper(trim((string)($_SESSION['role'] ?? 'GUEST')));
$isAdmin = ($userRole === 'ADMIN');
$isUser = ($userRole === 'USER');
$isGuest = !$isLoggedIn;
session_write_close();

$move = isset($_GET['move']) ? $_GET['move'] : '';
$deceased_id = isset($_GET['deceased_id']) ? intval($_GET['deceased_id']) : 0;
$service = isset($_GET['service']) ? $_GET['service'] : '';
$booking = isset($_GET['booking']) ? $_GET['booking'] : (isset($_GET['selection_mode']) ? $_GET['selection_mode'] : '');
$moveSuffix = ($move === '1' && $deceased_id > 0) ? ('&move=1&deceased_id=' . $deceased_id) : '';
$serviceSuffix = $service ? ('&service=' . urlencode($service)) : '';
$bookingSuffix = ($booking === '1' || $booking === 'booking') ? '&selection_mode=booking' : '';

$targetSec = isset($_GET['section']) ? strtoupper(trim($_GET['section'])) : '';
$targetPlot = isset($_GET['plot']) ? trim($_GET['plot']) : '';
$targetPx = isset($_GET['plot_x']) ? intval($_GET['plot_x']) : 0;
$targetPy = isset($_GET['plot_y']) ? intval($_GET['plot_y']) : 0;
if ($targetPlot !== '' && ($targetPx === 0 || $targetPy === 0)) {
    if (preg_match('/(\d+)\s*[-_,]\s*(\d+)/', $targetPlot, $m)) {
        $targetPx = intval($m[1]);
        $targetPy = intval($m[2]);
    }
}
if (!empty($_GET['guest_pay_return'])) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header("Location: sections/popup.php?" . $qs);
    exit;
}
if (!empty($targetSec) && $targetPx > 0 && $targetPy > 0 && empty($_GET['no_redirect']) && empty($_GET['highlight_section']) && ($_GET['from'] ?? '') !== 'reservation_logs') {
    $fromParam = (!empty($_GET['from']) && $_GET['from'] !== 'dashboard') ? ('&from=' . urlencode($_GET['from'])) : ($isGuest ? '&from=index' : '&from=map');
    header("Location: sections/popup.php?section=" . urlencode($targetSec) . "&plot_x={$targetPx}&plot_y={$targetPy}&search_highlight=1" . $fromParam);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_service') {
    include_once __DIR__ . '/connection.php';

    $section = $_POST['section'] ?? '';
    $plot_x = $_POST['plot_x'] ?? '';
    $plot_y = $_POST['plot_y'] ?? '';
    $service_type = $_POST['service_type'] ?? '';
    $user_id = $_SESSION['user_id'] ?? 0;

    if ($section && $plot_x && $plot_y && $service_type && $user_id) {

        $stmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('SERVICE_BOOKING', ?, ?, NOW())");
        $details = "Service booked: " . ucfirst($service_type) . " for plot " . $section . "-" . $plot_x . "-" . $plot_y;
        $stmt->bind_param("si", $details, $user_id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Service booked successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error booking service.']);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Missing required information.']);
    }
    exit;
}

if ((isset($_GET['ajax_map_search']) && $_GET['ajax_map_search'] === '1') || (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'map_search')) {
    if (file_exists(__DIR__ . '/map_search.php')) {
        require_once __DIR__ . '/map_search.php';
    } elseif (file_exists(__DIR__ . '/api/map_search.php')) {
        require_once __DIR__ . '/api/map_search.php';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_request'])) {
    session_write_close();
    include_once __DIR__ . '/connection.php';

    if ($_POST['ajax_request'] === 'get_all_deceased_for_navigation') {
        $deceased = [];
        $sql = "SELECT first_name, middle_name, last_name, section, plot_x, plot_y FROM tbl_deceased ORDER BY last_name, first_name";
        $result = $conn->query($sql);

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $deceased[] = [
                    'first_name' => $row['first_name'] ?? '',
                    'middle_name' => $row['middle_name'] ?? '',
                    'last_name' => $row['last_name'] ?? '',
                    'section' => strtoupper($row['section'] ?? ''),
                    'plot_x' => $row['plot_x'] ?? '',
                    'plot_y' => $row['plot_y'] ?? ''
                ];
            }
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'deceased' => $deceased]);
        exit;
    }

    if (in_array($_POST['ajax_request'], ['log_recent_click', 'log_user_recent_click', 'log_admin_recent_click'], true)) {
        include_once __DIR__ . '/connection.php';
        $section = isset($_POST['section']) ? trim($_POST['section']) : '';
        $plot_x = isset($_POST['plot_x']) ? trim($_POST['plot_x']) : '';
        $plot_y = isset($_POST['plot_y']) ? trim($_POST['plot_y']) : '';
        $details = isset($_POST['details']) ? trim($_POST['details']) : '';
        $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
        $role = strtoupper(trim((string)($_SESSION['role'] ?? 'USER')));
        $grave_id = isset($_POST['grave_id']) ? intval($_POST['grave_id']) : null;
        $grave_name = isset($_POST['grave_name']) ? trim($_POST['grave_name']) : '';

        if ($_POST['ajax_request'] === 'log_admin_recent_click' && $role !== 'ADMIN') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        if ($details === '') {
            if ($section !== '' && $plot_x !== '' && $plot_y !== '') {
                $details = "Clicked plot {$section}-{$plot_x}-{$plot_y}";
            } elseif ($section !== '') {
                $details = "Clicked section {$section}";
            } else {
                $details = 'Clicked map area';
            }
        }

        $action = ($plot_x !== '' && $plot_y !== '') ? 'CLICK_PLOT' : 'CLICK_SECTION';
        $stmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param('ssi', $action, $details, $user_id);
            $stmt->execute();
            $stmt->close();
        }

        if ($user_id > 0 && ($grave_id > 0 || ($section !== '' && $plot_x !== '' && $plot_y !== ''))) {
            $dup_stmt = $conn->prepare("DELETE FROM tbl_recent_clicks WHERE user_id = ? AND ((plot_id = ? AND plot_id IS NOT NULL) OR (section = ? AND plot_x = ? AND plot_y = ?))");
            if ($dup_stmt) {
                $dup_stmt->bind_param('iisss', $user_id, $grave_id, $section, $plot_x, $plot_y);
                $dup_stmt->execute();
                $dup_stmt->close();
            }
        }

        if (empty($grave_id) && $section !== '' && $plot_x !== '' && $plot_y !== '') {
            $q = $conn->prepare("SELECT deceased_id, CONCAT(first_name,' ',last_name) AS gname FROM tbl_deceased WHERE section = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
            if ($q) {
                $q->bind_param('sii', $section, $plot_x, $plot_y);
                $q->execute();
                $res = $q->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $grave_id = intval($row['deceased_id']);
                    if (empty($grave_name)) $grave_name = $row['gname'];
                }
                $q->close();
            }
        }

        $resCols = $conn->query("SHOW COLUMNS FROM tbl_recent_clicks");
        $existing = [];
        if ($resCols) {
            while ($rc = $resCols->fetch_assoc()) $existing[$rc['Field']] = true;
        }

        $gname = $grave_name ?: null;
        $details = $details ?: ($gname ? "Clicked plot {$section}-{$plot_x}-{$plot_y} ({$gname})" : "Clicked plot {$section}-{$plot_x}-{$plot_y}");

        if (isset($existing['grave_id'])) {
            $ins = $conn->prepare("INSERT INTO tbl_recent_clicks (user_id, grave_id, grave_name, section, plot_x, plot_y, details, clicked_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            if ($ins) { $ins->bind_param('iisssss', $user_id, $grave_id, $gname, $section, $plot_x, $plot_y, $details); $ins->execute(); $ins->close(); }
        } else {
            $ins = $conn->prepare("INSERT INTO tbl_recent_clicks (user_id, plot_id, plot_code, section, plot_x, plot_y, deceased_name, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            if ($ins) { $plotId = $grave_id ?: null; $plotCode = $grave_name ?: null; $ins->bind_param('iissssss', $user_id, $plotId, $plotCode, $section, $plot_x, $plot_y, $gname, $details); $ins->execute(); $ins->close(); }
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    if (in_array($_POST['ajax_request'], ['get_recent_activity', 'get_user_recently_clicked', 'get_admin_recently_clicked'], true)) {
        include_once __DIR__ . '/connection.php';
        $activities = [];
        $current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
        $role = strtoupper(trim((string)($_SESSION['role'] ?? 'USER')));

        if ($current_user_id <= 0 || ($_POST['ajax_request'] === 'get_admin_recently_clicked' && $role !== 'ADMIN')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'activities' => []]);
            exit;
        }

        $colRes = $conn->query("SHOW COLUMNS FROM tbl_recent_clicks");
        $existing = [];
        if ($colRes) {
            while ($c = $colRes->fetch_assoc()) $existing[$c['Field']] = true;
        }
        $gid = isset($existing['grave_id']) ? 'r.grave_id' : (isset($existing['plot_id']) ? 'r.plot_id' : null);
        $gname = isset($existing['grave_name']) ? 'r.grave_name' : (isset($existing['deceased_name']) ? 'r.deceased_name' : (isset($existing['plot_code']) ? 'r.plot_code' : null));
        $clicked = isset($existing['clicked_at']) ? 'r.clicked_at' : (isset($existing['created_at']) ? 'r.created_at' : null);
        $details_col = isset($existing['details']) ? 'r.details' : null;

        $selectParts = [];
        $selectParts[] = 'r.id';
        $selectParts[] = 'r.user_id';
        $selectParts[] = $gid ? ($gid . ' AS grave_id') : 'NULL AS grave_id';
        $selectParts[] = $gname ? ($gname . ' AS grave_name') : "'' AS grave_name";
        $selectParts[] = 'r.section';
        $selectParts[] = 'r.plot_x';
        $selectParts[] = 'r.plot_y';
        $selectParts[] = $details_col ? ($details_col . ' AS details') : "'' AS details";
        $selectParts[] = $clicked ? ($clicked . ' AS clicked_at') : 'NULL AS clicked_at';
        $selectParts[] = "CONCAT(TRIM(COALESCE(u.first_name,'')),' ',TRIM(COALESCE(u.last_name,''))) AS user_name";

        $select = implode(', ', $selectParts);
        $orderBy = $clicked ? ($clicked . ' DESC') : 'r.id DESC';
        $sql = "SELECT " . $select . " FROM tbl_recent_clicks r LEFT JOIN tbl_user u ON u.user_id = r.user_id WHERE r.user_id = ? ORDER BY " . $orderBy . " LIMIT 30";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $activities[] = [
                        'id' => $r['id'] ?? null,
                        'grave_id' => $r['grave_id'] ?? null,
                        'grave_name' => $r['grave_name'] ?? '',
                        'section' => $r['section'] ?? '',
                        'plot_x' => $r['plot_x'] ?? '',
                        'plot_y' => $r['plot_y'] ?? '',
                        'clicked_at' => $r['clicked_at'] ?? null,
                        'user_id' => $r['user_id'] ?? null,
                        'user_name' => trim($r['user_name'] ?? '')
                    ];
                }
            }
            $stmt->close();
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'activities' => $activities]);
        exit;
    }
}

include_once __DIR__ . '/connection.php';
$counts = [];
$qry = $conn->query("SELECT UPPER(TRIM(section)) AS s, COUNT(*) AS c FROM tbl_deceased GROUP BY UPPER(TRIM(section))");
if ($qry) {
    while ($row = $qry->fetch_assoc()) {
        $s = strtoupper(trim($row['s'] ?? ''));
        $counts[$s] = intval($row['c'] ?? 0);
    }
}

function section_style($count) {

    if ($count > 50) {
        return ['bg' => 'rgba(218, 57, 65, 0.15)', 'bd' => '#da3941'];
    } elseif ($count >= 21) {
        return ['bg' => 'rgba(255, 152, 0, 0.15)', 'bd' => '#ff9800'];
    }
    return ['bg' => 'rgba(76, 175, 80, 0.15)', 'bd' => '#4caf50'];
}
function style_attr($arr) {
    return 'border: 3px solid ' . $arr['bd'] . '; background-color: ' . $arr['bg'] . ';';
}

$sec = ['A','B','C','D','E','F','G','H','I','J'];
$secStyles = [];
foreach ($sec as $s) { $secStyles[$s] = style_attr(section_style($counts[$s] ?? 0)); }

$locked = ['G','H','I','J'];
$staticBlue = ['bg' => 'rgba(33,150,243,0.15)', 'bd' => '#2196f3'];
foreach ($locked as $ls) { $secStyles[$ls] = style_attr($staticBlue); }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Map</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- IE11 / Edge-Legacy polyfills (URLSearchParams, fetch, Promise, etc.) -->
    <script src="includes/ie_polyfills.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="notification_component.css">
    <link rel="stylesheet" href="includes/notification_component.css">
    <style>
        body {
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            background-color: #f3f3f3;
            margin: 0;
            padding: 20px;
            text-align: center;
        }

        .main-map-header {
            box-sizing: border-box;
            width: 100%;
            max-width: 600px;
            margin: 0 auto 0.75rem auto;
            background: #a03a3c;
            color: #fff;
            padding: 0.9rem 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            border-radius: 1.1rem;
        }

        .main-map-header h1 {
            font-size: 1.18rem;
            font-weight: 600;
            margin: 0;
            padding-right: 2.5rem;
            letter-spacing: 0.5px;
            flex: 1 1 auto;
            text-align: left;
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            line-height: 1.3;
        }

        .main-map-header #mapExit {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: 0;
            color: #fff;
            font-size: 1.9rem;
            line-height: 1;
            opacity: .95;
            cursor: pointer;
            text-decoration: none;
            border-radius: 50%;
            transition: opacity 0.2s, background 0.2s;
        }

        .main-map-header #mapExit:hover {
            opacity: 1;
            background: rgba(255,255,255,0.15);
        }

        .map-legend {
            box-sizing: border-box;
            width: 100%;
            max-width: 600px;
            margin: 0 auto 12px auto;
            padding: 8px 14px;
            display: flex;
            gap: 16px;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            flex-wrap: wrap;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .map-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #555;
            white-space: nowrap;
        }

        .map-container {
            position: relative;
            display: inline-block;
            border-radius: 1.25rem;
            border: 2.5px solid #a03a3c;
            box-shadow: 0 2px 16px rgba(160,58,60,0.10);
            background: #fff;
        }

        .map-container img {
            width: 600px;
            height: auto;
            border-radius: 1.1rem;
        }

        .section {
            position: absolute;
            border: 2px solid transparent;
            transition: 0.3s;
            cursor: pointer;
        }

        .section:hover {
            border-width: 3px;
            border-color: inherit;
            box-shadow: 0 0 0 3px rgba(33, 203, 47, 0.25) inset;
        }

/* Navigation Search Styles */
        .navigation-search {
            box-sizing: border-box;
            width: 100%;
            max-width: 600px;
            margin: 0 auto 12px auto;
            background: #ffffff;
            border-radius: 8px;
            padding: 6px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
            position: relative;
            z-index: 10000 !important;
            overflow: visible !important;
        }

        .search-input-wrapper,
        .search-input-group {
            display: flex;
            align-items: center;
            width: 100%;
            box-sizing: border-box;
            background: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            overflow: visible !important;
            position: relative !important;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .search-input-wrapper:focus-within,
        .search-input-group:focus-within {
            border-color: #dc2626;
            box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.1);
        }

        .search-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 10px 0 12px;
            color: #6b7280;
            font-size: 0.88rem;
            flex-shrink: 0;
            background: #ffffff;
        }

        .search-input-large,
        .search-input-control {
            flex: 1 1 auto;
            min-width: 0;
            border: none;
            outline: none;
            padding: 7px 8px;
            font-size: 0.88rem;
            font-family: inherit;
            color: #1f2937;
            background: transparent;
            box-sizing: border-box;
        }

        .search-input-large::placeholder,
        .search-input-control::placeholder {
            color: #9ca3af;
            font-size: 0.85rem;
        }

        .search-action-btn,
        .search-find-btn {
            flex-shrink: 0;
            background: transparent;
            color: #dc2626;
            border: 1.5px solid #dc2626;
            border-radius: 5px;
            padding: 5px 14px;
            font-size: 0.84rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            margin: 3px 4px 3px 0;
            transition: all 0.2s ease;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
        }

        .search-action-btn:hover,
        .search-find-btn:hover {
            background: #dc2626;
            color: #ffffff;
        }

        .btn-map-filter {
            background: transparent;
            border: none;
            outline: none;
            color: #6b7280;
            padding: 7px 12px;
            font-size: 0.95rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: color 0.2s, background-color 0.2s;
            border-right: 1px solid #e5e7eb;
            flex-shrink: 0;
            box-sizing: border-box;
            height: 38px;
        }
        .btn-map-filter:hover {
            color: #7b1113;
            background-color: #f9fafb;
        }
        .filter-active-dot {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 8px;
            height: 8px;
            background-color: #dc2626;
            border-radius: 50%;
            border: 2px solid #ffffff;
            box-shadow: 0 0 4px rgba(220, 38, 38, 0.6);
        }
        .map-filter-popover {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            width: 310px;
            max-width: 95vw;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            padding: 14px;
            z-index: 10050;
            text-align: left;
            box-sizing: border-box;
        }
        .map-filter-popover.show {
            display: block;
            animation: fadeInPopover 0.15s ease-out;
        }
        @keyframes fadeInPopover {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .map-active-chips {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 6px;
            flex-wrap: wrap;
            font-size: 0.8rem;
        }
        .map-active-chips .chip {
            display: inline-flex;
            align-items: center;
            background: #7b1113;
            color: #ffffff;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .map-active-chips .chip-sec {
            background: #0284c7;
        }
        .map-active-chips .chip-clear {
            background: #f1f5f9;
            color: #dc2626;
            cursor: pointer;
            border: 1px solid #cbd5e1;
        }

        .search-results {
            max-height: 380px;
            overflow-y: auto;
            margin-top: 6px;
            border-top: 1px solid #e0e0e0;
            padding-top: 6px;
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 2px);
            background: white;
            border-radius: 0 0 10px 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            z-index: 99999 !important;
            box-sizing: border-box;
            width: 100%;
        }

        .search-result-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background-color 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .search-result-item:hover {
            background-color: #f8f9fa;
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .deceased-info {
            flex: 1;
        }

        .deceased-name {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }

        .deceased-location {
            color: #666;
            font-size: 14px;
            margin-top: 4px;
        }

        .navigate-btn {
            background: #7b1113;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .navigate-btn:hover {
            background: #a03a3c;
        }

        .navigation-directions {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            display: none;
        }

        /* Recently Clicked Toggle Button */
        .recent-activity-toggle {
            position: fixed !important;
            bottom: 22px !important;
            right: 22px !important;
            z-index: 1040 !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
            background: linear-gradient(135deg, #7b1113 0%, #8e1518 100%) !important;
            color: #ffffff !important;
            border: 2px solid rgba(255, 255, 255, 0.25) !important;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.28) !important;
            padding: 10px 18px !important;
            border-radius: 30px !important;
            font-weight: 600 !important;
            font-size: 0.88rem !important;
            cursor: pointer !important;
            opacity: 1 !important;
            visibility: visible !important;
            pointer-events: auto !important;
            font-family: inherit !important;
            text-decoration: none !important;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease !important;
        }

        .recent-activity-toggle:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 22px rgba(0, 0, 0, 0.35) !important;
            background: #a03a3c !important;
            color: #ffffff !important;
        }

        .recent-activity-toggle:active {
            transform: translateY(0) !important;
        }

        @media (max-width: 768px) {
            .recent-activity-toggle {
                bottom: 14px !important;
                right: 14px !important;
                padding: 9px 15px !important;
                font-size: 0.84rem !important;
            }
        }

        .recent-activity-panel {
            display: none !important;
        }

        .rap-item {
            padding: 10px 12px;
            border-radius: 8px;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.15s ease;
            text-align: left;
        }

        .rap-item:hover {
            background: #f8fafc;
        }

        .rap-item:last-child { border-bottom: none; }

        .rap-item .rap-item-text {
            color: #1e293b;
            font-weight: 600;
            font-size: 0.92rem;
            margin-bottom: 3px;
        }
        .rap-item .rap-item-meta {
            color: #64748b;
            font-size: 0.78rem;
        }
        .rap-link {
            display: block;
            color: inherit;
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 4px;
        }
        .rap-btn {
            display: block;
            width: 100%;
            background: transparent;
            border: none;
            padding: 0;
            margin: 0;
            text-align: left;
            color: inherit;
            cursor: pointer;
        }
        .rap-btn:focus { outline: none; box-shadow: 0 0 0 3px rgba(123,17,19,0.12); }

        .direction-step {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .direction-step:last-child {
            margin-bottom: 0;
        }

        .direction-icon {
            width: 24px;
            height: 24px;
            margin-right: 10px;
            background: #7b1113;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .highlighted-section {
            animation: pulse 2s infinite;
            border-color: #ffc107 !important;
            box-shadow: 0 0 20px rgba(255, 193, 7, 0.6) !important;
            z-index: 1000;
        }

        @keyframes sectionSearchPulse {
            0% {
                box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7);
                outline: 4px solid #dc2626;
            }
            50% {
                box-shadow: 0 0 16px 8px rgba(220, 38, 38, 0.5);
                outline: 4px solid #ef4444;
            }
            100% {
                box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7);
                outline: 4px solid #dc2626;
            }
        }

        .search-section-highlight,
        .section.search-section-highlight,
        .section-blink {
            animation: sectionSearchPulse 1.2s infinite ease-in-out !important;
            outline: 4px solid #dc2626 !important;
            outline-offset: 2px !important;
            z-index: 99 !important;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        /* Google Maps-style Navigation */
        .navigation-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 999;
        }

        .navigation-path {
            stroke: #ff0000;
            stroke-width: 6;
            fill: none;
            stroke-dasharray: none;
            filter: drop-shadow(0 0 8px rgba(255, 0, 0, 0.6));
            animation: pathGlow 2s ease-in-out infinite;
        }

        @keyframes pathGlow {
            0%, 100% { 
                stroke: #ff0000; 
                filter: drop-shadow(0 0 8px rgba(255, 0, 0, 0.6));
            }
            50% { 
                stroke: #ff3333; 
                filter: drop-shadow(0 0 12px rgba(255, 51, 51, 0.8));
            }
        }

        .navigation-arrow {
            fill: #ff0000;
            animation: arrowMove 2s ease-in-out infinite;
            filter: drop-shadow(0 0 4px rgba(255, 0, 0, 0.8));
        }

        @keyframes arrowMove {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-3px); }
        }

        /* Walking path dots */
        .walking-dots {
            stroke: #ff0000;
            stroke-width: 3;
            stroke-dasharray: 2, 8;
            animation: dotsMove 1s linear infinite;
            opacity: 0.8;
        }

        @keyframes dotsMove {
            to { stroke-dashoffset: -10; }
        }

        /* Path animation for navigation */
        .path-animation {
            stroke-dasharray: 1000;
            stroke-dashoffset: 1000;
            animation: drawPath 3s ease-in-out forwards;
        }

        @keyframes drawPath {
            to { stroke-dashoffset: 0; }
        }

        .directions-panel {
            position: fixed;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            width: 320px;
            max-height: 70vh;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            z-index: 1001;
            overflow: hidden;
            display: none;
        }

        .directions-header {
            background: #4285f4;
            color: white;
            padding: 20px;
            font-weight: 600;
            font-size: 18px;
        }

        .directions-body {
            max-height: calc(70vh - 140px);
            overflow-y: auto;
        }

        .direction-step {
            padding: 16px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: flex-start;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .direction-step:hover {
            background-color: #f8f9fa;
        }

        .direction-step.active {
            background-color: #e3f2fd;
            border-left: 4px solid #4285f4;
        }

        .direction-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 16px;
            font-weight: bold;
            font-size: 16px;
            flex-shrink: 0;
        }

        .direction-icon.start {
            background: #4caf50;
            color: white;
        }

        .direction-icon.middle {
            background: #ff9800;
            color: white;
        }

        .direction-icon.end {
            background: #f44336;
            color: white;
        }

        .direction-content {
            flex: 1;
        }

        .direction-text {
            font-size: 15px;
            color: #333;
            margin-bottom: 4px;
            line-height: 1.4;
        }

        .direction-distance {
            font-size: 13px;
            color: #666;
        }

        .directions-footer {
            padding: 16px 20px;
            border-top: 1px solid #e0e0e0;
            background: #f8f9fa;
        }

        .close-directions {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-directions:hover {
            background: rgba(255,255,255,0.3);
        }

        .navigation-marker {
            position: absolute;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            z-index: 1000;
            pointer-events: none;
        }

        .navigation-marker.start {
            background: #4caf50;
        }

        .navigation-marker.end {
            background: #f44336;
            animation: markerPulse 2s infinite;
        }

        @keyframes markerPulse {
            0% { transform: scale(1); box-shadow: 0 2px 8px rgba(244,67,54,0.3); }
            50% { transform: scale(1.2); box-shadow: 0 4px 16px rgba(244,67,54,0.6); }
            100% { transform: scale(1); box-shadow: 0 2px 8px rgba(244,67,54,0.3); }
        }

        /* Desktop baseline: keep the original map geometry intact and avoid
           mobile pan/zoom rules from overriding the laptop/PC layout. */
        @media (min-width: 769px) {
            .map-viewport {
                position: relative;
                width: auto;
                height: auto;
                overflow: visible;
                touch-action: auto;
                cursor: default;
            }

            .map-container {
                width: 600px;
                max-width: none;
                border-radius: 1.1rem;
                border-width: 2.5px;
                margin: 0 auto;
                transform: none !important;
                transform-origin: 0 0;
                will-change: auto;
            }

            .map-container img {
                width: 600px;
                height: auto;
                border-radius: 1.1rem;
            }
        }

        /* Mobile Responsive Styles */
        @media (max-width: 768px) {
            body {
                padding: 8px 6px;
                font-size: 14px;
                overflow-x: hidden;
            }

            .main-map-header {
                box-sizing: border-box !important;
                width: 100% !important;
                max-width: 600px !important;
                margin: 0 auto 0.75rem auto !important;
                padding: 0.75rem 1rem !important;
                border-radius: 0.75rem !important;
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                position: relative !important;
                overflow: hidden !important;
                background: #a03a3c !important;
                color: #ffffff !important;
            }

            .main-map-header h1 {
                font-size: clamp(0.95rem, 3.8vw, 1.15rem) !important;
                font-weight: 600 !important;
                margin: 0 !important;
                padding-right: 2.4rem !important;
                line-height: 1.25 !important;
                flex: 1 1 auto !important;
                text-align: left !important;
                word-break: break-word !important;
            }

            .main-map-header #mapExit {
                position: absolute !important;
                right: 10px !important;
                top: 50% !important;
                transform: translateY(-50%) !important;
                width: 32px !important;
                height: 32px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                font-size: 1.75rem !important;
                line-height: 1 !important;
                color: #ffffff !important;
                text-decoration: none !important;
                border-radius: 50% !important;
                z-index: 2 !important;
            }

            .map-viewport {
                position: relative;
                width: 100%;
                max-width: 600px;
                margin: 0 auto;
                overflow: hidden;
                display: flex;
                justify-content: center;
                align-items: flex-start;
                border-radius: 0.75rem;
                touch-action: pan-y;
            }

            .map-container {
                position: relative;
                width: 600px;
                height: 750px;
                margin: 0 auto;
                display: block;
                transform-origin: top center;
                border-radius: 0.75rem;
                border-width: 2px;
                will-change: transform;
            }

            .map-container img {
                width: 600px !important;
                height: 750px !important;
                border-radius: 0.75rem;
                display: block;
            }

            .section {
                border-width: 1.5px;
            }

            .section:hover {
                border-width: 2px;
            }

            /* Mobile Navigation & Search Styles */
            .navigation-search {
                box-sizing: border-box !important;
                width: 100% !important;
                max-width: 600px !important;
                margin: 0 auto 10px auto !important;
                background: #ffffff !important;
                border-radius: 8px !important;
                padding: 6px !important;
                border: 1px solid #e2e8f0 !important;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05) !important;
                position: relative !important;
                z-index: 10000 !important;
            }

            .search-input-wrapper,
            .search-input-group {
                display: flex !important;
                align-items: center !important;
                width: 100% !important;
                box-sizing: border-box !important;
                background: #ffffff !important;
                border: 1px solid #d1d5db !important;
                border-radius: 6px !important;
                overflow: visible !important;
                position: relative !important;
            }

            .btn-map-filter {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 6px 10px !important;
                font-size: 0.95rem !important;
                height: 38px !important;
                min-width: 40px !important;
                cursor: pointer !important;
                border: none !important;
                border-right: 1px solid #e5e7eb !important;
                flex-shrink: 0 !important;
                box-sizing: border-box !important;
                background: transparent !important;
                color: #6b7280 !important;
                touch-action: manipulation !important;
            }

            .map-filter-popover {
                position: absolute !important;
                top: calc(100% + 6px) !important;
                left: 0 !important;
                right: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
                z-index: 100001 !important;
                border-radius: 12px !important;
                box-shadow: 0 12px 32px rgba(0,0,0,0.22) !important;
            }

            .search-icon {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 0 8px 0 10px !important;
                color: #6b7280 !important;
                font-size: 0.85rem !important;
                flex-shrink: 0 !important;
                background: #ffffff !important;
            }

            .search-input-large,
            .search-input-control {
                flex: 1 1 auto !important;
                min-width: 0 !important;
                border: none !important;
                outline: none !important;
                padding: 7px 8px !important;
                font-size: 0.88rem !important;
                font-family: inherit !important;
                color: #1f2937 !important;
                background: transparent !important;
                box-sizing: border-box !important;
            }

            .search-input-large:focus,
            .search-input-control:focus {
                outline: none !important;
                border-color: transparent !important;
                box-shadow: none !important;
            }

            .search-action-btn,
            .search-find-btn {
                flex-shrink: 0 !important;
                background: transparent !important;
                color: #dc2626 !important;
                border: 1.5px solid #dc2626 !important;
                border-radius: 5px !important;
                padding: 5px 12px !important;
                font-size: 0.82rem !important;
                font-weight: 600 !important;
                font-family: inherit !important;
                cursor: pointer !important;
                margin: 2px 3px 2px 0 !important;
                transition: all 0.2s ease !important;
                white-space: nowrap !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                min-height: 32px !important;
                box-sizing: border-box !important;
            }

            .search-action-btn:hover,
            .search-find-btn:hover {
                background: #dc2626 !important;
                color: #ffffff !important;
            }

            .map-legend {
                box-sizing: border-box !important;
                width: 100% !important;
                max-width: 600px !important;
                margin: 0 auto 12px auto !important;
                padding: 8px 12px !important;
                display: flex !important;
                flex-wrap: wrap !important;
                justify-content: center !important;
                align-items: center !important;
                gap: 8px 14px !important;
                background: #ffffff !important;
                border-radius: 8px !important;
                border: 1px solid #e2e8f0 !important;
                box-shadow: 0 1px 4px rgba(0,0,0,0.04) !important;
            }

            .map-legend-item {
                display: inline-flex !important;
                align-items: center !important;
                gap: 6px !important;
                font-size: 0.8rem !important;
                color: #475569 !important;
                line-height: 1.3 !important;
                white-space: nowrap !important;
            }

            .search-results {
                max-height: 340px !important;
                overflow-y: auto !important;
                margin-top: 6px !important;
                border-top: 1px solid #e2e8f0 !important;
                padding: 6px 0 !important;
                position: absolute !important;
                left: 0 !important;
                right: 0 !important;
                top: calc(100% + 2px) !important;
                background: #ffffff !important;
                border-radius: 0 0 12px 12px !important;
                box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15), 0 8px 10px -6px rgba(0,0,0,0.1) !important;
                z-index: 99999 !important;
                box-sizing: border-box !important;
                width: 100% !important;
            }

            .search-result-item {
                padding: 10px 14px;
                border-bottom: 1px solid #f1f5f9;
                cursor: pointer;
                transition: background-color 0.15s ease;
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
            }

            .search-result-item:hover {
                background-color: #f8fafc;
            }

            .search-result-item:last-child {
                border-bottom: none;
            }

            .deceased-info {
                flex: 1;
                min-width: 0;
            }

            .deceased-name {
                font-weight: 700;
                color: #1e293b;
                font-size: 14.5px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .deceased-location {
                color: #64748b;
                font-size: 12.5px;
                margin-top: 3px;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 6px;
            }

            .search-badge-status {
                display: inline-block;
                padding: 1px 6px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }

            .search-badge-occupied { background: #fee2e2; color: #991b1b; }
            .search-badge-reserved { background: #fef3c7; color: #92400e; }
            .search-badge-purchased { background: #e0e7ff; color: #3730a3; }
            .search-badge-available { background: #dcfce7; color: #166534; }

            .navigate-btn {
                background: #7b1113;
                color: #ffffff;
                border: none;
                padding: 7px 13px;
                border-radius: 6px;
                font-size: 12.5px;
                cursor: pointer;
                transition: background-color 0.2s, transform 0.1s;
                font-weight: 600;
                white-space: nowrap;
                flex-shrink: 0;
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }

            .navigate-btn:hover {
                background: #a03a3c;
                transform: translateY(-1px);
            }

            .navigate-btn:active {
                transform: translateY(0);
            }

            .directions-panel {
                position: fixed;
                bottom: 10px;
                left: 10px;
                right: 10px;
                top: auto;
                transform: none;
                width: auto;
                max-height: 40vh;
                background: white;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                z-index: 1001;
                overflow: hidden;
                display: none;
            }

            .directions-header {
                background: #ff0000;
                color: white;
                padding: 15px 20px;
                font-weight: 600;
                font-size: 16px;
                position: relative;
            }

            .directions-body {
                max-height: calc(40vh - 100px);
                overflow-y: auto;
            }

            .direction-step {
                padding: 10px 15px;
                border-bottom: 1px solid #e0e0e0;
                display: flex;
                align-items: flex-start;
                cursor: pointer;
                transition: background-color 0.2s;
            }

            .direction-step:hover {
                background-color: #f8f9fa;
            }

            .direction-step.active {
                background-color: #ffebee;
                border-left: 4px solid #ff0000;
            }

            .direction-icon {
                width: 28px;
                height: 28px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-right: 10px;
                font-weight: bold;
                font-size: 12px;
                flex-shrink: 0;
            }

            .direction-icon.start {
                background: #4caf50;
                color: white;
            }

            .direction-icon.middle {
                background: #ff9800;
                color: white;
            }

            .direction-icon.end {
                background: #f44336;
                color: white;
            }

            .direction-content {
                flex: 1;
            }

            .direction-text {
                font-size: 14px;
                color: #333;
                margin-bottom: 2px;
                line-height: 1.3;
            }

            .direction-distance {
                font-size: 12px;
                color: #666;
            }

            .directions-footer {
                padding: 10px 15px;
                border-top: 1px solid #e0e0e0;
                background: #f8f9fa;
            }

            .close-directions {
                position: absolute;
                top: 12px;
                right: 12px;
                background: rgba(255,255,255,0.2);
                border: none;
                color: white;
                width: 26px;
                height: 26px;
                border-radius: 50%;
                cursor: pointer;
                font-size: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .close-directions:hover {
                background: rgba(255,255,255,0.3);
            }

            .navigation-path {
                stroke: #ff0000;
                stroke-width: 8;
                fill: none;
                stroke-dasharray: none;
                filter: drop-shadow(0 0 12px rgba(255, 0, 0, 0.8));
                animation: pathGlow 2s ease-in-out infinite;
            }

            @keyframes pathGlow {
                0%, 100% {
                    stroke: #ff0000;
                    filter: drop-shadow(0 0 12px rgba(255, 0, 0, 0.8));
                }
                50% {
                    stroke: #ff3333;
                    filter: drop-shadow(0 0 16px rgba(255, 51, 51, 1));
                }
            }

            .navigation-arrow {
                fill: #ff0000;
                animation: arrowMove 2s ease-in-out infinite;
                filter: drop-shadow(0 0 6px rgba(255, 0, 0, 0.8));
            }

            .walking-dots {
                stroke: #ff0000;
                stroke-width: 4;
                stroke-dasharray: 3, 10;
                animation: dotsMove 1s linear infinite;
                opacity: 0.9;
            }

            .navigation-marker {
                position: absolute;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                border: 3px solid white;
                box-shadow: 0 2px 10px rgba(0,0,0,0.4);
                z-index: 1000;
                pointer-events: none;
            }

            .navigation-marker.start {
                background: #4caf50;
            }

            .navigation-marker.end {
                background: #f44336;
                animation: markerPulse 2s infinite;
            }

            .navigation-marker.current-location {
                background: #4caf50;
                animation: locationPulse 2s infinite;
            }

            @keyframes locationPulse {
                0% {
                    transform: scale(1);
                    box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.7);
                }
                70% {
                    transform: scale(1.2);
                    box-shadow: 0 0 0 10px rgba(76, 175, 80, 0);
                }
                100% {
                    transform: scale(1);
                    box-shadow: 0 0 0 0 rgba(76, 175, 80, 0);
                }
            }

            .navigation-menu-toggle:hover {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
            }

            .navigation-menu-toggle svg {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
            }

            .navigation-menu-toggle.active {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
            }

            .navigation-menu-toggle.active:hover {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
            }

            .navigation-menu {
                position: fixed;
                bottom: 90px;
                right: 20px;
                width: 320px;
                max-height: 60vh;
                background: white;
                border-radius: 12px;
                box-shadow: 0 8px 32px rgba(0,0,0,0.3);
                z-index: 1001;
                overflow: hidden;
                display: none;
                transform-origin: bottom right;
            }

            .navigation-menu.show {
                display: block;
                animation: menuSlideUp 0.3s ease-out;
            }

            @keyframes menuSlideUp {
                from {
                    opacity: 0;
                    transform: scale(0.8) translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: scale(1) translateY(0);
                }
            }

            .navigation-menu-header {
                background: #4caf50;
                color: white;
                padding: 15px 20px;
                font-weight: 600;
                font-size: 16px;
                position: relative;
            }

            .navigation-menu-body {
                max-height: calc(60vh - 120px);
                overflow-y: auto;
            }

            .navigation-menu-footer {
                padding: 12px 20px;
                border-top: 1px solid #e0e0e0;
                background: #f8f9fa;
            }

            .menu-close-btn {
                position: absolute;
                top: 12px;
                right: 12px;
                background: rgba(255,255,255,0.2);
                border: none;
                color: white;
                width: 26px;
                height: 26px;
                border-radius: 50%;
                cursor: pointer;
                font-size: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .menu-close-btn:hover {
                background: rgba(255,255,255,0.3);
            }

            .navigation-search {
                display: block !important;
                visibility: visible !important;
                height: auto !important;
                overflow: visible !important;
            }

            .navigation-menu-toggle {
                display: flex !important;
            }

            .navigation-menu {
                display: none !important;
                width: 90vw !important;
                max-width: 320px !important;
                right: 5vw !important;
                bottom: 80px !important;
            }

            .navigation-menu.show {
                display: block !important;
                animation: menuSlideUp 0.3s ease-out;
            }

            .navigation-menu-header {
                padding: 15px 20px !important;
                font-size: 16px !important;
            }

            .navigation-menu-body {
                padding: 15px 20px !important;
                max-height: 40vh !important;
                font-size: 14px !important;
            }

            .navigation-menu-footer {
                padding: 15px 20px !important;
            }

            .menu-close-btn {
                width: 30px !important;
                height: 30px !important;
                font-size: 18px !important;
            }

            .sidebar {
                display: none;
            }

            .content {
                width: 100%;
            }

            .card {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }

            .content {
                width: 100%;
            }

            .card {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="main-map-header">
    <h1>Garden Of Remembrance Map</h1>
    <?php
    $mapExitUrl = 'index.php';
    if (($_GET['from'] ?? '') === 'reservation_logs') {
        $mapExitUrl = 'logs.php';
    } elseif ($isGuest || ($_GET['from'] ?? '') === 'index') {
        $mapExitUrl = 'index.php';
    } elseif ($isAdmin || ($_GET['from'] ?? '') === 'dashboard') {
        $mapExitUrl = 'dashboard.php';
    } else {
        $mapExitUrl = 'user_dashboard.php';
    }
    ?>
    <a href="<?php echo htmlspecialchars($mapExitUrl); ?>" id="mapExit" title="<?php echo (($_GET['from'] ?? '') === 'reservation_logs') ? 'Back to Reservation Logs' : 'Close Map'; ?>" aria-label="Close Map">&times;</a>
</div>

<div class="navigation-search" id="mapNavSearch">
    <div class="search-input-wrapper search-input-group" style="position: relative;">

        <button type="button" class="btn-map-filter" id="mapFilterBtn" onclick="toggleMapFilterPopover(event)" ontouchstart="toggleMapFilterPopover(event)" title="Filter by Year, Previous Dates, &amp; Section">
            <i class="fas fa-sliders-h"></i>
            <span id="mapFilterActiveDot" class="filter-active-dot" style="display:none;"></span>
        </button>

        <div class="map-filter-popover" id="mapFilterPopover" onclick="event.stopPropagation()" ontouchstart="event.stopPropagation()" onpointerdown="event.stopPropagation()">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                <span style="font-weight: 700; color: #1e293b; font-size: 0.88rem;"><i class="fas fa-filter text-danger" style="color:#7b1113; margin-right: 4px;"></i> Filter Deceased</span>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <button type="button" onclick="clearMapFilters()" style="background: none; border: none; color: #dc2626; font-size: 0.78rem; cursor: pointer; padding: 0; font-weight: 600;">Reset</button>
                    <button type="button" onclick="toggleMapFilterPopover(event)" style="background: none; border: none; color: #94a3b8; font-size: 1.15rem; line-height: 1; cursor: pointer; padding: 0 4px;" aria-label="Close Filter">&times;</button>
                </div>
            </div>

            <div style="margin-bottom: 10px;">
                <label style="display: block; font-size: 0.78rem; font-weight: 700; color: #334155; margin-bottom: 4px;" for="mapFilterYear">
                    <i class="fas fa-calendar-alt" style="color: #7b1113; margin-right: 4px;"></i> Year of Death / Burial:
                </label>
                <input type="number" id="mapFilterYear" placeholder="e.g. 2023" min="1900" max="2099" style="width: 100%; box-sizing: border-box; padding: 5px 8px; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                <div style="display: flex; gap: 4px; margin-top: 4px; flex-wrap: wrap;">
                    <?php 
                    $curYr = (int)date('Y');
                    foreach ([$curYr, $curYr-1, $curYr-2, $curYr-3, $curYr-5] as $qy): 
                    ?>
                        <button type="button" style="background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 999px; font-size: 0.72rem; padding: 1px 7px; cursor: pointer; color: #475569;" onclick="document.getElementById('mapFilterYear').value='<?= $qy ?>'; applyMapFilters();"><?= $qy ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="margin-bottom: 10px;">
                <label style="display: block; font-size: 0.78rem; font-weight: 700; color: #334155; margin-bottom: 4px;">
                    <i class="fas fa-history" style="color: #64748b; margin-right: 4px;"></i> Check Previous Dates:
                </label>
                <div style="display: flex; gap: 6px;">
                    <div style="flex: 1;">
                        <span style="font-size: 0.7rem; color: #64748b; display: block;">From:</span>
                        <input type="date" id="mapFilterDateFrom" style="width: 100%; box-sizing: border-box; padding: 4px 6px; font-size: 0.78rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>
                    <div style="flex: 1;">
                        <span style="font-size: 0.7rem; color: #64748b; display: block;">To:</span>
                        <input type="date" id="mapFilterDateTo" style="width: 100%; box-sizing: border-box; padding: 4px 6px; font-size: 0.78rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 0.78rem; font-weight: 700; color: #334155; margin-bottom: 4px;" for="mapFilterSection">
                    <i class="fas fa-layer-group" style="color: #7b1113; margin-right: 4px;"></i> Section:
                </label>
                <select id="mapFilterSection" style="width: 100%; box-sizing: border-box; padding: 5px 8px; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff;">
                    <option value="">All Sections</option>
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

            <button type="button" onclick="applyMapFilters();" style="width: 100%; background: #7b1113; color: #ffffff; border: none; border-radius: 999px; padding: 6px 12px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: background 0.2s;">
                <i class="fas fa-check me-1"></i> Apply Filter
            </button>
        </div>

        <input type="text" id="deceasedSearch" class="search-input-large search-input-control" placeholder="Search by deceased name, owner, plot (e.g. A 51-1), or section..." autocomplete="off" oninput="if(typeof window.handleCemeterySearchInput==='function'){window.handleCemeterySearchInput(this.value);}" onkeydown="if(event.key==='Enter'){event.preventDefault();if(typeof window.triggerCemeteryPlotSearch==='function'){window.triggerCemeteryPlotSearch(false);}}">

        <button type="button" id="mapClearSearchBtn" title="Clear search" onclick="if(typeof window.clearMapSearch==='function'){window.clearMapSearch();}else if(window.cemeteryNavigation){window.cemeteryNavigation.clearSearch();}" style="display: none; background: none; border: none; color: #9ca3af; padding: 0 8px; cursor: pointer; font-size: 0.9rem;">
            <i class="fas fa-times"></i>
        </button>


    </div>

    <div id="mapActiveFilterChips" class="map-active-chips" style="display:none;"></div>

    <div id="searchResults" class="search-results" style="display:none;"></div>
    <div id="navigationDirections" class="navigation-directions"></div>
</div>

<div class="map-legend">
    <div class="map-legend-item">
        <span style="display:inline-block;width:14px;height:14px;border-radius:4px;background:rgba(76, 175, 80, 0.15);border:2px solid #4caf50;"></span>
        <span>0-20 Partially Occupied (Green)</span>
    </div>
    <div class="map-legend-item">
        <span style="display:inline-block;width:14px;height:14px;border-radius:4px;background:rgba(255, 152, 0, 0.15);border:2px solid #ff9800;"></span>
        <span>21–50 Slightly Full (Orange)</span>
    </div>
    <div class="map-legend-item">
        <span style="display:inline-block;width:14px;height:14px;border-radius:4px;background:rgba(218, 57, 65, 0.15);border:2px solid #da3941;"></span>
        <span>51–100 Full (Red)</span>
    </div>
</div>

<script>
// Map exit button: prefer returning to reservation logs if coming from reservation logs,
// otherwise fall back to appropriate dashboard based on server-side session role.
document.addEventListener('DOMContentLoaded', function(){
    var btn = document.getElementById('mapExit');
    if (!btn) return;
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('from') === 'reservation_logs') {
        try { sessionStorage.setItem('from_reservation_logs', '1'); } catch(e) {}
        try { sessionStorage.setItem('active_logs_tab', 'reservation'); } catch(e) {}
        btn.setAttribute('title', 'Back to Reservation Logs');
    }
    btn.addEventListener('click', function(e){
        e.preventDefault();
        var params = new URLSearchParams(window.location.search);
        if (params.get('from') === 'reservation_logs' || (window.sessionStorage && window.sessionStorage.getItem('from_reservation_logs') === '1')) {
            try { sessionStorage.removeItem('from_reservation_logs'); } catch(e) {}
            try { sessionStorage.setItem('active_logs_tab', 'reservation'); } catch(e) {}
            window.location.href = 'logs.php?tab=reservation';
            return;
        }
        var isGuest = <?php echo $isGuest ? 'true' : 'false'; ?>;
        var isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        if (isGuest || params.get('from') === 'index') {
            window.location.href = 'index.php';
            return;
        }
        if (isAdmin || params.get('from') === 'dashboard') {
            window.location.href = 'dashboard.php';
            return;
        }
        window.location.href = 'user_dashboard.php';
    });
});
</script>

<div class="map-viewport" id="mapViewport">
<div class="map-container">
        <img src="cmi_map.png" alt="Cemetery Map" style="width:600px;height:auto;border-radius:1.1rem;">

        <script>
        document.addEventListener('DOMContentLoaded', function() {
          try {
            var url = new URL(window.location.href);
            var sec = (url.searchParams.get('section') || '').toUpperCase();
            var px = url.searchParams.get('plot_x');
            var py = url.searchParams.get('plot_y');

            var links = document.querySelectorAll('.map-container a[href*="sections/popup.php?section="]');
            if (px && py) {
              links.forEach(function(a) {
                try {
                  var u = new URL(a.href, window.location.origin);
                  u.searchParams.set('plot_x', px);
                  u.searchParams.set('plot_y', py);
                  a.href = u.pathname + '?' + u.searchParams.toString();
                } catch (e) {}
              });
            }

            if (!sec) return;
            var safeSec = (window.CSS && typeof window.CSS.escape === 'function') ? window.CSS.escape(sec) : String(sec).replace(/["\\]/g, '\\$&');
            var target = document.querySelector('.map-container a[href*="section=' + safeSec + '"] .section');
            if (!target) {
              var all = document.querySelectorAll('.map-container .section[title]');
              all.forEach(function(d){ if ((d.getAttribute('title')||'').toUpperCase().indexOf('SECTION ' + sec) !== -1) { target = d; } });
            }
            if (!target) return;

            var style = document.createElement('style');
            style.textContent = '@keyframes secBlink{0%{box-shadow:0 0 0 0 rgba(255,193,7,.0), inset 0 0 0 0 rgba(255,193,7,.0);}50%{box-shadow:0 0 0 10px rgba(255,193,7,.35), inset 0 0 0 3px rgba(255,193,7,.65);}100%{box-shadow:0 0 0 0 rgba(255,193,7,.0), inset 0 0 0 0 rgba(255,193,7,.0);}} .section-blink{animation:secBlink 1.2s ease-in-out infinite;}';
            document.head.appendChild(style);
            target.classList.remove('section-blink'); void target.offsetWidth; target.classList.add('section-blink');

            try {
              var container = document.querySelector('.map-container');
              if (container) {
                var crect = container.getBoundingClientRect();
                var trect = target.getBoundingClientRect();
                var centerX = trect.left - crect.left + (trect.width / 2);
                var topY = trect.top - crect.top; // top edge
                // Remove any existing white section marker if present
                var existingSecPin = document.getElementById('sectionMarker');
                if (existingSecPin && existingSecPin.parentNode) { existingSecPin.parentNode.removeChild(existingSecPin); }
              }
            } catch (e) {}
          } catch (e) {}
        });
        </script>

        <script>
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/[&<>"']/g, function(s) {
                return ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                })[s];
            });
        }
        window.escapeHtml = escapeHtml;

        function getCemeteryInputVal(id) {
            var el = document.getElementById(id);
            return (el && el.value) ? String(el.value).trim() : '';
        }
        function safeEscapeSec(sec) {
            if (!sec) return '';
            if (window.CSS && typeof window.CSS.escape === 'function') {
                return window.CSS.escape(sec);
            }
            return String(sec).replace(/["\\]/g, '\\$&');
        }

        class GoogleMapsNavigation {
            constructor() {
                this.searchInput = document.getElementById('deceasedSearch');
                this.searchResults = document.getElementById('searchResults');
                this.mapContainer = document.querySelector('.map-container');
                this.currentNavigation = null;
                this.deceasedData = [];
                this.searchTimeout = null;
                this.userLocation = null;
                this.locationMarker = null;
                this.locationTrackingInterval = null;

                this.init();
            }

            init() {
                if (this.searchInput) {
                    this.setupSearch();
                    this.loadDeceasedData();
                }
                // Only initialize navigation overlay on mobile (GPS tracking removed per user request)
                if (window.innerWidth <= 768) {
                    this.setupNavigationOverlay();
                }

                // Listen for window resize to toggle navigation
                window.addEventListener('resize', () => {
                    this.handleResize();
                });
            }

            async requestUserLocation() {
                // Check if geolocation is supported
                if (!navigator.geolocation) {
                    console.log('Geolocation is not supported by this browser');
                    this.showLocationFallback();
                    return;
                }

                // Try to get current position with better error handling
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        this.handleLocationSuccess(position);
                    },
                    (error) => {
                        this.handleLocationError(error);
                    },
                    {
                        enableHighAccuracy: false, // Try without high accuracy first
                        timeout: 5000,
                        maximumAge: 60000 // Allow cached location up to 1 minute old
                    }
                );
            }

            handleLocationError(error) {
                console.error('Error getting location:', error);

                // Try again with different settings
                if (error.code === error.PERMISSION_DENIED) {
                    // User denied permission, show fallback
                    this.showLocationFallback();
                    return;
                }

                // Try again with less strict settings
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        this.handleLocationSuccess(position);
                    },
                    (retryError) => {
                        console.error('Retry failed:', retryError);
                        this.showLocationFallback();
                    },
                    {
                        enableHighAccuracy: false,
                        timeout: 10000,
                        maximumAge: 300000 // 5 minutes cached
                    }
                );
            }

            showLocationFallback() {
                // Show user-friendly message and continue without location
                this.userLocation = null;
                this.updateSearchInterface();

                // Show subtle notification instead of error
                const notification = document.createElement('div');
                notification.style.cssText = `
                    position: fixed;
                    top: 70px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: #2196f3;
                    color: white;
                    padding: 10px 16px;
                    border-radius: 6px;
                    font-size: 13px;
                    z-index: 10000;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                    max-width: 280px;
                    text-align: center;
                `;
                notification.textContent = 'Using entrance as starting point (Location unavailable)';
                document.body.appendChild(notification);

                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 3000);
            }

            handleLocationSuccess(position) {
                this.userLocation = {
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy: position.coords.accuracy,
                    timestamp: new Date().getTime()
                };

                console.log('Location found:', this.userLocation);
                this.addLocationMarker();
                this.updateSearchInterface();
            }

            addLocationMarker() {
                // Remove existing location marker
                if (this.locationMarker && this.locationMarker.parentNode) {
                    this.locationMarker.parentNode.removeChild(this.locationMarker);
                }

                // Create green location marker
                this.locationMarker = document.createElement('div');
                this.locationMarker.className = 'navigation-marker current-location';
                this.locationMarker.style.cssText = `
                    position: absolute;
                    width: 32px;
                    height: 32px;
                    border-radius: 50%;
                    border: 4px solid white;
                    background: #4caf50;
                    box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.7);
                    z-index: 1001;
                    pointer-events: none;
                    animation: locationPulse 2s infinite;
                `;

                // Add location icon
                this.locationMarker.innerHTML = `
                    <svg style="width: 20px; height: 20px; margin: 6px; fill: white;" viewBox="0 0 24 24">
                        <path d="M12 2C8.13 2 5 4.87 5 8c-1.1 0-2.9-.5-4.2-1.4L12 6.09l5.58-5.59C16.9 4.87 19 7.13 19 11s-2.1 6.13-5.58 7.41L12 22l-6.42-6.42C5.1 14.87 2.13 12.87 2.13 8s2.87-6.87 6.42-7.41L12 6.09z"/>
                    </svg>
                `;

                this.mapContainer.appendChild(this.locationMarker);

                // Position marker at user's actual location
                this.updateLocationMarkerPosition();

                // Start real-time location tracking
                this.startRealTimeTracking();
            }

            startRealTimeTracking() {
                // Update location every 5 seconds
                this.locationTrackingInterval = setInterval(() => {
                    this.updateRealTimeLocation();
                }, 5000);
            }

            updateRealTimeLocation() {
                if (!navigator.geolocation) return;

                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        this.userLocation = {
                            latitude: position.coords.latitude,
                            longitude: position.coords.longitude,
                            accuracy: position.coords.accuracy,
                            timestamp: new Date().getTime()
                        };

                        this.updateLocationMarkerPosition();
                        this.updateNavigationPath();
                    },
                    (error) => {
                        console.log('Location update failed:', error);
                    },
                    {
                        enableHighAccuracy: false,
                        timeout: 3000,
                        maximumAge: 10000
                    }
                );
            }

            updateLocationMarkerPosition() {
                if (!this.locationMarker || !this.userLocation) return;

                // Convert GPS coordinates to map coordinates
                // This is a simplified conversion - in real implementation you'd use proper mapping
                const containerRect = this.mapContainer.getBoundingClientRect();

                // Map GPS coordinates to map pixels (simplified for demo)
                // In real implementation, you'd use the actual cemetery GPS bounds
                const mapLatRange = { min: 14.5, max: 14.6 }; // Example latitude range
                const mapLngRange = { min: 120.9, max: 121.0 }; // Example longitude range

                const latPercent = (this.userLocation.latitude - mapLatRange.min) / (mapLatRange.max - mapLatRange.min);
                const lngPercent = (this.userLocation.longitude - mapLngRange.min) / (mapLngRange.max - mapLngRange.min);

                // Convert to pixel coordinates
                const pixelX = containerRect.width * lngPercent;
                const pixelY = containerRect.height * latPercent;

                // Ensure marker stays within bounds
                const finalX = Math.max(16, Math.min(containerRect.width - 16, pixelX));
                const finalY = Math.max(16, Math.min(containerRect.height - 16, pixelY));

                this.locationMarker.style.left = (finalX - 16) + 'px';
                this.locationMarker.style.top = (finalY - 16) + 'px';
            }

            updateNavigationPath() {
                // Update navigation path if currently navigating
                if (this.currentNavigation) {
                    this.drawNavigationPath();
                }
            }

            updateSearchInterface() {
                // Update search placeholder to show location status
                if (this.userLocation) {
                    this.searchInput.placeholder = 'Search Deceased Name/Plots/Reservation Name (GPS ready)';
                } else {
                    this.searchInput.placeholder = 'Search Deceased Name/Plots/Reservation Name';
                }
            }

            handleResize() {
                if (window.innerWidth <= 768) {
                    // Switch to mobile mode
                    if (!this.searchInput.hasAttribute('data-mobile-initialized')) {
                        this.setupSearch();
                        this.setupNavigationOverlay();
                        this.searchInput.setAttribute('data-mobile-initialized', 'true');
                    }
                } else {
                    // Switch to desktop mode - hide navigation
                    this.hideMobileNavigation();
                }
            }

            hideMobileNavigation() {
                // Hide directions panel
                const directionsPanel = document.getElementById('directionsPanel');
                if (directionsPanel) {
                    directionsPanel.style.display = 'none';
                }

                // Clear any active navigation
                this.clearNavigation();
            }

            setupNavigationOverlay() {
                // Create navigation overlay SVG
                const overlay = document.createElement('div');
                overlay.className = 'navigation-overlay';
                overlay.innerHTML = `
                    <svg style="position: absolute; width: 100%; height: 100%; pointer-events: none;">
                        <defs>
                            <marker id="arrowhead" markerWidth="12" markerHeight="8" refX="10" refY="4" orient="auto">
                                <polygon points="0 0, 12 4, 0 8" class="navigation-arrow" />
                            </marker>
                        </defs>
                        <g id="navigationPaths"></g>
                        <g id="walkingDots"></g>
                    </svg>
                `;
                this.mapContainer.appendChild(overlay);

                // Shield buttons removed per user request - kept only the functionality
                // this.createMenuToggle();  // DISABLED
                // this.createNavigationMenu();  // DISABLED
            }

            createMenuToggle() {
                const toggleBtn = document.createElement('button');
                toggleBtn.className = 'navigation-menu-toggle';
                toggleBtn.id = 'navigationMenuToggle';
                toggleBtn.innerHTML = `
                    <svg viewBox="0 0 24 24">
                        <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z"/>
                    </svg>
                `;
                toggleBtn.onclick = () => this.toggleNavigationMenu();
                document.body.appendChild(toggleBtn);
            }

            createNavigationMenu() {
                const menu = document.createElement('div');
                menu.className = 'navigation-menu';
                menu.id = 'navigationMenu';
                menu.innerHTML = `
                    <div class="navigation-menu-header">
                        Navigation Directions
                        <button class="menu-close-btn" onclick="if(window.cemeteryNavigation){window.cemeteryNavigation.closeNavigationMenu();}">×</button>
                    </div>
                    <div class="navigation-menu-body" id="navigationMenuBody"></div>
                    <div class="navigation-menu-footer">
                        <div style="text-align: center; padding: 10px; color: #666; font-size: 0.9rem;">
                            Google Maps opened in new tab for walking directions
                        </div>
                    </div>
                `;
                document.body.appendChild(menu);
            }

            toggleNavigationMenu() {
                const menu = document.getElementById('navigationMenu');
                const toggleBtn = document.getElementById('navigationMenuToggle');
                if (!menu) return;

                if (menu.classList.contains('show')) {
                    menu.classList.remove('show');
                    if (toggleBtn) toggleBtn.classList.remove('active');
                } else {
                    menu.classList.add('show');
                    if (toggleBtn) toggleBtn.classList.add('active');
                }
            }

            closeNavigationMenu() {
                const menu = document.getElementById('navigationMenu');
                const toggleBtn = document.getElementById('navigationMenuToggle');

                if (menu) menu.classList.remove('show');
                if (toggleBtn) toggleBtn.classList.remove('active');
            }

            setupSearch() {
                if (this._searchSetupDone) return;
                this._searchSetupDone = true;

                if (this.searchInput) {
                    this.searchInput.addEventListener('input', (e) => {
                        if (typeof window.handleCemeterySearchInput === 'function') {
                            window.handleCemeterySearchInput(e.target.value);
                        } else {
                            this.searchDeceased(e.target.value, false);
                        }
                    });
                    this.searchInput.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            if (typeof window.triggerCemeteryPlotSearch === 'function') {
                                window.triggerCemeteryPlotSearch(false);
                            } else {
                                this.searchDeceased(this.searchInput.value, false);
                            }
                        } else if (e.key === 'Escape') {
                            this.hideResults();
                        }
                    });
                }

                // Close search dropdown on click outside
                document.addEventListener('click', (e) => {
                    if (!e.target.closest('.navigation-search') && !e.target.closest('#deceasedSearch') && !e.target.closest('#searchResults')) {
                        this.hideResults();
                    }
                });
            }

            async loadDeceasedData() {
                try {
                    this.deceasedData = await this.fetchDeceasedFromDatabase();
                } catch (error) {
                    console.error('Error loading deceased data:', error);
                }
            }

            async fetchDeceasedFromDatabase() {
                try {
                    const response = await fetch('map.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'ajax_request=get_all_deceased_for_navigation'
                    });

                    if (response.ok) {
                        const data = await response.json();
                        return data.success ? data.deceased : [];
                    }
                } catch (error) {
                    console.error('Fetch error:', error);
                }
                return [];
            }

            async searchDeceased(query, navigateIfSingle) {
                const searchTerm = (query !== undefined ? query : (this.searchInput ? this.searchInput.value : '')).trim();
                const yr = getCemeteryInputVal('mapFilterYear');
                const dFrom = getCemeteryInputVal('mapFilterDateFrom');
                const dTo = getCemeteryInputVal('mapFilterDateTo');
                const sec = getCemeteryInputVal('mapFilterSection').toUpperCase();
                const hasFilter = (yr !== '' || dFrom !== '' || dTo !== '' || sec !== '');

                if (searchTerm.length < 1 && !hasFilter) {
                    this.hideResults();
                    return;
                }

                if (this.searchResults) {
                    this.searchResults.innerHTML = '<div style="padding: 14px; text-align: center; color: #64748b; font-size: 0.9rem;"><i class="fas fa-spinner fa-spin me-2"></i> Searching database for deceased records...</div>';
                    this.searchResults.style.display = 'block';
                }

                let qs = '?q=' + encodeURIComponent(searchTerm);
                if (yr) qs += '&year=' + encodeURIComponent(yr);
                if (dFrom) qs += '&date_from=' + encodeURIComponent(dFrom);
                if (dTo) qs += '&date_to=' + encodeURIComponent(dTo);
                if (sec) qs += '&section=' + encodeURIComponent(sec);

                try {
                    const basePath = (window.location.pathname.indexOf('/Admin/') !== -1 || window.location.pathname.indexOf('/User/') !== -1 || window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '';
                    let data = null;
                    const searchUrlCandidates = [
                        basePath + 'map_search.php' + qs,
                        basePath + 'api/map_search.php' + qs,
                        basePath + 'map.php' + qs + '&ajax_map_search=1'
                    ];

                    for (const sUrl of searchUrlCandidates) {
                        try {
                            const response = await fetch(sUrl, {
                                headers: { 'Accept': 'application/json' },
                                credentials: 'same-origin'
                            });
                            if (!response.ok) continue;
                            const resText = await response.text();
                            let cleanText = (resText || '').trim();
                            const firstBrace = cleanText.indexOf('{');
                            const lastBrace = cleanText.lastIndexOf('}');
                            if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
                                cleanText = cleanText.substring(firstBrace, lastBrace + 1);
                            }
                            if (cleanText.indexOf('{') === 0 || cleanText.indexOf('[') === 0) {
                                const parsed = JSON.parse(cleanText);
                                if (parsed && parsed.success !== undefined) {
                                    data = parsed;
                                    break;
                                }
                            }
                        } catch (endpointErr) {
                            // Try next candidate
                        }
                    }

                    if (data && data.success && Array.isArray(data.results)) {
                        this.displayResults(data.results, navigateIfSingle);
                    } else {
                        this.displayResults([], false);
                    }
                } catch (err) {
                    console.error('Search error:', err);
                    if (this.searchResults) {
                        this.searchResults.innerHTML = '<div style="padding: 16px; text-align: center; color: #64748b; font-size: 0.92rem;"><i class="fas fa-info-circle me-1 text-muted"></i> No deceased record or grave plot found for your search.</div>';
                        this.searchResults.style.display = 'block';
                    }
                }
            }

            displayResults(results, navigateIfSingle) {
                if (!this.searchResults) return;
                const basePath = (window.location.pathname.indexOf('/Admin/') !== -1 || window.location.pathname.indexOf('/User/') !== -1 || window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '';

                // Clear previous section highlights on cemetery map and restore original hrefs
                document.querySelectorAll('.map-container .section.search-section-highlight, .map-container .section.section-blink').forEach(el => {
                    el.classList.remove('search-section-highlight', 'section-blink');
                });
                document.querySelectorAll('.map-container a[data-original-href]').forEach(link => {
                    link.setAttribute('href', link.dataset.originalHref);
                    delete link.dataset.originalHref;
                });

                if (!results || results.length === 0) {
                    this.searchResults.innerHTML = `
                        <div style="padding: 16px; text-align: center; color: #64748b; font-size: 0.92rem; line-height: 1.5;">
                            <i class="fas fa-info-circle me-1 text-muted"></i> No deceased record or grave plot found for your search.
                        </div>
                    `;
                    this.searchResults.style.display = 'block';
                    return;
                }

                const count = results.length;
                const firstItem = results[0];
                const firstSec = (firstItem.section || '').toUpperCase();
                const firstPid = firstItem.plot_id || 0;
                const firstPx = firstItem.plot_x;
                const firstPy = firstItem.plot_y;
                const firstDid = firstItem.deceased_id || '';
                const fromParam = <?php echo $isGuest ? "'&from=index'" : "'&from=map'"; ?>;
                const firstTargetUrl = `${basePath}sections/popup.php?section=${encodeURIComponent(firstSec)}&plot_id=${firstPid}&plot_x=${firstPx}&plot_y=${firstPy}&deceased_name=${encodeURIComponent(firstItem.deceased_name || '')}&deceased_id=${firstDid}&search_highlight=1${fromParam}`;

                // Highlight first section on the cemetery overview map if present
                if (firstSec) {
                    let secLink = document.querySelector('.map-container a[href*="section=' + safeEscapeSec(firstSec) + '"]');
                    let secEl = secLink ? secLink.querySelector('.section') : null;
                    if (!secEl) {
                        secEl = Array.from(document.querySelectorAll('.map-container .section[title]')).find(d => (d.getAttribute('title') || '').toUpperCase().includes('SECTION ' + firstSec));
                        if (secEl) secLink = secEl.closest('a');
                    }
                    if (secEl) {
                        secEl.classList.add('search-section-highlight', 'section-blink');
                        if (secLink) {
                            if (!secLink.dataset.originalHref) {
                                secLink.dataset.originalHref = secLink.getAttribute('href');
                            }
                            secLink.setAttribute('href', firstTargetUrl);
                        }
                    }
                }

                const headerTitle = count === 1 ? '🔎 Search Result' : `🔎 Search Results (${count} matches)`;

                let html = `
                    <div style="padding: 10px 14px 6px; font-weight: 700; color: #7b1113; font-size: 0.9rem; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
                        <span>${this.escapeHtml(headerTitle)}</span>
                        <button type="button" style="border:none; background:transparent; font-size:18px; color:#94a3b8; cursor:pointer; line-height:1;" onclick="if(window.cemeteryNavigation){window.cemeteryNavigation.hideResults();}else{document.getElementById('searchResults').style.display='none';}">&times;</button>
                    </div>
                    <div class="search-results-list" style="max-height: 340px; overflow-y: auto;">
                `;

                html += results.map((item, idx) => {
                    const sec = (item.section || '').toUpperCase();
                    const px = item.plot_x;
                    const py = item.plot_y;
                    const pId = item.plot_id || 0;
                    const dId = item.deceased_id || '';
                    const rawStatus = (item.status || 'available').toLowerCase();
                    const statusClass = 'search-badge-' + rawStatus;
                    const statusLabel = item.status_label || rawStatus.toUpperCase();

                    const isDeceased = Boolean(item.deceased_name);
                    const plotDisplay = item.plot_number || `${sec} ${px}-${py}`;
                    const displayName = isDeceased ? item.deceased_name : ('Plot ' + plotDisplay + (rawStatus === 'reserved' ? ' (Reserved)' : ''));

                    const targetUrl = `${basePath}sections/popup.php?section=${encodeURIComponent(sec)}&plot_id=${pId}&plot_x=${px}&plot_y=${py}&deceased_name=${encodeURIComponent(item.deceased_name || '')}&deceased_id=${dId}&search_highlight=1` + fromParam;

                    const numPrefix = count > 1 ? `<span style="display:inline-block; width:18px; height:18px; line-height:18px; text-align:center; background:#f1f5f9; color:#475569; border-radius:50%; font-size:10px; font-weight:700; margin-right:6px;">${idx + 1}</span>` : '';

                    let ownerNote = '';
                    if (!isDeceased && item.owner_name) {
                        ownerNote = <?php echo $isGuest ? 'true' : 'false'; ?> 
                            ? `<span style="color:#92400e; font-size: 0.78rem;"><i class="fas fa-lock me-1"></i>Reserved Plot</span>` 
                            : `<span style="color:#92400e; font-size: 0.78rem;">Reserved: <strong>${this.escapeHtml(item.owner_name)}</strong></span>`;
                    } else if (!isDeceased && !item.owner_name) {
                        ownerNote = `<span style="color:#166534; font-size: 0.78rem;">Available Plot</span>`;
                    }

                    return `
                        <div class="search-result-item" data-section="${this.escapeHtml(sec)}" data-plot-id="${pId}" data-plot-x="${px}" data-plot-y="${py}" data-url="${this.escapeHtml(targetUrl)}" style="padding: 12px 14px; border-bottom: 1px solid #f1f5f9; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                            <div class="deceased-info" style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; margin-bottom: 3px;">
                                    ${numPrefix}
                                    <span style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; color: ${isDeceased ? '#dc2626' : '#64748b'}; font-weight: 700; margin-right: 6px;">
                                        ${isDeceased ? 'Deceased:' : 'Plot:'}
                                    </span>
                                    <span class="search-badge-status ${this.escapeHtml(statusClass)}" style="margin-left: auto; font-size: 10px; padding: 2px 6px; border-radius: 4px;">${this.escapeHtml(statusLabel)}</span>
                                </div>
                                <div class="deceased-name" style="font-size: 1rem; font-weight: 700; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    ${this.escapeHtml(displayName)}
                                </div>
                                <div class="deceased-location" style="font-size: 0.82rem; color: #475569; margin-top: 4px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                                    <span><strong>Section:</strong> <span style="color:#7b1113; font-weight:700;">${this.escapeHtml(sec)}</span></span>
                                    <span><strong>Grave / Plot:</strong> <span style="color:#7b1113; font-weight:700;">${this.escapeHtml(plotDisplay)}</span></span>
                                    ${ownerNote}
                                </div>
                            </div>
                            <div style="flex-shrink: 0;">
                                <a href="${this.escapeHtml(targetUrl)}" class="navigate-btn" style="display: inline-flex; align-items: center; gap: 5px; text-decoration: none; font-size: 0.78rem; font-weight: 700; padding: 7px 12px; border-radius: 6px; background: #7b1113; color: #fff; white-space: nowrap; letter-spacing: 0.5px;" onclick="event.stopPropagation();">
                                    <i class="fas fa-map-marker-alt"></i> VIEW ON MAP
                                </a>
                            </div>
                        </div>
                    `;
                }).join('');

                html += '</div>';
                this.searchResults.innerHTML = html;
                this.searchResults.style.display = 'block';

                this.searchResults.querySelectorAll('.search-result-item').forEach(el => {
                    el.addEventListener('click', (e) => {
                        if (e.target.closest('.navigate-btn')) return;
                        const u = el.getAttribute('data-url');
                        if (u) window.location.href = u;
                    });
                });
            }

            escapeHtml(str) {
                if (typeof window.escapeHtml === 'function') {
                    return window.escapeHtml(str);
                }
                if (!str) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            formatName(person) {
                if (!person) return '';
                const parts = [person.first_name, person.middle_name, person.last_name].filter(Boolean);
                return parts.join(' ');
            }

            startGoogleMapsNavigation(section, plotX, plotY, plotId) {
                this.currentNavigation = {
                    section: section,
                    plotX: plotX,
                    plotY: plotY,
                    deceasedName: this.findDeceasedName(section, plotX, plotY)
                };

                const formData = new FormData();
                formData.append('ajax_request', 'get_plot_navigation');
                formData.append('ajax', 'get_plot_navigation');
                formData.append('section', section || '');
                formData.append('plot_x', plotX || '');
                formData.append('plot_y', plotY || '');
                if (plotId) {
                    formData.append('plot_id', plotId);
                }
                const endpoint = (typeof basePath !== 'undefined' ? basePath : '') + 'sections/popup.php';

                let newWindow = null;
                try {
                    newWindow = window.open('about:blank', '_blank');
                } catch (e) {}

                fetch(endpoint, {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    const targetUrl = (data && data.directions_url) ? data.directions_url : (data ? data.navigation_url : '');
                    if (data && data.has_coordinates && targetUrl) {
                        if (newWindow && !newWindow.closed) {
                            newWindow.location.href = targetUrl;
                        } else {
                            try {
                                const nw = window.open(targetUrl, '_blank');
                                if (!nw || nw.closed || typeof nw.closed === 'undefined') {
                                    window.location.href = targetUrl;
                                }
                            } catch (e) {
                                const link = document.createElement('a');
                                link.href = targetUrl;
                                link.target = '_blank';
                                link.rel = 'noopener noreferrer';
                                document.body.appendChild(link);
                                link.click();
                                document.body.removeChild(link);
                            }
                        }
                        this.showNavigationInfo(section, plotX, plotY, data);
                    } else {
                        if (newWindow && !newWindow.closed) newWindow.close();
                        alert(data && data.message ? data.message : 'Navigation coordinates are not available for this plot yet.');
                    }
                })
                .catch(err => {
                    if (newWindow && !newWindow.closed) newWindow.close();
                    console.error('Error getting plot navigation:', err);
                    alert('Navigation coordinates are not available for this plot yet.');
                });

                // Only hide search results, don't show old visual navigation
                this.hideResults();
                if (this.searchInput) {
                    this.searchInput.value = '';
                }
            }

            showNavigationInfo(section, plotX, plotY, data) {
                const navUrl = (data && data.navigation_url) ? data.navigation_url : `https://www.google.com/maps?q=loc:${(data && data.latitude) || ''},${(data && data.longitude) || ''}&t=k&z=20`;
                const dirUrl = (data && data.directions_url) ? data.directions_url : `https://www.google.com/maps/dir/?api=1&destination=${(data && data.latitude) || ''},${(data && data.longitude) || ''}&travelmode=walking`;
                const latFormatted = (data && data.latitude) ? parseFloat(data.latitude).toFixed(7) : '';
                const lngFormatted = (data && data.longitude) ? parseFloat(data.longitude).toFixed(7) : '';

                const directionsHtml = `
                    <div class="direction-step active mb-2">
                        <div class="direction-icon start">🎯</div>
                        <div class="direction-content">
                            <div class="direction-text fw-bold">Section ${section} · Plot ${plotX}-${plotY}</div>
                            <div class="direction-distance text-muted">${this.currentNavigation.deceasedName || ''}</div>
                            ${latFormatted ? `<div style="font-size:0.75rem; color:#6c757d; margin-top:2px;">GPS: ${latFormatted}, ${lngFormatted}</div>` : ''}
                        </div>
                    </div>
                    <div class="direction-step mb-3">
                        <div class="direction-icon middle">🛰️</div>
                        <div class="direction-content">
                            <div class="direction-text fw-semibold">Exact Grave Satellite Pin</div>
                            <div class="direction-distance" style="font-size:0.78rem;">Pin dropped directly on the physical grave lawn.</div>
                            <a href="${navUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-success w-100 mt-1.5 py-1 fw-semibold" style="font-size:0.82rem; border-radius:6px;">
                                <i class="fas fa-crosshairs me-1"></i> Open Satellite Pin
                            </a>
                        </div>
                    </div>
                    <div class="direction-step mb-2">
                        <div class="direction-icon end">🚶</div>
                        <div class="direction-content">
                            <div class="direction-text fw-semibold">Walking / Road Directions</div>
                            <div class="direction-distance" style="font-size:0.78rem;">Follow road directions to the nearest cemetery driveway.</div>
                            <a href="${dirUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary w-100 mt-1.5 py-1 fw-semibold" style="font-size:0.82rem; border-radius:6px;">
                                <i class="fas fa-directions me-1"></i> Open Walking Route
                            </a>
                        </div>
                    </div>
                `;

                document.getElementById('navigationMenuBody').innerHTML = directionsHtml;
                this.showNavigationMenu();
            }

            showNavigationMenu() {
                const menu = document.getElementById('navigationMenu');
                const toggleBtn = document.getElementById('navigationMenuToggle');

                if (menu) menu.classList.add('show');
                if (toggleBtn) toggleBtn.classList.add('active');
            }

            findDeceasedName(section, plotX, plotY) {
                const person = this.deceasedData.find(p => 
                    p.section === section && p.plot_x === plotX && p.plot_y === plotY
                );
                return person ? this.formatName(person) : 'Unknown';
            }

            generateDirections() {
                const { section, plotX, plotY, deceasedName } = this.currentNavigation;

                // Generate turn-by-turn directions based on section location
                const directions = this.getSectionDirections(section, plotX, plotY);

                const directionsHtml = directions.map((step, index) => `
                    <div class="direction-step ${index === 0 ? 'active' : ''}" data-step="${index}">
                        <div class="direction-icon ${step.type}">
                            ${step.icon}
                        </div>
                        <div class="direction-content">
                            <div class="direction-text">${step.instruction}</div>
                            <div class="direction-distance">${step.distance}</div>
                        </div>
                    </div>
                `).join('');

                document.getElementById('navigationMenuBody').innerHTML = directionsHtml;
            }

            getSectionDirections(section, plotX, plotY) {
                // Accurate walking directions based on cemetery walkways
                const walkwayDirections = {
                    'A': [
                        {
                            type: 'start',
                            icon: '📍',
                            instruction: this.userLocation ? 
                                `You are currently here. Walk toward the main entrance path.` :
                                `Start at the cemetery entrance. Walk toward the main path.`,
                            distance: this.userLocation ? 'From your current location' : 'Walk to entrance'
                        },
                        {
                            type: 'middle',
                            icon: '⬆️',
                            instruction: `Follow the main path straight ahead, then turn left toward Section A.`,
                            distance: 'Approximately 50 meters'
                        },
                        {
                            type: 'middle',
                            icon: '⬅️',
                            instruction: `Turn left and walk down the left side path to Section A.`,
                            distance: 'Approximately 30 meters'
                        },
                        {
                            type: 'middle',
                            icon: '👁️',
                            instruction: `In Section A, find Plot ${plotX}-${plotY}. The plot should be clearly marked.`,
                            distance: `Within Section A`
                        },
                        {
                            type: 'end',
                            icon: '🎯',
                            instruction: `You've arrived at Plot ${plotX}-${plotY} where ${this.currentNavigation.deceasedName} is laid to rest.`,
                            distance: 'Destination reached'
                        }
                    ],
                    'B': [
                        {
                            type: 'start',
                            icon: '📍',
                            instruction: this.userLocation ? 
                                `You are currently here. Walk toward the main entrance path.` :
                                `Start at the cemetery entrance. Walk toward the main path.`,
                            distance: this.userLocation ? 'From your current location' : 'Walk to entrance'
                        },
                        {
                            type: 'middle',
                            icon: '⬆️',
                            instruction: `Follow the main path straight ahead to the left-middle area.`,
                            distance: 'Approximately 40 meters'
                        },
                        {
                            type: 'middle',
                            icon: '⬅️',
                            instruction: `Turn left and walk down to Section B area.`,
                            distance: 'Approximately 35 meters'
                        },
                        {
                            type: 'middle',
                            icon: '👁️',
                            instruction: `In Section B, find Plot ${plotX}-${plotY}. The plot should be clearly marked.`,
                            distance: `Within Section B`
                        },
                        {
                            type: 'end',
                            icon: '🎯',
                            instruction: `You've arrived at Plot ${plotX}-${plotY} where ${this.currentNavigation.deceasedName} is laid to rest.`,
                            distance: 'Destination reached'
                        }
                    ],
                    'C': [
                        {
                            type: 'start',
                            icon: '📍',
                            instruction: this.userLocation ? 
                                `You are currently here. Walk toward the main entrance path.` :
                                `Start at the cemetery entrance. Walk toward the main path.`,
                            distance: this.userLocation ? 'From your current location' : 'Walk to entrance'
                        },
                        {
                            type: 'middle',
                            icon: '⬆️',
                            instruction: `Follow the main path straight ahead to the center-left area.`,
                            distance: 'Approximately 45 meters'
                        },
                        {
                            type: 'middle',
                            icon: '⬅️',
                            instruction: `Turn left and walk down to Section C area.`,
                            distance: 'Approximately 35 meters'
                        },
                        {
                            type: 'middle',
                            icon: '👁️',
                            instruction: `In Section C, find Plot ${plotX}-${plotY}. The plot should be clearly marked.`,
                            distance: `Within Section C`
                        },
                        {
                            type: 'end',
                            icon: '🎯',
                            instruction: `You've arrived at Plot ${plotX}-${plotY} where ${this.currentNavigation.deceasedName} is laid to rest.`,
                            distance: 'Destination reached'
                        }
                    ],
                    'D': [
                        {
                            type: 'start',
                            icon: '📍',
                            instruction: this.userLocation ? 
                                `You are currently here. Walk toward the main entrance path.` :
                                `Start at the cemetery entrance. Walk toward the main path.`,
                            distance: this.userLocation ? 'From your current location' : 'Walk to entrance'
                        },
                        {
                            type: 'middle',
                            icon: '⬆️',
                            instruction: `Follow the main path straight ahead to the center area.`,
                            distance: 'Approximately 50 meters'
                        },
                        {
                            type: 'middle',
                            icon: '⬅️',
                            instruction: `Turn left and walk down to Section D area.`,
                            distance: 'Approximately 40 meters'
                        },
                        {
                            type: 'middle',
                            icon: '👁️',
                            instruction: `In Section D, find Plot ${plotX}-${plotY}. The plot should be clearly marked.`,
                            distance: `Within Section D`
                        },
                        {
                            type: 'end',
                            icon: '🎯',
                            instruction: `You've arrived at Plot ${plotX}-${plotY} where ${this.currentNavigation.deceasedName} is laid to rest.`,
                            distance: 'Destination reached'
                        }
                    ],
                    'E': [
                        {
                            type: 'start',
                            icon: '📍',
                            instruction: this.userLocation ? 
                                `You are currently here. Walk toward the main entrance path.` :
                                `Start at the cemetery entrance. Walk toward the main path.`,
                            distance: this.userLocation ? 'From your current location' : 'Walk to entrance'
                        },
                        {
                            type: 'middle',
                            icon: '⬆️',
                            instruction: `Follow the main path straight ahead to the center-right area.`,
                            distance: 'Approximately 55 meters'
                        },
                        {
                            type: 'middle',
                            icon: '➡️',
                            instruction: `Turn right and walk down to Section E area.`,
                            distance: 'Approximately 40 meters'
                        },
                        {
                            type: 'middle',
                            icon: '👁️',
                            instruction: `In Section E, find Plot ${plotX}-${plotY}. The plot should be clearly marked.`,
                            distance: `Within Section E`
                        },
                        {
                            type: 'end',
                            icon: '🎯',
                            instruction: `You've arrived at Plot ${plotX}-${plotY} where ${this.currentNavigation.deceasedName} is laid to rest.`,
                            distance: 'Destination reached'
                        }
                    ],
                    'F': [
                        {
                            type: 'start',
                            icon: '📍',
                            instruction: this.userLocation ? 
                                `You are currently here. Walk toward the main entrance path.` :
                                `Start at the cemetery entrance. Walk toward the main path.`,
                            distance: this.userLocation ? 'From your current location' : 'Walk to entrance'
                        },
                        {
                            type: 'middle',
                            icon: '⬆️',
                            instruction: `Follow the main path straight ahead to the right side area.`,
                            distance: 'Approximately 60 meters'
                        },
                        {
                            type: 'middle',
                            icon: '➡️',
                            instruction: `Turn right and walk down to Section F area.`,
                            distance: 'Approximately 35 meters'
                        },
                        {
                            type: 'middle',
                            icon: '👁️',
                            instruction: `In Section F, find Plot ${plotX}-${plotY}. The plot should be clearly marked.`,
                            distance: `Within Section F`
                        },
                        {
                            type: 'end',
                            icon: '🎯',
                            instruction: `You've arrived at Plot ${plotX}-${plotY} where ${this.currentNavigation.deceasedName} is laid to rest.`,
                            distance: 'Destination reached'
                        }
                    ],
                    'G': [
                        {
                            type: 'start',
                            icon: '📍',
                            instruction: this.userLocation ? 
                                `You are currently here. Walk toward the upper path.` :
                                `Start at the cemetery entrance. Walk toward the upper path.`,
                            distance: this.userLocation ? 'From your current location' : 'Walk to upper path'
                        },
                        {
                            type: 'middle',
                            icon: '⬆️',
                            instruction: `Follow the upper path straight ahead to the center area.`,
                            distance: 'Approximately 35 meters'
                        },
                        {
                            type: 'middle',
                            icon: '⬇️',
                            instruction: `Walk down to Section G area.`,
                            distance: 'Approximately 25 meters'
                        },
                        {
                            type: 'middle',
                            icon: '👁️',
                            instruction: `In Section G, find Plot ${plotX}-${plotY}. The plot should be clearly marked.`,
                            distance: `Within Section G`
                        },
                        {
                            type: 'end',
                            icon: '🎯',
                            instruction: `You've arrived at Plot ${plotX}-${plotY} where ${this.currentNavigation.deceasedName} is laid to rest.`,
                            distance: 'Destination reached'
                        }
                    ],
                    'H': [
                        {
                            type: 'start',
                            icon: '📍',
                            instruction: this.userLocation ? 
                                `You are currently here. Walk toward the upper path.` :
                                `Start at the cemetery entrance. Walk toward the upper path.`,
                            distance: this.userLocation ? 'From your current location' : 'Walk to upper path'
                        },
                        {
                            type: 'middle',
                            icon: '⬆️',
                            instruction: `Follow the upper path straight ahead to the right side area.`,
                            distance: 'Approximately 40 meters'
                        },
                        {
                            type: 'middle',
                            icon: '⬇️',
                            instruction: `Walk down to Section H area.`,
                            distance: 'Approximately 25 meters'
                        },
                        {
                            type: 'middle',
                            icon: '👁️',
                            instruction: `In Section H, find Plot ${plotX}-${plotY}. The plot should be clearly marked.`,
                            distance: `Within Section H`
                        },
                        {
                            type: 'end',
                            icon: '🎯',
                            instruction: `You've arrived at Plot ${plotX}-${plotY} where ${this.currentNavigation.deceasedName} is laid to rest.`,
                            distance: 'Destination reached'
                        }
                    ],
                    'I': [
                        {
                            type: 'start',
                            icon: '📍',
                            instruction: this.userLocation ? 
                                `You are currently here. Walk toward the top path.` :
                                `Start at the cemetery entrance. Walk toward the top path.`,
                            distance: this.userLocation ? 'From your current location' : 'Walk to top path'
                        },
                        {
                            type: 'middle',
                            icon: '⬆️',
                            instruction: `Follow the top path straight ahead to the center-top area.`,
                            distance: 'Approximately 30 meters'
                        },
                        {
                            type: 'middle',
                            icon: '⬇️',
                            instruction: `Walk down to Section I area.`,
                            distance: 'Approximately 20 meters'
                        },
                        {
                            type: 'middle',
                            icon: '👁️',
                            instruction: `In Section I, find Plot ${plotX}-${plotY}. The plot should be clearly marked.`,
                            distance: `Within Section I`
                        },
                        {
                            type: 'end',
                            icon: '🎯',
                            instruction: `You've arrived at Plot ${plotX}-${plotY} where ${this.currentNavigation.deceasedName} is laid to rest.`,
                            distance: 'Destination reached'
                        }
                    ],
                    'J': [
                        {
                            type: 'start',
                            icon: '📍',
                            instruction: this.userLocation ? 
                                `You are currently here. Walk toward the top path.` :
                                `Start at the cemetery entrance. Walk toward the top path.`,
                            distance: this.userLocation ? 'From your current location' : 'Walk to top path'
                        },
                        {
                            type: 'middle',
                            icon: '⬆️',
                            instruction: `Follow the top path straight ahead to the right-top area.`,
                            distance: 'Approximately 35 meters'
                        },
                        {
                            type: 'middle',
                            icon: '⬇️',
                            instruction: `Walk down to Section J area.`,
                            distance: 'Approximately 25 meters'
                        },
                        {
                            type: 'middle',
                            icon: '👁️',
                            instruction: `In Section J, find Plot ${plotX}-${plotY}. The plot should be clearly marked.`,
                            distance: `Within Section J`
                        },
                        {
                            type: 'end',
                            icon: '🎯',
                            instruction: `You've arrived at Plot ${plotX}-${plotY} where ${this.currentNavigation.deceasedName} is laid to rest.`,
                            distance: 'Destination reached'
                        }
                    ]
                };

                return walkwayDirections[section] || [
                    {
                        type: 'start',
                        icon: '📍',
                        instruction: this.userLocation ? 
                            `You are currently here. Follow the red path to Section ${section}.` :
                            `Start at the cemetery entrance. Head toward Section ${section}.`,
                        distance: this.userLocation ? 'Starting from your current location' : 'Walk toward the entrance gate'
                    },
                    {
                        type: 'middle',
                        icon: '🚶',
                        instruction: `Follow the red path to Section ${section}.`,
                        distance: `Approximately ${Math.floor(Math.random() * 50 + 50)} meters`
                    },
                    {
                        type: 'middle',
                        icon: '👁️',
                        instruction: `In Section ${section}, find Plot ${plotX}-${plotY}. The plot should be clearly marked.`,
                        distance: `Within Section ${section}`
                    },
                    {
                        type: 'end',
                        icon: '🎯',
                        instruction: `You've arrived at Plot ${plotX}-${plotY} where ${this.currentNavigation.deceasedName} is laid to rest.`,
                        distance: 'Destination reached'
                    }
                ];
            }

            showDirectionsPanel() {
                const panel = document.getElementById('directionsPanel');
                panel.style.display = 'block';

                // Animate panel entrance
                panel.style.transform = 'translateY(-50%) translateX(100%)';
                setTimeout(() => {
                    panel.style.transform = 'translateY(-50%) translateX(0)';
                }, 100);
            }

            drawNavigationPath() {
                const { section } = this.currentNavigation;
                const sectionElement = document.querySelector(`.section[title*="SECTION ${section.toUpperCase()}"]`);

                if (!sectionElement) return;

                const pathsGroup = document.getElementById('navigationPaths');
                const dotsGroup = document.getElementById('walkingDots');
                pathsGroup.innerHTML = ''; // Clear previous paths
                dotsGroup.innerHTML = ''; // Clear previous dots

                // Get section position
                const sectionRect = sectionElement.getBoundingClientRect();
                const containerRect = this.mapContainer.getBoundingClientRect();

                // Start from user's current location or entrance if no location detected
                let startX, startY;

                if (this.userLocation && this.locationMarker) {
                    // Start from user's location marker position
                    const markerRect = this.locationMarker.getBoundingClientRect();
                    startX = markerRect.left - containerRect.left + markerRect.width / 2;
                    startY = markerRect.top - containerRect.top + markerRect.height / 2;
                } else {
                    // Fallback to entrance
                    startX = containerRect.width / 2;
                    startY = 50;
                }

                const endX = sectionRect.left - containerRect.left + sectionRect.width / 2;
                const endY = sectionRect.top - containerRect.top + sectionRect.height / 2;

                // Create multiple path segments for realistic walking path
                const pathSegments = this.generateWalkingPathSegments(startX, startY, endX, endY, section);

                // Draw each segment
                pathSegments.forEach((segment, index) => {
                    setTimeout(() => {
                        this.drawPathSegment(segment, index);
                    }, index * 200); // Animate segments appearing
                });

                // Add walking dots animation
                setTimeout(() => {
                    this.addWalkingDots(pathSegments);
                }, pathSegments.length * 200);
            }

            generateWalkingPathSegments(startX, startY, endX, endY, section) {
                const segments = [];

                // Accurate cemetery walkway paths based on actual map layout
                const cemeteryWalkways = {
                    'A': [
                        // From user location to main entrance path
                        { x1: startX, y1: startY, x2: startX, y2: 100 },
                        // Follow main entrance path to left side
                        { x1: startX, y1: 100, x2: 100, y2: 100 },
                        // Walk down left side path
                        { x1: 100, y1: 100, x2: 100, y2: 550 },
                        // Turn toward Section A
                        { x1: 100, y1: 550, x2: 180, y2: 550 },
                        // Final approach to Section A
                        { x1: 180, y1: 550, x2: endX, y2: endY }
                    ],
                    'B': [
                        // From user location to main entrance path
                        { x1: startX, y1: startY, x2: startX, y2: 100 },
                        // Follow main entrance path to left-middle
                        { x1: startX, y1: 100, x2: 180, y2: 100 },
                        // Walk down to Section B area
                        { x1: 180, y1: 100, x2: 180, y2: 480 },
                        // Final approach to Section B
                        { x1: 180, y1: 480, x2: endX, y2: endY }
                    ],
                    'C': [
                        // From user location to main entrance path
                        { x1: startX, y1: startY, x2: startX, y2: 100 },
                        // Follow main entrance path to center-left
                        { x1: startX, y1: 100, x2: 280, y2: 100 },
                        // Walk down to Section C area
                        { x1: 280, y1: 100, x2: 280, y2: 480 },
                        // Final approach to Section C
                        { x1: 280, y1: 480, x2: endX, y2: endY }
                    ],
                    'D': [
                        // From user location to main entrance path
                        { x1: startX, y1: startY, x2: startX, y2: 100 },
                        // Follow main entrance path to center
                        { x1: startX, y1: 100, x2: 310, y2: 100 },
                        // Walk down to Section D area
                        { x1: 310, y1: 100, x2: 310, y2: 580 },
                        // Final approach to Section D
                        { x1: 310, y1: 580, x2: endX, y2: endY }
                    ],
                    'E': [
                        // From user location to main entrance path
                        { x1: startX, y1: startY, x2: startX, y2: 100 },
                        // Follow main entrance path to center-right
                        { x1: startX, y1: 100, x2: 400, y2: 100 },
                        // Walk down to Section E area
                        { x1: 400, y1: 100, x2: 400, y2: 580 },
                        // Final approach to Section E
                        { x1: 400, y1: 580, x2: endX, y2: endY }
                    ],
                    'F': [
                        // From user location to main entrance path
                        { x1: startX, y1: startY, x2: startX, y2: 100 },
                        // Follow main entrance path to right side
                        { x1: startX, y1: 100, x2: 450, y2: 100 },
                        // Walk down to Section F area
                        { x1: 450, y1: 100, x2: 450, y2: 500 },
                        // Final approach to Section F
                        { x1: 450, y1: 500, x2: endX, y2: endY }
                    ],
                    'G': [
                        // From user location to upper path
                        { x1: startX, y1: startY, x2: startX, y2: 80 },
                        // Follow upper path to center
                        { x1: startX, y1: 80, x2: 360, y2: 80 },
                        // Walk down to Section G area
                        { x1: 360, y1: 80, x2: 360, y2: 350 },
                        // Final approach to Section G
                        { x1: 360, y1: 350, x2: endX, y2: endY }
                    ],
                    'H': [
                        // From user location to upper path
                        { x1: startX, y1: startY, x2: startX, y2: 80 },
                        // Follow upper path to right side
                        { x1: startX, y1: 80, x2: 440, y2: 80 },
                        // Walk down to Section H area
                        { x1: 440, y1: 80, x2: 440, y2: 360 },
                        // Final approach to Section H
                        { x1: 440, y1: 360, x2: endX, y2: endY }
                    ],
                    'I': [
                        // From user location to top path
                        { x1: startX, y1: startY, x2: startX, y2: 60 },
                        // Follow top path to center-top
                        { x1: startX, y1: 60, x2: 400, y2: 60 },
                        // Walk down to Section I area
                        { x1: 400, y1: 60, x2: 400, y2: 180 },
                        // Final approach to Section I
                        { x1: 400, y1: 180, x2: endX, y2: endY }
                    ],
                    'J': [
                        // From user location to top path
                        { x1: startX, y1: startY, x2: startX, y2: 60 },
                        // Follow top path to right-top
                        { x1: startX, y1: 60, x2: 480, y2: 60 },
                        // Walk down to Section J area
                        { x1: 480, y1: 60, x2: 480, y2: 200 },
                        // Final approach to Section J
                        { x1: 480, y1: 200, x2: endX, y2: endY }
                    ]
                };

                // Get the predefined walkway path for this section
                const walkwayPath = cemeteryWalkways[section];

                if (walkwayPath) {
                    // Adjust the first segment to start from user's actual position
                    walkwayPath[0].x1 = startX;
                    walkwayPath[0].y1 = startY;

                    // Adjust the last segment to end at the exact destination
                    const lastSegment = walkwayPath[walkwayPath.length - 1];
                    lastSegment.x2 = endX;
                    lastSegment.y2 = endY;

                    return walkwayPath;
                }

                // Fallback: direct path if section not found
                return [
                    { x1: startX, y1: startY, x2: endX, y2: endY }
                ];
            }

            drawPathSegment(segment, index) {
                const pathsGroup = document.getElementById('navigationPaths');

                // Create straight path for walkway-following navigation
                const pathData = `M ${segment.x1} ${segment.y1} L ${segment.x2} ${segment.y2}`;

                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', pathData);
                path.setAttribute('class', 'navigation-path path-animation');

                // Add arrow to last segment
                const totalSegments = this.generateWalkingPathSegments(0, 0, 0, 0, this.currentNavigation.section).length;
                if (index === totalSegments - 1) {
                    path.setAttribute('marker-end', 'url(#arrowhead)');
                }

                pathsGroup.appendChild(path);
            }

            addWalkingDots(pathSegments) {
                const dotsGroup = document.getElementById('walkingDots');

                pathSegments.forEach(segment => {
                    const midX = (segment.x1 + segment.x2) / 2;
                    const midY = (segment.y1 + segment.y2) / 2;

                    // Create dotted line along the path
                    const dotPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    const dotPathData = `M ${segment.x1} ${segment.y1} L ${segment.x2} ${segment.y2}`;
                    dotPath.setAttribute('d', dotPathData);
                    dotPath.setAttribute('class', 'walking-dots');

                    dotsGroup.appendChild(dotPath);
                });
            }

            addNavigationMarkers() {
                // Remove existing markers
                document.querySelectorAll('.navigation-marker').forEach(marker => marker.remove());

                const { section } = this.currentNavigation;
                const sectionElement = document.querySelector(`.section[title*="SECTION ${section.toUpperCase()}"]`);

                if (!sectionElement) return;

                const containerRect = this.mapContainer.getBoundingClientRect();
                const sectionRect = sectionElement.getBoundingClientRect();

                // Add start marker (entrance)
                const startMarker = document.createElement('div');
                startMarker.className = 'navigation-marker start';
                startMarker.style.left = (containerRect.width / 2 - 10) + 'px';
                startMarker.style.top = '40px';
                this.mapContainer.appendChild(startMarker);

                // Add end marker (destination)
                const endMarker = document.createElement('div');
                endMarker.className = 'navigation-marker end';
                endMarker.style.left = (sectionRect.left - containerRect.left + sectionRect.width / 2 - 10) + 'px';
                endMarker.style.top = (sectionRect.top - containerRect.top + sectionRect.height / 2 - 10) + 'px';
                this.mapContainer.appendChild(endMarker);
            }

            startNavigation() {
                // Close menu on mobile after starting navigation
                this.closeNavigationMenu();

                // Start real-time GPS tracking if available
                if (navigator.geolocation) {
                    navigator.geolocation.watchPosition(
                        (position) => {
                            this.updateUserLocation(position.coords.latitude, position.coords.longitude);
                        },
                        (error) => {
                            console.log('GPS error:', error);
                        },
                        {
                            enableHighAccuracy: true,
                            timeout: 5000,
                            maximumAge: 0
                        }
                    );
                }

                // Animate through directions for mobile
                this.animateThroughDirections();
            }

            animateThroughDirections() {
                const steps = document.querySelectorAll('.direction-step');
                let currentStep = 0;

                const animateStep = () => {
                    // Remove active from all steps
                    steps.forEach(step => step.classList.remove('active'));

                    // Add active to current step
                    if (steps[currentStep]) {
                        steps[currentStep].classList.add('active');

                        // Mobile-optimized scrolling
                        if (window.innerWidth <= 768) {
                            steps[currentStep].scrollIntoView({ 
                                behavior: 'smooth', 
                                block: 'center',
                                inline: 'center'
                            });
                        } else {
                            steps[currentStep].scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }

                        // Vibrate on mobile for better feedback
                        if (navigator.vibrate && window.innerWidth <= 768) {
                            navigator.vibrate(200);
                        }
                    }

                    currentStep++;
                    if (currentStep < steps.length) {
                        setTimeout(animateStep, 3000); // Move to next step after 3 seconds
                    } else {
                        // Navigation complete
                        setTimeout(() => {
                            this.highlightDestination();
                            // Final vibration on mobile
                            if (navigator.vibrate && window.innerWidth <= 768) {
                                navigator.vibrate([200, 100, 200]);
                            }
                        }, 2000);
                    }
                };

                animateStep();
            }

            highlightDestination() {
                const { section } = this.currentNavigation;
                const sectionElement = document.querySelector(`.section[title*="SECTION ${section.toUpperCase()}"]`);

                if (sectionElement) {
                    sectionElement.classList.add('highlighted-section');
                    // Stationary map: scrollIntoView is disabled so overview map never scrolls or jumps
                    // sectionElement.scrollIntoView({ behavior: 'smooth', block: 'center' });

                    // Remove highlight after 5 seconds
                    setTimeout(() => {
                        sectionElement.classList.remove('highlighted-section');
                    }, 5000);
                }
            }

            closeDirections() {
                this.closeNavigationMenu();
                this.clearNavigation();
            }

            clearNavigation() {
                // Clear paths (only exist on mobile overlay)
                const pathsGroup = document.getElementById('navigationPaths');
                if (pathsGroup) pathsGroup.innerHTML = '';

                // Clear dots (only exist on mobile overlay)
                const dotsGroup = document.getElementById('walkingDots');
                if (dotsGroup) dotsGroup.innerHTML = '';

                // Clear markers
                document.querySelectorAll('.navigation-marker').forEach(marker => marker.remove());

                // Clear highlights
                document.querySelectorAll('.highlighted-section').forEach(el => {
                    el.classList.remove('highlighted-section');
                });

                // Clear location tracking
                if (this.locationTrackingInterval) {
                    clearInterval(this.locationTrackingInterval);
                    this.locationTrackingInterval = null;
                }

                this.currentNavigation = null;
            }

            hideResults() {
                this.searchResults.style.display = 'none';
            }

            clearSearch() {
                this.searchInput.value = '';
                this.hideResults();
                this.closeNavigationMenu();
                this.clearNavigation();

                // Clear search section highlights and restore href attributes
                document.querySelectorAll('.map-container .section.search-section-highlight, .map-container .section.section-blink').forEach(el => {
                    el.classList.remove('search-section-highlight', 'section-blink');
                });
                document.querySelectorAll('.map-container a[data-original-href]').forEach(link => {
                    link.setAttribute('href', link.dataset.originalHref);
                    delete link.dataset.originalHref;
                });
            }
        }

        // Helper to visually highlight and pulse a section on the cemetery map
        window.highlightSectionOnMap = function(sec) {
            if (!sec) return;
            var cleanSec = sec.toString().trim().toUpperCase();
            document.querySelectorAll('.map-container .section.search-section-highlight, .map-container .section.section-blink').forEach(function(el) {
                el.classList.remove('search-section-highlight', 'section-blink');
            });
            var secLink = document.querySelector('.map-container a[href*="section=' + safeEscapeSec(cleanSec) + '"]');
            var secEl = secLink ? secLink.querySelector('.section') : null;
            if (!secEl) {
                secEl = Array.from(document.querySelectorAll('.map-container .section[title]')).find(function(d) {
                    return (d.getAttribute('title') || '').toUpperCase().includes('SECTION ' + cleanSec);
                });
            }
            if (secEl) {
                secEl.classList.add('search-section-highlight', 'section-blink');
            }
        };

        // Map filter popover toggle and reset handlers with mobile touch support
        function toggleMapFilterPopover(e) {
            if (e) {
                if (e.type === 'touchstart') {
                    window._filterTouchHandled = true;
                    setTimeout(function(){ window._filterTouchHandled = false; }, 350);
                } else if (e.type === 'click' && window._filterTouchHandled) {
                    e.preventDefault();
                    e.stopPropagation();
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
            }
            var pop = document.getElementById('mapFilterPopover');
            if (pop) pop.classList.toggle('show');
        }
        window.toggleMapFilterPopover = toggleMapFilterPopover;

        function clearMapFilters() {
            var yr = document.getElementById('mapFilterYear');
            var dFrom = document.getElementById('mapFilterDateFrom');
            var dTo = document.getElementById('mapFilterDateTo');
            var sec = document.getElementById('mapFilterSection');
            if (yr) yr.value = '';
            if (dFrom) dFrom.value = '';
            if (dTo) dTo.value = '';
            if (sec) sec.value = '';
            applyMapFilters();
        }
        window.clearMapFilters = clearMapFilters;

        function clearMapSearch() {
            var inp = document.getElementById('deceasedSearch');
            var btn = document.getElementById('mapClearSearchBtn');
            if (inp) inp.value = '';
            if (btn) btn.style.display = 'none';
            clearMapFilters();
            var res = document.getElementById('searchResults');
            if (res) res.style.display = 'none';
            document.querySelectorAll('.map-container .section.search-section-highlight, .map-container .section.section-blink').forEach(function(el) {
                el.classList.remove('search-section-highlight', 'section-blink');
            });
            document.querySelectorAll('.map-container a[data-original-href]').forEach(function(link) {
                link.setAttribute('href', link.dataset.originalHref);
                delete link.dataset.originalHref;
            });
        }
        window.clearMapSearch = clearMapSearch;

        function applyMapFilters() {
            var pop = document.getElementById('mapFilterPopover');
            if (pop) pop.classList.remove('show');

            var yr = getCemeteryInputVal('mapFilterYear');
            var dFrom = getCemeteryInputVal('mapFilterDateFrom');
            var dTo = getCemeteryInputVal('mapFilterDateTo');
            var sec = getCemeteryInputVal('mapFilterSection').toUpperCase();

            var hasFilter = (yr !== '' || dFrom !== '' || dTo !== '' || sec !== '');
            var dot = document.getElementById('mapFilterActiveDot');
            if (dot) dot.style.display = hasFilter ? 'inline-block' : 'none';

            // Immediately highlight section on map if chosen
            if (sec) {
                highlightSectionOnMap(sec);
            }

            var chips = document.getElementById('mapActiveFilterChips');
            if (chips) {
                if (hasFilter) {
                    chips.style.display = 'flex';
                    var html = '<span style="color:#64748b; font-size:0.75rem;">Active:</span>';
                    if (yr) html += '<span class="chip">Year: ' + yr + '</span>';
                    if (dFrom || dTo) html += '<span class="chip" style="background:#475569;">' + (dFrom || 'Start') + ' &rarr; ' + (dTo || 'Now') + '</span>';
                    if (sec) html += '<span class="chip chip-sec">Section ' + sec + '</span>';
                    html += '<span class="chip chip-clear" onclick="clearMapFilters()"><i class="fas fa-times me-1"></i> Clear</span>';
                    chips.innerHTML = html;
                } else {
                    chips.style.display = 'none';
                    chips.innerHTML = '';
                }
            }

            triggerCemeteryPlotSearch(false);
        }
        window.applyMapFilters = applyMapFilters;

        function handleOutsideFilterDismiss(e) {
            var pop = document.getElementById('mapFilterPopover');
            var btn = document.getElementById('mapFilterBtn');
            if (pop && pop.classList.contains('show')) {
                if (!pop.contains(e.target) && (!btn || !btn.contains(e.target))) {
                    pop.classList.remove('show');
                }
            }
        }
        document.addEventListener('click', handleOutsideFilterDismiss);
        document.addEventListener('touchstart', handleOutsideFilterDismiss, { passive: true });

        // Global direct search triggers for Find Plot button and Enter key
        function triggerCemeteryPlotSearch(navigateIfSingle) {
            var input = document.getElementById('deceasedSearch');
            var val = input ? input.value : '';
            var yr = getCemeteryInputVal('mapFilterYear');
            var dFrom = getCemeteryInputVal('mapFilterDateFrom');
            var dTo = getCemeteryInputVal('mapFilterDateTo');
            var sec = getCemeteryInputVal('mapFilterSection').toUpperCase();
            var hasFilter = (yr !== '' || dFrom !== '' || dTo !== '' || sec !== '');

            var clearBtn = document.getElementById('mapClearSearchBtn');
            if (clearBtn) clearBtn.style.display = ((val || '').trim() !== '' || hasFilter) ? 'inline-block' : 'none';

            if (window.cemeteryNavigation && typeof window.cemeteryNavigation.searchDeceased === 'function') {
                window.cemeteryNavigation.searchDeceased(val, Boolean(navigateIfSingle));
            } else {
                doDirectCemeterySearch(val, Boolean(navigateIfSingle));
            }
        }
        window.triggerCemeteryPlotSearch = triggerCemeteryPlotSearch;

        function handleCemeterySearchInput(val) {
            var clearBtn = document.getElementById('mapClearSearchBtn');
            if (clearBtn) clearBtn.style.display = ((val || '').trim() !== '') ? 'inline-block' : 'none';

            clearTimeout(window._searchLiveDebounce);
            window._searchLiveDebounce = setTimeout(function() {
                var q = (val || '').trim();
                var yr = getCemeteryInputVal('mapFilterYear');
                var dFrom = getCemeteryInputVal('mapFilterDateFrom');
                var dTo = getCemeteryInputVal('mapFilterDateTo');
                var sec = getCemeteryInputVal('mapFilterSection').toUpperCase();
                var hasFilter = (yr !== '' || dFrom !== '' || dTo !== '' || sec !== '');

                if (q.length >= 1 || hasFilter) {
                    triggerCemeteryPlotSearch(false);
                } else {
                    var res = document.getElementById('searchResults');
                    if (res) res.style.display = 'none';
                }
            }, 300);
        }
        window.handleCemeterySearchInput = handleCemeterySearchInput;

        async function doDirectCemeterySearch(query, navigateIfSingle) {
            var searchTerm = (query !== undefined ? query : getCemeteryInputVal('deceasedSearch')).trim();
            var yr = getCemeteryInputVal('mapFilterYear');
            var dFrom = getCemeteryInputVal('mapFilterDateFrom');
            var dTo = getCemeteryInputVal('mapFilterDateTo');
            var sec = getCemeteryInputVal('mapFilterSection').toUpperCase();
            var hasFilter = (yr !== '' || dFrom !== '' || dTo !== '' || sec !== '');

            var resultsContainer = document.getElementById('searchResults');
            if (!resultsContainer) return;

            if (searchTerm.length < 1 && !hasFilter) {
                resultsContainer.style.display = 'none';
                return;
            }

            var qs = '?q=' + encodeURIComponent(searchTerm);
            if (yr) qs += '&year=' + encodeURIComponent(yr);
            if (dFrom) qs += '&date_from=' + encodeURIComponent(dFrom);
            if (dTo) qs += '&date_to=' + encodeURIComponent(dTo);
            if (sec) qs += '&section=' + encodeURIComponent(sec);

            resultsContainer.innerHTML = '<div style="padding: 14px; text-align: center; color: #64748b; font-size: 0.9rem;"><i class="fas fa-spinner fa-spin me-2"></i> Searching records...</div>';
            resultsContainer.style.display = 'block';

            try {
                var basePath = (window.location.pathname.indexOf('/Admin/') !== -1 || window.location.pathname.indexOf('/User/') !== -1 || window.location.pathname.indexOf('/sections/') !== -1 ? '../' : '');
                var data = null;
                var searchCandidates = [
                    basePath + 'map_search.php' + qs,
                    basePath + 'api/map_search.php' + qs,
                    basePath + 'map.php' + qs + '&ajax_map_search=1'
                ];

                for (var i = 0; i < searchCandidates.length; i++) {
                    try {
                        var response = await fetch(searchCandidates[i], {
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin'
                        });
                        if (!response.ok) continue;
                        var resText = await response.text();
                        var cleanText = (resText || '').trim();
                        var firstBrace = cleanText.indexOf('{');
                        var lastBrace = cleanText.lastIndexOf('}');
                        if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
                            cleanText = cleanText.substring(firstBrace, lastBrace + 1);
                        }
                        if (cleanText.indexOf('{') === 0 || cleanText.indexOf('[') === 0) {
                            var parsed = JSON.parse(cleanText);
                            if (parsed && parsed.success !== undefined) {
                                data = parsed;
                                break;
                            }
                        }
                    } catch (candErr) {
                        // Continue to next fallback
                    }
                }

                if (window.cemeteryNavigation && typeof window.cemeteryNavigation.displayResults === 'function') {
                    window.cemeteryNavigation.displayResults(data && data.success && Array.isArray(data.results) ? data.results : [], Boolean(navigateIfSingle));
                } else if (data && data.success && Array.isArray(data.results)) {
                    renderDirectSearchResults(data.results, resultsContainer, Boolean(navigateIfSingle));
                } else {
                    renderDirectSearchResults([], resultsContainer, false);
                }
            } catch (err) {
                console.error('Search error:', err);
                resultsContainer.innerHTML = '<div style="padding: 16px; text-align: center; color: #64748b; font-size: 0.92rem;"><i class="fas fa-info-circle me-1 text-muted"></i> No deceased record or grave plot found for your search.</div>';
                resultsContainer.style.display = 'block';
            }
        };

        function renderDirectSearchResults(results, container, navigateIfSingle) {
            if (!container) return;

            // Clear previous section highlights on cemetery map and restore original hrefs
            document.querySelectorAll('.map-container .section.search-section-highlight, .map-container .section.section-blink').forEach(function(el) {
                el.classList.remove('search-section-highlight', 'section-blink');
            });
            document.querySelectorAll('.map-container a[data-original-href]').forEach(function(link) {
                link.setAttribute('href', link.dataset.originalHref);
                delete link.dataset.originalHref;
            });

            if (!results || results.length === 0) {
                container.innerHTML = '<div style="padding: 16px; text-align: center; color: #64748b; font-size: 0.92rem;"><i class="fas fa-info-circle me-1 text-muted"></i> No deceased record or grave plot found for your search.</div>';
                container.style.display = 'block';
                return;
            }

            var safeEsc = (typeof window.escapeHtml === 'function') ? window.escapeHtml : function(s) {
                if (s === null || s === undefined) return '';
                return String(s).replace(/[&<>"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]; });
            };

            var count = results.length;
            var firstItem = results[0];
            var resultBasePath = (window.location.pathname.indexOf('/Admin/') !== -1 || window.location.pathname.indexOf('/User/') !== -1 || window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '';
            var firstSec = (firstItem.section || '').toUpperCase();
            var firstPid = firstItem.plot_id || 0;
            var firstPx = firstItem.plot_x;
            var firstPy = firstItem.plot_y;
            var firstDid = firstItem.deceased_id || '';
            var fromParam = <?php echo $isGuest ? "'&from=index'" : "'&from=map'"; ?>;
            var firstTargetUrl = resultBasePath + 'sections/popup.php?section=' + encodeURIComponent(firstSec) + '&plot_id=' + firstPid + '&plot_x=' + firstPx + '&plot_y=' + firstPy + '&deceased_name=' + encodeURIComponent(firstItem.deceased_name || '') + '&deceased_id=' + firstDid + '&search_highlight=1' + fromParam;

            // Highlight first section on the cemetery overview map if present
            if (firstSec) {
                var secLink = document.querySelector('.map-container a[href*="section=' + safeEscapeSec(firstSec) + '"]');
                var secEl = secLink ? secLink.querySelector('.section') : null;
                if (!secEl) {
                    secEl = Array.from(document.querySelectorAll('.map-container .section[title]')).find(function(d) {
                        return (d.getAttribute('title') || '').toUpperCase().includes('SECTION ' + firstSec);
                    });
                    if (secEl) secLink = secEl.closest('a');
                }
                if (secEl) {
                    secEl.classList.add('search-section-highlight', 'section-blink');
                    if (secLink) {
                        if (!secLink.dataset.originalHref) {
                            secLink.dataset.originalHref = secLink.getAttribute('href');
                        }
                        secLink.setAttribute('href', firstTargetUrl);
                    }
                }
            }

            var headerTitle = count === 1 ? '🔎 Search Result' : ('🔎 Search Results (' + count + ' matches)');
            var html = '<div style="padding: 10px 14px 6px; font-weight: 700; color: #7b1113; font-size: 0.9rem; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">'
                     + '<span>' + safeEsc(headerTitle) + '</span>'
                     + '<button type="button" style="border:none; background:transparent; font-size:18px; color:#94a3b8; cursor:pointer; line-height:1;" onclick="document.getElementById(\'searchResults\').style.display=\'none\'">&times;</button>'
                     + '</div>'
                     + '<div class="search-results-list" style="max-height: 340px; overflow-y: auto;">';

            results.forEach(function(item, idx) {
                var sec = (item.section || '').toUpperCase();
                var px = item.plot_x;
                var py = item.plot_y;
                var pId = item.plot_id || 0;
                var dId = item.deceased_id || '';
                var rawStatus = (item.status || 'available').toLowerCase();
                var statusClass = 'search-badge-' + rawStatus;
                var statusLabel = item.status_label || rawStatus.toUpperCase();
                var isDeceased = Boolean(item.deceased_name);
                var plotDisplay = item.plot_number || (sec + ' ' + px + '-' + py);
                var displayName = isDeceased ? item.deceased_name : ('Plot ' + plotDisplay + (rawStatus === 'reserved' ? ' (Reserved)' : ''));
                var targetUrl = resultBasePath + 'sections/popup.php?section=' + encodeURIComponent(sec) + '&plot_id=' + pId + '&plot_x=' + px + '&plot_y=' + py + '&deceased_name=' + encodeURIComponent(item.deceased_name || '') + '&deceased_id=' + dId + '&search_highlight=1' + fromParam;
                var numPrefix = count > 1 ? ('<span style="display:inline-block; width:18px; height:18px; line-height:18px; text-align:center; background:#f1f5f9; color:#475569; border-radius:50%; font-size:10px; font-weight:700; margin-right:6px;">' + (idx + 1) + '</span>') : '';

                var ownerNote = '';
                if (!isDeceased && item.owner_name) {
                    if (<?php echo $isGuest ? 'true' : 'false'; ?>) {
                        ownerNote = '<span style="color: #92400e; font-size: 0.78rem;"><i class="fas fa-lock me-1"></i>Reserved Plot</span>';
                    } else {
                        ownerNote = '<span style="color: #92400e; font-size: 0.78rem;">Reserved: <strong>' + safeEsc(item.owner_name) + '</strong></span>';
                    }
                } else if (!isDeceased && !item.owner_name) {
                    ownerNote = '<span style="color: #166534; font-size: 0.78rem;">Available Plot</span>';
                }

                html += '<div class="search-result-item" data-section="' + safeEsc(sec) + '" data-plot-id="' + pId + '" data-plot-x="' + px + '" data-plot-y="' + py + '" data-url="' + safeEsc(targetUrl) + '" style="padding: 12px 14px; border-bottom: 1px solid #f1f5f9; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 10px;">'
                      + '<div class="deceased-info" style="flex: 1; min-width: 0;">'
                      + '<div style="display: flex; align-items: center; margin-bottom: 3px;">'
                      + numPrefix
                      + '<span style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; color: ' + (isDeceased ? '#dc2626' : '#64748b') + '; font-weight: 700; margin-right: 6px;">' + (isDeceased ? 'Deceased:' : 'Plot:') + '</span>'
                      + '<span class="search-badge-status ' + safeEsc(statusClass) + '" style="margin-left: auto; font-size: 10px; padding: 2px 6px; border-radius: 4px;">' + safeEsc(statusLabel) + '</span>'
                      + '</div>'
                      + '<div class="deceased-name" style="font-size: 1rem; font-weight: 700; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">' + safeEsc(displayName) + '</div>'
                      + '<div class="deceased-location" style="font-size: 0.82rem; color: #475569; margin-top: 4px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">'
                      + '<span><strong>Section:</strong> <span style="color:#7b1113; font-weight:700;">' + safeEsc(sec) + '</span></span>'
                      + '<span><strong>Grave / Plot:</strong> <span style="color:#7b1113; font-weight:700;">' + safeEsc(plotDisplay) + '</span></span>'
                      + ownerNote
                      + '</div>'
                      + '</div>'
                      + '<div style="flex-shrink: 0;">'
                      + '<a href="' + safeEsc(targetUrl) + '" class="navigate-btn" style="display: inline-flex; align-items: center; gap: 5px; text-decoration: none; font-size: 0.78rem; font-weight: 700; padding: 7px 12px; border-radius: 6px; background: #7b1113; color: #fff; white-space: nowrap; letter-spacing: 0.5px;" onclick="event.stopPropagation();">'
                      + '<i class="fas fa-map-marker-alt"></i> VIEW ON MAP'
                      + '</a>'
                      + '</div>'
                      + '</div>';
            });
            html += '</div>';
            container.innerHTML = html;
            container.style.display = 'block';

            container.querySelectorAll('.search-result-item').forEach(function(el) {
                el.addEventListener('click', function(e) {
                    if (e.target.closest('.navigate-btn')) return;
                    var u = el.getAttribute('data-url');
                    if (u) window.location.href = u;
                });
            });
        }

        // Initialize navigation & search system when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            try {
                window.cemeteryNavigation = new GoogleMapsNavigation();
                window.cemeterySearch = window.cemeteryNavigation;
            } catch(e) {
                console.error("Error creating GoogleMapsNavigation:", e);
            }

            var searchInput = document.getElementById('deceasedSearch');
            if (searchInput) {
                searchInput.oninput = function(e) {
                    window.handleCemeterySearchInput(this.value);
                };
                searchInput.onkeydown = function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        window.triggerCemeteryPlotSearch(true);
                    } else if (e.key === 'Escape') {
                        if (window.cemeteryNavigation) {
                            window.cemeteryNavigation.hideResults();
                        } else {
                            var r = document.getElementById('searchResults');
                            if (r) r.style.display = 'none';
                        }
                    }
                };
            }


            // Check if navigation flag is present and auto-start navigation
            var urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('navigate') === 'true') {
                var section = urlParams.get('section');
                var plotX = urlParams.get('plot_x');
                var plotY = urlParams.get('plot_y');

                if (section && plotX && plotY) {
                    setTimeout(function() {
                        if (window.cemeteryNavigation && typeof window.cemeteryNavigation.startGoogleMapsNavigation === 'function') {
                            window.cemeteryNavigation.startGoogleMapsNavigation(section, plotX, plotY);
                        }
                    }, 1000);
                }
            }

            // Handle highlighting section and automatically entering inside from reservation logs (Fixed Map, No Zoom)
            var highlightSec = (urlParams.get('section') || '').toUpperCase().trim();
            var targetPx = urlParams.get('plot_x');
            var targetPy = urlParams.get('plot_y');
            var isHighlightSection = urlParams.get('highlight_section') === '1' || urlParams.get('from') === 'reservation_logs';
            if (isHighlightSection) {
                try { sessionStorage.setItem('from_reservation_logs', '1'); } catch(e) {}
            }

            if (highlightSec && isHighlightSection) {
                var secLink = document.querySelector('.map-container a[href*="section=' + safeEscapeSec(highlightSec) + '"]');
                var secEl = secLink ? secLink.querySelector('.section') : null;
                if (!secEl) {
                    secEl = Array.from(document.querySelectorAll('.map-container .section[title]')).find(function(d){
                        return (d.getAttribute('title') || '').toUpperCase().includes('SECTION ' + highlightSec);
                    });
                    if (secEl && !secLink) secLink = secEl.closest('a');
                }

                if (secEl) {
                    // Highlight & blink section on the fixed map without any zoom, pan, or scaling
                    secEl.classList.add('search-section-highlight', 'section-blink');

                    // Transition to section popup after visual preview
                    var nextUrl = 'sections/popup.php?section=' + encodeURIComponent(highlightSec);
                    if (targetPx && targetPy) {
                        nextUrl += '&plot_x=' + encodeURIComponent(targetPx) + '&plot_y=' + encodeURIComponent(targetPy) + '&search_highlight=1&auto_open=1&from=reservation_logs';
                    } else {
                        nextUrl += '&from=reservation_logs';
                    }

                    setTimeout(function(){
                        window.location.href = nextUrl;
                    }, 1000);
                }
            }
        });
        </script>

    <?php
    $fromNavSuffix = $isGuest ? '&from=index' : ((!empty($_GET['from']) && $_GET['from'] !== 'dashboard') ? ('&from=' . urlencode($_GET['from'])) : '&from=map');
    ?>

    <a href="sections/popup.php?section=A<?php echo $moveSuffix . $serviceSuffix . $bookingSuffix . $fromNavSuffix; ?>">
        <div class="section" style="top: 590px; left: 120px; width: 120px; height: 80px; <?php echo $secStyles['A']; ?> border-radius: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15);" title="SECTION A"></div>
    </a>

    <a href="sections/popup.php?section=B<?php echo $moveSuffix . $serviceSuffix . $bookingSuffix . $fromNavSuffix; ?>">
        <div class="section" style="top: 500px; left: 118px; width: 120px; height: 80px; <?php echo $secStyles['B']; ?> border-radius: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15);" title="SECTION B"></div>
    </a>

    <a href="sections/popup.php?section=C<?php echo $moveSuffix . $serviceSuffix . $bookingSuffix . $fromNavSuffix; ?>">
        <div class="section" style="top: 500px; left: 250px; width: 120px; height: 80px; <?php echo $secStyles['C']; ?> border-radius: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15);" title="SECTION C"></div>
    </a>

    <a href="sections/popup.php?section=D<?php echo $moveSuffix . $serviceSuffix . $bookingSuffix . $fromNavSuffix; ?>">
         <div class="section" style="top: 590px; left: 250px; width: 120px; height: 80px; <?php echo $secStyles['D']; ?> border-radius: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15);" title="SECTION D"></div>
    </a>

    <a href="sections/popup.php?section=E<?php echo $moveSuffix . $serviceSuffix . $bookingSuffix . $fromNavSuffix; ?>">
         <div class="section" style="top: 595px; left: 385px; width: 100px; height: 80px; <?php echo $secStyles['E']; ?> border-radius: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15);" title="SECTION E"></div>
    </a>

    <a href="sections/popup.php?section=F<?php echo $moveSuffix . $serviceSuffix . $bookingSuffix . $fromNavSuffix; ?>">
        <div class="section" style="top: 505px; left: 385px; width: 100px; height: 80px; <?php echo $secStyles['F']; ?> border-radius: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15);" title="SECTION F"></div>
    </a>

    <a href="sections/popup.php?section=G<?php echo $moveSuffix . $serviceSuffix . $bookingSuffix . $fromNavSuffix; ?>">
        <div class="section" style="top: 290px; left: 330px; width: 60px; height: 180px; <?php echo $secStyles['G']; ?> border-radius: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15); transform: rotate(13deg);" title="SECTION G"></div>
    </a>

    <a href="sections/popup.php?section=H<?php echo $moveSuffix . $serviceSuffix . $bookingSuffix . $fromNavSuffix; ?>">
        <div class="section" style="top: 305px; left: 415px; width: 60px; height: 180px; <?php echo $secStyles['H']; ?> border-radius: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15); transform: rotate(13deg);" title="SECTION H"></div>
    </a>

    <a href="sections/popup.php?section=I<?php echo $moveSuffix . $serviceSuffix . $bookingSuffix . $fromNavSuffix; ?>">
      <div class="section" style="top: 95px; left: 380px; width: 60px; height: 180px; <?php echo $secStyles['I']; ?> border-radius: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15); transform: rotate(14deg);" title="SECTION I"></div>
    </a>

    <a href="sections/popup.php?section=J<?php echo $moveSuffix . $serviceSuffix . $bookingSuffix . $fromNavSuffix; ?>">
    <div class="section" style="top: 115px; left: 460px; width: 60px; height: 180px; <?php echo $secStyles['J']; ?> border-radius: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15); transform: rotate(15deg);" title="SECTION J"></div>
    </a>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var viewport = document.getElementById('mapViewport');
    var canvas = viewport ? viewport.querySelector('.map-container') : null;
    if (!viewport || !canvas) return;

    var baseWidth = 600;
    var baseHeight = 750;

    function isMobile() {
        return window.matchMedia('(max-width: 768px)').matches;
    }

    function scaleMap() {
        if (!isMobile()) {
            canvas.style.transform = '';
            canvas.style.transformOrigin = '';
            viewport.style.height = '';
            return;
        }
        var availWidth = viewport.clientWidth || (document.body.clientWidth - 12);
        if (availWidth <= 0) return;
        var scale = Math.min(1, availWidth / baseWidth);
        var scaledHeight = Math.round(baseHeight * scale);
        viewport.style.height = scaledHeight + 'px';
        canvas.style.transformOrigin = 'top center';
        canvas.style.transform = 'scale(' + scale + ')';
    }

    scaleMap();
    window.addEventListener('resize', scaleMap);
    window.addEventListener('orientationchange', function () {
        setTimeout(scaleMap, 150);
    });

    var mapImg = canvas.querySelector('img');
    if (mapImg) {
        if (mapImg.complete) scaleMap();
        else mapImg.addEventListener('load', scaleMap, { once: true });
    }
});
</script>

<script>
/**
 * CMIS Map Recently Clicked Architecture
 * Completely independent, isolated subsystems for User and Admin
 */
(function(){
    var CURRENT_USER_ID = <?php echo json_encode((int)($_SESSION['user_id'] ?? 0)); ?>;
    var CURRENT_USER_ROLE = <?php echo json_encode(strtoupper(trim((string)($_SESSION['role'] ?? (!empty($_SESSION['user_id']) ? 'USER' : 'GUEST'))))); ?>;
    window.CURRENT_USER_ID = CURRENT_USER_ID;
    window.CURRENT_USER_ROLE = CURRENT_USER_ROLE;

    // 1. Purge legacy obsolete shared keys from localStorage
    try {
        var legacyKeys = [
            'recently_clicked', 'recentlyClicked', 'recent_plots', 'clicked_plots',
            'recently_viewed', 'recently_clicked_user_0', 'recently_clicked_admin_0',
            'recently_clicked_user', 'recently_clicked_admin', 'recentlyClickedPlots'
        ];
        for (var i = 0; i < legacyKeys.length; i++) {
            localStorage.removeItem(legacyKeys[i]);
        }
    } catch(e) {}

    function resolveMapEndpoint() {
        try {
            var loc = window.location.pathname;
            if (loc.indexOf('/sections/') !== -1 || loc.indexOf('/Admin/') !== -1 || loc.indexOf('/User/') !== -1) {
                return '../map.php';
            }
            return 'map.php';
        } catch(e) {
            return 'map.php';
        }
    }

    function escapeHtml(str){
        if (!str) return '';
        return String(str).replace(/[&"'<>]/g, function(s){
            return ({'&':'&amp;','"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;'})[s];
        });
    }

    function buildItemHtml(items, roleLabel) {
        if (!Array.isArray(items) || items.length === 0) {
            return '<div class="rap-empty-state" style="text-align:center;padding:2rem 1rem;color:#64748b;font-size:0.95rem;">'
                 + '<i class="fas fa-clock-rotate-left" style="font-size:2rem;margin-bottom:0.6rem;opacity:0.35;display:block;color:#7b1113;"></i>'
                 + 'No recently viewed plots.'
                 + '</div>';
        }
        var html = '';
        items.forEach(function(a){
            var user = (a.user_name && a.user_name.trim()) ? a.user_name.trim() : roleLabel;
            var time = a.clicked_at ? new Date(a.clicked_at).toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'}) : (a.ts ? new Date(a.ts).toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'}) : 'Recently');
            var sec = (a.section || '').toString().trim().toUpperCase();
            var px = (a.plot_x || '').toString().trim();
            var py = (a.plot_y || '').toString().trim();
            var gname = (a.grave_name || a.deceased_name || a.details || '').toString().trim();
            var gid = (a.grave_id || a.plot_id || '').toString().trim();

            var titleText = '';
            if (sec && px && py) {
                titleText = 'Section ' + sec + ' · Plot ' + px + '-' + py;
                if (gname && gname.toLowerCase().indexOf('section') === -1) {
                    titleText += ' (' + gname + ')';
                }
            } else if (gname) {
                titleText = gname;
            } else if (sec) {
                titleText = 'Section ' + sec;
            } else {
                titleText = 'Viewed Plot';
            }

            var href = '#';
            if (sec && px && py) {
                href = 'sections/popup.php?section=' + encodeURIComponent(sec) + '&plot_x=' + encodeURIComponent(px) + '&plot_y=' + encodeURIComponent(py) + '&from=recently_clicked';
            } else if (gid) {
                href = 'sections/popup.php?grave_id=' + encodeURIComponent(gid) + '&from=recently_clicked';
            } else if (sec) {
                href = 'sections/popup.php?section=' + encodeURIComponent(sec) + '&from=recently_clicked';
            }

            html += '<a class="rap-link" href="' + escapeHtml(href) + '" data-grave-id="' + escapeHtml(gid) + '" data-grave-name="' + escapeHtml(gname) + '" data-section="' + escapeHtml(sec) + '" data-plot-x="' + escapeHtml(px) + '" data-plot-y="' + escapeHtml(py) + '">'
                + '<div class="rap-item">'
                + '<div class="rap-item-text"><i class="fas fa-location-dot" style="color:#7b1113;margin-right:6px;font-size:0.85rem;"></i>' + escapeHtml(titleText) + '</div>'
                + '<div class="rap-item-meta"><i class="far fa-clock" style="margin-right:4px;"></i>' + escapeHtml(time) + ' · ' + escapeHtml(user) + '</div>'
                + '</div></a>';
        });
        return html;
    }

    // ==========================================
    // 2. USER RECENTLY CLICKED SUBSYSTEM
    // ==========================================
    window.userRecentlyClickedPlots = [];

    window.getUserRecentlyClickedStorageKey = function() {
        var uid = (CURRENT_USER_ID && parseInt(CURRENT_USER_ID, 10) > 0) ? parseInt(CURRENT_USER_ID, 10) : 0;
        return 'cmis_user_recently_clicked_' + uid;
    };

    window.renderUserRecentlyClicked = function(list) {
        var items = (Array.isArray(list) && list.length > 0) ? list.slice() : [];
        if (items.length === 0) {
            try {
                var storageKey = window.getUserRecentlyClickedStorageKey();
                var localList = JSON.parse(localStorage.getItem(storageKey) || localStorage.getItem('recently_clicked') || '[]');
                if (Array.isArray(localList) && localList.length > 0) {
                    items = localList;
                }
            } catch(e) {}
        }
        window.userRecentlyClickedPlots = items;
        var html = buildItemHtml(items, 'You');
        var modalBody = document.getElementById('recentModalBody');
        if (modalBody) modalBody.innerHTML = html;
        var body = document.getElementById('recentActivityBody');
        if (body) body.innerHTML = html;
    };

    window.loadUserRecentlyClicked = async function() {
        if (!CURRENT_USER_ID || CURRENT_USER_ID <= 0) {
            window.renderUserRecentlyClicked([]);
            return;
        }
        try {
            var endpoint = resolveMapEndpoint();
            var resp = await fetch(endpoint, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'ajax_request=get_user_recently_clicked'
            });
            if (!resp.ok) {
                window.renderUserRecentlyClicked([]);
                return;
            }
            var data = await resp.json();
            if (data && data.success && Array.isArray(data.activities)) {
                window.renderUserRecentlyClicked(data.activities);
            } else {
                window.renderUserRecentlyClicked([]);
            }
        } catch (e) {
            window.renderUserRecentlyClicked([]);
        }
    };

    window.saveUserRecentlyClicked = function(plotData) {
        if (!plotData || !plotData.section || !plotData.plot_x || !plotData.plot_y) return;
        var storageKey = window.getUserRecentlyClickedStorageKey();
        try {
            var list = JSON.parse(localStorage.getItem(storageKey) || '[]');
            list = list.filter(function(item){
                return !(item.section === plotData.section && String(item.plot_x) === String(plotData.plot_x) && String(item.plot_y) === String(plotData.plot_y));
            });
            list.unshift({
                section: plotData.section,
                plot_x: plotData.plot_x,
                plot_y: plotData.plot_y,
                grave_id: plotData.grave_id || null,
                grave_name: plotData.grave_name || null,
                details: plotData.details || null,
                ts: new Date().toISOString()
            });
            if (list.length > 20) list.pop();
            localStorage.setItem(storageKey, JSON.stringify(list));
            window.userRecentlyClickedPlots = list;
        } catch(e) {}

        if (!CURRENT_USER_ID || CURRENT_USER_ID <= 0) return;

        var params = new URLSearchParams();
        params.append('ajax_request', 'log_user_recent_click');
        params.append('section', plotData.section);
        params.append('plot_x', plotData.plot_x);
        params.append('plot_y', plotData.plot_y);
        if (plotData.grave_id) params.append('grave_id', plotData.grave_id);
        if (plotData.grave_name) params.append('grave_name', plotData.grave_name);
        if (plotData.details) params.append('details', plotData.details);

        var endpoint = resolveMapEndpoint();
        if (navigator.sendBeacon) {
            var blob = new Blob([params.toString()], {type: 'application/x-www-form-urlencoded'});
            navigator.sendBeacon(endpoint, blob);
        } else {
            fetch(endpoint, {method: 'POST', body: params.toString(), headers: {'Content-Type':'application/x-www-form-urlencoded'}, keepalive: true}).catch(function(){});
        }
    };

    window.clearUserRecentlyClicked = function() {
        try { localStorage.removeItem(window.getUserRecentlyClickedStorageKey()); } catch(e){}
        window.userRecentlyClickedPlots = [];
        window.renderUserRecentlyClicked([]);
    };

    // ==========================================
    // 3. ADMIN RECENTLY CLICKED SUBSYSTEM
    // ==========================================
    window.adminRecentlyClickedPlots = [];

    window.getAdminRecentlyClickedStorageKey = function() {
        var uid = (CURRENT_USER_ID && parseInt(CURRENT_USER_ID, 10) > 0) ? parseInt(CURRENT_USER_ID, 10) : 0;
        return 'cmis_admin_recently_clicked_' + uid;
    };

    window.renderAdminRecentlyClicked = function(list) {
        var items = (Array.isArray(list) && list.length > 0) ? list.slice() : [];
        if (items.length === 0) {
            try {
                var storageKey = window.getAdminRecentlyClickedStorageKey();
                var localList = JSON.parse(localStorage.getItem(storageKey) || localStorage.getItem('recently_clicked') || '[]');
                if (Array.isArray(localList) && localList.length > 0) {
                    items = localList;
                }
            } catch(e) {}
        }
        window.adminRecentlyClickedPlots = items;
        var html = buildItemHtml(items, 'Admin');
        var modalBody = document.getElementById('recentModalBody');
        if (modalBody) modalBody.innerHTML = html;
        var body = document.getElementById('recentActivityBody');
        if (body) body.innerHTML = html;
    };

    window.loadAdminRecentlyClicked = async function() {
        try {
            var endpoint = resolveMapEndpoint();
            var resp = await fetch(endpoint, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'ajax_request=get_admin_recently_clicked'
            });
            if (!resp.ok) {
                window.renderAdminRecentlyClicked([]);
                return;
            }
            var data = await resp.json();
            if (data && data.success && Array.isArray(data.activities)) {
                window.renderAdminRecentlyClicked(data.activities);
            } else {
                window.renderAdminRecentlyClicked([]);
            }
        } catch (e) {
            window.renderAdminRecentlyClicked([]);
        }
    };

    window.saveAdminRecentlyClicked = function(plotData) {
        if (!plotData || !plotData.section || !plotData.plot_x || !plotData.plot_y) return;
        var storageKey = window.getAdminRecentlyClickedStorageKey();
        try {
            var list = JSON.parse(localStorage.getItem(storageKey) || '[]');
            list = list.filter(function(item){
                return !(item.section === plotData.section && String(item.plot_x) === String(plotData.plot_x) && String(item.plot_y) === String(plotData.plot_y));
            });
            list.unshift({
                section: plotData.section,
                plot_x: plotData.plot_x,
                plot_y: plotData.plot_y,
                grave_id: plotData.grave_id || null,
                grave_name: plotData.grave_name || null,
                details: plotData.details || null,
                ts: new Date().toISOString()
            });
            if (list.length > 20) list.pop();
            localStorage.setItem(storageKey, JSON.stringify(list));
            window.adminRecentlyClickedPlots = list;
        } catch(e) {}

        var params = new URLSearchParams();
        params.append('ajax_request', 'log_admin_recent_click');
        params.append('section', plotData.section);
        params.append('plot_x', plotData.plot_x);
        params.append('plot_y', plotData.plot_y);
        if (plotData.grave_id) params.append('grave_id', plotData.grave_id);
        if (plotData.grave_name) params.append('grave_name', plotData.grave_name);
        if (plotData.details) params.append('details', plotData.details);

        var endpoint = resolveMapEndpoint();
        if (navigator.sendBeacon) {
            var blob = new Blob([params.toString()], {type: 'application/x-www-form-urlencoded'});
            navigator.sendBeacon(endpoint, blob);
        } else {
            fetch(endpoint, {method: 'POST', body: params.toString(), headers: {'Content-Type':'application/x-www-form-urlencoded'}, keepalive: true}).catch(function(){});
        }
    };

    window.clearAdminRecentlyClicked = function() {
        try { localStorage.removeItem(window.getAdminRecentlyClickedStorageKey()); } catch(e){}
        window.adminRecentlyClickedPlots = [];
        window.renderAdminRecentlyClicked([]);
    };

    // ==========================================
    // 4. MAP INITIALIZATION & CONTEXT BINDING
    // ==========================================
    document.addEventListener('DOMContentLoaded', function(){
        var toggle = document.getElementById('recentToggleBtn');
        var modal = document.getElementById('recentModal');

        if (toggle && modal) {
            toggle.addEventListener('click', function(e){
                e.preventDefault();
                modal.style.display = 'flex';
                if (CURRENT_USER_ROLE === 'ADMIN') {
                    window.loadAdminRecentlyClicked();
                } else {
                    window.loadUserRecentlyClicked();
                }
            });

            modal.addEventListener('click', function(ev){
                if (ev.target === modal) {
                    modal.style.display = 'none';
                }
            });

            var closeBtn = document.getElementById('recentModalClose');
            if (closeBtn) {
                closeBtn.addEventListener('click', function(e){
                    e.preventDefault();
                    modal.style.display = 'none';
                });
            }

            if (window.location.hash && window.location.hash.indexOf('recent') !== -1) {
                modal.style.display = 'flex';
                if (CURRENT_USER_ROLE === 'ADMIN') {
                    window.loadAdminRecentlyClicked();
                } else {
                    window.loadUserRecentlyClicked();
                }
                try { history.replaceState(null, null, window.location.pathname + window.location.search); } catch(e){}
            }

            document.addEventListener('click', function(ev){
                var a = ev.target.closest('a[href*="sections/popup.php?section="]');
                if (!a) return;
                try {
                    var u = new URL(a.href, window.location.origin);
                    var sec = u.searchParams.get('section') || '';
                    var px = u.searchParams.get('plot_x') || '';
                    var py = u.searchParams.get('plot_y') || '';
                    var graveId = a.getAttribute('data-grave-id') || (a.dataset && a.dataset.graveId) || '';
                    var graveName = a.getAttribute('data-grave-name') || (a.dataset && a.dataset.graveName) || '';
                    var plotInfo = {
                        section: sec,
                        plot_x: px,
                        plot_y: py,
                        grave_id: graveId,
                        grave_name: graveName,
                        details: (sec && px && py) ? ('Clicked plot ' + sec + '-' + px + '-' + py + (graveName ? (' (' + graveName + ')') : '')) : ('Clicked section ' + sec)
                    };

                    if (CURRENT_USER_ROLE === 'ADMIN') {
                        window.saveAdminRecentlyClicked(plotInfo);
                    } else {
                        window.saveUserRecentlyClicked(plotInfo);
                    }
                } catch (e) {}
            }, true);
        }
    });
})();
</script>

<button id="recentToggleBtn" class="recent-activity-toggle" type="button" aria-label="View Recently Clicked Plots" title="Recently Clicked Plots">
    <i class="fas fa-clock-rotate-left"></i> Recently Clicked
</button>

<div id="recentModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center;">
  <div class="recent-modal-inner" style="background:#fff;border-radius:14px;max-width:480px;width:92%;margin:auto;padding:18px 20px;position:relative;box-shadow:0 10px 40px rgba(0,0,0,0.25);box-sizing:border-box;">
    <button id="recentModalClose" type="button" style="position:absolute;right:14px;top:12px;border:0;background:transparent;font-size:24px;cursor:pointer;color:#64748b;line-height:1;" aria-label="Close">&times;</button>
    <h5 style="margin:0 0 12px 0;color:#7b1113;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:8px;">
        <i class="fas fa-clock-rotate-left"></i> Recently Clicked Plots
    </h5>
    <div id="recentModalBody" style="max-height:60vh;overflow-y:auto;padding-top:4px;">

    </div>
  </div>
</div>

<?php if ($isLoggedIn && function_exists('render_notification_containers')) echo render_notification_containers(); ?>
</body>
</html>

<?php

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

@ini_set('display_errors', '0');
if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Asia/Manila');
}

if (function_exists('mysqli_report')) {
    @mysqli_report(MYSQLI_REPORT_OFF);
}

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$userRole = strtoupper(trim((string)($_SESSION['role'] ?? 'GUEST')));
$isAdmin = ($isLoggedIn && ($userRole === 'ADMIN' || !empty($_SESSION['admin_id'])));
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    if (file_exists(__DIR__ . '/connection.php')) {
        require_once __DIR__ . '/connection.php';
    } elseif (file_exists(__DIR__ . '/../connection.php')) {
        require_once __DIR__ . '/../connection.php';
    }

    if (file_exists(__DIR__ . '/includes/gps_calculator.php')) {
        require_once __DIR__ . '/includes/gps_calculator.php';
    } elseif (file_exists(__DIR__ . '/../includes/gps_calculator.php')) {
        require_once __DIR__ . '/../includes/gps_calculator.php';
    }

    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
        if (function_exists('get_mailer_db_connection')) {
            $conn = get_mailer_db_connection();
        }
    }

    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
        echo json_encode([
            'success' => false,
            'count'   => 0,
            'results' => [],
            'message' => 'Database connection unavailable.'
        ]);
        exit;
    }

    $rawQuery = trim((string)($_REQUEST['q'] ?? $_REQUEST['query'] ?? $_REQUEST['search'] ?? $_REQUEST['search_term'] ?? $_REQUEST['term'] ?? ''));
    $filterYear = trim((string)($_REQUEST['year'] ?? ''));
    $filterDateFrom = trim((string)($_REQUEST['date_from'] ?? ''));
    $filterDateTo = trim((string)($_REQUEST['date_to'] ?? ''));
    $filterSection = strtoupper(trim((string)($_REQUEST['section'] ?? '')));

    $hasFilter = (!empty($filterYear) || !empty($filterDateFrom) || !empty($filterDateTo) || !empty($filterSection));

    if (empty($rawQuery) && !$hasFilter) {
        echo json_encode([
            'success' => true,
            'count'   => 0,
            'results' => [],
            'message' => 'Please enter a deceased name, plot number, or select a filter.'
        ]);
        exit;
    }

    $query = preg_replace('/\s+/', ' ', $rawQuery);
    $queryTrimmed = trim($query);

    $results = [];
    $matchedPlotKeys = []; 

    $safeFetchOne = function($stmt) {
        if (!$stmt) return null;
        try {
            $res = $stmt->get_result();
            if ($res && ($res instanceof mysqli_result)) {
                return $res->fetch_assoc();
            }
        } catch (Throwable $e) {}
        return null;
    };

    $safeFetchAll = function($stmt) {
        if (!$stmt) return [];
        try {
            $res = $stmt->get_result();
            if ($res && ($res instanceof mysqli_result)) {
                $rows = [];
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
                return $rows;
            }
        } catch (Throwable $e) {}
        return [];
    };

    $hasSuffix = false;
    try {
        $suffixCheck = @$conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'suffix'");
        if ($suffixCheck && ($suffixCheck instanceof mysqli_result) && $suffixCheck->num_rows > 0) {
            $hasSuffix = true;
        }
    } catch (Throwable $e) {}

    $hasUpoTable = false;
    try {
        $chkUpo = @$conn->query("SHOW TABLES LIKE 'user_plot_ownership'");
        if ($chkUpo && ($chkUpo instanceof mysqli_result) && $chkUpo->num_rows > 0) {
            $hasUpoTable = true;
        }
    } catch (Throwable $e) {}

    $hasPoTable = false;
    try {
        $chkPo = @$conn->query("SHOW TABLES LIKE 'tbl_plot_owners'");
        if ($chkPo && ($chkPo instanceof mysqli_result) && $chkPo->num_rows > 0) {
            $hasPoTable = true;
        }
    } catch (Throwable $e) {}

    $hasPlotsTable = false;
    try {
        $chkPlots = @$conn->query("SHOW TABLES LIKE 'tbl_plots'");
        if ($chkPlots && ($chkPlots instanceof mysqli_result) && $chkPlots->num_rows > 0) {
            $hasPlotsTable = true;
        }
    } catch (Throwable $e) {}

    $hasBookingsTable = false;
    try {
        $chkBk = @$conn->query("SHOW TABLES LIKE 'tbl_bookings'");
        if ($chkBk && ($chkBk instanceof mysqli_result) && $chkBk->num_rows > 0) {
            $hasBookingsTable = true;
        }
    } catch (Throwable $e) {}

    $addResult = function($section, $plotX, $plotY, $deceasedName = '', $ownerName = '', $bookingId = null, $plotStatus = null, $deceasedId = null, $plotId = null) use (&$results, &$matchedPlotKeys, $conn, $hasSuffix, $hasUpoTable, $hasPoTable, $hasPlotsTable, $safeFetchOne, $isAdmin) {
        $sec = strtoupper(trim((string)$section));
        $px = (int)$plotX;
        $py = (int)$plotY;
        if (empty($sec) || $px <= 0 || $py <= 0) return;

        $key = "$sec-$px-$py";
        if (isset($matchedPlotKeys[$key])) {

            if (!empty($deceasedName) || !empty($ownerName)) {
                foreach ($results as &$r) {
                    if ($r['section'] === $sec && $r['plot_x'] === $px && $r['plot_y'] === $py) {
                        if (empty($r['deceased_name']) && !empty($deceasedName)) {
                            $r['deceased_name'] = $deceasedName;
                            $r['deceased_id'] = $deceasedId ? (int)$deceasedId : $r['deceased_id'];
                            $r['status'] = 'occupied';
                            $r['status_label'] = 'OCCUPIED';
                        }
                        if (empty($r['owner_name']) && !empty($ownerName)) {
                            $r['owner_name'] = $ownerName;
                            if ($r['status'] === 'available') {
                                $r['status'] = 'reserved';
                                $r['status_label'] = 'RESERVED';
                            }
                        }
                        break;
                    }
                }
                unset($r);
            }
            return;
        }
        $matchedPlotKeys[$key] = true;

        $resolvedPlotId = $plotId ? (int)$plotId : null;
        $lawnType = 'Lawn (Regular)';
        $price = 85000.00;
        $dbStatus = 'available';
        $latitude = null;
        $longitude = null;

        if ($hasPlotsTable) {
            try {
                $pStmt = @$conn->prepare("SELECT plot_id, lawn_type, price, status, latitude, longitude FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
                if ($pStmt) {
                    $pStmt->bind_param('sii', $sec, $px, $py);
                    $pStmt->execute();
                    $pRow = $safeFetchOne($pStmt);
                    if ($pRow) {
                        $resolvedPlotId = (int)$pRow['plot_id'];
                        $lawnType = !empty($pRow['lawn_type']) ? $pRow['lawn_type'] : $lawnType;
                        $price = !empty($pRow['price']) ? (float)$pRow['price'] : $price;
                        $dbStatus = strtolower(trim(!empty($pRow['status']) ? $pRow['status'] : 'available'));
                        if (isset($pRow['latitude']) && $pRow['latitude'] !== null && isset($pRow['longitude']) && $pRow['longitude'] !== null) {
                            $latitude = (float)$pRow['latitude'];
                            $longitude = (float)$pRow['longitude'];
                        }
                    }
                    $pStmt->close();
                }
            } catch (Throwable $e) {}
        }

        if (($latitude === null || $longitude === null) && class_exists('CemeteryGPSCalculator')) {
            $gpsCalc = CemeteryGPSCalculator::calculatePlotGPS($conn, $resolvedPlotId, $sec, $px, $py, true);
            if (!empty($gpsCalc['success']) && !empty($gpsCalc['has_coordinates'])) {
                $latitude = (float)$gpsCalc['latitude'];
                $longitude = (float)$gpsCalc['longitude'];
            }
        }

        $resolvedDeceasedId = $deceasedId ? (int)$deceasedId : null;
        if (empty($deceasedName)) {
            try {
                $dSql = $hasSuffix 
                    ? "SELECT deceased_id, TRIM(CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(middle_name,''), NULLIF(last_name,''), NULLIF(suffix,''))) AS dname FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1"
                    : "SELECT deceased_id, TRIM(CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(middle_name,''), NULLIF(last_name,''))) AS dname FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1";
                $dStmt = @$conn->prepare($dSql);
                if ($dStmt) {
                    $dStmt->bind_param('sii', $sec, $px, $py);
                    $dStmt->execute();
                    $dRow = $safeFetchOne($dStmt);
                    if ($dRow) {
                        $deceasedName = trim($dRow['dname'] ?? '');
                        $resolvedDeceasedId = (int)$dRow['deceased_id'];
                    }
                    $dStmt->close();
                }
            } catch (Throwable $e) {}
        }

        if (empty($ownerName)) {

            if ($hasUpoTable) {
                try {
                    $uStmt = @$conn->prepare("SELECT CONCAT_WS(' ', NULLIF(u.first_name,''), NULLIF(u.last_name,'')) AS uname, upo.ownership_status 
                                              FROM user_plot_ownership upo 
                                              JOIN tbl_user u ON u.user_id = upo.user_id 
                                              WHERE UPPER(TRIM(upo.section)) = ? AND upo.plot_x = ? AND upo.plot_y = ? AND (upo.verification_status IS NULL OR upo.verification_status != 'REJECTED') 
                                              LIMIT 1");
                    if ($uStmt) {
                        $uStmt->bind_param('sii', $sec, $px, $py);
                        $uStmt->execute();
                        $uRow = $safeFetchOne($uStmt);
                        if ($uRow) {
                            $ownerName = trim($uRow['uname'] ?? '');
                            if (empty($plotStatus) || strtolower($plotStatus) === 'occupied' || strtolower($plotStatus) === 'available') {
                                $plotStatus = strtolower(trim($uRow['ownership_status'] ?? 'reserved'));
                            }
                        }
                        $uStmt->close();
                    }
                } catch (Throwable $e) {}
            }

            if (empty($ownerName) && $hasPoTable) {
                try {
                    $poStmt = @$conn->prepare("SELECT TRIM(COALESCE(NULLIF(owner_name,''), CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(last_name,'')))) AS oname 
                                              FROM tbl_plot_owners 
                                              WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND active = 1 
                                              LIMIT 1");
                    if ($poStmt) {
                        $poStmt->bind_param('sii', $sec, $px, $py);
                        $poStmt->execute();
                        $poRow = $safeFetchOne($poStmt);
                        if ($poRow) {
                            $poName = trim($poRow['oname'] ?? '');
                            if ($poName !== '' && strcasecmp($poName, 'Reserved') !== 0) {
                                $ownerName = $poName;
                                if (empty($plotStatus) || strtolower($plotStatus) === 'occupied' || strtolower($plotStatus) === 'available') {
                                    $plotStatus = 'reserved';
                                }
                            }
                        }
                        $poStmt->close();
                    }
                } catch (Throwable $e) {}
            }

            if (empty($ownerName)) {
                try {
                    $bpStmt = @$conn->prepare("SELECT b.customer_name, b.full_name, b.service_type, b.purpose 
                                               FROM booking_plots bp 
                                               JOIN tbl_bookings b ON b.id = bp.booking_id 
                                               WHERE UPPER(TRIM(bp.section)) = ? AND bp.plot_x = ? AND bp.plot_y = ? AND LOWER(TRIM(b.status)) NOT IN ('cancelled', 'rejected') 
                                               LIMIT 1");
                    if ($bpStmt) {
                        $bpStmt->bind_param('sii', $sec, $px, $py);
                        $bpStmt->execute();
                        $bpRow = $safeFetchOne($bpStmt);
                        if ($bpRow) {
                            $ownerName = trim($bpRow['customer_name'] ?: ($bpRow['full_name'] ?: ''));
                            if (empty($plotStatus) || strtolower($plotStatus) === 'available') {
                                $plotStatus = 'reserved';
                            }
                        }
                        $bpStmt->close();
                    }
                } catch (Throwable $e) {}
            }

            if (empty($ownerName)) {
                try {
                    $grStmt = @$conn->prepare("SELECT full_name FROM guest_reservation_requests WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND LOWER(TRIM(status)) NOT IN ('cancelled', 'rejected') LIMIT 1");
                    if ($grStmt) {
                        $grStmt->bind_param('sii', $sec, $px, $py);
                        $grStmt->execute();
                        $grRow = $safeFetchOne($grStmt);
                        if ($grRow) {
                            $ownerName = trim($grRow['full_name'] ?? '');
                            if (empty($plotStatus) || strtolower($plotStatus) === 'available') {
                                $plotStatus = 'reserved';
                            }
                        }
                        $grStmt->close();
                    }
                } catch (Throwable $e) {}
            }
        }

        if (!empty($deceasedName)) {
            $finalStatus = 'occupied';
        } else {

            $candidateStatus = $plotStatus ? strtolower($plotStatus) : $dbStatus;
            if ($candidateStatus === 'occupied') {

                $finalStatus = (!empty($ownerName)) ? 'reserved' : 'available';
            } elseif ($candidateStatus === 'reserved' || $candidateStatus === 'purchased') {
                $finalStatus = $candidateStatus;
            } else {
                $finalStatus = (!empty($ownerName)) ? 'reserved' : 'available';
            }
        }

        $formattedCode = sprintf("%s-%02d%04d", $sec, $px, $py);
        $displayCode = "$sec-$px-$py";
        $plotNumber = "$sec $px-$py"; 

        $results[] = [
            'deceased_id'    => $resolvedDeceasedId,
            'deceased_name'  => $deceasedName ?: null,
            'owner_name'     => $ownerName ?: null,
            'section'        => $sec,
            'plot_id'        => $resolvedPlotId,
            'plot_x'         => $px,
            'plot_y'         => $py,
            'plot_number'    => $plotNumber,
            'plot_code'      => $displayCode,
            'plot_formatted' => $formattedCode,
            'lawn_type'      => $lawnType,
            'price'          => $price,
            'status'         => $finalStatus,
            'status_label'   => strtoupper($finalStatus),
            'booking_id'     => $bookingId ? (int)$bookingId : null,
            'latitude'       => $latitude,
            'longitude'      => $longitude
        ];
    };

    $words = preg_split('/\s+/', $queryTrimmed);
    $words = array_values(array_filter($words, function($w) { return strlen($w) >= 2; }));

    $conds = [];
    $types = '';
    $params = [];

    $likeWhole = '%' . strtolower($queryTrimmed) . '%';

    $nameExpression = $hasSuffix
        ? "TRIM(CONCAT_WS(' ', NULLIF(d.first_name,''), NULLIF(d.middle_name,''), NULLIF(d.last_name,''), NULLIF(d.suffix,'')))"
        : "TRIM(CONCAT_WS(' ', NULLIF(d.first_name,''), NULLIF(d.middle_name,''), NULLIF(d.last_name,'')))";

    $conds[] = "LOWER($nameExpression) LIKE ?";
    $types .= 's';
    $params[] = $likeWhole;

    $conds[] = "LOWER(TRIM(CONCAT_WS(' ', NULLIF(d.first_name,''), NULLIF(d.last_name,'')))) LIKE ?";
    $types .= 's';
    $params[] = $likeWhole;

    $conds[] = "LOWER(d.first_name) LIKE ?";
    $types .= 's';
    $params[] = $likeWhole;

    $conds[] = "LOWER(d.middle_name) LIKE ?";
    $types .= 's';
    $params[] = $likeWhole;

    $conds[] = "LOWER(d.last_name) LIKE ?";
    $types .= 's';
    $params[] = $likeWhole;

    if (count($words) >= 2) {
        $wordConds = [];
        foreach ($words as $w) {
            $wordConds[] = "(LOWER(d.first_name) LIKE ? OR LOWER(d.last_name) LIKE ? OR LOWER(d.middle_name) LIKE ?)";
            $types .= 'sss';
            $wLike = '%' . strtolower($w) . '%';
            $params[] = $wLike;
            $params[] = $wLike;
            $params[] = $wLike;
        }
        if (!empty($wordConds)) {
            $conds[] = "(" . implode(' AND ', $wordConds) . ")";
        }
    }

    $fClauses = [];
    if (!empty($filterYear) && is_numeric($filterYear)) {
        $escY = (int)$filterYear;
        $fClauses[] = "(YEAR(d.date_died) = $escY OR YEAR(d.interment_date) = $escY OR YEAR(d.bday) = $escY)";
    }
    if (!empty($filterDateFrom)) {
        $escFrom = $conn->real_escape_string($filterDateFrom);
        $fClauses[] = "(COALESCE(NULLIF(d.interment_date, '0000-00-00'), d.date_died) >= '$escFrom')";
    }
    if (!empty($filterDateTo)) {
        $escTo = $conn->real_escape_string($filterDateTo);
        $fClauses[] = "(COALESCE(NULLIF(d.interment_date, '0000-00-00'), d.date_died) <= '$escTo')";
    }
    if (!empty($filterSection)) {
        $escSec = $conn->real_escape_string($filterSection);
        $fClauses[] = "(UPPER(TRIM(d.section)) = '$escSec' OR UPPER(TRIM(d.section)) = 'SECTION $escSec' OR UPPER(TRIM(d.section)) LIKE '%$escSec%')";
    }

    if (!empty($queryTrimmed)) {
        $allDecWhere = [];
        if (!empty($conds)) {
            $allDecWhere[] = "(" . implode(' OR ', $conds) . ")";
        }
        if (!empty($fClauses)) {
            $allDecWhere[] = "(" . implode(' AND ', $fClauses) . ")";
        }
        $whereSql = !empty($allDecWhere) ? "WHERE " . implode(' AND ', $allDecWhere) : "";

        $decSql = "SELECT d.deceased_id, d.section, d.plot_x, d.plot_y, $nameExpression AS full_name
                   FROM tbl_deceased d
                   $whereSql
                   ORDER BY d.last_name ASC, d.first_name ASC LIMIT 50";
        $decStmt = @$conn->prepare($decSql);

        if ($decStmt) {
            $decStmt->bind_param($types, ...$params);
            $decStmt->execute();
            $decRows = $safeFetchAll($decStmt);
            foreach ($decRows as $dRow) {
                $addResult(
                    $dRow['section'],
                    $dRow['plot_x'],
                    $dRow['plot_y'],
                    $dRow['full_name'],
                    '',
                    null,
                    'occupied',
                    $dRow['deceased_id'],
                    null
                );
            }
            $decStmt->close();
        }
    } elseif ($hasFilter && !empty($fClauses)) {

        $whereSql = "WHERE " . implode(' AND ', $fClauses);
        $decSql = "SELECT d.deceased_id, d.section, d.plot_x, d.plot_y, $nameExpression AS full_name
                   FROM tbl_deceased d
                   $whereSql
                   ORDER BY d.last_name ASC, d.first_name ASC LIMIT 50";
        $res = $conn->query($decSql);
        if ($res) {
            while ($dRow = $res->fetch_assoc()) {
                $addResult(
                    $dRow['section'],
                    $dRow['plot_x'],
                    $dRow['plot_y'],
                    $dRow['full_name'],
                    '',
                    null,
                    'occupied',
                    $dRow['deceased_id'],
                    null
                );
            }
            $res->free();
        }

        if (empty($results) && !empty($filterSection) && $hasPlotsTable) {
            $escSec = $conn->real_escape_string($filterSection);
            $secPlotsRes = $conn->query("SELECT section, plot_x, plot_y, plot_id, status FROM tbl_plots WHERE (UPPER(TRIM(section)) = '$escSec' OR UPPER(TRIM(section)) = 'SECTION $escSec' OR UPPER(TRIM(section)) LIKE '%$escSec%') ORDER BY plot_x ASC, plot_y ASC LIMIT 50");
            if ($secPlotsRes) {
                while ($spRow = $secPlotsRes->fetch_assoc()) {
                    $addResult($spRow['section'], $spRow['plot_x'], $spRow['plot_y'], '', '', null, $spRow['status'], null, $spRow['plot_id']);
                }
                $secPlotsRes->free();
            }
        }
    }

    if (preg_match('/^([A-Ja-j])[-_\s]*(\d{2})(\d{4})$/i', $queryTrimmed, $m)) {
        $sec = strtoupper($m[1]);
        $px = (int)$m[2];
        $py = (int)$m[3];
        if (empty($filterSection) || $sec === $filterSection) {
            $addResult($sec, $px, $py);
        }
    }

    elseif (preg_match('/^(?:SECTION|SEC)?\s*([A-Ja-j])[-_\s]*(?:PLOT)?\s*(\d+)[-_\s,]+(\d+)$/i', $queryTrimmed, $m)) {
        $sec = strtoupper($m[1]);
        $px = (int)$m[2];
        $py = (int)$m[3];
        if (empty($filterSection) || $sec === $filterSection) {
            $addResult($sec, $px, $py);
        }
    }

    elseif (preg_match('/^(\d+)[-_\s,]+(\d+)$/', $queryTrimmed, $m)) {
        $px = (int)$m[1];
        $py = (int)$m[2];

        try {
            $decByCoord = @$conn->prepare($hasSuffix 
                ? "SELECT deceased_id, section, plot_x, plot_y, TRIM(CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(middle_name,''), NULLIF(last_name,''), NULLIF(suffix,''))) AS dname FROM tbl_deceased WHERE plot_x = ? AND plot_y = ?"
                : "SELECT deceased_id, section, plot_x, plot_y, TRIM(CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(middle_name,''), NULLIF(last_name,''))) AS dname FROM tbl_deceased WHERE plot_x = ? AND plot_y = ?"
            );
            if ($decByCoord) {
                $decByCoord->bind_param('ii', $px, $py);
                $decByCoord->execute();
                $dcRows = $safeFetchAll($decByCoord);
                foreach ($dcRows as $dc) {
                    if (!empty($filterSection) && strtoupper($dc['section']) !== $filterSection) continue;
                    $addResult($dc['section'], $px, $py, $dc['dname'], '', null, 'occupied', $dc['deceased_id'], null);
                }
                $decByCoord->close();
            }
        } catch (Throwable $e) {}

        if ($hasPlotsTable) {
            try {
                $secStmt = @$conn->prepare("SELECT section, plot_id, status FROM tbl_plots WHERE plot_x = ? AND plot_y = ? ORDER BY section ASC");
                if ($secStmt) {
                    $secStmt->bind_param('ii', $px, $py);
                    $secStmt->execute();
                    $sRows = $safeFetchAll($secStmt);
                    foreach ($sRows as $sRow) {
                        if (!empty($filterSection) && strtoupper($sRow['section']) !== $filterSection) continue;
                        $addResult($sRow['section'], $px, $py, '', '', null, $sRow['status'], null, $sRow['plot_id']);
                    }
                    $secStmt->close();
                }
            } catch (Throwable $e) {}
        }
    }

    if ($hasBookingsTable && preg_match('/^(?:#|REF|BOOKING[-_\s]*#?)?(\d{1,6})$/i', $queryTrimmed, $m)) {
        $bId = (int)$m[1];
        if ($bId > 0) {
            try {
                $bStmt = @$conn->prepare("SELECT id, section, plot, customer_name, full_name, deceased_name, status FROM tbl_bookings WHERE id = ? LIMIT 1");
                if ($bStmt) {
                    $bStmt->bind_param('i', $bId);
                    $bStmt->execute();
                    $bRow = $safeFetchOne($bStmt);
                    if ($bRow) {
                        $bSec = strtoupper(trim($bRow['section'] ?? ''));
                        $rawPlot = trim($bRow['plot'] ?? '');
                        $px = 0; $py = 0;
                        if (preg_match('/(\d+)[-_\s,]+(\d+)/', $rawPlot, $pm)) {
                            $px = (int)$pm[1];
                            $py = (int)$pm[2];
                        }
                        if ($bSec && $px > 0 && $py > 0) {
                            $cName = trim(($bRow['customer_name'] ?? '') ?: ($bRow['full_name'] ?? ''));
                            $dName = trim($bRow['deceased_name'] ?? '');
                            $addResult($bSec, $px, $py, $dName, $cName, $bId, $bRow['status'], null, null);
                        }
                    }
                    $bStmt->close();
                }
            } catch (Throwable $e) {}
        }
    }

    if (!empty($queryTrimmed)) {

        if ($hasPoTable) {
            try {
                $ownerStmt = @$conn->prepare("SELECT section, plot_x, plot_y, owner_name, first_name, last_name FROM tbl_plot_owners WHERE LOWER(owner_name) LIKE ? OR LOWER(first_name) LIKE ? OR LOWER(last_name) LIKE ? OR LOWER(CONCAT_WS(' ', first_name, last_name)) LIKE ? LIMIT 10");
                if ($ownerStmt) {
                    $ownerStmt->bind_param('ssss', $likeWhole, $likeWhole, $likeWhole, $likeWhole);
                    $ownerStmt->execute();
                    $oRows = $safeFetchAll($ownerStmt);
                    foreach ($oRows as $oRow) {
                        if (!empty($filterSection) && strtoupper($oRow['section']) !== $filterSection) continue;
                        $oName = trim($oRow['owner_name'] ?? '') ?: trim(($oRow['first_name'] ?? '') . ' ' . ($oRow['last_name'] ?? ''));
                        $addResult($oRow['section'], $oRow['plot_x'], $oRow['plot_y'], '', $oName, null, 'purchased');
                    }
                    $ownerStmt->close();
                }
            } catch (Throwable $e) {}
        }

        if ($hasUpoTable) {
            try {
                $upoStmt = @$conn->prepare("SELECT upo.section, upo.plot_x, upo.plot_y, upo.ownership_status, upo.deceased_reference, upo.plot_id,
                                                  CONCAT_WS(' ', u.first_name, u.last_name) AS user_name
                                           FROM user_plot_ownership upo
                                           LEFT JOIN tbl_user u ON u.user_id = upo.user_id
                                           WHERE LOWER(CONCAT_WS(' ', u.first_name, u.last_name)) LIKE ?
                                              OR LOWER(COALESCE(upo.deceased_reference, '')) LIKE ?
                                           LIMIT 10");
                if ($upoStmt) {
                    $upoStmt->bind_param('ss', $likeWhole, $likeWhole);
                    $upoStmt->execute();
                    $uRows = $safeFetchAll($upoStmt);
                    foreach ($uRows as $uRow) {
                        if (!empty($filterSection) && strtoupper($uRow['section']) !== $filterSection) continue;
                        $addResult($uRow['section'], $uRow['plot_x'], $uRow['plot_y'], $uRow['deceased_reference'], $uRow['user_name'], null, $uRow['ownership_status'], null, $uRow['plot_id']);
                    }
                    $upoStmt->close();
                }
            } catch (Throwable $e) {}
        }

        if ($hasBookingsTable) {
            try {
                $bkStmt = @$conn->prepare("SELECT id, section, plot, customer_name, full_name, deceased_name, status
                                          FROM tbl_bookings
                                          WHERE LOWER(customer_name) LIKE ?
                                             OR LOWER(full_name) LIKE ?
                                             OR LOWER(deceased_name) LIKE ?
                                          LIMIT 15");
                if ($bkStmt) {
                    $bkStmt->bind_param('sss', $likeWhole, $likeWhole, $likeWhole);
                    $bkStmt->execute();
                    $bRows = $safeFetchAll($bkStmt);
                    foreach ($bRows as $bRow) {
                        $bSec = strtoupper(trim($bRow['section'] ?? ''));
                        if (!empty($filterSection) && $bSec !== $filterSection) continue;
                        $rawPlot = trim($bRow['plot'] ?? '');
                        $px = 0; $py = 0;
                        if (preg_match('/(\d+)[-_\s,]+(\d+)/', $rawPlot, $pm)) {
                            $px = (int)$pm[1];
                            $py = (int)$pm[2];
                        }
                        if ($bSec && $px > 0 && $py > 0) {
                            $cName = trim(($bRow['customer_name'] ?? '') ?: ($bRow['full_name'] ?? ''));
                            $dName = trim($bRow['deceased_name'] ?? '');
                            $addResult($bSec, $px, $py, $dName, $cName, (int)$bRow['id'], $bRow['status'], null, null);
                        }
                    }
                    $bkStmt->close();
                }
            } catch (Throwable $e) {}
        }
    }

    if ($hasPlotsTable && preg_match('/^(?:SECTION|SEC)?\s*([A-Ja-j])$/i', $queryTrimmed, $sm)) {
        try {
            $targetSec = strtoupper($sm[1]);
            $secPlots = @$conn->prepare("SELECT section, plot_x, plot_y, plot_id, status FROM tbl_plots WHERE UPPER(TRIM(section)) = ? ORDER BY (CASE WHEN LOWER(status) = 'occupied' THEN 0 WHEN LOWER(status) = 'reserved' THEN 1 ELSE 2 END), plot_x ASC, plot_y ASC LIMIT 12");
            if ($secPlots) {
                $secPlots->bind_param('s', $targetSec);
                $secPlots->execute();
                $spRows = $safeFetchAll($secPlots);
                foreach ($spRows as $sp) {
                    $addResult($sp['section'], $sp['plot_x'], $sp['plot_y'], '', '', null, $sp['status'], null, $sp['plot_id']);
                }
                $secPlots->close();
            }
        } catch (Throwable $e) {}
    }

    if (!empty($results) && count($results) > 1) {
        usort($results, function($a, $b) {
            $aHasDec = !empty($a['deceased_name']) ? 1 : 0;
            $bHasDec = !empty($b['deceased_name']) ? 1 : 0;
            if ($aHasDec !== $bHasDec) {
                return $bHasDec - $aHasDec; 
            }

            $prio = ['occupied' => 0, 'reserved' => 1, 'purchased' => 2, 'available' => 3];
            $aP = $prio[$a['status']] ?? 4;
            $bP = $prio[$b['status']] ?? 4;
            if ($aP !== $bP) {
                return $aP - $bP;
            }

            $secCmp = strcmp($a['section'], $b['section']);
            if ($secCmp !== 0) return $secCmp;

            if ($a['plot_x'] !== $b['plot_x']) return $a['plot_x'] - $b['plot_x'];
            return $a['plot_y'] - $b['plot_y'];
        });
    }

    if (!empty($results) && class_exists('CemeteryGPSCalculator')) {
        foreach ($results as &$r) {
            if ($r['latitude'] === null || $r['longitude'] === null || $r['latitude'] == 0.0) {
                $c = CemeteryGPSCalculator::calculatePlotGPS($conn, (int)($r['plot_id'] ?? 0), $r['section'], (int)$r['plot_x'], (int)$r['plot_y'], true);
                if (!empty($c['success']) && !empty($c['has_coordinates'])) {
                    $r['latitude'] = (float)$c['latitude'];
                    $r['longitude'] = (float)$c['longitude'];
                }
            }
        }
        unset($r);
    }

    $count = count($results);
    if ($count === 0) {
        echo json_encode([
            'success' => true,
            'count'   => 0,
            'results' => [],
            'message' => 'No deceased record or grave plot found for your search.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'count'   => $count,
        'results' => $results,
        'message' => "Found $count matching grave / plot result(s)."
    ]);
    exit;

} catch (Throwable $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => true,
        'count'   => 0,
        'results' => [],
        'message' => 'No deceased record or grave plot found for your search.'
    ]);
    exit;
}

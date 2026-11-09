<?php 

if (session_status() === PHP_SESSION_NONE) { @session_start(); }

if (function_exists('ob_start')) { @ob_start(); }

if (function_exists('date_default_timezone_set')) { @date_default_timezone_set('Asia/Manila'); }

$isSubdir = (basename(__DIR__) === 'sections');
$baseWebPath = $isSubdir ? '../' : '';
$connPath = file_exists(__DIR__ . '/connection.php') ? (__DIR__ . '/connection.php') : (__DIR__ . '/../connection.php');
$pricingPath = file_exists(__DIR__ . '/config/pricing_policy.php') ? (__DIR__ . '/config/pricing_policy.php') : (__DIR__ . '/../config/pricing_policy.php');
$spatialLookupPath = file_exists(__DIR__ . '/../includes/PlotSpatialLookup.php') ? (__DIR__ . '/../includes/PlotSpatialLookup.php') : (__DIR__ . '/includes/PlotSpatialLookup.php');
if (file_exists($spatialLookupPath)) {
    require_once $spatialLookupPath;
}
global $conn;
if (!isset($conn) || !($conn instanceof mysqli)) {
    @include_once $connPath;
    if (!isset($conn) && isset($GLOBALS['conn']) && ($GLOBALS['conn'] instanceof mysqli)) {
        $conn = $GLOBALS['conn'];
    }
}

$isAjaxRequest = isset($_REQUEST['ajax_request']) || isset($_REQUEST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$userRole = strtoupper(trim((string)($_SESSION['role'] ?? 'GUEST')));
$isAdmin = in_array($userRole, ['ADMIN', 'ADMINISTRATOR'], true);
$isGuest = !$isLoggedIn && !$isAdmin;

$isTransferMode = (isset($_REQUEST['selection_mode']) && strtolower(trim((string)$_REQUEST['selection_mode'])) === 'transfer')
    || (isset($_REQUEST['transfer_mode']) && (string)$_REQUEST['transfer_mode'] === '1');
$transferDeceasedId = intval($_REQUEST['deceased_id'] ?? 0);
$transferTargetUserId = 0;
if ($transferDeceasedId > 0 && isset($conn) && ($conn instanceof mysqli)) {
    $tDecQ = $conn->query("SELECT user_id FROM tbl_deceased WHERE deceased_id = {$transferDeceasedId} LIMIT 1");
    if ($tDecQ && $tDecRow = $tDecQ->fetch_assoc()) {
        $transferTargetUserId = (int)($tDecRow['user_id'] ?? 0);
    }
}
if ($transferTargetUserId <= 0 && !$isAdmin && $isLoggedIn) {
    $transferTargetUserId = (int)($_SESSION['user_id'] ?? 0);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$publicAllowedAjax = ['get_reserved_plots', 'get_plot_booking_status', 'get_deceased_info', 'submit_guest_reservation', 'check_guest_email', 'get_plot_navigation', 'guest_booking_status', 'get_plot_directions'];
$reqAjax = $_REQUEST['ajax_request'] ?? $_REQUEST['ajax'] ?? '';
$reqAction = $_POST['action'] ?? '';
$dbAvailable = class_exists('mysqli') && ($conn instanceof mysqli) && !$conn->connect_errno;

if (!function_exists('get_section_deceased_map')) {
    function get_section_deceased_map($conn, $secName) {
        $sec = strtoupper(trim((string)$secName));
        $deceased = [];
        if (!($conn instanceof mysqli) || $conn->connect_errno || $sec === '') {
            return $deceased;
        }
        $cleanSec = strtoupper(trim(preg_replace('/^SECTION\s*/i', '', $sec)));
        $escSec = $conn->real_escape_string($cleanSec);
        $secWhere = "(REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}')";

        $sql = "SELECT * FROM tbl_deceased WHERE {$secWhere}";
        $result = @$conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $py = (int)($row['plot_y'] ?? 0);
                $px = (int)($row['plot_x'] ?? 0);
                if ($px > 0 && $py > 0) {
                    $deceased[$px][$py] = $row;
                    $deceased[$px . '-' . $py] = $row;
                }
            }
        } else {
            $chkD = @$conn->query("SHOW TABLES LIKE 'deceased'");
            if ($chkD && $chkD->num_rows > 0) {
                $sql2 = "SELECT * FROM deceased WHERE {$secWhere}";
                $res2 = @$conn->query($sql2);
                if ($res2) {
                    while ($row = $res2->fetch_assoc()) {
                        $py = (int)($row['plot_y'] ?? 0);
                        $px = (int)($row['plot_x'] ?? 0);
                        if ($px > 0 && $py > 0) {
                            $deceased[$px][$py] = $row;
                            $deceased[$px . '-' . $py] = $row;
                        }
                    }
                }
            }
        }
        return $deceased;
    }
}

if (!function_exists('get_section_reserved_and_purchased_maps')) {
    function get_section_reserved_and_purchased_maps($conn, $secName) {
        $sec = strtoupper(trim((string)$secName));
        $reserved = [];
        $purchased = [];
        if (!($conn instanceof mysqli) || $conn->connect_errno || $sec === '') {
            return [$reserved, $purchased];
        }

        $cleanSec = strtoupper(trim(preg_replace('/^SECTION\s*/i', '', $sec)));
        $escSec = $conn->real_escape_string($cleanSec);
        $secWhere = "(REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}')";

        // 1. tbl_plot_owners (active only)
        $chkPo = @$conn->query("SHOW TABLES LIKE 'tbl_plot_owners'");
        if ($chkPo && $chkPo->num_rows > 0) {
            $rQ1 = $conn->query("SELECT plot_x, plot_y FROM tbl_plot_owners WHERE {$secWhere} AND active = 1");
            if ($rQ1) {
                while ($r = $rQ1->fetch_assoc()) {
                    $rx = (int)$r['plot_x']; $ry = (int)$r['plot_y'];
                    if ($rx > 0 && $ry > 0) {
                        $reserved[$ry][$rx] = true;
                        $reserved[$rx][$ry] = true;
                    }
                }
            }
        }

        $schemaLock = file_exists(__DIR__ . '/../config/.schema_migrated.lock') || file_exists(__DIR__ . '/config/.schema_migrated.lock');
        $forceSync = !empty($_GET['force_schema_sync']);

        // 2. tbl_plots
        $chkTp = @$conn->query("SHOW TABLES LIKE 'tbl_plots'");
        if ($chkTp && $chkTp->num_rows > 0) {
            if (!$schemaLock || $forceSync) {
                // Auto-heal any stale reserved plot whose booking is cancelled/rejected
                @$conn->query("UPDATE tbl_plots tp 
                    JOIN tbl_bookings b ON (
                        (REPLACE(UPPER(TRIM(b.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') OR UPPER(TRIM(b.section)) = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', ''))
                        AND (b.plot = CONCAT(tp.plot_x, '-', tp.plot_y) OR b.plot = CONCAT(tp.plot_x, ',', tp.plot_y) OR b.plot = CONCAT(tp.plot_x, ', ', tp.plot_y) OR b.plot_id = tp.plot_id)
                    )
                    SET tp.status = 'available'
                    WHERE LOWER(tp.status) = 'reserved' 
                      AND LOWER(TRIM(b.status)) IN ('cancelled', 'rejected', 'declined')
                      AND NOT EXISTS (
                          SELECT 1 FROM tbl_bookings b2 
                          WHERE (REPLACE(UPPER(TRIM(b2.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') OR UPPER(TRIM(b2.section)) = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', ''))
                            AND (b2.plot = CONCAT(tp.plot_x, '-', tp.plot_y) OR b2.plot = CONCAT(tp.plot_x, ',', tp.plot_y) OR b2.plot = CONCAT(tp.plot_x, ', ', tp.plot_y) OR b2.plot_id = tp.plot_id)
                            AND LOWER(TRIM(b2.status)) IN ('approved', 'confirmed', 'reserved', 'completed')
                      )");

                // Auto-heal user_plot_ownership: delete cancelled/rejected and stale reservations
                @$conn->query("DELETE FROM user_plot_ownership WHERE ownership_status = 'CANCELLED' OR verification_status = 'REJECTED'");
                @$conn->query("DELETE upo FROM user_plot_ownership upo
                    JOIN tbl_plots tp ON (
                        (REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', '') OR UPPER(TRIM(tp.section)) = REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', ''))
                        AND tp.plot_x = upo.plot_x AND tp.plot_y = upo.plot_y
                    )
                    WHERE upo.ownership_status = 'RESERVED'
                      AND LOWER(tp.status) = 'available'
                      AND NOT EXISTS (
                          SELECT 1 FROM tbl_deceased d 
                          WHERE (REPLACE(UPPER(TRIM(d.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', '') OR UPPER(TRIM(d.section)) = REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', ''))
                            AND d.plot_x = upo.plot_x AND d.plot_y = upo.plot_y
                      )");

                // Auto-heal from booking_plots
                @$conn->query("UPDATE tbl_plots tp 
                    JOIN booking_plots bp ON (
                        (REPLACE(UPPER(TRIM(bp.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') OR UPPER(TRIM(bp.section)) = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', ''))
                        AND ((bp.plot_x = tp.plot_x AND bp.plot_y = tp.plot_y) OR (bp.plot_x = tp.plot_y AND bp.plot_y = tp.plot_x) OR (bp.plot_id > 0 AND bp.plot_id = tp.plot_id))
                    )
                    JOIN tbl_bookings b ON b.id = bp.booking_id
                    SET tp.status = 'available'
                    WHERE LOWER(tp.status) = 'reserved' 
                      AND LOWER(TRIM(b.status)) IN ('cancelled', 'rejected')
                      AND NOT EXISTS (
                          SELECT 1 FROM booking_plots bp2 
                          JOIN tbl_bookings b2 ON b2.id = bp2.booking_id
                          WHERE (REPLACE(UPPER(TRIM(bp2.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') OR UPPER(TRIM(bp2.section)) = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', ''))
                            AND ((bp2.plot_x = tp.plot_x AND bp2.plot_y = tp.plot_y) OR (bp2.plot_x = tp.plot_y AND bp2.plot_y = tp.plot_x))
                            AND LOWER(TRIM(b2.status)) IN ('approved', 'confirmed', 'reserved', 'completed')
                      )");

                // Auto-heal from guest_reservation_requests
                @$conn->query("UPDATE tbl_plots tp 
                    JOIN guest_reservation_requests gr ON (
                        (REPLACE(UPPER(TRIM(gr.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') OR UPPER(TRIM(gr.section)) = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', ''))
                        AND ((gr.plot_x = tp.plot_x AND gr.plot_y = tp.plot_y) OR (gr.plot_x = tp.plot_y AND gr.plot_y = tp.plot_x))
                    )
                    SET tp.status = 'available'
                    WHERE LOWER(tp.status) = 'reserved' 
                      AND LOWER(TRIM(gr.status)) IN ('cancelled', 'rejected')
                      AND NOT EXISTS (
                          SELECT 1 FROM tbl_bookings b2 
                          WHERE (REPLACE(UPPER(TRIM(b2.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') OR UPPER(TRIM(b2.section)) = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', ''))
                            AND (b2.plot = CONCAT(tp.plot_x, '-', tp.plot_y) OR b2.plot = CONCAT(tp.plot_x, ', ', tp.plot_y) OR b2.plot_id = tp.plot_id)
                            AND LOWER(TRIM(b2.status)) IN ('approved', 'confirmed', 'reserved', 'completed')
                      )");

                // Auto-heal orphaned reserved/purchased/occupied plots that have no real booking, no real owner, and no deceased occupant
                @$conn->query("UPDATE tbl_plots tp 
                    SET tp.status = 'available'
                    WHERE LOWER(tp.status) IN ('reserved', 'purchased', 'occupied', 'taken', 'unavailable')
                      AND {$secWhere}
                      AND NOT EXISTS (
                          SELECT 1 FROM tbl_deceased d 
                          WHERE (REPLACE(UPPER(TRIM(d.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') OR UPPER(TRIM(d.section)) = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', ''))
                            AND ((d.plot_x = tp.plot_x AND d.plot_y = tp.plot_y) OR (d.plot_x = tp.plot_y AND d.plot_y = tp.plot_x))
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM tbl_plot_owners po 
                          WHERE (REPLACE(UPPER(TRIM(po.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') OR UPPER(TRIM(po.section)) = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', ''))
                            AND ((po.plot_x = tp.plot_x AND po.plot_y = tp.plot_y) OR (po.plot_x = tp.plot_y AND po.plot_y = tp.plot_x))
                            AND (po.active = 1 OR po.active IS NULL)
                            AND TRIM(COALESCE(po.owner_name, '')) NOT IN ('', 'Reserved', 'Unknown', 'N/A')
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM tbl_bookings b 
                          WHERE (REPLACE(UPPER(TRIM(b.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') OR UPPER(TRIM(b.section)) = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', ''))
                            AND (b.plot = CONCAT(tp.plot_x, '-', tp.plot_y) OR b.plot = CONCAT(tp.plot_y, '-', tp.plot_x) OR b.plot = CONCAT(tp.plot_x, ',', tp.plot_y) OR b.plot = CONCAT(tp.plot_y, ',', tp.plot_x) OR b.plot = CONCAT(tp.plot_x, ', ', tp.plot_y) OR b.plot = CONCAT(tp.plot_y, ', ', tp.plot_x) OR (tp.plot_id > 0 AND b.plot_id = tp.plot_id))
                            AND LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved', 'completed')
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM booking_plots bp 
                          JOIN tbl_bookings b2 ON b2.id = bp.booking_id
                          WHERE (REPLACE(UPPER(TRIM(bp.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') OR UPPER(TRIM(bp.section)) = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', ''))
                            AND ((bp.plot_x = tp.plot_x AND bp.plot_y = tp.plot_y) OR (bp.plot_x = tp.plot_y AND bp.plot_y = tp.plot_x) OR (tp.plot_id > 0 AND bp.plot_id = tp.plot_id))
                            AND LOWER(TRIM(b2.status)) IN ('approved', 'confirmed', 'reserved', 'completed')
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM user_plot_ownership upo 
                          WHERE (REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') OR UPPER(TRIM(upo.section)) = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', ''))
                            AND ((upo.plot_x = tp.plot_x AND upo.plot_y = tp.plot_y) OR (upo.plot_x = tp.plot_y AND upo.plot_y = tp.plot_x))
                            AND upo.verification_status = 'VERIFIED'
                            AND LOWER(TRIM(COALESCE(upo.ownership_status, ''))) NOT IN ('cancelled', 'rejected')
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM guest_reservation_requests gr 
                          WHERE (REPLACE(UPPER(TRIM(gr.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') OR UPPER(TRIM(gr.section)) = REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', ''))
                            AND ((gr.plot_x = tp.plot_x AND gr.plot_y = tp.plot_y) OR (gr.plot_x = tp.plot_y AND gr.plot_y = tp.plot_x))
                            AND LOWER(TRIM(gr.status)) IN ('approved', 'confirmed', 'account created')
                      )");
            }

            $rQ2 = $conn->query("SELECT plot_x, plot_y, LOWER(TRIM(status)) AS st FROM tbl_plots WHERE {$secWhere}");
            if ($rQ2) {
                while ($r = $rQ2->fetch_assoc()) {
                    $rx = (int)$r['plot_x']; $ry = (int)$r['plot_y'];
                    if ($rx <= 0 || $ry <= 0) continue;
                    $st = $r['st'];
                    if ($st === 'reserved') {
                        $reserved[$ry][$rx] = true;
                        $reserved[$rx][$ry] = true;
                    } elseif (in_array($st, ['purchased', 'owned'], true)) {
                        $purchased[$ry][$rx] = true;
                        $purchased[$rx][$ry] = true;
                    }
                }
            }
        }

        // 3. plots table if exists
        $chkPlots = @$conn->query("SHOW TABLES LIKE 'plots'");
        if ($chkPlots && $chkPlots->num_rows > 0) {
            if (!$schemaLock || $forceSync) {
                @$conn->query("UPDATE plots p 
                    SET p.status = 'Available'
                    WHERE LOWER(p.status) IN ('reserved', 'purchased', 'occupied', 'taken', 'unavailable')
                      AND {$secWhere}
                      AND NOT EXISTS (
                          SELECT 1 FROM tbl_deceased d 
                          WHERE (REPLACE(UPPER(TRIM(d.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(p.section)), 'SECTION ', '') OR UPPER(TRIM(d.section)) = REPLACE(UPPER(TRIM(p.section)), 'SECTION ', ''))
                            AND ((d.plot_x = p.plot_x AND d.plot_y = p.plot_y) OR (d.plot_x = p.plot_y AND d.plot_y = p.plot_x))
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM tbl_bookings b 
                          WHERE (REPLACE(UPPER(TRIM(b.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(p.section)), 'SECTION ', '') OR UPPER(TRIM(b.section)) = REPLACE(UPPER(TRIM(p.section)), 'SECTION ', ''))
                            AND (b.plot = CONCAT(p.plot_x, '-', p.plot_y) OR b.plot = CONCAT(p.plot_y, '-', p.plot_x) OR b.plot = CONCAT(p.plot_x, ',', p.plot_y) OR b.plot = CONCAT(p.plot_y, ',', p.plot_x) OR b.plot = CONCAT(p.plot_x, ', ', p.plot_y) OR b.plot = CONCAT(p.plot_y, ', ', p.plot_x))
                            AND LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved', 'completed')
                      )");
            }
            $rQ3 = $conn->query("SELECT plot_x, plot_y, LOWER(TRIM(status)) AS st FROM plots WHERE {$secWhere}");
            if ($rQ3) {
                while ($r = $rQ3->fetch_assoc()) {
                    $rx = (int)$r['plot_x']; $ry = (int)$r['plot_y'];
                    if ($rx <= 0 || $ry <= 0) continue;
                    $st = $r['st'];
                    if ($st === 'reserved') {
                        $reserved[$ry][$rx] = true;
                        $reserved[$rx][$ry] = true;
                    } elseif (in_array($st, ['purchased', 'owned'], true)) {
                        $purchased[$ry][$rx] = true;
                        $purchased[$rx][$ry] = true;
                    }
                }
            }
        }

        // 4. user_plot_ownership (verified only)
        $chkUpo = @$conn->query("SHOW TABLES LIKE 'user_plot_ownership'");
        if ($chkUpo && $chkUpo->num_rows > 0) {
            $rQ4 = $conn->query("SELECT COALESCE(NULLIF(upo.plot_x,0), tp.plot_x, 0) AS plot_x, COALESCE(NULLIF(upo.plot_y,0), tp.plot_y, 0) AS plot_y, UPPER(TRIM(COALESCE(upo.ownership_status, 'OWNED'))) AS ost, tp.status AS tp_status FROM user_plot_ownership upo LEFT JOIN tbl_plots tp ON (upo.plot_id = tp.plot_id AND upo.plot_id > 0) WHERE (REPLACE(UPPER(TRIM(COALESCE(upo.section,''))), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(COALESCE(upo.section,''))) = '{$escSec}' OR REPLACE(UPPER(TRIM(COALESCE(tp.section,''))), 'SECTION ', '') = '{$escSec}') AND upo.verification_status = 'VERIFIED' AND upo.ownership_status IN ('RESERVED', 'PURCHASED', 'OWNED') AND upo.ownership_status != 'CANCELLED'");
            if ($rQ4) {
                while ($r = $rQ4->fetch_assoc()) {
                    $rx = (int)$r['plot_x']; $ry = (int)$r['plot_y'];
                    if ($rx <= 0 || $ry <= 0) continue;
                    if ($r['ost'] === 'RESERVED') {
                        if (!empty($r['tp_status']) && strtolower(trim($r['tp_status'])) === 'available') {
                            continue;
                        }
                        $reserved[$ry][$rx] = true;
                        $reserved[$rx][$ry] = true;
                    } else {
                        $purchased[$ry][$rx] = true;
                        $purchased[$rx][$ry] = true;
                    }
                }
            }
        }

        // 5. booking_plots + tbl_bookings (confirmed/approved bookings only)
        $chkBp = @$conn->query("SHOW TABLES LIKE 'booking_plots'");
        if ($chkBp && $chkBp->num_rows > 0) {
            $rQ5 = $conn->query("SELECT bp.plot_x, bp.plot_y, LOWER(TRIM(COALESCE(b.status, 'pending'))) AS bst, LOWER(TRIM(COALESCE(b.service_type, ''))) AS stype, LOWER(TRIM(COALESCE(b.purpose, ''))) AS bpurp 
                                 FROM booking_plots bp 
                                 JOIN tbl_bookings b ON b.id = bp.booking_id 
                                 WHERE (REPLACE(UPPER(TRIM(bp.section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(bp.section)) = '{$escSec}') AND LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved', 'completed')");
            if ($rQ5) {
                while ($r = $rQ5->fetch_assoc()) {
                    $rx = (int)$r['plot_x']; $ry = (int)$r['plot_y'];
                    if ($rx <= 0 || $ry <= 0) continue;
                    $bpurp = $r['bpurp']; $stype = $r['stype'];
                    $isCemeteryService = ($bpurp === 'cemetery_service' || $stype === 'cleaning' || $stype === 'landscaping' || $stype === 'maintenance' || $stype === 'decoration');
                    if ($isCemeteryService) { continue; }
                    $isPlotPur = ($stype === 'plot_purchase' || $bpurp === 'plot_purchase' || $bpurp === 'add_deceased' || $stype === 'burial' || $bpurp === 'burial' || $stype === 'interment' || $bpurp === 'interment' || in_array($stype, ['purchase', 'purchased'], true) || in_array($bpurp, ['purchase', 'purchased'], true));
                    $isReserv = ($bpurp === 'reserve_grave' || $stype === 'reserve_grave' || in_array($stype, ['grave reservation', 'reservation', 'reserve', 'reserved', 'plot_reservation'], true) || in_array($bpurp, ['reservation', 'reserve', 'reserved', 'plot_reservation'], true));
                    if (!$isPlotPur && !$isReserv) {
                        $isReserv = true;
                    }
                    if ($isPlotPur) {
                        $purchased[$ry][$rx] = true;
                        $purchased[$rx][$ry] = true;
                    } elseif ($isReserv) {
                        $reserved[$ry][$rx] = true;
                        $reserved[$rx][$ry] = true;
                    }
                }
            }
        }

        // 6. guest_reservation_requests (approved/confirmed requests only)
        $chkGrr = @$conn->query("SHOW TABLES LIKE 'guest_reservation_requests'");
        if (!$chkGrr || $chkGrr->num_rows === 0) {
            $chkGrr = @$conn->query("SHOW TABLES LIKE 'tbl_guest_reservation_requests'");
            $grrTbl = 'tbl_guest_reservation_requests';
        } else {
            $grrTbl = 'guest_reservation_requests';
        }
        if ($chkGrr && $chkGrr->num_rows > 0) {
            $rQ6 = $conn->query("SELECT plot_x, plot_y FROM {$grrTbl} WHERE {$secWhere} AND LOWER(TRIM(status)) IN ('approved', 'confirmed', 'account created')");
            if ($rQ6) {
                while ($r = $rQ6->fetch_assoc()) {
                    $rx = (int)$r['plot_x']; $ry = (int)$r['plot_y'];
                    if ($rx > 0 && $ry > 0) {
                        $reserved[$ry][$rx] = true;
                        $reserved[$rx][$ry] = true;
                    }
                }
            }
        }

        // 7. tbl_bookings direct plot strings (approved/confirmed bookings only)
        $chkBkg = @$conn->query("SHOW TABLES LIKE 'tbl_bookings'");
        if ($chkBkg && $chkBkg->num_rows > 0) {
            $rQ7 = $conn->query("SELECT plot, section_plot, grave_location, LOWER(TRIM(COALESCE(service_type, ''))) AS stype, LOWER(TRIM(COALESCE(purpose, ''))) AS bpurp 
                                 FROM tbl_bookings 
                                 WHERE ({$secWhere} OR plot LIKE '%{$escSec}%' OR grave_location LIKE '%{$escSec}%') AND LOWER(TRIM(status)) IN ('approved', 'confirmed', 'reserved', 'completed')");
            if ($rQ7) {
                while ($bRow = $rQ7->fetch_assoc()) {
                    $bpurp7 = $bRow['bpurp']; $stype7 = $bRow['stype'];
                    $isCemSvc7 = ($bpurp7 === 'cemetery_service' || $stype7 === 'cleaning' || $stype7 === 'landscaping' || $stype7 === 'maintenance' || $stype7 === 'decoration');
                    if ($isCemSvc7) { continue; }
                    $isPlotPur = ($stype7 === 'plot_purchase' || $bpurp7 === 'plot_purchase' || $bpurp7 === 'add_deceased' || $stype7 === 'burial' || $bpurp7 === 'burial' || $stype7 === 'interment' || $bpurp7 === 'interment' || in_array($stype7, ['purchase', 'purchased'], true) || in_array($bpurp7, ['purchase', 'purchased'], true));
                    $isReserv = ($bpurp7 === 'reserve_grave' || $stype7 === 'reserve_grave' || in_array($stype7, ['grave reservation', 'reservation', 'reserve', 'reserved', 'plot_reservation'], true) || in_array($bpurp7, ['reservation', 'reserve', 'reserved', 'plot_reservation'], true));
                    if (!$isPlotPur && !$isReserv) {
                        $isReserv = true;
                    }
                    foreach ([$bRow['plot'] ?? '', $bRow['section_plot'] ?? '', $bRow['grave_location'] ?? ''] as $rawStr) {
                        if (empty($rawStr)) continue;
                        if (preg_match_all('/(\d{1,3})\s*[-,\s]\s*(\d{1,3})/', $rawStr, $mmAll, PREG_SET_ORDER)) {
                            foreach ($mmAll as $m) {
                                $ax = (int)$m[1]; $ay = (int)$m[2];
                                if ($ax > 0 && $ay > 0) {
                                    if ($isPlotPur) { 
                                        $purchased[$ay][$ax] = true; 
                                        $purchased[$ax][$ay] = true; 
                                    } elseif ($isReserv) { 
                                        $reserved[$ay][$ax] = true; 
                                        $reserved[$ax][$ay] = true; 
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return [$reserved, $purchased];
    }
}

if (!$dbAvailable) {
  if ($isAjaxRequest) {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'The cemetery database is temporarily unavailable. Please try again later.']);
    exit;
  }

  http_response_code(503);
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Temporarily unavailable</title></head><body><h1>Temporarily unavailable</h1><p>The cemetery database is unavailable. Please try again later.</p></body></html>';
  exit;
}

if (!$isLoggedIn) {
  if ($isAjaxRequest && !in_array($reqAjax, $publicAllowedAjax, true)) {
    @ob_clean();
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to access this feature.']);
    exit;
  }
  if (!empty($reqAction)) {
    header('Location: ' . $baseWebPath . 'login.php');
    exit;
  }
}
session_write_close();

if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'check_guest_email') {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    include_once $connPath;
    $emailCheck = strtolower(trim((string)($_POST['email'] ?? '')));
    if (empty($emailCheck) || !filter_var($emailCheck, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['registered' => false]);
        exit;
    }
    if (isset($conn) && ($conn instanceof mysqli)) {
        $uStmt = $conn->prepare("SELECT user_id FROM tbl_user WHERE LOWER(TRIM(email)) = ? LIMIT 1");
        if ($uStmt) {
            $uStmt->bind_param('s', $emailCheck);
            $uStmt->execute();
            $isReg = ($uStmt->get_result()->num_rows > 0);
            $uStmt->close();
            echo json_encode([
                'registered' => $isReg,
                'message' => $isReg ? 'This email is already registered. Please sign in to your existing account to continue.' : ''
            ]);
            exit;
        }
    }
    echo json_encode(['registered' => false]);
    exit;
}

// Guest Pay Now polling: returns payment state for a guest booking.
// Verified by single-booking pay token (no login required).
if (isset($_REQUEST['ajax_request']) && $_REQUEST['ajax_request'] === 'guest_booking_status') {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    include_once $connPath;
    $gBid = isset($_REQUEST['booking_id']) ? (int)$_REQUEST['booking_id'] : 0;
    $gTok = isset($_REQUEST['pay_token']) ? (string)$_REQUEST['pay_token'] : '';
    if ($gBid <= 0 || $gTok === '' || !hash_equals(hash_hmac('sha256', (string)$gBid, 'GOR_GUEST_PAY_2026_KEY'), $gTok)) {
        echo json_encode(['success' => false, 'message' => 'Invalid booking or token.']);
        exit;
    }
    $st = $conn->prepare("SELECT id, status, payment_status, total_amount, amount_paid, remaining_balance, gcash_reference_number, customer_name, full_name, customer_email, email, contact_number, booker_location, remarks, section, plot, plot_type, preferred_date FROM tbl_bookings WHERE id = ? LIMIT 1");
    if (!$st) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
        exit;
    }
    $st->bind_param('i', $gBid);
    $st->execute();
    $gb = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$gb) {
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit;
    }
    $rcptRef = '';
    $rcptCount = 0;
    $rcptDate = '';
    $rcptTxRef = '';
    $rcptProof = '';
    $rRes = $conn->query("SELECT gcash_reference_number, payment_date, transaction_reference, proof_filename FROM payment_receipts WHERE booking_id = $gBid ORDER BY id DESC LIMIT 1");
    if ($rRes && ($rRow = $rRes->fetch_assoc())) {
        $rcptRef = (string)($rRow['gcash_reference_number'] ?? '');
        $rcptDate = (string)($rRow['payment_date'] ?? '');
        $rcptTxRef = (string)($rRow['transaction_reference'] ?? '');
        $rcptProof = (string)($rRow['proof_filename'] ?? '');
        $rcptCount = 1;
    }
    $ref = !empty($gb['gcash_reference_number']) ? $gb['gcash_reference_number'] : $rcptRef;
    $paidAmt = (float)($gb['amount_paid'] ?? 0);
    $reqName = trim((string)(($gb['customer_name'] ?? '') !== '' ? $gb['customer_name'] : ($gb['full_name'] ?? '')));
    $reqEmail = trim((string)(($gb['customer_email'] ?? '') !== '' ? $gb['customer_email'] : ($gb['email'] ?? '')));

    $bSec = (string)($gb['section'] ?? '');
    $bPlot = (string)($gb['plot'] ?? '');
    $bPx = 0; $bPy = 0;
    if (preg_match('/(\d+)\s*[-,\s]\s*(\d+)/', $bPlot, $mP)) {
        $bPx = (int)$mP[1]; $bPy = (int)$mP[2];
    }
    $lawnType = (string)($gb['plot_type'] ?? '');
    $prefDate = (string)($gb['preferred_date'] ?? '');
    $grrQ = $conn->query("SELECT section, plot_x, plot_y, lawn_type, preferred_date FROM guest_reservation_requests WHERE booking_id = $gBid ORDER BY id DESC LIMIT 1");
    if ($grrQ && ($grrRow = $grrQ->fetch_assoc())) {
        if (empty($bSec)) $bSec = (string)($grrRow['section'] ?? '');
        if ($bPx <= 0) $bPx = (int)($grrRow['plot_x'] ?? 0);
        if ($bPy <= 0) $bPy = (int)($grrRow['plot_y'] ?? 0);
        if (empty($lawnType) && !empty($grrRow['lawn_type'])) $lawnType = (string)$grrRow['lawn_type'];
        if (empty($prefDate) && !empty($grrRow['preferred_date'])) $prefDate = (string)$grrRow['preferred_date'];
    }

    echo json_encode([
        'success' => true,
        'booking_id' => $gBid,
        'booking_status' => $gb['status'],
        'payment_status' => $gb['payment_status'],
        'total_amount' => (float)($gb['total_amount'] ?? 0),
        'amount_paid' => $paidAmt,
        'remaining_balance' => (float)($gb['remaining_balance'] ?? 0),
        'reference' => $ref,
        'transaction_reference' => $rcptTxRef,
        'payment_date' => $rcptDate,
        'proof_filename' => $rcptProof,
        'full_name' => $reqName,
        'email' => $reqEmail,
        'contact_number' => (string)($gb['contact_number'] ?? ''),
        'address' => (string)($gb['booker_location'] ?? ''),
        'remarks' => (string)($gb['remarks'] ?? ''),
        'section' => $bSec,
        'plot_x' => $bPx,
        'plot_y' => $bPy,
        'lawn_type' => $lawnType,
        'preferred_date' => $prefDate,
        'paid' => ($paidAmt > 0.009 || $rcptCount > 0)
    ]);
    exit;
}

if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'submit_guest_reservation') {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    include_once $connPath;
    try {
        if (!isset($conn) || !($conn instanceof mysqli)) {
            throw new Exception('Database service unavailable.');
        }
        if (function_exists('ensure_guest_reservation_columns')) {
            ensure_guest_reservation_columns($conn);
        }

        $requestType = trim((string)($_POST['request_type'] ?? 'guest'));
        if ($requestType !== 'deceased_verification') {
            $requestType = 'guest';
        }

        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $contact = trim((string)($_POST['contact_number'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        if ($requestType === 'deceased_verification' && empty($address)) {
            $address = 'Deceased Record Verification';
        }
        $section = strtoupper(trim((string)($_POST['section'] ?? '')));
        $plotX = intval($_POST['plot_x'] ?? 0);
        $plotY = intval($_POST['plot_y'] ?? 0);
        $purpose = trim((string)($_POST['purpose'] ?? ($requestType === 'deceased_verification' ? 'Add Additional Deceased' : 'Reserve New Grave')));
        if (empty($purpose)) { $purpose = ($requestType === 'deceased_verification' ? 'Add Additional Deceased' : 'Reserve New Grave'); }
        $remarks = trim((string)($_POST['remarks'] ?? ''));

        $decFirstName = strtoupper(trim((string)($_POST['deceased_first_name'] ?? '')));
        $decMiddleName = strtoupper(trim((string)($_POST['deceased_middle_name'] ?? '')));
        $decLastName = strtoupper(trim((string)($_POST['deceased_last_name'] ?? '')));
        $decSuffix = strtoupper(trim((string)($_POST['deceased_suffix'] ?? '')));
        $decBday = !empty($_POST['deceased_bday']) ? trim((string)$_POST['deceased_bday']) : null;
        $decDateDied = !empty($_POST['deceased_date_died']) ? trim((string)$_POST['deceased_date_died']) : null;
        $decRelationship = trim((string)($_POST['deceased_relationship'] ?? ''));
        $funeralHome = trim((string)($_POST['funeral_home'] ?? ''));
        $decFullName = trim(implode(' ', array_filter([$decFirstName, $decMiddleName, $decLastName, $decSuffix])));

        if (function_exists('date_default_timezone_set')) {
            @date_default_timezone_set('Asia/Manila');
        }
        $phDateTimeObj = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $phSubmissionTimestamp = $phDateTimeObj->format('Y-m-d H:i:s');
        $phSubmissionDate = $phDateTimeObj->format('Y-m-d');
        $rawPrefDate = trim((string)($_POST['preferred_date'] ?? ''));
        if (!empty($rawPrefDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawPrefDate)) {
            $preferredDate = $rawPrefDate;
        } else {
            $preferredDate = $phSubmissionDate;
        }

        if (empty($fullName)) { throw new Exception('Full Name is required.'); }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new Exception('A valid email address is required.'); }
        $contact = preg_replace('/[^0-9]/', '', (string)($_POST['contact_number'] ?? ''));
        if (!preg_match('/^09[0-9]{9}$/', $contact)) { throw new Exception('Contact number must be an 11-digit mobile number starting with 09.'); }
        if (empty($address)) { throw new Exception('Address is required.'); }
        if (empty($section) || $plotX <= 0 || $plotY <= 0) { throw new Exception('A valid plot must be selected.'); }

        $plotCode = $plotX . '-' . $plotY;
        $plotCodeAlt = $plotX . ', ' . $plotY;
        $graveLocation = 'Section ' . $section . ' Plot ' . $plotCode;

        // --- Nonce idempotency FIRST (before duplicate guards): a returning
        // Pay Now finalize carries the same nonce — resume it, never duplicate.
        $paymentNonce = trim((string)($_POST['payment_nonce'] ?? ''));
        if (!empty($paymentNonce)) {
            $nonceChk = $conn->prepare("SELECT grr.id, grr.booking_id, grr.section, grr.plot_code, grr.created_at, grr.preferred_date FROM guest_reservation_requests grr WHERE grr.payment_nonce = ? LIMIT 1");
            if ($nonceChk) {
                $nonceChk->bind_param('s', $paymentNonce);
                $nonceChk->execute();
                $existingReq = $nonceChk->get_result()->fetch_assoc();
                $nonceChk->close();
                if ($existingReq) {
                    // Return existing record — idempotent response (no duplicate booking/request).
                    $existBookingId = (int)($existingReq['booking_id'] ?? 0);
                    $guestPaySecret = 'GOR_GUEST_PAY_2026_KEY';
                    $guestPayToken = $existBookingId > 0 ? hash_hmac('sha256', (string)$existBookingId, $guestPaySecret) : '';
                    // Finalize: attach payment proof to the EXISTING booking if provided
                    // (e.g. guest paid in gateway, returned, then submitted proof).
                    $proofAttached = false;
                    if ($existBookingId > 0 && isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
                        $pfile = $_FILES['payment_proof'];
                        $pext = strtolower(pathinfo($pfile['name'], PATHINFO_EXTENSION));
                        $allowed_pext = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
                        $pmime = function_exists('mime_content_type') ? mime_content_type($pfile['tmp_name']) : '';
                        $allowed_pmime = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg', 'application/pdf'];
                        $is_valid_img = ($pext === 'pdf') || (!function_exists('exif_imagetype') || @exif_imagetype($pfile['tmp_name']) !== false);
                        if (in_array($pext, $allowed_pext) && (empty($pmime) || in_array($pmime, $allowed_pmime)) && $is_valid_img && $pfile['size'] <= 5 * 1024 * 1024) {
                            $u_dir = __DIR__ . '/../uploads/payment-proofs';
                            if (!is_dir($u_dir)) { @mkdir($u_dir, 0755, true); }
                            $finProofName = 'guest_res_' . $existBookingId . '_' . time() . '.' . $pext;
                            if (@move_uploaded_file($pfile['tmp_name'], $u_dir . '/' . $finProofName)) {
                                $finRef = trim((string)($_POST['payment_reference'] ?? ($_POST['gcash_reference'] ?? '')));
                                $escProof = $conn->real_escape_string($finProofName);
                                $escRef = $conn->real_escape_string($finRef);
                                $conn->query("UPDATE tbl_bookings SET payment_proof_filename = CASE WHEN payment_proof_filename IS NULL OR payment_proof_filename = '' THEN '$escProof' ELSE payment_proof_filename END, gcash_reference_number = CASE WHEN (gcash_reference_number IS NULL OR gcash_reference_number = '') AND '$escRef' <> '' THEN '$escRef' ELSE gcash_reference_number END, payment_proof_uploaded_at = COALESCE(payment_proof_uploaded_at, NOW()), reference_number = COALESCE(reference_number, '$escRef'), payment_status = 'FOR VERIFICATION' WHERE id = $existBookingId");
                                $conn->query("UPDATE guest_reservation_requests SET status = 'Pending', proof_filename = CASE WHEN proof_filename IS NULL OR proof_filename = '' THEN '$escProof' ELSE proof_filename END, payment_reference = CASE WHEN (payment_reference IS NULL OR payment_reference = '') AND '$escRef' <> '' THEN '$escRef' ELSE payment_reference END, payment_status = 'FOR VERIFICATION' WHERE booking_id = $existBookingId");
                                $conn->query("UPDATE payment_receipts SET proof_filename = CASE WHEN proof_filename IS NULL OR proof_filename = '' THEN '$escProof' ELSE proof_filename END WHERE booking_id = $existBookingId");
                                $proofAttached = true;
                                $logFin = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('PAYMENT_PROOF_UPLOADED', ?, NOW(), ?)");
                                if ($logFin) {
                                    $finUid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
                                    $finDet = "Payment proof attached to guest Booking #{$existBookingId} (finalize after gateway payment). Reference: {$finRef}";
                                    $logFin->bind_param('si', $finDet, $finUid);
                                    $logFin->execute();
                                    $logFin->close();
                                }
                            }
                        }
                    }
                    $existCreatedAt = $existingReq['created_at'] ?? $phSubmissionTimestamp;
                    $existPrefDate = !empty($existingReq['preferred_date']) ? $existingReq['preferred_date'] : $preferredDate;
                    echo json_encode([
                        'success' => true,
                        'request_id' => (int)$existingReq['id'],
                        'booking_id' => $existBookingId,
                        'pay_token' => $guestPayToken,
                        'section' => $existingReq['section'] ?? $section,
                        'plot' => $existingReq['plot_code'] ?? $plotCode,
                        'request_type' => $requestType,
                        'is_duplicate' => true,
                        'proof_attached' => $proofAttached,
                        'submitted_at' => $existCreatedAt,
                        'submission_date' => date('F j, Y', strtotime($existCreatedAt)),
                        'preferred_date' => $existPrefDate,
                        'preferred_date_display' => date('F j, Y', strtotime($existPrefDate)),
                        'message' => 'Reservation already exists.'
                    ]);
                    exit;
                }
            }
        }

        $userChk = $conn->prepare("SELECT user_id FROM tbl_user WHERE LOWER(TRIM(email)) = ? LIMIT 1");
        if ($userChk) {
            $userChk->bind_param('s', $email);
            $userChk->execute();
            $uRes = $userChk->get_result();
            if ($uRes && $uRes->num_rows > 0) {
                $userChk->close();
                echo json_encode([
                    'success' => false,
                    'is_registered' => true,
                    'message' => 'This email is already registered. Please sign in to your existing account to continue.'
                ]);
                exit;
            }
            $userChk->close();
        }

        $dupPlotChk = $conn->prepare("SELECT id FROM guest_reservation_requests WHERE LOWER(TRIM(email)) = ? AND UPPER(TRIM(section)) = ? AND (plot_code = ? OR (plot_x = ? AND plot_y = ?)) AND status IN ('Pending', 'Approved', 'Account Created') LIMIT 1");
        if ($dupPlotChk) {
            $dupPlotChk->bind_param('sssii', $email, $section, $plotCode, $plotX, $plotY);
            $dupPlotChk->execute();
            if ($dupPlotChk->get_result()->num_rows > 0) {
                $dupPlotChk->close();
                echo json_encode([
                    'success' => false,
                    'duplicate' => true,
                    'message' => "You have already submitted an active reservation request for Plot Section {$section} Plot {$plotCode}. Please await administrative review or check your email."
                ]);
                exit;
            }
            $dupPlotChk->close();
        }

        $coolChk = $conn->prepare("SELECT id FROM guest_reservation_requests WHERE LOWER(TRIM(email)) = ? AND created_at >= (NOW() - INTERVAL 30 SECOND) LIMIT 1");
        if ($coolChk) {
            $coolChk->bind_param('s', $email);
            $coolChk->execute();
            if ($coolChk->get_result()->num_rows > 0) {
                $coolChk->close();
                echo json_encode([
                    'success' => false,
                    'duplicate' => true,
                    'message' => 'A reservation request was recently submitted for this email. Please wait a moment before submitting another request.'
                ]);
                exit;
            }
            $coolChk->close();
        }

        $ownChk = $conn->prepare("SELECT id FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND active = 1 LIMIT 1");
        if ($ownChk) {
            $ownChk->bind_param('sii', $section, $plotX, $plotY);
            $ownChk->execute();
            if ($ownChk->get_result()->num_rows > 0) {
                $ownChk->close();
                throw new Exception("Plot Section {$section} Plot {$plotCode} is already reserved or owned. Please select another plot.");
            }
            $ownChk->close();
        }

        $decChk = $conn->prepare("SELECT deceased_id FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND ((plot_x = ? AND plot_y = ?) OR plot = ? OR plot = ?) LIMIT 1");
        if ($decChk) {
            $decChk->bind_param('siiss', $section, $plotX, $plotY, $plotCode, $plotCodeAlt);
            $decChk->execute();
            if ($decChk->get_result()->num_rows > 0) {
                $decChk->close();
                throw new Exception("Plot Section {$section} Plot {$plotCode} is occupied. Please select an available plot.");
            }
            $decChk->close();
        }

        $grrChk = $conn->prepare("SELECT id FROM guest_reservation_requests WHERE UPPER(TRIM(section)) = ? AND (plot_code = ? OR (plot_x = ? AND plot_y = ?)) AND status IN ('Approved', 'Account Created') LIMIT 1");
        if ($grrChk) {
            $grrChk->bind_param('ssii', $section, $plotCode, $plotX, $plotY);
            $grrChk->execute();
            if ($grrChk->get_result()->num_rows > 0) {
                $grrChk->close();
                throw new Exception("There is already a confirmed reservation for Plot Section {$section} Plot {$plotCode}. Please select another plot.");
            }
            $grrChk->close();
        }

        $bkChk = $conn->prepare("SELECT id FROM tbl_bookings WHERE UPPER(TRIM(section)) = ? AND (plot = ? OR plot = ? OR grave_location = ? OR grave_location = ? OR grave_location LIKE ?) AND LOWER(TRIM(status)) IN ('approved', 'confirmed', 'reserved', 'completed') LIMIT 1");
        if ($bkChk) {
            $glExact1 = "Section " . $section . " Plot " . $plotCode;
            $glExact2 = $section . " " . $plotCode;
            $glLike = "%Plot " . $plotCode . "%";
            $bkChk->bind_param('ssssss', $section, $plotCode, $plotCodeAlt, $glExact1, $glExact2, $glLike);
            $bkChk->execute();
            if ($bkChk->get_result()->num_rows > 0) {
                $bkChk->close();
                throw new Exception("There is already a confirmed reservation for Plot Section {$section} Plot {$plotCode}. Please select another plot.");
            }
            $bkChk->close();
        }

        $plotId = null;
        $pStmt = $conn->prepare("SELECT plot_id, status, lawn_type, price FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
        $dbLawnType = '';
        $dbPrice = 0.00;
        if ($pStmt) {
            $pStmt->bind_param('sii', $section, $plotX, $plotY);
            $pStmt->execute();
            $pRes = $pStmt->get_result();
            if ($pRow = $pRes->fetch_assoc()) {
                $plotId = (int)$pRow['plot_id'];
                $dbLawnType = trim((string)($pRow['lawn_type'] ?? ''));
                $dbPrice = floatval($pRow['price'] ?? 0);
                if (strtolower(trim((string)$pRow['status'])) !== 'available') {
                    // Check if plot truly has a deceased or active booking in reality
                    $realCheckStmt = $conn->prepare("SELECT 
                        (SELECT COUNT(*) FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?) as d_cnt,
                        (SELECT COUNT(*) FROM tbl_bookings WHERE UPPER(TRIM(section)) = ? AND (plot = ? OR plot = ? OR plot LIKE ?) AND LOWER(TRIM(status)) IN ('approved', 'confirmed', 'reserved', 'pending')) as b_cnt");
                    $hasRealObstacle = false;
                    if ($realCheckStmt) {
                        $pAlt = $plotX . ', ' . $plotY;
                        $pLike = '%' . $plotX . '-' . $plotY . '%';
                        $realCheckStmt->bind_param('siissss', $section, $plotX, $plotY, $section, $plotCode, $pAlt, $pLike);
                        $realCheckStmt->execute();
                        $rcRes = $realCheckStmt->get_result();
                        if ($rcRow = $rcRes->fetch_assoc()) {
                            $hasRealObstacle = ($rcRow['d_cnt'] > 0 || $rcRow['b_cnt'] > 0);
                        }
                        $realCheckStmt->close();
                    }
                    if ($hasRealObstacle) {
                        $pStmt->close();
                        throw new Exception("Plot Section {$section} Plot {$plotCode} is no longer available (Status: " . ucfirst($pRow['status']) . "). Please select another plot.");
                    } else {
                        // Stale status in tbl_plots - auto-heal to available
                        $conn->query("UPDATE tbl_plots SET status = 'available' WHERE plot_id = {$plotId}");
                    }
                }
            }
            $pStmt->close();
        }

        if (!empty($_POST['plot_id']) && $plotId !== null && (int)$_POST['plot_id'] !== $plotId) {
            throw new Exception("Invalid plot specification. Please select the plot directly from the cemetery map.");
        }

        $isCorner = false;
        if (function_exists('is_corner_premium_plot')) {
            $isCorner = is_corner_premium_plot($section, $plotX, $plotY);
        } elseif (function_exists('isCornerPlot')) {
            $isCorner = isCornerPlot($section, $plotX, $plotY);
        }
        $postedLawnType = trim((string)($_POST['lawn_type'] ?? ''));
        $isPremium = $isCorner || (stripos($dbLawnType, 'premium') !== false) || (stripos($postedLawnType, 'premium') !== false) || ($dbPrice >= 89000.00);
        $plotPrice = $isPremium ? 89000.00 : (($dbPrice > 0) ? $dbPrice : 85000.00);
        $lawnType = $isPremium ? 'Lawn (Premium)' : (!empty($dbLawnType) ? $dbLawnType : 'Lawn (Regular)');

        $matchedDeceasedId = null;
        if ($requestType === 'no_deceased') {
            $decFirstName = null;
            $decMiddleName = null;
            $decLastName = null;
            $decSuffix = null;
            $decBday = null;
            $decDateDied = null;
            $decRelationship = null;
            $funeralHome = null;
            $decFullName = null;
            $purpose = 'Reserve New Grave';
        } else if (!empty($decFirstName) || !empty($decLastName)) {
            if (function_exists('find_matching_cemetery_deceased')) {
                $matched = find_matching_cemetery_deceased($conn, $decFirstName, $decLastName, $decBday ?: '', $decDateDied ?: '');
                if ($matched && !empty($matched['deceased_id'])) {
                    $matchedDeceasedId = (int)$matched['deceased_id'];
                }
            }
        }

        $paymentPlan = strtolower(trim((string)($_POST['payment_plan'] ?? 'installment')));
        if (!in_array($paymentPlan, ['full', 'installment'], true)) {
            $paymentPlan = 'installment';
        }
        $paymentTerm = intval($_POST['payment_term'] ?? 36);
        if ($paymentPlan === 'full') {
            $paymentTerm = 0;
        } elseif (!in_array($paymentTerm, [12, 24, 36, 48, 60], true)) {
            $paymentTerm = 36;
        }

        $downpaymentAmt = round($plotPrice * 0.20, 2);
        if ($paymentPlan === 'full') {
            $initialDue = $plotPrice;
            $monthlyDue = 0.00;
            $firstDueDate = null;
            $totalAmountToRecord = $plotPrice;
        } else {
            $initialDue = $downpaymentAmt;
            if (file_exists(__DIR__ . '/../config/installment_helper.php')) {
                require_once __DIR__ . '/../config/installment_helper.php';
            } elseif (file_exists(__DIR__ . '/installment_helper.php')) {
                require_once __DIR__ . '/installment_helper.php';
            }
            if (function_exists('calculate_installment_plan')) {
                $pCalc = calculate_installment_plan($paymentTerm, 1, $plotPrice);
                $monthlyDue = floatval($pCalc['monthly_fee'] ?? 0);
                $totalAmountToRecord = floatval($pCalc['total_plan_cost'] ?? $plotPrice);
            } else {
                $remPrincipal = max(0.00, round($plotPrice - $downpaymentAmt, 2));
                $monthlyDue = round($remPrincipal / max(1, $paymentTerm), 2);
                $totalAmountToRecord = $plotPrice;
            }
            // Starting next month at exact same day of payment / booking
            $baseDate = !empty($preferredDate) ? $preferredDate : $phSubmissionDate;
            $bDt = new DateTime($baseDate);
            $bDay = (int)$bDt->format('j');
            $bDt->modify('+1 month');
            $maxDays = (int)$bDt->format('t');
            $bDt->setDate((int)$bDt->format('Y'), (int)$bDt->format('n'), min($bDay, $maxDays));
            $firstDueDate = $bDt->format('Y-m-d');
        }
        $guestDownpayment = $initialDue;

        $conn->begin_transaction();

        $newRequestId = 0;
        if (function_exists('ensure_guest_reservation_columns')) {
            ensure_guest_reservation_columns($conn);
        }

        $nonceVal = !empty($paymentNonce) ? $paymentNonce : null;

        // Inspect actual columns of guest_reservation_requests dynamically
        $grrCols = [];
        $chkGrrCols = @$conn->query("SHOW COLUMNS FROM `guest_reservation_requests`");
        if ($chkGrrCols) {
            while ($c = $chkGrrCols->fetch_assoc()) {
                $grrCols[strtolower($c['Field'])] = true;
            }
        }

        $hasProofUploaded = (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK);
        $initialRequestStatus = $hasProofUploaded ? 'Pending' : 'Awaiting Payment';

        // Complete specification of fields, types, and values
        $grrFieldMap = [
            'plot_id'               => ['i', $plotId],
            'full_name'             => ['s', $fullName],
            'email'                 => ['s', $email],
            'contact_number'        => ['s', $contact],
            'address'               => ['s', $address],
            'section'               => ['s', $section],
            'plot_x'                => ['i', $plotX],
            'plot_y'                => ['i', $plotY],
            'plot_code'             => ['s', $plotCode],
            'purpose'               => ['s', $purpose],
            'preferred_date'        => ['s', $preferredDate],
            'notes'                 => ['s', $remarks],
            'status'                => ['s', $initialRequestStatus],
            'request_type'          => ['s', $requestType],
            'deceased_first_name'   => ['s', $decFirstName],
            'deceased_middle_name'  => ['s', $decMiddleName],
            'deceased_last_name'    => ['s', $decLastName],
            'deceased_suffix'       => ['s', $decSuffix],
            'deceased_bday'         => ['s', $decBday],
            'deceased_date_died'    => ['s', $decDateDied],
            'deceased_relationship' => ['s', $decRelationship],
            'funeral_home'          => ['s', $funeralHome],
            'matched_deceased_id'   => ['i', $matchedDeceasedId],
            'deceased_full_name'    => ['s', $decFullName],
            'payment_nonce'         => ['s', $nonceVal],
            'payment_plan'          => ['s', $paymentPlan],
            'payment_term'          => ['i', $paymentTerm],
            'downpayment_amount'    => ['d', $initialDue],
            'downpayment'           => ['d', $initialDue],
            'monthly_due'           => ['d', $monthlyDue],
            'first_due_date'        => ['s', $firstDueDate],
            'created_at'            => ['s', $phSubmissionTimestamp],
            'updated_at'            => ['s', $phSubmissionTimestamp]
        ];

        $insCols = [];
        $insPhs = [];
        $insTypes = '';
        $insVals = [];

        foreach ($grrFieldMap as $colName => $spec) {
            if (empty($grrCols) || isset($grrCols[strtolower($colName)])) {
                $insCols[] = "`$colName`";
                $insPhs[] = "?";
                $insTypes .= $spec[0];
                $insVals[] = $spec[1];
            }
        }

        $grrSql = "INSERT INTO `guest_reservation_requests` (" . implode(', ', $insCols) . ") VALUES (" . implode(', ', $insPhs) . ")";
        $grrStmt = $conn->prepare($grrSql);
        if ($grrStmt) {
            $grrStmt->bind_param($insTypes, ...$insVals);
            if ($grrStmt->execute()) {
                $newRequestId = (int)$grrStmt->insert_id;
            }
            $grrStmt->close();
        } else {
            // Safe minimal fallback if prepare fails
            $fbSql = "INSERT INTO `guest_reservation_requests` (plot_id, full_name, email, contact_number, address, section, plot_x, plot_y, plot_code, purpose, preferred_date, notes, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $fbStmt = $conn->prepare($fbSql);
            if ($fbStmt) {
                $fbStmt->bind_param('isssssiisssssss', $plotId, $fullName, $email, $contact, $address, $section, $plotX, $plotY, $plotCode, $purpose, $preferredDate, $remarks, $initialRequestStatus, $phSubmissionTimestamp, $phSubmissionTimestamp);
                if ($fbStmt->execute()) {
                    $newRequestId = (int)$fbStmt->insert_id;
                }
                $fbStmt->close();
            }
        }

        $insStmt = $conn->prepare("INSERT INTO tbl_bookings 
            (customer_name, full_name, customer_email, email, contact_number, booker_location, section, plot, grave_location, plot_type, purpose, service_type, booking_type, source, status, is_guest_booking, user_id, preferred_date, remarks, special_requests, plot_price, total_amount, amount_paid, payment_type, deceased_name, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'guest_reservation', 'guest', 'pending', 1, NULL, ?, ?, ?, ?, ?, 0.00, 'Pending Approval', ?, ?)");

        if (!$insStmt) {
            if (function_exists('ensure_booking_table_integrity')) {
                ensure_booking_table_integrity($conn);
            }
            $insStmt = $conn->prepare("INSERT INTO tbl_bookings 
                (customer_name, full_name, customer_email, email, contact_number, booker_location, section, plot, grave_location, plot_type, purpose, service_type, booking_type, source, status, is_guest_booking, user_id, preferred_date, remarks, special_requests, plot_price, total_amount, amount_paid, payment_type, deceased_name, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'guest_reservation', 'guest', 'pending', 1, NULL, ?, ?, ?, ?, ?, 0.00, 'Pending Approval', ?, ?)");
        }
        if (!$insStmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }

        $insStmt->bind_param(
            'sssssssssssssssddss',
            $fullName, $fullName, $email, $email, $contact, $address,
            $section, $plotCode, $graveLocation, $lawnType,
            $purpose, $purpose,
            $preferredDate, $remarks, $remarks,
            $plotPrice, $plotPrice,
            $decFullName,
            $phSubmissionTimestamp
        );

        if (!$insStmt->execute()) {
            $err = $insStmt->error;
            $insStmt->close();
            throw new Exception("Failed to submit reservation: " . $err);
        }

        $newBookingId = (int)$insStmt->insert_id;
        $insStmt->close();

        // Update tbl_bookings with installment terms and correct initial balances
        if ($newBookingId > 0) {
            $remBalance = max(0.00, $totalAmountToRecord - $guestDownpayment);
            $conn->query("UPDATE tbl_bookings SET payment_plan = '{$conn->real_escape_string($paymentPlan)}', payment_term = {$paymentTerm}, total_amount = {$totalAmountToRecord}, downpayment = {$guestDownpayment}, remaining_balance = {$remBalance} WHERE id = {$newBookingId}");
            if (function_exists('record_booking_log')) {
                record_booking_log($conn, $newBookingId, $section, $contact, $address, $totalAmountToRecord);
            }
        }

        // Process GCash 20% Downpayment Proof Upload if provided
        $guestProofFilename = null;
        $guestPayRef = trim($_POST['payment_reference'] ?? '');

        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
            $pfile = $_FILES['payment_proof'];
            $pext = strtolower(pathinfo($pfile['name'], PATHINFO_EXTENSION));
            $allowed_pext = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
            $pmime = function_exists('mime_content_type') ? mime_content_type($pfile['tmp_name']) : '';
            $allowed_pmime = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg', 'application/pdf'];
            $is_valid_img = ($pext === 'pdf') || (!function_exists('exif_imagetype') || @exif_imagetype($pfile['tmp_name']) !== false);

            if (in_array($pext, $allowed_pext) && (empty($pmime) || in_array($pmime, $allowed_pmime)) && $is_valid_img && $pfile['size'] <= 5 * 1024 * 1024) {
                $u_dir = __DIR__ . '/../uploads/payment-proofs';
                if (!is_dir($u_dir)) {
                    @mkdir($u_dir, 0755, true);
                }
                $guestProofFilename = 'guest_res_' . ($newBookingId > 0 ? $newBookingId : time()) . '_' . time() . '.' . $pext;
                $dest_path = $u_dir . '/' . $guestProofFilename;
                if (@move_uploaded_file($pfile['tmp_name'], $dest_path)) {
                    $remBalance = max(0.00, $plotPrice - $guestDownpayment);
                    if ($newBookingId > 0) {
                        $upBk = $conn->prepare("UPDATE tbl_bookings SET payment_proof_filename = ?, gcash_reference_number = ?, payment_proof_uploaded_at = NOW(), reference_number = ?, payment_type = 'GCash', payment_status = 'FOR VERIFICATION', downpayment = ?, amount_paid = ?, remaining_balance = ? WHERE id = ?");
                        if ($upBk) {
                            $upBk->bind_param('sssdddi', $guestProofFilename, $guestPayRef, $guestPayRef, $guestDownpayment, $guestDownpayment, $remBalance, $newBookingId);
                            $upBk->execute();
                            $upBk->close();
                        }
                    }
                    if ($newRequestId > 0) {
                        $conn->query("UPDATE guest_reservation_requests SET status = 'Pending', proof_filename = '{$conn->real_escape_string($guestProofFilename)}', payment_reference = '{$conn->real_escape_string($guestPayRef)}', payment_status = 'FOR VERIFICATION' WHERE id = {$newRequestId}");
                    }
                }
            }
        }

        if ($newRequestId <= 0 && $newBookingId > 0) {
            $chkExist = @$conn->query("SELECT id FROM guest_reservation_requests WHERE booking_id = $newBookingId LIMIT 1");
            if ($chkExist && ($eRow = $chkExist->fetch_assoc())) {
                $newRequestId = (int)$eRow['id'];
            }
        }

        if ($newRequestId > 0 && $newBookingId > 0) {
            $upSql = "UPDATE guest_reservation_requests SET booking_id = $newBookingId";
            if ($guestProofFilename) {
                $escProof = $conn->real_escape_string($guestProofFilename);
                $escRef = $conn->real_escape_string($guestPayRef);
                $upSql .= ", proof_filename = '$escProof', payment_reference = '$escRef', downpayment = $guestDownpayment, downpayment_amount = $guestDownpayment";
            }
            $upSql .= " WHERE id = $newRequestId";
            @$conn->query($upSql);

            // Check if there was already a payment receipt for this booking (from GCash gateway)
            $chkRcpt = $conn->query("SELECT id, amount_paid, gcash_reference_number, payment_date, proof_filename FROM payment_receipts WHERE booking_id = $newBookingId ORDER BY id DESC LIMIT 1");
            if ($chkRcpt && ($rcptRow = $chkRcpt->fetch_assoc())) {
                $rcptId = (int)$rcptRow['id'];
                $rcptAmt = floatval($rcptRow['amount_paid']);
                $rcptRef = $conn->real_escape_string($rcptRow['gcash_reference_number'] ?: $guestPayRef);
                $proofToUse = $guestProofFilename ? $conn->real_escape_string($guestProofFilename) : ($rcptRow['proof_filename'] ? $conn->real_escape_string($rcptRow['proof_filename']) : '');
                
                $proofPayStatus = $guestProofFilename ? 'FOR VERIFICATION' : 'PENDING';
                $conn->query("UPDATE guest_reservation_requests SET 
                    payment_status = '{$proofPayStatus}',
                    payment_amount = $rcptAmt,
                    payment_date = '{$rcptRow['payment_date']}',
                    gcash_reference = '$rcptRef',
                    payment_receipt_id = $rcptId" .
                    ($proofToUse ? ", proof_filename = '$proofToUse'" : "") . "
                    WHERE id = $newRequestId");

                if ($guestProofFilename && empty($rcptRow['proof_filename'])) {
                    $conn->query("UPDATE payment_receipts SET proof_filename = '" . $conn->real_escape_string($guestProofFilename) . "' WHERE id = $rcptId");
                }
            } elseif ($guestProofFilename) {
                // Manual proof uploaded without prior gateway payment
                $escProof = $conn->real_escape_string($guestProofFilename);
                $escRef = $conn->real_escape_string($guestPayRef);
                $conn->query("UPDATE guest_reservation_requests SET 
                    payment_status = 'PENDING',
                    payment_amount = $guestDownpayment,
                    payment_date = NOW(),
                    gcash_reference = '$escRef',
                    proof_filename = '$escProof'
                    WHERE id = $newRequestId");
            }
        }

        $conn->commit();

        $logAction = ($requestType === 'deceased_verification') ? 'DECEASED_VERIFICATION_SUBMITTED' : (($requestType === 'no_deceased') ? 'GUEST_NO_DECEASED_RESERVATION_SUBMITTED' : 'GUEST_RESERVATION_SUBMITTED');
        $logDetails = ($requestType === 'no_deceased')
            ? "Guest submitted no-deceased reservation request #GR-{$newRequestId} (Booking #{$newBookingId}) for Section {$section} Plot {$plotCode}. Purpose: Reserve New Grave. Deceased: Not Assigned. Status: Pending Admin Review."
            : "Reservation request #{$newRequestId} [{$requestType}] (Booking #{$newBookingId}) submitted by {$fullName} ({$email}, {$contact}) for Section {$section} Plot {$plotCode}. Purpose: {$purpose}. Deceased: " . ($decFullName ?: 'N/A') . ($matchedDeceasedId ? " (Matched Deceased ID #{$matchedDeceasedId})" : "") . ". Status: Pending review.";
        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, 0, ?)");
        if ($logStmt) {
            $logStmt->bind_param('sss', $logAction, $logDetails, $phSubmissionTimestamp);
            $logStmt->execute();
            $logStmt->close();
        }

        $adminQ = $conn->query("SELECT user_id FROM tbl_user WHERE UPPER(TRIM(COALESCE(role, ''))) = 'ADMIN'");
        if ($adminQ && $adminQ->num_rows > 0) {
            $notifTitle = "New Guest Reservation Request";
            $notifMsg = "Guest {$fullName} submitted a reservation request for Section {$section} Plot {$plotCode} (Ref #{$newRequestId}). Pending review.";
            $notifLink = "guest_reservations.php?status=Pending&search=" . urlencode($fullName);
            $notifType = "guest_reservation";

            @$conn->query("CREATE TABLE IF NOT EXISTS `notifications` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `booking_id` int(11) DEFAULT NULL,
                `type` varchar(50) NOT NULL DEFAULT 'system',
                `title` varchar(255) NOT NULL,
                `message` text NOT NULL,
                `link` varchar(255) DEFAULT NULL,
                `is_read` tinyint(1) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `read_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_notifications_user_unread` (`user_id`,`is_read`),
                KEY `idx_notifications_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, booking_id, type, title, message, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            if ($notifStmt) {
                while ($adm = $adminQ->fetch_assoc()) {
                    $admId = (int)$adm['user_id'];
                    if ($admId > 0) {
                        $notifStmt->bind_param('iisssss', $admId, $newBookingId, $notifType, $notifTitle, $notifMsg, $notifLink, $phSubmissionTimestamp);
                        $notifStmt->execute();
                    }
                }
                $notifStmt->close();
            }
        }

        $mailerPath = dirname(__DIR__) . '/mailer.php';
        if (file_exists($mailerPath)) {
            require_once $mailerPath;
            if (function_exists('send_guest_reservation_received_email')) {
                try {
                    @send_guest_reservation_received_email($newRequestId, $conn);
                } catch (Throwable $mailEx) {
                    error_log("Failed to send guest reservation receipt: " . $mailEx->getMessage());
                }
            }
        }

        // Guest Pay Now token: lets this browser pay for its just-created booking
        // in the gateway without an account (session is write-closed above).
        $guestPaySecret = 'GOR_GUEST_PAY_2026_KEY';
        $guestPayToken = hash_hmac('sha256', (string)$newBookingId, $guestPaySecret);

        echo json_encode([
            'success' => true,
            'request_id' => $newRequestId,
            'booking_id' => $newBookingId,
            'pay_token' => $guestPayToken,
            'section' => $section,
            'plot' => $plotCode,
            'plot_price' => $plotPrice,
            'downpayment' => $guestDownpayment,
            'payment_plan' => $paymentPlan,
            'payment_term' => $paymentTerm,
            'monthly_due' => $monthlyDue,
            'first_due_date' => $firstDueDate,
            'first_due_date_display' => !empty($firstDueDate) ? date('F j, Y', strtotime($firstDueDate)) : '',
            'lawn_type' => $lawnType,
            'request_type' => $requestType,
            'is_match_found' => ($matchedDeceasedId !== null && $matchedDeceasedId > 0),
            'matched_deceased_id' => $matchedDeceasedId,
            'deceased_name' => $decFullName,
            'submitted_at' => $phSubmissionTimestamp,
            'submission_date' => date('F j, Y', strtotime($phSubmissionTimestamp)),
            'preferred_date' => $preferredDate,
            'preferred_date_display' => date('F j, Y', strtotime($preferredDate)),
            'message' => ($requestType === 'deceased_verification')
                ? "Your deceased verification & reservation request for Section {$section} Plot {$plotCode} has been submitted successfully!\n\nStatus: Pending Admin Review." . (($matchedDeceasedId > 0) ? "\n\n(A potential matching cemetery deceased record was found and attached for staff review.)" : "") . "\n\nOur administration will review the records and update you via {$email}."
                : "Your reservation request for Section {$section} Plot {$plotCode} has been submitted successfully!\n\nStatus: Pending Admin Review.\n\nOnce approved by our staff, you will receive an email at {$email} with a link to create your account and finalize your reservation."
        ]);
        exit;

    } catch (Throwable $e) {
        if (isset($conn) && ($conn instanceof mysqli)) {
            @$conn->rollback();
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'get_reserved_plots') {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    include_once $connPath;
    try {
        if (!isset($_POST['section'])) { throw new Exception('Missing field: section'); }
        $rawSection = trim($_POST['section']);
        $cleanSec = strtoupper(trim(preg_replace('/^SECTION\s*/i', '', $rawSection)));
        $section = $cleanSec;
        $escSec = $conn->real_escape_string($cleanSec);
        $list = [];
        $seen = [];

        $tbl = $conn->query("SHOW TABLES LIKE 'tbl_plot_owners'");
        if ($tbl && $tbl->num_rows > 0) {
            $stmt = $conn->prepare("SELECT plot_x, plot_y, owner_name, first_name, middle_name, last_name, suffix, phone_no FROM tbl_plot_owners WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}') AND active = 1");
            if ($stmt) {
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                  $oName = trim((string)($row['owner_name'] ?? ''));
                  if ($oName === '' || strcasecmp($oName, 'Reserved') === 0) {
                    $oName = trim(implode(' ', array_filter([
                      trim((string)($row['first_name'] ?? '')),
                      trim((string)($row['middle_name'] ?? '')),
                      trim((string)($row['last_name'] ?? '')),
                      trim((string)($row['suffix'] ?? ''))
                    ])));
                  }
                  if ($oName === '' || strcasecmp($oName, 'Unknown') === 0 || strcasecmp($oName, 'Reserved') === 0 || strcasecmp($oName, 'N/A') === 0) {
                    continue;
                  }
                  $px = (int)$row['plot_x'];
                  $py = (int)$row['plot_y'];
                  if ($px <= 0 || $py <= 0) continue;
                  $key = $px . '-' . $py;
                  $seen[$key] = true;
                  $list[] = [
                    'plot_x' => $px,
                    'plot_y' => $py,
                    'owner_name' => $oName,
                    'phone_no' => trim((string)($row['phone_no'] ?? '')),
                    'status' => 'reserved',
                    'is_reserved' => true,
                    'is_purchased' => false
                  ];
                }
                $stmt->close();
            }
        }

        if (function_exists('ensure_user_plot_ownership_table')) {
            ensure_user_plot_ownership_table($conn);
        }
        $chkUpo = $conn->query("SHOW TABLES LIKE 'user_plot_ownership'");
        if ($chkUpo && $chkUpo->num_rows > 0) {
            $upoQ = $conn->prepare("SELECT COALESCE(NULLIF(upo.plot_x,0), tp.plot_x, 0) AS plot_x, COALESCE(NULLIF(upo.plot_y,0), tp.plot_y, 0) AS plot_y, UPPER(TRIM(COALESCE(upo.ownership_status, 'OWNED'))) AS ost, tp.status AS tp_status, u.first_name, u.middle_name, u.last_name, u.suffix, u.phone_no FROM user_plot_ownership upo LEFT JOIN tbl_plots tp ON (upo.plot_id = tp.plot_id AND upo.plot_id > 0) LEFT JOIN tbl_user u ON upo.user_id = u.user_id WHERE (REPLACE(UPPER(TRIM(COALESCE(upo.section,''))), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(COALESCE(upo.section,''))) = '{$escSec}' OR REPLACE(UPPER(TRIM(COALESCE(tp.section,''))), 'SECTION ', '') = '{$escSec}') AND upo.verification_status = 'VERIFIED' AND upo.ownership_status IN ('RESERVED', 'PURCHASED', 'OWNED') AND upo.ownership_status != 'CANCELLED'");
            if ($upoQ) {
                $upoQ->execute();
                $upoRes = $upoQ->get_result();
                while ($uRow = $upoRes->fetch_assoc()) {
                    $px = (int)$uRow['plot_x'];
                    $py = (int)$uRow['plot_y'];
                    if ($px <= 0 || $py <= 0) continue;
                    $ostU = strtoupper(trim((string)($uRow['ost'] ?? '')));
                    if ($ostU === 'RESERVED' && !empty($uRow['tp_status']) && strtolower(trim($uRow['tp_status'])) === 'available') {
                        continue;
                    }
                    $key = $px . '-' . $py;
                    $uStatus = ($ostU === 'RESERVED') ? 'reserved' : 'purchased';
                    if (!isset($seen[$key])) {
                        $uName = trim(implode(' ', array_filter([
                            trim((string)($uRow['first_name'] ?? '')),
                            trim((string)($uRow['middle_name'] ?? '')),
                            trim((string)($uRow['last_name'] ?? '')),
                            trim((string)($uRow['suffix'] ?? ''))
                        ])));
                        if ($uName === '' || strcasecmp($uName, 'Unknown') === 0) {
                            $uName = ($uStatus === 'purchased') ? 'Purchased' : 'Reserved';
                        }
                        $seen[$key] = true;
                        $list[] = [
                            'plot_x' => $px,
                            'plot_y' => $py,
                            'owner_name' => $uName,
                            'phone_no' => trim((string)($uRow['phone_no'] ?? '')),
                            'status' => $uStatus,
                            'is_reserved' => ($uStatus === 'reserved'),
                            'is_purchased' => ($uStatus === 'purchased')
                        ];
                    }
                }
                $upoQ->close();
            }
        }

        $tpP = $conn->prepare("SELECT plot_x, plot_y FROM tbl_plots WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}') AND (LOWER(status) = 'purchased' OR LOWER(status) = 'occupied')");
        if ($tpP) {
            $tpP->execute();
            $tpRes = $tpP->get_result();
            while ($tpRow = $tpRes->fetch_assoc()) {
                $px = (int)$tpRow['plot_x'];
                $py = (int)$tpRow['plot_y'];
                if ($px <= 0 || $py <= 0) continue;
                $key = $px . '-' . $py;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $list[] = [
                        'plot_x' => $px,
                        'plot_y' => $py,
                        'owner_name' => 'Purchased',
                        'phone_no' => '',
                        'status' => 'purchased',
                        'is_reserved' => false,
                        'is_purchased' => true
                    ];
                }
            }
            $tpP->close();
        }

        $tpR = $conn->prepare("SELECT plot_x, plot_y FROM tbl_plots WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}') AND LOWER(status) = 'reserved'");
        if ($tpR) {
            $tpR->execute();
            $tpRRes = $tpR->get_result();
            while ($tpRRow = $tpRRes->fetch_assoc()) {
                $px = (int)$tpRRow['plot_x'];
                $py = (int)$tpRRow['plot_y'];
                if ($px <= 0 || $py <= 0) continue;
                $key = $px . '-' . $py;

                // Check if this reserved status is stale (associated booking was rejected or cancelled, and no active approved booking exists)
                $hasActiveBooking = false;
                $actChk = $conn->query("SELECT 1 FROM tbl_bookings b 
                    LEFT JOIN booking_plots bp ON bp.booking_id = b.id 
                    WHERE (REPLACE(UPPER(TRIM(b.section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(b.section)) = '{$escSec}' OR REPLACE(UPPER(TRIM(bp.section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(bp.section)) = '{$escSec}')
                      AND (
                          (bp.plot_x = $px AND bp.plot_y = $py)
                          OR b.plot = '$px-$py' OR b.plot = '$px, $py' OR b.plot = '$px,$py'
                          OR b.section_plot = '$px-$py' OR b.section_plot = '$px, $py'
                      )
                      AND LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved', 'completed') LIMIT 1");
                if ($actChk && $actChk->num_rows > 0) {
                    $hasActiveBooking = true;
                }
                if (!$hasActiveBooking) {
                    $grChk = $conn->query("SELECT 1 FROM guest_reservation_requests 
                        WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}')
                          AND plot_x = $px AND plot_y = $py
                          AND LOWER(TRIM(status)) IN ('approved', 'confirmed', 'account created') LIMIT 1");
                    if ($grChk && $grChk->num_rows > 0) {
                        $hasActiveBooking = true;
                    }
                }
                if (!$hasActiveBooking) {
                    $poChk = $conn->query("SELECT 1 FROM tbl_plot_owners 
                        WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}')
                          AND plot_x = $px AND plot_y = $py
                          AND active = 1 
                          AND TRIM(COALESCE(owner_name, '')) NOT IN ('', 'Reserved', 'Unknown', 'N/A') LIMIT 1");
                    if ($poChk && $poChk->num_rows > 0) {
                        $hasActiveBooking = true;
                    }
                }

                // If there's no active approved reservation, heal plot to available and do NOT mark reserved
                if (!$hasActiveBooking) {
                    @$conn->query("UPDATE tbl_plots SET status = 'available' WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}') AND plot_x = $px AND plot_y = $py AND LOWER(status) != 'occupied'");
                    continue;
                }

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $list[] = [
                        'plot_x' => $px,
                        'plot_y' => $py,
                        'owner_name' => 'Reserved',
                        'phone_no' => '',
                        'status' => 'reserved',
                        'is_reserved' => true,
                        'is_purchased' => false
                    ];
                }
            }
            $tpR->close();
        }

        // Query booking_plots table for reservations
        $chkBp = $conn->query("SHOW TABLES LIKE 'booking_plots'");
        if ($chkBp && $chkBp->num_rows > 0) {
            $bpQ = $conn->prepare("SELECT bp.plot_x, bp.plot_y, LOWER(TRIM(COALESCE(b.status, 'pending'))) AS bst, LOWER(TRIM(COALESCE(b.service_type, ''))) AS stype, LOWER(TRIM(COALESCE(b.purpose, ''))) AS bpurp, b.customer_name, b.full_name, b.contact_number, u.first_name, u.middle_name, u.last_name, u.suffix, u.phone_no 
                                   FROM booking_plots bp 
                                   JOIN tbl_bookings b ON b.id = bp.booking_id 
                                   LEFT JOIN tbl_user u ON b.user_id = u.user_id 
                                   WHERE (REPLACE(UPPER(TRIM(bp.section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(bp.section)) = '{$escSec}') AND LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved', 'completed')");
            if ($bpQ) {
                $bpQ->execute();
                $bpRes = $bpQ->get_result();
                while ($bpRow = $bpRes->fetch_assoc()) {
                    $px = (int)$bpRow['plot_x'];
                    $py = (int)$bpRow['plot_y'];
                    if ($px <= 0 || $py <= 0) continue;
                    $key = $px . '-' . $py;
                    $bpurp = strtolower(trim((string)($bpRow['bpurp'] ?? '')));
                    $stype = strtolower(trim((string)($bpRow['stype'] ?? '')));
                    $isCemSvc = ($bpurp === 'cemetery_service' || $stype === 'cleaning' || $stype === 'landscaping' || $stype === 'maintenance' || $stype === 'decoration');
                    $isPlotPurchase = ($stype === 'plot_purchase' || $bpurp === 'plot_purchase' || $bpurp === 'add_deceased' || $stype === 'burial' || $bpurp === 'burial' || $stype === 'interment' || $bpurp === 'interment' || in_array($stype, ['purchase', 'purchased'], true) || in_array($bpurp, ['purchase', 'purchased'], true));
                    $isReserveGrave = ($bpurp === 'reserve_grave' || $stype === 'reserve_grave' || in_array($stype, ['grave reservation', 'reservation', 'reserve', 'reserved', 'plot_reservation'], true) || in_array($bpurp, ['reservation', 'reserve', 'reserved', 'plot_reservation'], true));
                    if (!$isPlotPurchase && !$isReserveGrave) {
                        $isReserveGrave = true;
                    }
                    $bkgName = trim((string)($bpRow['customer_name'] ?? ''));
                    if ($bkgName === '' || strcasecmp($bkgName, 'Reserved') === 0) {
                        $bkgName = trim((string)($bpRow['full_name'] ?? ''));
                    }
                    if ($bkgName === '' || strcasecmp($bkgName, 'Reserved') === 0) {
                        $bkgName = trim(implode(' ', array_filter([
                            trim((string)($bpRow['first_name'] ?? '')),
                            trim((string)($bpRow['middle_name'] ?? '')),
                            trim((string)($bpRow['last_name'] ?? '')),
                            trim((string)($bpRow['suffix'] ?? ''))
                        ])));
                    }
                    if ($bkgName === '' || strcasecmp($bkgName, 'Unknown') === 0) {
                        $bkgName = $isPlotPurchase ? 'Purchased' : 'Reserved';
                    }
                    $bkgPhone = $bpRow['contact_number'] ?: ($bpRow['phone_no'] ?? '');

                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $list[] = [
                            'plot_x' => $px,
                            'plot_y' => $py,
                            'owner_name' => $bkgName,
                            'phone_no' => $bkgPhone,
                            'status' => $isPlotPurchase ? 'purchased' : 'reserved',
                            'is_reserved' => !$isPlotPurchase,
                            'is_purchased' => $isPlotPurchase
                        ];
                    }
                }
                $bpQ->close();
            }
        }

        // Query guest_reservation_requests table
        $chkGrr = $conn->query("SHOW TABLES LIKE 'guest_reservation_requests'");
        if ($chkGrr && $chkGrr->num_rows > 0) {
            $grrQ = $conn->prepare("SELECT plot_x, plot_y, full_name, contact_number FROM guest_reservation_requests WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}') AND LOWER(TRIM(status)) IN ('approved', 'confirmed', 'account created')");
            if ($grrQ) {
                $grrQ->execute();
                $grrRes = $grrQ->get_result();
                while ($grrRow = $grrRes->fetch_assoc()) {
                    $px = (int)$grrRow['plot_x'];
                    $py = (int)$grrRow['plot_y'];
                    if ($px <= 0 || $py <= 0) continue;
                    $key = $px . '-' . $py;
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $list[] = [
                            'plot_x' => $px,
                            'plot_y' => $py,
                            'owner_name' => trim((string)$grrRow['full_name']) ?: 'Reserved',
                            'phone_no' => trim((string)$grrRow['contact_number']),
                            'status' => 'reserved',
                            'is_reserved' => true,
                            'is_purchased' => false
                        ];
                    }
                }
                $grrQ->close();
            }
        }

        $bkgTbl = $conn->query("SHOW TABLES LIKE 'tbl_bookings'");
        if ($bkgTbl && $bkgTbl->num_rows > 0) {
            $bkgSql = "SELECT b.id, b.customer_name, b.full_name, b.contact_number, b.plot, b.section_plot, b.grave_location, b.plot_id, b.service_type, b.purpose,
                              u.first_name, u.middle_name, u.last_name, u.suffix, u.phone_no
                       FROM tbl_bookings b
                       LEFT JOIN tbl_user u ON b.user_id = u.user_id
                       WHERE (REPLACE(UPPER(TRIM(b.section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(b.section)) = '{$escSec}' OR b.plot LIKE '%{$escSec}%' OR b.grave_location LIKE '%{$escSec}%')
                         AND LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved', 'completed')
                       ORDER BY b.id DESC";
            if ($bkgStmt = $conn->prepare($bkgSql)) {
                $bkgStmt->execute();
                $bkgRes = $bkgStmt->get_result();
                while ($bRow = $bkgRes->fetch_assoc()) {
                    $bkgName = trim((string)($bRow['customer_name'] ?? ''));
                    if ($bkgName === '' || strcasecmp($bkgName, 'Reserved') === 0) {
                        $bkgName = trim((string)($bRow['full_name'] ?? ''));
                    }
                    if ($bkgName === '' || strcasecmp($bkgName, 'Reserved') === 0) {
                        $bkgName = trim(implode(' ', array_filter([
                            trim((string)($bRow['first_name'] ?? '')),
                            trim((string)($bRow['middle_name'] ?? '')),
                            trim((string)($bRow['last_name'] ?? '')),
                            trim((string)($bRow['suffix'] ?? ''))
                        ])));
                    }
                    $bpurp = strtolower(trim((string)($bRow['purpose'] ?? '')));
                    $stype = strtolower(trim((string)($bRow['service_type'] ?? '')));
                    $isCemSvc = ($bpurp === 'cemetery_service' || $stype === 'cleaning' || $stype === 'landscaping' || $stype === 'maintenance' || $stype === 'decoration');
                    if ($isCemSvc) continue;
                    $isPlotPurchase = ($stype === 'plot_purchase' || $bpurp === 'plot_purchase' || $bpurp === 'add_deceased' || $stype === 'burial' || $bpurp === 'burial' || $stype === 'interment' || $bpurp === 'interment' || in_array($stype, ['purchase', 'purchased'], true) || in_array($bpurp, ['purchase', 'purchased'], true));
                    $isReserveGrave = ($bpurp === 'reserve_grave' || $stype === 'reserve_grave' || in_array($stype, ['grave reservation', 'reservation', 'reserve', 'reserved', 'plot_reservation'], true) || in_array($bpurp, ['reservation', 'reserve', 'reserved', 'plot_reservation'], true));
                    if (!$isPlotPurchase && !$isReserveGrave) {
                        $isReserveGrave = true;
                    }
                    if ($bkgName === '' || strcasecmp($bkgName, 'Unknown') === 0) {
                        $bkgName = $isPlotPurchase ? 'Purchased' : 'Reserved';
                    }
                    $bkgPhone = $bRow['contact_number'] ?: ($bRow['phone_no'] ?? '');

                    // Collect EVERY plot pair (multi-plot bookings store comma-joined values like "44,1, 31,3").
                    $plotPairs = [];
                    $rawPlot = trim((string)($bRow['plot'] ?? ''));
                    $rawGrave = trim((string)($bRow['grave_location'] ?? ''));
                    $rawSecPlot = trim((string)($bRow['section_plot'] ?? ''));
                    foreach ([$rawPlot, $rawGrave, $rawSecPlot] as $rawStr) {
                        if ($rawStr === '') continue;
                        if (preg_match_all('/(\d{1,3})\s*,\s*(\d{1,3})/', $rawStr, $mmAll, PREG_SET_ORDER)) {
                            foreach ($mmAll as $m) {
                                $ax = (int)$m[1]; $ay = (int)$m[2];
                                if ($ax > 0 && $ay > 0 && $ax <= 999 && $ay <= 999) {
                                    $plotPairs[$ax . '-' . $ay] = [$ax, $ay];
                                }
                            }
                        }
                        if (preg_match_all('/(?:[A-Za-z]-)?(\d{1,3})[-\s](\d{1,3})/', $rawStr, $mmAll2, PREG_SET_ORDER)) {
                            foreach ($mmAll2 as $m) {
                                $ax = (int)$m[1]; $ay = (int)$m[2];
                                if ($ax > 0 && $ay > 0 && $ax <= 999 && $ay <= 999) {
                                    $plotPairs[$ax . '-' . $ay] = [$ax, $ay];
                                }
                            }
                        }
                        if (preg_match_all('/(?:[A-Za-z]-)?(\d{3})(\d{3})/', $rawStr, $mmAll3, PREG_SET_ORDER)) {
                            foreach ($mmAll3 as $m) {
                                $ax = (int)$m[1]; $ay = (int)$m[2];
                                if ($ax > 0 && $ay > 0) {
                                    $plotPairs[$ax . '-' . $ay] = [$ax, $ay];
                                }
                            }
                        }
                    }

                    $bId = (int)$bRow['id'];
                    if ($bId > 0) {
                        $bpQ = @$conn->query("SELECT plot_x, plot_y FROM booking_plots WHERE booking_id = {$bId} AND (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}')");
                        if ($bpQ) {
                            while ($bpR = $bpQ->fetch_assoc()) {
                                $bx = (int)$bpR['plot_x']; $by = (int)$bpR['plot_y'];
                                if ($bx > 0 && $by > 0) {
                                    $plotPairs[$bx . '-' . $by] = [$bx, $by];
                                }
                            }
                        }
                    }

                    $bPid = (int)($bRow['plot_id'] ?? 0);
                    if ($bPid > 0) {
                        $pQ = @$conn->query("SELECT plot_x, plot_y FROM tbl_plots WHERE plot_id = {$bPid} AND (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}')");
                        if ($pQ && ($pR = $pQ->fetch_assoc())) {
                            $bx = (int)$pR['plot_x']; $by = (int)$pR['plot_y'];
                            if ($bx > 0 && $by > 0) {
                                $plotPairs[$bx . '-' . $by] = [$bx, $by];
                            }
                        }
                    }

                    foreach ($plotPairs as $pp) {
                        $px = (int)$pp[0]; $py = (int)$pp[1];
                        if ($px <= 0 || $py <= 0) continue;
                        $key = $px . '-' . $py;
                        if (isset($seen[$key])) {
                            foreach ($list as &$existing) {
                                if ((int)$existing['plot_x'] === $px && (int)$existing['plot_y'] === $py) {
                                    if (empty($existing['owner_name']) || $existing['owner_name'] === 'Purchased' || $existing['owner_name'] === 'Reserved') {
                                        $existing['owner_name'] = $bkgName;
                                        $existing['phone_no'] = $bkgPhone;
                                    }
                                    if ($isPlotPurchase) {
                                        $existing['status'] = 'purchased';
                                        $existing['is_purchased'] = true;
                                        $existing['is_reserved'] = false;
                                    }
                                    break;
                                }
                            }
                            unset($existing);
                        } else {
                            $seen[$key] = true;
                            $list[] = [
                                'plot_x' => $px,
                                'plot_y' => $py,
                                'owner_name' => $bkgName,
                                'phone_no' => $bkgPhone,
                                'status' => $isPlotPurchase ? 'purchased' : 'reserved',
                                'is_reserved' => !$isPlotPurchase,
                                'is_purchased' => $isPlotPurchase
                            ];
                        }
                    }
                }
                $bkgStmt->close();
            }
        }

        $occPlots = [];
        $occCheckQ = $conn->query("SELECT DISTINCT plot_x, plot_y FROM tbl_deceased WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}')");
        if ($occCheckQ) {
            while ($ocRow = $occCheckQ->fetch_assoc()) {
                $ox = (int)$ocRow['plot_x'];
                $oy = (int)$ocRow['plot_y'];
                $occPlots[$ox . '-' . $oy] = true;
            }
        }
        $tpOccQ = $conn->query("SELECT DISTINCT plot_x, plot_y FROM tbl_plots WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}') AND LOWER(status) = 'occupied'");
        if ($tpOccQ) {
            while ($ocRow = $tpOccQ->fetch_assoc()) {
                $ox = (int)$ocRow['plot_x'];
                $oy = (int)$ocRow['plot_y'];
                $occPlots[$ox . '-' . $oy] = true;
            }
        }

        $validList = array_values(array_filter($list, function($item) use ($occPlots) {
            $px = (int)($item['plot_x'] ?? 0);
            $py = (int)($item['plot_y'] ?? 0);
            if (isset($occPlots[$px . '-' . $py])) {
                return false;
            }
            $name = trim((string)($item['owner_name'] ?? ''));
            return ($item['is_reserved'] ?? false) || ($item['is_purchased'] ?? false) || ($name !== '' && strcasecmp($name, 'Unknown') !== 0 && strcasecmp($name, 'N/A') !== 0);
        }));

        if (!$isAdmin) {
            foreach ($validList as &$item) {
                $item['owner_name'] = ($item['is_purchased'] ?? false) ? 'Purchased' : 'Reserved';
                $item['phone_no'] = '';
            }
            unset($item);
        }

        echo json_encode(['success' => true, 'reserved' => $validList]);
    } catch (Exception $e) {
        error_log('popup.php get_reserved_plots error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } finally {
        if (isset($conn)) { $conn->close(); }
    }
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'book_service') {

    if (!include_once $connPath) {
        echo "<script>alert('Database connection error. Please try again.'); window.location.href=(window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php';</script>";
        exit;
    }

    $section = $_POST['section'];
    $plot_x = $_POST['plot_x'];
    $plot_y = $_POST['plot_y'];
    $service_type = $_POST['service_type'];
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

    $plotCheck = $conn->prepare("SELECT COUNT(*) as count FROM tbl_deceased WHERE section = ? AND plot_x = ? AND plot_y = ?");
    $plotCheck->bind_param("sii", $section, $plot_x, $plot_y);
    $plotCheck->execute();
    $plotResult = $plotCheck->get_result();
    $plotCount = $plotResult ? $plotResult->fetch_assoc()['count'] : 0;
    $plotCheck->close();

    if ($plotCount == 0) {
        echo "<script>alert('Error: Cannot book service for unreserved plot. Plot " . $section . "-" . $plot_x . "-" . $plot_y . " is not reserved or occupied.'); window.location.href=(window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php';</script>";
        exit;
    }

    $details = "Service booked: " . ucfirst($service_type) . " for plot " . $section . "-" . $plot_x . "-" . $plot_y;

    try {
        $sql = "INSERT INTO tbl_logs (action, details, timestamp, user_id) VALUES ('SERVICE_BOOKING', '" . $details . "', NOW(), " . $user_id . ")";
        $result = $conn->query($sql);

        if ($result) {
            echo "<script>alert('Service booked successfully!'); window.location.href='../logs.php';</script>";
            exit;
        } else {
            echo "<script>alert('Database error: Could not save booking. Please try again.'); window.location.href=(window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php';</script>";
            exit;
        }
    } catch (Exception $e) {
      error_log('popup.php book_service error: ' . $e->getMessage());
      echo "<script>alert('Error: " . $e->getMessage() . ". Please try again.'); window.location.href=(window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php';</script>";
        exit;
    }
}

if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'edit_deceased') {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator authorization required to edit deceased records.']);
        exit;
    }
    include_once $connPath;
    try {
        $required = ['deceased_id','first_name','last_name','bday','date_died','born_in','contact_no','funeral_service'];
        foreach ($required as $f) { if (!isset($_POST[$f]) || trim($_POST[$f])==='') { throw new Exception('Missing field: ' . $f); } }
        $deceased_id = intval($_POST['deceased_id']);
        $first_name = strtoupper(trim($_POST['first_name']));
        $middle_name = strtoupper(trim($_POST['middle_name'] ?? ''));
        $last_name = strtoupper(trim($_POST['last_name']));
        $raw_suffix = isset($_POST['suffix']) ? strtoupper(trim($_POST['suffix'])) : '';
        $other_suffix = isset($_POST['suffix_other']) ? strtoupper(trim($_POST['suffix_other'])) : '';
        $suffix = ($raw_suffix === 'OTHER') ? $other_suffix : $raw_suffix;
        $bday = trim($_POST['bday']);
        $date_died = trim($_POST['date_died']);
        $born_in = strtoupper(trim($_POST['born_in']));
        $contact_no = preg_replace('/[^0-9]/','', $_POST['contact_no']);
        $funeral_service = strtoupper(trim($_POST['funeral_service']));

        if (!preg_match('/^[A-Z\s]+$/', $first_name)) throw new Exception('Invalid first name');
        if ($middle_name !== '' && !preg_match('/^[A-Z\s]+$/', $middle_name)) throw new Exception('Invalid middle name');
        if (!preg_match('/^[A-Z\s]+$/', $last_name)) throw new Exception('Invalid last name');
        if ($suffix !== '' && !preg_match('/^[A-Z\s\.]+$/', $suffix)) throw new Exception('Invalid suffix');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bday)) throw new Exception('Invalid birth date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_died)) throw new Exception('Invalid death date');
        if ($bday > date('Y-m-d')) throw new Exception('Birth date cannot be in the future');
        if ($date_died > date('Y-m-d')) throw new Exception('Death date cannot be in the future');
        if ($date_died < $bday) throw new Exception('Death date cannot be before birth date');
        if (!preg_match('/^09\d{9}$/', $contact_no)) throw new Exception('Contact number must be an 11-digit mobile number starting with 09');

        $res = $conn->prepare("SELECT section, plot_x, plot_y FROM tbl_deceased WHERE deceased_id = ? LIMIT 1");
        if (!$res) throw new Exception('Prepare failed: ' . $conn->error);
        $res->bind_param('i', $deceased_id);
        $res->execute();
        $row = $res->get_result()->fetch_assoc();
        $res->close();
        if (!$row) { throw new Exception('Deceased record not found'); }
        $section = strtoupper(trim($row['section']));
        $plot_x = intval($row['plot_x']);
        $plot_y = intval($row['plot_y']);

        $has_suffix_col = false; $has_edited_by_col = false;
        if ($c1 = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'suffix'")) { $has_suffix_col = $c1->num_rows > 0; $c1->free(); }
        if ($c2 = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'edited_by'")) { $has_edited_by_col = $c2->num_rows > 0; $c2->free(); }
        if (!$has_edited_by_col) { $conn->query("ALTER TABLE tbl_deceased ADD COLUMN edited_by varchar(100) DEFAULT NULL"); $has_edited_by_col = true; }

        $checkDeceasedCols = [
            'booker_name' => "VARCHAR(150) DEFAULT NULL",
            'payment_plan' => "VARCHAR(50) DEFAULT 'Cash'",
            'payment_term' => "INT DEFAULT 0",
            'payment_status' => "VARCHAR(50) DEFAULT 'PAID'",
            'total_amount' => "DECIMAL(10,2) DEFAULT 0.00",
            'amount_paid' => "DECIMAL(10,2) DEFAULT 0.00",
            'monthly_due' => "DECIMAL(10,2) DEFAULT 0.00"
        ];
        foreach ($checkDeceasedCols as $cName => $cDef) {
            $cCheck = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE '$cName'");
            if ($cCheck && $cCheck->num_rows === 0) {
                $conn->query("ALTER TABLE tbl_deceased ADD COLUMN `$cName` $cDef");
            }
            if ($cCheck) $cCheck->free();
        }

        $booker_name = strtoupper(trim($_POST['booker_name'] ?? ''));
        $payment_plan = ucfirst(strtolower(trim($_POST['payment_plan'] ?? 'Cash')));
        if (!in_array($payment_plan, ['Cash', 'Installment'])) { $payment_plan = 'Cash'; }
        $payment_term = ($payment_plan === 'Installment') ? max(1, intval($_POST['payment_term'] ?? 12)) : 0;
        $payment_status = strtoupper(trim($_POST['payment_status'] ?? 'PAID'));
        if ($payment_status !== 'HAS DUES') { $payment_status = 'PAID'; }
        $total_amount = floatval($_POST['total_amount'] ?? 85000.00);
        $amount_paid = floatval($_POST['amount_paid'] ?? 0.00);
        if ($payment_status === 'PAID' && $amount_paid < $total_amount && $total_amount > 0) {
            $amount_paid = $total_amount;
        }
        $monthly_due = ($payment_plan === 'Installment' && $payment_term > 0) ? round($total_amount / $payment_term, 2) : 0.00;

        if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) { @session_start(); }
        $edited_by = 'Unknown';
        if (isset($_SESSION['user_id'])) {
            $uid = intval($_SESSION['user_id']);
            if ($uRes = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = $uid")) {
                if ($uRes->num_rows > 0) { $u = $uRes->fetch_assoc(); $edited_by = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); }
                $uRes->free();
            }
        }

        if ($has_suffix_col) {
            $sql = "UPDATE tbl_deceased SET first_name=?, middle_name=?, last_name=?, suffix=?, bday=?, date_died=?, born_in=?, contact_no=?, funeral_service=?, edited_by=?, booker_name=?, payment_plan=?, payment_term=?, payment_status=?, total_amount=?, amount_paid=?, monthly_due=? WHERE deceased_id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $sx = $suffix !== '' ? $suffix : NULL;
            $stmt->bind_param('ssssssssssssisdddi', $first_name, $middle_name, $last_name, $sx, $bday, $date_died, $born_in, $contact_no, $funeral_service, $edited_by, $booker_name, $payment_plan, $payment_term, $payment_status, $total_amount, $amount_paid, $monthly_due, $deceased_id);
        } else {
            $sql = "UPDATE tbl_deceased SET first_name=?, middle_name=?, last_name=?, bday=?, date_died=?, born_in=?, contact_no=?, funeral_service=?, edited_by=?, booker_name=?, payment_plan=?, payment_term=?, payment_status=?, total_amount=?, amount_paid=?, monthly_due=? WHERE deceased_id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('sssssssssssisdddi', $first_name, $middle_name, $last_name, $bday, $date_died, $born_in, $contact_no, $funeral_service, $edited_by, $booker_name, $payment_plan, $payment_term, $payment_status, $total_amount, $amount_paid, $monthly_due, $deceased_id);
        }
        if (!$stmt->execute()) { throw new Exception('Failed to update record: ' . $stmt->error); }
        $stmt->close();

        $conn->query("CREATE TABLE IF NOT EXISTS tbl_logs (id INT AUTO_INCREMENT PRIMARY KEY, action VARCHAR(100) NOT NULL, details TEXT, timestamp DATETIME NOT NULL, user_id INT NULL)");
        if ($log = $conn->prepare("INSERT INTO tbl_logs(action, details, timestamp, user_id) VALUES('EDIT_DECEASED', ?, NOW(), ?)")) {
            $details = 'Edited deceased ID ' . $deceased_id . ' at Section ' . $section . ', Plot ' . $plot_x . '-' . $plot_y;
            $uid = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
            $log->bind_param('si', $details, $uid);
            $log->execute();
            $log->close();
        }

        echo json_encode(['success'=>true,'message'=>'Deceased record updated successfully','section'=>$section,'plot_x'=>$plot_x,'plot_y'=>$plot_y]);
    } catch (Exception $e) {
      error_log('popup.php edit_deceased error: ' . $e->getMessage());
      echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    } finally {
        if (isset($conn)) { $conn->close(); }
    }
    exit;
}

if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'get_plot_booking_status') {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!@include_once $connPath) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection is unavailable.']);
        exit;
    }
    if (!isset($conn) || !($conn instanceof mysqli)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection is unavailable.']);
        exit;
    }
    try {
        $required = ['section','plot_x','plot_y'];
        foreach ($required as $r) { 
            if (!isset($_POST[$r]) || trim($_POST[$r])==='') { 
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing field: ' . $r]);
                exit;
            } 
        }
        $section = strtoupper(trim($_POST['section']));
        $plot_x = intval($_POST['plot_x']);
        $plot_y = intval($_POST['plot_y']);

        if (in_array($section, ['G', 'H', 'I', 'J'], true)) {
            @ob_clean();
            echo json_encode([
                'success' => true,
                'status' => 'UNAVAILABLE',
                'is_reserved' => false,
                'can_book' => false,
                'lot_price' => 0,
                'interment_fee' => 0,
                'total' => 0,
                'message' => 'Section ' . $section . ' is currently unavailable.'
            ]);
            exit;
        }

        if ($plot_x <= 0 || $plot_y <= 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invalid plot coordinates.']);
            exit;
        }

        require_once $pricingPath;
        $lawn_type = 'Lawn (Regular)';
        $secSql = "SELECT lawn_type, base_price FROM sections WHERE UPPER(TRIM(section_name)) = ? LIMIT 1";
        $secBasePrice = 85000.00;
        if ($secStmt = $conn->prepare($secSql)) {
            $secStmt->bind_param('s', $section);
            $secStmt->execute();
            $secRes = $secStmt->get_result();
            if ($secRes && ($secRow = $secRes->fetch_assoc())) {
                if (!empty($secRow['lawn_type'])) {
                    $lawn_type = $secRow['lawn_type'];
                }
                if (!empty($secRow['base_price']) && (float)$secRow['base_price'] > 0) {
                    $secBasePrice = (float)$secRow['base_price'];
                }
            }
            $secStmt->close();
        }

        $official = get_plot_official_pricing($section, $plot_x, $plot_y, $lawn_type);
        $price = (float)($official['lot_price'] ?? $secBasePrice);
        $interment_fee = (float)($official['interment_fee'] ?? 38000.00);
        $total_price = (float)($official['total_price'] ?? ($price + $interment_fee));
        $lawn_type = $official['lawn_type'] ?? $lawn_type;
        $plotStatus = 'Available';

        $plotId = 0;
        $plotRowNo = $plot_y;
        $plotNum = $plot_x;
        $plotLat = null;
        $plotLng = null;

        if (function_exists('ensure_plot_coordinates_columns')) {
            ensure_plot_coordinates_columns($conn);
        }
        $plotStmt = $conn->prepare("SELECT plot_id, section, `row`, plot_number, COALESCE(price,0) AS base_price, status, lawn_type, latitude, longitude FROM tbl_plots WHERE section = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
        if ($plotStmt) {
            $plotStmt->bind_param('sii', $section, $plot_x, $plot_y);
            $plotStmt->execute();
            $plotRes = $plotStmt->get_result();
            if ($plotRes && ($plotRow = $plotRes->fetch_assoc())) {
                $plotId = (int)($plotRow['plot_id'] ?? 0);
                $plotRowNo = (int)($plotRow['row'] ?? $plot_y);
                $plotNum = (int)($plotRow['plot_number'] ?? $plot_x);
                $plotLat = ($plotRow['latitude'] !== null && $plotRow['latitude'] !== '') ? (float)$plotRow['latitude'] : null;
                $plotLng = ($plotRow['longitude'] !== null && $plotRow['longitude'] !== '') ? (float)$plotRow['longitude'] : null;
                $dbPrice = (float)($plotRow['base_price'] ?? 0);
                if ($dbPrice > 0) {
                    $price = $dbPrice;
                }
                if (!empty($plotRow['lawn_type'])) {
                    $lawn_type = $plotRow['lawn_type'];
                }
                $plotStatus = ucfirst(strtolower(trim((string)($plotRow['status'] ?? 'Available'))));
            }
            $plotStmt->close();
        }

        $plotTbl = $conn->query("SHOW TABLES LIKE 'plots'");
        if ($plotTbl && $plotTbl->num_rows > 0) {
            if ($ifStmt = $conn->prepare("SELECT COALESCE(base_price,0) AS base_price, COALESCE(interment_fee,0) AS interment_fee, status, lot_type FROM plots WHERE section = ? AND plot_x = ? AND plot_y = ? LIMIT 1")) {
                $ifStmt->bind_param('sii', $section, $plot_x, $plot_y);
                $ifStmt->execute();
                $ifRes = $ifStmt->get_result();
                if ($ifRes && ($ifRow = $ifRes->fetch_assoc())) {
                    $pBase = (float)($ifRow['base_price'] ?? 0);
                    $pFee = (float)($ifRow['interment_fee'] ?? 0);
                    if ($pBase > 0) { $price = $pBase; }
                    if ($pFee > 0) { $interment_fee = $pFee; }
                    if (!empty($ifRow['lot_type'])) { $lawn_type = $ifRow['lot_type']; }
                    $plotStatus = ucfirst(strtolower(trim((string)($ifRow['status'] ?? 'Available'))));
                }
                $ifStmt->close();
            }
        }
        $total_price = $price + $interment_fee;

        $isCorner = (function_exists('is_corner_premium_plot') && is_corner_premium_plot($section, $plot_x, $plot_y))
                 || (function_exists('isCornerPlot') && isCornerPlot($section, $plot_x, $plot_y));
        if ($isCorner || stripos($lawn_type, 'premium') !== false || $price >= 89000.00) {
            $price = 89000.00;
            $lawn_type = 'Lawn (Premium)';
            $total_price = $price + $interment_fee;
        }

        $status = 'Available';
        $deceased = [];      
        $deceased_name = ''; 
        $reservation = null; 
        $max_occupants = 4;  
        $occupant_count = 0; 
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);

        $occ = $conn->prepare("SELECT * FROM tbl_deceased WHERE section = ? AND plot_x = ? AND plot_y = ? ORDER BY date_died DESC, deceased_id DESC LIMIT 4");
        if ($occ) {
            $occ->bind_param('sii', $section, $plot_x, $plot_y);
            $occ->execute();
            $occRes = $occ->get_result();
            if ($occRes) { $occupant_count = $occRes->num_rows; }
            if ($occRes && $occRes->num_rows > 0) {
                $status = 'Occupied';
                $rawOccs = [];
                $plotFamilyPhone = '';
                $plotFuneralSvc = '';
                $plotBirthplace = '';
                while ($row = $occRes->fetch_assoc()) {
                    if (!empty($row['contact_no']) && empty($plotFamilyPhone)) { $plotFamilyPhone = trim($row['contact_no']); }
                    if (!empty($row['funeral_service']) && empty($plotFuneralSvc)) { $plotFuneralSvc = trim($row['funeral_service']); }
                    if (!empty($row['born_in']) && empty($plotBirthplace)) { $plotBirthplace = trim($row['born_in']); }
                    $rawOccs[] = $row;
                }

                // If plot family phone is empty, check tbl_user for the owner's phone
                if (empty($plotFamilyPhone) && !empty($rawOccs[0]['user_id'])) {
                    $uCheck = $conn->prepare("SELECT phone_no FROM tbl_user WHERE user_id = ? LIMIT 1");
                    if ($uCheck) {
                        $decUid = (int)$rawOccs[0]['user_id'];
                        $uCheck->bind_param('i', $decUid);
                        $uCheck->execute();
                        $uRes = $uCheck->get_result();
                        if ($uRes && ($uData = $uRes->fetch_assoc())) {
                            $plotFamilyPhone = trim($uData['phone_no'] ?? '');
                        }
                        $uCheck->close();
                    }
                }

                foreach ($rawOccs as $row) {
                    $name_parts = array_filter([
                        trim($row['first_name'] ?? ''),
                        trim($row['middle_name'] ?? ''),
                        trim($row['last_name'] ?? ''),
                        trim($row['suffix'] ?? '')
                    ]);
                    $row['full_name'] = implode(' ', $name_parts);
                    if (!empty($row['bday'])) { $row['formatted_birth_date'] = date('F j, Y', strtotime($row['bday'])); }
                    if (!empty($row['date_died'])) { $row['formatted_death_date'] = date('F j, Y', strtotime($row['date_died'])); }
                    $row['interment_date'] = !empty($row['interment_date']) ? $row['interment_date'] : ($row['date_died'] ?? null);

                    if (empty($row['contact_no']) && !empty($plotFamilyPhone)) {
                        $row['contact_no'] = $plotFamilyPhone;
                    }
                    if (empty($row['funeral_service']) && !empty($plotFuneralSvc)) {
                        $row['funeral_service'] = $plotFuneralSvc;
                    }
                    if (empty($row['born_in']) && !empty($plotBirthplace)) {
                        $row['born_in'] = $plotBirthplace;
                    }

                    if (!$isAdmin && (int)($row['user_id'] ?? 0) !== $currentUserId) {
                        $row['contact_no'] = '—';
                    }

                    $deceased[] = $row;
                }
                $deceased_name = $deceased[0]['full_name'] ?? '';
            }
            $occ->close();
        }        
        if ($status !== 'Occupied') {
            $isPurchasedPlot = false;
            $purchasedBuyerName = '';
            $purchasedPhone = '';

            $tpStmt = $conn->prepare("SELECT status FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND ((plot_x = ? AND plot_y = ?) OR (plot_x = ? AND plot_y = ?)) LIMIT 1");
            if ($tpStmt) {
                $tpStmt->bind_param('siiii', $section, $plot_x, $plot_y, $plot_y, $plot_x);
                $tpStmt->execute();
                $tpRes = $tpStmt->get_result();
                if ($tpRes && ($tpRow = $tpRes->fetch_assoc())) {
                    $plotStatus = ucfirst(strtolower(trim((string)($tpRow['status'] ?? 'Available'))));
                }
                $tpStmt->close();
            }

            $cleanSec = strtoupper(trim(preg_replace('/^SECTION\s*/i', '', $section)));
            $escSec = $conn->real_escape_string($cleanSec);

            if (!$isPurchasedPlot) {
                $upoStmt = $conn->prepare("SELECT upo.ownership_status, u.first_name, u.middle_name, u.last_name, u.suffix, u.phone_no, u.email 
                                           FROM user_plot_ownership upo 
                                           LEFT JOIN tbl_user u ON u.user_id = upo.user_id 
                                           WHERE (REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(upo.section)) = '{$escSec}') 
                                             AND ((upo.plot_x = ? AND upo.plot_y = ?) OR (upo.plot_x = ? AND upo.plot_y = ?))
                                             AND (upo.verification_status IS NULL OR upo.verification_status != 'REJECTED') 
                                           ORDER BY CASE WHEN upo.verification_status = 'VERIFIED' THEN 1 ELSE 2 END, upo.id DESC LIMIT 1");
                if ($upoStmt) {
                    $upoStmt->bind_param('iiii', $plot_x, $plot_y, $plot_y, $plot_x);
                    $upoStmt->execute();
                    $upoRes = $upoStmt->get_result();
                    if ($upoRes && ($uRow = $upoRes->fetch_assoc())) {
                        $ost = strtoupper(trim((string)($uRow['ownership_status'] ?? '')));
                        if ($ost === 'PURCHASED' || $ost === 'OWNED') {
                            $purchasedBuyerName = trim(implode(' ', array_filter([$uRow['first_name'] ?? '', $uRow['middle_name'] ?? '', $uRow['last_name'] ?? '', $uRow['suffix'] ?? ''])));
                            if ($purchasedBuyerName !== '') {
                                $isPurchasedPlot = true;
                                $purchasedPhone = $uRow['phone_no'] ?? '';
                                $purchasedEmail = $uRow['email'] ?? '';
                            }
                        }
                    }
                    $upoStmt->close();
                }
            }

            // Build all possible string variations of plot coordinates for matching in tbl_bookings
            $allPlotStrings = [];
            foreach ([[$plot_x, $plot_y], [$plot_y, $plot_x]] as $coordPair) {
                $cx = $coordPair[0]; $cy = $coordPair[1];
                $pPad = sprintf('%03d%03d', $cx, $cy);
                $allPlotStrings[] = $pPad;
                $allPlotStrings[] = $section . '-' . $pPad;
                $allPlotStrings[] = $section . ' ' . $pPad;
                $allPlotStrings[] = $cx . '-' . $cy;
                $allPlotStrings[] = $section . '-' . $cx . '-' . $cy;
                $allPlotStrings[] = $section . ' ' . $cx . '-' . $cy;
                $allPlotStrings[] = $section . ' ' . $cx . ',' . $cy;
                $allPlotStrings[] = $section . ' ' . $cx . ', ' . $cy;
                $allPlotStrings[] = $section . '- ' . $cx . ',' . $cy;
                $allPlotStrings[] = $section . '- ' . $cx . ', ' . $cy;
                $allPlotStrings[] = $section . '-' . $cx . ',' . $cy;
                $allPlotStrings[] = $section . '-' . $cx . ', ' . $cy;
                $allPlotStrings[] = $cx . ',' . $cy;
                $allPlotStrings[] = $cx . ', ' . $cy;
            }
            $allPlotStrings = array_values(array_unique(array_filter($allPlotStrings)));
            $inEscaped = "'" . implode("','", array_map(function($s) use ($conn) { return $conn->real_escape_string($s); }, $allPlotStrings)) . "'";

            $purchasedEmail = '';
            $purchasedBookingId = 0;
            $purchasedUserId = 0;

            $bkpSql = "SELECT b.id, b.customer_name, b.full_name, b.customer_email, b.email, b.contact_number, b.service_type, b.purpose, b.user_id, b.payment_plan, b.payment_term, b.total_amount, b.amount_paid, b.payment_type 
                       FROM tbl_bookings b 
                       WHERE (REPLACE(UPPER(TRIM(b.section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(b.section)) = '{$escSec}' OR b.plot LIKE '%{$escSec}%' OR b.grave_location LIKE '%{$escSec}%' OR b.section_plot LIKE '%{$escSec}%') 
                         AND (
                           b.plot IN ({$inEscaped})
                           OR b.grave_location IN ({$inEscaped})
                           OR b.section_plot IN ({$inEscaped})
                           OR ((b.plot_x = {$plot_x} AND b.plot_y = {$plot_y}) OR (b.plot_x = {$plot_y} AND b.plot_y = {$plot_x}))
                           OR b.plot_id IN (SELECT plot_id FROM tbl_plots WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}') AND ((plot_x = {$plot_x} AND plot_y = {$plot_y}) OR (plot_x = {$plot_y} AND plot_y = {$plot_x})))
                           OR b.id IN (SELECT bp.booking_id FROM booking_plots bp WHERE (REPLACE(UPPER(TRIM(bp.section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(bp.section)) = '{$escSec}') AND ((bp.plot_x = {$plot_x} AND bp.plot_y = {$plot_y}) OR (bp.plot_x = {$plot_y} AND bp.plot_y = {$plot_x})))
                         )
                         AND ((b.service_type = 'plot_purchase' OR b.purpose = 'plot_purchase' OR LOWER(TRIM(COALESCE(b.booking_type,''))) = 'plot_purchase') AND LOWER(TRIM(COALESCE(b.purpose,''))) != 'reserve_grave' AND LOWER(TRIM(COALESCE(b.service_type,''))) != 'reserve_grave') 
                         AND LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved', 'completed') 
                       ORDER BY CASE WHEN LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved') THEN 1 ELSE 2 END, b.id DESC LIMIT 1";
            $bkpRes = @$conn->query($bkpSql);
            if ($bkpRes && ($bkpRow = $bkpRes->fetch_assoc())) {
                $candidateBuyer = trim($bkpRow['customer_name'] ?: ($bkpRow['full_name'] ?: ''));
                if ($candidateBuyer !== '' && strcasecmp($candidateBuyer, 'Reserved') !== 0 && strcasecmp($candidateBuyer, 'Unknown') !== 0) {
                    $isPurchasedPlot = true;
                    $purchasedBuyerName = $candidateBuyer;
                    $purchasedPhone = $bkpRow['contact_number'] ?? '';
                    $purchasedEmail = $bkpRow['customer_email'] ?: ($bkpRow['email'] ?? '');
                    $purchasedBookingId = (int)$bkpRow['id'];
                    $purchasedUserId = (int)($bkpRow['user_id'] ?? 0);
                }
            }

            if ($plotStatus === 'Purchased') {
                $isPurchasedPlot = true;
                if ($purchasedBuyerName === '') {
                    $purchasedBuyerName = 'Purchased';
                }
            }

            if ($isPurchasedPlot) {
                $status = 'Purchased';
                $reservation = [
                    'owner_name' => $purchasedBuyerName ?: 'Purchased',
                    'first_name' => '',
                    'middle_name' => '',
                    'last_name' => '',
                    'suffix' => '',
                    'bday' => '',
                    'phone_no' => $purchasedPhone,
                    'customer_email' => $purchasedEmail,
                    'email' => $purchasedEmail,
                    'address' => '',
                    'user_id' => $purchasedUserId,
                    'booking_id' => $purchasedBookingId,
                    'is_purchased' => true
                ];
            } else {
                $ownerName = '';
                $ownerPhone = '';
                $ownerAddress = '';
                $resBday = '';
                $resFirst = '';
                $resMiddle = '';
                $resLast = '';
                $resSuffix = '';
                $resUserId = 0;
                $resBookingId = 0;

                // 1. Check tbl_plot_owners
                $tbl = @$conn->query("SHOW TABLES LIKE 'tbl_plot_owners'");
                if ($tbl && $tbl->num_rows > 0) {
                    $poSql = "SELECT id, owner_name, first_name, middle_name, last_name, suffix, bday, phone_no, address, reserved_by 
                              FROM tbl_plot_owners 
                              WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}') 
                                AND plot_x = {$plot_x} AND plot_y = {$plot_y} 
                                AND (active = 1 OR active IS NULL) 
                              ORDER BY id DESC LIMIT 1";
                    $resRes = @$conn->query($poSql);
                    if ($resRes && ($oRow = $resRes->fetch_assoc())) {
                        $resFirst = trim((string)($oRow['first_name'] ?? ''));
                        $resMiddle = trim((string)($oRow['middle_name'] ?? ''));
                        $resLast = trim((string)($oRow['last_name'] ?? ''));
                        $resSuffix = trim((string)($oRow['suffix'] ?? ''));
                        $resBday = $oRow['bday'] ?? '';
                        $ownerPhone = trim((string)($oRow['phone_no'] ?? ''));
                        $ownerAddress = trim((string)($oRow['address'] ?? ''));
                        $resUserId = (int)($oRow['reserved_by'] ?? 0);

                        $candidateName = trim((string)($oRow['owner_name'] ?? ''));
                        if ($candidateName === '' || strcasecmp($candidateName, 'Reserved') === 0 || strcasecmp($candidateName, 'Unknown') === 0) {
                            $candidateName = trim(implode(' ', array_filter([$resFirst, $resMiddle, $resLast, $resSuffix])));
                        }
                        if ($candidateName !== '' && strcasecmp($candidateName, 'Reserved') !== 0 && strcasecmp($candidateName, 'Unknown') !== 0) {
                            $status = 'Reserved';
                            $ownerName = $candidateName;
                        }
                    }
                }

                // 2. Check tbl_bookings
                if ($ownerName === '' || strcasecmp($ownerName, 'Reserved') === 0) {
                    $bkgTbl = @$conn->query("SHOW TABLES LIKE 'tbl_bookings'");
                    if ($bkgTbl && $bkgTbl->num_rows > 0) {
                        $bkgSql = "SELECT b.id, b.customer_name, b.customer_email, b.full_name, b.contact_number, b.booker_location, b.user_id, b.status,
                                          u.first_name, u.middle_name, u.last_name, u.suffix, u.phone_no, u.email
                                   FROM tbl_bookings b
                                   LEFT JOIN tbl_user u ON b.user_id = u.user_id
                                   WHERE (REPLACE(UPPER(TRIM(b.section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(b.section)) = '{$escSec}' OR b.plot LIKE '%{$escSec}%' OR b.grave_location LIKE '%{$escSec}%' OR b.section_plot LIKE '%{$escSec}%')
                                     AND (
                                       b.plot IN ({$inEscaped})
                                       OR b.grave_location IN ({$inEscaped})
                                       OR b.section_plot IN ({$inEscaped})
                                       OR (b.plot REGEXP '(^|[^0-9])({$plot_x}[,-]{$plot_y})([^0-9]|$)')
                                       OR (b.grave_location REGEXP '(^|[^0-9])({$plot_x}[,-]{$plot_y})([^0-9]|$)')
                                       OR (b.section_plot REGEXP '(^|[^0-9])({$plot_x}[,-]{$plot_y})([^0-9]|$)')
                                       OR b.plot_id IN (SELECT plot_id FROM tbl_plots WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}') AND plot_x = {$plot_x} AND plot_y = {$plot_y})
                                       OR b.id IN (SELECT bp.booking_id FROM booking_plots bp WHERE (REPLACE(UPPER(TRIM(bp.section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(bp.section)) = '{$escSec}') AND bp.plot_x = {$plot_x} AND bp.plot_y = {$plot_y})
                                     )
                                     AND LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved', 'completed')
                                   ORDER BY CASE WHEN LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved') THEN 1 ELSE 2 END, b.id DESC LIMIT 1";
                        $bkgRes = @$conn->query($bkgSql);
                        if ($bkgRes && ($bRow = $bkgRes->fetch_assoc())) {
                            $resBookingId = (int)$bRow['id'];
                            $resUserId = (int)($bRow['user_id'] ?? 0);
                            $ownerPhone = $bRow['contact_number'] ?: ($bRow['phone_no'] ?? '');
                            $ownerAddress = $bRow['booker_location'] ?? '';
                            $resFirst = $bRow['first_name'] ?? '';
                            $resMiddle = $bRow['middle_name'] ?? '';
                            $resLast = $bRow['last_name'] ?? '';
                            $resSuffix = $bRow['suffix'] ?? '';

                            $bkgName = trim((string)($bRow['customer_name'] ?? ''));
                            if ($bkgName === '' || strcasecmp($bkgName, 'Reserved') === 0) {
                                $bkgName = trim((string)($bRow['full_name'] ?? ''));
                            }
                            if ($bkgName === '' || strcasecmp($bkgName, 'Reserved') === 0) {
                                $bkgName = trim(implode(' ', array_filter([$resFirst, $resMiddle, $resLast, $resSuffix])));
                            }
                            if (($bkgName === '' || strcasecmp($bkgName, 'Reserved') === 0) && $resUserId > 0) {
                                $uQ = @$conn->query("SELECT first_name, middle_name, last_name, suffix, phone_no FROM tbl_user WHERE user_id = {$resUserId} LIMIT 1");
                                if ($uQ && ($uData = $uQ->fetch_assoc())) {
                                    $bkgName = trim(implode(' ', array_filter([$uData['first_name'] ?? '', $uData['middle_name'] ?? '', $uData['last_name'] ?? '', $uData['suffix'] ?? ''])));
                                    if (empty($ownerPhone)) { $ownerPhone = $uData['phone_no'] ?? ''; }
                                }
                            }
                            if ($bkgName !== '' && strcasecmp($bkgName, 'Reserved') !== 0 && strcasecmp($bkgName, 'Unknown') !== 0) {
                                $status = 'Reserved';
                                $ownerName = $bkgName;
                            }
                        }
                    }
                }

                // 3. Check user_plot_ownership
                if ($ownerName === '' || strcasecmp($ownerName, 'Reserved') === 0) {
                    if (function_exists('ensure_user_plot_ownership_table')) {
                        ensure_user_plot_ownership_table($conn);
                    }
                    $chkUpo = @$conn->query("SHOW TABLES LIKE 'user_plot_ownership'");
                    if ($chkUpo && $chkUpo->num_rows > 0) {
                        $upoSql = "SELECT upo.id, upo.user_id, upo.ownership_status, upo.deceased_reference, u.first_name, u.middle_name, u.last_name, u.suffix, u.phone_no, u.email 
                                   FROM user_plot_ownership upo 
                                   LEFT JOIN tbl_user u ON upo.user_id = u.user_id 
                                   WHERE (REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(upo.section)) = '{$escSec}') 
                                     AND upo.plot_x = {$plot_x} AND upo.plot_y = {$plot_y} 
                                     AND upo.verification_status = 'VERIFIED'
                                     AND LOWER(TRIM(COALESCE(upo.ownership_status, ''))) NOT IN ('cancelled', 'rejected')
                                   ORDER BY upo.id DESC 
                                   LIMIT 1";
                        $upoRes = @$conn->query($upoSql);
                        if ($upoRes && ($uRow = $upoRes->fetch_assoc())) {
                            $resUserId = (int)$uRow['user_id'];
                            $resFirst = $uRow['first_name'] ?? '';
                            $resMiddle = $uRow['middle_name'] ?? '';
                            $resLast = $uRow['last_name'] ?? '';
                            $resSuffix = $uRow['suffix'] ?? '';
                            $ownerPhone = $uRow['phone_no'] ?? '';
                            $candidateName = trim(implode(' ', array_filter([$resFirst, $resMiddle, $resLast, $resSuffix])));
                            if ($candidateName !== '' && strcasecmp($candidateName, 'Reserved') !== 0 && strcasecmp($candidateName, 'Unknown') !== 0) {
                                $status = ($uRow['ownership_status'] === 'RESERVED') ? 'Reserved' : (($uRow['ownership_status'] === 'PURCHASED') ? 'Purchased' : 'Owned');
                                $ownerName = $candidateName;
                            }
                        }
                    }
                }

                // 4. Check guest_reservation_requests
                if ($ownerName === '' || strcasecmp($ownerName, 'Reserved') === 0) {
                    $grrTbl = 'guest_reservation_requests';
                    $chkGrr = @$conn->query("SHOW TABLES LIKE 'guest_reservation_requests'");
                    if (!$chkGrr || $chkGrr->num_rows === 0) {
                        $chkGrr = @$conn->query("SHOW TABLES LIKE 'tbl_guest_reservation_requests'");
                        $grrTbl = 'tbl_guest_reservation_requests';
                    }
                    if ($chkGrr && $chkGrr->num_rows > 0) {
                        $grrSql = "SELECT id, full_name, email, contact_number, section, plot_x, plot_y, user_id, booking_id, status 
                                   FROM {$grrTbl} 
                                   WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}') 
                                     AND plot_x = {$plot_x} AND plot_y = {$plot_y} 
                                     AND LOWER(TRIM(status)) IN ('approved', 'confirmed', 'account created') 
                                   ORDER BY id DESC LIMIT 1";
                        $grrRes = @$conn->query($grrSql);
                        if ($grrRes && ($grRow = $grrRes->fetch_assoc())) {
                            $candidateName = trim((string)($grRow['full_name'] ?? ''));
                            if ($candidateName !== '' && strcasecmp($candidateName, 'Reserved') !== 0 && strcasecmp($candidateName, 'Unknown') !== 0) {
                                $status = 'Reserved';
                                $ownerName = $candidateName;
                                $ownerPhone = trim((string)($grRow['contact_number'] ?? ''));
                                $resUserId = (int)($grRow['user_id'] ?? 0);
                                $resBookingId = (int)($grRow['booking_id'] ?? 0);
                            }
                        }
                    }
                }

                $validOwnerName = trim((string)$ownerName);
                $hasRealOwner = !empty($validOwnerName) && strcasecmp($validOwnerName, 'Reserved') !== 0 && strcasecmp($validOwnerName, 'Unknown') !== 0 && strcasecmp($validOwnerName, 'N/A') !== 0;
                $hasRealBooking = (!empty($resBookingId) && $resBookingId > 0) || (!empty($resUserId) && $resUserId > 0);

                if (($status === 'Reserved' || $status === 'Owned' || $status === 'Purchased') && ($hasRealOwner || $hasRealBooking)) {
                    if (!$hasRealOwner) {
                        $validOwnerName = ($status === 'Purchased' ? 'Purchased' : 'Reserved');
                    }
                    if ($status !== 'Owned' && $status !== 'Purchased') {
                        $status = 'Reserved';
                    }
                    $reservation = [
                        'owner_name' => $validOwnerName,
                        'first_name' => $resFirst,
                        'middle_name' => $resMiddle,
                        'last_name' => $resLast,
                        'suffix' => $resSuffix,
                        'bday' => $resBday,
                        'phone_no' => $ownerPhone,
                        'address' => $ownerAddress,
                        'user_id' => $resUserId,
                        'booking_id' => $resBookingId
                    ];
                } else {
                    // Stale or ghost reservation: auto-heal to available
                    if ($plotStatus === 'Reserved' || $plotStatus === 'Purchased') {
                        @$conn->query("UPDATE tbl_plots SET status = 'available' WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}') AND plot_x = {$plot_x} AND plot_y = {$plot_y} AND LOWER(status) != 'occupied'");
                        $plotStatus = 'Available';
                    }
                    $status = 'Available';
                    $reservation = null;
                }
            }
        }

        $isActualPurchased = ($status === 'Purchased' || $status === 'Owned');
        $resolved_booker_name = '';
        $resolved_payment_plan = $isActualPurchased ? 'Cash' : 'Reservation';
        $resolved_payment_term = 0;
        $resolved_payment_status = $isActualPurchased ? 'PAID' : 'PENDING';
        $resolved_total_amount = $total_price;
        $resolved_amount_paid = 0.00;
        $resolved_monthly_due = 0.00;

        if (!empty($deceased)) {
            foreach ($deceased as $decRow) {
                if (!empty($decRow['booker_name'])) {
                    $resolved_booker_name = $decRow['booker_name'];
                }
                if (!empty($decRow['payment_plan'])) {
                    $resolved_payment_plan = $decRow['payment_plan'];
                }
                if (!empty($decRow['payment_term'])) {
                    $resolved_payment_term = (int)$decRow['payment_term'];
                }
                if (!empty($decRow['payment_status'])) {
                    $resolved_payment_status = $decRow['payment_status'];
                }
                if (!empty($decRow['total_amount']) && (float)$decRow['total_amount'] >= 10000) {
                    $resolved_total_amount = (float)$decRow['total_amount'];
                }
                if (isset($decRow['amount_paid']) && $decRow['amount_paid'] !== null && (float)$decRow['amount_paid'] >= 10000) {
                    $resolved_amount_paid = (float)$decRow['amount_paid'];
                }
                if (!empty($decRow['monthly_due']) && (float)$decRow['monthly_due'] > 0) {
                    $resolved_monthly_due = (float)$decRow['monthly_due'];
                }
                if (!empty($resolved_booker_name) && $resolved_total_amount >= 10000) break;
            }
        }

        if (empty($resolved_booker_name) && !empty($deceased)) {
            foreach ($deceased as $decRow) {
                $decUid = (int)($decRow['user_id'] ?? 0);
                if ($decUid > 0) {
                    $uStmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix FROM tbl_user WHERE user_id = ? LIMIT 1");
                    if ($uStmt) {
                        $uStmt->bind_param('i', $decUid);
                        $uStmt->execute();
                        $uRes = $uStmt->get_result();
                        if ($uRes && ($uRow = $uRes->fetch_assoc())) {
                            $resolved_booker_name = trim(implode(' ', array_filter([$uRow['first_name'] ?? '', $uRow['middle_name'] ?? '', $uRow['last_name'] ?? '', $uRow['suffix'] ?? ''])));
                        }
                        $uStmt->close();
                    }
                }
                if (!empty($resolved_booker_name)) break;
            }
        }

        if (empty($resolved_booker_name)) {
            $upoStmt = $conn->prepare("SELECT u.first_name, u.middle_name, u.last_name, u.suffix FROM user_plot_ownership upo JOIN tbl_user u ON u.user_id = upo.user_id WHERE UPPER(TRIM(upo.section)) = ? AND upo.plot_x = ? AND upo.plot_y = ? ORDER BY upo.id DESC LIMIT 1");
            if ($upoStmt) {
                $upoStmt->bind_param('sii', $section, $plot_x, $plot_y);
                $upoStmt->execute();
                $upoRes = $upoStmt->get_result();
                if ($upoRes && ($upoRow = $upoRes->fetch_assoc())) {
                    $resolved_booker_name = trim(implode(' ', array_filter([$upoRow['first_name'] ?? '', $upoRow['middle_name'] ?? '', $upoRow['last_name'] ?? '', $upoRow['suffix'] ?? ''])));
                }
                $upoStmt->close();
            }
        }

        if (empty($resolved_booker_name)) {
            $poStmt = $conn->prepare("SELECT owner_name, first_name, middle_name, last_name, suffix FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND active = 1 ORDER BY id DESC LIMIT 1");
            if ($poStmt) {
                $poStmt->bind_param('sii', $section, $plot_x, $plot_y);
                $poStmt->execute();
                $poRes = $poStmt->get_result();
                if ($poRes && ($poRow = $poRes->fetch_assoc())) {
                    $ownerVal = trim((string)($poRow['owner_name'] ?? ''));
                    if ($ownerVal === '' || strcasecmp($ownerVal, 'Reserved') === 0) {
                        $ownerVal = trim(implode(' ', array_filter([$poRow['first_name'] ?? '', $poRow['middle_name'] ?? '', $poRow['last_name'] ?? '', $poRow['suffix'] ?? ''])));
                    }
                    if ($ownerVal !== '' && strcasecmp($ownerVal, 'Reserved') !== 0) {
                        $resolved_booker_name = $ownerVal;
                    }
                }
                $poStmt->close();
            }
        }

        $plotPad6 = sprintf('%03d%03d', $plot_x, $plot_y);
        $plotSecPad6 = $section . '-' . $plotPad6;
        $plotHyphen = (string)$plot_x . '-' . (string)$plot_y;
        $plotSecHyphen = $section . '-' . $plotHyphen;
        $plotSpace = $section . ' ' . $plotHyphen;
        $plotComma1 = $section . ' ' . $plot_x . ',' . $plot_y;
        $plotComma2 = $section . ' ' . $plot_x . ', ' . $plot_y;
        $plotComma3 = $section . '- ' . $plot_x . ',' . $plot_y;
        $plotComma4 = $section . '- ' . $plot_x . ', ' . $plot_y;
        $plotComma5 = (string)$plot_x . ',' . (string)$plot_y;
        $plotComma6 = (string)$plot_x . ', ' . (string)$plot_y;

        $bkgCheckSql = "SELECT b.*, u.first_name, u.last_name FROM tbl_bookings b LEFT JOIN tbl_user u ON u.user_id = b.user_id 
            WHERE UPPER(TRIM(b.section)) = ? 
              AND (b.plot IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) OR b.grave_location IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) OR b.id IN (SELECT bp.booking_id FROM booking_plots bp WHERE UPPER(TRIM(bp.section)) = '" . $conn->real_escape_string($section) . "' AND bp.plot_x = " . (int)$plot_x . " AND bp.plot_y = " . (int)$plot_y . ")) 
              AND LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved', 'completed') 
              AND LOWER(TRIM(COALESCE(b.service_type, ''))) NOT IN ('cleaning', 'landscaping', 'maintenance', 'decoration')
              AND LOWER(TRIM(COALESCE(b.purpose, ''))) != 'cemetery_service'
              AND b.total_amount >= 10000
            ORDER BY CASE WHEN LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved') THEN 1 ELSE 2 END, 
                     CASE WHEN b.total_amount >= 50000 THEN 1 
                          WHEN LOWER(TRIM(COALESCE(b.booking_type, ''))) IN ('plot_purchase', 'another_plot', 'guest_reservation') THEN 2 
                          WHEN LOWER(TRIM(COALESCE(b.purpose, ''))) NOT IN ('cemetery_service', 'service') THEN 3 
                          ELSE 4 END ASC, 
                     b.id DESC LIMIT 1";
        if ($bkgCheckStmt = $conn->prepare($bkgCheckSql)) {
            $bkgCheckStmt->bind_param('sssssssssssssssssssssss',
                $section,
                $plotPad6, $plotSecPad6, $plotHyphen, $plotSecHyphen, $plotSpace, $plotComma1, $plotComma2, $plotComma3, $plotComma4, $plotComma5, $plotComma6,
                $plotPad6, $plotSecPad6, $plotHyphen, $plotSecHyphen, $plotSpace, $plotComma1, $plotComma2, $plotComma3, $plotComma4, $plotComma5, $plotComma6
            );
            $bkgCheckStmt->execute();
            $bkgCheckRes = $bkgCheckStmt->get_result();
            if ($bkgCheckRes && ($bkData = $bkgCheckRes->fetch_assoc())) {
                if (empty($resolved_booker_name)) {
                    $resolved_booker_name = trim($bkData['customer_name'] ?: ($bkData['full_name'] ?: ''));
                }
                if (!empty($bkData['payment_plan'])) {
                    $resolved_payment_plan = ucfirst(strtolower($bkData['payment_plan']));
                }
                if ($resolved_payment_term <= 0 && !empty($bkData['payment_term'])) {
                    $resolved_payment_term = (int)$bkData['payment_term'];
                }
                $bkTotalAmt = !empty($bkData['total_amount']) ? (float)$bkData['total_amount'] : 0.0;
                $bkAmtPaid = !empty($bkData['amount_paid']) ? (float)$bkData['amount_paid'] : 0.0;

                if ($bkTotalAmt >= 10000) {
                    $resolved_total_amount = $bkTotalAmt;
                    $resolved_amount_paid = $bkAmtPaid;
                }
            }
            $bkgCheckStmt->close();
        }

        if (empty($resolved_booker_name) && !empty($reservation['owner_name'])) {
            $resolved_booker_name = $reservation['owner_name'];
        }

        if ($resolved_total_amount < 10000) {
            $resolved_total_amount = ($total_price >= 10000) ? $total_price : ($price >= 10000 ? $price : 85000.00);
        }

        if ($isActualPurchased) {
            if (($resolved_payment_status === 'PAID' || in_array(strtolower(trim((string)$resolved_payment_plan)), ['cash', 'full', 'full payment', 'full_payment'])) && ($resolved_amount_paid <= 0 || $resolved_amount_paid < $resolved_total_amount)) {
                $resolved_amount_paid = $resolved_total_amount;
            }
            $resolved_balance = max(0, $resolved_total_amount - $resolved_amount_paid);
            if ($resolved_total_amount > 0 && $resolved_amount_paid >= $resolved_total_amount) {
                $resolved_payment_status = 'PAID';
            } elseif ($resolved_balance > 0) {
                $resolved_payment_status = 'HAS DUES';
            }
        } else {
            // For a RESERVED plot: Do not fabricate a full payment.
            $resolved_balance = max(0, $resolved_total_amount - $resolved_amount_paid);
            if ($resolved_total_amount > 0 && $resolved_amount_paid >= $resolved_total_amount) {
                $resolved_payment_status = 'PAID';
                if ($status !== 'Occupied' && empty($deceased) && $occupant_count <= 0) {
                    if (!empty($reservation['owner_name']) && $reservation['owner_name'] !== 'Reserved' && $reservation['owner_name'] !== 'Unknown') {
                        $status = 'Purchased';
                    }
                }
            } else {
                $resolved_payment_status = ($resolved_amount_paid > 0) ? 'DEPOSIT PAID' : 'PENDING';
            }
        }
        if ($resolved_payment_plan === 'Installment' && $resolved_payment_term > 0 && $resolved_monthly_due <= 0) {
            $resolved_monthly_due = round($resolved_total_amount / $resolved_payment_term, 2);
        }

        $payment_details = [
            'booker_name' => $resolved_booker_name ?: '',
            'payment_plan' => $resolved_payment_plan ?: 'Cash',
            'payment_term' => $resolved_payment_term,
            'payment_term_label' => ($resolved_payment_plan === 'Installment' && $resolved_payment_term > 0) ? ($resolved_payment_term . ' Months') : 'Full Payment',
            'total_amount' => $resolved_total_amount,
            'amount_paid' => $resolved_amount_paid,
            'balance' => $resolved_balance,
            'payment_status' => $resolved_payment_status,
            'monthly_due' => $resolved_monthly_due,
            'is_paid' => ($resolved_payment_status === 'PAID'),
            'has_dues' => ($resolved_payment_status === 'HAS DUES')
        ];

        $curr_user_id = (int)($_SESSION['user_id'] ?? 0);
        $curr_role = strtolower(trim((string)($_SESSION['role'] ?? ($_SESSION['user_type'] ?? ''))));
        $is_admin = (!empty($isAdmin))
            || ($curr_user_id > 0 && ($curr_role === 'admin' || $curr_role === 'administrator'))
            || (strtoupper(trim((string)($_SESSION['role'] ?? ''))) === 'ADMIN');

        $is_user_authorized = false;
        if ($is_admin) {
            $is_user_authorized = true;
        } elseif ($curr_user_id > 0) {
            if (function_exists('is_user_authorized_for_plot') && is_user_authorized_for_plot($conn, $curr_user_id, $section, $plot_x, $plot_y)) {
                $is_user_authorized = true;
            } elseif (!empty($deceased)) {
                foreach ($deceased as $decItem) {
                    $dId = (int)($decItem['deceased_id'] ?? 0);
                    if ($dId > 0 && function_exists('is_user_authorized_for_deceased') && is_user_authorized_for_deceased($conn, $curr_user_id, $dId)) {
                        $is_user_authorized = true;
                        break;
                    }
                    if ((int)($decItem['user_id'] ?? 0) === $curr_user_id) {
                        $is_user_authorized = true;
                        break;
                    }
                }
            }
            if (!$is_user_authorized && $conn->query("SHOW TABLES LIKE 'user_plot_ownership'")->num_rows > 0) {
                if ($uChk = $conn->prepare("SELECT id FROM user_plot_ownership WHERE user_id = ? AND UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND (verification_status IS NULL OR verification_status != 'REJECTED') LIMIT 1")) {
                    $uChk->bind_param('isii', $curr_user_id, $section, $plot_x, $plot_y);
                    $uChk->execute();
                    if ($uChk->get_result()->num_rows > 0) {
                        $is_user_authorized = true;
                    }
                    $uChk->close();
                }
            }
            if (!$is_user_authorized && $conn->query("SHOW TABLES LIKE 'tbl_bookings'")) {
                $plotPad6 = sprintf('%03d%03d', $plot_x, $plot_y);
                $plotHyphen = (string)$plot_x . '-' . (string)$plot_y;
                $likeStr = '%' . $plotHyphen . '%';
                if ($bCheck = $conn->prepare("SELECT id FROM tbl_bookings WHERE user_id = ? AND UPPER(TRIM(section)) = ? AND (plot = ? OR plot = ? OR grave_location LIKE ?) AND LOWER(status) NOT IN ('cancelled', 'rejected') LIMIT 1")) {
                    $bCheck->bind_param('issss', $curr_user_id, $section, $plotHyphen, $plotPad6, $likeStr);
                    $bCheck->execute();
                    $bRes = $bCheck->get_result();
                    if ($bRes && $bRes->num_rows > 0) {
                        $is_user_authorized = true;
                    }
                    $bCheck->close();
                }
            }
            if (!$is_user_authorized && $conn->query("SHOW TABLES LIKE 'booking_plots'")->num_rows > 0) {
                if ($bpCheck = $conn->prepare("SELECT bp.id FROM booking_plots bp JOIN tbl_bookings b ON b.id = bp.booking_id WHERE b.user_id = ? AND UPPER(TRIM(bp.section)) = ? AND bp.plot_x = ? AND bp.plot_y = ? AND LOWER(b.status) NOT IN ('cancelled', 'rejected') LIMIT 1")) {
                    $bpCheck->bind_param('isii', $curr_user_id, $section, $plot_x, $plot_y);
                    $bpCheck->execute();
                    if ($bpCheck->get_result()->num_rows > 0) {
                        $is_user_authorized = true;
                    }
                    $bpCheck->close();
                }
            }
            if (!$is_user_authorized && ($resUserId > 0 && $resUserId === $curr_user_id)) {
                $is_user_authorized = true;
            }
        }

        if (!$is_user_authorized && !$is_admin) {
            if ($reservation) {
                $rawPhone = trim((string)($reservation['phone_no'] ?? ''));
                $pLen = strlen($rawPhone);
                $maskedPhone = ($pLen > 4) ? (substr($rawPhone, 0, 2) . str_repeat('*', max(4, $pLen - 4)) . substr($rawPhone, -2)) : ($rawPhone !== '' ? '••••' : '');
                $reservation = [
                    'owner_name' => $reservation['owner_name'],
                    'first_name' => $reservation['first_name'],
                    'middle_name' => '',
                    'last_name' => $reservation['last_name'],
                    'suffix' => $reservation['suffix'],
                    'bday' => '',
                    'phone_no' => $maskedPhone,
                    'address' => '',
                    'user_id' => 0,
                    'booking_id' => 0,
                    'is_private' => false
                ];
            }
            if (!empty($deceased)) {
                foreach ($deceased as &$decRow) {
                    $decRow['booker_name'] = $decRow['booker_name'] ?? '';
                    $decRow['payment_plan'] = '';
                    $decRow['payment_term'] = 0;
                    $decRow['payment_status'] = '';
                    $decRow['total_amount'] = 0;
                    $decRow['amount_paid'] = 0;
                    $decRow['monthly_due'] = 0;
                    $rawDecContact = trim((string)($decRow['contact_no'] ?? ''));
                    $decLen = strlen($rawDecContact);
                    $decRow['contact_no'] = ($decLen > 4) ? (substr($rawDecContact, 0, 2) . str_repeat('*', max(4, $decLen - 4)) . substr($rawDecContact, -2)) : ($rawDecContact !== '' ? '••••' : '—');
                    $decRow['user_id'] = 0;
                }
                unset($decRow);
            }
            $payment_details = null;
        }

        if (!empty($deceased) || $occupant_count > 0) {
            $status = 'Occupied';
        } else {
            if ($status !== 'Purchased' && $status !== 'Reserved' && $status !== 'Owned') {
                $status = 'Available';
                @$conn->query("UPDATE tbl_plots SET status = 'available' WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}') AND ((plot_x = {$plot_x} AND plot_y = {$plot_y}) OR (plot_x = {$plot_y} AND plot_y = {$plot_x})) AND LOWER(status) IN ('occupied', 'taken', 'unavailable')");
                if (($chkPlotsCheck = @$conn->query("SHOW TABLES LIKE 'plots'")) && $chkPlotsCheck->num_rows > 0) {
                    @$conn->query("UPDATE plots SET status = 'Available' WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$escSec}' OR UPPER(TRIM(section)) = '{$escSec}') AND ((plot_x = {$plot_x} AND plot_y = {$plot_y}) OR (plot_x = {$plot_y} AND plot_y = {$plot_x})) AND LOWER(status) IN ('occupied', 'taken', 'unavailable')");
                }
            }
        }

        @ob_clean();
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'status' => $status,
            'section' => $section,
            'plot_id' => $plotId,
            'row' => $plot_x,
            'plot_number' => $plot_y,
            'plot_x' => $plot_x,
            'plot_y' => $plot_y,
            'latitude' => $plotLat,
            'longitude' => $plotLng,
            'has_coordinates' => ($plotLat !== null && $plotLng !== null && $plotLat != 0.0 && $plotLng != 0.0),
            'plot_no' => $section . ' · Row ' . $plot_x . ' · Plot ' . $plot_y,
            'plot_type' => 'Lawn',
            'lawn_type' => $lawn_type,
            'lot_price' => $price,
            'price' => $price,
            'interment_fee' => $interment_fee,
            'total_price' => $total_price,
            'reservation_deposit' => $price * 0.05,
            'amortization' => $official['amortization'] ?? [],
            'occupant_count' => $occupant_count,
            'max_occupants' => $max_occupants,
            'deceased' => $deceased,
            'deceased_name' => $deceased_name,
            'reservation' => $reservation,
            'payment_details' => $payment_details,
            'can_view_payment' => ($is_admin || $is_user_authorized),
            'is_user_authorized' => $is_user_authorized,
            'is_admin' => $is_admin
        ]);
    } catch (Exception $e) {
        error_log('popup.php get_plot_booking_status error: ' . $e->getMessage());
        @ob_clean();
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    } finally {
        if (isset($conn)) { $conn->close(); }
    }
    exit;
}

if ((isset($_REQUEST['ajax_request']) && $_REQUEST['ajax_request'] === 'get_plot_navigation') || (isset($_REQUEST['ajax']) && $_REQUEST['ajax'] === 'get_plot_navigation')) {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!@include_once $connPath) {
        http_response_code(500);
        echo json_encode(['success' => false, 'has_coordinates' => false, 'message' => 'Database connection is unavailable.']);
        exit;
    }
    if (!isset($conn) || !($conn instanceof mysqli)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'has_coordinates' => false, 'message' => 'Database connection is unavailable.']);
        exit;
    }
    if (function_exists('ensure_plot_coordinates_columns')) {
        ensure_plot_coordinates_columns($conn);
    }

    $plot_id = intval($_REQUEST['plot_id'] ?? 0);
    $section = strtoupper(trim((string)($_REQUEST['section'] ?? '')));
    $plot_x = intval($_REQUEST['plot_x'] ?? 0);
    $plot_y = intval($_REQUEST['plot_y'] ?? 0);

    $plotRow = null;
    if ($plot_id > 0) {
        $pStmt = $conn->prepare("SELECT plot_id, section, `row`, plot_number, plot_x, plot_y, latitude, longitude FROM tbl_plots WHERE plot_id = ? LIMIT 1");
        if ($pStmt) {
            $pStmt->bind_param('i', $plot_id);
            $pStmt->execute();
            $plotRow = $pStmt->get_result()->fetch_assoc();
            $pStmt->close();
        }
    }

    if (!$plotRow && $section !== '' && $plot_x > 0 && $plot_y > 0) {
        $pStmt = $conn->prepare("SELECT plot_id, section, `row`, plot_number, plot_x, plot_y, latitude, longitude FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
        if ($pStmt) {
            $pStmt->bind_param('sii', $section, $plot_x, $plot_y);
            $pStmt->execute();
            $plotRow = $pStmt->get_result()->fetch_assoc();
            $pStmt->close();
        }
    }

    if (!$plotRow && $section !== '' && $plot_x > 0 && $plot_y > 0) {
        $checkPlots = @$conn->query("SHOW TABLES LIKE 'plots'");
        if ($checkPlots && $checkPlots->num_rows > 0) {
            $chkCols = @$conn->query("SHOW COLUMNS FROM `plots` LIKE 'latitude'");
            if ($chkCols && $chkCols->num_rows > 0) {
                $p2Stmt = $conn->prepare("SELECT id AS plot_id, section, plot_x, plot_y, latitude, longitude FROM plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
                if ($p2Stmt) {
                    $p2Stmt->bind_param('sii', $section, $plot_x, $plot_y);
                    $p2Stmt->execute();
                    $plotRow = $p2Stmt->get_result()->fetch_assoc();
                    $p2Stmt->close();
                }
            }
        }
    }

    if (!$plotRow && $section !== '' && $plot_x > 0 && $plot_y > 0) {
        $plotRow = [
            'plot_id' => 0,
            'section' => $section,
            'row' => $plot_y,
            'plot_number' => $plot_x,
            'plot_x' => $plot_x,
            'plot_y' => $plot_y,
            'latitude' => null,
            'longitude' => null
        ];
    }

    if (!$plotRow) {
        echo json_encode([
            'success' => false,
            'has_coordinates' => false,
            'message' => 'Coordinates could not be resolved for this plot.'
        ]);
        exit;
    }

    $lat = ($plotRow['latitude'] !== null && $plotRow['latitude'] !== '') ? (float)$plotRow['latitude'] : null;
    $lng = ($plotRow['longitude'] !== null && $plotRow['longitude'] !== '') ? (float)$plotRow['longitude'] : null;
    $coordSource = strtolower(trim((string)($plotRow['coordinate_source'] ?? '')));
    $isManual = ($coordSource === 'manual' && $lat !== null && $lng !== null && $lat != 0.0 && $lng != 0.0);
    $hasCoords = false;
    $coordAccuracy = !empty($plotRow['coordinate_accuracy']) ? $plotRow['coordinate_accuracy'] : 'calculated layout';
    $calibMessage = 'Coordinates could not be resolved for this plot.';

    if ($isManual) {
        $hasCoords = true;
        $coordAccuracy = 'manual GPS override';
    } else {

        $calcServicePath = file_exists(__DIR__ . '/../includes/gps_calculator.php') 
            ? (__DIR__ . '/../includes/gps_calculator.php') 
            : (file_exists(__DIR__ . '/includes/gps_calculator.php') ? (__DIR__ . '/includes/gps_calculator.php') : null);
        if ($calcServicePath && file_exists($calcServicePath)) {
            require_once $calcServicePath;
        }

        if (class_exists('CemeteryGPSCalculator')) {
            $calcRes = CemeteryGPSCalculator::calculatePlotGPS(
                $conn,
                (int)($plotRow['plot_id'] ?? 0),
                $plotRow['section'],
                (int)$plotRow['plot_x'],
                (int)$plotRow['plot_y'],
                true
            );
            if (!empty($calcRes['success']) && !empty($calcRes['has_coordinates'])) {
                $lat = (float)$calcRes['latitude'];
                $lng = (float)$calcRes['longitude'];
                $hasCoords = true;
                $coordSource = 'calculated';
                $coordAccuracy = $calcRes['coordinate_accuracy'] ?? 'calibrated layout';
            } elseif (!empty($calcRes['message'])) {
                $calibMessage = $calcRes['message'];
            }
        }

        if (!$hasCoords && class_exists('PlotSpatialLookup')) {
            $spatialGps = PlotSpatialLookup::getPlotGPS($plotRow['section'], (int)$plotRow['plot_x'], (int)$plotRow['plot_y']);
            if (!empty($spatialGps['latitude']) && !empty($spatialGps['longitude'])) {
                $lat = (float)$spatialGps['latitude'];
                $lng = (float)$spatialGps['longitude'];
                $hasCoords = true;
                $coordSource = 'spatial_lookup';
                $coordAccuracy = 'spatial grid alignment';
            }
        }
    }

    $spatialServicePath = file_exists(__DIR__ . '/../includes/PlotSpatialLookup.php') 
        ? (__DIR__ . '/../includes/PlotSpatialLookup.php') 
        : (file_exists(__DIR__ . '/includes/PlotSpatialLookup.php') ? (__DIR__ . '/includes/PlotSpatialLookup.php') : null);
    if ($spatialServicePath && file_exists($spatialServicePath)) {
        require_once $spatialServicePath;
    }

    $manualPlot = null;
    if (class_exists('PlotSpatialLookup') && $section !== '' && $plot_x > 0 && $plot_y > 0) {
        $manualPlot = PlotSpatialLookup::getCoordinates($section, $plot_x, $plot_y);
    }

    $displayRow = $manualPlot ? (int)$manualPlot['plot_x'] : ($plot_x > 0 ? (int)$plot_x : (int)($plotRow['row'] ?? 1));
    $displayPlotNum = $manualPlot ? (int)$manualPlot['plot_y'] : ($plot_y > 0 ? (int)$plot_y : (int)($plotRow['plot_number'] ?? 1));
    $plotName = "Section {$section} · Row {$displayRow} · Plot {$displayPlotNum}";

    $basePath = (basename(__DIR__) === 'sections') ? '../' : '';
    $viewOnMapUrl = "{$basePath}sections/popup.php?section=" . urlencode($plotRow['section'] ?? $section) . "&plot_id=" . intval($plotRow['plot_id'] ?? 0) . "&plot_x=" . intval($plot_x) . "&plot_y=" . intval($plot_y) . "&auto_open=1";

    if ($hasCoords && $lat !== null && $lng !== null) {
        $navUrl = "https://www.google.com/maps?q=loc:{$lat},{$lng}&t=k&z=20";
        $dirUrl = "https://www.google.com/maps/dir/?api=1&destination={$lat},{$lng}&travelmode=walking";
        echo json_encode([
            'success' => true,
            'has_coordinates' => true,
            'plot_id' => (int)($plotRow['plot_id'] ?? 0),
            'section' => $section,
            'row' => $displayRow,
            'plot_number' => $displayPlotNum,
            'plot_x' => (int)$plot_x,
            'plot_y' => (int)$plot_y,
            'plot_name' => $plotName,
            'latitude' => $lat,
            'longitude' => $lng,
            'coordinate_source' => $coordSource,
            'coordinate_accuracy' => $coordAccuracy,
            'navigation_url' => $navUrl,
            'directions_url' => $dirUrl,
            'view_on_map_url' => $viewOnMapUrl
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'has_coordinates' => false,
            'plot_id' => (int)($plotRow['plot_id'] ?? 0),
            'section' => $section,
            'row' => $displayRow,
            'plot_number' => $displayPlotNum,
            'plot_x' => (int)$plot_x,
            'plot_y' => (int)$plot_y,
            'plot_name' => $plotName,
            'message' => $calibMessage
        ]);
    }
    exit;
}

if ((isset($_REQUEST['ajax_request']) && $_REQUEST['ajax_request'] === 'get_plot_directions') || (isset($_REQUEST['ajax']) && $_REQUEST['ajax'] === 'get_plot_directions')) {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!@include_once $connPath) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection unavailable.']);
        exit;
    }
    $section = strtoupper(trim((string)($_REQUEST['section'] ?? '')));
    $plot_x = intval($_REQUEST['plot_x'] ?? 0);
    $plot_y = intval($_REQUEST['plot_y'] ?? 0);

    if (empty($section) || $plot_x <= 0 || $plot_y <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid section or plot specifications.']);
        exit;
    }

    $spatial = class_exists('PlotSpatialLookup') ? PlotSpatialLookup::getCoordinates($section, $plot_x, $plot_y) : null;

    // Check for deceased record in tbl_deceased
    $deceasedName = '';
    $deceasedId = 0;
    if (isset($conn) && ($conn instanceof mysqli)) {
        $dStmt = $conn->prepare("SELECT deceased_id, CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(middle_name,''), NULLIF(last_name,''), NULLIF(suffix,'')) AS fullname FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
        if ($dStmt) {
            $dStmt->bind_param('sii', $section, $plot_x, $plot_y);
            $dStmt->execute();
            $dRes = $dStmt->get_result();
            if ($dRes && ($dRow = $dRes->fetch_assoc())) {
                $deceasedName = trim((string)($dRow['fullname'] ?? ''));
                $deceasedId = (int)$dRow['deceased_id'];
            }
            $dStmt->close();
        }
    }

    // Resolve GPS coordinates & Google Maps URL
    $gps = null;
    $calcPath = file_exists(__DIR__ . '/../includes/gps_calculator.php') ? (__DIR__ . '/../includes/gps_calculator.php') : (__DIR__ . '/includes/gps_calculator.php');
    if (file_exists($calcPath)) {
        require_once $calcPath;
    }
    if (class_exists('CemeteryGPSCalculator') && isset($conn) && ($conn instanceof mysqli)) {
        $calcRes = CemeteryGPSCalculator::calculatePlotGPS($conn, 0, $section, $plot_x, $plot_y, false);
        if (!empty($calcRes['success']) && !empty($calcRes['has_coordinates'])) {
            $gps = [
                'latitude' => $calcRes['latitude'],
                'longitude' => $calcRes['longitude'],
                'navigation_url' => $calcRes['navigation_url'] ?? '',
                'directions_url' => $calcRes['directions_url'] ?? "https://www.google.com/maps/dir/?api=1&destination={$calcRes['latitude']},{$calcRes['longitude']}&travelmode=walking"
            ];
        }
    }

    if ($spatial) {
        echo json_encode([
            'success' => true,
            'section' => $section,
            'plot_x' => $plot_x,
            'plot_y' => $plot_y,
            'deceased_name' => $deceasedName,
            'deceased_id' => $deceasedId,
            'spatial' => $spatial,
            'gps' => $gps
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => "Spatial coordinates for Section {$section} Plot {$plot_x}-{$plot_y} were not found."
        ]);
    }
    exit;
}

$fromRecently = isset($_GET['from']) && $_GET['from'] === 'recently_clicked';
$isTransferMode = (isset($_GET['selection_mode']) && $_GET['selection_mode'] === 'transfer') || (isset($_GET['transfer_mode']) && $_GET['transfer_mode'] === '1');
$bookingMode = $isTransferMode || (isset($_GET['selection_mode']) && $_GET['selection_mode'] === 'booking') || (isset($_GET['booking_mode']) && $_GET['booking_mode'] === '1');

if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'get_reservation_info') {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    include_once $connPath;
    $cUserId = (int)($_SESSION['user_id'] ?? 0);
    $reqSec = strtoupper(trim((string)($_POST['section'] ?? '')));
    $reqPx = intval($_POST['plot_x'] ?? 0);
    $reqPy = intval($_POST['plot_y'] ?? 0);
    $isAuthorized = $isAdmin || ($cUserId > 0 && function_exists('is_user_authorized_for_plot') && is_user_authorized_for_plot($conn, $cUserId, $reqSec, $reqPx, $reqPy));
    if (!$isAuthorized) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access restricted. You do not have permission to view or manage this reservation.']);
        exit;
    }
    try {
        $required = ['section','plot_x','plot_y'];
        foreach ($required as $r) { if (!isset($_POST[$r]) || trim($_POST[$r])==='') { throw new Exception('Missing field: '.$r); } }
        $section = strtoupper(trim($_POST['section']));
        $plot_x = intval($_POST['plot_x']);
        $plot_y = intval($_POST['plot_y']);

        $row = null;
        $tbl = $conn->query("SHOW TABLES LIKE 'tbl_plot_owners'");
        if ($tbl && $tbl->num_rows > 0) {
            if ($stmt = $conn->prepare("SELECT owner_name, first_name, middle_name, last_name, suffix, bday, phone_no, address FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND active = 1 LIMIT 1")) {
                $stmt->bind_param('sii', $section, $plot_x, $plot_y);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $row = $res->fetch_assoc();
                    $rawName = trim((string)($row['owner_name'] ?? ''));
                    if ($rawName === '' || strcasecmp($rawName, 'Reserved') === 0) {
                        $row['owner_name'] = trim(implode(' ', array_filter([
                            trim((string)($row['first_name'] ?? '')),
                            trim((string)($row['middle_name'] ?? '')),
                            trim((string)($row['last_name'] ?? '')),
                            trim((string)($row['suffix'] ?? ''))
                        ])));
                    }
                    if (strcasecmp($row['owner_name'], 'Reserved') === 0) {
                        $row['owner_name'] = '';
                    }
                }
                $stmt->close();
            }
        }

        if (!$row) {
            $bkgTbl = $conn->query("SHOW TABLES LIKE 'tbl_bookings'");
            if ($bkgTbl && $bkgTbl->num_rows > 0) {
                $plotPad6 = sprintf('%03d%03d', $plot_x, $plot_y);
                $plotSecPad6 = $section . '-' . $plotPad6;
                $plotHyphen = (string)$plot_x . '-' . (string)$plot_y;
                $plotSecHyphen = $section . '-' . $plotHyphen;
                $plotSpace = $section . ' ' . $plotHyphen;
                $plotComma1 = $section . ' ' . $plot_x . ',' . $plot_y;
                $plotComma2 = $section . ' ' . $plot_x . ', ' . $plot_y;
                $plotComma3 = $section . '- ' . $plot_x . ',' . $plot_y;
                $plotComma4 = $section . '- ' . $plot_x . ', ' . $plot_y;
                $plotComma5 = (string)$plot_x . ',' . (string)$plot_y;
                $plotComma6 = (string)$plot_x . ', ' . (string)$plot_y;

                $bkgSql = "SELECT b.id, b.customer_name, b.full_name, b.contact_number, b.booker_location, b.user_id,
                                  u.first_name, u.middle_name, u.last_name, u.suffix, u.phone_no
                           FROM tbl_bookings b
                           LEFT JOIN tbl_user u ON b.user_id = u.user_id
                           WHERE UPPER(TRIM(b.section)) = ?
                             AND (
                               b.plot IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                               OR b.grave_location IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                               OR b.plot_id IN (SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?) OR b.id IN (SELECT bp.booking_id FROM booking_plots bp WHERE UPPER(TRIM(bp.section)) = '" . $conn->real_escape_string($section) . "' AND bp.plot_x = " . (int)$plot_x . " AND bp.plot_y = " . (int)$plot_y . ")
                             )
                             AND LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved', 'completed')
                           ORDER BY CASE WHEN LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved') THEN 1 ELSE 2 END, b.id DESC LIMIT 1";
                if ($bkgStmt = $conn->prepare($bkgSql)) {
                    $bkgStmt->bind_param('ssssssssssssssssssssssssii',
                        $section,
                        $plotPad6, $plotSecPad6, $plotHyphen, $plotSecHyphen, $plotSpace, $plotComma1, $plotComma2, $plotComma3, $plotComma4, $plotComma5, $plotComma6,
                        $plotPad6, $plotSecPad6, $plotHyphen, $plotSecHyphen, $plotSpace, $plotComma1, $plotComma2, $plotComma3, $plotComma4, $plotComma5, $plotComma6,
                        $section, $plot_x, $plot_y
                    );
                    $bkgStmt->execute();
                    $bkgRes = $bkgStmt->get_result();
                    if ($bkgRes && ($bRow = $bkgRes->fetch_assoc())) {
                        $bkgName = trim((string)($bRow['customer_name'] ?? ''));
                        if ($bkgName === '' || strcasecmp($bkgName, 'Reserved') === 0) {
                            $bkgName = trim((string)($bRow['full_name'] ?? ''));
                        }
                        if ($bkgName === '' || strcasecmp($bkgName, 'Reserved') === 0) {
                            $bkgName = trim(implode(' ', array_filter([
                                trim((string)($bRow['first_name'] ?? '')),
                                trim((string)($bRow['middle_name'] ?? '')),
                                trim((string)($bRow['last_name'] ?? '')),
                                trim((string)($bRow['suffix'] ?? ''))
                            ])));
                        }
                        if (strcasecmp($bkgName, 'Reserved') === 0) {
                            $bkgName = '';
                        }
                        $row = [
                            'owner_name' => $bkgName,
                            'first_name' => $bRow['first_name'] ?? '',
                            'middle_name' => $bRow['middle_name'] ?? '',
                            'last_name' => $bRow['last_name'] ?? '',
                            'suffix' => $bRow['suffix'] ?? '',
                            'bday' => '',
                            'phone_no' => $bRow['contact_number'] ?: ($bRow['phone_no'] ?? ''),
                            'address' => $bRow['booker_location'] ?? ''
                        ];
                    }
                    $bkgStmt->close();
                }
            }
        }

        if (!$row && $conn->query("SHOW TABLES LIKE 'user_plot_ownership'")->num_rows > 0) {
            $uStmt = $conn->prepare("SELECT u.first_name, u.middle_name, u.last_name, u.suffix, u.phone_no, u.bday 
                                     FROM user_plot_ownership upo 
                                     LEFT JOIN tbl_user u ON u.user_id = upo.user_id 
                                     WHERE UPPER(TRIM(upo.section)) = ? AND upo.plot_x = ? AND upo.plot_y = ? AND upo.verification_status = 'VERIFIED' AND LOWER(TRIM(COALESCE(upo.ownership_status, ''))) NOT IN ('cancelled', 'rejected') 
                                     ORDER BY upo.id DESC LIMIT 1");
            if ($uStmt) {
                $uStmt->bind_param('sii', $section, $plot_x, $plot_y);
                $uStmt->execute();
                $uRes = $uStmt->get_result();
                if ($uRes && ($uRow = $uRes->fetch_assoc())) {
                    $uName = trim(implode(' ', array_filter([$uRow['first_name'] ?? '', $uRow['middle_name'] ?? '', $uRow['last_name'] ?? '', $uRow['suffix'] ?? ''])));
                    $row = [
                        'owner_name' => $uName,
                        'first_name' => $uRow['first_name'] ?? '',
                        'middle_name' => $uRow['middle_name'] ?? '',
                        'last_name' => $uRow['last_name'] ?? '',
                        'suffix' => $uRow['suffix'] ?? '',
                        'bday' => $uRow['bday'] ?? '',
                        'phone_no' => $uRow['phone_no'] ?? '',
                        'address' => ''
                    ];
                }
                $uStmt->close();
            }
        }

        if (!$row && $conn->query("SHOW TABLES LIKE 'guest_reservation_requests'")->num_rows > 0) {
            $grr = $conn->prepare("SELECT full_name, email, contact_number FROM guest_reservation_requests WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND LOWER(TRIM(status)) IN ('approved', 'confirmed', 'account created') ORDER BY id DESC LIMIT 1");
            if ($grr) {
                $grr->bind_param('sii', $section, $plot_x, $plot_y);
                $grr->execute();
                $grrRes = $grr->get_result();
                if ($grrRes && ($gr = $grrRes->fetch_assoc())) {
                    $nameParts = preg_split('/\s+/', trim((string)$gr['full_name']));
                    $row = [
                        'owner_name' => trim((string)$gr['full_name']),
                        'first_name' => $nameParts[0] ?? '',
                        'middle_name' => count($nameParts) > 2 ? implode(' ', array_slice($nameParts, 1, -1)) : '',
                        'last_name' => count($nameParts) > 1 ? end($nameParts) : '',
                        'suffix' => '',
                        'bday' => '',
                        'phone_no' => trim((string)($gr['phone_number'] ?? '')),
                        'address' => ''
                    ];
                }
                $grr->close();
            }
        }

        $validOwner = trim((string)($row['owner_name'] ?? ''));
        if ($row && $validOwner !== '' && strcasecmp($validOwner, 'Reserved') !== 0 && strcasecmp($validOwner, 'Unknown') !== 0) {
            @ob_clean();
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success'=>true,'reservation'=>$row]);
        } else {
            @ob_clean();
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success'=>false,'message'=>'No active reservation']);
        }
    } catch (Exception $e) {
      error_log('popup.php get_reservation_info error: ' . $e->getMessage());
      @ob_clean();
      echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    } finally { if (isset($conn)) { $conn->close(); } }
    exit;
}

if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'update_reservation') {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator authorization required to update reservations.']);
        exit;
    }
    include_once $connPath;
    try {
        $required = ['section','plot_x','plot_y','first_name','last_name','bday','phone_no','address'];
        foreach ($required as $r) { if (!isset($_POST[$r]) || trim($_POST[$r])==='') { throw new Exception('Missing field: '.$r); } }
        $section = strtoupper(trim($_POST['section']));
        $plot_x = intval($_POST['plot_x']);
        $plot_y = intval($_POST['plot_y']);
        $first = strtoupper(trim($_POST['first_name']));
        $middle = isset($_POST['middle_name']) ? strtoupper(trim($_POST['middle_name'])) : '';
        $last = strtoupper(trim($_POST['last_name']));
        $suffix = isset($_POST['suffix']) ? strtoupper(trim($_POST['suffix'])) : '';
        $bday = trim($_POST['bday']);
        $phone = preg_replace('/[^0-9]/','', $_POST['phone_no']);
        $address = strtoupper(trim($_POST['address']));

        if (!preg_match('/^[A-Z\s]+$/',$first)) throw new Exception('Invalid first name');
        if ($middle !== '' && !preg_match('/^[A-Z\s]+$/',$middle)) throw new Exception('Invalid middle name');
        if (!preg_match('/^[A-Z\s]+$/',$last)) throw new Exception('Invalid last name');
        if ($suffix !== '' && !preg_match('/^[A-Z\s\.]+$/',$suffix)) throw new Exception('Invalid suffix');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$bday)) throw new Exception('Invalid birth date');
        if (strtotime($bday) === false || strtotime($bday) > time()) throw new Exception('Birth date cannot be in the future');
        if (!preg_match('/^09\d{9}$/',$phone)) throw new Exception('Phone must be an 11-digit mobile number starting with 09');
        if ($address==='') throw new Exception('Address is required');

        $owner_name = trim($first . ' ' . ($middle !== '' ? $middle.' ' : '') . $last . ($suffix!=='' ? ' '.$suffix : ''));

        $tbl = $conn->query("SHOW TABLES LIKE 'tbl_plot_owners'");
        if (!$tbl || $tbl->num_rows === 0) { throw new Exception('Reservation table not found'); }

        $old = [
          'owner_name' => null,
          'first_name' => null,
          'middle_name' => null,
          'last_name' => null,
          'suffix' => null,
          'bday' => null,
          'phone_no' => null,
          'address' => null,
        ];
        if ($sel = $conn->prepare("SELECT owner_name, first_name, middle_name, last_name, suffix, bday, phone_no, address FROM tbl_plot_owners WHERE section=? AND plot_x=? AND plot_y=? AND active=1 LIMIT 1")) {
            $sel->bind_param('sii', $section, $plot_x, $plot_y);
            $sel->execute();
            $resOld = $sel->get_result();
            if ($resOld && $resOld->num_rows > 0) { $old = $resOld->fetch_assoc(); }
            $sel->close();
        }
        if ($stmt = $conn->prepare("UPDATE tbl_plot_owners SET owner_name=?, first_name=?, middle_name=?, last_name=?, suffix=?, bday=?, phone_no=?, address=? WHERE section=? AND plot_x=? AND plot_y=? AND active=1")) {
            $stmt->bind_param('sssssssssii', $owner_name, $first, $middle, $last, $suffix, $bday, $phone, $address, $section, $plot_x, $plot_y);
            if (!$stmt->execute()) { throw new Exception('Failed to update reservation: '.$stmt->error); }
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected === 0) { throw new Exception('No active reservation found to update'); }
        } else { throw new Exception('Prepare failed: '.$conn->error); }

        if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) { @session_start(); }
        $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
        $editor = 'Unknown';
        if ($user_id > 0) {
            if ($uRes = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = $user_id")) {
                if ($uRes->num_rows > 0) { $u = $uRes->fetch_assoc(); $editor = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')); }
                $uRes->free();
            }
        }

        $conn->query("CREATE TABLE IF NOT EXISTS tbl_logs (id INT AUTO_INCREMENT PRIMARY KEY, action VARCHAR(100) NOT NULL, details TEXT, timestamp DATETIME NOT NULL, user_id INT NULL)");
        if ($log = $conn->prepare("INSERT INTO tbl_logs(action, details, timestamp, user_id) VALUES('Updated Reservation', ?, NOW(), ?)")) {
            $old_owner_name = trim((string)($old['owner_name'] ?? ''));
            $old_bday = (string)($old['bday'] ?? '');
            $old_phone = (string)($old['phone_no'] ?? '');
            $old_addr = trim((string)($old['address'] ?? ''));
            $new_owner_name = $owner_name;
            $new_bday = $bday;
            $new_phone = $phone;
            $new_addr = $address;
            $details = 'Section ' . $section . ', Plot ' . $plot_x . '-' . $plot_y . ' | OLD: OWNER=' . $old_owner_name . ', BDAY=' . $old_bday . ', PHONE=' . $old_phone . ', ADDRESS=' . $old_addr . ' | NEW: OWNER=' . $new_owner_name . ', BDAY=' . $new_bday . ', PHONE=' . $new_phone . ', ADDRESS=' . $new_addr;
            $log->bind_param('si', $details, $user_id);
            $log->execute();
            $log->close();
        }

        echo json_encode(['success'=>true,'message'=>'Reservation updated successfully','owner_name'=>$owner_name,'owner_phone'=>$phone]);
    } catch (Exception $e) {
      error_log('popup.php update_reservation error: ' . $e->getMessage());
      @ob_clean();
      http_response_code(500);
      header('Content-Type: application/json');
      echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    } finally { if (isset($conn)) { $conn->close(); } }
    exit;
}

if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'reserve_plot') {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator authorization required to directly reserve plots.']);
        exit;
    }
    include_once $connPath;
    try {

        $required = ['section','plot_x','plot_y','res_first_name','res_last_name','res_bday','res_phone','res_address'];
        foreach ($required as $r) { if (!isset($_POST[$r]) || trim($_POST[$r])==='') { throw new Exception('Missing field: ' . $r); } }
        $section = strtoupper(trim($_POST['section']));
        $plot_x = intval($_POST['plot_x']);
        $plot_y = intval($_POST['plot_y']);
        $first_name = strtoupper(trim($_POST['res_first_name']));
        $middle_name = isset($_POST['res_middle_name']) ? strtoupper(trim($_POST['res_middle_name'])) : '';
        $last_name = strtoupper(trim($_POST['res_last_name']));
        $suffix = isset($_POST['res_suffix']) ? strtoupper(trim($_POST['res_suffix'])) : '';
        $bday = trim($_POST['res_bday']);
        $phone = preg_replace('/[^0-9]/','', $_POST['res_phone']);
        $address = strtoupper(trim($_POST['res_address']));

        if (!preg_match('/^[A-Z\s]+$/', $first_name)) throw new Exception('Invalid first name');
        if ($middle_name !== '' && !preg_match('/^[A-Z\s]+$/', $middle_name)) throw new Exception('Invalid middle name');
        if (!preg_match('/^[A-Z\s]+$/', $last_name)) throw new Exception('Invalid last name');

        if ($suffix !== '' && !preg_match('/^[A-Z\s\.]+$/', $suffix)) throw new Exception('Invalid suffix');

        $sfx_norm = $suffix;
        if ($sfx_norm === 'JR') { $sfx_norm = 'JR.'; }
        elseif ($sfx_norm === 'SR') { $sfx_norm = 'SR.'; }

        $sfx_norm = preg_replace('/\s+/', ' ', trim($sfx_norm));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bday)) throw new Exception('Invalid birth date');
        if (strtotime($bday) === false || strtotime($bday) > time()) throw new Exception('Birth date cannot be in the future');
        if (!preg_match('/^09\d{9}$/', $phone)) throw new Exception('Phone must be an 11-digit mobile number starting with 09');
        if ($address === '') throw new Exception('Address is required');

        $owner_name = trim($first_name . ' ' . ($middle_name !== '' ? $middle_name . ' ' : '') . $last_name . ($sfx_norm !== '' ? ' ' . $sfx_norm : ''));

        $createSql = "CREATE TABLE IF NOT EXISTS tbl_plot_owners (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section VARCHAR(10) NOT NULL,
            plot_x INT NOT NULL,
            plot_y INT NOT NULL,
            owner_name VARCHAR(150) NOT NULL,
            first_name VARCHAR(50) NOT NULL,
            middle_name VARCHAR(50) DEFAULT NULL,
            last_name VARCHAR(50) NOT NULL,
            suffix VARCHAR(20) DEFAULT NULL,
            bday DATE NOT NULL,
            phone_no VARCHAR(15) NOT NULL,
            address VARCHAR(255) NOT NULL,
            reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reserved_by INT DEFAULT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uniq_plot (section, plot_x, plot_y)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!$conn->query($createSql)) { throw new Exception('Failed to prepare reservation table: ' . $conn->error); }

        $ensureCols = [
            "first_name VARCHAR(50) NOT NULL",
            "middle_name VARCHAR(50) DEFAULT NULL",
            "last_name VARCHAR(50) NOT NULL",
            "suffix VARCHAR(20) DEFAULT NULL",
            "bday DATE NOT NULL",
            "phone_no VARCHAR(15) NOT NULL",
            "address VARCHAR(255) NOT NULL"
        ];
        foreach ($ensureCols as $def) {
            $col = trim(explode(' ', $def)[0]);
            $chkCol = $conn->query("SHOW COLUMNS FROM tbl_plot_owners LIKE '".$conn->real_escape_string($col)."'");
            if ($chkCol && $chkCol->num_rows === 0) {
                $conn->query("ALTER TABLE tbl_plot_owners ADD COLUMN $def");
            }
            if ($chkCol) $chkCol->free();
        }

        $conn->begin_transaction();

        $statusTable = 'tbl_plots';
        $chkPlots = @$conn->query("SHOW TABLES LIKE 'tbl_plots'");
        if (!$chkPlots || $chkPlots->num_rows === 0) {
            $statusTable = 'plots';
        }

        $plotCheck = $conn->prepare("SELECT status FROM {$statusTable} WHERE section = ? AND plot_x = ? AND plot_y = ? FOR UPDATE");
        if (!$plotCheck) {
            $altTable = ($statusTable === 'tbl_plots') ? 'plots' : 'tbl_plots';
            $plotCheck = $conn->prepare("SELECT status FROM {$altTable} WHERE section = ? AND plot_x = ? AND plot_y = ? FOR UPDATE");
            if ($plotCheck) {
                $statusTable = $altTable;
            } else {
                throw new Exception('Plot table is unavailable.');
            }
        }
        $plotCheck->bind_param('sii', $section, $plot_x, $plot_y);
        $plotCheck->execute();
        $plotRow = $plotCheck->get_result()->fetch_assoc();
        $plotCheck->close();
        if (!$plotRow || strtolower(trim((string)$plotRow['status'])) !== 'available') {
          throw new Exception('This plot is no longer available. Please choose another plot.');
        }

        $chk = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_deceased WHERE section = ? AND plot_x = ? AND plot_y = ?");
        $chk->bind_param('sii', $section, $plot_x, $plot_y);
        $chk->execute();
        $rc = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (intval($rc['c'] ?? 0) > 0) { throw new Exception('Cannot reserve: plot already has deceased.'); }

        $user_id = 0;
        if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) { @session_start(); }
        if (isset($_SESSION['user_id'])) { $user_id = intval($_SESSION['user_id']); }

        $activeCheck = $conn->prepare("SELECT id FROM tbl_plot_owners WHERE section = ? AND plot_x = ? AND plot_y = ? AND active = 1 LIMIT 1 FOR UPDATE");
        $activeCheck->bind_param('sii', $section, $plot_x, $plot_y);
        $activeCheck->execute();
        $hasActiveReservation = $activeCheck->get_result()->fetch_assoc();
        $activeCheck->close();
        if ($hasActiveReservation) { throw new Exception('This plot has already been reserved.'); }

        $ins = $conn->prepare("INSERT INTO tbl_plot_owners(section, plot_x, plot_y, owner_name, first_name, middle_name, last_name, suffix, bday, phone_no, address, reserved_by, active) VALUES(?,?,?,?,?,?,?,?,?,?,?, ?,1)");
        if ($ins) {
            $ins->bind_param('siissssssssi', $section, $plot_x, $plot_y, $owner_name, $first_name, $middle_name, $last_name, $suffix, $bday, $phone, $address, $user_id);
            if (!$ins->execute()) {
            throw new Exception('This plot has already been reserved.');
            }
            $ins->close();
        } else {
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        $statusUpdate = $conn->prepare("UPDATE {$statusTable} SET status = 'Reserved' WHERE section = ? AND plot_x = ? AND plot_y = ? AND LOWER(status) = 'available'");
        $statusUpdate->bind_param('sii', $section, $plot_x, $plot_y);
        if (!$statusUpdate->execute() || $statusUpdate->affected_rows !== 1) {
          $statusUpdate->close();
          throw new Exception('The plot became unavailable during reservation.');
        }
        $statusUpdate->close();

        if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) { @session_start(); }
        $editor = 'Unknown';
        $user_id = 0;
        if (isset($_SESSION['user_id'])) {
            $user_id = intval($_SESSION['user_id']);
            if ($uRes = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = $user_id")) {
                if ($uRes->num_rows > 0) { $u = $uRes->fetch_assoc(); $editor = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')); }
                $uRes->free();
            }
        }

        $conn->query("CREATE TABLE IF NOT EXISTS tbl_logs (id INT AUTO_INCREMENT PRIMARY KEY, action VARCHAR(100) NOT NULL, details TEXT, timestamp DATETIME NOT NULL, user_id INT NULL)");
        if ($log = $conn->prepare("INSERT INTO tbl_logs(action, details, timestamp, user_id) VALUES('Reserved By', ?, NOW(), ?)")) {
            $details = 'Section ' . $section . ', Plot ' . $plot_x . '-' . $plot_y . ' reserved for ' . $owner_name;
            $log->bind_param('si', $details, $user_id);
            $log->execute();
            $log->close();
        }

          $conn->commit();

        @ob_clean();
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['success'=>true, 'message'=>'Plot reserved successfully', 'owner_name' => $owner_name, 'owner_phone' => $phone]);
    } catch (Exception $e) {
      if (isset($conn)) { $conn->rollback(); }
      error_log('popup.php reserve_plot error: ' . $e->getMessage());
      @ob_clean();
      http_response_code(500);
      header('Content-Type: application/json');
      echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    } finally {
        if (isset($conn)) { $conn->close(); }
    }
    exit;
}

if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'move_deceased') {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator authorization required to move deceased records.']);
        exit;
    }
    include_once $connPath;
    try {
        $required = ['deceased_id','new_section','new_plot_x','new_plot_y'];
        foreach ($required as $r) { if (!isset($_POST[$r]) || $_POST[$r] === '') { throw new Exception('Missing field: ' . $r); } }
        $deceased_id = intval($_POST['deceased_id']);
        $new_section = strtoupper(trim($_POST['new_section']));
        $new_plot_x = intval($_POST['new_plot_x']);
        $new_plot_y = intval($_POST['new_plot_y']);

        $res = $conn->query("SELECT * FROM tbl_deceased WHERE deceased_id = " . $deceased_id . " LIMIT 1");
        if (!$res || $res->num_rows === 0) { throw new Exception('Deceased record not found'); }
        $row = $res->fetch_assoc();
        $old_section = strtoupper(trim($row['section']));
        $old_x = intval($row['plot_x']);
        $old_y = intval($row['plot_y']);

        if ($old_section === $new_section && $old_x === $new_plot_x && $old_y === $new_plot_y) {
            echo json_encode(['success'=>true,'message'=>'No changes made']);
            exit;
        }

        $stmtCnt = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_deceased WHERE section = ? AND plot_x = ? AND plot_y = ? AND deceased_id <> ?");
        $stmtCnt->bind_param('siii', $new_section, $new_plot_x, $new_plot_y, $deceased_id);
        $stmtCnt->execute();
        $cntRes = $stmtCnt->get_result();
        $cntRow = $cntRes ? $cntRes->fetch_assoc() : ['c'=>0];
        $currentOccupants = intval($cntRow['c'] ?? 0);
        if ($currentOccupants >= 4) { throw new Exception('Target plot has reached the maximum of 4 deceased.'); }
        $stmtCnt->close();

        $hadMatchingActiveReservation = false;
        if ($currentOccupants === 0) {
            if ($tbl = $conn->query("SHOW TABLES LIKE 'tbl_plot_owners'")) {
                if ($tbl->num_rows > 0) {
                    if ($rs = $conn->prepare("SELECT owner_name FROM tbl_plot_owners WHERE section = ? AND plot_x = ? AND plot_y = ? AND active = 1 LIMIT 1")) {
                        $rs->bind_param('sii', $new_section, $new_plot_x, $new_plot_y);
                        $rs->execute();
                        $chkRes = $rs->get_result();
                        if ($chkRes && $chkRes->num_rows > 0) {
                            $resRow = $chkRes->fetch_assoc();
                            $reserved_owner = strtoupper(trim($resRow['owner_name'] ?? ''));

                            $dec_first = strtoupper(trim($row['first_name']));
                            $dec_last = strtoupper(trim($row['last_name']));
                            $dec_key_raw = trim($dec_first . ' ' . $dec_last);
                            $dec_key = preg_replace('/\s+/', ' ', preg_replace('/[^A-Z\s]/', '', $dec_key_raw));
                            $owner_key = preg_replace('/\s+/', ' ', preg_replace('/[^A-Z\s]/', '', $reserved_owner));

                            $match = ($owner_key === $dec_key) || (strpos($owner_key, $dec_key) !== false) || (strpos($dec_key, $owner_key) !== false);
                            if (!$match) {
                                $hasFirst = ($dec_first !== '' && strpos(' ' . $owner_key . ' ', ' ' . $dec_first . ' ') !== false);
                                $hasLast  = ($dec_last  !== '' && strpos(' ' . $owner_key . ' ', ' ' . $dec_last  . ' ') !== false);
                                $match = $hasFirst && $hasLast;
                            }
                            if (!$match) { throw new Exception('Cannot move: reservation is under a different name.'); }
                            $hadMatchingActiveReservation = true;
                        }
                        $rs->close();
                    }
                }
            }
        }

        if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) { @session_start(); }

        $edited_by = 'Unknown';
        if (isset($_SESSION['user_id'])) {
            $uid = intval($_SESSION['user_id']);
            if ($uRes = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = $uid")) {
                if ($uRes->num_rows > 0) { $u = $uRes->fetch_assoc(); $edited_by = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')); }
                $uRes->free();
            }
        }

        $conn->autocommit(FALSE);

        $hasEditedBy = false;
        if ($cRes = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'edited_by'")) {
            $hasEditedBy = $cRes->num_rows > 0; $cRes->free();
        }
        if (!$hasEditedBy) {
            $conn->query("ALTER TABLE tbl_deceased ADD COLUMN edited_by varchar(100) DEFAULT NULL");
        }

        $up = $conn->prepare("UPDATE tbl_deceased SET section = ?, plot_x = ?, plot_y = ?, edited_by = ? WHERE deceased_id = ?");
        if (!$up) { throw new Exception('Prepare failed: ' . $conn->error); }
        $up->bind_param('siisi', $new_section, $new_plot_x, $new_plot_y, $edited_by, $deceased_id);
        if (!$up->execute()) { throw new Exception('Failed to move deceased: ' . $up->error); }
        $up->close();

        if ($hadMatchingActiveReservation) {
            if ($deact = $conn->prepare("UPDATE tbl_plot_owners SET active = 0 WHERE section = ? AND plot_x = ? AND plot_y = ? AND active = 1")) {
                $deact->bind_param('sii', $new_section, $new_plot_x, $new_plot_y);
                $deact->execute();
                $deact->close();
            }
        }

        if ($old_section && $old_x && $old_y) {
            if ($deactOld = $conn->prepare("UPDATE tbl_plot_owners SET active = 0 WHERE section = ? AND plot_x = ? AND plot_y = ? AND active = 1")) {
                $deactOld->bind_param('sii', $old_section, $old_x, $old_y);
                $deactOld->execute();
                $deactOld->close();
            }
        }

        $first = strtoupper(trim($row['first_name']));
        $middle = strtoupper(trim($row['middle_name'] ?? ''));
        $last = strtoupper(trim($row['last_name']));
        $bday = $row['bday'];
        $died = $row['date_died'];
        $hasSuffixCol = false;
        if ($sxRes = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'suffix'")) {
            $hasSuffixCol = $sxRes->num_rows > 0; $sxRes->free();
        }
        if ($hasSuffixCol) {
            $suffix = strtoupper(trim($row['suffix'] ?? ''));
            if ($dedupe = $conn->prepare("DELETE FROM tbl_deceased WHERE deceased_id <> ? AND UPPER(TRIM(first_name)) = ? AND UPPER(TRIM(COALESCE(middle_name,''))) = ? AND UPPER(TRIM(last_name)) = ? AND UPPER(TRIM(COALESCE(suffix,''))) = ? AND bday = ? AND date_died = ?")) {
                $sx = $suffix !== '' ? $suffix : '';
                $mn = $middle !== '' ? $middle : '';
                $dedupe->bind_param('issssss', $deceased_id, $first, $mn, $last, $sx, $bday, $died);
                $dedupe->execute();
                $dedupe->close();
            }
        } else {
            if ($dedupe = $conn->prepare("DELETE FROM tbl_deceased WHERE deceased_id <> ? AND UPPER(TRIM(first_name)) = ? AND UPPER(TRIM(COALESCE(middle_name,''))) = ? AND UPPER(TRIM(last_name)) = ? AND bday = ? AND date_died = ?")) {
                $mn = $middle !== '' ? $middle : '';
                $dedupe->bind_param('isssss', $deceased_id, $first, $mn, $last, $bday, $died);
                $dedupe->execute();
                $dedupe->close();
            }
        }

        $log_sql = "INSERT INTO tbl_logs (action, details, timestamp, user_id) VALUES (?, ?, NOW(), ?)";
        if ($log_stmt = $conn->prepare($log_sql)) {
            $action = 'Moved By';
            $details = $edited_by . ' moved deceased ID ' . $deceased_id . ' to Section ' . $new_section . ', Plot ' . $new_plot_x . '-' . $new_plot_y;
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
            $log_stmt->bind_param('ssi', $action, $details, $user_id);
            $log_stmt->execute();
            $log_stmt->close();
        }

        $conn->commit();
        $conn->autocommit(TRUE);
        @ob_clean();
        echo json_encode(['success'=>true,'message'=>'Deceased moved successfully']);
    } catch (Exception $e) {
        if (isset($conn)) { $conn->rollback(); $conn->autocommit(TRUE); }
        error_log('popup.php move_deceased error: ' . $e->getMessage());
        @ob_clean();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    } finally {
        if (isset($conn)) { $conn->close(); }
    }
    exit;
}

    if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'delete_deceased') {
      @ob_clean();
      header('Content-Type: application/json; charset=utf-8');
      if (!$isAdmin) {
          http_response_code(403);
          echo json_encode(['success' => false, 'message' => 'Administrator authorization required to delete deceased records.']);
          exit;
      }
      include_once $connPath;
      try {
        if (!isset($_POST['deceased_id']) || trim($_POST['deceased_id']) === '') { throw new Exception('Missing field: deceased_id'); }
        $deceased_id = intval($_POST['deceased_id']);

        $sel = $conn->prepare("SELECT section, plot_x, plot_y FROM tbl_deceased WHERE deceased_id = ? LIMIT 1");
        if (!$sel) throw new Exception('Prepare failed: ' . $conn->error);
        $sel->bind_param('i', $deceased_id);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$row) { throw new Exception('Deceased record not found'); }
        $section = strtoupper(trim($row['section']));
        $plot_x = intval($row['plot_x']);
        $plot_y = intval($row['plot_y']);

        $conn->autocommit(FALSE);

        $del = $conn->prepare("DELETE FROM tbl_deceased WHERE deceased_id = ?");
        if (!$del) throw new Exception('Prepare failed: ' . $conn->error);
        $del->bind_param('i', $deceased_id);
        if (!$del->execute()) { throw new Exception('Failed to delete record: ' . $del->error); }
        $del->close();

        $conn->query("CREATE TABLE IF NOT EXISTS tbl_logs (id INT AUTO_INCREMENT PRIMARY KEY, action VARCHAR(100) NOT NULL, details TEXT, timestamp DATETIME NOT NULL, user_id INT NULL)");
        $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
        if ($log = $conn->prepare("INSERT INTO tbl_logs(action, details, timestamp, user_id) VALUES('DELETE_DECEASED', ?, NOW(), ?)") ) {
          $details = 'Deleted deceased ID ' . $deceased_id . ' from Section ' . $section . ' Plot ' . $plot_x . '-' . $plot_y;
          $log->bind_param('si', $details, $user_id);
          $log->execute();
          $log->close();
        }

        $conn->commit();
        $conn->autocommit(TRUE);
        @ob_clean();
        echo json_encode(['success' => true, 'message' => 'Deceased record deleted successfully']);
      } catch (Exception $e) {
        if (isset($conn)) { $conn->rollback(); $conn->autocommit(TRUE); }
        error_log('popup.php delete_deceased error: ' . $e->getMessage());
        @ob_clean();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
      } finally {
        if (isset($conn)) { $conn->close(); }
      }
      exit;
    }

if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'get_deceased_info') {
  header('Content-Type: application/json');
  error_log('get_deceased_info: start');

  include_once $connPath;

    if (!isset($_POST['section']) || !isset($_POST['plot_x']) || !isset($_POST['plot_y'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required parameters'
        ]);
        exit;
    }

    $section = strtoupper(trim($_POST['section']));
    $plot_x = intval($_POST['plot_x']);
    $plot_y = intval($_POST['plot_y']);

    try {

        $sql = "SELECT * FROM tbl_deceased WHERE section = ? AND plot_x = ? AND plot_y = ? ORDER BY date_died DESC, deceased_id DESC";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }

        $stmt->bind_param("sii", $section, $plot_x, $plot_y);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $list = [];
            while ($row = $result->fetch_assoc()) {
                $full_name_parts = array_filter([
                    trim($row['first_name']),
                    trim($row['middle_name']),
                    trim($row['last_name']),
                    trim($row['suffix'] ?? '')
                ]);
                $row['full_name'] = implode(' ', $full_name_parts);
                if (!empty($row['bday'])) {
                    $row['formatted_birth_date'] = date('F j, Y', strtotime($row['bday']));
                }
                if (!empty($row['date_died'])) {
                    $row['formatted_death_date'] = date('F j, Y', strtotime($row['date_died']));
                }
                if (!empty($row['bday']) && !empty($row['date_died'])) {
                    $birth_date = new DateTime($row['bday']);
                    $death_date = new DateTime($row['date_died']);
                    $row['age_at_death'] = $birth_date->diff($death_date)->y;
                }
                $list[] = $row;
            }

            $curr_user_id = (int)($_SESSION['user_id'] ?? 0);
            $curr_role = strtolower(trim((string)($_SESSION['role'] ?? ($_SESSION['user_type'] ?? ''))));
            $is_admin = ($curr_role === 'admin' || $curr_role === 'administrator');
            $is_user_authorized = false;
            if ($is_admin) {
                $is_user_authorized = true;
            } elseif ($curr_user_id > 0) {
                if (function_exists('is_user_authorized_for_plot') && is_user_authorized_for_plot($conn, $curr_user_id, $section, $plot_x, $plot_y)) {
                    $is_user_authorized = true;
                } elseif (!empty($list)) {
                    foreach ($list as $decItem) {
                        $dId = (int)($decItem['deceased_id'] ?? 0);
                        if ($dId > 0 && function_exists('is_user_authorized_for_deceased') && is_user_authorized_for_deceased($conn, $curr_user_id, $dId)) {
                            $is_user_authorized = true;
                            break;
                        }
                        if ((int)($decItem['user_id'] ?? 0) === $curr_user_id) {
                            $is_user_authorized = true;
                            break;
                        }
                    }
                }
            }

            if (!$is_user_authorized && !$is_admin) {
                foreach ($list as &$dItem) {
                    $dItem['contact_no'] = '—';
                    $dItem['booker_name'] = '';
                    $dItem['payment_plan'] = '';
                    $dItem['payment_term'] = 0;
                    $dItem['payment_status'] = '';
                    $dItem['total_amount'] = 0;
                    $dItem['amount_paid'] = 0;
                    $dItem['monthly_due'] = 0;
                    $dItem['user_id'] = 0;
                }
                unset($dItem);
            }

            @ob_clean();
            http_response_code(200);
            error_log('get_deceased_info: returning ' . count($list) . ' rows');
            echo json_encode([
              'success' => true,
              'deceased_list' => $list,
              'count' => count($list),
              'plot_info' => [ 'section' => $section, 'plot_x' => $plot_x, 'plot_y' => $plot_y ]
            ]);
        } else {

            $owner_name = null;
            $owner_phone = null;
            if ($conn->query("SHOW TABLES LIKE 'tbl_plot_owners'")) {
                if ($ownStmt = $conn->prepare("SELECT owner_name, first_name, middle_name, last_name, suffix, phone_no FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND active = 1 ORDER BY id DESC LIMIT 1")) {
                    $ownStmt->bind_param('sii', $section, $plot_x, $plot_y);
                    if ($ownStmt->execute()) {
                        $ownRes = $ownStmt->get_result();
                        if ($ownRes && ($ownRow = $ownRes->fetch_assoc())) {
                            $rawName = trim((string)($ownRow['owner_name'] ?? ''));
                            if ($rawName === '' || strcasecmp($rawName, 'Reserved') === 0) {
                                $rawName = trim(implode(' ', array_filter([
                                    trim((string)($ownRow['first_name'] ?? '')),
                                    trim((string)($ownRow['middle_name'] ?? '')),
                                    trim((string)($ownRow['last_name'] ?? '')),
                                    trim((string)($ownRow['suffix'] ?? ''))
                                ])));
                            }
                            if (strcasecmp($rawName, 'Reserved') === 0) {
                                $rawName = '';
                            }
                            $owner_name = $rawName ?: null;
                            $owner_phone = $ownRow['phone_no'] ?? null;
                        }
                    }
                    $ownStmt->close();
                }
            }
            if (!$owner_name) {
                $bkgTbl = $conn->query("SHOW TABLES LIKE 'tbl_bookings'");
                if ($bkgTbl && $bkgTbl->num_rows > 0) {
                    $plotPad6 = sprintf('%03d%03d', $plot_x, $plot_y);
                    $plotSecPad6 = $section . '-' . $plotPad6;
                    $plotHyphen = (string)$plot_x . '-' . (string)$plot_y;
                    $plotSecHyphen = $section . '-' . $plotHyphen;
                    $plotSpace = $section . ' ' . $plotHyphen;
                    $plotComma1 = $section . ' ' . $plot_x . ',' . $plot_y;
                    $plotComma2 = $section . ' ' . $plot_x . ', ' . $plot_y;
                    $plotComma3 = $section . '- ' . $plot_x . ',' . $plot_y;
                    $plotComma4 = $section . '- ' . $plot_x . ', ' . $plot_y;
                    $plotComma5 = (string)$plot_x . ',' . (string)$plot_y;
                    $plotComma6 = (string)$plot_x . ', ' . (string)$plot_y;

                    $bkgSql = "SELECT b.id, b.customer_name, b.full_name, b.contact_number, b.booker_location, b.user_id,
                                      u.first_name, u.middle_name, u.last_name, u.suffix, u.phone_no
                               FROM tbl_bookings b
                               LEFT JOIN tbl_user u ON b.user_id = u.user_id
                               WHERE UPPER(TRIM(b.section)) = ?
                                 AND (
                                   b.plot IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                   OR b.grave_location IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                   OR b.plot_id IN (SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?) OR b.id IN (SELECT bp.booking_id FROM booking_plots bp WHERE UPPER(TRIM(bp.section)) = '" . $conn->real_escape_string($section) . "' AND bp.plot_x = " . (int)$plot_x . " AND bp.plot_y = " . (int)$plot_y . ")
                                 )
                                 AND LOWER(TRIM(b.status)) IN ('approved', 'confirmed', 'reserved')
                               ORDER BY b.id DESC LIMIT 1";
                    if ($bkgStmt = $conn->prepare($bkgSql)) {
                        $bkgStmt->bind_param('ssssssssssssssssssssssssii',
                            $section,
                            $plotPad6, $plotSecPad6, $plotHyphen, $plotSecHyphen, $plotSpace, $plotComma1, $plotComma2, $plotComma3, $plotComma4, $plotComma5, $plotComma6,
                            $plotPad6, $plotSecPad6, $plotHyphen, $plotSecHyphen, $plotSpace, $plotComma1, $plotComma2, $plotComma3, $plotComma4, $plotComma5, $plotComma6,
                            $section, $plot_x, $plot_y
                        );
                        $bkgStmt->execute();
                        $bkgRes = $bkgStmt->get_result();
                        if ($bkgRes && ($bRow = $bkgRes->fetch_assoc())) {
                            $bkgName = trim((string)($bRow['customer_name'] ?? ''));
                            if ($bkgName === '' || strcasecmp($bkgName, 'Reserved') === 0) {
                                $bkgName = trim((string)($bRow['full_name'] ?? ''));
                            }
                            if ($bkgName === '' || strcasecmp($bkgName, 'Reserved') === 0) {
                                $bkgName = trim(implode(' ', array_filter([
                                    trim((string)($bRow['first_name'] ?? '')),
                                    trim((string)($bRow['middle_name'] ?? '')),
                                    trim((string)($bRow['last_name'] ?? '')),
                                    trim((string)($bRow['suffix'] ?? ''))
                                ])));
                            }
                            if (strcasecmp($bkgName, 'Reserved') === 0) {
                                $bkgName = '';
                            }
                            $owner_name = $bkgName ?: null;
                            $owner_phone = $bRow['contact_number'] ?: ($bRow['phone_no'] ?? null);
                        }
                        $bkgStmt->close();
                    }
                }
            }

            $safe_owner_name = $isAdmin ? $owner_name : ($owner_name ? 'Reserved' : null);
            $safe_owner_phone = $isAdmin ? $owner_phone : null;
            @ob_clean();
            http_response_code(200);
            error_log('get_deceased_info: no deceased, returning owner info');
            echo json_encode([
              'success' => false,
              'message' => 'No deceased found in this plot',
              'owner_name' => $safe_owner_name,
              'owner_phone' => $safe_owner_phone,
              'plot_info' => [
                'section' => $section,
                'plot_x' => $plot_x,
                'plot_y' => $plot_y
              ]
            ]);
        }

        $stmt->close();

    } catch (Exception $e) {
      error_log('get_deceased_info error: ' . $e->getMessage());
      @ob_clean();
      http_response_code(500);
      echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
      ]);
    } finally {
        if (isset($conn)) {
            $conn->close();
        }
    }
    exit;
}

if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'add_deceased') {
    @ob_clean();
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator authorization required to register plot records.']);
        exit;
    }

    global $conn;
    include_once $connPath;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        if (isset($GLOBALS['conn']) && ($GLOBALS['conn'] instanceof mysqli)) {
            $conn = $GLOBALS['conn'];
        }
    }
    $installmentHelperPath = file_exists(__DIR__ . '/config/installment_helper.php') ? (__DIR__ . '/config/installment_helper.php') : (__DIR__ . '/../config/installment_helper.php');
    if (file_exists($installmentHelperPath)) { include_once $installmentHelperPath; }

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

            // Ensure columns exist in tbl_bookings
            $chkColsTrans = [
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

            $bkCheck = $conn->prepare("SELECT id FROM tbl_bookings WHERE UPPER(TRIM(section)) = ? AND (plot = ? OR plot = ?) AND status NOT IN ('cancelled', 'rejected') ORDER BY id DESC LIMIT 1");
            if ($bkCheck) {
                $bkCheck->bind_param('sss', $section, $plotHyphen, $plotPad6);
                $bkCheck->execute();
                $bkRes = $bkCheck->get_result();
                $existingBooking = $bkRes ? $bkRes->fetch_assoc() : null;
                $bkCheck->close();

                if ($existingBooking) {
                    $booking_id = (int)$existingBooking['id'];
                    $upBk = $conn->prepare("UPDATE tbl_bookings SET service_type = 'plot_purchase', customer_name = ?, full_name = ?, customer_email = ?, email = ?, contact_number = ?, payment_plan = ?, payment_term = ?, total_amount = ?, amount_paid = ?, payment_type = ?, purpose = 'plot_purchase', deceased_id = NULL, deceased_name = NULL, plot_id = ?, status = ? WHERE id = ?");
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
                    $insBk = $conn->prepare("INSERT INTO tbl_bookings (service_type, grave_location, plot_type, section, plot, section_plot, plot_price, preferred_date, contact_number, booker_location, payment_type, amount_paid, status, user_id, deceased_id, customer_name, full_name, customer_email, email, deceased_name, purpose, payment_plan, payment_term, total_amount, plot_id, booking_type, created_at) VALUES ('plot_purchase', ?, 'Lawn', ?, ?, ?, ?, NOW(), ?, 'Memorial Park Office', ?, ?, ?, ?, NULL, ?, ?, ?, ?, NULL, 'plot_purchase', ?, ?, ?, ?, 'plot_purchase', NOW())");
                    if (!$insBk) {
                        if (function_exists('ensure_booking_table_integrity')) {
                            ensure_booking_table_integrity($conn);
                        }
                        $insBk = $conn->prepare("INSERT INTO tbl_bookings (service_type, grave_location, plot_type, section, plot, section_plot, plot_price, preferred_date, contact_number, booker_location, payment_type, amount_paid, status, user_id, deceased_id, customer_name, full_name, customer_email, email, deceased_name, purpose, payment_plan, payment_term, total_amount, plot_id, booking_type, created_at) VALUES ('plot_purchase', ?, 'Lawn', ?, ?, ?, ?, NOW(), ?, 'Memorial Park Office', ?, ?, ?, ?, NULL, ?, ?, ?, ?, NULL, 'plot_purchase', ?, ?, ?, ?, 'plot_purchase', NOW())");
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
                throw new Exception("Missing required field: " . $field);
            }
        }

        $first_name = strtoupper(trim($_POST['first_name']));
        $middle_name = strtoupper(trim($_POST['middle_name'] ?? ''));
        $last_name = strtoupper(trim($_POST['last_name']));
        $raw_suffix = isset($_POST['suffix']) ? strtoupper(trim($_POST['suffix'])) : '';
        $other_suffix = isset($_POST['suffix_other']) ? strtoupper(trim($_POST['suffix_other'])) : '';
        $suffix = ($raw_suffix === 'OTHER') ? $other_suffix : $raw_suffix;
        $bday = trim($_POST['bday']);
        $date_died = trim($_POST['date_died']);
        $born_in = strtoupper(trim($_POST['born_in']));
        $contact_no = trim($_POST['contact_no']);
        $funeral_service = strtoupper(trim($_POST['funeral_service'] ?? ''));
        $section = strtoupper(trim($_POST['section']));
        $plot_x = intval($_POST['plot_x']);
        $plot_y = intval($_POST['plot_y']);

        $today = date('Y-m-d');
        if ($bday > $today) {
            throw new Exception("Birth date cannot be in the future.");
        }
        if ($date_died > $today) {
            throw new Exception("Death date cannot be in the future.");
        }
        if ($date_died < $bday) {
            throw new Exception("Death date cannot be before birth date.");
        }

        $clean_contact = preg_replace('/\D+/', '', $contact_no);
        if (!preg_match('/^09\d{9}$/', $clean_contact)) {
            throw new Exception("Family contact number must be an 11-digit mobile number starting with 09.");
        }
        $contact_no = $clean_contact;

        $full_name_parts = array_filter([$first_name, $middle_name, $last_name, $suffix]);
        $full_name = implode(' ', $full_name_parts);

        $count_sql = "SELECT COUNT(*) AS c FROM tbl_deceased WHERE section = ? AND plot_x = ? AND plot_y = ?";
        $count_stmt = $conn->prepare($count_sql);
        $count_stmt->bind_param("sii", $section, $plot_x, $plot_y);
        $count_stmt->execute();
        $count_res = $count_stmt->get_result();
        $rowC = $count_res ? $count_res->fetch_assoc() : ['c' => 0];
        $current_count = intval($rowC['c'] ?? 0);
        if ($current_count >= 4) {
            throw new Exception("This plot has reached the maximum of 4 deceased.");
        }

        if ($current_count > 0) {
            $transferPolicyPath = file_exists(__DIR__ . '/../config/transfer_policy.php') ? (__DIR__ . '/../config/transfer_policy.php') : (__DIR__ . '/config/transfer_policy.php');
            if (file_exists($transferPolicyPath)) {
                require_once $transferPolicyPath;
            }
            if (function_exists('check_plot_interment_interval_eligibility')) {
                $intervalCheck = check_plot_interment_interval_eligibility($conn, $section, $plot_x, $plot_y, $date_died);
                if (!$intervalCheck['is_eligible']) {
                    $adminBypass = (!empty($_POST['admin_confirm_interval_override']) && $isAdmin);
                    if (!$adminBypass) {
                        throw new Exception($intervalCheck['message']);
                    }
                }
            }
        }

        $matched_active_reservation = false;
        if ($current_count === 0) {
            if ($conn->query("SHOW TABLES LIKE 'tbl_plot_owners'")) {
                if ($ownStmt = $conn->prepare("SELECT owner_name FROM tbl_plot_owners WHERE section = ? AND plot_x = ? AND plot_y = ? AND active = 1 LIMIT 1")) {
                    $ownStmt->bind_param('sii', $section, $plot_x, $plot_y);
                    if ($ownStmt->execute()) {
                        $ownRes = $ownStmt->get_result();
                        if ($ownRes && $ownRes->num_rows > 0) {
                            $ownRow = $ownRes->fetch_assoc();
                            $reserved_owner = strtoupper(trim($ownRow['owner_name'] ?? ''));

                            $dec_first = strtoupper(trim($first_name));
                            $dec_last  = strtoupper(trim($last_name));
                            $dec_key_raw = trim($dec_first . ' ' . $dec_last);
                            $dec_key = preg_replace('/\s+/', ' ', preg_replace('/[^A-Z\s]/', '', $dec_key_raw));
                            $owner_key = preg_replace('/\s+/', ' ', preg_replace('/[^A-Z\s]/', '', $reserved_owner));
                            $match = ($owner_key === $dec_key) || (strpos($owner_key, $dec_key) !== false) || (strpos($dec_key, $owner_key) !== false);
                            if (!$match) {
                                $hasFirst = ($dec_first !== '' && strpos(' ' . $owner_key . ' ', ' ' . $dec_first . ' ') !== false);
                                $hasLast  = ($dec_last  !== '' && strpos(' ' . $owner_key . ' ', ' ' . $dec_last  . ' ') !== false);
                                $match = $hasFirst && $hasLast;
                            }
                            if (!$match) {
                                throw new Exception('This plot is reserved for ' . $reserved_owner . '. The first deceased added must match the reserver.');
                            }
                            $matched_active_reservation = true;
                        }
                    }
                    $ownStmt->close();
                }
            }
        }

        $added_by = 'Unknown';
        if (isset($_SESSION['user_id'])) {
            $uid = intval($_SESSION['user_id']);
            if ($uRes = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = $uid")) {
                if ($uRes->num_rows > 0) {
                    $u = $uRes->fetch_assoc();
                    $fn = isset($u['first_name']) ? trim($u['first_name']) : '';
                    $ln = isset($u['last_name']) ? trim($u['last_name']) : '';
                    $added_by = trim($fn . ' ' . $ln);
                }
                $uRes->free();
            }
        }

        $has_suffix_for_dupe = false;
        if ($colResDupe = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'suffix'")) {
            $has_suffix_for_dupe = $colResDupe->num_rows > 0;
            $colResDupe->free();
        }
        if ($has_suffix_for_dupe) {
            $dupe_sql = "SELECT deceased_id FROM tbl_deceased
                          WHERE UPPER(TRIM(first_name)) = ?
                            AND UPPER(TRIM(COALESCE(middle_name, ''))) = ?
                            AND UPPER(TRIM(last_name)) = ?
                            AND UPPER(TRIM(COALESCE(suffix, ''))) = ?
                            AND bday = ?
                            AND date_died = ?
                          LIMIT 1";
            $dupe_stmt = $conn->prepare($dupe_sql);
            if ($dupe_stmt) {
                $mn = $middle_name !== '' ? $middle_name : '';
                $sx = $suffix !== '' ? $suffix : '';
                $dupe_stmt->bind_param("ssssss", $first_name, $mn, $last_name, $sx, $bday, $date_died);
                $dupe_stmt->execute();
                $dupe_res = $dupe_stmt->get_result();
                if ($dupe_res && $dupe_res->num_rows > 0) {
                    throw new Exception("Deceased person already exists with the same name and dates.");
                }
                $dupe_stmt->close();
            }
        } else {
            $dupe_sql = "SELECT deceased_id FROM tbl_deceased
                          WHERE UPPER(TRIM(first_name)) = ?
                            AND UPPER(TRIM(COALESCE(middle_name, ''))) = ?
                            AND UPPER(TRIM(last_name)) = ?
                            AND bday = ?
                            AND date_died = ?
                          LIMIT 1";
            $dupe_stmt = $conn->prepare($dupe_sql);
            if ($dupe_stmt) {
                $mn = $middle_name !== '' ? $middle_name : '';
                $dupe_stmt->bind_param("sssss", $first_name, $mn, $last_name, $bday, $date_died);
                $dupe_stmt->execute();
                $dupe_res = $dupe_stmt->get_result();
                if ($dupe_res && $dupe_res->num_rows > 0) {
                    throw new Exception("Deceased person already exists with the same name and dates.");
                }
                $dupe_stmt->close();
            }
        }

        $conn->autocommit(FALSE);

        $has_suffix_col = false;
        if ($colRes = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'suffix'")) {
            $has_suffix_col = $colRes->num_rows > 0;
            $colRes->free();
        }
        $has_added_by_col = false;
        if ($colRes2 = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'added_by'")) {
            $has_added_by_col = $colRes2->num_rows > 0;
            $colRes2->free();
        }
        if (!$has_added_by_col) {
            $conn->query("ALTER TABLE tbl_deceased ADD COLUMN added_by varchar(100) DEFAULT NULL AFTER plot_y");
            if ($colRes2b = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'added_by'")) {
                $has_added_by_col = $colRes2b->num_rows > 0;
                $colRes2b->free();
            }
        }
        $has_edited_by_col = false;
        if ($colRes3 = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'edited_by'")) {
            $has_edited_by_col = $colRes3->num_rows > 0;
            $colRes3->free();
        }
        if ($has_added_by_col && !$has_edited_by_col) {
            $conn->query("ALTER TABLE tbl_deceased ADD COLUMN edited_by varchar(100) DEFAULT NULL");
            $has_edited_by_col = true;
        }

        $checkDeceasedCols = [
            'booker_name' => "VARCHAR(150) DEFAULT NULL",
            'payment_plan' => "VARCHAR(50) DEFAULT 'Cash'",
            'payment_term' => "INT DEFAULT 0",
            'payment_status' => "VARCHAR(50) DEFAULT 'PAID'",
            'total_amount' => "DECIMAL(10,2) DEFAULT 0.00",
            'amount_paid' => "DECIMAL(10,2) DEFAULT 0.00",
            'monthly_due' => "DECIMAL(10,2) DEFAULT 0.00"
        ];
        foreach ($checkDeceasedCols as $cName => $cDef) {
            $cCheck = $conn->query("SHOW COLUMNS FROM tbl_deceased LIKE '$cName'");
            if ($cCheck && $cCheck->num_rows === 0) {
                $conn->query("ALTER TABLE tbl_deceased ADD COLUMN `$cName` $cDef");
            }
            if ($cCheck) $cCheck->free();
        }

        $isAlreadyPurchased = false;
        $existingPurchasedBooking = null;
        $tpStatusCheck = $conn->prepare("SELECT status FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
        if ($tpStatusCheck) {
            $tpStatusCheck->bind_param('sii', $section, $plot_x, $plot_y);
            $tpStatusCheck->execute();
            $tpStRes = $tpStatusCheck->get_result();
            if ($tpStRes && ($stRow = $tpStRes->fetch_assoc())) {
                if (strcasecmp(trim($stRow['status']), 'purchased') === 0) {
                    $isAlreadyPurchased = true;
                }
            }
            $tpStatusCheck->close();
        }

        $plotHyphen = $plot_x . '-' . $plot_y;
        $plotPad6 = sprintf('%03d%03d', $plot_x, $plot_y);
        $bkCheck = $conn->prepare("SELECT * FROM tbl_bookings 
            WHERE UPPER(TRIM(section)) = ? 
              AND (plot = ? OR plot = ?) 
              AND LOWER(TRIM(status)) IN ('approved', 'confirmed', 'reserved', 'completed') 
              AND LOWER(TRIM(COALESCE(service_type, ''))) NOT IN ('cleaning', 'landscaping', 'maintenance', 'decoration')
              AND LOWER(TRIM(COALESCE(purpose, ''))) != 'cemetery_service'
              AND total_amount >= 10000
            ORDER BY id DESC LIMIT 1");
        if ($bkCheck) {
            $bkCheck->bind_param('sss', $section, $plotHyphen, $plotPad6);
            $bkCheck->execute();
            $bkRes = $bkCheck->get_result();
            $existingPurchasedBooking = $bkRes ? $bkRes->fetch_assoc() : null;
            if ($existingPurchasedBooking && ($existingPurchasedBooking['service_type'] === 'plot_purchase' || $existingPurchasedBooking['purpose'] === 'plot_purchase')) {
                $isAlreadyPurchased = true;
            }
            $bkCheck->close();
        }

        // If no purchase booking found in tbl_bookings, check if this plot has an existing occupant (interment layering / patong)
        if (!$existingPurchasedBooking) {
            $occQ = $conn->prepare("SELECT booker_name, payment_plan, payment_term, payment_status, total_amount, amount_paid, monthly_due 
                FROM tbl_deceased 
                WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND total_amount >= 10000 
                ORDER BY deceased_id ASC LIMIT 1");
            if ($occQ) {
                $occQ->bind_param('sii', $section, $plot_x, $plot_y);
                $occQ->execute();
                $occRes = $occQ->get_result();
                if ($occRes && ($occRow = $occRes->fetch_assoc())) {
                    $existingPurchasedBooking = [
                        'customer_name' => $occRow['booker_name'],
                        'payment_plan' => $occRow['payment_plan'],
                        'payment_term' => $occRow['payment_term'],
                        'payment_status' => $occRow['payment_status'],
                        'total_amount' => $occRow['total_amount'],
                        'amount_paid' => $occRow['amount_paid'],
                        'monthly_due' => $occRow['monthly_due'],
                        'is_from_occupant' => true
                    ];
                    $isAlreadyPurchased = true;
                }
                $occQ->close();
            }
        }

        $booker_name = strtoupper(trim($_POST['booker_name'] ?? ''));
        if ($booker_name === '') {
            $booker_name = ($existingPurchasedBooking && !empty($existingPurchasedBooking['customer_name'])) ? $existingPurchasedBooking['customer_name'] : $full_name;
        }
        $payment_plan = ucfirst(strtolower(trim($_POST['payment_plan'] ?? ($existingPurchasedBooking['payment_plan'] ?? 'Cash'))));
        if (!in_array($payment_plan, ['Cash', 'Installment'])) {
            $payment_plan = 'Cash';
        }
        $payment_term = ($payment_plan === 'Installment') ? max(1, intval($_POST['payment_term'] ?? ($existingPurchasedBooking['payment_term'] ?? 12))) : 0;
        
        $posted_total = floatval($_POST['total_amount'] ?? 0);
        if ($posted_total >= 10000) {
            $total_amount = $posted_total;
        } elseif (!empty($existingPurchasedBooking['total_amount']) && floatval($existingPurchasedBooking['total_amount']) >= 10000) {
            $total_amount = floatval($existingPurchasedBooking['total_amount']);
        } else {
            $isCorner = ((intval($plot_x) == 1 || intval($plot_x) == 6 || intval($plot_x) == 4) && (intval($plot_y) == 1 || intval($plot_y) == 37 || intval($plot_y) == 28));
            $total_amount = $isCorner ? 89000.00 : 85000.00;
        }

        $posted_paid = floatval($_POST['amount_paid'] ?? 0);
        if ($posted_paid >= 10000) {
            $amount_paid = $posted_paid;
        } elseif (!empty($existingPurchasedBooking['amount_paid']) && floatval($existingPurchasedBooking['amount_paid']) >= 10000) {
            $amount_paid = floatval($existingPurchasedBooking['amount_paid']);
        } else {
            $amount_paid = 0.00;
        }

        $payment_status = strtoupper(trim($_POST['payment_status'] ?? ($existingPurchasedBooking['payment_status'] ?? 'PAID')));
        if ($payment_status !== 'HAS DUES') {
            $payment_status = 'PAID';
        }
        if ($payment_status === 'PAID' && $amount_paid < $total_amount && $total_amount > 0) {
            $amount_paid = $total_amount;
        }
        $monthly_due = ($payment_plan === 'Installment' && $payment_term > 0) ? round($total_amount / $payment_term, 2) : 0.00;

        if ($has_suffix_col && $has_added_by_col && $has_edited_by_col) {
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

        $pType = ($payment_plan === 'Installment') ? 'Installment' : 'Cash on Site';
        $bkStatus = ($payment_status === 'PAID') ? 'approved' : 'approved';

        if ($existingPurchasedBooking) {

            $bId = (int)$existingPurchasedBooking['id'];
            $upBk = $conn->prepare("UPDATE tbl_bookings SET deceased_name = ?, deceased_id = ?, service_type = 'burial', status = 'approved' WHERE id = ?");
            if ($upBk) {
                $upBk->bind_param('sii', $full_name, $deceased_id, $bId);
                $upBk->execute();
                $upBk->close();
            }
        } else {

            $insBk = $conn->prepare("INSERT INTO tbl_bookings (service_type, grave_location, plot_type, section, plot, section_plot, plot_price, preferred_date, contact_number, booker_location, payment_type, amount_paid, status, user_id, deceased_id, customer_name, full_name, deceased_name, purpose, payment_plan, payment_term, total_amount, booking_type, created_at) VALUES ('burial', ?, 'Lawn', ?, ?, ?, ?, ?, ?, 'Memorial Park Office', ?, ?, ?, ?, ?, ?, ?, ?, 'add_deceased', ?, ?, ?, 'existing_plot_service', NOW())");
            if (!$insBk) {
                if (function_exists('ensure_booking_table_integrity')) {
                    ensure_booking_table_integrity($conn);
                }
                $insBk = $conn->prepare("INSERT INTO tbl_bookings (service_type, grave_location, plot_type, section, plot, section_plot, plot_price, preferred_date, contact_number, booker_location, payment_type, amount_paid, status, user_id, deceased_id, customer_name, full_name, deceased_name, purpose, payment_plan, payment_term, total_amount, booking_type, created_at) VALUES ('burial', ?, 'Lawn', ?, ?, ?, ?, ?, ?, 'Memorial Park Office', ?, ?, ?, ?, ?, ?, ?, ?, 'add_deceased', ?, ?, ?, 'existing_plot_service', NOW())");
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

        if (function_exists('ensure_user_plot_ownership_table')) {
            ensure_user_plot_ownership_table($conn);
        }
        $cUserId = (int)($_SESSION['user_id'] ?? 0);
        $cRole = strtoupper(trim((string)($_SESSION['role'] ?? '')));

        $updExisting = $conn->prepare("UPDATE user_plot_ownership SET ownership_status = 'OWNED', deceased_reference = ?, updated_at = NOW() WHERE UPPER(TRIM(section)) = UPPER(TRIM(?)) AND plot_x = ? AND plot_y = ? AND verification_status = 'VERIFIED'");
        if ($updExisting) {
            $updExisting->bind_param('ssii', $full_name, $section, $plot_x, $plot_y);
            $updExisting->execute();
            $updExisting->close();
        }

        if ($cRole !== 'ADMIN' && $cUserId > 0) {
            $upoOwned = $conn->prepare("INSERT INTO user_plot_ownership (user_id, section, plot_x, plot_y, ownership_status, verification_status, claim_source, deceased_reference, verified_at) VALUES (?, ?, ?, ?, 'OWNED', 'VERIFIED', 'CUSTOMER_REGISTRATION', ?, NOW()) ON DUPLICATE KEY UPDATE ownership_status = 'OWNED', verification_status = 'VERIFIED', deceased_reference = ?, updated_at = NOW()");
            if ($upoOwned) {
                $upoOwned->bind_param('isiiss', $cUserId, $section, $plot_x, $plot_y, $full_name, $full_name);
                $upoOwned->execute();
                $upoOwned->close();
            }
        }

        if ($matched_active_reservation) {
            if ($deact = $conn->prepare("UPDATE tbl_plot_owners SET active = 0 WHERE section = ? AND plot_x = ? AND plot_y = ? AND active = 1")) {
                $deact->bind_param('sii', $section, $plot_x, $plot_y);
                $deact->execute();
                $deact->close();
            }
        }

        $log_sql = "INSERT INTO tbl_logs (action, details, timestamp, user_id) VALUES (?, ?, NOW(), ?)";
        $log_stmt = $conn->prepare($log_sql);

        if ($log_stmt) {
            $action = "ADD_DECEASED";
            $details = "Added deceased person: " . $full_name . " to Section " . $section . ", Plot " . $plot_x . "-" . $plot_y . ($isAlreadyPurchased ? " (Assigned to purchased plot)" : "");
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

            $log_stmt->bind_param("ssi", $action, $details, $user_id);
            $log_stmt->execute();
            $log_stmt->close();
        }

        $conn->commit();
        $conn->autocommit(TRUE);

        @ob_clean();
        echo json_encode([
            'success' => true,
            'message' => $isAlreadyPurchased ? 'Deceased person successfully assigned to purchased plot!' : 'Deceased person added successfully!'
        ]);

        $insert_stmt->close();
        if (isset($count_stmt) && $count_stmt) { $count_stmt->close(); }

    } catch (Exception $e) {

        if (isset($conn)) {
            $conn->rollback();
            $conn->autocommit(TRUE);
        }

        error_log("Add deceased error: " . $e->getMessage());

        @ob_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    } finally {
        if (isset($conn)) {
            $conn->close();
        }
        exit;
    }
}

$section = isset($_GET['section']) ? htmlspecialchars($_GET['section']) : ''; 
$plot_x = isset($_GET['plot_x']) ? htmlspecialchars($_GET['plot_x']) : ''; 
$plot_y = isset($_GET['plot_y']) ? htmlspecialchars($_GET['plot_y']) : ''; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Section Map Popup</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <!-- IE11 / Edge-Legacy polyfills (URLSearchParams, fetch, Promise, etc.) -->
  <script src="../includes/ie_polyfills.js"></script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
  // Global modal replacement for native browser alert to eliminate "localhost says"
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
        } else if (/success|completed|updated|booked|added/i.test(text)) {
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

  window.CURRENT_USER_ID = <?php echo json_encode((int)($_SESSION['user_id'] ?? 0)); ?>;
  window.CURRENT_USER_ROLE = <?php echo json_encode(strtoupper(trim((string)($_SESSION['role'] ?? (!empty($_SESSION['user_id']) ? 'USER' : 'GUEST'))))); ?>;

  // Purge obsolete/legacy shared keys from localStorage to prevent cross-account leakage
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

  function phpEscape(s){ return String(s == null ? '' : s); }
  window.phpEscape = phpEscape;
  function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value).replace(/[&<>"']/g, function(m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
  }
  window.escapeHtml = escapeHtml;
  // Resolve canonical popup endpoint path for AJAX requests early so all functions
  // can access it without risk of ReferenceError when called before later code.
  window.__baseWebPath = (window.location && window.location.pathname && String(window.location.pathname).indexOf('/sections/') !== -1) ? '../' : '';
  window.popupPath = window.popupPath || ((window.location.pathname.indexOf('popup.php') !== -1) 
    ? window.location.pathname 
    : ((window.location.pathname.indexOf('/sections/') !== -1) ? 'popup.php' : 'sections/popup.php'));
  var popupPath = window.popupPath;
  window.applyReservedStyling = window.applyReservedStyling || function() {
    if (typeof window.loadReservedPlots === 'function') {
      window.loadReservedPlots();
    }
  };

  function saveUserRecentlyClicked(section, plotX, plotY, graveName) {
    try {
      if (!section || !plotX || !plotY) return;
      section = String(section).trim().toUpperCase();
      plotX = String(plotX).trim();
      plotY = String(plotY).trim();
      var name = graveName ? String(graveName).trim() : ('Plot ' + section + '-' + plotX + '-' + plotY);
      var details = 'Clicked plot ' + section + '-' + plotX + '-' + plotY + (graveName ? (' (' + name + ')') : '');

      var uid = (window.CURRENT_USER_ID && parseInt(window.CURRENT_USER_ID, 10) > 0) ? parseInt(window.CURRENT_USER_ID, 10) : 0;
      var storageKey = 'cmis_user_recently_clicked_' + uid;
      var list = [];
      try {
        list = JSON.parse(localStorage.getItem(storageKey) || '[]');
      } catch(e) { list = []; }
      if (!Array.isArray(list)) list = [];

      list = list.filter(function(item){
        return !(item.section === section && String(item.plot_x) === plotX && String(item.plot_y) === plotY);
      });

      list.unshift({
        section: section,
        plot_x: plotX,
        plot_y: plotY,
        grave_name: name,
        details: details,
        clicked_at: new Date().toISOString()
      });

      if (list.length > 20) list.pop();
      try {
        localStorage.setItem(storageKey, JSON.stringify(list));
      } catch(e) {}

      var endpoint = (window.location.pathname.indexOf('/sections/') !== -1) ? '../map.php' : 'map.php';
      var fd = new URLSearchParams();
      fd.append('ajax_request', 'log_user_recent_click');
      fd.append('section', section);
      fd.append('plot_x', plotX);
      fd.append('plot_y', plotY);
      if (graveName) fd.append('grave_name', name);
      fd.append('details', details);

      if (navigator.sendBeacon) {
        var blob = new Blob([fd.toString()], { type: 'application/x-www-form-urlencoded' });
        navigator.sendBeacon(endpoint, blob);
      } else {
        fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: fd.toString(),
          keepalive: true
        }).catch(function(){});
      }
    } catch(e) {}
  }

  function saveAdminRecentlyClicked(section, plotX, plotY, graveName) {
    try {
      if (!section || !plotX || !plotY) return;
      section = String(section).trim().toUpperCase();
      plotX = String(plotX).trim();
      plotY = String(plotY).trim();
      var name = graveName ? String(graveName).trim() : ('Plot ' + section + '-' + plotX + '-' + plotY);
      var details = 'Clicked plot ' + section + '-' + plotX + '-' + plotY + (graveName ? (' (' + name + ')') : '');

      var uid = (window.CURRENT_USER_ID && parseInt(window.CURRENT_USER_ID, 10) > 0) ? parseInt(window.CURRENT_USER_ID, 10) : 0;
      var storageKey = 'cmis_admin_recently_clicked_' + uid;
      var list = [];
      try {
        list = JSON.parse(localStorage.getItem(storageKey) || '[]');
      } catch(e) { list = []; }
      if (!Array.isArray(list)) list = [];

      list = list.filter(function(item){
        return !(item.section === section && String(item.plot_x) === plotX && String(item.plot_y) === plotY);
      });

      list.unshift({
        section: section,
        plot_x: plotX,
        plot_y: plotY,
        grave_name: name,
        details: details,
        clicked_at: new Date().toISOString()
      });

      if (list.length > 20) list.pop();
      try {
        localStorage.setItem(storageKey, JSON.stringify(list));
      } catch(e) {}

      var endpoint = (window.location.pathname.indexOf('/sections/') !== -1) ? '../map.php' : 'map.php';
      var fd = new URLSearchParams();
      fd.append('ajax_request', 'log_admin_recent_click');
      fd.append('section', section);
      fd.append('plot_x', plotX);
      fd.append('plot_y', plotY);
      if (graveName) fd.append('grave_name', name);
      fd.append('details', details);

      if (navigator.sendBeacon) {
        var blob = new Blob([fd.toString()], { type: 'application/x-www-form-urlencoded' });
        navigator.sendBeacon(endpoint, blob);
      } else {
        fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: fd.toString(),
          keepalive: true
        }).catch(function(){});
      }
    } catch(e) {}
  }

  window.recordRecentlyClicked = function(section, plotX, plotY, graveName) {
    if (window.CURRENT_USER_ROLE === 'ADMIN') {
      saveAdminRecentlyClicked(section, plotX, plotY, graveName);
    } else {
      saveUserRecentlyClicked(section, plotX, plotY, graveName);
    }
  };
  function recordRecentlyClicked(section, plotX, plotY, graveName) {
    window.recordRecentlyClicked(section, plotX, plotY, graveName);
  }

  var currentPlotOnly = false;
  var currentPlotX = '';
  var currentPlotY = '';
  window.__bookingMode = <?php echo !empty($bookingMode) ? 'true' : 'false'; ?>;
  var urlPTransferCheck = new URLSearchParams(window.location.search);
  var isTransferModeFromUrl = (urlPTransferCheck.get('selection_mode') === 'transfer') || (urlPTransferCheck.get('transfer_mode') === '1');
  window.__transferMode = Boolean(<?php echo !empty($isTransferMode) ? 'true' : 'false'; ?> || isTransferModeFromUrl);
  window.__transferTargetUserId = <?php echo (int)$transferTargetUserId; ?>;
  window.__transferDeceasedId = <?php echo (int)$transferDeceasedId; ?>;

  document.addEventListener('DOMContentLoaded', function(){
    var sectionName = <?php echo json_encode($section ?? ''); ?>;
    if (!sectionName) {
      try {
        sectionName = (new URL(window.location.href)).searchParams.get('section') || '';
      } catch (e) {
        sectionName = '';
      }
    }
    sectionName = String(sectionName).toUpperCase();
    var bookingMode = <?php echo !empty($bookingMode) ? 'true' : 'false'; ?>;
    currentPlotOnly = (new URLSearchParams(window.location.search)).get('current_plot_only') === '1';
    currentPlotX = (new URLSearchParams(window.location.search)).get('current_plot_x') || '';
    currentPlotY = (new URLSearchParams(window.location.search)).get('current_plot_y') || '';
    // Expose booking mode globally so plot handlers (attachPlotHandlers, delegated
    // click listener) can distinguish the booking selection flow from the normal
    // Cemetery Map flow. In booking mode, clicking a plot must NOT open the
    // Deceased Information modal.
    window.__bookingMode = bookingMode;
    window.__isAdmin = <?php echo !empty($isAdmin) ? 'true' : 'false'; ?>;
    var popupPath = window.popupPath || window.location.pathname;

    // Plot buttons use the single delegated handler below, including booking mode.
    if (typeof applyReservedStyling === 'function') {
      applyReservedStyling();
    } else if (typeof window.applyReservedStyling === 'function') {
      window.applyReservedStyling();
    }
  });

  function loadPlotBookingStatus(section, plotX, plotY) {
    recordRecentlyClicked(section, plotX, plotY, null);
    var body = new URLSearchParams();
    body.set('ajax_request', 'get_plot_booking_status');
    body.set('section', section);
    body.set('plot_x', plotX);
    body.set('plot_y', plotY);

    var urlParams = new URLSearchParams(window.location.search);
    var isParentAdmin = false;
    try {
      if (window.parent && (window.parent.__isAdmin || (window.parent.location && window.parent.location.pathname.indexOf('dashboard.php') !== -1))) {
        isParentAdmin = true;
      }
    } catch(e) {}
    if (isParentAdmin || urlParams.get('source') === 'admin' || urlParams.get('is_admin') === '1' || window.__isAdmin) {
      body.set('is_admin', '1');
      body.set('source', 'admin');
    }
    fetch(popupPath, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
      credentials: 'same-origin',
      body: body.toString()
    }).then(function(response) {
      return response.text().then(function(text) {
        var data = null;
        try { data = JSON.parse(text); } catch(e) {}
        if (!response.ok) {
          if (data && data.message) {
            throw new Error(data.message);
          }
          if (response.status === 401) throw new Error('Please log in to access plot information.');
          if (response.status === 403) throw new Error('You do not have permission to access this plot.');
          if (response.status === 404) throw new Error('Requested plot does not exist.');
          throw new Error('Server error (' + response.status + '). Please try again.');
        }
        return data;
      });
    }).then(function(data) {
      if (!data || !data.success) throw new Error((data && data.message) || 'Could not load plot status.');
      showPlotBookingStatusPopup(data);
    }).catch(function(error) {
      console.error('Plot status fetch error', error);
      var message = error.message || 'Could not load plot status.';
      if (typeof Swal !== 'undefined') Swal.fire({icon: 'error', title: 'Error', text: message});
      else alert(message);
    });
  }

function showPlotBookingStatusPopup(data) {
    var statusRaw = data.status || 'Available';
    var statusNorm = String(statusRaw).trim().toUpperCase();
    var section = data.section || '';
    var plotX = data.plot_x;
    var plotY = data.plot_y;
    var plotNo = (section && plotX && plotY) ? (section + '- ' + plotX + ',' + plotY) : (data.plot_no || ((plotX && plotY) ? (plotX + ',' + plotY) : ''));
    var lawnType = data.lawn_type || 'Lawn (Regular)';
    var plotType = data.plot_type || 'Lawn';
    var lotPriceNum = Number(data.lot_price !== undefined ? data.lot_price : (data.price || 85000));
    var intermentFeeNum = Number(data.interment_fee !== undefined ? data.interment_fee : 38000);
    var totalAmountNum = Number(data.total_price !== undefined ? data.total_price : (lotPriceNum + intermentFeeNum));
    var depositNum = Number(data.reservation_deposit !== undefined ? data.reservation_deposit : (lotPriceNum * 0.05));

    var lotPriceFmt = '₱' + lotPriceNum.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    var intermentFeeFmt = '₱' + intermentFeeNum.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    var totalFmt = '₱' + totalAmountNum.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    var depositFmt = '₱' + depositNum.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    var price = lotPriceFmt;
    var intermentFee = intermentFeeNum;
    var occupantCount = Number(data.occupant_count || 0);
    var maxOccupants = Number(data.max_occupants || 4);
    var fullCapacity = (occupantCount >= maxOccupants);

    var urlParams = new URLSearchParams(window.location.search);
    var isAdminUser = !!(data.is_admin || window.IS_ADMIN || window.__isAdmin || (urlParams.get('source') === 'admin') || (urlParams.get('is_admin') === '1'));
    try {
      if (!isAdminUser && window.parent && (window.parent.__isAdmin || (window.parent.location && window.parent.location.pathname.indexOf('dashboard.php') !== -1))) {
        isAdminUser = true;
      }
    } catch(e) {}

    // In transfer mode, does the destination plot belong to the customer whose deceased is being transferred?
    var isPlotOwnedByCustomer = false;
    var targetUid = window.__transferTargetUserId || 0;
    if (targetUid > 0) {
      if (data.user_id && Number(data.user_id) === Number(targetUid)) {
        isPlotOwnedByCustomer = true;
      }
      if (data.reservation && data.reservation.user_id && Number(data.reservation.user_id) === Number(targetUid)) {
        isPlotOwnedByCustomer = true;
      }
      if (data.deceased && Array.isArray(data.deceased)) {
        for (var di = 0; di < data.deceased.length; di++) {
          if (data.deceased[di].user_id && Number(data.deceased[di].user_id) === Number(targetUid)) {
            isPlotOwnedByCustomer = true;
            break;
          }
        }
      }
    }
    try {
      if (!isPlotOwnedByCustomer && window.parent && Array.isArray(window.parent.userOwnedPlotsKeys)) {
        var coordKey = String(section).toUpperCase() + '_' + plotX + '_' + plotY;
        if (window.parent.userOwnedPlotsKeys.indexOf(coordKey) !== -1) {
          isPlotOwnedByCustomer = true;
        }
      }
    } catch(e) {}

    // In transfer mode, authorization requires strict customer ownership - NO admin bypass.
    var isAuthorized = window.__transferMode
      ? isPlotOwnedByCustomer
      : !!(data.is_user_authorized || data.is_admin || isAdminUser || isPlotOwnedByCustomer);

    var icon = 'question';
    var color = '#0d6efd';
    var html = '';
    var modalTitle = 'Plot ' + phpEscape(plotNo);
    var isBookingMode = !!(window.__bookingMode);
    var canSelect = false;
    var showCancel = true;
    var confirmText = 'Book Now';
    var confirmColor = '#7b1113';
    var cancelText = 'Close';
    var isUnavailableSection = ['G','H','I','J'].includes(String(section).toUpperCase());

    if (isUnavailableSection || statusNorm === 'UNAVAILABLE') {
        icon = 'info';
        color = '#6c757d';
        modalTitle = 'Section ' + phpEscape(section) + ' Unavailable';
        html = '<div style="text-align:center;padding:10px 0;">'
            + '<div style="font-size:0.95rem;color:#6c757d;font-weight:600;margin-bottom:8px;">Section ' + phpEscape(section) + ' is currently not open for selection.</div>'
            + '<div style="font-size:0.85rem;color:#888;">Please choose an available section on the cemetery map.</div>'
            + '</div>';
        canSelect = false;
        showCancel = false;
        confirmText = 'Choose Another Plot';
        confirmColor = '#6c757d';
    } else if (statusNorm === 'RESERVED' || statusNorm === 'OWNED' || statusNorm === 'CLAIMED' || Boolean(data.reservation && data.reservation.owner_name)) {
        if (window.__transferMode && !isAuthorized) {
            // Plot transfer mode: cannot select a reserved plot that is not registered to the account
            icon = 'warning';
            color = '#dc3545';
            modalTitle = 'Plot Not Allowed for Transfer';
            html = '<div style="text-align:center;padding:4px 0;">'
                + '<div style="background:#fff3cd;border:1px solid #ffeeba;border-radius:10px;padding:12px 14px;margin-bottom:12px;color:#856404;font-size:0.95rem;text-align:left;">'
                + '<i class="fas fa-bookmark me-2 text-warning"></i>'
                + '<strong>Section ' + phpEscape(section) + ' • Plot ' + phpEscape(plotX) + '-' + phpEscape(plotY) + '</strong> is reserved under another client\'s account.'
                + '</div>'
                + '<div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:10px;padding:12px 14px;color:#721c24;font-size:0.9rem;line-height:1.5;text-align:left;">'
                + '<i class="fas fa-ban me-2 text-danger"></i>'
                + '<strong>You cannot select this plot.</strong> This plot is not owned by your account. For remains transfer, you may only select an <strong>available (vacant) plot</strong> or a plot registered under your account.'
                + '</div>'
                + '</div>';
            canSelect = false;
            showCancel = false;
            confirmText = 'OK';
            confirmColor = '#7b1113';
        } else if (!isAuthorized && !isAdminUser) {
            // Plot is reserved by someone else - block selection and display prominent warning
            icon = 'warning';
            color = '#dc3545';
            modalTitle = 'Plot Reserved by Someone Else';
            html = '<div style="text-align:center;padding:4px 0;">'
                + '<div style="background:#fff3cd;border:1px solid #ffeeba;border-radius:10px;padding:12px 14px;margin-bottom:12px;color:#856404;font-size:0.95rem;text-align:left;">'
                + '<i class="fas fa-bookmark me-2 text-warning"></i>'
                + '<strong>Section ' + phpEscape(section) + ' • Plot ' + phpEscape(plotX) + '-' + phpEscape(plotY) + '</strong> is currently reserved under another client\'s account.'
                + '</div>'
                + '<div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:10px;padding:12px 14px;color:#721c24;font-size:0.9rem;line-height:1.5;text-align:left;">'
                + '<i class="fas fa-ban me-2 text-danger"></i>'
                + '<strong>You cannot select this plot.</strong> This plot is reserved and does not belong to your account.'
                + '</div>'
                + '</div>';
            canSelect = false;
            showCancel = false;
            confirmText = 'OK';
            confirmColor = '#7b1113';
        } else {
            // Authorized owner or Admin
            icon = 'info';
            color = '#0d6efd';
            modalTitle = 'Plot ' + phpEscape(plotNo) + (isAdminUser ? '' : ' (Your Reserved Plot)');
            var resOwner = (data.reservation && data.reservation.owner_name) ? data.reservation.owner_name : 'Reserved';
            var resDetails = '';
            if (data.reservation && (data.is_admin || isAdminUser || data.is_user_authorized || isAuthorized)) {
                resDetails = '<div style="background:#fdfaf3;border:1px solid #f0e6d2;border-radius:8px;padding:8px 10px;margin-bottom:8px;text-align:left;font-size:0.85rem;">'
                    + '<div style="font-weight:600;color:#7b1113;margin-bottom:3px;">📋 Reservation Details</div>'
                    + '<div><strong>Reserved For:</strong> ' + phpEscape(resOwner) + '</div>'
                    + (data.reservation.phone_no ? '<div><strong>Contact:</strong> ' + phpEscape(data.reservation.phone_no) + '</div>' : '')
                    + '</div>';
            }
            html = '<div style="text-align:center;">'
                + '<div style="font-size:0.95rem;color:#b8860b;font-weight:600;margin-bottom:8px;">🟡 This plot is reserved.</div>'
                + resDetails
                + '<div style="font-size:0.85rem;color:#555;">' + (isAdminUser ? 'As administrator, you can service or reserve on this reserved plot.' : 'This plot is reserved under your account. You can select it for cemetery service.') + '</div>'
                + '</div>';
            if (window.__transferMode) {
                if (isAuthorized) {
                    canSelect = true;
                    showCancel = true;
                    confirmText = 'Select Your Reserved Plot';
                    confirmColor = '#7b1113';
                } else {
                    canSelect = false;
                    showCancel = false;
                    confirmText = 'OK';
                    confirmColor = '#7b1113';
                }
            } else if (isBookingMode) {
                canSelect = true;
                showCancel = true;
                confirmText = 'Select for Service / Booking';
                confirmColor = '#7b1113';
            } else {
                canSelect = false;
                showCancel = false;
                confirmText = 'Close';
                confirmColor = '#6c757d';
            }
        }
    } else if (statusNorm === 'OCCUPIED') {
        if (window.__transferMode && !isAuthorized) {
            // Plot transfer mode: cannot select an occupied plot belonging to another family
            icon = 'warning';
            color = '#dc3545';
            modalTitle = 'Plot Not Allowed for Transfer';
            html = '<div style="text-align:center;padding:4px 0;">'
                + '<div style="background:#fff3cd;border:1px solid #ffeeba;border-radius:10px;padding:12px 14px;margin-bottom:12px;color:#856404;font-size:0.95rem;text-align:left;">'
                + '<i class="fas fa-monument me-2 text-warning"></i>'
                + '<strong>Section ' + phpEscape(section) + ' • Plot ' + phpEscape(plotX) + '-' + phpEscape(plotY) + '</strong> is occupied and owned by another family.'
                + '</div>'
                + '<div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:10px;padding:12px 14px;color:#721c24;font-size:0.9rem;line-height:1.5;text-align:left;">'
                + '<i class="fas fa-ban me-2 text-danger"></i>'
                + '<strong>You cannot select this plot.</strong> This plot is not registered under your account. For remains transfer, you may only select an <strong>available (vacant) plot</strong> or a plot registered under your account.'
                + '</div>'
                + '</div>';
            canSelect = false;
            showCancel = false;
            confirmText = 'OK';
            confirmColor = '#7b1113';
        } else if (!isAuthorized && !isAdminUser) {
            // Occupied by someone else - block selection and display prominent warning
            icon = 'warning';
            color = '#dc3545';
            modalTitle = 'Plot Owned by Another Family';
            html = '<div style="text-align:center;padding:4px 0;">'
                + '<div style="background:#fff3cd;border:1px solid #ffeeba;border-radius:10px;padding:12px 14px;margin-bottom:12px;color:#856404;font-size:0.95rem;text-align:left;">'
                + '<i class="fas fa-monument me-2 text-warning"></i>'
                + '<strong>Section ' + phpEscape(section) + ' • Plot ' + phpEscape(plotX) + '-' + phpEscape(plotY) + '</strong> is occupied and owned by another family.'
                + '</div>'
                + '<div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:10px;padding:12px 14px;color:#721c24;font-size:0.9rem;line-height:1.5;text-align:left;">'
                + '<i class="fas fa-ban me-2 text-danger"></i>'
                + '<strong>You cannot select this plot.</strong> This plot is not registered under your account.'
                + '</div>'
                + '</div>';
            canSelect = false;
            showCancel = false;
            confirmText = 'OK';
            confirmColor = '#7b1113';
        } else {
            // Authorized owner or Admin
            modalTitle = 'Plot ' + phpEscape(plotNo) + (isAdminUser ? '' : ' (Your Registered Plot)');
            if (fullCapacity) {
                icon = 'warning';
                color = '#ffc107';
            } else {
                icon = 'info';
                color = '#0d6efd';
            }
            var dec = data.deceased || [];
            var decRows = '';
            if (dec.length) {
                decRows = dec.map(function(d, i){
                    var nm = d.full_name || 'Unknown';
                    var dob = d.formatted_birth_date || '—';
                    var dod = d.formatted_death_date || '—';
                    var inter = d.interment_date || '—';
                    return '<div style=\"background:#fff8f8;border:1px solid #f2caca;border-radius:8px;padding:8px 10px;margin-bottom:6px;text-align:left;\">'
                        + '<div style=\"font-weight:600;color:#7b1113;\">' + (i+1) + '. ' + phpEscape(nm) + '</div>'
                        + '<div style=\"font-size:0.85rem;color:#555;\">Birth: ' + phpEscape(dob) + '</div>'
                        + '<div style=\"font-size:0.85rem;color:#555;\">Death: ' + phpEscape(dod) + '</div>'
                        + '<div style=\"font-size:0.85rem;color:#555;\">Interment: ' + phpEscape(inter) + '</div>'
                        + '</div>';
                }).join('');
            }
            html = '<div style=\"text-align:center;\">'
                + '<div style=\"font-size:0.95rem;color:#b8860b;font-weight:600;margin-bottom:8px;\">🟡 This plot is currently occupied.</div>'
                + '<div style=\"font-size:0.9rem;color:#555;margin-bottom:8px;\">Occupants: ' + occupantCount + '/' + maxOccupants + ' (Min 2, Max 4 Deceased)</div>'
                + decRows;
            if (fullCapacity) {
                html += '<div style=\"font-size:0.9rem;color:#dc3545;font-weight:600;margin-top:8px;\">Maximum capacity reached (' + occupantCount + '/' + maxOccupants + '). This grave cannot accept another deceased.</div>'
                    + '<div style=\"font-size:0.85rem;color:#666;margin-top:4px;\">You may still request a cemetery service for this grave.</div>';
            } else {
                html += '<div style=\"font-size:0.9rem;color:#198754;font-weight:600;margin-top:8px;\">This grave can still accept additional deceased (' + occupantCount + '/' + maxOccupants + '). Capacity: Min 2, Max 4 deceased.</div>';
            }
            html += '</div>';

            if (window.__transferMode) {
                if (isAuthorized && !fullCapacity) {
                    canSelect = true;
                    showCancel = true;
                    confirmText = 'Select Your Existing Plot';
                    confirmColor = '#7b1113';
                } else {
                    canSelect = false;
                    showCancel = false;
                    confirmText = 'OK';
                    confirmColor = '#7b1113';
                }
            } else if (isBookingMode) {
                canSelect = true;
                showCancel = true;
                if (fullCapacity) {
                    confirmText = 'Select for Service';
                    confirmColor = '#0d6efd';
                } else {
                    confirmText = isAdminUser ? 'Select for Booking' : 'Select for Service / Interment';
                    confirmColor = '#7b1113';
                }
            } else {
                canSelect = false;
                showCancel = false;
                confirmText = 'Close';
                confirmColor = '#6c757d';
            }
        }
    } else {
        // AVAILABLE
        icon = 'success';
        color = '#198754';
        modalTitle = 'Plot ' + phpEscape(plotNo);

        // Build monthly amortization list if available
        var amortHtml = '';
        var amortObj = data.amortization;
        if (amortObj && typeof amortObj === 'object') {
            var amortItems = [];
            for (var m in amortObj) {
                if (amortObj.hasOwnProperty(m)) {
                    var item = amortObj[m];
                    var mFee = '₱' + Number(item.monthly_payment || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    amortItems.push('<span style=\"display:inline-block;padding:2px 8px;margin:2px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:4px;font-size:0.8rem;\"><strong>' + m + ' mos:</strong> ' + mFee + '/mo</span>');
                }
            }
            if (amortItems.length) {
                amortHtml = '<div style=\"margin-top:8px;padding-top:8px;border-top:1px dashed #ced4da;text-align:left;\">'
                    + '<div style=\"font-size:0.8rem;font-weight:600;color:#555;margin-bottom:3px;\">Monthly Amortization (No Downpayment):</div>'
                    + '<div style=\"display:flex;flex-wrap:wrap;gap:2px;\">' + amortItems.join('') + '</div>'
                    + '</div>';
            }
        }

        html = '<div style=\"text-align:center;\">'
            + '<div style=\"font-size:1rem;font-weight:600;color:#198754;margin-bottom:10px;\">🟢 This plot is available for booking.</div>'
            + '<table style=\"margin:0 auto;font-size:0.9rem;text-align:left;width:100%;max-width:340px;\">'
            + '<tr><td style=\"padding:4px 12px 4px 0;color:#555;\">Section:</td><td style=\"padding:4px 0;font-weight:600;\">' + phpEscape(section) + '</td></tr>'
            + '<tr><td style=\"padding:4px 12px 4px 0;color:#555;\">Plot Number:</td><td style=\"padding:4px 0;font-weight:600;\">' + phpEscape(plotNo) + '</td></tr>'
            + '<tr><td style=\"padding:4px 12px 4px 0;color:#555;\">Plot Type:</td><td style=\"padding:4px 0;font-weight:600;color:#0d6efd;\">' + phpEscape(plotType) + ' (' + phpEscape(lawnType) + ')</td></tr>'
            + '<tr><td style=\"padding:4px 12px 4px 0;color:#555;\">Lot Price:</td><td style=\"padding:4px 0;font-weight:700;color:#198754;\">' + phpEscape(lotPriceFmt) + '</td></tr>'
            + '<tr><td style=\"padding:4px 12px 4px 0;color:#555;\">Interment Fee:</td><td style=\"padding:4px 0;font-weight:600;color:#333;\">' + phpEscape(intermentFeeFmt) + '</td></tr>'
            + '<tr style=\"border-top:1px solid #eee;\"><td style=\"padding:6px 12px 4px 0;font-weight:700;color:#212529;\">Total:</td><td style=\"padding:6px 0 4px 0;font-weight:700;color:#7b1113;font-size:1rem;\">' + phpEscape(totalFmt) + '</td></tr>'
            + '<tr><td style=\"padding:2px 12px 4px 0;color:#6c757d;font-size:0.8rem;\">Reservation (5%):</td><td style=\"padding:2px 0 4px 0;font-size:0.8rem;color:#6c757d;\">' + phpEscape(depositFmt) + '</td></tr>'
            + '<tr><td style=\"padding:4px 12px 4px 0;color:#555;\">Status:</td><td style=\"padding:4px 0;font-weight:600;color:#198754;\">Available</td></tr>'
            + '</table>'
            + amortHtml
            + '</div>';
        canSelect = true;
        showCancel = true;
        confirmText = window.__transferMode ? 'Select as Destination Plot' : (isBookingMode ? 'Select for Booking' : 'Book Now');
        confirmColor = '#7b1113';
    }

    if (currentPlotOnly && (String(plotX) !== String(currentPlotX) || String(plotY) !== String(currentPlotY))) {
      canSelect = false;
      html += '<div style="font-size:0.9rem;color:#a03a3c;font-weight:600;margin-top:10px;">This service is restricted to the selected deceased person&#39;s current plot.</div>';
    }

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: modalTitle,
            html: html,
            icon: icon,
            iconColor: color,
            showCancelButton: showCancel,
            confirmButtonText: confirmText,
            cancelButtonText: cancelText,
            confirmButtonColor: confirmColor,
            reverseButtons: true,
            allowOutsideClick: true
        }).then(function(result){
            if (result.isConfirmed) {
                if (isUnavailableSection || statusNorm === 'UNAVAILABLE' || !canSelect) {
                    return;
                }
                if (window.__transferMode && statusNorm !== 'AVAILABLE' && !isAuthorized) {
                    return;
                }
                var selectedButton = Array.from(document.querySelectorAll('.plot-btn')).find(function(button) {
                  return String(button.getAttribute('data-plot-x')) === String(plotX) && String(button.getAttribute('data-plot-y')) === String(plotY);
                });
                document.querySelectorAll('.plot-btn.plot-selected').forEach(function(button) {
                  button.classList.remove('plot-selected');
                  button.removeAttribute('aria-selected');
                });
                if (selectedButton) {
                  selectedButton.classList.add('plot-selected');
                  selectedButton.setAttribute('aria-selected', 'true');
                }
                var plotCoordStr = (plotX && plotY) ? (plotX + ',' + plotY) : plotNo;
                var selection = {
                    type: window.__transferMode ? 'transferPlotSelected' : 'bookingPlotSelected',
                    section: section,
                    plotNo: plotCoordStr,
                    plot_number: plotCoordStr,
                    plot: plotCoordStr,
                    plot_id: data.plot_id || 0,
                    plotX: plotX,
                    plotY: plotY,
                    plot_x: plotX,
                    plot_y: plotY,
                    graveLocation: section + ' ' + plotX + '-' + plotY,
                    plotType: plotType,
                    lawnType: lawnType,
                    price: lotPriceNum,
                    lotPrice: lotPriceNum,
                    intermentFee: intermentFeeNum,
                    totalPrice: totalAmountNum,
                    deposit: depositNum,
                    amortization: data.amortization || null,
                    occupantCount: occupantCount,
                    maxOccupants: maxOccupants,
                    status: statusNorm,
                    isAuthorized: isAuthorized,
                    is_user_authorized: isAuthorized,
                    is_plot_owned_by_customer: isPlotOwnedByCustomer
                };

                if (window.parent && window.parent !== window) {
                    var handledDirect = false;
                    try {
                        if (window.__transferMode && typeof window.parent.applySelectedPlotToTransfer === 'function') {
                            window.parent.applySelectedPlotToTransfer(selection, true);
                            handledDirect = true;
                        } else if (typeof window.parent.applySelectedPlotToBooking === 'function') {
                            window.parent.applySelectedPlotToBooking(selection, true);
                            handledDirect = true;
                        }
                    } catch (e) {
                        console.error('Error in parent callback:', e);
                    }
                    if (!handledDirect) {
                        try {
                            window.parent.postMessage(selection, '*');
                        } catch (e) {
                            console.error('Error posting message:', e);
                        }
                    }
                    return;
                }

                // Standalone window mode (not inside an iframe)
                if (isAdminUser || isAdmin) {
                    showAdminBookingForm(section, plotX, plotY, lawnType);
                } else if (statusNorm === 'AVAILABLE') {
                    handlePlotReserveClick(section, plotX, plotY, lawnType);
                } else {
                    openExistingBookingFlow(section, plotX, plotY, lawnType);
                }
            }
        });
    } else {
        if (canSelect && window.confirm((window.__transferMode ? 'Select destination ' : 'Book ') + plotNo + '?')) {
            var plotCoordStr = (plotX && plotY) ? (plotX + ',' + plotY) : plotNo;
            var selection = {
                type: window.__transferMode ? 'transferPlotSelected' : 'bookingPlotSelected',
                section: section,
                plotNo: plotCoordStr,
                plot_number: plotCoordStr,
                plot: plotCoordStr,
                plot_id: data.plot_id || 0,
                plotX: plotX,
                plotY: plotY,
                plot_x: plotX,
                plot_y: plotY,
                graveLocation: section + ' ' + plotX + '-' + plotY,
                plotType: plotType,
                lawnType: lawnType,
                price: lotPriceNum,
                lotPrice: lotPriceNum,
                intermentFee: intermentFeeNum,
                totalPrice: totalAmountNum,
                deposit: depositNum,
                amortization: data.amortization || null,
                occupantCount: occupantCount,
                maxOccupants: maxOccupants,
                status: statusNorm,
                isAuthorized: isAuthorized,
                is_user_authorized: isAuthorized,
                is_plot_owned_by_customer: isPlotOwnedByCustomer
            };
            if (window.parent && window.parent !== window) {
                var handledDirectFallback = false;
                try {
                    if (window.__transferMode && typeof window.parent.applySelectedPlotToTransfer === 'function') {
                        window.parent.applySelectedPlotToTransfer(selection, true);
                        handledDirectFallback = true;
                    } else if (typeof window.parent.applySelectedPlotToBooking === 'function') {
                        window.parent.applySelectedPlotToBooking(selection, true);
                        handledDirectFallback = true;
                    }
                } catch (e) {}
                if (!handledDirectFallback) {
                    try {
                        window.parent.postMessage(selection, '*');
                    } catch (e) {}
                }
                return;
            }

            // Standalone window mode
            if (isAdminUser || isAdmin) {
                showAdminBookingForm(section, plotX, plotY, lawnType);
            } else if (statusNorm === 'AVAILABLE') {
                handlePlotReserveClick(section, plotX, plotY, lawnType);
            } else {
                openExistingBookingFlow(section, plotX, plotY, lawnType);
            }
        }
    }
  }
  </script>
  <style>
    body { background: #ffffffff; font-family: 'Inter', 'Segoe UI', Arial, sans-serif; }
    /* Limit the wide dialog and centered body only to the outer map modal */
    #sectionMapModal .modal-dialog { max-width: 900px; }
    #sectionMapModal .modal-content {
      border: 0;
      border-radius: 1.1rem;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    }
    #sectionMapModal .modal-header {
      width: 100% !important;
      background: #a03a3c !important;
      color: #fff !important;
      padding: 1.1rem 1.5rem !important;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
      display: flex !important;
      align-items: center !important;
      justify-content: space-between !important;
      border-top-left-radius: 1.1rem !important;
      border-top-right-radius: 1.1rem !important;
      border-bottom: 0 !important;
      box-sizing: border-box !important;
      position: relative;
    }
    #sectionMapModal .modal-header h2 {
      font-size: 1.18rem;
      font-weight: 600;
      margin: 0;
      letter-spacing: 0.5px;
      color: #fff;
      font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
    }
    #sectionMapModal #internalCloseBtn {
      width: 34px !important;
      height: 34px !important;
      min-width: 34px !important;
      min-height: 34px !important;
      max-width: 34px !important;
      max-height: 34px !important;
      aspect-ratio: 1 / 1 !important;
      flex: 0 0 34px !important;
      flex-shrink: 0 !important;
      border-radius: 50% !important;
      background-color: #ffffff !important;
      border: 2px solid rgba(255, 255, 255, 0.95) !important;
      color: #7b1113 !important;
      font-size: 22px !important;
      font-weight: 900 !important;
      line-height: 1 !important;
      opacity: 1 !important;
      cursor: pointer !important;
      padding: 0 !important;
      margin: 0 !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      box-shadow: 0 3px 10px rgba(0,0,0,0.32) !important;
      transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1) !important;
    }
    #sectionMapModal #internalCloseBtn:hover {
      background-color: #f8fafc !important;
      color: #94171a !important;
      transform: scale(1.12) rotate(90deg) !important;
      box-shadow: 0 5px 16px rgba(0,0,0,0.45) !important;
      border-color: #ffffff !important;
      opacity: 1 !important;
    }
    #sectionMapModal .modal-body { text-align: center; }
    .section-map-img {
      max-width: 100%;
        border-radius: 16px;
        box-shadow: 0 2px 16px rgba(160,58,60,0.10);
    }
    .plot-btn.plot-selected {
        outline: 3px solid #ffc107 !important;
        outline-offset: 2px;
        box-shadow: 0 0 0 2px rgba(255,193,7,0.35) !important;
        z-index: 4 !important;
    }
    .text-uppercase { text-transform: uppercase; }
    /* Match inner popup forms to dashboard modal width */
    #reservePlotModal .modal-dialog { max-width: 500px; }
    #addDeceasedModal .modal-dialog,
    #editDeceasedModal .modal-dialog { max-width: 820px; }
    #manageReservationModal .modal-dialog {
      max-width: 680px;
      width: 94%;
      max-height: 92vh;
      max-height: 92dvh;
    }
    #manageReservationModal .modal-content {
      max-height: 90vh;
      max-height: 90dvh;
      display: flex !important;
      flex-direction: column !important;
      overflow: hidden !important;
    }
    #manageReservationModal #manageReservationForm {
      display: flex !important;
      flex-direction: column !important;
      flex: 1 1 auto !important;
      min-height: 0 !important;
      max-height: 100% !important;
      overflow: hidden !important;
    }
    #manageReservationModal .modal-body {
      overflow-y: auto !important;
      -webkit-overflow-scrolling: touch !important;
      overscroll-behavior: contain !important;
      touch-action: pan-y !important;
      flex: 1 1 auto !important;
      min-height: 0 !important;
    }
    #manageReservationModal .modal-footer {
      flex-shrink: 0 !important;
    }

    /* Mobile-specific adjustments for section popup modal */
    /* Ensure action buttons in deceased modal wrap and stay visible */
    .deceased-action-row { display:flex; gap:0.5rem; flex-wrap:wrap; justify-content:flex-end; }

    @media (max-width: 768px) {
      #sectionMapModal .modal-dialog {
        max-width: 100%;
        margin: 0.5rem;
      }
      #sectionMapModal .modal-body {
        padding: 0.75rem 0.5rem;
      }
      .map-outer {
        position: relative;
        width: 100%;
        max-width: 100%;
        margin: 0 auto;
        overflow: hidden;
        touch-action: pan-x pan-y pinch-zoom;
        background: #f8f9fa;
        border-radius: 12px;
      }
      .section-map {
        position: relative;
        width: 868px;
        height: 651px;
        transform-origin: 0 0;
        will-change: transform;
      }
      #section-map-wrapper {
        position: relative;
        width: 868px;
        height: 651px;
        display: block;
      }
      .section-map-img {
        width: 868px !important;
        max-width: none !important;
        height: 651px !important;
        max-height: none !important;
        object-fit: fill !important;
        border-radius: 12px !important;
        box-shadow: 0 2px 12px rgba(160,58,60,0.15) !important;
      }
      #deceasedInfoModal .modal-dialog,
      #reservePlotModal .modal-dialog,
      #moveDeceasedModal .modal-dialog,
      #addDeceasedModal .modal-dialog,
      #editDeceasedModal .modal-dialog {
        max-width: 100% !important;
        margin: 0.5rem !important;
      }
      #manageReservationModal .modal-dialog {
        max-width: 100% !important;
        width: 96% !important;
        margin: 0.5rem auto !important;
        max-height: calc(100vh - 1rem) !important;
        max-height: calc(100dvh - 1rem) !important;
      }
      #manageReservationModal .modal-content {
        max-height: calc(100vh - 1rem) !important;
        max-height: calc(100dvh - 1rem) !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
      }
      #manageReservationModal #manageReservationForm {
        display: flex !important;
        flex-direction: column !important;
        flex: 1 1 auto !important;
        min-height: 0 !important;
        overflow: hidden !important;
      }
      #manageReservationModal .modal-body {
        padding: 1rem !important;
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch !important;
        overscroll-behavior: contain !important;
        touch-action: pan-y !important;
        flex: 1 1 auto !important;
        min-height: 0 !important;
        max-height: calc(100vh - 130px) !important;
        max-height: calc(100dvh - 130px) !important;
      }
      #manageReservationModal .modal-footer {
        padding: 0.75rem 1rem !important;
        flex-shrink: 0 !important;
      }
      .deceased-action-row button,
      .deceased-action-row a {
        min-height: 40px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
      }
      .swal2-popup {
        width: 95% !important;
        max-width: 440px !important;
        padding: 1.25rem 0.85rem !important;
        border-radius: 16px !important;
        box-sizing: border-box !important;
      }
      .swal2-title {
        font-size: 1.2rem !important;
        margin-bottom: 0.5rem !important;
      }
      .swal2-html-container {
        font-size: 0.88rem !important;
        margin: 0.5rem 0 !important;
        max-height: 55vh !important;
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch !important;
      }
      .swal2-actions {
        flex-direction: column-reverse !important;
        gap: 0.6rem !important;
        width: 100% !important;
        margin-top: 1rem !important;
      }
      .swal2-actions button {
        width: 100% !important;
        margin: 0 !important;
        min-height: 46px !important;
        font-size: 0.95rem !important;
        font-weight: 600 !important;
        border-radius: 10px !important;
        touch-action: manipulation !important;
      }
      .plot-btn {
        touch-action: manipulation !important;
      }
    }
    /* SweetAlert2 must always appear above Bootstrap modals and backdrops at ALL screen sizes */
    .swal2-container { z-index: 99999 !important; }
    .swal2-backdrop-show { z-index: 99998 !important; }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    var viewport = document.querySelector('.map-outer');
    var canvas = viewport ? viewport.querySelector('.section-map') : null;
    var image = canvas ? canvas.querySelector('#section-map-img') : null;
    if (!viewport || !canvas || !image) return;

    var baseWidth = 868;
    var baseHeight = 651;
    var scale = 1;
    var minScale = 1;
    var maxScale = 3;
    var panX = 0;
    var panY = 0;
    var pointers = new Map();
    var gestureStart = null;
    var didDrag = false;

    function isMobile() {
      return window.matchMedia('(max-width: 768px)').matches;
    }

    function constrainPan() {
      if (!isMobile()) return;
      var vWidth = viewport.clientWidth;
      var vHeight = viewport.clientHeight;
      var width = baseWidth * scale;
      var height = baseHeight * scale;
      if (width <= vWidth) {
        panX = (vWidth - width) / 2;
      } else {
        panX = Math.min(0, Math.max(vWidth - width, panX));
      }
      if (height <= vHeight) {
        panY = (vHeight - height) / 2;
      } else {
        panY = Math.min(0, Math.max(vHeight - height, panY));
      }
    }

    function render() {
      if (!isMobile()) {
        canvas.style.transform = '';
        viewport.style.height = '';
        return;
      }
      constrainPan();
      canvas.style.transformOrigin = '0 0';
      canvas.style.transform = 'translate(' + panX + 'px, ' + panY + 'px) scale(' + scale + ')';
    }

    function initialPosition() {
      if (!isMobile()) {
        canvas.style.transform = '';
        viewport.style.height = '';
        return;
      }
      var vWidth = viewport.clientWidth || (window.innerWidth - 24);
      minScale = Math.min(1, vWidth / baseWidth);
      scale = minScale;
      viewport.style.height = Math.round(baseHeight * scale) + 'px';
      panX = 0;
      panY = 0;
      render();
    }

    function distance(a, b) {
      return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
    }

    function midpoint(a, b) {
      var rect = viewport.getBoundingClientRect();
      return { x: (a.clientX + b.clientX) / 2 - rect.left, y: (a.clientY + b.clientY) / 2 - rect.top };
    }

    viewport.addEventListener('pointerdown', function (event) {
      if (!isMobile()) return;
      // Do not capture pointer if tapping a plot button or interactive control
      if (event.target && event.target.closest('.plot-btn, button, a, input, select')) {
        return;
      }
      pointers.set(event.pointerId, event);
      try { viewport.setPointerCapture(event.pointerId); } catch(e){}
      if (pointers.size === 1) {
        gestureStart = { x: event.clientX, y: event.clientY, panX: panX, panY: panY };
        didDrag = false;
      } else if (pointers.size === 2) {
        var pair = Array.from(pointers.values());
        gestureStart = { distance: distance(pair[0], pair[1]), scale: scale };
        didDrag = true;
      }
    });

    viewport.addEventListener('pointermove', function (event) {
      if (!isMobile() || !pointers.has(event.pointerId)) return;
      pointers.set(event.pointerId, event);
      if (pointers.size === 1 && gestureStart) {
        var dx = event.clientX - gestureStart.x;
        var dy = event.clientY - gestureStart.y;
        if (Math.hypot(dx, dy) > 8) didDrag = true;
        if (didDrag && scale > minScale) {
          panX = gestureStart.panX + dx;
          panY = gestureStart.panY + dy;
          render();
        }
      } else if (pointers.size === 2 && gestureStart) {
        var pair = Array.from(pointers.values());
        var focus = midpoint(pair[0], pair[1]);
        var nextScale = Math.min(maxScale, Math.max(minScale, gestureStart.scale * distance(pair[0], pair[1]) / gestureStart.distance));
        var worldX = (focus.x - panX) / scale;
        var worldY = (focus.y - panY) / scale;
        scale = nextScale;
        panX = focus.x - worldX * scale;
        panY = focus.y - worldY * scale;
        render();
      }
    });

    function release(event) {
      if (!isMobile()) return;
      pointers.delete(event.pointerId);
      if (pointers.size === 1) {
        var remaining = Array.from(pointers.values())[0];
        gestureStart = { x: remaining.clientX, y: remaining.clientY, panX: panX, panY: panY };
      } else if (pointers.size === 0) {
        gestureStart = null;
      }
    }

    viewport.addEventListener('pointerup', release);
    viewport.addEventListener('pointercancel', release);
    viewport.addEventListener('click', function (event) {
      if (didDrag) {
        event.preventDefault();
        event.stopPropagation();
        didDrag = false;
      }
    }, true);

    window.addEventListener('resize', initialPosition);
    window.addEventListener('orientationchange', function () { setTimeout(initialPosition, 150); });
    if (image.complete) initialPosition();
    else image.addEventListener('load', initialPosition, { once: true });
  });
  </script>
  <script>
  // Blink and scroll to a target plot if provided via URL params
document.addEventListener('DOMContentLoaded', function() {
  try {
    // Inject minimal CSS for plot blink
    var style = document.createElement('style');
    style.textContent = '\n@keyframes plotBlink{0%{box-shadow:0 0 0 0 rgba(255,193,7,.0), inset 0 0 0 0 rgba(255,193,7,.0);}50%{box-shadow:0 0 0 6px rgba(255,193,7,.35), inset 0 0 0 2px rgba(255,193,7,.65);}100%{box-shadow:0 0 0 0 rgba(255,193,7,.0), inset 0 0 0 0 rgba(255,193,7,.0);}}\n.plot-blink{animation:plotBlink 1.15s ease-in-out 1;}\n';
    document.head && document.head.appendChild(style);

    var params = new URLSearchParams(window.location.search);
    var px = params.get('plot_x');
    var py = params.get('plot_y');
    var section = params.get('section');
    if (!px || !py || !section) return;
    if (params.get('search_highlight') === '1') return;
    var safePx = (window.CSS && typeof window.CSS.escape === 'function') ? window.CSS.escape(px) : String(px).replace(/["\\]/g, '\\$&');
    var safePy = (window.CSS && typeof window.CSS.escape === 'function') ? window.CSS.escape(py) : String(py).replace(/["\\]/g, '\\$&');
    var sel = '.plot-btn[data-plot-x="' + safePx + '"][data-plot-y="' + safePy + '"]';
    var el = document.querySelector(sel);
    if (!el) return;
    // Restart blink animation
    el.classList.remove('plot-blink'); void el.offsetWidth; el.classList.add('plot-blink');
    // Remove class after animation to allow future replays
    setTimeout(function(){ el.classList.remove('plot-blink'); }, 1300);

    // Do not automatically pop up modal over the map so the user can clearly view the glowing/pulsing plot.
    // Modal will only open when the user explicitly clicks on the plot.
    if (params.get('auto_open') === '1' && params.get('search_highlight') !== '1') {
      setTimeout(function() {
        showDeceasedInfo(section, px, py);
      }, 800);
    }
  } catch(e) { /* no-op */ }
});
  </script>
<script>
  // Match dashboard input behaviors for Add Deceased form
  document.addEventListener('DOMContentLoaded', function() {
    try {
      // Toggle suffix OTHER field
      var sel = document.getElementById('suffix');
      var other = document.getElementById('suffix_other');
      function syncSuffix() {
        if (sel && sel.value === 'OTHER') { other.style.display = 'block'; }
        else if (other) { other.style.display = 'none'; other.value = ''; }
      }
      if (sel) { sel.addEventListener('change', syncSuffix); syncSuffix(); }

      // Reserve form suffix toggle
      var rSel = document.getElementById('res_suffix');
      var rOther = document.getElementById('res_suffix_other');
      function syncReserveSuffix() {
        if (rSel && rSel.value === 'OTHER') { rOther.style.display = 'block'; }
        else if (rOther) { rOther.style.display = 'none'; rOther.value = ''; }
      }
      if (rSel) { rSel.addEventListener('change', syncReserveSuffix); syncReserveSuffix(); }

      // Set max dates for birthday and date died
      var today = new Date().toISOString().split('T')[0];
      var bdayEl = document.getElementById('bday');
      var diedEl = document.getElementById('date_died');
      if (bdayEl) bdayEl.setAttribute('max', today);
      if (diedEl) diedEl.setAttribute('max', today);

      // Contact number helper ensuring numbers ALWAYS start with 09
      window.attachFamilyContactAuto09 = function(el) {
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
      };

      var contactEl = document.getElementById('contact_no');
      attachFamilyContactAuto09(contactEl);
      var eContactEl = document.getElementById('edit_contact_no');
      attachFamilyContactAuto09(eContactEl);
      var buyerContactEl = document.getElementById('add_buyer_contact');
      attachFamilyContactAuto09(buyerContactEl);

      // Edit modal behaviors: suffix toggle, max dates
      var eSel = document.getElementById('edit_suffix');
      var eOther = document.getElementById('edit_suffix_other');
      function syncEditSuffix() {
        if (eSel && eSel.value === 'OTHER') { eOther.style.display = 'block'; }
        else if (eOther) { eOther.style.display = 'none'; eOther.value = ''; }
      }
      if (eSel) { eSel.addEventListener('change', syncEditSuffix); syncEditSuffix(); }
      var ebdayEl = document.getElementById('edit_bday');
      var ediedEl = document.getElementById('edit_date_died');
      if (ebdayEl) ebdayEl.setAttribute('max', today);
      if (ediedEl) ediedEl.setAttribute('max', today);
    } catch (e) {}
  });

  // Submit Add Deceased via AJAX to this endpoint
  function submitAddDeceased() {
    var form = document.getElementById('addDeceasedForm');
    if (!form) return;
    var cVal = document.getElementById('contact_no') ? document.getElementById('contact_no').value.replace(/\D/g, '') : '';
    if (cVal && !/^09\d{9}$/.test(cVal)) {
      alert('Family Contact Number must be an 11-digit mobile number starting with 09.');
      document.getElementById('contact_no').focus();
      return;
    }
    var fd = new FormData(form);
    fd.append('ajax_request', 'add_deceased');
    fetch(window.location.href, { method: 'POST', body: fd })
      .then(r => r.json()).then(function(resp) {
        if (resp && resp.success) {
          // Close modal and notify
          try {
            var modalEl = document.getElementById('addDeceasedModal');
            var m = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            m.hide();
          } catch (e) {}
          alert('Deceased person added successfully');
          triggerFloatAnimation();
        } else {
          alert((resp && resp.message) ? resp.message : 'Failed to add deceased');
        }
      }).catch(function() { alert('Request failed'); });
  }
</script>
<style>
  @keyframes deceasedPlotPulse {
    0%, 100% {
      box-shadow: 0 0 0 0 rgba(255, 0, 0, 0.85);
      border-color: #ff0000 !important;
    }
    50% {
      box-shadow: 0 0 0 8px rgba(255, 0, 0, 0.25), 0 0 14px 2px rgba(255, 0, 0, 0.5);
      border-color: #cc0000 !important;
    }
  }

  @keyframes plotBlinkAlert {
    0%, 100% {
      border-color: #dc2626;
      outline-color: #ef4444;
      box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.7), 0 0 20px 6px rgba(220, 38, 38, 0.95);
      background-color: rgba(220, 38, 38, 0.9);
      opacity: 1;
    }
    50% {
      border-color: #ffffff;
      outline-color: #ffffff;
      box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.8), 0 0 12px 3px rgba(255, 255, 255, 0.95);
      background-color: rgba(255, 255, 255, 0.45);
      opacity: 0.65;
    }
  }

  @keyframes reservedPlotBlinkAlert {
    0%, 100% {
      border-color: #f59e0b !important;
      outline-color: #fbbf24 !important;
      box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.7), 0 0 20px 6px rgba(245, 158, 11, 0.95) !important;
      background-color: rgba(40, 40, 40, 0.85) !important;
      opacity: 1;
    }
    50% {
      border-color: #ffffff !important;
      outline-color: #ffffff !important;
      box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.8), 0 0 12px 3px rgba(251, 191, 36, 0.95) !important;
      background-color: rgba(245, 158, 11, 0.55) !important;
      opacity: 0.75;
    }
  }

  /* Prevent add occupied deceased modals from overflowing/clipping above viewport */
  #addOccupiedDeceasedModal .modal-dialog {
    margin: 1rem auto !important;
    max-height: calc(100vh - 2rem) !important;
    transform: none !important;
  }
  #addOccupiedDeceasedModal .modal-content {
    max-height: calc(100vh - 2rem) !important;
    overflow: hidden !important;
  }
  #addOccupiedDeceasedModal {
    padding-top: 0 !important;
    overflow-y: auto !important;
  }

  .deceased-search-highlight,
  .deceased-search-plot-highlight,
  .plot-search-pulse,
  .search-highlight,
  .search-blink,
  .search-pulse,
  .plot-blinking-highlight {
    animation: plotBlinkAlert 0.75s ease-in-out infinite !important;
    border: 3px solid #dc2626 !important;
    outline: 2.5px solid #ef4444 !important;
    outline-offset: 1px !important;
    box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.7), 0 0 20px 6px rgba(220, 38, 38, 0.95) !important;
    background: rgba(220, 38, 38, 0.9) !important;
    background-color: rgba(220, 38, 38, 0.9) !important;
    color: #ffffff !important;
    z-index: 9999 !important;
    transform: none !important;
  }

  .reserved-search-highlight,
  .plot-blinking-reserved {
    animation: reservedPlotBlinkAlert 0.75s ease-in-out infinite !important;
    border: 3px solid #f59e0b !important;
    outline: 2.5px solid #fbbf24 !important;
    outline-offset: 1px !important;
    box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.7), 0 0 20px 6px rgba(245, 158, 11, 0.95) !important;
    background: rgba(40, 40, 40, 0.85) !important;
    background-color: rgba(40, 40, 40, 0.85) !important;
    color: #ffffff !important;
    z-index: 9999 !important;
    transform: none !important;
  }

  /* Hide internal close button inside iframe or when selecting a grave plot */
  body.selection-mode-booking #internalCloseBtn,
  .is-embedded-frame #internalCloseBtn,
  .selection-mode-active #internalCloseBtn {
    display: none !important;
  }

  @keyframes floatIn {
    0% { opacity: 0; transform: translateY(12px); }
    100% { opacity: 1; transform: translateY(0); }
  }
  #deceasedInfoContent.float-in {
    animation: floatIn 400ms ease both;
  }

  body.modal-open {
    overflow: hidden !important;
  }
  .modal-header {
    flex-shrink: 0 !important;
    background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important;
    color: #ffffff !important;
    border-bottom: 1px solid rgba(255,255,255,0.15) !important;
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

  /* Modal size and scrollable fixes scoped ONLY to data forms - NEVER affecting the section map */
  #addDeceasedModal .modal-dialog {
    max-height: 92vh !important;
    margin-top: 1.5rem !important;
    margin-bottom: 1.5rem !important;
  }
  #addDeceasedModal .modal-content {
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    border-radius: 1.25rem !important;
  }
  #editDeceasedModal .modal-dialog {
    max-width: 820px !important;
    width: 94% !important;
    max-height: 92vh !important;
  }
  #editDeceasedModal .modal-content {
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    border-radius: 1.25rem !important;
  }
  #addDeceasedModal form,
  #editDeceasedModal form,
  #moveDeceasedForm {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    max-height: 100% !important;
    overflow: hidden !important;
  }
  #addDeceasedModal .modal-body,
  #editDeceasedModal .modal-body {
    overflow-y: auto !important;
    flex: 1 1 auto !important;
    max-height: calc(90vh - 145px) !important;
    padding-bottom: 1.5rem !important;
  }
  #addDeceasedModal .modal-footer,
  #editDeceasedModal .modal-footer {
    flex-shrink: 0 !important;
    background: #ffffff !important;
    border-top: 1px solid #e2e8f0 !important;
    z-index: 10 !important;
    padding: 0.85rem 1.5rem !important;
  }

  /* Scoped viewport styling for Deceased Info & Plot Details modal */
  #deceasedInfoModal .modal-dialog {
    max-width: min(680px, 94vw) !important;
    width: 94% !important;
    max-height: 92vh !important;
    margin: 1.5rem auto !important;
  }
  #deceasedInfoModal .modal-content {
    max-height: 94vh !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    border-radius: 16px !important;
    border: none !important;
    box-shadow: 0 10px 40px rgba(0,0,0,0.18) !important;
  }
  #deceasedInfoModal .modal-header {
    flex-shrink: 0 !important;
    padding: 0.85rem 1.35rem !important;
  }
  #deceasedInfoModal .modal-title {
    font-size: 1.18rem !important;
  }
  #deceasedInfoModal .modal-body {
    overflow-y: auto !important;
    overflow-x: hidden !important;
    flex: 1 1 auto !important;
    max-height: calc(94vh - 58px) !important;
    padding: 1.25rem !important;
  }

  /* Responsive layout for main card vs occupants sidebar */
  @media (min-width: 768px) {
    #deceasedInfoModal .deceased-info-row {
      display: flex !important;
      flex-direction: row !important;
      flex-wrap: nowrap !important;
      gap: 1rem !important;
      align-items: stretch !important;
    }
    #deceasedInfoModal .deceased-info-main-card.has-sidebar {
      flex: 1 1 64% !important;
      width: 64% !important;
      max-width: 64% !important;
      min-width: 0 !important;
    }
    #deceasedInfoModal .deceased-info-main-card:not(.has-sidebar) {
      flex: 1 1 100% !important;
      width: 100% !important;
      max-width: 100% !important;
      min-width: 0 !important;
    }
    #deceasedInfoModal .deceased-info-sidebar-card {
      flex: 0 0 36% !important;
      width: 36% !important;
      max-width: 36% !important;
      min-width: 0 !important;
      margin-top: 0 !important;
    }
  }

  @media (max-width: 767.98px) {
    #deceasedInfoModal .deceased-info-row {
      display: flex !important;
      flex-direction: column !important;
      gap: 1rem !important;
    }
    #deceasedInfoModal .deceased-info-main-card,
    #deceasedInfoModal .deceased-info-sidebar-card {
      width: 100% !important;
      max-width: 100% !important;
      min-width: 0 !important;
    }
  }

  /* Text containment rules so long values never overlap or breach card boundaries */
  #deceasedInfoModal .deceased-info-item {
    min-width: 0 !important;
  }
  #deceasedInfoModal .deceased-info-item .info-text-wrap {
    min-width: 0 !important;
    flex: 1 1 0% !important;
    overflow: hidden !important;
  }
  #deceasedInfoModal .deceased-info-item .info-val {
    word-break: break-word !important;
    overflow-wrap: anywhere !important;
    line-height: 1.25 !important;
  }

  /* Revert #sectionMapModal to original dimensions and natural layout */
  #sectionMapModal .modal-dialog {
    max-width: 900px !important;
    max-height: none !important;
  }
  #sectionMapModal .modal-content {
    max-height: none !important;
    overflow: visible !important;
  }
  #sectionMapModal .modal-body {
    max-height: none !important;
    overflow: visible !important;
    text-align: center !important;
  }
</style>
<script>
  function triggerFloatAnimation() {
    try {
      var el = document.getElementById('deceasedInfoContent');
      if (!el) return;
      el.classList.remove('float-in');
      // reflow to restart animation
      void el.offsetWidth;
      el.classList.add('float-in');
    } catch (e) {}
  }
</script>
</head>
<body class="<?php echo (!empty($_GET['selection_mode']) || !empty($_GET['embedded']) || isset($_GET['embed'])) ? 'selection-mode-booking is-embedded-frame' : ''; ?>">

<div class="modal show d-block" tabindex="-1" id="sectionMapModal">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header d-flex align-items-center justify-content-between">
        <h2 class="m-0 text-white" style="font-size:1.18rem; font-weight:600; letter-spacing:0.5px; font-family:'Inter','Segoe UI',Arial,sans-serif;">Garden Of Remembrance Section <?= $section ? strtoupper($section) : '' ?></h2>
        <?php if (empty($_GET['selection_mode']) && empty($_GET['embedded']) && !isset($_GET['embed'])): ?>
        <?php
        $popupFrom = $_GET['from'] ?? '';
        $popupCloseTitle = 'Close';
        if ($popupFrom === 'reservation_logs') {
            $popupCloseTitle = 'Back to Reservation Logs';
        } elseif ($popupFrom === 'index_direct' || $popupFrom === 'index') {
            $popupCloseTitle = 'Back to Cemetery Sections';
        } elseif ($popupFrom === 'map') {
            $popupCloseTitle = 'Back to Cemetery Map';
        } elseif ($popupFrom === 'dashboard') {
            $popupCloseTitle = 'Back to Admin Dashboard';
        } elseif ($popupFrom === 'user_dashboard' || $popupFrom === 'user_booking') {
            $popupCloseTitle = 'Back to User Dashboard';
        } else {
            $popupCloseTitle = 'Back to Cemetery Map';
        }
        ?>
        <button id="internalCloseBtn" type="button" onclick="closePopup()" aria-label="Close" title="<?php echo htmlspecialchars($popupCloseTitle); ?>">&times;</button>
        <?php endif; ?>
      </div>

<script>
  function closePopup() {
    try {
      // If inside iframe within dashboard modal, close the parent modal
      if (window.top !== window.self && window.parent && window.parent.document) {
        var pDoc = window.parent.document;
        var modalEl = pDoc.getElementById('plotSelectionModal') || pDoc.getElementById('popupSectionModal');
        if (modalEl && window.parent.bootstrap) {
          var inst = window.parent.bootstrap.Modal.getInstance(modalEl) || new window.parent.bootstrap.Modal(modalEl);
          if (inst) { inst.hide(); return; }
        }
        return; // Inside iframe: never navigate window.location to dashboard
      }
    } catch (e) { 
      if (window.top !== window.self) return; 
    }
    // Standalone fallback: return cleanly to logs.php, user_dashboard.php, map.php or index.php
    var isGuest = <?php echo ($isGuest ? 'true' : 'false'); ?>;
    var isAdmin = <?php echo ($isAdmin ? 'true' : 'false'); ?>;
    var params = new URLSearchParams(window.location.search);
    var basePath = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : ''));

    if (params.get('from') === 'reservation_logs' || (window.sessionStorage && window.sessionStorage.getItem('from_reservation_logs') === '1')) {
      try { if (window.sessionStorage) window.sessionStorage.removeItem('from_reservation_logs'); } catch(e) {}
      window.location.href = basePath + 'logs.php';
      return;
    }

    var fromParam = params.get('from') || '';
    var ref = document.referrer || '';

    if (fromParam === 'map' || ref.indexOf('map.php') !== -1) {
      window.location.href = basePath + 'map.php';
      return;
    }

    var cameFromIndex = fromParam === 'index_direct' || (ref.indexOf('index.php') !== -1 && fromParam !== 'map' && fromParam !== 'dashboard' && fromParam !== 'user_dashboard' && fromParam !== 'user_booking' && fromParam !== 'reservation_logs');

    if (cameFromIndex) {
      window.location.href = basePath + 'index.php#sections';
      return;
    }

    if (fromParam === 'user_booking') {
      window.location.href = basePath + 'user_dashboard.php#recentBookingsSection';
      return;
    }
    if (fromParam === 'user_dashboard') {
      window.location.href = basePath + 'user_dashboard.php#myPlotsSection';
      return;
    }
    if (fromParam === 'dashboard') {
      window.location.href = basePath + 'dashboard.php';
      return;
    }

    if (isGuest || fromParam === 'index') {
      if (fromParam === 'index_direct') {
        window.location.href = basePath + 'index.php#sections';
        return;
      }
      var fromRecentGuest = fromParam === 'recently_clicked';
      window.location.href = basePath + 'map.php?from=index' + (fromRecentGuest ? '#recent' : '');
      return;
    }

    var fromRecent = fromParam === 'recently_clicked';
    var mapTarget = basePath + 'map.php';
    window.location.href = fromRecent ? (mapTarget + '#recent') : mapTarget;
  }
  // Hide the internal close button and sync section dropdown when embedded inside the dashboard iframe
  document.addEventListener('DOMContentLoaded', function() {
    try {
      if (window.top !== window.self && window.parent && window.parent.document) {
        var btn = document.getElementById('internalCloseBtn');
        if (btn) btn.style.display = 'none';

        var parentSectionSel = window.parent.document.getElementById('adminPopupSectionSelect');
        if (parentSectionSel) {
          parentSectionSel.value = '<?= strtoupper($section) ?>';
        }
        var parentTransferSec = window.parent.document.getElementById('transferPopupSectionSelect');
        if (parentTransferSec) {
          parentTransferSec.value = '<?= strtoupper($section) ?>';
        }
        var parentBookingSec = window.parent.document.getElementById('section');
        if (parentBookingSec && parentBookingSec.value !== '<?= strtoupper($section) ?>') {
          parentBookingSec.value = '<?= strtoupper($section) ?>';
        }
      }
    } catch (e) { /* no-op */ }
  });
</script>

      <div class="modal-body">
        <div class="mb-3">
          <?php
          $imgPath = '';
          $imgDir = 'section/images/';
          if ($section === 'A' && file_exists(__DIR__ . '/section/images/section_a.png.jpg')) {
            $imgPath = $imgDir . 'section_a.png.jpg';
          } elseif ($section === 'B' && file_exists(__DIR__ . '/section/images/section_b.png.jpg')) {
            $imgPath = $imgDir . 'section_b.png.jpg';
          } elseif ($section === 'C' && file_exists(__DIR__ . '/section/images/setc.png.jpg')) {

            $imgPath = $imgDir . 'setc.png.jpg';
          } elseif ($section === 'D' && file_exists(__DIR__ . '/section/images/section_d.png.png')) {

            $imgPath = $imgDir . 'section_d.png.png';
          } elseif ($section === 'E' && file_exists(__DIR__ . '/section/images/section_e.png.png')) {

            $imgPath = $imgDir . 'section_e.png.png';
          } elseif ($section === 'F' && file_exists(__DIR__ . '/section/images/section_f.png.png')) {

            $imgPath = $imgDir . 'section_f.png.png';
          }
          if ($imgPath) {
            $plotIdMap = [];
            if ($pStmt = $conn->prepare("SELECT plot_id, plot_x, plot_y FROM tbl_plots WHERE UPPER(TRIM(section)) = ?")) {
                $secU = strtoupper($section);
                $pStmt->bind_param('s', $secU);
                $pStmt->execute();
                $pRes = $pStmt->get_result();
                while ($pRow = $pRes->fetch_assoc()) {
                    $plotIdMap[(int)$pRow['plot_y']][(int)$pRow['plot_x']] = (int)$pRow['plot_id'];
                }
                $pStmt->close();
            }

            echo '<div class="d-flex align-items-center flex-wrap gap-3 mb-2" style="gap:12px;">'
                . '<div class="d-inline-flex align-items-center" style="gap:8px;">'
                . '<span style="display:inline-block;width:16px;height:16px;border-radius:4px;background:rgba(218, 57, 65, 0.5);"></span>'
                . '<span class="small text-muted">Occupied</span>'
                . '</div>'
                . '<div class="d-inline-flex align-items-center" style="gap:8px;">'
                . '<span style="display:inline-block;width:16px;height:16px;border-radius:4px;background:rgba(33, 150, 243, 0.35);"></span>'
                . '<span class="small text-muted">Available</span>'
                . '</div>'
                . '<div class="d-inline-flex align-items-center" style="gap:8px;">'
                . '<span style="display:inline-block;width:16px;height:16px;border-radius:4px;background:rgba(0,0,0,0.35);"></span>'
                . '<span class="small text-muted">Reserved</span>'
                . '</div>'
              . '</div>';
            echo '<div class="map-outer">';
            echo '<div class="section-map">';
            echo '<div id="section-map-wrapper" style="position:relative;display:inline-block;">';
            echo '<img id="section-map-img" src="' . $imgPath . '" alt="Section ' . strtoupper($section) . ' Map" class="section-map-img" style="display:block;border-radius:12px;box-shadow:0 2px 16px rgba(160,58,60,0.10);">';

            if ($section === 'A') {
              include_once $connPath;

              $plots = [

                [1, 1, 775, 55, 65, 30],
                [1, 2, 775, 83, 65, 25],
                [1, 3, 709, 83, 65, 25],
                [1, 4, 709, 55, 66, 30],
                [2, 1, 775, 107, 65, 27],
                [2, 2, 775, 133, 65, 27],
                [2, 3, 709, 133, 65, 27],
                [2, 4, 709, 108, 65, 27],
                [3, 1, 775, 160, 65, 27],
                [3, 2, 775, 187, 65, 27],
                [3, 3, 709, 187, 65, 27],
                [3, 4, 709, 160, 65, 27],
                [4, 1, 775, 214, 65, 27],
                [4, 2, 775, 241, 65, 27],
                [4, 3, 709, 241, 65, 27],
                [4, 4, 709, 214, 65, 27],
                [5, 1, 775, 268, 65, 27],
                [5, 2, 775, 295, 65, 27],
                [5, 3, 709, 295, 65, 27],
                [5, 4, 709, 268, 65, 27],
                [6, 1, 775, 320, 65, 27],
                [6, 2, 775, 345, 65, 27],
                [6, 3, 709, 345, 65, 27],
                [6, 4, 709, 320, 65, 27],
                [7, 1, 775, 372, 65, 27],
                [7, 2, 775, 399, 65, 27],
                [7, 3, 709, 399, 65, 27],
                [7, 4, 709, 372, 65, 27],
                [8, 1, 775, 427, 65, 27],
                [8, 2, 775, 452, 65, 27],
                [8, 3, 709, 454, 65, 25],
                [8, 4, 709, 427, 65, 27],
                [9, 1, 775, 478, 65, 28],
                [9, 2, 775, 506, 65, 27],
                [9, 3, 709, 506, 65, 27],
                [9, 4, 709, 480, 65, 27],
                [10, 1, 775, 532, 65, 27],
                [10, 2, 709, 559, 65, 22],
                [10, 3, 709, 533, 65, 27],
                [11, 1, 642, 533, 65, 27],
                [11, 2, 642, 559, 65, 22],
                [11, 3, 574, 533, 66, 27],
                [12, 1, 642, 479, 66, 27],
                [12, 2, 642, 506, 65, 27],
                [12, 3, 574, 506, 66, 27],
                [12, 4, 574, 479, 66, 27],
                [13, 1, 642, 427, 66, 28],
                [13, 2, 642, 454, 66, 27],
                [13, 3, 574, 454, 69, 25],
                [13, 4, 574, 427, 66, 28],
                [14, 1, 642, 372, 66, 28],
                [14, 2, 642, 399, 66, 28],
                [14, 3, 574, 399, 66, 28],
                [14, 4, 574, 372, 66, 28],
                [15, 1, 642, 320, 66, 25],
                [15, 2, 642, 345, 66, 27],
                [15, 3, 574, 345, 66, 27],
                [15, 4, 574, 320, 66, 27],
                [16, 1, 642, 268, 66, 27],
                [16, 2, 642, 295, 66, 27],
                [16, 3, 574, 295, 66, 27],
                [16, 4, 574, 268, 66, 27],
                [17, 1, 642, 214, 66, 27],
                [17, 2, 642, 241, 66, 27],
                [17, 3, 574, 241, 66, 27],
                [17, 4, 574, 214, 66, 27],
                [18, 1, 642, 160, 66, 27],
                [18, 2, 642, 187, 66, 27],
                [18, 3, 574, 187, 66, 27],
                [18, 4, 574, 160, 66, 27],
                [19, 1, 642, 107, 66, 27],
                [19, 2, 642, 133, 66, 27],
                [19, 3, 574, 133, 66, 27],
                [19, 4, 574, 108, 66, 27],
                [20, 1, 642, 55, 66, 30],
                [20, 2, 642, 83, 66, 26],
                [20, 3, 574, 83, 66, 25],
                [20, 4, 574, 55, 66, 30],
                [21, 1, 508, 55, 66, 30],
                [21, 2, 508, 83, 66, 25],
                [21, 3, 442, 83, 66, 25],
                [21, 4, 442, 55, 66, 30],
                [22, 1, 508, 108, 66, 26],
                [22, 2, 508, 133, 66, 26],
                [22, 3, 442, 133, 66, 26],
                [22, 4, 442, 108, 66, 26],
                [23, 1, 508, 159, 66, 27],
                [23, 2, 508, 185, 66, 28],
                [23, 3, 442, 186, 66, 28],
                [23, 4, 442, 159, 66, 27],
                [24, 1, 508, 213, 66, 28],
                [24, 2, 508, 240, 66, 28],
                [24, 3, 442, 240, 66, 28],
                [24, 4, 442, 214, 66, 28],
                [25, 1, 508, 267, 66, 28],
                [25, 2, 508, 295, 66, 28],
                [25, 3, 442, 295, 66, 28],
                [25, 4, 442, 267, 66, 28],
                [26, 1, 508, 321, 66, 25],
                [26, 2, 508, 346, 66, 27],
                [26, 3, 442, 346, 66, 27],
                [26, 4, 442, 321, 66, 25],
                [27, 1, 508, 373, 66, 27],
                [27, 2, 508, 400, 66, 28],
                [27, 3, 442, 400, 66, 29],
                [27, 4, 442, 373, 66, 28],
                [28, 1, 508, 427, 66, 28],
                [28, 2, 508, 455, 66, 25],
                [28, 3, 442, 455, 66, 25],
                [28, 4, 442, 427, 66, 29],
                [29, 1, 508, 480, 66, 26],
                [29, 2, 508, 506, 66, 27],
                [29, 3, 442, 505, 66, 29],
                [29, 4, 442, 479, 66, 26],
                [30, 1, 508, 533, 66, 27],
                [30, 2, 508, 560, 66, 30],
                [30, 3, 442, 560, 66, 30],
                [30, 4, 442, 533, 66, 27],
                [31, 1, 375, 533, 67, 27],
                [31, 2, 375, 560, 67, 30],
                [31, 3, 307, 560, 69, 30],
                [31, 4, 307, 533, 68, 28],
                [32, 1, 375, 479, 68, 27],
                [32, 2, 375, 506, 68, 27],
                [32, 3, 307, 505, 68, 29],
                [32, 4, 307, 479, 68, 27],
                [33, 1, 375, 427, 68, 29],
                [33, 2, 375, 455, 68, 25],
                [33, 3, 307, 455, 68, 25],
                [33, 4, 307, 427, 68, 29],
                [34, 1, 375, 373, 68, 28],
                [34, 2, 375, 400, 68, 29],
                [34, 3, 307, 400, 68, 29],
                [34, 4, 307, 373, 68, 29],
                [35, 1, 375, 321, 68, 25],
                [35, 2, 375, 345, 68, 28],
                [35, 3, 307, 345, 68, 28],
                [35, 4, 307, 321, 68, 25],
                [36, 1, 375, 267, 68, 27],
                [36, 2, 375, 293, 68, 29],
                [36, 3, 307, 293, 68, 28],
                [36, 4, 307, 267, 68, 28],
                [37, 1, 375, 214, 68, 28],
                [37, 2, 375, 241, 68, 28],
                [37, 3, 307, 241, 68, 28],
                [37, 4, 307, 214, 68, 28],
                [38, 1, 375, 159, 68, 27],
                [38, 2, 375, 185, 68, 29],
                [38, 3, 307, 185, 68, 29],
                [38, 4, 307, 159, 68, 27],
                [39, 1, 375, 107, 68, 27],
                [39, 2, 375, 133, 68, 27],
                [39, 3, 307, 133, 68, 27],
                [39, 4, 307, 107, 68, 27],
                [40, 1, 376, 55, 66, 29],
                [40, 2, 376, 83, 66, 25],
                [40, 3, 305, 83, 70, 25],
                [40, 4, 305, 55, 70, 29],
                [41, 1, 240, 55, 66, 30],
                [41, 2, 240, 83, 66, 25],
                [41, 3, 170, 83, 71, 25],
                [41, 4, 170, 55, 71, 30],
                [42, 1, 240, 107, 67, 27],
                [42, 2, 240, 133, 67, 27],
                [42, 3, 170, 133, 69, 27],
                [42, 4, 170, 107, 70, 27],
                [43, 1, 240, 159, 68, 27],
                [43, 2, 240, 185, 68, 29],
                [43, 3, 170, 185, 70, 29],
                [43, 4, 170, 159, 70, 27],
                [44, 1, 240, 214, 69, 27],
                [44, 2, 240, 241, 69, 27],
                [44, 3, 170, 241, 70, 27],
                [44, 4, 170, 214, 70, 27],
                [45, 1, 240, 268, 69, 27],
                [45, 1, 240, 268, 69, 27],
                [45, 2, 240, 295, 68, 27],
                [45, 3, 170, 295, 70, 27],
                [45, 4, 170, 268, 70, 27],
                [46, 1, 240, 320, 69, 25],
                [46, 2, 240, 345, 69, 28],
                [46, 3, 170, 345, 70, 28],
                [46, 4, 170, 320, 70, 25],
                [47, 1, 240, 373, 69, 27],
                [47, 2, 240, 400, 69, 27],
                [47, 3, 170, 399, 70, 28],
                [47, 4, 170, 373, 70, 27],
                [48, 1, 240, 427, 69, 28],
                [48, 2, 240, 454, 69, 25],
                [48, 3, 170, 454, 70, 25],
                [48, 4, 170, 427, 70, 28],
                [49, 1, 240, 478, 69, 28],
                [49, 2, 240, 506, 69, 28],
                [49, 3, 170, 506, 70, 28],
                [49, 4, 170, 479, 70, 28],
                [50, 1, 240, 533, 69, 28],
                [50, 2, 240, 561, 69, 30],
                [50, 3, 170, 561, 70, 30],
                [50, 4, 170, 533, 70, 30],
                [51, 1, 105, 55, 66, 30],
                [51, 2, 105, 83, 66, 25],
                [51, 3, 105, 107, 66, 27],
                [51, 4, 105, 133, 66, 27],
                [51, 5, 105, 159, 66, 27],
                [52, 1, 105, 185, 66, 29],
                [52, 2, 105, 212, 66, 29],
                [52, 3, 105, 239, 66, 29],
                [52, 4, 105, 268, 66, 27],
                [52, 5, 105, 294, 66, 27],

              ];

              $deceased = get_section_deceased_map($conn, 'A');
              list($reservedA, $purchasedA) = get_section_reserved_and_purchased_maps($conn, 'A');
              $img_file = __DIR__ . '/section/images/section_a.png.jpg';
              $img_size = @getimagesize($img_file);
              $img_width = $img_size ? $img_size[0] : 500;
              $img_height = $img_size ? $img_size[1] : 300;
              echo '<div style="position:absolute;top:0;left:0;width:' . $img_width . 'px;height:' . $img_height . 'px;pointer-events:auto;z-index:3;cursor:pointer;">';

              $uniquePlots = [];
              $seen = [];
              foreach ($plots as $p) {
                $k = $p[0] . '-' . $p[1];
                if (!isset($seen[$k])) { $seen[$k] = true; $uniquePlots[] = $p; }
              }
              foreach ($uniquePlots as $plot) {
                list($x, $y, $left, $top, $box_w, $box_h) = $plot;
                $hasDeceased = isset($deceased[$x . '-' . $y]) || isset($deceased[$x][$y]);
                $isPurchased = isset($purchasedA[$y][$x]) || isset($purchasedA[$x][$y]);
                $isReserved = isset($reservedA[$y][$x]) || isset($reservedA[$x][$y]);
                $buttonStyle = 'position:absolute;left:' . $left . 'px;top:' . $top . 'px;width:' . $box_w . 'px;height:' . $box_h . 'px;padding:0;border:1.5px solid #3a3a3aff;border-radius:6px;box-shadow:none;font-size:0.85em;overflow:hidden;z-index:2;pointer-events:auto;cursor:pointer;';
                $extraAttrs = '';
                if ($hasDeceased) {
                  $buttonStyle .= 'background:rgba(218, 57, 65, 0.5);color:#fff;font-weight:bold;';
                } elseif ($isPurchased) {
                  $buttonStyle .= 'background:rgba(0, 0, 0, 0.35);color:#ffffff;';
                  $extraAttrs = ' data-purchased="1" title="Purchased Plot"';
                } elseif ($isReserved) {
                  $buttonStyle .= 'background:rgba(0, 0, 0, 0.35);color:#ffffff;';
                  $extraAttrs = ' data-reserved="1" title="This Plot is Reserved"';
                } else {
                  $buttonStyle .= 'background:rgba(33, 150, 243, 0.35);color:#ffffff;';
                }
                $pidA = $plotIdMap[$y][$x] ?? '';
                echo '<button type="button" class="plot-btn"' . $extraAttrs . ' data-plot-id="' . $pidA . '" data-plot-x="' . $x . '" data-plot-y="' . $y . '" style="' . $buttonStyle . '">';
                echo '';
                echo '</button>';
              }
              echo '</div>';
            }

            if ($section === 'B') {
              include_once $connPath;

              $plotsB = [

                [1, 1, 788, 77, 65, 27],
                [1, 2, 788, 102, 65, 27],
                [1, 3, 728, 102, 62, 27],
                [1, 4, 728, 77, 62, 27],
                [2, 1, 788, 129, 65, 27],
                [2, 2, 788, 154, 65, 27],
                [2, 3, 728, 154, 62, 27],
                [2, 4, 728, 129, 62, 27],
                [3, 1, 788, 179, 65, 27],
                [3, 2, 788, 204, 64, 27],
                [3, 3, 728, 204, 62, 27],
                [3, 4, 728, 179, 62, 27],
                [4, 1, 788, 230, 64, 27],
                [4, 2, 788, 255, 63, 27],
                [4, 3, 728, 255, 63, 27],
                [4, 4, 728, 230, 63, 27],
                [5, 1, 788, 280, 63, 26],
                [5, 2, 788, 305, 63, 27],
                [5, 3, 728, 305, 63, 27],
                [5, 4, 728, 280, 63, 27],
                [6, 1, 788, 332, 63, 27],
                [6, 2, 788, 357, 63, 26],
                [6, 3, 728, 357, 63, 26],
                [6, 4, 728, 332, 63, 26],
                [7, 1, 788, 383, 63, 26],
                [7, 2, 788, 408, 63, 26],
                [7, 3, 728, 408, 62, 26],
                [7, 4, 728, 383, 62, 26],
                [8, 1, 788, 434, 63, 28],
                [8, 2, 788, 460, 63, 28],
                [8, 3, 728, 460, 63, 28],
                [8, 4, 728, 434, 63, 28],
                [9, 1, 788, 487, 63, 28],
                [9, 2, 788, 513, 63, 25],
                [9, 3, 788, 536, 63, 25],
                [9, 4, 728, 536, 63, 25],
                [9, 5, 728, 513, 63, 25],
                [9, 6, 728, 487, 63, 27],
                [10, 1, 662, 487, 66, 27],
                [10, 2, 662, 513, 66, 26],
                [10, 3, 662, 537, 66, 24],
                [10, 4, 600, 537, 65, 24],
                [10, 5, 600, 513, 65, 25],
                [10, 6, 600, 487, 65, 27],
                [11, 1, 662, 435, 66, 27],
                [11, 2, 662, 460, 66, 27],
                [11, 3, 600, 460, 64, 27],
                [11, 4, 600, 435, 64, 27],
                [12, 1, 662, 383, 66, 26],
                [12, 2, 662, 408, 66, 28],
                [12, 3, 600, 410, 65, 26],
                [12, 4, 600, 383, 65, 27],
                [13, 1, 662, 332, 66, 27],
                [13, 2, 662, 357, 66, 28],
                [13, 3, 600, 357, 65, 28],
                [13, 4, 600, 332, 65, 28],
                [14, 1, 662, 280, 66, 27],
                [14, 2, 662, 305, 66, 27],
                [14, 3, 600, 305, 65, 27],
                [14, 4, 600, 280, 65, 27],
                [15, 1, 662, 230, 66, 27],
                [15, 2, 662, 256, 66, 25],
                [15, 3, 600, 256, 64, 25],
                [15, 4, 600, 230, 64, 26],
                [16, 1, 662, 179, 67, 27],
                [16, 2, 662, 205, 67, 26],
                [16, 3, 600, 205, 65, 26],
                [16, 4, 600, 179, 65, 26],
                [17, 1, 662, 129, 67, 27],
                [17, 2, 662, 155, 67, 25],
                [17, 3, 600, 154, 65, 27],
                [17, 4, 600, 129, 65, 27],
                [18, 1, 662, 78, 67, 27],
                [18, 2, 662, 103, 67, 27],
                [18, 3, 600, 103, 65, 27],
                [18, 4, 600, 78, 65, 27],
                [19, 1, 537, 78, 65, 27],
                [19, 2, 537, 103, 65, 27],
                [19, 3, 476, 103, 63, 27],
                [19, 4, 476, 78, 63, 27],
                [20, 1, 537, 128, 65, 25],
                [20, 2, 537, 153, 65, 27],
                [20, 3, 476, 153, 64, 27],
                [20, 4, 476, 128, 64, 27],
                [21, 1, 537, 178, 65, 27],
                [21, 2, 537, 204, 65, 27],
                [21, 3, 476, 204, 64, 27],
                [21, 4, 476, 178, 64, 27],
                [22, 1, 537, 229, 65, 27],
                [22, 2, 537, 255, 65, 27],
                [22, 3, 476, 255, 64, 27],
                [22, 4, 476, 229, 64, 27],
                [23, 1, 537, 280, 65, 26],
                [23, 2, 537, 306, 65, 26],
                [23, 3, 476, 306, 64, 26],
                [23, 4, 476, 280, 64, 26],
                [24, 1, 537, 331, 65, 27],
                [24, 2, 537, 358, 65, 27],
                [24, 3, 476, 358, 64, 27],
                [24, 4, 476, 331, 64, 27],
                [25, 1, 537, 382, 65, 27],
                [25, 2, 537, 409, 65, 27],
                [25, 3, 476, 409, 64, 27],
                [25, 4, 476, 382, 64, 27],
                [26, 1, 537, 434, 65, 27],
                [26, 2, 537, 461, 65, 27],
                [26, 3, 476, 461, 64, 27],
                [26, 4, 476, 434, 64, 27],
                [27, 1, 537, 487, 65, 27],
                [27, 2, 537, 512, 65, 26],
                [27, 3, 537, 535, 65, 25],
                [27, 4, 476, 535, 64, 25],
                [27, 5, 476, 512, 64, 25],
                [27, 6, 476, 487, 64, 25],
                [28, 1, 412, 487, 64, 25],
                [28, 2, 412, 512, 64, 25],
                [28, 3, 412, 535, 64, 25],
                [28, 4, 345, 535, 67, 25],
                [28, 5, 345, 512, 67, 25],
                [28, 6, 345, 487, 67, 25],
                [29, 1, 412, 435, 65, 26],
                [29, 2, 412, 461, 65, 26],
                [29, 3, 345, 461, 67, 26],
                [29, 4, 345, 434, 68, 27],
                [30, 1, 410, 382, 67, 28],
                [30, 2, 410, 409, 67, 28],
                [30, 3, 345, 409, 67, 27],
                [30, 4, 345, 382, 67, 27],
                [31, 1, 410, 331, 67, 27],
                [31, 2, 410, 358, 67, 27],
                [31, 3, 345, 358, 67, 27],
                [31, 4, 345, 331, 67, 27],
                [32, 1, 410, 280, 66, 26],
                [32, 2, 410, 306, 66, 26],
                [32, 3, 345, 306, 66, 26],
                [32, 4, 345, 280, 66, 26],
                [33, 1, 410, 229, 66, 26],
                [33, 2, 410, 255, 66, 26],
                [33, 3, 345, 255, 66, 26],
                [33, 4, 345, 229, 66, 26],
                [34, 1, 410, 178, 66, 27],
                [34, 2, 410, 205, 66, 26],
                [34, 3, 345, 205, 66, 26],
                [34, 4, 345, 178, 67, 27],
                [35, 1, 410, 128, 66, 26],
                [35, 2, 410, 152, 66, 26],
                [35, 3, 345, 152, 66, 26],
                [35, 4, 345, 128, 66, 26],
                [36, 1, 410, 78, 66, 26],
                [36, 2, 410, 104, 66, 26],
                [36, 3, 345, 104, 66, 26],
                [36, 4, 345, 78, 66, 26],
                [37, 1, 280, 78, 66, 26],
                [37, 2, 280, 104, 66, 26],
                [37, 3, 215, 104, 66, 26],
                [37, 4, 215, 78, 66, 26],
                [38, 1, 280, 128, 66, 26],
                [38, 2, 280, 153, 66, 26],
                [38, 3, 215, 154, 66, 26],
                [38, 4, 215, 128, 66, 26],
                [39, 1, 280, 179, 66, 26],
                [39, 2, 280, 203, 66, 26],
                [39, 3, 215, 203, 66, 26],
                [39, 4, 215, 179, 66, 26],
                [40, 1, 280, 229, 66, 26],
                [40, 2, 280, 254, 66, 28],
                [40, 3, 215, 254, 66, 28],
                [40, 4, 215, 229, 66, 26],
                [41, 1, 280, 281, 66, 26],
                [41, 2, 280, 306, 66, 27],
                [41, 3, 215, 306, 66, 27],
                [41, 4, 215, 281, 66, 27],
                [42, 1, 280, 331, 66, 26],
                [42, 2, 280, 357, 66, 28],
                [42, 3, 215, 357, 66, 28],
                [42, 4, 215, 331, 66, 28],
                [43, 1, 280, 385, 66, 26],
                [43, 2, 280, 411, 66, 26],
                [43, 3, 215, 411, 66, 26],
                [43, 4, 215, 385, 66, 26],
                [44, 1, 280, 435, 66, 26],
                [44, 2, 280, 461, 66, 29],
                [44, 3, 215, 461, 66, 29],
                [44, 4, 215, 435, 66, 29],
                [45, 1, 280, 488, 66, 25],
                [45, 2, 280, 511, 66, 26],
                [45, 3, 280, 535, 65, 26],
                [45, 4, 215, 536, 66, 25],
                [45, 5, 215, 512, 66, 24],
                [45, 6, 215, 488, 66, 24],
                [46, 1, 153, 459, 64, 28],
                [46, 2, 153, 486, 64, 28],
                [46, 3, 153, 512, 64, 26],
                [46, 4, 153, 537, 64, 24],
                [47, 1, 153, 384, 65, 26],
                [47, 2, 153, 410, 65, 27],
                [47, 3, 153, 434, 65, 25],
                [47, 4, 82, 434, 71, 27],
                [47, 5, 82, 410, 71, 27],
                [47, 6, 82, 384, 71, 27],
                [48, 1, 153, 331, 65, 28],
                [48, 2, 153, 358, 65, 28],
                [48, 3, 82, 358, 71, 28],
                [48, 4, 82, 331, 71, 28],
                [49, 1, 153, 281, 64, 27],
                [49, 2, 153, 306, 64, 27],
                [49, 3, 82, 306, 71, 27],
                [49, 4, 82, 281, 71, 27],
                [50, 1, 153, 229, 64, 26],
                [50, 2, 153, 255, 64, 26],
                [50, 3, 82, 255, 71, 26],
                [50, 4, 82, 229, 71, 26],
                [51, 1, 153, 179, 64, 26],
                [51, 2, 153, 205, 64, 26],
                [51, 3, 82, 205, 71, 26],
                [51, 4, 82, 179, 71, 26],
                [52, 1, 153, 128, 64, 26],
                [52, 2, 153, 153, 64, 26],
                [52, 3, 82, 153, 71, 26],
                [52, 4, 82, 128, 71, 26],
                [53, 1, 153, 78, 64, 26],
                [53, 2, 153, 104, 64, 26],
                [53, 3, 82, 104, 71, 26],
                [53, 4, 82, 78, 71, 26],

              ];

              $deceasedB = get_section_deceased_map($conn, 'B');
              list($reservedB, $purchasedB) = get_section_reserved_and_purchased_maps($conn, 'B');
              $img_fileB = __DIR__ . '/section/images/section_b.png.jpg';
              $img_sizeB = @getimagesize($img_fileB);
              $img_widthB = $img_sizeB ? $img_sizeB[0] : 500;
              $img_heightB = $img_sizeB ? $img_sizeB[1] : 300;
              echo '<div style="position:absolute;top:0;left:0;width:' . $img_widthB . 'px;height:' . $img_heightB . 'px;pointer-events:auto;z-index:3;cursor:pointer;">';

              $uniquePlotsB = [];
              $seenB = [];
              foreach ($plotsB as $pB) {
                $kB = $pB[0] . '-' . $pB[1];
                if (!isset($seenB[$kB])) { $seenB[$kB] = true; $uniquePlotsB[] = $pB; }
              }
              foreach ($uniquePlotsB as $plotB) {
                list($x, $y, $left, $top, $box_w, $box_h) = $plotB;
                $hasDeceasedB = isset($deceasedB[$x . '-' . $y]) || isset($deceasedB[$x][$y]);
                $isPurchasedB = isset($purchasedB[$y][$x]) || isset($purchasedB[$x][$y]);
                $isReservedB = isset($reservedB[$y][$x]) || isset($reservedB[$x][$y]);
                $buttonStyleB = 'position:absolute;left:' . $left . 'px;top:' . $top . 'px;width:' . $box_w . 'px;height:' . $box_h . 'px;padding:0;border:1.5px solid #3a3a3aff;border-radius:6px;box-shadow:none;font-size:0.85em;overflow:hidden;z-index:2;pointer-events:auto;';
                $extraAttrsB = '';
                if ($hasDeceasedB) {
                  $buttonStyleB .= 'background:rgba(218, 57, 65, 0.5);color:#fff;font-weight:bold;';
                } elseif ($isPurchasedB) {
                  $buttonStyleB .= 'background:rgba(0, 0, 0, 0.35);color:#ffffff;';
                  $extraAttrsB = ' data-purchased="1" title="Purchased Plot"';
                } elseif ($isReservedB) {
                  $buttonStyleB .= 'background:rgba(0, 0, 0, 0.35);color:#ffffff;';
                  $extraAttrsB = ' data-reserved="1" title="This Plot is Reserved"';
                } else {
                  $buttonStyleB .= 'background:rgba(33, 150, 243, 0.35);color:#ffffff;';
                }
                $pidB = $plotIdMap[$y][$x] ?? '';
                echo '<button type="button" class="plot-btn"' . $extraAttrsB . ' data-plot-id="' . $pidB . '" data-plot-x="' . $x . '" data-plot-y="' . $y . '" style="' . $buttonStyleB . '">';
                echo '';
                echo '</button>';
              }
              echo '</div>';
            }

            if ($section === 'C') {
              include_once $connPath;

              $plotsC = [
                [1, 1, 713, 91, 70, 29],
                [1, 2, 713  , 120, 70, 27],
                [1, 3, 644  , 120, 70, 27],
                [1, 4, 644  , 90, 70, 30],
                [2, 1, 713  , 147, 70, 28],
                [2, 2, 713  , 175, 70, 28],
                [2, 3, 644  , 175, 70, 28],
                [2, 4, 644  , 147, 70, 28],
                [3, 1, 713  , 202, 70, 26],
                [3, 2, 713  , 228, 70, 29],
                [3, 3, 644  , 227, 70, 30],
                [3, 4, 643  , 201, 70, 26],
                [4, 1, 713  , 256, 70, 27],
                [4, 2, 713  , 283, 70, 27],
                [4, 3, 643  , 283, 70, 27],
                [4, 4, 643  , 256, 70, 27],
                [5, 1, 713  , 308, 70, 29],
                [5, 2, 713  , 337, 70, 29],
                [5, 3, 643  , 337, 70, 29],
                [5, 4, 643  , 308, 70, 29],
                [6, 1, 713  , 365, 70, 28],
                [6, 2, 713  , 391, 70, 28],
                [6, 3, 643  , 391, 70, 28],
                [6, 4, 643  , 365, 70, 28],
                [7, 1, 643  , 419, 70, 31],
                [7, 2, 643  , 450, 70, 29],
                [7, 3, 643  , 477, 70, 29],
                [7, 4, 643  , 503, 70, 29],
                [8, 1, 643  , 532, 70, 27],
                [8, 2, 643  , 557, 70, 23],
                [8, 3, 643  , 579, 70, 29],
                [9, 1, 570  , 532, 73, 27],
                [9, 2, 570  , 558, 73, 23],
                [9, 3, 570 , 579, 73, 29],
                [9, 4, 503 , 579, 68, 29],
                [9, 5, 503 , 558, 68, 24],
                [9, 6, 503 , 532, 68, 27],
                [10, 1, 570  , 477, 73, 29],
                [10, 2, 570  , 503, 73, 29],
                [10, 3, 503  , 503, 70, 29],
                [10, 4, 503  , 477, 70, 29],
                [11, 1, 570  , 419, 73, 31],
                [11, 2, 570  , 448, 73, 29],
                [11, 3, 503  , 448, 70, 29],
                [11, 4, 503  , 419, 70, 29],
                [12, 1, 570  , 365, 73, 28],
                [12, 2, 570  , 393, 73, 28],
                [12, 3, 503  , 393, 70, 28],
                [12, 4, 503  , 365, 70, 28],
                [13, 1, 570  , 308, 73, 29],
                [13, 2, 570  , 337, 73, 29],
                [13, 3, 503  , 337, 70, 29],
                [13, 4, 503  , 308, 70, 29],
                [14, 1, 570  , 256, 73, 27],
                [14, 2, 570  , 283, 73, 27],
                [14, 3, 503  , 283, 70, 27],
                [14, 4, 503  , 256, 70, 27],
                [15, 1, 570  , 201, 74, 27],
                [15, 2, 570  , 228, 74, 29],
                [15, 3, 503  , 225, 70, 33],
                [15, 4, 503  , 201, 68, 25],
                [16, 1, 570  , 146, 74, 29],
                [16, 2, 570  , 175, 74, 26],
                [16, 3, 503  , 175, 70, 26],
                [16, 4, 503  , 146, 70, 29],
                [17, 1, 570  , 90, 74, 29],
                [17, 2, 570  , 119, 74, 29],
                [17, 3, 503  , 119, 70, 29],
                [17, 4, 503  , 90, 70, 29],
                [18, 1, 435  , 90, 70, 29],
                [18, 2, 435  , 119, 70, 29],
                [18, 3, 369  , 119, 67, 29],
                [18, 4, 369  , 90, 67, 29],
                [19, 1, 435  , 146, 68, 29],
                [19, 2, 435  , 174, 68, 29],
                [19, 3, 369  , 174, 67, 29],
                [19, 4, 369  , 146, 67, 29],
                [20, 1, 437  , 200, 67, 29],
                [20, 2, 437  , 228, 67, 29],
                [20, 3, 369  , 226, 68, 31],
                [20, 4, 369  , 200, 68, 29],
                [21, 1, 437  , 255, 67, 29],
                [21, 2, 437  , 284, 67, 29],
                [21, 3, 369  , 284, 69, 29],
                [21, 4, 369  , 255, 69, 29],
                [22, 1, 437  , 310, 67, 29],
                [22, 2, 437  , 337, 67, 29],
                [22, 3, 369  , 337, 69, 29],
                [22, 4, 369  , 310, 69, 29],
                [23, 1, 438  , 365, 67, 27],
                [23, 2, 438 , 390, 67, 29],
                [23, 3, 369  , 390, 69, 29],
                [23, 4, 369  , 365, 69, 25],
                [24, 1, 438  , 419, 67, 30],
                [24, 2, 438  , 449, 67, 30],
                [24, 3, 369  , 449, 69, 30],
                [24, 4, 369  , 419, 69, 30],
                [25, 1, 435  , 479, 69, 27],
                [25, 2, 435  , 505, 69, 29],
                [25, 3, 369  , 479, 68, 27],
                [25, 4, 369  , 505, 68, 29],
                [26, 1, 435  , 533, 68, 26],
                [26, 2, 435  , 557, 68, 26],
                [26, 3, 435  , 580, 68, 29],
                [26, 4, 369  , 580, 68, 29],
                [26, 5, 369  , 557, 68, 24],
                [26, 6, 369  , 533, 68, 24],
                [27, 1, 300  , 533, 70, 26],
                [27, 2, 300  , 557, 70, 26],
                [27, 3, 300  , 580, 70, 29],
                [27, 4, 232  , 580, 68, 29],
                [27, 5, 232  , 557, 68, 25],
                [27, 6, 232  , 530, 70, 29],
                [28, 1, 300  , 477, 70, 29],
                [28, 2, 300  , 505, 70, 29],
                [28, 3, 232  , 505, 68, 25],
                [28, 4, 232  , 477, 70, 29],
                [29, 1, 300  , 419, 70, 32],
                [29, 2, 300  , 450, 70, 29],
                [29, 3, 232  , 450, 70, 29],
                [29, 4, 232  , 419, 70, 31],
                [30, 1, 300 , 364, 70, 29],
                [30, 2, 300 , 390, 70, 29],
                [30, 3, 232  , 390, 70, 29],
                [30, 4, 232  , 364, 70, 26],
                [31, 1, 300 , 310, 70, 29],
                [31, 2, 300  , 338, 70, 26],
                [31, 3, 232  , 338, 70, 26],
                [31, 4, 232  , 310, 70, 29],
                [32, 1, 300 , 257, 70, 29],
                [32, 2, 300  , 284, 70, 29],
                [32, 3, 232  , 284, 70, 29],
                [32, 4, 232  , 257, 70, 29],
                [33, 1, 300  , 200, 70, 29],
                [33, 2, 300  , 227, 70, 29],
                [33, 3, 232  , 227, 70, 29],
                [33, 4, 232  , 200, 70, 29],
                [34, 1, 300  , 146, 70, 29],
                [34, 2, 300 , 174, 70, 29],
                [34, 3, 232  , 174, 70, 29],
                [34, 4, 232  , 146, 70, 29],
                [35, 1, 300  , 90, 70, 30],
                [35, 2, 300  , 120, 70, 29],
                [35, 3, 232  , 120, 70, 29],
                [35, 4, 232  , 90, 70, 30],
                [36, 1, 165  , 90, 70, 30],
                [36, 2, 165  , 120, 67, 28],
                [36, 3, 100 , 120, 67, 28],
                [36, 4, 100  , 90, 67, 30],
                [37, 1, 165  , 148, 67, 28],
                [37, 2, 165  , 175, 67, 28],
                [37, 3, 100  , 175, 67, 28],
                [37, 4, 100  , 148, 65, 28],
                [38, 1, 165 , 200, 67, 30],
                [38, 2, 165 , 228, 67, 30],
                [38, 3, 100  , 228, 67, 30],
                [38, 4, 100  , 200, 67, 30],
                [39, 1, 165  , 256, 67, 28],
                [39, 2, 165  , 284, 67, 28],
                [39, 3, 100 , 284, 67, 28],
                [39, 4, 100 , 256, 67, 28],
                [40, 1, 165 , 310, 67, 29],
                [40, 2, 165 , 338, 67, 29],
                [40, 3, 100 , 338, 67, 29],
                [40, 4, 100 , 310, 67, 29],
                [41, 1, 165 , 365, 67, 28],
                [41, 2, 165 , 391, 67, 28],
                [41, 3, 100 , 391, 67, 28], 
                [41, 4, 100 , 365, 67, 28],
                [42, 1, 165 , 419, 67, 30],
                [42, 2, 165 , 449, 67, 30],
                [42, 3, 100 , 449, 67, 30],
                [42, 4, 100 , 419, 67, 30],
                [43, 1, 165 , 479, 67, 27],
                [43, 2, 165 , 505, 67, 29],
                [43, 3, 100 , 505, 67, 29],
                [43, 4, 100 , 479, 67, 27],
                [44, 1, 165 , 533, 67, 26],
                [44, 2, 165 , 557, 67, 26],
                [44, 3, 165 , 580, 67, 29], 
                [44, 4, 100 , 580, 67, 29],
                [44, 5, 100 , 557, 67, 27],
                [44, 6, 100 , 530, 67, 27],
                [45, 3, 30 , 580, 70, 27],
                [45, 2, 30 , 556, 70, 23],
                [45, 1, 30 , 530, 70, 26],
                [46, 4, 30 , 505, 70, 26],
                [46, 3, 30 , 476, 70, 29],
                [46, 2, 30 , 448, 70, 29],
                [46, 1, 30 , 419, 70, 29],
                [47, 4, 30 , 390, 70, 29],
                [47, 3, 30 , 364, 70, 26],
                [47, 2, 30 , 338, 70, 26],
                [47, 1, 30 , 310, 70, 29],
                [48, 4, 30 , 283, 70, 27],
                [48, 3, 30 , 256, 70, 27],
                [48, 2, 30 , 227, 70, 30],
                [48, 1, 30 , 200, 70, 28],
                [49, 4, 30 , 174, 70, 27],
                [49, 3, 30 , 146, 70, 27],
                [49, 2, 30 , 119, 70, 29],
                [49, 1, 30 , 90, 70, 30],

              ];

              $deceasedC = get_section_deceased_map($conn, 'C');
              list($reservedC, $purchasedC) = get_section_reserved_and_purchased_maps($conn, 'C');
              $img_fileC = __DIR__ . '/section/images/setc.png.jpg';
              $img_sizeC = @getimagesize($img_fileC);
              $img_widthC = $img_sizeC ? $img_sizeC[0] : 500;
              $img_heightC = $img_sizeC ? $img_sizeC[1] : 300;
              echo '<div style="position:absolute;top:0;left:0;width:' . $img_widthC . 'px;height:' . $img_heightC . 'px;pointer-events:auto;z-index:3;cursor:pointer;">';

              $uniquePlotsC = [];
              $seenC = [];
              foreach ($plotsC as $pC) {
                $kC = $pC[0] . '-' . $pC[1];
                if (!isset($seenC[$kC])) { $seenC[$kC] = true; $uniquePlotsC[] = $pC; }
              }
              foreach ($uniquePlotsC as $plotC) {
                list($x, $y, $left, $top, $box_w, $box_h) = $plotC;
                $hasDeceasedC = isset($deceasedC[$x . '-' . $y]) || isset($deceasedC[$x][$y]);
                $isPurchasedC = isset($purchasedC[$y][$x]) || isset($purchasedC[$x][$y]);
                $isReservedC = isset($reservedC[$y][$x]) || isset($reservedC[$x][$y]);
                $buttonStyleC = 'position:absolute;left:' . $left . 'px;top:' . $top . 'px;width:' . $box_w . 'px;height:' . $box_h . 'px;padding:0;border:1.5px solid #3a3a3aff;border-radius:6px;box-shadow:none;font-size:0.85em;overflow:hidden;z-index:2;pointer-events:auto;';
                $extraAttrsC = '';
                if ($hasDeceasedC) {
                  $buttonStyleC .= 'background:rgba(218, 57, 65, 0.5);color:#fff;font-weight:bold;';
                } elseif ($isPurchasedC) {
                  $buttonStyleC .= 'background:rgba(0, 0, 0, 0.35);color:#ffffff;';
                  $extraAttrsC = ' data-purchased="1" title="Purchased Plot"';
                } elseif ($isReservedC) {
                  $buttonStyleC .= 'background:rgba(0, 0, 0, 0.35);color:#ffffff;';
                  $extraAttrsC = ' data-reserved="1" title="This Plot is Reserved"';
                } else {
                  $buttonStyleC .= 'background:rgba(33, 150, 243, 0.35);color:#ffffff;';
                }
                $pidC = $plotIdMap[$y][$x] ?? '';
                echo '<button type="button" class="plot-btn"' . $extraAttrsC . ' data-plot-id="' . $pidC . '" data-plot-x="' . $x . '" data-plot-y="' . $y . '" style="' . $buttonStyleC . '">';
                echo '';
                echo '</button>';
              }
              echo '</div>';
            }

            if ($section === 'D') {
              include_once $connPath;

              $plotsD = [

                [1, 1, 710, 30, 76, 31],
                [1, 2, 710, 60, 76, 31],
                [1, 3, 635, 60, 76, 31],
                [1, 4, 635, 30, 76, 31],
                [2, 1, 710, 90, 76, 31],
                [2, 2, 710, 120, 76, 31],
                [2, 3, 635, 120, 76, 31],
                [2, 4, 635, 90, 76, 31],
                [3, 1, 710, 150, 76, 31],
                [3, 2, 710, 180, 76, 31],
                [3, 3, 635, 180, 76, 31],
                [3, 4, 635, 150, 76, 31],
                [4, 1, 710, 210, 76, 31],
                [4, 2, 710, 240, 76, 31],
                [4, 3, 635, 240, 76, 31],
                [4, 4, 635, 210, 76, 31],
                [5, 1, 710, 270, 76, 28],
                [5, 2, 710, 298, 76, 29],
                [5, 3, 635, 298, 76, 29],
                [5, 4, 635, 270, 76, 28],
                [6, 1, 710, 325, 76, 29],
                [6, 2, 710, 354, 76, 31],
                [6, 3, 635, 386, 77, 31],
                [6, 4, 635, 354, 76, 31],
                [6, 5, 635, 325, 76, 29],
                [7, 3, 483, 564, 77, 29],
                [7, 2, 483, 534, 77, 30],
                [7, 1, 483, 506, 77, 29],
                [8, 1, 559, 450, 78, 29],
                [8, 2, 559, 480, 78, 27],
                [8, 3, 483, 480, 77, 27],
                [8, 4, 483, 450, 77, 29],
                [9, 1, 560, 384, 76, 33],
                [9, 2, 560, 417, 76, 33],
                [9, 3, 483, 417, 77, 33],
                [9, 4, 483, 384, 77, 33],
                [10, 1, 560, 325, 76, 29],
                [10, 2, 560, 354, 76, 31],
                [10, 3, 483, 354, 77, 31],
                [10, 4, 483, 325, 77, 29],
                [11, 1, 560, 266, 76, 30],
                [11, 2, 560, 296, 76, 29],
                [11, 3, 483, 296, 77, 29],
                [11, 4, 483, 266, 77, 30],
                [12, 1, 560, 208, 76, 29],
                [12, 2, 560, 237, 76, 29],
                [12, 3, 483, 237, 77, 29],
                [12, 4, 483, 208, 77, 29],
                [13, 1, 560, 148, 76, 31],
                [13, 2, 560, 179, 76, 29],
                [13, 3, 483, 177, 77, 31],
                [13, 4, 483, 148, 77, 31],
                [14, 1, 560, 90, 76, 30],
                [14, 2, 560, 120, 76, 29],
                [14, 3, 483, 120, 77, 29],
                [14, 4, 483, 90, 77, 30],
                [15, 1, 560, 30, 76, 31],
                [15, 2, 560, 60, 76, 31],
                [15, 3, 483, 60, 77, 31],
                [15, 4, 483, 30, 77, 31],
                [16, 1, 415, 30, 70, 30],
                [16, 2, 415, 60, 70, 30],
                [16, 3, 340, 60, 77, 30],
                [16, 4, 340, 30, 77, 30],
                [17, 1, 415, 90, 70, 30],
                [17, 2, 415, 120, 70, 29],
                [17, 3, 340, 120, 76, 29],
                [17, 4, 340, 90, 76, 30],
                [18, 1, 415, 149, 70, 28],  
                [18, 2, 415, 177, 70, 31],
                [18, 3, 340, 177, 75, 31],
                [18, 4, 340, 149, 75, 29],
                [19, 1, 415, 208, 70, 29],
                [19, 2, 415, 237, 70, 30],
                [19, 3, 340, 237, 75, 30],
                [19, 4, 340, 208, 75, 29],
                [20, 1, 416, 267, 68, 29],
                [20, 2, 416, 295, 68, 30],
                [20, 3, 340, 295, 76, 30],
                [20, 4, 340, 267, 76, 30],
                [21, 1, 416, 325, 68, 30],
                [21, 2, 416, 355, 68, 30],
                [21, 3, 340, 355, 76, 30],
                [21, 4, 340, 325, 76, 30],
                [22, 1, 416, 385, 68, 33],
                [22, 2, 416, 417, 68, 33],
                [22, 3, 340, 417, 76, 33],
                [22, 4, 340, 385, 76, 33],
                [23, 1, 416, 449, 68, 31],
                [23, 2, 416, 479, 68, 29],
                [23, 3, 340, 477, 76, 31],
                [23, 4, 340, 449, 76, 29],
                [24, 1, 416, 507, 68, 29],
                [24, 2, 416, 534, 68, 30],
                [24, 3, 340, 533, 76, 30],
                [24, 4, 340, 507, 76, 28],
                [25, 1, 416, 562, 68, 28],
                [25, 2, 416, 590, 68, 33],
                [25, 3, 340, 590, 76, 33],
                [25, 4, 340, 562, 76, 30],
                [26, 1, 267, 564, 72, 29],
                [26, 2, 267, 593, 72, 30],
                [26, 3, 195, 593, 72, 30],
                [26, 4, 195, 564, 72, 30],
                [27, 1, 266, 507, 73, 30],
                [27, 2, 266, 535, 73, 30],
                [27, 3, 195, 535, 73, 30],
                [27, 4, 195, 505, 73, 30],
                [28, 1, 268, 450, 71, 30],
                [28, 2, 268, 480, 71, 27],
                [28, 3, 195, 478, 73, 29],
                [28, 4, 195, 450, 72, 29],
                [29, 1, 266, 384, 73, 33],
                [29, 2, 266, 416, 73, 34],
                [29, 3, 195, 416, 73, 34],
                [29, 4, 195, 384, 73, 34],
                [30, 1, 267, 324, 73, 31],
                [30, 2, 267, 354, 73, 31],
                [30, 3, 195, 354, 73, 31],
                [30, 4, 195, 324, 73, 31],
                [31, 1, 267, 265, 73, 31],
                [31, 2, 267, 295, 73, 31],
                [31, 3, 195, 295, 73, 31],
                [31, 4, 195, 265, 73, 31],
                [32, 1, 267, 206, 73, 31],
                [32, 2, 267, 235, 73, 31],
                [32, 3, 195, 235, 73, 31],
                [32, 4, 195, 206, 73, 31],
                [33, 1, 267, 146, 73, 31],
                [33, 2, 267, 176, 73, 31],
                [33, 3, 196, 176, 73, 31],
                [33, 4, 196, 146, 73, 31],
                [34, 1, 267, 90, 73, 31],
                [34, 2, 267, 120, 73, 29],
                [34, 3, 196, 90, 73, 31],
                [34, 4, 196, 120, 73, 29],
                [35, 1, 267, 30, 73, 31],
                [35, 2, 267, 61, 73, 31],
                [35, 3, 196, 61, 73, 31],
                [35, 4, 196, 30, 73, 31],
                [36, 1, 126, 30, 72, 31],
                [36, 2, 126, 61, 72, 31],
                [36, 3, 46, 61, 80, 31],
                [36, 4, 46, 30, 80, 31],
                [37, 1, 126, 90, 72, 31],
                [37, 2, 126, 120, 72, 30],
                [37, 3, 46, 120, 80, 30],
                [37, 4, 46, 90, 80, 30],
                [38, 1, 126, 149, 72, 29],
                [38, 2, 126, 177, 72, 31],
                [38, 3, 46, 177, 80, 30],
                [38, 4, 46, 149, 80, 30],
                [39, 1, 126, 206, 71, 31],
                [39, 2, 126, 237, 71, 31],
                [39, 3, 46, 237, 80, 30],
                [39, 4, 46, 207, 80, 30],
                [40, 1, 126, 267, 71, 29],
                [40, 2, 126, 295, 71, 30],
                [40, 3, 46, 295, 80, 30],
                [40, 4, 46, 267, 80, 30],
                [41, 1, 126, 325, 71, 30],
                [41, 2, 126, 355, 71, 30],
                [41, 3, 46, 355, 80, 30],
                [41, 4, 46, 325, 80, 30],
                [42, 1, 126, 385, 71, 33],
                [42, 2, 126, 417, 71, 33],
                [42, 3, 46, 417, 80, 33],
                [42, 4, 46, 385, 80, 33],
                [43, 1, 126, 449, 71, 31],
                [43, 2, 126, 478, 71, 29],
                [43, 3, 46, 478, 80, 29],
                [43, 4, 46, 449, 80, 29],
                [44, 1, 126, 506, 71, 30],
                [44, 2, 126, 535, 71, 30],
                [44, 3, 46, 535, 80, 29],
                [44, 4, 46, 506, 80, 29],
                [45, 1, 126, 563, 71, 30],
                [45, 2, 126, 593, 71, 30],
                [45, 3, 46, 593, 80, 31],
                [45, 4, 46, 563, 80, 31],

              ];

              $deceasedD = get_section_deceased_map($conn, 'D');
              list($reservedD, $purchasedD) = get_section_reserved_and_purchased_maps($conn, 'D');
              $img_fileD = __DIR__ . '/section/images/section_d.png.png';
              $img_sizeD = @getimagesize($img_fileD);
              $img_widthD = $img_sizeD ? $img_sizeD[0] : 500;
              $img_heightD = $img_sizeD ? $img_sizeD[1] : 300;
              echo '<div style="position:absolute;top:0;left:0;width:' . $img_widthD . 'px;height:' . $img_heightD . 'px;pointer-events:auto;z-index:3;cursor:pointer;">';

              $uniquePlotsD = [];
              $seenD = [];
              foreach ($plotsD as $pD) {
                $kD = $pD[0] . '-' . $pD[1];
                if (!isset($seenD[$kD])) { $seenD[$kD] = true; $uniquePlotsD[] = $pD; }
              }
              foreach ($uniquePlotsD as $plotD) {
                list($x, $y, $left, $top, $box_w, $box_h) = $plotD;
                $hasDeceasedD = isset($deceasedD[$x . '-' . $y]) || isset($deceasedD[$x][$y]);
                $isPurchasedD = isset($purchasedD[$y][$x]) || isset($purchasedD[$x][$y]);
                $isReservedD = isset($reservedD[$y][$x]) || isset($reservedD[$x][$y]);
                $buttonStyleD = 'position:absolute;left:' . $left . 'px;top:' . $top . 'px;width:' . $box_w . 'px;height:' . $box_h . 'px;padding:0;border:1.5px solid #3a3a3aff;border-radius:6px;box-shadow:none;font-size:0.85em;overflow:hidden;z-index:2;pointer-events:auto;';
                $extraAttrsD = '';
                if ($hasDeceasedD) {
                  $buttonStyleD .= 'background:rgba(218, 57, 65, 0.5);color:#fff;font-weight:bold;';
                } elseif ($isPurchasedD) {
                  $buttonStyleD .= 'background:rgba(0, 0, 0, 0.35);color:#ffffff;';
                  $extraAttrsD = ' data-purchased="1" title="Purchased Plot"';
                } elseif ($isReservedD) {
                  $buttonStyleD .= 'background:rgba(0, 0, 0, 0.35);color:#ffffff;';
                  $extraAttrsD = ' data-reserved="1" title="This Plot is Reserved"';
                } else {
                  $buttonStyleD .= 'background:rgba(33, 150, 243, 0.35);color:#ffffff;';
                }
                $pidD = $plotIdMap[$y][$x] ?? '';
                echo '<button type="button" class="plot-btn"' . $extraAttrsD . ' data-plot-id="' . $pidD . '" data-plot-x="' . $x . '" data-plot-y="' . $y . '" style="' . $buttonStyleD . '">';
                echo '';
                echo '</button>';
              }
              echo '</div>';
            }

            if ($section === 'E') {
              include_once $connPath;

              $plotsE = [
                [1, 1, 709, 29, 72, 27],
                [1, 2, 709, 54, 72, 29],
                [1, 3, 635, 56, 75, 28],
                [1, 4, 635, 29, 75, 29],
                [2, 1, 709, 82, 72, 29],
                [2, 2, 709, 110, 72, 29],
                [2, 3, 635, 110, 75, 29],
                [2, 4, 635, 82, 75, 29],
                [3, 1, 709, 138, 72, 30],
                [3, 2, 709, 168, 72, 30],
                [3, 3, 635, 168, 75, 30],
                [3, 4, 635, 138, 75, 30],
                [4, 1, 709, 198, 72, 30],
                [4, 2, 709, 227, 72, 29],
                [4, 3, 635, 227, 75, 30],
                [4, 4, 635, 198, 75, 30],
                [5, 1, 709, 255, 72, 29],
                [5, 2, 709, 284, 72, 31],
                [5, 3, 635, 284, 75, 30],
                [5, 4, 635, 255, 75, 30],
                [6, 1, 709, 313, 72, 29],
                [6, 2, 709, 341, 72, 29],
                [6, 3, 635, 341, 75, 30],
                [6, 4, 635, 313, 75, 30],
                [7, 1, 709, 370, 72, 30],
                [7, 2, 709, 398, 72, 30],
                [7, 3, 635, 398, 75, 30],
                [7, 4, 635, 370, 75, 30],
                [8, 1, 709, 428, 72, 31],
                [8, 2, 709, 457, 72, 31],
                [8, 3, 635, 457, 75, 30],
                [8, 4, 635, 429, 75, 30],
                [9, 1, 709, 487, 72, 31],
                [9, 2, 709, 516, 72, 28],
                [9, 3, 635, 516, 75, 30],
                [9, 4, 635, 487, 75, 30],
                [10, 1, 709, 545, 75, 30],
                [10, 2, 709, 573, 75, 29],
                [10, 3, 635, 573, 75, 29],
                [10, 4, 635, 545, 75, 29],
                [11, 1, 565, 573, 72, 29],
                [11, 2, 565, 545, 72, 29],
                [11, 3, 493, 573, 72, 29],
                [11, 4, 493, 545, 72, 29],
                [12, 1, 565, 487, 72, 31],
                [12, 2, 565, 517, 72, 29],
                [12, 3, 493, 517, 72, 29],
                [12, 4, 493, 488, 72, 30],
                [13, 1, 565, 429, 73, 30],
                [13, 2, 565, 458, 73, 30],
                [13, 3, 493, 459, 72, 29],
                [13, 4, 493, 430, 72, 29],
                [14, 1, 565, 370, 73, 30],
                [14, 2, 565, 399, 73, 30],
                [14, 3, 493, 399, 72, 30],
                [14, 4, 493, 370, 72, 29],
                [15, 1, 565, 313, 73, 30],
                [15, 2, 565, 343, 73, 30],
                [15, 3, 493, 343, 72, 30],
                [15, 4, 493, 313, 72, 30],
                [16, 1, 565, 255, 73, 30],
                [16, 2, 565, 283, 73, 30],
                [16, 3, 493, 283, 72, 30],
                [16, 4, 493, 255, 72, 30],
                [17, 1, 565, 198, 73, 30],
                [17, 2, 565, 227, 73, 30],
                [17, 3, 493, 227, 72, 30],
                [17, 4, 493, 198, 72, 30],
                [18, 1, 565, 138, 73, 30],
                [18, 2, 565, 168, 73, 30],
                [18, 3, 493, 168, 72, 30],
                [18, 4, 493, 138, 72, 30],
                [19, 1, 565, 82, 73, 29],
                [19, 2, 565, 110, 73, 29],
                [19, 3, 493, 110, 72, 30],
                [19, 4, 493, 82, 72, 30],
                [20, 1, 565, 29, 73, 29],
                [20, 2, 565, 56, 73, 27],
                [20, 3, 493, 54, 72, 28],
                [20, 4, 493, 29, 72, 27],
                [21, 1, 422, 29, 72, 27],
                [21, 2, 422, 56, 72, 27],
                [21, 3, 349, 56, 73, 27],
                [21, 4, 349, 29, 73, 27],
                [22, 1, 422, 83, 72, 29],
                [22, 2, 422, 112, 72, 27],
                [22, 3, 349, 110, 73, 29],
                [22, 4, 349, 83, 73, 27],
                [23, 1, 422, 139, 72, 30],
                [23, 2, 422, 168, 72, 30],
                [23, 3, 349, 168, 73, 29],
                [23, 4, 349, 139, 73, 29],
                [24, 1, 422, 198, 72, 30],
                [24, 2, 422, 227, 72, 30],
                [24, 3, 349, 227, 73, 29],
                [24, 4, 349, 197, 73, 30],
                [25, 1, 422, 254, 72, 30],
                [25, 2, 422, 284, 72, 30],
                [25, 3, 349, 284, 72, 30],
                [25, 4, 349, 254, 72, 30],
                [26, 1, 422, 315, 72, 27],
                [26, 2, 422, 340, 72, 30],
                [26, 3, 349, 340, 72, 30],
                [26, 4, 349, 315, 72, 26],
                [27, 1, 422, 370, 72, 30],
                [27, 2, 422, 398, 72, 30],
                [27, 3, 349, 398, 73, 30],
                [27, 4, 349, 370, 73, 30],
                [28, 1, 422, 428, 72, 30],
                [28, 2, 422, 458, 72, 30],
                [28, 3, 349, 458, 73, 30],
                [28, 4, 349, 428, 73, 30],
                [29, 1, 422, 488, 72, 30],
                [29, 2, 422, 518, 72, 29],
                [29, 3, 349, 518, 73, 29],
                [29, 4, 349, 489, 73, 30],
                [30, 1, 422, 544, 72, 29],
                [30, 2, 422, 571, 72, 29],
                [30, 3, 349, 571, 73, 29],
                [30, 4, 349, 544, 73, 29],
                [31, 1, 275, 430, 74, 29],
                [31, 2, 275, 459, 74, 29],
                [31, 3, 275, 487, 74, 32],
                [31, 4, 275, 519, 75, 27],
                [32, 1, 275, 370, 75, 30],
                [32, 2, 275, 400, 75, 30],
                [32, 3, 209, 400, 67, 30],
                [32, 4, 209, 370, 67, 30],
                [33, 1, 275, 315, 75, 26],
                [33, 2, 275, 341, 75, 29],
                [33, 3, 209, 341, 68, 29],
                [33, 4, 209, 315, 68, 29],
                [34, 1, 275, 254, 75, 30],
                [34, 2, 275, 283, 75, 32],
                [34, 3, 209, 284, 68, 31],
                [34, 4, 209, 253, 68, 31],
                [35, 1, 275, 197, 75, 30],
                [35, 2, 275, 226, 75, 28],
                [35, 3, 209, 226, 68, 28],
                [35, 4, 209, 198, 68, 31],
                [36, 1, 275, 137, 75, 31],
                [36, 2, 275, 167, 75, 31],
                [36, 3, 209, 169, 68, 30],
                [36, 4, 209, 137, 68, 32],
                [37, 1, 275, 83, 75, 30],
                [37, 2, 275, 111, 75, 27],
                [37, 3, 209, 110, 69, 29],
                [37, 4, 209, 83, 69, 29],
                [38, 1, 275, 29, 75, 30],
                [38, 2, 275, 58, 75, 28],
                [38, 3, 209, 58, 69, 28],
                [38, 4, 209, 28, 68, 31],
                [39, 1, 137, 28, 74, 31],
                [39, 2, 137, 56, 74, 28],
                [39, 3, 137, 84, 74, 28],
                [39, 4, 137, 110, 74, 28],
                [40, 1, 137, 138, 74, 31],
                [40, 2, 137, 167, 74, 31],
                [40, 3, 137, 198, 74, 31],
                [40, 4, 137, 226, 74, 30],
                [41, 1, 137, 253, 74, 30],
                [41, 2, 137, 283, 74, 32],
                [41, 3, 137, 315, 74, 27],
                [41, 4, 137, 341, 74, 29],

              ];

              $deceasedE = get_section_deceased_map($conn, 'E');
              list($reservedE, $purchasedE) = get_section_reserved_and_purchased_maps($conn, 'E');
              $img_fileE = __DIR__ . '/section/images/section_e.png.png';
              $img_sizeE = @getimagesize($img_fileE);
              $img_widthE = $img_sizeE ? $img_sizeE[0] : 500;
              $img_heightE = $img_sizeE ? $img_sizeE[1] : 300;
              echo '<div style="position:absolute;top:0;left:0;width:' . $img_widthE . 'px;height:' . $img_heightE . 'px;pointer-events:auto;z-index:3;cursor:pointer;">';

              $uniquePlotsE = [];
              $seenE = [];
              foreach ($plotsE as $pE) {
                $kE = $pE[0] . '-' . $pE[1];
                if (!isset($seenE[$kE])) { $seenE[$kE] = true; $uniquePlotsE[] = $pE; }
              }
              foreach ($uniquePlotsE as $plotE) {
                list($x, $y, $left, $top, $box_w, $box_h) = $plotE;
                $hasDeceasedE = isset($deceasedE[$x . '-' . $y]) || isset($deceasedE[$x][$y]);
                $isPurchasedE = isset($purchasedE[$y][$x]) || isset($purchasedE[$x][$y]);
                $isReservedE = isset($reservedE[$y][$x]) || isset($reservedE[$x][$y]);
                $buttonStyleE = 'position:absolute;left:' . $left . 'px;top:' . $top . 'px;width:' . $box_w . 'px;height:' . $box_h . 'px;padding:0;border:1.5px solid #3a3a3aff;border-radius:6px;box-shadow:none;font-size:0.85em;overflow:hidden;z-index:2;pointer-events:auto;';
                $extraAttrsE = '';
                if ($hasDeceasedE) {
                  $buttonStyleE .= 'background:rgba(218, 57, 65, 0.5);color:#fff;font-weight:bold;';
                } elseif ($isPurchasedE) {
                  $buttonStyleE .= 'background:rgba(0, 0, 0, 0.35);color:#ffffff;';
                  $extraAttrsE = ' data-purchased="1" title="Purchased Plot"';
                } elseif ($isReservedE) {
                  $buttonStyleE .= 'background:rgba(0, 0, 0, 0.35);color:#ffffff;';
                  $extraAttrsE = ' data-reserved="1" title="This Plot is Reserved"';
                } else {
                  $buttonStyleE .= 'background:rgba(33, 150, 243, 0.35);color:#ffffff;';
                }
                $pidE = $plotIdMap[$y][$x] ?? '';
                echo '<button type="button" class="plot-btn"' . $extraAttrsE . ' data-plot-id="' . $pidE . '" data-plot-x="' . $x . '" data-plot-y="' . $y . '" style="' . $buttonStyleE . '">';
                echo '';
                echo '</button>';
              }
              echo '</div>';
            }

            if ($section === 'F') {
              include_once $connPath;

              $plotsF = [
                [1, 1, 697, 56, 80, 35],
                [1, 2, 697, 90, 79, 32],
                [1, 3, 622, 90, 76, 32],
                [1, 4, 622, 56, 76, 35],
                [2, 1, 697, 121, 79, 32],
                [2, 2, 697, 153, 78, 32],
                [2, 3, 622, 153, 76, 32],
                [2, 4, 622, 122, 76, 32],
                [3, 1, 698, 183, 78, 30],
                [3, 2, 698, 213, 78, 31],
                [3, 3, 622, 212, 78, 31],
                [3, 4, 622, 183, 78, 29],
                [4, 1, 698, 243, 78, 32],
                [4, 2, 698, 273, 78, 31],
                [4, 3, 622, 273, 78, 31],
                [4, 4, 622, 243, 78, 32],
                [5, 1, 698, 302, 78, 32],
                [5, 2, 698, 333, 78, 31],
                [5, 3, 622, 333, 78, 31],
                [5, 4, 622, 302, 78, 32],
                [6, 1, 698, 363, 78, 30],
                [6, 2, 698, 392, 78, 30],
                [6, 3, 622, 392, 78, 30],
                [6, 4, 622, 363, 78, 30],
                [7, 1, 698, 421, 78, 29],
                [7, 2, 698, 449, 78, 29],
                [7, 3, 622, 449, 78, 29],
                [7, 4, 622, 421, 78, 29],
                [8, 1, 698, 479, 78, 29],
                [8, 2, 698, 507, 78, 29],
                [8, 3, 622, 507, 78, 29],
                [8, 4, 622, 479, 78, 29],
                [9, 1, 698, 535, 78, 29],
                [9, 2, 698, 563, 78, 29],
                [9, 3, 698, 593, 78, 34],
                [9, 4, 622, 535, 78, 29],
                [9, 5, 622, 563, 78, 29],
                [9, 6, 622, 593, 78, 34],
                [10, 1, 549, 535, 75, 29],
                [10, 2, 549, 563, 75, 29],
                [10, 3, 549, 593, 75, 34],
                [10, 4, 480, 593, 70, 34],
                [10, 5, 480, 563, 70, 29],
                [10, 6, 480, 535, 70, 29],
                [11, 1, 549, 479, 74, 29],
                [11, 2, 549, 507, 74, 29],
                [11, 3, 480, 507, 70, 29],
                [11, 4, 480, 479, 70, 29],
                [12, 1, 549, 421, 74, 29],
                [12, 2, 549, 450, 74, 29],
                [12, 3, 480, 450, 70, 29],
                [12, 4, 480, 421, 70, 29],
                [13, 1, 549, 363, 75, 31],
                [13, 2, 549, 392, 75, 30],
                [13, 3, 480, 392, 70, 31],
                [13, 4, 480, 363, 70, 30],
                [14, 1, 549, 302, 75, 31],
                [14, 2, 549, 333, 75, 31],
                [14, 3, 480, 333, 70, 31],
                [14, 4, 480, 302, 70, 31],
                [15, 1, 549, 243, 75, 31],
                [15, 2, 549, 273, 75, 31],
                [15, 3, 480, 273, 70, 31],
                [15, 4, 480, 243, 70, 31],
                [16, 1, 549, 183, 75, 31],
                [16, 2, 549, 213, 75, 31],
                [16, 3, 480, 213, 70, 31],
                [16, 4, 480, 183, 70, 31],
                [17, 1, 549, 121, 75, 32],
                [17, 2, 549, 153, 75, 32],
                [17, 3, 480, 153, 70, 32],
                [17, 4, 480, 121, 70, 32],
                [18, 1, 549, 56, 75, 35],
                [18, 2, 549, 90, 75, 32],
                [18, 3, 480, 90, 70, 32],
                [18, 4, 480, 56, 70, 35],
                [19, 1, 409, 56, 71, 35],
                [19, 2, 409, 90, 71, 33],
                [19, 3, 335, 90, 74, 33],
                [19, 4, 335, 56, 74, 35],
                [20, 1, 409, 124, 71, 29],
                [20, 2, 409, 152, 71, 32],
                [20, 3, 335, 152, 74, 33],
                [20, 4, 335, 124, 74, 29],
                [21, 1, 409, 184, 71, 29],
                [21, 2, 409, 213, 71, 31],
                [21, 3, 335, 213, 74, 31],
                [21, 4, 335, 184, 74, 30],
                [22, 1, 409, 244, 72, 30],
                [22, 2, 409, 274, 72, 29],
                [22, 3, 335, 274, 74, 29],
                [22, 4, 335, 244, 74, 31],
                [23, 1, 409, 304, 72, 30],
                [23, 2, 409, 334, 72, 29],
                [23, 3, 335, 334, 74, 29],
                [23, 4, 335, 304, 74, 30],
                [24, 1, 409, 363, 72, 31],
                [24, 2, 409, 393, 72, 29],
                [24, 3, 335, 393, 74, 29],
                [24, 4, 335, 363, 74, 31],
                [25, 1, 409, 422, 72, 29],
                [25, 2, 409, 451, 72, 28],
                [25, 3, 335, 449, 74, 30],
                [25, 4, 335, 422, 74, 29],
                [26, 1, 409, 479, 72, 30],
                [26, 2, 409, 509, 72, 25],
                [26, 3, 335, 508, 74, 27],
                [26, 4, 335, 479, 74, 29],
                [27, 1, 409, 535, 72, 29],
                [27, 2, 409, 563, 72, 31],
                [27, 3, 409, 594, 72, 34],
                [27, 4, 335, 594, 73, 34],
                [27, 5, 335, 563, 74, 30],
                [27, 6, 335, 535, 74, 29],
                [28, 1, 262, 534, 74, 30],
                [28, 2, 262, 563, 74, 30],
                [28, 3, 262, 594, 74, 34],
                [28, 4, 187, 592, 75, 36],
                [28, 5, 187, 563, 75, 30],
                [28, 6, 187, 534, 75, 29],
                [29, 1, 262, 479, 74, 29],
                [29, 2, 262, 508, 74, 27],
                [29, 3, 186, 508, 76, 27],
                [29, 4, 186, 479, 76, 29],
                [30, 1, 262, 421, 74, 30],
                [30, 2, 262, 449, 74, 30],
                [30, 3, 186, 449, 77, 30],
                [30, 4, 186, 421, 77, 30],
                [31, 1, 262, 362, 74, 31],
                [31, 2, 262, 393, 74, 29],
                [31, 3, 186, 393, 77, 29],
                [31, 4, 186, 362, 77, 31],
                [32, 1, 262, 304, 74, 30],
                [32, 2, 262, 333, 74, 30],
                [32, 3, 186, 333, 77, 30],
                [32, 4, 186, 304, 77, 30],
                [33, 1, 262, 243, 73, 30],
                [33, 2, 262, 273, 73, 31],
                [33, 3, 187, 273, 75, 31],
                [33, 4, 187, 243, 75, 31],
                [34, 1, 262, 184, 74, 29],
                [34, 2, 262, 213, 74, 30],
                [34, 3, 186, 213, 76, 30],
                [34, 4, 186, 184, 76, 30],
                [35, 1, 262, 124, 74, 29],
                [35, 2, 262, 153, 74, 31],
                [35, 3, 186, 153, 76, 31],
                [35, 4, 186, 124, 76, 29],
                [36, 1, 262, 56, 74, 35],
                [36, 2, 262, 90, 74, 33],
                [36, 3, 186, 90, 76, 33],
                [36, 4, 186, 56, 76, 35],
                [37, 1, 107, 534, 80, 29],
                [37, 2, 107, 563, 80, 30],
                [37, 3, 107, 594, 80, 34],

              ];

              $deceasedF = get_section_deceased_map($conn, 'F');
              list($reservedF, $purchasedF) = get_section_reserved_and_purchased_maps($conn, 'F');
              $img_fileF = __DIR__ . '/section/images/section_f.png.png';
              $img_sizeF = @getimagesize($img_fileF);
              $img_widthF = $img_sizeF ? $img_sizeF[0] : 500;
              $img_heightF = $img_sizeF ? $img_sizeF[1] : 300;
              echo '<div style="position:absolute;top:0;left:0;width:' . $img_widthF . 'px;height:' . $img_heightF . 'px;pointer-events:auto;z-index:3;cursor:pointer;">';

              $uniquePlotsF = [];
              $seenF = [];
              foreach ($plotsF as $pF) {
                $kF = $pF[0] . '-' . $pF[1];
                if (!isset($seenF[$kF])) { $seenF[$kF] = true; $uniquePlotsF[] = $pF; }
              }
              foreach ($uniquePlotsF as $plotF) {
                list($x, $y, $left, $top, $box_w, $box_h) = $plotF;
                $hasDeceasedF = isset($deceasedF[$x . '-' . $y]) || isset($deceasedF[$x][$y]);
                $isPurchasedF = isset($purchasedF[$y][$x]) || isset($purchasedF[$x][$y]);
                $isReservedF = isset($reservedF[$y][$x]) || isset($reservedF[$x][$y]);
                $buttonStyleF = 'position:absolute;left:' . $left . 'px;top:' . $top . 'px;width:' . $box_w . 'px;height:' . $box_h . 'px;padding:0;border:1.5px solid #3a3a3aff;border-radius:6px;box-shadow:none;font-size:0.85em;overflow:hidden;z-index:2;pointer-events:auto;';
                $extraAttrsF = '';
                if ($hasDeceasedF) {
                  $buttonStyleF .= 'background:rgba(218, 57, 65, 0.5);color:#fff;font-weight:bold;';
                } elseif ($isPurchasedF) {
                  $buttonStyleF .= 'background:rgba(0, 0, 0, 0.35);color:#ffffff;';
                  $extraAttrsF = ' data-purchased="1" title="Purchased Plot"';
                } elseif ($isReservedF) {
                  $buttonStyleF .= 'background:rgba(0, 0, 0, 0.35);color:#ffffff;';
                  $extraAttrsF = ' data-reserved="1" title="This Plot is Reserved"';
                } else {
                  $buttonStyleF .= 'background:rgba(33, 150, 243, 0.35);color:#ffffff;';
                }
                $pidF = $plotIdMap[$y][$x] ?? '';
                echo '<button type="button" class="plot-btn"' . $extraAttrsF . ' data-plot-id="' . $pidF . '" data-plot-x="' . $x . '" data-plot-y="' . $y . '" style="' . $buttonStyleF . '">';
                echo '';
                echo '</button>';
              }
              echo '</div>';
            }
            echo '</div></div></div>';
          } else {
            $secUpper = $section ? strtoupper($section) : '';
            if (in_array($secUpper, ['G', 'H', 'I', 'J'], true)) {
              echo '<div class="alert alert-warning text-center p-4 rounded-4 shadow-sm" style="max-width:600px;margin:2rem auto;border:1px solid #ffeeba;background:#fff8e6;">';
              echo '<i class="fas fa-ban fs-1 text-warning mb-3"></i>';
              echo '<h4 class="fw-bold text-dark mb-2">Section ' . htmlspecialchars($secUpper) . ' — Currently Unavailable</h4>';
              echo '<p class="text-muted mb-3">Burial plots in Section ' . htmlspecialchars($secUpper) . ' are currently unavailable for booking or reservation. Please explore active Sections A through F.</p>';
              $unavailBackUrl = $baseWebPath . 'map.php';
              $unavailBackText = 'Back to Cemetery Map';
              $unavailFrom = $_GET['from'] ?? '';
              if ($unavailFrom === 'index_direct' || $unavailFrom === 'index') {
                  $unavailBackUrl = $baseWebPath . 'index.php#sections';
                  $unavailBackText = 'Back to Cemetery Sections';
              } elseif ($unavailFrom === 'dashboard') {
                  $unavailBackUrl = $baseWebPath . 'dashboard.php';
                  $unavailBackText = 'Back to Dashboard';
              } elseif ($unavailFrom === 'user_dashboard') {
                  $unavailBackUrl = $baseWebPath . 'user_dashboard.php';
                  $unavailBackText = 'Back to Dashboard';
              }
              echo '<a href="' . htmlspecialchars($unavailBackUrl) . '" class="btn btn-outline-danger btn-sm px-3"><i class="fas fa-arrow-left me-1"></i>' . htmlspecialchars($unavailBackText) . '</a>';
              echo '</div>';
            } else {
              echo '<div style="background:#f3f3f3; border:1px solid #ddd; border-radius:16px; padding:2rem; text-align:center;">';
              echo '<span style="font-size:1.1rem; color:#555;">No map image found for Section ' . htmlspecialchars($secUpper) . '.</span>';
              echo '</div>';
            }
          }
          ?>
        </div>

      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="reservePlotModal" tabindex="-1" aria-labelledby="reservePlotModalLabel" aria-hidden="true" style="z-index: 1065;">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-height: 92vh; max-height: 92dvh;">
    <div class="modal-content" style="border-radius: 1.25rem; overflow: hidden; max-height: 90vh; max-height: 90dvh; display: flex; flex-direction: column;">
      <div class="modal-header py-3 px-4 flex-shrink-0" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important; color: #fff; border-bottom: 1px solid rgba(255,255,255,0.15) !important;">
        <h5 class="modal-title fw-bold m-0 text-white" id="reservePlotModalLabel" style="font-weight:700; color:#ffffff !important;">Reserve Plot</h5>
        <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="reservePlotForm" class="d-flex flex-column flex-grow-1 overflow-hidden" style="min-height: 0; max-height: 100%;">
        <div class="modal-body" style="padding-top:12px; overflow-y: auto; flex: 1 1 auto; min-height: 0; max-height: calc(90vh - 140px); max-height: calc(90dvh - 140px); -webkit-overflow-scrolling: touch; overscroll-behavior: contain; touch-action: pan-y;">
          <div class="small mb-2" style="color:#333;">
            Location: Section <span id="reserve_loc_section">${section || ''}</span>, Plot <span id="reserve_loc_plotx">${plotX || ''}</span>-<span id="reserve_loc_ploty">${plotY || ''}</span>
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label text-dark" style="color:#111;">First Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control text-uppercase" id="res_first_name" required oninput="this.value=this.value.toUpperCase().replace(/[^A-Z\s]/g,'')">
            </div>
            <div class="col-md-6">
              <label class="form-label text-dark" style="color:#111;">Middle Name (optional)</label>
              <input type="text" class="form-control text-uppercase" id="res_middle_name" oninput="this.value=this.value.toUpperCase().replace(/[^A-Z\s]/g,'')">
            </div>
            <div class="col-md-6">
              <label class="form-label text-dark" style="color:#111;">Last Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control text-uppercase" id="res_last_name" required oninput="this.value=this.value.toUpperCase().replace(/[^A-Z\s]/g,'')">
            </div>
            <div class="col-md-6">
              <label class="form-label text-dark" style="color:#111;">Suffix (optional)</label>
              <select class="form-control" id="res_suffix">
                <option value="">No Suffix</option>
                <option value="JR.">JR.</option>
                <option value="SR.">SR.</option>
                <option value="II">II</option>
                <option value="III">III</option>
                <option value="IV">IV</option>
                <option value="V">V</option>
                <option value="OTHER">OTHER</option>
              </select>
              <input type="text" class="form-control mt-2 text-uppercase" id="res_suffix_other" placeholder="Enter custom suffix" style="display:none;" oninput="this.value=this.value.toUpperCase()">
            </div>
            <div class="col-md-6">
              <label class="form-label text-dark" style="color:#111;">Birth Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control" id="res_bday" required max="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label text-dark" style="color:#111;">Phone Number <span class="text-danger">*</span></label>
              <input type="tel" class="form-control" id="res_phone" required maxlength="11" inputmode="numeric" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
            </div>
            <div class="col-12">
              <label class="form-label text-dark" style="color:#111;">Address <span class="text-danger">*</span></label>
              <input type="text" class="form-control text-uppercase" id="res_address" required data-ph-address-selector="true" oninput="this.value=this.value.toUpperCase()">
            </div>
          </div>
          <input type="hidden" id="reserve_section" name="section">
          <input type="hidden" id="reserve_plot_x" name="plot_x">
          <input type="hidden" id="reserve_plot_y" name="plot_y">
        </div>
        <div class="modal-footer flex-shrink-0">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="submitReservePlot()">Reserve</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="editDeceasedModal" tabindex="-1" aria-labelledby="editDeceasedModalLabel" aria-hidden="true" data-bs-backdrop="static" style="z-index: 1065;">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-width: 820px; width: 94%; max-height: 90vh;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;">

      <div class="modal-header py-3 px-4 flex-shrink-0" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff; border-bottom: none;">
        <div class="d-flex align-items-center gap-3">
          <div style="background: rgba(255,255,255,0.15); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
            <i class="fas fa-user-edit text-white"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold m-0 text-white" id="editDeceasedModalLabel" style="font-family: 'Inter', sans-serif; font-size: 1.2rem; letter-spacing: -0.2px;">
              Edit Deceased Record
            </h5>
            <div style="font-size: 0.82rem; color: rgba(255,255,255,0.85);">Cemetery Management and Grave Mapping System — Update Deceased Information</div>
          </div>
        </div>
        <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="editDeceasedForm" class="d-flex flex-column flex-grow-1 overflow-hidden" style="min-height: 0; max-height: 100%;">
        <div class="modal-body p-4" style="background: #f8fafc; overflow-y: auto; flex: 1 1 auto; max-height: calc(90vh - 145px);">
          <input type="hidden" id="edit_deceased_id" name="deceased_id">

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; overflow: hidden; border: 1px solid #e2e8f0 !important;">
            <div class="p-3 d-flex flex-wrap align-items-center justify-content-between gap-2" style="background: #f1f5f9; border-bottom: 1px solid #e2e8f0;">
              <div class="d-flex align-items-center gap-2">
                <span class="badge px-3 py-2 fs-6 fw-bold" id="editDecSectionBadge" style="background: #7b1113; color: #fff;">Selected Plot</span>
                <span class="text-muted small fw-semibold" id="editDecPlotDisplay">Plot details</span>
              </div>
              <span class="badge bg-warning text-dark px-3 py-1 fw-bold"><i class="fas fa-pen me-1"></i>Editing Mode</span>
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
                  <input type="text" class="form-control form-control-sm text-uppercase" id="edit_first_name" name="first_name" placeholder="e.g., JUAN" required oninput="this.value=this.value.toUpperCase()">
                </div>
                <div class="col-md-4">
                  <label for="edit_middle_name" class="form-label small fw-semibold mb-1">Middle Name</label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="edit_middle_name" name="middle_name" placeholder="e.g., SANTOS" oninput="this.value=this.value.toUpperCase()">
                </div>
                <div class="col-md-4">
                  <label for="edit_last_name" class="form-label small fw-semibold mb-1">Last Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="edit_last_name" name="last_name" placeholder="e.g., DELA CRUZ" required oninput="this.value=this.value.toUpperCase()">
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
                  <input type="text" class="form-control form-control-sm mt-2 text-uppercase" id="edit_suffix_other" name="suffix_other" placeholder="Enter custom suffix" style="display:none;" oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="col-md-4">
                  <label for="edit_bday" class="form-label small fw-semibold mb-1">Birthday <span class="text-danger">*</span></label>
                  <input type="date" class="form-control form-control-sm" id="edit_bday" name="bday" required>
                </div>
                <div class="col-md-4">
                  <label for="edit_born_in" class="form-label small fw-semibold mb-1">Born In (Birthplace) <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="edit_born_in" name="born_in" placeholder="e.g., ALAMINOS CITY" required oninput="this.value=this.value.toUpperCase()">
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
                  <input type="tel" class="form-control form-control-sm" id="edit_contact_no" name="contact_no" inputmode="numeric" maxlength="11" minlength="11" pattern="09[0-9]{9}" placeholder="09XXXXXXXXX" title="Enter 11-digit mobile number starting with 09" required>
                </div>
                <div class="col-12">
                  <label for="edit_funeral_service" class="form-label small fw-semibold mb-1">Funeral Service <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="edit_funeral_service" name="funeral_service" placeholder="Enter funeral service name" required oninput="this.value=this.value.toUpperCase()">
                </div>
              </div>
            </div>
          </div>

          <!-- Hidden Booker & Payment fields to preserve data without showing UI -->
          <input type="hidden" id="edit_booker_name" name="booker_name" value="">
          <input type="hidden" id="edit_plan_cash" name="payment_plan" value="Cash">
          <input type="hidden" id="edit_payment_term" name="payment_term" value="12">
          <input type="hidden" id="edit_payment_status" name="payment_status" value="PAID">
          <input type="hidden" id="edit_total_amount" name="total_amount" value="85000.00">
          <input type="hidden" id="edit_amount_paid" name="amount_paid" value="85000.00">
          <input type="hidden" id="edit_monthly_due" name="monthly_due" value="0.00">
        </div>

        <div class="modal-footer d-flex justify-content-between px-4 py-3 flex-shrink-0" style="background:#fff; border-top: 1px solid #e2e8f0; z-index: 10;">
          <button type="button" class="btn btn-outline-secondary px-4 py-2 fw-semibold" data-bs-dismiss="modal" style="border-radius: 2rem;">
            <i class="fas fa-times me-1"></i> Cancel
          </button>
          <button type="button" class="btn btn-primary px-5 py-2 fw-bold shadow" onclick="submitEditDeceased()" style="border-radius: 2rem; background: #7b1113; border-color: #7b1113; min-width: 220px;">
            <i class="fas fa-save me-2"></i> Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="manageReservationModal" tabindex="-1" aria-labelledby="manageReservationModalLabel" aria-hidden="true" style="z-index: 1065;">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 680px; width: 94%; max-height: 92vh; max-height: 92dvh; margin: 1.5rem auto;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden; border: 1px solid rgba(0,0,0,0.08); max-height: 90vh; max-height: 90dvh; display: flex; flex-direction: column;">
      <div class="modal-header py-3 px-4 d-flex align-items-center justify-content-between flex-shrink-0" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff; border-bottom: none;">
        <div class="d-flex align-items-center gap-3">
          <div style="background: #ffffff; width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; padding: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.18); flex-shrink: 0;">
            <img src="<?= $baseWebPath ?>cmis_logo.png" alt="CMIS Logo" style="height: 34px; width: auto; object-fit: contain;" onerror="this.src='cmis_logo.png'">
          </div>
          <div>
            <h5 class="modal-title fw-bold m-0 text-white" id="manageReservationModalLabel" style="font-family: 'Inter', sans-serif; font-size: 1.2rem; letter-spacing: -0.2px;">Manage Reservation</h5>
            <div style="font-size: 0.82rem; color: rgba(255,255,255,0.85);">Reservation Owner & Contact Information</div>
          </div>
        </div>
        <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(0) invert(1); opacity: 0.85;"></button>
      </div>
      <form id="manageReservationForm" class="d-flex flex-column flex-grow-1 overflow-hidden" style="min-height: 0; max-height: 100%;">
        <div class="modal-body p-4" style="background: #f8fafc; overflow-y: auto; flex: 1 1 auto; min-height: 0; max-height: calc(90vh - 140px); max-height: calc(90dvh - 140px); -webkit-overflow-scrolling: touch; overscroll-behavior: contain; touch-action: pan-y;">

          <div class="card border-0 shadow-sm mb-4" style="border-radius: 0.9rem; overflow: hidden; border: 1px solid #e2e8f0 !important; background: #ffffff;">
            <div class="p-3 d-flex flex-wrap align-items-center justify-content-between gap-2" style="background: #f1f5f9; border-bottom: 1px solid #e2e8f0;">
              <div class="d-flex align-items-center gap-2">
                <span class="badge px-3 py-2 fs-6 fw-bold" style="background: #7b1113; color: #fff; border-radius: 8px;">
                  <i class="fas fa-map-pin me-1"></i> Section <span id="manage_loc_section"></span>
                </span>
                <span class="fw-semibold text-secondary" style="font-size: 0.95rem;">
                  Plot <span id="manage_loc_plotx" class="text-dark fw-bold"></span>-<span id="manage_loc_ploty" class="text-dark fw-bold"></span>
                </span>
              </div>
              <span class="badge px-3 py-2 fw-semibold" style="background: rgba(123, 17, 19, 0.1); color: #7b1113; border: 1px solid rgba(123, 17, 19, 0.2); border-radius: 8px;">
                <i class="fas fa-user-tag me-1"></i> Reserved Plot
              </span>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold text-dark small" style="color:#374151;"><i class="fas fa-user text-muted me-1"></i> First Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control text-uppercase text-dark" id="man_first_name" required oninput="this.value=this.value.toUpperCase().replace(/[^A-Z\s]/g,'')" placeholder="e.g. JUAN" style="border-radius: 8px; border: 1.5px solid #d1d5db; padding: 0.6rem 0.85rem;">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold text-dark small" style="color:#374151;"><i class="fas fa-user text-muted me-1"></i> Middle Name</label>
              <input type="text" class="form-control text-uppercase text-dark" id="man_middle_name" oninput="this.value=this.value.toUpperCase().replace(/[^A-Z\s]/g,'')" placeholder="e.g. SANTOS" style="border-radius: 8px; border: 1.5px solid #d1d5db; padding: 0.6rem 0.85rem;">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold text-dark small" style="color:#374151;"><i class="fas fa-user text-muted me-1"></i> Last Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control text-uppercase text-dark" id="man_last_name" required oninput="this.value=this.value.toUpperCase().replace(/[^A-Z\s]/g,'')" placeholder="e.g. DELA CRUZ" style="border-radius: 8px; border: 1.5px solid #d1d5db; padding: 0.6rem 0.85rem;">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold text-dark small" style="color:#374151;"><i class="fas fa-tag text-muted me-1"></i> Suffix</label>
              <input type="text" class="form-control text-uppercase text-dark" id="man_suffix" oninput="this.value=this.value.toUpperCase()" placeholder="e.g. JR, III (optional)" style="border-radius: 8px; border: 1.5px solid #d1d5db; padding: 0.6rem 0.85rem;">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold text-dark small" style="color:#374151;"><i class="fas fa-calendar-alt text-muted me-1"></i> Birth Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control text-dark" id="man_bday" required max="<?php echo date('Y-m-d'); ?>" style="border-radius: 8px; border: 1.5px solid #d1d5db; padding: 0.6rem 0.85rem;">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold text-dark small" style="color:#374151;"><i class="fas fa-phone text-muted me-1"></i> Phone Number <span class="text-danger">*</span></label>
              <input type="tel" class="form-control text-dark" id="man_phone" required maxlength="11" inputmode="numeric" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)" placeholder="e.g. 09123456789" style="border-radius: 8px; border: 1.5px solid #d1d5db; padding: 0.6rem 0.85rem;">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold text-dark small" style="color:#374151;"><i class="fas fa-home text-muted me-1"></i> Address <span class="text-danger">*</span></label>
              <input type="text" class="form-control text-uppercase text-dark" id="man_address" required data-ph-address-selector="true" oninput="this.value=this.value.toUpperCase()" placeholder="House No., Street, Barangay, City" style="border-radius: 8px; border: 1.5px solid #d1d5db; padding: 0.6rem 0.85rem;">
            </div>
          </div>
          <input type="hidden" id="man_section">
          <input type="hidden" id="man_plot_x">
          <input type="hidden" id="man_plot_y">
        </div>
        <div class="modal-footer px-4 py-3 flex-shrink-0" style="background: #ffffff; border-top: 1px solid #e2e8f0; z-index: 10;">
          <button type="button" class="btn btn-outline-secondary px-4 py-2" data-bs-dismiss="modal" style="border-radius: 8px; font-weight: 500;">Cancel</button>
          <button type="button" class="btn btn-primary px-4 py-2" onclick="submitManageReservation()" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); border: none; border-radius: 8px; font-weight: 600; box-shadow: 0 4px 12px rgba(123, 17, 19, 0.25);">
            <i class="fas fa-save me-1"></i> Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="moveDeceasedModal" tabindex="-1" aria-labelledby="moveDeceasedModalLabel" aria-hidden="true" style="z-index: 1065;">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden;">
      <div class="modal-header py-3 px-4 d-flex align-items-center justify-content-between flex-shrink-0" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff; border-bottom: none;">
        <div class="d-flex align-items-center gap-2">
          <div style="background: rgba(255,255,255,0.15); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem;">
            <i class="fas fa-exchange-alt text-white"></i>
          </div>
          <h5 class="modal-title fw-bold m-0 text-white" id="moveDeceasedModalLabel" style="font-family: 'Inter', sans-serif; font-size: 1.2rem;">Move Deceased Record</h5>
        </div>
        <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(0) invert(1); opacity: 0.85;"></button>
      </div>
      <form id="moveDeceasedForm">
        <div class="modal-body p-4" style="background: #f8fafc;">
          <input type="hidden" id="move_deceased_id" name="deceased_id">
          <div class="row g-3">
            <div class="col-4">
              <label for="move_section" class="form-label fw-semibold text-dark small">Section</label>
              <input type="text" class="form-control text-uppercase" id="move_section" name="new_section" required style="border-radius: 8px; border: 1.5px solid #d1d5db; padding: 0.6rem 0.85rem;">
            </div>
            <div class="col-4">
              <label for="move_plot_x" class="form-label fw-semibold text-dark small">Plot X</label>
              <input type="number" class="form-control" id="move_plot_x" name="new_plot_x" required style="border-radius: 8px; border: 1.5px solid #d1d5db; padding: 0.6rem 0.85rem;">
            </div>
            <div class="col-4">
              <label for="move_plot_y" class="form-label fw-semibold text-dark small">Plot Y</label>
              <input type="number" class="form-control" id="move_plot_y" name="new_plot_y" required style="border-radius: 8px; border: 1.5px solid #d1d5db; padding: 0.6rem 0.85rem;">
            </div>
          </div>
        </div>
        <div class="modal-footer px-4 py-3" style="background: #ffffff; border-top: 1px solid #e2e8f0;">
          <button type="button" class="btn btn-outline-secondary px-4 py-2" data-bs-dismiss="modal" style="border-radius: 8px; font-weight: 500;">Cancel</button>
          <button type="button" class="btn btn-primary px-4 py-2" onclick="submitMoveDeceased()" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); border: none; border-radius: 8px; font-weight: 600; box-shadow: 0 4px 12px rgba(123, 17, 19, 0.25);">Move Record</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="deceasedInfoModal" tabindex="-1" aria-labelledby="deceasedInfoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 680px; width: 94%; max-height: 92vh; margin: 1.5rem auto;">
    <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.18); max-height: 94vh; overflow: hidden; display: flex; flex-direction: column;">
      <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important; color: #fff; border-bottom: 1px solid rgba(255,255,255,0.15) !important; flex-shrink: 0;">
        <h5 class="modal-title fw-bold text-white m-0" id="deceasedInfoModalLabel" style="font-family: 'Inter', sans-serif; font-weight: 700; font-size: 1.18rem; margin: 0; color: #ffffff !important;">
          <i class="fas fa-user-circle me-2"></i>Deceased Information
        </h5>
        <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" style="padding: 1.25rem; background: #fafafa; overflow-y: auto; overflow-x: hidden; flex: 1 1 auto; max-height: calc(94vh - 58px);">
        <div id="deceasedInfoContent">

          <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Loading deceased information...</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="reservationChoiceModal" tabindex="-1" aria-labelledby="reservationChoiceModalLabel" aria-hidden="true" style="z-index: 1060;">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 520px;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
      <div class="modal-header py-3 px-4 text-white" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important;">
        <div class="d-flex align-items-center gap-2">
          <img src="<?= !empty($baseWebPath) ? $baseWebPath : '' ?>cmis_logo.png" alt="Garden of Remembrance" style="height: 36px; width: 36px; object-fit: contain; border-radius: 8px; background: rgba(255,255,255,0.18); padding: 2px;" onerror="this.src='cmis_logo.png'">
          <div>
            <h5 class="modal-title fw-bold text-white m-0" id="reservationChoiceModalLabel" style="font-size: 1.15rem;">
              Reserve This Plot
            </h5>
            <div style="font-size: 0.78rem; color: rgba(255,255,255,0.85);">Garden of Remembrance Memorial Park</div>
          </div>
        </div>
        <button type="button" class="btn-close custom-modal-close" onclick="returnToPlotInfoModal('reservationChoiceModal')" aria-label="Close"></button>
      </div>

      <div class="modal-body p-4" style="background: #faf8f5;">

        <div class="p-3 mb-3 rounded border bg-white d-flex align-items-center justify-content-between" style="border-color: rgba(123,17,19,0.15) !important;">
          <div>
            <div class="text-muted small text-uppercase fw-bold" style="font-size: 0.7rem;">SELECTED CEMETERY PLOT</div>
            <div class="fs-5 fw-bold text-dark font-monospace" id="choice_plot_display">Section — · Plot —</div>
            <div class="text-muted small" id="choice_price_display">Standard Memorial Lawn</div>
          </div>
          <span class="badge px-3 py-2" style="background: rgba(16, 185, 129, 0.15); color: #065f46; font-size: 0.82rem; font-weight: 600; border-radius: 999px;">
            AVAILABLE
          </span>
        </div>

        <div class="text-center mb-3">
          <h5 class="fw-bold text-dark mb-1" style="font-size: 1.18rem;">How would you like to proceed?</h5>
          <p class="text-muted small mb-0">Please choose how you would like to submit your reservation:</p>
        </div>

        <div class="d-grid gap-2 mb-2">

          <button type="button" class="btn p-3 text-start d-flex align-items-center gap-3 border shadow-sm bg-white" style="border-radius: 12px; border-color: #e5dbcf !important; transition: all 0.2s ease;" onmouseover="this.style.borderColor='#7b1113'; this.style.backgroundColor='#fdf7f7';" onmouseout="this.style.borderColor='#e5dbcf'; this.style.backgroundColor='#ffffff';" onclick="choiceSelectAccount()">
            <div class="rounded-circle p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(123,17,19,0.1); color: #7b1113; font-size: 1.2rem;">
              <i class="fas fa-user-check"></i>
            </div>
            <div class="flex-grow-1">
              <div class="fw-bold text-dark" style="font-size: 0.92rem;">I HAVE AN ACCOUNT</div>
              <div class="text-muted small" style="font-size: 0.76rem;">Log in to your account. Booking will verify your linked family deceased records.</div>
            </div>
            <i class="fas fa-chevron-right text-muted small"></i>
          </button>

          <button type="button" class="btn p-3 text-start d-flex align-items-center gap-3 border shadow-sm bg-white" style="border-radius: 12px; border-color: #e5dbcf !important; transition: all 0.2s ease;" onmouseover="this.style.borderColor='#7b1113'; this.style.backgroundColor='#fdf7f7';" onmouseout="this.style.borderColor='#e5dbcf'; this.style.backgroundColor='#ffffff';" onclick="choiceSelectExistingDeceased()">
            <div class="rounded-circle p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(197,155,39,0.15); color: #967417; font-size: 1.2rem;">
              <i class="fas fa-file-signature"></i>
            </div>
            <div class="flex-grow-1">
              <div class="fw-bold text-dark" style="font-size: 0.92rem;">I HAVE AN EXISTING DECEASED RECORD BUT NO ACCOUNT</div>
              <div class="text-muted small" style="font-size: 0.76rem;">Sign up and verify your existing deceased record to complete your reservation.</div>
            </div>
            <i class="fas fa-chevron-right text-muted small"></i>
          </button>
        </div>
      </div>

      <div class="modal-footer p-3 bg-white border-top text-end">
        <button type="button" class="btn btn-outline-secondary px-4" onclick="returnToPlotInfoModal('reservationChoiceModal')" style="border-radius: 8px;">Cancel</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="deceasedVerificationModal" tabindex="-1" aria-labelledby="deceasedVerificationModalLabel" aria-hidden="true" style="z-index: 1065;">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 580px;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 18px; overflow: hidden;">
      <div class="modal-header py-3 px-4 text-white" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important;">
        <div class="d-flex align-items-center gap-2">
          <img src="<?= !empty($baseWebPath) ? $baseWebPath : '' ?>cmis_logo.png" alt="Garden of Remembrance" style="height: 38px; width: 38px; object-fit: contain; border-radius: 8px; background: rgba(255,255,255,0.15); padding: 2px;" onerror="this.src='cmis_logo.png'">
          <div>
            <h5 class="modal-title fw-bold text-white m-0" id="deceasedVerificationModalLabel" style="font-size: 1.15rem;">
              Deceased Record Verification
            </h5>
            <div style="font-size: 0.8rem; color: rgba(255,255,255,0.85);">Existing Record Verification & Pending Request</div>
          </div>
        </div>
        <button type="button" class="btn-close custom-modal-close" onclick="returnToPlotInfoModal('deceasedVerificationModal')" aria-label="Close"></button>
      </div>
      <form id="deceasedVerificationForm" onsubmit="handleDeceasedVerificationSubmit(event)">
        <div class="modal-body p-4" style="background: #faf8f5;">

          <div class="p-3 mb-3 rounded border d-flex align-items-center justify-content-between bg-white" style="border-color: rgba(123,17,19,0.15) !important;">
            <div>
              <div class="text-muted small text-uppercase fw-bold" style="font-size: 0.7rem;">Target Grave Plot</div>
              <div class="fs-5 fw-bold text-dark font-monospace" id="dec_ver_plot_display">Section — · Plot —</div>
              <div class="text-muted small" id="dec_ver_price_display">Standard Memorial Lawn</div>
            </div>
            <span class="badge px-3 py-2" style="background: rgba(16, 185, 129, 0.15); color: #065f46; font-size: 0.82rem; font-weight: 600; border-radius: 999px;">
              AVAILABLE
            </span>
          </div>

          <div class="alert alert-light border py-2 px-3 mb-3 small d-flex align-items-center gap-2 text-muted" style="background: #fff; border-color: #e5dbcf !important;">
            <i class="fas fa-info-circle text-primary flex-shrink-0"></i>
            <span>Enter the deceased person's details. Matching an existing record will submit a <strong>PENDING</strong> request for Administrator verification.</span>
          </div>

          <h6 class="fw-bold border-bottom pb-1 mb-2" style="font-size: 0.85rem; color: #7b1113 !important;">
            <i class="fas fa-cross me-1"></i> Deceased Information
          </h6>
          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label text-dark fw-semibold small mb-1">First Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control form-control-sm" id="dec_ver_first_name" name="deceased_first_name" required placeholder="e.g. Pedro" style="border-radius: 6px;">
            </div>
            <div class="col-md-6">
              <label class="form-label text-dark fw-semibold small mb-1">Middle Name</label>
              <input type="text" class="form-control form-control-sm" id="dec_ver_middle_name" name="deceased_middle_name" placeholder="e.g. Santos" style="border-radius: 6px;">
            </div>
            <div class="col-md-8">
              <label class="form-label text-dark fw-semibold small mb-1">Last Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control form-control-sm" id="dec_ver_last_name" name="deceased_last_name" required placeholder="e.g. Dela Cruz" style="border-radius: 6px;">
            </div>
            <div class="col-md-4">
              <label class="form-label text-dark fw-semibold small mb-1">Suffix</label>
              <input type="text" class="form-control form-control-sm" id="dec_ver_suffix" name="deceased_suffix" placeholder="e.g. Jr., Sr." style="border-radius: 6px;">
            </div>
            <div class="col-md-6">
              <label class="form-label text-dark fw-semibold small mb-1">Date of Birth</label>
              <input type="date" class="form-control form-control-sm" id="dec_ver_bday" name="deceased_bday" style="border-radius: 6px;">
            </div>
            <div class="col-md-6">
              <label class="form-label text-dark fw-semibold small mb-1">Date of Death</label>
              <input type="date" class="form-control form-control-sm" id="dec_ver_date_died" name="deceased_date_died" style="border-radius: 6px;">
            </div>
            <div class="col-12">
              <label class="form-label text-dark fw-semibold small mb-1">Relationship to Deceased <span class="text-muted small fw-normal">(Optional)</span></label>
              <select class="form-select form-select-sm" id="dec_ver_relationship" name="deceased_relationship" style="border-radius: 6px;">
                <option value="">-- Select Relationship (Optional) --</option>
                <option value="Parent">Parent</option>
                <option value="Spouse">Spouse</option>
                <option value="Child">Child</option>
                <option value="Relative">Relative</option>
                <option value="Legal Guardian">Legal Guardian</option>
                <option value="Other">Other</option>
              </select>
            </div>
          </div>

          <h6 class="fw-bold border-bottom pb-1 mb-2" style="font-size: 0.85rem; color: #7b1113 !important;">
            <i class="fas fa-user me-1"></i> Requester Information
          </h6>
          <div class="row g-2">
            <div class="col-12">
              <label class="form-label text-dark fw-semibold small mb-1">Requester's Full Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control form-control-sm" id="dec_ver_req_name" name="full_name" required placeholder="e.g. Juan Dela Cruz" style="border-radius: 6px;">
            </div>
            <div class="col-md-6">
              <label class="form-label text-dark fw-semibold small mb-1">Email Address <span class="text-danger">*</span></label>
              <input type="email" class="form-control form-control-sm" id="dec_ver_req_email" name="email" required placeholder="name@example.com" style="border-radius: 6px;">
            </div>
            <div class="col-md-6">
              <label class="form-label text-dark fw-semibold small mb-1">Contact Number <span class="text-danger">*</span></label>
              <input type="tel" class="form-control form-control-sm" id="dec_ver_req_phone" name="contact_number" required placeholder="09XXXXXXXXX" maxlength="11" minlength="11" pattern="09[0-9]{9}" inputmode="numeric" oninput="formatPhilippineMobile(this)" style="border-radius: 6px;">
            </div>
            <div class="col-12">
              <label class="form-label text-dark fw-semibold small mb-1">Booking / Reservation Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control form-control-sm" id="dec_ver_preferred_date" name="preferred_date" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" style="border-radius: 6px;">
              <div class="form-text text-muted" style="font-size: 0.7rem;">Select your intended reservation or service date.</div>
            </div>
            <div class="col-12">
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="dec_ver_agreement" name="agreement" required>
                <label class="form-check-label small text-muted" for="dec_ver_agreement" style="font-size: 0.76rem;">
                  I confirm that the information provided is accurate and I have read and agree to the
                  <a href="#" onclick="document.getElementById('guestTermsModal').style.display='flex'; return false;" style="color:#7b1113; font-weight:600; text-decoration:underline;">Terms &amp; Conditions and No Refund Policy</a>
                  of Garden of Remembrance Memorial Park.
                </label>
              </div>
            </div>
          </div>

          <input type="hidden" id="dec_ver_section" name="section">
          <input type="hidden" id="dec_ver_plot_x" name="plot_x">
          <input type="hidden" id="dec_ver_plot_y" name="plot_y">
          <input type="hidden" id="dec_ver_lawn_type" name="lawn_type">
          <input type="hidden" name="request_type" value="deceased_verification">
          <input type="hidden" name="purpose" value="Add Additional Deceased">

        </div>
        <div class="modal-footer p-3 bg-white border-top d-flex justify-content-between">
          <button type="button" class="btn btn-light btn-sm" onclick="backToChoiceModal('deceasedVerificationModal')" style="border-radius: 6px;">
            <i class="fas fa-arrow-left me-1"></i> Back
          </button>
          <button type="submit" id="decVerSubmitBtn" class="btn btn-primary btn-sm px-3" style="background-color: #7b1113; border-color: #7b1113; border-radius: 6px; font-weight: 600;">
            <i class="fas fa-search me-1"></i> Submit Verification Request
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="guestReservationModal" tabindex="-1" aria-labelledby="guestReservationModalLabel" aria-hidden="true" style="z-index: 1065;">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 580px;">
    <div class="modal-content" style="border-radius: 18px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden;">
      <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important; color: #fff; border-bottom: 1px solid rgba(255,255,255,0.15) !important;">
        <div class="d-flex align-items-center gap-2">
          <img src="<?= !empty($baseWebPath) ? $baseWebPath : '' ?>cmis_logo.png" alt="Garden of Remembrance" style="height: 38px; width: 38px; object-fit: contain; border-radius: 8px; background: rgba(255,255,255,0.15); padding: 2px;" onerror="this.src='cmis_logo.png'">
          <div>
            <h5 class="modal-title fw-bold text-white m-0" id="guestReservationModalLabel" style="font-weight: 700; letter-spacing: 0.02em; font-size: 1.15rem; color: #ffffff !important;">
              Reserve Plot Without Deceased
            </h5>
            <div style="font-size: 0.8rem; color: rgba(255,255,255,0.85);">Reserve a plot for future use • No deceased record required</div>
          </div>
        </div>
        <button type="button" class="btn-close custom-modal-close" onclick="returnToPlotInfoModal('guestReservationModal')" aria-label="Close"></button>
      </div>
      <form id="guestReservationForm" enctype="multipart/form-data" onsubmit="handleGuestReservationSubmit(event)">
        <div class="modal-body p-4" style="background: #faf8f5;">

          <div class="p-3 mb-3 rounded border" style="background: #ffffff; border-color: rgba(123,17,19,0.15) !important;">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span class="text-muted small text-uppercase fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">TARGET CEMETERY PLOT</span>
              <span class="badge px-3 py-1" style="background: rgba(16, 185, 129, 0.15); color: #065f46; font-size: 0.78rem; font-weight: 600; border-radius: 999px;">
                AVAILABLE
              </span>
            </div>
            <div class="row g-2 text-start">
              <div class="col-6">
                <span class="text-muted small d-block" style="font-size: 0.75rem;">Section:</span>
                <strong class="text-dark fs-6" id="guest_res_section_disp">Section A</strong>
              </div>
              <div class="col-6">
                <span class="text-muted small d-block" style="font-size: 0.75rem;">Plot Number:</span>
                <strong class="text-dark fs-6 font-monospace" id="guest_res_plot_disp">Plot 1-1</strong>
              </div>
              <div class="col-6">
                <span class="text-muted small d-block" style="font-size: 0.75rem;">Plot ID:</span>
                <span class="badge bg-light text-dark border font-monospace" id="guest_res_plotid_disp">#—</span>
              </div>
              <div class="col-6">
                <span class="text-muted small d-block" style="font-size: 0.75rem;">Plot Type:</span>
                <span class="fw-semibold text-secondary" id="guest_res_type_disp">Lawn (Regular)</span>
              </div>
              <div class="col-12 pt-2 border-top mt-2 d-flex justify-content-between align-items-center">
                <span class="text-muted small">Current Plot Price:</span>
                <strong class="fs-6" style="color: #7b1113;" id="guest_res_price_disp">₱85,000.00</strong>
              </div>
            </div>
          </div>

          <div class="alert alert-light border py-2 px-3 mb-3 small d-flex align-items-center gap-2 text-muted" style="background: #fff; border-color: #e5dbcf !important;">
            <i class="fas fa-info-circle text-primary flex-shrink-0"></i>
            <span>This option reserves a plot for future use without assigning a deceased person at this time. Staff will review your submission and email an account creation link upon approval. You can add a deceased record to your reserved plot anytime in the future.</span>
          </div>

          <h6 class="fw-bold border-bottom pb-1 mb-2" style="font-size: 0.85rem; color: #7b1113 !important;">
            <i class="fas fa-user me-1"></i> Requester Information
          </h6>
          <div class="row g-2 mb-2">
            <div class="col-12">
              <label class="form-label text-dark fw-semibold small mb-1">Full Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control form-control-sm" id="guest_res_name" name="full_name" required placeholder="e.g. Juan Dela Cruz" style="border-radius: 6px;">
            </div>
            <div class="col-md-6">
              <label class="form-label text-dark fw-semibold small mb-1">Email Address <span class="text-danger">*</span></label>
              <input type="email" class="form-control form-control-sm" id="guest_res_email" name="email" required placeholder="name@example.com" style="border-radius: 6px;">
              <div class="form-text text-muted" style="font-size: 0.7rem;">Approval & account setup link will be emailed here.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label text-dark fw-semibold small mb-1">Contact Number <span class="text-danger">*</span></label>
              <input type="tel" class="form-control form-control-sm" id="guest_res_phone" name="contact_number" required placeholder="09XXXXXXXXX" maxlength="11" minlength="11" pattern="09[0-9]{9}" inputmode="numeric" oninput="formatPhilippineMobile(this)" style="border-radius: 6px;">
            </div>
            <div class="col-12">
              <label class="form-label text-dark fw-semibold small mb-1">Address <span class="text-danger">*</span></label>
              <input type="text" class="form-control form-control-sm" id="guest_res_address" name="address" required data-ph-address-selector="true" placeholder="Street, Barangay, City / Province" style="border-radius: 6px;">
            </div>
            <div class="col-12">
              <label class="form-label text-dark fw-semibold small mb-1">Booking / Reservation Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control form-control-sm" id="guest_res_preferred_date" name="preferred_date" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" onchange="if(typeof updateGuestPaymentPlanUI==='function') updateGuestPaymentPlanUI();" style="border-radius: 6px;">
              <div class="form-text text-muted" style="font-size: 0.7rem;">Select your intended reservation or booking date.</div>
            </div>
            <div class="col-12">
              <label class="form-label text-dark fw-semibold small mb-1">Special Requests / Remarks (Optional)</label>
              <textarea class="form-control form-control-sm" id="guest_res_remarks" name="remarks" rows="2" placeholder="Any special requests or instructions (optional)..." style="border-radius: 6px;"></textarea>
            </div>

            <!-- Payment Plan Selection (Installment vs Full Cash) -->
            <div class="col-12">
              <label class="form-label text-dark fw-semibold small mb-1">
                <i class="fas fa-credit-card me-1 text-danger"></i> Choose Payment Plan <span class="text-danger">*</span>
              </label>
              <div class="d-flex gap-2 mb-2">
                <label class="form-check form-check-inline flex-fill border rounded p-2 m-0 bg-white d-flex align-items-center" style="cursor: pointer;">
                  <input class="form-check-input ms-1 me-2" type="radio" name="payment_plan" id="guest_plan_installment" value="installment" checked onchange="updateGuestPaymentPlanUI()">
                  <div>
                    <div class="small fw-bold text-dark">Installment Plan</div>
                    <div class="text-muted" style="font-size:0.72rem;">20% Downpayment Today + Monthly</div>
                  </div>
                </label>
                <label class="form-check form-check-inline flex-fill border rounded p-2 m-0 bg-white d-flex align-items-center" style="cursor: pointer;">
                  <input class="form-check-input ms-1 me-2" type="radio" name="payment_plan" id="guest_plan_full" value="full" onchange="updateGuestPaymentPlanUI()">
                  <div>
                    <div class="small fw-bold text-dark">Full Cash Payment</div>
                    <div class="text-muted" style="font-size:0.72rem;">100% One-Time Full Payment</div>
                  </div>
                </label>
              </div>
            </div>

            <!-- Installment Term & Monthly Schedule Card -->
            <div class="col-12" id="guestInstallmentOptionsWrap">
              <div class="p-3 mb-2 rounded border" style="background:#ffffff; border-color:#e2e8f0;">
                <div class="row g-2 align-items-center">
                  <div class="col-sm-6">
                    <label class="form-label text-dark fw-semibold small mb-1">Installment Term <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" id="guest_res_payment_term" name="payment_term" onchange="updateGuestPaymentPlanUI()" style="border-radius:6px; font-weight:600;">
                      <option value="12">12 Months (1 Year)</option>
                      <option value="24">24 Months (2 Years)</option>
                      <option value="36" selected>36 Months (3 Years)</option>
                      <option value="48">48 Months (4 Years)</option>
                      <option value="60">60 Months (5 Years)</option>
                    </select>
                  </div>
                  <div class="col-sm-6 text-sm-end">
                    <span class="text-muted small d-block" style="font-size:0.75rem;">Monthly Installment:</span>
                    <strong class="fs-6 text-primary" id="guest_res_monthly_disp">₱2,668.80 / mo</strong>
                  </div>
                  <div class="col-12 mt-2 pt-2 border-top">
                    <div class="d-flex align-items-start gap-2 text-dark small">
                      <i class="fas fa-calendar-check text-success fs-6 mt-1"></i>
                      <div>
                        <div><strong>First Monthly Payment Due:</strong> <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" id="guest_res_first_due_disp">Next Month (exact date)</span></div>
                        <div class="text-muted" style="font-size:0.73rem; margin-top:2px;">
                          Pay downpayment today. Your monthly installment starts next month on the exact same date of payment.
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Downpayment & GCash Proof Section for Guest Reservation -->
            <div class="col-12">
              <div class="p-3 rounded border" style="background: linear-gradient(135deg, #f0fdf4 0%, #e0f2fe 100%); border-color: #bae6fd;">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                  <div class="d-flex align-items-center gap-1">
                    <i class="fas fa-wallet text-primary me-1"></i>
                    <strong class="text-dark small" id="guest_res_downpayment_title">20% Downpayment Required via GCash</strong>
                  </div>
                  <span class="badge bg-primary" id="guest_res_downpayment_badge" style="font-size: 0.75rem; white-space: normal; line-height: 1.25; text-align: right;">20% Downpayment Due</span>
                </div>
                <div class="small text-muted mb-2" id="guest_res_downpayment_desc" style="font-size: 0.78rem;">
                  To secure this plot reservation, an initial 20% downpayment is required. Please send payment to the official GCash number below, then provide your reference number and payment screenshot.
                </div>
                <div class="row g-2 align-items-center mb-2">
                  <div class="col-auto">
                    <img src="<?= !empty($baseWebPath) ? $baseWebPath : '' ?>assets/gcash-logo.png" alt="GCash Logo" class="p-1 rounded shadow-sm bg-white border" style="width: 75px; height: 75px; object-fit: contain;" onerror="this.onerror=null; this.src='assets/gcash-logo.png';">
                  </div>
                  <div class="col">
                    <div class="fw-bold text-dark small">Garden of Remembrance Memorial Park</div>
                    <div class="text-secondary small">GCash No: <strong class="text-dark">0917-823-4567</strong></div>
                    <div class="text-primary fw-bold small mt-1">
                      <span id="guest_res_amount_label">Downpayment Amount:</span> <span id="guest_res_downpayment_disp">20% of Plot Price</span>
                    </div>
                  </div>
                </div>
                <input type="hidden" id="guest_res_payment_ref" name="payment_reference" value="">
                <div id="guestPayNowStatus" class="mt-2 mb-2" style="display:none;"></div>
                <button type="button" id="guestPayNowBtn" class="btn btn-primary w-100 fw-bold btn-sm" onclick="guestPayNowViaGcash(event)" style="border-radius: 6px; padding: 10px 16px;">
                  <i class="fas fa-wallet me-2"></i> Pay Now — proceed to GCash
                </button>
                <div class="form-text text-center text-muted mt-1" id="guestPayNowHint" style="font-size:0.74rem;">Click Pay Now to settle securely via GCash. Your payment and reference number are automatically recorded.</div>

                <!-- Proof Upload Area (revealed after GCash payment completes) -->
                <div id="guestProofUploadArea" style="display:none;" class="mt-3">
                  <div class="border rounded p-3" style="background:#f9fafb; border-style: dashed !important; border-color: #7b1113 !important;">
                    <div class="text-center mb-2">
                      <i class="fas fa-cloud-upload-alt text-danger" style="font-size:1.6rem;"></i>
                      <div class="fw-bold small text-dark mt-1">Upload Payment Proof Screenshot <span class="text-danger">*</span></div>
                      <div class="text-danger fw-semibold" style="font-size:0.75rem;">Required &bull; Upload your GCash payment receipt or screenshot to proceed to verification</div>
                    </div>
                    <input type="file" id="guest_res_payment_proof" name="payment_proof" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp,application/pdf" style="font-size:0.8rem;" onchange="validateGuestProofSize(this)">
                    <div class="form-text mt-1" style="font-size:0.72rem;">Accepted: JPG, PNG, WEBP, PDF &bull; Max: 5MB</div>
                    <div id="guestProofPreview" class="mt-2" style="display:none;">
                      <img id="guestProofPreviewImg" src="" alt="Proof Preview" class="img-thumbnail" style="max-height:120px; max-width:100%; object-fit:contain;">
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-12">
              <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" id="guest_res_agreement" name="agreement" required>
                <label class="form-check-label small text-muted" for="guest_res_agreement" style="font-size: 0.76rem;">
                  I confirm that the information provided is accurate and I have read and agree to the
                  <a href="#" onclick="document.getElementById('guestTermsModal').style.display='flex'; return false;" style="color:#7b1113; font-weight:600; text-decoration:underline;">Terms &amp; Conditions and No Refund Policy</a>
                  of Garden of Remembrance Memorial Park.
                </label>
              </div>
            </div>
          </div>

          <input type="hidden" id="guest_res_section" name="section">
          <input type="hidden" id="guest_res_plot_x" name="plot_x">
          <input type="hidden" id="guest_res_plot_y" name="plot_y">
          <input type="hidden" id="guest_res_plot_id" name="plot_id">
          <input type="hidden" id="guest_res_lawn_type" name="lawn_type">
          <input type="hidden" name="request_type" value="no_deceased">
          <input type="hidden" name="purpose" value="Reserve New Grave">

        </div>
        <div class="modal-footer p-3 bg-white border-top d-flex justify-content-between">
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="returnToPlotInfoModal('guestReservationModal')" style="border-radius: 6px;">
              Cancel
            </button>
            <button type="button" class="btn btn-light btn-sm text-secondary" onclick="backToChoiceModal('guestReservationModal')" style="border-radius: 6px;" title="View all account & verification options">
              <i class="fas fa-list me-1"></i> More Options
            </button>
          </div>
          <button type="submit" id="guestResSubmitBtn" class="btn btn-primary btn-sm px-4" style="background-color: #7b1113; border-color: #7b1113; border-radius: 6px; font-weight: 600;">
            <i class="fas fa-paper-plane me-1"></i> Submit Reserve Request
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Terms & Conditions / No Refund Policy Modal -->
<div id="guestTermsModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:2000; align-items:center; justify-content:center; padding:16px;">
  <div style="background:#fff; border-radius:16px; max-width:520px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
    <div style="background:linear-gradient(135deg,#7b1113 0%,#470d0f 100%); padding:20px 24px; border-radius:16px 16px 0 0; display:flex; justify-content:space-between; align-items:center;">
      <div>
        <div style="color:#fff; font-weight:800; font-size:1.05rem;"><i class="fas fa-file-contract me-2"></i>Terms &amp; Conditions</div>
        <div style="color:rgba(255,255,255,0.8); font-size:0.78rem;">Garden of Remembrance Memorial Park</div>
      </div>
      <button type="button" onclick="document.getElementById('guestTermsModal').style.display='none';" style="background:rgba(255,255,255,0.2); border:none; color:#fff; border-radius:50%; width:32px; height:32px; font-size:1rem; cursor:pointer; display:flex; align-items:center; justify-content:center;">&times;</button>
    </div>
    <div style="padding:24px;">

      <div style="background:#fef2f2; border:1.5px solid #fca5a5; border-radius:10px; padding:14px 16px; margin-bottom:18px;">
        <div style="font-weight:800; color:#991b1b; font-size:0.95rem; margin-bottom:6px;"><i class="fas fa-ban me-2"></i>Strictly No Refund Policy</div>
        <p style="font-size:0.83rem; color:#7f1d1d; margin:0; line-height:1.6;">All downpayments and reservation fees paid to Garden of Remembrance Memorial Park are <strong>strictly non-refundable</strong> once processed. By submitting your reservation request and completing the GCash downpayment, you acknowledge and agree that no refunds will be issued under any circumstance, including cancellations initiated by the guest.</p>
      </div>

      <div style="margin-bottom:14px;">
        <div style="font-weight:700; color:#1e293b; font-size:0.88rem; margin-bottom:6px;"><i class="fas fa-handshake me-2 text-primary"></i>Reserve Agreement</div>
        <ul style="font-size:0.82rem; color:#374151; line-height:1.7; padding-left:18px; margin:0;">
          <li>The selected plot will be reserved under your name upon admin approval of this request.</li>
          <li>The required downpayment is <strong>20% of the total price of the plot</strong> and must be completed via GCash before submitting the request.</li>
          <li>Your reserve request is subject to review and approval by cemetery administration. Approval is not guaranteed.</li>
          <li>Misrepresentation of information may result in rejection or cancellation of the reserve without refund.</li>
        </ul>
      </div>

      <!-- 3 Months Non-Payment Cancellation Policy -->
      <div style="background:#fff5f5; border:1.5px solid #feb2b2; border-left:4px solid #dc3545; border-radius:10px; padding:14px 16px; margin-bottom:14px;">
        <div style="font-weight:800; color:#991b1b; font-size:0.92rem; margin-bottom:6px;">
          <i class="fas fa-calendar-times me-2 text-danger"></i>Three (3) Months Non-Payment Cancellation Policy
        </div>
        <p style="font-size:0.82rem; color:#7f1d1d; margin:0; line-height:1.6;">
          <strong>Automatic Cancellation:</strong> If a client fails to pay or settles no payment for <strong>three (3) consecutive months (90 days of non-payment or overdue installment amortizations)</strong>, the reserve will be automatically cancelled. The reserved plot will be immediately forfeited, released, and made available for other buyers. In accordance with memorial park policy, all previous payments and reservation fees are strictly non-refundable.
        </p>
      </div>

      <div style="margin-bottom:14px;">
        <div style="font-weight:700; color:#1e293b; font-size:0.88rem; margin-bottom:6px;"><i class="fas fa-undo-alt me-2 text-warning"></i>Cancellation Policy</div>
        <ul style="font-size:0.82rem; color:#374151; line-height:1.7; padding-left:18px; margin:0;">
          <li>Cancellation requests can be submitted via the cancellation link included in your confirmation email.</li>
          <li>All cancellation requests are reviewed by cemetery administration before the reservation is released.</li>
          <li><strong>Three (3) Months Non-Payment Forfeiture:</strong> Reserves with three (3) consecutive months of non-payment are automatically revoked, and the plot is released for new buyers without refund.</li>
          <li><strong>No refunds</strong> will be issued for any downpayments or fees already paid, regardless of the reason for cancellation.</li>
        </ul>
      </div>

      <div style="margin-bottom:20px;">
        <div style="font-weight:700; color:#1e293b; font-size:0.88rem; margin-bottom:6px;"><i class="fas fa-user-shield me-2 text-success"></i>Privacy &amp; Data</div>
        <p style="font-size:0.82rem; color:#374151; margin:0; line-height:1.6;">Your personal information is collected for the purpose of managing your memorial lot reserve and will not be shared with third parties outside of cemetery administration and related services.</p>
      </div>

      <button type="button" onclick="document.getElementById('guestTermsModal').style.display='none'; if(document.getElementById('guest_res_agreement')) document.getElementById('guest_res_agreement').checked=true; if(document.getElementById('dec_ver_agreement')) document.getElementById('dec_ver_agreement').checked=true;" class="btn w-100 fw-bold" style="background-color:#7b1113; color:#fff; border-radius:8px; padding:10px 16px; font-size:0.9rem;">
        <i class="fas fa-check-circle me-2"></i>I Understand &amp; Agree — Close
      </button>

    </div>
  </div>
</div>

<div class="modal fade" id="guestReservationSuccessModal" tabindex="-1" aria-labelledby="guestReservationSuccessModalLabel" aria-hidden="true" data-bs-backdrop="static" style="z-index: 1070;">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 500px;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
      <div class="modal-header py-3 px-4 text-white border-0" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important;">
        <div class="d-flex align-items-center gap-2">
          <img src="<?= !empty($baseWebPath) ? $baseWebPath : '' ?>cmis_logo.png" alt="Garden of Remembrance" style="height: 36px; width: 36px; object-fit: contain; border-radius: 8px; background: rgba(255,255,255,0.18); padding: 2px;" onerror="this.src='cmis_logo.png'">
          <div>
            <h5 class="modal-title fw-bold text-white m-0" id="guestReservationSuccessModalLabel" style="font-size: 1.1rem; letter-spacing: 0.02em;">
              Reserve Request Submitted
            </h5>
            <div style="font-size: 0.78rem; color: rgba(255,255,255,0.85);">Garden of Remembrance Memorial Park</div>
          </div>
        </div>
      </div>

      <div class="modal-body p-4 text-center" style="background: #faf8f5;">

        <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow-sm" style="width: 76px; height: 76px; background: #ecfdf5; border: 2px solid #a7f3d0;">
          <i class="fas fa-check text-success" style="font-size: 2.3rem;"></i>
        </div>

        <h5 class="fw-bold text-dark mb-1" id="succ_modal_title">Payment Proof Submitted</h5>
        <div class="mb-3">
          <span class="badge px-3 py-2" id="succ_status_badge" style="background: #fef3c7; color: #92400e; font-size: 0.82rem; font-weight: 600; border-radius: 999px; border: 1px solid #fde68a;">
            <i class="fas fa-hourglass-half me-1"></i> Status: For Verification / Waiting for Admin Verification
          </span>
        </div>

        <p class="text-muted small mb-3" id="succ_modal_desc" style="line-height: 1.6;">
          Payment proof submitted. Waiting for admin verification. Once the administrator approves your proof, your reservation will be confirmed and an account creation link will be emailed to you.
        </p>

        <div class="p-3 mb-3 rounded border text-start" style="background: #ffffff; border-color: #e5dbcf !important;">
          <div class="d-flex justify-content-between align-items-center pb-2 mb-2 border-bottom">
            <span class="text-muted small">Reservation Request #</span>
            <span class="fw-bold font-monospace text-dark" id="succ_booking_id">#—</span>
          </div>
          <div class="d-flex justify-content-between align-items-center pb-2 mb-2 border-bottom">
            <span class="text-muted small">Selected Plot</span>
            <span class="fw-bold text-dark" id="succ_plot_disp">—</span>
          </div>
          <div class="d-flex justify-content-between align-items-center pb-2 mb-2 border-bottom">
            <span class="text-muted small">Guest Name</span>
            <span class="fw-bold text-dark" id="succ_name_disp">—</span>
          </div>
          <div class="d-flex justify-content-between align-items-center pb-2 mb-2 border-bottom">
            <span class="text-muted small">Booking / Reservation Date</span>
            <span class="fw-semibold text-primary small" id="succ_pref_date_disp">—</span>
          </div>
          <div class="d-flex justify-content-between align-items-center pb-2 mb-2 border-bottom">
            <span class="text-muted small">Date Submitted</span>
            <span class="fw-semibold text-dark small" id="succ_date_disp">—</span>
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <span class="text-muted small">Email Address</span>
            <span class="text-dark font-monospace small" id="succ_email_disp">—</span>
          </div>
          <div class="d-flex justify-content-between align-items-center pt-2 mt-2 border-top" id="succ_payment_row" style="display: none !important;">
            <span class="text-muted small">Downpayment (GCash)</span>
            <span class="fw-bold text-success small" id="succ_payment_disp">—</span>
          </div>
        </div>

        <div class="alert alert-info border-0 p-3 mb-0 text-start small d-flex gap-2 align-items-start" style="background: #eff6ff; color: #1e40af; border-radius: 12px;">
          <i class="fas fa-envelope-open-text fs-5 flex-shrink-0 mt-1"></i>
          <div>
            <strong>What happens next?</strong><br>
            Once our staff approves your reservation, an <strong>Account Creation Link</strong> will be sent to your email. You will use that link to set your password and access your reservation.
          </div>
        </div>
      </div>

      <div class="modal-footer p-3 bg-white border-top d-flex justify-content-end">
        <button type="button" class="btn btn-primary px-4" data-bs-dismiss="modal" style="background-color: #7b1113; border-color: #7b1113; border-radius: 10px; font-weight: 600;">
          <i class="fas fa-check me-1"></i> Got It
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="loginRequiredModal" tabindex="-1" aria-labelledby="loginRequiredModalLabel" aria-hidden="true" style="z-index: 1065;">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
    <div class="modal-content" style="border-radius: 18px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden;">
      <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important; color: #fff; border-bottom: 1px solid rgba(255,255,255,0.15) !important;">
        <h5 class="modal-title fw-bold text-white d-flex align-items-center m-0" id="loginRequiredModalLabel" style="font-weight: 700; letter-spacing: 0.04em; margin: 0; font-size: 1.15rem; color: #ffffff !important;">
          <i class="fas fa-lock me-2"></i>LOGIN REQUIRED
        </h5>
        <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center p-4">
        <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 70px; height: 70px; background: rgba(123, 17, 19, 0.1);">
          <i class="fas fa-user-lock" style="font-size: 2rem; color: #7b1113;"></i>
        </div>
        <h5 class="fw-bold mb-2" id="loginRequiredPlotText" style="color: #2b211f;">Burial Plot Reservation</h5>
        <p class="text-muted mb-4" style="font-size: 1.05rem; line-height: 1.6;">
          You need to log in or create an account to reserve a burial plot.
        </p>
        <div class="p-3 mb-4 rounded bg-light border text-start small text-muted d-flex align-items-center" style="background: #faf8f5 !important; border-color: rgba(123,17,19,0.12) !important;">
          <i class="fas fa-check-circle text-success me-2 fs-5"></i>
          <span>Your selected plot will be <strong>automatically preserved</strong> after signing in.</span>
        </div>
        <div class="d-grid gap-2">
          <a href="#" id="loginRequiredLoginBtn" class="btn btn-primary btn-lg" style="background-color: #7b1113; border-color: #7b1113; border-radius: 10px; font-weight: 600;">Log In</a>
          <a href="#" id="loginRequiredSignUpBtn" class="btn btn-outline-primary btn-lg" style="color: #7b1113; border-color: #7b1113; border-radius: 10px; font-weight: 600;">Sign Up</a>
          <button type="button" class="btn btn-link text-muted mt-1" data-bs-dismiss="modal" style="text-decoration: none;">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="addDeceasedModal" tabindex="-1" aria-labelledby="addDeceasedModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-height: 92vh; margin: 1.5rem auto;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;">

      <div class="modal-header py-3 px-4 flex-shrink-0" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff; border-bottom: none;">
        <div class="d-flex align-items-center gap-2">
          <div style="background: rgba(255,255,255,0.15); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
            <i class="fas fa-user-plus text-white"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold m-0" id="addDeceasedModalLabel" style="font-family: 'Inter', sans-serif; font-size: 1.2rem; letter-spacing: -0.2px;">
              Add Deceased Record
            </h5>
            <div style="font-size: 0.82rem; color: rgba(255,255,255,0.85);">Cemetery Management and Grave Mapping System — Record Registration</div>
          </div>
        </div>
        <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="addDeceasedForm" class="d-flex flex-column flex-grow-1 overflow-hidden" style="min-height: 0; max-height: 100%;">
        <div class="modal-body p-4" style="background: #f8fafc; overflow-y: auto; flex: 1 1 auto; max-height: calc(90vh - 145px);">
          <script>
          // Auto-uppercase for name fields
          function toUpperCaseInput(e) {
            e.target.value = e.target.value.toUpperCase();
          }
          document.addEventListener('DOMContentLoaded', function() {
            ['first_name','middle_name','last_name','born_in','section','funeral_service','edit_funeral_service'].forEach(function(id) {
              var el = document.getElementById(id);
              if (el) el.addEventListener('input', toUpperCaseInput);
            });
            // Format contact number input (digits only, max 11)
            var contactEl = document.getElementById('contact_no');
            if (contactEl) {
              contactEl.addEventListener('input', function(e) {
                // Keep digits only and cap to 11 characters
                var v = e.target.value.replace(/\D/g, '');
                e.target.value = v.slice(0, 11);
              });
            }
            // Format edit contact number input (digits only, max 11)
            var editContactEl = document.getElementById('edit_contact_no');
            if (editContactEl) {
              editContactEl.addEventListener('input', function(e) {
                var v = e.target.value.replace(/\D/g, '');
                e.target.value = v.slice(0, 11);
              });
            }
            // Set max date for birthday and date died
            var today = new Date().toISOString().split('T')[0];
            var bdayEl = document.getElementById('bday');
            var diedEl = document.getElementById('date_died');
            if (bdayEl) bdayEl.setAttribute('max', today);
            if (diedEl) diedEl.setAttribute('max', today);
          });
          </script>

          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; overflow: hidden; border: 1px solid #e2e8f0 !important;">
            <div class="p-3 d-flex flex-wrap align-items-center justify-content-between gap-2" style="background: #f1f5f9; border-bottom: 1px solid #e2e8f0;">
              <div class="d-flex align-items-center gap-2">
                <span class="badge px-3 py-2 fs-6 fw-bold" id="addDecSectionBadge" style="background: #7b1113; color: #fff;">Selected Plot</span>
                <span class="text-muted small fw-semibold" id="addDecPlotDisplay">Plot details</span>
              </div>
              <span class="badge bg-success px-3 py-1" id="addDecStatusBadge">Ready for Record</span>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-3" id="workflowTypeCard" style="border-radius: 1rem; border: 1px solid #fed7aa !important; background: #fffaf0;">
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
                <span id="recordTypeNoticeText">Recording plot ownership/purchase only. Deceased details are optional and will not be validated.</span>
              </div>
            </div>
          </div>

          <div id="reservedPlotNoticeCard" class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1.5px solid #fed7aa !important; background: #fffdfa; border-left: 5px solid #7b1113 !important; display: none;">
            <div class="card-body p-3">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <span class="text-muted small fw-semibold">Reserved Plot / Owner:</span>
                  <div class="fw-bold text-dark fs-6" id="reservedPlotOwnerDisplay">—</div>
                </div>
                <span class="badge bg-warning text-dark px-3 py-1.5"><i class="fas fa-bookmark me-1"></i>Reserved Plot</span>
              </div>
              <div class="small text-muted mt-2 border-top pt-2">
                <i class="fas fa-info-circle text-primary me-1"></i> Clean record form: Please provide the deceased person's details below to assign them to this plot.
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
                  <input type="text" class="form-control form-control-sm text-uppercase" id="first_name" name="first_name" placeholder="e.g., JUAN">
                </div>
                <div class="col-md-4">
                  <label for="middle_name" class="form-label small fw-semibold mb-1">Middle Name</label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="middle_name" name="middle_name" placeholder="e.g., SANTOS">
                </div>
                <div class="col-md-4">
                  <label for="last_name" class="form-label small fw-semibold mb-1">Last Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="last_name" name="last_name" placeholder="e.g., DELA CRUZ">
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
                  <script>
                  document.addEventListener('DOMContentLoaded', function() {
                    var sel = document.getElementById('suffix');
                    var other = document.getElementById('suffix_other');
                    function sync() {
                      if (sel && sel.value === 'OTHER') { other.style.display = 'block'; }
                      else if (other) { other.style.display = 'none'; other.value = ''; }
                    }
                    if (sel) { sel.addEventListener('change', sync); sync(); }
                  });
                  </script>
                </div>
                <div class="col-md-4">
                  <label for="bday" class="form-label small fw-semibold mb-1">Birthday <span class="text-danger">*</span></label>
                  <input type="date" class="form-control form-control-sm" id="bday" name="bday">
                </div>
                <div class="col-md-4">
                  <label for="born_in" class="form-label small fw-semibold mb-1">Born In (Birthplace) <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="born_in" name="born_in" placeholder="e.g., ALAMINOS CITY">
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
                  <label for="contact_no" class="form-label small fw-semibold mb-1">Family Contact Number <span class="text-danger">*</span></label>
                  <input type="tel" class="form-control form-control-sm" id="contact_no" name="contact_no" inputmode="numeric" maxlength="11" minlength="11" pattern="09[0-9]{9}" placeholder="09XXXXXXXXX" title="Enter 11-digit mobile number starting with 09">
                </div>
                <div class="col-12">
                  <label for="funeral_service" class="form-label small fw-semibold mb-1">Funeral Service <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="funeral_service" name="funeral_service" placeholder="Enter funeral service name">
                </div>
              </div>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-3" id="addPaymentCard" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important;">
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
                    Buyer Contact No. <span class="text-danger">*</span>
                  </label>
                  <input type="tel" class="form-control form-control-sm" id="add_buyer_contact" name="buyer_contact" inputmode="numeric" maxlength="11" minlength="11" pattern="09[0-9]{9}" placeholder="09XXXXXXXXX" title="Enter 11-digit mobile number starting with 09">
                </div>

                <div class="col-md-6">
                  <label class="form-label small fw-semibold mb-1">Payment Plan <span class="text-danger">*</span></label>
                  <div class="d-flex gap-2">
                    <div class="form-check flex-fill p-2 border rounded text-center" style="cursor:pointer; background:#f8fafc;" id="wrap_plan_cash">
                      <input class="form-check-input ms-0 me-2" type="radio" name="payment_plan" id="plan_cash" value="Cash" checked onchange="toggleAddDeceasedPaymentPlan()">
                      <label class="form-check-label fw-bold text-dark small" for="plan_cash" style="cursor:pointer;">
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

          <!-- Cemetery Record Registration Terms & Agreements -->
          <div class="card border-0 shadow-sm mb-3" style="border-radius: 0.85rem; overflow: hidden; border: 1px solid #e2e8f0 !important; background: #fff;">
            <div class="p-3">
              <div class="form-check d-flex align-items-start gap-2 mb-0">
                <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" id="add_dec_terms_agreement" name="terms_agreement" required style="cursor: pointer; width: 1.15em; height: 1.15em; border-color: #7b1113;">
                <div class="small text-dark" style="line-height: 1.45;">
                  <label class="form-check-label mb-0" for="add_dec_terms_agreement" style="cursor: pointer;">
                    I confirm that all deceased details, burial permits, and plot ownership authorizations comply with the 
                  </label>
                  <a href="javascript:void(0)" class="fw-bold text-decoration-underline" style="color: #7b1113; position: relative; z-index: 10; cursor: pointer;" onclick="event.preventDefault(); event.stopPropagation(); openCemeteryTermsModal(event, 'record'); return false;">Terms and Agreements</a> 
                  <label class="form-check-label mb-0" for="add_dec_terms_agreement" style="cursor: pointer;">
                    of Garden of Remembrance Memorial Park. <span class="text-danger">*</span>
                  </label>
                </div>
              </div>
            </div>
          </div>

          <input type="hidden" id="plot_section" name="section">
          <input type="hidden" id="plot_x" name="plot_x">
          <input type="hidden" id="plot_y" name="plot_y">
        </div>

        <div class="modal-footer d-flex justify-content-between px-4 py-3 flex-shrink-0" style="background:#fff; border-top: 1px solid #e2e8f0; z-index: 10;">
          <button type="button" class="btn btn-outline-secondary px-4 py-2 fw-semibold" data-bs-dismiss="modal" style="border-radius: 2rem;">
            <i class="fas fa-times me-1"></i> Cancel
          </button>
          <button type="button" id="addDecSubmitBtn" class="btn btn-primary px-5 py-2 fw-bold shadow" onclick="submitAddDeceased()" style="border-radius: 2rem; background: #7b1113; border-color: #7b1113; min-width: 220px;">
            <i class="fas fa-check-circle me-2"></i> Save Plot Record
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Dedicated Modal for Adding Deceased to Occupied Plots ("May laman na" / Inter on Top / Patong) -->
<div class="modal fade" id="addOccupiedDeceasedModal" tabindex="-1" aria-labelledby="addOccupiedDeceasedModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-scrollable" style="max-height: calc(100vh - 2rem); margin: 1rem auto;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden; max-height: calc(100vh - 2rem); display: flex; flex-direction: column;">

      <div class="modal-header py-3 px-4 flex-shrink-0" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff; border-bottom: none;">
        <div class="d-flex align-items-center gap-2">
          <div style="background: rgba(255,255,255,0.18); width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem;">
            <i class="fas fa-layer-group text-white"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold m-0" id="addOccupiedDeceasedModalLabel" style="font-family: 'Inter', sans-serif; font-size: 1.2rem; letter-spacing: -0.2px;">
              Add Deceased to Occupied Plot (Interment Layering)
            </h5>
            <div style="font-size: 0.82rem; color: rgba(255,255,255,0.85);">Cemetery Management — Secondary Interment & Existing Occupants Check</div>
          </div>
        </div>
        <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="addOccupiedDeceasedForm" class="d-flex flex-column flex-grow-1 overflow-hidden" style="min-height: 0; max-height: 100%;">
        <div class="modal-body p-4" style="background: #f8fafc; overflow-y: auto; flex: 1 1 auto; max-height: calc(100vh - 12rem);">

          <!-- Plot Coordinates and Capacity Card -->
          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; overflow: hidden; border: 1px solid #e2e8f0 !important;">
            <div class="p-3 d-flex flex-wrap align-items-center justify-content-between gap-2" style="background: #f1f5f9; border-bottom: 1px solid #e2e8f0;">
              <div class="d-flex align-items-center gap-2">
                <span class="badge px-3 py-2 fs-6 fw-bold" id="occPlotSectionBadge" style="background: #7b1113; color: #fff;">Section A</span>
                <span class="text-muted small fw-semibold" id="occPlotCoordsDisplay">Plot Coordinates: Column 1, Row 1</span>
              </div>
              <div id="occPlotSlotsBadgeContainer">
                <span class="badge bg-secondary px-3 py-1.5" id="occPlotSlotsBadge" style="font-size: 0.82rem;">Occupants: 1/4 (3 slots left)</span>
              </div>
            </div>
          </div>

          <!-- Existing Occupant(s) and Interment Eligibility Card ("Ilang taon na nakalibing & kung pwede na magpatong") -->
          <div class="card border-0 shadow-sm mb-3" id="occExistingInfoCard" style="border-radius: 1rem; border: 1.5px solid #cbd5e1 !important; background: #ffffff;">
            <div class="card-header bg-white border-bottom py-2.5 px-3 d-flex align-items-center justify-content-between">
              <span class="fw-bold text-dark d-flex align-items-center gap-2" style="font-size: 0.92rem;">
                <i class="fas fa-monument text-danger"></i> Currently Interred Occupant(s) & Burial Duration
              </span>
              <span class="badge bg-light text-dark border px-2.5 py-1 rounded-pill" id="occCountLabel" style="font-size: 0.78rem;">1 Recorded</span>
            </div>
            <div class="card-body p-3" id="occExistingListContainer">
              <!-- Dynamically populated -->
            </div>
          </div>

          <!-- Clean Fill-up Form for New Deceased Person -->
          <div class="card border-0 shadow-sm mb-3" style="border-radius: 1rem; border: 1px solid #e2e8f0 !important; background: #ffffff;">
            <div class="card-body p-3">
              <h6 class="fw-bold mb-3 text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                <span class="text-danger"><i class="fas fa-user-plus"></i></span> New Deceased Person Information
              </h6>

              <div class="row g-3">
                <div class="col-md-4">
                  <label for="occ_first_name" class="form-label small fw-semibold mb-1">First Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="occ_first_name" name="first_name" placeholder="e.g., MARIA" required oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="col-md-4">
                  <label for="occ_middle_name" class="form-label small fw-semibold mb-1">Middle Name</label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="occ_middle_name" name="middle_name" placeholder="e.g., SANTOS" oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="col-md-4">
                  <label for="occ_last_name" class="form-label small fw-semibold mb-1">Last Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="occ_last_name" name="last_name" placeholder="e.g., DELA CRUZ" required oninput="this.value = this.value.toUpperCase()">
                </div>

                <div class="col-md-4">
                  <label for="occ_suffix" class="form-label small fw-semibold mb-1">Suffix</label>
                  <select class="form-select form-select-sm" id="occ_suffix" name="suffix">
                    <option value="">No Suffix</option>
                    <option value="JR.">JR.</option>
                    <option value="SR.">SR.</option>
                    <option value="II">II</option>
                    <option value="III">III</option>
                    <option value="IV">IV</option>
                    <option value="V">V</option>
                    <option value="OTHER">OTHER</option>
                  </select>
                  <input type="text" class="form-control form-control-sm mt-2" id="occ_suffix_other" name="suffix_other" placeholder="Enter custom suffix" style="display:none;" oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="col-md-4">
                  <label for="occ_bday" class="form-label small fw-semibold mb-1">Birthday <span class="text-danger">*</span></label>
                  <input type="date" class="form-control form-control-sm" id="occ_bday" name="bday" required>
                </div>
                <div class="col-md-4">
                  <label for="occ_born_in" class="form-label small fw-semibold mb-1">Born In (Birthplace) <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="occ_born_in" name="born_in" placeholder="e.g., ALAMINOS CITY" required oninput="this.value = this.value.toUpperCase()">
                </div>

                <div class="col-md-4">
                  <label for="occ_date_died" class="form-label small fw-semibold mb-1">Date Died <span class="text-danger">*</span></label>
                  <input type="date" class="form-control form-control-sm" id="occ_date_died" name="date_died" required>
                </div>
                <div class="col-md-4">
                  <label for="occ_contact_no" class="form-label small fw-semibold mb-1">Family Contact Number <span class="text-danger">*</span></label>
                  <input type="tel" class="form-control form-control-sm" id="occ_contact_no" name="contact_no" inputmode="numeric" maxlength="11" minlength="11" pattern="09[0-9]{9}" placeholder="09XXXXXXXXX" title="Enter 11-digit mobile number starting with 09" required>
                </div>
                <div class="col-md-4">
                  <label for="occ_relationship" class="form-label small fw-semibold mb-1">Relationship to Lot / Occupant <span class="text-muted small fw-normal">(Optional)</span></label>
                  <select class="form-select form-select-sm" id="occ_relationship" name="relationship_to_account_holder">
                    <option value="">-- Select Relationship (Optional) --</option>
                    <option value="Spouse">Spouse (Asawa)</option>
                    <option value="Child">Child (Anak)</option>
                    <option value="Parent">Parent (Magulang)</option>
                    <option value="Relative">Relative (Kamag-anak)</option>
                    <option value="Other">Other</option>
                  </select>
                </div>

                <div class="col-12">
                  <label for="occ_funeral_service" class="form-label small fw-semibold mb-1">Funeral Service <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-sm text-uppercase" id="occ_funeral_service" name="funeral_service" placeholder="Enter funeral service name" required oninput="this.value = this.value.toUpperCase()">
                </div>

                <div class="col-12" id="occAdminOverrideWrap" style="display: none;">
                  <div class="p-2.5 rounded border border-warning" style="background: #fffdf5;">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="occ_admin_override" name="admin_confirm_interval_override" value="1">
                      <label class="form-check-label small fw-bold text-dark" for="occ_admin_override">
                        Admin Authorization: Authorize secondary interment / multi-level crypt approval
                      </label>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Multi-Interment & Layering (Patong) Terms & Agreements -->
          <div class="card border-0 shadow-sm mb-3" style="border-radius: 0.85rem; overflow: hidden; border: 1px solid #fed7aa !important; background: #fffaf0;">
            <div class="p-3">
              <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fas fa-file-contract text-warning fs-5"></i>
                <h6 class="mb-0 fw-bold text-dark" style="font-size: 0.95rem;">Multi-Interment (Patong) Terms & Policy Agreement</h6>
              </div>
              <div class="small text-muted mb-2" style="font-size: 0.8rem; line-height: 1.45;">
                Adding an additional deceased person (patong / secondary interment) to an existing grave plot is strictly regulated pursuant to the Sanitation Code of the Philippines and Garden of Remembrance Memorial Park rules:
              </div>
              <ul class="small text-muted ps-3 mb-3" style="font-size: 0.78rem; line-height: 1.5;">
                <li>The plot owner or legal family representative has granted written authorization for secondary interment.</li>
                <li>Prescribed burial interval and multi-tier vault standards have been verified and satisfied.</li>
                <li>Maximum occupant capacity of the plot must not be exceeded (up to 4 occupants per lawn lot).</li>
                <li>Official death certificate and interment permit must be filed with the cemetery administration.</li>
              </ul>
              <div class="form-check d-flex align-items-start gap-2 mb-0">
                <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" id="occ_terms_agreement" name="occ_terms_agreement" required style="cursor: pointer; width: 1.15em; height: 1.15em; border-color: #7b1113;">
                <div class="small fw-semibold text-dark" style="line-height: 1.4;">
                  <label class="form-check-label mb-0" for="occ_terms_agreement" style="cursor: pointer;">
                    I hereby certify and agree to the 
                  </label>
                  <a href="javascript:void(0)" class="text-decoration-underline fw-bold" style="color: #7b1113; position: relative; z-index: 10; cursor: pointer;" onclick="event.preventDefault(); event.stopPropagation(); openCemeteryTermsModal(event, 'patong'); return false;">Multi-Interment (Patong) Terms and Cemetery Policies</a>. <span class="text-danger">*</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Hidden inputs required by backend -->
          <input type="hidden" id="occ_plot_section" name="section">
          <input type="hidden" id="occ_plot_x" name="plot_x">
          <input type="hidden" id="occ_plot_y" name="plot_y">
          <input type="hidden" name="record_type" value="with_deceased">
        </div>

        <div class="modal-footer d-flex justify-content-between px-4 py-3 flex-shrink-0" style="background:#fff; border-top: 1px solid #e2e8f0; z-index: 10;">
          <button type="button" class="btn btn-outline-secondary px-4 py-2 fw-semibold" data-bs-dismiss="modal" style="border-radius: 2rem;">
            <i class="fas fa-times me-1"></i> Cancel
          </button>
          <button type="button" id="occAddDecSubmitBtn" class="btn btn-primary px-5 py-2 fw-bold shadow" onclick="submitAddOccupiedDeceased()" style="border-radius: 2rem; background: #7b1113; border-color: #7b1113; min-width: 240px;">
            <i class="fas fa-check-circle me-2"></i> Submit Deceased Record
          </button>
        </div>
      </form>
</div>

<!-- Dedicated Modern Success Modal for Adding Deceased Record -->
<div class="modal fade" id="deceasedSuccessModal" tabindex="-1" aria-labelledby="deceasedSuccessModalLabel" aria-hidden="true" data-bs-backdrop="static" style="z-index: 1070;">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden; text-align: center;">
      <div class="modal-body p-4 pt-5">
        <div class="mb-3 mx-auto d-flex align-items-center justify-content-center" style="width: 76px; height: 76px; border-radius: 50%; background: #ecfdf5; color: #10b981; font-size: 2.5rem; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25);">
          <i class="fas fa-check-circle"></i>
        </div>
        <h4 class="fw-bold text-dark mb-2" id="deceasedSuccessModalLabel" style="font-family: 'Inter', sans-serif; font-size: 1.3rem;">
          Deceased Added Successfully!
        </h4>
        <p class="text-muted mb-4" id="deceasedSuccessModalMsg" style="font-size: 0.95rem; line-height: 1.5;">
          Deceased person added successfully to this plot!
        </p>
        <button type="button" class="btn btn-primary w-100 py-2.5 fw-bold" id="deceasedSuccessModalOkBtn" style="border-radius: 0.75rem; background: #7b1113; border-color: #7b1113; font-size: 1rem; box-shadow: 0 4px 12px rgba(123, 17, 19, 0.3);">
          OK
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Cemetery Terms and Policy Agreement Modal -->
<div class="modal fade" id="cemeteryTermsModal" tabindex="-1" aria-labelledby="cemeteryTermsModalLabel" aria-hidden="true" style="z-index: 1080;">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: min(650px, 94vw);">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
      <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff;">
        <h5 class="modal-title fw-bold m-0" id="cemeteryTermsModalLabel">
          <i class="fas fa-file-contract me-2 text-warning"></i> Cemetery Terms, Conditions & Policy Agreements
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 bg-light small" style="line-height: 1.6; color: #334155;">
        <div class="p-3 bg-white rounded border mb-3 shadow-sm">
          <h6 class="fw-bold text-dark mb-2"><i class="fas fa-layer-group text-warning me-2"></i>Multi-Interment (Patong) & Capacity Regulations</h6>
          <p class="mb-1">1. Lawn plots accommodate up to a maximum of four (4) interments depending on vault tier specifications.</p>
          <p class="mb-1">2. Each additional interment requires documented authorization from the registered plot owner or documented nearest surviving kin.</p>
          <p class="mb-0">3. Standard sanitation intervals, double/triple depth vaulting, and sanitary sealing must be certified by park operations before re-opening.</p>
        </div>

        <div class="p-3 bg-white rounded border mb-3 shadow-sm">
          <h6 class="fw-bold text-dark mb-2"><i class="fas fa-scroll text-primary me-2"></i>Documentation & Legal Burial Permits</h6>
          <p class="mb-0">An official Death Certificate, Burial/Transfer Permit issued by the local civil registrar, and Cemetery Work Order are mandatory prior to scheduling grave preparation or interment services.</p>
        </div>

        <div class="p-3 bg-white rounded border mb-3 shadow-sm" style="border-left: 4px solid #dc3545 !important;">
          <h6 class="fw-bold text-danger mb-2"><i class="fas fa-calendar-times text-danger me-2"></i>Three (3) Months Non-Payment Cancellation Policy</h6>
          <p class="mb-0"><strong>Automatic Cancellation:</strong> If a client fails to pay or settles no payment for three (3) consecutive months (90 days of overdue amortizations or non-payment), the reservation will be automatically cancelled. The reserved plot will be immediately forfeited, released, and made available to other clients. All previous downpayments and fees are strictly non-refundable.</p>
        </div>

        <div class="p-3 bg-white rounded border mb-3 shadow-sm">
          <h6 class="fw-bold text-dark mb-2"><i class="fas fa-shield-alt text-success me-2"></i>Perpetual Care & Maintenance</h6>
          <p class="mb-0">General lawn mowing, grounds upkeep, drainage, and landscape maintenance are performed exclusively by park management. Owners may not plant private trees or build unauthorized elevated curbs.</p>
        </div>

        <div class="p-3 bg-white rounded border shadow-sm">
          <h6 class="fw-bold text-dark mb-2"><i class="fas fa-user-shield text-info me-2"></i>Privacy & Cemetery Records Integrity</h6>
          <p class="mb-0">All registry records are kept in strict compliance with the Data Privacy Act of 2012 (R.A. 10173). Falsification of kinship or unauthorized grave claims is strictly penalized under park regulations and Philippine law.</p>
        </div>
      </div>
      <div class="modal-footer bg-white py-2 px-4 d-flex justify-content-between">
        <span class="text-muted small"><i class="fas fa-monument text-secondary me-1"></i> Garden of Remembrance Memorial Park</span>
        <button type="button" class="btn btn-secondary px-4 fw-semibold" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
window.popupPath = window.popupPath || ((window.location.pathname.indexOf('popup.php') !== -1) 
  ? window.location.pathname 
  : ((window.location.pathname.indexOf('/sections/') !== -1) ? 'popup.php' : 'sections/popup.php'));
var popupPath = window.popupPath;
window.phpEscape = window.phpEscape || function(s){ return String(s == null ? '' : s); };
var phpEscape = window.phpEscape;
window.recordRecentlyClicked = window.recordRecentlyClicked || function() {};

// Highlight and pulse targeted plot when opened from map search or direct navigation
document.addEventListener('DOMContentLoaded', function() {
  function getUrlParam(name) {
    try {
      const url = new URL(window.location.href);
      return url.searchParams.get(name);
    } catch(e) {
      return null;
    }
  }
  const plotId = getUrlParam('plot_id');
  var plotX = getUrlParam('plot_x');
  var plotY = getUrlParam('plot_y');
  var plotParam = getUrlParam('plot');
  if ((!plotX || !plotY) && plotParam) {
    var rawP = String(plotParam).trim();
    var d6Match = rawP.match(/^(\d{3})(\d{3})$/);
    if (d6Match) {
      plotX = String(parseInt(d6Match[1], 10));
      plotY = String(parseInt(d6Match[2], 10));
    } else {
      var cleaned = rawP.replace(/^[A-Za-z]+[-_\s]*/, '');
      var parts = cleaned.split(/[-_,\s]+/);
      if (parts.length >= 2) {
        plotX = parts[0].trim();
        plotY = parts[1].trim();
      }
    }
  }
  const deceasedName = getUrlParam('deceased_name');
  const deceasedId = getUrlParam('deceased_id');
  const autoOpen = getUrlParam('auto_open');
  const isPayReturn = (getUrlParam('guest_pay_return') === '1');
  const isSearchHighlight = !isPayReturn && (getUrlParam('search_highlight') === '1' || Boolean(plotX && plotY) || Boolean(plotId && plotId !== '0'));
  if (getUrlParam('from') === 'reservation_logs') {
    try { sessionStorage.setItem('from_reservation_logs', '1'); } catch(e) {}
  }

  if (isSearchHighlight && ((plotX && plotY) || (plotId && plotId !== '0'))) {
    // 1. Clear any previous search highlights on the map
    document.querySelectorAll('.deceased-search-highlight, .deceased-search-plot-highlight, .plot-search-pulse, .search-highlight, .search-blink, .plot-blinking-highlight, .reserved-search-highlight, .plot-blinking-reserved').forEach(function(el) {
      el.classList.remove('deceased-search-highlight', 'deceased-search-plot-highlight', 'plot-search-pulse', 'search-highlight', 'search-blink', 'plot-blinking-highlight', 'reserved-search-highlight', 'plot-blinking-reserved');
      var oldTag = el.querySelector('.searched-grave-tag');
      if (oldTag) oldTag.remove();
    });

    // 2. Locate the exact plot element by plot-id or coordinates
    function applyTargetHighlight() {
      var targetBtn = null;
      if (plotId && plotId !== '0') {
        targetBtn = document.querySelector('.plot-btn[data-plot-id="' + plotId + '"]');
      }
      if (!targetBtn && plotX && plotY) {
        var intPx = parseInt(plotX, 10);
        var intPy = parseInt(plotY, 10);
        targetBtn = document.querySelector('.plot-btn[data-plot-x="' + plotX + '"][data-plot-y="' + plotY + '"]') ||
                    document.querySelector('.plot-btn[data-plot-x="' + intPx + '"][data-plot-y="' + intPy + '"]') ||
                    document.querySelector('.plot-btn[data-plot-x="' + intPy + '"][data-plot-y="' + intPx + '"]');
      }

      if (targetBtn) {
        var isTargetReserved = targetBtn.getAttribute('data-reserved') === '1' ||
                               targetBtn.getAttribute('data-purchased') === '1' ||
                               (targetBtn.style.background && targetBtn.style.background.indexOf('218, 57, 65') === -1 && targetBtn.style.background.indexOf('0, 0, 0') !== -1) ||
                               sessionStorage.getItem('from_reservation_logs') === '1' ||
                               (getUrlParam && getUrlParam('from') === 'reservation_logs');

        if (isTargetReserved) {
          targetBtn.classList.remove('deceased-search-highlight', 'deceased-search-plot-highlight');
          targetBtn.classList.add('plot-blinking-reserved', 'reserved-search-highlight', 'plot-search-pulse', 'search-highlight', 'search-blink', 'plot-blinking-highlight');
          targetBtn.style.setProperty('animation', 'reservedPlotBlinkAlert 0.75s ease-in-out infinite', 'important');
          targetBtn.style.setProperty('border', '3px solid #f59e0b', 'important');
          targetBtn.style.setProperty('outline', '2.5px solid #fbbf24', 'important');
          targetBtn.style.setProperty('outline-offset', '1px', 'important');
          targetBtn.style.setProperty('box-shadow', '0 0 0 4px rgba(245, 158, 11, 0.7), 0 0 20px 6px rgba(245, 158, 11, 0.95)', 'important');
          targetBtn.style.setProperty('background', 'rgba(40, 40, 40, 0.85)', 'important');
          targetBtn.style.setProperty('background-color', 'rgba(40, 40, 40, 0.85)', 'important');
          targetBtn.style.setProperty('color', '#ffffff', 'important');
          targetBtn.style.setProperty('z-index', '9999', 'important');
          targetBtn.style.setProperty('transform', 'none', 'important');
          targetBtn.setAttribute('data-reserved', '1');
          targetBtn.title = 'This Plot is Reserved';
        } else {
          // Apply RED looping/pulsing/blinking highlight to the matching plot element only
          targetBtn.classList.remove('plot-blinking-reserved', 'reserved-search-highlight');
          targetBtn.classList.add('deceased-search-highlight', 'deceased-search-plot-highlight', 'plot-search-pulse', 'search-highlight', 'search-blink', 'plot-blinking-highlight');
          targetBtn.style.setProperty('animation', 'plotBlinkAlert 0.75s ease-in-out infinite', 'important');
          targetBtn.style.setProperty('border', '3px solid #dc2626', 'important');
          targetBtn.style.setProperty('outline', '2.5px solid #ef4444', 'important');
          targetBtn.style.setProperty('outline-offset', '1px', 'important');
          targetBtn.style.setProperty('box-shadow', '0 0 0 4px rgba(220, 38, 38, 0.7), 0 0 20px 6px rgba(220, 38, 38, 0.95)', 'important');
          targetBtn.style.setProperty('background', 'rgba(220, 38, 38, 0.9)', 'important');
          targetBtn.style.setProperty('background-color', 'rgba(220, 38, 38, 0.9)', 'important');
          targetBtn.style.setProperty('color', '#ffffff', 'important');
          targetBtn.style.setProperty('z-index', '9999', 'important');
          targetBtn.style.setProperty('transform', 'none', 'important');
        }
        return true;
      }
      return false;
    }

    applyTargetHighlight();
    setTimeout(applyTargetHighlight, 100);
    setTimeout(applyTargetHighlight, 300);
    setTimeout(applyTargetHighlight, 600);
    setTimeout(applyTargetHighlight, 1000);

    // Auto-open the deceased info modal after the plot highlight has settled.
    // This fires when the user clicks "VIEW ON MAP" from the search results.
    var _autoOpenSection = getUrlParam('section') || '<?= $section ?>';
    var _autoOpenDecId   = getUrlParam('deceased_id') || deceasedId;
    var _autoOpenDecName = getUrlParam('deceased_name') || deceasedName;
    setTimeout(function() {
      if (typeof showDeceasedInfo === 'function' && plotX && plotY) {
        showDeceasedInfo(_autoOpenSection, plotX, plotY, _autoOpenDecId, _autoOpenDecName);
      }
    }, 750);
  }

  // Global highlight plot helper for section
  window.highlightAndShowPlot = function(targetSection, targetX, targetY, targetPlotId, targetDeceasedName) {
    if (!targetX || !targetY) return;
    targetSection = (targetSection || '<?= $section ?>').toUpperCase();
    if (targetSection !== '<?= strtoupper($section) ?>') {
      var redirectUrl = 'popup.php?section=' + encodeURIComponent(targetSection) + '&plot_x=' + targetX + '&plot_y=' + targetY + '&search_highlight=1';
      if (targetPlotId) redirectUrl += '&plot_id=' + encodeURIComponent(targetPlotId);
      if (targetDeceasedName) redirectUrl += '&deceased_name=' + encodeURIComponent(targetDeceasedName);
      window.location.href = redirectUrl;
      return;
    }

    // Remove previous highlights
    document.querySelectorAll('.deceased-search-highlight, .plot-search-pulse, .search-highlight, .search-blink').forEach(function(el) {
      el.classList.remove('deceased-search-highlight', 'plot-search-pulse', 'search-highlight', 'search-blink');
      var oldTag = el.querySelector('.searched-grave-tag');
      if (oldTag) oldTag.remove();
    });

    var targetBtn = null;
    if (targetPlotId && targetPlotId !== '0') {
      targetBtn = document.querySelector('.plot-btn[data-plot-id="' + targetPlotId + '"]');
    }
    if (!targetBtn && targetX && targetY) {
      targetBtn = document.querySelector('.plot-btn[data-plot-x="' + targetX + '"][data-plot-y="' + targetY + '"]');
    }

    if (targetBtn) {
      // Apply RED looping/pulsing highlight only. Map remains completely stationary.
      targetBtn.classList.add('deceased-search-highlight', 'plot-search-pulse', 'search-highlight');
    } else {
      alert('Plot ' + targetSection + ' ' + targetX + '-' + targetY + ' was not found in this section grid.');
    }
  };

  // Global move mode state
  window.__moveMode = { active: false, deceasedId: null };

  // Auto-activate move mode if coming from map.php with params
  try {
    const url = new URL(window.location.href);
    const move = url.searchParams.get('move');
    const did = url.searchParams.get('deceased_id');
    if (move === '1' && did) {
      window.__moveMode = { active: true, deceasedId: did };
    }
  } catch (e) {}

  // Cancel move mode from popup banner: clear move state and reload without move params
  function cancelMoveMode() {
    try { window.__moveMode = { active: false, deceasedId: null }; } catch (e) {}
    // Hide banner immediately for feedback
    try {
      var banner = document.getElementById('moveBanner');
      if (banner) banner.style.display = 'none';
    } catch (e) {}
    // Navigate back to the map to fully cancel selection
    try {
      window.location.href = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php';
      return;
    } catch (e) {}
    // Fallbacks: remove params or reload path only
    try {
      const url = new URL(window.location.href);
      url.searchParams.delete('move');
      url.searchParams.delete('deceased_id');
      const qs = url.searchParams.toString();
      const target = qs ? (url.pathname + '?' + qs) : url.pathname;
      window.location.href = target;
    } catch (e) {
      window.location.href = window.location.pathname;
    }
  }

  // Handle plot button clicks: either move target, show info, or book service
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('plot-btn') || e.target.closest('.plot-btn')) {
      // In booking mode, plot clicks are handled exclusively by the
      // booking-selection handler. Do NOT run the normal map/deceased-information
      // path here, otherwise booking would also open the Deceased Information modal.
      const button = e.target.classList.contains('plot-btn') ? e.target : e.target.closest('.plot-btn');
      const plotX = button.getAttribute('data-plot-x');
      const plotY = button.getAttribute('data-plot-y');
      const section = '<?= $section ?>';
      if (window.__bookingMode) {
        e.preventDefault();
        e.stopPropagation();
        if (plotX && plotY) loadPlotBookingStatus(section, plotX, plotY);
        return;
      }
      try { console.log('Clicked plot button', { section: section, plotX: plotX, plotY: plotY, el: button }); } catch(e) {}
      try { console.log('Plot ID:', section + '-' + plotX + '-' + plotY, 'data-reserved=', button.getAttribute('data-reserved'), 'style-bg=', button.style.background); } catch(e) {}

      // Check if this is a service booking mode
      const urlParams = new URLSearchParams(window.location.search);
      const serviceType = urlParams.get('service');

      if (serviceType) {
          // Simple service booking - no complex logic
          e.preventDefault();
          e.stopPropagation();

          if (!confirm('Book ' + serviceType + ' service for Section ' + section.toUpperCase() + ', Plot ' + plotX + ', ' + plotY + '?')) {
            return;
          }

          // Create simple form submission
          var form = document.createElement('form');
          form.method = 'POST';
          form.action = 'popup.php';
          form.style.display = 'none';

          var actionInput = document.createElement('input');
          actionInput.type = 'hidden';
          actionInput.name = 'action';
          actionInput.value = 'book_service';
          form.appendChild(actionInput);

          var sectionInput = document.createElement('input');
          sectionInput.type = 'hidden';
          sectionInput.name = 'section';
          sectionInput.value = section;
          form.appendChild(sectionInput);

          var plotXInput = document.createElement('input');
          plotXInput.type = 'hidden';
          plotXInput.name = 'plot_x';
          plotXInput.value = plotX;
          form.appendChild(plotXInput);

          var plotYInput = document.createElement('input');
          plotYInput.type = 'hidden';
          plotYInput.name = 'plot_y';
          plotYInput.value = plotY;
          form.appendChild(plotYInput);

          var serviceInput = document.createElement('input');
          serviceInput.type = 'hidden';
          serviceInput.name = 'service_type';
          serviceInput.value = serviceType;
          form.appendChild(serviceInput);

          document.body.appendChild(form);
          form.submit();
          return;
        }

      if (window.__moveMode && window.__moveMode.active && window.__moveMode.deceasedId) {
        // Intercept for move action
        e.preventDefault();
        e.stopPropagation();
        const deceasedId = window.__moveMode.deceasedId;
        const confirmMsg = `Are you sure you want to move this deceased person to Section ${section.toUpperCase()}, Plot ${plotX}, ${plotY}?`;
        // Use SweetAlert2 when available
        const doMove = function() {
          const body = new URLSearchParams();
          body.set('ajax_request','move_deceased');
          body.set('deceased_id', deceasedId);
          body.set('new_section', section);
          body.set('new_plot_x', plotX);
          body.set('new_plot_y', plotY);
          fetch(popupPath, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then(r => r.json())
            .then(data => {
              if (data && data.success) {
                window.__moveMode.active = false; window.__moveMode.deceasedId = null;
                try { Swal.fire({ icon: 'success', title: 'Moved', text: data.message || 'Deceased moved successfully' }).then(() => { window.location.href = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php'; }); } catch (e) { alert('Deceased moved successfully'); window.location.href = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php'; }
              } else {
                try { Swal.fire({ icon: 'error', title: 'Error', text: (data && data.message) ? data.message : 'Failed to move deceased' }); } catch (e) { alert('Error: ' + (data && data.message)); }
                window.__moveMode.active = false; window.__moveMode.deceasedId = null;
              }
            })
            .catch(err => { console.error(err); try { Swal.fire({ icon: 'error', title: 'Error', text: 'Error moving deceased.' }); } catch (e) { alert('Error moving deceased.'); } window.__moveMode.active = false; window.__moveMode.deceasedId = null; });
        };
        try {
          if (typeof Swal !== 'undefined') {
            Swal.fire({ title: 'Confirm Move', text: confirmMsg, icon: 'warning', showCancelButton: true, confirmButtonText: 'Move', confirmButtonColor: '#d33' }).then((res) => {
              if (res.isConfirmed) doMove();
            });
          } else {
            if (window.confirm(confirmMsg)) doMove();
          }
        } catch (e) {
          if (window.confirm(confirmMsg)) doMove();
        }
} else {
        // Prevent the original modal from opening
        e.preventDefault();
        e.stopPropagation();
        // Delegate to the shared handler which fetches plot status and opens the modal.
        showDeceasedInfo(section, plotX, plotY);
      }
    }
  });
});

// Fetch and display the appropriate action for a plot click.
// Occupied -> show deceased info; Reserved -> show reservation/manage; Available -> show empty/reserve.
function showDeceasedInfo(section, plotX, plotY, targetDeceasedId, targetDeceasedName) {
  if (!section || !plotX || !plotY) {
    alert('Unable to identify this plot.');
    return;
  }
  if (typeof window.recordRecentlyClicked === 'function') {
    window.recordRecentlyClicked(section, plotX, plotY, null);
  }
  var contentEl = document.getElementById('deceasedInfoContent');
  if (contentEl) {
    contentEl.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-3 text-muted">Loading deceased information...</p></div>';
  }
  // Show the Deceased Information modal so the loading state and results are visible.
  try {
    var infoModalEl = document.getElementById('deceasedInfoModal');
    if (infoModalEl) {
      var infoModal = bootstrap.Modal.getInstance(infoModalEl) || new bootstrap.Modal(infoModalEl);
      infoModal.show();
    }
  } catch (e) {}
  var body = new URLSearchParams();
  body.set('ajax_request', 'get_plot_booking_status');
  body.set('section', section);
  body.set('plot_x', plotX);
  body.set('plot_y', plotY);
  var isLoading = true;
  var endpoint = window.popupPath || ((window.location.pathname.indexOf('popup.php') !== -1) 
    ? window.location.pathname 
    : ((window.location.pathname.indexOf('/sections/') !== -1) ? 'popup.php' : 'sections/popup.php'));
  fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin',
    body: body.toString()
  })
    .then(function(r){
      return r.text().then(function(text){
        var data = null;
        try { data = JSON.parse(text); } catch(e) {}
        if (!r.ok) {
          if (data && data.message) {
            throw new Error(data.message);
          }
          if (r.status === 401) throw new Error('Please log in to access plot information.');
          if (r.status === 403) throw new Error('You do not have permission to view this plot.');
          if (r.status === 404) throw new Error('Plot location does not exist.');
          throw new Error('Server error (' + r.status + '). Please try again.');
        }
        return data;
      });
    })
    .then(function(data){
      if (!data || !data.success) {
        function escHtmlText(t) { return String(t || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
        if (data && data.owner_name) {
          if (contentEl) {
            var rawPh = data.phone_no || (data.reservation && data.reservation.phone_no) || '';
            var phoneDisp = '';
            if (rawPh) {
              var pS = String(rawPh).trim();
              phoneDisp = (pS.length > 4) ? (pS.substring(0, 2) + '*'.repeat(Math.max(4, pS.length - 4)) + pS.substring(pS.length - 2)) : '••••';
            }
            contentEl.innerHTML = '<div class="alert alert-secondary text-start py-3 px-3 mb-0 mx-auto shadow-sm" style="max-width:440px; border-radius:12px; border-left:4px solid #7b1113 !important;">' +
              '<div class="d-flex justify-content-between align-items-center mb-2"><strong class="fs-6 text-dark"><i class="fas fa-bookmark me-2 text-warning"></i>Reserved Plot (' + escHtmlText(section) + ' ' + escHtmlText(plotX) + '-' + escHtmlText(plotY) + ')</strong><span class="badge bg-warning text-dark">Reserved</span></div>' +
              '<div class="d-flex justify-content-between py-1 border-top"><span class="text-muted">Reserved By:</span><strong class="text-dark">' + escHtmlText(data.owner_name) + '</strong></div>' +
              (phoneDisp ? '<div class="d-flex justify-content-between py-1 border-top"><span class="text-muted">Contact Number:</span><span class="fw-semibold text-secondary">' + escHtmlText(phoneDisp) + '</span></div>' : '') +
              '<p class="text-muted small mt-2 mb-0">No deceased record is currently assigned to this plot.</p>' +
            '</div>';
          }
        } else {
          var msg = (data && data.message) ? data.message : 'No deceased record found for this plot.';
          if (contentEl) contentEl.innerHTML = '<div class="alert alert-info text-center py-4 mb-0"><i class="fas fa-info-circle me-2 text-info fs-3 d-block mb-2"></i><strong>Plot ' + escHtmlText(section) + ' ' + escHtmlText(plotX) + '-' + escHtmlText(plotY) + '</strong><p class="mt-2 mb-0 text-muted">' + escHtmlText(msg) + '</p></div>';
        }
        isLoading = false;
        return;
      }
      var status = String(data.status || 'Available').trim().toUpperCase();
      var dec = data.deceased || [];
      var payment = data.payment_details || {};
      window.__currentPayment = payment;
      window.__currentPlotData = data;
      window.__currentReservation = data.reservation || {};
      if ((status === 'OCCUPIED' || dec.length > 0) && dec.length > 0) {
        var targetIndex = 0;
        if (targetDeceasedId) {
          var foundIdx = dec.findIndex(function(d) {
            return String(d.deceased_id) === String(targetDeceasedId);
          });
          if (foundIdx !== -1) targetIndex = foundIdx;
        } else if (targetDeceasedName) {
          var cleanTarget = targetDeceasedName.toLowerCase().trim();
          var foundIdx = dec.findIndex(function(d) {
            var nm = (d.full_name || ((d.first_name || '') + ' ' + (d.last_name || ''))).toLowerCase().trim();
            return nm === cleanTarget || nm.includes(cleanTarget) || cleanTarget.includes(nm);
          });
          if (foundIdx !== -1) targetIndex = foundIdx;
        }
        var targetOccupant = dec[targetIndex] || dec[0];
        var occName = (targetOccupant.full_name || ((targetOccupant.first_name || '') + ' ' + (targetOccupant.last_name || ''))).trim();
        recordRecentlyClicked(section, plotX, plotY, occName);
        window.__currentDeceased = { list: dec, section: section, plotX: plotX, plotY: plotY, payment: payment };
        isLoading = false;
        displayDeceasedInfo(targetOccupant, section, plotX, plotY, dec.length, dec, payment);
      } else if ((status === 'RESERVED' || status === 'OWNED' || status === 'CLAIMED' || status === 'PURCHASED' || status.indexOf('PURCHASED') !== -1 || status === 'SOLD' || Boolean(data.reservation && data.reservation.owner_name)) && (Boolean(data.reservation && data.reservation.owner_name && String(data.reservation.owner_name).trim().toLowerCase() !== 'reserved' && String(data.reservation.owner_name).trim().toLowerCase() !== 'unknown') || Boolean(data.payment_details && data.payment_details.booker_name))) {
        recordRecentlyClicked(section, plotX, plotY, (status === 'OWNED' ? 'Owned Plot' : (status === 'PURCHASED' ? 'Purchased Plot' : 'Reserved Plot')));
        isLoading = false;
        var res = data.reservation || {};
        var p = data.payment_details || {};
        var owner = res.owner_name || p.booker_name || ((status === 'OWNED') ? 'Registered Plot' : 'Reserved');
        var tBtn = document.querySelector('.plot-btn[data-plot-x="' + plotX + '"][data-plot-y="' + plotY + '"]');
        if (tBtn) {
          tBtn.setAttribute('data-reserved', '1');
          tBtn.title = 'This Plot is Reserved';
          if (tBtn.classList.contains('plot-blinking-highlight') || tBtn.classList.contains('deceased-search-highlight')) {
            tBtn.classList.remove('deceased-search-highlight', 'deceased-search-plot-highlight');
            tBtn.classList.add('plot-blinking-reserved', 'reserved-search-highlight');
            tBtn.style.setProperty('animation', 'reservedPlotBlinkAlert 0.75s ease-in-out infinite', 'important');
            tBtn.style.setProperty('border', '3px solid #f59e0b', 'important');
            tBtn.style.setProperty('outline', '2.5px solid #fbbf24', 'important');
            tBtn.style.setProperty('box-shadow', '0 0 0 4px rgba(245, 158, 11, 0.7), 0 0 20px 6px rgba(245, 158, 11, 0.95)', 'important');
            tBtn.style.setProperty('background', 'rgba(40, 40, 40, 0.85)', 'important');
            tBtn.style.setProperty('background-color', 'rgba(40, 40, 40, 0.85)', 'important');
            tBtn.style.setProperty('color', '#ffffff', 'important');
          }
        }
        displayEmptyPlot(section, plotX, plotY, owner, res.phone_no || p.phone_no || null, data);
      } else {
        recordRecentlyClicked(section, plotX, plotY, 'Available Plot');
        isLoading = false;
        var tBtnAvail = document.querySelector('.plot-btn[data-plot-x="' + plotX + '"][data-plot-y="' + plotY + '"]') ||
                        document.querySelector('.plot-btn[data-plot-x="' + plotY + '"][data-plot-y="' + plotX + '"]');
        if (tBtnAvail) {
          tBtnAvail.removeAttribute('data-reserved');
          tBtnAvail.removeAttribute('data-purchased');
          tBtnAvail.classList.remove('plot-blinking-reserved');
          tBtnAvail.style.background = 'rgba(33, 150, 243, 0.35)';
          tBtnAvail.style.color = '#ffffff';
          tBtnAvail.style.fontWeight = 'normal';
          tBtnAvail.title = 'Plot ' + plotX + ', ' + plotY + ' (Available)';
        }
        displayEmptyPlot(section, plotX, plotY, null, null, data);
      }
    })
    .catch(function(err){
      console.error('showDeceasedInfo error:', err);
      var msg = (err && err.message) ? err.message : 'Error loading plot information.';
      if (contentEl) contentEl.innerHTML = '<div class="alert alert-danger text-center py-4">' + phpEscape(msg) + '</div>';
      isLoading = false;
    })
    .finally(function(){
      if (isLoading && contentEl) {
        contentEl.innerHTML = '<div class="alert alert-danger text-center py-4">Unable to load deceased information. Please try again later.</div>';
      }
    });
}

function displayDeceasedInfo(deceased, section, plotX, plotY, count, fullList, payment) {
  var titleEl = document.getElementById('deceasedInfoModalLabel');
  if (titleEl) {
    titleEl.innerHTML = '<i class="fas fa-user-circle me-2"></i>Deceased Information';
  }
  // Determine selected index based on deceased_id to style the active occupant
  var selectedIndex = 0;
  try {
    if (deceased && fullList && fullList.length) {
      var did = (deceased.deceased_id !== undefined && deceased.deceased_id !== null) ? String(deceased.deceased_id) : null;
      if (did !== null) {
        var idx = fullList.findIndex(function(d){ return String(d.deceased_id) === did; });
        selectedIndex = idx >= 0 ? idx : 0;
      }
    }
  } catch (e) { selectedIndex = 0; }
  const fullName = deceased.full_name || `${deceased.first_name || ''} ${deceased.middle_name || ''} ${deceased.last_name || ''}`.replace(/\s+/g, ' ').trim();
  const birthDate = deceased.bday ? new Date(deceased.bday).toLocaleDateString('en-PH', {
    timeZone: 'Asia/Manila', year: 'numeric', month: 'long', day: 'numeric'
  }) : '—';
  const deathDate = deceased.date_died ? new Date(deceased.date_died).toLocaleDateString('en-PH', {
    timeZone: 'Asia/Manila', year: 'numeric', month: 'long', day: 'numeric'
  }) : '—';
  const diedISO = deceased.date_died || null;
  const todayStr = new Date().toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
  const canAdd = (count || 0) < 7;
  const occupants = (fullList || []).map(function(d, idx){
    const nm = d.full_name || `${d.first_name || ''} ${d.middle_name || ''} ${d.last_name || ''}`.replace(/\s+/g,' ').trim();
    const dd = d.date_died ? new Date(d.date_died).toLocaleDateString('en-PH', { timeZone:'Asia/Manila', year:'numeric', month:'short', day:'numeric'}) : '—';
    return `<div class="d-flex justify-content-between align-items-center py-1 border-bottom small occupant-item ${idx === selectedIndex ? 'bg-light' : ''}" data-deceased-id="${d.deceased_id || ''}" style="cursor: pointer; font-size: 0.8rem; min-width: 0; ${idx === selectedIndex ? 'border-left: 3px solid #7b1113; padding-left: 6px;' : ''}" onclick="if(window.__currentDeceased){displayDeceasedInfo(window.__currentDeceased.list[${idx}], '${section}', '${plotX}', '${plotY}', ${count}, window.__currentDeceased.list, window.__currentDeceased.payment);}">
              <div class="text-truncate me-2" style="min-width: 0; flex: 1 1 0%;" title="${nm}"><strong>${idx+1}. ${nm}</strong></div>
              <span class="text-muted ms-auto flex-shrink-0" style="font-size: 0.72rem;">${dd}</span>
            </div>`;
  }).join('');

  // Booker & Payment Details Resolution
  const isAuthorizedViewer = Boolean(
    (window.__currentPlotData && (window.__currentPlotData.is_admin || window.__currentPlotData.is_user_authorized)) ||
    (payment && (payment.is_user_authorized || payment.can_view_payment)) ||
    <?php echo ($isAdmin ? 'true' : 'false'); ?>
  );
  const p = payment || (window.__currentPayment || {});
  let bookerName = (deceased.booker_name && deceased.booker_name.trim() !== '' && deceased.booker_name.trim() !== 'Not Specified') ? deceased.booker_name.trim() : ((p.booker_name && p.booker_name !== 'Not Specified') ? p.booker_name : '');
  if (bookerName === fullName && p.booker_name && p.booker_name !== fullName && p.booker_name !== 'Not Specified') {
    bookerName = p.booker_name;
  }
  const paymentPlan = deceased.payment_plan || p.payment_plan || 'Cash';
  const paymentTerm = parseInt(deceased.payment_term || p.payment_term || 0, 10);
  let totalAmount = parseFloat(deceased.total_amount);
  if (isNaN(totalAmount) || totalAmount <= 0) {
    totalAmount = parseFloat(p.total_amount);
  }
  if (isNaN(totalAmount) || totalAmount <= 0) {
    totalAmount = 85000;
  }
  let isPaid = (deceased.payment_status === 'PAID') || (p.payment_status === 'PAID') || (paymentPlan === 'Cash');
  let amountPaid = parseFloat((deceased.amount_paid !== undefined && deceased.amount_paid !== null) ? deceased.amount_paid : p.amount_paid);
  if (isNaN(amountPaid) || (isPaid && amountPaid <= 0)) {
    amountPaid = totalAmount;
  }
  let balance = Math.max(0, totalAmount - amountPaid);
  if (isPaid) {
    balance = 0;
  }
  const monthlyDue = parseFloat(deceased.monthly_due || p.monthly_due || (paymentTerm > 0 ? (totalAmount / paymentTerm) : 0));
  const hasMultipleOccupants = (count > 1);
  const mainCardColClass = hasMultipleOccupants ? 'col-12 col-md-8 col-lg-8 deceased-info-main-card has-sidebar' : 'col-12 deceased-info-main-card';
  const sidebarHtml = hasMultipleOccupants ? `
      <div class="col-12 col-md-4 col-lg-4 mt-2 mt-md-0 deceased-info-sidebar-card">
        <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: #ffffff;">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-2 pb-1.5 border-bottom">
              <span class="mb-0 fw-bold text-dark" style="font-size: 0.88rem;">All Occupants</span>
              <span class="badge bg-light text-dark border px-2 py-0.5 rounded-pill" style="font-size: 0.76rem;">${count}</span>
            </div>
            <div style="max-height: 360px; overflow-y: auto;">
              ${occupants || '<div class="text-muted small py-2">No occupants listed.</div>'}
            </div>
          </div>
        </div>
      </div>` : '';

  document.getElementById('deceasedInfoContent').innerHTML = `
    <div class="row g-3 deceased-info-row">
      <div class="${mainCardColClass}">
        <div class="card border-0 shadow-sm" style="border-radius: 12px;">
          <div class="card-body p-3">
            <div class="text-center mb-2.5">
              <div class="bg-primary bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-1.5" style="width: 48px; height: 48px;">
                <i class="fas fa-user text-primary" style="font-size: 1.3rem;"></i>
              </div>
              <h4 class="fw-bold text-dark mb-0" style="font-size: 1.25rem; line-height: 1.25;">${fullName}</h4>
              <div class="text-muted mt-1" style="font-size: 0.84rem;">
                Section ${(deceased.section || section).toUpperCase()} &bull; Row ${deceased.plot_x || plotX}, Plot ${deceased.plot_y || plotY} &bull; Occupants: ${count}/4
              </div>
            </div>

            <div class="row g-2.5">
              <div class="col-12 col-sm-6">
                <div class="d-flex align-items-center mb-2 deceased-info-item" style="min-width: 0;">
                  <div class="bg-success bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-2 flex-shrink-0" style="width: 32px; height: 32px;">
                    <i class="fas fa-birthday-cake text-success" style="font-size: 0.82rem;"></i>
                  </div>
                  <div class="info-text-wrap" style="line-height: 1.25; min-width: 0; flex: 1;">
                    <div class="text-muted" style="font-size: 0.76rem;">Date of Birth</div>
                    <div class="fw-bold text-dark info-val" style="font-size: 0.88rem; word-break: break-word; overflow-wrap: anywhere; line-height: 1.25;">${birthDate}</div>
                  </div>
                </div>

                <div class="d-flex align-items-center mb-2 deceased-info-item" style="min-width: 0;">
                  <div class="bg-danger bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-2 flex-shrink-0" style="width: 32px; height: 32px;">
                    <i class="fas fa-cross text-danger" style="font-size: 0.82rem;"></i>
                  </div>
                  <div class="info-text-wrap" style="line-height: 1.25; min-width: 0; flex: 1;">
                    <div class="text-muted" style="font-size: 0.76rem;">Date of Death</div>
                    <div class="fw-bold text-dark info-val" style="font-size: 0.88rem; word-break: break-word; overflow-wrap: anywhere; line-height: 1.25;">${deathDate}</div>
                  </div>
                </div>

                <div class="d-flex align-items-center mb-2 deceased-info-item" style="min-width: 0;">
                  <div class="bg-dark bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-2 flex-shrink-0" style="width: 32px; height: 32px;">
                    <i class="fas fa-hourglass-half text-dark" style="font-size: 0.82rem;"></i>
                  </div>
                  <div class="info-text-wrap" style="line-height: 1.25; min-width: 0; flex: 1;">
                    <div class="text-muted" style="font-size: 0.76rem;">Buried For</div>
                    <div class="fw-bold text-dark info-val" style="font-size: 0.88rem; word-break: break-word; overflow-wrap: anywhere; line-height: 1.25;"><span id="burial_duration"></span></div>
                  </div>
                </div>

                <div class="d-flex align-items-center mb-1 deceased-info-item" style="min-width: 0;">
                  <div class="bg-info bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-2 flex-shrink-0" style="width: 32px; height: 32px;">
                    <i class="fas fa-map-marker-alt text-info" style="font-size: 0.82rem;"></i>
                  </div>
                  <div class="info-text-wrap" style="line-height: 1.25; min-width: 0; flex: 1;">
                    <div class="text-muted" style="font-size: 0.76rem;">Born In</div>
                    <div class="fw-bold text-dark info-val" style="font-size: 0.88rem; word-break: break-word; overflow-wrap: anywhere; line-height: 1.25;" title="${deceased.born_in || '—'}">${deceased.born_in || '—'}</div>
                  </div>
                </div>
              </div>

              <div class="col-12 col-sm-6">
                <div class="d-flex align-items-center mb-2 deceased-info-item" style="min-width: 0;">
                  <div class="bg-warning bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-2 flex-shrink-0" style="width: 32px; height: 32px;">
                    <i class="fas fa-phone text-warning" style="font-size: 0.82rem;"></i>
                  </div>
                  <div class="info-text-wrap" style="line-height: 1.25; min-width: 0; flex: 1;">
                    <div class="text-muted" style="font-size: 0.76rem;">Contact Number</div>
                    <div class="fw-bold text-dark info-val" style="font-size: 0.88rem; word-break: break-word; overflow-wrap: anywhere; line-height: 1.25;">${isAuthorizedViewer ? (deceased.contact_no || '—') : (deceased.contact_no && deceased.contact_no !== '—' ? (deceased.contact_no.length > 4 ? (deceased.contact_no.substring(0, 2) + '*'.repeat(Math.max(4, deceased.contact_no.length - 4)) + deceased.contact_no.substring(deceased.contact_no.length - 2)) : '••••') : '—')}</div>
                  </div>
                </div>

                <div class="d-flex align-items-center mb-2 deceased-info-item" style="min-width: 0;">
                  <div class="bg-secondary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-2 flex-shrink-0" style="width: 32px; height: 32px;">
                    <i class="fas fa-church text-secondary" style="font-size: 0.82rem;"></i>
                  </div>
                  <div class="info-text-wrap" style="line-height: 1.25; min-width: 0; flex: 1;">
                    <div class="text-muted" style="font-size: 0.76rem;">Funeral Service</div>
                    <div class="fw-bold text-dark info-val" style="font-size: 0.88rem; word-break: break-word; overflow-wrap: anywhere; line-height: 1.25;" title="${deceased.funeral_service || '—'}">${deceased.funeral_service || '—'}</div>
                  </div>
                </div>

                <div class="d-flex align-items-center mb-2 deceased-info-item" style="min-width: 0;">
                  <div class="bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-2 flex-shrink-0" style="width: 32px; height: 32px;">
                    <i class="fas fa-map text-primary" style="font-size: 0.82rem;"></i>
                  </div>
                  <div class="info-text-wrap" style="line-height: 1.25; min-width: 0; flex: 1;">
                    <div class="text-muted" style="font-size: 0.76rem;">Plot Location</div>
                    <div class="fw-bold text-dark info-val" style="font-size: 0.88rem; word-break: break-word; overflow-wrap: anywhere; line-height: 1.25;">Sec ${(deceased.section || section).toUpperCase()}, Row ${deceased.plot_x || plotX}, Plot ${deceased.plot_y || plotY}</div>
                  </div>
                </div>
              </div>
            </div>

            ${isAuthorizedViewer && p && (bookerName || (p.total_amount > 0)) ? `
            <div class="card border-0 shadow-sm mt-3" style="border-radius: 10px; background: #fff; border-left: 4px solid ${isPaid ? '#198754' : '#d97706'} !important; border: 1px solid #e2e8f0;">
              <div class="card-body p-2.5 px-3">
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1.5 border-bottom">
                  <span class="fw-bold text-dark d-flex align-items-center gap-2" style="font-size: 0.88rem;">
                    <span class="text-primary"><i class="fas fa-file-invoice-dollar"></i></span> Client & Payment Details
                  </span>
                  ${isPaid ? 
                    '<span class="badge bg-success px-2.5 py-1 rounded-pill" style="font-size: 0.76rem;"><i class="fas fa-check-circle me-1"></i> Paid in Full / Paid Now</span>' : 
                    '<span class="badge bg-warning text-dark px-2.5 py-1 rounded-pill fw-bold" style="font-size: 0.76rem;"><i class="fas fa-exclamation-triangle me-1"></i> Has Dues (₱' + balance.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ')</span>'
                  }
                </div>
                <div class="row g-2 small">
                  <div class="col-12 col-sm-6" style="min-width: 0;">
                    <span class="text-muted" style="font-size: 0.76rem;">Client / Buyer Name:</span>
                    <div class="fw-bold text-dark" style="font-size: 0.86rem; word-break: break-word; overflow-wrap: anywhere;" title="${escapeHtml(bookerName || '—')}">${escapeHtml(bookerName || '—')}</div>
                  </div>
                  <div class="col-12 col-sm-6" style="min-width: 0;">
                    <span class="text-muted" style="font-size: 0.76rem;">Payment Type / Plan:</span>
                    <div style="word-break: break-word;">
                      ${paymentPlan === 'Installment' ? 
                        '<span class="badge bg-primary px-2 py-0.5" style="font-size: 0.76rem;"><i class="fas fa-calendar-alt me-1"></i> Installment (' + (paymentTerm || 12) + ' Months)</span>' : 
                        '<span class="badge bg-info text-dark px-2 py-0.5" style="font-size: 0.76rem;"><i class="fas fa-money-bill-wave me-1"></i> Cash (Full Payment)</span>'
                      }
                    </div>
                  </div>
                  <div class="col-4">
                    <div class="text-muted" style="font-size: 0.76rem;">Total Price:</div>
                    <strong class="text-dark" style="font-size: 0.86rem;">₱${totalAmount.toLocaleString('en-PH', {minimumFractionDigits: 2})}</strong>
                  </div>
                  <div class="col-4">
                    <div class="text-muted" style="font-size: 0.76rem;">Amount Paid:</div>
                    <strong class="text-success" style="font-size: 0.86rem;">₱${amountPaid.toLocaleString('en-PH', {minimumFractionDigits: 2})}</strong>
                  </div>
                  <div class="col-4">
                    <div class="text-muted" style="font-size: 0.76rem;">Remaining Dues:</div>
                    <strong class="${balance > 0 ? 'text-danger' : 'text-muted'}" style="font-size: 0.86rem;">₱${balance.toLocaleString('en-PH', {minimumFractionDigits: 2})}</strong>
                  </div>
                  ${paymentPlan === 'Installment' && monthlyDue > 0 ? `
                  <div class="col-12 mt-1 pt-1.5 border-top d-flex justify-content-between align-items-center">
                    <span class="text-muted" style="font-size: 0.76rem;">Monthly Amortization (${paymentTerm} Mo):</span>
                    <strong class="text-primary" style="font-size: 0.82rem;">₱${monthlyDue.toLocaleString('en-PH', {minimumFractionDigits: 2})} / mo</strong>
                  </div>` : ''}
                </div>
              </div>
            </div>` : ''}

            <div class="d-flex justify-content-end gap-2 mt-3 pb-1 deceased-action-row flex-wrap">
              <button type="button" class="btn btn-sm btn-outline-info py-1.5 px-3" style="font-size: 0.85rem; border-radius: 6px;" onclick="navigation.startGoogleMapsNavigation('${deceased.section || section}', '${deceased.plot_x || plotX}', '${deceased.plot_y || plotY}', ${deceased.plot_id || (window.__currentPlotData ? window.__currentPlotData.plot_id : 0) || 0})">
                <i class="fas fa-directions me-1"></i>Get Directions
              </button>
              <?php if (strtoupper(trim($_SESSION['role'] ?? 'USER')) === 'ADMIN'): ?>
              <button type="button" class="btn btn-sm btn-outline-secondary py-1.5 px-3" style="font-size: 0.85rem; border-radius: 6px;" onclick="window.location.href = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php?move=1&deceased_id=' + (${deceased.deceased_id || 'null'});"><i class="fas fa-arrows-alt me-1"></i>Move</button>
              <button type="button" class="btn btn-sm btn-outline-dark py-1.5 px-3" style="font-size: 0.85rem; border-radius: 6px;" onclick="showEditDeceasedModalById(${deceased.deceased_id || 'null'})"><i class="fas fa-edit me-1"></i>Edit</button>
              ${canAdd ? '<button type="button" class="btn btn-sm btn-primary py-1.5 px-3.5" style="font-size: 0.85rem; border-radius: 6px;" onclick="showAddOccupiedDeceasedModal(\'' + (deceased.section || section) + '\',' + (deceased.plot_x || plotX) + ',' + (deceased.plot_y || plotY) + ')"><i class="fas fa-plus me-1"></i>Add Deceased</button>' : '<span class="badge bg-secondary py-1.5 px-2.5" style="font-size: 0.8rem;">Max (4) reached</span>'}
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      ${sidebarHtml}
    </div>
  `;
  if (window.__burialTimer) { clearInterval(window.__burialTimer); window.__burialTimer = null; }
  (function(){
    const span = document.getElementById('burial_duration');
    const toSpan = document.getElementById('burial_to');
    if (!span) return;
    if (!diedISO) { span.textContent = '—'; return; }
    function plural(n, w){ return n + ' ' + w + (n===1?'':'s'); }
    function manilaNowParts(){
      const u = new Date();
      let y = u.getUTCFullYear();
      let mo = u.getUTCMonth();
      let d = u.getUTCDate();
      let H = u.getUTCHours() + 8;
      let M = u.getUTCMinutes();
      let S = u.getUTCSeconds();
      if (H >= 24) { H -= 24; const daysInPrev = new Date(Date.UTC(y, mo+1, 0)).getUTCDate(); d += 1; if (d > new Date(Date.UTC(y, mo+1, 0)).getUTCDate()) { d = 1; mo += 1; if (mo > 11) { mo = 0; y += 1; } } }
      return { y, m: mo+1, d, H, M, S };
    }
    function manilaStartParts(isoDate){
      const m = (isoDate || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (!m) return null;
      return { y: parseInt(m[1],10), m: parseInt(m[2],10), d: parseInt(m[3],10), H:0, M:0, S:0 };
    }
    function daysInMonth(y, m){ return new Date(Date.UTC(y, m, 0)).getUTCDate(); }
    function pad(n){ return n<10 ? '0'+n : ''+n; }
    function monthName(m){ return new Date(Date.UTC(2000, m-1, 1)).toLocaleString('en-PH', { month:'long' }); }
    function formatManila(parts){
      const ampm = parts.H >= 12 ? 'PM' : 'AM';
      let h = parts.H % 12; if (h === 0) h = 12;
      return monthName(parts.m) + ' ' + pad(parts.d) + ', ' + parts.y + ' ' + pad(h) + ':' + pad(parts.M) + ':' + pad(parts.S) + ' ' + ampm;
    }
    function render(){
      const start = manilaStartParts(diedISO);
      const now = manilaNowParts();
      if (!start) { span.textContent = '—'; if (toSpan) toSpan.textContent = formatManila(now); return; }
      let y = now.y - start.y;
      let m = now.m - start.m;
      let d = now.d - start.d;
      if (d < 0) { m -= 1; const pm = now.m === 1 ? 12 : now.m - 1; const py = now.m === 1 ? now.y - 1 : now.y; d += daysInMonth(py, pm); }
      if (m < 0) { m += 12; y -= 1; }
      let parts = [];
      if (y) parts.push(plural(y,'year'));
      if (m) parts.push(plural(m,'month'));
      if (y===0 && m===0) { parts.push('Less than a month'); }
      span.textContent = parts.join(', ');
      if (toSpan) toSpan.textContent = formatManila(now);
    }
    render();
    window.__burialTimer = setInterval(render, 1000);
  })();
  try { triggerFloatAnimation(); } catch (e) {}
}

// Open and prefill the Edit Deceased modal for a given deceased_id using the current context list
// Payment helper functions for Edit Deceased Modal
function toggleEditDeceasedPaymentPlan() {
  var isInstallment = document.getElementById('edit_plan_installment') && document.getElementById('edit_plan_installment').checked;
  var termContainer = document.getElementById('editDecTermContainer');
  var wrapCash = document.getElementById('edit_wrap_plan_cash');
  var wrapInst = document.getElementById('edit_wrap_plan_installment');

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
  recalculateEditDeceasedPayment();
}

function handleEditPaymentStatusChange() {
  var status = document.getElementById('edit_payment_status') ? document.getElementById('edit_payment_status').value : 'PAID';
  var tot = parseFloat(document.getElementById('edit_total_amount') ? document.getElementById('edit_total_amount').value : 0) || 0;
  var paidEl = document.getElementById('edit_amount_paid');
  if (status === 'PAID') {
    if (paidEl) paidEl.value = tot.toFixed(2);
  } else {
    if (paidEl && parseFloat(paidEl.value) >= tot && tot > 0) {
      paidEl.value = (tot * 0.2).toFixed(2);
    }
  }
  recalculateEditDeceasedPayment();
}

function recalculateEditDeceasedPayment() {
  var isInstallment = document.getElementById('edit_plan_installment') && document.getElementById('edit_plan_installment').checked;
  var term = parseInt(document.getElementById('edit_payment_term') ? document.getElementById('edit_payment_term').value : 12, 10) || 12;
  var tot = parseFloat(document.getElementById('edit_total_amount') ? document.getElementById('edit_total_amount').value : 85000) || 0;
  var paid = parseFloat(document.getElementById('edit_amount_paid') ? document.getElementById('edit_amount_paid').value : 0) || 0;
  var balance = Math.max(0, tot - paid);

  var interestRate = 0.02;
  var monthlyDue = 0;
  if (isInstallment && term > 0) {
    monthlyDue = (tot / term) + (tot * (interestRate / 12));
  }
  var mDueInput = document.getElementById('edit_monthly_due');
  if (mDueInput) mDueInput.value = monthlyDue.toFixed(2);

  var mDisp = document.getElementById('editDecMonthlyDisplay');
  if (mDisp) mDisp.textContent = '₱' + monthlyDue.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' / mo';

  var balDisp = document.getElementById('editDecBalanceDisplay');
  var badge = document.getElementById('editDecPaymentBadge');
  var statusSel = document.getElementById('edit_payment_status');

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

function showEditDeceasedModalById(deceasedId) {
  // Check if user is admin (normalize server-side and perform case-insensitive client check)
  var userRole = '<?php echo isset($_SESSION['role']) ? strtoupper(trim($_SESSION['role'])) : 'USER'; ?>';
  if (typeof userRole !== 'string' || userRole.indexOf('ADMIN') === -1) {
    alert('Only administrators can edit deceased records.');
    return;
  }

  var ctx = window.__currentDeceased;
  if (!ctx || !ctx.list || !ctx.list.length) { alert('No deceased data loaded.'); return; }
  var item = ctx.list.find(function(d){ return String(d.deceased_id) === String(deceasedId); });
  if (!item) { alert('Selected record not found.'); return; }
  try {
    document.getElementById('edit_deceased_id').value = item.deceased_id || '';
    document.getElementById('edit_first_name').value = (item.first_name || '').toUpperCase();
    document.getElementById('edit_middle_name').value = (item.middle_name || '').toUpperCase();
    document.getElementById('edit_last_name').value = (item.last_name || '').toUpperCase();
    var sfx = (item.suffix || '').toUpperCase();
    var sel = document.getElementById('edit_suffix');
    var other = document.getElementById('edit_suffix_other');
    if (sel) {
      if (['JR.','SR.','II','III','IV','V',''].indexOf(sfx) >= 0) { sel.value = sfx; if (other) { other.style.display='none'; other.value=''; } }
      else { sel.value = 'OTHER'; if (other) { other.style.display='block'; other.value = sfx; } }
    }
    document.getElementById('edit_bday').value = item.bday || '';
    document.getElementById('edit_born_in').value = (item.born_in || '').toUpperCase();
    var rawContact = (item.contact_no || '').replace(/\D/g,'');
    if (rawContact.startsWith('639')) rawContact = '09' + rawContact.substring(3);
    else if (rawContact.startsWith('9')) rawContact = '09' + rawContact.substring(1);
    else if (!rawContact.startsWith('09')) rawContact = rawContact ? ('09' + rawContact) : '09';
    document.getElementById('edit_contact_no').value = rawContact.slice(0, 11);
    document.getElementById('edit_date_died').value = item.date_died || '';
    document.getElementById('edit_funeral_service').value = (item.funeral_service || '').toUpperCase();
    var secBadge = document.getElementById('editDecSectionBadge');
    if (secBadge && ctx.section) secBadge.textContent = 'Section ' + ctx.section;
    var plotDisp = document.getElementById('editDecPlotDisplay');
    if (plotDisp && ctx.plotX && ctx.plotY) plotDisp.textContent = 'Plot Coordinates: Column ' + ctx.plotX + ', Row ' + ctx.plotY;

    // Populate Booker & Payment Information
    var pay = window.__currentPayment || {};
    var bName = item.booker_name || pay.booker_name || '';
    if (bName === 'Not Specified') bName = '';
    var pPlan = item.payment_plan || pay.payment_plan || 'Cash';
    var pTerm = parseInt(item.payment_term || pay.payment_term || 12, 10) || 12;
    var pStatus = item.payment_status || pay.payment_status || 'PAID';
    var tAmt = (item.total_amount !== undefined && item.total_amount !== null) ? Number(item.total_amount) : Number(pay.total_amount || 85000);
    var aPaid = (item.amount_paid !== undefined && item.amount_paid !== null) ? Number(item.amount_paid) : Number(pay.amount_paid !== undefined ? pay.amount_paid : tAmt);

    var bNameEl = document.getElementById('edit_booker_name');
    if (bNameEl) bNameEl.value = bName.toUpperCase();

    if (pPlan === 'Installment') {
      var rInst = document.getElementById('edit_plan_installment');
      if (rInst) rInst.checked = true;
    } else {
      var rCash = document.getElementById('edit_plan_cash');
      if (rCash) rCash.checked = true;
    }

    var termEl = document.getElementById('edit_payment_term');
    if (termEl) termEl.value = String(pTerm);

    var statEl = document.getElementById('edit_payment_status');
    if (statEl) statEl.value = pStatus;

    var tAmtEl = document.getElementById('edit_total_amount');
    if (tAmtEl) tAmtEl.value = tAmt.toFixed(2);

    var aPaidEl = document.getElementById('edit_amount_paid');
    if (aPaidEl) aPaidEl.value = aPaid.toFixed(2);

    toggleEditDeceasedPaymentPlan();
    recalculateEditDeceasedPayment();
  } catch (e) { console.error('Error prefilling edit modal:', e); }
  // Hide info modal so edit appears in front
  try {
    var infoEl = document.getElementById('deceasedInfoModal');
    if (infoEl) {
      var infoM = bootstrap.Modal.getInstance(infoEl);
      if (infoM) { infoM.hide(); }
    }
  } catch (e) {}
  var editEl = document.getElementById('editDeceasedModal');
  if (editEl) {
    var modal = bootstrap.Modal.getOrCreateInstance(editEl);
    modal.show();
  }
}

// Submit the edited deceased record
function submitEditDeceased() {
  var id = document.getElementById('edit_deceased_id').value;
  var first = document.getElementById('edit_first_name').value.trim();
  var middle = document.getElementById('edit_middle_name').value.trim();
  var last = document.getElementById('edit_last_name').value.trim();
  var sfxSel = document.getElementById('edit_suffix');
  var sfxOther = document.getElementById('edit_suffix_other');
  var sfx = '';
  if (sfxSel) {
    var v = (sfxSel.value || '').trim().toUpperCase();
    if (v === 'OTHER' && sfxOther) { sfx = (sfxOther.value || '').trim().toUpperCase(); }
    else { sfx = v; }
  }
  var bday = document.getElementById('edit_bday').value.trim();
  var born_in = document.getElementById('edit_born_in').value.trim();
  var contact = document.getElementById('edit_contact_no').value.trim();
  var died = document.getElementById('edit_date_died').value.trim();
  var funeral = document.getElementById('edit_funeral_service').value.trim();
  if (!first || !last || !bday || !born_in || !contact || !died || !funeral) { alert('Please complete all required fields.'); return; }
  var today = new Date().toISOString().split('T')[0];
  if (bday > today) { alert('Birth date cannot be in the future.'); return; }
  if (died > today) { alert('Death date cannot be in the future.'); return; }
  if (died < bday) { alert('Death date cannot be before birth date.'); return; }
  if (!/^09\d{9}$/.test(contact.replace(/\D/g,''))) { alert('Contact number must be an 11-digit mobile number starting with 09.'); return; }

  var booker = (document.getElementById('edit_booker_name') ? document.getElementById('edit_booker_name').value.trim() : '').toUpperCase();
  var plan = (document.getElementById('edit_plan_installment') && document.getElementById('edit_plan_installment').checked) ? 'Installment' : 'Cash';
  var term = document.getElementById('edit_payment_term') ? document.getElementById('edit_payment_term').value : '12';
  var status = document.getElementById('edit_payment_status') ? document.getElementById('edit_payment_status').value : 'PAID';
  var totalAmount = document.getElementById('edit_total_amount') ? document.getElementById('edit_total_amount').value : '85000';
  var amountPaid = document.getElementById('edit_amount_paid') ? document.getElementById('edit_amount_paid').value : '85000';
  var monthlyDue = document.getElementById('edit_monthly_due') ? document.getElementById('edit_monthly_due').value : '0';

  var btn = document.querySelector('#editDeceasedModal .btn-primary') || document.querySelector('#editDeceasedModal .btn.btn-dark');
  var original = btn ? btn.innerHTML : '';
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...'; }
  var body = new URLSearchParams();
  body.set('ajax_request','edit_deceased');
  body.set('deceased_id', id);
  body.set('first_name', first.toUpperCase());
  body.set('middle_name', middle.toUpperCase());
  body.set('last_name', last.toUpperCase());
  body.set('suffix', sfx);
  if (sfxSel && sfxSel.value === 'OTHER') { body.set('suffix_other', sfx); }
  body.set('bday', bday);
  body.set('born_in', born_in.toUpperCase());
  body.set('contact_no', contact.replace(/\D/g,''));
  body.set('date_died', died);
  body.set('funeral_service', funeral.toUpperCase());
  body.set('booker_name', booker);
  body.set('payment_plan', plan);
  body.set('payment_term', term);
  body.set('payment_status', status);
  body.set('total_amount', totalAmount);
  body.set('amount_paid', amountPaid);
  body.set('monthly_due', monthlyDue);

  fetch(popupPath, { 
    method:'POST', 
    headers:{ 'Content-Type':'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, 
    credentials: 'same-origin',
    body: body.toString() 
  })
    .then(r => r.text())
    .then(t => { try { return JSON.parse(t); } catch(e) { console.error('Raw:', t); throw e; } })
    .then(data => {
      if (data.success) {
        const editM = bootstrap.Modal.getInstance(document.getElementById('editDeceasedModal'));
        if (editM) editM.hide();
        alert('Deceased record updated successfully');
        // Refresh the info modal to reflect changes
        try {
          var ctx = window.__currentDeceased;
          if (ctx && ctx.section && ctx.plotX && ctx.plotY) {
            showDeceasedInfo(ctx.section, ctx.plotX, ctx.plotY);
          } else {
            window.location.reload();
          }
        } catch (e) { window.location.reload(); }
      } else {
        alert('Error: ' + (data.message || 'Failed to update record'));
      }
    })
    .catch(() => alert('Request failed'))
    .finally(() => { if (btn) { btn.disabled = false; btn.innerHTML = original; } });
}

// Confirm and delete a deceased record using SweetAlert2
function confirmDeleteDeceased(deceasedId) {
  if (!deceasedId) { alert('Invalid deceased selected.'); return; }
  Swal.fire({
    title: 'Are you sure you want to delete this deceased record?',
    text: 'This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Delete',
    confirmButtonColor: '#d33',
    cancelButtonText: 'Cancel'
  }).then((result) => {
    if (result.isConfirmed) {
      // perform deletion via AJAX
      const body = new URLSearchParams();
      body.set('ajax_request', 'delete_deceased');
      body.set('deceased_id', deceasedId);
      fetch(popupPath, { 
        method: 'POST', 
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, 
        credentials: 'same-origin',
        body: body.toString() 
      })
        .then(r => r.json())
        .then(data => {
          if (data && data.success) {
            Swal.fire({ icon: 'success', title: 'Deleted', text: data.message || 'Deceased deleted.' }).then(() => {
              // Redirect back to main map so occupancy counts refresh
              window.location.href = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php';
            });
          } else {
            Swal.fire({ icon: 'error', title: 'Error', text: (data && data.message) ? data.message : 'Failed to delete deceased.' });
          }
        })
        .catch(err => { console.error('Delete failed', err); Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' }); });
    }
  });
}

// Delegated click handler to switch displayed deceased by clicking an occupant in the list
document.addEventListener('click', function(e) {
  var occ = e.target.closest('.occupant-item');
  if (occ) {
    var did = occ.getAttribute('data-deceased-id');
    var ctx = window.__currentDeceased;
    if (ctx && ctx.list && ctx.list.length) {
      var found = ctx.list.find(function(d){ return String(d.deceased_id) === String(did); });
      if (found) {
        displayDeceasedInfo(found, ctx.section, ctx.plotX, ctx.plotY, ctx.list.length, ctx.list);
      }
    }
  }
});

function displayPurchasedPlot(section, plotX, plotY, plotData) {
  var p = (plotData && plotData.payment_details) ? plotData.payment_details : {};
  var res = (plotData && plotData.reservation) ? plotData.reservation : {};
  var buyerName = p.booker_name || res.owner_name || '';
  var buyerPhone = res.phone_no || p.phone_no || '';
  displayEmptyPlot(section, plotX, plotY, buyerName, buyerPhone, plotData);
}

function displayEmptyPlot(section, plotX, plotY, ownerName, ownerPhone, plotData) {
  var isGuest = <?php echo ($isGuest ? 'true' : 'false'); ?>;
  var isAdmin = <?php echo ($isAdmin ? 'true' : 'false'); ?>;
  var plotStatus = String((plotData && plotData.status) || '').trim().toUpperCase();
  var isStatusReserved = (plotStatus === 'RESERVED' || plotStatus === 'OWNED' || plotStatus === 'CLAIMED' || plotStatus === 'PURCHASED' || plotStatus === 'SOLD');
  var validOwnerName = (ownerName && String(ownerName).trim() && String(ownerName).trim().toLowerCase() !== 'reserved' && String(ownerName).trim().toLowerCase() !== 'unknown' && String(ownerName).trim().toLowerCase() !== 'n/a') ? String(ownerName).trim() : '';
  if (!validOwnerName && plotData && plotData.reservation && plotData.reservation.owner_name) {
    var rawResOwner = String(plotData.reservation.owner_name).trim();
    if (rawResOwner && rawResOwner.toLowerCase() !== 'reserved' && rawResOwner.toLowerCase() !== 'unknown' && rawResOwner.toLowerCase() !== 'n/a') {
      validOwnerName = rawResOwner;
    }
  }
  var isReserved = isStatusReserved && (Boolean(validOwnerName) || Boolean(plotData && plotData.reservation && (plotData.reservation.booking_id || plotData.reservation.user_id)));
  if (!isReserved) {
    var mapBtn = document.querySelector('.plot-btn[data-plot-x="' + plotX + '"][data-plot-y="' + plotY + '"]');
    if (mapBtn && (mapBtn.hasAttribute('data-reserved') || mapBtn.hasAttribute('data-purchased') || mapBtn.classList.contains('plot-blinking-reserved'))) {
      if (validOwnerName) {
        isReserved = true;
      } else {
        mapBtn.removeAttribute('data-reserved');
        mapBtn.removeAttribute('data-purchased');
        mapBtn.classList.remove('plot-blinking-reserved');
      }
    }
  }
  var isAuthorizedViewer = !isGuest && (isAdmin || Boolean(plotData && (plotData.is_admin || plotData.is_user_authorized)));
  window.__currentPlotData = plotData || null;
  window.__currentOwnerName = validOwnerName || ownerName || (isReserved ? 'Reserved' : '');
  window.__currentOwnerPhone = ownerPhone || (plotData && plotData.reservation && plotData.reservation.phone_no) || '';
  window.__currentPayment = (plotData && plotData.payment_details) ? plotData.payment_details : {};
  var lotPrice = (plotData && plotData.lot_price !== undefined) ? Number(plotData.lot_price) : ((plotData && plotData.price !== undefined) ? Number(plotData.price) : 85000);
  var intermentFee = (plotData && plotData.interment_fee !== undefined) ? Number(plotData.interment_fee) : 38000;
  var totalPrice = (plotData && plotData.total_price !== undefined) ? Number(plotData.total_price) : (lotPrice + intermentFee);
  var lawnType = (plotData && plotData.lawn_type) ? plotData.lawn_type : 'Lawn (Regular)';
  var plotType = (plotData && plotData.plot_type) ? plotData.plot_type : 'Lawn';
  var lotPriceFmt = '₱' + lotPrice.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  var intermentFeeFmt = '₱' + intermentFee.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  var totalFmt = '₱' + totalPrice.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});

  var pricingCard = isReserved ? '' : `
    <div class="card border-0 bg-light p-3 mb-3 mx-auto text-start shadow-sm" style="max-width: 400px; border-radius: 12px; font-size: 0.9rem;">
      <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Plot Type:</span><span class="fw-semibold text-primary">${phpEscape(plotType)} (${phpEscape(lawnType)})</span></div>
      <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Lot Price:</span><span class="fw-bold text-success">${phpEscape(lotPriceFmt)}</span></div>
      <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Interment Fee:</span><span class="fw-semibold text-dark">${phpEscape(intermentFeeFmt)}</span></div>
      <div class="d-flex justify-content-between py-1 border-bottom"><span class="fw-bold text-dark">Total:</span><span class="fw-bold fs-6" style="color:#7b1113;">${phpEscape(totalFmt)}</span></div>
      <div class="d-flex justify-content-between pt-1"><span class="text-muted small">12 Mos Amortization:</span><span class="small fw-semibold text-secondary">₱${(lotPrice === 89000 ? '7,565.00' : '7,226.00')}/mo</span></div>
    </div>
  `;

  var isPurchased = (plotStatus === 'PURCHASED' || plotStatus === 'OWNED' || plotStatus === 'SOLD' || Boolean(plotData && plotData.reservation && plotData.reservation.is_purchased));
  var isReserved = isPurchased || (isStatusReserved && (Boolean(validOwnerName) || Boolean(plotData && plotData.reservation && (plotData.reservation.booking_id || plotData.reservation.user_id))));

  var reservationAlert = '';
  if (isReserved) {
    var displayOwner = validOwnerName || ownerName || (isPurchased ? 'Purchased Plot' : 'Reserved');
    var rawPhone = ownerPhone || (plotData && plotData.reservation && plotData.reservation.phone_no) || '';
    var displayPhone = '';
    if (rawPhone) {
      var pStr = String(rawPhone).trim();
      if (isAdmin || isAuthorizedViewer) {
        displayPhone = pStr;
      } else if (pStr.length >= 7) {
        displayPhone = pStr.substring(0, 4) + '****' + pStr.substring(pStr.length - 3);
      } else if (pStr.length > 2) {
        displayPhone = pStr.substring(0, 2) + '****';
      } else {
        displayPhone = '•••••••••••';
      }
    }

    var rawEmail = (plotData && plotData.reservation && (plotData.reservation.customer_email || plotData.reservation.email)) || '';
    var displayEmail = '';
    if (rawEmail) {
      var eStr = String(rawEmail).trim();
      if (isAdmin || isAuthorizedViewer) {
        displayEmail = eStr;
      } else {
        var parts = eStr.split('@');
        displayEmail = (parts[0].length > 2 ? parts[0].substring(0, 2) + '***' : '***') + '@' + (parts[1] || '***');
      }
    }

    var paymentSummaryHtml = '';
    var pDet = (plotData && plotData.payment_details) ? plotData.payment_details : {};
    if (isAuthorizedViewer && pDet && (pDet.total_amount || pDet.amount_paid)) {
      var totFmt = '₱' + Number(pDet.total_amount || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      var paidFmt = '₱' + Number(pDet.amount_paid || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      var balFmt = '₱' + Number(pDet.balance !== undefined ? pDet.balance : Math.max(0, (pDet.total_amount || 0) - (pDet.amount_paid || 0))).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      var planLabel = pDet.payment_term_label || pDet.payment_plan || 'Full Payment';
      var payStatusBadge = (pDet.is_paid || (pDet.balance !== undefined && Number(pDet.balance) <= 0))
        ? '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Paid in Full</span>'
        : '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Has Dues</span>';

      paymentSummaryHtml = `
        <div class="mt-2 pt-2 border-top">
          <div class="d-flex justify-content-between py-1"><span class="text-muted small">Payment Plan:</span><strong class="small text-dark">${phpEscape(planLabel)}</strong></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted small">Total Contract Price:</span><span class="small fw-bold text-dark">${phpEscape(totFmt)}</span></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted small">Amount Paid:</span><span class="small fw-semibold text-success">${phpEscape(paidFmt)}</span></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted small">Remaining Balance:</span><span class="small fw-bold" style="color:#7b1113;">${phpEscape(balFmt)}</span></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted small">Payment Status:</span>${payStatusBadge}</div>
        </div>
      `;
    }

    reservationAlert = `<div class="alert alert-secondary py-3 px-3 mx-auto text-start shadow-sm" style="max-width: 480px; width: 100%; border-radius: 12px; background: #fffdfa; border: 1.5px solid #fed7aa; border-left: 5px solid #7b1113 !important;">
      <div class="d-flex justify-content-between py-1 border-bottom">
        <span class="text-muted">Plot Status:</span>
        <span class="badge ${isPurchased ? 'bg-dark text-white' : 'bg-warning text-dark'}">
          <i class="fas ${isPurchased ? 'fa-tag' : 'fa-bookmark'} me-1"></i>${isPurchased ? 'Purchased Plot' : 'Reserved'}
        </span>
      </div>
      <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">${isPurchased ? 'Client / Buyer:' : 'Reserved By:'}</span><strong class="text-dark">${phpEscape(displayOwner)}</strong></div>
      ${displayEmail ? `<div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Client Email:</span><span class="fw-semibold text-dark"><i class="fas fa-envelope text-primary me-1"></i>${phpEscape(displayEmail)}</span></div>` : ''}
      ${displayPhone ? `<div class="d-flex justify-content-between py-1"><span class="text-muted">Contact Number:</span><span class="fw-semibold text-secondary"><i class="fas fa-phone-alt text-success me-1"></i>${phpEscape(displayPhone)}</span></div>` : ''}
      ${paymentSummaryHtml}
    </div>`;
  } else {
    reservationAlert = `<p class="text-muted mb-4">This plot is currently available.</p>`;
  }

  var titleEl = document.getElementById('deceasedInfoModalLabel');
  if (titleEl) {
    titleEl.innerHTML = isPurchased 
      ? '<i class="fas fa-tag me-2 text-warning"></i>Plot & Purchase Details' 
      : (isReserved ? '<i class="fas fa-bookmark me-2 text-warning"></i>Plot & Reservation Details' : '<i class="fas fa-info-circle me-2"></i>Plot Information');
  }

  document.getElementById('deceasedInfoContent').innerHTML = `
    <div class="text-center py-3 px-2 mx-auto" style="max-width: 520px;">
      <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 72px; height: 72px;">
        <i class="fas fa-map-marker-alt text-muted" style="font-size: 1.85rem;"></i>
      </div>
      <h4 class="fw-bold text-dark mb-2">${isPurchased ? 'Purchased Plot' : (isReserved ? 'Reserved Plot' : 'Empty Plot')}</h4>
      ${reservationAlert}
      <div class="bg-light rounded p-3 d-inline-block mb-3" style="max-width: 480px; width: 100%;">
        <strong>Location:</strong> Section ${section.toUpperCase()}, Row ${plotX}, Plot ${plotY}
      </div>
      ${pricingCard}
      <div class="d-flex flex-wrap gap-2 justify-content-center mt-2">
        <?php if ($isAdmin): ?>
        <button type="button" class="btn btn-outline-secondary btn-lg" onclick="showAddDeceasedModal('${section}', ${plotX}, ${plotY}, ${isReserved ? 'true' : 'false'})">
          <i class="fas fa-plus me-2"></i>Add Deceased
        </button>
        ${isReserved ? `<button type="button" class="btn btn-primary btn-lg" onclick="showManageReservationModal('${section}', ${plotX}, ${plotY})">
          <i class="fas fa-user-cog me-2"></i>Manage
        </button>` : `<button type="button" class="btn btn-primary btn-lg" onclick="showAdminBookingForm('${section}', ${plotX}, ${plotY}, '${lawnType}')">
          <i class="fas fa-bookmark me-2"></i>Book Now
        </button>`}
        <?php else: ?>
        ${isReserved ? (isAuthorizedViewer ? `
          <div class="w-100 text-center mb-1">
            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 fs-6 rounded-pill">
              <i class="fas fa-check-circle me-1"></i>Your Registered Plot
            </span>
          </div>
          <button type="button" class="btn btn-primary btn-lg" onclick="handlePlotServiceClick('${section}', ${plotX}, ${plotY})">
            <i class="fas fa-concierge-bell me-2"></i>Reserve Cemetery Service
          </button>` : `<button type="button" class="btn btn-secondary btn-lg" disabled>
          <i class="fas fa-lock me-2"></i>Reserved
        </button>`) : `<button type="button" class="btn btn-primary btn-lg" onclick="handlePlotReserveClick('${section}', ${plotX}, ${plotY}, '${lawnType}')">
          <i class="fas fa-bookmark me-2"></i>Reserve This Plot
        </button>`}
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary btn-lg" onclick="navigation.startGoogleMapsNavigation('${section}', ${plotX}, ${plotY}, ${(plotData && plotData.plot_id) || (window.__currentPlotData ? window.__currentPlotData.plot_id : 0) || 0})">
          <i class="fas fa-directions me-2"></i>Get Directions
        </button>
      </div>
    </div>
  `;
  try { triggerFloatAnimation(); } catch (e) {}
}

function showAdminBookingForm(section, plotX, plotY, lawnType) {
  var secUpper = (section || 'A').toString().toUpperCase();
  var px = parseInt(plotX, 10) || 1;
  var py = parseInt(plotY, 10) || 1;
  var isCorner = (typeof isCornerPlot === 'function') ? isCornerPlot(secUpper, px, py) : false;
  var curData = window.__currentPlotData || {};
  var typeName = lawnType || curData.lawn_type || (isCorner ? 'Lawn (Premium)' : 'Lawn (Regular)');
  var isPrem = isCorner || (typeName && /premium/i.test(typeName)) || (curData.lawn_type && /premium/i.test(curData.lawn_type)) || (Number(curData.price || curData.lot_price || 0) >= 89000);
  var lotPrice = isPrem ? 89000 : (Number(curData.price || curData.lot_price || 0) > 0 ? Number(curData.price || curData.lot_price) : 85000);
  if (isPrem && !/premium/i.test(typeName)) {
    typeName = 'Lawn (Premium)';
  }
  lawnType = typeName;
  var plotNo = px + ', ' + py;

  var payload = {
    type: 'bookingPlotSelected',
    section: secUpper,
    plotX: px,
    plotY: py,
    plot_x: px,
    plot_y: py,
    plotNo: plotNo,
    plot_no: plotNo,
    price: lotPrice,
    lot_price: lotPrice,
    intermentFee: 38000,
    interment_fee: 38000,
    lawnType: lawnType,
    lawn_type: lawnType,
    status: 'AVAILABLE',
    source: 'admin'
  };

  // If running inside parent dashboard iframe
  if (window.parent && window.parent !== window) {
    var handledDirectAdmin = false;
    try {
      if (typeof window.parent.applySelectedPlotToTransfer === 'function') {
        window.parent.applySelectedPlotToTransfer(payload, true);
        handledDirectAdmin = true;
      } else if (typeof window.parent.applySelectedPlotToBooking === 'function') {
        window.parent.applySelectedPlotToBooking(payload, true);
        handledDirectAdmin = true;
      }
    } catch (e) {}
    if (!handledDirectAdmin) {
      try {
        window.parent.postMessage(payload, '*');
      } catch (e) {}
    }
    return;
  }

  // Standalone map window: open Admin Dashboard with booking modal active
  var adminUrl = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'dashboard.php?open=booking&section=' + encodeURIComponent(secUpper) + '&plot_x=' + encodeURIComponent(px) + '&plot_y=' + encodeURIComponent(py);
  window.location.href = adminUrl;
}

function showLoginRequiredModal(section, plotX, plotY) {
  var secUpper = (section || '').toString().toUpperCase();
  var px = parseInt(plotX, 10) || 1;
  var py = parseInt(plotY, 10) || 1;

  var plotTitleEl = document.getElementById('loginRequiredPlotText');
  if (plotTitleEl) {
    plotTitleEl.textContent = 'Section ' + secUpper + ' · Plot ' + px + '-' + py;
  }

  var loginUrl = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'login.php?redirect=plot&section=' + encodeURIComponent(secUpper) + '&plot_x=' + encodeURIComponent(px) + '&plot_y=' + encodeURIComponent(py);
  var signUpUrl = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'login.php?register=1&redirect=plot&section=' + encodeURIComponent(secUpper) + '&plot_x=' + encodeURIComponent(px) + '&plot_y=' + encodeURIComponent(py);

  var loginBtn = document.getElementById('loginRequiredLoginBtn');
  if (loginBtn) {
    loginBtn.href = loginUrl;
    loginBtn.onclick = function(e) {
      e.preventDefault();
      if (window.top && window.top !== window) {
        window.top.location.href = loginUrl;
      } else {
        window.location.href = loginUrl;
      }
    };
  }

  var signUpBtn = document.getElementById('loginRequiredSignUpBtn');
  if (signUpBtn) {
    signUpBtn.href = signUpUrl;
    signUpBtn.onclick = function(e) {
      e.preventDefault();
      if (window.top && window.top !== window) {
        window.top.location.href = signUpUrl;
      } else {
        window.location.href = signUpUrl;
      }
    };
  }

  // Close deceased modal if open
  var deceasedModalEl = document.getElementById('deceasedInfoModal');
  if (deceasedModalEl) {
    var dModal = bootstrap.Modal.getInstance(deceasedModalEl);
    if (dModal) dModal.hide();
  }

  var reqModalEl = document.getElementById('loginRequiredModal');
  if (reqModalEl) {
    var modal = bootstrap.Modal.getOrCreateInstance(reqModalEl);
    modal.show();
  }
}

function handlePlotReserveClick(section, plotX, plotY, lawnType) {
  var isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
  var isGuest = <?php echo $isGuest ? 'true' : 'false'; ?>;
  if (isAdmin) {
    showAdminBookingForm(section, plotX, plotY, lawnType);
    return;
  }
  if (isGuest) {
    showGuestReservationModal(section, plotX, plotY, lawnType);
    return;
  }
  // Logged-in user: proceed to user dashboard booking flow
  openExistingBookingFlow(section, plotX, plotY, lawnType);
}

function openExistingBookingFlow(section, plotX, plotY, lawnType) {
  var isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
  var isGuest = <?php echo $isGuest ? 'true' : 'false'; ?>;
  if (isAdmin) {
    showAdminBookingForm(section, plotX, plotY, lawnType);
    return;
  }
  if (isGuest) {
    showGuestReservationModal(section, plotX, plotY, lawnType);
    return;
  }
  var target = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'user_dashboard.php?booking=1&section=' + encodeURIComponent(section) + '&plot_x=' + encodeURIComponent(plotX) + '&plot_y=' + encodeURIComponent(plotY);
  if (window.top && window.top !== window) {
    window.top.location.href = target;
    return;
  }
  window.location.href = target;
}

function handlePlotServiceClick(section, plotX, plotY) {
  var target = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'user_dashboard.php?booking=1&purpose=cemetery_service&section=' + encodeURIComponent(section) + '&plot_x=' + encodeURIComponent(plotX) + '&plot_y=' + encodeURIComponent(plotY);
  if (window.top && window.top !== window) {
    window.top.location.href = target;
    return;
  }
  window.location.href = target;
}

window.__selectedReservationPlot = { section: 'A', plotX: 1, plotY: 1, lawnType: 'Lawn (Regular)' };

function showReservationChoiceModal(section, plotX, plotY, lawnType) {
  var secUpper = (section || 'A').toString().toUpperCase();
  var px = parseInt(plotX, 10) || 1;
  var py = parseInt(plotY, 10) || 1;
  var isCorner = (typeof isCornerPlot === 'function') ? isCornerPlot(secUpper, px, py) : false;
  var curData = window.__currentPlotData || {};
  var typeName = lawnType || curData.lawn_type || (isCorner ? 'Lawn (Premium)' : 'Lawn (Regular)');
  var isPrem = isCorner || (typeName && /premium/i.test(typeName)) || (curData.lawn_type && /premium/i.test(curData.lawn_type)) || (Number(curData.price || curData.lot_price || 0) >= 89000);
  var lotPrice = isPrem ? 89000 : (Number(curData.price || curData.lot_price || 0) > 0 ? Number(curData.price || curData.lot_price) : 85000);
  if (isPrem && !/premium/i.test(typeName)) {
    typeName = 'Lawn (Premium)';
  }

  var currentPlotId = (window.__currentPlotData && window.__currentPlotData.plot_id) ? window.__currentPlotData.plot_id : '';
  window.__selectedReservationPlot = {
    section: secUpper,
    plotX: px,
    plotY: py,
    plotId: currentPlotId,
    lawnType: typeName,
    lotPrice: lotPrice
  };

  var dispPlot = document.getElementById('choice_plot_display');
  if (dispPlot) dispPlot.textContent = 'Section ' + secUpper + ' · Plot ' + px + '-' + py;

  var dispPrice = document.getElementById('choice_price_display');
  if (dispPrice) dispPrice.textContent = typeName + ' · ₱' + lotPrice.toLocaleString('en-PH', {minimumFractionDigits: 2});

  // Close deceased or plot info modal first, THEN show choice modal after animation completes
  var deceasedModalEl = document.getElementById('deceasedInfoModal');
  var choiceModalEl   = document.getElementById('reservationChoiceModal');

  function _showChoiceModal() {
    if (choiceModalEl) bootstrap.Modal.getOrCreateInstance(choiceModalEl).show();
  }

  // Only wait for animation if the deceased modal is currently VISIBLE
  var dModal = deceasedModalEl ? bootstrap.Modal.getInstance(deceasedModalEl) : null;
  var isDeceasedVisible = deceasedModalEl && deceasedModalEl.classList.contains('show');
  if (dModal && isDeceasedVisible) {
    deceasedModalEl.addEventListener('hidden.bs.modal', function onDecHidden() {
      deceasedModalEl.removeEventListener('hidden.bs.modal', onDecHidden);
      _showChoiceModal();
    });
    dModal.hide();
  } else {
    _showChoiceModal();
  }
}

function choiceSelectAccount() {
  var p = window.__selectedReservationPlot || {};
  var sec = p.section || 'A';
  var px = p.plotX || 1;
  var py = p.plotY || 1;
  var choiceModalEl = document.getElementById('reservationChoiceModal');
  if (choiceModalEl) {
    var m = bootstrap.Modal.getInstance(choiceModalEl);
    if (m) m.hide();
  }
  var targetUrl = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) +
    'login.php?redirect=plot&section=' + encodeURIComponent(sec) + '&plot_x=' + encodeURIComponent(px) + '&plot_y=' + encodeURIComponent(py);
  if (window.top && window.top !== window) {
    window.top.location.href = targetUrl;
  } else {
    window.location.href = targetUrl;
  }
}

function choiceSelectExistingDeceased() {
  var choiceModalEl = document.getElementById('reservationChoiceModal');
  if (choiceModalEl) {
    var m = bootstrap.Modal.getInstance(choiceModalEl);
    if (m) m.hide();
  }
  var targetUrl = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) +
    'login.php?register=1&flow=existing_deceased';
  if (window.top && window.top !== window) {
    window.top.location.href = targetUrl;
  } else {
    window.location.href = targetUrl;
  }
}

function choiceSelectNoDeceased() {
  var p = window.__selectedReservationPlot || {};
  var choiceModalEl = document.getElementById('reservationChoiceModal');
  // Only wait for animation if the modal is currently VISIBLE
  if (choiceModalEl && choiceModalEl.classList.contains('show')) {
    var m = bootstrap.Modal.getInstance(choiceModalEl);
    if (m) {
      choiceModalEl.addEventListener('hidden.bs.modal', function onChoiceHidden() {
        choiceModalEl.removeEventListener('hidden.bs.modal', onChoiceHidden);
        showGuestReservationModal(p.section, p.plotX, p.plotY, p.lawnType);
      });
      m.hide();
      return;
    }
  }
  showGuestReservationModal(p.section, p.plotX, p.plotY, p.lawnType);
}

function choiceSelectGuest() {
  choiceSelectNoDeceased();
}

function backToChoiceModal(currentModalId) {
  var p = window.__selectedReservationPlot || {};

  function _showChoice() {
    showReservationChoiceModal(p.section, p.plotX, p.plotY, p.lawnType);
  }

  if (currentModalId) {
    var currModalEl = document.getElementById(currentModalId);
    // Only wait for animation if the modal is currently VISIBLE
    if (currModalEl && currModalEl.classList.contains('show')) {
      var m = bootstrap.Modal.getInstance(currModalEl);
      if (m) {
        currModalEl.addEventListener('hidden.bs.modal', function onCurrHidden() {
          currModalEl.removeEventListener('hidden.bs.modal', onCurrHidden);
          _showChoice();
        });
        m.hide();
        return;
      }
    }
  }
  _showChoice();
}

function returnToPlotInfoModal(fromModalId) {
  var p = window.__selectedReservationPlot || {};

  function _showPlotInfo() {
    var infoModalEl = document.getElementById('deceasedInfoModal');
    var contentEl = document.getElementById('deceasedInfoContent');

    // If plot info content is empty or displaying loading spinner, reload fresh plot info
    if (!contentEl || !contentEl.innerHTML || contentEl.querySelector('.spinner-border')) {
      if (p.section && p.plotX && p.plotY && typeof showDeceasedInfo === 'function') {
        showDeceasedInfo(p.section, p.plotX, p.plotY);
        return;
      }
    }

    if (infoModalEl) {
      var m = bootstrap.Modal.getOrCreateInstance(infoModalEl);
      m.show();
    }
  }

  if (fromModalId) {
    var currModalEl = document.getElementById(fromModalId);
    if (currModalEl && currModalEl.classList.contains('show')) {
      var m = bootstrap.Modal.getInstance(currModalEl);
      if (m) {
        currModalEl.addEventListener('hidden.bs.modal', function onCurrHidden() {
          currModalEl.removeEventListener('hidden.bs.modal', onCurrHidden);
          setTimeout(_showPlotInfo, 50);
        });
        m.hide();
        return;
      }
    }
  }
  _showPlotInfo();
}

function showDeceasedVerificationModal(section, plotX, plotY, lawnType) {
  var secUpper = (section || 'A').toString().toUpperCase();
  var px = parseInt(plotX, 10) || 1;
  var py = parseInt(plotY, 10) || 1;
  var isCorner = (typeof isCornerPlot === 'function') ? isCornerPlot(secUpper, px, py) : false;
  var curData = window.__currentPlotData || {};
  var typeName = lawnType || curData.lawn_type || (isCorner ? 'Lawn (Premium)' : 'Lawn (Regular)');
  var isPrem = isCorner || (typeName && /premium/i.test(typeName)) || (curData.lawn_type && /premium/i.test(curData.lawn_type)) || (Number(curData.price || curData.lot_price || 0) >= 89000);
  var lotPrice = isPrem ? 89000 : (Number(curData.price || curData.lot_price || 0) > 0 ? Number(curData.price || curData.lot_price) : 85000);
  if (isPrem && !/premium/i.test(typeName)) {
    typeName = 'Lawn (Premium)';
  }

  var secEl = document.getElementById('dec_ver_section'); if (secEl) secEl.value = secUpper;
  var pxEl = document.getElementById('dec_ver_plot_x'); if (pxEl) pxEl.value = px;
  var pyEl = document.getElementById('dec_ver_plot_y'); if (pyEl) pyEl.value = py;
  var ltEl = document.getElementById('dec_ver_lawn_type'); if (ltEl) ltEl.value = typeName;

  var dispPlot = document.getElementById('dec_ver_plot_display');
  if (dispPlot) dispPlot.textContent = 'Section ' + secUpper + ' · Plot ' + px + '-' + py;

  var dispPrice = document.getElementById('dec_ver_price_display');
  if (dispPrice) dispPrice.textContent = typeName + ' · ₱' + lotPrice.toLocaleString('en-PH', {minimumFractionDigits: 2});

  var decModalEl = document.getElementById('deceasedVerificationModal');
  if (decModalEl) {
    var modal = bootstrap.Modal.getOrCreateInstance(decModalEl);
    modal.show();
  }
}

function handleDeceasedVerificationSubmit(e) {
  if (e) e.preventDefault();
  var form = document.getElementById('deceasedVerificationForm');
  if (!form) return;

  var btn = document.getElementById('decVerSubmitBtn');
  var origHtml = btn ? btn.innerHTML : '';

  var phone = (document.getElementById('dec_ver_req_phone') ? document.getElementById('dec_ver_req_phone').value : '').replace(/[^0-9]/g, '');
  if (!/^09[0-9]{9}$/.test(phone)) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({ icon: 'warning', title: 'Invalid Contact Number', text: 'Contact number must be an 11-digit mobile number starting with 09 (e.g. 09XXXXXXXXX).', confirmButtonColor: '#7b1113' });
    } else {
      alert('Contact number must be an 11-digit mobile number starting with 09.');
    }
    return;
  }

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying & Submitting...';
  }

  var formData = new FormData(form);
  formData.append('ajax_request', 'submit_guest_reservation');

  var reqName = (document.getElementById('dec_ver_req_name') ? document.getElementById('dec_ver_req_name').value : '');
  var reqEmail = (document.getElementById('dec_ver_req_email') ? document.getElementById('dec_ver_req_email').value : '');

  var targetUrl = (window.location.pathname.indexOf('/sections/') !== -1) ? 'popup.php' : 'sections/popup.php';

  fetch(targetUrl, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData
  })
  .then(function(res) {
    return res.json().catch(function() {
      throw new Error('Server returned invalid response');
    });
  })
  .then(function(data) {
    if (data && data.success) {
      var decModalEl = document.getElementById('deceasedVerificationModal');
      if (decModalEl) {
        var m = bootstrap.Modal.getInstance(decModalEl);
        if (m) m.hide();
      }
      form.reset();

      var succIdEl = document.getElementById('succ_booking_id');
      if (succIdEl) succIdEl.textContent = '#' + (data.booking_id || '');
      var succPlotEl = document.getElementById('succ_plot_disp');
      if (succPlotEl) succPlotEl.textContent = 'Section ' + (data.section || '') + ' · Plot ' + (data.plot || '');
      var succNameEl = document.getElementById('succ_name_disp');
      if (succNameEl) succNameEl.textContent = reqName || '—';
      var succEmailEl = document.getElementById('succ_email_disp');
      if (succEmailEl) succEmailEl.textContent = reqEmail || '—';
      var rawSubDate = data.submission_date || data.submitted_at;
      var subDateDisplay = '—';
      if (rawSubDate && String(rawSubDate).trim() !== '—' && String(rawSubDate).trim() !== '-') {
        if (String(rawSubDate).indexOf('-') !== -1 && String(rawSubDate).length <= 19) {
          try {
            subDateDisplay = new Date(String(rawSubDate).replace(' ', 'T')).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
          } catch(eSub) { subDateDisplay = rawSubDate; }
        } else {
          subDateDisplay = rawSubDate;
        }
      }
      if (!subDateDisplay || subDateDisplay === '—' || subDateDisplay === 'Invalid Date') {
        subDateDisplay = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
      }
      var succDateEl = document.getElementById('succ_date_disp');
      if (succDateEl) succDateEl.textContent = subDateDisplay;

      var rawPrefDate = data.preferred_date_display || data.preferred_date;
      var prefDateDisplay = '—';
      if (rawPrefDate && String(rawPrefDate).trim() !== '—' && String(rawPrefDate).trim() !== '-') {
        if (String(rawPrefDate).indexOf('-') !== -1 && String(rawPrefDate).length <= 10) {
          try {
            prefDateDisplay = new Date(String(rawPrefDate) + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
          } catch(ePref) { prefDateDisplay = rawPrefDate; }
        } else {
          prefDateDisplay = rawPrefDate;
        }
      }
      if (!prefDateDisplay || prefDateDisplay === '—' || prefDateDisplay === 'Invalid Date') {
        prefDateDisplay = subDateDisplay;
      }
      var succPrefDateEl = document.getElementById('succ_pref_date_disp');
      if (succPrefDateEl) succPrefDateEl.textContent = prefDateDisplay;

      var succModalEl = document.getElementById('guestReservationSuccessModal');
      if (succModalEl) {
        var succModal = bootstrap.Modal.getOrCreateInstance(succModalEl);
        succModal.show();
      } else if (typeof Swal !== 'undefined') {
        var msg = data.is_match_found 
          ? 'Deceased record verified in cemetery records! Your reservation request has been submitted as Pending Admin Review.'
          : 'Your reservation request and deceased record details have been submitted as Pending Admin Review for staff verification.';
        Swal.fire({
          icon: 'success',
          title: 'Request Submitted!',
          text: msg,
          confirmButtonColor: '#7b1113'
        });
      }

      if (typeof loadReservedPlots === 'function') {
        loadReservedPlots();
      }
    } else {
      var errMsg = (data && data.message) ? data.message : 'Failed to submit verification request.';
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          icon: 'error',
          title: 'Submission Error',
          text: errMsg,
          confirmButtonColor: '#7b1113'
        });
      } else {
        alert('Error: ' + errMsg);
      }
    }
  })
  .catch(function(err) {
    console.error('Verification reservation error:', err);
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        icon: 'error',
        title: 'Submission Failed',
        text: 'A network or server error occurred while processing your request. Please try again.',
        confirmButtonColor: '#7b1113'
      });
    } else {
      alert('A network or server error occurred while processing your request.');
    }
  })
  .finally(function() {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = origHtml;
    }
  });
}

function getGuestSelectedPaymentPlan() {
  var fullRadio = document.getElementById('guest_plan_full');
  if (fullRadio && fullRadio.checked) return 'full';
  return 'installment';
}

function calculateNextMonthExactDate(baseDateStr) {
  var d;
  if (baseDateStr && /^\d{4}-\d{2}-\d{2}$/.test(baseDateStr)) {
    var parts = baseDateStr.split('-');
    d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
  } else {
    d = new Date();
  }
  var day = d.getDate();
  var month = d.getMonth();
  var year = d.getFullYear();

  var nextMonth = month + 1;
  var nextYear = year;
  if (nextMonth > 11) {
    nextMonth = 0;
    nextYear += 1;
  }
  var maxDays = new Date(nextYear, nextMonth + 1, 0).getDate();
  var nextDay = Math.min(day, maxDays);
  return new Date(nextYear, nextMonth, nextDay);
}

function updateGuestPaymentPlanUI() {
  var plan = getGuestSelectedPaymentPlan();
  var lotPrice = window.__guestCurrentLotPrice || 85000.00;
  var termEl = document.getElementById('guest_res_payment_term');
  var term = termEl ? parseInt(termEl.value, 10) : 36;
  if (isNaN(term) || term <= 0) term = 36;

  var installWrap = document.getElementById('guestInstallmentOptionsWrap');
  var headerTitle = document.getElementById('guest_res_downpayment_title');
  var badge = document.getElementById('guest_res_downpayment_badge');
  var desc = document.getElementById('guest_res_downpayment_desc');
  var amtLabel = document.getElementById('guest_res_amount_label');
  var amtDisp = document.getElementById('guest_res_downpayment_disp');
  var monthlyDisp = document.getElementById('guest_res_monthly_disp');
  var firstDueDisp = document.getElementById('guest_res_first_due_disp');
  var payBtn = document.getElementById('guestPayNowBtn');

  // Pay Now button is clean without any 20% downpayment string inside it
  if (payBtn) {
    payBtn.innerHTML = '<i class="fas fa-wallet me-2"></i> Pay Now — proceed to GCash';
  }

  // Official rates table
  var baseRates = { 12: 7226.00, 24: 4495.00, 36: 3336.00, 48: 2772.00, 60: 2446.00 };
  var baseRate = baseRates[term] || 3336.00;
  var monthlyAmount = Math.round(baseRate * 0.80 * (lotPrice / 85000.00) * 100) / 100;
  if (monthlyDisp) {
    monthlyDisp.textContent = '₱' + monthlyAmount.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' / mo';
  }

  // Calculate first monthly payment due date (+1 month on exact same day)
  var prefDateInput = document.getElementById('guest_res_preferred_date');
  var baseDateVal = prefDateInput ? prefDateInput.value : '';
  var nextDueDateObj = calculateNextMonthExactDate(baseDateVal);
  var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var formattedDueDate = months[nextDueDateObj.getMonth()] + ' ' + nextDueDateObj.getDate() + ', ' + nextDueDateObj.getFullYear();
  if (firstDueDisp) {
    firstDueDisp.textContent = formattedDueDate + ' (Exact Date Next Month)';
  }

  if (plan === 'full') {
    if (installWrap) installWrap.style.display = 'none';
    if (headerTitle) headerTitle.textContent = 'Full Payment Required via GCash';
    if (badge) badge.textContent = '₱' + lotPrice.toLocaleString('en-PH', {minimumFractionDigits: 2}) + ' Full Due';
    if (desc) desc.textContent = 'You have selected Full Cash Payment. Settle the total plot price securely via GCash below.';
    if (amtLabel) amtLabel.textContent = 'Total Amount:';
    if (amtDisp) amtDisp.textContent = '₱' + lotPrice.toLocaleString('en-PH', {minimumFractionDigits: 2});
    window.__guestDownpayment = lotPrice;
  } else {
    var downpayment = Math.round(lotPrice * 0.20 * 100) / 100;
    if (installWrap) installWrap.style.display = 'block';
    if (headerTitle) headerTitle.textContent = '20% Downpayment Required via GCash';
    if (badge) badge.textContent = '₱' + downpayment.toLocaleString('en-PH', {minimumFractionDigits: 2}) + ' Downpayment Due';
    if (desc) desc.textContent = 'To secure this plot reservation, an initial 20% downpayment is required today. Your monthly installment starts next month on ' + formattedDueDate + '.';
    if (amtLabel) amtLabel.textContent = 'Downpayment Amount:';
    if (amtDisp) amtDisp.textContent = '₱' + downpayment.toLocaleString('en-PH', {minimumFractionDigits: 2});
    window.__guestDownpayment = downpayment;
  }
}

function showGuestReservationModal(section, plotX, plotY, lawnType) {
  var secUpper = (section || 'A').toString().toUpperCase();
  var px = parseInt(plotX, 10) || 1;
  var py = parseInt(plotY, 10) || 1;
  var isCorner = (typeof isCornerPlot === 'function') ? isCornerPlot(secUpper, px, py) : false;
  var curData = window.__currentPlotData || {};
  var pObj = window.__selectedReservationPlot || {};
  var typeName = lawnType || pObj.lawnType || curData.lawn_type || (isCorner ? 'Lawn (Premium)' : 'Lawn (Regular)');
  var isPrem = isCorner || (typeName && /premium/i.test(typeName)) || (curData.lawn_type && /premium/i.test(curData.lawn_type)) || (Number(curData.price || curData.lot_price || pObj.lotPrice || 0) >= 89000);
  var lotPrice = isPrem ? 89000 : (Number(curData.price || curData.lot_price || pObj.lotPrice || 0) > 0 ? Number(curData.price || curData.lot_price || pObj.lotPrice) : 85000);
  if (isPrem && !/premium/i.test(typeName)) {
    typeName = 'Lawn (Premium)';
  }
  var pId = pObj.plotId || (curData && curData.plot_id ? curData.plot_id : '');

  window.__selectedReservationPlot = {
    section: secUpper,
    plotX: px,
    plotY: py,
    plotId: pId,
    lawnType: typeName,
    lotPrice: lotPrice
  };

  var secEl = document.getElementById('guest_res_section'); if (secEl) secEl.value = secUpper;
  var pxEl = document.getElementById('guest_res_plot_x'); if (pxEl) pxEl.value = px;
  var pyEl = document.getElementById('guest_res_plot_y'); if (pyEl) pyEl.value = py;
  var pIdEl = document.getElementById('guest_res_plot_id'); if (pIdEl) pIdEl.value = pId;
  var ltEl = document.getElementById('guest_res_lawn_type'); if (ltEl) ltEl.value = typeName;

  var dispSec = document.getElementById('guest_res_section_disp');
  if (dispSec) dispSec.textContent = 'Section ' + secUpper;

  var dispPlot = document.getElementById('guest_res_plot_disp');
  if (dispPlot) dispPlot.textContent = 'Plot ' + px + '-' + py;

  var dispPlotId = document.getElementById('guest_res_plotid_disp');
  if (dispPlotId) dispPlotId.textContent = pId ? ('#' + pId) : ('Plot ' + secUpper + '-' + px + '-' + py);

  var dispType = document.getElementById('guest_res_type_disp');
  if (dispType) dispType.textContent = typeName;

  var dispPrice = document.getElementById('guest_res_price_disp');
  if (dispPrice) dispPrice.textContent = '₱' + lotPrice.toLocaleString('en-PH', {minimumFractionDigits: 2});

  window.__guestCurrentLotPrice = lotPrice;
  window.__guestDownpayment = lotPrice * 0.20;
  if (typeof updateGuestPaymentPlanUI === 'function') {
    updateGuestPaymentPlanUI();
  }

  var isPayReturn = new URLSearchParams(window.location.search).get('guest_pay_return') === '1';
  if (!isPayReturn) {
    try {
      var savedStCheck = JSON.parse(sessionStorage.getItem('guest_reservation_pending') || '{}');
      if (savedStCheck.guest_res_section && (savedStCheck.guest_res_section !== secUpper || String(savedStCheck.guest_res_plot_x) !== String(px) || String(savedStCheck.guest_res_plot_y) !== String(py))) {
        clearGuestFormState();
        window.__guestPayInfo = null;
        window.__guestPaymentNonce = null;
        window.__guestPaymentCompleted = false;
      }
    } catch (eCl) {}
    if (!window.__guestPaymentCompleted) {
      var pArea = document.getElementById('guestProofUploadArea');
      if (pArea) pArea.style.display = 'none';
      var pStatus = document.getElementById('guestPayNowStatus');
      if (pStatus) pStatus.style.display = 'none';
      var pBtn = document.getElementById('guestPayNowBtn');
      if (pBtn) {
        pBtn.style.display = '';
        pBtn.disabled = false;
        pBtn.innerHTML = '<i class="fas fa-wallet me-2"></i> Pay Now — proceed to GCash';
      }
    }
  }

  // Backward-compat for legacy display element if present
  var dispPlotOld = document.getElementById('guest_res_plot_display');
  if (dispPlotOld) dispPlotOld.textContent = 'Section ' + secUpper + ' · Plot ' + px + '-' + py;
  var dispPriceOld = document.getElementById('guest_res_price_display');
  if (dispPriceOld) dispPriceOld.textContent = typeName + ' · ₱' + lotPrice.toLocaleString('en-PH', {minimumFractionDigits: 2});

  // Close deceased/plot info modal and choice modal if open, then open guest modal AFTER they're fully gone
  var deceasedModalEl = document.getElementById('deceasedInfoModal');
  var choiceModalEl   = document.getElementById('reservationChoiceModal');
  var guestModalEl    = document.getElementById('guestReservationModal');

  function _openGuestModal() {
    if (guestModalEl) {
      bootstrap.Modal.getOrCreateInstance(guestModalEl).show();
    }
  }

  // Only wait for animation if the modal is currently VISIBLE (has 'show' class)
  var dModal = deceasedModalEl ? bootstrap.Modal.getInstance(deceasedModalEl) : null;
  var cModal = choiceModalEl   ? bootstrap.Modal.getInstance(choiceModalEl)   : null;
  var isDecVisible  = deceasedModalEl && deceasedModalEl.classList.contains('show');
  var isChoiceVisible = choiceModalEl && choiceModalEl.classList.contains('show');

  if (dModal && isDecVisible) {
    // Wait for the close animation to finish before showing guest modal
    deceasedModalEl.addEventListener('hidden.bs.modal', function onHidden() {
      deceasedModalEl.removeEventListener('hidden.bs.modal', onHidden);
      _openGuestModal();
    });
    if (cModal && isChoiceVisible) cModal.hide();
    dModal.hide();
  } else if (cModal && isChoiceVisible) {
    choiceModalEl.addEventListener('hidden.bs.modal', function onChoiceHidden() {
      choiceModalEl.removeEventListener('hidden.bs.modal', onChoiceHidden);
      _openGuestModal();
    });
    cModal.hide();
  } else {
    _openGuestModal();
  }
}

function formatPhilippineMobile(input) {
  if (!input) return;
  var val = input.value.replace(/[^0-9]/g, '');
  if (val.indexOf('639') === 0) {
    val = '09' + val.substring(3);
  } else if (val.indexOf('9') === 0) {
    val = '09' + val.substring(1);
  } else if (val.length >= 2 && val.charAt(0) === '0' && val.charAt(1) !== '9') {
    val = '0';
  } else if (val.length === 1 && val !== '0') {
    val = '09';
  }
  input.value = val.slice(0, 11);
}

function validateGuestProofSize(input) {
  if (!input || !input.files || !input.files[0]) return;
  var file = input.files[0];
  var maxBytes = 5 * 1024 * 1024; // 5MB limit
  if (file.size > maxBytes) {
    input.value = '';
    var pBox = document.getElementById('guestProofPreview');
    var pImg = document.getElementById('guestProofPreviewImg');
    if (pBox) pBox.style.display = 'none';
    if (pImg) pImg.src = '';
    var sizeMb = (file.size / (1024 * 1024)).toFixed(2);
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        icon: 'warning',
        title: 'File Too Large',
        text: 'The selected picture is ' + sizeMb + 'MB. Maximum allowed picture size is 5MB.'
      });
    } else {
      alert('The selected picture is ' + sizeMb + 'MB. Maximum allowed picture size is 5MB.');
    }
    return;
  }
  var pBox = document.getElementById('guestProofPreview');
  var pImg = document.getElementById('guestProofPreviewImg');
  if (pBox && pImg && file.type && file.type.indexOf('image/') !== -1) {
    var reader = new FileReader();
    reader.onload = function(e) {
      pImg.src = e.target.result;
      pBox.style.display = 'block';
    };
    reader.readAsDataURL(file);
  }
}

function guestPayNowViaGcash(e) {
  if (e && e.preventDefault) e.preventDefault();
  if (e && e.stopPropagation) e.stopPropagation();

  var curSec = document.getElementById('guest_res_section') ? document.getElementById('guest_res_section').value.trim().toUpperCase() : '';
  var curPx = document.getElementById('guest_res_plot_x') ? document.getElementById('guest_res_plot_x').value.trim() : '';
  var curPy = document.getElementById('guest_res_plot_y') ? document.getElementById('guest_res_plot_y').value.trim() : '';

  var info = window.__guestPayInfo || {};
  var urlParams = new URLSearchParams(window.location.search);
  var isPayReturn = urlParams.get('guest_pay_return') === '1';

  var savedSt = {};
  try {
    savedSt = JSON.parse(sessionStorage.getItem('guest_reservation_pending') || '{}');
  } catch(eSt) {}

  // If saved booking in sessionStorage is from a different plot, purge it!
  if (savedSt.guest_res_section && (savedSt.guest_res_section.toUpperCase() !== curSec || String(savedSt.guest_res_plot_x) !== String(curPx) || String(savedSt.guest_res_plot_y) !== String(curPy))) {
    try { sessionStorage.removeItem('guest_reservation_pending'); } catch (eR) {}
    savedSt = {};
    window.__guestPayInfo = null;
    window.__guestPaymentNonce = null;
    info = {};
  }

  // Only use retry flow if we are actively returning from payment or explicitly have payment info for THIS plot
  var bookingId = info.bookingId || (isPayReturn ? urlParams.get('booking_id') : '') || (isPayReturn ? savedSt.bookingId : '') || '';
  var payToken = info.payToken || (isPayReturn ? urlParams.get('pay_token') : '') || (isPayReturn ? savedSt.payToken : '') || '';

  if (bookingId && payToken) {
    retryGuestPayment(bookingId, payToken);
    return false;
  }

  // Skip ref/proof requirement: request is created first, payment happens in the gateway.
  window.__guestPayNowDirect = true;
  // Generate a nonce for idempotency (prevents duplicate records on refresh).
  if (!window.__guestPaymentNonce) {
    window.__guestPaymentNonce = 'gpn_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10);
  }
  // No new tab — redirect the current page to the gateway after booking is saved.
  window.__guestPayWin = null;
  if (typeof handleGuestReservationSubmit === 'function') return handleGuestReservationSubmit(e);
  return false;
}

function stopGuestPayPoll() {
  if (window.__guestPayPoll) { try { clearInterval(window.__guestPayPoll); } catch (eP) {} window.__guestPayPoll = null; }
}

// --- Save/Restore guest reservation form state to sessionStorage ---
function saveGuestFormState(extraData) {
  var fields = {};
  var ids = ['guest_res_name', 'guest_res_email', 'guest_res_phone', 'guest_res_address', 'guest_res_remarks',
             'guest_res_preferred_date',
             'guest_res_section', 'guest_res_plot_x', 'guest_res_plot_y', 'guest_res_plot_id', 'guest_res_lawn_type',
             'guest_res_payment_ref', 'guest_res_payment_term'];
  for (var i = 0; i < ids.length; i++) {
    var el = document.getElementById(ids[i]);
    if (el) fields[ids[i]] = el.value;
  }
  var agreeEl = document.getElementById('guest_res_agreement');
  if (agreeEl) fields['guest_res_agreement'] = agreeEl.checked ? 1 : 0;
  if (typeof getGuestSelectedPaymentPlan === 'function') {
    fields['guest_res_payment_plan'] = getGuestSelectedPaymentPlan();
  }

  // Include display values for restoration
  var dispIds = ['guest_res_section_disp', 'guest_res_plot_disp', 'guest_res_plotid_disp', 'guest_res_type_disp', 'guest_res_price_disp', 'guest_res_downpayment_disp', 'guest_res_downpayment_badge', 'guest_res_monthly_disp', 'guest_res_first_due_disp'];
  for (var j = 0; j < dispIds.length; j++) {
    var dEl = document.getElementById(dispIds[j]);
    if (dEl) fields['_disp_' + dispIds[j]] = dEl.textContent;
  }
  fields.__guestDownpayment = window.__guestDownpayment || 0;
  if (extraData) {
    for (var k in extraData) { if (extraData.hasOwnProperty(k)) fields[k] = extraData[k]; }
  }
  try { sessionStorage.setItem('guest_reservation_pending', JSON.stringify(fields)); } catch(e) {}
}

function restoreGuestFormState() {
  try {
    var raw = sessionStorage.getItem('guest_reservation_pending');
    if (!raw) return null;
    var fields = JSON.parse(raw);
    var ids = ['guest_res_name', 'guest_res_email', 'guest_res_phone', 'guest_res_address', 'guest_res_remarks',
               'guest_res_preferred_date',
               'guest_res_section', 'guest_res_plot_x', 'guest_res_plot_y', 'guest_res_plot_id', 'guest_res_lawn_type',
               'guest_res_payment_ref', 'guest_res_payment_term'];
    for (var i = 0; i < ids.length; i++) {
      var el = document.getElementById(ids[i]);
      if (el && fields[ids[i]]) el.value = fields[ids[i]];
    }
    var agreeEl = document.getElementById('guest_res_agreement');
    if (agreeEl && fields['guest_res_agreement']) agreeEl.checked = true;

    if (fields['guest_res_payment_plan'] === 'full') {
      var rFull = document.getElementById('guest_plan_full');
      if (rFull) rFull.checked = true;
    } else {
      var rInst = document.getElementById('guest_plan_installment');
      if (rInst) rInst.checked = true;
    }

    // Restore display values
    var dispIds = ['guest_res_section_disp', 'guest_res_plot_disp', 'guest_res_plotid_disp', 'guest_res_type_disp', 'guest_res_price_disp', 'guest_res_downpayment_disp', 'guest_res_downpayment_badge', 'guest_res_monthly_disp', 'guest_res_first_due_disp'];
    for (var j = 0; j < dispIds.length; j++) {
      var dEl = document.getElementById(dispIds[j]);
      if (dEl && fields['_disp_' + dispIds[j]]) dEl.textContent = fields['_disp_' + dispIds[j]];
    }
    if (fields.__guestDownpayment) window.__guestDownpayment = fields.__guestDownpayment;
    if (typeof updateGuestPaymentPlanUI === 'function') updateGuestPaymentPlanUI();
    return fields;
  } catch(e) { return null; }
}

function clearGuestFormState() {
  try { sessionStorage.removeItem('guest_reservation_pending'); } catch(e) {}
}

// Fill empty requester inputs from server (authoritative copy saved at Pay Now).
function prefillGuestFormFromServer(bookingId, payToken, cb) {
  var done = function() { if (typeof cb === 'function') { try { cb(); } catch (eCb) {} } };
  if (!bookingId || !payToken) { done(); return; }
  try {
    var targetUrl = (window.location.pathname.indexOf('/sections/') !== -1) ? 'popup.php' : 'sections/popup.php';
    var fd = new FormData();
    fd.append('ajax_request', 'guest_booking_status');
    fd.append('booking_id', bookingId);
    fd.append('pay_token', payToken);
    fetch(targetUrl, { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (d && d.success) {
          var map = { full_name: 'guest_res_name', email: 'guest_res_email', contact_number: 'guest_res_phone', address: 'guest_res_address', remarks: 'guest_res_remarks', preferred_date: 'guest_res_preferred_date' };
          Object.keys(map).forEach(function(k) {
            try {
              var el = document.getElementById(map[k]);
              if (el && !el.value && d[k]) el.value = d[k];
            } catch (eF) {}
          });
          var agreeEl = document.getElementById('guest_res_agreement');
          if (agreeEl) agreeEl.checked = true;

          // If plot display was empty, initialize it (only if not returning from completed payment)
          var curSec = document.getElementById('guest_res_section') ? document.getElementById('guest_res_section').value : '';
          var isPayReturnNow = (new URLSearchParams(window.location.search).get('guest_pay_return') === '1');
          if (!isPayReturnNow && !curSec && d.section && d.plot_x && d.plot_y && typeof showGuestReservationModal === 'function') {
            showGuestReservationModal(d.section, d.plot_x, d.plot_y, d.lawn_type);
          }
        }
        done(d);
      })
      .catch(function() { done(null); });
  } catch (eX) { done(null); }
}

function guestPayStatusHtml(kind, data) {
  data = data || {};
  if (kind === 'waiting') {
    return '<div class="alert alert-info py-2.5 px-3 mb-2 small d-flex align-items-center gap-2" style="border-radius: 8px; background-color: #eff6ff; border-left: 4px solid #3b82f6;">' +
      '<div class="spinner-border spinner-border-sm text-primary flex-shrink-0"></div>' +
      '<div style="line-height: 1.35;">' +
        '<strong class="text-dark d-block">Waiting for GCash payment...</strong>' +
        '<span class="text-muted" style="font-size: 0.78rem;">Booking ' + (data.bookingId ? ('#' + data.bookingId) : '') + ' is saved. Complete payment in GCash, or click Pay Now below to reopen.</span>' +
      '</div>' +
    '</div>';
  }
  if (kind === 'paid') {
    var html = '<div class="p-3 rounded border mb-0" style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%); border-color: #86efac !important; border-radius: 12px;">';
    html += '<div class="d-flex align-items-center gap-2 mb-2"><i class="fas fa-check-circle text-success fs-5"></i><strong class="text-success">✓ Downpayment Paid</strong></div>';
    html += '<div class="row g-2 small">';
    html += '<div class="col-6"><span class="text-muted d-block" style="font-size:0.72rem;">Amount Paid</span><strong class="text-dark">₱' + data.paid + '</strong></div>';
    if (data.ref) {
      html += '<div class="col-6"><span class="text-muted d-block" style="font-size:0.72rem;">GCash Reference</span><strong class="text-dark font-monospace">' + data.ref + '</strong></div>';
    }
    if (data.txRef) {
      html += '<div class="col-6"><span class="text-muted d-block" style="font-size:0.72rem;">Transaction ID</span><span class="text-dark font-monospace small">' + data.txRef + '</span></div>';
    }
    if (data.paymentDate) {
      html += '<div class="col-6"><span class="text-muted d-block" style="font-size:0.72rem;">Payment Date</span><span class="text-dark small">' + data.paymentDate + '</span></div>';
    }
    html += '</div>';
    html += '<div class="mt-2 pt-2 border-top small text-muted" style="font-size:0.76rem;"><i class="fas fa-info-circle me-1"></i>You can now upload your payment proof below and submit your reservation request.</div>';
    html += '</div>';
    return html;
  }
  if (kind === 'cancelled') {
    return '<div class="alert alert-warning py-2.5 px-3 mb-2 small d-flex align-items-start gap-2" style="border-radius: 8px; border-left: 4px solid #f59e0b; background-color: #fffbeb;">' +
      '<i class="fas fa-exclamation-triangle text-warning mt-1 flex-shrink-0"></i>' +
      '<div style="line-height: 1.35;">' +
        '<strong class="text-dark d-block">Payment Not Completed</strong>' +
        '<span class="text-muted" style="font-size: 0.8rem;">Your reservation request ' + (data.bookingId ? ('#' + data.bookingId) : '') + ' is saved. Click below to proceed to GCash and complete your 20% downpayment.</span>' +
      '</div>' +
    '</div>';
  }
  if (kind === 'timeout') {
    return '<div class="alert alert-warning py-2.5 px-3 mb-2 small d-flex align-items-start gap-2" style="border-radius: 8px; border-left: 4px solid #f59e0b; background-color: #fffbeb;">' +
      '<i class="fas fa-clock text-warning mt-1 flex-shrink-0"></i>' +
      '<div style="line-height: 1.35;">' +
        '<strong class="text-dark d-block">Payment Detection Timed Out</strong>' +
        '<span class="text-muted" style="font-size: 0.8rem;">If you have already paid, enter your GCash reference number and upload proof below. Otherwise, click Pay Now below to retry.</span>' +
      '</div>' +
    '</div>';
  }
  return '<div class="alert alert-secondary py-2 px-3 mb-2 small" style="border-radius: 8px;">' +
    'Booking #' + data.bookingId + ' is saved.' +
  '</div>';
}

function showGuestPayStatus(kind, data) {
  var el = document.getElementById('guestPayNowStatus');
  if (!el) return;
  el.style.display = '';
  el.innerHTML = guestPayStatusHtml(kind, data);

  var payNowBtn = document.getElementById('guestPayNowBtn');
  var payNowHint = document.getElementById('guestPayNowHint');

  if (kind === 'paid') {
    if (payNowBtn) payNowBtn.style.display = 'none';
    if (payNowHint) payNowHint.style.display = 'none';
  } else {
    if (payNowBtn) {
      payNowBtn.style.display = '';
      payNowBtn.disabled = false;
      if (kind === 'waiting') {
        payNowBtn.innerHTML = '<i class="fas fa-external-link-alt me-2"></i> Reopen GCash Gateway';
      } else {
        var dpAmt = Number(window.__guestDownpayment || 0);
        var dpText = dpAmt > 0 ? (' (20% Downpayment: ₱' + dpAmt.toLocaleString('en-PH', {minimumFractionDigits: 2}) + ')') : '';
        payNowBtn.innerHTML = '<i class="fas fa-wallet me-2"></i> Pay Now — proceed to GCash' + dpText;
      }
    }
    if (payNowHint) payNowHint.style.display = '';
  }
}

function reopenGuestGateway() {
  var info = window.__guestPayInfo || {};
  if (info.url) {
    try { var w = window.open(info.url, '_blank'); if (w) return; } catch (eR) {}
    window.location.href = info.url;
  }
}

function retryGuestPayment(overrideBookingId, overridePayToken) {
  window.__guestPayWaiting = false;
  var info = window.__guestPayInfo || {};
  var urlParams = new URLSearchParams(window.location.search);
  var bookingId = overrideBookingId || info.bookingId || urlParams.get('booking_id') || '';
  var payToken = overridePayToken || info.payToken || urlParams.get('pay_token') || '';

  if (!bookingId || !payToken) {
    try {
      var savedSt = JSON.parse(sessionStorage.getItem('guest_reservation_pending') || '{}');
      if (savedSt.bookingId) bookingId = bookingId || savedSt.bookingId;
      if (savedSt.payToken) payToken = payToken || savedSt.payToken;
    } catch(eSt) {}
  }

  if (bookingId && payToken) {
    var gwBase = (window.location.pathname.indexOf('/sections/') !== -1) ? '../gcash_gateway.php' : 'gcash_gateway.php';
    var dpAmt = Number(window.__guestDownpayment || 0);
    if (!(dpAmt > 0)) {
      var isPremPlot = /premium/i.test(document.getElementById('guest_res_lawn_type') ? document.getElementById('guest_res_lawn_type').value : '');
      dpAmt = isPremPlot ? 17800 : 17000;
    }
    var sec = document.getElementById('guest_res_section') ? document.getElementById('guest_res_section').value : '';
    var px = document.getElementById('guest_res_plot_x') ? document.getElementById('guest_res_plot_x').value : '';
    var py = document.getElementById('guest_res_plot_y') ? document.getElementById('guest_res_plot_y').value : '';
    var popBase = (window.location.pathname.indexOf('/sections/') !== -1) ? 'popup.php' : 'sections/popup.php';
    var retUrl = popBase + '?section=' + encodeURIComponent(sec || 'A') +
                 '&plot_x=' + encodeURIComponent(px || '') +
                 '&plot_y=' + encodeURIComponent(py || '') +
                 '&guest_pay_return=1&booking_id=' + encodeURIComponent(bookingId) +
                 '&pay_token=' + encodeURIComponent(payToken);
    try { retUrl = new URL(retUrl, window.location.href).href; } catch(eU) {}
    var targetGw = gwBase + '?booking_id=' + encodeURIComponent(bookingId) +
                   '&amount=' + encodeURIComponent(dpAmt.toFixed(2)) +
                   '&source=guest&pay_token=' + encodeURIComponent(payToken) +
                   '&return_url=' + encodeURIComponent(retUrl);

    // Hide guest reservation modal so Swal appears clearly
    var guestModalEl = document.getElementById('guestReservationModal');
    if (guestModalEl && typeof bootstrap !== 'undefined') {
      try {
        var bsInst = bootstrap.Modal.getInstance(guestModalEl);
        if (bsInst) bsInst.hide();
      } catch (eM) {}
    }

    if (typeof Swal !== 'undefined') {
      Swal.fire({
        title: 'Redirecting to GCash...',
        html: '<div class="py-3 text-center">' +
                '<div class="mb-3">' +
                  '<div class="spinner-border text-primary" style="width: 3.5rem; height: 3.5rem; border-width: 4px; color: #007dfe !important;" role="status"></div>' +
                '</div>' +
                '<h5 class="fw-bold text-dark mb-1">Reservation #' + bookingId + '</h5>' +
                '<p class="text-muted mb-3" style="font-size: 0.92rem;">Redirecting you to the secure GCash payment gateway (₱' + dpAmt.toLocaleString('en-PH', {minimumFractionDigits: 2}) + ')...</p>' +
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
        window.location.href = targetGw;
      }, 1200);
      return;
    }

    window.location.href = targetGw;
    return;
  }

  // Re-enable Pay Now button and clear stuck state
  var payNowBtn = document.getElementById('guestPayNowBtn');
  if (payNowBtn) {
    payNowBtn.disabled = false;
    payNowBtn.innerHTML = '<i class="fas fa-wallet me-1"></i> Pay Now — proceed to GCash';
  }
  var statusEl = document.getElementById('guestPayNowStatus');
  if (statusEl) statusEl.style.display = 'none';
}

function showGuestPostPaymentUI(data) {
  // Show paid status card
  showGuestPayStatus('paid', data);
  // Hide the Pay Now button
  var payNowBtn = document.getElementById('guestPayNowBtn');
  if (payNowBtn) payNowBtn.style.display = 'none';
  // Auto-fill reference if we have it
  var refInput = document.getElementById('guest_res_payment_ref');
  if (refInput && data.ref) {
    refInput.value = data.ref;
    refInput.readOnly = true;
  }
  // Reveal the proof upload area
  var proofArea = document.getElementById('guestProofUploadArea');
  if (proofArea) proofArea.style.display = 'block';
  // Wire up image preview when file is selected and ensure required attribute
  var proofInput = document.getElementById('guest_res_payment_proof');
  if (proofInput) {
    proofInput.setAttribute('required', 'required');
    proofInput.addEventListener('change', function() {
      var file = this.files && this.files[0];
      var previewWrap = document.getElementById('guestProofPreview');
      var previewImg = document.getElementById('guestProofPreviewImg');
      if (file && file.type.startsWith('image/') && previewWrap && previewImg) {
        var reader = new FileReader();
        reader.onload = function(e) {
          previewImg.src = e.target.result;
          previewWrap.style.display = 'block';
        };
        reader.readAsDataURL(file);
      } else if (previewWrap) {
        previewWrap.style.display = 'none';
      }
    });
  }
  // Ensure submit button is enabled and visible
  var submitBtn = document.getElementById('guestResSubmitBtn');
  if (submitBtn) {
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Submit Payment Proof & Reservation';
  }
  // Mark that payment was completed for submit handler
  window.__guestPaymentCompleted = true;
  window.__guestPaymentData = data;
}

function guestPaidDone() {
  stopGuestPayPoll();
  window.__guestPayWaiting = false;
  clearGuestFormState();
  var mEl = document.getElementById('guestReservationModal');
  try {
    if (mEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
      var m = bootstrap.Modal.getInstance(mEl) || bootstrap.Modal.getOrCreateInstance(mEl);
      if (m) m.hide();
    } else if (mEl) { mEl.style.display = 'none'; }
  } catch (eM) {}
  if (typeof loadReservedPlots === 'function') { try { loadReservedPlots(); } catch (eL) {} }
}

document.addEventListener('hidden.bs.modal', function(e) {
  if (e && e.target && e.target.id === 'guestReservationModal') stopGuestPayPoll();
});

// Cross-tab communication: listen for localStorage writes from the GCash gateway tab
try {
  window.addEventListener('storage', function(evt) {
    if (!evt || !evt.key) return;
    if (evt.key.indexOf('guest_payment_completed_') === 0 && evt.newValue) {
      try {
        var payData = JSON.parse(evt.newValue);
        if (payData && payData.booking_id && window.__guestPayInfo && payData.booking_id == window.__guestPayInfo.bookingId) {
          stopGuestPayPoll();
          if (typeof Swal !== 'undefined') Swal.close();
          showGuestPostPaymentUI({
            bookingId: payData.booking_id,
            paid: Number(payData.amount_paid || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
            ref: payData.reference || '',
            txRef: payData.transaction_reference || '',
            paymentDate: payData.payment_date || ''
          });
        }
      } catch(e) {}
    }
  });

  // Cross-tab message listener: if user closes/cancels GCash gateway
  window.addEventListener('message', function(evt) {
    if (evt && evt.data && evt.data.type === 'gcash_payment_cancelled') {
      stopGuestPayPoll();
      window.__guestPayWaiting = false;
      window.__guestPaymentCompleted = false;
      showGuestPayStatus('cancelled', { bookingId: evt.data.booking_id });
    }
  });

  // If page is restored from cache (e.g. user pressed back button from GCash gateway)
  window.addEventListener('pageshow', function(e) {
    if (e.persisted || (window.performance && window.performance.navigation && window.performance.navigation.type === 2)) {
      if (typeof Swal !== 'undefined' && Swal.isVisible()) {
        Swal.close();
      }
      window.__guestPayWaiting = false;
    }
  });
} catch(e) {}

function startGuestPayPoll(bookingId, payToken) {
  stopGuestPayPoll();
  var tries = 0;
  var targetUrl = (window.location.pathname.indexOf('/sections/') !== -1) ? 'popup.php' : 'sections/popup.php';
  window.__guestPayPoll = setInterval(function() {
    tries++;
    if (tries > 60) {
      stopGuestPayPoll();
      window.__guestPayWaiting = false;
      showGuestPayStatus('timeout', { bookingId: bookingId });
      // Re-enable Pay Now so they can retry or upload proof manually
      var payNowBtn = document.getElementById('guestPayNowBtn');
      if (payNowBtn) {
        payNowBtn.style.display = '';
        payNowBtn.disabled = false;
        payNowBtn.innerHTML = '<i class="fas fa-wallet me-1"></i> Pay Now — proceed to GCash';
      }
      // Make ref/proof fields usable for manual submission
      var refInput = document.getElementById('guest_res_payment_ref');
      if (refInput) refInput.removeAttribute('readonly');
      return;
    }
    var fd = new FormData();
    fd.append('ajax_request', 'guest_booking_status');
    fd.append('booking_id', bookingId);
    fd.append('pay_token', payToken || '');
    fetch(targetUrl, { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (d && d.success && d.paid) {
          stopGuestPayPoll();
          if (typeof Swal !== 'undefined') Swal.close();
          showGuestPostPaymentUI({
            bookingId: bookingId,
            paid: Number(d.amount_paid || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
            ref: d.reference || '',
            txRef: d.transaction_reference || '',
            paymentDate: d.payment_date || ''
          });
        }
      })
      .catch(function() {});
  }, 5000);
}

function handleGuestReservationSubmit(e) {
  if (e) e.preventDefault();
  var form = document.getElementById('guestReservationForm');
  if (!form) return;

  // Pay Now uses its own button/processing state so the footer
  // Submit button is never touched when Pay Now is clicked.
  var btn = null;
  var origHtml = '';

  // 1. Full Name
  var nameEl = document.getElementById('guest_res_name');
  var nameVal = nameEl ? nameEl.value.trim() : '';
  if (!nameVal) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({ icon: 'warning', title: 'Full Name Required', text: 'Please enter your full name before proceeding.', confirmButtonColor: '#7b1113' }).then(function() { if (nameEl) nameEl.focus(); });
    } else {
      alert('Please enter your full name.');
      if (nameEl) nameEl.focus();
    }
    return false;
  }

  // 2. Email Address
  var emailEl = document.getElementById('guest_res_email');
  var emailVal = emailEl ? emailEl.value.trim() : '';
  if (!emailVal || emailVal.indexOf('@') === -1 || emailVal.indexOf('.') === -1) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({ icon: 'warning', title: 'Valid Email Required', text: 'Please enter a valid email address (e.g. name@example.com).', confirmButtonColor: '#7b1113' }).then(function() { if (emailEl) emailEl.focus(); });
    } else {
      alert('Please enter a valid email address.');
      if (emailEl) emailEl.focus();
    }
    return false;
  }

  // 3. Contact Number
  var phoneEl = document.getElementById('guest_res_phone');
  var phone = (phoneEl ? phoneEl.value : '').replace(/[^0-9]/g, '');
  if (!/^09[0-9]{9}$/.test(phone)) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({ icon: 'warning', title: 'Invalid Contact Number', text: 'Contact number must be an 11-digit mobile number starting with 09 (e.g. 09XXXXXXXXX).', confirmButtonColor: '#7b1113' }).then(function() { if (phoneEl) phoneEl.focus(); });
    } else {
      alert('Contact number must be an 11-digit mobile number starting with 09.');
      if (phoneEl) phoneEl.focus();
    }
    return false;
  }

  // 4. Residential Address
  var addrEl = document.getElementById('guest_res_address');
  var addrVal = addrEl ? addrEl.value.trim() : '';
  if (!addrVal) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({ icon: 'warning', title: 'Address Required', text: 'Please enter your residential address.', confirmButtonColor: '#7b1113' }).then(function() { if (addrEl) addrEl.focus(); });
    } else {
      alert('Please enter your residential address.');
      if (addrEl) addrEl.focus();
    }
    return false;
  }

  // 5. Target Plot Selection
  var secVal = document.getElementById('guest_res_section') ? document.getElementById('guest_res_section').value.trim() : '';
  var pxVal = document.getElementById('guest_res_plot_x') ? document.getElementById('guest_res_plot_x').value.trim() : '';
  var pyVal = document.getElementById('guest_res_plot_y') ? document.getElementById('guest_res_plot_y').value.trim() : '';
  if (!secVal || !pxVal || !pyVal) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({ icon: 'error', title: 'No Plot Selected', text: 'Please select an available plot from the cemetery map first.', confirmButtonColor: '#7b1113' });
    } else {
      alert('Please select an available plot from the cemetery map first.');
    }
    return false;
  }

  // 6. Terms & Conditions Agreement
  var agreeEl = document.getElementById('guest_res_agreement');
  if (agreeEl && !agreeEl.checked) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({ icon: 'warning', title: 'Agreement Required', text: 'Please read and agree to the Terms & Conditions and No Refund Policy before proceeding.', confirmButtonColor: '#7b1113' }).then(function() { if (agreeEl) agreeEl.focus(); });
    } else {
      alert('Please read and agree to the Terms & Conditions and No Refund Policy before proceeding.');
      if (agreeEl) agreeEl.focus();
    }
    return false;
  }

  var payRef = document.getElementById('guest_res_payment_ref') ? document.getElementById('guest_res_payment_ref').value.trim() : '';
  // Pay Now: skip ref/proof requirement — request is created first, payment happens in the gateway.
  var payNowDirect = (window.__guestPayNowDirect === true);
  window.__guestPayNowDirect = false;
  // Also skip if payment was already completed via gateway return
  var paymentAlreadyDone = (window.__guestPaymentCompleted === true);
  if (!payNowDirect && !paymentAlreadyDone && !payRef) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({ 
        icon: 'info', 
        title: 'Downpayment Required', 
        text: 'Please click "Pay Now — proceed to GCash" above to complete your 20% downpayment.', 
        confirmButtonColor: '#7b1113',
        confirmButtonText: 'Proceed to Pay Now'
      }).then(function() {
        var pBtn = document.getElementById('guestPayNowBtn');
        if (pBtn) pBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
    } else {
      alert('Please click "Pay Now — proceed to GCash" above to complete your 20% downpayment.');
    }
    return;
  }

  // Payment proof file is strictly required before the booking can proceed to verification
  if (!payNowDirect) {
    var proofInputEl = document.getElementById('guest_res_payment_proof');
    if (!proofInputEl || !proofInputEl.files || !proofInputEl.files.length) {
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          icon: 'warning',
          title: 'Payment Proof Required',
          text: 'Please upload your GCash payment screenshot or receipt before submitting for verification.',
          confirmButtonColor: '#7b1113'
        });
      } else {
        alert('Please upload your GCash payment screenshot or receipt before submitting for verification.');
      }
      return;
    }
  }

  btn = payNowDirect
    ? document.getElementById('guestPayNowBtn')
    : document.getElementById('guestResSubmitBtn');
  origHtml = btn ? btn.innerHTML : '';

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = payNowDirect ? '<span class="spinner-border spinner-border-sm me-1"></span> Processing...' : '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';
  }

  var formData = new FormData(form);
  formData.append('ajax_request', 'submit_guest_reservation');
  // Add payment nonce for idempotency
  if (window.__guestPaymentNonce) {
    formData.append('payment_nonce', window.__guestPaymentNonce);
  }

  // Capture values before form reset
  var guestName = (document.getElementById('guest_res_name') ? document.getElementById('guest_res_name').value : '');
  var guestEmail = (document.getElementById('guest_res_email') ? document.getElementById('guest_res_email').value : '');
  var enteredPrefDate = (document.getElementById('guest_res_preferred_date') ? document.getElementById('guest_res_preferred_date').value : '');

  // Robust target URL resolution:
  var targetUrl = (window.location.pathname.indexOf('/sections/') !== -1) ? 'popup.php' : 'sections/popup.php';

  // Show the processing Swal FIRST (guarantees spinner is visible), then start the fetch
  // inside didOpen so the modal is fully rendered before any network call begins.
  function runGuestReservationFetch() {
    fetch(targetUrl, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    })
    .then(function(res) {
      return res.json().catch(function() {
        throw new Error('Server returned invalid response');
      });
    })
    .then(function(data) {
      if (data && data.success) {
        if (payNowDirect) {
          // Pay Now: redirect the SAME tab to the GCash gateway.
          // On return, the guest_pay_return handler re-opens the modal with state restored.
          if (!data.booking_id) {
            if (typeof Swal !== 'undefined') {
              Swal.fire({ icon: 'error', title: 'Redirect Failed', text: 'Booking was created but no booking ID was returned. Please contact support.', confirmButtonColor: '#7b1113' });
            } else {
              alert('Booking was created but no booking ID was returned.');
            }
            if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
            return;
          }
          var dpAmt = Number(data.downpayment || window.__guestDownpayment || 0);
          if (!(dpAmt > 0)) {
            var isPremPlot = /premium/i.test(document.getElementById('guest_res_lawn_type') ? document.getElementById('guest_res_lawn_type').value : '') ||
                             /premium/i.test(data.lawn_type || '');
            dpAmt = isPremPlot ? 17800 : 17000;
          }

          // Build return URL so the gateway redirects back to the same map/popup page
          var popBase = (window.location.pathname.indexOf('/sections/') !== -1) ? 'popup.php' : 'sections/popup.php';
          var section = document.getElementById('guest_res_section') ? document.getElementById('guest_res_section').value : '';
          var plotX = document.getElementById('guest_res_plot_x') ? document.getElementById('guest_res_plot_x').value : '';
          var plotY = document.getElementById('guest_res_plot_y') ? document.getElementById('guest_res_plot_y').value : '';
          var returnUrl = popBase + '?section=' + encodeURIComponent(section || 'A') +
                          '&plot_x=' + encodeURIComponent(plotX || '') +
                          '&plot_y=' + encodeURIComponent(plotY || '') +
                          '&guest_pay_return=1&booking_id=' + encodeURIComponent(data.booking_id) +
                          '&pay_token=' + encodeURIComponent(data.pay_token || '');
          try { returnUrl = new URL(returnUrl, window.location.href).href; } catch(eU) {}

          var gwBase = (window.location.pathname.indexOf('/sections/') !== -1) ? '../gcash_gateway.php' : 'gcash_gateway.php';
          var gwUrl = gwBase + '?booking_id=' + encodeURIComponent(data.booking_id || '') + '&amount=' + encodeURIComponent(dpAmt.toFixed(2)) + '&source=guest';
          if (data.pay_token) gwUrl += '&pay_token=' + encodeURIComponent(data.pay_token);
          gwUrl += '&return_url=' + encodeURIComponent(returnUrl);
          var gwAbs = gwUrl;
          try { gwAbs = new URL(gwUrl, window.location.href).href; } catch (eAbs) {}

          // Save form state to sessionStorage so the return handler can restore it
          saveGuestFormState({
            bookingId: data.booking_id,
            payToken: data.pay_token || '',
            gatewayUrl: gwAbs,
            nonce: window.__guestPaymentNonce || ''
          });

          // Show "Redirecting to GCash..." modal
          if (typeof Swal !== 'undefined') {
            Swal.fire({
              title: 'Redirecting to GCash...',
              html: '<div class="py-3 text-center">' +
                      '<div class="mb-3">' +
                        '<div class="spinner-border text-primary" style="width: 3.5rem; height: 3.5rem; border-width: 4px; color: #007dfe !important;" role="status"></div>' +
                      '</div>' +
                      '<h5 class="fw-bold text-dark mb-1">Reservation #' + data.booking_id + ' Created!</h5>' +
                      '<p class="text-muted mb-3" style="font-size: 0.92rem;">Redirecting you to the secure GCash payment gateway (' + (dpAmt > 0 ? ('₱' + dpAmt.toLocaleString('en-PH', {minimumFractionDigits: 2})) : '20% Downpayment') + ')...</p>' +
                      '<div class="progress" style="height: 6px; border-radius: 4px; overflow: hidden; background-color: #e9ecef;">' +
                        '<div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%; background-color: #007dfe;"></div>' +
                      '</div>' +
                    '</div>',
              allowOutsideClick: false,
              allowEscapeKey: false,
              showConfirmButton: false,
              timer: 1600
            });
            setTimeout(function() { window.location.href = gwAbs; }, 1300);
          } else {
            window.location.href = gwAbs;
          }
          return;
        }

        // Normal submit (with proof uploaded) — show success modal
        window.__guestPayWaiting = false;
        clearGuestFormState();
        window.__guestPaymentCompleted = false;
        window.__guestPaymentNonce = null;

        var guestModalEl = document.getElementById('guestReservationModal');
        if (guestModalEl) {
          var m = bootstrap.Modal.getInstance(guestModalEl);
          if (m) m.hide();
        }
        form.reset();

        var succIdEl = document.getElementById('succ_booking_id');
        if (succIdEl) succIdEl.textContent = '#' + (data.booking_id || '');
        var succPlotEl = document.getElementById('succ_plot_disp');
        if (succPlotEl) succPlotEl.textContent = 'Section ' + (data.section || '') + ' \u00b7 Plot ' + (data.plot || '');
        var succNameEl = document.getElementById('succ_name_disp');
        if (succNameEl) succNameEl.textContent = guestName || '\u2014';
        var succEmailEl = document.getElementById('succ_email_disp');
        if (succEmailEl) succEmailEl.textContent = guestEmail || '\u2014';
        var rawSubDate = data.submission_date || data.submitted_at;
        var subDateDisplay = '—';
        if (rawSubDate && String(rawSubDate).trim() !== '—' && String(rawSubDate).trim() !== '-') {
          if (String(rawSubDate).indexOf('-') !== -1 && String(rawSubDate).length <= 19) {
            try {
              subDateDisplay = new Date(String(rawSubDate).replace(' ', 'T')).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
            } catch(eSub) { subDateDisplay = rawSubDate; }
          } else {
            subDateDisplay = rawSubDate;
          }
        }
        if (!subDateDisplay || subDateDisplay === '—' || subDateDisplay === 'Invalid Date') {
          subDateDisplay = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
        }
        var succDateEl = document.getElementById('succ_date_disp');
        if (succDateEl) succDateEl.textContent = subDateDisplay;

        var rawPrefDate = data.preferred_date_display || data.preferred_date || enteredPrefDate;
        var prefDateDisplay = '—';
        if (rawPrefDate && String(rawPrefDate).trim() !== '—' && String(rawPrefDate).trim() !== '-') {
          if (String(rawPrefDate).indexOf('-') !== -1 && String(rawPrefDate).length <= 10) {
            try {
              prefDateDisplay = new Date(String(rawPrefDate) + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
            } catch(ePref) { prefDateDisplay = rawPrefDate; }
          } else {
            prefDateDisplay = rawPrefDate;
          }
        }
        if (!prefDateDisplay || prefDateDisplay === '—' || prefDateDisplay === 'Invalid Date') {
          prefDateDisplay = subDateDisplay;
        }
        var succPrefDateEl = document.getElementById('succ_pref_date_disp');
        if (succPrefDateEl) succPrefDateEl.textContent = prefDateDisplay;

        var succModalEl = document.getElementById('guestReservationSuccessModal');
        if (succModalEl) {
          var succModal = bootstrap.Modal.getOrCreateInstance(succModalEl);
          succModal.show();
        } else if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'success',
            title: 'Reservation Request Submitted!',
            text: data.message || 'Your reservation request is now Pending Admin Review. Once approved, you will receive an account creation link via email.',
            confirmButtonColor: '#7b1113'
          });
        } else {
          alert(data.message || 'Your reservation request has been submitted successfully!');
        }

        if (typeof loadReservedPlots === 'function') {
          loadReservedPlots();
        }
      } else {
        var errMsg = (data && data.message) ? data.message : 'Failed to submit reservation request.';
        var guestModalEl = document.getElementById('guestReservationModal');
        if (guestModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
          bootstrap.Modal.getOrCreateInstance(guestModalEl).show();
        }
        var pBtnErr = document.getElementById('guestPayNowBtn');
        if (pBtnErr && !window.__guestPaymentCompleted) {
          pBtnErr.disabled = false;
          pBtnErr.innerHTML = '<i class="fas fa-wallet me-2"></i> Pay Now — proceed to GCash';
        }
        if (data && data.is_registered) {
          if (typeof Swal !== 'undefined') {
            Swal.fire({
              icon: 'info',
              title: 'Account Already Exists',
              text: errMsg,
              confirmButtonText: '<i class="fas fa-sign-in-alt me-1"></i> Sign In to Account',
              confirmButtonColor: '#7b1113',
              showCancelButton: true,
              cancelButtonText: 'Stay on Form',
              cancelButtonColor: '#6c757d'
            }).then(function(result) {
              if (result.isConfirmed) {
                window.location.href = (window.location.pathname.indexOf('/sections/') !== -1) ? '../login.php' : 'login.php';
              }
            });
          } else {
            alert(errMsg);
          }
        } else if (typeof Swal !== 'undefined') {
          Swal.fire({ icon: 'error', title: 'Submission Error', text: errMsg, confirmButtonColor: '#7b1113' });
        } else {
          alert('Error: ' + errMsg);
        }
      }
    })
    .catch(function(err) {
      console.error('Guest reservation error:', err);
      var guestModalEl = document.getElementById('guestReservationModal');
      if (guestModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(guestModalEl).show();
      }
      var pBtnCatch = document.getElementById('guestPayNowBtn');
      if (pBtnCatch && !window.__guestPaymentCompleted) {
        pBtnCatch.disabled = false;
        pBtnCatch.innerHTML = '<i class="fas fa-wallet me-2"></i> Pay Now — proceed to GCash';
      }
      if (typeof Swal !== 'undefined') {
        Swal.fire({ icon: 'error', title: 'Submission Failed', text: 'A network or server error occurred while processing your reservation. Please try again.', confirmButtonColor: '#7b1113' });
      } else {
        alert('A network or server error occurred while processing your reservation. Please try again.');
      }
    })
    .finally(function() {
      if (window.__guestPayWaiting) return;
      if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
      var pBtnFin = document.getElementById('guestPayNowBtn');
      if (pBtnFin && !window.__guestPaymentCompleted) {
        pBtnFin.disabled = false;
        pBtnFin.innerHTML = '<i class="fas fa-wallet me-2"></i> Pay Now — proceed to GCash';
      }
    });
  }

  if (payNowDirect && typeof Swal !== 'undefined') {
    // Hide the reservation form modal first so the Swal spinner
    // appears cleanly without fighting Bootstrap's z-index stacking.
    var guestModalElForPay = document.getElementById('guestReservationModal');
    if (guestModalElForPay && typeof bootstrap !== 'undefined') {
      try {
        var bsModalInst = bootstrap.Modal.getInstance(guestModalElForPay);
        if (bsModalInst) bsModalInst.hide();
      } catch (eM) {}
    }
    // Small delay so Bootstrap finishes its hide transition before Swal renders
    setTimeout(function() {
      Swal.fire({
        title: 'Redirecting to GCash...',
        html: '<div class="py-3 text-center">' +
                '<div class="mb-3">' +
                  '<div class="spinner-border text-primary" style="width: 3.5rem; height: 3.5rem; border-width: 4px; color: #007dfe !important;" role="status"></div>' +
                '</div>' +
                '<h5 class="fw-bold text-dark mb-1">Please wait a moment</h5>' +
                '<p class="text-muted mb-3" style="font-size: 0.92rem;">Securing your plot and preparing GCash checkout...</p>' +
                '<div class="progress" style="height: 6px; border-radius: 4px; overflow: hidden; background-color: #e9ecef;">' +
                  '<div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%; background-color: #007dfe;"></div>' +
                '</div>' +
              '</div>',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        showCancelButton: false,
        didOpen: function() {
          runGuestReservationFetch();
        }
      });
    }, 150);

  } else {
    runGuestReservationFetch();
  }

}

// --- Guest Payment Return Detection ---
// On page load, check if we are returning from GCash gateway with payment completed.
document.addEventListener('DOMContentLoaded', function() {
  try {
    var urlParams = new URLSearchParams(window.location.search);
    var isPayReturn = urlParams.get('guest_pay_return');
    var returnBookingId = urlParams.get('booking_id');
    if (isPayReturn !== '1' || !returnBookingId) return;
    var paymentStatus = urlParams.get('payment_status');
    var paymentRef = urlParams.get('payment_ref');
    var paymentAmount = urlParams.get('payment_amount');

    // First, restore all saved form fields from sessionStorage
    var restored = restoreGuestFormState();

    var savedState = null;
    try {
      var rawSaved = sessionStorage.getItem('guest_reservation_pending');
      if (rawSaved) savedState = JSON.parse(rawSaved);
    } catch (eS) { savedState = null; }
    var urlToken = urlParams.get('pay_token') || '';
    if (savedState) {
      if (savedState.nonce) window.__guestPaymentNonce = savedState.nonce;
      if (savedState.payToken || urlToken) {
        window.__guestPayInfo = { bookingId: returnBookingId, payToken: (savedState && savedState.payToken) || urlToken, url: (savedState && savedState.gatewayUrl) || '' };
      }
    } else if (urlToken) {
      window.__guestPayInfo = { bookingId: returnBookingId, payToken: urlToken, url: '' };
    }
    var rSec = urlParams.get('section') || (restored && restored.guest_res_section) || '';
    var rPx = urlParams.get('plot_x') || (restored && restored.guest_res_plot_x) || '';
    var rPy = urlParams.get('plot_y') || (restored && restored.guest_res_plot_y) || '';
    var payTokenForCheck = (window.__guestPayInfo && window.__guestPayInfo.payToken) || urlToken;
    var showForStatus = function(sData) {
      var serverPayStatus = (sData && sData.payment_status) ? String(sData.payment_status).toLowerCase() : '';
      var serverPaid = (sData && sData.paid) ? true : false;

      // Handle cancellation
      if (paymentStatus === 'cancelled' || serverPayStatus === 'cancelled') {
        window.__guestPayWaiting = false;
        window.__guestPaymentCompleted = false;
        if (rSec && rPx && rPy && typeof showGuestReservationModal === 'function') {
          showGuestReservationModal(rSec, rPx, rPy);
        } else {
          var guestRetryModalEl = document.getElementById('guestReservationModal');
          if (guestRetryModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(guestRetryModalEl).show();
          }
        }
        setTimeout(function() {
          showGuestPayStatus('cancelled', { bookingId: returnBookingId });
          if (typeof Swal !== 'undefined' && Swal.isVisible()) {
            Swal.close();
          }
        }, 400);
        return;
      }

      // GCash payment returned — DO NOT mark booking as successful yet!
      // Required flow: show payment/proof submission section, user uploads receipt, clicks SUBMIT
      window.__guestPayWaiting = false;
      window.__guestPaymentCompleted = true;

      // Re-open reservation modal with form state restored
      if (rSec && rPx && rPy && typeof showGuestReservationModal === 'function') {
        showGuestReservationModal(rSec, rPx, rPy);
      } else {
        var gModalEl = document.getElementById('guestReservationModal');
        if (gModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
          bootstrap.Modal.getOrCreateInstance(gModalEl).show();
        }
      }

      // Populate GCash details and reveal proof upload section (required)
      setTimeout(function() {
        showGuestPostPaymentUI({
          bookingId: returnBookingId,
          paid: paymentAmount ? Number(paymentAmount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '',
          ref: paymentRef || (sData && sData.gcash_reference) || '',
          txRef: urlParams.get('transaction_ref') || '',
          paymentDate: urlParams.get('payment_date') || ''
        });

        if (typeof Swal !== 'undefined' && Swal.isVisible()) {
          Swal.close();
        }
      }, 350);
      return;
    };


    setTimeout(function() {
      // Do NOT open the reservation modal here — wait for the server response.
      // showForStatus (called inside the callback) decides what to show based on
      // the URL param + server-confirmed payment status.
      prefillGuestFormFromServer(returnBookingId, payTokenForCheck, showForStatus);
    }, 250);
    try {
      var cleanUrl = new URL(window.location.href);
      cleanUrl.searchParams.delete('guest_pay_return');
      cleanUrl.searchParams.delete('payment_status');
      cleanUrl.searchParams.delete('payment_ref');
      cleanUrl.searchParams.delete('payment_amount');
      cleanUrl.searchParams.delete('transaction_ref');
      window.history.replaceState({}, '', cleanUrl.pathname + cleanUrl.search);
    } catch (eH) {}
  } catch (e) { try { console.error('Guest pay return check error:', e); } catch (e2) {} }
});

function checkGuestEmailAvailability(email) {
  var emailClean = (email || '').trim().toLowerCase();
  var msgEl = document.getElementById('guest_email_status_msg');
  var emailInput = document.getElementById('guest_res_email');
  if (!emailClean || emailClean.indexOf('@') === -1 || emailClean.indexOf('.') === -1) {
    if (msgEl) {
      msgEl.className = 'form-text text-muted';
      msgEl.textContent = 'Approval link will be emailed here.';
    }
    if (emailInput) emailInput.classList.remove('is-invalid');
    return;
  }

  var targetUrl = (window.location.pathname.indexOf('/sections/') !== -1) ? 'popup.php' : 'sections/popup.php';
  var fd = new FormData();
  fd.append('ajax_request', 'check_guest_email');
  fd.append('email', emailClean);

  fetch(targetUrl, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: fd
  })
  .then(function(res) { return res.json(); })
  .then(function(data) {
    if (data && data.registered) {
      if (emailInput) emailInput.classList.add('is-invalid');
      if (msgEl) {
        msgEl.className = 'form-text text-danger fw-semibold';
        msgEl.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i> ' + (data.message || 'This email is already registered. Please sign in to continue.');
      }
    } else {
      if (emailInput) emailInput.classList.remove('is-invalid');
      if (msgEl) {
        msgEl.className = 'form-text text-muted';
        msgEl.textContent = 'Approval link will be emailed here.';
      }
    }
  })
  .catch(function() {});
}

// Payment helper functions for Add Deceased Modal
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

function toggleRecordType() {
  var isPurchaseOnly = document.getElementById('type_purchase_only') && document.getElementById('type_purchase_only').checked;
  var cardPersonal = document.getElementById('deceasedPersonalInfoCard');
  var cardDemise = document.getElementById('deceasedDemiseCard');
  var noticeText = document.getElementById('recordTypeNoticeText');
  var wrapPurchase = document.getElementById('wrap_type_purchase_only');
  var wrapDeceased = document.getElementById('wrap_type_with_deceased');
  var submitBtn = document.getElementById('addDecSubmitBtn') || document.querySelector('#addDeceasedModal .btn-primary');
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

function showAddDeceasedModal(section, plotX, plotY, isAlreadyPurchased) {
  // Check if user is admin (normalize server-side and perform case-insensitive client check)
  var isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
  var userRole = '<?php echo isset($_SESSION['role']) ? strtoupper(trim($_SESSION['role'])) : 'USER'; ?>';
  if (!isAdmin || typeof userRole !== 'string' || userRole.indexOf('ADMIN') === -1) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        icon: 'error',
        title: 'Access Restricted',
        text: 'Only administrators can add deceased records.',
        confirmButtonColor: '#7b1113'
      });
    } else {
      alert('Only administrators can add deceased records.');
    }
    return;
  }

  // Close the current modal first
  const currentModal = bootstrap.Modal.getInstance(document.getElementById('deceasedInfoModal'));
  if (currentModal) {
    currentModal.hide();
  }

  // Set the plot information in hidden fields
  document.getElementById('plot_section').value = section;
  document.getElementById('plot_x').value = plotX;
  document.getElementById('plot_y').value = plotY;

  var secBadge = document.getElementById('addDecSectionBadge');
  if (secBadge) secBadge.textContent = 'Section ' + section;
  var plotDisp = document.getElementById('addDecPlotDisplay');
  if (plotDisp) plotDisp.textContent = 'Plot Coordinates: Column ' + plotX + ', Row ' + plotY;

  // Clear the form
  document.getElementById('addDeceasedForm').reset();

  // Restore plot hidden values after form reset
  document.getElementById('plot_section').value = section;
  document.getElementById('plot_x').value = plotX;
  document.getElementById('plot_y').value = plotY;
  if (document.getElementById('contact_no')) {
    document.getElementById('contact_no').value = '09';
  }

  // Prepopulate Booker & Payment details
  var pay = window.__currentPayment || {};
  var res = window.__currentReservation || {};
  var defBooker = pay.booker_name || window.__currentOwnerName || res.owner_name || '';
  if (defBooker === 'Not Specified') defBooker = '';
  var defContact = pay.contact_number || window.__currentOwnerPhone || res.phone_no || '';
  if (defContact) {
    var dc = defContact.replace(/\D/g, '');
    if (dc.startsWith('639')) dc = '09' + dc.substring(3);
    else if (dc.startsWith('9')) dc = '09' + dc.substring(1);
    else if (!dc.startsWith('09')) dc = '09' + dc;
    defContact = dc.slice(0, 11);
  }

  var bInput = document.getElementById('add_booker_name');
  if (bInput) bInput.value = defBooker.toUpperCase();

  var eInput = document.getElementById('add_buyer_email');
  var defEmail = pay.customer_email || pay.email || (res && (res.customer_email || res.email)) || '';
  if (eInput) eInput.value = defEmail;

  var cInput = document.getElementById('add_buyer_contact');
  if (cInput) cInput.value = defContact;

  var isCorner = ((plotX == 1 || plotX == 6 || plotX == 4) && (plotY == 1 || plotY == 37 || plotY == 28));
  var defTotal = (pay.total_amount && Number(pay.total_amount) > 0) ? Number(pay.total_amount) : ((window.__currentPlotData && window.__currentPlotData.lot_price) ? Number(window.__currentPlotData.lot_price) : (isCorner ? 89000 : 85000));
  var defPaid = (pay.amount_paid !== undefined && pay.amount_paid !== null && pay.amount_paid !== '') ? Number(pay.amount_paid) : defTotal;

  var totInput = document.getElementById('add_total_amount');
  if (totInput) totInput.value = defTotal.toFixed(2);

  var paidInput = document.getElementById('add_amount_paid');
  if (paidInput) paidInput.value = defPaid.toFixed(2);

  var plan = pay.payment_plan || 'Cash';
  if (plan === 'Installment') {
    var instRadio = document.getElementById('plan_installment');
    if (instRadio) instRadio.checked = true;
  } else {
    var cashRadio = document.getElementById('plan_cash');
    if (cashRadio) cashRadio.checked = true;
  }

  var statusSel = document.getElementById('add_payment_status');
  if (statusSel) statusSel.value = pay.payment_status || (defPaid >= defTotal ? 'PAID' : 'HAS DUES');

  var termSel = document.getElementById('add_payment_term');
  if (termSel) termSel.value = String(pay.payment_term || '12');

  var isPlotReserved = Boolean(isAlreadyPurchased) || (window.__currentPlotData && (window.__currentPlotData.status === 'RESERVED' || window.__currentPlotData.status === 'PURCHASED')) || Boolean(window.__currentOwnerName && window.__currentOwnerName !== 'Not Specified');

  var wfCard = document.getElementById('workflowTypeCard');
  var payCard = document.getElementById('addPaymentCard');
  var resNotice = document.getElementById('reservedPlotNoticeCard');
  var modalTitle = document.getElementById('addDeceasedModalLabel');

  if (isPlotReserved) {
    // Clean form for reserved plot: hide workflow type selection and payment fields
    if (wfCard) wfCard.style.display = 'none';
    if (payCard) payCard.style.display = 'none';
    if (resNotice) {
      resNotice.style.display = 'block';
      var ownerDisp = document.getElementById('reservedPlotOwnerDisplay');
      if (ownerDisp) ownerDisp.textContent = defBooker || (window.__currentOwnerName || 'Reserved Plot Owner');
    }
    var decRadio = document.getElementById('type_with_deceased');
    if (decRadio) decRadio.checked = true;
    if (modalTitle) modalTitle.textContent = 'Add Deceased to Reserved Plot';

    var pCard = document.getElementById('deceasedPersonalInfoCard');
    if (pCard) pCard.style.display = 'block';
    var dCard = document.getElementById('deceasedDemiseCard');
    if (dCard) dCard.style.display = 'block';

    if (bInput) bInput.removeAttribute('required');
    if (eInput) eInput.removeAttribute('required');
    if (totInput) totInput.removeAttribute('required');
    if (paidInput) paidInput.removeAttribute('required');

    var deceasedReqInputs = ['first_name', 'last_name', 'bday', 'born_in', 'date_died', 'contact_no', 'funeral_service'];
    deceasedReqInputs.forEach(function(id) {
      var el = document.getElementById(id);
      if (el) el.setAttribute('required', 'required');
    });

    var submitBtn = document.getElementById('addDecSubmitBtn');
    if (submitBtn) {
      submitBtn.innerHTML = '<i class="fas fa-check-circle me-2"></i> Submit Deceased Record';
    }
  } else {
    // Normal form for available plots with no owner
    if (wfCard) wfCard.style.display = 'block';
    if (payCard) payCard.style.display = 'block';
    if (resNotice) resNotice.style.display = 'none';

    var purRadio = document.getElementById('type_purchase_only');
    if (purRadio) purRadio.checked = true;
    if (modalTitle) modalTitle.textContent = 'Add Deceased Record / Plot Purchase';

    if (bInput) bInput.setAttribute('required', 'required');
    if (eInput) eInput.setAttribute('required', 'required');
    if (totInput) totInput.setAttribute('required', 'required');
    if (paidInput) paidInput.setAttribute('required', 'required');

    toggleRecordType();
    toggleAddDeceasedPaymentPlan();
    recalculateAddDeceasedPayment();
  }

  // Show the add deceased modal
  const addModal = new bootstrap.Modal(document.getElementById('addDeceasedModal'));
  addModal.show();
}

function submitAddDeceased() {
  const form = document.getElementById('addDeceasedForm');
  const isReservedForm = (document.getElementById('workflowTypeCard') && document.getElementById('workflowTypeCard').style.display === 'none');
  const isPurchaseOnly = !isReservedForm && document.getElementById('type_purchase_only') && document.getElementById('type_purchase_only').checked;

  if (isPurchaseOnly) {
    const booker = (document.getElementById('add_booker_name').value || '').trim();
    if (!booker) {
      alert('Please enter the Client / Buyer Full Name.');
      document.getElementById('add_booker_name').focus();
      return;
    }
    const email = (document.getElementById('add_buyer_email') ? document.getElementById('add_buyer_email').value : '').trim();
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
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
  } else {
    // Validate form before submission
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    // Additional client-side validation for deceased
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
  if (!isReservedForm && buyerContactVal && !/^09\d{9}$/.test(buyerContactVal)) {
    alert('Buyer Contact Number must be an 11-digit mobile number starting with 09 (e.g. 09XXXXXXXXX).');
    if (document.getElementById('add_buyer_contact')) document.getElementById('add_buyer_contact').focus();
    return;
  }

  const decTermsCb = document.getElementById('add_dec_terms_agreement');
  if (decTermsCb && !decTermsCb.checked) {
    alert('Please confirm and check the Cemetery Terms & Agreements acknowledgement before saving.');
    decTermsCb.focus();
    return;
  }

  const formData = new FormData(form);
  formData.append('ajax_request', 'add_deceased');

  // Show loading state
  const submitBtn = document.getElementById('addDecSubmitBtn') || document.querySelector('#addDeceasedModal .btn-primary');
  const originalText = submitBtn ? submitBtn.innerHTML : 'Submit Record';
  if (submitBtn) {
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...';
    submitBtn.disabled = true;
  }

  // Submit the form via AJAX
  fetch(popupPath, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin',
    body: formData
  })
  .then(response => {
    return response.text();
  })
  .then(text => {
    try {
      const data = JSON.parse(text);
      if (data.success) {
        const addModal = bootstrap.Modal.getInstance(document.getElementById('addDeceasedModal'));
        if (addModal) {
          addModal.hide();
        }

        var successMsg = isPurchaseOnly ? 'Plot purchase recorded successfully!' : 'Deceased person added successfully!';
        alert(successMsg);

        // Refresh or reload plot
        if (window.location.search.indexOf('section=') !== -1 || window.location.pathname.indexOf('popup.php') !== -1) {
          window.location.reload();
        } else {
          window.location.href = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php';
        }
      } else {
        alert('Error: ' + data.message);
      }
    } catch (e) {
      console.error('JSON parse error:', e, text);
      alert('Error parsing server response. Check console for details.');
    }
  })
  .catch(error => {
    console.error('Error:', error);
    alert('Error submitting plot record. Please try again.');
  })
  .finally(() => {
    if (submitBtn) {
      submitBtn.innerHTML = originalText;
      submitBtn.disabled = false;
    }
  });
}

// Dedicated Modal for Adding Deceased to Occupied Plots ("May laman na" / Patong / Secondary Interment)
function showAddOccupiedDeceasedModal(section, plotX, plotY) {
  var isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
  var userRole = '<?php echo isset($_SESSION['role']) ? strtoupper(trim($_SESSION['role'])) : 'USER'; ?>';
  if (!isAdmin || typeof userRole !== 'string' || userRole.indexOf('ADMIN') === -1) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        icon: 'error',
        title: 'Access Restricted',
        text: 'Only administrators can add deceased records.',
        confirmButtonColor: '#7b1113'
      });
    } else {
      alert('Only administrators can add deceased records.');
    }
    return;
  }

  // Close the current modal first
  const currentModal = bootstrap.Modal.getInstance(document.getElementById('deceasedInfoModal'));
  if (currentModal) {
    currentModal.hide();
  }

  // Set plot coordinates in modal badges and hidden inputs
  document.getElementById('occ_plot_section').value = section;
  document.getElementById('occ_plot_x').value = plotX;
  document.getElementById('occ_plot_y').value = plotY;

  var secBadge = document.getElementById('occPlotSectionBadge');
  if (secBadge) secBadge.textContent = 'Section ' + section.toUpperCase();
  var coordsDisp = document.getElementById('occPlotCoordsDisplay');
  if (coordsDisp) coordsDisp.textContent = 'Plot Coordinates: Column ' + plotX + ', Row ' + plotY;

  // Reset form inputs
  var form = document.getElementById('addOccupiedDeceasedForm');
  if (form) form.reset();

  document.getElementById('occ_plot_section').value = section;
  document.getElementById('occ_plot_x').value = plotX;
  document.getElementById('occ_plot_y').value = plotY;
  if (document.getElementById('occ_contact_no')) {
    document.getElementById('occ_contact_no').value = '09';
  }

  // Restrict date inputs to today max
  var todayIso = new Date().toISOString().split('T')[0];
  var bdayEl = document.getElementById('occ_bday');
  var diedEl = document.getElementById('occ_date_died');
  if (bdayEl) bdayEl.setAttribute('max', todayIso);
  if (diedEl) diedEl.setAttribute('max', todayIso);

  // Retrieve existing occupants from window.__currentDeceased or window.__currentPlotData
  var decData = (window.__currentDeceased && window.__currentDeceased.list) ? window.__currentDeceased.list : ((window.__currentPlotData && window.__currentPlotData.deceased) ? window.__currentPlotData.deceased : []);
  var count = decData.length;
  var slotsLeft = Math.max(0, 4 - count);

  var slotsBadge = document.getElementById('occPlotSlotsBadge');
  if (slotsBadge) {
    slotsBadge.className = slotsLeft > 0 ? 'badge bg-success px-3 py-1.5' : 'badge bg-danger px-3 py-1.5';
    slotsBadge.textContent = 'Occupants: ' + count + '/4 (' + slotsLeft + ' slot' + (slotsLeft === 1 ? '' : 's') + ' left)';
  }

  var countLabel = document.getElementById('occCountLabel');
  if (countLabel) {
    countLabel.textContent = count + ' Recorded';
  }

  // Build the list of current occupants with "Ilang taon nang nakalibing" and "Kung pwede na ba magpatong ule"
  var listContainer = document.getElementById('occExistingListContainer');
  if (listContainer) {
    if (count === 0) {
      listContainer.innerHTML = '<div class="text-muted small py-2">No previous occupants recorded in this plot.</div>';
    } else {
      var html = '<div class="d-flex flex-column gap-3">';
      var anyEligible = true;
      var minYearsInterval = 8; // Standard 8-10 year sanitation code / cemetery layering interval

      decData.forEach(function(occ, idx) {
        var occName = occ.full_name || ((occ.first_name || '') + ' ' + (occ.middle_name || '') + ' ' + (occ.last_name || '') + ' ' + (occ.suffix || '')).replace(/\s+/g, ' ').trim();
        var diedRaw = occ.date_died || occ.interment_date || '';
        var bdayRaw = occ.bday || '';

        var diedFmt = diedRaw ? new Date(diedRaw).toLocaleDateString('en-PH', { timeZone: 'Asia/Manila', year: 'numeric', month: 'long', day: 'numeric' }) : 'Date not recorded';
        var bdayFmt = bdayRaw ? new Date(bdayRaw).toLocaleDateString('en-PH', { timeZone: 'Asia/Manila', year: 'numeric', month: 'long', day: 'numeric' }) : '—';

        // Calculate burial duration ("Ilang taon nang nakalibing")
        var durationText = 'Date not recorded';
        var yearsElapsed = 0;
        var isEligibleToInter = true;

        if (diedRaw && /^\d{4}-\d{2}-\d{2}/.test(diedRaw)) {
          var dStart = new Date(diedRaw);
          var dNow = new Date();
          var yDiff = dNow.getFullYear() - dStart.getFullYear();
          var mDiff = dNow.getMonth() - dStart.getMonth();
          var dayDiff = dNow.getDate() - dStart.getDate();
          if (dayDiff < 0) { mDiff--; }
          if (mDiff < 0) { mDiff += 12; yDiff--; }
          yearsElapsed = yDiff;

          var parts = [];
          if (yDiff > 0) parts.push(yDiff + ' ' + (yDiff === 1 ? 'year' : 'years'));
          if (mDiff > 0) parts.push(mDiff + ' ' + (mDiff === 1 ? 'month' : 'months'));
          if (parts.length === 0) parts.push('Less than a month');
          durationText = parts.join(', ');

          // Check if eligible to inter on top (pwede na magpatong):
          // If 8 or more years have passed: Eligible!
          // If less than 8 years: Not yet eligible under standard interval.
          isEligibleToInter = (yDiff >= minYearsInterval);
          if (!isEligibleToInter) anyEligible = false;
        } else {
          isEligibleToInter = false;
          anyEligible = false;
        }

        var badgeHtml = '';
        if (isEligibleToInter) {
          badgeHtml = '<span class="badge bg-success text-white py-1 px-2.5 rounded-pill"><i class="fas fa-check-circle me-1"></i> Eligible for Next Interment (Eligible: ' + yearsElapsed + ' yrs elapsed)</span>';
        } else {
          badgeHtml = '<span class="badge bg-warning text-dark py-1 px-2.5 rounded-pill fw-bold"><i class="fas fa-clock me-1"></i> Recently Interred (' + durationText + ' elapsed)</span>';
        }

        html += `
          <div class="p-3 rounded border" style="background: #fdfdfd; border-color: ${isEligibleToInter ? '#a7f3d0' : '#fed7aa'} !important; border-left: 5px solid ${isEligibleToInter ? '#10b981' : '#f59e0b'} !important;">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2 pb-1.5 border-bottom">
              <div>
                <strong class="text-dark fs-6"><i class="fas fa-user text-danger me-1.5"></i>${idx + 1}. ${escapeHtml(occName)}</strong>
                <span class="text-muted small ms-2">Born: ${escapeHtml(bdayFmt)}</span>
              </div>
              <div>${badgeHtml}</div>
            </div>
            <div class="row g-2 small">
              <div class="col-md-6">
                <span class="text-muted d-block" style="font-size: 0.78rem;">Date of Death / Burial:</span>
                <strong class="text-dark">${escapeHtml(diedFmt)}</strong>
              </div>
              <div class="col-md-6">
                <span class="text-muted d-block" style="font-size: 0.78rem;">Burial Duration / Years Elapsed:</span>
                <strong class="${isEligibleToInter ? 'text-success' : 'text-danger'}" style="font-size: 0.88rem;">${durationText}</strong>
              </div>
              <div class="col-12 mt-1">
                <div class="p-2 rounded ${isEligibleToInter ? 'bg-success bg-opacity-10 text-success' : 'bg-warning bg-opacity-10 text-dark'} small" style="font-size: 0.78rem;">
                  <i class="fas ${isEligibleToInter ? 'fa-check' : 'fa-info-circle'} me-1"></i>
                  ${isEligibleToInter 
                    ? 'Eligible for additional interment on top (interment layering). Interval requirement is satisfied.' 
                    : 'Notice: Standard cemetery guidelines recommend an 8-10 years interval from previous burial before opening/layering.'}
                </div>
              </div>
            </div>
          </div>
        `;
      });

      html += '</div>';
      listContainer.innerHTML = html;

      // Show/hide override checkbox if any occupant was buried less than 8-10 years ago
      var overrideWrap = document.getElementById('occAdminOverrideWrap');
      if (overrideWrap) {
        overrideWrap.style.display = (!anyEligible) ? 'block' : 'none';
        var cb = document.getElementById('occ_admin_override');
        if (cb) cb.checked = anyEligible;
      }
    }
  }

  // Open modal
  const addOccModal = new bootstrap.Modal(document.getElementById('addOccupiedDeceasedModal'));
  addOccModal.show();
}

function submitAddOccupiedDeceased() {
  const form = document.getElementById('addOccupiedDeceasedForm');
  if (!form) return;

  if (!form.checkValidity()) {
    form.reportValidity();
    return;
  }

  const birthday = document.getElementById('occ_bday').value;
  const dateDied = document.getElementById('occ_date_died').value;
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

  const contactVal = (document.getElementById('occ_contact_no') ? document.getElementById('occ_contact_no').value : '').replace(/\D/g, '');
  if (!/^09\d{9}$/.test(contactVal)) {
    alert('Family Contact Number must be an 11-digit mobile number starting with 09 (e.g. 09XXXXXXXXX).');
    if (document.getElementById('occ_contact_no')) document.getElementById('occ_contact_no').focus();
    return;
  }

  const occTermsCb = document.getElementById('occ_terms_agreement');
  if (occTermsCb && !occTermsCb.checked) {
    alert('Please read and agree to the Multi-Interment (Patong) Terms & Cemetery Policies before submitting.');
    if (occTermsCb) occTermsCb.focus();
    return;
  }

  const formData = new FormData(form);
  formData.append('ajax_request', 'add_deceased');

  const submitBtn = document.getElementById('occAddDecSubmitBtn');
  const originalText = submitBtn ? submitBtn.innerHTML : 'Submit Deceased Record';
  if (submitBtn) {
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...';
    submitBtn.disabled = true;
  }

  fetch(popupPath, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin',
    body: formData
  })
  .then(response => response.text())
  .then(text => {
    try {
      const data = JSON.parse(text);
      if (data.success) {
        const modal = bootstrap.Modal.getInstance(document.getElementById('addOccupiedDeceasedModal'));
        if (modal) modal.hide();

        const succEl = document.getElementById('deceasedSuccessModal');
        const handleDone = function() {
          if (window.location.search.indexOf('section=') !== -1 || window.location.pathname.indexOf('popup.php') !== -1) {
            window.location.reload();
          } else {
            window.location.href = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php';
          }
        };

        if (succEl && typeof bootstrap !== 'undefined') {
          const succModal = new bootstrap.Modal(succEl);
          const okBtn = document.getElementById('deceasedSuccessModalOkBtn');
          if (okBtn) okBtn.onclick = function() {
            succModal.hide();
            handleDone();
          };
          succEl.addEventListener('hidden.bs.modal', function() {
            handleDone();
          }, { once: true });
          succModal.show();
        } else {
          alert('Deceased person added successfully to this plot!');
          handleDone();
        }
      } else {
        alert('Error: ' + data.message);
      }
    } catch (e) {
      console.error('JSON parse error:', e, text);
      alert('Error parsing server response. Check console for details.');
    }
  })
  .catch(error => {
    console.error('Error:', error);
    alert('Error submitting deceased record. Please try again.');
  })
  .finally(() => {
    if (submitBtn) {
      submitBtn.innerHTML = originalText;
      submitBtn.disabled = false;
    }
  });
}

// Reserve Plot: open modal and submit handler
function showReservePlotModal(section, plotX, plotY) {
  var sec = document.getElementById('reserve_section');
  var px = document.getElementById('reserve_plot_x');
  var py = document.getElementById('reserve_plot_y');
  if (sec) sec.value = section;
  if (px) px.value = plotX;
  if (py) py.value = plotY;
  // Reset fields
  var f = document.getElementById('res_first_name'); if (f) f.value = '';
  var m = document.getElementById('res_middle_name'); if (m) m.value = '';
  var l = document.getElementById('res_last_name'); if (l) l.value = '';
  var sfx = document.getElementById('res_suffix'); if (sfx) sfx.value = '';
  var sfxo = document.getElementById('res_suffix_other'); if (sfxo) { sfxo.value = ''; sfxo.style.display = 'none'; }
  var bd = document.getElementById('res_bday'); if (bd) bd.value = '';
  var ph = document.getElementById('res_phone'); if (ph) ph.value = '';
  var addr = document.getElementById('res_address'); if (addr) addr.value = '';
  // Populate location labels
  var ls = document.getElementById('reserve_loc_section'); if (ls) ls.textContent = String(section).toUpperCase();
  var lx = document.getElementById('reserve_loc_plotx'); if (lx) lx.textContent = String(plotX);
  var ly = document.getElementById('reserve_loc_ploty'); if (ly) ly.textContent = String(plotY);
  // Ensure the reserve modal is in front: hide the deceased info modal if open
  var infoEl = document.getElementById('deceasedInfoModal');
  if (infoEl) {
    var infoModal = bootstrap.Modal.getInstance(infoEl) || new bootstrap.Modal(infoEl);
    try { infoModal.hide(); } catch (e) {}
  }
  var modal = new bootstrap.Modal(document.getElementById('reservePlotModal'));
  modal.show();
}

function submitReservePlot() {
  var fn = document.getElementById('res_first_name').value.trim();
  var mn = document.getElementById('res_middle_name').value.trim();
  var ln = document.getElementById('res_last_name').value.trim();
  var sxSel = document.getElementById('res_suffix');
  var sxOther = document.getElementById('res_suffix_other');
  var sx = '';
  if (sxSel) {
    var v = (sxSel.value || '').trim().toUpperCase();
    if (v === 'OTHER' && sxOther) { sx = (sxOther.value || '').trim().toUpperCase(); }
    else { sx = v; }
  }
  var bd = document.getElementById('res_bday').value.trim();
  var ph = document.getElementById('res_phone').value.trim();
  var addr = document.getElementById('res_address').value.trim();
  var section = document.getElementById('reserve_section').value;
  var plotX = document.getElementById('reserve_plot_x').value;
  var plotY = document.getElementById('reserve_plot_y').value;
  if (!fn || !ln || !bd || !ph || !addr) { alert('Please complete all required fields.'); return; }
  var btn = document.querySelector('#reservePlotModal .btn-primary');
  var original = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Reserving...';
  var body = new URLSearchParams();
  body.set('ajax_request','reserve_plot');
  body.set('section', section);
  body.set('plot_x', plotX);
  body.set('plot_y', plotY);
  body.set('res_first_name', fn);
  body.set('res_middle_name', mn);
  body.set('res_last_name', ln);
  body.set('res_suffix', sx);
  body.set('res_bday', bd);
  body.set('res_phone', ph);
  body.set('res_address', addr);
  fetch(popupPath, { 
    method: 'POST', 
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, 
    credentials: 'same-origin',
    body: body.toString() 
  })
    .then(r => r.text())
    .then(t => { console.log('Reserve response raw:', t); return JSON.parse(t); })
    .then(data => {
      if (data.success) {
        const modal = bootstrap.Modal.getInstance(document.getElementById('reservePlotModal'));
        if (modal) modal.hide();
        // Immediately update the plot button styling to Reserved (gray) using DB-driven response
        try {
          const sel = '.plot-btn[data-plot-x="' + plotX + '"][data-plot-y="' + plotY + '"]';
          const el = document.querySelector(sel);
          if (el) {
            el.style.background = 'rgba(0,0,0,0.35)';
            el.style.color = '#ffffff';
            el.style.borderColor = '#555';
            el.setAttribute('data-reserved','1');
            el.title = 'This Plot is Reserved';
          }
        } catch (e) { /* non-fatal */ }
        // Redirect to main map so occupancy and stats refresh
        try { Swal.fire({ icon: 'success', title: 'Reserved', text: data.message || 'Plot reserved successfully' }).then(() => { window.location.href = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php'; }); } catch (e) { window.location.href = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php'; }
      } else {
        alert('Error: ' + data.message);
      }
    })
    .catch(err => { console.error(err); alert('Error reserving plot.'); })
    .finally(() => { btn.disabled = false; btn.innerHTML = original; });
}

// Apply light gray styling to reserved plots (no deceased)
function applyReservedStyling() {
  var section = '<?= $section ?>';
  if (!section) {
    try {
      section = (new URL(window.location.href)).searchParams.get('section') || '';
    } catch (e) {
      section = '';
    }
  }
  section = String(section).toUpperCase();
  if (!section) return;
  const body = new URLSearchParams();
  body.set('ajax_request','get_reserved_plots');
  body.set('section', section);
  var popupPath = (window.location.pathname.indexOf('popup.php') !== -1) 
    ? window.location.pathname 
    : ((window.location.pathname.indexOf('/sections/') !== -1) ? 'popup.php' : 'sections/popup.php');
  fetch(popupPath, { credentials: 'same-origin', method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: body.toString() })
    .then(function(r) {
      var contentType = r.headers.get('content-type') || '';
      if (!contentType.toLowerCase().includes('application/json')) {
        throw new Error('Reserved-plots request returned HTTP ' + r.status + ' instead of JSON. Please sign in again.');
      }
      return r.json();
    })
    .then(data => {
      if (!data || !data.success || !Array.isArray(data.reserved)) return;
      data.reserved.forEach(function(item){
        const sel = '.plot-btn[data-plot-x="' + item.plot_x + '"][data-plot-y="' + item.plot_y + '"]';
        var el = document.querySelector(sel);
        if (!el) {
          el = document.querySelector('.plot-btn[data-plot-x="' + item.plot_y + '"][data-plot-y="' + item.plot_x + '"]');
        }
        if (!el) return;
        if (item.status === 'purchased' || item.is_purchased) {
          el.title = 'Purchased Plot';
          el.setAttribute('data-purchased','1');
          el.removeAttribute('data-reserved');
        } else {
          el.title = 'This Plot is Reserved';
          el.setAttribute('data-reserved','1');
          el.removeAttribute('data-purchased');
        }

        // Skip if already marked as deceased (red background in inline style)
        const bg = el.style.background || '';
        if (bg.indexOf('218, 57, 65') !== -1) return;

        // If currently blinking/highlighted, update it to use reserved blinking animation rather than red deceased blinking!
        if (el.classList.contains('plot-blinking-highlight') || el.classList.contains('deceased-search-highlight')) {
          el.classList.remove('deceased-search-highlight', 'deceased-search-plot-highlight');
          el.classList.add('plot-blinking-reserved', 'reserved-search-highlight');
          el.style.setProperty('animation', 'reservedPlotBlinkAlert 0.75s ease-in-out infinite', 'important');
          el.style.setProperty('border', '3px solid #f59e0b', 'important');
          el.style.setProperty('outline', '2.5px solid #fbbf24', 'important');
          el.style.setProperty('outline-offset', '1px', 'important');
          el.style.setProperty('box-shadow', '0 0 0 4px rgba(245, 158, 11, 0.7), 0 0 20px 6px rgba(245, 158, 11, 0.95)', 'important');
          el.style.setProperty('background', 'rgba(40, 40, 40, 0.85)', 'important');
          el.style.setProperty('background-color', 'rgba(40, 40, 40, 0.85)', 'important');
          el.style.setProperty('color', '#ffffff', 'important');
          return;
        }

        // Apply normal reserved/purchased styling (dark gray)
        el.style.background = 'rgba(0,0,0,0.35)';
        el.style.color = '#ffffff';
        el.style.borderColor = '#555';
      });
    })
    .catch(err => console.error('Failed to load reserved plots:', err));
}
window.applyReservedStyling = applyReservedStyling;
window.loadReservedPlots = applyReservedStyling;

// Run once the overlay buttons are in the DOM
setTimeout(applyReservedStyling, 0);

// Ensure plot buttons have click handlers (restore clickability if event delegation failed)
function attachPlotHandlers() {
  var sectionConst = '<?= $section ?>';
  // In booking mode, plot clicks are handled by the booking-selection handler
  // (which fetches plot status and shows the "Continue Booking" flow). Do NOT
  // attach the normal map/deceased-information handler here, otherwise the
  // Deceased Information modal would open during booking.
  var isBookingMode = !!(window.__bookingMode);
  document.querySelectorAll('.plot-btn').forEach(function(btn){
    try {
      if (btn.dataset._clickAttached === '1') return;
      btn.dataset._clickAttached = '1';
      if (isBookingMode) return; // booking handler owns the click in booking mode
      // Normal plot click handling is managed by the delegated document-level
      // click listener above. Do not attach a second plot click handler here.
    } catch (e) { /* non-fatal */ }
  });
}

setTimeout(attachPlotHandlers, 50);

// Manage Reservation: open modal, load, submit
function showManageReservationModal(section, plotX, plotY) {
  var isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
  var userRole = '<?php echo isset($_SESSION['role']) ? strtoupper(trim($_SESSION['role'])) : 'USER'; ?>';
  if (!isAdmin || typeof userRole !== 'string' || userRole.indexOf('ADMIN') === -1) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        icon: 'warning',
        title: 'Access Restricted',
        text: 'Only administrators can manage reservation records.',
        confirmButtonColor: '#7b1113'
      });
    } else {
      alert('Only administrators can manage reservation records.');
    }
    return;
  }

  // Ensure the info modal (if open) is hidden so Manage appears in front
  var infoEl = document.getElementById('deceasedInfoModal');
  if (infoEl) {
    var infoModal = bootstrap.Modal.getInstance(infoEl) || new bootstrap.Modal(infoEl);
    try { infoModal.hide(); } catch (e) {}
  }
  // Populate location
  document.getElementById('manage_loc_section').textContent = String(section).toUpperCase();
  document.getElementById('manage_loc_plotx').textContent = String(plotX);
  document.getElementById('manage_loc_ploty').textContent = String(plotY);
  document.getElementById('man_section').value = section;
  document.getElementById('man_plot_x').value = plotX;
  document.getElementById('man_plot_y').value = plotY;
  // Fetch current reservation
  const body = new URLSearchParams();
  body.set('ajax_request','get_reservation_info');
  body.set('section', section);
  body.set('plot_x', plotX);
  body.set('plot_y', plotY);
  fetch(popupPath, { 
    method: 'POST', 
    headers: { 'Content-Type':'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, 
    credentials: 'same-origin',
    body: body.toString() 
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success && data.reservation) {
        const r = data.reservation;
        document.getElementById('man_first_name').value = r.first_name || '';
        document.getElementById('man_middle_name').value = r.middle_name || '';
        document.getElementById('man_last_name').value = r.last_name || '';
        document.getElementById('man_suffix').value = r.suffix || '';
        document.getElementById('man_bday').value = r.bday || '';
        document.getElementById('man_phone').value = r.phone_no || '';
        const modalEl = document.getElementById('manageReservationModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
        setTimeout(function() {
          document.body.classList.add('modal-open');
          var mBody = modalEl.querySelector('.modal-body');
          if (mBody) mBody.scrollTop = 0;
        }, 50);
      } else {
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'warning',
            title: 'Access Restricted',
            text: (data && data.message) ? data.message : 'Reservation not found',
            confirmButtonColor: '#7b1113'
          });
        } else {
          alert((data && data.message) ? data.message : 'Reservation not found');
        }
      }
    })
    .catch(() => {
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Failed to load reservation info',
          confirmButtonColor: '#7b1113'
        });
      } else {
        alert('Failed to load reservation info');
      }
    });
}

function submitManageReservation() {
  const section = document.getElementById('man_section').value;
  const plotX = document.getElementById('man_plot_x').value;
  const plotY = document.getElementById('man_plot_y').value;
  const first = document.getElementById('man_first_name').value.trim();
  const middle = document.getElementById('man_middle_name').value.trim();
  const last = document.getElementById('man_last_name').value.trim();
  const suffix = document.getElementById('man_suffix').value.trim();
  const bday = document.getElementById('man_bday').value.trim();
  const phone = document.getElementById('man_phone').value.trim();
  const address = document.getElementById('man_address').value.trim();
  if (!first || !last || !bday || !phone || !address) { alert('Please complete all required fields.'); return; }
  const btn = document.querySelector('#manageReservationModal .btn.btn-primary');
  const original = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
  const body = new URLSearchParams();
  body.set('ajax_request','update_reservation');
  body.set('section', section);
  body.set('plot_x', plotX);
  body.set('plot_y', plotY);
  body.set('first_name', first);
  body.set('middle_name', middle);
  body.set('last_name', last);
  body.set('suffix', suffix);
  body.set('bday', bday);
  body.set('phone_no', phone);
  body.set('address', address);
  fetch(popupPath, { 
    method: 'POST', 
    headers: { 'Content-Type':'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, 
    credentials: 'same-origin',
    body: body.toString() 
  })
    .then(r => r.text())
    .then(t => { try { return JSON.parse(t); } catch(e) { console.error('Raw:', t); throw e; } })
    .then(data => {
      if (data.success) {
        const modal = bootstrap.Modal.getInstance(document.getElementById('manageReservationModal'));
        if (modal) modal.hide();
        try { Swal.fire({ icon: 'success', title: 'Updated', text: data.message || 'Reservation updated successfully' }).then(() => { window.location.href = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php'; }); } catch (e) { window.location.href = (window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '')) + 'map.php'; }
      } else {
        alert('Error: ' + (data.message || 'Failed to update reservation'));
      }
    })
    .catch(() => alert('Request failed'))
    .finally(() => { btn.disabled = false; btn.innerHTML = original; });
}

</script>

<script>
// Navigation object for Google Maps navigation
const navigation = {
    startGoogleMapsNavigation(section, plotX, plotY, plotId) {
        var baseWebPath = window.__baseWebPath || ((window.location.pathname.indexOf('/sections/') !== -1) ? '../' : '');
        var endpoint = baseWebPath + 'sections/popup.php';
        var formData = new FormData();
        formData.append('ajax_request', 'get_plot_navigation');
        formData.append('ajax', 'get_plot_navigation');
        formData.append('section', section || '');
        formData.append('plot_x', plotX || '');
        formData.append('plot_y', plotY || '');
        if (plotId) {
            formData.append('plot_id', plotId);
        }

        var newTab = null;
        try {
            newTab = window.open('about:blank', '_blank');
        } catch(e) {}

        fetch(endpoint, {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            var targetUrl = (data && data.directions_url) ? data.directions_url : (data ? data.navigation_url : '');
            if (data && data.has_coordinates && targetUrl) {
                if (newTab && !newTab.closed) {
                    newTab.location.href = targetUrl;
                } else {
                    try {
                        var w = window.open(targetUrl, '_blank');
                        if (!w || w.closed || typeof w.closed === 'undefined') {
                            window.location.href = targetUrl;
                        }
                    } catch(e) {
                        window.location.href = targetUrl;
                    }
                }
            } else {
                if (newTab && !newTab.closed) newTab.close();
                alert(data && data.message ? data.message : 'Navigation coordinates are not available for this plot yet.');
            }
        })
        .catch(function(err) {
            if (newTab && !newTab.closed) newTab.close();
            console.error('Error getting plot navigation:', err);
            alert('Navigation coordinates are not available for this plot yet.');
        });
    }
};

window.openCemeteryTermsModal = function(e, type) {
    if (e && e.preventDefault) e.preventDefault();
    if (e && e.stopPropagation) e.stopPropagation();

    var isPatong = (type === 'patong');
    var titleText = isPatong ? 'Multi-Interment (Patong) & Layering Policies' : 'Cemetery Terms, Conditions & Policy Agreements';
    var iconHtml = isPatong ? '<i class="fas fa-layer-group text-warning me-2"></i>' : '<i class="fas fa-file-contract text-warning me-2"></i>';
    var bodyHtml = isPatong ? `
        <div class="p-2 text-start" style="font-size: 0.92rem; line-height: 1.6; color: #2d3748;">
            <div class="alert alert-warning py-2 px-3 mb-3 small d-flex align-items-center gap-2">
                <i class="fas fa-exclamation-triangle fs-5 text-warning flex-shrink-0"></i>
                <div><strong>Policy Notice:</strong> Multi-interment (Patong) requires strict verification of relationship and plot deed authorization.</div>
            </div>
            <h6 class="fw-bold text-dark mb-2">1. Eligibility & Lineage Requirements</h6>
            <p class="text-muted small mb-3">Only direct descendants, legal spouses, or registered plot beneficiaries may be added as layered interments. Verification documents (Birth Certificate, Marriage Certificate, or Plot Title) are mandatory.</p>
            
            <h6 class="fw-bold text-dark mb-2">2. Minimum Inter-burial Period</h6>
            <p class="text-muted small mb-3">According to local sanitation laws, a standard period must have lapsed since the primary interment before performing a secondary burial on the same plot, unless exhumation and vault preparation have been legally cleared.</p>

            <h6 class="fw-bold text-dark mb-2">3. Permits & Fees</h6>
            <p class="text-muted small mb-3">All municipal transfer and burial permits must be filed and approved prior to the ceremony. Multi-interment layering fees apply.</p>

            <h6 class="fw-bold text-dark mb-2">4. Tombstone & Monument Alterations</h6>
            <p class="text-muted small mb-0">Engravings or monument adjustments must follow park height, width, and material specifications.</p>
        </div>
    ` : `
        <div class="p-2 text-start" style="font-size: 0.92rem; line-height: 1.6; color: #2d3748;">
            <div class="alert alert-info py-2 px-3 mb-3 small d-flex align-items-center gap-2">
                <i class="fas fa-info-circle fs-5 text-info flex-shrink-0"></i>
                <div><strong>General Policy:</strong> All records entered must be backed by official death and burial certificates.</div>
            </div>
            <h6 class="fw-bold text-dark mb-2">1. Official Record Accuracy</h6>
            <p class="text-muted small mb-3">All names, dates, permit numbers, and next-of-kin entries submitted to the memorial park record are treated as legal entries and subject to audit.</p>
            
            <h6 class="fw-bold text-dark mb-2">2. Plot Use & Assignment</h6>
            <p class="text-muted small mb-3">Plots allocated may only be utilized according to park rules and the registered deed. Transfer of rights requires cemetery administration approval.</p>

            <div class="p-3 bg-light rounded border mb-3" style="border-left: 4px solid #dc3545 !important;">
                <h6 class="fw-bold text-danger mb-1"><i class="fas fa-calendar-times text-danger me-2"></i>3. Three (3) Months Non-Payment Cancellation Policy</h6>
                <p class="mb-0 small text-muted"><strong>Automatic Cancellation:</strong> If a client fails to pay or settles no payment for three (3) consecutive months (90 days of non-payment or overdue installment amortizations), the reservation will be automatically cancelled. The reserved plot will be immediately forfeited, released, and made available for other buyers. In accordance with memorial park policy, all previous payments and reservation fees are strictly non-refundable.</p>
            </div>

            <h6 class="fw-bold text-dark mb-2">4. Visiting & Memorial Maintenance</h6>
            <p class="text-muted small mb-0">Families and visitors are requested to respect visiting hours, maintain solemnity, and cooperate with maintenance schedules.</p>
        </div>
    `;

    var modalEl = document.getElementById('cemeteryTermsModal');
    if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        var titleEl = document.getElementById('cemeteryTermsModalLabel');
        var bodyEl = document.getElementById('cemeteryTermsModalBody');
        if (titleEl) titleEl.innerHTML = iconHtml + titleText;
        if (bodyEl) bodyEl.innerHTML = bodyHtml;
        var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bsModal.show();
    } else if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: titleText,
            html: bodyHtml,
            icon: 'info',
            confirmButtonText: 'I Understand',
            confirmButtonColor: '#7b1113',
            width: '600px'
        });
    } else {
        alert(titleText + "\n\nAll records and interments must comply with Garden of Remembrance Memorial Park rules.");
    }
};
</script>

<!-- Cemetery Terms & Agreements Modal -->
<div class="modal fade" id="cemeteryTermsModal" tabindex="-1" aria-labelledby="cemeteryTermsModalLabel" aria-hidden="true" style="z-index: 1065;">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem; overflow: hidden;">
      <div class="modal-header text-white" style="background: linear-gradient(135deg, #7b1113 0%, #4a0a0b 100%);">
        <h5 class="modal-title fw-bold" id="cemeteryTermsModalLabel">
          <i class="fas fa-file-contract text-warning me-2"></i> Terms and Agreements
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4" id="cemeteryTermsModalBody">
        <!-- Content injected dynamically -->
      </div>
      <div class="modal-footer bg-light border-0 px-4 py-3">
        <button type="button" class="btn btn-secondary px-4 fw-semibold" data-bs-dismiss="modal" style="border-radius: 0.5rem;">Close</button>
      </div>
    </div>
  </div>
</div>
<script>
  (function() {
    var base = (window.location && window.location.pathname && String(window.location.pathname).indexOf('/sections/') !== -1) ? '../' : '';
    var s = document.createElement('script');
    s.src = base + 'assets/js/ph_address_selector.js';
    document.head.appendChild(s);
  })();
</script>
</body>
</html>

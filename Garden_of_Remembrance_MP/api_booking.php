<?php

ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/connection.php';

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

function json_out($arr) {
    echo json_encode($arr);
    exit;
}

if (!$action) json_out(['success' => false, 'message' => 'No action specified.']);

function ensure_booking_tables($conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $tbl = $conn->query("SHOW TABLES LIKE 'services'");
    if ($tbl && $tbl->num_rows > 0) return;

    
    $conn->query("CREATE TABLE IF NOT EXISTS `sections` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `section_name` VARCHAR(10) NOT NULL UNIQUE,
      `lawn_type` VARCHAR(100) DEFAULT NULL,
      `capacity` INT DEFAULT 0,
      `base_price` DECIMAL(10,2) DEFAULT 0.00
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    
    $conn->query("CREATE TABLE IF NOT EXISTS `plots` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `section_id` INT NOT NULL,
      `plot_no` VARCHAR(20) NOT NULL,
      `status` ENUM('available','occupied') DEFAULT 'available',
      `plot_price` DECIMAL(10,2) DEFAULT NULL,
      `occupant_name` VARCHAR(255) DEFAULT NULL,
      UNIQUE KEY `section_plot_unique` (`section_id`,`plot_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    
    $conn->query("CREATE TABLE IF NOT EXISTS `services` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `service_name` VARCHAR(100) NOT NULL,
      `price` DECIMAL(10,2) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    
    $r = $conn->query("SELECT COUNT(*) AS c FROM sections");
    if ($r) {
        $c = (int)$r->fetch_assoc()['c'];
        if ($c === 0) {
            $sections = [
                ['A','Lawn (Regular)',80,85000.00],['B','Lawn (Regular)',60,85000.00],['C','Lawn (Regular)',72,85000.00],
                ['D','Lawn (Regular)',100,85000.00],['E','Lawn (Regular)',50,85000.00],['F','Lawn (Regular)',40,85000.00],
                ['G','Lawn (Regular)',55,85000.00],['H','Lawn (Regular)',65,85000.00],['I','Lawn (Regular)',70,85000.00],['J','Lawn (Regular)',45,85000.00]
            ];
            $stmt = $conn->prepare("INSERT INTO sections (section_name, lawn_type, capacity, base_price) VALUES (?,?,?,?)");
            foreach ($sections as $s) {
                $stmt->bind_param('ssid', $s[0], $s[1], $s[2], $s[3]);
                $stmt->execute();
            }
            if ($stmt) $stmt->close();
        }
    }

    
    $r2 = (@$conn->query("SHOW TABLES LIKE 'plots'")->num_rows > 0) ? $conn->query("SELECT COUNT(*) AS c FROM plots") : null;
    if ($r2) {
        $c2 = (int)$r2->fetch_assoc()['c'];
        if ($c2 === 0) {
            $sections_res = $conn->query("SELECT id, section_name FROM sections");
            if ($sections_res) {
                $ins = $conn->prepare("INSERT INTO plots (section_id, plot_no, status) VALUES (?,?,?)");
                while ($row = $sections_res->fetch_assoc()) {
                    $sid = (int)$row['id'];
                    $sname = $row['section_name'];
                    
                    for ($n=1;$n<=10;$n++) {
                        $plotno = $sname . '-' . str_pad($n,3,'0',STR_PAD_LEFT);
                        $status = ($n % 5 === 0) ? 'occupied' : 'available';
                        $ins->bind_param('iss', $sid, $plotno, $status);
                        $ins->execute();
                    }
                }
                if ($ins) $ins->close();
            }
        }
    }

    
    $r3 = $conn->query("SELECT COUNT(*) AS c FROM services");
    if ($r3) {
        $c3 = (int)$r3->fetch_assoc()['c'];
        if ($c3 === 0) {
            $svc = $conn->prepare("INSERT INTO services (service_name, price) VALUES (?,?)");
            $services = [['Cleaning',200.00],['Landscaping',200.00],['Maintenance',200.00],['Decoration',200.00]];
            foreach ($services as $s) { $svc->bind_param('sd', $s[0], $s[1]); $svc->execute(); }
            if ($svc) $svc->close();
        }
    }
}

ensure_booking_tables($conn);
switch ($action) {
    case 'get_sections':
        $sql = "SELECT s.id, s.section_name, s.lawn_type, s.capacity, COALESCE(s.base_price,0) AS base_price,
                       COUNT(p.plot_id) AS total_plots,
                       SUM(CASE WHEN LOWER(COALESCE(p.status,'available')) = 'available' THEN 1 ELSE 0 END) AS available_plots
                FROM sections s
                LEFT JOIN tbl_plots p ON p.section = s.section_name
                GROUP BY s.id, s.section_name, s.lawn_type, s.capacity, s.base_price
                ORDER BY s.section_name";
        $res = $conn->query($sql);
        if (!$res) json_out(['success' => false, 'message' => 'DB error: ' . $conn->error]);
        $sections = [];
        while ($row = $res->fetch_assoc()) {
            $row['total_plots'] = (int)($row['total_plots'] ?? 0);
            $row['available_plots'] = (int)($row['available_plots'] ?? 0);
            $row['base_price'] = (float)($row['base_price'] ?? 0);
            $sections[] = $row;
        }
        json_out(['success' => true, 'sections' => $sections]);
        break;

    case 'get_plots':
        $section = isset($_REQUEST['section']) ? trim($_REQUEST['section']) : '';
        if (!$section) json_out(['success' => false, 'message' => 'Section required']);

        $sec_q = $conn->real_escape_string($section);
        $r = $conn->query("SELECT id, section_name, base_price FROM sections WHERE section_name = '$sec_q' OR id = '$sec_q' LIMIT 1");
        if (!$r || $r->num_rows == 0) json_out(['success' => false, 'message' => 'Section not found']);
        $sec_row = $r->fetch_assoc();
        $sec_name = $sec_row['section_name'];
        $base_price = (float)($sec_row['base_price'] ?? 0);

        $plots = [];
        $sec_name_esc = $conn->real_escape_string($sec_name);
        $q = $conn->query("SELECT plot_id AS id, CONCAT(section, '-', `row`, '-', plot_number) AS plot_no, status, IFNULL(price, $base_price) AS plot_price FROM tbl_plots WHERE section = '$sec_name_esc' ORDER BY `row`, plot_number");
        if (!$q) json_out(['success' => false, 'message' => 'DB error: ' . $conn->error]);
        while ($p = $q->fetch_assoc()) {
            $p['plot_price'] = $p['plot_price'] !== null ? (float)$p['plot_price'] : $base_price;
            $plots[] = $p;
        }
        json_out(['success' => true, 'plots' => $plots]);
        break;

    case 'get_plot_details':
        $section = isset($_REQUEST['section']) ? trim($_REQUEST['section']) : '';
        $plot = isset($_REQUEST['plot']) ? trim($_REQUEST['plot']) : '';
        if (!$section || !$plot) json_out(['success' => false, 'message' => 'Section and plot required']);

        $sec = $conn->real_escape_string($section);
        $sec_q = $conn->query("SELECT id, section_name, lawn_type, capacity, COALESCE(base_price,0) AS base_price FROM sections WHERE section_name = '$sec' OR id = '$sec' LIMIT 1");
        if (!$sec_q || $sec_q->num_rows == 0) json_out(['success' => false, 'message' => 'Section not found']);
        $section_row = $sec_q->fetch_assoc();
        $sec_name = $section_row['section_name'];

        $p = $conn->real_escape_string($plot);
        $sec_name_esc = $conn->real_escape_string($sec_name);
        $plot_q = $conn->query("SELECT plot_id AS id, CONCAT(section, '-', `row`, '-', plot_number) AS plot_no, status, IFNULL(price, {$section_row['base_price']}) AS plot_price FROM tbl_plots WHERE section = '$sec_name_esc' AND (plot_id = '$p' OR CONCAT(section, '-', `row`, '-', plot_number) = '$p') LIMIT 1");
        if (!$plot_q) json_out(['success' => false, 'message' => 'DB error: ' . $conn->error]);
        if ($plot_q->num_rows > 0) {
            $plot_row = $plot_q->fetch_assoc();
            $plot_row['plot_price'] = (float)$plot_row['plot_price'];
            $plot_row['interment_fee'] = 38000.00;
            $plot_row['total_price'] = $plot_row['plot_price'] + 38000.00;
            $plot_row['reservation_deposit'] = $plot_row['plot_price'] * 0.05;
            json_out(['success' => true, 'plot' => $plot_row, 'section' => $section_row]);
        }
        json_out(['success' => false, 'message' => 'Plot not found']);
        break;

    case 'get_services':
        $services = [];
        $r = $conn->query("SELECT id, service_name, price FROM services ORDER BY id");
        if (!$r) json_out(['success' => false, 'message' => 'DB error: ' . $conn->error]);
        while ($s = $r->fetch_assoc()) {
            $s['price'] = (float)$s['price'];
            $services[] = $s;
        }
        json_out(['success' => true, 'services' => $services]);
        break;

    case 'get_service_price':
        $id = isset($_REQUEST['service_id']) ? intval($_REQUEST['service_id']) : 0;
        if (!$id) json_out(['success' => false, 'message' => 'service_id required']);
        $r = $conn->query("SELECT price FROM services WHERE id = $id LIMIT 1");
        if ($r && $r->num_rows) {
            $price = (float)$r->fetch_assoc()['price'];
            json_out(['success' => true, 'price' => $price]);
        }
        json_out(['success' => false, 'message' => 'Service not found']);
        break;

    case 'check_interment_interval':
        $sec = strtoupper(trim((string)($_REQUEST['section'] ?? '')));
        $px = (int)($_REQUEST['plot_x'] ?? 0);
        $py = (int)($_REQUEST['plot_y'] ?? 0);
        $as_of = trim((string)($_REQUEST['as_of_date'] ?? ''));
        if (function_exists('check_plot_interment_interval_eligibility')) {
            $check = check_plot_interment_interval_eligibility($conn, $sec, $px, $py, $as_of ?: null);

            
            $intermentFee = 38000.00;
            $pTbl = (@$conn->query("SHOW TABLES LIKE 'tbl_plots'")->num_rows > 0) ? 'tbl_plots' : 'plots';
            $ifStmt = $conn->prepare("SELECT COALESCE(interment_fee,0) AS interment_fee FROM {$pTbl} WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
            if ($ifStmt) {
                $ifStmt->bind_param('sii', $sec, $px, $py);
                $ifStmt->execute();
                $ifRes = $ifStmt->get_result();
                if ($ifRes && ($ifRow = $ifRes->fetch_assoc()) && (float)$ifRow['interment_fee'] > 0) {
                    $intermentFee = (float)$ifRow['interment_fee'];
                }
                $ifStmt->close();
            }
            if ($intermentFee <= 0 && function_exists('get_plot_official_pricing')) {
                require_once __DIR__ . '/config/pricing_policy.php';
                $official = get_plot_official_pricing($sec, $px, $py);
                $intermentFee = (float)($official['interment_fee'] ?? 38000.00);
            }
            $check['interment_fee'] = $intermentFee;
            $check['formatted_interment_fee'] = '₱' . number_format($intermentFee, 2);

            
            $check['lawn_type'] = 'Lawn (Regular)';
            $check['plot_status'] = 'OCCUPIED';
            $check['lot_price'] = 85000.00;
            $pStmt = $conn->prepare("SELECT lawn_type, status, price FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
            if ($pStmt) {
                $pStmt->bind_param('sii', $sec, $px, $py);
                $pStmt->execute();
                $pRes = $pStmt->get_result();
                if ($pRes && ($pRow = $pRes->fetch_assoc())) {
                    if (!empty($pRow['lawn_type'])) $check['lawn_type'] = $pRow['lawn_type'];
                    if (!empty($pRow['status'])) $check['plot_status'] = strtoupper($pRow['status']);
                    if (isset($pRow['price'])) $check['lot_price'] = (float)$pRow['price'];
                }
                $pStmt->close();
            }

            json_out(array_merge(['success' => true], $check));
        } else {
            json_out(['success' => false, 'message' => 'Policy module not loaded']);
        }
        break;

    case 'get_available_destination_plots':
        if (function_exists('get_genuinely_available_destination_plots')) {
            $plots = get_genuinely_available_destination_plots($conn);
            json_out(['success' => true, 'plots' => $plots]);
        } else {
            json_out(['success' => false, 'message' => 'Policy module not loaded']);
        }
        break;

    default:
        json_out(['success' => false, 'message' => 'Invalid action']);
}


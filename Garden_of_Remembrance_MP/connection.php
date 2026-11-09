<?php

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Asia/Manila');
}

if (!empty($_GET['debug']) && $_GET['debug'] === '1') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

if (file_exists(__DIR__ . '/config/db_config.php')) {
    $db_config = require __DIR__ . '/config/db_config.php';
    $servername = $db_config['host'];
    $username = $db_config['user'];
    $password = $db_config['password'];
    $dbname = $db_config['dbname'];
    $port = isset($db_config['port']) ? (int)$db_config['port'] : 3306;
} else {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocal = ($host === 'localhost' || strpos($host, 'localhost:') === 0 || $host === '127.0.0.1' || $host === '::1')
        || (php_sapi_name() === 'cli' && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    if (!$isLocal) {

        $servername = "sql109.infinityfree.com";
        $username = "if0_42806961";
        $password = "Shumi2231";
        $dbname = "if0_42806961_cemetery2_db";
        $port = 3306;
    } else {

        $servername = "127.0.0.1";
        $username = "root";
        $password = "";
        $dbname = "cemetery2_db";
        $port = 3306;
    }
}

mysqli_report(MYSQLI_REPORT_OFF);

$db_connected = false;
$conn = null;
$lastConnErr = '';
$lastConnNo = 0;
$connectedDb = '';

$candidateDbs = [$dbname];
if ($servername !== '127.0.0.1' && $servername !== 'localhost') {
    if (strpos($username, 'if0_') === 0) {
        $candidateDbs[] = $username . '_cemetery2_db';
        $candidateDbs[] = $username . '_cemetery1_db';
    } else {
        $candidateDbs[] = 'if0_42806961_cemetery2_db';
        $candidateDbs[] = 'if0_42806961_cemetery1_db';
    }
    $candidateDbs[] = 'cemetery2_db';
    $candidateDbs[] = 'cemetery1_db';
} else {
    $candidateDbs[] = 'cemetery2_db';
    $candidateDbs[] = 'cemetery1_db';
}
$candidateDbs = array_values(array_unique(array_filter($candidateDbs)));

$firstConnected = null; $firstDbName = '';
foreach ($candidateDbs as $candDb) {
    try {
        $opt = mysqli_init();
        if ($opt) {

            @$opt->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            $ok = @$opt->real_connect($servername, $username, $password, $candDb, $port);
            if ($ok && !$opt->connect_errno) {
                @$opt->set_charset('utf8mb4');
                @$opt->query("SET time_zone = '+08:00'");
                $chkUser = @$opt->query("SHOW TABLES LIKE 'tbl_user'");
                if ($chkUser && $chkUser->num_rows > 0) {
                $conn = $opt;
                $dbname = $candDb;
                $connectedDb = $candDb;
                $db_connected = true;
                $lastConnErr = '';
                $lastConnNo = 0;
                break;
                }
                if (!$firstConnected) {
                    $firstConnected = $opt;
                    $firstDbName = $candDb;
                }
            } else {
                $lastConnErr = $opt->connect_error ?? mysqli_connect_error();
                $lastConnNo = (int)($opt->connect_errno ?? mysqli_connect_errno());

                if ($lastConnNo === 1045 || $lastConnNo === 2002) {
                    break;
                }
            }
        }
    } catch (Throwable $dbEx) {
        $lastConnErr = $dbEx->getMessage();
        $lastConnNo = (int)$dbEx->getCode();
        if ($lastConnNo === 1045 || $lastConnNo === 2002) {
            break;
        }
    }
}

if (!$db_connected && $firstConnected) {
    $conn = $firstConnected;
    $dbname = $firstDbName;
    $connectedDb = $firstDbName;
    $db_connected = true;
    $lastConnErr = '';
    $lastConnNo = 0;
}
if (!$db_connected) {
    $conn = null;
    $safeHost = htmlspecialchars((string)$servername);
    $safeDb = htmlspecialchars((string)$dbname);
    error_log("Database offline [Host: {$safeHost}, DB: {$safeDb}, Code: {$lastConnNo}, Err: {$lastConnErr}]");
}

if (!function_exists('is_schema_migrated')) {
    function is_schema_migrated(): bool {
        if (!empty($_GET['force_schema_sync']) || !empty($_GET['migrate_db'])) {
            return false;
        }
        return file_exists(__DIR__ . '/config/.schema_migrated.lock');
    }
}

if (!function_exists('ensure_deceased_database_integrity')) {

    function ensure_deceased_database_integrity($conn) {
        if (is_schema_migrated()) {
            return;
        }
        static $checked = false;
        if ($checked || !($conn instanceof mysqli) || $conn->connect_errno) {
            return;
        }
        $checked = true;

        try {

            $conn->query("DELETE FROM tbl_deceased WHERE UPPER(TRIM(last_name)) LIKE '%TAVERNA%' OR UPPER(TRIM(first_name)) LIKE '%TAVERNA%' OR UPPER(TRIM(booker_name)) LIKE '%TAVERNA%'");
            $conn->query("DELETE FROM tbl_bookings WHERE UPPER(TRIM(customer_name)) LIKE '%TAVERNA%' OR UPPER(TRIM(full_name)) LIKE '%TAVERNA%' OR UPPER(TRIM(deceased_name)) LIKE '%TAVERNA%'");
            $conn->query("DELETE FROM user_plot_ownership WHERE UPPER(TRIM(deceased_reference)) LIKE '%TAVERNA%'");
            $conn->query("DELETE FROM tbl_recent_clicks WHERE UPPER(TRIM(deceased_name)) LIKE '%TAVERNA%' OR UPPER(TRIM(details)) LIKE '%TAVERNA%' OR UPPER(TRIM(plot_code)) LIKE '%TAVERNA%'");
        } catch (Throwable $e) {
            error_log('ensure_deceased_database_integrity error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_guest_reservation_columns')) {
    function ensure_guest_reservation_columns($conn) {
        if (is_schema_migrated()) {
            return;
        }
        static $checked = false;
        if ($checked || !($conn instanceof mysqli) || $conn->connect_errno) {
            return;
        }
        $checked = true;
        try {
            @$conn->query("CREATE TABLE IF NOT EXISTS `guest_reservation_requests` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `user_id` INT(11) NULL DEFAULT NULL,
              `plot_id` INT(11) NULL DEFAULT NULL,
              `booking_id` INT(11) NULL DEFAULT NULL,
              `full_name` VARCHAR(255) NOT NULL,
              `email` VARCHAR(255) NOT NULL,
              `contact_number` VARCHAR(20) NOT NULL,
              `address` VARCHAR(255) NOT NULL,
              `section` VARCHAR(10) NOT NULL,
              `plot_x` INT(11) NOT NULL,
              `plot_y` INT(11) NOT NULL,
              `plot_code` VARCHAR(20) NOT NULL,
              `purpose` VARCHAR(50) NOT NULL DEFAULT 'Future Memorial Lot',
              `preferred_date` DATE NULL DEFAULT NULL,
              `notes` TEXT NULL DEFAULT NULL,
              `status` ENUM('Pending', 'Approved', 'Rejected', 'Account Created', 'Cancelled', 'Expired', 'Awaiting Payment') NOT NULL DEFAULT 'Awaiting Payment',
              `admin_notes` TEXT NULL DEFAULT NULL,
              `account_creation_token_hash` VARCHAR(64) NULL DEFAULT NULL,
              `token_expires_at` DATETIME NULL DEFAULT NULL,
              `token_used_at` DATETIME NULL DEFAULT NULL,
              `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
              `email_error` TEXT NULL DEFAULT NULL,
              `approved_at` DATETIME NULL DEFAULT NULL,
              `approved_by` INT(11) NULL DEFAULT NULL,
              `rejected_at` DATETIME NULL DEFAULT NULL,
              `rejected_by` INT(11) NULL DEFAULT NULL,
              `account_created_at` DATETIME NULL DEFAULT NULL,
              `cancelled_at` DATETIME NULL DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_grr_user_id` (`user_id`),
              KEY `idx_grr_plot_id` (`plot_id`),
              KEY `idx_grr_email` (`email`),
              KEY `idx_grr_status` (`status`),
              KEY `idx_grr_token` (`account_creation_token_hash`),
              KEY `idx_grr_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $grrCols = [
                'request_type' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `request_type` VARCHAR(50) NOT NULL DEFAULT 'guest' AFTER `booking_id`",
                'deceased_first_name' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_first_name` VARCHAR(100) NULL AFTER `notes`",
                'deceased_middle_name' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_middle_name` VARCHAR(100) NULL AFTER `deceased_first_name`",
                'deceased_last_name' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_last_name` VARCHAR(100) NULL AFTER `deceased_middle_name`",
                'deceased_suffix' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_suffix` VARCHAR(20) NULL AFTER `deceased_last_name`",
                'deceased_bday' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_bday` DATE NULL AFTER `deceased_suffix`",
                'deceased_date_died' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_date_died` DATE NULL AFTER `deceased_bday`",
                'deceased_relationship' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_relationship` VARCHAR(50) NULL AFTER `deceased_date_died`",
                'funeral_home' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `funeral_home` VARCHAR(150) NULL AFTER `deceased_relationship`",
                'matched_deceased_id' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `matched_deceased_id` INT(11) NULL AFTER `funeral_home`",
                'deceased_full_name' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_full_name` VARCHAR(255) NULL AFTER `matched_deceased_id`",
                'payment_nonce' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_nonce` VARCHAR(64) NULL AFTER `deceased_full_name`",
                'payment_status' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_status` VARCHAR(20) NOT NULL DEFAULT 'UNPAID' AFTER `payment_nonce`",
                'payment_amount' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `payment_status`",
                'payment_date' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_date` DATETIME NULL AFTER `payment_amount`",
                'gcash_reference' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `gcash_reference` VARCHAR(100) NULL AFTER `payment_date`",
                'payment_receipt_id' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_receipt_id` INT(11) NULL AFTER `gcash_reference`",
                'proof_filename' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `proof_filename` VARCHAR(255) NULL AFTER `payment_receipt_id`",
                'payment_reference' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_reference` VARCHAR(100) NULL AFTER `proof_filename`",
                'cancellation_reason' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `cancellation_reason` TEXT NULL AFTER `admin_notes`",
                'cancellation_requested' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `cancellation_requested` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cancellation_reason`",
                'cancellation_requested_at' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `cancellation_requested_at` DATETIME NULL AFTER `cancellation_requested`",
                'cancelled_by' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `cancelled_by` VARCHAR(100) NULL AFTER `cancelled_at`",
                'payment_plan' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_plan` VARCHAR(20) NOT NULL DEFAULT 'installment' AFTER `cancelled_by`",
                'payment_term' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_term` INT(11) NOT NULL DEFAULT 36 AFTER `payment_plan`",
                'downpayment_amount' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `downpayment_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `payment_term`",
                'downpayment' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `downpayment` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `downpayment_amount`",
                'monthly_due' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `monthly_due` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `downpayment`",
                'first_due_date' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `first_due_date` DATE NULL DEFAULT NULL AFTER `monthly_due`"
            ];
            foreach ($grrCols as $colName => $alterSql) {
                $check = @$conn->query("SHOW COLUMNS FROM `guest_reservation_requests` LIKE '{$colName}'");
                if ($check && $check->num_rows === 0) {
                    @$conn->query($alterSql);
                }
            }

            $chkBkCancel = @$conn->query("SHOW COLUMNS FROM `tbl_bookings` LIKE 'cancelled_by'");
            if ($chkBkCancel && $chkBkCancel->num_rows === 0) {
                @$conn->query("ALTER TABLE `tbl_bookings` ADD COLUMN `cancelled_by` VARCHAR(100) NULL AFTER `cancellation_reason`");
                @$conn->query("ALTER TABLE `tbl_bookings` ADD COLUMN `cancelled_at` DATETIME NULL AFTER `cancelled_by`");
            }

            $chk = @$conn->query("SHOW COLUMNS FROM tbl_bookings LIKE 'registration_token'");
            if ($chk && $chk->num_rows === 0) {
                @$conn->query("ALTER TABLE tbl_bookings ADD COLUMN is_guest_booking TINYINT(1) DEFAULT 0 AFTER status");
                @$conn->query("ALTER TABLE tbl_bookings ADD COLUMN registration_token VARCHAR(64) NULL AFTER is_guest_booking");
                @$conn->query("ALTER TABLE tbl_bookings ADD COLUMN token_expires_at DATETIME NULL AFTER registration_token");
                @$conn->query("ALTER TABLE tbl_bookings ADD COLUMN token_used TINYINT(1) DEFAULT 0 AFTER token_expires_at");
                @$conn->query("ALTER TABLE tbl_bookings ADD COLUMN token_used_at DATETIME NULL AFTER token_used");
                @$conn->query("CREATE INDEX idx_registration_token ON tbl_bookings (registration_token)");
            }
            @$conn->query("ALTER TABLE tbl_bookings MODIFY COLUMN user_id INT(11) NULL DEFAULT NULL");
            @$conn->query("ALTER TABLE tbl_user MODIFY COLUMN suffix VARCHAR(50) NULL DEFAULT ''");
            @$conn->query("ALTER TABLE tbl_user MODIFY COLUMN birthday DATE NULL");
            @$conn->query("ALTER TABLE tbl_user MODIFY COLUMN phone_no VARCHAR(20) NULL DEFAULT ''");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('find_matching_cemetery_deceased')) {

    function find_matching_cemetery_deceased($conn, $firstName, $lastName, $bday = '', $dateDied = '') {
        if (!($conn instanceof mysqli) || $conn->connect_errno) return null;
        $fn = strtoupper(trim((string)$firstName));
        $ln = strtoupper(trim((string)$lastName));
        if ($fn === '' && $ln === '') return null;

        $bdayClean = !empty($bday) ? trim((string)$bday) : '';
        $diedClean = !empty($dateDied) ? trim((string)$dateDied) : '';

        if ($fn !== '' && $ln !== '' && ($bdayClean !== '' || $diedClean !== '')) {
            $sql = "SELECT deceased_id, first_name, middle_name, last_name, suffix, bday, date_died, section, plot_x, plot_y, user_id 
                    FROM tbl_deceased 
                    WHERE UPPER(TRIM(first_name)) = ? AND UPPER(TRIM(last_name)) = ?";
            $types = 'ss';
            $params = [$fn, $ln];

            if ($diedClean !== '' && $bdayClean !== '') {
                $sql .= " AND (date_died = ? OR bday = ?)";
                $types .= 'ss';
                $params[] = $diedClean;
                $params[] = $bdayClean;
            } elseif ($diedClean !== '') {
                $sql .= " AND date_died = ?";
                $types .= 's';
                $params[] = $diedClean;
            } elseif ($bdayClean !== '') {
                $sql .= " AND bday = ?";
                $types .= 's';
                $params[] = $bdayClean;
            }
            $sql .= " ORDER BY deceased_id ASC LIMIT 1";

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $stmt->close();
                    return $row;
                }
                $stmt->close();
            }
        }

        if ($fn !== '' && $ln !== '') {
            $stmt = $conn->prepare("SELECT deceased_id, first_name, middle_name, last_name, suffix, bday, date_died, section, plot_x, plot_y, user_id 
                                   FROM tbl_deceased 
                                   WHERE UPPER(TRIM(first_name)) = ? AND UPPER(TRIM(last_name)) = ? 
                                   ORDER BY deceased_id ASC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ss', $fn, $ln);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $stmt->close();
                    return $row;
                }
                $stmt->close();
            }
        }

        $chkCol = @$conn->query("SHOW COLUMNS FROM tbl_deceased LIKE 'full_name'");
        if ($chkCol && $chkCol->num_rows > 0 && ($fn !== '' || $ln !== '')) {
            $combined = trim($fn . ' ' . $ln);
            $stmt = $conn->prepare("SELECT deceased_id, first_name, middle_name, last_name, suffix, bday, date_died, section, plot_x, plot_y, user_id 
                                   FROM tbl_deceased 
                                   WHERE UPPER(TRIM(full_name)) = ? OR UPPER(TRIM(full_name)) LIKE ? 
                                   ORDER BY deceased_id ASC LIMIT 1");
            if ($stmt) {
                $likeCombined = '%' . $combined . '%';
                $stmt->bind_param('ss', $combined, $likeCombined);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $stmt->close();
                    return $row;
                }
                $stmt->close();
            }
        }

        return null;
    }
}

if (!function_exists('ensure_plot_coordinates_columns')) {
    function ensure_plot_coordinates_columns($conn) {
        if (is_schema_migrated()) {
            return;
        }
        static $checked = false;
        if ($checked || !($conn instanceof mysqli) || $conn->connect_errno) {
            return;
        }
        $checked = true;
        try {

            $checkPlotsTbl = @$conn->query("SHOW TABLES LIKE 'tbl_plots'");
            if ($checkPlotsTbl && $checkPlotsTbl->num_rows > 0) {
                $chkLat = @$conn->query("SHOW COLUMNS FROM `tbl_plots` LIKE 'latitude'");
                if ($chkLat && $chkLat->num_rows === 0) {
                    @$conn->query("ALTER TABLE `tbl_plots` ADD COLUMN `latitude` DECIMAL(10,8) NULL DEFAULT NULL AFTER `price`, ADD COLUMN `longitude` DECIMAL(11,8) NULL DEFAULT NULL AFTER `latitude`");
                }
                $chkSrc = @$conn->query("SHOW COLUMNS FROM `tbl_plots` LIKE 'coordinate_source'");
                if ($chkSrc && $chkSrc->num_rows === 0) {
                    @$conn->query("ALTER TABLE `tbl_plots` ADD COLUMN `coordinate_source` VARCHAR(20) DEFAULT 'calculated' AFTER `longitude`, ADD COLUMN `coordinate_accuracy` VARCHAR(50) DEFAULT 'approximate (calibrated layout)' AFTER `coordinate_source`, ADD COLUMN `coordinate_updated_at` DATETIME NULL AFTER `coordinate_accuracy`");
                }
            }

            $checkSecPlotsTbl = @$conn->query("SHOW TABLES LIKE 'plots'");
            if ($checkSecPlotsTbl && $checkSecPlotsTbl->num_rows > 0) {
                $chkPlotsLat = @$conn->query("SHOW COLUMNS FROM `plots` LIKE 'latitude'");
                if ($chkPlotsLat && $chkPlotsLat->num_rows === 0) {
                    @$conn->query("ALTER TABLE `plots` ADD COLUMN `latitude` DECIMAL(10,8) NULL DEFAULT NULL, ADD COLUMN `longitude` DECIMAL(11,8) NULL DEFAULT NULL");
                }
                $chkSecSrc = @$conn->query("SHOW COLUMNS FROM `plots` LIKE 'coordinate_source'");
                if ($chkSecSrc && $chkSecSrc->num_rows === 0) {
                    @$conn->query("ALTER TABLE `plots` ADD COLUMN `coordinate_source` VARCHAR(20) DEFAULT 'calculated', ADD COLUMN `coordinate_accuracy` VARCHAR(50) DEFAULT 'approximate (calibrated layout)', ADD COLUMN `coordinate_updated_at` DATETIME NULL");
                }
            }

            // Ensure GPS calibration and settings tables exist for accurate cemetery and plot navigation
            $chkGpsCalib = @$conn->query("SHOW TABLES LIKE 'tbl_cemetery_gps_calibration'");
            if ($chkGpsCalib && $chkGpsCalib->num_rows === 0) {
                @$conn->query("CREATE TABLE IF NOT EXISTS `tbl_cemetery_gps_calibration` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `point_name` VARCHAR(150) NOT NULL,
                    `section` VARCHAR(10) NULL DEFAULT NULL,
                    `map_x` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `map_y` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `latitude` DECIMAL(10,8) NOT NULL,
                    `longitude` DECIMAL(11,8) NOT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `notes` TEXT NULL DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }

            $chkGpsSettings = @$conn->query("SHOW TABLES LIKE 'tbl_cemetery_gps_settings'");
            if ($chkGpsSettings && $chkGpsSettings->num_rows === 0) {
                @$conn->query("CREATE TABLE IF NOT EXISTS `tbl_cemetery_gps_settings` (
                    `setting_key` VARCHAR(100) NOT NULL,
                    `setting_value` VARCHAR(255) NOT NULL,
                    PRIMARY KEY (`setting_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                @$conn->query("INSERT IGNORE INTO `tbl_cemetery_gps_settings` (`setting_key`, `setting_value`) VALUES
                    ('cemetery_ref_lat', '16.15024000'),
                    ('cemetery_ref_lng', '119.98232000'),
                    ('orientation_deg', '-15.5'),
                    ('meters_per_pixel_x', '0.31'),
                    ('meters_per_pixel_y', '0.31'),
                    ('enable_auto_calculation', '1')");
            }
        } catch (Throwable $e) {
            error_log('ensure_plot_coordinates_columns error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_service_workflow_columns')) {
    function ensure_service_workflow_columns($conn) {
        if (is_schema_migrated()) {
            return;
        }
        static $checked = false;
        if ($checked || !($conn instanceof mysqli) || $conn->connect_errno) {
            return;
        }
        $checked = true;
        try {
            $checkBookings = @$conn->query("SHOW TABLES LIKE 'tbl_bookings'");
            if ($checkBookings && $checkBookings->num_rows > 0) {
                $cols = [
                    'service_status' => "ALTER TABLE `tbl_bookings` ADD COLUMN `service_status` VARCHAR(30) NOT NULL DEFAULT 'PENDING' AFTER `status`",
                    'service_completion_proof' => "ALTER TABLE `tbl_bookings` ADD COLUMN `service_completion_proof` VARCHAR(255) NULL DEFAULT NULL AFTER `service_status`",
                    'service_completed_at' => "ALTER TABLE `tbl_bookings` ADD COLUMN `service_completed_at` DATETIME NULL DEFAULT NULL AFTER `service_completion_proof`",
                    'service_completed_by' => "ALTER TABLE `tbl_bookings` ADD COLUMN `service_completed_by` INT(11) NULL DEFAULT NULL AFTER `service_completed_at`"
                ];
                foreach ($cols as $colName => $alterSql) {
                    $chk = @$conn->query("SHOW COLUMNS FROM `tbl_bookings` LIKE '{$colName}'");
                    if ($chk && $chk->num_rows === 0) {
                        @$conn->query($alterSql);
                    }
                }
            }

            // Ensure upload directories exist
            $uploadDirs = [
                __DIR__ . '/uploads',
                __DIR__ . '/uploads/payment-proofs',
                __DIR__ . '/uploads/service-proofs'
            ];
            foreach ($uploadDirs as $dir) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                }
            }
        } catch (Throwable $e) {
            error_log('ensure_service_workflow_columns error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_booking_table_integrity')) {
    function ensure_booking_table_integrity($conn) {
        if (is_schema_migrated()) {
            return;
        }
        static $checked = false;
        if ($checked || !($conn instanceof mysqli) || $conn->connect_errno) {
            return;
        }
        $checked = true;
        try {
            // 1. Ensure tbl_bookings exists and has all required columns
            $checkBookings = @$conn->query("SHOW TABLES LIKE 'tbl_bookings'");
            if ($checkBookings && $checkBookings->num_rows > 0) {
                $existingCols = [];
                $colRes = @$conn->query("SHOW COLUMNS FROM `tbl_bookings`");
                if ($colRes) {
                    while ($c = $colRes->fetch_assoc()) {
                        $existingCols[strtolower($c['Field'])] = true;
                    }
                }

                $columnsToAdd = [
                    'section_plot'             => "ALTER TABLE `tbl_bookings` ADD COLUMN `section_plot` VARCHAR(20) NULL DEFAULT NULL AFTER `plot`",
                    'plot_type'                => "ALTER TABLE `tbl_bookings` ADD COLUMN `plot_type` VARCHAR(50) NULL DEFAULT NULL AFTER `grave_location`",
                    'plot_price'               => "ALTER TABLE `tbl_bookings` ADD COLUMN `plot_price` DECIMAL(10,2) NULL DEFAULT 0.00 AFTER `section_plot`",
                    'amount_paid'              => "ALTER TABLE `tbl_bookings` ADD COLUMN `amount_paid` DECIMAL(10,2) NULL DEFAULT 0.00 AFTER `payment_type`",
                    'remaining_balance'        => "ALTER TABLE `tbl_bookings` ADD COLUMN `remaining_balance` DECIMAL(10,2) NULL DEFAULT 0.00 AFTER `amount_paid`",
                    'payment_status'           => "ALTER TABLE `tbl_bookings` ADD COLUMN `payment_status` VARCHAR(20) NULL DEFAULT 'PENDING' AFTER `remaining_balance`",
                    'reference_number'         => "ALTER TABLE `tbl_bookings` ADD COLUMN `reference_number` VARCHAR(100) NULL DEFAULT NULL AFTER `payment_status`",
                    'bank_name'                => "ALTER TABLE `tbl_bookings` ADD COLUMN `bank_name` VARCHAR(100) NULL DEFAULT NULL AFTER `reference_number`",
                    'payment_proof_filename'   => "ALTER TABLE `tbl_bookings` ADD COLUMN `payment_proof_filename` VARCHAR(255) NULL DEFAULT NULL AFTER `bank_name`",
                    'gcash_reference_number'   => "ALTER TABLE `tbl_bookings` ADD COLUMN `gcash_reference_number` VARCHAR(100) NULL DEFAULT NULL AFTER `payment_proof_filename`",
                    'payment_proof_uploaded_at'=> "ALTER TABLE `tbl_bookings` ADD COLUMN `payment_proof_uploaded_at` DATETIME NULL DEFAULT NULL AFTER `gcash_reference_number`",
                    'service_status'           => "ALTER TABLE `tbl_bookings` ADD COLUMN `service_status` VARCHAR(30) NOT NULL DEFAULT 'PENDING' AFTER `status`",
                    'service_completion_proof' => "ALTER TABLE `tbl_bookings` ADD COLUMN `service_completion_proof` VARCHAR(255) NULL DEFAULT NULL AFTER `service_status`",
                    'service_completed_at'     => "ALTER TABLE `tbl_bookings` ADD COLUMN `service_completed_at` DATETIME NULL DEFAULT NULL AFTER `service_completion_proof`",
                    'service_completed_by'     => "ALTER TABLE `tbl_bookings` ADD COLUMN `service_completed_by` INT(11) NULL DEFAULT NULL AFTER `service_completed_at`",
                    'is_guest_booking'         => "ALTER TABLE `tbl_bookings` ADD COLUMN `is_guest_booking` TINYINT(1) DEFAULT 0 AFTER `status`",
                    'registration_token'       => "ALTER TABLE `tbl_bookings` ADD COLUMN `registration_token` VARCHAR(64) NULL AFTER `is_guest_booking`",
                    'token_expires_at'         => "ALTER TABLE `tbl_bookings` ADD COLUMN `token_expires_at` DATETIME NULL AFTER `registration_token`",
                    'token_used'               => "ALTER TABLE `tbl_bookings` ADD COLUMN `token_used` TINYINT(1) DEFAULT 0 AFTER `token_expires_at`",
                    'token_used_at'            => "ALTER TABLE `tbl_bookings` ADD COLUMN `token_used_at` DATETIME NULL AFTER `token_used`",
                    'plot_id'                  => "ALTER TABLE `tbl_bookings` ADD COLUMN `plot_id` INT(11) NULL DEFAULT NULL AFTER `user_id`",
                    'deceased_id'              => "ALTER TABLE `tbl_bookings` ADD COLUMN `deceased_id` INT(11) NULL DEFAULT NULL AFTER `plot_id`",
                    'booking_type'             => "ALTER TABLE `tbl_bookings` ADD COLUMN `booking_type` VARCHAR(40) NOT NULL DEFAULT 'general' AFTER `deceased_id`",
                    'customer_name'            => "ALTER TABLE `tbl_bookings` ADD COLUMN `customer_name` VARCHAR(255) NULL DEFAULT NULL AFTER `created_at`",
                    'customer_email'           => "ALTER TABLE `tbl_bookings` ADD COLUMN `customer_email` VARCHAR(255) NULL DEFAULT NULL AFTER `customer_name`",
                    'full_name'                => "ALTER TABLE `tbl_bookings` ADD COLUMN `full_name` VARCHAR(255) NULL DEFAULT NULL AFTER `customer_email`",
                    'deceased_name'            => "ALTER TABLE `tbl_bookings` ADD COLUMN `deceased_name` VARCHAR(255) NULL DEFAULT NULL AFTER `full_name`",
                    'email'                    => "ALTER TABLE `tbl_bookings` ADD COLUMN `email` VARCHAR(255) NULL DEFAULT NULL AFTER `deceased_name`",
                    'purpose'                  => "ALTER TABLE `tbl_bookings` ADD COLUMN `purpose` VARCHAR(30) NULL DEFAULT 'reserve_grave' AFTER `email`",
                    'payment_plan'             => "ALTER TABLE `tbl_bookings` ADD COLUMN `payment_plan` VARCHAR(20) NULL DEFAULT 'full' AFTER `purpose`",
                    'payment_term'             => "ALTER TABLE `tbl_bookings` ADD COLUMN `payment_term` INT(11) NULL DEFAULT 0 AFTER `payment_plan`",
                    'interment_fee'            => "ALTER TABLE `tbl_bookings` ADD COLUMN `interment_fee` DECIMAL(10,2) NULL DEFAULT 0.00 AFTER `payment_term`",
                    'service_price'            => "ALTER TABLE `tbl_bookings` ADD COLUMN `service_price` DECIMAL(10,2) NULL DEFAULT 0.00 AFTER `interment_fee`",
                    'total_amount'             => "ALTER TABLE `tbl_bookings` ADD COLUMN `total_amount` DECIMAL(10,2) NULL DEFAULT 0.00 AFTER `service_price`",
                    'num_lots'                 => "ALTER TABLE `tbl_bookings` ADD COLUMN `num_lots` INT(11) NULL DEFAULT 1 AFTER `total_amount`",
                    'area'                     => "ALTER TABLE `tbl_bookings` ADD COLUMN `area` VARCHAR(50) NULL DEFAULT NULL AFTER `num_lots`",
                    'source'                   => "ALTER TABLE `tbl_bookings` ADD COLUMN `source` VARCHAR(20) NULL DEFAULT NULL AFTER `area`",
                    'remarks'                  => "ALTER TABLE `tbl_bookings` ADD COLUMN `remarks` TEXT NULL DEFAULT NULL AFTER `special_requests`",
                    'original_payment_method'  => "ALTER TABLE `tbl_bookings` ADD COLUMN `original_payment_method` VARCHAR(50) NULL DEFAULT NULL AFTER `payment_type`"
                ];

                foreach ($columnsToAdd as $colKey => $sql) {
                    if (!isset($existingCols[strtolower($colKey)])) {
                        @$conn->query($sql);
                    }
                }
            }

            // 2. Ensure tbl_user has reset columns
            $checkUser = @$conn->query("SHOW TABLES LIKE 'tbl_user'");
            if ($checkUser && $checkUser->num_rows > 0) {
                $uCols = [];
                $uColRes = @$conn->query("SHOW COLUMNS FROM `tbl_user`");
                if ($uColRes) {
                    while ($uc = $uColRes->fetch_assoc()) {
                        $uCols[strtolower($uc['Field'])] = true;
                    }
                }
                $userColsToAdd = [
                    'reset_otp'        => "ALTER TABLE `tbl_user` ADD COLUMN `reset_otp` VARCHAR(10) DEFAULT NULL",
                    'reset_otp_expiry' => "ALTER TABLE `tbl_user` ADD COLUMN `reset_otp_expiry` DATETIME DEFAULT NULL",
                    'reset_token'      => "ALTER TABLE `tbl_user` ADD COLUMN `reset_token` VARCHAR(100) DEFAULT NULL",
                    'reset_expires'    => "ALTER TABLE `tbl_user` ADD COLUMN `reset_expires` DATETIME DEFAULT NULL"
                ];
                foreach ($userColsToAdd as $uk => $usql) {
                    if (!isset($uCols[strtolower($uk)])) {
                        @$conn->query($usql);
                    }
                }
            }

            // 3. Ensure companion tables exist
            @$conn->query("CREATE TABLE IF NOT EXISTS `tbl_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `action` varchar(255) NOT NULL,
              `details` text DEFAULT NULL,
              `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
              `user_id` int(11) DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            @$conn->query("CREATE TABLE IF NOT EXISTS `payment_receipts` (
              `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `booking_id` int(11) NOT NULL,
              `receipt_number` varchar(50) NOT NULL,
              `amount` decimal(10,2) NOT NULL,
              `payment_method` varchar(50) NOT NULL,
              `payment_type` varchar(50) DEFAULT 'full',
              `installment_number` int(11) DEFAULT NULL,
              `reference_number` varchar(100) DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `received_by` varchar(100) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Ensure payment_receipts columns exist
            $checkPr = @$conn->query("SHOW TABLES LIKE 'payment_receipts'");
            if ($checkPr && $checkPr->num_rows > 0) {
                $prCols = [];
                $prColRes = @$conn->query("SHOW COLUMNS FROM `payment_receipts`");
                if ($prColRes) {
                    while ($prc = $prColRes->fetch_assoc()) {
                        $prCols[strtolower($prc['Field'])] = true;
                    }
                }
                $prColsToAdd = [
                    'user_id'                => "ALTER TABLE `payment_receipts` ADD COLUMN `user_id` INT(11) NULL DEFAULT NULL AFTER `booking_id`",
                    'payment_schedule_id'    => "ALTER TABLE `payment_receipts` ADD COLUMN `payment_schedule_id` INT(11) NULL DEFAULT NULL AFTER `user_id`",
                    'installment_number'     => "ALTER TABLE `payment_receipts` ADD COLUMN `installment_number` INT(11) NULL DEFAULT NULL AFTER `payment_schedule_id`",
                    'amount_paid'            => "ALTER TABLE `payment_receipts` ADD COLUMN `amount_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `installment_number`",
                    'gcash_reference_number' => "ALTER TABLE `payment_receipts` ADD COLUMN `gcash_reference_number` VARCHAR(100) NULL DEFAULT NULL AFTER `payment_method`",
                    'transaction_reference'  => "ALTER TABLE `payment_receipts` ADD COLUMN `transaction_reference` VARCHAR(100) NULL DEFAULT NULL AFTER `gcash_reference_number`",
                    'payment_date'           => "ALTER TABLE `payment_receipts` ADD COLUMN `payment_date` DATETIME NULL DEFAULT NULL AFTER `transaction_reference`",
                    'payment_status'         => "ALTER TABLE `payment_receipts` ADD COLUMN `payment_status` VARCHAR(30) DEFAULT 'PAID' AFTER `payment_date`",
                    'proof_filename'         => "ALTER TABLE `payment_receipts` ADD COLUMN `proof_filename` VARCHAR(255) NULL DEFAULT NULL AFTER `payment_status`",
                    'receipt_path'           => "ALTER TABLE `payment_receipts` ADD COLUMN `receipt_path` VARCHAR(255) NULL DEFAULT NULL AFTER `receipt_number`",
                    'verified_by'            => "ALTER TABLE `payment_receipts` ADD COLUMN `verified_by` INT(11) NULL DEFAULT NULL AFTER `receipt_path`",
                    'verified_at'            => "ALTER TABLE `payment_receipts` ADD COLUMN `verified_at` DATETIME NULL DEFAULT NULL AFTER `verified_by`",
                    'rejection_reason'       => "ALTER TABLE `payment_receipts` ADD COLUMN `rejection_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `verified_at`",
                    'updated_at'             => "ALTER TABLE `payment_receipts` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`"
                ];
                foreach ($prColsToAdd as $prKey => $prSql) {
                    if (!isset($prCols[strtolower($prKey)])) {
                        @$conn->query($prSql);
                    }
                }
            }

            @$conn->query("CREATE TABLE IF NOT EXISTS `payment_schedules` (
              `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `booking_id` int(11) NOT NULL,
              `installment_number` int(11) NOT NULL,
              `due_date` date NOT NULL,
              `amount_due` decimal(10,2) NOT NULL,
              `status` enum('PENDING','PAID','OVERDUE') NOT NULL DEFAULT 'PENDING',
              `paid_at` datetime DEFAULT NULL,
              `payment_receipt_id` int(11) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            @$conn->query("CREATE TABLE IF NOT EXISTS `booking_payment_schedule` (
              `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `booking_id` int(11) NOT NULL,
              `installment_number` int(11) NOT NULL,
              `due_date` date NOT NULL,
              `amount_due` decimal(10,2) NOT NULL,
              `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
              `status` enum('PENDING','PAID','OVERDUE','PARTIAL') NOT NULL DEFAULT 'PENDING',
              `paid_date` datetime DEFAULT NULL,
              `payment_method` varchar(50) DEFAULT NULL,
              `reference_number` varchar(100) DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            @$conn->query("CREATE TABLE IF NOT EXISTS `tbl_plot_owners` (
              `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `user_id` int(11) NOT NULL,
              `section` varchar(10) NOT NULL,
              `plot_x` int(11) NOT NULL,
              `plot_y` int(11) NOT NULL,
              `plot_number` varchar(50) DEFAULT NULL,
              `booking_id` int(11) DEFAULT NULL,
              `status` varchar(20) DEFAULT 'active',
              `created_at` timestamp NOT NULL DEFAULT current_timestamp()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            @$conn->query("CREATE TABLE IF NOT EXISTS `booking_plots` (
              `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `booking_id` int(11) NOT NULL,
              `plot_id` int(11) DEFAULT NULL,
              `section` varchar(10) NOT NULL,
              `plot_x` int(11) DEFAULT NULL,
              `plot_y` int(11) DEFAULT NULL,
              `plot_number` varchar(50) DEFAULT NULL,
              `lawn_type` varchar(50) DEFAULT 'Regular',
              `price_at_booking` decimal(10,2) DEFAULT 85000.00,
              `status` varchar(20) DEFAULT 'reserved',
              `created_at` timestamp NOT NULL DEFAULT current_timestamp()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            @$conn->query("CREATE TABLE IF NOT EXISTS `user_plot_ownership` (
              `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `user_id` int(11) NOT NULL,
              `booking_id` int(11) DEFAULT NULL,
              `plot_id` int(11) DEFAULT NULL,
              `section` varchar(10) NOT NULL,
              `plot_x` int(11) NOT NULL,
              `plot_y` int(11) NOT NULL,
              `plot_number` varchar(50) DEFAULT NULL,
              `lawn_type` varchar(50) DEFAULT 'Regular',
              `status` varchar(20) DEFAULT 'occupied',
              `created_at` timestamp NOT NULL DEFAULT current_timestamp()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Ensure tbl_booking_logs companion table exists (preserving existing structure)
            @$conn->query("CREATE TABLE IF NOT EXISTS `tbl_booking_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `booking_id` int(11) NOT NULL,
              `section` varchar(50) NOT NULL,
              `contact_number` varchar(20) NOT NULL,
              `booker_location` text NOT NULL,
              `price` decimal(10,2) NOT NULL,
              `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
              KEY `idx_booking_id` (`booking_id`),
              KEY `idx_timestamp` (`timestamp`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1");

            // Automatically backfill any existing tbl_bookings records into tbl_booking_logs if missing
            @$conn->query("INSERT INTO `tbl_booking_logs` (`booking_id`, `section`, `contact_number`, `booker_location`, `price`, `timestamp`)
            SELECT 
                b.id,
                COALESCE(NULLIF(TRIM(b.section), ''), '—'),
                COALESCE(NULLIF(TRIM(b.contact_number), ''), '—'),
                COALESCE(NULLIF(TRIM(b.booker_location), ''), 'Memorial Park Office'),
                COALESCE(b.total_amount, b.plot_price, 0.00),
                COALESCE(b.created_at, NOW())
            FROM `tbl_bookings` b
            WHERE NOT EXISTS (
                SELECT 1 FROM `tbl_booking_logs` bl WHERE bl.booking_id = b.id
            )
            ORDER BY b.id ASC");

            // Note: sales_reports synchronization is performed on sales_reports.php

        } catch (Throwable $e) {
            error_log('ensure_booking_table_integrity error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('sync_sales_report_table')) {
    function sync_sales_report_table($conn) {
        static $synced = false;
        if ($synced || !($conn instanceof mysqli) || $conn->connect_errno) {
            return false;
        }
        $synced = true;

        try {
            // Ensure sales_reports table exists in cemetery2_db
            $conn->query("CREATE TABLE IF NOT EXISTS `sales_reports` (
              `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `booking_id` int(11) NOT NULL,
              `user_id` int(11) DEFAULT NULL,
              `customer_name` varchar(255) DEFAULT 'Customer',
              `customer_email` varchar(255) DEFAULT 'N/A',
              `contact_number` varchar(50) DEFAULT '—',
              `service_type` varchar(150) DEFAULT 'Reserve Grave',
              `section` varchar(50) DEFAULT '—',
              `plot` varchar(50) DEFAULT '—',
              `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
              `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
              `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
              `payment_status` varchar(50) NOT NULL DEFAULT 'PENDING',
              `payment_method` varchar(50) DEFAULT 'GCash',
              `reference_number` varchar(100) DEFAULT '—',
              `report_month` varchar(7) NOT NULL,
              `payment_date` datetime DEFAULT NULL,
              `booking_date` datetime DEFAULT NULL,
              `booking_status` varchar(50) NOT NULL DEFAULT 'pending',
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              UNIQUE KEY `uk_booking_id` (`booking_id`),
              KEY `idx_report_month` (`report_month`),
              KEY `idx_payment_status` (`payment_status`),
              KEY `idx_booking_status` (`booking_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Also ensure alias table sales_report exists
            $conn->query("CREATE TABLE IF NOT EXISTS `sales_report` (
              `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `booking_id` int(11) NOT NULL,
              `user_id` int(11) DEFAULT NULL,
              `customer_name` varchar(255) DEFAULT 'Customer',
              `customer_email` varchar(255) DEFAULT 'N/A',
              `contact_number` varchar(50) DEFAULT '—',
              `service_type` varchar(150) DEFAULT 'Reserve Grave',
              `section` varchar(50) DEFAULT '—',
              `plot` varchar(50) DEFAULT '—',
              `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
              `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
              `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
              `payment_status` varchar(50) NOT NULL DEFAULT 'PENDING',
              `payment_method` varchar(50) DEFAULT 'GCash',
              `reference_number` varchar(100) DEFAULT '—',
              `report_month` varchar(7) NOT NULL,
              `payment_date` datetime DEFAULT NULL,
              `booking_date` datetime DEFAULT NULL,
              `booking_status` varchar(50) NOT NULL DEFAULT 'pending',
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              UNIQUE KEY `uk_sr_booking_id` (`booking_id`),
              KEY `idx_sr_report_month` (`report_month`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Populate all booking records into sales_reports
            $conn->query("INSERT INTO `sales_reports` (
                `booking_id`, `user_id`, `customer_name`, `customer_email`, `contact_number`,
                `service_type`, `section`, `plot`, `total_amount`, `amount_paid`, `balance`,
                `payment_status`, `payment_method`, `reference_number`, `report_month`,
                `payment_date`, `booking_date`, `booking_status`, `created_at`
            )
            SELECT 
                b.id AS booking_id,
                b.user_id,
                COALESCE(NULLIF(TRIM(b.customer_name), ''), NULLIF(TRIM(b.full_name), ''), CONCAT_WS(' ', u.first_name, u.last_name), 'Customer') AS customer_name,
                COALESCE(NULLIF(TRIM(b.customer_email), ''), NULLIF(TRIM(b.email), ''), u.email, 'N/A') AS customer_email,
                COALESCE(NULLIF(TRIM(b.contact_number), ''), '—') AS contact_number,
                COALESCE(NULLIF(TRIM(b.service_type), ''), NULLIF(TRIM(b.purpose), ''), 'Reserve Grave') AS service_type,
                COALESCE(NULLIF(TRIM(b.section), ''), '—') AS section,
                COALESCE(NULLIF(TRIM(b.plot), ''), '—') AS plot,
                COALESCE(b.total_amount, b.plot_price, 0.00) AS total_amount,
                COALESCE(pr_totals.tot_paid, b.amount_paid, 0.00) AS amount_paid,
                GREATEST(0.00, COALESCE(b.total_amount, b.plot_price, 0.00) - COALESCE(pr_totals.tot_paid, b.amount_paid, 0.00)) AS balance,
                CASE 
                    WHEN LOWER(TRIM(b.status)) IN ('rejected', 'cancelled') THEN UPPER(TRIM(b.status))
                    WHEN COALESCE(pr_totals.tot_paid, b.amount_paid, 0.00) >= COALESCE(b.total_amount, b.plot_price, 0.00) AND COALESCE(b.total_amount, b.plot_price, 0.00) > 0 THEN 'PAID'
                    WHEN COALESCE(pr_totals.tot_paid, b.amount_paid, 0.00) > 0 THEN 'PARTIAL'
                    ELSE 'UNPAID'
                END AS payment_status,
                COALESCE(NULLIF(TRIM(b.payment_type), ''), 'GCash') AS payment_method,
                COALESCE(NULLIF(TRIM(b.reference_number), ''), NULLIF(TRIM(b.gcash_reference_number), ''), '—') AS reference_number,
                DATE_FORMAT(COALESCE(b.created_at, NOW()), '%Y-%m') AS report_month,
                COALESCE(pr_totals.latest_payment_date, b.created_at) AS payment_date,
                COALESCE(b.created_at, NOW()) AS booking_date,
                COALESCE(b.status, 'pending') AS booking_status,
                COALESCE(b.created_at, NOW()) AS created_at
            FROM `tbl_bookings` b
            LEFT JOIN `tbl_user` u ON u.user_id = b.user_id
            LEFT JOIN (
                SELECT booking_id, SUM(amount_paid) AS tot_paid, MAX(payment_date) AS latest_payment_date
                FROM payment_receipts
                WHERE UPPER(COALESCE(payment_status, 'PAID')) = 'PAID'
                GROUP BY booking_id
            ) pr_totals ON pr_totals.booking_id = b.id
            ON DUPLICATE KEY UPDATE
                `customer_name` = VALUES(`customer_name`),
                `customer_email` = VALUES(`customer_email`),
                `contact_number` = VALUES(`contact_number`),
                `service_type` = VALUES(`service_type`),
                `section` = VALUES(`section`),
                `plot` = VALUES(`plot`),
                `total_amount` = VALUES(`total_amount`),
                `amount_paid` = VALUES(`amount_paid`),
                `balance` = VALUES(`balance`),
                `payment_status` = VALUES(`payment_status`),
                `booking_status` = VALUES(`booking_status`),
                `report_month` = VALUES(`report_month`),
                `updated_at` = NOW()");

            // Also ensure any bookings recorded in tbl_booking_logs are recorded
            $conn->query("INSERT IGNORE INTO `sales_reports` (
                `booking_id`, `section`, `contact_number`, `total_amount`, `report_month`,
                `booking_date`, `created_at`, `booking_status`, `payment_status`
            )
            SELECT 
                bl.booking_id,
                COALESCE(NULLIF(TRIM(bl.section), ''), '—'),
                COALESCE(NULLIF(TRIM(bl.contact_number), ''), '—'),
                COALESCE(bl.price, 0.00),
                DATE_FORMAT(COALESCE(bl.timestamp, NOW()), '%Y-%m'),
                COALESCE(bl.timestamp, NOW()),
                COALESCE(bl.timestamp, NOW()),
                'logged',
                'UNPAID'
            FROM `tbl_booking_logs` bl
            WHERE bl.booking_id NOT IN (SELECT booking_id FROM `sales_reports`)");

            // Keep sales_report alias updated
            $conn->query("INSERT INTO `sales_report` SELECT * FROM `sales_reports` ON DUPLICATE KEY UPDATE `updated_at` = NOW()");

            return true;
        } catch (Throwable $e) {
            error_log('sync_sales_report_table error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('record_sales_report_entry')) {
    function record_sales_report_entry($conn, $booking_id) {
        $booking_id = (int)$booking_id;
        if ($booking_id <= 0 || !($conn instanceof mysqli) || $conn->connect_errno) {
            return false;
        }

        try {
            $sql = "SELECT 
                        b.id AS booking_id,
                        b.user_id,
                        COALESCE(NULLIF(TRIM(b.customer_name), ''), NULLIF(TRIM(b.full_name), ''), CONCAT_WS(' ', u.first_name, u.last_name), 'Customer') AS customer_name,
                        COALESCE(NULLIF(TRIM(b.customer_email), ''), NULLIF(TRIM(b.email), ''), u.email, 'N/A') AS customer_email,
                        COALESCE(NULLIF(TRIM(b.contact_number), ''), '—') AS contact_number,
                        COALESCE(NULLIF(TRIM(b.service_type), ''), NULLIF(TRIM(b.purpose), ''), 'Reserve Grave') AS service_type,
                        COALESCE(NULLIF(TRIM(b.section), ''), '—') AS section,
                        COALESCE(NULLIF(TRIM(b.plot), ''), '—') AS plot,
                        COALESCE(b.total_amount, b.plot_price, 0.00) AS total_amount,
                        COALESCE(b.amount_paid, 0.00) AS b_amount_paid,
                        COALESCE(NULLIF(TRIM(b.payment_type), ''), 'GCash') AS payment_method,
                        COALESCE(NULLIF(TRIM(b.reference_number), ''), NULLIF(TRIM(b.gcash_reference_number), ''), '—') AS reference_number,
                        COALESCE(b.created_at, NOW()) AS created_at,
                        COALESCE(b.status, 'pending') AS booking_status
                    FROM `tbl_bookings` b
                    LEFT JOIN `tbl_user` u ON u.user_id = b.user_id
                    WHERE b.id = ? LIMIT 1";

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $booking_id);
                $stmt->execute();
                $bRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($bRow) {
                    $prPaid = 0.00;
                    $prDate = null;
                    $prRef = null;
                    $prQ = $conn->query("SELECT SUM(amount_paid) AS tot_paid, MAX(payment_date) AS max_date, MAX(gcash_reference_number) AS gref 
                                         FROM payment_receipts 
                                         WHERE booking_id = {$booking_id} AND (payment_status = 'PAID' OR payment_status IS NULL)");
                    if ($prQ && ($prRow = $prQ->fetch_assoc())) {
                        $prPaid = (float)($prRow['tot_paid'] ?? 0.00);
                        $prDate = $prRow['max_date'];
                        if (!empty($prRow['gref'])) $prRef = $prRow['gref'];
                    }

                    $totAmt = (float)$bRow['total_amount'];
                    $amtPaid = $prPaid > 0 ? $prPaid : (float)$bRow['b_amount_paid'];
                    $bal = max(0.00, $totAmt - $amtPaid);
                    $bStatus = $bRow['booking_status'];

                    if (in_array(strtolower($bStatus), ['cancelled', 'rejected'], true)) {
                        $payStatus = strtoupper($bStatus);
                    } elseif ($amtPaid >= $totAmt && $totAmt > 0) {
                        $payStatus = 'PAID';
                    } elseif ($amtPaid > 0) {
                        $payStatus = 'PARTIAL';
                    } else {
                        $payStatus = 'UNPAID';
                    }

                    $refNo = $prRef ?: $bRow['reference_number'];
                    $repMonth = date('Y-m', strtotime($bRow['created_at']));
                    $payDate = $prDate ?: $bRow['created_at'];

                    $insStmt = $conn->prepare("INSERT INTO `sales_reports` 
                        (`booking_id`, `user_id`, `customer_name`, `customer_email`, `contact_number`, `service_type`, `section`, `plot`, `total_amount`, `amount_paid`, `balance`, `payment_status`, `payment_method`, `reference_number`, `report_month`, `payment_date`, `booking_date`, `booking_status`, `created_at`)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        `customer_name` = VALUES(`customer_name`),
                        `customer_email` = VALUES(`customer_email`),
                        `contact_number` = VALUES(`contact_number`),
                        `service_type` = VALUES(`service_type`),
                        `section` = VALUES(`section`),
                        `plot` = VALUES(`plot`),
                        `total_amount` = VALUES(`total_amount`),
                        `amount_paid` = VALUES(`amount_paid`),
                        `balance` = VALUES(`balance`),
                        `payment_status` = VALUES(`payment_status`),
                        `payment_method` = VALUES(`payment_method`),
                        `reference_number` = VALUES(`reference_number`),
                        `report_month` = VALUES(`report_month`),
                        `payment_date` = VALUES(`payment_date`),
                        `booking_status` = VALUES(`booking_status`),
                        `updated_at` = NOW()");

                    if ($insStmt) {
                        $cName = $bRow['customer_name'];
                        $cEmail = $bRow['customer_email'];
                        $cPhone = $bRow['contact_number'];
                        $sType = $bRow['service_type'];
                        $sec = $bRow['section'];
                        $plt = $bRow['plot'];
                        $pMethod = $bRow['payment_method'];
                        $bDate = $bRow['created_at'];
                        $uId = (int)$bRow['user_id'];

                        $insStmt->bind_param('iissssssdddssssssss',
                            $booking_id, $uId, $cName, $cEmail, $cPhone,
                            $sType, $sec, $plt, $totAmt, $amtPaid,
                            $bal, $payStatus, $pMethod, $refNo,
                            $repMonth, $payDate, $bDate, $bStatus,
                            $bDate
                        );
                        $insStmt->execute();
                        $insStmt->close();

                        @$conn->query("INSERT INTO `sales_report` SELECT * FROM `sales_reports` WHERE booking_id = {$booking_id} ON DUPLICATE KEY UPDATE `updated_at` = NOW()");
                        return true;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('record_sales_report_entry error: ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('record_booking_log')) {
    function record_booking_log($conn, $booking_id, $section = null, $contact_number = null, $booker_location = null, $price = null) {
        $booking_id = (int)$booking_id;
        if ($booking_id <= 0 || !($conn instanceof mysqli) || $conn->connect_errno) {
            return false;
        }

        if ($section === null || $contact_number === null || $booker_location === null || $price === null) {
            $bkQ = @$conn->query("SELECT section, contact_number, booker_location, total_amount, plot_price FROM tbl_bookings WHERE id = {$booking_id} LIMIT 1");
            if ($bkQ && ($bRow = $bkQ->fetch_assoc())) {
                if ($section === null) $section = $bRow['section'];
                if ($contact_number === null) $contact_number = $bRow['contact_number'];
                if ($booker_location === null) $booker_location = $bRow['booker_location'];
                if ($price === null) $price = !empty($bRow['total_amount']) ? $bRow['total_amount'] : ($bRow['plot_price'] ?? 0.00);
            }
        }

        $sec = !empty($section) ? trim((string)$section) : '—';
        $contact = !empty($contact_number) ? trim((string)$contact_number) : '—';
        $location = !empty($booker_location) ? trim((string)$booker_location) : 'Memorial Park Office';
        $prc = (float)($price ?? 0.00);

        $stmt = @$conn->prepare("INSERT INTO `tbl_booking_logs` (`booking_id`, `section`, `contact_number`, `booker_location`, `price`, `timestamp`) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param('isssd', $booking_id, $sec, $contact, $location, $prc);
            $res = $stmt->execute();
            $stmt->close();

            // Record into sales_reports table
            record_sales_report_entry($conn, $booking_id);

            return $res;
        }
        return false;
    }
}

if (!function_exists('ensure_defense_cleanups_and_audits')) {
    function ensure_defense_cleanups_and_audits($conn) {
        if (is_schema_migrated()) {
            return;
        }
        static $checked = false;
        if ($checked || !($conn instanceof mysqli) || $conn->connect_errno) {
            return;
        }
        $checked = true;

        try {
            // 1. Clean up "Strong Pwd User" test data
            @$conn->query("DELETE FROM tbl_plot_owners WHERE id = 135 OR UPPER(TRIM(owner_name)) = 'STRONG PWD USER'");
            @$conn->query("DELETE FROM tbl_user WHERE user_id = 136 OR (UPPER(TRIM(first_name)) = 'STRONG' AND UPPER(TRIM(last_name)) = 'PWD USER')");
            @$conn->query("DELETE FROM tbl_logs WHERE UPPER(details) LIKE '%STRONG PWD USER%' OR user_id = 136");
            @$conn->query("DELETE FROM user_plot_ownership WHERE user_id = 136 OR (UPPER(TRIM(section)) = 'A' AND plot_x = 1 AND plot_y = 1 AND (notes LIKE '%Strong Pwd%' OR claim_source = 'BOOKING'))");

            // 2. Clean up Jasper Sevilliano test records
            @$conn->query("DELETE FROM booking_plots WHERE booking_id IN (142, 166, 167)");
            @$conn->query("DELETE FROM payment_receipts WHERE booking_id IN (142, 166, 167)");
            @$conn->query("DELETE FROM payment_schedules WHERE booking_id IN (142, 166, 167) OR contract_id IN (142, 166, 167)");
            @$conn->query("DELETE FROM booking_payment_schedule WHERE booking_id IN (142, 166, 167)");
            @$conn->query("DELETE FROM tbl_bookings WHERE id IN (142, 166, 167) OR UPPER(TRIM(customer_name)) LIKE '%JASPER SEVILLIANO%' OR UPPER(TRIM(customer_name)) LIKE '%JASPER SEVILLANO%' OR LOWER(TRIM(customer_email)) = 'jaspersevillano101@gmail.com'");

            $chkGrr = @$conn->query("SHOW TABLES LIKE 'guest_reservation_requests'");
            if ($chkGrr && $chkGrr->num_rows > 0) {
                @$conn->query("DELETE FROM guest_reservation_requests WHERE id IN (9, 10, 12) OR UPPER(TRIM(full_name)) LIKE '%JASPER SEVILLIANO%' OR UPPER(TRIM(full_name)) LIKE '%JASPER SEVILLANO%' OR LOWER(TRIM(email)) = 'jaspersevillano101@gmail.com'");
            }

            @$conn->query("DELETE FROM tbl_user WHERE user_id = 149 OR (user_id = 74 AND (UPPER(TRIM(first_name)) LIKE '%JASPER%' OR LOWER(TRIM(email)) LIKE '%jasper%'))");
            @$conn->query("DELETE FROM tbl_logs WHERE user_id IN (149, 74) OR UPPER(details) LIKE '%JASPER SEVILLIANO%' OR UPPER(details) LIKE '%JASPER SEVILLANO%' OR LOWER(details) LIKE '%jaspersevillano101@gmail.com%'");
            @$conn->query("DELETE FROM tbl_plot_owners WHERE UPPER(TRIM(owner_name)) LIKE '%JASPER SEVILLIANO%' OR UPPER(TRIM(owner_name)) LIKE '%JASPER SEVILLANO%' OR reserved_by IN (149, 74)");
            @$conn->query("DELETE FROM user_plot_ownership WHERE user_id IN (149, 74)");

            // 3. Reset test plots to 'available' if no real occupant or real owner
            $testPlots = [
                ['A', 1, 1],
                ['A', 50, 3],
                ['B', 53, 4],
                ['B', 44, 4],
                ['B', 46, 4]
            ];
            foreach ($testPlots as $tp) {
                $sec = $tp[0];
                $px = (int)$tp[1];
                $py = (int)$tp[2];
                $hasDec = false;
                $decRes = @$conn->query("SELECT deceased_id FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py} LIMIT 1");
                if ($decRes && $decRes->num_rows > 0) {
                    $hasDec = true;
                }
                $hasActiveOwner = false;
                $ownRes = @$conn->query("SELECT id FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py} AND active = 1 LIMIT 1");
                if ($ownRes && $ownRes->num_rows > 0) {
                    $hasActiveOwner = true;
                }
                if (!$hasDec && !$hasActiveOwner) {
                    @$conn->query("UPDATE tbl_plots SET status = 'available' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
                }
            }

            // 4. Fix Deceased Pretender Torio (deceased_id = 125): set plot_y = 1, plot = '10, 1'
            @$conn->query("UPDATE tbl_deceased SET plot_y = 1, plot = '10, 1' WHERE deceased_id = 125 AND plot_x = 10 AND plot_y = 2");
            @$conn->query("UPDATE tbl_plots SET status = 'occupied' WHERE UPPER(TRIM(section)) = 'C' AND plot_x = 10 AND plot_y = 1");
            $c102Dec = @$conn->query("SELECT deceased_id FROM tbl_deceased WHERE UPPER(TRIM(section)) = 'C' AND plot_x = 10 AND plot_y = 2 LIMIT 1");
            if (!$c102Dec || $c102Dec->num_rows === 0) {
                @$conn->query("UPDATE tbl_plots SET status = 'available' WHERE UPPER(TRIM(section)) = 'C' AND plot_x = 10 AND plot_y = 2");
            }

            // 5. Ensure downpayment column exists in tbl_bookings
            $chkDp = @$conn->query("SHOW COLUMNS FROM `tbl_bookings` LIKE 'downpayment'");
            if ($chkDp && $chkDp->num_rows === 0) {
                @$conn->query("ALTER TABLE `tbl_bookings` ADD COLUMN `downpayment` DECIMAL(10,2) NULL DEFAULT 0.00 AFTER `total_amount`");
            }
            @$conn->query("UPDATE `tbl_bookings` SET `downpayment` = ROUND(total_amount * 0.20, 2) WHERE (`downpayment` IS NULL OR `downpayment` <= 0) AND total_amount > 0");

            // 6. Ensure booking_plots has valid plot_id and proper lawn_type (fix any NULL or 0)
            @$conn->query("UPDATE booking_plots bp
            INNER JOIN tbl_plots tp 
                ON UPPER(TRIM(tp.section)) = UPPER(TRIM(bp.section)) 
               AND tp.plot_x = bp.plot_x 
               AND tp.plot_y = bp.plot_y
            SET bp.plot_id = tp.plot_id,
                bp.lawn_type = tp.lawn_type
            WHERE bp.plot_id IS NULL OR bp.lawn_type = '0' OR bp.lawn_type = 'Regular' OR bp.lawn_type IS NULL OR bp.lawn_type = ''");

            // 7. Ensure single-plot tbl_bookings has valid plot_id
            @$conn->query("UPDATE tbl_bookings tb
            INNER JOIN tbl_plots tp
                ON UPPER(TRIM(tp.section)) = UPPER(TRIM(tb.section))
               AND (tb.plot = CONCAT(tp.plot_x, '-', tp.plot_y) 
                 OR tb.plot = CONCAT(tp.plot_x, ', ', tp.plot_y) 
                 OR tb.plot = CONCAT(tp.plot_x, ',', tp.plot_y) 
                 OR tb.plot = CONCAT(tb.section, '- ', tp.plot_x, ',', tp.plot_y)
                 OR tb.grave_location = CONCAT(tp.plot_x, '-', tp.plot_y))
            SET tb.plot_id = tp.plot_id
            WHERE tb.plot_id IS NULL OR tb.plot_id = 0");

        } catch (Throwable $e) {
            error_log('ensure_defense_cleanups_and_audits error: ' . $e->getMessage());
        }
    }
}

if ($db_connected && ($conn instanceof mysqli)) {
    $schemaLockFile = __DIR__ . '/config/.schema_migrated.lock';
    $forceSync = !empty($_GET['force_schema_sync']) || !empty($_GET['migrate_db']);
    if (!file_exists($schemaLockFile) || $forceSync) {
        ensure_deceased_database_integrity($conn);
        ensure_guest_reservation_columns($conn);
        ensure_plot_coordinates_columns($conn);
        ensure_service_workflow_columns($conn);
        ensure_booking_table_integrity($conn);
        ensure_defense_cleanups_and_audits($conn);
        @file_put_contents($schemaLockFile, date('Y-m-d H:i:s'));
    }
}


if (file_exists(__DIR__ . '/config/transfer_policy.php')) {
    require_once __DIR__ . '/config/transfer_policy.php';
}

if (file_exists(__DIR__ . '/includes/gps_calculator.php')) {
    require_once __DIR__ . '/includes/gps_calculator.php';
}

if (!function_exists('is_db_connected')) {
    function is_db_connected() {
        global $db_connected, $conn;
        return $db_connected && ($conn instanceof mysqli) && !$conn->connect_errno;
    }
}

if (!function_exists('safe_db_query')) {
    function safe_db_query($sql, $fallback = false) {
        global $conn;
        if (!is_db_connected()) {
            return $fallback;
        }
        try {
            $res = $conn->query($sql);
            return ($res !== false) ? $res : $fallback;
        } catch (Throwable $e) {
            error_log('Safe DB query error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            return $fallback;
        }
    }
}

if (!function_exists('format_plot_number')) {
    function format_plot_number($plot, $section = '') {
        if (empty($plot) && empty($section)) return '—';
        $raw = trim((string)$plot);
        $sec = strtoupper(trim((string)$section));

        if (preg_match('/^(?:([A-Za-z0-9]+)-)?(\d{3})(\d{3})$/', $raw, $m)) {
            $s = strtoupper(!empty($m[1]) ? $m[1] : ($sec ?: 'A'));
            $x = (int)$m[2];
            $y = (int)$m[3];
            return $s . '- ' . $x . ',' . $y;
        }

        if (preg_match('/^(?:([A-Za-z0-9]+)[-_\s]+)?(\d+)[-_,\s]+(\d+)$/', $raw, $m)) {
            $s = strtoupper(!empty($m[1]) ? $m[1] : ($sec ?: 'A'));
            $x = (int)$m[2];
            $y = (int)$m[3];
            return $s . '- ' . $x . ',' . $y;
        }

        if (!empty($sec) && !empty($raw)) {
            if (preg_match('/^(?:' . preg_quote($sec, '/') . '[\s\-_]+)+/i', $raw)) {
                $cleaned = preg_replace('/^(?:' . preg_quote($sec, '/') . '[\s\-_]+)+/i', '', $raw);
                return $sec . '- ' . trim($cleaned);
            }
            return $sec . '- ' . $raw;
        }
        return $raw ?: ($sec ?: '—');
    }
}

if (!function_exists('finalize_approved_booking_payments')) {
    function finalize_approved_booking_payments(mysqli $conn, int $booking_id, int $admin_user_id = 1): void {
        if (file_exists(__DIR__ . '/config/payment_helper.php')) {
            require_once __DIR__ . '/config/payment_helper.php';
        }
        $chkPR = $conn->query("SELECT id FROM payment_receipts WHERE booking_id = {$booking_id} AND (payment_status IS NULL OR UPPER(payment_status) IN ('PENDING', 'FOR VERIFICATION'))");
        if ($chkPR && $chkPR->num_rows > 0) {
            while ($prRow = $chkPR->fetch_assoc()) {
                $pId = (int)$prRow['id'];
                if (function_exists('verify_and_approve_payment')) {
                    verify_and_approve_payment($conn, $pId, $admin_user_id);
                } else {
                    $conn->query("UPDATE payment_receipts SET payment_status = 'PAID', verified_by = {$admin_user_id}, verified_at = NOW(), rejection_reason = NULL WHERE id = {$pId}");
                    if (function_exists('apply_verified_payment_effects')) {
                        apply_verified_payment_effects($conn, $pId);
                    }
                }
            }
        }
        if (function_exists('recalculate_booking_payment_totals')) {
            recalculate_booking_payment_totals($booking_id, $conn);
        }
        $conn->query("UPDATE guest_reservation_requests SET payment_status = 'PAID', status = 'Approved', approved_by = {$admin_user_id}, approved_at = NOW() WHERE booking_id = {$booking_id}");
    }
}

if (!function_exists('handle_booking_approval')) {

    function handle_booking_approval($conn, $booking_id, $admin_user_id = 1) {
        $booking_id = (int)$booking_id;
        if ($booking_id <= 0 || !($conn instanceof mysqli)) {
            return ['success' => false, 'message' => 'Invalid booking ID.'];
        }

        ensure_guest_reservation_columns($conn);

        $stmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database query failed: ' . $conn->error];
        }
        $stmt->bind_param('i', $booking_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $b = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$b) {
            return ['success' => false, 'message' => 'Reservation request #' . $booking_id . ' not found.'];
        }

        $sec = strtoupper(trim((string)($b['section'] ?? '')));
        $plotVal = trim((string)($b['plot'] ?? ''));
        $px = (int)($b['plot_x'] ?? 0);
        $py = (int)($b['plot_y'] ?? 0);
        if ($px <= 0 || $py <= 0) {
            if (preg_match('/(\d+)\s*[-_,]\s*(\d+)/', $plotVal, $m)) {
                $px = (int)$m[1];
                $py = (int)$m[2];
            } else {
                $plotParts = explode('-', str_replace(',', '-', $plotVal));
                $px = (int)($plotParts[0] ?? 0);
                $py = (int)($plotParts[1] ?? 0);
            }
        }
        if ($px <= 0 || $py <= 0) {
            $bpQ = $conn->query("SELECT plot_x, plot_y FROM booking_plots WHERE booking_id = {$booking_id} AND plot_x > 0 AND plot_y > 0 LIMIT 1");
            if ($bpQ && ($bpR = $bpQ->fetch_assoc())) {
                $px = (int)$bpR['plot_x'];
                $py = (int)$bpR['plot_y'];
            }
        }
        $isGuest = (!empty($b['is_guest_booking']) || empty($b['user_id']) || (int)$b['user_id'] === 0);

        $bType = strtolower(trim((string)($b['booking_type'] ?? '')));
        $bPurpose = strtolower(trim((string)($b['purpose'] ?? '')));
        $bServiceType = strtolower(trim((string)($b['service_type'] ?? '')));

        $isServiceBooking = (
            $bType === 'existing_plot_service' ||
            $bPurpose === 'cemetery_service' ||
            $bPurpose === 'add_deceased' ||
            in_array($bServiceType, ['cleaning', 'maintenance', 'landscaping', 'burial', 'interment'])
        );
        $isPlotReservation = !$isServiceBooking;

        $currentStatus = strtolower(trim((string)($b['status'] ?? '')));
        if ($currentStatus === 'approved' && !empty($b['registration_token']) && empty($b['token_used'])) {
            return ['success' => false, 'message' => 'This reservation request has already been approved and an account creation link was sent.'];
        }
        if ($currentStatus === 'approved' && !$isGuest) {
            return ['success' => false, 'message' => 'This booking has already been approved.'];
        }

        if ($isGuest) {
            $custEmail = strtolower(trim((string)($b['customer_email'] ?? ($b['email'] ?? ''))));
            if (!empty($custEmail)) {
                $uChk = $conn->prepare("SELECT user_id FROM tbl_user WHERE LOWER(TRIM(email)) = ? LIMIT 1");
                if ($uChk) {
                    $uChk->bind_param('s', $custEmail);
                    $uChk->execute();
                    if ($uChk->get_result()->num_rows > 0) {
                        $uChk->close();
                        return [
                            'success' => false,
                            'message' => 'This guest reservation cannot be approved because the email is already associated with a registered account. The user must continue through their existing account.'
                        ];
                    }
                    $uChk->close();
                }
            }
        }

        if ($isPlotReservation && $px > 0 && $py > 0 && !empty($sec)) {

            $ownChk = $conn->prepare("SELECT id, owner_name, reserved_by FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND active = 1 LIMIT 1");
            if ($ownChk) {
                $ownChk->bind_param('sii', $sec, $px, $py);
                $ownChk->execute();
                $ownRes = $ownChk->get_result();
                if ($ownRes && $ownRes->num_rows > 0) {
                    $ownerRow = $ownRes->fetch_assoc();
                    $ownChk->close();
                    $bUserId = (int)($b['user_id'] ?? 0);
                    if ($ownerRow['reserved_by'] != $bUserId || $isGuest) {
                        return ['success' => false, 'message' => "Cannot approve: Plot Section {$sec} Plot {$plotVal} is already owned or reserved."];
                    }
                } else {
                    $ownChk->close();
                }
            }

            $decChk = $conn->prepare("SELECT deceased_id FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND ((plot_x = ? AND plot_y = ?) OR plot = ? OR plot = ?) LIMIT 1");
            if ($decChk) {
                $plotAlt = $px . ', ' . $py;
                $decChk->bind_param('siiss', $sec, $px, $py, $plotVal, $plotAlt);
                $decChk->execute();
                $decRes = $decChk->get_result();
                if ($decRes && $decRes->num_rows > 0) {
                    $decChk->close();
                    return ['success' => false, 'message' => "Cannot approve: Plot Section {$sec} Plot {$plotVal} is occupied."];
                }
                $decChk->close();
            }

            $dupBk = $conn->prepare("SELECT id FROM tbl_bookings WHERE UPPER(TRIM(section)) = ? AND (plot = ? OR plot = ?) AND status = 'approved' AND id != ? AND (booking_type IN ('guest_reservation', 'another_plot', 'plot_purchase') OR purpose IN ('reserve_grave', 'plot_purchase', 'Future Memorial Lot')) LIMIT 1");
            if ($dupBk) {
                $plotAlt = $px . ', ' . $py;
                $dupBk->bind_param('sssi', $sec, $plotVal, $plotAlt, $booking_id);
                $dupBk->execute();
                $dupRes = $dupBk->get_result();
                if ($dupRes && $dupRes->num_rows > 0) {
                    $dupBk->close();
                    return ['success' => false, 'message' => "Cannot approve: Another reservation has already been approved for Plot Section {$sec} Plot {$plotVal}."];
                }
                $dupBk->close();
            }
        }

        if ($isGuest) {

            $token = bin2hex(random_bytes(32));

            $tokenExpiresAt = date('Y-m-d H:i:s', time() + (48 * 3600));

            $upStmt = $conn->prepare("UPDATE tbl_bookings SET status = 'approved', registration_token = ?, token_expires_at = ?, token_used = 0 WHERE id = ?");
            if (!$upStmt) {
                return ['success' => false, 'message' => 'Database error: ' . $conn->error];
            }
            $upStmt->bind_param('ssi', $token, $tokenExpiresAt, $booking_id);
            if (!$upStmt->execute()) {
                $err = $upStmt->error;
                $upStmt->close();
                return ['success' => false, 'message' => 'Failed to update booking status: ' . $err];
            }
            $upStmt->close();

            if ($px > 0 && $py > 0 && !empty($sec)) {
                $plotUp = $conn->prepare("UPDATE tbl_plots SET status = 'reserved' WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND LOWER(status) = 'available'");
                if ($plotUp) {
                    $plotUp->bind_param('sii', $sec, $px, $py);
                    $plotUp->execute();
                    $plotUp->close();
                }

                $conn->query("CREATE TABLE IF NOT EXISTS tbl_plot_owners (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    section VARCHAR(10) NOT NULL,
                    plot_x INT NOT NULL,
                    plot_y INT NOT NULL,
                    owner_name VARCHAR(100) NOT NULL,
                    first_name VARCHAR(50) DEFAULT NULL,
                    middle_name VARCHAR(50) DEFAULT NULL,
                    last_name VARCHAR(50) DEFAULT NULL,
                    suffix VARCHAR(20) DEFAULT NULL,
                    bday DATE DEFAULT NULL,
                    phone_no VARCHAR(15) DEFAULT NULL,
                    address VARCHAR(255) DEFAULT NULL,
                    reserved_by INT DEFAULT NULL,
                    active TINYINT(1) DEFAULT 1,
                    reserved_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $deact = $conn->prepare("UPDATE tbl_plot_owners SET active = 0 WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND active = 1");
                if ($deact) {
                    $deact->bind_param('sii', $sec, $px, $py);
                    $deact->execute();
                    $deact->close();
                }

                $custName = trim((string)($b['customer_name'] ?? ($b['full_name'] ?? 'Guest')));
                $nameParts = preg_split('/\s+/', $custName);
                $fName = $nameParts[0] ?? '';
                $lName = count($nameParts) > 1 ? end($nameParts) : '';
                $mName = count($nameParts) > 2 ? implode(' ', array_slice($nameParts, 1, -1)) : '';
                $contactNo = trim((string)($b['contact_number'] ?? ''));
                $loc = trim((string)($b['booker_location'] ?? ''));
                $bUserId = (int)($b['user_id'] ?? 0);

                $insOwner = $conn->prepare("INSERT INTO tbl_plot_owners (section, plot_x, plot_y, owner_name, first_name, middle_name, last_name, suffix, bday, phone_no, address, reserved_by, active, reserved_at) VALUES (?, ?, ?, ?, ?, ?, ?, '', '1970-01-01', ?, ?, ?, 1, NOW())");
                if ($insOwner) {
                    $insOwner->bind_param('siissssssi', $sec, $px, $py, $custName, $fName, $mName, $lName, $contactNo, $loc, $bUserId);
                    $insOwner->execute();
                    $insOwner->close();
                }
            }
            $bPlotId = (int)($b['plot_id'] ?? 0);
            if ($bPlotId > 0) {
                $conn->query("UPDATE tbl_plots SET status = 'reserved' WHERE plot_id = {$bPlotId} AND LOWER(status) = 'available'");
            }

            $bpQ = $conn->query("SELECT plot_id, section, plot_x, plot_y FROM booking_plots WHERE booking_id = {$booking_id}");
            if ($bpQ) {
                while ($bpRow = $bpQ->fetch_assoc()) {
                    $bpPlotId = (int)$bpRow['plot_id'];
                    $bpSec = strtoupper(trim((string)$bpRow['section']));
                    $bpPx = (int)$bpRow['plot_x'];
                    $bpPy = (int)$bpRow['plot_y'];
                    if ($bpPlotId > 0) {
                        $conn->query("UPDATE tbl_plots SET status = 'reserved' WHERE plot_id = {$bpPlotId}");
                    }
                    if (!empty($bpSec) && $bpPx > 0 && $bpPy > 0) {
                        $conn->query("UPDATE tbl_plots SET status = 'reserved' WHERE UPPER(TRIM(section)) = '{$conn->real_escape_string($bpSec)}' AND plot_x = {$bpPx} AND plot_y = {$bpPy}");
                    }
                }
                $conn->query("UPDATE booking_plots SET status = 'reserved' WHERE booking_id = {$booking_id}");
            }

            $custName = trim((string)($b['customer_name'] ?? ($b['full_name'] ?? 'Guest')));
            $custEmail = trim((string)($b['customer_email'] ?? ($b['email'] ?? '')));
            $logDetails = "Approved guest reservation #{$booking_id} for {$custName} ({$custEmail}) for Section {$sec} Plot {$plotVal}. Account creation link dispatched.";
            $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('GUEST_RESERVATION_APPROVED', ?, ?, NOW())");
            if ($logStmt) {
                $logStmt->bind_param('si', $logDetails, $admin_user_id);
                $logStmt->execute();
                $logStmt->close();
            }

            finalize_approved_booking_payments($conn, $booking_id, $admin_user_id);

            if (file_exists(__DIR__ . '/mailer.php')) {
                require_once __DIR__ . '/mailer.php';
                if (function_exists('send_guest_account_creation_email')) {
                    send_guest_account_creation_email($booking_id, $token, $conn);
                }
            }

            return [
                'success' => true,
                'status' => 'approved',
                'is_guest' => true,
                'token' => $token,
                'message' => "Guest reservation #{$booking_id} approved! An account creation email with a secure registration link has been sent to {$custEmail}."
            ];
        } elseif ($isServiceBooking) {

            $upStmt = $conn->prepare("UPDATE tbl_bookings SET status = 'approved', service_status = 'APPROVED' WHERE id = ?");
            if (!$upStmt) {
                return ['success' => false, 'message' => 'Database error: ' . $conn->error];
            }
            $upStmt->bind_param('i', $booking_id);
            if (!$upStmt->execute()) {
                $err = $upStmt->error;
                $upStmt->close();
                return ['success' => false, 'message' => 'Failed to approve service booking: ' . $err];
            }
            $upStmt->close();

            $logAction = 'Service Booking Approved';
            $srvLabel = !empty($b['service_type']) ? $b['service_type'] : (!empty($b['purpose']) ? $b['purpose'] : 'Service');
            $logDetails = "Service Booking ID: {$booking_id} ({$srvLabel}) for Section {$sec} Plot {$plotVal} has been approved.";
            $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, ?, NOW())");
            if ($logStmt) {
                $logStmt->bind_param('ssi', $logAction, $logDetails, $admin_user_id);
                $logStmt->execute();
                $logStmt->close();
            }

            finalize_approved_booking_payments($conn, $booking_id, $admin_user_id);

            if (file_exists(__DIR__ . '/mailer.php')) {
                require_once __DIR__ . '/mailer.php';
                if (function_exists('send_booking_status_email')) {
                    send_booking_status_email((int)$booking_id, 'Approved', $conn);
                }
            }

            return [
                'success' => true,
                'status' => 'approved',
                'is_guest' => false,
                'token' => null,
                'message' => "Service booking #{$booking_id} ({$srvLabel}) approved successfully."
            ];
        } else {

            $upStmt = $conn->prepare("UPDATE tbl_bookings SET status = 'approved' WHERE id = ?");
            if (!$upStmt) {
                return ['success' => false, 'message' => 'Database error: ' . $conn->error];
            }
            $upStmt->bind_param('i', $booking_id);
            if (!$upStmt->execute()) {
                $err = $upStmt->error;
                $upStmt->close();
                return ['success' => false, 'message' => 'Failed to approve booking: ' . $err];
            }
            $upStmt->close();

            if ($px > 0 && $py > 0 && !empty($sec)) {
                $plotUp = $conn->prepare("UPDATE tbl_plots SET status = 'reserved' WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND LOWER(status) = 'available'");
                if ($plotUp) {
                    $plotUp->bind_param('sii', $sec, $px, $py);
                    $plotUp->execute();
                    $plotUp->close();
                }

                $conn->query("CREATE TABLE IF NOT EXISTS tbl_plot_owners (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    section VARCHAR(10) NOT NULL,
                    plot_x INT NOT NULL,
                    plot_y INT NOT NULL,
                    owner_name VARCHAR(100) NOT NULL,
                    first_name VARCHAR(50) DEFAULT NULL,
                    middle_name VARCHAR(50) DEFAULT NULL,
                    last_name VARCHAR(50) DEFAULT NULL,
                    suffix VARCHAR(20) DEFAULT NULL,
                    bday DATE DEFAULT NULL,
                    phone_no VARCHAR(15) DEFAULT NULL,
                    address VARCHAR(255) DEFAULT NULL,
                    reserved_by INT DEFAULT NULL,
                    active TINYINT(1) DEFAULT 1,
                    reserved_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $deact = $conn->prepare("UPDATE tbl_plot_owners SET active = 0 WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND active = 1");
                if ($deact) {
                    $deact->bind_param('sii', $sec, $px, $py);
                    $deact->execute();
                    $deact->close();
                }

                $custName = trim((string)($b['customer_name'] ?? ($b['full_name'] ?? '')));
                $nameParts = preg_split('/\s+/', $custName);
                $fName = $nameParts[0] ?? '';
                $lName = count($nameParts) > 1 ? end($nameParts) : '';
                $mName = count($nameParts) > 2 ? implode(' ', array_slice($nameParts, 1, -1)) : '';
                $contactNo = trim((string)($b['contact_number'] ?? ''));
                $loc = trim((string)($b['booker_location'] ?? ''));
                $bUserId = (int)($b['user_id'] ?? 0);

                $insOwner = $conn->prepare("INSERT INTO tbl_plot_owners (section, plot_x, plot_y, owner_name, first_name, middle_name, last_name, suffix, bday, phone_no, address, reserved_by, active, reserved_at) VALUES (?, ?, ?, ?, ?, ?, ?, '', '1970-01-01', ?, ?, ?, 1, NOW())");
                if ($insOwner) {
                    $insOwner->bind_param('siissssssi', $sec, $px, $py, $custName, $fName, $mName, $lName, $contactNo, $loc, $bUserId);
                    $insOwner->execute();
                    $insOwner->close();
                }

                if ($bUserId > 0) {
                    if (function_exists('ensure_user_plot_ownership_table')) {
                        ensure_user_plot_ownership_table($conn);
                    }
                    $chkUpo = $conn->prepare("SELECT id FROM user_plot_ownership WHERE user_id = ? AND (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = ? OR UPPER(TRIM(section)) = ?) AND plot_x = ? AND plot_y = ? LIMIT 1");
                    if ($chkUpo) {
                        $chkUpo->bind_param('issii', $bUserId, $sec, $sec, $px, $py);
                        $chkUpo->execute();
                        $chkRes = $chkUpo->get_result();
                        if ($chkRes && $chkRes->num_rows > 0) {
                            $updUpo = $conn->prepare("UPDATE user_plot_ownership SET ownership_status = 'RESERVED', verification_status = 'VERIFIED', updated_at = NOW() WHERE user_id = ? AND (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = ? OR UPPER(TRIM(section)) = ?) AND plot_x = ? AND plot_y = ?");
                            if ($updUpo) {
                                $updUpo->bind_param('issii', $bUserId, $sec, $sec, $px, $py);
                                $updUpo->execute();
                                $updUpo->close();
                            }
                        } else {
                            $insUpo = $conn->prepare("INSERT INTO user_plot_ownership (user_id, section, plot_x, plot_y, ownership_status, verification_status, claim_source, notes) VALUES (?, ?, ?, ?, 'RESERVED', 'VERIFIED', 'ONLINE_RESERVATION', 'Reserved via approved booking')");
                            if ($insUpo) {
                                $insUpo->bind_param('isii', $bUserId, $sec, $px, $py);
                                $insUpo->execute();
                                $insUpo->close();
                            }
                        }
                        $chkUpo->close();
                    }
                }
            }
            $bPlotId = (int)($b['plot_id'] ?? 0);
            if ($bPlotId > 0) {
                $conn->query("UPDATE tbl_plots SET status = 'reserved' WHERE plot_id = {$bPlotId} AND LOWER(status) = 'available'");
            }

            $bpQ = $conn->query("SELECT plot_id, section, plot_x, plot_y FROM booking_plots WHERE booking_id = {$booking_id}");
            if ($bpQ) {
                while ($bpRow = $bpQ->fetch_assoc()) {
                    $bpPlotId = (int)$bpRow['plot_id'];
                    $bpSec = strtoupper(trim((string)$bpRow['section']));
                    $bpPx = (int)$bpRow['plot_x'];
                    $bpPy = (int)$bpRow['plot_y'];
                    if ($bpPlotId > 0) {
                        $conn->query("UPDATE tbl_plots SET status = 'reserved' WHERE plot_id = {$bpPlotId}");
                    }
                    if (!empty($bpSec) && $bpPx > 0 && $bpPy > 0) {
                        $conn->query("UPDATE tbl_plots SET status = 'reserved' WHERE UPPER(TRIM(section)) = '{$conn->real_escape_string($bpSec)}' AND plot_x = {$bpPx} AND plot_y = {$bpPy}");
                        $insOwner = $conn->prepare("INSERT INTO tbl_plot_owners (section, plot_x, plot_y, owner_name, first_name, middle_name, last_name, suffix, bday, phone_no, address, reserved_by, active, reserved_at) VALUES (?, ?, ?, ?, ?, ?, ?, '', '1970-01-01', ?, ?, ?, 1, NOW())");
                        if ($insOwner) {
                            $insOwner->bind_param('siissssssi', $bpSec, $bpPx, $bpPy, $custName, $fName, $mName, $lName, $contactNo, $loc, $bUserId);
                            $insOwner->execute();
                            $insOwner->close();
                        }
                        if ($bUserId > 0) {
                            if (function_exists('ensure_user_plot_ownership_table')) {
                                ensure_user_plot_ownership_table($conn);
                            }
                            $chkUpo2 = $conn->prepare("SELECT id FROM user_plot_ownership WHERE user_id = ? AND (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = ? OR UPPER(TRIM(section)) = ?) AND plot_x = ? AND plot_y = ? LIMIT 1");
                            if ($chkUpo2) {
                                $chkUpo2->bind_param('issii', $bUserId, $bpSec, $bpSec, $bpPx, $bpPy);
                                $chkUpo2->execute();
                                $chkRes2 = $chkUpo2->get_result();
                                if ($chkRes2 && $chkRes2->num_rows > 0) {
                                    $updUpo2 = $conn->prepare("UPDATE user_plot_ownership SET ownership_status = 'RESERVED', verification_status = 'VERIFIED', updated_at = NOW() WHERE user_id = ? AND (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = ? OR UPPER(TRIM(section)) = ?) AND plot_x = ? AND plot_y = ?");
                                    if ($updUpo2) {
                                        $updUpo2->bind_param('issii', $bUserId, $bpSec, $bpSec, $bpPx, $bpPy);
                                        $updUpo2->execute();
                                        $updUpo2->close();
                                    }
                                } else {
                                    $insUpo2 = $conn->prepare("INSERT INTO user_plot_ownership (user_id, section, plot_x, plot_y, ownership_status, verification_status, claim_source, notes) VALUES (?, ?, ?, ?, 'RESERVED', 'VERIFIED', 'ONLINE_RESERVATION', 'Reserved via approved booking')");
                                    if ($insUpo2) {
                                        $insUpo2->bind_param('isii', $bUserId, $bpSec, $bpPx, $bpPy);
                                        $insUpo2->execute();
                                        $insUpo2->close();
                                    }
                                }
                                $chkUpo2->close();
                            }
                        }
                    }
                }
                $conn->query("UPDATE booking_plots SET status = 'reserved' WHERE booking_id = {$booking_id}");
            }

            $logAction = 'Booking Approved';
            $logDetails = "Booking ID: {$booking_id} has been approved";
            $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES (?, ?, ?, NOW())");
            if ($logStmt) {
                $logStmt->bind_param('ssi', $logAction, $logDetails, $admin_user_id);
                $logStmt->execute();
                $logStmt->close();
            }

            finalize_approved_booking_payments($conn, $booking_id, $admin_user_id);

            if (file_exists(__DIR__ . '/mailer.php')) {
                require_once __DIR__ . '/mailer.php';
                if (function_exists('send_booking_status_email')) {
                    send_booking_status_email((int)$booking_id, 'Approved', $conn);
                }
            }

            return [
                'success' => true,
                'status' => 'approved',
                'is_guest' => false,
                'token' => null,
                'message' => 'Booking approved successfully.'
            ];
        }
    }
}

if (!function_exists('get_app_base_url')) {
    function get_app_base_url(): string {
        if (defined('APP_BASE_URL') && !empty(APP_BASE_URL)) {
            return rtrim(APP_BASE_URL, '/\\');
        }
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (empty($host)) {
            return 'http://localhost/Garden_of_Remembrance_MP';
        }

        $scheme = 'http';
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) {
            $scheme = 'https';
        } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = $_SERVER['REQUEST_SCHEME'];
        }

        $script = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
        $dir = str_replace('\\', '/', dirname($script));
        $dir = preg_replace('#/(sections|api|setup|includes|config|cron)$#i', '', $dir);
        if ($dir === '/' || $dir === '.') {
            $dir = '';
        }

        return $scheme . '://' . $host . $dir;
    }
}

if (!function_exists('ensure_user_plot_ownership_table')) {
    function ensure_user_plot_ownership_table($conn, $force = false) {
        if (!$force && is_schema_migrated()) {
            return;
        }
        static $initialized = false;
        if (($initialized && !$force) || !($conn instanceof mysqli) || $conn->connect_errno) {
            return;
        }
        $initialized = true;

        try {

            if (!$force) {
                $checkRes = @$conn->query("SHOW TABLES LIKE 'user_plot_ownership'");
                if ($checkRes && $checkRes->num_rows > 0) {
                    $colRes = @$conn->query("SHOW COLUMNS FROM `user_plot_ownership` LIKE 'ownership_status'");
                    if ($colRes && ($colRow = $colRes->fetch_assoc())) {
                        if (strpos((string)$colRow['Type'], 'CANCELLED') === false) {
                            @$conn->query("ALTER TABLE `user_plot_ownership` MODIFY COLUMN `ownership_status` ENUM('OWNED', 'RESERVED', 'PURCHASED', 'CANCELLED') NOT NULL DEFAULT 'OWNED'");
                        }
                    }
                    return;
                }
            }

            $sql = "CREATE TABLE IF NOT EXISTS `user_plot_ownership` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `section` VARCHAR(10) NOT NULL,
                `plot_x` INT NOT NULL,
                `plot_y` INT NOT NULL,
                `plot_id` INT DEFAULT NULL,
                `ownership_status` ENUM('OWNED', 'RESERVED', 'PURCHASED', 'CANCELLED') NOT NULL DEFAULT 'OWNED',
                `verification_status` ENUM('PENDING', 'VERIFIED', 'REJECTED') NOT NULL DEFAULT 'PENDING',
                `claim_source` ENUM('REGISTRATION', 'USER_DASHBOARD', 'BOOKING', 'ADMIN_ASSIGNED', 'ADMIN_MANUAL') NOT NULL DEFAULT 'REGISTRATION',
                `deceased_reference` VARCHAR(255) DEFAULT NULL,
                `notes` TEXT DEFAULT NULL,
                `admin_notes` TEXT DEFAULT NULL,
                `verified_by` INT DEFAULT NULL,
                `verified_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_user_plot` (`user_id`, `section`, `plot_x`, `plot_y`),
                KEY `idx_plot_loc` (`section`, `plot_x`, `plot_y`),
                KEY `idx_verification` (`verification_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            @$conn->query($sql);
            @$conn->query("ALTER TABLE `user_plot_ownership` MODIFY COLUMN `ownership_status` ENUM('OWNED', 'RESERVED', 'PURCHASED', 'CANCELLED') NOT NULL DEFAULT 'OWNED'");

            // Only sync plot_id references for already-existing ownership records.
            // Do NOT auto-insert from deceased/booking/reservation tables; those carry
            // the booking-agent (often Admin) user_id, not the real cemetery plot owner.
            @$conn->query("DELETE upo FROM user_plot_ownership upo JOIN tbl_user u ON upo.user_id = u.user_id WHERE UPPER(TRIM(u.role)) = 'ADMIN'");

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

            @$conn->query("UPDATE user_plot_ownership upo
                JOIN tbl_plots tp ON UPPER(TRIM(tp.section)) = UPPER(TRIM(upo.section)) AND tp.plot_x = upo.plot_x AND tp.plot_y = upo.plot_y
                SET upo.plot_id = tp.plot_id
                WHERE upo.plot_id IS NULL");
        } catch (Throwable $t) {
            error_log('ensure_user_plot_ownership_table notice: ' . $t->getMessage());
        }
    }
}

if (!function_exists('get_user_owned_plots')) {
    function get_user_owned_plots($conn, $user_id, $include_unverified = false) {
        if (!($conn instanceof mysqli) || $conn->connect_errno || empty($user_id)) {
            return [];
        }
        ensure_user_plot_ownership_table($conn);

        $plots  = [];
        $userId = (int)$user_id;

        // Auto-cleanup: remove any plot ownership records for cancelled bookings
        @$conn->query("DELETE FROM user_plot_ownership WHERE user_id = {$userId} AND ownership_status = 'CANCELLED'");
        @$conn->query("DELETE upo FROM user_plot_ownership upo
            JOIN tbl_bookings b ON (
                b.user_id = upo.user_id
                AND (REPLACE(UPPER(TRIM(b.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', '') OR UPPER(TRIM(b.section)) = REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', ''))
                AND (
                    b.plot = CONCAT(upo.plot_x, '-', upo.plot_y) 
                    OR b.plot = CONCAT(upo.plot_x, ',', upo.plot_y)
                    OR b.plot = CONCAT(upo.plot_x, ', ', upo.plot_y)
                    OR b.grave_location LIKE CONCAT('%', upo.plot_x, '-', upo.plot_y, '%')
                    OR (b.plot_id > 0 AND b.plot_id = upo.plot_id)
                )
            )
            WHERE upo.user_id = {$userId}
              AND LOWER(TRIM(b.status)) IN ('cancelled', 'rejected', 'declined')
              AND upo.ownership_status IN ('RESERVED', 'CANCELLED')");
        @$conn->query("DELETE upo FROM user_plot_ownership upo
            JOIN booking_plots bp ON (
                (REPLACE(UPPER(TRIM(bp.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', '') OR UPPER(TRIM(bp.section)) = REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', ''))
                AND ((bp.plot_x = upo.plot_x AND bp.plot_y = upo.plot_y) OR (bp.plot_x = upo.plot_y AND bp.plot_y = upo.plot_x) OR (bp.plot_id > 0 AND bp.plot_id = upo.plot_id))
            )
            JOIN tbl_bookings b ON b.id = bp.booking_id
            WHERE upo.user_id = {$userId}
              AND LOWER(TRIM(b.status)) IN ('cancelled', 'rejected', 'declined')
              AND upo.ownership_status IN ('RESERVED', 'CANCELLED')");
        @$conn->query("DELETE upo FROM user_plot_ownership upo
            JOIN tbl_plots tp ON (
                (REPLACE(UPPER(TRIM(tp.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', '') OR UPPER(TRIM(tp.section)) = REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', ''))
                AND tp.plot_x = upo.plot_x AND tp.plot_y = upo.plot_y
            )
            WHERE upo.user_id = {$userId}
              AND upo.ownership_status = 'RESERVED'
              AND LOWER(tp.status) = 'available'
              AND NOT EXISTS (
                  SELECT 1 FROM tbl_deceased d 
                  WHERE (REPLACE(UPPER(TRIM(d.section)), 'SECTION ', '') = REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', '') OR UPPER(TRIM(d.section)) = REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', ''))
                    AND d.plot_x = upo.plot_x AND d.plot_y = upo.plot_y
              )");

        // -----------------------------------------------------------------------
        // SINGLE AUTHORITATIVE SOURCE: user_plot_ownership
        // -----------------------------------------------------------------------
        $verCond = $include_unverified ? "AND upo.verification_status != 'CANCELLED'" : "AND upo.verification_status = 'VERIFIED'";
        $sql = "SELECT upo.*,
                       u.first_name AS owner_first_name, u.last_name AS owner_last_name,
                       u.email AS owner_email, u.phone_no AS owner_phone
                FROM user_plot_ownership upo
                LEFT JOIN tbl_user u ON u.user_id = upo.user_id
                WHERE upo.user_id = ?
                  {$verCond}
                  AND upo.ownership_status != 'CANCELLED'
                ORDER BY upo.section ASC, upo.plot_x ASC, upo.plot_y ASC";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $sec = strtoupper(trim($row['section']));
                $px  = (int)$row['plot_x'];
                $py  = (int)$row['plot_y'];

                // Fetch all deceased buried in this plot (informational only)
                $deceasedList = [];
                $decStmt = $conn->prepare(
                    "SELECT deceased_id, first_name, middle_name, last_name, suffix,
                            bday, date_died, interment_date, relationship_to_account_holder
                     FROM tbl_deceased
                     WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?
                     ORDER BY date_died DESC, deceased_id DESC"
                );
                if ($decStmt) {
                    $decStmt->bind_param('sii', $sec, $px, $py);
                    $decStmt->execute();
                    $decRes = $decStmt->get_result();
                    while ($dRow = $decRes->fetch_assoc()) {
                        $dRow['full_name'] = trim(implode(' ', array_filter([
                            trim($dRow['first_name']   ?? ''),
                            trim($dRow['middle_name']  ?? ''),
                            trim($dRow['last_name']    ?? ''),
                            trim($dRow['suffix']       ?? '')
                        ])));
                        $deceasedList[] = $dRow;
                    }
                    $decStmt->close();
                }

                $row['deceased_list']  = $deceasedList;
                $row['deceased_count'] = count($deceasedList);
                $row['has_deceased']   = (count($deceasedList) > 0);
                $row['formatted_plot'] = "Section {$sec} \u2014 Plot {$px}-{$py}";

                $plots[] = $row;
            }
            $stmt->close();
        }

        return $plots;
    }
}

if (!function_exists('is_plot_owned_or_reserved')) {
    function is_plot_owned_or_reserved($conn, $section, $plot_x, $plot_y) {
        if (!($conn instanceof mysqli) || $conn->connect_errno) {
            return ['status' => 'AVAILABLE', 'is_taken' => false, 'owner_name' => null, 'has_deceased' => false];
        }
        ensure_user_plot_ownership_table($conn);

        $sec = strtoupper(trim($section));
        $px = (int)$plot_x;
        $py = (int)$plot_y;

        $decStmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
        if ($decStmt) {
            $decStmt->bind_param('sii', $sec, $px, $py);
            $decStmt->execute();
            $decRes = $decStmt->get_result();
            if ($decRes && ($dRow = $decRes->fetch_assoc())) {
                $decStmt->close();
                $name = trim(implode(' ', array_filter([$dRow['first_name'], $dRow['middle_name'], $dRow['last_name'], $dRow['suffix']])));
                return ['status' => 'OCCUPIED', 'is_taken' => true, 'owner_name' => $name, 'has_deceased' => true];
            }
            $decStmt->close();
        }

        $cleanSec = strtoupper(trim(preg_replace('/^SECTION\s*/i', '', $sec)));
        $ownStmt = $conn->prepare("SELECT upo.ownership_status, u.first_name, u.last_name, u.phone_no
            FROM user_plot_ownership upo
            LEFT JOIN tbl_user u ON u.user_id = upo.user_id
            WHERE (REPLACE(UPPER(TRIM(upo.section)), 'SECTION ', '') = ? OR UPPER(TRIM(upo.section)) = ?) AND upo.plot_x = ? AND upo.plot_y = ? AND upo.verification_status = 'VERIFIED' AND upo.ownership_status != 'CANCELLED'
            LIMIT 1");
        if ($ownStmt) {
            $ownStmt->bind_param('ssii', $cleanSec, $sec, $px, $py);
            $ownStmt->execute();
            $ownRes = $ownStmt->get_result();
            if ($ownRes && ($oRow = $ownRes->fetch_assoc())) {
                $ownStmt->close();
                $ownerName = trim($oRow['first_name'] . ' ' . $oRow['last_name']);
                $stat = strtoupper(trim($oRow['ownership_status'] ?? 'OWNED'));
                return ['status' => $stat, 'is_taken' => true, 'owner_name' => $ownerName, 'has_deceased' => false];
            }
            $ownStmt->close();
        }

        $poStmt = $conn->prepare("SELECT owner_name, first_name, last_name FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND active = 1 LIMIT 1");
        if ($poStmt) {
            $poStmt->bind_param('sii', $sec, $px, $py);
            $poStmt->execute();
            $poRes = $poStmt->get_result();
            if ($poRes && ($poRow = $poRes->fetch_assoc())) {
                $poStmt->close();
                $name = trim((string)($poRow['owner_name'] ?? ''));
                if (!$name) $name = trim($poRow['first_name'] . ' ' . $poRow['last_name']);
                return ['status' => 'RESERVED', 'is_taken' => true, 'owner_name' => $name, 'has_deceased' => false];
            }
            $poStmt->close();
        }

        return ['status' => 'AVAILABLE', 'is_taken' => false, 'owner_name' => null, 'has_deceased' => false];
    }
}

if (!function_exists('is_plot_really_available')) {
    function is_plot_really_available($conn, $section, $plot_x, $plot_y) {
        if (!($conn instanceof mysqli) || $conn->connect_errno) {
            return false;
        }
        $sec = strtoupper(trim($section));
        $px = (int)$plot_x;
        $py = (int)$plot_y;

        $decStmt = $conn->prepare("SELECT deceased_id FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
        if ($decStmt) {
            $decStmt->bind_param('sii', $sec, $px, $py);
            $decStmt->execute();
            if ($decStmt->get_result()->fetch_assoc()) {
                $decStmt->close();
                return false;
            }
            $decStmt->close();
        }

        $poStmt = $conn->prepare("SELECT id FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND active = 1 LIMIT 1");
        if ($poStmt) {
            $poStmt->bind_param('sii', $sec, $px, $py);
            $poStmt->execute();
            if ($poStmt->get_result()->fetch_assoc()) {
                $poStmt->close();
                return false;
            }
            $poStmt->close();
        }

        if (function_exists('ensure_user_plot_ownership_table')) {
            ensure_user_plot_ownership_table($conn);
        }
        $ownStmt = $conn->prepare("SELECT id FROM user_plot_ownership WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND verification_status = 'VERIFIED' LIMIT 1");
        if ($ownStmt) {
            $ownStmt->bind_param('sii', $sec, $px, $py);
            $ownStmt->execute();
            if ($ownStmt->get_result()->fetch_assoc()) {
                $ownStmt->close();
                return false;
            }
            $ownStmt->close();
        }

        $pStmt = $conn->prepare("SELECT status FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
        if ($pStmt) {
            $pStmt->bind_param('sii', $sec, $px, $py);
            $pStmt->execute();
            $pRow = $pStmt->get_result()->fetch_assoc();
            $pStmt->close();
            if ($pRow) {
                $st = strtolower(trim($pRow['status'] ?? ''));
                if ($st === 'occupied' || $st === 'reserved' || $st === 'purchased' || $st === 'unavailable') {
                    return false;
                }
            }
        }

        $bStmt = $conn->prepare("SELECT id FROM tbl_bookings WHERE UPPER(TRIM(section)) = ? AND status IN ('pending', 'approved', 'confirmed', 'paid') AND (plot LIKE ? OR plot LIKE ? OR grave_location LIKE ?) LIMIT 1");
        if ($bStmt) {
            $pat1 = "%{$px}-{$py}%";
            $pat2 = "%{$px},{$py}%";
            $bStmt->bind_param('ssss', $sec, $pat1, $pat2, $pat1);
            $bStmt->execute();
            if ($bStmt->get_result()->fetch_assoc()) {
                $bStmt->close();
                return false;
            }
            $bStmt->close();
        }

        return true;
    }
}

if (!function_exists('find_next_available_adjacent_plot')) {
    function find_next_available_adjacent_plot($conn, $section, $plot_x, $plot_y) {
        if (!($conn instanceof mysqli) || $conn->connect_errno) {
            return null;
        }
        $sec = strtoupper(trim($section));
        $px = (int)$plot_x;
        $py = (int)$plot_y;

        $candidates = [];

        $immediateY = ($py % 2 === 1) ? ($py + 1) : max(1, $py - 1);
        $candidates[] = [$px, $immediateY];

        for ($r = 1; $r <= 4; $r++) {
            if ($r !== $py && $r !== $immediateY) {
                $candidates[] = [$px, $r];
            }
        }

        for ($colOffset = 1; $colOffset <= 30; $colOffset++) {
            foreach ([$px + $colOffset, $px - $colOffset] as $col) {
                if ($col >= 1 && $col <= 50) {
                    $candidates[] = [$col, $py];
                    $candidates[] = [$col, ($py % 2 === 1) ? ($py + 1) : max(1, $py - 1)];
                    for ($r = 1; $r <= 4; $r++) {
                        $candidates[] = [$col, $r];
                    }
                }
            }
        }

        $tested = [];
        foreach ($candidates as $cand) {
            $cx = $cand[0];
            $cy = $cand[1];
            $key = "{$cx}-{$cy}";
            if (isset($tested[$key])) continue;
            $tested[$key] = true;

            if ($cx === $px && $cy === $py) continue;

            if (is_plot_really_available($conn, $sec, $cx, $cy)) {
                return [
                    'section' => $sec,
                    'plot_x' => $cx,
                    'plot_y' => $cy,
                    'plot_no' => "{$sec}- {$cx},{$cy}"
                ];
            }
        }

        return null;
    }
}

if (!function_exists('sync_user_existing_cemetery_records')) {

    function sync_user_existing_cemetery_records($conn, $user_id, $force = false) {
        $user_id = (int)$user_id;
        if ($user_id <= 0 || !($conn instanceof mysqli) || $conn->connect_errno) {
            return 0;
        }

        if (!$force && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $user_id) {
            $lastSync = (int)($_SESSION['last_cemetery_sync_' . $user_id] ?? 0);
            if ($lastSync > 0 && (time() - $lastSync < 600)) {
                return 0;
            }
            $_SESSION['last_cemetery_sync_' . $user_id] = time();
        }

        ensure_user_plot_ownership_table($conn);

        $uStmt = $conn->prepare("SELECT role, first_name, middle_name, last_name, suffix, email, phone_no FROM tbl_user WHERE user_id = ? LIMIT 1");
        if (!$uStmt) return 0;
        $uStmt->bind_param('i', $user_id);
        $uStmt->execute();
        $uRow = $uStmt->get_result()->fetch_assoc();
        $uStmt->close();
        if (!$uRow) return 0;

        if (strtoupper(trim($uRow['role'] ?? '')) === 'ADMIN') {
            return 0;
        }

        $fName = strtoupper(trim($uRow['first_name'] ?? ''));
        $lName = strtoupper(trim($uRow['last_name'] ?? ''));
        $email = strtolower(trim($uRow['email'] ?? ''));
        $phone = preg_replace('/\D+/', '', $uRow['phone_no'] ?? '');
        $fullName = trim($fName . ' ' . $lName);
        $linkedCount = 0;

        $poSql = "SELECT section, plot_x, plot_y, owner_name, reserved_by FROM tbl_plot_owners WHERE active = 1 AND (reserved_by = ? OR UPPER(TRIM(owner_name)) = ? OR (UPPER(TRIM(first_name)) = ? AND UPPER(TRIM(last_name)) = ?)";
        if ($phone !== '' && strlen($phone) >= 8) {
            $poSql .= " OR REPLACE(REPLACE(phone_no, '-', ''), ' ', '') = '{$phone}'";
        }
        $poSql .= ")";
        $poStmt = $conn->prepare($poSql);
        if ($poStmt) {
            $poStmt->bind_param('isss', $user_id, $fullName, $fName, $lName);
            $poStmt->execute();
            $poRes = $poStmt->get_result();
            while ($poRes && ($pRow = $poRes->fetch_assoc())) {
                $sec = strtoupper(trim($pRow['section']));
                $px = (int)$pRow['plot_x'];
                $py = (int)$pRow['plot_y'];
                if ($px <= 0 || $py <= 0) continue;

                $chk = $conn->query("SELECT id FROM user_plot_ownership WHERE user_id = {$user_id} AND UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py} LIMIT 1");
                if ($chk && $chk->num_rows > 0) continue;

                $fPlotId = null;
                $tp = $conn->query("SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py} LIMIT 1");
                if ($tp && ($tpRow = $tp->fetch_assoc())) $fPlotId = (int)$tpRow['plot_id'];

                $ins = $conn->prepare("INSERT INTO user_plot_ownership (user_id, section, plot_x, plot_y, plot_id, ownership_status, verification_status, claim_source, notes)
                    VALUES (?, ?, ?, ?, ?, 'OWNED', 'VERIFIED', 'EXISTING_RECORD', 'Associated with cemetery owner record on file')
                    ON DUPLICATE KEY UPDATE verification_status = 'VERIFIED', ownership_status = 'OWNED'");
                if ($ins) {
                    $ins->bind_param('isiis', $user_id, $sec, $px, $py, $fPlotId);
                    $ins->execute();
                    $ins->close();
                    $linkedCount++;
                }

                $conn->query("UPDATE tbl_plot_owners SET reserved_by = {$user_id} WHERE active = 1 AND UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py} AND (reserved_by IS NULL OR reserved_by = 0)");
            }
            $poStmt->close();
        }

        $decSql = "SELECT deceased_id, section, plot_x, plot_y, first_name, last_name FROM tbl_deceased WHERE (user_id = ? OR (UPPER(TRIM(booker_name)) = ? AND section IS NOT NULL AND section != ''))";
        $decStmt = $conn->prepare($decSql);
        if ($decStmt) {
            $decStmt->bind_param('is', $user_id, $fullName);
            $decStmt->execute();
            $decRes = $decStmt->get_result();
            while ($decRes && ($dRow = $decRes->fetch_assoc())) {
                $sec = strtoupper(trim($dRow['section']));
                $px = (int)$dRow['plot_x'];
                $py = (int)$dRow['plot_y'];
                if ($px <= 0 || $py <= 0) continue;

                $decName = trim($dRow['first_name'] . ' ' . $dRow['last_name']);

                $fPlotId = null;
                $tp = $conn->query("SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py} LIMIT 1");
                if ($tp && ($tpRow = $tp->fetch_assoc())) $fPlotId = (int)$tpRow['plot_id'];

                $ins = $conn->prepare("INSERT INTO user_plot_ownership (user_id, section, plot_x, plot_y, plot_id, ownership_status, verification_status, claim_source, deceased_reference, notes)
                    VALUES (?, ?, ?, ?, ?, 'OWNED', 'VERIFIED', 'DECEASED_RECORD', ?, 'Linked via deceased family record')
                    ON DUPLICATE KEY UPDATE verification_status = 'VERIFIED', ownership_status = 'OWNED', deceased_reference = VALUES(deceased_reference)");
                if ($ins) {
                    $ins->bind_param('isiiss', $user_id, $sec, $px, $py, $fPlotId, $decName);
                    $ins->execute();
                    $ins->close();
                    $linkedCount++;
                }

                $conn->query("UPDATE tbl_deceased SET user_id = {$user_id} WHERE deceased_id = " . (int)$dRow['deceased_id'] . " AND (user_id IS NULL OR user_id = 0)");
            }
            $decStmt->close();
        }

        // Synchronize approved plot transfer requests for this user
        $chkPtrTable = $conn->query("SHOW TABLES LIKE 'plot_transfer_requests'");
        if ($chkPtrTable && $chkPtrTable->num_rows > 0) {
            $ptrSql = "SELECT ptr.requested_section, ptr.requested_plot_x, ptr.requested_plot_y, ptr.current_section, ptr.current_plot_x, ptr.current_plot_y, ptr.deceased_id,
                              d.first_name, d.last_name, d.middle_name, d.suffix
                       FROM plot_transfer_requests ptr
                       JOIN tbl_deceased d ON d.deceased_id = ptr.deceased_id
                       WHERE ptr.user_id = ? AND ptr.status = 'approved'";
            $ptrStmt = $conn->prepare($ptrSql);
            if ($ptrStmt) {
                $ptrStmt->bind_param('i', $user_id);
                $ptrStmt->execute();
                $ptrRes = $ptrStmt->get_result();
                while ($ptrRes && ($pRow = $ptrRes->fetch_assoc())) {
                    $sec = strtoupper(trim($pRow['requested_section']));
                    $px = (int)$pRow['requested_plot_x'];
                    $py = (int)$pRow['requested_plot_y'];
                    $oldS = strtoupper(trim($pRow['current_section']));
                    $oldX = (int)$pRow['current_plot_x'];
                    $oldY = (int)$pRow['current_plot_y'];
                    $did = (int)$pRow['deceased_id'];
                    if ($px <= 0 || $py <= 0) continue;

                    $decName = trim(implode(' ', array_filter([$pRow['first_name'], $pRow['middle_name'] ?? '', $pRow['last_name'], $pRow['suffix'] ?? ''])));
                    $fPlotId = null;
                    $tp = $conn->query("SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py} LIMIT 1");
                    if ($tp && ($tpRow = $tp->fetch_assoc())) $fPlotId = (int)$tpRow['plot_id'];

                    $ins = $conn->prepare("INSERT INTO user_plot_ownership (user_id, section, plot_x, plot_y, plot_id, ownership_status, verification_status, claim_source, deceased_reference, notes, verified_at)
                        VALUES (?, ?, ?, ?, ?, 'OWNED', 'VERIFIED', 'ADMIN_ASSIGNED', ?, 'Transferred deceased plot', NOW())
                        ON DUPLICATE KEY UPDATE verification_status = 'VERIFIED', ownership_status = 'OWNED', deceased_reference = VALUES(deceased_reference)");
                    if ($ins) {
                        $ins->bind_param('isiiss', $user_id, $sec, $px, $py, $fPlotId, $decName);
                        $ins->execute();
                        $ins->close();
                        $linkedCount++;
                    }

                    $conn->query("UPDATE tbl_deceased SET user_id = {$user_id} WHERE deceased_id = {$did} AND (user_id IS NULL OR user_id = 0)");

                    // If old plot has 0 remaining deceased and is marked available, remove stale ownership
                    if (!empty($oldS) && $oldX > 0 && $oldY > 0) {
                        $chkOldOcc = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$oldS}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                        if ($chkOldOcc && (int)($chkOldOcc->fetch_assoc()['c'] ?? 0) === 0) {
                            $chkOldPl = $conn->query("SELECT status FROM tbl_plots WHERE UPPER(TRIM(section)) = '{$oldS}' AND plot_x = {$oldX} AND plot_y = {$oldY} LIMIT 1");
                            if ($chkOldPl && strtolower(trim((string)$chkOldPl->fetch_assoc()['status'] ?? '')) === 'available') {
                                $conn->query("DELETE FROM user_plot_ownership WHERE user_id = {$user_id} AND UPPER(TRIM(section)) = '{$oldS}' AND plot_x = {$oldX} AND plot_y = {$oldY}");
                            }
                        }
                    }
                }
                $ptrStmt->close();
            }
        }

        $bSql = "SELECT id, section, plot_x, plot_y, deceased_name FROM tbl_bookings WHERE (user_id = ? OR (customer_email = ? AND customer_email != '') OR UPPER(TRIM(customer_name)) = ?) AND LOWER(status) IN ('confirmed', 'approved', 'paid')";
        $bStmt = $conn->prepare($bSql);
        if ($bStmt) {
            $bStmt->bind_param('iss', $user_id, $email, $fullName);
            $bStmt->execute();
            $bRes = $bStmt->get_result();
            while ($bRes && ($bRow = $bRes->fetch_assoc())) {
                $sec = strtoupper(trim($bRow['section']));
                $px = (int)$bRow['plot_x'];
                $py = (int)$bRow['plot_y'];
                if ($px <= 0 || $py <= 0) continue;

                $bDec = trim($bRow['deceased_name'] ?? '');

                $fPlotId = null;
                $tp = $conn->query("SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py} LIMIT 1");
                if ($tp && ($tpRow = $tp->fetch_assoc())) $fPlotId = (int)$tpRow['plot_id'];

                $ins = $conn->prepare("INSERT INTO user_plot_ownership (user_id, section, plot_x, plot_y, plot_id, ownership_status, verification_status, claim_source, deceased_reference, notes)
                    VALUES (?, ?, ?, ?, ?, 'OWNED', 'VERIFIED', 'BOOKING', ?, 'Linked via confirmed booking record')
                    ON DUPLICATE KEY UPDATE verification_status = 'VERIFIED', ownership_status = 'OWNED'");
                if ($ins) {
                    $ins->bind_param('isiiss', $user_id, $sec, $px, $py, $fPlotId, $bDec);
                    $ins->execute();
                    $ins->close();
                    $linkedCount++;
                }
            }
            $bStmt->close();
        }

        return $linkedCount;
    }
}

if (!function_exists('is_user_authorized_for_deceased')) {

    function is_user_authorized_for_deceased($conn, $user_id, $deceased_id) {
        $user_id = (int)$user_id;
        $deceased_id = (int)$deceased_id;
        if ($deceased_id <= 0) {
            return false;
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $role = strtolower(trim((string)($_SESSION['role'] ?? ($_SESSION['user_type'] ?? ''))));
        if ($role === 'admin' || $role === 'administrator') {
            return true;
        }
        if ($user_id <= 0) {
            return false;
        }

        $stmt = $conn->prepare("SELECT deceased_id, user_id, section, plot_x, plot_y FROM tbl_deceased WHERE deceased_id = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('i', $deceased_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $dec = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$dec) return false;

        if ((int)$dec['user_id'] === $user_id) {
            return true;
        }

        if (!empty($dec['user_id']) && (int)$dec['user_id'] > 0 && (int)$dec['user_id'] !== $user_id) {
            return false;
        }

        $sec = strtoupper(trim((string)($dec['section'] ?? '')));
        $px = (int)($dec['plot_x'] ?? 0);
        $py = (int)($dec['plot_y'] ?? 0);

        if ($sec !== '' && $px > 0 && $py > 0) {

            $upo = $conn->prepare("SELECT id FROM user_plot_ownership 
                                   WHERE user_id = ? AND UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? 
                                     AND verification_status = 'VERIFIED' LIMIT 1");
            if ($upo) {
                $upo->bind_param('isii', $user_id, $sec, $px, $py);
                $upo->execute();
                $upoRes = $upo->get_result();
                $isUpo = ($upoRes && $upoRes->num_rows > 0);
                $upo->close();
                if ($isUpo) {

                    $conn->query("UPDATE tbl_deceased SET user_id = {$user_id} WHERE deceased_id = {$deceased_id} AND (user_id IS NULL OR user_id = 0)");
                    return true;
                }
            }

            $po = $conn->prepare("SELECT id FROM tbl_plot_owners 
                                  WHERE reserved_by = ? AND UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? 
                                    AND active = 1 LIMIT 1");
            if ($po) {
                $po->bind_param('isii', $user_id, $sec, $px, $py);
                $po->execute();
                $poRes = $po->get_result();
                $isPo = ($poRes && $poRes->num_rows > 0);
                $po->close();
                if ($isPo) {
                    $conn->query("UPDATE tbl_deceased SET user_id = {$user_id} WHERE deceased_id = {$deceased_id} AND (user_id IS NULL OR user_id = 0)");
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('get_user_linked_deceased_records')) {

    function get_user_linked_deceased_records($conn, $user_id) {
        $user_id = (int)$user_id;
        if ($user_id <= 0) return [];

        if (function_exists('sync_user_existing_cemetery_records')) {
            sync_user_existing_cemetery_records($conn, $user_id);
        }

        $records = [];
        $seen = [];

        $stmt1 = $conn->prepare("SELECT deceased_id, first_name, middle_name, last_name, suffix, bday, date_died, interment_date, section, plot_x, plot_y, relationship_to_account_holder
                                 FROM tbl_deceased 
                                 WHERE user_id = ? 
                                 ORDER BY deceased_id ASC");
        if ($stmt1) {
            $stmt1->bind_param('i', $user_id);
            $stmt1->execute();
            $res1 = $stmt1->get_result();
            while ($res1 && ($row = $res1->fetch_assoc())) {
                $did = (int)$row['deceased_id'];
                if (!isset($seen[$did])) {
                    $seen[$did] = true;
                    $records[] = $row;
                }
            }
            $stmt1->close();
        }

        $stmt2 = $conn->prepare("SELECT d.deceased_id, d.first_name, d.middle_name, d.last_name, d.suffix, d.bday, d.date_died, d.interment_date, d.section, d.plot_x, d.plot_y, d.relationship_to_account_holder
                                 FROM tbl_deceased d
                                 INNER JOIN user_plot_ownership upo 
                                    ON UPPER(TRIM(d.section)) = UPPER(TRIM(upo.section)) 
                                   AND d.plot_x = upo.plot_x 
                                   AND d.plot_y = upo.plot_y
                                 WHERE upo.user_id = ? 
                                   AND upo.verification_status = 'VERIFIED'
                                   AND (d.user_id IS NULL OR d.user_id = 0 OR d.user_id = ?)
                                 ORDER BY d.deceased_id ASC");
        if ($stmt2) {
            $stmt2->bind_param('ii', $user_id, $user_id);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($res2 && ($row = $res2->fetch_assoc())) {
                $did = (int)$row['deceased_id'];
                if (!isset($seen[$did])) {
                    $seen[$did] = true;
                    $records[] = $row;
                }
            }
            $stmt2->close();
        }

        // Also include deceased linked via approved transfer requests submitted by this user
        $chkPtrTable2 = $conn->query("SHOW TABLES LIKE 'plot_transfer_requests'");
        if ($chkPtrTable2 && $chkPtrTable2->num_rows > 0) {
            $stmt3 = $conn->prepare("SELECT d.deceased_id, d.first_name, d.middle_name, d.last_name, d.suffix, d.bday, d.date_died, d.interment_date, d.section, d.plot_x, d.plot_y, d.relationship_to_account_holder
                                     FROM tbl_deceased d
                                     INNER JOIN plot_transfer_requests ptr ON ptr.deceased_id = d.deceased_id
                                     WHERE ptr.user_id = ? AND ptr.status = 'approved'
                                     ORDER BY d.deceased_id ASC");
            if ($stmt3) {
                $stmt3->bind_param('i', $user_id);
                $stmt3->execute();
                $res3 = $stmt3->get_result();
                while ($res3 && ($row = $res3->fetch_assoc())) {
                    $did = (int)$row['deceased_id'];
                    if (!isset($seen[$did])) {
                        $seen[$did] = true;
                        $records[] = $row;
                    }
                    @$conn->query("UPDATE tbl_deceased SET user_id = {$user_id} WHERE deceased_id = {$did} AND (user_id IS NULL OR user_id = 0)");
                }
                $stmt3->close();
            }
        }

        return $records;
    }
}

if (!function_exists('is_user_authorized_for_plot')) {

    function is_user_authorized_for_plot($conn, $user_id, $section, $plot_x, $plot_y, $strict_user_only = false) {
        $user_id = (int)$user_id;
        if ($user_id <= 0) return false;

        if (!$strict_user_only) {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $role = strtolower(trim((string)($_SESSION['role'] ?? ($_SESSION['user_type'] ?? ''))));
            if ($role === 'admin' || $role === 'administrator') {
                return true;
            }
        }

        $sec = strtoupper(trim((string)$section));
        $sec = preg_replace('/^SECTION\s*/i', '', $sec);
        $px = (int)$plot_x;
        $py = (int)$plot_y;
        if ($sec === '' || $px <= 0 || $py <= 0) return false;

        // 1. Check user_plot_ownership
        $upo = $conn->prepare("SELECT id FROM user_plot_ownership 
                               WHERE user_id = ? AND (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = ? OR UPPER(TRIM(section)) = ?) AND plot_x = ? AND plot_y = ? 
                                 AND (verification_status IS NULL OR verification_status IN ('VERIFIED', 'PENDING') OR verification_status != 'REJECTED') LIMIT 1");
        if ($upo) {
            $upo->bind_param('issii', $user_id, $sec, $sec, $px, $py);
            $upo->execute();
            $upoRes = $upo->get_result();
            $isUpo = ($upoRes && $upoRes->num_rows > 0);
            $upo->close();
            if ($isUpo) return true;
        }

        // 2. Check booking_plots + tbl_bookings
        $chkBP = $conn->query("SHOW TABLES LIKE 'booking_plots'");
        if ($chkBP && $chkBP->num_rows > 0) {
            $bp = $conn->prepare("SELECT bp.id FROM booking_plots bp 
                                  JOIN tbl_bookings b ON b.id = bp.booking_id 
                                  WHERE b.user_id = ? AND (REPLACE(UPPER(TRIM(bp.section)), 'SECTION ', '') = ? OR UPPER(TRIM(bp.section)) = ?) AND bp.plot_x = ? AND bp.plot_y = ? 
                                    AND LOWER(TRIM(COALESCE(b.status, ''))) NOT IN ('cancelled', 'rejected') LIMIT 1");
            if ($bp) {
                $bp->bind_param('issii', $user_id, $sec, $sec, $px, $py);
                $bp->execute();
                $bpRes = $bp->get_result();
                $isBp = ($bpRes && $bpRes->num_rows > 0);
                $bp->close();
                if ($isBp) return true;
            }
        }

        // 3. Check tbl_bookings directly (by plot_x/plot_y or plot identifier string)
        $plotHyphen = $px . '-' . $py;
        $plotComma = $px . ',' . $py;
        $plotLike = '%' . $px . '-' . $py . '%';
        $tb = $conn->prepare("SELECT id FROM tbl_bookings 
                              WHERE user_id = ? AND (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = ? OR UPPER(TRIM(section)) = ?) 
                                AND (
                                    (plot_x = ? AND plot_y = ?) 
                                    OR plot = ? 
                                    OR plot = ? 
                                    OR plot LIKE ? 
                                    OR grave_location LIKE ?
                                )
                                AND LOWER(TRIM(COALESCE(status, ''))) NOT IN ('cancelled', 'rejected') LIMIT 1");
        if ($tb) {
            $tb->bind_param('issiissss', $user_id, $sec, $sec, $px, $py, $plotHyphen, $plotComma, $plotLike, $plotLike);
            $tb->execute();
            $tbRes = $tb->get_result();
            $isTb = ($tbRes && $tbRes->num_rows > 0);
            $tb->close();
            if ($isTb) return true;
        }

        // 4. Check tbl_plot_owners
        $po = $conn->prepare("SELECT id, active FROM tbl_plot_owners 
                              WHERE reserved_by = ? AND (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = ? OR UPPER(TRIM(section)) = ?) AND plot_x = ? AND plot_y = ? 
                              ORDER BY active DESC, id DESC LIMIT 1");
        if ($po) {
            $po->bind_param('issii', $user_id, $sec, $sec, $px, $py);
            $po->execute();
            $poRes = $po->get_result();
            if ($poRes && ($poRow = $poRes->fetch_assoc())) {
                if ((int)$poRow['active'] === 1) {
                    $po->close();
                    return true;
                }
                // If active = 0, check if an active booking exists for this user on this plot
                $chkActiveBk = $conn->prepare("SELECT id FROM tbl_bookings WHERE user_id = ? AND (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = ? OR UPPER(TRIM(section)) = ?) AND (plot = ? OR plot = ? OR (plot_x = ? AND plot_y = ?)) AND LOWER(TRIM(COALESCE(status, ''))) NOT IN ('cancelled', 'rejected') LIMIT 1");
                if ($chkActiveBk) {
                    $chkActiveBk->bind_param('issisii', $user_id, $sec, $sec, $plotHyphen, $plotComma, $px, $py);
                    $chkActiveBk->execute();
                    if ($chkActiveBk->get_result()->num_rows > 0) {
                        $chkActiveBk->close();
                        $po->close();
                        return true;
                    }
                    $chkActiveBk->close();
                }
            }
            $po->close();
        }

        // 5. Check tbl_deceased
        $td = $conn->prepare("SELECT deceased_id FROM tbl_deceased 
                              WHERE user_id = ? AND (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = ? OR UPPER(TRIM(section)) = ?) AND plot_x = ? AND plot_y = ? LIMIT 1");
        if ($td) {
            $td->bind_param('issii', $user_id, $sec, $sec, $px, $py);
            $td->execute();
            $tdRes = $td->get_result();
            $isTd = ($tdRes && $tdRes->num_rows > 0);
            $td->close();
            if ($isTd) return true;
        }

        // 6. Consistency check: if get_user_owned_plots reports this plot belongs to user, authorize!
        if (function_exists('get_user_owned_plots')) {
            $ownedPlots = get_user_owned_plots($conn, $user_id);
            if (!empty($ownedPlots)) {
                foreach ($ownedPlots as $op) {
                    $opSec = strtoupper(trim(preg_replace('/^SECTION\s*/i', '', $op['section'] ?? '')));
                    $opX = (int)($op['plot_x'] ?? 0);
                    $opY = (int)($op['plot_y'] ?? 0);
                    if ($opSec === $sec && $opX === $px && $opY === $py) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
?>

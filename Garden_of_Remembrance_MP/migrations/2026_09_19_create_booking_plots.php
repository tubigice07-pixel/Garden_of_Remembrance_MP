<?php
require_once __DIR__ . '/../connection.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== CREATING BOOKING_PLOTS TABLE ===\n";

$createSql = "CREATE TABLE IF NOT EXISTS `booking_plots` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `booking_id` INT(11) NOT NULL,
    `plot_id` INT(11) DEFAULT NULL,
    `section` VARCHAR(10) NOT NULL,
    `plot_x` INT(11) NOT NULL,
    `plot_y` INT(11) NOT NULL,
    `plot_number` VARCHAR(50) NOT NULL,
    `lawn_type` VARCHAR(50) DEFAULT 'Regular',
    `price_at_booking` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status` VARCHAR(20) DEFAULT 'reserved',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_bp_booking_id` (`booking_id`),
    KEY `idx_bp_plot_id` (`plot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($createSql)) {
    echo "booking_plots table verified/created successfully.\n";
} else {
    echo "Failed to create booking_plots: " . $conn->error . "\n";
}

$checkEmpty = $conn->query("SELECT COUNT(*) AS c FROM `booking_plots`")->fetch_assoc();
if ((int)$checkEmpty['c'] === 0) {
    echo "Backfilling existing single-plot bookings into booking_plots...\n";
    $bQuery = $conn->query("SELECT id, section, plot, plot_id, plot_price, status, lawn_type FROM tbl_bookings WHERE section IS NOT NULL AND section != ''");

    $hasLawnType = $conn->query("SHOW COLUMNS FROM tbl_bookings LIKE 'lawn_type'")->num_rows > 0;
    $hasArea = $conn->query("SHOW COLUMNS FROM tbl_bookings LIKE 'area'")->num_rows > 0;

    $insStmt = $conn->prepare("INSERT INTO booking_plots (booking_id, plot_id, section, plot_x, plot_y, plot_number, lawn_type, price_at_booking, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    $backfilled = 0;
    $bQueryAll = $conn->query("SELECT id, section, plot, plot_id, plot_price, status, created_at FROM tbl_bookings WHERE section IS NOT NULL AND section != ''");
    while ($b = $bQueryAll->fetch_assoc()) {
        $bId = (int)$b['id'];
        $sec = strtoupper(trim($b['section']));
        $rawPlot = trim($b['plot'] ?? '');
        $pId = !empty($b['plot_id']) ? (int)$b['plot_id'] : null;
        $price = (float)($b['plot_price'] ?? 0);
        $stat = !empty($b['status']) ? trim($b['status']) : 'reserved';

        $px = 0;
        $py = 0;
        if (preg_match('/(\d+)[\s,-]+(\d+)/', $rawPlot, $m)) {
            $px = (int)$m[1];
            $py = (int)$m[2];
        }
        $plotNum = $sec . '- ' . ($px ? "$px,$py" : $rawPlot);
        $lawnType = 'Lawn (Regular)';

        // Resolve plot_id and lawn_type from tbl_plots if missing
        if ($px > 0 && $py > 0) {
            $pChk = $conn->query("SELECT plot_id, lawn_type FROM tbl_plots WHERE UPPER(TRIM(section)) = '{$conn->real_escape_string($sec)}' AND plot_x = {$px} AND plot_y = {$py} LIMIT 1");
            if ($pChk && ($pRow = $pChk->fetch_assoc())) {
                $pId = (int)$pRow['plot_id'];
                if (!empty($pRow['lawn_type'])) {
                    $lawnType = $pRow['lawn_type'];
                }
            }
        }

        $insStmt->bind_param('iisiissds', $bId, $pId, $sec, $px, $py, $plotNum, $lawnType, $price, $stat);
        if ($insStmt->execute()) {
            $backfilled++;
        }
    }
    $insStmt->close();
    echo "Backfilled {$backfilled} existing bookings into booking_plots.\n";
} else {
    echo "booking_plots table already contains " . $checkEmpty['c'] . " records.\n";
}

echo "Migration finished.\n";

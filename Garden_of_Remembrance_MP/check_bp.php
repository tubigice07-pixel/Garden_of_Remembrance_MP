<?php
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/gps_calculator.php';
header('Content-Type: text/plain');

echo "=== GPS SETTINGS ===\n";
$settings = CemeteryGPSCalculator::getSettings($conn);
print_r($settings);

echo "\n=== CALIBRATION POINTS ===\n";
$points = CemeteryGPSCalculator::getCalibrationPoints($conn, false);
print_r($points);

echo "\n=== CALIBRATION MODEL ===\n";
$model = CemeteryGPSCalculator::solveCalibrationTransform($conn, true);
print_r($model);

echo "\n=== PLOTS COORDINATES SUMMARY BY SECTION ===\n";
$res = $conn->query("SELECT section, 
                            COUNT(*) as total_plots,
                            SUM(CASE WHEN latitude IS NOT NULL AND latitude != 0 AND longitude IS NOT NULL AND longitude != 0 THEN 1 ELSE 0 END) as with_coords,
                            SUM(CASE WHEN latitude IS NULL OR latitude = 0 OR longitude IS NULL OR longitude = 0 THEN 1 ELSE 0 END) as missing_coords,
                            MIN(latitude) as min_lat, MAX(latitude) as max_lat,
                            MIN(longitude) as min_lng, MAX(longitude) as max_lng
                     FROM tbl_plots 
                     GROUP BY section 
                     ORDER BY section ASC");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}

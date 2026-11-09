<?php
/**
 * Garden of Remembrance Memorial Park
 * Plot Spatial Lookup & Navigation Service
 * 
 * Single source of truth for spatial map coordinates parsed directly 
 * from the manual arrays in sections/popup.php (Sections A to F).
 * Provides pixel coordinates, centroid, aisle wayfinding path,
 * and step-by-step walking directions.
 */

if (!class_exists('PlotSpatialLookup')) {
    class PlotSpatialLookup {

        private static $cache = null;

        /**
         * Section entrance points on section map images (1440x1080 canvas)
         * Defines where visitors enter each section walkway.
         */
        private static $sectionEntrances = [
            'A' => ['x' => 840, 'y' => 55,  'label' => 'Section A Main Aisle Entrance (North-East)'],
            'B' => ['x' => 850, 'y' => 77,  'label' => 'Section B Main Aisle Entrance (North-East)'],
            'C' => ['x' => 780, 'y' => 90,  'label' => 'Section C Main Aisle Entrance (North-East)'],
            'D' => ['x' => 780, 'y' => 30,  'label' => 'Section D Main Aisle Entrance (North-East)'],
            'E' => ['x' => 780, 'y' => 28,  'label' => 'Section E Main Aisle Entrance (North-East)'],
            'F' => ['x' => 775, 'y' => 56,  'label' => 'Section F Main Aisle Entrance (North-East)']
        ];

        /**
         * Load and parse all six plot arrays from popup.php
         */
        public static function loadAllPlots() {
            if (self::$cache !== null) {
                return self::$cache;
            }

            self::$cache = [
                'A' => [],
                'B' => [],
                'C' => [],
                'D' => [],
                'E' => [],
                'F' => []
            ];

            $popupPaths = [
                __DIR__ . '/../sections/popup.php',
                __DIR__ . '/sections/popup.php',
                dirname(__DIR__) . '/sections/popup.php'
            ];

            $popupFile = null;
            foreach ($popupPaths as $p) {
                if (file_exists($p)) {
                    $popupFile = $p;
                    break;
                }
            }

            if (!$popupFile || !is_readable($popupFile)) {
                return self::$cache;
            }

            $content = @file_get_contents($popupFile);
            if (!$content) {
                return self::$cache;
            }

            $sectionPatterns = [
                'A' => '/\$plots\s*=\s*\[(.*?)\]\s*;/s',
                'B' => '/\$plotsB\s*=\s*\[(.*?)\]\s*;/s',
                'C' => '/\$plotsC\s*=\s*\[(.*?)\]\s*;/s',
                'D' => '/\$plotsD\s*=\s*\[(.*?)\]\s*;/s',
                'E' => '/\$plotsE\s*=\s*\[(.*?)\]\s*;/s',
                'F' => '/\$plotsF\s*=\s*\[(.*?)\]\s*;/s'
            ];

            foreach ($sectionPatterns as $sec => $pattern) {
                if (preg_match($pattern, $content, $matches)) {
                    $block = $matches[1];
                    if (preg_match_all('/\[\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\]/', $block, $entries, PREG_SET_ORDER)) {
                        foreach ($entries as $e) {
                            $px     = (int)$e[1];
                            $py     = (int)$e[2];
                            $left   = (int)$e[3];
                            $top    = (int)$e[4];
                            $width  = (int)$e[5];
                            $height = (int)$e[6];
                            $key    = "{$px}-{$py}";

                            // Avoid duplicate overwrite (e.g. Section A line 6425 duplicate)
                            if (!isset(self::$cache[$sec][$key])) {
                                $cx = $left + ($width / 2.0);
                                $cy = $top + ($height / 2.0);

                                self::$cache[$sec][$key] = [
                                    'section'  => $sec,
                                    'plot_x'   => $px,
                                    'plot_y'   => $py,
                                    'left'     => $left,
                                    'top'      => $top,
                                    'width'    => $width,
                                    'height'   => $height,
                                    'center_x' => $cx,
                                    'center_y' => $cy,
                                    'plot_key' => $key
                                ];
                            }
                        }
                    }
                }
            }

            return self::$cache;
        }

        /**
         * Get spatial coordinates and full wayfinding directions for a specific plot
         * 
         * @param string $section Section letter ('A'-'F')
         * @param int $plotX Row / X coordinate
         * @param int $plotY Plot / Y coordinate
         * @return array|null
         */
        public static function getCoordinates($section, $plotX, $plotY) {
            $all = self::loadAllPlots();
            $sec = strtoupper(trim((string)$section));
            $px = (int)$plotX;
            $py = (int)$plotY;
            $key = "{$px}-{$py}";

            if (!isset($all[$sec][$key])) {
                // Try reverse index fallback in case coordinates were keyed py-px
                $revKey = "{$py}-{$px}";
                if (isset($all[$sec][$revKey])) {
                    $key = $revKey;
                } else {
                    return null;
                }
            }

            $plot = $all[$sec][$key];

            // Calculate wayfinding route path from section entrance to plot
            $entrance = self::$sectionEntrances[$sec] ?? ['x' => $plot['left'], 'y' => 50, 'label' => "Section {$sec} Entrance"];
            
            // Construct walking waypoints:
            // 1. Start at Section Entrance Walkway
            // 1. Start from section entrance / main walkway
            // 2. Walk along main access aisle to the plot's row level (Y)
            // 3. Turn into row walkway leading directly to the plot center (X, Y)
            $waypoints = [
                ['x' => $entrance['x'], 'y' => $entrance['y'], 'step' => 'Start at Section Entrance'],
                ['x' => $entrance['x'], 'y' => $plot['center_y'], 'step' => 'Walk along Main Access Aisle'],
                ['x' => $plot['center_x'], 'y' => $plot['center_y'], 'step' => "Turn into Row {$plot['plot_x']} and arrive at Plot {$plot['plot_y']}"]
            ];

            // Human readable turn-by-turn directions
            $steps = [
                "1. Pumunta sa bungad ng <strong>Section {$sec}</strong> ({$entrance['label']}).",
                "2. Maglakad sa pangunahing daanan (aisle) patungo sa <strong>Row / Block {$plot['plot_x']}</strong>.",
                "3. Lumiko sa tapat ng hanay at hanapin ang <strong>Plot {$plot['plot_y']}</strong>.",
                "4. Matatagpuan ang puntod sa eksaktong minarkahang kahon."
            ];

            // Approximate real-world distance estimate (pixels * ~0.27m calibration scale)
            $pixelDistance = abs($entrance['x'] - $plot['center_x']) + abs($entrance['y'] - $plot['center_y']);
            $estimatedMeters = max(5, round($pixelDistance * 0.12)); // Rough scale for section walk
            $estimatedMinutes = max(1, ceil($estimatedMeters / 60));

            return array_merge($plot, [
                'entrance'          => $entrance,
                'waypoints'         => $waypoints,
                'directions_steps'  => $steps,
                'estimated_meters'  => $estimatedMeters,
                'estimated_minutes' => $estimatedMinutes
            ]);
        }

        /**
         * Get spatial coordinates by plot_id from tbl_plots
         */
        public static function getCoordinatesByPlotId($conn, $plotId) {
            $plotId = (int)$plotId;
            if ($plotId <= 0 || !($conn instanceof mysqli) || $conn->connect_errno) {
                return null;
            }

            $stmt = $conn->prepare("SELECT section, plot_x, plot_y FROM tbl_plots WHERE plot_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $plotId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $stmt->close();
                    return self::getCoordinates($row['section'], $row['plot_x'], $row['plot_y']);
                }
                $stmt->close();
            }

            return null;
        }

        /**
         * Get calculated GPS coordinates for a specific plot based on its manual layout definition
         */
        public static function getPlotGPS($section, $plotX, $plotY) {
            $plot = self::getCoordinates($section, $plotX, $plotY);
            if (!$plot) {
                return null;
            }
            if (class_exists('CemeteryGPSCalculator')) {
                global $conn;
                $calcRes = CemeteryGPSCalculator::calculatePlotGPS($conn, 0, $plot['section'], $plot['plot_x'], $plot['plot_y'], false);
                if (!empty($calcRes['success']) && !empty($calcRes['has_coordinates'])) {
                    return [
                        'latitude'  => (float)$calcRes['latitude'],
                        'longitude' => (float)$calcRes['longitude'],
                        'accuracy'  => 'manual pixel calibrated'
                    ];
                }
            }
            return null;
        }

        /**
         * Summary count of all parsed plots per section
         */
        public static function getPlotCounts() {
            $all = self::loadAllPlots();
            $counts = [];
            $total = 0;
            foreach ($all as $sec => $plots) {
                $c = count($plots);
                $counts[$sec] = $c;
                $total += $c;
            }
            $counts['TOTAL'] = $total;
            return $counts;
        }
    }
}

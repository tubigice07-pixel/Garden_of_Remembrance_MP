<?php

if (!class_exists('CemeteryGPSCalculator')) {
    class CemeteryGPSCalculator {

        private static $layoutCache = null;

        /**
         * Overview section boxes: the position and size of each section
         * on the cemetery overview map image (in overview map pixels).
         */
        private static $overviewSectionBoxes = [
            'A' => ['left' => 145.0, 'top' => 595.0, 'width' =>  96.0, 'height' => 70.0],
            'B' => ['left' => 145.0, 'top' => 505.0, 'width' =>  96.0, 'height' => 70.0],
            'C' => ['left' => 262.0, 'top' => 505.0, 'width' =>  96.0, 'height' => 70.0],
            'D' => ['left' => 263.0, 'top' => 595.0, 'width' =>  94.0, 'height' => 70.0],
            'E' => ['left' => 392.5, 'top' => 600.0, 'width' =>  85.0, 'height' => 70.0],
            'F' => ['left' => 393.0, 'top' => 510.0, 'width' =>  84.0, 'height' => 70.0],
            'G' => ['left' => 330.0, 'top' => 290.0, 'width' =>  60.0, 'height' => 180.0],
            'H' => ['left' => 415.0, 'top' => 305.0, 'width' =>  60.0, 'height' => 180.0],
            'I' => ['left' => 380.0, 'top' =>  95.0, 'width' =>  60.0, 'height' => 180.0],
            'J' => ['left' => 460.0, 'top' => 115.0, 'width' =>  60.0, 'height' => 180.0],
        ];

        /**
         * Per-section section map image dimensions (all are 1440x1080).
         * These are the pixel bounds of the plot areas within each section image.
         * Derived from the actual $plots arrays in popup.php:
         *   - imgW, imgH:     Section image dimensions in pixels
         *   - plotLeft:       Left edge of the plot area (westernmost plot's left)
         *   - plotRight:      Right edge of the plot area (easternmost plot's right)
         *   - plotTop:        Top edge of the plot area (northernmost plot's top)
         *   - plotBottom:     Bottom edge of the plot area (southernmost plot's bottom)
         *   - plotRight is cx of plot(1,1) side (plot_x=1 is on the EAST = right side)
         *   - plotLeft  is cx of plot(max,1) side (plot_x=max is on the WEST = left side)
         *
         * Layout convention (from popup.php plot data):
         *   - plot_x increases going WEST (left in image, lower ox in overview)
         *   - plot_y increases going SOUTH (down in image, higher oy in overview)
         *   - cx(1,1) is top-right (NE corner)
         *   - cx(max_px, max_py) is bottom-left (SW corner)
         */
        private static $sectionImageParams = [
            // All images are 1440x1080.
            // plotAreaLeft/Right: the horizontal span of the plot area (west/east edges).
            // plotAreaTop/Bottom: the vertical span of the plot area (north/south edges).
            // Derived from actual plot data in popup.php.
            'A' => [
                'imgW' => 1440, 'imgH' => 1080,
                'plotAreaLeft'   => 105,  // west edge  (plot_x=max side)
                'plotAreaRight'  => 840,  // east edge  (plot_x=1 side)
                'plotAreaTop'    => 55,   // north edge (plot_y=1 side)
                'plotAreaBottom' => 591,  // south edge (plot_y=max side)
            ],
            'B' => [
                'imgW' => 1440, 'imgH' => 1080,
                'plotAreaLeft'   => 82,
                'plotAreaRight'  => 853,
                'plotAreaTop'    => 77,
                'plotAreaBottom' => 561,
            ],
            'C' => [
                'imgW' => 1440, 'imgH' => 1080,
                'plotAreaLeft'   => 30,
                'plotAreaRight'  => 783,
                'plotAreaTop'    => 90,
                'plotAreaBottom' => 609,
            ],
            'D' => [
                'imgW' => 1440, 'imgH' => 1080,
                'plotAreaLeft'   => 46,
                'plotAreaRight'  => 786,
                'plotAreaTop'    => 30,
                'plotAreaBottom' => 624,
            ],
            'E' => [
                'imgW' => 1440, 'imgH' => 1080,
                'plotAreaLeft'   => 137,
                'plotAreaRight'  => 784,
                'plotAreaTop'    => 28,
                'plotAreaBottom' => 602,
            ],
            'F' => [
                'imgW' => 1440, 'imgH' => 1080,
                'plotAreaLeft'   => 107,
                'plotAreaRight'  => 777,
                'plotAreaTop'    => 56,
                'plotAreaBottom' => 628,
            ],
        ];

        private static $layoutBoundsCache = null;

        private static $solvedModelCache = null;

        private static $sectionDimensionsCache = null;

        /**
         * Load plot layouts by parsing popup.php.
         * This is used for UI hover/click positions; GPS calculation now uses
         * directOverviewPosition() instead for accuracy.
         */
        public static function loadSectionLayouts() {
            if (self::$layoutCache !== null) {
                return self::$layoutCache;
            }

            self::$layoutCache = [
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

            if ($popupFile && is_readable($popupFile)) {
                $content = @file_get_contents($popupFile);
                if ($content) {
                    $sectionMap = [
                        'A' => '/\$plots\s*=\s*\[(.*?)\]\s*;/s',
                        'B' => '/\$plotsB\s*=\s*\[(.*?)\]\s*;/s',
                        'C' => '/\$plotsC\s*=\s*\[(.*?)\]\s*;/s',
                        'D' => '/\$plotsD\s*=\s*\[(.*?)\]\s*;/s',
                        'E' => '/\$plotsE\s*=\s*\[(.*?)\]\s*;/s',
                        'F' => '/\$plotsF\s*=\s*\[(.*?)\]\s*;/s'
                    ];

                    foreach ($sectionMap as $sec => $pattern) {
                        if (preg_match($pattern, $content, $matches)) {
                            $block = $matches[1];
                            if (preg_match_all('/\[\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\]/', $block, $entries, PREG_SET_ORDER)) {
                                foreach ($entries as $e) {
                                    $x = (int)$e[1];
                                    $y = (int)$e[2];
                                    $left = (int)$e[3];
                                    $top = (int)$e[4];
                                    $width = (int)$e[5];
                                    $height = (int)$e[6];
                                    $key = "{$x}-{$y}";
                                    if (!isset(self::$layoutCache[$sec][$key])) {
                                        self::$layoutCache[$sec][$key] = [
                                            'x' => $x,
                                            'y' => $y,
                                            'left' => $left,
                                            'top' => $top,
                                            'width' => $width,
                                            'height' => $height,
                                            'cx' => $left + ($width / 2.0),
                                            'cy' => $top + ($height / 2.0)
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return self::$layoutCache;
        }

        /**
         * Get per-section max plot dimensions from database (cached).
         * Returns ['A' => ['max_px' => 52, 'max_py' => 5], ...]
         */
        public static function getSectionDimensions($conn) {
            if (self::$sectionDimensionsCache !== null) {
                return self::$sectionDimensionsCache;
            }

            self::$sectionDimensionsCache = [];

            if (!($conn instanceof mysqli) || $conn->connect_errno) {
                return self::$sectionDimensionsCache;
            }

            $res = @$conn->query(
                "SELECT UPPER(TRIM(section)) as sec, MAX(plot_x) as max_px, MAX(plot_y) as max_py
                 FROM tbl_plots
                 GROUP BY UPPER(TRIM(section))"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $sec = $row['sec'];
                    self::$sectionDimensionsCache[$sec] = [
                        'max_px' => (int)$row['max_px'],
                        'max_py' => (int)$row['max_py']
                    ];
                }
            }

            return self::$sectionDimensionsCache;
        }

        /**
         * Compute overview map coordinates (ox, oy) for a plot.
         *
         * PRIMARY: Uses the actual cx, cy pixel coordinates parsed from popup.php
         * for each plot, then maps them to the section's overview box position.
         * This correctly handles irregular/snake layouts (e.g. Section D) where
         * plot_y=1 is not uniformly north across all plot_x values.
         *
         * FALLBACK: If the plot is not in the parsed cache, uses a linear estimate
         * with the corrected east-west orientation (plot_x=1 is EAST).
         *
         * Mapping:
         *   - East (high cx) -> right side of overview box  (high ox)
         *   - West (low cx)  -> left  side of overview box  (low  ox)
         *   - North (low cy) -> top   of overview box       (low  oy)
         *   - South (high cy)-> bottom of overview box      (high oy)
         *
         * @param string $section  Section letter (A-J)
         * @param int    $plotX    plot_x value
         * @param int    $plotY    plot_y value
         * @param mysqli $conn     DB connection (fallback dimensions lookup)
         * @return array|null ['ox' => float, 'oy' => float] or null if unknown
         */
        public static function directOverviewPosition($section, $plotX, $plotY, $conn) {
            $sec = strtoupper(trim((string)$section));
            $px  = max(1, (int)$plotX);
            $py  = max(1, (int)$plotY);

            $box  = self::$overviewSectionBoxes[$sec] ?? null;
            $imgP = self::$sectionImageParams[$sec] ?? null;

            if (!$box || !$imgP) {
                return null;
            }

            $spanW = max(1.0, (float)($imgP['plotAreaRight'] - $imgP['plotAreaLeft']));
            $spanH = max(1.0, (float)($imgP['plotAreaBottom'] - $imgP['plotAreaTop']));

            // ------------------------------------------------------------------
            // PRIMARY: lookup the actual cx, cy from popup.php plot arrays.
            // This handles irregular layouts (snake-pattern, L-shaped, etc.)
            // because it uses the true visual centroid of each plot cell.
            // ------------------------------------------------------------------
            $layouts = self::loadSectionLayouts();
            $key = "{$px}-{$py}";

            if (!empty($layouts[$sec][$key])) {
                $cx = (float)$layouts[$sec][$key]['cx'];
                $cy = (float)$layouts[$sec][$key]['cy'];

                // u = east fraction  (0.0 = west edge, 1.0 = east edge)
                // v = south fraction (0.0 = north edge, 1.0 = south edge)
                $u = ($cx - $imgP['plotAreaLeft']) / $spanW;
                $v = ($cy - $imgP['plotAreaTop'])  / $spanH;

                $u = max(0.0, min(1.0, $u));
                $v = max(0.0, min(1.0, $v));

                return [
                    'ox' => $box['left'] + $u * $box['width'],
                    'oy' => $box['top']  + $v * $box['height'],
                ];
            }

            // ------------------------------------------------------------------
            // FALLBACK: plot not found in parsed layout cache.
            // Estimate with linear interpolation, correcting the east-west axis:
            //   plot_x = 1   -> east side (u close to 1.0)
            //   plot_x = max -> west side (u close to 0.0)
            // ------------------------------------------------------------------
            $dims = self::getSectionDimensions($conn);
            $dim  = $dims[$sec] ?? null;

            if (!$dim || $dim['max_px'] < 1 || $dim['max_py'] < 1) {
                return [
                    'ox' => $box['left'] + $box['width']  / 2.0,
                    'oy' => $box['top']  + $box['height'] / 2.0,
                ];
            }

            $maxPx = $dim['max_px'];
            $maxPy = $dim['max_py'];
            $px = max(1, min($px, $maxPx));
            $py = max(1, min($py, $maxPy));

            // plot_x=1 -> u=1 (east); plot_x=maxPx -> u~0 (west)
            $u = 1.0 - ($px - 0.5) / $maxPx;
            $v =       ($py - 0.5) / $maxPy;

            $u = max(0.0, min(1.0, $u));
            $v = max(0.0, min(1.0, $v));

            return [
                'ox' => $box['left'] + $u * $box['width'],
                'oy' => $box['top']  + $v * $box['height'],
            ];
        }


        public static function getPlotLayout($section, $plotX, $plotY) {
            $sec = strtoupper(trim((string)$section));
            $px = (int)$plotX;
            $py = (int)$plotY;

            if ($sec === '' || $px <= 0 || $py <= 0) {
                return ['found' => false, 'message' => 'Invalid section or plot specifications.'];
            }

            $layouts = self::loadSectionLayouts();
            $key = "{$px}-{$py}";

            if (isset($layouts[$sec][$key])) {
                $p = $layouts[$sec][$key];
                return [
                    'found' => true,
                    'is_estimated' => false,
                    'section' => $sec,
                    'x' => $px,
                    'y' => $py,
                    'left' => $p['left'],
                    'top' => $p['top'],
                    'width' => $p['width'],
                    'height' => $p['height'],
                    'cx' => $p['cx'],
                    'cy' => $p['cy']
                ];
            }

            // Fallback estimated position using section image bounds
            $imgP = self::$sectionImageParams[$sec] ?? null;
            if ($imgP) {
                $dims = self::$sectionDimensionsCache ?? [];
                $maxPx = $dims[$sec]['max_px'] ?? 10;
                $maxPy = $dims[$sec]['max_py'] ?? 5;
                $u = ($px - 0.5) / $maxPx;
                $v = ($py - 0.5) / $maxPy;
                // Plot cx in section image (east=right, west=left)
                $cx = $imgP['plotAreaRight'] - $u * ($imgP['plotAreaRight'] - $imgP['plotAreaLeft']);
                $cy = $imgP['plotAreaTop'] + $v * ($imgP['plotAreaBottom'] - $imgP['plotAreaTop']);
                $defaultW = ($imgP['plotAreaRight'] - $imgP['plotAreaLeft']) / $maxPx;
                $defaultH = ($imgP['plotAreaBottom'] - $imgP['plotAreaTop']) / $maxPy;
                return [
                    'found' => true,
                    'is_estimated' => true,
                    'section' => $sec,
                    'x' => $px,
                    'y' => $py,
                    'left' => $cx - $defaultW / 2,
                    'top'  => $cy - $defaultH / 2,
                    'width' => $defaultW,
                    'height' => $defaultH,
                    'cx' => $cx,
                    'cy' => $cy
                ];
            }

            // Last-resort fallback
            $bounds = self::getLayoutBounds();
            $b = $bounds[$sec] ?? ['minX' => 100, 'maxX' => 800, 'minY' => 50, 'maxY' => 600, 'w' => 700, 'h' => 550];
            $defaultWidth = 65.0;
            $defaultHeight = 28.0;
            $estimatedLeft = max($b['minX'], $b['maxX'] - (($py - 1) * 68.0));
            $estimatedTop = min($b['maxY'], $b['minY'] + (($px - 1) * 28.0));
            $cx = $estimatedLeft + ($defaultWidth / 2.0);
            $cy = $estimatedTop + ($defaultHeight / 2.0);

            return [
                'found' => true,
                'is_estimated' => true,
                'section' => $sec,
                'x' => $px,
                'y' => $py,
                'left' => $estimatedLeft,
                'top' => $estimatedTop,
                'width' => $defaultWidth,
                'height' => $defaultHeight,
                'cx' => $cx,
                'cy' => $cy
            ];
        }

        public static function getLayoutBounds() {
            if (self::$layoutBoundsCache !== null) {
                return self::$layoutBoundsCache;
            }

            $layouts = self::loadSectionLayouts();
            self::$layoutBoundsCache = [];

            foreach ($layouts as $sec => $plots) {
                if (empty($plots)) {
                    // Use actual section image bounds if available
                    $imgP = self::$sectionImageParams[$sec] ?? null;
                    if ($imgP) {
                        self::$layoutBoundsCache[$sec] = [
                            'minX' => (float)$imgP['plotAreaLeft'],
                            'maxX' => (float)$imgP['plotAreaRight'],
                            'w'    => (float)max(1.0, $imgP['plotAreaRight'] - $imgP['plotAreaLeft']),
                            'minY' => (float)$imgP['plotAreaTop'],
                            'maxY' => (float)$imgP['plotAreaBottom'],
                            'h'    => (float)max(1.0, $imgP['plotAreaBottom'] - $imgP['plotAreaTop']),
                        ];
                    } else {
                        self::$layoutBoundsCache[$sec] = [
                            'minX' => 100.0, 'maxX' => 800.0, 'w' => 700.0,
                            'minY' => 50.0,  'maxY' => 600.0, 'h' => 550.0
                        ];
                    }
                    continue;
                }

                $minX = PHP_INT_MAX; $maxX = -PHP_INT_MAX;
                $minY = PHP_INT_MAX; $maxY = -PHP_INT_MAX;
                foreach ($plots as $p) {
                    $minX = min($minX, $p['left']);
                    $maxX = max($maxX, $p['left'] + $p['width']);
                    $minY = min($minY, $p['top']);
                    $maxY = max($maxY, $p['top'] + $p['height']);
                }

                self::$layoutBoundsCache[$sec] = [
                    'minX' => (float)$minX,
                    'maxX' => (float)$maxX,
                    'w' => (float)max(1.0, $maxX - $minX),
                    'minY' => (float)$minY,
                    'maxY' => (float)$maxY,
                    'h' => (float)max(1.0, $maxY - $minY)
                ];
            }

            return self::$layoutBoundsCache;
        }

        public static function mapPlotToOverview($section, $cx, $cy) {
            $sec = strtoupper(trim((string)$section));
            $bounds = self::getLayoutBounds();
            $b = $bounds[$sec] ?? null;
            $box = self::$overviewSectionBoxes[$sec] ?? null;

            if ($b && $box) {
                $u = ($cx - $b['minX']) / $b['w'];
                $v = ($cy - $b['minY']) / $b['h'];
                $ox = $box['left'] + ($u * $box['width']);
                $oy = $box['top'] + ($v * $box['height']);
                return ['ox' => $ox, 'oy' => $oy];
            }

            if ($box) {
                return ['ox' => $box['left'] + ($box['width'] / 2.0), 'oy' => $box['top'] + ($box['height'] / 2.0)];
            }

            return ['ox' => 245.0, 'oy' => 585.0];
        }

        public static function solveCalibrationTransform($conn, $forceRefresh = false) {
            if (!$forceRefresh && self::$solvedModelCache !== null) {
                return self::$solvedModelCache;
            }

            $points = self::getCalibrationPoints($conn, true);

            if (count($points) < 2) {
                $settings = self::getSettings($conn);
                $refLat = (float)($settings['cemetery_ref_lat'] ?? 16.15024000);
                $refLng = (float)($settings['cemetery_ref_lng'] ?? 119.98232000);
                $rotDeg = (float)($settings['orientation_deg'] ?? -15.5);
                $scale  = (float)($settings['meters_per_pixel_x'] ?? 0.31);

                $rotRad = deg2rad($rotDeg);
                $a = $scale * cos($rotRad);
                $b = $scale * sin($rotRad);

                self::$solvedModelCache = [
                    'calibrated' => true,
                    'is_default' => true,
                    'message' => 'Automatic coordinate calculation active.',
                    'point_count' => count($points),
                    'origin' => [
                        'map_x' => 245.0,
                        'map_y' => 585.0,
                        'latitude' => $refLat,
                        'longitude' => $refLng
                    ],
                    'coefficients' => [
                        'a' => $a,
                        'b' => $b
                    ],
                    'scale_m_per_px' => round($scale, 4),
                    'rotation_deg' => round($rotDeg, 2),
                    'rmse_meters' => 0.0,
                    'residuals' => [],
                    'points' => $points
                ];
                return self::$solvedModelCache;
            }

            $n = count($points);
            $sumMapX = 0.0; $sumMapY = 0.0;
            $sumLat = 0.0; $sumLng = 0.0;

            foreach ($points as $pt) {
                $sumMapX += $pt['map_x'];
                $sumMapY += $pt['map_y'];
                $sumLat += $pt['latitude'];
                $sumLng += $pt['longitude'];
            }

            $x0 = $sumMapX / $n;
            $y0 = $sumMapY / $n;
            $lat0 = $sumLat / $n;
            $lng0 = $sumLng / $n;

            $mPerDegLat = 111139.0;
            $cosLat0 = cos(deg2rad($lat0));
            if (abs($cosLat0) < 0.0001) $cosLat0 = 1.0;
            $mPerDegLng = 111139.0 * $cosLat0;

            $gcpData = [];
            foreach ($points as $pt) {
                $dx = (float)$pt['map_x'] - $x0;
                $dy = (float)$pt['map_y'] - $y0;
                $dEast = ((float)$pt['longitude'] - $lng0) * $mPerDegLng;
                $dNorth = ((float)$pt['latitude'] - $lat0) * $mPerDegLat;
                $gcpData[] = [
                    'id' => $pt['id'],
                    'name' => $pt['point_name'],
                    'dx' => $dx,
                    'dy' => $dy,
                    'east' => $dEast,
                    'north' => $dNorth
                ];
            }

            $sectionGcps = [];
            foreach ($points as $pt) {
                if (!empty($pt['section'])) {
                    $secCode = strtoupper(trim((string)$pt['section']));
                    $sectionGcps[$secCode] = $pt;
                }
            }

            $Suu = 0.0; $Svv = 0.0; $Suv = 0.0;
            $Sue = 0.0; $Sve = 0.0;
            $Sun = 0.0; $Svn = 0.0;

            foreach ($gcpData as $g) {
                $u = $g['dx'];
                $v = -$g['dy'];
                $e = $g['east'];
                $north = $g['north'];

                $Suu += $u * $u;
                $Svv += $v * $v;
                $Suv += $u * $v;
                $Sue += $u * $e;
                $Sve += $v * $e;
                $Sun += $u * $north;
                $Svn += $v * $north;
            }

            $det = ($Suu * $Svv) - ($Suv * $Suv);
            if ($det > 1e-7) {
                $c1 = (($Svv * $Sue) - ($Suv * $Sve)) / $det;
                $c2 = (($Suu * $Sve) - ($Suv * $Sue)) / $det;
                $d1 = (($Svv * $Sun) - ($Suv * $Svn)) / $det;
                $d2 = (($Suu * $Svn) - ($Suv * $Sun)) / $det;

                $scaleX = sqrt(($c1 * $c1) + ($d1 * $d1));
                $scaleY = sqrt(($c2 * $c2) + ($d2 * $d2));
                $scale = ($scaleX + $scaleY) / 2.0;
                $rotRad = atan2($d1, $c1);
                $rotDeg = rad2deg($rotRad);
            } else {
                $den = $Suu + $Svv;
                if ($den < 1e-7) {
                    $settings = self::getSettings($conn);
                    $refLat = (float)($settings['cemetery_ref_lat'] ?? 16.15024000);
                    $refLng = (float)($settings['cemetery_ref_lng'] ?? 119.98232000);
                    $rotDeg = (float)($settings['orientation_deg'] ?? -15.5);
                    $scale  = (float)($settings['meters_per_pixel_x'] ?? 0.31);
                    $rotRad = deg2rad($rotDeg);
                    $a = $scale * cos($rotRad);
                    $b = $scale * sin($rotRad);

                    self::$solvedModelCache = [
                        'calibrated' => true,
                        'is_default' => true,
                        'message' => 'Standard coordinate calculation active.',
                        'point_count' => $n,
                        'origin' => [
                            'map_x' => 245.0,
                            'map_y' => 585.0,
                            'latitude' => $refLat,
                            'longitude' => $refLng
                        ],
                        'coefficients' => [
                            'a' => $a,
                            'b' => $b,
                            'c1' => $a,
                            'c2' => -$b,
                            'd1' => $b,
                            'd2' => $a
                        ],
                        'scale_m_per_px' => round($scale, 4),
                        'rotation_deg' => round($rotDeg, 2),
                        'rmse_meters' => 0.0,
                        'residuals' => [],
                        'section_gcps' => $sectionGcps,
                        'points' => $points
                    ];
                    return self::$solvedModelCache;
                }

                $numA = $Sue + $Svn;
                $numB = $Sun - $Sve;
                $a = $numA / $den;
                $b = $numB / $den;
                $c1 = $a; $c2 = -$b;
                $d1 = $b; $d2 = $a;
                $scale = sqrt(($a * $a) + ($b * $b));
                $rotRad = atan2($b, $a);
                $rotDeg = rad2deg($rotRad);
            }

            $sumSqRes = 0.0;
            $residuals = [];
            foreach ($gcpData as $g) {
                $u = $g['dx'];
                $v = -$g['dy'];
                $predEast = ($c1 * $u) + ($c2 * $v);
                $predNorth = ($d1 * $u) + ($d2 * $v);

                $resE = $predEast - $g['east'];
                $resN = $predNorth - $g['north'];
                $resDist = sqrt(($resE * $resE) + ($resN * $resN));
                $sumSqRes += ($resDist * $resDist);

                $residuals[] = [
                    'id' => $g['id'],
                    'name' => $g['name'],
                    'res_east' => round($resE, 3),
                    'res_north' => round($resN, 3),
                    'res_meters' => round($resDist, 3)
                ];
            }

            $rmse = sqrt($sumSqRes / $n);

            self::$solvedModelCache = [
                'calibrated' => true,
                'point_count' => $n,
                'origin' => [
                    'map_x' => $x0,
                    'map_y' => $y0,
                    'latitude' => $lat0,
                    'longitude' => $lng0
                ],
                'coefficients' => [
                    'a' => $c1,
                    'b' => $d1,
                    'c1' => $c1,
                    'c2' => $c2,
                    'd1' => $d1,
                    'd2' => $d2
                ],
                'scale_m_per_px' => round($scale, 4),
                'rotation_deg' => round($rotDeg, 2),
                'rmse_meters' => round($rmse, 3),
                'residuals' => $residuals,
                'section_gcps' => $sectionGcps,
                'points' => $points
            ];

            return self::$solvedModelCache;
        }

        public static function getCalibrationPoints($conn, $activeOnly = true) {
            $points = [];
            if (!($conn instanceof mysqli) || $conn->connect_errno) {
                return [];
            }

            $sql = "SELECT id, point_name, section, map_x, map_y, latitude, longitude, is_active, notes FROM tbl_cemetery_gps_calibration";
            if ($activeOnly) {
                $sql .= " WHERE is_active = 1";
            }
            $sql .= " ORDER BY section ASC, id ASC";

            $res = @$conn->query($sql);
            if ($res && $res->num_rows > 0) {
                while ($row = $res->fetch_assoc()) {
                    $points[] = [
                        'id' => (int)$row['id'],
                        'point_name' => $row['point_name'],
                        'section' => $row['section'] ? strtoupper(trim($row['section'])) : null,
                        'map_x' => (float)$row['map_x'],
                        'map_y' => (float)$row['map_y'],
                        'latitude' => (float)$row['latitude'],
                        'longitude' => (float)$row['longitude'],
                        'is_active' => (int)$row['is_active'],
                        'notes' => $row['notes']
                    ];
                }
            }

            return $points;
        }

        public static function getSettings($conn) {
            $defaults = [
                'cemetery_ref_lat' => 16.15024000,
                'cemetery_ref_lng' => 119.98232000,
                'orientation_deg' => -15.5,
                'meters_per_pixel_x' => 0.31,
                'meters_per_pixel_y' => 0.31,
                'enable_auto_calculation' => 1
            ];

            if (!($conn instanceof mysqli) || $conn->connect_errno) {
                return $defaults;
            }

            $res = @$conn->query("SELECT setting_key, setting_value FROM tbl_cemetery_gps_settings");
            if ($res && $res->num_rows > 0) {
                while ($row = $res->fetch_assoc()) {
                    $k = $row['setting_key'];
                    $v = $row['setting_value'];
                    if (isset($defaults[$k])) {
                        $defaults[$k] = is_numeric($v) ? (float)$v : $v;
                    }
                }
            }

            return $defaults;
        }

        /**
         * Calculate GPS coordinates for a cemetery plot.
         *
         * Uses directOverviewPosition() to map plot_x/plot_y to overview map
         * coordinates based on actual section image pixel bounds, then applies
         * the calibration transform to get GPS.
         */
        public static function calculatePlotGPS($conn, $plotId = 0, $section = '', $plotX = 0, $plotY = 0, $saveToDb = false) {
            $plotId  = (int)$plotId;
            $section = strtoupper(trim((string)$section));
            $plotX   = (int)$plotX;
            $plotY   = (int)$plotY;

            $existingPlot = null;
            if ($conn instanceof mysqli && !$conn->connect_errno) {
                if ($plotId > 0) {
                    $q = $conn->prepare("SELECT plot_id, section, `row`, plot_number, plot_x, plot_y, latitude, longitude, coordinate_source FROM tbl_plots WHERE plot_id = ? LIMIT 1");
                    if ($q) {
                        $q->bind_param('i', $plotId);
                        $q->execute();
                        $existingPlot = $q->get_result()->fetch_assoc();
                        $q->close();
                    }
                } elseif ($section !== '' && $plotX > 0 && $plotY > 0) {
                    $q = $conn->prepare("SELECT plot_id, section, `row`, plot_number, plot_x, plot_y, latitude, longitude, coordinate_source FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
                    if ($q) {
                        $q->bind_param('sii', $section, $plotX, $plotY);
                        $q->execute();
                        $existingPlot = $q->get_result()->fetch_assoc();
                        $q->close();
                    }
                }
            }

            if ($existingPlot) {
                $plotId  = (int)$existingPlot['plot_id'];
                $section = strtoupper(trim($existingPlot['section']));
                $plotX   = (int)$existingPlot['plot_x'];
                $plotY   = (int)$existingPlot['plot_y'];

                $src   = strtolower(trim((string)($existingPlot['coordinate_source'] ?? '')));
                $exLat = ($existingPlot['latitude'] !== null && $existingPlot['latitude'] !== '') ? (float)$existingPlot['latitude'] : null;
                $exLng = ($existingPlot['longitude'] !== null && $existingPlot['longitude'] !== '') ? (float)$existingPlot['longitude'] : null;

                if ($src === 'manual' && $exLat !== null && $exLng !== null && $exLat != 0.0 && $exLng != 0.0) {
                    $navUrl = "https://www.google.com/maps?q=loc:{$exLat},{$exLng}&t=k&z=20";
                    $dirUrl = "https://www.google.com/maps/dir/?api=1&destination={$exLat},{$exLng}&travelmode=walking";
                    return [
                        'success' => true,
                        'has_coordinates' => true,
                        'plot_id' => $plotId,
                        'section' => $section,
                        'row' => (int)($existingPlot['row'] ?? $plotY),
                        'plot_number' => (int)($existingPlot['plot_number'] ?? $plotX),
                        'plot_x' => $plotX,
                        'plot_y' => $plotY,
                        'plot_name' => "Section {$section} · Plot {$plotX}-{$plotY}",
                        'latitude' => $exLat,
                        'longitude' => $exLng,
                        'coordinate_source' => 'manual',
                        'coordinate_accuracy' => 'manual GPS override',
                        'navigation_url' => $navUrl,
                        'directions_url' => $dirUrl,
                        'view_on_map_url' => "sections/popup.php?section={$section}&plot_id={$plotId}&plot_x={$plotX}&plot_y={$plotY}&auto_open=1"
                    ];
                }
            }

            if (empty($section) || $plotX <= 0 || $plotY <= 0) {
                return [
                    'success' => false,
                    'has_coordinates' => false,
                    'message' => 'Invalid section or plot specifications.'
                ];
            }

            // === CORE CHANGE: Use directOverviewPosition for accurate GPS ===
            // This bypasses the old broken estimation that didn't account for
            // actual plot_x/plot_y orientation and section dimensions.
            $overviewPos = self::directOverviewPosition($section, $plotX, $plotY, $conn);

            if (!$overviewPos) {
                return [
                    'success' => false,
                    'has_coordinates' => false,
                    'message' => "Section '{$section}' is not configured for GPS calculation."
                ];
            }

            $model = self::solveCalibrationTransform($conn);
            if (empty($model['calibrated'])) {
                return [
                    'success' => false,
                    'has_coordinates' => false,
                    'calibrated' => false,
                    'message' => $model['message'] ?? 'GPS calibration is required before plot-level navigation can be considered accurate.'
                ];
            }

            $ox = $overviewPos['ox'];
            $oy = $overviewPos['oy'];

            $orig  = $model['origin'];
            $coeff = $model['coefficients'];

            // Per-Section GCP Anchoring:
            // If this section has an active calibration landmark (GCP), anchor directly to it.
            // This completely eliminates global residuals (guarantees 0.000m error at section center),
            // and applies the affine model from that center to accurately project all plots and edges.
            $secGcp = $model['section_gcps'][$section] ?? null;
            if ($secGcp) {
                $refX   = (float)$secGcp['map_x'];
                $refY   = (float)$secGcp['map_y'];
                $refLat = (float)$secGcp['latitude'];
                $refLng = (float)$secGcp['longitude'];

                $dx = $ox - $refX;
                $dy = $oy - $refY;
                $u  = $dx;
                $v  = -$dy;

                $eastMeters  = ($coeff['c1'] * $u) + ($coeff['c2'] * $v);
                $northMeters = ($coeff['d1'] * $u) + ($coeff['d2'] * $v);
            } else {
                $refX   = (float)$orig['map_x'];
                $refY   = (float)$orig['map_y'];
                $refLat = (float)$orig['latitude'];
                $refLng = (float)$orig['longitude'];

                $dx = $ox - $refX;
                $dy = $oy - $refY;
                $u  = $dx;
                $v  = -$dy;

                $eastMeters  = ($coeff['c1'] * $u) + ($coeff['c2'] * $v);
                $northMeters = ($coeff['d1'] * $u) + ($coeff['d2'] * $v);
            }

            $mPerDegLat = 111139.0;
            $cosLat0 = cos(deg2rad($refLat));
            if (abs($cosLat0) < 0.0001) $cosLat0 = 1.0;
            $mPerDegLng = 111139.0 * $cosLat0;

            $calcLat = round($refLat + ($northMeters / $mPerDegLat), 8);
            $calcLng = round($refLng + ($eastMeters  / $mPerDegLng), 8);

            if (!is_finite($calcLat) || !is_finite($calcLng) || abs($calcLat) > 90.0 || abs($calcLng) > 180.0) {
                return [
                    'success' => false,
                    'has_coordinates' => false,
                    'message' => 'GPS calibration calculation produced out-of-bounds coordinates.'
                ];
            }

            $distMeters = self::haversineDistance($calcLat, $calcLng, $orig['latitude'], $orig['longitude']);
            if ($distMeters > 1500.0) {
                error_log("GPS calculation out of bounds: ({$calcLat}, {$calcLng}) dist {$distMeters}m from origin");
                return [
                    'success' => false,
                    'has_coordinates' => false,
                    'message' => 'GPS calibration calculation produced out-of-bounds coordinate.'
                ];
            }

            if ($saveToDb && $plotId > 0 && ($conn instanceof mysqli) && !$conn->connect_errno) {
                $upStmt = $conn->prepare(
                    "UPDATE tbl_plots SET latitude = ?, longitude = ?, " .
                    "coordinate_source = 'calculated', coordinate_accuracy = 'calibrated layout', " .
                    "coordinate_updated_at = NOW() " .
                    "WHERE plot_id = ? AND (coordinate_source != 'manual' OR coordinate_source IS NULL)"
                );
                if ($upStmt) {
                    $upStmt->bind_param('ddi', $calcLat, $calcLng, $plotId);
                    $upStmt->execute();
                    $upStmt->close();
                }
            }

            $navUrl = "https://www.google.com/maps?q=loc:{$calcLat},{$calcLng}&t=k&z=20";
            $dirUrl = "https://www.google.com/maps/dir/?api=1&destination={$calcLat},{$calcLng}&travelmode=walking";
            $mapUrl = "sections/popup.php?section={$section}&plot_id={$plotId}&plot_x={$plotX}&plot_y={$plotY}&auto_open=1";

            return [
                'success' => true,
                'has_coordinates' => true,
                'plot_id' => $plotId,
                'section' => $section,
                'row' => (int)($existingPlot['row'] ?? $plotY),
                'plot_number' => (int)($existingPlot['plot_number'] ?? $plotX),
                'plot_x' => $plotX,
                'plot_y' => $plotY,
                'plot_name' => "Section {$section} · Plot {$plotX}-{$plotY}",
                'latitude' => $calcLat,
                'longitude' => $calcLng,
                'coordinate_source' => 'calculated',
                'coordinate_accuracy' => 'calibrated layout',
                'overview_coords' => ['ox' => round($ox, 1), 'oy' => round($oy, 1)],
                'offset_meters' => ['east' => round($eastMeters, 2), 'north' => round($northMeters, 2)],
                'calibration_metrics' => [
                    'scale' => $model['scale_m_per_px'],
                    'rotation' => $model['rotation_deg'],
                    'rmse' => $model['rmse_meters'],
                    'points_used' => $model['point_count']
                ],
                'navigation_url' => $navUrl,
                'directions_url' => $dirUrl,
                'view_on_map_url' => $mapUrl
            ];
        }

        public static function bulkRecalculate($conn, $section = null, $overwriteManual = false) {
            if (!($conn instanceof mysqli) || $conn->connect_errno) {
                return ['success' => false, 'message' => 'Database connection unavailable.'];
            }

            // Reset caches so fresh data is used
            self::$sectionDimensionsCache = null;
            self::$solvedModelCache = null;

            $model = self::solveCalibrationTransform($conn, true);
            if (empty($model['calibrated'])) {
                return [
                    'success' => false,
                    'calibrated' => false,
                    'message' => $model['message'] ?? 'GPS calibration is required before plot-level navigation can be considered accurate.'
                ];
            }

            $whereSec = "";
            if (!empty($section)) {
                $cleanSec = strtoupper(trim((string)$section));
                $whereSec = " WHERE UPPER(TRIM(section)) = '" . $conn->real_escape_string($cleanSec) . "'";
            }

            $sql = "SELECT plot_id, section, plot_x, plot_y, coordinate_source FROM tbl_plots" . $whereSec;
            $res = $conn->query($sql);
            if (!$res) {
                return ['success' => false, 'message' => 'Failed to retrieve plots from database.'];
            }

            $total = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;

            while ($row = $res->fetch_assoc()) {
                $total++;
                $pId = (int)$row['plot_id'];
                $sec = strtoupper(trim((string)$row['section']));
                $px  = (int)$row['plot_x'];
                $py  = (int)$row['plot_y'];
                $src = strtolower(trim((string)($row['coordinate_source'] ?? '')));

                if (!$overwriteManual && $src === 'manual') {
                    $skipped++;
                    continue;
                }

                $calc = self::calculatePlotGPS($conn, $pId, $sec, $px, $py, true);
                if ($calc['success'] && $calc['has_coordinates']) {
                    $updated++;
                } else {
                    $errors++;
                }
            }

            return [
                'success' => true,
                'calibrated' => true,
                'total_plots' => $total,
                'updated' => $updated,
                'skipped_manual' => $skipped,
                'errors' => $errors,
                'metrics' => [
                    'scale' => $model['scale_m_per_px'],
                    'rotation' => $model['rotation_deg'],
                    'rmse' => $model['rmse_meters'],
                    'points_used' => $model['point_count']
                ]
            ];
        }

        public static function haversineDistance($lat1, $lon1, $lat2, $lon2) {
            $earthRadius = 6371000.0;
            $dLat = deg2rad($lat2 - $lat1);
            $dLon = deg2rad($lon2 - $lon1);
            $a = sin($dLat / 2) * sin($dLat / 2) +
                 cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
                 sin($dLon / 2) * sin($dLon / 2);
            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
            return $earthRadius * $c;
        }

        public static function saveCalibrationPoint($conn, $data) {
            if (!($conn instanceof mysqli) || $conn->connect_errno) {
                return ['success' => false, 'message' => 'Database connection unavailable.'];
            }

            $id      = intval($data['id'] ?? 0);
            $name    = trim((string)($data['point_name'] ?? ''));
            $sec     = !empty($data['section']) ? strtoupper(trim((string)$data['section'])) : null;
            $mapX    = floatval($data['map_x'] ?? 0);
            $mapY    = floatval($data['map_y'] ?? 0);
            $lat     = floatval($data['latitude'] ?? 0);
            $lng     = floatval($data['longitude'] ?? 0);
            $isActive = isset($data['is_active']) ? (intval($data['is_active']) ? 1 : 0) : 1;
            $notes   = trim((string)($data['notes'] ?? ''));

            if (empty($name) || $lat == 0 || $lng == 0) {
                return ['success' => false, 'message' => 'Point name, latitude, and longitude are required.'];
            }

            self::$solvedModelCache = null;

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE tbl_cemetery_gps_calibration SET point_name = ?, section = ?, map_x = ?, map_y = ?, latitude = ?, longitude = ?, is_active = ?, notes = ? WHERE id = ?");
                $stmt->bind_param('ssddddisi', $name, $sec, $mapX, $mapY, $lat, $lng, $isActive, $notes, $id);
                $ok = $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO tbl_cemetery_gps_calibration (point_name, section, map_x, map_y, latitude, longitude, is_active, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('ssddddis', $name, $sec, $mapX, $mapY, $lat, $lng, $isActive, $notes);
                $ok = $stmt->execute();
                $id = $stmt->insert_id;
                $stmt->close();
            }

            return ['success' => $ok, 'id' => $id, 'message' => $ok ? 'Calibration point saved successfully.' : 'Error saving calibration point.'];
        }

        public static function deleteCalibrationPoint($conn, $id) {
            $id = intval($id);
            if ($id <= 0 || !($conn instanceof mysqli) || $conn->connect_errno) {
                return false;
            }
            self::$solvedModelCache = null;
            $stmt = $conn->prepare("DELETE FROM tbl_cemetery_gps_calibration WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $ok = ($stmt->affected_rows > 0);
                $stmt->close();
                return $ok;
            }
            return false;
        }

        public static function saveSettings($conn, $settings) {
            if (!($conn instanceof mysqli) || $conn->connect_errno) {
                return false;
            }
            self::$solvedModelCache = null;
            foreach ($settings as $k => $v) {
                $stmt = $conn->prepare("INSERT INTO tbl_cemetery_gps_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                if ($stmt) {
                    $stmt->bind_param('ss', $k, $v);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            return true;
        }

        public static function resetModelCache() {
            self::$solvedModelCache = null;
            self::$sectionDimensionsCache = null;
        }
    }
}

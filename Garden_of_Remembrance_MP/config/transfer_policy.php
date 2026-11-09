<?php
/**
 * Deceased Remains Transfer Policy Configuration
 * Garden of Remembrance Memorial Park - CMIS
 *
 * Centralized policy settings governing remains movement/transfer eligibility.
 * Standard interval rule:
 *   - Less than 8 years: NOT YET ELIGIBLE
 *   - 8 to 10 years:     ELIGIBLE FOR TRANSFER
 *   - More than 10 years: FOR ADMIN REVIEW
 */

if (!defined('REMAINS_MIN_TRANSFER_YEARS')) {
    define('REMAINS_MIN_TRANSFER_YEARS', 8);
}

if (!defined('REMAINS_MAX_REVIEW_YEARS')) {
    define('REMAINS_MAX_REVIEW_YEARS', 10);
}

if (!function_exists('get_remains_transfer_policy')) {
    /**
     * Get centralized policy configuration array
     */
    function get_remains_transfer_policy(): array
    {
        return [
            'min_years' => REMAINS_MIN_TRANSFER_YEARS,
            'max_years' => REMAINS_MAX_REVIEW_YEARS,
            'status_labels' => [
                'not_eligible'    => 'NOT YET ELIGIBLE',
                'eligible'        => 'ELIGIBLE FOR TRANSFER',
                'review_required' => 'FOR ADMIN REVIEW',
                'transferred'     => 'TRANSFERRED',
                'unknown'         => 'NO BURIAL DATE'
            ]
        ];
    }
}

if (!function_exists('calculate_remains_transfer_eligibility')) {
    /**
     * Calculates transfer eligibility based strictly on the Date of Interment/Burial.
     *
     * @param string|null $interment_date Date string (YYYY-MM-DD)
     * @param bool|string $is_transferred True or 'TRANSFERRED' if already transferred
     * @param string|null $as_of_date Reference date for calculation (defaults to current date)
     * @return array Calculated eligibility metadata
     */
    function calculate_remains_transfer_eligibility($interment_date = null, $is_transferred = false, $as_of_date = null): array
    {
        $policy = get_remains_transfer_policy();
        $minYears = $policy['min_years'];
        $maxYears = $policy['max_years'];

        // If record is already marked as transferred
        if ($is_transferred === true || (is_string($is_transferred) && strtoupper(trim($is_transferred)) === 'TRANSFERRED')) {
            return [
                'status'             => 'TRANSFERRED',
                'status_code'        => 'transferred',
                'badge_class'        => 'bg-secondary',
                'badge_style'        => 'background-color: #64748b !important; color: #fff;',
                'text_color'         => '#64748b',
                'years_elapsed'      => null,
                'total_days'         => null,
                'formatted_interval' => 'Completed Transfer',
                'interment_date'     => $interment_date,
                'can_transfer'       => false,
                'is_transferred'     => true,
                'message'            => 'Remains have already been transferred.'
            ];
        }

        // Validate date
        if (empty($interment_date) || $interment_date === '0000-00-00' || $interment_date === '1970-01-01') {
            return [
                'status'             => 'NO BURIAL DATE',
                'status_code'        => 'unknown',
                'badge_class'        => 'bg-light text-dark border',
                'badge_style'        => 'background-color: #f1f5f9 !important; color: #475569; border: 1px solid #cbd5e1;',
                'text_color'         => '#64748b',
                'years_elapsed'      => 0,
                'total_days'         => 0,
                'formatted_interval' => 'Date not recorded',
                'interment_date'     => null,
                'can_transfer'       => false,
                'is_transferred'     => false,
                'message'            => 'Interment date is required to evaluate transfer eligibility.'
            ];
        }

        try {
            $startDate = new DateTime($interment_date);
            $currentDate = $as_of_date ? new DateTime($as_of_date) : new DateTime();
        } catch (Exception $e) {
            return [
                'status'             => 'INVALID DATE',
                'status_code'        => 'unknown',
                'badge_class'        => 'bg-light text-dark border',
                'badge_style'        => 'background-color: #f1f5f9 !important; color: #475569;',
                'text_color'         => '#64748b',
                'years_elapsed'      => 0,
                'total_days'         => 0,
                'formatted_interval' => 'Invalid date format',
                'interment_date'     => $interment_date,
                'can_transfer'       => false,
                'is_transferred'     => false,
                'message'            => 'Invalid interment date format.'
            ];
        }

        // If interment date is in the future
        if ($startDate > $currentDate) {
            return [
                'status'             => 'NOT YET ELIGIBLE',
                'status_code'        => 'not_eligible',
                'badge_class'        => 'bg-warning text-dark',
                'badge_style'        => 'background-color: #f59e0b !important; color: #fff;',
                'text_color'         => '#d97706',
                'years_elapsed'      => 0,
                'total_days'         => 0,
                'formatted_interval' => 'Future date',
                'interment_date'     => $interment_date,
                'can_transfer'       => false,
                'is_transferred'     => false,
                'message'            => 'Interment date is scheduled in the future.'
            ];
        }

        $diff = $startDate->diff($currentDate);
        $years = (int)$diff->y;
        $months = (int)$diff->m;
        $days = (int)$diff->d;
        $totalDays = (int)$diff->days;

        // Exact decimal years
        $exactYears = round($years + ($months / 12) + ($days / 365.25), 1);

        // Format interval string
        $parts = [];
        if ($years > 0) {
            $parts[] = $years . ' ' . ($years === 1 ? 'year' : 'years');
        }
        if ($months > 0) {
            $parts[] = $months . ' ' . ($months === 1 ? 'month' : 'months');
        }
        if (empty($parts)) {
            $parts[] = $days . ' ' . ($days === 1 ? 'day' : 'days');
        }
        $formattedInterval = implode(', ', $parts);

        // Exact calendar date threshold
        $eligibleDate = (clone $startDate)->modify("+{$minYears} years");
        $isPastEligible = ($currentDate >= $eligibleDate);

        // Evaluate Eligibility Rules
        if (!$isPastEligible) {
            // Less than minimum years (e.g. less than 8 full years)
            $remDiff = $currentDate->diff($eligibleDate);
            $remYears = (int)$remDiff->y;
            $remMonths = (int)$remDiff->m;
            $remParts = [];
            if ($remYears > 0) {
                $remParts[] = $remYears . ' ' . ($remYears === 1 ? 'year' : 'years');
            }
            if ($remMonths > 0) {
                $remParts[] = $remMonths . ' ' . ($remMonths === 1 ? 'month' : 'months');
            }
            if (empty($remParts)) {
                $remParts[] = $remDiff->d . ' ' . ($remDiff->d === 1 ? 'day' : 'days');
            }
            $remainingStr = implode(' and ', $remParts);

            return [
                'status'             => 'NOT YET ELIGIBLE',
                'status_code'        => 'not_eligible',
                'badge_class'        => 'bg-warning text-dark',
                'badge_style'        => 'background-color: #fef3c7 !important; color: #b45309 !important; border: 1px solid #fde68a;',
                'text_color'         => '#b45309',
                'years_elapsed'      => $years,
                'exact_years'        => $exactYears,
                'total_days'         => $totalDays,
                'formatted_interval' => $formattedInterval,
                'interment_date'     => $interment_date,
                'formatted_interment_date' => $startDate->format('F j, Y'),
                'eligible_date'      => $eligibleDate->format('Y-m-d'),
                'formatted_eligible_date' => $eligibleDate->format('F j, Y'),
                'can_transfer'       => false,
                'is_transferred'     => false,
                'message'            => "Transfer not yet eligible. Only {$years} years completed. Minimum required interval: {$minYears} years. Remaining time: approximately {$remainingStr}. Eligible on {$eligibleDate->format('F j, Y')}."
            ];
        } elseif ($years <= $maxYears) {
            // 8 to 10 years completed
            return [
                'status'             => 'ELIGIBLE FOR TRANSFER',
                'status_code'        => 'eligible',
                'badge_class'        => 'bg-success',
                'badge_style'        => 'background-color: #15803d !important; color: #fff;',
                'text_color'         => '#15803d',
                'years_elapsed'      => $years,
                'exact_years'        => $exactYears,
                'total_days'         => $totalDays,
                'formatted_interval' => $formattedInterval,
                'interment_date'     => $interment_date,
                'formatted_interment_date' => $startDate->format('F j, Y'),
                'eligible_date'      => $eligibleDate->format('Y-m-d'),
                'formatted_eligible_date' => $eligibleDate->format('F j, Y'),
                'can_transfer'       => true,
                'is_transferred'     => false,
                'message'            => "Eligible for transfer. {$years} years completed since interment on {$startDate->format('F j, Y')}."
            ];
        } else {
            // Exceeds 10 years
            return [
                'status'             => 'FOR ADMIN REVIEW',
                'status_code'        => 'review_required',
                'badge_class'        => 'bg-danger',
                'badge_style'        => 'background-color: #b91c1c !important; color: #fff;',
                'text_color'         => '#b91c1c',
                'years_elapsed'      => $years,
                'exact_years'        => $exactYears,
                'total_days'         => $totalDays,
                'formatted_interval' => $formattedInterval,
                'interment_date'     => $interment_date,
                'formatted_interment_date' => $startDate->format('F j, Y'),
                'eligible_date'      => $eligibleDate->format('Y-m-d'),
                'formatted_eligible_date' => $eligibleDate->format('F j, Y'),
                'can_transfer'       => true,
                'is_transferred'     => false,
                'message'            => "Interred for {$formattedInterval} (exceeds {$maxYears} years). Requires administrator evaluation before processing."
            ];
        }
    }
}

if (!function_exists('check_plot_interment_interval_eligibility')) {
    /**
     * Checks if a plot is eligible for adding a new deceased person ("pagpapatong")
     * based on the 8-year minimum interval policy from previous interment.
     *
     * @param mysqli $conn Active database connection
     * @param string $section Plot section code
     * @param int $plot_x Plot X coordinate
     * @param int $plot_y Plot Y coordinate
     * @param string|null $target_date Date of new interment or reference date
     * @return array Detailed eligibility status
     */
    function check_plot_interment_interval_eligibility(mysqli $conn, $section, $plot_x, $plot_y, $target_date = null): array
    {
        $policy = get_remains_transfer_policy();
        $minYears = (int)($policy['min_years'] ?? 8);
        $sec = strtoupper(trim((string)$section));
        $px = (int)$plot_x;
        $py = (int)$plot_y;

        if ($sec === '' || $px <= 0 || $py <= 0) {
            return [
                'is_eligible' => false,
                'has_previous_interment' => false,
                'status' => 'INVALID PLOT',
                'status_code' => 'invalid_plot',
                'occupant_count' => 0,
                'message' => 'Please select a valid cemetery plot.'
            ];
        }

        try {
            $currentDate = $target_date ? new DateTime($target_date) : new DateTime();
        } catch (Exception $e) {
            $currentDate = new DateTime();
        }

        // Query all existing occupants in this plot, latest date of death first
        $stmt = $conn->prepare("SELECT deceased_id, first_name, middle_name, last_name, suffix, bday, date_died, interment_date, relationship_to_account_holder 
                               FROM tbl_deceased 
                               WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? 
                               ORDER BY COALESCE(NULLIF(date_died, '0000-00-00'), interment_date) DESC, deceased_id DESC");
        if (!$stmt) {
            return [
                'is_eligible' => false,
                'has_previous_interment' => false,
                'status' => 'DB ERROR',
                'status_code' => 'db_error',
                'occupant_count' => 0,
                'all_occupants' => [],
                'message' => 'Database error while checking plot records.'
            ];
        }
        $stmt->bind_param('sii', $sec, $px, $py);
        $stmt->execute();
        $res = $stmt->get_result();
        $occupants = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $row['full_name'] = trim(implode(' ', array_filter([$row['first_name'], $row['middle_name'], $row['last_name'], $row['suffix']])));
            $occupants[] = $row;
        }
        $stmt->close();

        // Case A: Empty Plot (No previous deceased record)
        if (empty($occupants)) {
            return [
                'is_eligible' => true,
                'has_previous_interment' => false,
                'status' => 'ELIGIBLE (FIRST OCCUPANT)',
                'status_code' => 'first_interment',
                'occupant_count' => 0,
                'all_occupants' => [],
                'latest_deceased' => null,
                'latest_name' => null,
                'interment_date' => null,
                'formatted_interment_date' => 'N/A',
                'eligible_date' => null,
                'formatted_eligible_date' => 'Immediate',
                'years_elapsed' => 0,
                'required_interval' => $minYears,
                'message' => 'Plot has no previous deceased record. First occupant is allowed.'
            ];
        }

        // Capacity check: Max 4 occupants per grave
        if (count($occupants) >= 4) {
            return [
                'is_eligible' => false,
                'has_previous_interment' => true,
                'status' => 'MAXIMUM CAPACITY REACHED',
                'status_code' => 'max_capacity',
                'occupant_count' => count($occupants),
                'all_occupants' => $occupants,
                'latest_deceased' => $occupants[0],
                'eligible_after_date' => 'N/A (Plot Full)',
                'formatted_eligible_date' => 'N/A (Plot Full)',
                'message' => 'This plot has reached the maximum capacity of 4 occupants. No additional deceased can be added.'
            ];
        }

        // Case B: Existing Deceased in Plot - evaluate 8 to 10-year interval from the MOST RECENT interment/burial ("bagong nilibing or pinatong dyan")
        $latestOccupant = null;
        $latestBurialDate = null;

        foreach ($occupants as $occ) {
            $occDate = null;
            if (!empty($occ['interment_date']) && $occ['interment_date'] !== '0000-00-00') {
                $occDate = $occ['interment_date'];
            } elseif (!empty($occ['date_died']) && $occ['date_died'] !== '0000-00-00') {
                $occDate = $occ['date_died'];
            }
            if ($occDate) {
                if ($latestBurialDate === null || $occDate > $latestBurialDate) {
                    $latestBurialDate = $occDate;
                    $latestOccupant = $occ;
                }
            }
        }

        if (!$latestOccupant) {
            $latestOccupant = $occupants[0];
        }
        $latestName = trim(implode(' ', array_filter([$latestOccupant['first_name'], $latestOccupant['middle_name'], $latestOccupant['last_name'], $latestOccupant['suffix']])));

        if (empty($latestBurialDate) || $latestBurialDate === '0000-00-00') {
            return [
                'is_eligible' => false,
                'has_previous_interment' => true,
                'status' => 'NO BURIAL DATE',
                'status_code' => 'missing_date',
                'occupant_count' => count($occupants),
                'all_occupants' => $occupants,
                'latest_deceased' => $latestOccupant,
                'latest_name' => $latestName,
                'interment_date' => null,
                'formatted_interment_date' => 'Not Recorded',
                'eligible_date' => null,
                'formatted_eligible_date' => 'Pending Date Verification',
                'eligible_after_date' => 'Pending Date Verification',
                'years_elapsed' => 0,
                'required_interval' => $minYears,
                'message' => "Previous occupant ({$latestName}) has no recorded burial/death date. Please record the date before adding another deceased record."
            ];
        }

        try {
            $startDate = new DateTime($latestBurialDate);
        } catch (Exception $e) {
            return [
                'is_eligible' => false,
                'has_previous_interment' => true,
                'status' => 'INVALID DATE',
                'status_code' => 'invalid_date',
                'occupant_count' => count($occupants),
                'all_occupants' => $occupants,
                'latest_deceased' => $latestOccupant,
                'latest_name' => $latestName,
                'eligible_after_date' => 'Invalid Date',
                'message' => 'Invalid previous burial date format.'
            ];
        }

        $eligibleDate = (clone $startDate)->modify("+{$minYears} years");
        $diff = $startDate->diff($currentDate);
        $yearsElapsed = (int)$diff->y;
        $isPastEligible = ($currentDate >= $eligibleDate);

        if ($isPastEligible) {
            return [
                'is_eligible' => true,
                'has_previous_interment' => true,
                'status' => 'ELIGIBLE FOR NEW DECEASED',
                'status_code' => 'eligible',
                'occupant_count' => count($occupants),
                'all_occupants' => $occupants,
                'latest_deceased' => $latestOccupant,
                'latest_name' => $latestName,
                'interment_date' => $latestBurialDate,
                'formatted_interment_date' => $startDate->format('F j, Y'),
                'eligible_date' => $eligibleDate->format('Y-m-d'),
                'formatted_eligible_date' => $eligibleDate->format('F j, Y'),
                'eligible_after_date' => 'Immediate (Eligible)',
                'years_elapsed' => $yearsElapsed,
                'required_interval' => $minYears,
                'message' => "Eligible to add deceased. {$yearsElapsed} years have passed since the previous interment of {$latestName} ({$startDate->format('F j, Y')}). The 8 to 10-year interval policy is satisfied."
            ];
        } else {
            return [
                'is_eligible' => false,
                'has_previous_interment' => true,
                'status' => 'NOT YET ELIGIBLE',
                'status_code' => 'not_eligible',
                'occupant_count' => count($occupants),
                'all_occupants' => $occupants,
                'latest_deceased' => $latestOccupant,
                'latest_name' => $latestName,
                'interment_date' => $latestBurialDate,
                'formatted_interment_date' => $startDate->format('F j, Y'),
                'eligible_date' => $eligibleDate->format('Y-m-d'),
                'formatted_eligible_date' => $eligibleDate->format('F j, Y'),
                'eligible_after_date' => $eligibleDate->format('F j, Y'),
                'years_elapsed' => $yearsElapsed,
                'required_interval' => $minYears,
                'message' => "Cannot add deceased: An interval of at least 8 to 10 years from the previous interment is required. Only {$yearsElapsed} year(s) have passed since {$latestName} was interred on {$startDate->format('F j, Y')}. Eligible for next interment on: {$eligibleDate->format('F j, Y')}."
            ];
        }
    }
}

if (!function_exists('get_genuinely_available_destination_plots')) {
    /**
     * Retrieves only genuinely available plots for destination choice.
     * Excludes:
     * - Occupied plots
     * - Reserved plots
     * - Customer owned plots in user_plot_ownership
     * - Active reservation plots in tbl_plot_owners
     * - Unavailable sections G, H, I, J
     *
     * @param mysqli $conn Active database connection
     * @return array List of available plot records
     */
    function get_genuinely_available_destination_plots(mysqli $conn): array
    {
        $sql = "SELECT p.section, p.plot_x, p.plot_y, p.lawn_type, p.price
                FROM tbl_plots p
                WHERE LOWER(p.status) = 'available'
                  AND UPPER(TRIM(p.section)) IN ('A', 'B', 'C', 'D', 'E', 'F')
                  AND NOT EXISTS (
                      SELECT 1 FROM user_plot_ownership upo 
                      WHERE UPPER(TRIM(upo.section)) = UPPER(TRIM(p.section)) 
                        AND upo.plot_x = p.plot_x AND upo.plot_y = p.plot_y 
                        AND upo.verification_status = 'VERIFIED'
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM tbl_deceased d 
                      WHERE UPPER(TRIM(d.section)) = UPPER(TRIM(p.section)) 
                        AND d.plot_x = p.plot_x AND d.plot_y = p.plot_y
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM tbl_plot_owners po 
                      WHERE UPPER(TRIM(po.section)) = UPPER(TRIM(p.section)) 
                        AND po.plot_x = p.plot_x AND po.plot_y = p.plot_y 
                        AND po.active = 1
                  )
                ORDER BY p.section ASC, p.plot_x ASC, p.plot_y ASC";
        $res = $conn->query($sql);
        $plots = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $row['plot_no'] = $row['section'] . ' ' . $row['plot_x'] . '-' . $row['plot_y'];
            $row['display_label'] = 'Section ' . $row['section'] . ' Plot ' . $row['plot_x'] . '-' . $row['plot_y'] . ' (' . ($row['lawn_type'] ?: 'Lawn') . ')';
            $plots[] = $row;
        }
        return $plots;
    }
}

if (!function_exists('ensure_remains_transfer_tables')) {
    /**
     * Ensure database columns and history table exist
     */
    function ensure_remains_transfer_tables(mysqli $conn, $force = false): void
    {
        static $ensured = false;
        if ($ensured && !$force) return;
        $ensured = true;

        // Fast check: If tbl_deceased_transfers already exists, skip DDL during hot request path
        if (!$force) {
            $chk = @$conn->query("SHOW TABLES LIKE 'tbl_deceased_transfers'");
            if ($chk && $chk->num_rows > 0) {
                return;
            }
        }

        // 1. Check tbl_deceased.interment_date
        $c1 = $conn->query("SHOW COLUMNS FROM `tbl_deceased` LIKE 'interment_date'");
        if ($c1 && $c1->num_rows === 0) {
            $conn->query("ALTER TABLE `tbl_deceased` ADD COLUMN `interment_date` DATE DEFAULT NULL AFTER `date_died`");
        }

        // 2. Check tbl_deceased.transfer_status
        $c2 = $conn->query("SHOW COLUMNS FROM `tbl_deceased` LIKE 'transfer_status'");
        if ($c2 && $c2->num_rows === 0) {
            $conn->query("ALTER TABLE `tbl_deceased` ADD COLUMN `transfer_status` VARCHAR(50) DEFAULT 'ORIGINAL' AFTER `action`");
        }

        // 3. Create tbl_deceased_transfers history table
        $tblSql = "CREATE TABLE IF NOT EXISTS `tbl_deceased_transfers` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `deceased_id` INT(11) NOT NULL,
            `deceased_name` VARCHAR(150) NOT NULL,
            `original_section` VARCHAR(20) NOT NULL,
            `original_plot_x` INT(11) NOT NULL,
            `original_plot_y` INT(11) NOT NULL,
            `original_interment_date` DATE DEFAULT NULL,
            `destination_section` VARCHAR(20) DEFAULT NULL,
            `destination_plot_x` INT(11) DEFAULT NULL,
            `destination_plot_y` INT(11) DEFAULT NULL,
            `destination_type` VARCHAR(50) DEFAULT 'NEW_PLOT',
            `destination_details` VARCHAR(255) DEFAULT NULL,
            `transfer_date` DATE NOT NULL,
            `reason` TEXT DEFAULT NULL,
            `eligibility_at_transfer` VARCHAR(50) DEFAULT NULL,
            `years_since_interment` DECIMAL(4,1) DEFAULT NULL,
            `recorded_by_id` INT(11) DEFAULT NULL,
            `recorded_by_name` VARCHAR(100) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_deceased_id` (`deceased_id`),
            KEY `idx_transfer_date` (`transfer_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($tblSql);

        $ensured = true;
    }
}


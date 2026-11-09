<?php
/**
 * Shared cancellation / delinquency helper.
 *
 * - Strict NO REFUND policy: payments are never refunded or deleted on cancel.
 * - Consecutive missed installments are derived from the actual payment
 *   schedule (booking_payment_schedule), never from booking-date arithmetic.
 * - All functions are wrapped in function_exists() so this file can be
 *   safely required from dashboard.php, logs.php, booking_process.php and cron.
 */

if (!function_exists('ensure_cancellation_columns')) {
    function ensure_cancellation_columns($conn) {
        if (!$conn || $conn->connect_errno) return;
        try {
            $c = $conn->query("SHOW COLUMNS FROM `tbl_bookings` LIKE 'cancellation_reason'");
            if ($c && $c->num_rows === 0) {
                @$conn->query("ALTER TABLE `tbl_bookings` ADD COLUMN `cancellation_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `remarks`");
            }
        } catch (Throwable $t) { /* ignore - column stays optional */ }
    }
}

if (!function_exists('get_booking_delinquency')) {
    /**
     * Returns delinquency info derived from the actual payment schedule.
     * Only rows with due_date <= today count. Counter resets on every paid row.
     */
    function get_booking_delinquency($conn, $booking_id) {
        $out = [
            'is_installment' => false,
            'has_schedule' => false,
            'consecutive_unpaid' => 0,
            'next_due_date' => null,
            'overdue_amount' => 0.00,
            'last_payment_date' => null,
            'last_due_date' => null,
            'threshold' => 3,
            'months_until_cancel' => 3,
            'total_rows' => 0,
            'paid_rows' => 0,
        ];
        $booking_id = (int)$booking_id;
        if ($booking_id <= 0 || !$conn || $conn->connect_errno) return $out;
        try {
            $bRes = $conn->query("SELECT payment_plan, status FROM tbl_bookings WHERE id = $booking_id LIMIT 1");
            $b = $bRes ? $bRes->fetch_assoc() : null;
            if (!$b) return $out;
            if (strtolower(trim((string)($b['payment_plan'] ?? ''))) !== 'installment') return $out;
            $out['is_installment'] = true;

            $sRes = $conn->query("SELECT installment_number, due_date, amount_due, amount_paid, status, paid_at FROM booking_payment_schedule WHERE booking_id = $booking_id ORDER BY due_date ASC, installment_number ASC");
            if (!$sRes || $sRes->num_rows === 0) return $out;
            $out['has_schedule'] = true;
            $today = date('Y-m-d');
            $counter = 0;
            $overdue = 0.00;
            $lastPay = null;
            $lastDue = null;
            $nextDue = null;
            $total = 0; $paid = 0;
            while ($r = $sRes->fetch_assoc()) {
                $total++;
                $due = substr(trim((string)($r['due_date'] ?? '')), 0, 10);
                $amtDue = (float)($r['amount_due'] ?? 0);
                $amtPaid = (float)($r['amount_paid'] ?? 0);
                $st = strtoupper(trim((string)($r['status'] ?? '')));
                $isPaid = ($st === 'PAID') || ($amtPaid >= $amtDue - 0.009 && $amtDue > 0);
                if ($isPaid) {
                    $paid++;
                    $counter = 0; // reset on every successful payment
                    if (!empty($r['paid_at'])) {
                        if ($lastPay === null || strcmp((string)$r['paid_at'], (string)$lastPay) > 0) $lastPay = $r['paid_at'];
                    }
                    continue;
                }
                if ($due !== '' && $due <= $today) {
                    $counter++;
                    $overdue += max(0.00, $amtDue - $amtPaid);
                    $lastDue = $due;
                } elseif ($nextDue === null) {
                    $nextDue = $due !== '' ? $due : null;
                }
            }
            // Next due = nearest unpaid row (past-due first, else nearest future)
            $out['consecutive_unpaid'] = $counter;
            $out['overdue_amount'] = round($overdue, 2);
            $out['last_payment_date'] = $lastPay;
            if ($lastPay === null) {
                $prRes = $conn->query("SELECT MAX(payment_date) AS lp FROM payment_receipts WHERE booking_id = $booking_id AND UPPER(COALESCE(payment_status,'')) IN ('PAID','VERIFIED')");
                if ($prRes && ($prRow = $prRes->fetch_assoc()) && !empty($prRow['lp'])) $lastPay = $prRow['lp'];
                $out['last_payment_date'] = $lastPay;
            }
            $out['last_due_date'] = $lastDue;
            $out['next_due_date'] = $lastDue !== null && $counter > 0 ? $lastDue : $nextDue;
            $out['months_until_cancel'] = max(0, 3 - $counter);
            $out['total_rows'] = $total;
            $out['paid_rows'] = $paid;
        } catch (Throwable $t) { /* return best-effort data */ }
        return $out;
    }
}

if (!function_exists('release_booking_plots')) {
    /**
     * Releases a booking's plots back to available UNLESS occupied.
     * Synchronizes across tbl_plots, plots, tbl_plot_owners, user_plot_ownership,
     * booking_plots, and guest_reservation_requests.
     * Never deletes booking_plots rows or payment records (strict NO REFUND policy).
     * Returns ['released' => [...], 'kept' => [...]] plot labels.
     */
    function release_booking_plots($conn, $booking_id) {
        $released = []; $kept = [];
        $booking_id = (int)$booking_id;
        if ($booking_id <= 0 || !$conn || $conn->connect_errno) return ['released' => $released, 'kept' => $kept];

        $isOccupied = function ($sec, $px, $py) use ($conn) {
            $sec = strtoupper(trim((string)$sec));
            $cleanSec = strtoupper(trim(preg_replace('/^SECTION\s*/i', '', $sec)));
            $px = (int)$px; $py = (int)$py;
            if ($cleanSec === '' || $px <= 0 || $py <= 0) return false;
            try {
                $e = $conn->real_escape_string($cleanSec);
                $dRes = $conn->query("SELECT COUNT(*) AS c FROM tbl_deceased WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '$e' OR UPPER(TRIM(section)) = '$e') AND ((plot_x = $px AND plot_y = $py) OR (plot_x = $py AND plot_y = $px))");
                if ($dRes && ($dRow = $dRes->fetch_assoc()) && (int)$dRow['c'] > 0) return true;
                $sRes = $conn->query("SELECT LOWER(status) AS st FROM tbl_plots WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '$e' OR UPPER(TRIM(section)) = '$e') AND ((plot_x = $px AND plot_y = $py) OR (plot_x = $py AND plot_y = $px)) LIMIT 1");
                if ($sRes && ($sRow = $sRes->fetch_assoc()) && trim((string)($sRow['st'] ?? '')) === 'occupied') return true;
            } catch (Throwable $t) {}
            return false;
        };

        $releaseSinglePlot = function ($sec, $px, $py, $pid = 0) use ($conn, $isOccupied, &$released, &$kept) {
            $sec = strtoupper(trim((string)$sec));
            $cleanSec = strtoupper(trim(preg_replace('/^SECTION\s*/i', '', $sec)));
            $e = $conn->real_escape_string($cleanSec);
            $px = (int)$px; $py = (int)$py; $pid = (int)$pid;
            $label = trim(($cleanSec !== '' ? "Section {$cleanSec} " : '') . ($px > 0 && $py > 0 ? "Plot {$px}-{$py}" : ($pid > 0 ? "Plot #{$pid}" : 'Plot')));

            if ($px > 0 && $py > 0 && $isOccupied($cleanSec, $px, $py)) {
                $kept[] = $label . ' (occupied)';
                return;
            }

            try {
                // 1. tbl_plots
                if ($pid > 0) {
                    $conn->query("UPDATE tbl_plots SET status = 'available' WHERE plot_id = $pid AND LOWER(status) != 'occupied'");
                }
                if ($cleanSec !== '' && $px > 0 && $py > 0) {
                    $conn->query("UPDATE tbl_plots SET status = 'available' WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '$e' OR UPPER(TRIM(section)) = '$e') AND ((plot_x = $px AND plot_y = $py) OR (plot_x = $py AND plot_y = $px)) AND LOWER(status) != 'occupied'");
                    
                    // 2. plots table if exists
                    $chkPlots = @$conn->query("SHOW TABLES LIKE 'plots'");
                    if ($chkPlots && $chkPlots->num_rows > 0) {
                        $conn->query("UPDATE plots SET status = 'Available' WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '$e' OR UPPER(TRIM(section)) = '$e') AND ((plot_x = $px AND plot_y = $py) OR (plot_x = $py AND plot_y = $px)) AND LOWER(status) != 'occupied'");
                    }

                    // 3. tbl_plot_owners (deactivate)
                    $chkPo = @$conn->query("SHOW TABLES LIKE 'tbl_plot_owners'");
                    if ($chkPo && $chkPo->num_rows > 0) {
                        $conn->query("UPDATE tbl_plot_owners SET active = 0 WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '$e' OR UPPER(TRIM(section)) = '$e') AND ((plot_x = $px AND plot_y = $py) OR (plot_x = $py AND plot_y = $px)) AND active = 1");
                    }

                    // 4. user_plot_ownership (delete reservation so it is removed from user and database)
                    $chkUpo = @$conn->query("SHOW TABLES LIKE 'user_plot_ownership'");
                    if ($chkUpo && $chkUpo->num_rows > 0) {
                        $conn->query("DELETE FROM user_plot_ownership WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '$e' OR UPPER(TRIM(section)) = '$e') AND ((plot_x = $px AND plot_y = $py) OR (plot_x = $py AND plot_y = $px)) AND (ownership_status IN ('RESERVED', 'CANCELLED') OR verification_status IN ('REJECTED', 'PENDING'))");
                    }
                }
                $released[] = $label;
            } catch (Throwable $t) {}
        };

        try {
            // A. booking_plots records
            $bpRes = $conn->query("SELECT plot_id, section, plot_x, plot_y, plot_number FROM booking_plots WHERE booking_id = $booking_id");
            if ($bpRes) {
                while ($bp = $bpRes->fetch_assoc()) {
                    $releaseSinglePlot($bp['section'] ?? '', $bp['plot_x'] ?? 0, $bp['plot_y'] ?? 0, $bp['plot_id'] ?? 0);
                }
                $conn->query("UPDATE booking_plots SET status = 'cancelled' WHERE booking_id = $booking_id");
            }

            // B. Single / multi-plot strings on tbl_bookings itself
            $bRes = $conn->query("SELECT section, plot, plot_id, grave_location, section_plot, user_id FROM tbl_bookings WHERE id = $booking_id LIMIT 1");
            if ($bRes && ($bRow = $bRes->fetch_assoc())) {
                $bSec = strtoupper(trim((string)($bRow['section'] ?? '')));
                $bPid = (int)($bRow['plot_id'] ?? 0);
                if ($bPid > 0) {
                    $releaseSinglePlot($bSec, 0, 0, $bPid);
                }
                foreach ([$bRow['plot'] ?? '', $bRow['section_plot'] ?? '', $bRow['grave_location'] ?? ''] as $pStr) {
                    if (empty($pStr)) continue;
                    if (preg_match_all('/(\d{1,3})\s*[-,\s]\s*(\d{1,3})/', $pStr, $mAll, PREG_SET_ORDER)) {
                        foreach ($mAll as $m) {
                            $releaseSinglePlot($bSec, (int)$m[1], (int)$m[2], $bPid);
                        }
                    }
                }
                $bUid = (int)($bRow['user_id'] ?? 0);
                if ($bUid > 0) {
                    $chkUpo = @$conn->query("SHOW TABLES LIKE 'user_plot_ownership'");
                    if ($chkUpo && $chkUpo->num_rows > 0) {
                        $eSec = $conn->real_escape_string(strtoupper(trim(preg_replace('/^SECTION\s*/i', '', $bSec))));
                        $secClause = $eSec !== '' ? "AND (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '$eSec' OR UPPER(TRIM(section)) = '$eSec')" : "";
                        $conn->query("DELETE FROM user_plot_ownership WHERE user_id = $bUid $secClause AND (ownership_status IN ('RESERVED', 'CANCELLED') OR verification_status IN ('REJECTED', 'PENDING'))");
                    }
                }
            }

            // C. guest_reservation_requests records
            $chkGrr = @$conn->query("SHOW TABLES LIKE 'guest_reservation_requests'");
            if ($chkGrr && $chkGrr->num_rows > 0) {
                $grrRes = $conn->query("SELECT section, plot_x, plot_y FROM guest_reservation_requests WHERE booking_id = $booking_id");
                if ($grrRes) {
                    while ($gr = $grrRes->fetch_assoc()) {
                        $releaseSinglePlot($gr['section'] ?? '', $gr['plot_x'] ?? 0, $gr['plot_y'] ?? 0);
                    }
                }
                $conn->query("UPDATE guest_reservation_requests SET status = 'Rejected' WHERE booking_id = $booking_id AND LOWER(TRIM(status)) NOT IN ('rejected', 'cancelled')");
            }
        } catch (Throwable $t) {}

        return ['released' => array_values(array_unique(array_filter($released))), 'kept' => array_values(array_unique(array_filter($kept)))];
    }
}

if (!function_exists('auto_cancel_overdue_bookings')) {
    /**
     * Cancels installment bookings with 3+ consecutive unpaid past-due installments.
     * Preserves all payment records. Idempotent. Returns summary array.
     */
    function auto_cancel_overdue_bookings($conn, $source = 'SYSTEM/AUTOMATIC') {
        $summary = ['checked' => 0, 'cancelled' => [], 'errors' => []];
        if (!$conn || $conn->connect_errno) return $summary;
        try {
            ensure_cancellation_columns($conn);
            $cRes = $conn->query("SELECT id FROM tbl_bookings WHERE LOWER(TRIM(COALESCE(payment_plan,''))) = 'installment' AND LOWER(TRIM(COALESCE(status,''))) IN ('pending','approved')");
            if (!$cRes) return $summary;
            while ($cRow = $cRes->fetch_assoc()) {
                $bid = (int)$cRow['id'];
                $summary['checked']++;
                try {
                    $d = get_booking_delinquency($conn, $bid);
                    if (!$d['is_installment'] || !$d['has_schedule']) continue;
                    if ((int)$d['consecutive_unpaid'] < 3) continue;
                    $bRes = $conn->query("SELECT id, user_id, customer_name, full_name, grave_location, section, plot, total_amount, amount_paid, remaining_balance, status, payment_status FROM tbl_bookings WHERE id = $bid LIMIT 1");
                    $b = $bRes ? $bRes->fetch_assoc() : null;
                    if (!$b) continue;
                    if (!in_array(strtolower(trim((string)($b['status'] ?? ''))), ['pending', 'approved'], true)) continue;
                    $prevStatus = $b['status'];
                    $userName = trim((string)(($b['customer_name'] ?? '') !== '' ? $b['customer_name'] : ($b['full_name'] ?? '')));
                    $plotLabel = trim((string)($b['grave_location'] ?? '')) !== '' ? $b['grave_location'] : ('Section ' . ($b['section'] ?? '') . ' ' . ($b['plot'] ?? ''));
                    $total = number_format((float)($b['total_amount'] ?? 0), 2);
                    $paid = number_format((float)($b['amount_paid'] ?? 0), 2);
                    $bal = number_format((float)($b['remaining_balance'] ?? 0), 2);
                    $reason = 'Cancelled due to 3 months of non-payment';
                    $escReason = $conn->real_escape_string($reason);
                    $conn->query("UPDATE tbl_bookings SET status = 'cancelled', cancellation_reason = '$escReason' WHERE id = $bid");
                    $rel = release_booking_plots($conn, $bid);
                    $plotIds = [];
                    try {
                        $pidRes = $conn->query("SELECT plot_id, section, plot_x, plot_y FROM booking_plots WHERE booking_id = $bid");
                        if ($pidRes) { while ($pidRow = $pidRes->fetch_assoc()) { $plotIds[] = '#' . (int)($pidRow['plot_id'] ?? 0) . ' (' . $pidRow['section'] . ' ' . $pidRow['plot_x'] . '-' . $pidRow['plot_y'] . ')'; } }
                    } catch (Throwable $tPid) {}
                    $logUid = (int)($b['user_id'] ?? 0);
                    $details = "Booking #$bid automatically cancelled. Reason: $reason. " .
                        "User ID: $logUid. User name: " . ($userName !== '' ? $userName : 'n/a') . ". " .
                        "Plot: $plotLabel. Plot IDs: " . (empty($plotIds) ? 'n/a' : implode(', ', $plotIds)) . ". " .
                        "Previous booking status: " . strtoupper($prevStatus) . ". New booking status: CANCELLED. " .
                        "Consecutive unpaid months: " . (int)$d['consecutive_unpaid'] . ". " .
                        "Last payment date: " . ($d['last_payment_date'] ?? 'none') . ". " .
                        "Last due date: " . ($d['last_due_date'] ?? 'n/a') . ". " .
                        "Amount Paid: ₱$paid. Remaining Balance (outstanding): ₱$bal. Total: ₱$total. " .
                        "Refund: NON-REFUNDABLE. Plots released: " . (empty($rel['released']) ? 'none' : implode(', ', $rel['released'])) . ". " .
                        "Plots kept (occupied): " . (empty($rel['kept']) ? 'none' : implode(', ', $rel['kept'])) . ". " .
                        "Timestamp: " . date('Y-m-d H:i:s') . ". Action source: $source.";
                    $lStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('Booking Auto-Cancelled', ?, ?, NOW())");
                    if ($lStmt) { $lStmt->bind_param('si', $details, $logUid); $lStmt->execute(); $lStmt->close(); }
                    $summary['cancelled'][] = ['booking_id' => $bid, 'user' => $userName, 'plot' => $plotLabel, 'prev_status' => $prevStatus, 'paid' => $paid, 'balance' => $bal];
                } catch (Throwable $tIn) {
                    $summary['errors'][] = ['booking_id' => $bid, 'error' => $tIn->getMessage()];
                }
            }
        } catch (Throwable $t) {
            $summary['errors'][] = ['booking_id' => 0, 'error' => $t->getMessage()];
        }
        return $summary;
    }
}

if (!function_exists('cancel_booking_by_user')) {
    /**
     * User-initiated cancellation. Preserves payments (NO REFUND). Returns result array.
     */
    function cancel_booking_by_user($conn, $booking_id, $user_id, $is_admin = false) {
        $booking_id = (int)$booking_id;
        $user_id = (int)$user_id;
        if ($booking_id <= 0) return ['success' => false, 'message' => 'Invalid booking ID.'];
        if (!$is_admin && $user_id <= 0) return ['success' => false, 'message' => 'Please log in to cancel a booking.'];
        if (!$conn || $conn->connect_errno) return ['success' => false, 'message' => 'Database connection failed.'];
        try {
            ensure_cancellation_columns($conn);
            $bRes = $conn->query("SELECT * FROM tbl_bookings WHERE id = $booking_id LIMIT 1");
            $b = $bRes ? $bRes->fetch_assoc() : null;
            if (!$b) return ['success' => false, 'message' => 'Booking not found.'];

            if (!$is_admin) {
                $bUid = (int)($b['user_id'] ?? 0);
                $bEmail = strtolower(trim((string)($b['customer_email'] ?? ($b['email'] ?? ''))));
                $userEmail = '';
                if ($user_id > 0) {
                    $uRes = $conn->query("SELECT email FROM tbl_user WHERE user_id = $user_id LIMIT 1");
                    if ($uRes && ($uRow = $uRes->fetch_assoc())) {
                        $userEmail = strtolower(trim((string)$uRow['email']));
                    }
                }
                $authorized = ($bUid > 0 && $bUid === $user_id) || (!empty($userEmail) && !empty($bEmail) && $bEmail === $userEmail);
                if (!$authorized) {
                    return ['success' => false, 'message' => 'You are not authorized to cancel this booking.'];
                }
            }

            $cur = strtolower(trim((string)($b['status'] ?? '')));
            if (in_array($cur, ['cancelled', 'rejected', 'completed'], true)) {
                return ['success' => false, 'message' => 'This booking can no longer be cancelled (status: ' . strtoupper($cur) . ').'];
            }
            $reason = 'Cancelled by user';
            $escReason = $conn->real_escape_string($reason);
            $conn->query("UPDATE tbl_bookings SET status = 'cancelled', cancellation_reason = '$escReason' WHERE id = $booking_id");
            $rel = release_booking_plots($conn, $booking_id);
            $paid = number_format((float)($b['amount_paid'] ?? 0), 2);
            $bal = number_format((float)($b['remaining_balance'] ?? 0), 2);
            $total = number_format((float)($b['total_amount'] ?? 0), 2);
            $details = "Booking #$booking_id cancelled by user (ID: $user_id). Previous status: " . strtoupper($cur) . ". " .
                "Total: ₱$total. Amount Paid (preserved): ₱$paid. Remaining: ₱$bal. Refund: NON-REFUNDABLE. " .
                "Plots released: " . (empty($rel['released']) ? 'none' : implode(', ', $rel['released'])) . ". " .
                "Plots kept (occupied): " . (empty($rel['kept']) ? 'none' : implode(', ', $rel['kept'])) . ".";
            $lStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('Booking Cancelled', ?, ?, NOW())");
            if ($lStmt) { $lStmt->bind_param('si', $details, $user_id); $lStmt->execute(); $lStmt->close(); }
            return ['success' => true, 'message' => 'Booking cancelled. Payments made are non-refundable according to the cemetery policy.', 'booking_id' => $booking_id, 'paid' => $paid, 'remaining' => $bal, 'total' => $total];
        } catch (Throwable $t) {
            return ['success' => false, 'message' => 'System error: ' . $t->getMessage()];
        }
    }
}

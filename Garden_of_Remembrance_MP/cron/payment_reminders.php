<?php

@set_time_limit(300);
@ini_set('memory_limit', '256M');

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/../includes/notification_helper.php';
require_once __DIR__ . '/../email_template.php';

$is_cli = (php_sapi_name() === 'cli');
$cron_key = 'gor_cron_reminder_2026';
$provided_key = $_GET['key'] ?? '';

if (!$is_cli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $is_admin = isset($_SESSION['role']) && strtoupper($_SESSION['role']) === 'ADMIN';
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (!$is_admin && $provided_key !== $cron_key) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Provide valid key (?key=' . $cron_key . ') or login as Admin.']);
        exit;
    }
}

$today = date('Y-m-d');
$todayTs = strtotime($today);

$conn->query("CREATE TABLE IF NOT EXISTS `payment_reminder_history` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `schedule_id` INT(11) NOT NULL,
    `booking_id` INT(11) DEFAULT NULL,
    `user_id` INT(11) NOT NULL,
    `reminder_type` VARCHAR(50) NOT NULL,
    `due_date` DATE NOT NULL,
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_schedule_reminder_type_date` (`schedule_id`, `reminder_type`, `due_date`),
    KEY `idx_reminder_user_date` (`user_id`, `due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("UPDATE payment_schedules SET status = 'overdue' WHERE due_date < '$today' AND status = 'pending'");

// Automatic cancellation: installment bookings with 3+ consecutive unpaid
// past-due installments are cancelled (payments preserved, NO REFUND).
if (file_exists(__DIR__ . '/../config/cancellation_helper.php')) {
    require_once __DIR__ . '/../config/cancellation_helper.php';
}
if (function_exists('auto_cancel_overdue_bookings')) {
    try {
        $autoCancelSummary = auto_cancel_overdue_bookings($conn, 'CRON');
        $summary['auto_cancelled'] = isset($autoCancelSummary['cancelled']) ? count($autoCancelSummary['cancelled']) : 0;
        $summary['auto_cancel_details'] = isset($autoCancelSummary['cancelled']) ? $autoCancelSummary['cancelled'] : [];
        if (!empty($autoCancelSummary['errors'])) {
            $summary['auto_cancel_errors'] = $autoCancelSummary['errors'];
        }
    } catch (Throwable $acEx) {
        $summary['auto_cancel_errors'] = [['booking_id' => 0, 'error' => $acEx->getMessage()]];
    }
}

$sql = "
    SELECT 
        ps.id AS schedule_id,
        ps.contract_id,
        ps.user_id,
        ps.installment_number,
        ps.monthly_amount,
        ps.remaining_balance,
        ps.term_months,
        ps.status AS schedule_status,
        ps.due_date,
        ps.user_email,
        b.customer_name,
        b.full_name,
        b.customer_email,
        b.email AS booking_email,
        b.section,
        b.plot,
        b.grave_location,
        b.total_amount,
        u.email AS user_table_email,
        u.first_name,
        u.last_name
    FROM payment_schedules ps
    LEFT JOIN tbl_bookings b ON b.id = ps.contract_id
    LEFT JOIN tbl_user u ON u.user_id = ps.user_id
    WHERE ps.status IN ('pending', 'overdue')
      AND ps.remaining_balance > 0
      AND LOWER(TRIM(COALESCE(b.status, ''))) NOT IN ('cancelled', 'rejected')
    ORDER BY ps.due_date ASC, ps.contract_id ASC
";

$res = $conn->query($sql);
if (!$res) {
    $err = "Query error: " . $conn->error;
    if ($is_cli) {
        echo "[ERROR] $err\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $err]);
    }
    exit;
}

$summary = [
    'evaluated'     => 0,
    'reminders_sent'=> 0,
    'skipped_dup'   => 0,
    'failed_email'  => 0,
    'details'       => []
];

while ($row = $res->fetch_assoc()) {
    $summary['evaluated']++;
    $schedId = (int)$row['schedule_id'];
    $contractId = (int)($row['contract_id'] ?? 0);
    $userId = (int)($row['user_id'] ?? 0);
    $instNum = (int)($row['installment_number'] ?? 1);
    $monthlyAmt = (float)($row['monthly_amount'] ?? 0);
    $remainingBal = (float)($row['remaining_balance'] ?? 0);
    $dueDate = $row['due_date'];
    $dueTs = strtotime($dueDate);
    $diffDays = (int)round(($dueTs - $todayTs) / 86400);

    $reminderType = null;
    $title = '';
    $message = '';
    $badge = '';

    if ($diffDays === 7) {
        $reminderType = '7_DAYS_BEFORE';
        $title = "Payment Reminder: Due in 7 Days (Booking #$contractId)";
        $message = "Your installment payment of ₱" . number_format($monthlyAmt, 2) . " (Installment #$instNum) for Booking #$contractId is due in 7 days on " . date('F j, Y', $dueTs) . ".";
        $badge = "Due in 7 Days";
    } elseif ($diffDays === 1) {
        $reminderType = '1_DAY_BEFORE';
        $title = "Payment Reminder: Due Tomorrow (Booking #$contractId)";
        $message = "Your installment payment of ₱" . number_format($monthlyAmt, 2) . " (Installment #$instNum) for Booking #$contractId is due tomorrow (" . date('F j, Y', $dueTs) . ").";
        $badge = "Due Tomorrow";
    } elseif ($diffDays === 0) {
        $reminderType = 'DUE_DATE';
        $title = "Payment Due Today: Booking #$contractId";
        $message = "Your installment payment of ₱" . number_format($monthlyAmt, 2) . " (Installment #$instNum) for Booking #$contractId is now due today (" . date('F j, Y', $dueTs) . ").";
        $badge = "Due Today";
    } elseif ($diffDays < 0) {
        $reminderType = 'OVERDUE';
        $daysPast = abs($diffDays);
        $title = "URGENT: Payment Overdue (Booking #$contractId)";
        $message = "Your installment payment of ₱" . number_format($monthlyAmt, 2) . " (Installment #$instNum) for Booking #$contractId is overdue by $daysPast day(s). Due date was " . date('F j, Y', $dueTs) . ".";
        $badge = "Payment Overdue";
    } else {

        continue;
    }

    $chkStmt = $conn->prepare("SELECT id FROM payment_reminder_history WHERE schedule_id = ? AND reminder_type = ? AND due_date = ? LIMIT 1");
    if ($chkStmt) {
        $chkStmt->bind_param('iss', $schedId, $reminderType, $dueDate);
        $chkStmt->execute();
        $chkRes = $chkStmt->get_result();
        $isDuplicate = ($chkRes && $chkRes->num_rows > 0);
        $chkStmt->close();

        if ($isDuplicate) {
            $summary['skipped_dup']++;
            continue;
        }
    }

    $custName = trim(($row['customer_name'] ?? '') ?: ($row['full_name'] ?? ''));
    if (empty($custName)) {
        $custName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Valued Customer';
    }
    $recipientEmail = strtolower(trim($row['user_email'] ?: $row['customer_email'] ?: $row['booking_email'] ?: $row['user_table_email'] ?: ''));

    $detailsTable = [
        'Booking Reference' => '#' . $contractId,
        'Installment Number'=> '#' . $instNum . ' of ' . ($row['term_months'] ?: 12),
        'Due Date'          => date('F j, Y', $dueTs),
        'Amount Due'        => '₱' . number_format($monthlyAmt, 2),
        'Remaining Balance' => '₱' . number_format($remainingBal, 2),
        'Payment Status'    => strtoupper($row['schedule_status'] ?? 'PENDING')
    ];
    if (!empty($row['section'])) {
        $detailsTable['Section'] = 'Section ' . strtoupper($row['section']);
    }

    $subject = "$title | Garden of Remembrance";
    $html = render_branded_email([
        'title'                 => $title,
        'badge'                 => $badge,
        'greeting_name'         => $custName,
        'intro_lines'           => [
            $message,
            "Please find your installment payment breakdown below:"
        ],
        'details_section_title' => 'Installment Schedule Breakdown',
        'details_table'         => $detailsTable,
        'action_button'         => [
            'text' => 'Make Payment Online / Upload Proof',
            'url'  => get_email_asset_base_url() . '/user_dashboard.php'
        ],
        'outro_lines'           => [
            "You may submit payment via GCash or in-person at the cemetery administrative office.",
            "Keeping your payments on schedule ensures smooth cemetery services."
        ]
    ]);

    $dispatch = send_system_notification(
        $conn,
        $userId,
        $contractId,
        'payment_reminder',
        $title,
        $message,
        'user_dashboard.php',
        true,
        $subject,
        $html
    );

    $insH = $conn->prepare("INSERT INTO payment_reminder_history (schedule_id, booking_id, user_id, reminder_type, due_date, sent_at) VALUES (?, ?, ?, ?, ?, NOW())");
    if ($insH) {
        $insH->bind_param('iiiss', $schedId, $contractId, $userId, $reminderType, $dueDate);
        $insH->execute();
        $insH->close();
    }

    @$conn->query("UPDATE payment_schedules SET reminder_sent_at = NOW() WHERE id = $schedId");

    $summary['reminders_sent']++;
    $summary['details'][] = [
        'schedule_id'  => $schedId,
        'contract_id'  => $contractId,
        'type'         => $reminderType,
        'email'        => $recipientEmail,
        'email_sent'   => $dispatch['email_sent']
    ];

    if (!$dispatch['email_sent']) {
        $summary['failed_email']++;
    }
}

if ($is_cli) {
    echo "=== Payment Reminder Cron Summary ===\n";
    echo "Schedules Evaluated: " . $summary['evaluated'] . "\n";
    echo "Reminders Dispatched: " . $summary['reminders_sent'] . "\n";
    echo "Skipped (Duplicate Prevention): " . $summary['skipped_dup'] . "\n";
    echo "Email Failures: " . $summary['failed_email'] . "\n";
    echo "Auto-Cancelled (3+ months unpaid): " . (int)($summary['auto_cancelled'] ?? 0) . "\n";
    if (!empty($summary['auto_cancel_details'])) {
        foreach ($summary['auto_cancel_details'] as $ac) {
            echo "  - Booking #" . $ac['booking_id'] . " (" . $ac['user'] . ") cancelled. Paid: " . $ac['paid'] . "\n";
        }
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'summary' => $summary]);
}

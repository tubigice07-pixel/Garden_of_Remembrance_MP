<?php

if (!function_exists('ensure_notifications_table')) {
    function ensure_notifications_table(mysqli $conn): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS `notifications` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `booking_id` INT DEFAULT NULL,
            `type` VARCHAR(50) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `link` VARCHAR(255) DEFAULT NULL,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `read_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_notif_user_read` (`user_id`, `is_read`),
            KEY `idx_notif_user_created` (`user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        if ($conn->query($sql)) {
            return true;
        }

        error_log('Notification table unavailable: ' . $conn->error);
        return false;
    }
}

if (!function_exists('notify_administrators')) {
    function notify_administrators(mysqli $conn, string $title, string $message, ?string $link = null, ?int $bookingId = null, string $type = 'user_action'): int
    {
        if (!ensure_notifications_table($conn)) {
            return 0;
        }
        $sent = 0;
        $admins = $conn->query("SELECT user_id FROM tbl_user WHERE UPPER(TRIM(role)) = 'ADMIN'");
        if (!$admins) {
            return 0;
        }
        $bId = ($bookingId !== null && $bookingId > 0) ? (int)$bookingId : null;
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, booking_id, type, title, message, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())");
        if (!$stmt) {
            return 0;
        }
        while ($admin = $admins->fetch_assoc()) {
            $adminId = (int)$admin['user_id'];
            $stmt->bind_param('iissss', $adminId, $bId, $type, $title, $message, $link);
            if ($stmt->execute()) {
                $sent++;
            }
        }
        $stmt->close();
        return $sent;
    }
}

if (!function_exists('ensure_email_notifications_table')) {
    function ensure_email_notifications_table(mysqli $conn): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS `email_notifications` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` INT DEFAULT NULL,
            `booking_id` INT DEFAULT NULL,
            `recipient_email` VARCHAR(255) NOT NULL,
            `type` VARCHAR(50) NOT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `status` ENUM('sent','failed') NOT NULL DEFAULT 'sent',
            `error_message` TEXT DEFAULT NULL,
            `sent_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_email_notif_user_type` (`user_id`, `type`),
            KEY `idx_email_notif_recipient` (`recipient_email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        return (bool)$conn->query($sql);
    }
}

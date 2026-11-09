<?php

require_once __DIR__ . '/../connection.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    die("Database connection failed.\n");
}

echo "Running migration on database: " . $conn->query("SELECT DATABASE()")->fetch_row()[0] . "\n";

$sql = "CREATE TABLE IF NOT EXISTS `guest_reservation_requests` (
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
  `status` ENUM('Pending', 'Approved', 'Rejected', 'Account Created', 'Cancelled', 'Expired') NOT NULL DEFAULT 'Pending',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($conn->query($sql)) {
    echo "✓ Table `guest_reservation_requests` created or already exists.\n";
} else {
    echo "✗ Failed to create `guest_reservation_requests`: " . $conn->error . "\n";
}

try {

    $chkFk = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_reservation_requests' AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'tbl_user'");
    if ($chkFk && $chkFk->num_rows === 0) {
        @$conn->query("ALTER TABLE `guest_reservation_requests` ADD CONSTRAINT `fk_grr_user` FOREIGN KEY (`user_id`) REFERENCES `tbl_user` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE");
    }
} catch (Throwable $e) {}

echo "Migration complete.\n";

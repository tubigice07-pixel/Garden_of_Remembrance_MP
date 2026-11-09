<?php

require_once __DIR__ . '/../connection.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    echo "Database connection failed.\n";
    exit(1);
}

$columnsToAdd = [
    'is_guest_booking' => "ALTER TABLE tbl_bookings ADD COLUMN is_guest_booking TINYINT(1) DEFAULT 0 AFTER status",
    'registration_token' => "ALTER TABLE tbl_bookings ADD COLUMN registration_token VARCHAR(64) NULL AFTER is_guest_booking",
    'token_expires_at' => "ALTER TABLE tbl_bookings ADD COLUMN token_expires_at DATETIME NULL AFTER registration_token",
    'token_used' => "ALTER TABLE tbl_bookings ADD COLUMN token_used TINYINT(1) DEFAULT 0 AFTER token_expires_at",
    'token_used_at' => "ALTER TABLE tbl_bookings ADD COLUMN token_used_at DATETIME NULL AFTER token_used"
];

foreach ($columnsToAdd as $col => $sql) {
    $check = $conn->query("SHOW COLUMNS FROM tbl_bookings LIKE '$col'");
    if (!$check || $check->num_rows === 0) {
        if ($conn->query($sql)) {
            echo "✓ Added column $col to tbl_bookings\n";
        } else {
            echo "✗ Failed to add $col: " . $conn->error . "\n";
        }
    } else {
        echo "✓ Column $col already exists in tbl_bookings\n";
    }
}

$idxCheck = $conn->query("SHOW INDEX FROM tbl_bookings WHERE Key_name = 'idx_registration_token'");
if (!$idxCheck || $idxCheck->num_rows === 0) {
    @$conn->query("CREATE INDEX idx_registration_token ON tbl_bookings (registration_token)");
    echo "✓ Created index on registration_token\n";
}

@$conn->query("ALTER TABLE tbl_bookings MODIFY COLUMN user_id INT(11) NULL DEFAULT NULL");

@$conn->query("ALTER TABLE tbl_user MODIFY COLUMN suffix VARCHAR(50) NULL DEFAULT ''");
@$conn->query("ALTER TABLE tbl_user MODIFY COLUMN birthday DATE NULL");
@$conn->query("ALTER TABLE tbl_user MODIFY COLUMN phone_no VARCHAR(20) NULL DEFAULT ''");

echo "Migration check complete.\n";

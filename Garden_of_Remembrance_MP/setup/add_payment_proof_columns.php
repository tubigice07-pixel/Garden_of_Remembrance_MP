<?php

include_once __DIR__ . '/../connection.php';

$checkProofCol = $conn->query("SHOW COLUMNS FROM tbl_bookings LIKE 'payment_proof_filename'");
$checkRefCol = $conn->query("SHOW COLUMNS FROM tbl_bookings LIKE 'gcash_reference_number'");
$checkUploadDateCol = $conn->query("SHOW COLUMNS FROM tbl_bookings LIKE 'payment_proof_uploaded_at'");

$needsUpdate = false;

if (!$checkProofCol || $checkProofCol->num_rows === 0) {
    echo "Adding payment_proof_filename column...<br>";
    if ($conn->query("ALTER TABLE tbl_bookings ADD COLUMN payment_proof_filename VARCHAR(255) NULL AFTER bank_name")) {
        echo "✓ payment_proof_filename added<br>";
        $needsUpdate = true;
    } else {
        echo "✗ Failed to add payment_proof_filename: " . $conn->error . "<br>";
    }
}

if (!$checkRefCol || $checkRefCol->num_rows === 0) {
    echo "Adding gcash_reference_number column...<br>";
    if ($conn->query("ALTER TABLE tbl_bookings ADD COLUMN gcash_reference_number VARCHAR(100) NULL AFTER payment_proof_filename")) {
        echo "✓ gcash_reference_number added<br>";
        $needsUpdate = true;
    } else {
        echo "✗ Failed to add gcash_reference_number: " . $conn->error . "<br>";
    }
}

if (!$checkUploadDateCol || $checkUploadDateCol->num_rows === 0) {
    echo "Adding payment_proof_uploaded_at column...<br>";
    if ($conn->query("ALTER TABLE tbl_bookings ADD COLUMN payment_proof_uploaded_at DATETIME NULL AFTER gcash_reference_number")) {
        echo "✓ payment_proof_uploaded_at added<br>";
        $needsUpdate = true;
    } else {
        echo "✗ Failed to add payment_proof_uploaded_at: " . $conn->error . "<br>";
    }
}

if (!$needsUpdate) {
    echo "All payment proof columns already exist.<br>";
} else {
    echo "<br><strong>Database migration completed successfully!</strong><br>";
}

$uploadDir = __DIR__ . '/../uploads/payment-proofs';
if (!is_dir($uploadDir)) {
    if (mkdir($uploadDir, 0755, true)) {
        echo "✓ Created directory: /uploads/payment-proofs<br>";
        
        
        $htaccess = <<<HTACCESS
# Only allow image files
<FilesMatch "(?<!\.(?:jpg|jpeg|png|gif|webp))\$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# Set proper MIME types
AddType image/jpeg .jpg
AddType image/jpeg .jpeg
AddType image/png .png
AddType image/gif .gif
AddType image/webp .webp

# Disable PHP execution
php_flag engine off
AddType text/plain .php .php3 .php4 .php5 .php6 .php7 .phps .phtml .pht .phar
HTACCESS;
        
        if (file_put_contents("$uploadDir/.htaccess", $htaccess)) {
            echo "✓ Created security .htaccess file<br>";
        }
    } else {
        echo "⚠ Warning: Could not create /uploads/payment-proofs directory. Please create it manually.<br>";
    }
}

$conn->close();
?>

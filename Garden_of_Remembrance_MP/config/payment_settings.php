<?php
/**
 * Central Payment Configuration
 * 
 * Manages GCash payment settings, merchant details, and scannable QR payload data.
 */

if (!function_exists('get_gcash_payment_settings')) {
    function get_gcash_payment_settings(): array
    {
        static $cached_settings = null;
        if ($cached_settings !== null) {
            return $cached_settings;
        }

        // Default configured values
        $accountName = 'Garden of Remembrance Memorial Park';
        $rawNumber = '09178234567'; // Configured GCash Number

        // Check for dedicated gcash_config.php override if present
        $customConfigFile = __DIR__ . '/gcash_config.php';
        if (file_exists($customConfigFile)) {
            $customConfig = include $customConfigFile;
            if (is_array($customConfig)) {
                if (!empty($customConfig['account_name'])) {
                    $accountName = trim($customConfig['account_name']);
                }
                if (!empty($customConfig['number'])) {
                    $rawNumber = trim($customConfig['number']);
                }
            }
        }

        // Check database for configured payment settings if DB connection is active
        global $conn;
        if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
            $tblCheck = $conn->query("SHOW TABLES LIKE 'tbl_settings'");
            if ($tblCheck && $tblCheck->num_rows > 0) {
                $q = $conn->query("SELECT setting_key, setting_value FROM tbl_settings WHERE setting_key IN ('gcash_number', 'gcash_account_name')");
                if ($q) {
                    while ($row = $q->fetch_assoc()) {
                        if ($row['setting_key'] === 'gcash_number' && !empty($row['setting_value'])) {
                            $rawNumber = trim($row['setting_value']);
                        }
                        if ($row['setting_key'] === 'gcash_account_name' && !empty($row['setting_value'])) {
                            $accountName = trim($row['setting_value']);
                        }
                    }
                }
            }
        }

        // Clean digits
        $cleanNumber = preg_replace('/\D+/', '', $rawNumber);
        if (strlen($cleanNumber) === 10 && substr($cleanNumber, 0, 1) === '9') {
            $cleanNumber = '0' . $cleanNumber;
        }
        if (strlen($cleanNumber) === 12 && substr($cleanNumber, 0, 2) === '63') {
            $cleanNumber = '0' . substr($cleanNumber, 2);
        }

        // Format for display (e.g., 0917 823 4567)
        if (strlen($cleanNumber) === 11) {
            $displayNumber = substr($cleanNumber, 0, 4) . ' ' . substr($cleanNumber, 4, 3) . ' ' . substr($cleanNumber, 7);
        } else {
            $displayNumber = $rawNumber;
        }

        // Default QR code image
        $qrImage = 'gcashqr1.jpg';
        if (file_exists(__DIR__ . '/../gcashqr1.jpg')) {
            $qrImage = 'gcashqr1.jpg';
        } elseif (file_exists(__DIR__ . '/../assets/gcashqr1.jpg')) {
            $qrImage = 'assets/gcashqr1.jpg';
        }

        $cached_settings = [
            'label' => 'GCash Account',
            'account_name' => $accountName,
            'number' => $cleanNumber,
            'display_number' => $displayNumber,
            'merchant_id' => 'GCASH-' . substr($cleanNumber, -4),
            'qr_image' => $qrImage,
        ];

        return $cached_settings;
    }
}

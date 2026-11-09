<?php
require_once __DIR__ . '/../connection.php';

header('Content-Type: text/plain; charset=utf-8');

function ensure_column(mysqli $conn, string $table, string $column, string $definition): void {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($res && $res->num_rows === 0) {
        $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $definition";
        if ($conn->query($sql)) {
            echo "Added `$column` to `$table`\n";
        } else {
            echo "Failed adding `$column` to `$table`: " . $conn->error . "\n";
        }
    } else {
        echo "Column `$column` already exists in `$table`\n";
    }
}

ensure_column($conn, 'payment_receipts', 'or_number', 'VARCHAR(100) DEFAULT NULL AFTER `receipt_number`');
ensure_column($conn, 'payment_receipts', 'notes', 'TEXT DEFAULT NULL AFTER `rejection_reason`');
ensure_column($conn, 'payment_receipts', 'recorded_by', 'INT(11) DEFAULT NULL AFTER `verified_by`');
ensure_column($conn, 'payment_receipts', 'recorded_by_name', 'VARCHAR(100) DEFAULT NULL AFTER `recorded_by`');

ensure_column($conn, 'tbl_bookings', 'remaining_balance', 'DECIMAL(10,2) DEFAULT 0.00 AFTER `amount_paid`');
ensure_column($conn, 'tbl_bookings', 'payment_status', "VARCHAR(20) DEFAULT 'PENDING' AFTER `remaining_balance`");
ensure_column($conn, 'tbl_bookings', 'original_payment_method', 'VARCHAR(50) DEFAULT NULL AFTER `payment_type`');

$conn->query("UPDATE tbl_bookings SET original_payment_method = payment_type WHERE (original_payment_method IS NULL OR original_payment_method = '') AND payment_type IS NOT NULL");

$bRes = $conn->query("SELECT id, total_amount, amount_paid, plot_price, service_price, interment_fee FROM tbl_bookings");
if ($bRes) {
    while ($b = $bRes->fetch_assoc()) {
        $bId = (int)$b['id'];
        $tot = (float)($b['total_amount'] ?? 0);
        if ($tot <= 0) {
            $tot = (float)($b['plot_price'] ?? 0) + (float)($b['service_price'] ?? 0) + (float)($b['interment_fee'] ?? 0);
        }

        $prQ = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM payment_receipts WHERE booking_id = $bId AND UPPER(COALESCE(payment_status, '')) IN ('PAID', 'VERIFIED')");
        $paid = 0.00;
        if ($prQ && ($prRow = $prQ->fetch_assoc())) {
            $paid = (float)$prRow['total_paid'];
        }

        if ($paid <= 0 && (float)$b['amount_paid'] > 0) {
            $paid = (float)$b['amount_paid'];
        }
        $rem = max(0.00, round($tot - $paid, 2));
        $pStatus = ($paid >= $tot && $tot > 0) ? 'PAID' : (($paid > 0) ? 'PARTIAL' : 'PENDING');

        $up = $conn->prepare("UPDATE tbl_bookings SET total_amount = ?, amount_paid = ?, remaining_balance = ?, payment_status = ? WHERE id = ?");
        if ($up) {
            $up->bind_param('dddsi', $tot, $paid, $rem, $pStatus, $bId);
            $up->execute();
            $up->close();
        }
    }
}

echo "\nMigration completed successfully.\n";

<?php
require __DIR__ . '/connection.php';
header('Content-Type: text/plain');

echo "=== TBL_USER ===\n";
$u = $conn->query("SELECT user_id, first_name, last_name, role FROM tbl_user");
while ($row = $u->fetch_assoc()) {
    echo "ID: {$row['user_id']} | {$row['first_name']} {$row['last_name']} | Role: {$row['role']}\n";
}

echo "\n=== USER_PLOT_OWNERSHIP ===\n";
$upo = $conn->query("SELECT * FROM user_plot_ownership");
echo "Count: " . ($upo ? $upo->num_rows : 0) . "\n";
while ($row = $upo->fetch_assoc()) {
    echo "ID: {$row['id']} | User: {$row['user_id']} | Sec: {$row['section']} | Plot: {$row['plot_x']}-{$row['plot_y']} | Status: {$row['ownership_status']} | Ver: {$row['verification_status']}\n";
}

echo "\n=== TBL_PLOT_OWNERS ===\n";
$po = $conn->query("SELECT * FROM tbl_plot_owners LIMIT 20");
echo "Count: " . ($po ? $po->num_rows : 0) . "\n";
while ($row = $po->fetch_assoc()) {
    echo "ID: {$row['id']} | Sec: {$row['section']} | Plot: {$row['plot_x']}-{$row['plot_y']} | Owner: {$row['owner_name']} | ReservedBy: {$row['reserved_by']} | Active: {$row['active']}\n";
}

echo "\n=== TBL_PLOTS (Status != 'available') ===\n";
$pl = $conn->query("SELECT plot_id, section, plot_x, plot_y, status FROM tbl_plots WHERE LOWER(status) != 'available' LIMIT 30");
echo "Count: " . ($pl ? $pl->num_rows : 0) . "\n";
while ($row = $pl->fetch_assoc()) {
    echo "ID: {$row['plot_id']} | Sec: {$row['section']} | Plot: {$row['plot_x']}-{$row['plot_y']} | Status: {$row['status']}\n";
}

echo "\n=== TBL_BOOKINGS ===\n";
$bk = $conn->query("SELECT id, user_id, customer_name, section, plot_x, plot_y, status, service_type FROM tbl_bookings LIMIT 20");
echo "Count: " . ($bk ? $bk->num_rows : 0) . "\n";
while ($row = $bk->fetch_assoc()) {
    echo "ID: {$row['id']} | User: {$row['user_id']} | Name: {$row['customer_name']} | Sec: {$row['section']} | Plot: {$row['plot_x']}-{$row['plot_y']} | Status: {$row['status']}\n";
}

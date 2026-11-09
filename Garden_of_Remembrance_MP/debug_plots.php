<?php
require_once __DIR__ . '/connection.php';

echo "=== USER LIST ===\n";
$r = $conn->query("SELECT user_id, first_name, last_name, email, role FROM tbl_user");
while ($row = $r->fetch_assoc()) {
    echo "ID: {$row['user_id']} | Name: {$row['first_name']} {$row['last_name']} | Email: {$row['email']} | Role: {$row['role']}\n";
}

echo "\n=== USER PLOT OWNERSHIP ===\n";
$r2 = $conn->query("SELECT * FROM user_plot_ownership ORDER BY user_id, section, plot_x, plot_y");
if ($r2) {
    while ($row = $r2->fetch_assoc()) {
        echo "User: {$row['user_id']} | Sec: {$row['section']} | Plot: {$row['plot_x']}-{$row['plot_y']} | Status: {$row['ownership_status']} | Ver: {$row['verification_status']} | Source: {$row['claim_source']} | Dec: {$row['deceased_reference']}\n";
    }
} else {
    echo "user_plot_ownership table query error: " . $conn->error . "\n";
}

echo "\n=== TBL_PLOT_OWNERS FOR SECTION A 1-2, 2-2, 25-3, 31-3, 50-3 ===\n";
$r3 = $conn->query("SELECT * FROM tbl_plot_owners WHERE section = 'A' AND plot_x IN (1, 2, 25, 31, 50)");
if ($r3) {
    while ($row = $r3->fetch_assoc()) {
        echo "ID: {$row['id']} | Sec: {$row['section']} | Plot: {$row['plot_x']}-{$row['plot_y']} | Owner: " . ($row['owner_name'] ?? '') . " | Reserved By: " . ($row['reserved_by'] ?? 'NULL') . " | Active: " . ($row['active'] ?? '') . "\n";
    }
}

echo "\n=== TBL_DECEASED FOR SECTION A 1-2, 2-2 ===\n";
$r4 = $conn->query("SELECT deceased_id, first_name, last_name, section, plot_x, plot_y, user_id FROM tbl_deceased WHERE section = 'A' AND plot_x IN (1, 2, 25, 31, 50)");
if ($r4) {
    while ($row = $r4->fetch_assoc()) {
        echo "Dec ID: {$row['deceased_id']} | Name: {$row['first_name']} {$row['last_name']} | Sec: {$row['section']} | Plot: {$row['plot_x']}-{$row['plot_y']} | User ID: " . ($row['user_id'] ?? 'NULL') . "\n";
    }
}

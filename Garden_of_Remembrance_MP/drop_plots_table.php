<?php
/**
 * Garden of Remembrance - One-time Live Database Cleanup Utility
 * Safely drops the duplicate legacy 'plots' table (and optional unused tables)
 * Active data remains safely in 'tbl_plots', 'tbl_deceased', etc.
 */

require_once __DIR__ . '/connection.php';

// Security check: Only allow logged-in admin OR access with secure key
$authorized = false;
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$secret_key = 'garden_cleanup_2026';
if (isset($_GET['key']) && $_GET['key'] === $secret_key) {
    $authorized = true;
} elseif (!empty($_SESSION['admin_logged_in']) || !empty($_SESSION['admin_id'])) {
    $authorized = true;
}

if (!$authorized) {
    http_response_code(403);
    die("<h3>Access Denied</h3><p>Unauthorized access. Please log in as Admin or provide the correct security key.</p>");
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Drop Legacy Duplicate Plots Table</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; color: #333; margin: 40px auto; max-width: 600px; padding: 20px; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); padding: 25px; }
        h2 { margin-top: 0; color: #1e3a8a; }
        .status { padding: 12px; border-radius: 6px; margin: 15px 0; }
        .success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .btn { display: inline-block; background: #dc2626; color: #fff; border: none; padding: 10px 18px; border-radius: 6px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .btn:hover { background: #b91c1c; }
        ul { padding-left: 20px; }
    </style>
</head>
<body>
<div class="card">
    <h2>Database Maintenance: Drop Duplicate Tables</h2>
    <p>This script removes legacy duplicate tables while preserving all active data in <code>tbl_plots</code>, <code>tbl_deceased</code>, <code>user_plot_ownership</code>, etc.</p>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_drop'])) {
        $tables_to_drop = ['plots'];
        
        // Optionally drop other obsolete duplicate tables if checked
        if (!empty($_POST['drop_all_duplicates'])) {
            $tables_to_drop = array_merge($tables_to_drop, ['deceased', 'lot_pricing', 'booking_payment_schedule', 'reservations', 'sections', 'services', 'interment_packages']);
        }

        echo "<div class='status info'><strong>Executing table removal on database:</strong> " . htmlspecialchars($connectedDb ?: $dbname) . "</div>";
        echo "<ul>";

        foreach ($tables_to_drop as $tbl) {
            $check = @$conn->query("SHOW TABLES LIKE '{$tbl}'");
            if ($check && $check->num_rows > 0) {
                if ($conn->query("DROP TABLE IF EXISTS `{$tbl}`")) {
                    echo "<li style='color:green;'>Successfully dropped duplicate table: <strong>{$tbl}</strong></li>";
                } else {
                    echo "<li style='color:red;'>Failed to drop table {$tbl}: " . htmlspecialchars($conn->error) . "</li>";
                }
            } else {
                echo "<li style='color:gray;'>Table <strong>{$tbl}</strong> does not exist (already removed).</li>";
            }
        }
        echo "</ul>";

        // Verification
        $tblPlotsCheck = @$conn->query("SHOW TABLES LIKE 'tbl_plots'");
        if ($tblPlotsCheck && $tblPlotsCheck->num_rows > 0) {
            $countRes = $conn->query("SELECT COUNT(*) AS total FROM tbl_plots");
            $totalPlots = $countRes ? $countRes->fetch_assoc()['total'] : 0;
            echo "<div class='status success'>Active table <strong>tbl_plots</strong> is safe and intact with <strong>{$totalPlots}</strong> records!</div>";
        }

        echo "<p style='margin-top:20px;'><a href='dashboard.php' style='color:#2563eb;font-weight:600;'>&larr; Return to Admin Dashboard</a></p>";
    } else {
        // Show current status of tables
        $hasPlots = false;
        $chk = @$conn->query("SHOW TABLES LIKE 'plots'");
        if ($chk && $chk->num_rows > 0) {
            $hasPlots = true;
        }

        if ($hasPlots) {
            echo "<div class='status warning'>Duplicate table <strong>plots</strong> was found in the database.</div>";
        } else {
            echo "<div class='status success'>The duplicate table <strong>plots</strong> is not found (it is already removed).</div>";
        }
        ?>
        <form method="POST" onsubmit="return confirm('Are you sure you want to drop the duplicate plots table? Active data in tbl_plots will not be affected.');">
            <input type="hidden" name="confirm_drop" value="1">
            <p>
                <label>
                    <input type="checkbox" name="drop_all_duplicates" value="1">
                    Also drop other legacy duplicates (<code>deceased</code>, <code>lot_pricing</code>, <code>booking_payment_schedule</code>)
                </label>
            </p>
            <button type="submit" class="btn">Confirm & Drop Duplicate Table(s)</button>
        </form>
    <?php } ?>
</div>
</body>
</html>

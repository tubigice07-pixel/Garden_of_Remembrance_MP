<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header("Location: login.php?auth_required=1");
    exit;
}

$tab = isset($_GET['tab']) && $_GET['tab'] === 'plots' ? '&tab=plots' : '';
$success = isset($_GET['success']) ? '&success=1' : '';
header("Location: user_dashboard.php?open=my_bookings" . $tab . $success);
exit;

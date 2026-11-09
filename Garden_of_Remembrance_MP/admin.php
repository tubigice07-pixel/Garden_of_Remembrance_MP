<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && strtoupper(trim((string)$_SESSION['role'])) === 'ADMIN') {
    header('Location: dashboard.php');
    exit;
}

header('Location: admin_login.php');
exit;

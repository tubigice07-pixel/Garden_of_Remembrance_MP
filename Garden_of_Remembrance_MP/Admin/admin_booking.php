<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isSubdir = (strtolower(basename(__DIR__)) === 'admin');
$prefix = $isSubdir ? '../' : '';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $prefix . 'admin_login.php');
    exit;
}

$isAdmin = strtoupper(trim((string)($_SESSION['role'] ?? ''))) === 'ADMIN';
if (!$isAdmin) {
    header('Location: ' . $prefix . 'user_dashboard.php?open=booking');
    exit;
}

header('Location: ' . $prefix . 'dashboard.php?open=booking');
exit;

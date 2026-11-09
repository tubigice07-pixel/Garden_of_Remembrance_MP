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

if (!isset($_SESSION['role']) || strtoupper(trim((string)$_SESSION['role'])) !== 'ADMIN') {
    header('Location: ' . $prefix . 'admin_login.php');
    exit;
}

require_once __DIR__ . '/' . $prefix . 'dashboard.php';

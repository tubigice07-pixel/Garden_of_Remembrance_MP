<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isSubdir = (strtolower(basename(__DIR__)) === 'user');
if (!$isSubdir) {
    if (file_exists(__DIR__ . '/index.php') && realpath(__FILE__) !== realpath(__DIR__ . '/index.php')) {
        require_once __DIR__ . '/index.php';
        exit;
    }
    if (!empty($_SESSION['user_id'])) {
        header('Location: user_dashboard.php');
        exit;
    }
    header('Location: login.php');
    exit;
}

if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && strtoupper(trim((string)$_SESSION['role'])) !== 'ADMIN') {
    header('Location: ../user_dashboard.php');
    exit;
}

header('Location: ../login.php');
exit;


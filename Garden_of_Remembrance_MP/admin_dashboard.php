<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: admin_login.php');
    exit;
}

if (!isset($_SESSION['role']) || strtoupper(trim((string)$_SESSION['role'])) !== 'ADMIN') {
    header('Location: admin_login.php');
    exit;
}

require_once __DIR__ . '/dashboard.php';

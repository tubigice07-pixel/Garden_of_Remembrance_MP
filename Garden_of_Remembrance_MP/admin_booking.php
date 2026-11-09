<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || strtoupper(trim((string)($_SESSION['role'] ?? ''))) !== 'ADMIN') {
    header('Location: admin_login.php');
    exit;
}
header('Location: dashboard.php?open=booking');
exit;

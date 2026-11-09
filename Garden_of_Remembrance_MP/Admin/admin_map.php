<?php

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

if (!isset($_SESSION['user_id'])) {
	header('Location: ../admin_login.php');
	exit;
}

if (strtoupper(trim((string)($_SESSION['role'] ?? ''))) !== 'ADMIN') {
	header('Location: ../user_dashboard.php');
	exit;
}

define('CEMETERY_ADMIN_MAP', true);
require_once __DIR__ . '/../map.php';

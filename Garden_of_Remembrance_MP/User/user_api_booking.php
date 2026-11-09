<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (isset($_SESSION['role']) && strtoupper($_SESSION['role']) === 'ADMIN') {
    header('Location: ../dashboard.php');
    exit;
}

require_once __DIR__ . '/../api_booking.php';

<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_SESSION['role']) || strtoupper($_SESSION['role']) !== 'ADMIN') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

if (file_exists(__DIR__ . '/api_booking.php')) {
    require_once __DIR__ . '/api_booking.php';
} elseif (file_exists(__DIR__ . '/../api_booking.php')) {
    require_once __DIR__ . '/../api_booking.php';
} else {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => false, 'message' => 'api_booking.php not found']);
    exit;
}

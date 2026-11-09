<?php

if (file_exists(__DIR__ . '/../map_search.php')) {
    require_once __DIR__ . '/../map_search.php';
} else {

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    require_once __DIR__ . '/../connection.php';
    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
        echo json_encode(['success' => false, 'count' => 0, 'results' => [], 'message' => 'Database connection unavailable.']);
        exit;
    }
}

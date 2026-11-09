<?php

if (file_exists(__DIR__ . '/../admin_announcement_api.php')) {
    require_once __DIR__ . '/../admin_announcement_api.php';
} else {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'API handler missing']);
}

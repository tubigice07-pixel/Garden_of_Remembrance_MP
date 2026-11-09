<?php

if (file_exists(__DIR__ . '/../notifications_api.php')) {
    require_once __DIR__ . '/../notifications_api.php';
} else {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    require_once __DIR__ . '/../connection.php';
}

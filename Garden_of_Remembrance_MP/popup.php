<?php

if (file_exists(__DIR__ . '/sections/popup.php')) {
    require __DIR__ . '/sections/popup.php';
} else {
    http_response_code(404);
    echo "Section map popup not found.";
}

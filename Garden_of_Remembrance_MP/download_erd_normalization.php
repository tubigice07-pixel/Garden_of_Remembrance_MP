<?php
/**
 * Garden of Remembrance Memorial Park CMIS
 * Download Handler for Entity Relationship Diagram (ERD) & Normalization (.doc format)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$docTitle = "Garden_of_Remembrance_CMIS_ERD_and_Normalization.doc";
$docPath = __DIR__ . '/' . $docTitle;

if (file_exists($docPath)) {
    header("Content-Type: application/vnd.ms-word; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"" . $docTitle . "\"");
    header("Content-Length: " . filesize($docPath));
    header("Cache-Control: private, max-age=0, must-revalidate");
    header("Pragma: public");
    readfile($docPath);
    exit;
} else {
    http_response_code(404);
    echo "Error: ERD document file not found at " . htmlspecialchars($docPath);
    exit;
}

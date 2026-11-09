<?php

ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

session_start();

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in.']);
    exit;
}
session_write_close();

include_once __DIR__ . '/connection.php';

$section = trim($_REQUEST['section'] ?? '');
$section = strtoupper($section);

if ($section === '') {
    echo json_encode(['success' => false, 'message' => 'Section is required.']);
    exit;
}

if (!preg_match('/^[A-Z0-9]{1,10}$/', $section)) {
    echo json_encode(['success' => false, 'message' => 'Invalid section name.']);
    exit;
}

$stmt = $conn->prepare(
    "SELECT section_name, lawn_type, capacity, base_price
     FROM sections
     WHERE section_name = ?
     LIMIT 1"
);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}
$stmt->bind_param('s', $section);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Section not found.']);
    exit;
}

$row = $result->fetch_assoc();
$stmt->close();

$occStmt = $conn->prepare(
    "SELECT COUNT(*) AS occupied
     FROM tbl_plots
     WHERE section = ? AND LOWER(status) = 'occupied'"
);
$occupied = 0;
if ($occStmt) {
    $occStmt->bind_param('s', $section);
    $occStmt->execute();
    $occRes = $occStmt->get_result();
    if ($occRes && ($occRow = $occRes->fetch_assoc())) {
        $occupied = (int)$occRow['occupied'];
    }
    $occStmt->close();
}

$capacity      = (int)($row['capacity'] ?? 0);
$available     = max(0, $capacity - $occupied);
$price         = (float)($row['base_price'] ?? 0);
$description   = ($row['lawn_type'] ?? '') !== '' ? $row['lawn_type'] : 'Memorial Lawn Section';

echo json_encode([
    'success' => true,
    'section' => [
        'section_name' => $row['section_name'],
        'total_capacity' => $capacity,
        'occupied'       => $occupied,
        'available'      => $available,
        'price'          => $price,
        'lot_price'      => $price,
        'interment_fee'  => 38000.00,
        'total_price'    => $price + 38000.00,
        'description'    => $description
    ]
]);
exit;

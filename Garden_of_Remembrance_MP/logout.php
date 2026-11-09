<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : null;

$_SESSION = array();
if (ini_get("session.use_cookies")) {
	$params = session_get_cookie_params();
	setcookie(session_name(), '', time() - 42000,
		$params['path'], $params['domain'],
		$params['secure'], $params['httponly']
	);
	setcookie(session_name(), '', time() - 42000, '/');
}
session_destroy();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Return JSON for background beacon/AJAX session invalidation
if (!empty($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'session_expired' => true]);
    exit;
}

$isExpired = (!empty($_GET['session_expired']) && $_GET['session_expired'] === '1');
$suffix = $isExpired ? '?session_expired=1' : '?logged_out=1';

if ($role === 'admin') {
	header('Location: admin_login.php' . $suffix);
} else {
	header('Location: login.php' . $suffix);
}
exit;
?>

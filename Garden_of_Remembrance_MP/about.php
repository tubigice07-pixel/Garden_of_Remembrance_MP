<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/connection.php';

$from = strtolower(trim((string)($_GET['from'] ?? '')));

if ($from === 'index' || $from === 'public') {
    header("Location: aboutus.php");
    exit;
}
if ($from === 'admin' || $from === 'admin_login') {
    header("Location: admin_about.php" . ($from === 'admin_login' ? '?from=admin_login' : ''));
    exit;
}
if ($from === 'user' || $from === 'user_dashboard') {
    header("Location: user_about.php");
    exit;
}

if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $role = strtoupper(trim((string)($_SESSION['role'] ?? '')));
    if (empty($role) && isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $rChk = $conn->query("SELECT role FROM tbl_user WHERE user_id = $uid LIMIT 1");
        if ($rChk && $rRow = $rChk->fetch_assoc()) {
            $role = strtoupper(trim((string)$rRow['role']));
            $_SESSION['role'] = $role;
        }
    }
    if ($role === 'ADMIN') {
        header("Location: admin_about.php");
        exit;
    } else {
        header("Location: user_about.php");
        exit;
    }
}

header("Location: aboutus.php");
exit;


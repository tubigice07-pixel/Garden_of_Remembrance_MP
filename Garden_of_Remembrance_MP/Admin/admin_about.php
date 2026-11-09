<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isSubdir = (strtolower(basename(__DIR__)) === 'admin');
$prefix = $isSubdir ? '../' : '';
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ' . $prefix . 'admin_about.php' . $qs);
exit;

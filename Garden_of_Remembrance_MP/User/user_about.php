<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isSubdir = (strtolower(basename(__DIR__)) === 'user');
$prefix = $isSubdir ? '../' : '';
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ' . $prefix . 'user_about.php' . $qs);
exit;

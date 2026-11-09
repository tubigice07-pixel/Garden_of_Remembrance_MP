<?php

$isSubdir = (strtolower(basename(__DIR__)) === 'user');
if (!$isSubdir) {
    if (file_exists(__DIR__ . '/user_dashboard.php') && realpath(__FILE__) !== realpath(__DIR__ . '/user_dashboard.php')) {
        require_once __DIR__ . '/user_dashboard.php';
        exit;
    }
    header('Location: index.php');
    exit;
}
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: ../user_dashboard.php' . $qs, true, 302);
exit;


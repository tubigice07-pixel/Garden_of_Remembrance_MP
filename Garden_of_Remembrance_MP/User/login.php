<?php

$isSubdir = (strtolower(basename(__DIR__)) === 'user');
if (!$isSubdir) {
    if (file_exists(__DIR__ . '/login.php') && realpath(__FILE__) !== realpath(__DIR__ . '/login.php')) {
        require_once __DIR__ . '/login.php';
        exit;
    }
    header('Location: index.php');
    exit;
}
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: ../login.php' . $qs, true, 302);
exit;


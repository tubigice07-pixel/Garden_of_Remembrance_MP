<?php

$isSubdir = (strtolower(basename(__DIR__)) === 'admin');
$prefix = $isSubdir ? '../' : '';
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: ' . $prefix . 'resend_verification.php' . $qs, true, 302);
exit;

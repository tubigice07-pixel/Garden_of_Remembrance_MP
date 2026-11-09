<?php

$isSubdir = (strtolower(basename(__DIR__)) === 'user');
$prefix = $isSubdir ? '../' : '';
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ' . $prefix . 'map.php' . $qs);
exit;

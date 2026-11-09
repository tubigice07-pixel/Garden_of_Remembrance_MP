<?php
$isSubdir = (strtolower(basename(__DIR__)) === 'user');
$prefix = $isSubdir ? '../' : '';
header('Location: ' . $prefix . 'logout.php', true, 302);
exit;

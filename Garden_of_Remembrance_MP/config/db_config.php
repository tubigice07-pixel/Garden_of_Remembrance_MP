<?php
/**
 * Environment-Aware Database Configuration
 * 
 * Automatically switches between Local XAMPP and InfinityFree production
 * without requiring manual code changes when deploying or testing locally.
 */

$isProduction = false;

// 1. Strict OS-level check:
// Local development in this repository is strictly on Windows XAMPP.
// InfinityFree and shared Linux hosting always run on Linux.
$isWindows = (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows')
    || (DIRECTORY_SEPARATOR === '\\')
    || (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

$httpHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
$serverName = strtolower($_SERVER['SERVER_NAME'] ?? '');
$docRoot = str_replace('\\', '/', strtolower($_SERVER['DOCUMENT_ROOT'] ?? ''));

// If on Windows and no explicit production flag is present, ALWAYS use local XAMPP
if ($isWindows && !file_exists(__DIR__ . '/force_production.flag')) {
    $isProduction = false;
} else {
    // Check for InfinityFree / production hosting domains
    $productionDomains = [
        'infinityfreeapp.com',
        'infinityfree',
        'epizy.com',
        'rf.gd',
        'gamer.gd',
        'great-site.net',
        'free.nf',
        '42web.io',
        'kesug.com',
        'ct8.pl'
    ];

    foreach ($productionDomains as $domain) {
        if (strpos($httpHost, $domain) !== false || strpos($serverName, $domain) !== false) {
            $isProduction = true;
            break;
        }
    }

    // Check Linux production server directory structures
    if (!$isProduction) {
        if (strpos($docRoot, '/home/') !== false || strpos($docRoot, '/vol/') !== false || strpos($docRoot, '/epiz') !== false || strpos($docRoot, '/var/www') !== false) {
            $isProduction = true;
        } elseif (!$isWindows && !in_array($httpHost, ['localhost', '127.0.0.1', '::1'], true) && !preg_match('/^(localhost|127\.0\.0\.1|192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[0-1])\.\d+\.\d+)(:\d+)?$/', $httpHost)) {
            $isProduction = true;
        }
    }
}

// Return configuration based on environment
if ($isProduction) {
    $credentialsPath = __DIR__ . '/db_credentials.php';
    if (file_exists($credentialsPath)) {
        $prodCreds = require $credentialsPath;
        if (is_array($prodCreds)) {
            return array_merge([
                'host'     => 'sql109.infinityfree.com',
                'user'     => 'if0_42806961',
                'password' => 'Shumi2231',
                'dbname'   => 'if0_42806961_cemetery2_db',
                'port'     => 3306,
                'env'      => 'production',
            ], $prodCreds, ['env' => 'production']);
        }
    }

    return [
        'host'     => 'sql109.infinityfree.com',
        'user'     => 'if0_42806961',
        'password' => 'Shumi2231',
        'dbname'   => 'if0_42806961_cemetery2_db',
        'port'     => 3306,
        'env'      => 'production',
    ];
}

// Local XAMPP Default Configuration: connects to cemetery2_db
return [
    'host'     => '127.0.0.1',
    'user'     => 'root',
    'password' => '',
    'dbname'   => 'cemetery2_db',
    'port'     => 3306,
    'env'      => 'local',
];

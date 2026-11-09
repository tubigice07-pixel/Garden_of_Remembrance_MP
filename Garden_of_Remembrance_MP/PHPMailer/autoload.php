<?php
$phpmailer_autoload_files = [
    __DIR__ . '/Exception.php',
    __DIR__ . '/PHPMailer.php',
    __DIR__ . '/SMTP.php',
    __DIR__ . '/POP3.php',
    __DIR__ . '/OAuthTokenProvider.php',
    __DIR__ . '/OAuth.php',
    __DIR__ . '/DSNConfigurator.php',
];
foreach ($phpmailer_autoload_files as $f) {
    if (file_exists($f)) {
        require_once $f;
    }
}

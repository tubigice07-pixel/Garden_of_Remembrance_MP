<?php

$envValue = function($keys, $default = null) {
    foreach ((array) $keys as $key) {
        $value = getenv($key);
        if ($value !== false && trim((string) $value) !== '') {
            return $value;
        }
    }
    return $default;
};

$smtp_host = $envValue(['SMTP_HOST', 'MAIL_HOST'], 'smtp.gmail.com');
$smtp_port = $envValue(['SMTP_PORT', 'MAIL_PORT'], '587');
$smtp_user = $envValue(['SMTP_USERNAME', 'MAIL_USERNAME'], 'jaspersevillano102@gmail.com');
$smtp_pass = $envValue(['SMTP_PASSWORD', 'MAIL_PASSWORD'], 'pnuu pnhz bgje hjrm');
$smtp_secure = $envValue(['SMTP_SECURE', 'MAIL_ENCRYPTION'], 'tls');
$from_email = $envValue(['MAIL_FROM', 'SMTP_FROM_EMAIL'], ($smtp_user ?: 'jaspersevillano102@gmail.com'));
$from_name = $envValue(['MAIL_FROM_NAME', 'SMTP_FROM_NAME'], 'Garden of Remembrance Memorial Park');

$enabled = !empty($smtp_user) && !empty($smtp_pass);

$cfg = [
    'from_email' => $from_email,
    'from_name' => $from_name,
    'smtp' => [
        'enabled' => $enabled,
        'host' => $smtp_host,
        'port' => $smtp_port ?: 587,
        'username' => $smtp_user,
        'password' => $smtp_pass,
        'secure' => $smtp_secure ?: 'tls',
        'debug' => false,
    ],
];

$custom_settings_file = null;
if (file_exists(__DIR__ . '/mail_credentials.php')) {
    $custom_settings_file = __DIR__ . '/mail_credentials.php';
} elseif (file_exists(__DIR__ . '/mail_config.php')) {
    $custom_settings_file = __DIR__ . '/mail_config.php';
}

if ($custom_settings_file) {
    $custom = include $custom_settings_file;
    if (is_array($custom)) {
        if (!empty($custom['from_email'])) {
            $cfg['from_email'] = $custom['from_email'];
        }
        if (!empty($custom['from_name'])) {
            $cfg['from_name'] = $custom['from_name'];
        }
        if (!empty($custom['smtp']) && is_array($custom['smtp'])) {
            $cfg['smtp'] = array_merge($cfg['smtp'], $custom['smtp']);
            if (!empty($custom['smtp']['username']) && !empty($custom['smtp']['password'])) {
                $cfg['smtp']['enabled'] = true;
            }
        }
    }
}

return $cfg;

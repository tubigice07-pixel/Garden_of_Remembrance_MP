<?php
session_start();

if (!isset($_SESSION['role']) || strtoupper(trim((string)$_SESSION['role'])) !== 'ADMIN') {
    header('Location: ../admin_login.php');
    exit;
}
$configPath = __DIR__ . '/../config/mail_config.php';
$backupDir = __DIR__ . '/../config/backups';
if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

$config = [];
if (file_exists($configPath)) $config = include $configPath;

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $from_email = trim($_POST['from_email'] ?? $config['from_email'] ?? 'noreply@localhost');
    $from_name = trim($_POST['from_name'] ?? $config['from_name'] ?? 'Garden of Remembrance');
    $smtp_enabled = isset($_POST['smtp_enabled']) ? true : false;
    $smtp_host = trim($_POST['smtp_host'] ?? ($config['smtp']['host'] ?? ''));
    $smtp_user = trim($_POST['smtp_user'] ?? ($config['smtp']['username'] ?? ''));
    $smtp_pass = trim($_POST['smtp_pass'] ?? ($config['smtp']['password'] ?? ''));
    $smtp_port = intval($_POST['smtp_port'] ?? ($config['smtp']['port'] ?? 587));
    $smtp_secure = trim($_POST['smtp_secure'] ?? ($config['smtp']['secure'] ?? 'tls'));

    
    if (file_exists($configPath)) {
        copy($configPath, $backupDir . '/mail_config_' . date('Ymd_His') . '.php.backup');
    }

    $newConfig = [
        'from_email' => $from_email,
        'from_name' => $from_name,
        'smtp' => [
            'enabled' => $smtp_enabled,
            'host' => $smtp_host,
            'username' => $smtp_user,
            'password' => $smtp_pass,
            'secure' => $smtp_secure,
            'port' => $smtp_port,
        ],
    ];

    $php = "<?php\nreturn " . var_export($newConfig, true) . ";\n";
    if (file_put_contents($configPath, $php) !== false) {
        $message = '<div class="alert alert-success">Saved successfully.</div>';
        $config = $newConfig;
    } else {
        $message = '<div class="alert alert-danger">Failed to save configuration. Check file permissions.</div>';
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Mail Settings</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
    <h3>Mail Configuration</h3>
    <?php echo $message; ?>
    <form method="post">
        <div class="mb-3">
            <label class="form-label" for="from_email">From Email</label>
            <input class="form-control" id="from_email" name="from_email" value="<?php echo htmlspecialchars($config['from_email'] ?? 'noreply@localhost'); ?>" autocomplete="email">
        </div>
        <div class="mb-3">
            <label class="form-label" for="from_name">From Name</label>
            <input class="form-control" id="from_name" name="from_name" value="<?php echo htmlspecialchars($config['from_name'] ?? 'Garden of Remembrance'); ?>" autocomplete="name">
        </div>
        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="smtp_enabled" name="smtp_enabled" <?php echo (!empty($config['smtp']['enabled']) ? 'checked' : ''); ?>>
            <label class="form-check-label" for="smtp_enabled">Enable SMTP</label>
        </div>
        <div class="mb-3">
            <label class="form-label" for="smtp_host">SMTP Host</label>
            <input class="form-control" id="smtp_host" name="smtp_host" value="<?php echo htmlspecialchars($config['smtp']['host'] ?? ''); ?>" autocomplete="off">
        </div>
        <div class="mb-3">
            <label class="form-label" for="smtp_user">SMTP Username</label>
            <input class="form-control" id="smtp_user" name="smtp_user" value="<?php echo htmlspecialchars($config['smtp']['username'] ?? ''); ?>" autocomplete="username">
        </div>
        <div class="mb-3">
            <label class="form-label" for="smtp_pass">SMTP Password</label>
            <input class="form-control" id="smtp_pass" name="smtp_pass" value="<?php echo htmlspecialchars($config['smtp']['password'] ?? ''); ?>" autocomplete="current-password">
        </div>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label" for="smtp_port">SMTP Port</label>
                <input class="form-control" id="smtp_port" name="smtp_port" value="<?php echo htmlspecialchars($config['smtp']['port'] ?? 587); ?>" inputmode="numeric" autocomplete="off">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label" for="smtp_secure">Secure (tls/ssl)</label>
                <input class="form-control" id="smtp_secure" name="smtp_secure" value="<?php echo htmlspecialchars($config['smtp']['secure'] ?? 'tls'); ?>" autocomplete="off">
            </div>
        </div>
        <button class="btn btn-primary">Save</button>
    </form>
</div>
</body>
</html>
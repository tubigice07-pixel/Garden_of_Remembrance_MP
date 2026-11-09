<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/connection.php';

$message = '';
$step = 'email'; 

try {
    if (function_exists('is_db_connected') && is_db_connected()) {
        $requiredCols = [
            'reset_otp' => "ALTER TABLE tbl_user ADD COLUMN reset_otp VARCHAR(10) DEFAULT NULL",
            'reset_otp_expiry' => "ALTER TABLE tbl_user ADD COLUMN reset_otp_expiry DATETIME DEFAULT NULL"
        ];
        $existingCols = [];
        $colQuery = @$conn->query("SHOW COLUMNS FROM tbl_user");
        if ($colQuery) {
            while ($cRow = $colQuery->fetch_assoc()) {
                $existingCols[] = strtolower($cRow['Field']);
            }
        }
        foreach ($requiredCols as $col => $alterSql) {
            if (!in_array(strtolower($col), $existingCols, true)) {
                @$conn->query($alterSql);
            }
        }
    }
} catch (Throwable $e) {
    error_log('Notice: Admin column check exception: ' . $e->getMessage());
}

$email = isset($_GET['email']) ? strtolower(trim($_GET['email'])) : (isset($_POST['email']) ? strtolower(trim($_POST['email'])) : '');
if (!empty($_SESSION['admin_reset_verified'][$email])) {
    $step = 'new_password';
} elseif (!empty($email)) {
    $step = 'otp';
}

$lastSent = isset($_SESSION['admin_reset_otp_last_sent'][$email]) ? $_SESSION['admin_reset_otp_last_sent'][$email] : 0;
$timeUntilResend = max(0, 60 - (time() - $lastSent));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_otp'])) {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Please provide a valid administrator email address.</div>';
        $step = 'email';
    } elseif (!function_exists('is_db_connected') || !is_db_connected()) {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-triangle me-1"></i> Database connection unavailable. Please try again shortly.</div>';
        $step = 'email';
    } else {
        try {
            $stmt = $conn ? $conn->prepare("SELECT user_id, role, first_name FROM tbl_user WHERE LOWER(TRIM(email)) = ? LIMIT 1") : null;
            $user = null;
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }

            if (!$user || strtoupper(trim((string)$user['role'])) !== 'ADMIN') {
                $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> No administrator account found with that email address.</div>';
                $step = 'email';
            } else {
                $reset_otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $reset_exp = date('Y-m-d H:i:s', time() + 600);

                $_SESSION['admin_reset_otp'][$email] = $reset_otp;
                $_SESSION['admin_reset_otp_expiry'][$email] = time() + 600;

                if ($conn) {
                    $update = $conn->prepare("UPDATE tbl_user SET reset_otp = ?, reset_otp_expiry = ? WHERE LOWER(TRIM(email)) = ? LIMIT 1");
                    if ($update) {
                        $update->bind_param('sss', $reset_otp, $reset_exp, $email);
                        $update->execute();
                        $update->close();
                    }
                }

                $adminName = trim($user['first_name'] ?? 'Administrator');
                $mail_sent = false;
                try {
                    if (file_exists(__DIR__ . '/email_template.php')) {
                        include_once __DIR__ . '/email_template.php';
                    }
                    if (file_exists(__DIR__ . '/mailer.php')) {
                        include_once __DIR__ . '/mailer.php';
                    }
                    if (function_exists('send_password_reset_otp_email')) {
                        $mail_sent = send_password_reset_otp_email($email, $adminName, $reset_otp, true);
                    } elseif (function_exists('render_branded_email') && function_exists('send_mail')) {
                        $subject = "Admin Password Reset Code - Garden of Remembrance";
                        $html = render_branded_email([
                            'title' => 'Administrator Password Reset',
                            'badge' => 'Staff Security',
                            'greeting_name' => $adminName,
                            'intro_lines' => [
                                'We received a request to reset the password for your Garden of Remembrance administrator account.',
                                'Please enter the 6-digit verification code below to authorize the password change:'
                            ],
                            'otp_code' => $reset_otp,
                            'otp_expiry_minutes' => 10,
                            'outro_lines' => [
                                'This verification code is strictly confidential and will expire in 10 minutes.',
                                'If you did not request this, please contact technical support immediately.'
                            ]
                        ]);
                        $mail_sent = send_mail($email, $subject, $html, true);
                    } elseif (function_exists('send_mail')) {
                        $subject = "Admin Password Reset Code - Garden of Remembrance";
                        $body = "Dear {$adminName},\n\nYour administrator password reset verification code is: $reset_otp\n\nThis code will expire in 10 minutes.\n\nGarden of Remembrance Memorial Park";
                        $mail_sent = send_mail($email, $subject, $body, false);
                    }
                } catch (Throwable $mEx) {
                    error_log('Admin mail error: ' . $mEx->getMessage());
                }

                $_SESSION['admin_reset_otp_last_sent'][$email] = time();
                $lastSent = time();
                $timeUntilResend = 60;
                $step = 'otp';

                if ($mail_sent) {
                    $message = '<div class="alert alert-success shadow-sm"><i class="fas fa-check-circle me-1"></i> A 6-digit verification code has been dispatched to your administrator email.</div>';
                } else {
                    $message = '<div class="alert alert-warning shadow-sm"><i class="fas fa-exclamation-triangle me-1"></i> Notice: Email gateway notice. Your administrator verification code is: <strong>' . htmlspecialchars($reset_otp) . '</strong>. Please enter it below.</div>';
                }
            }
        } catch (Throwable $aEx) {
            error_log('Admin reset request error: ' . $aEx->getMessage());
            $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> An error occurred processing your request. Please try again.</div>';
            $step = 'email';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_otp'])) {
    if (!empty($_SESSION['admin_reset_otp_last_sent'][$email]) && time() - $_SESSION['admin_reset_otp_last_sent'][$email] < 60) {
        $wait = 60 - (time() - $_SESSION['admin_reset_otp_last_sent'][$email]);
        $message = '<div class="alert alert-info shadow-sm"><i class="fas fa-clock me-1"></i> Please wait ' . $wait . ' seconds before resending code.</div>';
        $step = 'otp';
    } else {
        $reset_otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $reset_exp = date('Y-m-d H:i:s', time() + 600);

        $_SESSION['admin_reset_otp'][$email] = $reset_otp;
        $_SESSION['admin_reset_otp_expiry'][$email] = time() + 600;

        if ($conn) {
            $update = $conn->prepare("UPDATE tbl_user SET reset_otp = ?, reset_otp_expiry = ? WHERE LOWER(TRIM(email)) = ? AND role = 'ADMIN' LIMIT 1");
            if ($update) {
                $update->bind_param('sss', $reset_otp, $reset_exp, $email);
                $update->execute();
                $update->close();
            }
        }

        $adminName = 'Administrator';
        if ($conn) {
            $uCheck = $conn->prepare("SELECT first_name FROM tbl_user WHERE LOWER(TRIM(email)) = ? AND role = 'ADMIN' LIMIT 1");
            if ($uCheck) {
                $uCheck->bind_param('s', $email);
                $uCheck->execute();
                if ($uRow = $uCheck->get_result()->fetch_assoc()) {
                    $adminName = trim($uRow['first_name'] ?? 'Administrator');
                }
                $uCheck->close();
            }
        }

        $mail_sent = false;
        try {
            if (file_exists(__DIR__ . '/email_template.php')) {
                include_once __DIR__ . '/email_template.php';
            }
            if (file_exists(__DIR__ . '/mailer.php')) {
                include_once __DIR__ . '/mailer.php';
            }
            if (function_exists('send_password_reset_otp_email')) {
                $mail_sent = send_password_reset_otp_email($email, $adminName, $reset_otp, true);
            } elseif (function_exists('render_branded_email') && function_exists('send_mail')) {
                $subject = "Admin Password Reset Code - Garden of Remembrance";
                $html = render_branded_email([
                    'title' => 'Administrator Password Reset',
                    'badge' => 'Staff Security',
                    'greeting_name' => $adminName,
                    'intro_lines' => [
                        'A new verification code was generated for your Garden of Remembrance administrator account.',
                        'Please enter the 6-digit verification code below to authorize your password change:'
                    ],
                    'otp_code' => $reset_otp,
                    'otp_expiry_minutes' => 10,
                    'outro_lines' => [
                        'This verification code is strictly confidential and will expire in 10 minutes.'
                    ]
                ]);
                $mail_sent = send_mail($email, $subject, $html, true);
            } elseif (function_exists('send_mail')) {
                $subject = "Admin Password Reset Code - Garden of Remembrance";
                $body = "Your administrator password reset verification code is: $reset_otp\n\nThis code will expire in 10 minutes.";
                $mail_sent = send_mail($email, $subject, $body, false);
            }
        } catch (Throwable $mEx) {
            error_log('Admin mail error: ' . $mEx->getMessage());
        }

        $_SESSION['admin_reset_otp_last_sent'][$email] = time();
        $lastSent = time();
        $timeUntilResend = 60;
        $step = 'otp';

        if ($mail_sent) {
            $message = '<div class="alert alert-success shadow-sm"><i class="fas fa-check-circle me-1"></i> A fresh verification code has been dispatched. Please check your inbox.</div>';
        } else {
            $message = '<div class="alert alert-warning shadow-sm"><i class="fas fa-exclamation-triangle me-1"></i> Fresh code generated. Your administrator verification code is: <strong>' . htmlspecialchars($reset_otp) . '</strong>.</div>';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $otp = trim($_POST['otp'] ?? '');
    if (empty($otp)) {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Please enter the 6-digit code.</div>';
        $step = 'otp';
    } else {
        $verified = false;

        if (!empty($_SESSION['admin_reset_otp'][$email]) 
            && hash_equals((string)$_SESSION['admin_reset_otp'][$email], (string)$otp)
            && (!empty($_SESSION['admin_reset_otp_expiry'][$email]) && $_SESSION['admin_reset_otp_expiry'][$email] >= time())) {
            $verified = true;
        }

        if (!$verified && $conn) {
            try {
                $stmt = $conn->prepare("SELECT reset_otp, reset_otp_expiry FROM tbl_user WHERE LOWER(TRIM(email)) = ? AND role = 'ADMIN' LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $email);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($row && !empty($row['reset_otp']) && hash_equals((string)$row['reset_otp'], (string)$otp) && strtotime($row['reset_otp_expiry']) >= time()) {
                        $verified = true;
                    }
                }
            } catch (Throwable $vEx) {
                error_log('Admin verify error: ' . $vEx->getMessage());
            }
        }

        if ($verified) {
            $_SESSION['admin_reset_verified'][$email] = true;
            $step = 'new_password';
            $message = '<div class="alert alert-success shadow-sm"><i class="fas fa-check-circle me-1"></i> Code verified. Please set your new administrator password.</div>';
        } else {
            $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-times-circle me-1"></i> Invalid or expired verification code.</div>';
            $step = 'otp';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    if (empty($_SESSION['admin_reset_verified'][$email])) {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-lock me-1"></i> Authorization expired. Please restart the reset process.</div>';
        $step = 'email';
    } else {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        if ($new_password !== $confirm_password) {
            $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Passwords do not match.</div>';
            $step = 'new_password';
        } elseif (strlen($new_password) < 8) {
            $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Password must be at least 8 characters.</div>';
            $step = 'new_password';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $updated = false;

            if ($conn) {
                try {
                    $u = $conn->prepare("UPDATE tbl_user SET password = ? WHERE LOWER(TRIM(email)) = ? AND role = 'ADMIN' LIMIT 1");
                    if ($u) {
                        $u->bind_param('ss', $hashed, $email);
                        $updated = $u->execute();
                        $u->close();
                    }

                    @$conn->query("UPDATE tbl_user SET reset_otp = NULL, reset_otp_expiry = NULL WHERE LOWER(TRIM(email)) = '" . $conn->real_escape_string($email) . "' AND role = 'ADMIN'");
                } catch (Throwable $upEx) {
                    error_log('Admin password update error: ' . $upEx->getMessage());
                }
            }

            if ($updated) {
                unset($_SESSION['admin_reset_verified'][$email]);
                unset($_SESSION['admin_reset_otp'][$email]);
                unset($_SESSION['admin_reset_otp_expiry'][$email]);
                header('Location: admin_login.php?password_reset=1');
                exit;
            } else {
                $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-times-circle me-1"></i> Failed to update password. Please try again.</div>';
                $step = 'new_password';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Password Recovery - Garden of Remembrance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bs-primary: #7b1113;
            --bs-primary-hover: #921719;
            --stone: #f5efe8;
            --ink: #2b211f;
            --accent: #8c5b2d;
        }
        html, body {
            height: 100%;
        }
        body {
            margin: 0;
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background: url('garden of remembrance background.webp') center/cover no-repeat fixed;
            min-height: 100vh;
            color: var(--ink);
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, rgba(7,15,30,0.72), rgba(17,23,38,0.48));
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 0;
        }
        .page-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
            z-index: 1;
        }
        .auth-card {
            width: min(520px, 100%);
            background: rgba(255, 250, 245, 0.96);
            border-radius: 1.5rem;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0,0,0,0.28);
            border: 1px solid rgba(255,255,255,0.5);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
        .auth-header {
            background: linear-gradient(145deg, rgba(123,17,19,0.96), rgba(71,13,15,0.92));
            color: #ffffff;
            padding: 2.2rem 2rem 1.8rem;
            text-align: center;
        }
        .brand-logo {
            max-width: 130px;
            height: auto;
            display: block;
            margin: 0 auto 1rem;
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.25));
        }
        .auth-header h2 {
            font-size: 1.4rem;
            font-weight: 700;
            margin: 0 0 0.35rem;
        }
        .auth-header p {
            font-size: 0.88rem;
            color: rgba(255,255,255,0.88);
            margin: 0;
        }
        .auth-body {
            padding: 2rem 2.2rem 2.2rem;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.88rem;
            color: #475569;
            margin-bottom: 0.4rem;
        }
        .input-group-text {
            background-color: #f8fafc;
            border-color: rgba(140,91,45,0.25);
            color: #64748b;
            border-top-left-radius: 0.75rem;
            border-bottom-left-radius: 0.75rem;
        }
        .form-control {
            border-radius: 0.75rem;
            border-color: rgba(140,91,45,0.25);
            padding: 0.75rem 0.95rem;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        .input-group .form-control {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        .form-control:focus {
            border-color: var(--bs-primary);
            box-shadow: 0 0 0 0.22rem rgba(123, 17, 19, 0.15);
        }
        .btn-burgundy {
            background-color: var(--bs-primary);
            color: #ffffff;
            border: none;
            border-radius: 0.75rem;
            padding: 0.8rem 1.2rem;
            font-weight: 700;
            font-size: 0.98rem;
            width: 100%;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(123, 17, 19, 0.25);
        }
        .btn-burgundy:hover {
            background-color: var(--bs-primary-hover);
            color: #ffffff;
            transform: translateY(-1px);
        }
        .btn-outline-burgundy {
            background: #ffffff;
            color: var(--bs-primary);
            border: 1.5px solid var(--bs-primary);
            border-radius: 0.75rem;
            padding: 0.75rem 1.2rem;
            font-weight: 700;
            font-size: 0.92rem;
            width: 100%;
            transition: all 0.25s ease;
            cursor: pointer;
        }
        .btn-outline-burgundy:hover:not(:disabled) {
            background: var(--bs-primary);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(123, 17, 19, 0.2);
        }
        .btn-outline-burgundy:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            background-color: #f1f5f9;
            color: #64748b;
            border-color: #cbd5e1;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: #64748b;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .back-link:hover {
            color: var(--bs-primary);
        }
        .otp-input {
            letter-spacing: 0.35em;
            font-size: 1.4rem;
            font-weight: 700;
            text-align: center;
            color: var(--bs-primary);
        }
        .show-pass-toggle {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.3rem;
            margin-bottom: 1.2rem;
            font-size: 0.88rem;
            color: #475569;
            cursor: pointer;
            user-select: none;
        }
        .show-pass-toggle input {
            accent-color: var(--bs-primary);
            width: 1rem;
            height: 1rem;
        }

        /* Hide browser-native password reveal eye button since 'Show password' checkbox is provided */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear,
        input[type="password"]::-webkit-reveal,
        input[type="password"]::-webkit-contacts-auto-fill-button,
        input[type="password"]::-webkit-credentials-auto-fill-button {
            display: none !important;
            visibility: hidden !important;
            pointer-events: none !important;
            width: 0 !important;
            height: 0 !important;
            -webkit-appearance: none !important;
            appearance: none !important;
        }
        ::-ms-reveal {
            display: none !important;
        }
        ::-ms-clear {
            display: none !important;
        }
        .badge-admin {
            background: rgba(255,255,255,0.18);
            border-radius: 999px;
            padding: 0.35rem 0.8rem;
            font-size: 0.76rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            display: inline-block;
            margin-bottom: 0.6rem;
        }

        /* Password Strength Checker Styles */
        .password-strength-container {
            margin-top: 0.65rem;
            margin-bottom: 0.85rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.65rem;
            padding: 0.75rem 0.9rem;
        }
        .strength-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.78rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
        }
        .strength-label {
            color: #64748b;
        }
        .strength-badge {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.18rem 0.55rem;
            border-radius: 999px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.25s ease;
        }
        .strength-badge.empty {
            background: #e2e8f0;
            color: #64748b;
        }
        .strength-badge.weak {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        .strength-badge.medium {
            background: #fef3c7;
            color: #d97706;
            border: 1px solid #fde68a;
        }
        .strength-badge.strong {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }
        .strength-bar-track {
            display: flex;
            gap: 5px;
            height: 6px;
            background: transparent;
            margin-bottom: 0.65rem;
        }
        .strength-segment {
            flex: 1;
            height: 100%;
            background: #e2e8f0;
            border-radius: 999px;
            transition: background-color 0.3s ease;
        }
        .strength-segment.active-weak {
            background-color: #ef4444;
        }
        .strength-segment.active-medium {
            background-color: #f59e0b;
        }
        .strength-segment.active-strong {
            background-color: #10b981;
        }
        .password-criteria {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.35rem 0.5rem;
            font-size: 0.74rem;
            color: #64748b;
        }
        @media (max-width: 480px) {
            .password-criteria {
                grid-template-columns: 1fr;
            }
        }
        .criteria-item {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            transition: color 0.2s ease;
        }
        .criteria-item i {
            font-size: 0.65rem;
            color: #cbd5e1;
            transition: all 0.2s ease;
        }
        .criteria-item.met {
            color: #15803d;
            font-weight: 600;
        }
        .criteria-item.met i {
            color: #16a34a;
        }
        .password-match-indicator {
            font-size: 0.78rem;
            font-weight: 600;
            margin-top: 0.35rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            min-height: 1.2rem;
        }
        .password-match-indicator.matching {
            color: #16a34a;
        }
        .password-match-indicator.mismatch {
            color: #dc2626;
        }
    </style>
</head>
<body>
<div class="page-shell">
    <div class="auth-card">
        <div class="auth-header">
            <img src="cmis_logo.png" alt="Garden of Remembrance Logo" class="brand-logo">
            <div class="badge-admin"><i class="fas fa-shield-alt me-1"></i> Staff Portal</div>
            <h2>Admin Password Recovery</h2>
            <p>Securely reset your administrator credentials</p>
        </div>
        <div class="auth-body">
            <?php if (!empty($message)) echo $message; ?>

            <?php if ($step === 'email'): ?>
                <form method="POST" action="">
                    <div class="mb-4">
                        <label for="emailInput" class="form-label">Administrator Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                            <input type="email" id="emailInput" name="email" class="form-control" placeholder="admin@example.com" value="<?php echo htmlspecialchars($email); ?>" required autofocus>
                        </div>
                    </div>
                    <button type="submit" name="request_otp" class="btn btn-burgundy mb-3">
                        <i class="fas fa-paper-plane me-1"></i> Send Verification Code
                    </button>
                </form>

            <?php elseif ($step === 'otp'): ?>
                <div class="p-2 bg-light border rounded-3 text-center mb-3 text-truncate small fw-semibold text-muted">
                    <i class="fas fa-envelope me-1"></i> Code sent to: <span class="text-dark"><?php echo htmlspecialchars($email); ?></span>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <?php if (!empty($_SESSION['admin_reset_otp'][$email])): ?>
                        <div class="alert alert-info py-2 px-3 small text-center mb-3 shadow-sm">
                            <i class="fas fa-shield-alt me-1 text-primary"></i> Verification code: <strong class="fs-6 font-monospace"><?php echo htmlspecialchars($_SESSION['admin_reset_otp'][$email]); ?></strong>. Click <strong>Verify Code</strong> to proceed.
                        </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="otp" class="form-label">6-Digit Verification Code</label>
                        <input type="text" id="otp" name="otp" class="form-control otp-input" value="<?php echo htmlspecialchars(!empty($_POST['otp']) ? $_POST['otp'] : (!empty($_SESSION['admin_reset_otp'][$email]) ? $_SESSION['admin_reset_otp'][$email] : '')); ?>" placeholder="••••••" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" required autofocus>
                    </div>
                    <button type="submit" name="verify_otp" class="btn btn-burgundy mb-2">
                        <i class="fas fa-shield-alt me-1"></i> Verify Code
                    </button>
                    <button type="submit" name="resend_otp" id="resendBtn" class="btn btn-outline-burgundy mt-2" formnovalidate disabled>
                        <i class="fas fa-clock me-1"></i> Resend Code in <span id="countdownSecs">60</span>s
                    </button>
                </form>

            <?php elseif ($step === 'new_password'): ?>
                <form method="POST" action="" id="adminResetPassForm">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Enter new password (min. 8 chars)" minlength="8" required autofocus autocomplete="new-password">
                        </div>

                        <div class="password-strength-container" id="strengthContainer">
                            <div class="strength-header">
                                <span class="strength-label">Password Strength:</span>
                                <span class="strength-badge empty" id="strengthBadge">
                                    <i class="fas fa-shield-alt"></i> <span id="strengthText">Not Entered</span>
                                </span>
                            </div>
                            <div class="strength-bar-track">
                                <div class="strength-segment" id="seg1"></div>
                                <div class="strength-segment" id="seg2"></div>
                                <div class="strength-segment" id="seg3"></div>
                            </div>
                            <ul class="password-criteria">
                                <li class="criteria-item" id="critLength">
                                    <i class="fas fa-circle"></i> <span>At least 8 characters</span>
                                </li>
                                <li class="criteria-item" id="critCase">
                                    <i class="fas fa-circle"></i> <span>Upper & lowercase</span>
                                </li>
                                <li class="criteria-item" id="critNumber">
                                    <i class="fas fa-circle"></i> <span>At least 1 number</span>
                                </li>
                                <li class="criteria-item" id="critSymbol">
                                    <i class="fas fa-circle"></i> <span>At least 1 symbol</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-enter new password" minlength="8" required autocomplete="new-password">
                        </div>
                        <div class="password-match-indicator" id="matchIndicator"></div>
                    </div>

                    <label class="show-pass-toggle" for="show_password_checkbox">
                        <input type="checkbox" id="show_password_checkbox">
                        <span>Show password</span>
                    </label>

                    <button type="submit" name="update_password" id="submitPassBtn" class="btn btn-burgundy mb-3">
                        <i class="fas fa-save me-1"></i> Save New Password
                    </button>
                </form>
            <?php endif; ?>

            <div class="text-center pt-2 border-top mt-4">
                <a href="admin_login.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Admin Login
                </a>
            </div>
        </div>
    </div>
</div>

<script>
    // Toggle password visibility
    document.getElementById('show_password_checkbox')?.addEventListener('change', function() {
        var newPassword = document.getElementById('new_password');
        var confirmPassword = document.getElementById('confirm_password');
        var show = this.checked;
        if (newPassword) newPassword.type = show ? 'text' : 'password';
        if (confirmPassword) confirmPassword.type = show ? 'text' : 'password';
    });

    // Password Strength & Match Engine
    (function() {
        var newPassInput = document.getElementById('new_password');
        var confirmPassInput = document.getElementById('confirm_password');
        if (!newPassInput) return;

        var seg1 = document.getElementById('seg1');
        var seg2 = document.getElementById('seg2');
        var seg3 = document.getElementById('seg3');
        var badge = document.getElementById('strengthBadge');
        var badgeText = document.getElementById('strengthText');
        var matchIndicator = document.getElementById('matchIndicator');

        var critLength = document.getElementById('critLength');
        var critCase = document.getElementById('critCase');
        var critNumber = document.getElementById('critNumber');
        var critSymbol = document.getElementById('critSymbol');

        function updateCriteria(el, isMet) {
            if (!el) return;
            var icon = el.querySelector('i');
            if (isMet) {
                el.classList.add('met');
                if (icon) {
                    icon.className = 'fas fa-check-circle';
                }
            } else {
                el.classList.remove('met');
                if (icon) {
                    icon.className = 'fas fa-circle';
                }
            }
        }

        function evaluateStrength(pwd) {
            if (!pwd || pwd.length === 0) {
                updateCriteria(critLength, false);
                updateCriteria(critCase, false);
                updateCriteria(critNumber, false);
                updateCriteria(critSymbol, false);

                seg1.className = 'strength-segment';
                seg2.className = 'strength-segment';
                seg3.className = 'strength-segment';

                badge.className = 'strength-badge empty';
                badge.innerHTML = '<i class="fas fa-shield-alt"></i> <span id="strengthText">Not Entered</span>';
                return 'empty';
            }

            var hasLength = pwd.length >= 8;
            var hasUpper = /[A-Z]/.test(pwd);
            var hasLower = /[a-z]/.test(pwd);
            var hasCase = hasUpper && hasLower;
            var hasNumber = /[0-9]/.test(pwd);
            var hasSymbol = /[^A-Za-z0-9]/.test(pwd);

            updateCriteria(critLength, hasLength);
            updateCriteria(critCase, hasCase);
            updateCriteria(critNumber, hasNumber);
            updateCriteria(critSymbol, hasSymbol);

            var score = 0;
            if (hasLength) score++;
            if (hasCase) score++;
            if (hasNumber) score++;
            if (hasSymbol) score++;
            if (pwd.length >= 12) score++;

            // Reset segments
            seg1.className = 'strength-segment';
            seg2.className = 'strength-segment';
            seg3.className = 'strength-segment';

            // Weak: length < 8, or very low variety
            if (pwd.length < 8 || score <= 2) {
                seg1.classList.add('active-weak');
                badge.className = 'strength-badge weak';
                badge.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <span id="strengthText">Weak</span>';
                return 'weak';
            }
            // Mid / Medium: length >= 8 and at least 3 criteria satisfied
            else if (score === 3 || score === 4) {
                seg1.classList.add('active-medium');
                seg2.classList.add('active-medium');
                badge.className = 'strength-badge medium';
                badge.innerHTML = '<i class="fas fa-check-circle"></i> <span id="strengthText">Medium</span>';
                return 'medium';
            }
            // Strong: length >= 8, all criteria met (uppercase, lowercase, number, symbol)
            else {
                seg1.classList.add('active-strong');
                seg2.classList.add('active-strong');
                seg3.classList.add('active-strong');
                badge.className = 'strength-badge strong';
                badge.innerHTML = '<i class="fas fa-shield-alt"></i> <span id="strengthText">Strong</span>';
                return 'strong';
            }
        }

        function evaluateMatch() {
            if (!confirmPassInput || !matchIndicator) return;
            var p1 = newPassInput.value;
            var p2 = confirmPassInput.value;

            if (!p2 || p2.length === 0) {
                matchIndicator.innerHTML = '';
                matchIndicator.className = 'password-match-indicator';
                return;
            }

            if (p1 === p2) {
                matchIndicator.className = 'password-match-indicator matching';
                matchIndicator.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match';
            } else {
                matchIndicator.className = 'password-match-indicator mismatch';
                matchIndicator.innerHTML = '<i class="fas fa-times-circle"></i> Passwords do not match';
            }
        }

        newPassInput.addEventListener('input', function() {
            evaluateStrength(this.value);
            evaluateMatch();
        });

        if (confirmPassInput) {
            confirmPassInput.addEventListener('input', evaluateMatch);
        }
    })();

    (function() {
        var seconds = <?php echo json_encode($timeUntilResend > 0 ? $timeUntilResend : 60); ?>;
        var resendBtn = document.getElementById('resendBtn');
        if (!resendBtn) return;

        function updateTimer() {
            if (seconds > 0) {
                resendBtn.disabled = true;
                resendBtn.innerHTML = '<i class="fas fa-clock me-1"></i> Resend Code in ' + seconds + 's';
            } else {
                resendBtn.disabled = false;
                resendBtn.innerHTML = '<i class="fas fa-redo-alt me-1"></i> Resend Verification Code';
            }
        }

        updateTimer();
        if (seconds > 0) {
            var intervalId = setInterval(function() {
                seconds -= 1;
                updateTimer();
                if (seconds <= 0) {
                    clearInterval(intervalId);
                }
            }, 1000);
        }
    })();
</script>
</body>
</html>

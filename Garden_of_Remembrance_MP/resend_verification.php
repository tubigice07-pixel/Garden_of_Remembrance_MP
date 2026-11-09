<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/connection.php';
if (file_exists(__DIR__ . '/mailer.php')) {
    include_once __DIR__ . '/mailer.php';
}

if (!function_exists('get_app_base_url')) {
    function get_app_base_url(): string {
        $scheme = 'http';
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) {
            $scheme = 'https';
        } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = $_SERVER['REQUEST_SCHEME'];
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['PHP_SELF'] ?? '/');
        $path = rtrim($path, '/\\');
        return $scheme . '://' . $host . ($path === '' ? '' : $path);
    }
}

try {
    if (function_exists('is_db_connected') && is_db_connected()) {
        $reqCols = [
            'verification_token' => "ALTER TABLE tbl_user ADD COLUMN verification_token VARCHAR(255) DEFAULT NULL",
            'verification_expires' => "ALTER TABLE tbl_user ADD COLUMN verification_expires DATETIME DEFAULT NULL",
            'email_verified' => "ALTER TABLE tbl_user ADD COLUMN email_verified TINYINT(1) DEFAULT 0"
        ];
        $existingCols = [];
        $colQuery = @$conn->query("SHOW COLUMNS FROM tbl_user");
        if ($colQuery) {
            while ($cRow = $colQuery->fetch_assoc()) {
                $existingCols[] = strtolower($cRow['Field']);
            }
        }
        foreach ($reqCols as $c => $alterSql) {
            if (!in_array(strtolower($c), $existingCols, true)) {
                @$conn->query($alterSql);
            }
        }
    }
} catch (Throwable $colEx) {
    error_log('Verification schema check notice: ' . $colEx->getMessage());
}

$message = '';
$email = isset($_GET['email']) ? strtolower(trim($_GET['email'])) : (isset($_POST['email']) ? strtolower(trim($_POST['email'])) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert alert-danger text-center shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Please enter a valid email address.</div>';
    } elseif (!function_exists('is_db_connected') || !is_db_connected()) {
        $message = '<div class="alert alert-danger text-center shadow-sm"><i class="fas fa-exclamation-triangle me-1"></i> Database service is temporarily unavailable. Please try again in a few moments.</div>';
    } else {
        try {
            $stmt = $conn ? $conn->prepare("SELECT user_id, role, first_name, last_name, email, email_verified FROM tbl_user WHERE LOWER(TRIM(email)) = ? LIMIT 1") : null;
            $user = null;
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $res = $stmt->get_result();
                $user = $res ? $res->fetch_assoc() : null;
                $stmt->close();
            }

            if (!$user) {
                $message = '<div class="alert alert-danger text-center shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> No account found registered under that email address.</div>';
            } else {
                $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
                $isAdmin = ($role === 'ADMIN');
                $loginLink = $isAdmin ? 'admin_login.php' : 'login.php';
                $roleBadge = $isAdmin ? '<span class="badge bg-danger ms-1">Admin Account</span>' : '';

                if (isset($user['email_verified']) && intval($user['email_verified']) === 1) {
                    $message = '<div class="alert alert-info text-center shadow-sm">'
                        . '<i class="fas fa-check-circle me-1"></i> This account ' . $roleBadge . ' is already verified.'
                        . '<br><a href="' . $loginLink . '" class="btn btn-sm btn-burgundy mt-2">Proceed to ' . ($isAdmin ? 'Admin' : 'User') . ' Login</a>'
                        . '</div>';
                } else {
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', time() + 86400); 

                    $updateSuccess = false;
                    $update = $conn ? $conn->prepare("UPDATE tbl_user SET verification_token = ?, verification_expires = ? WHERE user_id = ? LIMIT 1") : null;
                    if ($update) {
                        $update->bind_param('ssi', $token, $expires, $user['user_id']);
                        $updateSuccess = $update->execute();
                        $update->close();
                    }

                    $baseUrl = get_app_base_url();
                    $verifyUrl = $baseUrl . '/verify.php?token=' . urlencode($token);
                    $displayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($isAdmin ? 'Administrator' : 'Account Owner');

                    $mail_sent = false;
                    $mail_error_detail = '';

                    try {
                        $subject = 'Verify your Garden of Remembrance ' . ($isAdmin ? 'Administrator ' : '') . 'Account';
                        $htmlBody = '';
                        $textBody = '';
                        if (function_exists('build_verification_email_html')) {
                            $htmlBody = build_verification_email_html($displayName, $verifyUrl);
                        } elseif (function_exists('render_branded_email')) {
                            $htmlBody = render_branded_email([
                                'title' => 'Account Email Verification',
                                'badge' => $isAdmin ? 'Admin Verification' : 'Account Activation',
                                'greeting_name' => $displayName,
                                'intro_lines' => [
                                    'Thank you for using Garden of Remembrance Memorial Park.',
                                    'Please click the button below to verify your email address and activate your account:'
                                ],
                                'action_button' => [
                                    'text' => 'Verify Email Address',
                                    'url' => $verifyUrl
                                ],
                                'outro_lines' => [
                                    'This verification link will expire in 24 hours.',
                                    'If you did not request this verification, you may disregard this email.'
                                ]
                            ]);
                        } else {
                            $htmlBody = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px;border:1px solid #e0d6cb;border-radius:12px;background:#ffffff;'>"
                                . "<h2 style='color:#7b1113;'>Account Email Verification</h2>"
                                . "<p>Hello <strong>" . htmlspecialchars($displayName) . "</strong>,</p>"
                                . "<p>Please verify your email address by clicking the link below:</p>"
                                . "<div style='margin:25px 0;'><a href='" . htmlspecialchars($verifyUrl) . "' style='background:#7b1113;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:8px;font-weight:bold;'>Verify Email Address</a></div>"
                                . "<p style='font-size:12px;color:#777;'>This link will expire in 24 hours.</p>"
                                . "</div>";
                        }
                        if (function_exists('send_mail')) {
                            $mail_sent = send_mail($email, $subject, $htmlBody, true);
                        } else {
                            $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: Garden of Remembrance <noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";
                            $mail_sent = @mail($email, $subject, $htmlBody, $headers);
                        }
                    } catch (Throwable $mEx) {
                        error_log('Resend verification mail error: ' . $mEx->getMessage());
                        $mail_error_detail = $mEx->getMessage();
                    }

                    if ($mail_sent) {
                        $message = '<div class="alert alert-success text-center shadow-sm">'
                            . '<i class="fas fa-check-circle me-1"></i> A fresh verification email has been dispatched to <strong>' . htmlspecialchars($email) . '</strong>.'
                            . '<br><span class="small text-muted">Please check your inbox or spam folder.</span>'
                            . '</div>';
                    } else {
                        $err = function_exists('get_mail_error') ? get_mail_error() : $mail_error_detail;
                        $message = '<div class="alert alert-warning text-center shadow-sm">'
                            . '<i class="fas fa-exclamation-triangle me-1"></i> <strong>Notice:</strong> The verification token was generated, but the email delivery encountered an issue.'
                            . '<br><span class="small">You can verify your account directly using this link:</span>'
                            . '<br><a href="' . htmlspecialchars($verifyUrl) . '" class="btn btn-sm btn-burgundy mt-2"><i class="fas fa-check me-1"></i> Verify Account Directly</a>'
                            . '</div>';
                    }
                }
            }
        } catch (Throwable $rEx) {
            error_log('Resend verification error: ' . $rEx->getMessage());
            $message = '<div class="alert alert-danger text-center shadow-sm"><i class="fas fa-times-circle me-1"></i> An unexpected error occurred. Please try again.</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resend Verification Email - Garden of Remembrance</title>
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
            position: relative;
        }
        .auth-header .brand-logo {
            max-width: 130px;
            height: auto;
            display: block;
            margin: 0 auto 1rem;
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.25));
        }
        .auth-header h2 {
            font-size: 1.45rem;
            font-weight: 700;
            margin: 0 0 0.35rem;
            letter-spacing: -0.01em;
        }
        .auth-header p {
            font-size: 0.88rem;
            color: rgba(255,255,255,0.88);
            margin: 0;
            line-height: 1.5;
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
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(123, 17, 19, 0.25);
        }
        .btn-burgundy:hover {
            background-color: var(--bs-primary-hover);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(123, 17, 19, 0.35);
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: #64748b;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: color 0.2s ease;
        }
        .back-link:hover {
            color: var(--bs-primary);
        }
    </style>
</head>
<body>
<div class="page-shell">
    <div class="auth-card">
        <div class="auth-header">
            <img src="cmis_logo.png" alt="Garden of Remembrance Logo" class="brand-logo">
            <h2>Resend Verification Email</h2>
            <p>Request an activation link for your User or Administrator account</p>
        </div>
        <div class="auth-body">
            <?php if (!empty($message)) echo $message; ?>

            <form method="post" action="">
                <div class="mb-4">
                    <label for="emailInput" class="form-label">Registered Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="email" id="emailInput" name="email" class="form-control" placeholder="user@example.com or admin@example.com" value="<?php echo htmlspecialchars($email); ?>" required autofocus>
                    </div>
                    <div class="form-text text-muted small mt-1">Works for both User and Administrator accounts.</div>
                </div>
                <button type="submit" class="btn btn-burgundy mb-3">
                    <i class="fas fa-paper-plane me-1"></i> Send Verification Link
                </button>
            </form>

            <div class="text-center pt-3 border-top mt-4 d-flex justify-content-center gap-3">
                <a href="login.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> User Login
                </a>
                <span class="text-muted">•</span>
                <a href="admin_login.php" class="back-link">
                    <i class="fas fa-user-shield"></i> Admin Login
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>

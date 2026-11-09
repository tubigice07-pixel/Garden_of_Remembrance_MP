<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/connection.php';

$message = "";

try {
    if (function_exists('is_db_connected') && is_db_connected()) {
        $requiredCols = [
            'reset_otp' => "ALTER TABLE tbl_user ADD COLUMN reset_otp VARCHAR(10) DEFAULT NULL",
            'reset_otp_expiry' => "ALTER TABLE tbl_user ADD COLUMN reset_otp_expiry DATETIME DEFAULT NULL",
            'reset_token' => "ALTER TABLE tbl_user ADD COLUMN reset_token VARCHAR(128) DEFAULT NULL",
            'reset_expires' => "ALTER TABLE tbl_user ADD COLUMN reset_expires DATETIME DEFAULT NULL"
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
    error_log('Notice: Column check exception: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "<div class='alert alert-danger text-center shadow-sm'><i class='fas fa-exclamation-circle me-1'></i> Please enter a valid email address.</div>";
    } elseif (!function_exists('is_db_connected') || !is_db_connected()) {
        $message = "<div class='alert alert-danger text-center shadow-sm'><i class='fas fa-exclamation-triangle me-1'></i> System database is temporarily unavailable. Please try again in a few moments.</div>";
    } else {
        try {
            $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email FROM tbl_user WHERE LOWER(TRIM(email)) = ? LIMIT 2");
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $res = $stmt->get_result();
                $users = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                $stmt->close();

                if (count($users) === 0) {
                    $message = "<div class='alert alert-danger text-center shadow-sm'><i class='fas fa-exclamation-circle me-1'></i> Email address not found in our records.</div>";
                } elseif (count($users) > 1) {
                    $message = "<div class='alert alert-danger text-center shadow-sm'><i class='fas fa-exclamation-triangle me-1'></i> Multiple accounts found with this email. Please contact memorial administration.</div>";
                } else {
                    $user = $users[0];
                    $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Account Owner';

                    $reset_token = bin2hex(random_bytes(32));
                    $reset_otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                    $reset_exp = date('Y-m-d H:i:s', time() + 3600); 

                    $updateStmt = $conn->prepare("UPDATE tbl_user SET reset_token = ?, reset_expires = ?, reset_otp = ?, reset_otp_expiry = ? WHERE user_id = ? LIMIT 1");
                    if ($updateStmt) {
                        $updateStmt->bind_param('ssssi', $reset_token, $reset_exp, $reset_otp, $reset_exp, $user['user_id']);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }

                    $mail_sent = false;
                    $mail_error_detail = '';

                    try {
                        if (file_exists(__DIR__ . '/email_template.php')) {
                            include_once __DIR__ . '/email_template.php';
                        }
                        if (file_exists(__DIR__ . '/mailer.php')) {
                            include_once __DIR__ . '/mailer.php';
                        }
                        $subject = "Password Reset Request - Garden of Remembrance";
                        if (function_exists('render_branded_email')) {
                            $bodyHtml = render_branded_email([
                                'title' => 'Password Reset Verification',
                                'badge' => 'Security Verification',
                                'greeting_name' => $userName,
                                'intro_lines' => [
                                    'We received a request to reset your password for your Garden of Remembrance Memorial Park account.',
                                    'Please use your 6-digit verification code below to authorize the password reset:'
                                ],
                                'otp_code' => $reset_otp,
                                'otp_expiry_minutes' => 60,
                                'outro_lines' => [
                                    'Enter this 6-digit verification code on the verification page to proceed with resetting your password.',
                                    'This verification code will expire in 1 hour.',
                                    'If you did not request this, please ignore this email or contact support.'
                                ]
                            ]);
                        } else {
                            $bodyHtml = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px;border:1px solid #e0d6cb;border-radius:12px;background:#ffffff;'>"
                                . "<h2 style='color:#7b1113;margin-top:0;'>Password Reset Verification Code</h2>"
                                . "<p>Hello <strong>" . htmlspecialchars($userName) . "</strong>,</p>"
                                . "<p>We received a request to reset your password for your Garden of Remembrance Memorial Park account.</p>"
                                . "<p style='color:#555;'>Your 6-digit verification code is:</p>"
                                . "<div style='background:#f5efe8;padding:14px;text-align:center;font-size:24px;letter-spacing:6px;font-weight:bold;color:#7b1113;border-radius:8px;margin:16px 0;'>"
                                . htmlspecialchars($reset_otp)
                                . "</div>"
                                . "<p style='font-size:13px;color:#777;'>Enter this 6-digit code on the reset page. This code will expire in 1 hour. If you did not request this, please ignore this email.</p>"
                                . "<hr style='border:none;border-top:1px solid #eee;margin:24px 0;'>"
                                . "<p style='font-size:12px;color:#999;text-align:center;'>Garden of Remembrance Memorial Park &copy; " . date('Y') . "</p>"
                                . "</div>";
                        }

                        if (function_exists('send_mail')) {
                            $mail_sent = send_mail($email, $subject, $bodyHtml, true);
                        } else {
                            $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: Garden of Remembrance <noreply@$host>\r\n";
                            $mail_sent = @mail($email, $subject, $bodyHtml, $headers);
                        }
                    } catch (Throwable $mailEx) {
                        error_log('Forgot password mail dispatch exception: ' . $mailEx->getMessage());
                        $mail_error_detail = $mailEx->getMessage();
                    }

                    if (isset($_SESSION['pending_reset_otp'])) {
                        unset($_SESSION['pending_reset_otp']);
                    }
                    $_SESSION['reset_otp_last_sent'][$email] = time();

                    if ($mail_sent) {
                        header('Location: reset_password.php?email=' . urlencode($email) . '&sent=1');
                        exit;
                    }

                    $message = "<div class='alert alert-danger text-center shadow-sm'><i class='fas fa-exclamation-triangle me-1'></i> Failed to send verification code to your email. Please check your internet connection or email address.</div>";
                    if (!empty($mail_error_detail)) {
                        error_log("Password reset email error: " . $mail_error_detail);
                    }
                }
            }
        } catch (Throwable $dbEx) {
            error_log('Forgot password DB exception: ' . $dbEx->getMessage());
            $message = "<div class='alert alert-danger text-center shadow-sm'><i class='fas fa-exclamation-circle me-1'></i> An error occurred processing your request. Please try again shortly.</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Garden of Remembrance</title>
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
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(123, 17, 19, 0.25);
        }
        .btn-burgundy:hover {
            background-color: var(--bs-primary-hover);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(123, 17, 19, 0.35);
        }
        .btn-burgundy:active {
            transform: translateY(0);
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
        .info-pill {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            font-size: 0.84rem;
            color: #475569;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            line-height: 1.45;
        }
        .info-pill i {
            color: var(--bs-primary);
            font-size: 1rem;
            margin-top: 0.15rem;
        }
    </style>
</head>
<body>
<div class="page-shell">
    <div class="auth-card">
        <div class="auth-header">
            <img src="cmis_logo.png" alt="Garden of Remembrance Logo" class="brand-logo">
            <h2>Forgot Password</h2>
            <p>Recover access to your Garden of Remembrance account</p>
        </div>
        <div class="auth-body">
            <?php if (!empty($message)) echo $message; ?>

            <div class="info-pill">
                <i class="fas fa-info-circle"></i>
                <div>Enter your registered email address below. We'll send you a 6-digit verification code to reset your password.</div>
            </div>

            <form method="POST" action="">
                <div class="mb-4">
                    <label for="emailInput" class="form-label">Registered Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="email" id="emailInput" name="email" class="form-control" placeholder="example@gmail.com" required autofocus>
                    </div>
                </div>
                <button type="submit" class="btn btn-burgundy mb-3">
                    <i class="fas fa-paper-plane me-1"></i> Send Verification Code
                </button>
            </form>

            <div class="text-center pt-2 border-top mt-4">
                <a href="login.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>

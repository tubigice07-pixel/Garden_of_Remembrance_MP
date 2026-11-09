<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/mailer.php';

if ($conn instanceof mysqli && @$conn->ping()) {
    static $db_checked = false;
    if (!$db_checked) {
        $db_checked = true;
        @$conn->query("ALTER TABLE tbl_user MODIFY COLUMN verification_otp VARCHAR(255) NULL");
        $chkCol = @$conn->query("SHOW COLUMNS FROM tbl_user LIKE 'verification_attempts'");
        if ($chkCol && $chkCol->num_rows === 0) {
            @$conn->query("ALTER TABLE tbl_user ADD COLUMN verification_attempts INT DEFAULT 0");
        }
    }
}

$email = strtolower(trim($_GET['email'] ?? $_POST['email'] ?? $_SESSION['verify_email'] ?? ''));
$type = strtolower(trim($_GET['type'] ?? $_POST['type'] ?? $_SESSION['verify_role'] ?? 'user'));
$isAdmin = ($type === 'admin');

$legacyToken = trim($_GET['token'] ?? '');
if (!empty($legacyToken)) {
    if ($conn instanceof mysqli) {
        $stmt = $conn->prepare("SELECT user_id, email, role, email_verified, verification_expires FROM tbl_user WHERE verification_token = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $legacyToken);
            $stmt->execute();
            $legacyUser = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($legacyUser) {
                $email = strtolower(trim($legacyUser['email']));
                $isAdmin = (strtoupper(trim($legacyUser['role'] ?? '')) === 'ADMIN');
                $type = $isAdmin ? 'admin' : 'user';

                if (intval($legacyUser['email_verified']) === 1) {
                    $targetLogin = $isAdmin ? 'admin_login.php?verified=1' : 'login.php?verified=1';
                    header("Location: $targetLogin");
                    exit;
                }
                if (!empty($legacyUser['verification_expires']) && strtotime($legacyUser['verification_expires']) >= time()) {
                    $up = $conn->prepare("UPDATE tbl_user SET email_verified = 1, verification_token = NULL, verification_expires = NULL, verification_otp = NULL, otp_expiry = NULL, verification_attempts = 0 WHERE user_id = ?");
                    if ($up) {
                        $up->bind_param('i', $legacyUser['user_id']);
                        $up->execute();
                        $up->close();
                        $targetLogin = $isAdmin ? 'admin_login.php?verified=1' : 'login.php?verified=1';
                        header("Location: $targetLogin");
                        exit;
                    }
                }
            }
        }
    }
}

$user = null;
if (!empty($email) && $conn instanceof mysqli) {
    $uStmt = $conn->prepare("SELECT user_id, first_name, last_name, email, role, email_verified, verification_otp, otp_expiry, verification_attempts FROM tbl_user WHERE LOWER(TRIM(email)) = ? LIMIT 1");
    if ($uStmt) {
        $uStmt->bind_param('s', $email);
        $uStmt->execute();
        $user = $uStmt->get_result()->fetch_assoc();
        $uStmt->close();
    }
}

if ($user) {
    $userRole = strtoupper(trim((string)($user['role'] ?? 'USER')));
    $isAdmin = ($userRole === 'ADMIN');
    $type = $isAdmin ? 'admin' : 'user';

    if (intval($user['email_verified'] ?? 0) === 1) {
        $dest = $isAdmin ? 'admin_login.php?verified=1' : 'login.php?verified=1';
        header("Location: $dest");
        exit;
    }
}

$alertMessage = '';
$alertType = '';

if (isset($_POST['action']) && $_POST['action'] === 'resend_code') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax']);

    if (!$user) {
        $response = ['success' => false, 'message' => 'No account associated with that email address was found.'];
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($response);
            exit;
        }
        $alertMessage = $response['message'];
        $alertType = 'danger';
    } else {
        $lastResendKey = 'last_resend_time_' . md5($email);
        $lastResendTime = (int)($_SESSION[$lastResendKey] ?? 0);
        $secondsSince = time() - $lastResendTime;
        $cooldown = 60;

        if ($secondsSince < $cooldown) {
            $remaining = $cooldown - $secondsSince;
            $response = ['success' => false, 'message' => "Please wait {$remaining} second(s) before requesting another code."];
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($response);
                exit;
            }
            $alertMessage = $response['message'];
            $alertType = 'warning';
        } else {

            $newOtp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $newHash = hash('sha256', $newOtp);
            $newExpiry = date('Y-m-d H:i:s', time() + (5 * 60)); 

            $upStmt = $conn->prepare("UPDATE tbl_user SET verification_otp = ?, otp_expiry = ?, verification_token = ?, verification_expires = ?, verification_attempts = 0 WHERE user_id = ?");
            if ($upStmt) {
                $upStmt->bind_param('ssssi', $newHash, $newExpiry, $newHash, $newExpiry, $user['user_id']);
                $upStmt->execute();
                $upStmt->close();

                $_SESSION[$lastResendKey] = time();

                $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                $sent = send_registration_otp_email($user['email'], $fullName, $newOtp, $isAdmin);

                $user['verification_otp'] = $newHash;
                $user['otp_expiry'] = $newExpiry;
                $user['verification_attempts'] = 0;

                if ($sent) {
                    $response = ['success' => true, 'message' => 'A new 6-digit verification code has been sent to your email.'];
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode($response);
                        exit;
                    }
                    $alertMessage = $response['message'];
                    $alertType = 'success';
                } else {
                    $response = ['success' => false, 'message' => 'Failed to send verification email. Please try again later.'];
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode($response);
                        exit;
                    }
                    $alertMessage = $response['message'];
                    $alertType = 'danger';
                }
            } else {
                $response = ['success' => false, 'message' => 'Database error while generating new code.'];
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($response);
                    exit;
                }
                $alertMessage = $response['message'];
                $alertType = 'danger';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {
    $submittedCode = '';
    if (!empty($_POST['code'])) {
        $submittedCode = preg_replace('/\D+/', '', trim($_POST['code']));
    } else {
        for ($i = 1; $i <= 6; $i++) {
            $digit = trim($_POST['digit' . $i] ?? '');
            if ($digit !== '') {
                $submittedCode .= $digit;
            }
        }
    }

    if (!$user) {
        $alertMessage = 'No account record was found. Please register or provide your email.';
        $alertType = 'danger';
    } elseif (strlen($submittedCode) !== 6) {
        $alertMessage = 'Please enter the complete 6-digit verification code.';
        $alertType = 'danger';
    } else {
        $attempts = (int)($user['verification_attempts'] ?? 0);

        if ($attempts >= 5) {

            $conn->query("UPDATE tbl_user SET verification_otp = NULL, otp_expiry = NULL WHERE user_id = " . (int)$user['user_id']);
            $user['verification_otp'] = null;
            $user['otp_expiry'] = null;
            $alertMessage = 'Too many failed attempts. Please request a new verification code.';
            $alertType = 'danger';
        } elseif (empty($user['otp_expiry']) || strtotime($user['otp_expiry']) < time()) {
            $alertMessage = 'Verification code expired. Please request a new code.';
            $alertType = 'warning';
        } else {
            $storedHash = (string)($user['verification_otp'] ?? '');
            $inputHash = hash('sha256', $submittedCode);

            $isMatch = hash_equals($storedHash, $inputHash) || ($storedHash === $submittedCode);

            if ($isMatch) {

                $vUp = $conn->prepare("UPDATE tbl_user SET email_verified = 1, verification_otp = NULL, otp_expiry = NULL, verification_token = NULL, verification_expires = NULL, verification_attempts = 0 WHERE user_id = ?");
                if ($vUp) {
                    $vUp->bind_param('i', $user['user_id']);
                    $vUp->execute();
                    $vUp->close();

                    unset($_SESSION['verify_email']);
                    unset($_SESSION['verify_role']);

                    if (!$isAdmin && function_exists('sync_user_existing_cemetery_records')) {
                        sync_user_existing_cemetery_records($conn, (int)$user['user_id']);
                    }

                    if ($isAdmin) {
                        header('Location: admin_login.php?verified=1');
                    } else {
                        header('Location: login.php?verified=1');
                    }
                    exit;
                } else {
                    $alertMessage = 'An error occurred while updating your account. Please try again.';
                    $alertType = 'danger';
                }
            } else {
                $attempts++;
                $conn->query("UPDATE tbl_user SET verification_attempts = {$attempts} WHERE user_id = " . (int)$user['user_id']);
                $user['verification_attempts'] = $attempts;

                if ($attempts >= 5) {
                    $conn->query("UPDATE tbl_user SET verification_otp = NULL, otp_expiry = NULL WHERE user_id = " . (int)$user['user_id']);
                    $user['verification_otp'] = null;
                    $user['otp_expiry'] = null;
                    $alertMessage = 'Too many failed attempts. Please request a new verification code.';
                    $alertType = 'danger';
                } else {
                    $remainingAttempts = 5 - $attempts;
                    $alertMessage = "Invalid verification code. You have {$remainingAttempts} attempt(s) remaining.";
                    $alertType = 'danger';
                }
            }
        }
    }
}

$expirySeconds = 0;
if ($user && !empty($user['otp_expiry'])) {
    $diff = strtotime($user['otp_expiry']) - time();
    $expirySeconds = max(0, $diff);
}

$lastResendKey = 'last_resend_time_' . md5($email);
$lastResendTime = (int)($_SESSION[$lastResendKey] ?? 0);
$secondsSinceResend = time() - $lastResendTime;
$cooldownSeconds = max(0, 60 - $secondsSinceResend);

$isExpired = ($expirySeconds <= 0);
$attemptsExceeded = ($user && (int)($user['verification_attempts'] ?? 0) >= 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isAdmin ? 'Admin Email Verification' : 'Email Verification'; ?> - Garden of Remembrance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #7b1113;
            --primary-hover: #921719;
            --primary-light: #fbf0f0;
            --accent-gold: #c59b27;
            --ink: #1e293b;
        }
        * {
            box-sizing: border-box;
        }
        html, body {
            height: 100%;
        }
        body {
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: url('garden of remembrance background.webp') center/cover no-repeat fixed;
            min-height: 100vh;
            color: var(--ink);
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.82), rgba(123, 17, 19, 0.45));
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
        .verify-card {
            width: min(520px, 100%);
            background: rgba(255, 255, 255, 0.98);
            border-radius: 1.5rem;
            box-shadow: 0 25px 60px rgba(0,0,0,0.35);
            border: 1px solid rgba(255,255,255,0.7);
            overflow: hidden;
            animation: fadeInCard 0.4s ease;
        }
        @keyframes fadeInCard {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .verify-header {
            background: linear-gradient(145deg, #7b1113 0%, #4a0a0b 100%);
            color: #ffffff;
            padding: 2.2rem 2rem 1.8rem;
            text-align: center;
            position: relative;
        }
        .verify-header::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #c59b27, #fef3c7, #c59b27);
        }
        .brand-logo {
            max-width: 110px;
            height: auto;
            display: block;
            margin: 0 auto 1rem;
            filter: drop-shadow(0 4px 10px rgba(0,0,0,0.3));
        }
        .verify-badge {
            display: inline-block;
            background: rgba(255,255,255,0.18);
            color: #ffffff;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 0.3rem 0.8rem;
            border-radius: 2rem;
            margin-bottom: 0.6rem;
            border: 1px solid rgba(255,255,255,0.25);
        }
        .verify-header h2 {
            font-size: 1.35rem;
            font-weight: 800;
            margin: 0 0 0.35rem;
            letter-spacing: -0.02em;
        }
        .verify-header p {
            font-size: 0.88rem;
            color: rgba(255,255,255,0.9);
            margin: 0;
        }
        .verify-body {
            padding: 2rem 2.2rem 2.2rem;
            text-align: center;
        }
        .recipient-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.85rem;
            padding: 0.85rem 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: #475569;
            word-break: break-all;
        }
        .recipient-box strong {
            color: #0f172a;
        }

        /* 6 individual OTP input boxes */
        .otp-inputs {
            display: flex;
            justify-content: center;
            gap: 0.55rem;
            margin: 1.5rem 0 1.2rem;
        }
        .otp-digit {
            width: 52px;
            height: 60px;
            font-size: 1.65rem;
            font-weight: 800;
            text-align: center;
            color: var(--primary);
            background: #ffffff;
            border: 2px solid #cbd5e1;
            border-radius: 0.75rem;
            transition: all 0.2s ease;
            outline: none;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .otp-digit:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(123, 17, 19, 0.15);
            background: var(--primary-light);
            transform: translateY(-2px);
        }
        .otp-digit:disabled {
            background: #f1f5f9;
            border-color: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
            transform: none;
        }

        /* Timer and expiration styling */
        .timer-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.92rem;
            font-weight: 700;
            color: #475569;
            background: #f1f5f9;
            padding: 0.45rem 1rem;
            border-radius: 2rem;
            margin-bottom: 1.4rem;
        }
        .timer-badge.warning {
            color: #b91c1c;
            background: #fee2e2;
            animation: pulseTimer 1.2s infinite ease-in-out;
        }
        @keyframes pulseTimer {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.65; }
        }

        .btn-verify {
            background-color: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 0.75rem;
            padding: 0.85rem 1.4rem;
            font-weight: 700;
            font-size: 1rem;
            width: 100%;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(123, 17, 19, 0.28);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .btn-verify:hover:not(:disabled) {
            background-color: var(--primary-hover);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(123, 17, 19, 0.35);
        }
        .btn-verify:disabled {
            background-color: #cbd5e1;
            color: #64748b;
            box-shadow: none;
            cursor: not-allowed;
            transform: none;
        }

        .btn-resend {
            background: transparent;
            color: var(--primary);
            border: 1.5px solid #cbd5e1;
            border-radius: 0.75rem;
            padding: 0.75rem 1.2rem;
            font-weight: 600;
            font-size: 0.92rem;
            width: 100%;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            margin-top: 0.75rem;
        }
        .btn-resend:hover:not(:disabled) {
            background: #f8fafc;
            border-color: var(--primary);
            color: var(--primary-hover);
        }
        .btn-resend:disabled {
            color: #94a3b8;
            border-color: #e2e8f0;
            background: #f8fafc;
            cursor: not-allowed;
        }

        .back-nav {
            margin-top: 1.6rem;
            padding-top: 1.2rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            font-size: 0.88rem;
        }
        .back-nav a {
            color: #64748b;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .back-nav a:hover {
            color: var(--primary);
        }

        @media (max-width: 480px) {
            .verify-card {
                border-radius: 1.2rem;
            }
            .verify-body {
                padding: 1.6rem 1.2rem;
            }
            .otp-inputs {
                gap: 0.35rem;
            }
            .otp-digit {
                width: 44px;
                height: 52px;
                font-size: 1.4rem;
            }
        }
    </style>
</head>
<body>
<div class="page-shell">
    <div class="verify-card">
        <div class="verify-header">
            <img src="cmis_logo.png" alt="Garden of Remembrance" class="brand-logo">
            <span class="verify-badge">
                <i class="fas fa-shield-alt me-1"></i>
                <?php echo $isAdmin ? 'Administrator Verification' : 'Email Verification'; ?>
            </span>
            <h2>Verify Your Email Address</h2>
            <p>Garden of Remembrance Memorial Park</p>
        </div>

        <div class="verify-body">

            <div id="alertContainer">
                <?php if (!empty($alertMessage)): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($alertType); ?> shadow-sm text-center py-2 mb-3" role="alert">
                        <i class="fas <?php echo $alertType === 'success' ? 'fa-check-circle' : ($alertType === 'warning' ? 'fa-clock' : 'fa-exclamation-circle'); ?> me-1"></i>
                        <?php echo htmlspecialchars($alertMessage); ?>
                    </div>
                <?php elseif ($isExpired): ?>
                    <div class="alert alert-warning shadow-sm text-center py-2 mb-3" id="expiredNotice">
                        <i class="fas fa-clock me-1"></i>
                        Verification code expired. Please request a new code.
                    </div>
                <?php elseif ($attemptsExceeded): ?>
                    <div class="alert alert-danger shadow-sm text-center py-2 mb-3">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        Too many failed attempts. Please request a new verification code.
                    </div>
                <?php endif; ?>
            </div>

            <div class="recipient-box">
                <i class="fas fa-envelope-open-text text-muted me-1"></i>
                We have sent a 6-digit code to:<br>
                <strong><?php echo htmlspecialchars($email ?: 'your email address'); ?></strong>
            </div>

            <form method="post" action="verify.php" id="otpForm">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
                <input type="hidden" name="code" id="hiddenCodeInput" value="">

                <div class="otp-inputs" id="otpInputsGroup">
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-digit" id="d1" name="digit1" autocomplete="one-time-code" autofocus required <?php echo ($isExpired || $attemptsExceeded) ? 'disabled' : ''; ?>>
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-digit" id="d2" name="digit2" required <?php echo ($isExpired || $attemptsExceeded) ? 'disabled' : ''; ?>>
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-digit" id="d3" name="digit3" required <?php echo ($isExpired || $attemptsExceeded) ? 'disabled' : ''; ?>>
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-digit" id="d4" name="digit4" required <?php echo ($isExpired || $attemptsExceeded) ? 'disabled' : ''; ?>>
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-digit" id="d5" name="digit5" required <?php echo ($isExpired || $attemptsExceeded) ? 'disabled' : ''; ?>>
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-digit" id="d6" name="digit6" required <?php echo ($isExpired || $attemptsExceeded) ? 'disabled' : ''; ?>>
                </div>

                <div class="timer-badge <?php echo ($expirySeconds <= 60 && $expirySeconds > 0) ? 'warning' : ''; ?>" id="timerWrapper">
                    <i class="fas fa-hourglass-half"></i>
                    <span id="timerText">Code expires in: <strong id="countdownDisplay"><?php echo sprintf('%02d:%02d', floor($expirySeconds / 60), $expirySeconds % 60); ?></strong></span>
                </div>

                <button type="submit" name="verify_code" id="verifyBtn" class="btn-verify" <?php echo ($isExpired || $attemptsExceeded) ? 'disabled' : ''; ?>>
                    <i class="fas fa-check-shield"></i> Verify Code
                </button>
            </form>

            <form method="post" action="verify.php" id="resendForm">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
                <input type="hidden" name="action" value="resend_code">

                <button type="submit" id="resendBtn" class="btn-resend" <?php echo ($cooldownSeconds > 0) ? 'disabled' : ''; ?>>
                    <i class="fas fa-redo-alt" id="resendIcon"></i>
                    <span id="resendText">
                        <?php if ($cooldownSeconds > 0): ?>
                            Resend Code (<?php echo $cooldownSeconds; ?>s)
                        <?php else: ?>
                            Resend Code
                        <?php endif; ?>
                    </span>
                </button>
            </form>

            <div class="back-nav">
                <?php if ($isAdmin): ?>
                    <a href="admin_login.php">
                        <i class="fas fa-arrow-left"></i> Back to Admin Login
                    </a>
                <?php else: ?>
                    <a href="login.php">
                        <i class="fas fa-arrow-left"></i> Back to User Login
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const digits = [
        document.getElementById('d1'),
        document.getElementById('d2'),
        document.getElementById('d3'),
        document.getElementById('d4'),
        document.getElementById('d5'),
        document.getElementById('d6')
    ];
    const otpForm = document.getElementById('otpForm');
    const hiddenCode = document.getElementById('hiddenCodeInput');
    const verifyBtn = document.getElementById('verifyBtn');
    const resendBtn = document.getElementById('resendBtn');
    const resendText = document.getElementById('resendText');
    const resendForm = document.getElementById('resendForm');
    const countdownDisplay = document.getElementById('countdownDisplay');
    const timerWrapper = document.getElementById('timerWrapper');
    const alertContainer = document.getElementById('alertContainer');

    let expirySeconds = <?php echo (int)$expirySeconds; ?>;
    let cooldownSeconds = <?php echo (int)$cooldownSeconds; ?>;
    let timerInterval = null;
    let cooldownInterval = null;

    // --- 1. Input Box Navigation (Focus next, backspace, paste) ---
    digits.forEach((input, idx) => {
        if (!input) return;

        // Auto focus next on single digit entry
        input.addEventListener('input', function(e) {
            const val = this.value.replace(/\D/g, '');
            this.value = val ? val.slice(-1) : '';

            if (this.value && idx < digits.length - 1) {
                digits[idx + 1].focus();
                digits[idx + 1].select();
            }
            updateHiddenCode();
        });

        // Handle Backspace navigation
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace') {
                if (!this.value && idx > 0) {
                    digits[idx - 1].focus();
                    digits[idx - 1].value = '';
                    updateHiddenCode();
                    e.preventDefault();
                }
            } else if (e.key === 'ArrowLeft' && idx > 0) {
                digits[idx - 1].focus();
            } else if (e.key === 'ArrowRight' && idx < digits.length - 1) {
                digits[idx + 1].focus();
            }
        });

        // Paste support: pastes all 6 digits and distributes to boxes
        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const pasteData = (e.clipboardData || window.clipboardData).getData('text');
            const cleanDigits = pasteData.replace(/\D/g, '').slice(0, 6);

            if (cleanDigits.length > 0) {
                cleanDigits.split('').forEach((char, i) => {
                    if (digits[i]) {
                        digits[i].value = char;
                    }
                });
                const focusIdx = Math.min(cleanDigits.length, digits.length - 1);
                digits[focusIdx].focus();
                updateHiddenCode();
            }
        });
    });

    function updateHiddenCode() {
        const fullCode = digits.map(d => d ? d.value : '').join('');
        if (hiddenCode) hiddenCode.value = fullCode;
    }

    if (otpForm) {
        otpForm.addEventListener('submit', function(e) {
            updateHiddenCode();
            const fullCode = hiddenCode.value;
            if (fullCode.length !== 6) {
                e.preventDefault();
                showAlert('Please enter the complete 6-digit verification code.', 'danger');
                const emptyIdx = digits.findIndex(d => !d.value);
                if (emptyIdx !== -1 && digits[emptyIdx]) {
                    digits[emptyIdx].focus();
                }
            }
        });
    }

    // --- 2. Expiration Timer (5:00 countdown) ---
    function formatTime(sec) {
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function startExpiryTimer() {
        if (timerInterval) clearInterval(timerInterval);

        function tick() {
            if (expirySeconds <= 0) {
                clearInterval(timerInterval);
                if (countdownDisplay) countdownDisplay.textContent = '00:00';
                if (timerWrapper) timerWrapper.classList.add('warning');
                if (verifyBtn) verifyBtn.disabled = true;
                digits.forEach(d => { if (d) d.disabled = true; });

                showAlert('Verification code expired. Please request a new code.', 'warning');
                return;
            }

            if (countdownDisplay) {
                countdownDisplay.textContent = formatTime(expirySeconds);
            }
            if (expirySeconds <= 60 && timerWrapper) {
                timerWrapper.classList.add('warning');
            } else if (timerWrapper) {
                timerWrapper.classList.remove('warning');
            }
            expirySeconds--;
        }

        tick();
        timerInterval = setInterval(tick, 1000);
    }

    if (expirySeconds > 0) {
        startExpiryTimer();
    } else {
        if (countdownDisplay) countdownDisplay.textContent = '00:00';
        if (verifyBtn) verifyBtn.disabled = true;
        digits.forEach(d => { if (d) d.disabled = true; });
    }

    // --- 3. Resend Code Cooldown Timer (60s) ---
    function startCooldownTimer() {
        if (cooldownInterval) clearInterval(cooldownInterval);
        if (resendBtn) resendBtn.disabled = true;

        function tickCooldown() {
            if (cooldownSeconds <= 0) {
                clearInterval(cooldownInterval);
                if (resendBtn) resendBtn.disabled = false;
                if (resendText) resendText.textContent = 'Resend Code';
                return;
            }
            if (resendText) resendText.textContent = 'Resend Code (' + cooldownSeconds + 's)';
            cooldownSeconds--;
        }

        tickCooldown();
        cooldownInterval = setInterval(tickCooldown, 1000);
    }

    if (cooldownSeconds > 0) {
        startCooldownTimer();
    }

    // --- 4. AJAX Resend Code Handler for Seamless UX ---
    if (resendForm) {
        resendForm.addEventListener('submit', function(e) {
            e.preventDefault();
            if (resendBtn.disabled) return;

            resendBtn.disabled = true;
            const origHtml = resendText.textContent;
            resendText.textContent = 'Sending...';

            const formData = new FormData(resendForm);
            formData.append('ajax', '1');

            fetch('verify.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message || 'A new verification code has been sent.', 'success');

                    // Reset 5:00 expiration timer
                    expirySeconds = 300;
                    startExpiryTimer();

                    // Enable verify button & digits
                    if (verifyBtn) verifyBtn.disabled = false;
                    digits.forEach(d => {
                        if (d) {
                            d.disabled = false;
                            d.value = '';
                        }
                    });
                    if (digits[0]) digits[0].focus();

                    // Start 60s cooldown
                    cooldownSeconds = 60;
                    startCooldownTimer();
                } else {
                    showAlert(data.message || 'Failed to resend code. Please try again.', 'danger');
                    resendBtn.disabled = false;
                    resendText.textContent = origHtml;
                }
            })
            .catch(err => {
                console.error('Resend error:', err);
                // Fallback to normal form submit
                resendForm.submit();
            });
        });
    }

    function showAlert(msg, type) {
        if (!alertContainer) return;
        const icon = (type === 'success') ? 'fa-check-circle' : (type === 'warning' ? 'fa-clock' : 'fa-exclamation-circle');
        alertContainer.innerHTML = `
            <div class="alert alert-${type} shadow-sm text-center py-2 mb-3" role="alert">
                <i class="fas ${icon} me-1"></i> ${msg}
            </div>
        `;
    }
});
</script>
</body>
</html>

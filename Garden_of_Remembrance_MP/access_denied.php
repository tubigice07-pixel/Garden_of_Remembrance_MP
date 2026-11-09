<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$redirect = 'login.php';
if (!empty($_SESSION['role'])) {
    if (strtoupper(trim((string) $_SESSION['role'])) === 'ADMIN') {
        $redirect = 'dashboard.php';
    } else {
        $redirect = 'user_dashboard.php';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - Garden of Remembrance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bs-primary: #7b1113;
            --bs-primary-hover: #921719;
            --stone: #f5efe8;
            --ink: #2b211f;
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
            width: min(500px, 100%);
            background: rgba(255, 250, 245, 0.96);
            border-radius: 1.5rem;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0,0,0,0.28);
            border: 1px solid rgba(255,255,255,0.5);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            text-align: center;
        }
        .auth-header {
            background: linear-gradient(145deg, rgba(123,17,19,0.96), rgba(71,13,15,0.92));
            color: #ffffff;
            padding: 2.2rem 2rem 1.8rem;
        }
        .brand-logo {
            max-width: 130px;
            height: auto;
            display: block;
            margin: 0 auto 1rem;
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.25));
        }
        .auth-body {
            padding: 2.2rem 2rem;
        }
        .warning-icon-wrap {
            width: 70px;
            height: 70px;
            background: rgba(123, 17, 19, 0.1);
            color: var(--bs-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1.25rem;
        }
        .btn-burgundy {
            background-color: var(--bs-primary);
            color: #ffffff;
            border: none;
            border-radius: 0.75rem;
            padding: 0.75rem 1.5rem;
            font-weight: 700;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(123, 17, 19, 0.25);
            text-decoration: none;
        }
        .btn-burgundy:hover {
            background-color: var(--bs-primary-hover);
            color: #ffffff;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
<div class="page-shell">
    <div class="auth-card">
        <div class="auth-header">
            <img src="cmis_logo.png" alt="Garden of Remembrance Logo" class="brand-logo">
            <h2 class="h5 mb-0 text-uppercase" style="letter-spacing: 0.08em; font-weight: 700;">Access Denied</h2>
        </div>
        <div class="auth-body">
            <div class="warning-icon-wrap">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h3 class="h5 fw-bold text-dark mb-2">Restricted Access</h3>
            <p class="text-muted mb-4" style="font-size: 0.92rem; line-height: 1.5;">You do not have administrative permissions to view this section.</p>
            <a href="<?php echo htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'); ?>" class="btn-burgundy">
                <i class="fas fa-arrow-left"></i> Return to Dashboard
            </a>
        </div>
    </div>
</div>
</body>
</html>

<?php
include 'connection.php';
session_start();

$message = '';
$first = '';
$middle = '';
$last = '';
$suffix = '';
$birthday = '';
$gender = '';
$email = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_register'])) {
    $first = strtoupper(trim($_POST['first_name'] ?? ''));
    $middle = strtoupper(trim($_POST['middle_name'] ?? ''));
    $last = strtoupper(trim($_POST['last_name'] ?? ''));
    $raw_suffix = strtoupper(trim($_POST['suffix'] ?? ''));
    $other_suffix = strtoupper(trim($_POST['suffix_other'] ?? ''));
    $suffix = ($raw_suffix === 'OTHER') ? $other_suffix : $raw_suffix;
    $birthday = trim($_POST['birthday'] ?? '');
    $gender = ucfirst(strtolower(trim($_POST['gender'] ?? '')));
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = preg_replace('/\D+/', '', $_POST['phone_no'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $today = date('Y-m-d');
    if ($first === '' || $last === '' || $birthday === '' || $email === '' || $phone === '') {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Please complete all required administrator information.</div>';
    } elseif ($gender !== 'Male' && $gender !== 'Female') {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Please select a valid gender (Male or Female).</div>';
    } elseif ($birthday > $today) {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Date of birth cannot be a future date.</div>';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Please provide a valid email address.</div>';
    } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Phone number must be an 11-digit mobile number starting with 09 (e.g. 09XXXXXXXXX).</div>';
    } elseif ($password !== $confirmPassword) {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Passwords do not match.</div>';
    } elseif (strlen($password) < 8) {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Password must be at least 8 characters in length.</div>';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Password must contain at least one uppercase letter (A-Z).</div>';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Password must contain at least one lowercase letter (a-z).</div>';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Password must contain at least one number (0-9).</div>';
    } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> Password must contain at least one symbol or special character (e.g. !@#$%^&*).</div>';
    } else {
        $check = $conn->prepare('SELECT user_id FROM tbl_user WHERE email = ? LIMIT 1');
        $check->bind_param('s', $email);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle me-1"></i> That email address is already registered.</div>';
        } else {
            require_once __DIR__ . '/mailer.php';
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $otp_hash = hash('sha256', $otp);
            $expires = date('Y-m-d H:i:s', time() + (5 * 60));

            @$conn->query("ALTER TABLE tbl_user MODIFY COLUMN verification_otp VARCHAR(255) NULL");
            $colChk = @$conn->query("SHOW COLUMNS FROM tbl_user LIKE 'verification_attempts'");
            if ($colChk && $colChk->num_rows === 0) {
                @$conn->query("ALTER TABLE tbl_user ADD COLUMN verification_attempts INT DEFAULT 0");
            }
            $genChk = @$conn->query("SHOW COLUMNS FROM tbl_user LIKE 'gender'");
            if ($genChk && $genChk->num_rows === 0) {
                @$conn->query("ALTER TABLE tbl_user ADD COLUMN gender VARCHAR(10) NULL DEFAULT NULL AFTER birthday");
            }

            $stmt = $conn->prepare("INSERT INTO tbl_user (role, first_name, middle_name, last_name, suffix, birthday, gender, email, password, phone_no, email_verified, verification_otp, otp_expiry, verification_token, verification_expires, verification_attempts) VALUES ('ADMIN', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 0)");
            if ($stmt) {
                $stmt->bind_param('sssssssssssss', $first, $middle, $last, $suffix, $birthday, $gender, $email, $hash, $phone, $otp_hash, $expires, $otp_hash, $expires);
                if ($stmt->execute()) {
                    $fullName = trim($first . ' ' . $last);
                    send_registration_otp_email($email, $fullName, $otp, true);

                    $_SESSION['verify_email'] = $email;
                    $_SESSION['verify_role'] = 'ADMIN';
                    $_SESSION['last_resend_time_' . md5($email)] = time();

                    header('Location: verify.php?email=' . urlencode($email) . '&type=admin');
                    exit;
                }
                $stmt->close();
            }
            $message = '<div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-triangle me-1"></i> Unable to create the administrator account. Please try again.</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Registration - Garden of Remembrance</title>
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
            width: min(620px, 100%);
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
            padding: 2rem 2rem 1.6rem;
            text-align: center;
        }
        .brand-logo {
            max-width: 120px;
            height: auto;
            display: block;
            margin: 0 auto 0.8rem;
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.25));
        }
        .auth-header h2 {
            font-size: 1.35rem;
            font-weight: 700;
            margin: 0 0 0.25rem;
        }
        .auth-header p {
            font-size: 0.85rem;
            color: rgba(255,255,255,0.85);
            margin: 0;
        }
        .auth-body {
            padding: 1.8rem 2rem 2rem;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.84rem;
            color: #475569;
            margin-bottom: 0.3rem;
        }
        .form-control {
            border-radius: 0.65rem;
            border-color: rgba(140,91,45,0.25);
            padding: 0.6rem 0.85rem;
            font-size: 0.9rem;
        }
        .form-control:focus {
            border-color: var(--bs-primary);
            box-shadow: 0 0 0 0.2rem rgba(123, 17, 19, 0.15);
        }
        .input-group .form-control {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        .toggle-pass-btn {
            border-color: rgba(140,91,45,0.25);
            color: #64748b;
            border-top-right-radius: 0.65rem;
            border-bottom-right-radius: 0.65rem;
            background: #ffffff;
            transition: all 0.2s ease;
            padding: 0.6rem 0.85rem;
        }
        .toggle-pass-btn:hover {
            background: #f1f5f9;
            color: var(--bs-primary);
            border-color: var(--bs-primary);
        }
        .password-strength-container {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 0.75rem 0.9rem;
            margin-top: 0.5rem;
        }
        .strength-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 0.35rem;
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
            margin-bottom: 0.55rem;
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
            gap: 0.3rem 0.5rem;
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
        .btn-burgundy {
            background-color: var(--bs-primary);
            color: #ffffff;
            border: none;
            border-radius: 0.75rem;
            padding: 0.75rem 1.2rem;
            font-weight: 700;
            font-size: 0.95rem;
            width: 100%;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(123, 17, 19, 0.25);
        }
        .btn-burgundy:hover {
            background-color: var(--bs-primary-hover);
            color: #ffffff;
            transform: translateY(-1px);
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: #64748b;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.88rem;
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
            <h2>Administrator Registration</h2>
            <p>Create a staff administrator account</p>
        </div>
        <div class="auth-body">
            <?php if (!empty($message)) echo $message; ?>

            <form method="post" action="" id="adminRegisterForm">
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label" for="first_name">First Name <span class="text-danger">*</span></label>
                        <input class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($first); ?>" autocomplete="given-name" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="middle_name">Middle Name <span class="text-muted fw-normal">(Optional)</span></label>
                        <input class="form-control" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($middle); ?>" autocomplete="additional-name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="last_name">Last Name <span class="text-danger">*</span></label>
                        <input class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($last); ?>" autocomplete="family-name" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="suffix">Suffix</label>
                        <select class="form-select" id="suffix" name="suffix" onchange="var o = document.getElementById('suffix_other'); if(o) { o.style.display = (this.value === 'OTHER' ? 'block' : 'none'); if(this.value === 'OTHER') o.focus(); }">
                            <option value="">No Suffix</option>
                            <option value="JR." <?php echo ($suffix === 'JR.' || $suffix === 'JR') ? 'selected' : ''; ?>>JR.</option>
                            <option value="SR." <?php echo ($suffix === 'SR.' || $suffix === 'SR') ? 'selected' : ''; ?>>SR.</option>
                            <option value="I" <?php echo $suffix === 'I' ? 'selected' : ''; ?>>I</option>
                            <option value="II" <?php echo $suffix === 'II' ? 'selected' : ''; ?>>II</option>
                            <option value="III" <?php echo $suffix === 'III' ? 'selected' : ''; ?>>III</option>
                            <option value="IV" <?php echo $suffix === 'IV' ? 'selected' : ''; ?>>IV</option>
                            <option value="V" <?php echo $suffix === 'V' ? 'selected' : ''; ?>>V</option>
                            <option value="VI" <?php echo $suffix === 'VI' ? 'selected' : ''; ?>>VI</option>
                            <option value="OTHER">OTHER</option>
                        </select>
                        <input type="text" class="form-control mt-1" id="suffix_other" name="suffix_other" style="display:none;" oninput="this.value = this.value.toUpperCase()">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="birthday">Birthday <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="birthday" name="birthday" value="<?php echo htmlspecialchars($birthday); ?>" autocomplete="bday" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="gender">Gender <span class="text-danger">*</span></label>
                        <select class="form-select" id="gender" name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="Male" <?php echo ($gender === 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($gender === 'Female') ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="phone_no">Phone Number <span class="text-danger">*</span></label>
                        <input class="form-control" id="phone_no" name="phone_no" value="<?php echo htmlspecialchars($phone); ?>" inputmode="numeric" maxlength="11" minlength="11" pattern="09[0-9]{9}" oninput="var val=this.value.replace(/[^0-9]/g,''); if(val.indexOf('639')===0) val='09'+val.substring(3); else if(val.indexOf('9')===0) val='09'+val.substring(1); else if(val.length>=2 && val.charAt(0)==='0' && val.charAt(1)!=='9') val='0'; else if(val.length===1 && val!=='0') val='09'; this.value=val.slice(0,11);" autocomplete="tel" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" autocomplete="email" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="password">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password" minlength="8" autocomplete="new-password" required>
                            <button class="btn btn-outline-secondary toggle-pass-btn" type="button" tabindex="-1" onclick="togglePass('password', this)" title="Show/Hide password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="confirm_password">Confirm Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                            <button class="btn btn-outline-secondary toggle-pass-btn" type="button" tabindex="-1" onclick="togglePass('confirm_password', this)" title="Show/Hide password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-12 mt-2">

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
                        <div class="password-match-indicator" id="matchIndicator"></div>
                    </div>
                </div>
                <button type="submit" name="admin_register" class="btn btn-burgundy mt-4 mb-3">
                    <i class="fas fa-user-plus me-1"></i> Create Admin Account
                </button>
            </form>

            <div class="text-center pt-2 border-top mt-3">
                <a href="admin_login.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Admin Login
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function togglePass(inputId, btn) {
    var input = document.getElementById(inputId);
    if (!input) return;
    var isPass = (input.type === 'password');
    input.type = isPass ? 'text' : 'password';
    var icon = btn.querySelector('i');
    if (icon) {
        icon.className = isPass ? 'fas fa-eye-slash' : 'fas fa-eye';
    }
}

// Password Strength & Match Engine
(function() {
    var passInput = document.getElementById('password');
    var confirmPassInput = document.getElementById('confirm_password');
    var form = document.getElementById('adminRegisterForm');
    if (!passInput) return;

    var seg1 = document.getElementById('seg1');
    var seg2 = document.getElementById('seg2');
    var seg3 = document.getElementById('seg3');
    var badge = document.getElementById('strengthBadge');
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
            if (icon) icon.className = 'fas fa-check-circle';
        } else {
            el.classList.remove('met');
            if (icon) icon.className = 'fas fa-circle';
        }
    }

    function evaluateStrength(pwd) {
        if (!pwd || pwd.length === 0) {
            updateCriteria(critLength, false);
            updateCriteria(critCase, false);
            updateCriteria(critNumber, false);
            updateCriteria(critSymbol, false);

            if (seg1) seg1.className = 'strength-segment';
            if (seg2) seg2.className = 'strength-segment';
            if (seg3) seg3.className = 'strength-segment';

            if (badge) {
                badge.className = 'strength-badge empty';
                badge.innerHTML = '<i class="fas fa-shield-alt"></i> <span id="strengthText">Not Entered</span>';
            }
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

        if (seg1) seg1.className = 'strength-segment';
        if (seg2) seg2.className = 'strength-segment';
        if (seg3) seg3.className = 'strength-segment';

        // Weak
        if (pwd.length < 8 || score <= 2) {
            if (seg1) seg1.classList.add('active-weak');
            if (badge) {
                badge.className = 'strength-badge weak';
                badge.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <span id="strengthText">Weak</span>';
            }
            return 'weak';
        }
        // Medium
        else if (score === 3 || score === 4) {
            if (seg1) seg1.classList.add('active-medium');
            if (seg2) seg2.classList.add('active-medium');
            if (badge) {
                badge.className = 'strength-badge medium';
                badge.innerHTML = '<i class="fas fa-check-circle"></i> <span id="strengthText">Medium</span>';
            }
            return 'medium';
        }
        // Strong
        else {
            if (seg1) seg1.classList.add('active-strong');
            if (seg2) seg2.classList.add('active-strong');
            if (seg3) seg3.classList.add('active-strong');
            if (badge) {
                badge.className = 'strength-badge strong';
                badge.innerHTML = '<i class="fas fa-shield-alt"></i> <span id="strengthText">Strong</span>';
            }
            return 'strong';
        }
    }

    function evaluateMatch() {
        if (!confirmPassInput || !matchIndicator) return;
        var p1 = passInput.value;
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

    passInput.addEventListener('input', function() {
        evaluateStrength(this.value);
        evaluateMatch();
    });

    if (confirmPassInput) {
        confirmPassInput.addEventListener('input', evaluateMatch);
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            var pwd = passInput.value;
            var cpwd = confirmPassInput ? confirmPassInput.value : '';

            var hasLength = pwd.length >= 8;
            var hasUpper = /[A-Z]/.test(pwd);
            var hasLower = /[a-z]/.test(pwd);
            var hasNumber = /[0-9]/.test(pwd);
            var hasSymbol = /[^A-Za-z0-9]/.test(pwd);

            if (!hasLength || !hasUpper || !hasLower || !hasNumber || !hasSymbol) {
                e.preventDefault();
                alert('Please provide a strong password that meets all criteria:\n• At least 8 characters\n• Both uppercase and lowercase letters\n• At least 1 number\n• At least 1 special symbol (!@#$%^&*)');
                passInput.focus();
                return false;
            }

            if (pwd !== cpwd) {
                e.preventDefault();
                alert('Passwords do not match. Please verify your confirm password.');
                confirmPassInput.focus();
                return false;
            }
        });
    }
})();
</script>
</body>
</html>

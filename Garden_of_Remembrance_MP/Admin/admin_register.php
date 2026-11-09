<?php
include __DIR__ . '/../connection.php';
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
    $suffix = strtoupper(trim($_POST['suffix'] ?? ''));
    $birthday = trim($_POST['birthday'] ?? '');
    $gender = ucfirst(strtolower(trim($_POST['gender'] ?? '')));
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = preg_replace('/\D+/', '', $_POST['phone_no'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($first === '' || $last === '' || $birthday === '' || $email === '' || $phone === '') {
        $message = '<div class="alert alert-danger">Please complete all required administrator information.</div>';
    } elseif ($gender !== 'Male' && $gender !== 'Female') {
        $message = '<div class="alert alert-danger">Please select a valid gender (Male or Female).</div>';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert alert-danger">Please provide a valid email address.</div>';
    } elseif ($password !== $confirmPassword) {
        $message = '<div class="alert alert-danger">Passwords do not match.</div>';
    } elseif (strlen($password) < 8) {
        $message = '<div class="alert alert-danger">Password must be at least 8 characters in length.</div>';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $message = '<div class="alert alert-danger">Password must contain at least one uppercase letter (A-Z).</div>';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $message = '<div class="alert alert-danger">Password must contain at least one lowercase letter (a-z).</div>';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $message = '<div class="alert alert-danger">Password must contain at least one number (0-9).</div>';
    } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $message = '<div class="alert alert-danger">Password must contain at least one symbol or special character (e.g. !@#$%^&*).</div>';
    } else {
        $check = $conn->prepare('SELECT user_id FROM tbl_user WHERE email = ? LIMIT 1');
        $check->bind_param('s', $email);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            $message = '<div class="alert alert-danger">That email address is already registered.</div>';
        } else {
            require_once __DIR__ . '/../mailer.php';
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

                    header('Location: ../verify.php?email=' . urlencode($email) . '&type=admin');
                    exit;
                }
                $stmt->close();
            }
            $message = '<div class="alert alert-danger">Unable to create the administrator account. Please try again.</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Registration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --primary: #7b1113; --ink: #2b211f; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; color: var(--ink); background: url('garden of remembrance background.webp') center/cover fixed; font-family: 'Segoe UI', sans-serif; }
        body::before { content: ''; position: fixed; inset: 0; background: rgba(7,15,30,.68); backdrop-filter: blur(5px); }
        .admin-card { position: relative; z-index: 1; width: min(520px, 100%); padding: 2rem; background: rgba(255,250,245,.96); border-radius: 1rem; box-shadow: 0 20px 55px rgba(0,0,0,.25); }
        .brand-logo { display: block; width: 110px; margin: 0 auto 1rem; }
        h1 { color: var(--primary); text-align: center; font-size: 1.6rem; margin-bottom: 1.5rem; }
        .btn-primary { background: var(--primary); border-color: var(--primary); }
        .btn-primary:hover { background: #a03a3c; border-color: #a03a3c; }
    </style>
</head>
<body>
<main class="admin-card">
    <img src="cmis_logo.png" alt="Garden of Remembrance" class="brand-logo">
    <h1>Administrator Registration</h1>
    <?php echo $message; ?>
    <form method="post" action="">
        <div class="row g-2">
            <div class="col-md-6"><label class="form-label" for="first_name">First Name</label><input class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($first); ?>" autocomplete="given-name" required></div>
            <div class="col-md-6"><label class="form-label" for="middle_name">Middle Name</label><input class="form-control" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($middle); ?>" autocomplete="additional-name"></div>
            <div class="col-md-6"><label class="form-label" for="last_name">Last Name</label><input class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($last); ?>" autocomplete="family-name" required></div>
            <div class="col-md-6"><label class="form-label" for="suffix">Suffix</label><input class="form-control" id="suffix" name="suffix" value="<?php echo htmlspecialchars($suffix); ?>" autocomplete="honorific-suffix"></div>
            <div class="col-md-6"><label class="form-label" for="birthday">Birthday</label><input type="date" class="form-control" id="birthday" name="birthday" value="<?php echo htmlspecialchars($birthday); ?>" autocomplete="bday" max="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-6">
                <label class="form-label" for="gender">Gender</label>
                <select class="form-select" id="gender" name="gender" required>
                    <option value="">Select Gender</option>
                    <option value="Male" <?php echo ($gender === 'Male') ? 'selected' : ''; ?>>Male</option>
                    <option value="Female" <?php echo ($gender === 'Female') ? 'selected' : ''; ?>>Female</option>
                </select>
            </div>
            <div class="col-md-6"><label class="form-label" for="phone_no">Phone Number</label><input class="form-control" id="phone_no" name="phone_no" value="<?php echo htmlspecialchars($phone); ?>" inputmode="numeric" autocomplete="tel" required></div>
            <div class="col-md-6"><label class="form-label" for="email">Email</label><input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" autocomplete="email" required></div>
            <div class="col-md-6"><label class="form-label" for="password">Password</label><input type="password" class="form-control" id="password" name="password" minlength="8" autocomplete="new-password" required></div>
            <div class="col-md-6"><label class="form-label" for="confirm_password">Confirm Password</label><input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required></div>
        </div>
        <button type="submit" name="admin_register" class="btn btn-primary w-100 mt-4">Create Admin Account</button>
        <p class="text-center mt-3 mb-0"><a href="admin_login.php">Back to Admin Login</a></p>
    </form>
</main>
</body>
</html>

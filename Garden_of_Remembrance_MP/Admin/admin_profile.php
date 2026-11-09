<?php
include __DIR__ . '/../connection.php';
require_once __DIR__ . '/../includes/formatters.php';
require_once __DIR__ . '/../config/payment_settings.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isRootContext = (strpos($_SERVER['REQUEST_URI'] ?? '', '/Admin/') === false);
$loginRedirect = $isRootContext ? 'admin_login.php' : '../admin_login.php';
$dashboardRedirect = $isRootContext ? 'user_dashboard.php' : '../user_dashboard.php';
$adminDashboardUrl = $isRootContext ? 'dashboard.php' : '../dashboard.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $loginRedirect);
    exit;
}

$user_id = intval($_SESSION['user_id']);
$is_admin = isset($_SESSION['role']) && strtoupper(trim((string)$_SESSION['role'])) === 'ADMIN';

if (!$is_admin) {
    header('Location: ' . $dashboardRedirect);
    exit;
}

$check_profile_column = $conn->query("SHOW COLUMNS FROM tbl_user LIKE 'profile_picture'");
if ($check_profile_column && $check_profile_column->num_rows === 0) {
    $conn->query("ALTER TABLE tbl_user ADD COLUMN profile_picture VARCHAR(255) NULL");
}
$check_account_column = $conn->query("SHOW COLUMNS FROM tbl_user LIKE 'account_number'");
if ($check_account_column && $check_account_column->num_rows === 0) {
    $conn->query("ALTER TABLE tbl_user ADD COLUMN account_number VARCHAR(20) DEFAULT NULL");
}
$check_gender_column = $conn->query("SHOW COLUMNS FROM tbl_user LIKE 'gender'");
if ($check_gender_column && $check_gender_column->num_rows === 0) {
    $conn->query("ALTER TABLE tbl_user ADD COLUMN gender VARCHAR(10) NULL DEFAULT NULL AFTER birthday");
}

$stmt = $conn->prepare("SELECT user_id, role, first_name, middle_name, last_name, suffix, birthday, gender, email, password, phone_no, profile_picture, account_number, email_verified FROM tbl_user WHERE user_id = ? AND UPPER(role) = 'ADMIN' LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
$admin_user = $res->fetch_assoc();
$stmt->close();

if (!$admin_user) {
    header('Location: logout.php');
    exit;
}

$errors = [];
$success = '';
$upload_path = 'uploads/profile_pictures/';
$default_avatar = 'uploads/profile_pictures/default_avatar.png';
$rootDir = dirname(__DIR__);
$current_picture = $default_avatar;
if (!empty($admin_user['profile_picture']) && file_exists($rootDir . '/' . $admin_user['profile_picture'])) {
    $current_picture = $admin_user['profile_picture'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_picture' && isset($_FILES['profile_picture'])) {
        if (!is_dir($rootDir . '/' . $upload_path)) {
            @mkdir($rootDir . '/' . $upload_path, 0755, true);
        }

        $file = $_FILES['profile_picture'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed. Please try again.';
        } else {
            if ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Avatar image must be 5MB or less.';
            }

            $allowed = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!array_key_exists($mime_type, $allowed)) {
                $errors[] = 'Only JPG, JPEG, PNG and WEBP image files are allowed.';
            }

            if (empty($errors)) {
                $extension = $allowed[$mime_type];
                $filename = 'admin_' . $user_id . '_' . time() . '.' . $extension;
                $destination = $upload_path . $filename;
                $destination_path = $rootDir . '/' . $destination;

                if (move_uploaded_file($file['tmp_name'], $destination_path)) {
                    @chmod($destination_path, 0644);

                    if (!empty($admin_user['profile_picture']) && file_exists($rootDir . '/' . $admin_user['profile_picture']) && $admin_user['profile_picture'] !== $default_avatar) {
                        @unlink($rootDir . '/' . $admin_user['profile_picture']);
                    }

                    $updStmt = $conn->prepare("UPDATE tbl_user SET profile_picture = ? WHERE user_id = ? AND UPPER(role) = 'ADMIN' LIMIT 1");
                    if ($updStmt) {
                        $updStmt->bind_param('si', $destination, $user_id);
                        if ($updStmt->execute()) {
                            $success = 'Admin profile picture updated successfully.';
                            $admin_user['profile_picture'] = $destination;
                            $current_picture = $destination;

                            if ($conn->query("SHOW TABLES LIKE 'tbl_logs'")->num_rows > 0) {
                                $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('ADMIN_AVATAR_UPDATE', 'Administrator updated profile photo', ?, NOW())");
                                if ($logStmt) {
                                    $logStmt->bind_param('i', $user_id);
                                    $logStmt->execute();
                                    $logStmt->close();
                                }
                            }
                        } else {
                            $errors[] = 'Database error while saving profile picture.';
                            @unlink($destination_path);
                        }
                        $updStmt->close();
                    }
                } else {
                    $errors[] = 'Unable to save the uploaded image. Check folder permissions.';
                }
            }
        }
    }

    if ($action === 'update_profile') {
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $raw_suffix = trim($_POST['suffix'] ?? '');
        $other_suffix = trim($_POST['suffix_other'] ?? '');
        $suffix = strtoupper($raw_suffix === 'OTHER' ? $other_suffix : $raw_suffix);
        $gender = ucfirst(strtolower(trim($_POST['gender'] ?? '')));
        $phone_no = trim($_POST['phone_no'] ?? '');
        $birthday = trim($_POST['birthday'] ?? '');

        if ($first_name === '') {
            $errors[] = 'First name is required.';
        }
        if ($last_name === '') {
            $errors[] = 'Last name is required.';
        }
        if ($gender !== '' && $gender !== 'Male' && $gender !== 'Female') {
            $errors[] = 'Please select a valid gender (Male or Female).';
        }
        if ($phone_no !== '' && !preg_match('/^[0-9]{8,20}$/', preg_replace('/\D/', '', $phone_no))) {
            $errors[] = 'Contact number must contain valid digits (e.g., 09123456789).';
        }

        $bday_val = !empty($birthday) ? $birthday : '1970-01-01';

        if (empty($errors)) {
            $clean_phone = preg_replace('/\D/', '', $phone_no);
            $updDetails = $conn->prepare("UPDATE tbl_user SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, gender = ?, phone_no = ?, birthday = ? WHERE user_id = ? AND UPPER(role) = 'ADMIN' LIMIT 1");
            if ($updDetails) {
                $updDetails->bind_param('sssssssi', $first_name, $middle_name, $last_name, $suffix, $gender, $clean_phone, $bday_val, $user_id);
                if ($updDetails->execute()) {
                    $success = 'Admin profile information updated successfully.';
                    $admin_user['first_name'] = $first_name;
                    $admin_user['middle_name'] = $middle_name;
                    $admin_user['last_name'] = $last_name;
                    $admin_user['suffix'] = $suffix;
                    $admin_user['gender'] = $gender;
                    $admin_user['phone_no'] = $clean_phone;
                    $admin_user['birthday'] = $bday_val;
                    $_SESSION['first_name'] = $first_name;

                    if ($conn->query("SHOW TABLES LIKE 'tbl_logs'")->num_rows > 0) {
                        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('ADMIN_PROFILE_UPDATE', 'Administrator updated personal details', ?, NOW())");
                        if ($logStmt) {
                            $logStmt->bind_param('i', $user_id);
                            $logStmt->execute();
                            $logStmt->close();
                        }
                    }
                } else {
                    $errors[] = 'Unable to save profile changes. Please try again.';
                }
                $updDetails->close();
            }
        }
    }

    if ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password)) {
            $errors[] = 'Please enter your current password.';
        } elseif (!password_verify($current_password, $admin_user['password'])) {
            $errors[] = 'The current password you entered is incorrect.';
        }

        if (empty($new_password)) {
            $errors[] = 'Please enter a new password.';
        } elseif (strlen($new_password) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            $errors[] = 'New password must contain at least one uppercase letter (A-Z).';
        } elseif (!preg_match('/[a-z]/', $new_password)) {
            $errors[] = 'New password must contain at least one lowercase letter (a-z).';
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            $errors[] = 'New password must contain at least one number (0-9).';
        } elseif (!preg_match('/[^A-Za-z0-9]/', $new_password)) {
            $errors[] = 'New password must contain at least one symbol or special character (e.g. !@#$%^&*).';
        } elseif ($new_password !== $confirm_password) {
            $errors[] = 'The new password and confirmation password do not match.';
        }

        if (empty($errors)) {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $passStmt = $conn->prepare("UPDATE tbl_user SET password = ? WHERE user_id = ? AND UPPER(role) = 'ADMIN' LIMIT 1");
            if ($passStmt) {
                $passStmt->bind_param('si', $new_hash, $user_id);
                if ($passStmt->execute()) {
                    $success = 'Your password has been changed successfully.';
                    $admin_user['password'] = $new_hash;

                    if ($conn->query("SHOW TABLES LIKE 'tbl_logs'")->num_rows > 0) {
                        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('ADMIN_PASSWORD_CHANGE', 'Administrator changed account password', ?, NOW())");
                        if ($logStmt) {
                            $logStmt->bind_param('i', $user_id);
                            $logStmt->execute();
                            $logStmt->close();
                        }
                    }
                } else {
                    $errors[] = 'Failed to update password. Please try again.';
                }
                $passStmt->close();
            }
        }
    }
}

$total_plots = 0;
$total_bookings = 0;
$total_deceased = 0;
$total_logs_count = 0;

$plotRes = $conn->query("SELECT COUNT(*) AS total FROM tbl_plots");
if ($plotRes && $pRow = $plotRes->fetch_assoc()) $total_plots = (int)$pRow['total'];

$bookRes = $conn->query("SELECT COUNT(*) AS total FROM tbl_bookings");
if ($bookRes && $bRow = $bookRes->fetch_assoc()) $total_bookings = (int)$bRow['total'];

$decRes = $conn->query("SELECT COUNT(*) AS total FROM tbl_deceased");
if ($decRes && $dRow = $decRes->fetch_assoc()) $total_deceased = (int)$dRow['total'];

$logRes = $conn->query("SELECT COUNT(*) AS total FROM tbl_logs WHERE user_id = $user_id");
if ($logRes && $lRow = $logRes->fetch_assoc()) $total_logs_count = (int)$lRow['total'];

$admin_logs = [];
$actStmt = $conn->prepare("SELECT action, details, timestamp FROM tbl_logs WHERE user_id = ? ORDER BY timestamp DESC LIMIT 6");
if ($actStmt) {
    $actStmt->bind_param('i', $user_id);
    $actStmt->execute();
    $actRes = $actStmt->get_result();
    while ($row = $actRes->fetch_assoc()) {
        $admin_logs[] = $row;
    }
    $actStmt->close();
}

$full_name = trim(implode(' ', array_filter([$admin_user['first_name'] ?? '', $admin_user['middle_name'] ?? '', $admin_user['last_name'] ?? '', $admin_user['suffix'] ?? ''])));
$display_picture = (!empty($admin_user['profile_picture']) && file_exists($rootDir . '/' . $admin_user['profile_picture'])) ? $admin_user['profile_picture'] : $default_avatar;
$active_nav = 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Profile • Garden of Remembrance CMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo $isRootContext ? 'includes/admin_header.css' : '../includes/admin_header.css'; ?>">
    <style>
        :root {
            --bs-primary: #7b1113;
            --bs-primary-dark: #5e0c0e;
            --bs-primary-light: #94181b;
            --admin-accent: #a03a3c;
            --admin-header-gradient: linear-gradient(135deg, #7b1113 0%, #8e1518 50%, #5d0c0e 100%);
            --card-radius: 1.15rem;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f4f6f9;
            color: #1e293b;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        .profile-wrapper {
            max-width: 1140px;
            margin: 1.75rem auto 3.5rem;
            padding: 0 1rem;
        }

        /* Hero Header Card */
        .admin-hero-card {
            background: var(--admin-header-gradient);
            border-radius: var(--card-radius);
            color: #ffffff;
            padding: 2.25rem 2rem 2rem;
            margin-bottom: 1.75rem;
            box-shadow: 0 12px 32px rgba(123, 17, 19, 0.22);
            position: relative;
            overflow: hidden;
        }

        .admin-hero-card::after {
            content: '✚';
            position: absolute;
            right: 1.5rem;
            bottom: 0.5rem;
            font-size: 8rem;
            color: rgba(255, 255, 255, 0.05);
            pointer-events: none;
            line-height: 1;
        }

        .hero-layout {
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .avatar-container {
            position: relative;
            width: 120px;
            height: 120px;
            flex-shrink: 0;
            margin: 0 auto;
        }

        .avatar-img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255, 255, 255, 0.9);
            background: #ffffff;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.28);
        }

        .avatar-badge-btn {
            position: absolute;
            bottom: 2px;
            right: 2px;
            background: #7b1113;
            color: #ffffff;
            border: 2px solid #ffffff;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.35);
            transition: all 0.2s ease;
        }

        .avatar-badge-btn:hover {
            transform: scale(1.1);
            background: #4a0709;
            color: #ffffff;
        }

        .hero-details {
            flex: 1;
            min-width: 260px;
            text-align: left;
        }

        @media (max-width: 768px) {
            .hero-details {
                text-align: center;
            }
        }

        .admin-name-title {
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 0.25rem;
        }

        .admin-email-text {
            font-size: 0.95rem;
            opacity: 0.92;
            margin-bottom: 0.85rem;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .admin-email-text {
                justify-content: center;
            }
        }

        .pill-badge {
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.28);
            color: #ffffff;
            padding: 5px 14px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            backdrop-filter: blur(4px);
        }

        /* Metric Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.75rem;
        }

        .metric-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 1.15rem 1.25rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.2s ease;
        }

        .metric-card:hover {
            transform: translateY(-2px);
        }

        .metric-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }

        .metric-val {
            font-size: 1.45rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
        }

        .metric-lbl {
            font-size: 0.78rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* Navigation Tabs */
        .profile-nav-tabs {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 1.5rem;
            overflow-x: auto;
            white-space: nowrap;
            padding-bottom: 2px;
        }

        .profile-tab-btn {
            background: none;
            border: none;
            padding: 10px 18px;
            font-size: 0.92rem;
            font-weight: 600;
            color: #64748b;
            border-radius: 10px 10px 0 0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            position: relative;
            transition: all 0.2s ease;
        }

        .profile-tab-btn:hover {
            color: #7b1113;
            background: #fff;
        }

        .profile-tab-btn.active {
            color: #7b1113;
            font-weight: 700;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-bottom: 2px solid #ffffff;
            margin-bottom: -2px;
            box-shadow: 0 -2px 6px rgba(0,0,0,0.02);
        }

        /* Content Panel Card */
        .content-card {
            background: #ffffff;
            border-radius: var(--card-radius);
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
            padding: 1.75rem 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 768px) {
            .content-card {
                padding: 1.25rem 1.25rem;
            }
        }

        .card-header-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 0.9rem;
            margin-bottom: 1.5rem;
        }

        .card-header-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #7b1113;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        /* Detail List Item */
        .detail-item-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .detail-label {
            font-size: 0.82rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .detail-value {
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
        }

        /* Form Controls */
        .form-label {
            font-weight: 600;
            font-size: 0.86rem;
            color: #334155;
            margin-bottom: 0.4rem;
        }

        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            padding: 0.65rem 0.95rem;
            font-size: 0.92rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-control:focus, .form-select:focus {
            border-color: #7b1113;
            box-shadow: 0 0 0 0.22rem rgba(123, 17, 19, 0.18);
        }

        /* Password Strength Meter & Security Checklist */
        .password-strength-container {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
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
        .strength-segment.active-weak { background-color: #ef4444; }
        .strength-segment.active-medium { background-color: #f59e0b; }
        .strength-segment.active-strong { background-color: #10b981; }
        .criteria-item {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.78rem;
            color: #64748b;
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
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 0.35rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            min-height: 1.2rem;
        }
        .password-match-indicator.matching { color: #16a34a; }
        .password-match-indicator.mismatch { color: #dc2626; }

        .btn-burgundy {
            background: #7b1113;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            padding: 0.7rem 1.6rem;
            font-size: 0.92rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }

        .btn-burgundy:hover {
            background: #5e0c0e;
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(123, 17, 19, 0.3);
        }

        /* Activity Timeline */
        .timeline-box {
            position: relative;
            padding-left: 24px;
        }

        .timeline-box::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 6px;
            bottom: 6px;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-node {
            position: relative;
            margin-bottom: 1.25rem;
        }

        .timeline-node::before {
            content: '';
            position: absolute;
            left: -21px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #7b1113;
            border: 2px solid #ffffff;
            box-shadow: 0 0 0 2px #7b1113;
        }

        .timeline-action {
            font-weight: 700;
            color: #0f172a;
            font-size: 0.9rem;
        }

        .timeline-details {
            font-size: 0.84rem;
            color: #475569;
            margin: 2px 0 4px;
        }

        .timeline-time {
            font-size: 0.74rem;
            color: #94a3b8;
            font-weight: 500;
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/../includes/admin_header.php'; ?>

    <main class="profile-wrapper">

        <div class="d-flex align-items-center justify-content-between mb-3">
            <a href="<?php echo htmlspecialchars($adminDashboardUrl); ?>" class="text-decoration-none text-muted small fw-semibold">
                <i class="fas fa-arrow-left me-1"></i> Return to Admin Dashboard
            </a>
            <span class="badge bg-danger text-uppercase px-3 py-2" style="letter-spacing: 0.05em; font-size: 0.75rem; border-radius: 999px;">
                <i class="fas fa-shield-alt me-1"></i> System Administrator
            </span>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-3 shadow-sm" role="alert" style="border-radius: 12px;">
                <i class="fas fa-check-circle fs-5 text-success me-2"></i>
                <div class="fw-semibold"><?php echo htmlspecialchars($success); ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-3 shadow-sm" role="alert" style="border-radius: 12px;">
                <div class="d-flex align-items-center mb-1">
                    <i class="fas fa-exclamation-triangle fs-5 text-danger me-2"></i>
                    <strong>Action Required:</strong>
                </div>
                <ul class="mb-0 ps-4 small">
                    <?php foreach ($errors as $err): ?>
                        <li><?php echo htmlspecialchars($err); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <section class="admin-hero-card">
            <div class="hero-layout">
                <div class="avatar-container">
                    <img src="<?php echo htmlspecialchars($display_picture); ?>" alt="Admin Avatar" class="avatar-img" id="adminAvatarPreview" onerror="this.onerror=null;this.src='uploads/profile_pictures/default_avatar.png'">
                    <label for="admin_avatar_file" class="avatar-badge-btn" title="Change Avatar Image">
                        <i class="fas fa-camera" style="font-size: 0.95rem;"></i>
                    </label>
                </div>

                <form id="avatarUploadForm" method="POST" enctype="multipart/form-data" style="display:none;">
                    <input type="hidden" name="action" value="upload_picture">
                    <input type="file" name="profile_picture" id="admin_avatar_file" accept="image/jpeg,image/jpg,image/png,image/webp" onchange="document.getElementById('avatarUploadForm').submit();">
                </form>

                <div class="hero-details">
                    <h1 class="admin-name-title"><?php echo htmlspecialchars($full_name ?: 'System Administrator'); ?></h1>
                    <div class="admin-email-text">
                        <span><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($admin_user['email']); ?></span>
                        <?php if (!empty($admin_user['email_verified'])): ?>
                            <span class="badge bg-success ms-1" style="font-size: 0.72rem;"><i class="fas fa-check"></i> Verified</span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="pill-badge">
                            <i class="fas fa-user-shield text-warning"></i> Role: Administrator
                        </span>
                        <span class="pill-badge">
                            <i class="fas fa-id-card"></i> Admin ID: #<?php echo htmlspecialchars($admin_user['user_id']); ?>
                        </span>
                        <?php if (!empty($admin_user['phone_no'])): ?>
                            <span class="pill-badge">
                                <i class="fas fa-phone-alt text-info"></i> <?php echo htmlspecialchars($admin_user['phone_no']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="metrics-grid">
            <div class="metric-card">
                <div class="metric-icon" style="background:#e0f2fe; color:#0284c7;">
                    <i class="fas fa-map-marked-alt"></i>
                </div>
                <div>
                    <div class="metric-val"><?php echo number_format($total_plots); ?></div>
                    <div class="metric-lbl">Total Plots</div>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-icon" style="background:#fef3c7; color:#d97706;">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div>
                    <div class="metric-val"><?php echo number_format($total_bookings); ?></div>
                    <div class="metric-lbl">All Bookings</div>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-icon" style="background:#dcfce7; color:#16a34a;">
                    <i class="fas fa-monument"></i>
                </div>
                <div>
                    <div class="metric-val"><?php echo number_format($total_deceased); ?></div>
                    <div class="metric-lbl">Deceased Records</div>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-icon" style="background:#f3e8ff; color:#9333ea;">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div>
                    <div class="metric-val"><?php echo number_format($total_logs_count); ?></div>
                    <div class="metric-lbl">Admin Logs</div>
                </div>
            </div>
        </section>

        <div class="profile-nav-tabs" role="tablist">
            <button type="button" class="profile-tab-btn active" onclick="switchTab('overview', this)">
                <i class="fas fa-id-badge"></i> Account Overview
            </button>
            <button type="button" class="profile-tab-btn" onclick="switchTab('edit', this)">
                <i class="fas fa-user-edit"></i> Edit Profile
            </button>
            <button type="button" class="profile-tab-btn" onclick="switchTab('security', this)">
                <i class="fas fa-key"></i> Password & Security
            </button>
            <button type="button" class="profile-tab-btn" onclick="switchTab('activity', this)">
                <i class="fas fa-history"></i> Activity Logs
            </button>
        </div>

        <div id="tab-overview" class="content-card tab-pane-content">
            <div class="card-header-line">
                <h3 class="card-header-title">
                    <i class="fas fa-user-circle"></i> Administrator Credentials & Details
                </h3>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="switchTab('edit', document.querySelectorAll('.profile-tab-btn')[1])">
                    <i class="fas fa-edit me-1"></i> Edit Info
                </button>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-user me-1"></i> Full Name</span>
                        <span class="detail-value"><?php echo htmlspecialchars($full_name ?: 'Not Provided'); ?></span>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-envelope me-1"></i> Email Address</span>
                        <span class="detail-value text-primary"><?php echo htmlspecialchars($admin_user['email']); ?></span>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-phone me-1"></i> Contact Number</span>
                        <span class="detail-value"><?php echo htmlspecialchars($admin_user['phone_no'] ?: 'None specified'); ?></span>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-birthday-cake me-1"></i> Birthday</span>
                        <span class="detail-value"><?php echo (!empty($admin_user['birthday']) && $admin_user['birthday'] !== '1970-01-01') ? date('F j, Y', strtotime($admin_user['birthday'])) : 'Not Set'; ?></span>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-venus-mars me-1"></i> Gender</span>
                        <span class="detail-value"><?php echo htmlspecialchars($admin_user['gender'] ?: 'Not Specified'); ?></span>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-shield-alt me-1"></i> System Role</span>
                        <span class="badge bg-danger px-2 py-1">ADMINISTRATOR</span>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item-box">
                        <span class="detail-label"><i class="fas fa-lock me-1"></i> Authentication Status</span>
                        <span class="badge bg-success px-2 py-1"><i class="fas fa-check-shield me-1"></i> Active & Protected</span>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="small text-muted">
                    <i class="fas fa-shield-check text-success me-1"></i> Logged in as authorized CMIS Administrator.
                </div>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i> Logout Session
                </a>
            </div>
        </div>

        <div id="tab-edit" class="content-card tab-pane-content" style="display:none;">
            <div class="card-header-line">
                <h3 class="card-header-title">
                    <i class="fas fa-edit"></i> Modify Profile Information
                </h3>
            </div>

            <form method="POST" action="admin_profile.php">
                <input type="hidden" name="action" value="update_profile">

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="first_name">First Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($admin_user['first_name'] ?? ''); ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="middle_name">Middle Name</label>
                        <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($admin_user['middle_name'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="last_name">Last Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($admin_user['last_name'] ?? ''); ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="suffix">Suffix</label>
                        <?php 
                        $curAdminSuffix = strtoupper(trim((string)($admin_user['suffix'] ?? '')));
                        $stdSuffixes = ['JR.', 'SR.', 'II', 'III', 'IV', 'V'];
                        $isAdminOther = !empty($curAdminSuffix) && !in_array($curAdminSuffix, $stdSuffixes, true);
                        ?>
                        <select class="form-select" id="suffix" name="suffix" onchange="toggleSuffixOther(this, 'admin_suffix_other')">
                            <option value="" <?php echo empty($curAdminSuffix) ? 'selected' : ''; ?>>No Suffix</option>
                            <option value="JR." <?php echo ($curAdminSuffix === 'JR.' || $curAdminSuffix === 'JR') ? 'selected' : ''; ?>>JR.</option>
                            <option value="SR." <?php echo ($curAdminSuffix === 'SR.' || $curAdminSuffix === 'SR') ? 'selected' : ''; ?>>SR.</option>
                            <option value="II" <?php echo $curAdminSuffix === 'II' ? 'selected' : ''; ?>>II</option>
                            <option value="III" <?php echo $curAdminSuffix === 'III' ? 'selected' : ''; ?>>III</option>
                            <option value="IV" <?php echo $curAdminSuffix === 'IV' ? 'selected' : ''; ?>>IV</option>
                            <option value="V" <?php echo $curAdminSuffix === 'V' ? 'selected' : ''; ?>>V</option>
                            <option value="OTHER" <?php echo $isAdminOther ? 'selected' : ''; ?>>OTHER</option>
                        </select>
                        <input type="text" class="form-control mt-1" id="admin_suffix_other" name="suffix_other" value="<?php echo $isAdminOther ? htmlspecialchars($curAdminSuffix) : ''; ?>" style="<?php echo $isAdminOther ? 'display:block;' : 'display:none;'; ?>" oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="phone_no">Contact / Phone Number</label>
                        <input type="tel" class="form-control" id="phone_no" name="phone_no" value="<?php echo htmlspecialchars($admin_user['phone_no'] ?? ''); ?>" maxlength="20">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="birthday">Birthday</label>
                        <input type="date" class="form-control" id="birthday" name="birthday" value="<?php echo htmlspecialchars($admin_user['birthday'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="gender">Gender</label>
                        <?php $curAdminGen = ucfirst(strtolower(trim((string)($admin_user['gender'] ?? '')))); ?>
                        <select class="form-select" id="gender" name="gender">
                            <option value="">Not Specified</option>
                            <option value="Male" <?php echo ($curAdminGen === 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($curAdminGen === 'Female') ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                    <button type="button" class="btn btn-light" onclick="switchTab('overview', document.querySelectorAll('.profile-tab-btn')[0])">
                        Cancel
                    </button>
                    <button type="submit" class="btn-burgundy">
                        <i class="fas fa-save me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>

        <div id="tab-security" class="content-card tab-pane-content" style="display:none;">
            <div class="card-header-line">
                <h3 class="card-header-title">
                    <i class="fas fa-key"></i> Update Account Password
                </h3>
            </div>

            <form method="POST" action="admin_profile.php" onsubmit="return validatePasswordForm();">
                <input type="hidden" name="action" value="change_password">

                <div class="row g-3 mb-3">
                    <div class="col-md-12">
                        <label class="form-label" for="current_password">Current Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="current_password" name="current_password" required placeholder="Enter current admin password">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePass('current_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="new_password">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8" placeholder="Create strong password" autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePass('new_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="confirm_password">Confirm New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Re-enter new password" autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePass('confirm_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-12 mt-2">
                        <div class="password-strength-container p-3">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="small fw-bold text-dark"><i class="fas fa-shield-alt text-danger me-1"></i> Password Strength:</span>
                                <span class="badge bg-secondary px-2 py-1" id="adminStrengthBadge">Not Entered</span>
                            </div>
                            <div class="strength-bar-track">
                                <div class="strength-segment" id="adminSeg1"></div>
                                <div class="strength-segment" id="adminSeg2"></div>
                                <div class="strength-segment" id="adminSeg3"></div>
                            </div>
                            <div class="row g-2">
                                <div class="col-sm-6 criteria-item" id="adminCritLen">
                                    <i class="fas fa-circle"></i> <span>At least 8 characters</span>
                                </div>
                                <div class="col-sm-6 criteria-item" id="adminCritCase">
                                    <i class="fas fa-circle"></i> <span>Upper &amp; lowercase letters</span>
                                </div>
                                <div class="col-sm-6 criteria-item" id="adminCritNum">
                                    <i class="fas fa-circle"></i> <span>At least 1 number (0-9)</span>
                                </div>
                                <div class="col-sm-6 criteria-item" id="adminCritSym">
                                    <i class="fas fa-circle"></i> <span>At least 1 symbol (!@#$%^&amp;*)</span>
                                </div>
                            </div>
                        </div>
                        <div class="password-match-indicator mt-2" id="passMatchFeedback"></div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                    <button type="button" class="btn btn-light" onclick="switchTab('overview', document.querySelectorAll('.profile-tab-btn')[0])">
                        Cancel
                    </button>
                    <button type="submit" class="btn-burgundy">
                        <i class="fas fa-shield-alt me-1"></i> Change Password
                    </button>
                </div>
            </form>
        </div>

        <div id="tab-activity" class="content-card tab-pane-content" style="display:none;">
            <div class="card-header-line">
                <h3 class="card-header-title">
                    <i class="fas fa-history"></i> Recent Admin Activity
                </h3>
            </div>

            <?php if (!empty($admin_logs)): ?>
                <div class="timeline-box">
                    <?php foreach ($admin_logs as $log): ?>
                        <div class="timeline-node">
                            <div class="timeline-action"><?php echo htmlspecialchars($log['action']); ?></div>
                            <div class="timeline-details"><?php echo htmlspecialchars($log['details']); ?></div>
                            <div class="timeline-time"><i class="far fa-clock me-1"></i> <?php echo date('M j, Y • h:i A', strtotime($log['timestamp'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-clipboard-check fs-2 mb-2 d-block opacity-50"></i>
                    <p class="mb-0">No recent activity logs recorded for this account.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function switchTab(tabName, btnEl) {
            document.querySelectorAll('.tab-pane-content').forEach(p => p.style.display = 'none');
            document.querySelectorAll('.profile-tab-btn').forEach(b => b.classList.remove('active'));

            const targetPane = document.getElementById('tab-' + tabName);
            if (targetPane) targetPane.style.display = 'block';
            if (btnEl) btnEl.classList.add('active');
        }

        function togglePass(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                if (icon) { icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
            } else {
                input.type = 'password';
                if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
            }
        }

        function toggleSuffixOther(sel, otherId) {
            const other = document.getElementById(otherId);
            if (!other) return;
            if (sel.value === 'OTHER') {
                other.style.display = 'block';
                other.focus();
            } else {
                other.style.display = 'none';
                other.value = '';
            }
        }

        // Live Password Strength and Match Evaluator for Admin Profile
        const adminNewPass = document.getElementById('new_password');
        const adminConfPass = document.getElementById('confirm_password');
        const adminBadge = document.getElementById('adminStrengthBadge');
        const adminSeg1 = document.getElementById('adminSeg1');
        const adminSeg2 = document.getElementById('adminSeg2');
        const adminSeg3 = document.getElementById('adminSeg3');
        const adminMatchFeedback = document.getElementById('passMatchFeedback');

        const aCritLen = document.getElementById('adminCritLen');
        const aCritCase = document.getElementById('adminCritCase');
        const aCritNum = document.getElementById('adminCritNum');
        const aCritSym = document.getElementById('adminCritSym');

        function updateAdminCrit(el, isMet) {
            if (!el) return;
            const icon = el.querySelector('i');
            if (isMet) {
                el.classList.add('met');
                if (icon) icon.className = 'fas fa-check-circle text-success';
            } else {
                el.classList.remove('met');
                if (icon) icon.className = 'fas fa-circle text-muted opacity-50';
            }
        }

        function evaluateAdminStrength(pwd) {
            if (!pwd || pwd.length === 0) {
                updateAdminCrit(aCritLen, false);
                updateAdminCrit(aCritCase, false);
                updateAdminCrit(aCritNum, false);
                updateAdminCrit(aCritSym, false);
                if (adminSeg1) adminSeg1.className = 'strength-segment';
                if (adminSeg2) adminSeg2.className = 'strength-segment';
                if (adminSeg3) adminSeg3.className = 'strength-segment';
                if (adminBadge) {
                    adminBadge.className = 'badge bg-secondary px-2 py-1';
                    adminBadge.textContent = 'Not Entered';
                }
                return;
            }

            const hasLen = pwd.length >= 8;
            const hasCase = /[A-Z]/.test(pwd) && /[a-z]/.test(pwd);
            const hasNum = /[0-9]/.test(pwd);
            const hasSym = /[^A-Za-z0-9]/.test(pwd);

            updateAdminCrit(aCritLen, hasLen);
            updateAdminCrit(aCritCase, hasCase);
            updateAdminCrit(aCritNum, hasNum);
            updateAdminCrit(aCritSym, hasSym);

            let score = 0;
            if (hasLen) score++;
            if (hasCase) score++;
            if (hasNum) score++;
            if (hasSym) score++;
            if (pwd.length >= 12) score++;

            if (adminSeg1) adminSeg1.className = 'strength-segment';
            if (adminSeg2) adminSeg2.className = 'strength-segment';
            if (adminSeg3) adminSeg3.className = 'strength-segment';

            if (!hasLen || score <= 2) {
                if (adminSeg1) adminSeg1.classList.add('active-weak');
                if (adminBadge) {
                    adminBadge.className = 'badge bg-danger px-2 py-1';
                    adminBadge.textContent = 'Weak';
                }
            } else if (score === 3 || score === 4) {
                if (adminSeg1) adminSeg1.classList.add('active-medium');
                if (adminSeg2) adminSeg2.classList.add('active-medium');
                if (adminBadge) {
                    adminBadge.className = 'badge bg-warning text-dark px-2 py-1';
                    adminBadge.textContent = 'Medium';
                }
            } else {
                if (adminSeg1) adminSeg1.classList.add('active-strong');
                if (adminSeg2) adminSeg2.classList.add('active-strong');
                if (adminSeg3) adminSeg3.classList.add('active-strong');
                if (adminBadge) {
                    adminBadge.className = 'badge bg-success px-2 py-1';
                    adminBadge.textContent = 'Strong';
                }
            }
        }

        function evaluateAdminMatch() {
            if (!adminConfPass || !adminMatchFeedback) return;
            const p1 = adminNewPass ? adminNewPass.value : '';
            const p2 = adminConfPass.value;

            if (!p2 || p2.length === 0) {
                adminMatchFeedback.textContent = '';
                adminMatchFeedback.className = 'password-match-indicator mt-2';
                return;
            }

            if (p1 === p2) {
                adminMatchFeedback.textContent = '✓ Passwords match';
                adminMatchFeedback.className = 'password-match-indicator matching mt-2';
            } else {
                adminMatchFeedback.textContent = '✗ Passwords do not match';
                adminMatchFeedback.className = 'password-match-indicator mismatch mt-2';
            }
        }

        if (adminNewPass) {
            adminNewPass.addEventListener('input', function() {
                evaluateAdminStrength(this.value);
                evaluateAdminMatch();
            });
        }

        if (adminConfPass) {
            adminConfPass.addEventListener('input', evaluateAdminMatch);
        }

        function validatePasswordForm() {
            const p1 = document.getElementById('new_password').value;
            const p2 = document.getElementById('confirm_password').value;
            if (p1.length < 8) {
                alert('New password must be at least 8 characters long.');
                return false;
            }
            if (!/[A-Z]/.test(p1) || !/[a-z]/.test(p1)) {
                alert('New password must contain both uppercase and lowercase letters.');
                return false;
            }
            if (!/[0-9]/.test(p1)) {
                alert('New password must contain at least one number (0-9).');
                return false;
            }
            if (!/[^A-Za-z0-9]/.test(p1)) {
                alert('New password must contain at least one symbol or special character (e.g. !@#$%^&*).');
                return false;
            }
            if (p1 !== p2) {
                alert('New password and confirmation password do not match.');
                return false;
            }
            return true;
        }
    </script>
</body>
</html>

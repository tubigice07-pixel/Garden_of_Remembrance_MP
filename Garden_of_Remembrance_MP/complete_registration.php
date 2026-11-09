<?php

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/connection.php';

if (isset($conn) && ($conn instanceof mysqli)) {
    ensure_guest_reservation_columns($conn);
}

$token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$errorMessage = '';
$successMessage = '';
$booking = null;
$accountCreated = false;

if (!function_exists('validate_registration_token')) {
    function validate_registration_token($conn, $token) {
        if (empty($token) || !preg_match('/^[a-f0-9]{32,128}$/i', $token)) {
            return [false, null, 'Invalid token format.'];
        }

        $chkGrr = $conn->prepare("SELECT grr.*, b.id AS linked_b_id FROM guest_reservation_requests grr LEFT JOIN tbl_bookings b ON b.id = grr.booking_id WHERE grr.account_creation_token_hash = ? LIMIT 1");
        if ($chkGrr) {
            $chkGrr->bind_param('s', $token);
            $chkGrr->execute();
            $grrRes = $chkGrr->get_result();
            if ($grrRes && $grrRes->num_rows > 0) {
                $grr = $grrRes->fetch_assoc();
                $chkGrr->close();

                $status = strtolower(trim((string)($grr['status'] ?? '')));
                if ($status !== 'approved') {
                    return [false, null, 'Reservation is not in approved status (Current status: ' . htmlspecialchars($grr['status']) . ').'];
                }
                if (in_array($status, ['cancelled', 'rejected'])) {
                    return [false, null, 'Reservation was cancelled or rejected.'];
                }
                if (!empty($grr['token_used_at']) || $status === 'account created') {
                    return [false, null, 'This account creation token has already been used.'];
                }
                if (!empty($grr['user_id']) && (int)$grr['user_id'] > 0) {
                    return [false, null, 'An account has already been linked to this reservation.'];
                }
                if (!empty($grr['token_expires_at'])) {
                    $expiresTimestamp = strtotime($grr['token_expires_at']);
                    if ($expiresTimestamp !== false && $expiresTimestamp < time()) {
                        return [false, null, 'This registration token has expired.'];
                    }
                }

                $mapped = [
                    'id' => (int)($grr['booking_id'] ?? 0),
                    'guest_request_id' => (int)$grr['id'],
                    'customer_name' => $grr['full_name'],
                    'full_name' => $grr['full_name'],
                    'customer_email' => $grr['email'],
                    'email' => $grr['email'],
                    'contact_number' => $grr['contact_number'],
                    'section' => $grr['section'],
                    'plot' => !empty($grr['plot_code']) ? $grr['plot_code'] : ($grr['plot_x'] . '-' . $grr['plot_y']),
                    'plot_x' => (int)$grr['plot_x'],
                    'plot_y' => (int)$grr['plot_y'],
                    'purpose' => $grr['purpose'],
                    'status' => $grr['status'],
                    'token_expires_at' => $grr['token_expires_at'],
                    'token_used' => !empty($grr['token_used_at']) ? 1 : 0,
                    'user_id' => (int)($grr['user_id'] ?? 0)
                ];
                return [true, $mapped, ''];
            }
            $chkGrr->close();
        }

        $stmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE registration_token = ? LIMIT 1");
        if (!$stmt) {
            return [false, null, 'Database query failed.'];
        }
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $res = $stmt->get_result();
        $b = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$b) {
            return [false, null, 'Token not found.'];
        }

        $status = strtolower(trim((string)($b['status'] ?? '')));
        if ($status !== 'approved') {
            return [false, null, 'Reservation is not in approved status.'];
        }

        if (in_array($status, ['cancelled', 'rejected'])) {
            return [false, null, 'Reservation was cancelled or rejected.'];
        }

        if (!empty($b['token_used']) && (int)$b['token_used'] === 1) {
            return [false, null, 'This account creation token has already been used.'];
        }

        if (!empty($b['user_id']) && (int)$b['user_id'] > 0) {
            return [false, null, 'An account has already been linked to this reservation.'];
        }

        if (!empty($b['token_expires_at'])) {
            $expiresTimestamp = strtotime($b['token_expires_at']);
            if ($expiresTimestamp !== false && $expiresTimestamp < time()) {
                return [false, null, 'This registration token has expired.'];
            }
        }

        return [true, $b, ''];
    }
}

if (empty($token)) {
    $errorMessage = "This account creation link is invalid or has expired. Please contact Garden of Remembrance Memorial Park.";
} else {
    list($isValid, $bookingData, $tokenReason) = validate_registration_token($conn, $token);
    if (!$isValid) {
        $errorMessage = "This account creation link is invalid or has expired. Please contact Garden of Remembrance Memorial Park.";
    } else {
        $booking = $bookingData;
    }
}

$reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($reqMethod === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_account') {
    if (!$booking) {
        $errorMessage = "This account creation link is invalid or has expired. Please contact Garden of Remembrance Memorial Park.";
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $customerEmail = strtolower(trim((string)($booking['customer_email'] ?? ($booking['email'] ?? ''))));
        $customerName = trim((string)($booking['customer_name'] ?? ($booking['full_name'] ?? '')));
        $contactNumber = trim((string)($booking['contact_number'] ?? ''));
        $section = strtoupper(trim((string)($booking['section'] ?? '')));
        $plot = trim((string)($booking['plot'] ?? ''));

        if (strlen($password) < 8) {
            $formError = "Password must be at least 8 characters in length.";
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $formError = "Password must contain at least one uppercase letter (A-Z).";
        } elseif (!preg_match('/[a-z]/', $password)) {
            $formError = "Password must contain at least one lowercase letter (a-z).";
        } elseif (!preg_match('/[0-9]/', $password)) {
            $formError = "Password must contain at least one number (0-9).";
        } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $formError = "Password must contain at least one symbol or special character (e.g. !@#$%^&*).";
        } elseif ($password !== $confirmPassword) {
            $formError = "Passwords do not match. Please verify and try again.";
        } elseif (empty($customerEmail) || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $formError = "A valid email address could not be found for this reservation.";
        } else {

            $emailChk = $conn->prepare("SELECT user_id FROM tbl_user WHERE LOWER(TRIM(email)) = ? LIMIT 1");
            $emailChk->bind_param('s', $customerEmail);
            $emailChk->execute();
            $emailRes = $emailChk->get_result();
            if ($emailRes && $emailRes->num_rows > 0) {
                $emailChk->close();
                $formError = "This email address (" . htmlspecialchars($customerEmail) . ") is already registered. Please log in with your existing account or contact administration.";
            } else {
                $emailChk->close();

                $parts = preg_split('/\s+/', $customerName);
                $firstName = !empty($parts[0]) ? $parts[0] : 'User';
                $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : $firstName;

                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

                $userRole = 'USER';
                $emailVerified = 1;
                $defaultBday = '1990-01-01';
                $emptySuffix = '';

                $uStmt = $conn->prepare("INSERT INTO tbl_user 
                    (role, first_name, last_name, suffix, birthday, email, password, phone_no, email_verified, verification_attempts)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");

                if (!$uStmt) {
                    $formError = "Database prepare error: " . $conn->error;
                } else {
                    $uStmt->bind_param(
                        'ssssssssi',
                        $userRole, $firstName, $lastName, $emptySuffix, $defaultBday,
                        $customerEmail, $hashedPassword, $contactNumber, $emailVerified
                    );

                    if (!$uStmt->execute()) {
                        $formError = "Account creation failed: " . $uStmt->error;
                        $uStmt->close();
                    } else {
                        $newUserId = (int)$uStmt->insert_id;
                        $uStmt->close();

                        $updBk = $conn->prepare("UPDATE tbl_bookings 
                            SET user_id = ?, is_guest_booking = 0, token_used = 1, token_used_at = NOW() 
                            WHERE id = ?");
                        if ($updBk) {
                            $bId = (int)$booking['id'];
                            $updBk->bind_param('ii', $newUserId, $bId);
                            $updBk->execute();
                            $updBk->close();
                        }

                        $bId = (int)($booking['id'] ?? 0);
                        $grrId = (int)($booking['guest_request_id'] ?? 0);
                        $updGrr = $conn->prepare("UPDATE guest_reservation_requests 
                            SET user_id = ?, status = 'Account Created', account_created_at = NOW(), token_used_at = NOW() 
                            WHERE id = ? OR account_creation_token_hash = ? OR (? > 0 AND booking_id = ?)");
                        if ($updGrr) {
                            $updGrr->bind_param('iissi', $newUserId, $grrId, $token, $bId, $bId);
                            $updGrr->execute();
                            $updGrr->close();
                        }

                        $plotParts = explode('-', str_replace(',', '-', $plot));
                        $px = (int)($plotParts[0] ?? 0);
                        $py = (int)($plotParts[1] ?? 0);

                        if ($px > 0 && $py > 0 && !empty($section)) {

                            $poChk = $conn->prepare("SELECT id FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
                            if ($poChk) {
                                $poChk->bind_param('sii', $section, $px, $py);
                                $poChk->execute();
                                $poRes = $poChk->get_result();
                                if ($poRes && $poRes->num_rows > 0) {
                                    $poRow = $poRes->fetch_assoc();
                                    $poId = (int)$poRow['id'];
                                    $poUpd = $conn->prepare("UPDATE tbl_plot_owners SET reserved_by = ?, owner_name = ?, active = 1 WHERE id = ?");
                                    if ($poUpd) {
                                        $poUpd->bind_param('isi', $newUserId, $customerName, $poId);
                                        $poUpd->execute();
                                        $poUpd->close();
                                    }
                                } else {
                                    $poIns = $conn->prepare("INSERT INTO tbl_plot_owners (section, plot_x, plot_y, owner_name, first_name, last_name, phone_no, reserved_by, active, reserved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
                                    if ($poIns) {
                                        $poIns->bind_param('siissssi', $section, $px, $py, $customerName, $firstName, $lastName, $contactNumber, $newUserId);
                                        $poIns->execute();
                                        $poIns->close();
                                    }
                                }
                                $poChk->close();
                            }

                            $ensureTable = ensure_user_plot_ownership_table($conn);
                            $upoStmt = $conn->prepare("INSERT INTO user_plot_ownership (user_id, section, plot_x, plot_y, ownership_status, verification_status, claim_source, notes, verified_at)
                                VALUES (?, ?, ?, ?, 'RESERVED', 'VERIFIED', 'BOOKING', 'Created via approved guest reservation', NOW())
                                ON DUPLICATE KEY UPDATE ownership_status = 'RESERVED', verification_status = 'VERIFIED', verified_at = NOW()");
                            if ($upoStmt) {
                                $upoStmt->bind_param('isii', $newUserId, $section, $px, $py);
                                $upoStmt->execute();
                                $upoStmt->close();
                            }
                        }

                        if ($grrId > 0) {
                            $grrDecChk = $conn->prepare("SELECT matched_deceased_id, deceased_relationship FROM guest_reservation_requests WHERE id = ? LIMIT 1");
                            if ($grrDecChk) {
                                $grrDecChk->bind_param('i', $grrId);
                                $grrDecChk->execute();
                                $grrDecRow = $grrDecChk->get_result()->fetch_assoc();
                                $grrDecChk->close();
                                if ($grrDecRow && !empty($grrDecRow['matched_deceased_id'])) {
                                    $mId = (int)$grrDecRow['matched_deceased_id'];
                                    $rel = trim((string)($grrDecRow['deceased_relationship'] ?? ''));
                                    $linkDec = $conn->prepare("UPDATE tbl_deceased SET user_id = ?, relationship_to_account_holder = COALESCE(NULLIF(relationship_to_account_holder, ''), ?) WHERE deceased_id = ?");
                                    if ($linkDec) {
                                        $linkDec->bind_param('isi', $newUserId, $rel, $mId);
                                        $linkDec->execute();
                                        $linkDec->close();
                                    }
                                }
                            }
                        }
                        if ($px > 0 && $py > 0 && !empty($section)) {
                            $linkPlotDec = $conn->prepare("UPDATE tbl_deceased SET user_id = ? WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND (user_id IS NULL OR user_id = 0)");
                            if ($linkPlotDec) {
                                $linkPlotDec->bind_param('isii', $newUserId, $section, $px, $py);
                                $linkPlotDec->execute();
                                $linkPlotDec->close();
                            }
                        }

                        $logDetails = "Guest reservation #{$booking['id']} converted to User #{$newUserId} ({$customerEmail}) for Section {$section} Plot {$plot}.";
                        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('GUEST_ACCOUNT_CREATED', ?, ?, NOW())");
                        if ($logStmt) {
                            $logStmt->bind_param('si', $logDetails, $newUserId);
                            $logStmt->execute();
                            $logStmt->close();
                        }

                        $adminQ = $conn->query("SELECT user_id FROM tbl_user WHERE UPPER(TRIM(COALESCE(role, ''))) = 'ADMIN'");
                        if ($adminQ && $adminQ->num_rows > 0) {
                            $notifTitle = "Guest Account Created";
                            $notifMsg = "Guest {$customerName} has completed account registration for Section {$section} Plot {$plot} (User #{$newUserId}).";
                            $notifLink = "guest_reservations.php?status=Account+Created&search=" . urlencode($customerName);
                            $notifType = "guest_account_created";

                            $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, booking_id, type, title, message, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())");
                            if ($notifStmt) {
                                while ($adm = $adminQ->fetch_assoc()) {
                                    $admId = (int)$adm['user_id'];
                                    if ($admId > 0) {
                                        $notifStmt->bind_param('iissss', $admId, $bId, $notifType, $notifTitle, $notifMsg, $notifLink);
                                        $notifStmt->execute();
                                    }
                                }
                                $notifStmt->close();
                            }
                        }

                        $userNotifStmt = $conn->prepare("INSERT INTO notifications (user_id, booking_id, type, title, message, link, is_read, created_at) VALUES (?, ?, 'welcome_user', 'Welcome to Garden of Remembrance', ?, 'user_dashboard.php', 0, NOW())");
                        if ($userNotifStmt) {
                            $uWelcomeMsg = "Welcome, {$customerName}! Your account has been created successfully. Your reservation for Plot {$section}-{$plot} is active in your dashboard.";
                            $userNotifStmt->bind_param('iis', $newUserId, $bId, $uWelcomeMsg);
                            $userNotifStmt->execute();
                            $userNotifStmt->close();
                        }

                        $_SESSION['user_id'] = $newUserId;
                        $_SESSION['email'] = $customerEmail;
                        $_SESSION['role'] = 'USER';
                        $_SESSION['first_name'] = $firstName;
                        $_SESSION['last_name'] = $lastName;

                        $accountCreated = true;
                        $successMessage = "Your profile has been completed successfully! Your reservation for Plot {$section}-{$plot} is now linked to your account.";
                    }
                }
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
  <title>Complete Your Profile · Garden of Remembrance Memorial Park</title>
  <link rel="icon" href="favicon.ico" type="image/x-icon">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary-maroon: #7b1113;
      --primary-dark: #580c0d;
      --accent-gold: #c5a059;
      --bg-cream: #faf7f2;
    }
    body {
      font-family: 'Inter', sans-serif;
      background: url('garden of remembrance background.webp') center/cover no-repeat fixed;
      color: #2b211f;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 2rem 1rem;
      position: relative;
    }
    body::before {
      content: "";
      position: fixed;
      inset: 0;
      background: linear-gradient(135deg, rgba(20, 10, 10, 0.75) 0%, rgba(40, 15, 16, 0.82) 100%);
      backdrop-filter: blur(5px);
      -webkit-backdrop-filter: blur(5px);
      z-index: 0;
    }
    .registration-card {
      position: relative;
      z-index: 1;
      background: #ffffff;
      border-radius: 20px;
      box-shadow: 0 25px 70px rgba(0,0,0,0.35);
      border: 1px solid rgba(197, 160, 89, 0.35);
      overflow: hidden;
      max-width: 580px;
      margin: 0 auto;
      width: 100%;
    }
    .card-header-brand {
      background: linear-gradient(135deg, var(--primary-maroon) 0%, var(--primary-dark) 100%);
      color: #ffffff;
      padding: 2.2rem 2rem 1.8rem;
      text-align: center;
      position: relative;
    }
    .brand-title {
      font-family: 'Cinzel', serif;
      font-weight: 700;
      letter-spacing: 0.05em;
      margin-bottom: 0.35rem;
      font-size: 1.45rem;
    }
    .brand-subtitle {
      font-size: 0.85rem;
      color: rgba(255,255,255,0.85);
      letter-spacing: 0.03em;
    }
    .card-body-content {
      padding: 2.2rem 2rem;
    }
    .form-control-readonly {
      background-color: #f8fafc !important;
      border-color: #e2e8f0 !important;
      color: #334155 !important;
      font-weight: 500;
      cursor: not-allowed;
    }
    .btn-create {
      background: linear-gradient(135deg, var(--primary-maroon) 0%, #94171a 100%);
      border: none;
      color: #ffffff;
      padding: 0.85rem 1.5rem;
      font-size: 1.05rem;
      font-weight: 600;
      border-radius: 12px;
      letter-spacing: 0.03em;
      transition: all 0.2s ease-in-out;
      box-shadow: 0 4px 15px rgba(123, 17, 19, 0.25);
    }
    .btn-create:hover {
      background: linear-gradient(135deg, #660e10 0%, var(--primary-maroon) 100%);
      color: #ffffff;
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(123, 17, 19, 0.35);
    }
    .plot-pill {
      background: #fdf2f2;
      border: 1px solid rgba(123, 17, 19, 0.2);
      border-radius: 12px;
      padding: 0.9rem 1.1rem;
      margin-bottom: 1.4rem;
    }
    .password-req {
      font-size: 0.78rem;
      color: #64748b;
      margin-top: 0.35rem;
    }
    .password-req.valid {
      color: #16a34a;
    }
    .password-toggle-btn {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: #94a3b8;
      cursor: pointer;
      padding: 0;
      font-size: 0.95rem;
    }
    .password-toggle-btn:hover {
      color: #475569;
    }
    .summary-item {
      display: flex;
      flex-direction: column;
      gap: 0.15rem;
    }
    .summary-label {
      font-size: 0.75rem;
      color: #64748b;
      text-transform: uppercase;
      font-weight: 600;
      letter-spacing: 0.04em;
    }
    .summary-value {
      font-size: 0.92rem;
      font-weight: 600;
      color: #1e293b;
    }
    .badge-status-approved {
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid #a7f3d0;
      border-radius: 999px;
      padding: 0.35rem 0.85rem;
      font-size: 0.75rem;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
  </style>
</head>
<body>

  <div class="container">
    <?php if (!empty($errorMessage)): ?>

      <div class="error-card">
        <div class="mb-3">
          <img src="cmis_logo.png" alt="Garden of Remembrance Logo" style="height: 58px; width: auto; object-fit: contain;">
        </div>
        <div class="mb-3 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 76px; height: 76px; background: #fef2f2; color: #dc2626;">
          <i class="fas fa-exclamation-triangle" style="font-size: 2.2rem;"></i>
        </div>
        <h4 class="fw-bold mb-3" style="color: #1e293b;">Link Unavailable</h4>
        <p class="text-muted mb-4" style="font-size: 1.05rem; line-height: 1.6;">
          <?php echo htmlspecialchars($errorMessage); ?>
        </p>
        <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
          <a href="index.php" class="btn btn-outline-secondary px-4 py-2" style="border-radius: 10px;">
            <i class="fas fa-home me-1"></i> Return to Home
          </a>
          <a href="login.php" class="btn btn-primary px-4 py-2" style="background-color: var(--primary-maroon); border-color: var(--primary-maroon); border-radius: 10px;">
            <i class="fas fa-sign-in-alt me-1"></i> Go to Login
          </a>
        </div>
      </div>

    <?php elseif ($accountCreated): ?>

      <div class="registration-card text-center">
        <div class="card-header-brand">
          <div class="mb-3">
            <img src="cmis_logo.png" alt="Garden of Remembrance Logo" style="height: 56px; width: auto; object-fit: contain; filter: drop-shadow(0 2px 8px rgba(0,0,0,0.3));">
          </div>
          <div class="mb-2">
            <i class="fas fa-check-circle" style="font-size: 2.8rem; color: #4ade80;"></i>
          </div>
          <h3 class="brand-title">Account Created!</h3>
          <p class="brand-subtitle m-0">Welcome to Garden of Remembrance Memorial Park</p>
        </div>
        <div class="card-body-content">
          <div class="alert alert-success border-0 py-3 mb-4" style="background: #f0fdf4; color: #166534; border-radius: 12px;">
            <i class="fas fa-shield-alt me-1"></i> <?php echo htmlspecialchars($successMessage); ?>
          </div>

          <p class="text-muted mb-4" style="font-size: 0.98rem; line-height: 1.6;">
            Your account is now fully active. You can now access your member dashboard, track payments, and manage your reservation details.
          </p>

          <div class="d-grid gap-2">
            <a href="user_dashboard.php" class="btn btn-create">
              <i class="fas fa-columns me-2"></i> Open My User Dashboard
            </a>
          </div>
        </div>
      </div>

    <?php else: ?>

      <div class="registration-card">
        <div class="card-header-brand">
          <div class="mb-3">
            <img src="cmis_logo.png" alt="Garden of Remembrance Logo" style="height: 58px; width: auto; object-fit: contain; filter: drop-shadow(0 2px 8px rgba(0,0,0,0.35));">
          </div>
          <h2 class="brand-title">COMPLETE YOUR PROFILE</h2>
          <p class="brand-subtitle m-0">Set your password to finalize your memorial plot reservation</p>
        </div>

        <div class="card-body-content">
          <?php if (!empty($formError)): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" style="border-radius: 10px; font-size: 0.95rem;">
              <i class="fas fa-exclamation-circle flex-shrink-0"></i>
              <div><?php echo htmlspecialchars($formError); ?></div>
            </div>
          <?php endif; ?>

          <div class="p-3 mb-4 rounded border d-flex align-items-center justify-content-between" style="background: #faf8f5; border-color: rgba(123,17,19,0.15) !important;">
            <div>
              <div class="text-muted small text-uppercase fw-bold" style="font-size: 0.72rem;">Approved Reservation</div>
              <div class="fw-bold text-dark font-monospace" style="font-size: 1.05rem;">
                Plot <?php echo htmlspecialchars(($booking['section'] ?? '') . '-' . ($booking['plot'] ?? '')); ?>
              </div>
              <div class="text-muted small" style="font-size: 0.8rem;">
                <?php echo htmlspecialchars($booking['purpose'] ?? 'Reserve New Grave'); ?>
              </div>
            </div>
            <span class="badge px-3 py-2" style="background: rgba(16, 185, 129, 0.15); color: #065f46; font-size: 0.82rem; font-weight: 600; border-radius: 999px;">
              <i class="fas fa-check me-1"></i> APPROVED
            </span>
          </div>

          <form method="POST" action="complete_registration.php" onsubmit="return validatePasswords();">
            <input type="hidden" name="action" value="create_account">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

            <div class="row g-3 mb-3">
              <div class="col-12">
                <label class="form-label text-dark fw-semibold small mb-1">
                  <i class="fas fa-user text-muted me-1"></i> Full Name
                </label>
                <div class="input-group">
                  <input type="text" class="form-control form-control-readonly" value="<?php echo htmlspecialchars($booking['customer_name'] ?? ''); ?>" readonly>
                  <span class="input-group-text bg-light text-muted"><i class="fas fa-lock small"></i></span>
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label text-dark fw-semibold small mb-1">
                  <i class="fas fa-envelope text-muted me-1"></i> Email Address
                </label>
                <div class="input-group">
                  <input type="email" class="form-control form-control-readonly" value="<?php echo htmlspecialchars($booking['customer_email'] ?: ($booking['email'] ?? '')); ?>" readonly>
                  <span class="input-group-text bg-light text-muted"><i class="fas fa-lock small"></i></span>
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label text-dark fw-semibold small mb-1">
                  <i class="fas fa-phone text-muted me-1"></i> Contact Number
                </label>
                <div class="input-group">
                  <input type="text" class="form-control form-control-readonly" value="<?php echo htmlspecialchars($booking['contact_number'] ?? ''); ?>" readonly>
                  <span class="input-group-text bg-light text-muted"><i class="fas fa-lock small"></i></span>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label text-dark fw-semibold small mb-1">
                  <i class="fas fa-map-marker-alt text-muted me-1"></i> Selected Plot
                </label>
                <div class="input-group">
                  <input type="text" class="form-control form-control-readonly" value="<?php echo htmlspecialchars(($booking['section'] ?? '') . '-' . ($booking['plot'] ?? '')); ?>" readonly>
                  <span class="input-group-text bg-light text-muted"><i class="fas fa-lock small"></i></span>
                </div>
              </div>
            </div>

            <hr class="my-4" style="opacity: 0.12;">

            <div class="mb-3">
              <label for="regPassword" class="form-label text-dark fw-semibold small mb-1">
                <i class="fas fa-key text-muted me-1"></i> Strong Password <span class="text-danger">*</span>
              </label>
              <div class="input-group">
                <input type="password" class="form-control" id="regPassword" name="password" required minlength="8" placeholder="Create a strong password" style="border-radius: 10px 0 0 10px;">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePassVisibility('regPassword', this)" style="border-radius: 0 10px 10px 0;">
                  <i class="fas fa-eye"></i>
                </button>
              </div>

              <div class="p-3 my-2 rounded bg-light border" style="font-size: 0.76rem; border-color: #e5dbcf !important;">
                <div class="fw-bold text-muted text-uppercase mb-2" style="font-size: 0.68rem; letter-spacing: 0.04em;">Password Requirements:</div>
                <div class="row g-1">
                  <div class="col-sm-6" id="rule-len" style="color: #dc2626;">
                    <i class="fas fa-circle-xmark me-1"></i> At least 8 characters
                  </div>
                  <div class="col-sm-6" id="rule-upper" style="color: #dc2626;">
                    <i class="fas fa-circle-xmark me-1"></i> Uppercase letter (A-Z)
                  </div>
                  <div class="col-sm-6" id="rule-lower" style="color: #dc2626;">
                    <i class="fas fa-circle-xmark me-1"></i> Lowercase letter (a-z)
                  </div>
                  <div class="col-sm-6" id="rule-num" style="color: #dc2626;">
                    <i class="fas fa-circle-xmark me-1"></i> At least one number (0-9)
                  </div>
                  <div class="col-12" id="rule-sym" style="color: #dc2626;">
                    <i class="fas fa-circle-xmark me-1"></i> Symbol / special char (!@#$%^&* etc.)
                  </div>
                </div>
              </div>
            </div>

            <div class="mb-4">
              <label for="regConfirmPassword" class="form-label text-dark fw-semibold small mb-1">
                <i class="fas fa-shield-alt text-muted me-1"></i> Confirm Password <span class="text-danger">*</span>
              </label>
              <div class="input-group">
                <input type="password" class="form-control" id="regConfirmPassword" name="confirm_password" required minlength="8" placeholder="Confirm your password" style="border-radius: 10px 0 0 10px;">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePassVisibility('regConfirmPassword', this)" style="border-radius: 0 10px 10px 0;">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
              <div id="passwordMatchMessage" class="small mt-1" style="display: none; font-size: 0.78rem;"></div>
            </div>

            <div class="d-grid">
              <button type="submit" id="createAccountBtn" class="btn btn-create">
                <i class="fas fa-check-circle me-2"></i> COMPLETE PROFILE
              </button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    function togglePassVisibility(inputId, btn) {
      var input = document.getElementById(inputId);
      var icon = btn.querySelector('i');
      if (!input) return;
      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      }
    }

    function updatePasswordRules(p1) {
      var hasLen = p1.length >= 8;
      var hasUpper = /[A-Z]/.test(p1);
      var hasLower = /[a-z]/.test(p1);
      var hasNum = /[0-9]/.test(p1);
      var hasSym = /[^A-Za-z0-9]/.test(p1);

      function setRule(id, valid) {
        var el = document.getElementById(id);
        if (!el) return;
        var icon = el.querySelector('i');
        if (valid) {
          el.style.color = '#16a34a';
          if (icon) { icon.className = 'fas fa-circle-check me-1'; }
        } else {
          el.style.color = '#dc2626';
          if (icon) { icon.className = 'fas fa-circle-xmark me-1'; }
        }
      }

      setRule('rule-len', hasLen);
      setRule('rule-upper', hasUpper);
      setRule('rule-lower', hasLower);
      setRule('rule-num', hasNum);
      setRule('rule-sym', hasSym);

      return hasLen && hasUpper && hasLower && hasNum && hasSym;
    }

    function validatePasswords() {
      var p1 = document.getElementById('regPassword').value;
      var p2 = document.getElementById('regConfirmPassword').value;
      var msg = document.getElementById('passwordMatchMessage');

      if (!updatePasswordRules(p1)) {
        if (msg) {
          msg.textContent = 'Please satisfy all strong password requirements (uppercase, lowercase, number, and symbol).';
          msg.className = 'small mt-1 text-danger';
          msg.style.display = 'block';
        }
        return false;
      }

      if (p1 !== p2) {
        if (msg) {
          msg.textContent = 'Passwords do not match.';
          msg.className = 'small mt-1 text-danger';
          msg.style.display = 'block';
        }
        return false;
      }
      return true;
    }

    document.addEventListener('DOMContentLoaded', function() {
      var p1 = document.getElementById('regPassword');
      var p2 = document.getElementById('regConfirmPassword');
      var msg = document.getElementById('passwordMatchMessage');

      function checkMatch() {
        if (!p2 || !p1 || !msg) return;
        if (p2.value.length === 0) {
          msg.style.display = 'none';
          return;
        }
        if (p1.value === p2.value) {
          msg.textContent = 'Passwords match!';
          msg.className = 'small mt-1 text-success';
          msg.style.display = 'block';
        } else {
          msg.textContent = 'Passwords do not match.';
          msg.className = 'small mt-1 text-danger';
          msg.style.display = 'block';
        }
      }

      if (p1) {
        p1.addEventListener('input', function() {
          updatePasswordRules(p1.value);
          checkMatch();
        });
      }
      if (p2) {
        p2.addEventListener('input', checkMatch);
      }
    });
  </script>
</body>
</html>

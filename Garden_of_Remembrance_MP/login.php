<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!empty($_SESSION['user_id'])) {
    $currentRole = strtoupper(trim((string)($_SESSION['role'] ?? 'USER')));
    if ($currentRole === 'ADMIN') {
        header("Location: dashboard.php");
        exit;
    } else {
        header("Location: user_dashboard.php");
        exit;
    }
}

require_once __DIR__ . '/connection.php';

$appBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$userDashboardUrl = ($appBasePath === '' ? '' : $appBasePath . '/') . 'user_dashboard.php';

$redirect_plot = null;
if (!empty($_REQUEST['flow']) && $_REQUEST['flow'] === 'existing_deceased') {

    unset($_SESSION['pending_booking_plot']);
    $redirect_plot = null;
} elseif (!empty($_REQUEST['redirect']) && $_REQUEST['redirect'] === 'plot' && !empty($_REQUEST['section']) && !empty($_REQUEST['plot_x']) && !empty($_REQUEST['plot_y'])) {
    $redirect_plot = [
        'section' => strtoupper(trim($_REQUEST['section'])),
        'plot_x' => (int)$_REQUEST['plot_x'],
        'plot_y' => (int)$_REQUEST['plot_y']
    ];
    $_SESSION['pending_booking_plot'] = $redirect_plot;
} else {

    unset($_SESSION['pending_booking_plot']);
    $redirect_plot = null;
}

$show_login = !isset($_GET['register']) || $_GET['register'] !== '1';
$message = "";
if (isset($_GET['auth_required']) && $_GET['auth_required'] === '1') {
    $message = "<div class='alert alert-warning text-center shadow-sm'><i class='fas fa-lock me-1'></i> <strong>Access Restricted:</strong> Please log in to your account to view that page.</div>";
} elseif (isset($_GET['session_expired']) && $_GET['session_expired'] === '1') {
    $message = "<div class='alert alert-warning text-center shadow-sm'><i class='fas fa-clock me-1'></i> <strong>Session Expired:</strong> Your session timed out due to inactivity. Please log in again.</div>";
} elseif (isset($_GET['logged_out']) && $_GET['logged_out'] === '1') {
    $message = "<div class='alert alert-success text-center shadow-sm'><i class='fas fa-check-circle me-1'></i> You have been successfully logged out.</div>";
}
$show_registration_banner = false;
if (isset($_SESSION['registration_success']) && $_SESSION['registration_success']) {
    $show_registration_banner = true;
    $message = "<div class='alert alert-success text-center'>Registration successful! A verification email has been sent to your email address. Please verify your email before logging in.</div>";
    unset($_SESSION['registration_success']);
}
if ($redirect_plot) {
    $plotLabel = 'Section ' . htmlspecialchars($redirect_plot['section']) . ' · Plot ' . (int)$redirect_plot['plot_x'] . '-' . (int)$redirect_plot['plot_y'];

    $registerPlotBanner = "<div id='registerPlotBanner' class='alert alert-info shadow-sm py-2 px-3 mb-3 text-start' style='display:" . ($show_login ? 'none' : 'block') . ";background:#f4f9ff;border:1px solid #b9d9ff;color:#1e3a8a;border-radius:10px;font-size:0.86rem;'>
        <div class='d-flex justify-content-between align-items-start'>
            <div>
                <div class='fw-bold mb-1' style='color:#1e40af;font-size:0.92rem;'><i class='fas fa-info-circle me-1'></i> Create an account to continue your reservation.</div>
                <div class='mb-1 text-dark' style='font-size:0.84rem;'><strong>Selected Plot:</strong> <span class='badge px-2 py-1' style='background:#7b1113;color:#ffffff;font-weight:600;font-size:0.82rem;'>{$plotLabel}</span></div>
            </div>
            <a href='login.php?register=1' class='btn-close' style='font-size:0.75rem;' title='Dismiss reservation'></a>
        </div>
        <div class='text-muted' style='font-size:0.77rem;line-height:1.35;'>
            Your selected plot will be kept for your reservation after you complete registration.<br>
            <span style='color:#b45309;'><strong>Note:</strong> This plot is your selected reservation plot. It is not yet assigned to the deceased and is subject to admin approval.</span>
        </div>
    </div>";

    $loginPlotBanner = "<div id='loginPlotBanner' class='alert alert-info shadow-sm py-2 px-3 mb-3 text-start' style='display:" . ($show_login ? 'block' : 'none') . ";background:#f4f9ff;border:1px solid #b9d9ff;color:#1e3a8a;border-radius:10px;font-size:0.86rem;'>
        <div class='d-flex justify-content-between align-items-start'>
            <div>
                <div class='fw-bold mb-1' style='color:#1e40af;font-size:0.92rem;'><i class='fas fa-sign-in-alt me-1'></i> Log in to continue your reservation.</div>
                <div class='mb-1 text-dark' style='font-size:0.84rem;'><strong>Selected Reservation Plot:</strong> <span class='badge px-2 py-1' style='background:#7b1113;color:#ffffff;font-weight:600;font-size:0.82rem;'>{$plotLabel}</span></div>
            </div>
            <a href='login.php' class='btn-close' style='font-size:0.75rem;' title='Dismiss reservation'></a>
        </div>
        <div class='text-muted' style='font-size:0.77rem;line-height:1.35;'>
            Sign in to continue your reservation request.<br>
            <span style='color:#b45309;'><strong>Note:</strong> This plot is your selected reservation plot. It is not yet assigned to the deceased and is subject to admin approval.</span>
        </div>
    </div>";

    $message .= $registerPlotBanner . $loginPlotBanner;
} elseif (!$show_login) {

    $message .= "<div id='registerGeneralBanner' class='alert alert-light border shadow-sm py-2 px-3 mb-3 text-start' style='background:#fdfcfb;border-color:#e5dbcf !important;border-radius:10px;font-size:0.86rem;'>
        <div class='fw-bold' style='color:#7b1113;font-size:0.95rem;'><i class='fas fa-user-plus me-1'></i> Create an account to continue.</div>
        <div class='text-muted' style='font-size:0.78rem;line-height:1.35;margin-top:2px;'>
            Please enter your account information and existing deceased family member details below to verify and link your cemetery record.
        </div>
    </div>";
}
$verified = isset($_GET['verified']) && $_GET['verified'] === '1';
if ($verified) {
	$extraNotice = !empty($_SESSION['registration_plot_notice']) ? '<div class="mt-1 small" style="color:#046c4e;">' . htmlspecialchars($_SESSION['registration_plot_notice']) . '</div>' : '';
	unset($_SESSION['registration_plot_notice']);
	$message = "<div class='alert alert-success text-center shadow-sm'><i class='fas fa-check-circle me-1'></i> <strong>Email verified successfully!</strong> You can now log in.{$extraNotice}</div>";
}
if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
	$message = "<div class='alert alert-success text-center shadow-sm' style='border-radius: 12px;'><i class='fas fa-check-circle me-1'></i> <strong>Account Deleted:</strong> Your account and all associated plot reservations have been permanently deleted and released back to available. You can register a new account anytime.</div>";
}
if (isset($_GET['password_reset']) && $_GET['password_reset'] === '1') {
	$message = "<div class='alert alert-success text-center shadow-sm'><i class='fas fa-check-circle me-1'></i> <strong>Password Reset:</strong> Your password has been successfully updated. Please log in with your new password.</div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (isset($_POST['verify_deceased'])) {
		header('Content-Type: application/json; charset=utf-8');
		$deceased_first = strtoupper(trim($_POST['deceased_first_name'] ?? ''));
		$deceased_middle = strtoupper(trim($_POST['deceased_middle_name'] ?? ''));
		$deceased_last = strtoupper(trim($_POST['deceased_last_name'] ?? ''));
		$dec_raw_suffix = trim($_POST['deceased_suffix'] ?? '');
		$dec_other = trim($_POST['deceased_suffix_other'] ?? '');
		$deceased_suffix = strtoupper(trim($dec_raw_suffix === 'OTHER' ? $dec_other : $dec_raw_suffix));
		$deceased_suffix = strip_tags($deceased_suffix);
		$deceased_birthday = trim($_POST['deceased_birthday'] ?? '');
		$deceased_date_died = trim($_POST['deceased_date_died'] ?? '');
		if ($deceased_first === '' || $deceased_last === '' || $deceased_birthday === '' || $deceased_date_died === '') {
			echo json_encode(['success' => false, 'message' => 'Please complete the deceased person name and dates.']);
			exit;
		}
		$today = date('Y-m-d');
		if ($deceased_birthday > $today) {
			echo json_encode(['success' => false, 'message' => 'Date of birth cannot be a future date.']);
			exit;
		}
		if ($deceased_date_died > $today) {
			echo json_encode(['success' => false, 'message' => 'Date of death cannot be a future date.']);
			exit;
		}
		if ($deceased_date_died < $deceased_birthday) {
			echo json_encode(['success' => false, 'message' => 'Date of death cannot be earlier than date of birth.']);
			exit;
		}
        try {
            $verifyStmt = $conn->prepare("SELECT deceased_id, user_id, first_name, middle_name, last_name, suffix, section, plot_x, plot_y, bday, date_died FROM tbl_deceased WHERE UPPER(TRIM(first_name)) = ? AND UPPER(TRIM(COALESCE(middle_name,''))) = ? AND UPPER(TRIM(last_name)) = ? AND UPPER(TRIM(COALESCE(suffix,''))) = ? AND bday = ? AND date_died = ? LIMIT 1");
            $verifyStmt->bind_param('ssssss', $deceased_first, $deceased_middle, $deceased_last, $deceased_suffix, $deceased_birthday, $deceased_date_died);
            $verifyStmt->execute();
            $verifiedRecord = $verifyStmt->get_result()->fetch_assoc();
            $verifyStmt->close();
            if (!$verifiedRecord) {
                unset($_SESSION['verified_registration_deceased_id']);
                echo json_encode(['success' => false, 'message' => 'No matching deceased record was found.']);
                exit;
            }
            if (!empty($verifiedRecord['user_id'])) {
                unset($_SESSION['verified_registration_deceased_id']);
                echo json_encode(['success' => false, 'message' => 'This deceased person is already linked to another user account.']);
                exit;
            }
            $_SESSION['verified_registration_deceased_id'] = (int)$verifiedRecord['deceased_id'];
            $secVal = !empty($verifiedRecord['section']) ? strtoupper(trim($verifiedRecord['section'])) : 'Unassigned';
            $pxVal = (int)($verifiedRecord['plot_x'] ?? 0);
            $pyVal = (int)($verifiedRecord['plot_y'] ?? 0);
            $plotRowVal = ($pxVal > 0 && $pyVal > 0) ? ($pxVal . '-' . $pyVal) : 'None';
            $curGrave = ($secVal !== 'Unassigned' && $plotRowVal !== 'None')
                ? ('Section ' . $secVal . ' · Plot ' . $plotRowVal)
                : 'No Grave Assigned Yet';

            $fullName = trim(implode(' ', array_filter([
                $verifiedRecord['first_name'],
                $verifiedRecord['middle_name'],
                $verifiedRecord['last_name'],
                $verifiedRecord['suffix']
            ])));

            echo json_encode([
                'success' => true,
                'message' => 'Deceased record found & verified.',
                'deceased' => [
                    'name' => $fullName,
                    'bday' => !empty($verifiedRecord['bday']) ? $verifiedRecord['bday'] : $deceased_birthday,
                    'date_died' => !empty($verifiedRecord['date_died']) ? $verifiedRecord['date_died'] : $deceased_date_died,
                    'section' => $secVal,
                    'plot_row' => $plotRowVal,
                    'current_grave' => $curGrave,
                    'status' => 'Verified'
                ]
            ]);
            exit;
        } catch (Throwable $vEx) {
            echo json_encode(['success' => false, 'message' => 'Verification query error: ' . $vEx->getMessage()]);
            exit;
        }
	}
	if (isset($_POST['login'])) {

		$email = strtolower(trim($_POST['email'] ?? ''));
		$password_input = $_POST['password'] ?? '';

        if (empty($email) || empty($password_input)) {
            $message = "<div class='alert alert-danger text-center'>Please enter both your email address and password.</div>";
        } elseif (!function_exists('is_db_connected') || !is_db_connected()) {
            global $lastConnErr, $lastConnNo, $servername, $dbname;
            $detail = "";
            if (!empty($lastConnNo) || !empty($lastConnErr)) {
                $h = htmlspecialchars((string)($servername ?? ''));
                $d = htmlspecialchars((string)($dbname ?? ''));
                $e = htmlspecialchars((string)($lastConnErr ?? ''));
                $c = (int)($lastConnNo ?? 0);
                $tip = "";
                if ($c === 1044 || $c === 1049) {
                    $tip = "<div class='mt-2 pt-2 border-top' style='border-color:#fca5a5 !important;font-size:0.78rem;'>"
                        . "<strong><i class='fas fa-info-circle'></i> How to fix Error 1044:</strong><br>"
                        . "Your MySQL user & password are valid, but database <code>{$d}</code> does not exist in your InfinityFree account.<br>"
                        . "1. Open <strong>InfinityFree Client Area &rarr; Control Panel (vPanel)</strong>.<br>"
                        . "2. Click <strong>MySQL Databases</strong>.<br>"
                        . "3. Enter <code>cemetery2_db</code> and click <strong>Create Database</strong>.<br>"
                        . "4. Open <strong>phpMyAdmin</strong> and import your database tables."
                        . "</div>";
                }
                $detail = "<div class='mt-2 p-2 text-start rounded' style='background:#fef2f2;border:1px solid #fca5a5;font-size:0.8rem;color:#7f1d1d;font-family:monospace;'>"
                    . "<strong>Connection Diagnostic:</strong><br>"
                    . "• Host: {$h}<br>"
                    . "• Database: {$d}<br>"
                    . "• Error: [{$c}] {$e}"
                    . $tip
                    . "</div>";
            }
            error_log("Login rejected: Database connection unavailable (is_db_connected=false).");
            $message = "<div class='alert alert-danger text-center'>Database temporarily unavailable. Please try again shortly.{$detail}</div>";
        } else {
            try {
                $loginStmt = $conn->prepare("SELECT user_id, first_name, last_name, email, password, role, email_verified FROM tbl_user WHERE LOWER(TRIM(email)) = ? AND UPPER(TRIM(COALESCE(role, 'USER'))) != 'ADMIN' LIMIT 1");
                if ($loginStmt) {
                    $loginStmt->bind_param('s', $email);
                    $loginStmt->execute();
                    $result = $loginStmt->get_result();
                    $user = $result ? $result->fetch_assoc() : null;
                    $loginStmt->close();

                    $pwMatches = false;
                    if ($user && !empty($user['password'])) {
                        $dbPass = (string)$user['password'];
                        $cleanInput = trim($password_input);
                        $pwCandidates = array_unique([
                            $password_input,
                            $cleanInput,
                            strtoupper($cleanInput),
                            strtolower($cleanInput),
                            strtoupper($password_input),
                            strtolower($password_input)
                        ]);
                        foreach ($pwCandidates as $cand) {
                            if ($cand === '') continue;
                            if (password_verify($cand, $dbPass) || $dbPass === $cand) {
                                $pwMatches = true;
                                if ($dbPass === $cand) {

                                    $newHash = password_hash($cand, PASSWORD_DEFAULT);
                                    $upPass = $conn->prepare("UPDATE tbl_user SET password = ? WHERE user_id = ?");
                                    if ($upPass) {
                                        $uidInt = (int)$user['user_id'];
                                        $upPass->bind_param('si', $newHash, $uidInt);
                                        $upPass->execute();
                                        $upPass->close();
                                    }
                                }
                                break;
                            }
                        }
                    }

                    if ($user && $pwMatches) {
                        $stored_role = isset($user['role']) ? strtoupper(trim((string)$user['role'])) : '';
                        if ($stored_role === 'ADMIN') {

                            $message = "<div class='alert alert-danger text-center shadow-sm'><i class='fas fa-times-circle me-1'></i> ❌ Invalid Credentials (Incorrect Email or Password)</div>";
                        } else {

                            if (isset($user['email_verified']) && intval($user['email_verified']) !== 1) {
                                $verifyUrl = 'verify.php?email=' . urlencode($email) . '&type=user';
                                $message = "<div class='alert alert-danger text-center shadow-sm'><i class='fas fa-exclamation-circle me-1'></i> Your email is not verified yet. Please check your inbox or request a new code.<br><a class='btn btn-sm mt-2' style='background-color:#7b1113 !important;border-color:#7b1113 !important;color:#ffffff !important;font-weight:600;border-radius:8px;padding:6px 16px;text-decoration:none;display:inline-block;' href='$verifyUrl'><i class='fas fa-shield-alt me-1'></i> Enter Verification Code</a></div>";
                            } else {

                                session_regenerate_id(true);

                                unset($_SESSION['admin_logged_in'], $_SESSION['admin_id']);

                                $_SESSION['user_id'] = (int)$user['user_id'];
                                $_SESSION['email'] = $user['email'];
                                $_SESSION['role'] = 'USER';
                                $_SESSION['first_name'] = $user['first_name'];
                                $_SESSION['last_name'] = $user['last_name'];
                                $_SESSION['last_activity'] = time();

                                $linkedDeceasedStmt = $conn->prepare("SELECT deceased_id FROM tbl_deceased WHERE user_id = ? ORDER BY deceased_id ASC LIMIT 1");
                                if ($linkedDeceasedStmt) {
                                    $linkedDeceasedStmt->bind_param('i', $user['user_id']);
                                    $linkedDeceasedStmt->execute();
                                    $linkedDeceased = $linkedDeceasedStmt->get_result()->fetch_assoc();
                                    $_SESSION['deceased_id'] = (int)($linkedDeceased['deceased_id'] ?? 0);
                                    $linkedDeceasedStmt->close();
                                } else {
                                    $_SESSION['deceased_id'] = 0;
                                }

                                if (function_exists('sync_user_existing_cemetery_records')) {
                                    sync_user_existing_cemetery_records($conn, (int)$user['user_id']);
                                }

								$destUrl = $userDashboardUrl;
                                if (!empty($redirect_plot['section']) && !empty($redirect_plot['plot_x']) && !empty($redirect_plot['plot_y'])) {
									$destUrl .= '?booking=1&section=' . urlencode($redirect_plot['section']) . '&plot_x=' . (int)$redirect_plot['plot_x'] . '&plot_y=' . (int)$redirect_plot['plot_y'];
                                    unset($_SESSION['pending_booking_plot']);
                                }
                                session_write_close();
                                header('Location: ' . $destUrl);
                                exit;
                            }
                        }
                    } else {
                        $message = "<div class='alert alert-danger text-center'>❌ Invalid Credentials (Incorrect Email or Password)</div>";
                    }
                } else {
                    $dbErrorDetail = (!empty($conn) && !empty($conn->error)) ? ' (' . htmlspecialchars($conn->error) . ')' : ''; $message = "<div class='alert alert-danger text-center'>❌ Query error{$dbErrorDetail}. Please try again.</div>";
                }
            } catch (Throwable $lEx) {
                error_log('Login DB error: ' . $lEx->getMessage());
                $message = "<div class='alert alert-danger text-center'>❌ An error occurred during sign in. Please try again.</div>";
            }
        }
	} elseif (isset($_POST['register'])) {

		$show_login = false;

		$first = strtoupper($conn->real_escape_string(strip_tags($_POST['first_name'] ?? '')));
		$middle = isset($_POST['middle_name']) ? strtoupper($conn->real_escape_string(strip_tags($_POST['middle_name']))) : '';
		$last = strtoupper($conn->real_escape_string(strip_tags($_POST['last_name'] ?? '')));
		$raw_suffix = $conn->real_escape_string(strip_tags($_POST['suffix'] ?? ''));
		$other = isset($_POST['suffix_other']) ? $conn->real_escape_string(strip_tags($_POST['suffix_other'])) : '';
		$suffix = strtoupper(trim(($_POST['suffix'] ?? '') === 'OTHER' ? $other : $raw_suffix));
		$birthday = $conn->real_escape_string(trim($_POST['birthday'] ?? ''));
		$gender = ucfirst(strtolower(trim($_POST['gender'] ?? '')));
		$email = str_replace(' ', '', $conn->real_escape_string($_POST['email'] ?? ''));
		$phone = str_replace(' ', '', $conn->real_escape_string($_POST['phone_no'] ?? ''));
		$password_plain = $_POST['password'] ?? '';
		$confirm_password = $_POST['confirm_password'] ?? '';
		$password_hashed = password_hash($password_plain, PASSWORD_DEFAULT);

		$today = date('Y-m-d');
		if ($birthday > $today) {
			$message = "<div class='alert alert-danger text-center'>❌ Birthday cannot be a future date.</div>";
		} elseif ($gender !== 'Male' && $gender !== 'Female') {
			$message = "<div class='alert alert-danger text-center'>❌ Please select a valid gender (Male or Female).</div>";
		} elseif (strlen($password_plain) < 8) {
			$message = "<div class='alert alert-danger text-center'>❌ Password must be at least 8 characters in length.</div>";
		} elseif (!preg_match('/[A-Z]/', $password_plain)) {
			$message = "<div class='alert alert-danger text-center'>❌ Password must contain at least one uppercase letter (A-Z).</div>";
		} elseif (!preg_match('/[a-z]/', $password_plain)) {
			$message = "<div class='alert alert-danger text-center'>❌ Password must contain at least one lowercase letter (a-z).</div>";
		} elseif (!preg_match('/[0-9]/', $password_plain)) {
			$message = "<div class='alert alert-danger text-center'>❌ Password must contain at least one number (0-9).</div>";
		} elseif (!preg_match('/[^A-Za-z0-9]/', $password_plain)) {
			$message = "<div class='alert alert-danger text-center'>❌ Password must contain at least one symbol or special character (e.g. !@#$%^&*).</div>";
		} elseif ($password_plain !== $confirm_password) {
			$message = "<div class='alert alert-danger text-center'>❌ Passwords do not match.</div>";
		} elseif (!preg_match("/^[A-Za-z0-9._%+-]+@gmail\.com$/i", $email)) {
			$message = "<div class='alert alert-danger text-center'>❌ Email must be a valid Gmail address ending in @gmail.com.</div>";
		} elseif (!preg_match('/^09[0-9]{9}$/', preg_replace('/[^0-9]/', '', $phone))) {
			$message = "<div class='alert alert-danger text-center'>❌ Phone number must be an 11-digit mobile number starting with 09 (e.g. 09XXXXXXXXX).</div>";
		} else {
			$check_email = $conn->query("SELECT * FROM tbl_user WHERE email='$email' LIMIT 1");
			if ($check_email->num_rows > 0) {
				$message = "<div class='alert alert-danger text-center'>❌ Email already registered</div>";
			} else {
				$deceased_first = strtoupper(trim($_POST['deceased_first_name'] ?? ''));
				$deceased_middle = strtoupper(trim($_POST['deceased_middle_name'] ?? ''));
				$deceased_last = strtoupper(trim($_POST['deceased_last_name'] ?? ''));
				$dec_raw_suffix = trim($_POST['deceased_suffix'] ?? '');
				$dec_other = trim($_POST['deceased_suffix_other'] ?? '');
				$deceased_suffix = strtoupper(trim($dec_raw_suffix === 'OTHER' ? $dec_other : $dec_raw_suffix));
				$deceased_suffix = strip_tags($deceased_suffix);
				$deceased_birthday = trim($_POST['deceased_birthday'] ?? '');
				$deceased_date_died = trim($_POST['deceased_date_died'] ?? '');
				$deceased_relationship = strtoupper(trim($_POST['deceased_relationship'] ?? ''));
				$verifiedRegistrationId = (int)($_SESSION['verified_registration_deceased_id'] ?? 0);

				if ($deceased_birthday !== '' && $deceased_birthday > $today) {
					$message = "<div class='alert alert-danger text-center'>❌ Deceased date of birth cannot be a future date.</div>";
					$show_login = false;
					goto login_registration_end;
				}
				if ($deceased_date_died !== '' && $deceased_date_died > $today) {
					$message = "<div class='alert alert-danger text-center'>❌ Deceased date of death cannot be a future date.</div>";
					$show_login = false;
					goto login_registration_end;
				}
				if ($deceased_birthday !== '' && $deceased_date_died !== '' && $deceased_date_died < $deceased_birthday) {
					$message = "<div class='alert alert-danger text-center'>❌ Deceased date of death cannot be earlier than date of birth.</div>";
					$show_login = false;
					goto login_registration_end;
				}

				if ($verifiedRegistrationId <= 0 && $deceased_first !== '' && $deceased_last !== '' && $deceased_birthday !== '' && $deceased_date_died !== '') {
					$preCheck = $conn->prepare("SELECT deceased_id, user_id FROM tbl_deceased WHERE UPPER(TRIM(first_name)) = ? AND UPPER(TRIM(COALESCE(middle_name,''))) = ? AND UPPER(TRIM(last_name)) = ? AND UPPER(TRIM(COALESCE(suffix,''))) = ? AND bday = ? AND date_died = ? LIMIT 1");
					if ($preCheck) {
						$preCheck->bind_param('ssssss', $deceased_first, $deceased_middle, $deceased_last, $deceased_suffix, $deceased_birthday, $deceased_date_died);
						$preCheck->execute();
						$pRow = $preCheck->get_result()->fetch_assoc();
						$preCheck->close();
						if ($pRow) {
							if (!empty($pRow['user_id'])) {
								$message = "<div class='alert alert-danger text-center'>This deceased person is already linked to another user account. Please contact the cemetery administrator.</div>";
								$show_login = false;
								goto login_registration_end;
							}
							$verifiedRegistrationId = (int)$pRow['deceased_id'];
							$_SESSION['verified_registration_deceased_id'] = $verifiedRegistrationId;
						}
					}
				}

				if ($verifiedRegistrationId <= 0) {
					$message = "<div class='alert alert-danger text-center'>No matching deceased record was found. Please check the deceased details or click 'Verify Deceased Record'.</div>";
					$show_login = false;
				} elseif ($deceased_first === '' || $deceased_last === '' || $deceased_birthday === '' || $deceased_date_died === '') {
					$message = "<div class='alert alert-danger text-center'>❌ Please complete the deceased person's information.</div>";
					$show_login = false;
				} elseif ($deceased_relationship !== '' && !in_array($deceased_relationship, ['PARENT', 'SPOUSE', 'CHILD', 'RELATIVE', 'OTHER'], true)) {
					$message = "<div class='alert alert-danger text-center'>❌ Please select a valid relationship.</div>";
					$show_login = false;
				} else {
					$deceasedStmt = $conn->prepare("SELECT deceased_id, user_id, section, plot_x, plot_y FROM tbl_deceased WHERE deceased_id = ? AND UPPER(TRIM(first_name)) = ? AND UPPER(TRIM(COALESCE(middle_name,''))) = ? AND UPPER(TRIM(last_name)) = ? AND UPPER(TRIM(COALESCE(suffix,''))) = ? AND bday = ? AND date_died = ? LIMIT 1");
					$deceasedStmt->bind_param('issssss', $verifiedRegistrationId, $deceased_first, $deceased_middle, $deceased_last, $deceased_suffix, $deceased_birthday, $deceased_date_died);
					$deceasedStmt->execute();
					$deceasedRecord = $deceasedStmt->get_result()->fetch_assoc();
					$deceasedStmt->close();

					if (!$deceasedRecord) {
						unset($_SESSION['verified_registration_deceased_id']);
						$message = "<div class='alert alert-danger text-center'>No matching deceased record was found. Please check the information or contact the cemetery administrator.</div>";
						$show_login = false;
					} elseif (!empty($deceasedRecord['user_id'])) {
						$message = "<div class='alert alert-danger text-center'>This deceased person is already linked to another user account. Please contact the cemetery administrator.</div>";
						$show_login = false;
					} else {
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

						$uInsertStmt = $conn->prepare("INSERT INTO tbl_user 
							(first_name, middle_name, last_name, suffix, birthday, gender, email, phone_no, password, role, email_verified, verification_otp, otp_expiry, verification_token, verification_expires, verification_attempts)
							VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'USER', 0, ?, ?, ?, ?, 0)");
						if (!$uInsertStmt) {
							$message = "<div class='alert alert-danger text-center'>User insert failed: " . htmlspecialchars($conn->error) . "</div>";
							$show_login = false;
						} else {
							$uInsertStmt->bind_param('sssssssssssss', $first, $middle, $last, $suffix, $birthday, $gender, $email, $phone, $password_hashed, $otp_hash, $expires, $otp_hash, $expires);
							if (!$uInsertStmt->execute()) {
								$message = "<div class='alert alert-danger text-center'>User insert failed: " . htmlspecialchars($uInsertStmt->error) . "</div>";
								$uInsertStmt->close();
								$show_login = false;
							} else {
								$newUserId = (int)$uInsertStmt->insert_id;
								$uInsertStmt->close();

								$relToSave = $deceased_relationship !== '' ? $deceased_relationship : null;
								$linkStmt = $conn->prepare("UPDATE tbl_deceased SET user_id = ?, relationship_to_account_holder = ? WHERE deceased_id = ? AND user_id IS NULL");
								$linkStmt->bind_param('isi', $newUserId, $relToSave, $deceasedRecord['deceased_id']);
								if (!$linkStmt->execute() || $linkStmt->affected_rows !== 1) {
									$linkStmt->close();
									$conn->query("DELETE FROM tbl_user WHERE user_id = " . $newUserId . " LIMIT 1");
									$message = "<div class='alert alert-danger text-center'>The deceased record could not be linked. Please try again.</div>";
									$show_login = false;
									goto login_registration_end;
								}
								$linkStmt->close();

								if (function_exists('ensure_user_plot_ownership_table')) {
									ensure_user_plot_ownership_table($conn);
								}
								if (!empty($deceasedRecord['section']) && !empty($deceasedRecord['plot_x']) && !empty($deceasedRecord['plot_y'])) {
									$dSec = strtoupper(trim($deceasedRecord['section']));
									$dPx = (int)$deceasedRecord['plot_x'];
									$dPy = (int)$deceasedRecord['plot_y'];
									$dDecName = trim(implode(' ', array_filter([$deceased_first, $deceased_middle, $deceased_last, $deceased_suffix])));
									$dNotes = 'Linked via deceased record registration: ' . $dDecName;

									$fPlotId = null;
									$tpStmt = $conn->prepare("SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
									if ($tpStmt) {
										$tpStmt->bind_param('sii', $dSec, $dPx, $dPy);
										$tpStmt->execute();
										$tpRes = $tpStmt->get_result();
										if ($tpRow = $tpRes->fetch_assoc()) {
											$fPlotId = (int)$tpRow['plot_id'];
										}
										$tpStmt->close();
									}

									$insOwner = $conn->prepare("INSERT INTO user_plot_ownership 
										(user_id, section, plot_x, plot_y, plot_id, ownership_status, verification_status, claim_source, deceased_reference, notes)
										VALUES (?, ?, ?, ?, ?, 'OWNED', 'VERIFIED', 'DECEASED_RECORD', ?, ?)
										ON DUPLICATE KEY UPDATE verification_status = 'VERIFIED', ownership_status = 'OWNED', deceased_reference = VALUES(deceased_reference)");
									if ($insOwner) {
										$insOwner->bind_param('isiisss', $newUserId, $dSec, $dPx, $dPy, $fPlotId, $dDecName, $dNotes);
										$insOwner->execute();
										$insOwner->close();
									}
								}

								if (function_exists('sync_user_existing_cemetery_records')) {
									sync_user_existing_cemetery_records($conn, $newUserId);
								}

								if (file_exists(__DIR__ . '/mailer.php')) {
									include_once __DIR__ . '/mailer.php';
								}
								$fullName = trim($first . ' ' . $last);
								send_registration_otp_email($email, $fullName, $otp, false);

								$_SESSION['verify_email'] = $email;
								$_SESSION['verify_role'] = 'USER';
								$_SESSION['last_resend_time_' . md5($email)] = time();

								unset($_SESSION['verified_registration_deceased_id']);

								header('Location: verify.php?email=' . urlencode($email) . '&type=user');
								exit;
							}
						}
					}
				}
			}
		}
	}
}

login_registration_end:

?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Login & Register</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<!-- IE11 / Edge-Legacy polyfills (URLSearchParams, fetch, Promise, etc.) -->
	<script src="includes/ie_polyfills.js"></script>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
	<style>
		:root { --bs-primary: #7b1113; --stone: #f5efe8; --ink: #2b211f; --accent: #8c5b2d; }
		html, body { height: 100%; }
		body {
			margin: 0;
			font-family: 'Segoe UI', sans-serif;
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
		.mobile-header { display: none; position: fixed; top: 0; left: 0; right: 0; background: #7b1113; color: white; z-index: 1060; box-shadow: 0 2px 4px rgba(0,0,0,0.2); height: 56px; }
		.mobile-header-content { display:flex; align-items:center; justify-content:center; height:100%; padding:0 1rem; }
		.mobile-header-logo { height: 32px; width: auto; max-width: 100px; margin-right: 0.75rem; }
		.mobile-header-title { font-size: 1rem; font-weight:600; margin:0; }
		@media(min-width:769px) { .mobile-header { display:none; } }
		.form-toggle { cursor: pointer; color: var(--bs-primary); text-decoration: underline; }
		.form-toggle:hover { text-decoration: none; }
		.page-shell {
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 0.65rem 0.5rem;
			position: relative;
			z-index: 1;
		}
		.auth-card {
			width: min(1000px, 100%);
			min-height: 520px;
			max-height: 96vh;
			display: grid;
			grid-template-columns: 1.1fr 0.9fr;
			background: rgba(255, 250, 245, 0.95);
			border-radius: 1.25rem;
			overflow: hidden;
			box-shadow: 0 20px 50px rgba(0,0,0,0.22);
			border: 1px solid rgba(255,255,255,0.35);
			transition: all 0.3s ease;
		}
		.auth-card.register-mode {
			width: min(1060px, 98vw);
			grid-template-columns: 0.85fr 1.15fr;
		}
		.auth-hero {
			padding: 2.2rem;
			background: linear-gradient(145deg, rgba(123,17,19,0.96), rgba(71,13,15,0.92));
			color: #fff;
			display: flex;
			flex-direction: column;
			justify-content: center !important;
			position: relative;
			overflow-y: auto;
		}
		.register-mode .auth-hero {
			padding: 1.5rem 1.75rem;
		}
		.auth-hero::after {
			content: "✚";
			position: absolute;
			right: 1rem;
			bottom: 0.85rem;
			font-size: 3rem;
			opacity: 0.16;
		}
		.hero-badge {
			display: inline-block;
			width: fit-content;
			padding: 0.45rem 0.8rem;
			border-radius: 999px;
			background: rgba(255,255,255,0.16);
			font-size: 0.8rem;
			letter-spacing: 0.2em;
			text-transform: uppercase;
			margin-bottom: 1rem;
		}
		.hero-title {
			font-size: 1.8rem;
			font-weight: 700;
			margin-bottom: 0.7rem;
			line-height: 1.2;
		}
		.hero-text {
			font-size: 0.98rem;
			line-height: 1.6;
			color: rgba(255,255,255,0.9);
			margin-bottom: 1.1rem;
		}
		.hero-list {
			list-style: none;
			padding: 0;
			margin: 0;
			display: grid;
			gap: 0.6rem;
		}
		.hero-list li { display: flex; align-items: center; gap: 0.55rem; font-size: 0.95rem; }
		.hero-list li::before { content: "✶"; color: #f1d8a8; }
		.auth-form-panel {
			padding: 1.5rem;
			display: flex;
			align-items: center;
			justify-content: center;
			background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(245,239,232,0.95));
			max-height: 94vh;
			overflow-y: auto;
		}
		.register-mode .auth-form-panel {
			padding: 0.65rem 0.95rem;
			align-items: flex-start;
		}
		.auth-form-panel::-webkit-scrollbar {
			width: 6px;
		}
		.auth-form-panel::-webkit-scrollbar-track {
			background: rgba(0,0,0,0.04);
			border-radius: 6px;
		}
		.auth-form-panel::-webkit-scrollbar-thumb {
			background: rgba(123,17,19,0.25);
			border-radius: 6px;
		}
		.auth-form-panel::-webkit-scrollbar-thumb:hover {
			background: rgba(123,17,19,0.5);
		}
		.form-container {
			width: 100%;
			max-width: 380px;
			padding: 1.5rem;
			background: rgba(255,255,255,0.86);
			border-radius: 1rem;
			box-shadow: inset 0 1px 0 rgba(255,255,255,0.6), 0 12px 30px rgba(43,33,31,0.08);
			border: 1px solid rgba(140,91,45,0.16);
			transition: max-width 0.25s ease, padding 0.25s ease;
		}
		.register-mode .form-container {
			max-width: 535px;
			padding: 0.65rem 1rem;
		}
		.brand-logo { display:block; margin:0 auto 1.2rem; max-width:120px; width:100%; height:auto; transition: all 0.25s ease; }
		.register-mode .brand-logo { max-width: 42px; margin: 0 auto 0.2rem; }
		.form-control, .form-select { border-radius: 0.55rem; border-color: rgba(140,91,45,0.2); padding: 0.4rem 0.65rem; font-size: 0.86rem; }
		.form-control:focus, .form-select:focus { border-color: var(--bs-primary); box-shadow: 0 0 0 0.15rem rgba(123,17,19,0.12); }
		#loginForm .form-control { border-radius: 0.7rem; border-color: rgba(140,91,45,0.2); padding: 0.72rem 0.85rem; font-size: 1rem; }
		#loginForm .form-control:focus { border-color: var(--bs-primary); box-shadow: 0 0 0 0.2rem rgba(123,17,19,0.12); }
		.form-label-compact { font-size: 0.71rem; font-weight: 600; color: #555; margin-bottom: 0.1rem; display: block; }
		.register-mode .form-control,
		.register-mode .form-select {
			padding: 0.32rem 0.52rem;
			font-size: 0.82rem;
			min-width: 0;
		}
		.register-mode input[type="date"].form-control {
			padding: 0.28rem 0.42rem;
			font-size: 0.79rem;
		}
		.register-mode .row.g-2 {
			--bs-gutter-x: 0.45rem;
			--bs-gutter-y: 0.2rem;
		}
		.register-mode .registration-section {
			padding: 0.4rem 0.65rem !important;
			margin-top: 0.2rem !important;
			margin-bottom: 0.3rem !important;
		}
		.register-mode h3 {
			font-size: 1.22rem !important;
			margin-bottom: 0.2rem !important;
		}
		.register-mode .btn {
			padding: 0.42rem 0.85rem;
		}
		.btn-primary, .btn-success {
			--bs-btn-color: #ffffff;
			--bs-btn-bg: #7b1113;
			--bs-btn-border-color: #7b1113;
			--bs-btn-hover-color: #ffffff;
			--bs-btn-hover-bg: #921719;
			--bs-btn-hover-border-color: #921719;
			--bs-btn-focus-shadow-rgb: 123, 17, 19;
			--bs-btn-active-color: #ffffff;
			--bs-btn-active-bg: #630d0f;
			--bs-btn-active-border-color: #630d0f;
			--bs-btn-disabled-color: #ffffff;
			--bs-btn-disabled-bg: #7b1113;
			--bs-btn-disabled-border-color: #7b1113;
			--bs-btn-disabled-opacity: 0.65;
			background-color: #7b1113 !important;
			border-color: #7b1113 !important;
			color: #ffffff !important;
			border-radius: 0.65rem;
			padding: 0.48rem 1rem;
			font-weight: 600;
		}
		.btn-primary:hover, .btn-success:hover {
			background-color: #921719 !important;
			border-color: #921719 !important;
			color: #ffffff !important;
		}
		.btn-primary:disabled, .btn-primary.disabled {
			background-color: #7b1113 !important;
			border-color: #7b1113 !important;
			color: #ffffff !important;
			opacity: 0.65 !important;
			cursor: not-allowed;
		}
		.btn-outline-primary {
			--bs-btn-color: #7b1113;
			--bs-btn-border-color: #7b1113;
			--bs-btn-hover-color: #ffffff;
			--bs-btn-hover-bg: #7b1113;
			--bs-btn-hover-border-color: #7b1113;
			color: #7b1113 !important;
			border-color: #7b1113 !important;
			border-radius: 0.65rem;
			padding: 0.48rem 1rem;
			font-weight: 600;
		}
		.btn-outline-primary:hover {
			background-color: #7b1113 !important;
			border-color: #7b1113 !important;
			color: #ffffff !important;
		}
		#verifyDeceasedButton,
		#createAccountButton {
			background-color: #7b1113 !important;
			border-color: #7b1113 !important;
			color: #ffffff !important;
		}
		#verifyDeceasedButton:hover:not(:disabled),
		#createAccountButton:hover:not(:disabled) {
			background-color: #921719 !important;
			border-color: #921719 !important;
			color: #ffffff !important;
		}
		#verifyDeceasedButton:disabled,
		#createAccountButton:disabled {
			background-color: #7b1113 !important;
			border-color: #7b1113 !important;
			color: #ffffff !important;
			opacity: 0.65 !important;
		}
		.password-wrap { position: relative; }
		.password-toggle-btn {
			position: absolute;
			right: 12px;
			top: 50%;
			transform: translateY(-50%);
			background: none;
			border: none;
			color: #8c5b2d;
			cursor: pointer;
			padding: 0;
			font-size: 1rem;
			z-index: 5;
		}
		.forgot-link {
			color: var(--bs-primary);
			text-decoration: none;
			font-weight: 600;
			font-size: 0.86rem;
		}
		.forgot-link:hover {
			text-decoration: underline;
		}
		.password-toggle-wrapper { position: relative; }
		.password-toggle-button {
			position: absolute;
			right: 0.65rem;
			bottom: 0.38rem;
			border: none;
			background: transparent;
			color: #6c757d;
			font-size: 0.95rem;
			cursor: pointer;
			padding: 0;
			line-height: 1;
		}
		.password-toggle-button:focus { outline: none; }
		h3 { color: var(--bs-primary); text-align: center; margin-bottom: 0.65rem; font-size: 1.35rem; font-weight: 700; }
		#loginForm h3 { color: var(--bs-primary); text-align: center; margin-bottom: 1.25rem; font-weight: 700; font-size: 1.75rem; }
		#loginForm .btn-primary {
			border-radius: 0.7rem;
			padding: 0.72rem 1rem;
			font-weight: 600;
			font-size: 1rem;
		}
		.form-note { font-size: 0.85rem; color: #6f5d4f; text-align: center; margin-top: 0.4rem; }
		@media (min-width: 769px) and (max-height: 820px) {
			.page-shell { padding: 0.3rem 0.25rem; }
			.auth-card:not(.register-mode) { min-height: 520px; }
			.auth-card:not(.register-mode) .auth-hero { padding: 2.2rem; justify-content: center !important; }
			.register-mode .auth-card { min-height: unset; }
			.register-mode .auth-hero { padding: 1.1rem 1.25rem; justify-content: center; overflow-y: auto; }
			.register-mode .hero-badge { font-size: 0.72rem; padding: 0.25rem 0.65rem; margin-bottom: 0.6rem; display: inline-block !important; }
			.register-mode .hero-title { font-size: 1.35rem; margin-bottom: 0.35rem; }
			.register-mode .hero-text { font-size: 0.85rem; line-height: 1.35; margin-bottom: 0.6rem; display: block !important; }
			.register-mode .hero-list { gap: 0.35rem; display: flex !important; flex-direction: column !important; }
			.register-mode .hero-list li { font-size: 0.82rem; }
			.register-mode .brand-logo { max-width: 36px; margin-bottom: 0.1rem; }
			.register-mode .form-container { padding: 0.45rem 0.75rem; }
			.register-mode .auth-form-panel { padding: 0.35rem 0.55rem; }
			.register-mode .form-control, .register-mode .form-select { padding: 0.26rem 0.45rem; font-size: 0.8rem; }
		}
		@media (min-width: 769px) and (max-height: 600px) {
			.page-shell { padding: 0.15rem; }
			.auth-card:not(.register-mode) .auth-hero { padding: 1.5rem; justify-content: center !important; }
			.register-mode .auth-hero { padding: 0.8rem 1rem; justify-content: center; overflow-y: auto; }
			.register-mode .hero-badge { font-size: 0.68rem; padding: 0.2rem 0.5rem; margin-bottom: 0.4rem; display: inline-block !important; }
			.register-mode .hero-title { font-size: 1.2rem; margin-bottom: 0.25rem; }
			.register-mode .hero-text { font-size: 0.78rem; line-height: 1.3; margin-bottom: 0.4rem; display: block !important; }
			.register-mode .hero-list { gap: 0.25rem; display: flex !important; flex-direction: column !important; }
			.register-mode .hero-list li { font-size: 0.78rem; }
		}
		@media (max-width: 768px) {
			.page-shell {
				padding: 1rem 0.65rem !important;
				min-height: 100vh;
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
				box-sizing: border-box !important;
			}
			body.is-register-active .page-shell,
			.page-shell:has(.register-mode) {
				align-items: flex-start !important;
				padding: 0.5rem 0.5rem 2rem !important;
			}
			.auth-card { 
				display: flex !important; 
				flex-direction: column !important;
				grid-template-columns: 1fr !important; 
				max-height: none !important; 
				width: 100% !important;
				max-width: 440px !important;
				margin: auto !important;
				border-radius: 1.15rem !important;
				box-shadow: 0 10px 30px rgba(0,0,0,0.18) !important;
				overflow: hidden !important;
				background: #ffffff !important;
				border: 1px solid rgba(255,255,255,0.4) !important;
			}
			.auth-card.register-mode {
				margin: 0.5rem auto 1.5rem auto !important;
				max-width: 490px !important;
			}
			/* HERO BANNER: VISIBLE & BEAUTIFULLY FITTED LIKE DESKTOP VERSION */
			.auth-hero,
			.register-mode .auth-hero { 
				display: flex !important; 
				flex-direction: column !important;
				justify-content: center !important;
				width: 100% !important;
				padding: 1.15rem 1.25rem 1rem 1.25rem !important;
				background: linear-gradient(145deg, rgba(123,17,19,0.98), rgba(71,13,15,0.94)) !important;
				color: #ffffff !important;
				position: relative !important;
				box-sizing: border-box !important;
				min-height: auto !important;
				border-radius: 1.15rem 1.15rem 0 0 !important;
			}
			.auth-hero::after {
				content: "✚" !important;
				position: absolute !important;
				right: 0.85rem !important;
				bottom: 0.5rem !important;
				font-size: 2.2rem !important;
				opacity: 0.12 !important;
			}
			.hero-badge {
				display: inline-flex !important;
				align-items: center !important;
				width: fit-content !important;
				padding: 0.22rem 0.65rem !important;
				border-radius: 999px !important;
				background: rgba(255,255,255,0.18) !important;
				font-size: 0.72rem !important;
				letter-spacing: 0.15em !important;
				text-transform: uppercase !important;
				margin-bottom: 0.4rem !important;
				color: #ffffff !important;
				font-weight: 600 !important;
			}
			.hero-title {
				font-size: 1.25rem !important;
				font-weight: 700 !important;
				margin-bottom: 0.25rem !important;
				line-height: 1.25 !important;
				color: #ffffff !important;
			}
			.hero-text {
				font-size: 0.82rem !important;
				line-height: 1.35 !important;
				color: rgba(255,255,255,0.92) !important;
				margin-bottom: 0.55rem !important;
			}
			.hero-list {
				display: flex !important;
				flex-wrap: wrap !important;
				gap: 0.35rem 0.85rem !important;
				padding: 0 !important;
				margin: 0 !important;
				list-style: none !important;
			}
			.hero-list li {
				display: inline-flex !important;
				align-items: center !important;
				gap: 0.35rem !important;
				font-size: 0.78rem !important;
				color: rgba(255,255,255,0.95) !important;
			}
			.hero-list li::before {
				content: "✶" !important;
				color: #f1d8a8 !important;
				font-size: 0.72rem !important;
			}
			/* FORM PANEL BELOW HERO */
			.auth-form-panel,
			.register-mode .auth-form-panel { 
				width: 100% !important;
				max-width: 100% !important;
				padding: 1.25rem 1rem !important; 
				max-height: none !important;
				display: block !important;
				background: #ffffff !important;
				box-sizing: border-box !important;
				overflow-y: visible !important;
			}
			.form-container,
			.register-mode .form-container { 
				width: 100% !important;
				max-width: 100% !important; 
				padding: 0 !important;
				box-sizing: border-box !important;
				margin: 0 !important;
				box-shadow: none !important;
				border: none !important;
				background: transparent !important;
			}
			.brand-logo,
			.register-mode .brand-logo {
				max-width: 44px !important;
				margin: 0 auto 0.4rem auto !important;
				display: block !important;
			}
			.form-control, .form-select, 
			.register-mode .form-control, .register-mode .form-select,
			#loginForm .form-control,
			input[type="date"].form-control,
			.register-mode input[type="date"].form-control { 
				font-size: 15px !important;
				height: 44px !important;
				min-height: 44px !important;
				padding: 0.5rem 0.75rem !important;
				border-radius: 0.55rem !important;
				box-sizing: border-box !important;
				line-height: 1.4 !important;
			}
			.form-label-compact { 
				font-size: 0.8rem !important; 
				font-weight: 600 !important;
				margin-bottom: 0.22rem !important; 
				color: #333 !important;
			}
			.registration-section {
				padding: 0.75rem !important;
				border-radius: 0.75rem !important;
				margin-top: 0.6rem !important;
				margin-bottom: 0.75rem !important;
				background: #fff8f5 !important;
			}
			.password-toggle-wrapper {
				position: relative !important;
			}
			.password-toggle-wrapper .form-control {
				padding-right: 46px !important;
			}
			.password-toggle-button {
				width: 44px !important;
				height: 44px !important;
				min-width: 44px !important;
				min-height: 44px !important;
				right: 0 !important;
				bottom: 0 !important;
				top: auto !important;
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
			}
			.btn-primary, #verifyDeceasedButton, #createAccountButton {
				min-height: 46px !important;
				height: 46px !important;
				font-size: 0.95rem !important;
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
			}
		}
		@media (max-width: 576px) {
			.page-shell { 
				padding: 1rem 0.5rem !important; 
				align-items: center !important;
				justify-content: center !important;
			}
			body.is-register-active .page-shell,
			.page-shell:has(.register-mode) {
				padding: 0.35rem 0.35rem 2rem !important; 
				align-items: flex-start !important;
			}
			.auth-card { 
				border-radius: 1rem !important; 
				margin: auto !important;
			}
			.auth-card.register-mode { 
				border-radius: 0.85rem !important; 
				margin: 0.35rem auto 1.5rem auto !important;
			}
			.auth-hero,
			.register-mode .auth-hero {
				padding: 1rem 1rem 0.85rem 1rem !important;
				border-radius: 0.85rem 0.85rem 0 0 !important;
			}
			.auth-form-panel,
			.register-mode .auth-form-panel { 
				padding: 1rem 0.75rem !important; 
			}
			.registration-section { 
				padding: 0.65rem 0.65rem !important; 
			}
		}
	</style>
</head>
<body class="<?= !$show_login ? 'is-register-active' : '' ?>">

<div class="mobile-header">
  <div class="mobile-header-content">
	<img src="cmis_logo.png" alt="Logo" class="mobile-header-logo">
	<h1 class="mobile-header-title" id="mobileTitle">Sign In</h1>
  </div>
</div>

<div class="page-shell">
<div class="auth-card <?= !$show_login ? 'register-mode' : '' ?>">
	<div class="auth-hero">
		<div class="hero-badge">Garden of Remembrance</div>
		<h1 class="hero-title">Welcome to your memorial portal</h1>
		<p class="hero-text">Access your bookings and family memorial services with dignity, calm, and security.</p>
		<ul class="hero-list">
			<li>Secure sign-in</li>
			<li>Booking history</li>
			<li>Memorial support</li>
		</ul>
	</div>
	<div class="auth-form-panel">
	<div class="form-container">
		<img src="cmis_logo.png" alt="Garden of Remembrance Logo" class="brand-logo">

	<?php echo $message; ?>

	<?php if (true): ?>
	<form method="POST" action="" id="loginForm" style="display: <?= $show_login ? 'block' : 'none' ?>; max-width: 380px; margin: 0 auto;">
		<?php if (!empty($redirect_plot)): ?>
		<input type="hidden" name="redirect" value="plot">
		<input type="hidden" name="section" value="<?= htmlspecialchars($redirect_plot['section']) ?>">
		<input type="hidden" name="plot_x" value="<?= (int)$redirect_plot['plot_x'] ?>">
		<input type="hidden" name="plot_y" value="<?= (int)$redirect_plot['plot_y'] ?>">
		<?php endif; ?>
		<h3>Sign In
			
		</h3>
		<div class="mb-2">
			<input type="email" name="email" id="login_email" class="form-control" placeholder="Email" required autofocus>
		</div>
		<div class="mb-2 password-wrap">
			<input type="password" name="password" id="login_password" class="form-control" placeholder="Password" style="padding-right: 40px;" required>
			<button type="button" class="password-toggle-btn" onclick="togglePassword('login_password', this)" aria-label="Show or hide password">
				<i class="fas fa-eye"></i>
			</button>
		</div>

		<div class="d-flex justify-content-end mb-3 mt-1">
			<a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
		</div>

		<button type="submit" name="login" class="btn btn-primary w-100 mb-3">Sign In</button>
		<p class="text-center mb-1"><a href="javascript:void(0)" onclick="toggleForms()" class="text-decoration-none fw-semibold" style="color: var(--bs-primary); font-size: 0.9rem;">Don't have an account? <span style="text-decoration: underline;">Sign Up</span></a></p>
	</form>

	<form method="POST" action="" id="registerForm" style="display: <?= $show_login ? 'none' : 'block' ?>;" onsubmit="return validateRegisterForm(event)">
		<?php if (!empty($redirect_plot)): ?>
		<input type="hidden" name="redirect" value="plot">
		<input type="hidden" name="section" id="hidden_redirect_section" value="<?= htmlspecialchars($redirect_plot['section']) ?>">
		<input type="hidden" name="plot_x" id="hidden_redirect_plot_x" value="<?= (int)$redirect_plot['plot_x'] ?>">
		<input type="hidden" name="plot_y" id="hidden_redirect_plot_y" value="<?= (int)$redirect_plot['plot_y'] ?>">
		<?php endif; ?>
		<h3 class="mb-2 text-center" style="font-size: 1.35rem;">Sign Up</h3>

		<div class="row g-2 mb-1">
			<div class="col-6">
				<label class="form-label-compact" for="first_name">First Name <span class="text-danger">*</span></label>
				<input type="text" name="first_name" id="first_name" class="form-control" required oninput="this.value = this.value.toUpperCase()" autocomplete="given-name">
			</div>
			<div class="col-6">
				<label class="form-label-compact" for="middle_name">Middle Name (Optional)</label>
				<input type="text" name="middle_name" id="middle_name" class="form-control" oninput="this.value = this.value.toUpperCase()" autocomplete="additional-name">
			</div>
		</div>

		<div class="row g-2 mb-1">
			<div class="col-6">
				<label class="form-label-compact" for="last_name">Last Name <span class="text-danger">*</span></label>
				<input type="text" name="last_name" id="last_name" class="form-control" required oninput="this.value = this.value.toUpperCase()" autocomplete="family-name">
			</div>
			<div class="col-6">
				<label class="form-label-compact" for="suffix">Suffix</label>
				<select name="suffix" id="suffix" class="form-control">
					<option value="">No Suffix</option>
					<option value="JR.">JR.</option>
					<option value="SR.">SR.</option>
					<option value="I">I</option>
					<option value="II">II</option>
					<option value="III">III</option>
					<option value="IV">IV</option>
					<option value="V">V</option>
					<option value="VI">VI</option>
					<option value="OTHER">OTHER</option>
				</select>
				<input type="text" name="suffix_other" id="suffix_other" class="form-control mt-1" style="display:none;" oninput="this.value = this.value.toUpperCase()">
			</div>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var sel = document.getElementById('suffix');
			var other = document.getElementById('suffix_other');
			if (sel && other) {
				function sync() {
					if (sel.value === 'OTHER') { other.style.display = 'block'; }
					else { other.style.display = 'none'; other.value = ''; }
				}
				sel.addEventListener('change', sync);
				sync();
			}

			var decSel = document.getElementById('deceased_suffix');
			var decOther = document.getElementById('deceased_suffix_other');
			if (decSel && decOther) {
				function decSync() {
					if (decSel.value === 'OTHER') { decOther.style.display = 'block'; }
					else { decOther.style.display = 'none'; decOther.value = ''; }
				}
				decSel.addEventListener('change', decSync);
				decSync();
			}
		});
		</script>

		<div class="row g-2 mb-2">
			<div class="col-6 col-md-3">
				<label class="form-label-compact" for="birthday">Birthday <span class="text-danger">*</span></label>
				<input type="date" name="birthday" id="birthday" class="form-control" required max="<?= date('Y-m-d') ?>" autocomplete="bday">
			</div>
			<div class="col-6 col-md-3">
				<label class="form-label-compact" for="gender">Gender <span class="text-danger">*</span></label>
				<select name="gender" id="gender" class="form-control" required>
					<option value="">Select Gender</option>
					<option value="Male">Male</option>
					<option value="Female">Female</option>
				</select>
			</div>
			<div class="col-6 col-md-3">
				<label class="form-label-compact" for="phone_no">Phone Number <span class="text-danger">*</span></label>
				<input type="tel" name="phone_no" id="phone_no" class="form-control" required
					   minlength="11" maxlength="11" inputmode="numeric" pattern="09[0-9]{9}"
					   oninput="var val=this.value.replace(/[^0-9]/g,''); if(val.indexOf('639')===0) val='09'+val.substring(3); else if(val.indexOf('9')===0) val='09'+val.substring(1); else if(val.length>=2 && val.charAt(0)==='0' && val.charAt(1)!=='9') val='0'; else if(val.length===1 && val!=='0') val='09'; this.value=val.slice(0,11);" autocomplete="tel">
			</div>
			<div class="col-6 col-md-3">
				<label class="form-label-compact" for="email">Email <span class="text-danger">*</span></label>
				<input type="email" name="email" id="email" class="form-control" required oninput="this.value = this.value.replace(/\s/g, '')" autocomplete="email">
			</div>
		</div>

		<div class="registration-section my-2" style="padding:0.75rem;border:1px solid rgba(123,17,19,0.18);border-radius:0.75rem;background:#fff8f5;">
			<div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-2">
				<h6 class="m-0" style="color:#7b1113;font-size:0.82rem;font-weight:700;letter-spacing:0.3px;">DECEASED PERSON INFORMATION</h6>
				<span class="badge bg-light text-muted border" style="font-size:0.7rem;font-weight:500;">Cemetery verification</span>
			</div>
			<div class="row g-2 mb-2">
				<div class="col-6">
					<label class="form-label-compact" for="deceased_first_name">Deceased First Name <span class="text-danger">*</span></label>
					<input type="text" name="deceased_first_name" id="deceased_first_name" class="form-control" required oninput="this.value = this.value.toUpperCase()" autocomplete="given-name">
				</div>
				<div class="col-6">
					<label class="form-label-compact" for="deceased_middle_name">Deceased Middle Name</label>
					<input type="text" name="deceased_middle_name" id="deceased_middle_name" class="form-control" oninput="this.value = this.value.toUpperCase()" autocomplete="additional-name">
				</div>
			</div>
			<div class="row g-2 mb-2">
				<div class="col-6">
					<label class="form-label-compact" for="deceased_last_name">Deceased Last Name <span class="text-danger">*</span></label>
					<input type="text" name="deceased_last_name" id="deceased_last_name" class="form-control" required oninput="this.value = this.value.toUpperCase()" autocomplete="family-name">
				</div>
				<div class="col-6">
					<label class="form-label-compact" for="deceased_suffix">Deceased Suffix</label>
					<select name="deceased_suffix" id="deceased_suffix" class="form-control">
						<option value="">No Suffix</option>
						<option value="JR.">JR.</option>
						<option value="SR.">SR.</option>
						<option value="I">I</option>
						<option value="II">II</option>
						<option value="III">III</option>
						<option value="IV">IV</option>
						<option value="V">V</option>
						<option value="VI">VI</option>
						<option value="OTHER">OTHER</option>
					</select>
					<input type="text" name="deceased_suffix_other" id="deceased_suffix_other" class="form-control mt-1" style="display:none;" oninput="this.value = this.value.toUpperCase()">
				</div>
			</div>
			<div class="row g-2 mb-2">
				<div class="col-12 col-md-4">
					<label class="form-label-compact" for="deceased_birthday">Date of Birth <span class="text-danger">*</span></label>
					<input type="date" name="deceased_birthday" id="deceased_birthday" class="form-control" required max="<?= date('Y-m-d') ?>" autocomplete="bday">
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label-compact" for="deceased_date_died">Date of Death <span class="text-danger">*</span></label>
					<input type="date" name="deceased_date_died" id="deceased_date_died" class="form-control" required max="<?= date('Y-m-d') ?>" autocomplete="off">
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label-compact" for="deceased_relationship">Relationship <span class="text-muted small fw-normal">(Optional)</span></label>
					<select name="deceased_relationship" id="deceased_relationship" class="form-control" autocomplete="off">
						<option value="">Select Relationship (Optional)</option>
						<?php 
						$curRel = $deceased_relationship ?? '';
						foreach (['PARENT', 'SPOUSE', 'CHILD', 'RELATIVE', 'OTHER'] as $relationship): 
						?>
							<option value="<?= $relationship ?>" <?= ($curRel === $relationship ? 'selected' : '') ?>><?= ucfirst(strtolower($relationship)) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<button type="button" id="verifyDeceasedButton" class="btn btn-primary w-100 py-2 mt-2" style="font-size:0.88rem;" onclick="verifyDeceasedRecord()">Verify Deceased Record</button>
			<div id="deceasedVerificationMessage" class="small mt-1" role="status"></div>
			<div id="deceasedVerificationDetails" class="small mt-1 p-2 rounded" style="display:none;background:#eef8f0;border:1px solid #b7dfbf;font-size:0.75rem;"></div>
		</div>

		<div class="row g-2 mb-2">
			<div class="col-12 col-md-6">
				<div class="password-toggle-wrapper">
					<label class="form-label-compact" for="password">Password <span class="text-danger">*</span></label>
					<input type="password" name="password" id="password" class="form-control" required autocomplete="new-password">
					<button type="button" class="password-toggle-button" onclick="togglePassword('password', this)" aria-label="Show or hide password"><i class="fas fa-eye"></i></button>
				</div>
			</div>
			<div class="col-12 col-md-6">
				<div class="password-toggle-wrapper">
					<label class="form-label-compact" for="confirm_password">Confirm Password <span class="text-danger">*</span></label>
					<input type="password" name="confirm_password" id="confirm_password" class="form-control" required autocomplete="new-password">
					<button type="button" class="password-toggle-button" onclick="togglePassword('confirm_password', this)" aria-label="Show or hide password"><i class="fas fa-eye"></i></button>
				</div>
			</div>
		</div>

		<div class="password-strength-box my-1 p-2 rounded" style="background: rgba(245, 239, 232, 0.6); border: 1px solid rgba(140, 91, 45, 0.18); font-size: 0.72rem;">
			<div class="d-flex justify-content-between align-items-center mb-1">
				<span class="text-muted fw-semibold" style="font-size: 0.68rem; letter-spacing: 0.03em;">PASSWORD STRENGTH:</span>
				<span id="strengthText" class="fw-bold" style="font-size: 0.7rem; color: #dc2626;">Too Weak</span>
			</div>
			<div class="progress" style="height: 4px; border-radius: 4px; background: #e2e8f0; margin-bottom: 0.35rem;">
				<div id="strengthBar" class="progress-bar bg-danger" role="progressbar" style="width: 0%; transition: width 0.3s ease, background-color 0.3s ease;"></div>
			</div>
			<div class="row g-1 text-muted" style="font-size: 0.68rem; line-height: 1.25;">
				<div class="col-sm-6" id="rule-len" style="color: #dc2626;"><i class="fas fa-circle-xmark me-1"></i> At least 8 characters</div>
				<div class="col-sm-6" id="rule-upper" style="color: #dc2626;"><i class="fas fa-circle-xmark me-1"></i> Uppercase letter (A-Z)</div>
				<div class="col-sm-6" id="rule-lower" style="color: #dc2626;"><i class="fas fa-circle-xmark me-1"></i> Lowercase letter (a-z)</div>
				<div class="col-sm-6" id="rule-num" style="color: #dc2626;"><i class="fas fa-circle-xmark me-1"></i> At least one number (0-9)</div>
				<div class="col-12" id="rule-sym" style="color: #dc2626;"><i class="fas fa-circle-xmark me-1"></i> Special character (!@#$%^&*)</div>
			</div>
		</div>
		<div id="passwordMatchMessage" class="small mb-1" style="display: none; font-size: 0.75rem; font-weight: 600;"></div>

		<button type="submit" name="register" id="createAccountButton" class="btn btn-primary w-100 py-2 mb-1" style="font-size:0.95rem;">Sign Up</button>
		<p class="text-center mb-0 mt-1" style="font-size:0.85rem;"><span class="form-toggle" onclick="toggleForms()">Already have an account? <strong>Sign In</strong></span></p>
	</form>
<?php endif; ?>
	</div>
	</div>
</div>
</div>

<script>
function toggleForms() {
	const loginForm = document.getElementById('loginForm');
	const registerForm = document.getElementById('registerForm');
	const card = document.querySelector('.auth-card');
	const isLoginVisible = (loginForm.style.display !== 'none');
	if (isLoginVisible) {
		loginForm.style.display = 'none';
		registerForm.style.display = 'block';
		if (card) card.classList.add('register-mode');
		document.body.classList.add('is-register-active');
	} else {
		loginForm.style.display = 'block';
		registerForm.style.display = 'none';
		if (card) card.classList.remove('register-mode');
		document.body.classList.remove('is-register-active');
	}
	const loginPlotBanner = document.getElementById('loginPlotBanner');
	const registerPlotBanner = document.getElementById('registerPlotBanner');
	const registerGeneralBanner = document.getElementById('registerGeneralBanner');
	if (loginPlotBanner && registerPlotBanner) {
		if (isLoginVisible) {
			loginPlotBanner.style.display = 'none';
			registerPlotBanner.style.display = 'block';
		} else {
			loginPlotBanner.style.display = 'block';
			registerPlotBanner.style.display = 'none';
		}
	}
	if (registerGeneralBanner) {
		registerGeneralBanner.style.display = isLoginVisible ? 'block' : 'none';
	}
	const panel = document.querySelector('.auth-form-panel');
	if (panel) panel.scrollTop = 0;
}

function togglePassword(fieldId, button) {
	const field = document.getElementById(fieldId);
	if (!field) return;
	const isPassword = field.type === 'password';
	field.type = isPassword ? 'text' : 'password';
	button.innerHTML = isPassword ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
	button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
}

function updatePasswordRules(p1) {
	p1 = p1 || '';
	const hasLen = p1.length >= 8;
	const hasUpper = /[A-Z]/.test(p1);
	const hasLower = /[a-z]/.test(p1);
	const hasNum = /[0-9]/.test(p1);
	const hasSym = /[^A-Za-z0-9]/.test(p1);

	function setRule(id, valid) {
		const el = document.getElementById(id);
		if (!el) return;
		const icon = el.querySelector('i');
		if (valid) {
			el.style.color = '#16a34a';
			if (icon) icon.className = 'fas fa-circle-check me-1';
		} else {
			el.style.color = '#dc2626';
			if (icon) icon.className = 'fas fa-circle-xmark me-1';
		}
	}

	setRule('rule-len', hasLen);
	setRule('rule-upper', hasUpper);
	setRule('rule-lower', hasLower);
	setRule('rule-num', hasNum);
	setRule('rule-sym', hasSym);

	let score = 0;
	if (hasLen) score++;
	if (hasUpper) score++;
	if (hasLower) score++;
	if (hasNum) score++;
	if (hasSym) score++;

	const bar = document.getElementById('strengthBar');
	const text = document.getElementById('strengthText');
	if (bar && text) {
		if (!p1 || score === 0) {
			bar.style.width = '0%';
			bar.className = 'progress-bar bg-danger';
			text.textContent = 'Too Weak';
			text.style.color = '#dc2626';
		} else if (score <= 2) {
			bar.style.width = '35%';
			bar.className = 'progress-bar bg-danger';
			text.textContent = 'Weak';
			text.style.color = '#dc2626';
		} else if (score <= 4) {
			bar.style.width = '70%';
			bar.className = 'progress-bar bg-warning';
			text.textContent = 'Moderate';
			text.style.color = '#d97706';
		} else {
			bar.style.width = '100%';
			bar.className = 'progress-bar bg-success';
			text.textContent = 'Strong';
			text.style.color = '#16a34a';
		}
	}

	return hasLen && hasUpper && hasLower && hasNum && hasSym;
}

function checkPasswordMatch() {
	const pass = document.getElementById('password') ? document.getElementById('password').value : '';
	const confirm = document.getElementById('confirm_password') ? document.getElementById('confirm_password').value : '';
	const msg = document.getElementById('passwordMatchMessage');
	if (!msg) return;

	if (!confirm) {
		msg.style.display = 'none';
		return;
	}

	msg.style.display = 'block';
	if (pass === confirm) {
		msg.textContent = '✓ Passwords match';
		msg.style.color = '#16a34a';
	} else {
		msg.textContent = '✗ Passwords do not match';
		msg.style.color = '#dc2626';
	}
}

function validateRegisterForm(e) {
	const pass = document.getElementById('password') ? document.getElementById('password').value : '';
	const confirm = document.getElementById('confirm_password') ? document.getElementById('confirm_password').value : '';

	if (!updatePasswordRules(pass)) {
		alert("❌ Password does not meet strong security requirements:\n• At least 8 characters\n• Uppercase letter (A-Z)\n• Lowercase letter (a-z)\n• At least one number (0-9)\n• Special character (!@#$%^&*)");
		const pField = document.getElementById('password');
		if (pField) pField.focus();
		if (e) e.preventDefault();
		return false;
	}

	if (pass !== confirm) {
		alert("❌ Passwords do not match.");
		const cField = document.getElementById('confirm_password');
		if (cField) cField.focus();
		if (e) e.preventDefault();
		return false;
	}

	const phoneInput = document.getElementById('phone_no');
	if (phoneInput) {
		const val = phoneInput.value.replace(/\D/g, '');
		if (!/^09\d{9}$/.test(val)) {
			phoneInput.classList.add('is-invalid');
			alert('Phone number must be an 11-digit mobile number starting with 09 (e.g. 09XXXXXXXXX).');
			phoneInput.focus();
			if (e) e.preventDefault();
			return false;
		} else {
			phoneInput.classList.remove('is-invalid');
		}
	}
	return true;
}

function validatePasswords() {
	return validateRegisterForm();
}

async function verifyDeceasedRecord() {
	const form = document.getElementById('registerForm');
	const button = document.getElementById('verifyDeceasedButton');
	const message = document.getElementById('deceasedVerificationMessage');
	const details = document.getElementById('deceasedVerificationDetails');
	const createButton = document.getElementById('createAccountButton');
	const fields = ['deceased_first_name', 'deceased_middle_name', 'deceased_last_name', 'deceased_suffix', 'deceased_suffix_other', 'deceased_birthday', 'deceased_date_died'];
	const body = new URLSearchParams();
	body.set('verify_deceased', '1');
	fields.forEach(function(name) { const field = form.elements[name]; body.set(name, field ? field.value : ''); });
	details.style.display = 'none';
	message.textContent = 'Checking cemetery records...';
	message.className = 'small mt-2 text-muted';
	button.disabled = true;
	try {
		const response = await fetch('login.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'}, body: body.toString() });
		const data = await response.json();
		if (!response.ok || !data.success) throw new Error(data.message || 'No matching deceased record was found.');
		message.textContent = data.message;
		message.className = 'small mt-1 text-success';

		var html = '<div class="p-3 border rounded text-start shadow-sm" style="background:#f9fbf9;border-color:#b7dfbf;">';
		html += '<div class="fw-bold text-success mb-2" style="font-size:0.92rem;"><i class="fas fa-check-circle me-1"></i> Deceased Record Found</div>';
		html += '<div style="font-size:0.83rem;line-height:1.7;">';
		html += '<div><strong>Name:</strong> ' + data.deceased.name + '</div>';
		html += '<div><strong>Date of Birth:</strong> ' + data.deceased.bday + '</div>';
		html += '<div><strong>Date of Death:</strong> ' + data.deceased.date_died + '</div>';
		html += '<div><strong>Section:</strong> <span class="badge bg-secondary text-white">' + data.deceased.section + '</span></div>';
		html += '<div><strong>Plot/Row:</strong> ' + data.deceased.plot_row + '</div>';
		html += '<div><strong>Status:</strong> <span class="badge bg-success text-white">' + data.deceased.status + '</span></div>';
		html += '<div class="mt-2 pt-2 border-top text-dark" style="border-color:#d4edd9 !important;">';
		html += '<strong>Verified Grave Location:</strong> <span class="fw-bold" style="color:#7b1113;">' + data.deceased.current_grave + '</span>';
		html += '</div>';
		html += '</div>';
		html += '</div>';

		details.innerHTML = html;
		details.style.display = 'block';
		createButton.disabled = false;
	} catch (error) {
		message.textContent = error.message;
		message.className = 'small mt-2 text-danger';
		details.style.display = 'none';
	} finally {
		button.disabled = false;
	}
}

// Update mobile header title based on active form
document.addEventListener('DOMContentLoaded', function() {
	const loginForm = document.getElementById('loginForm');
	const registerForm = document.getElementById('registerForm');
	const mobileTitle = document.getElementById('mobileTitle');

	function updateMobileTitle() {
		if (mobileTitle) {
			if (loginForm && loginForm.style.display !== 'none') {
				mobileTitle.textContent = 'Sign In';
			} else if (registerForm && registerForm.style.display !== 'none') {
				mobileTitle.textContent = 'Register';
			}
		}
	}

	// Update title when forms toggle
	const originalToggleForms = toggleForms;
	toggleForms = function() {
		originalToggleForms();
		updateMobileTitle();
	};

	// Set initial title
	updateMobileTitle();

	// Real-time password strength & match monitoring
	const passInput = document.getElementById('password');
	const confirmInput = document.getElementById('confirm_password');
	if (passInput) {
		passInput.addEventListener('input', function() {
			updatePasswordRules(this.value);
			checkPasswordMatch();
		});
	}
	if (confirmInput) {
		confirmInput.addEventListener('input', checkPasswordMatch);
	}
});

</script>
</body>
</html>

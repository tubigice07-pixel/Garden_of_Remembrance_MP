<?php

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Asia/Manila');
}

require_once __DIR__ . '/email_template.php';

$mail_config = [];
$configPath = null;

function is_valid_smtp_config(array $config): bool {
    $smtp = $config['smtp'] ?? [];
    return !empty($smtp['host']) && !empty($smtp['username']) && !empty($smtp['password']) && !empty($smtp['port']);
}

function is_enabled_smtp_config(array $config): bool {
    $smtp = $config['smtp'] ?? [];
    return !empty($smtp['enabled']) && $smtp['enabled'] && is_valid_smtp_config($config);
}

$configCandidates = [
    __DIR__ . '/config/mail_credentials.php',
    __DIR__ . '/config/mail_config.php',
    __DIR__ . '/config/email_config.php',
];

foreach ($configCandidates as $path) {
    if (!file_exists($path)) {
        continue;
    }
    $candidateConfig = include $path;
    if (!is_array($candidateConfig)) {
        continue;
    }
    if (is_enabled_smtp_config($candidateConfig)) {
        $mail_config = $candidateConfig;
        $configPath = $path;
        break;
    }
}

function get_loaded_smtp_config_path(): string {
    global $configPath;
    return $configPath ?: 'none';
}

function sanitize_smtp_host(string $host): string {
    $host = trim($host);
    $host = preg_replace('#^https?://#i', '', $host);
    $host = preg_replace('#^.*@#', '', $host);
    $host = preg_replace('#/.*$#', '', $host);
    $host = trim($host, " \t\n\r\0\x0B\"'");
    if (strpos($host, ':') !== false) {
        $host = explode(':', $host, 2)[0];
    }
    $host = trim($host, " \t\n\r\0\x0B\"'");
    if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
        return '';
    }
    return $host;
}

function has_placeholder_smtp_password(string $password): bool {
    $password = trim($password);
    return $password === '' || strtolower($password) === 'paste_your_gmail_app_password_here';
}

try {
    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        $phpmailer_paths = [
            __DIR__ . '/vendor/PHPMailer-master/src/',
            __DIR__ . '/PHPMailer/',
            __DIR__ . '/vendor/phpmailer/phpmailer/src/',
        ];
        foreach ($phpmailer_paths as $pDir) {
            if (file_exists($pDir . 'PHPMailer.php') && file_exists($pDir . 'Exception.php')) {
                require_once $pDir . 'Exception.php';
                require_once $pDir . 'PHPMailer.php';
                if (file_exists($pDir . 'SMTP.php')) {
                    require_once $pDir . 'SMTP.php';
                }
                break;
            }
        }
        if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            if (file_exists(__DIR__ . '/PHPMailer/autoload.php')) {
                @include_once __DIR__ . '/PHPMailer/autoload.php';
            }
            if (file_exists(__DIR__ . '/vendor/autoload.php')) {
                @include_once __DIR__ . '/vendor/autoload.php';
            }
        }
    }
} catch (Throwable $loadEx) {
    error_log('PHPMailer autoloader notice: ' . $loadEx->getMessage());
}

$mail_error = '';
function get_mail_error() {
    global $mail_error;
    return $mail_error;
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

function build_verification_email_html(string $name, string $verifyUrl): string {
    return render_branded_email([
        'title' => 'Verify Your Account',
        'badge' => 'Account Activation',
        'greeting_name' => $name,
        'intro_lines' => [
            'Thank you for registering with Garden of Remembrance Memorial Park.',
            'Please confirm your email address by clicking the button below. This helps keep your account secure and ensures you receive important booking updates and receipts.'
        ],
        'action_button' => [
            'text' => 'Verify Email Address',
            'url' => $verifyUrl
        ],
        'outro_lines' => [
            'If the button above does not work, please copy and paste the following link into your web browser:',
            $verifyUrl,
            'If you did not register for an account with Garden of Remembrance, you can safely disregard this email.'
        ]
    ]);
}

function build_verification_email_text(string $name, string $verifyUrl): string {
    $cleanName = trim($name);
    return "Hello $cleanName,\n\nThank you for registering with Garden of Remembrance Memorial Park.\n\nPlease verify your email address by clicking the link below:\n$verifyUrl\n\nIf you did not register, please ignore this email.\n\nGarden of Remembrance Memorial Park\nCemetery Management and Grave Mapping System";
}

function send_password_reset_otp_email(string $email, string $name, string $otp, bool $isAdmin = false): bool {
    $roleTitle = $isAdmin ? 'Administrator Password Reset' : 'Password Reset Verification';
    $htmlBody = render_branded_email([
        'title' => $roleTitle,
        'badge' => $isAdmin ? 'Staff Security' : 'Security Verification',
        'greeting_name' => $name ?: 'Account Owner',
        'intro_lines' => [
            'We received a request to reset the password for your Garden of Remembrance ' . ($isAdmin ? 'administrator' : 'user') . ' account.',
            'Please enter the 6-digit verification code below to authorize the password change:'
        ],
        'otp_code' => $otp,
        'otp_expiry_minutes' => 10,
        'outro_lines' => [
            'This verification code is strictly confidential and will expire in 10 minutes.',
            'If you did not request a password reset, please ignore this email or contact support immediately.'
        ]
    ]);

    $subject = ($isAdmin ? 'Admin Password Reset Code' : 'Password Reset Verification') . ' - Garden of Remembrance';
    return send_mail($email, $subject, $htmlBody, true);
}

function send_registration_otp_email(string $email, string $name, string $otp, bool $isAdmin = false): bool {
    $subject = $isAdmin ? 'Garden of Remembrance - Admin Email Verification' : 'Garden of Remembrance - Email Verification';
    $htmlBody = render_branded_email([
        'title' => 'Email Verification',
        'badge' => $isAdmin ? 'Admin Verification' : 'Email Verification',
        'greeting_name' => $name ?: ($isAdmin ? 'Administrator' : 'Valued Customer'),
        'intro_lines' => [
            'Thank you for registering with Garden of Remembrance Memorial Park.',
            'Please use the 6-digit verification code below to verify your email address and activate your account:'
        ],
        'otp_code' => $otp,
        'otp_expiry_minutes' => 5,
        'outro_lines' => [
            'Your verification code is: ' . $otp,
            'This code will expire in 5 minutes.',
            'Do not share this code with anyone.'
        ],
        'footer_note' => 'Garden of Remembrance Memorial Park • Cemetery Management and Grave Mapping System'
    ]);

    return send_mail($email, $subject, $htmlBody, true);
}

function get_mailer_db_connection(?mysqli $conn = null): ?mysqli {
    if ($conn instanceof mysqli && @$conn->ping()) {
        return $conn;
    }
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli && @$GLOBALS['conn']->ping()) {
        return $GLOBALS['conn'];
    }
    @include_once __DIR__ . '/connection.php';
    if (isset($conn) && $conn instanceof mysqli) {
        return $conn;
    }
    return null;
}

function send_booking_confirmation_email(int $booking_id, ?mysqli $conn = null): bool {
    $db = get_mailer_db_connection($conn);
    if (!$db || $booking_id <= 0) {
        return false;
    }

    $stmt = $db->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $booking_id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$b) {
        return false;
    }

    $customerName = trim($b['customer_name'] ?? '');
    if (empty($customerName)) {
        $customerName = trim($b['full_name'] ?? '');
    }
    if (empty($customerName) && !empty($b['user_id'])) {
        $uStmt = $db->prepare("SELECT first_name, last_name, email FROM tbl_user WHERE user_id = ? LIMIT 1");
        if ($uStmt) {
            $uStmt->bind_param('i', $b['user_id']);
            $uStmt->execute();
            if ($uRow = $uStmt->get_result()->fetch_assoc()) {
                $customerName = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
                if (empty($b['customer_email']) && empty($b['email'])) {
                    $b['customer_email'] = $uRow['email'] ?? '';
                }
            }
            $uStmt->close();
        }
    }
    if (empty($customerName)) {
        $customerName = 'Valued Customer';
    }

    $recipientEmail = strtolower(trim($b['customer_email'] ?? ''));
    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        $recipientEmail = strtolower(trim($b['email'] ?? ''));
    }

    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("Mailer notice: Booking #$booking_id does not have a valid customer recipient email.");
        return false;
    }

    $section = strtoupper(trim($b['section'] ?? 'A'));
    $plotDisplay = trim($b['plot'] ?? $b['grave_location'] ?? '');
    if (empty($plotDisplay)) {
        $plotDisplay = "Section $section";
    }
    $plotType = ucwords(trim($b['plot_type'] ?? 'Regular'));
    $area = ucwords(trim($b['area'] ?? 'Lawn Area'));
    $numLots = max(1, (int)($b['num_lots'] ?? 1));
    $paymentPlanRaw = strtolower(trim($b['payment_plan'] ?? 'full_payment'));
    $paymentTerm = (int)($b['payment_term'] ?? 0);
    $totalAmount = (float)($b['total_amount'] ?? 85000.00);

    $confirmedPaid = 0.00;
    $rcptStmt = $db->prepare("SELECT SUM(amount_paid) AS total_paid, COUNT(*) AS paid_count FROM payment_receipts WHERE booking_id = ? AND UPPER(payment_status) = 'PAID'");
    if ($rcptStmt) {
        $rcptStmt->bind_param('i', $booking_id);
        $rcptStmt->execute();
        $rRow = $rcptStmt->get_result()->fetch_assoc();
        if ($rRow) {
            $confirmedPaid = (float)($rRow['total_paid'] ?? 0.00);
        }
        $rcptStmt->close();
    }

    if ($confirmedPaid <= 0 && !empty($b['amount_paid']) && strtolower($b['status']) === 'approved') {
        $confirmedPaid = (float)$b['amount_paid'];
    }

    $remainingBalance = max(0.00, $totalAmount - $confirmedPaid);
    $statusText = ucwords(str_replace('_', ' ', trim($b['status'] ?? 'Pending Verification')));

    $detailsTable = [
        'Booking Reference' => '#' . $booking_id,
        'Section' => $section,
        'Grave / Plot Location' => $plotDisplay,
        'Lot Type' => $plotType,
        'Area' => $area,
        'Number of Lots' => (string)$numLots,
    ];

    if (!empty($b['deceased_name'])) {
        $detailsTable['Deceased Name'] = trim($b['deceased_name']);
    }
    if (!empty($b['preferred_date'])) {
        $detailsTable['Preferred Date'] = date('F j, Y', strtotime($b['preferred_date']));
    }

    $paymentTable = [];
    if ($paymentPlanRaw === 'installment' && $paymentTerm > 0) {
        $detailsTable['Payment Option'] = $paymentTerm . '-Month Installment';
        $monthlyInstallment = round($totalAmount / $paymentTerm, 2);

        $paymentTable['Total Plan'] = '₱' . number_format($totalAmount, 2);
        $paymentTable['Monthly Installment'] = '₱' . number_format($monthlyInstallment, 2);
        $paymentTable['Amount Confirmed Paid'] = '₱' . number_format($confirmedPaid, 2);
        $paymentTable['Remaining Balance'] = '₱' . number_format($remainingBalance, 2);
        $paymentTable['Booking Status'] = $statusText;
    } else {
        $detailsTable['Payment Option'] = 'Full Payment (' . ($b['payment_type'] ?? 'Cash on Site') . ')';
        $paymentTable['Total Amount'] = '₱' . number_format($totalAmount, 2);
        $paymentTable['Amount Confirmed Paid'] = '₱' . number_format($confirmedPaid, 2);
        $paymentTable['Remaining Balance'] = '₱' . number_format($remainingBalance, 2);
        $paymentTable['Booking Status'] = $statusText;
    }

    $htmlBody = render_branded_email([
        'title' => 'Booking Confirmation',
        'badge' => 'Booking #' . $booking_id,
        'greeting_name' => $customerName,
        'intro_lines' => [
            'Your memorial reservation booking has been successfully recorded in our system.',
            'Below are the verified details and payment information for your records:'
        ],
        'details_section_title' => 'Reservation Details',
        'details_table' => $detailsTable,
        'payment_summary_title' => 'Payment Summary',
        'payment_table' => $paymentTable,
        'action_button' => [
            'text' => 'Access Your Dashboard',
            'url' => get_email_asset_base_url() . '/login.php'
        ],
        'outro_lines' => [
            'Our memorial administration staff will review your booking requirements with utmost care.',
            'Please present your Booking Reference #' . $booking_id . ' when making in-person inquiries or submitting payment receipts.'
        ],
        'cancel_action' => [
            'text' => 'Request Reservation Cancellation',
            'url' => rtrim(get_email_asset_base_url(), '/\\') . '/cancel_reservation.php?booking_id=' . $booking_id . '&token=' . hash_hmac('sha256', 'cancel_' . $booking_id . '_' . $recipientEmail, 'GOR_CANCEL_SECRET_2026')
        ]
    ]);

    $subject = "Booking Confirmation #{$booking_id} - Garden of Remembrance";
    $sent = send_mail($recipientEmail, $subject, $htmlBody, true);
    if ($db) {
        $uId = (int)($b['user_id'] ?? 0);
        $st = $sent ? 'sent' : 'failed';
        $err = $sent ? null : get_mail_error();
        @$logStmt = $db->prepare("INSERT INTO email_notifications (user_id, booking_id, recipient_email, type, subject, status, error_message, sent_at, created_at) VALUES (?, ?, ?, 'booking_confirmation', ?, ?, ?, " . ($sent ? "NOW()" : "NULL") . ", NOW())");
        if ($logStmt) {
            $logStmt->bind_param('iissss', $uId, $booking_id, $recipientEmail, $subject, $st, $err);
            $logStmt->execute();
            $logStmt->close();
        }
    }
    return $sent;
}

function send_plot_purchase_confirmation_email(int $booking_id, ?mysqli $conn = null): bool {
    $db = get_mailer_db_connection($conn);
    if (!$db || $booking_id <= 0) {
        return false;
    }

    $stmt = $db->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $booking_id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$b) {
        return false;
    }

    $customerName = trim($b['customer_name'] ?? '');
    if (empty($customerName)) {
        $customerName = trim($b['full_name'] ?? '');
    }
    if (empty($customerName) && !empty($b['user_id'])) {
        $uStmt = $db->prepare("SELECT first_name, last_name, email FROM tbl_user WHERE user_id = ? LIMIT 1");
        if ($uStmt) {
            $uStmt->bind_param('i', $b['user_id']);
            $uStmt->execute();
            if ($uRow = $uStmt->get_result()->fetch_assoc()) {
                $customerName = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
                if (empty($b['customer_email']) && empty($b['email'])) {
                    $b['customer_email'] = $uRow['email'] ?? '';
                }
            }
            $uStmt->close();
        }
    }
    if (empty($customerName)) {
        $customerName = 'Valued Client';
    }

    $recipientEmail = strtolower(trim($b['customer_email'] ?? ''));
    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        $recipientEmail = strtolower(trim($b['email'] ?? ''));
    }

    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("Mailer notice: Plot Purchase Booking #$booking_id does not have a valid customer recipient email.");
        return false;
    }

    $section = strtoupper(trim($b['section'] ?? 'A'));
    $plotDisplay = trim($b['plot'] ?? $b['grave_location'] ?? '');
    if (!empty($plotDisplay) && preg_match('/(\d+)\s*[-,\s]\s*(\d+)/', $plotDisplay, $m)) {
        $plotDisplay = "Section $section · Row {$m[1]} · Plot {$m[2]}";
    } elseif (empty($plotDisplay)) {
        $plotDisplay = "Section $section";
    }

    $plotType = ucwords(trim($b['plot_type'] ?? 'Regular'));
    $paymentPlanRaw = strtolower(trim($b['payment_plan'] ?? 'cash'));
    $paymentTerm = (int)($b['payment_term'] ?? 0);
    $totalAmount = (float)($b['total_amount'] ?? 85000.00);
    $amountPaid = (float)($b['amount_paid'] ?? 0.00);

    // If paid in receipts
    $rcptStmt = $db->prepare("SELECT SUM(amount_paid) AS total_paid FROM payment_receipts WHERE booking_id = ? AND UPPER(payment_status) = 'PAID'");
    if ($rcptStmt) {
        $rcptStmt->bind_param('i', $booking_id);
        $rcptStmt->execute();
        $rRow = $rcptStmt->get_result()->fetch_assoc();
        if ($rRow && (float)($rRow['total_paid'] ?? 0) > 0) {
            $amountPaid = (float)$rRow['total_paid'];
        }
        $rcptStmt->close();
    }

    if ($paymentPlanRaw === 'cash' && $amountPaid <= 0 && $totalAmount > 0) {
        $amountPaid = $totalAmount;
    }

    $remainingBalance = max(0.00, $totalAmount - $amountPaid);
    $statusText = ($remainingBalance <= 0) ? 'Paid in Full' : 'Has Remaining Balance';

    $detailsTable = [
        'Transaction Reference' => '#' . $booking_id,
        'Memorial Section' => 'Section ' . $section,
        'Plot Location' => $plotDisplay,
        'Lot Type' => $plotType . ' Lawn Lot',
        'Registered Owner / Buyer' => $customerName,
        'Contact Number' => trim($b['contact_number'] ?? '—'),
        'Email Address' => $recipientEmail,
        'Registration Date' => date('F j, Y g:i A', strtotime($b['created_at'] ?? 'now')),
    ];

    $paymentTable = [];
    if ($paymentPlanRaw === 'installment' && $paymentTerm > 0) {
        $monthlyInstallment = round($totalAmount / $paymentTerm, 2);
        $paymentTable['Payment Plan'] = $paymentTerm . '-Month Installment';
        $paymentTable['Total Contract Price'] = '₱' . number_format($totalAmount, 2);
        $paymentTable['Monthly Amortization'] = '₱' . number_format($monthlyInstallment, 2);
        $paymentTable['Amount Paid / Downpayment'] = '₱' . number_format($amountPaid, 2);
        $paymentTable['Remaining Balance'] = '₱' . number_format($remainingBalance, 2);
        $paymentTable['Account Status'] = $statusText;
    } else {
        $paymentTable['Payment Plan'] = 'Cash (Full Payment)';
        $paymentTable['Total Amount'] = '₱' . number_format($totalAmount, 2);
        $paymentTable['Amount Paid'] = '₱' . number_format($amountPaid, 2);
        $paymentTable['Remaining Balance'] = '₱' . number_format($remainingBalance, 2);
        $paymentTable['Payment Status'] = 'Paid in Full (Verified)';
    }

    $mapUrl = rtrim(get_email_asset_base_url(), '/\\') . '/sections/popup.php?section=' . urlencode($section);

    $htmlBody = render_branded_email([
        'title' => 'Plot Purchase Confirmation',
        'badge' => 'Official Plot Purchase #' . $booking_id,
        'greeting_name' => $customerName,
        'intro_lines' => [
            'Congratulations on securing your memorial plot at Garden of Remembrance Memorial Park.',
            'Your plot purchase has been officially approved and recorded in our cemetery mapping and records system.',
            'Below is the verified summary of your purchased memorial plot and payment information:'
        ],
        'details_section_title' => 'Plot & Ownership Details',
        'details_table' => $detailsTable,
        'payment_summary_title' => 'Payment Summary',
        'payment_table' => $paymentTable,
        'action_button' => [
            'text' => 'View Plot on Cemetery Map',
            'url' => $mapUrl
        ],
        'outro_lines' => [
            'This confirmation serves as your digital purchase summary and official proof of plot registration.',
            'Please keep your Reference Number #' . $booking_id . ' for your records and when inquiring about future cemetery services.'
        ]
    ]);

    $subject = "Official Plot Purchase Confirmation #{$booking_id} - Garden of Remembrance";
    $sent = send_mail($recipientEmail, $subject, $htmlBody, true);
    if ($db) {
        $uId = (int)($b['user_id'] ?? 0);
        $st = $sent ? 'sent' : 'failed';
        $err = $sent ? null : get_mail_error();
        @$logStmt = $db->prepare("INSERT INTO email_notifications (user_id, booking_id, recipient_email, type, subject, status, error_message, sent_at, created_at) VALUES (?, ?, ?, 'plot_purchase_confirmation', ?, ?, ?, " . ($sent ? "NOW()" : "NULL") . ", NOW())");
        if ($logStmt) {
            $logStmt->bind_param('iissss', $uId, $booking_id, $recipientEmail, $subject, $st, $err);
            $logStmt->execute();
            $logStmt->close();
        }
    }
    return $sent;
}

function send_payment_update_email(int $booking_id, float $amount, string $ref_no = '', string $payment_status = 'Pending Verification', ?mysqli $conn = null): bool {
    $db = get_mailer_db_connection($conn);
    if (!$db || $booking_id <= 0) {
        return false;
    }

    $stmt = $db->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $booking_id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$b) return false;

    $customerName = trim($b['customer_name'] ?? $b['full_name'] ?? 'Valued Customer');
    $recipientEmail = strtolower(trim($b['customer_email'] ?? $b['email'] ?? ''));
    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $totalAmount = (float)($b['total_amount'] ?? 85000.00);
    $paymentPlan = strtolower(trim($b['payment_plan'] ?? 'full_payment'));
    $paymentTerm = (int)($b['payment_term'] ?? 0);

    $confirmedPaid = 0.00;
    $paidCount = 0;
    $rcptStmt = $db->prepare("SELECT SUM(amount_paid) AS total_paid, COUNT(*) AS paid_count FROM payment_receipts WHERE booking_id = ? AND UPPER(payment_status) = 'PAID'");
    if ($rcptStmt) {
        $rcptStmt->bind_param('i', $booking_id);
        $rcptStmt->execute();
        $rRow = $rcptStmt->get_result()->fetch_assoc();
        if ($rRow) {
            $confirmedPaid = (float)($rRow['total_paid'] ?? 0.00);
            $paidCount = (int)($rRow['paid_count'] ?? 0);
        }
        $rcptStmt->close();
    }

    if (strtoupper($payment_status) === 'PAID' && $confirmedPaid <= 0 && $amount > 0) {
        $confirmedPaid = $amount;
        $paidCount = 1;
    }

    $remainingBalance = max(0.00, $totalAmount - $confirmedPaid);

    $paymentTable = [
        'Booking Reference' => '#' . $booking_id,
        'Amount Recorded' => '₱' . number_format($amount, 2),
        'Payment Status' => $payment_status,
    ];

    if (!empty($ref_no)) {
        $paymentTable['Reference Number'] = $ref_no;
    }

    if ($paymentPlan === 'installment' && $paymentTerm > 0) {
        $paymentTable['Installment Progress'] = ($paidCount > 0 ? $paidCount : 1) . ' of ' . $paymentTerm;
    }

    $paymentTable['Total Plan'] = '₱' . number_format($totalAmount, 2);
    $paymentTable['Total Confirmed Paid'] = '₱' . number_format($confirmedPaid, 2);
    $paymentTable['Remaining Balance'] = '₱' . number_format($remainingBalance, 2);

    $htmlBody = render_branded_email([
        'title' => 'Payment Information Update',
        'badge' => 'Official Receipt & Status',
        'greeting_name' => $customerName,
        'intro_lines' => [
            'Your payment information for Booking #' . $booking_id . ' has been updated.',
            'Here is the verified payment and balance summary for your account:'
        ],
        'details_section_title' => 'Payment Summary & Balance',
        'details_table' => $paymentTable,
        'action_button' => [
            'text' => 'View Your Account & Bookings',
            'url' => get_email_asset_base_url() . '/login.php'
        ],
        'outro_lines' => [
            'Please keep this payment confirmation for your personal records.',
            'If you have any questions regarding your billing schedule, our staff is available to assist you.'
        ]
    ]);

    $subject = "Payment Update for Booking #{$booking_id} - Garden of Remembrance";
    $sent = send_mail($recipientEmail, $subject, $htmlBody, true);
    if ($db) {
        $uId = (int)($b['user_id'] ?? 0);
        $st = $sent ? 'sent' : 'failed';
        $err = $sent ? null : get_mail_error();
        @$logStmt = $db->prepare("INSERT INTO email_notifications (user_id, booking_id, recipient_email, type, subject, status, error_message, sent_at, created_at) VALUES (?, ?, ?, 'payment_update', ?, ?, ?, " . ($sent ? "NOW()" : "NULL") . ", NOW())");
        if ($logStmt) {
            $logStmt->bind_param('iissss', $uId, $booking_id, $recipientEmail, $subject, $st, $err);
            $logStmt->execute();
            $logStmt->close();
        }
    }
    return $sent;
}

function send_booking_status_email(int $booking_id, string $status, ?mysqli $conn = null): bool {
    $db = get_mailer_db_connection($conn);
    if (!$db || $booking_id <= 0) return false;

    $stmt = $db->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $booking_id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$b) return false;

    $customerName = trim($b['customer_name'] ?? $b['full_name'] ?? 'Valued Customer');
    $recipientEmail = strtolower(trim($b['customer_email'] ?? $b['email'] ?? ''));
    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $formattedStatus = ucwords(strtolower(trim($status)));
    $introMessage = "Your memorial reservation booking (#{$booking_id}) has been updated to: {$formattedStatus}.";
    if (strtolower($status) === 'approved') {
        $introMessage = "Great news! Your memorial reservation booking (#{$booking_id}) has been officially Approved and confirmed by administration.";
    } elseif (strtolower($status) === 'completed') {
        $introMessage = "Your memorial reservation booking (#{$booking_id}) has been marked as Completed.";
    }

    $htmlBody = render_branded_email([
        'title' => 'Reservation Status: ' . $formattedStatus,
        'badge' => $formattedStatus,
        'greeting_name' => $customerName,
        'intro_lines' => [
            $introMessage,
            'Here are your current reservation summary details:'
        ],
        'details_section_title' => 'Reservation Overview',
        'details_table' => [
            'Reservation Reference' => '#' . $booking_id,
            'Section' => strtoupper($b['section'] ?? 'A'),
            'Grave / Plot Location' => $b['plot'] ?? $b['grave_location'] ?? 'Assigned Plot',
            'Current Status' => $formattedStatus
        ],
        'action_button' => [
            'text' => 'View Reservation Status',
            'url' => get_email_asset_base_url() . '/login.php'
        ],
        'outro_lines' => [
            'Thank you for entrusting Garden of Remembrance Memorial Park with your memorial arrangements.'
        ]
    ]);

    $subject = "Booking #{$booking_id} Status Update: {$formattedStatus} - Garden of Remembrance";
    $sent = send_mail($recipientEmail, $subject, $htmlBody, true);
    if ($db) {
        $uId = (int)($b['user_id'] ?? 0);
        $st = $sent ? 'sent' : 'failed';
        $err = $sent ? null : get_mail_error();
        @$logStmt = $db->prepare("INSERT INTO email_notifications (user_id, booking_id, recipient_email, type, subject, status, error_message, sent_at, created_at) VALUES (?, ?, ?, 'booking_status', ?, ?, ?, " . ($sent ? "NOW()" : "NULL") . ", NOW())");
        if ($logStmt) {
            $logStmt->bind_param('iissss', $uId, $booking_id, $recipientEmail, $subject, $st, $err);
            $logStmt->execute();
            $logStmt->close();
        }
    }
    return $sent;
}

function send_guest_account_creation_email(int $booking_id, string $token, ?mysqli $conn = null): bool {
    $db = get_mailer_db_connection($conn);
    if (!$db || $booking_id <= 0 || empty($token)) {
        return false;
    }

    $stmt = $db->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $booking_id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$b) return false;

    $customerName = trim($b['customer_name'] ?? '');
    if (empty($customerName)) {
        $customerName = trim($b['full_name'] ?? 'Valued Guest');
    }

    $recipientEmail = strtolower(trim($b['customer_email'] ?? ''));
    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        $recipientEmail = strtolower(trim($b['email'] ?? ''));
    }

    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("Mailer notice: Guest reservation #$booking_id does not have a valid customer email.");
        return false;
    }

    $section = strtoupper(trim($b['section'] ?? 'A'));
    $plot = trim($b['plot'] ?? $b['grave_location'] ?? 'Assigned Plot');
    $plotFormatted = 'Plot ' . $section . '-' . $plot;

    $baseUrl = function_exists('get_app_base_url') ? get_app_base_url() : (function_exists('get_email_asset_base_url') ? get_email_asset_base_url() : 'http://localhost/Garden_of_Remembrance_MP');
    $regUrl = rtrim($baseUrl, '/\\') . '/complete_registration.php?token=' . urlencode($token);

    $detailsTable = [
        'Request #' => '#' . $booking_id,
        'Name' => $customerName,
        'Email' => $recipientEmail,
        'Contact' => $b['contact_number'] ?? '—',
        'Selected Plot' => $section . '-' . $plot,
        'Purpose' => $b['purpose'] ?? 'Reserve New Grave',
        'Status' => 'Approved'
    ];

    $htmlBody = render_branded_email([
        'title' => 'Reservation Approved',
        'badge' => 'Request #' . $booking_id,
        'greeting_name' => $customerName,
        'intro_lines' => [
            "Your reservation request for {$plotFormatted} has been approved by Garden of Remembrance Memorial Park.",
            "To continue your reservation, please complete your profile using the button below:"
        ],
        'details_section_title' => 'Reservation Request Summary',
        'details_table' => $detailsTable,
        'action_button' => [
            'text' => 'COMPLETE PROFILE',
            'url' => $regUrl
        ],
        'outro_lines' => [
            "Please click the 'COMPLETE PROFILE' button above to set up your password and complete your reservation.",
            "This link is secure, personal to you, and will remain valid for 48 hours.",
            "Thank you,<br>Garden of Remembrance Memorial Park"
        ],
        'cancel_action' => [
            'text' => 'Cancel Reservation Request',
            'url' => rtrim(get_email_asset_base_url(), '/\\') . '/cancel_reservation.php?booking_id=' . $booking_id . '&token=' . hash_hmac('sha256', 'cancel_' . $booking_id . '_' . $recipientEmail, 'GOR_CANCEL_SECRET_2026')
        ]
    ]);

    $subject = "Garden of Remembrance - Reservation Approved";
    $sent = send_mail($recipientEmail, $subject, $htmlBody, true);

    if ($db) {
        $uId = 0;
        $st = $sent ? 'sent' : 'failed';
        $err = $sent ? null : get_mail_error();
        @$logStmt = $db->prepare("INSERT INTO email_notifications (user_id, booking_id, recipient_email, type, subject, status, error_message, sent_at, created_at) VALUES (?, ?, ?, 'guest_account_creation', ?, ?, ?, " . ($sent ? "NOW()" : "NULL") . ", NOW())");
        if ($logStmt) {
            $logStmt->bind_param('iissss', $uId, $booking_id, $recipientEmail, $subject, $st, $err);
            $logStmt->execute();
            $logStmt->close();
        }

        $emailSentInt = $sent ? 1 : 0;
        $updGrr = $db->prepare("UPDATE guest_reservation_requests SET email_sent = ?, email_error = ? WHERE booking_id = ? OR account_creation_token_hash = ?");
        if ($updGrr) {
            $updGrr->bind_param('isis', $emailSentInt, $err, $booking_id, $token);
            $updGrr->execute();
            $updGrr->close();
        }
    }

    return $sent;
}

function send_guest_reservation_received_email(int $requestId, ?mysqli $conn = null): bool {
    $db = get_mailer_db_connection($conn);
    if (!$db || $requestId <= 0) {
        return false;
    }

    $stmt = $db->prepare("SELECT * FROM guest_reservation_requests WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) return false;

    $customerName = trim($req['full_name'] ?? 'Valued Guest');
    $recipientEmail = strtolower(trim($req['email'] ?? ''));

    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $section = strtoupper(trim($req['section'] ?? 'A'));
    $plot = trim($req['plot_code'] ?: ($req['plot_x'] . '-' . $req['plot_y']));
    $plotFormatted = 'Plot ' . $section . '-' . $plot;

    $detailsTable = [
        'Request Reference' => '#' . $requestId,
        'Full Name'         => $customerName,
        'Email Address'     => $recipientEmail,
        'Contact Number'    => $req['contact_number'] ?? '—',
        'Selected Plot'     => $section . '-' . $plot,
        'Purpose'           => $req['purpose'] ?? 'Future Memorial Lot',
        'Current Status'    => 'Pending Admin Review'
    ];

    $htmlBody = render_branded_email([
        'title' => 'Reservation Request Received',
        'badge' => 'Request #' . $requestId,
        'greeting_name' => $customerName,
        'intro_lines' => [
            "Thank you for contacting Garden of Remembrance Memorial Park.",
            "We have successfully received your reservation request for {$plotFormatted}. Our administration staff is currently reviewing your submission."
        ],
        'details_section_title' => 'Reservation Request Summary',
        'details_table' => $detailsTable,
        'outro_lines' => [
            "What happens next?",
            "Once our staff verifies and approves your request, you will receive a Complete Profile Link via email to set your password and access your reservation details.",
            "If you have any questions or require urgent assistance, please feel free to reach out to our office.",
            "Thank you,<br>Garden of Remembrance Memorial Park"
        ],
        'cancel_action' => [
            'text' => 'Cancel Reservation Request',
            'url' => rtrim(get_email_asset_base_url(), '/\\') . '/cancel_reservation.php?req_id=' . $requestId . '&token=' . hash_hmac('sha256', 'cancel_' . $requestId . '_' . $recipientEmail, 'GOR_CANCEL_SECRET_2026')
        ]
    ]);

    $subject = "Garden of Remembrance - Reservation Request Received";
    $sent = send_mail($recipientEmail, $subject, $htmlBody, true);

    if ($db) {
        $uId = 0;
        $bId = (int)($req['booking_id'] ?? 0);
        $st = $sent ? 'sent' : 'failed';
        $err = $sent ? null : get_mail_error();
        @$logStmt = $db->prepare("INSERT INTO email_notifications (user_id, booking_id, recipient_email, type, subject, status, error_message, sent_at, created_at) VALUES (?, ?, ?, 'guest_reservation_received', ?, ?, ?, " . ($sent ? "NOW()" : "NULL") . ", NOW())");
        if ($logStmt) {
            $logStmt->bind_param('iissss', $uId, $bId, $recipientEmail, $subject, $st, $err);
            $logStmt->execute();
            $logStmt->close();
        }
    }

    return $sent;
}

function send_guest_reservation_rejected_email(int $requestId, string $reason = '', ?mysqli $conn = null): bool {
    $db = get_mailer_db_connection($conn);
    if (!$db || $requestId <= 0) {
        return false;
    }

    $stmt = $db->prepare("SELECT * FROM guest_reservation_requests WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) return false;

    $customerName = trim($req['full_name'] ?? 'Valued Guest');
    $recipientEmail = strtolower(trim($req['email'] ?? ''));

    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $section = strtoupper(trim($req['section'] ?? 'A'));
    $plot = trim($req['plot_code'] ?: ($req['plot_x'] . '-' . $req['plot_y']));
    $plotFormatted = 'Plot ' . $section . '-' . $plot;

    $detailsTable = [
        'Request Reference' => '#' . $requestId,
        'Applicant'         => $customerName,
        'Requested Plot'    => $section . '-' . $plot,
        'Status'            => 'Not Approved / Rejected'
    ];
    if (!empty($reason)) {
        $detailsTable['Reason for Decision'] = htmlspecialchars($reason);
    }

    $htmlBody = render_branded_email([
        'title' => 'Reservation Request Status',
        'badge' => 'Request #' . $requestId,
        'greeting_name' => $customerName,
        'intro_lines' => [
            "We are writing to update you regarding your recent plot reservation request for {$plotFormatted} at Garden of Remembrance Memorial Park.",
            "After careful review, our administration is unable to approve this reservation request at this time."
        ],
        'details_section_title' => 'Request Details',
        'details_table' => $detailsTable,
        'outro_lines' => [
            "You are welcome to browse our interactive park map to explore other available plots or contact our office directly for personal assistance.",
            "Thank you,<br>Garden of Remembrance Memorial Park"
        ]
    ]);

    $subject = "Garden of Remembrance - Reservation Request Update";
    $sent = send_mail($recipientEmail, $subject, $htmlBody, true);

    if ($db) {
        $uId = 0;
        $bId = (int)($req['booking_id'] ?? 0);
        $st = $sent ? 'sent' : 'failed';
        $err = $sent ? null : get_mail_error();
        @$logStmt = $db->prepare("INSERT INTO email_notifications (user_id, booking_id, recipient_email, type, subject, status, error_message, sent_at, created_at) VALUES (?, ?, ?, 'guest_reservation_rejected', ?, ?, ?, " . ($sent ? "NOW()" : "NULL") . ", NOW())");
        if ($logStmt) {
            $logStmt->bind_param('iissss', $uId, $bId, $recipientEmail, $subject, $st, $err);
            $logStmt->execute();
            $logStmt->close();
        }
    }

    return $sent;
}

function send_reservation_cancellation_confirmed_email(int $requestId, string $reason = '', string $cancelledBy = '', ?mysqli $conn = null): bool {
    $db = get_mailer_db_connection($conn);
    if (!$db || $requestId <= 0) return false;

    $stmt = $db->prepare("SELECT * FROM guest_reservation_requests WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) return false;

    $customerName = trim($req['full_name'] ?? 'Valued Customer');
    $recipientEmail = strtolower(trim($req['email'] ?? ''));
    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) return false;

    $section = strtoupper(trim($req['section'] ?? 'A'));
    $plot = trim($req['plot_code'] ?: ($req['plot_x'] . '-' . $req['plot_y']));
    $plotFormatted = 'Plot ' . $section . '-' . $plot;

    $detailsTable = [
        'Request Reference' => '#' . $requestId,
        'Reservation Holder' => $customerName,
        'Cancelled Plot'     => $plotFormatted,
        'Cancellation Status'=> 'Officially Cancelled',
        'Cancelled By'       => !empty($cancelledBy) ? $cancelledBy : 'Administration',
        'Refund Policy'      => 'No Refund Issued (Non-Refundable Policy)'
    ];
    if (!empty($reason)) {
        $detailsTable['Reason for Cancellation'] = $reason;
    }

    $htmlBody = render_branded_email([
        'title' => 'Reservation Cancellation Confirmation',
        'badge' => 'Cancelled #' . $requestId,
        'greeting_name' => $customerName,
        'intro_lines' => [
            "This email confirms that your memorial lot reservation for {$plotFormatted} (Reference #{$requestId}) has been officially cancelled.",
            "The plot has been released and is no longer reserved under your name."
        ],
        'details_section_title' => 'Cancellation Summary',
        'details_table' => $detailsTable,
        'outro_lines' => [
            "Important Policy Notice:",
            "In accordance with Garden of Remembrance Memorial Park policy and terms of reservation, all downpayments, reservation fees, or installment amounts paid are strictly non-refundable.",
            "If you have questions regarding this cancellation or wish to reserve a different plot in the future, please contact our administrative office.",
            "Thank you,<br>Garden of Remembrance Memorial Park"
        ]
    ]);

    $subject = "Reservation Cancellation Confirmation - Garden of Remembrance";
    $sent = send_mail($recipientEmail, $subject, $htmlBody, true);

    if ($db) {
        $uId = 0;
        $bId = (int)($req['booking_id'] ?? 0);
        $st = $sent ? 'sent' : 'failed';
        $err = $sent ? null : get_mail_error();
        @$logStmt = $db->prepare("INSERT INTO email_notifications (user_id, booking_id, recipient_email, type, subject, status, error_message, sent_at, created_at) VALUES (?, ?, ?, 'reservation_cancelled', ?, ?, ?, " . ($sent ? "NOW()" : "NULL") . ", NOW())");
        if ($logStmt) {
            $logStmt->bind_param('iissss', $uId, $bId, $recipientEmail, $subject, $st, $err);
            $logStmt->execute();
            $logStmt->close();
        }
    }
    return $sent;
}

function send_mail($to, $subject, $body, $isHtml = false) {
    global $mail_config, $mail_error, $configPath;
    $mail_error = ''; 

    $smtp = $mail_config['smtp'] ?? [];
    $fromName = $mail_config['from_name'] ?? 'Garden of Remembrance Memorial Park';
    $fromEmail = trim($mail_config['from_email'] ?? '');
    $smtp['host'] = sanitize_smtp_host($smtp['host'] ?? '');
    $smtp['username'] = trim($smtp['username'] ?? '');
    $smtp['password'] = str_replace(' ', '', trim($smtp['password'] ?? ''));
    $smtp['secure'] = trim($smtp['secure'] ?? '');
    $smtp['port'] = intval($smtp['port'] ?? 0);

    if (empty($fromEmail) && !empty($smtp['username'])) {
        $fromEmail = $smtp['username'];
    }
    if (empty($fromEmail)) {
        $fromEmail = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    if (has_placeholder_smtp_password($smtp['password'])) {
        $mail_error = 'SMTP password placeholder detected. Replace PASTE_YOUR_GMAIL_APP_PASSWORD_HERE in config/mail_config.php or config/mail_credentials.php with your real Gmail App Password.';
        error_log('Mailer error: ' . $mail_error);
        return false;
    }

    if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        if (!extension_loaded('openssl')) {
            $mail_error = 'PHP OpenSSL extension is missing. Enable openssl in php.ini and restart your web server.';
            error_log('Mailer error: ' . $mail_error);
            return false;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $missing = [];
            if (empty($smtp['host'])) {
                $missing[] = 'SMTP host';
            }
            if (empty($smtp['username'])) {
                $missing[] = 'SMTP username';
            }
            if (empty($smtp['password'])) {
                $missing[] = 'SMTP password';
            }
            if (empty($smtp['port']) || $smtp['port'] <= 0) {
                $missing[] = 'SMTP port';
            }
            if (!in_array(strtolower($smtp['secure']), ['', 'tls', 'ssl'], true)) {
                $missing[] = 'SMTP secure must be tls, ssl, or empty';
            }
            if (!empty($missing)) {
                $mail_error = 'SMTP configuration missing or invalid: ' . implode(', ', $missing) . '. (loaded config: ' . basename($configPath) . ')';
                error_log('Mailer error: ' . $mail_error);
                return false;
            }

            $mail->isSMTP();
            $mail_debug_enabled = !empty($smtp['debug']);
            $mail->SMTPDebug = $mail_debug_enabled ? \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER : \PHPMailer\PHPMailer\SMTP::DEBUG_OFF;
            $mail_debug_output = '';
            $mail->Debugoutput = function($str, $level) use (&$mail_debug_output) {
                $mail_debug_output .= trim($str) . "\n";
            };
            $mail->SMTPAutoTLS = true;
            $mail->SMTPAuth = true;
            $mail->Timeout = 10;
            $mail->Timelimit = 15;
            $mail->Host = $smtp['host'];
            $mail->Username = $smtp['username'];
            $mail->Password = $smtp['password'];
            $mail->Port = $smtp['port'];
            if (strtolower($smtp['secure']) === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif (strtolower($smtp['secure']) === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            if (!empty($smtp['smtp_options']) && is_array($smtp['smtp_options'])) {
                $mail->SMTPOptions = $smtp['smtp_options'];
            }

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            if ($isHtml) {
                $mail->isHTML(true);

                $logoCandidates = [
                    __DIR__ . '/cmis_logo.png',
                    dirname(__DIR__) . '/cmis_logo.png',
                    __DIR__ . '/images/cmis_logo.png',
                    __DIR__ . '/assets/img/cmis_logo.png'
                ];
                $embeddedLogo = false;
                foreach ($logoCandidates as $cand) {
                    if (file_exists($cand)) {
                        try {
                            $mail->addEmbeddedImage($cand, 'cemetery_logo', 'cmis_logo.png', 'base64', 'image/png');
                            $embeddedLogo = true;
                            break;
                        } catch (Throwable $embedEx) {
                            error_log('Notice: Failed to embed cemetery logo: ' . $embedEx->getMessage());
                        }
                    }
                }
                if ($embeddedLogo) {

                    $body = preg_replace('/src=["\'][^"\']*cmis_logo\.png[^"\']*["\']/i', 'src="cid:cemetery_logo"', $body);
                }
                $mail->Body = $body;
                $mail->AltBody = strip_tags($body);
            } else {
                $mail->Body = $body;
            }
            $mail->send();
            return true;
        } catch (Throwable $e) {
            $mail_error = 'SMTP Error: ' . $e->getMessage();
            if (!empty($mail_debug_output)) {
                $mail_error .= ' | SMTP debug: ' . trim($mail_debug_output);
            }
            $mail_error .= ' (loaded config: ' . basename($configPath ?: 'none') . ')';
            error_log('Mailer error: ' . $mail_error);

        }
    }

    $headers = 'From: ' . $fromName . ' <' . $fromEmail . '>' . "\r\n";
    if ($isHtml) {
        $headers .= 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
    }
    return mail($to, $subject, $body, $headers);
}

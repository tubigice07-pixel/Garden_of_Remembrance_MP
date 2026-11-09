<?php

if (is_file(__DIR__ . '/../connection.php')) {
    require_once __DIR__ . '/../connection.php';
} elseif (is_file(__DIR__ . '/connection.php')) {
    require_once __DIR__ . '/connection.php';
}
if (is_file(__DIR__ . '/../mailer.php')) {
    require_once __DIR__ . '/../mailer.php';
} elseif (is_file(__DIR__ . '/mailer.php')) {
    require_once __DIR__ . '/mailer.php';
}
if (is_file(__DIR__ . '/../email_template.php')) {
    require_once __DIR__ . '/../email_template.php';
} elseif (is_file(__DIR__ . '/email_template.php')) {
    require_once __DIR__ . '/email_template.php';
}
if (is_file(__DIR__ . '/notification_schema.php')) {
    require_once __DIR__ . '/notification_schema.php';
} elseif (is_file(__DIR__ . '/includes/notification_schema.php')) {
    require_once __DIR__ . '/includes/notification_schema.php';
}

function create_system_notification(mysqli $conn, int $userId, ?int $bookingId, string $type, string $title, string $message, ?string $link = null): int {
    if ($userId <= 0) {
        return 0;
    }

    if (!ensure_notifications_table($conn)) {
        return 0;
    }

    $sql = "INSERT INTO notifications (user_id, booking_id, type, title, message, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Failed to prepare notification insert: " . $conn->error);
        return 0;
    }

    $stmt->bind_param('iissss', $userId, $bookingId, $type, $title, $message, $link);
    $success = $stmt->execute();
    $insertId = $success ? (int)$conn->insert_id : 0;
    $stmt->close();

    return $insertId;
}

function log_email_attempt(mysqli $conn, ?int $userId, ?int $bookingId, string $recipientEmail, string $type, string $subject, string $status, ?string $errorMessage = null): bool {
    if (!ensure_email_notifications_table($conn)) {
        error_log('Email notification log table unavailable: ' . $conn->error);
        return false;
    }
    $sql = "INSERT INTO email_notifications (user_id, booking_id, recipient_email, type, subject, status, error_message, sent_at, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, " . ($status === 'sent' ? "NOW()" : "NULL") . ", NOW())";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Failed to prepare email log: " . $conn->error);
        return false;
    }

    $stmt->bind_param('iisssss', $userId, $bookingId, $recipientEmail, $type, $subject, $status, $errorMessage);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

function get_user_contact_info(mysqli $conn, int $userId = 0, ?int $bookingId = null): array {
    $info = [
        'user_id' => $userId,
        'name'    => 'Valued Customer',
        'email'   => ''
    ];

    $isBookingAdmin = false;
    $bookingData = null;

    if (!empty($bookingId) && $bookingId > 0) {
        $bStmt = $conn->prepare("SELECT user_id, customer_name, full_name, customer_email, email, source, is_guest_booking FROM tbl_bookings WHERE id = ? LIMIT 1");
        if ($bStmt) {
            $bStmt->bind_param('i', $bookingId);
            $bStmt->execute();
            $bookingData = $bStmt->get_result()->fetch_assoc();
            $bStmt->close();
        }
    }

    if ($userId > 0) {
        $uStmt = $conn->prepare("SELECT user_id, first_name, last_name, email, role FROM tbl_user WHERE user_id = ? LIMIT 1");
        if ($uStmt) {
            $uStmt->bind_param('i', $userId);
            $uStmt->execute();
            if ($uRow = $uStmt->get_result()->fetch_assoc()) {
                if (strtoupper(trim((string)($uRow['role'] ?? ''))) === 'ADMIN') {
                    $isBookingAdmin = true;
                } else {
                    $info['user_id'] = (int)$uRow['user_id'];
                    $fullName = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
                    if (!empty($fullName)) {
                        $info['name'] = $fullName;
                    }
                    $info['email'] = strtolower(trim((string)($uRow['email'] ?? '')));
                }
            }
            $uStmt->close();
        }
    }

    // If the booking was placed by admin on behalf of a customer, or guest booking, or client email is empty:
    // ALWAYS prioritize the actual customer's name and Gmail from tbl_bookings!
    if ($bookingData) {
        $bName = trim(($bookingData['customer_name'] ?? '') ?: ($bookingData['full_name'] ?? ''));
        $bEmail = strtolower(trim(($bookingData['customer_email'] ?? '') ?: ($bookingData['email'] ?? '')));

        if ($isBookingAdmin || empty($info['email']) || ($bookingData['source'] ?? '') === 'admin' || !empty($bookingData['is_guest_booking'])) {
            if (!empty($bName)) {
                $info['name'] = $bName;
            }
            if (!empty($bEmail)) {
                $info['email'] = $bEmail;
            }
        }
    }

    return $info;
}

function send_system_notification(
    mysqli $conn,
    int $userId,
    ?int $bookingId,
    string $type,
    string $title,
    string $message,
    ?string $link = null,
    bool $sendEmail = true,
    ?string $emailSubject = null,
    ?string $emailHtml = null
): array {
    $result = [
        'notif_id'    => 0,
        'email_sent'  => false,
        'email_error' => ''
    ];

    // Customer-facing in-app notifications must NEVER be sent to administrators!
    // When an admin books on behalf of a customer, the admin is NOT the user.
    // Rejections and customer updates belong ONLY in the customer's Gmail, not the admin's notification bell.
    $isAdminUser = false;
    if ($userId > 0) {
        $chkRole = $conn->prepare("SELECT role FROM tbl_user WHERE user_id = ? LIMIT 1");
        if ($chkRole) {
            $chkRole->bind_param('i', $userId);
            $chkRole->execute();
            $rRow = $chkRole->get_result()->fetch_assoc();
            $chkRole->close();
            if ($rRow && strtoupper(trim((string)($rRow['role'] ?? ''))) === 'ADMIN') {
                $isAdminUser = true;
            }
        }
    }

    $isGuestOrAdminBooking = false;
    if (!empty($bookingId) && $bookingId > 0) {
        $bCheck = $conn->query("SELECT source, is_guest_booking FROM tbl_bookings WHERE id = " . (int)$bookingId . " LIMIT 1");
        if ($bCheck && ($bRow = $bCheck->fetch_assoc())) {
            if (($bRow['source'] ?? '') === 'admin' || !empty($bRow['is_guest_booking'])) {
                $isGuestOrAdminBooking = true;
            }
        }
    }

    if ($userId > 0 && !$isAdminUser && !$isGuestOrAdminBooking) {
        $result['notif_id'] = create_system_notification($conn, $userId, $bookingId, $type, $title, $message, $link);
    }

    if ($sendEmail) {
        $contact = get_user_contact_info($conn, $userId, $bookingId);
        $toEmail = $contact['email'];

        if (!empty($toEmail) && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $subject = $emailSubject ?: ($title . ' - Garden of Remembrance');
            $body = $emailHtml;

            if (empty($body) && function_exists('render_branded_email') && function_exists('get_email_asset_base_url')) {
                $body = render_branded_email([
                    'title'         => $title,
                    'badge'         => ucwords(str_replace('_', ' ', $type)),
                    'greeting_name' => $contact['name'],
                    'intro_lines'   => [
                        $message
                    ],
                    'action_button' => [
                        'text' => 'Access Your Account',
                        'url'  => get_email_asset_base_url() . ($link ?: '/user_dashboard.php')
                    ],
                    'outro_lines'   => [
                        'Thank you for entrusting Garden of Remembrance Memorial Park with your memorial arrangements.',
                        'If you have any questions or require assistance, our administration staff is always available to help.'
                    ]
                ]);
            }

            if (!function_exists('send_mail')) {
                $result['email_error'] = 'Email delivery is not configured; in-system notification was saved.';
                log_email_attempt($conn, $userId, $bookingId, $toEmail, $type, $subject, 'failed', $result['email_error']);
                return $result;
            }

            try {
                $sent = send_mail($toEmail, $subject, $body, true);
                if ($sent) {
                    $result['email_sent'] = true;
                    log_email_attempt($conn, $userId, $bookingId, $toEmail, $type, $subject, 'sent');
                } else {
                    $err = get_mail_error() ?: 'Unknown PHPMailer error';
                    $result['email_error'] = $err;
                    log_email_attempt($conn, $userId, $bookingId, $toEmail, $type, $subject, 'failed', $err);
                }
            } catch (Throwable $e) {
                $result['email_error'] = $e->getMessage();
                error_log("PHPMailer exception for user #$userId: " . $e->getMessage());
                log_email_attempt($conn, $userId, $bookingId, $toEmail, $type, $subject, 'failed', $e->getMessage());
            }
        } else {
            $result['email_error'] = 'No valid recipient email address found.';
            log_email_attempt($conn, $userId, $bookingId, $toEmail ?: 'none', $type, $title, 'failed', 'No valid recipient email address');
        }
    }

    return $result;
}

function notify_booking_submitted(mysqli $conn, int $bookingId): array {
    $stmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if (!$stmt) return ['success' => false];
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$b) return ['success' => false];

    $userId = (int)($b['user_id'] ?? 0);
    $customerName = trim(($b['customer_name'] ?? '') ?: ($b['full_name'] ?? 'Valued Customer'));
    $section = strtoupper(trim($b['section'] ?? 'A'));
    $plotDisplay = trim($b['plot'] ?? $b['grave_location'] ?? "Section $section");
    $plotType = ucwords(trim($b['plot_type'] ?? 'Regular'));
    $purpose = ucwords(str_replace('_', ' ', trim($b['purpose'] ?? $b['service_type'] ?? 'Reservation')));
    $totalAmount = (float)($b['total_amount'] ?? 0);
    $paymentPlan = strtolower(trim($b['payment_plan'] ?? 'full'));
    $paymentTerm = (int)($b['payment_term'] ?? 0);
    $planDisplay = ($paymentPlan === 'installment' && $paymentTerm > 0) ? "{$paymentTerm}-Month Installment" : "Full Payment";
    $monthlyFee = ($paymentPlan === 'installment' && $paymentTerm > 0) ? round($totalAmount / $paymentTerm, 2) : 0;

    $title = "Booking Submitted (Ref #$bookingId)";
    $message = "Your memorial reservation booking #$bookingId for Section $section ($plotDisplay) has been submitted successfully and is pending administrator review.";
    $link = "user_dashboard.php";

    $detailsTable = [
        'Booking Reference' => '#' . $bookingId,
        'Section'           => 'Section ' . $section,
        'Plot Location'     => $plotDisplay,
        'Lawn Type'         => $plotType,
        'Purpose / Service' => $purpose,
        'Total Amount'      => '₱' . number_format($totalAmount, 2),
        'Payment Option'    => $planDisplay,
    ];
    if ($paymentPlan === 'installment' && $monthlyFee > 0) {
        $detailsTable['Installment Duration'] = $paymentTerm . ' Months';
        $detailsTable['Monthly Payment'] = '₱' . number_format($monthlyFee, 2);
    }
    if (!empty($b['deceased_name'])) {
        $detailsTable['Deceased Person'] = trim($b['deceased_name']);
    }
    $detailsTable['Booking Status'] = 'Pending Review';

    $subject = "Booking #{$bookingId} Submitted - Garden of Remembrance";
    $html = render_branded_email([
        'title'                 => 'Booking Submitted Successfully',
        'badge'                 => 'Booking #' . $bookingId,
        'greeting_name'         => $customerName,
        'intro_lines'           => [
            "Thank you for submitting your memorial reservation with Garden of Remembrance Memorial Park.",
            "Your booking application (Reference #{$bookingId}) has been safely recorded in our system. Our administration staff will review the requirements promptly."
        ],
        'details_section_title' => 'Reservation Summary',
        'details_table'         => $detailsTable,
        'action_button'         => [
            'text' => 'View Booking Details in Dashboard',
            'url'  => get_email_asset_base_url() . '/user_dashboard.php'
        ],
        'outro_lines'           => [
            "Please keep your Booking Reference #{$bookingId} for follow-ups and payment inquiries.",
            "You will receive automatic notifications as your reservation is confirmed."
        ]
    ]);

    // Also notify administrators in real-time
    try {
        if (function_exists('notify_administrators')) {
            $rawPurpose = strtolower(trim((string)($b['purpose'] ?? '')));
            $rawServiceType = trim((string)($b['service_type'] ?? ''));
            $isService = ($rawPurpose === 'cemetery_service' || !empty($rawServiceType));
            if ($isService) {
                $srvName = !empty($rawServiceType) ? $rawServiceType : 'Cemetery Care Service';
                $adminTitle = "New Service Booking: " . $srvName;
                $adminMsg = "Customer {$customerName} booked {$srvName} for Section {$section} ({$plotDisplay}). Ref #{$bookingId}. Total: ₱" . number_format($totalAmount, 2);
                $adminLink = "logs.php?search=" . urlencode((string)$bookingId);
                $adminType = "cemetery_service";
            } else {
                $adminTitle = "New Booking Application (Ref #{$bookingId})";
                $adminMsg = "Customer {$customerName} submitted a booking for Section {$section} ({$plotDisplay}). Lawn: {$plotType}. Total: ₱" . number_format($totalAmount, 2);
                $adminLink = "dashboard.php#bookings";
                $adminType = "booking";
            }
            notify_administrators($conn, $adminTitle, $adminMsg, $adminLink, $bookingId, $adminType);
        }
    } catch (Throwable $eAdmin) {
        error_log("Failed to notify administrators for booking #$bookingId: " . $eAdmin->getMessage());
    }

    return send_system_notification($conn, $userId, $bookingId, 'booking_submitted', $title, $message, $link, true, $subject, $html);
}

function notify_booking_approved(mysqli $conn, int $bookingId): array {
    $stmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if (!$stmt) return ['success' => false];
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$b) return ['success' => false];

    $userId = (int)($b['user_id'] ?? 0);
    $customerName = trim(($b['customer_name'] ?? '') ?: ($b['full_name'] ?? 'Valued Customer'));
    $section = strtoupper(trim($b['section'] ?? 'A'));
    $plotDisplay = trim($b['plot'] ?? $b['grave_location'] ?? "Section $section");
    $totalAmount = (float)($b['total_amount'] ?? 0);
    $paymentPlan = strtolower(trim($b['payment_plan'] ?? 'full'));
    $paymentTerm = (int)($b['payment_term'] ?? 0);
    $planDisplay = ($paymentPlan === 'installment' && $paymentTerm > 0) ? "{$paymentTerm}-Month Installment" : "Full Payment";

    $title = "Booking Approved (Ref #$bookingId)";
    $message = "Great news! Your booking #$bookingId for Section $section ($plotDisplay) has been officially approved by cemetery administration.";
    $link = "user_dashboard.php";

    $detailsTable = [
        'Booking Reference' => '#' . $bookingId,
        'Section'           => 'Section ' . $section,
        'Plot Location'     => $plotDisplay,
        'Payment Option'    => $planDisplay,
        'Total Amount'      => '₱' . number_format($totalAmount, 2),
        'Booking Status'    => 'Approved / Confirmed'
    ];
    if (!empty($b['preferred_date'])) {
        $detailsTable['Scheduled Date'] = date('F j, Y', strtotime($b['preferred_date']));
    }

    $subject = "Booking #{$bookingId} Approved - Garden of Remembrance";
    $html = render_branded_email([
        'title'                 => 'Booking Officially Approved',
        'badge'                 => 'Booking #' . $bookingId,
        'greeting_name'         => $customerName,
        'intro_lines'           => [
            "We are pleased to inform you that your memorial reservation (Reference #{$bookingId}) has been approved and confirmed by Garden of Remembrance Memorial Park.",
            "The plot location and service details are confirmed as follows:"
        ],
        'details_section_title' => 'Confirmed Details',
        'details_table'         => $detailsTable,
        'action_button'         => [
            'text' => 'View Your Dashboard',
            'url'  => get_email_asset_base_url() . '/user_dashboard.php'
        ],
        'outro_lines'           => [
            "You may access your account anytime to view receipts, installment schedules, and cemetery map locations.",
            "Our staff is at your service for any questions or arrangements."
        ]
    ]);

    return send_system_notification($conn, $userId, $bookingId, 'booking_approved', $title, $message, $link, true, $subject, $html);
}

function notify_booking_rejected(mysqli $conn, int $bookingId, string $reason = ''): array {
    $stmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if (!$stmt) return ['success' => false];
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$b) return ['success' => false];

    $userId = (int)($b['user_id'] ?? 0);
    $customerName = trim(($b['customer_name'] ?? '') ?: ($b['full_name'] ?? 'Valued Customer'));
    $reasonText = !empty($reason) ? $reason : 'Requirement verification incomplete or conflicting schedule.';

    $title = "Booking Update: Rejected (Ref #$bookingId)";
    $message = "Your booking #$bookingId could not be approved at this time. Reason: $reasonText. Please contact administration for assistance.";
    $link = "user_dashboard.php";

    $detailsTable = [
        'Booking Reference' => '#' . $bookingId,
        'Section'           => strtoupper($b['section'] ?? 'A'),
        'Booking Status'    => 'Rejected',
        'Remarks / Reason'  => $reasonText
    ];

    $subject = "Booking #{$bookingId} Status: Rejected - Garden of Remembrance";
    $html = render_branded_email([
        'title'                 => 'Booking Status Update',
        'badge'                 => 'Booking #' . $bookingId,
        'greeting_name'         => $customerName,
        'intro_lines'           => [
            "We are writing to update you regarding your booking application (Reference #{$bookingId}).",
            "Unfortunately, your booking request could not be approved at this time for the reason noted below:"
        ],
        'details_section_title' => 'Application Review Remarks',
        'details_table'         => $detailsTable,
        'outro_lines'           => [
            "If you have additional verification documents or wish to select an alternative plot or schedule, please contact our office or submit a new reservation."
        ]
    ]);

    return send_system_notification($conn, $userId, $bookingId, 'booking_rejected', $title, $message, $link, true, $subject, $html);
}

function notify_booking_cancelled(mysqli $conn, int $bookingId, string $reason = ''): array {
    $stmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if (!$stmt) return ['success' => false];
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$b) return ['success' => false];

    $userId = (int)($b['user_id'] ?? 0);
    $customerName = trim(($b['customer_name'] ?? '') ?: ($b['full_name'] ?? 'Valued Customer'));
    $reasonText = !empty($reason) ? $reason : 'Cancelled per user or administrator request.';

    $title = "Booking Cancelled (Ref #$bookingId)";
    $message = "Booking #$bookingId has been cancelled. If this was an error, please contact administration.";
    $link = "user_dashboard.php";

    $detailsTable = [
        'Booking Reference' => '#' . $bookingId,
        'Section'           => strtoupper($b['section'] ?? 'A'),
        'Booking Status'    => 'Cancelled',
        'Remarks'           => $reasonText
    ];

    $subject = "Booking #{$bookingId} Cancelled - Garden of Remembrance";
    $html = render_branded_email([
        'title'                 => 'Booking Cancelled',
        'badge'                 => 'Booking #' . $bookingId,
        'greeting_name'         => $customerName,
        'intro_lines'           => [
            "Your booking (Reference #{$bookingId}) has been cancelled.",
            "The reservation for this plot has been released."
        ],
        'details_section_title' => 'Cancellation Details',
        'details_table'         => $detailsTable,
        'action_button'         => [
            'text' => 'View Your Account',
            'url'  => get_email_asset_base_url() . '/user_dashboard.php'
        ]
    ]);

    return send_system_notification($conn, $userId, $bookingId, 'booking_cancelled', $title, $message, $link, true, $subject, $html);
}

function notify_interment_scheduled(mysqli $conn, int $bookingId, string $scheduledDate, string $serviceType = 'Interment / Burial', string $notes = ''): array {
    $stmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if (!$stmt) return ['success' => false];
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$b) return ['success' => false];

    $userId = (int)($b['user_id'] ?? 0);
    $customerName = trim(($b['customer_name'] ?? '') ?: ($b['full_name'] ?? 'Valued Customer'));
    $section = strtoupper(trim($b['section'] ?? 'A'));
    $plotDisplay = trim($b['plot'] ?? $b['grave_location'] ?? "Section $section");
    $formattedDate = date('F j, Y', strtotime($scheduledDate));

    $title = "Service Schedule: $serviceType (Ref #$bookingId)";
    $message = "The schedule for your $serviceType at Section $section ($plotDisplay) is set for $formattedDate.";
    $link = "user_dashboard.php";

    $detailsTable = [
        'Booking Reference' => '#' . $bookingId,
        'Section'           => 'Section ' . $section,
        'Plot Location'     => $plotDisplay,
        'Service'           => $serviceType,
        'Scheduled Date'    => $formattedDate,
    ];
    if (!empty($b['deceased_name'])) {
        $detailsTable['Deceased Person'] = trim($b['deceased_name']);
    }
    if (!empty($notes)) {
        $detailsTable['Notes / Guidelines'] = $notes;
    }

    $subject = "Service Schedule Confirmed: {$formattedDate} - Booking #{$bookingId}";
    $html = render_branded_email([
        'title'                 => 'Memorial Service Schedule',
        'badge'                 => 'Service Schedule',
        'greeting_name'         => $customerName,
        'intro_lines'           => [
            "We have recorded the official schedule for your memorial service at Garden of Remembrance Memorial Park.",
            "Please review the schedule details below:"
        ],
        'details_section_title' => 'Schedule Information',
        'details_table'         => $detailsTable,
        'action_button'         => [
            'text' => 'View in Dashboard',
            'url'  => get_email_asset_base_url() . '/user_dashboard.php'
        ],
        'outro_lines'           => [
            "Please coordinate with cemetery administration at least 24 hours prior to the scheduled date for gate passes and setup requirements."
        ]
    ]);

    return send_system_notification($conn, $userId, $bookingId, 'interment_scheduled', $title, $message, $link, true, $subject, $html);
}

function notify_payment_submitted(mysqli $conn, int $bookingId, float $amount, string $paymentMethod, string $refNo = ''): array {
    $stmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if (!$stmt) return ['success' => false];
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$b) return ['success' => false];

    $userId = (int)($b['user_id'] ?? 0);
    $customerName = trim(($b['customer_name'] ?? '') ?: ($b['full_name'] ?? 'Valued Customer'));

    $title = "Payment Proof Submitted (₱" . number_format($amount, 2) . ")";
    $message = "Your payment proof of ₱" . number_format($amount, 2) . " ($paymentMethod" . ($refNo ? ", Ref: $refNo" : "") . ") for Booking #$bookingId has been submitted for administrator review.";
    $link = "user_dashboard.php";

    $detailsTable = [
        'Booking Reference' => '#' . $bookingId,
        'Amount Submitted'  => '₱' . number_format($amount, 2),
        'Payment Method'    => $paymentMethod,
        'Payment Status'    => 'Pending Verification'
    ];
    if (!empty($refNo)) {
        $detailsTable['Reference Number'] = $refNo;
    }

    $subject = "Payment Proof Submitted for Booking #{$bookingId} - Garden of Remembrance";
    $html = render_branded_email([
        'title'                 => 'Payment Proof Submitted',
        'badge'                 => 'Payment Under Review',
        'greeting_name'         => $customerName,
        'intro_lines'           => [
            "We have received your payment proof for Booking #{$bookingId}.",
            "Our finance team will verify the transaction and credit it to your account balance."
        ],
        'details_section_title' => 'Payment Details',
        'details_table'         => $detailsTable,
        'action_button'         => [
            'text' => 'View Payment Status',
            'url'  => get_email_asset_base_url() . '/user_dashboard.php'
        ]
    ]);

    return send_system_notification($conn, $userId, $bookingId, 'payment_submitted', $title, $message, $link, true, $subject, $html);
}

function notify_payment_approved(mysqli $conn, int $bookingId, float $amount, string $refNo = '', string $method = 'Cash / GCash'): array {
    $stmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if (!$stmt) return ['success' => false];
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$b) return ['success' => false];

    $userId = (int)($b['user_id'] ?? 0);
    $customerName = trim(($b['customer_name'] ?? '') ?: ($b['full_name'] ?? 'Valued Customer'));
    $totalAmount = (float)($b['total_amount'] ?? 0);
    $paymentPlan = strtolower(trim($b['payment_plan'] ?? 'full'));
    $paymentTerm = (int)($b['payment_term'] ?? 0);

    $confirmedPaid = 0.00;
    $rcptStmt = $conn->prepare("SELECT SUM(amount_paid) AS total_paid, COUNT(*) AS paid_count FROM payment_receipts WHERE booking_id = ? AND UPPER(payment_status) = 'PAID'");
    if ($rcptStmt) {
        $rcptStmt->bind_param('i', $bookingId);
        $rcptStmt->execute();
        $rRow = $rcptStmt->get_result()->fetch_assoc();
        if ($rRow) {
            $confirmedPaid = (float)($rRow['total_paid'] ?? 0.00);
        }
        $rcptStmt->close();
    }
    if ($confirmedPaid <= 0 && $amount > 0) {
        $confirmedPaid = $amount;
    }

    $remainingBalance = max(0.00, $totalAmount - $confirmedPaid);

    $nextDueText = 'None (Fully Paid)';
    $nextAmountText = '₱0.00';
    if ($paymentPlan === 'installment' && $remainingBalance > 0.01) {
        $nextStmt = $conn->prepare("SELECT due_date, amount_due FROM booking_payment_schedule WHERE booking_id = ? AND LOWER(status) = 'pending' ORDER BY installment_number ASC LIMIT 1");
        if ($nextStmt) {
            $nextStmt->bind_param('i', $bookingId);
            $nextStmt->execute();
            if ($nRow = $nextStmt->get_result()->fetch_assoc()) {
                $nextDueText = date('F j, Y', strtotime($nRow['due_date']));
                $nextAmountText = '₱' . number_format((float)$nRow['amount_due'], 2);
            }
            $nextStmt->close();
        }
    }

    $title = "Payment Received: ₱" . number_format($amount, 2) . " (Ref #$bookingId)";
    $message = "Your payment of ₱" . number_format($amount, 2) . " for Booking #$bookingId has been confirmed. Remaining balance: ₱" . number_format($remainingBalance, 2) . ".";
    $link = "user_dashboard.php";

    $paymentTable = [
        'Booking Reference' => '#' . $bookingId,
        'Amount Recorded'   => '₱' . number_format($amount, 2),
        'Payment Method'    => $method,
        'Payment Status'    => 'PAID & CONFIRMED',
        'Payment Date'      => date('F j, Y g:i A'),
        'Total Contract'    => '₱' . number_format($totalAmount, 2),
        'Total Confirmed'   => '₱' . number_format($confirmedPaid, 2),
        'Remaining Balance' => '₱' . number_format($remainingBalance, 2),
    ];
    if (!empty($refNo)) {
        $paymentTable['Reference Number'] = $refNo;
    }
    if ($paymentPlan === 'installment' && $remainingBalance > 0.01) {
        $paymentTable['Next Due Date'] = $nextDueText;
        $paymentTable['Next Amount Due'] = $nextAmountText;
    }

    $subject = "Official Payment Confirmation: ₱" . number_format($amount, 2) . " - Booking #{$bookingId}";
    $html = render_branded_email([
        'title'                 => 'Official Payment Receipt',
        'badge'                 => 'Payment Confirmed',
        'greeting_name'         => $customerName,
        'intro_lines'           => [
            "We have successfully recorded your payment of ₱" . number_format($amount, 2) . " for Booking Reference #{$bookingId}.",
            "Here is your official payment confirmation and updated account balance:"
        ],
        'details_section_title' => 'Payment & Account Balance Summary',
        'details_table'         => $paymentTable,
        'action_button'         => [
            'text' => 'View Full Statement & Receipts',
            'url'  => get_email_asset_base_url() . '/user_dashboard.php'
        ],
        'outro_lines'           => [
            "Please retain this email as your official confirmation.",
            "Thank you for keeping your account updated with Garden of Remembrance Memorial Park."
        ]
    ]);

    return send_system_notification($conn, $userId, $bookingId, 'payment_received', $title, $message, $link, true, $subject, $html);
}

function notify_announcement(mysqli $conn, string $title, string $message, string $recipientGroup = 'all', ?int $specificUserId = null, bool $isPromotion = false): array {
    $stats = [
        'total_target'   => 0,
        'in_system_sent' => 0,
        'emails_sent'    => 0,
        'emails_failed'  => 0
    ];

    $type = $isPromotion ? 'promotion' : 'announcement';
    $badge = $isPromotion ? 'Exclusive Promotion' : 'Important Announcement';

    $targetUsers = [];
    if ($specificUserId && $specificUserId > 0) {
        $stmt = @$conn->prepare("SELECT user_id, first_name, last_name, email FROM tbl_user WHERE user_id = ? AND UPPER(TRIM(COALESCE(role, 'USER'))) <> 'ADMIN' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $specificUserId);
            $stmt->execute();
            if ($u = $stmt->get_result()->fetch_assoc()) {
                $targetUsers[] = $u;
            }
            $stmt->close();
        }
    } elseif ($recipientGroup === 'active_bookings') {
        $sql = "SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email 
                FROM tbl_bookings b 
                JOIN tbl_user u ON u.user_id = b.user_id 
                WHERE b.status IN ('pending', 'approved')";
        $res = @$conn->query($sql);
        if ($res) {
            while ($u = $res->fetch_assoc()) $targetUsers[] = $u;
        }
    } elseif ($recipientGroup === 'active_installments') {
        $sql = "SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email 
                FROM payment_schedules ps 
                JOIN tbl_user u ON u.user_id = ps.user_id 
                WHERE ps.status IN ('pending', 'overdue') AND ps.remaining_balance > 0";
        $res = @$conn->query($sql);
        if ($res) {
            while ($u = $res->fetch_assoc()) $targetUsers[] = $u;
        }
    } else {

        $sql = "SELECT user_id, first_name, last_name, email FROM tbl_user WHERE UPPER(TRIM(COALESCE(role, 'USER'))) <> 'ADMIN'";
        $res = @$conn->query($sql);
        if ($res) {
            while ($u = $res->fetch_assoc()) $targetUsers[] = $u;
        }
    }

    $stats['total_target'] = count($targetUsers);
    if ($stats['total_target'] === 0) {
        return $stats;
    }

    ensure_notifications_table($conn);
    $insStmt = @$conn->prepare("INSERT INTO notifications (user_id, booking_id, type, title, message, link, is_read, created_at) VALUES (?, NULL, ?, ?, ?, 'user_dashboard.php', 0, NOW())");

    foreach ($targetUsers as $u) {
        $uid = (int)$u['user_id'];
        if ($uid <= 0) continue;

        if ($insStmt) {
            $insStmt->bind_param('isss', $uid, $type, $title, $message);
            if ($insStmt->execute()) {
                $stats['in_system_sent']++;
            }
        } else {
            $notifId = create_system_notification($conn, $uid, null, $type, $title, $message, 'user_dashboard.php');
            if ($notifId > 0) {
                $stats['in_system_sent']++;
            }
        }
    }
    if ($insStmt) {
        $insStmt->close();
    }

    $startTime = microtime(true);
    $maxMailSeconds = 8.0; 

    foreach ($targetUsers as $u) {

        if ((microtime(true) - $startTime) > $maxMailSeconds) {
            $stats['emails_failed'] += ($stats['total_target'] - $stats['emails_sent'] - $stats['emails_failed']);
            break;
        }

        $uid = (int)$u['user_id'];
        $uName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'Valued Customer';
        $uEmail = strtolower(trim($u['email'] ?? ''));

        if (!empty($uEmail) && filter_var($uEmail, FILTER_VALIDATE_EMAIL)) {
            $subject = "$title - Garden of Remembrance";
            $html = render_branded_email([
                'title'         => $title,
                'badge'         => $badge,
                'greeting_name' => $uName,
                'intro_lines'   => [
                    $message
                ],
                'action_button' => [
                    'text' => 'Visit Garden of Remembrance',
                    'url'  => get_email_asset_base_url() . '/user_dashboard.php'
                ],
                'outro_lines'   => [
                    'Garden of Remembrance Memorial Park • Cemetery Management and Grave Mapping System'
                ]
            ]);

            try {
                if (function_exists('send_mail')) {
                    $sent = send_mail($uEmail, $subject, $html, true);
                    if ($sent) {
                        $stats['emails_sent']++;
                        log_email_attempt($conn, $uid, null, $uEmail, $type, $subject, 'sent');
                    } else {
                        $stats['emails_failed']++;
                        log_email_attempt($conn, $uid, null, $uEmail, $type, $subject, 'failed', get_mail_error() ?: 'Mail server unreachable');
                    }
                } else {
                    $stats['emails_failed']++;
                }
            } catch (Throwable $e) {
                $stats['emails_failed']++;
                log_email_attempt($conn, $uid, null, $uEmail, $type, $subject, 'failed', $e->getMessage());
            }
        }
    }

    return $stats;
}

function notify_plot_claim_rejected(mysqli $conn, int $userId, string $section, int $px, int $py, string $reason = '', bool $wasDeleted = false): array {
    if ($userId <= 0) return ['success' => false];

    $stmt = $conn->prepare("SELECT first_name, last_name, email FROM tbl_user WHERE user_id = ? LIMIT 1");
    if (!$stmt) return ['success' => false];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$u) return ['success' => false];

    $customerName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'Valued Customer';
    $sec = strtoupper(trim($section));
    $plotDisplay = "Section {$sec} — Plot {$px}-{$py}";
    $reasonText = !empty($reason) ? $reason : 'Unverified ownership records or details could not be confirmed.';

    $title = "Plot Claim Update: Rejected ({$plotDisplay})";
    $message = "Your ownership claim for {$plotDisplay} was rejected by the administrator. Reason: {$reasonText}." . ($wasDeleted ? " The unverified claim has been deleted from your account." : " You may review or remove this record from your dashboard.");
    $link = "user_dashboard.php#myPlotsSection";

    $detailsTable = [
        'Target Plot'         => $plotDisplay,
        'Claim Status'        => 'Rejected',
        'Administrator Notes' => $reasonText,
        'Action Taken'        => $wasDeleted ? 'Record removed from system' : 'Marked as rejected'
    ];

    $subject = "Plot Ownership Claim Status: Rejected ({$plotDisplay}) - Garden of Remembrance";
    $html = render_branded_email([
        'title'                 => 'Plot Claim Status Update',
        'badge'                 => $plotDisplay,
        'greeting_name'         => $customerName,
        'intro_lines'           => [
            "We are writing to update you regarding your plot ownership claim for {$plotDisplay}.",
            "After review by our cemetery management staff, this claim could not be verified and has been rejected:"
        ],
        'details_section_title' => 'Verification Review Remarks',
        'details_table'         => $detailsTable,
        'action_button'         => [
            'text' => 'Access Your Dashboard',
            'url'  => get_email_asset_base_url() . '/user_dashboard.php'
        ],
        'outro_lines'           => [
            "If you have title documents, deed of sale, or burial records proving your ownership, please contact our office or submit a new claim with verified supporting details."
        ]
    ]);

    return send_system_notification($conn, $userId, null, 'plot_claim_rejected', $title, $message, $link, true, $subject, $html);
}

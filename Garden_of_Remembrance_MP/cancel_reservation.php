<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Asia/Manila');
}

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/mailer.php';

if (function_exists('ensure_guest_reservation_columns')) {
    ensure_guest_reservation_columns($conn);
}

define('GOR_CANCEL_SECRET', 'GOR_CANCEL_SECRET_2026');

$reqId = (int)($_GET['req_id'] ?? ($_POST['req_id'] ?? 0));
$bookingId = (int)($_GET['booking_id'] ?? ($_POST['booking_id'] ?? 0));
$token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));

$requestData = null;
$bookingData = null;
$valid = false;
$errorMessage = '';
$successMessage = '';

if ($reqId > 0) {
    $stmt = $conn->prepare("SELECT grr.*, 
            b.plot_price, b.total_amount, b.amount_paid AS b_amount_paid, b.gcash_reference_number AS b_gcash_ref,
            b.payment_status AS b_payment_status, b.status AS booking_status
        FROM guest_reservation_requests grr
        LEFT JOIN tbl_bookings b ON b.id = grr.booking_id
        WHERE grr.id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $reqId);
        $stmt->execute();
        $requestData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($requestData) {
        $guestEmail = strtolower(trim($requestData['email'] ?? ''));
        $expectedToken = hash_hmac('sha256', 'cancel_' . $requestData['id'] . '_' . $guestEmail, GOR_CANCEL_SECRET);
        if (hash_equals($expectedToken, $token)) {
            $valid = true;
        } else {
            $errorMessage = 'Security validation failed. Invalid cancellation link or token.';
        }
    } else {
        $errorMessage = 'Reservation request not found or invalid reference.';
    }
} elseif ($bookingId > 0) {
    $stmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $bookingData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($bookingData) {
        $bEmail = strtolower(trim($bookingData['customer_email'] ?: ($bookingData['email'] ?: '')));
        $expectedToken = hash_hmac('sha256', 'cancel_' . $bookingData['id'] . '_' . $bEmail, GOR_CANCEL_SECRET);
        if (hash_equals($expectedToken, $token)) {
            $valid = true;
            // Also attempt to find linked guest_reservation_requests record if any
            $grrChk = $conn->prepare("SELECT * FROM guest_reservation_requests WHERE booking_id = ? LIMIT 1");
            if ($grrChk) {
                $grrChk->bind_param('i', $bookingId);
                $grrChk->execute();
                $requestData = $grrChk->get_result()->fetch_assoc();
                $grrChk->close();
            }
        } else {
            $errorMessage = 'Security validation failed. Invalid cancellation link or token.';
        }
    } else {
        $errorMessage = 'Booking reference not found.';
    }
} else {
    $errorMessage = 'Missing reservation or booking parameter.';
}

// Consolidate display details
$displayName = '';
$displayEmail = '';
$displayPhone = '';
$displayPlot = '';
$displayStatus = '';
$displayDownpayment = '₱0.00';
$isCancelled = false;
$cancellationRequested = false;
$cancelledBy = '';
$cancellationReason = '';
$cancelledAt = '';

if ($valid) {
    if ($requestData) {
        $displayName = trim($requestData['full_name'] ?? 'Valued Customer');
        $displayEmail = trim($requestData['email'] ?? '');
        $displayPhone = trim($requestData['contact_number'] ?? '');
        $sec = strtoupper(trim($requestData['section'] ?? ''));
        $plot = trim($requestData['plot_code'] ?: ($requestData['plot_x'] . '-' . $requestData['plot_y']));
        $displayPlot = "Section {$sec} Plot {$plot}";
        $displayStatus = trim($requestData['status'] ?? 'Pending');
        $isCancelled = (strtolower($displayStatus) === 'cancelled');
        $cancellationRequested = ((int)($requestData['cancellation_requested'] ?? 0) === 1);
        $cancelledBy = trim($requestData['cancelled_by'] ?? '');
        $cancellationReason = trim($requestData['cancellation_reason'] ?? '');
        $cancelledAt = trim($requestData['cancelled_at'] ?? '');

        $payAmt = floatval($requestData['payment_amount'] ?? 0);
        if ($payAmt <= 0 && !empty($requestData['b_amount_paid'])) {
            $payAmt = floatval($requestData['b_amount_paid']);
        }
        if ($payAmt <= 0) {
            $payAmt = 17000.00; // standard 20% downpayment
        }
        $displayDownpayment = '₱' . number_format($payAmt, 2);
    } elseif ($bookingData) {
        $displayName = trim($bookingData['customer_name'] ?: ($bookingData['full_name'] ?: 'Valued Customer'));
        $displayEmail = trim($bookingData['customer_email'] ?: ($bookingData['email'] ?: ''));
        $displayPhone = trim($bookingData['contact_number'] ?? '');
        $sec = strtoupper(trim($bookingData['section'] ?? ''));
        $plot = trim($bookingData['plot'] ?: ($bookingData['grave_location'] ?: ''));
        $displayPlot = $plot ?: "Section {$sec}";
        $displayStatus = trim($bookingData['status'] ?? 'pending');
        $isCancelled = (strtolower($displayStatus) === 'cancelled');
        $cancellationReason = trim($bookingData['cancellation_reason'] ?? '');
        $cancelledBy = trim($bookingData['cancelled_by'] ?? '');
        $cancelledAt = trim($bookingData['cancelled_at'] ?? '');

        $payAmt = floatval($bookingData['amount_paid'] ?? 0);
        if ($payAmt <= 0) {
            $payAmt = floatval($bookingData['downpayment'] ?? 0);
        }
        $displayDownpayment = '₱' . number_format($payAmt > 0 ? $payAmt : 17000.00, 2);
    }
}

// Handle Form Submission
if ($valid && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_cancellation') {
    $reason = trim((string)($_POST['cancellation_reason'] ?? ''));
    $ackNoRefund = isset($_POST['ack_no_refund']);

    if (empty($reason)) {
        $errorMessage = 'Please provide your reason for requesting cancellation.';
    } elseif (mb_strlen($reason) < 5) {
        $errorMessage = 'Please provide a more detailed reason for cancellation (at least 5 characters).';
    } elseif (!$ackNoRefund) {
        $errorMessage = 'You must acknowledge and agree to the No-Refund policy to proceed.';
    } elseif ($isCancelled) {
        $errorMessage = 'This reservation has already been cancelled.';
    } else {
        $now = date('Y-m-d H:i:s');
        $applicantName = $displayName . ' (Guest)';

        if ($requestData && !empty($requestData['id'])) {
            $rId = (int)$requestData['id'];
            $upGrr = $conn->prepare("UPDATE guest_reservation_requests 
                SET cancellation_requested = 1, 
                    cancellation_requested_at = NOW(), 
                    cancellation_reason = ?, 
                    cancelled_by = ? 
                WHERE id = ?");
            if ($upGrr) {
                $upGrr->bind_param('ssi', $reason, $applicantName, $rId);
                $upGrr->execute();
                $upGrr->close();
            }

            if (!empty($requestData['booking_id'])) {
                $bId = (int)$requestData['booking_id'];
                $upBk = $conn->prepare("UPDATE tbl_bookings SET cancellation_reason = ? WHERE id = ?");
                if ($upBk) {
                    $upBk->bind_param('si', $reason, $bId);
                    $upBk->execute();
                    $upBk->close();
                }
            }

            $logText = "Guest {$displayName} ({$displayEmail}) submitted a cancellation request for Reservation #GR-{$rId} ({$displayPlot}). Reason: {$reason}. Strictly no-refund policy acknowledged.";
            $lStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('GUEST_RESERVATION_CANCEL_REQUESTED', ?, 0, NOW())");
            if ($lStmt) {
                $lStmt->bind_param('s', $logText);
                $lStmt->execute();
                $lStmt->close();
            }

            // Notify Admin
            @$conn->query("CREATE TABLE IF NOT EXISTS `notifications` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `booking_id` int(11) DEFAULT NULL,
                `type` varchar(50) NOT NULL DEFAULT 'system',
                `title` varchar(255) NOT NULL,
                `message` text NOT NULL,
                `link` varchar(255) DEFAULT NULL,
                `is_read` tinyint(1) NOT NULL DEFAULT 0,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $admQ = $conn->query("SELECT user_id FROM tbl_user WHERE UPPER(TRIM(COALESCE(role, ''))) = 'ADMIN'");
            if ($admQ) {
                $nTitle = "Cancellation Request Submitted";
                $nMsg = "Guest {$displayName} requested cancellation for {$displayPlot} (Ref #GR-{$rId}). Reason: {$reason}.";
                $nLink = "guest_reservations.php?status=all&search=" . urlencode($displayName);
                while ($adm = $admQ->fetch_assoc()) {
                    $aUid = (int)$adm['user_id'];
                    $nIns = $conn->prepare("INSERT INTO notifications (user_id, booking_id, type, title, message, link, is_read, created_at) VALUES (?, ?, 'cancellation_request', ?, ?, ?, 0, NOW())");
                    if ($nIns) {
                        $bkIdParam = !empty($requestData['booking_id']) ? (int)$requestData['booking_id'] : null;
                        $nIns->bind_param('iisss', $aUid, $bkIdParam, $nTitle, $nMsg, $nLink);
                        $nIns->execute();
                        $nIns->close();
                    }
                }
            }

            $cancellationRequested = true;
            $cancellationReason = $reason;
            $cancelledBy = $applicantName;
            $successMessage = 'Your cancellation request has been submitted successfully. Our administration team has been notified and will process your request. As noted, payments are non-refundable.';
        } elseif ($bookingData && !empty($bookingData['id'])) {
            $bId = (int)$bookingData['id'];
            $upBk = $conn->prepare("UPDATE tbl_bookings SET cancellation_reason = ?, cancelled_by = ? WHERE id = ?");
            if ($upBk) {
                $upBk->bind_param('ssi', $reason, $applicantName, $bId);
                $upBk->execute();
                $upBk->close();
            }

            $logText = "Customer {$displayName} ({$displayEmail}) submitted a cancellation request for Booking #{$bId} ({$displayPlot}). Reason: {$reason}. Strictly no-refund policy acknowledged.";
            $lStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('GUEST_RESERVATION_CANCEL_REQUESTED', ?, 0, NOW())");
            if ($lStmt) {
                $lStmt->bind_param('s', $logText);
                $lStmt->execute();
                $lStmt->close();
            }

            $cancellationRequested = true;
            $cancellationReason = $reason;
            $cancelledBy = $applicantName;
            $successMessage = 'Your cancellation request has been submitted successfully. Our administration team will review and finalize the cancellation. Reminder: payments are non-refundable.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reservation Cancellation — Garden of Remembrance</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <style>
    :root {
      --gor-primary: #7b1113;
      --gor-primary-dark: #580c0d;
      --gor-accent: #c5a059;
    }
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      background: linear-gradient(135deg, #fdfbf7 0%, #f4eee3 100%);
      color: #1e293b;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .header-bar {
      background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%);
      color: white;
      padding: 1.25rem 0;
      box-shadow: 0 4px 15px rgba(123, 17, 19, 0.15);
    }
    .cancel-card {
      background: #ffffff;
      border: 1px solid #e7dfd5;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06);
      overflow: hidden;
    }
    .warning-box {
      background: #fff5f5;
      border-left: 4px solid #e53e3e;
      border-radius: 8px;
      padding: 1rem 1.25rem;
    }
    .info-tile {
      background: #faf8f5;
      border: 1px solid #ede5da;
      border-radius: 10px;
      padding: 0.85rem 1rem;
    }
    .btn-cancel-submit {
      background: #e53e3e;
      border-color: #e53e3e;
      color: white;
      font-weight: 600;
      padding: 0.75rem 1.5rem;
      border-radius: 10px;
      transition: all 0.2s;
    }
    .btn-cancel-submit:hover {
      background: #c53030;
      border-color: #c53030;
      color: white;
    }
  </style>
</head>
<body>

  <header class="header-bar mb-4">
    <div class="container d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-3">
        <div style="background: rgba(255,255,255,0.15); width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
          <i class="fas fa-monument text-white fs-5"></i>
        </div>
        <div>
          <h1 class="h5 m-0 fw-bold text-white tracking-wide">Garden of Remembrance</h1>
          <div style="font-size: 0.78rem; color: rgba(255,255,255,0.85);">Memorial Park & Cemetery Management System</div>
        </div>
      </div>
      <a href="index.php" class="btn btn-sm btn-outline-light d-none d-sm-inline-flex align-items-center gap-1" style="border-radius: 8px;">
        <i class="fas fa-home"></i> Home
      </a>
    </div>
  </header>

  <main class="container mb-5 flex-grow-1">
    <div class="row justify-content-center">
      <div class="col-lg-8 col-md-10">

        <?php if (!empty($errorMessage)): ?>
          <div class="alert alert-danger shadow-sm border-0 d-flex align-items-center gap-2 mb-4" style="border-radius: 12px;">
            <i class="fas fa-exclamation-circle fs-5"></i>
            <div><?php echo htmlspecialchars($errorMessage); ?></div>
          </div>
        <?php endif; ?>

        <?php if (!empty($successMessage)): ?>
          <div class="alert alert-success shadow-sm border-0 d-flex align-items-center gap-2 mb-4" style="border-radius: 12px; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0 !important;">
            <i class="fas fa-check-circle fs-5"></i>
            <div><?php echo htmlspecialchars($successMessage); ?></div>
          </div>
        <?php endif; ?>

        <?php if ($valid): ?>
          <div class="cancel-card">
            <div class="p-4 border-bottom" style="background: linear-gradient(to right, #fdfbf7, #ffffff);">
              <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                  <span class="badge" style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; font-weight: 600; padding: 6px 12px; border-radius: 20px; font-size: 0.78rem;">
                    <i class="fas fa-calendar-times me-1"></i> Reservation Cancellation Portal
                  </span>
                  <h2 class="h4 fw-bold mt-2 mb-1" style="color: #7b1113;">
                    Request Reservation Cancellation
                  </h2>
                  <p class="text-muted small mb-0">Review your reservation details and submit a formal cancellation request</p>
                </div>
                <div>
                  <span class="badge font-monospace" style="background: #e2e8f0; color: #334155; font-size: 0.85rem; padding: 6px 12px; border-radius: 8px;">
                    Ref #<?php echo $reqId > 0 ? "GR-{$reqId}" : "BK-{$bookingId}"; ?>
                  </span>
                </div>
              </div>
            </div>

            <div class="p-4">
              <!-- Summary Grid -->
              <h3 class="h6 fw-bold text-uppercase text-muted small mb-3" style="letter-spacing: 0.5px;">Reservation Summary</h3>
              <div class="row g-3 mb-4">
                <div class="col-sm-6">
                  <div class="info-tile">
                    <div class="text-muted small" style="font-size: 0.72rem;">RESERVATION HOLDER</div>
                    <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($displayName); ?></div>
                    <div class="text-muted small mt-1 font-monospace" style="font-size: 0.76rem;"><?php echo htmlspecialchars($displayEmail); ?></div>
                  </div>
                </div>
                <div class="col-sm-6">
                  <div class="info-tile">
                    <div class="text-muted small" style="font-size: 0.72rem;">SELECTED GRAVE / PLOT</div>
                    <div class="fw-bold text-dark fs-6 font-monospace" style="color: #7b1113 !important;">
                      <i class="fas fa-map-pin me-1"></i><?php echo htmlspecialchars($displayPlot); ?>
                    </div>
                    <div class="text-muted small mt-1" style="font-size: 0.76rem;">Garden of Remembrance Memorial Park</div>
                  </div>
                </div>
                <div class="col-sm-6">
                  <div class="info-tile">
                    <div class="text-muted small" style="font-size: 0.72rem;">DOWNPAYMENT STATUS</div>
                    <div class="fw-bold text-success fs-6">
                      <i class="fas fa-check-circle me-1"></i><?php echo $displayDownpayment; ?> Paid
                    </div>
                    <div class="text-muted small mt-1" style="font-size: 0.76rem;">Settled via GCash Gateway</div>
                  </div>
                </div>
                <div class="col-sm-6">
                  <div class="info-tile">
                    <div class="text-muted small" style="font-size: 0.72rem;">CURRENT STATUS</div>
                    <div class="fw-bold text-dark fs-6">
                      <?php if ($isCancelled): ?>
                        <span class="badge bg-secondary">Cancelled</span>
                      <?php elseif ($cancellationRequested): ?>
                        <span class="badge bg-warning text-dark">Cancellation Under Review</span>
                      <?php else: ?>
                        <span class="badge" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a;">Active Reservation</span>
                      <?php endif; ?>
                    </div>
                    <?php if ($isCancelled && !empty($cancelledBy)): ?>
                      <div class="text-muted small mt-1" style="font-size: 0.72rem;">Cancelled by: <?php echo htmlspecialchars($cancelledBy); ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <?php if ($isCancelled): ?>
                <!-- Already Cancelled View -->
                <div class="alert alert-secondary p-4 rounded-3 border">
                  <div class="d-flex align-items-center gap-3 mb-2">
                    <i class="fas fa-ban text-danger fs-3"></i>
                    <div>
                      <h4 class="h5 fw-bold m-0 text-dark">This Reservation Has Been Officially Cancelled</h4>
                      <div class="text-muted small mt-1">Cancelled by: <strong><?php echo htmlspecialchars($cancelledBy ?: 'Administration'); ?></strong> <?php echo !empty($cancelledAt) ? 'on ' . date('F j, Y h:i A', strtotime($cancelledAt)) : ''; ?></div>
                    </div>
                  </div>
                  <?php if (!empty($cancellationReason)): ?>
                    <div class="mt-3 p-3 bg-white rounded border small">
                      <strong class="text-muted text-uppercase" style="font-size: 0.7rem;">Recorded Reason:</strong>
                      <div class="text-dark mt-1 fst-italic">"<?php echo htmlspecialchars($cancellationReason); ?>"</div>
                    </div>
                  <?php endif; ?>
                  <div class="mt-3 text-danger small">
                    <i class="fas fa-info-circle me-1"></i> As per cemetery reservation terms, no refund was issued for this cancelled plot.
                  </div>
                </div>

              <?php elseif ($cancellationRequested): ?>
                <!-- Cancellation Pending Review View -->
                <div class="alert alert-warning p-4 rounded-3 border border-warning" style="background: #fffbeb;">
                  <div class="d-flex align-items-center gap-3 mb-2">
                    <i class="fas fa-hourglass-half text-warning fs-3"></i>
                    <div>
                      <h4 class="h5 fw-bold m-0 text-dark">Cancellation Request Pending Review</h4>
                      <div class="text-muted small mt-1">Submitted by: <strong><?php echo htmlspecialchars($cancelledBy ?: $displayName); ?></strong></div>
                    </div>
                  </div>
                  <p class="small text-muted mb-2">
                    Your request to cancel this reservation has been logged and is awaiting final review and plot release by the cemetery administration.
                  </p>
                  <?php if (!empty($cancellationReason)): ?>
                    <div class="p-3 bg-white rounded border small mb-2">
                      <strong class="text-muted text-uppercase" style="font-size: 0.7rem;">Your Reason for Cancellation:</strong>
                      <div class="text-dark mt-1 fst-italic">"<?php echo htmlspecialchars($cancellationReason); ?>"</div>
                    </div>
                  <?php endif; ?>
                  <div class="text-danger small fw-semibold">
                    <i class="fas fa-exclamation-triangle me-1"></i> Reminder: Strictly NO REFUND will be issued upon processing of this cancellation.
                  </div>
                </div>

              <?php else: ?>
                <!-- Interactive Cancellation Form -->
                <div class="warning-box mb-4">
                  <div class="d-flex align-items-start gap-3">
                    <i class="fas fa-exclamation-triangle text-danger fs-4 mt-1"></i>
                    <div>
                      <h4 class="h6 fw-bold text-danger mb-1 text-uppercase">Critical Notice: Strictly No Refund Policy</h4>
                      <p class="small text-danger mb-0" style="color: #9b2c2c !important; line-height: 1.5;">
                        Please be fully advised that once you submit a cancellation request, any reservation fee, downpayment, or installment paid (including via GCash) is <strong>strictly non-refundable</strong> in accordance with Garden of Remembrance terms and conditions. Once accepted by the admin, the selected lot will be released and made available for other clients.
                      </p>
                    </div>
                  </div>
                </div>

                <form method="POST" action="" onsubmit="return validateCancelForm(event)">
                  <input type="hidden" name="action" value="request_cancellation">
                  <input type="hidden" name="req_id" value="<?php echo $reqId; ?>">
                  <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">
                  <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                  <div class="mb-3">
                    <label for="cancellation_reason" class="form-label fw-bold text-dark small text-uppercase">
                      State Your Reason for Cancellation <span class="text-danger">*</span>
                    </label>
                    <textarea 
                      class="form-control" 
                      id="cancellation_reason" 
                      name="cancellation_reason" 
                      rows="4" 
                      required 
                      placeholder="Please explain why you need to cancel this reservation (e.g., changed family burial plans, relocating to another province, acquired a lot in another cemetery, etc.)..."
                      style="border-radius: 10px; font-size: 0.9rem;"
                    ><?php echo htmlspecialchars($_POST['cancellation_reason'] ?? ''); ?></textarea>
                    <div class="form-text small text-muted">This explanation will be recorded in the cemetery reservation logs for administrative tracking.</div>
                  </div>

                  <div class="mb-4">
                    <div class="form-check p-3 rounded border" style="background: #fffaf0; border-color: #feebc8 !important;">
                      <input class="form-check-input" type="checkbox" id="ack_no_refund" name="ack_no_refund" required>
                      <label class="form-check-label small text-dark fw-semibold" for="ack_no_refund">
                        I understand and confirm that I am requesting to cancel this memorial reservation, and I explicitly acknowledge and agree that <u>NO REFUND</u> will be provided for any payments made.
                      </label>
                    </div>
                  </div>

                  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-2 border-top">
                    <a href="map.php" class="btn btn-light px-3 py-2 fw-semibold" style="border-radius: 8px;">
                      <i class="fas fa-arrow-left me-1"></i> Keep Reservation / Return to Map
                    </a>
                    <button type="submit" class="btn btn-cancel-submit" id="submitCancelBtn">
                      <i class="fas fa-ban me-1"></i> Confirm & Submit Cancellation Request
                    </button>
                  </div>
                </form>

              <?php endif; ?>

            </div>
          </div>
        <?php else: ?>
          <div class="card p-5 text-center shadow-sm border-0" style="border-radius: 16px;">
            <div class="mb-3">
              <i class="fas fa-link-slash text-danger" style="font-size: 3rem;"></i>
            </div>
            <h2 class="h5 fw-bold text-dark mb-2">Invalid or Expired Cancellation Link</h2>
            <p class="text-muted small mb-4">
              We could not verify the security token for this reservation cancellation request. Please ensure you clicked the full link sent to your registered email address.
            </p>
            <div>
              <a href="index.php" class="btn btn-primary px-4 py-2" style="background: #7b1113; border-color: #7b1113; border-radius: 8px;">
                Return to Homepage
              </a>
            </div>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </main>

  <footer class="mt-auto py-3 bg-white border-top text-center text-muted small">
    <div class="container">
      &copy; <?php echo date('Y'); ?> Garden of Remembrance Memorial Park. All rights reserved.
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    function validateCancelForm(e) {
      var reason = document.getElementById('cancellation_reason') ? document.getElementById('cancellation_reason').value.trim() : '';
      var ack = document.getElementById('ack_no_refund') ? document.getElementById('ack_no_refund').checked : false;

      if (!reason || reason.length < 5) {
        e.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'Reason Required',
          text: 'Please provide a clear reason for your cancellation request (minimum 5 characters).',
          confirmButtonColor: '#7b1113'
        });
        return false;
      }

      if (!ack) {
        e.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'Acknowledgment Required',
          text: 'You must acknowledge the strictly NO REFUND policy before proceeding.',
          confirmButtonColor: '#7b1113'
        });
        return false;
      }

      e.preventDefault();
      Swal.fire({
        title: 'Confirm Cancellation Request?',
        html: '<div class="text-start small"><p>Are you sure you want to request cancellation for this reservation?</p><p class="text-danger fw-bold mb-0">Reminder: Any payments made are strictly non-refundable.</p></div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Submit Request',
        cancelButtonText: 'No, Keep Reservation',
        confirmButtonColor: '#e53e3e',
        cancelButtonColor: '#6c757d'
      }).then(function(result) {
        if (result.isConfirmed) {
          var btn = document.getElementById('submitCancelBtn');
          if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';
          }
          e.target.submit();
        }
      });
      return false;
    }
  </script>
</body>
</html>

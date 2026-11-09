<?php
include_once __DIR__ . '/connection.php';
require_once __DIR__ . '/config/payment_settings.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$gcash_payment = get_gcash_payment_settings();

$payment_id = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : (int)($_SESSION['booking_id'] ?? ($_SESSION['gcash_tx_data']['booking_id'] ?? 0));
$reference = isset($_GET['ref']) ? trim($_GET['ref']) : ($_SESSION['gcash_tx_ref'] ?? ($_SESSION['gcash_tx_data']['reference'] ?? ('GC' . date('Ymd') . rand(100000, 999999))));

$booking_info = null;
$receipt_info = null;
if (isset($conn) && $conn instanceof mysqli) {
    if ($payment_id > 0) {
        $rStmt = $conn->prepare("SELECT * FROM payment_receipts WHERE id = ? LIMIT 1");
        if ($rStmt) {
            $rStmt->bind_param('i', $payment_id);
            $rStmt->execute();
            $rRes = $rStmt->get_result();
            $receipt_info = $rRes ? $rRes->fetch_assoc() : null;
            $rStmt->close();
            if ($receipt_info && $booking_id <= 0) {
                $booking_id = (int)$receipt_info['booking_id'];
            }
        }
    }

    if ($booking_id > 0) {
        $bStmt = $conn->prepare("SELECT b.*, CONCAT_WS(' ', u.first_name, u.last_name) AS customer_fullname, u.email AS user_email, u.phone_no AS user_phone 
                                 FROM tbl_bookings b 
                                 LEFT JOIN tbl_user u ON u.user_id = b.user_id 
                                 WHERE b.id = ? LIMIT 1");
        if ($bStmt) {
            $bStmt->bind_param('i', $booking_id);
            $bStmt->execute();
            $bRes = $bStmt->get_result();
            $booking_info = $bRes ? $bRes->fetch_assoc() : null;
            $bStmt->close();
        }

        if (!$receipt_info) {
            $rStmt = $conn->prepare("SELECT * FROM payment_receipts WHERE booking_id = ? ORDER BY CASE WHEN (installment_number IS NULL OR installment_number = 0) THEN 0 ELSE 1 END ASC, id ASC LIMIT 1");
            if ($rStmt) {
                $rStmt->bind_param('i', $booking_id);
                $rStmt->execute();
                $rRes = $rStmt->get_result();
                $receipt_info = $rRes ? $rRes->fetch_assoc() : null;
                $rStmt->close();
            }
        }
    }
}

$tx = $_SESSION['gcash_tx_data'] ?? [];
$total_amount = floatval($booking_info['total_amount'] ?? ($booking_info['plot_price'] ?? 0));
$amount_paid = 0.00;
if ($receipt_info && floatval($receipt_info['amount_paid']) > 0) {
    $amount_paid = floatval($receipt_info['amount_paid']);
} elseif ($booking_info && floatval($booking_info['amount_paid']) > 0) {
    $amount_paid = floatval($booking_info['amount_paid']);
} elseif (!empty($tx['amount'])) {
    $amount_paid = floatval(str_replace(',', '', $tx['amount']));
}

$receipt_status = strtoupper(trim((string)($receipt_info['payment_status'] ?? '')));
$booking_pay_status = strtoupper(trim((string)($booking_info['payment_status'] ?? '')));
$booking_status = strtolower(trim((string)($booking_info['status'] ?? '')));

$isVerifiedPaid = ($receipt_status === 'PAID' || ($booking_pay_status === 'PAID' && $booking_status === 'approved'));
$isRejected = ($receipt_status === 'REJECTED' || $booking_pay_status === 'REJECTED' || $booking_status === 'rejected');
$hasProof = (!empty($receipt_info['proof_filename']) || !empty($booking_info['payment_proof']));
$isForVerification = (!$isVerifiedPaid && !$isRejected && ($receipt_status === 'FOR VERIFICATION' || $booking_pay_status === 'FOR VERIFICATION' || $hasProof));

$isFullyPaid = ($isVerifiedPaid && $total_amount > 0 && $amount_paid >= ($total_amount - 0.009));
$isPartial = ($isVerifiedPaid && $amount_paid > 0 && !$isFullyPaid);
$remaining_balance = max(0.00, $total_amount - $amount_paid);

if ($isFullyPaid) {
    $receiptTitle = 'Payment Completed';
    $statusPillText = 'Status: PAID (Verified)';
    $statusPillStyle = 'background: #e6f7ec; color: #16a34a; border: 1px solid #bbf7d0;';
    $statusIcon = 'fas fa-check-circle';
    $circleIcon = 'fas fa-check';
    $circleStyle = 'background: #e6f7ec; color: #16a34a;';
} elseif ($isPartial) {
    $receiptTitle = 'Downpayment / Partial Payment';
    $statusPillText = 'Status: PARTIAL (Verified)';
    $statusPillStyle = 'background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe;';
    $statusIcon = 'fas fa-hourglass-half';
    $circleIcon = 'fas fa-coins';
    $circleStyle = 'background: #eff6ff; color: #2563eb;';
} elseif ($isForVerification) {
    $receiptTitle = 'Payment Proof Submitted';
    $statusPillText = 'Status: FOR VERIFICATION';
    $statusPillStyle = 'background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe;';
    $statusIcon = 'fas fa-hourglass-half';
    $circleIcon = 'fas fa-file-invoice';
    $circleStyle = 'background: #eff6ff; color: #2563eb;';
} elseif ($isRejected) {
    $receiptTitle = 'Payment Proof Rejected';
    $statusPillText = 'Status: REJECTED';
    $statusPillStyle = 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;';
    $statusIcon = 'fas fa-times-circle';
    $circleIcon = 'fas fa-times';
    $circleStyle = 'background: #fef2f2; color: #dc2626;';
} else {
    $receiptTitle = 'Payment Initiated';
    $statusPillText = 'Status: PENDING PROOF';
    $statusPillStyle = 'background: #fffbeb; color: #d97706; border: 1px solid #fde68a;';
    $statusIcon = 'fas fa-clock';
    $circleIcon = 'fas fa-exclamation';
    $circleStyle = 'background: #fffbeb; color: #d97706;';
}

$payment_date = $receipt_info['payment_date'] ?? ($booking_info['payment_proof_uploaded_at'] ?? ($tx['datetime'] ?? date('Y-m-d H:i:s')));
$official_receipt_number = $receipt_info['receipt_number'] ?? ('RCPT-' . date('Ymd', strtotime($payment_date)) . '-' . str_pad($booking_id, 4, '0', STR_PAD_LEFT));
$gcash_ref_number = !empty($receipt_info['gcash_reference_number']) ? $receipt_info['gcash_reference_number'] : (!empty($booking_info['gcash_reference_number']) ? $booking_info['gcash_reference_number'] : ($booking_info['reference_number'] ?? ($tx['reference'] ?? $reference)));
$customer_name = $booking_info['customer_fullname'] ?? ($booking_info['full_name'] ?? ($booking_info['customer_name'] ?? 'Customer'));
$isUserAdmin = (isset($_SESSION['role']) && strtoupper(trim((string)$_SESSION['role'])) === 'ADMIN');
$from = isset($_GET['from']) ? strtolower(trim((string)$_GET['from'])) : '';
if (empty($from) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = strtolower((string)$_SERVER['HTTP_REFERER']);
    if (strpos($ref, 'logs.php') !== false) {
        if (strpos($ref, 'tab=booking') !== false || strpos($ref, '#booking') !== false) {
            $from = 'booking_logs';
        } elseif (strpos($ref, 'tab=service') !== false || strpos($ref, '#service') !== false) {
            $from = 'service_logs';
        } elseif (strpos($ref, 'tab=reservation') !== false || strpos($ref, '#reservation') !== false) {
            $from = 'reservation_logs';
        } else {
            $from = 'booking_logs';
        }
    }
}

$source = isset($_GET['source']) ? strtolower(trim((string)$_GET['source'])) : ($_SESSION['payment_source'] ?? ($isUserAdmin ? 'admin' : 'user'));
if ($isUserAdmin) {
    $source = 'admin';
}
if ($source !== 'admin' && $source !== 'user' && $source !== 'guest') {
    $source = 'user';
}
// Guests have no dashboard: send them back to the map where their booking lives.
if ($source === 'guest' || (!isset($_SESSION['user_id']) && !$isUserAdmin)) {
    $source = 'guest';
}

if ($from === 'booking_logs') {
    $dashboard_url = 'logs.php?tab=booking';
    $dashboard_label = 'Back to Request Logs';
} elseif ($from === 'service_logs') {
    $dashboard_url = 'logs.php?tab=service';
    $dashboard_label = 'Back to Service Logs';
} elseif ($from === 'reservation_logs') {
    $dashboard_url = 'logs.php?tab=reservation';
    $dashboard_label = 'Back to Reservation Logs';
} else {
    $dashboard_url = ($source === 'admin') ? 'dashboard.php' : (($source === 'guest') ? 'map.php' : 'user_dashboard.php');
    $dashboard_label = ($source === 'guest') ? 'Back to Map' : 'Return to Dashboard';
    // Guest return target: deep-link to their own plot so Done reopens its map popup.
    $guest_return_url = 'map.php';
    if ($source === 'guest' && $booking_info) {
        $gSec = strtoupper(trim((string)($booking_info['section'] ?? '')));
        $gPlotRaw = trim((string)($booking_info['plot'] ?? ''));
        $gPx = 0; $gPy = 0;
        if (preg_match('/(\d+)\s*[-_,\s]\s*(\d+)/', $gPlotRaw, $gm)) {
            $gPx = (int)$gm[1]; $gPy = (int)$gm[2];
        }
        if ($gSec !== '' && $gPx > 0 && $gPy > 0) {
            $guest_return_url = 'map.php?section=' . urlencode($gSec) . '&plot_x=' . $gPx . '&plot_y=' . $gPy;
        }
        $dashboard_url = $guest_return_url;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Payment Receipt — Garden of Remembrance</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <style>
    :root {
      --brand: #7b1113;
      --brand-dark: #5a0c0e;
      --primary-blue: #005ce6;
      --card-bg: #ffffff;
      --bg-page: #f4f6f9;
      --text-dark: #1e293b;
      --text-muted: #64748b;
      --border: #e2e8f0;
      --radius: 16px;
    }

    body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      background: var(--bg-page);
      color: var(--text-dark);
      margin: 0;
      padding: 20px 12px 40px;
      min-height: 100vh;
    }

    .success-wrap {
      max-width: 520px;
      margin: 0 auto;
    }

    .processing-full {
      position: fixed;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(15, 23, 42, 0.7);
      backdrop-filter: blur(4px);
      z-index: 9999;
    }

    .proc-panel {
      background: #ffffff;
      color: var(--text-dark);
      padding: 32px 24px;
      border-radius: 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 16px;
      width: min(380px, 90vw);
      box-shadow: 0 20px 50px rgba(0,0,0,0.25);
      text-align: center;
    }

    .proc-ring {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      border: 5px solid #e2e8f0;
      border-top-color: var(--primary-blue);
      animation: spin 1s linear infinite;
    }

    @keyframes spin { to { transform: rotate(360deg); } }

    .receipt-card {
      background: #ffffff;
      border-radius: var(--radius);
      box-shadow: 0 10px 30px rgba(0,0,0,0.06);
      border: 1px solid var(--border);
      overflow: hidden;
      margin-bottom: 20px;
    }

    .receipt-header-bar {
      background: linear-gradient(135deg, #005ce6 0%, #003b99 100%);
      color: #ffffff;
      padding: 16px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .receipt-top {
      padding: 28px 24px 20px;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      border-bottom: 1px dashed var(--border);
    }

    .check-circle, .receipt-gcash-logo {
      width: 68px;
      height: 68px;
      border-radius: 50%;
      background: #ffffff;
      display: grid;
      place-items: center;
      margin-bottom: 12px;
      box-shadow: 0 4px 14px rgba(0, 102, 255, 0.15);
      border: 1px solid rgba(0, 102, 255, 0.12);
    }

    .check-circle img, .receipt-gcash-logo img {
      width: 50px;
      height: 50px;
      object-fit: contain;
      display: block;
    }

    .receipt-title {
      font-weight: 800;
      font-size: 1.3rem;
      color: var(--text-dark);
      margin-bottom: 2px;
    }

    .receipt-sub {
      color: var(--text-muted);
      font-size: 0.85rem;
    }

    .receipt-content {
      padding: 20px 24px;
    }

    .receipt-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 0;
      border-bottom: 1px solid #f1f5f9;
      font-size: 0.9rem;
    }

    .receipt-row:last-child {
      border-bottom: none;
    }

    .meta {
      color: var(--text-muted);
      font-size: 0.82rem;
      font-weight: 500;
    }

    .value {
      font-weight: 600;
      color: var(--text-dark);
      text-align: right;
    }

    .receipt-amount {
      font-size: 1.4rem;
      color: var(--primary-blue);
      font-weight: 800;
    }

    .status-pill {
      background: #e6f7ec;
      color: #16a34a;
      font-weight: 700;
      font-size: 0.8rem;
      padding: 3px 10px;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .actions-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      padding: 0 24px 24px;
    }

    .btn-action-primary {
      background: var(--brand);
      color: #ffffff;
      border: none;
      padding: 12px 14px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 0.9rem;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      cursor: pointer;
      transition: all 0.2s;
    }

    .btn-action-primary:hover {
      background: var(--brand-dark);
      color: #ffffff;
    }

    .btn-action-outline {
      background: #ffffff;
      color: var(--text-dark);
      border: 1px solid var(--border);
      padding: 12px 14px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 0.9rem;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      cursor: pointer;
      transition: all 0.2s;
    }

    .btn-action-outline:hover {
      background: #f8fafc;
      border-color: #cbd5e1;
      color: var(--text-dark);
    }

    .upload-box {
      border: 2px dashed #93c5fd;
      background: #eff6ff;
      border-radius: 12px;
      padding: 20px;
      text-align: center;
      cursor: pointer;
      transition: all 0.2s;
    }

    .upload-box:hover {
      background: #dbeafe;
      border-color: #3b82f6;
    }

    @media print {
      .processing-full, .actions-grid, .upload-section, .btn-exit-header, .payment-footer-note {
        display: none !important;
      }
      body {
        background: #fff;
        padding: 0;
      }
      .receipt-card {
        box-shadow: none;
        border: 1px solid #ddd;
        max-width: 100%;
      }
    }
  </style>
</head>
<body>

  <div class="processing-full" id="processingScreen">
    <div class="proc-panel">
      <div class="proc-ring" aria-hidden="true"></div>
      <div style="font-size: 1.15rem; font-weight: 700;">Processing Payment...</div>
      <div style="font-size: 0.88rem; color: var(--text-muted);">Verifying transaction and generating receipt...</div>
    </div>
  </div>

  <div class="success-wrap" id="receiptWrap" style="display:none">

    <div class="receipt-card" id="printableReceipt">
      <div class="receipt-header-bar">
        <div style="display: flex; align-items: center; gap: 8px;">
          <img src="assets/gcash-logo.png" alt="GCash" style="height: 22px; background: #fff; padding: 2px 6px; border-radius: 6px;">
          <span style="font-weight: 700; font-size: 0.95rem;">Payment Receipt</span>
        </div>
      </div>

      <div class="receipt-top">
        <div class="receipt-gcash-logo">
          <img src="assets/gcash-logo.png" alt="GCash">
        </div>
        <div class="receipt-title"><?php echo htmlspecialchars($receiptTitle); ?></div>
        <div class="receipt-sub">Garden of Remembrance Memorial Park</div>
        <div class="mt-2">
          <span class="status-pill" style="<?php echo $statusPillStyle; ?>"><i class="<?php echo $statusIcon; ?> me-1"></i> <?php echo htmlspecialchars($statusPillText); ?></span>
        </div>
      </div>

      <div class="receipt-content">
        <div class="receipt-row">
          <span class="meta">Recipient Account</span>
          <span class="value"><?php echo htmlspecialchars($gcash_payment['account_name']); ?></span>
        </div>

        <div class="receipt-row">
          <span class="meta">GCash Number</span>
          <span class="value font-monospace text-primary"><?php echo htmlspecialchars($gcash_payment['display_number']); ?></span>
        </div>

        <?php if ($booking_id > 0): ?>
        <div class="receipt-row">
          <span class="meta">Booking ID</span>
          <span class="value font-monospace">#<?php echo htmlspecialchars($booking_id); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($booking_info && !empty($booking_info['section'])): ?>
        <div class="receipt-row">
          <span class="meta">Grave Plot</span>
          <span class="value">Section <?php echo htmlspecialchars($booking_info['section']); ?> Plot <?php echo htmlspecialchars($booking_info['plot'] ?? $booking_info['grave_location'] ?? '—'); ?></span>
        </div>
        <?php endif; ?>

        <div class="receipt-row">
          <span class="meta">Customer Name</span>
          <span class="value"><?php echo htmlspecialchars($customer_name); ?></span>
        </div>

        <?php if ($total_amount > 0): ?>
        <div class="receipt-row">
          <span class="meta">Total Amount</span>
          <span class="value font-monospace">₱<?php echo number_format($total_amount, 2); ?></span>
        </div>
        <?php endif; ?>

        <div class="receipt-row">
          <span class="meta">Amount Paid</span>
          <span class="receipt-amount font-monospace" id="recAmount">₱<?php echo number_format($amount_paid, 2); ?></span>
        </div>

        <?php if ($total_amount > 0 && $isPartial): ?>
        <div class="receipt-row">
          <span class="meta">Remaining Balance</span>
          <span class="value font-monospace text-danger fw-bold">₱<?php echo number_format($remaining_balance, 2); ?></span>
        </div>
        <?php endif; ?>

        <div class="receipt-row">
          <span class="meta">Official Receipt No.</span>
          <span class="value font-monospace text-dark fw-bold" id="recRef"><?php echo htmlspecialchars($official_receipt_number); ?></span>
        </div>

        <?php if (!empty($gcash_ref_number)): ?>
        <div class="receipt-row">
          <span class="meta">GCash Reference No.</span>
          <span class="value font-monospace text-primary fw-bold" id="gcashRef"><?php echo htmlspecialchars($gcash_ref_number); ?></span>
        </div>
        <?php endif; ?>

        <div class="receipt-row">
          <span class="meta">Payment Date & Time</span>
          <span class="value" id="recDate"><?php echo date('M d, Y • h:i A', strtotime($payment_date)); ?></span>
        </div>

        <div class="receipt-row">
          <span class="meta">Payment Method</span>
          <span class="value"><i class="fas fa-mobile-alt text-primary me-1"></i> GCash</span>
        </div>
      </div>

      <?php if ($isForVerification): ?>
      <div class="p-3 text-center border-top" style="font-size: 0.82rem; background: #eff6ff; color: #1e40af; border-color: #bfdbfe !important;">
        <i class="fas fa-info-circle me-1"></i> <strong>Payment proof submitted. Waiting for admin verification.</strong><br>
        <span style="font-size: 0.76rem; color: #3b82f6;">Your reservation will be confirmed once the administrator reviews and approves your uploaded payment proof.</span>
      </div>
      <?php elseif ($isRejected): ?>
      <div class="p-3 text-center border-top" style="font-size: 0.82rem; background: #fef2f2; color: #991b1b; border-color: #fecaca !important;">
        <i class="fas fa-exclamation-circle me-1"></i> <strong>Payment proof rejected.</strong><br>
        <span style="font-size: 0.76rem;"><?php echo htmlspecialchars($receipt_info['rejection_reason'] ?? 'Please submit a clear screenshot of your GCash transaction.'); ?></span>
      </div>
      <?php else: ?>
      <div class="p-3 bg-light text-center border-top" style="font-size: 0.75rem; color: #64748b;">
        <i class="fas fa-info-circle me-1"></i> Official System Transaction Record • Garden of Remembrance Memorial Park
      </div>
      <?php endif; ?>

      <div class="actions-grid mt-3">
        <button type="button" id="printBtn" class="btn-action-outline">
          <i class="fas fa-print"></i> Print Receipt
        </button>
        <button type="button" id="downloadReceiptBtn" class="btn-action-primary">
          <i class="fas fa-download"></i> Download Receipt
        </button>
      </div>

      <div id="downloadNotice" style="display: none; margin: 0 24px 18px; padding: 10px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; color: #065f46; font-size: 0.82rem; text-align: center;">
        <i class="fas fa-check-circle me-1"></i> Receipt downloaded! You can use this file as proof of payment.
      </div>
    </div>

    <div class="text-center mt-3">
      <a href="<?php echo htmlspecialchars($dashboard_url); ?>" style="color: var(--text-muted); font-size: 0.85rem; text-decoration: none; font-weight: 500;">
        <i class="fas fa-arrow-left me-1"></i> <?php echo htmlspecialchars($dashboard_label); ?>
      </a>
    </div>

  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const proc = document.getElementById('processingScreen');
      const receiptWrap = document.getElementById('receiptWrap');

      // Reveal receipt after brief transition
      setTimeout(() => {
        if (proc) proc.style.display = 'none';
        if (receiptWrap) receiptWrap.style.display = 'block';
      }, 1200);

      // Print handler
      const printBtn = document.getElementById('printBtn');
      if (printBtn) {
        printBtn.addEventListener('click', function() {
          window.print();
        });
      }

      // Download Receipt handler using html2canvas
      const downloadBtn = document.getElementById('downloadReceiptBtn');
      if (downloadBtn) {
        downloadBtn.addEventListener('click', function() {
          const receiptEl = document.getElementById('printableReceipt');
          const actionsGrid = receiptEl.querySelector('.actions-grid');
          const exitBtn = receiptEl.querySelector('.btn-exit-header');
          const notice = document.getElementById('downloadNotice');

          const origBtnHtml = downloadBtn.innerHTML;
          downloadBtn.disabled = true;
          downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Generating...';

          // Temporarily hide buttons and Done link so they don't appear in the captured image
          if (actionsGrid) actionsGrid.style.display = 'none';
          if (exitBtn) exitBtn.style.visibility = 'hidden';

          if (typeof html2canvas === 'function') {
            html2canvas(receiptEl, {
              scale: 2,
              useCORS: true,
              backgroundColor: '#ffffff'
            }).then(function(canvas) {
              if (actionsGrid) actionsGrid.style.display = 'grid';
              if (exitBtn) exitBtn.style.visibility = 'visible';
              downloadBtn.disabled = false;
              downloadBtn.innerHTML = origBtnHtml;

              const link = document.createElement('a');
              link.download = 'Receipt_Booking_<?php echo $booking_id; ?>_<?php echo preg_replace('/[^A-Za-z0-9_-]/', '', $official_receipt_number); ?>.png';
              link.href = canvas.toDataURL('image/png');
              link.click();

              if (notice) {
                notice.style.display = 'block';
              }
            }).catch(function(err) {
              console.error('Download receipt error:', err);
              if (actionsGrid) actionsGrid.style.display = 'grid';
              if (exitBtn) exitBtn.style.visibility = 'visible';
              downloadBtn.disabled = false;
              downloadBtn.innerHTML = origBtnHtml;
              alert('Unable to generate receipt image directly. You can use Print Receipt > Save as PDF.');
            });
          } else {
            if (actionsGrid) actionsGrid.style.display = 'grid';
            if (exitBtn) exitBtn.style.visibility = 'visible';
            downloadBtn.disabled = false;
            downloadBtn.innerHTML = origBtnHtml;
            window.print();
          }
        });
      }
    });
  </script>
</body>
</html>

<?php
/**
 * =========================================================================
 * Garden of Remembrance Memorial Park
 * Official Sales Invoice & Payment Receipt System
 * =========================================================================
 * Generates print-ready invoices and official statements for:
 * 1. Single Booking / Reservation (Grave plots & cemetery care services)
 * 2. Consolidated Monthly Sales & Collection Statements
 */

// Core database connection and system helper includes
include_once __DIR__ . '/connection.php';
require_once __DIR__ . '/config/payment_settings.php';
require_once __DIR__ . '/includes/formatters.php';

// Ensure user session is started if not already initialized
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// Authentication & role authorization checks
$current_role = isset($_SESSION['role']) ? strtoupper(trim((string)$_SESSION['role'])) : '';
$is_admin = ($current_role === 'ADMIN');
$user_id = (int)($_SESSION['user_id'] ?? 0);

// Restrict access: Only authenticated users can access the invoice system
if (!$user_id) {
    header('Location: admin_login.php');
    exit;
}

// Fetch current administrator profile name for signatures and audit trail
$admin_name = 'Authorized Administrator';
$admin_query = $conn->query("SELECT first_name, last_name FROM tbl_user WHERE user_id = $user_id LIMIT 1");
if ($admin_query && $arow = $admin_query->fetch_assoc()) {
    $admin_name = trim(($arow['first_name'] ?? '') . ' ' . ($arow['last_name'] ?? '')) ?: 'Administrator';
}

// Parse request query parameters (Booking ID, Sales Month, Start Date, End Date, Autoprint flag)
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$start_date = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date']) ? $_GET['end_date'] : '';
$sales_month = isset($_GET['month']) ? trim($_GET['month']) : (isset($_GET['sales_month']) ? trim($_GET['sales_month']) : '');
if (!empty($sales_month) && !preg_match('/^\d{4}-\d{2}$/', $sales_month)) {
    $sales_month = date('Y-m');
}
$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';

// Load active GCash merchant configuration and hotline settings
$gcashSettings = get_gcash_payment_settings();

$booking = null;
$receipt = null;

// =========================================================================
// Case 1: Fetch Single Booking Invoice & Official Receipt Details
// =========================================================================
if ($booking_id > 0) {
    // Query booking record with booker profile join
    $sql = "SELECT b.*,
                   COALESCE(NULLIF(TRIM(b.customer_name), ''), NULLIF(TRIM(b.full_name), ''), NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), 'Valued Customer') AS client_name,
                   COALESCE(NULLIF(TRIM(b.customer_email), ''), NULLIF(TRIM(b.email), ''), NULLIF(TRIM(u.email), ''), 'N/A') AS client_email,
                   COALESCE(NULLIF(TRIM(b.contact_number), ''), NULLIF(TRIM(u.phone_no), ''), 'N/A') AS client_phone,
                   u.user_id AS account_user_id
              FROM tbl_bookings b
         LEFT JOIN tbl_user u ON u.user_id = b.user_id
             WHERE b.id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $booking_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $booking = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    // Verify booking existence
    if (!$booking) {
        die("<h3>Invoice Not Found</h3><p>Booking ID #{$booking_id} does not exist.</p><p><a href='sales_reports.php'>Back to Sales Reports</a></p>");
    }

    // Security check: Only admins or the original booker may access this invoice
    if (!$is_admin && (int)$booking['account_user_id'] !== $user_id) {
        die("<h3>Access Denied</h3><p>You do not have permission to view this invoice.</p>");
    }

    // Retrieve payment receipt records for this booking
    $payment_id_param = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;
    if ($payment_id_param > 0) {
        $rStmt = $conn->prepare("SELECT * FROM payment_receipts WHERE id = ? AND booking_id = ? LIMIT 1");
        if ($rStmt) {
            $rStmt->bind_param('ii', $payment_id_param, $booking_id);
            $rStmt->execute();
            $rRes = $rStmt->get_result();
            $receipt = $rRes ? $rRes->fetch_assoc() : null;
            $rStmt->close();
        }
    }
    if (!$receipt) {
        $rStmt = $conn->prepare("SELECT * FROM payment_receipts WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
        if ($rStmt) {
            $rStmt->bind_param('i', $booking_id);
            $rStmt->execute();
            $rRes = $rStmt->get_result();
            $receipt = $rRes ? $rRes->fetch_assoc() : null;
            $rStmt->close();
        }
    }

    // Compute pricing, effective paid amount, and remaining balance
    $total_amount = floatval($booking['service_price'] ?? ($booking['total_amount'] ?? ($booking['plot_price'] ?? 0)));
    if ($total_amount <= 0 && strtolower(trim($booking['purpose'] ?? '')) === 'cemetery_service') {
        $total_amount = 200.00;
    }
    $amount_paid = floatval($receipt['amount_paid'] ?? ($booking['amount_paid'] ?? 0));
    $balance = max(0, $total_amount - $amount_paid);

    // Determine normalized payment status badge
    $booking_status = strtolower($booking['status'] ?? 'pending');
    $pay_status = 'PENDING';
    if ($booking_status === 'cancelled' || $booking_status === 'rejected') {
        $pay_status = strtoupper($booking_status);
    } elseif ($amount_paid >= $total_amount && $total_amount > 0) {
        $pay_status = 'PAID';
    } elseif ($amount_paid > 0) {
        $pay_status = 'PARTIAL';
    } elseif ($booking_status === 'approved' || $booking_status === 'completed') {
        $pay_status = 'PAID';
    }

    // Format plot location and service purpose
    $section = $booking['section'] ?? '—';
    $plot = format_plot_number($booking['plot'] ?? ($booking['grave_location'] ?? '—'), $section);
    $purpose = $booking['purpose'] ?? 'Grave Reservation';
    $service_type = !empty($booking['service_type']) ? $booking['service_type'] : ($purpose === 'cemetery_service' ? 'Cemetery Care Service' : 'Grave Plot Reservation');

    // Build unique official receipt and invoice numbers
    $official_receipt_no = !empty($receipt['receipt_number']) ? $receipt['receipt_number'] : ('RCPT-' . date('Ymd', strtotime($booking['created_at'])) . '-' . str_pad($booking_id, 4, '0', STR_PAD_LEFT));
    $gcash_ref_no = !empty($receipt['gcash_reference_number']) ? $receipt['gcash_reference_number'] : (!empty($booking['gcash_reference_number']) ? $booking['gcash_reference_number'] : ($booking['reference_number'] ?? ''));
    $ref_num = $official_receipt_no;
    $pay_date = !empty($receipt['payment_date']) ? $receipt['payment_date'] : (!empty($booking['payment_proof_uploaded_at']) ? $booking['payment_proof_uploaded_at'] : $booking['created_at']);
    $pay_method = !empty($receipt['payment_method']) ? $receipt['payment_method'] : ($booking['payment_type'] ?? 'GCash');
    $invoice_number = 'INV-' . date('Ym', strtotime($booking['created_at'])) . '-' . str_pad($booking_id, 4, '0', STR_PAD_LEFT);
}

// =========================================================================
// Case 2: Consolidated Monthly Sales Invoice Report
// =========================================================================
$monthly_data = [];
$monthly_total = 0.0;
$monthly_paid_count = 0;
$monthly_pending_count = 0;
$sales_month_label = '';

if ($booking_id <= 0) {
    $filter = isset($_GET['filter']) ? strtolower(trim($_GET['filter'])) : 'all';
    $filter_tag = ($filter !== 'all' && $filter !== '') ? ('-' . strtoupper($filter)) : '';

    if (!empty($start_date) && !empty($end_date)) {
        if ($start_date > $end_date) {
            $temp = $start_date;
            $start_date = $end_date;
            $end_date = $temp;
        }
        $sales_month_start = $start_date;
        $sales_month_end = $end_date;
        if ($start_date === $end_date) {
            $sales_month_label = date('F d, Y', strtotime($start_date));
        } elseif (date('Y-m-01', strtotime($start_date)) === $start_date && date('Y-m-t', strtotime($start_date)) === $end_date) {
            $sales_month_label = date('F Y', strtotime($start_date));
        } else {
            $sales_month_label = date('M d, Y', strtotime($start_date)) . ' – ' . date('M d, Y', strtotime($end_date));
        }
        $invoice_number = 'INV-SALES-' . str_replace('-', '', $start_date) . '-' . str_replace('-', '', $end_date) . $filter_tag;
    } else {
        if (empty($sales_month)) {
            $sales_month = date('Y-m');
        }
        $sales_month_label = date('F Y', strtotime($sales_month . '-01'));
        $sales_month_start = $sales_month . '-01';
        $sales_month_end = date('Y-m-t', strtotime($sales_month_start));
        $invoice_number = 'INV-SALES-' . str_replace('-', '', $sales_month) . $filter_tag;
    }

    // Retrieve all booking submissions and receipts matching this calendar month (consistent with sales_reports.php)
    $month_sql = "SELECT b.id, b.user_id, b.section, b.plot, b.grave_location, b.status, b.purpose, b.service_type, 
                         b.total_amount, b.plot_price, b.service_price, b.interment_fee, b.amount_paid AS booking_amount_paid, 
                         b.payment_type, b.reference_number, b.created_at,
                         COALESCE(NULLIF(TRIM(b.customer_name), ''), NULLIF(TRIM(b.full_name), ''), CONCAT_WS(' ', u.first_name, u.last_name), 'Client') AS customer_name,
                         COALESCE(NULLIF(TRIM(b.customer_email), ''), NULLIF(TRIM(b.email), ''), u.email, '—') AS customer_email,
                         MAX(pr.receipt_number) AS receipt_number,
                         MAX(pr.payment_date) AS pr_payment_date,
                         MAX(pr.payment_status) AS pr_status,
                         COALESCE(SUM(pr.amount_paid), 0) AS total_payment_received
                    FROM tbl_bookings b
               LEFT JOIN tbl_user u ON u.user_id = b.user_id
               LEFT JOIN payment_receipts pr ON pr.booking_id = b.id AND LOWER(TRIM(COALESCE(pr.payment_status, ''))) NOT IN ('rejected', 'cancelled')
                   WHERE (
                       DATE(b.created_at) BETWEEN ? AND ?
                       OR (pr.payment_date IS NOT NULL AND DATE(pr.payment_date) BETWEEN ? AND ?)
                   )
                   GROUP BY b.id, b.user_id, b.section, b.plot, b.grave_location, b.status, b.purpose, b.service_type, 
                            b.total_amount, b.plot_price, b.service_price, b.interment_fee, b.amount_paid, b.payment_type, 
                            b.reference_number, b.created_at, b.customer_name, b.full_name, u.first_name, u.last_name
                   ORDER BY b.id DESC";

    $mStmt = $conn->prepare($month_sql);
    if ($mStmt) {
        $mStmt->bind_param('ssss', $sales_month_start, $sales_month_end, $sales_month_start, $sales_month_end);
        $mStmt->execute();
        $mRes = $mStmt->get_result();
        while ($row = $mRes->fetch_assoc()) {
            $raw_plot_price = (float)($row['plot_price'] ?? 0);
            $raw_total_amount = (float)($row['total_amount'] ?? 0);
            $receipt_total = (float)($row['total_payment_received'] ?? 0);
            $booking_paid_total = (float)($row['booking_amount_paid'] ?? 0);
            $aPaid = max($receipt_total, $booking_paid_total);

            $base_price = max($raw_total_amount, $raw_plot_price);
            if ($aPaid > $base_price) {
                $tPrice = $aPaid;
            } else {
                $tPrice = ($base_price > 0) ? $base_price : ($aPaid > 0 ? $aPaid : 85000.00);
            }

            $b_status = strtolower(trim((string)($row['status'] ?? '')));
            $is_cancelled = in_array($b_status, ['cancelled', 'rejected', 'declined', 'expired', 'void'], true);

            // Do NOT include cancelled or rejected bookings
            if ($is_cancelled) {
                continue;
            }

            // Do NOT include pending bookings with NO payment (only include if nabayaran na, amount paid > 0)
            if ($aPaid <= 0) {
                continue;
            }

            if ($aPaid >= $tPrice && $tPrice > 0) {
                $stLabel = 'PAID';
            } elseif ($aPaid > 0) {
                $stLabel = 'PARTIAL';
            } else {
                $stLabel = 'PENDING';
            }

            $monthly_total += $aPaid;
            if ($stLabel === 'PAID') {
                $monthly_paid_count++;
            } else {
                $monthly_pending_count++;
            }

            $monthly_data[] = [
                'id' => (int)$row['id'],
                'date' => $row['pr_payment_date'] ?? $row['created_at'],
                'customer' => $row['customer_name'],
                'section' => $row['section'] ?? '—',
                'plot' => format_plot_number($row['plot'] ?? ($row['grave_location'] ?? '—'), $row['section'] ?? ''),
                'purpose' => !empty($row['service_type']) ? $row['service_type'] : (strtolower(trim($row['purpose'] ?? '')) === 'cemetery_service' ? 'Cemetery Service' : 'Grave Reservation'),
                'total' => $tPrice,
                'paid' => $aPaid,
                'payment_type' => $row['payment_type'] ?? 'GCash',
                'status' => $stLabel,
                'ref' => !empty($row['receipt_number']) ? $row['receipt_number'] : (!empty($row['reference_number']) ? $row['reference_number'] : 'N/A')
            ];
        }
        $mStmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($invoice_number); ?> — Official Sales Invoice</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Cinzel:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand: #7b1113;
            --brand-dark: #5a0c0e;
            --brand-light: #fbf6f6;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
        }

        body {
            background-color: #f1f5f9;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--text-dark);
            margin: 0;
            padding: 30px 15px 50px;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Top Action Bar (Hidden on print) */
        .no-print-bar {
            max-width: 900px;
            margin: 0 auto 20px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
            padding: 12px 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border-color);
        }

        .btn-print {
            background: var(--brand);
            color: #ffffff;
            font-weight: 700;
            border: none;
            border-radius: 8px;
            padding: 8px 22px;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            cursor: pointer;
        }

        .btn-print:hover {
            background: var(--brand-dark);
            color: #fff;
            transform: translateY(-1px);
        }

        .btn-back {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 8px 18px;
            font-size: 0.88rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-back:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        /* Invoice Container */
        .invoice-box {
            max-width: 900px;
            margin: 0 auto;
            background: #ffffff;
            padding: 40px 45px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border-color);
            position: relative;
        }

        /* Watermark Crest */
        .invoice-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 26rem;
            color: rgba(123, 17, 19, 0.025);
            pointer-events: none;
            z-index: 0;
            user-select: none;
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #7b1113;
            padding-bottom: 24px;
            margin-bottom: 28px;
            position: relative;
            z-index: 1;
        }

        .invoice-brand-wrap {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .invoice-logo {
            height: 64px;
            width: auto;
            max-width: 72px;
            object-fit: contain;
            flex-shrink: 0;
        }

        .brand-title {
            font-family: 'Cinzel', serif;
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--brand);
            margin: 0 0 4px 0;
            letter-spacing: 0.03em;
        }

        .brand-sub {
            font-size: 0.84rem;
            color: var(--text-muted);
            margin: 0;
            line-height: 1.4;
        }

        .invoice-badge-title {
            text-align: right;
        }

        .inv-badge-text {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--brand);
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin: 0;
        }

        .inv-number {
            font-family: monospace;
            font-size: 0.95rem;
            font-weight: 700;
            color: #334155;
            background: #f8fafc;
            padding: 3px 8px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            display: inline-block;
            margin-top: 4px;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 24px;
            margin-bottom: 28px;
            position: relative;
            z-index: 1;
        }

        .meta-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 20px;
        }

        .meta-card h6 {
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--brand);
            margin-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 6px;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.86rem;
            margin-bottom: 6px;
        }

        .meta-row:last-child {
            margin-bottom: 0;
        }

        .meta-label {
            color: var(--text-muted);
        }

        .meta-val {
            font-weight: 600;
            color: var(--text-dark);
            text-align: right;
        }

        /* Items Table */
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            position: relative;
            z-index: 1;
        }

        .invoice-table th {
            background: var(--brand);
            color: #ffffff;
            padding: 10px 14px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .invoice-table td {
            padding: 12px 14px;
            font-size: 0.86rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        .invoice-table tbody tr:nth-child(even) {
            background-color: #fafbfc;
        }

        /* Totals Block */
        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .totals-box {
            width: 340px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 20px;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            margin-bottom: 8px;
        }

        .totals-row.grand-total {
            border-top: 2px solid var(--brand);
            padding-top: 10px;
            margin-top: 10px;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--brand);
        }

        /* Status Badge */
        .badge-paid {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            display: inline-block;
        }

        .badge-pending {
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #fde047;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            display: inline-block;
        }

        .badge-partial {
            background: #e0f2fe;
            color: #075985;
            border: 1px solid #7dd3fc;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            display: inline-block;
        }

        .badge-cancelled, .badge-rejected {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            display: inline-block;
        }

        /* Signatures */
        .signatures-row {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            padding-top: 24px;
            border-top: 1px dashed var(--border-color);
            position: relative;
            z-index: 1;
        }

        .sig-col {
            width: 220px;
            text-align: center;
        }

        .sig-line {
            border-bottom: 1px solid #94a3b8;
            height: 40px;
            margin-bottom: 6px;
        }

        .sig-name {
            font-weight: 700;
            font-size: 0.88rem;
            color: #1e293b;
            margin-bottom: 2px;
        }

        .sig-title {
            font-size: 0.74rem;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        /* Footer */
        .invoice-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
            font-size: 0.78rem;
            color: var(--text-muted);
            position: relative;
            z-index: 1;
        }

        /* Print Media Styles */
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .no-print-bar {
                display: none !important;
            }

            .invoice-box {
                box-shadow: none !important;
                border: none !important;
                padding: 20px 25px !important;
                max-width: 100% !important;
                width: 100% !important;
            }

            .invoice-table th {
                background: #7b1113 !important;
                color: #ffffff !important;
            }

            .totals-row.grand-total {
                color: #7b1113 !important;
            }
        }
    </style>
</head>
<body>

        <!-- Navigation Action Bar (Print & Back Controls - Hidden on Print) -->
    <div class="no-print-bar">
        <div class="d-flex align-items-center gap-2">
            <?php
            $backParams = [];
            if (!empty($start_date) && !empty($end_date)) {
                $backParams[] = 'start_date=' . urlencode($start_date);
                $backParams[] = 'end_date=' . urlencode($end_date);
            } elseif (!empty($sales_month)) {
                $backParams[] = 'sales_month=' . urlencode($sales_month);
            }
            $backUrl = 'sales_reports.php' . (!empty($backParams) ? '?' . implode('&', $backParams) : '');
            ?>
            <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Sales Reports
            </a>
                    <!-- Single Booking Invoice Details -->
        <?php if ($booking_id > 0): ?>
                <a href="sales_reports.php?sales_month=<?php echo urlencode(date('Y-m', strtotime($booking['created_at']))); ?>" class="btn-back">
                    <i class="fas fa-calendar-alt"></i> View <?php echo date('M Y', strtotime($booking['created_at'])); ?> Sales
                </a>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" onclick="window.print()" class="btn-print">
                <i class="fas fa-print"></i> Print Invoice
            </button>
        </div>
    </div>

        <!-- Main Printable Invoice Container -->
    <div class="invoice-box">
                <!-- Watermark Memorial Monument Emblem -->
        <div class="invoice-watermark">
            <i class="fas fa-monument"></i>
        </div>

                <!-- Invoice Header: Park Branding & Address -->
        <div class="invoice-header">
            <div class="invoice-brand-wrap">
                <img src="cmis_logo.png" alt="Garden of Remembrance Logo" class="invoice-logo">
                <div>
                    <h1 class="brand-title">Garden of Remembrance Memorial Park</h1>
                    <p class="brand-sub">
                        <strong>Memorial Park Cemetery Management & Information System</strong><br>
                        De Guzman Street, Barangay Palamis, Alaminos City, Pangasinan 2404, Philippines<br>
                        Hotline: 0960-529-5663 / 0928-864-2520 • GCash Support: <?php echo htmlspecialchars($gcashSettings['display_number']); ?>
                    </p>
                </div>
            </div>
            <div class="invoice-badge-title">
                <div class="inv-badge-text">
                    <?php echo ($booking_id > 0) ? 'Sales Invoice' : 'Monthly Statement'; ?>
                </div>
                <div class="inv-number"><?php echo htmlspecialchars($invoice_number); ?></div>
                <div class="mt-2">
                            <!-- Single Booking Invoice Details -->
        <?php if ($booking_id > 0): ?>
                        <?php if ($pay_status === 'PAID'): ?>
                            <span class="badge-paid"><i class="fas fa-check-circle me-1"></i> PAID IN FULL</span>
                        <?php elseif ($pay_status === 'PARTIAL'): ?>
                            <span class="badge-partial"><i class="fas fa-adjust me-1"></i> PARTIAL PAYMENT</span>
                        <?php elseif ($pay_status === 'CANCELLED' || $pay_status === 'REJECTED'): ?>
                            <span class="badge-cancelled"><i class="fas fa-times-circle me-1"></i> <?php echo htmlspecialchars($pay_status); ?> (VOID)</span>
                        <?php else: ?>
                            <span class="badge-pending"><i class="fas fa-clock me-1"></i> PAYMENT PENDING</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge-paid"><i class="fas fa-file-alt me-1"></i> OFFICIAL REPORT</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

                <!-- Single Booking Invoice Details -->
        <?php if ($booking_id > 0): ?>

            <div class="meta-grid">

                <div class="meta-card">
                    <h6><i class="fas fa-user me-1"></i> Billed To (Customer Details)</h6>
                    <div class="meta-row">
                        <span class="meta-label">Customer Name:</span>
                        <span class="meta-val"><?php echo htmlspecialchars($booking['client_name']); ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Email Address:</span>
                        <span class="meta-val"><?php echo htmlspecialchars($booking['client_email']); ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Contact Number:</span>
                        <span class="meta-val font-monospace"><?php echo htmlspecialchars($booking['client_phone']); ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Address / Location:</span>
                        <span class="meta-val"><?php echo htmlspecialchars($booking['booker_location'] ?? '—'); ?></span>
                    </div>
                </div>

                <div class="meta-card">
                    <h6><i class="fas fa-info-circle me-1"></i> Invoice & Plot Information</h6>
                    <div class="meta-row">
                        <span class="meta-label">Invoice Date:</span>
                        <span class="meta-val"><?php echo date('F d, Y', strtotime($booking['created_at'])); ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Reservation ID:</span>
                        <span class="meta-val font-monospace">#<?php echo (int)$booking['id']; ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Section & Plot:</span>
                        <span class="meta-val">Section <?php echo htmlspecialchars($section); ?><?php echo ($plot !== '—') ? ' • Plot ' . htmlspecialchars($plot) : ''; ?></span>
                    </div>
                    <?php if (!empty($booking['deceased_name'])): ?>
                        <div class="meta-row">
                            <span class="meta-label">Deceased Person:</span>
                            <span class="meta-val"><?php echo htmlspecialchars($booking['deceased_name']); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="meta-row">
                        <span class="meta-label">Payment Method:</span>
                        <span class="meta-val"><?php echo htmlspecialchars($pay_method); ?></span>
                    </div>
                </div>
            </div>

                        <!-- Line Items & Financial Summary Table -->
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th style="width: 8%;">#</th>
                        <th style="width: 52%;">Item Description & Details</th>
                        <th style="width: 15%; text-align: center;">Qty</th>
                        <th style="width: 25%; text-align: right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td>
                            <strong><?php echo htmlspecialchars($service_type); ?></strong>
                            <div class="text-muted small mt-1">
                                Section <?php echo htmlspecialchars($section); ?>, Plot <?php echo htmlspecialchars($plot); ?>
                                <?php if (!empty($booking['preferred_date'])): ?>
                                    • Preferred Date: <?php echo htmlspecialchars($booking['preferred_date']); ?>
                                <?php endif; ?>
                                <?php if (!empty($booking['remarks']) || !empty($booking['special_requests'])): ?>
                                    <br><em>Notes: <?php echo htmlspecialchars($booking['remarks'] ?: $booking['special_requests']); ?></em>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="text-align: center;">1</td>
                        <td style="text-align: right; font-weight: 700;"><?php echo formatDisplayCurrency($total_amount); ?></td>
                    </tr>
                </tbody>
            </table>

                        <!-- Financial Balance & Payment Receipts Details -->
            <div class="totals-section">
                <div class="totals-box">
                    <div class="totals-row">
                        <span class="text-muted">Subtotal:</span>
                        <span class="fw-semibold"><?php echo formatDisplayCurrency($total_amount); ?></span>
                    </div>
                    <div class="totals-row">
                        <span class="text-muted">Tax / Handling Fee:</span>
                        <span class="fw-semibold">₱0.00</span>
                    </div>
                    <div class="totals-row">
                        <span class="text-muted">Amount Paid:</span>
                        <span class="fw-bold text-success">-<?php echo formatDisplayCurrency($amount_paid); ?></span>
                    </div>
                    <div class="totals-row grand-total">
                        <span>Balance Due:</span>
                        <span><?php echo formatDisplayCurrency($balance); ?></span>
                    </div>
                    <div class="mt-2 pt-2 border-top text-muted small" style="font-size: 0.78rem;">
                        <strong>Official Receipt No:</strong><br>
                        <span class="font-monospace text-dark fw-bold"><?php echo htmlspecialchars($official_receipt_no); ?></span><br>
                        <?php if (!empty($gcash_ref_no)): ?>
                            <strong>GCash Reference No:</strong><br>
                            <span class="font-monospace text-primary fw-bold"><?php echo htmlspecialchars($gcash_ref_no); ?></span><br>
                        <?php endif; ?>
                        <strong>Payment Date:</strong> <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($pay_date))); ?>
                    </div>
                </div>
            </div>

        <?php else: ?>

            <div class="meta-grid">
                <div class="meta-card">
                    <h6><i class="fas fa-chart-line me-1"></i> Statement Overview</h6>
                    <div class="meta-row">
                        <span class="meta-label">Billing Period:</span>
                        <span class="meta-val fw-bold text-dark"><?php echo htmlspecialchars($sales_month_label); ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Generated Date:</span>
                        <span class="meta-val"><?php echo date('F d, Y • h:i A'); ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Total Transactions:</span>
                        <span class="meta-val"><?php echo count($monthly_data); ?> entries</span>
                    </div>
                </div>

                <div class="meta-card">
                    <h6><i class="fas fa-calculator me-1"></i> Revenue Summary</h6>
                    <div class="meta-row">
                        <span class="meta-label">Total Monthly Sales:</span>
                        <span class="meta-val fs-6 fw-bold" style="color: var(--brand);"><?php echo formatDisplayCurrency($monthly_total); ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Completed / Paid:</span>
                        <span class="meta-val text-success"><?php echo (int)$monthly_paid_count; ?> transactions</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Pending Payments:</span>
                        <span class="meta-val text-warning"><?php echo (int)$monthly_pending_count; ?> transactions</span>
                    </div>
                </div>
            </div>

                        <!-- Line Items & Financial Summary Table -->
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th style="width: 8%;">Reservation</th>
                        <th style="width: 13%;">Date</th>
                        <th style="width: 20%;">Customer</th>
                        <th style="width: 17%;">Section / Plot</th>
                        <th style="width: 16%;">Service / Purpose</th>
                        <th style="width: 12%; text-align: center;">Status</th>
                        <th style="width: 14%; text-align: right;">Amount Paid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($monthly_data)): ?>
                        <?php foreach ($monthly_data as $idx => $item): ?>
                            <?php
                                $stBadge = 'background: #dcfce7; color: #166534; border: 1px solid #86efac;';
                                if ($item['status'] === 'CANCELLED') {
                                    $stBadge = 'background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;';
                                } elseif ($item['status'] === 'PENDING') {
                                    $stBadge = 'background: #fef3c7; color: #92400e; border: 1px solid #fde68a;';
                                } elseif ($item['status'] === 'PARTIAL') {
                                    $stBadge = 'background: #e0f2fe; color: #075985; border: 1px solid #7dd3fc;';
                                }
                            ?>
                            <tr>
                                <td class="font-monospace fw-bold" style="color: var(--brand);">#<?php echo (int)$item['id']; ?></td>
                                <td class="text-muted small"><?php echo date('M d, Y', strtotime($item['date'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($item['customer']); ?></strong></td>
                                <td>Section <?php echo htmlspecialchars($item['section']); ?><?php echo ($item['plot'] !== '—') ? ' • ' . htmlspecialchars($item['plot']) : ''; ?></td>
                                <td><?php echo htmlspecialchars($item['purpose']); ?></td>
                                <td style="text-align: center;">
                                    <span style="display: inline-block; padding: 2px 7px; border-radius: 6px; font-size: 0.72rem; font-weight: 700; <?php echo $stBadge; ?>">
                                        <?php echo htmlspecialchars($item['status']); ?>
                                    </span>
                                </td>
                                <td style="text-align: right; font-weight: 700;"><?php echo formatDisplayCurrency($item['paid']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No transactions recorded for this period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

                        <!-- Financial Balance & Payment Receipts Details -->
            <div class="totals-section">
                <div class="totals-box">
                    <div class="totals-row">
                        <span class="text-muted">Total Transactions:</span>
                        <span class="fw-semibold"><?php echo count($monthly_data); ?></span>
                    </div>
                    <div class="totals-row grand-total">
                        <span>Total Revenue:</span>
                        <span><?php echo formatDisplayCurrency($monthly_total); ?></span>
                    </div>
                </div>
            </div>

        <?php endif; ?>

                <!-- Authorized Administrator & Park Signatures -->
        <div class="signatures-row">
            <div class="sig-col">
                <div class="sig-line"></div>
                <div class="sig-name"><?php echo htmlspecialchars($admin_name); ?></div>
                <div class="sig-title">Prepared by / CMIS Admin</div>
            </div>
            <div class="sig-col">
                <div class="sig-line"></div>
                <div class="sig-name">Park Administration</div>
                <div class="sig-title">Approved & Verified</div>
            </div>
        </div>

                <!-- Official Document Notice & Footer -->
        <div class="invoice-footer">
            Thank you for choosing Garden of Remembrance Memorial Park.<br>
            This document is computer-generated and constitutes an official sales invoice record of the Cemetery Management & Information System (CMIS).
        </div>
    </div>

        <!-- Autoprint Handler: Automatically opens print dialog if requested -->
    <?php if ($autoprint): ?>
    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });
    </script>
    <?php endif; ?>
</body>
</html>

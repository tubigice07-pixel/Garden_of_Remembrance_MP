<?php

require_once __DIR__ . '/connection.php';
if (file_exists(__DIR__ . '/config/installment_helper.php')) {
    require_once __DIR__ . '/config/installment_helper.php';
}
require_once __DIR__ . '/config/payment_settings.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$contract_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : (isset($_GET['contract_id']) ? (int)$_GET['contract_id'] : 0);
$schedule_id = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;
$installment_num = isset($_GET['installment']) ? (int)$_GET['installment'] : 0;

$user_id = (int)($_SESSION['user_id'] ?? 0);
$is_admin = isset($_SESSION['role']) && strtoupper($_SESSION['role']) === 'ADMIN';

if ($contract_id <= 0) {
    if ($is_admin) {
        header("Location: dashboard.php");
    } elseif ($user_id > 0) {
        header("Location: user_dashboard.php");
    } else {
        header("Location: index.php");
    }
    exit;
}

$stmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $contract_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    die("Booking contract #{$contract_id} not found.");
}

if (!$is_admin && $user_id > 0 && (int)$booking['user_id'] !== $user_id) {
    die("Unauthorized access to this booking contract.");
}

$schChk = $conn->query("SELECT COUNT(*) AS cnt FROM payment_schedules WHERE contract_id = $contract_id");
$schCnt = $schChk ? (int)$schChk->fetch_assoc()['cnt'] : 0;
if ($schCnt === 0 && strtolower(trim((string)$booking['payment_plan'])) === 'installment') {
    generate_contract_payment_schedule($contract_id, $conn, false);
}

$balance_summary = update_contract_remaining_balances($contract_id, $conn);

$schedules = [];
$sRes = $conn->query("SELECT * FROM payment_schedules WHERE contract_id = $contract_id ORDER BY installment_number ASC");
if ($sRes) {
    while ($row = $sRes->fetch_assoc()) {
        $schedules[] = $row;
    }
}

$target_schedule = null;
if ($schedule_id > 0) {
    foreach ($schedules as $s) {
        if ((int)$s['id'] === $schedule_id) {
            $target_schedule = $s;
            break;
        }
    }
} elseif ($installment_num > 0) {
    foreach ($schedules as $s) {
        if ((int)$s['installment_number'] === $installment_num) {
            $target_schedule = $s;
            break;
        }
    }
}

if (!$target_schedule) {

    foreach ($schedules as $s) {
        if (strtolower($s['status']) !== 'paid') {
            $target_schedule = $s;
            break;
        }
    }
}

$amount_to_pay = $target_schedule ? (float)$target_schedule['monthly_amount'] : (float)$booking['total_amount'];
$term_months = (int)($booking['payment_term'] ?? 12);
$is_installment = strtolower(trim((string)$booking['payment_plan'])) === 'installment';

$gcash_settings = get_gcash_payment_settings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Portal - Contract #<?php echo $contract_id; ?> | Garden of Remembrance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #7b1113;
            --primary-dark: #5a0c0e;
            --primary-light: #9c1b1e;
            --accent: #d97706;
            --bg-page: #f8fafc;
            --text-dark: #0f172a;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-page);
            color: var(--text-dark);
            min-height: 100vh;
        }
        .brand-header {
            background: linear-gradient(135deg, var(--primary) 0%, #8e1518 50%, var(--primary-dark) 100%);
            color: #fff;
            padding: 24px 0;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
        }
        .brand-logo {
            height: 48px;
            object-fit: contain;
        }
        .portal-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            padding: 24px;
            margin-bottom: 24px;
        }
        .balance-stat {
            border-radius: 12px;
            padding: 16px;
            text-align: center;
        }
        .stat-total { background: #f1f5f9; border: 1px solid #e2e8f0; }
        .stat-paid { background: #ecfdf5; border: 1px solid #a7f3d0; }
        .stat-balance { background: #fff5f5; border: 1px solid #fecaca; }
        .badge-pending { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .badge-overdue { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .badge-paid { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .btn-pay-now {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
            font-weight: 700;
            border-radius: 10px;
            padding: 12px 24px;
            transition: all 0.2s;
        }
        .btn-pay-now:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            color: #fff;
            transform: translateY(-1px);
        }
        @media (max-width: 576px) {
            .brand-header {
                padding: 16px 0;
            }
            .brand-header .container {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 12px;
            }
            .brand-header .container > div:last-child {
                align-self: flex-start;
            }
            .portal-card {
                padding: 16px;
                border-radius: 14px;
            }
            .balance-stat {
                padding: 12px 8px;
            }
            .btn-pay-now {
                width: 100%;
                text-align: center;
                padding: 12px;
            }
        }
    </style>
</head>
<body>

    <header class="brand-header">
        <div class="container d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-3">
                <img src="assets/images/logo.png" alt="Garden of Remembrance" class="brand-logo" onerror="this.style.display='none';">
                <div>
                    <h5 class="fw-bold mb-0 text-white">Garden of Remembrance</h5>
                    <small class="text-white-50">Memorial Park Billing & Payment Portal</small>
                </div>
            </div>
            <div>
                <?php if ($is_admin): ?>
                    <a href="dashboard.php" class="btn btn-sm btn-outline-light"><i class="fas fa-arrow-left me-1"></i> Admin Dashboard</a>
                <?php else: ?>
                    <a href="user_dashboard.php" class="btn btn-sm btn-outline-light"><i class="fas fa-arrow-left me-1"></i> My Dashboard</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="container my-4 py-2">
        <div class="row g-4">

            <div class="col-lg-7">
                <div class="portal-card">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 pb-3 border-bottom">
                        <div>
                            <span class="text-muted small text-uppercase fw-semibold">Contract Details</span>
                            <h4 class="fw-bold mb-0 text-dark">Booking #<?php echo $contract_id; ?></h4>
                        </div>
                        <span class="badge <?php echo $is_installment ? 'bg-warning text-dark' : 'bg-primary'; ?> px-3 py-2 fs-6">
                            <?php echo $is_installment ? ($term_months . '-Month Installment Plan') : 'Full Payment'; ?>
                        </span>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <span class="text-muted small">Client / Buyer Name:</span>
                            <div class="fw-bold fs-6"><?php echo htmlspecialchars($booking['customer_name'] ?: $booking['full_name'] ?: 'Customer'); ?></div>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small">Plot Location:</span>
                            <div class="fw-bold fs-6">Section <?php echo htmlspecialchars($booking['section'] ?? 'A'); ?> &bull; Plot <?php echo htmlspecialchars($booking['plot'] ?? 'Auto'); ?></div>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small">Email Address:</span>
                            <div class="fw-semibold text-secondary"><?php echo htmlspecialchars($booking['customer_email'] ?: $booking['email'] ?: '—'); ?></div>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small">Contract Booking Date:</span>
                            <div class="fw-semibold text-secondary"><?php echo date('F j, Y', strtotime($booking['created_at'] ?? 'now')); ?></div>
                        </div>
                    </div>

                    <div class="row g-2 mb-4">
                        <div class="col-4">
                            <div class="balance-stat stat-total">
                                <div class="text-muted small text-uppercase">Total Plan</div>
                                <div class="fw-bold fs-5 text-dark">₱<?php echo number_format($balance_summary['total_amount'], 2); ?></div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="balance-stat stat-paid">
                                <div class="text-success small text-uppercase fw-semibold">Total Paid</div>
                                <div class="fw-bold fs-5 text-success">₱<?php echo number_format($balance_summary['total_paid'], 2); ?></div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="balance-stat stat-balance">
                                <div class="text-danger small text-uppercase fw-semibold">Remaining Balance</div>
                                <div class="fw-bold fs-5 text-danger">₱<?php echo number_format($balance_summary['remaining_balance'], 2); ?></div>
                            </div>
                        </div>
                    </div>

                    <?php if ($target_schedule && strtolower($target_schedule['status']) !== 'paid'): ?>
                        <div class="p-3 rounded border border-danger mb-3" style="background: #fff8f8;">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div>
                                    <span class="badge bg-danger mb-1">Due For Payment</span>
                                    <h5 class="fw-bold text-dark mb-0">Installment #<?php echo (int)$target_schedule['installment_number']; ?></h5>
                                    <small class="text-muted">
                                        <i class="fas fa-calendar-alt me-1"></i> Due Date: <strong><?php echo date('F j, Y', strtotime($target_schedule['due_date'])); ?></strong>
                                    </small>
                                </div>
                                <div class="text-end">
                                    <span class="text-muted small d-block">Amount Due Today</span>
                                    <h3 class="fw-bold text-danger mb-0">₱<?php echo number_format((float)$target_schedule['monthly_amount'], 2); ?></h3>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($balance_summary['remaining_balance'] <= 0): ?>
                        <div class="alert alert-success d-flex align-items-center gap-3">
                            <i class="fas fa-check-circle fs-2"></i>
                            <div>
                                <h6 class="fw-bold mb-1">Congratulations! All Installments Settled</h6>
                                <p class="mb-0 small">This contract is fully paid with zero remaining balance.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($is_installment && !empty($schedules)): ?>
                <div class="portal-card">
                    <h6 class="fw-bold text-dark mb-3"><i class="fas fa-calendar-check text-danger me-1"></i> Monthly Payment Schedule</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.88rem;">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width: 45px;">#</th>
                                    <th>Due Date</th>
                                    <th class="text-end">Amount Due</th>
                                    <th class="text-center" style="width: 100px;">Status</th>
                                    <th class="text-center" style="width: 100px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schedules as $sch): ?>
                                    <?php 
                                        $st = strtolower(trim($sch['status']));
                                        $isCur = $target_schedule && ((int)$target_schedule['id'] === (int)$sch['id']);
                                    ?>
                                    <tr class="<?php echo $isCur ? 'table-warning' : ''; ?>">
                                        <td class="text-center fw-bold text-muted"><?php echo (int)$sch['installment_number']; ?></td>
                                        <td><?php echo date('M j, Y', strtotime($sch['due_date'])); ?></td>
                                        <td class="text-end fw-semibold">₱<?php echo number_format((float)$sch['monthly_amount'], 2); ?></td>
                                        <td class="text-center">
                                            <?php if ($st === 'paid'): ?>
                                                <span class="badge badge-paid px-2 py-1">Paid</span>
                                            <?php elseif ($st === 'overdue'): ?>
                                                <span class="badge badge-overdue px-2 py-1">Overdue</span>
                                            <?php else: ?>
                                                <span class="badge badge-pending px-2 py-1">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($st === 'paid'): ?>
                                                <span class="text-muted small"><i class="fas fa-check text-success"></i></span>
                                            <?php else: ?>
                                                <a href="payment.php?booking_id=<?php echo $contract_id; ?>&schedule_id=<?php echo (int)$sch['id']; ?>" class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size: 0.75rem;">
                                                    Select
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-5">
                <div class="portal-card">
                    <h5 class="fw-bold mb-3 text-dark d-flex align-items-center gap-2">
                        <i class="fas fa-wallet text-danger"></i> Checkout Options
                    </h5>

                    <div class="p-3 rounded bg-light border mb-4">
                        <div class="d-flex justify-content-between text-muted small mb-1">
                            <span>Item:</span>
                            <span><?php echo $target_schedule ? ('Installment #' . $target_schedule['installment_number']) : 'Booking Reservation'; ?></span>
                        </div>
                        <div class="d-flex justify-content-between text-muted small mb-2">
                            <span>Contract:</span>
                            <span>#<?php echo $contract_id; ?></span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-dark">Total Due Now:</span>
                            <span class="fw-bold fs-4 text-danger">₱<?php echo number_format($amount_to_pay, 2); ?></span>
                        </div>
                    </div>

                    <?php if ($target_schedule && strtolower($target_schedule['status']) !== 'paid'): ?>

                        <div class="mb-4">
                            <h6 class="fw-bold text-dark mb-2">Method 1: Pay via GCash</h6>
                            <p class="text-muted small mb-3">Pay instantly through GCash online gateway. Your installment status and remaining balance will update immediately.</p>
                            <a href="gcash_gateway.php?booking_id=<?php echo $contract_id; ?>&amount=<?php echo number_format($amount_to_pay, 2, '.', ''); ?>&installment=<?php echo (int)($target_schedule['installment_number'] ?? 1); ?>&schedule_id=<?php echo (int)($target_schedule['id'] ?? 0); ?>&source=user" class="btn btn-pay-now w-100 py-3 d-flex align-items-center justify-content-center gap-2 shadow-sm">
                                <img src="assets/gcash-logo.png" alt="GCash" style="height: 22px; width: auto; object-fit: contain; background: #fff; padding: 2px 6px; border-radius: 5px; flex-shrink: 0;"> Pay ₱<?php echo number_format($amount_to_pay, 2); ?> via GCash
                            </a>
                        </div>

                        <hr>

                        <div class="mt-4">
                            <h6 class="fw-bold text-dark mb-2">Method 2: Cash on Site</h6>
                            <p class="text-muted small mb-2">You can also visit our Memorial Park administration office to pay directly in cash:</p>
                            <div class="p-3 rounded bg-light border small text-secondary">
                                <div><strong>Office Address:</strong> Garden of Remembrance Memorial Park, De Guzman Street, Barangay Palamis, Alaminos City, Pangasinan 2404, Philippines</div>
                                <div><strong>Office Hours:</strong> Monday – Friday: 8:00 AM – 5:00 PM | Saturday: 8:00 AM – 3:00 PM (Sunday: CLOSED)</div>
                                <div><strong>Reference:</strong> Please mention Booking #<?php echo $contract_id; ?> upon payment.</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-double text-success fs-1 mb-2"></i>
                            <h6 class="fw-bold text-dark">No Pending Dues</h6>
                            <p class="text-muted small">All payments for this selection have been recorded.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

    <footer class="text-center py-4 text-muted small border-top bg-white mt-5">
        &copy; <?php echo date('Y'); ?> Garden of Remembrance Memorial Park. All rights reserved.
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    document.addEventListener('click', function(e) {
        var link = e.target.closest('a[href*="gcash_gateway.php"]');
        if (!link) return;
        if (typeof Swal === 'undefined') return;
        e.preventDefault();
        var href = link.href;

        Swal.fire({
            title: 'Redirecting to GCash...',
            html: '<div class="py-3 text-center">' +
                    '<div class="mb-3">' +
                      '<div class="spinner-border text-primary" style="width: 3.5rem; height: 3.5rem; border-width: 4px; color: #007dfe !important;" role="status"></div>' +
                    '</div>' +
                    '<h5 class="fw-bold text-dark mb-1">GCash Payment Gateway</h5>' +
                    '<p class="text-muted mb-3" style="font-size: 0.92rem;">Redirecting you to the secure GCash checkout page...</p>' +
                    '<div class="progress" style="height: 6px; border-radius: 4px; overflow: hidden; background-color: #e9ecef;">' +
                      '<div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%; background-color: #007dfe;"></div>' +
                    '</div>' +
                  '</div>',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            timer: 1600
        });
        setTimeout(function() {
            window.location.href = href;
        }, 1200);
    });
    </script>
</body>
</html>

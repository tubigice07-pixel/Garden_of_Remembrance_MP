<?php
$file = "c:/xampp/htdocs/Garden_of_Remembrance_MP/sales_reports.php";
$content = file_get_contents($file);

// Normalize line endings to LF for clean matching
$isCrlf = (strpos($content, "\r\n") !== false);
$content = str_replace("\r\n", "\n", $content);

// 1. Update Summary Cards HTML
$oldSummaryCards = <<< 'OLD_CARDS'
            <div class="summary-cards">
                <div class="card-primary">
                    <div class="card-label">Overall Revenue</div>
                    <div class="card-value"><?php echo formatDisplayCurrency($total_revenue); ?></div>
                </div>
                <div class="card-primary">
                    <div class="card-label"><?php echo htmlspecialchars($sales_month_label); ?> Sales</div>
                    <div class="card-value"><?php echo formatDisplayCurrency($monthly_sales_total); ?></div>
                </div>
                <div class="card-primary">
                    <div class="card-label">Total Bookings</div>
                    <div class="card-value"><?php echo formatDisplayNumber($total_booking_count); ?></div><div style="font-size: 0.72rem; color: var(--muted); margin-top: 4px; font-weight: 600;"><?php echo $active_booking_count; ?> Active &bull; <?php echo $cancelled_booking_count; ?> Cancelled/Logs</div>
                </div>
                <div class="card-primary">
                    <div class="card-label">Paid Bookings</div>
                    <div class="card-value"><?php echo formatDisplayNumber($paid_booking_count); ?></div>
                </div>
                <div class="card-primary">
                    <div class="card-label">Pending Payments</div>
                    <div class="card-value"><?php echo formatDisplayNumber($pending_payment_count); ?></div>
                </div>
            </div>
OLD_CARDS;

$newSummaryCards = <<< 'NEW_CARDS'
            <?php
            $count_all = count($monthly_transactions);
            $count_paid = count(array_filter($monthly_transactions, fn($t) => ($t['filter_type'] ?? '') === 'paid'));
            $count_pending = count(array_filter($monthly_transactions, fn($t) => ($t['filter_type'] ?? '') === 'pending'));
            $count_cancelled = count(array_filter($monthly_transactions, fn($t) => ($t['filter_type'] ?? '') === 'cancelled'));
            $count_active = $count_paid + $count_pending;
            ?>
            <div class="summary-cards">
                <div class="card-primary">
                    <div class="card-label">Overall Revenue</div>
                    <div class="card-value"><?php echo formatDisplayCurrency($total_revenue); ?></div>
                </div>
                <div class="card-primary">
                    <div class="card-label"><?php echo htmlspecialchars($sales_month_label); ?> Sales</div>
                    <div class="card-value"><?php echo formatDisplayCurrency($monthly_sales_total); ?></div>
                </div>
                <div class="card-primary card-filterable active" id="cardTotalBookings" data-card-filter="all" onclick="filterTransactions('all')" title="Click to view all bookings in table below">
                    <div class="card-label d-flex justify-content-between align-items-center">
                        <span>Total Bookings</span>
                        <i class="fas fa-list small" style="font-size: 0.68rem; color: var(--brand);"></i>
                    </div>
                    <div class="card-value"><?php echo formatDisplayNumber($total_booking_count); ?></div>
                    <div style="font-size: 0.72rem; color: var(--muted); margin-top: 4px; font-weight: 600;">
                        <?php echo $active_booking_count; ?> Active &bull; <?php echo $cancelled_booking_count; ?> Cancelled
                    </div>
                    <div class="card-filter-hint"><i class="fas fa-hand-pointer"></i> View all <?php echo $total_booking_count; ?> total</div>
                </div>
                <div class="card-primary card-filterable" id="cardPaidBookings" data-card-filter="paid" onclick="filterTransactions('paid')" title="Click to view paid bookings only">
                    <div class="card-label d-flex justify-content-between align-items-center">
                        <span>Paid Bookings</span>
                        <i class="fas fa-check-circle text-success small" style="font-size: 0.68rem;"></i>
                    </div>
                    <div class="card-value"><?php echo formatDisplayNumber($paid_booking_count); ?></div>
                    <div class="card-filter-hint text-success"><i class="fas fa-hand-pointer"></i> View <?php echo $paid_booking_count; ?> paid only</div>
                </div>
                <div class="card-primary card-filterable" id="cardPendingPayments" data-card-filter="pending" onclick="filterTransactions('pending')" title="Click to view pending payments only">
                    <div class="card-label d-flex justify-content-between align-items-center">
                        <span>Pending Payments</span>
                        <i class="fas fa-clock text-warning small" style="font-size: 0.68rem;"></i>
                    </div>
                    <div class="card-value"><?php echo formatDisplayNumber($pending_payment_count); ?></div>
                    <div class="card-filter-hint text-warning"><i class="fas fa-hand-pointer"></i> View <?php echo $pending_payment_count; ?> pending only</div>
                </div>
            </div>
NEW_CARDS;

$content = str_replace($oldSummaryCards, $newSummaryCards, $content);

// 2. Update Transactions Table Header & Body
$oldTableSection = <<< 'OLD_TABLE'
            <div class="transactions-section">
                <div class="section-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <h3 class="mb-0"><?php echo htmlspecialchars($sales_month_label); ?> Transactions</h3>
                        <span class="entry-badge"><?php echo count($monthly_transactions); ?> entries</span>
                    </div>
                    <button type="button" class="btn-print-invoice-sm" onclick="printMonthlyInvoice()" title="Print Monthly Sales Invoice Statement">
                        <i class="fas fa-print me-1"></i> Print Monthly Invoice
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="transactions-table mobile-card-table">
                        <thead>
                            <tr>
                                <th>Booking</th>
                                <th>Customer</th>
                                <th>Section / Plot</th>
                                <th>Total Price</th>
                                <th>Amount Paid</th>
                                <th>Balance</th>
                                <th>Payment Status</th>
                                <th>Date</th>
                                <th class="text-center">Proof</th>
                                <th class="text-center" style="width: 110px;">Invoice</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($monthly_transactions)): ?>
                                <?php foreach ($monthly_transactions as $transaction): ?>
                                    <?php
                                        $booking_id = (int)($transaction['booking_id'] ?? 0);
                                        $customer_name = $transaction['customer_name'] ?? 'Unknown';
                                        $section = $transaction['section'] ?? '—';
                                        $plot = format_plot_number($transaction['plot'] ?? '—', $section);
                                        $total_price = (float)($transaction['total_amount'] ?? $transaction['plot_price'] ?? 0);
                                        $amount_paid = (float)($transaction['amount_paid'] ?? 0);
                                        $balance = max(0, $total_price - $amount_paid);
                                        $payment_date = $transaction['payment_date'] ?? date('Y-m-d H:i:s');
                                        $proof_filename = $transaction['proof_filename'] ?? null;
                                        
                                        
                                        $status = strtoupper($transaction['payment_status'] ?? $transaction['status'] ?? 'PENDING');
                                        if ($amount_paid >= $total_price && $total_price > 0) {
                                            $status = 'PAID';
                                        } elseif ($amount_paid > 0 && $amount_paid < $total_price) {
                                            $status = 'PARTIAL';
                                        } elseif ($amount_paid <= 0) {
                                            $status = 'UNPAID';
                                        }
                                        
                                        $status_class = 'status-' . strtolower(str_replace(' ', '_', $status));
                                    ?>
                                    <tr onclick="openTransactionDetails(<?php echo $booking_id; ?>)">
                                        <td data-label="Booking ID"><span class="booking-id">#<?php echo $booking_id; ?></span></td>
                                        <td data-label="Customer"><?php echo htmlspecialchars($customer_name); ?></td>
                                        <td data-label="Section / Plot"><?php echo htmlspecialchars($section); ?><?php echo ($plot !== '—') ? ' / ' . htmlspecialchars($plot) : ''; ?></td>
                                        <td data-label="Total Price"><?php echo formatDisplayCurrency($total_price); ?></td>
                                        <td data-label="Amount Paid"><?php echo formatDisplayCurrency($amount_paid); ?></td>
                                        <td data-label="Balance"><?php echo formatDisplayCurrency($balance); ?></td>
                                        <td data-label="Payment Status"><span class="status-badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                                        <td data-label="Date"><?php echo htmlspecialchars(date('M d, Y', strtotime($payment_date))); ?></td>
                                        <td data-label="Proof" class="text-center" onclick="event.stopPropagation()">
                                            <?php if (!empty($proof_filename)): ?>
                                                <button type="button" class="btn btn-sm" onclick="adminViewPaymentProof(<?php echo $booking_id; ?>, '<?php echo htmlspecialchars(addslashes($proof_filename), ENT_QUOTES); ?>')" style="background: rgba(123, 17, 19, 0.1); color: #7b1113; border: 1px solid rgba(123, 17, 19, 0.25); border-radius: 6px; font-size: 0.76rem; font-weight: 600; padding: 4px 10px; white-space: nowrap;">
                                                    <i class="fas fa-image me-1"></i> View Proof
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Invoice" class="text-center" onclick="event.stopPropagation()">
                                            <button type="button" class="btn-invoice-row" onclick="printTransactionInvoice(<?php echo $booking_id; ?>)" title="Print Official Invoice for Booking #<?php echo $booking_id; ?>">
                                                <i class="fas fa-file-invoice me-1"></i> Invoice
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="empty-state">
                                            <div class="empty-state-icon">📊</div>
                                            <div>No sales recorded for <?php echo htmlspecialchars($sales_month_label); ?> yet.</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
OLD_TABLE;

$newTableSection = <<< 'NEW_TABLE'
            <div class="transactions-section">
                <div class="section-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <h3 class="mb-0" id="transactionSectionTitle"><?php echo htmlspecialchars($sales_month_label); ?> All Bookings</h3>
                        <span class="entry-badge" id="transactionEntryBadge"><?php echo count($monthly_transactions); ?> entries</span>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="transaction-filter-tabs d-flex align-items-center gap-1 flex-wrap">
                            <button type="button" class="txn-tab-btn active" data-filter="all" onclick="filterTransactions('all')" title="Show all bookings">
                                <i class="fas fa-list me-1"></i> Total <span class="badge bg-secondary ms-1"><?php echo $count_all; ?></span>
                            </button>
                            <button type="button" class="txn-tab-btn" data-filter="active" onclick="filterTransactions('active')" title="Show active bookings (paid and pending)">
                                <i class="fas fa-shield-alt me-1 text-primary"></i> Active <span class="badge bg-primary ms-1"><?php echo $count_active; ?></span>
                            </button>
                            <button type="button" class="txn-tab-btn" data-filter="paid" onclick="filterTransactions('paid')" title="Show paid bookings only">
                                <i class="fas fa-check-circle me-1 text-success"></i> Paid <span class="badge bg-success ms-1"><?php echo $count_paid; ?></span>
                            </button>
                            <button type="button" class="txn-tab-btn" data-filter="pending" onclick="filterTransactions('pending')" title="Show pending payment bookings only">
                                <i class="fas fa-clock me-1 text-warning"></i> Pending <span class="badge bg-warning text-dark ms-1"><?php echo $count_pending; ?></span>
                            </button>
                            <?php if ($count_cancelled > 0): ?>
                            <button type="button" class="txn-tab-btn" data-filter="cancelled" onclick="filterTransactions('cancelled')" title="Show cancelled bookings only">
                                <i class="fas fa-times-circle me-1 text-danger"></i> Cancelled <span class="badge bg-danger ms-1"><?php echo $count_cancelled; ?></span>
                            </button>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn-print-invoice-sm ms-md-2" onclick="printMonthlyInvoice()" title="Print Monthly Sales Invoice Statement">
                            <i class="fas fa-print me-1"></i> Print Invoice
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="transactions-table mobile-card-table">
                        <thead>
                            <tr>
                                <th>Booking</th>
                                <th>Customer</th>
                                <th>Section / Plot</th>
                                <th>Booking Status</th>
                                <th>Total Price</th>
                                <th>Amount Paid</th>
                                <th>Balance</th>
                                <th>Payment Status</th>
                                <th>Date</th>
                                <th class="text-center">Proof</th>
                                <th class="text-center" style="width: 110px;">Invoice</th>
                            </tr>
                        </thead>
                        <tbody id="transactionsTableBody">
                            <?php if (!empty($monthly_transactions)): ?>
                                <?php foreach ($monthly_transactions as $transaction): ?>
                                    <?php
                                        $booking_id = (int)($transaction['booking_id'] ?? 0);
                                        $customer_name = $transaction['customer_name'] ?? 'Unknown';
                                        $section = $transaction['section'] ?? '—';
                                        $plot = format_plot_number($transaction['plot'] ?? '—', $section);
                                        $total_price = (float)($transaction['total_amount'] ?? $transaction['plot_price'] ?? 0);
                                        $amount_paid = (float)($transaction['amount_paid'] ?? 0);
                                        $balance = max(0, $total_price - $amount_paid);
                                        $payment_date = $transaction['payment_date'] ?? date('Y-m-d H:i:s');
                                        $proof_filename = $transaction['proof_filename'] ?? null;
                                        $filter_type = $transaction['filter_type'] ?? 'pending';
                                        $b_status_raw = strtolower(trim((string)($transaction['status'] ?? 'pending')));
                                        
                                        $status = strtoupper($transaction['payment_status'] ?? 'PENDING');
                                        $status_class = 'status-' . strtolower(str_replace(' ', '_', $status));

                                        $b_badge_class = 'bg-secondary';
                                        if ($b_status_raw === 'approved') $b_badge_class = 'bg-success';
                                        elseif ($b_status_raw === 'completed') $b_badge_class = 'bg-info text-dark';
                                        elseif ($b_status_raw === 'pending') $b_badge_class = 'bg-warning text-dark';
                                        elseif (in_array($b_status_raw, ['cancelled', 'rejected'])) $b_badge_class = 'bg-danger';
                                    ?>
                                    <tr class="transaction-row" data-filter-type="<?php echo htmlspecialchars($filter_type); ?>" data-booking-id="<?php echo $booking_id; ?>" onclick="openTransactionDetails(<?php echo $booking_id; ?>)">
                                        <td data-label="Booking ID"><span class="booking-id">#<?php echo $booking_id; ?></span></td>
                                        <td data-label="Customer"><?php echo htmlspecialchars($customer_name); ?></td>
                                        <td data-label="Section / Plot"><?php echo htmlspecialchars($section); ?><?php echo ($plot !== '—') ? ' / ' . htmlspecialchars($plot) : ''; ?></td>
                                        <td data-label="Booking Status">
                                            <span class="badge <?php echo $b_badge_class; ?>" style="font-size: 0.74rem; text-transform: uppercase; font-weight: 600;">
                                                <?php echo htmlspecialchars($transaction['status'] ?? 'pending'); ?>
                                            </span>
                                        </td>
                                        <td data-label="Total Price"><?php echo formatDisplayCurrency($total_price); ?></td>
                                        <td data-label="Amount Paid"><?php echo formatDisplayCurrency($amount_paid); ?></td>
                                        <td data-label="Balance"><?php echo formatDisplayCurrency($balance); ?></td>
                                        <td data-label="Payment Status">
                                            <?php if ($status === 'PAID'): ?>
                                                <span class="status-badge status-paid"><i class="fas fa-check-circle me-1"></i>PAID</span>
                                            <?php elseif ($status === 'PARTIAL'): ?>
                                                <span class="status-badge status-partial"><i class="fas fa-adjust me-1"></i>PARTIAL</span>
                                            <?php elseif ($status === 'CANCELLED'): ?>
                                                <span class="status-badge status-cancelled" style="background: rgba(220, 38, 38, 0.12); color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.3);"><i class="fas fa-ban me-1"></i>CANCELLED</span>
                                            <?php else: ?>
                                                <span class="status-badge status-unpaid" style="background: rgba(217, 119, 6, 0.12); color: #b45309; border: 1px solid rgba(217, 119, 6, 0.3);"><i class="fas fa-clock me-1"></i>PENDING</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Date"><?php echo htmlspecialchars(date('M d, Y', strtotime($payment_date))); ?></td>
                                        <td data-label="Proof" class="text-center" onclick="event.stopPropagation()">
                                            <?php if (!empty($proof_filename)): ?>
                                                <button type="button" class="btn btn-sm" onclick="adminViewPaymentProof(<?php echo $booking_id; ?>, '<?php echo htmlspecialchars(addslashes($proof_filename), ENT_QUOTES); ?>')" style="background: rgba(123, 17, 19, 0.1); color: #7b1113; border: 1px solid rgba(123, 17, 19, 0.25); border-radius: 6px; font-size: 0.76rem; font-weight: 600; padding: 4px 10px; white-space: nowrap;">
                                                    <i class="fas fa-image me-1"></i> View Proof
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Invoice" class="text-center" onclick="event.stopPropagation()">
                                            <button type="button" class="btn-invoice-row" onclick="printTransactionInvoice(<?php echo $booking_id; ?>)" title="Print Official Invoice for Booking #<?php echo $booking_id; ?>">
                                                <i class="fas fa-file-invoice me-1"></i> Invoice
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr id="filterEmptyRow" style="display: none;">
                                    <td colspan="11">
                                        <div class="empty-state py-4 text-center">
                                            <div class="empty-state-icon fs-3">🔍</div>
                                            <div class="text-muted mt-2 fw-semibold">No bookings found for the selected filter.</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11">
                                        <div class="empty-state">
                                            <div class="empty-state-icon">📊</div>
                                            <div>No bookings recorded for <?php echo htmlspecialchars($sales_month_label); ?> yet.</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
NEW_TABLE;

$content = str_replace($oldTableSection, $newTableSection, $content);

// 3. Add filterTransactions JavaScript function
$jsTarget = "        function adminViewPaymentProof(bookingId, filename) {";
$newJs = <<< 'NEW_JS'
        function filterTransactions(filterType) {
            // 1. Update active tab buttons
            document.querySelectorAll('.txn-tab-btn').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-filter') === filterType);
            });

            // 2. Update active summary card styling
            document.querySelectorAll('.card-filterable').forEach(card => {
                card.classList.toggle('active', card.getAttribute('data-card-filter') === filterType);
            });

            // 3. Filter table rows
            const rows = document.querySelectorAll('.transactions-table tbody tr.transaction-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const rowType = row.getAttribute('data-filter-type');
                let show = false;

                if (filterType === 'all') {
                    show = true;
                } else if (filterType === 'active') {
                    show = (rowType === 'paid' || rowType === 'pending');
                } else if (filterType === 'paid') {
                    show = (rowType === 'paid');
                } else if (filterType === 'pending') {
                    show = (rowType === 'pending');
                } else if (filterType === 'cancelled') {
                    show = (rowType === 'cancelled');
                }

                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            // 4. Update entry count badge and header title
            const entryBadge = document.getElementById('transactionEntryBadge');
            if (entryBadge) {
                entryBadge.textContent = visibleCount + (visibleCount === 1 ? ' entry' : ' entries');
            }

            const titleEl = document.getElementById('transactionSectionTitle');
            if (titleEl) {
                let label = 'All Bookings';
                if (filterType === 'active') label = 'Active Bookings';
                else if (filterType === 'paid') label = 'Paid Bookings';
                else if (filterType === 'pending') label = 'Pending Payments';
                else if (filterType === 'cancelled') label = 'Cancelled Bookings';
                titleEl.textContent = '<?php echo htmlspecialchars($sales_month_label); ?> ' + label;
            }

            // 5. Toggle empty row
            const emptyRow = document.getElementById('filterEmptyRow');
            if (emptyRow) {
                emptyRow.style.display = (visibleCount === 0) ? '' : 'none';
            }

            // Smooth scroll to table if triggered from summary cards at top
            const section = document.querySelector('.transactions-section');
            if (section && window.scrollY < section.offsetTop - 150) {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function adminViewPaymentProof(bookingId, filename) {
NEW_JS;

$content = str_replace($jsTarget, $newJs, $content);

if ($isCrlf) {
    $content = str_replace("\n", "\r\n", $content);
}

file_put_contents($file, $content);
echo "Successfully updated sales_reports.php!\n";

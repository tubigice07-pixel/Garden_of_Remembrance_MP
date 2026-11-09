<?php
require_once __DIR__ . '/connection.php';
$r = $conn->query("SELECT id, full_name, plot, section, plot_price, total_amount, downpayment, remaining_balance, service_price, interment_fee, payment_plan, payment_term FROM tbl_bookings ORDER BY id DESC LIMIT 5");
while($row = $r->fetch_assoc()) {
    print_r($row);
}

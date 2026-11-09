<?php
require 'connection.php';
$u = $conn->query("SELECT user_id, first_name, last_name, email FROM tbl_user WHERE first_name LIKE '%carlito%' OR last_name LIKE '%carlito%'");
while($r = $u->fetch_assoc()) { echo "USER: " . json_encode($r) . "\n"; }

$b = $conn->query("SELECT id, user_id, customer_name, full_name, section, plot, payment_type, booking_type FROM tbl_bookings WHERE customer_name LIKE '%carlito%' OR full_name LIKE '%carlito%'");
while($r = $b->fetch_assoc()) { echo "BOOKING: " . json_encode($r) . "\n"; }

$ps = $conn->query("SELECT ps.id, ps.contract_id, ps.user_id, ps.installment_number, ps.monthly_amount, ps.due_date, ps.status, COALESCE(b.customer_name, b.full_name) as name FROM payment_schedules ps LEFT JOIN tbl_bookings b ON b.id = ps.contract_id WHERE b.customer_name LIKE '%carlito%' OR b.full_name LIKE '%carlito%' OR ps.user_name LIKE '%carlito%'");
while($r = $ps->fetch_assoc()) { echo "SCHED: " . json_encode($r) . "\n"; }

$pr = $conn->query("SELECT pr.id, pr.booking_id, pr.installment_number, pr.amount_paid, pr.payment_date, pr.payment_status, COALESCE(b.customer_name, b.full_name) as name FROM payment_receipts pr LEFT JOIN tbl_bookings b ON b.id = pr.booking_id WHERE b.customer_name LIKE '%carlito%' OR b.full_name LIKE '%carlito%'");
while($r = $pr->fetch_assoc()) { echo "RECEIPT: " . json_encode($r) . "\n"; }

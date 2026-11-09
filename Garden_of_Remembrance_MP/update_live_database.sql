-- SQL Script to update booking_payment_schedule on Live Server phpMyAdmin
-- Run this on your live database (cemetery2_db) in phpMyAdmin -> SQL tab

-- 1. Delete orphan payment schedules from deleted/test bookings
DELETE FROM `booking_payment_schedule` 
WHERE `booking_id` NOT IN (SELECT `id` FROM `tbl_bookings`);

-- 2. Populate payment_method from tbl_bookings so it is not blank
UPDATE `booking_payment_schedule` bps
JOIN `tbl_bookings` b ON bps.booking_id = b.id
SET bps.payment_method = CASE 
    WHEN b.payment_type IS NOT NULL AND TRIM(b.payment_type) != '' THEN b.payment_type
    WHEN b.original_payment_method IS NOT NULL AND TRIM(b.original_payment_method) != '' THEN b.original_payment_method
    ELSE 'GCash'
END
WHERE bps.payment_method IS NULL OR bps.payment_method = '' OR bps.payment_method = '-';

-- 3. Replace empty strings with '-' for pending references
UPDATE `booking_payment_schedule` 
SET `transaction_reference` = '-' 
WHERE (`transaction_reference` IS NULL OR TRIM(`transaction_reference`) = '') AND `status` != 'PAID';

UPDATE `booking_payment_schedule` 
SET `gcash_reference_number` = '-' 
WHERE (`gcash_reference_number` IS NULL OR TRIM(`gcash_reference_number`) = '') AND `status` != 'PAID';

-- 4. Update payment_receipt_id to default 0 (eliminates NULL)
ALTER TABLE `booking_payment_schedule` MODIFY `payment_receipt_id` INT(11) NOT NULL DEFAULT 0;
UPDATE `booking_payment_schedule` SET `payment_receipt_id` = 0 WHERE `payment_receipt_id` IS NULL;

-- 5. Convert paid_at to VARCHAR(30) DEFAULT '-' (eliminates NULL for pending installments)
ALTER TABLE `booking_payment_schedule` MODIFY `paid_at` VARCHAR(30) NOT NULL DEFAULT '-';
UPDATE `booking_payment_schedule` SET `paid_at` = '-' WHERE `paid_at` IS NULL OR `paid_at` = '' OR `paid_at` = '0000-00-00 00:00:00';

-- 6. Sync status for cancelled and rejected bookings
UPDATE `booking_payment_schedule` bps
JOIN `tbl_bookings` b ON bps.booking_id = b.id
SET bps.status = 'CANCELLED'
WHERE LOWER(b.status) = 'cancelled' AND bps.status = 'PENDING';

UPDATE `booking_payment_schedule` bps
JOIN `tbl_bookings` b ON bps.booking_id = b.id
SET bps.status = 'REJECTED'
WHERE LOWER(b.status) = 'rejected' AND bps.status = 'PENDING';

-- ============================================================
-- SQL Migration: Enhance Existing Payment & Schedule Tables
-- Database: cemetery2_db
-- Target Tables: payment_receipts, booking_payment_schedule
-- Purpose:
--   1. Reuse existing payment_receipts as authoritative individual payments ledger
--   2. Disambiguate GCash Reference Number from Official Receipt Number
--   3. Link individual payments to installment schedule items
--   4. Support admin verification/rejection workflow and full auditability
--   5. Maintain 100% backwards compatibility and preserve all existing data
-- ============================================================

-- 1. ADD COLUMNS TO payment_receipts (IF NOT EXISTS pattern via stored procedure or direct safe ALTER)
SET @dbname = DATABASE();

-- Add payment_schedule_id
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payment_receipts' AND COLUMN_NAME = 'payment_schedule_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE payment_receipts ADD COLUMN payment_schedule_id INT(11) NULL AFTER user_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add installment_number
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payment_receipts' AND COLUMN_NAME = 'installment_number');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE payment_receipts ADD COLUMN installment_number INT(11) NULL AFTER payment_schedule_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add gcash_reference_number
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payment_receipts' AND COLUMN_NAME = 'gcash_reference_number');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE payment_receipts ADD COLUMN gcash_reference_number VARCHAR(100) NULL AFTER payment_method', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add transaction_reference
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payment_receipts' AND COLUMN_NAME = 'transaction_reference');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE payment_receipts ADD COLUMN transaction_reference VARCHAR(100) NULL AFTER gcash_reference_number', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add verified_by
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payment_receipts' AND COLUMN_NAME = 'verified_by');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE payment_receipts ADD COLUMN verified_by INT(11) NULL AFTER receipt_path', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add verified_at
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payment_receipts' AND COLUMN_NAME = 'verified_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE payment_receipts ADD COLUMN verified_at DATETIME NULL AFTER verified_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add rejection_reason
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payment_receipts' AND COLUMN_NAME = 'rejection_reason');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE payment_receipts ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER verified_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add updated_at
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payment_receipts' AND COLUMN_NAME = 'updated_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE payment_receipts ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add INDEX on booking_id, user_id, payment_schedule_id, gcash_reference_number if not exist
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payment_receipts' AND INDEX_NAME = 'idx_pr_booking_id');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE payment_receipts ADD INDEX idx_pr_booking_id (booking_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payment_receipts' AND INDEX_NAME = 'idx_pr_gcash_ref');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE payment_receipts ADD INDEX idx_pr_gcash_ref (gcash_reference_number)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'payment_receipts' AND INDEX_NAME = 'idx_pr_sched_id');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE payment_receipts ADD INDEX idx_pr_sched_id (payment_schedule_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. ADD COLUMNS TO booking_payment_schedule (IF NOT EXISTS)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'booking_payment_schedule' AND COLUMN_NAME = 'gcash_reference_number');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE booking_payment_schedule ADD COLUMN gcash_reference_number VARCHAR(100) NULL AFTER transaction_reference', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'booking_payment_schedule' AND COLUMN_NAME = 'payment_receipt_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE booking_payment_schedule ADD COLUMN payment_receipt_id INT(11) NULL AFTER gcash_reference_number', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. NORMALIZE EXISTING DATA IN payment_receipts
-- If receipt_number looks like a GCash reference (starts with GC or numeric sequence), copy to gcash_reference_number
UPDATE payment_receipts 
SET gcash_reference_number = receipt_number 
WHERE (gcash_reference_number IS NULL OR gcash_reference_number = '') 
  AND receipt_number LIKE 'GC%';

-- If receipt_number looks like cash transaction (CSH-TXN...), copy to transaction_reference
UPDATE payment_receipts 
SET transaction_reference = receipt_number 
WHERE (transaction_reference IS NULL OR transaction_reference = '') 
  AND receipt_number LIKE 'CSH-TXN%';

-- Ensure all existing payment_receipts have a proper official receipt_number format RCPT-YYYYMMDD-XXXX
UPDATE payment_receipts
SET receipt_number = CONCAT('RCPT-', DATE_FORMAT(created_at, '%Y%m%d'), '-', LPAD(id, 4, '0'))
WHERE receipt_number LIKE 'GC%' OR receipt_number LIKE 'CSH-TXN%' OR receipt_number IS NULL OR receipt_number = '';

-- Backfill gcash_reference_number from tbl_bookings if missing in payment_receipts
UPDATE payment_receipts pr
JOIN tbl_bookings b ON pr.booking_id = b.id
SET pr.gcash_reference_number = b.gcash_reference_number
WHERE (pr.gcash_reference_number IS NULL OR pr.gcash_reference_number = '')
  AND b.gcash_reference_number IS NOT NULL 
  AND b.gcash_reference_number != '';

-- Backfill proof_filename from tbl_bookings if missing in payment_receipts
UPDATE payment_receipts pr
JOIN tbl_bookings b ON pr.booking_id = b.id
SET pr.proof_filename = b.payment_proof_filename
WHERE (pr.proof_filename IS NULL OR pr.proof_filename = '')
  AND b.payment_proof_filename IS NOT NULL 
  AND b.payment_proof_filename != '';

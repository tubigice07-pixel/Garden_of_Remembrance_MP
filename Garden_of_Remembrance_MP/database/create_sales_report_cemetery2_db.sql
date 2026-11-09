-- =========================================================================
-- Garden of Remembrance Memorial Park
-- Database Schema: Sales & Reports for cemetery2_db
-- =========================================================================

-- Ensure database is selected (adjust database name if on live InfinityFree: if0_42806961_cemetery2_db)
-- USE `cemetery2_db`;

-- 1. Create main sales_reports table
CREATE TABLE IF NOT EXISTS `sales_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `booking_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT 'Customer',
  `customer_email` varchar(255) DEFAULT 'N/A',
  `contact_number` varchar(50) DEFAULT '—',
  `service_type` varchar(150) DEFAULT 'Reserve Grave',
  `section` varchar(50) DEFAULT '—',
  `plot` varchar(50) DEFAULT '—',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` varchar(50) NOT NULL DEFAULT 'PENDING',
  `payment_method` varchar(50) DEFAULT 'GCash',
  `reference_number` varchar(100) DEFAULT '—',
  `report_month` varchar(7) NOT NULL,
  `payment_date` datetime DEFAULT NULL,
  `booking_date` datetime DEFAULT NULL,
  `booking_status` varchar(50) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY `uk_booking_id` (`booking_id`),
  KEY `idx_report_month` (`report_month`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_booking_status` (`booking_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Create alias sales_report table (singular form compatibility)
CREATE TABLE IF NOT EXISTS `sales_report` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `booking_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT 'Customer',
  `customer_email` varchar(255) DEFAULT 'N/A',
  `contact_number` varchar(50) DEFAULT '—',
  `service_type` varchar(150) DEFAULT 'Reserve Grave',
  `section` varchar(50) DEFAULT '—',
  `plot` varchar(50) DEFAULT '—',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` varchar(50) NOT NULL DEFAULT 'PENDING',
  `payment_method` varchar(50) DEFAULT 'GCash',
  `reference_number` varchar(100) DEFAULT '—',
  `report_month` varchar(7) NOT NULL,
  `payment_date` datetime DEFAULT NULL,
  `booking_date` datetime DEFAULT NULL,
  `booking_status` varchar(50) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY `uk_sr_booking_id` (`booking_id`),
  KEY `idx_sr_report_month` (`report_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Backfill/Synchronize all existing records from tbl_bookings & payment_receipts
INSERT INTO `sales_reports` (
    `booking_id`, `user_id`, `customer_name`, `customer_email`, `contact_number`,
    `service_type`, `section`, `plot`, `total_amount`, `amount_paid`, `balance`,
    `payment_status`, `payment_method`, `reference_number`, `report_month`,
    `payment_date`, `booking_date`, `booking_status`, `created_at`
)
SELECT 
    b.id AS booking_id,
    b.user_id,
    COALESCE(NULLIF(TRIM(b.customer_name), ''), NULLIF(TRIM(b.full_name), ''), CONCAT_WS(' ', u.first_name, u.last_name), 'Customer') AS customer_name,
    COALESCE(NULLIF(TRIM(b.customer_email), ''), NULLIF(TRIM(b.email), ''), u.email, 'N/A') AS customer_email,
    COALESCE(NULLIF(TRIM(b.contact_number), ''), '—') AS contact_number,
    COALESCE(NULLIF(TRIM(b.service_type), ''), NULLIF(TRIM(b.purpose), ''), 'Reserve Grave') AS service_type,
    COALESCE(NULLIF(TRIM(b.section), ''), '—') AS section,
    COALESCE(NULLIF(TRIM(b.plot), ''), '—') AS plot,
    COALESCE(b.total_amount, b.plot_price, 0.00) AS total_amount,
    COALESCE(pr_totals.tot_paid, b.amount_paid, 0.00) AS amount_paid,
    GREATEST(0.00, COALESCE(b.total_amount, b.plot_price, 0.00) - COALESCE(pr_totals.tot_paid, b.amount_paid, 0.00)) AS balance,
    CASE 
        WHEN LOWER(TRIM(b.status)) IN ('rejected', 'cancelled') THEN UPPER(TRIM(b.status))
        WHEN COALESCE(pr_totals.tot_paid, b.amount_paid, 0.00) >= COALESCE(b.total_amount, b.plot_price, 0.00) AND COALESCE(b.total_amount, b.plot_price, 0.00) > 0 THEN 'PAID'
        WHEN COALESCE(pr_totals.tot_paid, b.amount_paid, 0.00) > 0 THEN 'PARTIAL'
        ELSE 'UNPAID'
    END AS payment_status,
    COALESCE(NULLIF(TRIM(b.payment_type), ''), 'GCash') AS payment_method,
    COALESCE(NULLIF(TRIM(b.reference_number), ''), NULLIF(TRIM(b.gcash_reference_number), ''), '—') AS reference_number,
    DATE_FORMAT(COALESCE(b.created_at, NOW()), '%Y-%m') AS report_month,
    COALESCE(pr_totals.latest_payment_date, b.created_at) AS payment_date,
    COALESCE(b.created_at, NOW()) AS booking_date,
    COALESCE(b.status, 'pending') AS booking_status,
    COALESCE(b.created_at, NOW()) AS created_at
FROM `tbl_bookings` b
LEFT JOIN `tbl_user` u ON u.user_id = b.user_id
LEFT JOIN (
    SELECT booking_id, SUM(amount_paid) AS tot_paid, MAX(payment_date) AS latest_payment_date
    FROM payment_receipts
    WHERE UPPER(COALESCE(payment_status, 'PAID')) = 'PAID'
    GROUP BY booking_id
) pr_totals ON pr_totals.booking_id = b.id
ON DUPLICATE KEY UPDATE
    `customer_name` = VALUES(`customer_name`),
    `customer_email` = VALUES(`customer_email`),
    `contact_number` = VALUES(`contact_number`),
    `service_type` = VALUES(`service_type`),
    `section` = VALUES(`section`),
    `plot` = VALUES(`plot`),
    `total_amount` = VALUES(`total_amount`),
    `amount_paid` = VALUES(`amount_paid`),
    `balance` = VALUES(`balance`),
    `payment_status` = VALUES(`payment_status`),
    `booking_status` = VALUES(`booking_status`),
    `report_month` = VALUES(`report_month`),
    `updated_at` = NOW();

-- 4. Reconcile with any bookings recorded in tbl_booking_logs
INSERT IGNORE INTO `sales_reports` (
    `booking_id`, `section`, `contact_number`, `total_amount`, `report_month`,
    `booking_date`, `created_at`, `booking_status`, `payment_status`
)
SELECT 
    bl.booking_id,
    COALESCE(NULLIF(TRIM(bl.section), ''), '—'),
    COALESCE(NULLIF(TRIM(bl.contact_number), ''), '—'),
    COALESCE(bl.price, 0.00),
    DATE_FORMAT(COALESCE(bl.timestamp, NOW()), '%Y-%m'),
    COALESCE(bl.timestamp, NOW()),
    COALESCE(bl.timestamp, NOW()),
    'logged',
    'UNPAID'
FROM `tbl_booking_logs` bl
WHERE bl.booking_id NOT IN (SELECT booking_id FROM `sales_reports`);

-- 5. Keep sales_report alias synchronized
INSERT INTO `sales_report` SELECT * FROM `sales_reports` ON DUPLICATE KEY UPDATE `updated_at` = NOW();

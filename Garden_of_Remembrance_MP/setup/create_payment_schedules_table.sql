-- SQL Schema for Installment Payment Schedules
-- Garden of Remembrance Memorial Park

CREATE TABLE IF NOT EXISTS `payment_schedules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `installment_number` INT(11) NOT NULL DEFAULT 1,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `monthly_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `remaining_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `term_months` INT(11) NOT NULL DEFAULT 12,
  `status` ENUM('pending', 'paid', 'overdue') NOT NULL DEFAULT 'pending',
  `due_date` DATE NOT NULL,
  `user_email` VARCHAR(255) NOT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `payment_method` VARCHAR(50) DEFAULT NULL,
  `transaction_reference` VARCHAR(100) DEFAULT NULL,
  `reminder_sent_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_contract` (`contract_id`),
  KEY `idx_status_due` (`status`, `due_date`),
  KEY `idx_user_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

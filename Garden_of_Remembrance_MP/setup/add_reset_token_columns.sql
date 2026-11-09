-- Add reset_token and reset_expires columns to tbl_user if not already present
ALTER TABLE `tbl_user` 
  ADD COLUMN `reset_token` VARCHAR(128) DEFAULT NULL AFTER `account_number`,
  ADD COLUMN `reset_expires` DATETIME DEFAULT NULL AFTER `reset_token`;

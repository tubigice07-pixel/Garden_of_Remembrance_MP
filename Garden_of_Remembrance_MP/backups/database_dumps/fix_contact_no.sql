-- Fix contact_no column in tbl_deceased table
-- This script changes the contact_no column from INT to VARCHAR to properly store phone numbers

-- First, let's see the current structure
DESCRIBE tbl_deceased;

-- Alter the contact_no column to VARCHAR(20) to store phone numbers
ALTER TABLE tbl_deceased MODIFY COLUMN contact_no VARCHAR(20);

-- Verify the change
DESCRIBE tbl_deceased;

-- Optional: Update any existing records that have the truncated value (2147483647)
-- This will set them to empty string, you can manually update them later
UPDATE tbl_deceased SET contact_no = '' WHERE contact_no = '2147483647';

-- Show the updated records
SELECT first_name, last_name, contact_no FROM tbl_deceased WHERE contact_no != '';





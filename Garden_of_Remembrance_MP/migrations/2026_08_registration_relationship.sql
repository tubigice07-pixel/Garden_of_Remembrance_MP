-- Add the registration relationship label. This is additive and preserves all existing rows.
ALTER TABLE tbl_deceased
  ADD COLUMN relationship_to_account_holder VARCHAR(40) NULL AFTER user_id;

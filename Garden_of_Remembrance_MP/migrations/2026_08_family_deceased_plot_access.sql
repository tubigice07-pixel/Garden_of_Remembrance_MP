-- Family/deceased/plot access extension. Safe for existing data: all new links are nullable.
ALTER TABLE tbl_deceased
  ADD COLUMN user_id INT NULL AFTER deceased_id,
  ADD KEY idx_tbl_deceased_user_id (user_id);

ALTER TABLE tbl_bookings
  ADD COLUMN deceased_id INT NULL AFTER user_id,
  ADD COLUMN booking_type VARCHAR(40) NOT NULL DEFAULT 'general' AFTER deceased_id,
  ADD KEY idx_tbl_bookings_deceased_id (deceased_id);

CREATE TABLE IF NOT EXISTS plot_transfer_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  deceased_id INT NOT NULL,
  current_section VARCHAR(20) NOT NULL,
  current_plot_x INT NOT NULL,
  current_plot_y INT NOT NULL,
  requested_section VARCHAR(20) NOT NULL,
  requested_plot_x INT NOT NULL,
  requested_plot_y INT NOT NULL,
  reason TEXT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by INT NULL,
  KEY idx_transfer_user (user_id),
  KEY idx_transfer_deceased (deceased_id),
  KEY idx_transfer_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional foreign keys can be added after legacy orphan data is audited:
-- ALTER TABLE tbl_deceased ADD CONSTRAINT fk_deceased_family FOREIGN KEY (user_id) REFERENCES tbl_user(user_id);
-- ALTER TABLE tbl_bookings ADD CONSTRAINT fk_booking_deceased FOREIGN KEY (deceased_id) REFERENCES tbl_deceased(deceased_id);

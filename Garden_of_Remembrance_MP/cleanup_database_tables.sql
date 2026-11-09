-- ========================================================
-- Garden of Remembrance CMIS - Database Cleanup Script
-- Safe removal of duplicate / obsolete legacy tables
-- PRESERVED: tbl_plots, tbl_user, tbl_admin, tbl_deceased,
--            tbl_bookings, tbl_booking_payment_schedule,
--            tbl_plot_pricing, tbl_guest_reservation_requests,
--            tbl_plot_owners, tbl_plot_deceased_mapping,
--            payment_receipts, tbl_logs, tbl_booking_logs,
--            tbl_deceased_transfers, plot_transfer_requests
-- ========================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Drop duplicate un-prefixed tables (active data is in tbl_* counterparts)
DROP TABLE IF EXISTS `plots`;
DROP TABLE IF EXISTS `deceased`;
DROP TABLE IF EXISTS `lot_pricing`;
DROP TABLE IF EXISTS `booking_payment_schedule`;

-- 2. Drop obsolete / unused legacy tables
DROP TABLE IF EXISTS `reservations`;
DROP TABLE IF EXISTS `sections`;
DROP TABLE IF EXISTS `services`;
DROP TABLE IF EXISTS `interment_packages`;
DROP TABLE IF EXISTS `tbl_recent_clicks`;

SET FOREIGN_KEY_CHECKS = 1;

-- Verification query: View all remaining active tables
SHOW TABLES;

-- Create schema for sections, plots, and services

CREATE TABLE IF NOT EXISTS `sections` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `section_name` VARCHAR(10) NOT NULL UNIQUE,
  `lawn_type` VARCHAR(100) DEFAULT NULL,
  `capacity` INT DEFAULT 0,
  `base_price` DECIMAL(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `plots` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `section_id` INT NOT NULL,
  `plot_no` VARCHAR(20) NOT NULL,
  `status` ENUM('available','occupied') DEFAULT 'available',
  `plot_price` DECIMAL(10,2) DEFAULT NULL,
  `occupant_name` VARCHAR(255) DEFAULT NULL,
  FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `section_plot_unique` (`section_id`,`plot_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `services` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `service_name` VARCHAR(100) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample sections A-J
INSERT IGNORE INTO `sections` (`section_name`, `lawn_type`, `capacity`, `base_price`) VALUES
('A','Lawn Type A',80,3000.00),
('B','Lawn Type B',60,2800.00),
('C','Lawn Type C',72,2900.00),
('D','Lawn Type D',100,3200.00),
('E','Lawn Type E',50,2500.00),
('F','Lawn Type F',40,2400.00),
('G','Lawn Type G',55,2600.00),
('H','Lawn Type H',65,2700.00),
('I','Lawn Type I',70,2950.00),
('J','Lawn Type J',45,2300.00);

-- Sample plots: insert 10 plots per section (A-001..A-010, etc.)
-- Adjust counts as needed for your cemetery
INSERT IGNORE INTO `plots` (`section_id`, `plot_no`, `status`) 
SELECT s.id, CONCAT(s.section_name, '-', LPAD(n,3,'0')), 'available' FROM (
  SELECT 1 AS n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10
) nums
CROSS JOIN sections s
ORDER BY s.section_name, n;

-- Mark a few plots as occupied for demonstration
UPDATE plots p JOIN sections s ON p.section_id = s.id SET p.status = 'occupied' WHERE (s.section_name = 'A' AND p.plot_no LIKE 'A-00' ) AND p.plot_no IN ('A-002','A-005');

-- Sample services
INSERT IGNORE INTO `services` (`service_name`, `price`) VALUES
('Cleaning',200.00),
('Landscaping',200.00),
('Maintenance',200.00),
('Decoration',200.00);

-- End of schema

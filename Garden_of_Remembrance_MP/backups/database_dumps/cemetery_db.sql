-- phpMyAdmin SQL Dump
-- version 4.8.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 10, 2026 at 05:57 AM
-- Server version: 10.1.36-MariaDB
-- PHP Version: 7.2.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `cemetery_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `deceased`
--

CREATE TABLE `deceased` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `death_date` date DEFAULT NULL,
  `grave_id` varchar(20) DEFAULT NULL,
  `coords` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `plots`
--

CREATE TABLE `plots` (
  `id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `plot_no` varchar(20) NOT NULL,
  `status` enum('available','occupied') DEFAULT 'available',
  `plot_price` decimal(10,2) DEFAULT NULL,
  `occupant_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `plots`
--

INSERT INTO `plots` (`id`, `section_id`, `plot_no`, `status`, `plot_price`, `occupant_name`) VALUES
(1, 1, 'A-001', 'available', NULL, NULL),
(2, 1, 'A-002', 'available', NULL, NULL),
(3, 1, 'A-003', 'available', NULL, NULL),
(4, 1, 'A-004', 'available', NULL, NULL),
(5, 1, 'A-005', 'occupied', NULL, NULL),
(6, 1, 'A-006', 'available', NULL, NULL),
(7, 1, 'A-007', 'available', NULL, NULL),
(8, 1, 'A-008', 'available', NULL, NULL),
(9, 1, 'A-009', 'available', NULL, NULL),
(10, 1, 'A-010', 'occupied', NULL, NULL),
(11, 3, 'B-001', 'available', NULL, NULL),
(12, 3, 'B-002', 'available', NULL, NULL),
(13, 3, 'B-003', 'available', NULL, NULL),
(14, 3, 'B-004', 'available', NULL, NULL),
(15, 3, 'B-005', 'occupied', NULL, NULL),
(16, 3, 'B-006', 'available', NULL, NULL),
(17, 3, 'B-007', 'available', NULL, NULL),
(18, 3, 'B-008', 'available', NULL, NULL),
(19, 3, 'B-009', 'available', NULL, NULL),
(20, 3, 'B-010', 'occupied', NULL, NULL),
(21, 4, 'C-001', 'available', NULL, NULL),
(22, 4, 'C-002', 'available', NULL, NULL),
(23, 4, 'C-003', 'available', NULL, NULL),
(24, 4, 'C-004', 'available', NULL, NULL),
(25, 4, 'C-005', 'occupied', NULL, NULL),
(26, 4, 'C-006', 'available', NULL, NULL),
(27, 4, 'C-007', 'available', NULL, NULL),
(28, 4, 'C-008', 'available', NULL, NULL),
(29, 4, 'C-009', 'available', NULL, NULL),
(30, 4, 'C-010', 'occupied', NULL, NULL),
(31, 6, 'D-001', 'available', NULL, NULL),
(32, 6, 'D-002', 'available', NULL, NULL),
(33, 6, 'D-003', 'available', NULL, NULL),
(34, 6, 'D-004', 'available', NULL, NULL),
(35, 6, 'D-005', 'occupied', NULL, NULL),
(36, 6, 'D-006', 'available', NULL, NULL),
(37, 6, 'D-007', 'available', NULL, NULL),
(38, 6, 'D-008', 'available', NULL, NULL),
(39, 6, 'D-009', 'available', NULL, NULL),
(40, 6, 'D-010', 'occupied', NULL, NULL),
(41, 7, 'E-001', 'available', NULL, NULL),
(42, 7, 'E-002', 'available', NULL, NULL),
(43, 7, 'E-003', 'available', NULL, NULL),
(44, 7, 'E-004', 'available', NULL, NULL),
(45, 7, 'E-005', 'occupied', NULL, NULL),
(46, 7, 'E-006', 'available', NULL, NULL),
(47, 7, 'E-007', 'available', NULL, NULL),
(48, 7, 'E-008', 'available', NULL, NULL),
(49, 7, 'E-009', 'available', NULL, NULL),
(50, 7, 'E-010', 'occupied', NULL, NULL),
(51, 9, 'F-001', 'available', NULL, NULL),
(52, 9, 'F-002', 'available', NULL, NULL),
(53, 9, 'F-003', 'available', NULL, NULL),
(54, 9, 'F-004', 'available', NULL, NULL),
(55, 9, 'F-005', 'occupied', NULL, NULL),
(56, 9, 'F-006', 'available', NULL, NULL),
(57, 9, 'F-007', 'available', NULL, NULL),
(58, 9, 'F-008', 'available', NULL, NULL),
(59, 9, 'F-009', 'available', NULL, NULL),
(60, 9, 'F-010', 'occupied', NULL, NULL),
(61, 11, 'G-001', 'available', NULL, NULL),
(62, 11, 'G-002', 'available', NULL, NULL),
(63, 11, 'G-003', 'available', NULL, NULL),
(64, 11, 'G-004', 'available', NULL, NULL),
(65, 11, 'G-005', 'occupied', NULL, NULL),
(66, 11, 'G-006', 'available', NULL, NULL),
(67, 11, 'G-007', 'available', NULL, NULL),
(68, 11, 'G-008', 'available', NULL, NULL),
(69, 11, 'G-009', 'available', NULL, NULL),
(70, 11, 'G-010', 'occupied', NULL, NULL),
(71, 13, 'H-001', 'available', NULL, NULL),
(72, 13, 'H-002', 'available', NULL, NULL),
(73, 13, 'H-003', 'available', NULL, NULL),
(74, 13, 'H-004', 'available', NULL, NULL),
(75, 13, 'H-005', 'occupied', NULL, NULL),
(76, 13, 'H-006', 'available', NULL, NULL),
(77, 13, 'H-007', 'available', NULL, NULL),
(78, 13, 'H-008', 'available', NULL, NULL),
(79, 13, 'H-009', 'available', NULL, NULL),
(80, 13, 'H-010', 'occupied', NULL, NULL),
(81, 15, 'I-001', 'available', NULL, NULL),
(82, 15, 'I-002', 'available', NULL, NULL),
(83, 15, 'I-003', 'available', NULL, NULL),
(84, 15, 'I-004', 'available', NULL, NULL),
(85, 15, 'I-005', 'occupied', NULL, NULL),
(86, 15, 'I-006', 'available', NULL, NULL),
(87, 15, 'I-007', 'available', NULL, NULL),
(88, 15, 'I-008', 'available', NULL, NULL),
(89, 15, 'I-009', 'available', NULL, NULL),
(90, 15, 'I-010', 'occupied', NULL, NULL),
(91, 17, 'J-001', 'available', NULL, NULL),
(92, 17, 'J-002', 'available', NULL, NULL),
(93, 17, 'J-003', 'available', NULL, NULL),
(94, 17, 'J-004', 'available', NULL, NULL),
(95, 17, 'J-005', 'occupied', NULL, NULL),
(96, 17, 'J-006', 'available', NULL, NULL),
(97, 17, 'J-007', 'available', NULL, NULL),
(98, 17, 'J-008', 'available', NULL, NULL),
(99, 17, 'J-009', 'available', NULL, NULL),
(100, 17, 'J-010', 'occupied', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` int(11) NOT NULL,
  `section_name` varchar(10) NOT NULL,
  `lawn_type` varchar(100) DEFAULT NULL,
  `capacity` int(11) DEFAULT '0',
  `base_price` decimal(10,2) DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`id`, `section_name`, `lawn_type`, `capacity`, `base_price`) VALUES
(1, 'A', 'Lawn Type A', 80, '3000.00'),
(3, 'B', 'Lawn Type B', 60, '2800.00'),
(4, 'C', 'Lawn Type C', 72, '2900.00'),
(6, 'D', 'Lawn Type D', 100, '3200.00'),
(7, 'E', 'Lawn Type E', 50, '2500.00'),
(9, 'F', 'Lawn Type F', 40, '2400.00'),
(11, 'G', 'Lawn Type G', 55, '2600.00'),
(13, 'H', 'Lawn Type H', 65, '2700.00'),
(15, 'I', 'Lawn Type I', 70, '2950.00'),
(17, 'J', 'Lawn Type J', 45, '2300.00');

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `service_name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `service_name`, `price`) VALUES
(1, 'Cleaning', '500.00'),
(2, 'Landscaping', '1500.00'),
(3, 'Maintenance', '800.00'),
(4, 'Decoration', '1200.00');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_bookings`
--

CREATE TABLE `tbl_bookings` (
  `id` int(11) NOT NULL,
  `service_type` varchar(50) NOT NULL,
  `grave_location` varchar(100) NOT NULL,
  `plot_type` varchar(50) DEFAULT NULL,
  `section` varchar(10) DEFAULT NULL,
  `plot` varchar(20) DEFAULT NULL,
  `section_plot` varchar(20) DEFAULT NULL,
  `plot_price` decimal(10,2) DEFAULT NULL,
  `preferred_date` date NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `booker_location` varchar(255) DEFAULT NULL,
  `special_requests` text,
  `payment_type` varchar(50) DEFAULT NULL,
  `amount_paid` decimal(10,2) DEFAULT '0.00',
  `reference_number` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_email` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `tbl_bookings`
--

INSERT INTO `tbl_bookings` (`id`, `service_type`, `grave_location`, `plot_type`, `section`, `plot`, `section_plot`, `plot_price`, `preferred_date`, `contact_number`, `booker_location`, `special_requests`, `payment_type`, `amount_paid`, `reference_number`, `bank_name`, `status`, `user_id`, `created_at`, `customer_name`, `customer_email`) VALUES
(41, 'Maintenance', 'A - Auto Assigned', 'Standard', 'A', 'AUTO', NULL, '5000.00', '2026-12-01', '09783247328', 'sual', 'Test', 'GCash', '5000.00', 'GC17830660921912', 'GCash', 'completed', 68, '2026-07-03 08:08:02', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_booking_logs`
--

CREATE TABLE `tbl_booking_logs` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `section` varchar(50) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `booker_location` text NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_deceased`
--

CREATE TABLE `tbl_deceased` (
  `deceased_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `suffix` varchar(20) NOT NULL,
  `bday` date NOT NULL,
  `born_in` varchar(50) NOT NULL,
  `contact_no` varchar(25) NOT NULL,
  `date_died` date NOT NULL,
  `funeral_service` varchar(100) NOT NULL,
  `section` varchar(20) NOT NULL,
  `plot_x` int(11) NOT NULL,
  `plot_y` int(11) NOT NULL,
  `added_by` varchar(100) DEFAULT NULL,
  `edited_by` varchar(100) DEFAULT NULL,
  `action` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `tbl_deceased`
--

INSERT INTO `tbl_deceased` (`deceased_id`, `first_name`, `middle_name`, `last_name`, `suffix`, `bday`, `born_in`, `contact_no`, `date_died`, `funeral_service`, `section`, `plot_x`, `plot_y`, `added_by`, `edited_by`, `action`) VALUES
(30, 'JOSE', 'george', 'RIZAL', '', '2025-09-04', 'LUNETA PARK ', '09123456789', '2025-09-27', 'GETHSEMANE FUNERAL SERVICE', 'C', 33, 2, NULL, 'CHAD BASILA', ''),
(31, 'EMILIO', '', 'AGUINALDO', '', '2025-09-01', 'MABINI PANGASINAN', '0912345678', '2025-09-27', 'ABALOS FUNERAL', 'A', 3, 2, NULL, '0', ''),
(32, 'JOHN', '', 'CARLO', '', '2025-09-26', 'MABINI PANGASINAN', '09232345456', '2025-09-25', 'GETHSEMANE FUNERAL', 'A', 12, 1, NULL, '0', ''),
(42, 'SPONGEBOB', 'MOSHI', 'SQUAREPANTS', '', '2025-10-01', 'LUNETA PARK', '09123456789', '2025-10-17', 'GETHSEMANE FUNERAL', 'A', 18, 3, NULL, 'JASPER SEVILLANO', ''),
(44, 'TOM', 'AND', 'JERRY', 'III', '2025-10-01', 'MABINI PANGASINAN', '09232387654', '2025-10-17', 'GETHSEMANE FUNERAL', 'B', 43, 4, NULL, 'CHAD BASILA', ''),
(45, 'COURAGE', 'THE', 'COWARDLY DOG', 'JR.', '2001-12-04', 'MABINI PANGASINAN', '09123784645', '2025-06-08', 'GETHSEMANE', 'A', 13, 4, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(48, 'MICKEY', 'MOUSE', 'CLUBHOUSE', '', '1998-05-04', 'LUNETA PARK', '09112312443', '2025-02-07', 'QAAQ FUNERAL SERVICES', 'B', 47, 4, 'JASPER SEVILLANO', 'CHAD BASILA', ''),
(49, 'JOSE', 'A', 'RIZAL', 'JR.', '2025-10-03', 'LUNETA PARK', '09161523223', '2025-10-03', 'QAAQ FUNERAL SERVICES', 'A', 18, 3, 'JASPER SEVILLANO', 'ALJON LUCERO', ''),
(50, 'SOFIA', 'D', 'FIRST', 'JR', '2021-05-04', 'CASTLE', '09123456711', '2025-02-05', 'GETHSEMANE', 'F', 31, 2, 'JASPER SEVILLANO', 'CHAD BASILA', ''),
(57, 'SAMMY', 'M', 'ILBY', '', '2020-02-02', 'ALAMINOS CITY PANGASINAN', '09887645672', '2025-04-03', 'QAAQ FUNERAL SERVICES', 'F', 36, 2, 'JASPER SEVILLANO', 'CHAD BASILA', ''),
(61, 'ARTURO', 'D', 'NERY', 'JR.', '0060-10-02', 'LUNETA PARK', '1213123551', '1991-10-18', 'GETHSEMANE', 'A', 41, 1, 'JASPER SEVILLANO', 'CHAD BASILA', ''),
(62, 'RAUL', 'SECULO', 'MAGIBA', 'JR.', '1990-10-03', 'MALACANANG', '0917262623', '2025-03-04', 'ABALOS', 'B', 7, 1, 'JASPER SEVILLANO', '', ''),
(63, 'JOMAR', 'D', 'ACE', 'IV', '1980-10-09', 'MALACANANG', '0189182823', '2025-05-04', 'ABALOS FUNERAL', 'A', 18, 3, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(64, 'JANNELLEIE', 'JA', 'JAJA', 'SR.', '1950-04-03', 'MABINI PANGASINAN', '09123812412', '2025-03-05', 'GARDEN REMEMBRANCE', 'B', 22, 3, 'CHAD BASILA', 'CHAD BASILA', ''),
(65, 'JADE', 'S', 'SALDE', 'V', '2005-04-03', 'ALAMINOS CITY PANGASINAN', '09605295663', '2025-06-06', 'QAAQ FUNERAL SERVICES', 'C', 45, 2, 'CHAD BASILA', 'JASPER SEVILLANO', ''),
(66, 'KOBE', 'A', 'BRYAN', 'JR.', '1980-02-03', 'USA', '01927363611', '2020-08-08', 'GETHSEMANE FUNERAL SERVICE', 'B', 50, 2, 'CHAD BASILA', 'CHAD BASILA', ''),
(67, 'JHONNY', 'V', 'BRAVO', 'V', '2025-10-03', 'MABINI PANGASINAN', '09475698674', '2025-10-18', 'GETHSEMANE FUNERAL SERVICE', 'F', 35, 4, 'CHAD BASILA', 'CHAD BASILA', ''),
(71, 'NANDO', 'Z', 'AGILA', '', '2025-10-01', 'CASTLE', '09808037483', '2025-10-18', 'GETHSEMANE FUNERAL SERVICE', 'B', 16, 4, 'CHAD BASILA', 'JASPER SEVILLANO', ''),
(73, 'MARIE', 'S', 'JULIANA', 'SR.', '2021-02-18', 'LUNETA PARK', '09454756756', '2025-10-18', 'ABALOS FUNERAL', 'B', 1, 1, 'CHAD BASILA', 'JASPER SEVILLANO', ''),
(74, 'MARIANO', 'SABEL', 'JUNIOR', '', '2021-02-18', 'MANILA CITY', '09288642520', '2025-10-18', 'GETHSEMANE FUNERAL SERVICE', 'B', 24, 4, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(75, 'MUZAN', '', 'DEGUZMAN', '', '2025-10-04', 'MABINI PANGASINAN', '09123456789', '2025-10-18', 'ABALOS', 'A', 5, 1, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(76, 'VENTUSA', 'D', 'MASSAGE', 'JR.', '2025-10-01', 'USA', '09656867548', '2025-10-16', 'GETHSEMANE FUNERAL', 'B', 49, 3, 'CHAD BASILA', 'CHAD BASILA', ''),
(78, 'MICHAEL', 'D', 'JACKSON', 'II', '2000-06-01', 'ALAMINOS CITY PANGASINAN', '09123456711', '2020-05-04', 'ABALOS', 'F', 36, 4, 'CHAD BASILA', 'CHAD BASILA', ''),
(79, 'JACKSON', 'S', 'STORM', '', '2005-02-09', 'MABINI PANGASINAN', '09123456789', '2025-06-05', 'GARDEN REMEMBRANCE', 'F', 36, 4, 'CHAD BASILA', 'CHAD BASILA', ''),
(80, 'BUNNY', '', 'BUGS', '', '1994-06-21', 'MABINI PANGASINAN', '09887645675', '2000-12-28', 'QAAQ FUNERAL SERVICES', 'C', 32, 2, 'CHAD BASILA', 'CHAD BASILA', ''),
(81, 'FRIEREN', '', 'BEYOND', '', '1968-04-25', 'AGNO, PANGASINAN', '09748736477', '1980-07-18', 'ST.PETER', 'D', 20, 4, 'Unknown', 'Unknown', ''),
(82, 'DORA', '', 'BOOTS', '', '1999-02-22', 'BIRINGAN CITY', '09123456789', '2010-03-17', 'ABALOS', 'A', 51, 1, 'Unknown', 'CHAD BASILA', ''),
(83, 'ZION', '', 'SONG', '', '2010-01-22', 'BIRINGAN CITY', '09737628173', '2017-06-21', 'ABALOS', 'F', 21, 3, 'Unknown', 'Unknown', ''),
(84, 'AURO', 'DAN', 'TAN', '', '1996-02-10', 'AGNO, PANGASINAN', '09637153732', '2016-10-22', 'GETHSEMANE FUNERAL', 'F', 13, 2, 'Unknown', 'Unknown', ''),
(85, 'MARI', '', 'SAN', 'SR.', '1986-06-17', 'POBLACION ALAMINOS', '09635376282', '2007-07-09', 'ST.PETER', 'F', 16, 3, 'Unknown', 'Unknown', ''),
(86, 'MOUNTAIN', '', 'DEW', 'JR.', '2000-10-19', 'AGNO, PANGASINAN', '09984165581', '2018-06-27', 'ST.PETER', 'F', 14, 3, 'Unknown', 'Unknown', ''),
(87, 'MACHI', '', 'UZAWA', '', '2010-09-22', 'CAOAYAN', '09933124886', '2018-09-22', 'QAAQ FUNERAL SERVICES', 'F', 32, 4, 'Unknown', 'Unknown', ''),
(88, 'RELA', 'CULIMA', 'LUSTERIO', '', '2021-05-03', 'BIRINGAN CITY', '09513159737', '2024-07-25', 'GETHSEMANE FUNERAL', 'E', 41, 4, 'Unknown', 'CHAD BASILA', ''),
(89, 'SCOOBY', '', 'DOO', '', '2021-01-20', 'MYSTERY LANE', '09999999999', '2024-05-19', 'ST.PETER', 'D', 1, 1, 'Unknown', 'Unknown', ''),
(90, 'SHAGGY', '', 'ROGERS', '', '2020-05-12', 'SADWICH STREET', '09888888888', '2023-01-02', 'GETHSEMANE FUNERAL', 'D', 1, 2, 'Unknown', 'Unknown', ''),
(91, 'PATRICK', '', 'STAR', '', '2019-01-07', 'BIKINI BOTTOM', '09254746858', '2024-03-18', 'ABALOS FUNERAL', 'D', 1, 3, 'Unknown', 'Unknown', ''),
(92, 'MR. CRABS', '', 'BOBS', '', '2021-05-21', 'KRUSTY CRAB STREET', '09123435433', '2024-08-06', 'ABALOS', 'D', 1, 4, 'Unknown', 'Unknown', ''),
(93, 'SANDY', '', 'CHEEKS', '', '1991-05-20', 'MABINI PANGASINAN', '09923456787', '2010-05-22', 'GETHSEMANE FUNERAL', 'D', 33, 4, 'Unknown', 'Unknown', ''),
(94, 'BEN', '', 'TENESON', '', '1994-06-22', 'BIRINGAN CITY', '09741853573', '2009-12-01', 'ST.PETER', 'D', 41, 2, 'Unknown', 'Unknown', ''),
(95, 'DETECTIVE', '', 'CONAN', '', '1911-02-22', 'AGNO, PANGASINAN', '09884576135', '2006-09-22', 'ABALOS', 'D', 39, 4, 'Unknown', 'JASPER SEVILLANO', ''),
(96, 'ARTURO', '', 'RATATUWE', '', '1985-01-22', 'CAOAYAN', '09645312678', '1997-06-22', 'ABALOS FUNERAL', 'D', 23, 4, 'Unknown', 'Unknown', ''),
(97, 'ROBERT', '', 'FOXERS', '', '1993-08-22', 'CAOAYAN', '09864532165', '2002-07-22', 'ABALOS FUNERAL', 'D', 36, 3, 'Unknown', 'Unknown', ''),
(98, 'JEL', 'BIYAK', 'DELA CRUZ', '', '2020-12-29', 'MABINI PANGASINAN', '09513519737', '2024-06-26', 'GETHSEMANE FUNERAL', 'D', 16, 2, 'Unknown', 'Unknown', ''),
(99, 'JADE', 'OREAS', 'DEGUZMAN', '', '2018-02-11', 'BIRINGAN CITY', '09513519737', '2023-02-27', 'GETHSEMANE FUNERAL', 'E', 25, 2, 'Unknown', 'Unknown', ''),
(100, 'ANGILINE', 'JAVIER', 'MENDOZA', '', '2007-08-22', 'BIRINGAN CITY', '09288642520', '2020-11-10', 'LAZO FUNERAL SERVICES', 'E', 24, 2, 'Unknown', 'Unknown', ''),
(101, 'GELLY', 'OSCA', 'BALUYOT', '', '2012-09-24', 'CAOAYAN', '09234678655', '2019-12-10', 'ST.PETER', 'E', 33, 2, 'Unknown', 'Unknown', ''),
(102, 'TWERTY', '', 'DEGUZMAN', '', '2006-05-12', 'CAYUCAY PANGASINAN', '09288642520', '2017-04-03', 'ABALOS FUNERAL', 'E', 39, 1, 'Unknown', 'Unknown', ''),
(103, 'MADZ', '', 'MENDEZ', '', '2003-08-18', 'BIRINGAN CITY', '09288642520', '2021-09-10', 'ST.PETER', 'E', 1, 1, 'Unknown', 'Unknown', ''),
(104, 'SAM', '', 'CORBILION', '', '2009-06-19', 'MABINI PANGASINAN', '09674325678', '2019-10-28', 'QAAQ FUNERAL SERVICES', 'E', 7, 2, 'Unknown', 'Unknown', ''),
(105, 'ALLEN', '', 'TEJADA', '', '2003-09-02', 'AGNO, PANGASINAN', '09567843267', '2016-10-08', 'GETHSEMANE FUNERAL', 'E', 3, 4, 'Unknown', 'Unknown', ''),
(106, 'LYN', '', 'SIOCO', '', '2002-03-14', 'BIRINGAN CITY', '09674256368', '2025-10-20', 'QAAQ FUNERAL SERVICES', 'E', 30, 3, 'Unknown', 'Unknown', ''),
(107, 'JEFF', '', 'LAGUISMA', '', '2009-11-02', 'MAGSAYAY ALAMINOS CITY', '09345678299', '2016-07-04', 'GETHSEMANE FUNERAL', 'E', 9, 4, 'Unknown', 'Unknown', ''),
(108, 'VINCE', '', 'LOMBOY', '', '2007-03-26', 'MABINI PANGASINAN', '09456287923', '2010-09-22', 'GETHSEMANE FUNERAL', 'E', 21, 3, 'Unknown', 'Unknown', ''),
(109, 'LANY', '', 'ONY', '', '2021-02-15', 'MABINI PANGASINAN', '09887963364', '2023-02-14', 'ABALOS', 'E', 5, 2, 'Unknown', 'CHAD BASILA', ''),
(110, 'LANY', '', 'LYN', '', '2025-10-14', 'CAOAYAN', '09837676456', '2025-10-22', 'LAZO FUNERAL SERVICES', 'C', 22, 2, 'Unknown', 'Unknown', ''),
(111, 'EREN', '', 'TITAN', '', '2022-01-03', 'CAOAYAN', '09121333423', '2024-05-26', 'ST.PETER', 'B', 1, 2, 'Unknown', 'Unknown', ''),
(112, 'KIM', 'JONG', 'OUNG', '', '2000-07-12', 'MEXICO PAMPANGA', '09182818273', '2025-02-04', 'QAAQ FUNERAL SERVICES', 'B', 33, 3, 'Unknown', 'Unknown', ''),
(113, 'JOFFE', 'R', 'ALBS', '', '1999-02-01', 'SUAL', '09605295663', '2025-05-16', 'QAAQ FUNERAL SERVICES', 'B', 7, 1, 'JASPER SEVILLANO', '', ''),
(114, 'ALFRED', '', 'VERGARA', 'JR.', '2005-07-20', 'ANDA', '09213214212', '2026-03-15', 'ST.PETER', 'A', 23, 3, 'User', 'User', '');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_logs`
--

CREATE TABLE `tbl_logs` (
  `id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text,
  `timestamp` datetime NOT NULL,
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `tbl_logs`
--

INSERT INTO `tbl_logs` (`id`, `action`, `details`, `timestamp`, `user_id`) VALUES
(1, 'Updated Reservation', 'CHAD BASILA updated reservation for Section B, Plot 38-1 to BERNARD A BASILA JR', '2025-10-21 13:16:10', 59),
(2, 'Updated Reservation', 'Section B, Plot 38-1 | OLD: OWNER=BERNARD A BASILA JR, BDAY=2025-10-01, PHONE=09453867433, ADDRESS=DSADWDW | NEW: OWNER=BERNARD A BARINA JR, BDAY=2025-10-01, PHONE=09453867433, ADDRESS=DSADWDW', '2025-10-21 13:21:18', 59),
(3, 'Updated Reservation', 'Section B, Plot 38-1 | OLD: OWNER=BERNARD A BARINA JR, BDAY=2025-10-01, PHONE=09453867433, ADDRESS=DSADWDW | NEW: OWNER=WILFREDO A BARINA JR, BDAY=2025-10-01, PHONE=09453867433, ADDRESS=DSADWDW', '2025-10-21 13:24:30', 59),
(4, 'Updated Reservation', 'Section B, Plot 39-3 | OLD: OWNER=KOBERTY A BRYAN JR, BDAY=1980-02-03, PHONE=01927363611, ADDRESS=USA | NEW: OWNER=KOBERTY A BRYAN JR, BDAY=1980-02-03, PHONE=01927363222, ADDRESS=USA', '2025-10-21 13:31:36', 59),
(5, 'Updated Reservation', 'Section B, Plot 49-3 | OLD: OWNER=MCDO CHOWKING INASAL, BDAY=2021-02-27, PHONE=09342596845, ADDRESS=MABINI PANGASINAN | NEW: OWNER=MCDOG CHOWKINGS INASAL, BDAY=2021-02-27, PHONE=09342596842, ADDRESS=MABINI PANGASINAN', '2025-10-21 13:37:53', 59),
(6, 'Reserved By', 'Section B, Plot 34-3 reserved for FLAPPY BIRD', '2025-10-21 13:39:41', 59),
(7, 'Moved By', 'CHAD BASILA moved deceased ID 61 to Section A, Plot 41-1', '2025-10-21 13:47:45', 59),
(8, 'Moved By', 'CHAD BASILA moved deceased ID 75 to Section A, Plot 5-1', '2025-10-21 13:48:12', 59),
(9, 'Updated Reservation', 'Section B, Plot 37-3 | OLD: OWNER=MICKEY MINNEY MOUSE JR, BDAY=2025-03-01, PHONE=09812623612, ADDRESS=ALAMINOS PANGASINAN | NEW: OWNER=TTYTYTYT MINNEY MOUSE JR, BDAY=2025-03-01, PHONE=09812623612, ADDRESS=ALAMINOS PANGASINAN', '2025-10-21 13:51:21', 59),
(10, 'Updated Reservation', 'Section B, Plot 38-1 | OLD: OWNER=WILFREDO A BARINA JR, BDAY=2025-10-01, PHONE=09453867433, ADDRESS=DSADWDW | NEW: OWNER=WILFREDORER A BARINAW JR, BDAY=2025-10-01, PHONE=09453867433, ADDRESS=DSADWDW', '2025-10-21 13:52:22', 59),
(11, 'Updated Reservation', 'Section B, Plot 37-3 | OLD: OWNER=TTYTYTYT MINNEY MOUSE JR, BDAY=2025-03-01, PHONE=09812623612, ADDRESS=ALAMINOS PANGASINAN | NEW: OWNER=TTYTYTYT MOUSEYYY JR, BDAY=2025-03-01, PHONE=09812623612, ADDRESS=ALAMINOS PANGASINAN', '2025-10-21 13:53:22', 61),
(12, 'ADD_DECEASED', 'Added deceased person: FRIEREN BEYOND to Section D, Plot 20-4', '2025-10-21 22:01:03', 0),
(13, 'ADD_DECEASED', 'Added deceased person: DORA BOOTS to Section B, Plot 19-4', '2025-10-22 08:58:49', 0),
(14, 'EDIT_DECEASED', 'Edited deceased ID 65 at Section B, Plot 35-4', '2025-10-22 09:16:19', 59),
(15, 'EDIT_DECEASED', 'Edited deceased ID 65 at Section B, Plot 35-4', '2025-10-22 09:21:30', 59),
(16, 'Updated Reservation', 'Section B, Plot 38-1 | OLD: OWNER=WILFREDORER A BARINAW JR, BDAY=2025-10-01, PHONE=09453867433, ADDRESS=DSADWDW | NEW: OWNER=ALFREDO A BARINAW JR, BDAY=2025-10-01, PHONE=09453867433, ADDRESS=DSADWDW', '2025-10-22 09:27:03', 59),
(17, 'Moved By', 'CHAD BASILA moved deceased ID 82 to Section A, Plot 51-1', '2025-10-22 09:29:37', 59),
(18, 'ADD_DECEASED', 'Added deceased person: ZION SONG to Section F, Plot 21-3', '2025-10-22 09:39:18', 0),
(19, 'ADD_DECEASED', 'Added deceased person: AURO DAN TAN to Section F, Plot 13-2', '2025-10-22 09:41:11', 0),
(20, 'ADD_DECEASED', 'Added deceased person: MARI SAN SR. to Section F, Plot 16-3', '2025-10-22 09:43:13', 0),
(21, 'ADD_DECEASED', 'Added deceased person: MOUNTAIN DEW JR. to Section F, Plot 14-3', '2025-10-22 09:54:28', 0),
(22, 'ADD_DECEASED', 'Added deceased person: MACHI UZAWA to Section F, Plot 32-4', '2025-10-22 09:56:27', 0),
(23, 'ADD_DECEASED', 'Added deceased person: RELA CULIMA LUSTERIO to Section B, Plot 28-3', '2025-10-22 10:01:33', 0),
(24, 'Moved By', 'CHAD BASILA moved deceased ID 88 to Section E, Plot 41-4', '2025-10-22 10:02:15', 59),
(25, 'Reserved By', 'Section E, Plot 17-2 reserved for BEN REVERA', '2025-10-22 10:04:47', 59),
(26, 'Updated Reservation', 'Section E, Plot 17-2 | OLD: OWNER=BEN REVERA, BDAY=2013-02-06, PHONE=09513518973, ADDRESS=CAYUCAY ALAMINOS CITY | NEW: OWNER=BENBEN REVERA, BDAY=2013-02-06, PHONE=09513518973, ADDRESS=CAYUCAY ALAMINOS CITY', '2025-10-22 10:05:50', 59),
(27, 'ADD_DECEASED', 'Added deceased person: SCOOBY DOO to Section D, Plot 1-1', '2025-10-22 10:48:56', 0),
(28, 'ADD_DECEASED', 'Added deceased person: SHAGGY ROGERS to Section D, Plot 1-2', '2025-10-22 10:50:23', 0),
(29, 'ADD_DECEASED', 'Added deceased person: PATRICK STAR to Section D, Plot 1-3', '2025-10-22 10:51:42', 0),
(30, 'ADD_DECEASED', 'Added deceased person: MR. CRABS BOBS to Section D, Plot 1-4', '2025-10-22 10:53:23', 0),
(31, 'ADD_DECEASED', 'Added deceased person: SANDY CHEEKS to Section D, Plot 33-4', '2025-10-22 10:54:58', 0),
(32, 'ADD_DECEASED', 'Added deceased person: BEN TENESON to Section D, Plot 41-2', '2025-10-22 10:57:01', 0),
(33, 'ADD_DECEASED', 'Added deceased person: CONAN BARBARTEK to Section D, Plot 39-4', '2025-10-22 10:58:32', 0),
(34, 'ADD_DECEASED', 'Added deceased person: ARTURO RATATUWE to Section D, Plot 23-4', '2025-10-22 11:00:56', 0),
(35, 'ADD_DECEASED', 'Added deceased person: ROBERT FOXERS to Section D, Plot 36-3', '2025-10-22 11:03:06', 0),
(36, 'ADD_DECEASED', 'Added deceased person: JEL BIYAK DELA CRUZ to Section D, Plot 16-2', '2025-10-22 11:06:31', 0),
(37, 'ADD_DECEASED', 'Added deceased person: JADE OREAS DEGUZMAN to Section E, Plot 25-2', '2025-10-22 11:17:30', 0),
(38, 'ADD_DECEASED', 'Added deceased person: ANGILINE JAVIER MENDOZA to Section E, Plot 24-2', '2025-10-22 11:19:14', 0),
(39, 'ADD_DECEASED', 'Added deceased person: GELLY OSCA BALUYOT to Section E, Plot 33-2', '2025-10-22 11:21:03', 0),
(40, 'ADD_DECEASED', 'Added deceased person: TWERTY DEGUZMAN to Section E, Plot 39-1', '2025-10-22 11:22:42', 0),
(41, 'ADD_DECEASED', 'Added deceased person: MADZ MENDEZ to Section E, Plot 1-1', '2025-10-22 11:24:09', 0),
(42, 'ADD_DECEASED', 'Added deceased person: SAM CORBILION to Section E, Plot 7-2', '2025-10-22 11:25:38', 0),
(43, 'ADD_DECEASED', 'Added deceased person: ALLEN TEJADA to Section E, Plot 3-4', '2025-10-22 11:27:41', 0),
(44, 'ADD_DECEASED', 'Added deceased person: LYN SIOCO to Section E, Plot 30-3', '2025-10-22 11:29:34', 0),
(45, 'ADD_DECEASED', 'Added deceased person: JEFF LAGUISMA to Section E, Plot 9-4', '2025-10-22 11:31:45', 0),
(46, 'ADD_DECEASED', 'Added deceased person: VINCE LOMBOY to Section E, Plot 21-3', '2025-10-22 11:33:15', 0),
(47, 'Moved By', 'CHAD BASILA moved deceased ID 69 to Section E, Plot 23-1', '2025-10-22 11:58:35', 59),
(48, 'Reserved By', 'Section B, Plot 40-4 reserved for ANOJ BIRD', '2025-10-22 12:13:03', 59),
(49, 'Updated Reservation', 'Section B, Plot 40-4 | OLD: OWNER=ANOJ BIRD, BDAY=2024-06-03, PHONE=09234394832, ADDRESS=ALAMINOS CITY PANGASINAN | NEW: OWNER=JAMES GAR, BDAY=2020-05-11, PHONE=09234394832, ADDRESS=ALAMINOS CITY PANGASINAN', '2025-10-22 12:15:57', 59),
(50, 'Updated Reservation', 'Section B, Plot 49-3 | OLD: OWNER=MCDOG CHOWKINGS INASAL, BDAY=2021-02-27, PHONE=09342596842, ADDRESS=MABINI PANGASINAN | NEW: OWNER=VENTUSA D MASSAGE JR., BDAY=2025-10-01, PHONE=09656867548, ADDRESS=USA', '2025-10-22 12:25:18', 59),
(51, 'Moved By', 'CHAD BASILA moved deceased ID 76 to Section B, Plot 49-3', '2025-10-22 12:25:52', 59),
(52, 'EDIT_DECEASED', 'Edited deceased ID 70 at Section B, Plot 7-1', '2025-10-22 12:42:50', 61),
(53, 'EDIT_DECEASED', 'Edited deceased ID 73 at Section B, Plot 1-1', '2025-10-22 12:43:34', 61),
(54, 'Updated Reservation', 'Section B, Plot 37-3 | OLD: OWNER=TTYTYTYT MOUSEYYY JR, BDAY=2025-03-01, PHONE=09812623612, ADDRESS=ALAMINOS PANGASINAN | NEW: OWNER=GILBERT MOUSEYYY JR, BDAY=2025-03-01, PHONE=09812623612, ADDRESS=ALAMINOS PANGASINAN', '2025-10-22 12:43:45', 61),
(55, 'EDIT_DECEASED', 'Edited deceased ID 95 at Section D, Plot 39-4', '2025-10-22 12:46:41', 61),
(56, 'EDIT_DECEASED', 'Edited deceased ID 75 at Section A, Plot 5-1', '2025-10-22 12:48:53', 61),
(57, 'ADD_DECEASED', 'Added deceased person: LAN ONY to Section A, Plot 51-1', '2025-10-22 13:25:36', 0),
(58, 'EDIT_DECEASED', 'Edited deceased ID 109 at Section A, Plot 51-1', '2025-10-22 13:26:26', 59),
(59, 'Moved By', 'CHAD BASILA moved deceased ID 109 to Section E, Plot 5-2', '2025-10-22 13:26:52', 59),
(60, 'ADD_DECEASED', 'Added deceased person: LANY LYN to Section C, Plot 22-2', '2025-10-22 13:29:10', 0),
(61, 'ADD_DECEASED', 'Added deceased person: EREN TITAN to Section B, Plot 1-2', '2025-10-28 10:08:58', 0),
(62, 'EDIT_DECEASED', 'Edited deceased ID 76 at Section B, Plot 49-3', '2025-10-28 13:09:14', 59),
(63, 'ADD_DECEASED', 'Added deceased person: KIM JONG OUNG to Section B, Plot 33-3', '2026-02-28 23:08:58', 0),
(64, 'Reserved By', 'Section B, Plot 36-2 reserved for JAY JOE MIDE', '2026-02-28 23:09:58', 61),
(65, 'SERVICE_BOOKING', 'Service booked: Decoration for plot F-16-3', '2026-03-12 20:20:22', 61),
(66, 'SERVICE_BOOKING', 'Service booked: Cleaning for plot B-35-4', '2026-03-12 20:21:03', 61),
(67, 'SERVICE_BOOKING', 'Service booked: Painting for plot C-33-2', '2026-03-12 20:24:43', 61),
(68, 'SERVICE_BOOKING', 'Service booked: Decoration for plot B-47-4', '2026-03-12 22:55:48', 61),
(69, 'SERVICE_BOOKING', 'Service booked: Maintenance for plot B-50-2', '2026-03-12 23:09:23', 61),
(70, 'EDIT_DECEASED', 'Edited deceased ID 65 at Section B, Plot 35-4', '2026-03-14 18:26:13', 61),
(71, 'SERVICE_BOOKING', 'Service booked: Maintenance for plot B-35-4 - Notes: Please make it good', '2026-03-14 18:26:34', 61),
(72, 'SERVICE_BOOKING', 'Service booked: Decoration for plot E-25-2', '2026-03-16 16:57:53', 61),
(73, 'SERVICE_BOOKING', 'Service booked: Maintenance for plot C-32-2', '2026-03-16 22:41:43', 61),
(74, 'Moved By', 'JASPER SEVILLANO moved deceased ID 65 to Section C, Plot 49-1', '2026-03-17 08:03:44', 61),
(75, 'SERVICE_BOOKING', 'Service booked: Cleaning for plot C-32-2', '2026-03-17 10:34:55', 61),
(76, 'ADD_DECEASED', 'Added deceased person: ALFRED VERGARA JR. to Section A, Plot 23-3', '2026-03-17 11:43:25', 64),
(77, 'Booking Approved', 'Booking ID: #26 has been approved', '2026-06-20 20:47:27', 64),
(78, 'Booking Approved', 'Booking ID: #25 has been approved', '2026-06-20 21:02:29', 64),
(79, 'Booking Rejected', 'Booking ID: #24 has been rejected', '2026-06-20 21:02:38', 64),
(80, 'Booking Completed', 'Booking ID: #26 has been completed', '2026-06-20 21:21:38', 64),
(81, 'Booking Completed', 'Booking ID: #26 has been completed', '2026-06-20 21:30:43', 64),
(82, 'Booking Completed', 'Booking ID: #26 has been completed', '2026-06-20 21:51:31', 64),
(83, 'Booking Completed', 'Booking ID: #25 has been completed', '2026-06-20 21:51:33', 64),
(84, 'Booking Completed', 'Booking ID: #24 has been completed', '2026-06-20 21:51:36', 64),
(85, 'Booking Completed', 'Booking ID: #30 has been completed', '2026-06-20 23:25:22', 61),
(86, 'Booking Completed', 'Booking ID: #29 has been completed', '2026-06-20 23:25:26', 61),
(87, 'Reserved By', 'Section A, Plot 29-4 reserved for DIGONG DUGS', '2026-06-21 23:36:15', 64),
(88, 'Booking Completed', 'Booking ID: #37 has been completed', '2026-06-29 15:32:57', 64),
(89, 'Booking Completed', 'Booking ID: #41 has been completed', '2026-07-03 16:08:42', 68);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_plot_owners`
--

CREATE TABLE `tbl_plot_owners` (
  `id` int(11) NOT NULL,
  `section` varchar(10) NOT NULL,
  `plot_x` int(11) NOT NULL,
  `plot_y` int(11) NOT NULL,
  `reserved_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reserved_by` int(11) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `bday` date NOT NULL,
  `phone_no` varchar(15) NOT NULL,
  `address` varchar(255) NOT NULL,
  `owner_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_plot_owners`
--

INSERT INTO `tbl_plot_owners` (`id`, `section`, `plot_x`, `plot_y`, `reserved_at`, `reserved_by`, `active`, `first_name`, `middle_name`, `last_name`, `suffix`, `bday`, `phone_no`, `address`, `owner_name`) VALUES
(3, 'B', 38, 1, '2025-10-18 01:39:42', 61, 1, 'ALFREDO', 'A', 'BARINAW', 'JR', '2025-10-01', '09453867433', 'DSADWDW', 'ALFREDO A BARINAW JR'),
(6, 'B', 37, 3, '2025-10-18 11:31:10', 59, 1, 'GILBERT', '', 'MOUSEYYY', 'JR', '2025-03-01', '09812623612', 'ALAMINOS PANGASINAN', 'GILBERT MOUSEYYY JR'),
(7, 'B', 39, 3, '2025-10-18 11:44:10', 59, 1, 'KOBERTY', 'A', 'BRYAN', 'JR', '1980-02-03', '01927363222', 'USA', 'KOBERTY A BRYAN JR'),
(9, 'B', 49, 3, '2025-10-18 11:48:02', 59, 0, 'VENTUSA', 'D', 'MASSAGE', 'JR.', '2025-10-01', '09656867548', 'USA', 'VENTUSA D MASSAGE JR.'),
(11, 'B', 43, 4, '2025-10-18 12:03:34', 59, 0, 'TOM', 'AND', 'JERRY', 'III', '2025-10-01', '09232387654', 'MABINI PANGASINAN', 'TOM AND JERRY III'),
(13, 'C', 27, 6, '2025-10-18 12:43:16', 59, 1, 'SOFIA', 'D', 'FIRST', 'JR', '2021-05-04', '09123456711', 'CASTLE', 'SOFIA D FIRST JR'),
(14, 'F', 35, 4, '2025-10-18 12:52:26', 59, 0, 'JHONNY', 'V', 'BRAVO', 'V', '2025-10-03', '09475698674', 'MABINI PANGASINAN', 'JHONNY V BRAVO V'),
(15, 'B', 31, 2, '2025-10-18 13:09:54', 59, 0, 'KOLOKOYS', '', 'BASILO', 'JR.', '2025-10-04', '09454580323', 'MABINI PANGASINAN', 'KOLOKOYS BASILO JR.'),
(16, 'B', 21, 1, '2025-10-18 13:45:06', 59, 0, 'WALLY', 'B', 'BAYOLA', 'JR.', '2025-10-01', '09388878477', 'ALAMINOS CITY PANGASINAN', 'WALLY B BAYOLA JR.'),
(17, 'B', 35, 4, '2025-10-18 13:54:46', 59, 0, 'NANDO', 'Z', 'AGILA', '', '2025-10-01', '09808037483', 'CASTLE', 'NANDO Z AGILA'),
(19, 'B', 28, 3, '2025-10-18 17:39:52', 61, 0, 'PERSONAL', 'SAMMY', 'OLGAR', '', '2025-09-01', '09567899077', 'MABINI PANGASINAN', 'PERSONAL SAMMY OLGAR'),
(21, 'B', 53, 3, '2025-10-18 19:30:41', 61, 0, 'PABLO', 'HUMAN', 'CRUZ', 'V', '2025-10-01', '05869456756', 'BAGUIO CITY', 'PABLO HUMAN CRUZ V'),
(22, 'B', 50, 2, '2025-10-18 19:46:07', 59, 0, 'VENTUSA', 'D', 'MASSAGE', 'JR.', '2025-10-01', '09123456711', 'USA', 'VENTUSA D MASSAGE JR.'),
(25, 'F', 31, 2, '2025-10-18 20:11:47', 59, 0, 'SOFIA', 'D', 'FIRST', 'JR', '2021-05-04', '09123456711', 'CASTLE', 'SOFIA D FIRST JR.'),
(26, 'F', 36, 4, '2025-10-18 20:13:12', 59, 0, 'MICHAEL', 'D', 'JACKSON', 'II', '2000-01-06', '09123456712', 'ALAMINOS PANGASINAN', 'MICHAEL D JACKSON II'),
(27, 'B', 34, 3, '2025-10-21 13:39:41', 59, 1, 'FLAPPY', '', 'BIRD', '', '1990-07-14', '09234394832', 'ALAMINOS CITY PANGASINAN', 'FLAPPY BIRD'),
(28, 'E', 17, 2, '2025-10-22 10:04:47', 59, 1, 'BENBEN', '', 'REVERA', '', '2013-02-06', '09513518973', 'CAYUCAY ALAMINOS CITY', 'BENBEN REVERA'),
(29, 'B', 40, 4, '2025-10-22 12:13:03', 59, 1, 'JAMES', '', 'GAR', '', '2020-05-11', '09234394832', 'ALAMINOS CITY PANGASINAN', 'JAMES GAR'),
(30, 'B', 36, 2, '2026-02-28 23:09:58', 61, 1, 'JAY', 'JOE', 'MIDE', '', '1991-02-02', '09282382173', 'MANGATAREM PANGASINAN', 'JAY JOE MIDE'),
(31, 'A', 29, 4, '2026-06-21 23:36:15', 64, 1, 'DIGONG', '', 'DUGS', '', '1990-12-10', '09123213213', 'CAMAGSINGALAN', 'DIGONG DUGS');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_recent_clicks`
--

CREATE TABLE `tbl_recent_clicks` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `section` varchar(10) DEFAULT NULL,
  `plot_id` int(11) DEFAULT NULL,
  `plot_code` varchar(50) DEFAULT NULL,
  `plot_x` varchar(20) DEFAULT NULL,
  `plot_y` varchar(20) DEFAULT NULL,
  `deceased_name` varchar(255) DEFAULT NULL,
  `details` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_recent_clicks`
--

INSERT INTO `tbl_recent_clicks` (`id`, `user_id`, `section`, `plot_id`, `plot_code`, `plot_x`, `plot_y`, `deceased_name`, `details`, `created_at`) VALUES
(1, 64, 'A', 10, 'A-010', '010', '', NULL, 'Opened plot A-010', '2026-06-23 23:13:59'),
(2, 64, 'C', NULL, NULL, '', '', NULL, 'Clicked section C', '2026-06-23 23:14:14'),
(3, 64, 'B', NULL, NULL, '', '', NULL, 'Clicked section B', '2026-06-23 23:15:04'),
(4, 64, 'B', NULL, 'B-50-2', '50', '2', 'KOBE A BRYAN', 'Clicked plot B-50-2', '2026-06-23 23:15:05'),
(5, 64, 'E', NULL, NULL, '', '', NULL, 'Clicked section E', '2026-06-23 23:15:33'),
(6, 64, 'C', NULL, NULL, '', '', NULL, 'Clicked section C', '2026-06-23 23:15:36'),
(7, 64, 'F', NULL, NULL, '', '', NULL, 'Clicked section F', '2026-06-23 23:15:40'),
(8, 64, 'D', NULL, NULL, '', '', NULL, 'Clicked section D', '2026-06-23 23:15:43'),
(9, 64, 'B', NULL, NULL, '', '', NULL, 'Clicked section B', '2026-06-23 23:17:19'),
(10, 64, 'A', NULL, NULL, '', '', NULL, 'Clicked section A', '2026-06-23 23:17:26'),
(11, 64, 'B', NULL, NULL, '', '', NULL, 'Clicked section B', '2026-06-23 23:17:30'),
(12, 64, 'A', 1, 'A-001', '001', '', NULL, 'Opened plot A-001', '2026-06-23 23:19:33'),
(13, 64, 'A', 1, 'A-001', '001', '', NULL, 'Opened plot A-001', '2026-06-23 23:20:26'),
(14, 64, 'A', 3, 'A-003', '003', '', NULL, 'Opened plot A-003', '2026-06-23 23:40:01'),
(15, 64, 'A', 3, 'A-003', '003', '', NULL, 'Opened plot A-003', '2026-06-23 23:42:03'),
(16, 64, 'A', 7, 'A-007', '007', '', NULL, 'Opened plot A-007', '2026-06-23 23:51:12'),
(17, 64, 'A', 6, 'A-006', '006', '', NULL, 'Opened plot A-006', '2026-06-23 23:51:24');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_user`
--

CREATE TABLE `tbl_user` (
  `user_id` int(11) NOT NULL,
  `role` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `suffix` varchar(50) NOT NULL,
  `birthday` date NOT NULL,
  `email` varchar(30) NOT NULL,
  `password` varchar(250) NOT NULL,
  `phone_no` varchar(15) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `account_number` varchar(20) DEFAULT NULL,
  `email_verified` tinyint(1) DEFAULT '0',
  `verification_token` varchar(255) DEFAULT NULL,
  `verification_otp` varchar(10) DEFAULT NULL,
  `otp_expiry` datetime DEFAULT NULL,
  `reset_otp` varchar(10) DEFAULT NULL,
  `reset_otp_expiry` datetime DEFAULT NULL,
  `verification_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `tbl_user`
--

INSERT INTO `tbl_user` (`user_id`, `role`, `first_name`, `middle_name`, `last_name`, `suffix`, `birthday`, `email`, `password`, `phone_no`, `profile_picture`, `account_number`, `email_verified`, `verification_token`, `verification_otp`, `otp_expiry`, `reset_otp`, `reset_otp_expiry`, `verification_expires`) VALUES
(61, 'ADMIN', 'Test User', '', 'TEST', '', '2021-06-01', 'jasper@gmail.com', '$2y$10$MZsmCoRgN3vqsTvOMSc6GOqLK45gBWIY15AvQdw7VX3FHSGkb18yi', '', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(65, '', 'JANELLE', '', 'OQUIN', '', '2005-07-10', 'oquin@gmail.com', '$2y$10$SdN8bMo17lY6C5kpTdxDNemR0NaKr7YFnDfXKb4vUngYwnqQ/Nye6', '09874213213', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(68, '', 'JASPER', '', 'SEVILLANO', '', '2005-06-01', 'jasper1@gmail.com', '$2y$10$J53d0UgHq7B8U90wO2TM4uLjNyVnr30ZOdP8xtIw2AAvRA5PKRUFO', '00000000001', 'uploads/profile_pictures/68_1783065124.jpg', NULL, 0, NULL, NULL, NULL, '521932', '2026-07-06 15:54:42', NULL),
(70, 'user', 'JASPER', '', 'SEVILLANO', '', '2005-06-01', 'lolipoopxex20@gmail.com', '$2y$10$uCtSiavwy0ppBhVI4lLaG.rsLU4idsSJvhwT0JzRpBI7c9GkOTqGa', '09605295663', NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL),
(71, 'user', 'JANELLE', '', 'OQUIN', '', '2005-06-22', 'jaspersevillano103@gmail.com', '$2y$10$gakNRQkPWk4aQPNROuhUV.EEVzPPNntdIV4fatYSI4RR2Q4f2W9qS', '09605295663', 'uploads/profile_pictures/71_1783598793.jpg', NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `deceased`
--
ALTER TABLE `deceased`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `plots`
--
ALTER TABLE `plots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `section_plot_unique` (`section_id`,`plot_no`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `section_name` (`section_name`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_bookings`
--
ALTER TABLE `tbl_bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `tbl_booking_logs`
--
ALTER TABLE `tbl_booking_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_booking_id` (`booking_id`),
  ADD KEY `idx_timestamp` (`timestamp`);

--
-- Indexes for table `tbl_deceased`
--
ALTER TABLE `tbl_deceased`
  ADD PRIMARY KEY (`deceased_id`);

--
-- Indexes for table `tbl_logs`
--
ALTER TABLE `tbl_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_plot_owners`
--
ALTER TABLE `tbl_plot_owners`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_plot` (`section`,`plot_x`,`plot_y`);

--
-- Indexes for table `tbl_recent_clicks`
--
ALTER TABLE `tbl_recent_clicks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_user`
--
ALTER TABLE `tbl_user`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `deceased`
--
ALTER TABLE `deceased`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plots`
--
ALTER TABLE `plots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=101;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tbl_bookings`
--
ALTER TABLE `tbl_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `tbl_booking_logs`
--
ALTER TABLE `tbl_booking_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_deceased`
--
ALTER TABLE `tbl_deceased`
  MODIFY `deceased_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=115;

--
-- AUTO_INCREMENT for table `tbl_logs`
--
ALTER TABLE `tbl_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT for table `tbl_plot_owners`
--
ALTER TABLE `tbl_plot_owners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `tbl_recent_clicks`
--
ALTER TABLE `tbl_recent_clicks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `tbl_user`
--
ALTER TABLE `tbl_user`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tbl_bookings`
--
ALTER TABLE `tbl_bookings`
  ADD CONSTRAINT `tbl_bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `tbl_user` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- phpMyAdmin SQL Dump
-- version 4.8.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 19, 2025 at 05:19 AM
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
(30, 'JOSE', 'george', 'RIZAL', '', '2025-09-04', 'LUNETA PARK ', '09123456789', '2025-09-27', 'GETHSEMANE FUNERAL SERVICE', 'C', 34, 4, NULL, 'JASPER SEVILLANO', ''),
(31, 'EMILIO', '', 'AGUINALDO', '', '2025-09-01', 'MABINI PANGASINAN', '0912345678', '2025-09-27', 'ABALOS FUNERAL', 'A', 3, 2, NULL, '0', ''),
(32, 'JOHN', '', 'CARLO', '', '2025-09-26', 'MABINI PANGASINAN', '09232345456', '2025-09-25', 'GETHSEMANE FUNERAL', 'A', 12, 1, NULL, '0', ''),
(37, 'PERSONAL', 'SAMMY', 'OLGAR', '', '2025-09-01', 'MABINI PANGASINAN', '09567899077', '2025-09-29', 'ABALOS FUNERAL', 'B', 28, 3, NULL, 'JASPER SEVILLANO', ''),
(42, 'SPONGEBOB', 'MOSHI', 'SQUAREPANTS', '', '2025-10-01', 'LUNETA PARK', '09123456789', '2025-10-17', 'GETHSEMANE FUNERAL', 'A', 18, 3, NULL, 'JASPER SEVILLANO', ''),
(44, 'TOM', 'AND', 'JERRY', 'III', '2025-10-01', 'MABINI PANGASINAN', '09232387654', '2025-10-17', 'GETHSEMANE FUNERAL', 'B', 31, 2, NULL, 'CHAD BASILA', ''),
(45, 'COURAGE', 'THE', 'COWARDLY DOG', 'JR.', '2001-12-04', 'MABINI PANGASINAN', '09123784645', '2025-06-08', 'GETHSEMANE', 'A', 13, 4, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(48, 'MICKEY', 'MOUSE', 'CLUBHOUSE', '', '1998-05-04', 'LUNETA PARK', '09112312443', '2025-02-07', 'QAAQ FUNERAL SERVICES', 'B', 47, 4, 'JASPER SEVILLANO', 'CHAD BASILA', ''),
(49, 'JOSE', 'A', 'RIZAL', 'JR.', '2025-10-03', 'LUNETA PARK', '09161523223', '2025-10-03', 'QAAQ FUNERAL SERVICES', 'A', 18, 3, 'JASPER SEVILLANO', 'ALJON LUCERO', ''),
(50, 'SOFIA', 'D', 'FIRST', 'JR', '2021-05-04', 'CASTLE', '09123456711', '2025-02-05', 'GETHSEMANE', 'F', 31, 2, 'JASPER SEVILLANO', 'CHAD BASILA', ''),
(57, 'SAMMY', 'M', 'ILBY', '', '2020-02-02', 'ALAMINOS CITY PANGASINAN', '09887645672', '2025-04-03', 'QAAQ FUNERAL SERVICES', 'F', 36, 2, 'JASPER SEVILLANO', 'CHAD BASILA', ''),
(61, 'ARTURO', 'D', 'NERY', 'JR.', '0060-10-02', 'LUNETA PARK', '1213123551', '1991-10-18', 'GETHSEMANE', 'A', 43, 1, 'JASPER SEVILLANO', 'CHAD BASILA', ''),
(62, 'RAUL', 'SECULO', 'MAGIBA', 'JR.', '1990-10-03', 'MALACANANG', '0917262623', '2025-03-04', 'ABALOS', 'B', 7, 1, 'JASPER SEVILLANO', '', ''),
(63, 'JOMAR', 'D', 'ACE', 'IV', '1980-10-09', 'MALACANANG', '0189182823', '2025-05-04', 'ABALOS FUNERAL', 'A', 18, 3, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(64, 'JANNELLEIE', 'JA', 'JAJA', 'SR.', '1950-04-03', 'MABINI PANGASINAN', '09123812412', '2025-03-05', 'GARDEN REMEMBRANCE', 'A', 51, 5, 'CHAD BASILA', 'JASPER SEVILLANO', ''),
(65, 'JEYMS', 'S', 'SALDE', 'V', '2005-04-03', 'ALAMINOS CITY PANGASINAN', '091891273322', '2025-06-06', 'GETHSEMANE FUNERAL SERVICE', 'B', 40, 4, 'CHAD BASILA', 'CHAD BASILA', ''),
(66, 'KOBE', 'A', 'BRYAN', 'JR.', '1980-02-03', 'USA', '01927363611', '2020-08-08', 'GETHSEMANE FUNERAL SERVICE', 'B', 50, 2, 'CHAD BASILA', 'CHAD BASILA', ''),
(67, 'JHONNY', 'V', 'BRAVO', 'V', '2025-10-03', 'MABINI PANGASINAN', '09475698674', '2025-10-18', 'GETHSEMANE FUNERAL SERVICE', 'F', 35, 4, 'CHAD BASILA', 'CHAD BASILA', ''),
(68, 'KOLOKOYS', '', 'BASILO', 'JR.', '2025-10-04', 'MABINI PANGASINAN', '09454580323', '2025-10-18', 'ABALOS', 'A', 31, 3, 'CHAD BASILA', 'JASPER SEVILLANO', ''),
(69, 'WALLY', 'B', 'BAYOLA', 'JR.', '2025-10-01', 'ALAMINOS CITY PANGASINAN', '09388878477', '2025-10-18', 'GETHSEMANE FUNERAL SERVICE', 'A', 18, 3, 'CHAD BASILA', 'ALJON LUCERO', ''),
(70, 'SIRAULO', 'D', 'MAGIBA', 'JR.', '2025-10-01', 'MABINI PANGASINAN', '09123456789', '2025-10-18', 'GETHSEMANE FUNERAL SERVICE', 'B', 7, 1, 'CHAD BASILA', 'CHAD BASILA', ''),
(71, 'NANDO', 'Z', 'AGILA', '', '2025-10-01', 'CASTLE', '09808037483', '2025-10-18', 'GETHSEMANE FUNERAL SERVICE', 'B', 16, 4, 'CHAD BASILA', 'JASPER SEVILLANO', ''),
(72, 'HALAMAN', 'S', 'MABINI', '', '2025-10-02', 'USA', '09344345769', '2025-10-18', 'GETHSEMANE FUNERAL SERVICE', 'B', 1, 1, 'CHAD BASILA', 'CHAD BASILA', ''),
(73, 'MARIE', 'S', 'JUANA', 'SR.', '2021-02-18', 'LUNETA PARK', '09454756756', '2025-10-18', 'ABALOS FUNERAL', 'B', 1, 1, 'CHAD BASILA', 'CHAD BASILA', ''),
(74, 'MARIANO', 'SABEL', 'JUNIOR', '', '2021-02-18', 'MANILA CITY', '09288642520', '2025-10-18', 'GETHSEMANE FUNERAL SERVICE', 'B', 24, 4, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(75, 'WWED', 'EFERF', 'FRR', '', '2025-10-04', 'MABINI PANGASINAN', '09123456789', '2025-10-18', 'ABALOS', 'B', 53, 3, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(76, 'VENTUSA', 'D', 'MASSAGE', 'JR.', '2025-10-01', 'USA', '09656867548', '2025-10-16', 'GETHSEMANE FUNERAL', 'B', 50, 2, 'CHAD BASILA', 'CHAD BASILA', ''),
(78, 'MICHAEL', 'D', 'JACKSON', 'II', '2000-06-01', 'ALAMINOS CITY PANGASINAN', '09123456711', '2020-05-04', 'ABALOS', 'F', 36, 4, 'CHAD BASILA', 'CHAD BASILA', ''),
(79, 'JACKSON', 'S', 'STORM', '', '2005-02-09', 'MABINI PANGASINAN', '09123456789', '2025-06-05', 'GARDEN REMEMBRANCE', 'F', 36, 4, 'CHAD BASILA', 'CHAD BASILA', '');

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
(3, 'B', 38, 1, '2025-10-18 01:39:42', 61, 1, 'RICHARD', 'T', 'BASILA', 'JR', '2025-10-01', '09453867433', 'DSADWDW', 'RICHARD T BASILA JR'),
(6, 'B', 37, 3, '2025-10-18 11:31:10', 59, 1, 'MICKEY', 'MINNEY', 'MOUSE', 'JR', '2025-03-01', '09812623612', 'ALAMINOS PANGASINAN', 'MICKEY MINNEY MOUSE JR'),
(7, 'B', 39, 3, '2025-10-18 11:44:10', 59, 1, 'KOBE', 'A', 'BRYAN', 'JR', '1980-02-03', '01927363611', 'USA', 'KOBE A BRYAN JR'),
(9, 'B', 49, 3, '2025-10-18 11:48:02', 59, 1, 'MCDO', 'CHOWKING', 'INASAL', '', '2021-02-27', '09342596845', 'MABINI PANGASINAN', 'MCDO CHOWKING INASAL'),
(11, 'B', 43, 4, '2025-10-18 12:03:34', 59, 1, 'TOM', 'AND', 'JERRY', 'III', '2025-10-01', '09232387654', 'MABINI PANGASINAN', 'TOM AND JERRY III'),
(13, 'C', 27, 6, '2025-10-18 12:43:16', 59, 1, 'SOFIA', 'D', 'FIRST', 'JR', '2021-05-04', '09123456711', 'CASTLE', 'SOFIA D FIRST JR'),
(14, 'F', 35, 4, '2025-10-18 12:52:26', 59, 0, 'JHONNY', 'V', 'BRAVO', 'V', '2025-10-03', '09475698674', 'MABINI PANGASINAN', 'JHONNY V BRAVO V'),
(15, 'B', 31, 2, '2025-10-18 13:09:54', 59, 0, 'KOLOKOYS', '', 'BASILO', 'JR.', '2025-10-04', '09454580323', 'MABINI PANGASINAN', 'KOLOKOYS BASILO JR.'),
(16, 'B', 21, 1, '2025-10-18 13:45:06', 59, 0, 'WALLY', 'B', 'BAYOLA', 'JR.', '2025-10-01', '09388878477', 'ALAMINOS CITY PANGASINAN', 'WALLY B BAYOLA JR.'),
(17, 'B', 35, 4, '2025-10-18 13:54:46', 59, 0, 'NANDO', 'Z', 'AGILA', '', '2025-10-01', '09808037483', 'CASTLE', 'NANDO Z AGILA'),
(19, 'B', 28, 3, '2025-10-18 17:39:52', 61, 0, 'PERSONAL', 'SAMMY', 'OLGAR', '', '2025-09-01', '09567899077', 'MABINI PANGASINAN', 'PERSONAL SAMMY OLGAR'),
(21, 'B', 53, 3, '2025-10-18 19:30:41', 61, 1, 'PABLO', 'HUMAN', 'CRUZ', 'V', '2025-10-01', '05869456756', 'BAGUIO CITY', 'PABLO HUMAN CRUZ V'),
(22, 'B', 50, 2, '2025-10-18 19:46:07', 59, 0, 'VENTUSA', 'D', 'MASSAGE', 'JR.', '2025-10-01', '09123456711', 'USA', 'VENTUSA D MASSAGE JR.'),
(25, 'F', 31, 2, '2025-10-18 20:11:47', 59, 0, 'SOFIA', 'D', 'FIRST', 'JR', '2021-05-04', '09123456711', 'CASTLE', 'SOFIA D FIRST JR.'),
(26, 'F', 36, 4, '2025-10-18 20:13:12', 59, 0, 'MICHAEL', 'D', 'JACKSON', 'II', '2000-01-06', '09123456712', 'ALAMINOS PANGASINAN', 'MICHAEL D JACKSON II');

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
  `phone_no` varchar(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `tbl_user`
--

INSERT INTO `tbl_user` (`user_id`, `role`, `first_name`, `middle_name`, `last_name`, `suffix`, `birthday`, `email`, `password`, `phone_no`) VALUES
(59, '', 'CHAD', '', 'BASILA', '', '2005-12-09', 'chad@gmail.com', '$2y$10$9qHQn8r9y0TqgJ754bXKjOqir4iEXEPo0/NCwZvVX8TtfeaqpZLp6', '2147483647'),
(61, '', 'JASPER', '', 'SEVILLANO', '', '2021-06-01', 'jasper@gmail.com', '$2y$10$ICzeo/AmeZ4GWyU/e/zEOenFfl/fwcKp7ujfZeaHeKoBu2/pP3TXa', '2147483647'),
(63, '', 'ALJON', 'DALINA', 'LUCERO', '', '1999-01-05', 'aljon@gmail.com', '$2y$10$6x.zSEDkPPdCmL386TP2r.zCAtVq5ZfmPx87pnplSQNsT6T02tW/q', '09055539237');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `deceased`
--
ALTER TABLE `deceased`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_deceased`
--
ALTER TABLE `tbl_deceased`
  ADD PRIMARY KEY (`deceased_id`);

--
-- Indexes for table `tbl_plot_owners`
--
ALTER TABLE `tbl_plot_owners`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_plot` (`section`,`plot_x`,`plot_y`);

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
-- AUTO_INCREMENT for table `tbl_deceased`
--
ALTER TABLE `tbl_deceased`
  MODIFY `deceased_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=80;

--
-- AUTO_INCREMENT for table `tbl_plot_owners`
--
ALTER TABLE `tbl_plot_owners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `tbl_user`
--
ALTER TABLE `tbl_user`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

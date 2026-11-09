-- phpMyAdmin SQL Dump
-- version 4.8.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 18, 2025 at 11:52 AM
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
(30, 'JOSE', 'george', 'RIZAL', '', '2025-09-04', 'LUNETA PARK ', '09123456789', '2025-09-27', 'GETHSEMANE FUNERAL SERVICE', 'B', 18, 4, NULL, 'CHAD BASILA', ''),
(31, 'EMILIO', '', 'AGUINALDO', '', '2025-09-01', 'MABINI PANGASINAN', '0912345678', '2025-09-27', 'ABALOS FUNERAL', 'A', 3, 2, NULL, '0', ''),
(32, 'JOHN', '', 'CARLO', '', '2025-09-26', 'MABINI PANGASINAN', '09232345456', '2025-09-25', 'GETHSEMANE FUNERAL', 'A', 12, 1, NULL, '0', ''),
(33, 'SAMMY', 'BUWAKAL', 'MONKEYFOX', '', '2021-01-01', 'MABINI PANGASINAN', '0988764567', '2025-09-26', 'ABALOS FUNERALSES', 'A', 18, 3, NULL, 'CHAD BASILA', ''),
(34, 'BABY', '', 'KO', '', '2021-01-25', 'MABINI PANGASINAN', '09343456789', '2025-09-27', 'ABALOS FUNERAL', 'C', 6, 4, NULL, 'CHAD BASILA', ''),
(35, 'MINDREAMER', 'corpural', 'BREAKERS', '', '2010-01-19', 'MABINI PANGASINAN', '09232387654', '2025-09-28', 'ABALOS', 'A', 18, 3, NULL, 'JASPER SEVILLANO', ''),
(36, 'RABIESag', '', 'DOGSyy', '', '2024-02-29', 'MABINI PANGASINAN', '09223267744', '2025-09-29', 'GETHSEMANE FUNERAL', 'b', 15, 3, NULL, 'CHAD BASILA', ''),
(37, 'PERSONAL', 'SAMMY', 'OLGAR', '', '2025-09-01', 'MABINI PANGASINAN', '0956789907', '2025-09-29', 'ABALOS FUNERAL', 'B', 10, 1, NULL, 'CHAD BASILA', ''),
(39, 'POGS', '', 'SAKIT', '', '2005-04-12', 'BIRINGAN CITY', '09999999999', '2025-09-29', 'ABALOS', 'A', 3, 4, NULL, 'ALJON LUCERO', ''),
(41, 'ALJAMES', 'BUWAYA', 'NOEL', '', '2025-10-01', 'MABINI PANGASINAN', '09657896432', '2025-10-18', 'GETHSEMANE FUNERAL SERVICE', 'F', 36, 4, NULL, NULL, ''),
(42, 'SPONGEBOB', 'MOSHI', 'SQUAREPANTS', '', '2025-10-01', 'LUNETA PARK', '09123456789', '2025-10-17', 'GETHSEMANE FUNERAL', 'A', 18, 3, NULL, 'JASPER SEVILLANO', ''),
(43, 'PATRICK', 'BUWAYA', 'HOTDOG', 'SR.', '2025-10-01', 'MABINI PANGASINAN', '0988764567', '2025-10-17', 'ABALOS FUNERAL', 'B', 37, 2, NULL, 'JASPER SEVILLANO', ''),
(44, 'TOM', 'AND', 'JERRY', 'III', '2025-10-01', 'MABINI PANGASINAN', '09232387654', '2025-10-17', 'GETHSEMANE FUNERAL', 'B', 5, 3, NULL, 'JASPER SEVILLANO', ''),
(45, 'COURAGE', 'THE', 'COWARDLY DOG', 'JR.', '2001-12-04', 'MABINI PANGASINAN', '09123784645', '2025-06-08', 'GETHSEMANE', 'A', 13, 4, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(46, 'MARCELINE', 'D', 'MAGIBA', 'V', '2000-01-03', 'LUNETA PARK', '09125637126', '2025-01-06', 'ABALOS FUNERAL', 'B', 8, 4, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(47, 'MCDO', 'CHOWKING', 'INASAL', '', '2021-02-27', 'MABINI PANGASINAN', '09342596845', '2025-09-20', 'GARDEN REMEMBRANCE', 'B', 34, 2, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(48, 'MICKEY', 'MOUSE', 'CLUBHOUSE', '', '1998-05-04', 'LUNETA PARK', '09112312443', '2025-02-07', 'QAAQ FUNERAL SERVICES', 'B', 15, 3, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(49, 'JOSE', 'A', 'RIZAL', 'JR.', '2025-10-03', 'LUNETA PARK', '09161523223', '2025-10-03', 'QAAQ FUNERAL SERVICES', 'A', 17, 1, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(50, 'SOFIA', 'D', 'FIRST', 'JR.', '2021-05-04', 'CASTLE', '09123456711', '2025-02-05', 'GETHSEMANE', 'B', 21, 2, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(56, 'KILLER', 'S', 'MILE', 'II', '1980-04-01', 'LUNETA PARK', '12345678901', '2025-02-09', 'ABALOS FUNERAL', 'B', 21, 2, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(57, 'SAM', 'M', 'ILBY', '', '2020-02-02', 'ALAMINOS CITY PANGASINAN', '09887645672', '2025-04-03', 'QAAQ FUNERAL SERVICES', 'A', 46, 1, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(58, 'KILL', 'BILL', 'BILL', 'JR.', '2021-12-03', 'LUNETA PARK', '019123124124', '2025-01-02', 'GARDEN REMEMBRANCE', 'A', 18, 3, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(59, 'EACAKES', 'RABANAL', 'HAHAHA', 'JR.', '2025-10-01', 'LUNETA PARK', '09232387654', '2025-10-18', 'GETHSEMANE FUNERAL', 'A', 18, 2, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(60, 'NICKY', 'SWABE', 'BESY', 'JR.', '2025-10-01', 'BATANGAS CITY', '09346899999', '2025-10-18', 'GARDEN REMEMBRANCE', 'D', 3, 4, 'JASPER SEVILLANO', 'JASPER SEVILLANO', ''),
(61, 'ARTURO', 'D', 'NERY', 'JR.', '0060-10-02', 'LUNETA PARK', '1213123551', '1991-10-18', 'GETHSEMANE', 'A', 20, 1, 'JASPER SEVILLANO', '', ''),
(62, 'RAUL', 'SECULO', 'MAGIBA', 'JR.', '1990-10-03', 'MALACANANG', '0917262623', '2025-03-04', 'ABALOS', 'B', 7, 1, 'JASPER SEVILLANO', '', ''),
(63, 'JOMAR', 'D', 'ACE', 'IV', '1980-10-09', 'MALACANANG', '0189182823', '2025-05-04', 'ABALOS FUNERAL', 'A', 18, 3, 'JASPER SEVILLANO', 'JASPER SEVILLANO', '');

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
(4, 'B', 37, 2, '2025-10-18 01:47:05', 61, 1, 'ALJON', 'A', 'LUCERO', 'SR', '2025-10-01', '09675435476', 'DSADWD', 'ALJON A LUCERO SR'),
(5, 'B', 33, 3, '2025-10-18 02:06:56', 61, 1, 'AYAW', 'KONA', 'PO', 'SR', '2025-10-01', '09238484584', 'DSADWD', 'AYAW KONA PO SR');

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
  `phone_no` int(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `tbl_user`
--

INSERT INTO `tbl_user` (`user_id`, `role`, `first_name`, `middle_name`, `last_name`, `suffix`, `birthday`, `email`, `password`, `phone_no`) VALUES
(58, '', 'ADMIN', '', '1', '', '2005-09-16', 'richardjamestolibas@gmail.com', '$2y$10$4KSBi5OQJDCR2HyKCVszoOgbnD0VVUakgNX8.DligGrCwT9tDMYaO', 2147483647),
(59, '', 'CHAD', '', 'BASILA', '', '2005-12-09', 'chad@gmail.com', '$2y$10$9qHQn8r9y0TqgJ754bXKjOqir4iEXEPo0/NCwZvVX8TtfeaqpZLp6', 2147483647),
(60, '', 'ALJON', '', 'LUCERO', '', '2005-11-02', 'aljon123@gmail.com', '$2y$10$JGlmtyxk5iqaWj9rK3v0EOwGkcaB.yNuqthw71WR3FHcgnOyueJZG', 912345678),
(61, '', 'JASPER', '', 'SEVILLANO', '', '2021-06-01', 'jasper@gmail.com', '$2y$10$ICzeo/AmeZ4GWyU/e/zEOenFfl/fwcKp7ujfZeaHeKoBu2/pP3TXa', 2147483647);

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
  MODIFY `deceased_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `tbl_plot_owners`
--
ALTER TABLE `tbl_plot_owners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tbl_user`
--
ALTER TABLE `tbl_user`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

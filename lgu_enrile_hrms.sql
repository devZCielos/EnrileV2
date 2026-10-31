-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 29, 2026 at 06:51 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `lgu_enrile_hrms`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `log_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(200) NOT NULL,
  `module` varchar(60) DEFAULT NULL,
  `reference_id` int(10) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`log_id`, `user_id`, `action`, `module`, `reference_id`, `ip_address`, `created_at`) VALUES
(1, 1, 'Approved leave request for Maria Reyes (Sick Leave)', 'Leave Requests', NULL, NULL, '2026-03-09 16:22:10'),
(2, 1, 'Added new employee: Ramon Dela Lopez (EMP-010)', 'Employees', NULL, NULL, '2026-03-09 16:22:10'),
(3, 1, 'Posted announcement: Leave Filing Deadline', 'Announcements', NULL, NULL, '2026-03-09 16:22:10'),
(4, 1, 'Denied leave request for Maria Reyes (Vacation Leave)', 'Leave Requests', NULL, NULL, '2026-03-09 16:22:10'),
(5, 1, 'Updated department: Finance Office', 'Departments', NULL, NULL, '2026-03-09 16:22:10'),
(6, 1, 'Approved leave request for Maria Reyes (Sick Leave)', 'Leave Requests', NULL, NULL, '2026-03-09 16:22:58'),
(7, 1, 'Added new employee: Ramon Dela Lopez (EMP-010)', 'Employees', NULL, NULL, '2026-03-09 16:22:58'),
(8, 1, 'Posted announcement: Leave Filing Deadline', 'Announcements', NULL, NULL, '2026-03-09 16:22:58'),
(9, 1, 'Denied leave request for Maria Reyes (Vacation Leave)', 'Leave Requests', NULL, NULL, '2026-03-09 16:22:58'),
(10, 1, 'Updated department: Finance Office', 'Departments', NULL, NULL, '2026-03-09 16:22:58'),
(11, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-09 16:25:33'),
(12, NULL, 'Logged in: mreyes@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-09 16:26:51'),
(13, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-09 16:35:33'),
(14, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-09 23:21:47'),
(15, NULL, 'Logged in: mreyes@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-09 23:34:24'),
(16, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-09 23:53:28'),
(17, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-10 02:25:10'),
(18, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-10 02:38:14'),
(19, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-10 04:25:41'),
(20, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-10 04:29:43'),
(21, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-10 04:39:04'),
(22, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-10 04:53:36'),
(23, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-10 05:00:50'),
(24, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-10 05:06:46'),
(25, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-10 05:43:50'),
(26, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-10 09:21:39'),
(27, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-10 09:26:49'),
(28, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-10 09:33:31'),
(29, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-10 10:10:52'),
(30, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-10 10:24:03'),
(31, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-10 10:24:37'),
(32, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-10 10:25:38'),
(33, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-10 10:31:12'),
(34, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-10 10:39:57'),
(35, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-10 12:40:48'),
(36, NULL, 'Logged in: zyberjohnc@gmail.com', 'Login', NULL, '::1', '2026-03-10 12:46:04'),
(37, NULL, 'Logged in: zyberjohnc@gmail.com', 'Login', NULL, '::1', '2026-03-10 12:48:20'),
(38, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-10 12:49:12'),
(39, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-11 03:08:00'),
(40, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 03:08:47'),
(41, NULL, 'Logged in: zyberjohnc@gmail.com', 'Login', NULL, '::1', '2026-03-11 03:10:23'),
(42, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-11 03:13:06'),
(43, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 03:14:05'),
(44, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 03:19:10'),
(45, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 04:41:31'),
(46, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 04:49:01'),
(47, NULL, 'Logged in: zyberjohnc@gmail.com', 'Login', NULL, '::1', '2026-03-11 04:51:59'),
(48, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-11 04:55:20'),
(49, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 04:57:52'),
(50, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 05:06:10'),
(51, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 05:07:31'),
(52, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 05:14:25'),
(53, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 05:17:54'),
(54, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-11 05:38:39'),
(55, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-11 06:03:08'),
(56, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 06:03:50'),
(57, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-11 06:06:37'),
(58, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-11 06:13:12'),
(59, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 06:37:51'),
(60, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 06:39:56'),
(61, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-11 06:40:35'),
(62, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-11 07:17:09'),
(63, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 07:17:32'),
(64, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 08:20:25'),
(65, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-11 08:23:30'),
(66, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 10:57:30'),
(67, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-11 23:52:53'),
(68, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-11 23:53:15'),
(69, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-12 05:55:48'),
(70, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-12 05:56:06'),
(71, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-12 07:00:22'),
(72, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-12 07:23:08'),
(73, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-12 08:47:24'),
(74, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-13 00:48:54'),
(75, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-13 00:49:19'),
(76, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-13 02:43:19'),
(77, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-13 02:46:52'),
(78, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-13 03:02:41'),
(79, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-13 03:33:59'),
(80, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-13 05:58:27'),
(81, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-13 06:44:34'),
(82, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-13 07:19:04'),
(83, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-13 07:20:04'),
(84, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-13 07:20:28'),
(85, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-13 14:09:02'),
(86, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-13 14:09:38'),
(87, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-13 14:09:57'),
(88, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-13 14:30:45'),
(89, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-13 14:30:58'),
(90, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-13 15:25:29'),
(91, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-14 01:16:22'),
(92, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-14 06:18:23'),
(93, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-14 06:18:43'),
(94, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-14 07:03:18'),
(95, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-14 12:35:39'),
(96, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-14 12:38:10'),
(97, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-14 13:47:51'),
(98, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-14 14:08:38'),
(99, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-14 14:16:07'),
(100, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-14 23:52:44'),
(101, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-14 23:53:16'),
(102, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-15 00:06:39'),
(103, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-15 04:44:25'),
(104, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-15 04:53:01'),
(105, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-15 13:55:14'),
(106, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-15 13:55:36'),
(107, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-16 04:15:34'),
(108, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-16 04:16:28'),
(109, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-16 05:05:02'),
(110, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-16 05:06:19'),
(111, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-16 05:40:05'),
(112, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-16 07:01:23'),
(113, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-16 07:01:46'),
(114, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-16 07:19:55'),
(115, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-16 08:29:53'),
(116, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-16 08:30:10'),
(117, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-17 02:04:05'),
(118, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-17 02:06:25'),
(119, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-17 03:15:40'),
(120, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-17 07:02:07'),
(121, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-17 07:15:38'),
(122, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-17 09:42:39'),
(123, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-18 07:41:15'),
(124, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-18 07:41:34'),
(125, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-21 03:09:17'),
(126, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-21 03:09:31'),
(127, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-21 04:27:06'),
(128, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-21 04:54:34'),
(129, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-21 04:55:08'),
(130, 1, 'Approved leave request for Maria Reyes (Sick Leave)', 'Leave Requests', NULL, NULL, '2026-03-21 05:05:14'),
(131, 1, 'Added new employee: Ramon Dela Lopez (EMP-010)', 'Employees', NULL, NULL, '2026-03-21 05:05:14'),
(132, 1, 'Posted announcement: Leave Filing Deadline', 'Announcements', NULL, NULL, '2026-03-21 05:05:14'),
(133, 1, 'Denied leave request for Maria Reyes (Vacation Leave)', 'Leave Requests', NULL, NULL, '2026-03-21 05:05:14'),
(134, 1, 'Updated department: Finance Office', 'Departments', NULL, NULL, '2026-03-21 05:05:14'),
(135, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-22 15:08:32'),
(136, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-22 15:08:52'),
(137, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-22 15:58:12'),
(138, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-22 16:01:52'),
(139, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-22 16:02:24'),
(140, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-22 16:23:42'),
(141, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-22 17:16:35'),
(142, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-22 17:37:25'),
(143, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-23 02:10:50'),
(144, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-23 02:11:03'),
(145, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-23 03:29:56'),
(146, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-23 03:36:01'),
(147, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-23 03:41:33'),
(148, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-23 08:42:39'),
(149, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-23 08:42:54'),
(150, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-23 10:37:07'),
(151, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-23 11:11:07'),
(152, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-23 23:32:32'),
(153, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-23 23:33:52'),
(154, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-24 01:24:30'),
(155, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-24 04:45:55'),
(156, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-24 04:46:28'),
(157, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-24 04:47:00'),
(158, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-24 04:50:55'),
(159, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-24 06:05:20'),
(160, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-25 08:47:41'),
(161, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-25 23:54:41'),
(162, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-25 23:54:58'),
(163, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-26 01:31:56'),
(164, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-26 01:50:48'),
(165, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-26 01:58:52'),
(166, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-26 02:00:29'),
(167, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-26 02:14:59'),
(168, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-26 02:17:39'),
(169, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2027-03-26 04:44:47'),
(170, NULL, 'Password reset requested', 'Auth', NULL, '::1', '2026-03-26 05:06:43'),
(171, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-26 06:22:04'),
(172, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-26 12:05:43'),
(173, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-26 14:30:56'),
(174, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-26 23:43:08'),
(175, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-26 23:43:53'),
(176, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-27 13:49:13'),
(177, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-27 13:49:53'),
(178, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-27 15:25:33'),
(179, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-27 15:43:02'),
(180, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-27 17:39:22'),
(181, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-27 17:39:41'),
(182, NULL, 'Password reset requested', 'Auth', NULL, '::1', '2026-03-27 18:51:58'),
(183, NULL, 'Password reset requested', 'Auth', NULL, '::1', '2026-03-27 18:52:31'),
(184, NULL, 'Password reset requested', 'Auth', NULL, '::1', '2026-03-27 18:55:22'),
(185, NULL, 'Password reset requested', 'Auth', NULL, '::1', '2026-03-27 18:57:03'),
(186, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-28 00:28:20'),
(187, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-28 00:30:43'),
(188, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-28 01:34:12'),
(189, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-28 01:35:07'),
(190, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-28 01:53:39'),
(191, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-28 04:25:12'),
(192, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-28 04:33:01'),
(193, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-28 04:38:33'),
(194, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-03-28 04:47:07'),
(195, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-28 04:47:52'),
(196, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-28 04:58:47'),
(197, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-28 05:31:35'),
(198, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-28 05:32:12'),
(199, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-03-28 07:04:34'),
(200, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-28 07:13:12'),
(201, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-28 14:29:43'),
(202, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-29 03:14:14'),
(203, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-29 03:15:33'),
(204, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-29 13:06:51'),
(205, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-29 14:49:54'),
(206, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-03-29 17:14:54'),
(207, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-29 17:37:50'),
(208, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-30 06:42:45'),
(209, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-30 06:43:05'),
(210, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-30 10:29:25'),
(211, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-30 10:51:23'),
(212, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-30 12:13:07'),
(213, NULL, 'Password reset requested', 'Auth', NULL, '::1', '2026-03-30 12:13:39'),
(214, NULL, 'Password reset successfully', 'Auth', NULL, '::1', '2026-03-30 12:14:27'),
(215, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-03-30 12:14:48'),
(216, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-30 12:17:46'),
(217, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-03-30 13:01:19'),
(218, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-03-30 14:41:56'),
(219, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-30 14:46:40'),
(220, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-30 23:21:53'),
(221, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-30 23:22:10'),
(222, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-03-31 06:36:45'),
(223, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-31 07:49:30'),
(224, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-03-31 08:21:34'),
(225, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-03-31 09:08:44'),
(226, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-01 10:34:22'),
(227, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-01 10:50:17'),
(228, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-02 03:15:17'),
(229, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-02 06:20:18'),
(230, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-02 06:28:48'),
(231, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-02 06:31:10'),
(232, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-02 06:31:41'),
(233, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-02 06:40:43'),
(234, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-02 07:26:08'),
(235, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-02 07:50:57'),
(236, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-02 08:07:02'),
(237, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-03 09:59:38'),
(238, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-03 10:00:29'),
(239, NULL, 'Logged in: Johnkervin@gmail.com', 'Login', NULL, '::1', '2026-04-03 11:45:01'),
(240, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-03 13:12:56'),
(241, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-06 03:24:44'),
(242, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-06 03:25:28'),
(243, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-06 08:42:14'),
(244, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-06 08:45:13'),
(245, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-06 08:49:05'),
(246, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-06 09:19:09'),
(247, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-06 12:27:59'),
(248, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-06 12:33:54'),
(249, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-06 14:12:10'),
(250, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-07 01:05:24'),
(251, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-07 01:05:53'),
(252, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-07 11:50:17'),
(253, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-07 11:50:33'),
(254, NULL, 'Logged in: lfernandez@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-07 12:33:44'),
(255, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-07 12:35:17'),
(256, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-08 00:52:53'),
(257, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-08 00:56:24'),
(258, NULL, 'Logged in: lfernandez@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-08 01:28:22'),
(259, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-04-08 04:48:37'),
(260, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-08 04:51:20'),
(261, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-08 05:01:18'),
(262, NULL, 'Logged in: lfernandez@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-08 09:27:40'),
(263, NULL, 'Logged in: lfernandez@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-08 10:15:09'),
(264, NULL, 'Password reset requested', 'Auth', NULL, '::1', '2026-04-08 10:18:38'),
(265, NULL, 'Password reset successfully', 'Auth', NULL, '::1', '2026-04-08 10:19:07'),
(266, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-08 10:19:33'),
(267, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-08 10:26:08'),
(268, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-08 10:27:01'),
(269, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-08 10:27:30'),
(270, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-08 10:31:34'),
(271, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-08 11:13:36'),
(272, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-08 13:54:45'),
(273, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-08 13:55:05'),
(274, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-08 13:57:52'),
(275, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-08 15:21:56'),
(276, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-08 18:51:32'),
(277, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-09 07:12:41'),
(278, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-09 07:13:01'),
(279, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-09 07:15:17'),
(280, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-09 07:16:39'),
(281, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-09 07:17:37'),
(282, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-09 08:25:16'),
(283, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-09 14:00:44'),
(284, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-09 14:01:17'),
(285, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-09 14:01:57'),
(286, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-04-09 16:22:24'),
(287, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-09 17:29:47'),
(288, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-09 20:25:49'),
(289, NULL, 'Password reset requested', 'Auth', NULL, '::1', '2026-04-09 20:37:50'),
(290, NULL, 'Password reset successfully', 'Auth', NULL, '::1', '2026-04-09 20:38:28'),
(291, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-04-09 20:38:44'),
(292, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-09 22:03:15'),
(293, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-09 22:25:59'),
(294, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-04-09 22:26:47'),
(295, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-09 22:27:09'),
(296, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-09 22:30:17'),
(297, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-09 22:31:23'),
(298, NULL, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-09 23:43:56'),
(299, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-09 23:44:15'),
(300, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-09 23:44:43'),
(301, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-04-10 00:55:18'),
(302, NULL, 'Logged in: nicole@gmail.com', 'Login', NULL, '::1', '2026-04-10 01:55:07'),
(303, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-04-10 03:28:00'),
(304, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-10 03:28:18'),
(305, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-10 15:01:32'),
(306, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-10 17:34:28'),
(307, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-04-10 17:36:02'),
(308, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-10 17:37:07'),
(309, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-11 03:13:42'),
(310, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-11 04:13:28'),
(311, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-11 07:31:52'),
(312, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-11 07:33:19'),
(313, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-04-11 07:35:54'),
(314, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-11 12:13:08'),
(315, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-04-11 12:14:10'),
(316, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-04-11 12:31:54'),
(317, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-11 14:35:53'),
(318, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-11 15:03:38'),
(319, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-04-11 15:22:26'),
(320, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-11 16:19:47'),
(321, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-12 04:02:12'),
(322, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-04-12 04:02:24'),
(323, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-12 04:02:49'),
(324, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-12 04:40:19'),
(325, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-12 04:42:37'),
(326, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '127.0.0.1', '2026-04-12 06:02:14'),
(327, NULL, 'Logged in: zyberjohncielos@gmail.com', 'Login', NULL, '::1', '2026-04-12 06:02:38'),
(328, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-12 06:03:04'),
(329, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '127.0.0.1', '2026-04-12 07:42:56'),
(330, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-12 08:01:46'),
(331, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-12 08:25:13'),
(332, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-12 08:25:30'),
(333, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-04-12 09:03:44'),
(334, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-14 01:27:32'),
(335, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-04-14 01:35:24'),
(336, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-04-14 01:35:55'),
(337, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-05-04 12:14:59'),
(338, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-05-04 12:16:09'),
(339, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-05-04 12:25:30'),
(340, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-05-04 12:25:43'),
(341, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-05-04 13:00:59'),
(342, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-05-04 13:01:55'),
(343, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-05-04 13:04:16'),
(344, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-05-04 13:23:56'),
(345, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-05-04 13:24:13'),
(346, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-05-04 13:25:11'),
(347, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-05-05 12:53:27'),
(348, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-05-05 12:54:03'),
(349, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-05-05 12:54:21'),
(350, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-05-05 16:42:12'),
(351, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-05-05 16:42:40'),
(352, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-05-05 16:43:15'),
(353, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-05-06 04:30:48'),
(354, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-05-06 04:30:59'),
(355, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-05-06 04:31:40'),
(356, NULL, 'Password reset requested', 'Auth', NULL, '::1', '2026-05-06 04:38:42'),
(357, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-05-06 06:44:12'),
(358, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-05-06 06:44:50'),
(359, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-05-06 06:45:27'),
(360, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-05-07 05:38:26'),
(361, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-05-07 05:38:42'),
(362, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-05-07 06:03:59'),
(363, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-05-07 07:08:36'),
(364, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-05-12 04:45:21'),
(365, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-05-12 04:45:45'),
(366, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-05-12 04:47:00'),
(367, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-05-12 04:47:32'),
(368, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-05-13 04:46:52'),
(369, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-05-13 04:47:39'),
(370, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-05-13 04:52:36'),
(371, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-27 04:35:16'),
(372, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-27 04:36:01'),
(373, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-06-27 04:36:31'),
(374, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-27 12:25:19'),
(375, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-06-27 12:41:45'),
(376, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-27 13:38:49'),
(377, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-28 04:42:27'),
(378, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-28 04:43:02'),
(379, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-06-28 04:43:12'),
(380, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-29 00:12:33'),
(381, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-29 00:15:04'),
(382, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-29 02:44:47'),
(383, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-29 02:45:19'),
(384, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-06-29 02:45:56'),
(385, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-06-29 03:01:36'),
(386, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-29 03:02:20'),
(387, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-29 03:03:01'),
(388, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-29 03:04:05'),
(389, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-06-29 03:05:35'),
(390, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-06-29 03:06:04'),
(391, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-29 05:05:24'),
(392, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-29 05:07:58'),
(393, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-29 05:36:11'),
(394, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-29 12:05:19'),
(395, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-29 12:05:36'),
(396, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-06-29 12:05:44'),
(397, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-29 12:17:53'),
(398, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-29 12:48:24'),
(399, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-29 12:56:03'),
(400, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-06-29 13:08:22'),
(401, NULL, 'Logged in: ian@gmail.com', 'Login', NULL, '::1', '2026-06-29 13:36:56'),
(402, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-06-29 13:37:21'),
(403, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-29 22:44:01'),
(404, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-29 22:44:42'),
(405, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-29 23:28:59'),
(406, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-29 23:31:43'),
(407, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-30 00:12:29'),
(408, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-30 00:15:33'),
(409, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-30 00:27:25'),
(410, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-30 00:30:30'),
(411, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-30 00:32:35'),
(412, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-30 02:07:16'),
(413, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-30 02:13:43'),
(414, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-30 02:27:56'),
(415, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-30 02:31:49'),
(416, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-30 02:59:27'),
(417, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-30 03:01:26'),
(418, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-06-30 03:12:25'),
(419, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-06-30 03:24:16'),
(420, 54, 'Logged in: nicolecarlet@gmail.com', 'Login', NULL, '::1', '2026-06-30 03:24:53'),
(421, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-01 01:14:35'),
(422, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-07-01 01:27:20'),
(423, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-07-02 07:54:56'),
(424, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-02 10:41:31'),
(425, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-06 08:28:32'),
(426, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-07-06 08:28:54'),
(427, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-07-06 08:29:10'),
(428, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-18 03:31:01'),
(429, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-21 02:08:19'),
(430, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-07-21 02:33:42'),
(431, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-21 02:48:21'),
(432, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-21 03:43:58'),
(433, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-21 03:47:22'),
(434, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-21 13:49:37'),
(435, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-07-21 13:52:05'),
(436, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-22 03:16:01'),
(437, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-07-22 03:16:19'),
(438, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-07-23 02:18:45'),
(439, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-23 02:19:13'),
(440, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-07-23 02:31:59'),
(441, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-23 08:12:20'),
(442, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-07-23 08:13:29'),
(443, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-07-23 08:13:38'),
(444, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-23 08:21:00'),
(445, 49, 'Logged in: kimberlyy@gmail.com', 'Login', NULL, '::1', '2026-07-24 02:15:38'),
(446, 50, 'Logged in: ronnie@gmail.com', 'Login', NULL, '::1', '2026-07-24 03:01:25'),
(447, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-25 03:48:38'),
(448, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-25 05:47:28'),
(449, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-29 01:18:31'),
(450, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-29 03:49:36'),
(451, 1, 'Logged in: admin@lgu-enrile.gov.ph', 'Login', NULL, '::1', '2026-07-29 04:03:44');

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `announcement_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `category` enum('General','HR Notice','Policy','Event','Holiday','Urgent') NOT NULL DEFAULT 'General',
  `priority` enum('Normal','High','Urgent') NOT NULL DEFAULT 'Normal',
  `audience` enum('All','Permanent','Contractual','Casual','Job Order','Department') NOT NULL DEFAULT 'All',
  `department_id` int(11) DEFAULT NULL,
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('Draft','Published','Archived') NOT NULL DEFAULT 'Draft',
  `publish_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `tag` enum('INFO','URGENT','NOTICE','WARNING') DEFAULT 'INFO',
  `posted_by` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`announcement_id`, `title`, `body`, `category`, `priority`, `audience`, `department_id`, `pinned`, `status`, `publish_date`, `expiry_date`, `created_by`, `tag`, `posted_by`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Leave Filing Deadline', 'April leave requests must be filed on or before March 28, 2025 to ensure timely processing.', 'General', 'Normal', 'All', NULL, 0, 'Draft', NULL, NULL, NULL, 'INFO', 1, 1, '2026-03-09 16:22:10', '2026-03-27 14:10:12'),
(5, 'Leave Filing Deadlines', 'April leave requests must be filed on or before March 28, 2025 to ensure timely processing.', 'General', 'High', 'Permanent', NULL, 0, 'Archived', '2026-03-12', '2026-03-13', 1, 'INFO', 1, 1, '2026-03-09 16:22:58', '2026-03-27 14:11:35'),
(6, 'Updated Leave Policy', 'New CSC sick leave monetization rules are effective April 1, 2025. Please review the circular.', 'General', 'Normal', 'All', NULL, 0, 'Draft', NULL, NULL, NULL, 'URGENT', 1, 1, '2026-03-09 16:22:58', '2026-03-17 04:42:54'),
(7, 'System Maintenance', 'The HR system will be offline on March 10, 2025 from 6:00 PM to 9:00 PM for maintenance.', 'General', 'Normal', 'All', NULL, 0, 'Archived', NULL, NULL, NULL, 'NOTICE', 1, 1, '2026-03-09 16:22:58', '2026-03-17 04:43:00'),
(8, 'Service Awards Ceremony', 'Annual service awards scheduled for April 15. All employees are encouraged to attend.', 'General', 'Normal', 'All', NULL, 0, 'Published', NULL, NULL, 1, 'INFO', 1, 1, '2026-03-09 16:22:58', '2026-03-27 14:06:25'),
(10, 'Updated Leave Policy', 'New CSC sick leave monetization rules are effective April 1, 2025. Please review the circular.', 'General', 'Normal', 'All', NULL, 0, 'Published', NULL, NULL, 1, 'URGENT', 1, 1, '2026-03-21 05:05:14', '2026-03-27 14:10:23'),
(11, 'System Maintenance', 'The HR system will be offline on March 10, 2025 from 6:00 PM to 9:00 PM for maintenance.', 'General', 'Normal', 'All', NULL, 0, 'Published', NULL, NULL, 1, 'NOTICE', 1, 1, '2026-03-21 05:05:14', '2026-03-27 14:10:45'),
(12, 'Service Awards Ceremony', 'Annual service awards scheduled for April 15. All employees are encouraged to attend.', 'General', 'Normal', 'All', NULL, 0, 'Draft', NULL, NULL, NULL, 'INFO', 1, 1, '2026-03-21 05:05:14', '2026-03-21 05:05:14');

-- --------------------------------------------------------

--
-- Table structure for table `archive_log`
--

CREATE TABLE `archive_log` (
  `log_id` int(11) NOT NULL,
  `record_type` enum('employee','contract','leave_request','document','announcement') NOT NULL,
  `record_id` int(11) NOT NULL,
  `action` enum('archived','restored','deleted') NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `performed_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `message_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_unsent` tinyint(1) NOT NULL DEFAULT 0,
  `is_edited` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chat_messages`
--

INSERT INTO `chat_messages` (`message_id`, `sender_id`, `receiver_id`, `message`, `is_read`, `sent_at`, `is_unsent`, `is_edited`) VALUES
(1, 29, 1, 'Good morning po maam', 1, '2026-03-15 12:39:37', 0, 0),
(2, 29, 1, 'Hello po', 1, '2026-03-15 12:52:32', 0, 0),
(3, 1, 29, 'Yes po maam. Good afternoon po', 1, '2026-03-15 13:19:25', 0, 0),
(4, 29, 1, 'Maam pa check naman po yung leave ko', 1, '2026-03-15 13:19:57', 0, 0),
(5, 1, 29, 'sure po', 1, '2026-03-15 13:20:14', 0, 0),
(6, 29, 1, 'thank you po maam. mwaps', 1, '2026-03-15 13:22:21', 0, 1),
(7, 29, 1, '[unsent]', 1, '2026-03-15 13:26:35', 1, 0),
(8, 1, 29, 'k lang', 1, '2026-03-15 13:27:32', 0, 0),
(9, 29, 1, 'k', 1, '2026-03-15 13:27:51', 0, 0),
(10, 1, 29, 'mwa', 1, '2026-03-15 13:47:54', 0, 0),
(11, 1, 29, 'hello', 1, '2026-03-15 21:55:49', 0, 1),
(12, 29, 1, 'hi maam', 1, '2026-03-15 22:28:00', 0, 0),
(13, 1, 29, 'yo', 1, '2026-03-15 22:36:24', 0, 0),
(14, 1, 29, 'yo', 1, '2026-03-15 22:36:42', 0, 0),
(15, 29, 1, 'yo', 1, '2026-03-15 22:37:09', 0, 0),
(16, 1, 29, 'yo', 1, '2026-03-15 22:40:24', 0, 0),
(17, 1, 29, 'yoooo', 1, '2026-03-15 22:40:44', 0, 0),
(18, 29, 1, 'yoooo', 1, '2026-03-15 22:44:19', 0, 0),
(19, 1, 29, 'hi ve', 1, '2026-03-15 22:59:28', 0, 0),
(20, 29, 1, '[unsent]', 1, '2026-03-16 12:17:23', 1, 1),
(21, 29, 1, 'hiiiiiii', 1, '2026-03-16 13:21:38', 0, 0),
(22, 29, 1, '[unsent]', 1, '2026-03-16 13:21:56', 1, 1),
(23, 1, 29, 'asdfgh', 1, '2026-03-16 13:40:55', 0, 0),
(24, 1, 29, 'sdfghj', 1, '2026-03-16 13:41:08', 0, 0),
(25, 29, 1, 'h i sister', 1, '2026-03-16 15:04:52', 0, 0),
(26, 1, 29, 'Hi sister', 1, '2026-03-16 15:05:04', 0, 0),
(27, 1, 29, '[unsent]', 1, '2026-03-16 15:16:21', 1, 0),
(28, 1, 29, 'hi', 1, '2026-03-17 10:26:22', 0, 0),
(29, 1, 29, 'hi sis', 1, '2026-03-23 11:04:57', 0, 0),
(30, 1, 29, 'hi be', 1, '2026-03-23 18:54:15', 0, 0),
(31, 29, 1, 'hiiii', 1, '2026-03-24 12:18:37', 0, 0),
(32, 29, 1, 'hello babe', 1, '2026-03-24 12:19:04', 0, 1),
(33, 29, 1, 'bITCH', 1, '2026-03-26 10:01:01', 0, 0),
(34, 29, 1, '[unsent]', 1, '2026-03-26 12:13:44', 1, 0),
(35, 1, 29, 'hi', 1, '2026-03-26 12:13:56', 0, 0),
(36, 1, 29, 'hi', 1, '2026-03-26 12:16:32', 0, 0),
(37, 29, 1, 'nsa sacajscssss', 1, '2026-03-28 00:08:06', 0, 1),
(38, 1, 29, 'bitch', 1, '2026-03-28 11:11:09', 0, 0),
(39, 29, 1, '[unsent]', 1, '2026-03-28 11:11:21', 1, 0),
(40, 29, 1, 'hi babe niya', 1, '2026-04-02 15:50:44', 0, 1),
(41, 39, 47, 'hi', 0, '2026-04-08 09:46:23', 0, 0),
(42, 39, 29, 'hi', 1, '2026-04-08 09:46:55', 0, 0),
(43, 1, 48, 'Hello', 1, '2026-04-11 01:57:56', 0, 0),
(44, 49, 48, 'Hi sir', 1, '2026-04-12 14:19:37', 0, 1),
(45, 48, 49, 'hello', 1, '2026-04-12 14:21:03', 0, 0),
(46, 1, 49, 'Hi vave', 1, '2026-05-12 13:10:18', 0, 0),
(48, 49, 1, 'HI', 1, '2026-05-13 12:47:02', 0, 0),
(50, 49, 1, 'hi', 1, '2026-06-28 17:10:10', 0, 0),
(51, 1, 49, 'hi', 1, '2026-06-28 17:26:55', 0, 0),
(52, 49, 1, 'hi', 1, '2026-06-28 17:27:06', 0, 0),
(53, 1, 49, 'hi', 1, '2026-06-28 17:27:20', 0, 0),
(54, 1, 49, 'hi', 1, '2026-06-28 17:27:32', 0, 0),
(55, 1, 49, 'hi', 1, '2026-06-28 17:28:01', 0, 0),
(56, 1, 49, 'hi', 1, '2026-06-29 20:48:33', 0, 0),
(57, 1, 49, '[unsent]', 1, '2026-06-29 20:48:42', 1, 0),
(58, 1, 49, 'hiiii', 1, '2026-06-30 12:11:39', 0, 0),
(59, 49, 1, 'hello', 1, '2026-06-30 12:11:56', 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `coe_requests`
--

CREATE TABLE `coe_requests` (
  `coe_request_id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `purpose` text NOT NULL,
  `status` enum('Pending','Approved','Denied','Cancelled') NOT NULL DEFAULT 'Pending',
  `admin_remarks` text DEFAULT NULL,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `filed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `coe_requests`
--

INSERT INTO `coe_requests` (`coe_request_id`, `employee_id`, `purpose`, `status`, `admin_remarks`, `reviewed_by`, `reviewed_at`, `is_deleted`, `filed_at`, `updated_at`) VALUES
(1, 58, 'basta', 'Approved', NULL, 1, '2026-07-21 03:23:11', 0, '2026-07-21 03:21:44', '2026-07-21 03:23:11');

-- --------------------------------------------------------

--
-- Table structure for table `contracts`
--

CREATE TABLE `contracts` (
  `contract_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `contract_type` enum('Permanent','Contractual','Casual','Job Order','Co-Terminus') NOT NULL DEFAULT 'Contractual',
  `contract_number` varchar(100) DEFAULT NULL,
  `position_title` varchar(150) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `salary_grade` varchar(50) DEFAULT NULL,
  `monthly_salary` decimal(12,2) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('Active','Expired','Terminated','Renewed') NOT NULL DEFAULT 'Active',
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `contracts`
--

INSERT INTO `contracts` (`contract_id`, `employee_id`, `contract_type`, `contract_number`, `position_title`, `department_id`, `salary_grade`, `monthly_salary`, `start_date`, `end_date`, `status`, `remarks`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 30, 'Contractual', 'CON-000-002', 'Administrative Aide V', 3, NULL, 28000.00, '2026-03-11', '2027-08-11', 'Active', NULL, 1, '2026-03-11 13:50:51', '2026-03-11 13:50:51'),
(2, 43, 'Contractual', 'CON-OOO-OO3', 'Engineering Aide', 2, '15', 15000.00, '2026-03-30', '2027-03-31', 'Active', NULL, 1, '2026-03-31 07:51:40', '2026-03-31 07:51:40'),
(3, 51, 'Casual', NULL, 'Administrative Aide V', 8, NULL, NULL, '2026-03-31', NULL, 'Active', NULL, 1, '2026-03-31 08:01:38', '2026-03-31 08:01:38'),
(4, 55, 'Casual', NULL, 'Nurse 1', 4, NULL, NULL, '2026-03-31', NULL, 'Active', NULL, 1, '2026-03-31 16:19:58', '2026-03-31 16:19:58'),
(6, 59, 'Contractual', NULL, 'Administrative Aide VI', 3, NULL, NULL, '2026-04-01', NULL, 'Active', NULL, 1, '2026-04-12 17:04:13', '2026-04-12 17:04:13'),
(7, 66, 'Contractual', NULL, 'Test', 3, NULL, NULL, '2026-06-30', NULL, 'Active', NULL, 1, '2026-06-30 12:00:59', '2026-06-30 12:00:59');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `head_name` varchar(150) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(10) DEFAULT '?',
  `color` varchar(7) DEFAULT '#2563eb',
  `employee_count` int(10) UNSIGNED DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `head_employee_id` int(11) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `name`, `head_name`, `description`, `icon`, `color`, `employee_count`, `is_active`, `created_at`, `updated_at`, `head_employee_id`) VALUES
(3, 'Admin Office', 'Kimberly Villan', 'Handles general administrative operations and records.', '🏢', '#2563eb', 4, 1, '2026-03-09 16:22:09', '2026-06-29 03:03:21', 58),
(26, 'Finance Office', '', 'test', '💰', '#2563eb', 0, 1, '2026-04-12 08:53:07', '2026-06-29 03:04:52', NULL),
(27, 'Human Resource Office', 'Brytz Camille Raymundo', 'Test', '📚', '#f59e0b', 2, 1, '2026-05-06 07:26:47', '2026-06-29 23:04:41', 32),
(28, 'Office of the Mayor', '', 'Test', '🏢', '#14b8a6', 0, 1, '2026-05-12 04:57:18', '2026-05-12 04:57:18', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `document_id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `document_type` varchar(80) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(10) UNSIGNED DEFAULT NULL,
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `documents`
--

INSERT INTO `documents` (`document_id`, `employee_id`, `document_type`, `file_name`, `file_path`, `file_size`, `uploaded_by`, `uploaded_at`) VALUES
(15, 58, 'Appointment', 'Unit-1-Lesson-4-Historical-Developments-of-Hardware-and-Operating-System.pdf', '../uploads/documents/1784691862_d4e44ec57276.pdf', 519686, 49, '2026-07-22 03:44:22');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `employee_id` int(11) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `employee_code` varchar(20) NOT NULL,
  `first_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `suffix_name` varchar(30) DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `gender` enum('Male','Female','Other') NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `civil_status` enum('Single','Married','Widowed','Separated','Other') DEFAULT 'Single',
  `home_address` text DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email_address` varchar(180) DEFAULT NULL,
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `position_title` varchar(120) NOT NULL,
  `employment_type` enum('Permanent','Co-Terminus','Elective','Contractual','Casual','Job Order') NOT NULL DEFAULT 'Permanent',
  `employment_status` enum('Active','On Leave','Inactive','Resigned','Retired') NOT NULL DEFAULT 'Active',
  `salary_grade` varchar(20) DEFAULT NULL,
  `gsis_number` varchar(30) DEFAULT NULL,
  `pagibig_number` varchar(30) DEFAULT NULL,
  `philhealth_number` varchar(30) DEFAULT NULL,
  `tin_number` varchar(30) DEFAULT NULL,
  `date_hired` date NOT NULL,
  `date_separated` date DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `signature_file_path` varchar(255) DEFAULT NULL,
  `signature_original_name` varchar(255) DEFAULT NULL,
  `signature_uploaded_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `archived_at` datetime DEFAULT NULL,
  `archive_reason` varchar(255) DEFAULT NULL,
  `is_mayor` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`employee_id`, `user_id`, `employee_code`, `first_name`, `middle_name`, `suffix_name`, `last_name`, `gender`, `date_of_birth`, `civil_status`, `home_address`, `contact_number`, `email_address`, `department_id`, `position_title`, `employment_type`, `employment_status`, `salary_grade`, `gsis_number`, `pagibig_number`, `philhealth_number`, `tin_number`, `date_hired`, `date_separated`, `profile_photo`, `signature_file_path`, `signature_original_name`, `signature_uploaded_at`, `created_at`, `updated_at`, `archived_at`, `archive_reason`, `is_mayor`) VALUES
(32, 1, 'Emp-004', 'Brytz Camille', 'Faustino', NULL, 'Raymundo', 'Female', '2001-12-24', 'Single', 'Cataratan, Allacapan, Cagayan', '09474759463', 'Brytz@gmail.com', 3, 'Head, Human Resource', 'Permanent', 'Active', 'SG-20', '98765432111', NULL, NULL, NULL, '2023-02-14', NULL, NULL, '../uploads/leave_signatures/sig_emp_32_1785300116.png', '54c1825e-3b14-431f-b23c-a9788e35a065.png', '2026-07-29 12:41:56', '2026-03-14 02:45:06', '2026-07-29 04:41:56', NULL, NULL, 0),
(58, 49, 'EMP-002', 'Kimberly', '', '', 'Villan', 'Female', '2001-01-10', 'Single', 'Ugac Sur, Tuguegarao City', '09952559469', 'kimberlyy@gmail.com', 3, 'Administrative Aide V', 'Permanent', 'Active', '9', '9876543211111', NULL, NULL, NULL, '2026-04-10', NULL, '../uploads/profile_photos/emp_58_1778133807.jpg', '../uploads/leave_signatures/sig_emp_58_1784859367.png', '59b8dc2b-726a-4bc6-920b-e0d11d5b97e8.png', '2026-07-24 10:16:07', '2026-04-10 03:26:49', '2026-07-24 02:16:07', NULL, NULL, 0),
(59, 50, 'EMP-003', 'Ronnie', 'Tagapan', NULL, 'Barasi', 'Male', '2001-12-29', 'Married', 'Enrile, Cagayan', '09952559469', 'ronnie@gmail.com', 3, 'Mayor', 'Permanent', 'Active', '16', '98765432111', NULL, NULL, NULL, '2026-04-01', NULL, NULL, '../uploads/leave_signatures/sig_emp_59_1784778407.jpg', 'esig.jpg', '2026-07-23 11:46:47', '2026-04-12 07:00:44', '2026-07-24 03:02:40', NULL, NULL, 0),
(66, 54, 'EMP-008', 'Nicole', '', NULL, 'Carlet', 'Female', '2001-03-08', 'Single', NULL, '09123456789', 'nicolecarlet@gmail.com', 3, 'Test', 'Permanent', 'Active', '18', '', NULL, NULL, NULL, '2026-06-30', NULL, NULL, NULL, NULL, NULL, '2026-06-30 03:23:22', '2026-06-30 04:02:09', NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `holidays`
--

CREATE TABLE `holidays` (
  `holiday_id` int(10) UNSIGNED NOT NULL,
  `holiday_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'If 1, the month-day recurs every year',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_balances`
--

CREATE TABLE `leave_balances` (
  `balance_id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `leave_type_id` int(10) UNSIGNED NOT NULL,
  `calendar_year` year(4) NOT NULL,
  `total_credits` decimal(6,2) NOT NULL DEFAULT 0.00,
  `used_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `remaining_days` decimal(6,2) GENERATED ALWAYS AS (`total_credits` - `used_days`) STORED,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leave_balances`
--

INSERT INTO `leave_balances` (`balance_id`, `employee_id`, `leave_type_id`, `calendar_year`, `total_credits`, `used_days`, `created_at`, `updated_at`) VALUES
(161, 32, 1, '2026', 15.00, 0.00, '2026-03-14 02:45:06', '2026-03-14 02:45:06'),
(162, 32, 2, '2026', 15.00, 0.00, '2026-03-14 02:45:06', '2026-03-14 02:45:06'),
(164, 32, 4, '2026', 105.00, 0.00, '2026-03-14 02:45:06', '2026-03-14 02:45:06'),
(165, 32, 5, '2026', 7.00, 0.00, '2026-03-14 02:45:06', '2026-03-14 02:45:06'),
(166, 32, 6, '2026', 3.00, 0.00, '2026-03-14 02:45:06', '2026-03-14 02:45:06'),
(167, 32, 14, '2026', 180.00, 0.00, '2026-03-14 02:45:06', '2026-03-14 02:45:06'),
(170, 32, 17, '2026', 7.00, 0.00, '2026-03-14 02:45:06', '2026-03-14 02:45:06'),
(171, 32, 18, '2026', 10.00, 0.00, '2026-03-14 02:45:06', '2026-03-14 02:45:06'),
(174, 32, 22, '2026', 5.00, 0.00, '2026-03-14 02:45:06', '2026-03-14 02:45:06'),
(206, 32, 1, '2027', 15.00, 0.00, '2026-03-16 05:20:50', '2026-03-16 05:20:50'),
(207, 32, 2, '2027', 15.00, 0.00, '2026-03-16 05:20:50', '2026-03-16 05:20:50'),
(209, 32, 4, '2027', 105.00, 0.00, '2026-03-16 05:20:50', '2026-03-16 05:20:50'),
(210, 32, 5, '2027', 7.00, 0.00, '2026-03-16 05:20:50', '2026-03-16 05:20:50'),
(211, 32, 6, '2027', 3.00, 0.00, '2026-03-16 05:20:50', '2026-03-16 05:20:50'),
(212, 32, 14, '2027', 180.00, 0.00, '2026-03-16 05:20:50', '2026-03-16 05:20:50'),
(215, 32, 17, '2027', 7.00, 0.00, '2026-03-16 05:20:50', '2026-03-16 05:20:50'),
(216, 32, 18, '2027', 10.00, 0.00, '2026-03-16 05:20:50', '2026-03-16 05:20:50'),
(219, 32, 22, '2027', 5.00, 0.00, '2026-03-16 05:20:50', '2026-03-16 05:20:50'),
(248, 32, 1, '2025', 15.00, 2.00, '2026-03-21 05:05:14', '2026-03-21 05:05:14'),
(249, 32, 2, '2025', 15.00, 0.00, '2026-03-21 05:05:14', '2026-03-21 05:05:14'),
(251, 32, 6, '2025', 5.00, 0.00, '2026-03-21 05:05:14', '2026-03-21 05:05:14'),
(308, 32, 25, '2027', 5.00, 0.00, '2026-03-26 04:39:52', '2026-03-26 04:39:52'),
(309, 32, 33, '2027', 6.00, 0.00, '2026-03-26 04:39:52', '2026-03-26 04:39:52'),
(310, 32, 34, '2027', 2.00, 0.00, '2026-03-26 04:39:52', '2026-03-26 04:39:52'),
(311, 32, 35, '2027', 5.00, 0.00, '2026-03-26 04:39:52', '2026-03-26 04:39:52'),
(462, 32, 25, '2026', 5.00, 0.00, '2026-03-26 04:40:33', '2026-03-26 04:40:33'),
(463, 32, 33, '2026', 6.00, 0.00, '2026-03-26 04:40:33', '2026-03-26 04:40:33'),
(464, 32, 34, '2026', 2.00, 0.00, '2026-03-26 04:40:33', '2026-03-26 04:40:33'),
(465, 32, 35, '2026', 5.00, 0.00, '2026-03-26 04:40:33', '2026-03-26 04:40:33'),
(976, 58, 1, '2026', 15.00, 0.00, '2026-04-10 03:26:49', '2026-04-10 03:26:49'),
(977, 58, 2, '2026', 15.00, 1.00, '2026-04-10 03:26:49', '2026-06-29 22:52:50'),
(978, 58, 4, '2026', 105.00, 0.00, '2026-04-10 03:26:49', '2026-04-10 03:26:49'),
(979, 58, 6, '2026', 3.00, 0.00, '2026-04-10 03:26:49', '2026-04-10 03:26:49'),
(980, 58, 14, '2026', 180.00, 0.00, '2026-04-10 03:26:49', '2026-04-10 03:26:49'),
(982, 58, 17, '2026', 7.00, 0.00, '2026-04-10 03:26:49', '2026-04-10 03:26:49'),
(983, 58, 18, '2026', 10.00, 0.00, '2026-04-10 03:26:49', '2026-04-10 03:26:49'),
(984, 58, 22, '2026', 5.00, 1.00, '2026-04-10 03:26:49', '2026-04-11 04:16:23'),
(985, 58, 25, '2026', 5.00, 0.00, '2026-04-10 03:26:49', '2026-04-10 03:26:49'),
(986, 58, 33, '2026', 6.00, 0.00, '2026-04-10 03:26:49', '2026-04-10 03:26:49'),
(987, 58, 34, '2026', 2.00, 0.00, '2026-04-10 03:26:49', '2026-04-10 03:26:49'),
(988, 58, 35, '2026', 5.00, 0.00, '2026-04-10 03:26:49', '2026-04-10 03:26:49'),
(1030, 58, 5, '2026', 7.00, 0.00, '2026-04-11 16:28:52', '2026-04-11 16:28:52'),
(1085, 32, 4, '2025', 105.00, 0.00, '2026-04-11 16:29:32', '2026-04-11 16:29:32'),
(1086, 32, 5, '2025', 7.00, 0.00, '2026-04-11 16:29:32', '2026-04-11 16:29:32'),
(1088, 32, 14, '2025', 180.00, 0.00, '2026-04-11 16:29:32', '2026-04-11 16:29:32'),
(1090, 32, 17, '2025', 7.00, 0.00, '2026-04-11 16:29:32', '2026-04-11 16:29:32'),
(1091, 32, 18, '2025', 10.00, 0.00, '2026-04-11 16:29:32', '2026-04-11 16:29:32'),
(1092, 32, 22, '2025', 5.00, 0.00, '2026-04-11 16:29:32', '2026-04-11 16:29:32'),
(1093, 32, 25, '2025', 5.00, 0.00, '2026-04-11 16:29:32', '2026-04-11 16:29:32'),
(1094, 32, 33, '2025', 6.00, 0.00, '2026-04-11 16:29:32', '2026-04-11 16:29:32'),
(1095, 32, 34, '2025', 2.00, 0.00, '2026-04-11 16:29:32', '2026-04-11 16:29:32'),
(1096, 32, 35, '2025', 5.00, 0.00, '2026-04-11 16:29:32', '2026-04-11 16:29:32'),
(1111, 58, 1, '2025', 15.00, 0.00, '2026-04-11 16:29:33', '2026-04-11 16:29:33'),
(1112, 58, 2, '2025', 15.00, 0.00, '2026-04-11 16:29:33', '2026-04-11 16:29:33'),
(1113, 58, 4, '2025', 105.00, 0.00, '2026-04-11 16:29:33', '2026-04-11 16:29:33'),
(1114, 58, 5, '2025', 7.00, 0.00, '2026-04-11 16:29:33', '2026-04-11 16:29:33'),
(1115, 58, 6, '2025', 3.00, 0.00, '2026-04-11 16:29:33', '2026-04-11 16:29:33'),
(1116, 58, 14, '2025', 180.00, 0.00, '2026-04-11 16:29:33', '2026-04-11 16:29:33'),
(1118, 58, 17, '2025', 7.00, 0.00, '2026-04-11 16:29:33', '2026-04-11 16:29:33'),
(1119, 58, 18, '2025', 10.00, 0.00, '2026-04-11 16:29:33', '2026-04-11 16:29:33'),
(1120, 58, 22, '2025', 5.00, 0.00, '2026-04-11 16:29:33', '2026-04-11 16:29:33'),
(1121, 58, 25, '2025', 5.00, 0.00, '2026-04-11 16:29:33', '2026-04-11 16:29:33'),
(1122, 58, 33, '2025', 6.00, 0.00, '2026-04-11 16:29:33', '2026-04-11 16:29:33'),
(1123, 58, 34, '2025', 2.00, 0.00, '2026-04-11 16:29:33', '2026-04-11 16:29:33'),
(1124, 58, 35, '2025', 5.00, 0.00, '2026-04-11 16:29:33', '2026-04-11 16:29:33'),
(1195, 59, 1, '2026', 15.00, 11.00, '2026-04-12 07:00:44', '2026-05-07 07:16:11'),
(1196, 59, 2, '2026', 15.00, 7.00, '2026-04-12 07:00:44', '2026-05-07 06:14:57'),
(1197, 59, 5, '2026', 7.00, 0.00, '2026-04-12 07:00:44', '2026-04-12 07:00:44'),
(1198, 59, 6, '2026', 3.00, 0.00, '2026-04-12 07:00:44', '2026-04-12 07:00:44'),
(1199, 59, 14, '2026', 180.00, 0.00, '2026-04-12 07:00:44', '2026-04-12 07:00:44'),
(1201, 59, 17, '2026', 7.00, 0.00, '2026-04-12 07:00:44', '2026-04-12 07:00:44'),
(1202, 59, 22, '2026', 10.00, 0.00, '2026-04-12 07:00:44', '2026-07-23 08:15:45'),
(1203, 59, 25, '2026', 5.00, 0.00, '2026-04-12 07:00:44', '2026-04-12 07:00:44'),
(1204, 59, 33, '2026', 6.00, 0.00, '2026-04-12 07:00:44', '2026-04-12 07:00:44'),
(1205, 59, 35, '2026', 5.00, 0.00, '2026-04-12 07:00:44', '2026-04-12 07:00:44'),
(1304, 66, 1, '2026', 15.00, 3.00, '2026-06-30 03:23:22', '2026-06-30 03:33:33'),
(1305, 66, 2, '2026', 15.00, 0.00, '2026-06-30 03:23:22', '2026-06-30 03:23:22'),
(1306, 66, 4, '2026', 105.00, 0.00, '2026-06-30 03:23:22', '2026-06-30 03:23:22'),
(1307, 66, 5, '2026', 7.00, 0.00, '2026-06-30 03:23:22', '2026-06-30 03:23:22'),
(1308, 66, 6, '2026', 3.00, 0.00, '2026-06-30 03:23:22', '2026-06-30 03:23:22'),
(1309, 66, 14, '2026', 180.00, 0.00, '2026-06-30 03:23:22', '2026-06-30 03:23:22'),
(1310, 66, 17, '2026', 7.00, 0.00, '2026-06-30 03:23:22', '2026-06-30 03:23:22'),
(1311, 66, 18, '2026', 10.00, 0.00, '2026-06-30 03:23:22', '2026-06-30 03:23:22'),
(1312, 66, 22, '2026', 5.00, 0.00, '2026-06-30 03:23:22', '2026-06-30 03:23:22'),
(1313, 66, 25, '2026', 5.00, 0.00, '2026-06-30 03:23:22', '2026-06-30 03:23:22'),
(1314, 66, 33, '2026', 6.00, 0.00, '2026-06-30 03:23:22', '2026-06-30 03:23:22'),
(1315, 66, 34, '2026', 2.00, 0.00, '2026-06-30 03:23:22', '2026-06-30 03:23:22'),
(1316, 66, 35, '2026', 5.00, 0.00, '2026-06-30 03:23:22', '2026-06-30 03:23:22'),
(1317, 32, 38, '2026', 5.00, 0.00, '2026-07-01 01:37:41', '2026-07-01 01:37:41'),
(1319, 58, 38, '2026', 5.00, 0.00, '2026-07-01 01:37:41', '2026-07-21 02:34:19'),
(1320, 59, 38, '2026', 5.00, 0.00, '2026-07-01 01:37:41', '2026-07-01 01:37:41'),
(1321, 66, 38, '2026', 5.00, 0.00, '2026-07-01 01:37:41', '2026-07-01 01:37:41');

-- --------------------------------------------------------

--
-- Table structure for table `leave_notifications`
--

CREATE TABLE `leave_notifications` (
  `notification_id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `request_id` int(10) UNSIGNED DEFAULT NULL,
  `leave_type_id` int(10) UNSIGNED NOT NULL,
  `notification_type` enum('FILING_DEADLINE','LEAVE_REMINDER','LEAVE_ENDING','OTHER') NOT NULL DEFAULT 'OTHER',
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `target_date` date NOT NULL COMMENT 'Date when notification should be shown (e.g., 5 days before filing deadline)',
  `is_sent` tinyint(1) DEFAULT 0 COMMENT 'Whether notification has been marked as sent/viewed',
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leave_notifications`
--

INSERT INTO `leave_notifications` (`notification_id`, `employee_id`, `request_id`, `leave_type_id`, `notification_type`, `title`, `message`, `target_date`, `is_sent`, `read_at`, `created_at`, `updated_at`) VALUES
(58, 58, 91, 22, 'LEAVE_ENDING', 'Leave Ending Soon: Adaption Leave', 'Your Adaption Leave ends on 2026-04-13. Please prepare to return to work.', '2026-04-12', 1, NULL, '2026-04-11 04:13:45', '2026-06-28 06:08:53'),
(66, 59, 99, 2, 'LEAVE_ENDING', 'Leave Ending Soon: Sick Leave', 'Your Sick Leave ends on 2026-04-18. Please prepare to return to work.', '2026-04-17', 1, NULL, '2026-04-12 09:12:25', '2026-06-28 06:07:12'),
(67, 59, 100, 22, 'LEAVE_ENDING', 'Leave Ending Soon: Adaption Leave', 'Your Adaption Leave ends on 2026-05-08. Please prepare to return to work.', '2026-05-07', 1, NULL, '2026-05-04 13:02:54', '2026-06-28 06:07:12'),
(68, 59, 101, 1, 'LEAVE_ENDING', 'Leave Ending Soon: Vacation Leave', 'Your Vacation Leave ends on 2026-05-13. Please prepare to return to work.', '2026-05-12', 1, NULL, '2026-05-07 07:08:24', '2026-06-28 06:07:12'),
(69, 59, 102, 1, 'FILING_DEADLINE', 'Filing Deadline: Vacation Leave', 'Reminder: You filed a Vacation Leave request. The deadline to file was 5 day(s) before 2026-05-15.', '2026-05-10', 1, NULL, '2026-05-07 07:14:53', '2026-06-28 06:07:12'),
(70, 59, 102, 1, 'LEAVE_ENDING', 'Leave Ending Soon: Vacation Leave', 'Your Vacation Leave ends on 2026-05-22. Please prepare to return to work.', '2026-05-21', 1, NULL, '2026-05-07 07:14:53', '2026-06-28 06:07:12'),
(71, 59, 103, 1, 'LEAVE_ENDING', 'Leave Ending Soon: Vacation Leave', 'Your Vacation Leave ends on 2026-06-30. Please prepare to return to work.', '2026-06-29', 1, NULL, '2026-06-27 12:35:38', '2026-06-29 02:45:56'),
(72, 59, 104, 1, 'LEAVE_ENDING', 'Leave Ending Soon: Vacation Leave', 'Your Vacation Leave ends on 2026-06-30. Please prepare to return to work.', '2026-06-29', 1, NULL, '2026-06-28 06:08:22', '2026-06-29 02:45:56'),
(73, 66, 107, 1, 'LEAVE_ENDING', 'Leave Ending Soon: Vacation Leave', 'Your Vacation Leave ends on 2026-07-03. Please prepare to return to work.', '2026-07-02', 0, NULL, '2026-06-30 03:29:49', '2026-06-30 03:29:49'),
(74, 66, 108, 1, 'FILING_DEADLINE', 'Filing Deadline: Vacation Leave', 'Reminder: You filed a Vacation Leave request. The deadline to file was 5 day(s) before 2026-07-10.', '2026-07-05', 0, NULL, '2026-06-30 03:43:22', '2026-06-30 03:43:22'),
(75, 66, 108, 1, 'LEAVE_ENDING', 'Leave Ending Soon: Vacation Leave', 'Your Vacation Leave ends on 2026-07-13. Please prepare to return to work.', '2026-07-12', 0, NULL, '2026-06-30 03:43:22', '2026-06-30 03:43:22'),
(76, 58, 109, 38, 'LEAVE_ENDING', 'Leave Ending Soon: Wellness Leave', 'Your Wellness Leave ends on 2026-07-10. Please prepare to return to work.', '2026-07-09', 1, NULL, '2026-07-01 01:41:02', '2026-07-21 02:33:43'),
(77, 59, 110, 22, 'LEAVE_ENDING', 'Leave Ending Soon: Adaption Leave', 'Your Adaption Leave ends on 2026-07-31. Please prepare to return to work.', '2026-07-30', 0, NULL, '2026-07-23 03:47:13', '2026-07-23 03:47:13'),
(78, 58, 111, 22, 'LEAVE_ENDING', 'Leave Ending Soon: Adaption Leave', 'Your Adaption Leave ends on 2026-07-24. Please prepare to return to work.', '2026-07-23', 1, NULL, '2026-07-23 08:19:54', '2026-07-23 08:20:00'),
(79, 58, 112, 1, 'LEAVE_ENDING', 'Leave Ending Soon: Vacation Leave', 'Your Vacation Leave ends on 2026-07-27. Please prepare to return to work.', '2026-07-26', 0, NULL, '2026-07-24 02:46:25', '2026-07-24 02:46:25'),
(80, 58, 113, 25, 'LEAVE_ENDING', 'Leave Ending Soon: Mandatory/Forced Leave', 'Your Mandatory/Forced Leave ends on 2026-07-27. Please prepare to return to work.', '2026-07-26', 0, NULL, '2026-07-24 02:47:38', '2026-07-24 02:47:38'),
(81, 58, 115, 4, 'LEAVE_ENDING', 'Leave Ending Soon: Maternity Leave', 'Your Maternity Leave ends on 2026-07-31. Please prepare to return to work.', '2026-07-30', 0, NULL, '2026-07-24 03:00:08', '2026-07-24 03:00:08'),
(82, 59, 116, 5, 'LEAVE_ENDING', 'Leave Ending Soon: Paternity Leave', 'Your Paternity Leave ends on 2026-08-04. Please prepare to return to work.', '2026-08-03', 0, NULL, '2026-07-24 03:02:46', '2026-07-24 03:02:46'),
(83, 58, 117, 6, 'LEAVE_ENDING', 'Leave Ending Soon: Special Leave', 'Your Special Leave ends on 2026-07-29. Please prepare to return to work.', '2026-07-28', 0, NULL, '2026-07-24 03:05:35', '2026-07-24 03:05:35'),
(84, 58, 118, 6, 'LEAVE_ENDING', 'Leave Ending Soon: Special Leave', 'Your Special Leave ends on 2026-07-29. Please prepare to return to work.', '2026-07-28', 0, NULL, '2026-07-24 03:05:35', '2026-07-24 03:05:35'),
(85, 59, 119, 17, 'LEAVE_ENDING', 'Leave Ending Soon: Solo Parent Leave', 'Your Solo Parent Leave ends on 2026-07-28. Please prepare to return to work.', '2026-07-27', 0, NULL, '2026-07-24 03:07:47', '2026-07-24 03:07:47'),
(86, 58, 120, 14, 'LEAVE_ENDING', 'Leave Ending Soon: Study Leave', 'Your Study Leave ends on 2026-07-31. Please prepare to return to work.', '2026-07-30', 0, NULL, '2026-07-24 03:08:42', '2026-07-24 03:08:42'),
(87, 58, 121, 18, 'LEAVE_ENDING', 'Leave Ending Soon: VAWC LEAVE', 'Your VAWC LEAVE ends on 2026-07-31. Please prepare to return to work.', '2026-07-30', 0, NULL, '2026-07-24 03:09:50', '2026-07-24 03:09:50'),
(88, 58, 122, 33, 'LEAVE_ENDING', 'Leave Ending Soon: Rehabilitation Privilege', 'Your Rehabilitation Privilege ends on 2026-07-27. Please prepare to return to work.', '2026-07-26', 0, NULL, '2026-07-24 03:15:42', '2026-07-24 03:15:42'),
(89, 58, 123, 22, 'LEAVE_ENDING', 'Leave Ending Soon: Adaption Leave', 'Your Adaption Leave ends on 2026-07-27. Please prepare to return to work.', '2026-07-26', 0, NULL, '2026-07-24 03:25:06', '2026-07-24 03:25:06'),
(90, 58, 124, 22, 'LEAVE_ENDING', 'Leave Ending Soon: Adaption Leave', 'Your Adaption Leave ends on 2026-07-27. Please prepare to return to work.', '2026-07-26', 0, NULL, '2026-07-24 03:59:19', '2026-07-24 03:59:19');

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `request_id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `leave_type_id` int(10) UNSIGNED NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `days_requested` decimal(6,2) NOT NULL,
  `with_pay` tinyint(1) NOT NULL DEFAULT 1,
  `reason` text NOT NULL,
  `signature_file_path` varchar(255) DEFAULT NULL,
  `signature_original_name` varchar(255) DEFAULT NULL,
  `signature_uploaded_at` datetime DEFAULT NULL,
  `status` enum('Pending Dept Head','Approved by Dept Head','Denied by Dept Head','Pending HR','Approved','Denied','Cancelled') NOT NULL DEFAULT 'Pending Dept Head',
  `dept_head_id` int(10) UNSIGNED DEFAULT NULL,
  `dept_head_remarks` text DEFAULT NULL,
  `dept_head_action_date` timestamp NULL DEFAULT NULL,
  `admin_remarks` text DEFAULT NULL,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `filed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leave_requests`
--

INSERT INTO `leave_requests` (`request_id`, `employee_id`, `leave_type_id`, `date_from`, `date_to`, `days_requested`, `with_pay`, `reason`, `signature_file_path`, `signature_original_name`, `signature_uploaded_at`, `status`, `dept_head_id`, `dept_head_remarks`, `dept_head_action_date`, `admin_remarks`, `reviewed_by`, `reviewed_at`, `filed_at`, `updated_at`, `is_deleted`) VALUES
(51, 32, 1, '2025-03-05', '2025-03-06', 2.00, 1, 'Personal matters.', NULL, NULL, NULL, 'Denied', NULL, NULL, NULL, 'Panget kasi', 1, '2026-04-07 15:08:40', '2025-03-01 16:00:00', '2026-04-07 15:08:40', 0),
(91, 58, 22, '2026-04-13', '2026-04-13', 1.00, 1, 'QWERTYUIO', NULL, NULL, NULL, 'Approved', 49, 'OK', '2026-04-11 04:14:24', 'qwertyuio', 1, '2026-04-11 04:16:23', '2026-04-11 04:13:45', '2026-04-11 04:16:23', 0),
(99, 59, 2, '2026-04-12', '2026-04-18', 7.00, 1, '[In Hospital] asdfgh', NULL, NULL, NULL, 'Approved', 49, 'asdfgh', '2026-04-12 09:13:44', 'Approved.', 1, '2026-05-07 06:14:57', '2026-04-12 09:12:25', '2026-05-07 06:14:57', 0),
(100, 59, 22, '2026-05-07', '2026-05-08', 2.00, 1, 'DFGHJ', NULL, NULL, NULL, 'Cancelled', NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-04 13:02:54', '2026-05-06 09:23:18', 1),
(101, 59, 1, '2026-05-07', '2026-05-13', 5.00, 1, '[Location: Within the Philippines] q', NULL, NULL, NULL, 'Approved', 49, '', '2026-05-07 07:08:52', 'Approved.', 1, '2026-05-07 07:09:07', '2026-05-07 07:08:24', '2026-05-07 07:09:07', 0),
(102, 59, 1, '2026-05-15', '2026-05-22', 6.00, 1, '[Location: Within the Philippines] q', NULL, NULL, NULL, 'Approved', 49, '', '2026-05-07 07:15:39', 'Approved.', 1, '2026-05-07 07:16:11', '2026-05-07 07:14:53', '2026-05-07 07:16:11', 0),
(103, 59, 1, '2026-06-29', '2026-06-30', 2.00, 1, '[Location: Within the Philippines] QWERTYU', NULL, NULL, NULL, 'Cancelled', 49, '', '2026-06-28 05:53:35', NULL, NULL, NULL, '2026-06-27 12:35:38', '2026-06-28 06:07:27', 0),
(104, 59, 1, '2026-06-29', '2026-06-30', 2.00, 1, '[Location: Within the Philippines] qwery', NULL, NULL, NULL, 'Denied', 49, '', '2026-06-28 06:16:37', 'WERTYUI', 1, '2026-06-29 02:52:41', '2026-06-28 06:08:22', '2026-06-29 02:52:41', 0),
(105, 59, 2, '2026-06-22', '2026-06-24', 3.00, 1, '[In Hospital] qwert', NULL, NULL, NULL, 'Cancelled', NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-29 13:40:28', '2026-06-29 13:40:34', 0),
(106, 58, 2, '2026-06-26', '2026-06-26', 1.00, 1, '[In Hospital] qwerty', NULL, NULL, NULL, 'Approved', 49, '', '2026-06-29 22:52:02', 'Approved.', 1, '2026-06-29 22:52:50', '2026-06-29 22:51:45', '2026-06-29 22:52:50', 0),
(107, 66, 1, '2026-07-01', '2026-07-03', 3.00, 1, '[Location: Abroad (Japan)] Test', NULL, NULL, NULL, 'Approved', 49, 'Test', '2026-06-30 03:31:39', 'Test', 1, '2026-06-30 03:33:33', '2026-06-30 03:29:49', '2026-06-30 03:33:33', 0),
(108, 66, 1, '2026-07-10', '2026-07-13', 2.00, 1, '[Location: Within the Philippines] test', NULL, NULL, NULL, 'Pending HR', 49, '', '2026-06-30 04:00:14', NULL, NULL, NULL, '2026-06-30 03:43:22', '2026-06-30 04:00:14', 0),
(109, 58, 38, '2026-07-06', '2026-07-10', 5.00, 1, 'QWERTY', NULL, NULL, NULL, 'Cancelled', 49, '', '2026-07-01 01:41:36', 'Cancelled: Typhoon', 1, '2026-07-21 02:34:19', '2026-07-01 01:41:02', '2026-07-21 02:34:19', 0),
(110, 59, 22, '2026-07-27', '2026-07-31', 5.00, 1, 'qwerty', '../uploads/leave_signatures/sig_emp_59_1784778407.jpg', 'esig.jpg', '2026-07-23 11:47:13', 'Cancelled', 49, 'qwerty', '2026-07-23 03:47:44', 'Cancelled: Di natuloy', 1, '2026-07-23 08:15:45', '2026-07-23 03:47:13', '2026-07-23 08:15:45', 0),
(111, 58, 22, '2026-07-24', '2026-07-24', 1.00, 1, 'mag aampon lang po', '../uploads/leave_signatures/sig_emp_58_1784779929.jpg', '59b8dc2b-726a-4bc6-920b-e0d11d5b97e8.jpg', '2026-07-23 16:19:54', 'Pending HR', 49, '', '2026-07-23 08:20:39', NULL, NULL, NULL, '2026-07-23 08:19:54', '2026-07-23 08:20:39', 0),
(112, 58, 1, '2026-07-27', '2026-07-27', 1.00, 1, '[Location: Within the Philippines] nm,', '../uploads/leave_signatures/sig_emp_58_1784859367.png', '59b8dc2b-726a-4bc6-920b-e0d11d5b97e8.png', '2026-07-24 10:46:25', 'Cancelled', 49, '', '2026-07-24 02:46:44', NULL, NULL, NULL, '2026-07-24 02:46:25', '2026-07-24 02:47:04', 0),
(113, 58, 25, '2026-07-27', '2026-07-27', 1.00, 1, 'nm,', '../uploads/leave_signatures/sig_emp_58_1784859367.png', '59b8dc2b-726a-4bc6-920b-e0d11d5b97e8.png', '2026-07-24 10:47:38', 'Cancelled', 49, '', '2026-07-24 02:47:44', NULL, NULL, NULL, '2026-07-24 02:47:38', '2026-07-24 02:48:04', 0),
(114, 58, 2, '2026-07-23', '2026-07-23', 1.00, 1, '[In Hospital] m,', '../uploads/leave_signatures/sig_emp_58_1784859367.png', '59b8dc2b-726a-4bc6-920b-e0d11d5b97e8.png', '2026-07-24 10:48:53', 'Cancelled', 49, '', '2026-07-24 02:49:00', NULL, NULL, NULL, '2026-07-24 02:48:53', '2026-07-24 02:59:40', 0),
(115, 58, 4, '2026-07-27', '2026-07-31', 5.00, 1, 'qwertyu', '../uploads/leave_signatures/sig_emp_58_1784859367.png', '59b8dc2b-726a-4bc6-920b-e0d11d5b97e8.png', '2026-07-24 11:00:08', 'Denied', 49, 'qwerty', '2026-07-24 03:00:20', 'Request denied.', 1, '2026-07-24 03:00:43', '2026-07-24 03:00:08', '2026-07-24 03:00:43', 0),
(116, 59, 5, '2026-07-27', '2026-08-04', 7.00, 1, 'qwer', '../uploads/leave_signatures/sig_emp_59_1784778407.jpg', 'esig.jpg', '2026-07-24 11:02:46', 'Denied', 49, '', '2026-07-24 03:02:57', 'Request denied.', 1, '2026-07-24 03:03:18', '2026-07-24 03:02:46', '2026-07-24 03:03:18', 0),
(117, 58, 6, '2026-07-27', '2026-07-29', 3.00, 1, '[Illness: qwd] qwd', '../uploads/leave_signatures/sig_emp_58_1784859367.png', '59b8dc2b-726a-4bc6-920b-e0d11d5b97e8.png', '2026-07-24 11:05:35', 'Denied by Dept Head', 49, '', '2026-07-24 03:07:12', NULL, NULL, NULL, '2026-07-24 03:05:35', '2026-07-24 03:07:12', 0),
(118, 58, 6, '2026-07-27', '2026-07-29', 3.00, 1, '[Illness: qwd] qwd', '../uploads/leave_signatures/sig_emp_58_1784859367.png', '59b8dc2b-726a-4bc6-920b-e0d11d5b97e8.png', '2026-07-24 11:05:35', 'Denied', 49, '', '2026-07-24 03:05:49', 'Request denied.', 1, '2026-07-24 03:06:04', '2026-07-24 03:05:35', '2026-07-24 03:06:04', 0),
(119, 59, 17, '2026-07-27', '2026-07-28', 2.00, 1, 'fghjk', '../uploads/leave_signatures/sig_emp_59_1784778407.jpg', 'esig.jpg', '2026-07-24 11:07:47', 'Denied', 49, '', '2026-07-24 03:07:59', 'Request denied.', 1, '2026-07-24 03:09:15', '2026-07-24 03:07:47', '2026-07-24 03:09:15', 0),
(120, 58, 14, '2026-07-27', '2026-07-31', 5.00, 1, '[Completion of Master\'s Degree] fghjk', '../uploads/leave_signatures/sig_emp_58_1784859367.png', '59b8dc2b-726a-4bc6-920b-e0d11d5b97e8.png', '2026-07-24 11:08:42', 'Denied', 49, '', '2026-07-24 03:08:49', 'Request denied.', 1, '2026-07-24 03:09:09', '2026-07-24 03:08:42', '2026-07-24 03:09:09', 0),
(121, 58, 18, '2026-07-27', '2026-07-31', 5.00, 1, 'qwert', '../uploads/leave_signatures/sig_emp_58_1784859367.png', '59b8dc2b-726a-4bc6-920b-e0d11d5b97e8.png', '2026-07-24 11:09:50', 'Denied', 49, '', '2026-07-24 03:09:57', 'Request denied.', 1, '2026-07-24 03:15:21', '2026-07-24 03:09:50', '2026-07-24 03:15:21', 0),
(122, 58, 33, '2026-07-27', '2026-07-27', 1.00, 1, '[Location: Within the Philippines] wertyu', '../uploads/leave_signatures/sig_emp_58_1784859367.png', '59b8dc2b-726a-4bc6-920b-e0d11d5b97e8.png', '2026-07-24 11:15:42', 'Pending HR', 49, '', '2026-07-24 03:15:52', NULL, NULL, NULL, '2026-07-24 03:15:42', '2026-07-24 03:15:52', 0),
(123, 58, 22, '2026-07-27', '2026-07-27', 1.00, 1, 'qweg', '../uploads/leave_signatures/sig_emp_58_1784859367.png', '59b8dc2b-726a-4bc6-920b-e0d11d5b97e8.png', '2026-07-24 11:25:06', 'Pending HR', 49, '', '2026-07-24 03:25:11', NULL, NULL, NULL, '2026-07-24 03:25:06', '2026-07-24 03:25:11', 0),
(124, 58, 22, '2026-07-27', '2026-07-27', 1.00, 1, 'qwerty', '../uploads/leave_signatures/sig_emp_58_1784859367.png', '59b8dc2b-726a-4bc6-920b-e0d11d5b97e8.png', '2026-07-24 11:59:19', 'Pending Dept Head', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24 03:59:19', '2026-07-24 03:59:19', 0),
(125, 32, 2, '2026-07-27', '2026-07-28', 2.00, 1, '[In Hospital] Reason', '../uploads/leave_signatures/sig_emp_32_1785298960.jpg', '13721024-8a88-4009-9e7d-a902790dd1bc.jpg', '2026-07-29 12:23:51', 'Pending Dept Head', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-29 04:23:51', '2026-07-29 04:23:51', 0);

-- --------------------------------------------------------

--
-- Table structure for table `leave_types`
--

CREATE TABLE `leave_types` (
  `leave_type_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `code` varchar(10) NOT NULL,
  `max_days` decimal(6,2) NOT NULL DEFAULT 15.00,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `applicable_gender` enum('All','Male','Female') NOT NULL DEFAULT 'All',
  `require_married` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `advance_notice_days` int(11) DEFAULT 0 COMMENT 'Days in advance employee must file leave (0 = same-day filing allowed)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leave_types`
--

INSERT INTO `leave_types` (`leave_type_id`, `name`, `code`, `max_days`, `description`, `is_active`, `applicable_gender`, `require_married`, `created_at`, `advance_notice_days`) VALUES
(1, 'Vacation Leave', 'VL', 15.00, 'Annual vacation leave credits per CSC rules.', 1, 'All', 0, '2026-03-09 16:22:09', 5),
(2, 'Sick Leave', 'SL', 15.00, 'Leave for illness or medical appointments.', 1, 'All', 0, '2026-03-09 16:22:09', 0),
(4, 'Maternity Leave', 'ML', 105.00, 'Maternity leave per RA 11210 (105 days).', 1, 'Female', 0, '2026-03-09 16:22:09', 30),
(5, 'Paternity Leave', 'PL', 7.00, 'Paternity leave per RA 8187 (7 days).', 1, 'Male', 1, '2026-03-09 16:22:09', 14),
(6, 'Special Leave', 'SPL', 3.00, 'Special privilege leave per CSC rules.', 1, 'All', 0, '2026-03-09 16:22:09', 0),
(14, 'Study Leave', 'STL', 180.00, 'Study leave is a special leave granted to government employees to pursue higher studies, specialized training, or research that enhances their professional skills and benefits public service.', 1, 'All', 0, '2026-03-12 02:47:01', 30),
(17, 'Solo Parent Leave', 'SOPL', 7.00, '', 1, 'All', 1, '2026-03-13 14:37:50', 0),
(18, 'VAWC LEAVE', 'VAWC', 10.00, '', 1, 'Female', 0, '2026-03-13 14:38:33', 14),
(22, 'Adaption Leave', 'AL', 5.00, '', 1, 'All', 0, '2026-03-13 14:41:52', 0),
(25, 'Mandatory/Forced Leave', 'MFL', 5.00, 'Annual five-day mandatory leave (Sec. 25, Rule XVI).', 1, 'All', 0, '2026-03-21 05:05:14', 0),
(33, 'Rehabilitation Privilege', 'RL', 6.00, 'Rehabilitation privilege per CSC rules (up to 6 months).', 1, 'All', 0, '2026-03-21 05:05:14', 0),
(34, 'Special Leave Benefits for Women', 'SLBW', 2.00, 'Special leave benefits for women per RA 9710 (up to 2 months).', 1, 'All', 0, '2026-03-21 05:05:14', 0),
(35, 'Special Emergency (Calamity) Leave', 'ECL', 5.00, 'Special emergency/calamity leave per CSC MC No. 2, s. 2012 (up to 5 days).', 1, 'All', 0, '2026-03-21 05:05:14', 0),
(38, 'Wellness Leave', 'WL', 5.00, 'A Wellness Leave is a type of leave granted to employees to support their physical, mental, and emotional well-being', 1, 'All', 0, '2026-07-01 01:37:41', 0);

-- --------------------------------------------------------

--
-- Table structure for table `pds_records`
--

CREATE TABLE `pds_records` (
  `pds_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `personal_info` text DEFAULT NULL,
  `family_background` text DEFAULT NULL,
  `education` text DEFAULT NULL,
  `civil_service` text DEFAULT NULL,
  `work_experience` text DEFAULT NULL,
  `voluntary_work` text DEFAULT NULL,
  `trainings` text DEFAULT NULL,
  `other_info` text DEFAULT NULL,
  `pds_references` text DEFAULT NULL,
  `q4_questions` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `pds_character_references` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pds_records`
--

INSERT INTO `pds_records` (`pds_id`, `employee_id`, `personal_info`, `family_background`, `education`, `civil_service`, `work_experience`, `voluntary_work`, `trainings`, `other_info`, `pds_references`, `q4_questions`, `created_at`, `updated_at`, `pds_character_references`) VALUES
(1, 30, '{\"last_name\":\"ARESGA\",\"first_name\":\"KIMBERLY\",\"middle_name\":\"ARESGA\",\"suffix\":\"\",\"dob\":\"2001-06-07\",\"pob\":\"IGUIG, CAGAYAN\",\"sex\":\"Male\",\"civil_status\":\"Single\",\"height\":\"1.65\",\"weight\":\"60\",\"blood_type\":\"B-\",\"umid\":\"21346767\",\"pagibig\":\"44\",\"philhealth\":\"4\",\"psn\":\"4\",\"tin\":\"4\",\"agency_no\":\"4\",\"citizenship\":\"Filipino\",\"dual_by\":\"\",\"dual_country\":\"\",\"res_housenum\":\"4\",\"res_street\":\"4\",\"res_subdivision\":\"4\",\"res_barangay\":\"4\",\"res_city\":\"4\",\"res_province\":\"4\",\"res_zip\":\"4\",\"perm_same\":true,\"perm_housenum\":\"\",\"perm_street\":\"\",\"perm_subdivision\":\"\",\"perm_barangay\":\"\",\"perm_city\":\"\",\"perm_province\":\"\",\"perm_zip\":\"\",\"tel\":\"4\",\"mobile\":\"4\",\"email\":\"4\",\"gov_id_type\":\"4\",\"gov_id_no\":\"4\",\"gov_id_issuance\":\"4\",\"date_accomplished\":\"2026-03-28\"}', '{\"sp_surname\":\"4\",\"sp_firstname\":\"4\",\"sp_ext\":\"4\",\"sp_middle\":\"4\",\"sp_occupation\":\"4\",\"sp_employer\":\"4\",\"sp_biz_addr\":\"4\",\"sp_tel\":\"4\",\"fa_surname\":\"4\",\"fa_firstname\":\"4\",\"fa_ext\":\"4\",\"fa_middle\":\"4\",\"mo_surname\":\"4\",\"mo_firstname\":\"4\",\"mo_middle\":\"4\",\"children\":[{\"name\":\"4\",\"dob\":\"2026-03-28\"}]}', '[{\"level\":\"Elementary\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Secondary\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Vocational\\/Trade Course\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"College\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Graduate Studies\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"}]', '[{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"}]', '[{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"}]', '[{\"org_name\":\"\",\"org_address\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"position\":\"\"},{\"org_name\":\"\",\"org_address\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"position\":\"\"},{\"org_name\":\"\",\"org_address\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"position\":\"\"},{\"org_name\":\"\",\"org_address\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"position\":\"\"},{\"org_name\":\"\",\"org_address\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"position\":\"\"},{\"org_name\":\"\",\"org_address\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"position\":\"\"},{\"org_name\":\"\",\"org_address\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"position\":\"\"},{\"org_name\":\"\",\"org_address\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"position\":\"\"}]', '[{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"}]', '{\"skills\":\"\",\"distinctions\":\"\",\"memberships\":\"\",\"skills_arr\":[],\"distinctions_arr\":[],\"memberships_arr\":[]}', '[{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"}]', '{\"q34a\":\"No\",\"q34b\":\"No\",\"q34_details\":\"\",\"q35a\":\"No\",\"q35a_details\":\"\",\"q35b\":\"No\",\"q35b_details\":\"\",\"q35b_date\":\"\",\"q35b_status\":\"\",\"q36\":\"No\",\"q36_details\":\"\",\"q37\":\"No\",\"q37_details\":\"\",\"q38a\":\"No\",\"q38a_details\":\"\",\"q38b\":\"No\",\"q38b_details\":\"\",\"q39\":\"No\",\"q39_details\":\"\",\"q40a\":false,\"q40a_detail\":\"\",\"q40b\":false,\"q40b_detail\":\"\",\"q40c\":false,\"q40c_detail\":\"\"}', '2026-03-13 03:58:15', '2026-04-02 08:37:58', '[{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"Gener\",\"address\":\"\",\"tel\":\"\"}]'),
(2, 32, '{\"last_name\":\"Raymundo\",\"first_name\":\"\",\"middle_name\":\"\",\"suffix\":\"\",\"dob\":\"\",\"pob\":\"\",\"sex\":\"\",\"civil_status\":\"\",\"height\":\"\",\"weight\":\"\",\"blood_type\":\"\",\"umid\":\"\",\"pagibig\":\"\",\"philhealth\":\"\",\"psn\":\"\",\"tin\":\"\",\"agency_no\":\"\",\"citizenship\":\"Filipino\",\"dual_by\":\"\",\"dual_country\":\"\",\"res_housenum\":\"\",\"res_street\":\"\",\"res_subdivision\":\"\",\"res_barangay\":\"\",\"res_city\":\"\",\"res_province\":\"\",\"res_zip\":\"\",\"perm_same\":false,\"perm_housenum\":\"\",\"perm_street\":\"\",\"perm_subdivision\":\"\",\"perm_barangay\":\"\",\"perm_city\":\"\",\"perm_province\":\"\",\"perm_zip\":\"\",\"tel\":\"\",\"mobile\":\"\",\"email\":\"\",\"gov_id_type\":\"\",\"gov_id_no\":\"\",\"gov_id_issuance\":\"\",\"date_accomplished\":\"\"}', '{\"sp_surname\":\"\",\"sp_firstname\":\"\",\"sp_ext\":\"\",\"sp_middle\":\"\",\"sp_occupation\":\"\",\"sp_employer\":\"\",\"sp_biz_addr\":\"\",\"sp_tel\":\"\",\"fa_surname\":\"\",\"fa_firstname\":\"\",\"fa_ext\":\"\",\"fa_middle\":\"\",\"mo_surname\":\"\",\"mo_firstname\":\"\",\"mo_middle\":\"\",\"children\":[]}', '[{\"level\":\"Elementary\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Secondary\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Vocational\\/Trade Course\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"College\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Graduate Studies\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"}]', '[{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"}]', '[{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"}]', '[]', '[]', '{\"skills\":\"\",\"distinctions\":\"\",\"memberships\":\"\"}', '[{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"}]', '{\"q34a\":\"No\",\"q34b\":\"No\",\"q34_details\":\"\",\"q35a\":\"No\",\"q35a_details\":\"\",\"q35b\":\"No\",\"q35b_details\":\"\",\"q35b_date\":\"\",\"q35b_status\":\"\",\"q36\":\"No\",\"q36_details\":\"\",\"q37\":\"No\",\"q37_details\":\"\",\"q38a\":\"No\",\"q38a_details\":\"\",\"q38b\":\"No\",\"q38b_details\":\"\",\"q39\":\"No\",\"q39_details\":\"\",\"q40a\":false,\"q40a_detail\":\"\",\"q40b\":false,\"q40b_detail\":\"\",\"q40c\":false,\"q40c_detail\":\"\"}', '2026-03-14 08:00:51', '2026-03-14 08:00:51', NULL),
(3, 49, '{\"last_name\":\"\",\"first_name\":\"\",\"middle_name\":\"\",\"suffix\":\"\",\"dob\":\"\",\"pob\":\"\",\"sex\":\"\",\"civil_status\":\"\",\"height\":\"\",\"weight\":\"\",\"blood_type\":\"\",\"umid\":\"\",\"pagibig\":\"\",\"philhealth\":\"\",\"psn\":\"\",\"tin\":\"\",\"agency_no\":\"\",\"citizenship\":\"Filipino\",\"dual_by\":\"\",\"dual_country\":\"\",\"res_housenum\":\"\",\"res_street\":\"\",\"res_subdivision\":\"\",\"res_barangay\":\"\",\"res_city\":\"\",\"res_province\":\"\",\"res_zip\":\"\",\"perm_same\":false,\"perm_housenum\":\"\",\"perm_street\":\"\",\"perm_subdivision\":\"\",\"perm_barangay\":\"\",\"perm_city\":\"\",\"perm_province\":\"\",\"perm_zip\":\"\",\"tel\":\"\",\"mobile\":\"\",\"email\":\"\",\"gov_id_type\":\"\",\"gov_id_no\":\"\",\"gov_id_issuance\":\"\",\"date_accomplished\":\"\"}', '{\"sp_surname\":\"\",\"sp_firstname\":\"\",\"sp_ext\":\"\",\"sp_middle\":\"\",\"sp_occupation\":\"\",\"sp_employer\":\"\",\"sp_biz_addr\":\"\",\"sp_tel\":\"\",\"fa_surname\":\"\",\"fa_firstname\":\"\",\"fa_ext\":\"\",\"fa_middle\":\"\",\"mo_surname\":\"\",\"mo_firstname\":\"\",\"mo_middle\":\"\",\"children\":[]}', '[{\"level\":\"Elementary\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Secondary\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Vocational\\/Trade Course\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"College\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Graduate Studies\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"}]', '[{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"}]', '[{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"}]', '[]', '[]', '{\"skills\":\"\",\"distinctions\":\"\",\"memberships\":\"\",\"skills_arr\":[],\"distinctions_arr\":[],\"memberships_arr\":[]}', '[{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"}]', '{\"q34a\":\"No\",\"q34b\":\"No\",\"q34_details\":\"\",\"q35a\":\"No\",\"q35a_details\":\"\",\"q35b\":\"No\",\"q35b_details\":\"\",\"q35b_date\":\"\",\"q35b_status\":\"\",\"q36\":\"No\",\"q36_details\":\"\",\"q37\":\"No\",\"q37_details\":\"\",\"q38a\":\"No\",\"q38a_details\":\"\",\"q38b\":\"No\",\"q38b_details\":\"\",\"q39\":\"No\",\"q39_details\":\"\",\"q40a\":false,\"q40a_detail\":\"\",\"q40b\":false,\"q40b_detail\":\"\",\"q40c\":false,\"q40c_detail\":\"\"}', '2026-03-30 16:16:04', '2026-03-30 16:16:04', '[{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"}]'),
(4, 58, '{\"last_name\":\"VILLAN\",\"first_name\":\"KIMBERLY\",\"middle_name\":\"\",\"suffix\":\"\",\"dob\":\"\",\"pob\":\"\",\"sex\":\"\",\"civil_status\":\"\",\"height\":\"\",\"weight\":\"\",\"blood_type\":\"\",\"umid\":\"\",\"pagibig\":\"\",\"philhealth\":\"\",\"psn\":\"\",\"tin\":\"\",\"agency_no\":\"\",\"citizenship\":\"Filipino\",\"dual_by\":\"\",\"dual_country\":\"\",\"res_housenum\":\"\",\"res_street\":\"\",\"res_subdivision\":\"\",\"res_barangay\":\"\",\"res_city\":\"\",\"res_province\":\"\",\"res_zip\":\"\",\"perm_same\":false,\"perm_housenum\":\"\",\"perm_street\":\"\",\"perm_subdivision\":\"\",\"perm_barangay\":\"\",\"perm_city\":\"\",\"perm_province\":\"\",\"perm_zip\":\"\",\"tel\":\"\",\"mobile\":\"\",\"email\":\"\",\"gov_id_type\":\"\",\"gov_id_no\":\"\",\"gov_id_issuance\":\"\",\"date_accomplished\":\"\"}', '{\"sp_surname\":\"\",\"sp_firstname\":\"\",\"sp_ext\":\"\",\"sp_middle\":\"\",\"sp_occupation\":\"\",\"sp_employer\":\"\",\"sp_biz_addr\":\"\",\"sp_tel\":\"\",\"fa_surname\":\"\",\"fa_firstname\":\"\",\"fa_ext\":\"\",\"fa_middle\":\"\",\"mo_surname\":\"\",\"mo_firstname\":\"\",\"mo_middle\":\"\",\"children\":[{\"name\":\"\",\"dob\":\"\"}]}', '[{\"level\":\"Elementary\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Secondary\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Vocational\\/Trade Course\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"College\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Graduate Studies\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"}]', '[{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"}]', '[{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"}]', '[]', '[]', '{\"skills\":\"\",\"distinctions\":\"\",\"memberships\":\"\",\"skills_arr\":[],\"distinctions_arr\":[],\"memberships_arr\":[]}', '[{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"}]', '{\"q34a\":\"No\",\"q34b\":\"No\",\"q34_details\":\"\",\"q35a\":\"No\",\"q35a_details\":\"\",\"q35b\":\"No\",\"q35b_details\":\"\",\"q35b_date\":\"\",\"q35b_status\":\"\",\"q36\":\"No\",\"q36_details\":\"\",\"q37\":\"No\",\"q37_details\":\"\",\"q38a\":\"No\",\"q38a_details\":\"\",\"q38b\":\"No\",\"q38b_details\":\"\",\"q39\":\"No\",\"q39_details\":\"\",\"q40a\":false,\"q40a_detail\":\"\",\"q40b\":false,\"q40b_detail\":\"\",\"q40c\":false,\"q40c_detail\":\"\"}', '2026-04-11 09:42:47', '2026-06-30 06:02:49', '[{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"}]'),
(5, 59, '{\"last_name\":\"\",\"first_name\":\"\",\"middle_name\":\"\",\"suffix\":\"\",\"dob\":\"\",\"pob\":\"\",\"sex\":\"\",\"civil_status\":\"\",\"height\":\"\",\"weight\":\"\",\"blood_type\":\"\",\"umid\":\"\",\"pagibig\":\"\",\"philhealth\":\"\",\"psn\":\"\",\"tin\":\"\",\"agency_no\":\"\",\"citizenship\":\"Dual Citizenship\",\"dual_by\":\"\",\"dual_country\":\"\",\"res_housenum\":\"\",\"res_street\":\"\",\"res_subdivision\":\"\",\"res_barangay\":\"\",\"res_city\":\"\",\"res_province\":\"\",\"res_zip\":\"\",\"perm_same\":false,\"perm_housenum\":\"\",\"perm_street\":\"\",\"perm_subdivision\":\"\",\"perm_barangay\":\"\",\"perm_city\":\"\",\"perm_province\":\"\",\"perm_zip\":\"\",\"tel\":\"\",\"mobile\":\"\",\"email\":\"\",\"gov_id_type\":\"\",\"gov_id_no\":\"\",\"gov_id_issuance\":\"\",\"date_accomplished\":\"\"}', '{\"sp_surname\":\"\",\"sp_firstname\":\"\",\"sp_ext\":\"\",\"sp_middle\":\"\",\"sp_occupation\":\"\",\"sp_employer\":\"\",\"sp_biz_addr\":\"\",\"sp_tel\":\"\",\"fa_surname\":\"\",\"fa_firstname\":\"\",\"fa_ext\":\"\",\"fa_middle\":\"\",\"mo_surname\":\"\",\"mo_firstname\":\"\",\"mo_middle\":\"\",\"children\":[{\"name\":\"\",\"dob\":\"\"},{\"name\":\"\",\"dob\":\"\"}]}', '[{\"level\":\"Elementary\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Secondary\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Vocational\\/Trade Course\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"College\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"},{\"level\":\"Graduate Studies\",\"school\":\"\",\"degree\":\"\",\"from\":\"\",\"to\":\"\",\"units\":\"\",\"year_grad\":\"\",\"honors\":\"\"}]', '[{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"},{\"eligibility\":\"\",\"rating\":\"\",\"exam_date\":\"\",\"exam_place\":\"\",\"license_no\":\"\",\"license_validity\":\"\"}]', '[{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"},{\"date_from\":\"\",\"date_to\":\"\",\"position\":\"\",\"dept_agency\":\"\",\"monthly_salary\":\"\",\"salary_grade\":\"\",\"status\":\"\",\"govt_service\":\"\"}]', '[{\"org_name\":\"\",\"org_address\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"position\":\"\"},{\"org_name\":\"\",\"org_address\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"position\":\"\"},{\"org_name\":\"\",\"org_address\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"position\":\"\"}]', '[{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"},{\"title\":\"\",\"date_from\":\"\",\"date_to\":\"\",\"no_hours\":\"\",\"ld_type\":\"\",\"conducted_by\":\"\"}]', '{\"skills\":\"\",\"distinctions\":\"\",\"memberships\":\"\",\"skills_arr\":[],\"distinctions_arr\":[],\"memberships_arr\":[]}', '[{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"}]', '{\"q34a\":\"No\",\"q34b\":\"No\",\"q34_details\":\"\",\"q35a\":\"No\",\"q35a_details\":\"\",\"q35b\":\"No\",\"q35b_details\":\"\",\"q35b_date\":\"\",\"q35b_status\":\"\",\"q36\":\"No\",\"q36_details\":\"\",\"q37\":\"No\",\"q37_details\":\"\",\"q38a\":\"No\",\"q38a_details\":\"\",\"q38b\":\"No\",\"q38b_details\":\"\",\"q39\":\"No\",\"q39_details\":\"\",\"q40a\":false,\"q40a_detail\":\"\",\"q40b\":false,\"q40b_detail\":\"\",\"q40c\":false,\"q40c_detail\":\"\"}', '2026-04-12 11:29:04', '2026-06-29 17:04:47', '[{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"},{\"name\":\"\",\"address\":\"\",\"tel\":\"\"}]');

-- --------------------------------------------------------

--
-- Table structure for table `saln_records`
--

CREATE TABLE `saln_records` (
  `saln_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `saln_year` year(4) NOT NULL,
  `as_of_date` date DEFAULT NULL,
  `filing_type` varchar(20) DEFAULT NULL,
  `filing_mode` varchar(20) DEFAULT NULL,
  `multi_spouse` varchar(255) DEFAULT NULL,
  `declarant_info` text DEFAULT NULL,
  `spouse_info` text DEFAULT NULL,
  `children_info` text DEFAULT NULL,
  `real_properties` text DEFAULT NULL,
  `personal_properties` text DEFAULT NULL,
  `liabilities` text DEFAULT NULL,
  `no_biz` tinyint(1) DEFAULT 0,
  `biz_interests` text DEFAULT NULL,
  `no_rel` tinyint(1) DEFAULT 0,
  `relatives_govt` text DEFAULT NULL,
  `real_subtotal` decimal(14,2) DEFAULT NULL,
  `personal_subtotal` decimal(14,2) DEFAULT NULL,
  `total_assets` decimal(14,2) DEFAULT NULL,
  `total_liabilities` decimal(14,2) DEFAULT NULL,
  `net_worth` decimal(14,2) DEFAULT NULL,
  `as1_real_properties` longtext DEFAULT NULL COMMENT 'JSON array: AS-1 additional declarant real properties',
  `as1_personal_properties` longtext DEFAULT NULL COMMENT 'JSON array: AS-1 additional declarant personal properties',
  `as1_liabilities` longtext DEFAULT NULL COMMENT 'JSON array: AS-1 additional declarant liabilities',
  `as1_biz_interests` longtext DEFAULT NULL COMMENT 'JSON array: AS-1 additional declarant business interests',
  `as1_real_subtotal` decimal(18,2) DEFAULT NULL,
  `as1_personal_subtotal` decimal(18,2) DEFAULT NULL,
  `as1_total_assets` decimal(18,2) DEFAULT NULL,
  `as1_total_liabilities` decimal(18,2) DEFAULT NULL,
  `as2_name_info` longtext DEFAULT NULL COMMENT 'JSON: AS-2 name, position, agency/office',
  `as2_real_properties` longtext DEFAULT NULL COMMENT 'JSON array: AS-2 spouse/children real properties',
  `as2_personal_properties` longtext DEFAULT NULL COMMENT 'JSON array: AS-2 spouse/children personal properties',
  `as2_liabilities` longtext DEFAULT NULL COMMENT 'JSON array: AS-2 spouse/children liabilities',
  `as2_biz_interests` longtext DEFAULT NULL COMMENT 'JSON array: AS-2 spouse/children business interests',
  `gov_id` varchar(100) DEFAULT NULL,
  `gov_id_no` varchar(100) DEFAULT NULL,
  `gov_id_issued` date DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `saln_records`
--

INSERT INTO `saln_records` (`saln_id`, `employee_id`, `saln_year`, `as_of_date`, `filing_type`, `filing_mode`, `multi_spouse`, `declarant_info`, `spouse_info`, `children_info`, `real_properties`, `personal_properties`, `liabilities`, `no_biz`, `biz_interests`, `no_rel`, `relatives_govt`, `real_subtotal`, `personal_subtotal`, `total_assets`, `total_liabilities`, `net_worth`, `as1_real_properties`, `as1_personal_properties`, `as1_liabilities`, `as1_biz_interests`, `as1_real_subtotal`, `as1_personal_subtotal`, `as1_total_assets`, `as1_total_liabilities`, `as2_name_info`, `as2_real_properties`, `as2_personal_properties`, `as2_liabilities`, `as2_biz_interests`, `gov_id`, `gov_id_no`, `gov_id_issued`, `created_at`, `updated_at`) VALUES
(1, 30, '2026', '2026-03-15', 'Annual', 'NA', '', '{\"lastname\":\"Villan\",\"firstname\":\"\",\"mi\":\"\",\"position\":\"\",\"agency\":\"\",\"office_addr\":\"\"}', '{\"lastname\":\"\",\"firstname\":\"\",\"mi\":\"\",\"position\":\"\",\"agency\":\"\",\"office_addr\":\"\"}', '[]', '[]', '[]', '[]', 0, '[]', 0, '[]', 0.00, 0.00, 0.00, 0.00, 0.00, '[{\"description\":\"ZXMN\",\"kind\":\"VM VKK\",\"location\":\"VKND;S\",\"assessed_value\":\"\",\"market_value\":\"\",\"acq_year\":\"\",\"acq_mode\":\"\",\"acq_cost\":\"\"}]', '[]', '[]', '[]', 0.00, 0.00, 0.00, 0.00, '{\"full_name\":\"\",\"position\":\"\",\"agency\":\"\"}', '[]', '[]', '[]', '[]', '', '', '0000-00-00', '2026-03-13 04:16:47', '2026-04-06 18:03:40'),
(2, 58, '2026', '0000-00-00', '', 'NA', '', '{\"lastname\":\"\",\"firstname\":\"\",\"mi\":\"\",\"position\":\"\",\"agency\":\"\",\"office_addr\":\"\"}', '{\"lastname\":\"\",\"firstname\":\"\",\"mi\":\"\",\"position\":\"\",\"agency\":\"\",\"office_addr\":\"\"}', '[]', '[]', '[]', '[]', 0, '[]', 0, '[]', 0.00, 0.00, 0.00, 0.00, 0.00, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '', '', '0000-00-00', '2026-06-30 02:15:38', '2026-06-30 06:03:51');

-- --------------------------------------------------------

--
-- Table structure for table `service_records`
--

CREATE TABLE `service_records` (
  `sr_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `date_from` date DEFAULT NULL,
  `date_to` date DEFAULT NULL,
  `designation` varchar(200) DEFAULT NULL,
  `status` varchar(100) DEFAULT NULL,
  `salary` decimal(14,2) DEFAULT NULL,
  `branch` varchar(200) DEFAULT NULL,
  `station` varchar(200) DEFAULT NULL,
  `lvbs_wo_pay` varchar(200) DEFAULT NULL,
  `separation_date` date DEFAULT NULL,
  `separation_cause` varchar(200) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `service_records`
--

INSERT INTO `service_records` (`sr_id`, `employee_id`, `date_from`, `date_to`, `designation`, `status`, `salary`, `branch`, `station`, `lvbs_wo_pay`, `separation_date`, `separation_cause`, `created_at`, `updated_at`) VALUES
(1, 30, '2026-05-23', NULL, 'Administrative Aide V', 'Permanent', NULL, 'Local Government Unit', 'Enrile, Cagayan', '', NULL, '', '2026-04-03 20:22:20', '2026-04-03 20:22:20'),
(2, 30, '2026-05-23', NULL, 'Administrative Aide V', 'Permanent', 30000.00, 'Local Government Unit', 'Enrile, Cagayan', '', NULL, '', '2026-04-03 20:50:24', '2026-04-03 20:50:24'),
(3, 30, '2026-05-23', '2027-04-03', 'Administrative Aide V', 'Permanent', NULL, 'Local Government Unit', 'Enrile, Cagayan', '', '2026-04-03', 'Resigned', '2026-04-03 21:27:33', '2026-04-03 21:43:28'),
(4, 49, '2026-03-23', NULL, 'Secretary', 'Permanent', NULL, 'Local Government Unit', 'Enrile, Cagayan', '', NULL, '', '2026-04-03 21:55:25', '2026-04-03 21:55:25'),
(5, 32, '2023-02-14', NULL, 'Head, Human Resource', 'Permanent', NULL, 'Local Government Unit', 'Enrile, Cagayan', '', NULL, '', '2026-04-10 11:03:40', '2026-04-10 11:03:40'),
(6, 32, '2023-02-14', NULL, 'Head, Human Resource', 'Permanent', NULL, 'Local Government Unit', 'Enrile, Cagayan', '', NULL, '', '2026-04-10 11:31:43', '2026-04-10 11:31:43'),
(8, 58, '2026-04-10', NULL, 'Administrative Aide V', 'Permanent', NULL, 'Local Government Unit', 'Enrile, Cagayan', '', NULL, '', '2026-04-10 11:32:44', '2026-04-10 11:32:44'),
(9, 58, '2026-04-10', NULL, 'Administrative Aide V', 'Permanent', NULL, 'Local Government Unit', 'Enrile, Cagayan', '', NULL, 'Resigned', '2026-04-12 12:33:38', '2026-04-12 12:33:38'),
(10, 58, '2026-04-10', NULL, 'Administrative Aide V', 'Permanent', NULL, 'Local Government Unit', 'Enrile, Cagayan', '', NULL, 'promotion', '2026-04-12 12:34:40', '2026-04-12 12:34:40'),
(11, 58, '2026-04-10', NULL, 'Administrative Aide V', 'Permanent', NULL, 'Local Government Unit', 'Enrile, Cagayan', '', NULL, '', '2026-04-12 12:35:41', '2026-04-12 12:35:41'),
(12, 59, '2026-04-01', NULL, 'Administrative Aide VI', 'Permanent', NULL, 'Local Government Unit', 'Enrile, Cagayan', '', NULL, '', '2026-04-12 17:35:16', '2026-04-12 17:35:16'),
(13, 58, '2026-04-10', NULL, 'Administrative Aide V', 'Permanent', NULL, 'Local Government Unit', 'Enrile, Cagayan', '', NULL, '', '2026-06-30 12:05:55', '2026-06-30 12:05:55');

-- --------------------------------------------------------

--
-- Table structure for table `sr_requests`
--

CREATE TABLE `sr_requests` (
  `req_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `purpose` varchar(300) DEFAULT NULL,
  `status` enum('Pending','Approved','Denied') DEFAULT 'Pending',
  `admin_note` text DEFAULT NULL,
  `requested_at` datetime DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sr_requests`
--

INSERT INTO `sr_requests` (`req_id`, `employee_id`, `purpose`, `status`, `admin_note`, `requested_at`, `resolved_at`) VALUES
(1, 30, 'For Employment application', 'Approved', '', '2026-03-22 23:56:30', '2026-04-03 21:12:39'),
(2, 49, 'Scholarship', 'Approved', '', '2026-03-28 15:04:50', '2026-04-03 21:12:22'),
(3, 30, 'For Employment', 'Approved', '', '2026-04-07 20:24:35', '2026-04-08 13:03:47'),
(4, 58, '', 'Approved', '', '2026-04-10 11:31:15', '2026-04-10 11:32:26'),
(5, 57, '', 'Approved', '', '2026-04-10 11:32:12', '2026-04-10 11:32:22'),
(6, 58, '', 'Approved', '', '2026-04-10 12:21:18', '2026-04-12 12:33:02'),
(7, 58, 'scho', 'Approved', '', '2026-04-12 12:34:08', '2026-06-30 12:05:34'),
(8, 59, 'Test', 'Approved', '', '2026-04-12 17:33:38', '2026-04-12 17:35:23'),
(9, 58, 'basta', 'Approved', '', '2026-07-21 11:20:19', '2026-07-21 11:21:04');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_group` varchar(50) NOT NULL DEFAULT 'general',
  `label` varchar(150) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `input_type` enum('text','number','email','textarea','select','toggle','password') NOT NULL DEFAULT 'text',
  `options_json` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_group`, `label`, `description`, `input_type`, `options_json`, `updated_by`, `updated_at`) VALUES
(1, 'org_name', 'LGU Enrile', 'organization', 'Organization Name', 'Official name of the LGU', 'text', NULL, NULL, '2026-03-12 08:30:46'),
(2, 'org_address', 'Enrile, Cagayan, Philippines', 'organization', 'Address', 'Complete office address', 'textarea', NULL, NULL, '2026-03-12 08:30:46'),
(3, 'org_email', 'hrm@lguenrile.gov.ph', 'organization', 'HR Email Address', 'Official HR e-mail shown on documents', 'email', NULL, NULL, '2026-03-12 08:30:46'),
(4, 'org_phone', '', 'organization', 'Contact Number', 'HR office telephone / mobile number', 'text', NULL, NULL, '2026-03-12 08:30:46'),
(5, 'org_logo_path', '../images/Enrile.png', 'organization', 'Logo Path', 'Relative path to the logo image', 'text', NULL, NULL, '2026-03-12 08:30:46'),
(6, 'leave_max_pending_days', '30', 'leave', 'Pending Request Timeout (days)', 'Auto-expire pending leave requests after N days', 'number', NULL, NULL, '2026-03-12 08:30:46'),
(7, 'leave_allow_half_day', '1', 'leave', 'Allow Half-Day Leaves', 'Employees can file half-day leave requests', 'toggle', NULL, NULL, '2026-03-12 08:30:46'),
(8, 'leave_require_attachment', '0', 'leave', 'Require Attachment', 'Require supporting document for leave requests', 'toggle', NULL, NULL, '2026-03-12 08:30:46'),
(9, 'leave_auto_approve', '0', 'leave', 'Auto-Approve Leaves', 'Automatically approve leave requests on filing', 'toggle', NULL, NULL, '2026-03-12 08:30:46'),
(10, 'leave_credits_reset_month', '1', 'leave', 'Credits Reset Month', 'Month (1–12) when annual leave credits reset', 'number', NULL, NULL, '2026-03-12 08:30:46'),
(11, 'contract_expiry_warn_days', '30', 'contracts', 'Expiry Warning (days)', 'Warn HR when contracts expire within N days', 'number', NULL, NULL, '2026-03-12 08:30:46'),
(12, 'contract_auto_archive', '1', 'contracts', 'Auto-Archive Expired', 'Automatically archive contracts past their end date', 'toggle', NULL, NULL, '2026-03-12 08:30:46'),
(13, 'notif_email_enabled', '0', 'notifications', 'Email Notifications', 'Send e-mail alerts for leave requests and contracts', 'toggle', NULL, NULL, '2026-03-12 08:30:46'),
(14, 'notif_smtp_host', '', 'notifications', 'SMTP Host', 'e.g. smtp.gmail.com', 'text', NULL, NULL, '2026-03-12 08:30:46'),
(15, 'notif_smtp_port', '587', 'notifications', 'SMTP Port', 'Usually 587 (TLS) or 465 (SSL)', 'number', NULL, NULL, '2026-03-12 08:30:46'),
(16, 'notif_smtp_user', '', 'notifications', 'SMTP Username', 'SMTP login e-mail address', 'email', NULL, NULL, '2026-03-12 08:30:46'),
(17, 'notif_smtp_pass', '', 'notifications', 'SMTP Password', 'SMTP login password', 'password', NULL, NULL, '2026-03-12 08:30:46'),
(18, 'notif_from_name', 'LGU Enrile HRMS', 'notifications', 'Sender Name', 'Name shown in outgoing e-mails', 'text', NULL, NULL, '2026-03-12 08:30:46'),
(19, 'sec_session_timeout', '120', 'security', 'Session Timeout (minutes)', 'Automatically log out inactive users after N minutes', 'number', NULL, NULL, '2026-03-12 08:30:46'),
(20, 'sec_max_login_attempts', '5', 'security', 'Max Login Attempts', 'Lock account after N failed login attempts', 'number', NULL, NULL, '2026-03-12 08:30:46'),
(21, 'sec_password_min_len', '8', 'security', 'Min Password Length', 'Minimum characters required for passwords', 'number', NULL, NULL, '2026-03-12 08:30:46'),
(22, 'sec_force_strong_pass', '1', 'security', 'Require Strong Password', 'Require uppercase, number, and symbol in passwords', 'toggle', NULL, NULL, '2026-03-12 08:30:46'),
(23, 'sys_date_format', 'd M Y', 'system', 'Date Display Format', 'PHP date() format used throughout the system', 'text', NULL, NULL, '2026-03-12 08:30:46'),
(24, 'sys_timezone', 'Asia/Manila', 'system', 'Timezone', 'PHP timezone for all date/time operations', 'select', '[\"Asia/Manila\",\"Asia/Singapore\",\"UTC\"]', NULL, '2026-03-12 08:30:46'),
(25, 'sys_items_per_page', '10', 'system', 'Records Per Page', 'Default number of rows shown in list views', 'number', NULL, NULL, '2026-03-12 08:30:46'),
(26, 'sys_maintenance_mode', '0', 'system', 'Maintenance Mode', 'Block all non-admin logins for system maintenance', 'toggle', NULL, NULL, '2026-03-12 08:30:46');

-- --------------------------------------------------------

--
-- Table structure for table `user_accounts`
--

CREATE TABLE `user_accounts` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `email` varchar(180) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','hr_staff','dept_head','employee') NOT NULL DEFAULT 'employee',
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `failed_login_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reset_token` varchar(160) DEFAULT NULL COMMENT 'sha256-hashed password-reset token (raw token is emailed)',
  `reset_expires` datetime DEFAULT NULL COMMENT 'UTC expiry for the reset token (1 hour after issue)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_accounts`
--

INSERT INTO `user_accounts` (`user_id`, `email`, `password_hash`, `role`, `is_active`, `last_login`, `remember_token`, `failed_login_attempts`, `locked_until`, `must_change_password`, `created_at`, `updated_at`, `reset_token`, `reset_expires`) VALUES
(1, 'admin@lgu-enrile.gov.ph', '$2y$10$pzW5xmXlsHHDugMF8F0ZR.mC0RFLhA7dLFl.GOaU.xxw4z7ocnNla', 'admin', 1, '2026-07-29 04:03:44', 'c7c26ae65544d196396b13753e3c2ba34daeb1c756e26107703e89f8731c6237', 0, NULL, 0, '2026-03-09 16:22:09', '2026-07-29 04:03:44', NULL, NULL),
(49, 'kimberlyy@gmail.com', '$2y$10$WC7dIDLhrf0xwBl2vxDOlOdBwTNPpuu9HPerNopn1UZDGoUSFnq/.', 'dept_head', 1, '2026-07-24 02:15:37', NULL, 0, NULL, 0, '2026-04-10 03:27:42', '2026-07-24 02:15:37', NULL, NULL),
(50, 'ronnie@gmail.com', '$2y$10$/V7nLje1VsQR.WwhXKHSqeg8gqZiSte13Rlq13R8iCtkNyjBLmndm', 'employee', 1, '2026-07-24 03:01:25', NULL, 0, NULL, 0, '2026-04-12 09:02:09', '2026-07-24 03:01:25', NULL, NULL),
(54, 'nicolecarlet@gmail.com', '$2y$10$NA/da1f8h96e5EbKMDrMHOB6.vX9guaofemgCgBPg8FeYDHjn14kC', 'employee', 1, '2026-06-30 03:24:53', NULL, 0, NULL, 1, '2026-06-30 03:23:23', '2026-06-30 03:24:53', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_log_user` (`user_id`),
  ADD KEY `idx_log_created` (`created_at`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`announcement_id`),
  ADD KEY `fk_ann_user` (`posted_by`);

--
-- Indexes for table `archive_log`
--
ALTER TABLE `archive_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_record` (`record_type`,`record_id`),
  ADD KEY `idx_performed_by` (`performed_by`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `idx_sender` (`sender_id`),
  ADD KEY `idx_receiver` (`receiver_id`),
  ADD KEY `idx_conv` (`sender_id`,`receiver_id`),
  ADD KEY `idx_unread` (`receiver_id`,`is_read`);

--
-- Indexes for table `coe_requests`
--
ALTER TABLE `coe_requests`
  ADD PRIMARY KEY (`coe_request_id`),
  ADD KEY `fk_coe_req_reviewer` (`reviewed_by`),
  ADD KEY `idx_coe_status` (`status`),
  ADD KEY `idx_coe_employee_filed` (`employee_id`,`filed_at`),
  ADD KEY `idx_coe_deleted_status` (`is_deleted`,`status`);

--
-- Indexes for table `contracts`
--
ALTER TABLE `contracts`
  ADD PRIMARY KEY (`contract_id`),
  ADD KEY `idx_employee_id` (`employee_id`),
  ADD KEY `idx_department_id` (`department_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_end_date` (`end_date`),
  ADD KEY `idx_contracts_emp` (`employee_id`),
  ADD KEY `idx_contracts_status` (`status`),
  ADD KEY `idx_contracts_end` (`end_date`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `fk_head_employee` (`head_employee_id`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `fk_doc_emp` (`employee_id`),
  ADD KEY `fk_doc_user` (`uploaded_by`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`employee_id`),
  ADD UNIQUE KEY `employee_code` (`employee_code`),
  ADD KEY `fk_emp_user` (`user_id`),
  ADD KEY `idx_emp_dept` (`department_id`),
  ADD KEY `idx_emp_status` (`employment_status`),
  ADD KEY `idx_emp_type` (`employment_type`);

--
-- Indexes for table `holidays`
--
ALTER TABLE `holidays`
  ADD PRIMARY KEY (`holiday_id`),
  ADD UNIQUE KEY `holiday_date` (`holiday_date`);

--
-- Indexes for table `leave_balances`
--
ALTER TABLE `leave_balances`
  ADD PRIMARY KEY (`balance_id`),
  ADD UNIQUE KEY `uq_emp_type_year` (`employee_id`,`leave_type_id`,`calendar_year`),
  ADD UNIQUE KEY `uq_lb_emp_type_year` (`employee_id`,`leave_type_id`,`calendar_year`),
  ADD KEY `fk_bal_type` (`leave_type_id`),
  ADD KEY `idx_bal_year` (`calendar_year`);

--
-- Indexes for table `leave_notifications`
--
ALTER TABLE `leave_notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `fk_notif_req` (`request_id`),
  ADD KEY `fk_notif_type` (`leave_type_id`),
  ADD KEY `idx_emp_sent` (`employee_id`,`is_sent`),
  ADD KEY `idx_target_date` (`target_date`),
  ADD KEY `idx_notif_type` (`notification_type`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `fk_req_type` (`leave_type_id`),
  ADD KEY `fk_req_reviewer` (`reviewed_by`),
  ADD KEY `idx_req_employee` (`employee_id`),
  ADD KEY `idx_req_status` (`status`),
  ADD KEY `idx_req_dates` (`date_from`,`date_to`),
  ADD KEY `fk_req_dept_head` (`dept_head_id`);

--
-- Indexes for table `leave_types`
--
ALTER TABLE `leave_types`
  ADD PRIMARY KEY (`leave_type_id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `pds_records`
--
ALTER TABLE `pds_records`
  ADD PRIMARY KEY (`pds_id`),
  ADD KEY `idx_emp` (`employee_id`);

--
-- Indexes for table `saln_records`
--
ALTER TABLE `saln_records`
  ADD PRIMARY KEY (`saln_id`),
  ADD UNIQUE KEY `uq_emp_year` (`employee_id`,`saln_year`),
  ADD KEY `idx_emp` (`employee_id`);

--
-- Indexes for table `service_records`
--
ALTER TABLE `service_records`
  ADD PRIMARY KEY (`sr_id`),
  ADD KEY `idx_sr_emp` (`employee_id`);

--
-- Indexes for table `sr_requests`
--
ALTER TABLE `sr_requests`
  ADD PRIMARY KEY (`req_id`),
  ADD KEY `idx_srr_emp` (`employee_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `user_accounts`
--
ALTER TABLE `user_accounts`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=452;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `announcement_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `archive_log`
--
ALTER TABLE `archive_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `coe_requests`
--
ALTER TABLE `coe_requests`
  MODIFY `coe_request_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `contracts`
--
ALTER TABLE `contracts`
  MODIFY `contract_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `document_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `employee_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT for table `holidays`
--
ALTER TABLE `holidays`
  MODIFY `holiday_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_balances`
--
ALTER TABLE `leave_balances`
  MODIFY `balance_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1322;

--
-- AUTO_INCREMENT for table `leave_notifications`
--
ALTER TABLE `leave_notifications`
  MODIFY `notification_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `request_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=126;

--
-- AUTO_INCREMENT for table `leave_types`
--
ALTER TABLE `leave_types`
  MODIFY `leave_type_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `pds_records`
--
ALTER TABLE `pds_records`
  MODIFY `pds_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `saln_records`
--
ALTER TABLE `saln_records`
  MODIFY `saln_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `service_records`
--
ALTER TABLE `service_records`
  MODIFY `sr_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `sr_requests`
--
ALTER TABLE `sr_requests`
  MODIFY `req_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `user_accounts`
--
ALTER TABLE `user_accounts`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `user_accounts` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `fk_ann_user` FOREIGN KEY (`posted_by`) REFERENCES `user_accounts` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `coe_requests`
--
ALTER TABLE `coe_requests`
  ADD CONSTRAINT `fk_coe_req_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_coe_req_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `user_accounts` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `fk_head_employee` FOREIGN KEY (`head_employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE SET NULL ON UPDATE NO ACTION;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `fk_doc_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_doc_user` FOREIGN KEY (`uploaded_by`) REFERENCES `user_accounts` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_emp_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_emp_user` FOREIGN KEY (`user_id`) REFERENCES `user_accounts` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `leave_balances`
--
ALTER TABLE `leave_balances`
  ADD CONSTRAINT `fk_bal_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bal_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`leave_type_id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_notifications`
--
ALTER TABLE `leave_notifications`
  ADD CONSTRAINT `fk_notif_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_notif_req` FOREIGN KEY (`request_id`) REFERENCES `leave_requests` (`request_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_notif_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`leave_type_id`);

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `fk_req_dept_head` FOREIGN KEY (`dept_head_id`) REFERENCES `user_accounts` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_req_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_req_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `user_accounts` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_req_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`leave_type_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

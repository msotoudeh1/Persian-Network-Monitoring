-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Oct 26, 2026 at 07:24 PM
-- Server version: 8.0.36
-- PHP Version: 8.4.27

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `qyewltkd_monitor`
--

-- --------------------------------------------------------

--
-- Table structure for table `devices`
--

CREATE TABLE `devices` (
  `id` int NOT NULL,
  `device_name` varchar(255) NOT NULL,
  `ip_address` varchar(255) NOT NULL,
  `port` int UNSIGNED DEFAULT '80',
  `ping_threshold` int DEFAULT '100',
  `status` enum('online','offline','warning') DEFAULT 'offline',
  `last_check` timestamp NULL DEFAULT NULL,
  `response_time` float DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `devices`
--

INSERT INTO `devices` (`id`, `device_name`, `ip_address`, `port`, `ping_threshold`, `status`, `last_check`, `response_time`, `created_at`) VALUES
(17, '1.1.1.1', '1.1.1.1', 80, 100, 'online', '2026-10-26 15:53:17', 85.68, '2026-09-11 13:57:13'),
(19, 'google.com', 'google.com', 80, 100, 'online', '2026-10-26 15:53:17', 85.12, '2026-10-26 15:53:10');

-- --------------------------------------------------------

--
-- Table structure for table `monitoring_logs`
--

CREATE TABLE `monitoring_logs` (
  `id` int NOT NULL,
  `device_id` int NOT NULL,
  `status` enum('online','offline','warning') DEFAULT NULL,
  `response_time` float DEFAULT NULL,
  `check_method` varchar(10) DEFAULT 'tcp',
  `checked_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `monitoring_logs`
--

INSERT INTO `monitoring_logs` (`id`, `device_id`, `status`, `response_time`, `check_method`, `checked_at`) VALUES
(181, 17, 'online', 79.53, 'tcp', '2026-09-11 13:57:16'),
(182, 17, 'online', 76.22, 'tcp', '2026-09-11 13:57:27'),
(183, 17, 'online', 84.01, 'tcp', '2026-09-11 13:57:34'),
(184, 17, 'online', 81.94, 'tcp', '2026-09-11 13:57:41'),
(185, 17, 'online', 85.29, 'tcp', '2026-09-11 13:58:04'),
(186, 17, 'online', 86.24, 'tcp', '2026-09-12 12:10:12'),
(187, 17, 'online', 83.39, 'tcp', '2026-09-17 05:55:58'),
(188, 17, 'online', 78.57, 'tcp', '2026-09-19 10:53:44'),
(189, 17, 'online', 84.36, 'tcp', '2026-09-21 06:06:19'),
(190, 17, 'online', 85.65, 'tcp', '2026-09-21 06:07:06'),
(191, 17, 'online', 88.45, 'tcp', '2026-09-23 07:25:31'),
(192, 17, 'online', 88.64, 'tcp', '2026-10-02 13:31:02'),
(193, 17, 'online', 81.67, 'tcp', '2026-10-07 09:44:03'),
(194, 17, 'online', 84.28, 'tcp', '2026-10-07 09:44:11'),
(195, 17, 'online', 85.02, 'tcp', '2026-10-07 09:44:14'),
(196, 17, 'online', 86.91, 'tcp', '2026-10-07 09:44:18'),
(197, 17, 'online', 89.85, 'tcp', '2026-10-07 09:44:33'),
(198, 17, 'online', 87.4, 'tcp', '2026-10-07 09:44:44'),
(199, 17, 'online', 86.04, 'tcp', '2026-10-07 09:45:32'),
(200, 17, 'online', 86.64, 'tcp', '2026-10-22 10:59:11'),
(202, 17, 'online', 86.37, 'tcp', '2026-10-22 11:00:05'),
(204, 17, 'online', 84.34, 'tcp', '2026-10-22 11:01:25'),
(206, 17, 'online', 81.92, 'tcp', '2026-10-22 11:04:23'),
(208, 17, 'online', 81.04, 'tcp', '2026-10-26 07:32:32'),
(210, 17, 'online', 84.75, 'tcp', '2026-10-26 07:33:33'),
(212, 17, 'online', 87.28, 'tcp', '2026-10-26 07:33:42'),
(214, 17, 'online', 83.92, 'tcp', '2026-10-26 15:52:42'),
(215, 17, 'online', 82.61, 'tcp', '2026-10-26 15:53:16'),
(216, 19, 'warning', 166.15, 'tcp', '2026-10-26 15:53:16'),
(217, 17, 'online', 85.68, 'tcp', '2026-10-26 15:53:17'),
(218, 19, 'online', 85.12, 'tcp', '2026-10-26 15:53:17');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `last_login`, `created_at`) VALUES
(1, 'admin', '$2y$10$SPjphQm16oJzuHEHJrbfi.Qj9zX75aMEHnBlaeVlAW7QNbcKGxfw.', 'مدیر سیستم', NULL, NULL, '2026-09-08 07:31:01');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `devices`
--
ALTER TABLE `devices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_ip_address` (`ip_address`);

--
-- Indexes for table `monitoring_logs`
--
ALTER TABLE `monitoring_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_device_id` (`device_id`),
  ADD KEY `idx_checked_at` (`checked_at`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `devices`
--
ALTER TABLE `devices`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `monitoring_logs`
--
ALTER TABLE `monitoring_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=219;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `monitoring_logs`
--
ALTER TABLE `monitoring_logs`
  ADD CONSTRAINT `monitoring_logs_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

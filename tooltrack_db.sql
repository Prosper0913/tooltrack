-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 18, 2026 at 06:11 PM
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
-- Database: `tooltrack_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `borrowers`
--

CREATE TABLE `borrowers` (
  `id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `id_number` varchar(50) NOT NULL,
  `type` enum('Student','Faculty','Staff','Guest') NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `active_borrows` int(11) NOT NULL DEFAULT 0,
  `total_borrows` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=borrowable, 0=soft-deactivated by CMS sync',
  `last_synced_at` datetime DEFAULT NULL COMMENT 'Last time CMS pushed an update for this borrower',
  `source` varchar(32) DEFAULT NULL COMMENT 'cms_push for sync-created rows, NULL for manual'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `borrowers`
--

INSERT INTO `borrowers` (`id`, `full_name`, `id_number`, `type`, `email`, `phone`, `active_borrows`, `total_borrows`, `created_at`, `updated_at`, `is_active`, `last_synced_at`, `source`) VALUES
(3, 'Bonjorno casipe', '34423232222', 'Guest', '', '', 1, 1, '2026-07-27 12:06:06', '2026-07-27 12:06:06', 1, NULL, NULL),
(5, 'Jess Anthony V.. Dances', '20222644', 'Student', NULL, NULL, 0, 0, '2026-08-13 23:20:31', '2026-08-14 00:52:44', 1, NULL, NULL),
(13, 'Andy H. Lim', '2023-00109', 'Student', NULL, NULL, 0, 0, '2026-08-14 01:01:00', '2026-08-14 01:06:03', 1, '2026-08-14 01:05:56', 'cms_push'),
(39, 'Matt Maldo. Albuera', '02', 'Student', NULL, NULL, 0, 0, '2026-08-14 01:47:07', '2026-08-14 13:30:43', 1, '2026-08-14 13:30:43', 'cms_push'),
(47, 'Keth Dalag. Dalugdugan', '08', 'Student', NULL, NULL, 0, 0, '2026-08-14 01:54:12', '2026-08-14 13:30:43', 1, '2026-08-14 13:30:43', 'cms_push'),
(50, 'Precious', '20222761', 'Guest', '', '', 0, 0, '2026-08-14 12:04:26', '2026-08-14 12:04:26', 1, NULL, NULL),
(51, 'Precious', '20226373', 'Guest', '', '', 1, 1, '2026-08-14 12:05:11', '2026-08-14 12:05:11', 1, NULL, NULL),
(54, 'John Gil Igama. Tolibas', '25', 'Student', NULL, NULL, 1, 1, '2026-08-14 14:56:02', '2026-08-14 22:31:40', 1, '2026-08-14 22:31:40', 'cms_push'),
(56, 'Mary Rose Abrig. Sugapa', '24', 'Student', NULL, NULL, 0, 0, '2026-08-14 22:21:21', '2026-08-14 22:31:40', 1, '2026-08-14 22:31:40', 'cms_push'),
(60, 'Neil Vincent Tanto. Marquez', '16', 'Student', NULL, NULL, 0, 0, '2026-08-14 22:35:20', '2026-08-14 22:37:48', 1, '2026-08-14 22:37:48', 'cms_push'),
(63, 'Robin Padilla', '8080', 'Student', NULL, NULL, 0, 0, '2026-08-16 13:28:10', '2026-08-16 13:37:15', 1, '2026-08-16 13:37:15', 'cms_push'),
(81, 'Yi Sun-shin', '0101', 'Student', '', '', 0, 0, '2026-08-16 13:56:48', '2026-08-16 13:56:48', 1, NULL, NULL),
(82, 'Gagam Boy', '000000', 'Student', NULL, NULL, 0, 0, '2026-08-22 20:44:25', '2026-08-22 21:11:10', 1, '2026-08-22 21:11:10', 'cms_push'),
(84, 'student test', 'test123', 'Student', NULL, NULL, 0, 0, '2026-09-12 21:28:42', '2026-09-12 21:37:03', 1, '2026-09-12 21:37:03', 'cms_push');

-- --------------------------------------------------------

--
-- Table structure for table `borrower_enrollments`
--

CREATE TABLE `borrower_enrollments` (
  `id` int(11) NOT NULL,
  `borrower_id` int(11) NOT NULL COMMENT 'FK -> borrowers.id',
  `cms_section_id` int(10) UNSIGNED NOT NULL COMMENT 'CMS sections.id',
  `cms_subject_id` int(11) NOT NULL COMMENT 'CMS subjects.id',
  `course` varchar(80) NOT NULL COMMENT 'Denormalized from sections.course for filtering',
  `section_name` varchar(80) NOT NULL COMMENT 'Denormalized from sections.section_name',
  `subject_code` varchar(20) NOT NULL COMMENT 'Denormalized from subjects.subject_code',
  `subject_name` varchar(100) NOT NULL COMMENT 'Denormalized from subjects.subject_name',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=current enrollment, 0=withdrawn in CMS',
  `synced_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `borrower_enrollments`
--

INSERT INTO `borrower_enrollments` (`id`, `borrower_id`, `cms_section_id`, `cms_subject_id`, `course`, `section_name`, `subject_code`, `subject_name`, `is_active`, `synced_at`, `created_at`) VALUES
(1, 13, 26, 18, 'FPST', '1-A', 'GLM101', 'GLM TEST', 0, '2026-08-14 01:21:52', '2026-08-14 01:01:41'),
(3, 39, 26, 18, 'FPST', '1-A', 'GLM101', 'GLM TEST', 1, '2026-08-14 13:30:43', '2026-08-14 01:47:07'),
(11, 47, 26, 18, 'FPST', '1-A', 'GLM101', 'GLM TEST', 1, '2026-08-14 13:30:43', '2026-08-14 01:54:12'),
(16, 54, 27, 19, 'FPST', 'Foods 9', 'Foods 9', 'Foods 9', 1, '2026-08-14 22:31:40', '2026-08-14 14:56:02'),
(18, 56, 27, 19, 'FPST', 'Foods 9', 'Foods 9', 'Foods 9', 1, '2026-08-14 22:31:40', '2026-08-14 22:21:21'),
(22, 60, 26, 19, 'FPST', 'Foods 9', 'Foods 9', 'Foods 9', 1, '2026-08-14 22:37:48', '2026-08-14 22:35:20'),
(25, 63, 28, 19, 'FPST', '1-A', 'Foods 9', 'Foods 9', 0, '2026-08-16 13:38:08', '2026-08-16 13:28:10'),
(43, 81, 26, 0, 'FPST', '1-A', '', 'Manual Section Enrollment', 1, '2026-08-16 14:18:50', '2026-08-16 14:18:50'),
(44, 82, 28, 19, 'FPST', '1-A', 'Foods 9', 'Foods 9', 1, '2026-08-22 21:11:10', '2026-08-22 20:44:25'),
(46, 84, 33, 23, 'FPST', 'Nior', 'F9', 'Foods 9', 1, '2026-09-12 21:37:03', '2026-09-12 21:28:42');

-- --------------------------------------------------------

--
-- Table structure for table `tools`
--

CREATE TABLE `tools` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `code` varchar(50) NOT NULL,
  `category` varchar(80) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `available` int(11) NOT NULL DEFAULT 0,
  `min_stock` int(11) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=active/borrowable, 0=retired (soft-removed, transaction history kept)',
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tools`
--

INSERT INTO `tools` (`id`, `name`, `code`, `category`, `quantity`, `available`, `min_stock`, `is_active`, `description`, `created_at`, `updated_at`) VALUES
(1, 'spoon', 'SP-101', 'Electronics', 12, 8, 2, 1, '', '2026-07-20 22:22:43', '2026-08-14 15:03:08'),
(2, 'frying pan', 'FP-101', 'Mechanical', 22, 12, 10, 1, '', '2026-08-14 12:03:35', '2026-08-14 12:05:11'),
(3, 'fork', 'F001-ORK', 'Mechanical', 50, 50, 10, 1, '', '2026-09-12 21:32:22', '2026-09-12 21:32:22');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `txn_id` varchar(30) NOT NULL,
  `type` enum('borrow','return') NOT NULL,
  `tool_id` int(11) NOT NULL,
  `borrower_id` int(11) DEFAULT NULL,
  `status` enum('active','returned') NOT NULL DEFAULT 'active',
  `condition` enum('good','minor','damaged') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `qty` int(11) NOT NULL DEFAULT 1,
  `qty_returned` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `txn_id`, `type`, `tool_id`, `borrower_id`, `status`, `condition`, `notes`, `due_date`, `returned_at`, `created_at`, `qty`, `qty_returned`) VALUES
(1, 'TXN-2026-B860BC', 'borrow', 1, NULL, 'returned', NULL, '', '2026-07-28', '2026-07-21 17:01:40', '2026-07-21 22:08:11', 1, 0),
(2, 'TXN-2026-40EAD4', 'return', 1, NULL, 'returned', 'good', '', NULL, '2026-07-21 17:01:40', '2026-07-21 23:01:40', 1, 0),
(3, 'TXN-2026-EC8D83', 'borrow', 1, 3, 'active', NULL, '', '2026-08-03', NULL, '2026-07-27 12:06:06', 3, 0),
(4, 'TXN-2026-7917FD', 'borrow', 2, 51, 'active', NULL, '', '2026-08-21', NULL, '2026-08-14 12:05:11', 10, 0),
(5, 'TXN-2026-CA531B', 'borrow', 1, 54, 'active', NULL, '', '2026-08-05', NULL, '2026-08-14 15:03:08', 1, 0);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'Department Admin',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `email`, `password`, `role`, `created_at`) VALUES
(1, 'NIORRITOS', 'admin', 'admin@school.edu', '$2y$10$oCLk5miIvA/taNF7D4ROYe7XxdIcDwCe/aYgxdhrz0H0.0hTPQ2Im', 'Admin', '2026-07-20 22:19:22'),
(2, 'staff', 'staff', 'staff@school.edu', '$2y$10$b0aUD0fi.mlCUjYuzWb3Mu.it4.AU4fnvYQqK5sYNGQeKSrrQilWO', 'Staff', '2026-09-16 14:48:42');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `borrowers`
--
ALTER TABLE `borrowers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_number` (`id_number`),
  ADD KEY `idx_borrowers_is_active` (`is_active`);

--
-- Indexes for table `borrower_enrollments`
--
ALTER TABLE `borrower_enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_borrower_section_subject` (`cms_section_id`,`cms_subject_id`,`borrower_id`),
  ADD KEY `idx_be_borrower` (`borrower_id`),
  ADD KEY `idx_be_active` (`is_active`),
  ADD KEY `idx_be_course` (`course`);

--
-- Indexes for table `tools`
--
ALTER TABLE `tools`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `txn_id` (`txn_id`),
  ADD KEY `borrower_id` (`borrower_id`),
  ADD KEY `idx_transactions_type` (`type`),
  ADD KEY `idx_transactions_status` (`status`),
  ADD KEY `idx_transactions_tool` (`tool_id`),
  ADD KEY `idx_transactions_date` (`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `borrowers`
--
ALTER TABLE `borrowers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=86;

--
-- AUTO_INCREMENT for table `borrower_enrollments`
--
ALTER TABLE `borrower_enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `tools`
--
ALTER TABLE `tools`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `borrower_enrollments`
--
ALTER TABLE `borrower_enrollments`
  ADD CONSTRAINT `fk_be_borrower` FOREIGN KEY (`borrower_id`) REFERENCES `borrowers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`tool_id`) REFERENCES `tools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`borrower_id`) REFERENCES `borrowers` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

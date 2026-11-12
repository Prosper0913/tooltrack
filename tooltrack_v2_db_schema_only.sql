-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 01, 2026 at 03:01 PM
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
-- Database: `tooltrack v2_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'FK -> users.id; NULL for a failed login (no known user) or if the user was later deleted',
  `username_snapshot` varchar(100) DEFAULT NULL COMMENT 'Kept even if the user account is later deleted',
  `role_snapshot` varchar(20) DEFAULT NULL,
  `action` varchar(40) NOT NULL COMMENT 'login | login_failed | logout | borrow | return | tool_create | tool_edit | tool_retire | tool_reactivate | borrower_create | borrower_edit | borrower_deactivate | borrower_reactivate | user_create | user_delete | password_reset | password_change | replacement_request | replacement_resolved | cms_sync',
  `description` varchar(500) NOT NULL COMMENT 'Human-readable summary, e.g. "Borrowed 2x Spoon (SP-101) for Juan Dela Cruz"',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IPv4 or IPv6',
  `user_agent` varchar(255) DEFAULT NULL COMMENT 'Raw browser User-Agent string',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_log`
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
  `year_level` varchar(30) DEFAULT NULL,
  `active_borrows` int(11) NOT NULL DEFAULT 0,
  `total_borrows` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=borrowable, 0=soft-deactivated by CMS sync',
  `last_synced_at` datetime DEFAULT NULL COMMENT 'Last time CMS pushed an update for this borrower',
  `source` varchar(32) DEFAULT NULL COMMENT 'cms_push for sync-created rows, NULL for manual'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `year_level` varchar(30) DEFAULT NULL,
  `subject_code` varchar(20) NOT NULL COMMENT 'Denormalized from subjects.subject_code',
  `subject_name` varchar(100) NOT NULL COMMENT 'Denormalized from subjects.subject_name',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=current enrollment, 0=withdrawn in CMS',
  `synced_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_adjustments`
--

CREATE TABLE `inventory_adjustments` (
  `id` int(11) NOT NULL,
  `tool_id` int(11) NOT NULL,
  `action` enum('repair','recover','qty_edit') NOT NULL,
  `from_state` varchar(20) DEFAULT NULL,
  `to_state` varchar(20) DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_resolutions`
--

CREATE TABLE `inventory_resolutions` (
  `id` int(11) NOT NULL,
  `charge_case_id` int(11) NOT NULL,
  `tool_id` int(11) DEFAULT NULL,
  `affected_quantity` int(11) NOT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'awaiting_action',
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `received_quantity` int(11) NOT NULL DEFAULT 0,
  `restored_quantity` int(11) NOT NULL DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_resolution_events`
--

CREATE TABLE `inventory_resolution_events` (
  `id` int(11) NOT NULL,
  `resolution_id` int(11) NOT NULL,
  `old_status` varchar(40) NOT NULL,
  `new_status` varchar(40) NOT NULL,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_resolution_receipts`
--

CREATE TABLE `inventory_resolution_receipts` (
  `id` int(11) NOT NULL,
  `resolution_id` int(11) NOT NULL,
  `tool_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `event_kind` varchar(20) NOT NULL,
  `disposition` varchar(24) NOT NULL,
  `received_date` date NOT NULL,
  `reference_number` varchar(100) NOT NULL DEFAULT '',
  `notes` varchar(500) NOT NULL DEFAULT '',
  `stock_history_id` int(11) DEFAULT NULL,
  `removal_history_id` int(11) DEFAULT NULL,
  `adjustment_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(120) NOT NULL,
  `request_token` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `missing_reports`
--

CREATE TABLE `missing_reports` (
  `id` int(11) NOT NULL,
  `report_no` varchar(20) NOT NULL,
  `request_token` varchar(64) DEFAULT NULL,
  `borrow_txn_id` int(11) NOT NULL,
  `tool_id` int(11) NOT NULL,
  `borrower_id` int(11) DEFAULT NULL,
  `borrower_name_snapshot` varchar(150) DEFAULT NULL,
  `borrower_id_number_snapshot` varchar(50) DEFAULT NULL,
  `qty_reported` int(11) NOT NULL,
  `qty_missing` int(11) NOT NULL DEFAULT 0,
  `qty_replaced` int(11) NOT NULL DEFAULT 0,
  `qty_recovered` int(11) NOT NULL DEFAULT 0,
  `qty_surplus` int(11) NOT NULL DEFAULT 0,
  `missing_date` date NOT NULL,
  `reason` varchar(255) NOT NULL,
  `investigation_notes` text DEFAULT NULL,
  `status` enum('pending','confirmed','resolved','rejected') NOT NULL DEFAULT 'pending',
  `open_key` int(11) DEFAULT NULL,
  `reported_by` int(11) DEFAULT NULL,
  `reported_by_name` varchar(120) DEFAULT NULL,
  `reported_at` datetime NOT NULL DEFAULT current_timestamp(),
  `confirmed_by` int(11) DEFAULT NULL,
  `confirmed_by_name` varchar(120) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `decision_notes` varchar(500) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `missing_report_events`
--

CREATE TABLE `missing_report_events` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `action` varchar(30) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `detail` varchar(500) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `replacement_incident_sources`
--

CREATE TABLE `replacement_incident_sources` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `return_transaction_id` int(11) DEFAULT NULL,
  `missing_report_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `replacement_payment_claims`
--

CREATE TABLE `replacement_payment_claims` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `receipt_number` varchar(80) NOT NULL,
  `payment_date` date NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `charge_version` int(11) NOT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_by_name` varchar(120) NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `verified_by` int(11) DEFAULT NULL,
  `verified_by_name` varchar(120) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `verification_notes` varchar(500) DEFAULT NULL,
  `active_receipt` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `active_request_id` int(11) DEFAULT NULL,
  `verified_by_role` varchar(24) DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `voided_by_name` varchar(120) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `void_reason` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `replacement_requests`
--

CREATE TABLE `replacement_requests` (
  `id` int(11) NOT NULL,
  `tool_id` int(11) DEFAULT NULL COMMENT 'NULL if the tool no longer exists / was fully removed',
  `tool_name_snapshot` varchar(150) NOT NULL COMMENT 'Tool name at time of request, kept even if the tool row is later deleted',
  `reason` varchar(20) DEFAULT 'damaged',
  `responsible_borrower_id` int(11) DEFAULT NULL,
  `responsible_name_snapshot` varchar(150) DEFAULT NULL,
  `missing_report_id` int(11) DEFAULT NULL,
  `quantity_needed` int(11) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending',
  `requested_by` int(11) DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL COMMENT 'FK -> users.id',
  `resolution_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `writeoff_applied` tinyint(1) NOT NULL DEFAULT 0,
  `workflow_mode` varchar(20) NOT NULL DEFAULT 'legacy',
  `financial_status` varchar(24) NOT NULL DEFAULT 'legacy',
  `responsibility` varchar(24) NOT NULL DEFAULT 'unreviewed',
  `incident_quantity` int(11) DEFAULT NULL,
  `amount_due` decimal(12,2) DEFAULT NULL,
  `charge_version` int(11) NOT NULL DEFAULT 0,
  `payment_version` int(11) NOT NULL DEFAULT 0,
  `needs_financial_review` tinyint(1) NOT NULL DEFAULT 0,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_by_name` varchar(120) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `financial_closed_at` datetime DEFAULT NULL,
  `submission_token` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `requested_by_snapshot` varchar(120) DEFAULT NULL,
  `charge_assessed_at` datetime DEFAULT NULL,
  `case_completed_at` datetime DEFAULT NULL,
  `case_completed_by` int(11) DEFAULT NULL,
  `parent_case_id` int(11) DEFAULT NULL,
  `assessed_unit_charge` decimal(12,2) DEFAULT NULL,
  `line_subtotal` decimal(12,2) DEFAULT NULL,
  `tool_code_snapshot` varchar(50) DEFAULT NULL,
  `inventory_status` varchar(32) NOT NULL DEFAULT 'Pending Review'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `replacement_request_events`
--

CREATE TABLE `replacement_request_events` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `action` varchar(40) NOT NULL,
  `before_state` longtext DEFAULT NULL,
  `after_state` longtext DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(120) NOT NULL,
  `user_role` varchar(24) NOT NULL,
  `request_token` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `payload_hash` char(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_history`
--

CREATE TABLE `stock_history` (
  `id` int(11) NOT NULL,
  `tool_id` int(11) NOT NULL,
  `tool_name` varchar(150) NOT NULL,
  `transaction_type` enum('Stock Added','Stock Removed') NOT NULL,
  `quantity` int(11) NOT NULL COMMENT 'Units added or removed (always positive)',
  `previous_stock` int(11) NOT NULL COMMENT 'tools.quantity before the change',
  `updated_stock` int(11) NOT NULL COMMENT 'tools.quantity after the change',
  `remarks` varchar(500) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `event_kind` varchar(16) NOT NULL DEFAULT 'adjustment',
  `tool_code` varchar(50) DEFAULT NULL,
  `request_token` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `original_quantity` int(11) DEFAULT NULL COMMENT 'Quantity when the tool was first registered. NULL = unknown (tool predates this column)',
  `available` int(11) NOT NULL DEFAULT 0,
  `minor_qty` int(11) NOT NULL DEFAULT 0 COMMENT 'Subset of `available` that has minor wear',
  `damaged_qty` int(11) NOT NULL DEFAULT 0 COMMENT 'Present but not borrowable until repaired and cleared',
  `missing_qty` int(11) NOT NULL DEFAULT 0 COMMENT 'Cannot be accounted for; still part of `quantity`',
  `min_stock` int(11) NOT NULL DEFAULT 1,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=active/borrowable, 0=retired (soft-removed, transaction history kept)',
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `replaceable_qty` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tool_sku_issued`
--

CREATE TABLE `tool_sku_issued` (
  `sku` varchar(50) NOT NULL,
  `category_code` varchar(8) DEFAULT NULL,
  `sequence_number` int(10) UNSIGNED DEFAULT NULL,
  `tool_id` int(11) DEFAULT NULL,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tool_sku_sequences`
--

CREATE TABLE `tool_sku_sequences` (
  `category_name` varchar(80) NOT NULL,
  `category_code` varchar(8) NOT NULL,
  `last_number` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tool_sku_sequences`
--


-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `txn_id` varchar(30) NOT NULL,
  `borrow_txn_id` int(11) DEFAULT NULL,
  `type` enum('borrow','return') NOT NULL,
  `tool_id` int(11) NOT NULL,
  `borrower_id` int(11) DEFAULT NULL,
  `status` enum('active','returned') NOT NULL DEFAULT 'active',
  `is_late` tinyint(1) NOT NULL DEFAULT 0,
  `condition` enum('good','minor','damaged','missing') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `returnee_name` varchar(150) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `due_time` time DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `qty` int(11) NOT NULL DEFAULT 1,
  `qty_returned` int(11) NOT NULL DEFAULT 0,
  `qty_missing` int(11) NOT NULL DEFAULT 0,
  `borrower_name_snapshot` varchar(150) DEFAULT NULL,
  `borrower_id_number_snapshot` varchar(50) DEFAULT NULL,
  `return_batch` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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


--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `borrowers`
--
ALTER TABLE `borrowers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_number` (`id_number`),
  ADD KEY `idx_borrowers_is_active` (`is_active`),
  ADD KEY `idx_borrowers_name` (`full_name`),
  ADD KEY `idx_borrowers_type_active` (`type`,`is_active`);

--
-- Indexes for table `borrower_enrollments`
--
ALTER TABLE `borrower_enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_borrower_section_subject` (`cms_section_id`,`cms_subject_id`,`borrower_id`),
  ADD KEY `idx_be_borrower` (`borrower_id`),
  ADD KEY `idx_be_active` (`is_active`),
  ADD KEY `idx_be_course` (`course`),
  ADD KEY `idx_enroll_borrower` (`borrower_id`,`is_active`,`cms_subject_id`);

--
-- Indexes for table `inventory_adjustments`
--
ALTER TABLE `inventory_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_adj_tool` (`tool_id`,`created_at`),
  ADD KEY `fk_adj_user` (`user_id`);

--
-- Indexes for table `inventory_resolutions`
--
ALTER TABLE `inventory_resolutions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_resolution_case` (`charge_case_id`),
  ADD KEY `idx_resolution_status` (`status`);

--
-- Indexes for table `inventory_resolution_events`
--
ALTER TABLE `inventory_resolution_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_resolution_events` (`resolution_id`,`id`);

--
-- Indexes for table `inventory_resolution_receipts`
--
ALTER TABLE `inventory_resolution_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_resolution_receipt_token` (`request_token`),
  ADD KEY `idx_resolution_receipt` (`resolution_id`,`id`);

--
-- Indexes for table `missing_reports`
--
ALTER TABLE `missing_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mr_report_no` (`report_no`),
  ADD UNIQUE KEY `uq_mr_token` (`request_token`),
  ADD UNIQUE KEY `uq_mr_one_pending` (`open_key`),
  ADD KEY `idx_mr_loan` (`borrow_txn_id`),
  ADD KEY `idx_mr_tool` (`tool_id`),
  ADD KEY `idx_mr_status` (`status`);

--
-- Indexes for table `missing_report_events`
--
ALTER TABLE `missing_report_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mre_report` (`report_id`);

--
-- Indexes for table `replacement_incident_sources`
--
ALTER TABLE `replacement_incident_sources`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ris_return` (`request_id`,`return_transaction_id`),
  ADD UNIQUE KEY `uq_ris_missing` (`request_id`,`missing_report_id`),
  ADD KEY `idx_ris_return` (`return_transaction_id`),
  ADD KEY `idx_ris_missing` (`missing_report_id`);

--
-- Indexes for table `replacement_payment_claims`
--
ALTER TABLE `replacement_payment_claims`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rpc_receipt` (`active_receipt`),
  ADD UNIQUE KEY `uq_rpc_active_request` (`active_request_id`),
  ADD KEY `idx_rpc_request` (`request_id`,`id`),
  ADD KEY `fk_rpc_submitter` (`submitted_by`),
  ADD KEY `fk_rpc_verifier` (`verified_by`),
  ADD KEY `idx_rpc_collection` (`status`,`payment_date`,`request_id`);

--
-- Indexes for table `replacement_requests`
--
ALTER TABLE `replacement_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rr_submission` (`submission_token`),
  ADD KEY `idx_rr_status` (`status`),
  ADD KEY `idx_rr_tool` (`tool_id`),
  ADD KEY `fk_rr_requested_by` (`requested_by`),
  ADD KEY `fk_rr_resolved_by` (`resolved_by`),
  ADD KEY `idx_rr_responsible` (`responsible_borrower_id`),
  ADD KEY `idx_rr_missing_report` (`missing_report_id`),
  ADD KEY `idx_rr_financial` (`workflow_mode`,`financial_status`,`needs_financial_review`),
  ADD KEY `idx_rr_assessed` (`charge_assessed_at`),
  ADD KEY `idx_case_completed` (`workflow_mode`,`case_completed_at`,`id`),
  ADD KEY `idx_charge_parent` (`parent_case_id`,`id`);

--
-- Indexes for table `replacement_request_events`
--
ALTER TABLE `replacement_request_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rre_token` (`request_token`),
  ADD KEY `idx_rre_request` (`request_id`,`id`),
  ADD KEY `fk_rre_actor` (`user_id`);

--
-- Indexes for table `stock_history`
--
ALTER TABLE `stock_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stock_request` (`request_token`),
  ADD KEY `idx_sh_tool` (`tool_id`,`created_at`),
  ADD KEY `idx_sh_user` (`user_id`),
  ADD KEY `idx_sh_created` (`created_at`),
  ADD KEY `idx_stock_registration` (`tool_id`,`event_kind`),
  ADD KEY `idx_stock_created` (`created_at`,`id`);

--
-- Indexes for table `tools`
--
ALTER TABLE `tools`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD UNIQUE KEY `uq_tools_code` (`code`);

--
-- Indexes for table `tool_sku_issued`
--
ALTER TABLE `tool_sku_issued`
  ADD PRIMARY KEY (`sku`),
  ADD KEY `idx_sku_sequence` (`category_code`,`sequence_number`);

--
-- Indexes for table `tool_sku_sequences`
--
ALTER TABLE `tool_sku_sequences`
  ADD PRIMARY KEY (`category_name`),
  ADD UNIQUE KEY `category_code` (`category_code`);

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
  ADD KEY `idx_transactions_date` (`created_at`),
  ADD KEY `idx_borrow_txn` (`borrow_txn_id`),
  ADD KEY `idx_txn_borrower` (`borrower_id`,`type`,`status`,`due_date`),
  ADD KEY `idx_txn_type_created` (`type`,`created_at`),
  ADD KEY `idx_txn_type_status` (`type`,`status`,`created_at`),
  ADD KEY `idx_txn_overdue` (`type`,`status`,`due_date`),
  ADD KEY `idx_txn_tool_type_status` (`tool_id`,`type`,`status`),
  ADD KEY `idx_txn_return_batch` (`return_batch`,`borrow_txn_id`);

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
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=336;

--
-- AUTO_INCREMENT for table `borrowers`
--
ALTER TABLE `borrowers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=161;

--
-- AUTO_INCREMENT for table `borrower_enrollments`
--
ALTER TABLE `borrower_enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=120;

--
-- AUTO_INCREMENT for table `inventory_adjustments`
--
ALTER TABLE `inventory_adjustments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `inventory_resolutions`
--
ALTER TABLE `inventory_resolutions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `inventory_resolution_events`
--
ALTER TABLE `inventory_resolution_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `inventory_resolution_receipts`
--
ALTER TABLE `inventory_resolution_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `missing_reports`
--
ALTER TABLE `missing_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `missing_report_events`
--
ALTER TABLE `missing_report_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `replacement_incident_sources`
--
ALTER TABLE `replacement_incident_sources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `replacement_payment_claims`
--
ALTER TABLE `replacement_payment_claims`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `replacement_requests`
--
ALTER TABLE `replacement_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `replacement_request_events`
--
ALTER TABLE `replacement_request_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `stock_history`
--
ALTER TABLE `stock_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `tools`
--
ALTER TABLE `tools`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=105;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `borrower_enrollments`
--
ALTER TABLE `borrower_enrollments`
  ADD CONSTRAINT `fk_be_borrower` FOREIGN KEY (`borrower_id`) REFERENCES `borrowers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_adjustments`
--
ALTER TABLE `inventory_adjustments`
  ADD CONSTRAINT `fk_adj_tool` FOREIGN KEY (`tool_id`) REFERENCES `tools` (`id`),
  ADD CONSTRAINT `fk_adj_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `missing_reports`
--
ALTER TABLE `missing_reports`
  ADD CONSTRAINT `fk_mr_loan` FOREIGN KEY (`borrow_txn_id`) REFERENCES `transactions` (`id`),
  ADD CONSTRAINT `fk_mr_tool` FOREIGN KEY (`tool_id`) REFERENCES `tools` (`id`);

--
-- Constraints for table `missing_report_events`
--
ALTER TABLE `missing_report_events`
  ADD CONSTRAINT `fk_mre_report` FOREIGN KEY (`report_id`) REFERENCES `missing_reports` (`id`);

--
-- Constraints for table `replacement_incident_sources`
--
ALTER TABLE `replacement_incident_sources`
  ADD CONSTRAINT `fk_ris_missing` FOREIGN KEY (`missing_report_id`) REFERENCES `missing_reports` (`id`),
  ADD CONSTRAINT `fk_ris_request` FOREIGN KEY (`request_id`) REFERENCES `replacement_requests` (`id`),
  ADD CONSTRAINT `fk_ris_return` FOREIGN KEY (`return_transaction_id`) REFERENCES `transactions` (`id`);

--
-- Constraints for table `replacement_payment_claims`
--
ALTER TABLE `replacement_payment_claims`
  ADD CONSTRAINT `fk_rpc_request` FOREIGN KEY (`request_id`) REFERENCES `replacement_requests` (`id`),
  ADD CONSTRAINT `fk_rpc_submitter` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rpc_verifier` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `replacement_requests`
--
ALTER TABLE `replacement_requests`
  ADD CONSTRAINT `fk_rr_missing_report` FOREIGN KEY (`missing_report_id`) REFERENCES `missing_reports` (`id`),
  ADD CONSTRAINT `fk_rr_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rr_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rr_responsible` FOREIGN KEY (`responsible_borrower_id`) REFERENCES `borrowers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rr_tool` FOREIGN KEY (`tool_id`) REFERENCES `tools` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `replacement_request_events`
--
ALTER TABLE `replacement_request_events`
  ADD CONSTRAINT `fk_rre_actor` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rre_request` FOREIGN KEY (`request_id`) REFERENCES `replacement_requests` (`id`);

--
-- Constraints for table `stock_history`
--
ALTER TABLE `stock_history`
  ADD CONSTRAINT `fk_sh_tool` FOREIGN KEY (`tool_id`) REFERENCES `tools` (`id`),
  ADD CONSTRAINT `fk_sh_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `fk_txn_borrower` FOREIGN KEY (`borrower_id`) REFERENCES `borrowers` (`id`),
  ADD CONSTRAINT `fk_txn_tool` FOREIGN KEY (`tool_id`) REFERENCES `tools` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;


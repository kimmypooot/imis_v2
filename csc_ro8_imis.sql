-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 26, 2026 at 09:53 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.4.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `csc_ro8_imis`
--

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `summary` varchar(500) DEFAULT NULL,
  `body` text NOT NULL,
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `publish_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `featured` tinyint(1) NOT NULL DEFAULT 0,
  `author_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_tokens`
--

CREATE TABLE `api_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `integration` varchar(30) NOT NULL,
  `name` varchar(100) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `token_hint` varchar(12) NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `last_used_ip` varchar(45) DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `revoked_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `applications`
--

CREATE TABLE `applications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `short_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `feed_url` varchar(500) DEFAULT NULL,
  `feed_key` text DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `external` tinyint(1) NOT NULL DEFAULT 1,
  `featured` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `visibility` enum('public','authenticated','administrative') NOT NULL DEFAULT 'authenticated',
  `requires_access` tinyint(1) NOT NULL DEFAULT 0,
  `under_maintenance` tinyint(1) NOT NULL DEFAULT 0,
  `maintenance_message` varchar(500) DEFAULT NULL,
  `maintenance_started_at` timestamp NULL DEFAULT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `organizational_unit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `application_access`
--

CREATE TABLE `application_access` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `application_role_id` bigint(20) UNSIGNED NOT NULL,
  `via_designation` tinyint(1) NOT NULL DEFAULT 0,
  `designation_previous_role_id` bigint(20) UNSIGNED DEFAULT NULL,
  `granted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `application_listings`
--

CREATE TABLE `application_listings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `listable_type` varchar(255) NOT NULL,
  `listable_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `application_roles`
--

CREATE TABLE `application_roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `description` varchar(500) DEFAULT NULL,
  `launch_url` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `approval_delegations`
--

CREATE TABLE `approval_delegations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `delegator_id` bigint(20) UNSIGNED NOT NULL,
  `delegate_id` bigint(20) UNSIGNED NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `reason` varchar(200) DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `actor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `actor_label` varchar(255) DEFAULT NULL,
  `event` varchar(64) NOT NULL,
  `action` varchar(32) NOT NULL,
  `target_type` varchar(64) DEFAULT NULL,
  `target_id` bigint(20) UNSIGNED DEFAULT NULL,
  `target_label` varchar(255) DEFAULT NULL,
  `description` varchar(500) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `biometric_imports`
--

CREATE TABLE `biometric_imports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_hash` varchar(64) NOT NULL,
  `rows_read` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `rows_new` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `rows_duplicate` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `rows_skipped` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `rows_ignored` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `rows_before_start` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `new_by_month` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_by_month`)),
  `first_punch_at` datetime DEFAULT NULL,
  `last_punch_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `biometric_punches`
--

CREATE TABLE `biometric_punches` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `biometric_id` varchar(20) NOT NULL,
  `punched_at` datetime NOT NULL,
  `source` varchar(10) NOT NULL DEFAULT 'device',
  `reason` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` bigint(20) UNSIGNED DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `biometric_import_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `brand_assets`
--

CREATE TABLE `brand_assets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `key` varchar(32) NOT NULL,
  `path` varchar(255) NOT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `changelogs`
--

CREATE TABLE `changelogs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `version` varchar(20) NOT NULL,
  `title` varchar(150) NOT NULL,
  `release_date` date DEFAULT NULL,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `changelog_items`
--

CREATE TABLE `changelog_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `changelog_id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('feature','improvement','fix') NOT NULL DEFAULT 'feature',
  `description` varchar(500) NOT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `committees`
--

CREATE TABLE `committees` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `short_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `effective_from` date DEFAULT NULL,
  `effective_until` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `committee_members`
--

CREATE TABLE `committee_members` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `committee_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `position` varchar(255) NOT NULL,
  `effective_from` date DEFAULT NULL,
  `effective_until` date DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `data_correction_requests`
--

CREATE TABLE `data_correction_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `reference_number` varchar(255) DEFAULT NULL,
  `field` varchar(40) NOT NULL,
  `current_value` text DEFAULT NULL,
  `proposed_value` text NOT NULL,
  `reason` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `decided_by` bigint(20) UNSIGNED DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dtr_adjustments`
--

CREATE TABLE `dtr_adjustments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `kind` varchar(20) NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `remark` varchar(255) NOT NULL,
  `entered_by_employee` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `verified_by` bigint(20) UNSIGNED DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dtr_leave_deductions`
--

CREATE TABLE `dtr_leave_deductions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `period_year` smallint(5) UNSIGNED NOT NULL,
  `period_month` tinyint(3) UNSIGNED NOT NULL,
  `minutes` int(10) UNSIGNED NOT NULL,
  `days` decimal(8,3) NOT NULL,
  `days_without_pay` decimal(8,3) NOT NULL DEFAULT 0.000,
  `leave_type_id` bigint(20) UNSIGNED NOT NULL,
  `leave_ledger_entry_id` bigint(20) UNSIGNED DEFAULT NULL,
  `posted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `posted_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reversed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dtr_signatories`
--

CREATE TABLE `dtr_signatories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `period_from` date NOT NULL,
  `period_to` date NOT NULL,
  `signatory_user_id` bigint(20) UNSIGNED NOT NULL,
  `chosen_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dtr_signatures`
--

CREATE TABLE `dtr_signatures` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `period_from` date NOT NULL,
  `period_to` date NOT NULL,
  `code` varchar(16) NOT NULL,
  `content_hash` varchar(64) NOT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `signed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `signed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `superseded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_templates`
--

CREATE TABLE `email_templates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `key` varchar(64) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `intro` text NOT NULL,
  `button` varchar(60) DEFAULT NULL,
  `outro` text DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `employee_number` varchar(30) DEFAULT NULL,
  `biometric_id` varchar(20) DEFAULT NULL,
  `employment_status` varchar(30) DEFAULT NULL,
  `original_appointment_date` date DEFAULT NULL,
  `csc_entry_date` date DEFAULT NULL,
  `separation_date` date DEFAULT NULL,
  `separation_reason` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `immediate_supervisor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_solo_parent` tinyint(1) NOT NULL DEFAULT 0,
  `solo_parent_id_number` text DEFAULT NULL,
  `solo_parent_id_expiry` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_assignments`
--

CREATE TABLE `employee_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `kind` varchar(20) NOT NULL,
  `as_head` tinyint(1) NOT NULL DEFAULT 0,
  `head_capacity` varchar(20) DEFAULT NULL,
  `from_unit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `to_unit_id` bigint(20) UNSIGNED NOT NULL,
  `effective_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `order_number` varchar(100) DEFAULT NULL,
  `order_date` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `document_id` bigint(20) UNSIGNED DEFAULT NULL,
  `applied_at` timestamp NULL DEFAULT NULL,
  `hand_over_supervision` tinyint(1) NOT NULL DEFAULT 0,
  `supervision_handed_at` timestamp NULL DEFAULT NULL,
  `restore_supervision` tinyint(1) NOT NULL DEFAULT 0,
  `supervision_restored_at` timestamp NULL DEFAULT NULL,
  `recorded_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_documents`
--

CREATE TABLE `employee_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `document_type` varchar(40) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `document_date` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `size` int(10) UNSIGNED NOT NULL,
  `sha256` char(64) NOT NULL,
  `uploaded_by` bigint(20) UNSIGNED NOT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `archived_by` bigint(20) UNSIGNED DEFAULT NULL,
  `archive_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_loans`
--

CREATE TABLE `employee_loans` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `pay_component_id` bigint(20) UNSIGNED NOT NULL,
  `reference` varchar(60) DEFAULT NULL,
  `granted_on` date DEFAULT NULL,
  `principal` decimal(12,2) NOT NULL,
  `total_payable` decimal(12,2) NOT NULL,
  `monthly_amortization` decimal(12,2) NOT NULL,
  `start_month` date NOT NULL,
  `paid_before` decimal(12,2) NOT NULL DEFAULT 0.00,
  `stopped_on` date DEFAULT NULL,
  `cancelled_on` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_pay_items`
--

CREATE TABLE `employee_pay_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `pay_component_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_work_schedules`
--

CREATE TABLE `employee_work_schedules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `work_schedule_id` bigint(20) UNSIGNED NOT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `executive_snapshots`
--

CREATE TABLE `executive_snapshots` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `source` varchar(64) NOT NULL,
  `collected_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` varchar(16) NOT NULL,
  `error` varchar(255) DEFAULT NULL,
  `metrics` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metrics`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `executive_targets`
--

CREATE TABLE `executive_targets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `metric` varchar(150) NOT NULL,
  `target` decimal(14,2) NOT NULL,
  `direction` varchar(8) NOT NULL,
  `tolerance` tinyint(3) UNSIGNED NOT NULL DEFAULT 10,
  `note` varchar(255) DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `type` varchar(20) NOT NULL,
  `subject` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `page_path` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'new',
  `status_changed_at` timestamp NULL DEFAULT NULL,
  `seen_at` timestamp NULL DEFAULT NULL,
  `handled_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feedback_replies`
--

CREATE TABLE `feedback_replies` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `feedback_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `body` text NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fwa_arrangements`
--

CREATE TABLE `fwa_arrangements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `legacy_id` bigint(20) UNSIGNED DEFAULT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(20) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `schedule` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`schedule`)),
  `daily_hours` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `decision_remarks` text DEFAULT NULL,
  `decided_by` bigint(20) UNSIGNED DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fwa_reports`
--

CREATE TABLE `fwa_reports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `legacy_id` bigint(20) UNSIGNED DEFAULT NULL,
  `fwa_arrangement_id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `week_start` date NOT NULL,
  `week_end` date NOT NULL,
  `targets` text NOT NULL,
  `accomplishments` text NOT NULL,
  `remarks` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'submitted',
  `acknowledged_by` bigint(20) UNSIGNED DEFAULT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledgement_remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `holidays`
--

CREATE TABLE `holidays` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `legacy_id` bigint(20) UNSIGNED DEFAULT NULL,
  `date` date NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(30) NOT NULL,
  `proclamation` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) UNSIGNED NOT NULL,
  `reserved_at` int(10) UNSIGNED DEFAULT NULL,
  `available_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_applications`
--

CREATE TABLE `leave_applications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `legacy_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reference_number` varchar(30) DEFAULT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `leave_type_id` bigint(20) UNSIGNED NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `dates` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`dates`)),
  `event_date` date DEFAULT NULL,
  `is_half_day` tinyint(1) NOT NULL DEFAULT 0,
  `half_day_period` varchar(2) DEFAULT NULL,
  `days` decimal(7,3) NOT NULL,
  `days_with_pay` decimal(7,3) DEFAULT NULL,
  `days_without_pay` decimal(7,3) DEFAULT NULL,
  `charges` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`charges`)),
  `detail` varchar(30) DEFAULT NULL,
  `detail_text` varchar(500) DEFAULT NULL,
  `commutation_requested` tinyint(1) NOT NULL DEFAULT 0,
  `is_emergency` tinyint(1) NOT NULL DEFAULT 0,
  `remarks` text DEFAULT NULL,
  `late_reason` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'for_review',
  `current_step` tinyint(3) UNSIGNED DEFAULT NULL,
  `deadline_notices` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_mime` varchar(100) DEFAULT NULL,
  `attachment_size` int(10) UNSIGNED DEFAULT NULL,
  `clearance_path` varchar(255) DEFAULT NULL,
  `clearance_name` varchar(255) DEFAULT NULL,
  `clearance_mime` varchar(100) DEFAULT NULL,
  `clearance_size` int(10) UNSIGNED DEFAULT NULL,
  `filed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `signed_at` timestamp NULL DEFAULT NULL,
  `signed_ip` varchar(45) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_application_steps`
--

CREATE TABLE `leave_application_steps` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `leave_application_id` bigint(20) UNSIGNED NOT NULL,
  `round` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `step` tinyint(3) UNSIGNED NOT NULL,
  `part` varchar(1) NOT NULL,
  `role` varchar(20) NOT NULL,
  `assigned_to` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(30) NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `acted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `acted_for` bigint(20) UNSIGNED DEFAULT NULL,
  `acted_at` timestamp NULL DEFAULT NULL,
  `signed_at` timestamp NULL DEFAULT NULL,
  `signed_ip` varchar(45) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `certified_credits` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`certified_credits`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_card_months`
--

CREATE TABLE `leave_card_months` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `year` smallint(5) UNSIGNED NOT NULL,
  `month` tinyint(3) UNSIGNED NOT NULL,
  `vl_earned` decimal(8,3) DEFAULT NULL,
  `vl_used` decimal(8,3) DEFAULT NULL,
  `vl_balance` decimal(9,3) DEFAULT NULL,
  `sl_earned` decimal(8,3) DEFAULT NULL,
  `sl_used` decimal(8,3) DEFAULT NULL,
  `sl_balance` decimal(9,3) DEFAULT NULL,
  `particulars` varchar(500) DEFAULT NULL,
  `applications` varchar(1000) DEFAULT NULL,
  `imported_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`imported_values`)),
  `correction_reason` varchar(300) DEFAULT NULL,
  `corrected_by` bigint(20) UNSIGNED DEFAULT NULL,
  `corrected_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_designations`
--

CREATE TABLE `leave_designations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `legacy_id` bigint(20) UNSIGNED DEFAULT NULL,
  `designee_id` bigint(20) UNSIGNED NOT NULL,
  `acting_for_id` bigint(20) UNSIGNED DEFAULT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `order_number` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `recorded_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_ledger_entries`
--

CREATE TABLE `leave_ledger_entries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `legacy_id` bigint(20) UNSIGNED DEFAULT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `leave_type_id` bigint(20) UNSIGNED NOT NULL,
  `entry_date` date NOT NULL,
  `kind` varchar(20) NOT NULL,
  `amount` decimal(8,3) NOT NULL,
  `particulars` varchar(500) NOT NULL,
  `period_year` smallint(5) UNSIGNED DEFAULT NULL,
  `period_month` tinyint(3) UNSIGNED DEFAULT NULL,
  `reverses_id` bigint(20) UNSIGNED DEFAULT NULL,
  `recorded_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_monetizations`
--

CREATE TABLE `leave_monetizations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `legacy_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reference_number` varchar(30) DEFAULT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `kind` varchar(20) NOT NULL,
  `vl_days` decimal(8,3) NOT NULL DEFAULT 0.000,
  `sl_days` decimal(8,3) NOT NULL DEFAULT 0.000,
  `vl_balance` decimal(8,3) NOT NULL,
  `sl_balance` decimal(8,3) NOT NULL,
  `monthly_salary` decimal(12,2) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `calendar_year` smallint(5) UNSIGNED NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `clearance_on_file` tinyint(1) NOT NULL DEFAULT 0,
  `decision_remarks` text DEFAULT NULL,
  `requested_by` bigint(20) UNSIGNED DEFAULT NULL,
  `decided_by` bigint(20) UNSIGNED DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_types`
--

CREATE TABLE `leave_types` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `workflow` varchar(20) NOT NULL DEFAULT 'standard',
  `requires_balance` tinyint(1) NOT NULL DEFAULT 0,
  `excess_without_pay` tinyint(1) NOT NULL DEFAULT 0,
  `charged_to_id` bigint(20) UNSIGNED DEFAULT NULL,
  `excess_charged_to_id` bigint(20) UNSIGNED DEFAULT NULL,
  `monthly_accrual` decimal(6,3) DEFAULT NULL,
  `annual_grant` decimal(6,3) DEFAULT NULL,
  `lapses_yearly` tinyint(1) NOT NULL DEFAULT 0,
  `max_days_per_year` decimal(7,2) DEFAULT NULL,
  `max_days_per_filing` decimal(7,2) DEFAULT NULL,
  `solo_parent_extra_days` decimal(6,2) DEFAULT NULL,
  `unpaid_extension_days` decimal(6,2) DEFAULT NULL,
  `max_filings` smallint(5) UNSIGNED DEFAULT NULL,
  `event_label` varchar(120) DEFAULT NULL,
  `event_window_days` smallint(5) UNSIGNED DEFAULT NULL,
  `max_consecutive_days` smallint(5) UNSIGNED DEFAULT NULL,
  `sex` varchar(10) DEFAULT NULL,
  `eligible_statuses` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`eligible_statuses`)),
  `min_service_months` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `requires_solo_parent_id` tinyint(1) NOT NULL DEFAULT 0,
  `advance_filing_days` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `allows_emergency_filing` tinyint(1) NOT NULL DEFAULT 0,
  `late_filing_with_reason` tinyint(1) NOT NULL DEFAULT 0,
  `allows_half_day` tinyint(1) NOT NULL DEFAULT 1,
  `counts_calendar_days` tinyint(1) NOT NULL DEFAULT 0,
  `medical_certificate_after_days` smallint(5) UNSIGNED DEFAULT NULL,
  `medical_certificate_if_advance` tinyint(1) NOT NULL DEFAULT 0,
  `supporting_documents` varchar(500) DEFAULT NULL,
  `requires_attachment` tinyint(1) NOT NULL DEFAULT 0,
  `monetizable` tinyint(1) NOT NULL DEFAULT 0,
  `policy_reference` varchar(255) DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_histories`
--

CREATE TABLE `login_histories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `username` varchar(64) NOT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `logged_in_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `logged_out_at` timestamp NULL DEFAULT NULL,
  `ended_by` enum('user','admin','timeout') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `non_plantilla_contracts`
--

CREATE TABLE `non_plantilla_contracts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `employment_status` varchar(30) NOT NULL,
  `designation` varchar(150) NOT NULL,
  `organizational_unit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `date_from` date NOT NULL,
  `date_to` date DEFAULT NULL,
  `rate` decimal(12,2) DEFAULT NULL,
  `rate_basis` varchar(10) DEFAULT NULL,
  `funding_source` varchar(150) DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `recorded_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) UNSIGNED NOT NULL,
  `data` text NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `organizational_units`
--

CREATE TABLE `organizational_units` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(255) DEFAULT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `short_name` varchar(255) DEFAULT NULL,
  `unit_type` varchar(255) NOT NULL DEFAULT '''other''',
  `description` text DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `telephone` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `website_url` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `plantilla_only` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `passkeys`
--

CREATE TABLE `passkeys` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `credential_id` varchar(255) NOT NULL,
  `credential` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`credential`)),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_histories`
--

CREATE TABLE `password_histories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_entries`
--

CREATE TABLE `payroll_entries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payroll_run_id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `position` varchar(255) DEFAULT NULL,
  `item_number` varchar(100) DEFAULT NULL,
  `salary_grade` tinyint(3) UNSIGNED DEFAULT NULL,
  `step` tinyint(3) UNSIGNED DEFAULT NULL,
  `office` varchar(255) DEFAULT NULL,
  `basic_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gross_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint(20) UNSIGNED DEFAULT NULL,
  `cancel_reason` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_lines`
--

CREATE TABLE `payroll_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payroll_entry_id` bigint(20) UNSIGNED NOT NULL,
  `kind` varchar(20) NOT NULL,
  `pay_component_id` bigint(20) UNSIGNED DEFAULT NULL,
  `employee_loan_id` bigint(20) UNSIGNED DEFAULT NULL,
  `label` varchar(120) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `original_amount` decimal(12,2) DEFAULT NULL,
  `adjusted` tinyint(1) NOT NULL DEFAULT 0,
  `adjusted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `adjusted_at` timestamp NULL DEFAULT NULL,
  `adjust_reason` varchar(500) DEFAULT NULL,
  `remitted_on` date DEFAULT NULL,
  `remitted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_runs`
--

CREATE TABLE `payroll_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `year` smallint(5) UNSIGNED NOT NULL,
  `month` tinyint(3) UNSIGNED NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `release_on` date DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `submitted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL,
  `returned_by` bigint(20) UNSIGNED DEFAULT NULL,
  `accounting_note` varchar(1000) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `finalized_at` timestamp NULL DEFAULT NULL,
  `finalized_by` bigint(20) UNSIGNED DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `reopen_reason` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payslip_requests`
--

CREATE TABLE `payslip_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `reference_number` varchar(30) DEFAULT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `payroll_entry_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `purpose` varchar(255) DEFAULT NULL,
  `decided_by` bigint(20) UNSIGNED DEFAULT NULL,
  `acting_for_id` bigint(20) UNSIGNED DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pay_components`
--

CREATE TABLE `pay_components` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `category` varchar(30) DEFAULT NULL,
  `method` varchar(20) NOT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `rate` decimal(6,3) DEFAULT NULL,
  `base_min` decimal(12,2) DEFAULT NULL,
  `base_max` decimal(12,2) DEFAULT NULL,
  `automatic` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pds_children`
--

CREATE TABLE `pds_children` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pds_educations`
--

CREATE TABLE `pds_educations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `level` varchar(20) NOT NULL,
  `school` varchar(255) NOT NULL,
  `degree` varchar(255) DEFAULT NULL,
  `attendance_from` smallint(5) UNSIGNED DEFAULT NULL,
  `attendance_to` smallint(5) UNSIGNED DEFAULT NULL,
  `highest_level_units` varchar(100) DEFAULT NULL,
  `year_graduated` smallint(5) UNSIGNED DEFAULT NULL,
  `honors` varchar(255) DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pds_eligibilities`
--

CREATE TABLE `pds_eligibilities` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `eligibility` varchar(255) NOT NULL,
  `rating` varchar(20) DEFAULT NULL,
  `exam_date` date DEFAULT NULL,
  `exam_place` varchar(255) DEFAULT NULL,
  `license_number` varchar(50) DEFAULT NULL,
  `license_valid_until` date DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pds_learning_developments`
--

CREATE TABLE `pds_learning_developments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(512) NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `hours` decimal(7,2) DEFAULT NULL,
  `ld_type` varchar(50) DEFAULT NULL,
  `ld_type_other` varchar(100) DEFAULT NULL,
  `modality` varchar(20) DEFAULT NULL,
  `sponsor` varchar(512) DEFAULT NULL,
  `venue` varchar(255) DEFAULT NULL,
  `resource_speaker` varchar(512) DEFAULT NULL,
  `approval_status` varchar(20) NOT NULL DEFAULT 'pending',
  `reviewed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `certificate_path` varchar(255) DEFAULT NULL,
  `certificate_name` varchar(255) DEFAULT NULL,
  `certificate_mime` varchar(100) DEFAULT NULL,
  `certificate_size` int(10) UNSIGNED DEFAULT NULL,
  `legacy_id` bigint(20) UNSIGNED DEFAULT NULL,
  `otrs_training_id` bigint(20) UNSIGNED DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pds_other_information`
--

CREATE TABLE `pds_other_information` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(20) NOT NULL,
  `detail` varchar(512) NOT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pds_profiles`
--

CREATE TABLE `pds_profiles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `place_of_birth` varchar(255) DEFAULT NULL,
  `civil_status` varchar(20) DEFAULT NULL,
  `civil_status_other` varchar(50) DEFAULT NULL,
  `height_m` decimal(3,2) DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `blood_type` varchar(3) DEFAULT NULL,
  `umid_no` text DEFAULT NULL,
  `pagibig_no` text DEFAULT NULL,
  `philhealth_no` text DEFAULT NULL,
  `philsys_no` text DEFAULT NULL,
  `tin_no` text DEFAULT NULL,
  `citizenship` varchar(10) DEFAULT NULL,
  `dual_citizenship_by` varchar(15) DEFAULT NULL,
  `dual_citizenship_country` varchar(100) DEFAULT NULL,
  `residential_house_no` varchar(100) DEFAULT NULL,
  `residential_street` varchar(100) DEFAULT NULL,
  `residential_subdivision` varchar(100) DEFAULT NULL,
  `residential_barangay` varchar(100) DEFAULT NULL,
  `residential_city` varchar(100) DEFAULT NULL,
  `residential_province` varchar(100) DEFAULT NULL,
  `residential_zip_code` varchar(10) DEFAULT NULL,
  `permanent_house_no` varchar(100) DEFAULT NULL,
  `permanent_street` varchar(100) DEFAULT NULL,
  `permanent_subdivision` varchar(100) DEFAULT NULL,
  `permanent_barangay` varchar(100) DEFAULT NULL,
  `permanent_city` varchar(100) DEFAULT NULL,
  `permanent_province` varchar(100) DEFAULT NULL,
  `permanent_zip_code` varchar(10) DEFAULT NULL,
  `permanent_same_as_residential` tinyint(1) NOT NULL DEFAULT 0,
  `telephone_no` varchar(50) DEFAULT NULL,
  `mobile_no` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `spouse_surname` varchar(100) DEFAULT NULL,
  `spouse_first_name` varchar(100) DEFAULT NULL,
  `spouse_middle_name` varchar(100) DEFAULT NULL,
  `spouse_name_extension` varchar(10) DEFAULT NULL,
  `spouse_occupation` varchar(150) DEFAULT NULL,
  `spouse_employer` varchar(255) DEFAULT NULL,
  `spouse_business_address` varchar(255) DEFAULT NULL,
  `spouse_telephone_no` varchar(50) DEFAULT NULL,
  `father_surname` varchar(100) DEFAULT NULL,
  `father_first_name` varchar(100) DEFAULT NULL,
  `father_middle_name` varchar(100) DEFAULT NULL,
  `father_name_extension` varchar(10) DEFAULT NULL,
  `mother_surname` varchar(100) DEFAULT NULL,
  `mother_first_name` varchar(100) DEFAULT NULL,
  `mother_middle_name` varchar(100) DEFAULT NULL,
  `questions` text DEFAULT NULL,
  `government_id_type` varchar(100) DEFAULT NULL,
  `government_id_number` text DEFAULT NULL,
  `government_id_issuance` text DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pds_references`
--

CREATE TABLE `pds_references` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `contact` varchar(255) DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pds_submissions`
--

CREATE TABLE `pds_submissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(20) NOT NULL,
  `snapshot` longtext NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `signed_by` bigint(20) UNSIGNED NOT NULL,
  `signed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `signed_ip` varchar(45) DEFAULT NULL,
  `reviewed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pds_voluntary_works`
--

CREATE TABLE `pds_voluntary_works` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `organization` varchar(512) NOT NULL,
  `date_from` date DEFAULT NULL,
  `date_to` date DEFAULT NULL,
  `hours` decimal(7,2) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pds_work_experiences`
--

CREATE TABLE `pds_work_experiences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date DEFAULT NULL,
  `position_title` varchar(255) NOT NULL,
  `agency` varchar(255) NOT NULL,
  `monthly_salary` decimal(12,2) DEFAULT NULL,
  `salary_grade` varchar(10) DEFAULT NULL,
  `appointment_status` varchar(50) DEFAULT NULL,
  `is_government` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permission_role`
--

CREATE TABLE `permission_role` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `plantilla_appointments`
--

CREATE TABLE `plantilla_appointments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `plantilla_item_id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `step` tinyint(3) UNSIGNED DEFAULT NULL,
  `salary_override` decimal(12,2) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `nature` varchar(30) DEFAULT NULL,
  `date_from` date DEFAULT NULL,
  `date_to` date DEFAULT NULL,
  `end_reason` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `recorded_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `plantilla_items`
--

CREATE TABLE `plantilla_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `position_id` bigint(20) UNSIGNED DEFAULT NULL,
  `item_number` varchar(100) DEFAULT NULL,
  `position_title` varchar(255) NOT NULL,
  `salary_grade` tinyint(3) UNSIGNED DEFAULT NULL,
  `level` varchar(10) DEFAULT NULL,
  `organizational_unit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `authority` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `abolished_at` timestamp NULL DEFAULT NULL,
  `abolished_on` date DEFAULT NULL,
  `abolition_authority` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `plantilla_selections`
--

CREATE TABLE `plantilla_selections` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `plantilla_item_id` bigint(20) UNSIGNED NOT NULL,
  `recruitment_vacancy_id` bigint(20) UNSIGNED NOT NULL,
  `applicant_name` varchar(255) NOT NULL,
  `applicant_email` varchar(255) DEFAULT NULL,
  `selected_on` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `salary_grade` tinyint(3) UNSIGNED DEFAULT NULL,
  `level` varchar(10) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `push_subscriptions`
--

CREATE TABLE `push_subscriptions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `subscribable_type` varchar(255) NOT NULL,
  `subscribable_id` bigint(20) UNSIGNED NOT NULL,
  `endpoint` varchar(1024) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `public_key` varchar(255) DEFAULT NULL,
  `auth_token` varchar(255) DEFAULT NULL,
  `content_encoding` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_user`
--

CREATE TABLE `role_user` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salary_notices`
--

CREATE TABLE `salary_notices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(10) NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `plantilla_appointment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `salary_schedule_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notice_date` date NOT NULL,
  `effective_date` date NOT NULL,
  `basis` text NOT NULL,
  `addressee` varchar(255) NOT NULL,
  `salutation` varchar(255) DEFAULT NULL,
  `position_title` varchar(255) NOT NULL,
  `item_number` varchar(100) DEFAULT NULL,
  `salary_grade` tinyint(3) UNSIGNED NOT NULL,
  `from_step` tinyint(3) UNSIGNED NOT NULL,
  `from_salary` decimal(12,2) NOT NULL,
  `to_step` tinyint(3) UNSIGNED NOT NULL,
  `to_salary` decimal(12,2) NOT NULL,
  `signatory_name` varchar(255) NOT NULL,
  `signatory_position` varchar(255) NOT NULL,
  `prepared_by` bigint(20) UNSIGNED DEFAULT NULL,
  `employee_document_id` bigint(20) UNSIGNED DEFAULT NULL,
  `filed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salary_rates`
--

CREATE TABLE `salary_rates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `salary_schedule_id` bigint(20) UNSIGNED NOT NULL,
  `salary_grade` tinyint(3) UNSIGNED NOT NULL,
  `step` tinyint(3) UNSIGNED NOT NULL,
  `monthly_salary` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salary_schedules`
--

CREATE TABLE `salary_schedules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `issuance_date` date DEFAULT NULL,
  `implements` varchar(255) DEFAULT NULL,
  `effective_date` date NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salns`
--

CREATE TABLE `salns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(10) NOT NULL,
  `reckoning_date` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `current_version` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `received_version` smallint(5) UNSIGNED DEFAULT NULL,
  `oath_mode` varchar(20) NOT NULL DEFAULT 'regional_director',
  `officer_name` varchar(255) DEFAULT NULL,
  `officer_title` varchar(255) DEFAULT NULL,
  `statement` longtext NOT NULL,
  `employee_document_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `saln_events`
--

CREATE TABLE `saln_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `saln_id` bigint(20) UNSIGNED NOT NULL,
  `action` varchar(20) NOT NULL,
  `version` smallint(5) UNSIGNED DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `override` tinyint(1) NOT NULL DEFAULT 0,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `saln_exemptions`
--

CREATE TABLE `saln_exemptions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `year` smallint(5) UNSIGNED NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `saln_versions`
--

CREATE TABLE `saln_versions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `saln_id` bigint(20) UNSIGNED NOT NULL,
  `version` smallint(5) UNSIGNED NOT NULL,
  `reference` varchar(40) NOT NULL,
  `statement` longtext NOT NULL,
  `oath_mode` varchar(20) NOT NULL,
  `officer_name` varchar(255) DEFAULT NULL,
  `officer_title` varchar(255) DEFAULT NULL,
  `generated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scheduled_task_pauses`
--

CREATE TABLE `scheduled_task_pauses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `task` varchar(191) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `paused_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `short_name` varchar(255) DEFAULT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `requirements` text DEFAULT NULL,
  `procedure` text DEFAULT NULL,
  `fees` varchar(255) DEFAULT NULL,
  `processing_time` varchar(255) DEFAULT NULL,
  `contact_information` text DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `external_url` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `featured` tinyint(1) NOT NULL DEFAULT 0,
  `visibility` enum('public','authenticated','administrative') NOT NULL DEFAULT 'authenticated',
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `organizational_unit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `category_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_categories`
--

CREATE TABLE `service_categories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_record_entries`
--

CREATE TABLE `service_record_entries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `designation` varchar(255) NOT NULL,
  `status` varchar(50) NOT NULL,
  `salary_grade` tinyint(3) UNSIGNED DEFAULT NULL,
  `step` tinyint(3) UNSIGNED DEFAULT NULL,
  `annual_salary` decimal(12,2) DEFAULT NULL,
  `agency` varchar(255) NOT NULL,
  `station` varchar(255) DEFAULT NULL,
  `branch` varchar(50) NOT NULL,
  `leave_without_pay` decimal(7,3) DEFAULT NULL,
  `separation_date` date DEFAULT NULL,
  `separation_cause` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `recorded_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(20) NOT NULL,
  `group` varchar(30) NOT NULL,
  `label` varchar(255) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `editable` tinyint(1) NOT NULL DEFAULT 1,
  `constraints` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`constraints`)),
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sso_sessions`
--

CREATE TABLE `sso_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `imis_session_id` varchar(100) NOT NULL,
  `sid` text NOT NULL,
  `logout_url` varchar(500) NOT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sso_tokens`
--

CREATE TABLE `sso_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `audience` varchar(100) DEFAULT NULL,
  `imis_session_id` varchar(100) DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supervisor_handovers`
--

CREATE TABLE `supervisor_handovers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `assignment_id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `previous_supervisor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `restored_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_access_logs`
--

CREATE TABLE `system_access_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `username` varchar(64) NOT NULL,
  `application_id` bigint(20) UNSIGNED DEFAULT NULL,
  `application_name` varchar(255) NOT NULL,
  `role_name` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `accessed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `legacy_id` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `honorific` varchar(20) DEFAULT NULL,
  `first_name` varchar(64) DEFAULT NULL,
  `middle_name` varchar(64) DEFAULT NULL,
  `last_name` varchar(64) DEFAULT NULL,
  `name_extension` varchar(10) DEFAULT NULL,
  `sex` enum('male','female') DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `position` varchar(128) DEFAULT NULL,
  `organizational_unit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `office_designation` varchar(30) DEFAULT NULL,
  `profile_photo_path` varchar(255) DEFAULT NULL,
  `username` varchar(64) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `password_changed_at` timestamp NULL DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `status_changed_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `previous_login_at` timestamp NULL DEFAULT NULL,
  `last_seen_changelog_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `notification_preferences` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`notification_preferences`)),
  `signature_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `work_schedules`
--

CREATE TABLE `work_schedules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `start_time` time NOT NULL,
  `latest_arrival` time NOT NULL,
  `required_minutes` smallint(5) UNSIGNED NOT NULL,
  `break_start` time DEFAULT NULL,
  `break_minutes` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `rest_weekdays` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`rest_weekdays`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `announcements_slug_unique` (`slug`),
  ADD KEY `announcements_author_id_foreign` (`author_id`),
  ADD KEY `announcements_status_publish_at_expires_at_index` (`status`,`publish_at`,`expires_at`);

--
-- Indexes for table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `api_tokens_token_hash_unique` (`token_hash`),
  ADD KEY `api_tokens_created_by_foreign` (`created_by`),
  ADD KEY `api_tokens_revoked_by_foreign` (`revoked_by`),
  ADD KEY `api_tokens_integration_index` (`integration`);

--
-- Indexes for table `applications`
--
ALTER TABLE `applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `applications_organizational_unit_id_foreign` (`organizational_unit_id`),
  ADD KEY `applications_status_visibility_sort_order_index` (`status`,`visibility`,`sort_order`),
  ADD KEY `applications_featured_index` (`featured`);

--
-- Indexes for table `application_access`
--
ALTER TABLE `application_access`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `application_access_application_id_user_id_unique` (`application_id`,`user_id`),
  ADD KEY `application_access_user_id_foreign` (`user_id`),
  ADD KEY `application_access_granted_by_foreign` (`granted_by`),
  ADD KEY `application_access_application_id_application_role_id_foreign` (`application_id`,`application_role_id`),
  ADD KEY `application_access_designation_previous_role_id_foreign` (`designation_previous_role_id`);

--
-- Indexes for table `application_listings`
--
ALTER TABLE `application_listings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `application_listings_unique` (`application_id`,`listable_type`,`listable_id`),
  ADD KEY `application_listings_listable_type_listable_id_index` (`listable_type`,`listable_id`);

--
-- Indexes for table `application_roles`
--
ALTER TABLE `application_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `application_roles_application_id_name_unique` (`application_id`,`name`),
  ADD UNIQUE KEY `application_roles_application_id_id_unique` (`application_id`,`id`),
  ADD UNIQUE KEY `application_roles_application_id_code_unique` (`application_id`,`code`);

--
-- Indexes for table `approval_delegations`
--
ALTER TABLE `approval_delegations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `approval_delegations_delegate_id_date_from_date_to_index` (`delegate_id`,`date_from`,`date_to`),
  ADD KEY `approval_delegations_delegator_id_date_from_date_to_index` (`delegator_id`,`date_from`,`date_to`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `audit_logs_created_at_index` (`created_at`),
  ADD KEY `audit_logs_event_created_at_index` (`event`,`created_at`),
  ADD KEY `audit_logs_actor_id_created_at_index` (`actor_id`,`created_at`),
  ADD KEY `audit_logs_target_type_target_id_created_at_index` (`target_type`,`target_id`,`created_at`);

--
-- Indexes for table `biometric_imports`
--
ALTER TABLE `biometric_imports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `biometric_imports_user_id_foreign` (`user_id`);

--
-- Indexes for table `biometric_punches`
--
ALTER TABLE `biometric_punches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `biometric_punches_unique` (`biometric_id`,`punched_at`),
  ADD KEY `biometric_punches_biometric_import_id_foreign` (`biometric_import_id`),
  ADD KEY `biometric_punches_punched_at_index` (`punched_at`),
  ADD KEY `biometric_punches_created_by_foreign` (`created_by`),
  ADD KEY `biometric_punches_voided_by_foreign` (`voided_by`);

--
-- Indexes for table `brand_assets`
--
ALTER TABLE `brand_assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `brand_assets_key_unique` (`key`),
  ADD KEY `brand_assets_updated_by_foreign` (`updated_by`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_expiration_index` (`expiration`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_locks_expiration_index` (`expiration`);

--
-- Indexes for table `changelogs`
--
ALTER TABLE `changelogs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `changelogs_version_unique` (`version`),
  ADD KEY `changelogs_created_by_foreign` (`created_by`),
  ADD KEY `changelogs_status_published_at_index` (`status`,`published_at`);

--
-- Indexes for table `changelog_items`
--
ALTER TABLE `changelog_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `changelog_items_changelog_id_sort_order_index` (`changelog_id`,`sort_order`);

--
-- Indexes for table `committees`
--
ALTER TABLE `committees`
  ADD PRIMARY KEY (`id`),
  ADD KEY `committees_status_index` (`status`);

--
-- Indexes for table `committee_members`
--
ALTER TABLE `committee_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `committee_members_committee_id_status_index` (`committee_id`,`status`),
  ADD KEY `committee_members_user_id_index` (`user_id`);

--
-- Indexes for table `data_correction_requests`
--
ALTER TABLE `data_correction_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `data_correction_requests_reference_number_unique` (`reference_number`),
  ADD KEY `data_correction_requests_employee_id_foreign` (`employee_id`),
  ADD KEY `data_correction_requests_decided_by_foreign` (`decided_by`),
  ADD KEY `data_correction_requests_status_index` (`status`);

--
-- Indexes for table `dtr_adjustments`
--
ALTER TABLE `dtr_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `dtr_adjustments_employee_id_date_unique` (`employee_id`,`date`),
  ADD KEY `dtr_adjustments_created_by_foreign` (`created_by`),
  ADD KEY `dtr_adjustments_verified_by_foreign` (`verified_by`);

--
-- Indexes for table `dtr_leave_deductions`
--
ALTER TABLE `dtr_leave_deductions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dtr_leave_deductions_leave_type_id_foreign` (`leave_type_id`),
  ADD KEY `dtr_leave_deductions_leave_ledger_entry_id_foreign` (`leave_ledger_entry_id`),
  ADD KEY `dtr_leave_deductions_posted_by_foreign` (`posted_by`),
  ADD KEY `dtr_leave_deductions_period_index` (`employee_id`,`period_year`,`period_month`);

--
-- Indexes for table `dtr_signatories`
--
ALTER TABLE `dtr_signatories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `dtr_signatories_employee_id_period_from_period_to_unique` (`employee_id`,`period_from`,`period_to`),
  ADD KEY `dtr_signatories_signatory_user_id_foreign` (`signatory_user_id`),
  ADD KEY `dtr_signatories_chosen_by_foreign` (`chosen_by`);

--
-- Indexes for table `dtr_signatures`
--
ALTER TABLE `dtr_signatures`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `dtr_signatures_code_unique` (`code`),
  ADD KEY `dtr_signatures_signed_by_foreign` (`signed_by`),
  ADD KEY `dtr_signatures_employee_id_period_from_period_to_index` (`employee_id`,`period_from`,`period_to`);

--
-- Indexes for table `email_templates`
--
ALTER TABLE `email_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email_templates_key_unique` (`key`),
  ADD KEY `email_templates_updated_by_foreign` (`updated_by`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employees_user_id_unique` (`user_id`),
  ADD UNIQUE KEY `employees_employee_number_unique` (`employee_number`),
  ADD UNIQUE KEY `employees_biometric_id_unique` (`biometric_id`),
  ADD KEY `employees_employment_status_index` (`employment_status`),
  ADD KEY `employees_immediate_supervisor_id_foreign` (`immediate_supervisor_id`);

--
-- Indexes for table `employee_assignments`
--
ALTER TABLE `employee_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_assignments_from_unit_id_foreign` (`from_unit_id`),
  ADD KEY `employee_assignments_to_unit_id_foreign` (`to_unit_id`),
  ADD KEY `employee_assignments_document_id_foreign` (`document_id`),
  ADD KEY `employee_assignments_recorded_by_foreign` (`recorded_by`),
  ADD KEY `employee_assignments_employee_id_effective_date_index` (`employee_id`,`effective_date`),
  ADD KEY `employee_assignments_kind_effective_date_index` (`kind`,`effective_date`);

--
-- Indexes for table `employee_documents`
--
ALTER TABLE `employee_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_documents_uploaded_by_foreign` (`uploaded_by`),
  ADD KEY `employee_documents_archived_by_foreign` (`archived_by`),
  ADD KEY `employee_documents_employee_id_archived_at_index` (`employee_id`,`archived_at`);

--
-- Indexes for table `employee_loans`
--
ALTER TABLE `employee_loans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_loans_pay_component_id_foreign` (`pay_component_id`),
  ADD KEY `employee_loans_employee_id_start_month_index` (`employee_id`,`start_month`);

--
-- Indexes for table `employee_pay_items`
--
ALTER TABLE `employee_pay_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_pay_items_pay_component_id_foreign` (`pay_component_id`),
  ADD KEY `employee_pay_items_employee_id_effective_from_index` (`employee_id`,`effective_from`);

--
-- Indexes for table `employee_work_schedules`
--
ALTER TABLE `employee_work_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_work_schedules_work_schedule_id_foreign` (`work_schedule_id`),
  ADD KEY `employee_work_schedules_employee_id_valid_from_index` (`employee_id`,`valid_from`);

--
-- Indexes for table `executive_snapshots`
--
ALTER TABLE `executive_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `executive_snapshots_source_collected_at_index` (`source`,`collected_at`);

--
-- Indexes for table `executive_targets`
--
ALTER TABLE `executive_targets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `executive_targets_metric_unique` (`metric`),
  ADD KEY `executive_targets_updated_by_foreign` (`updated_by`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  ADD KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `feedback_handled_by_foreign` (`handled_by`),
  ADD KEY `feedback_status_created_at_index` (`status`,`created_at`),
  ADD KEY `feedback_user_id_created_at_index` (`user_id`,`created_at`);

--
-- Indexes for table `feedback_replies`
--
ALTER TABLE `feedback_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `feedback_replies_user_id_foreign` (`user_id`),
  ADD KEY `feedback_replies_feedback_id_created_at_index` (`feedback_id`,`created_at`);

--
-- Indexes for table `fwa_arrangements`
--
ALTER TABLE `fwa_arrangements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `fwa_arrangements_legacy_id_unique` (`legacy_id`),
  ADD KEY `fwa_arrangements_decided_by_foreign` (`decided_by`),
  ADD KEY `fwa_arrangements_employee_id_status_index` (`employee_id`,`status`);

--
-- Indexes for table `fwa_reports`
--
ALTER TABLE `fwa_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `fwa_reports_employee_id_week_start_unique` (`employee_id`,`week_start`),
  ADD UNIQUE KEY `fwa_reports_legacy_id_unique` (`legacy_id`),
  ADD KEY `fwa_reports_fwa_arrangement_id_foreign` (`fwa_arrangement_id`),
  ADD KEY `fwa_reports_acknowledged_by_foreign` (`acknowledged_by`);

--
-- Indexes for table `holidays`
--
ALTER TABLE `holidays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `holidays_date_name_unique` (`date`,`name`),
  ADD UNIQUE KEY `holidays_legacy_id_unique` (`legacy_id`),
  ADD KEY `holidays_date_index` (`date`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `leave_applications`
--
ALTER TABLE `leave_applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `leave_applications_reference_number_unique` (`reference_number`),
  ADD UNIQUE KEY `leave_applications_legacy_id_unique` (`legacy_id`),
  ADD KEY `leave_applications_leave_type_id_foreign` (`leave_type_id`),
  ADD KEY `leave_applications_filed_by_foreign` (`filed_by`),
  ADD KEY `leave_applications_employee_id_date_from_index` (`employee_id`,`date_from`),
  ADD KEY `leave_applications_status_date_from_index` (`status`,`date_from`);

--
-- Indexes for table `leave_application_steps`
--
ALTER TABLE `leave_application_steps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `leave_application_steps_assigned_to_foreign` (`assigned_to`),
  ADD KEY `leave_application_steps_acted_by_foreign` (`acted_by`),
  ADD KEY `leave_application_steps_acted_for_foreign` (`acted_for`),
  ADD KEY `leave_steps_application_round_step_index` (`leave_application_id`,`round`,`step`),
  ADD KEY `leave_steps_role_action_index` (`role`,`action`);

--
-- Indexes for table `leave_card_months`
--
ALTER TABLE `leave_card_months`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `leave_card_months_employee_id_year_month_unique` (`employee_id`,`year`,`month`),
  ADD KEY `leave_card_months_corrected_by_foreign` (`corrected_by`);

--
-- Indexes for table `leave_designations`
--
ALTER TABLE `leave_designations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `leave_designations_legacy_id_unique` (`legacy_id`),
  ADD KEY `leave_designations_designee_id_foreign` (`designee_id`),
  ADD KEY `leave_designations_acting_for_id_foreign` (`acting_for_id`),
  ADD KEY `leave_designations_recorded_by_foreign` (`recorded_by`),
  ADD KEY `leave_designations_date_from_date_to_index` (`date_from`,`date_to`);

--
-- Indexes for table `leave_ledger_entries`
--
ALTER TABLE `leave_ledger_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `leave_ledger_entries_legacy_id_unique` (`legacy_id`),
  ADD KEY `leave_ledger_entries_reverses_id_foreign` (`reverses_id`),
  ADD KEY `leave_ledger_entries_recorded_by_foreign` (`recorded_by`),
  ADD KEY `leave_ledger_employee_type_date_index` (`employee_id`,`leave_type_id`,`entry_date`),
  ADD KEY `leave_ledger_type_kind_period_index` (`leave_type_id`,`kind`,`period_year`,`period_month`);

--
-- Indexes for table `leave_monetizations`
--
ALTER TABLE `leave_monetizations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `leave_monetizations_reference_number_unique` (`reference_number`),
  ADD UNIQUE KEY `leave_monetizations_legacy_id_unique` (`legacy_id`),
  ADD KEY `leave_monetizations_requested_by_foreign` (`requested_by`),
  ADD KEY `leave_monetizations_decided_by_foreign` (`decided_by`),
  ADD KEY `leave_monetizations_employee_id_calendar_year_index` (`employee_id`,`calendar_year`),
  ADD KEY `leave_monetizations_status_index` (`status`);

--
-- Indexes for table `leave_types`
--
ALTER TABLE `leave_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `leave_types_code_unique` (`code`),
  ADD KEY `leave_types_charged_to_id_foreign` (`charged_to_id`),
  ADD KEY `leave_types_excess_charged_to_id_foreign` (`excess_charged_to_id`);

--
-- Indexes for table `login_histories`
--
ALTER TABLE `login_histories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `login_histories_user_id_logged_in_at_index` (`user_id`,`logged_in_at`),
  ADD KEY `login_histories_session_id_index` (`session_id`),
  ADD KEY `login_histories_logged_in_at_index` (`logged_in_at`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `non_plantilla_contracts`
--
ALTER TABLE `non_plantilla_contracts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `non_plantilla_contracts_organizational_unit_id_foreign` (`organizational_unit_id`),
  ADD KEY `non_plantilla_contracts_recorded_by_foreign` (`recorded_by`),
  ADD KEY `non_plantilla_contracts_employee_id_date_from_index` (`employee_id`,`date_from`),
  ADD KEY `non_plantilla_contracts_employment_status_date_to_index` (`employment_status`,`date_to`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`);

--
-- Indexes for table `organizational_units`
--
ALTER TABLE `organizational_units`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `organizational_units_code_unique` (`code`),
  ADD UNIQUE KEY `organizational_units_slug_unique` (`slug`),
  ADD KEY `organizational_units_status_sort_order_index` (`status`,`sort_order`),
  ADD KEY `organizational_units_parent_id_foreign` (`parent_id`);

--
-- Indexes for table `passkeys`
--
ALTER TABLE `passkeys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `passkeys_credential_id_unique` (`credential_id`),
  ADD KEY `passkeys_user_id_index` (`user_id`);

--
-- Indexes for table `password_histories`
--
ALTER TABLE `password_histories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `password_histories_user_id_id_index` (`user_id`,`id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `payroll_entries`
--
ALTER TABLE `payroll_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payroll_entries_payroll_run_id_employee_id_unique` (`payroll_run_id`,`employee_id`),
  ADD KEY `payroll_entries_employee_id_foreign` (`employee_id`),
  ADD KEY `payroll_entries_cancelled_by_foreign` (`cancelled_by`);

--
-- Indexes for table `payroll_lines`
--
ALTER TABLE `payroll_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `payroll_lines_payroll_entry_id_foreign` (`payroll_entry_id`),
  ADD KEY `payroll_lines_pay_component_id_foreign` (`pay_component_id`),
  ADD KEY `payroll_lines_employee_loan_id_foreign` (`employee_loan_id`),
  ADD KEY `payroll_lines_adjusted_by_foreign` (`adjusted_by`),
  ADD KEY `payroll_lines_remitted_by_foreign` (`remitted_by`);

--
-- Indexes for table `payroll_runs`
--
ALTER TABLE `payroll_runs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payroll_runs_year_month_unique` (`year`,`month`),
  ADD KEY `payroll_runs_created_by_foreign` (`created_by`),
  ADD KEY `payroll_runs_finalized_by_foreign` (`finalized_by`),
  ADD KEY `payroll_runs_submitted_by_foreign` (`submitted_by`),
  ADD KEY `payroll_runs_returned_by_foreign` (`returned_by`);

--
-- Indexes for table `payslip_requests`
--
ALTER TABLE `payslip_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payslip_requests_reference_number_unique` (`reference_number`),
  ADD KEY `payslip_requests_payroll_entry_id_foreign` (`payroll_entry_id`),
  ADD KEY `payslip_requests_decided_by_foreign` (`decided_by`),
  ADD KEY `payslip_requests_acting_for_id_foreign` (`acting_for_id`),
  ADD KEY `payslip_requests_employee_id_status_index` (`employee_id`,`status`);

--
-- Indexes for table `pay_components`
--
ALTER TABLE `pay_components`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pay_components_code_unique` (`code`);

--
-- Indexes for table `pds_children`
--
ALTER TABLE `pds_children`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pds_children_employee_id_foreign` (`employee_id`);

--
-- Indexes for table `pds_educations`
--
ALTER TABLE `pds_educations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pds_educations_employee_id_foreign` (`employee_id`);

--
-- Indexes for table `pds_eligibilities`
--
ALTER TABLE `pds_eligibilities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pds_eligibilities_employee_id_foreign` (`employee_id`);

--
-- Indexes for table `pds_learning_developments`
--
ALTER TABLE `pds_learning_developments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pds_learning_developments_legacy_id_unique` (`legacy_id`),
  ADD KEY `pds_learning_developments_employee_id_date_from_index` (`employee_id`,`date_from`),
  ADD KEY `pds_learning_developments_reviewed_by_foreign` (`reviewed_by`),
  ADD KEY `pds_learning_developments_created_by_foreign` (`created_by`),
  ADD KEY `pds_learning_developments_approval_status_date_from_index` (`approval_status`,`date_from`);

--
-- Indexes for table `pds_other_information`
--
ALTER TABLE `pds_other_information`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pds_other_information_employee_id_foreign` (`employee_id`);

--
-- Indexes for table `pds_profiles`
--
ALTER TABLE `pds_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pds_profiles_employee_id_unique` (`employee_id`);

--
-- Indexes for table `pds_references`
--
ALTER TABLE `pds_references`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pds_references_employee_id_foreign` (`employee_id`);

--
-- Indexes for table `pds_submissions`
--
ALTER TABLE `pds_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pds_submissions_signed_by_foreign` (`signed_by`),
  ADD KEY `pds_submissions_reviewed_by_foreign` (`reviewed_by`),
  ADD KEY `pds_submissions_employee_id_status_index` (`employee_id`,`status`),
  ADD KEY `pds_submissions_status_signed_at_index` (`status`,`signed_at`);

--
-- Indexes for table `pds_voluntary_works`
--
ALTER TABLE `pds_voluntary_works`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pds_voluntary_works_employee_id_foreign` (`employee_id`);

--
-- Indexes for table `pds_work_experiences`
--
ALTER TABLE `pds_work_experiences`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pds_work_experiences_employee_id_date_from_index` (`employee_id`,`date_from`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permissions_name_unique` (`name`);

--
-- Indexes for table `permission_role`
--
ALTER TABLE `permission_role`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permission_role_role_id_permission_id_unique` (`role_id`,`permission_id`),
  ADD KEY `permission_role_permission_id_foreign` (`permission_id`);

--
-- Indexes for table `plantilla_appointments`
--
ALTER TABLE `plantilla_appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `plantilla_appointments_recorded_by_foreign` (`recorded_by`),
  ADD KEY `plantilla_appointments_plantilla_item_id_date_from_index` (`plantilla_item_id`,`date_from`),
  ADD KEY `plantilla_appointments_employee_id_date_from_index` (`employee_id`,`date_from`);

--
-- Indexes for table `plantilla_items`
--
ALTER TABLE `plantilla_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `plantilla_items_item_number_unique` (`item_number`),
  ADD KEY `plantilla_items_organizational_unit_id_salary_grade_index` (`organizational_unit_id`,`salary_grade`),
  ADD KEY `plantilla_items_position_id_foreign` (`position_id`);

--
-- Indexes for table `plantilla_selections`
--
ALTER TABLE `plantilla_selections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `plantilla_selections_item_vacancy_unique` (`plantilla_item_id`,`recruitment_vacancy_id`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `positions_title_unique` (`title`);

--
-- Indexes for table `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `push_subscriptions_endpoint_unique` (`endpoint`),
  ADD KEY `push_subscriptions_subscribable_morph_idx` (`subscribable_type`,`subscribable_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `roles_name_unique` (`name`);

--
-- Indexes for table `role_user`
--
ALTER TABLE `role_user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_user_user_id_role_id_unique` (`user_id`,`role_id`),
  ADD KEY `role_user_role_id_foreign` (`role_id`);

--
-- Indexes for table `salary_notices`
--
ALTER TABLE `salary_notices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `salary_notices_plantilla_appointment_id_foreign` (`plantilla_appointment_id`),
  ADD KEY `salary_notices_salary_schedule_id_foreign` (`salary_schedule_id`),
  ADD KEY `salary_notices_prepared_by_foreign` (`prepared_by`),
  ADD KEY `salary_notices_employee_document_id_foreign` (`employee_document_id`),
  ADD KEY `salary_notices_type_effective_date_index` (`type`,`effective_date`),
  ADD KEY `salary_notices_employee_id_type_index` (`employee_id`,`type`);

--
-- Indexes for table `salary_rates`
--
ALTER TABLE `salary_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `salary_rates_salary_schedule_id_salary_grade_step_unique` (`salary_schedule_id`,`salary_grade`,`step`);

--
-- Indexes for table `salary_schedules`
--
ALTER TABLE `salary_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `salary_schedules_effective_date_unique` (`effective_date`);

--
-- Indexes for table `salns`
--
ALTER TABLE `salns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `salns_employee_id_type_reckoning_date_unique` (`employee_id`,`type`,`reckoning_date`),
  ADD KEY `salns_employee_document_id_foreign` (`employee_document_id`),
  ADD KEY `salns_created_by_foreign` (`created_by`),
  ADD KEY `salns_updated_by_foreign` (`updated_by`),
  ADD KEY `salns_status_index` (`status`);

--
-- Indexes for table `saln_events`
--
ALTER TABLE `saln_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `saln_events_user_id_foreign` (`user_id`),
  ADD KEY `saln_events_saln_id_id_index` (`saln_id`,`id`);

--
-- Indexes for table `saln_exemptions`
--
ALTER TABLE `saln_exemptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `saln_exemptions_employee_id_year_unique` (`employee_id`,`year`),
  ADD KEY `saln_exemptions_created_by_foreign` (`created_by`);

--
-- Indexes for table `saln_versions`
--
ALTER TABLE `saln_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `saln_versions_saln_id_version_unique` (`saln_id`,`version`),
  ADD KEY `saln_versions_generated_by_foreign` (`generated_by`),
  ADD KEY `saln_versions_reference_index` (`reference`);

--
-- Indexes for table `scheduled_task_pauses`
--
ALTER TABLE `scheduled_task_pauses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `scheduled_task_pauses_task_unique` (`task`),
  ADD KEY `scheduled_task_pauses_paused_by_foreign` (`paused_by`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `services_slug_unique` (`slug`),
  ADD KEY `services_organizational_unit_id_foreign` (`organizational_unit_id`),
  ADD KEY `services_status_visibility_sort_order_index` (`status`,`visibility`,`sort_order`),
  ADD KEY `services_category_id_index` (`category_id`);

--
-- Indexes for table `service_categories`
--
ALTER TABLE `service_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `service_categories_slug_unique` (`slug`),
  ADD KEY `service_categories_status_sort_order_index` (`status`,`sort_order`);

--
-- Indexes for table `service_record_entries`
--
ALTER TABLE `service_record_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `service_record_entries_recorded_by_foreign` (`recorded_by`),
  ADD KEY `service_record_entries_employee_id_date_from_index` (`employee_id`,`date_from`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `settings_key_unique` (`key`),
  ADD KEY `settings_group_sort_order_index` (`group`,`sort_order`);

--
-- Indexes for table `sso_sessions`
--
ALTER TABLE `sso_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sso_sessions_user_id_foreign` (`user_id`),
  ADD KEY `sso_sessions_application_id_foreign` (`application_id`),
  ADD KEY `sso_sessions_imis_session_id_index` (`imis_session_id`);

--
-- Indexes for table `sso_tokens`
--
ALTER TABLE `sso_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sso_tokens_token_hash_unique` (`token_hash`),
  ADD KEY `sso_tokens_user_id_foreign` (`user_id`),
  ADD KEY `sso_tokens_application_id_foreign` (`application_id`),
  ADD KEY `sso_tokens_expires_at_index` (`expires_at`);

--
-- Indexes for table `supervisor_handovers`
--
ALTER TABLE `supervisor_handovers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supervisor_handovers_assignment_id_employee_id_unique` (`assignment_id`,`employee_id`),
  ADD KEY `supervisor_handovers_employee_id_foreign` (`employee_id`),
  ADD KEY `supervisor_handovers_previous_supervisor_id_foreign` (`previous_supervisor_id`);

--
-- Indexes for table `system_access_logs`
--
ALTER TABLE `system_access_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `system_access_logs_user_id_accessed_at_index` (`user_id`,`accessed_at`),
  ADD KEY `system_access_logs_application_id_accessed_at_index` (`application_id`,`accessed_at`),
  ADD KEY `system_access_logs_accessed_at_index` (`accessed_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_username_unique` (`username`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD UNIQUE KEY `users_legacy_id_unique` (`legacy_id`),
  ADD KEY `users_last_seen_changelog_id_foreign` (`last_seen_changelog_id`),
  ADD KEY `users_organizational_unit_id_office_designation_index` (`organizational_unit_id`,`office_designation`);

--
-- Indexes for table `work_schedules`
--
ALTER TABLE `work_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `work_schedules_name_unique` (`name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_tokens`
--
ALTER TABLE `api_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `applications`
--
ALTER TABLE `applications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `application_access`
--
ALTER TABLE `application_access`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `application_listings`
--
ALTER TABLE `application_listings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `application_roles`
--
ALTER TABLE `application_roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `approval_delegations`
--
ALTER TABLE `approval_delegations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `biometric_imports`
--
ALTER TABLE `biometric_imports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `biometric_punches`
--
ALTER TABLE `biometric_punches`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `brand_assets`
--
ALTER TABLE `brand_assets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `changelogs`
--
ALTER TABLE `changelogs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `changelog_items`
--
ALTER TABLE `changelog_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `committees`
--
ALTER TABLE `committees`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `committee_members`
--
ALTER TABLE `committee_members`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `data_correction_requests`
--
ALTER TABLE `data_correction_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dtr_adjustments`
--
ALTER TABLE `dtr_adjustments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dtr_leave_deductions`
--
ALTER TABLE `dtr_leave_deductions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dtr_signatories`
--
ALTER TABLE `dtr_signatories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dtr_signatures`
--
ALTER TABLE `dtr_signatures`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_templates`
--
ALTER TABLE `email_templates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_assignments`
--
ALTER TABLE `employee_assignments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_documents`
--
ALTER TABLE `employee_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_loans`
--
ALTER TABLE `employee_loans`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_pay_items`
--
ALTER TABLE `employee_pay_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_work_schedules`
--
ALTER TABLE `employee_work_schedules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `executive_snapshots`
--
ALTER TABLE `executive_snapshots`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `executive_targets`
--
ALTER TABLE `executive_targets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feedback_replies`
--
ALTER TABLE `feedback_replies`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fwa_arrangements`
--
ALTER TABLE `fwa_arrangements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fwa_reports`
--
ALTER TABLE `fwa_reports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `holidays`
--
ALTER TABLE `holidays`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_applications`
--
ALTER TABLE `leave_applications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_application_steps`
--
ALTER TABLE `leave_application_steps`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_card_months`
--
ALTER TABLE `leave_card_months`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_designations`
--
ALTER TABLE `leave_designations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_ledger_entries`
--
ALTER TABLE `leave_ledger_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_monetizations`
--
ALTER TABLE `leave_monetizations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_types`
--
ALTER TABLE `leave_types`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_histories`
--
ALTER TABLE `login_histories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `non_plantilla_contracts`
--
ALTER TABLE `non_plantilla_contracts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `organizational_units`
--
ALTER TABLE `organizational_units`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `passkeys`
--
ALTER TABLE `passkeys`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_histories`
--
ALTER TABLE `password_histories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_entries`
--
ALTER TABLE `payroll_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_lines`
--
ALTER TABLE `payroll_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_runs`
--
ALTER TABLE `payroll_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payslip_requests`
--
ALTER TABLE `payslip_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pay_components`
--
ALTER TABLE `pay_components`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pds_children`
--
ALTER TABLE `pds_children`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pds_educations`
--
ALTER TABLE `pds_educations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pds_eligibilities`
--
ALTER TABLE `pds_eligibilities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pds_learning_developments`
--
ALTER TABLE `pds_learning_developments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pds_other_information`
--
ALTER TABLE `pds_other_information`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pds_profiles`
--
ALTER TABLE `pds_profiles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pds_references`
--
ALTER TABLE `pds_references`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pds_submissions`
--
ALTER TABLE `pds_submissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pds_voluntary_works`
--
ALTER TABLE `pds_voluntary_works`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pds_work_experiences`
--
ALTER TABLE `pds_work_experiences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permission_role`
--
ALTER TABLE `permission_role`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plantilla_appointments`
--
ALTER TABLE `plantilla_appointments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plantilla_items`
--
ALTER TABLE `plantilla_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plantilla_selections`
--
ALTER TABLE `plantilla_selections`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `role_user`
--
ALTER TABLE `role_user`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salary_notices`
--
ALTER TABLE `salary_notices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salary_rates`
--
ALTER TABLE `salary_rates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salary_schedules`
--
ALTER TABLE `salary_schedules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salns`
--
ALTER TABLE `salns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `saln_events`
--
ALTER TABLE `saln_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `saln_exemptions`
--
ALTER TABLE `saln_exemptions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `saln_versions`
--
ALTER TABLE `saln_versions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scheduled_task_pauses`
--
ALTER TABLE `scheduled_task_pauses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_categories`
--
ALTER TABLE `service_categories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_record_entries`
--
ALTER TABLE `service_record_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sso_sessions`
--
ALTER TABLE `sso_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sso_tokens`
--
ALTER TABLE `sso_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supervisor_handovers`
--
ALTER TABLE `supervisor_handovers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_access_logs`
--
ALTER TABLE `system_access_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `work_schedules`
--
ALTER TABLE `work_schedules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD CONSTRAINT `api_tokens_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `api_tokens_revoked_by_foreign` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `applications`
--
ALTER TABLE `applications`
  ADD CONSTRAINT `applications_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `application_access`
--
ALTER TABLE `application_access`
  ADD CONSTRAINT `application_access_application_id_application_role_id_foreign` FOREIGN KEY (`application_id`,`application_role_id`) REFERENCES `application_roles` (`application_id`, `id`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_access_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_access_designation_previous_role_id_foreign` FOREIGN KEY (`designation_previous_role_id`) REFERENCES `application_roles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `application_access_granted_by_foreign` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `application_access_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `application_listings`
--
ALTER TABLE `application_listings`
  ADD CONSTRAINT `application_listings_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `application_roles`
--
ALTER TABLE `application_roles`
  ADD CONSTRAINT `application_roles_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `approval_delegations`
--
ALTER TABLE `approval_delegations`
  ADD CONSTRAINT `approval_delegations_delegate_id_foreign` FOREIGN KEY (`delegate_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `approval_delegations_delegator_id_foreign` FOREIGN KEY (`delegator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `biometric_imports`
--
ALTER TABLE `biometric_imports`
  ADD CONSTRAINT `biometric_imports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `biometric_punches`
--
ALTER TABLE `biometric_punches`
  ADD CONSTRAINT `biometric_punches_biometric_import_id_foreign` FOREIGN KEY (`biometric_import_id`) REFERENCES `biometric_imports` (`id`),
  ADD CONSTRAINT `biometric_punches_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `biometric_punches_voided_by_foreign` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `brand_assets`
--
ALTER TABLE `brand_assets`
  ADD CONSTRAINT `brand_assets_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `changelogs`
--
ALTER TABLE `changelogs`
  ADD CONSTRAINT `changelogs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `changelog_items`
--
ALTER TABLE `changelog_items`
  ADD CONSTRAINT `changelog_items_changelog_id_foreign` FOREIGN KEY (`changelog_id`) REFERENCES `changelogs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `committee_members`
--
ALTER TABLE `committee_members`
  ADD CONSTRAINT `committee_members_committee_id_foreign` FOREIGN KEY (`committee_id`) REFERENCES `committees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `committee_members_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `data_correction_requests`
--
ALTER TABLE `data_correction_requests`
  ADD CONSTRAINT `data_correction_requests_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `data_correction_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dtr_adjustments`
--
ALTER TABLE `dtr_adjustments`
  ADD CONSTRAINT `dtr_adjustments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `dtr_adjustments_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `dtr_adjustments_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `dtr_leave_deductions`
--
ALTER TABLE `dtr_leave_deductions`
  ADD CONSTRAINT `dtr_leave_deductions_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `dtr_leave_deductions_leave_ledger_entry_id_foreign` FOREIGN KEY (`leave_ledger_entry_id`) REFERENCES `leave_ledger_entries` (`id`),
  ADD CONSTRAINT `dtr_leave_deductions_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`),
  ADD CONSTRAINT `dtr_leave_deductions_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `dtr_signatories`
--
ALTER TABLE `dtr_signatories`
  ADD CONSTRAINT `dtr_signatories_chosen_by_foreign` FOREIGN KEY (`chosen_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `dtr_signatories_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `dtr_signatories_signatory_user_id_foreign` FOREIGN KEY (`signatory_user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `dtr_signatures`
--
ALTER TABLE `dtr_signatures`
  ADD CONSTRAINT `dtr_signatures_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `dtr_signatures_signed_by_foreign` FOREIGN KEY (`signed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `email_templates`
--
ALTER TABLE `email_templates`
  ADD CONSTRAINT `email_templates_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `employees_immediate_supervisor_id_foreign` FOREIGN KEY (`immediate_supervisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `employees_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `employee_assignments`
--
ALTER TABLE `employee_assignments`
  ADD CONSTRAINT `employee_assignments_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `employee_documents` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `employee_assignments_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `employee_assignments_from_unit_id_foreign` FOREIGN KEY (`from_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `employee_assignments_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `employee_assignments_to_unit_id_foreign` FOREIGN KEY (`to_unit_id`) REFERENCES `organizational_units` (`id`);

--
-- Constraints for table `employee_documents`
--
ALTER TABLE `employee_documents`
  ADD CONSTRAINT `employee_documents_archived_by_foreign` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `employee_documents_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `employee_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `employee_loans`
--
ALTER TABLE `employee_loans`
  ADD CONSTRAINT `employee_loans_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `employee_loans_pay_component_id_foreign` FOREIGN KEY (`pay_component_id`) REFERENCES `pay_components` (`id`);

--
-- Constraints for table `employee_pay_items`
--
ALTER TABLE `employee_pay_items`
  ADD CONSTRAINT `employee_pay_items_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `employee_pay_items_pay_component_id_foreign` FOREIGN KEY (`pay_component_id`) REFERENCES `pay_components` (`id`);

--
-- Constraints for table `employee_work_schedules`
--
ALTER TABLE `employee_work_schedules`
  ADD CONSTRAINT `employee_work_schedules_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `employee_work_schedules_work_schedule_id_foreign` FOREIGN KEY (`work_schedule_id`) REFERENCES `work_schedules` (`id`);

--
-- Constraints for table `executive_targets`
--
ALTER TABLE `executive_targets`
  ADD CONSTRAINT `executive_targets_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `feedback_handled_by_foreign` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `feedback_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `feedback_replies`
--
ALTER TABLE `feedback_replies`
  ADD CONSTRAINT `feedback_replies_feedback_id_foreign` FOREIGN KEY (`feedback_id`) REFERENCES `feedback` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `feedback_replies_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `fwa_arrangements`
--
ALTER TABLE `fwa_arrangements`
  ADD CONSTRAINT `fwa_arrangements_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fwa_arrangements_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `fwa_reports`
--
ALTER TABLE `fwa_reports`
  ADD CONSTRAINT `fwa_reports_acknowledged_by_foreign` FOREIGN KEY (`acknowledged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fwa_reports_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fwa_reports_fwa_arrangement_id_foreign` FOREIGN KEY (`fwa_arrangement_id`) REFERENCES `fwa_arrangements` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_applications`
--
ALTER TABLE `leave_applications`
  ADD CONSTRAINT `leave_applications_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `leave_applications_filed_by_foreign` FOREIGN KEY (`filed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_applications_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`);

--
-- Constraints for table `leave_application_steps`
--
ALTER TABLE `leave_application_steps`
  ADD CONSTRAINT `leave_application_steps_acted_by_foreign` FOREIGN KEY (`acted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_application_steps_acted_for_foreign` FOREIGN KEY (`acted_for`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_application_steps_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_application_steps_leave_application_id_foreign` FOREIGN KEY (`leave_application_id`) REFERENCES `leave_applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_card_months`
--
ALTER TABLE `leave_card_months`
  ADD CONSTRAINT `leave_card_months_corrected_by_foreign` FOREIGN KEY (`corrected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_card_months_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_designations`
--
ALTER TABLE `leave_designations`
  ADD CONSTRAINT `leave_designations_acting_for_id_foreign` FOREIGN KEY (`acting_for_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_designations_designee_id_foreign` FOREIGN KEY (`designee_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `leave_designations_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `leave_ledger_entries`
--
ALTER TABLE `leave_ledger_entries`
  ADD CONSTRAINT `leave_ledger_entries_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `leave_ledger_entries_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`),
  ADD CONSTRAINT `leave_ledger_entries_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_ledger_entries_reverses_id_foreign` FOREIGN KEY (`reverses_id`) REFERENCES `leave_ledger_entries` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `leave_monetizations`
--
ALTER TABLE `leave_monetizations`
  ADD CONSTRAINT `leave_monetizations_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_monetizations_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `leave_monetizations_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `leave_types`
--
ALTER TABLE `leave_types`
  ADD CONSTRAINT `leave_types_charged_to_id_foreign` FOREIGN KEY (`charged_to_id`) REFERENCES `leave_types` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_types_excess_charged_to_id_foreign` FOREIGN KEY (`excess_charged_to_id`) REFERENCES `leave_types` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `login_histories`
--
ALTER TABLE `login_histories`
  ADD CONSTRAINT `login_histories_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `non_plantilla_contracts`
--
ALTER TABLE `non_plantilla_contracts`
  ADD CONSTRAINT `non_plantilla_contracts_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `non_plantilla_contracts_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `non_plantilla_contracts_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `organizational_units`
--
ALTER TABLE `organizational_units`
  ADD CONSTRAINT `organizational_units_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `organizational_units` (`id`);

--
-- Constraints for table `passkeys`
--
ALTER TABLE `passkeys`
  ADD CONSTRAINT `passkeys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_histories`
--
ALTER TABLE `password_histories`
  ADD CONSTRAINT `password_histories_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payroll_entries`
--
ALTER TABLE `payroll_entries`
  ADD CONSTRAINT `payroll_entries_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payroll_entries_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `payroll_entries_payroll_run_id_foreign` FOREIGN KEY (`payroll_run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payroll_lines`
--
ALTER TABLE `payroll_lines`
  ADD CONSTRAINT `payroll_lines_adjusted_by_foreign` FOREIGN KEY (`adjusted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payroll_lines_employee_loan_id_foreign` FOREIGN KEY (`employee_loan_id`) REFERENCES `employee_loans` (`id`),
  ADD CONSTRAINT `payroll_lines_pay_component_id_foreign` FOREIGN KEY (`pay_component_id`) REFERENCES `pay_components` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payroll_lines_payroll_entry_id_foreign` FOREIGN KEY (`payroll_entry_id`) REFERENCES `payroll_entries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payroll_lines_remitted_by_foreign` FOREIGN KEY (`remitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `payroll_runs`
--
ALTER TABLE `payroll_runs`
  ADD CONSTRAINT `payroll_runs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payroll_runs_finalized_by_foreign` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payroll_runs_returned_by_foreign` FOREIGN KEY (`returned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payroll_runs_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `payslip_requests`
--
ALTER TABLE `payslip_requests`
  ADD CONSTRAINT `payslip_requests_acting_for_id_foreign` FOREIGN KEY (`acting_for_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payslip_requests_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payslip_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `payslip_requests_payroll_entry_id_foreign` FOREIGN KEY (`payroll_entry_id`) REFERENCES `payroll_entries` (`id`);

--
-- Constraints for table `pds_children`
--
ALTER TABLE `pds_children`
  ADD CONSTRAINT `pds_children_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `pds_educations`
--
ALTER TABLE `pds_educations`
  ADD CONSTRAINT `pds_educations_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `pds_eligibilities`
--
ALTER TABLE `pds_eligibilities`
  ADD CONSTRAINT `pds_eligibilities_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `pds_learning_developments`
--
ALTER TABLE `pds_learning_developments`
  ADD CONSTRAINT `pds_learning_developments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `pds_learning_developments_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `pds_learning_developments_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `pds_other_information`
--
ALTER TABLE `pds_other_information`
  ADD CONSTRAINT `pds_other_information_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `pds_profiles`
--
ALTER TABLE `pds_profiles`
  ADD CONSTRAINT `pds_profiles_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `pds_references`
--
ALTER TABLE `pds_references`
  ADD CONSTRAINT `pds_references_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `pds_submissions`
--
ALTER TABLE `pds_submissions`
  ADD CONSTRAINT `pds_submissions_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `pds_submissions_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `pds_submissions_signed_by_foreign` FOREIGN KEY (`signed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `pds_voluntary_works`
--
ALTER TABLE `pds_voluntary_works`
  ADD CONSTRAINT `pds_voluntary_works_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `pds_work_experiences`
--
ALTER TABLE `pds_work_experiences`
  ADD CONSTRAINT `pds_work_experiences_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `permission_role`
--
ALTER TABLE `permission_role`
  ADD CONSTRAINT `permission_role_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `permission_role_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `plantilla_appointments`
--
ALTER TABLE `plantilla_appointments`
  ADD CONSTRAINT `plantilla_appointments_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `plantilla_appointments_plantilla_item_id_foreign` FOREIGN KEY (`plantilla_item_id`) REFERENCES `plantilla_items` (`id`),
  ADD CONSTRAINT `plantilla_appointments_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `plantilla_items`
--
ALTER TABLE `plantilla_items`
  ADD CONSTRAINT `plantilla_items_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `plantilla_items_position_id_foreign` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `plantilla_selections`
--
ALTER TABLE `plantilla_selections`
  ADD CONSTRAINT `plantilla_selections_plantilla_item_id_foreign` FOREIGN KEY (`plantilla_item_id`) REFERENCES `plantilla_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_user`
--
ALTER TABLE `role_user`
  ADD CONSTRAINT `role_user_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `salary_notices`
--
ALTER TABLE `salary_notices`
  ADD CONSTRAINT `salary_notices_employee_document_id_foreign` FOREIGN KEY (`employee_document_id`) REFERENCES `employee_documents` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `salary_notices_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `salary_notices_plantilla_appointment_id_foreign` FOREIGN KEY (`plantilla_appointment_id`) REFERENCES `plantilla_appointments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `salary_notices_prepared_by_foreign` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `salary_notices_salary_schedule_id_foreign` FOREIGN KEY (`salary_schedule_id`) REFERENCES `salary_schedules` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `salary_rates`
--
ALTER TABLE `salary_rates`
  ADD CONSTRAINT `salary_rates_salary_schedule_id_foreign` FOREIGN KEY (`salary_schedule_id`) REFERENCES `salary_schedules` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `salns`
--
ALTER TABLE `salns`
  ADD CONSTRAINT `salns_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `salns_employee_document_id_foreign` FOREIGN KEY (`employee_document_id`) REFERENCES `employee_documents` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `salns_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `salns_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `saln_events`
--
ALTER TABLE `saln_events`
  ADD CONSTRAINT `saln_events_saln_id_foreign` FOREIGN KEY (`saln_id`) REFERENCES `salns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `saln_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `saln_exemptions`
--
ALTER TABLE `saln_exemptions`
  ADD CONSTRAINT `saln_exemptions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `saln_exemptions_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `saln_versions`
--
ALTER TABLE `saln_versions`
  ADD CONSTRAINT `saln_versions_generated_by_foreign` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `saln_versions_saln_id_foreign` FOREIGN KEY (`saln_id`) REFERENCES `salns` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `scheduled_task_pauses`
--
ALTER TABLE `scheduled_task_pauses`
  ADD CONSTRAINT `scheduled_task_pauses_paused_by_foreign` FOREIGN KEY (`paused_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `services`
--
ALTER TABLE `services`
  ADD CONSTRAINT `services_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `services_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `service_record_entries`
--
ALTER TABLE `service_record_entries`
  ADD CONSTRAINT `service_record_entries_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `service_record_entries_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sso_sessions`
--
ALTER TABLE `sso_sessions`
  ADD CONSTRAINT `sso_sessions_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sso_sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sso_tokens`
--
ALTER TABLE `sso_tokens`
  ADD CONSTRAINT `sso_tokens_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sso_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `supervisor_handovers`
--
ALTER TABLE `supervisor_handovers`
  ADD CONSTRAINT `supervisor_handovers_assignment_id_foreign` FOREIGN KEY (`assignment_id`) REFERENCES `employee_assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `supervisor_handovers_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `supervisor_handovers_previous_supervisor_id_foreign` FOREIGN KEY (`previous_supervisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `system_access_logs`
--
ALTER TABLE `system_access_logs`
  ADD CONSTRAINT `system_access_logs_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `system_access_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_last_seen_changelog_id_foreign` FOREIGN KEY (`last_seen_changelog_id`) REFERENCES `changelogs` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

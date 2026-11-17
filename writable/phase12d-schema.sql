-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: coin_platform
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `admin_impersonation_grants`
--

DROP TABLE IF EXISTS `admin_impersonation_grants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_impersonation_grants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `grant_ref` varchar(40) NOT NULL,
  `admin_user_id` int(10) unsigned NOT NULL,
  `target_user_id` int(10) unsigned NOT NULL,
  `target_member_id` varchar(32) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grant_ref` (`grant_ref`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `admin_impersonation_grants_target_user_id_foreign` (`target_user_id`),
  KEY `admin_user_id_created_at` (`admin_user_id`,`created_at`),
  KEY `status_expires_at` (`status`,`expires_at`),
  CONSTRAINT `admin_impersonation_grants_admin_user_id_foreign` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `admin_impersonation_grants_target_user_id_foreign` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admin_impersonation_sessions`
--

DROP TABLE IF EXISTS `admin_impersonation_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_impersonation_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_ref` varchar(40) NOT NULL,
  `grant_ref` varchar(40) NOT NULL,
  `admin_user_id` int(10) unsigned NOT NULL,
  `target_user_id` int(10) unsigned NOT NULL,
  `target_member_id` varchar(32) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `last_activity_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `end_reason` varchar(40) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_ref` (`session_ref`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `admin_impersonation_sessions_target_user_id_foreign` (`target_user_id`),
  KEY `admin_user_id_status` (`admin_user_id`,`status`),
  KEY `status_expires_at` (`status`,`expires_at`),
  CONSTRAINT `admin_impersonation_sessions_admin_user_id_foreign` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `admin_impersonation_sessions_target_user_id_foreign` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(80) DEFAULT NULL,
  `entity_id` varchar(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `actor_user_id` (`actor_user_id`),
  KEY `action` (`action`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `auth_groups_users`
--

DROP TABLE IF EXISTS `auth_groups_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_groups_users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `group` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `auth_groups_users_user_id_foreign` (`user_id`),
  CONSTRAINT `auth_groups_users_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `auth_identities`
--

DROP TABLE IF EXISTS `auth_identities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_identities` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `secret` varchar(255) NOT NULL,
  `secret2` varchar(255) DEFAULT NULL,
  `expires` datetime DEFAULT NULL,
  `extra` text DEFAULT NULL,
  `force_reset` tinyint(1) NOT NULL DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_secret` (`type`,`secret`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `auth_identities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `auth_logins`
--

DROP TABLE IF EXISTS `auth_logins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_logins` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(255) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `id_type` varchar(255) NOT NULL,
  `identifier` varchar(255) NOT NULL,
  `user_id` int(11) unsigned DEFAULT NULL,
  `date` datetime NOT NULL,
  `success` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_type_identifier` (`id_type`,`identifier`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `auth_permissions_users`
--

DROP TABLE IF EXISTS `auth_permissions_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_permissions_users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `permission` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `auth_permissions_users_user_id_foreign` (`user_id`),
  CONSTRAINT `auth_permissions_users_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `auth_remember_tokens`
--

DROP TABLE IF EXISTS `auth_remember_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_remember_tokens` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `selector` varchar(255) NOT NULL,
  `hashedValidator` varchar(255) NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `expires` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `auth_remember_tokens_user_id_foreign` (`user_id`),
  CONSTRAINT `auth_remember_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `auth_token_logins`
--

DROP TABLE IF EXISTS `auth_token_logins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_token_logins` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(255) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `id_type` varchar(255) NOT NULL,
  `identifier` varchar(255) NOT NULL,
  `user_id` int(11) unsigned DEFAULT NULL,
  `date` datetime NOT NULL,
  `success` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_type_identifier` (`id_type`,`identifier`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `blockchain_health_snapshots`
--

DROP TABLE IF EXISTS `blockchain_health_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `blockchain_health_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(80) NOT NULL DEFAULT 'global',
  `network_id` int(10) unsigned DEFAULT NULL,
  `rpc_host_fingerprint` varchar(64) DEFAULT NULL COMMENT 'sha256 of host only — never full URL/credentials',
  `rpc_host_redacted` varchar(120) DEFAULT NULL,
  `expected_chain_id` int(10) unsigned DEFAULT NULL,
  `reported_chain_id` int(10) unsigned DEFAULT NULL,
  `head_block_number` bigint(20) unsigned DEFAULT NULL,
  `latency_ms` int(10) unsigned DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'unavailable',
  `error_category` varchar(64) DEFAULT NULL,
  `error_summary` varchar(255) DEFAULT NULL,
  `checked_by` int(10) unsigned DEFAULT NULL,
  `checked_at` datetime NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scope_checked_at` (`scope`,`checked_at`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `coin_inventory`
--

DROP TABLE IF EXISTS `coin_inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `coin_inventory` (
  `id` tinyint(3) unsigned NOT NULL,
  `available_balance` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `total_added` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `total_allocated` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `coin_inventory_transactions`
--

DROP TABLE IF EXISTS `coin_inventory_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `coin_inventory_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_ref` varchar(40) NOT NULL,
  `type` enum('ADMIN_INVENTORY_ADD','ADMIN_INVENTORY_CORRECTION','MEMBER_PURCHASE_ALLOCATION','PURCHASE_REVERSAL') NOT NULL,
  `direction` enum('credit','debit') NOT NULL,
  `quantity` decimal(24,8) NOT NULL,
  `previous_balance` decimal(24,8) NOT NULL,
  `new_balance` decimal(24,8) NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `idempotency_key` varchar(64) DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_ref` (`transaction_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `type` (`type`),
  KEY `actor_user_id` (`actor_user_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `coin_locking_plans`
--

DROP TABLE IF EXISTS `coin_locking_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `coin_locking_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plan_code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `min_quantity` decimal(24,8) NOT NULL DEFAULT 0.00000001,
  `max_quantity` decimal(24,8) DEFAULT NULL,
  `duration_days` int(10) unsigned NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `allow_early_unlock` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plan_code` (`plan_code`),
  KEY `is_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `coin_price_history`
--

DROP TABLE IF EXISTS `coin_price_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `coin_price_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `previous_price_usdt` decimal(18,8) DEFAULT NULL,
  `new_price_usdt` decimal(18,8) NOT NULL,
  `actor_user_id` int(10) unsigned NOT NULL,
  `reason` varchar(500) NOT NULL,
  `effective_at` datetime NOT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `actor_user_id` (`actor_user_id`),
  KEY `effective_at` (`effective_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `coin_purchase_requests`
--

DROP TABLE IF EXISTS `coin_purchase_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `coin_purchase_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_ref` varchar(40) NOT NULL,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `coin_quantity` decimal(24,8) NOT NULL,
  `price_usdt_snapshot` decimal(18,8) NOT NULL,
  `total_usdt` decimal(18,8) NOT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `member_note` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `requested_at` datetime NOT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `idempotency_key` varchar(64) NOT NULL,
  `inventory_transaction_ref` varchar(40) DEFAULT NULL,
  `ownership_transaction_ref` varchar(40) DEFAULT NULL,
  `payment_deposit_ref` varchar(40) DEFAULT NULL,
  `payment_status` enum('unpaid','deposit_linked','verified') DEFAULT 'unpaid',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_ref` (`purchase_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `member_user_id` (`member_user_id`),
  KEY `member_id` (`member_id`),
  KEY `status` (`status`),
  KEY `requested_at` (`requested_at`),
  KEY `status_requested_at` (`status`,`requested_at`),
  KEY `coin_purchase_requests_payment_deposit_ref` (`payment_deposit_ref`),
  KEY `coin_purchase_requests_payment_status` (`payment_status`),
  CONSTRAINT `coin_purchase_requests_member_user_id_foreign` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `coin_sell_events`
--

DROP TABLE IF EXISTS `coin_sell_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `coin_sell_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sell_ref` varchar(40) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `from_status` varchar(32) DEFAULT NULL,
  `to_status` varchar(32) DEFAULT NULL,
  `payload_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sell_ref` (`sell_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `coin_sell_requests`
--

DROP TABLE IF EXISTS `coin_sell_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `coin_sell_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sell_ref` varchar(40) NOT NULL,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `purchase_ref` varchar(40) NOT NULL,
  `lock_ref` varchar(40) DEFAULT NULL,
  `quantity` decimal(24,8) NOT NULL,
  `price_snapshot_usdt` decimal(24,8) DEFAULT NULL,
  `gross_value_usdt` decimal(24,8) DEFAULT NULL,
  `fee_amount` decimal(24,8) DEFAULT NULL,
  `net_amount` decimal(24,8) DEFAULT NULL,
  `fee_policy_status` varchar(40) NOT NULL DEFAULT 'pending_business_decision',
  `settlement_asset` varchar(16) DEFAULT NULL,
  `settlement_amount` decimal(24,8) DEFAULT NULL,
  `settlement_method` varchar(40) DEFAULT NULL,
  `settlement_evidence_ref` varchar(80) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'draft',
  `idempotency_key` varchar(96) NOT NULL,
  `is_reserved` tinyint(1) NOT NULL DEFAULT 0,
  `is_settled` tinyint(1) NOT NULL DEFAULT 0,
  `rejection_reason` varchar(500) DEFAULT NULL,
  `requested_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewer_id` int(10) unsigned DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sell_ref` (`sell_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `member_user_id_status` (`member_user_id`,`status`),
  KEY `purchase_ref_status` (`purchase_ref`,`status`),
  KEY `lock_ref` (`lock_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `coin_settings`
--

DROP TABLE IF EXISTS `coin_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `coin_settings` (
  `id` tinyint(3) unsigned NOT NULL,
  `current_price_usdt` decimal(18,8) DEFAULT NULL,
  `min_purchase_usdt` decimal(18,8) NOT NULL DEFAULT 5.00000000,
  `price_updated_at` datetime DEFAULT NULL,
  `price_updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_eligibility_audit_events`
--

DROP TABLE IF EXISTS `commission_eligibility_audit_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_eligibility_audit_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_ref` varchar(40) NOT NULL,
  `plan_ref` varchar(40) DEFAULT NULL,
  `level` tinyint(3) unsigned DEFAULT NULL,
  `beneficiary_member_id` varchar(32) DEFAULT NULL,
  `eligible` tinyint(1) NOT NULL DEFAULT 0,
  `reason_codes_json` text DEFAULT NULL,
  `source` varchar(32) NOT NULL DEFAULT 'simulation',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_ref` (`purchase_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_plan_approval_events`
--

DROP TABLE IF EXISTS `commission_plan_approval_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_plan_approval_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plan_ref` varchar(40) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `actor_user_id` int(10) unsigned NOT NULL,
  `from_status` varchar(32) DEFAULT NULL,
  `to_status` varchar(32) DEFAULT NULL,
  `payload_json` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `plan_ref` (`plan_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_plan_levels`
--

DROP TABLE IF EXISTS `commission_plan_levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_plan_levels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plan_ref` varchar(40) NOT NULL,
  `level` tinyint(3) unsigned NOT NULL,
  `percent` decimal(18,8) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plan_ref_level` (`plan_ref`,`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_plans`
--

DROP TABLE IF EXISTS `commission_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plan_ref` varchar(40) NOT NULL,
  `plan_name` varchar(120) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `level_count` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `commission_currency` varchar(16) NOT NULL DEFAULT 'USDT',
  `eligible_purchase_policy` varchar(64) NOT NULL DEFAULT 'approved_verified_usdt',
  `sponsor_eligibility_policy` varchar(64) NOT NULL DEFAULT 'active_sponsors_only',
  `status` enum('draft','approved','disabled') NOT NULL DEFAULT 'draft',
  `created_by` int(10) unsigned NOT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `disabled_by` int(10) unsigned DEFAULT NULL,
  `disabled_at` datetime DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plan_ref` (`plan_ref`),
  KEY `status_effective_from` (`status`,`effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_posting_proposal_events`
--

DROP TABLE IF EXISTS `commission_posting_proposal_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_posting_proposal_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_ref` varchar(40) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `actor_user_id` int(10) unsigned NOT NULL,
  `from_status` varchar(32) DEFAULT NULL,
  `to_status` varchar(32) DEFAULT NULL,
  `payload_json` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `proposal_ref` (`proposal_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_posting_proposals`
--

DROP TABLE IF EXISTS `commission_posting_proposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_posting_proposals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_ref` varchar(40) NOT NULL,
  `purchase_ref` varchar(40) NOT NULL,
  `plan_ref` varchar(40) NOT NULL,
  `beneficiary_user_id` int(10) unsigned NOT NULL,
  `beneficiary_member_id` varchar(32) NOT NULL,
  `level` tinyint(3) unsigned NOT NULL,
  `purchase_amount` decimal(18,8) NOT NULL,
  `percent` decimal(18,8) NOT NULL,
  `proposed_amount` decimal(24,8) NOT NULL,
  `currency` varchar(16) NOT NULL DEFAULT 'USDT',
  `status` enum('draft','pending_approval','approved','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `snapshot_status` varchar(40) NOT NULL,
  `preflight_json` text DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` int(10) unsigned DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(500) DEFAULT NULL,
  `is_executable` tinyint(1) NOT NULL DEFAULT 0,
  `is_posted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proposal_ref` (`proposal_ref`),
  UNIQUE KEY `purchase_ref_beneficiary_member_id_level_plan_ref` (`purchase_ref`,`beneficiary_member_id`,`level`,`plan_ref`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_purchase_calculations`
--

DROP TABLE IF EXISTS `commission_purchase_calculations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_purchase_calculations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `calc_ref` varchar(40) NOT NULL,
  `purchase_ref` varchar(40) NOT NULL,
  `purchase_id` bigint(20) unsigned DEFAULT NULL,
  `purchaser_user_id` int(10) unsigned NOT NULL,
  `purchaser_member_id` varchar(32) NOT NULL,
  `sponsor_level` tinyint(3) unsigned NOT NULL,
  `beneficiary_user_id` int(10) unsigned DEFAULT NULL,
  `beneficiary_member_id` varchar(32) DEFAULT NULL,
  `commission_base_usdt` decimal(24,8) NOT NULL,
  `rate_percent` decimal(24,8) DEFAULT NULL,
  `expected_amount_usdt` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `posted_amount_usdt` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `plan_ref` varchar(40) DEFAULT NULL,
  `plan_effective_from` date DEFAULT NULL,
  `business_date` date NOT NULL,
  `status` varchar(40) NOT NULL,
  `eligibility_status` varchar(40) NOT NULL,
  `reason_codes` varchar(500) DEFAULT NULL,
  `assumptions_json` text DEFAULT NULL,
  `idempotency_key` varchar(96) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `calc_ref` (`calc_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  UNIQUE KEY `purchase_ref_sponsor_level` (`purchase_ref`,`sponsor_level`),
  KEY `beneficiary_user_id_business_date` (`beneficiary_user_id`,`business_date`),
  KEY `status_business_date` (`status`,`business_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_simulation_items`
--

DROP TABLE IF EXISTS `commission_simulation_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_simulation_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_ref` varchar(40) NOT NULL,
  `item_ref` varchar(64) NOT NULL,
  `idempotency_key` varchar(120) NOT NULL,
  `purchase_ref` varchar(40) NOT NULL,
  `purchaser_user_id` int(10) unsigned NOT NULL,
  `purchaser_member_id` varchar(32) NOT NULL,
  `beneficiary_user_id` int(10) unsigned NOT NULL,
  `beneficiary_member_id` varchar(32) NOT NULL,
  `level` tinyint(3) unsigned NOT NULL,
  `plan_ref` varchar(40) NOT NULL,
  `purchase_amount` decimal(18,8) NOT NULL,
  `currency` varchar(16) NOT NULL DEFAULT 'USDT',
  `percent` decimal(18,8) NOT NULL,
  `preview_amount` decimal(24,8) NOT NULL,
  `calculation_date` date NOT NULL,
  `eligible` tinyint(1) NOT NULL DEFAULT 0,
  `reason_codes_json` text DEFAULT NULL,
  `status` enum('simulated','skipped','error') NOT NULL DEFAULT 'simulated',
  `is_payable` tinyint(1) NOT NULL DEFAULT 0,
  `is_posted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_ref` (`item_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `beneficiary_user_id_calculation_date` (`beneficiary_user_id`,`calculation_date`),
  KEY `purchase_ref` (`purchase_ref`),
  KEY `run_ref_level` (`run_ref`,`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_simulation_runs`
--

DROP TABLE IF EXISTS `commission_simulation_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_simulation_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_ref` varchar(40) NOT NULL,
  `calculation_date` date NOT NULL,
  `idempotency_key` varchar(80) NOT NULL,
  `plan_ref` varchar(40) DEFAULT NULL,
  `status` enum('completed','failed','partial') NOT NULL DEFAULT 'completed',
  `is_dry_run` tinyint(1) NOT NULL DEFAULT 1,
  `is_payable` tinyint(1) NOT NULL DEFAULT 0,
  `is_posted` tinyint(1) NOT NULL DEFAULT 0,
  `purchase_count` int(10) unsigned NOT NULL DEFAULT 0,
  `eligible_purchase_count` int(10) unsigned NOT NULL DEFAULT 0,
  `ineligible_purchase_count` int(10) unsigned NOT NULL DEFAULT 0,
  `item_count` int(10) unsigned NOT NULL DEFAULT 0,
  `total_preview_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `level1_preview` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `level2_preview` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `level3_preview` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `error_count` int(10) unsigned NOT NULL DEFAULT 0,
  `error_message` varchar(500) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_ref` (`run_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `calculation_date` (`calculation_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_wallet_accounts`
--

DROP TABLE IF EXISTS `commission_wallet_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_wallet_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `currency` varchar(16) NOT NULL DEFAULT 'USDT',
  `available_balance` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `reserved_balance` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `total_balance` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_user_id_currency` (`member_user_id`,`currency`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_wallet_ledger_entries`
--

DROP TABLE IF EXISTS `commission_wallet_ledger_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_wallet_ledger_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entry_ref` varchar(48) NOT NULL,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `currency` varchar(16) NOT NULL DEFAULT 'USDT',
  `entry_type` varchar(40) NOT NULL,
  `direction` enum('credit','debit') NOT NULL DEFAULT 'credit',
  `amount` decimal(24,8) NOT NULL,
  `available_delta` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `reserved_delta` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `balance_after_available` decimal(24,8) NOT NULL,
  `balance_after_reserved` decimal(24,8) NOT NULL,
  `balance_after_total` decimal(24,8) NOT NULL,
  `reference_type` varchar(64) DEFAULT NULL,
  `reference_id` varchar(64) DEFAULT NULL,
  `idempotency_key` varchar(120) NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `entry_ref` (`entry_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `member_user_id_created_at` (`member_user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_withdrawal_events`
--

DROP TABLE IF EXISTS `commission_withdrawal_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_withdrawal_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `withdrawal_ref` varchar(40) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `from_status` varchar(32) DEFAULT NULL,
  `to_status` varchar(32) DEFAULT NULL,
  `payload_json` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `withdrawal_ref` (`withdrawal_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_withdrawal_payout_evidence`
--

DROP TABLE IF EXISTS `commission_withdrawal_payout_evidence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_withdrawal_payout_evidence` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `evidence_ref` varchar(40) NOT NULL,
  `withdrawal_ref` varchar(40) NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `approved_amount` decimal(24,8) NOT NULL,
  `paid_amount` decimal(24,8) DEFAULT NULL,
  `token_contract` varchar(80) DEFAULT NULL,
  `blockchain_network` varchar(32) NOT NULL,
  `destination_address` varchar(128) NOT NULL,
  `tx_hash` varchar(80) NOT NULL,
  `tx_hash_normalized` varchar(80) NOT NULL,
  `tx_status` varchar(40) NOT NULL DEFAULT 'recorded',
  `block_number` bigint(20) unsigned DEFAULT NULL,
  `confirmations` int(10) unsigned DEFAULT NULL,
  `verification_status` varchar(40) NOT NULL DEFAULT 'blocked',
  `verification_method` varchar(40) NOT NULL DEFAULT 'manual_record',
  `verification_message` varchar(500) DEFAULT NULL,
  `verification_details_json` text DEFAULT NULL,
  `payment_timestamp` datetime DEFAULT NULL,
  `recorded_by` int(10) unsigned NOT NULL,
  `verified_by` int(10) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `audit_ref` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `evidence_ref` (`evidence_ref`),
  UNIQUE KEY `tx_hash_normalized` (`tx_hash_normalized`),
  KEY `withdrawal_ref` (`withdrawal_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `commission_withdrawal_requests`
--

DROP TABLE IF EXISTS `commission_withdrawal_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_withdrawal_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `withdrawal_ref` varchar(40) NOT NULL,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `amount` decimal(24,8) NOT NULL,
  `fee_amount` decimal(24,8) DEFAULT NULL,
  `net_amount` decimal(24,8) DEFAULT NULL,
  `fee_policy_status` varchar(40) DEFAULT 'pending_business_decision',
  `currency` varchar(16) NOT NULL DEFAULT 'USDT',
  `network` varchar(32) NOT NULL,
  `destination_address` varchar(128) NOT NULL,
  `destination_address_snapshot` varchar(128) DEFAULT NULL,
  `wallet_changed_at_snapshot` datetime DEFAULT NULL,
  `status` enum('draft','submitted','under_review','pending_admin_review','approved','rejected','payout_in_progress','payout_submitted','paid','failed','cancelled') NOT NULL DEFAULT 'draft',
  `requested_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewer_id` int(10) unsigned DEFAULT NULL,
  `rejection_reason` varchar(500) DEFAULT NULL,
  `payout_reference` varchar(80) DEFAULT NULL,
  `idempotency_key` varchar(80) NOT NULL,
  `is_reserved` tinyint(1) NOT NULL DEFAULT 0,
  `is_paid` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `paid_amount` decimal(24,8) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `verification_status` varchar(40) DEFAULT NULL,
  `address_change_review_required` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `withdrawal_ref` (`withdrawal_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `member_user_id_status` (`member_user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `financial_alerts`
--

DROP TABLE IF EXISTS `financial_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `financial_alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `alert_ref` varchar(40) NOT NULL,
  `alert_type` varchar(64) NOT NULL,
  `severity` varchar(16) NOT NULL DEFAULT 'warning',
  `dedupe_key` varchar(190) NOT NULL,
  `status` enum('open','acknowledged') NOT NULL DEFAULT 'open',
  `title` varchar(180) NOT NULL,
  `message` varchar(500) NOT NULL,
  `entity_type` varchar(64) DEFAULT NULL,
  `entity_id` varchar(64) DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `occurrence_count` int(10) unsigned NOT NULL DEFAULT 1,
  `first_seen_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `acknowledged_by` int(10) unsigned DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `ack_note` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `alert_ref` (`alert_ref`),
  UNIQUE KEY `dedupe_key` (`dedupe_key`),
  KEY `status` (`status`),
  KEY `alert_type` (`alert_type`),
  KEY `severity` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `financial_remediation_events`
--

DROP TABLE IF EXISTS `financial_remediation_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `financial_remediation_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_ref` varchar(40) NOT NULL,
  `actor_user_id` int(10) unsigned NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `metadata` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `proposal_ref` (`proposal_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `financial_remediation_proposals`
--

DROP TABLE IF EXISTS `financial_remediation_proposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `financial_remediation_proposals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_ref` varchar(40) NOT NULL,
  `status` enum('proposed','approved','rejected','execution_unavailable') NOT NULL DEFAULT 'proposed',
  `remediation_type` varchar(64) NOT NULL,
  `reason_code` varchar(64) DEFAULT NULL,
  `title` varchar(180) NOT NULL,
  `reason` text NOT NULL,
  `evidence_notes` text DEFAULT NULL,
  `evidence_stored_name` varchar(255) DEFAULT NULL,
  `evidence_original_name` varchar(255) DEFAULT NULL,
  `evidence_mime` varchar(120) DEFAULT NULL,
  `entity_type` varchar(64) DEFAULT NULL,
  `entity_id` varchar(64) DEFAULT NULL,
  `before_state_json` text DEFAULT NULL,
  `planned_after_state_json` text DEFAULT NULL,
  `proposed_by` int(10) unsigned NOT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `review_note` text DEFAULT NULL,
  `proposed_at` datetime NOT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `execution_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `execution_result` varchar(64) DEFAULT NULL,
  `idempotency_key` varchar(80) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proposal_ref` (`proposal_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `status` (`status`),
  KEY `proposed_by` (`proposed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `member_coin_balances`
--

DROP TABLE IF EXISTS `member_coin_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_coin_balances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `balance` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `available_balance` decimal(24,8) DEFAULT 0.00000000,
  `locked_balance` decimal(24,8) DEFAULT 0.00000000,
  `reserved_balance` decimal(24,8) DEFAULT 0.00000000,
  `total_acquired` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_user_id` (`member_user_id`),
  UNIQUE KEY `member_id` (`member_id`),
  CONSTRAINT `member_coin_balances_member_user_id_foreign` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `member_coin_lock_events`
--

DROP TABLE IF EXISTS `member_coin_lock_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_coin_lock_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lock_ref` varchar(40) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `detail` varchar(500) DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `lock_ref` (`lock_ref`),
  KEY `event_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `member_coin_locks`
--

DROP TABLE IF EXISTS `member_coin_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_coin_locks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lock_ref` varchar(40) NOT NULL,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `purchase_ref` varchar(40) DEFAULT NULL,
  `lock_kind` varchar(32) DEFAULT 'voluntary',
  `plan_id` bigint(20) unsigned NOT NULL,
  `plan_code` varchar(40) NOT NULL,
  `plan_name` varchar(120) NOT NULL,
  `quantity` decimal(24,8) NOT NULL,
  `duration_days` int(10) unsigned NOT NULL,
  `status` enum('active','matured','unlocked','cancelled') NOT NULL DEFAULT 'active',
  `locked_at` datetime NOT NULL,
  `matures_at` datetime NOT NULL,
  `matured_at` datetime DEFAULT NULL,
  `unlocked_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `ownership_lock_txn_ref` varchar(40) DEFAULT NULL,
  `ownership_unlock_txn_ref` varchar(40) DEFAULT NULL,
  `idempotency_key` varchar(64) DEFAULT NULL,
  `unlock_idempotency_key` varchar(64) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lock_ref` (`lock_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  UNIQUE KEY `unlock_idempotency_key` (`unlock_idempotency_key`),
  UNIQUE KEY `member_coin_locks_purchase_ref_unique` (`purchase_ref`),
  KEY `member_user_id_status` (`member_user_id`,`status`),
  KEY `status_matures_at` (`status`,`matures_at`),
  KEY `plan_id` (`plan_id`),
  KEY `member_coin_locks_lock_kind` (`lock_kind`),
  CONSTRAINT `member_coin_locks_member_user_id_foreign` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `member_coin_locks_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `coin_locking_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `member_coin_ownership_transactions`
--

DROP TABLE IF EXISTS `member_coin_ownership_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_coin_ownership_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_ref` varchar(40) NOT NULL,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `type` enum('PURCHASE_CREDIT','ADMIN_ADJUSTMENT','LOCK','UNLOCK','SALE_RESERVE','SALE_RELEASE','SALE_DEBIT') NOT NULL,
  `direction` enum('credit','debit') NOT NULL,
  `quantity` decimal(24,8) NOT NULL,
  `previous_balance` decimal(24,8) NOT NULL,
  `new_balance` decimal(24,8) NOT NULL,
  `previous_available` decimal(24,8) DEFAULT NULL,
  `new_available` decimal(24,8) DEFAULT NULL,
  `previous_locked` decimal(24,8) DEFAULT NULL,
  `new_locked` decimal(24,8) DEFAULT NULL,
  `previous_reserved` decimal(24,8) DEFAULT NULL,
  `new_reserved` decimal(24,8) DEFAULT NULL,
  `purchase_ref` varchar(40) DEFAULT NULL,
  `lock_ref` varchar(40) DEFAULT NULL,
  `sell_ref` varchar(40) DEFAULT NULL,
  `inventory_transaction_ref` varchar(40) DEFAULT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `idempotency_key` varchar(64) DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_ref` (`transaction_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `member_user_id` (`member_user_id`),
  KEY `member_id` (`member_id`),
  KEY `purchase_ref` (`purchase_ref`),
  KEY `type` (`type`),
  KEY `created_at` (`created_at`),
  KEY `member_coin_ownership_transactions_lock_ref` (`lock_ref`),
  CONSTRAINT `member_coin_ownership_transactions_member_user_id_foreign` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `member_email_change_tokens`
--

DROP TABLE IF EXISTS `member_email_change_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_email_change_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `current_email` varchar(255) NOT NULL,
  `new_email` varchar(255) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_id` (`user_id`),
  KEY `new_email` (`new_email`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `member_email_change_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `member_id_sequences`
--

DROP TABLE IF EXISTS `member_id_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_id_sequences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `prefix` varchar(16) NOT NULL,
  `last_number` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prefix` (`prefix`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `member_profiles`
--

DROP TABLE IF EXISTS `member_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `member_name` varchar(120) NOT NULL,
  `mobile` varchar(20) NOT NULL,
  `pending_email` varchar(255) DEFAULT NULL,
  `usdt_wallet` varchar(255) DEFAULT NULL,
  `usdt_wallet_changed_at` datetime DEFAULT NULL,
  `usdt_wallet_cooldown_until` datetime DEFAULT NULL,
  `account_status` enum('inactive','active') NOT NULL DEFAULT 'inactive',
  `activated_at` datetime DEFAULT NULL,
  `activation_purchase_ref` varchar(40) DEFAULT NULL,
  `is_root_sponsor` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_id` (`member_id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `account_status` (`account_status`),
  KEY `mobile` (`mobile`),
  CONSTRAINT `member_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `member_wallet_change_events`
--

DROP TABLE IF EXISTS `member_wallet_change_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_wallet_change_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `previous_wallet` varchar(255) NOT NULL,
  `new_wallet` varchar(255) NOT NULL,
  `cooldown_until` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `member_id` (`member_id`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `member_wallet_change_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(255) NOT NULL,
  `class` varchar(255) NOT NULL,
  `group` varchar(255) NOT NULL,
  `namespace` varchar(255) NOT NULL,
  `time` int(11) NOT NULL,
  `batch` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mining_daily_calculations`
--

DROP TABLE IF EXISTS `mining_daily_calculations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mining_daily_calculations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `calc_ref` varchar(40) NOT NULL,
  `run_ref` varchar(40) NOT NULL,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `business_date` date NOT NULL,
  `eligible_coins` decimal(24,8) NOT NULL,
  `monthly_rate_percent` decimal(24,8) DEFAULT NULL,
  `day_divisor` decimal(24,8) DEFAULT NULL,
  `daily_factor` decimal(24,12) DEFAULT NULL,
  `expected_amount` decimal(24,8) NOT NULL,
  `posted_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `asset_code` varchar(16) NOT NULL DEFAULT 'COIN',
  `rate_ref` varchar(40) DEFAULT NULL,
  `status` varchar(40) NOT NULL,
  `explanation` varchar(500) DEFAULT NULL,
  `idempotency_key` varchar(96) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `calc_ref` (`calc_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `mining_daily_calculations_member_user_id_foreign` (`member_user_id`),
  KEY `business_date_member_user_id` (`business_date`,`member_user_id`),
  KEY `run_ref` (`run_ref`),
  CONSTRAINT `mining_daily_calculations_member_user_id_foreign` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mining_daily_runs`
--

DROP TABLE IF EXISTS `mining_daily_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mining_daily_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_ref` varchar(40) NOT NULL,
  `business_date` date NOT NULL,
  `run_type` varchar(24) NOT NULL DEFAULT 'dry_run',
  `status` varchar(24) NOT NULL DEFAULT 'completed',
  `member_count` int(10) unsigned NOT NULL DEFAULT 0,
  `eligible_count` int(10) unsigned NOT NULL DEFAULT 0,
  `total_eligible_coins` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `total_expected_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `total_posted_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `rate_ref` varchar(40) DEFAULT NULL,
  `assumptions_json` text DEFAULT NULL,
  `error_summary` varchar(500) DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_ref` (`run_ref`),
  KEY `business_date_run_type` (`business_date`,`run_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mining_eligibility_audit_events`
--

DROP TABLE IF EXISTS `mining_eligibility_audit_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mining_eligibility_audit_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `calculation_date` date NOT NULL,
  `eligible` tinyint(1) NOT NULL DEFAULT 0,
  `eligible_coins` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `reason_codes_json` text DEFAULT NULL,
  `rate_ref` varchar(40) DEFAULT NULL,
  `source` varchar(32) NOT NULL DEFAULT 'simulation',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_user_id_calculation_date` (`member_user_id`,`calculation_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mining_p2p_transfers`
--

DROP TABLE IF EXISTS `mining_p2p_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mining_p2p_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_ref` varchar(40) NOT NULL,
  `sender_user_id` int(10) unsigned NOT NULL,
  `sender_member_id` varchar(32) NOT NULL,
  `receiver_user_id` int(10) unsigned NOT NULL,
  `receiver_member_id` varchar(32) NOT NULL,
  `asset_code` varchar(16) NOT NULL DEFAULT 'COIN',
  `amount` decimal(24,8) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `idempotency_key` varchar(96) NOT NULL,
  `sender_ledger_ref` varchar(40) DEFAULT NULL,
  `receiver_ledger_ref` varchar(40) DEFAULT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `meta_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transfer_ref` (`transfer_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `sender_user_id_created_at` (`sender_user_id`,`created_at`),
  KEY `receiver_user_id_created_at` (`receiver_user_id`,`created_at`),
  KEY `status_created_at` (`status`,`created_at`),
  CONSTRAINT `mining_p2p_transfers_receiver_user_id_foreign` FOREIGN KEY (`receiver_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `mining_p2p_transfers_sender_user_id_foreign` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mining_rate_approval_events`
--

DROP TABLE IF EXISTS `mining_rate_approval_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mining_rate_approval_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rate_ref` varchar(40) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `actor_user_id` int(10) unsigned NOT NULL,
  `from_status` varchar(32) DEFAULT NULL,
  `to_status` varchar(32) DEFAULT NULL,
  `payload_json` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rate_ref` (`rate_ref`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mining_rate_configs`
--

DROP TABLE IF EXISTS `mining_rate_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mining_rate_configs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rate_ref` varchar(40) NOT NULL,
  `effective_month` char(7) NOT NULL,
  `rate_percent` decimal(18,8) NOT NULL,
  `rate_basis` enum('daily','monthly','annual') NOT NULL DEFAULT 'monthly',
  `precision_scale` tinyint(3) unsigned NOT NULL DEFAULT 8,
  `eligibility_policy` varchar(64) NOT NULL DEFAULT 'available_active_only',
  `status` enum('draft','approved','disabled') NOT NULL DEFAULT 'draft',
  `created_by` int(10) unsigned NOT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `disabled_by` int(10) unsigned DEFAULT NULL,
  `disabled_at` datetime DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rate_ref` (`rate_ref`),
  KEY `status_effective_month` (`status`,`effective_month`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mining_simulation_items`
--

DROP TABLE IF EXISTS `mining_simulation_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mining_simulation_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_ref` varchar(40) NOT NULL,
  `item_ref` varchar(48) NOT NULL,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `calculation_date` date NOT NULL,
  `rate_ref` varchar(40) DEFAULT NULL,
  `applied_percent` decimal(18,8) NOT NULL DEFAULT 0.00000000,
  `rate_basis` varchar(16) DEFAULT NULL,
  `total_owned` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `available_coins` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `locked_coins` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `eligible_coins` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `ineligible_coins` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `preview_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `eligible` tinyint(1) NOT NULL DEFAULT 0,
  `reason_codes_json` text DEFAULT NULL,
  `status` enum('simulated','skipped','error') NOT NULL DEFAULT 'simulated',
  `is_payable` tinyint(1) NOT NULL DEFAULT 0,
  `is_posted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_ref` (`item_ref`),
  UNIQUE KEY `run_ref_member_user_id` (`run_ref`,`member_user_id`),
  KEY `member_user_id_calculation_date` (`member_user_id`,`calculation_date`),
  KEY `calculation_date` (`calculation_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mining_simulation_runs`
--

DROP TABLE IF EXISTS `mining_simulation_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mining_simulation_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_ref` varchar(40) NOT NULL,
  `calculation_date` date NOT NULL,
  `idempotency_key` varchar(80) NOT NULL,
  `status` enum('completed','failed','partial') NOT NULL DEFAULT 'completed',
  `is_dry_run` tinyint(1) NOT NULL DEFAULT 1,
  `is_payable` tinyint(1) NOT NULL DEFAULT 0,
  `is_posted` tinyint(1) NOT NULL DEFAULT 0,
  `rate_ref` varchar(40) DEFAULT NULL,
  `member_count` int(10) unsigned NOT NULL DEFAULT 0,
  `eligible_member_count` int(10) unsigned NOT NULL DEFAULT 0,
  `total_eligible_coins` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `total_preview_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `error_count` int(10) unsigned NOT NULL DEFAULT 0,
  `error_message` varchar(500) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_ref` (`run_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `calculation_date` (`calculation_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mining_wallet_ledger`
--

DROP TABLE IF EXISTS `mining_wallet_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mining_wallet_ledger` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entry_ref` varchar(40) NOT NULL,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `asset_code` varchar(16) NOT NULL DEFAULT 'COIN',
  `entry_type` varchar(40) NOT NULL,
  `direction` enum('credit','debit') NOT NULL,
  `amount` decimal(24,8) NOT NULL,
  `balance_after` decimal(24,8) NOT NULL,
  `business_date` date NOT NULL,
  `reference_type` varchar(40) DEFAULT NULL,
  `reference_id` varchar(64) DEFAULT NULL,
  `idempotency_key` varchar(96) NOT NULL,
  `meta_json` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `entry_ref` (`entry_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `member_user_id_business_date` (`member_user_id`,`business_date`),
  KEY `member_user_id_id` (`member_user_id`,`id`),
  CONSTRAINT `mining_wallet_ledger_member_user_id_foreign` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mining_wallets`
--

DROP TABLE IF EXISTS `mining_wallets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mining_wallets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `asset_code` varchar(16) NOT NULL DEFAULT 'COIN',
  `available_balance` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `total_credited` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `total_debited` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_user_id_asset_code` (`member_user_id`,`asset_code`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `mining_wallets_member_user_id_foreign` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_delivery_events`
--

DROP TABLE IF EXISTS `notification_delivery_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_delivery_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `outbox_ref` varchar(40) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `detail` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `outbox_ref` (`outbox_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_outbox`
--

DROP TABLE IF EXISTS `notification_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_outbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `outbox_ref` varchar(40) NOT NULL,
  `channel` varchar(32) NOT NULL DEFAULT 'email',
  `dedupe_key` varchar(190) NOT NULL,
  `status` enum('pending','sending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  `severity` varchar(16) NOT NULL DEFAULT 'warning',
  `subject` varchar(255) NOT NULL,
  `body_text` text NOT NULL,
  `recipients_redacted` varchar(500) DEFAULT NULL,
  `alert_ref` varchar(40) DEFAULT NULL,
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `outbox_ref` (`outbox_ref`),
  UNIQUE KEY `dedupe_key` (`dedupe_key`),
  KEY `status_next_attempt_at` (`status`,`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ops_job_locks`
--

DROP TABLE IF EXISTS `ops_job_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ops_job_locks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lock_name` varchar(64) NOT NULL,
  `locked_by` varchar(80) DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lock_name` (`lock_name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_id` (`user_id`),
  KEY `email` (`email`),
  CONSTRAINT `password_reset_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_destination_change_events`
--

DROP TABLE IF EXISTS `payment_destination_change_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_destination_change_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_ref` varchar(40) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `actor_user_id` int(10) unsigned NOT NULL,
  `from_status` varchar(32) DEFAULT NULL,
  `to_status` varchar(32) DEFAULT NULL,
  `payload_json` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `proposal_ref` (`proposal_ref`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_destination_change_proposals`
--

DROP TABLE IF EXISTS `payment_destination_change_proposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_destination_change_proposals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_ref` varchar(40) NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `network_key` varchar(40) NOT NULL,
  `from_destination_wallet` varchar(128) DEFAULT NULL,
  `to_destination_wallet` varchar(128) NOT NULL,
  `instructions` text DEFAULT NULL,
  `min_deposit_usdt` decimal(24,8) DEFAULT NULL,
  `max_deposit_usdt` decimal(24,8) DEFAULT NULL,
  `qr_mode` varchar(16) DEFAULT 'keep',
  `qr_staging_stored_name` varchar(120) DEFAULT NULL,
  `qr_staging_mime` varchar(64) DEFAULT NULL,
  `qr_decoded_payload` varchar(512) DEFAULT NULL,
  `display_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `currency_code` varchar(16) DEFAULT 'USDT',
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `proposed_by` int(10) unsigned NOT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` int(10) unsigned DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `recipient_name` varchar(120) DEFAULT NULL,
  `token_contract_address` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proposal_ref` (`proposal_ref`),
  KEY `network_id_status` (`network_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `platform_settings`
--

DROP TABLE IF EXISTS `platform_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `platform_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_sponsor_snapshots`
--

DROP TABLE IF EXISTS `purchase_sponsor_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_sponsor_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_id` bigint(20) unsigned NOT NULL,
  `purchase_ref` varchar(40) NOT NULL,
  `purchaser_user_id` int(10) unsigned NOT NULL,
  `purchaser_member_id` varchar(32) NOT NULL,
  `sponsor_level` tinyint(3) unsigned NOT NULL,
  `sponsor_user_id` int(10) unsigned DEFAULT NULL,
  `sponsor_member_id` varchar(32) DEFAULT NULL,
  `sponsor_relationship_id` bigint(20) unsigned DEFAULT NULL,
  `is_missing` tinyint(1) NOT NULL DEFAULT 0,
  `snapshot_status` varchar(40) NOT NULL DEFAULT 'CAPTURED',
  `snapshot_source` varchar(40) NOT NULL DEFAULT 'purchase_approval',
  `reason_codes_json` text DEFAULT NULL,
  `snapshot_timestamp` datetime NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_ref_sponsor_level` (`purchase_ref`,`sponsor_level`),
  KEY `purchase_id` (`purchase_id`),
  KEY `purchaser_member_id` (`purchaser_member_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` int(9) NOT NULL AUTO_INCREMENT,
  `class` varchar(255) NOT NULL,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(31) NOT NULL DEFAULT 'string',
  `context` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `settings_audit`
--

DROP TABLE IF EXISTS `settings_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(64) NOT NULL,
  `previous_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `changed_by` int(10) unsigned NOT NULL,
  `changed_at` datetime NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `setting_key` (`setting_key`),
  KEY `changed_by` (`changed_by`),
  KEY `changed_at` (`changed_at`),
  CONSTRAINT `settings_audit_changed_by_foreign` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sponsor_relationships`
--

DROP TABLE IF EXISTS `sponsor_relationships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sponsor_relationships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_user_id` int(10) unsigned NOT NULL,
  `sponsor_user_id` int(10) unsigned NOT NULL,
  `sponsor_member_id` varchar(32) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_user_id` (`member_user_id`),
  KEY `sponsor_user_id` (`sponsor_user_id`),
  KEY `sponsor_member_id` (`sponsor_member_id`),
  CONSTRAINT `sponsor_relationships_member_user_id_foreign` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `sponsor_relationships_sponsor_user_id_foreign` FOREIGN KEY (`sponsor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `support_ticket_messages`
--

DROP TABLE IF EXISTS `support_ticket_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_ticket_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) unsigned NOT NULL,
  `ticket_ref` varchar(40) NOT NULL,
  `sender_role` enum('member','admin') NOT NULL,
  `sender_user_id` int(10) unsigned NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `support_ticket_messages_sender_user_id_foreign` (`sender_user_id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `ticket_ref` (`ticket_ref`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `support_ticket_messages_sender_user_id_foreign` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `support_ticket_messages_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `support_tickets`
--

DROP TABLE IF EXISTS `support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_tickets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_ref` varchar(40) NOT NULL,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `category` enum('account','payment','coin_purchase','wallet','other') NOT NULL DEFAULT 'other',
  `subject` varchar(180) NOT NULL,
  `status` enum('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `last_reply_at` datetime DEFAULT NULL,
  `last_reply_by` enum('member','admin') DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `closed_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_ref` (`ticket_ref`),
  KEY `member_user_id` (`member_user_id`),
  KEY `member_id` (`member_id`),
  KEY `status` (`status`),
  KEY `category` (`category`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `support_tickets_member_user_id_foreign` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usdt_deposit_events`
--

DROP TABLE IF EXISTS `usdt_deposit_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usdt_deposit_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deposit_id` bigint(20) unsigned NOT NULL,
  `deposit_ref` varchar(40) NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `event_type` varchar(80) NOT NULL,
  `previous_status` varchar(40) DEFAULT NULL,
  `new_status` varchar(40) DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deposit_id` (`deposit_id`),
  KEY `deposit_ref` (`deposit_ref`),
  KEY `event_type` (`event_type`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `usdt_deposit_events_deposit_id_foreign` FOREIGN KEY (`deposit_id`) REFERENCES `usdt_deposit_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usdt_deposit_requests`
--

DROP TABLE IF EXISTS `usdt_deposit_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usdt_deposit_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deposit_ref` varchar(40) NOT NULL,
  `member_user_id` int(10) unsigned NOT NULL,
  `member_id` varchar(32) NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `network_key` varchar(40) NOT NULL,
  `expected_amount_usdt` decimal(18,8) NOT NULL,
  `destination_wallet_snapshot` varchar(128) NOT NULL,
  `tx_hash` varchar(128) DEFAULT NULL,
  `tx_hash_normalized` varchar(128) DEFAULT NULL,
  `evidence_stored_name` varchar(80) DEFAULT NULL,
  `evidence_original_name` varchar(255) DEFAULT NULL,
  `evidence_mime` varchar(100) DEFAULT NULL,
  `evidence_size` int(10) unsigned DEFAULT NULL,
  `status` enum('draft','submitted','under_review','verified','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `verification_method` varchar(80) DEFAULT NULL,
  `verification_reference` varchar(255) DEFAULT NULL,
  `verified_amount_usdt` decimal(18,8) DEFAULT NULL,
  `verified_chain_id` int(10) unsigned DEFAULT NULL,
  `verified_token_contract` varchar(128) DEFAULT NULL,
  `verified_destination` varchar(128) DEFAULT NULL,
  `verified_block_number` bigint(20) unsigned DEFAULT NULL,
  `verified_block_hash` varchar(128) DEFAULT NULL,
  `verified_confirmations` int(10) unsigned DEFAULT NULL,
  `last_verification_status` varchar(64) DEFAULT NULL,
  `last_verification_message` text DEFAULT NULL,
  `last_verification_at` datetime DEFAULT NULL,
  `verification_attempt_count` int(10) unsigned DEFAULT 0,
  `next_verification_after` datetime DEFAULT NULL,
  `integrity_flag` varchar(40) DEFAULT NULL COMMENT 'null|ok|reorg_suspected|needs_investigation',
  `integrity_checked_at` datetime DEFAULT NULL,
  `integrity_notes` text DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `idempotency_key` varchar(64) NOT NULL,
  `linked_purchase_ref` varchar(40) DEFAULT NULL,
  `payment_consumption_status` enum('available','linked','consumed') DEFAULT 'available',
  `payment_consumed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deposit_ref` (`deposit_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  UNIQUE KEY `tx_hash_normalized` (`tx_hash_normalized`),
  KEY `member_user_id` (`member_user_id`),
  KEY `member_id` (`member_id`),
  KEY `network_id` (`network_id`),
  KEY `status` (`status`),
  KEY `submitted_at` (`submitted_at`),
  KEY `usdt_deposit_requests_consumption` (`payment_consumption_status`,`status`),
  KEY `usdt_deposit_requests_last_verify` (`last_verification_status`,`last_verification_at`),
  KEY `usdt_deposit_requests_integrity` (`integrity_flag`),
  CONSTRAINT `usdt_deposit_requests_member_user_id_foreign` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `usdt_deposit_requests_network_id_foreign` FOREIGN KEY (`network_id`) REFERENCES `usdt_payment_networks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usdt_payment_allocation_events`
--

DROP TABLE IF EXISTS `usdt_payment_allocation_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usdt_payment_allocation_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `allocation_ref` varchar(40) NOT NULL,
  `deposit_ref` varchar(40) NOT NULL,
  `purchase_ref` varchar(40) NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `event_type` varchar(64) NOT NULL,
  `previous_status` varchar(32) DEFAULT NULL,
  `new_status` varchar(32) DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `allocation_ref` (`allocation_ref`),
  KEY `deposit_ref` (`deposit_ref`),
  KEY `purchase_ref` (`purchase_ref`),
  KEY `event_type` (`event_type`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usdt_payment_allocations`
--

DROP TABLE IF EXISTS `usdt_payment_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usdt_payment_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `allocation_ref` varchar(40) NOT NULL,
  `deposit_ref` varchar(40) NOT NULL,
  `deposit_active_key` varchar(40) DEFAULT NULL,
  `purchase_ref` varchar(40) NOT NULL,
  `purchase_active_key` varchar(40) DEFAULT NULL,
  `member_user_id` int(10) unsigned NOT NULL,
  `amount_usdt` decimal(18,8) NOT NULL,
  `status` enum('linked','consumed','released') NOT NULL DEFAULT 'linked',
  `linked_by` int(10) unsigned NOT NULL,
  `consumed_by` int(10) unsigned DEFAULT NULL,
  `released_by` int(10) unsigned DEFAULT NULL,
  `linked_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `release_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `allocation_ref` (`allocation_ref`),
  UNIQUE KEY `usdt_alloc_active_deposit` (`deposit_active_key`),
  UNIQUE KEY `usdt_alloc_active_purchase` (`purchase_active_key`),
  KEY `member_user_id` (`member_user_id`),
  KEY `status` (`status`),
  KEY `usdt_alloc_deposit_ref` (`deposit_ref`),
  KEY `usdt_alloc_purchase_ref` (`purchase_ref`),
  CONSTRAINT `usdt_payment_allocations_member_user_id_foreign` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usdt_payment_networks`
--

DROP TABLE IF EXISTS `usdt_payment_networks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usdt_payment_networks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `network_key` varchar(40) NOT NULL,
  `chain_id` int(10) unsigned DEFAULT NULL,
  `verifier_type` varchar(40) DEFAULT 'none',
  `network_name` varchar(120) NOT NULL,
  `token_symbol` varchar(20) NOT NULL DEFAULT 'USDT',
  `token_contract_address` varchar(128) DEFAULT NULL,
  `token_decimals` tinyint(3) unsigned NOT NULL DEFAULT 18,
  `destination_wallet` varchar(128) DEFAULT NULL,
  `address_pattern` varchar(255) DEFAULT NULL,
  `tx_hash_pattern` varchar(255) DEFAULT NULL,
  `required_confirmations` int(10) unsigned NOT NULL DEFAULT 12,
  `min_deposit_usdt` decimal(18,8) NOT NULL DEFAULT 5.00000000,
  `max_deposit_usdt` decimal(18,8) NOT NULL DEFAULT 100000.00000000,
  `instructions` text DEFAULT NULL,
  `qr_stored_name` varchar(120) DEFAULT NULL,
  `qr_mime` varchar(64) DEFAULT NULL,
  `qr_source` varchar(16) DEFAULT 'none',
  `qr_decoded_payload` varchar(512) DEFAULT NULL,
  `qr_verified_at` datetime DEFAULT NULL,
  `explorer_tx_url_template` varchar(255) DEFAULT NULL,
  `rpc_url_env_key` varchar(80) DEFAULT NULL,
  `is_enabled` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `is_demo` tinyint(1) DEFAULT 0,
  `display_status` varchar(24) NOT NULL DEFAULT 'hidden',
  `sort_order` int(10) unsigned NOT NULL DEFAULT 100,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `recipient_name` varchar(120) DEFAULT NULL,
  `logo_stored_name` varchar(120) DEFAULT NULL,
  `logo_mime` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `network_key` (`network_key`),
  KEY `is_enabled` (`is_enabled`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(30) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `status_message` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `last_active` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-17 17:49:06

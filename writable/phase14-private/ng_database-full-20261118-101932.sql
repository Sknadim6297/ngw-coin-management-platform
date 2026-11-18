-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: ng_database
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_impersonation_grants`
--

LOCK TABLES `admin_impersonation_grants` WRITE;
/*!40000 ALTER TABLE `admin_impersonation_grants` DISABLE KEYS */;
INSERT INTO `admin_impersonation_grants` VALUES (1,'AIG-20261118-4E35D672',1,6,'NGW000002','821f79589fb30b3c50e3cd917d9377408bd6b314884335ec76feb769a9af65bd','consumed','2026-11-18 06:09:20','2026-11-18 06:08:22','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','2026-11-18 06:08:20');
/*!40000 ALTER TABLE `admin_impersonation_grants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_impersonation_sessions`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_impersonation_sessions`
--

LOCK TABLES `admin_impersonation_sessions` WRITE;
/*!40000 ALTER TABLE `admin_impersonation_sessions` DISABLE KEYS */;
INSERT INTO `admin_impersonation_sessions` VALUES (1,'AIS-20261118-A47F3031','AIG-20261118-4E35D672',1,6,'NGW000002','b4fb980fce262e205a79790f8084f98d4b73ebee999383bc78d6f0d88b476122','ended','2026-11-18 06:08:22','2026-11-18 06:08:50','2026-11-18 06:38:22','2026-11-18 06:50:49','admin_logout','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36');
/*!40000 ALTER TABLE `admin_impersonation_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=115 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,1,'admin.member_created','member_profile','NGW000001','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"sponsor_id\":\"ROOT0001\",\"source\":\"admin\"}','2026-11-16 12:51:40','2026-11-16 12:51:40'),(2,1,'coin.price_updated','coin_price_history','1','152.59.160.107','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36 Edg/157.0.0.0','{\"previous_price\":\"1.00000000\",\"new_price\":\"0.05000000\",\"reason\":\"FIRST PRE SELL PRICE\"}','2026-11-16 18:14:09','2026-11-16 18:14:09'),(3,1,'coin.inventory_added','coin_inventory_transaction','INV-6A26B9F95944E3E2','152.59.160.107','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36 Edg/157.0.0.0','{\"quantity\":\"3000000.00000000\",\"new_balance\":\"3000000.00000000\",\"reference\":\"NGW\"}','2026-11-16 18:18:44','2026-11-16 18:18:44'),(4,1,'coin.price_updated','coin_price_history','2','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"previous_price\":\"0.05000000\",\"new_price\":\"2.00000000\",\"reason\":\"gfgfgrgr\"}','2026-11-17 04:32:38','2026-11-17 04:32:38'),(5,0,'usdt.network_created','usdt_payment_network','2','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"network_key\":\"bep20\",\"is_enabled\":0}','2026-11-17 08:22:45','2026-11-17 08:22:45'),(6,5,'support.ticket_created','support_ticket','SUP-866C3757E5','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"category\":\"wallet\"}','2026-11-17 10:04:32','2026-11-17 10:04:32'),(7,1,'payment.details_metadata_updated','usdt_payment_network','bep20','152.59.158.124','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"fields\":[\"recipient_name\",\"logo_stored_name\",\"logo_mime\",\"min_deposit_usdt\",\"instructions\",\"token_contract_address\",\"address_pattern\",\"tx_hash_pattern\",\"updated_at\"]}','2026-11-17 10:23:01','2026-11-17 10:23:01'),(8,1,'usdt.network_updated','usdt_payment_network','2','152.59.158.124','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"is_enabled\":\"1\"}','2026-11-17 10:23:01','2026-11-17 10:23:01'),(9,1,'payment.details_bootstrapped','usdt_payment_network','bep20','152.59.158.124','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"enabled\":1}','2026-11-17 10:23:01','2026-11-17 10:23:01'),(10,1,'admin.member_created','member_profile','NGW000002','152.59.158.124','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"sponsor_id\":\"ROOT0001\",\"source\":\"admin\"}','2026-11-17 10:26:13','2026-11-17 10:26:13'),(11,1,'coin.price_updated','coin_price_history','3','152.59.158.124','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"previous_price\":\"2.00000000\",\"new_price\":\"0.05000000\",\"reason\":\"OPENING PRICE\"}','2026-11-17 10:32:07','2026-11-17 10:32:07'),(12,6,'coin.purchase_requested','coin_purchase_request','PUR-CE1D2C1ECD7A801A','152.59.158.124','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"quantity\":\"100.00000000\",\"price\":\"0.05000000\",\"total_usdt\":\"5.00000000\"}','2026-11-17 10:32:51','2026-11-17 10:32:51'),(13,6,'usdt.deposit_submitted','usdt_deposit_request','DEP-F2C593D936A6830C','152.59.158.124','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"amount\":\"5.00000000\",\"network_key\":\"bep20\"}','2026-11-17 10:34:33','2026-11-17 10:34:33'),(14,1,'usdt.deposit_verified_manual','usdt_deposit_request','DEP-F2C593D936A6830C','152.59.158.124','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"method\":\"MANUAL\",\"amount\":\"5.00000000\"}','2026-11-17 10:37:15','2026-11-17 10:37:15'),(15,6,'usdt.payment_linked','usdt_payment_allocation','PAY-7C5BFC6B999140BA','152.59.158.124','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"deposit_ref\":\"DEP-F2C593D936A6830C\",\"purchase_ref\":\"PUR-CE1D2C1ECD7A801A\",\"amount\":\"5.00000000\"}','2026-11-17 10:37:15','2026-11-17 10:37:15'),(18,1,'commission.sponsor_snapshot_captured','coin_purchase_request','PUR-CE1D2C1ECD7A801A','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"levels\":3,\"has_cycle\":false}','2026-11-17 10:40:37','2026-11-17 10:40:37'),(19,1,'coin.lock_plan_created','coin_locking_plan','LOCK1000','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"plan_id\":1,\"duration_days\":1000}','2026-11-17 10:40:37','2026-11-17 10:40:37'),(20,1,'coin.lock_plan_enabled','coin_locking_plan','LOCK1000','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"plan_id\":1}','2026-11-17 10:40:37','2026-11-17 10:40:37'),(21,1,'coin.lock_mandatory_created','member_coin_lock','LCK-M-8D685D52EACE360C','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"purchase_ref\":\"PUR-CE1D2C1ECD7A801A\",\"quantity\":\"100.00000000\",\"days\":1000}','2026-11-17 10:40:37','2026-11-17 10:40:37'),(22,1,'commission.purchase_calculated','coin_purchase_request','PUR-CE1D2C1ECD7A801A','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"expected_total\":\"0.00000000\",\"posting\":\"OFF\",\"line_count\":3}','2026-11-17 10:40:37','2026-11-17 10:40:37'),(23,1,'coin.purchase_approved','coin_purchase_request','PUR-CE1D2C1ECD7A801A','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"quantity\":\"100.00000000\",\"inventory_transaction_ref\":\"INV-ACC6562A7BA7F1C2\",\"ownership_transaction_ref\":\"OWN-F6AE7D6FAB2BCDA3\",\"activated\":true,\"sponsor_snapshot\":true,\"mandatory_lock_ref\":\"LCK-M-8D685D52EACE360C\",\"commission_calc_expected\":\"0.00000000\",\"commission_posting\":\"OFF\"}','2026-11-17 10:40:37','2026-11-17 10:40:37'),(24,6,'coin.purchase_requested','coin_purchase_request','PUR-D61744BF39922D39','152.56.132.246','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"quantity\":\"2000.00000000\",\"price\":\"0.05000000\",\"total_usdt\":\"100.00000000\"}','2026-11-17 10:50:36','2026-11-17 10:50:36'),(25,7,'member.registered','member_profile','NGW000003','106.219.250.213','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','{\"sponsor_id\":\"NGW000002\",\"source\":\"public\"}','2026-11-17 12:02:08','2026-11-17 12:02:08'),(26,1,'payment.details_metadata_updated','usdt_payment_network','bep20','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"fields\":[\"recipient_name\",\"logo_stored_name\",\"logo_mime\",\"min_deposit_usdt\",\"instructions\",\"token_contract_address\",\"address_pattern\",\"tx_hash_pattern\",\"updated_at\"]}','2026-11-17 12:15:01','2026-11-17 12:15:01'),(27,1,'payment.destination_change_proposed','payment_destination_change','PDC-20261117-CAFB17E0','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"network_id\":2}','2026-11-17 12:15:01','2026-11-17 12:15:01'),(28,1,'payment.details_change_proposed','usdt_payment_network','bep20','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"proposal_ref\":\"PDC-20261117-CAFB17E0\"}','2026-11-17 12:15:01','2026-11-17 12:15:01'),(29,1,'payment.details_metadata_updated','usdt_payment_network','bep20','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"fields\":[\"recipient_name\",\"logo_stored_name\",\"logo_mime\",\"min_deposit_usdt\",\"instructions\",\"token_contract_address\",\"address_pattern\",\"tx_hash_pattern\",\"updated_at\"]}','2026-11-17 12:17:04','2026-11-17 12:17:04'),(30,1,'payment.destination_change_proposed','payment_destination_change','PDC-20261117-4431121C','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"network_id\":2}','2026-11-17 12:17:04','2026-11-17 12:17:04'),(31,1,'payment.details_change_proposed','usdt_payment_network','bep20','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"proposal_ref\":\"PDC-20261117-4431121C\"}','2026-11-17 12:17:04','2026-11-17 12:17:04'),(32,1,'coin.purchase_rejected','coin_purchase_request','PUR-D61744BF39922D39','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"reason\":\"NO PAYMENT\"}','2026-11-17 12:19:12','2026-11-17 12:19:12'),(33,7,'coin.purchase_requested','coin_purchase_request','PUR-880DA8BF9321A4AD','106.219.250.213','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','{\"quantity\":\"100.00000000\",\"price\":\"0.05000000\",\"total_usdt\":\"5.00000000\"}','2026-11-17 12:20:47','2026-11-17 12:20:47'),(34,1,'usdt.network_updated','usdt_payment_network','2','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"is_enabled\":\"1\"}','2026-11-17 12:31:28','2026-11-17 12:31:28'),(35,1,'payment.destination_change_approved','payment_destination_change','PDC-20261117-4431121C','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"network_id\":\"2\",\"note\":\"Existing deposit destination snapshots remain unchanged.\",\"platform_admin_self_approve\":true}','2026-11-17 12:31:28','2026-11-17 12:31:28'),(36,1,'payment.details_metadata_updated','usdt_payment_network','bep20','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"fields\":[\"recipient_name\",\"logo_stored_name\",\"logo_mime\",\"min_deposit_usdt\",\"instructions\",\"token_contract_address\",\"address_pattern\",\"tx_hash_pattern\",\"updated_at\"]}','2026-11-17 12:31:46','2026-11-17 12:31:46'),(37,1,'payment.details_metadata_saved','usdt_payment_network','bep20','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36',NULL,'2026-11-17 12:31:46','2026-11-17 12:31:46'),(38,1,'payment.details_metadata_updated','usdt_payment_network','bep20','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"fields\":[\"recipient_name\",\"logo_stored_name\",\"logo_mime\",\"min_deposit_usdt\",\"instructions\",\"token_contract_address\",\"address_pattern\",\"tx_hash_pattern\",\"updated_at\"]}','2026-11-17 12:32:37','2026-11-17 12:32:37'),(39,1,'payment.destination_change_proposed','payment_destination_change','PDC-20261117-4366779A','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"network_id\":2}','2026-11-17 12:32:37','2026-11-17 12:32:37'),(40,1,'payment.details_change_proposed','usdt_payment_network','bep20','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"proposal_ref\":\"PDC-20261117-4366779A\"}','2026-11-17 12:32:37','2026-11-17 12:32:37'),(41,1,'usdt.network_updated','usdt_payment_network','2','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"is_enabled\":\"1\"}','2026-11-17 12:32:51','2026-11-17 12:32:51'),(42,1,'payment.destination_change_approved','payment_destination_change','PDC-20261117-4366779A','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"network_id\":\"2\",\"note\":\"Existing deposit destination snapshots remain unchanged.\",\"platform_admin_self_approve\":true}','2026-11-17 12:32:51','2026-11-17 12:32:51'),(43,1,'payment.details_metadata_updated','usdt_payment_network','bep20','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"fields\":[\"recipient_name\",\"logo_stored_name\",\"logo_mime\",\"min_deposit_usdt\",\"instructions\",\"token_contract_address\",\"address_pattern\",\"tx_hash_pattern\",\"updated_at\"]}','2026-11-17 12:33:03','2026-11-17 12:33:03'),(44,1,'payment.details_metadata_saved','usdt_payment_network','bep20','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36',NULL,'2026-11-17 12:33:03','2026-11-17 12:33:03'),(45,7,'coin.purchase_requested','coin_purchase_request','PUR-DA91F7DA0F5DD26C','106.219.255.214','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','{\"quantity\":\"100.00000000\",\"price\":\"0.05000000\",\"total_usdt\":\"5.00000000\"}','2026-11-17 12:34:10','2026-11-17 12:34:10'),(46,7,'usdt.deposit_submitted','usdt_deposit_request','DEP-0FD4804DC7E76307','106.219.255.214','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','{\"amount\":\"5.00000000\",\"network_key\":\"bep20\"}','2026-11-17 12:35:50','2026-11-17 12:35:50'),(47,1,'usdt.deposit_verified_manual','usdt_deposit_request','DEP-0FD4804DC7E76307','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"method\":\"MANUAL\",\"amount\":\"5.00000000\"}','2026-11-17 12:40:49','2026-11-17 12:40:49'),(48,7,'usdt.payment_linked','usdt_payment_allocation','PAY-DBFFEDE03DF84609','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"deposit_ref\":\"DEP-0FD4804DC7E76307\",\"purchase_ref\":\"PUR-DA91F7DA0F5DD26C\",\"amount\":\"5.00000000\"}','2026-11-17 12:40:49','2026-11-17 12:40:49'),(49,1,'commission.sponsor_snapshot_captured','coin_purchase_request','PUR-DA91F7DA0F5DD26C','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"levels\":3,\"has_cycle\":false}','2026-11-17 12:41:05','2026-11-17 12:41:05'),(50,1,'coin.lock_mandatory_created','member_coin_lock','LCK-M-43500DBFAEEEBECF','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"purchase_ref\":\"PUR-DA91F7DA0F5DD26C\",\"quantity\":\"100.00000000\",\"days\":1000}','2026-11-17 12:41:05','2026-11-17 12:41:05'),(51,1,'commission.purchase_calculated','coin_purchase_request','PUR-DA91F7DA0F5DD26C','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"expected_total\":\"0.00000000\",\"posting\":\"OFF\",\"line_count\":3}','2026-11-17 12:41:05','2026-11-17 12:41:05'),(52,1,'coin.purchase_approved','coin_purchase_request','PUR-DA91F7DA0F5DD26C','152.59.159.97','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"quantity\":\"100.00000000\",\"inventory_transaction_ref\":\"INV-43A8CF8053F99007\",\"ownership_transaction_ref\":\"OWN-1E01D6B5DFDBEE25\",\"activated\":true,\"sponsor_snapshot\":true,\"mandatory_lock_ref\":\"LCK-M-43500DBFAEEEBECF\",\"commission_calc_expected\":\"0.00000000\",\"commission_posting\":\"OFF\"}','2026-11-17 12:41:05','2026-11-17 12:41:05'),(53,8,'member.registered','member_profile','NGW981234','152.56.133.103','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','{\"sponsor_id\":\"NGW000003\",\"source\":\"public\"}','2026-11-17 14:23:57','2026-11-17 14:23:57'),(54,8,'coin.purchase_requested','coin_purchase_request','PUR-899B6BC6A2AF6FCC','152.56.133.103','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','{\"quantity\":\"100.00000000\",\"price\":\"0.05000000\",\"total_usdt\":\"5.00000000\"}','2026-11-17 14:25:23','2026-11-17 14:25:23'),(55,8,'usdt.deposit_submitted','usdt_deposit_request','DEP-48BEC06030C4E190','152.56.133.103','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','{\"amount\":\"5.00000000\",\"network_key\":\"bep20\"}','2026-11-17 14:26:33','2026-11-17 14:26:33'),(56,1,'usdt.deposit_verified_manual','usdt_deposit_request','DEP-48BEC06030C4E190','152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"method\":\"MANUAL\",\"amount\":\"5.00000000\"}','2026-11-17 14:31:13','2026-11-17 14:31:13'),(57,8,'usdt.payment_linked','usdt_payment_allocation','PAY-1254070929B0609F','152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"deposit_ref\":\"DEP-48BEC06030C4E190\",\"purchase_ref\":\"PUR-899B6BC6A2AF6FCC\",\"amount\":\"5.00000000\"}','2026-11-17 14:31:13','2026-11-17 14:31:13'),(58,1,'commission.sponsor_snapshot_captured','coin_purchase_request','PUR-899B6BC6A2AF6FCC','152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"levels\":3,\"has_cycle\":false}','2026-11-17 14:31:30','2026-11-17 14:31:30'),(59,1,'coin.lock_mandatory_created','member_coin_lock','LCK-M-EE24E1517DCE4B9B','152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"purchase_ref\":\"PUR-899B6BC6A2AF6FCC\",\"quantity\":\"100.00000000\",\"days\":1000}','2026-11-17 14:31:30','2026-11-17 14:31:30'),(60,1,'commission.purchase_calculated','coin_purchase_request','PUR-899B6BC6A2AF6FCC','152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"expected_total\":\"0.00000000\",\"posting\":\"OFF\",\"line_count\":3}','2026-11-17 14:31:30','2026-11-17 14:31:30'),(61,1,'coin.purchase_approved','coin_purchase_request','PUR-899B6BC6A2AF6FCC','152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"quantity\":\"100.00000000\",\"inventory_transaction_ref\":\"INV-5283C4E749F427FA\",\"ownership_transaction_ref\":\"OWN-5F676EDC14B02DEF\",\"activated\":true,\"sponsor_snapshot\":true,\"mandatory_lock_ref\":\"LCK-M-EE24E1517DCE4B9B\",\"commission_calc_expected\":\"0.00000000\",\"commission_posting\":\"OFF\"}','2026-11-17 14:31:30','2026-11-17 14:31:30'),(62,9,'member.registered','member_profile','NGW981235','152.58.139.135','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','{\"sponsor_id\":\"NGW000003\",\"source\":\"public\"}','2026-11-17 15:15:34','2026-11-17 15:15:34'),(63,9,'coin.purchase_requested','coin_purchase_request','PUR-84D66C36BB7C46AD','152.58.139.132','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','{\"quantity\":\"100.00000000\",\"price\":\"0.05000000\",\"total_usdt\":\"5.00000000\"}','2026-11-17 15:19:14','2026-11-17 15:19:14'),(64,9,'usdt.deposit_submitted','usdt_deposit_request','DEP-942A048112A938F3','152.58.139.132','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','{\"amount\":\"5.00000000\",\"network_key\":\"bep20\"}','2026-11-17 15:20:29','2026-11-17 15:20:29'),(65,1,'usdt.deposit_verified_manual','usdt_deposit_request','DEP-942A048112A938F3','152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"method\":\"MANUAL\",\"amount\":\"5.00000000\"}','2026-11-17 15:23:36','2026-11-17 15:23:36'),(66,9,'usdt.payment_linked','usdt_payment_allocation','PAY-B8C91A2A88B6CAC5','152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"deposit_ref\":\"DEP-942A048112A938F3\",\"purchase_ref\":\"PUR-84D66C36BB7C46AD\",\"amount\":\"5.00000000\"}','2026-11-17 15:23:36','2026-11-17 15:23:36'),(67,1,'commission.sponsor_snapshot_captured','coin_purchase_request','PUR-84D66C36BB7C46AD','152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"levels\":3,\"has_cycle\":false}','2026-11-17 15:24:01','2026-11-17 15:24:01'),(68,1,'coin.lock_mandatory_created','member_coin_lock','LCK-M-943F318036C8C378','152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"purchase_ref\":\"PUR-84D66C36BB7C46AD\",\"quantity\":\"100.00000000\",\"days\":1000}','2026-11-17 15:24:01','2026-11-17 15:24:01'),(69,1,'commission.purchase_calculated','coin_purchase_request','PUR-84D66C36BB7C46AD','152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"expected_total\":\"0.00000000\",\"posting\":\"OFF\",\"line_count\":3}','2026-11-17 15:24:01','2026-11-17 15:24:01'),(70,1,'coin.purchase_approved','coin_purchase_request','PUR-84D66C36BB7C46AD','152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"quantity\":\"100.00000000\",\"inventory_transaction_ref\":\"INV-E5335F7CD81E01F1\",\"ownership_transaction_ref\":\"OWN-FC78AC6778DC5F6F\",\"activated\":true,\"sponsor_snapshot\":true,\"mandatory_lock_ref\":\"LCK-M-943F318036C8C378\",\"commission_calc_expected\":\"0.00000000\",\"commission_posting\":\"OFF\"}','2026-11-17 15:24:01','2026-11-17 15:24:01'),(71,1,'coin.purchase_rejected','coin_purchase_request','PUR-880DA8BF9321A4AD','152.58.162.57','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"reason\":\"Payment not received\"}','2026-11-17 16:29:14','2026-11-17 16:29:14'),(72,10,'member.registered','member_profile','NGW981236','152.59.163.243','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','{\"sponsor_id\":\"NGW981235\",\"source\":\"public\"}','2026-11-17 16:32:04','2026-11-17 16:32:04'),(73,10,'coin.purchase_requested','coin_purchase_request','PUR-34049F062590516B','152.59.163.243','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','{\"quantity\":\"200.00000000\",\"price\":\"0.05000000\",\"total_usdt\":\"10.00000000\"}','2026-11-17 16:33:32','2026-11-17 16:33:32'),(74,10,'usdt.deposit_submitted','usdt_deposit_request','DEP-062356221DC3D2AB','152.59.163.243','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','{\"amount\":\"10.00000000\",\"network_key\":\"bep20\"}','2026-11-17 16:34:30','2026-11-17 16:34:30'),(75,1,'usdt.deposit_verified_manual','usdt_deposit_request','DEP-062356221DC3D2AB','152.58.162.57','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"method\":\"MANUAL\",\"amount\":\"10.00000000\"}','2026-11-17 16:35:17','2026-11-17 16:35:17'),(76,10,'usdt.payment_linked','usdt_payment_allocation','PAY-17CBE05A27C9D73F','152.58.162.57','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"deposit_ref\":\"DEP-062356221DC3D2AB\",\"purchase_ref\":\"PUR-34049F062590516B\",\"amount\":\"10.00000000\"}','2026-11-17 16:35:17','2026-11-17 16:35:17'),(77,1,'commission.sponsor_snapshot_captured','coin_purchase_request','PUR-34049F062590516B','152.58.162.57','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"levels\":3,\"has_cycle\":false}','2026-11-17 16:35:24','2026-11-17 16:35:24'),(78,1,'coin.lock_mandatory_created','member_coin_lock','LCK-M-BD51E60CF601C9FE','152.58.162.57','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"purchase_ref\":\"PUR-34049F062590516B\",\"quantity\":\"200.00000000\",\"days\":1000}','2026-11-17 16:35:24','2026-11-17 16:35:24'),(79,1,'commission.purchase_calculated','coin_purchase_request','PUR-34049F062590516B','152.58.162.57','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"expected_total\":\"0.00000000\",\"posting\":\"OFF\",\"line_count\":3}','2026-11-17 16:35:24','2026-11-17 16:35:24'),(80,1,'coin.purchase_approved','coin_purchase_request','PUR-34049F062590516B','152.58.162.57','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"quantity\":\"200.00000000\",\"inventory_transaction_ref\":\"INV-B56E65CC0BF3FE19\",\"ownership_transaction_ref\":\"OWN-79E6E59613A0FDA3\",\"activated\":true,\"sponsor_snapshot\":true,\"mandatory_lock_ref\":\"LCK-M-BD51E60CF601C9FE\",\"commission_calc_expected\":\"0.00000000\",\"commission_posting\":\"OFF\"}','2026-11-17 16:35:24','2026-11-17 16:35:24'),(81,11,'member.registered','member_profile','NGW981237','106.202.2.15','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','{\"sponsor_id\":\"NGW981236\",\"source\":\"public\"}','2026-11-17 16:45:30','2026-11-17 16:45:30'),(82,11,'coin.purchase_requested','coin_purchase_request','PUR-1B4480DAD069E5B3','106.202.2.15','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','{\"quantity\":\"100.00000000\",\"price\":\"0.05000000\",\"total_usdt\":\"5.00000000\"}','2026-11-17 16:47:05','2026-11-17 16:47:05'),(83,11,'usdt.deposit_submitted','usdt_deposit_request','DEP-76FCB506397419BF','106.202.2.15','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','{\"amount\":\"5.00000000\",\"network_key\":\"bep20\"}','2026-11-17 16:50:04','2026-11-17 16:50:04'),(84,1,'usdt.deposit_verified_manual','usdt_deposit_request','DEP-76FCB506397419BF','152.58.162.57','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"method\":\"MANUAL\",\"amount\":\"5.00000000\"}','2026-11-17 16:50:47','2026-11-17 16:50:47'),(85,11,'usdt.payment_linked','usdt_payment_allocation','PAY-B8A5ED436804BDEA','152.58.162.57','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"deposit_ref\":\"DEP-76FCB506397419BF\",\"purchase_ref\":\"PUR-1B4480DAD069E5B3\",\"amount\":\"5.00000000\"}','2026-11-17 16:50:47','2026-11-17 16:50:47'),(86,1,'commission.sponsor_snapshot_captured','coin_purchase_request','PUR-1B4480DAD069E5B3','152.58.162.57','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"levels\":3,\"has_cycle\":false}','2026-11-17 16:50:54','2026-11-17 16:50:54'),(87,1,'coin.lock_mandatory_created','member_coin_lock','LCK-M-2DEE923F3148DA1B','152.58.162.57','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"purchase_ref\":\"PUR-1B4480DAD069E5B3\",\"quantity\":\"100.00000000\",\"days\":1000}','2026-11-17 16:50:54','2026-11-17 16:50:54'),(88,1,'commission.purchase_calculated','coin_purchase_request','PUR-1B4480DAD069E5B3','152.58.162.57','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"expected_total\":\"0.00000000\",\"posting\":\"OFF\",\"line_count\":3}','2026-11-17 16:50:54','2026-11-17 16:50:54'),(89,1,'coin.purchase_approved','coin_purchase_request','PUR-1B4480DAD069E5B3','152.58.162.57','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"quantity\":\"100.00000000\",\"inventory_transaction_ref\":\"INV-AE1084832360AD7D\",\"ownership_transaction_ref\":\"OWN-42CB4E90AE7232CB\",\"activated\":true,\"sponsor_snapshot\":true,\"mandatory_lock_ref\":\"LCK-M-2DEE923F3148DA1B\",\"commission_calc_expected\":\"0.00000000\",\"commission_posting\":\"OFF\"}','2026-11-17 16:50:54','2026-11-17 16:50:54'),(90,12,'member.registered','member_profile','NGW981238','223.184.140.37','Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/157.0.8103.37 Mobile/15E148 Safari/604.1','{\"sponsor_id\":\"NGW981236\",\"source\":\"public\"}','2026-11-17 19:02:32','2026-11-17 19:02:32'),(91,10,'coin.purchase_requested','coin_purchase_request','PUR-73E219F638FD202D','152.59.165.241','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','{\"quantity\":\"100.00000000\",\"price\":\"0.05000000\",\"total_usdt\":\"5.00000000\"}','2026-11-17 19:25:47','2026-11-17 19:25:47'),(92,12,'coin.purchase_requested','coin_purchase_request','PUR-21037EBC049498EA','223.184.140.37','Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/157.0.8103.37 Mobile/15E148 Safari/604.1','{\"quantity\":\"500.00000000\",\"price\":\"0.05000000\",\"total_usdt\":\"25.00000000\"}','2026-11-17 21:22:18','2026-11-17 21:22:18'),(93,12,'usdt.deposit_submitted','usdt_deposit_request','DEP-5BD53E8A2CDE2FA0','223.184.140.37','Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/157.0.8103.37 Mobile/15E148 Safari/604.1','{\"amount\":\"25.00000000\",\"network_key\":\"bep20\"}','2026-11-17 21:24:16','2026-11-17 21:24:16'),(94,1,'usdt.deposit_verified_manual','usdt_deposit_request','DEP-5BD53E8A2CDE2FA0','152.59.164.161','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"method\":\"MANUAL\",\"amount\":\"25.00000000\"}','2026-11-17 21:25:41','2026-11-17 21:25:41'),(95,12,'usdt.payment_linked','usdt_payment_allocation','PAY-23582F191462F215','152.59.164.161','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"deposit_ref\":\"DEP-5BD53E8A2CDE2FA0\",\"purchase_ref\":\"PUR-21037EBC049498EA\",\"amount\":\"25.00000000\"}','2026-11-17 21:25:41','2026-11-17 21:25:41'),(96,1,'commission.sponsor_snapshot_captured','coin_purchase_request','PUR-21037EBC049498EA','152.59.164.161','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"levels\":3,\"has_cycle\":false}','2026-11-17 21:25:49','2026-11-17 21:25:49'),(97,1,'coin.lock_mandatory_created','member_coin_lock','LCK-M-9843C201C8AF7DC3','152.59.164.161','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"purchase_ref\":\"PUR-21037EBC049498EA\",\"quantity\":\"500.00000000\",\"days\":1000}','2026-11-17 21:25:49','2026-11-17 21:25:49'),(98,1,'commission.purchase_calculated','coin_purchase_request','PUR-21037EBC049498EA','152.59.164.161','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"expected_total\":\"0.00000000\",\"posting\":\"OFF\",\"line_count\":3}','2026-11-17 21:25:49','2026-11-17 21:25:49'),(99,1,'coin.purchase_approved','coin_purchase_request','PUR-21037EBC049498EA','152.59.164.161','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"quantity\":\"500.00000000\",\"inventory_transaction_ref\":\"INV-AF54016B7908F9B0\",\"ownership_transaction_ref\":\"OWN-9D5A9CE5993BB528\",\"activated\":true,\"sponsor_snapshot\":true,\"mandatory_lock_ref\":\"LCK-M-9843C201C8AF7DC3\",\"commission_calc_expected\":\"0.00000000\",\"commission_posting\":\"OFF\"}','2026-11-17 21:25:49','2026-11-17 21:25:49'),(100,13,'member.registered','member_profile','NGW981239','223.184.140.37','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','{\"sponsor_id\":\"NGW981238\",\"source\":\"public\"}','2026-11-17 21:35:36','2026-11-17 21:35:36'),(101,13,'coin.purchase_requested','coin_purchase_request','PUR-C69C97B26EDF9546','223.184.140.37','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','{\"quantity\":\"100.00000000\",\"price\":\"0.05000000\",\"total_usdt\":\"5.00000000\"}','2026-11-17 21:36:33','2026-11-17 21:36:33'),(102,13,'usdt.deposit_submitted','usdt_deposit_request','DEP-05C2A521C4AB8CFB','223.184.140.37','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','{\"amount\":\"5.00000000\",\"network_key\":\"bep20\"}','2026-11-17 21:38:02','2026-11-17 21:38:02'),(103,1,'usdt.deposit_verified_manual','usdt_deposit_request','DEP-05C2A521C4AB8CFB','152.59.164.161','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"method\":\"MANUAL\",\"amount\":\"5.00000000\"}','2026-11-17 21:39:00','2026-11-17 21:39:00'),(104,13,'usdt.payment_linked','usdt_payment_allocation','PAY-527BBD258AC74AAE','152.59.164.161','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"deposit_ref\":\"DEP-05C2A521C4AB8CFB\",\"purchase_ref\":\"PUR-C69C97B26EDF9546\",\"amount\":\"5.00000000\"}','2026-11-17 21:39:00','2026-11-17 21:39:00'),(105,1,'commission.sponsor_snapshot_captured','coin_purchase_request','PUR-C69C97B26EDF9546','152.59.164.161','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"levels\":3,\"has_cycle\":false}','2026-11-17 21:39:11','2026-11-17 21:39:11'),(106,1,'coin.lock_mandatory_created','member_coin_lock','LCK-M-699C4E16ED019130','152.59.164.161','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"purchase_ref\":\"PUR-C69C97B26EDF9546\",\"quantity\":\"100.00000000\",\"days\":1000}','2026-11-17 21:39:11','2026-11-17 21:39:11'),(107,1,'commission.purchase_calculated','coin_purchase_request','PUR-C69C97B26EDF9546','152.59.164.161','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"expected_total\":\"0.00000000\",\"posting\":\"OFF\",\"line_count\":3}','2026-11-17 21:39:11','2026-11-17 21:39:11'),(108,1,'coin.purchase_approved','coin_purchase_request','PUR-C69C97B26EDF9546','152.59.164.161','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"quantity\":\"100.00000000\",\"inventory_transaction_ref\":\"INV-81B7B6F898461686\",\"ownership_transaction_ref\":\"OWN-DCB6EB71A13FC99F\",\"activated\":true,\"sponsor_snapshot\":true,\"mandatory_lock_ref\":\"LCK-M-699C4E16ED019130\",\"commission_calc_expected\":\"0.00000000\",\"commission_posting\":\"OFF\"}','2026-11-17 21:39:11','2026-11-17 21:39:11'),(109,1,'coin.purchase_rejected','coin_purchase_request','PUR-73E219F638FD202D','152.59.165.81','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"reason\":\"Payment not received\"}','2026-11-18 02:32:24','2026-11-18 02:32:24'),(110,1,'commission.plan_created','commission_plan','CPL-20261118-D5EEC007','152.59.165.81','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','{\"levels\":{\"1\":\"10.00000000\",\"2\":\"5.00000000\",\"3\":\"2.00000000\"},\"from\":\"2026-11-17\"}','2026-11-18 02:35:33','2026-11-18 02:35:33'),(111,1,'admin.impersonation_requested','impersonation_grant','AIG-20261118-4E35D672','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"target_member_id\":\"NGW000002\",\"target_user_id\":6,\"session_ref\":null}','2026-11-18 06:08:20','2026-11-18 06:08:20'),(112,1,'admin.impersonation_started','impersonation_session','AIS-20261118-A47F3031','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"target_member_id\":\"NGW000002\",\"target_user_id\":6,\"grant_ref\":\"AIG-20261118-4E35D672\"}','2026-11-18 06:08:22','2026-11-18 06:08:22'),(113,1,'admin.impersonation_member_viewed','impersonation_session','AIS-20261118-A47F3031','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"target_member_id\":\"NGW000002\",\"page\":\"dashboard\"}','2026-11-18 06:08:23','2026-11-18 06:08:23'),(114,1,'admin.impersonation_ended','impersonation_session','AIS-20261118-A47F3031','49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','{\"reason\":\"admin_logout\",\"target_member_id\":\"NGW000002\"}','2026-11-18 06:50:49','2026-11-18 06:50:49');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_groups_users`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_groups_users`
--

LOCK TABLES `auth_groups_users` WRITE;
/*!40000 ALTER TABLE `auth_groups_users` DISABLE KEYS */;
INSERT INTO `auth_groups_users` VALUES (6,1,'admin','2026-11-16 09:17:13'),(7,1,'member','2026-11-16 09:17:13'),(8,1,'finance_operator','2026-11-16 09:17:13'),(9,1,'finance_approver','2026-11-16 09:17:13'),(10,5,'member','2026-11-16 12:51:40'),(11,6,'member','2026-11-17 10:26:13'),(12,7,'member','2026-11-17 12:02:08'),(13,8,'member','2026-11-17 14:23:57'),(14,9,'member','2026-11-17 15:15:34'),(15,10,'member','2026-11-17 16:32:04'),(16,11,'member','2026-11-17 16:45:30'),(17,12,'member','2026-11-17 19:02:32'),(18,13,'member','2026-11-17 21:35:36');
/*!40000 ALTER TABLE `auth_groups_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_identities`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_identities`
--

LOCK TABLES `auth_identities` WRITE;
/*!40000 ALTER TABLE `auth_identities` DISABLE KEYS */;
INSERT INTO `auth_identities` VALUES (1,1,'email_password',NULL,'admin@aureumcoin.local','$2y$12$36QiTOImJS8WRVtRCka0duoJ6Br6iVWtpPxn.LmjX6YyTpxXqCIty',NULL,NULL,0,'2026-11-18 09:03:43','2026-11-12 07:05:07','2026-11-18 09:03:43'),(5,5,'email_password',NULL,'sknadimsk8509@gmail.com','$2y$12$NMzVbdMOikf4debHQdUze..5z2PMKXnikjPV9mRqf2idzAnsvHXXy',NULL,NULL,0,'2026-11-17 08:57:03','2026-11-16 12:51:40','2026-11-17 08:57:03'),(6,6,'email_password',NULL,'itarafdar73@gmail.com','$2y$12$UBQ1s6GDd/seLi.uhLtU.uFFdwYjbRZf5n9CMOh.Sz4hr0Lax4Tz2',NULL,NULL,0,'2026-11-17 15:31:14','2026-11-17 10:26:13','2026-11-17 15:31:14'),(7,7,'email_password',NULL,'tarafdarallamin@gmail.com','$2y$12$6qnNp6X1phFVh9XO3H9EfeHIJ0B7y.VAZW0AJZGl/3XFIzJ4BGkcm',NULL,NULL,0,'2026-11-18 02:36:48','2026-11-17 12:02:08','2026-11-18 02:36:48'),(8,8,'email_password',NULL,'mahibullasardar04@gmail.com','$2y$12$K7yR90aNCimiLgUXu/rfJejH4GSiYR/kUmVQzviwqrmwj3jYMf8Bu',NULL,NULL,0,'2026-11-18 01:15:08','2026-11-17 14:23:57','2026-11-18 01:15:08'),(9,9,'email_password',NULL,'sadikurrahaman915@gmail.com','$2y$12$zC2zm/T5OEXYgfexOgvheOxCy6tmjbotGxXMcbREkk7uNtEjAaC2W',NULL,NULL,0,'2026-11-18 08:46:26','2026-11-17 15:15:34','2026-11-18 08:46:26'),(10,10,'email_password',NULL,'sadikurrahaman407@gmail.com','$2y$12$qpwyp8zX3zoium.Z8heuPu0U3dE5o5T0Iszs5UVHbhpZ2fm30YVJ6',NULL,NULL,0,'2026-11-17 17:08:54','2026-11-17 16:32:04','2026-11-17 17:08:54'),(11,11,'email_password',NULL,'kalamgazi919@gmail.com','$2y$12$1B7flPeH/0X9Cn8.xMt9Ies7CPcAf9jx1ykZVlIqH5RQiDVuNxRDi',NULL,NULL,0,'2026-11-17 16:54:49','2026-11-17 16:45:30','2026-11-17 16:54:49'),(12,12,'email_password',NULL,'golpogriho1@gmail.com','$2y$12$7s9oeLx6pHxzOr1Cdc5AL.5iGEFscIoWv.Z1oXeZhrJ.zGPmBbUMm',NULL,NULL,0,'2026-11-17 21:25:12','2026-11-17 19:02:32','2026-11-17 21:25:12'),(13,13,'email_password',NULL,'smolla4051@gmail.com','$2y$12$lEjwuLfe7h7ec/sEVe2WM.U4WEXMpFFkXyq55ko4KlxU.4pjntoAi',NULL,NULL,0,'2026-11-17 21:39:55','2026-11-17 21:35:36','2026-11-17 21:39:55');
/*!40000 ALTER TABLE `auth_identities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_logins`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_logins`
--

LOCK TABLES `auth_logins` WRITE;
/*!40000 ALTER TABLE `auth_logins` DISABLE KEYS */;
INSERT INTO `auth_logins` VALUES (1,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-16 09:22:21',1),(2,'49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-16 10:06:12',1),(3,'152.56.132.48','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-16 10:42:58',1),(4,'49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-16 11:26:22',1),(5,'49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-16 12:50:04',1),(6,'49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36 Edg/157.0.0.0','email_password','sknadimsk8509@gmail.com',5,'2026-11-16 12:52:54',1),(7,'106.202.4.248','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/27.2 Safari/605.1.15','email_password','admin@aureumcoin.local',1,'2026-11-16 17:00:56',1),(8,'152.59.160.107','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36 Edg/157.0.0.0','email_password','admin@aureumcoin.local',1,'2026-11-16 17:48:17',1),(9,'152.58.162.50','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',NULL,'2026-11-17 03:54:30',0),(10,'152.58.162.50','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',NULL,'2026-11-17 03:55:38',0),(11,'152.58.162.50','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',NULL,'2026-11-17 04:01:07',0),(12,'152.58.162.50','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-17 04:02:16',1),(13,'49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-17 04:29:27',1),(14,'49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-17 08:03:38',1),(15,'49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-17 08:04:04',1),(16,'49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','sknadimsk8509@gmail.com',5,'2026-11-17 08:57:03',1),(17,'152.59.158.124','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-17 10:11:30',1),(18,'152.59.158.124','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','itarafdar73@gmail.com',6,'2026-11-17 10:30:13',1),(19,'152.56.132.246','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','email_password','itarafdar73@gmail.com',6,'2026-11-17 10:49:44',1),(20,'106.219.250.213','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36','email_password','tarafdarallamin@gmail.com',7,'2026-11-17 12:04:25',1),(21,'152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','email_password','tarafdarallamin@gmail.com',7,'2026-11-17 14:05:23',1),(22,'152.56.133.103','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','email_password','mahibullasardar04@gmail.com',8,'2026-11-17 14:24:27',1),(23,'152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-17 14:29:38',1),(24,'152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','email_password','tarafdarallamin@gmail.com',7,'2026-11-17 15:17:20',1),(25,'152.58.139.132','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','email_password','sadikurrahaman915@gmail.com',9,'2026-11-17 15:18:03',1),(26,'152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-17 15:22:31',1),(27,'152.58.139.132','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','email_password','sadikurrahaman915@gmail.com',9,'2026-11-17 15:24:46',1),(28,'152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','email_password','itarafdar73@gmail.com',6,'2026-11-17 15:31:14',1),(29,'152.58.162.104','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-17 15:58:50',1),(30,'152.59.163.243','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','email_password','sadikurrahaman407@gmail.com',10,'2026-11-17 16:32:40',1),(31,'152.59.163.243','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','email_password','sadikurrahaman915@gmail.com',9,'2026-11-17 16:34:55',1),(32,'152.59.163.243','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','email_password','sadikurrahaman915@gmail.com',9,'2026-11-17 16:35:27',1),(33,'152.59.163.243','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','email_password','sadikurrahaman407@gmail.com',10,'2026-11-17 16:35:49',1),(34,'106.202.2.15','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','email_password','kalamgazi919@gmail.com',11,'2026-11-17 16:46:26',1),(35,'106.202.2.15','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','email_password','kalamgazi919@gmail.com',11,'2026-11-17 16:50:56',1),(36,'106.202.2.15','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','email_password','kalamgazi919@gmail.com',11,'2026-11-17 16:54:49',1),(37,'152.59.163.9','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','email_password','sadikurrahaman407@gmail.com',10,'2026-11-17 16:56:05',1),(38,'152.59.163.46','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','email_password','sadikurrahaman915@gmail.com',9,'2026-11-17 17:02:51',1),(39,'152.59.163.46','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','email_password','sadikurrahaman407@gmail.com',10,'2026-11-17 17:08:54',1),(40,'223.184.140.37','Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/157.0.8103.37 Mobile/15E148 Safari/604.1','email_password','golpogriho1@gmail.com',12,'2026-11-17 19:06:39',1),(41,'152.59.161.126','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-17 19:29:27',1),(42,'152.59.161.126','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','email_password','tarafdarallamin@gmail.com',7,'2026-11-17 19:31:31',1),(43,'152.59.164.207','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','email_password','sadikurrahaman915@gmail.com',9,'2026-11-17 20:32:36',1),(44,'152.59.161.234','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-17 20:33:58',1),(45,'223.184.140.37','Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/157.0.8103.37 Mobile/15E148 Safari/604.1','email_password','golpogriho1@gmail.com',12,'2026-11-17 21:21:40',1),(46,'223.184.140.37','Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/157.0.8103.37 Mobile/15E148 Safari/604.1','email_password','golpogriho1@gmail.com',12,'2026-11-17 21:25:12',1),(47,'223.184.140.37','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','email_password','smolla4051@gmail.com',13,'2026-11-17 21:36:09',1),(48,'223.184.140.37','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','email_password','smolla4051@gmail.com',13,'2026-11-17 21:38:24',1),(49,'223.184.140.37','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','email_password','smolla4051@gmail.com',13,'2026-11-17 21:39:55',1),(50,'152.58.138.81','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','email_password','mahibullasardar04@gmail.com',8,'2026-11-18 01:15:08',1),(51,'152.59.165.81','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-18 02:31:45',1),(52,'152.59.165.81','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36','email_password','tarafdarallamin@gmail.com',7,'2026-11-18 02:36:48',1),(53,'152.56.133.184','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','email_password','sadikurrahaman915@gmail.com',9,'2026-11-18 04:19:57',1),(54,'49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-18 05:41:11',1),(55,'49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-18 06:50:54',1),(56,'152.58.163.234','Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36','email_password','sadikurrahaman915@gmail.com',9,'2026-11-18 08:46:26',1),(57,'49.37.42.41','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36','email_password','admin@aureumcoin.local',1,'2026-11-18 09:03:43',1);
/*!40000 ALTER TABLE `auth_logins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_permissions_users`
--

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
-- Dumping data for table `auth_permissions_users`
--

LOCK TABLES `auth_permissions_users` WRITE;
/*!40000 ALTER TABLE `auth_permissions_users` DISABLE KEYS */;
/*!40000 ALTER TABLE `auth_permissions_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_remember_tokens`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_remember_tokens`
--

LOCK TABLES `auth_remember_tokens` WRITE;
/*!40000 ALTER TABLE `auth_remember_tokens` DISABLE KEYS */;
INSERT INTO `auth_remember_tokens` VALUES (5,'2aa51b749581455d02811e3b','e6a472986e20fee13e87f07909e92c85afb66b1c542c25d6013e7b34750ded70',8,'2026-12-17 14:24:27','2026-11-17 14:24:27','2026-11-17 14:24:27'),(13,'cc6b6104f026f0c380eafc89','492477ec0c3e04899dd8ffd3e4865a347c1fabd0439939df4c1828ac776aa270',8,'2026-12-18 01:15:08','2026-11-18 01:15:08','2026-11-18 01:15:08');
/*!40000 ALTER TABLE `auth_remember_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_token_logins`
--

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
-- Dumping data for table `auth_token_logins`
--

LOCK TABLES `auth_token_logins` WRITE;
/*!40000 ALTER TABLE `auth_token_logins` DISABLE KEYS */;
/*!40000 ALTER TABLE `auth_token_logins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `blockchain_health_snapshots`
--

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blockchain_health_snapshots`
--

LOCK TABLES `blockchain_health_snapshots` WRITE;
/*!40000 ALTER TABLE `blockchain_health_snapshots` DISABLE KEYS */;
/*!40000 ALTER TABLE `blockchain_health_snapshots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `coin_inventory`
--

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
-- Dumping data for table `coin_inventory`
--

LOCK TABLES `coin_inventory` WRITE;
/*!40000 ALTER TABLE `coin_inventory` DISABLE KEYS */;
INSERT INTO `coin_inventory` VALUES (1,2998700.00000000,3000000.00000000,1300.00000000,'2026-11-16 09:17:13','2026-11-17 21:39:11');
/*!40000 ALTER TABLE `coin_inventory` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `coin_inventory_transactions`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `coin_inventory_transactions`
--

LOCK TABLES `coin_inventory_transactions` WRITE;
/*!40000 ALTER TABLE `coin_inventory_transactions` DISABLE KEYS */;
INSERT INTO `coin_inventory_transactions` VALUES (1,'INV-6A26B9F95944E3E2','ADMIN_INVENTORY_ADD','credit',3000000.00000000,0.00000000,3000000.00000000,1,'NGW',NULL,'eaf1fdd9c315b7be5192049e95082151','{\"label\":\"Unverified internal inventory record\"}','2026-11-16 18:18:44','2026-11-16 18:18:44'),(4,'INV-ACC6562A7BA7F1C2','MEMBER_PURCHASE_ALLOCATION','debit',100.00000000,3000000.00000000,2999900.00000000,1,'PUR-CE1D2C1ECD7A801A','Approved member purchase allocation','alloc:PUR-CE1D2C1ECD7A801A','{\"purchase_ref\":\"PUR-CE1D2C1ECD7A801A\",\"label\":\"Internal inventory allocation for approved purchase request\"}','2026-11-17 10:40:37','2026-11-17 10:40:37'),(5,'INV-43A8CF8053F99007','MEMBER_PURCHASE_ALLOCATION','debit',100.00000000,2999900.00000000,2999800.00000000,1,'PUR-DA91F7DA0F5DD26C','Approved member purchase allocation','alloc:PUR-DA91F7DA0F5DD26C','{\"purchase_ref\":\"PUR-DA91F7DA0F5DD26C\",\"label\":\"Internal inventory allocation for approved purchase request\"}','2026-11-17 12:41:05','2026-11-17 12:41:05'),(6,'INV-5283C4E749F427FA','MEMBER_PURCHASE_ALLOCATION','debit',100.00000000,2999800.00000000,2999700.00000000,1,'PUR-899B6BC6A2AF6FCC','Approved member purchase allocation','alloc:PUR-899B6BC6A2AF6FCC','{\"purchase_ref\":\"PUR-899B6BC6A2AF6FCC\",\"label\":\"Internal inventory allocation for approved purchase request\"}','2026-11-17 14:31:30','2026-11-17 14:31:30'),(7,'INV-E5335F7CD81E01F1','MEMBER_PURCHASE_ALLOCATION','debit',100.00000000,2999700.00000000,2999600.00000000,1,'PUR-84D66C36BB7C46AD','Approved member purchase allocation','alloc:PUR-84D66C36BB7C46AD','{\"purchase_ref\":\"PUR-84D66C36BB7C46AD\",\"label\":\"Internal inventory allocation for approved purchase request\"}','2026-11-17 15:24:01','2026-11-17 15:24:01'),(8,'INV-B56E65CC0BF3FE19','MEMBER_PURCHASE_ALLOCATION','debit',200.00000000,2999600.00000000,2999400.00000000,1,'PUR-34049F062590516B','Approved member purchase allocation','alloc:PUR-34049F062590516B','{\"purchase_ref\":\"PUR-34049F062590516B\",\"label\":\"Internal inventory allocation for approved purchase request\"}','2026-11-17 16:35:24','2026-11-17 16:35:24'),(9,'INV-AE1084832360AD7D','MEMBER_PURCHASE_ALLOCATION','debit',100.00000000,2999400.00000000,2999300.00000000,1,'PUR-1B4480DAD069E5B3','Approved member purchase allocation','alloc:PUR-1B4480DAD069E5B3','{\"purchase_ref\":\"PUR-1B4480DAD069E5B3\",\"label\":\"Internal inventory allocation for approved purchase request\"}','2026-11-17 16:50:53','2026-11-17 16:50:53'),(10,'INV-AF54016B7908F9B0','MEMBER_PURCHASE_ALLOCATION','debit',500.00000000,2999300.00000000,2998800.00000000,1,'PUR-21037EBC049498EA','Approved member purchase allocation','alloc:PUR-21037EBC049498EA','{\"purchase_ref\":\"PUR-21037EBC049498EA\",\"label\":\"Internal inventory allocation for approved purchase request\"}','2026-11-17 21:25:49','2026-11-17 21:25:49'),(11,'INV-81B7B6F898461686','MEMBER_PURCHASE_ALLOCATION','debit',100.00000000,2998800.00000000,2998700.00000000,1,'PUR-C69C97B26EDF9546','Approved member purchase allocation','alloc:PUR-C69C97B26EDF9546','{\"purchase_ref\":\"PUR-C69C97B26EDF9546\",\"label\":\"Internal inventory allocation for approved purchase request\"}','2026-11-17 21:39:11','2026-11-17 21:39:11');
/*!40000 ALTER TABLE `coin_inventory_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `coin_locking_plans`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `coin_locking_plans`
--

LOCK TABLES `coin_locking_plans` WRITE;
/*!40000 ALTER TABLE `coin_locking_plans` DISABLE KEYS */;
INSERT INTO `coin_locking_plans` VALUES (1,'LOCK1000','Mandatory 1000-Day Purchase Lock','System plan for Coin Plan mandatory locks after purchase approval.',0.00000001,NULL,1000,1,0,1,1,'2026-11-17 10:40:37','2026-11-17 10:40:37');
/*!40000 ALTER TABLE `coin_locking_plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `coin_price_history`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `coin_price_history`
--

LOCK TABLES `coin_price_history` WRITE;
/*!40000 ALTER TABLE `coin_price_history` DISABLE KEYS */;
INSERT INTO `coin_price_history` VALUES (1,1.00000000,0.05000000,1,'FIRST PRE SELL PRICE','2026-11-16 18:14:09','2026-11-16 18:14:09','2026-11-16 18:14:09'),(2,0.05000000,2.00000000,1,'gfgfgrgr','2026-11-17 04:32:38','2026-11-17 04:32:38','2026-11-17 04:32:38'),(3,2.00000000,0.05000000,1,'OPENING PRICE','2026-11-17 10:32:07','2026-11-17 10:32:07','2026-11-17 10:32:07');
/*!40000 ALTER TABLE `coin_price_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `coin_purchase_requests`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `coin_purchase_requests`
--

LOCK TABLES `coin_purchase_requests` WRITE;
/*!40000 ALTER TABLE `coin_purchase_requests` DISABLE KEYS */;
INSERT INTO `coin_purchase_requests` VALUES (1,'PUR-CE1D2C1ECD7A801A',6,'NGW000002',100.00000000,0.05000000,5.00000000,'approved',NULL,NULL,1,'2026-11-17 10:32:51','2026-11-17 10:40:37','191ca78842a27cdc032040b62224d504','INV-ACC6562A7BA7F1C2','OWN-F6AE7D6FAB2BCDA3','DEP-F2C593D936A6830C','verified','2026-11-17 10:32:51','2026-11-17 10:40:37'),(2,'PUR-D61744BF39922D39',6,'NGW000002',2000.00000000,0.05000000,100.00000000,'rejected',NULL,'NO PAYMENT',1,'2026-11-17 10:50:36','2026-11-17 12:19:12','d8ce0db5d21807eecf6ed4fe4186a176',NULL,NULL,NULL,'unpaid','2026-11-17 10:50:36','2026-11-17 12:19:12'),(3,'PUR-880DA8BF9321A4AD',7,'NGW000003',100.00000000,0.05000000,5.00000000,'rejected',NULL,'Payment not received',1,'2026-11-17 12:20:47','2026-11-17 16:29:14','d0ea8d1c13a7a156fb426526ac121744',NULL,NULL,NULL,'unpaid','2026-11-17 12:20:47','2026-11-17 16:29:14'),(4,'PUR-DA91F7DA0F5DD26C',7,'NGW000003',100.00000000,0.05000000,5.00000000,'approved',NULL,NULL,1,'2026-11-17 12:34:10','2026-11-17 12:41:05','1e25184262afb80a9b32a0d8c06cc6bb','INV-43A8CF8053F99007','OWN-1E01D6B5DFDBEE25','DEP-0FD4804DC7E76307','verified','2026-11-17 12:34:10','2026-11-17 12:41:05'),(5,'PUR-899B6BC6A2AF6FCC',8,'NGW981234',100.00000000,0.05000000,5.00000000,'approved',NULL,NULL,1,'2026-11-17 14:25:23','2026-11-17 14:31:30','7f042c55a7c7544b5518cb1b5dbf7317','INV-5283C4E749F427FA','OWN-5F676EDC14B02DEF','DEP-48BEC06030C4E190','verified','2026-11-17 14:25:23','2026-11-17 14:31:30'),(6,'PUR-84D66C36BB7C46AD',9,'NGW981235',100.00000000,0.05000000,5.00000000,'approved',NULL,NULL,1,'2026-11-17 15:19:14','2026-11-17 15:24:01','a5d5110799d04e20172096793e90f4e5','INV-E5335F7CD81E01F1','OWN-FC78AC6778DC5F6F','DEP-942A048112A938F3','verified','2026-11-17 15:19:14','2026-11-17 15:24:01'),(7,'PUR-34049F062590516B',10,'NGW981236',200.00000000,0.05000000,10.00000000,'approved',NULL,NULL,1,'2026-11-17 16:33:32','2026-11-17 16:35:24','9bbdfe600d8aab86c80dda68e3c58e88','INV-B56E65CC0BF3FE19','OWN-79E6E59613A0FDA3','DEP-062356221DC3D2AB','verified','2026-11-17 16:33:32','2026-11-17 16:35:24'),(8,'PUR-1B4480DAD069E5B3',11,'NGW981237',100.00000000,0.05000000,5.00000000,'approved',NULL,NULL,1,'2026-11-17 16:47:05','2026-11-17 16:50:54','8b7f3ab81ea79a440c87b5aed51e94ae','INV-AE1084832360AD7D','OWN-42CB4E90AE7232CB','DEP-76FCB506397419BF','verified','2026-11-17 16:47:05','2026-11-17 16:50:54'),(9,'PUR-73E219F638FD202D',10,'NGW981236',100.00000000,0.05000000,5.00000000,'rejected',NULL,'Payment not received',1,'2026-11-17 19:25:47','2026-11-18 02:32:24','a960054cc23365f1ceb12deab12287ab',NULL,NULL,NULL,'unpaid','2026-11-17 19:25:47','2026-11-18 02:32:24'),(10,'PUR-21037EBC049498EA',12,'NGW981238',500.00000000,0.05000000,25.00000000,'approved',NULL,NULL,1,'2026-11-17 21:22:18','2026-11-17 21:25:49','04056e4d6a6818ad512dd5305fe5c48b','INV-AF54016B7908F9B0','OWN-9D5A9CE5993BB528','DEP-5BD53E8A2CDE2FA0','verified','2026-11-17 21:22:18','2026-11-17 21:25:49'),(11,'PUR-C69C97B26EDF9546',13,'NGW981239',100.00000000,0.05000000,5.00000000,'approved',NULL,NULL,1,'2026-11-17 21:36:33','2026-11-17 21:39:11','e9cfcd40c23e21cbf93695fd4305ab0c','INV-81B7B6F898461686','OWN-DCB6EB71A13FC99F','DEP-05C2A521C4AB8CFB','verified','2026-11-17 21:36:33','2026-11-17 21:39:11');
/*!40000 ALTER TABLE `coin_purchase_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `coin_sell_events`
--

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
-- Dumping data for table `coin_sell_events`
--

LOCK TABLES `coin_sell_events` WRITE;
/*!40000 ALTER TABLE `coin_sell_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `coin_sell_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `coin_sell_requests`
--

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
-- Dumping data for table `coin_sell_requests`
--

LOCK TABLES `coin_sell_requests` WRITE;
/*!40000 ALTER TABLE `coin_sell_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `coin_sell_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `coin_settings`
--

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
-- Dumping data for table `coin_settings`
--

LOCK TABLES `coin_settings` WRITE;
/*!40000 ALTER TABLE `coin_settings` DISABLE KEYS */;
INSERT INTO `coin_settings` VALUES (1,0.05000000,5.00000000,'2026-11-17 10:32:07',1,'2026-11-12 07:31:13','2026-11-17 10:32:07');
/*!40000 ALTER TABLE `coin_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_eligibility_audit_events`
--

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
-- Dumping data for table `commission_eligibility_audit_events`
--

LOCK TABLES `commission_eligibility_audit_events` WRITE;
/*!40000 ALTER TABLE `commission_eligibility_audit_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `commission_eligibility_audit_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_plan_approval_events`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commission_plan_approval_events`
--

LOCK TABLES `commission_plan_approval_events` WRITE;
/*!40000 ALTER TABLE `commission_plan_approval_events` DISABLE KEYS */;
INSERT INTO `commission_plan_approval_events` VALUES (1,'CPL-20261118-D5EEC007','created',1,NULL,'draft','{\"levels\":{\"1\":\"10.00000000\",\"2\":\"5.00000000\",\"3\":\"2.00000000\"}}','2026-11-18 02:35:33');
/*!40000 ALTER TABLE `commission_plan_approval_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_plan_levels`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commission_plan_levels`
--

LOCK TABLES `commission_plan_levels` WRITE;
/*!40000 ALTER TABLE `commission_plan_levels` DISABLE KEYS */;
INSERT INTO `commission_plan_levels` VALUES (1,'CPL-20261118-D5EEC007',1,10.00000000,'2026-11-18 02:35:33'),(2,'CPL-20261118-D5EEC007',2,5.00000000,'2026-11-18 02:35:33'),(3,'CPL-20261118-D5EEC007',3,2.00000000,'2026-11-18 02:35:33');
/*!40000 ALTER TABLE `commission_plan_levels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_plans`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commission_plans`
--

LOCK TABLES `commission_plans` WRITE;
/*!40000 ALTER TABLE `commission_plans` DISABLE KEYS */;
INSERT INTO `commission_plans` VALUES (1,'CPL-20261118-D5EEC007','Refferal commission','2026-11-17',NULL,3,'USDT','approved_verified_usdt','active_sponsors_only','draft',1,NULL,NULL,NULL,NULL,NULL,'2026-11-18 02:35:33','2026-11-18 02:35:33');
/*!40000 ALTER TABLE `commission_plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_posting_proposal_events`
--

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
-- Dumping data for table `commission_posting_proposal_events`
--

LOCK TABLES `commission_posting_proposal_events` WRITE;
/*!40000 ALTER TABLE `commission_posting_proposal_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `commission_posting_proposal_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_posting_proposals`
--

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
-- Dumping data for table `commission_posting_proposals`
--

LOCK TABLES `commission_posting_proposals` WRITE;
/*!40000 ALTER TABLE `commission_posting_proposals` DISABLE KEYS */;
/*!40000 ALTER TABLE `commission_posting_proposals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_purchase_calculations`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commission_purchase_calculations`
--

LOCK TABLES `commission_purchase_calculations` WRITE;
/*!40000 ALTER TABLE `commission_purchase_calculations` DISABLE KEYS */;
INSERT INTO `commission_purchase_calculations` VALUES (1,'CPC-BE31CFD52B87','PUR-CE1D2C1ECD7A801A',1,6,'NGW000002',1,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-CE1D2C1ECD7A801A:L1',1,'2026-11-17 10:40:37','2026-11-17 10:40:37'),(2,'CPC-BB4D51633CCF','PUR-CE1D2C1ECD7A801A',1,6,'NGW000002',2,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-CE1D2C1ECD7A801A:L2',1,'2026-11-17 10:40:37','2026-11-17 10:40:37'),(3,'CPC-645681CE7950','PUR-CE1D2C1ECD7A801A',1,6,'NGW000002',3,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-CE1D2C1ECD7A801A:L3',1,'2026-11-17 10:40:37','2026-11-17 10:40:37'),(4,'CPC-63CF4911C791','PUR-DA91F7DA0F5DD26C',4,7,'NGW000003',1,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-DA91F7DA0F5DD26C:L1',1,'2026-11-17 12:41:05','2026-11-17 12:41:05'),(5,'CPC-C6D7DBD8186B','PUR-DA91F7DA0F5DD26C',4,7,'NGW000003',2,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-DA91F7DA0F5DD26C:L2',1,'2026-11-17 12:41:05','2026-11-17 12:41:05'),(6,'CPC-CACF45CC444A','PUR-DA91F7DA0F5DD26C',4,7,'NGW000003',3,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-DA91F7DA0F5DD26C:L3',1,'2026-11-17 12:41:05','2026-11-17 12:41:05'),(7,'CPC-D964E4D65AAD','PUR-899B6BC6A2AF6FCC',5,8,'NGW981234',1,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-899B6BC6A2AF6FCC:L1',1,'2026-11-17 14:31:30','2026-11-17 14:31:30'),(8,'CPC-E83501DECD8C','PUR-899B6BC6A2AF6FCC',5,8,'NGW981234',2,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-899B6BC6A2AF6FCC:L2',1,'2026-11-17 14:31:30','2026-11-17 14:31:30'),(9,'CPC-75BFD78303C0','PUR-899B6BC6A2AF6FCC',5,8,'NGW981234',3,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-899B6BC6A2AF6FCC:L3',1,'2026-11-17 14:31:30','2026-11-17 14:31:30'),(10,'CPC-6294F018E0B5','PUR-84D66C36BB7C46AD',6,9,'NGW981235',1,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-84D66C36BB7C46AD:L1',1,'2026-11-17 15:24:01','2026-11-17 15:24:01'),(11,'CPC-874EB5C5BA42','PUR-84D66C36BB7C46AD',6,9,'NGW981235',2,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-84D66C36BB7C46AD:L2',1,'2026-11-17 15:24:01','2026-11-17 15:24:01'),(12,'CPC-E7124E578FA8','PUR-84D66C36BB7C46AD',6,9,'NGW981235',3,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-84D66C36BB7C46AD:L3',1,'2026-11-17 15:24:01','2026-11-17 15:24:01'),(13,'CPC-9E824051DFF9','PUR-34049F062590516B',7,10,'NGW981236',1,NULL,NULL,10.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-34049F062590516B:L1',1,'2026-11-17 16:35:24','2026-11-17 16:35:24'),(14,'CPC-3595B31FA451','PUR-34049F062590516B',7,10,'NGW981236',2,NULL,NULL,10.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-34049F062590516B:L2',1,'2026-11-17 16:35:24','2026-11-17 16:35:24'),(15,'CPC-3527AFB4862B','PUR-34049F062590516B',7,10,'NGW981236',3,NULL,NULL,10.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-34049F062590516B:L3',1,'2026-11-17 16:35:24','2026-11-17 16:35:24'),(16,'CPC-D2EF044A53CA','PUR-1B4480DAD069E5B3',8,11,'NGW981237',1,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-1B4480DAD069E5B3:L1',1,'2026-11-17 16:50:54','2026-11-17 16:50:54'),(17,'CPC-B18C3D6C68DE','PUR-1B4480DAD069E5B3',8,11,'NGW981237',2,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-1B4480DAD069E5B3:L2',1,'2026-11-17 16:50:54','2026-11-17 16:50:54'),(18,'CPC-27AE43AB45CF','PUR-1B4480DAD069E5B3',8,11,'NGW981237',3,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-1B4480DAD069E5B3:L3',1,'2026-11-17 16:50:54','2026-11-17 16:50:54'),(19,'CPC-AD12758D88C3','PUR-21037EBC049498EA',10,12,'NGW981238',1,NULL,NULL,25.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-21037EBC049498EA:L1',1,'2026-11-17 21:25:49','2026-11-17 21:25:49'),(20,'CPC-14B3B7FE4F70','PUR-21037EBC049498EA',10,12,'NGW981238',2,NULL,NULL,25.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-21037EBC049498EA:L2',1,'2026-11-17 21:25:49','2026-11-17 21:25:49'),(21,'CPC-7131DC8E55B4','PUR-21037EBC049498EA',10,12,'NGW981238',3,NULL,NULL,25.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-21037EBC049498EA:L3',1,'2026-11-17 21:25:49','2026-11-17 21:25:49'),(22,'CPC-2B1DBC2856D8','PUR-C69C97B26EDF9546',11,13,'NGW981239',1,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-C69C97B26EDF9546:L1',1,'2026-11-17 21:39:11','2026-11-17 21:39:11'),(23,'CPC-92E871E1F8A0','PUR-C69C97B26EDF9546',11,13,'NGW981239',2,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-C69C97B26EDF9546:L2',1,'2026-11-17 21:39:11','2026-11-17 21:39:11'),(24,'CPC-F1C03160278A','PUR-C69C97B26EDF9546',11,13,'NGW981239',3,NULL,NULL,5.00000000,NULL,0.00000000,0.00000000,NULL,NULL,'2026-11-17','NO_APPROVED_PLAN','ineligible','no_approved_plan','{\"label\":\"DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY\",\"commission_base\":\"approved_purchase_total_usdt\",\"rounding\":\"bcmath_truncate_toward_zero_at_usdt_scale\",\"missing_levels\":\"recorded_as_unallocated_no_redistribution\",\"posting\":\"OFF\",\"unconfirmed\":[\"commission_base_business_signoff\",\"rounding_policy_signoff\"]}','cpc:PUR-C69C97B26EDF9546:L3',1,'2026-11-17 21:39:11','2026-11-17 21:39:11');
/*!40000 ALTER TABLE `commission_purchase_calculations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_simulation_items`
--

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
-- Dumping data for table `commission_simulation_items`
--

LOCK TABLES `commission_simulation_items` WRITE;
/*!40000 ALTER TABLE `commission_simulation_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `commission_simulation_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_simulation_runs`
--

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
-- Dumping data for table `commission_simulation_runs`
--

LOCK TABLES `commission_simulation_runs` WRITE;
/*!40000 ALTER TABLE `commission_simulation_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `commission_simulation_runs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_wallet_accounts`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commission_wallet_accounts`
--

LOCK TABLES `commission_wallet_accounts` WRITE;
/*!40000 ALTER TABLE `commission_wallet_accounts` DISABLE KEYS */;
INSERT INTO `commission_wallet_accounts` VALUES (1,5,'NGW000001','USDT',0.00000000,0.00000000,0.00000000,'2026-11-17 08:57:04','2026-11-17 08:57:04'),(2,6,'NGW000002','USDT',0.00000000,0.00000000,0.00000000,'2026-11-17 10:30:14','2026-11-17 10:30:14'),(3,7,'NGW000003','USDT',0.00000000,0.00000000,0.00000000,'2026-11-17 12:04:28','2026-11-17 12:04:28'),(4,8,'NGW981234','USDT',0.00000000,0.00000000,0.00000000,'2026-11-17 14:24:28','2026-11-17 14:24:28'),(5,1,'ROOT0001','USDT',0.00000000,0.00000000,0.00000000,'2026-11-17 15:16:55','2026-11-17 15:16:55'),(6,9,'NGW981235','USDT',0.00000000,0.00000000,0.00000000,'2026-11-17 15:18:04','2026-11-17 15:18:04'),(7,10,'NGW981236','USDT',0.00000000,0.00000000,0.00000000,'2026-11-17 16:32:41','2026-11-17 16:32:41'),(8,11,'NGW981237','USDT',0.00000000,0.00000000,0.00000000,'2026-11-17 16:46:27','2026-11-17 16:46:27'),(9,12,'NGW981238','USDT',0.00000000,0.00000000,0.00000000,'2026-11-17 19:06:41','2026-11-17 19:06:41'),(10,13,'NGW981239','USDT',0.00000000,0.00000000,0.00000000,'2026-11-17 21:36:11','2026-11-17 21:36:11');
/*!40000 ALTER TABLE `commission_wallet_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_wallet_ledger_entries`
--

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
-- Dumping data for table `commission_wallet_ledger_entries`
--

LOCK TABLES `commission_wallet_ledger_entries` WRITE;
/*!40000 ALTER TABLE `commission_wallet_ledger_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `commission_wallet_ledger_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_withdrawal_events`
--

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
-- Dumping data for table `commission_withdrawal_events`
--

LOCK TABLES `commission_withdrawal_events` WRITE;
/*!40000 ALTER TABLE `commission_withdrawal_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `commission_withdrawal_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_withdrawal_payout_evidence`
--

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
-- Dumping data for table `commission_withdrawal_payout_evidence`
--

LOCK TABLES `commission_withdrawal_payout_evidence` WRITE;
/*!40000 ALTER TABLE `commission_withdrawal_payout_evidence` DISABLE KEYS */;
/*!40000 ALTER TABLE `commission_withdrawal_payout_evidence` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_withdrawal_requests`
--

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
-- Dumping data for table `commission_withdrawal_requests`
--

LOCK TABLES `commission_withdrawal_requests` WRITE;
/*!40000 ALTER TABLE `commission_withdrawal_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `commission_withdrawal_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `financial_alerts`
--

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
-- Dumping data for table `financial_alerts`
--

LOCK TABLES `financial_alerts` WRITE;
/*!40000 ALTER TABLE `financial_alerts` DISABLE KEYS */;
/*!40000 ALTER TABLE `financial_alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `financial_remediation_events`
--

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
-- Dumping data for table `financial_remediation_events`
--

LOCK TABLES `financial_remediation_events` WRITE;
/*!40000 ALTER TABLE `financial_remediation_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `financial_remediation_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `financial_remediation_proposals`
--

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
-- Dumping data for table `financial_remediation_proposals`
--

LOCK TABLES `financial_remediation_proposals` WRITE;
/*!40000 ALTER TABLE `financial_remediation_proposals` DISABLE KEYS */;
/*!40000 ALTER TABLE `financial_remediation_proposals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_coin_balances`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `member_coin_balances`
--

LOCK TABLES `member_coin_balances` WRITE;
/*!40000 ALTER TABLE `member_coin_balances` DISABLE KEYS */;
INSERT INTO `member_coin_balances` VALUES (3,6,'NGW000002',100.00000000,0.00000000,100.00000000,0.00000000,100.00000000,'2026-11-17 10:40:37','2026-11-17 10:40:37'),(4,7,'NGW000003',100.00000000,0.00000000,100.00000000,0.00000000,100.00000000,'2026-11-17 12:41:05','2026-11-17 12:41:05'),(5,8,'NGW981234',100.00000000,0.00000000,100.00000000,0.00000000,100.00000000,'2026-11-17 14:31:30','2026-11-17 14:31:30'),(6,9,'NGW981235',100.00000000,0.00000000,100.00000000,0.00000000,100.00000000,'2026-11-17 15:24:01','2026-11-17 15:24:01'),(7,10,'NGW981236',200.00000000,0.00000000,200.00000000,0.00000000,200.00000000,'2026-11-17 16:35:24','2026-11-17 16:35:24'),(8,11,'NGW981237',100.00000000,0.00000000,100.00000000,0.00000000,100.00000000,'2026-11-17 16:50:54','2026-11-17 16:50:54'),(9,12,'NGW981238',500.00000000,0.00000000,500.00000000,0.00000000,500.00000000,'2026-11-17 21:25:49','2026-11-17 21:25:49'),(10,13,'NGW981239',100.00000000,0.00000000,100.00000000,0.00000000,100.00000000,'2026-11-17 21:39:11','2026-11-17 21:39:11');
/*!40000 ALTER TABLE `member_coin_balances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_coin_lock_events`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `member_coin_lock_events`
--

LOCK TABLES `member_coin_lock_events` WRITE;
/*!40000 ALTER TABLE `member_coin_lock_events` DISABLE KEYS */;
INSERT INTO `member_coin_lock_events` VALUES (1,'LCK-M-8D685D52EACE360C','lock.mandatory_created',1,'Mandatory purchase lock','{\"purchase_ref\":\"PUR-CE1D2C1ECD7A801A\",\"quantity\":\"100.00000000\",\"matures_at\":\"2029-08-13 10:40:37\",\"days\":1000}','2026-11-17 10:40:37'),(2,'LCK-M-43500DBFAEEEBECF','lock.mandatory_created',1,'Mandatory purchase lock','{\"purchase_ref\":\"PUR-DA91F7DA0F5DD26C\",\"quantity\":\"100.00000000\",\"matures_at\":\"2029-08-13 12:41:05\",\"days\":1000}','2026-11-17 12:41:05'),(3,'LCK-M-EE24E1517DCE4B9B','lock.mandatory_created',1,'Mandatory purchase lock','{\"purchase_ref\":\"PUR-899B6BC6A2AF6FCC\",\"quantity\":\"100.00000000\",\"matures_at\":\"2029-08-13 14:31:30\",\"days\":1000}','2026-11-17 14:31:30'),(4,'LCK-M-943F318036C8C378','lock.mandatory_created',1,'Mandatory purchase lock','{\"purchase_ref\":\"PUR-84D66C36BB7C46AD\",\"quantity\":\"100.00000000\",\"matures_at\":\"2029-08-13 15:24:01\",\"days\":1000}','2026-11-17 15:24:01'),(5,'LCK-M-BD51E60CF601C9FE','lock.mandatory_created',1,'Mandatory purchase lock','{\"purchase_ref\":\"PUR-34049F062590516B\",\"quantity\":\"200.00000000\",\"matures_at\":\"2029-08-13 16:35:24\",\"days\":1000}','2026-11-17 16:35:24'),(6,'LCK-M-2DEE923F3148DA1B','lock.mandatory_created',1,'Mandatory purchase lock','{\"purchase_ref\":\"PUR-1B4480DAD069E5B3\",\"quantity\":\"100.00000000\",\"matures_at\":\"2029-08-13 16:50:54\",\"days\":1000}','2026-11-17 16:50:54'),(7,'LCK-M-9843C201C8AF7DC3','lock.mandatory_created',1,'Mandatory purchase lock','{\"purchase_ref\":\"PUR-21037EBC049498EA\",\"quantity\":\"500.00000000\",\"matures_at\":\"2029-08-13 21:25:49\",\"days\":1000}','2026-11-17 21:25:49'),(8,'LCK-M-699C4E16ED019130','lock.mandatory_created',1,'Mandatory purchase lock','{\"purchase_ref\":\"PUR-C69C97B26EDF9546\",\"quantity\":\"100.00000000\",\"matures_at\":\"2029-08-13 21:39:11\",\"days\":1000}','2026-11-17 21:39:11');
/*!40000 ALTER TABLE `member_coin_lock_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_coin_locks`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `member_coin_locks`
--

LOCK TABLES `member_coin_locks` WRITE;
/*!40000 ALTER TABLE `member_coin_locks` DISABLE KEYS */;
INSERT INTO `member_coin_locks` VALUES (1,'LCK-M-8D685D52EACE360C',6,'NGW000002','PUR-CE1D2C1ECD7A801A','mandatory_purchase',1,'LOCK1000','Mandatory 1000-Day Purchase Lock',100.00000000,1000,'active','2026-11-17 10:40:37','2029-08-13 10:40:37',NULL,NULL,NULL,'OWN-03001D4396F847F2',NULL,'mandatory-lock:PUR-CE1D2C1ECD7A801A',NULL,'2026-11-17 10:40:37','2026-11-17 10:40:37'),(2,'LCK-M-43500DBFAEEEBECF',7,'NGW000003','PUR-DA91F7DA0F5DD26C','mandatory_purchase',1,'LOCK1000','Mandatory 1000-Day Purchase Lock',100.00000000,1000,'active','2026-11-17 12:41:05','2029-08-13 12:41:05',NULL,NULL,NULL,'OWN-95DA97D88293AF25',NULL,'mandatory-lock:PUR-DA91F7DA0F5DD26C',NULL,'2026-11-17 12:41:05','2026-11-17 12:41:05'),(3,'LCK-M-EE24E1517DCE4B9B',8,'NGW981234','PUR-899B6BC6A2AF6FCC','mandatory_purchase',1,'LOCK1000','Mandatory 1000-Day Purchase Lock',100.00000000,1000,'active','2026-11-17 14:31:30','2029-08-13 14:31:30',NULL,NULL,NULL,'OWN-AE93490488E9F733',NULL,'mandatory-lock:PUR-899B6BC6A2AF6FCC',NULL,'2026-11-17 14:31:30','2026-11-17 14:31:30'),(4,'LCK-M-943F318036C8C378',9,'NGW981235','PUR-84D66C36BB7C46AD','mandatory_purchase',1,'LOCK1000','Mandatory 1000-Day Purchase Lock',100.00000000,1000,'active','2026-11-17 15:24:01','2029-08-13 15:24:01',NULL,NULL,NULL,'OWN-21997A3AF4537962',NULL,'mandatory-lock:PUR-84D66C36BB7C46AD',NULL,'2026-11-17 15:24:01','2026-11-17 15:24:01'),(5,'LCK-M-BD51E60CF601C9FE',10,'NGW981236','PUR-34049F062590516B','mandatory_purchase',1,'LOCK1000','Mandatory 1000-Day Purchase Lock',200.00000000,1000,'active','2026-11-17 16:35:24','2029-08-13 16:35:24',NULL,NULL,NULL,'OWN-4B576D09ED563537',NULL,'mandatory-lock:PUR-34049F062590516B',NULL,'2026-11-17 16:35:24','2026-11-17 16:35:24'),(6,'LCK-M-2DEE923F3148DA1B',11,'NGW981237','PUR-1B4480DAD069E5B3','mandatory_purchase',1,'LOCK1000','Mandatory 1000-Day Purchase Lock',100.00000000,1000,'active','2026-11-17 16:50:54','2029-08-13 16:50:54',NULL,NULL,NULL,'OWN-26FA78B54725BE7C',NULL,'mandatory-lock:PUR-1B4480DAD069E5B3',NULL,'2026-11-17 16:50:54','2026-11-17 16:50:54'),(7,'LCK-M-9843C201C8AF7DC3',12,'NGW981238','PUR-21037EBC049498EA','mandatory_purchase',1,'LOCK1000','Mandatory 1000-Day Purchase Lock',500.00000000,1000,'active','2026-11-17 21:25:49','2029-08-13 21:25:49',NULL,NULL,NULL,'OWN-3F4F82FF5217D256',NULL,'mandatory-lock:PUR-21037EBC049498EA',NULL,'2026-11-17 21:25:49','2026-11-17 21:25:49'),(8,'LCK-M-699C4E16ED019130',13,'NGW981239','PUR-C69C97B26EDF9546','mandatory_purchase',1,'LOCK1000','Mandatory 1000-Day Purchase Lock',100.00000000,1000,'active','2026-11-17 21:39:11','2029-08-13 21:39:11',NULL,NULL,NULL,'OWN-E7D153CF2CEDBD5C',NULL,'mandatory-lock:PUR-C69C97B26EDF9546',NULL,'2026-11-17 21:39:11','2026-11-17 21:39:11');
/*!40000 ALTER TABLE `member_coin_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_coin_ownership_transactions`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `member_coin_ownership_transactions`
--

LOCK TABLES `member_coin_ownership_transactions` WRITE;
/*!40000 ALTER TABLE `member_coin_ownership_transactions` DISABLE KEYS */;
INSERT INTO `member_coin_ownership_transactions` VALUES (3,'OWN-F6AE7D6FAB2BCDA3',6,'NGW000002','PURCHASE_CREDIT','credit',100.00000000,0.00000000,100.00000000,0.00000000,100.00000000,0.00000000,0.00000000,NULL,NULL,'PUR-CE1D2C1ECD7A801A',NULL,NULL,'INV-ACC6562A7BA7F1C2',1,'own:PUR-CE1D2C1ECD7A801A','{\"source\":\"approved_purchase\"}','2026-11-17 10:40:37','2026-11-17 10:40:37'),(4,'OWN-03001D4396F847F2',6,'NGW000002','LOCK','debit',100.00000000,100.00000000,100.00000000,100.00000000,0.00000000,0.00000000,100.00000000,NULL,NULL,NULL,'LCK-M-8D685D52EACE360C',NULL,NULL,1,'own-lock:mandatory-lock:PUR-CE1D2C1ECD7A801A','{\"source\":\"coin_lock\",\"lock_ref\":\"LCK-M-8D685D52EACE360C\"}','2026-11-17 10:40:37','2026-11-17 10:40:37'),(5,'OWN-1E01D6B5DFDBEE25',7,'NGW000003','PURCHASE_CREDIT','credit',100.00000000,0.00000000,100.00000000,0.00000000,100.00000000,0.00000000,0.00000000,0.00000000,0.00000000,'PUR-DA91F7DA0F5DD26C',NULL,NULL,'INV-43A8CF8053F99007',1,'own:PUR-DA91F7DA0F5DD26C','{\"source\":\"approved_purchase\"}','2026-11-17 12:41:05','2026-11-17 12:41:05'),(6,'OWN-95DA97D88293AF25',7,'NGW000003','LOCK','debit',100.00000000,100.00000000,100.00000000,100.00000000,0.00000000,0.00000000,100.00000000,0.00000000,0.00000000,NULL,'LCK-M-43500DBFAEEEBECF',NULL,NULL,1,'own-lock:mandatory-lock:PUR-DA91F7DA0F5DD26C','{\"source\":\"coin_lock\",\"lock_ref\":\"LCK-M-43500DBFAEEEBECF\"}','2026-11-17 12:41:05','2026-11-17 12:41:05'),(7,'OWN-5F676EDC14B02DEF',8,'NGW981234','PURCHASE_CREDIT','credit',100.00000000,0.00000000,100.00000000,0.00000000,100.00000000,0.00000000,0.00000000,0.00000000,0.00000000,'PUR-899B6BC6A2AF6FCC',NULL,NULL,'INV-5283C4E749F427FA',1,'own:PUR-899B6BC6A2AF6FCC','{\"source\":\"approved_purchase\"}','2026-11-17 14:31:30','2026-11-17 14:31:30'),(8,'OWN-AE93490488E9F733',8,'NGW981234','LOCK','debit',100.00000000,100.00000000,100.00000000,100.00000000,0.00000000,0.00000000,100.00000000,0.00000000,0.00000000,NULL,'LCK-M-EE24E1517DCE4B9B',NULL,NULL,1,'own-lock:mandatory-lock:PUR-899B6BC6A2AF6FCC','{\"source\":\"coin_lock\",\"lock_ref\":\"LCK-M-EE24E1517DCE4B9B\"}','2026-11-17 14:31:30','2026-11-17 14:31:30'),(9,'OWN-FC78AC6778DC5F6F',9,'NGW981235','PURCHASE_CREDIT','credit',100.00000000,0.00000000,100.00000000,0.00000000,100.00000000,0.00000000,0.00000000,0.00000000,0.00000000,'PUR-84D66C36BB7C46AD',NULL,NULL,'INV-E5335F7CD81E01F1',1,'own:PUR-84D66C36BB7C46AD','{\"source\":\"approved_purchase\"}','2026-11-17 15:24:01','2026-11-17 15:24:01'),(10,'OWN-21997A3AF4537962',9,'NGW981235','LOCK','debit',100.00000000,100.00000000,100.00000000,100.00000000,0.00000000,0.00000000,100.00000000,0.00000000,0.00000000,NULL,'LCK-M-943F318036C8C378',NULL,NULL,1,'own-lock:mandatory-lock:PUR-84D66C36BB7C46AD','{\"source\":\"coin_lock\",\"lock_ref\":\"LCK-M-943F318036C8C378\"}','2026-11-17 15:24:01','2026-11-17 15:24:01'),(11,'OWN-79E6E59613A0FDA3',10,'NGW981236','PURCHASE_CREDIT','credit',200.00000000,0.00000000,200.00000000,0.00000000,200.00000000,0.00000000,0.00000000,0.00000000,0.00000000,'PUR-34049F062590516B',NULL,NULL,'INV-B56E65CC0BF3FE19',1,'own:PUR-34049F062590516B','{\"source\":\"approved_purchase\"}','2026-11-17 16:35:24','2026-11-17 16:35:24'),(12,'OWN-4B576D09ED563537',10,'NGW981236','LOCK','debit',200.00000000,200.00000000,200.00000000,200.00000000,0.00000000,0.00000000,200.00000000,0.00000000,0.00000000,NULL,'LCK-M-BD51E60CF601C9FE',NULL,NULL,1,'own-lock:mandatory-lock:PUR-34049F062590516B','{\"source\":\"coin_lock\",\"lock_ref\":\"LCK-M-BD51E60CF601C9FE\"}','2026-11-17 16:35:24','2026-11-17 16:35:24'),(13,'OWN-42CB4E90AE7232CB',11,'NGW981237','PURCHASE_CREDIT','credit',100.00000000,0.00000000,100.00000000,0.00000000,100.00000000,0.00000000,0.00000000,0.00000000,0.00000000,'PUR-1B4480DAD069E5B3',NULL,NULL,'INV-AE1084832360AD7D',1,'own:PUR-1B4480DAD069E5B3','{\"source\":\"approved_purchase\"}','2026-11-17 16:50:54','2026-11-17 16:50:54'),(14,'OWN-26FA78B54725BE7C',11,'NGW981237','LOCK','debit',100.00000000,100.00000000,100.00000000,100.00000000,0.00000000,0.00000000,100.00000000,0.00000000,0.00000000,NULL,'LCK-M-2DEE923F3148DA1B',NULL,NULL,1,'own-lock:mandatory-lock:PUR-1B4480DAD069E5B3','{\"source\":\"coin_lock\",\"lock_ref\":\"LCK-M-2DEE923F3148DA1B\"}','2026-11-17 16:50:54','2026-11-17 16:50:54'),(15,'OWN-9D5A9CE5993BB528',12,'NGW981238','PURCHASE_CREDIT','credit',500.00000000,0.00000000,500.00000000,0.00000000,500.00000000,0.00000000,0.00000000,0.00000000,0.00000000,'PUR-21037EBC049498EA',NULL,NULL,'INV-AF54016B7908F9B0',1,'own:PUR-21037EBC049498EA','{\"source\":\"approved_purchase\"}','2026-11-17 21:25:49','2026-11-17 21:25:49'),(16,'OWN-3F4F82FF5217D256',12,'NGW981238','LOCK','debit',500.00000000,500.00000000,500.00000000,500.00000000,0.00000000,0.00000000,500.00000000,0.00000000,0.00000000,NULL,'LCK-M-9843C201C8AF7DC3',NULL,NULL,1,'own-lock:mandatory-lock:PUR-21037EBC049498EA','{\"source\":\"coin_lock\",\"lock_ref\":\"LCK-M-9843C201C8AF7DC3\"}','2026-11-17 21:25:49','2026-11-17 21:25:49'),(17,'OWN-DCB6EB71A13FC99F',13,'NGW981239','PURCHASE_CREDIT','credit',100.00000000,0.00000000,100.00000000,0.00000000,100.00000000,0.00000000,0.00000000,0.00000000,0.00000000,'PUR-C69C97B26EDF9546',NULL,NULL,'INV-81B7B6F898461686',1,'own:PUR-C69C97B26EDF9546','{\"source\":\"approved_purchase\"}','2026-11-17 21:39:11','2026-11-17 21:39:11'),(18,'OWN-E7D153CF2CEDBD5C',13,'NGW981239','LOCK','debit',100.00000000,100.00000000,100.00000000,100.00000000,0.00000000,0.00000000,100.00000000,0.00000000,0.00000000,NULL,'LCK-M-699C4E16ED019130',NULL,NULL,1,'own-lock:mandatory-lock:PUR-C69C97B26EDF9546','{\"source\":\"coin_lock\",\"lock_ref\":\"LCK-M-699C4E16ED019130\"}','2026-11-17 21:39:11','2026-11-17 21:39:11');
/*!40000 ALTER TABLE `member_coin_ownership_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_email_change_tokens`
--

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
-- Dumping data for table `member_email_change_tokens`
--

LOCK TABLES `member_email_change_tokens` WRITE;
/*!40000 ALTER TABLE `member_email_change_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_email_change_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_id_sequences`
--

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
-- Dumping data for table `member_id_sequences`
--

LOCK TABLES `member_id_sequences` WRITE;
/*!40000 ALTER TABLE `member_id_sequences` DISABLE KEYS */;
INSERT INTO `member_id_sequences` VALUES (1,'NGW',981239,'2026-11-16 09:17:13','2026-11-17 21:35:36');
/*!40000 ALTER TABLE `member_id_sequences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_profiles`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `member_profiles`
--

LOCK TABLES `member_profiles` WRITE;
/*!40000 ALTER TABLE `member_profiles` DISABLE KEYS */;
INSERT INTO `member_profiles` VALUES (1,1,'ROOT0001','Platform Admin','0000000000',NULL,'0x0000000000000000000000000000000000000001',NULL,NULL,'active',NULL,NULL,1,'2026-11-12 07:05:07','2026-11-16 09:17:13'),(5,5,'NGW000001','test  from developer','6297616918',NULL,'nfinifeiienineinrie',NULL,NULL,'inactive',NULL,NULL,0,'2026-11-16 12:51:40','2026-11-16 12:51:40'),(6,6,'NGW000002','NGW2','8372802941',NULL,'0xe8c3DD3Bf04B72733fc71801467692267770304c',NULL,NULL,'active','2026-11-17 10:40:37','PUR-CE1D2C1ECD7A801A',0,'2026-11-17 10:26:13','2026-11-17 10:40:37'),(7,7,'NGW000003','AllaminTarafdar','7407914579',NULL,'0x5FACe9dBAFd074E1De7Ea1FE744dA9D75db9E44F',NULL,NULL,'active','2026-11-17 12:41:05','PUR-DA91F7DA0F5DD26C',0,'2026-11-17 12:02:08','2026-11-17 12:41:05'),(8,8,'NGW981234','Mahibulla Sardar ','7700068276',NULL,'0x2B44786717cc11c3CC576EFCB2E2d154ACb239d1',NULL,NULL,'active','2026-11-17 14:31:30','PUR-899B6BC6A2AF6FCC',0,'2026-11-17 14:23:57','2026-11-17 14:31:30'),(9,9,'NGW981235','SADIKUR RAHAMAN','7076580160',NULL,'0xD5890a2E0f1CBb8CCB6Ae75Ecc94767d77b1b16E',NULL,NULL,'active','2026-11-17 15:24:01','PUR-84D66C36BB7C46AD',0,'2026-11-17 15:15:34','2026-11-17 15:24:01'),(10,10,'NGW981236','SADIKUR RAHAMAN2','6295292533',NULL,'0xD5890a2E0f1CBb8CCB6Ae75Ecc94767d77b1b16E',NULL,NULL,'active','2026-11-17 16:35:24','PUR-34049F062590516B',0,'2026-11-17 16:32:04','2026-11-17 16:35:24'),(11,11,'NGW981237','Kalam Gazi','9749103011',NULL,'0x76C728b0343805C7871C73A86afABc5257e49F45',NULL,NULL,'active','2026-11-17 16:50:54','PUR-1B4480DAD069E5B3',0,'2026-11-17 16:45:30','2026-11-17 16:50:54'),(12,12,'NGW981238','Afridi Enterprise','9732900052',NULL,'0x9c065a5BD8744aA4ac0e76A6fBa80097985CD070',NULL,NULL,'active','2026-11-17 21:25:49','PUR-21037EBC049498EA',0,'2026-11-17 19:02:32','2026-11-17 21:25:49'),(13,13,'NGW981239','Salauddin Molla','8972341383',NULL,'0x89adC70cC79EC583f075A21Fa63f4fB690b846F1',NULL,NULL,'active','2026-11-17 21:39:11','PUR-C69C97B26EDF9546',0,'2026-11-17 21:35:36','2026-11-17 21:39:11');
/*!40000 ALTER TABLE `member_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_wallet_change_events`
--

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
-- Dumping data for table `member_wallet_change_events`
--

LOCK TABLES `member_wallet_change_events` WRITE;
/*!40000 ALTER TABLE `member_wallet_change_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_wallet_change_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

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
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2020-12-28-223112','CodeIgniter\\Shield\\Database\\Migrations\\CreateAuthTables','default','CodeIgniter\\Shield',1794467105,1),(2,'2021-07-04-041948','CodeIgniter\\Settings\\Database\\Migrations\\CreateSettingsTable','default','CodeIgniter\\Settings',1794467105,1),(3,'2021-11-14-143905','CodeIgniter\\Settings\\Database\\Migrations\\AddContextColumn','default','CodeIgniter\\Settings',1794467105,1),(4,'2026-07-20-181246','CodeIgniter\\Settings\\Database\\Migrations\\ConvertSqlsrvValueColumn','default','CodeIgniter\\Settings',1794467105,1),(5,'2026-11-12-000001','App\\Database\\Migrations\\CreateMemberIdSequences','default','App',1794467105,1),(6,'2026-11-12-000002','App\\Database\\Migrations\\CreateMemberProfiles','default','App',1794467105,1),(7,'2026-11-12-000003','App\\Database\\Migrations\\CreateSponsorRelationships','default','App',1794467105,1),(8,'2026-11-12-000004','App\\Database\\Migrations\\CreateAuditLogs','default','App',1794467105,1),(9,'2026-11-12-000005','App\\Database\\Migrations\\CreatePasswordResetTokens','default','App',1794467106,1),(10,'2026-11-12-100001','App\\Database\\Migrations\\CreateCoinSettings','default','App',1794468673,2),(11,'2026-11-12-100002','App\\Database\\Migrations\\CreateCoinInventory','default','App',1794468673,2),(12,'2026-11-12-100003','App\\Database\\Migrations\\CreateCoinInventoryTransactions','default','App',1794468673,2),(13,'2026-11-12-100004','App\\Database\\Migrations\\CreateCoinPriceHistory','default','App',1794468673,2),(14,'2026-11-12-110001','App\\Database\\Migrations\\WidenUsdtWalletColumn','default','App',1794469605,3),(15,'2026-11-13-200001','App\\Database\\Migrations\\CreateCoinPurchaseRequests','default','App',1794544281,4),(16,'2026-11-13-200002','App\\Database\\Migrations\\CreateMemberCoinBalances','default','App',1794544281,4),(17,'2026-11-13-200003','App\\Database\\Migrations\\CreateMemberCoinOwnershipTransactions','default','App',1794544281,4),(18,'2026-11-13-300001','App\\Database\\Migrations\\CreateUsdtPaymentNetworks','default','App',1794545331,5),(19,'2026-11-13-300002','App\\Database\\Migrations\\CreateUsdtDepositRequests','default','App',1794545332,5),(20,'2026-11-13-300003','App\\Database\\Migrations\\CreateUsdtDepositEvents','default','App',1794545332,5),(21,'2026-11-13-300004','App\\Database\\Migrations\\AddPurchasePaymentSafetyColumns','default','App',1794545332,5),(22,'2026-11-13-400001','App\\Database\\Migrations\\ExtendUsdtNetworksForEvm','default','App',1794546483,6),(23,'2026-11-13-400002','App\\Database\\Migrations\\ExtendUsdtDepositsVerificationFields','default','App',1794546483,6),(24,'2026-11-13-400003','App\\Database\\Migrations\\CreateUsdtPaymentAllocations','default','App',1794546483,6),(25,'2026-11-13-500001','App\\Database\\Migrations\\ExtendDepositsForPaymentOps','default','App',1794549177,7),(26,'2026-11-13-500002','App\\Database\\Migrations\\CreateBlockchainHealthSnapshots','default','App',1794549177,7),(27,'2026-11-13-600001','App\\Database\\Migrations\\ExtendAllocationsForLifecycle','default','App',1794550752,8),(28,'2026-11-13-600002','App\\Database\\Migrations\\CreateAllocationEventsAndFinanceOps','default','App',1794550753,8),(29,'2026-11-13-700001','App\\Database\\Migrations\\CreateNotificationOutboxAndRemediationExtras','default','App',1794553791,9),(30,'2026-11-13-800001','App\\Database\\Migrations\\CreateCoinLockingAndBalanceSplit','default','App',1794555864,10),(31,'2026-11-16-900001','App\\Database\\Migrations\\CreateMiningSimulationTables','default','App',1794903745,11),(32,'2026-11-16-900002','App\\Database\\Migrations\\CreateCommissionSimulationTables','default','App',1794903745,11),(33,'2026-11-17-900001','App\\Database\\Migrations\\CreateCommissionWalletAndWithdrawalTables','default','App',1794903746,11),(34,'2026-11-17-900002','App\\Database\\Migrations\\Phase8ActivationAndPaymentSettings','default','App',1794903746,11),(35,'2026-11-17-910001','App\\Database\\Migrations\\PaymentQrAndDisplayConfig','default','App',1794903746,11),(36,'2026-11-17-920001','App\\Database\\Migrations\\PaymentDetailsDisplayFields','default','App',1794903746,11),(37,'2026-11-17-930001','App\\Database\\Migrations\\CreatePlatformSettingsAndSupportTickets','default','App',1794903747,11),(38,'2026-11-17-940001','App\\Database\\Migrations\\MemberProfileEmailWalletSecurity','default','App',1794903747,11),(39,'2026-11-17-950001','App\\Database\\Migrations\\Phase11AAutoLockAndMiningWallet','default','App',1794912012,12),(40,'2026-11-17-960001','App\\Database\\Migrations\\Phase10DAdminImpersonation','default','App',1794912012,12),(41,'2026-11-17-970001','App\\Database\\Migrations\\Phase11BCommissionPurchaseCalculations','default','App',1794912012,12),(42,'2026-11-17-980001','App\\Database\\Migrations\\Phase11CMiningP2pTransfers','default','App',1794912012,12),(43,'2026-11-17-990001','App\\Database\\Migrations\\Phase11DCommissionWithdrawalPayout','default','App',1794912012,12),(44,'2026-11-17-991001','App\\Database\\Migrations\\Phase11ECoinSelling','default','App',1794918647,13),(45,'2026-11-17-992001','App\\Database\\Migrations\\SyncMemberIdSequenceStart','default','App',1794918647,13);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mining_daily_calculations`
--

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
-- Dumping data for table `mining_daily_calculations`
--

LOCK TABLES `mining_daily_calculations` WRITE;
/*!40000 ALTER TABLE `mining_daily_calculations` DISABLE KEYS */;
/*!40000 ALTER TABLE `mining_daily_calculations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mining_daily_runs`
--

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
-- Dumping data for table `mining_daily_runs`
--

LOCK TABLES `mining_daily_runs` WRITE;
/*!40000 ALTER TABLE `mining_daily_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `mining_daily_runs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mining_eligibility_audit_events`
--

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mining_eligibility_audit_events`
--

LOCK TABLES `mining_eligibility_audit_events` WRITE;
/*!40000 ALTER TABLE `mining_eligibility_audit_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `mining_eligibility_audit_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mining_p2p_transfers`
--

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
-- Dumping data for table `mining_p2p_transfers`
--

LOCK TABLES `mining_p2p_transfers` WRITE;
/*!40000 ALTER TABLE `mining_p2p_transfers` DISABLE KEYS */;
/*!40000 ALTER TABLE `mining_p2p_transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mining_rate_approval_events`
--

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mining_rate_approval_events`
--

LOCK TABLES `mining_rate_approval_events` WRITE;
/*!40000 ALTER TABLE `mining_rate_approval_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `mining_rate_approval_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mining_rate_configs`
--

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mining_rate_configs`
--

LOCK TABLES `mining_rate_configs` WRITE;
/*!40000 ALTER TABLE `mining_rate_configs` DISABLE KEYS */;
/*!40000 ALTER TABLE `mining_rate_configs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mining_simulation_items`
--

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mining_simulation_items`
--

LOCK TABLES `mining_simulation_items` WRITE;
/*!40000 ALTER TABLE `mining_simulation_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `mining_simulation_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mining_simulation_runs`
--

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mining_simulation_runs`
--

LOCK TABLES `mining_simulation_runs` WRITE;
/*!40000 ALTER TABLE `mining_simulation_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `mining_simulation_runs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mining_wallet_ledger`
--

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
-- Dumping data for table `mining_wallet_ledger`
--

LOCK TABLES `mining_wallet_ledger` WRITE;
/*!40000 ALTER TABLE `mining_wallet_ledger` DISABLE KEYS */;
/*!40000 ALTER TABLE `mining_wallet_ledger` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mining_wallets`
--

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
-- Dumping data for table `mining_wallets`
--

LOCK TABLES `mining_wallets` WRITE;
/*!40000 ALTER TABLE `mining_wallets` DISABLE KEYS */;
/*!40000 ALTER TABLE `mining_wallets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_delivery_events`
--

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
-- Dumping data for table `notification_delivery_events`
--

LOCK TABLES `notification_delivery_events` WRITE;
/*!40000 ALTER TABLE `notification_delivery_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_delivery_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_outbox`
--

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
-- Dumping data for table `notification_outbox`
--

LOCK TABLES `notification_outbox` WRITE;
/*!40000 ALTER TABLE `notification_outbox` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_outbox` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ops_job_locks`
--

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
-- Dumping data for table `ops_job_locks`
--

LOCK TABLES `ops_job_locks` WRITE;
/*!40000 ALTER TABLE `ops_job_locks` DISABLE KEYS */;
INSERT INTO `ops_job_locks` VALUES (1,'coins_check_maturity',NULL,NULL,NULL,'2026-11-16 18:00:51','2026-11-17 12:28:46');
/*!40000 ALTER TABLE `ops_job_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

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
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_destination_change_events`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_destination_change_events`
--

LOCK TABLES `payment_destination_change_events` WRITE;
/*!40000 ALTER TABLE `payment_destination_change_events` DISABLE KEYS */;
INSERT INTO `payment_destination_change_events` VALUES (1,'PDC-20261117-CAFB17E0','proposed',1,NULL,'pending','{\"to\":\"0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E\",\"qr_mode\":\"upload\"}','2026-11-17 12:15:01'),(2,'PDC-20261117-CAFB17E0','rejected',1,'pending','rejected','{\"reason\":\"Rejected by finance approver.\"}','2026-11-17 12:16:14'),(3,'PDC-20261117-4431121C','proposed',1,NULL,'pending','{\"to\":\"0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E\",\"qr_mode\":\"keep\"}','2026-11-17 12:17:04'),(4,'PDC-20261117-4431121C','approved',1,'pending','approved',NULL,'2026-11-17 12:31:28'),(5,'PDC-20261117-4366779A','proposed',1,NULL,'pending','{\"to\":\"0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E\",\"qr_mode\":\"upload\"}','2026-11-17 12:32:37'),(6,'PDC-20261117-4366779A','approved',1,'pending','approved',NULL,'2026-11-17 12:32:51');
/*!40000 ALTER TABLE `payment_destination_change_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_destination_change_proposals`
--

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
  `display_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `proposed_by` int(10) unsigned NOT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` int(10) unsigned DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `min_deposit_usdt` decimal(24,8) DEFAULT NULL,
  `max_deposit_usdt` decimal(24,8) DEFAULT NULL,
  `qr_mode` varchar(16) NOT NULL DEFAULT 'keep',
  `qr_staging_stored_name` varchar(120) DEFAULT NULL,
  `qr_staging_mime` varchar(64) DEFAULT NULL,
  `qr_decoded_payload` varchar(512) DEFAULT NULL,
  `currency_code` varchar(16) NOT NULL DEFAULT 'USDT',
  `recipient_name` varchar(120) DEFAULT NULL,
  `token_contract_address` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proposal_ref` (`proposal_ref`),
  KEY `network_id_status` (`network_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_destination_change_proposals`
--

LOCK TABLES `payment_destination_change_proposals` WRITE;
/*!40000 ALTER TABLE `payment_destination_change_proposals` DISABLE KEYS */;
INSERT INTO `payment_destination_change_proposals` VALUES (1,'PDC-20261117-CAFB17E0',2,'bep20','0xe8c3DD3Bf04B72733fc71801467692267770304c','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E',NULL,1,'rejected',1,NULL,NULL,1,'2026-11-17 12:16:14','Rejected by finance approver.','2026-11-17 12:15:01','2026-11-17 12:16:14',5.00000000,NULL,'upload','qr_stage_038404987e71ec597c8cde776d57806d.jpg','image/jpeg',NULL,'USDT','NGW','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E'),(2,'PDC-20261117-4431121C',2,'bep20','0xe8c3DD3Bf04B72733fc71801467692267770304c','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E',NULL,1,'approved',1,1,'2026-11-17 12:31:28',NULL,NULL,NULL,'2026-11-17 12:17:04','2026-11-17 12:31:28',5.00000000,NULL,'keep',NULL,NULL,NULL,'USDT','NGW','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E'),(3,'PDC-20261117-4366779A',2,'bep20','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E',NULL,1,'approved',1,1,'2026-11-17 12:32:51',NULL,NULL,NULL,'2026-11-17 12:32:37','2026-11-17 12:32:51',5.00000000,NULL,'upload','qr_stage_4b4c3c657790f0a5555c4e488fa80547.jpg','image/jpeg',NULL,'USDT','NGW','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E');
/*!40000 ALTER TABLE `payment_destination_change_proposals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `platform_settings`
--

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
-- Dumping data for table `platform_settings`
--

LOCK TABLES `platform_settings` WRITE;
/*!40000 ALTER TABLE `platform_settings` DISABLE KEYS */;
INSERT INTO `platform_settings` VALUES (1,'platform_name','Next Generation Wealth',NULL,'2026-11-17 08:22:27','2026-11-17 08:22:27'),(2,'platform_logo','',NULL,'2026-11-17 08:22:27','2026-11-17 08:22:27'),(3,'contact_email','',NULL,'2026-11-17 08:22:27','2026-11-17 08:22:27'),(4,'support_contact','',NULL,'2026-11-17 08:22:27','2026-11-17 08:22:27'),(5,'timezone','UTC',NULL,'2026-11-17 08:22:27','2026-11-17 08:22:27'),(6,'date_format','Y-m-d H:i',NULL,'2026-11-17 08:22:27','2026-11-17 08:22:27'),(7,'registration_enabled','1',NULL,'2026-11-17 08:22:27','2026-11-17 08:22:27'),(8,'sponsor_required','1',NULL,'2026-11-17 08:22:27','2026-11-17 08:22:27'),(9,'registration_instructions','',NULL,'2026-11-17 08:22:27','2026-11-17 08:22:27'),(10,'notify_admin_alerts','1',NULL,'2026-11-17 08:22:27','2026-11-17 08:22:27'),(11,'notify_member_events','0',NULL,'2026-11-17 08:22:27','2026-11-17 08:22:27'),(12,'notify_payment_submission','1',NULL,'2026-11-17 08:22:27','2026-11-17 08:22:27'),(13,'notify_purchase_approval','1',NULL,'2026-11-17 08:22:27','2026-11-17 08:22:27');
/*!40000 ALTER TABLE `platform_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_sponsor_snapshots`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_sponsor_snapshots`
--

LOCK TABLES `purchase_sponsor_snapshots` WRITE;
/*!40000 ALTER TABLE `purchase_sponsor_snapshots` DISABLE KEYS */;
INSERT INTO `purchase_sponsor_snapshots` VALUES (7,1,'PUR-CE1D2C1ECD7A801A',6,'NGW000002',1,1,'ROOT0001',2,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 10:40:37','2026-11-17 10:40:37'),(8,1,'PUR-CE1D2C1ECD7A801A',6,'NGW000002',2,NULL,NULL,NULL,1,'CAPTURED','purchase_approval','[\"missing_level_2\"]','2026-11-17 10:40:37','2026-11-17 10:40:37'),(9,1,'PUR-CE1D2C1ECD7A801A',6,'NGW000002',3,NULL,NULL,NULL,1,'CAPTURED','purchase_approval','[\"missing_level_3\"]','2026-11-17 10:40:37','2026-11-17 10:40:37'),(10,4,'PUR-DA91F7DA0F5DD26C',7,'NGW000003',1,6,'NGW000002',3,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 12:41:05','2026-11-17 12:41:05'),(11,4,'PUR-DA91F7DA0F5DD26C',7,'NGW000003',2,1,'ROOT0001',2,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 12:41:05','2026-11-17 12:41:05'),(12,4,'PUR-DA91F7DA0F5DD26C',7,'NGW000003',3,NULL,NULL,NULL,1,'CAPTURED','purchase_approval','[\"missing_level_3\"]','2026-11-17 12:41:05','2026-11-17 12:41:05'),(13,5,'PUR-899B6BC6A2AF6FCC',8,'NGW981234',1,7,'NGW000003',4,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 14:31:30','2026-11-17 14:31:30'),(14,5,'PUR-899B6BC6A2AF6FCC',8,'NGW981234',2,6,'NGW000002',3,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 14:31:30','2026-11-17 14:31:30'),(15,5,'PUR-899B6BC6A2AF6FCC',8,'NGW981234',3,1,'ROOT0001',2,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 14:31:30','2026-11-17 14:31:30'),(16,6,'PUR-84D66C36BB7C46AD',9,'NGW981235',1,7,'NGW000003',5,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 15:24:01','2026-11-17 15:24:01'),(17,6,'PUR-84D66C36BB7C46AD',9,'NGW981235',2,6,'NGW000002',3,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 15:24:01','2026-11-17 15:24:01'),(18,6,'PUR-84D66C36BB7C46AD',9,'NGW981235',3,1,'ROOT0001',2,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 15:24:01','2026-11-17 15:24:01'),(19,7,'PUR-34049F062590516B',10,'NGW981236',1,9,'NGW981235',6,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 16:35:24','2026-11-17 16:35:24'),(20,7,'PUR-34049F062590516B',10,'NGW981236',2,7,'NGW000003',5,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 16:35:24','2026-11-17 16:35:24'),(21,7,'PUR-34049F062590516B',10,'NGW981236',3,6,'NGW000002',3,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 16:35:24','2026-11-17 16:35:24'),(22,8,'PUR-1B4480DAD069E5B3',11,'NGW981237',1,10,'NGW981236',7,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 16:50:54','2026-11-17 16:50:54'),(23,8,'PUR-1B4480DAD069E5B3',11,'NGW981237',2,9,'NGW981235',6,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 16:50:54','2026-11-17 16:50:54'),(24,8,'PUR-1B4480DAD069E5B3',11,'NGW981237',3,7,'NGW000003',5,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 16:50:54','2026-11-17 16:50:54'),(25,10,'PUR-21037EBC049498EA',12,'NGW981238',1,10,'NGW981236',8,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 21:25:49','2026-11-17 21:25:49'),(26,10,'PUR-21037EBC049498EA',12,'NGW981238',2,9,'NGW981235',6,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 21:25:49','2026-11-17 21:25:49'),(27,10,'PUR-21037EBC049498EA',12,'NGW981238',3,7,'NGW000003',5,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 21:25:49','2026-11-17 21:25:49'),(28,11,'PUR-C69C97B26EDF9546',13,'NGW981239',1,12,'NGW981238',9,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 21:39:11','2026-11-17 21:39:11'),(29,11,'PUR-C69C97B26EDF9546',13,'NGW981239',2,10,'NGW981236',8,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 21:39:11','2026-11-17 21:39:11'),(30,11,'PUR-C69C97B26EDF9546',13,'NGW981239',3,9,'NGW981235',6,0,'CAPTURED','purchase_approval',NULL,'2026-11-17 21:39:11','2026-11-17 21:39:11');
/*!40000 ALTER TABLE `purchase_sponsor_snapshots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

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
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings_audit`
--

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
-- Dumping data for table `settings_audit`
--

LOCK TABLES `settings_audit` WRITE;
/*!40000 ALTER TABLE `settings_audit` DISABLE KEYS */;
/*!40000 ALTER TABLE `settings_audit` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sponsor_relationships`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sponsor_relationships`
--

LOCK TABLES `sponsor_relationships` WRITE;
/*!40000 ALTER TABLE `sponsor_relationships` DISABLE KEYS */;
INSERT INTO `sponsor_relationships` VALUES (1,5,1,'ROOT0001','2026-11-16 12:51:40','2026-11-16 12:51:40'),(2,6,1,'ROOT0001','2026-11-17 10:26:13','2026-11-17 10:26:13'),(3,7,6,'NGW000002','2026-11-17 12:02:08','2026-11-17 12:02:08'),(4,8,7,'NGW000003','2026-11-17 14:23:57','2026-11-17 14:23:57'),(5,9,7,'NGW000003','2026-11-17 15:15:34','2026-11-17 15:15:34'),(6,10,9,'NGW981235','2026-11-17 16:32:04','2026-11-17 16:32:04'),(7,11,10,'NGW981236','2026-11-17 16:45:30','2026-11-17 16:45:30'),(8,12,10,'NGW981236','2026-11-17 19:02:32','2026-11-17 19:02:32'),(9,13,12,'NGW981238','2026-11-17 21:35:36','2026-11-17 21:35:36');
/*!40000 ALTER TABLE `sponsor_relationships` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_ticket_messages`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_ticket_messages`
--

LOCK TABLES `support_ticket_messages` WRITE;
/*!40000 ALTER TABLE `support_ticket_messages` DISABLE KEYS */;
INSERT INTO `support_ticket_messages` VALUES (1,1,'SUP-866C3757E5','member',5,'ghghghg','2026-11-17 10:04:32');
/*!40000 ALTER TABLE `support_ticket_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_tickets`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_tickets`
--

LOCK TABLES `support_tickets` WRITE;
/*!40000 ALTER TABLE `support_tickets` DISABLE KEYS */;
INSERT INTO `support_tickets` VALUES (1,'SUP-866C3757E5',5,'NGW000001','wallet','dfdfd','open','2026-11-17 10:04:32','member',NULL,NULL,'2026-11-17 10:04:32','2026-11-17 10:04:32');
/*!40000 ALTER TABLE `support_tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usdt_deposit_events`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usdt_deposit_events`
--

LOCK TABLES `usdt_deposit_events` WRITE;
/*!40000 ALTER TABLE `usdt_deposit_events` DISABLE KEYS */;
INSERT INTO `usdt_deposit_events` VALUES (1,1,'DEP-F2C593D936A6830C',6,'deposit.submitted',NULL,'submitted','{\"network_key\":\"bep20\",\"amount\":\"5.00000000\",\"has_evidence\":false}','2026-11-17 10:34:33'),(2,1,'DEP-F2C593D936A6830C',1,'deposit.verified_manual','submitted','verified','{\"method\":\"MANUAL\",\"amount\":\"5.00000000\",\"confirmations\":15,\"wallet\":\"0xe8c3DD3Bf04B72733fc71801467692267770304c\"}','2026-11-17 10:37:15'),(3,2,'DEP-0FD4804DC7E76307',7,'deposit.submitted',NULL,'submitted','{\"network_key\":\"bep20\",\"amount\":\"5.00000000\",\"has_evidence\":false}','2026-11-17 12:35:50'),(4,2,'DEP-0FD4804DC7E76307',1,'deposit.verified_manual','submitted','verified','{\"method\":\"MANUAL\",\"amount\":\"5.00000000\",\"confirmations\":15,\"wallet\":\"0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E\"}','2026-11-17 12:40:49'),(5,3,'DEP-48BEC06030C4E190',8,'deposit.submitted',NULL,'submitted','{\"network_key\":\"bep20\",\"amount\":\"5.00000000\",\"has_evidence\":false}','2026-11-17 14:26:33'),(6,3,'DEP-48BEC06030C4E190',1,'deposit.verified_manual','submitted','verified','{\"method\":\"MANUAL\",\"amount\":\"5.00000000\",\"confirmations\":15,\"wallet\":\"0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E\"}','2026-11-17 14:31:13'),(7,4,'DEP-942A048112A938F3',9,'deposit.submitted',NULL,'submitted','{\"network_key\":\"bep20\",\"amount\":\"5.00000000\",\"has_evidence\":false}','2026-11-17 15:20:29'),(8,4,'DEP-942A048112A938F3',1,'deposit.verified_manual','submitted','verified','{\"method\":\"MANUAL\",\"amount\":\"5.00000000\",\"confirmations\":15,\"wallet\":\"0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E\"}','2026-11-17 15:23:36'),(9,5,'DEP-062356221DC3D2AB',10,'deposit.submitted',NULL,'submitted','{\"network_key\":\"bep20\",\"amount\":\"10.00000000\",\"has_evidence\":false}','2026-11-17 16:34:30'),(10,5,'DEP-062356221DC3D2AB',1,'deposit.verified_manual','submitted','verified','{\"method\":\"MANUAL\",\"amount\":\"10.00000000\",\"confirmations\":15,\"wallet\":\"0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E\"}','2026-11-17 16:35:17'),(11,6,'DEP-76FCB506397419BF',11,'deposit.submitted',NULL,'submitted','{\"network_key\":\"bep20\",\"amount\":\"5.00000000\",\"has_evidence\":false}','2026-11-17 16:50:04'),(12,6,'DEP-76FCB506397419BF',1,'deposit.verified_manual','submitted','verified','{\"method\":\"MANUAL\",\"amount\":\"5.00000000\",\"confirmations\":15,\"wallet\":\"0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E\"}','2026-11-17 16:50:47'),(13,7,'DEP-5BD53E8A2CDE2FA0',12,'deposit.submitted',NULL,'submitted','{\"network_key\":\"bep20\",\"amount\":\"25.00000000\",\"has_evidence\":false}','2026-11-17 21:24:16'),(14,7,'DEP-5BD53E8A2CDE2FA0',1,'deposit.verified_manual','submitted','verified','{\"method\":\"MANUAL\",\"amount\":\"25.00000000\",\"confirmations\":15,\"wallet\":\"0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E\"}','2026-11-17 21:25:41'),(15,8,'DEP-05C2A521C4AB8CFB',13,'deposit.submitted',NULL,'submitted','{\"network_key\":\"bep20\",\"amount\":\"5.00000000\",\"has_evidence\":false}','2026-11-17 21:38:02'),(16,8,'DEP-05C2A521C4AB8CFB',1,'deposit.verified_manual','submitted','verified','{\"method\":\"MANUAL\",\"amount\":\"5.00000000\",\"confirmations\":15,\"wallet\":\"0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E\"}','2026-11-17 21:39:00');
/*!40000 ALTER TABLE `usdt_deposit_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usdt_deposit_requests`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usdt_deposit_requests`
--

LOCK TABLES `usdt_deposit_requests` WRITE;
/*!40000 ALTER TABLE `usdt_deposit_requests` DISABLE KEYS */;
INSERT INTO `usdt_deposit_requests` VALUES (1,'DEP-F2C593D936A6830C',6,'NGW000002',2,'bep20',5.00000000,'0xe8c3DD3Bf04B72733fc71801467692267770304c','0x51f0e66aae788c66237616fdd6c657b97aa32f4c1708b27b8b623e2ee9493f17','0x51f0e66aae788c66237616fdd6c657b97aa32f4c1708b27b8b623e2ee9493f17',NULL,NULL,NULL,NULL,'verified','MANUAL','succesiniiihihihihibjvyyvh',5.00000000,56,'0xe8c3DD3Bf04B72733fc71801467692267770304c','0xe8c3DD3Bf04B72733fc71801467692267770304c',NULL,NULL,15,'verified','MANUAL: succesiniiihihihihibjvyyvh','2026-11-17 10:37:15',0,NULL,'ok','2026-11-17 10:37:15',NULL,'2026-11-17 10:37:15',1,'MANUAL APPROVAL: succesiniiihihihihibjvyyvh',NULL,'2026-11-17 10:34:33','5dada990231a25f57ab2d1d8febb1336','PUR-CE1D2C1ECD7A801A','consumed','2026-11-17 10:40:37','2026-11-17 10:34:33','2026-11-17 10:40:37'),(2,'DEP-0FD4804DC7E76307',7,'NGW000003',2,'bep20',5.00000000,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0xdc0c15f093779de48383c674f272d8563e03183b52df43a16652031e40363468','0xdc0c15f093779de48383c674f272d8563e03183b52df43a16652031e40363468',NULL,NULL,NULL,NULL,'verified','MANUAL','payment success',5.00000000,56,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E',NULL,NULL,15,'verified','MANUAL: payment success','2026-11-17 12:40:49',0,NULL,'ok','2026-11-17 12:40:49',NULL,'2026-11-17 12:40:49',1,'MANUAL APPROVAL: payment success',NULL,'2026-11-17 12:35:50','42d485632ed621637177aff42f41cae1','PUR-DA91F7DA0F5DD26C','consumed','2026-11-17 12:41:05','2026-11-17 12:35:50','2026-11-17 12:41:05'),(3,'DEP-48BEC06030C4E190',8,'NGW981234',2,'bep20',5.00000000,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0xd8a1ce5f16222a88cd1766a8dd674f0572e4f7fe9025de61c75a1a4ff7764115','0xd8a1ce5f16222a88cd1766a8dd674f0572e4f7fe9025de61c75a1a4ff7764115',NULL,NULL,NULL,NULL,'verified','MANUAL','Payment successfully',5.00000000,56,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E',NULL,NULL,15,'verified','MANUAL: Payment successfully','2026-11-17 14:31:13',0,NULL,'ok','2026-11-17 14:31:13',NULL,'2026-11-17 14:31:13',1,'MANUAL APPROVAL: Payment successfully',NULL,'2026-11-17 14:26:33','9a63bb26bf3702d5e9716529b6b165b0','PUR-899B6BC6A2AF6FCC','consumed','2026-11-17 14:31:30','2026-11-17 14:26:33','2026-11-17 14:31:30'),(4,'DEP-942A048112A938F3',9,'NGW981235',2,'bep20',5.00000000,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0x62e2bc026c763c556083a0303c6883a9192b12a9bc709d09a3250fb091cfc0b7','0x62e2bc026c763c556083a0303c6883a9192b12a9bc709d09a3250fb091cfc0b7',NULL,NULL,NULL,NULL,'verified','MANUAL','Payment successfully',5.00000000,56,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E',NULL,NULL,15,'verified','MANUAL: Payment successfully','2026-11-17 15:23:36',0,NULL,'ok','2026-11-17 15:23:36',NULL,'2026-11-17 15:23:36',1,'MANUAL APPROVAL: Payment successfully',NULL,'2026-11-17 15:20:29','655b3ed42dfece3ba50d889b06886289','PUR-84D66C36BB7C46AD','consumed','2026-11-17 15:24:01','2026-11-17 15:20:29','2026-11-17 15:24:01'),(5,'DEP-062356221DC3D2AB',10,'NGW981236',2,'bep20',10.00000000,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0xa6d49151f3efc6e462b5499259671bc2193edeb522b9cd71a1379b9abfd547a2','0xa6d49151f3efc6e462b5499259671bc2193edeb522b9cd71a1379b9abfd547a2',NULL,NULL,NULL,NULL,'verified','MANUAL','Payment received',10.00000000,56,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E',NULL,NULL,15,'verified','MANUAL: Payment received','2026-11-17 16:35:17',0,NULL,'ok','2026-11-17 16:35:17',NULL,'2026-11-17 16:35:17',1,'MANUAL APPROVAL: Payment received',NULL,'2026-11-17 16:34:30','80b024cc7466875cd84148de4ab893ed','PUR-34049F062590516B','consumed','2026-11-17 16:35:24','2026-11-17 16:34:30','2026-11-17 16:35:24'),(6,'DEP-76FCB506397419BF',11,'NGW981237',2,'bep20',5.00000000,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0xab5dcfc44b5722cce7c4c0f4614c43f424fb6a7caf0704c5530355a5418b02db','0xab5dcfc44b5722cce7c4c0f4614c43f424fb6a7caf0704c5530355a5418b02db',NULL,NULL,NULL,NULL,'verified','MANUAL','Payment received',5.00000000,56,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E',NULL,NULL,15,'verified','MANUAL: Payment received','2026-11-17 16:50:47',0,NULL,'ok','2026-11-17 16:50:47',NULL,'2026-11-17 16:50:47',1,'MANUAL APPROVAL: Payment received',NULL,'2026-11-17 16:50:04','65926f95b609db7ff8de585981284182','PUR-1B4480DAD069E5B3','consumed','2026-11-17 16:50:53','2026-11-17 16:50:04','2026-11-17 16:50:53'),(7,'DEP-5BD53E8A2CDE2FA0',12,'NGW981238',2,'bep20',25.00000000,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0xedeeca7715c07272c6576a1c2c2262957fbfda2ee902232632fc12ff5cb2ce53','0xedeeca7715c07272c6576a1c2c2262957fbfda2ee902232632fc12ff5cb2ce53',NULL,NULL,NULL,NULL,'verified','MANUAL','Payment successfully received',25.00000000,56,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E',NULL,NULL,15,'verified','MANUAL: Payment successfully received','2026-11-17 21:25:41',0,NULL,'ok','2026-11-17 21:25:41',NULL,'2026-11-17 21:25:41',1,'MANUAL APPROVAL: Payment successfully received',NULL,'2026-11-17 21:24:16','778beaf93ce87ba33e1c3e76522e2b36','PUR-21037EBC049498EA','consumed','2026-11-17 21:25:49','2026-11-17 21:24:16','2026-11-17 21:25:49'),(8,'DEP-05C2A521C4AB8CFB',13,'NGW981239',2,'bep20',5.00000000,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0x26ca94a518bc28e5da9dfd149ce3f03fb9971fe11e73e40c346862c3825c9c9e','0x26ca94a518bc28e5da9dfd149ce3f03fb9971fe11e73e40c346862c3825c9c9e',NULL,NULL,NULL,NULL,'verified','MANUAL','Payment received',5.00000000,56,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E',NULL,NULL,15,'verified','MANUAL: Payment received','2026-11-17 21:39:00',0,NULL,'ok','2026-11-17 21:39:00',NULL,'2026-11-17 21:39:00',1,'MANUAL APPROVAL: Payment received',NULL,'2026-11-17 21:38:02','52a1a88ddceef8ab01dd3e3c2113ef9f','PUR-C69C97B26EDF9546','consumed','2026-11-17 21:39:11','2026-11-17 21:38:02','2026-11-17 21:39:11');
/*!40000 ALTER TABLE `usdt_deposit_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usdt_payment_allocation_events`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usdt_payment_allocation_events`
--

LOCK TABLES `usdt_payment_allocation_events` WRITE;
/*!40000 ALTER TABLE `usdt_payment_allocation_events` DISABLE KEYS */;
INSERT INTO `usdt_payment_allocation_events` VALUES (1,'PAY-7C5BFC6B999140BA','DEP-F2C593D936A6830C','PUR-CE1D2C1ECD7A801A',6,'allocation.reserved',NULL,'linked','{\"amount\":\"5.00000000\"}','2026-11-17 10:37:15'),(4,'PAY-7C5BFC6B999140BA','DEP-F2C593D936A6830C','PUR-CE1D2C1ECD7A801A',1,'allocation.consumed','linked','consumed','[]','2026-11-17 10:40:37'),(5,'PAY-DBFFEDE03DF84609','DEP-0FD4804DC7E76307','PUR-DA91F7DA0F5DD26C',7,'allocation.reserved',NULL,'linked','{\"amount\":\"5.00000000\"}','2026-11-17 12:40:49'),(6,'PAY-DBFFEDE03DF84609','DEP-0FD4804DC7E76307','PUR-DA91F7DA0F5DD26C',1,'allocation.consumed','linked','consumed','[]','2026-11-17 12:41:05'),(7,'PAY-1254070929B0609F','DEP-48BEC06030C4E190','PUR-899B6BC6A2AF6FCC',8,'allocation.reserved',NULL,'linked','{\"amount\":\"5.00000000\"}','2026-11-17 14:31:13'),(8,'PAY-1254070929B0609F','DEP-48BEC06030C4E190','PUR-899B6BC6A2AF6FCC',1,'allocation.consumed','linked','consumed','[]','2026-11-17 14:31:30'),(9,'PAY-B8C91A2A88B6CAC5','DEP-942A048112A938F3','PUR-84D66C36BB7C46AD',9,'allocation.reserved',NULL,'linked','{\"amount\":\"5.00000000\"}','2026-11-17 15:23:36'),(10,'PAY-B8C91A2A88B6CAC5','DEP-942A048112A938F3','PUR-84D66C36BB7C46AD',1,'allocation.consumed','linked','consumed','[]','2026-11-17 15:24:01'),(11,'PAY-17CBE05A27C9D73F','DEP-062356221DC3D2AB','PUR-34049F062590516B',10,'allocation.reserved',NULL,'linked','{\"amount\":\"10.00000000\"}','2026-11-17 16:35:17'),(12,'PAY-17CBE05A27C9D73F','DEP-062356221DC3D2AB','PUR-34049F062590516B',1,'allocation.consumed','linked','consumed','[]','2026-11-17 16:35:24'),(13,'PAY-B8A5ED436804BDEA','DEP-76FCB506397419BF','PUR-1B4480DAD069E5B3',11,'allocation.reserved',NULL,'linked','{\"amount\":\"5.00000000\"}','2026-11-17 16:50:47'),(14,'PAY-B8A5ED436804BDEA','DEP-76FCB506397419BF','PUR-1B4480DAD069E5B3',1,'allocation.consumed','linked','consumed','[]','2026-11-17 16:50:53'),(15,'PAY-23582F191462F215','DEP-5BD53E8A2CDE2FA0','PUR-21037EBC049498EA',12,'allocation.reserved',NULL,'linked','{\"amount\":\"25.00000000\"}','2026-11-17 21:25:41'),(16,'PAY-23582F191462F215','DEP-5BD53E8A2CDE2FA0','PUR-21037EBC049498EA',1,'allocation.consumed','linked','consumed','[]','2026-11-17 21:25:49'),(17,'PAY-527BBD258AC74AAE','DEP-05C2A521C4AB8CFB','PUR-C69C97B26EDF9546',13,'allocation.reserved',NULL,'linked','{\"amount\":\"5.00000000\"}','2026-11-17 21:39:00'),(18,'PAY-527BBD258AC74AAE','DEP-05C2A521C4AB8CFB','PUR-C69C97B26EDF9546',1,'allocation.consumed','linked','consumed','[]','2026-11-17 21:39:11');
/*!40000 ALTER TABLE `usdt_payment_allocation_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usdt_payment_allocations`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usdt_payment_allocations`
--

LOCK TABLES `usdt_payment_allocations` WRITE;
/*!40000 ALTER TABLE `usdt_payment_allocations` DISABLE KEYS */;
INSERT INTO `usdt_payment_allocations` VALUES (1,'PAY-7C5BFC6B999140BA','DEP-F2C593D936A6830C','DEP-F2C593D936A6830C','PUR-CE1D2C1ECD7A801A','PUR-CE1D2C1ECD7A801A',6,5.00000000,'consumed',6,1,NULL,'2026-11-17 10:37:15','2026-11-17 10:40:37',NULL,NULL,'2026-11-17 10:37:15','2026-11-17 10:40:37'),(2,'PAY-DBFFEDE03DF84609','DEP-0FD4804DC7E76307','DEP-0FD4804DC7E76307','PUR-DA91F7DA0F5DD26C','PUR-DA91F7DA0F5DD26C',7,5.00000000,'consumed',7,1,NULL,'2026-11-17 12:40:49','2026-11-17 12:41:05',NULL,NULL,'2026-11-17 12:40:49','2026-11-17 12:41:05'),(3,'PAY-1254070929B0609F','DEP-48BEC06030C4E190','DEP-48BEC06030C4E190','PUR-899B6BC6A2AF6FCC','PUR-899B6BC6A2AF6FCC',8,5.00000000,'consumed',8,1,NULL,'2026-11-17 14:31:13','2026-11-17 14:31:30',NULL,NULL,'2026-11-17 14:31:13','2026-11-17 14:31:30'),(4,'PAY-B8C91A2A88B6CAC5','DEP-942A048112A938F3','DEP-942A048112A938F3','PUR-84D66C36BB7C46AD','PUR-84D66C36BB7C46AD',9,5.00000000,'consumed',9,1,NULL,'2026-11-17 15:23:36','2026-11-17 15:24:01',NULL,NULL,'2026-11-17 15:23:36','2026-11-17 15:24:01'),(5,'PAY-17CBE05A27C9D73F','DEP-062356221DC3D2AB','DEP-062356221DC3D2AB','PUR-34049F062590516B','PUR-34049F062590516B',10,10.00000000,'consumed',10,1,NULL,'2026-11-17 16:35:17','2026-11-17 16:35:24',NULL,NULL,'2026-11-17 16:35:17','2026-11-17 16:35:24'),(6,'PAY-B8A5ED436804BDEA','DEP-76FCB506397419BF','DEP-76FCB506397419BF','PUR-1B4480DAD069E5B3','PUR-1B4480DAD069E5B3',11,5.00000000,'consumed',11,1,NULL,'2026-11-17 16:50:47','2026-11-17 16:50:53',NULL,NULL,'2026-11-17 16:50:47','2026-11-17 16:50:53'),(7,'PAY-23582F191462F215','DEP-5BD53E8A2CDE2FA0','DEP-5BD53E8A2CDE2FA0','PUR-21037EBC049498EA','PUR-21037EBC049498EA',12,25.00000000,'consumed',12,1,NULL,'2026-11-17 21:25:41','2026-11-17 21:25:49',NULL,NULL,'2026-11-17 21:25:41','2026-11-17 21:25:49'),(8,'PAY-527BBD258AC74AAE','DEP-05C2A521C4AB8CFB','DEP-05C2A521C4AB8CFB','PUR-C69C97B26EDF9546','PUR-C69C97B26EDF9546',13,5.00000000,'consumed',13,1,NULL,'2026-11-17 21:39:00','2026-11-17 21:39:11',NULL,NULL,'2026-11-17 21:39:00','2026-11-17 21:39:11');
/*!40000 ALTER TABLE `usdt_payment_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usdt_payment_networks`
--

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
  `explorer_tx_url_template` varchar(255) DEFAULT NULL,
  `rpc_url_env_key` varchar(80) DEFAULT NULL,
  `is_enabled` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 100,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  `display_status` varchar(24) NOT NULL DEFAULT 'hidden',
  `qr_stored_name` varchar(120) DEFAULT NULL,
  `qr_mime` varchar(64) DEFAULT NULL,
  `qr_source` varchar(16) NOT NULL DEFAULT 'none',
  `qr_decoded_payload` varchar(512) DEFAULT NULL,
  `qr_verified_at` datetime DEFAULT NULL,
  `recipient_name` varchar(120) DEFAULT NULL,
  `logo_stored_name` varchar(120) DEFAULT NULL,
  `logo_mime` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `network_key` (`network_key`),
  KEY `is_enabled` (`is_enabled`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usdt_payment_networks`
--

LOCK TABLES `usdt_payment_networks` WRITE;
/*!40000 ALTER TABLE `usdt_payment_networks` DISABLE KEYS */;
INSERT INTO `usdt_payment_networks` VALUES (1,'ngw_test_evm',11155111,'none','NGW Test EVM (Demo Only)','USDT','0x0000000000000000000000000000000000000001',6,'0x000000000000000000000000000000000000dEaD','/^0x[a-fA-F0-9]{40}$/','/^0x[a-fA-F0-9]{64}$/',3,5.00000000,100000.00000000,'DEMO ONLY. Do not send real USDT or any other cryptocurrency to this address.','https://sepolia.etherscan.io/tx/{txHash}',NULL,0,100,'2026-11-13 05:56:29','2026-11-13 05:57:14',1,'hidden',NULL,NULL,'none',NULL,NULL,NULL,NULL,NULL),(2,'bep20',56,'evm_erc20','BEP20','USDT','0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E',18,'0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E','/^0x[a-fA-F0-9]{40}$/','/^0x[a-fA-F0-9]{64}$/',15,5.00000000,100000.00000000,NULL,NULL,'blockchain.evmRpcUrl',1,1,'2026-11-17 08:22:45','2026-11-17 12:33:03',0,'live','qr_live_e9e665f9a164ad4feadfbe32929fdd59.jpg','image/jpeg','uploaded','','2026-11-17 12:32:51','NGW','logo_1d07aa185c1cb127eda7f22cc080a8e5.jpg','image/jpeg');
/*!40000 ALTER TABLE `usdt_payment_networks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'ROOT0001',NULL,NULL,1,NULL,'2026-11-12 07:05:07','2026-11-12 07:05:07',NULL),(5,'NGW000001',NULL,NULL,1,NULL,'2026-11-16 12:51:40','2026-11-16 12:51:40',NULL),(6,'NGW000002',NULL,NULL,1,NULL,'2026-11-17 10:26:13','2026-11-17 10:26:13',NULL),(7,'NGW000003',NULL,NULL,1,NULL,'2026-11-17 12:02:07','2026-11-17 12:02:07',NULL),(8,'NGW981234',NULL,NULL,1,NULL,'2026-11-17 14:23:57','2026-11-17 14:23:57',NULL),(9,'NGW981235',NULL,NULL,1,NULL,'2026-11-17 15:15:34','2026-11-17 15:15:34',NULL),(10,'NGW981236',NULL,NULL,1,NULL,'2026-11-17 16:32:03','2026-11-17 16:32:03',NULL),(11,'NGW981237',NULL,NULL,1,NULL,'2026-11-17 16:45:29','2026-11-17 16:45:29',NULL),(12,'NGW981238',NULL,NULL,1,NULL,'2026-11-17 19:02:32','2026-11-17 19:02:32',NULL),(13,'NGW981239',NULL,NULL,1,NULL,'2026-11-17 21:35:36','2026-11-17 21:35:36',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'ng_database'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-18 15:49:33

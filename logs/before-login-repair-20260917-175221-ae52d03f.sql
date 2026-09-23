-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: coopvote_test
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
-- Table structure for table `admin_profiles`
--

DROP TABLE IF EXISTS `admin_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_profiles` (
  `user_id` int(11) NOT NULL,
  `display_name` varchar(150) NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `admin_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_profiles`
--

LOCK TABLES `admin_profiles` WRITE;
/*!40000 ALTER TABLE `admin_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `announcements`
--

DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcements` (
  `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`announcement_id`),
  KEY `idx_ann_active` (`is_active`,`created_at`),
  KEY `fk_ann_creator` (`created_by`),
  CONSTRAINT `fk_ann_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcements`
--

LOCK TABLES `announcements` WRITE;
/*!40000 ALTER TABLE `announcements` DISABLE KEYS */;
/*!40000 ALTER TABLE `announcements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `log_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_audit_time` (`timestamp`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_logs_user` (`user_id`,`timestamp`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ballots`
--

DROP TABLE IF EXISTS `ballots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ballots` (
  `ballot_id` int(11) NOT NULL AUTO_INCREMENT,
  `election_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `has_voted` tinyint(1) NOT NULL DEFAULT 0,
  `receipt_code` varchar(24) DEFAULT NULL,
  `voted_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ballot_id`),
  UNIQUE KEY `uq_ballot_per_voter` (`user_id`,`election_id`),
  UNIQUE KEY `receipt_code` (`receipt_code`),
  KEY `idx_ballots_election` (`election_id`,`has_voted`),
  CONSTRAINT `fk_ballots_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ballots_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ballots`
--

LOCK TABLES `ballots` WRITE;
/*!40000 ALTER TABLE `ballots` DISABLE KEYS */;
INSERT INTO `ballots` VALUES (1,2,2,1,'CV-TEST1000','2026-09-17 17:52:21',NULL,NULL,'2026-09-17 17:52:21'),(2,2,3,1,'CV-TEST2000','2026-09-17 17:52:21',NULL,NULL,'2026-09-17 17:52:21'),(3,2,4,1,'CV-TEST3000','2026-09-17 17:52:21',NULL,NULL,'2026-09-17 17:52:21');
/*!40000 ALTER TABLE `ballots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `blocked_ips`
--

DROP TABLE IF EXISTS `blocked_ips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `blocked_ips` (
  `block_id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `blocked_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`block_id`),
  UNIQUE KEY `uq_blocked_ip` (`ip_address`),
  KEY `fk_blocked_by` (`blocked_by`),
  CONSTRAINT `fk_blocked_by` FOREIGN KEY (`blocked_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blocked_ips`
--

LOCK TABLES `blocked_ips` WRITE;
/*!40000 ALTER TABLE `blocked_ips` DISABLE KEYS */;
/*!40000 ALTER TABLE `blocked_ips` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `candidates`
--

DROP TABLE IF EXISTS `candidates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `candidates` (
  `candidate_id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  `election_id` int(11) NOT NULL,
  `bio` text DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`candidate_id`),
  UNIQUE KEY `uq_candidate_per_election` (`member_id`,`election_id`),
  UNIQUE KEY `uq_candidate_context` (`candidate_id`,`position_id`,`election_id`),
  KEY `idx_candidates_position` (`position_id`,`sort_order`),
  KEY `idx_candidates_lookup` (`election_id`,`position_id`),
  KEY `fk_candidate_position_context` (`position_id`,`election_id`),
  CONSTRAINT `fk_candidate_position_context` FOREIGN KEY (`position_id`, `election_id`) REFERENCES `positions` (`position_id`, `election_id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_candidates_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_candidates_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_candidates_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`position_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `candidates`
--

LOCK TABLES `candidates` WRITE;
/*!40000 ALTER TABLE `candidates` DISABLE KEYS */;
INSERT INTO `candidates` VALUES (1,13,1,1,'bio text',NULL,1,1,'2026-09-17 17:52:21'),(2,14,1,1,'bio text',NULL,2,1,'2026-09-17 17:52:21'),(3,15,2,1,'bio text',NULL,1,1,'2026-09-17 17:52:21'),(4,16,2,1,'bio text',NULL,2,1,'2026-09-17 17:52:21'),(5,13,3,2,'bio text',NULL,1,1,'2026-09-17 17:52:21'),(6,14,3,2,'bio text',NULL,2,1,'2026-09-17 17:52:21'),(7,15,4,2,'bio text',NULL,1,1,'2026-09-17 17:52:21'),(8,16,4,2,'bio text',NULL,2,1,'2026-09-17 17:52:21'),(9,13,5,3,'bio text',NULL,1,1,'2026-09-17 17:52:21'),(10,14,5,3,'bio text',NULL,2,1,'2026-09-17 17:52:21'),(11,15,6,3,'bio text',NULL,1,1,'2026-09-17 17:52:21'),(12,16,6,3,'bio text',NULL,2,1,'2026-09-17 17:52:21');
/*!40000 ALTER TABLE `candidates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `election_admin_decisions`
--

DROP TABLE IF EXISTS `election_admin_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `election_admin_decisions` (
  `request_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `decision` enum('approve','reject') NOT NULL,
  `note` varchar(1000) DEFAULT NULL,
  `decided_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`request_id`,`admin_id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `election_admin_decisions_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `election_approval_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `election_admin_decisions_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `election_admin_decisions`
--

LOCK TABLES `election_admin_decisions` WRITE;
/*!40000 ALTER TABLE `election_admin_decisions` DISABLE KEYS */;
/*!40000 ALTER TABLE `election_admin_decisions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `election_approval_requests`
--

DROP TABLE IF EXISTS `election_approval_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `election_approval_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `election_id` int(11) NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `required_approvals` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `proposed_end` datetime DEFAULT NULL,
  `state` enum('pending','opened','superseded') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`request_id`),
  KEY `requested_by` (`requested_by`),
  KEY `idx_request_election` (`election_id`,`state`),
  CONSTRAINT `election_approval_requests_ibfk_1` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE,
  CONSTRAINT `election_approval_requests_ibfk_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `chk_request_approvals` CHECK (`required_approvals` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `election_approval_requests`
--

LOCK TABLES `election_approval_requests` WRITE;
/*!40000 ALTER TABLE `election_approval_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `election_approval_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `election_integrity`
--

DROP TABLE IF EXISTS `election_integrity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `election_integrity` (
  `election_id` int(11) NOT NULL,
  `vote_count` bigint(20) NOT NULL DEFAULT 0,
  `baseline_count` bigint(20) NOT NULL DEFAULT 0,
  `seal` char(64) NOT NULL,
  PRIMARY KEY (`election_id`),
  CONSTRAINT `fk_integrity_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `election_integrity`
--

LOCK TABLES `election_integrity` WRITE;
/*!40000 ALTER TABLE `election_integrity` DISABLE KEYS */;
INSERT INTO `election_integrity` VALUES (1,0,0,'12c27cfc2e3b40404cf9aa413722b023f8d726bbbe01b17611182af2fec97fde'),(2,6,6,'950139c5988cadc02515ba76c1d5d5597182b5a64470910ae0b4068d3be79c0f'),(3,0,0,'6aa45415ea5cd0d12f20e6e7f2e7637f7b8949f7366758e1f9386f7b4ff7ace9'),(4,0,0,'1ce3964afc6c0f67218e6e13caada4cfcac862c14421fb06054e0b2df67c085c'),(5,0,0,'7277623edb95ab38b996e17b854c5f167c565011c9535422720c603aa17f8b61');
/*!40000 ALTER TABLE `election_integrity` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `election_reports`
--

DROP TABLE IF EXISTS `election_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `election_reports` (
  `election_id` int(11) NOT NULL,
  `snapshot` longtext NOT NULL,
  `signature` char(64) NOT NULL,
  `generated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `superseded_at` datetime DEFAULT NULL,
  `history` longtext DEFAULT NULL,
  PRIMARY KEY (`election_id`),
  CONSTRAINT `fk_report_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `election_reports`
--

LOCK TABLES `election_reports` WRITE;
/*!40000 ALTER TABLE `election_reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `election_reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `elections`
--

DROP TABLE IF EXISTS `elections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `elections` (
  `election_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `status` enum('draft','published','cancelled','postponed') NOT NULL DEFAULT 'draft',
  `results_released_at` datetime DEFAULT NULL,
  `released_by` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`election_id`),
  KEY `fk_elections_creator` (`created_by`),
  KEY `fk_elections_releaser` (`released_by`),
  KEY `idx_elections_window` (`start_datetime`,`end_datetime`),
  KEY `idx_elections_status` (`status`),
  CONSTRAINT `fk_elections_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_elections_releaser` FOREIGN KEY (`released_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_election_dates` CHECK (`end_datetime` > `start_datetime`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `elections`
--

LOCK TABLES `elections` WRITE;
/*!40000 ALTER TABLE `elections` DISABLE KEYS */;
INSERT INTO `elections` VALUES (1,'Live Election','open for voting','2026-09-17 16:52:21','2026-09-18 17:52:21','published',NULL,NULL,1,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(2,'Closed Election',NULL,'2026-09-15 17:52:21','2026-09-16 17:52:21','published','2026-09-17 17:52:21',NULL,1,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(3,'Unreleased Election',NULL,'2026-09-15 17:52:21','2026-09-16 17:52:21','published',NULL,NULL,1,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(4,'Upcoming Election',NULL,'2026-09-18 17:52:21','2026-09-19 17:52:21','published',NULL,NULL,1,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(5,'Draft Election',NULL,'2026-09-18 17:52:21','2026-09-19 17:52:21','draft',NULL,NULL,1,'2026-09-17 17:52:21','2026-09-17 17:52:21');
/*!40000 ALTER TABLE `elections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `governance_config`
--

DROP TABLE IF EXISTS `governance_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `governance_config` (
  `id` tinyint(4) NOT NULL,
  `required_approvals` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_governance_singleton` CHECK (`id` = 1),
  CONSTRAINT `chk_required_approvals` CHECK (`required_approvals` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `governance_config`
--

LOCK TABLES `governance_config` WRITE;
/*!40000 ALTER TABLE `governance_config` DISABLE KEYS */;
INSERT INTO `governance_config` VALUES (1,1);
/*!40000 ALTER TABLE `governance_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `attempt_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(120) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_attempt_ip` (`ip_address`,`created_at`),
  KEY `idx_attempt_success` (`success`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_profile_photo_chunks`
--

DROP TABLE IF EXISTS `member_profile_photo_chunks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_profile_photo_chunks` (
  `member_id` int(11) NOT NULL,
  `chunk_no` smallint(5) unsigned NOT NULL,
  `photo_data` blob NOT NULL,
  PRIMARY KEY (`member_id`,`chunk_no`),
  CONSTRAINT `fk_profile_photo_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `member_profile_photo_chunks`
--

LOCK TABLES `member_profile_photo_chunks` WRITE;
/*!40000 ALTER TABLE `member_profile_photo_chunks` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_profile_photo_chunks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `members`
--

DROP TABLE IF EXISTS `members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `members` (
  `member_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `member_no` varchar(30) DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `contact_no` varchar(20) DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `years_in_coop` int(11) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `profile_photo` mediumblob DEFAULT NULL,
  `profile_photo_mime` varchar(32) DEFAULT NULL,
  `profile_photo_updated_at` datetime DEFAULT NULL,
  `id_front_url` varchar(255) DEFAULT NULL,
  `id_back_url` varchar(255) DEFAULT NULL,
  `membership_status` enum('pending','active','inactive') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `registered_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`member_id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `member_no` (`member_no`),
  KEY `fk_members_approver` (`approved_by`),
  KEY `idx_members_status` (`membership_status`),
  KEY `idx_members_name` (`full_name`),
  CONSTRAINT `fk_members_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `members`
--

LOCK TABLES `members` WRITE;
/*!40000 ALTER TABLE `members` DISABLE KEYS */;
INSERT INTO `members` VALUES (1,2,'TEST-0001','Test Member 1',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21'),(2,3,'TEST-0002','Test Member 2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21'),(3,4,'TEST-0003','Test Member 3',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21'),(4,5,'TEST-0004','Test Member 4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21'),(5,6,'TEST-0005','Test Member 5',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21'),(6,7,'TEST-0006','Test Member 6',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21'),(7,8,'TEST-0007','Test Member 7',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21'),(8,9,'TEST-0008','Test Member 8',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21'),(9,10,'TEST-0009','Test Member 9',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21'),(10,11,'TEST-0010','Test Member 10',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21'),(11,12,'TEST-0090','Suspended Member',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'',NULL,NULL,'2026-09-17 17:52:21'),(12,13,'TEST-0091','Pending Member',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,NULL,'2026-09-17 17:52:21'),(13,14,'TEST-CCHAIR1','Chairperson Candidate 1',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21'),(14,15,'TEST-CCHAIR2','Chairperson Candidate 2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21'),(15,16,'TEST-CTREAS1','Treasurer Candidate 1',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21'),(16,17,'TEST-CTREAS2','Treasurer Candidate 2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,'2026-09-17 17:52:21');
/*!40000 ALTER TABLE `members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_reads`
--

DROP TABLE IF EXISTS `notification_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_reads` (
  `user_id` int(11) NOT NULL,
  `notice_id` varchar(160) NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`notice_id`),
  CONSTRAINT `fk_notice_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_reads`
--

LOCK TABLES `notification_reads` WRITE;
/*!40000 ALTER TABLE `notification_reads` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_reads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `otp_tokens`
--

DROP TABLE IF EXISTS `otp_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `otp_tokens` (
  `otp_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `purpose` enum('login','ballot','register') NOT NULL DEFAULT 'login',
  `channel` enum('email','sms') NOT NULL DEFAULT 'email',
  `code_hash` varchar(255) NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`otp_id`),
  KEY `idx_otp_lookup` (`user_id`,`purpose`,`is_used`,`expires_at`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_tokens`
--

LOCK TABLES `otp_tokens` WRITE;
/*!40000 ALTER TABLE `otp_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `otp_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `positions`
--

DROP TABLE IF EXISTS `positions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `positions` (
  `position_id` int(11) NOT NULL AUTO_INCREMENT,
  `election_id` int(11) NOT NULL,
  `position_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `max_vote` int(11) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`position_id`),
  UNIQUE KEY `uq_position_per_election` (`election_id`,`position_name`),
  UNIQUE KEY `uq_position_election` (`position_id`,`election_id`),
  KEY `idx_positions_order` (`election_id`,`sort_order`),
  CONSTRAINT `fk_positions_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_position_maxvote` CHECK (`max_vote` = 1)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `positions`
--

LOCK TABLES `positions` WRITE;
/*!40000 ALTER TABLE `positions` DISABLE KEYS */;
INSERT INTO `positions` VALUES (1,1,'Chairperson',NULL,1,1),(2,1,'Treasurer',NULL,1,2),(3,2,'Chairperson',NULL,1,1),(4,2,'Treasurer',NULL,1,2),(5,3,'Chairperson',NULL,1,1),(6,3,'Treasurer',NULL,1,2);
/*!40000 ALTER TABLE `positions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `slug` varchar(30) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Administrator','admin',NULL),(2,'Voter','voter',NULL);
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `security_alert_reviews`
--

DROP TABLE IF EXISTS `security_alert_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_alert_reviews` (
  `alert_key` char(64) NOT NULL,
  `reviewed_by` int(11) NOT NULL,
  `reviewed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`alert_key`),
  KEY `fk_alert_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_alert_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE NO ACTION ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `security_alert_reviews`
--

LOCK TABLES `security_alert_reviews` WRITE;
/*!40000 ALTER TABLE `security_alert_reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `security_alert_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES ('coop_name','Test Cooperative','2026-09-17 17:52:21'),('coop_short','TESTCOOP','2026-09-17 17:52:21'),('support_email','help@test.coop','2026-09-17 17:52:21');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_preferences`
--

DROP TABLE IF EXISTS `user_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_preferences` (
  `user_id` int(11) NOT NULL,
  `theme` varchar(20) NOT NULL DEFAULT 'navy',
  PRIMARY KEY (`user_id`),
  CONSTRAINT `user_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_preferences`
--

LOCK TABLES `user_preferences` WRITE;
/*!40000 ALTER TABLE `user_preferences` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_preferences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` int(11) NOT NULL,
  `status` enum('pending_verification','pending_approval','active','suspended','deactivated','rejected') NOT NULL DEFAULT 'pending_verification',
  `email_verified_at` datetime DEFAULT NULL,
  `verify_token` varchar(64) DEFAULT NULL,
  `failed_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_role` (`role_id`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_verify` (`verify_token`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'t_admin','admin@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',1,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(2,'t_voter1','voter1@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(3,'t_voter2','voter2@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(4,'t_voter3','voter3@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(5,'t_voter4','voter4@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(6,'t_voter5','voter5@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(7,'t_voter6','voter6@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(8,'t_voter7','voter7@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(9,'t_voter8','voter8@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(10,'t_voter9','voter9@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(11,'t_voter10','voter10@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(12,'t_suspended','susp@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'suspended','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(13,'t_pending','pend@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'pending_approval','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(14,'t_cand_chair1','cand_chair1@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(15,'t_cand_chair2','cand_chair2@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(16,'t_cand_treas1','cand_treas1@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21'),(17,'t_cand_treas2','cand_treas2@test.coop','$2y$10$rJ73YF78B00bytVnSRt.eebSl4PQo4WrQ14fyEijHPcyAMzB1crpe',2,'active','2026-09-17 17:52:21',NULL,0,NULL,NULL,'2026-09-17 17:52:21','2026-09-17 17:52:21');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vote_integrity`
--

DROP TABLE IF EXISTS `vote_integrity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vote_integrity` (
  `vote_id` bigint(20) NOT NULL,
  `election_id` int(11) NOT NULL,
  `nonce` char(32) NOT NULL,
  `signature` char(64) NOT NULL,
  `origin` varchar(12) NOT NULL,
  PRIMARY KEY (`vote_id`),
  KEY `idx_integrity_election` (`election_id`),
  CONSTRAINT `fk_protected_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vote_integrity`
--

LOCK TABLES `vote_integrity` WRITE;
/*!40000 ALTER TABLE `vote_integrity` DISABLE KEYS */;
INSERT INTO `vote_integrity` VALUES (1,2,'1937ce18f348ab8db20b17674db5e2e7','cb4d8c262f0f46ede3ae3ba0028a1570e4c7f4825d78d734151110a3d75cd9f1','baseline'),(2,2,'17ef76ffc4ddcd6359415ff7e76f087e','2a000e323d606b46dcb010055c79cd03d7e85d137ee1541a9163a2a9b237745e','baseline'),(3,2,'53033a3539b78d315284e0a4e06e7954','9280468b1cd0650b4ae0b5fa62e99cc301e4b7a076749cf2bef9fa54f348a9fc','baseline'),(4,2,'a5c84961379a578372ba2d2aad0d7cc3','1035fcb9e623fe99060d37dd2c91094d7c0fe67f494686fb02fe0f7ceb6142a5','baseline'),(5,2,'edcf153e291faabab6666cdb02f0ef23','d6d24d874db55c1b2bf1236e6ab747a2793ae855d9b71505f66e8707b8902e3a','baseline'),(6,2,'c7c9dcc08b072c04bf2f37ecc68e998c','5507e18e2ba24bdc0ef446a91960c99b1f983d75557b9705cce5b92ff7f4f8d4','baseline');
/*!40000 ALTER TABLE `vote_integrity` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `votes`
--

DROP TABLE IF EXISTS `votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `votes` (
  `vote_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `election_id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  `candidate_id` int(11) NOT NULL,
  `cast_date` date NOT NULL,
  PRIMARY KEY (`vote_id`),
  KEY `fk_votes_position` (`position_id`),
  KEY `fk_votes_candidate` (`candidate_id`),
  KEY `idx_votes_tally` (`election_id`,`position_id`,`candidate_id`),
  KEY `fk_vote_candidate_context` (`candidate_id`,`position_id`,`election_id`),
  CONSTRAINT `fk_vote_candidate_context` FOREIGN KEY (`candidate_id`, `position_id`, `election_id`) REFERENCES `candidates` (`candidate_id`, `position_id`, `election_id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_votes_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`candidate_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_votes_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_votes_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`position_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `votes`
--

LOCK TABLES `votes` WRITE;
/*!40000 ALTER TABLE `votes` DISABLE KEYS */;
INSERT INTO `votes` VALUES (1,2,3,5,'2026-09-17'),(2,2,4,7,'2026-09-17'),(3,2,3,5,'2026-09-17'),(4,2,4,7,'2026-09-17'),(5,2,3,6,'2026-09-17'),(6,2,4,8,'2026-09-17');
/*!40000 ALTER TABLE `votes` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-17 17:52:21

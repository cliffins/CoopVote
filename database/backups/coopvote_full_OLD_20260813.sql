--
-- This dump does not choose a database.
--
-- mysqldump normally writes CREATE DATABASE and USE lines here. They look
-- helpful and are not: they override whichever database the person doing
-- the import actually selected, so the file lands in the database the dump
-- was taken from rather than the one that was chosen. Piping this file into
-- the mysql client while pointing at a scratch database sent every
-- DROP TABLE in it at the live one instead.
--
-- Import it by naming the target explicitly:
--
--     mysql -u root coopvote < database/coopvote_full.sql
--
-- or select the database first in phpMyAdmin, then Import. setup.php
-- creates and selects the database itself, so it is unaffected.
--
-- The DROP DATABASE statement mysqldump writes here has been removed.
-- It was hidden inside a version-gated comment, which MySQL executes:
--     /*!40000 DROP DATABASE IF EXISTS `coopvote`*/;
-- Importing this file through phpMyAdmin or the mysql client would have
-- destroyed an existing coopvote database before restoring anything --
-- including on a machine where the import was only meant to add tables.
-- CREATE DATABASE IF NOT EXISTS and USE below are kept: they are what
-- makes a fresh import work, and neither destroys existing data.
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */
;

/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */
;

/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */
;

/*!40101 SET NAMES utf8mb4 */
;

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */
;

/*!40103 SET TIME_ZONE='+00:00' */
;

/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */
;

/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */
;

/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */
;

/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */
;

;

/*!32312 IF NOT EXISTS*/
`coopvote`
/*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */
;


DROP TABLE IF EXISTS `announcements`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

CREATE TABLE `announcements` (
  `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`announcement_id`),
  KEY `idx_ann_active` (`is_active`, `created_at`),
  KEY `fk_ann_creator` (`created_by`),
  CONSTRAINT `fk_ann_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE
  SET
    NULL ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `announcements` WRITE;

/*!40000 ALTER TABLE `announcements` DISABLE KEYS */
;

/*!40000 ALTER TABLE `announcements` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `audit_logs`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

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
  KEY `idx_audit_logs_user` (`user_id`, `timestamp`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE
  SET
    NULL ON UPDATE CASCADE
) ENGINE = InnoDB AUTO_INCREMENT = 99 DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `audit_logs` WRITE;

/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */
;

INSERT INTO
  `audit_logs`
VALUES
  (
    1,
    1,
    'system.seed',
    NULL,
    NULL,
    'Demo data seeded',
    '127.0.0.1',
    NULL,
    '2026-08-05 21:06:09'
  ),
(
    2,
    2,
    'election.created',
    'election',
    1,
    'Created \"2026 Annual General Assembly Election\"',
    '127.0.0.1',
    NULL,
    '2026-08-05 21:06:09'
  ),
(
    3,
    2,
    'election.created',
    'election',
    2,
    'Created \"Audit Committee Special Election\"',
    '127.0.0.1',
    NULL,
    '2026-08-05 21:06:09'
  ),
(
    4,
    10,
    'auth.password_ok',
    'user',
    10,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-05 21:08:01'
  ),
(
    5,
    10,
    'auth.login',
    'user',
    10,
    'Signed in successfully as Voter',
    '::1',
    'curl/8.18.0',
    '2026-08-05 21:08:02'
  ),
(
    6,
    2,
    'auth.password_ok',
    'user',
    2,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-05 21:08:02'
  ),
(
    7,
    2,
    'auth.login',
    'user',
    2,
    'Signed in successfully as Election Officer',
    '::1',
    'curl/8.18.0',
    '2026-08-05 21:08:02'
  ),
(
    8,
    1,
    'auth.password_ok',
    'user',
    1,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-05 21:08:02'
  ),
(
    9,
    1,
    'auth.login',
    'user',
    1,
    'Signed in successfully as Administrator',
    '::1',
    'curl/8.18.0',
    '2026-08-05 21:08:02'
  ),
(
    10,
    3,
    'auth.password_ok',
    'user',
    3,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-05 21:08:03'
  ),
(
    11,
    3,
    'auth.login',
    'user',
    3,
    'Signed in successfully as Auditor',
    '::1',
    'curl/8.18.0',
    '2026-08-05 21:08:03'
  ),
(
    12,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"admin\"',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Code/1.131.0 Chrome/148.0.7778.280 Electron/42.7.0 Safari/537.36',
    '2026-08-05 21:11:11'
  ),
(
    13,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"admin\"',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Code/1.131.0 Chrome/148.0.7778.280 Electron/42.7.0 Safari/537.36',
    '2026-08-05 21:11:30'
  ),
(
    14,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"admin\"',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
    '2026-08-05 21:12:38'
  ),
(
    15,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"admin@coopvote.test\"',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
    '2026-08-05 21:27:18'
  ),
(
    16,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"admin@coopvote.test\"',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
    '2026-08-05 21:27:24'
  ),
(
    17,
    NULL,
    'auth.register',
    'user',
    19,
    'Registered new account (pending email verification)',
    '::1',
    'curl/8.18.0',
    '2026-08-06 01:46:58'
  ),
(
    18,
    NULL,
    'auth.email_verified',
    'user',
    19,
    'Verified email address',
    '::1',
    'curl/8.18.0',
    '2026-08-06 01:47:20'
  ),
(
    19,
    NULL,
    'auth.password_ok',
    'user',
    19,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-06 01:47:51'
  ),
(
    20,
    NULL,
    'auth.password_ok',
    'user',
    19,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-06 01:48:20'
  ),
(
    21,
    NULL,
    'auth.login',
    'user',
    19,
    'Signed in successfully as Voter',
    '::1',
    'curl/8.18.0',
    '2026-08-06 01:48:20'
  ),
(
    22,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"test.applicant\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 01:48:35'
  ),
(
    23,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"test.applicant\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 01:48:35'
  ),
(
    24,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"test.applicant\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 01:48:35'
  ),
(
    25,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"test.applicant\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 01:48:35'
  ),
(
    26,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"test.applicant\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 01:48:35'
  ),
(
    27,
    NULL,
    'auth.password_ok',
    'user',
    19,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-06 01:49:08'
  ),
(
    28,
    NULL,
    'auth.login',
    'user',
    19,
    'Signed in successfully as Voter',
    '::1',
    'curl/8.18.0',
    '2026-08-06 01:49:08'
  ),
(
    29,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"voter1\"',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.25927.0 Chrome/148.0.7778.280 Electron/42.7.0 Safari/537.36 MSIX',
    '2026-08-06 02:09:03'
  ),
(
    30,
    10,
    'auth.password_ok',
    'user',
    10,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:43:09'
  ),
(
    31,
    10,
    'auth.login',
    'user',
    10,
    'Signed in successfully as Voter',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:43:09'
  ),
(
    32,
    2,
    'auth.password_ok',
    'user',
    2,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:43:12'
  ),
(
    33,
    2,
    'auth.login',
    'user',
    2,
    'Signed in successfully as Election Officer',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:43:12'
  ),
(
    34,
    1,
    'auth.password_ok',
    'user',
    1,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:43:16'
  ),
(
    35,
    1,
    'auth.login',
    'user',
    1,
    'Signed in successfully as Administrator',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:43:16'
  ),
(
    36,
    3,
    'auth.password_ok',
    'user',
    3,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:43:20'
  ),
(
    37,
    3,
    'auth.login',
    'user',
    3,
    'Signed in successfully as Auditor',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:43:20'
  ),
(
    38,
    2,
    'election.created',
    'election',
    3,
    'Created \"Smoke Test Open Election\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:44:22'
  ),
(
    39,
    2,
    'position.created',
    'position',
    6,
    'Added position \"Test Seat\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:44:22'
  ),
(
    40,
    2,
    'candidate.created',
    'candidate',
    11,
    'Registered candidate \"Juan D. Dela Cruz\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:44:22'
  ),
(
    41,
    2,
    'candidate.created',
    'candidate',
    12,
    'Registered candidate \"Maria L. Santos\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:44:22'
  ),
(
    42,
    2,
    'election.publishd',
    'election',
    3,
    'Published \"Smoke Test Open Election\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:44:22'
  ),
(
    43,
    2,
    'election.created',
    'election',
    4,
    'Created \"Smoke Test Closed Election\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:44:22'
  ),
(
    44,
    2,
    'position.created',
    'position',
    7,
    'Added position \"Closed Seat\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:44:22'
  ),
(
    45,
    2,
    'candidate.created',
    'candidate',
    13,
    'Registered candidate \"Pedro R. Reyes\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:44:22'
  ),
(
    46,
    2,
    'election.publishd',
    'election',
    4,
    'Published \"Smoke Test Closed Election\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:44:23'
  ),
(
    47,
    2,
    'election.results_released',
    'election',
    3,
    'Released results for \"Smoke Test Open Election\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:44:23'
  ),
(
    48,
    2,
    'election.results_released',
    'election',
    4,
    'Released results for \"Smoke Test Closed Election\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 13:44:23'
  ),
(
    49,
    NULL,
    'auth.register',
    'user',
    20,
    'Registered new account (pending email verification)',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:03:16'
  ),
(
    50,
    NULL,
    'auth.password_ok',
    'user',
    20,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:03:21'
  ),
(
    51,
    NULL,
    'auth.login',
    'user',
    20,
    'Signed in successfully as Voter',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:03:21'
  ),
(
    52,
    2,
    'election.created',
    'election',
    5,
    'Created \"Smoke Test Vote Flow 2\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:16:27'
  ),
(
    53,
    2,
    'position.created',
    'position',
    8,
    'Added position \"Test Seat 2\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:16:27'
  ),
(
    54,
    2,
    'candidate.created',
    'candidate',
    14,
    'Registered candidate \"Ana M. Lim\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:16:27'
  ),
(
    55,
    2,
    'election.publishd',
    'election',
    5,
    'Published \"Smoke Test Vote Flow 2\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:16:27'
  ),
(
    56,
    10,
    'ballot.cast',
    'election',
    5,
    'Cast ballot for \"Smoke Test Vote Flow 2\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:16:28'
  ),
(
    57,
    1,
    'member.approved',
    'user',
    18,
    'Approved \"Grace A. Villanueva\" as COOP-0009',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:17:12'
  ),
(
    58,
    1,
    'user.role_changed',
    'user',
    11,
    'Changed role for \"voter2\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:17:12'
  ),
(
    59,
    1,
    'user.role_changed',
    'user',
    11,
    'Changed role for \"voter2\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:17:12'
  ),
(
    60,
    1,
    'user.status_changed',
    'user',
    11,
    'Set \"voter2\" status to suspended',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:17:12'
  ),
(
    61,
    1,
    'user.status_changed',
    'user',
    11,
    'Set \"voter2\" status to active',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:17:12'
  ),
(
    62,
    1,
    'settings.updated',
    'settings',
    NULL,
    'Updated cooperative settings',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:17:12'
  ),
(
    63,
    10,
    'auth.password_ok',
    'user',
    10,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:18:21'
  ),
(
    64,
    10,
    'auth.login',
    'user',
    10,
    'Signed in successfully as Voter',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:18:22'
  ),
(
    65,
    2,
    'auth.password_ok',
    'user',
    2,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:18:25'
  ),
(
    66,
    2,
    'auth.login',
    'user',
    2,
    'Signed in successfully as Election Officer',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:18:25'
  ),
(
    67,
    1,
    'auth.password_ok',
    'user',
    1,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:18:28'
  ),
(
    68,
    1,
    'auth.login',
    'user',
    1,
    'Signed in successfully as Administrator',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:18:29'
  ),
(
    69,
    3,
    'auth.password_ok',
    'user',
    3,
    'Password accepted; one-time passcode sent',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:18:32'
  ),
(
    70,
    3,
    'auth.login',
    'user',
    3,
    'Signed in successfully as Auditor',
    '::1',
    'curl/8.18.0',
    '2026-08-06 14:18:32'
  ),
(
    71,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"admin\"',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
    '2026-08-06 14:20:09'
  ),
(
    72,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"admin\"',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
    '2026-08-06 14:20:12'
  ),
(
    73,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"admin@coopvote.test\"',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
    '2026-08-06 14:20:33'
  ),
(
    74,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"admin@coopvote.test\"',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
    '2026-08-06 14:20:34'
  ),
(
    75,
    NULL,
    'auth.login_failed',
    'user',
    NULL,
    'Sign-in refused (invalid) for \"admin@coopvote.test\"',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
    '2026-08-06 14:20:34'
  ),
(
    76,
    10,
    'auth.login_failed',
    'user',
    10,
    'Sign-in refused for \"voter1\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:31:47'
  ),
(
    77,
    10,
    'auth.login',
    'user',
    10,
    'Signed in successfully as Voter',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:31:47'
  ),
(
    78,
    10,
    'ballot.cast',
    'election',
    1,
    'Ballot cast (4 selections), receipt CV-33A2QJ47',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:31:57'
  ),
(
    79,
    1,
    'auth.login',
    'user',
    1,
    'Signed in successfully as Administrator',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:32:14'
  ),
(
    80,
    3,
    'auth.login',
    'user',
    3,
    'Signed in successfully as Auditor',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:32:29'
  ),
(
    81,
    NULL,
    'auth.register',
    'user',
    21,
    'Registered as \"testapplicant\" (COOP-0010) — awaiting approval',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:37:36'
  ),
(
    82,
    NULL,
    'auth.login_failed',
    'user',
    21,
    'Sign-in refused for \"testapplicant\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:37:37'
  ),
(
    83,
    1,
    'member.approved',
    'user',
    197610,
    'Approved membership (user #197610)',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:37:48'
  ),
(
    84,
    NULL,
    'auth.login_failed',
    'user',
    21,
    'Sign-in refused for \"testapplicant\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:37:48'
  ),
(
    85,
    1,
    'member.approved',
    'user',
    21,
    'Approved membership (user #21)',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:38:00'
  ),
(
    86,
    NULL,
    'auth.login',
    'user',
    21,
    'Signed in successfully as Voter',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:38:00'
  ),
(
    87,
    11,
    'auth.login',
    'user',
    11,
    'Signed in successfully as Voter',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:45:33'
  ),
(
    88,
    1,
    'auth.login',
    'user',
    1,
    'Signed in successfully as Administrator',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:46:47'
  ),
(
    89,
    12,
    'auth.login',
    'user',
    12,
    'Signed in successfully as Voter',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/150.0.0.0 Safari/537.36',
    '2026-08-06 20:49:19'
  ),
(
    90,
    12,
    'ballot.cast',
    'election',
    1,
    'Ballot cast (4 selections), receipt CV-DQ568832',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/150.0.0.0 Safari/537.36',
    '2026-08-06 20:49:25'
  ),
(
    91,
    2,
    'auth.login',
    'user',
    2,
    'Signed in successfully as Election Officer',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:49:51'
  ),
(
    92,
    2,
    'election.results_released',
    'election',
    1,
    'Released results for \"2026 Annual General Assembly Election\"',
    '::1',
    'curl/8.18.0',
    '2026-08-06 20:49:51'
  ),
(
    93,
    12,
    'auth.login',
    'user',
    12,
    'Signed in successfully as Voter',
    '::1',
    'curl/8.18.0',
    '2026-08-06 22:33:38'
  ),
(
    94,
    NULL,
    'auth.register',
    'user',
    22,
    'Registered as \"ramonmshmdiqc\" (COOP-0011) — awaiting approval',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/150.0.0.0 Safari/537.36',
    '2026-08-06 22:37:06'
  ),
(
    95,
    1,
    'member.approved',
    'user',
    22,
    'Approved membership (user #22)',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/150.0.0.0 Safari/537.36',
    '2026-08-06 22:37:45'
  ),
(
    96,
    1,
    'member.updated',
    'user',
    14,
    'Updated member \"Rosa Aquino\"',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/150.0.0.0 Safari/537.36',
    '2026-08-06 22:37:47'
  ),
(
    97,
    10,
    'auth.login',
    'user',
    10,
    'Signed in successfully as Voter',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/150.0.0.0 Safari/537.36',
    '2026-08-06 22:38:53'
  ),
(
    98,
    10,
    'ballot.cast',
    'election',
    1,
    'Ballot cast (4 selections), receipt CV-M73JNZTY',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/150.0.0.0 Safari/537.36',
    '2026-08-06 22:38:59'
  );

/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `ballots`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

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
  UNIQUE KEY `uq_ballot_per_voter` (`user_id`, `election_id`),
  UNIQUE KEY `receipt_code` (`receipt_code`),
  KEY `idx_ballots_election` (`election_id`, `has_voted`),
  CONSTRAINT `fk_ballots_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ballots_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB AUTO_INCREMENT = 18 DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `ballots` WRITE;

/*!40000 ALTER TABLE `ballots` DISABLE KEYS */
;

/*!40000 ALTER TABLE `ballots` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `blocked_ips`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

CREATE TABLE `blocked_ips` (
  `block_id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `blocked_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`block_id`),
  UNIQUE KEY `uq_blocked_ip` (`ip_address`),
  KEY `fk_blocked_by` (`blocked_by`),
  CONSTRAINT `fk_blocked_by` FOREIGN KEY (`blocked_by`) REFERENCES `users` (`user_id`) ON DELETE
  SET
    NULL ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `blocked_ips` WRITE;

/*!40000 ALTER TABLE `blocked_ips` DISABLE KEYS */
;

/*!40000 ALTER TABLE `blocked_ips` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `candidates`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

CREATE TABLE `candidates` (
  `candidate_id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  `election_id` int(11) NOT NULL,
  `platform` text DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`candidate_id`),
  UNIQUE KEY `uq_candidate_per_election` (`member_id`, `election_id`),
  KEY `idx_candidates_position` (`position_id`, `sort_order`),
  KEY `idx_candidates_lookup` (`election_id`, `position_id`),
  CONSTRAINT `fk_candidates_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_candidates_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_candidates_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`position_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB AUTO_INCREMENT = 15 DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `candidates` WRITE;

/*!40000 ALTER TABLE `candidates` DISABLE KEYS */
;

INSERT INTO
  `candidates`
VALUES
  (
    1,
    10,
    1,
    1,
    'Twelve years of service to the cooperative. I will push for transparent quarterly financial reporting and a members-first lending policy.',
    NULL,
    NULL,
    1,
    1,
    '2026-08-05 21:06:09'
  ),
(
    2,
    11,
    1,
    1,
    'Focus on expanding member benefits, digitising records, and reducing loan processing time from ten days to three.',
    NULL,
    NULL,
    2,
    1,
    '2026-08-05 21:06:09'
  ),
(
    3,
    12,
    2,
    1,
    'Strengthen coordination between committees and open monthly consultation sessions for ordinary members.',
    NULL,
    NULL,
    1,
    1,
    '2026-08-05 21:06:09'
  ),
(
    4,
    13,
    2,
    1,
    'Build a mentorship programme so younger members take part in cooperative governance.',
    NULL,
    NULL,
    2,
    1,
    '2026-08-05 21:06:09'
  ),
(
    5,
    14,
    3,
    1,
    'A licensed accountant by profession. I will publish an audited statement of accounts every quarter without exception.',
    NULL,
    NULL,
    1,
    1,
    '2026-08-05 21:06:09'
  ),
(
    6,
    15,
    3,
    1,
    'Reduce operating overhead and channel the savings into the members\' dividend pool.',
    NULL,
    NULL,
    2,
    1,
    '2026-08-05 21:06:09'
  ),
(
    7,
    16,
    4,
    1,
    'Digitise every minute and resolution so members can search cooperative records from any device.',
    NULL,
    NULL,
    1,
    1,
    '2026-08-05 21:06:09'
  ),
(
    8,
    17,
    4,
    1,
    'Timely notices, accurate minutes, and a published calendar of all cooperative activities.',
    NULL,
    NULL,
    2,
    1,
    '2026-08-05 21:06:09'
  ),
(
    9,
    14,
    5,
    2,
    'Independent, evidence-based audits reported directly to the General Assembly.',
    NULL,
    NULL,
    1,
    1,
    '2026-08-05 21:06:09'
  ),
(
    10,
    16,
    5,
    2,
    'Quarterly spot audits and a published compliance checklist.',
    NULL,
    NULL,
    2,
    1,
    '2026-08-05 21:06:09'
  );

/*!40000 ALTER TABLE `candidates` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `elections`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

CREATE TABLE `elections` (
  `election_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `status` enum('draft', 'published', 'cancelled', 'postponed') NOT NULL DEFAULT 'draft',
  `results_released_at` datetime DEFAULT NULL,
  `released_by` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`election_id`),
  KEY `fk_elections_creator` (`created_by`),
  KEY `fk_elections_releaser` (`released_by`),
  KEY `idx_elections_window` (`start_datetime`, `end_datetime`),
  KEY `idx_elections_status` (`status`),
  CONSTRAINT `fk_elections_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_elections_releaser` FOREIGN KEY (`released_by`) REFERENCES `users` (`user_id`) ON DELETE
  SET
    NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_election_dates` CHECK (`end_datetime` > `start_datetime`)
) ENGINE = InnoDB AUTO_INCREMENT = 6 DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `elections` WRITE;

/*!40000 ALTER TABLE `elections` DISABLE KEYS */
;

INSERT INTO
  `elections`
VALUES
  (
    1,
    '2026 Annual General Assembly Election',
    'Election of officers for the 2026-2027 term. All members in good standing are entitled to one ballot.',
    '2026-08-04 21:06:09',
    '2026-08-11 21:06:09',
    'published',
    NULL,
    NULL,
    2,
    '2026-08-05 21:06:09',
    '2026-08-06 22:38:36'
  ),
(
    2,
    'Audit Committee Special Election',
    'Special election to fill the vacant Audit Committee seats for the remainder of the term.',
    '2026-08-25 21:06:09',
    '2026-08-28 21:06:09',
    'published',
    NULL,
    NULL,
    2,
    '2026-08-05 21:06:09',
    '2026-08-05 21:06:09'
  );

/*!40000 ALTER TABLE `elections` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `login_attempts`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

CREATE TABLE `login_attempts` (
  `attempt_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(120) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_attempt_ip` (`ip_address`, `created_at`),
  KEY `idx_attempt_success` (`success`, `created_at`)
) ENGINE = InnoDB AUTO_INCREMENT = 14 DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `login_attempts` WRITE;

/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */
;

INSERT INTO
  `login_attempts`
VALUES
  (
    1,
    'voter1',
    '::1',
    'curl/8.18.0',
    0,
    '2026-08-06 20:31:47'
  ),
(
    2,
    'voter1',
    '::1',
    'curl/8.18.0',
    1,
    '2026-08-06 20:31:47'
  ),
(
    3,
    'admin',
    '::1',
    'curl/8.18.0',
    1,
    '2026-08-06 20:32:14'
  ),
(
    4,
    'auditor',
    '::1',
    'curl/8.18.0',
    1,
    '2026-08-06 20:32:29'
  ),
(
    5,
    'testapplicant',
    '::1',
    'curl/8.18.0',
    0,
    '2026-08-06 20:37:37'
  ),
(
    6,
    'testapplicant',
    '::1',
    'curl/8.18.0',
    0,
    '2026-08-06 20:37:48'
  ),
(
    7,
    'testapplicant',
    '::1',
    'curl/8.18.0',
    1,
    '2026-08-06 20:38:00'
  ),
(
    8,
    'voter2',
    '::1',
    'curl/8.18.0',
    1,
    '2026-08-06 20:45:33'
  ),
(
    9,
    'admin',
    '::1',
    'curl/8.18.0',
    1,
    '2026-08-06 20:46:47'
  ),
(
    10,
    'voter3',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/150.0.0.0 Safari/537.36',
    1,
    '2026-08-06 20:49:19'
  ),
(
    11,
    'officer',
    '::1',
    'curl/8.18.0',
    1,
    '2026-08-06 20:49:51'
  ),
(
    12,
    'voter3',
    '::1',
    'curl/8.18.0',
    1,
    '2026-08-06 22:33:38'
  ),
(
    13,
    'voter1',
    '::1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/150.0.0.0 Safari/537.36',
    1,
    '2026-08-06 22:38:53'
  );

/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `members`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

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
  `id_front_url` varchar(255) DEFAULT NULL,
  `id_back_url` varchar(255) DEFAULT NULL,
  `membership_status` enum('pending', 'active', 'inactive') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `registered_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`member_id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `member_no` (`member_no`),
  KEY `fk_members_approver` (`approved_by`),
  KEY `idx_members_status` (`membership_status`),
  KEY `idx_members_name` (`full_name`),
  CONSTRAINT `fk_members_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE
  SET
    NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB AUTO_INCREMENT = 23 DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `members` WRITE;

/*!40000 ALTER TABLE `members` DISABLE KEYS */
;

INSERT INTO
  `members`
VALUES
  (
    1,
    1,
    'STAFF-001',
    'System Administrator',
    NULL,
    '09170000001',
    NULL,
    NULL,
    'Santiago City, Isabela',
    NULL,
    NULL,
    NULL,
    'active',
    NULL,
    '2026-08-05 21:06:08',
    '2026-08-05 21:06:08'
  ),
(
    2,
    2,
    'STAFF-002',
    'Lorna V. Fulong',
    NULL,
    '09170000002',
    NULL,
    NULL,
    'Santiago City, Isabela',
    NULL,
    NULL,
    NULL,
    'active',
    NULL,
    '2026-08-05 21:06:08',
    '2026-08-05 21:06:08'
  ),
(
    3,
    3,
    'STAFF-003',
    'Marjorie S. Magbitang',
    NULL,
    '09170000003',
    NULL,
    NULL,
    'Santiago City, Isabela',
    NULL,
    NULL,
    NULL,
    'active',
    NULL,
    '2026-08-05 21:06:08',
    '2026-08-05 21:06:08'
  ),
(
    10,
    10,
    'COOP-0001',
    'Juan D. Dela Cruz',
    NULL,
    '09171110001',
    NULL,
    NULL,
    'Brgy. Victory Norte, Santiago City',
    NULL,
    NULL,
    NULL,
    'active',
    1,
    '2026-08-05 21:06:09',
    '2026-08-05 21:06:09'
  ),
(
    11,
    11,
    'COOP-0002',
    'Maria L. Santos',
    NULL,
    '09171110002',
    NULL,
    NULL,
    'Brgy. Calao East, Santiago City',
    NULL,
    NULL,
    NULL,
    'active',
    1,
    '2026-08-05 21:06:09',
    '2026-08-05 21:06:09'
  ),
(
    12,
    12,
    'COOP-0003',
    'Pedro R. Reyes',
    NULL,
    '09171110003',
    NULL,
    NULL,
    'Brgy. Rizal, Santiago City',
    NULL,
    NULL,
    NULL,
    'active',
    1,
    '2026-08-05 21:06:09',
    '2026-08-05 21:06:09'
  ),
(
    13,
    13,
    'COOP-0004',
    'Ana M. Lim',
    NULL,
    '09171110004',
    NULL,
    NULL,
    'Brgy. Dubinan East, Santiago City',
    NULL,
    NULL,
    NULL,
    'active',
    1,
    '2026-08-05 21:06:09',
    '2026-08-05 21:06:09'
  ),
(
    14,
    14,
    'COOP-0005',
    'Rosa Aquino',
    NULL,
    '09175143899',
    NULL,
    NULL,
    'Brgy. Malvar, Santiago City',
    NULL,
    NULL,
    NULL,
    'active',
    1,
    '2026-08-05 21:06:09',
    '2026-08-05 21:06:09'
  ),
(
    15,
    15,
    'COOP-0006',
    'Benito C. Torres',
    NULL,
    '09171110006',
    NULL,
    NULL,
    'Brgy. Sinsayon, Santiago City',
    NULL,
    NULL,
    NULL,
    'active',
    1,
    '2026-08-05 21:06:09',
    '2026-08-05 21:06:09'
  ),
(
    16,
    16,
    'COOP-0007',
    'Lina P. Bautista',
    NULL,
    '09171110007',
    NULL,
    NULL,
    'Brgy. Patul, Santiago City',
    NULL,
    NULL,
    NULL,
    'active',
    1,
    '2026-08-05 21:06:09',
    '2026-08-05 21:06:09'
  ),
(
    17,
    17,
    'COOP-0008',
    'Carlos V. Mendoza',
    NULL,
    '09171110008',
    NULL,
    NULL,
    'Brgy. Batal, Santiago City',
    NULL,
    NULL,
    NULL,
    'active',
    1,
    '2026-08-05 21:06:09',
    '2026-08-05 21:06:09'
  ),
(
    18,
    18,
    'COOP-0009',
    'Grace A. Villanueva',
    NULL,
    '09171110009',
    NULL,
    NULL,
    'Brgy. Nabbuan, Santiago City',
    NULL,
    NULL,
    NULL,
    'active',
    1,
    '2026-08-06 14:17:12',
    '2026-08-05 21:06:09'
  );

/*!40000 ALTER TABLE `members` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `otp_tokens`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

CREATE TABLE `otp_tokens` (
  `otp_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `purpose` enum('login', 'ballot') NOT NULL DEFAULT 'login',
  `channel` enum('email', 'sms') NOT NULL DEFAULT 'email',
  `code_hash` varchar(255) NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`otp_id`),
  KEY `idx_otp_lookup` (`user_id`, `purpose`, `is_used`, `expires_at`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB AUTO_INCREMENT = 17 DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `otp_tokens` WRITE;

/*!40000 ALTER TABLE `otp_tokens` DISABLE KEYS */
;

INSERT INTO
  `otp_tokens`
VALUES
  (
    1,
    10,
    'login',
    'email',
    '0fe16b7e1326ade9e32565ea9309e80baf2da5030cddd07e2ff18b2b545f8765',
    0,
    '2026-08-05 21:18:01',
    1,
    '2026-08-05 21:08:01'
  ),
(
    2,
    2,
    'login',
    'email',
    '6dbfe6f442430e5f138d1e68b3e572e98b3758f188ea0e002aaedc7da94004d6',
    0,
    '2026-08-05 21:18:02',
    1,
    '2026-08-05 21:08:02'
  ),
(
    3,
    1,
    'login',
    'email',
    'ef6dc601a58a6c7ceb954df27bfd9de538ba61aba17189b558aa1b90af262784',
    0,
    '2026-08-05 21:18:02',
    1,
    '2026-08-05 21:08:02'
  ),
(
    4,
    3,
    'login',
    'email',
    '3afa83d2e9b4ac370ec27b53e2002e7a2f0778d0d6b1a0fbb27d56999afb01a9',
    0,
    '2026-08-05 21:18:03',
    1,
    '2026-08-05 21:08:03'
  ),
(
    8,
    10,
    'login',
    'email',
    '78e48cfe3e6aebd93a1d990a38719ff5897207094b8d3075014b455083e214f2',
    0,
    '2026-08-06 13:48:07',
    1,
    '2026-08-06 13:43:07'
  ),
(
    9,
    2,
    'login',
    'email',
    'dd8886144bc8af79ab64df6d5a30ca00789c56416503a92aacaccc9d3d4a83e7',
    0,
    '2026-08-06 13:48:10',
    1,
    '2026-08-06 13:43:10'
  ),
(
    10,
    1,
    'login',
    'email',
    '6c2f42de92e43c3a927b5f387cdce4a38a9afb2b292da438ae54da420544fa06',
    0,
    '2026-08-06 13:48:14',
    1,
    '2026-08-06 13:43:14'
  ),
(
    11,
    3,
    'login',
    'email',
    'e10167f1267d7e251f7d672a67a321af2ff90a033f1d76a5f7a5e07144ff4708',
    0,
    '2026-08-06 13:48:18',
    1,
    '2026-08-06 13:43:18'
  ),
(
    13,
    10,
    'login',
    'email',
    'cd94963030fb2dc2d9420dea516b54fef2b799f109139236afbf492349689ced',
    0,
    '2026-08-06 14:23:19',
    1,
    '2026-08-06 14:18:19'
  ),
(
    14,
    2,
    'login',
    'email',
    '818b247f082b0e0873bac50227d5147d42dfbf83eb320e426d9fe5fcf46208de',
    0,
    '2026-08-06 14:23:23',
    1,
    '2026-08-06 14:18:23'
  ),
(
    15,
    1,
    'login',
    'email',
    'a9f1c3e289f035c3e938817cd3ca877b232478957a404ecc8b2d8043518dafb3',
    0,
    '2026-08-06 14:23:26',
    1,
    '2026-08-06 14:18:26'
  ),
(
    16,
    3,
    'login',
    'email',
    '78d8ab129010a7dbf58ba8ef88b3c2ac78a3304121f1192fd3a4ac7d152ba326',
    0,
    '2026-08-06 14:23:30',
    1,
    '2026-08-06 14:18:30'
  );

/*!40000 ALTER TABLE `otp_tokens` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `password_resets`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

CREATE TABLE `password_resets` (
  `reset_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reset_id`),
  KEY `idx_reset_lookup` (`user_id`, `used_at`),
  CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `password_resets` WRITE;

/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */
;

/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `positions`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

CREATE TABLE `positions` (
  `position_id` int(11) NOT NULL AUTO_INCREMENT,
  `election_id` int(11) NOT NULL,
  `position_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `max_vote` int(11) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`position_id`),
  UNIQUE KEY `uq_position_per_election` (`election_id`, `position_name`),
  KEY `idx_positions_order` (`election_id`, `sort_order`),
  CONSTRAINT `fk_positions_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_position_maxvote` CHECK (`max_vote` = 1)
) ENGINE = InnoDB AUTO_INCREMENT = 9 DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `positions` WRITE;

/*!40000 ALTER TABLE `positions` DISABLE KEYS */
;

INSERT INTO
  `positions`
VALUES
  (
    1,
    1,
    'Chairperson',
    'Presides over the Board of Directors and the General Assembly.',
    1,
    1
  ),
(
    2,
    1,
    'Vice Chairperson',
    'Assumes the duties of the Chairperson in their absence.',
    1,
    2
  ),
(
    3,
    1,
    'Treasurer',
    'Custodian of cooperative funds and financial records.',
    1,
    3
  ),
(
    4,
    1,
    'Secretary',
    'Keeps minutes, records and official correspondence.',
    1,
    4
  ),
(
    5,
    2,
    'Audit Committee Chair',
    'Leads the internal audit of cooperative operations.',
    1,
    1
  );

/*!40000 ALTER TABLE `positions` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `roles`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `slug` varchar(30) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE = InnoDB AUTO_INCREMENT = 9 DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `roles` WRITE;

/*!40000 ALTER TABLE `roles` DISABLE KEYS */
;

INSERT INTO
  `roles`
VALUES
  (
    1,
    'Administrator',
    'admin',
    'Manages users, roles, reports, backups and audit logs'
  ),
(
    2,
    'Election Officer',
    'officer',
    'Creates elections, positions, candidates; releases results'
  ),
(
    3,
    'Voter',
    'voter',
    'Cooperative member who casts a ballot'
  ),
(
    4,
    'Auditor',
    'auditor',
    'Read-only oversight of audit logs and results'
  );

/*!40000 ALTER TABLE `roles` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `settings`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

CREATE TABLE `settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `settings` WRITE;

/*!40000 ALTER TABLE `settings` DISABLE KEYS */
;

INSERT INTO
  `settings`
VALUES
  (
    'coop_name',
    'Santiago City Multi-Purpose Cooperative',
    '2026-08-05 21:06:09'
  ),
('coop_short', 'SCMPC', '2026-08-06 14:17:12'),
('last_backup_at', NULL, '2026-08-05 21:06:09'),
(
    'support_email',
    'support@coopvote.test',
    '2026-08-05 21:06:09'
  );

/*!40000 ALTER TABLE `settings` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `users`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` int(11) NOT NULL,
  `status` enum(
    'pending_verification',
    'pending_approval',
    'active',
    'suspended',
    'deactivated',
    'rejected'
  ) NOT NULL DEFAULT 'pending_verification',
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
) ENGINE = InnoDB AUTO_INCREMENT = 23 DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `users` WRITE;

/*!40000 ALTER TABLE `users` DISABLE KEYS */
;

INSERT INTO
  `users`
VALUES
  (
    1,
    'admin',
    'admin@coopvote.test',
    '$2y$10$52U1Eycdz1Aey24cQGFSDuq03hrIKLjlOUp3EpifwuvOveLkaZ.by',
    1,
    'active',
    '2026-08-05 21:06:08',
    NULL,
    0,
    NULL,
    '2026-08-06 20:46:47',
    '2026-08-05 21:06:08',
    '2026-08-06 20:46:47'
  ),
(
    2,
    'officer',
    'officer@coopvote.test',
    '$2y$10$6eBp82wN8izGz4HaIweWOOyXGDSNsF7l/QVtgUC5FbCQoPKjdXRP.',
    2,
    'active',
    '2026-08-05 21:06:08',
    NULL,
    0,
    NULL,
    '2026-08-06 20:49:51',
    '2026-08-05 21:06:08',
    '2026-08-06 20:49:51'
  ),
(
    3,
    'auditor',
    'auditor@coopvote.test',
    '$2y$10$ZRjLdLcjfSR9amFiOmVcqed0g/NHakPieomNlsMULuuchUdpUmTbG',
    4,
    'active',
    '2026-08-05 21:06:08',
    NULL,
    0,
    NULL,
    '2026-08-06 20:32:29',
    '2026-08-05 21:06:08',
    '2026-08-06 20:32:29'
  ),
(
    10,
    'voter1',
    'voter1@coopvote.test',
    '$2y$10$prfuTryqAtwBX9TAuBZfveBxNI9lTR2a3wD9DuxmiMeVpS5LppWeK',
    3,
    'active',
    '2026-08-05 21:06:08',
    NULL,
    0,
    NULL,
    '2026-08-06 22:38:53',
    '2026-08-05 21:06:08',
    '2026-08-06 22:38:53'
  ),
(
    11,
    'voter2',
    'voter2@coopvote.test',
    '$2y$10$VMOnUQwKNv2/JN18/Mv1qO3x1Eg7uedhxSkVWgsba7JBqfl1g5p8O',
    3,
    'active',
    '2026-08-05 21:06:08',
    NULL,
    0,
    NULL,
    '2026-08-06 20:45:33',
    '2026-08-05 21:06:08',
    '2026-08-06 20:45:33'
  ),
(
    12,
    'voter3',
    'voter3@coopvote.test',
    '$2y$10$50ah.YvVlyUbevuRIa9/uO9HPyiLoTqA45mPdRW2NwAEO89PhR6JK',
    3,
    'active',
    '2026-08-05 21:06:08',
    NULL,
    0,
    NULL,
    '2026-08-06 22:33:38',
    '2026-08-05 21:06:08',
    '2026-08-06 22:33:38'
  ),
(
    13,
    'voter4',
    'voter4@coopvote.test',
    '$2y$10$ze2lZxqDlbRyZeKha8atDO2LOFnE8m09xvaTAQSqqZK3XziEE2D5C',
    3,
    'active',
    '2026-08-05 21:06:08',
    NULL,
    0,
    NULL,
    NULL,
    '2026-08-05 21:06:08',
    '2026-08-06 20:30:53'
  ),
(
    14,
    'voter5',
    'voter5@coopvote.test',
    '$2y$10$ldaJfJhF3SBJ7wvKF16Bh.IvOa4FTBR3YToZU69gwv7UuSPdfy65a',
    3,
    'active',
    '2026-08-05 21:06:08',
    NULL,
    0,
    NULL,
    NULL,
    '2026-08-05 21:06:08',
    '2026-08-06 20:30:54'
  ),
(
    15,
    'voter6',
    'voter6@coopvote.test',
    '$2y$10$C.5kXDPqyQLGEpu1JrOTtulYbM5Asu0dr8VtBcjZ6kEhoj0Go4i1i',
    3,
    'active',
    '2026-08-05 21:06:08',
    NULL,
    0,
    NULL,
    NULL,
    '2026-08-05 21:06:08',
    '2026-08-06 20:30:54'
  ),
(
    16,
    'voter7',
    'voter7@coopvote.test',
    '$2y$10$NTazr06cWA458np0ALlsh.yXhNLPfsZZF2iC6NLTki974y3lkElI6',
    3,
    'active',
    '2026-08-05 21:06:08',
    NULL,
    0,
    NULL,
    NULL,
    '2026-08-05 21:06:08',
    '2026-08-06 20:30:54'
  ),
(
    17,
    'voter8',
    'voter8@coopvote.test',
    '$2y$10$9SQkHc8D7YU1kzSXfiKKiekNtv3KUTwxaPAWNA2uKefYKTeutU7iS',
    3,
    'active',
    '2026-08-05 21:06:08',
    NULL,
    0,
    NULL,
    NULL,
    '2026-08-05 21:06:08',
    '2026-08-06 20:30:54'
  ),
(
    18,
    'pending1',
    'pending1@coopvote.test',
    '$2y$10$yXqJPeNTc4W7QIbFvK3ruOk23nE0LSQz9bngUGbjfWtcG0Adjk24.',
    3,
    'active',
    '2026-08-05 21:06:08',
    NULL,
    0,
    NULL,
    NULL,
    '2026-08-05 21:06:08',
    '2026-08-06 20:30:54'
  );

/*!40000 ALTER TABLE `users` ENABLE KEYS */
;

UNLOCK TABLES;

DROP TABLE IF EXISTS `votes`;

/*!40101 SET @saved_cs_client     = @@character_set_client */
;

/*!40101 SET character_set_client = utf8 */
;

CREATE TABLE `votes` (
  `vote_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `election_id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  `candidate_id` int(11) NOT NULL,
  `cast_date` date NOT NULL,
  PRIMARY KEY (`vote_id`),
  KEY `fk_votes_position` (`position_id`),
  KEY `fk_votes_candidate` (`candidate_id`),
  KEY `idx_votes_tally` (`election_id`, `position_id`, `candidate_id`),
  CONSTRAINT `fk_votes_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`candidate_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_votes_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_votes_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`position_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB AUTO_INCREMENT = 14 DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/*!40101 SET character_set_client = @saved_cs_client */
;

LOCK TABLES `votes` WRITE;

/*!40000 ALTER TABLE `votes` DISABLE KEYS */
;

/*!40000 ALTER TABLE `votes` ENABLE KEYS */
;

UNLOCK TABLES;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */
;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */
;

/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */
;

/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */
;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */
;

/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */
;

/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */
;

/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */
;
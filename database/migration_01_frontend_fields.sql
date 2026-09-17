-- ============================================================
--  CoopVote — Migration 01: fields required by the UI
--  ------------------------------------------------------------
--  ADDITIVE ONLY. Creates nothing that drops or rewrites existing
--  data. Safe to re-run (uses IF NOT EXISTS — MariaDB 10.0+).
--
--  Run:  mysql -u root coopvote < migration_01_frontend_fields.sql
-- ============================================================

-- ------------------------------------------------------------
-- members: profile fields the voter profile + candidate pages need
-- ------------------------------------------------------------
ALTER TABLE `members`
  ADD COLUMN IF NOT EXISTS `date_of_birth`  DATE          NULL AFTER `full_name`,
  ADD COLUMN IF NOT EXISTS `occupation`     VARCHAR(100)  NULL AFTER `contact_no`,
  ADD COLUMN IF NOT EXISTS `years_in_coop`  INT           NULL AFTER `occupation`,
  ADD COLUMN IF NOT EXISTS `photo_url`      VARCHAR(255)  NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `id_front_url`   VARCHAR(255)  NULL AFTER `photo_url`,
  ADD COLUMN IF NOT EXISTS `id_back_url`    VARCHAR(255)  NULL AFTER `id_front_url`;

-- ------------------------------------------------------------
-- candidates: biography + active flag (platform/photo already exist)
-- ------------------------------------------------------------
ALTER TABLE `candidates`
  ADD COLUMN IF NOT EXISTS `bio`       TEXT         NULL AFTER `platform`,
  ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1)   NOT NULL DEFAULT 1 AFTER `sort_order`;

-- ------------------------------------------------------------
-- ballots: request metadata for the security / monitoring views.
-- NOTE: this records THAT a member voted, never WHAT they voted.
-- Ballot secrecy is preserved — `votes` has no user_id by design.
-- ------------------------------------------------------------
ALTER TABLE `ballots`
  ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(45)  NULL AFTER `voted_at`,
  ADD COLUMN IF NOT EXISTS `user_agent` VARCHAR(255) NULL AFTER `ip_address`;

-- ------------------------------------------------------------
-- blocked_ips: used by admin/security-logs.html
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blocked_ips` (
  `block_id`   INT(11)      NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45)  NOT NULL,
  `reason`     VARCHAR(255) DEFAULT NULL,
  `blocked_by` INT(11)      DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`block_id`),
  UNIQUE KEY `uq_blocked_ip` (`ip_address`),
  KEY `fk_blocked_by` (`blocked_by`),
  CONSTRAINT `fk_blocked_by` FOREIGN KEY (`blocked_by`)
    REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- announcements: the notice banner on the voter dashboard
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
  `announcement_id` INT(11)      NOT NULL AUTO_INCREMENT,
  `title`           VARCHAR(150) NOT NULL,
  `message`         TEXT         NOT NULL,
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`      INT(11)      DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`announcement_id`),
  KEY `idx_ann_active` (`is_active`, `created_at`),
  KEY `fk_ann_creator` (`created_by`),
  CONSTRAINT `fk_ann_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- login_attempts: powers the security dashboard's failed-login
-- and suspicious-IP panels without scanning audit_logs text.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `attempt_id` BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(120) NOT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `success`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_attempt_ip` (`ip_address`, `created_at`),
  KEY `idx_attempt_success` (`success`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

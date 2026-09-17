-- CoopVote schema only - MariaDB 10.4+ / XAMPP
-- Import into a new, empty database selected in phpMyAdmin.
-- No account, configuration, image, or election records are included.
-- Application-compatible relational model with constrained election context.
-- See SCHEMA_NOTES.txt for intentional integrity/report snapshots.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @COOPVOTE_OLD_FK_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `admin_profiles` (
  `user_id` int(11) NOT NULL,
  `display_name` varchar(150) NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `admin_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  UNIQUE KEY `uq_candidate_per_election` (`member_id`,`election_id`),
  KEY `idx_candidates_position` (`position_id`,`sort_order`),
  KEY `idx_candidates_lookup` (`election_id`,`position_id`),
  CONSTRAINT `fk_candidates_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_candidates_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_candidates_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`position_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  CONSTRAINT `election_approval_requests_ibfk_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `election_integrity` (
  `election_id` int(11) NOT NULL,
  `vote_count` bigint(20) NOT NULL DEFAULT 0,
  `baseline_count` bigint(20) NOT NULL DEFAULT 0,
  `seal` char(64) NOT NULL,
  PRIMARY KEY (`election_id`),
  CONSTRAINT `fk_integrity_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `governance_config` (
  `id` tinyint(4) NOT NULL,
  `required_approvals` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE `member_profile_photo_chunks` (
  `member_id` int(11) NOT NULL,
  `chunk_no` smallint(5) unsigned NOT NULL,
  `photo_data` blob NOT NULL,
  PRIMARY KEY (`member_id`,`chunk_no`),
  CONSTRAINT `fk_profile_photo_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notification_reads` (
  `user_id` int(11) NOT NULL,
  `notice_id` varchar(160) NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`notice_id`),
  CONSTRAINT `fk_notice_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE `positions` (
  `position_id` int(11) NOT NULL AUTO_INCREMENT,
  `election_id` int(11) NOT NULL,
  `position_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `max_vote` int(11) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`position_id`),
  UNIQUE KEY `uq_position_per_election` (`election_id`,`position_name`),
  KEY `idx_positions_order` (`election_id`,`sort_order`),
  CONSTRAINT `fk_positions_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_position_maxvote` CHECK (`max_vote` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `slug` varchar(30) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `security_alert_reviews` (
  `alert_key` char(64) NOT NULL,
  `reviewed_by` int(11) NOT NULL,
  `reviewed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`alert_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_preferences` (
  `user_id` int(11) NOT NULL,
  `theme` varchar(20) NOT NULL DEFAULT 'navy',
  PRIMARY KEY (`user_id`),
  CONSTRAINT `user_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `lockout_count` int(11) NOT NULL DEFAULT 0 COMMENT 'How many times this account has been locked since the last successful sign-in',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  CONSTRAINT `fk_votes_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`candidate_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_votes_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`election_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_votes_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`position_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Context keys prevent contradictory election / position / candidate references.
ALTER TABLE positions
  ADD UNIQUE KEY uq_position_election (position_id, election_id);
ALTER TABLE candidates
  ADD UNIQUE KEY uq_candidate_context (candidate_id, position_id, election_id),
  ADD CONSTRAINT fk_candidate_position_context FOREIGN KEY (position_id, election_id)
    REFERENCES positions (position_id, election_id) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE votes
  ADD CONSTRAINT fk_vote_candidate_context FOREIGN KEY (candidate_id, position_id, election_id)
    REFERENCES candidates (candidate_id, position_id, election_id) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE security_alert_reviews
  ADD CONSTRAINT fk_alert_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (user_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE governance_config
  ADD CONSTRAINT chk_governance_singleton CHECK (id = 1),
  ADD CONSTRAINT chk_required_approvals CHECK (required_approvals >= 1);
ALTER TABLE election_approval_requests
  ADD CONSTRAINT chk_request_approvals CHECK (required_approvals >= 1);

SET FOREIGN_KEY_CHECKS = @COOPVOTE_OLD_FK_CHECKS;

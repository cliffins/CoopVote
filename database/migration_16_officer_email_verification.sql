-- Additive migration. Existing accounts, votes and election records are unchanged.
CREATE TABLE IF NOT EXISTS `officer_email_verifications` (
    `user_id` INT(11) NOT NULL,
    `purpose` ENUM('invite','email_change') NOT NULL,
    `pending_email` VARCHAR(100) NOT NULL,
    `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `requested_by` INT(11) DEFAULT NULL,
    `requested_at` DATETIME NOT NULL,
    `window_started_at` DATETIME NOT NULL,
    `send_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `delivery_status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    `consumed_at` DATETIME DEFAULT NULL,
    `verified_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_officer_verification_token (token_hash),
    CONSTRAINT fk_officer_verification_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_officer_verification_requester FOREIGN KEY (requested_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

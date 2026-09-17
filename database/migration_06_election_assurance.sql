-- Additive migration: run tools/upgrade_assurance.php after backing up the database.
-- No identities, receipts, IP addresses or precise vote times belong in vote_integrity.
CREATE TABLE IF NOT EXISTS election_integrity (
  election_id INT NOT NULL PRIMARY KEY,
  vote_count BIGINT NOT NULL DEFAULT 0,
  baseline_count BIGINT NOT NULL DEFAULT 0,
  seal CHAR(64) NOT NULL,
  CONSTRAINT fk_integrity_election FOREIGN KEY (election_id) REFERENCES elections(election_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS vote_integrity (
  vote_id BIGINT NOT NULL PRIMARY KEY,
  election_id INT NOT NULL,
  nonce CHAR(32) NOT NULL,
  signature CHAR(64) NOT NULL,
  origin VARCHAR(12) NOT NULL,
  KEY idx_integrity_election (election_id),
  CONSTRAINT fk_protected_election FOREIGN KEY (election_id) REFERENCES elections(election_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS election_reports (
  election_id INT NOT NULL PRIMARY KEY,
  snapshot LONGTEXT NOT NULL,
  signature CHAR(64) NOT NULL,
  superseded_at DATETIME DEFAULT NULL,
  history LONGTEXT DEFAULT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_report_election FOREIGN KEY (election_id) REFERENCES elections(election_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS notification_reads (
  user_id INT NOT NULL,
  notice_id VARCHAR(160) NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, notice_id),
  CONSTRAINT fk_notice_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS security_alert_reviews (
  alert_key CHAR(64) NOT NULL PRIMARY KEY,
  reviewed_by INT NOT NULL,
  reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

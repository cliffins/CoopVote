CREATE TABLE IF NOT EXISTS governance_config (
 id TINYINT PRIMARY KEY, required_approvals INT NOT NULL DEFAULT 1
) ENGINE=InnoDB;
INSERT IGNORE INTO governance_config (id) VALUES (1);
CREATE TABLE IF NOT EXISTS election_approval_requests (
 request_id INT AUTO_INCREMENT PRIMARY KEY, election_id INT NOT NULL, fingerprint CHAR(64) NOT NULL,
 required_approvals INT NOT NULL, requested_by INT NOT NULL, proposed_end DATETIME NULL,
 state ENUM('pending','opened','superseded') NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (election_id) REFERENCES elections(election_id) ON DELETE CASCADE,
 FOREIGN KEY (requested_by) REFERENCES users(user_id), KEY idx_request_election(election_id,state)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS election_admin_decisions (
 request_id INT NOT NULL, admin_id INT NOT NULL, decision ENUM('approve','reject') NOT NULL,
 note VARCHAR(1000) NULL, decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(request_id,admin_id),
 FOREIGN KEY(request_id) REFERENCES election_approval_requests(request_id) ON DELETE CASCADE,
 FOREIGN KEY(admin_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

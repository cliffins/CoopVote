-- Additive account/profile preferences only; Administrator and Voter remain the only roles.
CREATE TABLE IF NOT EXISTS admin_profiles (
 user_id INT PRIMARY KEY, display_name VARCHAR(150) NOT NULL,
 FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS user_preferences (
 user_id INT PRIMARY KEY, theme VARCHAR(20) NOT NULL DEFAULT 'navy',
 FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

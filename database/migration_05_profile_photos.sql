-- CoopVote Migration 05: store voter profile pictures in MariaDB.
-- Safe to run more than once. Existing photo_url values remain as legacy fallbacks.
-- Run on the live host after taking a backup:
--   mysql -u YOUR_USER -p YOUR_DATABASE < database/migration_05_profile_photos.sql

ALTER TABLE `members`
  ADD COLUMN IF NOT EXISTS `profile_photo` MEDIUMBLOB NULL AFTER `photo_url`,
  ADD COLUMN IF NOT EXISTS `profile_photo_mime` VARCHAR(32) NULL AFTER `profile_photo`,
  ADD COLUMN IF NOT EXISTS `profile_photo_updated_at` DATETIME NULL AFTER `profile_photo_mime`;

CREATE TABLE IF NOT EXISTS `member_profile_photo_chunks` (
  `member_id` INT(11) NOT NULL,
  `chunk_no` SMALLINT UNSIGNED NOT NULL,
  `photo_data` BLOB NOT NULL,
  PRIMARY KEY (`member_id`, `chunk_no`),
  CONSTRAINT `fk_profile_photo_member` FOREIGN KEY (`member_id`)
    REFERENCES `members` (`member_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

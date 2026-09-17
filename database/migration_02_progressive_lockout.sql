-- ============================================================
--  CoopVote — Migration 02: progressive account lockout
--  ------------------------------------------------------------
--  Each successive lockout on the same account lasts longer, so
--  the app has to remember how many times it has locked already.
--  A single flat duration cannot express that.
--
--  ADDITIVE ONLY. Safe to re-run (MariaDB 10.0+).
--
--  Run:  mysql -u root coopvote < migration_02_progressive_lockout.sql
-- ============================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `lockout_count` INT(11) NOT NULL DEFAULT 0
    COMMENT 'How many times this account has been locked since the last successful sign-in'
    AFTER `failed_attempts`;

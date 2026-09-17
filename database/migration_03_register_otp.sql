-- ============================================================
--  CoopVote — Migration 03: e-mail OTP at registration
--  ------------------------------------------------------------
--  otp_tokens.purpose only allowed 'login' and 'ballot'. Registration
--  needs its own purpose so a code issued to verify an e-mail address
--  can never be replayed against a different flow.
--
--  ADDITIVE ONLY — widening an enum keeps every existing row valid.
--
--  Run:  mysql -u root coopvote < migration_03_register_otp.sql
-- ============================================================

ALTER TABLE `otp_tokens`
  MODIFY COLUMN `purpose` ENUM('login','ballot','register')
    NOT NULL DEFAULT 'login';

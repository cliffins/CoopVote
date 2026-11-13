-- Add academic details without changing existing candidate or member data.
ALTER TABLE candidates
  ADD COLUMN IF NOT EXISTS section varchar(100) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS year_level varchar(32) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS department varchar(150) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS course varchar(150) NOT NULL DEFAULT '';

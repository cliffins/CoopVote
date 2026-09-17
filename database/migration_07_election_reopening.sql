-- Preserve earlier signed closing reports when an election is reopened.
ALTER TABLE election_reports
  ADD COLUMN IF NOT EXISTS superseded_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS history LONGTEXT DEFAULT NULL;

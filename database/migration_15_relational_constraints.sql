-- Align existing installations with constraints already in schema_only.sql.
-- Back up first and check for incompatible rows. DDL commits independently.
-- Apply once to the legacy schema; do not run after importing schema_only.sql.
-- No records, signed fields, tables, or application columns are changed.
ALTER TABLE positions
  ADD UNIQUE KEY uq_position_election (position_id, election_id),
  ADD CONSTRAINT chk_position_maxvote CHECK (max_vote = 1);
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
ALTER TABLE elections
  ADD CONSTRAINT chk_election_dates CHECK (end_datetime > start_datetime);

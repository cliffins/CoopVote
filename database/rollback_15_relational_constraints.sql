-- Revert only migration 15's added constraints after a complete application.
-- This removes database protections; existing rows and legacy keys remain.
ALTER TABLE votes DROP FOREIGN KEY fk_vote_candidate_context;
ALTER TABLE candidates DROP FOREIGN KEY fk_candidate_position_context;
ALTER TABLE candidates DROP INDEX uq_candidate_context;
ALTER TABLE positions DROP INDEX uq_position_election;
ALTER TABLE positions DROP CONSTRAINT chk_position_maxvote;
ALTER TABLE security_alert_reviews DROP FOREIGN KEY fk_alert_reviewer;
ALTER TABLE governance_config DROP CONSTRAINT chk_governance_singleton;
ALTER TABLE governance_config DROP CONSTRAINT chk_required_approvals;
ALTER TABLE election_approval_requests DROP CONSTRAINT chk_request_approvals;
ALTER TABLE elections DROP CONSTRAINT chk_election_dates;

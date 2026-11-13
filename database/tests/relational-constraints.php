<?php
/** Synthetic upgrade/rollback coverage. Never accepts a production database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COOPVOTE_ENV') !== 'test') exit(1);
require dirname(__DIR__, 2) . '/includes/helpers.php';
if (DB_NAME !== 'coopvote_test' || !str_starts_with(DB_SETTINGS['socket'], '/tmp/coopvote-db-tests-')
    || basename(DB_SETTINGS['socket']) !== 'mysql.sock') throw new RuntimeException('Disposable fixture required.');
require dirname(__DIR__, 2) . '/includes/vote-integrity.php';
require dirname(__DIR__, 2) . '/includes/privacy-migration.php';
require dirname(__DIR__, 2) . '/includes/sql-import.php';
$p = db();
$checks = 0;
function constraint_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
}
function constraint_reject(PDO $p, string $sql, string $label): void {
    $p->beginTransaction();
    try {
        try { $p->exec($sql); $rejected = false; }
        catch (PDOException $e) {
            if (!in_array((int) ($e->errorInfo[1] ?? 0), [1451, 1452, 4025], true)) throw $e;
            $rejected = true;
        }
        constraint_check($rejected, $label);
    } finally { $p->rollBack(); }
}
$seed = privacy_database_fingerprint($p);
$sequences = $p->query('SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND AUTO_INCREMENT IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
$p->beginTransaction();
foreach ([900001 => 1, 900002 => 2] as $id => $memberId) {
    $p->exec("INSERT INTO elections(election_id,title,start_datetime,end_datetime,status,created_by)
        VALUES($id,'Constraint fixture','2026-01-01','2026-01-02','draft',1)");
    $p->exec("INSERT INTO positions(position_id,election_id,position_name) VALUES($id,$id,'Test position')");
    $p->exec("INSERT INTO candidates(candidate_id,member_id,position_id,election_id) VALUES($id,$memberId,$id,$id)");
    $p->prepare('INSERT INTO election_integrity(election_id,seal) VALUES(?,?)')
        ->execute([$id, integrity_state_seal($id, 0, 0)]);
}
integrity_insert_selections($p, 900001, [900001 => 900001], integrity_state($p, 900001));
$p->commit();
$before = privacy_database_fingerprint($p);
$tally = privacy_tally($p);
$up = file_get_contents(dirname(__DIR__) . '/migration_15_relational_constraints.sql');
$down = file_get_contents(dirname(__DIR__) . '/rollback_15_relational_constraints.sql');
// Simulate an existing installation lacking the new constraints.
sql_import($p, $down);
constraint_check(privacy_database_fingerprint($p) === $before, 'legacy conversion preserves every row');
sql_import($p, $up);
constraint_check(privacy_database_fingerprint($p) === $before, 'upgrade preserves every row');
constraint_check(privacy_tally($p) === $tally, 'upgrade preserves vote totals');
constraint_check(integrity_verify(900001)['status'] === 'passed', 'existing HMAC signatures survive upgrade');
$names = ['uq_position_election','uq_candidate_context','fk_candidate_position_context',
    'fk_vote_candidate_context','fk_alert_reviewer','chk_governance_singleton',
    'chk_required_approvals','chk_request_approvals','chk_position_maxvote','chk_election_dates'];
$q = $p->query('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()');
constraint_check(!array_diff($names, $q->fetchAll(PDO::FETCH_COLUMN)), 'all ten constraints installed');
constraint_reject($p, 'UPDATE candidates SET election_id=900002 WHERE candidate_id=900001', 'candidate cannot cross elections');
constraint_reject($p, 'UPDATE votes SET position_id=900002 WHERE election_id=900001', 'vote cannot cross positions');
constraint_reject($p, 'UPDATE votes SET election_id=900002 WHERE election_id=900001', 'vote cannot cross elections');
constraint_reject($p, "INSERT INTO security_alert_reviews(alert_key,reviewed_by) VALUES('fixture',999999999)", 'reviewer must exist');
constraint_reject($p, 'INSERT INTO governance_config(id,required_approvals) VALUES(2,1)', 'governance configuration is a singleton');
constraint_reject($p, 'UPDATE governance_config SET required_approvals=0', 'configured threshold must be positive');
constraint_reject($p, "INSERT INTO election_approval_requests(election_id,fingerprint,required_approvals,requested_by) VALUES(900001,REPEAT('a',64),0,1)", 'request threshold must be positive');
constraint_reject($p, 'UPDATE positions SET max_vote=2 WHERE position_id=900001', 'one selection per position');
constraint_reject($p, 'UPDATE elections SET end_datetime=start_datetime WHERE election_id=900001', 'election dates must be ordered');
constraint_check(privacy_database_fingerprint($p) === $before, 'rejected writes do not change records');
sql_import($p, $down);
constraint_check(privacy_database_fingerprint($p) === $before, 'rollback preserves every row');
constraint_check(integrity_verify(900001)['status'] === 'passed', 'rollback preserves signatures');
sql_import($p, $up);
constraint_check(privacy_database_fingerprint($p) === $before, 'upgrade after rollback preserves every row');
$p->exec('DELETE FROM elections WHERE election_id IN (900001,900002)');
constraint_check(privacy_database_fingerprint($p) === $seed, 'cascade cleanup preserves seed accounts');
// Keep the existing endpoint fixture IDs stable after these temporary records.
foreach ($sequences as $table => $next) {
    $p->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` AUTO_INCREMENT=' . (int) $next);
}
echo "$checks/$checks relational migration checks PASS (upgrade, rejection, rollback, rows and signatures)\n";

<?php
/**
 * Export current, application-compatible DDL only.
 * Source access is limited to schema inspection; rows and credentials never
 * enter the deliverable. Validate its table inventory and empty state in a
 * newly created scratch database before replacing the published schema file.
 * Existing installs need a separate migration, not this fresh-install DDL.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/helpers.php';
require __DIR__.'/../includes/sql-import.php';

$source = db();
$tables = $source->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
$tables = array_values(array_diff($tables, ['password_resets', 'election_controls']));
sort($tables);
$sql = "-- CoopVote schema only - MariaDB 10.4+ / XAMPP\n"
    . "-- Import into a new, empty database selected in phpMyAdmin.\n"
    . "-- No account, configuration, image, or election records are included.\n"
    . "-- Application-compatible relational model with constrained election context.\n"
    . "-- See SCHEMA_NOTES.txt for intentional integrity/report snapshots.\n\n"
    . "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
    . "SET @COOPVOTE_OLD_FK_CHECKS = @@FOREIGN_KEY_CHECKS;\n"
    . "SET FOREIGN_KEY_CHECKS = 0;\n\n";
foreach ($tables as $table) {
    if (!preg_match('/^[a-z_]+$/', $table)) throw new RuntimeException('Unexpected table name.');
    $ddl = $source->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
    // Do not disclose live sequence counters or mix server-default encodings.
    $ddl = preg_replace('/\sAUTO_INCREMENT=\d+/', '', $ddl);
    $ddl = preg_replace('/DEFAULT CHARSET=\w+(?: COLLATE=\w+)?/', 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $ddl);
    $sql .= $ddl.";\n\n";
}
$sql .= <<<'SQL'
-- Context keys prevent contradictory election / position / candidate references.
ALTER TABLE positions
  ADD UNIQUE KEY uq_position_election (position_id, election_id);
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

SET FOREIGN_KEY_CHECKS = @COOPVOTE_OLD_FK_CHECKS;

SQL;
if (preg_match('/\b(?:INSERT|REPLACE|TRUNCATE|DROP)\b|\bCREATE\s+DATABASE\b|^\s*USE\s/im', $sql)) {
    throw new RuntimeException('Unexpected data or destructive statement in schema.');
}
// A unique name and CREATE without IF NOT EXISTS establish ownership before
// the finally block is allowed to delete the disposable validation database.
$scratch = 'coopvote_schemacheck_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if (!preg_match('/^coopvote_schemacheck_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $scratch) || $scratch === DB_NAME) {
    throw new RuntimeException('Unsafe scratch database name.');
}
$server = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE `'.$scratch.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $server->exec('USE `'.$scratch.'`');
    sql_import($server, $sql);
    $actual = $server->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($actual);
    if ($actual !== $tables) throw new RuntimeException('Table inventory differs.');
    foreach ($tables as $table) {
        if ((int)$server->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn() !== 0) {
            throw new RuntimeException('Schema contains row data.');
        }
    }
    if ((int)$server->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn() !== 1) {
        throw new RuntimeException('Foreign key enforcement was not restored.');
    }
} finally {
    $server->exec('DROP DATABASE `'.$scratch.'`');
}
$path = BASE_PATH.'/database/schema_only.sql';
if (file_put_contents($path, $sql) === false) throw new RuntimeException('Cannot write schema file.');
echo 'Verified '.count($tables).' tables, zero records, source database unchanged.'.PHP_EOL;
echo $path.PHP_EOL;

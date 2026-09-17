<?php
/** Add assurance tables and an explicit baseline, after an operator-created backup. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../api/routes/voter.php';
require_once __DIR__ . '/../includes/election-reports.php';
if (!in_array('--backup-confirmed', $argv, true)) exit("Back up the database first, then run with --backup-confirmed.\n");
$pdo = db();
$pdo->exec(file_get_contents(BASE_PATH . '/database/migration_06_election_assurance.sql'));
$pdo->exec(file_get_contents(BASE_PATH . '/database/migration_07_election_reopening.sql'));
if (DB_NAME !== 'coopvote_test' && !getenv('COOPVOTE_INTEGRITY_KEY') && !is_file(BASE_PATH . '/config/integrity-key.php')) {
    if ((int) $pdo->query('SELECT COUNT(*) FROM election_integrity')->fetchColumn()) {
        exit("Existing signatures found: restore the original key. A replacement key cannot verify them.\n");
    }
    $handle = fopen(BASE_PATH . '/config/integrity-key.php', 'x');
    if (!$handle) exit("Could not create the protected signing key.\n");
    fwrite($handle, '<?php /** Private vote integrity key. Preserve with backups. */ return ' . var_export(bin2hex(random_bytes(32)), true) . ';');
    fclose($handle);
}
integrity_key();
foreach ($pdo->query('SELECT election_id FROM elections')->fetchAll(PDO::FETCH_COLUMN) as $eid) {
    integrity_baseline($pdo, (int) $eid);
    $check = integrity_verify((int) $eid);
    if ($check['status'] !== 'passed') throw new RuntimeException('Existing integrity mismatch; no re-baseline was performed.');
}
election_reports_due();
echo "Assurance installed. Existing votes are explicitly marked as migration-baseline votes.\n";

<?php
/** Explicit read-only production snapshot. Outputs keyed digests only, never rows. */
declare(strict_types=1);
ini_set('display_errors','0');
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--read-only' || getenv('COOPVOTE_ENV') === 'test') exit(2);
try {
    $comparisonKey = stream_get_contents(STDIN);
    if (strlen($comparisonKey) !== 32) throw new RuntimeException('Comparison key unavailable');
    require dirname(__DIR__,2) . '/config/database.php';
    if (DB_NAME === 'coopvote_test' || DB_SETTINGS['environment'] !== 'production') throw new RuntimeException('Not production');
    $pdo=db();
    $tables=['votes','vote_integrity','election_integrity','elections','positions','candidates',
        'ballots','election_reports','election_approval_requests','election_admin_decisions'];
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $fingerprints=[];
    foreach ($tables as $table) {
        $rows=[];
        $statement=$pdo->query('SELECT * FROM `'.$table.'`');
        while ($row=$statement->fetch(PDO::FETCH_ASSOC)) {
            // Keys, receipt codes, IDs, and field values never leave this process.
            $rows[]=hash_hmac('sha256',serialize($row),$comparisonKey);
        }
        sort($rows,SORT_STRING);
        $fingerprints[$table]=hash_hmac('sha256',serialize($rows),$comparisonKey);
    }
    $pdo->rollBack();
    echo json_encode(['fingerprints'=>$fingerprints,'transaction'=>'READ ONLY','captured_at'=>date('c')],JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['error'=>'Read-only comparison unavailable; production unchanged must not be marked PASS.']),PHP_EOL;
    exit(2);
}

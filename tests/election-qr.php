<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('COOPVOTE_DB=coopvote_test');
putenv('COOPVOTE_MAIL=0');
require __DIR__.'/../includes/auth.php';
require __DIR__.'/lib/testdb.php';
require __DIR__.'/lib/http.php';
$pdo = tdb_connect();
tdb_guard((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
$original = $pdo->query('SELECT election_id,status,start_datetime,end_datetime FROM elections WHERE election_id IN (1,4)')->fetchAll();
if (count($original) !== 2) throw new RuntimeException('Seed scratch elections before running this test.');
try {
    $pdo->exec("UPDATE elections SET status='published', start_datetime=DATE_SUB(NOW(),INTERVAL 1 HOUR), end_datetime=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE election_id IN (1,4)");
    if (!TestServer::start(1,8097)) throw new RuntimeException('Scratch web server did not start.');
    passthru('node tests/election-qr.cjs', $status);
} finally {
    TestServer::stop();
    $restore = $pdo->prepare('UPDATE elections SET status=?,start_datetime=?,end_datetime=? WHERE election_id=?');
    foreach ($original as $row) $restore->execute([$row['status'],$row['start_datetime'],$row['end_datetime'],$row['election_id']]);
}
exit($status);

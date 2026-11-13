<?php
/** NFR21: actual stored-vote verifier, synthetic disposable database only. */
declare(strict_types=1);
ini_set('display_errors', '0');
if (PHP_SAPI !== 'cli' || getenv('COOPVOTE_ENV') !== 'test') exit(2);

$results = [];
function nfr21_result(string $id, string $name, array $expected, array $actual): void
{
    global $results;
    $results[] = ['id'=>$id, 'name'=>$name, 'expected'=>$expected, 'actual'=>$actual,
        'result'=>$expected === $actual ? 'PASS' : 'FAIL'];
}
function nfr21_summary(array $result): array
{
    return ['status'=>$result['status'], 'checkedVotes'=>$result['checkedVotes'],
        'mismatchedVotes'=>$result['mismatchedVotes']];
}
try {
    require dirname(__DIR__, 2) . '/includes/helpers.php';
    require_once dirname(__DIR__, 2) . '/includes/vote-integrity.php';
    $fixture = (string)getenv('COOPVOTE_NFR21_FIXTURE');
    if (!preg_match('~^/tmp/coopvote-db-tests-nfr21-[A-Za-z0-9_-]+$~D', $fixture)
        || !is_file($fixture . '/nfr21.marker') || DB_NAME !== 'coopvote_test'
        || DB_SETTINGS['socket'] !== $fixture . '/mysql.sock'
        || getenv('COOPVOTE_CONFIG_FILE') !== $fixture . '/config.php'
        || !preg_match('/^[a-f0-9]{64}$/D', (string)getenv('COOPVOTE_INTEGRITY_KEY'))) {
        throw new RuntimeException('Unsafe fixture');
    }
    $pdo = db();
    if (realpath((string)$pdo->query('SELECT @@datadir')->fetchColumn()) !== realpath($fixture . '/data')) {
        throw new RuntimeException('Unexpected database server');
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM elections')->fetchColumn() !== 0) {
        throw new RuntimeException('Fixture is not empty');
    }
    // seed.php creates synthetic accounts/members only in this disposable server.
    $pdo->exec("INSERT INTO elections(election_id,title,start_datetime,end_datetime,status,created_by)
        VALUES(2100,'Synthetic NFR21 fixture',DATE_SUB(NOW(),INTERVAL 1 HOUR),DATE_ADD(NOW(),INTERVAL 1 DAY),'published',1)");
    $pdo->exec("INSERT INTO positions(position_id,election_id,position_name) VALUES(2100,2100,'Synthetic position')");
    $pdo->exec('INSERT INTO candidates(candidate_id,member_id,position_id,election_id) VALUES(2100,1,2100,2100),(2101,2,2100,2100)');
    $pdo->prepare('INSERT INTO election_integrity(election_id,seal) VALUES(?,?)')->execute([2100,integrity_state_seal(2100,0,0)]);
    foreach ([2100,2101,2100] as $candidate) {
        $pdo->beginTransaction();
        try {
            $state = integrity_lock_live_election($pdo,2100);
            integrity_insert_selections($pdo,2100,[2100=>$candidate],$state);
            $pdo->commit();
        } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
    }
    $rows = $pdo->query('SELECT v.*,i.nonce,i.signature,i.origin FROM votes v JOIN vote_integrity i USING(vote_id,election_id) ORDER BY vote_id')->fetchAll();
    $hmacMatches = count($rows) === 3;
    foreach ($rows as $row) {
        $canonical = [(int)$row['vote_id'],(int)$row['election_id'],(int)$row['position_id'],
            (int)$row['candidate_id'],(string)$row['cast_date'],$row['nonce'],$row['origin']];
        // Match the application's literal backslash+n domain separator, not a newline.
        $expectedMac = hash_hmac('sha256','anonymous-vote-v1' . '\n' . json_encode($canonical,JSON_THROW_ON_ERROR),getenv('COOPVOTE_INTEGRITY_KEY'));
        $hmacMatches = $hmacMatches && strlen($row['signature']) === 64 && hash_equals($expectedMac,$row['signature']);
    }
    nfr21_result('NFR21-01','Legitimate stored votes',
        ['status'=>'passed','checkedVotes'=>3,'mismatchedVotes'=>0,'independent_hmac_sha256_match'=>true],
        nfr21_summary(integrity_verify(2100))+['independent_hmac_sha256_match'=>$hmacMatches]);

    $victim = $rows[0];
    $alternate = (int)$victim['candidate_id'] === 2100 ? 2101 : 2100;
    // Committed direct database edit: simulate an attacker without the signing key.
    $pdo->prepare('UPDATE votes SET candidate_id=? WHERE vote_id=?')->execute([$alternate,$victim['vote_id']]);
    try {
        $q=$pdo->prepare('SELECT signature FROM vote_integrity WHERE vote_id=?');$q->execute([$victim['vote_id']]);
        nfr21_result('NFR21-02','One signed vote field modified',
            ['status'=>'mismatch','checkedVotes'=>3,'mismatchedVotes'=>1,'original_signature_unchanged'=>true],
            nfr21_summary(integrity_verify(2100))+['original_signature_unchanged'=>hash_equals($victim['signature'],(string)$q->fetchColumn())]);
    } finally {
        $pdo->prepare('UPDATE votes SET candidate_id=? WHERE vote_id=?')->execute([$victim['candidate_id'],$victim['vote_id']]);
    }
    nfr21_result('NFR21-02-CONTROL','Original field restored without re-signing',
        ['status'=>'passed','checkedVotes'=>3,'mismatchedVotes'=>0],nfr21_summary(integrity_verify(2100)));

    $original = $pdo->query('SELECT * FROM election_integrity WHERE election_id=2100')->fetch();
    $pdo->exec('UPDATE election_integrity SET vote_count=vote_count+1 WHERE election_id=2100');
    try {
        $changed=$pdo->query('SELECT * FROM election_integrity WHERE election_id=2100')->fetch();
        $rejected=false;
        $pdo->beginTransaction();
        try { integrity_state($pdo,2100); }
        catch (RuntimeException $error) { $rejected=$error->getMessage()==='The signed vote count does not match. Administrator review is required.'; }
        finally { $pdo->rollBack(); }
        nfr21_result('NFR21-03','Signed count changed without updating seal',
            ['status'=>'mismatch','checkedVotes'=>3,'mismatchedVotes'=>0,'stored_count'=>4,'original_seal_unchanged'=>true,'write_guard_rejected'=>true],
            nfr21_summary(integrity_verify(2100))+['stored_count'=>(int)$changed['vote_count'],
                'original_seal_unchanged'=>hash_equals($original['seal'],$changed['seal']),'write_guard_rejected'=>$rejected]);
    } finally {
        $pdo->prepare('UPDATE election_integrity SET vote_count=? WHERE election_id=2100')->execute([$original['vote_count']]);
    }
    nfr21_result('NFR21-03-CONTROL','Original count restored without re-sealing',
        ['status'=>'passed','checkedVotes'=>3,'mismatchedVotes'=>0],nfr21_summary(integrity_verify(2100)));
    echo json_encode(['cases'=>$results,'php_version'=>PHP_VERSION,'database_version'=>$pdo->query('SELECT VERSION()')->fetchColumn()],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
    exit(count(array_filter($results,static fn($r)=>$r['result']!=='PASS')) ? 1 : 0);
} catch (Throwable $error) {
    // Never emit connection strings, records, keys, exception traces, or credentials.
    echo json_encode(['cases'=>$results,'execution_error'=>'NFR21 execution stopped; no unexecuted case is marked PASS.']),PHP_EOL;
    exit(2);
}

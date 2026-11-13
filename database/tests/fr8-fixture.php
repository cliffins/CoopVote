<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COOPVOTE_ENV') !== 'test') exit(1);
require dirname(__DIR__, 2).'/includes/helpers.php';
require dirname(__DIR__, 2).'/api/routes/voter.php';
if (DB_NAME !== 'coopvote_test' || !str_starts_with(DB_SETTINGS['socket'], '/tmp/coopvote-db-tests-') || basename(DB_SETTINGS['socket']) !== 'mysql.sock') {
    throw new RuntimeException('Disposable fixture required.');
}
$pdo = db();
$snapshot = dirname(DB_SETTINGS['socket']).'/fr8-snapshot.json';
function fr8_records(PDO $pdo): array {
    $out = [];
    foreach (['votes'=>'vote_id','ballots'=>'ballot_id','candidates'=>'candidate_id','positions'=>'position_id','vote_integrity'=>'vote_id'] as $table=>$key) {
        $rows = $pdo->query("SELECT * FROM $table ORDER BY $key")->fetchAll();
        $out[$table] = ['rows'=>count($rows),'sha256'=>hash('sha256',json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    return $out;
}
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $insert = $pdo->prepare('INSERT INTO elections(election_id,title,start_datetime,end_datetime,status,created_by) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 2 DAY),DATE_ADD(NOW(),INTERVAL 3 DAY),?,1)');
    foreach ([800=>'Upcoming reschedule',801=>'Active zero votes',802=>'Closed zero votes',803=>'Draft with abstention',804=>'Previously opened',805=>'Stale cancellation',806=>'Upcoming cancellation',807=>'Draft cancellation'] as $id=>$title) {
        $insert->execute([$id,'FR8 '.$title,in_array($id,[803,807],true)?'draft':'published']);
        $pdo->prepare('INSERT INTO positions(position_id,election_id,position_name,max_vote,sort_order) VALUES(?,?,?,1,0)')->execute([$id,$id,'President']);
        $pdo->prepare('INSERT INTO candidates(candidate_id,election_id,position_id,member_id,platform) VALUES(?,?,?,2,?)')->execute([$id,$id,$id,'Synthetic FR8 candidate']);
    }
    $pdo->exec("UPDATE elections SET start_datetime=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE election_id=801");
    $pdo->exec("UPDATE elections SET start_datetime=DATE_SUB(NOW(),INTERVAL 2 DAY),end_datetime=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE election_id=802");
    $pdo->exec("INSERT INTO ballots(election_id,user_id,has_voted) VALUES(803,2,1),(806,2,0)");
    $pdo->exec("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,description) VALUES(1,'election.published','election',804,'Synthetic prior opening')");
    db_transaction(static function (PDO $pdo): void {
        $state = integrity_lock_live_election($pdo, 900);
        $pdo->exec('INSERT INTO ballots(election_id,user_id,has_voted) VALUES(900,10000,1)');
        integrity_insert_selections($pdo, 900, [9000=>9000,9001=>9002], $state);
    });
    if (integrity_verify(900)['status'] !== 'passed') throw new RuntimeException('Signed vote fixture failed.');
    file_put_contents($snapshot, json_encode(fr8_records($pdo), JSON_THROW_ON_ERROR));
} elseif ($mode === 'start-race') {
    // Deterministically simulate voting opening after the confirmation was shown.
    $pdo->exec('UPDATE elections SET start_datetime=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE election_id=805');
} elseif ($mode === 'backend-state') {
    echo json_encode($pdo->query('SELECT election_id,title,description,status,start_datetime,end_datetime FROM elections WHERE election_id IN (801,805,900) ORDER BY election_id')->fetchAll(),JSON_THROW_ON_ERROR);
} elseif ($mode === 'deletion-audit') {
    $s=$pdo->prepare("SELECT user_id,action,entity_id,description,timestamp,ip_address FROM audit_logs WHERE entity_type='election' AND entity_id=? AND action='election.deleted' ORDER BY log_id");
    $s->execute([(int)($argv[2]??0)]);
    echo json_encode($s->fetchAll(),JSON_THROW_ON_ERROR);
} elseif ($mode === 'fail-deletion-audit') {
    $pdo->exec("CREATE TRIGGER fr8_refuse_deletion_audit BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='election.deleted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'; END IF; END");
} elseif ($mode === 'restore-deletion-audit') {
    $pdo->exec('DROP TRIGGER IF EXISTS fr8_refuse_deletion_audit');
} elseif ($mode === 'verify' || $mode === 'verify-backend') {
    $before = json_decode(file_get_contents($snapshot), true, 512, JSON_THROW_ON_ERROR);
    $after = fr8_records($pdo);
    if ($before !== $after) throw new RuntimeException('Voting or candidate records changed.');
    if ($before['votes']['rows'] < 1 || integrity_verify(900)['status'] !== 'passed') throw new RuntimeException('Stored vote integrity did not pass.');
    $states = $pdo->query('SELECT election_id,status,start_datetime,end_datetime FROM elections WHERE election_id BETWEEN 800 AND 807 ORDER BY election_id')->fetchAll();
    foreach ($states as $e) {
        $expected = in_array((int)$e['election_id'],[803,807],true) ? 'draft' : 'published';
        if ($e['status'] !== $expected) throw new RuntimeException('Unexpected final election state.');
    }
    $audit = $pdo->query("SELECT entity_id,action,description FROM audit_logs WHERE entity_id BETWEEN 800 AND 807 AND action IN ('election.cancelled','election.rescheduled') ORDER BY log_id")->fetchAll();
    $expectedAudits=$mode==='verify-backend'?0:1;
    if (count($audit) !== $expectedAudits) throw new RuntimeException('Unexpected reschedule/cancellation audit events.');
    if ($expectedAudits && $audit[0]['action'] !== 'election.rescheduled') throw new RuntimeException('Missing reschedule audit.');
    echo json_encode(['all_passed'=>true,'protected_records_unchanged'=>true,'before'=>$before,'after'=>$after,'final_elections'=>$states,'audit'=>$audit], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
} else throw new RuntimeException('Unknown fixture operation.');

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COOPVOTE_ENV') !== 'test') exit(1);
require dirname(__DIR__,2).'/includes/helpers.php';
require dirname(__DIR__,2).'/api/routes/voter.php';
require dirname(__DIR__,2).'/includes/election-reports.php';
if (DB_NAME !== 'coopvote_test' || !str_starts_with(DB_SETTINGS['socket'],'/tmp/coopvote-db-tests-') || basename(DB_SETTINGS['socket']) !== 'mysql.sock') throw new RuntimeException('Disposable fixture required.');
$pdo=db(); $dir=dirname(DB_SETTINGS['socket']); $file=$dir.'/reopening-snapshot.json'; $mode=$argv[1]??'';
function reopen_records(PDO $pdo): array {
    $out=[];
    foreach (['votes'=>'vote_id','ballots'=>'ballot_id','vote_integrity'=>'vote_id'] as $table=>$key) {
        $out[$table]=$pdo->query("SELECT * FROM $table WHERE election_id=900 ORDER BY $key")->fetchAll();
    }
    return $out;
}
function reopen_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
if ($mode==='seed') {
    $pdo->exec("UPDATE elections SET title='Reopening verification election' WHERE election_id=900");
    $pdo->exec("INSERT INTO elections(election_id,title,start_datetime,end_datetime,status,created_by) VALUES(902,'Cancelled fixture',DATE_ADD(NOW(),INTERVAL 1 DAY),DATE_ADD(NOW(),INTERVAL 2 DAY),'cancelled',1)");
} elseif ($mode==='snapshot') {
    $records=reopen_records($pdo);
    reopen_assert(count($records['votes'])===14 && count($records['ballots'])===7,'Expected recorded voting fixture');
    $report=$pdo->query('SELECT * FROM election_reports WHERE election_id=900')->fetch();
    file_put_contents($file,json_encode(['records'=>$records,'report'=>$report],JSON_THROW_ON_ERROR));
} elseif ($mode==='check-reopened' || $mode==='verify') {
    $before=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    $after=reopen_records($pdo);
    foreach (['votes'=>'vote_id','ballots'=>'ballot_id','vote_integrity'=>'vote_id'] as $table=>$key) {
        $current=array_column($after[$table],null,$key);
        foreach ($before['records'][$table] as $row) reopen_assert(($current[$row[$key]]??null)===$row,'An existing '.$table.' row changed.');
    }
    $report=$pdo->query('SELECT * FROM election_reports WHERE election_id=900')->fetch();
    $history=json_decode($report['history'],true,512,JSON_THROW_ON_ERROR);
    reopen_assert(count($history)===1 && $history[0]['snapshot']===$before['report']['snapshot'] && $history[0]['signature']===$before['report']['signature'],'Prior signed report not retained exactly');
    reopen_assert(integrity_verify(900)['status']==='passed','Vote integrity failed');
    $e=$pdo->query('SELECT * FROM elections WHERE election_id=900')->fetch();
    if ($mode==='check-reopened') {
        reopen_assert($before['records']===$after,'Reopening changed stored voting records');
        reopen_assert(election_status($e)==='Active' && $e['results_released_at']===null && $e['released_by']===null && $report['superseded_at']!==null,'Reopened state or result withdrawal incorrect');
        echo 'Reopening preserves all original rows and signed report; results withdrawn.';
    } else {
        reopen_assert(count($after['votes'])===16 && count($after['ballots'])===8,'Additional ballot not retained');
        reopen_assert(election_status($e)==='Closed' && $e['results_released_at']!==null && $report['superseded_at']===null,'Final closing/release incorrect');
        $audit=$pdo->query("SELECT action,description FROM audit_logs WHERE entity_type='election' AND entity_id=900 AND action='election.reopened'")->fetchAll();
        reopen_assert(count($audit)===1,'Expected one successful reopen audit');
        echo json_encode(['all_passed'=>true,'existing_vote_ballot_signature_rows_preserved'=>true,'original_votes'=>14,'final_votes'=>16,'original_ballots'=>7,'final_ballots'=>8,'prior_signed_report_retained'=>true,'integrity'=>'passed','audit'=>$audit],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
    }
} elseif ($mode==='tamper') {
    $row=$pdo->query('SELECT vote_id,signature FROM vote_integrity WHERE election_id=900 ORDER BY vote_id LIMIT 1')->fetch();
    file_put_contents($dir.'/original-signature.json',json_encode($row,JSON_THROW_ON_ERROR));
    $pdo->prepare('UPDATE vote_integrity SET signature=? WHERE vote_id=?')->execute([str_repeat('0',64),$row['vote_id']]);
} elseif ($mode==='repair') {
    $row=json_decode(file_get_contents($dir.'/original-signature.json'),true,512,JSON_THROW_ON_ERROR);
    $pdo->prepare('UPDATE vote_integrity SET signature=? WHERE vote_id=?')->execute([$row['signature'],$row['vote_id']]);
} else throw new RuntimeException('Unknown test operation');

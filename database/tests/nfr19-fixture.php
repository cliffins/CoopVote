<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COOPVOTE_ENV')!=='test')exit(1);
require dirname(__DIR__,2).'/includes/helpers.php';
require dirname(__DIR__,2).'/api/routes/voter.php';
require dirname(__DIR__,2).'/includes/privacy-migration.php';
if(DB_NAME!=='coopvote_test'||!str_starts_with(DB_SETTINGS['socket'],'/tmp/coopvote-db-tests-'))throw new RuntimeException('Disposable fixture required.');
$p=db();$mode=$argv[1]??'';$dir=dirname(getenv('COOPVOTE_TEST_RESULTS'));
function nfr_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
if($mode==='seed'){
    $p->exec("INSERT INTO elections(election_id,title,start_datetime,end_datetime,status,created_by) VALUES(900,'NFR19 Test',DATE_SUB(NOW(),INTERVAL 1 HOUR),DATE_ADD(NOW(),INTERVAL 1 DAY),'published',1)");
    $p->prepare('INSERT INTO election_integrity(election_id,seal) VALUES(900,?)')->execute([integrity_state_seal(900,0,0)]);
    $q=$p->prepare("INSERT INTO users(user_id,username,email,password_hash,role_id,status,email_verified_at) VALUES(?,?,?,?,3,'active',NOW())");
    $m=$p->prepare("INSERT INTO members(member_id,user_id,member_no,full_name,membership_status) VALUES(?,?,?,?,'active')");
    for($i=0;$i<8;$i++){$id=10000+$i;$q->execute([$id,'privacy'.$i,'privacy'.$i.'@example.test',password_hash('PrivacyTest9!',PASSWORD_BCRYPT)]);$m->execute([$id,$id,'PRIV-'.$i,'Privacy Voter '.$i]);}
    $pos=$p->prepare('INSERT INTO positions(position_id,election_id,position_name,max_vote,sort_order) VALUES(?,900,?,1,?)');
    $c=$p->prepare("INSERT INTO candidates(candidate_id,election_id,position_id,member_id,platform) VALUES(?,900,?,?,'Test')");
    foreach(['Balanced','Unanimous','Sparse','All abstain'] as $i=>$name){$id=9000+$i;$pos->execute([$id,$name,$i]);$c->execute([9000+$i*2,$id,10000+$i*2]);$c->execute([9001+$i*2,$id,10001+$i*2]);}
}
if($mode==='seed-abstention'){
    $p->exec("INSERT INTO elections(election_id,title,start_datetime,end_datetime,status,created_by) VALUES(901,'NFR19 Abstention',DATE_SUB(NOW(),INTERVAL 1 HOUR),DATE_ADD(NOW(),INTERVAL 1 DAY),'published',1)");
    $p->prepare('INSERT INTO election_integrity(election_id,seal) VALUES(901,?)')->execute([integrity_state_seal(901,0,0)]);
    $p->exec("INSERT INTO positions(position_id,election_id,position_name,max_vote,sort_order) VALUES(9010,901,'Abstention only',1,1)");
    $p->exec("INSERT INTO candidates(candidate_id,election_id,position_id,member_id,platform) VALUES(9010,901,9010,10000,'Test')");
}
if($mode==='verify-new'){
    nfr_check((int)$p->query('SELECT COUNT(*) FROM votes WHERE election_id=900')->fetchColumn()===14,'Mixed/all-abstain candidate total');
    nfr_check((int)$p->query('SELECT COUNT(*) FROM ballots WHERE election_id=900 AND has_voted=1')->fetchColumn()===7,'Participation count');
    nfr_check((int)$p->query('SELECT COUNT(*) FROM votes WHERE election_id=900 AND (cast_date IS NOT NULL OR vote_id < 1099511627776)')->fetchColumn()===0,'Dates/order removed');
    nfr_check((int)$p->query('SELECT COUNT(*) FROM ballots WHERE election_id=900 AND (voted_at IS NOT NULL OR issued_at IS NOT NULL OR ip_address IS NOT NULL OR user_agent IS NOT NULL)')->fetchColumn()===0,'Participation metadata removed');
    nfr_check((int)$p->query("SELECT COUNT(*) FROM audit_logs WHERE action='ballot.cast' AND entity_id=900")->fetchColumn()===0,'No per-voter cast audit');
    nfr_check(integrity_verify(900)['status']==='passed','New signatures verify');
    foreach($p->query('SELECT * FROM votes WHERE election_id=900')->fetchAll() as $v) nfr_check(!isset($v['user_id'],$v['ballot_id'],$v['receipt_code']),'No direct link');
    echo "New vote anonymity metadata, participation and integrity PASS\n";
}
if($mode==='migration'){
    // Recreate pre-upgrade dates/IDs/metadata in the disposable fixture only.
    $id=1;$votes=$p->query('SELECT * FROM votes ORDER BY vote_id')->fetchAll();
    $u=$p->prepare('UPDATE votes SET vote_id=?,cast_date=? WHERE vote_id=?');
    $i=$p->prepare('UPDATE vote_integrity SET vote_id=?,signature=? WHERE vote_id=?');
    foreach($votes as $v){$old=$v['vote_id'];$v['vote_id']=$id++;$v['cast_date']='2020-03-'.str_pad((string)$id,2,'0',STR_PAD_LEFT);$q=$p->prepare('SELECT * FROM vote_integrity WHERE vote_id=?');$q->execute([$old]);$sig=$q->fetch();$u->execute([$v['vote_id'],$v['cast_date'],$old]);$i->execute([$v['vote_id'],integrity_vote_signature($v,$sig['nonce'],$sig['origin']),$old]);}
    $p->exec('ALTER TABLE votes MODIFY vote_id BIGINT NOT NULL AUTO_INCREMENT, MODIFY cast_date DATE NOT NULL');
    $p->exec("UPDATE ballots SET voted_at=IF(has_voted=1,'2020-03-01 10:00:00',NULL),issued_at='2020-03-01 09:00:00',ip_address='192.0.2.99',user_agent='fixture'");
    $p->exec("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,description,ip_address,user_agent,timestamp) VALUES(10000,'ballot.cast','election',900,'Ballot cast (3 selections)','192.0.2.99','fixture','2020-03-01 10:00:00')");
    $p->exec("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,description) VALUES(1,'candidate.deactivated','candidate',9000,'Candidate has 3 votes')");
    // Exercise maintenance backup tooling and its restored-copy verifier in this disposable DB.
    $backupDir=$dir.'/nfr19-backup';mkdir($backupDir,0700);
    $tool=dirname(__DIR__,2).'/tools/nfr19-backup.php';
    $run=static function(array $args):void{$pipes=[];$proc=proc_open(array_merge([PHP_BINARY],$args),[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);fclose($pipes[0]);nfr_check(proc_close($proc)===0,'Backup CLI check');};
    $run([$tool,'--mode=create','--directory='.$backupDir]);
    $run([$tool,'--mode=verify','--snapshot='.$backupDir.'/snapshot.json','--backup='.$backupDir.'/database.sql','--verification='.$backupDir.'/verified.json']);
    $before=privacy_database_fingerprint($p);$counts=privacy_tally($p);
    $result=privacy_migrate_up($p,$dir.'/rollback.enc',$dir.'/rollback.key');
    nfr_check($result['talliesUnchanged']&&privacy_tally($p)===$counts,'Migration tally preservation');
    $after=privacy_database_fingerprint($p);
    foreach($before as $table=>$fingerprint) {
        nfr_check($after[$table]['count']===$fingerprint['count'],'No rows deleted: '.$table);
        if(!in_array($table,['votes','vote_integrity','ballots','audit_logs'],true))nfr_check($after[$table]===$fingerprint,'Unrelated table unchanged: '.$table);
    }
    nfr_check((int)$p->query('SELECT COUNT(*) FROM votes WHERE cast_date IS NOT NULL')->fetchColumn()===0,'Historical dates removed');
    nfr_check((int)$p->query('SELECT COUNT(*) FROM ballots WHERE voted_at IS NOT NULL OR issued_at IS NOT NULL OR ip_address IS NOT NULL OR user_agent IS NOT NULL')->fetchColumn()===0,'Historical participation metadata removed');
    nfr_check((int)$p->query("SELECT COUNT(*) FROM audit_logs WHERE action='ballot.cast' AND (user_id IS NOT NULL OR timestamp IS NOT NULL OR description LIKE '%3 selections%')")->fetchColumn()===0,'Historical cast audit sanitized');
    nfr_check((int)$p->query("SELECT COUNT(*) FROM audit_logs WHERE action='candidate.deactivated' AND description LIKE '%3 votes%'")->fetchColumn()===0,'Historical candidate count sanitized');
    privacy_verify_integrity($p);
    $bytes=file_get_contents($dir.'/rollback.enc');$bad=$bytes;$bad[strlen($bad)-1]=chr(ord($bad[strlen($bad)-1])^1);file_put_contents($dir.'/tampered.enc',$bad);
    try{privacy_migrate_down($p,$dir.'/tampered.enc',$dir.'/rollback.key');throw new LogicException('Tampered artifact accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
    nfr_check(privacy_database_fingerprint($p)===$after,'Tamper failure is non-mutating');
    privacy_migrate_down($p,$dir.'/rollback.enc',$dir.'/rollback.key');
    nfr_check(privacy_database_fingerprint($p)===$before,'Exact row-for-row rollback');
    privacy_migrate_up($p,$dir.'/rollback2.enc',$dir.'/rollback2.key');
    // A new legitimate selection makes the old rollback unsafe; refuse without deleting it.
    $p->beginTransaction();$state=integrity_state($p,900);integrity_insert_selections($p,900,[9000=>9000],$state);$p->commit();
    $withNew=privacy_database_fingerprint($p);
    $refused=false;try{privacy_migrate_down($p,$dir.'/rollback2.enc',$dir.'/rollback2.key');}catch(RuntimeException){$refused=true;}
    nfr_check($refused&&privacy_database_fingerprint($p)===$withNew,'Rollback refuses new votes without data loss');
    echo "Migration: every table row count/tally preserved; all unrelated records unchanged; authenticated rollback exact; tamper/new-vote refusal PASS\n";
}

if($mode==='seed-receipts'){
    $e=$p->prepare("INSERT INTO elections(election_id,title,start_datetime,end_datetime,status,results_released_at,created_by) VALUES(?,?,DATE_SUB(NOW(),INTERVAL 3 DAY),DATE_SUB(NOW(),INTERVAL 2 DAY),'published',NOW(),1)");
    foreach([902,903,904] as $id){
        $e->execute([$id,'Receipt Test '.$id]);
        $p->prepare('INSERT INTO election_integrity(election_id,seal) VALUES(?,?)')->execute([$id,integrity_state_seal($id,0,0)]);
    }
    $b=$p->prepare('INSERT INTO ballots(election_id,user_id,has_voted,receipt_code,issued_at) VALUES(?,?,1,?,NULL)');
    $b->execute([902,10000,'CV-TESTFIRST']);
    $b->execute([903,10000,'CV-TESTSECOND']);
    $b->execute([904,10000,null]);
    $b->execute([902,10001,'CV-OTHERUSER']);
}

<?php
/** Synthetic concurrency fixtures and read-only evidence; refuses production configuration. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COOPVOTE_ENV') !== 'test') exit(1);
require dirname(__DIR__,2).'/includes/helpers.php';
require dirname(__DIR__,2).'/api/routes/voter.php';
require_once dirname(__DIR__,2).'/includes/vote-integrity.php';
if (DB_NAME !== 'coopvote_test' || !str_starts_with(DB_SETTINGS['socket'],'/tmp/coopvote-db-tests-perf-') || basename(DB_SETTINGS['socket']) !== 'mysql.sock') {
    throw new RuntimeException('Private concurrency database required.');
}
$p=db(); $mode=$argv[1]??'';
if ($mode==='seed') {
    $n=(int)($argv[2]??0);
    if ($n<8 || $n>1000) throw new RuntimeException('Choose 8–1000 fixture voters.');
    $hash=password_hash('ConcurrentTest9!',PASSWORD_BCRYPT);
    db_transaction(static function(PDO $p) use($n,$hash): void {
        $u=$p->prepare("INSERT INTO users(user_id,username,email,password_hash,role_id,status,email_verified_at) VALUES(?,?,?,?,3,'active',NOW())");
        $m=$p->prepare("INSERT INTO members(member_id,user_id,member_no,full_name,membership_status) VALUES(?,?,?,?,'active')");
        for($i=0;$i<$n;$i++) {
            $id=20000+$i;
            $u->execute([$id,'concurrent'.$i,'concurrent'.$i.'@example.test',$hash]);
            $m->execute([$id,$id,'CONCURRENT-'.$i,'Synthetic Concurrent Voter '.$i]);
        }
        foreach([1000,1001] as $eid) {
            $p->prepare("INSERT INTO elections(election_id,title,start_datetime,end_datetime,status,created_by) VALUES(?,?,DATE_SUB(NOW(),INTERVAL 1 HOUR),DATE_ADD(NOW(),INTERVAL 1 DAY),'published',1)")
                ->execute([$eid,'NFR-PERF-02 synthetic election '.$eid]);
            $p->prepare('INSERT INTO election_integrity(election_id,seal) VALUES(?,?)')->execute([$eid,integrity_state_seal($eid,0,0)]);
            for($pos=0;$pos<2;$pos++) {
                $pid=$eid*10+$pos;
                $p->prepare('INSERT INTO positions(position_id,election_id,position_name,max_vote,sort_order) VALUES(?,?,?,1,?)')->execute([$pid,$eid,'Position '.($pos+1),$pos]);
                for($c=0;$c<2;$c++) {
                    $p->prepare('INSERT INTO candidates(candidate_id,election_id,position_id,member_id,platform) VALUES(?,?,?,?,?)')
                        ->execute([$eid*10+$pos*2+$c,$eid,$pid,20000+$pos*2+$c,'Synthetic concurrency fixture']);
                }
            }
        }
    });
    echo json_encode(['voters'=>$n,'elections'=>[1000,1001]]),"\n";
} elseif($mode==='metrics') {
    $metrics=[];
    foreach($p->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Com_rollback','Innodb_deadlocks','Innodb_row_lock_waits','Innodb_row_lock_time','Threads_running')")->fetchAll(PDO::FETCH_NUM) as [$k,$v]) $metrics[$k]=(int)$v;
    echo json_encode($metrics),"\n";
} elseif($mode==='snapshot') {
    $result=['database_version'=>$p->query('SELECT VERSION()')->fetchColumn(),'elections'=>[]];
    foreach([1000,1001] as $eid) {
        $s=$p->prepare('SELECT user_id,has_voted,receipt_code FROM ballots WHERE election_id=? ORDER BY user_id');$s->execute([$eid]);$ballots=$s->fetchAll();
        $s=$p->prepare('SELECT c.candidate_id,COUNT(v.vote_id) AS votes FROM candidates c LEFT JOIN votes v ON v.candidate_id=c.candidate_id AND v.election_id=c.election_id WHERE c.election_id=? GROUP BY c.candidate_id ORDER BY c.candidate_id');$s->execute([$eid]);$tallies=$s->fetchAll();
        $s=$p->prepare('SELECT COUNT(*) AS vote_rows,COUNT(DISTINCT vote_id) AS unique_vote_ids FROM votes WHERE election_id=?');$s->execute([$eid]);$counts=$s->fetch();
        $s=$p->prepare('SELECT COUNT(*) FROM (SELECT user_id FROM ballots WHERE election_id=? GROUP BY user_id HAVING COUNT(*)>1) duplicates');$s->execute([$eid]);
        $result['elections'][$eid]=['ballots'=>$ballots,'tallies'=>$tallies,'counts'=>$counts,'duplicate_ballot_groups'=>(int)$s->fetchColumn(),'integrity'=>integrity_verify($eid)];
    }
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
} else throw new RuntimeException('Unknown concurrency fixture mode.');

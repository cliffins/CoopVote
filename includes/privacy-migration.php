<?php
/** Maintenance-only NFR19 migration. Never called by a web request. */
declare(strict_types=1);
require_once __DIR__ . '/ballot-privacy.php';

function privacy_tally(PDO $pdo): array
{
    return $pdo->query('SELECT election_id,position_id,candidate_id,COUNT(*) AS n FROM votes GROUP BY election_id,position_id,candidate_id ORDER BY election_id,position_id,candidate_id')->fetchAll();
}

/** Hash every row without exposing records in verification reports. */
function privacy_database_fingerprint(PDO $pdo): array
{
    $out = [];
    $q=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $rows=$pdo->query('SELECT * FROM `'.str_replace('`','``',$table).'`')->fetchAll(PDO::FETCH_ASSOC);
        $hashes=array_map(static fn($r)=>hash('sha256',serialize($r)), $rows); sort($hashes);
        $out[$table]=['count'=>count($rows),'sha256'=>hash('sha256',implode('', $hashes))];
    }
    return $out;
}

function privacy_private_file(string $path, string $bytes): void
{
    $parent=realpath(dirname($path)); $web=realpath(BASE_PATH);
    if (!$parent || ($web && ($parent===$web || str_starts_with($parent,$web.DIRECTORY_SEPARATOR)))) throw new RuntimeException('Migration artifacts must be outside the website.');
    if (PHP_OS_FAMILY!=='Windows' && (fileperms($parent)&0077)!==0) throw new RuntimeException('Artifact directory must be private (0700).');
    $old=umask(0077);
    try { $f=fopen($path,'xb'); } finally { umask($old); }
    if (!$f) throw new RuntimeException('Artifact already exists or cannot be created.');
    try {
        $offset=0;
        while($offset<strlen($bytes)) { $n=fwrite($f,substr($bytes,$offset)); if(!$n) throw new RuntimeException('Artifact write failed.'); $offset+=$n; }
        if (!fflush($f) || (function_exists('fsync') && !fsync($f))) throw new RuntimeException('Artifact flush failed.');
    } finally { fclose($f); }
}

function privacy_archive_write(string $archive, string $keyFile, array $data): void
{
    $key=random_bytes(32); $nonce=random_bytes(12); $tag='';
    $cipher=openssl_encrypt(json_encode($data,JSON_THROW_ON_ERROR), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'CoopVote-NFR19-rollback-v1',16);
    if ($cipher===false) throw new RuntimeException('Rollback encryption failed.');
    privacy_private_file($keyFile,bin2hex($key)."\n");
    privacy_private_file($archive,"CVNFR19\x01".$nonce.$tag.$cipher);
}

function privacy_archive_read(string $archive, string $keyFile): array
{
    $hex=trim(file_get_contents($keyFile)); $bytes=file_get_contents($archive);
    if (!preg_match('/^[0-9a-f]{64}$/D',$hex) || strlen($bytes)<36 || substr($bytes,0,8)!=="CVNFR19\x01") throw new RuntimeException('Invalid rollback artifact.');
    $plain=openssl_decrypt(substr($bytes,36),'aes-256-gcm',hex2bin($hex),OPENSSL_RAW_DATA,substr($bytes,8,12),substr($bytes,20,16),'CoopVote-NFR19-rollback-v1');
    if ($plain===false) throw new RuntimeException('Rollback authentication failed; wrong key or modified archive.');
    return json_decode($plain,true,512,JSON_THROW_ON_ERROR);
}

function privacy_verify_integrity(PDO $pdo): void
{
    foreach ($pdo->query('SELECT election_id FROM elections ORDER BY election_id')->fetchAll(PDO::FETCH_COLUMN) as $eid) {
        if (integrity_verify((int)$eid)['status']!=='passed') throw new RuntimeException('Existing vote integrity failed. No migration permitted.');
    }
}

function privacy_migrate_up(PDO $pdo,string $archive,string $keyFile): array
{
    if (PHP_SAPI!=='cli') throw new RuntimeException('CLI only.');
    if (file_exists($archive) || file_exists($keyFile)) throw new RuntimeException('Use new artifact paths; existing rollback files must not be overwritten.');
    privacy_verify_integrity($pdo);
    $columns=$pdo->query('SHOW COLUMNS FROM votes')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    // Additive/compatibility DDL commits separately; no vote data is removed by DDL.
    $pdo->exec('ALTER TABLE votes MODIFY vote_id BIGINT NOT NULL, MODIFY cast_date DATE NULL DEFAULT NULL');
    $pdo->exec('ALTER TABLE ballots MODIFY issued_at DATETIME NULL DEFAULT NULL');
    $pdo->exec('ALTER TABLE audit_logs MODIFY timestamp DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT election_id FROM elections ORDER BY election_id FOR UPDATE')->fetchAll();
        privacy_verify_integrity($pdo);
        $tally=privacy_tally($pdo);
        $votes=$pdo->query('SELECT * FROM votes ORDER BY vote_id FOR UPDATE')->fetchAll();
        $integrity=$pdo->query('SELECT * FROM vote_integrity ORDER BY vote_id FOR UPDATE')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
        $ballots=$pdo->query('SELECT * FROM ballots ORDER BY ballot_id FOR UPDATE')->fetchAll();
        $logs=$pdo->query("SELECT * FROM audit_logs WHERE action IN ('ballot.cast','candidate.deactivated','member.deactivated') ORDER BY log_id FOR UPDATE")->fetchAll();
        $used=[]; foreach($votes as $v)$used[(string)$v['vote_id']]=true;
        $mapping=[];
        foreach($votes as $v) {
            do {$id=privacy_vote_id();} while(isset($used[(string)$id]));
            $used[(string)$id]=true;
            $mapping[]=['old'=>$v,'new_id'=>$id,'integrity'=>$integrity[$v['vote_id']]];
        }
        $data=['version'=>1,'database'=>DB_NAME,'votes'=>$mapping,'ballots'=>$ballots,'logs'=>$logs,'tally'=>$tally,'oldAutoIncrement'=>str_contains($columns['vote_id']['Extra'],'auto_increment'),'oldDateNullable'=>$columns['cast_date']['Null']==='YES'];
        // Persist and authenticate rollback information BEFORE changing any row.
        privacy_archive_write($archive,$keyFile,$data);
        if (privacy_archive_read($archive,$keyFile)!==$data) throw new RuntimeException('Rollback artifact verification failed.');
        $update=$pdo->prepare('UPDATE votes SET vote_id=?,cast_date=NULL WHERE vote_id=?');
        $sign=$pdo->prepare('UPDATE vote_integrity SET vote_id=?,nonce=?,signature=? WHERE vote_id=?');
        // Randomize migration write order as additional hygiene; not a defence against DB logs.
        shuffle($mapping);
        foreach($mapping as $item) {
            $v=$item['old'];$old=$v['vote_id'];$v['vote_id']=$item['new_id'];$v['cast_date']=null;
            $nonce=bin2hex(random_bytes(16));$origin=$item['integrity']['origin'];
            $update->execute([$v['vote_id'],$old]);
            $sign->execute([$v['vote_id'],$nonce,integrity_vote_signature($v,$nonce,$origin),$old]);
            if ($update->rowCount()!==1 || $sign->rowCount()!==1) throw new RuntimeException('Unexpected migration row count.');
        }
        $pdo->exec('UPDATE ballots SET voted_at=NULL,issued_at=NULL,ip_address=NULL,user_agent=NULL');
        $pdo->exec("UPDATE audit_logs SET user_id=NULL,description='Participation recorded; historical timing and selection count removed for voter privacy',ip_address=NULL,user_agent=NULL,timestamp=NULL WHERE action='ballot.cast'");
        $pdo->exec("UPDATE audit_logs SET description='Account or candidate deactivated; election records preserved; historical count details withheld for privacy' WHERE action IN ('candidate.deactivated','member.deactivated')");
        if (privacy_tally($pdo)!==$tally) throw new RuntimeException('Tally changed; migration rolled back.');
        privacy_verify_integrity($pdo);
        if ((int)$pdo->query('SELECT COUNT(*) FROM ballots')->fetchColumn()!==count($ballots)) throw new RuntimeException('Participation count changed.');
        $pdo->commit();
        return ['selections'=>count($votes),'participationRecords'=>count($ballots),'sanitizedAuditRecords'=>count($logs),'talliesUnchanged'=>true];
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** No reset/restore-over-live shortcut: refuse rollback if new votes were cast. */
function privacy_migrate_down(PDO $pdo,string $archive,string $keyFile): void
{
    if (PHP_SAPI!=='cli') throw new RuntimeException('CLI only.');
    $data=privacy_archive_read($archive,$keyFile);
    if (($data['database']??'')!==DB_NAME || ($data['version']??0)!==1) throw new RuntimeException('Wrong rollback database or version.');
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT election_id FROM elections ORDER BY election_id FOR UPDATE')->fetchAll();
        privacy_verify_integrity($pdo);
        if (privacy_tally($pdo)!==$data['tally'] || (int)$pdo->query('SELECT COUNT(*) FROM votes')->fetchColumn()!==count($data['votes'])) throw new RuntimeException('New or changed votes exist. Automatic rollback refused; no data was removed.');
        $q=$pdo->prepare('SELECT * FROM votes WHERE vote_id=? FOR UPDATE');
        foreach($data['votes'] as $item) {
            $q->execute([$item['new_id']]);$v=$q->fetch();$expected=$item['old'];$expected['vote_id']=$item['new_id'];$expected['cast_date']=null;
            if ($v!=$expected) throw new RuntimeException('Migrated vote changed. Rollback refused.');
        }
        foreach($data['ballots'] as $old) {
            $q=$pdo->prepare('SELECT * FROM ballots WHERE ballot_id=? FOR UPDATE');$q->execute([$old['ballot_id']]);$now=$q->fetch();
            foreach(['election_id','user_id','has_voted','receipt_code'] as $f) if(($now[$f]??null)!==$old[$f]) throw new RuntimeException('Participation changed. Rollback refused.');
        }
        $update=$pdo->prepare('UPDATE votes SET vote_id=?,cast_date=? WHERE vote_id=?');
        $sign=$pdo->prepare('UPDATE vote_integrity SET vote_id=?,nonce=?,signature=?,origin=? WHERE vote_id=?');
        foreach($data['votes'] as $item) {
            $old=$item['old'];$i=$item['integrity'];
            $update->execute([$old['vote_id'],$old['cast_date'],$item['new_id']]);
            $sign->execute([$old['vote_id'],$i['nonce'],$i['signature'],$i['origin'],$item['new_id']]);
        }
        $b=$pdo->prepare('UPDATE ballots SET voted_at=?,issued_at=?,ip_address=?,user_agent=? WHERE ballot_id=?');
        foreach($data['ballots'] as $old)$b->execute([$old['voted_at'],$old['issued_at'],$old['ip_address'],$old['user_agent'],$old['ballot_id']]);
        $a=$pdo->prepare('UPDATE audit_logs SET user_id=?,description=?,ip_address=?,user_agent=?,timestamp=? WHERE log_id=?');
        foreach($data['logs'] as $old)$a->execute([$old['user_id'],$old['description'],$old['ip_address'],$old['user_agent'],$old['timestamp'],$old['log_id']]);
        privacy_verify_integrity($pdo);
        if(privacy_tally($pdo)!==$data['tally'])throw new RuntimeException('Rollback tally mismatch.');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    // Keep nullable participation/audit columns: unrelated new rows may exist.
    $auto=$data['oldAutoIncrement']?' AUTO_INCREMENT':'';
    $date=$data['oldDateNullable']?'NULL DEFAULT NULL':'NOT NULL';
    $pdo->exec('ALTER TABLE votes MODIFY vote_id BIGINT NOT NULL'.$auto.', MODIFY cast_date DATE '.$date);
}

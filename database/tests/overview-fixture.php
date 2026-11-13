<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli' || getenv('COOPVOTE_ENV')!=='test')exit(1);
require dirname(__DIR__,2).'/includes/helpers.php';
if(DB_NAME!=='coopvote_test' || !str_starts_with(DB_SETTINGS['socket'],'/tmp/coopvote-db-tests-'))exit(2);
$p=db();$mode=$argv[1]??'';
if($mode==='seed'){
    $p->exec("UPDATE users SET status='suspended' WHERE user_id=3; INSERT INTO admin_profiles(user_id,display_name) VALUES(1,'Demo Administrator')");
    $insert=$p->prepare("INSERT INTO users(user_id,username,email,password_hash,role_id,status) VALUES(?,?,?,'unusable-fixture-password',1,?)");
    foreach([[10,'pending_verification'],[11,'pending_verification'],[12,'pending_verification'],[13,'active'],[14,'deactivated']] as [$id,$status]){
        $insert->execute([$id,'overview'.$id,'overview'.$id.'@example.test',$status]);
        $p->prepare("INSERT INTO officer_email_verifications(user_id,purpose,pending_email,token_hash,expires_at,requested_by,requested_at,window_started_at,delivery_status) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE),1,NOW(),NOW(),?)")
          ->execute([$id,$id===13?'email_change':'invite','pending'.$id.'@example.test',str_repeat(dechex($id),32),$id===10?'failed':'sent']);
    }
    $p->exec("UPDATE officer_email_verifications SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE user_id=11;
        INSERT INTO audit_logs(user_id,action,description) VALUES(1,'member.created','Registered a voter account'),(1,'officer.verification_requested','Sent an officer verification invitation'),(2,'auth.login','Private voter activity'),(1,'ballot.cast','Private ballot activity');
        INSERT INTO elections(election_id,title,start_datetime,end_datetime,created_by) VALUES(800,'Preserved synthetic election','2030-01-01','2030-01-02',1)");
}elseif($mode==='empty'){
    $p->exec('UPDATE officer_email_verifications SET consumed_at=NOW()');
    $p->exec('DELETE FROM audit_logs');
}elseif($mode==='escape'){
    $p->exec("INSERT INTO audit_logs(user_id,action,description) VALUES(1,'admin.updated','<img src=x onerror=alert(1)>')");
}elseif($mode==='fingerprint'){
    $rows=$p->query('SELECT * FROM elections ORDER BY election_id')->fetchAll();
    echo hash('sha256',serialize($rows));
}else exit(2);

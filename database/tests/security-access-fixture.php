<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COOPVOTE_ENV')!=='test')exit(1);
require dirname(__DIR__,2).'/includes/auth.php';
if(DB_NAME!=='coopvote_test'||!str_starts_with(DB_SETTINGS['socket'],'/tmp/coopvote-db-tests-'))exit(1);
$mode=$argv[1]??'';$p=db();
if($mode==='migration'){
 $before=$p->query('SELECT user_id,username,email,password_hash,status FROM users ORDER BY user_id')->fetchAll();
 $p->exec('DROP TABLE password_reset_tokens');$p->exec('DROP TABLE password_reset_limits');$p->exec('ALTER TABLE users DROP COLUMN auth_version');
 require dirname(__DIR__).'/migrate-password-recovery.php';
 require dirname(__DIR__).'/migrate-password-recovery.php';
 if($before!==$p->query('SELECT user_id,username,email,password_hash,status FROM users ORDER BY user_id')->fetchAll())throw new RuntimeException('Migration changed accounts');
}
elseif($mode==='limits-clear')$p->exec('DELETE FROM password_reset_limits');
elseif($mode==='expire')$p->exec('UPDATE password_reset_tokens SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE)');
elseif($mode==='cooldown-clear')$p->exec('UPDATE password_reset_limits SET last_request=DATE_SUB(NOW(),INTERVAL 2 MINUTE)');
elseif($mode==='suspend')$p->exec("UPDATE users SET status='suspended' WHERE user_id=3");
elseif($mode==='activate')$p->exec("UPDATE users SET status='active',email_verified_at=NOW() WHERE user_id=3");
elseif($mode==='unverify')$p->exec('UPDATE users SET email_verified_at=NULL WHERE user_id=3');
elseif($mode==='change-email')$p->exec("UPDATE users SET email='changed@example.test' WHERE user_id=3");
elseif($mode==='change-password')$p->prepare('UPDATE users SET password_hash=? WHERE user_id=3')->execute([password_hash('AnotherTest9!',PASSWORD_BCRYPT)]);
elseif($mode==='verify-storage'){
 $r=$p->query('SELECT token_hash FROM password_reset_tokens WHERE user_id=2')->fetchColumn();
 if(!$r||strlen($r)!==64)throw new RuntimeException('Missing hashed token');
 $lines=file(getenv('COOPVOTE_RECOVERY_TEST_OUTBOX'),FILE_IGNORE_NEW_LINES);$job=json_decode(end($lines),true);$token=explode('#token=',$job['url'])[1];
 if(!hash_equals($r,hash('sha256',$token)))throw new RuntimeException('Token storage is not hashed');
}
elseif($mode==='ipv6'){if(!is_ip_blocked('2001:0db8:0:0:0:0:0:1'))throw new RuntimeException('IPv6 spelling bypass');}
elseif($mode==='counts')echo json_encode([$p->query('SELECT COUNT(*) FROM ballots')->fetchColumn(),$p->query('SELECT COUNT(*) FROM votes')->fetchColumn()]);
else throw new RuntimeException('Unknown fixture mode');

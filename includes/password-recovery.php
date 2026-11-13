<?php
declare(strict_types=1);
require_once __DIR__ . '/officer-verification.php';
const RECOVERY_MESSAGE = 'If this address belongs to an active, verified account, you will receive a password reset link. Check your inbox and spam folder. Wait at least 60 seconds before requesting again.';

/** Limits use hashed identifiers, and are locked across simultaneous requests. */
function recovery_allow(PDO $pdo, string $scope, string $value, int $limit, int $cooldown = 0): bool
{
    $key = hash('sha256', $scope . '|' . $value);
    $pdo->prepare('INSERT IGNORE INTO password_reset_limits (key_hash,window_start) VALUES (?,NOW())')->execute([$key]);
    $s = $pdo->prepare('SELECT *,TIMESTAMPDIFF(SECOND,window_start,NOW()) AS age,TIMESTAMPDIFF(SECOND,last_request,NOW()) AS since_last FROM password_reset_limits WHERE key_hash=? FOR UPDATE');
    $s->execute([$key]); $r = $s->fetch();
    $count = (int)$r['age'] >= 3600 ? 0 : (int)$r['attempts'];
    if ($count >= $limit || ($r['since_last'] !== null && (int)$r['since_last'] < $cooldown)) return false;
    $pdo->prepare('UPDATE password_reset_limits SET window_start=IF(?=0,NOW(),window_start),attempts=?,last_request=NOW() WHERE key_hash=?')->execute([$count,$count+1,$key]);
    return true;
}
function recovery_test_outbox(): ?string
{
    $capture = getenv('COOPVOTE_RECOVERY_TEST_OUTBOX');
    return $capture && getenv('COOPVOTE_ENV') === 'test' && DB_NAME === 'coopvote_test'
        && str_starts_with(DB_SETTINGS['socket'], '/tmp/coopvote-db-tests-')
        && realpath(dirname($capture)) === realpath(dirname(DB_SETTINGS['socket'])) ? $capture : null;
}
function recovery_request(string $email): array
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email)>254) governance_fail('Enter a valid email address.');
    if (!recovery_test_outbox() && (!MAIL_ENABLED || MAIL_USERNAME === '' || MAIL_PASSWORD === '' || !is_file(BASE_PATH.'/vendor/autoload.php'))) {
        governance_fail('Email recovery is temporarily unavailable. Please contact the cooperative office.',503);
    }
    $base = officer_verification_base_url();
    $job = db_transaction(static function(PDO $pdo) use($email,$base): array {
        if (!recovery_allow($pdo,'request-ip',client_ip(),10)) return ['limited'=>true];
        if (!recovery_allow($pdo,'request-email',$email,3,60)) return [];
        $s=$pdo->prepare("SELECT user_id,username,email,password_hash FROM users WHERE email=? AND status='active' AND email_verified_at IS NOT NULL FOR UPDATE");
        $s->execute([$email]);$u=$s->fetch();
        if (!$u) return [];
        $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
        $pdo->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,email,password_fingerprint,expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE)) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),email=VALUES(email),password_fingerprint=VALUES(password_fingerprint),expires_at=VALUES(expires_at),consumed_at=NULL,created_at=NOW()')
            ->execute([(int)$u['user_id'],$hash,$u['email'],hash('sha256',$u['password_hash'])]);
        return ['id'=>(int)$u['user_id'],'email'=>$u['email'],'name'=>$u['username'],'hash'=>$hash,'url'=>$base.'/password-recovery.php#token='.$token];
    });
    if (!empty($job['limited'])) governance_fail('Too many recovery requests. Please try again in an hour.',429);
    // Return the same response before SMTP, avoiding a delivery-time account oracle.
    if (!recovery_test_outbox()) respond_then(['ok'=>true,'message'=>RECOVERY_MESSAGE], static fn() => recovery_deliver($job));
    recovery_deliver($job);
    return ['ok'=>true,'message'=>RECOVERY_MESSAGE];
}
function recovery_deliver(array $job): void
{
    if (isset($job['url'])) {
        if ($capture=recovery_test_outbox()) {
            $sent=file_put_contents($capture,json_encode($job,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX)!==false;
            if ($sent) chmod($capture,0600);
        } else {
            $sent=send_mail($job['email'],$job['name'],'CoopVote — Reset your password','Reset your password',
                'Use the button below to choose a new password for your CoopVote account.', ['Expires in'=>'30 minutes'],
                'This link works once. If you did not request a reset, ignore this email. Your password has not changed.',
                ['url'=>$job['url'],'label'=>'Reset password']);
        }
        if (!$sent) db()->prepare('UPDATE password_reset_tokens SET token_hash=NULL WHERE user_id=? AND token_hash=?')->execute([$job['id'],$job['hash']]);
        audit($job['id'],$sent?'auth.recovery_requested':'auth.recovery_delivery_failed','user',$job['id'],'Password recovery email '.($sent?'submitted for delivery':'could not be delivered'));
    }
}

function recovery_invalid(): never
{
    governance_fail('This reset link is invalid, expired, or already used. Request a new link.',400);
}
function recovery_token(PDO $pdo,string $token,bool $lock=false): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) recovery_invalid();
    $q=$pdo->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash=?');
    $q->execute([hash('sha256',$token)]);$id=$q->fetchColumn();
    if (!$id) recovery_invalid();
    // Match issuance lock order: user, then reset record.
    $q=$pdo->prepare('SELECT * FROM users WHERE user_id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);$u=$q->fetch();
    $q=$pdo->prepare('SELECT *,expires_at>NOW() AS valid_time FROM password_reset_tokens WHERE user_id=? AND token_hash=?'.($lock?' FOR UPDATE':''));
    $q->execute([$id,hash('sha256',$token)]);$r=$q->fetch();
    if (!$u || !$r || !$r['valid_time'] || $r['consumed_at'] || $u['status']!=='active' || !$u['email_verified_at']
        || $r['email']!==$u['email'] || !hash_equals($r['password_fingerprint'],hash('sha256',$u['password_hash']))) recovery_invalid();
    return $u;
}
function handle_password_recovery(): never
{
    require_method('POST');require_json_request();
    try {
        $action=input_str('action');
        if ($action==='request') json_out(recovery_request(strtolower(input_str('email'))));
        if (!in_array($action,['inspect','complete'],true)) governance_fail('Unknown recovery action.',404);
        $allowed=db_transaction(static fn(PDO $pdo): bool=>recovery_allow($pdo,'verify-ip',client_ip(),60));
        if (!$allowed) governance_fail('Too many attempts. Please try again in an hour.',429);
        $token=input_scalar('token');
        if ($action==='inspect') { recovery_token(db(),$token);json_out(['ok'=>true]); }
        $password=input_scalar('password');$confirm=input_scalar('confirmPassword');
        if ($error=changed_password_error($password)) governance_fail($error);
        if ($password!==$confirm) governance_fail('Passwords do not match.');
        db_transaction(static function(PDO $pdo) use($token,$password): void {
            $u=recovery_token($pdo,$token,true);$id=(int)$u['user_id'];
            $pdo->prepare('UPDATE users SET password_hash=?,auth_version=auth_version+1,failed_attempts=0,lockout_count=0,locked_until=NULL WHERE user_id=?')->execute([password_hash($password,PASSWORD_BCRYPT),$id]);
            $pdo->prepare('UPDATE password_reset_tokens SET token_hash=NULL,consumed_at=NOW() WHERE user_id=?')->execute([$id]);
            audit($id,'auth.password_reset','user',$id,'Password reset through verified email; previous sessions revoked');
        });
        json_out(['ok'=>true,'message'=>'Your password has been reset. Sign in with your new password.']);
    } catch(GovernanceError $e) { json_error($e->getMessage(),$e->status); }
}

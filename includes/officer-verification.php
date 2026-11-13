<?php
declare(strict_types=1);
require_once __DIR__ . '/governance.php';
require_once __DIR__ . '/mailer.php';
const OFFICER_LINK_MINUTES = 30;
const OFFICER_RESEND_SECONDS = 60;
const OFFICER_HOURLY_SENDS = 5;

function officer_verification_base_url(): string
{
    // Never build security links from an untrusted Host or forwarded header.
    $base = rtrim(getenv('COOPVOTE_PUBLIC_URL') ?: 'https://coopvote.ludom.app', '/');
    $url = parse_url($base);
    $test = getenv('COOPVOTE_ENV') === 'test' && DB_NAME === 'coopvote_test';
    if (!$url || !isset($url['host'], $url['scheme']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
        ($url['scheme'] !== 'https' && !($test && $url['scheme'] === 'http' && $url['host'] === '127.0.0.1'))) {
        governance_fail('Configure a trusted HTTPS COOPVOTE_PUBLIC_URL before sending verification email.', 503);
    }
    return $base;
}
function officer_locked(PDO $pdo, int $id): array
{
    $s=$pdo->prepare("SELECT u.*,COALESCE(p.display_name,u.username) AS name FROM users u JOIN roles r ON r.role_id=u.role_id LEFT JOIN admin_profiles p ON p.user_id=u.user_id WHERE u.user_id=? AND r.slug='admin' FOR UPDATE");
    $s->execute([$id]);return $s->fetch() ?: governance_fail('Officer account not found.',404);
}
/** Caller holds the administrator role lock and owns this transaction. */
function officer_issue(PDO $pdo, array $user, string $email, string $purpose, int $actor): array
{
    $base=officer_verification_base_url();
    $s=$pdo->prepare('SELECT user_id FROM users WHERE email=? AND user_id<>?');$s->execute([$email,$user['user_id']]);
    if ($s->fetchColumn()) governance_fail('That email address is already in use.',409);
    $s=$pdo->prepare('SELECT *, TIMESTAMPDIFF(SECOND,requested_at,NOW()) AS age,TIMESTAMPDIFF(SECOND,window_started_at,NOW()) AS window_age FROM officer_email_verifications WHERE user_id=? FOR UPDATE');
    $s->execute([$user['user_id']]);$old=$s->fetch();
    if ($old && (int)$old['age']<OFFICER_RESEND_SECONDS) governance_fail('Wait at least 60 seconds before requesting another verification email.',429);
    $count=($old && (int)$old['window_age']<3600)?(int)$old['send_count']+1:1;
    if ($count>OFFICER_HOURLY_SENDS) governance_fail('Verification email limit reached. Try again after the one-hour window.',429);
    $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
    $pdo->prepare("INSERT INTO officer_email_verifications(user_id,purpose,pending_email,token_hash,expires_at,requested_by,requested_at,window_started_at,send_count) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE),?,NOW(),NOW(),?) ON DUPLICATE KEY UPDATE purpose=VALUES(purpose),pending_email=VALUES(pending_email),token_hash=VALUES(token_hash),expires_at=VALUES(expires_at),requested_by=VALUES(requested_by),requested_at=NOW(),window_started_at=IF(?=1,NOW(),window_started_at),send_count=VALUES(send_count),failed_attempts=0,delivery_status='pending',consumed_at=NULL")
        ->execute([$user['user_id'],$purpose,$email,$hash,$actor,$count,$count]);
    audit($actor,'officer.verification_requested','user',(int)$user['user_id'],'Requested '.($purpose==='invite'?'account invitation':'email change').' verification');
    return ['id'=>(int)$user['user_id'],'name'=>$user['name'],'email'=>$email,'purpose'=>$purpose,'hash'=>$hash,'url'=>$base.'/officer-verification.php#token='.$token];
}
/** Only test fixtures can capture email; never return a link/token in an API response. */
function officer_deliver(array $job): bool
{
    $sent=false;
    $capture=getenv('COOPVOTE_OFFICER_TEST_OUTBOX');
    if ($capture && getenv('COOPVOTE_ENV')==='test' && DB_NAME==='coopvote_test' &&
        str_starts_with(DB_SETTINGS['socket'],'/tmp/coopvote-db-tests-') &&
        realpath(dirname($capture))===realpath(dirname(DB_SETTINGS['socket']))) {
        $sent=file_put_contents($capture,json_encode($job,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX)!==false;
        if ($sent) chmod($capture,0600);
    } else {
        $invite=$job['purpose']==='invite';
        $sent=send_mail($job['email'],$job['name'],'CoopVote — '.($invite?'Verify your officer account':'Verify your new email'),
            $invite?'Set up your officer account':'Confirm your email change',
            $invite?'Verify this email address and choose your own password to activate your officer account.':'Your current email stays in use until you verify this address and confirm your current password.',
            ['Link expires'=>'30 minutes'], 'Use only the newest email. This link works once. If you did not expect this request, contact your cooperative administrator.',
            ['url'=>$job['url'],'label'=>$invite?'Verify email and set password':'Verify new email']);
    }
    db()->prepare('UPDATE officer_email_verifications SET delivery_status=? WHERE user_id=? AND token_hash=?')->execute([$sent?'sent':'failed',$job['id'],$job['hash']]);
    return $sent;
}
function officer_delivery_response(?array $job, string $message): array
{
    if (!$job) return ['ok'=>true,'verificationRequired'=>false,'message'=>$message];
    $sent=officer_deliver($job);
    return ['ok'=>true,'verificationRequired'=>true,'mailSent'=>$sent,'expiresInMinutes'=>OFFICER_LINK_MINUTES,
        'message'=>$sent?$message.' A verification email was sent.':$message.' Email delivery failed. Check mail settings, then use Resend Verification.'];
}
function officer_link(PDO $pdo, string $token, bool $lock=false): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) governance_fail('This verification link is invalid, expired, or already used. Ask an administrator to resend it.',400);
    $s=$pdo->prepare("SELECT v.*,u.status,u.email AS current_email,u.email_verified_at,u.password_hash,u.username,COALESCE(p.display_name,u.username) AS name FROM officer_email_verifications v JOIN users u ON u.user_id=v.user_id JOIN roles r ON r.role_id=u.role_id LEFT JOIN admin_profiles p ON p.user_id=u.user_id WHERE v.token_hash=? AND v.consumed_at IS NULL AND v.expires_at>NOW() AND r.slug='admin'".($lock?' FOR UPDATE':''));
    $s->execute([hash('sha256',$token)]);$row=$s->fetch();
    if (!$row || ($row['purpose']==='invite' ? $row['status']!=='pending_verification' : $row['status']!=='active'))
        governance_fail('This verification link is invalid, expired, or already used. Ask an administrator to resend it.',400);
    return $row;
}
function officer_finish(string $token, string $password, string $confirm): array
{
    $result=db_transaction(static function(PDO $pdo) use($token,$password,$confirm): array {
        admin_accounts_lock($pdo);$row=officer_link($pdo,$token,true);$id=(int)$row['user_id'];
        if ($row['purpose']==='invite') {
            if ($error=changed_password_error($password)) governance_fail($error);
            if ($password!==$confirm) governance_fail('Passwords do not match.');
            $pdo->prepare("UPDATE users SET status='active',email_verified_at=NOW(),password_hash=?,failed_attempts=0,lockout_count=0,locked_until=NULL WHERE user_id=?")->execute([password_hash($password,PASSWORD_BCRYPT),$id]);
        } else {
            if (strlen($password)>72 || !password_verify($password,$row['password_hash'])) {
                $pdo->prepare('UPDATE officer_email_verifications SET token_hash=IF(failed_attempts>=4,NULL,token_hash),failed_attempts=failed_attempts+1 WHERE user_id=?')->execute([$id]);
                return ['error'=>(int)$row['failed_attempts']>=4?'Too many incorrect passwords. Ask an administrator to resend verification.':'The current password is incorrect.','status'=>422];
            }
            $s=$pdo->prepare('SELECT user_id FROM users WHERE email=? AND user_id<>?');$s->execute([$row['pending_email'],$id]);
            if ($s->fetchColumn()) governance_fail('This email address is now in use. Ask an administrator to choose another address.',409);
            $pdo->prepare('UPDATE users SET email=?,email_verified_at=NOW() WHERE user_id=?')->execute([$row['pending_email'],$id]);
        }
        $pdo->prepare('UPDATE officer_email_verifications SET consumed_at=NOW(),verified_at=NOW(),token_hash=NULL WHERE user_id=?')->execute([$id]);
        audit($id,$row['purpose']==='invite'?'officer.email_verified':'officer.email_changed','user',$id,'Completed officer email ownership verification');
        return ['ok'=>true,'message'=>$row['purpose']==='invite'?'Your email is verified and your officer account is active. Sign in with your username and new password.':'Your new email is verified and saved. Your password is unchanged.'];
    });
    if (isset($result['error'])) governance_fail($result['error'],$result['status']);
    return $result;
}
function handle_officer_verification(): never
{
    require_method('POST');require_json_request();
    try {
        $token=input_scalar('token');$action=input_str('action');
        if ($action==='inspect') {
            $row=officer_link(db(),$token);
            json_out(['ok'=>true,'purpose'=>$row['purpose'],'name'=>$row['name'],'email'=>mask_email($row['pending_email'])]);
        }
        if ($action==='complete') json_out(officer_finish($token,input_scalar('password'),input_scalar('confirmPassword')));
        governance_fail('Unknown verification action.',404);
    } catch(GovernanceError $e) { json_error($e->getMessage(),$e->status); }
    catch(PDOException $e) { if ($e->getCode()==='23000') json_error('This email address is already in use. Ask your administrator to update the request.',409); throw $e; }
}

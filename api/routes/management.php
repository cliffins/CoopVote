<?php
/**
 * Account-scoped themes and administrator election review endpoints.
 * Officers use the existing admin role; no third role is introduced.
 * Preferences always belong to the authenticated user, never a submitted ID.
 * Every other operation requires an active administrator session.
 * Approval requests snapshot both election content and the required count.
 * A changed election needs a new request; old decisions are retained.
 * One unique decision per request and admin prevents repeat-click inflation.
 * Rejections block opening until a replacement request is reviewed.
 * The admin role row serializes reviews, settings and account deactivation.
 * New officers verify email and choose their password before activation.
 * Reaching the threshold permits explicit opening; it never opens voting itself.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin-approvals.php';
require_once __DIR__ . '/voter.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/officer-verification.php';

function handle_management(array $args): never
{
    try {
        management_dispatch($args);
    } catch (GovernanceError $e) {
        json_error($e->getMessage(), $e->status);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000')
            json_error('This record already exists or has changed. Reload and try again.', 409);
        throw $e;
    }
}

function management_dispatch(array $args): never
{
    $action = $args[0] ?? 'overview';
    $method = $_SERVER['REQUEST_METHOD'];
    $pdo = db();
    $me = require_login();
    $uid = (int) $me['user_id'];
    $admin = $me['role_slug'] === 'admin';
    if ($action === 'preferences') {
        $branding = null;
        if ($method === 'PUT') {
            require_json_request();
            $theme = input_str('theme');
            if (!in_array($theme, GOVERNANCE_THEMES, true))
                governance_fail('Choose one of the six themes.');
            $resetBranding = input('resetBranding', false);
            if (!is_bool($resetBranding))
                governance_fail('Choose a valid default-theme action.');
            if ($resetBranding && !$admin)
                governance_fail('Only administrators can reset the cooperative branding.', 403);
            if ($resetBranding && $theme !== 'navy')
                governance_fail('Branding can be reset with the Default theme.');
            $branding = $resetBranding ? branding_defaults() : null;
            db_transaction(static function (PDO $pdo) use ($uid, $theme, $branding): void {
                $pdo->prepare('INSERT INTO user_preferences (user_id,theme) VALUES (?,?) ON DUPLICATE KEY UPDATE theme=VALUES(theme)')->execute([$uid, $theme]);
                if ($branding !== null)
                    branding_store($pdo, $branding);
            });
            if ($branding !== null)
                audit($uid, 'branding.updated', 'settings', null, 'Restored the default logo, logo text and cooperative name with the Default theme');
        } else
            require_method('GET');
        $s = $pdo->prepare('SELECT theme FROM user_preferences WHERE user_id=?');
        $s->execute([$uid]);
        $data = ['theme' => $s->fetchColumn() ?: 'navy', 'themes' => GOVERNANCE_THEMES];
        if ($branding !== null)
            $data['branding'] = $branding;
        json_out($data);
    }
    require_admin();
    if ($action === 'overview') {
        require_method('GET');
        $elections = [];
        foreach ($pdo->query('SELECT * FROM elections ORDER BY start_datetime DESC')->fetchAll() as $e) {
            $eid = (int) $e['election_id'];
            $elections[] = [
                'id' => $eid,
                'title' => $e['title'],
                'status' => election_status($e),
                'start' => $e['start_datetime'],
                'end' => $e['end_datetime'],
                'positions' => vt_positions($eid),
                'request' => governance_request($pdo, $eid),
                'candidates' => array_map(static fn($c) => ['name' => $c['full_name'], 'position' => $c['position_name'], 'platform' => $c['platform'], 'active' => (bool) $c['is_active']], vt_candidates($eid))
            ];
        }
        json_out(['elections' => $elections, 'requiredApprovals' => (int) $pdo->query('SELECT required_approvals FROM governance_config WHERE id=1')->fetchColumn(), 'activeAdmins' => governance_admin_count($pdo)]);
    }
    if ($action === 'elections' && !empty($args[1])) {
        require_method('POST');
        require_json_request();
        $eid = (int) $args[1];
        $operation = $args[2] ?? '';
        if ($operation === 'submit') {
            $raw = input_str('proposedEnd');
            $end = null;
            if ($raw !== '') {
                $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw);
                if (!$d || $d->format('Y-m-d\TH:i') !== $raw || $d->getTimestamp() <= time())
                    governance_fail('Choose a reopening deadline in the future.');
                $end = $d->format('Y-m-d H:i:s');
            }
            db_transaction(static function (PDO $pdo) use ($eid, $uid, $end): void {
                governance_lock($pdo);
                $e = governance_election($pdo, $eid);
                $state = election_status($e);
                if (!in_array($state, ['Draft', 'Upcoming', 'Closed'], true))
                    governance_fail('Only draft, upcoming or closed elections can be submitted for opening approval.', 409);
                if (($state === 'Closed') !== ($end !== null))
                    governance_fail('A closed election needs a proposed reopening deadline. A draft election uses its saved dates.');
                if ($state === 'Draft' && strtotime($e['end_datetime']) <= time())
                    governance_fail('Update the election end date before requesting approval.');
                if (!vt_positions($eid) || !array_filter(vt_candidates($eid), static fn($c) => (bool) $c['is_active']))
                    governance_fail('Add positions and active candidates before requesting opening approval.', 409);
                $threshold = (int) $pdo->query('SELECT required_approvals FROM governance_config WHERE id=1')->fetchColumn();
                if (governance_admin_count($pdo) < $threshold)
                    governance_fail('There are not enough active admin accounts for the required approvals.', 409);
                $fingerprint = governance_fingerprint($pdo, $eid, $end);
                $old = governance_request($pdo, $eid);
                if ($old && !$old['stale'] && !$old['rejections'] && $old['fingerprint'] === $fingerprint && (int) $old['required_approvals'] === $threshold)
                    return;
                $pdo->prepare("UPDATE election_approval_requests SET state='superseded' WHERE election_id=? AND state='pending'")->execute([$eid]);
                $pdo->prepare('INSERT INTO election_approval_requests (election_id,fingerprint,required_approvals,requested_by,proposed_end) VALUES (?,?,?,?,?)')->execute([$eid, $fingerprint, $threshold, $uid, $end]);
            });
            audit($uid, 'election.approval_requested', 'election', $eid, 'Requested admin approval for election ' . audit_subject('election', $eid));
            json_out(['ok' => true]);
        }
        if ($operation === 'decide') {
            $decision = input_str('decision');
            $note = input_str('note');
            $requestId = (int) input('requestId');
            if (!in_array($decision, ['approve', 'reject'], true) || mb_strlen($note) > 1000 || ($decision === 'reject' && !$note))
                governance_fail('Provide a valid decision and a reason when rejecting.');
            db_transaction(static function (PDO $pdo) use ($eid, $uid, $decision, $note, $requestId): void {
                governance_lock($pdo);
                governance_election($pdo, $eid);
                $r = governance_request($pdo, $eid);
                if (!$r || (int) $r['request_id'] !== $requestId || $r['stale'])
                    governance_fail('This request changed. Reload the review before deciding.', 409);
                $pdo->prepare('INSERT INTO election_admin_decisions (request_id,admin_id,decision,note) VALUES (?,?,?,?)')->execute([$requestId, $uid, $decision, $note]);
            });
            audit($uid, 'election.reviewed', 'election', $eid, ucfirst($decision) . ' decision for election ' . audit_subject('election', $eid) . ($note !== '' ? ': ' . $note : ''));
            json_out(['ok' => true]);
        }
        governance_fail('Unknown election review action.', 404);
    }
    if ($action === 'accounts') management_accounts($pdo, $uid, $method, $args);
    if ($action === 'config') {
        require_method('PUT');
        require_json_request();
        $n = filter_var(input('requiredApprovals'), FILTER_VALIDATE_INT);
        if ($n === false || $n < 1)
            governance_fail('Require at least one admin approval.');
        db_transaction(static function (PDO $pdo) use ($n): void {
            governance_lock($pdo);
            if ($n > governance_admin_count($pdo))
                governance_fail('Required approvals cannot exceed active admins.');
            $pdo->prepare('UPDATE governance_config SET required_approvals=? WHERE id=1')->execute([$n]);
        });
        audit($uid, 'approvals.settings_updated', 'settings', null, 'Required approvals: ' . $n);
        json_out(['ok' => true]);
    }
    governance_fail('Unknown management action.', 404);
}

/**
 * Account operations share the dispatcher's authenticated administrator guard.
 * The governance lock protects edits, creation and status transitions.
 * Email changes are pending until ownership and current password are confirmed.
 * Approval thresholds and self-deactivation are checked before commit.
 */
function management_accounts(PDO $pdo, int $uid, string $method, array $args): never
{
    $id=(int)($args[1]??0);$operation=$args[2]??'';
    if ($operation!=='') {
        require_method('POST');require_json_request();
        if (!in_array($operation,['resend-verification','cancel-email-change'],true)) governance_fail('Unknown officer action.',404);
        $job=db_transaction(static function(PDO $pdo) use($id,$uid,$operation): ?array {
            governance_lock($pdo);$user=officer_locked($pdo,$id);
            $q=$pdo->prepare('SELECT * FROM officer_email_verifications WHERE user_id=? FOR UPDATE');$q->execute([$id]);$pending=$q->fetch();
            if (!$pending || $pending['consumed_at']!==null) governance_fail('No pending verification request.',409);
            if ($operation==='cancel-email-change') {
                if ($pending['purpose']!=='email_change') governance_fail('This action only cancels a pending email change.',409);
                $pdo->prepare('UPDATE officer_email_verifications SET token_hash=NULL,consumed_at=NOW() WHERE user_id=?')->execute([$id]);
                audit($uid,'officer.email_change_cancelled','user',$id,'Cancelled pending email change');return null;
            }
            if ($pending['purpose']==='invite') {
                if (!in_array($user['status'],['pending_verification','deactivated'],true) || $user['email_verified_at']!==null) governance_fail('This account cannot be invited again.',409);
                $pdo->prepare("UPDATE users SET status='pending_verification' WHERE user_id=?")->execute([$id]);
            } elseif ($user['status']!=='active') governance_fail('Reactivate the officer before requesting an email change.',409);
            return officer_issue($pdo,$user,$pending['pending_email'],$pending['purpose'],$uid);
        });
        json_out(officer_delivery_response($job,$operation==='cancel-email-change'?'Email change cancelled. The current email is unchanged.':'Verification requested.'));
    }
    if ($method==='PUT' && $id) {
        require_json_request();$name=input_str('name');$email=strtolower(input_str('email'));
        if ($error=person_name_error($name)) governance_fail($error);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>100) governance_fail('Enter a valid email address.');
        $job=db_transaction(static function(PDO $pdo) use($id,$uid,$name,$email): ?array {
            governance_lock($pdo);$user=officer_locked($pdo,$id);$job=null;
            if (strcasecmp($user['email'],$email)!==0) {
                if ($user['status']==='pending_verification') {
                    $job=officer_issue($pdo,[...$user,'name'=>$name],$email,'invite',$uid);
                    $pdo->prepare('UPDATE users SET email=? WHERE user_id=?')->execute([$email,$id]);
                } elseif ($user['status']==='active') $job=officer_issue($pdo,[...$user,'name'=>$name],$email,'email_change',$uid);
                else governance_fail('Reactivate this officer before requesting an email change.',409);
            }
            $pdo->prepare('INSERT INTO admin_profiles(user_id,display_name) VALUES(?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name)')->execute([$id,$name]);
            return $job;
        });
        audit($uid,'admin.updated','user',$id,'Updated officer profile');
        json_out(officer_delivery_response($job,$job?'Officer details saved. Email verification is required.':'Officer details saved.'));
    }
    if ($method==='GET') {
        $rows=$pdo->query("SELECT u.user_id AS id,u.username,u.email,u.status,COALESCE(p.display_name,u.username) AS name,v.purpose,v.pending_email AS pendingEmail,v.delivery_status AS deliveryStatus,v.consumed_at,v.verified_at,u.email_verified_at FROM users u JOIN roles r ON r.role_id=u.role_id LEFT JOIN admin_profiles p ON p.user_id=u.user_id LEFT JOIN officer_email_verifications v ON v.user_id=u.user_id WHERE r.slug='admin' ORDER BY u.user_id")->fetchAll();
        foreach ($rows as &$row) {
            $pending=$row['purpose']!==null && $row['consumed_at']===null;
            $row['verification']=($row['status']==='pending_verification' || ($row['purpose']==='invite' && $row['email_verified_at']===null))?'pending':($row['verified_at']!==null?'verified':'existing');
            $row['canResend']=$pending && ($row['purpose']==='invite'?in_array($row['status'],['pending_verification','deactivated'],true):$row['status']==='active');
            $row['canActivate']=!($row['purpose']==='invite' && $row['email_verified_at']===null);
            $row['pendingEmail']=$pending?$row['pendingEmail']:null;
            $row['canCancelEmailChange']=$pending && $row['purpose']==='email_change';
            unset($row['consumed_at'],$row['verified_at'],$row['email_verified_at']);
        }
        unset($row);json_out($rows);
    }
    require_method('POST');require_json_request();
    if (!$id) {
        $name=input_str('name');$username=input_str('username');$email=strtolower(input_str('email'));
        if ($error=person_name_error($name)) governance_fail($error);
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/',$username) || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>100) governance_fail('Enter a valid username and email address.');
        $job=db_transaction(static function(PDO $pdo) use($name,$username,$email,$uid): array {
            governance_lock($pdo);
            $pdo->prepare("INSERT INTO users(username,email,password_hash,role_id,status,email_verified_at) VALUES(?,?,?,(SELECT role_id FROM roles WHERE slug='admin'),'pending_verification',NULL)")->execute([$username,$email,password_hash(bin2hex(random_bytes(32)),PASSWORD_BCRYPT)]);
            $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO admin_profiles(user_id,display_name) VALUES(?,?)')->execute([$id,$name]);
            return officer_issue($pdo,['user_id'=>$id,'name'=>$name],$email,'invite',$uid);
        });
        audit($uid,'admin.created','user',$job['id'],'Created officer pending verification');
        json_out([...officer_delivery_response($job,'Officer created with access blocked until email verification and password setup.'),'id'=>$job['id'],'username'=>$username],201);
    }
    $status=input_str('status');
    if (!in_array($status,['active','deactivated'],true)) governance_fail('Choose active or deactivated.');
    db_transaction(static function(PDO $pdo) use($id,$uid,$status): void {
        governance_lock($pdo);officer_locked($pdo,$id);
        if ($status==='active') {
            $q=$pdo->prepare("SELECT 1 FROM officer_email_verifications v JOIN users u ON u.user_id=v.user_id WHERE v.user_id=? AND v.purpose='invite' AND u.email_verified_at IS NULL");$q->execute([$id]);
            if ($q->fetchColumn()) governance_fail('This officer must verify their email and set a password. Use Resend Verification.',409);
        }
        if ($id===$uid && $status==='deactivated') governance_fail('You cannot deactivate your own account.',409);
        $pdo->prepare('UPDATE users SET status=? WHERE user_id=?')->execute([$status,$id]);
        if ($status==='deactivated') $pdo->prepare('UPDATE officer_email_verifications SET token_hash=NULL WHERE user_id=? AND consumed_at IS NULL')->execute([$id]);
        $required=max(1,(int)$pdo->query('SELECT required_approvals FROM governance_config WHERE id=1')->fetchColumn(),(int)$pdo->query("SELECT COALESCE(MAX(required_approvals),1) FROM election_approval_requests WHERE state='pending'")->fetchColumn());
        if (governance_admin_count($pdo)<$required) governance_fail('Keep enough active admins to satisfy the configured and pending approval requirements.',409);
    });
    audit($uid,'admin.status_changed','user',$id,ucfirst($status).' officer account');json_out(['ok'=>true]);
}

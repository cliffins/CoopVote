<?php
/** Snapshot-based election review: distinct active admins approve before opening. */
/** Snapshot-based election review: distinct active admins approve before opening. */
declare(strict_types=1);
require_once __DIR__ . '/governance.php';
function governance_lock(PDO $pdo): void { admin_accounts_lock($pdo); }
function governance_admin_count(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.slug='admin' AND u.status='active'")->fetchColumn();
}
function governance_election(PDO $pdo, int $eid): array
{
    $s=$pdo->prepare('SELECT * FROM elections WHERE election_id=? FOR UPDATE');$s->execute([$eid]);
    return $s->fetch() ?: governance_fail('Election not found.',404);
}
function governance_fingerprint(PDO $pdo, int $eid, ?string $end=null): string
{
    $s=$pdo->prepare('SELECT title,description,start_datetime,end_datetime,status FROM elections WHERE election_id=?');$s->execute([$eid]);$e=$s->fetch();
    $s=$pdo->prepare('SELECT position_id,position_name,max_vote FROM positions WHERE election_id=? ORDER BY position_id');$s->execute([$eid]);$p=$s->fetchAll();
    $s=$pdo->prepare('SELECT candidate_id,member_id,position_id,platform,bio,is_active FROM candidates WHERE election_id=? ORDER BY candidate_id');$s->execute([$eid]);
    return hash('sha256',json_encode([$e,$p,$s->fetchAll(),$end],JSON_THROW_ON_ERROR));
}
function governance_request(PDO $pdo, int $eid): ?array
{
    $s=$pdo->prepare("SELECT * FROM election_approval_requests WHERE election_id=? AND state='pending' ORDER BY request_id DESC LIMIT 1");$s->execute([$eid]);$r=$s->fetch();
    if (!$r) return null;
    $s=$pdo->prepare("SELECT d.admin_id,d.decision,d.note,d.decided_at,COALESCE(p.display_name,u.username) AS name FROM election_admin_decisions d JOIN users u ON u.user_id=d.admin_id JOIN roles role ON role.role_id=u.role_id LEFT JOIN admin_profiles p ON p.user_id=u.user_id WHERE d.request_id=? AND role.slug='admin' AND u.status='active'");
    $s->execute([$r['request_id']]);$r['decisions']=$s->fetchAll();
    $r['approvals']=count(array_filter($r['decisions'],static fn($d)=>$d['decision']==='approve'));
    $r['rejections']=count(array_filter($r['decisions'],static fn($d)=>$d['decision']==='reject'));
    $r['stale']=($r['proposed_end'] !== null && strtotime($r['proposed_end']) <= time()) || !hash_equals($r['fingerprint'],governance_fingerprint($pdo,$eid,$r['proposed_end']));return $r;
}
function governance_open_error(PDO $pdo, int $eid, ?string $end=null): ?string
{
    $r=governance_request($pdo,$eid);
    if (!$r) return 'Submit this election for admin approval in Election Approvals first.';
    if ($r['stale']) return 'The election changed or its reopening deadline passed. Submit it for approval again.';
    if ($r['proposed_end']!==$end) return 'Use the reopening deadline approved by the admins.';
    if ($r['rejections']) return 'An admin rejected this request. Resolve the review and submit a new request.';
    if ($r['approvals']<(int)$r['required_approvals']) return 'This election needs '.$r['required_approvals'].' admin approvals; '.$r['approvals'].' received.';
    return null;
}
function governance_consume(PDO $pdo,int $eid): void
{
    $pdo->prepare("UPDATE election_approval_requests SET state='opened' WHERE election_id=? AND state='pending'")->execute([$eid]);
}

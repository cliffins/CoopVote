<?php
/** Read-only account overview; called only after the administrator route guard. */
declare(strict_types=1);
function admin_account_overview(PDO $pdo): never
{
    $voters=$pdo->query("SELECT COUNT(*) AS registered, COALESCE(SUM(u.status='active'),0) AS active FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.slug='voter'")->fetch();
    $officers=$pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(u.status='active'),0) AS active FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.slug='admin'")->fetch();
    // Deactivated accounts are deliberately not treated as invitations awaiting action.
    $pending=$pdo->query("SELECT v.purpose,v.delivery_status,v.token_hash IS NULL AS invalidated,v.expires_at<=NOW() AS expired
        FROM officer_email_verifications v JOIN users u ON u.user_id=v.user_id JOIN roles r ON r.role_id=u.role_id
        WHERE r.slug='admin' AND v.consumed_at IS NULL
        AND ((v.purpose='invite' AND u.status='pending_verification') OR (v.purpose='email_change' AND u.status='active'))")->fetchAll();
    $counts=['failed'=>0,'expired'=>0,'invite'=>0,'email_change'=>0];
    foreach($pending as $row) {
        $bucket=$row['delivery_status']==='failed'?'failed':(($row['expired'] || $row['invalidated'])?'expired':$row['purpose']);
        $counts[$bucket]++;
    }
    $labels=[
        'failed'=>['Verification email delivery failed','Check the email address and mail settings, then resend verification.'],
        'expired'=>['Verification links need renewal','These links have expired or were invalidated. Resend to continue.'],
        'invite'=>['Officers awaiting verification','Invited officers still need to verify their email and set a password.'],
        'email_change'=>['Email changes awaiting verification','Current email addresses remain in use until verification is complete.'],
    ];
    $attention=[];
    foreach($labels as $kind=>[$title,$description]) if($counts[$kind]) $attention[]=['kind'=>$kind,'title'=>$title,'description'=>$description,'count'=>$counts[$kind]];
    $activity=[];
    foreach($pdo->query("SELECT a.action,a.description,a.timestamp,COALESCE(p.display_name,u.username) AS actor
        FROM audit_logs a JOIN users u ON u.user_id=a.user_id JOIN roles r ON r.role_id=u.role_id
        LEFT JOIN admin_profiles p ON p.user_id=u.user_id
        WHERE ".admin_activity_scope()." AND a.action NOT LIKE 'ballot.%' AND a.action NOT LIKE 'vote.%'
        ORDER BY a.log_id DESC LIMIT 6")->fetchAll() as $row) {
        $activity[]=['title'=>audit_event_text($row),'actor'=>$row['actor'],'time'=>fmt_relative($row['timestamp']),'date'=>fmt_datetime($row['timestamp'])];
    }
    json_out(['voters'=>['registered'=>(int)$voters['registered'],'active'=>(int)$voters['active']],
        'officers'=>['total'=>(int)$officers['total'],'active'=>(int)$officers['active']],
        'pendingVerification'=>count($pending),'attention'=>$attention,'recentActivity'=>$activity]);
}

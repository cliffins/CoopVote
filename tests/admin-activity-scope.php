<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('COOPVOTE_DB=coopvote_test');
putenv('COOPVOTE_MAIL=0');
require __DIR__.'/../includes/auth.php';
require __DIR__.'/../api/routes/admin.php';
$pdo=db();
if (DB_NAME !== 'coopvote_test' || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coopvote_test')
    throw new RuntimeException('Only the isolated test database may be used.');
$accounts=$pdo->query("SELECT r.slug, MIN(u.user_id) AS id FROM users u JOIN roles r ON r.role_id=u.role_id GROUP BY r.slug")->fetchAll(PDO::FETCH_KEY_PAIR);
if (empty($accounts['admin']) || empty($accounts['voter'])) throw new RuntimeException('Seeded test accounts required.');
$checks=0;
$check=static function(bool $ok,string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$countSql='SELECT COUNT(*) FROM audit_logs a JOIN users u ON u.user_id=a.user_id JOIN roles r ON r.role_id=u.role_id WHERE '.admin_activity_scope();
$pdo->beginTransaction();
try {
    $before=(int)$pdo->query($countSql)->fetchColumn();
    $rawBefore=(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    $prefix='activity-scope-'.bin2hex(random_bytes(6)).':';
    $insert=$pdo->prepare('INSERT INTO audit_logs (user_id,action,description) VALUES (?,?,?)');
    $hidden=['branding.updated','branding.reset','theme.changed'];
    $visible=['admin.updated','election.reviewed','approvals.settings_updated','auth.login_failed'];
    foreach (array_merge($hidden,$visible) as $action) $insert->execute([$accounts['admin'],$action,$prefix.$action]);
    $insert->execute([$accounts['voter'],'auth.login',$prefix.'voter-login']);
    $check((int)$pdo->query($countSql)->fetchColumn()===$before+count($visible),'Appearance changes must not increase activity totals.');
    foreach ([120,500] as $limit) {
        $events=array_column(security_logs($limit),'event');
        foreach ($hidden as $action) $check(!in_array($prefix.$action,$events,true),'Appearance event leaked into list/export: '.$action);
        foreach ($visible as $action) $check(in_array($prefix.$action,$events,true),'Operational event missing: '.$action);
        $check(!in_array($prefix.'voter-login',$events,true),'Voter events must remain excluded.');
    }
    $check((int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()===$rawBefore+8,'Filtering must not erase stored audit history.');
} finally {
    $pdo->rollBack();
}
echo "Activity scope: $checks checks passed; test rows rolled back.\n";

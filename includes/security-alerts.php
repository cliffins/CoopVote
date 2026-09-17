<?php
/** Explainable security rules; review acknowledges an alert but never hides new attempts. */
declare(strict_types=1);

function security_alerts(): array
{
    $rows = db()->query("SELECT ip_address, COUNT(*) AS attempts,
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS recent_attempts,
        COUNT(DISTINCT identifier) AS accounts, MAX(created_at) AS last_seen, MAX(attempt_id) AS last_id
        FROM login_attempts WHERE success = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        GROUP BY ip_address HAVING COUNT(*) >= 3 ORDER BY recent_attempts DESC, attempts DESC LIMIT 30")->fetchAll();
    $reviews = db()->query('SELECT alert_key, reviewed_at FROM security_alert_reviews')->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_map(static function ($r) use ($reviews): array {
        $spray = (int) $r['accounts'] >= 5;
        $burst = (int) $r['recent_attempts'] >= 10;
        $kind = $spray ? 'multiple_accounts' : ($burst ? 'rapid_failures' : 'repeated_failures');
        $id = hash('sha256', $r['ip_address'] . '|' . $kind . '|' . $r['last_id']);
        return ['id' => $id, 'ip' => $r['ip_address'], 'attempts' => (int) $r['attempts'],
            'severity' => $spray || $burst ? 'high' : 'medium', 'lastSeen' => $r['last_seen'],
            'reviewed' => isset($reviews[$id]) && $reviews[$id] >= $r['last_seen'],
            'reason' => $spray ? 'Failed sign-ins across ' . $r['accounts'] . ' account identifiers in 24 hours'
                : ($burst ? $r['recent_attempts'] . ' failed sign-ins in 15 minutes' : $r['attempts'] . ' failed sign-ins in 24 hours')];
    }, $rows);
}

function security_review_alert(): never
{
    require_method('POST');
    $me = require_admin();
    require_json_request();
    $id = input_str('id');
    $alert = null;
    foreach (security_alerts() as $a) if (hash_equals($a['id'], $id)) $alert = $a;
    if (!$alert) json_error('This alert is no longer active. Refresh the list.', 404);
    db()->prepare('INSERT INTO security_alert_reviews (alert_key, reviewed_by, reviewed_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE reviewed_by = VALUES(reviewed_by), reviewed_at = NOW()')
        ->execute([$id, (int) $me['user_id']]);
    audit((int) $me['user_id'], 'security.alert_reviewed', 'ip', null, 'Reviewed ' . $alert['severity'] . ' alert for ' . $alert['ip']);
    json_out(['ok' => true]);
}

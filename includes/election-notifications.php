<?php
/** In-app schedule notifications, recomputed from server time; read state is per account. */
declare(strict_types=1);

function election_notices(array $user, ?int $now = null): array
{
    $now ??= time();
    $admin = $user['role_slug'] === 'admin';
    $notices = [];
    $s = db()->prepare("SELECT e.*, COALESCE(b.has_voted,0) AS has_voted FROM elections e
        LEFT JOIN ballots b ON b.election_id = e.election_id AND b.user_id = ?
        WHERE e.status = 'published' ORDER BY e.start_datetime DESC LIMIT 100");
    $s->execute([(int) $user['user_id']]);
    foreach ($s->fetchAll() as $e) {
        $eid = (int) $e['election_id'];
        $start = strtotime($e['start_datetime']); $end = strtotime($e['end_datetime']);
        $type = ''; $text = '';
        if ($start > $now && $start - $now <= 7 * 86400) {
            $type = 'upcoming:' . $start;
            $text = $e['title'] . ' opens on ' . fmt_datetime($e['start_datetime']) . '.';
        } elseif ($start <= $now && $now < $end && ($admin || !$e['has_voted'])) {
            $hours = ($end - $now) / 3600;
            $type = ($hours <= 1 ? 'deadline-1h:' : ($hours <= 24 ? 'deadline-24h:' : 'open:')) . $end;
            $text = $e['title'] . ($hours <= 24 ? ' closes on ' . fmt_datetime($e['end_datetime']) . '.' : ' is open for voting.');
        } elseif ($now >= $end && $now - $end <= 7 * 86400 && !$e['results_released_at']) {
            $type = 'closed:' . $end;
            $text = $e['title'] . ' has closed. Results await administrator publication.';
        }
        if ($type !== '') $notices[] = ['id' => $eid . ':' . $type, 'text' => $text, 'electionId' => $eid,
            'href' => $admin ? 'election-management.html' : 'election.html'];
        if ($e['results_released_at'] && ($admin || $e['has_voted'])) {
            $notices[] = ['id' => $eid . ':results:' . strtotime($e['results_released_at']),
                'text' => 'Results are published for ' . $e['title'] . '.', 'electionId' => $eid, 'href' => 'results.html'];
        }
    }
    return $notices;
}

/** Only the account whose profile changed receives this event. Never expose audit descriptions or IPs. */
function profile_update_notices(array $user): array
{
    $q = db()->prepare("SELECT a.log_id,a.timestamp,u.username,p.display_name
        FROM audit_logs a LEFT JOIN users u ON u.user_id=a.user_id
        LEFT JOIN admin_profiles p ON p.user_id=a.user_id
        WHERE a.action='member.profile_updated' AND a.entity_type='user' AND a.entity_id=?
        AND NOT EXISTS (SELECT 1 FROM notification_reads nr WHERE nr.user_id=? AND nr.notice_id=CONCAT('profile:',a.log_id))
        ORDER BY a.log_id DESC LIMIT 100");
    $q->execute([(int)$user['user_id'],(int)$user['user_id']]);
    return array_map(static function(array $row): array {
        $actor = $row['username'] ?: 'removed admin account';
        if (!empty($row['display_name'])) $actor = $row['display_name'] . ' (@' . $actor . ')';
        return ['id'=>'profile:'.$row['log_id'], 'text'=>'The admin updated your profile — '.$actor.'.',
            'href'=>'profile.php','createdAt'=>$row['timestamp']];
    }, $q->fetchAll());
}

function handle_election_notifications(): never
{
    $u = require_login();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        require_json_request();
        $id = input_str('id');
        if ($id === '' || strlen($id) > 160) json_error('Invalid notification.', 422);
        db()->prepare('INSERT IGNORE INTO notification_reads (user_id, notice_id) VALUES (?,?)')->execute([(int) $u['user_id'], $id]);
        json_out(['ok' => true]);
    }
    require_method('GET');
    $s = db()->prepare('SELECT notice_id FROM notification_reads WHERE user_id = ?');
    $s->execute([(int) $u['user_id']]);
    $read = array_fill_keys($s->fetchAll(PDO::FETCH_COLUMN), true);
    $notices = array_values(array_filter(array_merge(profile_update_notices($u), election_notices($u)), static fn($n) => !isset($read[$n['id']])));
    json_out(['notices' => $notices, 'serverTime' => date('c')]);
}

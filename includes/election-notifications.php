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
    $notices = array_values(array_filter(election_notices($u), static fn($n) => !isset($read[$n['id']])));
    json_out(['notices' => $notices, 'serverTime' => date('c')]);
}

<?php
/** Shared database-side filters for the activity list and its full CSV export. */
declare(strict_types=1);

function activity_log_filters(): array
{
    $filters = [];
    foreach (['from', 'to', 'actor', 'action', 'q'] as $key) {
        $raw = $_GET[$key] ?? '';
        if (!is_string($raw)) json_error('Choose valid activity log filters.', 422);
        $filters[$key] = trim($raw);
    }
    foreach (['from', 'to'] as $key) {
        $date = $filters[$key];
        if ($date === '') continue;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < '1000-01-01' || $date > '9999-12-30') {
            json_error('Enter valid start and end dates.', 422);
        }
    }
    if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) json_error('The start date must be on or before the end date.', 422);
    if ($filters['actor'] !== '' && (!ctype_digit($filters['actor']) || (int) $filters['actor'] < 1 || (int) $filters['actor'] > 2147483647)) json_error('Choose a valid administrator.', 422);
    if ($filters['action'] !== '' && !preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $filters['action'])) json_error('Choose a valid activity type.', 422);
    if (mb_strlen($filters['q'], 'UTF-8') > 150) json_error('Use at most 150 characters for activity search.', 422);
    return $filters;
}

function activity_log_from(): string
{
    return ' FROM audit_logs a JOIN users u ON u.user_id = a.user_id JOIN roles r ON r.role_id = u.role_id
             LEFT JOIN members m ON m.user_id = a.user_id LEFT JOIN admin_profiles p ON p.user_id = a.user_id ';
}

function activity_log_where(array $filters): array
{
    $where = [admin_activity_scope()]; $params = [];
    if (!empty($filters['from'])) { $where[] = 'a.timestamp >= ?'; $params[] = $filters['from'] . ' 00:00:00'; }
    if (!empty($filters['to'])) { $where[] = 'a.timestamp < ?'; $params[] = (new DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'; }
    if (!empty($filters['actor'])) { $where[] = 'a.user_id = ?'; $params[] = (int) $filters['actor']; }
    if (!empty($filters['action'])) { $where[] = 'a.action = ?'; $params[] = $filters['action']; }
    if (($filters['q'] ?? '') !== '') {
        // Escape SQL wildcards, so searching % or _ means that literal text.
        $term = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $filters['q']) . '%';
        $where[] = "(a.description LIKE ? ESCAPE '=' OR a.action LIKE ? ESCAPE '=' OR u.username LIKE ? ESCAPE '=' OR m.full_name LIKE ? ESCAPE '=' OR p.display_name LIKE ? ESCAPE '=' OR a.ip_address LIKE ? ESCAPE '=')";
        array_push($params, ...array_fill(0, 6, $term));
    }
    return [' WHERE ' . implode(' AND ', $where), $params];
}

function activity_log_query(array $filters, ?int $limit = null, int $offset = 0): PDOStatement
{
    [$where, $params] = activity_log_where($filters);
    $sql = "SELECT a.log_id, a.user_id, a.action, a.description, a.ip_address, a.user_agent, a.timestamp,
                   COALESCE(NULLIF(p.display_name,''), m.full_name, u.username) AS actor, r.role_name"
        . activity_log_from() . $where . ' ORDER BY a.log_id DESC';
    if ($limit !== null) { $sql .= ' LIMIT ? OFFSET ?'; $params[] = $limit; $params[] = $offset; }
    $query = db()->prepare($sql);
    foreach ($params as $index => $value) $query->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $query->execute();
    return $query;
}

function activity_log_row(array $row): array
{
    return ['id' => (int) $row['log_id'], 'actorId' => (int) $row['user_id'], 'action' => $row['action'],
        'type' => audit_type($row['action']), 'event' => audit_event_text($row),
        'time' => fmt_datetime($row['timestamp']), 'timestamp' => $row['timestamp'],
        'actor' => $row['actor'], 'role' => $row['role_name'], 'ip' => $row['ip_address'] ?: '—',
        'userAgent' => trim((string) ($row['user_agent'] ?? '')),
        'flagged' => in_array($row['action'], ['auth.login_failed','auth.password_change_failed','security.ip_blocked'], true)];
}

function activity_log_page(array $filters): array
{
    $page = $_GET['page'] ?? '1';
    if (!is_string($page) || !ctype_digit($page) || (int) $page < 1 || (int) $page > 10000000) json_error('Choose a valid log page.', 422);
    [$where, $params] = activity_log_where($filters);
    $query = db()->prepare('SELECT COUNT(*)' . activity_log_from() . $where); $query->execute($params);
    $total = (int) $query->fetchColumn(); $perPage = 50;
    $pages = max(1, (int) ceil($total / $perPage)); $page = min((int) $page, $pages);
    $rows = activity_log_query($filters, $perPage, ($page - 1) * $perPage)->fetchAll();
    $actors = db()->query("SELECT DISTINCT a.user_id AS id, COALESCE(NULLIF(p.display_name,''), m.full_name, u.username) AS name"
        . activity_log_from() . ' WHERE ' . admin_activity_scope() . ' ORDER BY name, a.user_id')->fetchAll();
    $actions = db()->query('SELECT DISTINCT a.action' . activity_log_from() . ' WHERE ' . admin_activity_scope() . ' ORDER BY a.action')->fetchAll(PDO::FETCH_COLUMN);
    return ['logs' => array_map('activity_log_row', $rows), 'logPagination' => compact('total','page','pages','perPage'),
        'logOptions' => ['actors' => $actors, 'actions' => $actions]];
}

function activity_log_export(array $filters): never
{
    $rows = activity_log_query($filters);
    $file = tmpfile();
    if ($file === false) throw new RuntimeException('Could not prepare the activity export.');
    fwrite($file, "\xEF\xBB\xBF");
    fputcsv($file, ['Time (Asia/Manila)','Administrator','Role','Activity','Event','IP Address','Flag']);
    while ($row = $rows->fetch()) {
        $log = activity_log_row($row);
        $values = [$log['timestamp'], $log['actor'], $log['role'], $log['action'], $log['event'], $log['ip'], $log['flagged'] ? 'FLAGGED' : ''];
        $values = array_map(static fn($value) => preg_match('/^[=+@\-\t\r\n]/', (string) $value) ? "'" . $value : $value, $values);
        fputcsv($file, $values);
    }
    rewind($file);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="coopvote-activity-logs.csv"');
    header('Cache-Control: no-store, private');
    try { fpassthru($file); } finally { fclose($file); }
    exit;
}

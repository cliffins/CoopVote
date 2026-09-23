<?php
/** Human-readable audit subjects, saved before records can be removed. */
declare(strict_types=1);
function audit_subject(string $type, int $id): string
{
    $queries = [
        'user'=>"SELECT COALESCE(m.full_name,p.display_name,u.username) AS name, COALESCE(m.member_no,u.username) AS reference FROM users u LEFT JOIN members m ON m.user_id=u.user_id LEFT JOIN admin_profiles p ON p.user_id=u.user_id WHERE u.user_id=?",
        'candidate'=>'SELECT m.full_name AS name, p.position_name AS position, e.title AS election FROM candidates c JOIN members m ON m.member_id=c.member_id JOIN positions p ON p.position_id=c.position_id JOIN elections e ON e.election_id=c.election_id WHERE c.candidate_id=?',
        'position'=>'SELECT p.position_name AS name, e.title AS election FROM positions p JOIN elections e ON e.election_id=p.election_id WHERE p.position_id=?',
        'election'=>'SELECT title AS name FROM elections WHERE election_id=?',
        'announcement'=>'SELECT title AS name FROM announcements WHERE announcement_id=?',
    ];
    if (!isset($queries[$type])) return '(details unavailable)';
    $q = db()->prepare($queries[$type]); $q->execute([$id]); $row = $q->fetch();
    if (!$row) return '(details unavailable)';
    $quote = static fn(string $value, int $limit): string => '"' . mb_substr(preg_replace('/\s+/u',' ',trim($value)),0,$limit,'UTF-8') . '"';
    $label = $quote((string)$row['name'],80);
    if (!empty($row['reference'])) $label .= ' (' . mb_substr($row['reference'],0,35,'UTF-8') . ')';
    if (!empty($row['position'])) $label .= ' for ' . $quote($row['position'],45);
    if (!empty($row['election'])) $label .= ' in ' . $quote($row['election'],65);
    return $label;
}

/** Identify edited fields without copying private values into the audit trail. */
function audit_changed_fields(array $before, array $after, array $labels): string
{
    $changed = [];
    foreach ($labels as $field => $label) {
        if ((string)($before[$field] ?? '') !== (string)($after[$field] ?? '')) $changed[] = $label;
    }
    return $changed ? '; changed: ' . implode(', ', $changed) : '';
}

/** Keep historical descriptions intact in storage while explaining missing details. */
function audit_event_text(array $row): string
{
    $text = (string) ($row['description'] ?: $row['action']);
    $legacy = '/^(Added|Updated|Deleted|Deactivated|Withdrew|Removed) (candidate|member|election|position|notice) #\d+(.*)$/s';
    if (preg_match($legacy,$text,$m)) {
        return $m[1].' '.$m[2].' (details were not recorded in this older log)'.$m[3];
    }
    return preg_replace('/ \(user #\d+\)$/',' (details were not recorded in this older log)',$text);
}

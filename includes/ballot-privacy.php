<?php
/** NFR19 application disclosure controls. Not protection against a live DB/server operator. */
declare(strict_types=1);

const PRIVACY_MIN_PARTICIPANTS = 5; // Owner-approved minimum; additional publication safeguards still apply.

function privacy_election(int $eid): ?array
{
    $q = db()->prepare('SELECT * FROM elections WHERE election_id=?'); $q->execute([$eid]);
    return $q->fetch() ?: null;
}

function privacy_results_available(int $eid): bool
{
    $e = privacy_election($eid);
    return $e && $e['status'] === 'published' && !empty($e['results_released_at'])
        && election_status($e) === 'Closed' && vt_votes_cast($eid) >= PRIVACY_MIN_PARTICIPANTS;
}

/** Whole-position suppression avoids reconstructing a hidden cell by subtraction. */
function privacy_visible_positions(int $eid): array
{
    if (!privacy_results_available($eid)) return [];
    $ballots = vt_votes_cast($eid);
    $q = db()->prepare('SELECT position_id, SUM(n) AS selections, MAX(n) AS largest, COUNT(*) AS choices
        FROM (SELECT position_id,candidate_id,COUNT(*) n FROM votes WHERE election_id=? GROUP BY position_id,candidate_id) t GROUP BY position_id');
    $q->execute([$eid]); $ids = [];
    foreach ($q->fetchAll() as $row) {
        if ((int)$row['selections'] >= PRIVACY_MIN_PARTICIPANTS && (int)$row['largest'] < $ballots && (int)$row['choices'] >= 2) $ids[] = (int)$row['position_id'];
    }
    return $ids;
}

function privacy_message(): string
{
    return 'Candidate totals are available only after final release. Small or revealing position results are withheld for voter privacy; stored votes are unchanged.';
}

/** Migration must run under maintenance before the new vote writer is enabled. */
function privacy_assert_vote_schema(PDO $pdo): void
{
    $columns = $pdo->query('SHOW COLUMNS FROM votes')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    if (str_contains($columns['vote_id']['Extra'] ?? '', 'auto_increment') || ($columns['cast_date']['Null'] ?? '') !== 'YES') {
        throw new RuntimeException('Voting privacy upgrade is incomplete. Contact the administrator.');
    }
}

/** Random IDs have no casting-order meaning and are never returned to a voter. */
function privacy_vote_id(): int
{
    return random_int(1 << 40, PHP_INT_MAX - 1);
}

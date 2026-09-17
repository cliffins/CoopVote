<?php
/** Signed, persistent election summaries, with no individual voter information. */
declare(strict_types=1);
require_once __DIR__ . '/vote-integrity.php';

/** Build a point-in-time tally; ties are explicit rather than arbitrary winners. */
function election_report_data(array $e): array
{
    $eid = (int) $e['election_id'];
    $total = vt_total_voters();
    // Freeze the current eligible-member denominator with the snapshot; later
    // account changes must not rewrite a historical turnout percentage.
    $cast = vt_votes_cast($eid);
    $positions = [];
    foreach (vt_grouped_positions($eid, true, true, true) as $p) {
        $candidates = $p['candidates'];
        $top = $candidates ? max(array_column($candidates, 'votes')) : 0;
        $leaders = count(array_filter($candidates, static fn($c) => (int) $c['votes'] === (int) $top));
        $positions[] = ['name' => $p['name'], 'candidates' => array_map(static fn($c) => [
            'name' => trim($c['first'] . ' ' . $c['last']), 'votes' => (int) $c['votes'],
            'result' => (int) $c['votes'] === (int) $top && $top > 0 ? ($leaders > 1 ? 'Tied' : 'Leading') : '',
        ], $candidates)];
    }
    return ['version' => 1, 'electionId' => $eid, 'title' => $e['title'],
        'start' => $e['start_datetime'], 'end' => $e['end_datetime'], 'generatedAt' => date('c'),
        'electionStatus' => election_status($e), 'registeredVoters' => $total, 'ballotsCast' => $cast,
        'participationRate' => $total ? round($cast / $total * 100, 2) : 0, 'integrity' => integrity_verify($eid),
        'positions' => $positions];
}

/** One snapshot per closure. Locked with vote writers, so a report cannot race a vote. */
function election_report_capture(int $eid): ?array
{
    return db_transaction(static function (PDO $pdo) use ($eid): ?array {
        $s = $pdo->prepare('SELECT * FROM elections WHERE election_id = ? FOR UPDATE');
        $s->execute([$eid]);
        $e = $s->fetch();
        if (!$e || election_status($e) !== 'Closed') return null;
        return election_report_capture_locked($pdo, $e);
    });
}

/** Caller holds the election row lock, shared with vote writers and reopening. */
function election_report_capture_locked(PDO $pdo, array $e): array
{
    $eid = (int) $e['election_id'];
    $s = $pdo->prepare('SELECT * FROM election_reports WHERE election_id = ?');
    $s->execute([$eid]);
    $row = $s->fetch();
    if ($row) {
        if (!hash_equals(integrity_mac('election-report-v1', [$row['snapshot']]), $row['signature'])) {
            throw new RuntimeException('Saved election report signature mismatch.');
        }
        if (!$row['superseded_at']) return json_decode($row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
    }
    $report = election_report_data($e);
    $json = json_encode($report, JSON_THROW_ON_ERROR);
    if ($row) {
        // Previous signed bytes have already been retained in history on reopening.
        $pdo->prepare('UPDATE election_reports SET snapshot = ?, signature = ?, generated_at = NOW(), superseded_at = NULL WHERE election_id = ?')
            ->execute([$json, integrity_mac('election-report-v1', [$json]), $eid]);
    } else {
        $pdo->prepare('INSERT INTO election_reports (election_id, snapshot, signature) VALUES (?,?,?)')
            ->execute([$eid, $json, integrity_mac('election-report-v1', [$json])]);
    }
    return $report;
}

/** Retain each signed closing report; no old report or ballot is deleted. */
function election_report_supersede(PDO $pdo, array $e, int $adminId): void
{
    $eid = (int) $e['election_id'];
    election_report_capture_locked($pdo, $e);
    $s = $pdo->prepare('SELECT * FROM election_reports WHERE election_id = ?');
    $s->execute([$eid]);
    $row = $s->fetch();
    $history = json_decode($row['history'] ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    $history[] = ['snapshot' => $row['snapshot'], 'signature' => $row['signature'],
        'generatedAt' => $row['generated_at'], 'reopenedAt' => date('c'), 'reopenedBy' => $adminId];
    $pdo->prepare('UPDATE election_reports SET history = ?, superseded_at = NOW() WHERE election_id = ?')
        ->execute([json_encode($history, JSON_THROW_ON_ERROR), $eid]);
}

/** Cron and admin reads catch scheduled closures even when no close button is pressed. */
function election_reports_due(): void
{
    $rows = db()->query("SELECT e.election_id FROM elections e LEFT JOIN election_reports r ON r.election_id = e.election_id
        WHERE e.status = 'published' AND e.end_datetime <= NOW() AND (r.election_id IS NULL OR r.superseded_at IS NOT NULL) ORDER BY e.election_id LIMIT 10")->fetchAll();
    foreach ($rows as $e) election_report_capture((int) $e['election_id']);
}

/** Project verified historical snapshots without changing their stored bytes or signatures. */
function election_report_view(array $report): array
{
    unset($report['quorum']);
    $total = (int) $report['registeredVoters'];
    $report['participationRate'] = $total ? round((int) $report['ballotsCast'] / $total * 100, 2) : 0;
    return $report;
}

/** HTML is printable to PDF by the browser; CSV is a full summary plus position tallies. */
function election_report_download(array $report, string $format): never
{
    $report = election_report_view($report);
    $rows = [['Election', $report['title']], ['Election status', $report['electionStatus']],
        ['Report generated at', $report['generatedAt']],
        ['Voting starts', $report['start']], ['Voting ends', $report['end']],
        ['Registered voters at report generation', $report['registeredVoters']], ['Ballots cast', $report['ballotsCast']],
        ['Participation rate (%)', $report['participationRate']],
        ['Integrity at report generation', $report['integrity']['status']],
        ['Existing votes protected from migration baseline', $report['integrity']['baselineVotes']], [],
        ['Candidate results'],
        ['Position', 'Candidate', 'Votes', 'Result']];
    foreach ($report['positions'] as $p) foreach ($p['candidates'] as $c) $rows[] = [$p['name'], $c['name'], $c['votes'], $c['result']];
    if ($format === 'csv') {
        // A four-column document keeps summary cells and candidate totals aligned.
        $csvRows = array_map(static fn($row) => array_pad($row, 4, ''), $rows);
        csv_out('coopvote-election-' . $report['electionId'] . '-summary.csv', ['CoopVote Election Summary', '', '', ''], $csvRows);
    }
    $escape = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>CoopVote Election Report</title><style>body{background:#fff;color:#142139;font:16px/1.5 Arial,sans-serif;max-width:960px;margin:32px auto;padding:0 20px}h1{font-family:Georgia,serif}table{border-collapse:collapse;width:100%}td{padding:9px;border-bottom:1px solid #d4dbe5;overflow-wrap:anywhere}tr:first-child{font-weight:bold}button{padding:10px 18px;background:#142139;color:#fff;border:0;cursor:pointer}button:focus-visible{outline:3px solid #b68a29} @media print{button{display:none}body{margin:0}} </style>';
    $label = $report['electionStatus'] === 'Closed' ? 'Saved election summary.' : 'Preview of an election still in progress; totals may change.';
    echo '<h1>' . $escape($report['title']) . '</h1><p>' . $label . ' Integrity status applies at generation time. Leading totals are not a certification of election winners.</p>';
    echo '<button onclick="window.print()">Print / Save as PDF</button><table aria-label="Election report"><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) echo '<td>' . $escape($cell) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></html>';
    exit;
}

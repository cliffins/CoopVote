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
    foreach (vt_grouped_positions($eid, true, true, true, true) as $p) {
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
    // Project through today's disclosure policy; never change signed historical bytes.
    $allowed = privacy_visible_positions((int)$report['electionId']);
    $q = db()->prepare('SELECT position_id,position_name FROM positions WHERE election_id=?');
    $q->execute([(int)$report['electionId']]);
    $positions = $q->fetchAll(); $nameCounts = array_count_values(array_column($positions, 'position_name'));
    $names = []; foreach ($positions as $p) if (($nameCounts[$p['position_name']] ?? 0) === 1 && in_array((int)$p['position_id'], $allowed, true)) $names[] = $p['position_name'];
    $report['positions'] = array_values(array_filter($report['positions'], static fn($p) => in_array($p['name'], $names, true)));
    $report['integrity'] = ['status'=>$report['integrity']['status']];
    $report['privacyNotice'] = privacy_message();
    $total = (int) $report['registeredVoters'];
    $report['participationRate'] = $total ? round((int) $report['ballotsCast'] / $total * 100, 2) : 0;
    return $report;
}

/** HTML is printable to PDF by the browser; CSV is a full summary plus position tallies. */
function election_report_download(array $report, string $format): never
{
    $report = election_report_view($report);
    $summaryRows = [['Election', $report['title']], ['Election status', $report['electionStatus']],
        ['Report generated at', $report['generatedAt']],
        ['Voting starts', $report['start']], ['Voting ends', $report['end']],
        ['Registered voters at report generation', $report['registeredVoters']], ['Ballots cast', $report['ballotsCast']],
        ['Participation rate (%)', $report['participationRate']],
        ['Integrity at report generation', $report['integrity']['status']],
        ['Privacy', $report['privacyNotice']]];
    $rows = array_merge($summaryRows, [[],
        ['Candidate results'],
        ['Position', 'Candidate', 'Votes', 'Result']]);
    foreach ($report['positions'] as $p) foreach ($p['candidates'] as $c) $rows[] = [$p['name'], $c['name'], $c['votes'], $c['result']];
    if ($format === 'csv') {
        // A four-column document keeps summary cells and candidate totals aligned.
        $csvRows = array_map(static fn($row) => array_pad($row, 4, ''), $rows);
        csv_out('coopvote-election-' . $report['electionId'] . '-summary.csv', ['CoopVote Election Summary', '', '', ''], $csvRows);
    }
    $escape = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>CoopVote Election Report</title>
<style>
body{background:#fff;color:#142139;font:16px/1.5 Arial,sans-serif;max-width:960px;margin:32px auto;padding:0 20px}
h1{font-family:Georgia,serif;line-height:1.2;overflow-wrap:anywhere}
h2{font-size:1.25rem;margin:28px 0 12px}
table{border-collapse:collapse;width:100%;table-layout:fixed}
th,td{padding:10px 12px;border-bottom:1px solid #d4dbe5;text-align:left;vertical-align:top}
.summary-table{margin-top:24px}
.summary-table th{width:36%;font-weight:600}
.summary-table th,.summary-table td,.results-table td{overflow-wrap:anywhere}
.results-scroll{overflow-x:auto}
.results-table{min-width:36rem}
.results-table thead{background:#edf1f6}
.results-table th{white-space:nowrap}
.results-table .votes-column{width:5rem}
.results-table .result-column{width:6rem}
.results-table .votes,.results-table .result{white-space:nowrap;overflow-wrap:normal;word-break:normal}
.results-table .votes{text-align:right;font-variant-numeric:tabular-nums}
button{padding:10px 18px;background:#142139;color:#fff;border:0;border-radius:4px;cursor:pointer;font:inherit}
button:focus-visible,.results-scroll:focus-visible{outline:3px solid #b68a29;outline-offset:3px}
@media(max-width:600px){body{margin:20px auto;padding:0 16px}h1{font-size:1.8rem}}
@media print{button{display:none}body{margin:0;padding:0;font-size:10pt}.results-scroll{overflow:visible}.results-table{min-width:0}thead{display:table-header-group}tr{break-inside:avoid}h2{break-after:avoid}}
</style></head><body>
HTML;
    $label = $report['electionStatus'] === 'Closed' ? 'Saved election summary.' : 'Preview of an election still in progress; totals may change.';
    echo '<h1>' . $escape($report['title']) . '</h1><p>' . $label . ' Integrity status applies at generation time. Leading totals are not a certification of election winners.</p>';
    echo '<button type="button" onclick="window.print()">Print / Save as PDF</button><table class="summary-table" aria-label="Election summary"><tbody>';
    foreach ($summaryRows as [$label, $value]) {
        echo '<tr><th scope="row">' . $escape($label) . '</th><td>' . $escape($value) . '</td></tr>';
    }
    echo '</tbody></table><h2 id="candidate-results-heading">Candidate results</h2>';
    echo '<div class="results-scroll" role="region" aria-labelledby="candidate-results-heading" tabindex="0"><table class="results-table" aria-labelledby="candidate-results-heading">';
    echo '<colgroup><col><col><col class="votes-column"><col class="result-column"></colgroup>';
    echo '<thead><tr><th scope="col">Position</th><th scope="col">Candidate</th><th scope="col" class="votes">Votes</th><th scope="col" class="result">Result</th></tr></thead><tbody>';
    foreach ($report['positions'] as $p) foreach ($p['candidates'] as $c) {
        echo '<tr><td>' . $escape($p['name']) . '</td><td>' . $escape($c['name']) . '</td><td class="votes">' . $escape($c['votes']) . '</td><td class="result">' . $escape($c['result']) . '</td></tr>';
    }
    echo '</tbody></table></div></body></html>';
    exit;
}

<?php
/**
 * Administrator election views, state transitions and signed reports.
 * Opening locks administrator policy before the election row so review and
 * account changes cannot invalidate the approval count during the transition.
 * The UI readiness flag is advisory; every opening checks approvals again.
 * Reopening preserves ballots and archives the prior report before hiding results.
 */
declare(strict_types=1);
require_once __DIR__ . '/election-reports.php';
require_once __DIR__ . '/admin-approvals.php';

/** Call under an election row lock when authorizing a write. */
function election_preopening_error(PDO $pdo, array $e): ?string
{
    $state = election_status($e);
    if (!in_array($state, ['Draft', 'Upcoming', 'Postponed'], true)) {
        return 'Only an election that has not opened can be rescheduled.';
    }
    if ($state === 'Postponed' && strtotime($e['start_datetime']) <= time()) {
        return 'This election cannot be verified as unopened.';
    }
    $id = (int)$e['election_id'];
    // Include abstentions, legacy records and prior openings, even with zero votes.
    foreach ([
        'SELECT 1 FROM ballots WHERE election_id=? AND (has_voted=1 OR voted_at IS NOT NULL) LIMIT 1',
        'SELECT 1 FROM votes WHERE election_id=? LIMIT 1',
        'SELECT 1 FROM election_reports WHERE election_id=? LIMIT 1',
        "SELECT 1 FROM audit_logs WHERE entity_type='election' AND entity_id=? AND action IN ('election.published','election.reopened') LIMIT 1",
        "SELECT 1 FROM election_approval_requests WHERE election_id=? AND state='opened' LIMIT 1",
    ] as $sql) {
        $s = $pdo->prepare($sql);
        $s->execute([$id]);
        if ($s->fetchColumn()) return 'This election has already opened or contains voting records. It cannot be rescheduled.';
    }
    if (!empty($e['results_released_at'])) return 'An election with released results cannot be rescheduled.';
    return null;
}

/** Deletion is limited to elections that have never opened or recorded participation.
 * Legacy cancelled, unopened records can still be removed; cancellation does not
 * erase opening history. The write caller must hold the election row lock.
 */
function election_deletion_error(PDO $pdo, array $e): ?string
{
    if ($e['status'] === 'cancelled') $e['status'] = 'draft';
    return election_preopening_error($pdo, $e) === null ? null
        : 'This election has already opened or contains voting records and cannot be deleted.';
}

/** Election cards include approval readiness so opening actions can lead to review. */
function admin_elections_list(): array
{
    $rows        = db()->query('SELECT * FROM elections ORDER BY start_datetime DESC')->fetchAll();
    $totalVoters = vt_total_voters();
    $out         = [];

    foreach ($rows as $e) {
        $eid       = (int) $e['election_id'];
        $positions = vt_positions($eid);
        $cands     = vt_candidates($eid, true);
        $canChangeSchedule = election_preopening_error(db(), $e) === null;
        $request = governance_request(db(), $eid);
        $approvalReady = $request && !$request['stale'] && !$request['rejections'] && $request['approvals'] >= (int)$request['required_approvals'];

        $positionList = [];
        foreach ($positions as $p) {
            $pid  = (int) $p['position_id'];
            $mine = array_values(array_filter($cands, static fn($c) => (int) $c['position_id'] === $pid));
            $top  = 0;
            foreach ($mine as $c) $top = max($top, (int) $c['vote_count']);

            $positionList[] = [
                'id'             => $pid,
                'name'           => $p['position_name'],
                'candidateCount' => count($mine),
                'topVotes'       => in_array($pid, privacy_visible_positions($eid), true) ? $top : null,
                'maxVotes'       => in_array($pid, privacy_visible_positions($eid), true) ? max($top, 1) : null,
            ];
        }

        $out[] = [
            'id'           => $eid,
            'title'        => $e['title'],
            'description'  => $e['description'] ?: '',
            'startDate'    => fmt_datetime($e['start_datetime']),
            'endDate'      => fmt_datetime($e['end_datetime']),
            'startISO'     => date('Y-m-d\TH:i', strtotime((string) $e['start_datetime'])),
            'endISO'       => date('Y-m-d\TH:i', strtotime((string) $e['end_datetime'])),
            'year'         => (int) date('Y', strtotime((string) $e['start_datetime'])),
            'status'       => election_status($e),
            'canReschedule' => $canChangeSchedule,
            'canDelete'    => $e['status'] === 'cancelled' ? election_deletion_error(db(), $e) === null : $canChangeSchedule,
            'published'    => !empty($e['results_released_at']),
            'voters'       => $totalVoters,
            'voted'        => vt_votes_cast($eid),
            'candidates'   => count($cands),
            'positions'    => count($positions),
            'positionList' => $positionList,
            'approvalReady' => (bool)$approvalReady,
            'approvedEnd' => $approvalReady && $request['proposed_end'] ? str_replace(' ', 'T', substr($request['proposed_end'], 0, 16)) : null,
        ];
    }
    return $out;
}

/** Open only the exact election configuration reviewed by active admins. */
function admin_elections_open(int $electionId, array $me): never
{
    $result = db_transaction(static function (PDO $pdo) use ($electionId, $me): array {
        governance_lock($pdo);
        $e = governance_election($pdo, $electionId);
        if ($e['status'] === 'cancelled') return ['error' => 'A cancelled election cannot be opened. Create a new election instead.'];
        if (election_status($e) === 'Closed') return ['error'=>'Use Reopen Election with an approved new deadline to resume this closed election.'];
        if (election_status($e) !== 'Active' && vt_votes_cast($electionId) > 0) return ['error'=>'An election with recorded participation cannot be started through Activate. Use Reopen Election for a closed election.'];
        if (election_status($e) === 'Active') return ['ok' => true, 'status' => 'Active'];
        if (strtotime($e['end_datetime']) <= time()) return ['error' => 'The end date has passed. Use Reopen Election for a closed election.'];
        if (!vt_positions($electionId) || !array_filter(vt_candidates($electionId), static fn($c) => (bool)$c['is_active'])) return ['error' => 'Add at least one position and active candidate before starting this election.'];
        if ($error = governance_open_error($pdo, $electionId)) return ['error' => $error];
        $start = min($e['start_datetime'], date('Y-m-d H:i:s'));
        $pdo->prepare("UPDATE elections SET status='published', start_datetime=? WHERE election_id=?")->execute([$start, $electionId]);
        governance_consume($pdo, $electionId);
        audit((int)$me['user_id'], 'election.published', 'election', $electionId, 'Started approved election '.audit_subject('election',$electionId));
        return ['ok' => true, 'status' => 'Active'];
    });
    if (isset($result['error'])) json_error($result['error'], 409, ['code' => 'APPROVAL_REQUIRED']);
    json_out($result);
}

/** Reopening preserves ballots and earlier signed reports while withdrawing results.
 * Election row locking serializes this transition with voting, closing and release.
 * The caller must be an administrator through the elections route.
 */
function admin_elections_reopen(int $electionId, int $adminId): never
{
    $end = input_str('endDate');
    $date = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $end)
        ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $end) : false;
    if (!$date || $date->format('Y-m-d\TH:i') !== $end || $date->getTimestamp() <= time()) {
        json_error('Choose a new closing date and time in the future.', 422,
            ['fields' => ['endDate' => 'The new deadline must be in the future.']]);
    }
    $endSql = $date->format('Y-m-d H:i:s');
    $result = db_transaction(static function (PDO $pdo) use ($electionId, $adminId, $endSql): array {
        governance_lock($pdo);
        $s = $pdo->prepare('SELECT * FROM elections WHERE election_id = ? FOR UPDATE');
        $s->execute([$electionId]);
        $e = $s->fetch();
        if (!$e || $e['status'] !== 'published' || election_status($e) !== 'Closed') {
            return ['error' => 'Only a closed election can be reopened.', 'code' => 'NOT_CLOSED'];
        }
        if (strtotime($endSql) <= time()) return ['error' => 'Choose a deadline in the future.', 'code' => 'END_DATE_PASSED'];
        if ($error = governance_open_error($pdo, $electionId, $endSql)) {
            return ['error' => $error, 'code' => 'APPROVAL_REQUIRED'];
        }
        if (integrity_verify($electionId)['status'] !== 'passed') {
            return ['error' => 'Vote integrity must pass before reopening. Review this election in Reports.', 'code' => 'INTEGRITY_CHECK_FAILED'];
        }
        election_report_supersede($pdo, $e, $adminId);
        governance_consume($pdo, $electionId);
        $pdo->prepare('UPDATE elections SET end_datetime = ?, results_released_at = NULL, released_by = NULL WHERE election_id = ?')
            ->execute([$endSql, $electionId]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, ip_address, user_agent) VALUES (?,?,?,?,?,?,?)')
            ->execute([$adminId, 'election.reopened', 'election', $electionId,
                'Reopened voting; deadline changed from ' . $e['end_datetime'] . ' to ' . $endSql . '. Prior report retained; results unpublished.',
                client_ip(), user_agent()]);
        return ['ok' => true, 'status' => 'Active', 'endDate' => $endSql];
    });
    if (isset($result['error'])) json_error($result['error'], 409, ['code' => $result['code']]);
    json_out($result);
}

function admin_operations(array $args): never
{
    require_admin();
    $action = $args[0] ?? 'overview';
    if (!in_array($action, ['overview', 'report'], true)) json_error('Unknown election operation.', 404);
    require_method('GET');
    $eid = (int) ($_GET['election'] ?? input('electionId', 0));
    if (!$eid) $eid = (int) (vt_current_election()['election_id'] ?? 0);
    $s = db()->prepare('SELECT * FROM elections WHERE election_id = ?');
    $s->execute([$eid]);
    $e = $s->fetch();
    if (!$e) json_out(['election' => null]);
    if ($action === 'report') {
        if (!privacy_results_available($eid)) json_error(privacy_message(), 409, ['code'=>'RESULTS_PRIVATE']);
        $report = election_report_capture($eid);
        if (!$report) $report = db_transaction(static fn() => election_report_data($e));
        $format = (string) ($_GET['format'] ?? 'html');
        if ($format === 'json') json_out(election_report_view($report));
        if (!in_array($format, ['html', 'csv'], true)) json_error('Choose HTML or CSV format.', 422);
        election_report_download($report, $format);
    }
    if ($action !== 'overview') json_error('Unknown election operation.', 404);
    election_reports_due();
    $s = db()->prepare('SELECT generated_at FROM election_reports WHERE election_id = ? AND superseded_at IS NULL');
    $s->execute([$eid]);
    json_out(['election' => ['id' => $eid, 'title' => $e['title'], 'status' => election_status($e)],
        'integrity' => ['status'=>integrity_verify($eid)['status'], 'message'=>'Integrity status only; selection counts are private until final release.'],
        'reportGeneratedAt' => $s->fetchColumn() ?: null, 'updatedAt' => date('c')]);
}

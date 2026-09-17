<?php
/**
 * 4 — SYSTEM TESTING
 *
 * The whole application behaving as one, from the administrator creating an
 * election through members voting to the count being released — every step
 * driven the way a browser would drive it.
 */

declare(strict_types=1);

return static function (Suite $t, PDO $pdo, array $fx): void {

    $admin = new Http('sys_admin');
    $admin->login('t_admin');

    /* ═══ A complete election, start to finish ═══════════════ */
    $t->group('End-to-end: an election from creation to released result');

    $r = $admin->api('POST', 'elections', [
        'title'       => 'System Test Election',
        'positions'   => ['President', 'Secretary'],
        'description' => 'the whole lifecycle',
        'startDate'   => date('Y-m-d\TH:i', time() - 300),
        'endDate'     => date('Y-m-d\TH:i', time() + 7200),
    ]);
    $t->same('1. the administrator creates the election', 201, $r['status']);
    $eid = (int) ($r['json']['id'] ?? 0);

    $seats = [];
    $r = $admin->api('GET', 'positions&election=' . $eid);
    $t->same('2. both selected seats were created', 2, count($r['json'] ?? []));
    foreach ($r['json'] ?? [] as $position) {
        $seats[$position['position_name']] = (int) $position['position_id'];
    }

    $standing = array_values($fx['standing']);
    $cands = [];
    $slot  = 0;
    foreach ($seats as $seatName => $seatId) {
        foreach ([1, 2] as $k) {
            $member = $standing[$slot % count($standing)][$k]['member'];
            $r = $admin->api('POST', 'candidates', [
                'electionId' => $eid,
                'positionId' => $seatId,
                'memberId'   => $member,
                'platform'   => $seatName . ' candidate ' . $k,
            ]);
            $t->same('3. a candidate stands for ' . $seatName . ' (' . $k . ')', 201, $r['status']);
            $cands[$seatName][$k] = (int) ($r['json']['id'] ?? 0);
        }
        $slot++;
    }

    tdb_approve_election($admin, $eid);
    $r = $admin->api('PATCH', 'elections/' . $eid . '/activate');
    $t->same('4. the administrator opens the ballot', 200, $r['status']);
    $t->same('and the election reads as active', 'Active', $r['json']['status'] ?? null);

    /* ── Members vote ──────────────────────────────────────── */
    $t->group('Members cast their ballots');

    $receipts = [];
    $expected = ['President' => [1 => 0, 2 => 0], 'Secretary' => [1 => 0, 2 => 0]];

    for ($i = 1; $i <= 6; $i++) {
        $v = new Http('sys_voter' . $i);
        $r = $v->login('t_voter' . $i);
        $t->same('voter ' . $i . ' signs in', 200, $r['status']);

        $r = $v->api('GET', 'voter/ballot&election=' . $eid);
        $t->same('voter ' . $i . ' is shown the ballot', 200, $r['status']);

        /* Voters 1–4 pick candidate 1, voters 5–6 pick candidate 2. */
        $pick = $i <= 4 ? 1 : 2;
        $votes = [];
        foreach ($seats as $seatName => $seatId) {
            $votes[] = ['candidateId' => $cands[$seatName][$pick]];
            $expected[$seatName][$pick]++;
        }

        $r = $v->api('POST', 'voter/vote', ['electionId' => $eid, 'votes' => $votes]);
        $t->same('voter ' . $i . ' casts a ballot', 201, $r['status']);
        $t->matches('voter ' . $i . ' gets a receipt', '/^CV-[A-Z2-9]{8}$/', (string) ($r['json']['receiptCode'] ?? ''));
        $t->same('voter ' . $i . ' cast both selections', 2, (int) ($r['json']['selections'] ?? 0));
        $receipts[$i] = $r['json']['receiptCode'] ?? null;

        /* Voting twice must be impossible. */
        $r = $v->api('POST', 'voter/vote', ['electionId' => $eid, 'votes' => $votes]);
        $t->same('voter ' . $i . ' cannot vote a second time', 409, $r['status']);
        $t->same('and is told why', 'ALREADY_VOTED', $r['json']['code'] ?? null);
    }

    $t->same('every receipt is unique', 6, count(array_unique($receipts)));

    /* ── Turnout ───────────────────────────────────────────── */
    $t->group('Turnout is counted correctly');

    $t->same('six ballots were cast', 6, (int) $pdo->query(
        "SELECT COUNT(*) FROM ballots WHERE election_id = $eid AND has_voted = 1"
    )->fetchColumn());
    $t->same('twelve votes were recorded (6 members x 2 seats)', 12, (int) $pdo->query(
        "SELECT COUNT(*) FROM votes WHERE election_id = $eid"
    )->fetchColumn());

    $r = $admin->api('GET', 'monitor&election=' . $eid);
    $t->same('the monitor page loads', 200, $r['status']);

    /* ── Results are withheld until released ───────────────── */
    $t->group('Nothing is published while voting is open');

    $voter1 = new Http('sys_v1');
    $voter1->login('t_voter1');

    $r = $voter1->api('GET', 'voter/results');
    $ids = array_column($r['json']['elections'] ?? [], 'id');
    $t->ok('a live election is not in the members\' results', !in_array($eid, $ids, true), implode(',', $ids));

    $r = $admin->api('PATCH', 'elections/' . $eid . '/release');
    $t->same('releasing before closing is refused', 409, $r['status'],
        'a running count could sway members who have not voted');

    /* ── Close, then release ───────────────────────────────── */
    $t->group('Closing and releasing');

    $r = $admin->api('PATCH', 'elections/' . $eid . '/close');
    $t->same('5. the administrator closes the voting', 200, $r['status']);
    $t->same('and it reads as closed', 'Closed', $r['json']['status'] ?? null);

    $v7 = new Http('sys_v7');
    $v7->login('t_voter7');
    $r = $v7->api('POST', 'voter/vote', [
        'electionId' => $eid,
        'votes' => [['candidateId' => $cands['President'][1]]],
    ]);
    $t->same('a late ballot is refused once voting closes', 409, $r['status']);
    $t->same('and no extra vote was stored', 12, (int) $pdo->query(
        "SELECT COUNT(*) FROM votes WHERE election_id = $eid"
    )->fetchColumn());

    $r = $voter1->api('GET', 'voter/results');
    $ids = array_column($r['json']['elections'] ?? [], 'id');
    $t->ok('closed but unreleased results stay hidden', !in_array($eid, $ids, true), implode(',', $ids));

    $r = $admin->api('PATCH', 'elections/' . $eid . '/release');
    $t->same('6. the administrator releases the result', 200, $r['status']);
    $t->ok('the release is stamped in the database', $pdo->query(
        "SELECT results_released_at FROM elections WHERE election_id = $eid"
    )->fetchColumn() !== null);

    $r = $admin->api('PATCH', 'elections/' . $eid . '/release');
    $t->ok('releasing twice is handled without error', in_array($r['status'], [200, 409], true), (string) $r['status']);

    /* ── The count is right ────────────────────────────────── */
    $t->group('The published count matches the ballots cast');

    $r = $voter1->api('GET', 'voter/results');
    $t->same('the member can now open the result', 200, $r['status']);

    $election = null;
    foreach ($r['json']['elections'] ?? [] as $e) {
        if ((int) $e['id'] === $eid) $election = $e;
    }
    $t->ok('the election is listed', $election !== null);

    if ($election) {
        $t->same('turnout is reported as six', 6, (int) $election['votesCast']);
        $t->same('it is marked final', 'Final', $election['status']);

        $tally = [];
        foreach ($election['positions'] ?? [] as $pos) {
            foreach ($pos['candidates'] ?? [] as $c) {
                $tally[$pos['name']][$c['name']] = (int) ($c['votes'] ?? 0);
            }
        }

        foreach (['President', 'Secretary'] as $seat) {
            $counts = array_values($tally[$seat] ?? []);
            rsort($counts);
            $t->same($seat . ': the leader has four votes', 4, $counts[0] ?? null);
            $t->same($seat . ': the runner-up has two', 2, $counts[1] ?? null);
            $t->same($seat . ': the votes add up to the turnout', 6, array_sum($counts));
        }
    }

    /* ── Secrecy holds end to end ──────────────────────────── */
    $t->group('The ballot stays secret through the whole flow');

    $t->same('no vote row carries a voter', 0, (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'votes'
            AND column_name IN ('user_id','member_id','voter_id','ballot_id')"
    )->fetchColumn());

    $r = $admin->api('GET', 'monitor&election=' . $eid);
    $body = strtolower($r['body']);
    foreach ($receipts as $i => $rc) {
        $t->notContains('the monitor does not expose voter ' . $i . '\'s receipt', strtolower((string) $rc), $body);
    }

    $r = $admin->api('GET', 'results&election=' . $eid);
    $t->same('the administrator can read the tally', 200, $r['status']);
    $t->ok('but the tally names no voters', !preg_match('/t_voter\d/i', $r['body']),
        'a tally that named its voters would break the secret ballot');

    $r = $admin->api('GET', 'security');
    $t->same('the security log loads', 200, $r['status']);
    $t->ok('and records that ballots were cast without saying how',
        !preg_match('/candidate.{0,20}(President|Secretary) candidate/i', $r['body']));

    /* ── Results reach only those who took part ────────────── */
    $t->group('Only the members who voted may open the result');

    $voter8 = new Http('sys_v8');
    $voter8->login('t_voter8');

    $r = $voter8->api('GET', 'voter/results');
    $t->same('a member who did not vote gets an empty list', 0, count($r['json']['elections'] ?? []));
    $t->ok('but is told results exist that are not theirs to read',
        (int) ($r['json']['withheldCount'] ?? 0) > 0, json_encode($r['json']['withheldCount'] ?? null));

    $r = $voter8->api('GET', 'voter/results&election=' . $eid);
    $t->same('naming the election directly does not bypass it', 0, count($r['json']['elections'] ?? []));

    $r = $voter8->api('GET', 'voter/dashboard&election=' . $eid);
    $t->same('the dashboard loads for them', 200, $r['status']);
    $t->ok('the dashboard really does carry a results key', array_key_exists('results', $r['json'] ?? []),
        'without this check the next assertion would pass for the wrong reason');
    $t->same('but it shows them no winners', null, ($r['json'] ?? [])['results']);

    $r = $voter1->api('GET', 'voter/dashboard&election=' . $eid);
    $t->ok('a member who voted does see the winners', !empty($r['json']['results']),
        json_encode(array_column($r['json']['results'] ?? [], 'position')));
    $t->same('one winner per contested seat', 2, count($r['json']['results'] ?? []));
    $t->same('and the winning count is shown', 4, (int) ($r['json']['results'][0]['votes'] ?? 0));

    /* ── Receipts ──────────────────────────────────────────── */
    $t->group('Receipts');

    $r = $voter1->api('GET', 'voter/results&election=' . $eid);
    $t->same('the member sees their own receipt', $receipts[1], $r['json']['member']['receiptCode'] ?? null);

    $r = $voter8->api('GET', 'voter/results&election=' . $eid);
    $t->same('a member who did not vote has no receipt', null, $r['json']['member']['receiptCode'] ?? null);

    $stored = $pdo->query("SELECT receipt_code FROM ballots WHERE election_id = $eid")->fetchAll(PDO::FETCH_COLUMN);
    $t->same('every stored receipt is distinct', count($stored), count(array_unique($stored)));

    /* ── Parallel elections do not bleed together ──────────── */
    $t->group('Two elections running side by side stay separate');

    $t->same('the earlier live election still has no votes', 0, (int) $pdo->query(
        "SELECT COUNT(*) FROM votes WHERE election_id = {$fx['live']}"
    )->fetchColumn());

    $r = $voter1->api('GET', 'voter/ballot&election=' . $fx['live']);
    $t->same('a member who voted in one may still vote in the other', 200, $r['status']);
    $t->ok('and the other ballot is still open to them',
        !empty($r['json']['positions']), json_encode(array_column($r['json']['positions'] ?? [], 'name')));

    $r = $voter1->api('POST', 'voter/vote', [
        'electionId' => $fx['live'],
        'votes' => [['candidateId' => $fx['candidates'][$fx['live']]['Chairperson'][1]]],
    ]);
    $t->same('and they can cast it', 201, $r['status']);
    $t->same('which does not disturb the first election', 12, (int) $pdo->query(
        "SELECT COUNT(*) FROM votes WHERE election_id = $eid"
    )->fetchColumn());

    /* ── Publishing can be undone ──────────────────── */
    $t->group('A released result can be taken back');

    /*
     * Releasing used to be a one-way door: the card showed a dead
     * "Published" label and no page could clear the stamp again, so a count
     * let out by mistake could only be retracted by editing the database by
     * hand. What must stay true is that taking it back moves the flag and
     * nothing else - every ballot and every receipt survives it.
     */
    $votesBefore   = (int) $pdo->query("SELECT COUNT(*) FROM votes WHERE election_id = $eid")->fetchColumn();
    $ballotsBefore = (int) $pdo->query("SELECT COUNT(*) FROM ballots WHERE election_id = $eid")->fetchColumn();

    $r = $voter1->api('PATCH', 'elections/' . $eid . '/unpublish');
    $t->ok('a member cannot unpublish a result', in_array($r['status'], [401, 403], true),
        (string) $r['status']);

    $r = $admin->api('PATCH', 'elections/' . $eid . '/unpublish');
    $t->same('the administrator can', 200, $r['status']);
    $t->same('and the reply says it is no longer published', false, $r['json']['published'] ?? null);

    $t->ok('the release stamp is cleared', $pdo->query(
        "SELECT results_released_at FROM elections WHERE election_id = $eid"
    )->fetchColumn() === null);
    $t->ok('and so is the record of who released it', $pdo->query(
        "SELECT released_by FROM elections WHERE election_id = $eid"
    )->fetchColumn() === null, 'the audit trail still says who, and when');

    $r = $voter1->api('GET', 'voter/results');
    $ids = array_column($r['json']['elections'] ?? [], 'id');
    $t->ok('the member can no longer open the result', !in_array($eid, $ids, true), implode(',', $ids));

    $t->same('not one vote was disturbed', $votesBefore, (int) $pdo->query(
        "SELECT COUNT(*) FROM votes WHERE election_id = $eid"
    )->fetchColumn());
    $t->same('nor one ballot record', $ballotsBefore, (int) $pdo->query(
        "SELECT COUNT(*) FROM ballots WHERE election_id = $eid"
    )->fetchColumn());

    $r = $admin->api('PATCH', 'elections/' . $eid . '/unpublish');
    $t->same('unpublishing twice is handled, not an error', 200, $r['status']);
    $t->same('and says it was already hidden', true, $r['json']['alreadyHidden'] ?? null);

    $trail = implode(' ', $pdo->query(
        "SELECT action FROM audit_logs WHERE entity_type = 'election' AND entity_id = $eid"
    )->fetchAll(PDO::FETCH_COLUMN));
    $t->contains('the retraction is in the audit trail', 'election.results_unpublished', $trail);
    $t->contains('and the release it undid is still there', 'election.results_released', $trail);

    $r = $admin->api('PATCH', 'elections/' . $eid . '/release');
    $t->same('it can be published again afterwards', 200, $r['status']);

    $r = $voter1->api('GET', 'voter/results');
    $t->ok('and the member sees the result once more',
        in_array($eid, array_column($r['json']['elections'] ?? [], 'id'), true));
};

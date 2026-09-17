<?php
declare(strict_types=1);
return static function (Suite $t, PDO $pdo): void {
    if (DB_NAME !== 'coopvote_test') throw new RuntimeException('Scratch database required.');
    $fx = tdb_seed($pdo);
    $id = $fx['closed'];
    $admin = new Http('reopen_admin'); $admin->login('t_admin');
    $admin2 = new Http('reopen_admin2'); $admin2->login('t_admin');
    $oldVoter = new Http('reopen_old'); $oldVoter->login('t_voter1');
    $newVoter = new Http('reopen_new'); $newVoter->login('t_voter9');
    $anon = new Http('reopen_anon');
    $route = 'elections/' . $id . '/reopen';
    $deadline = date('Y-m-d\TH:i', time() + 86400);
    $t->group('Reopen election without resetting any ballots');
    $t->same('anonymous cannot reopen', 401, $anon->api('PATCH', $route, ['endDate' => $deadline])['status']);
    $t->same('voter cannot reopen', 403, $newVoter->api('PATCH', $route, ['endDate' => $deadline])['status']);
    foreach (['', ' ', 'yesterday', '2000-01-01T12:00', '2030-02-31T12:00', "2030-01-01T12:00\0"] as $bad) {
        $t->same('invalid deadline is rejected: ' . json_encode($bad), 422, $admin->api('PATCH', $route, ['endDate' => $bad])['status']);
    }
    foreach (['draft', 'upcoming', 'live'] as $state) {
        $t->same($state . ' cannot use reopen', 409, $admin->api('PATCH', 'elections/' . $fx[$state] . '/reopen', ['endDate' => $deadline])['status']);
    }
    $admin->api('GET', 'operations/report&election=' . $id . '&format=json');
    $saved = $pdo->query("SELECT * FROM election_reports WHERE election_id = $id")->fetch();
    $votes = $pdo->query("SELECT * FROM votes WHERE election_id = $id ORDER BY vote_id")->fetchAll();
    $ballots = $pdo->query("SELECT * FROM ballots WHERE election_id = $id ORDER BY ballot_id")->fetchAll();
    tdb_approve_election($admin, $id, $deadline);
    $r = $admin->api('PATCH', $route, ['endDate' => $deadline]);
    $t->same('closed election reopens', 200, $r['status']);
    $t->same('reopened state is Active', 'Active', $r['json']['status'] ?? null);
    $t->same('vote rows remain byte-for-byte unchanged', $votes, $pdo->query("SELECT * FROM votes WHERE election_id = $id ORDER BY vote_id")->fetchAll());
    $t->same('ballots, receipts and vote flags remain unchanged', $ballots, $pdo->query("SELECT * FROM ballots WHERE election_id = $id ORDER BY ballot_id")->fetchAll());
    $election = $pdo->query("SELECT * FROM elections WHERE election_id = $id")->fetch();
    $t->same('old result publication is cleared', null, $election['results_released_at']);
    $t->same('new deadline is stored', str_replace('T', ' ', $deadline) . ':00', $election['end_datetime']);
    $history = json_decode($pdo->query("SELECT history FROM election_reports WHERE election_id = $id")->fetchColumn(), true);
    $t->same('prior signed snapshot retained exactly', $saved['snapshot'], $history[0]['snapshot']);
    $t->same('prior signature retained exactly', $saved['signature'], $history[0]['signature']);
    $t->same('archived signature still verifies', $history[0]['signature'], integrity_mac('election-report-v1', [$history[0]['snapshot']]));
    $t->same('double click does not reopen or archive twice', 409, $admin->api('PATCH', $route, ['endDate' => $deadline])['status']);
    $t->same('running election cannot republish old results', 409, $admin->api('PATCH', "elections/$id/release")['status']);
    $t->same('published results disappear for prior participants', [], $oldVoter->api('GET', 'voter/results')['json']['elections']);
    $t->same('receipt survives reopening', 'CV-TEST1000', $oldVoter->api('GET', 'voter/receipts')['json']['receipts'][0]['receiptCode']);
    $t->same('voter dashboard treats reopened election as active', 'Active', $newVoter->api('GET', "voter/dashboard&election=$id")['json']['election']['status']);
    $preview = $admin->api('GET', "operations/report&election=$id&format=json")['json'];
    $t->same('reports show a current preview instead of the old closing report', 'Active', $preview['electionStatus']);
    $body = ['electionId' => $id, 'votes' => [['candidateId' => $fx['candidates'][$id]['Chairperson'][1]]]];
    $t->same('prior voter still cannot vote twice', 409, $oldVoter->api('POST', 'voter/vote', $body)['status']);
    $t->same('member who has not voted can now vote', 201, $newVoter->api('POST', 'voter/vote', $body)['status']);
    $t->same('new and old votes verify together', 'passed', integrity_verify($id)['status']);
    $t->same('reopened election can close again', 200, $admin->api('PATCH', "elections/$id/close")['status']);
    $report = $admin->api('GET', "operations/report&election=$id&format=json")['json'];
    $t->same('new closing report contains both voting periods', 4, $report['ballotsCast']);
    $t->same('new closing report is closed', 'Closed', $report['electionStatus']);
    $t->same('results still need explicit publication', [], $newVoter->api('GET', 'voter/results')['json']['elections']);
    $t->same('new results can be published', 200, $admin->api('PATCH', "elections/$id/release")['status']);
    $t->ok('new participant sees newly published results', count($newVoter->api('GET', 'voter/results')['json']['elections']) === 1);
    $t->same('reopening is recorded in the admin audit log', 1, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_id = $id AND action = 'election.reopened'")->fetchColumn());

    $t->group('Concurrent reopening and publication');
    tdb_approve_election($admin, $id, $deadline);
    $responses = Http::parallel([
        ['PATCH', TestServer::url('api/index.php?r=' . $route, 0), ['endDate' => $deadline], $admin->jar],
        ['PATCH', TestServer::url("api/index.php?r=elections/$id/release", 1), [], $admin2->jar],
    ]);
    $t->same('concurrent reopen succeeds', 200, $responses[0]['status']);
    $election = $pdo->query("SELECT * FROM elections WHERE election_id = $id")->fetch();
    $t->same('race leaves voting active', 'Active', election_status($election));
    $t->same('race never exposes running tallies', null, $election['results_released_at']);
    $history = json_decode($pdo->query("SELECT history FROM election_reports WHERE election_id = $id")->fetchColumn(), true);
    $t->same('each closing report remains in history', 2, count($history));
    $t->same('second archived report includes the new voter', 4, json_decode($history[1]['snapshot'], true)['ballotsCast']);
    $t->same('first archive is still intact', $saved['snapshot'], $history[0]['snapshot']);
};

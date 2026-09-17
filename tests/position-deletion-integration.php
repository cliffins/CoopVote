<?php
declare(strict_types=1);
return static function (Suite $t, PDO $pdo): void {
    if (DB_NAME !== 'coopvote_test') throw new RuntimeException('Scratch database required.');
    $fx = tdb_seed($pdo);
    $t->group('Delete positions without deleting candidates or ballots');
    $admin = new Http('position_delete_admin'); $admin->login('t_admin');
    $voter = new Http('position_delete_voter'); $voter->login('t_voter9');
    $anon = new Http('position_delete_anon');
    $r = $admin->api('POST', 'elections', ['title' => 'Position Deletion', 'positions' => ['President', 'Secretary'],
        'startDate' => date('Y-m-d\TH:i', time() + 3600), 'endDate' => date('Y-m-d\TH:i', time() + 86400)]);
    $eid = (int) $r['json']['id'];
    $rows = $admin->api('GET', 'positions&election=' . $eid)['json'];
    $pid = (int) $rows[0]['position_id'];
    $last = (int) $rows[1]['position_id'];
    $elections = $admin->api('GET', 'elections')['json'];
    $e = array_values(array_filter($elections, static fn($e) => $e['id'] === $eid))[0];
    $t->same('position cards expose the stable position ID', $pid, $e['positionList'][0]['id']);
    $route = 'positions/' . $pid;
    $t->same('anonymous cannot delete positions', 401, $anon->api('DELETE', $route)['status']);
    $t->same('voter cannot delete positions', 403, $voter->api('DELETE', $route)['status']);
    $t->same('admin deletes an unused position', 200, $admin->api('DELETE', $route)['status']);
    $t->same('position was removed', 0, (int) $pdo->query("SELECT COUNT(*) FROM positions WHERE position_id = $pid")->fetchColumn());
    $t->same('second deletion reports missing record', 404, $admin->api('DELETE', $route)['status']);
    $t->same('last position cannot be deleted', 409, $admin->api('DELETE', 'positions/' . $last)['status']);
    $t->same('last position remains', 1, (int) $pdo->query("SELECT COUNT(*) FROM positions WHERE election_id = $eid")->fetchColumn());
    $t->same('successful deletion is audited once', 1, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'position.deleted' AND entity_id = $pid")->fetchColumn());

    $livePid = $fx['positions'][$fx['live']]['Chairperson'];
    $before = $pdo->query('SELECT * FROM candidates ORDER BY candidate_id')->fetchAll();
    $r = $admin->api('DELETE', 'positions/' . $livePid);
    $t->same('position with candidates is refused', 409, $r['status']);
    $t->contains('admin is directed to candidate management', 'Candidate Management', $r['json']['error']);
    $t->same('candidate records remain unchanged', $before, $pdo->query('SELECT * FROM candidates ORDER BY candidate_id')->fetchAll());
    $pdo->exec("UPDATE candidates SET is_active = 0 WHERE position_id = $livePid");
    $t->same('inactive candidates are protected too', 409, $admin->api('DELETE', 'positions/' . $livePid)['status']);
    $pdo->exec("UPDATE candidates SET is_active = 1 WHERE position_id = $livePid");
    $r = $voter->api('POST', 'voter/vote', ['electionId' => $fx['live'], 'votes' => [['candidateId' => $fx['candidates'][$fx['live']]['Chairperson'][1]]]]);
    $t->same('fixture voter casts a genuine ballot', 201, $r['status']);
    $votes = $pdo->query('SELECT * FROM votes ORDER BY vote_id')->fetchAll();
    $ballots = $pdo->query('SELECT * FROM ballots ORDER BY ballot_id')->fetchAll();
    $r = $admin->api('DELETE', 'positions/' . $livePid);
    $t->same('position with votes is refused', 409, $r['status']);
    $t->contains('vote protection is explained', 'has votes', $r['json']['error']);
    $t->same('vote rows remain unchanged', $votes, $pdo->query('SELECT * FROM votes ORDER BY vote_id')->fetchAll());
    $t->same('ballots remain unchanged', $ballots, $pdo->query('SELECT * FROM ballots ORDER BY ballot_id')->fetchAll());
    $t->same('closed election rejects position deletion', 409, $admin->api('DELETE', 'positions/' . $fx['positions'][$fx['closed']]['Chairperson'])['status']);
};

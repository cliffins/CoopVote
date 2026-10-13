<?php
/**
 * 3 — INTEGRATION TESTING
 *
 * The seams between the parts: the browser's request reaching the right
 * route, the session surviving between calls, the guards agreeing with the
 * database, and one module's write becoming another module's read.
 */

declare(strict_types=1);

return static function (Suite $t, PDO $pdo, array $fx): void {
    (require __DIR__ . '/../profile-photo-integration.php')($t, $pdo);

    /* ── Front controller routing ──────────────────────────── */
    $t->group('Front controller — every route style reaches the same place');

    $anon = new Http('anon');

    $r = $anon->api('GET', '');
    $t->same('the API index answers', 200, $r['status']);
    $t->same('and names the service', 'CoopVote API', $r['json']['service'] ?? null);

    $q = Http::raw('GET', TestServer::url('api/index.php?r=auth/me'));
    $t->same('?r= style routing reaches auth/me', 401, $q['status']);

    $p = Http::raw('GET', TestServer::url('api/index.php/auth/me'));
    $t->same('PATH_INFO style routing reaches auth/me', 401, $p['status']);

    $r = $anon->api('GET', 'no/such/route');
    $t->same('an unknown route is a 404', 404, $r['status']);
    $t->contains('and says so in a sentence a person can read',
        'That page or action does not exist.', $r['body']);
    $t->contains('while still naming the route for whoever is debugging',
        'no/such/route', $r['body']);

    $r = $anon->api('GET', '../../config/config.php');
    $t->ok('a traversal attempt does not reach the config file', !str_contains($r['body'], 'MAIL_PASSWORD'),
        substr($r['body'], 0, 120));

    $t->group('Front controller — every response is JSON');

    foreach ([['GET', 'auth/me'], ['GET', 'no/such/route'], ['POST', 'auth/login'], ['GET', 'voter/dashboard']] as [$m, $route]) {
        $r = $anon->api($m, $route);
        $t->ok($m . ' ' . $route . ' answers with a JSON content type',
            str_contains(strtolower($r['headers']), 'content-type: application/json'));
        $t->ok($m . ' ' . $route . ' has a decodable body', $r['json'] !== null, substr($r['body'], 0, 100));
    }

    /* ── Method enforcement ────────────────────────────────── */
    $t->group('HTTP method enforcement');

    $r = $anon->api('GET', 'auth/login');
    $t->same('login refuses GET', 405, $r['status']);
    $r = Http::raw('OPTIONS', TestServer::url('api/index.php?r=auth/login'));
    $t->same('a preflight is answered without a body', 204, $r['status']);

    /* ── Session integration ───────────────────────────────── */
    $t->group('Sessions carry between requests');

    $voter = new Http('voter1');

    $r = $voter->api('GET', 'auth/me');
    $t->same('before signing in, auth/me is 401', 401, $r['status']);

    $r = $voter->login('t_voter1');
    $t->same('signing in succeeds', 200, $r['status']);
    $t->same('the response says where to go next', 'voter/dashboard.html', $r['json']['redirect'] ?? null);
    $t->contains('a session cookie is issued', 'coopvote_session', $r['headers']);
    $t->contains('the cookie is HttpOnly', 'HttpOnly', $r['headers']);

    $r = $voter->api('GET', 'auth/me');
    $t->same('the session is remembered on the next call', 200, $r['status']);
    $t->same('and identifies the same member', 'Test Member 1', $r['json']['user']['name'] ?? null);
    $t->same('with the member number attached', 'TEST-0001', $r['json']['user']['memberId'] ?? null);

    $r = $voter->logout();
    $t->same('signing out succeeds', 200, $r['status']);
    $r = $voter->api('GET', 'auth/me');
    $t->same('and the session is gone afterwards', 401, $r['status']);

    /* ── Role guards ───────────────────────────────────────── */
    $t->group('Role guards — a member cannot reach the back office');

    $voter->login('t_voter1');
    foreach (['dashboard', 'voters', 'candidates', 'elections', 'monitor', 'results', 'reports', 'security'] as $route) {
        $r = $voter->api('GET', $route);
        $t->same('a member is refused ' . $route, 403, $r['status']);
    }

    $t->group('Role guards — staff cannot use the member routes');

    $admin = new Http('admin');
    $admin->login('t_admin');
    foreach (['voter/dashboard', 'voter/ballot', 'voter/results'] as $route) {
        $r = $admin->api('GET', $route);
        $t->same('an administrator is refused ' . $route, 403, $r['status']);
    }

    $t->group('Role guards — there are two roles, and only one may write');

    /*
     * This used to check that the read-only Auditor could look but not
     * touch. Election Officer and Auditor were removed — the cooperative
     * runs an administrator and its members — so what is left to prove is
     * that those two really are the only two, and that the line between
     * them holds in both directions.
     */
    $slugs = $pdo->query('SELECT slug FROM roles ORDER BY slug')->fetchAll(PDO::FETCH_COLUMN);
    $t->same('the system knows two roles', ['admin', 'voter'], $slugs);
    $t->same('and both constants name only the administrator',
        [['admin'], ['admin']], [ADMIN_ROLES, WRITE_ROLES]);

    $member = new Http('member_guard');
    $member->login('t_voter2');

    foreach ([['GET', 'dashboard'], ['GET', 'results'], ['GET', 'voters'],
              ['GET', 'security'], ['GET', 'elections']] as [$method, $route]) {
        $r = $member->api($method, $route);
        $t->same('a member may not read ' . $route, 403, $r['status']);
    }

    $r = $member->api('POST', 'elections', [
        'title' => 'A member should not be able to make this',
        'startDate' => '2030-01-01T09:00', 'endDate' => '2030-01-02T09:00',
    ]);
    $t->same('nor create an election', 403, $r['status']);
    $t->same('and none was created', 0, (int) $pdo->query(
        "SELECT COUNT(*) FROM elections WHERE title LIKE 'A member should not%'"
    )->fetchColumn());

    $r = $member->api('POST', 'positions', ['electionId' => $fx['live'], 'name' => 'Member Position']);
    $t->same('nor add a position', 403, $r['status']);

    /* The administrator, by contrast, may do both. */
    $r = $admin->api('GET', 'dashboard');
    $t->same('an administrator may read the dashboard', 200, $r['status']);
    $r = $admin->api('GET', 'results');
    $t->same('an administrator may read results', 200, $r['status']);

    /* ── Account status guard ──────────────────────────────── */
    $t->group('Account status is enforced on every request, not just at sign-in');

    $s = new Http('suspend_me');
    $s->login('t_voter3');
    $t->same('the member is signed in', 200, $s->api('GET', 'auth/me')['status']);

    $pdo->prepare("UPDATE users SET status = 'suspended' WHERE user_id = ?")->execute([$fx['voters'][3]]);
    $r = $s->api('GET', 'voter/dashboard');
    $t->same('suspending them mid-session locks them out at once', 403, $r['status']);
    $t->same('with a machine-readable reason', 'INACTIVE', $r['json']['code'] ?? null);
    $pdo->prepare("UPDATE users SET status = 'active' WHERE user_id = ?")->execute([$fx['voters'][3]]);

    /* ── Self-registration is closed ───────────────────────── */
    $t->group('Self-registration is closed at the API, not only in the UI');

    foreach (['register', 'verify-otp', 'resend-otp'] as $route) {
        $r = $anon->api('POST', 'auth/' . $route, ['username' => 'x', 'password' => 'Passw0rd!']);
        $t->same('auth/' . $route . ' is refused', 403, $r['status']);
        $t->same('with the reason given', 'SELF_REGISTRATION_CLOSED', $r['json']['code'] ?? null);
    }

    $t->same('no account was created by any of it', 0, (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE username = 'x'"
    )->fetchColumn());

    $r = $anon->api('POST', 'auth/check-username', ['username' => 't_voter1']);
    $t->ok('username lookup is closed to strangers', in_array($r['status'], [401, 403], true),
        'answering it would let anyone enumerate who holds an account; got ' . $r['status']);

    $r = $admin->api('POST', 'auth/check-username', ['username' => 't_voter1']);
    $t->same('but the office may still ask', 200, $r['status']);
    $t->same('and gets a straight answer', false, $r['json']['available'] ?? null);

    /* ── Cross-module writes ───────────────────────────────── */
    $t->group('An administrator creating an election is visible to members');

    $before = (int) $pdo->query('SELECT COUNT(*) FROM elections')->fetchColumn();
    foreach ([null, [], '', [''], ['   '], ['President', ''], [false], [['name' => 'President']], ['President', ' president ']] as $positions) {
        $body = ['title' => 'Invalid Position Selection', 'startDate' => '2030-01-01T09:00', 'endDate' => '2030-01-02T09:00'];
        if ($positions !== null) $body['positions'] = $positions;
        $r = $admin->api('POST', 'elections', $body);
        $t->same('missing or invalid positions are refused', 422, $r['status']);
        $t->same('invalid selection creates no election', $before, (int) $pdo->query('SELECT COUNT(*) FROM elections')->fetchColumn());
    }

    $r = $admin->api('POST', 'elections', [
        'title'     => 'Integration Election',
        'positions' => ['Integration Seat', 'Chairperson'],
        'startDate' => date('Y-m-d\TH:i', time() - 600),
        'endDate'   => date('Y-m-d\TH:i', time() + 86400),
    ]);
    $t->same('the election is created', 201, $r['status']);
    $newId = (int) ($r['json']['id'] ?? 0);
    $t->ok('and comes back with an id', $newId > 0);
    $t->same('selected positions are saved with the election', ['Integration Seat', 'Chairperson'], $pdo->query("SELECT position_name FROM positions WHERE election_id = $newId ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN));
    $t->same('new election inherits no candidates', 0, (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE election_id = $newId")->fetchColumn());
    $t->same('new positions cannot reuse old candidate assignments', 0, (int)$pdo->query("SELECT COUNT(*) FROM positions p JOIN candidates c ON c.position_id=p.position_id WHERE p.election_id=$newId")->fetchColumn());
    $t->ok('same-named old positions keep their candidates', (int)$pdo->query("SELECT COUNT(*) FROM positions p JOIN candidates c ON c.position_id=p.position_id WHERE p.position_name='Chairperson' AND p.election_id<>$newId")->fetchColumn()>0);

    $t->same('it starts as a draft', 'draft', (string) $pdo->query(
        "SELECT status FROM elections WHERE election_id = $newId"
    )->fetchColumn());

    $r = $voter->api('GET', 'voter/elections');
    $titles = array_column($r['json']['elections'] ?? [], 'title');
    $t->ok('a draft election is invisible to members', !in_array('Integration Election', $titles, true),
        implode(', ', $titles));

    $t->group('An empty ballot cannot be opened');
    // Simulate a legacy draft whose positions were removed.
    $pdo->exec("DELETE FROM positions WHERE election_id = $newId");

    $r = $admin->api('PATCH', 'elections/' . $newId . '/activate');
    $t->same('starting an election with no positions is refused', 409, $r['status']);
    $t->contains('and says what is missing', 'position', $r['json']['error'] ?? '');

    $r = $admin->api('POST', 'positions', ['electionId' => $newId, 'name' => 'Integration Seat']);
    $t->same('the position is added', 201, $r['status']);
    $seatId = (int) ($r['json']['id'] ?? 0);

    $r = $admin->api('GET', 'positions&election=' . $newId);
    $t->same('and reads back', 1, count($r['json'] ?? []));

    $r = $admin->api('PATCH', 'elections/' . $newId . '/activate');
    $t->same('a position with nobody standing is still refused', 409, $r['status']);
    $t->contains('and says so', 'candidate', $r['json']['error'] ?? '');

    $t->group('An administrator starting an election is visible to members');

    $r = $admin->api('POST', 'candidates', [
        'electionId' => $newId,
        'positionId' => $seatId,
        'memberId'   => $fx['standing']['Chairperson'][1]['member'],
        'platform'   => 'A platform',
    ]);
    $t->same('a candidate is nominated', 201, $r['status']);
    $nominationId = (int) ($r['json']['id'] ?? 0);
    $memberId = $fx['standing']['Chairperson'][1]['member'];
    $eligible = $admin->api('GET', 'candidates/eligible');
    $t->same('admin can load voter nomination availability', 200, $eligible['status']);
    $member = array_values(array_filter($eligible['json'] ?? [], static fn($m) => $m['id'] === $memberId))[0] ?? [];
    $t->ok('candidate stays selectable for other elections', !empty($member));
    $t->ok('new nomination is included in unavailable elections', in_array($newId, $member['candidateElectionIds'] ?? [], true));
    $t->ok('the same member holds nominations across elections', count($member['candidateElectionIds'] ?? []) > 1);
    $r = $admin->api('POST', 'candidates', ['memberId' => $memberId, 'electionId' => $newId, 'positionId' => $seatId]);
    $t->same('duplicate nomination in the same election is rejected', 409, $r['status']);
    $r = $admin->api('PUT', 'candidates/' . $nominationId, ['memberId' => $memberId, 'electionId' => $newId, 'positionId' => $seatId, 'status' => 'Inactive']);
    $t->same('existing nomination remains editable', 200, $r['status']);
    $eligible = $admin->api('GET', 'candidates/eligible');
    $member = array_values(array_filter($eligible['json'] ?? [], static fn($m) => $m['id'] === $memberId))[0] ?? [];
    $t->ok('inactive nomination still excludes duplicate election', in_array($newId, $member['candidateElectionIds'] ?? [], true));
    $r = $admin->api('PUT', 'candidates/' . $nominationId, ['memberId' => $memberId, 'electionId' => $newId, 'positionId' => $seatId, 'status' => 'Active']);
    $t->same('nomination can be reactivated', 200, $r['status']);
    $t->same('voter cannot access admin nomination availability', 403, $voter->api('GET', 'candidates/eligible')['status']);

    tdb_approve_election($admin, $newId);

    $r = $admin->api('PATCH', 'elections/' . $newId . '/activate');
    $t->same('the administrator can now start it', 200, $r['status']);

    $r = $voter->api('GET', 'voter/elections');
    $titles = array_column($r['json']['elections'] ?? [], 'title');
    $t->ok('and now members can see it', in_array('Integration Election', $titles, true), implode(', ', $titles));

    /* ── Validation is server-side ─────────────────────────── */
    $t->group('Validation lives on the server, not only in the browser');

    $r = $admin->api('POST', 'elections', ['title' => '', 'startDate' => '', 'endDate' => '']);
    $t->same('an empty election is rejected', 422, $r['status']);
    $t->ok('every missing field is named', isset($r['json']['fields']['title'], $r['json']['fields']['startDate']),
        json_encode($r['json']['fields'] ?? []));

    $r = $admin->api('POST', 'elections', [
        'title' => 'Backwards', 'startDate' => '2030-06-01T09:00', 'endDate' => '2030-01-01T09:00',
    ]);
    $t->same('an end date before the start is rejected', 422, $r['status']);
    $t->contains('with the end date blamed', 'after the start date', json_encode($r['json']['fields'] ?? []));

    $r = $admin->api('POST', 'positions', ['electionId' => $newId, 'name' => '']);
    $t->same('a nameless position is rejected', 422, $r['status']);

    $t->group('A description really is optional');

    $r = $admin->api('POST', 'elections', [
        'title' => 'No Description Election',
        'positions' => ['President'],
        'startDate' => '2030-01-01T09:00', 'endDate' => '2030-01-02T09:00',
    ]);
    $t->same('an election saves without one', 201, $r['status']);
    $t->same('and stores NULL rather than an empty string', null, $pdo->query(
        "SELECT description FROM elections WHERE title = 'No Description Election'"
    )->fetchColumn());

    $r = $admin->api('POST', 'positions', ['electionId' => $newId, 'name' => 'Undescribed Seat', 'description' => '']);
    $t->same('a position saves without one', 201, $r['status']);
    $t->same('and stores NULL too', null, $pdo->query(
        "SELECT description FROM positions WHERE position_name = 'Undescribed Seat'"
    )->fetchColumn());

    /* ── Duplicate handling ────────────────────────────────── */
    $t->group('Duplicates are refused politely');

    $r = $admin->api('POST', 'positions', ['electionId' => $newId, 'name' => 'Integration Seat']);
    $t->same('the same position twice is a conflict', 409, $r['status']);
    $t->contains('with a message a person can act on', 'already exists', $r['json']['error'] ?? '');

    /* ── Audit integration ─────────────────────────────────── */
    $t->group('Actions reach the audit trail');

    $rows = $pdo->query(
        "SELECT action FROM audit_logs WHERE entity_type = 'election' ORDER BY log_id DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_COLUMN);
    $t->ok('creating an election was logged', in_array('election.created', $rows, true), implode(', ', $rows));
    $t->ok('starting an election was logged', in_array('election.published', $rows, true), implode(', ', $rows));

    $logins = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login'")->fetchColumn();
    $t->ok('sign-ins were logged', $logins > 0, $logins . ' recorded');

    $attempts = (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
    $t->ok('sign-in attempts reached the security log', $attempts > 0, $attempts . ' recorded');

    $t->group('Dashboard and monitoring activity feeds show only administrator actions');
    $expectedActivity = [];
    for ($i = 1; $i <= 11; $i++) {
        $description = 'Dashboard admin action ' . $i;
        audit($fx['admin'], 'member.updated', 'member', null, $description);
        $expectedActivity[] = $description;
    }
    foreach (['auth.login', 'auth.logout', 'ballot.cast', 'member.updated', 'auth.login_failed'] as $action) {
        audit($fx['voters'][1], $action, null, null, 'Dashboard voter action ' . $action);
        audit(null, $action, null, null, 'Dashboard anonymous action ' . $action);
    }
    $beforeRead = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    $expectedMonitor = array_slice(array_reverse($expectedActivity), 0, 10);
    $expectedActivity = array_slice(array_reverse($expectedActivity), 0, 8);
    foreach (['initial load', 'refresh'] as $load) {
        $dashboard = $admin->api('GET', 'dashboard');
        $t->same('dashboard activity ' . $load . ' succeeds', 200, $dashboard['status']);
        $t->same('dashboard ' . $load . ' retains the latest eight admin actions only',
            $expectedActivity, array_column($dashboard['json']['recentActivity'] ?? [], 'title'));
        $monitor = $admin->api('GET', 'monitor');
        $t->same('monitor activity ' . $load . ' succeeds', 200, $monitor['status']);
        $t->same('monitor ' . $load . ' retains the latest ten admin actions only',
            $expectedMonitor, array_column($monitor['json']['activityFeed'] ?? [], 'title'));
    }
    $t->same('filtering the dashboard preserves the audit trail', $beforeRead,
        (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
    (require __DIR__ . '/../election-assurance-integration.php')($t, $pdo);
    (require __DIR__ . '/../receipts-integration.php')($t, $pdo);
    (require __DIR__ . '/../reopening-integration.php')($t, $pdo);
    (require __DIR__ . '/../position-deletion-integration.php')($t, $pdo);
    (require __DIR__ . '/../voter-registration-integration.php')($t, $pdo);
    (require __DIR__ . '/../installation-integration.php')($t, $pdo);
    (require __DIR__ . '/../audit-descriptions-integration.php')($t, $pdo);
    (require __DIR__ . '/../voter-import-integration.php')($t, $pdo);
};

<?php
/**
 * 6 — PERFORMANCE TESTING
 *
 * Measured, not asserted by feel. The budgets below are deliberately
 * generous for a cooperative office PC running XAMPP: what matters is that
 * nothing is pathologically slow and that nothing breaks under simultaneous
 * use on election day.
 *
 * Note the servers under test are PHP's built-in one, which handles a
 * single request per process. Four are run, so four requests can genuinely
 * overlap. Apache with mod_php will do better than these figures, not
 * worse, so a pass here is a conservative result.
 */

declare(strict_types=1);

return static function (Suite $t, PDO $pdo, array $fx): void {

    /** Median is used rather than the mean so one slow outlier — a cold
        opcache, a background process — does not decide the result. */
    $median = static function (array $xs): float {
        sort($xs);
        $n = count($xs);
        return $n === 0 ? 0.0 : (float) ($n % 2 ? $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2);
    };

    $timeIt = static function (callable $fn, int $times) use ($median): array {
        $ms = [];
        for ($i = 0; $i < $times; $i++) {
            $t0 = microtime(true);
            $fn();
            $ms[] = (microtime(true) - $t0) * 1000;
        }
        sort($ms);
        return [
            'median' => $median($ms),
            'p95'    => $ms[min(count($ms) - 1, (int) floor(count($ms) * 0.95))],
            'max'    => end($ms),
        ];
    };

    /* ── Response times ────────────────────────────────────── */
    $t->group('Response times on the common pages');

    $voter = new Http('perf_voter');
    $voter->login('t_voter1');
    $admin = new Http('perf_admin');
    $admin->login('t_admin');

    $budgets = [
        ['a member signing in',       500, static function () { (new Http('perf_login'))->login('t_voter4'); }],
        ['the member dashboard',      400, static fn() => $voter->api('GET', 'voter/dashboard')],
        ['the ballot page',           400, static fn() => $voter->api('GET', 'voter/ballot&election=' . $fx['live'])],
        ['the candidates list',       400, static fn() => $voter->api('GET', 'voter/candidates')],
        ['the members\' results',     400, static fn() => $voter->api('GET', 'voter/results')],
        ['the back-office dashboard', 600, static fn() => $admin->api('GET', 'dashboard')],
        ['the voter register',        600, static fn() => $admin->api('GET', 'voters')],
        ['live vote monitoring',      600, static fn() => $admin->api('GET', 'monitor&election=' . $fx['live'])],
        ['the results tally',         600, static fn() => $admin->api('GET', 'results&election=' . $fx['closed'])],
        ['the security log',          600, static fn() => $admin->api('GET', 'security')],
    ];

    foreach ($budgets as [$label, $budgetMs, $fn]) {
        $m = $timeIt($fn, 8);
        $t->ok(
            sprintf('%s: %.0f ms median (budget %d ms)', $label, $m['median'], $budgetMs),
            $m['median'] < $budgetMs,
            sprintf('median %.0f ms, 95th percentile %.0f ms, worst %.0f ms', $m['median'], $m['p95'], $m['max'])
        );
    }

    /* ── Casting a vote ────────────────────────────────────── */
    $t->group('Casting a vote answers immediately');

    /*
     * The receipt email is sent after the response is flushed. This is the
     * check that the member is not left waiting for Gmail: with the mail
     * step inside the request this used to take about five seconds, and
     * held the session lock for all of it, so every other page the member
     * had open went blank.
     */
    $castTimes = [];
    for ($i = 5; $i <= 9; $i++) {
        $v = new Http('perf_cast' . $i);
        $v->login('t_voter' . $i);
        $r = $v->api('POST', 'voter/vote', [
            'electionId' => $fx['live'],
            'votes' => [['candidateId' => $fx['candidates'][$fx['live']]['Chairperson'][1]]],
        ]);
        if ($r['status'] === 201) $castTimes[] = $r['ms'];
    }

    $t->same('all five ballots were accepted', 5, count($castTimes));
    $castMedian = $median($castTimes);
    $t->ok(sprintf('a vote is acknowledged in %.0f ms (budget 1500 ms)', $castMedian),
        $castMedian < 1500,
        'the member must not wait on the mail server; ' . implode(', ',
            array_map(static fn($x) => sprintf('%.0f', $x), $castTimes)) . ' ms');

    /* ── Simultaneous voting ───────────────────────────────── */
    $t->group('Members voting at the same moment');

    /* Sixteen members created for this test specifically. Ten was not
       enough to reproduce the ballots-table deadlock reliably; sixteen
       casting two selections each fails within a round or two if the
       retry in db_transaction() is ever removed. */
    $mkUser = $pdo->prepare(
        'INSERT INTO users (username, email, password_hash, role_id, status, email_verified_at)
         VALUES (?,?,?,(SELECT role_id FROM roles WHERE slug = ?),?,NOW())'
    );
    $mkMember = $pdo->prepare(
        'INSERT INTO members (user_id, member_no, full_name, membership_status, registered_at)
         VALUES (?,?,?,?,NOW())'
    );
    $hash = password_hash('Test@1234', PASSWORD_BCRYPT);
    for ($i = 1; $i <= 16; $i++) {
        $mkUser->execute(['t_rush' . $i, 'rush' . $i . '@test.coop', $hash, 'voter', 'active']);
        $uid = (int) $pdo->lastInsertId();
        $mkMember->execute([$uid, sprintf('RUSH-%04d', $i), 'Rush Member ' . $i, 'active']);
    }

    $jars    = [];
    $badLogin = [];
    for ($i = 1; $i <= 16; $i++) {
        $h = new Http('rush' . $i);
        $r = $h->login('t_rush' . $i);
        if ($r['status'] !== 200) $badLogin[] = $i . ':' . $r['status'];
        $jars[$i] = $h;
    }
    $t->same('all sixteen members signed in first', '', implode(' ', $badLogin));

    $voteUrl = static fn(int $i) => TestServer::url('api/index.php?r=voter/vote', $i);
    $calls = [];
    for ($i = 1; $i <= 16; $i++) {
        $calls[] = ['POST', $voteUrl($i), [
            'electionId' => $fx['live'],
            'votes' => [
                ['candidateId' => $fx['candidates'][$fx['live']]['Chairperson'][($i % 2) + 1]],
                ['candidateId' => $fx['candidates'][$fx['live']]['Treasurer'][($i % 2) + 1]],
            ],
        ], $jars[$i]->jar];
    }

    $res  = Http::parallel($calls);
    $wall = $res['_wallMs'];
    unset($res['_wallMs']);

    $accepted = count(array_filter($res, static fn($r) => $r['status'] === 201));
    $errored  = count(array_filter($res, static fn($r) => $r['status'] >= 500));

    /*
     * This is the check that caught the deadlock. Two of ten ballots came
     * back 500 "the server could not complete that request" — InnoDB had
     * rolled one transaction back and nothing retried it, so on election
     * day those members' votes would simply have been lost.
     */
    $t->same('no ballot ended in a server error', 0, $errored);
    $t->ok('all sixteen simultaneous ballots were accepted', $accepted === 16,
        'statuses: ' . implode(', ', array_map(static fn($r) => (string) $r['status'], $res))
        . ' | bodies: ' . implode(' ~ ', array_map(
            static fn($r) => substr(trim($r['body']), 0, 90), $res)));
    $t->ok(sprintf('sixteen at once finished in %.0f ms', $wall), $wall < 12000,
        'on four single-threaded servers, so roughly four rounds');

    $t->same('sixteen ballots are on record', 16, (int) $pdo->query(
        "SELECT COUNT(*) FROM ballots b JOIN users u ON u.user_id = b.user_id
          WHERE b.election_id = {$fx['live']} AND b.has_voted = 1 AND u.username LIKE 't_rush%'"
    )->fetchColumn());
    /* Every ballot writes exactly one vote row per seat chosen, so the two
       counts must move together. Comparing them to each other avoids having
       to account for the votes cast earlier in this suite. */
    $ballotsHere = (int) $pdo->query(
        "SELECT COUNT(*) FROM ballots WHERE election_id = {$fx['live']} AND has_voted = 1"
    )->fetchColumn();
    $votesHere = (int) $pdo->query(
        "SELECT COUNT(*) FROM votes WHERE election_id = {$fx['live']}"
    )->fetchColumn();
    $t->same('every ballot is accounted for in the count, once', $votesHere, $ballotsHere * 2 - 5,
        'the five single-selection ballots cast earlier count once each');

    /* ── The double-vote race ──────────────────────────────── */
    $t->group('One member pressing Submit five times at once');

    $mkUser->execute(['t_racer', 'racer@test.coop', $hash, 'voter', 'active']);
    $racerId = (int) $pdo->lastInsertId();
    $mkMember->execute([$racerId, 'RACE-0001', 'Race Member', 'active']);

    $racer = new Http('racer');
    $racer->login('t_racer');

    $raceCalls = [];
    for ($i = 0; $i < 5; $i++) {
        $raceCalls[] = ['POST', $voteUrl($i), [
            'electionId' => $fx['live'],
            'votes' => [['candidateId' => $fx['candidates'][$fx['live']]['Chairperson'][1]]],
        ], $racer->jar];
    }

    $votesBeforeRace = (int) $pdo->query(
        "SELECT COUNT(*) FROM votes WHERE election_id = {$fx['live']}"
    )->fetchColumn();

    $res = Http::parallel($raceCalls);
    unset($res['_wallMs']);

    $ok  = count(array_filter($res, static fn($r) => $r['status'] === 201));
    $dup = count(array_filter($res, static fn($r) => $r['status'] === 409));

    $t->ok('exactly one of the five is accepted', $ok === 1,
        'statuses: ' . implode(', ', array_map(static fn($r) => (string) $r['status'], $res)));
    $t->ok('the other four are refused as duplicates', $dup === 4,
        'statuses: ' . implode(', ', array_map(static fn($r) => (string) $r['status'], $res)));
    $t->ok('and none of them errored', count(array_filter($res, static fn($r) => $r['status'] >= 500)) === 0,
        'a 500 here would mean a member is told their vote failed when it may not have');

    $t->same('one ballot is recorded', 1, (int) $pdo->query(
        "SELECT COUNT(*) FROM ballots WHERE user_id = $racerId AND election_id = {$fx['live']}"
    )->fetchColumn());

    /* The vote rows carry no voter, so the member's single selection is
       counted by comparing the totals either side of their submission. */
    $votesAfterRace = (int) $pdo->query(
        "SELECT COUNT(*) FROM votes WHERE election_id = {$fx['live']}"
    )->fetchColumn();
    $t->same('their five attempts added exactly one vote to the count',
        1, $votesAfterRace - $votesBeforeRace);

    /* ── Scale ─────────────────────────────────────────────── */
    $t->group('The pages hold up as the cooperative grows');

    /* Grow the register to 500 members and re-time the heavy pages. */
    $pdo->beginTransaction();
    for ($i = 1; $i <= 500; $i++) {
        $mkUser->execute(['t_bulk' . $i, 'bulk' . $i . '@test.coop', $hash, 'voter', 'active']);
        $uid = (int) $pdo->lastInsertId();
        $mkMember->execute([$uid, sprintf('BULK-%04d', $i), 'Bulk Member ' . $i, 'active']);
    }
    $pdo->commit();

    $count = (int) $pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
    $t->ok('the register now holds ' . $count . ' members', $count > 500);

    /* And a few thousand votes, so the tallies have something to count. */
    $pdo->exec(
        "INSERT INTO votes (election_id, position_id, candidate_id, cast_date)
         SELECT {$fx['closed']}, {$fx['positions'][$fx['closed']]['Chairperson']},
                {$fx['candidates'][$fx['closed']]['Chairperson'][1]}, CURDATE()
           FROM members m1 CROSS JOIN (SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4) n
          LIMIT 2000"
    );
    $votes = (int) $pdo->query('SELECT COUNT(*) FROM votes')->fetchColumn();
    $t->ok('and ' . $votes . ' votes are on record', $votes > 2000);

    // Protect the synthetic load so the benchmark includes real HMAC verification,
    // rather than the cheaper path that only reports missing signature rows.
    db_transaction(static function (PDO $connection) use ($fx): void {
        $eid = $fx['closed'];
        $state = integrity_state($connection, $eid);
        $ids = $connection->query("SELECT v.vote_id FROM votes v LEFT JOIN vote_integrity i ON i.vote_id = v.vote_id WHERE v.election_id = $eid AND i.vote_id IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        integrity_record($connection, $eid, $ids, $state);
    });
    $check = $admin->api('GET', 'operations/overview&election=' . $fx['closed']);
    $t->same('integrity endpoint verifies the signed load', 'passed', $check['json']['integrity']['status'] ?? null);

    foreach ([
        ['the voter register at scale',   1500, static fn() => $admin->api('GET', 'voters')],
        ['the results tally at scale',    1500, static fn() => $admin->api('GET', 'results&election=' . $fx['closed'])],
        ['the back-office dashboard',     1500, static fn() => $admin->api('GET', 'dashboard')],
        ['signed integrity checks at scale', 1500, static fn() => $admin->api('GET', 'operations/overview&election=' . $fx['closed'])],
        ['the member dashboard',          1000, static fn() => $voter->api('GET', 'voter/dashboard')],
    ] as [$label, $budgetMs, $fn]) {
        $m = $timeIt($fn, 5);
        $t->ok(
            sprintf('%s: %.0f ms median (budget %d ms)', $label, $m['median'], $budgetMs),
            $m['median'] < $budgetMs,
            sprintf('median %.0f ms, worst %.0f ms', $m['median'], $m['max'])
        );
    }

    /* ── Indexes ───────────────────────────────────────────── */
    $t->group('The queries that matter are answered from an index');

    /*
     * Two separate things are worth checking, and only one of them is about
     * the optimiser.
     *
     * The indexes have to exist — that is a property of the schema and holds
     * whatever the data looks like. Whether the optimiser uses one is only a
     * fair question on a table with volume: scanning twelve candidate rows
     * is genuinely cheaper than seeking, so demanding an index there would
     * fail for a reason that says nothing about the system.
     */
    $indexed = [
        'ballots'    => ['user_id', 'election_id'],
        'votes'      => ['candidate_id'],
        'candidates' => ['election_id'],
        'users'      => ['username'],
        'members'    => ['user_id'],
        'audit_logs' => ['user_id'],
    ];

    foreach ($indexed as $table => $columns) {
        $covered = array_column($pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(), 'Column_name');
        foreach ($columns as $col) {
            $t->ok($table . '.' . $col . ' is indexed', in_array($col, $covered, true),
                'indexed columns: ' . implode(', ', array_unique($covered)));
        }
    }

    /* Now the optimiser, on the tables that carry real volume. */
    $realBallot = $pdo->query('SELECT user_id, election_id FROM ballots WHERE has_voted = 1 LIMIT 1')->fetch();
    $realCand   = (int) $pdo->query('SELECT candidate_id FROM votes LIMIT 1')->fetchColumn();

    $plans = [
        'looking up a member\'s ballot' => sprintf(
            'SELECT * FROM ballots WHERE user_id = %d AND election_id = %d',
            (int) $realBallot['user_id'],
            (int) $realBallot['election_id']
        ),
        'counting the votes for a candidate' =>
            'SELECT COUNT(*) FROM votes WHERE candidate_id = ' . $realCand,
        'finding an account by username' =>
            "SELECT * FROM users WHERE username = 't_voter1'",
    ];

    foreach ($plans as $label => $sql) {
        $row  = $pdo->query('EXPLAIN ' . $sql)->fetch();
        $type = strtoupper((string) ($row['type'] ?? ''));
        $key  = (string) ($row['key'] ?? '');

        /* An empty plan type means no row matched, which would let the next
           assertion pass without testing anything. */
        $t->ok($label . ' matched a real row', $type !== '' && $type !== 'NULL',
            'EXPLAIN returned type=' . var_export($row['type'] ?? null, true));

        $t->ok($label . ' uses an index (' . ($type ?: '?') . ' on ' . ($key ?: 'none') . ')',
            $type !== 'ALL' && $key !== '',
            'EXPLAIN: type=' . $type . ', key=' . ($key ?: 'none') . ', rows=' . ($row['rows'] ?? '?'));
    }

    /* ── Payload sizes ─────────────────────────────────────── */
    $t->group('Responses stay small enough for a slow connection');

    foreach ([
        ['the member dashboard', 'voter/dashboard', 256],
        ['the ballot',           'voter/ballot&election=' . $fx['live'], 256],
        ['the results',          'voter/results', 512],
    ] as [$label, $route, $kb]) {
        $r = $voter->api('GET', $route);
        $size = strlen($r['body']) / 1024;
        $t->ok(sprintf('%s is %.1f KB (budget %d KB)', $label, $size, $kb), $size < $kb);
    }
};

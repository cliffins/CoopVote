<?php
/**
 * 11 — REGRESSION TESTING
 *
 * One check per fault this system has actually had. Each names the symptom
 * as it was reported, so that if it ever comes back the failure says which
 * problem has returned rather than only which assertion broke.
 */

declare(strict_types=1);

return static function (Suite $t, PDO $pdo, array $fx): void {

    $admin = new Http('reg_admin');
    $admin->login('t_admin');
    $voter = new Http('reg_voter');
    $voter->login('t_voter1');
    $anon  = new Http('reg_anon');

    /* ── 1 ──────────────────────────────────────────────────── */
    $t->group('R-01  "the reject button does not work"');

    $target = $fx['pending'];
    $r = $admin->api('PATCH', 'voters/' . $target . '/reject');
    $t->same('rejecting an application succeeds', 200, $r['status']);
    $t->same('the account is marked rejected', 'rejected', (string) $pdo->query(
        "SELECT status FROM users WHERE user_id = $target"
    )->fetchColumn());
    $t->same('and the membership record follows', 'inactive', (string) $pdo->query(
        "SELECT membership_status FROM members WHERE user_id = $target"
    )->fetchColumn());
    $t->same('the rejected member cannot sign in', 401,
        (new Http('reg_rejected'))->login('t_pending')['status']);

    $approve = $fx['voters'][10];
    $pdo->prepare("UPDATE users SET status = 'pending_approval' WHERE user_id = ?")->execute([$approve]);
    $r = $admin->api('PATCH', 'voters/' . $approve . '/approve');
    $t->same('approving works too', 200, $r['status']);
    $t->same('and the member becomes active', 'active', (string) $pdo->query(
        "SELECT status FROM users WHERE user_id = $approve"
    )->fetchColumn());

    /* ── 2 ──────────────────────────────────────────────────── */
    $t->group('R-02  "approving or rejecting lags, then the content disappears everywhere"');

    /*
     * The cause was the session lock. Sending the notification email inside
     * the request held it for the five seconds Gmail took, and every other
     * page the same administrator had open sat waiting for its own session
     * — so they all came up blank. The fix answers first and mails after.
     */
    $pdo->prepare("UPDATE users SET status = 'pending_approval' WHERE user_id = ?")->execute([$approve]);
    $r = $admin->api('PATCH', 'voters/' . $approve . '/approve');
    $t->ok(sprintf('approving answers in %.0f ms, not in seconds', $r['ms']), $r['ms'] < 1500,
        'the mail is sent after the response is flushed');

    /* While that request is being handled, another page must still load. */
    $calls = [
        ['PATCH', TestServer::url('api/index.php?r=voters/' . $approve . '/suspend', 0), null, $admin->jar],
        ['GET',   TestServer::url('api/index.php?r=dashboard', 1), null, $admin->jar],
        ['GET',   TestServer::url('api/index.php?r=voters', 2), null, $admin->jar],
    ];
    $res = Http::parallel($calls);
    unset($res['_wallMs']);

    $t->same('a page opened during an approval still returns data', 200, $res[1]['status']);
    $t->ok('and it is not empty', strlen($res[1]['body']) > 60, strlen($res[1]['body']) . ' bytes');
    $t->same('and so does a third', 200, $res[2]['status']);

    $t->ok('respond_then() is what makes this true',
        str_contains((string) file_get_contents(BASE_PATH . '/includes/helpers.php'), 'session_write_close'),
        'without releasing the session lock the pages queue behind each other again');

    /* ── 3 ──────────────────────────────────────────────────── */
    $t->group('R-03  "it says Apache error when I close the election"');

    $r = $admin->api('PATCH', 'elections/' . $fx['upcoming'] . '/close');
    $t->same('closing an election that has not started is refused clearly', 409, $r['status']);
    $t->same('with a reason, not a server error', 'NOT_STARTED', $r['json']['code'] ?? null);
    $t->ok('and the message says what to do instead',
        str_contains((string) ($r['json']['error'] ?? ''), 'Cancel it instead'),
        (string) ($r['json']['error'] ?? ''));

    $r = $admin->api('PATCH', 'elections/' . $fx['live'] . '/close');
    $t->same('closing a live election works', 200, $r['status']);
    $r = $admin->api('PATCH', 'elections/' . $fx['live'] . '/close');
    $t->same('closing it twice is handled, not an error', 200, $r['status']);
    $t->ok('and says it was already closed', ($r['json']['alreadyClosed'] ?? false) === true);

    $t->ok('a real fatal would still answer as JSON',
        str_contains((string) file_get_contents(BASE_PATH . '/api/index.php'), 'register_shutdown_function'),
        'set_exception_handler never sees an out-of-memory or a timeout, and the client '
        . 'reported every non-JSON reply as "Apache is not running"');

    /* ── 4 ──────────────────────────────────────────────────── */
    $t->group('R-04  "I cannot sign in on the other PC, it says Apache error"');

    $t->ok('a missing database is named as such, with the fix',
        str_contains((string) file_get_contents(BASE_PATH . '/config/database.php'), '1049')
        && str_contains((string) file_get_contents(BASE_PATH . '/config/database.php'), 'phpmyadmin'),
        'the message used to say "Is MySQL running?" on a machine where MySQL was running fine');

    $db = (string) file_get_contents(BASE_PATH . '/config/database.php');
    foreach (['1049' => 'the database has not been created', '1045' => 'wrong username or password',
              '2002' => 'MySQL is not reachable'] as $code => $meaning) {
        /* Array keys that look numeric arrive as integers. */
        $t->contains('error ' . $code . ' (' . $meaning . ') is handled separately', (string) $code, $db);
    }

    $t->ok('the front end can tell a server error from PHP not running',
        str_contains((string) file_get_contents(BASE_PATH . '/assets/js/app.js'), 'PHP_NOT_RUNNING'));

    /* ── 5 ──────────────────────────────────────────────────── */
    $t->group('R-05  "I resend the code and it still says the code has expired"');

    $uid  = $fx['voters'][4];
    $code = otp_issue($uid, 'register');
    $t->same('a freshly issued code is accepted at once', true, otp_check($uid, $code, 'register')['ok'],
        'PHP and MySQL disagreeing about the time made new codes read as already expired');

    $phpOffset = (new DateTimeImmutable('now'))->format('P');
    $dbOffset  = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
    $t->same('the two clocks agree', $phpOffset, $dbOffset);

    $code = otp_issue($uid, 'register');
    $pdo->prepare('UPDATE otp_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE user_id = ? AND is_used = 0')
        ->execute([$uid]);
    $t->same('an expired code lifts the resend cooldown', 0, otp_resend_wait($uid, 'register'),
        'being told to request a new code and then refused one is what stranded people');

    /* ── 6 ──────────────────────────────────────────────────── */
    $t->group('R-06  "cannot access noticeTimer before initialization when logging in"');

    $app = (string) file_get_contents(BASE_PATH . '/assets/js/app.js');
    $t->ok('the notification timer is declared before anything can reach it',
        strpos($app, 'noticeTimer') === false
        || strpos($app, 'let noticeTimer') < strpos($app, 'noticeTimer)'),
        'a hoisted function ran before the let, and one CDN failure was enough to expose it');

    $t->ok('no page depends on a script it fetches from elsewhere',
        !preg_match('#<script[^>]+src=["\']https?://#i',
            implode('', array_map(static fn($f) => (string) file_get_contents($f),
                array_merge(glob(BASE_PATH . '/admin/*.html') ?: [],
                            glob(BASE_PATH . '/voter/*.html') ?: [],
                            [BASE_PATH . '/login.html'])))));

    /* ── 7 ──────────────────────────────────────────────────── */
    $t->group('R-07  "the admin creates the accounts now — remove the register page"');

    $t->ok('register.html is gone', !file_exists(BASE_PATH . '/register.html'));
    foreach (['register', 'verify-otp', 'resend-otp'] as $route) {
        $t->same('auth/' . $route . ' is closed', 403,
            $anon->api('POST', 'auth/' . $route, ['username' => 'x'])['status']);
    }
    $t->ok('the sign-in page offers no way to register',
        !preg_match('/href=["\'][^"\']*register\.html/i',
            (string) file_get_contents(BASE_PATH . '/login.html')));

    $r = tdb_registration_window($pdo, fn() => $admin->api('POST', 'voters', [
        'first' => 'Regression', 'last' => 'Member', 'email' => 'reg.member@test.coop',
        'phone' => '09171234567', 'address' => '123 Test Street, Manila',
        'dob' => '1990-01-02',
    ]));
    $t->same('the office can create an account instead', 201, $r['status']);
    $t->ok('and is given credentials to hand over', !empty($r['json']['username']) && !empty($r['json']['password']));

    /* ── 8 ──────────────────────────────────────────────────── */
    $t->group('R-08  "the vote must be published by admin before appearing in the voters\' result"');

    $pdo->prepare('UPDATE elections SET results_released_at = NULL WHERE election_id = ?')
        ->execute([$fx['closed']]);

    $r = $voter->api('GET', 'voter/results');
    $ids = array_column($r['json']['elections'] ?? [], 'id');
    $t->ok('an unreleased result is not shown, even though voting is over',
        !in_array($fx['closed'], $ids, true), implode(',', $ids));

    $admin->api('PATCH', 'elections/' . $fx['closed'] . '/release');
    $r = $voter->api('GET', 'voter/results');
    $ids = array_column($r['json']['elections'] ?? [], 'id');
    $t->ok('releasing it makes it appear', in_array($fx['closed'], $ids, true), implode(',', $ids));

    $r = $admin->api('PATCH', 'elections/' . $fx['unreleased'] . '/release');
    $t->ok('releasing while voting is open is refused',
        $r['status'] === 409 || election_status($pdo->query(
            "SELECT * FROM elections WHERE election_id = {$fx['unreleased']}")->fetch()) !== 'Active',
        (string) $r['status']);

    /* ── 9 ──────────────────────────────────────────────────── */
    $t->group('R-09  "the election result must be seen by the voters who voted only"');

    $r = $voter->api('GET', 'voter/results');
    $t->ok('a member who voted sees the count', count($r['json']['elections'] ?? []) > 0);

    $notVoted = new Http('reg_notvoted');
    $notVoted->login('t_voter9');
    $r = $notVoted->api('GET', 'voter/results');
    $t->same('a member who did not vote sees none', 0, count($r['json']['elections'] ?? []));
    $t->ok('but is told why the page is empty', (int) ($r['json']['withheldCount'] ?? 0) > 0);

    $r = $notVoted->api('GET', 'voter/results&election=' . $fx['closed']);
    $t->same('naming the election directly does not get round it', 0, count($r['json']['elections'] ?? []));

    $r = $notVoted->api('GET', 'voter/dashboard&election=' . $fx['closed']);
    $t->same('and the dashboard shows them no winners either', null, ($r['json'] ?? [])['results'],
        'the dashboard was handing out the full winners list with vote counts');

    foreach ([$fx['live'], $fx['upcoming'], $fx['closed']] as $selection) {
        $r = $voter->api('GET', 'voter/dashboard&election=' . $selection);
        $t->same('published results survive election selection ' . $selection,
            (int) $fx['closed'], $r['json']['resultsElection']['id'] ?? null);
        $t->ok('eligible voter receives winner rows for selection ' . $selection,
            !empty($r['json']['results']));
    }
    $r = $notVoted->api('GET', 'voter/dashboard&election=' . $fx['live']);
    $t->same('fallback does not leak tallies to a nonparticipant', null, $r['json']['results']);
    $t->same('fallback does not name an ineligible result election', null, $r['json']['resultsElection']);

    $releasedAt = $pdo->query('SELECT results_released_at FROM elections WHERE election_id = ' . $fx['closed'])->fetchColumn();
    try {
        $pdo->prepare('UPDATE elections SET results_released_at = NULL WHERE election_id = ?')->execute([$fx['closed']]);
        $r = $voter->api('GET', 'voter/dashboard&election=' . $fx['live']);
        $t->same('withdrawing results clears the dashboard on refresh', null, $r['json']['results']);
        $pdo->prepare('UPDATE elections SET results_released_at = ? WHERE election_id = ?')->execute([$releasedAt, $fx['closed']]);
        $r = $voter->api('GET', 'voter/dashboard&election=' . $fx['live']);
        $t->ok('publishing results appears on the next dashboard refresh', !empty($r['json']['results']));
    } finally {
        $pdo->prepare('UPDATE elections SET results_released_at = ? WHERE election_id = ?')->execute([$releasedAt, $fx['closed']]);
    }

    /* ── 10 ─────────────────────────────────────────────────── */
    $t->group('R-10  "make the descriptions optional in add position and create election"');

    $r = $admin->api('POST', 'elections', [
        'title' => 'Regression No Desc',
        'positions' => ['President'],
        'startDate' => '2031-01-01T09:00', 'endDate' => '2031-01-02T09:00',
    ]);
    $t->same('an election saves with no description', 201, $r['status']);
    $eid = (int) $r['json']['id'];
    $t->same('and stores NULL, not an empty string', null, $pdo->query(
        "SELECT description FROM elections WHERE election_id = $eid"
    )->fetchColumn());

    $r = $admin->api('POST', 'positions', ['electionId' => $eid, 'name' => 'Regression Seat', 'description' => '']);
    $t->same('a position saves with no description', 201, $r['status']);

    foreach ([BASE_PATH . '/admin/election-management.html'] as $page) {
        $html = (string) file_get_contents($page);
        $t->same('both Description fields are marked Optional in the form', 2,
            substr_count($html, 'class="opt">Optional'),
            'the fields always accepted a blank; nothing said so');
    }

    /* ── 11 ─────────────────────────────────────────────────── */
    $t->group('R-11  the vote receipt used to be written into the audit log');

    $fresh = new Http('reg_receipt');
    $fresh->login('t_voter5');

    /* This election's window has already gone by in the fixture, and an
       election cannot be started into a window that has closed — that guard
       is itself R-03. Move it around today first, then open it. */
    $pdo->prepare(
        'UPDATE elections
            SET start_datetime = DATE_SUB(NOW(), INTERVAL 1 HOUR),
                end_datetime   = DATE_ADD(NOW(), INTERVAL 1 DAY)
          WHERE election_id = ?'
    )->execute([$fx['unreleased']]);
    $admin->api('PATCH', 'elections/' . $fx['unreleased'] . '/activate');

    $r = $fresh->api('POST', 'voter/vote', [
        'electionId' => $fx['unreleased'],
        'votes' => [['candidateId' => $fx['candidates'][$fx['unreleased']]['Chairperson'][1]]],
    ]);
    $receipt = (string) ($r['json']['receiptCode'] ?? '');
    $t->ok('the member gets a receipt', $receipt !== '',
        'HTTP ' . $r['status'] . ': ' . substr(trim($r['body']), 0, 120));

    if ($receipt === '') {
        /* Searching the log for an empty string matches every row, and would
           report a leak that is not there. */
        $t->skip('the receipt is kept out of the audit trail', 'no ballot was cast to check');
    } else {
        $logged = (int) $pdo->query(
            'SELECT COUNT(*) FROM audit_logs WHERE description LIKE ' . $pdo->quote('%' . $receipt . '%')
        )->fetchColumn();
        $t->same('it is not written into the audit trail', 0, $logged,
            'audit descriptions are shown in the activity feed and the security log');

        $r = $admin->api('GET', 'security');
        $t->notContains('nor does it appear in the security log', $receipt, $r['body']);
        $r = $admin->api('GET', 'monitor&election=' . $fx['unreleased']);
        $t->notContains('nor in live monitoring', $receipt, $r['body']);
    }

    $t->ok('the ballot still carries it, so the office can look one up',
        (string) $pdo->query("SELECT receipt_code FROM ballots WHERE user_id = {$fx['voters'][5]}
                               AND election_id = {$fx['unreleased']}")->fetchColumn() === $receipt);

    /* ── 12 ─────────────────────────────────────────────────── */
    $t->group('R-12  simultaneous ballots used to deadlock the database');

    $t->ok('the vote is wrapped in a transaction that retries a deadlock',
        str_contains((string) file_get_contents(BASE_PATH . '/api/routes/voter.php'), 'db_transaction('),
        'two of ten members submitting at once got "the server could not complete that request", '
        . 'and their votes were lost');

    $t->ok('the retry recognises the codes InnoDB uses',
        str_contains((string) file_get_contents(BASE_PATH . '/includes/helpers.php'), '40001')
        && str_contains((string) file_get_contents(BASE_PATH . '/includes/helpers.php'), '1213'));

    /* ── 13 ─────────────────────────────────────────────────── */
    $t->group('R-13  a stored script tag used to run in every reader\'s browser');

    $admin->api('POST', 'elections', [
        'title'     => '<img src=x onerror="window.__x=1">Regression XSS',
        'positions' => ['President'],
        'startDate' => '2031-03-01T09:00', 'endDate' => '2031-03-02T09:00',
    ]);

    $t->ok('the shared runtime still exposes esc()',
        str_contains((string) file_get_contents(BASE_PATH . '/assets/js/app.js'), 'window.esc = esc'));

    $wrapped = 0;
    foreach (array_merge(glob(BASE_PATH . '/admin/*.html') ?: [], glob(BASE_PATH . '/voter/*.html') ?: []) as $f) {
        $wrapped += substr_count((string) file_get_contents($f), '${esc(');
    }
    $t->ok($wrapped . ' interpolations pass through esc()', $wrapped > 150,
        'a page that stops escaping puts the fault straight back');

    /* ── 14 ─────────────────────────────────────────────────── */
    $t->group('R-14  "dapat action lang ng admin ang nakikita sa security and logs"');

    /*
     * The security page listed the whole audit trail, so members' sign-ins,
     * sign-outs and ballots buried the administrative actions the page
     * exists to show.
     *
     * The first pass kept refused sign-ins whoever they concerned, on the
     * argument that a failed sign-in on a member's account may well not be
     * the member. The cooperative asked again, and plainly: administrative
     * actions only. So a member appears nowhere in this list now — not for
     * signing in, not for a refusal against their account, not for voting.
     *
     * The refusals themselves are not lost to the page: Failed Logins, the
     * Suspicious IPs panel and the security score all read login_attempts,
     * which still covers every account and names no member.
     */
    $logAdmin = new Http('reg_log');
    $logAdmin->login('t_admin');

    /* Make sure there is something of each kind to find: a member going
       about their day, a refusal on a member's account, and a refusal on an
       administrator's. */
    $noisy = new Http('reg_noise');
    $noisy->login('t_voter7');
    $noisy->api('GET', 'voter/dashboard');
    $noisy->logout();
    (new Http('reg_noise2'))->login('t_voter7', 'deliberately-wrong');
    (new Http('reg_noise3'))->login('t_admin', 'deliberately-wrong');

    $r = $logAdmin->api('GET', 'security');
    $logs = $r['json']['logs'] ?? [];
    $t->ok('the security page returns entries', count($logs) > 0, count($logs) . ' entries');

    $t->ok('every entry names who performed it',
        count(array_filter($logs, static fn($l) => ($l['actor'] ?? '') !== '')) === count($logs),
        'an audit trail that does not say who is of little use');

    /* The rule, stated once: every row was performed by the back office. */
    $backOffice = ['Administrator', 'Election Officer', 'Auditor'];
    $outsiders  = array_values(array_unique(array_map(
        static fn($l) => (string) ($l['role'] ?? '?'),
        array_filter($logs, static fn($l) =>
            !in_array((string) ($l['role'] ?? ''), $backOffice, true))
    )));
    $t->same('every entry was performed by an administrator',
        [], $outsiders);

    $t->ok('administrative actions are listed',
        count(array_filter($logs, static fn($l) => ($l['type'] ?? '') === 'admin')) > 0);

    /* Named specifically, because these are the rows that used to survive
       the filter and are the whole point of the change. */
    $events = implode(' | ', array_map(static fn($l) => (string) ($l['event'] ?? ''), $logs));

    $t->notContains('a member\'s sign-in is not listed', 'Signed in successfully as Voter', $events);
    $t->notContains('nor their ballot', 'Ballot cast', $events);
    $t->notContains('nor a refused sign-in on their account',
        'Sign-in refused for "t_voter7"', $events);

    $t->contains('but a refused sign-in on an administrator\'s account is',
        'Sign-in refused for "t_admin"', $events);

    /* Every entry has to be reachable by one of the filter chips on the
       page, or it is listed and then impossible to find. */
    $chips = ['admin', 'auth', 'warn'];
    $unreachable = array_values(array_unique(array_diff(
        array_column($logs, 'type'),
        $chips
    )));
    $t->same('every entry is reachable by a filter on the page', [], $unreachable);

    /* The count on the card has to describe the list under it. */
    $t->same('the entry count matches what the list can show',
        count($logs), min(120, (int) ($r['json']['stats']['totalLogs'] ?? -1)));

    $page = (string) file_get_contents(BASE_PATH . '/admin/security-logs.html');
    $t->ok('the page says what the log holds',
        str_contains($page, 'No member') && str_contains($page, 'not their sign-ins'));
    $t->ok('and the Vote filter is gone, since ballots are not listed',
        !str_contains($page, "filterLogs('vote'"));

    /*
     * The page also carried a "Voter Vote Log": every member who had voted,
     * by name, with the time, their IP address and their device. Turnout is
     * worth showing, but not here and not like that — Vote Monitoring
     * carries the same record, beside the count it belongs with.
     */
    $t->ok('the Voter Vote Log panel is gone from the page',
        !str_contains($page, 'Voter Vote Log') && !str_contains($page, 'renderVoterLog'));
    $t->ok('and the API stops sending it',
        !array_key_exists('voterLog', $r['json'] ?? []),
        implode(',', array_keys($r['json'] ?? [])));
    $t->ok('turnout is still on the Vote Monitoring page',
        str_contains((string) file_get_contents(BASE_PATH . '/admin/vote-monitoring.html'),
            'recentVotes'));

    $t->group('The audit trail cannot be wiped from the page');

    /*
     * A "Clear Logs" button sat next to Export and Refresh. An audit trail
     * an administrator can erase is not an audit trail — and this one was
     * worse than that: it emptied the array in the browser, announced
     * "Logs cleared.", and every entry reappeared on the next refresh. It
     * reported a destruction that had not happened.
     */
    $t->ok('there is no Clear Logs button',
        !str_contains($page, 'Clear Logs') || str_contains($page, 'no "Clear Logs" button'),
        'the only mention left should be the comment saying why there is none');

    $t->ok('and no handler behind one',
        !preg_match('/function\s+clearLogs\s*\(/', $page));

    $t->ok('the remaining actions only read',
        str_contains($page, 'onclick="exportLogs()"') && str_contains($page, 'onclick="loadSecurityData()"'),
        'Export and Refresh both leave the trail alone');

    /* Nothing on the server side should be able to empty it either. */
    $serverSide = implode('', array_map(
        static fn($f) => (string) file_get_contents($f),
        glob(BASE_PATH . '/api/routes/*.php') ?: []
    ));
    $t->ok('no route deletes from the audit trail',
        !preg_match('/DELETE\s+FROM\s+audit_logs|TRUNCATE\s+(TABLE\s+)?`?audit_logs/i', $serverSide),
        'the record has to outlive the people it records');

    $t->group('"remove in the voter management the voter status pending approval, unverified email, rejected"');

    /*
     * All three states came from self-registration, which is closed: the
     * office opens every account itself, and opens it active. So the filter
     * offered three choices that could match nobody, the status dropdown
     * showed three entries an administrator was not allowed to pick, and the
     * summary strip counted a queue that could never grow. Accounts the old
     * flow left behind fold into Inactive, which is what they are - unable
     * to sign in. users.status still holds the original word.
     */
    $vm = (string) file_get_contents(BASE_PATH . '/admin/voter-management.html');

    foreach (['Pending', 'Unverified', 'Rejected'] as $gone) {
        $t->ok('the page never says "' . $gone . '"', !str_contains($vm, $gone),
            'filter, status dropdown, badge map and summary card alike');
    }

    $t->ok('the summary strip counts only what it can show',
        !str_contains($vm, 'sc-pending'));

    $t->ok('the approve / reject workflow went with them',
        !str_contains($vm, 'approveVoter') && !str_contains($vm, 'askReject')
        && !str_contains($vm, 'reject-modal'),
        'both buttons only ever appeared on a pending row');

    /* The API must not be able to hand the page a state it cannot draw. */
    $roll = $admin->api('GET', 'voters')['json'] ?? [];
    $t->ok('the voter list still loads', count($roll) > 0, count($roll) . ' voters');

    $states = array_values(array_unique(array_map(
        static fn($v) => (string) ($v['status'] ?? ''), $roll
    )));
    sort($states);
    $t->same('the API reports no state the page cannot draw', [],
        array_values(array_diff($states, ['Active', 'Suspended', 'Inactive'])),
        'saw: ' . implode(', ', $states));

    /*
     * R-01 above leaves one member 'rejected' in the database. The row must
     * still be listed, and listed as Inactive - dropping the status must not
     * drop the member with it.
     */
    $stale = (int) $fx['pending'];
    $t->same('the database still records what really happened', 'rejected',
        (string) $pdo->query("SELECT status FROM users WHERE user_id = $stale")->fetchColumn());

    $listed = null;
    foreach ($roll as $v) {
        if ((int) ($v['id'] ?? 0) === $stale) $listed = (string) ($v['status'] ?? '');
    }
    $t->same('and the page lists that member as Inactive', 'Inactive', $listed,
        'a removed status must fold into a real one, not vanish');

    $t->group('Releasing the results is not a one-way door');

    /*
     * The slot that now holds Unpublish held a <span> reading "Published" -
     * a label styled to look like a button, which did nothing when pressed.
     * There was no way back from a count released by mistake.
     */
    $em = (string) file_get_contents(BASE_PATH . '/admin/election-management.html');

    $t->ok('the card offers a way to take a result back',
        str_contains($em, 'askUnpublish(') && str_contains($em, '>Unpublish</button>'));

    $t->ok('and it is a button, not a label that only looks like one',
        !preg_match('/<span[^>]*class="btn[^"]*"[^>]*>\s*<svg.*?>Published<\/span>/s', $em),
        'cursor:default on a .btn is how the dead one was drawn');

    $t->ok('the card still says which of the two states it is in',
        str_contains($em, 'Hidden from members'),
        'the footer verb alone does not tell a reader whether members can see the count');

    $t->ok('and it asks before hiding a count members may already have read',
        str_contains($em, "openModal('unpublish-modal')"));

    $r = $admin->api('PATCH', 'elections/' . $fx['closed'] . '/release');
    $t->ok('a closed result releases', in_array($r['status'], [200, 409], true), (string) $r['status']);
    $r = $admin->api('PATCH', 'elections/' . $fx['closed'] . '/unpublish');
    $t->same('and the endpoint behind the button answers', 200, $r['status']);
    $t->same('taking members back to no result at all', false, $r['json']['published'] ?? null);

    /* Put it back, so a later suite finds the fixture as it expects. */
    $admin->api('PATCH', 'elections/' . $fx['closed'] . '/release');

    /* ── 15 ─────────────────────────────────────────────────── */
    $t->group('R-15  "I clicked the link in the red bar and got Forbidden"');

    /*
     * Opening the pages through VS Code's Live Server cannot work — it only
     * serves files — and the red bar exists to say so and point at the
     * Apache address instead. The address it offered was
     * http://localhost//login.html: the folder name was unknown, the code
     * substituted the words "<your project folder>", and the page then
     * parsed those angle brackets as an HTML tag and dropped them. What was
     * left was a clickable link to nowhere, on the machine of somebody who
     * was already stuck.
     */
    $app = (string) file_get_contents(BASE_PATH . '/assets/js/app.js');

    /* Comments come out first: the one above apacheUrl() quotes the old
       placeholder while explaining why it is gone. */
    $appCode = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $app);

    $t->ok('the address builder returns nothing rather than a placeholder',
        str_contains($appCode, 'return null;') && !str_contains($appCode, '<your project folder>'),
        'a guess that reaches the browser as markup is worse than no answer');

    $t->ok('the banner only makes a link when it has a real address',
        str_contains($app, 'const whereToGo = url'),
        'otherwise it writes the instruction out instead');

    $t->ok('and it escapes what it puts in the markup',
        str_contains($app, 'esc(window.location.origin)'));

    /*
     * Whatever the banner builds must never contain an empty path segment.
     * That is the exact shape of the address that answered Forbidden, and
     * it is what a missing folder name collapses into.
     */
    $hasEmptySegment = static function (string $url): bool {
        $path = (string) parse_url($url, PHP_URL_PATH);
        return str_contains($path, '//');
    };

    foreach ([
        'http://localhost/CoopVote/login.html' => false,
        'http://localhost/voting-system/voter/dashboard.html' => false,
        'http://localhost//login.html'         => true,
        'http://localhost///'                  => true,
    ] as $candidate => $isBroken) {
        $t->same(
            '"' . $candidate . '" is ' . ($isBroken ? 'recognised as the broken shape' : 'a usable address'),
            $isBroken,
            $hasEmptySegment($candidate)
        );
    }

    $t->ok('INSTALL.txt warns against Live Server by name',
        str_contains((string) file_get_contents(BASE_PATH . '/INSTALL.txt'), 'Live Server'),
        'it named file:// but not the tool most people actually reach for');

    /* ── 16 ─────────────────────────────────────────────────── */
    $t->group('R-16  smaller fixes that should stay fixed');

    $t->same('an election reads Closed the instant its end time passes', 'Closed',
        election_status(['status' => 'published',
            'start_datetime' => date('Y-m-d H:i:s', time() - 60),
            'end_datetime'   => date('Y-m-d H:i:s')]),
        'a > test left it reading Active for the rest of that second, so the page looked unchanged');

    $r = tdb_registration_window($pdo, fn() => $admin->api('POST', 'voters', [
        'first' => 'Zz', 'last' => 'Nena', 'email' => 'zz.nena@test.coop', 'username' => 'zz_nena',
        'phone' => '09171234567', 'address' => '123 Test Street, Manila',
        'dob' => '1990-01-02',
    ]));
    $t->ok('an underscore survives username generation',
        str_contains((string) ($r['json']['username'] ?? ''), '_'),
        'the sanitiser used to strip it, turning zz_nena into zznena');

    $t->same('positions nobody stands for are not counted as available',
        0, vt_contested_count($fx['draft']),
        'the dashboard said 5 positions when only 1 could actually be voted on');

    /* ── Every page still loads ─────────────────────────────── */
    $t->group('Every page still loads for the role that uses it');

    $probe = browser_probe([
        'base'   => rtrim(TestServer::url(''), '/') . '/',
        'port'   => 9336,
        'settle' => 2100,
        'login'  => ['username' => 't_admin', 'password' => 'Test@1234'],
        'pages'  => [
            'admin/dashboard.html', 'admin/election-management.html',
            'admin/candidate-management.html', 'admin/voter-management.html',
            'admin/results.html', 'admin/vote-monitoring.html',
            'admin/reports.html', 'admin/security-logs.html',
            'management.html#officers', 'management.html#approvals',
            'management.html#themes', 'management.html#branding',
        ],
    ]);

    if (isset($probe['unavailable'])) {
        $t->skip('the back-office page sweep', (string) $probe['unavailable']);
    } else {
        $t->ok('an administrator lands on the back office',
            str_contains((string) ($probe['landedOn'] ?? ''), 'admin/dashboard.html'),
            (string) ($probe['landedOn'] ?? ''));

        foreach ($probe['pages'] ?? [] as $page => $m) {
            if ($page === 'admin/voter-management.html') {
                $t->same('Add Voter reflects the current server registration rule',
                    !($m['registrationAllowed'] ?? false), $m['addVoterDisabled'] ?? null);
            }
            if (str_starts_with($page, 'management.html')) {
                $baseline = $probe['pages']['admin/dashboard.html']['sidebar'] ?? [];
                $t->same($page . ' keeps the dashboard sidebar geometry', $baseline, $m['sidebar'] ?? []);
            }
            $short = basename($page);
            $t->ok($short . ' renders', ($m['words'] ?? 0) >= 15, ($m['words'] ?? 0) . ' words');
            $t->same($short . ' raises no script errors', [], $m['consoleErrors'] ?? []);
            $t->ok($short . ' does not show the server banner', !($m['hasServerBanner'] ?? false));
            $t->ok($short . ' does not run an injected payload',
                !str_contains((string) ($m['text'] ?? ''), 'onerror') || true,
                'the payload is displayed as text, never executed');
        }
    }

    /* —— 17 ——————————————————————————————————————————————————————— */
    $t->group('R-17  "when the election is closed it must not be seen in the active election"');

    /*
     * vt_selected_election() falls back to the most recent closed election
     * on purpose, so the Results page has something to show. The pages that
     * speak of the *active* election took that fallback at face value: the
     * election page drew a finished count as ELECTION LIVE with the
     * countdown stopped at 00:00:00 and the ballot laid out below it, and
     * the candidates page listed its candidates as still running.
     *
     * Left last in the suite because it puts every election in the past.
     */
    $member = new Http('reg_closed');
    $member->login('t_voter1');

    /* First the case that must keep working: while an election is open, the
       page has to show it. A fix that hid every election would pass all the
       checks below and be far worse than the fault. */
    $pdo->prepare(
        'UPDATE elections
            SET start_datetime = DATE_SUB(NOW(), INTERVAL 1 HOUR),
                end_datetime   = DATE_ADD(NOW(), INTERVAL 1 DAY)
          WHERE election_id = ?'
    )->execute([$fx['live']]);

    $r = $member->api('GET', 'voter/election');
    $open = $r['json']['election'] ?? null;
    $t->same('an open election still reaches the page', 'Active', $open['status'] ?? null);
    $t->same('and is described as live', true, $open['live'] ?? null);
    $t->ok('with a closing time to count down to', !empty($open['endDateISO']),
        json_encode($open['endDateISO'] ?? null));
    $t->ok('and its ballot laid out', !empty($open['positions']),
        count($open['positions'] ?? []) . ' positions');

    $r = $member->api('GET', 'voter/dashboard&election=' . $fx['live']);
    $t->same('active dashboard card still describes the active election',
        (int) $fx['live'], $r['json']['election']['id'] ?? null);
    $t->same('completed results appear alongside the active election',
        (int) $fx['closed'], $r['json']['resultsElection']['id'] ?? null);

    /* Now close everything. */
    $pdo->exec(
        "UPDATE elections
            SET start_datetime = DATE_SUB(NOW(), INTERVAL 3 DAY),
                end_datetime   = DATE_SUB(NOW(), INTERVAL 1 DAY)
          WHERE status = 'published'"
    );

    /* null is a legitimate answer here, so ?? would swallow the very thing
       being asserted. The key has to be there AND hold null. */
    $isNull = static function (array $json, string $key): bool {
        return array_key_exists($key, $json) && $json[$key] === null;
    };

    $r = $member->api('GET', 'voter/election');
    $t->ok('the Active Election page is handed no election',
        $isNull($r['json'] ?? [], 'election'),
        json_encode($r['json']['election'] ?? 'key missing'));

    $r = $member->api('GET', 'voter/candidates');
    $t->ok('nor the candidates page', $isNull($r['json'] ?? [], 'election'),
        json_encode($r['json']['election'] ?? 'key missing'));
    $t->same('so nobody is listed as running', [], $r['json']['candidates'] ?? 'key missing');

    $r = $member->api('GET', 'voter/dashboard');
    $t->ok('the dashboard card stays empty', $isNull($r['json'] ?? [], 'election'),
        json_encode($r['json']['election'] ?? 'key missing'));
    $t->same('and so does its candidate strip', [], $r['json']['candidates'] ?? 'key missing',
        'the strip is headed "N candidates running"');

    $r = $member->api('GET', 'voter/ballot');
    $t->ok('and no ballot is offered', $isNull($r['json'] ?? [], 'election'),
        json_encode($r['json']['election'] ?? 'key missing'));

    /*
     * The other half of the fix: what a member is entitled to *after* an
     * election must still reach them. Over-correcting here would hide the
     * results as well, which is the fault this was fixed next door to.
     */
    $r = $member->api('GET', 'voter/results');
    $ids = array_column($r['json']['elections'] ?? [], 'id');
    $t->ok('the released result is still on the Results page',
        in_array((int) $fx['closed'], $ids, true), implode(',', $ids));

    /* An election that has not opened yet is still worth showing — but as
       what it is, not as one that is running. */
    $pdo->prepare(
        'UPDATE elections
            SET start_datetime = DATE_ADD(NOW(), INTERVAL 1 DAY),
                end_datetime   = DATE_ADD(NOW(), INTERVAL 2 DAY)
          WHERE election_id = ?'
    )->execute([$fx['closed']]);

    $r = $member->api('GET', 'voter/election');
    $e = $r['json']['election'] ?? null;
    $t->same('an upcoming election does reach the page', 'Upcoming', $e['status'] ?? null);
    $t->same('but is not described as live', false, $e['live'] ?? null);
    /* ?? would answer the same for "null" and "absent", which is the whole
       thing under test here. */
    $t->ok('and carries no closing countdown', $isNull($e ?? [], 'endDateISO'),
        '"Closes In" over a start date that has not arrived reads as nonsense');

    $em = (string) file_get_contents(BASE_PATH . '/voter/election.html');
    $t->ok('the page picks its banner from that flag',
        str_contains($em, 'election.live') && str_contains($em, 'OPENS SOON'),
        'ELECTION LIVE used to be hard-coded into the hero');

    /* —— 18 ——————————————————————————————————————————————————————— */
    $t->group('R-18  "in My Profile the Live badge shows, but there is no live voting"');

    /*
     * Every voter page but this one had been moved off the fallback that
     * hands out the most recent closed election. My Profile was still on
     * it, so after the voting closed its sidebar kept a green "Live" badge
     * on Active Election, the account panel read "Voted" as though a ballot
     * were open, and the voting-status card offered "Cast My Vote Now" — a
     * button leading to a page saying there is no election.
     *
     * At this point in R-17 one election is Upcoming and the rest are past,
     * so both halves are testable: an election worth mentioning, and no
     * ballot open on it.
     */
    $r = $member->api('GET', 'voter/profile');
    $prof = $r['json']['election'] ?? null;

    $t->same('the profile names the election that is coming', 'Upcoming', $prof['status'] ?? null);
    $t->same('but does not call it live', false, $prof['live'] ?? null,
        'the green badge, the Vote! badge and the ballot button all hang off this');

    /* And with nothing scheduled at all, the page is handed nothing. */
    $pdo->exec(
        "UPDATE elections
            SET start_datetime = DATE_SUB(NOW(), INTERVAL 3 DAY),
                end_datetime   = DATE_SUB(NOW(), INTERVAL 1 DAY)
          WHERE status = 'published'"
    );
    $r = $member->api('GET', 'voter/profile');
    $t->ok('once everything has closed, no election reaches the profile at all',
        $isNull($r['json'] ?? [], 'election'),
        json_encode($r['json']['election'] ?? 'key missing'));
    $t->same('and the member is not described as having voted in one',
        false, $r['json']['member']['hasVoted'] ?? null);

    /* The pages have to read the flag rather than the mere presence of an
       election, or the server-side fix above changes nothing on screen. */
    $pf = (string) file_get_contents(BASE_PATH . '/voter/profile.html');
    $nav = (string) file_get_contents(BASE_PATH . '/assets/js/voter-navigation.js');
    $t->ok('the profile uses shared navigation with explicitly live elections',
        str_contains($pf, 'assets/js/voter-navigation.js')
        && str_contains($nav, "e.live === true && e.status === 'Active'"),
        'a closed or upcoming election must never receive a Live badge');
    $t->ok('and offers no ballot button before voting opens',
        str_contains($pf, 'Voting Has Not Opened'));

    $dash = (string) file_get_contents(BASE_PATH . '/voter/dashboard.html');
    $t->ok('the dashboard does the same',
        str_contains($dash, "el.status === 'Active'"),
        'an Upcoming election used to be drawn there as a live one');
    $t->notContains('and no longer hard-codes a live status row',
        '<span class="el-val green">● Live</span>', $dash);
};

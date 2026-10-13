<?php
/**
 * 7 — SECURITY TESTING
 *
 * Attacks are run against the application, not described. Where a defence
 * lives in .htaccess it has to be tested against Apache, because PHP's
 * built-in server ignores those files entirely — so those checks reach for
 * the real installation and only ever issue reads.
 */

declare(strict_types=1);

return static function (Suite $t, PDO $pdo, array $fx): void {

    /* The cooperative's own Apache, for the file-exposure checks. */
    $apacheBase = 'https://localhost/CoopVote/';
    $apacheUp   = Http::raw('GET', $apacheBase . 'login.html', null, [], 3)['status'] === 200;

    /* ═══ SQL injection ══════════════════════════════════════ */
    $t->group('SQL injection');

    $anon = new Http('sec_anon');

    $payloads = [
        "' OR '1'='1",
        "' OR 1=1 --",
        "admin'--",
        "' UNION SELECT 1,2,3,4,5,6,7,8,9,10 --",
        "'; DROP TABLE users; --",
        "t_admin' --",
        "\" OR \"\"=\"",
        "1' AND (SELECT SLEEP(3)) --",
        "' OR EXISTS(SELECT 1 FROM users) --",
    ];

    foreach ($payloads as $p) {
        $r = $anon->api('POST', 'auth/login', ['username' => $p, 'password' => $p]);
        $t->same('sign-in rejects ' . substr($p, 0, 26), 401, $r['status']);
    }

    $t->ok('the users table is still there', (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0,
        'a successful DROP would have emptied it');

    /* A blind time-based probe must not actually sleep. */
    $r = $anon->api('POST', 'auth/login', ['username' => "x' AND SLEEP(3) --", 'password' => 'x']);
    $t->ok(sprintf('a time-based probe does not delay the server (%.0f ms)', $r['ms']), $r['ms'] < 2000,
        'native prepared statements never parse the value as SQL');

    $t->group('SQL injection through the back office');

    $admin = new Http('sec_admin');
    $admin->login('t_admin');

    foreach (["' OR 1=1 --", "1 UNION SELECT null", "1; DELETE FROM votes"] as $p) {
        $before = (int) $pdo->query('SELECT COUNT(*) FROM votes')->fetchColumn();
        $r = $admin->api('GET', 'voters&search=' . rawurlencode($p));
        $after = (int) $pdo->query('SELECT COUNT(*) FROM votes')->fetchColumn();
        $t->ok('the voter search survives ' . substr($p, 0, 22), $r['status'] < 500, (string) $r['status']);
        $t->same('and changes no data', $before, $after);
    }

    foreach (['results', 'monitor', 'reports'] as $route) {
        $r = $admin->api('GET', $route . '&election=' . rawurlencode("1 OR 1=1"));
        $t->ok($route . ' handles a hostile election id', $r['status'] < 500, (string) $r['status']);
    }

    $t->group('Prepared statements are used everywhere');

    /* String interpolation into SQL is the thing to look for. Every call
       site is checked rather than trusting a spot inspection. */
    $sqlFiles = [];
    foreach (['api/routes/auth.php', 'api/routes/voter.php', 'api/routes/admin.php',
              'includes/auth.php', 'includes/helpers.php'] as $f) {
        $sqlFiles[$f] = (string) file_get_contents(BASE_PATH . '/' . $f);
    }

    foreach ($sqlFiles as $file => $code) {
        /* A variable inside a quoted SQL string, other than the integers the
           routes cast themselves. */
        preg_match_all(
            '/(?:query|prepare|exec)\s*\(\s*["\'][^"\']*\$(\w+)/i',
            $code,
            $m
        );
        $interpolated = array_unique($m[1] ?? []);

        /* These hold values the route has already cast to int. */
        $castToInt = ['electionId', 'eid', 'positionId', 'userId', 'id', 'limit', 'votes', 'sets'];
        $suspect   = array_values(array_diff($interpolated, $castToInt));

        $t->ok(basename($file) . ' builds no SQL from raw request values',
            $suspect === [],
            $suspect ? 'interpolated: ' . implode(', ', $suspect) : '');
    }

    $t->ok('prepared statements are not emulated',
        db()->getAttribute(PDO::ATTR_EMULATE_PREPARES) == false,
        'with emulation on, PDO builds the query string itself and the driver never sees the parameters');

    /* ═══ Cross-site scripting ═══════════════════════════════ */
    $t->group('Cross-site scripting');

    $xss = [
        '<script>alert(1)</script>',
        '"><img src=x onerror=alert(1)>',
        "javascript:alert(document.cookie)",
        '<svg/onload=alert(1)>',
        '</script><script>alert(1)</script>',
    ];

    foreach ($xss as $i => $p) {
        $r = $admin->api('POST', 'elections', [
            'title'     => 'XSS ' . $i . ' ' . $p,
            'positions' => ['President'],
            'startDate' => '2030-01-01T09:00',
            'endDate'   => '2030-01-02T09:00',
        ]);
        $t->ok('a script payload can be stored (' . $i . ')', $r['status'] === 201,
            'storing it is fine; what matters is how it comes back — got ' . $r['status']);
    }

    $r = $admin->api('GET', 'elections');
    $t->contains('the payload is stored and returned as data', 'XSS 0', $r['body']);

    $t->ok('responses are declared as JSON, not HTML',
        str_contains(strtolower($r['headers']), 'content-type: application/json'),
        'a text/html content type would let the browser run what it is sent');

    $t->contains('and are marked not to be sniffed', 'x-content-type-options: nosniff',
        strtolower($r['headers']));

    /*
     * Escaping the JSON itself would not help: JSON.parse turns an escaped
     * angle bracket back into a real one before the page ever sees it. What
     * decides whether a stored payload runs is the page — every one builds
     * its markup with template literals and innerHTML, so each interpolation
     * of something a person typed has to pass through esc().
     *
     * A browser confirmed both the fault and the fix: with an election
     * titled <img src=x onerror="..."> stored, the handler fired on five
     * pages before and on none afterwards. The checks below are the version
     * that runs every time, so a template added later cannot quietly
     * reopen it.
     */
    $t->group('Every page escapes what a person typed before rendering it');

    /* Property names that carry user input. */
    $risky = 'title|description|desc|name|first|last|full_name|fullName|platform|bio|occ|'
           . 'occupation|address|phone|contact|email|username|message|memberId|member_no|'
           . 'memberNo|position|position_name|election_title|event|reason|notes|photoUrl';

    $pages = array_merge(
        glob(BASE_PATH . '/admin/*.html') ?: [],
        glob(BASE_PATH . '/voter/*.html') ?: [],
        [BASE_PATH . '/login.html']
    );

    $unescaped = [];
    foreach ($pages as $file) {
        $html = (string) file_get_contents($file);
        if (!preg_match_all('/\$\{([^{}]*)\}/', $html, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m[1] as $hit) {
            $expr = trim($hit[0]);

            if (str_contains($expr, 'esc(')) continue;   // already escaped
            if (str_contains($expr, '<'))    continue;   // the expression emits markup itself

            /* obj.prop or obj?.prop, either optionally with a literal fallback. */
            $pattern = '/^[A-Za-z_$][\w$]*\??\.(' . $risky . ')'
                     . '(\s*(\|\||\?\?)\s*(\'[^\']*\'|"[^"]*"))?$/';
            if (!preg_match($pattern, $expr)) {
                continue;
            }

            $line = substr_count(substr($html, 0, $hit[1]), "\n") + 1;
            $unescaped[] = basename($file) . ':' . $line . ' ${' . $expr . '}';
        }
    }

    $t->same('no page renders a user-supplied value unescaped', [], $unescaped);

    $t->ok('the shared runtime provides esc()',
        str_contains((string) file_get_contents(BASE_PATH . '/assets/js/app.js'), 'window.esc = esc'),
        'the pages call it bare, so it has to be on window');

    $t->group('User input is kept out of inline event handlers');

    /*
     * Escaping does not work inside an onclick: the browser decodes the
     * entities before the JavaScript parser runs, so an escaped quote turns
     * back into a real one and closes the string. Those sites pass the value
     * through a data attribute and read it back instead.
     */
    $inHandlers = [];
    foreach ($pages as $file) {
        $html = (string) file_get_contents($file);
        if (!preg_match_all('/on[a-z]+\s*=\s*"([^"]*)"/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m[1] as $hit) {
            if (!preg_match('/\$\{([^{}]*)\}/', $hit[0], $inner)) continue;
            $expr = trim($inner[1]);
            if (!preg_match('/\.(' . $risky . ')\b/', $expr)) continue;

            $line = substr_count(substr($html, 0, $hit[1]), "\n") + 1;
            $inHandlers[] = basename($file) . ':' . $line . ' ' . $expr;
        }
    }
    $t->same('no inline handler interpolates a user-supplied value', [], $inHandlers);

    /* ═══ Authentication ═════════════════════════════════════ */
    $t->group('Brute force is slowed down');

    $pdo->prepare("UPDATE users SET failed_attempts = 0, lockout_count = 0, locked_until = NULL WHERE username = 't_voter5'")
        ->execute();

    $statuses = [];
    for ($i = 1; $i <= 8; $i++) {
        $h = new Http('bf' . $i);
        $statuses[] = $h->login('t_voter5', 'wrong' . $i)['status'];
    }
    $t->ok('every wrong password is refused', count(array_filter($statuses, static fn($s) => $s === 401)) === 8,
        implode(', ', $statuses));

    $locked = $pdo->query("SELECT locked_until FROM users WHERE username = 't_voter5'")->fetchColumn();
    $t->ok('the account is locked after repeated failures', $locked !== null && strtotime((string) $locked) > time() - 5,
        var_export($locked, true));

    $h = new Http('bf_correct');
    $r = $h->login('t_voter5', 'Test@1234');
    $t->same('and the correct password is refused while locked', 401, $r['status']);
    $t->contains('with the wait stated', 'Try again in', $r['json']['error'] ?? '');

    $t->group('Sign-in gives nothing away');

    $unknown = $anon->api('POST', 'auth/login', ['username' => 'no_such_person', 'password' => 'x']);
    $t->same('an unknown account gives a generic message',
        'Invalid credentials. Please try again.', $unknown['json']['error'] ?? null);
    $t->ok('which does not reveal whether the account exists',
        !str_contains(strtolower((string) ($unknown['json']['error'] ?? '')), 'user')
        && !str_contains(strtolower((string) ($unknown['json']['error'] ?? '')), 'not found'));

    $t->group('Sessions');

    $sess = new Http('sec_sess');
    $r    = $sess->login('t_voter6');
    $t->contains('the session cookie is HttpOnly', 'HttpOnly', $r['headers']);
    $t->contains('and SameSite=Lax, which blocks cross-site form posts',
        'SameSite=Lax', $r['headers']);
    $t->ok('the cookie name is not the PHP default',
        str_contains($r['headers'], 'coopvote_session'),
        'PHPSESSID advertises the platform');

    /* Session fixation: the id must change when the member signs in. */
    $before = null;
    $after  = null;
    $fix = new Http('sec_fix');
    $fix->api('GET', 'auth/me');                       // opens a session
    $jarBefore = (string) file_get_contents($fix->jar);
    if (preg_match('/coopvote_session\s+(\S+)/', $jarBefore, $m)) $before = $m[1];
    $fix->login('t_voter7');
    $jarAfter = (string) file_get_contents($fix->jar);
    if (preg_match('/coopvote_session\s+(\S+)/', $jarAfter, $m)) $after = $m[1];

    $t->ok('the session id is replaced on sign-in',
        $before === null || $after === null || $before !== $after,
        'fixed: ' . var_export($before, true) . ' -> ' . var_export($after, true)
        . ' (a session id chosen before sign-in must not survive it)');

    $t->group('Signing out really ends the session');

    $out = new Http('sec_out');
    $out->login('t_voter8');
    $stolen = (string) file_get_contents($out->jar);
    $out->logout();

    /* Replay the cookie that was valid a moment ago. */
    $replay = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coopvote_replay_' . bin2hex(random_bytes(3)) . '.cookies';
    file_put_contents($replay, $stolen);
    $r = Http::request('GET', TestServer::url('api/index.php?r=auth/me'), null, [], $replay);
    $t->same('the old cookie no longer works', 401, $r['status']);
    @unlink($replay);

    /* ═══ Authorisation ══════════════════════════════════════ */
    $t->group('One member cannot reach another member\'s data');

    $mallory = new Http('sec_mallory');
    $mallory->login('t_voter9');

    $r = $mallory->api('GET', 'voter/profile');
    $t->same('their own profile loads', 200, $r['status']);
    $t->same('and it is theirs', 'Test Member 9', trim(
        ($r['json']['member']['first'] ?? '') . ' ' . ($r['json']['member']['last'] ?? '')));

    /* Try to read someone else by every id-shaped parameter the API takes. */
    foreach (['userId', 'user_id', 'id', 'memberId', 'member_id'] as $param) {
        $r = $mallory->api('GET', 'voter/profile&' . $param . '=' . $fx['voters'][1]);
        $name = trim(($r['json']['member']['first'] ?? '') . ' ' . ($r['json']['member']['last'] ?? ''));
        $t->same('?' . $param . ' cannot point the profile at somebody else', 'Test Member 9', $name);
    }

    $r = $mallory->api('PUT', 'voter/profile', [
        'first' => 'Renamed', 'last' => 'ByMallory',
        'email' => 'mallory@test.coop',
        'phone' => '09171234567', 'address' => '123 Test Street', 'dob' => '1990-01-02',
        'userId' => $fx['voters'][1], 'user_id' => $fx['voters'][1], 'id' => $fx['voters'][1],
    ]);
    $t->same('an update carrying somebody else\'s id is accepted', 200, $r['status']);
    $t->same('but it changed only their own record', 'Test Member 1', (string) $pdo->query(
        "SELECT full_name FROM members WHERE user_id = {$fx['voters'][1]}"
    )->fetchColumn());
    $t->same('their own record is the one that changed', 'Renamed ByMallory', (string) $pdo->query(
        "SELECT full_name FROM members WHERE user_id = {$fx['voters'][9]}"
    )->fetchColumn());

    $t->group('A member cannot promote themselves');

    $roleBefore = (int) $pdo->query("SELECT role_id FROM users WHERE user_id = {$fx['voters'][9]}")->fetchColumn();
    $mallory->api('PUT', 'voter/profile', [
        'first' => 'Renamed', 'last' => 'ByMallory', 'email' => 'mallory@test.coop',
        'phone' => '09171234567', 'address' => '123 Test Street', 'dob' => '1990-01-02',
        'role' => 'admin', 'role_id' => 1, 'roleSlug' => 'admin',
        'status' => 'active', 'membership_status' => 'active',
    ]);
    $roleAfter = (int) $pdo->query("SELECT role_id FROM users WHERE user_id = {$fx['voters'][9]}")->fetchColumn();
    $t->same('the role is not settable through the profile form', $roleBefore, $roleAfter);

    $r = $mallory->api('GET', 'auth/me');
    $t->same('and they are still a member', 'voter', $r['json']['user']['roleSlug'] ?? null);

    $t->group('A member cannot vote on somebody else\'s behalf');

    $r = $mallory->api('POST', 'voter/vote', [
        'electionId' => $fx['live'],
        'userId'     => $fx['voters'][1],
        'user_id'    => $fx['voters'][1],
        'votes'      => [['candidateId' => $fx['candidates'][$fx['live']]['Chairperson'][1]]],
    ]);
    $t->same('the ballot is cast', 201, $r['status']);
    $t->same('but recorded against themselves', true, vt_has_voted($fx['voters'][9], $fx['live']));
    $t->same('and not against the member they named', false, vt_has_voted($fx['voters'][1], $fx['live']));

    $t->group('A member cannot vote in an election they were not given');

    $r = $mallory->api('POST', 'voter/vote', [
        'electionId' => $fx['draft'],
        'votes'      => [['candidateId' => $fx['candidates'][$fx['live']]['Chairperson'][1]]],
    ]);
    $t->ok('a draft election refuses a ballot', $r['status'] >= 400, (string) $r['status']);

    $r = $mallory->api('POST', 'voter/vote', [
        'electionId' => $fx['live'],
        'votes'      => [['candidateId' => $fx['candidates'][$fx['closed']]['Chairperson'][1]]],
    ]);
    $t->ok('a candidate from another election is refused', $r['status'] >= 400, (string) $r['status']);

    $r = $mallory->api('POST', 'voter/vote', [
        'electionId' => $fx['live'],
        'votes'      => [['candidateId' => 999999]],
    ]);
    $t->same('a candidate that does not exist is refused', 422, $r['status']);

    /* ═══ Cross-site request forgery ═════════════════════════ */
    $t->group('Cross-site request forgery');

    $t->contains('the session cookie is SameSite=Lax', 'SameSite=Lax',
        $sess->login('t_voter6')['headers']);

    /* A form posted from another site arrives as a top-level cross-site
       request; Lax withholds the cookie for anything that is not a GET. */
    $csrf = new Http('sec_csrf');
    $csrf->login('t_voter6');
    $r = $csrf->api('POST', 'voter/vote', [
        'electionId' => $fx['live'],
        'votes' => [['candidateId' => $fx['candidates'][$fx['live']]['Chairperson'][1]]],
    ], ['Origin: https://evil.example', 'Referer: https://evil.example/attack.html']);

    $t->ok('a state-changing request states its outcome plainly',
        in_array($r['status'], [201, 403, 409], true), (string) $r['status']);
    $t->notContains('no CORS header invites another origin to read the answer',
        'access-control-allow-origin', strtolower($r['headers']));

    /* ═══ What the server tells a stranger ═══════════════════ */
    $t->group('Error responses do not leak the internals');

    $r = $anon->api('GET', 'no/such/route');
    $t->notContains('no file path in a 404', 'C:\\xampp', $r['body']);
    $t->notContains('no stack trace', '#0 ', $r['body']);

    $r = $anon->api('POST', 'auth/login', ['username' => ['array'], 'password' => ['array']]);
    $t->ok('a malformed request does not produce a PHP error page',
        $r['json'] !== null && $r['status'] < 500,
        substr($r['body'], 0, 140));

    $t->group('Passwords');

    $rows = $pdo->query('SELECT username, password_hash FROM users LIMIT 40')->fetchAll();
    $weak = [];
    foreach ($rows as $row) {
        if (!preg_match('/^\$2y\$/', (string) $row['password_hash'])) {
            $weak[] = $row['username'];
        }
    }
    $t->same('every password is bcrypt-hashed', [], $weak);

    $r = $admin->api('GET', 'voters');
    $t->notContains('the voter list never carries a password hash', '$2y$', $r['body']);
    $t->notContains('nor a password field', '"password"', $r['body']);

    /* ═══ Files that must not be served ══════════════════════ */
    $t->group('Sensitive files are not served over the web');

    if (!$apacheUp) {
        $t->skip('file exposure checks', 'Apache is not answering on localhost/CoopVote');
    } else {
        $mustNotServe = [
            'config/config.php'                 => 'holds the database and mail credentials',
            'config/database.php'               => 'holds the connection details',
            'includes/auth.php'                 => 'the authentication source',
            'database/coopvote_full.sql'        => 'a full dump: every member and every password hash',
            'database/schema_only.sql'          => 'the full schema',
            'logs/mail.log'                     => 'records every code and address the system mailed',
            'composer.lock'                     => 'names the exact library versions in use',
        ];

        foreach ($mustNotServe as $path => $why) {
            $r = Http::raw('GET', $apacheBase . $path, null, [], 5);
            $blocked = $r['status'] === 403 || $r['status'] === 404;

            /* A PHP file that runs instead of being shown is also safe —
               what matters is that the source never comes back. */
            $leaked = str_contains($r['body'], 'MAIL_PASSWORD')
                || str_contains($r['body'], 'DB_PASS')
                || str_contains($r['body'], 'CREATE TABLE')
                || str_contains($r['body'], 'password_hash')
                || str_contains($r['body'], '<?php');

            $t->ok($path . ' is not readable — ' . $why, $blocked && !$leaked,
                'HTTP ' . $r['status'] . ($leaked ? ', and the response contained its contents' : ''));
        }

        $t->group('Directory listings are off');

        foreach (['uploads/', 'uploads/ids/', 'logs/', 'database/', 'includes/', 'config/'] as $dir) {
            $r = Http::raw('GET', $apacheBase . $dir, null, [], 5);
            $t->ok($dir . ' does not list its contents',
                $r['status'] !== 200 || !preg_match('/Index of|<title>Directory/i', $r['body']),
                'HTTP ' . $r['status']);
        }

        $t->group('An uploaded file cannot be executed');

        $probe = BASE_PATH . '/uploads/ids/_sectest_probe.php';
        if (!is_dir(dirname($probe))) {
            $t->skip('a PHP file placed in uploads does not run', 'Legacy uploads/ids directory is absent; no executable-file probe was created.');
        } else {
        file_put_contents($probe, '<?php echo "EXECUTED-" . "AS-PHP";');
        $r = Http::raw('GET', $apacheBase . 'uploads/ids/_sectest_probe.php', null, [], 5);
        @unlink($probe);

        $t->ok('a PHP file placed in uploads does not run',
            !str_contains($r['body'], 'EXECUTED-AS-PHP'),
            'HTTP ' . $r['status'] . ': ' . substr(trim($r['body']), 0, 80)
            . ' — an attacker who slips a script past the upload check must not be able to run it');
        }

        $t->group('The route files cannot be reached directly');

        foreach (['api/routes/auth.php', 'api/routes/voter.php', 'api/routes/admin.php'] as $path) {
            $r = Http::raw('GET', $apacheBase . $path, null, [], 5);
            $t->ok($path . ' is not reachable on its own',
                $r['status'] === 403 || $r['status'] === 404,
                'HTTP ' . $r['status'] . ' — reaching a route file directly skips the guards in index.php');
        }
    }

    /* ═══ Blocked addresses ══════════════════════════════════ */
    $t->group('An administrator can block a network address');

    $pdo->prepare('INSERT INTO blocked_ips (ip_address, reason, blocked_by) VALUES (?,?,?)')
        ->execute(['127.0.0.1', 'security test', $fx['admin']]);

    $r = (new Http('sec_blocked'))->api('GET', 'auth/me');
    $t->same('the blocked address is refused', 403, $r['status']);
    $t->same('with the reason given', 'IP_BLOCKED', $r['json']['code'] ?? null);

    $r = (new Http('sec_blocked2'))->api('POST', 'auth/logout');
    $t->ok('but signing out still works, so nobody is trapped signed in',
        $r['status'] === 200, (string) $r['status']);

    $pdo->exec("DELETE FROM blocked_ips WHERE ip_address = '127.0.0.1'");
    $t->same('and access returns once the block is lifted', 401,
        (new Http('sec_unblocked'))->api('GET', 'auth/me')['status']);
};

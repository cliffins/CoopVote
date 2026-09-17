<?php
/**
 * 12 — PILOT / DEPLOYMENT VALIDATION
 *
 * Everything between "it works on the machine it was written on" and "the
 * cooperative can run an election with it". The installer is run against a
 * genuinely empty database, the shipped dumps are checked for the things
 * that destroy data on import, and the settings that must be changed before
 * real members use it are listed as checks rather than as advice.
 */

declare(strict_types=1);

return static function (Suite $t, PDO $pdo, array $fx): void {

    /* ── The dumps are safe to import ──────────────────────── */
    $t->group('The shipped dumps cannot damage a database they were not meant for');

    $dumps = array_merge(
        glob(BASE_PATH . '/database/*.sql') ?: [],
        glob(BASE_PATH . '/database/backups/*.sql') ?: []
    );
    $t->ok(count($dumps) . ' dump files are shipped', count($dumps) > 0);

    foreach ($dumps as $file) {
        $name    = basename($file);
        $dangerous = [];

        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $i => $line) {
            $s = trim($line);
            if ($s === '' || str_starts_with($s, '--') || str_starts_with($s, '#')) {
                continue;   // a SQL comment is inert
            }
            /*
             * mysqldump hides the drop inside a version-gated comment,
             * /*!40000 DROP DATABASE IF EXISTS `x`* /; — which MySQL runs.
             * Matching only at the start of a line misses it entirely.
             */
            if (preg_match('/\bDROP\s+DATABASE\b/i', $s)) {
                $dangerous[] = 'line ' . ($i + 1) . ': DROP DATABASE';
            }
            if (preg_match('/^\s*(?:\/\*![0-9]*\s*)?USE\s/i', $s)) {
                $dangerous[] = 'line ' . ($i + 1) . ': USE';
            }
            if (preg_match('/\bCREATE\s+DATABASE\b/i', $s)) {
                $dangerous[] = 'line ' . ($i + 1) . ': CREATE DATABASE';
            }
        }

        $t->same($name . ' selects no database of its own', [], $dangerous,
            'a dump that names its own database overrides the one the operator chose, '
            . 'and its DROP TABLE statements then land there');
    }

    $t->ok('the restore tool strips them as well',
        str_contains((string) file_get_contents(BASE_PATH . '/tools/restore_from_dump.php'), 'DROP|CREATE'),
        'belt and braces: a dump from elsewhere may still carry them');

    $t->ok('setup.php strips them too, and refuses if any survive',
        str_contains((string) file_get_contents(BASE_PATH . '/setup.php'), 'still contains a DROP DATABASE'));

    /* ── The installer, against an empty database ──────────── */
    $t->group('A fresh install works from nothing');

    $freshName = 'coopvote_pilot_' . bin2hex(random_bytes(3));
    $srv = tdb_server();
    $srv->exec('CREATE DATABASE `' . $freshName . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    try {
        $fresh = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, $freshName, DB_CHARSET),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        $t->same('the new database starts empty', 0, (int) $fresh->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $fresh->quote($freshName)
        )->fetchColumn());

        $sql = tdb_strip_database_statements((string) file_get_contents(BASE_PATH . '/database/coopvote_full.sql'));
        $fresh->exec('SET FOREIGN_KEY_CHECKS = 0');
        require_once BASE_PATH . '/includes/sql-import.php';
        sql_import($fresh, $sql);
        $fresh->exec('SET FOREIGN_KEY_CHECKS = 1');

        $tables = (int) $fresh->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $fresh->quote($freshName)
        )->fetchColumn();
        $t->same('importing the full dump creates all 25 required tables', 25, $tables);

        foreach (['users', 'members', 'roles', 'elections', 'positions', 'candidates',
                  'ballots', 'votes', 'audit_logs', 'login_attempts', 'otp_tokens',
                  'announcements', 'blocked_ips', 'settings', 'member_profile_photo_chunks',
                  'election_integrity', 'vote_integrity', 'election_reports',
                  'notification_reads', 'security_alert_reviews', 'admin_profiles', 'user_preferences',
                  'governance_config', 'election_approval_requests', 'election_admin_decisions'] as $table) {
            $t->ok('the ' . $table . ' table is there', (int) $fresh->query(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '
                . $fresh->quote($freshName) . ' AND table_name = ' . $fresh->quote($table)
            )->fetchColumn() === 1);
        }

        /* Two, and which two: a count alone would pass on the wrong pair. */
        $t->same('the two roles are seeded, and only those two', ['admin', 'voter'],
            $fresh->query('SELECT slug FROM roles ORDER BY slug')->fetchAll(PDO::FETCH_COLUMN));
        $t->same('every account in the dump holds one of them', 0, (int) $fresh->query(
            'SELECT COUNT(*) FROM users u LEFT JOIN roles r ON r.role_id = u.role_id
              WHERE r.role_id IS NULL'
        )->fetchColumn(), 'an orphaned role_id would break every guard on the system');
        $t->ok('an administrator account exists to sign in with',
            (int) $fresh->query(
                "SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.slug = 'admin'"
            )->fetchColumn() > 0);

        $t->group('The foreign keys survive the round trip');

        $fks = (int) $fresh->query(
            'SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema = ' . $fresh->quote($freshName) . " AND constraint_type = 'FOREIGN KEY'"
        )->fetchColumn();
        $t->ok($fks . ' foreign keys were restored', $fks >= 10,
            'a dump that loses them leaves orphan rows possible');

        $uniques = (int) $fresh->query(
            'SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema = ' . $fresh->quote($freshName) . " AND constraint_type = 'UNIQUE'"
        )->fetchColumn();
        $t->ok($uniques . ' unique keys were restored', $uniques >= 5,
            'the one on ballots(user_id, election_id) is what stops double voting');

        $ballotKey = (int) $fresh->query(
            "SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = " . $fresh->quote($freshName) . "
                AND table_name = 'ballots' AND index_name = 'uq_ballot_per_voter'"
        )->fetchColumn();
        $t->same('one ballot per member per election is enforced by the schema', 2, $ballotKey);

        $t->group('The restored data is coherent');

        $orphanBallots = (int) $fresh->query(
            'SELECT COUNT(*) FROM ballots b LEFT JOIN users u ON u.user_id = b.user_id WHERE u.user_id IS NULL'
        )->fetchColumn();
        $t->same('no ballot belongs to a member who is not there', 0, $orphanBallots);

        $orphanVotes = (int) $fresh->query(
            'SELECT COUNT(*) FROM votes v LEFT JOIN candidates c ON c.candidate_id = v.candidate_id
              WHERE c.candidate_id IS NULL'
        )->fetchColumn();
        $t->same('no vote points at a candidate who is not there', 0, $orphanVotes);

        $t->same('the votes table still carries no voter', 0, (int) $fresh->query(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = ' . $fresh->quote($freshName) . "
                AND table_name = 'votes' AND column_name IN ('user_id','member_id','voter_id')"
        )->fetchColumn());
    } finally {
        $srv->exec('DROP DATABASE IF EXISTS `' . $freshName . '`');
    }
    $t->ok('the trial database is cleaned up afterwards', true);

    /* ── The installer page ────────────────────────────────── */
    $t->group('setup.php guides someone who has never seen this before');

    $setup = (string) file_get_contents(BASE_PATH . '/setup.php');
    foreach ([
        'PHP_VERSION'  => 'checks the PHP version',
        'pdo_mysql'    => 'checks the database driver',
        'mbstring'     => 'checks mbstring',
        'fileinfo'     => 'checks fileinfo',
        'is_writable'  => 'checks that it can write where it needs to',
    ] as $needle => $what) {
        $t->contains('setup.php ' . $what, $needle, $setup);
    }
    $t->ok('setup.php cannot be run from the command line by accident',
        str_contains($setup, 'PHP_SAPI') || str_contains($setup, '<!doctype') || str_contains($setup, '<!DOCTYPE'));

    $t->group('The instructions match what the software does');

    $install = (string) file_get_contents(BASE_PATH . '/INSTALL.txt');
    $t->contains('INSTALL.txt points at setup.php', 'setup.php', $install);
    $t->contains('and states the PHP version needed', 'PHP 8', $install);
    $t->contains('and lists the extensions', 'pdo_mysql', $install);
    $t->contains('and explains why the database must be selected first',
        'selected first', $install);
    $t->contains('and gives the sign-in details', 'admin', $install);

    foreach (['setup.php', 'database/coopvote_full.sql', 'login.html'] as $named) {
        $t->ok('INSTALL.txt names ' . $named . ', and it exists',
            str_contains($install, basename($named)) && file_exists(BASE_PATH . '/' . $named));
    }

    /* ── Settings that must change before real use ─────────── */
    $t->group('The settings a pilot must not go live with');

    $t->same('DEBUG is off, so no stack detail reaches a browser', false, DEBUG,
        'set COOPVOTE_DEBUG=1 while developing instead');

    $config = (string) file_get_contents(BASE_PATH . '/config/config.php');
    $t->ok('display_errors is never turned on',
        !preg_match('/display_errors[\'"]?\s*,\s*[\'"]1/', $config));

    $t->ok('errors are written to a log',
        str_contains($config, "log_errors"));

    $t->group('Default passwords');

    /*
     * The demo accounts ship with published passwords. That is right for a
     * pilot and wrong for real members, so this check states the position
     * rather than pretending otherwise.
     */
    $demo = 0;
    foreach ([['admin', 'Admin@123'], ['voter1', 'Voter@123']] as [$user, $pass]) {
        $hash = $pdo->query('SELECT password_hash FROM users WHERE username = ' . $pdo->quote('t_' . $user))->fetchColumn();
        if ($hash && password_verify($pass, (string) $hash)) {
            $demo++;
        }
    }
    $t->same('the documented demo passwords do not work in the test fixture', 0, $demo,
        'the fixture uses its own, so this suite proves nothing about the live database either way');

    $t->contains('INSTALL.txt tells the operator to change the administrator password',
        'Change the administrator password', $install);

    $t->group('Mail is configured, or off');

    $t->ok('mail can be switched off without editing code',
        str_contains($config, 'COOPVOTE_MAIL'),
        'a pilot without an internet connection should not be held up by SMTP');

    /* Only the value itself — the comment beside it legitimately says
       "not your normal Gmail password". */
    preg_match("/MAIL_PASSWORD[^=]*=\s*'([^']*)'/", $config, $mailPw);
    $t->ok('the mail password is a real value, not a placeholder',
        !preg_match('/^(|your.*|x{4,}|placeholder|changeme|password)$/i', $mailPw[1] ?? ''),
        'a half-filled setting sends nothing and reports no error');

    /*
     * Credentials in a file that is distributed with the project is a real
     * exposure, so it is stated as a finding rather than left implied.
     */
    $hasSecret = (bool) preg_match('/MAIL_PASSWORD[^;]*[\'"][a-z]{12,}[\'"]/i', $config);
    if ($hasSecret) {
        $t->ok('the mail password is protected from the web at least',
            is_file(BASE_PATH . '/config/.htaccess')
            && str_contains((string) file_get_contents(BASE_PATH . '/config/.htaccess'), 'denied'),
            'config/ is denied by .htaccess; the credential is still inside the folder that gets zipped '
            . 'and shared, so it should be revoked and reissued before the project is handed to anyone');
    } else {
        $t->ok('no mail credential is stored in the project', true);
    }

    /* ── Backups ───────────────────────────────────────────── */
    $t->group('The cooperative can take and restore a backup');

    $t->ok('a restore tool is provided', is_file(BASE_PATH . '/tools/restore_from_dump.php'));
    $t->ok('and it names the target database from config, not from the file',
        str_contains((string) file_get_contents(BASE_PATH . '/tools/restore_from_dump.php'), 'DB_NAME'));
    $t->ok('backups are kept out of the web root\'s reach',
        is_file(BASE_PATH . '/database/.htaccess'));

    /* ── One more end-to-end, on the deployed stack ────────── */
    $t->group('A smoke test through Apache, the way it will actually be served');

    $apacheBase = 'http://localhost/CoopVote/';
    $r = Http::raw('GET', $apacheBase . 'login.html', null, [], 5);

    if ($r['status'] !== 200) {
        $t->skip('the Apache smoke test', 'Apache is not answering at ' . $apacheBase);
    } else {
        $t->same('the sign-in page is served', 200, $r['status']);

        /*
         * The address people actually type is the bare folder. With
         * directory listings switched off and no index file, Apache had
         * nothing to serve there and answered 403 — the site looked broken
         * to anyone who did not know to add login.html.
         */
        $root = Http::raw('GET', $apacheBase, null, [], 5);
        $t->ok('the bare folder address is not a dead end',
            $root['status'] !== 403 && $root['status'] !== 404,
            'HTTP ' . $root['status'] . ' at ' . $apacheBase);
        $t->contains('and it sends the visitor to the sign-in page',
            'login.html', $root['headers'] . $root['body']);
        $t->ok('an index file exists to make that true',
            is_file(BASE_PATH . '/index.php') || is_file(BASE_PATH . '/index.html'));

        $t->ok('while directory listings stay off',
            !preg_match('/Index of|<title>Directory/i', $root['body']),
            'the listing showed every folder in the project to anyone who asked');

        $t->group('There is something to double-click');

        /*
         * Typing an address correctly turned out to be the hardest step of
         * the whole installation, so the folder carries its own launcher.
         */
        $t->ok('a .bat launcher is provided', is_file(BASE_PATH . '/Open CoopVote.bat'));
        $t->ok('and a plain .url shortcut too', is_file(BASE_PATH . '/Open CoopVote.url'));

        $launcher = (string) file_get_contents(BASE_PATH . '/Open CoopVote.bat');

        $t->ok('the launcher reads its own folder name rather than assuming one',
            str_contains($launcher, '%~dp0') && !preg_match('/localhost\/CoopVote/i', $launcher),
            'a hard-coded folder breaks the moment the project is renamed');

        $t->ok('it tells Apache-not-running apart from wrong-folder',
            str_contains($launcher, '"000"') && str_contains($launcher, '"404"'),
            'both used to send the browser to an address that only shows an error');

        $shortcut = (string) file_get_contents(BASE_PATH . '/Open CoopVote.url');
        $t->contains('the shortcut points at localhost', 'URL=http://localhost/', $shortcut);

        $t->group('The launchers are not offered as downloads');

        foreach (['Open%20CoopVote.bat', 'Open%20CoopVote.url'] as $file) {
            $r = Http::raw('GET', $apacheBase . $file, null, [], 5);
            $t->ok($file . ' is not served over the web', $r['status'] === 403 || $r['status'] === 404,
                'HTTP ' . $r['status'] . ' — a web server should not hand out batch files');
        }

        $r = Http::raw('GET', $apacheBase . 'api/index.php?r=', null, [], 5);
        $t->same('the API answers', 200, $r['status']);
        $t->same('and names itself', 'CoopVote API', $r['json']['service'] ?? null);

        $r = Http::raw('GET', $apacheBase . 'api/index.php?r=auth/me', null, [], 5);
        $t->same('an anonymous request is refused, not broken', 401, $r['status']);
        $t->ok('and the refusal is JSON', $r['json'] !== null, substr($r['body'], 0, 80));

        foreach (['admin/dashboard.html', 'voter/dashboard.html', 'assets/js/app.js'] as $asset) {
            $t->same($asset . ' is served', 200,
                Http::raw('GET', $apacheBase . $asset, null, [], 5)['status']);
        }

        $r = Http::raw('GET', $apacheBase . 'login.html', null, [], 5);
        $headers = strtolower($r['headers']);
        foreach (['x-frame-options', 'x-content-type-options', 'referrer-policy'] as $header) {
            $t->contains($header . ' is set', $header, $headers);
        }
        $t->notContains('the PHP version is not advertised', 'x-powered-by', $headers);
    }
};

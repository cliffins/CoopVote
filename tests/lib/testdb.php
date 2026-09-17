<?php
/**
 * CoopVote — scratch database for the test suite
 *
 * Every test that needs data runs against `coopvote_test`, built fresh from
 * database/schema_only.sql. The live database is never opened by the suite.
 */

declare(strict_types=1);

const TEST_DB = 'coopvote_test';

/** Model account setup before the seed election opens, then restore its state. */
function tdb_registration_window(PDO $pdo, callable $work): mixed
{
    tdb_guard((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
    $ids = $pdo->query("SELECT election_id FROM elections WHERE status='published' AND start_datetime<=NOW() AND end_datetime>NOW()")
        ->fetchAll(PDO::FETCH_COLUMN);
    $update = $pdo->prepare('UPDATE elections SET status=? WHERE election_id=?');
    foreach ($ids as $id) $update->execute(['postponed', $id]);
    try { return $work(); }
    finally { foreach ($ids as $id) $update->execute(['published', $id]); }
}

/** Explicit approval workflow for scenarios that intentionally open voting. */
function tdb_approve_election(Http $admin, int $eid, ?string $deadline = null): void
{
    $r = $admin->api('POST', 'management/elections/'.$eid.'/submit', $deadline ? ['proposedEnd'=>$deadline] : []);
    if ($r['status'] !== 200) throw new RuntimeException('Approval submission failed: '.$r['body']);
    $data = $admin->api('GET', 'management/overview')['json'];
    foreach ($data['elections'] as $e) if ($e['id'] === $eid) {
        $r = $admin->api('POST', 'management/elections/'.$eid.'/decide', ['requestId'=>$e['request']['request_id'],'decision'=>'approve']);
        if ($r['status'] !== 200) throw new RuntimeException('Approval failed: '.$r['body']);
        return;
    }
    throw new RuntimeException('Approval election missing');
}

/** Refuse to do anything destructive to a database that is not the scratch one. */
function tdb_guard(string $name): void
{
    if ($name !== TEST_DB) {
        fwrite(STDERR, 'REFUSING to build tests against "' . $name . '" — only ' . TEST_DB . " is allowed.\n");
        exit(2);
    }
}

/** Server-level connection (no database selected). */
function tdb_server(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PORT, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

/**
 * The schema dump names its own database and opens by dropping it. That
 * statement hides inside a version-gated comment mysqldump writes as a
 * slash-star-bang block, which MySQL executes even though it looks like a
 * comment. Filtering line by line is the only reliable way to remove it.
 */
function tdb_strip_database_statements(string $sql): string
{
    $out = implode("\n", array_filter(
        preg_split('/\R/', $sql) ?: [],
        static fn(string $line): bool => !preg_match(
            '/\b(?:DROP|CREATE)\s+DATABASE\b|^\s*(?:\/\*![0-9]*\s*)?USE\s/i',
            $line
        )
    ));

    if (preg_match('/\b(?:DROP|CREATE)\s+DATABASE\b/i', $out)) {
        throw new RuntimeException('a DROP/CREATE DATABASE statement survived filtering — refusing to run');
    }
    return $out;
}

/** Build the scratch database from scratch. Returns a PDO pointed at it. */
function tdb_rebuild(): PDO
{
    tdb_guard(TEST_DB);

    $srv = tdb_server();
    $srv->exec('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
    $srv->exec('CREATE DATABASE `' . TEST_DB . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $srv->exec('USE `' . TEST_DB . '`');

    $schema = (string) file_get_contents(BASE_PATH . '/database/schema_only.sql');
    $srv->exec(tdb_strip_database_statements($schema));
    $srv->exec(file_get_contents(BASE_PATH . '/database/migration_06_election_assurance.sql'));
    $srv->exec(file_get_contents(BASE_PATH . '/database/migration_08_governance.sql'));
    $srv->exec(file_get_contents(BASE_PATH . '/database/migration_09_admin_approvals.sql'));

    return tdb_connect();
}

/** Connection to the scratch database. */
function tdb_connect(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, TEST_DB, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $pdo->exec("SET time_zone = '" . (new DateTimeImmutable('now'))->format('P') . "'");
    return $pdo;
}

/**
 * A known-good starting position: roles, three staff accounts, ten members,
 * one live election with two seats and four candidates, one closed and
 * published election, one upcoming and one draft.
 */
function tdb_seed(PDO $pdo): array
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['election_admin_decisions','election_approval_requests','admin_profiles','user_preferences'] as $table) $pdo->exec('TRUNCATE TABLE ' . $table);
    $pdo->exec('UPDATE governance_config SET required_approvals=1');
    foreach (['vote_integrity','election_integrity','election_reports','notification_reads','security_alert_reviews',
              'votes','ballots','candidates','positions','elections','otp_tokens','audit_logs','member_profile_photo_chunks',
              'login_attempts','blocked_ips','announcements','members','users','roles','settings'] as $t) {
        $pdo->exec('TRUNCATE TABLE `' . $t . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $roles = [
        ['Administrator',    'admin'],
        ['Voter',            'voter'],
    ];
    $rs = $pdo->prepare('INSERT INTO roles (role_name, slug) VALUES (?,?)');
    $roleId = [];
    foreach ($roles as [$n, $s]) {
        $rs->execute([$n, $s]);
        $roleId[$s] = (int) $pdo->lastInsertId();
    }

    $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?),(?,?),(?,?)')
        ->execute(['coop_name', 'Test Cooperative', 'coop_short', 'TESTCOOP', 'support_email', 'help@test.coop']);

    $hash = password_hash('Test@1234', PASSWORD_BCRYPT);
    $mkUser = $pdo->prepare(
        'INSERT INTO users (username, email, password_hash, role_id, status, email_verified_at)
         VALUES (?,?,?,?,?,NOW())'
    );
    $mkMember = $pdo->prepare(
        'INSERT INTO members (user_id, member_no, full_name, membership_status, registered_at)
         VALUES (?,?,?,?,NOW())'
    );

    $ids = [];
    /* Two roles: an administrator and the members who vote. */
    foreach ([['admin', 'admin']] as [$u, $slug]) {
        $mkUser->execute(['t_' . $u, $u . '@test.coop', $hash, $roleId[$slug], 'active']);
        $ids[$u] = (int) $pdo->lastInsertId();
    }

    $ids['voters'] = [];
    for ($i = 1; $i <= 10; $i++) {
        $mkUser->execute(['t_voter' . $i, 'voter' . $i . '@test.coop', $hash, $roleId['voter'], 'active']);
        $uid = (int) $pdo->lastInsertId();
        $mkMember->execute([$uid, sprintf('TEST-%04d', $i), 'Test Member ' . $i, 'active']);
        $ids['voters'][$i] = $uid;
    }

    /* One suspended and one pending member, for the guard tests. */
    $mkUser->execute(['t_suspended', 'susp@test.coop', $hash, $roleId['voter'], 'suspended']);
    $ids['suspended'] = (int) $pdo->lastInsertId();
    $mkMember->execute([$ids['suspended'], 'TEST-0090', 'Suspended Member', 'suspended']);

    $mkUser->execute(['t_pending', 'pend@test.coop', $hash, $roleId['voter'], 'pending_approval']);
    $ids['pending'] = (int) $pdo->lastInsertId();
    $mkMember->execute([$ids['pending'], 'TEST-0091', 'Pending Member', 'pending']);

    /* ── Elections ── */
    $mkElection = $pdo->prepare(
        'INSERT INTO elections (title, description, start_datetime, end_datetime, status, created_by)
         VALUES (?,?,?,?,?,?)'
    );

    // Live: started an hour ago, ends in a day.
    $mkElection->execute(['Live Election', 'open for voting',
        date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 86400),
        'published', $ids['admin']]);
    $ids['live'] = (int) $pdo->lastInsertId();

    // Closed, with results released.
    $mkElection->execute(['Closed Election', null,
        date('Y-m-d H:i:s', time() - 172800), date('Y-m-d H:i:s', time() - 86400),
        'published', $ids['admin']]);
    $ids['closed'] = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE elections SET results_released_at = NOW() WHERE election_id = ?')
        ->execute([$ids['closed']]);

    // Closed, results NOT yet released.
    $mkElection->execute(['Unreleased Election', null,
        date('Y-m-d H:i:s', time() - 172800), date('Y-m-d H:i:s', time() - 86400),
        'published', $ids['admin']]);
    $ids['unreleased'] = (int) $pdo->lastInsertId();

    // Upcoming.
    $mkElection->execute(['Upcoming Election', null,
        date('Y-m-d H:i:s', time() + 86400), date('Y-m-d H:i:s', time() + 172800),
        'published', $ids['admin']]);
    $ids['upcoming'] = (int) $pdo->lastInsertId();

    // Draft.
    $mkElection->execute(['Draft Election', null,
        date('Y-m-d H:i:s', time() + 86400), date('Y-m-d H:i:s', time() + 172800),
        'draft', $ids['admin']]);
    $ids['draft'] = (int) $pdo->lastInsertId();

    /* ── Positions and candidates ── */
    $mkPos  = $pdo->prepare(
        'INSERT INTO positions (election_id, position_name, description, max_vote, sort_order) VALUES (?,?,?,1,?)'
    );
    $mkCand = $pdo->prepare(
        'INSERT INTO candidates (election_id, position_id, member_id, platform, bio, sort_order, is_active)
         VALUES (?,?,?,?,?,?,1)'
    );

    /*
     * A candidate is a member who stands for a seat, so each one needs a
     * members row. The unique key is (member_id, election_id), which lets
     * the same four people stand again in a later election.
     */
    $standing = [];
    foreach (['Chairperson', 'Treasurer'] as $pname) {
        for ($k = 1; $k <= 2; $k++) {
            $slug = strtolower(substr($pname, 0, 5)) . $k;
            $mkUser->execute(['t_cand_' . $slug, 'cand_' . $slug . '@test.coop', $hash, $roleId['voter'], 'active']);
            $uid = (int) $pdo->lastInsertId();
            $mkMember->execute([$uid, 'TEST-C' . strtoupper($slug), $pname . ' Candidate ' . $k, 'active']);
            $standing[$pname][$k] = ['user' => $uid, 'member' => (int) $pdo->lastInsertId()];
        }
    }
    $ids['standing'] = $standing;

    $ids['positions']  = [];
    $ids['candidates'] = [];
    foreach ([$ids['live'], $ids['closed'], $ids['unreleased']] as $eid) {
        foreach (['Chairperson', 'Treasurer'] as $n => $pname) {
            $mkPos->execute([$eid, $pname, null, $n + 1]);
            $pid = (int) $pdo->lastInsertId();
            $ids['positions'][$eid][$pname] = $pid;

            for ($k = 1; $k <= 2; $k++) {
                $mkCand->execute([$eid, $pid, $standing[$pname][$k]['member'], 'platform text', 'bio text', $k]);
                $ids['candidates'][$eid][$pname][$k] = (int) $pdo->lastInsertId();
            }
        }
    }

    /* Voters 1–3 already voted in the closed election, so results exist. */
    $mkBallot = $pdo->prepare(
        'INSERT INTO ballots (election_id, user_id, has_voted, receipt_code, voted_at)
         VALUES (?,?,1,?,NOW())'
    );
    $mkVote = $pdo->prepare(
        'INSERT INTO votes (election_id, position_id, candidate_id, cast_date) VALUES (?,?,?,CURDATE())'
    );
    for ($i = 1; $i <= 3; $i++) {
        $mkBallot->execute([$ids['closed'], $ids['voters'][$i], 'CV-TEST' . $i . '000']);
        foreach (['Chairperson', 'Treasurer'] as $pname) {
            $mkVote->execute([
                $ids['closed'],
                $ids['positions'][$ids['closed']][$pname],
                $ids['candidates'][$ids['closed']][$pname][$i === 3 ? 2 : 1],
            ]);
        }
    }

    foreach ($pdo->query('SELECT election_id FROM elections')->fetchAll(PDO::FETCH_COLUMN) as $eid) {
        integrity_baseline($pdo, (int) $eid);
    }
    return $ids;
}

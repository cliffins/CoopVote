<?php
/**
 * 2 — COMPONENT / MODULE TESTING
 *
 * One module at a time, against a real database but with no HTTP layer:
 * the authentication module, the one-time-code module, the audit module
 * and the election/ballot query module.
 */

declare(strict_types=1);

return static function (Suite $t, PDO $pdo, array $fx): void {

    /* ── Authentication module ─────────────────────────────── */
    $t->group('Authentication — auth_attempt()');

    $r = auth_attempt('t_voter1', 'Test@1234');
    $t->same('correct password signs the member in', true, $r['ok']);
    $t->same('the right account comes back', 't_voter1', $r['user']['username'] ?? null);
    $t->same('the role slug travels with it', 'voter', $r['user']['role_slug'] ?? null);

    $r = auth_attempt('voter1@test.coop', 'Test@1234');
    $t->same('an email address works as the identifier', true, $r['ok']);

    $r = auth_attempt('TEST-0001', 'Test@1234');
    $t->same('a member number works as the identifier', true, $r['ok']);

    $r = auth_attempt('t_voter1', 'wrong-password');
    $t->same('a wrong password is refused', false, $r['ok']);
    $t->contains('the refusal counts down the attempts', 'attempt(s) remaining', $r['error']);

    $r = auth_attempt('nobody_at_all', 'whatever');
    $t->same('an unknown account is refused', false, $r['ok']);
    $t->same('an unknown account gives nothing away', 'Invalid credentials. Please try again.', $r['error']);

    $t->group('Authentication — account status gates');

    $r = auth_attempt('t_suspended', 'Test@1234');
    $t->same('a suspended account cannot sign in', false, $r['ok']);
    $t->contains('and is told why', 'suspended', $r['error']);

    $r = auth_attempt('t_pending', 'Test@1234');
    $t->same('an unapproved account cannot sign in', false, $r['ok']);
    $t->contains('and is told it is awaiting approval', 'awaiting administrator approval', $r['error']);

    /* ── Lockout escalation ────────────────────────────────── */
    $t->group('Authentication — lockout after repeated failures');

    $pdo->prepare('UPDATE users SET failed_attempts = 0, lockout_count = 0, locked_until = NULL WHERE username = ?')
        ->execute(['t_voter2']);

    $last = [];
    for ($i = 1; $i <= MAX_FAILED_ATTEMPTS; $i++) {
        $last = auth_attempt('t_voter2', 'nope');
    }
    $t->contains('the fifth failure locks the account', 'locked for', strtolower($last['error']));
    $t->contains('the first lockout is the shortest', '15 seconds', $last['error']);

    $row = $pdo->query("SELECT locked_until, failed_attempts, lockout_count FROM users WHERE username = 't_voter2'")->fetch();
    $t->ok('locked_until is set in the future', strtotime((string) $row['locked_until']) > time() - 2, (string) $row['locked_until']);
    $t->same('the failure counter is reset once locked', 0, (int) $row['failed_attempts']);
    $t->same('the lockout counter has advanced', 1, (int) $row['lockout_count']);

    $r = auth_attempt('t_voter2', 'Test@1234');
    $t->same('even the correct password is refused while locked', false, $r['ok']);
    $t->contains('and says how long to wait', 'Try again in', $r['error']);

    /* Second lockout must be longer than the first. */
    $pdo->prepare("UPDATE users SET locked_until = NULL, failed_attempts = 0 WHERE username = 't_voter2'")->execute();
    for ($i = 1; $i <= MAX_FAILED_ATTEMPTS; $i++) {
        $last = auth_attempt('t_voter2', 'nope');
    }
    $t->contains('the second lockout is longer', '30 seconds', $last['error']);

    /* A successful sign-in wipes the slate. */
    $pdo->prepare("UPDATE users SET locked_until = NULL, failed_attempts = 3 WHERE username = 't_voter2'")->execute();
    $r = auth_attempt('t_voter2', 'Test@1234');
    $t->same('signing in successfully works again', true, $r['ok']);
    $row = $pdo->query("SELECT failed_attempts, lockout_count, last_login_at FROM users WHERE username = 't_voter2'")->fetch();
    $t->same('the failure counter is cleared', 0, (int) $row['failed_attempts']);
    $t->same('the escalation is cleared, so an honest typo starts at 15s again', 0, (int) $row['lockout_count']);
    $t->ok('the sign-in is timestamped', $row['last_login_at'] !== null);

    /* ── Password hashing ──────────────────────────────────── */
    $t->group('Passwords are stored as bcrypt hashes');

    $stored = (string) $pdo->query("SELECT password_hash FROM users WHERE username = 't_voter1'")->fetchColumn();
    $t->matches('the hash is a bcrypt hash', '/^\$2y\$\d{2}\$/', $stored);
    $t->ok('the plain password is nowhere in the hash', !str_contains($stored, 'Test@1234'));
    $t->same('password_verify accepts the right password', true, password_verify('Test@1234', $stored));
    $t->same('password_verify rejects a wrong one', false, password_verify('Test@1235', $stored));

    $again = password_hash('Test@1234', PASSWORD_BCRYPT);
    $t->ok('the same password hashes differently each time (salted)', $again !== $stored);

    /* ── One-time codes ────────────────────────────────────── */
    $t->group('One-time codes — otp_issue() / otp_check()');

    $uid  = $fx['voters'][4];
    $code = otp_issue($uid, 'register');

    $t->matches('the code is six digits', '/^\d{6}$/', $code);

    $storedHash = (string) $pdo->query(
        "SELECT code_hash FROM otp_tokens WHERE user_id = $uid ORDER BY otp_id DESC LIMIT 1"
    )->fetchColumn();
    $t->ok('only the hash is stored, never the digits', $storedHash !== $code);
    $t->same('the hash is SHA-256 of the code', hash('sha256', $code), $storedHash);

    $t->same('a wrong code is refused', false, otp_check($uid, '000000', 'register')['ok']);
    $t->same('the right code is accepted', true, otp_check($uid, $code, 'register')['ok']);
    $t->same('the same code cannot be used twice', false, otp_check($uid, $code, 'register')['ok']);

    $t->group('One-time codes — issuing a new one retires the old');

    $uid  = $fx['voters'][5];
    $old  = otp_issue($uid, 'register');
    $new  = otp_issue($uid, 'register');
    $t->same('the superseded code no longer works', false, otp_check($uid, $old, 'register')['ok']);
    $t->same('the newest code works', true, otp_check($uid, $new, 'register')['ok']);
    $t->same('exactly one unused code exists at a time', 0, (int) $pdo->query(
        "SELECT COUNT(*) FROM otp_tokens WHERE user_id = $uid AND is_used = 0"
    )->fetchColumn());

    $t->group('One-time codes — guessing is capped');

    $uid  = $fx['voters'][6];
    $code = otp_issue($uid, 'register');
    for ($i = 0; $i < OTP_MAX_ATTEMPTS; $i++) {
        otp_check($uid, '111111', 'register');
    }
    $res = otp_check($uid, $code, 'register');
    $t->same('the real code stops working after 5 wrong guesses', false, $res['ok']);
    $t->contains('and the member is told to ask for a new one', 'request a new one', $res['error']);

    $t->group('One-time codes — expiry is decided by the database clock');

    $uid  = $fx['voters'][7];
    $code = otp_issue($uid, 'register');
    $pdo->prepare('UPDATE otp_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE user_id = ? AND is_used = 0')
        ->execute([$uid]);
    $res = otp_check($uid, $code, 'register');
    $t->same('an expired code is refused', false, $res['ok']);
    $t->contains('with the expiry as the reason', 'expired', $res['error']);

    /* This is the bug that stranded members mid-registration: the resend
       cooldown kept applying to a code that could no longer be used. */
    $t->same('a dead code lifts the resend cooldown', 0, otp_resend_wait($uid, 'register'));

    $t->group('One-time codes — the resend cooldown still applies to a live code');

    $uid = $fx['voters'][8];
    otp_issue($uid, 'register');
    $wait = otp_resend_wait($uid, 'register');
    $t->ok('a fresh code holds the cooldown', $wait > 0 && $wait <= OTP_RESEND_SECONDS, $wait . 's');
    $t->same('and it reports as usable', true, otp_has_usable($uid, 'register'));

    $pdo->prepare('UPDATE otp_tokens SET attempts = ? WHERE user_id = ? AND is_used = 0')
        ->execute([OTP_MAX_ATTEMPTS, $uid]);
    $t->same('a burnt-out code is not usable', false, otp_has_usable($uid, 'register'));
    $t->same('and so the cooldown lifts', 0, otp_resend_wait($uid, 'register'));

    $t->group('One-time codes — codes are separated by purpose');

    $uid  = $fx['voters'][9];
    $reg  = otp_issue($uid, 'register');
    $rst  = otp_issue($uid, 'reset');
    $t->same('a reset code does not satisfy a registration', false, otp_check($uid, $rst, 'register')['ok']);
    $t->same('the registration code still works', true, otp_check($uid, $reg, 'register')['ok']);

    /* ── Audit module ──────────────────────────────────────── */
    $t->group('Audit trail');

    $before = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    audit($fx['admin'], 'test.action', 'election', 1, 'A description for the trail');
    $after = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    $t->same('one row is written', $before + 1, $after);

    $row = $pdo->query('SELECT * FROM audit_logs ORDER BY log_id DESC LIMIT 1')->fetch();
    $t->same('the action is recorded', 'test.action', $row['action']);
    $t->same('the entity is recorded', 'election', $row['entity_type']);
    $t->ok('the IP address is recorded', $row['ip_address'] !== null);

    $long = str_repeat('x', 400);
    audit($fx['admin'], 'test.long', null, null, $long);
    $len = (int) $pdo->query("SELECT CHAR_LENGTH(description) FROM audit_logs ORDER BY log_id DESC LIMIT 1")->fetchColumn();
    $t->ok('an over-long description is truncated, not rejected', $len <= 255, $len . ' characters');

    /* Auditing must never be the thing that breaks a request. */
    $t->group('Audit failures are swallowed');
    $threw = false;
    try {
        audit(999999999, 'test.bad_fk', null, null, 'user does not exist');
    } catch (Throwable) {
        $threw = true;
    }
    $t->same('a failed audit write does not throw', false, $threw);

    /* ── Election / ballot query module ────────────────────── */
    $t->group('Election queries');

    $live = vt_live_elections();
    $t->same('exactly one election is live', 1, count($live));
    $t->same('and it is the right one', 'Live Election', $live[0]['title'] ?? null);

    $published = vt_published_elections();
    $titles = array_column($published, 'title');
    $t->ok('a draft election is never published', !in_array('Draft Election', $titles, true), implode(', ', $titles));

    $t->same('positions come back for the live election', 2, count(vt_positions($fx['live'])));
    $t->same('candidates come back for the live election', 4, count(vt_candidates($fx['live'])));

    $t->group('Ballot register');

    $t->same('a member who voted is recorded as such', true, vt_has_voted($fx['voters'][1], $fx['closed']));
    $t->same('a member who did not vote is not', false, vt_has_voted($fx['voters'][9], $fx['closed']));
    $t->same('nobody has voted in the live election yet', false, vt_has_voted($fx['voters'][1], $fx['live']));

    $t->same('the turnout count matches the ballots', 3, vt_votes_cast($fx['closed']));
    $t->same('the live election has no turnout yet', 0, vt_votes_cast($fx['live']));

    $t->group('Secret ballot — the schema itself prevents linking');

    $cols = $pdo->query('SHOW COLUMNS FROM votes')->fetchAll(PDO::FETCH_COLUMN);
    $t->ok('the votes table has no user_id column', !in_array('user_id', $cols, true), implode(', ', $cols));
    $t->ok('the votes table has no member_id column', !in_array('member_id', $cols, true));
    $t->ok('the votes table has no ballot_id column', !in_array('ballot_id', $cols, true));
    $t->ok('the votes table stores only a date, not a time', in_array('cast_date', $cols, true) && !in_array('cast_at', $cols, true),
        'a timestamp would let a vote be matched to a ballot by its moment');

    /* ── Cooperative settings ──────────────────────────────── */
    $t->group('Cooperative settings');

    $t->same('a setting is read from the database', 'Test Cooperative', coop_setting('coop_name', 'fallback'));
    $t->same('a missing setting falls back', 'fallback', coop_setting('no_such_setting', 'fallback'));
};

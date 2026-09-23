<?php
/**
 * CoopVote — session authentication, guards and audit logging
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/audit-description.php';
require_once __DIR__ . '/session-storage.php';

const MAX_FAILED_ATTEMPTS = 5;

/**
 * How long each successive lockout lasts, in seconds.
 * First 15s, then 30s, then a minute. Once the list runs out the last
 * value repeats, so a persistent attacker stays on the longest wait.
 */
const LOCKOUT_SECONDS = [15, 30, 60];

/** The wait for the Nth lockout (1-based). */
function lockout_seconds(int $lockoutNumber): int
{
    $steps = LOCKOUT_SECONDS;
    $i     = max(1, $lockoutNumber) - 1;
    return $steps[min($i, count($steps) - 1)];
}

/** "15 seconds" / "1 minute" — for messages people actually read. */
function format_wait(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    }
    $m = (int) round($seconds / 60);
    return $m . ' minute' . ($m === 1 ? '' : 's');
}

/** Start the session once, with hardened cookie flags. */
function auth_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ensure_session_storage();
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    if (!@session_start()) throw new RuntimeException('SESSION_STORAGE_UNAVAILABLE');
}

/**
 * The signed-in user as a row joined with their role + member record,
 * or null when nobody is signed in.
 */
function current_user(): ?array
{
    auth_boot();

    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) {
        return null;
    }

    static $cache = null;
    if ($cache !== null && (int) $cache['user_id'] === (int) $uid) {
        return $cache;
    }

    $stmt = db()->prepare(
        'SELECT u.*, r.slug AS role_slug, r.role_name,
                m.member_id, m.member_no, m.full_name, m.contact_no, m.address,
                m.date_of_birth, m.occupation, m.years_in_coop, m.photo_url,
                m.membership_status, m.registered_at
           FROM users u
           JOIN roles   r ON r.role_id = u.role_id
           LEFT JOIN members m ON m.user_id = u.user_id
          WHERE u.user_id = ?
          LIMIT 1'
    );
    $stmt->execute([$uid]);
    $row = $stmt->fetch();

    if (!$row) {
        // Stale session pointing at a deleted user.
        auth_logout();
        return null;
    }

    return $cache = $row;
}

/** Stop with 401 unless somebody is signed in. Returns the user. */
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        json_error('You are not signed in.', 401, ['code' => 'UNAUTHENTICATED']);
    }
    if ($u['status'] !== 'active') {
        json_error('This account is ' . $u['status'] . '.', 403, ['code' => 'INACTIVE']);
    }
    return $u;
}

/** Stop with 403 unless the signed-in user holds one of these role slugs. */
function require_role(string ...$slugs): array
{
    $u = require_login();
    if (!in_array($u['role_slug'], $slugs, true)) {
        json_error('Your role does not have access to this resource.', 403, ['code' => 'FORBIDDEN']);
    }
    return $u;
}

/** The back office, which is to say an administrator. */
function require_admin(): array
{
    return require_role(...ADMIN_ROLES);
}

/** Roles allowed to modify data. See WRITE_ROLES. */
function require_writer(): array
{
    return require_role(...WRITE_ROLES);
}

/** A cooperative member casting votes. */
function require_voter(): array
{
    return require_role('voter');
}

/* ── One-time email codes ────────────────────────────────── */

const OTP_LENGTH          = 6;
const OTP_TTL_MINUTES     = 10;
const OTP_MAX_ATTEMPTS    = 5;    // wrong guesses before the code dies
const OTP_RESEND_SECONDS  = 60;   // wait between "send it again" requests

/**
 * Issue a fresh code and return the plain digits (the caller emails them).
 * Only the SHA-256 hash is stored, so a leaked database still cannot be
 * used to walk into somebody's registration.
 */
function otp_issue(int $userId, string $purpose = 'register'): string
{
    $code = str_pad((string) random_int(0, 10 ** OTP_LENGTH - 1), OTP_LENGTH, '0', STR_PAD_LEFT);
    $pdo  = db();

    /*
     * Retiring the old codes and inserting the new one has to be one step.
     * Done separately, a failed insert left the member with every code
     * retired and none issued — no code could ever work again, and the
     * resend cooldown kept them from asking for another.
     */
    $pdo->beginTransaction();
    try {
        /* Any earlier code for this purpose stops working the moment a new
           one is issued, so two live codes can never be valid at once. */
        $pdo->prepare(
            'UPDATE otp_tokens SET is_used = 1 WHERE user_id = ? AND purpose = ? AND is_used = 0'
        )->execute([$userId, $purpose]);

        $pdo->prepare(
            'INSERT INTO otp_tokens (user_id, purpose, channel, code_hash, expires_at)
             VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
        )->execute([$userId, $purpose, 'email', hash('sha256', $code), OTP_TTL_MINUTES]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $code;
}

/**
 * Is there a code this member could still successfully enter?
 *
 * When there is not, the resend cooldown must not apply: refusing to send
 * a new code while the old one is dead is what left people stuck, being
 * told to request a new code and then being refused one.
 */
function otp_has_usable(int $userId, string $purpose = 'register'): bool
{
    $s = db()->prepare(
        'SELECT 1 FROM otp_tokens
          WHERE user_id = ? AND purpose = ? AND is_used = 0
            AND expires_at > NOW() AND attempts < ?
          LIMIT 1'
    );
    $s->execute([$userId, $purpose, OTP_MAX_ATTEMPTS]);

    return (bool) $s->fetchColumn();
}

/** Seconds until another code may be requested; 0 when it is allowed now. */
function otp_resend_wait(int $userId, string $purpose = 'register'): int
{
    /* The cooldown only exists to stop the same address being mailed over
       and over. Once the current code can no longer be used there is
       nothing to protect, so the member may ask for another immediately. */
    if (!otp_has_usable($userId, $purpose)) {
        return 0;
    }

    $s = db()->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
           FROM otp_tokens WHERE user_id = ? AND purpose = ?
          ORDER BY otp_id DESC LIMIT 1'
    );
    $s->execute([$userId, $purpose]);
    $age = $s->fetchColumn();

    return $age === false ? 0 : max(0, OTP_RESEND_SECONDS - (int) $age);
}

/**
 * Check a code. Returns [ok => bool, error => string].
 * Counts wrong guesses so a six-digit code cannot simply be enumerated.
 */
function otp_check(int $userId, string $code, string $purpose = 'register'): array
{
    $code = preg_replace('/\D/', '', $code);

    /* Whether the code has expired is decided by the database, which is
       also what wrote expires_at. Comparing a MySQL timestamp against
       PHP's clock made a fresh code look expired whenever the two were on
       different time zones. */
    $s = db()->prepare(
        'SELECT *, (expires_at < NOW()) AS is_expired
           FROM otp_tokens
          WHERE user_id = ? AND purpose = ? AND is_used = 0
          ORDER BY otp_id DESC LIMIT 1'
    );
    $s->execute([$userId, $purpose]);
    $row = $s->fetch();

    if (!$row) {
        return ['ok' => false, 'error' => 'That code is no longer valid. Please request a new one.'];
    }

    if ((int) $row['is_expired'] === 1) {
        return ['ok' => false, 'error' => 'That code has expired. Please request a new one.'];
    }

    if ((int) $row['attempts'] >= OTP_MAX_ATTEMPTS) {
        db()->prepare('UPDATE otp_tokens SET is_used = 1 WHERE otp_id = ?')->execute([$row['otp_id']]);
        return ['ok' => false, 'error' => 'Too many incorrect codes. Please request a new one.'];
    }

    if (!hash_equals((string) $row['code_hash'], hash('sha256', (string) $code))) {
        db()->prepare('UPDATE otp_tokens SET attempts = attempts + 1 WHERE otp_id = ?')
            ->execute([$row['otp_id']]);

        $left = OTP_MAX_ATTEMPTS - ((int) $row['attempts'] + 1);
        return [
            'ok'    => false,
            'error' => $left > 0
                ? "That code is incorrect. {$left} attempt(s) remaining."
                : 'Too many incorrect codes. Please request a new one.',
        ];
    }

    db()->prepare('UPDATE otp_tokens SET is_used = 1 WHERE otp_id = ?')->execute([$row['otp_id']]);
    return ['ok' => true];
}

/** "j•••@gmail.com" — confirms the address without printing it in full. */
function mask_email(string $email): string
{
    [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') return $email;

    $head = mb_substr($user, 0, 1);
    $tail = mb_strlen($user) > 3 ? mb_substr($user, -1) : '';
    return $head . str_repeat('•', max(2, mb_strlen($user) - 2)) . $tail . '@' . $domain;
}

/** Write one row to audit_logs. Never throws — logging must not break a request. */
function audit(
    ?int   $userId,
    string $action,
    ?string $entityType = null,
    ?int   $entityId = null,
    ?string $description = null
): void {
    try {
        db()->prepare(
            'INSERT INTO audit_logs
                (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $description !== null ? mb_strcut($description, 0, 255, 'UTF-8') : null,
            client_ip(),
            user_agent(),
        ]);
    } catch (Throwable) {
        // swallow — auditing is best-effort
    }
}

/** Record a login attempt for the security dashboard. */
function record_login_attempt(string $identifier, bool $success): void
{
    try {
        db()->prepare(
            'INSERT INTO login_attempts (identifier, ip_address, user_agent, success)
             VALUES (?,?,?,?)'
        )->execute([substr($identifier, 0, 120), client_ip(), user_agent(), $success ? 1 : 0]);
    } catch (Throwable) {
        // best-effort
    }
}

/** True when this IP is on the block list. */
function is_ip_blocked(?string $ip = null): bool
{
    $ip = $ip ?? client_ip();
    try {
        $s = db()->prepare('SELECT 1 FROM blocked_ips WHERE ip_address = ? LIMIT 1');
        $s->execute([$ip]);
        return (bool) $s->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/**
 * Verify credentials and open a session.
 * Returns [ok => bool, user|error => …] rather than throwing, so the
 * caller controls the audit trail.
 */
function auth_attempt(string $identifier, string $password): array
{
    auth_boot();

    /*
     * Accept username, email address or cooperative member number.
     * Each placeholder is distinct: native prepared statements (emulation
     * is off) will not let one named parameter be reused.
     */
    $stmt = db()->prepare(
        'SELECT u.*, r.slug AS role_slug, r.role_name
           FROM users u
           JOIN roles r ON r.role_id = u.role_id
           LEFT JOIN members m ON m.user_id = u.user_id
          WHERE u.username = :id1 OR u.email = :id2 OR m.member_no = :id3
          LIMIT 1'
    );
    $stmt->execute([':id1' => $identifier, ':id2' => $identifier, ':id3' => $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['ok' => false, 'error' => 'Invalid credentials. Please try again.'];
    }

    /* Temporary lockout after repeated failures. */
    if ($user['locked_until'] && strtotime((string) $user['locked_until']) > time()) {
        $left = max(1, strtotime((string) $user['locked_until']) - time());
        return [
            'ok'    => false,
            'user'  => $user,
            'error' => 'Too many failed attempts. Try again in ' . format_wait($left) . '.',
        ];
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = (int) $user['failed_attempts'] + 1;

        if ($attempts >= MAX_FAILED_ATTEMPTS) {
            /*
             * Each lockout on the same account is longer than the last.
             * The counter resets on a successful sign-in, so somebody who
             * simply mistypes now and then never builds up a long wait.
             */
            $lockoutNo = (int) ($user['lockout_count'] ?? 0) + 1;
            $wait      = lockout_seconds($lockoutNo);

            db()->prepare(
                'UPDATE users
                    SET failed_attempts = 0,
                        lockout_count   = ?,
                        locked_until    = DATE_ADD(NOW(), INTERVAL ? SECOND)
                  WHERE user_id = ?'
            )->execute([$lockoutNo, $wait, $user['user_id']]);

            audit((int) $user['user_id'], 'auth.account_locked', 'user', (int) $user['user_id'],
                'Locked for ' . format_wait($wait) . ' after ' . MAX_FAILED_ATTEMPTS
                . ' failed attempts (lockout #' . $lockoutNo . ')');

            return [
                'ok'    => false,
                'user'  => $user,
                'error' => 'Too many failed attempts. This account is locked for ' . format_wait($wait) . '.',
            ];
        }

        db()->prepare('UPDATE users SET failed_attempts = ? WHERE user_id = ?')
            ->execute([$attempts, $user['user_id']]);

        $left = MAX_FAILED_ATTEMPTS - $attempts;
        return [
            'ok'    => false,
            'user'  => $user,
            'error' => "Invalid credentials. {$left} attempt(s) remaining.",
        ];
    }

    /*
     * Password is correct but the email was never verified. Rather than a
     * dead end, say so distinctly so the sign-in page can offer the code
     * step — otherwise somebody who lost their first code can never get in,
     * and cannot register again either because the username is taken.
     */
    if ($user['status'] === 'pending_verification') {
        return ['ok' => false, 'user' => $user, 'needsVerification' => true,
                'error' => 'Your email address has not been verified yet.'];
    }

    /* Password is correct — now check the account is usable. */
    $blocked = [
        'pending_approval'     => 'Your membership is still awaiting administrator approval.',
        'suspended'            => 'This account has been suspended. Please contact the cooperative office.',
        'deactivated'          => 'This account has been deactivated.',
        'rejected'             => 'This registration was rejected. Please contact the cooperative office.',
    ];
    if (isset($blocked[$user['status']])) {
        return ['ok' => false, 'user' => $user, 'error' => $blocked[$user['status']]];
    }

    /* Success — reset counters, stamp the login, rotate the session id. */
    /* lockout_count resets too: getting in proves this is the real member,
       so the next mistyped password starts again at the shortest wait. */
    db()->prepare(
        'UPDATE users
            SET failed_attempts = 0, lockout_count = 0, locked_until = NULL, last_login_at = NOW()
          WHERE user_id = ?'
    )->execute([$user['user_id']]);

    session_regenerate_id(true);
    $_SESSION['user_id']    = (int) $user['user_id'];
    $_SESSION['role_slug']  = $user['role_slug'];
    $_SESSION['login_time'] = time();

    return ['ok' => true, 'user' => $user];
}

/** Destroy the session completely. */
function auth_logout(): void
{
    auth_boot();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Map a role slug to the landing page the UI expects. */
function role_home(string $slug): string
{
    return $slug === 'voter' ? 'voter/dashboard.html' : 'admin/dashboard.html';
}

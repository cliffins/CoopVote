<?php
/**
 * CoopVote — authentication routes
 *   POST auth/login     POST auth/logout
 *   GET  auth/me        POST auth/register
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/validation.php';

function handle_auth(array $args): never
{
    $action = $args[0] ?? '';

    /*
     * Members no longer sign themselves up: the cooperative office creates
     * the account. Taking the page away is not enough on its own — these
     * endpoints would still accept a hand-made request and mint an account
     * nobody approved, so they are closed here too.
     *
     * The handlers are left in place. Should the cooperative ever go back
     * to self-registration, restoring it is a matter of re-listing them.
     */
    $selfSignup = ['register', 'verify-otp', 'resend-otp'];
    if (in_array($action, $selfSignup, true)) {
        json_error(
            'Accounts are created by the cooperative office. Please contact them to be registered.',
            403,
            ['code' => 'SELF_REGISTRATION_CLOSED']
        );
    }

    match ($action) {
        'login'          => auth_route_login(),
        'logout'         => auth_route_logout(),
        'me'             => auth_route_me(),
        /* Used by the "add member" form to warn about clashes. */
        'check-username' => auth_route_check_username(),
        default          => json_error('That page or action does not exist.', 404, ['code' => 'UNKNOWN_ROUTE', 'endpoint' => $action]),
    };
}

/* ── POST auth/login ────────────────────────────────────── */
function auth_route_login(): never
{
    require_method('POST');

    $identifier = input_str('username') ?: input_str('identifier') ?: input_str('email');
    $password   = input_scalar('password');

    if ($identifier === '') json_error('Please enter your username, email or member ID.');
    if (blank_form_value($password)) json_error('Please enter your password.');

    $result = auth_attempt($identifier, $password);

    /*
     * Right password, unverified address: send a fresh code and let the page
     * finish the job. Without this an account whose first code went missing
     * is unreachable — it cannot sign in and cannot register again either.
     */
    if (!$result['ok'] && !empty($result['needsVerification'])) {
        $user   = $result['user'];
        $userId = (int) $user['user_id'];

        require_once BASE_PATH . '/includes/mailer.php';

        /* Respect the cooldown so repeated sign-in attempts cannot be used
           to flood the address with codes. */
        $name = (string) ($user['username'] ?? '');
        $sent = false;
        if (otp_resend_wait($userId, 'register') === 0) {
            $code = otp_issue($userId, 'register');
            $sent = mail_otp_code((string) $user['email'], $name, $code, OTP_TTL_MINUTES);
        }

        auth_boot();
        $_SESSION['pending_verify_user'] = $userId;

        json_out([
            'ok'                => false,
            'needsVerification' => true,
            'error'             => 'Please verify your email address to finish setting up your account.',
            'email'             => mask_email((string) $user['email']),
            'expiresInMinutes'  => OTP_TTL_MINUTES,
            'mailSent'          => $sent,
        ], 403);
    }

    if (!$result['ok']) {
        record_login_attempt($identifier, false);
        audit(
            isset($result['user']) ? (int) $result['user']['user_id'] : null,
            'auth.login_failed',
            'user',
            isset($result['user']) ? (int) $result['user']['user_id'] : null,
            'Sign-in refused for "' . $identifier . '"'
        );
        json_error($result['error'], 401);
    }

    $user = $result['user'];
    record_login_attempt($identifier, true);
    audit((int) $user['user_id'], 'auth.login', 'user', (int) $user['user_id'],
        'Signed in successfully as ' . $user['role_name']);

    $isVoter = $user['role_slug'] === 'voter';

    json_out([
        'ok'       => true,
        /* login.html branches on this exact value */
        'role'     => $isVoter ? 'member' : 'admin',
        'roleSlug' => $user['role_slug'],
        'roleName' => $user['role_name'],
        'redirect' => role_home($user['role_slug']),
        'user'     => [
            'id'       => (int) $user['user_id'],
            'username' => $user['username'],
            'email'    => $user['email'],
        ],
    ]);
}

/* ── POST auth/logout ───────────────────────────────────── */
function auth_route_logout(): never
{
    $u = current_user();
    if ($u) {
        audit((int) $u['user_id'], 'auth.logout', 'user', (int) $u['user_id'], 'Signed out');
    }
    auth_logout();
    json_out(['ok' => true]);
}

/* ── GET auth/me ────────────────────────────────────────── */
function auth_route_me(): never
{
    $u = current_user();
    if (!$u) {
        json_error('Not signed in.', 401, ['code' => 'UNAUTHENTICATED']);
    }

    $name = (string) ($u['full_name'] ?? $u['username']);

    json_out([
        'ok'   => true,
        'user' => [
            'id'       => (int) $u['user_id'],
            'username' => $u['username'],
            'email'    => $u['email'],
            'name'     => $name,
            'memberId' => $u['member_no'] ?? '—',
            'roleSlug' => $u['role_slug'],
            'roleName' => $u['role_name'],
            'role'     => $u['role_slug'] === 'voter' ? 'member' : 'admin',
            'status'   => $u['status'],
            'canWrite' => in_array($u['role_slug'], WRITE_ROLES, true),
            'home'     => role_home($u['role_slug']),
        ],
        /* The cooperative's own details, so pages can name it rather than
           showing the product name everywhere. */
        'coop' => [
            'name'         => coop_setting('coop_name', 'Santiago City Multi-Purpose Cooperative'),
            'short'        => coop_setting('coop_short', ''),
            'supportEmail' => coop_setting('support_email', ''),
        ],
    ]);
}

/* ── POST auth/check-username ───────────────────────────── */
function auth_route_check_username(): never
{
    require_method('POST');
    /* Only the office asks this now, and answering it for anyone would
       let a stranger work out who holds an account. */
    require_admin();

    $username = input_str('username');
    if ($username === '') json_error('No username supplied.');

    $s = db()->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
    $s->execute([$username]);

    json_out(['ok' => true, 'available' => !$s->fetchColumn()]);
}

/* ── POST auth/register ─────────────────────────────────── */
/**
 * @retained Self-registration is closed — handle_auth() refuses this
 * route with 403 before it can be reached. The handler is kept whole
 * so the cooperative can reopen registration by re-listing the route,
 * rather than having it rewritten from memory later.
 */
function auth_store_member_id_photos(PDO $pdo, int $userId, int $memberId): void
{
    $paths = [];
    foreach (['idFront' => 'id_front_url', 'idBack' => 'id_back_url'] as $field => $column) {
        $stored = store_id_upload($field, $userId);
        if ($stored !== null) $paths[$column] = $stored;
    }
    if ($paths) {
        $sets = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($paths)));
        $pdo->prepare("UPDATE members SET $sets WHERE member_id = ?")
            ->execute([...array_values($paths), $memberId]);
    }
}

/** @retained Unrouted self-registration handler, kept for compatibility with older installations. */
function auth_route_register(): never
{
    require_method('POST');

    /* Multipart (with ID photos) populates $_POST; JSON populates body(). */
    $src = !empty($_POST) ? $_POST : body();
    $get = static fn(string $k): string => trim((string) ($src[$k] ?? ''));

    $firstName = $get('firstName') ?: $get('first');
    $lastName  = $get('lastName')  ?: $get('last');
    $username  = $get('username');
    $email     = strtolower($get('email'));
    $password  = (string) ($src['password'] ?? '');
    $confirm   = (string) ($src['confirmPassword'] ?? $src['confirm'] ?? '');
    $phone     = $get('phone')   ?: $get('contactNo');
    $address   = $get('address');
    $dob       = $get('dob')     ?: $get('dateOfBirth');

    /* ── Validation ── */
    $errors = [];
    foreach (person_fields_errors($firstName, $lastName, $phone) as $field => $error) {
        $key = ['first' => 'firstName', 'last' => 'lastName'][$field] ?? $field;
        $errors[$key] = $error;
    }
    if ($firstName === '')                      $errors['firstName'] = 'First name is required.';
    if ($lastName === '')                       $errors['lastName']  = 'Last name is required.';
    if ($username === '')                       $errors['username']  = 'Username is required.';
    elseif (!preg_match('/^[A-Za-z0-9._-]{4,50}$/', $username))
        $errors['username'] = 'Use 4–50 letters, numbers, dot, dash or underscore.';
    if ($email === '')                          $errors['email'] = 'Email address is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors['email'] = 'Please enter a valid email address.';
    if (strlen($password) < 8)                  $errors['password'] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)                 $errors['confirmPassword'] = 'Passwords do not match.';

    if ($dob !== '') {
        $age = age_from_dob($dob);
        if ($age === null)  $errors['dob'] = 'Please enter a valid date of birth.';
        elseif ($age < 18)  $errors['dob'] = 'Members must be at least 18 years old.';
    }

    /*
     * ID photographs are mandatory: an administrator approves a membership
     * by checking the ID against the details given here, so an application
     * without one cannot be verified at all. Enforced server-side because
     * the browser check alone can be bypassed.
     */
    foreach (['idFront' => 'front', 'idBack' => 'back'] as $field => $side) {
        $file = $_FILES[$field] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[$field] = 'A photo of the ' . $side . ' of your ID is required.';
        }
    }

    if ($errors) {
        json_error('Please correct the highlighted fields.', 422, ['fields' => $errors]);
    }

    $pdo = db();

    /* ── Uniqueness ── */
    $s = $pdo->prepare('SELECT username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
    $s->execute([$username, $email]);
    if ($clash = $s->fetch()) {
        $field = strcasecmp($clash['username'], $username) === 0 ? 'username' : 'email';
        json_error(
            $field === 'username' ? 'That username is already taken.' : 'That email address is already registered.',
            409,
            ['fields' => [$field => 'Already in use.']]
        );
    }

    /* ── Create the account ── */
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, role_id, status)
             VALUES (?,?,?,(SELECT role_id FROM roles WHERE slug = ?),?)'
        )->execute([
            $username,
            $email,
            password_hash($password, PASSWORD_BCRYPT),
            'voter',
            /* Not a real applicant yet: the email address has to be proven
               first. Only after the code is entered does this become
               pending_approval and reach the administrator's queue. */
            'pending_verification',
        ]);

        $userId = (int) $pdo->lastInsertId();

        /* Sequential cooperative member number: COOP-0001, COOP-0002, … */
        $next = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(member_no, 6) AS UNSIGNED)), 0) + 1
               FROM members WHERE member_no LIKE 'COOP-%'"
        )->fetchColumn();
        $memberNo = sprintf('COOP-%04d', $next);

        $pdo->prepare(
            'INSERT INTO members
                (user_id, member_no, full_name, date_of_birth, contact_no, address, membership_status)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $userId,
            $memberNo,
            $firstName . ' ' . $lastName,
            $dob !== '' ? $dob : null,
            $phone !== '' ? $phone : null,
            $address !== '' ? $address : null,
            'pending',
        ]);

        $memberId = (int) $pdo->lastInsertId();

        /* ── ID photographs (required, validated above) ── */
        auth_store_member_id_photos($pdo, $userId, $memberId);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    audit($userId, 'auth.register', 'user', $userId,
        'Registered as "' . $username . '" (' . $memberNo . ') — awaiting email verification');

    /* Send the verification code and remember who is verifying. The id is
       held in the session, never taken from the request, so nobody can
       point the verify step at somebody else's account. */
    require_once BASE_PATH . '/includes/mailer.php';
    $code = otp_issue($userId, 'register');
    $sent = mail_otp_code($email, $firstName . ' ' . $lastName, $code, OTP_TTL_MINUTES);

    auth_boot();
    $_SESSION['pending_verify_user'] = $userId;

    json_out([
        'ok'                => true,
        'needsVerification' => true,
        'message'           => 'We sent a verification code to your email address.',
        'email'             => mask_email($email),
        'memberNo'          => $memberNo,
        'expiresInMinutes'  => OTP_TTL_MINUTES,
        'status'            => 'pending_verification',
        /* Tells the page to explain why no code will arrive. */
        'mailSent'          => $sent,
    ], 201);
}

/* ── POST auth/verify-otp ───────────────────────────────── */
/** @retained Part of the closed self-registration flow; see above. */
function auth_route_verify_otp(): never
{
    require_method('POST');
    auth_boot();

    $userId = (int) ($_SESSION['pending_verify_user'] ?? 0);
    if (!$userId) {
        json_error('Your registration session has ended. Please register again.', 409,
            ['code' => 'NO_PENDING_REGISTRATION']);
    }

    $code = input_str('code');
    if ($code === '') json_error('Please enter the code from your email.');

    $result = otp_check($userId, $code, 'register');
    if (!$result['ok']) {
        audit($userId, 'auth.otp_failed', 'user', $userId, 'Wrong registration code entered');
        json_error($result['error'], 422);
    }

    /* Verified — the application now reaches the administrator's queue. */
    db()->prepare("UPDATE users SET status = 'pending_approval' WHERE user_id = ?")
        ->execute([$userId]);

    $s = db()->prepare(
        'SELECT u.email, m.full_name, m.member_no
           FROM users u LEFT JOIN members m ON m.user_id = u.user_id
          WHERE u.user_id = ? LIMIT 1'
    );
    $s->execute([$userId]);
    $who = $s->fetch() ?: ['email' => '', 'full_name' => '', 'member_no' => '—'];

    audit($userId, 'auth.email_verified', 'user', $userId, 'Email address verified');

    unset($_SESSION['pending_verify_user']);

    /* The confirmation email is sent after the response — the applicant
       should see "Registration Received" the moment their code is
       accepted, not five seconds later when Gmail replies. */
    $email = (string) $who['email'];
    $name  = (string) $who['full_name'];
    $no    = (string) $who['member_no'];

    respond_then(
        [
            'ok'       => true,
            'message'  => 'Email verified. An administrator will review your membership shortly.',
            'memberNo' => $no,
            'status'   => 'pending_approval',
        ],
        static function () use ($email, $name, $no): void {
            require_once BASE_PATH . '/includes/mailer.php';
            mail_registration_received($email, $name, $no);
        }
    );
}

/* ── POST auth/resend-otp ───────────────────────────────── */
/** @retained Part of the closed self-registration flow; see above. */
function auth_route_resend_otp(): never
{
    require_method('POST');
    auth_boot();

    $userId = (int) ($_SESSION['pending_verify_user'] ?? 0);
    if (!$userId) {
        json_error('Your registration session has ended. Please register again.', 409,
            ['code' => 'NO_PENDING_REGISTRATION']);
    }

    /* A cooldown keeps this from becoming a way to spam an address. */
    $wait = otp_resend_wait($userId, 'register');
    if ($wait > 0) {
        json_error("Please wait {$wait} more second(s) before asking for another code.", 429,
            ['retryAfter' => $wait]);
    }

    $s = db()->prepare(
        'SELECT u.email, m.full_name FROM users u
           LEFT JOIN members m ON m.user_id = u.user_id
          WHERE u.user_id = ? LIMIT 1'
    );
    $s->execute([$userId]);
    $who = $s->fetch();
    if (!$who) {
        json_error('That registration no longer exists.', 404);
    }

    require_once BASE_PATH . '/includes/mailer.php';
    $code = otp_issue($userId, 'register');
    $sent = mail_otp_code((string) $who['email'], (string) $who['full_name'], $code, OTP_TTL_MINUTES);

    audit($userId, 'auth.otp_resent', 'user', $userId, 'New registration code requested');

    json_out([
        'ok'               => true,
        'message'          => 'A new code is on its way.',
        'email'            => mask_email((string) $who['email']),
        'expiresInMinutes' => OTP_TTL_MINUTES,
        'mailSent'         => $sent,
    ]);
}

/**
 * Validate and move one uploaded ID photo into /uploads/ids.
 * Returns the web-relative path, or null when nothing was uploaded.
 */
function store_id_upload(string $field, int $userId): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_error('Upload failed for ' . $field . ' (error code ' . $file['error'] . ').', 400);
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        json_error('Each ID photo must be smaller than 5 MB.', 413);
    }

    /* Trust the sniffed type, not the browser-supplied one. */
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime    = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';

    if (!isset($allowed[$mime])) {
        json_error('ID photos must be JPG, PNG or WebP images.', 415);
    }

    $dir = UPLOAD_PATH . DIRECTORY_SEPARATOR . 'ids';
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        json_error('Could not create the upload directory.', 500);
    }

    $side = str_contains(strtolower($field), 'back') ? 'back' : 'front';
    $name = sprintf('u%d_%s_%s.%s', $userId, $side, bin2hex(random_bytes(4)), $allowed[$mime]);

    if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) {
        json_error('Could not save the uploaded ID photo.', 500);
    }

    return 'uploads/ids/' . $name;
}

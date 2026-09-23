<?php
/**
 * CoopVote — back-office routes
 *   dashboard  voters  candidates  elections
 *   monitor    results reports     security  announcements
 *
 * Read and write access: admin. There are two roles on this system, and
 * the other one votes.
 */

declare(strict_types=1);

require_once __DIR__ . '/voter.php';   // reuse the tally/grouping helpers
require_once __DIR__ . '/../../includes/election-operations.php';
require_once __DIR__ . '/../../includes/security-alerts.php';

function handle_admin(string $group, array $args): never
{
    require_admin();

    match ($group) {
        'dashboard'     => admin_dashboard(),
        'operations'    => admin_operations($args),
        'voters'        => admin_voters($args),
        'candidates'    => admin_candidates($args),
        'elections'     => admin_elections($args),
        'positions'     => admin_positions($args),
        'monitor'       => admin_monitor(),
        'results'       => admin_results($args),
        'reports'       => admin_reports(),
        'security'      => admin_security($args),
        'announcements' => admin_announcements($args),
        'settings'      => admin_settings(),
        default         => json_error('That page or action does not exist.', 404, ['code' => 'UNKNOWN_ROUTE', 'endpoint' => $group]),
    };
}

/* ═══════════════════════════════════════════════════════════
   GET dashboard
   ═══════════════════════════════════════════════════════════ */
function admin_dashboard(): never
{
    require_method('GET');
    $pdo = db();

    $election    = vt_current_election();
    $totalVoters = vt_total_voters();

    $totalCandidates = (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn();

    /*
     * The stat cards are labelled "Total …" and "Votes Cast", so they must
     * count the whole system. Scoping this one to the running election made
     * it read 0 whenever a fresh election opened, even with ballots already
     * cast in earlier ones. Turnout for the live election is reported
     * separately in activeElection below.
     */
    $votesCast     = (int) $pdo->query('SELECT COUNT(*) FROM ballots WHERE has_voted = 1')->fetchColumn();
    $activeVotes   = $election ? vt_votes_cast((int) $election['election_id']) : 0;

    /* Votes per position for the bar chart. */
    $positionVotes = [];
    if ($election) {
        $s = $pdo->prepare(
            'SELECT p.position_name AS label,
                    (SELECT COUNT(*) FROM votes v WHERE v.position_id = p.position_id) AS votes
               FROM positions p
              WHERE p.election_id = ?
              ORDER BY p.sort_order, p.position_id'
        );
        $s->execute([(int) $election['election_id']]);
        foreach ($s->fetchAll() as $r) {
            $positionVotes[] = ['label' => $r['label'], 'votes' => (int) $r['votes']];
        }
    }

    /* Active election panel. */
    $activeElection = null;
    if ($election && is_election_live($election)) {
        $eid = (int) $election['election_id'];
        $activeElection = [
            'id'            => $eid,
            'title'         => $election['title'],
            'startDate'     => fmt_date($election['start_datetime']),
            'endDate'       => fmt_date($election['end_datetime']),
            'positions'     => count(vt_positions($eid)),
            'turnoutVoted'  => $activeVotes,
            'turnoutTotal'  => $totalVoters,
        ];
    }

    /* Who voted recently — names and times only, never their choices. */
    $recentVoters = [];
    if ($election) {
        $s = $pdo->prepare(
            'SELECT m.full_name, m.member_no, b.voted_at
               FROM ballots b
               JOIN members m ON m.user_id = b.user_id
              WHERE b.election_id = ? AND b.has_voted = 1
              ORDER BY b.voted_at DESC LIMIT 6'
        );
        $s->execute([(int) $election['election_id']]);
        foreach ($s->fetchAll() as $r) {
            $recentVoters[] = [
                'name'     => $r['full_name'],
                'memberId' => $r['member_no'] ?? '—',
                'time'     => fmt_relative($r['voted_at']),
                'status'   => 'Voted',
            ];
        }
    }

    /* Show administrator actions, using the same actor scope as Security & Logs. */
    $recentActivity = [];
    foreach ($pdo->query(
        "SELECT a.action, a.description, a.timestamp
           FROM audit_logs a
           JOIN users u ON u.user_id = a.user_id
           JOIN roles r ON r.role_id = u.role_id
          WHERE r.slug = 'admin'
          ORDER BY a.log_id DESC LIMIT 8"
    )->fetchAll() as $r) {
        $recentActivity[] = [
            'type'  => audit_type($r['action']),
            'title' => audit_event_text($r),
            'time'  => fmt_relative($r['timestamp']),
        ];
    }

    json_out([
        'totalVoters'      => $totalVoters,
        'totalCandidates'  => $totalCandidates,
        'votesCast'        => $votesCast,
        'positionVotes'    => $positionVotes,
        'activeElection'   => $activeElection,
        'recentVoters'     => $recentVoters,
        'recentActivity'   => $recentActivity,
    ]);
}

/** Map an audit action onto the icon classes the UI uses. */
function audit_type(string $action): string
{
    return match (true) {
        str_starts_with($action, 'ballot.')            => 'vote',

        /* Anything that was refused reads as a warning, so the "Refused"
           filter on the security page finds all of them and not just
           failed sign-ins. */
        $action === 'auth.login_failed',
        $action === 'auth.account_locked',
        $action === 'auth.otp_failed',
        $action === 'auth.password_change_failed'      => 'warn',

        str_starts_with($action, 'auth.')              => 'auth',
        str_starts_with($action, 'election.'),
        str_starts_with($action, 'candidate.'),
        str_starts_with($action, 'position.'),
        str_starts_with($action, 'member.'),
        str_starts_with($action, 'user.'),
        str_starts_with($action, 'settings.'),
        str_starts_with($action, 'security.')          => 'admin',
        default                                        => 'sys',
    };
}

/* ═══════════════════════════════════════════════════════════
   voters — GET list | POST create | PUT update
            PATCH {id}/approve|reject | DELETE {id} | GET export
   ═══════════════════════════════════════════════════════════ */
function admin_voters(array $args): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $first  = $args[0] ?? '';

    if ($first === 'export') admin_voters_export();
    if ($first === 'registration-status') {
        require_method('GET');
        json_out(admin_voter_registration_state());
    }

    if ($method === 'GET' && $first === '') {
        json_out(admin_voters_list());
    }

    require_writer();

    $id     = (int) $first;
    $action = $args[1] ?? '';

    /*
     * The cooperative office registers its members: there is no public
     * sign-up page any more, so this is the only way an account is born.
     */
    if ($method === 'POST' && $first === '') {
        admin_voters_save(0);
    }

    if (in_array($method, ['PUT', 'PATCH'], true) && $id && $action === '') admin_voters_save($id);
    if ($method === 'PATCH' && $id && $action !== '')        admin_voters_status($id, $action);
    if ($method === 'DELETE' && $id)                         admin_voters_delete($id);

    json_error('Unsupported voters request.', 405);
}

function admin_voters_list(): array
{
    $rows = db()->query(
        "SELECT u.user_id, u.username, u.email, u.status, u.created_at,
                m.member_id, m.member_no, m.full_name, m.contact_no, m.address,
                m.membership_status, m.registered_at, m.date_of_birth,
                m.id_front_url, m.id_back_url, m.photo_url
           FROM users u
           JOIN roles r ON r.role_id = u.role_id
           LEFT JOIN members m ON m.user_id = u.user_id
          WHERE r.slug = 'voter'
          ORDER BY m.full_name, u.user_id"
    )->fetchAll();

    $election = vt_current_election();
    $eid      = $election ? (int) $election['election_id'] : 0;

    $voted = [];
    if ($eid) {
        foreach (db()->query(
            "SELECT user_id FROM ballots WHERE election_id = $eid AND has_voted = 1"
        )->fetchAll() as $b) {
            $voted[(int) $b['user_id']] = true;
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $n = split_name((string) ($r['full_name'] ?? $r['username']));
        $out[] = [
            'id'         => (int) $r['user_id'],
            'first'      => $n['first'],
            'last'       => $n['last'],
            'memberId'   => $r['member_no'] ?? '—',
            'username'   => $r['username'],
            'email'      => $r['email'],
            'phone'      => $r['contact_no'] ?: '',
            'address'    => $r['address'] ?: '',
            /*
             * Voter management knows three states: Active, Suspended and
             * Inactive. The office opens every account itself, and opens it
             * active, so the application states the old sign-up flow used —
             * pending_approval, pending_verification, rejected — can no
             * longer be reached; see the closed self-registration flow in
             * routes/auth.php. Accounts the old flow left in one of them are
             * folded into Inactive, which is exactly what they are: unable
             * to sign in. Nothing is lost — users.status still holds the
             * original word.
             */
            'status'     => match ($r['status']) {
                'active'    => 'Active',
                'suspended' => 'Suspended',
                default     => 'Inactive',
            },
            'voted'      => isset($voted[(int) $r['user_id']]) ? 'Voted' : 'Not Voted',
            'registered' => fmt_date($r['registered_at'] ?? $r['created_at']),
            'dob'        => $r['date_of_birth'] ? fmt_date($r['date_of_birth']) : '',
            'dobInput'   => $r['date_of_birth'] ?: '',
            /* Paths are relative to the project root; the page prefixes them. */
            'idFrontUrl' => $r['id_front_url'] ?: null,
            'idBackUrl'  => $r['id_back_url'] ?: null,
            'photoUrl'   => profile_photo_url($r['photo_url'] ?: null, (int) $r['member_id']),
        ];
    }
    return $out;
}

/**
 * A username nobody else holds, built from the name the administrator typed.
 * The old code appended random digits once and hoped; a second clash
 * would have thrown a duplicate-key error in the administrator's face.
 */
function admin_unique_username(string $seed): string
{
    /* Dots, dashes and underscores are ordinary in a username, so a name
       the administrator typed on purpose survives; everything else goes. */
    $base = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $seed));
    $base = trim($base, '._-') ?: 'member';
    $base = substr($base, 0, 40);

    $pdo  = db();
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');

    $try = $base;
    for ($n = 1; $n <= 200; $n++) {
        $stmt->execute([$try]);
        if (!$stmt->fetchColumn()) {
            return $try;
        }
        $try = $base . $n;
    }

    return $base . random_int(1000, 999999);
}

/**
 * A one-off password for a brand-new account.
 *
 * Every administrator-made account used to get the same "coopvote123",
 * which meant anyone who knew it could sign in as any new member before
 * they did. Ambiguous characters are left out because this gets read
 * aloud across a desk or copied off a printout.
 */
function admin_temp_password(int $length = 10): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $max      = strlen($alphabet) - 1;

    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/** Registration is closed while any election is accepting ballots. */
function admin_voter_registration_state(bool $lock = false): array
{
    $active = db()->query(
        "SELECT election_id FROM elections
          WHERE status = 'published' AND start_datetime <= NOW() AND end_datetime > NOW()
          LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
    )->fetchColumn();
    return [
        'allowed' => $active === false,
        'reason' => $active === false ? '' : 'Adding voters is disabled while an election is active. Wait until all active elections have closed.',
    ];
}

function admin_voters_save(int $userId): never
{
    if ($userId === 0) {
        $registration = admin_voter_registration_state();
        if (!$registration['allowed']) json_error($registration['reason'], 409, ['code' => 'VOTER_REGISTRATION_CLOSED']);
    }
    $first = input_str('first');
    $last  = input_str('last');
    $email = strtolower(input_str('email'));
    $phone = input_str('phone');
    $addr  = input_str('address');
    $dob   = input_str('dob');
    $memberNo = input_str('memberId', input_str('memberNo'));

    $errors = person_fields_errors($first, $last, $phone);
    if ($error = birth_date_error($dob)) $errors['dob'] = $error;
    if ($first === '') $errors['first'] = 'First name is required.';
    if ($last === '')  $errors['last']  = 'Last name is required.';
    if ($dob === '')   $errors['dob'] = 'Date of birth is required.';
    if ($phone === '') $errors['phone'] = 'Phone number is required.';
    if ($addr === '')  $errors['address'] = 'Address is required.';
    if ($userId !== 0 && $memberNo === '') $errors['memberId'] = 'Member ID is required.';
    if (mb_strlen($memberNo, 'UTF-8') > 30) $errors['memberId'] = 'Use at most 30 characters for the member ID.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'A valid email address is required.';
    }
    if ($errors) json_error('Please correct the highlighted fields.', 422, ['fields' => $errors]);

    $pdo  = db();
    $me   = current_user();
    $full = trim($first . ' ' . $last);

    if ($userId !== 0) {
        $existing = $pdo->prepare('SELECT m.*, u.email FROM members m JOIN users u ON u.user_id=m.user_id WHERE m.user_id = ? LIMIT 1');
        $existing->execute([$userId]);
        $before = $existing->fetch();
        if (!$before) json_error('That member no longer exists. Reload the voter list.', 404);
    }
    if ($memberNo !== '') {
        $dupNo = $pdo->prepare('SELECT 1 FROM members WHERE member_no = ? AND user_id <> ? LIMIT 1');
        $dupNo->execute([$memberNo, $userId]);
        if ($dupNo->fetchColumn()) json_error('That member ID is already in use.', 409,
            ['fields' => ['memberId' => 'Choose a unique member ID.']]);
    }

    $dup = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND user_id <> ? LIMIT 1');
    $dup->execute([$email, $userId]);
    if ($dup->fetchColumn()) {
        json_error('That email address is already in use.', 409, ['fields' => ['email' => 'Already in use.']]);
    }

    if ($userId === 0) {
        /*
         * Create. The office registers the member, so the account is usable
         * straight away — there is nobody left to approve it.
         */
        $username = admin_unique_username(input_str('username') ?: $first . $last);
        $password = admin_temp_password();

        $pdo->beginTransaction();
        try {
            // Recheck after validation, holding election locks until creation commits.
            $registration = admin_voter_registration_state(true);
            if (!$registration['allowed']) {
                $pdo->rollBack();
                json_error($registration['reason'], 409, ['code' => 'VOTER_REGISTRATION_CLOSED']);
            }
            $pdo->prepare(
                "INSERT INTO users (username, email, password_hash, role_id, status, email_verified_at)
                 VALUES (?,?,?,(SELECT role_id FROM roles WHERE slug='voter'),'active',NOW())"
            )->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT)]);

            $userId = (int) $pdo->lastInsertId();

            if ($memberNo === '') {
                $next = (int) $pdo->query(
                    "SELECT COALESCE(MAX(CAST(SUBSTRING(member_no,6) AS UNSIGNED)),0)+1
                       FROM members WHERE member_no LIKE 'COOP-%'"
                )->fetchColumn();
                $memberNo = sprintf('COOP-%04d', $next);
            }

            $pdo->prepare(
                "INSERT INTO members
                    (user_id, member_no, full_name, date_of_birth, contact_no, address,
                     membership_status, approved_by, approved_at)
                 VALUES (?,?,?,?,?,?,'active',?,NOW())"
            )->execute([
                $userId, $memberNo, $full,
                $dob !== '' ? $dob : null,
                $phone ?: null, $addr ?: null, (int) $me['user_id'],
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        audit((int) $me['user_id'], 'member.created', 'user', $userId,
            'Registered member "' . $full . '" (' . $memberNo . ') as ' . $username);

        /*
         * Hand the credentials back so the administrator can read them out to the
         * member in the office, and post them as well. The password is only
         * ever shown at this moment — it is stored hashed, so it cannot be
         * looked up again afterwards.
         */
        $payload = [
            'ok'       => true,
            'id'       => $userId,
            'username' => $username,
            'memberNo' => $memberNo,
            'password' => $password,
            'emailing' => MAIL_ENABLED,
        ];

        if (!MAIL_ENABLED) {
            json_out($payload, 201);
        }

        respond_then($payload, static function () use ($email, $full, $memberNo, $username, $password): void {
            require_once BASE_PATH . '/includes/mailer.php';
            mail_account_created($email, $full, $memberNo, $username, $password);
        }, 201);
    }

    /* Update */
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET email = ? WHERE user_id = ?')->execute([$email, $userId]);
        $pdo->prepare(
            'UPDATE members SET full_name = ?, contact_no = ?, address = ?, date_of_birth = ?, member_no = ? WHERE user_id = ?'
        )->execute([$full, $phone, $addr, $dob, $memberNo, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            json_error('The email address or member ID is already in use. Reload and try again.', 409);
        }
        throw $e;
    }

    audit((int) $me['user_id'], 'member.updated', 'user', $userId, 'Updated member ' . audit_subject('user', $userId)
        . audit_changed_fields($before, ['full_name'=>$full,'email'=>$email,'contact_no'=>$phone,'address'=>$addr,'date_of_birth'=>$dob,'member_no'=>$memberNo],
            ['full_name'=>'name','email'=>'email','contact_no'=>'phone','address'=>'address','date_of_birth'=>'date of birth','member_no'=>'member ID']));
    json_out(['ok' => true, 'id' => $userId]);
}

function admin_voters_status(int $userId, string $action): never
{
    /*
     * @retained approve/reject belonged to self-registration, which is
     * closed (routes/auth.php). Nothing in the admin pages calls them any
     * more, and a member left 'rejected' now lists as Inactive. They stay
     * so that reopening self-registration is a matter of restoring the UI.
     */
    $map = [
        'approve'    => ['active', 'active',   'member.approved',        'Approved membership'],
        'reject'     => ['rejected', 'inactive', 'member.rejected',      'Rejected membership'],
        'suspend'    => ['suspended', 'inactive', 'user.status_changed', 'Suspended account'],
        'activate'   => ['active', 'active',   'user.status_changed',    'Reactivated account'],
        'deactivate' => ['deactivated', 'inactive', 'user.status_changed', 'Deactivated account'],
    ];

    if (!isset($map[$action])) {
        json_error('That page or action does not exist.', 404, ['code' => 'UNKNOWN_ROUTE', 'endpoint' => $action]);
    }

    [$userStatus, $memberStatus, $auditAction, $label] = $map[$action];
    $me = current_user();

    db()->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([$userStatus, $userId]);
    db()->prepare(
        'UPDATE members SET membership_status = ?, approved_by = ?, approved_at = NOW() WHERE user_id = ?'
    )->execute([$memberStatus, (int) $me['user_id'], $userId]);

    audit((int) $me['user_id'], $auditAction, 'user', $userId, $label . ' for ' . audit_subject('user', $userId));

    /* Tell the applicant the decision — but not while the administrator waits.
       The status change already stands, so the answer goes back straight
       away and the mail is sent after the response has been flushed. */
    if ($action === 'approve' || $action === 'reject') {
        $s = db()->prepare(
            'SELECT u.email, m.full_name, m.member_no
               FROM users u LEFT JOIN members m ON m.user_id = u.user_id
              WHERE u.user_id = ? LIMIT 1'
        );
        $s->execute([$userId]);
        $who = $s->fetch();

        if ($who) {
            respond_then(
                ['ok' => true, 'status' => $userStatus, 'emailing' => true],
                static function () use ($who, $action): void {
                    require_once BASE_PATH . '/includes/mailer.php';
                    $name = (string) ($who['full_name'] ?? '');
                    $no   = (string) ($who['member_no'] ?? '—');

                    $action === 'approve'
                        ? mail_membership_approved((string) $who['email'], $name, $no)
                        : mail_membership_rejected((string) $who['email'], $name, $no);
                }
            );
        }
    }

    json_out(['ok' => true, 'status' => $userStatus, 'emailing' => false]);
}

function admin_voters_delete(int $userId): never
{
    $me = current_user();
    $subject = audit_subject('user', $userId);
    if ((int) $me['user_id'] === $userId) {
        json_error('You cannot delete your own account.', 409);
    }

    /*
     * A hard delete cascades members → candidates → votes, so removing a
     * member who stands as a candidate would silently wipe their candidacy
     * and every vote cast for them. Retire the account instead whenever
     * deleting it would destroy election data.
     */
    $s = db()->prepare('SELECT COUNT(*) FROM ballots WHERE user_id = ? AND has_voted = 1');
    $s->execute([$userId]);
    $hasVoted = (int) $s->fetchColumn() > 0;

    $s = db()->prepare(
        'SELECT COUNT(*) AS candidacies,
                COALESCE((SELECT COUNT(*) FROM votes v
                            JOIN candidates c2 ON c2.candidate_id = v.candidate_id
                            JOIN members m2 ON m2.member_id = c2.member_id
                           WHERE m2.user_id = ?), 0) AS votes_received
           FROM candidates c
           JOIN members m ON m.member_id = c.member_id
          WHERE m.user_id = ?'
    );
    $s->execute([$userId, $userId]);
    $standing = $s->fetch() ?: ['candidacies' => 0, 'votes_received' => 0];

    $candidacies    = (int) $standing['candidacies'];
    $votesReceived  = (int) $standing['votes_received'];

    if ($hasVoted || $candidacies > 0) {
        db()->prepare("UPDATE users SET status = 'deactivated' WHERE user_id = ?")->execute([$userId]);
        db()->prepare("UPDATE members SET membership_status = 'inactive' WHERE user_id = ?")->execute([$userId]);

        $why = match (true) {
            $votesReceived > 0 => "This member is a candidate holding {$votesReceived} vote(s), so the account was "
                                . 'deactivated instead of deleted. Deleting it would have erased those votes and changed the result.',
            $candidacies > 0   => 'This member is standing as a candidate, so the account was deactivated instead of '
                                . 'deleted. Withdraw them in Candidate Management first if you really want them removed.',
            default            => 'This member has already voted, so the account was deactivated instead of deleted to '
                                . 'keep the audit trail intact.',
        };

        audit((int) $me['user_id'], 'user.status_changed', 'user', $userId,
            'Deactivated member ' . $subject . ' (voted=' . ($hasVoted ? 'yes' : 'no')
            . ', candidacies=' . $candidacies . ', votes received=' . $votesReceived . ')');

        json_out(['ok' => true, 'deactivated' => true, 'message' => $why]);
    }

    db()->prepare('DELETE FROM users WHERE user_id = ?')->execute([$userId]);
    audit((int) $me['user_id'], 'member.deleted', 'user', $userId, 'Deleted member ' . $subject);
    json_out(['ok' => true, 'deleted' => true]);
}

function admin_voters_export(): never
{
    require_method('GET');
    csv_out('coopvote-members.csv',
        ['Member ID', 'First Name', 'Last Name', 'Username', 'Email', 'Phone', 'Status', 'Voted', 'Registered'],
        array_map(static fn($v) => [
            $v['memberId'], $v['first'], $v['last'], $v['username'],
            $v['email'], $v['phone'], $v['status'], $v['voted'], $v['registered'],
        ], admin_voters_list())
    );
}

/* ═══════════════════════════════════════════════════════════
   candidates — GET | POST | PUT {id} | DELETE {id} | GET export
   ═══════════════════════════════════════════════════════════ */
function admin_candidates(array $args): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $first  = $args[0] ?? '';

    if ($first === 'export') admin_candidates_export();

    if ($method === 'GET' && $first === '') {
        json_out(admin_candidates_list());
    }
    if ($method === 'GET' && $first === 'eligible') {
        json_out(admin_candidate_members());
    }

    require_writer();
    $id = (int) $first;

    if ($method === 'POST' && $first === '')                       admin_candidates_save(0);
    if (in_array($method, ['PUT', 'PATCH'], true) && $id)          admin_candidates_save($id);
    if ($method === 'DELETE' && $id)                               admin_candidates_delete($id);

    json_error('Unsupported candidates request.', 405);
}

/** Candidacy belongs to an existing voter account, never a separate role. */
function admin_candidate_members(): array
{
    // Include every nomination, including inactive records: uniqueness is per election.
    $nominations = [];
    foreach (db()->query('SELECT member_id, election_id FROM candidates')->fetchAll() as $candidate) {
        $nominations[(int) $candidate['member_id']][] = (int) $candidate['election_id'];
    }
    $rows = db()->query(
        "SELECT m.member_id AS id, m.member_no AS memberNo, m.full_name AS name,
                m.date_of_birth, m.occupation AS occ, m.years_in_coop AS years, m.photo_url AS photoUrl
           FROM members m JOIN users u ON u.user_id = m.user_id
           JOIN roles r ON r.role_id = u.role_id
          WHERE u.status = 'active' AND r.slug = 'voter'
          ORDER BY m.full_name, m.member_no, m.member_id"
    )->fetchAll();
    foreach ($rows as &$row) {
        $name = split_name((string) $row['name']);
        $row['id'] = (int) $row['id'];
        $row['candidateElectionIds'] = $nominations[$row['id']] ?? [];
        $row['first'] = $name['first'];
        $row['last'] = $name['last'];
        $row['age'] = age_from_dob($row['date_of_birth']);
        $row['photoUrl'] = profile_photo_url($row['photoUrl'] ?: null, $row['id']);
        unset($row['date_of_birth']);
    }
    unset($row);
    return $rows;
}

/**
 * Every candidate on record, newest election first.
 *
 * This used to return only the currently running election's candidates,
 * which meant the moment a different election went live the rest simply
 * vanished from Candidate Management — there was no way to see or edit
 * them. Pass ?election=<id> to narrow it down.
 */
function admin_candidates_list(): array
{
    $wanted = (int) ($_GET['election'] ?? 0);

    $elections = db()->query(
        'SELECT election_id, title FROM elections ORDER BY start_datetime DESC'
    )->fetchAll();

    $out = [];
    foreach ($elections as $e) {
        $eid = (int) $e['election_id'];
        if ($wanted && $eid !== $wanted) {
            continue;
        }

        foreach (vt_candidates($eid, true) as $c) {
            $d = vt_candidate_dto($c, true);
            $out[] = admin_candidate_row($d, $c, $eid, (string) $e['title']);
        }
    }
    return $out;
}

/** Shape one candidate the way the management table expects. */
function admin_candidate_row(array $d, array $c, int $electionId, string $electionTitle): array
{
    return [
        'id'         => $d['id'],
        'first'      => $d['first'],
        'last'       => $d['last'],
        'position'   => $d['position'],
        'age'        => $d['age'],
        'occ'        => $d['occ'],
        'desc'       => $d['bio'] ?: $d['platform'],
        'platform'   => $d['platform'],
        'votes'      => $d['votes'],
        'status'     => $d['status'],
        'years'      => $d['yearsInCoop'],
        'photoUrl'   => $d['photoUrl'],
        'memberId'   => (int) $c['member_id'],
        'memberNo'   => $c['member_no'] ?? '',
        'positionId' => (int) $c['position_id'],
        /* Which election this candidacy belongs to. */
        'electionId' => $electionId,
        'election'   => $electionTitle,
    ];
}

function admin_candidates_save(int $candidateId): never
{
    $pdo = db();
    $me  = current_user();

    $electionId = (int) input('electionId', 0);
    $positionId = (int) input('positionId', 0);
    $memberId   = (int) input('memberId', 0);
    $platform   = input_str('platform');
    $bio        = input_str('desc') ?: input_str('bio');
    $years      = input('years', null);
    $occ        = input_str('occ');
    $isActive   = input('status', 'Active') === 'Inactive' ? 0 : 1;

    $first = input_str('first');
    $last = input_str('last');
    $errors = [];
    if (input('first') !== null || input('last') !== null) {
        $errors = person_fields_errors($first, $last);
    }
    if ($candidateId === 0 && $memberId <= 0) $errors['memberId'] = 'Select an existing voter. Register new members in Voter Management first.';
    if (input('memberId') !== null && whole_number_error(input('memberId'), 1, 2147483647)) {
        $errors['memberId'] = 'Select a valid voter from the list.';
    }
    if ($error = whole_number_error($years, 0, 2147483647)) $errors['years'] = $error;
    if ($error = whole_number_error(input('age', null), 18, 100)) $errors['age'] = $error;
    if (input('occ') !== null && $occ === '') $errors['occ'] = 'Occupation / background is required.';
    if (input('years') !== null && blank_form_value((string) (is_scalar($years) ? $years : ''))) $errors['years'] = 'Years as member is required. Enter 0 for less than a year.';
    if (input('positionId') !== null && !$positionId) $errors['positionId'] = 'Please choose a position.';
    if ($errors) json_error('Please correct the highlighted fields.', 422, ['fields' => $errors]);

    /* Editing keeps the candidacy in its original election. */
    $existingMember = 0;
    if ($candidateId !== 0) {
        $s = $pdo->prepare('SELECT c.*, m.occupation, m.years_in_coop FROM candidates c JOIN members m ON m.member_id=c.member_id WHERE c.candidate_id = ? LIMIT 1');
        $s->execute([$candidateId]);
        $existing = $s->fetch();
        if (!$existing) json_error('That candidate does not exist.', 404);
        $existingMember = (int) $existing['member_id'];
        if (input('memberId') !== null && $memberId !== $existingMember) {
            json_error('This candidacy belongs to its original member. Add a separate nomination for another voter.', 422,
                ['fields' => ['memberId' => 'The linked member cannot be changed.']]);
        }
        if ($first !== '' || $last !== '') {
            $memberName = $pdo->prepare('SELECT full_name FROM members WHERE member_id = ?');
            $memberName->execute([$existingMember]);
            if (trim($first . ' ' . $last) !== trim(preg_replace('/\s+/', ' ', (string) $memberName->fetchColumn()))) {
                json_error('Edit the member name in Voter Management, then reload the candidate list.', 422,
                    ['fields' => ['first' => 'The candidate name comes from the member record.']]);
            }
        }
        if ($electionId && $electionId !== (int) $existing['election_id']) {
            json_error('A candidate must stay in their original election.', 422);
        }
        $electionId = (int) $existing['election_id'];
    }

    if ($electionId <= 0) {
        json_error('Please choose an election first.', 422, ['fields' => ['electionId' => 'Required.']]);
    }
    $s = $pdo->prepare('SELECT election_id FROM elections WHERE election_id = ? LIMIT 1');
    $s->execute([$electionId]);
    if (!$s->fetchColumn()) json_error('That election does not exist.', 404);

    /* Legacy position names are resolved only within the chosen election. */
    if (!$positionId && ($positionName = input_str('position')) !== '') {
        $s = $pdo->prepare(
            'SELECT position_id FROM positions WHERE election_id = ? AND position_name = ? LIMIT 1'
        );
        $s->execute([$electionId, $positionName]);
        $positionId = (int) $s->fetchColumn();

        if (!$positionId) {
            json_error('There is no "' . $positionName . '" position in the selected election. Add the position first.', 422);
        }
    }

    if ($candidateId === 0 && !$positionId) {
        json_error('Please choose a position.', 422, ['fields' => ['position' => 'Required.']]);
    }
    if ($positionId) {
        $s = $pdo->prepare('SELECT election_id FROM positions WHERE position_id = ? LIMIT 1');
        $s->execute([$positionId]);
        $positionElection = $s->fetchColumn();
        if ($positionElection === false) json_error('That position does not exist.', 404);
        if ((int) $positionElection !== $electionId) {
            json_error('Please choose a position from the selected election.', 422,
                ['fields' => ['positionId' => 'This position belongs to another election.']]);
        }
    }

    if ($candidateId === 0) {
        $member = $pdo->prepare(
            "SELECT m.member_id FROM members m JOIN users u ON u.user_id = m.user_id
             JOIN roles r ON r.role_id = u.role_id
             WHERE m.member_id = ? AND u.status = 'active' AND r.slug = 'voter' LIMIT 1"
        );
        $member->execute([$memberId]);
        if (!$member->fetchColumn()) json_error('Select an active voter from Voter Management.', 422,
            ['fields' => ['memberId' => 'This member is not an active voter. Reload the list.']]);

        $dup = $pdo->prepare('SELECT 1 FROM candidates WHERE member_id = ? AND election_id = ? LIMIT 1');
        $dup->execute([$memberId, $electionId]);
        if ($dup->fetchColumn()) {
            json_error('That member is already a candidate in this election.', 409);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO candidates (member_id, position_id, election_id, platform, bio, is_active)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$memberId, $positionId, $electionId, $platform ?: null, $bio ?: null, $isActive]);
            $candidateId = (int) $pdo->lastInsertId();
            admin_candidate_member_extras($memberId, $occ, $years);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                json_error('This nomination conflicts with an existing or changed record. Reload the candidate list.', 409);
            }
            throw $e;
        }

        audit((int) $me['user_id'], 'candidate.created', 'candidate', $candidateId, 'Added candidate ' . audit_subject('candidate', $candidateId));
        json_out(['ok' => true, 'id' => $candidateId], 201);
    }

    $sets   = ['platform = ?', 'bio = ?', 'is_active = ?'];
    $params = [$platform ?: null, $bio ?: null, $isActive];

    if ($positionId) {
        $sets[]   = 'position_id = ?';
        $params[] = $positionId;
    }
    $params[] = $candidateId;

    db_transaction(static function(PDO $pdo) use($electionId,$sets,$params):void {
        governance_election($pdo,$electionId);
        $pdo->prepare('UPDATE candidates SET ' . implode(', ', $sets) . ' WHERE candidate_id = ?')->execute($params);
    });

    admin_candidate_member_extras($existingMember, $occ, $years);

    audit((int) $me['user_id'], 'candidate.updated', 'candidate', $candidateId, 'Updated candidate ' . audit_subject('candidate', $candidateId)
        . audit_changed_fields($existing, ['platform'=>$platform,'bio'=>$bio,'is_active'=>$isActive,'position_id'=>$positionId ?: $existing['position_id'],
            'occupation'=>$occ !== '' ? $occ : $existing['occupation'],
            'years_in_coop'=>($years !== null && $years !== '') ? (int)$years : $existing['years_in_coop']],
            ['platform'=>'platform','bio'=>'biography','is_active'=>'status','position_id'=>'position','occupation'=>'occupation','years_in_coop'=>'membership years']));
    json_out(['ok' => true, 'id' => $candidateId]);
}

/** Occupation and years-in-coop live on the member record. */
function admin_candidate_member_extras(int $memberId, string $occ, mixed $years): void
{
    if ($occ === '' && ($years === null || $years === '')) return;

    $sets   = [];
    $params = [];
    if ($occ !== '')                       { $sets[] = 'occupation = ?';    $params[] = $occ; }
    if ($years !== null && $years !== '')  { $sets[] = 'years_in_coop = ?'; $params[] = (int) $years; }
    $params[] = $memberId;

    db()->prepare('UPDATE members SET ' . implode(', ', $sets) . ' WHERE member_id = ?')->execute($params);
}

function admin_candidates_delete(int $candidateId): never
{
    $me = current_user();
    $subject = audit_subject('candidate', $candidateId);

    // Serialize candidate removal with the approval check and opening transition.
    db()->beginTransaction();
    $election = db()->prepare('SELECT election_id FROM candidates WHERE candidate_id = ?');
    $election->execute([$candidateId]);
    $eid = (int)$election->fetchColumn();
    if ($eid) governance_election(db(), $eid);

    $s = db()->prepare('SELECT COUNT(*) FROM votes WHERE candidate_id = ?');
    $s->execute([$candidateId]);

    if ((int) $s->fetchColumn() > 0) {
        db()->prepare('UPDATE candidates SET is_active = 0 WHERE candidate_id = ?')->execute([$candidateId]);
        db()->commit();
        audit((int) $me['user_id'], 'candidate.deactivated', 'candidate', $candidateId,
            'Withdrew candidate ' . $subject . ' (votes exist — tally preserved)');
        json_out(['ok' => true, 'deactivated' => true,
            'message' => 'This candidate already has votes, so they were marked withdrawn instead of deleted. The tally stays intact.']);
    }

    db()->prepare('DELETE FROM candidates WHERE candidate_id = ?')->execute([$candidateId]);
    db()->commit();
    audit((int) $me['user_id'], 'candidate.deleted', 'candidate', $candidateId, 'Deleted candidate ' . $subject);
    json_out(['ok' => true, 'deleted' => true]);
}

function admin_candidates_export(): never
{
    require_method('GET');
    csv_out('coopvote-candidates.csv',
        ['ID', 'First Name', 'Last Name', 'Position', 'Age', 'Occupation', 'Years', 'Votes', 'Status'],
        array_map(static fn($c) => [
            $c['id'], $c['first'], $c['last'], $c['position'],
            $c['age'], $c['occ'], $c['years'], $c['votes'], $c['status'],
        ], admin_candidates_list())
    );
}

/* ═══════════════════════════════════════════════════════════
   elections — GET | POST | PUT {id} | DELETE {id}
               PATCH {id}/activate|close|reopen|release|unpublish|cancel
   ═══════════════════════════════════════════════════════════ */
function admin_elections(array $args): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $first  = $args[0] ?? '';

    if ($method === 'GET' && $first === '') {
        json_out(admin_elections_list());
    }

    require_writer();

    $id     = (int) $first;
    $action = $args[1] ?? '';

    if ($method === 'POST' && $first === '')                             admin_elections_save(0);
    if (in_array($method, ['PUT', 'PATCH'], true) && $id && $action === '') admin_elections_save($id);
    if ($method === 'PATCH' && $id && $action !== '')                    admin_elections_action($id, $action);
    if ($method === 'DELETE' && $id)                                     admin_elections_delete($id);

    json_error('Unsupported elections request.', 405);
}


function admin_elections_save(int $electionId): never
{
    $me    = current_user();
    $title = input_str('title');
    $desc  = input_str('description');
    $start = input_str('startDate') ?: input_str('start');
    $end   = input_str('endDate')   ?: input_str('end');

    $errors = [];
    if ($title === '') $errors['title'] = 'Title is required.';
    elseif (mb_strlen($title, 'UTF-8') > 150) $errors['title'] = 'Use at most 150 characters for the election title.';
    if ($start === '') $errors['startDate'] = 'Start date is required.';
    if ($end === '')   $errors['endDate']   = 'End date is required.';

    $startTs = strtotime($start);
    $endTs   = strtotime($end);
    if ($start !== '' && !$startTs) $errors['startDate'] = 'Invalid start date.';
    if ($end !== '' && !$endTs)     $errors['endDate']   = 'Invalid end date.';
    if ($startTs && $endTs && $endTs <= $startTs) {
        $errors['endDate'] = 'The end date must be after the start date.';
    }
    if ($errors) json_error('Please correct the highlighted fields.', 422, ['fields' => $errors]);

    $positions = [];
    if ($electionId === 0) {
        $selected = input('positions');
        $message = 'Select at least one position to save this election.';
        if (!is_array($selected) || !array_is_list($selected) || !$selected) {
            json_error($message, 422, ['fields' => ['positions' => $message]]);
        }
        $seen = [];
        foreach ($selected as $name) {
            if (!is_string($name) || blank_form_value($name)) {
                json_error($message, 422, ['fields' => ['positions' => $message]]);
            }
            $name = preg_replace('/\s+/u', ' ', trim_form_text($name));
            if (mb_strlen($name, 'UTF-8') > 100) {
                json_error('Use at most 100 characters for each position name.', 422);
            }
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$key])) json_error('Select each position only once.', 422);
            $seen[$key] = true;
            $positions[] = $name;
        }
    }

    $startSql = date('Y-m-d H:i:s', (int) $startTs);
    $endSql   = date('Y-m-d H:i:s', (int) $endTs);

    if ($electionId === 0) {
        $electionId = db_transaction(static function (PDO $pdo) use ($title, $desc, $startSql, $endSql, $me, $positions): int {
        $pdo->prepare(
            "INSERT INTO elections (title, description, start_datetime, end_datetime, status, created_by)
             VALUES (?,?,?,?,'draft',?)"
        )->execute([$title, $desc ?: null, $startSql, $endSql, (int) $me['user_id']]);

        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO election_integrity (election_id, seal) VALUES (?,?)')
            ->execute([$id, integrity_state_seal($id, 0, 0)]);
        $insertPosition = $pdo->prepare('INSERT INTO positions (election_id, position_name, max_vote, sort_order) VALUES (?,?,1,?)');
        foreach ($positions as $index => $name) {
            $insertPosition->execute([$id, $name, $index + 1]);
        }
        return $id;
        });
        audit((int) $me['user_id'], 'election.created', 'election', $electionId, 'Created election "' . $title . '"');
        json_out(['ok' => true, 'id' => $electionId], 201);
    }

    $changes = '';
    $error = db_transaction(static function (PDO $pdo) use ($electionId, $title, $desc, $startSql, $endSql, &$changes): ?array {
        $existing = $pdo->prepare('SELECT * FROM elections WHERE election_id = ? FOR UPDATE');
        $existing->execute([$electionId]);
        $current = $existing->fetch();
        if (!$current) return ['That election no longer exists. Reload the election list.', 404];
        $locked = $pdo->prepare('SELECT election_id FROM election_reports WHERE election_id = ? AND superseded_at IS NULL');
        $locked->execute([$electionId]);
        if ($locked->fetchColumn() || election_status($current) === 'Closed') {
            return ['This election has closed. Use Reopen Election with a new deadline before editing it.', 409];
        }
        $pdo->prepare('UPDATE elections SET title = ?, description = ?, start_datetime = ?, end_datetime = ? WHERE election_id = ?')
            ->execute([$title, $desc ?: null, $startSql, $endSql, $electionId]);
        $changes = audit_changed_fields($current,
            ['title'=>$title,'description'=>$desc,'start_datetime'=>$startSql,'end_datetime'=>$endSql],
            ['title'=>'title','description'=>'description','start_datetime'=>'start date','end_datetime'=>'end date']);
        return null;
    });
    if ($error) json_error($error[0], $error[1]);

    audit((int) $me['user_id'], 'election.updated', 'election', $electionId, 'Updated election "' . $title . '"' . $changes);
    json_out(['ok' => true, 'id' => $electionId]);
}


function admin_elections_action(int $electionId, string $action): never
{
    $me = current_user();
    $s  = db()->prepare('SELECT * FROM elections WHERE election_id = ? LIMIT 1');
    $s->execute([$electionId]);
    $e = $s->fetch();
    if (!$e) json_error('That election does not exist.', 404);

    switch ($action) {
        case 'reopen':
            admin_elections_reopen($electionId, (int) $me['user_id']);

        case 'activate':
        case 'publish':
            admin_elections_open($electionId, $me);

        case 'close':
            $result = db_transaction(static function (PDO $pdo) use ($electionId, $me): array {
                $s = $pdo->prepare('SELECT * FROM elections WHERE election_id = ? FOR UPDATE');
                $s->execute([$electionId]);
                $current = $s->fetch();
                if (!$current) return ['error' => 'That election no longer exists.', 'code' => 'NOT_FOUND'];
                $state = election_status($current);
                if ($state === 'Upcoming') return ['error' => 'This election has not started yet. Cancel it instead, or edit its dates.', 'code' => 'NOT_STARTED'];
                if ($state !== 'Active') return ['ok' => true, 'status' => $state, 'alreadyClosed' => true];
                // Closing in the same second as opening must still satisfy end > start.
                $pdo->prepare('UPDATE elections SET start_datetime = LEAST(start_datetime, DATE_SUB(NOW(), INTERVAL 1 SECOND)), end_datetime = NOW() WHERE election_id = ?')->execute([$electionId]);
                $s->execute([$electionId]);
                election_report_capture_locked($pdo, $s->fetch());
                audit((int) $me['user_id'], 'election.closed', 'election', $electionId,
                    'Closed election "' . $current['title'] . '"');
                return ['ok' => true, 'status' => 'Closed'];
            });
            if (isset($result['error'])) json_error($result['error'], 409, ['code' => $result['code']]);
            json_out($result);

        case 'release':
        case 'release-results':
            $result = db_transaction(static function (PDO $pdo) use ($electionId, $me): array {
                $s = $pdo->prepare('SELECT * FROM elections WHERE election_id = ? FOR UPDATE');
                $s->execute([$electionId]);
                $current = $s->fetch();
                if (!$current || $current['status'] !== 'published' || election_status($current) !== 'Closed') {
                    return ['error' => 'Close the voting period first before publishing results.', 'code' => 'STILL_OPEN'];
                }
                if (integrity_verify($electionId)['status'] !== 'passed') {
                    return ['error' => 'Vote integrity must pass before publishing. Open Reports to review the integrity status.', 'code' => 'INTEGRITY_CHECK_FAILED'];
                }
                if (!empty($current['results_released_at'])) return ['ok' => true, 'published' => true, 'alreadyPublished' => true];
                election_report_capture_locked($pdo, $current);
                $pdo->prepare('UPDATE elections SET results_released_at = NOW(), released_by = ? WHERE election_id = ?')
                    ->execute([(int) $me['user_id'], $electionId]);
                audit((int) $me['user_id'], 'election.results_released', 'election', $electionId,
                    'Released results for "' . $current['title'] . '"');
                return ['ok' => true, 'published' => true];
            });
            if (isset($result['error'])) json_error($result['error'], 409, ['code' => $result['code']]);
            json_out($result);

        case 'unpublish':
        case 'unrelease':
            /*
             * The mirror of release, and the reason it exists: releasing was
             * a one-way door. The card showed a dead "Published" label, so a
             * count let out by mistake — the wrong election, or figures that
             * turn out to need correcting — could not be taken back from the
             * members without editing the database by hand.
             *
             * Only the flag moves. Votes, ballots and receipts are not
             * touched, and both the release and this are in the audit trail,
             * so the record still shows the results were once visible.
             */
            if (empty($e['results_released_at'])) {
                json_out(['ok' => true, 'published' => false, 'alreadyHidden' => true]);
            }

            db()->prepare(
                'UPDATE elections SET results_released_at = NULL, released_by = NULL WHERE election_id = ?'
            )->execute([$electionId]);
            audit((int) $me['user_id'], 'election.results_unpublished', 'election', $electionId,
                'Unpublished results for "' . $e['title'] . '"');
            json_out(['ok' => true, 'published' => false]);

        case 'cancel':
            db()->prepare("UPDATE elections SET status = 'cancelled' WHERE election_id = ?")
                ->execute([$electionId]);
            audit((int) $me['user_id'], 'election.cancelled', 'election', $electionId,
                'Cancelled election "' . $e['title'] . '"');
            json_out(['ok' => true, 'status' => 'Cancelled']);

        default:
            json_error('Unknown election action: ' . $action, 404);
    }
}

function admin_elections_delete(int $electionId): never
{
    $me = current_user();
    $subject = audit_subject('election', $electionId);

    $s = db()->prepare('SELECT COUNT(*) FROM votes WHERE election_id = ?');
    $s->execute([$electionId]);
    if ((int) $s->fetchColumn() > 0) {
        json_error('This election already has votes and cannot be deleted. Cancel it instead.', 409);
    }

    db()->prepare('DELETE FROM elections WHERE election_id = ?')->execute([$electionId]);
    audit((int) $me['user_id'], 'election.deleted', 'election', $electionId, 'Deleted election ' . $subject);
    json_out(['ok' => true, 'deleted' => true]);
}

/* ═══════════════════════════════════════════════════════════
   positions — GET ?election=<id> | POST | DELETE {id}
   ═══════════════════════════════════════════════════════════ */
function admin_positions(array $args): never
{
    $method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $electionId = (int) ($_GET['election'] ?? 0);

    if ($method === 'GET') {
        /* ?all=1 — every position on record, each tagged with its election.
           Candidate Management needs this: it lists candidates from all
           elections, so the position picker must cover all of them too. */
        if (!empty($_GET['all'])) {
            json_out(db()->query(
                'SELECT p.*, e.title AS election_title
                   FROM positions p
                   JOIN elections e ON e.election_id = p.election_id
                  ORDER BY e.start_datetime DESC, p.sort_order, p.position_id'
            )->fetchAll());
        }

        if (!$electionId) {
            $e = vt_current_election();
            $electionId = $e ? (int) $e['election_id'] : 0;
        }
        json_out($electionId ? vt_positions($electionId) : []);
    }

    require_writer();
    $me = current_user();

    if ($method === 'POST') {
        $electionId = (int) input('electionId', $electionId);
        $name       = input_str('name');
        $desc       = input_str('description');

        if (!$electionId) json_error('Please choose an election.', 422);
        if ($name === '') json_error('Please enter a position name.', 422, ['fields' => ['name' => 'Required.']]);
        if (mb_strlen($name, 'UTF-8') > 100) json_error('Use at most 100 characters for the position name.', 422,
            ['fields' => ['name' => 'Too long.']]);

        $order = (int) db()->query(
            "SELECT COALESCE(MAX(sort_order),0)+1 FROM positions WHERE election_id = $electionId"
        )->fetchColumn();

        try {
            db()->prepare(
                'INSERT INTO positions (election_id, position_name, description, max_vote, sort_order)
                 VALUES (?,?,?,1,?)'
            )->execute([$electionId, $name, $desc ?: null, $order]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                json_error('That position already exists in this election.', 409);
            }
            throw $e;
        }

        $pid = (int) db()->lastInsertId();
        audit((int) $me['user_id'], 'position.created', 'position', $pid, 'Added position ' . audit_subject('position', $pid));
        json_out(['ok' => true, 'id' => $pid], 201);
    }

    if ($method === 'DELETE' && ($id = (int) ($args[0] ?? 0))) {
        $lookup = db()->prepare('SELECT election_id FROM positions WHERE position_id = ?');
        $lookup->execute([$id]);
        $eid = (int) $lookup->fetchColumn();
        if (!$eid) json_error('This position no longer exists. Reload the election list.', 404);
        $subject = audit_subject('position', $id);
        $error = db_transaction(static function (PDO $pdo) use ($id, $eid): ?array {
            // Use the same election lock as voting and closing before changing the ballot.
            $election = $pdo->prepare('SELECT * FROM elections WHERE election_id = ? FOR UPDATE');
            $election->execute([$eid]);
            $row = $election->fetch();
            if (!$row) return ['This election no longer exists.', 404];
            if (election_status($row) === 'Closed') return ['Reopen the election before deleting a position.', 409];
            $positions = $pdo->prepare('SELECT position_id FROM positions WHERE election_id = ? FOR UPDATE');
            $positions->execute([$eid]);
            $ids = array_map('intval', $positions->fetchAll(PDO::FETCH_COLUMN));
            if (!in_array($id, $ids, true)) return ['This position no longer exists. Reload the election list.', 404];
            $votes = $pdo->prepare('SELECT vote_id FROM votes WHERE position_id = ? LIMIT 1 FOR UPDATE');
            $votes->execute([$id]);
            if ($votes->fetchColumn()) return ['This position already has votes and cannot be deleted.', 409];
            $candidates = $pdo->prepare('SELECT candidate_id FROM candidates WHERE position_id = ? LIMIT 1 FOR UPDATE');
            $candidates->execute([$id]);
            if ($candidates->fetchColumn()) return ['Remove or reassign this position\'s candidates in Candidate Management first.', 409];
            if (count($ids) <= 1) return ['Keep at least one position in this election.', 409];
            $pdo->prepare('DELETE FROM positions WHERE position_id = ?')->execute([$id]);
            return null;
        });
        if ($error) json_error($error[0], $error[1]);
        audit((int) $me['user_id'], 'position.deleted', 'position', $id, 'Deleted position ' . $subject);
        json_out(['ok' => true, 'deleted' => true]);
    }

    json_error('Unsupported positions request.', 405);
}

/* ═══════════════════════════════════════════════════════════
   GET monitor
   ═══════════════════════════════════════════════════════════ */
function admin_monitor(): never
{
    require_method('GET');

    $election = vt_current_election();
    if (!$election || !is_election_live($election)) {
        json_out(['election' => null, 'positionBreakdown' => [], 'recentVotes' => [], 'activityFeed' => []]);
    }

    $eid         = (int) $election['election_id'];
    $totalVoters = vt_total_voters();
    $votesCast   = vt_votes_cast($eid);

    $positionBreakdown = [];
    foreach (vt_positions($eid) as $p) {
        $s = db()->prepare('SELECT COUNT(*) FROM votes WHERE position_id = ?');
        $s->execute([(int) $p['position_id']]);
        $positionBreakdown[] = [
            'name'        => $p['position_name'],
            'votesCast'   => (int) $s->fetchColumn(),
            'totalVoters' => $totalVoters,
        ];
    }

    /*
     * Recent ballots: who voted and when. The position column deliberately
     * reads "Complete ballot" — linking a member to their choices would
     * break ballot secrecy, and `votes` stores no user_id to allow it.
     */
    $s = db()->prepare(
        'SELECT b.ballot_id, b.voted_at, m.full_name, m.member_no
           FROM ballots b JOIN members m ON m.user_id = b.user_id
          WHERE b.election_id = ? AND b.has_voted = 1
          ORDER BY b.voted_at DESC LIMIT 12'
    );
    $s->execute([$eid]);

    $recentVotes = [];
    foreach ($s->fetchAll() as $r) {
        $recentVotes[] = [
            'id'       => (int) $r['ballot_id'],
            'name'     => $r['full_name'],
            'memberId' => $r['member_no'] ?? '—',
            'position' => 'Complete ballot',
            'time'     => fmt_relative($r['voted_at']),
            'status'   => 'Verified',
        ];
    }

    $activityFeed = [];
    foreach (db()->query(
        "SELECT a.action, a.description, a.timestamp
           FROM audit_logs a
           JOIN users u ON u.user_id = a.user_id
           JOIN roles r ON r.role_id = u.role_id
          WHERE r.slug = 'admin'
          ORDER BY a.log_id DESC LIMIT 10"
    )->fetchAll() as $r) {
        $activityFeed[] = [
            'type'  => audit_type($r['action']),
            'title' => audit_event_text($r),
            'time'  => fmt_relative($r['timestamp']),
        ];
    }

    json_out([
        'election' => [
            'id'          => $eid,
            'title'       => $election['title'],
            'startDate'   => fmt_datetime($election['start_datetime']),
            'endDate'     => fmt_datetime($election['end_datetime']),
            'endDateISO'  => fmt_iso($election['end_datetime']),
            'totalVoters' => $totalVoters,
            'votesCast'   => $votesCast,
            'positions'   => count($positionBreakdown),
        ],
        'positionBreakdown' => $positionBreakdown,
        'recentVotes'       => $recentVotes,
        'activityFeed'      => $activityFeed,
    ]);
}

/* ═══════════════════════════════════════════════════════════
   GET results
   ═══════════════════════════════════════════════════════════ */
function admin_results(array $args): never
{
    if (($args[0] ?? '') === 'export') admin_results_export();
    require_method('GET');

    /*
     * Elections that actually have something to show come first:
     * released results, then anything with votes counted, then newest.
     */
    $rows = db()->query(
        'SELECT e.* FROM elections e
          ORDER BY (e.results_released_at IS NULL),
                   ((SELECT COUNT(*) FROM votes v WHERE v.election_id = e.election_id) = 0),
                   e.start_datetime DESC'
    )->fetchAll();

    $elections = [];

    foreach ($rows as $e) {
        $eid    = (int) $e['election_id'];
        $status = election_status($e);

        $elections[] = [
            'id'          => $eid,
            'title'       => $e['title'],
            /* Officers see live tallies; the UI marks them Final once released. */
            'status'      => !empty($e['results_released_at']) ? 'Final' : ($status === 'Active' ? 'Live' : $status),
            'published'   => !empty($e['results_released_at']),
            'totalVoters' => vt_total_voters(),
            'votesCast'   => vt_votes_cast($eid),
            'startDate'   => fmt_date($e['start_datetime']),
            'endDate'     => fmt_date($e['end_datetime']),
            'positions'   => vt_grouped_positions($eid, true, true, true),
        ];
    }

    json_out(['elections' => $elections]);
}

function admin_results_export(): never
{
    require_method('GET');
    $eid = (int) ($_GET['election'] ?? 0);
    if (!$eid) {
        $e   = vt_current_election();
        $eid = $e ? (int) $e['election_id'] : 0;
    }
    if (!$eid) json_error('No election to export.', 404);

    $rows = [];
    foreach (vt_grouped_positions($eid, true, true, true) as $pos) {
        $rank = 1;
        foreach ($pos['candidates'] as $c) {
            $rows[] = [$pos['name'], $rank, trim($c['first'] . ' ' . $c['last']), $c['votes'], $rank === 1 ? 'Winner' : ''];
            $rank++;
        }
    }
    csv_out('coopvote-results.csv', ['Position', 'Rank', 'Candidate', 'Votes', 'Result'], $rows);
}

/* ═══════════════════════════════════════════════════════════
   GET reports?election=<id>
   ═══════════════════════════════════════════════════════════ */
function admin_reports(): never
{
    require_method('GET');
    election_reports_due();

    $all = db()->query(
        'SELECT election_id, title, start_datetime, end_datetime
           FROM elections ORDER BY start_datetime DESC'
    )->fetchAll();

    /* Plain dates travel with each entry so the page can offer a date filter. */
    $list = array_map(
        static fn($e) => [
            'id'    => (int) $e['election_id'],
            'title' => $e['title'],
            'start' => date('Y-m-d', strtotime((string) $e['start_datetime'])),
            'end'   => date('Y-m-d', strtotime((string) $e['end_datetime'])),
        ],
        $all
    );

    $eid = (int) ($_GET['election'] ?? 0);
    if (!$eid) {
        $cur = vt_current_election();
        $eid = $cur ? (int) $cur['election_id'] : (int) ($all[0]['election_id'] ?? 0);
    }

    if (!$eid) {
        json_out(['elections' => $list, 'selected' => null]);
    }

    $s = db()->prepare('SELECT * FROM elections WHERE election_id = ? LIMIT 1');
    $s->execute([$eid]);
    $e = $s->fetch();
    if (!$e) json_out(['elections' => $list, 'selected' => null]);

    $totalVoters = vt_total_voters();
    $grouped     = vt_grouped_positions($eid, true, true, true);

    $positionBreakdown = [];
    $candidateResults  = [];

    foreach ($grouped as $pos) {
        $posVotes = array_sum(array_column($pos['candidates'], 'votes'));
        $positionBreakdown[] = [
            'name'        => $pos['name'],
            'votesCast'   => $posVotes,
            'totalVoters' => $totalVoters,
        ];

        $rank = 1;
        foreach ($pos['candidates'] as $c) {
            $candidateResults[] = [
                'rank'            => $rank,
                'name'            => trim($c['first'] . ' ' . $c['last']),
                'position'        => $pos['name'],
                'votes'           => $c['votes'],
                'totalInPosition' => max($posVotes, 1),
                'winner'          => $rank === 1 && $c['votes'] > 0,
            ];
            $rank++;
        }
    }

    $startTs  = strtotime((string) $e['start_datetime']);
    $endTs    = strtotime((string) $e['end_datetime']);
    $days     = max(1, (int) round(($endTs - $startTs) / 86400));

    json_out([
        'elections' => $list,
        'selected'  => [
            'id'                => $eid,
            'title'             => $e['title'],
            'startDate'         => fmt_date($e['start_datetime']),
            'endDate'           => fmt_date($e['end_datetime']),
            'totalVoters'       => $totalVoters,
            'votesCast'         => vt_votes_cast($eid),
            'candidates'        => count(vt_candidates($eid)),
            'duration'          => $days . ' day' . ($days === 1 ? '' : 's'),
            'status'            => election_status($e),
            'positionBreakdown' => $positionBreakdown,
            'candidateResults'  => $candidateResults,
        ],
    ]);
}

/* ═══════════════════════════════════════════════════════════
   security — GET | GET logs/export | POST block-ip | DELETE block-ip
   ═══════════════════════════════════════════════════════════ */
function admin_security(array $args): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $first  = $args[0] ?? '';

    if ($first === 'review-alert') security_review_alert();

    if ($first === 'block-ip') {
        require_writer();
        $me = current_user();
        $ip = input_str('ip');
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            json_error('Please supply a valid IP address.', 422);
        }

        if ($method === 'DELETE') {
            db()->prepare('DELETE FROM blocked_ips WHERE ip_address = ?')->execute([$ip]);
            audit((int) $me['user_id'], 'security.ip_unblocked', 'ip', null, 'Unblocked ' . $ip);
            json_out(['ok' => true, 'blocked' => false]);
        }

        require_method('POST');
        if ($ip === client_ip()) {
            json_error('That is your own address — blocking it would lock you out.', 409);
        }

        db()->prepare(
            'INSERT INTO blocked_ips (ip_address, reason, blocked_by) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), blocked_by = VALUES(blocked_by)'
        )->execute([$ip, input_str('reason') ?: 'Blocked by administrator', (int) $me['user_id']]);

        audit((int) $me['user_id'], 'security.ip_blocked', 'ip', null, 'Blocked ' . $ip);
        json_out(['ok' => true, 'blocked' => true], 201);
    }

    if ($first === 'logs' && ($args[1] ?? '') === 'export') {
        require_method('GET');
        $rows = [];
        foreach (security_logs(500) as $l) {
            $rows[] = [$l['time'], $l['type'], $l['event'], $l['ip'], $l['flagged'] ? 'FLAGGED' : ''];
        }
        csv_out('coopvote-security-logs.csv', ['Time', 'Type', 'Event', 'IP Address', 'Flag'], $rows);
    }

    require_method('GET');
    $pdo = db();

    $votesVerified = (int) $pdo->query('SELECT COUNT(*) FROM ballots WHERE has_voted = 1')->fetchColumn();
    $blockedIPs    = (int) $pdo->query('SELECT COUNT(*) FROM blocked_ips')->fetchColumn();
    /* Counted the same way security_logs() selects, so the figure on the
       card matches the list underneath it rather than the whole table. */
    $totalLogs     = (int) $pdo->query(
        "SELECT COUNT(*)
           FROM audit_logs a
           JOIN users u ON u.user_id = a.user_id
           JOIN roles r ON r.role_id = u.role_id
          WHERE r.slug = 'admin'"
    )->fetchColumn();
    $failedLogins  = (int) $pdo->query(
        "SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
    )->fetchColumn();
    $loginAttempts = (int) $pdo->query(
        "SELECT COUNT(*) FROM login_attempts WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
    )->fetchColumn();
    /* This card sits over the list, so it counts what the list can show.
       Failures on member accounts are covered by Failed Logins below, which
       reads login_attempts and still sees every account. */
    $flagged = (int) $pdo->query(
        "SELECT COUNT(*)
           FROM audit_logs a
           JOIN users u ON u.user_id = a.user_id
           JOIN roles r ON r.role_id = u.role_id
          WHERE r.slug = 'admin'
            AND a.action IN ('auth.login_failed','auth.password_change_failed','security.ip_blocked')"
    )->fetchColumn();

    $suspicious = security_alerts();

    $threats = array_map(
        static fn($s) => ['ip' => $s['ip'], 'message' => $s['attempts'] . ' failed sign-in attempts in the last 24 hours'],
        array_slice(array_values(array_filter($suspicious, static fn($a) => !$a['reviewed'])), 0, 3)
    );

    /*
     * Duplicate-vote detection: the UNIQUE key on ballots makes a second
     * ballot impossible, so anything here means data was edited directly.
     */
    $duplicateVotes = (int) $pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT user_id, election_id FROM ballots
             WHERE has_voted = 1 GROUP BY user_id, election_id HAVING COUNT(*) > 1
         ) d'
    )->fetchColumn();

    /* 100 minus deductions for unresolved risk. */
    $score = 100;
    $score -= min(20, $failedLogins);
    $score -= count(array_filter($suspicious, static fn($a) => !$a['reviewed'])) * 5;
    $score -= $duplicateVotes * 25;
    $score = max(0, min(100, $score));

    /*
     * There is deliberately no voter log here. This page listed one — every
     * member who had voted, by name, with the time, their IP address and
     * their device — which is members' activity on a page that is meant to
     * show the back office's own. Turnout still has a home: Vote Monitoring
     * carries the same record, next to the count it belongs with.
     */

    json_out([
        'stats' => [
            'votesVerified' => $votesVerified,
            'blockedIPs'    => $blockedIPs,
            'flaggedEvents' => $flagged,
            'loginAttempts' => $loginAttempts,
            'totalLogs'     => $totalLogs,
            'securityScore' => $score,
        ],
        'threats'       => $threats,
        'suspiciousIPs' => $suspicious,
        'integrity'     => [
            'duplicateVotes' => $duplicateVotes,
            'votesVerified'  => $votesVerified,
            'sessionHijacks' => null,
            'failedLogins'   => $failedLogins,
            'blockedIPs'     => $blockedIPs,
        ],
        'logs' => security_logs(120),
    ]);
}

/**
 * The audit trail for the Security & Logs page.
 *
 * The back office's own record, and nothing else: what an administrator
 * did. A member's activity is not listed here — not
 * their sign-ins, not their sign-outs, not their ballots, and not a refused
 * sign-in against their account either. All of it is still written to
 * audit_logs; this page is simply not where it is read.
 *
 * Refused sign-ins on member accounts used to be kept, on the argument that
 * a failed sign-in is not necessarily the member's own doing. The
 * cooperative asked for administrative actions only, so they are gone from
 * the list. What they were there to warn about has not left the page: the
 * Failed Logins figure, the Suspicious IPs panel and the security score are
 * counted from login_attempts, name no member, and still cover every
 * account on the system.
 *
 * The joins are inner ones on purpose. "There is a back-office user behind
 * this row" is the whole rule, so a row with no account at all — what an
 * attempt against a username that does not exist looks like — is left out
 * by the same stroke.
 */
function security_logs(int $limit = 120): array
{
    $s = db()->prepare(
        "SELECT a.action, a.description, a.ip_address, a.user_agent, a.timestamp,
                u.username, m.full_name, r.role_name, r.slug AS role_slug
           FROM audit_logs a
           JOIN users u ON u.user_id = a.user_id
           JOIN roles r ON r.role_id = u.role_id
           LEFT JOIN members m ON m.user_id = a.user_id
          WHERE r.slug = 'admin'
          ORDER BY a.log_id DESC
          LIMIT ?"
    );

    $s->bindValue(1, $limit, PDO::PARAM_INT);
    $s->execute();

    $out = [];
    foreach ($s->fetchAll() as $r) {
        $flagged = in_array($r['action'], [
            'auth.login_failed', 'auth.password_change_failed', 'security.ip_blocked',
        ], true);

        /* Who did it. An administrator reading an audit trail needs this
           more than anything else on the row, and it was not there. */
        $who = $r['full_name'] ?: $r['username'];

        $out[] = [
            'type'    => audit_type($r['action']),
            'event'   => audit_event_text($r),
            'time'    => fmt_datetime($r['timestamp']),
            'ip'      => $r['ip_address'] ?: '—',
            'userAgent' => trim((string) ($r['user_agent'] ?? '')),
            'actor'   => $who ?: 'Not signed in',
            'role'    => $r['role_name'] ?: '—',
            'flagged' => $flagged,
        ];
    }
    return $out;
}

/* ═══════════════════════════════════════════════════════════
   announcements — GET | POST | DELETE {id}
   ═══════════════════════════════════════════════════════════ */
function admin_announcements(array $args): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        json_out(db()->query(
            'SELECT announcement_id AS id, title, message, is_active AS active, created_at
               FROM announcements ORDER BY created_at DESC'
        )->fetchAll());
    }

    require_writer();
    $me = current_user();

    if ($method === 'POST') {
        $title   = input_str('title');
        $message = input_str('message');
        if ($title === '' || $message === '') {
            json_error('A title and message are both required.', 422);
        }

        /* One active notice at a time keeps the dashboard banner predictable. */
        db()->query('UPDATE announcements SET is_active = 0');
        db()->prepare(
            'INSERT INTO announcements (title, message, is_active, created_by) VALUES (?,?,1,?)'
        )->execute([$title, $message, (int) $me['user_id']]);

        $id = (int) db()->lastInsertId();
        audit((int) $me['user_id'], 'announcement.created', 'announcement', $id, 'Posted notice "' . $title . '"');
        json_out(['ok' => true, 'id' => $id], 201);
    }

    if ($method === 'DELETE' && ($id = (int) ($args[0] ?? 0))) {
        $subject = audit_subject('announcement', $id);
        db()->prepare('DELETE FROM announcements WHERE announcement_id = ?')->execute([$id]);
        audit((int) $me['user_id'], 'announcement.deleted', 'announcement', $id, 'Removed notice ' . $subject);
        json_out(['ok' => true, 'deleted' => true]);
    }

    json_error('Unsupported announcements request.', 405);
}

/* ═══════════════════════════════════════════════════════════
   settings — GET read | PUT update
   ═══════════════════════════════════════════════════════════ */
function admin_settings(): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        json_out(['ok' => true, 'settings' => coop_settings()]);
    }

    require_method('PUT', 'POST');
    require_writer();
    $me = current_user();

    /* Only these keys may be written — the table is not a free-for-all. */
    $allowed = ['coop_name', 'coop_short', 'support_email'];
    $changed = [];

    foreach ($allowed as $key) {
        $value = input($key, null);
        if ($value === null) {
            continue;
        }
        $value = trim((string) $value);

        if ($key === 'support_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            json_error('That support address is not a valid email address.', 422,
                ['fields' => ['support_email' => 'Enter a valid email address.']]);
        }
        if ($key === 'coop_name' && $value === '') {
            json_error('The cooperative name cannot be empty.', 422,
                ['fields' => ['coop_name' => 'Required.']]);
        }

        db()->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        )->execute([$key, $value]);

        $changed[] = $key;
    }

    if (!$changed) {
        json_error('Nothing to update.', 422);
    }

    audit((int) $me['user_id'], 'settings.updated', 'settings', null,
        'Updated cooperative settings: ' . implode(', ', $changed));

    json_out(['ok' => true, 'updated' => $changed, 'settings' => coop_settings()]);
}

/* ═══════════════════════════════════════════════════════════
   CSV helper
   ═══════════════════════════════════════════════════════════ */
function csv_out(string $filename, array $header, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");            // BOM so Excel reads UTF-8
    // Prevent spreadsheet formulas in user-supplied names/titles. Numeric values stay numeric.
    $safe = static fn($value) => is_string($value) && preg_match('/^[\s\x00-\x1f]*[=+@-]|^[\t\r\n]/u', $value) ? "'" . $value : $value;
    if ($header) fputcsv($out, array_map($safe, $header), ',', '"', '', "\r\n");
    foreach ($rows as $r) {
        fputcsv($out, array_map($safe, $r), ',', '"', '', "\r\n");
    }
    fclose($out);
    exit;
}

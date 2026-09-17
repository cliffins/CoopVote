<?php
/**
 * CoopVote — member-facing routes
 *   GET voter/dashboard   GET voter/election    GET voter/candidates
 *   GET voter/ballot      POST voter/vote       GET voter/results  GET voter/receipts
 *   GET|PUT voter/profile POST voter/change-password
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/profile-photo.php';
require_once __DIR__ . '/../../includes/vote-integrity.php';

function handle_voter(array $args): never
{
    $action = $args[0] ?? '';

    match ($action) {
        'elections'       => voter_route_elections(),
        'dashboard'       => voter_route_dashboard(),
        'election'        => voter_route_election(),
        'candidates'      => voter_route_candidates(),
        'ballot'          => voter_route_ballot(),
        'vote'            => voter_route_vote(),
        'results'         => voter_route_results(),
        'receipts'        => voter_route_receipts(),
        'profile'         => voter_route_profile(),
        'profile-photo'   => voter_route_profile_photo(array_slice($args, 1)),
        'change-password' => voter_route_change_password(),
        default           => json_error('That page or action does not exist.', 404, ['code' => 'UNKNOWN_ROUTE', 'endpoint' => $action]),
    };
}

/* ═══════════════════════════════════════════════════════════
   SHARED QUERIES
   ═══════════════════════════════════════════════════════════ */

/** Number of members eligible to vote. */
function vt_total_voters(): int
{
    return (int) db()->query(
        "SELECT COUNT(*)
           FROM users u
           JOIN roles r ON r.role_id = u.role_id
          WHERE r.slug = 'voter' AND u.status = 'active'"
    )->fetchColumn();
}

/** Ballots already cast in one election. */
function vt_votes_cast(int $electionId): int
{
    $s = db()->prepare('SELECT COUNT(*) FROM ballots WHERE election_id = ? AND has_voted = 1');
    $s->execute([$electionId]);
    return (int) $s->fetchColumn();
}

/** Every published election, newest first. */
function vt_published_elections(): array
{
    return db()->query(
        "SELECT * FROM elections WHERE status = 'published' ORDER BY start_datetime DESC"
    )->fetchAll();
}

/** Every election open for voting right now — there may be several. */
function vt_live_elections(): array
{
    return array_values(array_filter(vt_published_elections(), 'is_election_live'));
}

/**
 * The election a member should currently see: the live one if there is
 * one, otherwise the next upcoming, otherwise the most recent closed.
 */
function vt_current_election(): ?array
{
    $rows = vt_published_elections();

    foreach ($rows as $r) if (election_status($r) === 'Active')   return $r;
    foreach ($rows as $r) if (election_status($r) === 'Upcoming') return $r;
    return $rows[0] ?? null;
}

/**
 * The election this request is about.
 *
 * Several elections can run at once, so the member chooses which ballot
 * they are looking at; ?election=<id> carries that choice. An unknown or
 * unpublished id falls back to the default rather than erroring, so a
 * stale bookmark still lands somewhere sensible.
 */
function vt_selected_election(): ?array
{
    $requested = (int) ($_GET['election'] ?? 0);

    if ($requested > 0) {
        foreach (vt_published_elections() as $e) {
            if ((int) $e['election_id'] === $requested) {
                return $e;
            }
        }
    }
    return vt_current_election();
}

/**
 * The election a member can act on right now: open for voting, or about to
 * be. A finished election is not one of these.
 *
 * vt_selected_election() deliberately falls back to the most recent closed
 * election, because the Results page needs something to show. Every page
 * that speaks of the *active* election has to answer "none" instead — or a
 * finished election turns up under a heading reading "Active Election",
 * which is exactly what the election page did: it drew a closed count as
 * ELECTION LIVE, with the countdown stopped at 00:00:00 and the ballot
 * still laid out below it.
 */
function vt_open_election(): ?array
{
    $e = vt_selected_election();
    if ($e === null) {
        return null;
    }
    return in_array(election_status($e), ['Active', 'Upcoming'], true) ? $e : null;
}

/** The member's ballot row for an election, or null. */
function vt_ballot(int $userId, int $electionId): ?array
{
    $s = db()->prepare('SELECT * FROM ballots WHERE user_id = ? AND election_id = ? LIMIT 1');
    $s->execute([$userId, $electionId]);
    return $s->fetch() ?: null;
}

/** True when this member has already voted in the election. */
function vt_has_voted(int $userId, int $electionId): bool
{
    $b = vt_ballot($userId, $electionId);
    return $b !== null && (int) $b['has_voted'] === 1;
}

/** Positions of an election, in display order. */
function vt_positions(int $electionId): array
{
    $s = db()->prepare(
        'SELECT * FROM positions WHERE election_id = ? ORDER BY sort_order, position_id'
    );
    $s->execute([$electionId]);
    return $s->fetchAll();
}

/**
 * Positions a member can actually vote on — those with at least one
 * active candidate. "Positions Available" counting empty seats told
 * members there were 5 things to vote for when only 1 could be chosen.
 */
function vt_contested_count(int $electionId): int
{
    $s = db()->prepare(
        'SELECT COUNT(DISTINCT p.position_id)
           FROM positions p
           JOIN candidates c ON c.position_id = p.position_id AND c.is_active = 1
          WHERE p.election_id = ?'
    );
    $s->execute([$electionId]);
    return (int) $s->fetchColumn();
}

/**
 * Candidates of an election joined with their member record.
 * `$withVotes` adds a tally column — only use it once results may be shown.
 */
function vt_candidates(int $electionId, bool $withVotes = false): array
{
    $votes = $withVotes
        ? '(SELECT COUNT(*) FROM votes v WHERE v.candidate_id = c.candidate_id)'
        : '0';

    $s = db()->prepare(
        "SELECT c.candidate_id, c.member_id, c.position_id, c.platform, c.bio,
                c.photo_url, c.sort_order, c.is_active,
                m.full_name, m.member_no, m.occupation, m.years_in_coop, m.date_of_birth,
                m.photo_url AS member_photo,
                p.position_name, p.sort_order AS position_order,
                $votes AS vote_count
           FROM candidates c
           JOIN members   m ON m.member_id   = c.member_id
           JOIN positions p ON p.position_id = c.position_id
          WHERE c.election_id = ?
          ORDER BY p.sort_order, p.position_id, c.sort_order, c.candidate_id"
    );
    $s->execute([$electionId]);
    return $s->fetchAll();
}

/** Shape one candidate row the way the UI expects. */
function vt_candidate_dto(array $c, bool $withVotes = false): array
{
    $n   = split_name((string) $c['full_name']);
    $dto = [
        'id'          => (int) $c['candidate_id'],
        'first'       => $n['first'],
        'last'        => $n['last'],
        'name'        => $c['full_name'],
        'position'    => $c['position_name'],
        'age'         => age_from_dob($c['date_of_birth'] ?? null),
        'occ'         => $c['occupation'] ?: 'Cooperative Member',
        'bio'         => $c['bio'] ?: '',
        'platform'    => $c['platform'] ?: '',
        'photoUrl'    => $c['photo_url'] ?: profile_photo_url($c['member_photo'] ?: null, (int) $c['member_id']),
        'yearsInCoop' => $c['years_in_coop'] !== null ? (int) $c['years_in_coop'] : null,
        'status'      => ((int) ($c['is_active'] ?? 1)) === 1 ? 'Active' : 'Inactive',
    ];
    if ($withVotes) {
        $dto['votes'] = (int) $c['vote_count'];
    }
    return $dto;
}

/**
 * Group candidates under their positions: [{ name, candidates: [...] }].
 * `$onlyContested` drops seats nobody stood for — results pages have
 * nothing to report for them, though the ballot still lists them so the
 * member can see why they are being skipped.
 */
function vt_grouped_positions(
    int $electionId,
    bool $withVotes = false,
    bool $sortByVotes = false,
    bool $onlyContested = false
): array {
    $positions = vt_positions($electionId);
    $cands     = vt_candidates($electionId, $withVotes);

    $byPosition = [];
    foreach ($cands as $c) {
        if ((int) ($c['is_active'] ?? 1) !== 1) continue;
        $byPosition[(int) $c['position_id']][] = vt_candidate_dto($c, $withVotes);
    }

    $out = [];
    foreach ($positions as $p) {
        $list = $byPosition[(int) $p['position_id']] ?? [];
        if ($onlyContested && $list === []) {
            continue;
        }
        if ($sortByVotes) {
            usort($list, static fn($a, $b) => $b['votes'] <=> $a['votes']);
        }
        $out[] = [
            'id'         => (int) $p['position_id'],
            'name'       => $p['position_name'],
            'candidates' => $list,
        ];
    }
    return $out;
}

/** The member block every voter page renders in its sidebar. */
function vt_member_dto(array $u, ?array $election): array
{
    $name     = (string) ($u['full_name'] ?? $u['username']);
    $hasVoted = $election ? vt_has_voted((int) $u['user_id'], (int) $election['election_id']) : false;

    return [
        'name'        => $name,
        'memberId'    => $u['member_no'] ?? '—',
        'email'       => $u['email'],
        'status'      => ucfirst((string) ($u['membership_status'] ?? $u['status'])),
        'memberSince' => fmt_month_year($u['registered_at'] ?? $u['created_at']),
        'hasVoted'    => $hasVoted,
    ];
}

/* ═══════════════════════════════════════════════════════════
   GET voter/elections
   ─────────────────────────────────────────────────────────
   The elections this member may open, so the pages can offer a
   choice when more than one is running at the same time.
   ═══════════════════════════════════════════════════════════ */
function voter_route_elections(): never
{
    require_method('GET');
    $u      = require_voter();
    $userId = (int) $u['user_id'];

    $selected = vt_selected_election();
    $out      = [];

    foreach (vt_published_elections() as $e) {
        $eid    = (int) $e['election_id'];
        $status = election_status($e);

        /* Closed elections stay listed only once their results are out. */
        if ($status !== 'Active' && $status !== 'Upcoming' && empty($e['results_released_at'])) {
            continue;
        }

        $out[] = [
            'id'         => $eid,
            'title'      => $e['title'],
            'status'     => $status,
            'live'       => $status === 'Active',
            'startDate'  => fmt_datetime($e['start_datetime']),
            'endDate'    => fmt_datetime($e['end_datetime']),
            'endDateISO' => fmt_iso($e['end_datetime']),
            'positions'  => vt_contested_count($eid),
            'candidates' => count(vt_candidates($eid)),
            'hasVoted'   => vt_has_voted($userId, $eid),
            'published'  => !empty($e['results_released_at']),
            'selected'   => $selected && (int) $selected['election_id'] === $eid,
        ];
    }

    json_out([
        'elections' => $out,
        'liveCount' => count(array_filter($out, static fn($e) => $e['live'])),
        'selectedId' => $selected ? (int) $selected['election_id'] : null,
    ]);
}

/* ═══════════════════════════════════════════════════════════
   GET voter/dashboard
   ═══════════════════════════════════════════════════════════ */
function voter_route_dashboard(): never
{
    require_method('GET');
    $u = require_voter();

    /*
     * Two different elections are in play here. The card at the top and the
     * candidate strip under it describe an election a member can still do
     * something about, so they take the open one. The released results
     * further down use an eligible released election independently, so an
     * active election cannot hide results from a completed one.
     */
    $election = vt_selected_election();
    $open     = vt_open_election();
    $member   = vt_member_dto($u, $election);

    $electionDto = null;
    $candidates  = [];
    $results     = null;
    $resultsElection = null;

    if ($open) {
        $eid   = (int) $open['election_id'];
        $live  = is_election_live($open);
        $cands = vt_candidates($eid, true);

        $electionDto = [
            'id'          => $eid,
            'title'       => $open['title'],
            'status'      => election_status($open),
            'startDate'   => fmt_datetime($open['start_datetime']),
            'endDate'     => fmt_datetime($open['end_datetime']),
            'endDateISO'  => $live ? fmt_iso($open['end_datetime']) : null,
            /* Only seats that are actually contested — see vt_contested_count. */
            'positions'   => vt_contested_count($eid),
            'candidates'  => count($cands),
            'totalVoters' => vt_total_voters(),
            'votesCast'   => vt_votes_cast($eid),
        ];

        /* The strip this feeds is headed "N candidates running", so it has
           to be empty when nobody is running. */
        foreach ($cands as $c) {
            $dto = vt_candidate_dto($c);
            $candidates[] = [
                'id'       => $dto['id'],
                'first'    => $dto['first'],
                'last'     => $dto['last'],
                'position' => $dto['position'],
                'photoUrl' => $dto['photoUrl'],
            ];
        }
    }

    $released = db()->prepare(
        "SELECT e.* FROM elections e
          WHERE e.status = 'published' AND e.results_released_at IS NOT NULL
            AND EXISTS (SELECT 1 FROM ballots b WHERE b.election_id = e.election_id
                        AND b.user_id = ? AND b.has_voted = 1)
          ORDER BY (e.election_id = ?) DESC, e.results_released_at DESC, e.election_id DESC
          LIMIT 1"
    );
    $released->execute([(int) $u['user_id'], (int) ($election['election_id'] ?? 0)]);
    $resultElection = $released->fetch();
    if ($resultElection) {
        $eid = (int) $resultElection['election_id'];

        /*
         * Winners, but only once the results have been released — and
         * only for a member who actually voted in this election. The
         * dashboard was handing the whole winners list, vote counts and
         * all, to members who never cast a ballot in it.
         */
        if (vt_has_voted((int) $u['user_id'], $eid)) {
            $resultsElection = ['id' => $eid, 'title' => $resultElection['title']];
            $results = [];
            foreach (vt_grouped_positions($eid, true, true, true) as $pos) {
                if (!empty($pos['candidates'])) {
                    $top = $pos['candidates'][0];
                    $results[] = [
                        'rank'     => count($results) + 1,
                        'name'     => trim($top['first'] . ' ' . $top['last']),
                        'position' => $pos['name'],
                        'votes'    => $top['votes'],
                    ];
                }
            }
        }
    }

    /* Most recent active announcement. */
    $ann = db()->query(
        'SELECT title, message FROM announcements
          WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1'
    )->fetch() ?: null;

    json_out([
        'member'       => $member,
        'election'     => $electionDto,
        'candidates'   => $candidates,
        'results'      => $results,
        'resultsElection' => $resultsElection,
        'announcement' => $ann ? ['title' => $ann['title'], 'message' => $ann['message']] : null,
    ]);
}

/* ═══════════════════════════════════════════════════════════
   GET voter/election
   ═══════════════════════════════════════════════════════════ */
function voter_route_election(): never
{
    require_method('GET');
    $u = require_voter();

    /* The page is titled "Active Election", so a closed one has no business
       on it — it belongs on the Results page instead. */
    $election    = vt_open_election();
    $electionDto = null;

    if ($election) {
        $eid  = (int) $election['election_id'];
        $live = is_election_live($election);
        $electionDto = [
            'id'          => $eid,
            'title'       => $election['title'],
            'description' => $election['description'] ?: '',
            'status'      => election_status($election),
            'live'        => $live,
            'startDate'   => fmt_datetime($election['start_datetime']),
            'endDate'     => fmt_datetime($election['end_datetime']),
            /* No countdown on an election that has not opened: "Closes In"
               over a start date still in the future reads as nonsense. */
            'endDateISO'  => $live ? fmt_iso($election['end_datetime']) : null,
            'totalVoters' => vt_total_voters(),
            'votesCast'   => vt_votes_cast($eid),
            'candidates'  => count(vt_candidates($eid)),
            'positions'   => vt_grouped_positions($eid),
        ];
    }

    json_out([
        'member'   => vt_member_dto($u, $election),
        'election' => $electionDto,
    ]);
}

/* ═══════════════════════════════════════════════════════════
   GET voter/candidates
   ═══════════════════════════════════════════════════════════ */
function voter_route_candidates(): never
{
    require_method('GET');
    $u = require_voter();

    /* "N candidates running" is a claim about an election in progress, so a
       finished one lists nobody. */
    $election   = vt_open_election();
    $candidates = [];

    if ($election) {
        foreach (vt_candidates((int) $election['election_id']) as $c) {
            if ((int) ($c['is_active'] ?? 1) !== 1) continue;
            $candidates[] = vt_candidate_dto($c);
        }
    }

    json_out([
        'member'     => vt_member_dto($u, $election),
        'election'   => $election ? [
            'title'  => $election['title'],
            'active' => is_election_live($election),
        ] : null,
        'candidates' => $candidates,
    ]);
}

/* ═══════════════════════════════════════════════════════════
   GET voter/ballot
   ═══════════════════════════════════════════════════════════ */
function voter_route_ballot(): never
{
    require_method('GET');
    $u = require_voter();

    $election = vt_selected_election();

    /* Only a live election yields a usable ballot. */
    if (!$election || !is_election_live($election)) {
        json_out([
            'member'    => vt_member_dto($u, $election),
            'election'  => null,
            'positions' => [],
        ]);
    }

    $eid = (int) $election['election_id'];

    /* Issue the ballot record on first visit (idempotent). */
    db()->prepare(
        'INSERT IGNORE INTO ballots (election_id, user_id, has_voted) VALUES (?,?,0)'
    )->execute([$eid, (int) $u['user_id']]);

    json_out([
        'member'   => vt_member_dto($u, $election),
        'election' => [
            'id'        => $eid,
            'title'     => $election['title'],
            'startDate' => fmt_datetime($election['start_datetime']),
            'endDate'   => fmt_datetime($election['end_datetime']),
            'active'    => true,
        ],
        'positions' => vt_grouped_positions($eid),
    ]);
}

/* ═══════════════════════════════════════════════════════════
   POST voter/vote
   ═══════════════════════════════════════════════════════════ */
function voter_route_vote(): never
{
    require_method('POST');
    $u = require_voter();

    $userId     = (int) $u['user_id'];
    $electionId = (int) input('electionId', 0);
    $votes      = input('votes', []);

    if (!is_array($votes) || $votes === []) {
        json_error('Your ballot is empty. Please choose at least one candidate.');
    }

    $pdo = db();

    $s = $pdo->prepare('SELECT * FROM elections WHERE election_id = ? LIMIT 1');
    $s->execute([$electionId]);
    $election = $s->fetch();

    if (!$election)                  json_error('That election does not exist.', 404);
    if (!is_election_live($election)) json_error('This election is not open for voting right now.', 409);

    /* Positions and candidates that legitimately belong to this election. */
    $positions = [];
    foreach (vt_positions($electionId) as $p) {
        $positions[(int) $p['position_id']] = $p;
    }

    $candidates = [];
    foreach (vt_candidates($electionId) as $c) {
        $candidates[(int) $c['candidate_id']] = $c;
    }

    /* ── Validate every selection before writing anything ── */
    $clean = [];
    foreach ($votes as $v) {
        $candidateId = (int) ($v['candidateId'] ?? 0);
        if ($candidateId === 0) continue;

        if (!isset($candidates[$candidateId])) {
            json_error('One of your selections is not a candidate in this election.', 422);
        }

        $cand       = $candidates[$candidateId];
        $positionId = (int) $cand['position_id'];

        if (!isset($positions[$positionId])) {
            json_error('One of your selections has an invalid position.', 422);
        }
        /* max_vote is 1 per position — reject a second pick for the same seat. */
        if (isset($clean[$positionId])) {
            json_error('You may only choose one candidate for ' . $positions[$positionId]['position_name'] . '.', 422);
        }
        $clean[$positionId] = $candidateId;
    }

    if ($clean === []) {
        json_error('Your ballot is empty. Please choose at least one candidate.');
    }

    $receipt = generate_receipt();

    /*
     * db_transaction() repeats the whole block if InnoDB deadlocks, which it
     * does when several members submit at the same moment. Nothing is
     * written by a rolled-back attempt, so a repeat cannot double-count: the
     * claim below is conditional on has_voted = 0 and the unique key on
     * (user_id, election_id) is the real guarantee either way.
     *
     * Refusing a member's vote outright is handled by returning false rather
     * than answering from in here — an exit inside the transaction would
     * leave the retry loop with nothing to decide.
     */
    $claimed = db_transaction(static function (PDO $pdo) use (
        $electionId, $userId, $receipt, $clean
    ): bool {
        $integrityState = integrity_lock_live_election($pdo, $electionId);
        /*
         * Claim the ballot first. The UNIQUE (user_id, election_id) key plus
         * this conditional UPDATE make double voting impossible even if two
         * requests arrive at the same instant.
         */
        $pdo->prepare(
            'INSERT IGNORE INTO ballots (election_id, user_id, has_voted) VALUES (?,?,0)'
        )->execute([$electionId, $userId]);

        $claim = $pdo->prepare(
            'UPDATE ballots
                SET has_voted = 1, receipt_code = ?, voted_at = NOW(), ip_address = ?, user_agent = ?
              WHERE user_id = ? AND election_id = ? AND has_voted = 0'
        );
        $claim->execute([$receipt, client_ip(), user_agent(), $userId, $electionId]);

        if ($claim->rowCount() === 0) {
            return false;
        }

        /*
         * Store the choices with no reference back to the voter — the votes
         * table deliberately has no user_id, so the ballot stays secret.
         */
        integrity_insert_selections($pdo, $electionId, $clean, $integrityState);

        return true;
    });

    if (!$claimed) {
        json_error('You have already voted in this election.', 409, ['code' => 'ALREADY_VOTED']);
    }

    /*
     * Log that they voted — never what they chose, and never their receipt.
     *
     * The receipt used to be written here. Audit descriptions are shown in
     * the back office activity feed and the security log, so every member's
     * receipt was on display to anyone in the back office who opened those
     * pages. It is the member's own confirmation that their ballot was
     * counted and it belongs to them alone; the audit trail needs to record
     * that a ballot was cast, which it still does. The code remains stored
     * against the ballot, so the office can look one up deliberately when a
     * member asks for help.
     */
    audit($userId, 'ballot.cast', 'election', $electionId,
        'Ballot cast (' . count($clean) . ' selections)');

    /*
     * Receipt email. The ballot is already committed above, so this runs
     * outside the transaction and its result is deliberately ignored —
     * a vote must never depend on a mail server being reachable.
     *
     * It also runs after the response: reaching Gmail takes about five
     * seconds, and on election day that would be five seconds of frozen
     * screen for every member at the moment they cast their vote.
     */
    $castAt = fmt_datetime(date('Y-m-d H:i:s'));
    $email  = (string) $u['email'];
    $voter  = (string) ($u['full_name'] ?? $u['username']);
    $title  = (string) $election['title'];
    $count  = count($clean);

    respond_then(
        [
            'ok'          => true,
            'receiptCode' => $receipt,
            'castAt'      => $castAt,
            'selections'  => $count,
        ],
        static function () use ($email, $voter, $title, $receipt, $castAt, $count): void {
            require_once BASE_PATH . '/includes/mailer.php';
            mail_vote_receipt($email, $voter, $title, $receipt, $castAt, $count);
        },
        201
    );
}

/* ═══════════════════════════════════════════════════════════
   GET voter/receipts
   ═══════════════════════════════════════════════════════════ */
/** Participation receipts are private and available before results are released. */
function voter_route_receipts(): never
{
    require_method('GET');
    $u = require_voter();
    // Identity belongs to ballots only. Never join the anonymous vote choices.
    $stmt = db()->prepare(
        'SELECT b.ballot_id, b.receipt_code, b.voted_at, e.election_id, e.title,
                e.status, e.results_released_at
           FROM ballots b JOIN elections e ON e.election_id = b.election_id
          WHERE b.user_id = ? AND b.has_voted = 1
          ORDER BY b.voted_at DESC, b.ballot_id DESC'
    );
    $stmt->execute([(int) $u['user_id']]);
    $receipts = [];
    foreach ($stmt->fetchAll() as $row) {
        $receipts[] = [
            'id' => (int) $row['ballot_id'],
            'electionId' => (int) $row['election_id'],
            'electionTitle' => $row['title'],
            'receiptCode' => $row['receipt_code'],
            'castAt' => fmt_datetime($row['voted_at']),
            'published' => $row['status'] === 'published' && !empty($row['results_released_at']),
        ];
    }
    $member = vt_member_dto($u, null);
    json_out([
        'member' => ['name' => $member['name'], 'memberId' => $member['memberId']],
        'receipts' => $receipts,
    ]);
}

/* GET voter/results — published tallies for elections the member voted in. */
function voter_route_results(): never
{
    require_method('GET');
    $u = require_voter();

    $userId  = (int) $u['user_id'];
    $current = vt_selected_election();

    /*
     * Only elections whose results have actually been released.
     *
     * Closed-but-unreleased elections used to be listed here too, showing a
     * "results pending" notice. That still put them in the members' results
     * page and in its election dropdown before anyone had published
     * anything, which is not what a released result should look like. An
     * election now reaches this page at the moment its results are
     * released, and not before; until then members get the page's own
     * "results will appear here once published by the administrator".
     */
    /* Newest election first, which is the one members ask about. */
    $rows = db()->query(
        "SELECT * FROM elections
          WHERE status = 'published'
            AND results_released_at IS NOT NULL
          ORDER BY start_datetime DESC"
    )->fetchAll();

    $elections = [];
    $withheld  = 0;               // released, but this member did not take part

    foreach ($rows as $e) {
        $eid       = (int) $e['election_id'];
        $status    = election_status($e);
        $published = !empty($e['results_released_at']);

        /*
         * The count belongs to the members who took part in it. Someone who
         * did not vote in this election does not get to read its result,
         * so the whole entry is left out rather than sent with the tally
         * stripped — an entry the page could still name and count.
         *
         * This reads the ballot register, which records only THAT a member
         * voted. What they chose is not stored against them anywhere, so
         * the secret ballot is untouched by this check.
         */
        if (!vt_has_voted($userId, $eid)) {
            $withheld++;
            continue;
        }

        $elections[] = [
            'id'          => $eid,
            'title'       => $e['title'],
            'startDate'   => fmt_date($e['start_datetime']),
            'endDate'     => fmt_date($e['end_datetime']),
            /* "Closed" says voting is over but the count is not out yet;
               "Pending" alone read as though nothing had happened. */
            'status'      => $published
                ? 'Final'
                : ($status === 'Active' ? 'Live' : ($status === 'Closed' ? 'Closed' : 'Pending')),
            'published'   => $published,
            'totalVoters' => vt_total_voters(),
            'votesCast'   => vt_votes_cast($eid),
            /* Tallies are withheld until an administrator releases them. */
            'positions'   => $published ? vt_grouped_positions($eid, true, true, true) : [],
        ];
    }

    $member = vt_member_dto($u, $current);
    if ($current) {
        $ballot = vt_ballot($userId, (int) $current['election_id']);
        $member['receiptCode'] = $ballot['receipt_code'] ?? null;
    }

    /*
     * Say how many results exist that this member cannot open, so the page
     * can explain the silence. Without it, someone who skipped an election
     * would see an empty page telling them nothing has been published yet,
     * which is not true and reads like a fault.
     */
    json_out([
        'member'         => $member,
        'elections'      => $elections,
        'withheldCount'  => $withheld,
    ]);
}

/* ═══════════════════════════════════════════════════════════
   GET | PUT voter/profile
   ═══════════════════════════════════════════════════════════ */
function voter_route_profile(): never
{
    require_method('GET', 'PUT', 'POST');
    $u = require_voter();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        $u = voter_update_profile($u);
    }

    /* The nav badge and the voting-status card on this page both speak of
       an election in progress, so a finished one has no business reaching
       them: the profile page was the last one still showing a green "Live"
       badge after the voting had closed. */
    $election = vt_open_election();
    $name     = split_name((string) ($u['full_name'] ?? ''));

    json_out([
        'member' => [
            'first'       => $name['first'],
            'last'        => $name['last'],
            'memberId'    => $u['member_no'] ?? '—',
            'dob'         => $u['date_of_birth'],
            'email'       => $u['email'],
            'phone'       => $u['contact_no'] ?: '',
            'address'     => $u['address'] ?: '',
            'memberSince' => fmt_month_year($u['registered_at'] ?? $u['created_at']),
            'status'      => ucfirst((string) ($u['membership_status'] ?? 'active')),
            'hasVoted'    => $election ? vt_has_voted((int) $u['user_id'], (int) $election['election_id']) : false,
            'lastLogin'   => fmt_datetime($u['last_login_at']),
            'photoUrl'    => profile_photo_url($u['photo_url'], (int) $u['member_id']),
        ],
        'election' => $election ? [
            'title'  => $election['title'],
            'status' => election_status($election),
            /* Upcoming counts as an election worth mentioning, but not as
               one to invite a vote on. */
            'live'   => is_election_live($election),
        ] : null,
    ]);
}

function voter_update_profile(array $u): array
{
    $first   = input_str('first');
    $last    = input_str('last');
    $email   = strtolower(input_str('email'));
    $phone   = input_str('phone');
    $address = input_str('address');
    $dob     = input_str('dob');

    $errors = person_fields_errors($first, $last, $phone);
    if ($error = birth_date_error($dob)) $errors['dob'] = $error;
    if ($dob === '') $errors['dob'] = 'Date of birth is required.';
    if ($phone === '') $errors['phone'] = 'Phone number is required.';
    if ($address === '') $errors['address'] = 'Home address is required.';
    if ($first === '') $errors['first'] = 'First name is required.';
    if ($last === '')  $errors['last']  = 'Last name is required.';
    if ($email === '') $errors['email'] = 'Email address is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';

    if ($errors) {
        json_error('Please correct the highlighted fields.', 422, ['fields' => $errors]);
    }

    /* The email address must stay unique across users. */
    $s = db()->prepare('SELECT 1 FROM users WHERE email = ? AND user_id <> ? LIMIT 1');
    $s->execute([$email, (int) $u['user_id']]);
    if ($s->fetchColumn()) {
        json_error('That email address is already in use.', 409, ['fields' => ['email' => 'Already in use.']]);
    }

    db()->prepare('UPDATE users SET email = ? WHERE user_id = ?')
        ->execute([$email, (int) $u['user_id']]);

    db()->prepare(
        'UPDATE members
            SET full_name = ?, contact_no = ?, address = ?, date_of_birth = ?
          WHERE user_id = ?'
    )->execute([
        trim($first . ' ' . $last),
        $phone !== '' ? $phone : null,
        $address !== '' ? $address : null,
        $dob !== '' ? $dob : null,
        (int) $u['user_id'],
    ]);

    audit((int) $u['user_id'], 'member.profile_updated', 'user', (int) $u['user_id'], 'Updated own profile');

    /* current_user() is cached for this request; return the saved values
       so the response does not reset the form to the old profile. */
    return array_replace($u, [
        'full_name' => trim($first . ' ' . $last),
        'email' => $email,
        'contact_no' => $phone !== '' ? $phone : null,
        'address' => $address !== '' ? $address : null,
        'date_of_birth' => $dob !== '' ? $dob : null,
    ]);
}

/* ═══════════════════════════════════════════════════════════
   POST voter/change-password
   ═══════════════════════════════════════════════════════════ */
function voter_route_change_password(): never
{
    require_method('POST');
    $u = require_login();     // any signed-in role may change their own password

    $current = input_scalar('currentPassword');
    $new     = input_scalar('newPassword');

    if (blank_form_value($current)) json_error('Please enter your current password.', 422,
        ['fields' => ['currentPassword' => 'Current password is required.']]);
    if (blank_form_value($new)) json_error('Enter a new password containing at least one non-space character.', 422,
        ['fields' => ['newPassword' => 'New password cannot be blank or only spaces.']]);
    if (($passwordError = changed_password_error($new)) !== null) {
        json_error($passwordError, 422, [
            'code' => 'WEAK_PASSWORD',
            'fields' => ['newPassword' => $passwordError],
        ]);
    }
    if ($new === $current)    json_error('Your new password must be different from the current one.');

    if (!password_verify($current, $u['password_hash'])) {
        audit((int) $u['user_id'], 'auth.password_change_failed', 'user', (int) $u['user_id'],
            'Password change refused — current password incorrect');
        // The session is still valid. A 401 here redirects the member away
        // from their form through the shared API client's sign-in handler.
        json_error('Your current password is incorrect. Re-enter it and try again.', 422, [
            'code' => 'CURRENT_PASSWORD_INCORRECT',
            'fields' => ['currentPassword' => 'Your current password is incorrect.'],
        ]);
    }

    db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
        ->execute([password_hash($new, PASSWORD_BCRYPT), (int) $u['user_id']]);

    audit((int) $u['user_id'], 'auth.password_changed', 'user', (int) $u['user_id'], 'Password changed');

    json_out(['ok' => true, 'message' => 'Password updated successfully.']);
}

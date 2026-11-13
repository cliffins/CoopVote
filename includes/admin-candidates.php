<?php
/**
 * Election-scoped candidate administration, loaded by the protected admin router.
 *
 * A member may run in multiple elections, with one candidacy per election.
 * The member record remains their voter identity; nominations never create users.
 * Biography, platform, position, and nomination status belong to the candidacy.
 * Section, year, department, and course belong to the election's candidacy.
 *
 * Lists and exports accept an explicit election query. The admin UI always sends
 * it, while existing administrative integrations may request the full inventory.
 * A supplied query is validated by admin_requested_election(), never cast to zero.
 * Save and delete handlers compare that context with the stored election before
 * writing. Existing nominations cannot change their member or election ownership.
 * Position IDs must belong to the same election, even when names match elsewhere.
 *
 * Candidates with votes are withdrawn rather than deleted so tallies remain
 * intact. Changes use the election lock where required by the approval workflow.
 */
declare(strict_types=1);
require_once __DIR__ . '/candidate-display.php';

/* ═══════════════════════════════════════════════════════════
   candidates — GET | POST | PUT {id} | DELETE {id} | GET export
   ═══════════════════════════════════════════════════════════ */
function admin_candidates(array $args): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $first  = $args[0] ?? '';

    if ($first === 'display-settings') admin_candidate_display_settings();

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

function admin_candidate_display_settings(): never
{
    require_method('GET', 'PUT');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_out(['showYearSection' => candidate_show_year_section()]);
    }
    $me = require_writer();
    require_json_request();
    $visible = input('showYearSection');
    if (!is_bool($visible)) json_error('Choose whether to show Year & Section.', 422);
    db()->prepare("INSERT INTO settings(setting_key,setting_value) VALUES ('candidate_show_year_section',?)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$visible ? '1' : '0']);
    audit((int)$me['user_id'], 'candidate.display_settings_updated', 'settings', null,
        'Year and section display ' . ($visible ? 'enabled' : 'hidden'));
    json_out(['showYearSection' => $visible]);
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
                m.photo_url AS photoUrl
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
        $row['photoUrl'] = profile_photo_url($row['photoUrl'] ?: null, $row['id']);
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
    $wanted = admin_requested_election();

    $elections = db()->query(
        'SELECT * FROM elections ORDER BY start_datetime DESC'
    )->fetchAll();

    $out = [];
    foreach ($elections as $e) {
        $eid = (int) $e['election_id'];
        if ($wanted && $eid !== $wanted) {
            continue;
        }

        $positionLocked = election_preopening_error(db(), $e) !== null;
        foreach (vt_candidates($eid, true) as $c) {
            $d = vt_candidate_dto($c, true);
            $out[] = admin_candidate_row($d, $c, $eid, (string) $e['title'])
                + ['positionLocked' => $positionLocked];
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
        'section'    => $d['section'],
        'year'       => $d['year'],
        'department' => $d['department'],
        'course'     => $d['course'],
        'desc'       => $d['bio'] ?: $d['platform'],
        'platform'   => $d['platform'],
        'votes'      => $d['votes'],
        'status'     => $d['status'],
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
    $isActive   = input('status', 'Active') === 'Inactive' ? 0 : 1;
    $photo = candidate_photo_input();

    $first = input_str('first');
    $last = input_str('last');
    $errors = [];
    $academic = [];
    foreach (['section' => ['section', 100], 'year' => ['year_level', 32],
              'department' => ['department', 150], 'course' => ['course', 150]] as $field => [$column, $limit]) {
        // Hidden fields are preserved on edits and left empty on new nominations.
        if (in_array($field, ['year', 'section'], true) && !candidate_show_year_section()) continue;
        // Omitted fields preserve old records and integrations; supplied values
        // must be nonblank strings within the same limits as the form/schema.
        $raw = input($field);
        if ($raw === null) continue;
        if (!is_string($raw) || trim($raw) === '' || mb_strlen(trim($raw), 'UTF-8') > $limit) {
            $errors[$field] = 'Enter ' . $field . ' using 1–' . $limit . ' characters.';
        } else {
            $academic[$column] = trim($raw);
        }
    }
    if (input('first') !== null || input('last') !== null) {
        $errors += person_fields_errors($first, $last);
    }
    if ($candidateId === 0 && $memberId <= 0) $errors['memberId'] = 'Select an existing voter. Register new members in Voter Management first.';
    if (input('memberId') !== null && whole_number_error(input('memberId'), 1, 2147483647)) {
        $errors['memberId'] = 'Select a valid voter from the list.';
    }
    if (input('positionId') !== null && !$positionId) $errors['positionId'] = 'Please choose a position.';
    if ($errors) json_error('Please correct the highlighted fields.', 422, ['fields' => $errors]);

    /* Editing keeps the candidacy in its original election. */
    $existingMember = 0;
    if ($candidateId !== 0) {
        $s = $pdo->prepare('SELECT c.* FROM candidates c WHERE c.candidate_id = ? LIMIT 1');
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

    $contextElection = admin_requested_election();
    if ($contextElection !== null && $contextElection !== $electionId) {
        json_error('This candidacy does not belong to the selected election. Reload the candidate list.', 422);
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
                'INSERT INTO candidates (member_id, position_id, election_id, platform, bio, is_active, section, year_level, department, course)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([$memberId, $positionId, $electionId, $platform ?: null, $bio ?: null, $isActive,
                $academic['section'] ?? '', $academic['year_level'] ?? '', $academic['department'] ?? '', $academic['course'] ?? '']);
            $candidateId = (int) $pdo->lastInsertId();
            if ($photo !== null) store_candidate_photo($pdo, $candidateId, $photo);
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
    foreach ($academic as $column => $value) {
        $sets[] = $column . ' = ?';
        $params[] = $value;
    }

    if ($positionId) {
        $sets[]   = 'position_id = ?';
        $params[] = $positionId;
    }
    $params[] = $candidateId;

    $conflict = db_transaction(static function(PDO $pdo) use($electionId,$sets,$params,$candidateId,$photo,$positionId):?array {
        // Share the election lock with activation and voting. Recheck the stored
        // position here so stale forms cannot move a candidate after opening.
        $election = governance_election($pdo,$electionId);
        $s = $pdo->prepare('SELECT c.position_id, p.position_name FROM candidates c JOIN positions p ON p.position_id=c.position_id WHERE c.candidate_id=? FOR UPDATE');
        $s->execute([$candidateId]);
        $current = $s->fetch();
        if (!$current) return ['error'=>'This candidate no longer exists.', 'code'=>'CANDIDATE_NOT_FOUND'];
        if ($positionId && $positionId !== (int)$current['position_id'] && election_preopening_error($pdo, $election) !== null) {
            return ['error'=>'Position is locked because this election has started, contains voting records, or is no longer open for setup. You can still update the profile, platform, and status.',
                'code'=>'POSITION_LOCKED', 'positionId'=>(int)$current['position_id'], 'position'=>$current['position_name']];
        }
        $pdo->prepare('UPDATE candidates SET ' . implode(', ', $sets) . ' WHERE candidate_id = ?')->execute($params);
        if ($photo !== null) store_candidate_photo($pdo, $candidateId, $photo);
        return null;
    });
    if ($conflict) json_error($conflict['error'], $conflict['code'] === 'CANDIDATE_NOT_FOUND' ? 404 : 409, $conflict);

    audit((int) $me['user_id'], 'candidate.updated', 'candidate', $candidateId, 'Updated candidate ' . audit_subject('candidate', $candidateId)
        . ($photo !== null ? '; updated shared profile photo' : '')
        . audit_changed_fields($existing, ['platform'=>$platform,'bio'=>$bio,'is_active'=>$isActive,'position_id'=>$positionId ?: $existing['position_id']]
            + $academic + array_intersect_key($existing, array_flip(['section','year_level','department','course'])),
            ['platform'=>'platform','bio'=>'biography','is_active'=>'status','position_id'=>'position',
             'section'=>'section','year_level'=>'year','department'=>'department','course'=>'course']));
    json_out(['ok' => true, 'id' => $candidateId]);
}

function admin_candidates_delete(int $candidateId): never
{
    $contextElection = admin_requested_election();
    $lookup = db()->prepare('SELECT election_id FROM candidates WHERE candidate_id = ?');
    $lookup->execute([$candidateId]);
    $owner = $lookup->fetchColumn();
    if ($owner === false) json_error('This candidate no longer exists.', 404);
    if ($contextElection !== null && (int)$owner !== $contextElection) {
        json_error('This candidate belongs to another election. Reload the candidate list.', 422);
    }
    $me = current_user();
    $subject = audit_subject('candidate', $candidateId);

    // Serialize candidate removal with the approval check and opening transition.
    db()->beginTransaction();
    $election = db()->prepare('SELECT election_id FROM candidates WHERE candidate_id = ?');
    $election->execute([$candidateId]);
    $eid = (int)$election->fetchColumn();
    if ($eid) governance_election(db(), $eid);

    // Uniform retirement once any ballot was cast: no candidate-specific count oracle.
    if (vt_votes_cast($eid) > 0) {
        db()->prepare('UPDATE candidates SET is_active=0 WHERE candidate_id=?')->execute([$candidateId]);
        db()->commit();
        audit((int)$me['user_id'], 'candidate.deactivated', 'candidate', $candidateId, 'Withdrew candidate '.$subject.'; election records preserved');
        json_out(['ok'=>true,'deactivated'=>true,'message'=>'Candidate withdrawn; election records are preserved.']);
    }
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
    $visible = candidate_show_year_section();
    csv_out('coopvote-candidates.csv',
        ['ID', 'First Name', 'Last Name', 'Position', ...($visible ? ['Year & Section'] : []), 'Votes', 'Status'],
        array_map(static fn($c) => [
            $c['id'], $c['first'], $c['last'], $c['position'],
            ...($visible ? [candidate_year_section_label($c)] : []), $c['votes'], $c['status'],
        ], admin_candidates_list())
    );
}


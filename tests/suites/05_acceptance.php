<?php
/**
 * 5 — ACCEPTANCE TESTING (UAT)
 *
 * Each check is one thing the cooperative asked the system to do, written
 * the way a member of the election committee would say it. These are the
 * scenarios a panel would sit down and walk through.
 */

declare(strict_types=1);

return static function (Suite $t, PDO $pdo, array $fx): void {

    /* ═══ UAT-01 — The office creates a member account ═══════ */
    $t->group('UAT-01  The office creates a member account and hands over the credentials');

    $admin = new Http('uat_admin');
    $admin->login('t_admin');

    $r = tdb_registration_window($pdo, fn() => $admin->api('POST', 'voters', [
        'first' => 'Bagong', 'last' => 'Kasapi',
        'email' => 'bagong.kasapi@test.coop',
        'phone' => '09171234567',
        'address' => '123 Test Street, Manila',
        'dob' => '1990-01-02',
    ]));
    $t->same('the account is created in one step', 201, $r['status']);
    $t->ok('a username is generated', !empty($r['json']['username']), json_encode($r['json'] ?? []));
    $t->ok('a member number is issued', !empty($r['json']['memberNo']));
    $t->ok('a temporary password is shown once, for the office to pass on',
        !empty($r['json']['password']), 'the office has no other way to tell the member');
    $t->matches('the member number follows the cooperative format', '/^COOP-\d{4}$/', (string) $r['json']['memberNo']);

    $newUser = (string) $r['json']['username'];
    $newPass = (string) $r['json']['password'];

    $t->same('the new member is active straight away, with nobody left to approve them',
        'active', (string) $pdo->query(
            "SELECT status FROM users WHERE username = " . $pdo->quote($newUser)
        )->fetchColumn());

    $t->group('UAT-02  The new member can sign in with what the office gave them');

    $fresh = new Http('uat_fresh');
    $r = $fresh->login($newUser, $newPass);
    $t->same('the credentials work', 200, $r['status']);
    $t->same('and they land on the member dashboard', 'voter/dashboard.html', $r['json']['redirect'] ?? null);

    $r = $fresh->api('GET', 'auth/me');
    $t->same('the system knows who they are', 'Bagong Kasapi', $r['json']['user']['name'] ?? null);

    $t->group('UAT-03  A member changes the temporary password');

    $beforeHash = $pdo->query('SELECT password_hash FROM users WHERE username = ' . $pdo->quote($newUser))->fetchColumn();
    foreach (['abcdefgh', '12345678', 'Abcdefgh', 'Ab1!', 'Abc     '] as $weak) {
        $r = $fresh->api('POST', 'voter/change-password', ['currentPassword' => $newPass, 'newPassword' => $weak]);
        $t->same('weak new password is refused through the API', 422, $r['status']);
        $t->same('response identifies password strength failure', 'WEAK_PASSWORD', $r['json']['code'] ?? null);
        $t->same('rejected change preserves the stored hash', $beforeHash,
            $pdo->query('SELECT password_hash FROM users WHERE username = ' . $pdo->quote($newUser))->fetchColumn());
    }
    $t->same('weak password rejection keeps the member signed in', 200, $fresh->api('GET', 'auth/me')['status']);

    $r = $fresh->api('POST', 'voter/change-password', [
        'currentPassword' => $newPass,
        'newPassword'     => 'MyOwnPass@2026',
        'confirmPassword' => 'MyOwnPass@2026',
    ]);
    $t->same('the password is changed', 200, $r['status']);

    $again = new Http('uat_fresh2');
    $t->same('the old password no longer works', 401, $again->login($newUser, $newPass)['status']);
    $t->same('the new password does', 200, $again->login($newUser, 'MyOwnPass@2026')['status']);

    $r = $again->api('POST', 'voter/change-password', [
        'currentPassword' => 'wrong-one',
        'newPassword'     => 'Another@2026',
        'confirmPassword' => 'Another@2026',
    ]);
    $t->same('wrong current password is a validation error, not session expiry', 422, $r['status']);
    $t->same('the session still works after a wrong current password', 200,
        $again->api('GET', 'voter/profile')['status']);

    /* ═══ UAT-04 — Members are not left to sign themselves up ═ */
    $t->group('UAT-04  Members cannot create their own accounts');

    $anon = new Http('uat_anon');
    $r = $anon->api('POST', 'auth/register', [
        'firstName' => 'Sneaky', 'lastName' => 'Person',
        'username' => 'sneaky', 'email' => 'sneaky@test.coop',
        'password' => 'Passw0rd!', 'confirmPassword' => 'Passw0rd!',
    ]);
    $t->same('self-registration is refused', 403, $r['status']);
    $t->contains('and the message points them at the office',
        'contact them to be registered', $r['json']['error'] ?? '');
    $t->same('no account was made', 0, (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE username = 'sneaky'"
    )->fetchColumn());

    $t->ok('the register page is gone from the site',
        !file_exists(BASE_PATH . '/register.html'), 'a page that cannot work should not be reachable');

    /* ═══ UAT-05 — One member, one vote ══════════════════════ */
    $t->group('UAT-05  One member, one vote');

    $v = new Http('uat_v1');
    $v->login('t_voter1');

    $ballot = $v->api('GET', 'voter/ballot&election=' . $fx['live']);
    $t->same('the member is shown the ballot', 200, $ballot['status']);
    $t->same('with every seat on it', 2, count($ballot['json']['positions'] ?? []));

    $pick = [['candidateId' => $fx['candidates'][$fx['live']]['Chairperson'][1]]];
    $r = $v->api('POST', 'voter/vote', ['electionId' => $fx['live'], 'votes' => $pick]);
    $t->same('the ballot is accepted', 201, $r['status']);
    $t->ok('and a receipt is issued', !empty($r['json']['receiptCode']));

    $r = $v->api('POST', 'voter/vote', ['electionId' => $fx['live'], 'votes' => $pick]);
    $t->same('a second attempt is refused', 409, $r['status']);
    $t->contains('in words the member will understand',
        'already voted', $r['json']['error'] ?? '');

    $t->same('exactly one ballot is on record for them', 1, (int) $pdo->query(
        "SELECT COUNT(*) FROM ballots WHERE user_id = {$fx['voters'][1]} AND election_id = {$fx['live']} AND has_voted = 1"
    )->fetchColumn());

    $t->group('UAT-06  A member may choose only one candidate per seat');

    $v2 = new Http('uat_v2');
    $v2->login('t_voter2');

    $r = $v2->api('POST', 'voter/vote', [
        'electionId' => $fx['live'],
        'votes' => [
            ['candidateId' => $fx['candidates'][$fx['live']]['Chairperson'][1]],
            ['candidateId' => $fx['candidates'][$fx['live']]['Chairperson'][2]],
        ],
    ]);
    $t->same('two picks for one seat are refused', 422, $r['status']);
    $t->contains('and the seat is named', 'Chairperson', $r['json']['error'] ?? '');
    $t->same('nothing was recorded', false, vt_has_voted($fx['voters'][2], $fx['live']));

    $t->group('UAT-07  A member may skip a seat (an abstention)');

    $r = $v2->api('POST', 'voter/vote', [
        'electionId' => $fx['live'],
        'votes' => [['candidateId' => $fx['candidates'][$fx['live']]['Chairperson'][1]]],
    ]);
    $t->same('a partial ballot is accepted', 201, $r['status']);
    $t->same('only the seat they voted on is counted', 1, (int) ($r['json']['selections'] ?? 0));

    $t->group('UAT-08  An empty ballot is refused');

    $v3 = new Http('uat_v3');
    $v3->login('t_voter3');
    $r = $v3->api('POST', 'voter/vote', ['electionId' => $fx['live'], 'votes' => []]);
    $t->same('submitting nothing is refused', 400, $r['status']);
    $t->contains('with a message that says what to do', 'choose at least one', $r['json']['error'] ?? '');
    $t->same('and they may still vote afterwards', false, vt_has_voted($fx['voters'][3], $fx['live']));

    /* ═══ UAT-09 — The count is published by the office ══════ */
    $t->group('UAT-09  Members see no count until the office publishes it');

    $r = $v->api('GET', 'voter/results');
    $ids = array_column($r['json']['elections'] ?? [], 'id');
    $t->ok('the live election is not in the results yet', !in_array($fx['live'], $ids, true), implode(',', $ids));

    $admin->api('PATCH', 'elections/' . $fx['live'] . '/close');
    $r = $v->api('GET', 'voter/results');
    $ids = array_column($r['json']['elections'] ?? [], 'id');
    $t->ok('closing the voting alone does not publish the count',
        !in_array($fx['live'], $ids, true), implode(',', $ids));

    $admin->api('PATCH', 'elections/' . $fx['live'] . '/release');
    $r = $v->api('GET', 'voter/results');
    $ids = array_column($r['json']['elections'] ?? [], 'id');
    $t->ok('publishing it makes the count visible', in_array($fx['live'], $ids, true), implode(',', $ids));

    /* ═══ UAT-10 — The count is for those who voted ══════════ */
    $t->group('UAT-10  The result is shown to the members who took part');

    $r = $v->api('GET', 'voter/results');
    $t->ok('a member who voted sees the count', count($r['json']['elections'] ?? []) > 0);

    $v9 = new Http('uat_v9');
    $v9->login('t_voter9');
    $r = $v9->api('GET', 'voter/results');
    $t->same('a member who did not vote sees no count', 0, count($r['json']['elections'] ?? []));
    $t->ok('but is told a result exists that is not theirs to read',
        (int) ($r['json']['withheldCount'] ?? 0) > 0,
        'otherwise the page reads as though nothing had been published');

    /* ═══ UAT-11 — The ballot is secret ══════════════════════ */
    $t->group('UAT-11  Nobody, including the administrator, can see how a member voted');

    $r = $admin->api('GET', 'monitor&election=' . $fx['live']);
    $t->same('the office can see that people voted', 200, $r['status']);

    $body = $r['body'];
    $t->ok('the monitor lists turnout, not choices',
        !preg_match('/Chairperson Candidate \d.*Test Member/s', $body)
        && !preg_match('/Test Member.*chose/i', $body));

    $t->same('no query can join a vote to a member', 0, (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'votes'
            AND column_name IN ('user_id','member_id','voter_id','ballot_id','receipt_code')"
    )->fetchColumn());

    $t->group('UAT-12  A member can prove their own vote was counted');

    $r = $v->api('GET', 'voter/results&election=' . $fx['live']);
    $receipt = $r['json']['member']['receiptCode'] ?? null;
    $t->ok('their receipt is shown back to them', !empty($receipt), json_encode($receipt));
    $t->same('and it is the one stored against their ballot', $receipt, $pdo->query(
        "SELECT receipt_code FROM ballots WHERE user_id = {$fx['voters'][1]} AND election_id = {$fx['live']}"
    )->fetchColumn());

    $r = $admin->api('GET', 'security');
    $t->notContains('the receipt is not on show in the back office', (string) $receipt, $r['body']);

    /* ═══ UAT-13 — Suspending a member ═══════════════════════ */
    $t->group('UAT-13  The office can suspend and restore a member');

    $target = $fx['voters'][10];
    $r = $admin->api('PATCH', 'voters/' . $target . '/suspend');
    $t->same('the member is suspended', 200, $r['status']);

    $blocked = new Http('uat_susp');
    $t->same('and can no longer sign in', 401, $blocked->login('t_voter10')['status']);

    $r = $admin->api('PATCH', 'voters/' . $target . '/activate');
    $t->same('restoring them works', 200, $r['status']);
    $t->same('and they can sign in again', 200, $blocked->login('t_voter10')['status']);

    $t->group('UAT-14  An administrator cannot delete their own account');

    $r = $admin->api('DELETE', 'voters/' . $fx['admin']);
    $t->same('deleting yourself is refused', 409, $r['status']);
    $t->contains('with a plain explanation', 'your own account', $r['json']['error'] ?? '');

    /* ═══ UAT-15 — The office can report on an election ══════ */
    $t->group('UAT-15  The office can produce the figures for the general assembly');

    $r = $admin->api('GET', 'results&election=' . $fx['live']);
    $t->same('the results page loads', 200, $r['status']);

    $r = $admin->api('GET', 'reports');
    $t->same('the reports page loads', 200, $r['status']);

    $r = $admin->api('GET', 'dashboard');
    $t->same('the dashboard loads', 200, $r['status']);
    $t->ok('and reports a member count', isset($r['json']['stats']) || isset($r['json']['totalVoters'])
        || isset($r['json']['voters']), implode(', ', array_keys($r['json'] ?? [])));

    /* ═══ UAT-16 — Every action is accountable ══════════════ */
    $t->group('UAT-16  Every administrative action is recorded against a person');

    $rows = $pdo->query(
        'SELECT action, user_id FROM audit_logs ORDER BY log_id DESC LIMIT 60'
    )->fetchAll();
    $actions = array_column($rows, 'action');

    foreach (['member.created', 'auth.login', 'ballot.cast'] as $expect) {
        $t->ok('"' . $expect . '" is on the record', in_array($expect, $actions, true));
    }

    $orphans = 0;
    foreach ($rows as $row) {
        if ($row['user_id'] === null && !str_starts_with((string) $row['action'], 'auth.login_failed')) {
            $orphans++;
        }
    }
    $t->same('no administrative entry is anonymous', 0, $orphans);

    $t->group('UAT-17  A candidate keeps the same voter account and one-ballot limit');
    $member = $pdo->query('SELECT m.member_id, m.user_id FROM members m JOIN users u ON u.user_id=m.user_id WHERE u.username=' . $pdo->quote($newUser))->fetch();
    $picker = $admin->api('GET', 'candidates/eligible');
    $t->same('the existing-voter picker loads', 200, $picker['status']);
    $t->ok('the registered voter is available for nomination', in_array((int)$member['member_id'], array_column($picker['json'] ?? [], 'id'), true));
    $usersBefore = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $membersBefore = (int)$pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
    $r = $admin->api('POST', 'elections', ['title'=>'Candidate Voter Check', 'description'=>'',
        'positions'=>['Chairperson'],
        'startDate'=>date('Y-m-d\TH:i', time()-300), 'endDate'=>date('Y-m-d\TH:i', time()+3600)]);
    $eid = (int)($r['json']['id'] ?? 0);
    $r = $admin->api('GET', 'positions&election=' . $eid);
    $pid = (int)($r['json'][0]['position_id'] ?? 0);
    $r = $admin->api('POST', 'candidates', ['memberId'=>(int)$member['member_id'], 'electionId'=>$eid, 'positionId'=>$pid]);
    $t->same('the existing voter is assigned as a candidate', 201, $r['status']);
    $cid = (int)($r['json']['id'] ?? 0);
    $t->same('nomination creates no second account', $usersBefore, (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    $t->same('nomination creates no second member record', $membersBefore, (int)$pdo->query('SELECT COUNT(*) FROM members')->fetchColumn());
    $candidateVoter = new Http('uat_candidate_voter');
    $t->same('the candidate signs in with the same username and password', 200, $candidateVoter->login($newUser, 'MyOwnPass@2026')['status']);
    $me = $candidateVoter->api('GET', 'auth/me');
    $t->same('the account role remains voter', 'voter', $me['json']['user']['roleSlug'] ?? null);
    tdb_approve_election($admin, $eid);
    $t->same('the nominated ballot can be opened', 200, $admin->api('PATCH', 'elections/'.$eid.'/activate')['status']);
    $ballot = ['electionId'=>$eid, 'votes'=>[['candidateId'=>$cid]]];
    $t->same('the candidate can cast a ballot', 201, $candidateVoter->api('POST', 'voter/vote', $ballot)['status']);
    $repeat = $candidateVoter->api('POST', 'voter/vote', $ballot);
    $t->same('a second submission is rejected', 409, $repeat['status']);
    $t->same('the rejection is the normal one-vote rule', 'ALREADY_VOTED', $repeat['json']['code'] ?? null);
    $t->same('only one vote is stored', 1, (int)$pdo->query('SELECT COUNT(*) FROM votes WHERE election_id='.$eid)->fetchColumn());
};

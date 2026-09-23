<?php
declare(strict_types=1);
return static function (Suite $t, PDO $pdo): void {
    $fx = tdb_seed($pdo);
    $admin = new Http('audit_details');
    $admin->login('t_admin');
    $last = static function (string $action) use ($pdo): string {
        $q = $pdo->prepare('SELECT description FROM audit_logs WHERE action=? ORDER BY log_id DESC LIMIT 1');
        $q->execute([$action]);
        return (string) $q->fetchColumn(); };
    $t->group('Audit entries retain readable subjects after deletion');
    $payload = ['first' => 'Maria', 'last' => 'Santos', 'email' => 'audit.member@test.coop', 'phone' => '09171234567', 'address' => 'Test address', 'dob' => '1990-01-02'];
    $created = tdb_registration_window($pdo, fn() => $admin->api('POST', 'voters', $payload));
    $uid = (int) ($created['json']['id'] ?? 0);
    $t->same('member for log checks is created', 201, $created['status']);
    $t->contains('add log identifies the member', 'Maria Santos', $last('member.created'));
    $payload['memberId'] = $created['json']['memberNo'];
    $payload['phone'] = '09179876543';
    $t->same('member details are edited', 200, $admin->api('PUT', 'voters/' . $uid, $payload)['status']);
    $t->contains('edit log identifies the changed field', 'changed: phone', $last('member.updated'));
    $admin->api('PATCH', 'voters/' . $uid . '/deactivate');
    $t->contains('status log identifies the account', 'Maria Santos', $last('user.status_changed'));
    $admin->api('DELETE', 'voters/' . $uid);
    $t->contains('deleted member name remains in the log', 'Maria Santos', $last('member.deleted'));

    $created = $admin->api('POST', 'elections', ['title' => 'Audit Assembly', 'positions' => ['Chairperson', 'Treasurer'], 'startDate' => '2030-01-01T09:00', 'endDate' => '2030-01-02T09:00']);
    $eid = (int) ($created['json']['id'] ?? 0);
    $t->same('election for log checks is created', 201, $created['status']);
    $edited = $admin->api('PUT', 'elections/' . $eid, [
        'title' => 'Audit Assembly',
        'description' => 'Annual assembly',
        'startDate' => '2030-01-01T09:00',
        'endDate' => '2030-01-03T09:00'
    ]);
    $t->same('election details can be edited', 200, $edited['status']);
    $t->contains('election log identifies changed scheduling details', 'end date', $last('election.updated'));
    $positions = $admin->api('GET', 'positions&election=' . $eid)['json'];
    $pid = (int) $positions[0]['position_id'];
    $mid = (int) $pdo->query('SELECT member_id FROM members WHERE user_id=' . (int) $fx['voters'][10])->fetchColumn();
    $created = $admin->api('POST', 'candidates', ['electionId' => $eid, 'positionId' => $pid, 'memberId' => $mid, 'platform' => 'Open meetings']);
    $cid = (int) ($created['json']['id'] ?? 0);
    $t->same('candidate for log checks is added', 201, $created['status']);
    $name = (string) $pdo->query('SELECT full_name FROM members WHERE member_id=' . $mid)->fetchColumn();
    foreach ([$name, $positions[0]['position_name'], 'Audit Assembly'] as $detail)
        $t->contains('candidate log includes ' . $detail, $detail, $last('candidate.created'));
    $admin->api('PUT', 'candidates/' . $cid, ['electionId' => $eid, 'positionId' => $pid, 'memberId' => $mid, 'platform' => 'Published meeting minutes', 'occ' => 'Teacher', 'years' => 2]);
    $t->contains('candidate edit records the changed field', 'changed: platform', $last('candidate.updated'));
    $t->contains('candidate log identifies profile-field changes', 'occupation', $last('candidate.updated'));
    $admin->api('DELETE', 'candidates/' . $cid);
    $t->contains('candidate deletion retains the name', $name, $last('candidate.deleted'));
    $t->contains('candidate deletion retains election context', 'Audit Assembly', $last('candidate.deleted'));
    $admin->api('DELETE', 'positions/' . $pid);
    $t->contains('position deletion retains its name', $positions[0]['position_name'], $last('position.deleted'));
    $t->contains('position deletion retains its election', 'Audit Assembly', $last('position.deleted'));
    $admin->api('DELETE', 'elections/' . $eid);
    $t->contains('election deletion retains its title', 'Audit Assembly', $last('election.deleted'));
    $created = $admin->api('POST', 'announcements', ['title' => 'Assembly notice', 'message' => 'The meeting starts tomorrow.']);
    $admin->api('DELETE', 'announcements/' . $created['json']['id']);
    $t->contains('notice deletion retains its title', 'Assembly notice', $last('announcement.deleted'));

    $created = $admin->api('POST', 'management/accounts', ['name' => 'Officer Jane', 'username' => 'audit.jane', 'email' => 'audit.jane@test.coop']);
    $officer = (int) $pdo->query("SELECT user_id FROM users WHERE username='audit.jane'")->fetchColumn();
    $t->same('officer is created', 201, $created['status']);
    $t->contains('officer creation names the person', 'Officer Jane', $last('admin.created'));
    $t->notContains('generated officer password is never logged', $created['json']['password'], $last('admin.created'));
    $admin->api('PUT', 'management/accounts/' . $officer, ['name' => 'Officer Janet', 'email' => 'audit.janet@test.coop']);
    $t->contains('officer editing names the person', 'Officer Janet', $last('admin.updated'));
    $admin->api('POST', 'management/accounts/' . $officer, ['status' => 'deactivated']);
    $t->contains('officer deactivation names the person', 'Officer Janet', $last('admin.status_changed'));

    audit($fx['admin'], 'candidate.deleted', 'candidate', 99999, 'Deleted candidate #99999');
    $stored = $last('candidate.deleted');
    $logs = $admin->api('GET', 'security')['json']['logs'];
    $t->contains('legacy entries explain missing details', 'details were not recorded', $logs[0]['event']);
    $t->notContains('legacy display removes the opaque database number', '#99999', $logs[0]['event']);
    $t->same('reading does not rewrite historical audit data', $stored, $last('candidate.deleted'));
    foreach (['dashboard' => 'recentActivity', 'monitor' => 'activityFeed'] as $route => $key) {
        $feed = $admin->api('GET', $route)['json'][$key];
        $t->same($route . ' uses the same event description', $logs[0]['event'], $feed[0]['title']);
    }
    $export = $admin->api('GET', 'security/export');
    $t->contains('export includes human-readable subjects', 'Officer Janet', $export['body']);
    $t->notContains('export does not expose the legacy database number', '#99999', $export['body']);
    audit($fx['admin'], 'test.unicode', null, null, str_repeat('ñ', 300));
    $t->ok('long names remain valid UTF-8', mb_check_encoding($last('test.unicode'), 'UTF-8'));
    $t->ok('long description fits existing storage', strlen($last('test.unicode')) <= 255);
};

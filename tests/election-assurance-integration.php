<?php
/** Authorization, tamper detection and notification regressions, scratch database only. */
declare(strict_types=1);
return static function (Suite $t, PDO $pdo): void {
    if (DB_NAME !== 'coopvote_test') throw new RuntimeException('Scratch database required.');
    require_once __DIR__ . '/../includes/election-reports.php';
    require_once __DIR__ . '/../includes/security-alerts.php';
    require_once __DIR__ . '/../includes/election-notifications.php';
    $fx = tdb_seed($pdo);
    $admin = new Http('assurance-admin'); $admin->login('t_admin');
    $voter = new Http('assurance-voter'); $voter->login('t_voter10');
    $anon = new Http('assurance-anon');
    $eid = $fx['live']; $closed = $fx['closed'];
    $t->group('Election operations access');
    $t->same('anonymous cannot view checks', 401, $anon->api('GET', 'operations/overview')['status']);
    $t->same('voter cannot view admin checks', 403, $voter->api('GET', 'operations/overview')['status']);
    $t->same('admin can view checks', 200, $admin->api('GET', 'operations/overview&election=' . $eid)['status']);
    $overview = $admin->api('GET', 'operations/overview&election=' . $eid)['json'];
    $t->ok('overview has no quorum', !array_key_exists('quorum', $overview));
    $t->same('removed quorum control rejects direct requests', 404, $admin->api('PUT', 'operations/quorum', ['electionId' => $eid, 'percent' => 50])['status']);

    $t->group('Anonymous signatures and tamper detection');
    $body = ['electionId' => $eid, 'votes' => [['candidateId' => $fx['candidates'][$eid]['Chairperson'][1]]]];
    $t->same('ballot and signature commit together', 201, $voter->api('POST', 'voter/vote', $body)['status']);
    $t->same('signed vote verifies', 'passed', integrity_verify($eid)['status']);
    $t->same('voter cannot cast twice', 409, $voter->api('POST', 'voter/vote', $body)['status']);
    $columns = array_column($pdo->query('SHOW COLUMNS FROM vote_integrity')->fetchAll(), 'Field');
    $t->same('no voter linkage in signature metadata', [], array_values(array_intersect(['user_id','ballot_id','receipt_code','ip_address','user_agent','voted_at'], $columns)));
    $vote = (int) $pdo->query("SELECT vote_id FROM votes WHERE election_id = $eid LIMIT 1")->fetchColumn();
    foreach ([
        'altered date' => ["UPDATE votes SET cast_date = '2000-01-01' WHERE vote_id = $vote"],
        'deleted vote' => ["DELETE FROM votes WHERE vote_id = $vote"],
        'deleted vote and signature' => ["DELETE FROM votes WHERE vote_id = $vote", "DELETE FROM vote_integrity WHERE vote_id = $vote"],
        'modified count' => ["UPDATE election_integrity SET vote_count = vote_count + 1 WHERE election_id = $eid"],
    ] as $name => $queries) {
        db()->beginTransaction();
        try {
            foreach ($queries as $query) db()->exec($query);
            $t->same($name . ' detected', 'mismatch', integrity_verify($eid)['status']);
        } finally { db()->rollBack(); }
    }
    $t->same('restored records verify', 'passed', integrity_verify($eid)['status']);
    $t->ok('legacy votes explicitly identified', integrity_verify($closed)['baselineVotes'] > 0);
    db()->beginTransaction();
    try {
        db()->exec("DELETE FROM election_integrity WHERE election_id = $eid");
        $blocked = false;
        try { integrity_state(db(), $eid); } catch (RuntimeException $e) { $blocked = true; }
        $t->ok('missing register never silently recreated', $blocked);
    } finally { db()->rollBack(); }

    $t->group('Saved report signatures');
    $report = election_report_capture($closed);
    $t->ok('new saved reports omit quorum', !array_key_exists('quorum', $report));
    $t->same('snapshot stable across reads', $report, election_report_capture($closed));
    $t->same('active election has no closing snapshot', null, election_report_capture($eid));
    $csv = $admin->api('GET', 'operations/report&election=' . $closed . '&format=csv');
    $t->same('full summary downloads', 200, $csv['status']);
    $t->contains('CSV includes participation', 'Participation rate (%)', $csv['body']);
    $t->ok('CSV has no quorum fields', stripos($csv['body'], 'quorum') === false);
    $parseCsv = static function (string $text): array {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) $rows[] = $row;
        fclose($stream);
        return $rows;
    };
    $csvRows = $parseCsv($csv['body']);
    $t->ok('CSV keeps UTF-8 Excel marker', str_starts_with($csv['body'], "\xEF\xBB\xBF"));
    $t->same('CSV starts with title instead of blank row', 'CoopVote Election Summary', $csvRows[0][0]);
    $t->same('all summary rows have four columns', [4], array_values(array_unique(array_map('count', $csvRows))));
    $t->ok('candidate results have explicit column headings', in_array(['Position', 'Candidate', 'Votes', 'Result'], $csvRows, true));
    $t->contains('CSV response supplies a filename', 'attachment; filename="coopvote-election-' . $closed . '-summary.csv"', $csv['headers']);
    $t->contains('HTML prints to PDF', 'window.print()', $admin->api('GET', 'operations/report&election=' . $closed . '&format=html')['body']);
    $pdo->exec("UPDATE election_reports SET snapshot = '{}' WHERE election_id = $closed");
    $blocked = false;
    try { election_report_capture($closed); } catch (RuntimeException $e) { $blocked = true; }
    $t->ok('altered saved report rejected', $blocked);
    // Restore this scratch snapshot using its unchanged original data and signature.
    $pdo->prepare('UPDATE election_reports SET snapshot = ? WHERE election_id = ?')->execute([json_encode($report, JSON_THROW_ON_ERROR), $closed]);

    $t->group('Legacy signed reports without quorum in exports');
    $legacy = $report;
    $legacy['title'] = '=1+1';
    $legacy['positions'][0]['candidates'][0]['name'] = "Dela Cruz, \"José\" \\ Ana\nJr.";
    unset($legacy['participationRate']);
    $legacy['quorum'] = ['percent' => 50, 'required' => 7, 'status' => 'not_reached', 'participationRate' => 21.43];
    $legacyJson = json_encode($legacy, JSON_THROW_ON_ERROR);
    $legacySignature = integrity_mac('election-report-v1', [$legacyJson]);
    $pdo->prepare('UPDATE election_reports SET snapshot = ?, signature = ? WHERE election_id = ?')->execute([$legacyJson, $legacySignature, $closed]);
    foreach (['json', 'csv', 'html'] as $format) {
        $export = $admin->api('GET', 'operations/report&election=' . $closed . '&format=' . $format);
        $t->same('legacy ' . $format . ' still exports', 200, $export['status']);
        $t->ok('legacy ' . $format . ' omits quorum', stripos($export['body'], 'quorum') === false);
    }
    $t->same('legacy signature still verifies after exports', $legacy, election_report_capture($closed));
    $legacyRows = $parseCsv($admin->api('GET', 'operations/report&election=' . $closed . '&format=csv')['body']);
    $t->same('spreadsheet formula in title is treated as text', "'=1+1", $legacyRows[1][1]);
    $t->ok('quoted comma, backslash, accent and newline survive CSV parsing', in_array($legacy['positions'][0]['candidates'][0]['name'], array_column($legacyRows, 1), true));
    $row = $pdo->query("SELECT snapshot, signature FROM election_reports WHERE election_id = $closed")->fetch();
    $t->same('legacy stored bytes unchanged', $legacyJson, $row['snapshot']);
    $t->same('legacy stored signature unchanged', $legacySignature, $row['signature']);
    $t->same('legacy turnout uses frozen report counts', round($report['ballotsCast'] / $report['registeredVoters'] * 100, 2), election_report_view($legacy)['participationRate']);

    $t->group('Notification read state and result eligibility');
    $list = $voter->api('GET', 'notifications')['json']['notices'];
    $t->ok('member has schedule notices', count($list) > 0);
    $t->same('loading notices leaves them unread', $list, $voter->api('GET', 'notifications')['json']['notices']);
    $id = $list[0]['id'];
    $t->same('click records read status', 200, $voter->api('POST', 'notifications', ['id' => $id])['status']);
    $t->ok('read notice stays dismissed', !in_array($id, array_column($voter->api('GET', 'notifications')['json']['notices'], 'id'), true));
    $t->ok('admin notice stays unread', in_array($id, array_column($admin->api('GET', 'notifications')['json']['notices'], 'id'), true));
    $notices = election_notices(['user_id' => $fx['voters'][10], 'role_slug' => 'voter']);
    $t->ok('no result notice without participation', !array_filter($notices, static fn($n) => str_starts_with($n['id'], $closed . ':results:')));

    $t->group('Security severity and review');
    $insert = $pdo->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?,?,0)');
    for ($i = 0; $i < 10; $i++) $insert->execute(['probe', '192.0.2.42']);
    $get = static fn() => array_values(array_filter(security_alerts(), static fn($a) => $a['ip'] === '192.0.2.42'))[0];
    $alert = $get();
    $t->same('rapid failures high severity', 'high', $alert['severity']);
    $t->same('voter cannot review alert', 403, $voter->api('POST', 'security/review-alert', ['id' => $alert['id']])['status']);
    $t->same('admin reviews alert', 200, $admin->api('POST', 'security/review-alert', ['id' => $alert['id']])['status']);
    $t->ok('review persists', $get()['reviewed']);
    $insert->execute(['probe', '192.0.2.42']);
    $t->ok('new failure reopens alert in same second', !$get()['reviewed'] && $get()['id'] !== $alert['id']);
};

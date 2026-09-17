<?php
declare(strict_types=1);

return static function (Suite $t, PDO $pdo): void {
    $fx = tdb_seed($pdo);
    $t->group('Private vote receipts before and after publication');
    $anon = new Http('receipt_anon');
    $t->same('receipts require sign-in', 401, $anon->api('GET', 'voter/receipts')['status']);
    $admin = new Http('receipt_admin');
    $admin->login('t_admin');
    $t->same('admin cannot use the private voter receipt endpoint', 403, $admin->api('GET', 'voter/receipts')['status']);
    $voter = new Http('receipt_voter');
    $voter->login('t_voter1');
    $r = $voter->api('GET', 'voter/receipts');
    $t->same('voter can reopen a receipt', 200, $r['status']);
    $t->same('only their completed ballot appears', 1, count($r['json']['receipts'] ?? []));
    $receipt = $r['json']['receipts'][0];
    $t->same('stored receipt code is preserved', 'CV-TEST1000', $receipt['receiptCode']);
    $t->same('correct election is named', $fx['closed'], $receipt['electionId']);
    $t->same('released result is described as published', true, $receipt['published']);
    $t->ok('submission time is included', $receipt['castAt'] !== '—');
    $t->same('receipt data contains no choices or identity linkage fields',
        ['id', 'electionId', 'electionTitle', 'receiptCode', 'castAt', 'published'], array_keys($receipt));
    $t->ok('response is not cacheable', str_contains(strtolower(json_encode($r['headers'])), 'no-store'));

    $pdo->prepare('UPDATE elections SET results_released_at = NULL WHERE election_id = ?')->execute([$fx['closed']]);
    $r = $voter->api('GET', 'voter/receipts&election=' . $fx['live']);
    $t->same('active election selection cannot hide an older receipt', 'CV-TEST1000', $r['json']['receipts'][0]['receiptCode']);
    $t->same('unpublished result still has a receipt with waiting status', false, $r['json']['receipts'][0]['published']);
    $t->same('unpublished tallies remain inaccessible', [], $voter->api('GET', 'voter/results')['json']['elections']);
    $pdo->prepare('UPDATE elections SET results_released_at = NOW() WHERE election_id = ?')->execute([$fx['closed']]);
    $t->same('publication is reflected on refresh', true, $voter->api('GET', 'voter/receipts')['json']['receipts'][0]['published']);

    $other = new Http('receipt_other');
    $other->login('t_voter2');
    $r = $other->api('GET', 'voter/receipts&user_id=' . $fx['voters'][1] . '&id=' . $receipt['id']);
    $t->same('tampered query still returns the signed-in voters own receipt', 'CV-TEST2000', $r['json']['receipts'][0]['receiptCode']);
    $t->ok('another voters receipt never reaches the response', !str_contains($r['body'], 'CV-TEST1000'));
    $new = new Http('receipt_empty');
    $new->login('t_voter9');
    $t->same('nonvoter has an empty receipt list', [], $new->api('GET', 'voter/receipts')['json']['receipts']);
    $pdo->prepare('INSERT INTO ballots (election_id, user_id, has_voted) VALUES (?, ?, 0)')->execute([$fx['live'], $fx['voters'][9]]);
    $t->same('opening a ballot does not create a receipt', [], $new->api('GET', 'voter/receipts')['json']['receipts']);
    $t->same('receipt endpoint does not accept mutations', 405, $voter->api('POST', 'voter/receipts', [])['status']);

    $pdo->prepare('INSERT INTO ballots (election_id, user_id, has_voted, receipt_code, voted_at) VALUES (?, ?, 1, ?, DATE_ADD(NOW(), INTERVAL 1 SECOND))')
        ->execute([$fx['live'], $fx['voters'][1], 'CV-NEWRECEIPT']);
    $r = $voter->api('GET', 'voter/receipts');
    $t->same('multiple receipts are retained', 2, count($r['json']['receipts']));
    $t->same('newest submitted receipt appears first', 'CV-NEWRECEIPT', $r['json']['receipts'][0]['receiptCode']);
    $t->same('new receipt is visible while voting is still open', false, $r['json']['receipts'][0]['published']);
};

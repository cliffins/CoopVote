<?php
/** Anonymous vote signatures. The signing key is kept outside the database.
 * Detects changes since recording/baselining, not whole-system rollback or a
 * compromise of both the database and signing key. Never signs voter identity.
 */
declare(strict_types=1);

/** Runtime reads only; installation creates the key explicitly and never replaces it. */
function integrity_key(): string
{
    $key = getenv('COOPVOTE_INTEGRITY_KEY');
    if (!$key && DB_NAME === 'coopvote_test') return str_repeat('test-only-key-', 4);
    if (!$key && is_file(BASE_PATH . '/config/integrity-key.php')) {
        $key = require BASE_PATH . '/config/integrity-key.php';
    }
    if (!is_string($key) || strlen($key) < 32) {
        throw new RuntimeException('Vote integrity key is unavailable. Run the assurance upgrade or restore the original key.');
    }
    return $key;
}

/** Domain separation prevents a report signature being reused as a vote signature. */
function integrity_mac(string $domain, array $data): string
{
    return hash_hmac('sha256', $domain . '\n' . json_encode($data, JSON_THROW_ON_ERROR), integrity_key());
}

function integrity_state_seal(int $eid, int $count, int $baseline): string
{
    return integrity_mac('election-count-v1', [$eid, $count, $baseline]);
}

/** Canonical fixed-order fields; no user, ballot, receipt or precise timestamp. */
function integrity_vote_signature(array $vote, string $nonce, string $origin): string
{
    return integrity_mac('anonymous-vote-v1', [
        (int) $vote['vote_id'], (int) $vote['election_id'], (int) $vote['position_id'],
        (int) $vote['candidate_id'], (string) $vote['cast_date'], $nonce, $origin,
    ]);
}

/** Call after locking the election row, in the same transaction as vote insertion. */
function integrity_state(PDO $pdo, int $eid): array
{
    $s = $pdo->prepare('SELECT * FROM election_integrity WHERE election_id = ? FOR UPDATE');
    $s->execute([$eid]);
    $state = $s->fetch();
    if (!$state) {
        throw new RuntimeException('The election integrity register is missing. Administrator review is required.');
    }
    if (!hash_equals(integrity_state_seal($eid, (int) $state['vote_count'], (int) $state['baseline_count']), $state['seal'])) {
        throw new RuntimeException('The signed vote count does not match. Administrator review is required.');
    }
    return $state;
}

/** Serialize voting with report capture; evaluate the closing time after acquiring the lock. */
function integrity_lock_live_election(PDO $pdo, int $eid): array
{
    $lock = $pdo->prepare('SELECT * FROM elections WHERE election_id = ? FOR UPDATE');
    $lock->execute([$eid]);
    $election = $lock->fetch();
    if (!$election || !is_election_live($election)) {
        throw new RuntimeException('Voting closed before this ballot could be recorded.');
    }
    return integrity_state($pdo, $eid);
}

/** Protect only the newly inserted anonymous rows and update the signed count atomically. */
function integrity_record(PDO $pdo, int $eid, array $voteIds, array $state, string $origin = 'cast'): void
{
    $read = $pdo->prepare('SELECT vote_id, election_id, position_id, candidate_id, cast_date FROM votes WHERE vote_id = ? AND election_id = ?');
    $save = $pdo->prepare('INSERT INTO vote_integrity (vote_id, election_id, nonce, signature, origin) VALUES (?,?,?,?,?)');
    foreach ($voteIds as $id) {
        $read->execute([$id, $eid]);
        $vote = $read->fetch();
        if (!$vote) throw new RuntimeException('Cannot protect a missing vote.');
        $nonce = bin2hex(random_bytes(16));
        $save->execute([$id, $eid, $nonce, integrity_vote_signature($vote, $nonce, $origin), $origin]);
    }
    $count = (int) $state['vote_count'] + count($voteIds);
    $baseline = (int) $state['baseline_count'] + ($origin === 'baseline' ? count($voteIds) : 0);
    $pdo->prepare('UPDATE election_integrity SET vote_count = ?, baseline_count = ?, seal = ? WHERE election_id = ?')
        ->execute([$count, $baseline, integrity_state_seal($eid, $count, $baseline), $eid]);
}

/** Insert validated anonymous selections and their signatures within the ballot transaction. */
function integrity_insert_selections(PDO $pdo, int $eid, array $selections, array $state): void
{
    $ins = $pdo->prepare('INSERT INTO votes (election_id, position_id, candidate_id, cast_date) VALUES (?,?,?,CURDATE())');
    $ids = [];
    foreach ($selections as $positionId => $candidateId) {
        $ins->execute([$eid, $positionId, $candidateId]);
        $ids[] = (int) $pdo->lastInsertId();
    }
    integrity_record($pdo, $eid, $ids, $state);
}

/** Consistent read snapshot avoids false alarms while votes are being committed. */
function integrity_verify(int $eid): array
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM election_integrity WHERE election_id = ?');
        $s->execute([$eid]);
        $state = $s->fetch();
        $s = $pdo->prepare('SELECT v.*, i.nonce, i.signature, i.origin FROM votes v LEFT JOIN vote_integrity i ON i.vote_id = v.vote_id AND i.election_id = v.election_id WHERE v.election_id = ?');
        $s->execute([$eid]);
        $count = 0; $bad = 0;
        while ($vote = $s->fetch()) {
            $count++;
            if (!$vote['signature'] || !hash_equals(integrity_vote_signature($vote, (string) $vote['nonce'], (string) $vote['origin']), $vote['signature'])) $bad++;
        }
        $s = $pdo->prepare('SELECT COUNT(*) FROM vote_integrity WHERE election_id = ?');
        $s->execute([$eid]);
        $protected = (int) $s->fetchColumn();
        $validSeal = $state && hash_equals(integrity_state_seal($eid, (int) $state['vote_count'], (int) $state['baseline_count']), $state['seal']);
        $ok = $validSeal && !$bad && $count === $protected && $count === (int) $state['vote_count'];
        $status = !$state ? 'unprotected' : ($ok ? 'passed' : 'mismatch');
        if ($own) $pdo->commit();
        return ['status' => $status, 'checkedVotes' => $count, 'mismatchedVotes' => $bad,
            'baselineVotes' => (int) ($state['baseline_count'] ?? 0), 'checkedAt' => date('c'),
            'message' => $status === 'passed' ? 'Anonymous vote signatures and signed count match.'
                : ($status === 'unprotected' ? 'No integrity baseline is installed.' : 'Integrity mismatch. Review before publishing results.')];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Explicit install step only: never silently bless modified existing records. */
function integrity_baseline(PDO $pdo, int $eid): void
{
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Baselines can only be created from the command line.');
    db_transaction(static function (PDO $pdo) use ($eid): void {
        $s = $pdo->prepare('SELECT election_id FROM elections WHERE election_id = ? FOR UPDATE');
        $s->execute([$eid]);
        $s = $pdo->prepare('SELECT election_id FROM election_integrity WHERE election_id = ?');
        $s->execute([$eid]);
        if ($s->fetchColumn()) return;
        $s = $pdo->prepare('SELECT vote_id FROM votes WHERE election_id = ? ORDER BY vote_id');
        $s->execute([$eid]);
        $ids = $s->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('INSERT INTO election_integrity (election_id, seal) VALUES (?,?)')->execute([$eid, integrity_state_seal($eid, 0, 0)]);
        integrity_record($pdo, $eid, $ids, ['vote_count' => 0, 'baseline_count' => 0], 'baseline');
    });
}

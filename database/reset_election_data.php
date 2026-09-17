<?php
/**
 * CoopVote — clear voting data so an election can be run again.
 *
 * Removes cast votes, issued ballots and the results-release stamp for one
 * election (or all of them). Members, candidates, positions and the audit
 * trail are left untouched.
 *
 * Run from the project root:
 *     C:\xampp\php\php.exe database\reset_election_data.php          (all)
 *     C:\xampp\php\php.exe database\reset_election_data.php 1        (election 1)
 *
 * Add --purge-test-accounts to also delete accounts created by test runs.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../config/database.php';

$args       = array_slice($argv, 1);
$purge      = in_array('--purge-test-accounts', $args, true);
$electionId = 0;

foreach ($args as $a) {
    if (ctype_digit($a)) {
        $electionId = (int) $a;
    }
}

$pdo = db();
$pdo->beginTransaction();

try {
    if ($electionId > 0) {
        $where  = 'WHERE election_id = ?';
        $params = [$electionId];
        echo "Resetting election #{$electionId}…\n";
    } else {
        $where  = '';
        $params = [];
        echo "Resetting every election…\n";
    }

    $v = $pdo->prepare("DELETE FROM votes $where");
    $v->execute($params);
    echo "  votes deleted:   {$v->rowCount()}\n";

    $b = $pdo->prepare("DELETE FROM ballots $where");
    $b->execute($params);
    echo "  ballots cleared: {$b->rowCount()}\n";

    $r = $pdo->prepare(
        'UPDATE elections SET results_released_at = NULL, released_by = NULL '
        . ($electionId > 0 ? 'WHERE election_id = ?' : 'WHERE results_released_at IS NOT NULL')
    );
    $r->execute($electionId > 0 ? [$electionId] : []);
    echo "  results un-published: {$r->rowCount()}\n";

    if ($purge) {
        /* Accounts created by the automated test runs. */
        $t = $pdo->prepare(
            "DELETE FROM users
              WHERE username LIKE 'testapplicant%'
                 OR username LIKE 'ramon%'
                 OR email LIKE '%@example.com'"
        );
        $t->execute();
        echo "  test accounts removed: {$t->rowCount()}\n";
    }

    $pdo->commit();
    echo "Done.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

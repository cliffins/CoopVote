<?php
/**
 * CoopVote — restore the database from a dump file
 *
 *   php tools/restore_from_dump.php database/coopvote_full.sql
 *
 * Written after a dump was piped straight into the mysql client and its
 * `USE `coopvote`;` line silently redirected the import to the live
 * database, dropping and recreating every table there.
 *
 * This script removes the statements that choose a database, so the file
 * can only ever be restored into the database named in config/config.php —
 * whichever one that is — and never into whichever one the dump happens to
 * mention. It prints the row counts afterwards so the result is checked
 * rather than assumed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This runs from the command line only.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/sql-import.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    exit("Usage: php tools/restore_from_dump.php <dump.sql>\n");
}

$sql = (string) file_get_contents($file);

/*
 * Drop every statement that names a database. A dump written on one machine
 * should not decide which database it lands in on another.
 */
$sql = implode("\n", array_filter(
    preg_split('/\R/', $sql) ?: [],
    static fn(string $line): bool => !preg_match(
        '/\b(?:DROP|CREATE)\s+DATABASE\b|^\s*(?:\/\*![0-9]*\s*)?USE\s/i',
        $line
    )
));

if (preg_match('/\b(?:DROP|CREATE)\s+DATABASE\b/i', $sql) || preg_match('/^\s*USE\s/mi', $sql)) {
    exit("Refusing to run: a database-selecting statement survived filtering.\n");
}

echo 'Restoring ' . $file . ' into "' . DB_NAME . '"…' . PHP_EOL;

$pdo = db();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
sql_import($pdo,$sql);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo PHP_EOL . 'Row counts now in "' . DB_NAME . '":' . PHP_EOL;
foreach (['users', 'members', 'roles', 'elections', 'positions', 'candidates',
          'ballots', 'votes', 'audit_logs', 'login_attempts', 'settings'] as $table) {
    try {
        printf("  %-16s %d\n", $table, (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn());
    } catch (Throwable $e) {
        printf("  %-16s (missing)\n", $table);
    }
}

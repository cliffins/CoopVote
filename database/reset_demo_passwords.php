<?php
/**
 * CoopVote — reset the demo account passwords.
 *
 * The original password hashes could not be recovered (bcrypt is one-way),
 * so this restores a known set of credentials for local development.
 *
 * Run from the project root:
 *     C:\xampp\php\php.exe database\reset_demo_passwords.php
 *
 * Delete this file before deploying anywhere public.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../config/database.php';

/* Two roles: an administrator and the members who vote. The old officer
   and auditor accounts are administrators now, and keep their passwords. */
$passwords = [
    'admin' => 'Admin@123',
];
for ($i = 1; $i <= 8; $i++) {
    $passwords["voter$i"] = 'Voter@123';
}
$passwords['pending1'] = 'Voter@123';

$pdo  = db();
$stmt = $pdo->prepare(
    'UPDATE users
        SET password_hash = ?, failed_attempts = 0, locked_until = NULL
      WHERE username = ?'
);

$done = 0;
foreach ($passwords as $username => $plain) {
    $stmt->execute([password_hash($plain, PASSWORD_BCRYPT), $username]);
    if ($stmt->rowCount() > 0) {
        printf("  %-10s → %s\n", $username, $plain);
        $done++;
    }
}

echo "\nReset {$done} account(s).\n";

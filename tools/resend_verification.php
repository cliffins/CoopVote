<?php
/**
 * CoopVote — send a fresh verification code to a stuck registration.
 *
 * If somebody registered while email was switched off (or the message was
 * lost), their account sits at `pending_verification` with a code they
 * never received. Rather than making them register again — which the
 * duplicate username and email checks would block anyway — issue a new one.
 *
 *   C:\xampp\php\php.exe tools\resend_verification.php            (list them)
 *   C:\xampp\php\php.exe tools\resend_verification.php gladz      (send one)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

$who = $argv[1] ?? '';

/* ── No argument: list who is waiting ── */
if ($who === '') {
    $rows = db()->query(
        "SELECT u.user_id, u.username, u.email, m.full_name, m.member_no, u.created_at
           FROM users u LEFT JOIN members m ON m.user_id = u.user_id
          WHERE u.status = 'pending_verification'
          ORDER BY u.user_id"
    )->fetchAll();

    if (!$rows) {
        exit("No accounts are waiting for email verification.\n");
    }

    echo "Accounts still waiting to verify their email:\n\n";
    foreach ($rows as $r) {
        printf("  %-18s %-34s %s  (registered %s)\n",
            $r['username'], $r['email'], $r['member_no'] ?? '—', $r['created_at']);
    }
    echo "\nSend a fresh code with:\n";
    echo "  C:\\xampp\\php\\php.exe tools\\resend_verification.php <username>\n";
    exit(0);
}

/* ── Send a new code ── */
$s = db()->prepare(
    "SELECT u.user_id, u.username, u.email, u.status, m.full_name
       FROM users u LEFT JOIN members m ON m.user_id = u.user_id
      WHERE u.username = ? OR u.email = ?
      LIMIT 1"
);
$s->execute([$who, $who]);
$user = $s->fetch();

if (!$user) {
    exit("No account found for \"{$who}\".\n");
}

if ($user['status'] !== 'pending_verification') {
    exit("\"{$user['username']}\" is already past verification (status: {$user['status']}).\n");
}

if (!MAIL_ENABLED) {
    echo "MAIL_ENABLED is false — the code will be written to logs/mail.log instead of sent.\n";
}

$code = otp_issue((int) $user['user_id'], 'register');
$sent = mail_otp_code(
    (string) $user['email'],
    (string) ($user['full_name'] ?? $user['username']),
    $code,
    OTP_TTL_MINUTES
);

audit((int) $user['user_id'], 'auth.otp_resent', 'user', (int) $user['user_id'],
    'Verification code re-sent by an administrator');

if ($sent) {
    echo "A new code was sent to {$user['email']}.\n";
    echo "It is valid for " . OTP_TTL_MINUTES . " minutes.\n\n";
    echo "Tell them to sign in at login.html with their usual username and\n";
    echo "password — the verification screen appears there automatically for\n";
    echo "an unverified account, and they can enter the code (or ask for a\n";
    echo "fresh one from that screen).\n";
} else {
    echo "Could not send the message — check logs/mail.log for the reason.\n";
}

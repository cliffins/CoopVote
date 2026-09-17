<?php
/**
 * CoopVote — send one test email.
 *
 * Use this to prove the Gmail settings work BEFORE turning MAIL_ENABLED on,
 * so no member ever receives a broken message.
 *
 *   C:\xampp\php\php.exe tools\send_test_email.php you@example.com
 *
 * It reports exactly which setting is missing rather than failing silently,
 * and it ignores MAIL_ENABLED on purpose — that flag is for the app.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../config/config.php';

$to = $argv[1] ?? '';
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    exit("Usage: php tools\\send_test_email.php you@example.com\n");
}

/* ── Check the settings first, with a specific message for each ── */
$problems = [];
if (MAIL_USERNAME === '')     $problems[] = 'MAIL_USERNAME is empty — put your full Gmail address there.';
if (MAIL_PASSWORD === '')     $problems[] = 'MAIL_PASSWORD is empty — paste the 16-character App Password.';
if (strlen(str_replace(' ', '', MAIL_PASSWORD)) === 16 && str_contains(MAIL_PASSWORD, ' ')) {
    $problems[] = 'MAIL_PASSWORD still has spaces in it — remove them.';
}
if (MAIL_USERNAME !== '' && !filter_var(MAIL_USERNAME, FILTER_VALIDATE_EMAIL)) {
    $problems[] = 'MAIL_USERNAME does not look like an email address.';
}
if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    $problems[] = 'vendor/autoload.php is missing — run: composer install';
}

if ($problems) {
    echo "Cannot send yet:\n";
    foreach ($problems as $p) {
        echo "  - $p\n";
    }
    echo "\nEdit config/config.php, then run this again.\n";
    exit(1);
}

echo "Sending a test message…\n";
echo "  host    : " . MAIL_HOST . ':' . MAIL_PORT . ' (' . MAIL_SECURE . ")\n";
echo "  from    : " . (MAIL_FROM_ADDRESS !== '' ? MAIL_FROM_ADDRESS : MAIL_USERNAME) . "\n";
echo "  to      : $to\n\n";

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

try {
    $m = new PHPMailer(true);

    /* Show the SMTP conversation — this is the point of the tool. */
    $m->SMTPDebug  = 2;
    $m->Debugoutput = static fn($str, $level) => print('  smtp: ' . trim($str) . "\n");

    $m->isSMTP();
    $m->Host       = MAIL_HOST;
    $m->Port       = MAIL_PORT;
    $m->SMTPAuth   = true;
    $m->Username   = MAIL_USERNAME;
    $m->Password   = MAIL_PASSWORD;
    $m->SMTPSecure = MAIL_SECURE === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $m->CharSet = 'UTF-8';
    $m->Timeout = 20;

    $m->setFrom(MAIL_FROM_ADDRESS !== '' ? MAIL_FROM_ADDRESS : MAIL_USERNAME, MAIL_FROM_NAME);
    $m->addAddress($to);

    $m->isHTML(true);
    $m->Subject = 'CoopVote test message';
    $m->Body    = '<p>This is a test message from CoopVote.</p>'
                . '<p>If you are reading this, the Gmail settings are correct. '
                . 'You can now set <code>MAIL_ENABLED</code> to <code>true</code> '
                . 'in <code>config/config.php</code>.</p>';
    $m->AltBody = "This is a test message from CoopVote.\n\n"
                . "If you are reading this, the Gmail settings are correct. "
                . "Set MAIL_ENABLED to true in config/config.php.";

    $m->send();

    echo "\nSUCCESS — the message was accepted by Gmail.\n";
    echo "Check the inbox of $to (look in Spam too, the first time).\n";
    echo "\nNow set  MAIL_ENABLED = true;  in config/config.php.\n";
    exit(0);
} catch (MailException $e) {
    echo "\nFAILED: " . $e->getMessage() . "\n\n";
    echo "Common causes:\n";
    echo "  - \"Username and Password not accepted\": you used your normal Gmail\n";
    echo "    password. It must be an App Password (16 characters).\n";
    echo "  - \"Could not connect\": port " . MAIL_PORT . " is blocked, or\n";
    echo "    php_openssl is not enabled in php.ini.\n";
    echo "  - App Passwords not offered by Google: 2-Step Verification is off.\n";
    exit(1);
} catch (Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    exit(1);
}

<?php
/**
 * CoopVote — outgoing email
 *
 * Every message is written in English only.
 *
 * Nothing in here is allowed to break the request that triggered it. A
 * member's vote must be recorded whether or not the receipt email goes
 * out, so every failure is caught, logged and reported as a false return
 * value rather than thrown.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/** Append one line to logs/mail.log. Silent if logging is off. */
function mail_log(string $line): void
{
    if (!MAIL_DEBUG_LOG) {
        return;
    }
    try {
        $dir = BASE_PATH . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'mail.log',
            date('Y-m-d H:i:s') . '  ' . $line . PHP_EOL,
            FILE_APPEND
        );
    } catch (Throwable) {
        // logging must never matter more than the request
    }
}

/**
 * Send one message.
 *
 * @param  string $to      recipient address
 * @param  string $toName  recipient display name
 * @param  string $subject plain subject line
 * @param  string $heading headline inside the message
 * @param  string $intro   opening sentence
 * @param  array  $rows    label => value pairs shown as a detail table
 * @param  string $closing final paragraph
 * @return bool            true when handed to the SMTP server
 */
function send_mail(
    string $to,
    string $toName,
    string $subject,
    string $heading,
    string $intro,
    array $rows = [],
    string $closing = ''
): bool {
    if (!MAIL_ENABLED) {
        mail_log("SKIPPED (MAIL_ENABLED is false) -> {$to} | {$subject}");
        return false;
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        mail_log("SKIPPED (invalid address) -> {$to}");
        return false;
    }

    if (MAIL_USERNAME === '' || MAIL_PASSWORD === '') {
        mail_log("SKIPPED (MAIL_USERNAME or MAIL_PASSWORD is empty) -> {$to}");
        return false;
    }

    $autoload = BASE_PATH . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        mail_log('SKIPPED (vendor/autoload.php missing — run composer install)');
        return false;
    }
    require_once $autoload;

    try {
        $m = new PHPMailer(true);

        $m->isSMTP();
        $m->Host = MAIL_HOST;
        $m->Port = MAIL_PORT;
        $m->SMTPAuth = true;
        $m->Username = MAIL_USERNAME;
        $m->Password = MAIL_PASSWORD;
        $m->SMTPSecure = MAIL_SECURE === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;

        $m->CharSet = 'UTF-8';
        $m->Timeout = 15;                       // never hang a page on SMTP

        $from = MAIL_FROM_ADDRESS !== '' ? MAIL_FROM_ADDRESS : MAIL_USERNAME;
        $m->setFrom($from, MAIL_FROM_NAME);
        if (MAIL_REPLY_TO !== '') {
            $m->addReplyTo(MAIL_REPLY_TO);
        }
        $m->addAddress($to, $toName);

        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body = mail_html($heading, $intro, $rows, $closing);
        $m->AltBody = mail_text($heading, $intro, $rows, $closing);

        $m->send();
        mail_log("SENT -> {$to} | {$subject}");
        return true;
    } catch (MailException $e) {
        mail_log("FAILED -> {$to} | {$subject} | " . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        mail_log("ERROR -> {$to} | {$subject} | " . $e->getMessage());
        return false;
    }
}

/** The HTML body, in the app's navy-and-gold colours. */
function mail_html(string $heading, string $intro, array $rows, string $closing): string
{
    $esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $table = '';
    foreach ($rows as $label => $value) {
        $table .= '<tr>'
            . '<td style="padding:8px 0;color:#7a8ba0;font-size:13px;width:42%">' . $esc($label) . '</td>'
            . '<td style="padding:8px 0;color:#0b1931;font-size:14px;font-weight:600">' . $esc($value) . '</td>'
            . '</tr>';
    }

    $closingHtml = $closing !== ''
        ? '<p style="margin:18px 0 0;color:#44546a;font-size:14px;line-height:1.65">' . $esc($closing) . '</p>'
        : '';

    return '<!doctype html><html><body style="margin:0;padding:24px;background:#eef1f6;'
        . 'font-family:Segoe UI,Helvetica,Arial,sans-serif">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px;'
        . 'margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;'
        . 'box-shadow:0 6px 24px rgba(11,25,49,.10)">'

        . '<tr><td style="background:#0b1931;padding:22px 28px">'
        . '<span style="color:#fff;font-size:19px;font-weight:700;letter-spacing:.3px">Coop</span>'
        . '<span style="color:#c9a84c;font-size:19px;font-weight:700">Vote</span>'
        . '</td></tr>'

        . '<tr><td style="padding:28px">'
        . '<h1 style="margin:0 0 12px;color:#0b1931;font-size:19px">' . $esc($heading) . '</h1>'
        . '<p style="margin:0;color:#44546a;font-size:14px;line-height:1.65">' . $esc($intro) . '</p>'
        . ($table !== ''
            ? '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" '
            . 'style="margin:20px 0 0;border-top:1px solid #e4e9f0">' . $table . '</table>'
            : '')
        . $closingHtml
        . '</td></tr>'

        . '<tr><td style="background:#f5f7fa;padding:16px 28px;color:#8794a6;font-size:12px;line-height:1.6">'
        . 'This is an automated message from CoopVote. Please do not reply to it.'
        . '</td></tr>'

        . '</table></body></html>';
}

/** Plain-text alternative, for clients that will not render HTML. */
function mail_text(string $heading, string $intro, array $rows, string $closing): string
{
    $out = "CoopVote\n\n" . $heading . "\n\n" . $intro . "\n";

    foreach ($rows as $label => $value) {
        $out .= "\n" . $label . ': ' . $value;
    }
    if ($rows) {
        $out .= "\n";
    }
    if ($closing !== '') {
        $out .= "\n" . $closing . "\n";
    }

    return $out . "\nThis is an automated message from CoopVote. Please do not reply to it.\n";
}

/* ═══════════════════════════════════════════════════════════
   The messages this app sends
   ═══════════════════════════════════════════════════════════ */

/**
 * The one-time code that proves the applicant owns the email address.
 *
 * While MAIL_ENABLED is false the code is written to logs/mail.log so the
 * flow can be exercised without a mail server. Once mail is switched on the
 * code is never logged — see otp_debug_note().
 */
function mail_otp_code(string $to, string $name, string $code, int $minutes): bool
{
    $sent = send_mail(
        $to,
        $name,
        'Your CoopVote verification code: ' . $code,
        'Verify your email address',
        'Enter this code on the registration page to finish creating your account. '
        . 'It expires in ' . $minutes . ' minutes.',
        ['Verification code' => $code, 'Valid for' => $minutes . ' minutes'],
        'If you did not try to register with CoopVote, you can ignore this message — '
        . 'no account is created until the code is entered.'
    );

    otp_debug_note($to, $code);
    return $sent;
}

/**
 * Local-testing aid: with mail switched off there is no other way to learn
 * the code, so record it. Deliberately does nothing once MAIL_ENABLED is
 * true — a live system must never keep verification codes in a log file.
 */
function otp_debug_note(string $to, string $code): void
{
    if (MAIL_ENABLED) {
        return;
    }
    mail_log("OTP (mail off, for local testing only) -> {$to} | code {$code}");
}

/** Sent the moment somebody registers. */
function mail_registration_received(string $to, string $name, string $memberNo): bool
{
    return send_mail(
        $to,
        $name,
        'We received your CoopVote registration',
        'Registration received',
        'Thank you for registering with the cooperative. An administrator will review '
        . 'your details and the ID you uploaded before your membership is approved.',
        ['Name' => $name, 'Member number' => $memberNo, 'Status' => 'Awaiting approval'],
        'You will get another email once a decision has been made. '
        . 'You cannot sign in until your membership is approved.'
    );
}

/** Sent when an administrator approves the membership. */
function mail_membership_approved(string $to, string $name, string $memberNo): bool
{
    return send_mail(
        $to,
        $name,
        'Your CoopVote membership has been approved',
        'Your membership is approved',
        'Your cooperative membership has been approved. You can now sign in and '
        . 'take part in any election that is open.',
        ['Name' => $name, 'Member number' => $memberNo, 'Status' => 'Active'],
        'Sign in with the username and password you chose when you registered.'
    );
}

/** Sent when an administrator rejects the application. */
/**
 * The cooperative office opened the account on the member's behalf, so
 * this is the first the member hears of it — it has to carry the sign-in
 * details, not just announce itself.
 *
 * The temporary password travels in the clear because there is nowhere
 * else for it to go; the member is told to change it on first sign-in.
 */
function mail_account_created(
    string $to,
    string $name,
    string $memberNo,
    string $username,
    string $tempPassword
): bool {
    return send_mail(
        $to,
        $name,
        'Your CoopVote account is ready',
        'An account has been created for you',
        'The cooperative office has registered you for online voting. Sign in '
        . 'with the details below, and change the password once you are in.',
        [
            'Name'               => $name,
            'Member number'      => $memberNo,
            'Username'           => $username,
            'Temporary password' => $tempPassword,
        ],
        'Please change this password after your first sign-in. If you did not '
        . 'expect this account, contact the cooperative office.'
    );
}

function mail_membership_rejected(string $to, string $name, string $memberNo): bool
{
    return send_mail(
        $to,
        $name,
        'About your CoopVote registration',
        'Your registration was not approved',
        'After reviewing your application, the cooperative was not able to approve '
        . 'your membership at this time.',
        ['Name' => $name, 'Member number' => $memberNo, 'Status' => 'Not approved'],
        'If you believe this is a mistake, please contact the cooperative office. '
        . 'They can review your application again.'
    );
}

/** Sent after a ballot is cast — the member's proof of voting. */
function mail_vote_receipt(
    string $to,
    string $name,
    string $electionTitle,
    string $receipt,
    string $castAt,
    int $selections
): bool {
    return send_mail(
        $to,
        $name,
        'Your CoopVote voting receipt — ' . $receipt,
        'Your vote has been recorded',
        'Thank you for voting. Keep this receipt as proof that your ballot was '
        . 'counted. It does not reveal who you voted for — the cooperative cannot '
        . 'link a ballot back to a member.',
        [
            'Election' => $electionTitle,
            'Receipt code' => $receipt,
            'Date recorded' => $castAt,
            'Positions' => (string) $selections,
        ],
        'Your vote cannot be changed or withdrawn once submitted.'
    );
}

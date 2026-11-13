<?php
/**
 * CoopVote — application configuration
 */

declare(strict_types=1);

// CoopVote application constants.
defined('APP_NAME') || define('APP_NAME', 'CoopVote');
defined('SESSION_NAME') || define('SESSION_NAME', 'coopvote_session');
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
defined('UPLOAD_PATH') || define('UPLOAD_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'uploads');
defined('MAX_UPLOAD_BYTES') || define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024);


/* ── Database ───────────────────────────────────────────── */
require_once __DIR__ . '/runtime.php';

// Test processes supply their own settings and never load production credentials.
$dbSettings = deployment_settings();
if (getenv('COOPVOTE_ENV') !== 'test') {
    $dbPath = dirname(BASE_PATH) . '/private/coopvote-db.php';
    if (!is_readable($dbPath)) {
        config_failure('CONFIG_FILE_UNREADABLE', 'The private database configuration is not readable.');
    }
    ob_start();
    try {
        $dbConfig = require $dbPath;
        $dbOutput = ob_get_clean();
    } catch (Throwable) {
        ob_end_clean();
        config_failure('CONFIG_FILE_INVALID', 'The private database configuration could not be loaded.');
    }
    if ($dbOutput !== '' || !is_array($dbConfig)) {
        config_failure('CONFIG_FILE_INVALID', 'The private database configuration must return an array without output.');
    }
    foreach (['host'=>'db_host', 'port'=>'db_port', 'name'=>'db_name', 'user'=>'db_user', 'pass'=>'db_password'] as $key=>$setting) {
        if (!array_key_exists($key, $dbConfig)) {
            config_failure('DB_CONFIG_INVALID', 'A required private database setting is missing.');
        }
        $dbSettings[$setting] = $dbConfig[$key];
    }
}
define('DB_SETTINGS', database_settings($dbSettings, 'getenv'));
define('DB_HOST', DB_SETTINGS['host']);
define('DB_PORT', DB_SETTINGS['port']);
define('DB_NAME', DB_SETTINGS['name']);
define('DB_USER', DB_SETTINGS['user']);
define('DB_PASS', DB_SETTINGS['password']);
define('DB_CHARSET', 'utf8mb4');
unset($dbConfig, $dbSettings, $dbPath, $dbOutput, $key, $setting);

/**
 * Role slugs that may reach /api/admin/* endpoints.
 *
 * The cooperative runs two roles: an administrator, who operates the back
 * office, and a member, who votes. Election Officer and Auditor are gone.
 * The two accounts that held them were made administrators rather than
 * deleted, so the elections they created and the actions in the audit trail
 * still name who did them.
 */
const ADMIN_ROLES = ['admin'];

/**
 * Roles allowed to change data.
 *
 * The same list as above now that the read-only Auditor is gone. It is kept
 * as its own constant because the two mean different things — "may open the
 * back office" and "may change what is in it" — and a read-only role is the
 * sort of thing a cooperative asks for again.
 */
const WRITE_ROLES = ['admin'];

/* ── Email (PHPMailer over Gmail SMTP) ─────────────────────
 *
 * Off by default: with this false the app behaves exactly as before and
 * sends nothing, so a half-filled config can never spray mail at members.
 * Turn it on only once `php tools/send_test_email.php you@example.com`
 * succeeds. See docs/EMAIL_SETUP.md for the Gmail steps.
 *
 * MAIL_PASSWORD is a Google **App Password**, not your normal Gmail
 * password — Google refuses plain passwords from apps like this one.
 */
/*
 * Sending is on by default. COOPVOTE_MAIL=0 turns it off for the duration
 * of one process — the automated test suite sets it, so a test run can
 * exercise registration and voting without posting real mail to real
 * addresses through the cooperative's Gmail account.
 */
define('MAIL_ENABLED', getenv('COOPVOTE_MAIL') === '1');

const MAIL_HOST     = 'smtp.gmail.com';
const MAIL_PORT     = 587;             // 587 = STARTTLS, 465 = SMTPS
const MAIL_SECURE   = 'tls';           // 'tls' for 587, 'ssl' for 465
define('MAIL_USERNAME', getenv('COOPVOTE_MAIL_USERNAME') ?: '');              // your full Gmail address
define('MAIL_PASSWORD', getenv('COOPVOTE_MAIL_PASSWORD') ?: '');              // 16-character App Password, no spaces

/* Shown as the sender. Gmail rewrites the address to MAIL_USERNAME. */
define('MAIL_FROM_ADDRESS', getenv('COOPVOTE_MAIL_FROM_ADDRESS') ?: MAIL_USERNAME);
const MAIL_FROM_NAME    = 'CoopVote';

/** Where members are told to reply; blank uses the from address. */
const MAIL_REPLY_TO = '';

/** Write every attempt to logs/mail.log — useful while setting it up. */
const MAIL_DEBUG_LOG = true;

/* ── Error reporting ────────────────────────────────────── */
/*
 * Off by default, because DEBUG puts the exception message, file and line
 * into the JSON the browser receives — and that JSON goes to whoever asked,
 * signed in or not. A malformed sign-in request answered with
 * "Array to string conversion @ auth.php:48", which tells a stranger the
 * layout of the source.
 *
 * Errors are still written to the PHP error log either way, so nothing is
 * lost. To see the detail in the browser while developing, either set the
 * COOPVOTE_DEBUG environment variable to 1, or change the fallback below
 * to true and remember to change it back before the cooperative uses it.
 */
define('DEBUG', getenv('COOPVOTE_DEBUG') === '1');

/* Errors are logged whether or not DEBUG is on; they are never printed,
   because anything echoed would land in the middle of a JSON body. */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set('Asia/Manila');

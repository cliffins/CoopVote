<?php
/**
 * CoopVote — application configuration
 */

declare(strict_types=1);

/* ── Database ───────────────────────────────────────────── */
const DB_HOST    = '127.0.0.1';
const DB_PORT    = 3306;
/**
 * The live database. COOPVOTE_DB overrides it so the automated test suite
 * can run against a scratch copy and never touch real member data.
 * Nothing else sets this variable.
 */
define('DB_NAME', getenv('COOPVOTE_DB') ?: 'coopvote');
const DB_USER    = 'root';
const DB_PASS    = '';          // default XAMPP root password is empty
const DB_CHARSET = 'utf8mb4';

/* ── Application ────────────────────────────────────────── */
const APP_NAME    = 'CoopVote';
const SESSION_NAME = 'coopvote_session';

/** Absolute path to the project root (…/htdocs/CoopVote). */
define('BASE_PATH', dirname(__DIR__));

/** Where legacy registration ID uploads land; profile pictures live in MariaDB. */
define('UPLOAD_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'uploads');

/** Max upload size for legacy registration ID photos (5 MB). */
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

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
define('MAIL_ENABLED', getenv('COOPVOTE_MAIL') !== '0');

const MAIL_HOST     = 'smtp.gmail.com';
const MAIL_PORT     = 587;             // 587 = STARTTLS, 465 = SMTPS
const MAIL_SECURE   = 'tls';           // 'tls' for 587, 'ssl' for 465
const MAIL_USERNAME = 'coopvote5@gmail.com';              // your full Gmail address
const MAIL_PASSWORD = 'wtionnbnnjrunviq';              // 16-character App Password, no spaces

/* Shown as the sender. Gmail rewrites the address to MAIL_USERNAME. */
const MAIL_FROM_ADDRESS = 'coopvote5@gmail.com';
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

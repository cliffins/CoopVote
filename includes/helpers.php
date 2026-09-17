<?php
/**
 * CoopVote — request/response helpers
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/validation.php';

/** Send a JSON response and stop. */
function json_out(mixed $data, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Send a JSON error and stop. */
function json_error(string $message, int $status = 400, array $extra = []): never
{
    json_out(['ok' => false, 'error' => $message] + $extra, $status);
}

/**
 * Answer the browser now, then keep running to finish slow work.
 *
 * Sending mail over Gmail SMTP costs about five seconds. Doing that
 * inside the request froze the whole application, not just the button
 * that started it: PHP holds an exclusive lock on the session file for
 * the lifetime of a request, so every other page the same administrator
 * opened sat waiting for its data until the mail server replied. Pages
 * came up blank and the app looked hung.
 *
 * Closing the session first drops that lock, and flushing a complete
 * response lets the browser treat the request as finished while PHP
 * carries on with the mail.
 *
 * @param callable():void $work Runs after the response has been sent.
 */
function respond_then(mixed $data, callable $work, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Connection: close');
    }

    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    /* The browser only stops reading once it has the promised number of
       bytes, so the length has to be exact — no compression handler may
       still be sitting on the stream. */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    echo $body;
    $len = ob_get_length();
    if (!headers_sent()) {
        header('Content-Length: ' . $len);
    }
    ob_end_flush();
    flush();

    /* Release the session lock so other pages stop queuing behind us. */
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    /* PHP-FPM can hand the connection back properly; mod_php cannot, but
       the flush above is enough there in practice. */
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    /* The user has their answer — losing the connection must not stop
       the follow-up work, and it must never surface as a failed request. */
    ignore_user_abort(true);
    try {
        $work();
    } catch (Throwable $e) {
        error_log('respond_then: ' . $e->getMessage());
    }
    exit;
}

/** Decoded JSON request body (falls back to form-encoded POST). */
function body(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);

    if (is_array($decoded)) {
        return $cache = $decoded;
    }
    return $cache = $_POST;
}

/** One field from the JSON/form body. */
function input(string $key, mixed $default = null): mixed
{
    $b = body();
    return array_key_exists($key, $b) ? $b[$key] : $default;
}

/** Trimmed string field from the body. */
function input_str(string $key, string $default = ''): string
{
    $v = input($key, $default);
    return is_scalar($v) ? trim_form_text((string) $v) : $default;
}

/**
 * A string field taken exactly as sent — no trimming.
 *
 * Passwords are the reason this exists: a trailing space is a legitimate
 * part of one, so input_str() cannot be used. Anything that is not a
 * scalar (a JSON array or object, which is what a hand-made request sends)
 * becomes the default rather than being cast, because casting an array to
 * string raises a warning that the API turns into a 500 — and the 500 then
 * told the sender which file and line it came from.
 */
function input_scalar(string $key, string $default = ''): string
{
    $v = input($key, $default);
    return is_scalar($v) ? (string) $v : $default;
}

/** JSON-only writes cannot be submitted by a cross-origin HTML form. */
function require_json_request(): void
{
    $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($type !== 'application/json') json_error('Send this request as JSON.', 415);
}

function require_method(string ...$methods): void
{
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($m, $methods, true)) {
        json_error('Method ' . $m . ' not allowed on this endpoint.', 405);
    }
}

/** Best-effort client IP. */
function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** Truncated user-agent string. */
function user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/**
 * Rough device label from the user-agent.
 *
 * @retained Its one caller was the Voter Vote Log on the security page,
 * which is gone: that page shows administrative actions only now. Kept,
 * with its unit tests, for whenever a device column is wanted again.
 */
function device_label(?string $ua = null): string
{
    $ua = $ua ?? user_agent();
    return match (true) {
        stripos($ua, 'iphone')  !== false => 'iPhone',
        stripos($ua, 'ipad')    !== false => 'iPad',
        stripos($ua, 'android') !== false => 'Android',
        stripos($ua, 'mac')     !== false => 'Mac',
        stripos($ua, 'linux')   !== false => 'Linux',
        stripos($ua, 'windows') !== false => 'Windows',
        default                           => 'Unknown',
    };
}

/* ── Formatting ─────────────────────────────────────────── */

/** "January 15, 2026" — matches the UI's long-date style. */
function fmt_date(?string $dt): string
{
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return $ts ? date('F j, Y', $ts) : '—';
}

/** "January 15, 2026 · 3:00 PM" */
function fmt_datetime(?string $dt): string
{
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return $ts ? date('F j, Y · g:i A', $ts) : '—';
}

/** "March 2024" — used for "member since". */
function fmt_month_year(?string $dt): string
{
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return $ts ? date('F Y', $ts) : '—';
}

/** ISO-8601 for the JS countdown timer. */
function fmt_iso(?string $dt): ?string
{
    if (!$dt) return null;
    $ts = strtotime($dt);
    return $ts ? date('c', $ts) : null;
}

/** "2 mins ago" / "3 hours ago" — activity feeds. */
function fmt_relative(?string $dt): string
{
    if (!$dt) return '—';
    $ts = strtotime($dt);
    if (!$ts) return '—';

    $diff = time() - $ts;
    if ($diff < 0)    return 'just now';
    if ($diff < 60)   return $diff <= 5 ? 'just now' : $diff . ' secs ago';
    if ($diff < 3600) {
        $m = (int) floor($diff / 60);
        return $m . ' min' . ($m === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $h = (int) floor($diff / 3600);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    $d = (int) floor($diff / 86400);
    if ($d < 30) return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
    return fmt_date($dt);
}

/**
 * Split "Juan D. Dela Cruz" into a first / last pair.
 * The UI wants separate fields but `members` stores one full_name.
 */
function split_name(string $full): array
{
    $full  = trim(preg_replace('/\s+/', ' ', $full));
    if ($full === '') return ['first' => '', 'last' => ''];

    $parts = explode(' ', $full);
    if (count($parts) === 1) {
        return ['first' => $parts[0], 'last' => ''];
    }

    $first = array_shift($parts);
    // Drop a middle initial like "D." so "Juan D. Dela Cruz" → Juan / Dela Cruz
    if (count($parts) > 1 && preg_match('/^[A-Z]\.$/', $parts[0])) {
        array_shift($parts);
    }
    return ['first' => $first, 'last' => implode(' ', $parts)];
}

/** Age in whole years from a date of birth. */
function age_from_dob(?string $dob): ?int
{
    if (!$dob) return null;
    try {
        return (new DateTime($dob))->diff(new DateTime('now'))->y;
    } catch (Exception) {
        return null;
    }
}

/**
 * Derive the UI-facing election status from the DB status + time window.
 * DB enum is draft|published|cancelled|postponed; the UI wants
 * Draft | Upcoming | Active | Closed.
 */
function election_status(array $e): string
{
    $status = $e['status'] ?? 'draft';
    if ($status === 'draft')     return 'Draft';
    if ($status === 'cancelled') return 'Cancelled';
    if ($status === 'postponed') return 'Postponed';

    $now   = time();
    $start = strtotime((string) $e['start_datetime']);
    $end   = strtotime((string) $e['end_datetime']);

    /*
     * The end moment is exclusive: an election that ends at 21:06:09 is
     * over at 21:06:09. "Close voting" stamps end_datetime with NOW(), so
     * a `>` test here would leave it reading Active for the rest of that
     * second — long enough for the page to reload and look unchanged.
     */
    if ($now < $start)  return 'Upcoming';
    if ($now >= $end)   return 'Closed';
    return 'Active';
}

/** True when the election is open for voting right now. */
function is_election_live(array $e): bool
{
    return election_status($e) === 'Active';
}

/**
 * Cooperative settings as a key => value map.
 *
 * The `settings` table held the cooperative's real name and support address
 * but nothing ever read it, so every screen said the generic product name
 * instead. Cached per request — several places ask for it.
 */
function coop_settings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $out = [];
    try {
        foreach (db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $r) {
            $v = $r['setting_value'];
            /* The seed data stores the literal word NULL for "not set". */
            $out[$r['setting_key']] = ($v === null || $v === 'NULL') ? '' : $v;
        }
    } catch (Throwable) {
        // a missing settings table must not break the app
    }
    return $cache = $out;
}

/** One setting, with a fallback when it is blank or absent. */
function coop_setting(string $key, string $default = ''): string
{
    $all = coop_settings();
    return isset($all[$key]) && $all[$key] !== '' ? $all[$key] : $default;
}

/** A short, human-readable vote receipt, e.g. "CV-7F3K2QX9". */
function generate_receipt(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I/O/0/1
    $out = '';
    for ($i = 0; $i < 8; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'CV-' . $out;
}

/* ── Transactions that survive a deadlock ───────────────── */

/**
 * Is this the database telling us to simply try again?
 *
 * InnoDB resolves a deadlock by picking one transaction and rolling it back
 * whole. Nothing it wrote survives, so the work can be repeated safely —
 * this is a transient condition, not a failure of the request.
 */
function is_retryable_db_error(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }
    $sqlstate = (string) ($e->errorInfo[0] ?? $e->getCode());
    $driver   = (int) ($e->errorInfo[1] ?? 0);

    return $sqlstate === '40001'   // serialization failure
        || $driver === 1213        // deadlock found
        || $driver === 1205;       // lock wait timeout
}

/**
 * Run $work inside a transaction, repeating it if the database deadlocks.
 *
 * Two members pressing Submit at the same moment deadlocked on the ballots
 * table: InnoDB takes gap locks on the unique (user_id, election_id) key
 * while inserting, and the vote rows then take shared locks on the same
 * candidate rows, so two transactions could each hold what the other
 * wanted. Around one ballot in eight was lost that way at peak, answered
 * with "the server could not complete that request".
 *
 * The retry is safe because the rolled-back transaction wrote nothing: the
 * ballot claim is conditional on has_voted = 0 and the unique key still
 * guarantees one ballot per member per election, whether this runs once or
 * four times.
 *
 * @param callable(PDO):mixed $work
 */
function db_transaction(callable $work, int $attempts = 4): mixed
{
    $pdo = db();

    for ($try = 1; ; $try++) {
        try {
            $pdo->beginTransaction();
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($try >= $attempts || !is_retryable_db_error($e)) {
                throw $e;
            }
            /* Back off a little, and differently in each request, so the
               two transactions that collided do not collide again. */
            usleep(random_int(4_000, 20_000) * $try);
        }
    }
}

<?php
/**
 * CoopVote — API front controller
 *
 * Every endpoint is reached through this one file, so no Apache
 * rewrite rules are required for the app to work under XAMPP.
 *
 *   api/index.php?r=voter/dashboard      ← always works
 *   api/index.php/voter/dashboard        ← PATH_INFO, also works
 *   api/voter/dashboard                  ← pretty URL, needs .htaccess
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/installation.php';

/* Turn warnings/errors into JSON instead of HTML that breaks the client. */
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return false;
    throw new ErrorException($str, 0, $no, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    error_log('[CoopVote] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(array_merge(installation_error($e), [
        'ok'     => false,
        'detail' => DEBUG ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : null,
    ]));
    exit;
});

/*
 * A last line of defence.
 *
 * set_exception_handler() never sees a real fatal — running out of memory,
 * exceeding max_execution_time, or a failure inside an extension. Those end
 * the request with either an HTML error page or nothing at all, and the
 * frontend cannot tell that apart from PHP not running: it told people to
 * start Apache when Apache was already running perfectly well. This makes
 * sure the answer is always JSON, so a server problem reads as one.
 */
register_shutdown_function(static function (): void {
    $fatal = error_get_last();
    if ($fatal === null || !in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log('[CoopVote] FATAL ' . $fatal['message'] . ' @ ' . $fatal['file'] . ':' . $fatal['line']);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode([
        'ok'     => false,
        'error'  => 'The server stopped part-way through that request.',
        'code'   => 'SERVER_FATAL',
        'detail' => DEBUG
            ? $fatal['message'] . ' @ ' . basename($fatal['file']) . ':' . $fatal['line']
            : null,
    ]);
});

/* ── Same-origin only ───────────────────────────────────── */
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ── Work out the requested route ───────────────────────── */
$route = '';
if (!empty($_GET['r'])) {
    $route = (string) $_GET['r'];
} elseif (!empty($_SERVER['PATH_INFO'])) {
    $route = (string) $_SERVER['PATH_INFO'];
} else {
    /* Pretty URL: strip the directory this script lives in. */
    $uri  = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    $base = rtrim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/');
    if ($base !== '' && str_starts_with($uri, $base)) {
        $route = substr($uri, strlen($base));
    }
}

$route = trim(preg_replace('#/+#', '/', $route), '/');

/* A direct hit on api/index.php leaves the script name in the path. */
if ($route === 'index.php' || str_starts_with($route, 'index.php/')) {
    $route = trim(substr($route, strlen('index.php')), '/');
}

$segments = $route === '' ? [] : explode('/', $route);

/* Guard against path traversal in the first segment. */
$group = preg_replace('/[^a-z0-9_-]/i', '', $segments[0] ?? '');

if ($group === '') {
    json_out([
        'ok'      => true,
        'service' => APP_NAME . ' API',
        'usage'   => 'api/index.php?r=<route>',
        'routes'  => [
            'auth'  => ['POST auth/login', 'POST auth/logout', 'GET auth/me', 'POST auth/register'],
            'voter' => [
                'GET voter/dashboard', 'GET voter/election', 'GET voter/candidates',
                'GET voter/ballot', 'POST voter/vote', 'GET voter/results',
                'GET voter/receipts',
                'GET|PUT voter/profile', 'POST voter/profile-photo',
                'GET voter/profile-photo/{memberId}', 'POST voter/change-password',
            ],
            'admin' => [
                'GET dashboard', 'GET|POST voters', 'GET|POST candidates',
                'GET|POST elections', 'GET monitor', 'GET results',
                'GET reports', 'GET security',
            ],
        ],
    ]);
}

/* Blocked IPs are refused everywhere except the logout route. */
if ($route !== 'auth/logout' && is_ip_blocked()) {
    json_error('Your network address has been blocked by an administrator.', 403, ['code' => 'IP_BLOCKED']);
}

/* ── Dispatch ───────────────────────────────────────────── */
$args = array_slice($segments, 1);

switch ($group) {
    case 'branding':
        require __DIR__ . '/routes/branding.php';
        handle_branding();
        break;
    case 'management':
        require __DIR__ . '/routes/management.php';
        handle_management($args);
        break;
    case 'notifications':
        require __DIR__ . '/../includes/election-notifications.php';
        handle_election_notifications();
        break;
    case 'auth':
        require __DIR__ . '/routes/auth.php';
        handle_auth($args);
        break;

    case 'voter':
        require __DIR__ . '/routes/voter.php';
        handle_voter($args);
        break;

    /* Everything else is back-office. */
    case 'dashboard':
    case 'voters':
    case 'candidates':
    case 'elections':
    case 'positions':
    case 'monitor':
    case 'results':
    case 'reports':
    case 'operations':
    case 'security':
    case 'announcements':
    case 'settings':
        require __DIR__ . '/routes/admin.php';
        handle_admin($group, $args);
        break;

    default:
        json_error('That page or action does not exist.', 404, ['code' => 'UNKNOWN_ROUTE', 'endpoint' => $route]);
}

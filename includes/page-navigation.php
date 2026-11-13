<?php
declare(strict_types=1);

/** Accept only app-owned pages allowed for this role; never arbitrary return URLs. */
function allowed_page_destination(string $candidate, string $role): ?string
{
    $parts = parse_url($candidate);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])
        || isset($parts['user']) || isset($parts['port'])) return null;
    $path = preg_replace('/\.html$/D', '.php', $parts['path'] ?? '');
    $pages = $role === 'admin'
        ? ['dashboard', 'candidate-management', 'election-management', 'reports', 'results', 'security-logs', 'vote-monitoring', 'voter-management', 'themes', 'branding', 'officer-management', 'election-approvals']
        : ($role === 'voter' ? ['dashboard', 'ballot', 'candidates', 'election', 'profile', 'receipts', 'results', 'themes'] : []);
    if ($pages === []) return null;
    if ($path !== 'management.php' && !in_array($path, array_map(static fn($name) => $role . '/' . $name . '.php', $pages), true)) return null;
    $isManagement = $path === 'management.php';
    $query = [];
    parse_str($parts['query'] ?? '', $query);
    $election = $query['election'] ?? '';
    if (is_string($election) && preg_match('/^[1-9][0-9]{0,9}$/D', $election)) $path .= '?election=' . $election;
    $fragment = $parts['fragment'] ?? '';
    $tabs = $role === 'admin' ? ['themes', 'branding', 'officers', 'approvals'] : ['themes'];
    if ($isManagement && in_array($fragment, $tabs, true)) $path .= '#' . $fragment;
    return $path;
}

function remember_allowed_page(array $user, string $rootPrefix): void
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $relative = ($rootPrefix === '' ? '' : basename(dirname($uri)) . '/') . basename($uri);
    $election = $_GET['election'] ?? '';
    if (is_string($election) && preg_match('/^[1-9][0-9]{0,9}$/D', $election)) $relative .= '?election=' . $election;
    $page = allowed_page_destination($relative, $user['role_slug']);
    if ($page !== null) $_SESSION['last_allowed_page'] = $page;
}

function previous_allowed_page(array $user): string
{
    $page = allowed_page_destination((string) ($_SESSION['last_allowed_page'] ?? ''), $user['role_slug'])
        ?? role_home($user['role_slug']);
    // The browser supplies the fragment/current election, which HTTP never sends
    // in a fragment. It can only refine the same server-recorded allowed page.
    $cookie = $_COOKIE['cv_last_page'] ?? '';
    $client = is_string($cookie) ? json_decode($cookie, true) : null;
    if (is_array($client) && ($client['userId'] ?? null) === (int) $user['user_id'] && is_string($client['page'] ?? null)) {
        $candidate = allowed_page_destination($client['page'], $user['role_slug']);
        if ($candidate !== null && parse_url($candidate, PHP_URL_PATH) === parse_url($page, PHP_URL_PATH)) $page = $candidate;
    }
    return $page;
}

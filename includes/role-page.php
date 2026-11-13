<?php
/** Authorize before any protected HTML is sent, including direct PHP URLs. */
declare(strict_types=1);
require_once __DIR__ . '/device-theme.php';
require_once __DIR__ . '/page-navigation.php';

function require_page_role(?string $requiredRole, string $rootPrefix = '../'): void
{
$roleLabel = $requiredRole === null ? 'management' : ($requiredRole === 'admin' ? 'administrator' : 'voter');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$returnUrl = $rootPrefix . 'login.php';
$returnLabel = 'Back to sign in';
try {
    require_once __DIR__ . '/auth.php';
    if (is_ip_blocked()) {
        $status = 403;
        $heading = 'Access blocked';
        $message = 'Your network address has been blocked by an administrator. Contact the cooperative office.';
    } else {
    $user = current_user();
    $allowedRoles = $requiredRole === null ? [...ADMIN_ROLES, 'voter'] : ($requiredRole === 'admin' ? ADMIN_ROLES : ['voter']);
    if ($user && $user['status'] === 'active' && in_array($user['role_slug'], $allowedRoles, true)) {
        remember_allowed_page($user, $rootPrefix);
        session_write_close();
        return;
    }
    if (!$user || $user['status'] !== 'active') {
        // Previously printed QR codes point directly to a voter election page.
        // Carry only a validated election ID to the local login page.
        $electionId = $_GET['election'] ?? '';
        $returnUrl = $rootPrefix . 'login.php';
        if ($requiredRole === 'voter' && is_string($electionId)
            && preg_match('/^[1-9][0-9]{0,9}$/D', $electionId)) $returnUrl .= '?election=' . $electionId;
        if ($user) auth_logout();
    } else {
        $returnUrl = $rootPrefix . previous_allowed_page($user);
    }
    if ($user) {
        audit((int) $user['user_id'], 'auth.access_denied', 'page', null, ucfirst($roleLabel) . ' page access denied');
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Location: ' . $returnUrl, true, 302);
    exit;
    }
} catch (Throwable $error) {
    error_log('[CoopVote] Protected page authorization failed: ' . get_class($error));
    $status = 503;
    $heading = 'Page temporarily unavailable';
    $message = 'We could not verify your access. Please try again shortly.';
}
http_response_code($status);
?>
<!doctype html>
<html lang="en" data-cv-theme="<?= device_theme() ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $status ?> — <?= $heading ?> | CoopVote</title>
  <style>
    body { margin:0; min-height:100vh; display:grid; place-items:center; background:#0b1931; color:#fff; font:16px/1.6 system-ui,sans-serif; }
    main { max-width:32rem; padding:2rem; text-align:center; }
    .status { color:#c9a84c; font-weight:700; }
    a { display:inline-block; margin-top:1rem; color:#e5c773; }
  </style>
</head>
<body><main>
  <div class="status"><?= $status ?></div>
  <h1><?= $heading ?></h1>
  <p><?= $message ?></p>
  <a href="<?= $returnUrl ?>"><?= $returnLabel ?></a>
</main></body>
</html>
<?php exit;
}

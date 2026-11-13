<?php
// Legacy bookmarks carry their module in a fragment, which only the browser sees.
require __DIR__ . '/includes/role-page.php';
require_page_role(null, '');
$user = current_user();
$isAdmin = $user && in_array($user['role_slug'], ADMIN_ROLES, true);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$targets = $isAdmin
    ? ['officers' => 'admin/officer-management.php', 'approvals' => 'admin/election-approvals.php',
       'themes' => 'admin/themes.php', 'branding' => 'admin/branding.php']
    : ['themes' => 'voter/themes.php'];
$default = $isAdmin ? $targets['officers'] : $targets['themes'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CoopVote — Opening page</title>
  <script>
    const targets = <?= json_encode($targets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const key = location.hash.slice(1);
    const destination = Object.prototype.hasOwnProperty.call(targets, key) ? targets[key] : <?= json_encode($default) ?>;
    const next = new URL(destination, location.href);
    const election = new URLSearchParams(location.search).get('election');
    if (/^[1-9][0-9]{0,9}$/.test(election || '')) next.searchParams.set('election', election);
    location.replace(next.href);
  </script>
</head>
<body><noscript><a href="<?= $default ?>">Continue to CoopVote</a></noscript></body>
</html>

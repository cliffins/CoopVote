<?php
declare(strict_types=1);
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/profile-photo.php';

/** Presentation data only, called after the page's server-side role guard. */
function sidebar_bootstrap_json(): string
{
    // The guard has closed the session. Read its verified ID without reopening
    // it, and select only the public fields needed to paint the sidebar.
    $query = db()->prepare('SELECT u.user_id, u.username, r.slug, r.role_name,
        m.full_name, m.member_no, m.member_id, m.photo_url FROM users u JOIN roles r ON r.role_id = u.role_id
        LEFT JOIN members m ON m.user_id = u.user_id WHERE u.user_id = ? LIMIT 1');
    $query->execute([$_SESSION['user_id'] ?? 0]);
    $user = $query->fetch();
    if (!$user) throw new RuntimeException('Sidebar identity unavailable');
    return json_encode([
        'user' => [
            'id' => (int) $user['user_id'],
            'role' => $user['slug'] === 'voter' ? 'member' : 'admin',
            'name' => $user['full_name'] ?? $user['username'],
            'memberId' => $user['member_no'] ?? '—',
            'photoUrl' => profile_photo_url($user['photo_url'] ?? null, isset($user['member_id']) ? (int)$user['member_id'] : null),
            'roleName' => $user['role_name'],
        ],
        'branding' => branding_read(),
    ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/page-navigation.php';

function check(bool $condition): void
{
    if (!$condition) throw new RuntimeException('Unsafe or incorrect page destination');
}

foreach (['https://example.com', '//example.com', '/admin/dashboard.php',
    'admin/../voter/dashboard.php', 'admin/dashboard.php/extra',
    'javascript:alert(1)', "admin/dashboard.php\r\nLocation: https://example.com",
    'voter/dashboard.php', 'api/index.php'] as $value) {
    check(allowed_page_destination($value, 'admin') === null);
}
check(allowed_page_destination('admin/dashboard.php', 'voter') === null);
foreach (['themes', 'branding', 'officer-management', 'election-approvals'] as $module) {
    check(allowed_page_destination('admin/' . $module . '.php', 'admin') === 'admin/' . $module . '.php');
    check(allowed_page_destination('admin/' . $module . '.php', 'voter') === null);
}
check(allowed_page_destination('voter/themes.php', 'voter') === 'voter/themes.php');
check(allowed_page_destination('voter/themes.php', 'admin') === null);
check(allowed_page_destination('admin/themes.php#branding', 'admin') === 'admin/themes.php');
check(allowed_page_destination('management.php', 'unknown') === null);
check(allowed_page_destination('voter/profile.html?return=https://example.com', 'voter') === 'voter/profile.php');
check(allowed_page_destination('management.html#themes', 'voter') === 'management.php#themes');
check(allowed_page_destination('management.php#approvals', 'voter') === 'management.php');
check(allowed_page_destination('management.php#approvals', 'admin') === 'management.php#approvals');
check(allowed_page_destination('voter/election.php?election=123', 'voter') === 'voter/election.php?election=123');
check(allowed_page_destination('voter/election.php?election[]=123', 'voter') === 'voter/election.php');

$_SESSION = ['last_allowed_page' => 'management.php'];
$user = ['user_id' => 2, 'role_slug' => 'voter'];
foreach (['invalid JSON', '{"userId":2,"page":"https://example.com"}',
    '{"userId":2,"page":"admin/dashboard.php"}',
    '{"userId":2,"page":"voter/profile.php"}',
    '{"userId":3,"page":"management.php#themes"}'] as $cookie) {
    $_COOKIE['cv_last_page'] = $cookie;
    check(previous_allowed_page($user) === 'management.php');
}
$_COOKIE['cv_last_page'] = '{"userId":2,"page":"management.php#themes"}';
check(previous_allowed_page($user) === 'management.php#themes');
echo "Page destinations: PASS (local role allowlist, election/tab validation, malformed and forged cookies)\n";

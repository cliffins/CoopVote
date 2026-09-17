<?php
/** Install database photo columns and copy legacy profile files into MariaDB. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/profile-photo.php';

$apply = in_array('--apply', $argv, true);
$databaseArg = null;
foreach ($argv as $arg) if (str_starts_with($arg, '--database=')) $databaseArg = substr($arg, 11);
if (!$apply || $databaseArg !== DB_NAME) {
    exit("Dry run only. To install and migrate the configured database explicitly, use:\n"
        . "php tools/migrate_profile_photos.php --apply --database=" . DB_NAME . "\n");
}

$pdo = db();
$sql = (string) file_get_contents(BASE_PATH . '/database/migration_05_profile_photos.sql');
$pdo->exec($sql);
$rows = $pdo->query(
    "SELECT member_id, photo_url FROM members
      WHERE photo_url LIKE 'uploads/profiles/%'
        AND NOT EXISTS (SELECT 1 FROM member_profile_photo_chunks pc WHERE pc.member_id = members.member_id)"
)->fetchAll();
$migrated = 0;
$skipped = [];
foreach ($rows as $row) {
    $relative = str_replace('\\', '/', (string) $row['photo_url']);
    if (!preg_match('~^uploads/profiles/[a-f0-9]{48}\.(?:jpg|png|webp)$~', $relative)) {
        $skipped[] = 'member ' . $row['member_id'] . ': unsafe legacy path';
        continue;
    }
    $path = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) {
        $skipped[] = 'member ' . $row['member_id'] . ': file missing';
        continue;
    }
    try { [$bytes, $mime] = profile_photo_details($path); }
    catch (Throwable $e) { $skipped[] = 'member ' . $row['member_id'] . ': ' . $e->getMessage(); continue; }
    try {
        $userId = (int) $pdo->query('SELECT user_id FROM members WHERE member_id=' . (int) $row['member_id'])->fetchColumn();
        store_member_profile_photo($pdo, (int) $row['member_id'], $userId, $bytes, $mime);
        $migrated++;
    } catch (Throwable $e) { throw $e; }
}

echo "Migration 05 installed in " . DB_NAME . ".\n";
echo $migrated . " legacy profile picture(s) copied into MariaDB.\n";
foreach ($skipped as $message) echo "SKIPPED: " . $message . "\n";
echo "Legacy files were retained as a recovery copy.\n";

<?php
/**
 * Voter profile pictures stored as verified binary data in MariaDB.
 * Upload validation checks the actual file contents, not its supplied extension.
 * Ordered chunks keep each SQL packet below shared-host limits. Replacing all
 * chunks in one transaction prevents readers seeing a partially uploaded photo.
 * Authenticated image URLs preserve legacy photos until members replace them.
 */
declare(strict_types=1);

const PROFILE_PHOTO_MAX_BYTES = 2 * 1024 * 1024;

function profile_photo_url(?string $legacyUrl, ?int $memberId = null, ?string $mime = null, ?string $updatedAt = null): ?string
{
    if ($memberId && !$mime) {
        static $photos = [];
        if (!array_key_exists($memberId, $photos)) {
            try {
                $statement = db()->prepare(
                    'SELECT m.profile_photo_mime, m.profile_photo_updated_at
                       FROM members m
                      WHERE m.member_id = ?
                        AND EXISTS (SELECT 1 FROM member_profile_photo_chunks pc WHERE pc.member_id = m.member_id)'
                );
                $statement->execute([$memberId]);
                $photos[$memberId] = $statement->fetch() ?: [];
            } catch (PDOException) {
                // Existing installations continue using legacy URLs until Migration 05 is run.
                $photos[$memberId] = [];
            }
        }
        $mime = $photos[$memberId]['profile_photo_mime'] ?? null;
        $updatedAt = $photos[$memberId]['profile_photo_updated_at'] ?? null;
    }
    if ($memberId && $mime) {
        $version = $updatedAt ? '&v=' . preg_replace('/\D/', '', $updatedAt) : '';
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/api/index.php');
        $base = preg_replace('~/api/index\.php.*$~', '', $script);
        return ($base === $script ? '' : $base) . '/api/index.php?r=voter/profile-photo/' . $memberId . $version;
    }
    return $legacyUrl ?: null;
}

/** Validate actual bytes, never the browser's claimed MIME type or filename. */
function profile_photo_details(string $path): array
{
    $bytes = file_get_contents($path);
    if ($bytes === false || $bytes === '' || strlen($bytes) > PROFILE_PHOTO_MAX_BYTES) {
        json_error('Choose a picture smaller than 2 MB.', 422);
    }
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    set_error_handler(static fn() => true);
    try { $info = getimagesizefromstring($bytes); }
    finally { restore_error_handler(); }
    if (!in_array($mime, $allowed, true) || !$info || ($info['mime'] ?? '') !== $mime) {
        json_error('Choose a valid JPG, PNG or WebP picture.', 422);
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000
        || $info[0] * $info[1] > 16000000) {
        json_error('Resize your picture to at most 6000 pixels per side and 16 megapixels.', 422);
    }
    return [$bytes, $mime];
}

/** Store ordered BLOB chunks so shared/live MariaDB packet limits accept a 2 MB photo. */
function store_member_profile_photo(PDO $pdo, int $memberId, int $userId, string $bytes, string $mime): void
{
    $pdo->beginTransaction();
    try {
        $start = $pdo->prepare(
            "UPDATE members SET profile_photo = NULL, profile_photo_mime = ?, profile_photo_updated_at = NULL
              WHERE member_id = ? AND user_id = ?"
        );
        $start->execute([$mime, $memberId, $userId]);
        if ($start->rowCount() !== 1) throw new RuntimeException('Member record changed during photo upload.');
        $pdo->prepare('DELETE FROM member_profile_photo_chunks WHERE member_id = ?')->execute([$memberId]);
        $insert = $pdo->prepare(
            'INSERT INTO member_profile_photo_chunks (member_id, chunk_no, photo_data) VALUES (?, ?, UNHEX(?))'
        );
        foreach (str_split($bytes, 48 * 1024) as $index => $chunk) {
            $insert->execute([$memberId, $index, bin2hex($chunk)]);
        }
        $finish = $pdo->prepare(
            'UPDATE members SET profile_photo_updated_at = NOW(), photo_url = NULL WHERE member_id = ? AND user_id = ?'
        );
        $finish->execute([$memberId, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable) { /* connection may already be closed */ }
        }
        throw $e;
    }
}

/** Upload a photo or stream one to an authenticated CoopVote user. */
function voter_route_profile_photo(array $args = []): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        voter_stream_profile_photo((int) ($args[0] ?? 0));
    }
    require_method('POST');
    $u = require_voter();
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        json_error('Upload your picture from My Profile.', 403);
    }
    $file = $_FILES['photo'] ?? null;
    if (!is_array($file) || !isset($file['error']) || !is_int($file['error'])) {
        json_error('Choose a JPG, PNG or WebP picture up to 2 MB.', 422);
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        json_error('The picture exceeds the server upload limit. Choose a smaller picture.', 413);
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
        || !is_uploaded_file($file['tmp_name'])) {
        json_error('The picture did not upload completely. Choose it again and retry.', 422);
    }
    if (empty($u['member_id'])) json_error('Your member record could not be found.', 404);
    [$bytes, $mime] = profile_photo_details($file['tmp_name']);
    try {
        store_member_profile_photo(db(), (int) $u['member_id'], (int) $u['user_id'], $bytes, $mime);
    } catch (PDOException $e) {
        if (str_contains(strtolower($e->getMessage()), 'profile_photo')) {
            json_error('The profile-picture database update has not been installed yet. Ask the administrator to run database/migration_05_profile_photos.sql.', 503);
        }
        throw $e;
    }
    audit((int) $u['user_id'], 'member.photo_updated', 'member', (int) $u['member_id'], 'Updated own profile picture');
    json_out(['ok' => true, 'photoUrl' => profile_photo_url(null, (int) $u['member_id'], $mime, date('Y-m-d H:i:s'))]);
}

/** Send image bytes without exposing them inside JSON or public storage. */
function voter_stream_profile_photo(int $memberId): never
{
    require_login();
    if ($memberId < 1) json_error('Profile picture not found.', 404);
    $statement = db()->prepare(
        'SELECT profile_photo_mime, profile_photo_updated_at FROM members WHERE member_id = ? LIMIT 1'
    );
    $statement->execute([$memberId]);
    $photo = $statement->fetch();
    if (!$photo || !in_array($photo['profile_photo_mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
        json_error('Profile picture not found.', 404);
    }
    $chunks = db()->prepare('SELECT photo_data FROM member_profile_photo_chunks WHERE member_id = ? ORDER BY chunk_no');
    $chunks->execute([$memberId]);
    $bytes = '';
    while (($chunk = $chunks->fetchColumn()) !== false) $bytes .= (string) $chunk;
    if ($bytes === '') json_error('Profile picture not found.', 404);
    $etag = '"' . hash('sha256', $bytes) . '"';
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        exit;
    }
    header('Content-Type: ' . $photo['profile_photo_mime']);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=86400');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

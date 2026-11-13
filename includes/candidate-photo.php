<?php
/** Candidate uploads update the linked member profile picture. */
declare(strict_types=1);
require_once __DIR__ . '/profile-photo.php';

function candidate_photo_url(?string $stored, int $candidateId): ?string
{
    if (!$stored || !str_starts_with($stored, 'candidate-photo:')) return $stored ?: null;
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/api/index.php');
    $base = preg_replace('~/api/index\.php.*$~', '', $script);
    return ($base === $script ? '' : $base) . '/api/index.php?r=voter/candidate-photo/' . $candidateId
        . '&v=' . substr($stored, strlen('candidate-photo:'));
}

/** Omitted or null photoData preserves the shared member photo. */
function candidate_photo_input(): ?array
{
    if (!array_key_exists('photoData', body())) return null;
    require_json_request();
    $data = input('photoData');
    if ($data === null) return null;
    if (!is_string($data) || strlen($data) > 2800000
        || !preg_match('~^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$~D', $data, $m)) {
        json_error('Choose a JPG, PNG or WebP picture up to 2 MB.', 422, ['fields'=>['photoData'=>'Invalid picture.']]);
    }
    $bytes = base64_decode($m[2], true);
    if ($bytes === false) json_error('The picture could not be read. Choose it again.', 422);
    [$bytes, $mime] = profile_photo_bytes($bytes);
    if ($mime !== 'image/' . $m[1]) json_error('The picture contents do not match its file type.', 422);
    return ['bytes'=>$bytes, 'mime'=>$mime];
}

/** Called inside the candidate-save transaction, after locking its election. */
function store_candidate_photo(PDO $pdo, int $candidateId, array $photo): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Candidate photo requires a transaction.');
    $q = $pdo->prepare('SELECT m.member_id,m.user_id FROM candidates c JOIN members m ON m.member_id=c.member_id WHERE c.candidate_id=?');
    $q->execute([$candidateId]);
    $member = $q->fetch();
    if (!$member) throw new RuntimeException('Linked member not found.');
    store_member_profile_photo($pdo, (int)$member['member_id'], (int)$member['user_id'], $photo['bytes'], $photo['mime']);
    record_admin_profile_update($pdo, (int)current_user()['user_id'], (int)$member['user_id'], true);
}

/** Legacy image URLs resolve to the same shared member picture. */
function voter_stream_candidate_photo(int $candidateId): never
{
    require_method('GET'); require_login();
    $q = db()->prepare('SELECT member_id FROM candidates WHERE candidate_id=?');
    $q->execute([$candidateId]);
    $memberId = $q->fetchColumn();
    if (!$memberId) json_error('Candidate picture not found.',404);
    voter_stream_profile_photo((int)$memberId);
}

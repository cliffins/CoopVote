<?php
/** Real multipart requests against the test runner's isolated database and servers. */
declare(strict_types=1);

return static function (Suite $t, PDO $pdo): void {
    if (DB_NAME !== 'coopvote_test') throw new RuntimeException('Scratch database required.');
    $t->group('Database-backed profile photo uploads');
    $voter = new Http('photo-voter');
    $admin = new Http('photo-admin');
    $anonymous = new Http('photo-anonymous');
    $t->same('photo voter signs in', 200, $voter->login('t_voter1')['status']);
    $t->same('photo administrator signs in', 200, $admin->login('t_admin')['status']);
    $header = ['X-Requested-With: XMLHttpRequest'];
    $query = "SELECT m.* FROM members m JOIN users u ON u.user_id=m.user_id WHERE u.username='t_voter1'";
    $before = $pdo->query($query)->fetch();
    $otherPhotos = $pdo->query("SELECT member_id, profile_photo_mime FROM members WHERE member_id <> " . (int) $before['member_id'])->fetchAll();
    $temp = tempnam(sys_get_temp_dir(), 'cv_photo_');
    try {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        file_put_contents($temp, $png);
        $body = ['photo' => new CURLFile($temp, 'image/png', '../../picture.php'), 'memberId' => 999999];
        $t->same('anonymous upload is refused', 401, $anonymous->api('POST', 'voter/profile-photo', $body, $header)['status']);
        $t->same('admin cannot use voter upload', 403, $admin->api('POST', 'voter/profile-photo', $body, $header)['status']);
        $t->same('cross-site form style request is refused', 403, $voter->api('POST', 'voter/profile-photo', $body)['status']);
        $t->same('missing photo is rejected', 422, $voter->api('POST', 'voter/profile-photo', [], $header)['status']);

        $result = $voter->api('POST', 'voter/profile-photo', $body, $header);
        $t->same('valid PNG upload succeeds', 200, $result['status']);
        $stored = $pdo->query($query)->fetch();
        $chunks = $pdo->query('SELECT photo_data FROM member_profile_photo_chunks WHERE member_id=' . (int) $before['member_id'] . ' ORDER BY chunk_no')->fetchAll(PDO::FETCH_COLUMN);
        $t->same('actual picture bytes are stored in MariaDB chunks', $png, implode('', $chunks));
        $t->same('verified MIME type is stored', 'image/png', $stored['profile_photo_mime']);
        $t->ok('filesystem path is cleared', empty($stored['photo_url']));
        $url = $result['json']['photoUrl'] ?? '';
        $t->contains('response points to the protected image endpoint', 'api/index.php?r=voter/profile-photo/', $url);
        $t->same('profile reload returns the database image endpoint', $url,
            $voter->api('GET', 'voter/profile')['json']['member']['photoUrl'] ?? null);
        $image = $voter->page(ltrim(parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY), '/'));
        $t->same('authenticated user can fetch database image', 200, $image['status']);
        $t->same('image endpoint returns exact database bytes', $png, $image['body']);
        $t->contains('image endpoint sends the verified type', 'content-type: image/png', strtolower($image['headers']));
        $t->same('anonymous image request is refused', 401,
            $anonymous->page(ltrim(parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY), '/'))['status']);
        foreach (array_keys($before) as $key) {
            if (!in_array($key, ['photo_url', 'profile_photo', 'profile_photo_mime', 'profile_photo_updated_at'], true)) {
                $t->same('upload preserves member field ' . $key, $before[$key], $stored[$key]);
            }
        }
        $t->same('spoofed member ID cannot affect other members', $otherPhotos,
            $pdo->query("SELECT member_id, profile_photo_mime FROM members WHERE member_id <> " . (int) $before['member_id'])->fetchAll());

        file_put_contents($temp, '<?php echo "not an image";');
        $t->same('fake image MIME type cannot upload PHP', 422, $voter->api('POST', 'voter/profile-photo', $body, $header)['status']);
        file_put_contents($temp, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $t->same('SVG uploads are refused', 422, $voter->api('POST', 'voter/profile-photo', $body, $header)['status']);
        file_put_contents($temp, str_repeat('x', 2 * 1024 * 1024 + 1));
        $result = $voter->api('POST', 'voter/profile-photo', $body, $header);
        $t->ok('oversized upload is refused by PHP or application', in_array($result['status'], [413, 422], true));
        $chunks = $pdo->query('SELECT photo_data FROM member_profile_photo_chunks WHERE member_id=' . (int) $before['member_id'] . ' ORDER BY chunk_no')->fetchAll(PDO::FETCH_COLUMN);
        $t->same('rejected files preserve database picture bytes', $png, implode('', $chunks));

        $_SERVER['SCRIPT_NAME'] = '/nested/CoopVote/api/index.php';
        $t->same('image endpoint supports hosted subfolders', '/nested/CoopVote/api/index.php?r=voter/profile-photo/5&v=20260912110000',
            profile_photo_url(null, 5, 'image/png', '2026-09-12 11:00:00'));
        $_SERVER['SCRIPT_NAME'] = '/api/index.php';
        $t->same('image endpoint supports a domain root', '/api/index.php?r=voter/profile-photo/5',
            profile_photo_url(null, 5, 'image/png'));
    } finally {
        unset($_SERVER['SCRIPT_NAME']);
        $pdo->prepare('UPDATE members SET photo_url=?, profile_photo=?, profile_photo_mime=?, profile_photo_updated_at=? WHERE member_id=?')
            ->execute([$before['photo_url'], $before['profile_photo'], $before['profile_photo_mime'], $before['profile_photo_updated_at'], $before['member_id']]);
        $pdo->prepare('DELETE FROM member_profile_photo_chunks WHERE member_id=?')->execute([$before['member_id']]);
        unlink($temp);
    }
};

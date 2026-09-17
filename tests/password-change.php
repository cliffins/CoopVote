<?php
/** Password handler regression: SQLite memory and fake session only. No real accounts or mail. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
final class PasswordResponse extends RuntimeException
{
    public function __construct(public array $data, public int $status) { parent::__construct(); }
}
function db(): PDO { return $GLOBALS['passwordDb']; }
function require_method(string ...$methods): void {}
function require_login(): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE user_id = ?');
    $stmt->execute([$GLOBALS['sessionUserId']]);
    $user = $stmt->fetch();
    if (!$user) json_error('You are not signed in.', 401, ['code' => 'UNAUTHENTICATED']);
    return $user;
}
function input_scalar(string $key): string { return $GLOBALS['passwordInput'][$key] ?? ''; }
function json_out(mixed $data, int $status = 200): never { throw new PasswordResponse($data, $status); }
function json_error(string $message, int $status = 400, array $extra = []): never
{ json_out(['ok' => false, 'error' => $message] + $extra, $status); }
function audit(mixed ...$args): void { $GLOBALS['passwordAudit'][] = $args; }
require __DIR__ . '/../api/routes/voter.php';

$passwordDb = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$passwordDb->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, password_hash TEXT)');
$oldHash = password_hash('SampleOld123', PASSWORD_BCRYPT);
$insert = db()->prepare('INSERT INTO users VALUES (?, ?)');
$insert->execute([1, $oldHash]);
$insert->execute([2, $oldHash]);
$sessionUserId = 1;
$passwordAudit = [];
$checks = 0;
function password_expect(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $GLOBALS['checks']++;
}
function change_sample_password(string $current, string $new): PasswordResponse
{
    $GLOBALS['passwordInput'] = ['currentPassword' => $current, 'newPassword' => $new];
    try { voter_route_change_password(); }
    catch (PasswordResponse $response) { return $response; }
}

$wrong = change_sample_password('WrongCurrent123', 'SampleNew123');
if (in_array('--incorrect-response', $argv, true)) {
    echo json_encode(['status' => $wrong->status, 'data' => $wrong->data]);
    exit;
}
password_expect($wrong->status === 422, 'Wrong password is validation failure, not session expiry');
password_expect($wrong->data['code'] === 'CURRENT_PASSWORD_INCORRECT', 'Client receives a specific error code');
password_expect(isset($wrong->data['fields']['currentPassword']), 'Client can focus the current-password field');
password_expect(db()->query('SELECT password_hash FROM users WHERE user_id = 1')->fetchColumn() === $oldHash,
    'Wrong current password cannot change the stored password');
password_expect($sessionUserId === 1, 'A rejected change retains the fake session identity');
password_expect($passwordAudit[0][1] === 'auth.password_change_failed', 'Failure is audited');

foreach (['abcdefgh', '12345678', 'Abcdefgh', 'abc12345', 'Ab1!', 'Abc     ', 'Ab1' . str_repeat('x', 70), 'Éé1' . str_repeat('界', 23)] as $weak) {
    $response = change_sample_password('SampleOld123', $weak);
    password_expect($response->status === 422, 'Weak or oversized passwords are refused by the server');
    password_expect(($response->data['code'] ?? '') === 'WEAK_PASSWORD', 'Strength rejection has a specific code');
    password_expect(isset($response->data['fields']['newPassword']), 'Strength error identifies the new password');
    password_expect(db()->query('SELECT password_hash FROM users WHERE user_id = 1')->fetchColumn() === $oldHash,
        'Rejected password leaves the original hash unchanged');
}
foreach (['SampleNew123', 'sample123!', 'SampleNew123!', 'Éé123456', 'Ab1' . str_repeat('x', 69)] as $good) {
    password_expect(changed_password_error($good) === null, 'Good or strong password meets the server policy');
}

foreach ([['', 'SampleNew123'], ['SampleOld123', 'short'], ['SampleOld123', 'SampleOld123'],
    ['        ', 'SampleNew123'], ['SampleOld123', '        '], ['SampleOld123', "\u{00A0}\u{2003}\u{200B}"]]
    as [$current, $new]) {
    $response = change_sample_password($current, $new);
    password_expect($response->status >= 400 && $response->status !== 401, 'Invalid password values do not signal session expiry');
}
password_expect(db()->query('SELECT password_hash FROM users WHERE user_id = 1')->fetchColumn() === $oldHash,
    'Other invalid submissions cannot change the password');

$success = change_sample_password('SampleOld123', 'SampleNew123');
$newHash = db()->query('SELECT password_hash FROM users WHERE user_id = 1')->fetchColumn();
password_expect($success->status === 200 && $success->data['ok'], 'Retry with the correct current password succeeds');
password_expect(password_verify('SampleNew123', $newHash), 'New password verifies against the saved hash');
password_expect(!password_verify('SampleOld123', $newHash), 'Old password no longer verifies');
password_expect(db()->query('SELECT password_hash FROM users WHERE user_id = 2')->fetchColumn() === $oldHash,
    'Another member is unaffected');
password_expect(end($passwordAudit)[1] === 'auth.password_changed', 'Successful change is audited');
$stale = change_sample_password('SampleOld123', 'AnotherNew123');
password_expect($stale->status === 422, 'A later request must use the latest current password');
password_expect(password_verify('SampleNew123', db()->query('SELECT password_hash FROM users WHERE user_id = 1')->fetchColumn()),
    'Stale-password retry cannot overwrite a successful change');
echo "$checks password handler checks passed. SQLite memory only; no MySQL connection.\n";

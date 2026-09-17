<?php
/** Saved profile response regression. SQLite memory only; no real accounts. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
final class ProfileError extends RuntimeException
{
    public function __construct(public int $status, public array $fields) { parent::__construct(); }
}
function db(): PDO { return $GLOBALS['profileDb']; }
function input_str(string $key): string { return trim_form_text((string) ($GLOBALS['profileInput'][$key] ?? '')); }
function json_error(string $message, int $status = 400, array $extra = []): never
{ throw new ProfileError($status, $extra['fields'] ?? []); }
function audit(mixed ...$args): void {}
function current_user(): array { return ['user_id' => 1]; }
function json_out(mixed $data, int $status = 200): never { throw new ProfileError($status, []); }
require __DIR__ . '/../api/routes/admin.php';
$profileDb = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$profileDb->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, email TEXT);
    CREATE TABLE members (user_id INTEGER PRIMARY KEY, full_name TEXT, contact_no TEXT, address TEXT, date_of_birth TEXT, member_id INTEGER DEFAULT 1, member_no TEXT DEFAULT 'DEMO-001');
    INSERT INTO users VALUES (1, 'sample@example.test'), (2, 'other@example.test');
    INSERT INTO members (user_id, full_name, contact_no, address, date_of_birth) VALUES (1, 'Sample Member', '', '', NULL);");
$original = ['user_id' => 1, 'email' => 'sample@example.test', 'full_name' => 'Sample Member',
    'contact_no' => '', 'address' => '', 'date_of_birth' => null, 'member_no' => 'DEMO-001'];
$profileInput = ['first' => ' Updated ', 'last' => ' Member ', 'email' => 'UPDATED@example.test',
    'phone' => '09000000000', 'address' => 'Sample address', 'dob' => '1990-01-02'];
$saved = voter_update_profile($original);
$checks = 0;
function profile_expect(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $GLOBALS['checks']++;
    echo "PASS: $label\n";
}
profile_expect($saved['full_name'] === 'Updated Member', 'Response contains the saved name');
profile_expect($saved['email'] === 'updated@example.test', 'Response contains the normalized saved email');
profile_expect($saved['member_no'] === 'DEMO-001', 'Membership details are retained');
profile_expect($original['full_name'] === 'Sample Member', 'Response replaces the stale request snapshot');
$stored = db()->query('SELECT u.email, m.* FROM users u JOIN members m ON m.user_id = u.user_id WHERE u.user_id = 1')->fetch();
foreach (['email', 'full_name', 'contact_no', 'address', 'date_of_birth'] as $key) {
    profile_expect($saved[$key] === $stored[$key], 'Response matches stored ' . $key);
}
$profileInput['email'] = 'other@example.test';
try { voter_update_profile($saved); throw new RuntimeException('Duplicate email accepted'); }
catch (ProfileError $e) { profile_expect($e->status === 409 && isset($e->fields['email']), 'Duplicate email still returns a field error'); }
profile_expect(db()->query('SELECT email FROM users WHERE user_id = 1')->fetchColumn() === 'updated@example.test',
    'A failed save keeps the previously saved values');
$profileInput['email'] = 'updated@example.test';
$validInput = $profileInput;
foreach (['phone', 'address', 'dob'] as $field) {
    foreach (['', '   ', "\u{00A0}\u{200B}"] as $blank) {
        $profileInput = array_replace($validInput, [$field => $blank, 'first' => 'Must Not Save']);
        try { voter_update_profile($saved); throw new RuntimeException('Blank profile field accepted'); }
        catch (ProfileError $e) {
            profile_expect($e->status === 422 && isset($e->fields[$field]), 'Blank ' . $field . ' returns a field error');
        }
        $after = db()->query('SELECT u.email, m.* FROM users u JOIN members m ON m.user_id = u.user_id WHERE u.user_id = 1')->fetch();
        profile_expect($after === $stored, 'Rejected ' . $field . ' edit preserves every saved value');
    }
}
$profileInput = array_replace($validInput, ['dob' => '1992-03-04', 'memberId' => 'DEMO-001']);
try { admin_voters_save(1); }
catch (ProfileError $e) { profile_expect($e->status === 200, 'Admin can save complete personal details'); }
profile_expect(db()->query('SELECT date_of_birth FROM members WHERE user_id = 1')->fetchColumn() === '1992-03-04',
    'Admin edits persist the entered date of birth');
echo "$checks profile response checks passed. No MySQL connection was opened.\n";

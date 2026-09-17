<?php
/** Save correctness against SQLite memory. No live database, accounts or email. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
final class SaveResponse extends RuntimeException {
    public function __construct(public int $status, public array $data) { parent::__construct(); }
}
function db(): PDO { return $GLOBALS['saveDb']; }
function current_user(): array { return ['user_id' => 1]; }
function input(string $key, mixed $default = null): mixed { return $GLOBALS['saveInput'][$key] ?? $default; }
function input_str(string $key, string $default = ''): string {
    $value = input($key, $default); return is_scalar($value) ? trim_form_text((string) $value) : $default;
}
function json_out(mixed $data, int $status = 200): never { throw new SaveResponse($status, $data); }
function json_error(string $message, int $status = 400, array $extra = []): never { json_out(['error' => $message] + $extra, $status); }
function audit(mixed ...$args): void { $GLOBALS['saveAudits'][] = $args; }
require __DIR__ . '/../api/routes/admin.php';
$saveDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$saveDb->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, email TEXT UNIQUE);
 CREATE TABLE members (member_id INTEGER PRIMARY KEY, user_id INTEGER UNIQUE, full_name TEXT, contact_no TEXT, address TEXT, date_of_birth TEXT, member_no TEXT UNIQUE);
 CREATE TABLE elections (election_id INTEGER PRIMARY KEY);
 INSERT INTO users VALUES (1, 'ana@example.test'), (2, 'bea@example.test');
 INSERT INTO members VALUES (1,1,'Ana Santos','09123456789','Sample address','1990-01-02','DEMO-001'),
 (2,2,'Bea Santos','09123456789','Sample address','1990-01-02','DEMO-002');");
$saveAudits = []; $checks = 0;
function expect_save(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); $GLOBALS['checks']++; }
function snapshot_save(): array { return db()->query('SELECT u.email, m.* FROM users u JOIN members m ON m.user_id=u.user_id ORDER BY u.user_id')->fetchAll(); }
function save_request(array $data, int $id = 1): SaveResponse {
    $GLOBALS['saveInput'] = $data;
    try { admin_voters_save($id); } catch (SaveResponse $r) { return $r; }
}
$base = ['first'=>'Ana','last'=>'Santos','phone'=>'09123456789','address'=>'Sample address','dob'=>'1990-01-02','email'=>'ana@example.test','memberId'=>'DEMO-001'];
$original = snapshot_save();
foreach (['', '   ', "\u{00A0}\u{200B}", null, str_repeat('X',31)] as $id) {
    $body = array_replace($base, ['memberId'=>$id, 'email'=>'changed@example.test']);
    if ($id === null) unset($body['memberId']);
    $r = save_request($body);
    expect_save($r->status === 422 && isset($r->data['fields']['memberId']), 'Invalid member ID must be rejected');
    expect_save(snapshot_save() === $original && !$saveAudits, 'Rejected ID cannot save other fields or create a success audit');
}
$r = save_request(array_replace($base, ['memberId'=>'DEMO-002']));
expect_save($r->status === 409 && isset($r->data['fields']['memberId']), 'Duplicate ID returns a field error');
expect_save(snapshot_save() === $original, 'Duplicate ID leaves all records unchanged');
expect_save(save_request($base, 999)->status === 404, 'Missing voter cannot produce a save success');
expect_save(save_request($base)->status === 200, 'Unchanged valid ID is accepted');
$r = save_request(array_replace($base, ['memberId'=>'DEMO-NEW','email'=>'changed@example.test']));
expect_save($r->status === 200, 'Unique member ID change succeeds');
expect_save(snapshot_save()[0]['member_no'] === 'DEMO-NEW' && snapshot_save()[0]['email'] === 'changed@example.test', 'Success persists ID and email together');
expect_save(snapshot_save()[1] === $original[1], 'Another member remains unchanged');
$beforeFailure = snapshot_save();
$saveDb->exec("CREATE TRIGGER fail_member_update BEFORE UPDATE ON members WHEN NEW.member_no='RACE' BEGIN SELECT RAISE(ABORT, 'simulated constraint conflict'); END");
$r = save_request(array_replace($base, ['memberId'=>'RACE','email'=>'rollback@example.test']));
expect_save($r->status === 409 && snapshot_save() === $beforeFailure, 'Database failure rolls back the email and member edits together');
foreach (['occ'=>'', 'years'=>'', 'positionId'=>''] as $field=>$value) {
    $saveInput = [$field=>$value];
    try { admin_candidates_save(1); throw new RuntimeException('Cleared candidate field accepted'); }
    catch (SaveResponse $r) { expect_save($r->status === 422 && isset($r->data['fields'][$field]), 'Candidate rejects cleared '.$field); }
}
$saveInput = ['title'=>'Sample election','description'=>'','startDate'=>'2026-10-01T09:00','endDate'=>'2026-10-02T09:00'];
try { admin_elections_save(999); throw new RuntimeException('Missing election accepted'); }
catch (SaveResponse $r) { expect_save($r->status === 404, 'Missing election cannot produce a save success'); }
echo "$checks save correctness checks passed. SQLite memory only.\n";

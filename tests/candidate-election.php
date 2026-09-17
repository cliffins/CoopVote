<?php
/** Focused candidate validation checks. Uses only SQLite in memory, never MySQL. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class CandidateResponse extends RuntimeException
{
    public function __construct(public int $status, public array $body) { parent::__construct(); }
}
function db(): PDO { return $GLOBALS['candidateTestDb']; }
function current_user(): array { return ['user_id' => 1]; }
function input(string $key, mixed $default = null): mixed { return $GLOBALS['candidateInput'][$key] ?? $default; }
function input_str(string $key): string { return trim((string) input($key, '')); }
function json_error(string $message, int $status = 400, array $extra = []): never
{ throw new CandidateResponse($status, ['error' => $message] + $extra); }
function json_out(mixed $body, int $status = 200): never
{ throw new CandidateResponse($status, $body); }
function audit(mixed ...$args): void { $GLOBALS['candidateAuditCount']++; }

require __DIR__ . '/../api/routes/admin.php';

$candidateTestDb = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$candidateTestDb->exec('CREATE TABLE elections (election_id INTEGER PRIMARY KEY);
    CREATE TABLE positions (position_id INTEGER PRIMARY KEY, election_id INTEGER, position_name TEXT);
    CREATE TABLE candidates (candidate_id INTEGER PRIMARY KEY, member_id INTEGER, position_id INTEGER,
        election_id INTEGER, platform TEXT, bio TEXT, is_active INTEGER);
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, slug TEXT);
    CREATE TABLE users (user_id INTEGER PRIMARY KEY, role_id INTEGER, status TEXT);
    CREATE TABLE members (member_id INTEGER PRIMARY KEY, user_id INTEGER);
    INSERT INTO roles VALUES (1, "admin"), (2, "voter");
    INSERT INTO users VALUES (1,2,"active"), (2,2,"suspended"), (3,1,"active"), (4,2,"active");
    INSERT INTO members VALUES (1,1), (2,2), (3,3), (4,4);
    INSERT INTO elections VALUES (1), (2), (3);
    INSERT INTO positions VALUES (11, 1, "Chairperson"), (12, 1, "Treasurer"), (21, 2, "Chairperson");');
$candidateAuditCount = 0;
$checks = 0;
function expect_candidate(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $GLOBALS['checks']++;
    echo "PASS: $label\n";
}
function candidate_request(array $body, int $id = 0): CandidateResponse
{
    $GLOBALS['candidateInput'] = $body;
    try { admin_candidates_save($id); } catch (CandidateResponse $r) { return $r; }
}

$base = ['memberId' => 1, 'positionId' => 11];
foreach ([0, '', '1abc', 999, 2, 3] as $badMember) {
    expect_candidate(candidate_request(['memberId' => $badMember, 'electionId' => 1, 'positionId' => 11])->status === 422,
        'Missing, invalid, inactive and administrator accounts cannot be nominated');
}
expect_candidate(candidate_request(['first'=>'Sample','last'=>'Member','electionId'=>1,'positionId'=>11])->status === 422,
    'Names alone cannot nominate an ambiguous person');
expect_candidate(candidate_request($base)->status === 422, 'Creation requires an explicit election');
expect_candidate(candidate_request($base + ['electionId' => 999])->status === 404, 'Unknown elections are rejected');
expect_candidate(candidate_request($base + ['electionId' => 2])->status === 422, 'A position from another election is rejected');
expect_candidate(candidate_request($base + ['electionId' => 3])->status === 422, 'An election with no positions cannot borrow one');
expect_candidate(candidate_request(['electionId' => 1, 'memberId' => 1])->status === 422, 'Creation also requires a position');
expect_candidate(candidate_request(['electionId' => 1, 'positionId' => 999, 'memberId' => 1])->status === 404, 'Unknown positions are rejected');
expect_candidate((int) db()->query('SELECT COUNT(*) FROM candidates')->fetchColumn() === 0 && $candidateAuditCount === 0,
    'Rejected requests create no candidates or success audit entries');

$created = candidate_request($base + ['electionId' => 1]);
expect_candidate($created->status === 201, 'Matching election and position create the candidate');
expect_candidate((int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 4 &&
    (int) db()->query('SELECT COUNT(*) FROM members')->fetchColumn() === 4, 'Nomination creates no user or member account');
expect_candidate(db()->query('SELECT role_id FROM users WHERE user_id=1')->fetchColumn() === 2,
    'The nominated person remains a voter');
$id = (int) $created->body['id'];
expect_candidate(candidate_request(['memberId'=>4], $id)->status === 422, 'An existing candidacy cannot move to another member');
expect_candidate(candidate_request($base + ['electionId' => 1])->status === 409, 'Duplicate nomination in the same election is still rejected');
expect_candidate(candidate_request(['electionId' => 2, 'positionId' => 21], $id)->status === 422, 'Editing cannot move a candidate to another election');
expect_candidate(candidate_request(['positionId' => 21], $id)->status === 422, 'Omitting the election cannot bypass position ownership');
expect_candidate(candidate_request(['electionId' => 1, 'positionId' => 12], $id)->status === 200, 'Editing can select another position in the same election');
expect_candidate(candidate_request(['status' => 'Inactive'], $id)->status === 200, 'Status-only updates retain the original election');
$row = db()->query('SELECT * FROM candidates WHERE candidate_id = ' . $id)->fetch();
expect_candidate((int) $row['election_id'] === 1 && (int) $row['position_id'] === 12 && (int) $row['is_active'] === 0,
    'The saved candidate retains the matching election and position');

$legacy = candidate_request(['electionId' => 2, 'position' => 'Chairperson', 'memberId' => 1]);
expect_candidate($legacy->status === 201, 'Position names resolve in the explicitly selected election');
$row = db()->query('SELECT * FROM candidates WHERE candidate_id = ' . (int) $legacy->body['id'])->fetch();
expect_candidate((int) $row['position_id'] === 21 && (int) $row['election_id'] === 2,
    'Same-named positions in different elections stay separate');
echo "$checks candidate election checks passed. No MySQL connection was opened.\n";

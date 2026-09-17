<?php
/** Pure rule and route rejection checks. No connection to the application's database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
final class InputRejection extends RuntimeException
{
    public function __construct(public int $status, public array $fields) { parent::__construct(); }
}
function db(): PDO { static $db = null; return $db ??= new PDO('sqlite::memory:'); }
function current_user(): array { return ['user_id' => 1]; }
function input(string $key, mixed $default = null): mixed { return $GLOBALS['validationInput'][$key] ?? $default; }
function input_str(string $key): string { $v = input($key, ''); return is_scalar($v) ? trim_form_text((string) $v) : ''; }
function json_error(string $message, int $status = 400, array $extra = []): never
{ throw new InputRejection($status, $extra['fields'] ?? []); }
require __DIR__ . '/../api/routes/admin.php';
$checks = 0;
function valid_expect(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $GLOBALS['checks']++;
}
foreach (['Maria', 'Dela Cruz', 'Muñoz', 'María', "O'Connor", 'O’Connor', 'Anne-Marie', 'Ma. Luisa', '李明'] as $name)
    valid_expect(person_name_error($name) === null, 'Valid name ' . $name);
foreach (['Maria1', '2Juan', 'Juan１２', 'John_Doe', 'John@Doe', '123', '---'] as $name)
    valid_expect(person_name_error($name) !== null, 'Invalid name');
foreach (['09123456789', '+63 912 345 6789', '(02) 8123-4567', ''] as $phone)
    valid_expect(phone_number_error($phone) === null, 'Valid phone format');
foreach (['09abc1234567', '12+3456789', '++639123456789', '123', '1234567890123456', '------'] as $phone)
    valid_expect(phone_number_error($phone) !== null, 'Invalid phone format');
foreach (['', '2000-02-29', date('Y-m-d')] as $date) valid_expect(birth_date_error($date) === null, 'Valid date');
foreach (['2025-02-29', '2020-13-01', 'not a date', "2000-01-01\0", '0000-01-01', '2999-01-01'] as $date)
    valid_expect(birth_date_error($date) !== null, 'Invalid or future date');
foreach (['3e2', '1.5', '-1', 'abc', [], true] as $number)
    valid_expect(whole_number_error($number, 0, 100) !== null, 'Invalid whole number');
valid_expect(whole_number_error(10, 0, 100) === null, 'Valid integer');
valid_expect(isset(person_fields_errors(str_repeat('A', 60), str_repeat('B', 45))['last']), 'Full-name length');

$base = ['first' => 'Maria', 'last' => 'Santos', 'email' => 'sample@example.test', 'phone' => '09123456789', 'address' => 'Sample address', 'dob' => '1990-01-02'];
foreach (['', '   ', "\t\r\n", "\u{00A0}\u{2003}\u{200B}", null] as $blank) {
    foreach (array_keys($base) as $field) {
        foreach (['create', 'edit', 'profile'] as $route) {
            $validationInput = array_replace($base, [$field => $blank]);
            if ($blank === null) unset($validationInput[$field]);
            try {
                if ($route === 'profile') voter_update_profile(['user_id' => 1]);
                else admin_voters_save($route === 'create' ? 0 : 1);
                throw new RuntimeException('Blank field accepted');
            } catch (InputRejection $e) {
                valid_expect($e->status === 422 && isset($e->fields[$field]), $route . ' rejects blank ' . $field);
            }
        }
    }
}
valid_expect(trim_form_text("\u{00A0} Maria Dela Cruz \u{200B}") === 'Maria Dela Cruz', 'Meaningful spaces in names survive');
valid_expect(trim_form_text('  123 Main Street  ') === '123 Main Street', 'Meaningful spaces in addresses survive');
foreach (['create', 'edit'] as $route) {
    $validationInput = ['memberId' => 1, 'first' => '   ', 'last' => '   '];
    try { admin_candidates_save($route === 'create' ? 0 : 1); throw new RuntimeException('Blank candidate names accepted'); }
    catch (InputRejection $e) { valid_expect(isset($e->fields['first'], $e->fields['last']), 'Submitted blank candidate names rejected'); }
}
foreach ([null, '', '   '] as $missingDob) {
    $validationInput = array_replace($base, ['dob' => $missingDob]);
    if ($missingDob === null) unset($validationInput['dob']);
    try { admin_voters_save(0); throw new RuntimeException('Missing date of birth accepted'); }
    catch (InputRejection $e) {
        valid_expect($e->status === 422 && ($e->fields['dob'] ?? '') === 'Date of birth is required.',
            'Add Voter requires date of birth before SQL');
    }
}
foreach (['first' => 'Maria9', 'last' => 'Santos2', 'phone' => '0912abc3456', 'dob' => '2999-01-01'] as $field => $bad) {
    foreach (['create', 'edit', 'profile'] as $route) {
        $validationInput = array_replace($base, [$field => $bad]);
        try {
            if ($route === 'profile') voter_update_profile(['user_id' => 1]);
            else admin_voters_save($route === 'create' ? 0 : 1);
            throw new RuntimeException('Invalid input was accepted');
        } catch (InputRejection $e) {
            valid_expect($e->status === 422 && isset($e->fields[$field]), $route . ' rejects ' . $field);
        }
    }
}
foreach (['first' => 'Maria9', 'years' => '2years'] as $field => $bad) {
    $validationInput = array_replace($base, ['electionId' => 1, 'positionId' => 1, 'memberId' => 1], [$field => $bad]);
    try { admin_candidates_save(0); throw new RuntimeException('Invalid candidate accepted'); }
    catch (InputRejection $e) { valid_expect($e->status === 422 && isset($e->fields[$field]), 'Candidate rejects ' . $field); }
}
echo "$checks server input validation checks passed. All invalid writes were rejected before SQL.\n";

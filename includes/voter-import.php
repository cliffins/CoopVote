<?php
/** Session-bound CSV preview and atomic voter creation; no live records change at preview. */
declare(strict_types=1);

const VOTER_IMPORT_HEADERS = ['memberId', 'first', 'last', 'email', 'phone', 'address', 'dob', 'username'];
const VOTER_IMPORT_LIMIT = 100;

/** Parse Excel's CSV UTF-8 export, including quoted commas and multiline addresses. */
function voter_import_parse(string $csv): array
{
    if (strlen($csv) > 262144) throw new InvalidArgumentException('Use a CSV file no larger than 256 KB.');
    if (!mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) throw new InvalidArgumentException('Save the file as CSV UTF-8 in Excel, then upload it again.');
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv); rewind($stream);
    try {
        $headers = fgetcsv($stream, 0, ',', '"', '');
        if (!$headers || array_map('trim_form_text', $headers) !== VOTER_IMPORT_HEADERS) {
            throw new InvalidArgumentException('Use the template column names and order: ' . implode(', ', VOTER_IMPORT_HEADERS) . '.');
        }
        $rows = []; $record = 1;
        while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $record++;
            // Excel may export its formatted blank input rows as comma-only records.
            if (!array_filter($values, static fn($v) => trim_form_text((string)$v) !== '')) continue;
            if (count($rows) >= VOTER_IMPORT_LIMIT) throw new InvalidArgumentException('Import up to 100 voters per file. Split this list into smaller files.');
            $error = count($values) === count(VOTER_IMPORT_HEADERS) ? [] : ['csv' => 'This row must have 8 columns. Use quotes around values containing commas.'];
            $values = array_map(static fn($v) => trim_form_text((string) $v), array_slice(array_pad($values, 8, ''), 0, 8));
            $data = array_combine(VOTER_IMPORT_HEADERS, $values);
            $data['email'] = strtolower($data['email']);
            $rows[] = ['row' => $record, 'data' => $data, 'errors' => $error];
        }
        if (!$rows) throw new InvalidArgumentException('The file has no voters. Add rows below the template headers.');
        return $rows;
    } finally { fclose($stream); }
}

/** Reuse the single-voter field rules and detect duplicates before committing. */
function voter_import_validate(array $rows): array
{
    $seen = [];
    foreach ($rows as $i => &$row) {
        $d = $row['data'];
        $row['errors'] = array_intersect_key($row['errors'], ['csv' => true]);
        $row['errors'] += person_fields_errors($d['first'], $d['last'], $d['phone']);
        foreach (['email','phone','address','dob'] as $field) {
            if ($d[$field] === '') $row['errors'][$field] = ucfirst($field) . ' is required.';
        }
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || strlen($d['email']) > 100) $row['errors']['email'] = 'Enter a valid email address (up to 100 characters).';
        if ($error = birth_date_error($d['dob'])) $row['errors']['dob'] = $error . ' Use YYYY-MM-DD.';
        if (mb_strlen($d['address']) > 255) $row['errors']['address'] = 'Use at most 255 characters for the address.';
        if (mb_strlen($d['memberId']) > 30) $row['errors']['memberId'] = 'Use at most 30 characters for the member ID.';
        if ($d['username'] !== '' && !preg_match('/^[a-zA-Z0-9._-]{3,50}$/D', $d['username'])) $row['errors']['username'] = 'Use 3–50 letters, numbers, dots, underscores or hyphens.';
        foreach (['email','memberId','username'] as $field) {
            if ($d[$field] === '') continue;
            $key = mb_strtolower($d[$field]);
            $seen[$field][$key][] = $i;
            $sql = $field === 'memberId' ? 'SELECT 1 FROM members WHERE member_no=?' : 'SELECT 1 FROM users WHERE ' . $field . '=?';
            $q = db()->prepare($sql); $q->execute([$d[$field]]);
            if ($q->fetchColumn()) $row['errors'][$field] = 'Already used by an existing account.';
        }
    }
    unset($row);
    foreach ($seen as $field => $groups) foreach ($groups as $indices) {
        if (count($indices) > 1) foreach ($indices as $i) $rows[$i]['errors'][$field] = 'Repeated in this file. Keep only one row with this value.';
    }
    return $rows;
}

/** Passwords are available only through the explicit credentials download. */
function voter_import_public(array $batch): array
{
    if (isset($batch['created'])) {
        $created = array_map(static function ($row) { unset($row['password']); return $row; }, $batch['created']);
        return ['token'=>$batch['token'], 'created'=>$created, 'expires'=>$batch['expires'], 'mailEnabled'=>MAIL_ENABLED];
    }
    $valid = count(array_filter($batch['rows'], static fn($r) => !$r['errors']));
    return ['token'=>$batch['token'], 'rows'=>$batch['rows'], 'valid'=>$valid, 'invalid'=>count($batch['rows'])-$valid];
}

/**
 * Keep the preview and its one-time credentials in the administrator's session.
 * Commit reads only the stored rows; client-supplied replacements are ignored.
 * A short expiry bounds credential retention and logout removes the session.
 * No schema migration or public registration endpoint is needed for imports.
 */
function admin_voter_import(string $action): never
{
    require_admin();
    auth_boot();
    if ($action === 'template') {
        require_method('GET');
        $format = $_GET['format'] ?? 'csv';
        if ($format === 'xlsx') {
            $file = BASE_PATH . '/assets/templates/coopvote-voter-template.xlsx';
            if (!is_file($file)) json_error('The Excel template is unavailable. Use the CSV template instead.',503);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="coopvote-voter-template.xlsx"');
            header('Cache-Control: no-store');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
        }
        if ($format !== 'csv') json_error('Choose Excel or CSV for the template.',422);
        csv_out('coopvote-voter-template.csv', VOTER_IMPORT_HEADERS, []);
    }
    if (isset($_SESSION['voter_import']) && $_SESSION['voter_import']['expires'] < time()) unset($_SESSION['voter_import']);
    if ($action === 'status') {
        require_method('GET');
        // A terminated SMTP worker cannot prove delivery; do not automatically resend.
        foreach ($_SESSION['voter_import']['created'] ?? [] as $i => $row) {
            if ($row['delivery'] === 'sending' && ($row['mailStarted'] ?? 0) < time()-120) {
                $_SESSION['voter_import']['created'][$i]['delivery'] = 'unknown';
            }
        }
        json_out(isset($_SESSION['voter_import']) ? voter_import_public($_SESSION['voter_import']) : ['token'=>null]);
    }
    if ($action === 'credentials') {
        require_method('GET');
        $batch = $_SESSION['voter_import'] ?? null;
        if (!$batch || !isset($batch['created'])) json_error('The credentials download has expired. Use password reset for existing accounts.', 410);
        header('Cache-Control: no-store');
        csv_out('coopvote-imported-accounts.csv', ['Name','Member ID','Email','Username','Temporary password','Email status'], array_map(
            static fn($r) => [$r['name'],$r['memberNo'],$r['email'],$r['username'],$r['password'],$r['delivery']], $batch['created']));
    }
    require_method('POST');
    require_json_request();
    if ($action === 'clear') { unset($_SESSION['voter_import']); json_out(['ok'=>true]); }
    if ($action === 'preview') {
        $state = admin_voter_registration_state();
        if (!$state['allowed']) json_error($state['reason'],409);
        if (isset($_SESSION['voter_import']['created'])) json_error('Finish the previous import before uploading another file.',409);
        try { $rows = voter_import_validate(voter_import_parse(input_str('csv'))); }
        catch (InvalidArgumentException $e) { json_error($e->getMessage(),422); }
        $batch = ['token'=>bin2hex(random_bytes(24)), 'rows'=>$rows, 'expires'=>time()+1800];
        $_SESSION['voter_import'] = $batch;
        json_out(voter_import_public($batch));
    }
    $batch = $_SESSION['voter_import'] ?? null;
    if (!$batch || !hash_equals($batch['token'], input_str('token'))) json_error('This import preview has expired. Upload your file again.',410);
    if ($action === 'email') voter_import_email($batch);
    if ($action !== 'commit') json_error('Unknown import action.',404);
    // A repeated confirmation returns the original result without creating accounts twice.
    if (isset($batch['created'])) json_out(voter_import_public($batch));
    $rows = array_values(array_filter($batch['rows'], static fn($r) => !$r['errors']));
    if (!$rows) json_error('Correct the errors and upload the file again.',422);
    $pdo = db(); $me = (int) current_user()['user_id']; $created = [];
    $pdo->beginTransaction();
    try {
        // Lock election rows so opening an election cannot race an import.
        $pdo->query('SELECT election_id FROM elections ORDER BY election_id FOR UPDATE')->fetchAll();
        $state = admin_voter_registration_state(true);
        if (!$state['allowed']) { $pdo->rollBack(); json_error($state['reason'],409); }
        $rows = voter_import_validate($rows);
        if (array_filter($rows, static fn($r) => (bool)$r['errors'])) {
            $pdo->rollBack(); json_error('Some accounts now exist. Upload the file again to refresh the preview. No accounts were added.',409);
        }
        // Reserve supplied identifiers first; generated identifiers cannot steal later rows' values.
        usort($rows, static fn($a,$b) => (int)($b['data']['username'] !== '') <=> (int)($a['data']['username'] !== ''));
        $reserved = array_map('mb_strtolower', array_column(array_column($rows,'data'),'memberId'));
        foreach ($rows as $row) {
            $d = $row['data']; $username = $d['username'] ?: admin_unique_username($d['first'].$d['last']);
            $password = admin_temp_password(); $memberNo = $d['memberId'];
            if ($memberNo === '') {
                $next = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(member_no,6) AS UNSIGNED)),0)+1 FROM members WHERE member_no LIKE 'COOP-%'")->fetchColumn();
                do { $memberNo = sprintf('COOP-%04d',$next++); } while (in_array(mb_strtolower($memberNo),$reserved,true));
            }
            $pdo->prepare("INSERT INTO users(username,email,password_hash,role_id,status,email_verified_at) VALUES(?,?,?,(SELECT role_id FROM roles WHERE slug='voter'),'active',NOW())")
                ->execute([$username,$d['email'],password_hash($password,PASSWORD_BCRYPT)]);
            $id=(int)$pdo->lastInsertId(); $name=$d['first'].' '.$d['last'];
            $pdo->prepare("INSERT INTO members(user_id,member_no,full_name,date_of_birth,contact_no,address,membership_status,approved_by,approved_at) VALUES(?,?,?,?,?,?,'active',?,NOW())")
                ->execute([$id,$memberNo,$name,$d['dob'],$d['phone'],$d['address'],$me]);
            audit($me,'member.created','user',$id,'Imported member "'.$name.'" ('.$memberNo.') as '.$username);
            $created[]=['row'=>$row['row'],'id'=>$id,'name'=>$name,'memberNo'=>$memberNo,'username'=>$username,'email'=>$d['email'],'password'=>$password,'delivery'=>MAIL_ENABLED?'pending':'disabled'];
        }
        $state = admin_voter_registration_state(true);
        if (!$state['allowed']) { $pdo->rollBack(); json_error($state['reason'] . ' No accounts were added.',409); }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode()==='23000') json_error('An email, username or member ID is already in use. No accounts were added. Upload the file again.',409);
        throw $e;
    }
    usort($created, static fn($a,$b) => $a['row'] <=> $b['row']);
    $batch=['token'=>$batch['token'],'created'=>$created,'expires'=>time()+1800];
    $_SESSION['voter_import']=$batch;
    json_out(voter_import_public($batch),201);
}

/** Send one account email per request, releasing the session lock during SMTP. */
function voter_import_email(array $batch): never
{
    if (!isset($batch['created'])) json_error('Confirm the import first.',409);
    $index = array_search((int)input_str('id'), array_column($batch['created'],'id'),true);
    if ($index === false) json_error('That account is not part of this import.',404);
    $row=$batch['created'][$index];
    if (!MAIL_ENABLED || in_array($row['delivery'],['sent','sending','unknown'],true)) json_out(['id'=>$row['id'],'delivery'=>$row['delivery']]);
    $_SESSION['voter_import']['created'][$index]['delivery']='sending';
    $_SESSION['voter_import']['created'][$index]['mailStarted']=time();
    $uid=$_SESSION['user_id'];
    session_write_close();
    ignore_user_abort(true);
    $sent=false;
    try {
        require_once BASE_PATH.'/includes/mailer.php';
        $sent=mail_account_created($row['email'],$row['name'],$row['memberNo'],$row['username'],$row['password']);
    } catch (Throwable) { /* Return a delivery failure, never SMTP credentials. */ }
    auth_boot();
    if (($_SESSION['user_id'] ?? null)===$uid && ($_SESSION['voter_import']['token'] ?? null)===$batch['token']) {
        $_SESSION['voter_import']['created'][$index]['delivery']=$sent?'sent':'failed';
    }
    json_out(['id'=>$row['id'],'delivery'=>$sent?'sent':'failed']);
}

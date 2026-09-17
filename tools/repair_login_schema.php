<?php
/** Restore supported optional columns from this release without replacing data. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/installation.php';
$pdo = db();
$issues = installation_schema_issues($pdo, DB_NAME);
if ($issues['missingTables']) {
    fwrite(STDERR, "Incomplete import; missing tables: " . implode(', ', $issues['missingTables'])
        . ". Import the matching full SQL into an empty database. No changes made.\n");
    exit(1);
}
if (!$issues['missingColumns']) { echo "Database columns already match this release. No changes needed.\n"; exit(0); }
$supported = [
    'users'=>['lockout_count','failed_attempts','locked_until','last_login_at','email_verified_at','verify_token'],
    'members'=>['date_of_birth','occupation','years_in_coop','photo_url','id_front_url','id_back_url',
        'profile_photo','profile_photo_mime','profile_photo_updated_at'],
    'candidates'=>['platform','bio','photo_url','is_active','sort_order'],
];
$schema = file_get_contents(__DIR__ . '/../database/schema_only.sql');
preg_match_all('/CREATE TABLE `([^`]+)`\s*\((.*?)\) ENGINE=/s', $schema, $tables, PREG_SET_ORDER);
$definitions = [];
foreach ($tables as $table) {
    preg_match_all('/^\s*(`([^`]+)`[^\r\n]+),?\r?$/m', $table[2], $columns, PREG_SET_ORDER);
    foreach ($columns as $column) $definitions[$table[1].'.'.$column[2]] = rtrim(trim($column[1]), ',');
}
$plan = [];
foreach ($issues['missingColumns'] as $missing) {
    [$table,$column] = explode('.', $missing, 2);
    if (!in_array($column, $supported[$table] ?? [], true) || !isset($definitions[$missing])) {
        fwrite(STDERR, "Unsupported missing column: $missing. Use the version-specific migration or a complete import into an empty database. No changes made.\n");
        exit(1);
    }
    $plan[] = 'ALTER TABLE `'.$table.'` ADD COLUMN '.$definitions[$missing];
}

$folder = BASE_PATH . '/logs';
if (!is_dir($folder) || !is_writable($folder)) {
    fwrite(STDERR, "The logs folder must be writable so a backup can be saved. No changes made.\n"); exit(1);
}
$backup = $folder . '/before-login-repair-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql';
$binary = PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY, 2) . '/mysql/bin/mysqldump.exe' : 'mysqldump';
$env = getenv(); $env['MYSQL_PWD'] = DB_PASS;
$args = [$binary, '--host='.DB_HOST, '--port='.DB_PORT, '--user='.DB_USER,
    '--single-transaction','--hex-blob','--result-file='.$backup,DB_NAME];
$process = proc_open($args, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, $env);
if (!is_resource($process)) { fwrite(STDERR,"Cannot start the database backup. No changes made.\n"); exit(1); }
fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]);
stream_get_contents($pipes[2]); fclose($pipes[2]);
if (proc_close($process) !== 0 || !is_file($backup) || filesize($backup) < 100) {
    fwrite(STDERR, "Backup failed. Check mysqldump and database credentials. No changes made.\n"); exit(1);
}
echo 'Backup: '.$backup.PHP_EOL;
foreach ($plan as $statement) $pdo->exec($statement);
$after = installation_schema_issues($pdo, DB_NAME);
if ($after['missingTables'] || $after['missingColumns']) {
    fwrite(STDERR,"The schema still needs attention. Open setup.php for details.\n"); exit(1);
}
echo "Missing columns restored. Existing accounts, votes and records were retained. Open setup.php, then try signing in.\n";

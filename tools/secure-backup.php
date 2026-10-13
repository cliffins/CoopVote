<?php
/** CLI-only encrypted export. SQL is never written to disk in plaintext. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/backup-crypto.php';
require __DIR__ . '/../includes/sql-import.php';

$name = $argv[1] ?? '';
if (!in_array($name, ['coopvote', 'coopvote_test'], true)) {
    throw new RuntimeException('Usage: php tools/secure-backup.php coopvote|coopvote_test');
}
$key = backup_key();
$local = is_file(__DIR__.'/../config/security-local.php') ? require __DIR__.'/../config/security-local.php' : [];
$ca = getenv('COOPVOTE_DB_SSL_CA') ?: ($local['db_ssl_ca'] ?? '');
if (!$ca || !is_readable($ca)) throw new RuntimeException('Configure a readable database TLS CA before backing up.');
$binary = PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY, 2) . '/mysql/bin/mysqldump.exe' : 'mysqldump';
$env = getenv(); $env['MYSQL_PWD'] = DB_PASS;
$p = proc_open([$binary, '--host=' . DB_HOST, '--port=' . DB_PORT, '--user=' . DB_USER,
    '--ssl-ca='.$ca, '--ssl-verify-server-cert', '--tls-version=TLSv1.2,TLSv1.3',
    '--single-transaction', '--hex-blob', '--complete-insert', '--skip-add-locks', '--skip-add-drop-table', $name],
    [0=>['pipe','r'], 1=>['pipe','w'], 2=>['file', __DIR__.'/../logs/secure-backup-error.log','a']], $pipes, null, $env);
if (!is_resource($p)) throw new RuntimeException('Cannot start mysqldump.');
fclose($pipes[0]); $sql = stream_get_contents($pipes[1]); fclose($pipes[1]);
if (proc_close($p) !== 0 || strlen($sql) < 100) throw new RuntimeException('Export failed; no database changed.');
if (preg_match('/\b(?:DROP|CREATE)\s+DATABASE\b|^\s*(?:\/\*![0-9]*\s*)?USE\s/im', $sql)) {
    throw new RuntimeException('Unexpected database-selection statement.');
}
$envelope = backup_encrypt($sql, $key);
if (!hash_equals(hash('sha256', $sql), hash('sha256', backup_decrypt($envelope, $key)))) {
    throw new RuntimeException('Encryption round-trip failed.');
}
$stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
$directory = getenv('COOPVOTE_BACKUP_DIRECTORY') ?: ($local['backup_directory'] ?? '');
if (!$directory || !is_dir($directory)) throw new RuntimeException('Configure an existing private COOPVOTE_BACKUP_DIRECTORY outside the website.');
$out = rtrim($directory, '/\\') . '/' . $name . '-' . $stamp . '.cvbak';
if (file_put_contents($out, $envelope, LOCK_EX) !== strlen($envelope)) throw new RuntimeException('Backup write failed.');
if (backup_decrypt((string)file_get_contents($out), $key) !== $sql) throw new RuntimeException('Backup read-back failed.');

// Restore into a unique scratch database to verify the export before any cleanup.
$server = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_SSL_CA=>$ca,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=>true]);
$scratch = 'coopvote_backupcheck_' . str_replace('-', '_', $stamp);
$server->exec('CREATE DATABASE `'.$scratch.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $server->exec('USE `'.$scratch.'`');
    sql_import($server, $sql);
    $counts = [];
    foreach ($server->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $counts[$table] = (int)$server->query('SELECT COUNT(*) FROM `'.str_replace('`','``',$table).'`')->fetchColumn();
    }
    if (empty($counts['users']) || empty($counts['roles'])) throw new RuntimeException('Restored account tables are empty.');
} finally { $server->exec('DROP DATABASE `'.$scratch.'`'); }
$manifest = ['database'=>$name, 'createdAt'=>date('c'), 'algorithm'=>'AES-256-GCM',
    'sha256'=>hash_file('sha256',$out), 'restoreVerified'=>true, 'rows'=>$counts];
file_put_contents($out.'.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['file'=>realpath($out), 'manifest'=>$manifest], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";

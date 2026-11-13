<?php
/** Generate a private, consistent SQL dump using the installed database client. */
declare(strict_types=1);

function database_backup_option(string $value): string
{
    return '"' . str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], $value) . '"';
}

/** Returns an open temporary file. Caller must close it after streaming. */
function database_backup_file(): mixed
{
    if (!function_exists('proc_open')) throw new RuntimeException('Backup creation is unavailable on this server. Contact the system administrator.');
    $binary = null;
    foreach (['/usr/bin/mariadb-dump', '/usr/bin/mysqldump', 'C:/xampp/mysql/bin/mysqldump.exe'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) { $binary = $candidate; break; }
    }
    if ($binary === null) throw new RuntimeException('The database backup tool is not installed. Contact the hosting administrator.');
    $pdo = db();
    $engine = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' AND ENGINE <> 'InnoDB'");
    $engine->execute([DB_NAME]);
    if ((int) $engine->fetchColumn() !== 0) throw new RuntimeException('This database needs a maintenance backup. Contact the hosting administrator.');

    $directory = sys_get_temp_dir() . '/coopvote-backup-' . bin2hex(random_bytes(12));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Could not prepare a private backup folder.');
    $configPath = $directory . '/client.cnf';
    $dump = $error = $process = null;
    $keep = false;
    $cleanup = static function () use ($configPath, $directory): void {
        if (is_file($configPath)) @unlink($configPath);
        if (is_dir($directory)) @rmdir($directory);
    };
    register_shutdown_function($cleanup);
    try {
        $options = ['user' => DB_SETTINGS['user'], 'password' => DB_SETTINGS['password']];
        if (DB_SETTINGS['socket'] !== '') {
            $options += ['protocol' => 'SOCKET', 'socket' => DB_SETTINGS['socket']];
        } else {
            $options += ['protocol' => 'TCP', 'host' => DB_SETTINGS['host'], 'port' => (string) DB_SETTINGS['port']];
            foreach (['ca' => 'ssl-ca', 'cert' => 'ssl-cert', 'key' => 'ssl-key'] as $key => $flag) {
                if (DB_SETTINGS[$key] !== '') $options[$flag] = DB_SETTINGS[$key];
            }
        }
        $config = "[client]\n";
        foreach ($options as $key => $value) $config .= $key . '=' . database_backup_option($value) . "\n";
        if (DB_SETTINGS['socket'] === '' && DB_SETTINGS['ca'] !== '') $config .= "ssl-verify-server-cert\n";
        if (file_put_contents($configPath, $config) === false || !chmod($configPath, 0600)) throw new RuntimeException('Could not prepare the database backup.');
        unset($config, $options);
        $dump = tmpfile(); $error = tmpfile();
        if ($dump === false || $error === false) throw new RuntimeException('Not enough temporary storage to prepare a backup.');
        $command = [$binary, '--defaults-file=' . $configPath, '--single-transaction', '--quick',
            '--hex-blob', '--skip-lock-tables', '--no-tablespaces', '--routines', '--events', '--triggers',
            '--default-character-set=utf8mb4', '--', DB_NAME];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $dump, 2 => $error], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Could not start the database backup.');
        fclose($pipes[0]);
        $deadline = microtime(true) + 120;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) > $deadline) throw new RuntimeException('Backup preparation took too long. Try again or contact the hosting administrator.');
            usleep(50000);
        } while (true);
        $closed = proc_close($process); $process = null;
        $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        if ($exit !== 0 || fstat($dump)['size'] === 0) {
            // Client stderr can contain connection details: never return it to a browser.
            error_log('[CoopVote] Database backup client failed with exit code ' . $exit);
            throw new RuntimeException('Could not prepare the database backup. Ask the hosting administrator to check backup permissions and temporary storage.');
        }
        rewind($dump);
        $keep = true;
        return $dump;
    } finally {
        if (is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
        if (is_resource($error)) fclose($error);
        if (!$keep && is_resource($dump)) fclose($dump);
        $cleanup();
    }
}

function admin_database_backup(): never
{
    require_method('POST');
    $me = require_writer();
    require_json_request();
    $pdo = db();
    // Hold the same election locks as vote writers through snapshot creation.
    $pdo->beginTransaction();
    $pdo->query('SELECT election_id FROM elections ORDER BY election_id FOR UPDATE')->fetchAll();
    if ((int)$pdo->query('SELECT (EXISTS(SELECT 1 FROM ballots WHERE has_voted=1) OR EXISTS(SELECT 1 FROM votes))')->fetchColumn() > 0) {
        $pdo->rollBack();
        json_error('For voter privacy, databases with recorded ballots must be backed up by the hosting administrator in maintenance mode. Web downloads could expose individual selections or differences between snapshots.', 409, ['code'=>'BALLOT_PRIVACY_BACKUP_RESTRICTED']);
    }
    $pdo = db();
    $lock = 'cv-backup-' . substr(hash('sha256', DB_NAME), 0, 40);
    $query = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $query->execute([$lock]);
    if ((int) $query->fetchColumn() !== 1) { $pdo->rollBack(); json_error('Another database backup is being prepared. Please try again shortly.', 409); }
    session_write_close();
    if (function_exists('set_time_limit')) @set_time_limit(150);
    $dump = null;
    try {
        $dump = database_backup_file();
        audit((int) $me['user_id'], 'security.database_backup', 'database', null, 'Prepared a database backup download');
    } catch (RuntimeException $error) {
        audit((int) $me['user_id'], 'security.database_backup_failed', 'database', null, 'Database backup preparation failed');
        $failure = $error instanceof PDOException ? 'Could not prepare the database backup.' : $error->getMessage();
    } finally {
        if ($pdo->inTransaction()) $pdo->commit();
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
    if ($dump === null) json_error($failure ?? 'Could not prepare the database backup.', 503);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="coopvote-database-' . date('Ymd-His') . '.sql"');
    header('Content-Length: ' . fstat($dump)['size']);
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    try { fpassthru($dump); } finally { fclose($dump); }
    exit;
}

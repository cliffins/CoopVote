<?php
/** Read-only installation checks shared by setup and API error reporting. */
declare(strict_types=1);

function installation_schema_requirements(): array
{
    $sql = file_get_contents(__DIR__ . '/../database/schema_only.sql');
    if ($sql === false) throw new RuntimeException('The bundled database/schema_only.sql file is missing.');
    preg_match_all('/CREATE TABLE `([^`]+)`\s*\((.*?)\) ENGINE=/s', $sql, $tables, PREG_SET_ORDER);
    $required = [];
    foreach ($tables as $table) {
        preg_match_all('/^\s*`([^`]+)`\s+/m', $table[2], $columns);
        $required[$table[1]] = $columns[1];
    }
    if (!$required) throw new RuntimeException('The bundled database schema could not be read.');
    return $required;
}

function installation_schema_issues(PDO $pdo, string $database): array
{
    $q = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?');
    $q->execute([$database]);
    $actual = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $actual[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
    $missingTables = []; $missingColumns = [];
    foreach (installation_schema_requirements() as $table => $columns) {
        if (!isset($actual[$table])) { $missingTables[] = $table; continue; }
        foreach (array_diff($columns, $actual[$table]) as $column) $missingColumns[] = $table . '.' . $column;
    }
    return compact('missingTables', 'missingColumns');
}

function installation_error(Throwable $e): array
{
    if ($e->getMessage() === 'DB_TLS_REQUIRED') {
        return ['code'=>'DB_TLS_REQUIRED', 'error'=>'The secure database connection is not configured.',
            'fix'=>'Set COOPVOTE_DB_SSL_CA or db_ssl_ca in config/security-local.php to the database CA certificate. See SECURITY_DEPLOYMENT.txt.'];
    }
    if ($e->getMessage() === 'DB_ENCRYPTION_REQUIRED') {
        return ['code'=>'DB_ENCRYPTION_REQUIRED', 'error'=>'The database encryption configuration is incomplete.',
            'fix'=>'Configure MariaDB with a separate AES-256 key, then run php tools/encrypt_database.php. See SECURITY_DEPLOYMENT.txt.'];
    }
    if ($e instanceof PDOException && (in_array((int) ($e->errorInfo[1] ?? 0), [1054,1146,1932], true)
        || in_array((string) $e->getCode(), ['42S02','42S22'], true))) {
        return ['code'=>'DB_SCHEMA_MISMATCH',
            'error'=>'The imported database is incomplete or does not match this version of CoopVote.',
            'fix'=>'Open setup.php in this project to see the missing tables or columns. Use the SQL file supplied with this same project version.'];
    }
    if ($e->getMessage() === 'SESSION_STORAGE_UNAVAILABLE') {
        return ['code'=>'SESSION_STORAGE_UNAVAILABLE', 'error'=>'The server cannot save your sign-in session.',
            'fix'=>'Open setup.php and check Session storage. Allow PHP to write to its configured session folder or temporary directory.'];
    }
    if (preg_match('/undefined function (mb_\w+|finfo_\w+|openssl_\w+)\(/i', $e->getMessage())) {
        return ['code'=>'PHP_EXTENSION_MISSING', 'error'=>'A required PHP extension is missing on this computer.',
            'fix'=>'Open setup.php, enable the listed PHP extensions in php.ini, and restart Apache.'];
    }
    return ['code'=>'SERVER_ERROR','error'=>'The server could not complete that request.'];
}

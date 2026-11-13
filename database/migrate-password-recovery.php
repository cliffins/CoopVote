<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/sql-import.php';
$pdo = db();
if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'auth_version'")->fetch()) {
    $pdo->exec('ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 0');
}
sql_import($pdo, file_get_contents(__DIR__ . '/password-recovery.sql'));
echo "Password recovery schema ready. Existing passwords and election records unchanged.\n";

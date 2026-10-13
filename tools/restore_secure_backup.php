<?php
/** Restore only into a new, explicitly named database, never over existing data. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/backup-crypto.php';
require __DIR__.'/../includes/sql-import.php';
$file=$argv[1]??'';$target=$argv[2]??'';
if(!preg_match('/^coopvote_restore_[a-z0-9_]{1,35}$/D',$target)||!is_file($file))throw new RuntimeException('Usage: php tools/restore_secure_backup.php file.cvbak coopvote_restore_NAME');
$sql=backup_decrypt((string)file_get_contents($file),backup_key());
if(preg_match('/\b(?:DROP|CREATE)\s+DATABASE\b|^\s*(?:\/\*![0-9]*\s*)?USE\s/im',$sql))throw new RuntimeException('Backup contains database-selection statements.');
$local=is_file(__DIR__.'/../config/security-local.php') ? require __DIR__.'/../config/security-local.php' : [];
$ca=getenv('COOPVOTE_DB_SSL_CA') ?: ($local['db_ssl_ca']??'');
if(!$ca || !is_readable($ca))throw new RuntimeException('Configure a readable database TLS CA before restoring.');
$p=db();
$p->exec('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $p->exec('USE `'.$target.'`');sql_import($p,$sql);
    foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
        $p->exec('ALTER TABLE `'.str_replace('`','``',$table).'` ENCRYPTED=YES ENCRYPTION_KEY_ID=1');
    }
}catch(Throwable $e){
    // This invocation created the target; a failure never affects an existing DB.
    $p->exec('DROP DATABASE `'.$target.'`');throw $e;
}
echo "Restored encrypted tables into $target. Existing databases were not changed.\n";

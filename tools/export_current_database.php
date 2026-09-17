<?php
/**
 * Back up, validate a data-bearing export, then retire quorum storage.
 * Live data is read through a consistent mysqldump snapshot, with photos in hex.
 * Only a newly created, uniquely named scratch database receives import probes.
 * Schema and data checks must pass before the retired table is removed.
 * The old full export and complete pre-cleanup database remain in protected logs.
 * Database-selection statements are forbidden in the deliverable SQL file.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/helpers.php';
require __DIR__.'/../includes/sql-import.php';
$live=db();$stamp=date('Ymd_His').'_'.bin2hex(random_bytes(3));
$backup=BASE_PATH.'/logs/before-quorum-cleanup-'.$stamp.'.sql';
$pending=BASE_PATH.'/database/coopvote_full.pending.sql';
$binary=PHP_OS_FAMILY==='Windows'?dirname(PHP_BINARY,2).'/mysql/bin/mysqldump.exe':'mysqldump';
$dump=static function(string $path,bool $clean)use($binary):void {
    $env=getenv();$env['MYSQL_PWD']=DB_PASS;
    $args=[$binary,'--host='.DB_HOST,'--port='.DB_PORT,'--user='.DB_USER,'--single-transaction','--hex-blob','--complete-insert','--net-buffer-length=262144','--skip-add-drop-table','--result-file='.$path];
    if($clean)$args[]='--ignore-table='.DB_NAME.'.election_controls';
    $args[]=DB_NAME;
    $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    if(!is_resource($p))throw new RuntimeException('Cannot start mysqldump.');
    fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);
    if(proc_close($p)!==0||!is_file($path)||filesize($path)<100)throw new RuntimeException('Database export failed; no cleanup performed.');
};
$dump($backup,false);$dump($pending,true);
$sql=file_get_contents($pending);
if(preg_match('/\b(?:DROP|CREATE)\s+DATABASE\b|^\s*(?:\/\*![0-9]*\s*)?USE\s/im',$sql))throw new RuntimeException('Refusing database-selection statements in the export.');
$tables=$live->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$expected=array_values(array_diff($tables,['election_controls']));sort($expected);
$scratch='coopvote_exportcheck_'.$stamp;
if(!preg_match('/^coopvote_exportcheck_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/',$scratch)||$scratch===DB_NAME)throw new RuntimeException('Unsafe scratch database name.');
$server=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
// CREATE without IF NOT EXISTS ensures an existing database is never overwritten.
$server->exec('CREATE DATABASE `'.$scratch.'` CHARACTER SET utf8mb4');
try {
    $server->exec('USE `'.$scratch.'`');sql_import($server,$sql);
    $actual=$server->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($actual);
    if($actual!==$expected)throw new RuntimeException('Export table inventory mismatch.');
    $counts=[];foreach($actual as $t)$counts[$t]=(int)$server->query('SELECT COUNT(*) FROM `'.str_replace('`','``',$t).'`')->fetchColumn();
    if(!$counts['users']||!$counts['roles'])throw new RuntimeException('Export is missing account data.');
    $roleNames=$server->query('SELECT slug FROM roles ORDER BY slug')->fetchAll(PDO::FETCH_COLUMN);
    if($roleNames!==['admin','voter'])throw new RuntimeException('Unexpected role inventory.');
    $orphans=(int)$server->query('SELECT COUNT(*) FROM vote_integrity i LEFT JOIN votes v ON v.vote_id=i.vote_id WHERE v.vote_id IS NULL')->fetchColumn();
    if($orphans)throw new RuntimeException('Orphaned integrity records in export.');
    $counts['profilePhotoBytes']=(int)$server->query('SELECT COALESCE(SUM(OCTET_LENGTH(photo_data)),0) FROM member_profile_photo_chunks')->fetchColumn();
} finally { $server->exec('DROP DATABASE `'.$scratch.'`'); }
// Only the retired quorum table is removed; its previous contents remain in the backup.
if(in_array('election_controls',$tables,true)){
    $columns=$live->query('SHOW COLUMNS FROM election_controls')->fetchAll(PDO::FETCH_COLUMN);
    if($columns!==['election_id','quorum_percent'])throw new RuntimeException('Quorum table shape changed; review before cleanup.');
    $refs=$live->prepare('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME=?');$refs->execute([DB_NAME,'election_controls']);
    if((int)$refs->fetchColumn())throw new RuntimeException('Another table references quorum storage; cleanup stopped.');
    $live->exec('DROP TABLE election_controls');
}
$destination=BASE_PATH.'/database/coopvote_full.sql';
if(is_file($destination))copy($destination,BASE_PATH.'/logs/previous-full-export-'.$stamp.'.sql');
if(!copy($pending,$destination))throw new RuntimeException('Validated export could not be published. It remains at '.$pending);
unlink($pending);
file_put_contents(BASE_PATH.'/database/export_manifest.json',json_encode(['exportedAt'=>date('c'),'tables'=>count($expected),'removed'=>['election_controls'],'rows'=>$counts,'sha256'=>hash_file('sha256',$destination),'backup'=>basename($backup)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo 'Validated export: '.$destination.PHP_EOL.'Tables: '.count($expected).PHP_EOL.'Backup: '.$backup.PHP_EOL;
echo json_encode($counts,JSON_UNESCAPED_SLASHES).PHP_EOL;

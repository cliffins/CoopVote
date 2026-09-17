<?php
/** Additive management upgrade. The backup is retained in the protected logs folder. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../includes/helpers.php';
$pdo=db();
$dump=PHP_OS_FAMILY==='Windows' ? dirname(PHP_BINARY,2).'/mysql/bin/mysqldump.exe' : 'mysqldump';
$backup=BASE_PATH.'/logs/pre-management-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sql';
$env=getenv();$env['MYSQL_PWD']=DB_PASS;
$args=[$dump,'--host='.DB_HOST,'--port='.DB_PORT,'--user='.DB_USER,'--single-transaction','--hex-blob','--result-file='.$backup,DB_NAME];
$process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
if(!is_resource($process))throw new RuntimeException('Could not start database backup.');
fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);
if(proc_close($process)!==0 || !is_file($backup) || filesize($backup)<100)throw new RuntimeException('Backup did not complete. No migration was run. Check mysqldump availability and database credentials.');
$counts=static function(PDO $pdo):array { $out=[];foreach(['users','roles','elections','votes','ballots','candidates'] as $t)$out[$t]=(int)$pdo->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();return $out; };
$before=$counts($pdo);
foreach(['08_governance','09_admin_approvals'] as $migration){
    $sql=file_get_contents(BASE_PATH.'/database/migration_'.$migration.'.sql');
    if(preg_match('/\b(DROP\s+(DATABASE|TABLE)|DELETE\s+FROM|TRUNCATE\s+TABLE|ALTER\s+TABLE)\b/i',$sql))throw new RuntimeException('Expected additive table creation only.');
    $pdo->exec($sql);
}
if($before!==$counts($pdo))throw new RuntimeException('Record counts changed during migration; review concurrent activity. Backup: '.$backup);
echo 'Management installed. Existing account, election and voting counts unchanged.'.PHP_EOL;
echo 'Backup: '.$backup.PHP_EOL;

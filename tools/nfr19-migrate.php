<?php
/** Never browse this file. Explicit CLI review/backup gates precede any database write. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/helpers.php';
require dirname(__DIR__).'/api/routes/voter.php';
require dirname(__DIR__).'/includes/privacy-migration.php';
$args=getopt('', ['mode:','backup:','verification:','archive:','key:','database:','approve:','maintenance-confirmed']);
$mode=$args['mode']??'plan';
try {
    $pdo=db();
    if($mode==='plan'){
        privacy_verify_integrity($pdo);
        echo json_encode(['mode'=>'read-only','selections'=>(int)$pdo->query('SELECT COUNT(*) FROM votes')->fetchColumn(),'datedSelections'=>(int)$pdo->query('SELECT COUNT(*) FROM votes WHERE cast_date IS NOT NULL')->fetchColumn(),'integrity'=>'passed','productionChanged'=>false]),PHP_EOL;exit;
    }
    if(!in_array($mode,['apply','rollback'],true)||($args['approve']??'')!=='NFR19-reviewed'||($args['database']??'')!==DB_NAME||!array_key_exists('maintenance-confirmed',$args))throw new RuntimeException('Explicit reviewed approval, exact database, and confirmed maintenance are required.');
    $archive=$args['archive']??'';$key=$args['key']??'';
    if(!$archive||!$key)throw new RuntimeException('Private rollback archive and separate key paths are required.');
    if($mode==='apply'){
        $backup=$args['backup']??'';$file=$args['verification']??'';
        if(!is_file($backup)||!is_file($file))throw new RuntimeException('A restore-verified backup and verification manifest are required.');
        $verified=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if(($verified['database']??'')!==DB_NAME||($verified['restoredAndCompared']??false)!==true||!hash_equals($verified['backupSha256']??'',hash_file('sha256',$backup)))throw new RuntimeException('Backup verification does not match.');
        if(($verified['fingerprint']??null)!==privacy_database_fingerprint($pdo))throw new RuntimeException('Database changed after backup verification. Take and verify a fresh maintenance backup.');
        echo json_encode(privacy_migrate_up($pdo,$archive,$key)),PHP_EOL;
    } else {privacy_migrate_down($pdo,$archive,$key);echo "Rollback complete; no records deleted.\n";}
}catch(Throwable $e){fwrite(STDERR,'NFR19: '.$e->getMessage().PHP_EOL);exit(1);}

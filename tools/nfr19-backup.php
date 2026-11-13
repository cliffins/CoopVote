<?php
/** CLI maintenance backup and restored-copy comparison. No production writes. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/includes/helpers.php';
require dirname(__DIR__).'/api/routes/voter.php';
require dirname(__DIR__).'/includes/privacy-migration.php';
require dirname(__DIR__).'/includes/database-backup.php';
$args=getopt('', ['mode:','directory:','snapshot:','backup:','verification:']);
try {
    $pdo=db();
    if (($args['mode']??'')==='create') {
        $dir=realpath($args['directory']??'');
        if (!$dir || (fileperms($dir)&0077)!==0) throw new RuntimeException('Use an existing private directory (0700).');
        foreach (['database.sql','snapshot.json'] as $name) if(file_exists($dir.'/'.$name))throw new RuntimeException('Use a new empty backup directory.');
        privacy_verify_integrity($pdo);
        $before=privacy_database_fingerprint($pdo);
        $stream=database_backup_file();
        try { $bytes=stream_get_contents($stream); if($bytes===false)throw new RuntimeException('Backup read failed.'); privacy_private_file($dir.'/database.sql',$bytes); }
        finally { fclose($stream); }
        if(privacy_database_fingerprint($pdo)!==$before)throw new RuntimeException('Data changed during backup. Keep maintenance enabled and create a fresh backup.');
        privacy_private_file($dir.'/snapshot.json',json_encode(['database'=>DB_NAME,'fingerprint'=>$before,'backupSha256'=>hash_file('sha256',$dir.'/database.sql'),'restoredAndCompared'=>false],JSON_THROW_ON_ERROR));
        echo "Private backup created. Restore comparison is still required.\n";
    } elseif (($args['mode']??'')==='verify') {
        // Never mistake re-reading the source for independently restoring its backup.
        if(getenv('COOPVOTE_ENV')!=='test' || DB_NAME!=='coopvote_test' || !str_starts_with(DB_SETTINGS['socket'],'/tmp/coopvote-db-tests-'))throw new RuntimeException('Verification must use a disposable socket-only restored test database.');
        $meta=json_decode(file_get_contents($args['snapshot']??''),true,512,JSON_THROW_ON_ERROR);
        $backup=$args['backup']??'';
        if(!is_file($backup)||!hash_equals($meta['backupSha256']??'',hash_file('sha256',$backup)))throw new RuntimeException('Backup checksum mismatch.');
        if(($meta['fingerprint']??null)!==privacy_database_fingerprint($pdo))throw new RuntimeException('Restored rows differ from the source snapshot.');
        // The original signing key must be supplied privately to the disposable server.
        privacy_verify_integrity($pdo);
        $meta['restoredAndCompared']=true;
        privacy_private_file($args['verification']??'',json_encode($meta,JSON_THROW_ON_ERROR));
        echo "Backup restored and compared: every table row and vote integrity verified.\n";
    } else throw new RuntimeException('Specify --mode=create or --mode=verify. See docs/NFR19.md.');
} catch (Throwable $e) {
    // SQL/native exceptions could contain private values; report only the class.
    fwrite(STDERR,'NFR19 backup check failed: '.($e instanceof PDOException?get_class($e):$e->getMessage()).PHP_EOL);exit(1);
}

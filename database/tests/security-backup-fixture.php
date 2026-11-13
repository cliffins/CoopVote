<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COOPVOTE_ENV') !== 'test') exit(1);
require dirname(__DIR__, 2) . '/config/database.php';
if (DB_NAME !== 'coopvote_test' || !str_starts_with(DB_SETTINGS['socket'], '/tmp/coopvote-db-tests-')) throw new RuntimeException('Disposable database required.');
$pdo = db();
$mode = $argv[1] ?? '';
if ($mode === 'failure') $pdo->exec('CREATE TABLE backup_failure_fixture(id INT) ENGINE=MyISAM');
if ($mode === 'cleanup-failure') $pdo->exec('DROP TABLE backup_failure_fixture');
if ($mode === 'seed') {
    $pdo->exec("INSERT INTO users(user_id,username,email,password_hash,role_id,status) VALUES(9001,'securityfixture9001','securityfixture9001@example.test','unused',1,'deactivated')");
    $pdo->exec("INSERT INTO admin_profiles(user_id,display_name) VALUES(9001,'Other Administrator')");
    $insert = $pdo->prepare('INSERT INTO audit_logs(user_id,action,description,ip_address,timestamp) VALUES(?,?,?,?,?)');
    for ($i=0;$i<525;$i++) $insert->execute([1,'candidate.updated','Archived filter fixture '.$i,'192.0.2.25','2020-02-20 12:00:00']);
    foreach ([
        [1,'candidate.created','Start boundary','2020-02-21 00:00:00'],
        [9001,'candidate.created','End boundary 100%_ complete','2020-02-21 23:59:59'],
        [9001,'candidate.created','=1+1','2020-02-21 20:00:00'],
        [1,'candidate.created','Following day','2020-02-22 00:00:00'],
        [2,'candidate.created','Voter excluded','2020-02-21 10:00:00'],
        [1,'theme.updated','Theme excluded','2020-02-21 10:00:00'],
        [1,'branding.updated','Branding excluded','2020-02-21 10:00:00'],
    ] as [$user,$action,$description,$timestamp]) $insert->execute([$user,$action,$description,'192.0.2.25',$timestamp]);
    // Exercise binary data, Unicode, nulls, quoting and schema objects on restore.
    $pdo->exec('CREATE TABLE backup_fixture (id INT PRIMARY KEY, value_text TEXT, value_blob BLOB, optional_value TEXT NULL) ENGINE=InnoDB');
    $pdo->prepare('INSERT INTO backup_fixture VALUES(?,?,?,NULL)')->execute([1,"Quote ' and \\ and newline\nJosé 日本語", "\x00\xff\x80\x27\x5c"]);
    $pdo->exec('CREATE VIEW backup_fixture_view AS SELECT id,value_text FROM backup_fixture');
    $pdo->exec('CREATE FUNCTION backup_fixture_function() RETURNS INT DETERMINISTIC RETURN 42');
    $pdo->exec('CREATE TRIGGER backup_fixture_trigger BEFORE INSERT ON backup_fixture FOR EACH ROW SET NEW.optional_value = COALESCE(NEW.optional_value, \'trigger\')');
    $pdo->exec('CREATE EVENT backup_fixture_event ON SCHEDULE EVERY 1 DAY DISABLE DO UPDATE backup_fixture SET id=id');
}
if ($mode === 'snapshot' || $mode === 'verify') {
    $snapshot = [];
    $source = $mode === 'verify' ? 'coopvote_restore_test' : DB_NAME;
    $tables = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
    $tables->execute([$source]);
    foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $rows = $pdo->query('SELECT * FROM `'.$source.'`.`'.str_replace('`','``',$table).'`')->fetchAll(PDO::FETCH_ASSOC);
        $serialized = array_map('serialize', $rows); sort($serialized);
        $snapshot[$table] = hash('sha256', serialize($serialized));
    }
    $path = dirname(getenv('COOPVOTE_TEST_RESULTS')).'/backup-snapshot.json';
    if ($mode === 'snapshot') file_put_contents($path,json_encode($snapshot));
    else {
        if ($snapshot !== json_decode(file_get_contents($path),true)) throw new RuntimeException('Restored rows differ from source snapshot');
        if ((int)$pdo->query('SELECT coopvote_restore_test.backup_fixture_function()')->fetchColumn() !== 42) throw new RuntimeException('Routine missing');
        if ((int)$pdo->query('SELECT COUNT(*) FROM coopvote_restore_test.backup_fixture_view')->fetchColumn() !== 1) throw new RuntimeException('View missing');
        $pdo->exec('INSERT INTO coopvote_restore_test.backup_fixture(id) VALUES(2)');
        if ($pdo->query('SELECT optional_value FROM coopvote_restore_test.backup_fixture WHERE id=2')->fetchColumn() !== 'trigger') throw new RuntimeException('Trigger missing');
        if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA='coopvote_restore_test'")->fetchColumn() !== 1) throw new RuntimeException('Event missing');
        echo 'Backup restore: all table rows, Unicode/binary values, view, function, trigger and event PASS'.PHP_EOL;
    }
}
if ($mode === 'restore') {
    $pdo->exec('CREATE DATABASE coopvote_restore_test CHARACTER SET utf8mb4');
    $dump = fopen($argv[2], 'rb');
    $process = proc_open(['mariadb','--no-defaults','--socket='.DB_SETTINGS['socket'],'--user=root','coopvote_restore_test'], [0=>$dump,1=>STDOUT,2=>STDERR],$pipes);
    $exit = proc_close($process); fclose($dump);
    if ($exit !== 0) throw new RuntimeException('Test restore failed');
}

if ($mode === 'backup-cli') {
    require dirname(__DIR__, 2).'/includes/database-backup.php';
    $dump=database_backup_file(); $out=fopen($argv[2],'xb'); stream_copy_to_stream($dump,$out); fclose($out); fclose($dump);
}

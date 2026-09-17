<?php
declare(strict_types=1);
return static function (Suite $t, PDO $pdo): void {
    require_once BASE_PATH . '/includes/installation.php';
    tdb_guard((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
    $fx = tdb_seed($pdo);
    $t->group('Transferred installations identify and repair login prerequisites');
    $t->same('schema checker covers all shipped tables',25,count(installation_schema_requirements()));
    $t->same('current schema passes',['missingTables'=>[],'missingColumns'=>[]],installation_schema_issues($pdo,TEST_DB));
    $pdo->exec('ALTER TABLE users DROP COLUMN lockout_count');
    $pdo->exec('ALTER TABLE candidates DROP COLUMN platform');
    $issues = installation_schema_issues($pdo,TEST_DB);
    $t->ok('missing login column is detected',in_array('users.lockout_count',$issues['missingColumns'],true));
    $t->ok('missing candidate column is detected',in_array('candidates.platform',$issues['missingColumns'],true));
    $client = new Http('transfer_login');
    $failed = $client->login('t_admin');
    $t->same('incompatible login returns an installation error','DB_SCHEMA_MISMATCH',$failed['json']['code'] ?? null);
    $t->contains('login error points to the setup checker','setup.php',$failed['json']['fix'] ?? '');
    $t->same('raw SQL details stay private',null,$failed['json']['detail'] ?? null);
    $setup = Http::raw('GET',TestServer::url('setup.php'));
    $t->same('setup renders despite missing columns',200,$setup['status']);
    $t->contains('setup identifies the missing login column','users.lockout_count',$setup['body']);
    $run = static function(array $args): array {
        $env = array_merge(getenv(),['COOPVOTE_DB'=>TEST_DB,'COOPVOTE_MAIL'=>'0']);
        $proc = proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH,$env);
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);
        $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
        return [proc_close($proc),$out,$err];
    };
    $accounts = $pdo->query('SELECT user_id,password_hash FROM users ORDER BY user_id')->fetchAll();
    $votes = $pdo->query('SELECT * FROM votes ORDER BY vote_id')->fetchAll();
    [$status,$out,$err] = $run([PHP_BINARY,BASE_PATH.'/tools/repair_login_schema.php']);
    $t->same('additive repair succeeds',0,$status,$out.$err);
    $t->contains('repair creates a backup before changes','Backup:',$out);
    $t->same('repair preserves account hashes',$accounts,$pdo->query('SELECT user_id,password_hash FROM users ORDER BY user_id')->fetchAll());
    $t->same('repair preserves every vote',$votes,$pdo->query('SELECT * FROM votes ORDER BY vote_id')->fetchAll());
    $t->same('all required columns now exist',[],installation_schema_issues($pdo,TEST_DB)['missingColumns']);
    $t->same('login works after repair',200,$client->login('t_admin')['status']);
    [$status,$out,$err] = $run([PHP_BINARY,BASE_PATH.'/tools/repair_login_schema.php']);
    $t->same('repair can be repeated safely',0,$status,$out.$err);
    $t->contains('complete schema needs no further changes','No changes needed',$out);
    [$status,$out,$err] = $run([PHP_BINARY,'-d','session.save_path='.BASE_PATH.'/tests/reports/nonexistent-session-folder',BASE_PATH.'/tests/session-portability.php']);
    $t->same('invalid old session path recovers',0,$status,$out.$err);
    $t->same('login persists in the replacement session directory',['ok'=>true,'persisted'=>true,'fallback'=>true],json_decode($out,true));
    $pdo->exec('RENAME TABLE roles TO transfer_saved_roles');
    try {
        $t->same('partial import gives a meaningful login error','DB_SCHEMA_MISMATCH',$client->login('t_admin')['json']['code'] ?? null);
        $setup = Http::raw('GET',TestServer::url('setup.php'));
        $t->contains('setup lists missing tables','roles',$setup['body']);
        $before = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $curl = curl_init(TestServer::url('setup.php'));
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['action'=>'import']),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
        $body = (string) curl_exec($curl); curl_close($curl);
        $t->contains('installer refuses to overwrite an incomplete existing database','Nothing was changed',$body);
        $t->same('existing accounts survive an attempted reimport',$before,$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    } finally { $pdo->exec('RENAME TABLE transfer_saved_roles TO roles'); }
};

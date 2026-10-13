<?php
/** Maximum-size import and responsive browser checks against scratch data only. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
putenv('COOPVOTE_DB=coopvote_test');putenv('COOPVOTE_MAIL=0');
require __DIR__.'/../includes/auth.php';require __DIR__.'/../api/routes/voter.php';
require __DIR__.'/lib/testdb.php';require __DIR__.'/lib/http.php';
$pdo=tdb_connect();tdb_guard((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
tdb_seed($pdo);$pdo->exec("UPDATE elections SET status='closed'");
if(!TestServer::start(1,8098))exit(1);
try {
    if(!in_array('--browser-only',$argv,true)) {
    $admin=new Http('bulk_max');$admin->login('t_admin');
    $csv="memberId,first,last,email,phone,address,dob,username\n";
    for($i=0;$i<100;$i++)$csv.=",Maria,Santos,bulk$i@test.coop,09171234567,Santiago,1990-01-02,\n";
    $r=$admin->api('POST','voters/import/preview',['csv'=>$csv]);
    if(($r['json']['valid']??0)!==100)throw new RuntimeException('100-row preview failed');
    $r=$admin->api('POST','voters/import/commit',['token'=>$r['json']['token']]);
    if($r['status']!==201||count($r['json']['created']??[])!==100)throw new RuntimeException('100-row confirmation failed');
    echo '100 accounts imported in '.round($r['ms'])." ms; mail disabled.\n";
    }
    tdb_seed($pdo);$pdo->exec("UPDATE elections SET status='closed'");
    passthru('node tests/voter-import-browser.cjs',$status);
} finally { TestServer::stop(); }
exit($status);

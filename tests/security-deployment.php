<?php
/** Test web fail-closed behavior against a unique, disposable encrypted database. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/sql-import.php';
$p=db();$name='coopvote_security_probe_'.bin2hex(random_bytes(4));
$port=8129;
$sock=@fsockopen('127.0.0.1',$port,$no,$error,0.2);
if(is_resource($sock)){fclose($sock);throw new RuntimeException('Probe port already occupied.');}
$p->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$proc=null;$checks=0;
function deploy_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function probe_get():array{
    global $port;
    $c=curl_init("http://127.0.0.1:$port/api/index.php?r=branding");
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);$body=curl_exec($c);
    $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
    return [$status,json_decode($body?:'{}',true)];
}
try {
    $p->exec("USE `$name`");
    $sql=(string)file_get_contents(BASE_PATH.'/database/coopvote_full.sql');
    if(preg_match('/\b(?:DROP|CREATE)\s+DATABASE\b|^\s*(?:\/\*![0-9]*\s*)?USE\s/im',$sql))throw new RuntimeException('Unsafe source SQL.');
    sql_import($p,$sql);
    $proc=proc_open([PHP_BINARY,'-S',"127.0.0.1:$port",'-t',BASE_PATH],
        [0=>['pipe','r'],1=>['file',__DIR__.'/reports/deployment-probe.log','a'],2=>['file',__DIR__.'/reports/deployment-probe.log','a']],$pipes,BASE_PATH,
        array_merge(getenv(),['COOPVOTE_DB'=>$name,'COOPVOTE_MAIL'=>'0']));
    if(!is_resource($proc))throw new RuntimeException('Probe server failed.');fclose($pipes[0]);
    $deadline=microtime(true)+10;do{$r=probe_get();if($r[0])break;usleep(100000);}while(microtime(true)<$deadline);
    deploy_check($r[0]===200,'Encrypted database should serve branding.');
    $p->exec('ALTER TABLE settings ENCRYPTED=NO');
    $r=probe_get();deploy_check($r[0]===500 && ($r[1]['code']??'')==='DB_ENCRYPTION_REQUIRED','Unencrypted table must stop normal web access.');
    $p->exec('ALTER TABLE settings ENCRYPTED=YES ENCRYPTION_KEY_ID=1');
    deploy_check(probe_get()[0]===200,'Encryption repair should restore access.');
    $tls=$p->query("SHOW SESSION STATUS LIKE 'Ssl_version'")->fetch();
    deploy_check(in_array($tls['Value'],['TLSv1.2','TLSv1.3'],true),'PDO uses TLS 1.2+.');
    $local=require BASE_PATH.'/config/security-local.php';
    foreach([STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT=>false,STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT=>false,
        STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT=>true,STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT=>true] as $method=>$allowed){
        $ctx=stream_context_create(['ssl'=>['cafile'=>$local['db_ssl_ca'],'verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>'localhost','crypto_method'=>$method]]);
        $s=@stream_socket_client('tls://localhost:443',$errno,$errstr,5,STREAM_CLIENT_CONNECT,$ctx);
        deploy_check(is_resource($s)===$allowed,'Apache TLS version policy mismatch.');
        if(is_resource($s))fclose($s);
    }
    echo "$checks deployment security checks passed.\n";
} finally {
    if(is_resource($proc)){proc_terminate($proc);proc_close($proc);}
    $p->exec("DROP DATABASE `$name`");
}

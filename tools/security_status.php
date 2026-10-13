<?php
/** Read-only local deployment evidence; never prints credentials or key bytes. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../config/database.php';
$p=db();
$tls=$p->query("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_version','Ssl_cipher')")->fetchAll(PDO::FETCH_KEY_PAIR);
$q=$p->prepare("SELECT t.TABLE_NAME, t.CREATE_OPTIONS, e.ENCRYPTION_SCHEME, e.CURRENT_KEY_VERSION
    FROM information_schema.TABLES t LEFT JOIN information_schema.INNODB_TABLESPACES_ENCRYPTION e
    ON e.NAME=CONCAT(t.TABLE_SCHEMA,'/',t.TABLE_NAME)
    WHERE t.TABLE_SCHEMA=? AND t.TABLE_TYPE='BASE TABLE'");
$q->execute([DB_NAME]);$tables=$q->fetchAll();
$protected=array_filter($tables,static fn($t)=>str_contains(str_replace('`','',strtoupper($t['CREATE_OPTIONS'])),'ENCRYPTED=YES')
    && (int)$t['ENCRYPTION_SCHEME']===1 && (int)$t['CURRENT_KEY_VERSION']>0);
$variable=$p->query("SHOW VARIABLES LIKE 'file_key_management_filename'")->fetch();
$path=$variable['Value']??'';
$keyBits=null;
if($path && is_readable($path)){
    $text=(string)file_get_contents($path);
    if(preg_match('/^1;([a-f0-9]+)\s*$/mi',$text,$m))$keyBits=strlen($m[1])*4;
    unset($text,$m);
}
$controls=$p->query("SHOW VARIABLES WHERE Variable_name IN ('innodb_encrypt_log','innodb_encrypt_temporary_tables','encrypt_tmp_files','tls_version')")->fetchAll(PDO::FETCH_KEY_PAIR);
echo json_encode(['database'=>DB_NAME,'dbTls'=>$tls,'tables'=>count($tables),'encryptedTables'=>count($protected),
    'databaseKeyBits'=>$keyBits,'controls'=>$controls,'users'=>(int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn()],JSON_PRETTY_PRINT),"\n";
exit(count($protected)===count($tables)&&count($tables)>0&&$keyBits===256&&in_array($tls['Ssl_version']??'',['TLSv1.2','TLSv1.3'],true)?0:1);

<?php
/** Enable native MariaDB encryption on this application's InnoDB tables. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../config/database.php';
$name=$argv[1]??DB_NAME;
if(!in_array($name,[DB_NAME,'coopvote_test'],true)||in_array($name,['mysql','information_schema','performance_schema','sys'],true))throw new RuntimeException('Refusing a database outside this application.');
$p=db();
if($p->query("SELECT PLUGIN_STATUS FROM information_schema.PLUGINS WHERE PLUGIN_NAME='file_key_management'")->fetchColumn()!=='ACTIVE')throw new RuntimeException('Configure the encryption plugin and its separate 256-bit key first.');
$q=$p->prepare("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE'");
$q->execute([$name]);$tables=$q->fetchAll();
if(!$tables)throw new RuntimeException('No application tables found.');
foreach($tables as $table)if($table['ENGINE']!=='InnoDB')throw new RuntimeException('Unsupported table engine: '.$table['TABLE_NAME']);
foreach($tables as $table){
    $qualified='`'.str_replace('`','``',$name).'`.`'.str_replace('`','``',$table['TABLE_NAME']).'`';
    $p->exec('ALTER TABLE '.$qualified.' ENCRYPTED=YES ENCRYPTION_KEY_ID=1');
}
$q=$p->prepare('SELECT NAME,ENCRYPTION_SCHEME,CURRENT_KEY_ID,CURRENT_KEY_VERSION FROM information_schema.INNODB_TABLESPACES_ENCRYPTION WHERE NAME LIKE ?');
$q->execute([str_replace(['\\','_','%'],['\\\\','\\_','\\%'],$name).'/%']);$encrypted=$q->fetchAll();
if(count($encrypted)!==count($tables))throw new RuntimeException('Encrypted table inventory mismatch.');
foreach($encrypted as $row)if($row['ENCRYPTION_SCHEME']!=1||$row['CURRENT_KEY_ID']!=1||$row['CURRENT_KEY_VERSION']<1)throw new RuntimeException('Encryption is not ready for '.$row['NAME']);
echo $name.': '.count($encrypted)." InnoDB tables verified encrypted.\n";

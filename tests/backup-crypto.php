<?php
/** Exercise confidentiality, random nonces, and authenticated failure paths. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../includes/backup-crypto.php';
$checks=0;
function check(bool $ok,string $label):void { global $checks; if(!$ok)throw new RuntimeException($label); $checks++; }
function rejects(callable $work,string $label):void { $rejected=false; try{$work();}catch(RuntimeException|InvalidArgumentException $e){$rejected=true;} check($rejected,$label); }
$key=random_bytes(32);
foreach(['',"Secret election data\0with binary bytes",str_repeat('SQL ballot data ',10000)] as $plain) {
    $a=backup_encrypt($plain,$key); $b=backup_encrypt($plain,$key);
    check(backup_decrypt($a,$key)===$plain,'round-trip');
    check($a!==$b,'nonce reuse');
    if($plain!=='')check(!str_contains($a,$plain),'plaintext leaked');
    rejects(fn()=>backup_decrypt($a,random_bytes(32)),'wrong key accepted');
    foreach([0,10,22,strlen($a)-1] as $offset){$bad=$a;$bad[$offset]=chr(ord($bad[$offset])^1);rejects(fn()=>backup_decrypt($bad,$key),'modified envelope accepted');}
    rejects(fn()=>backup_decrypt(substr($a,0,20),$key),'truncated backup accepted');
}
rejects(fn()=>backup_encrypt('x','short'),'short key accepted');
echo "$checks backup encryption checks passed.\n";

<?php
/** Import ordinary mysqldump statements without sending the whole file as one packet. */
declare(strict_types=1);
function sql_import(PDO $pdo,string $sql):void
{
    $start=0;$quote=null;$comment=null;$code=false;$length=strlen($sql);
    for($i=0;$i<$length;$i++){
        $c=$sql[$i];$next=$sql[$i+1]??'';
        if($comment==='line'){if($c==="\n")$comment=null;continue;}
        if($comment==='block'){if($c==='*'&&$next==='/'){$comment=null;$i++;}continue;}
        if($quote!==null){
            if($c==='\\'){$i++;continue;}
            if($c===$quote){if($next===$quote)$i++;else $quote=null;}continue;
        }
        if($c==='/'&&$next==='*'){
            if(($sql[$i+2]??'')==='!'||substr($sql,$i+2,2)==='M!')$code=true;
            $comment='block';$i++;continue;
        }
        if($c==='#'||($c==='-'&&$next==='-'&&ctype_space($sql[$i+2]??' '))){$comment='line';continue;}
        if($c===';' ){
            if($code)$pdo->exec(substr($sql,$start,$i-$start));
            $start=$i+1;$code=false;continue;
        }
        if(!ctype_space($c))$code=true;
        if($c==="'"||$c==='"'||$c==='`')$quote=$c;
    }
    if($quote!==null||$comment==='block')throw new RuntimeException('The SQL file ends inside a quoted value or comment.');
    if($code)$pdo->exec(substr($sql,$start));
}

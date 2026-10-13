<?php
// Dedicated scratch-only API and browser checks; never opens the live database.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('COOPVOTE_DB=coopvote_test');putenv('COOPVOTE_MAIL=0');
require __DIR__.'/../includes/auth.php';require __DIR__.'/../api/routes/voter.php';
require __DIR__.'/lib/testdb.php';require __DIR__.'/lib/http.php';
$pdo=tdb_connect();tdb_guard((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
$fx=tdb_seed($pdo);
if(!TestServer::start(1,8097))exit(1);
$checks=0;$status=1;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
try {
 $admin=new Http('election_context');$admin->login('t_admin');
 $create=static function(string $title)use($admin,$check):array{
  $r=$admin->api('POST','elections',['title'=>$title,'positions'=>['President','Treasurer'],'startDate'=>'2090-01-01T09:00','endDate'=>'2090-01-02T09:00']);
  $check($r['status']===201,'Create election '.$title);$id=(int)$r['json']['id'];
  $positions=$admin->api('GET','positions&election='.$id)['json'];return ['id'=>$id,'position'=>(int)$positions[0]['position_id'],'title'=>$title];
 };
 $a=$create('Isolation A');$b=$create('Isolation B');$empty=$create('Isolation Empty');
 $members=$pdo->query("SELECT m.member_id FROM members m JOIN users u ON u.user_id=m.user_id JOIN roles r ON r.role_id=u.role_id WHERE u.status='active' AND r.slug='voter' ORDER BY m.member_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
 $nominate=static function(array $e,int $member,string $bio)use($admin,$check):int{
  $r=$admin->api('POST','candidates&election='.$e['id'],['electionId'=>$e['id'],'positionId'=>$e['position'],'memberId'=>$member,'desc'=>$bio]);
  $check($r['status']===201,'Create nomination in '.$e['title']);return (int)$r['json']['id'];
 };
 $ca=$nominate($a,(int)$members[0],'Only A');$cb=$nominate($b,(int)$members[0],'Only B');$other=$nominate($b,(int)$members[1],'Only B second');
 foreach([$a,$b] as $e){$rows=$admin->api('GET','candidates&election='.$e['id'])['json'];$check(count($rows)===($e['id']===$a['id']?1:2),'Scoped candidate count');foreach($rows as $row)$check((int)$row['electionId']===$e['id'],'No foreign candidacy in list');}
 $check($admin->api('GET','candidates&election='.$empty['id'])['json']===[],'New election inherits no candidates');
 $check($admin->api('GET','candidates&election=invalid')['status']===422,'Malformed scope cannot return all candidates');
 $ps=$admin->api('GET','positions&all=1&election='.$a['id'])['json'];$check(count($ps)===2&&count(array_filter($ps,fn($p)=>(int)$p['election_id']!==$a['id']))===0,'Explicit position scope overrides all');
 $check($admin->api('POST','candidates&election='.$b['id'],['electionId'=>$a['id'],'positionId'=>$a['position'],'memberId'=>$members[2]])['status']===422,'Reject mismatched create context');
 $check($admin->api('POST','candidates&election='.$a['id'],['electionId'=>$a['id'],'positionId'=>$b['position'],'memberId'=>$members[2]])['status']===422,'Reject position from another election');
 $check($admin->api('PUT','candidates/'.$ca.'&election='.$b['id'],['desc'=>'Wrong election'])['status']===422,'Reject mismatched edit context');
 $check($admin->api('DELETE','candidates/'.$ca.'&election='.$b['id'])['status']===422,'Reject mismatched delete context');
 $check($admin->api('DELETE','positions/'.$a['position'].'&election='.$b['id'])['status']===422,'Reject mismatched position delete');
 $check($admin->api('POST','positions&election='.$b['id'],['electionId'=>$a['id'],'name'=>'Wrong context'])['status']===422,'Reject mismatched position create');
 $check($admin->api('PUT','candidates/'.$ca.'&election='.$a['id'],['desc'=>'Updated A'])['status']===200,'Edit own election');
 $otherRow=$admin->api('GET','candidates&election='.$b['id'])['json'];$check($otherRow[0]['desc']==='Only B'||$otherRow[1]['desc']==='Only B','Editing A preserves B biography');
 $check($pdo->query('SELECT bio FROM candidates WHERE candidate_id='.$cb)->fetchColumn()==='Only B','Shared voter has independent election biography');
 file_put_contents(__DIR__.'/reports/election-context-fixtures.json',json_encode(compact('a','b','empty','ca','cb','members')));
 echo $checks." election context API checks passed.\n";
 passthru('node tests/election-context.cjs',$status);
} finally {TestServer::stop();}
exit($status);

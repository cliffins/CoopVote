<?php
/**
 * Local fixture preparation and read-only verification. No ballots are inserted here.
 * Usage: php tools/realistic_data.php prepare /path/to/source.xlsx
 * The source workbook is supplied by the operator so the script is portable.
 * Preparation retains existing fixtures instead of silently regenerating them.
 * Later verification compares those expected accounts with database records.
 * Output files are local test evidence, not reports intended for public hosting.
 * Account exports may contain test credentials and must stay private.
 * Normal voting still goes through the application's one-vote transaction.
 * Run only when deliberately preparing or checking an approved test dataset.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../config/database.php';
$dir = BASE_PATH . '/database/test-data';
$mode = $argv[1] ?? 'prepare';
function write_json(string $file, mixed $data): void {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
if ($mode === 'prepare') {
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    if (is_file($dir.'/fixtures.json')) { echo "Existing fixtures retained.\n"; exit; }
    $z = new ZipArchive();
    $workbook = $argv[2] ?? '';
    if ($workbook === '' || !is_file($workbook) || $z->open($workbook) !== true) throw new RuntimeException('Supply an existing workbook path: php tools/realistic_data.php prepare /path/to/source.xlsx');
    $dom = new DOMDocument();
    $dom->loadXML($z->getFromName('xl/worksheets/sheet1.xml'), LIBXML_NONET);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $voters = [];
    foreach ($xp->query('//s:sheetData/s:row') as $row) {
        if ((int)$row->getAttribute('r') === 1) continue;
        $r=[];
        foreach ($xp->query('s:c',$row) as $cell) {
            $column=preg_replace('/[0-9]/','',$cell->getAttribute('r'));
            $r[$column]=$xp->evaluate('string(s:is/s:t|s:v)',$cell);
        }
        if (empty($r['B']) || empty($r['C'])) continue;
        $voters[]=['number'=>(int)$r['A'],'first'=>$r['B'],'last'=>$r['C'],
            'dob'=>gmdate('Y-m-d', ((int)$r['E']-25569)*86400), 'email'=>$r['F'],
            'username'=>$r['G'],'phone'=>$r['H'],'address'=>$r['I'],'source'=>'Provided workbook'];
    }
    if (count($voters)!==60) throw new RuntimeException('Expected 60 source voters; inspect the workbook before continuing.');
    $extra=[
        ['Alejandro','Abad'],['Amelia','Aguilar'],['Andres','Balagtas'],['Angela','Bonifacio'],
        ['Antonio','Cabral'],['Beatriz','Cabrera'],['Benjie','Candelaria'],['Camille','Catapang'],
        ['Christian','David'],['Clarissa','Del Rosario'],['Daniel','Domingo'],['Danica','Enriquez'],
        ['Eduardo','Fajardo'],['Elaine','Flores'],['Ernesto','Galang'],['Francesca','Guevarra'],
        ['Gabriel','Hernandez'],['Giselle','Ignacio'],['Harold','Jimenez'],['Irene','Lacson'],
        ['Jerome','Lazaro'],['Joanna','Macapagal'],['Kenneth','Malabanan'],['Kristine','Manalo'],
        ['Leonardo','Marquez'],['Lorena','Mercado'],['Manuel','Navarro'],['Maricel','Ocampo'],
        ['Nathaniel','Pascual'],['Patricia','Pineda'],['Rafael','Ramos'],['Regina','Rivera'],
        ['Rogelio','Salvador'],['Rosalie','Sison'],['Samuel','Soriano'],['Sofia','Tolentino'],
        ['Victor','Valdez'],['Victoria','Velasco'],['William','Vergara'],['Yvonne','Zamora']
    ];
    foreach ($extra as $i=>[$first,$last]) {
        $n=61+$i;
        $voters[]=['number'=>$n,'first'=>$first,'last'=>$last,
            'dob'=>sprintf('%04d-%02d-%02d',1972+($i*7)%31,1+($i*5)%12,1+($i*3)%27),
            'email'=>sprintf('coopvote.voter%03d@example.com',$n),'username'=>sprintf('voter%03d',$n),
            'phone'=>sprintf('+63 900 000 %04d',$n),'address'=>sprintf('Test Address %02d, Santiago City, Isabela',$n),
            'source'=>'Additional fictional record'];
    }
    $s=db()->prepare('SELECT COUNT(*) FROM users WHERE username=? OR email=?');
    foreach ($voters as $v) {
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid test email');
        $s->execute([$v['username'],$v['email']]);
        if ($s->fetchColumn()) throw new RuntimeException('Existing account collision: '.$v['username']);
    }
    $baseline=[];
    foreach (['users','members','elections','positions','candidates','ballots','votes','audit_logs'] as $table) {
        $baseline[$table]=db()->query('SELECT * FROM '.$table)->fetchAll();
    }
    write_json($dir.'/baseline.json',$baseline);
    write_json($dir.'/fixtures.json',$voters);
    echo "Prepared 60 workbook voters plus 40 fictional voters; no database rows changed.\n";
    exit;
}
$state=json_decode(file_get_contents($dir.'/state.json'),true,512,JSON_THROW_ON_ERROR);
$fixtures=json_decode(file_get_contents($dir.'/fixtures.json'),true,512,JSON_THROW_ON_ERROR);
$p=db(); $checks=[]; $summary=[];
$check=static function(string $name,bool $ok) use (&$checks): void { $checks[]=['check'=>$name,'passed'=>$ok]; };
$check('100 accounts created through the admin interface',count($state['accounts'])===100);
$lookup=$p->prepare('SELECT u.*,m.member_no,m.full_name,m.date_of_birth,m.contact_no,m.address,m.membership_status FROM users u JOIN members m USING(user_id) WHERE username=?');
$valid=0; $unused=0; $csvRows=[];
foreach ($state['accounts'] as $i=>$account) {
    $lookup->execute([$account['username']]); $u=$lookup->fetch();
    $v=$fixtures[$i];
    $correct=$u && password_verify($account['password'],$u['password_hash'])
        && $u['status']==='active' && $u['membership_status']==='active'
        && $u['full_name']===$v['first'].' '.$v['last'] && $u['email']===$v['email']
        && $u['date_of_birth']===$v['dob'] && $u['contact_no']===$v['phone']
        && $u['address']===$v['address'] && $u['member_no']===$account['memberNo'];
    if ($correct) $valid++;
    $count=$p->prepare('SELECT COUNT(*) FROM ballots WHERE user_id=? AND has_voted=1');
    $count->execute([$u['user_id']]); $cast=(int)$count->fetchColumn();
    if ($i>=50 && $cast===0) $unused++;
    $csvRows[]=['No.'=>$i+1,'First Name'=>$v['first'],'Last Name'=>$v['last'],
        'Member ID'=>$account['memberNo'],'Username'=>$account['username'],'Temporary Password'=>$account['password'],
        'Email'=>$v['email'],'Date of Birth'=>$v['dob'],'Phone'=>$v['phone'],'Address'=>$v['address'],
        'Main Election'=>$i<50?'Voted through UI':'Available for manual voting',
        'Results Demo'=>$i<10?'Voted through UI':'Not voted','Source'=>$v['source']];
}
$check('All imported fields and generated passwords match 100 active accounts',$valid===100);
$check('Voters 051-100 have no cast ballot in any election',$unused===50);
foreach ($state['elections'] as $e) {
    $id=(int)$e['id'];
    $row=$p->query("SELECT * FROM elections WHERE election_id=$id")->fetch();
    $ballots=$p->query("SELECT * FROM ballots WHERE election_id=$id AND has_voted=1")->fetchAll();
    $votes=(int)$p->query("SELECT COUNT(*) FROM votes WHERE election_id=$id")->fetchColumn();
    $expected=$e['kind']==='main'?50:10;
    $positions=6;
    $check($e['kind'].': expected ballot and selection totals',count($ballots)===$expected && $votes===$expected*$positions);
    $check($e['kind'].': ballots carry browser provenance',count(array_filter($ballots,static fn($b)=>str_contains($b['user_agent']??'','Chrome/') && $b['ip_address']==='127.0.0.1'))===$expected);
    $check($e['kind'].': one audit entry per cast ballot',(int)$p->query("SELECT COUNT(*) FROM audit_logs WHERE action='ballot.cast' AND entity_type='election' AND entity_id=$id")->fetchColumn()===$expected);
    $check($e['kind'].': correct election availability',$e['kind']==='main'
        ? $row['status']==='published' && strtotime($row['end_datetime'])>time() && !$row['results_released_at']
        : $row['status']==='published' && strtotime($row['end_datetime'])<=time() && (bool)$row['results_released_at']);
    $check($e['kind'].': browser receipts match database ballots',
        count(array_filter($state['ballots'],static fn($b)=>(int)$b['electionId']===$id))===$expected
        && count(array_intersect(array_column($ballots,'receipt_code'),array_column($state['ballots'],'receiptCode')))===$expected);
    $summary[]=['kind'=>$e['kind'],'id'=>$id,'title'=>$row['title'],'ballots'=>count($ballots),'selections'=>$votes,
        'ends'=>$row['end_datetime'],'released'=>$row['results_released_at'],
        'tallies'=>$p->query("SELECT p.position_name,m.full_name,COUNT(v.vote_id) votes FROM candidates c JOIN positions p ON p.position_id=c.position_id JOIN members m ON m.member_id=c.member_id LEFT JOIN votes v ON v.candidate_id=c.candidate_id WHERE c.election_id=$id GROUP BY c.candidate_id ORDER BY p.position_id,votes DESC")->fetchAll()];
}
$baseline=json_decode(file_get_contents($dir.'/baseline.json'),true,512,JSON_THROW_ON_ERROR);
foreach (['elections'=>'election_id','positions'=>'position_id','candidates'=>'candidate_id','ballots'=>'ballot_id','votes'=>'vote_id'] as $table=>$key) {
    $old=$baseline[$table]; $ok=true;
    foreach ($old as $row) { $s=$p->prepare("SELECT * FROM $table WHERE $key=?"); $s->execute([$row[$key]]); if ($s->fetch()!=$row) $ok=false; }
    $check('Existing '.$table.' records preserved',$ok);
}
$eligible=(int)$p->query("SELECT COUNT(*) FROM users u JOIN roles r USING(role_id) WHERE r.slug='voter' AND u.status='active'")->fetchColumn();
$report=['generatedAt'=>date(DATE_ATOM),'sourceVoters'=>60,'additionalVoters'=>40,'dummyVoters'=>100,
    'dummyVotersWhoVoted'=>50,'manualVotersUnused'=>$unused,'eligibleVotersIncludingExisting'=>$eligible,
    'elections'=>$summary,'checks'=>$checks,'browserChecks'=>$state['checks']];
write_json($dir.'/verification.json',$report);
$f=fopen($dir.'/100-voter-accounts.csv','w'); fwrite($f,"\xEF\xBB\xBF"); fputcsv($f,array_keys($csvRows[0]));
foreach ($csvRows as $row) fputcsv($f,$row); fclose($f);
if (class_exists('ZipArchive')) {
    $out=new ZipArchive(); $out->open($dir.'/CoopVote_100_Test_Voter_Accounts.xlsx',ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $out->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $out->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $out->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="100 Test Voter Accounts" sheetId="1" r:id="r1"/></sheets></workbook>');
    $out->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="14" width="24" customWidth="1"/></cols><sheetData>';
    foreach (array_merge([array_keys($csvRows[0])],array_map('array_values',$csvRows)) as $r=>$row) {
        $xml.='<row r="'.($r+1).'">';
        foreach ($row as $c=>$value) $xml.='<c r="'.chr(65+$c).($r+1).'" t="inlineStr"><is><t>'.htmlspecialchars((string)$value,ENT_XML1,'UTF-8').'</t></is></c>';
        $xml.='</row>';
    }
    $xml.='</sheetData><autoFilter ref="A1:N101"/></worksheet>';
    $out->addFromString('xl/worksheets/sheet1.xml',$xml); $out->close();
}
$failed=array_filter($checks,static fn($c)=>!$c['passed']);
echo json_encode(['checks'=>count($checks),'failed'=>array_values($failed),'eligibleVoters'=>$eligible,'elections'=>array_map(static fn($e)=>array_diff_key($e,['tallies'=>true]),$summary)],JSON_PRETTY_PRINT).PHP_EOL;
exit($failed?1:0);

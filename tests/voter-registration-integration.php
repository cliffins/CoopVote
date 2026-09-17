<?php
declare(strict_types=1);
return static function (Suite $t, PDO $pdo): void {
    $fx = tdb_seed($pdo);
    $admin = new Http('registration_admin'); $admin->login('t_admin');
    $voter = new Http('registration_voter'); $voter->login('t_voter1');
    $payload = ['first'=>'New', 'last'=>'Member', 'email'=>'registration@test.coop',
        'phone'=>'09171234567', 'address'=>'123 Test Street', 'dob'=>'1990-01-01'];
    $state = fn() => $admin->api('GET', 'voters/registration-status');
    $t->group('Voter creation is locked while any election is active');
    $t->same('voters cannot read admin registration settings',403,$voter->api('GET','voters/registration-status')['status']);
    $t->same('anonymous callers cannot read registration settings',401,(new Http('registration_anon'))->api('GET','voters/registration-status')['status']);
    $t->same('active election disables registration',false,$state()['json']['allowed']);
    $before = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $r = $admin->api('POST','voters',$payload);
    $t->same('direct account creation is blocked',409,$r['status']);
    $t->same('blocked creation identifies the election rule','VOTER_REGISTRATION_CLOSED',$r['json']['code'] ?? null);
    $t->same('blocked requests create no user',$before,$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    $t->same('editing an existing voter remains allowed',200,$admin->api('PUT','voters/'.$fx['voters'][1],
        ['first'=>'Test','last'=>'Member','email'=>'voter1@test.coop','memberId'=>'TEST-0001',
         'phone'=>'09171234567','address'=>'Updated test address','dob'=>'1990-01-01'])['status']);

    $pdo->prepare("UPDATE elections SET start_datetime=DATE_SUB(NOW(),INTERVAL 1 HOUR), end_datetime=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE election_id=?")->execute([$fx['upcoming']]);
    $t->same('one election can close',200,$admin->api('PATCH','elections/'.$fx['live'].'/close')['status']);
    $t->same('another active election keeps registration locked',false,$state()['json']['allowed']);
    $t->same('a selected closed election cannot bypass the rule',409,$admin->api('POST','voters&election='.$fx['closed'],$payload)['status']);
    $admin->api('PATCH','elections/'.$fx['upcoming'].'/close');
    $t->same('closing all active elections unlocks registration',true,$state()['json']['allowed']);
    $t->same('registration works after closing',201,$admin->api('POST','voters',$payload)['status']);

    foreach (['draft','postponed','cancelled','upcoming','expired'] as $i=>$scenario) {
        $status = in_array($scenario,['upcoming','expired'],true) ? 'published' : $scenario;
        $start = date('Y-m-d H:i:s',time()+($scenario==='upcoming'?3600:-7200));
        $end = date('Y-m-d H:i:s',time()+($scenario==='expired'?-1:7200));
        $pdo->prepare('UPDATE elections SET status=?, start_datetime=?, end_datetime=? WHERE election_id=?')
            ->execute([$status,$start,$end,$fx['live']]);
        $t->same($scenario.' election permits registration',true,$state()['json']['allowed']);
        $payload['email']='registration'.$i.'@test.coop';
        $t->same('account creation works with '.$scenario.' election',201,$admin->api('POST','voters',$payload)['status']);
    }
    // The form was available above; a scheduled election now starts before submission.
    $pdo->prepare("UPDATE elections SET status='published',start_datetime=NOW(),end_datetime=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE election_id=?")->execute([$fx['live']]);
    $payload['email']='registration.late@test.coop';
    $t->same('a form opened before voting cannot submit after voting starts',409,$admin->api('POST','voters',$payload)['status']);
};

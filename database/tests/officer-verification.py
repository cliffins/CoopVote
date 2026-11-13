#!/usr/bin/env python3
"""Officer verification tests: private disposable DB, synthetic accounts, no real mail."""
import argparse, contextlib, hashlib, http.cookiejar, json, os, secrets, socket, subprocess, tempfile, time
import urllib.request, urllib.error
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CHECKS = []

def check(name, expected, actual):
    ok = expected == actual
    CHECKS.append(dict(test=name, expected=expected, actual=actual, status='PASS' if ok else 'FAIL'))
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    if not ok:
        raise AssertionError(name)  # Never print response bodies, credentials or links.

def stop(p):
    if p.poll() is None:
        p.terminate()
        try: p.wait(timeout=10)
        except subprocess.TimeoutExpired: p.kill(); p.wait()

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def exercise(env, sock, out, tmp):
    base = env['COOPVOTE_TEST_URL']
    assert env['COOPVOTE_ENV']=='test' and str(sock).startswith('/tmp/coopvote-db-tests-')
    assert base.startswith('http://127.0.0.1:')
    def sql(statement):
        return subprocess.run(['mariadb','--no-defaults','--socket='+str(sock),'-uroot','--batch','--skip-column-names','coopvote_test'],input=statement,text=True,capture_output=True,check=True).stdout.strip()
    def api(c, route, data=None, method=None, expected=200, label=None):
        method = method or ('POST' if data is not None else 'GET')
        req=urllib.request.Request(base+'?r='+route, data=None if data is None else json.dumps(data).encode(), method=method,headers={'Content-Type':'application/json'})
        try: response=c.open(req,timeout=20)
        except urllib.error.HTTPError as e: response=e
        body=json.loads(response.read())
        check(label or method+' '+route,expected,response.status)
        return body
    anon,admin,voter=client(),client(),client()
    api(admin,'auth/login',dict(username='testadmin',password='EndpointTest9!'))
    api(voter,'auth/login',dict(username='testvoter',password='EndpointTest9!'))
    def token(uid):
        jobs=[json.loads(line) for line in Path(env['COOPVOTE_OFFICER_TEST_OUTBOX']).read_text().splitlines()]
        return next(j['url'].split('#token=')[1] for j in reversed(jobs) if j['id']==uid)
    def age(uid): sql(f'UPDATE officer_email_verifications SET requested_at=DATE_SUB(NOW(),INTERVAL 61 SECOND),window_started_at=DATE_SUB(NOW(),INTERVAL 2 HOUR) WHERE user_id={uid}')
    def create(username):
        r=api(admin,'management/accounts',dict(name='Synthetic Officer',username=username,email=username+'@example.test'),expected=201)
        check('Invitation response contains no password or token',False,any(k in r for k in ['password','token','hash','url']))
        check('Synthetic message captured',True,r['mailSent'])
        return r['id'],token(r['id'])
    def complete(t,p='OfficerPass9!',confirm='OfficerPass9!',expected=200):
        return api(anon,'auth/officer-verification',dict(action='complete',token=t,password=p,confirmPassword=confirm),expected=expected)
    def inspect(t,expected=200): return api(anon,'auth/officer-verification',dict(action='inspect',token=t),expected=expected)
    def account(uid): return next(a for a in api(admin,'management/accounts') if a['id']==uid)
    def change(uid,email):
        age(uid)
        return api(admin,f'management/accounts/{uid}',dict(name='Synthetic Officer',email=email),method='PUT')
    def snap():
        return {table:hashlib.sha256(sql('SELECT * FROM '+table+' ORDER BY 1').encode()).hexdigest() for table in ['elections','positions','candidates','votes','ballots','vote_integrity','election_integrity']}
    sql("INSERT INTO elections(election_id,title,start_datetime,end_datetime,created_by) VALUES(1,'Synthetic preserved election','2030-01-01','2030-01-02',1); INSERT INTO positions(position_id,election_id,position_name) VALUES(1,1,'Chair'); INSERT INTO candidates(candidate_id,member_id,position_id,election_id) VALUES(1,2,1,1); INSERT INTO ballots(election_id,user_id,has_voted) VALUES(1,2,1); INSERT INTO votes(vote_id,election_id,position_id,candidate_id) VALUES(1,1,1,1)")
    before=snap()
    existing=sql('SELECT user_id,username,email,password_hash,status,email_verified_at FROM users WHERE user_id IN(1,2,3) ORDER BY user_id')
    # Upgrade path starts without the new table; apply twice to prove additive/idempotent behavior.
    sql('DROP TABLE officer_email_verifications')
    for _ in range(2): sql((ROOT/'database/migration_16_officer_email_verification.sql').read_text())
    check('Migration twice preserves existing user records',True,existing==sql('SELECT user_id,username,email,password_hash,status,email_verified_at FROM users WHERE user_id IN(1,2,3) ORDER BY user_id'))
    check('Legacy administrator access retained; not falsely marked verified','existing',account(1)['verification'])
    for c,status in [(anon,401),(voter,403)]:
        for method,route,data in [('GET','management/accounts',None),('POST','management/accounts',{}),('PUT','management/accounts/1',{}),('POST','management/accounts/1/resend-verification',{}),('POST','management/accounts/1/cancel-email-change',{})]:
            api(c,route,data,method,status)
    for action in ['register','verify-otp','resend-otp']: api(anon,'auth/'+action,{},expected=403)
    api(anon,'auth/officer-verification',expected=405)
    for t in ['', 'bad', '0'*64]: inspect(t,400)
    uid,t=create('invitedofficer')
    inspect(('0' if t[0]!='0' else '1')+t[1:],400)
    check('New officer pending, unverified, excluded from active admins','pending_verification\t1',sql(f'SELECT status,email_verified_at IS NULL FROM users WHERE user_id={uid}'))
    check('Only one active administrator before acceptance','1',sql("SELECT COUNT(*) FROM users WHERE role_id=1 AND status='active'"))
    check('Token stored hashed only',True,sql(f'SELECT token_hash FROM officer_email_verifications WHERE user_id={uid}')==hashlib.sha256(t.encode()).hexdigest())
    check('Token absent from listing',False,t in json.dumps(account(uid)))
    inspect(t);inspect(t)
    check('Inspect does not activate','pending_verification',sql(f'SELECT status FROM users WHERE user_id={uid}'))
    api(anon,'auth/login',dict(username='invitedofficer',password='OfficerPass9!'),expected=401)
    api(admin,f'management/accounts/{uid}',dict(status='active'),expected=409)
    complete(t,'weak','weak',422);complete(t,'OfficerPass9!','different',422)
    api(admin,f'management/accounts/{uid}/resend-verification',{},expected=429)
    age(uid);api(admin,f'management/accounts/{uid}/resend-verification',{})
    inspect(t,400);t=token(uid)
    sql(f'UPDATE officer_email_verifications SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE user_id={uid}')
    inspect(t,400);complete(t,expected=400)
    age(uid);api(admin,f'management/accounts/{uid}/resend-verification',{});t=token(uid)
    complete(t);complete(t,expected=400);inspect(t,400)
    check('Activation verified and token consumed','active\t1\t1\t1',sql(f'SELECT u.status,u.email_verified_at IS NOT NULL,v.token_hash IS NULL,v.consumed_at IS NOT NULL FROM users u JOIN officer_email_verifications v USING(user_id) WHERE u.user_id={uid}'))
    officer=client();api(officer,'auth/login',dict(username='invitedofficer',password='OfficerPass9!'))
    check('Verified badge after acceptance','verified',account(uid)['verification'])
    change(uid,'new-officer@example.test');et=token(uid)
    check('Old email retained during verification','invitedofficer@example.test',sql(f'SELECT email FROM users WHERE user_id={uid}'))
    check('Pending new email displayed','new-officer@example.test',account(uid)['pendingEmail'])
    complete(et,'incorrect','',422)
    check('Wrong current password counted','1',sql(f'SELECT failed_attempts FROM officer_email_verifications WHERE user_id={uid}'))
    complete(et,confirm='');complete(et,expected=400)
    check('New email saved after verification','new-officer@example.test',sql(f'SELECT email FROM users WHERE user_id={uid}'))
    api(client(),'auth/login',dict(username='invitedofficer',password='OfficerPass9!'))
    change(uid,'cancelled@example.test');et=token(uid)
    api(admin,f'management/accounts/{uid}/cancel-email-change',{});inspect(et,400)
    check('Cancel retains current email','new-officer@example.test',sql(f'SELECT email FROM users WHERE user_id={uid}'))
    change(uid,'attempts@example.test');et=token(uid)
    for _ in range(5): complete(et,'incorrect','',422)
    inspect(et,400);complete(et,expected=400)
    change(uid,'conflict@example.test');et=token(uid)
    sql("UPDATE users SET email='conflict@example.test' WHERE user_id=3")
    complete(et,confirm='',expected=409)
    sql("UPDATE users SET email='testnominee@example.test' WHERE user_id=3")
    api(admin,f'management/accounts/{uid}/cancel-email-change',{})
    age(uid);api(admin,f'management/accounts/{uid}',dict(name='Synthetic Officer',email='testadmin@example.test'),method='PUT',expected=409)
    change(uid,'deactivated@example.test');et=token(uid)
    api(admin,f'management/accounts/{uid}',dict(status='deactivated'));inspect(et,400)
    api(officer,'management/accounts',expected=403,label='Deactivated officer session denied access')
    api(admin,f'management/accounts/{uid}',dict(status='active'));inspect(et,400)
    age(uid);api(admin,f'management/accounts/{uid}/resend-verification',{});complete(token(uid),confirm='')
    pid,pt=create('pendingofficer')
    api(admin,f'management/accounts/{pid}',dict(status='deactivated'))
    check('Deactivated invite still labelled pending','pending',account(pid)['verification'])
    api(admin,f'management/accounts/{pid}',dict(status='active'),expected=409);inspect(pt,400)
    age(pid);api(admin,f'management/accounts/{pid}/resend-verification',{})
    check('Reinvitation remains pending','pending_verification',sql(f'SELECT status FROM users WHERE user_id={pid}'))
    # Exercise the actual five-send window while advancing only the cooldown clock.
    for _ in range(4):
        sql(f'UPDATE officer_email_verifications SET requested_at=DATE_SUB(NOW(),INTERVAL 61 SECOND) WHERE user_id={pid}')
        api(admin,f'management/accounts/{pid}/resend-verification',{})
    sql(f'UPDATE officer_email_verifications SET requested_at=DATE_SUB(NOW(),INTERVAL 61 SECOND) WHERE user_id={pid}')
    api(admin,f'management/accounts/{pid}/resend-verification',{},expected=429)
    # Disabled transport must report failure and leave the account pending.
    no_capture=dict(env);no_capture.pop('COOPVOTE_OFFICER_TEST_OUTBOX')
    code="require 'includes/officer-verification.php'; echo json_encode(officer_deliver(['id'=>"+str(pid)+",'name'=>'Synthetic Officer','email'=>'pendingofficer@example.test','purpose'=>'invite','hash'=>'"+hashlib.sha256(token(pid).encode()).hexdigest()+"','url'=>'https://example.test/officer-verification.php']));"
    delivered=subprocess.run(['php','-r',code],env=no_capture,cwd=ROOT,capture_output=True,text=True,check=True).stdout.strip()
    check('Disabled mail returns failure','false',delivered)
    check('Failed delivery leaves pending and records failure','pending_verification\tfailed',sql(f'SELECT u.status,v.delivery_status FROM users u JOIN officer_email_verifications v USING(user_id) WHERE u.user_id={pid}'))
    check('Mail failure visible in officer listing','failed',account(pid)['deliveryStatus'])
    # Exercise the response wrapper used by create/resend, with the real disabled mail transport.
    response_code=code.replace('json_encode(officer_deliver(', 'json_encode(officer_delivery_response(').replace("]));", "], 'Officer saved.'));")
    failed_response=json.loads(subprocess.run(['php','-r',response_code],env=no_capture,cwd=ROOT,capture_output=True,text=True,check=True).stdout)
    check('Mail failure response reports failure and resend instructions',True,failed_response['mailSent'] is False and 'Resend Verification' in failed_response['message'])
    # Separate PHP processes contend on the real database lock, unlike the single-worker HTTP fixture.
    race_id,race_token=create('concurrentofficer')
    worker="require 'includes/auth.php'; require 'includes/officer-verification.php'; $v=json_decode(stream_get_contents(STDIN),true); while(microtime(true)<$v['start']) usleep(1000); try { officer_finish($v['token'],'ConcurrentPass9!','ConcurrentPass9!'); echo '200'; } catch(GovernanceError $e) { echo $e->status; }"
    payload=json.dumps(dict(token=race_token,start=time.time()+1.5)).encode()
    workers=[subprocess.Popen(['php','-r',worker],env=env,cwd=ROOT,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE) for _ in range(2)]
    for p in workers:p.stdin.write(payload);p.stdin.close();p.stdin=None
    statuses=[]
    for p in workers:
        stdout,stderr=p.communicate(timeout=15)
        check('Concurrent verification worker exits cleanly',0,p.returncode)
        statuses.append(stdout.decode())
    check('Concurrent acceptance succeeds exactly once',['200','400'],sorted(statuses))
    check('Concurrent acceptance audited exactly once','1',sql(f"SELECT COUNT(*) FROM audit_logs WHERE entity_id={race_id} AND action='officer.email_verified'"))
    check('No verification tokens in audit details',False,any(token(x) in sql('SELECT description FROM audit_logs') for x in [uid,pid]))
    check('Existing accounts retain email, password, status',True,existing==sql('SELECT user_id,username,email,password_hash,status,email_verified_at FROM users WHERE user_id IN(1,2,3) ORDER BY user_id'))
    check('Synthetic elections, ballots and votes unchanged',True,before==snap())
    env['COOPVOTE_OFFICER_EVIDENCE']=str(out)
    if env.get('COOPVOTE_PLAYWRIGHT_MODULE'):
        r=subprocess.run(['node',str(ROOT/'database/tests/officer-verification-browser.cjs')],env=env,cwd=ROOT,capture_output=True,text=True,timeout=180)
        (out/'browser-checks.txt').write_text(r.stdout+r.stderr)
        print(r.stdout,end='')
        check('Browser verification suite exit code',0,r.returncode)
        check('Browser flow preserves elections, ballots and votes',True,before==snap())


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir',type=Path,required=True)
    parser.add_argument('--playwright-module')
    parser.add_argument('--regression',action='store_true',help='Run existing HTTP and database regression checks on a fresh disposable fixture')
    args=parser.parse_args();out=args.output_dir.resolve();out.mkdir(parents=True,exist_ok=False)
    env={k:v for k,v in os.environ.items() if not k.startswith('COOPVOTE_')}
    env.update(COOPVOTE_ENV='test',COOPVOTE_MAIL='0',COOPVOTE_INTEGRITY_KEY=secrets.token_hex(32))
    if args.playwright_module:env['COOPVOTE_PLAYWRIGHT_MODULE']=args.playwright_module
    try:
        with tempfile.TemporaryDirectory(prefix='coopvote-db-tests-officers-',dir='/tmp') as folder, contextlib.ExitStack() as stack:
            tmp=Path(folder);(tmp/'data').mkdir(mode=0o700);(tmp/'sessions').mkdir(mode=0o700);sock=tmp/'mysql.sock'
            cfg=tmp/'config.php';cfg.write_text("<?php return ['environment'=>'test','db_socket'=>'"+str(sock)+"','db_name'=>'coopvote_test','db_user'=>'root','db_password'=>''];")
            env.update(COOPVOTE_CONFIG_FILE=str(cfg),COOPVOTE_OFFICER_TEST_OUTBOX=str(tmp/'outbox.jsonl'))
            log=stack.enter_context((tmp/'services.log').open('w'))
            db=subprocess.Popen(['/usr/sbin/mariadbd','--no-defaults','--datadir='+str(tmp/'data'),'--socket='+str(sock),'--pid-file='+str(tmp/'mysql.pid'),'--skip-networking','--skip-grant-tables','--skip-log-bin','--innodb-buffer-pool-size=64M'],stdout=log,stderr=log);stack.callback(stop,db)
            for _ in range(300):
                if db.poll() is not None:raise RuntimeError('Disposable database startup failed')
                if sock.exists() and subprocess.run(['mariadb','--no-defaults','--socket='+str(sock),'-uroot','-e','SELECT 1'],capture_output=True).returncode==0:break
                time.sleep(.1)
            else:raise RuntimeError('Database readiness timeout')
            subprocess.run(['php',str(ROOT/'database/tests/seed.php')],env=env,cwd=ROOT,check=True)
            with socket.socket() as probe:probe.bind(('127.0.0.1',0));port=probe.getsockname()[1]
            env.update(COOPVOTE_TEST_URL=f'http://127.0.0.1:{port}/api/index.php',COOPVOTE_PUBLIC_URL=f'http://127.0.0.1:{port}')
            web=subprocess.Popen(['php','-d','session.save_path='+str(tmp/'sessions'),'-S',f'127.0.0.1:{port}','-t',str(ROOT),str(ROOT/'database/tests/router.php')],env=env,stdout=log,stderr=log);stack.callback(stop,web)
            for _ in range(100):
                try:
                    if urllib.request.urlopen(env['COOPVOTE_TEST_URL'],timeout=1).status==200:break
                except OSError:pass
                time.sleep(.1)
            else:raise RuntimeError('Web readiness timeout')
            exercise(env,sock,out,tmp)
            if args.regression:
                env['COOPVOTE_TEST_RESULTS']=str(tmp/'regression-results.json')
                for command in [['php','database/tests/seed.php'],['php','database/tests/relational-constraints.php'],['python3','database/tests/endpoints.py'],['php','database/tests/database-checks.php']]:
                    result=subprocess.run(command,env=env,cwd=ROOT,capture_output=True,text=True,timeout=180)
                    (out/(Path(command[1]).stem+'-regression.txt')).write_text(result.stdout+result.stderr)
                    check('Regression '+Path(command[1]).name,0,result.returncode)
    finally:
        (out/'results.json').write_text(json.dumps(CHECKS,indent=2)+'\n')
    print(f'{len(CHECKS)} checks passed. Disposable services stopped and data removed.')

if __name__=='__main__':main()

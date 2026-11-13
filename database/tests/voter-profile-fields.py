#!/usr/bin/env python3
"""Voter profile field checks on a disposable socket-only database."""
import contextlib, http.cookiejar, importlib.util, json, os, secrets, socket, subprocess, tempfile, time, urllib.error, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
REPORT=Path(os.environ.get('COOPVOTE_PROFILE_REPORT','/tmp/coopvote-voter-profile-fields-results.json'))
results=[]
def check(name,condition):
    results.append({'test':name,'status':'PASS' if condition else 'FAIL'})
    print(results[-1]['status'],name,flush=True)
    if not condition: raise AssertionError(name)
def stop(p):
    if p.poll() is None:
        p.terminate()
        try:p.wait(timeout=10)
        except subprocess.TimeoutExpired:p.kill();p.wait()
def main():
    env={k:v for k,v in os.environ.items() if not k.startswith('COOPVOTE_')}
    with tempfile.TemporaryDirectory(prefix='coopvote-db-tests-login-',dir='/tmp') as folder,contextlib.ExitStack() as stack:
        tmp=Path(folder);(tmp/'data').mkdir();(tmp/'sessions').mkdir(mode=0o700);sock=tmp/'mysql.sock'
        cfg=tmp/'config.php';cfg.write_text("<?php return ['environment'=>'test','db_socket'=>'"+str(sock)+"','db_name'=>'coopvote_test','db_user'=>'root','db_password'=>''];")
        env.update(COOPVOTE_ENV='test',COOPVOTE_CONFIG_FILE=str(cfg),COOPVOTE_MAIL='0',COOPVOTE_INTEGRITY_KEY=secrets.token_hex(32))
        log=stack.enter_context((tmp/'services.log').open('w'))
        db=subprocess.Popen(['/usr/sbin/mariadbd','--no-defaults','--datadir='+str(tmp/'data'),'--socket='+str(sock),'--pid-file='+str(tmp/'mysql.pid'),'--skip-networking','--skip-grant-tables','--skip-log-bin','--innodb-buffer-pool-size=64M'],stdout=log,stderr=log);stack.callback(stop,db)
        for _ in range(600):
            if db.poll() is not None:raise RuntimeError('Disposable database stopped')
            if sock.exists() and subprocess.run(['mariadb','--no-defaults','--socket='+str(sock),'-uroot','-e','SELECT 1'],capture_output=True).returncode==0:break
            time.sleep(.1)
        else:raise RuntimeError('Disposable database readiness timeout')
        subprocess.run(['php8.4',str(ROOT/'database/tests/seed.php')],env=env,check=True,stdout=log,stderr=log)
        with socket.socket() as probe:probe.bind(('127.0.0.1',0));port=probe.getsockname()[1]
        base=f'http://127.0.0.1:{port}/api/index.php?r='
        web=subprocess.Popen(['php8.4','-d','session.save_path='+str(tmp/'sessions'),'-d','session.gc_probability=0','-d','output_buffering=0','-S',f'127.0.0.1:{port}','-t',str(ROOT)],env=env,stdout=log,stderr=log);stack.callback(stop,web)
        for _ in range(100):
            try:
                if urllib.request.urlopen(base,timeout=1).status==200:break
            except OSError:pass
            time.sleep(.1)
        else:raise RuntimeError('Disposable API readiness timeout')
        def client():
            jar=http.cookiejar.CookieJar();return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar)),jar
        def call(c,route,data=None,expected=200,headers=None,method=None):
            req=urllib.request.Request(base+route,method=method,data=json.dumps(data).encode() if data is not None else None,headers={'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest',**(headers or {})})
            try:r=c.open(req,timeout=15)
            except urllib.error.HTTPError as e:r=e
            raw=r.read();payload=json.loads(raw)
            assert r.status==expected,(route,r.status,expected)
            assert 'application/json' in r.headers.get('Content-Type','')
            return payload,r
        creds=lambda name:{'username':name,'password':'EndpointTest9!'}
        def sql(query):
            r=subprocess.run(['mariadb','--no-defaults','--socket='+str(sock),'-uroot','-N','coopvote_test','-e',query],capture_output=True,text=True,check=True)
            return r.stdout.strip()
        c,jar=client();call(c,'auth/login',creds('testvoter'))
        form=c.open(f'http://127.0.0.1:{port}/voter/profile.php').read().decode()
        check('Rendered My Profile has no phone input or phone save binding', 'f-phone' not in form and "'email', 'phone', 'address'" not in form)
        values={'first':'Test','last':'Voter','email':'testvoter@example.test','dob':'1990-01-01','address':'Updated synthetic address'}
        before=sql('SELECT contact_no FROM members WHERE user_id=2')
        data,_=call(c,'voter/profile')
        check('Profile response omits obsolete phone field','phone' not in data['member'])
        for method in ('PUT','POST'):
            data,_=call(c,'voter/profile',values,method=method)
            check(method+' saves profile without phone',data['member']['address']==values['address'])
            check(method+' preserves existing contact number',sql('SELECT contact_no FROM members WHERE user_id=2')==before)
        call(c,'voter/profile',{**values,'phone':''},method='PUT')
        check('Stale phone payload cannot erase stored number',sql('SELECT contact_no FROM members WHERE user_id=2')==before)
        admin,_=client();call(admin,'auth/login',creds('testadmin'))
        admin_form=admin.open(f'http://127.0.0.1:{port}/admin/voter-management.php').read().decode()
        check('Admin form has no obsolete phone control or save binding','f-phone' not in admin_form and 'payload.phone' not in admin_form)
        voters,_=call(admin,'voters')
        target=next(v for v in voters if v['id']==2)
        check('Admin voter response omits phone','phone' not in target)
        for method in ('PUT','PATCH'):
            call(admin,'voters/2',{**values,'memberId':target['memberId'],'phone':'invalid obsolete phone'},method=method)
            check(method+' admin edit ignores stale phone input and preserves stored number',sql('SELECT contact_no FROM members WHERE user_id=2')==before)
        created,_=call(admin,'voters',{**values,'email':'nophone@example.test','username':'nophone','phone':'obsolete'},method='POST',expected=201)
        check('Admin create ignores obsolete phone input',sql('SELECT contact_no IS NULL FROM members WHERE user_id='+str(created['id']))=='1')
        sql('UPDATE members SET contact_no=NULL WHERE user_id=2')
        call(c,'voter/profile',values,method='PUT')
        check('Voter with no phone can save other details',sql('SELECT contact_no IS NULL FROM members WHERE user_id=2')=='1')
        data,_=call(c,'voter/profile',{**values,'email':'invalid'},expected=422,method='PUT')
        check('Email validation remains active','email' in data['fields'])
        data,_=call(c,'voter/profile',{**values,'dob':''},expected=422,method='PUT')
        check('Birth date validation remains active','dob' in data['fields'])
        anon,_=client();call(anon,'voter/profile',values,expected=401,method='PUT')
        check('Unauthenticated profile update still denied',True)
        check('No votes or elections created',sql('SELECT (SELECT COUNT(*) FROM votes)+(SELECT COUNT(*) FROM elections)')=='0')
    check('Disposable database and test services cleaned up',True)
try:main()
finally:REPORT.write_text(json.dumps({'scope':'Synthetic database only; no production profile changes','results':results},indent=2))

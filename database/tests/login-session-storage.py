#!/usr/bin/env python3
"""Login session persistence checks on a disposable socket-only database."""
import contextlib, http.cookiejar, importlib.util, json, os, secrets, socket, subprocess, tempfile, time, urllib.error, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
REPORT=Path(os.environ.get('COOPVOTE_LOGIN_REPORT','/tmp/coopvote-login-session-results.json'))
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
        # Fault injection is created only in this private temporary test server.
        prepend=tmp/'fault.php';prepend.write_text('''<?php
if (getenv('COOPVOTE_ENV') !== 'test') exit(1);
if (($_SERVER['HTTP_X_TEST_HTTPS'] ?? '') === '1') $_SERVER['HTTPS']='on';
final class TestStorage extends SessionHandler {
 public function write(string $id, string $data): bool {
  $fault=$_SERVER['HTTP_X_TEST_SESSION_FAULT'] ?? '';
  if ($fault==='rotation' || ($fault==='write' && $data!=='')) {
   trigger_error('Synthetic session write failed: Disk quota exceeded (122)',E_USER_WARNING);
   return false;
  }
  return parent::write($id,$data);
 }
}
session_set_save_handler(new TestStorage(),true);
''')
        with socket.socket() as probe:probe.bind(('127.0.0.1',0));port=probe.getsockname()[1]
        base=f'http://127.0.0.1:{port}/api/index.php?r='
        web=subprocess.Popen(['php8.4','-d','session.save_path='+str(tmp/'sessions'),'-d','session.gc_probability=0','-d','auto_prepend_file='+str(prepend),'-d','output_buffering=0','-S',f'127.0.0.1:{port}','-t',str(ROOT)],env=env,stdout=log,stderr=log);stack.callback(stop,web)
        for _ in range(100):
            try:
                if urllib.request.urlopen(base,timeout=1).status==200:break
            except OSError:pass
            time.sleep(.1)
        else:raise RuntimeError('Disposable API readiness timeout')
        def client():
            jar=http.cookiejar.CookieJar();return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar)),jar
        def call(c,route,data=None,expected=200,headers=None):
            req=urllib.request.Request(base+route,data=json.dumps(data).encode() if data is not None else None,headers={'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest',**(headers or {})})
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
        for name,role in [('testadmin','admin'),('testvoter','member')]:
            c,jar=client();call(c,'auth/me',expected=401);before=[x.value for x in jar]
            data,r=call(c,'auth/login',creds(name));after=[x.value for x in jar]
            check(name+' login response matches frontend contract',data['ok'] is True and data['role']==role and all(k in data for k in ('roleSlug','roleName','redirect','user')))
            check(name+' session ID rotated',bool(after) and before!=after)
            check(name+' cookie HttpOnly and SameSite=Lax',all(x.has_nonstandard_attr('HttpOnly') and x.get_nonstandard_attr('SameSite')=='Lax' for x in jar))
            call(c,'auth/me');check(name+' login persists to next request',True)
            call(c,'auth/logout',{});call(c,'auth/me',expected=401);check(name+' logout removes authentication',True)
        c,jar=client();_,r=call(c,'auth/login',creds('testadmin'),headers={'X-Test-HTTPS':'1'})
        check('HTTPS login cookie is Secure, HttpOnly and SameSite=Lax',all(x.secure and x.has_nonstandard_attr('HttpOnly') and x.get_nonstandard_attr('SameSite')=='Lax' for x in jar) and bool(list(jar)))
        for fault in ('write','rotation'):
            c,jar=client();before=sql('SELECT failed_attempts,lockout_count,COALESCE(last_login_at,\'NULL\') FROM users WHERE user_id=3')
            data,r=call(c,'auth/login',creds('testnominee'),expected=500,headers={'X-Test-Session-Fault':fault})
            check(fault+' failure returns one JSON 500 with storage code',data['ok'] is False and data['code']=='SESSION_STORAGE_UNAVAILABLE' and 'session' in data['error'])
            check(fault+' failure does not record successful login/reset counters',before==sql('SELECT failed_attempts,lockout_count,COALESCE(last_login_at,\'NULL\') FROM users WHERE user_id=3'))
            call(c,'auth/me',expected=401);check(fault+' failure leaves browser unauthenticated',True)
        c,jar=client();call(c,'auth/login',creds('testnominee'));call(c,'auth/me');check('Login recovers when session writes recover',True)
        sql("UPDATE users SET status='pending_verification',email_verified_at=NULL WHERE user_id=3")
        c,jar=client();data,_=call(c,'auth/login',creds('testnominee'),expected=403)
        check('Unverified voter still requires verification',data['needsVerification'] is True and data['ok'] is False)
        call(c,'auth/me',expected=401)
        c,jar=client();data,_=call(c,'auth/login',creds('testnominee'),expected=500,headers={'X-Test-Session-Fault':'write'})
        check('Pending verification session write failure is one JSON storage error',data['code']=='SESSION_STORAGE_UNAVAILABLE')
        call(c,'auth/me',expected=401)
        c,jar=client();call(c,'auth/login',{},expected=400)
        check('Empty login remains JSON 400',True)
        for _ in range(5):data,_=call(c,'auth/login',{'username':'testadmin','password':'SyntheticWrongPassword'},expected=401)
        data,_=call(c,'auth/login',creds('testadmin'),expected=401)
        check('Five wrong passwords still lock account; correct password cannot bypass lockout','Too many failed attempts' in data['error'])
        check('Lockout counter retained',sql('SELECT lockout_count FROM users WHERE user_id=1')=='1')
        call(c,'auth/me',expected=401)
        check('No votes or elections created',sql('SELECT (SELECT COUNT(*) FROM votes)+(SELECT COUNT(*) FROM elections)')=='0')
    check('Disposable database and test services cleaned up',True)
try:main()
finally:REPORT.write_text(json.dumps({'scope':'Synthetic database only; no production account login or data changes','results':results},indent=2))

#!/usr/bin/env python3
"""Verify rescheduling and removal of the cancellation action in a disposable database only."""
import argparse, contextlib, datetime, hashlib, json, os
from pathlib import Path
import secrets, shutil, socket, subprocess, tempfile, time
ROOT=Path(__file__).resolve().parents[2]

def stop(process):
    if process.poll() is None:
        process.terminate()
        try: process.wait(timeout=10)
        except subprocess.TimeoutExpired: process.kill(); process.wait()

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir',required=True,type=Path)
    parser.add_argument('--playwright-module',default='playwright')
    parser.add_argument('--browser-path')
    parser.add_argument('--fontconfig-file')
    parser.add_argument('--regression',action='store_true',help='Also run the existing HTTP regression suite on a fresh disposable fixture')
    parser.add_argument('--backend-only',action='store_true',help='Verify schedule/deletion restrictions through direct HTTP requests and test atomic deletion auditing')
    args=parser.parse_args(); out=args.output_dir.resolve();out.mkdir(parents=True,exist_ok=False)
    env={k:v for k,v in os.environ.items() if not k.startswith(('COOPVOTE_','FR8_'))}
    env.update(COOPVOTE_ENV='test',COOPVOTE_MAIL='0',COOPVOTE_INTEGRITY_KEY=secrets.token_hex(32),
               COOPVOTE_PLAYWRIGHT_MODULE=args.playwright_module,FR8_OUTPUT=str(out))
    if args.browser_path:env['PLAYWRIGHT_BROWSERS_PATH']=args.browser_path
    if args.fontconfig_file:env['FR8_FONTCONFIG']=args.fontconfig_file
    paths=['admin/election-management.php','api/routes/admin.php','includes/election-operations.php','assets/css/election-form.css']
    hashes={p:hashlib.sha256((ROOT/p).read_bytes()).hexdigest() for p in paths}
    started=datetime.datetime.now(datetime.timezone.utc).isoformat()
    with contextlib.ExitStack() as stack:
        folder=Path(tempfile.mkdtemp(prefix='coopvote-db-tests-fr8-',dir='/tmp'))
        stack.callback(shutil.rmtree,folder)
        (folder/'data').mkdir(mode=0o700);(folder/'sessions').mkdir(mode=0o700);sock=folder/'mysql.sock'
        config=folder/'settings.php'
        config.write_text("<?php return ['environment'=>'test','db_host'=>'127.0.0.1','db_socket'=>'"+str(sock)+"','db_name'=>'coopvote_test','db_user'=>'root','db_password'=>''];")
        env.update(COOPVOTE_CONFIG_FILE=str(config),COOPVOTE_TEST_RESULTS=str(folder/'fixture.json'))
        log=stack.enter_context((out/'isolated-services.log').open('w'))
        db=subprocess.Popen(['/usr/sbin/mariadbd','--no-defaults','--datadir='+str(folder/'data'),'--socket='+str(sock),
            '--pid-file='+str(folder/'db.pid'),'--skip-networking','--skip-grant-tables','--skip-log-bin',
            '--innodb-buffer-pool-size=64M'],stdout=log,stderr=log)
        stack.callback(stop,db)
        mysql=['mariadb','--no-defaults','--socket='+str(sock),'--user=root']
        for _ in range(150):
            if subprocess.run(mysql+['-e','SELECT 1'],capture_output=True).returncode==0:break
            if db.poll() is not None:raise RuntimeError('Disposable database stopped')
            time.sleep(.1)
        else:raise RuntimeError('Disposable database readiness timeout')
        for script,arguments in [('seed.php',[]),('nfr19-fixture.php',['seed']),('nfr19-fixture.php',['seed-abstention'])]:
            subprocess.run(['php',str(ROOT/'database/tests'/script),*arguments],env=env,check=True,capture_output=True)
        subprocess.run(['php',str(ROOT/'database/tests/fr8-fixture.php'),'seed'],env=env,check=True)
        with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
        env['FR8_BASE']='http://127.0.0.1:'+str(port)
        web=subprocess.Popen(['php','-d','session.save_path='+str(folder/'sessions'),'-S','127.0.0.1:'+str(port),'-t',str(ROOT),str(ROOT/'database/tests/router.php')],env=env,stdout=log,stderr=log)
        stack.callback(stop,web);time.sleep(.5)
        command=['python3',str(ROOT/'database/tests/fr8-backend.py')] if args.backend_only else ['node',str(ROOT/'database/tests/fr8-browser.cjs')]
        run=subprocess.run(command,env=env,text=True,capture_output=True,timeout=180)
        (out/'TEST.log').write_text(run.stdout+run.stderr);print(run.stdout+run.stderr,flush=True);run.check_returncode()
        verification=subprocess.run(['php',str(ROOT/'database/tests/fr8-fixture.php'),'verify-backend' if args.backend_only else 'verify'],env=env,check=True,capture_output=True,text=True)
        (out/'DATABASE.json').write_text(verification.stdout)
        result=json.loads((out/'RESULTS.json').read_text())
        result.update(database_checks=json.loads(verification.stdout),started_utc=started,source_sha256=hashes,
            source_unchanged=all(hashlib.sha256((ROOT/p).read_bytes()).hexdigest()==h for p,h in hashes.items()),
            database_authentication='Grant checks disabled only on this private socket-only disposable fixture')
        if args.regression:
            subprocess.run(['php',str(ROOT/'database/tests/seed.php')],env=env,check=True,capture_output=True)
            env.update(COOPVOTE_TEST_URL=env['FR8_BASE']+'/api/index.php',COOPVOTE_TEST_RESULTS=str(out/'REGRESSION.json'))
            regression=subprocess.run(['python3',str(ROOT/'database/tests/endpoints.py')],env=env,text=True,capture_output=True,timeout=180)
            (out/'REGRESSION.log').write_text(regression.stdout+regression.stderr)
            print(regression.stdout+regression.stderr,flush=True)
            regression.check_returncode()
            checks=json.loads((out/'REGRESSION.json').read_text())
            result['regression']={'assertions':len(checks),'all_passed':all(c['pass'] for c in checks)}
    result.update(temporary_database_removed=not folder.exists(),services_stopped=db.poll() is not None and web.poll() is not None)
    (out/'RESULTS.json').write_text(json.dumps(result,indent=2)+'\n')

if __name__=='__main__':main()

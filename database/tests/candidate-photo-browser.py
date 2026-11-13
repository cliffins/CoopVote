#!/usr/bin/env python3
"""Exercise candidate photos through real browser/API calls in a disposable DB only."""
import argparse, contextlib, datetime, hashlib, json, os
from pathlib import Path
import secrets, shutil, socket, struct, subprocess, tempfile, time, zlib
ROOT=Path(__file__).resolve().parents[2]

def stop(process):
    if process.poll() is None:
        process.terminate()
        try: process.wait(timeout=10)
        except subprocess.TimeoutExpired: process.kill(); process.wait()

def png(width,height,pixels):
    def chunk(kind,data):
        return struct.pack('!I',len(data))+kind+data+struct.pack('!I',zlib.crc32(kind+data)&0xffffffff)
    raw=b''.join(b'\0'+pixels[y*width*3:(y+1)*width*3] for y in range(height))
    return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('!2I5B',width,height,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(raw))+chunk(b'IEND',b'')

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir',required=True,type=Path)
    parser.add_argument('--playwright-module',default='playwright')
    parser.add_argument('--browser-path')
    parser.add_argument('--fontconfig-file')
    args=parser.parse_args(); out=args.output_dir.resolve();out.mkdir(parents=True,exist_ok=False)
    env={k:v for k,v in os.environ.items() if not k.startswith(('COOPVOTE_','FR10_'))}
    env.update(COOPVOTE_ENV='test',COOPVOTE_MAIL='0',COOPVOTE_INTEGRITY_KEY=secrets.token_hex(32),
               COOPVOTE_PLAYWRIGHT_MODULE=args.playwright_module,FR10_OUTPUT=str(out))
    if args.browser_path:env['PLAYWRIGHT_BROWSERS_PATH']=args.browser_path
    if args.fontconfig_file:env['FR10_FONTCONFIG']=args.fontconfig_file
    for name,color in [('original',(10,90,170)),('replacement',(200,110,30)),('member',(80,150,80))]:
        (out/(name+'.png')).write_bytes(png(80,80,bytes(color)*80*80))
    (out/'too-wide.png').write_bytes(png(6001,1,bytes((10,20,30))*6001))
    (out/'multichunk.png').write_bytes(png(180,180,secrets.token_bytes(180*180*3)))
    paths=['admin/voter-management.php','api/routes/admin.php','admin/candidate-management.php','includes/admin-candidates.php','includes/candidate-photo.php',
           'includes/profile-photo.php','api/routes/voter.php','database/schema_only.sql']
    hashes={p:hashlib.sha256((ROOT/p).read_bytes()).hexdigest() for p in paths}
    started=datetime.datetime.now(datetime.timezone.utc).isoformat()
    with contextlib.ExitStack() as stack:
        folder=Path(tempfile.mkdtemp(prefix='coopvote-db-tests-fr10-',dir='/tmp'))
        stack.callback(shutil.rmtree,folder)
        (folder/'data').mkdir(mode=0o700);(folder/'sessions').mkdir(mode=0o700);sock=folder/'mysql.sock'
        config=folder/'settings.php'
        config.write_text("<?php return ['environment'=>'test','db_host'=>'127.0.0.1','db_socket'=>'"+str(sock)+"','db_name'=>'coopvote_test','db_user'=>'root','db_password'=>''];")
        env.update(COOPVOTE_CONFIG_FILE=str(config),COOPVOTE_TEST_RESULTS=str(folder/'fixture.json'),
                   COOPVOTE_OFFICER_TEST_OUTBOX=str(folder/'officer-outbox.jsonl'))
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
        sql="UPDATE elections SET title='FR10 Candidate Profile Test' WHERE election_id=900; UPDATE members SET full_name='Sample Candidate' WHERE member_id=10000; UPDATE candidates SET section='A',year_level='2nd Year',department='Computing',course='BS Information Technology' WHERE candidate_id=9000;"
        subprocess.run(mysql+['coopvote_test'],input=sql,text=True,check=True,capture_output=True)
        # Legacy nomination-only image: reads must expose it to the member without rewriting history.
        legacy=(out/'member.png').read_bytes()
        sql="INSERT INTO candidate_photo_chunks(candidate_id,chunk_no,mime,photo_data) VALUES(9010,0,'image/png',UNHEX('"+legacy.hex()+"')); UPDATE candidates SET photo_url='candidate-photo:"+hashlib.sha256(legacy).hexdigest()+"' WHERE candidate_id=9010;"
        subprocess.run(mysql+['coopvote_test'],input=sql,text=True,check=True,capture_output=True)
        protected=['votes','vote_integrity','election_integrity','ballots','elections','positions','election_approval_requests','election_admin_decisions']
        def protected_hashes():
            return {table:hashlib.sha256(subprocess.run(mysql+['--batch','coopvote_test','-e','SELECT * FROM '+table],check=True,capture_output=True).stdout).hexdigest() for table in protected}
        before=protected_hashes()
        with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
        env['FR10_BASE']='http://127.0.0.1:'+str(port)
        web=subprocess.Popen(['php','-d','session.save_path='+str(folder/'sessions'),'-S','127.0.0.1:'+str(port),'-t',str(ROOT),str(ROOT/'database/tests/router.php')],env=env,stdout=log,stderr=log)
        stack.callback(stop,web);time.sleep(.5)
        run=subprocess.run(['node',str(ROOT/'database/tests/candidate-photo-browser.cjs')],env=env,text=True,capture_output=True,timeout=180)
        (out/'TEST.log').write_text(run.stdout+run.stderr);print(run.stdout+run.stderr,flush=True);run.check_returncode()
        result=json.loads((out/'RESULTS.json').read_text())
        assert before==protected_hashes(), 'Protected election or vote data changed during photo tests'
        result['protected_tables_unchanged']=protected
        print('PASS Protected election and vote tables unchanged (8)',flush=True)
        result.update(started_utc=started,source_sha256=hashes,
            source_unchanged=all(hashlib.sha256((ROOT/p).read_bytes()).hexdigest()==h for p,h in hashes.items()),
            database_authentication='Grant checks disabled only on this private socket-only disposable fixture')
    result.update(temporary_database_removed=not folder.exists(),services_stopped=db.poll() is not None and web.poll() is not None)
    (out/'RESULTS.json').write_text(json.dumps(result,indent=2)+'\n')

if __name__=='__main__':main()

#!/usr/bin/env python3
"""NFR-PERF-02: real concurrent HTTP voting against a private disposable database."""
import argparse, collections, concurrent.futures, datetime, hashlib, http.cookiejar, json, math, os
from pathlib import Path
import re, secrets, shutil, signal, socket, subprocess, tempfile, threading, time, urllib.error, urllib.request

ROOT=Path(__file__).resolve().parents[2]
HERE=Path(__file__).resolve().parent

def stop(process, group=False):
    if process is None: return
    if group:
        try: os.killpg(process.pid,signal.SIGTERM)
        except ProcessLookupError: pass
    elif process.poll() is None: process.terminate()
    try: process.wait(timeout=10)
    except subprocess.TimeoutExpired:
        if group:
            try: os.killpg(process.pid,signal.SIGKILL)
            except ProcessLookupError: pass
        else: process.kill()
        process.wait(timeout=10)

def open_port(port):
    try:
        with socket.create_connection(('127.0.0.1',port),timeout=.2): return True
    except OSError: return False

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir',type=Path,required=True)
    parser.add_argument('--voters',type=int,default=40,help='Distinct voters in the initial waves (default 40)')
    parser.add_argument('--workers',type=int,default=8,help='PHP_CLI_SERVER_WORKERS setting (default 8)')
    args=parser.parse_args()
    if not 8<=args.voters<=200 or not 2<=args.workers<=16: parser.error('Use 8–200 voters and 2–16 workers')
    out=args.output_dir.resolve();out.mkdir(parents=True,exist_ok=False)
    n=args.voters; total=n+n//2
    paths=['api/index.php','api/routes/voter.php','includes/helpers.php','includes/auth.php','includes/vote-integrity.php',
           'includes/ballot-privacy.php','config/DB.php','database/schema_only.sql',
           'database/tests/perf-concurrency.py','database/tests/concurrency-fixture.php','database/tests/concurrency-router.php']
    hashes={p:hashlib.sha256((ROOT/p).read_bytes()).hexdigest() for p in paths}
    result={'requirement':'NFR-PERF-02','all_passed':False,'started_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
            'source_sha256':hashes,'production_accessed':False,'synthetic_voters':total,'configured_php_workers':args.workers,
            'scope':'Controlled multi-process PHP HTTP test with shared isolated MariaDB; not a production-capacity or latency SLA certification',
            'waves':[],'checks':[]}
    requests=[]; snapshots=[]; db=web=None;port=None
    folder=Path(tempfile.mkdtemp(prefix='coopvote-db-tests-perf-',dir='/tmp'))
    env={k:v for k,v in os.environ.items() if not k.startswith(('COOPVOTE_','PHP_CLI_SERVER_WORKERS'))}
    config=folder/'settings.php';sock=folder/'mysql.sock';trace=folder/'request-trace.jsonl';php_errors=folder/'php-errors.log'
    config.write_text("<?php return ['environment'=>'test','db_host'=>'127.0.0.1','db_socket'=>'"+str(sock)+"','db_name'=>'coopvote_test','db_user'=>'root','db_password'=>''];")
    config.chmod(0o600)
    env.update(COOPVOTE_ENV='test',COOPVOTE_MAIL='0',COOPVOTE_CONFIG_FILE=str(config),COOPVOTE_INTEGRITY_KEY=secrets.token_hex(32),
               COOPVOTE_CONCURRENCY_TRACE=str(trace),PHP_CLI_SERVER_WORKERS=str(args.workers))
    (folder/'data').mkdir(mode=0o700);(folder/'sessions').mkdir(mode=0o700)
    db_log=(out/'DATABASE-SERVER.log').open('w');web_log=(out/'HTTP-SERVER.log').open('w');log=(out/'TEST.log').open('w')

    def note(message): print(message,flush=True);log.write(message+'\n');log.flush()
    def check(name,ok,detail=None):
        result['checks'].append({'name':name,'pass':bool(ok),'detail':detail})
        if not ok: raise AssertionError(name+': '+str(detail))
    def fixture(mode,*values):
        run=subprocess.run(['php',str(HERE/'concurrency-fixture.php'),mode,*map(str,values)],env=env,text=True,capture_output=True,check=True)
        return json.loads(run.stdout)
    def events():
        return [json.loads(line) for line in trace.read_text().splitlines() if line.strip()] if trace.exists() else []

    try:
        db=subprocess.Popen(['/usr/sbin/mariadbd','--no-defaults','--datadir='+str(folder/'data'),'--socket='+str(sock),
            '--pid-file='+str(folder/'db.pid'),'--skip-networking','--skip-grant-tables','--skip-log-bin',
            '--innodb-buffer-pool-size=64M','--max-connections=64'],stdout=db_log,stderr=db_log)
        mysql=['mariadb','--no-defaults','--socket='+str(sock),'--user=root','-e','SELECT 1']
        for _ in range(150):
            if subprocess.run(mysql,capture_output=True).returncode==0: break
            if db.poll() is not None: raise RuntimeError('Private database stopped during startup')
            time.sleep(.1)
        else: raise RuntimeError('Private database readiness timeout')
        subprocess.run(['php',str(HERE/'seed.php')],env=env,check=True,capture_output=True)
        fixture('seed',total)
        with socket.socket() as probe: probe.bind(('127.0.0.1',0));port=probe.getsockname()[1]
        base=f'http://127.0.0.1:{port}/api/index.php'
        web=subprocess.Popen(['php','-d','session.save_path='+str(folder/'sessions'),'-d','error_log='+str(php_errors),
                              '-S',f'127.0.0.1:{port}','-t',str(ROOT),str(HERE/'concurrency-router.php')],
                             env=env,stdout=web_log,stderr=web_log,start_new_session=True)
        for _ in range(100):
            if open_port(port): break
            if web.poll() is not None: raise RuntimeError('HTTP workers stopped during startup')
            time.sleep(.1)
        else: raise RuntimeError('HTTP readiness timeout')
        result['php_version']=subprocess.run(['php','-r','echo PHP_VERSION;'],text=True,capture_output=True,check=True).stdout

        def send_http(client,route,data,headers=None):
            req=urllib.request.Request(base+'?r='+route,data=json.dumps(data).encode(),method='POST',
                headers={'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest',**(headers or {})})
            try: response=client.open(req,timeout=30)
            except urllib.error.HTTPError as error: response=error
            return response.status,json.loads(response.read())

        # Different cookie jars/session IDs for the same voter avoid session-lock serialization.
        def login(pair):
            i,variant=pair
            client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            status,body=send_http(client,'auth/login',{'username':'concurrent'+str(i),'password':'ConcurrentTest9!'})
            if status!=200: raise AssertionError('Synthetic login failed: '+str(status))
            return pair,client
        with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
            clients=dict(pool.map(login,[(i,v) for i in range(total) for v in [0,1]]))
        note(f'Authenticated {total} synthetic voters with independent sessions; voting tests starting.')

        def selections(eid,i,variant):
            choices=[]
            for pos in range(2):
                abstain=i%10==0 or (pos==1 and i%5==0)
                choices.append({'positionId':eid*10+pos,'candidateId':None,'abstain':True} if abstain else
                               {'positionId':eid*10+pos,'candidateId':eid*10+pos*2+(i+variant+pos)%2})
            return choices

        accepted={1000:{},1001:{}}
        expected_tallies={eid:collections.Counter({eid*10+c:0 for c in range(4)}) for eid in accepted}
        def verify_database(label):
            snap=fixture('snapshot');snap['wave']=label;snapshots.append(snap)
            result['database_version']=snap['database_version']
            for eid,winners in accepted.items():
                actual=snap['elections'][str(eid)]
                ballots={int(b['user_id']):b for b in actual['ballots']}
                check(label+f': election {eid} accepted voters equal recorded ballots',set(ballots)=={20000+i for i in winners})
                check(label+f': election {eid} each accepted receipt matches its ballot',all(
                    b['has_voted']==1 and b['receipt_code']==winners[uid-20000]['receipt'] for uid,b in ballots.items()))
                check(label+f': election {eid} no duplicate ballot/receipt',actual['duplicate_ballot_groups']==0 and
                    len({b['receipt_code'] for b in ballots.values()})==len(ballots))
                tallies={int(t['candidate_id']):int(t['votes']) for t in actual['tallies']}
                check(label+f': election {eid} exact candidate totals match accepted payloads',tallies==dict(expected_tallies[eid]),{'expected':dict(expected_tallies[eid]),'actual':tallies})
                count=sum(expected_tallies[eid].values())
                check(label+f': election {eid} exact selection count and unique vote IDs',actual['counts']['vote_rows']==count and actual['counts']['unique_vote_ids']==count)
                check(label+f': election {eid} every vote signature and signed count valid',actual['integrity']['status']=='passed' and actual['integrity']['checkedVotes']==count)

        def wave(name,jobs,new_voters):
            before=fixture('metrics');barrier=threading.Barrier(len(jobs)+1,timeout=30)
            def submit(job):
                i,variant,eid,number=job;rid=name+'-'+str(number)
                payload={'electionId':eid,'votes':selections(eid,i,variant)}
                barrier.wait();started=time.monotonic_ns()
                try:
                    status,body=send_http(clients[(i,variant)],'voter/vote',payload,{'X-Concurrency-Request':rid})
                    return {'request':rid,'voter_index':i,'variant':variant,'election':eid,'status':status,'body':body,
                            'client_start_ns':started,'client_end_ns':time.monotonic_ns(),'payload':payload}
                except Exception as error:
                    return {'request':rid,'status':0,'error':type(error).__name__+': '+str(error),'voter_index':i,'election':eid,
                            'client_start_ns':started,'client_end_ns':time.monotonic_ns()}
            with concurrent.futures.ThreadPoolExecutor(max_workers=len(jobs)) as pool:
                futures=[pool.submit(submit,(*job,k)) for k,job in enumerate(jobs)]
                barrier.wait();rows=[f.result() for f in futures]
            requests.extend(rows)
            ids={r['request'] for r in rows}
            for _ in range(100):
                relevant=[e for e in events() if e['request'] in ids]
                if len([e for e in relevant if e['event']=='end'])==len(rows): break
                time.sleep(.05)
            after=fixture('metrics')
            check(name+': all requests reached application and completed',len(relevant)==2*len(rows))
            intervals=[]
            for rid in ids:
                pair=[e for e in relevant if e['request']==rid]
                check(name+': trace pair '+rid,len(pair)==2 and {e['event'] for e in pair}=={'start','end'})
                for e in pair: intervals.append((e['monotonic_ns'],1 if e['event']=='start' else -1))
            active=peak=0
            for _,delta in sorted(intervals): active+=delta;peak=max(peak,active)
            pids=sorted({e['pid'] for e in relevant})
            check(name+': simultaneous server-side execution observed',peak>=2 and len(pids)>=2,{'peak':peak,'worker_pids':pids})
            check(name+': no transport/server errors',all(r['status'] in [201,409] for r in rows))
            check(name+': rejected requests are duplicate submissions',all(r['body'].get('code')=='ALREADY_VOTED' for r in rows if r['status']==409))
            successes=[r for r in rows if r['status']==201]
            check(name+': one accepted request per new eligible voter',len(successes)==len(new_voters) and {r['voter_index'] for r in successes}==set(new_voters))
            for row in successes:
                i=row['voter_index'];eid=row['election']
                check(name+': accepted voter not already recorded '+str(i),i not in accepted[eid])
                choices=row['payload']['votes'];selected=[v['candidateId'] for v in choices if v['candidateId'] is not None]
                check(name+': response matches selection/abstention count '+str(i),row['body']['selections']==len(selected) and row['body']['abstentions']==2-len(selected))
                accepted[eid][i]={'receipt':row['body']['receiptCode'],'request':row['request']}
                expected_tallies[eid].update(selected)
            delta={key:after[key]-before[key] for key in before if key!='Threads_running'}
            for key in ['Com_rollback','Innodb_deadlocks']:
                check(name+': zero '+key, key in delta and delta[key]==0,delta.get(key))
            durations=sorted((r['client_end_ns']-r['client_start_ns'])/1e6 for r in rows)
            summary={'name':name,'simultaneous_client_requests':len(rows),'accepted':len(successes),'expected_duplicate_rejections':len(rows)-len(successes),
                     'max_observed_overlapping_server_requests':peak,'worker_pids':pids,'database_metrics_before':before,'database_metrics_after':after,
                     'database_metrics_delta':delta,'client_latency_ms':{'min':round(durations[0],2),'p50':round(durations[len(durations)//2],2),'p95':round(durations[math.ceil(len(durations)*.95)-1],2),'max':round(durations[-1],2)}}
            result['waves'].append(summary)
            verify_database(name)
            note(f'{name}: {len(rows)} simultaneous submissions; {len(successes)} accepted, {len(rows)-len(successes)} expected duplicates; peak server overlap {peak}; zero rollbacks/deadlocks; totals verified.')

        db_log.flush();db_offset=(out/'DATABASE-SERVER.log').stat().st_size
        wave('distinct',[(i,0,1000) for i in range(n)],range(n))
        wave('duplicate-race',[(i,v,1001) for i in range(n) for v in [0,1]],range(n))
        wave('mixed',[(i,0,1001) for i in range(n//2)]+[(i,0,1001) for i in range(n,total)],range(n,total))
        wave('replay',[(i,0,1001) for i in range(total)],[])
        errors=php_errors.read_text() if php_errors.exists() else ''
        db_log.flush();db_messages=(out/'DATABASE-SERVER.log').read_bytes()[db_offset:].decode(errors='replace')
        (out/'PHP-ERRORS.log').write_text(errors);(out/'DATABASE-DURING-VOTING.log').write_text(db_messages)
        check('No PHP application errors',not errors.strip())
        check('No database error/deadlock/timeout messages during voting',not re.search(r'\[ERROR\]|deadlock|lock wait timeout|crash',db_messages,re.I))
        check('Application source unchanged during test',all(hashlib.sha256((ROOT/p).read_bytes()).hexdigest()==h for p,h in hashes.items()))
        result['totals']={'requests':len(requests),'accepted_ballots':sum(len(v) for v in accepted.values()),
                          'expected_duplicate_rejections':sum(r['status']==409 for r in requests),'unexpected_failures':0,
                          'stored_selections':sum(sum(t.values()) for t in expected_tallies.values()),'transaction_rollbacks':sum(w['database_metrics_delta']['Com_rollback'] for w in result['waves']),
                          'deadlocks':sum(w['database_metrics_delta']['Innodb_deadlocks'] for w in result['waves'])}
        result['all_passed']=True
        note('NFR-PERF-02 PASS for the tested concurrent workload.')
    except Exception as error:
        result['error']=type(error).__name__+': '+str(error);note('FAILED: '+result['error'])
    finally:
        stop(web,group=True);stop(db)
        if trace.exists(): shutil.copy2(trace,out/'SERVER-TRACE.jsonl')
        if php_errors.exists(): shutil.copy2(php_errors,out/'PHP-ERRORS.log')
        result['services_stopped']=(web is None or web.poll() is not None) and (db is None or db.poll() is not None) and (port is None or not open_port(port))
        shutil.rmtree(folder)
        result['temporary_database_removed']=not folder.exists()
        result['completed_utc']=datetime.datetime.now(datetime.timezone.utc).isoformat()
        result['all_passed']=result['all_passed'] and result['services_stopped'] and result['temporary_database_removed']
        (out/'RESULTS.json').write_text(json.dumps(result,indent=2)+'\n')
        (out/'REQUESTS.json').write_text(json.dumps(requests,indent=2)+'\n')
        (out/'DATABASE-CHECKS.json').write_text(json.dumps(snapshots,indent=2)+'\n')
        db_log.close();web_log.close();log.close()
    return 0 if result['all_passed'] else 1

if __name__=='__main__': raise SystemExit(main())

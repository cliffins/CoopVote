#!/usr/bin/env python3
"""NFR26: transfer synthetic elections to isolated Apache/FPM and a second DB server.

Never restores into production. --verify-production performs keyed READ ONLY checks.
Requires the official MariaDB 11.4.12 system-table SQL (checksum pinned below).
"""
import argparse
import datetime
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import ssl
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[2]
SYSTEM_HASH = 'f42d35df1a8062d65ad36362856ad94fe79859670f719cb52e100ba83f0db3c8'


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--system-sql', required=True, type=Path)
    ap.add_argument('--output-dir', required=True, type=Path)
    ap.add_argument('--verify-production', action='store_true')
    args = ap.parse_args()
    assert digest(args.system_sql) == SYSTEM_HASH, 'Unrecognized MariaDB initialization SQL'
    out = args.output_dir.resolve()
    out.mkdir(parents=True, exist_ok=False)
    os.chmod(out, 0o700)
    result = {'started': datetime.datetime.now(ZoneInfo('Asia/Manila')).isoformat(),
              'scope': 'Same-host isolated staging transfer; separate source/destination code, database processes, sockets, datadirs and web processes. Not a second provider or machine.',
              'guide_sha256': digest(ROOT/'INSTALLATION_DEPLOYMENT.md'),
              'system_sql_sha256': SYSTEM_HASH, 'checks': [], 'overall': 'INCOMPLETE'}
    clean_env = {k:v for k,v in os.environ.items() if not k.startswith('COOPVOTE_')}
    clean_env['COOPVOTE_MAIL'] = '0'
    processes, logs = [], []
    comparison_key = secrets.token_bytes(32)
    original_hashes = {str(p.relative_to(ROOT)): digest(p) for p in ROOT.rglob('*')
                       if p.is_file() and p.suffix in ('.php', '.js', '.css', '.sql')
                       and not any(x in p.parts for x in ('logs', 'test-data', '.git'))}

    def run(cmd, **kwargs):
        r = subprocess.run(cmd, capture_output=True, timeout=90, env=kwargs.pop('env', clean_env), **kwargs)
        if r.returncode:
            raise RuntimeError('Command failed: '+str(cmd[0])+' '+r.stderr.decode(errors='replace')[-1600:])
        return r.stdout

    def check(name, ok, evidence):
        result['checks'].append({'name': name, 'pass': bool(ok), 'evidence': evidence})
        print(('PASS ' if ok else 'FAIL ')+name, flush=True)
        assert ok, name

    def production_snapshot():
        r = json.loads(run(['php', str(ROOT/'database/tests/nfr21-production-snapshot.php'), '--read-only'], input=comparison_key))
        assert r['transaction'] == 'READ ONLY' and len(r['fingerprints']) == 10
        return r

    def stop(p):
        if p.poll() is None:
            p.terminate()
            try: p.wait(timeout=10)
            except subprocess.TimeoutExpired: p.kill(); p.wait(timeout=5)

    before = None
    temporary = None
    try:
        if args.verify_production:
            before = production_snapshot()
        temporary = tempfile.TemporaryDirectory(prefix='coopvote-db-tests-nfr26-', dir='/tmp')
        temp = Path(temporary.name)
        result['isolated_directory'] = str(temp)

        def start(cmd, name, env=None):
            log = (temp/(name+'.log')).open('wb'); logs.append(log)
            p = subprocess.Popen(cmd, env=env or clean_env, stdout=log, stderr=log)
            processes.append(p)
            return p

        def ready(probe, proc):
            for _ in range(150):
                if proc.poll() is not None: raise RuntimeError('Isolated service exited')
                try:
                    if probe(): return
                except (OSError, urllib.error.URLError, subprocess.CalledProcessError): pass
                time.sleep(.1)
            raise RuntimeError('Isolated service readiness timeout')

        def port():
            with socket.socket() as s: s.bind(('127.0.0.1',0)); return s.getsockname()[1]

        def mysql(sock, sql, db=None):
            assert str(sock).startswith(str(temp)+'/') and sock.name == 'mysql.sock'
            return run(['mariadb','--no-defaults','--socket='+str(sock),'--user=root','--batch','--skip-column-names']+([db] if db else []), input=sql.encode())

        def database(name):
            home = temp/name; home.mkdir(mode=0o700); data=home/'data'; data.mkdir(mode=0o700)
            sock=home/'mysql.sock'
            cmd=['/usr/sbin/mariadbd','--no-defaults','--datadir='+str(data),'--socket='+str(sock),
                 '--pid-file='+str(home/'server.pid'),'--skip-networking','--skip-log-bin','--innodb-buffer-pool-size=64M']
            p=start(cmd+['--skip-grant-tables'], name+'-bootstrap')
            def ping():
                return subprocess.run(['mariadb','--no-defaults','--socket='+str(sock),'--user=root','-e','SELECT 1'],capture_output=True).returncode==0
            ready(ping,p)
            mysql(sock, 'CREATE DATABASE mysql; USE mysql;\n'+args.system_sql.read_text()+
                  '\nINSERT INTO mysql.global_priv(Host,User,Priv) VALUES (\'localhost\',\'root\',\'{"access":18446744073709551615,"plugin":"mysql_native_password","authentication_string":""}\');\nFLUSH PRIVILEGES;')
            stop(p); p=start(cmd,name+'-database'); ready(ping,p)
            observed=mysql(sock,'SELECT @@datadir, @@socket, @@skip_networking, @@skip_grant_tables, VERSION()').decode().strip().split('\t')
            check(name+' database isolation and enabled grants', Path(observed[0]).resolve()==data.resolve() and observed[1]==str(sock) and observed[2:4]==['1','0'], observed)
            return sock

        source_socket=database('source-db'); target_socket=database('target-db')
        key=secrets.token_hex(32)

        def copy_app(name):
            home=temp/name; home.mkdir(mode=0o700); web=home/'public_html'; web.mkdir(mode=0o750)
            (home/'private').mkdir(mode=0o700)
            for folder in ['admin','api','assets','audit','config','database','includes','tools','vendor','voter']:
                shutil.copytree(ROOT/folder,web/folder,ignore=shutil.ignore_patterns('test-data','__pycache__','security-local.php','integrity-key.php','*.log','*.bak'))
            for p in ROOT.iterdir():
                if p.is_file() and (p.suffix in ('.php','.html') or p.name in ('.htaccess','.user.ini','robots.txt')): shutil.copy2(p,web/p.name)
            # Mail credentials are deployment configuration, never part of the transferred release.
            config=web/'config/config.php'; text=config.read_text()
            text,n=re.subn(r"const MAIL_PASSWORD\s*=\s*'[^']*'", "const MAIL_PASSWORD = ''",text)
            assert n==1
            config.write_text(text)
            (web/'config/integrity-key.php').write_text("<?php return '"+key+"';\n")
            (web/'config/integrity-key.php').chmod(0o600)
            return web

        source=copy_app('source-app'); target=copy_app('target-app')
        manifest={str(p.relative_to(source)):digest(p) for p in source.rglob('*') if p.is_file()}
        check('Transferred release files match', all(digest(target/p)==h for p,h in manifest.items()), {'files':len(manifest),'deployment_secrets_excluded':True})
        result['release_manifest']=manifest
        source_config=temp/'source-settings.php'
        source_config.write_text("<?php return ['environment'=>'test','db_host'=>'127.0.0.1','db_socket'=>'"+str(source_socket)+"','db_name'=>'coopvote_test','db_user'=>'root','db_password'=>''];")
        src_env=dict(clean_env,COOPVOTE_ENV='test',COOPVOTE_CONFIG_FILE=str(source_config),COOPVOTE_INTEGRITY_KEY=key,
                     COOPVOTE_TEST_RESULTS=str(temp/'fixture-results.json'))
        run(['php',str(source/'database/tests/seed.php')],env=src_env)
        run(['php',str(source/'database/tests/nfr19-fixture.php'),'seed'],env=src_env)
        source_port=port(); source_base='http://127.0.0.1:'+str(source_port)
        sessions=temp/'source-sessions'; sessions.mkdir(mode=0o700)
        source_web=start(['php','-d','session.save_path='+str(sessions),'-S','127.0.0.1:'+str(source_port),'-t',str(source)],'source-web',src_env)
        ready(lambda: urllib.request.urlopen(source_base+'/api/index.php',timeout=1).status==200,source_web)

        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self,*a,**kw): return None

        def client(context=None):
            jar=http.cookiejar.CookieJar()
            handlers=[urllib.request.ProxyHandler({}),NoRedirect(),urllib.request.HTTPCookieProcessor(jar)]
            if context: handlers.append(urllib.request.HTTPSHandler(context=context))
            return urllib.request.build_opener(*handlers),jar

        def request(c,base,path,status=200,data=None,method=None):
            req=urllib.request.Request(base+path,data=json.dumps(data).encode() if data is not None else None,
                   method=method,headers={'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'})
            try: r=c.open(req,timeout=20)
            except urllib.error.HTTPError as e: r=e
            body=r.read()
            assert r.status==status, (path,r.status,status,body[:150])
            return body,r.headers

        def api(c,base,route,status=200,data=None,method=None):
            body,_=request(c,base,'/api/index.php?r='+route,status,data,method)
            return json.loads(body)

        admin,_=client(); api(admin,source_base,'auth/login',data={'username':'testadmin','password':'EndpointTest9!'})
        for i in range(7):
            voter,_=client(); api(voter,source_base,'auth/login',data={'username':'privacy'+str(i),'password':'PrivacyTest9!'})
            api(voter,source_base,'voter/ballot&election=900')
            choices=[]
            for p in range(4):
                selected=(p==0 and i<6) or p==1 or (p==2 and i==0)
                choices.append({'positionId':9000+p,'candidateId':9000+p*2+(1 if p==0 and i>=3 else 0)} if selected else {'positionId':9000+p,'candidateId':None,'abstain':True})
            api(voter,source_base,'voter/vote',201,{'electionId':900,'votes':choices})
        api(admin,source_base,'elections/900/close',data={},method='PATCH')
        api(admin,source_base,'elections/900/release',data={},method='PATCH')
        source_results=api(admin,source_base,'results')
        voter,_=client(); api(voter,source_base,'auth/login',data={'username':'privacy0','password':'PrivacyTest9!'})
        source_voter_results=api(voter,source_base,'voter/results')
        source_receipts=api(voter,source_base,'voter/receipts')
        stop(source_web)  # Quiesce synthetic source before the migration snapshot.
        check('Synthetic source election populated and quiesced',True,{'elections':1,'participating_voters':7,'selections':14,'results_released':True})

        snapshot_code=r'''require $argv[1].'/includes/helpers.php'; require $argv[1].'/includes/vote-integrity.php';
        $p=db(); $tables=$p->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN); sort($tables);
        $data=[]; foreach($tables as $table){$rows=$p->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC); $rows=array_map('serialize',$rows);sort($rows,SORT_STRING);$ddl=$p->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$data[$table]=['count'=>count($rows),'sha256'=>hash('sha256',serialize($rows)),'schema_sha256'=>hash('sha256',$ddl)];}
        $tally=$p->query('SELECT election_id,position_id,candidate_id,COUNT(*) AS votes FROM votes GROUP BY election_id,position_id,candidate_id ORDER BY election_id,position_id,candidate_id')->fetchAll();
        $integrity=integrity_verify(900); unset($integrity['checkedAt']);
        echo json_encode(['tables'=>$data,'tallies'=>$tally,'integrity'=>$integrity],JSON_THROW_ON_ERROR);'''

        def snapshot(web,env,php='php'):
            return json.loads(run([php,'-r',snapshot_code,str(web)],env=env))

        source_snapshot=snapshot(source,src_env)
        check('Source vote integrity',source_snapshot['integrity']['status']=='passed' and source_snapshot['integrity']['checkedVotes']==14,source_snapshot['integrity'])
        dump=run(['mariadb-dump','--no-defaults','--socket='+str(source_socket),'--user=root','--single-transaction','--quick','--hex-blob','--routines','--events','--triggers','coopvote_test'])
        result['backup_sha256']=hashlib.sha256(dump).hexdigest()
        mysql(target_socket,'CREATE DATABASE coopvote_stage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
        mysql(target_socket,dump.decode(),'coopvote_stage')
        password=secrets.token_hex(24)
        mysql(target_socket,"CREATE USER 'coopvote_app'@'localhost' IDENTIFIED BY '"+password+"'; GRANT SELECT,INSERT,UPDATE,DELETE ON coopvote_stage.* TO 'coopvote_app'@'localhost';")
        private=target.parent/'private/coopvote-db.php'
        private.write_text("<?php return ['host'=>'127.0.0.1','port'=>3306,'name'=>'coopvote_stage','user'=>'coopvote_app','pass'=>'"+password+"'];")
        private.chmod(0o600)
        (target/'config/security-local.php').write_text("<?php return ['environment'=>'production','db_socket'=>'"+str(target_socket)+"','db_ssl_ca'=>'','db_ssl_cert'=>'','db_ssl_key'=>'','db_encryption_required'=>false];")
        target_snapshot=snapshot(target,clean_env,'php8.4')
        check('All restored table rows, schemas, tallies and signatures match',source_snapshot==target_snapshot,{'tables':len(target_snapshot['tables']),'integrity':target_snapshot['integrity']})
        result['source_snapshot']=source_snapshot; result['restored_snapshot']=target_snapshot
        check('Original synthetic signing key preserved',digest(source/'config/integrity-key.php')==digest(target/'config/integrity-key.php'), 'Matching key transferred separately; no production key used')
        grants=mysql(target_socket,"SHOW GRANTS FOR 'coopvote_app'@'localhost'").decode()
        check('Destination runtime has only scoped CRUD grants','SELECT, INSERT, UPDATE, DELETE ON `coopvote_stage`.*' in grants, 'USAGE plus SELECT, INSERT, UPDATE, DELETE on coopvote_stage only')

        # Standalone services: never edit or reload the hosting provider's services.
        fpm_socket=temp/'fpm.sock'; target_sessions=temp/'target-sessions'; target_sessions.mkdir(mode=0o700)
        fpm=temp/'fpm.conf'
        fpm.write_text(f'''[global]
pid = {temp}/fpm.pid
error_log = {temp}/fpm-error.log
daemonize = no
[nfr26]
listen = {fpm_socket}
pm = ondemand
pm.max_children = 3
pm.process_idle_timeout = 10s
clear_env = yes
env[COOPVOTE_MAIL] = 0
env[COOPVOTE_DEBUG] = 0
env[PATH] = /usr/bin:/bin
php_admin_value[session.save_path] = {target_sessions}
php_admin_value[sys_temp_dir] = {temp}
php_admin_value[display_errors] = Off
php_admin_value[log_errors] = On
php_admin_value[error_log] = {temp}/php-errors.log
''')
        run(['php-fpm8.4','-t','-y',str(fpm)])
        fpm_proc=start(['php-fpm8.4','-F','-y',str(fpm)],'target-fpm'); ready(lambda:fpm_socket.exists(),fpm_proc)
        cert=temp/'certificate.pem'; cert_key=temp/'certificate.key'
        run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost','-addext','subjectAltName=IP:127.0.0.1,DNS:localhost','-keyout',str(cert_key),'-out',str(cert)])
        http_port,https_port=port(),port()
        conf=temp/'apache.conf'; (temp/'mime.types').write_text('text/html html\ntext/css css\napplication/javascript js\nimage/png png\nimage/svg+xml svg\n')
        modules=['mpm_event','authz_core','authz_host','dir','env','rewrite','mime','alias','proxy','proxy_fcgi','ssl','socache_shmcb']
        config=f'ServerRoot "{temp}"\nDefaultRuntimeDir "{temp}"\nPidFile "{temp}/apache.pid"\nServerName localhost\nErrorLog "{temp}/apache-error.log"\n'
        config+=''.join(f'LoadModule {m}_module /usr/lib/apache2/modules/mod_{m}.so\n' for m in modules)
        config+=f'''Listen 127.0.0.1:{http_port}
Listen 127.0.0.1:{https_port}
TypesConfig "{temp}/mime.types"
SSLSessionCache none
<Directory />
Require all denied
</Directory>
<VirtualHost 127.0.0.1:{http_port}>
Redirect permanent / https://127.0.0.1:{https_port}/
</VirtualHost>
<VirtualHost 127.0.0.1:{https_port}>
SSLEngine on
SSLCertificateFile "{cert}"
SSLCertificateKeyFile "{cert_key}"
DocumentRoot "{target}"
DirectoryIndex index.php index.html
<Directory "{target}">
Options -Indexes +FollowSymLinks -MultiViews
AllowOverride All
Require all granted
</Directory>
<DirectoryMatch "^{target}/(config|database|includes|tools|logs|audit|vendor|docs|\\.vscode|\\.git)(/|$)">
Require all denied
</DirectoryMatch>
<Directory "{target}/api/routes">
Require all denied
</Directory>
<FilesMatch "(?i)(^\\.|\\.(sql|log|bak|md|docx|ini)$)">
Require all denied
</FilesMatch>
<FilesMatch "\\.php$">
SetHandler "proxy:unix:{fpm_socket}|fcgi://localhost"
</FilesMatch>
SetEnv COOPVOTE_MAIL 0
SetEnv COOPVOTE_DEBUG 0
</VirtualHost>
'''
        conf.write_text(config)
        run(['apache2','-t','-f',str(conf)])
        apache=start(['apache2','-f',str(conf),'-DFOREGROUND'],'target-apache')
        context=ssl.create_default_context(cafile=str(cert)); base='https://127.0.0.1:'+str(https_port)
        anon,_=client(context)
        ready(lambda:request(anon,base,'/api/index.php')[0],apache)
        result['destination_url']=base
        for p in [conf,fpm]: shutil.copy2(p,out/p.name)
        probe=target/'nfr26-runtime.php'
        probe.write_text("<?php require __DIR__.'/config/database.php'; header('Content-Type: application/json'); echo json_encode(['php'=>PHP_VERSION,'sapi'=>PHP_SAPI,'apache'=>$_SERVER['SERVER_SOFTWARE'],'mail'=>MAIL_ENABLED,'environment'=>DB_SETTINGS['environment'],'database'=>db()->query('SELECT VERSION()')->fetchColumn(),'database_name'=>DB_NAME,'database_user'=>db()->query('SELECT CURRENT_USER()')->fetchColumn()]);")
        try: runtime=json.loads(request(anon,base,'/nfr26-runtime.php')[0])
        finally: probe.unlink()
        check('Destination Apache/PHP-FPM normal deployment runtime',runtime['sapi']=='fpm-fcgi' and runtime['environment']=='production' and not runtime['mail'] and runtime['database_name']=='coopvote_stage' and runtime['database_user']=='coopvote_app@localhost',runtime)
        result['runtime']=runtime
        request(anon,'http://127.0.0.1:'+str(http_port),'/',301)
        body,headers=request(anon,base,'/',302)
        check('HTTPS and documented homepage routing',headers['Location']=='login.php',{'http_redirect':301,'https_root':302,'location':headers['Location'],'certificate_verification':True})
        body,_=request(anon,base,'/login.php'); check('Destination login page',b'CoopVote' in body and b'password' in body,200)
        for path in ['/config/config.php','/database/schema_only.sql','/api/routes/auth.php','/.user.ini','/includes/helpers.php']:
            request(anon,base,path,403)
        check('Apache blocks private source/schema/configuration',True,'5 sensitive paths returned HTTP 403')
        request(anon,base,'/login.html'); request(anon,base,'/api/branding')
        check('Legacy and pretty-URL Apache rewrites',True,'/login.html and /api/branding returned HTTP 200')
        api(anon,base,'auth/me',401); request(anon,base,'/admin/dashboard.php',302)
        dest_admin,admin_jar=client(context); api(dest_admin,base,'auth/login',data={'username':'testadmin','password':'EndpointTest9!'})
        dest_voter,voter_jar=client(context); api(dest_voter,base,'auth/login',data={'username':'privacy0','password':'PrivacyTest9!'})
        check('Restored administrator and voter login; secure session cookies',all(c.secure and c.has_nonstandard_attr('HttpOnly') for jar in [admin_jar,voter_jar] for c in jar) and bool(list(admin_jar)) and bool(list(voter_jar)), 'Both restored credentials accepted; Secure and HttpOnly cookies')
        for c,path in [(dest_admin,'/admin/dashboard.php'),(dest_admin,'/admin/election-management.php'),(dest_admin,'/admin/results.php'),(dest_voter,'/voter/dashboard.php'),(dest_voter,'/voter/election.php?election=900'),(dest_voter,'/voter/results.php')]:
            body,_=request(c,base,path); assert b'id="sidebar"' in body
        check('Restored administrator/voter pages render',True,'6 authenticated PHP pages returned HTTP 200 with application markup')
        check('Restored results and receipts match source',api(dest_admin,base,'results')==source_results and api(dest_voter,base,'voter/results')==source_voter_results and api(dest_voter,base,'voter/receipts')==source_receipts,'Exact parsed JSON equality for administrator results, voter results, and voter receipts')
        for c in [dest_admin,dest_voter]: api(c,base,'auth/logout',data={}); api(c,base,'auth/me',401)
        check('Logout revokes authenticated access',True,'Both roles return HTTP 401 after logout')
        final_snapshot=snapshot(target,clean_env,'php8.4')
        election_tables=['votes','vote_integrity','election_integrity','elections','positions','candidates','ballots','election_reports','election_approval_requests','election_admin_decisions']
        check('Restored election data unchanged after functional checks',all(final_snapshot['tables'][t]==source_snapshot['tables'][t] for t in election_tables) and final_snapshot['integrity']==source_snapshot['integrity'],{'tables':election_tables,'integrity':final_snapshot['integrity']})
        # Negative control: losing the original signing key must fail verification.
        wrong=snapshot(target,dict(clean_env,COOPVOTE_INTEGRITY_KEY=secrets.token_hex(32)),'php8.4')
        check('Missing/mismatched signing-key negative control',wrong['integrity']['status']=='mismatch' and wrong['integrity']['mismatchedVotes']==14,wrong['integrity'])
        check('Synthetic source preserved during transfer',snapshot(source,src_env)==source_snapshot,'Exact source snapshot equality')
        result['overall']='PASS'
    except Exception as e:
        result['overall']='FAIL'; result['error']=str(e)
        print('ERROR '+str(e),flush=True)
    finally:
        for p in reversed(processes): stop(p)
        for log in logs: log.close()
        if temporary:
            temp=Path(temporary.name)
            # Logs contain synthetic data only; retained privately for reproducibility.
            for p in temp.glob('*.log'): shutil.copy2(p,out/p.name)
            temporary.cleanup()
            result['temporary_environment_removed']=not temp.exists()
        result['services_stopped']=all(p.poll() is not None for p in processes)
        if before:
            try:
                after=production_snapshot()
                matches={k:before['fingerprints'][k]==after['fingerprints'][k] for k in before['fingerprints']}
                check('Production election data preserved (READ ONLY comparison)',all(matches.values()),matches)
                result['production_verification']={'transaction':'READ ONLY','table_matches':matches,'before':before['captured_at'],'after':after['captured_at']}
            except Exception:
                result['overall']='FAIL'; result['production_verification']={'error':'Read-only comparison failed or detected concurrent changes'}
        else:
            result['production_verification']='NOT TESTED; no production mutation commands are present in this runner'
            if args.verify_production: result['overall']='FAIL'
        unchanged=all((ROOT/p).is_file() and digest(ROOT/p)==h for p,h in original_hashes.items())
        result['production_source_files_unchanged']=unchanged
        result['production_source_files_checked']=len(original_hashes)
        if not unchanged or not result['services_stopped'] or not result.get('temporary_environment_removed'): result['overall']='FAIL'
        result['finished']=datetime.datetime.now(ZoneInfo('Asia/Manila')).isoformat()
        (out/'NFR26_RESULTS.json').write_text(json.dumps(result,indent=2)+'\n')
        print('NFR26 '+result['overall']+'; evidence: '+str(out),flush=True)
    return 0 if result['overall']=='PASS' else 1


if __name__=='__main__':
    raise SystemExit(main())

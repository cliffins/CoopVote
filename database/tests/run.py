#!/usr/bin/env python3
"""Run synthetic API write tests on a disposable, socket-only MariaDB server."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent

def wait_for(probe, process):
    for _ in range(100):
        if process.poll() is not None:
            raise RuntimeError('Test server stopped before becoming ready.')
        try:
            if probe(): return
        except (OSError, urllib.error.URLError):
            pass
        time.sleep(0.1)
    raise RuntimeError('Test server did not become ready.')

def stop(process):
    if process is not None and process.poll() is None:
        process.terminate()
        try: process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()

def main():
    binary = shutil.which('mariadbd') or '/usr/sbin/mariadbd'
    db = web = None
    with tempfile.TemporaryDirectory(prefix='coopvote-db-tests-', dir='/tmp') as directory:
        temp = Path(directory)
        data = temp / 'data'
        data.mkdir(mode=0o700)
        sessions = temp / 'sessions'
        sessions.mkdir(mode=0o700)
        mysql_socket = temp / 'mysql.sock'
        with socket.socket() as port_probe:
            port_probe.bind(('127.0.0.1', 0))
            port = port_probe.getsockname()[1]
        url = f'http://127.0.0.1:{port}/api/index.php'
        config = temp / 'config.php'
        config.write_text("<?php return ['environment'=>'test','db_host'=>'127.0.0.1',"
                          f"'db_socket'=>'{mysql_socket}',"
                          "'db_name'=>'coopvote_test','db_user'=>'root','db_password'=>'','db_ssl_ca'=>''];\n")
        env = {key:value for key,value in os.environ.items() if not key.startswith('COOPVOTE_')}
        env.update(COOPVOTE_ENV='test', COOPVOTE_CONFIG_FILE=str(config), COOPVOTE_MAIL='0',
                   COOPVOTE_TEST_URL=url, COOPVOTE_TEST_RESULTS=str(temp/'results.json'))
        try:
            with (temp/'mysql-console.log').open('w') as db_log, (temp/'php.log').open('w') as php_log:
                # System tables are needed to exercise routine/event backup and restore.
                system_sql = os.environ.get('COOPVOTE_TEST_SYSTEM_SQL')
                if not system_sql:
                    subprocess.run(['mariadb-install-db', '--no-defaults', f'--datadir={data}',
                        '--auth-root-authentication-method=normal', '--skip-test-db'],
                        stdout=db_log, stderr=db_log, check=True)
                db = subprocess.Popen([binary, '--no-defaults', f'--datadir={data}',
                    f'--socket={mysql_socket}', f'--pid-file={temp / "mysql.pid"}',
                    '--skip-networking', '--skip-grant-tables', '--skip-log-bin',
                    '--innodb-buffer-pool-size=64M', f'--log-error={temp / "mysql.log"}'],
                    stdout=db_log, stderr=db_log)
                wait_for(lambda: mysql_socket.exists(), db)
                if system_sql:
                    subprocess.run(['mariadb', '--no-defaults', f'--socket={mysql_socket}', '--user=root'],
                        input=b'CREATE DATABASE mysql; USE mysql;\n' + Path(system_sql).read_bytes()
                            + b'''\nINSERT INTO mysql.global_priv(Host,User,Priv) VALUES ('localhost','root','{"access":18446744073709551615,"plugin":"mysql_native_password","authentication_string":""}');\n''',
                        stdout=db_log, stderr=db_log, check=True)
                subprocess.run(['mariadb', '--no-defaults', f'--socket={mysql_socket}', '--user=root',
                    '-e', 'FLUSH PRIVILEGES'], stdout=db_log, stderr=db_log, check=True)
                # A server started with skip-grants disables events until restart.
                normal_args = [arg for arg in db.args if arg != '--skip-grant-tables']
                stop(db)
                db = subprocess.Popen(normal_args, stdout=db_log, stderr=db_log)
                wait_for(lambda: mysql_socket.exists(), db)
                subprocess.run(['php', str(HERE/'seed.php')], env=env, cwd=ROOT, check=True)
                subprocess.run(['php', str(HERE/'relational-constraints.php')], env=env, cwd=ROOT, check=True)
                web = subprocess.Popen(['php','-d',f'session.save_path={sessions}','-S',f'127.0.0.1:{port}','-t',str(ROOT),str(HERE/'router.php')],
                                       env=env, stdout=php_log, stderr=php_log)
                wait_for(lambda: urllib.request.urlopen(url,timeout=1).status == 200, web)
                if os.environ.get('COOPVOTE_MOBILE_TEST') == '1':
                    browser_env = dict(env, COOPVOTE_PLAYWRIGHT_MODULE=os.environ.get('COOPVOTE_PLAYWRIGHT_MODULE', 'playwright'))
                    subprocess.run(['node',str(HERE/'mobile-layout-browser.cjs')],env=browser_env,cwd=ROOT,check=True)
                if os.environ.get('COOPVOTE_SIDEBAR_TEST') == '1':
                    browser_env = dict(env, COOPVOTE_PLAYWRIGHT_MODULE=os.environ.get('COOPVOTE_PLAYWRIGHT_MODULE', 'playwright'))
                    subprocess.run(['node',str(HERE/'sidebar-stream-browser.cjs')],env=browser_env,cwd=ROOT,check=True)
                    subprocess.run(['node',str(HERE/'sidebar-browser.cjs')],env=browser_env,cwd=ROOT,check=True)
                if os.environ.get('COOPVOTE_BROWSER_TEST') == '1':
                    browser_env = dict(env, COOPVOTE_PLAYWRIGHT_MODULE=os.environ.get('COOPVOTE_PLAYWRIGHT_MODULE', 'playwright'))
                    subprocess.run(['node',str(HERE/'theme-browser.cjs')],env=browser_env,cwd=ROOT,check=True)
                subprocess.run(['python3',str(HERE/'endpoints.py')],env=env,cwd=ROOT,check=True)
                subprocess.run(['php',str(HERE/'database-checks.php')],env=env,cwd=ROOT,check=True)
                subprocess.run(['python3',str(HERE/'security-backup.py')],env=env,cwd=ROOT,check=True)
                subprocess.run(['python3',str(HERE/'nfr19.py')],env=env,cwd=ROOT,check=True)
                if os.environ.get('COOPVOTE_PASSWORD_TEST') == '1':
                    browser_env = dict(env, COOPVOTE_PLAYWRIGHT_MODULE=os.environ.get('COOPVOTE_PLAYWRIGHT_MODULE', 'playwright'),
                        COOPVOTE_PASSWORD_SCREENSHOT=os.environ.get('COOPVOTE_PASSWORD_SCREENSHOT', ''))
                    subprocess.run(['node',str(HERE/'admin-password-browser.cjs')],env=browser_env,cwd=ROOT,check=True)
                if os.environ.get('COOPVOTE_ELECTION_TEST') == '1':
                    browser_env = dict(env, COOPVOTE_PLAYWRIGHT_MODULE=os.environ.get('COOPVOTE_PLAYWRIGHT_MODULE', 'playwright'),
                        COOPVOTE_ELECTION_SCREENSHOT=os.environ.get('COOPVOTE_ELECTION_SCREENSHOT', ''))
                    subprocess.run(['node',str(HERE/'election-management-browser.cjs')],env=browser_env,cwd=ROOT,check=True)
                if os.environ.get('COOPVOTE_CANDIDATE_TEST') == '1':
                    browser_env = dict(env, COOPVOTE_PLAYWRIGHT_MODULE=os.environ.get('COOPVOTE_PLAYWRIGHT_MODULE', 'playwright'),
                        COOPVOTE_CANDIDATE_SCREENSHOT=os.environ.get('COOPVOTE_CANDIDATE_SCREENSHOT', ''))
                    subprocess.run(['node',str(HERE/'candidate-management-browser.cjs')],env=browser_env,cwd=ROOT,check=True)
                if os.environ.get('COOPVOTE_DASHBOARD_TEST') == '1':
                    # The account overview needs its own fresh synthetic account fixture.
                    overview_out = temp / 'overview-checks'
                    subprocess.run(['python3',str(HERE/'management-layout-browser.py'),'--suite','overview',
                        '--output-dir',str(overview_out),'--playwright-module',os.environ.get('COOPVOTE_PLAYWRIGHT_MODULE','playwright')],env=env,cwd=ROOT,check=True)
                    if os.environ.get('COOPVOTE_DASHBOARD_SCREENSHOT'):
                        shutil.copy2(overview_out/'admin-overview-1280.png',os.environ['COOPVOTE_DASHBOARD_SCREENSHOT'])
                subprocess.run(['node',str(HERE/'result-receipts.cjs')],env=env,cwd=ROOT,check=True)
                if os.environ.get('COOPVOTE_SECURITY_BROWSER_TEST') == '1':
                    browser_env = dict(env, COOPVOTE_PLAYWRIGHT_MODULE=os.environ.get('COOPVOTE_PLAYWRIGHT_MODULE','playwright'),
                        COOPVOTE_SECURITY_SCREENSHOT=os.environ.get('COOPVOTE_SECURITY_SCREENSHOT',''))
                    subprocess.run(['node',str(HERE/'security-backup-browser.cjs')],env=browser_env,cwd=ROOT,check=True)
                stop(web)
                # A failed connection must be one safe JSON response, even with DEBUG enabled.
                failure_env = dict(env, COOPVOTE_DB_SOCKET=str(temp/'missing.sock'), COOPVOTE_DEBUG='1')
                web = subprocess.Popen(['php','-d',f'session.save_path={sessions}','-S',f'127.0.0.1:{port}','-t',str(ROOT)],
                                       env=failure_env, stdout=php_log, stderr=php_log)
                wait_for(lambda: urllib.request.urlopen(url,timeout=1).status == 200, web)
                try:
                    urllib.request.urlopen(url+'?r=branding',timeout=5)
                    raise AssertionError('Expected unavailable database response')
                except urllib.error.HTTPError as error:
                    payload=json.load(error)
                    assert error.code==500 and payload['code']=='DB_CONNECTION_FAILED'
                    assert payload['detail'] is None and not payload['ok']
                    assert directory not in json.dumps(payload)
                print('Database outage HTTP response: PASS (safe JSON; no secret/path detail)')
        except Exception:
            for name in ['mysql-console.log','mysql.log','php.log']:
                path=temp/name
                if path.exists(): print(path.read_text()[-4000:])
            raise
        finally:
            stop(web)
            stop(db)

if __name__=='__main__':
    main()

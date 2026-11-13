#!/usr/bin/env python3
"""Verify the administrator password form using a disposable socket-only DB."""
import argparse
import contextlib
import os
from pathlib import Path
import secrets
import socket
import subprocess
import tempfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[2]

def stop(process):
    if process.poll() is None:
        process.terminate()
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir', required=True, type=Path)
    parser.add_argument('--playwright-module', required=True)
    args = parser.parse_args()
    out = args.output_dir.resolve()
    out.mkdir(parents=True, exist_ok=False)
    env = {k:v for k,v in os.environ.items() if not k.startswith('COOPVOTE_')}
    env.update(COOPVOTE_ENV='test', COOPVOTE_MAIL='0', COOPVOTE_INTEGRITY_KEY=secrets.token_hex(32),
               COOPVOTE_PLAYWRIGHT_MODULE=args.playwright_module, COOPVOTE_PASSWORD_SCREENSHOT=str(out/'password.png'))
    with tempfile.TemporaryDirectory(prefix='coopvote-db-tests-password-', dir='/tmp') as folder, contextlib.ExitStack() as stack:
        temp = Path(folder)
        (temp/'data').mkdir(mode=0o700)
        (temp/'sessions').mkdir(mode=0o700)
        sock = temp/'mysql.sock'
        config = temp/'config.php'
        config.write_text("<?php return ['environment'=>'test','db_socket'=>'"+str(sock)+"','db_name'=>'coopvote_test','db_user'=>'root','db_password'=>''];")
        env['COOPVOTE_CONFIG_FILE'] = str(config)
        log = stack.enter_context((out/'services.log').open('w'))
        db = subprocess.Popen(['/usr/sbin/mariadbd','--no-defaults','--datadir='+str(temp/'data'),
            '--socket='+str(sock),'--pid-file='+str(temp/'mysql.pid'),'--skip-networking',
            '--skip-grant-tables','--skip-log-bin','--innodb-buffer-pool-size=64M'],stdout=log,stderr=log)
        stack.callback(stop, db)
        for _ in range(600):
            if db.poll() is not None:
                raise RuntimeError('Disposable database stopped during startup.')
            if sock.exists() and subprocess.run(['mariadb','--no-defaults','--socket='+str(sock),'-uroot','-e','SELECT 1'],capture_output=True).returncode == 0:
                break
            time.sleep(.1)
        else:
            raise RuntimeError('Disposable database readiness timeout.')
        subprocess.run(['php',str(ROOT/'database/tests/seed.php')],env=env,cwd=ROOT,check=True)
        with socket.socket() as probe:
            probe.bind(('127.0.0.1',0))
            port = probe.getsockname()[1]
        url = f'http://127.0.0.1:{port}/api/index.php'
        env['COOPVOTE_TEST_URL'] = url
        web = subprocess.Popen(['php','-d','session.save_path='+str(temp/'sessions'),'-S',f'127.0.0.1:{port}',
            '-t',str(ROOT),str(ROOT/'database/tests/router.php')],env=env,stdout=log,stderr=log)
        stack.callback(stop, web)
        for _ in range(100):
            try:
                if urllib.request.urlopen(url,timeout=1).status == 200:
                    break
            except OSError:
                pass
            time.sleep(.1)
        else:
            raise RuntimeError('Disposable web server readiness timeout.')
        result = subprocess.run(['node',str(ROOT/'database/tests/admin-password-browser.cjs')],env=env,cwd=ROOT,capture_output=True,text=True,timeout=180)
        (out/'checks.txt').write_text(result.stdout+result.stderr)
        print(result.stdout+result.stderr, end='')
        result.check_returncode()
    print('Disposable database removed; test services stopped.')

if __name__ == '__main__':
    main()

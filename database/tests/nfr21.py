#!/usr/bin/env python3
"""Run NFR21 on a disposable server; optionally compare production read-only."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
import time
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir', required=True)
    parser.add_argument('--verify-production', action='store_true',
                        help='Compare production rows before/after using READ ONLY transactions; no row data exported.')
    args = parser.parse_args()
    output = Path(args.output_dir).resolve()
    output.mkdir(parents=True, exist_ok=True)
    for name in ('NFR21_RESULTS.json', 'NFR21_TEST_LOG.txt'):
        if (output / name).exists():
            raise SystemExit('Choose a new evidence directory; existing results are not overwritten.')
    env = {key: value for key, value in os.environ.items() if not key.startswith('COOPVOTE_')}
    env['COOPVOTE_MAIL'] = '0'
    started = datetime.datetime.now(ZoneInfo('Asia/Manila')).isoformat()
    tracked = [ROOT / p for p in ['includes/vote-integrity.php', 'includes/ballot-privacy.php',
               'database/schema_only.sql', 'database/tests/nfr21.php',
               'database/tests/nfr21.py', 'database/tests/nfr21-production-snapshot.php']]
    hashes = {str(p.relative_to(ROOT)): hashlib.sha256(p.read_bytes()).hexdigest() for p in tracked}
    comparison_key = secrets.token_bytes(32)

    def snapshot():
        run = subprocess.run(['php', str(HERE / 'nfr21-production-snapshot.php'), '--read-only'],
                             input=comparison_key, env=env, cwd=ROOT, capture_output=True, timeout=120)
        if run.returncode:
            return None
        data = json.loads(run.stdout)
        return data if data.get('transaction') == 'READ ONLY' and len(data.get('fingerprints', {})) == 10 else None

    before = after = None
    if args.verify_production:
        try:
            before = snapshot()
        except Exception:
            before = None
    cases = []
    runtime = {}
    execution_error = None
    server_stopped = False
    fixture_removed = False
    try:
        with tempfile.TemporaryDirectory(prefix='coopvote-db-tests-nfr21-', dir='/tmp') as directory:
            temp = Path(directory)
            data = temp / 'data'
            data.mkdir(mode=0o700)
            (temp / 'nfr21.marker').write_text('Disposable NFR21 fixture only\n')
            socket = temp / 'mysql.sock'
            config = temp / 'config.php'
            config.write_text("<?php return ['environment'=>'test','db_host'=>'127.0.0.1',"
                              f"'db_socket'=>'{socket}','db_name'=>'coopvote_test',"
                              "'db_user'=>'root','db_password'=>'','db_ssl_ca'=>''];\n")
            config.chmod(0o600)
            test_env = dict(env, COOPVOTE_ENV='test', COOPVOTE_CONFIG_FILE=str(config),
                            COOPVOTE_NFR21_FIXTURE=str(temp), COOPVOTE_INTEGRITY_KEY=secrets.token_hex(32))
            # No network listener, no existing datadir, no service restart.
            # Grant checks are disabled only inside this private, disposable server.
            with (temp / 'server.log').open('w') as server_log:
                server = subprocess.Popen(['/usr/sbin/mariadbd', '--no-defaults', f'--datadir={data}',
                         f'--socket={socket}', f'--pid-file={temp / "server.pid"}',
                         '--skip-networking', '--skip-grant-tables', '--skip-log-bin',
                         '--innodb-buffer-pool-size=64M'], stdout=server_log, stderr=server_log)
                try:
                    ready = False
                    for _ in range(150):
                        if server.poll() is not None:
                            break
                        probe = subprocess.run(['mariadb', '--no-defaults', f'--socket={socket}',
                                                '--user=root', '-e', 'SELECT 1'], capture_output=True)
                        if probe.returncode == 0:
                            ready = True
                            break
                        time.sleep(.1)
                    if not ready:
                        raise RuntimeError('Disposable database did not become ready')
                    seeded = subprocess.run(['php', str(HERE / 'seed.php')], env=test_env,
                                            cwd=ROOT, capture_output=True, timeout=60)
                    if seeded.returncode:
                        raise RuntimeError('Disposable fixture initialization failed')
                    run = subprocess.run(['php', str(HERE / 'nfr21.php')], env=test_env,
                                         cwd=ROOT, capture_output=True, timeout=90)
                    result = json.loads(run.stdout)
                    cases = result.get('cases', [])
                    runtime = {key: result[key] for key in ('php_version', 'database_version') if key in result}
                    if run.returncode or result.get('execution_error'):
                        execution_error = 'Test execution failed or an assertion failed; see recorded case statuses.'
                finally:
                    if server.poll() is None:
                        server.terminate()
                        try:
                            server.wait(timeout=15)
                        except subprocess.TimeoutExpired:
                            server.kill()
                            server.wait(timeout=10)
                    server_stopped = server.poll() is not None
        fixture_removed = not Path(directory).exists()
    except Exception:
        execution_error = 'Disposable execution incomplete; no unexecuted case is marked PASS.'
        if 'directory' in locals():
            fixture_removed = not Path(directory).exists()
    finally:
        if args.verify_production:
            try:
                after = snapshot()
            except Exception:
                after = None
    comparisons = {}
    if before is not None and after is not None:
        comparisons = {table: value == after['fingerprints'].get(table)
                       for table, value in before['fingerprints'].items()}
        production_result = 'PASS' if all(comparisons.values()) else 'FAIL'
        actual = {'transaction': 'READ ONLY', 'tables_compared': len(comparisons),
                  'all_rows_unchanged': all(comparisons.values())}
    else:
        production_result = 'NOT TESTED'
        actual = {'reason': 'Production comparison was not requested or one read-only snapshot was unavailable.'}
    cases.append({'id': 'NFR21-04', 'name': 'Production votes and election data unchanged',
                  'expected': {'transaction': 'READ ONLY', 'tables_compared': 10, 'all_rows_unchanged': True},
                  'actual': actual, 'result': production_result})
    recorded = {case['id'] for case in cases}
    for case_id in ['NFR21-01', 'NFR21-02', 'NFR21-02-CONTROL', 'NFR21-03', 'NFR21-03-CONTROL']:
        if case_id not in recorded:
            cases.append({'id': case_id, 'name': 'Unexecuted integrity case', 'expected': 'Scenario completes',
                          'actual': 'Execution stopped before this case', 'result': 'NOT TESTED'})
    changed = [name for name, value in hashes.items() if hashlib.sha256((ROOT / name).read_bytes()).hexdigest() != value]
    complete = (not execution_error and all(c['result'] == 'PASS' for c in cases)
                and server_stopped and fixture_removed and not changed)
    final = {'scope': 'NFR21 requested scenarios only; not full-system regression certification',
             'started_at': started, 'finished_at': datetime.datetime.now(ZoneInfo('Asia/Manila')).isoformat(),
             'status': 'PASS' if complete else 'INCOMPLETE OR FAILED', 'runtime': runtime,
             'cases': cases, 'production_table_equality': comparisons,
             'production_snapshots': {'before': before.get('captured_at') if before else None,
                                      'after': after.get('captured_at') if after else None},
             'cleanup': {'temporary_server_stopped': server_stopped, 'temporary_database_removed': fixture_removed},
             'source_sha256': hashes, 'changed_source_files': changed,
             'execution_error': execution_error}
    (output / 'NFR21_RESULTS.json').write_text(json.dumps(final, indent=2) + '\n')
    lines = ['NFR21 — Stored Vote Integrity Verification', f'Start: {started}',
             f'End: {final["finished_at"]}', 'Scope: requested NFR21 scenarios only', '']
    for case in cases:
        lines.extend([f'{case["id"]}: {case["name"]}',
                      'EXPECTED: ' + json.dumps(case['expected'], sort_keys=True),
                      'ACTUAL:   ' + json.dumps(case['actual'], sort_keys=True),
                      'RESULT: ' + case['result'], ''])
    lines.extend(['Production equality: ' + json.dumps(comparisons, sort_keys=True),
                  'Cleanup: ' + json.dumps(final['cleanup']),
                  'Changed tracked source files: ' + str(len(changed)),
                  'NFR21 scoped result: ' + final['status'],
                  'No keys, passwords, vote IDs, receipts, row contents, or voter information are included.'])
    (output / 'NFR21_TEST_LOG.txt').write_text('\n'.join(lines) + '\n')
    for case in cases:
        print(case['id'] + ': ' + case['result'])
    print('NFR21 scoped result: ' + final['status'])
    return 0 if complete else 1


if __name__ == '__main__':
    raise SystemExit(main())

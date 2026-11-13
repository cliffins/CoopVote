"""Security filters and real SQL backup/restore against the disposable fixture only."""
import csv, http.cookiejar, io, json, os, subprocess, urllib.error, urllib.parse, urllib.request
from pathlib import Path
BASE = os.environ['COOPVOTE_TEST_URL']
assert urllib.parse.urlparse(BASE).hostname == '127.0.0.1'
HERE = Path(__file__).resolve().parent
def fixture(mode, *args):
    subprocess.run(['php', str(HERE/'security-backup-fixture.php'), mode, *args], check=True)
def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks = 0
def request(who, route, method='GET', data=None, query=None, status=200, raw=False):
    global checks
    url = BASE+'?'+urllib.parse.urlencode({'r':route, **(query or {})})
    headers = {'Content-Type':'application/json'} if data is not None else {}
    req = urllib.request.Request(url, data=json.dumps(data).encode() if data is not None else None, headers=headers, method=method)
    try: res=who.open(req,timeout=140)
    except urllib.error.HTTPError as err: res=err
    body=res.read()
    assert res.status == status, (route, res.status, body[:250])
    checks += 1
    return (body,res.headers) if raw else json.loads(body)
anon, admin, voter = client(),client(),client()
request(anon,'security/backup','POST',{},status=401)
for who,username in [(admin,'testadmin'),(voter,'testvoter')]:
    request(who,'auth/login','POST',{'username':username,'password':'ChangedTest9!' if who is voter else 'EndpointTest9!'})
request(voter,'security/backup','POST',{},status=403)
request(voter,'security',status=403)
request(voter,'security/logs/export',status=403)
request(admin,'security/backup',status=405)
request(admin,'security/backup','POST',status=415)
fixture('seed')
day={'from':'2020-02-21','to':'2020-02-21'}
logs=request(admin,'security',query=day)
assert logs['logPagination']['total']==3
assert {l['event'] for l in logs['logs']}=={'Start boundary','End boundary 100%_ complete','=1+1'}
assert any(str(a['id'])=='9001' for a in logs['logOptions']['actors'])
assert not any(a.startswith(('theme.','branding.')) for a in logs['logOptions']['actions'])
filtered=request(admin,'security',query={**day,'actor':'9001','action':'candidate.created'})
assert filtered['logPagination']['total']==2 and all(l['actor']=='Other Administrator' for l in filtered['logs'])
assert request(admin,'security',query={**day,'q':'%_'})['logPagination']['total']==1
assert request(admin,'security',query={**day,'q':"' OR 1=1 --"})['logPagination']['total']==0
assert request(admin,'security',query={**day,'actor':'2'})['logPagination']['total']==0
archive={'from':'2020-02-20','to':'2020-02-20','action':'candidate.updated'}
first=request(admin,'security',query=archive)
last=request(admin,'security',query={**archive,'page':'999'})
assert first['logPagination']=={'total':525,'page':1,'pages':11,'perPage':50} and len(first['logs'])==50
assert last['logPagination']['page']==11 and len(last['logs'])==25
assert not ({l['id'] for l in first['logs']} & {l['id'] for l in last['logs']})
for invalid in [{'from':'2020-02-30'},{'from':'2021-01-01','to':'2020-01-01'},{'actor':'-1'},{'action':'x\" OR 1=1'},{'q':'x'*151},{'page':'0'},{'page[]':'1'},{'from[]':'2020-01-01'}]:
    request(admin,'security',query=invalid,status=422)
    if 'page' not in invalid and 'page[]' not in invalid: request(admin,'security/logs/export',query=invalid,status=422)
body,headers=request(admin,'security/logs/export',query=archive,raw=True)
assert 'text/csv' in headers['Content-Type'] and 'no-store' in headers['Cache-Control']
rows=list(csv.reader(io.StringIO(body.decode('utf-8-sig'))))
assert len(rows)==526 and all(row[3]=='candidate.updated' for row in rows[1:])
body,_=request(admin,'security/logs/export',query={**day,'actor':'9001'},raw=True)
rows=list(csv.reader(io.StringIO(body.decode('utf-8-sig'))))
assert len(rows)==3 and any(row[4]=="'=1+1" for row in rows[1:])
fixture('snapshot')
blocked=request(admin,'security/backup','POST',{},status=409)
assert blocked['code']=='BALLOT_PRIVACY_BACKUP_RESTRICTED'
path=Path(os.environ['COOPVOTE_TEST_RESULTS']).parent/'restore.sql'
fixture('backup-cli',str(path))
fixture('restore',str(path))
fixture('verify')
print(f'Security filters and restricted web backup: {checks} HTTP checks PASS')

"""Anonymity regression tests. Only runs against the disposable loopback fixture."""
import argparse, csv, http.cookiejar, io, json, os, subprocess, urllib.error, urllib.parse, urllib.request
from pathlib import Path
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--application-only',action='store_true',help='Check application disclosure controls without exercising the separate backup/migration tooling')
args=parser.parse_args()
BASE=os.environ['COOPVOTE_TEST_URL']; HERE=Path(__file__).resolve().parent
assert urllib.parse.urlparse(BASE).hostname=='127.0.0.1'
checks=0
def fixture(mode):subprocess.run(['php',str(HERE/'nfr19-fixture.php'),mode],check=True)
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(who,route,method='GET',data=None,status=200,query=None,raw=False):
 global checks
 req=urllib.request.Request(BASE+'?'+urllib.parse.urlencode({'r':route,**(query or {})}),method=method,data=json.dumps(data).encode() if data is not None else None,headers={'Content-Type':'application/json'})
 try:res=who.open(req,timeout=25)
 except urllib.error.HTTPError as e:res=e
 body=res.read();assert res.status==status,(route,res.status,status,body[:220]);checks+=1
 return body if raw else json.loads(body)
admin=client();request(admin,'auth/login','POST',{'username':'testadmin','password':'EndpointTest9!'})
fixture('seed');voters=[]
def hidden():
 candidates=request(admin,'candidates',query={'election':900})
 assert all(c['votes'] is None for c in candidates)
 elections=request(admin,'elections');e=next(e for e in elections if e['id']==900)
 assert all(p['topVotes'] is None and p['maxVotes'] is None for p in e['positionList'])
 e=next(e for e in request(admin,'results')['elections'] if e['id']==900);assert e['positions']==[]
 report=request(admin,'reports',query={'election':900})['selected'];assert report['candidateResults']==[] and report['positionBreakdown']==[]
 # Owner-approved policy: only admin live monitoring exposes aggregate candidate totals.
 # Individual voter activity stays absent; a closed election has no live tally.
 monitor=request(admin,'monitor',query={'election':900})
 assert not monitor.get('recentVotes') and 'activityFeed' not in monitor
 if monitor['election']:
  assert monitor['election']['id']==900
  for position in monitor['candidateTally']:
   for candidate in position['candidates']:
    assert set(candidate)=={'id','name','votes','status'} and isinstance(candidate['votes'],int)
 else: assert monitor['candidateTally']==[]
 for voter in voters: request(voter,'monitor',query={'election':900},status=403)
 dashboard=request(admin,'dashboard');assert dashboard['positionVotes']==[] and dashboard['recentVoters']==[]
 overview=request(admin,'operations/overview',query={'election':900});assert 'checkedVotes' not in overview['integrity']
 for fmt in ['json','html','csv']:request(admin,'operations/report',query={'election':900,'format':fmt},status=409)
 request(admin,'results/export',query={'election':900},status=409)
 export=list(csv.reader(io.StringIO(request(admin,'candidates/export',query={'election':900},raw=True).decode('utf-8-sig'))))
 assert all(row[-2]=='' for row in export[1:]),'CSV candidate counts withheld'
hidden()
for i in range(7):
 voter=client();voters.append(voter);request(voter,'auth/login','POST',{'username':'privacy'+str(i),'password':'PrivacyTest9!'})
 request(voter,'voter/ballot',query={'election':900})
 choices=[]
 for p in range(4):
  chosen=(p==0 and i<6) or p==1 or (p==2 and i==0)
  # Seven participants: six choose balanced candidates, all seven choose same unanimous candidate, one sparse vote.
  if chosen: choices.append({'positionId':9000+p,'candidateId':9000+p*2+(1 if p==0 and i>=3 else 0)})
  else:choices.append({'positionId':9000+p,'candidateId':None,'abstain':True})
 payload={'electionId':900,'votes':choices};receipt=request(voter,'voter/vote','POST',payload,status=201)
 assert 'vote_id' not in receipt and 'voteId' not in receipt and receipt['receiptCode']
 request(voter,'voter/vote','POST',payload,status=409)
 if i==0: hidden()
hidden()
# 6 balanced + 7 unanimous + 1 sparse = 14 selections, not 13.
fixture('verify-new')
request(admin,'security/backup','POST',{},status=409)
request(admin,'elections/900/close','PATCH',{})
hidden()
request(admin,'elections/900/release','PATCH',{})
results=next(e for e in request(admin,'results')['elections'] if e['id']==900)
assert len(results['positions'])==1 and results['positions'][0]['name']=='Balanced'
assert sorted(c['votes'] for c in results['positions'][0]['candidates'])==[3,3]
visible=request(admin,'candidates',query={'election':900})
assert all(c['votes'] is None for c in visible if c['position']!='Balanced')
# Direct CSV downloads must enforce the same post-release disclosure limits.
export=list(csv.DictReader(io.StringIO(request(admin,'results/export',query={'election':900},raw=True).decode('utf-8-sig'))))
assert len(export)==2 and all(row['Position']=='Balanced' for row in export)
assert sorted(int(row['Votes']) for row in export)==[3,3]
candidates=list(csv.DictReader(io.StringIO(request(admin,'candidates/export',query={'election':900},raw=True).decode('utf-8-sig'))))
assert len(candidates)==8
assert all(row['Votes']=='' for row in candidates if row['Position']!='Balanced')
assert sorted(int(row['Votes']) for row in candidates if row['Position']=='Balanced')==[3,3]
print('Released CSV disclosure: results contain only Balanced 3/3; all six suppressed candidate counts blank PASS')
for voter in voters:
 result=next(e for e in request(voter,'voter/results')['elections'] if e['id']==900)
 assert len(result['positions'])==1 and result['positions'][0]['name']=='Balanced'
 receipt=request(voter,'voter/receipts')['receipts']
 assert len(receipt)==1 and all('candidateId' not in r and 'selections' not in r for r in receipt)
for fmt in ['json','html','csv']:
 report=request(admin,'operations/report',query={'election':900,'format':fmt},raw=fmt!='json')
 if fmt=='json':assert len(report['positions'])==1 and report['positions'][0]['name']=='Balanced' and 'checkedVotes' not in report['integrity']
 else: assert b'Unanimous' not in report and b'Sparse' not in report
request(admin,'elections/900/unrelease','PATCH',{})
hidden()
import datetime
end=(datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8)))+datetime.timedelta(days=2)).strftime('%Y-%m-%dT%H:%M')
reopen=request(admin,'elections/900/reopen','PATCH',{'endDate':end},status=409)
# Reopening with participation is permitted by policy; this request has no fresh approval.
# Protection against comparisons across released voting periods is no longer claimed.
assert reopen['code']=='APPROVAL_REQUIRED'
request(admin,'elections/900/release','PATCH',{})
# Withdrawing a zero-vote vs positive-vote candidate yields identical generic behavior.
one=request(admin,'candidates/9000','DELETE',{})
zero=request(admin,'candidates/9007','DELETE',{})
assert one==zero
results=next(e for e in request(admin,'results')['elections'] if e['id']==900)
assert sorted(c['votes'] for c in results['positions'][0]['candidates'])==[3,3],'Withdrawn candidate tally is preserved'
fixture('seed-abstention')
request(voters[0],'voter/ballot',query={'election':901})
request(voters[0],'voter/vote','POST',{'electionId':901,'votes':[{'positionId':9010,'candidateId':None,'abstain':True}]},status=201)
request(admin,'elections/901','DELETE',{},status=409)
request(admin,'elections/901/close','PATCH',{})
request(admin,'elections/901/release','PATCH',{},status=409)
request(admin,'elections/901','DELETE',{},status=409)
# Each result uses this member's ballot for that election, never a global receipt.
fixture('seed-receipts')
first=request(voters[0],'voter/results')
by_id={e['id']:e for e in first['elections']}
assert 'receiptCode' not in first['member']
assert by_id[902]['receiptCode']=='CV-TESTFIRST' and by_id[902]['hasVoted'] is True
assert by_id[903]['receiptCode']=='CV-TESTSECOND' and by_id[903]['hasVoted'] is True
assert by_id[904]['receiptCode'] is None and by_id[904]['hasVoted'] is True
saved={r['electionId']:r['receiptCode'] for r in request(voters[0],'voter/receipts')['receipts']}
assert all(by_id[eid]['receiptCode']==saved[eid] for eid in [902,903,904])
second=request(voters[1],'voter/results')
other={e['id']:e for e in second['elections']}
assert other[902]['receiptCode']=='CV-OTHERUSER'
assert 903 not in other and 904 not in other
assert 'CV-TESTFIRST' not in json.dumps(second) and 'CV-TESTSECOND' not in json.dumps(second)
print('Election-specific receipts: API association, missing receipt, and other-voter isolation PASS')
if not args.application_only:
 fixture('migration')
else:
 print('Application-only scope: backup/migration/rollback tooling not exercised in this run.')
print(f'NFR19 scoped application privacy checks: {checks} HTTP checks PASS; full anonymity certification remains pending')

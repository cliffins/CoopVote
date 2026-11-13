"""Direct HTTP verification of schedule/deletion guards and atomic auditing without production writes."""
import datetime, http.cookiejar, json, os, subprocess, urllib.error, urllib.parse, urllib.request
from pathlib import Path

BASE=os.environ['FR8_BASE']
assert os.environ.get('COOPVOTE_ENV')=='test' and urllib.parse.urlparse(BASE).hostname=='127.0.0.1'
HERE=Path(__file__).resolve().parent
OUT=Path(os.environ['FR8_OUTPUT'])
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
cases=[]

def fixture(mode, *args):
    r=subprocess.run(['php',str(HERE/'fr8-fixture.php'),mode,*map(str,args)],check=True,text=True,capture_output=True)
    return json.loads(r.stdout) if r.stdout else None

def call(route, method='GET', data=None, expected=200):
    req=urllib.request.Request(BASE+'/api/index.php?'+urllib.parse.urlencode({'r':route}),method=method,
        data=None if data is None else json.dumps(data).encode(),headers={'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'})
    try: response=client.open(req,timeout=20)
    except urllib.error.HTTPError as e: response=e
    body=json.loads(response.read())
    assert response.status==expected,(route,method,response.status,expected,body)
    return body

def check(name, ok, **detail):
    cases.append({'name':name,'pass':bool(ok),**detail})
    assert ok,name
    print('PASS '+name,flush=True)

def elections(): return {e['id']:e for e in call('elections')}
def plus(value, **delta): return (datetime.datetime.fromisoformat(value)+datetime.timedelta(**delta)).strftime('%Y-%m-%dT%H:%M')
def payload(e): return {'title':e['title'],'description':e['description'],'startDate':e['startISO'],'endDate':e['endISO']}

try:
    call('auth/login','POST',{'username':'testadmin','password':'EndpointTest9!'})
    initial=fixture('backend-state')
    for eid,label in [(801,'Live with zero votes'),(900,'Live with stored votes')]:
        e=elections()[eid]
        assert e['status']=='Active'
        variants=[('start only',{'startDate':plus(e['startISO'],minutes=1)}),
                  ('end only',{'endDate':plus(e['endISO'],days=1)}),
                  ('both dates',{'startDate':plus(e['endISO'],days=2),'endDate':plus(e['endISO'],days=3)})]
        for method in ['PUT','PATCH']:
            for name,changes in variants:
                response=call(f'elections/{eid}',method,{**payload(e),**changes,'title':'MUST NOT SAVE'},409)
                check(f'{label}: {method} rejects {name}',fixture('backend-state')==initial,
                      expected_http=409,actual_http=409,response=response)
            alias={'title':'MUST NOT SAVE','description':e['description'],'start':plus(e['endISO'],days=2),'end':plus(e['endISO'],days=3)}
            call(f'elections/{eid}',method,alias,409)
            check(f'{label}: {method} date aliases cannot bypass guard',fixture('backend-state')==initial,actual_http=409)

    stale=elections()[805]
    assert stale['status']=='Upcoming'
    fixture('start-race')
    now_live=fixture('backend-state')
    for method in ['PUT','PATCH']:
        call('elections/805',method,{**payload(stale),'startDate':plus(stale['endISO'],days=1),'endDate':plus(stale['endISO'],days=2)},409)
        check(f'{method} rejects stale request after opening',fixture('backend-state')==now_live,actual_http=409)

    for eid,label in [(801,'Live with zero votes'),(802,'Closed with zero votes'),(804,'previously opened and now future-dated'),(805,'stale request after opening'),(900,'Live with votes'),(803,'recorded abstention')]:
        before=elections()[eid]
        call(f'elections/{eid}','DELETE',{},409)
        check(f'Deletion blocked: {label}',elections()[eid]==before and not before['canDelete'] and not fixture('deletion-audit',eid),actual_http=409)

    # Only this new unopened synthetic election is deleted.
    start=plus(stale['endISO'],days=1); end=plus(stale['endISO'],days=2)
    created=call('elections','POST',{'title':'Backend deletion verification fixture','startDate':start,'endDate':end,'positions':['President']},201)
    eid=created['id']; e=elections()[eid]; pid=e['positionList'][0]['id']
    call('candidates','POST',{'electionId':eid,'positionId':pid,'memberId':2,'platform':'Synthetic verification',
         'section':'A','year':'2nd Year','department':'Testing','course':'BS Test'},201)
    draft=elections()[eid]
    assert draft['status']=='Draft' and draft['canDelete']
    fixture('fail-deletion-audit')
    try:
        call(f'elections/{eid}','DELETE',{},500)
    finally:
        fixture('restore-deletion-audit')
    check('Audit failure rolls back deletion and linked candidate/position records',elections()[eid]==draft and not fixture('deletion-audit',eid))
    response=call(f'elections/{eid}','DELETE',{})
    check('Unopened Draft election remains deletable',response.get('deleted') and eid not in elections())
    audit=fixture('deletion-audit',eid)
    check('Successful deletion records actor, election, description and timestamp',len(audit)==1 and audit[0]['user_id']==1 and audit[0]['entity_id']==eid and 'Backend deletion verification fixture' in audit[0]['description'] and bool(audit[0]['timestamp']) and bool(audit[0]['ip_address']),audit=audit)

    result={'all_passed':True,'cases':cases,'scope':'Backend schedule restrictions and user-approved post-opening deletion prohibition',
            'manual_fr8_status':'PASS as reported by user','live_deletion_policy':'Block deletion once voting opens, as explicitly confirmed by user',
            'audit_atomicity':'Verified that an audit write failure rolls back the deletion and cascades',
            'production_accessed':False,'test_election_12_recreated':False}
    (OUT/'RESULTS.json').write_text(json.dumps(result,indent=2)+'\n')
finally:
    if not (OUT/'RESULTS.json').exists():
        (OUT/'RESULTS.json').write_text(json.dumps({'all_passed':False,'cases':cases},indent=2)+'\n')

import base64, datetime, http.cookiejar, json, os, urllib.request, urllib.error, urllib.parse
import csv as csv_module, io
from pathlib import Path

BASE = os.environ['COOPVOTE_TEST_URL']
assert urllib.parse.urlparse(BASE).hostname == '127.0.0.1', 'Write tests require the isolated loopback server'
results = []
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None
def client():
    return urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
anon, admin, voter = client(), client(), client()
def call(c, method, route, data=None, expect=200, query=None, kind='json', raw=None, headers=None):
    url = BASE + '?' + urllib.parse.urlencode({'r': route, **(query or {})})
    h = {'X-Requested-With':'XMLHttpRequest'}
    if data is not None: h['Content-Type']='application/json'
    h.update(headers or {})
    request = urllib.request.Request(url, data=raw if raw is not None else (json.dumps(data).encode() if data is not None else None), headers=h, method=method)
    try: response=c.open(request, timeout=20)
    except urllib.error.HTTPError as e: response=e
    body=response.read()
    parsed=None
    valid=True
    if kind=='json':
        try: parsed=json.loads(body)
        except Exception: valid=False
        valid = valid and 'application/json' in response.headers.get('Content-Type','')
    else: valid = kind in response.headers.get('Content-Type','') if kind else not body
    passed=response.status==expect and valid
    results.append({'method':method,'route':route,'query':query,'expected':expect,'actual':response.status,'pass':passed})
    if not passed: print('FAIL',method,route,expect,response.status,body[:350].decode(errors='replace'), flush=True)
    return parsed if parsed is not None else body

# Every routed action/method is checked through its unauthenticated boundary.
protected = {
 'auth/me':['GET'], 'auth/check-username':['POST'], 'branding':['PUT'],
 'notifications':['GET','POST'],
 'management':['GET'], 'management/overview':['GET'], 'management/preferences':['GET','PUT'],
 'management/accounts':['GET','POST'], 'management/accounts/999':['PUT','POST'],
 'management/config':['PUT'], 'management/elections/999/submit':['POST'], 'management/elections/999/decide':['POST'],
 'voter/elections':['GET'], 'voter/dashboard':['GET'], 'voter/election':['GET'], 'voter/candidates':['GET'],
 'voter/ballot':['GET'], 'voter/vote':['POST'], 'voter/results':['GET'], 'voter/receipts':['GET'],
 'voter/profile':['GET','PUT','POST'], 'voter/profile-photo':['POST'], 'voter/profile-photo/999':['GET'],
 'voter/change-password':['POST'], 'dashboard':['GET'],
 'voters':['GET','POST'], 'voters/999':['PUT','PATCH','DELETE'], 'voters/export':['GET'],
 'voters/registration-status':['GET'], 'voters/import/status':['GET'], 'voters/import/template':['GET'],
 'voters/import/credentials':['GET'],
 'candidates':['GET','POST'], 'candidates/999':['PUT','PATCH','DELETE'], 'candidates/eligible':['GET'], 'candidates/export':['GET'],
 'elections':['GET','POST'], 'elections/999':['PUT','PATCH','DELETE'],
 'positions':['GET','POST'], 'positions/999':['DELETE'], 'monitor':['GET'], 'results':['GET'], 'results/export':['GET'],
 'reports':['GET'], 'operations':['GET'], 'operations/overview':['GET'], 'operations/report':['GET'],
 'security':['GET'], 'security/logs/export':['GET'], 'security/block-ip':['POST','DELETE'], 'security/review-alert':['POST'],
 'settings':['GET','PUT','POST']}
for action in ['approve','reject','suspend','activate','deactivate']: protected['voters/999/'+action]=['PATCH']
for action in ['activate','publish','close','reopen','release','release-results','unpublish','unrelease','cancel']: protected['elections/999/'+action]=['PATCH']
for action in ['clear','preview','commit','email']: protected['voters/import/'+action]=['POST']
for route, methods in protected.items():
    for method in methods: call(anon,method,route,{} if method!='GET' else None,401)
call(anon,'GET','')
call(anon,'OPTIONS','branding',expect=204,kind='')
call(anon,'GET','branding')
call(anon,'GET','not-a-route',expect=404)
for action in ['register','verify-otp','resend-otp']: call(anon,'POST','auth/'+action,{},403)
call(anon,'GET','auth/login',expect=405)
call(anon,'POST','auth/login',{},400)
for _ in range(3): call(anon,'POST','auth/login',{'username':'nonexistent','password':'wrong'},401)
for c,name in [(admin,'testadmin'),(voter,'testvoter')]:
    call(c,'POST','auth/login',{'username':name,'password':'EndpointTest9!'})
    call(c,'GET','auth/me')
# FR-RBAC-01: redirect before any forbidden markup is sent; preserve last page.
last_page = {admin:'admin/dashboard.php', voter:'voter/dashboard.php'}
def get_page(c, path):
    url = BASE.split('/api/')[0] + '/' + path
    try: response = c.open(url, timeout=20)
    except urllib.error.HTTPError as error: response = error
    return response
for page in Path(__file__).resolve().parents[2].glob('*/*.php'):
    if page.parent.name not in ['admin', 'voter']: continue
    folder = page.parent.name
    for suffix in ['.html', '.php']:
        path = folder + '/' + page.stem + suffix
        roles = [(anon,302), (voter,302), (admin,200)] if folder == 'admin' else [(anon,302), (admin,302), (voter,200)]
        for c, expected in roles:
            response = get_page(c, path)
            body = response.read().decode()
            assert response.status == expected, (path, response.status, expected)
            assert 'no-store' in response.headers.get('Cache-Control', '')
            if expected == 302:
                assert body == '', 'Forbidden page must not render any markup'
                assert response.headers['Location'] == '../' + ('login.php' if c is anon else last_page[c])
            else:
                assert 'id="sidebar"' in body
                last_page[c] = folder + '/' + page.stem + '.php'
            results.append({'method':'GET','route':path,'expected':expected,'actual':response.status,'pass':True})
for suffix in ['html', 'php']:
    response = get_page(anon, 'management.' + suffix)
    assert response.status == 302 and response.headers['Location'] == 'login.php' and response.read() == b''
response = get_page(anon, 'includes/management-page.php')
assert response.status == 404 and response.read() == b'', 'Shared template cannot be opened directly'
for c, own, forbidden in [(voter,'voter/election.php?election=123','admin/dashboard.php'),
                           (admin,'admin/reports.php?election=123','voter/dashboard.php')]:
    assert get_page(c,own).status == 200
    response = get_page(c,forbidden)
    assert response.status == 302 and response.headers['Location'] == '../' + own
    assert response.read() == b''
# Old printed QR links must reach login without losing the selected election.
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None
no_redirect = urllib.request.build_opener(NoRedirect())
for suffix in ['.html', '.php']:
    url = BASE.split('/api/')[0] + '/voter/election' + suffix + '?election=123'
    try: response = no_redirect.open(url, timeout=20)
    except urllib.error.HTTPError as error: response = error
    assert response.status == 302 and response.headers['Location'] == '../login.php?election=123'
    assert 'no-store' in response.headers.get('Cache-Control', '')
    assert 'id="sidebar"' not in response.read().decode()
    results.append({'method':'GET','route':url,'expected':302,'actual':response.status,'pass':True})
    login = urllib.request.urlopen(url, timeout=20)
    assert login.status == 200 and login.url.endswith('/login.php?election=123')
    for c, expected in [(admin,302), (voter,200)]:
        try: response = c.open(url, timeout=20)
        except urllib.error.HTTPError as error: response = error
        assert response.status == expected
        results.append({'method':'GET','route':url,'expected':expected,'actual':response.status,'pass':True})
for query in ['election=0','election=-1','election=abc','election=12345678901','election[]=123','election=https%3A%2F%2Fexample.com']:
    url = BASE.split('/api/')[0] + '/voter/election.html?' + query
    try: response = no_redirect.open(url, timeout=20)
    except urllib.error.HTTPError as error: response = error
    assert response.status == 302 and response.headers['Location'] == '../login.php' and response.read() == b''
    results.append({'method':'GET','route':url,'expected':302,'actual':response.status,'pass':True})
for route in ['dashboard','voters','candidates','elections','positions','monitor','results','reports','operations','security','settings','management','management/accounts','management/preferences','notifications','voters/registration-status','voters/import/status','candidates/eligible']:
    call(admin,'GET',route)
for route in ['voters/export','candidates/export','security/logs/export','voters/import/template']:
    call(admin,'GET',route,kind='text/csv')
call(admin,'GET','results/export',expect=404)
call(admin,'GET','voters/import/template',query={'format':'xlsx'},kind='application/vnd.openxmlformats')
call(admin,'GET','voters/import/credentials',expect=410)
call(admin,'POST','auth/check-username',{'username':'unusedname'})
call(voter,'GET','dashboard',expect=403)
call(voter,'GET','management/accounts',expect=403)
call(admin,'GET','voter/dashboard',expect=403)
call(admin,'PUT','branding',{'text':'Test Coop','logo':None,'coopName':'Test Cooperative'})
branding=call(anon,'GET','branding'); assert branding['text']=='Test Coop'
call(admin,'PUT','management/preferences',{'theme':'navy','resetBranding':True})
call(voter,'PUT','management/preferences',{'theme':'navy'})
call(voter,'GET','management/preferences')
call(admin,'PUT','management/config',{'requiredApprovals':1})
call(admin,'PUT','settings',{'coop_name':'Endpoint Test Coop'})
call(admin,'POST','settings',{'coop_short':'TEST'})
# Retired announcements must never reach a table or accept writes.
for c in [anon, admin, voter]:
    for method, route in [('GET','announcements'),('POST','announcements'),('DELETE','announcements/999')]:
        payload=call(c,method,route,{} if method!='GET' else None,410)
        assert payload['code']=='ANNOUNCEMENTS_REMOVED'
dashboard=call(voter,'GET','voter/dashboard')
assert isinstance(dashboard,dict) and 'member' in dashboard and 'announcement' not in dashboard
page=get_page(voter,'voter/dashboard.php').read().decode()
assert 'ann-wrap' not in page and 'renderAnnouncement' not in page
call(admin,'POST','security/block-ip',{'ip':'192.0.2.12'},201)
call(admin,'DELETE','security/block-ip',{'ip':'192.0.2.12'})
security=call(admin,'GET','security')
# Alert ID is retrieved from the documented security response.
for alert in security.get('alerts',[]): call(admin,'POST','security/review-alert',{'id':alert['id']})
call(admin,'POST','security/review-alert',{'id':'missing'},404)
newadmin=call(admin,'POST','management/accounts',{'name':'Other Admin','username':'otheradmin','email':'otheradmin@example.test'},201)
accounts=call(admin,'GET','management/accounts'); aid=next(a['id'] for a in accounts if a['username']=='otheradmin')
call(admin,'PUT',f'management/accounts/{aid}',{'name':'Updated Admin','email':'otheradmin@example.test'})
call(admin,'POST',f'management/accounts/{aid}',{'status':'deactivated'})
profile={'first':'Test','last':'Voter','email':'testvoter@example.test','phone':'09171234567','address':'Updated test address','dob':'1990-01-01'}
call(voter,'PUT','voter/profile',profile)
call(voter,'POST','voter/profile',profile)
newprofile={**profile,'first':'New','last':'Member','email':'newmember@example.test','memberId':'TEST-3'}
newprofile.pop('phone')
member=call(admin,'POST','voters',newprofile,201)
uid=member['id']
assert 'phone' not in next(v for v in call(admin,'GET','voters') if v['id']==uid), 'Add Voter omits obsolete phone field'
call(admin,'PUT',f'voters/{uid}',newprofile)
call(admin,'PATCH',f'voters/{uid}',newprofile)
call(admin,'PUT',f'voters/{uid}',{**newprofile,'phone':'09171234567'})
call(admin,'PUT',f'voters/{uid}',newprofile)
assert 'phone' not in next(v for v in call(admin,'GET','voters') if v['id']==uid), 'Stale phone input is not exposed'
for action in ['suspend','activate','deactivate','reject','approve']: call(admin,'PATCH',f'voters/{uid}/{action}',{})
csv='memberId,first,last,email,address,dob,username\nTEST-4,Import,Member,import@example.test,Test address,1990-01-01,importmember\n'
preview=call(admin,'POST','voters/import/preview',{'csv':csv})
imported=call(admin,'POST','voters/import/commit',{'token':preview['token']},201)
call(admin,'GET','voters/import/credentials',kind='text/csv')
call(admin,'POST','voters/import/email',{'token':preview['token'],'id':imported['created'][0]['id']})
call(admin,'POST','voters/import/clear',{})
# Phone is no longer part of the CSV format, including voter exports.
phone_free_header='memberId,first,last,email,address,dob,username'
template=call(admin,'GET','voters/import/template',kind='text/csv').decode('utf-8-sig').strip()
assert template==phone_free_header, 'New CSV template must not request a phone column'
for label,source,username in [
    ('omitted column',phone_free_header+'\nTEST-NOPHONE,No,Phone,nophone@example.test,"Block 1, Main Street",1990-01-01,nophonemember\n','nophonemember'),
]:
    preview=call(admin,'POST','voters/import/preview',{'csv':source})
    assert preview['valid']==1 and preview['invalid']==0 and 'phone' not in preview['rows'][0]['data'],label
    imported=call(admin,'POST','voters/import/commit',{'token':preview['token']},201)
    assert len(imported['created'])==1,label
    saved=next(v for v in call(admin,'GET','voters') if v['username']==username)
    assert 'phone' not in saved and saved['dobInput']=='1990-01-01',label+' correct column mapping'
    if label=='omitted column':assert saved['address']=='Block 1, Main Street'
    repeated=call(admin,'POST','voters/import/commit',{'token':preview['token']})
    assert repeated['created']==imported['created'],label+' replay must not duplicate accounts'
    call(admin,'POST','voters/import/clear',{})
    call(admin,'DELETE',f"voters/{saved['id']}",{})
for source,field in [
    (phone_free_header+'\n,Missing,Email,,Test address,1990-01-01,missingemail\n','email'),
    (phone_free_header+'\n,Bad,Date,baddate@example.test,Test address,invalid,baddate\n','dob'),
    (phone_free_header+'\n,Short,Row,shortrow@example.test,Test address,1990-01-01\n','csv'),
]:
    preview=call(admin,'POST','voters/import/preview',{'csv':source})
    assert preview['valid']==0 and field in preview['rows'][0]['errors'],field+' validation remains active'
    call(admin,'POST','voters/import/commit',{'token':preview['token']},422)
    call(admin,'POST','voters/import/clear',{})
call(admin,'POST','voters/import/preview',{'csv':'first,last,email,address,dob,username\nWrong,Header,wrong@example.test,Test address,1990-01-01,wrongheader\n'},422)
for phone in ['', '09171234569']:
    legacy='memberId,first,last,email,phone,address,dob,username\n,Legacy,Import,legacy@example.test,'+phone+',Test address,1990-01-01,legacyimport\n'
    call(admin,'POST','voters/import/preview',{'csv':legacy},422)
exported=list(csv_module.reader(io.StringIO(call(admin,'GET','voters/export',kind='text/csv').decode('utf-8-sig'))))
assert exported[0]==phone_free_header.split(',') and all(len(row)==7 for row in exported)
print('Phone-free CSV: preview/commit, seven-column template/export, quoted fields and replay protection PASS; legacy phone columns rejected',flush=True)
# Spreadsheet slash dates are day-first; the preview and stored values agree.
date_cases=[('15/03/1999','1999-03-15'),('22/07/2000','2000-07-22'),('09/11/1998','1998-11-09'),
    ('28/01/2001','2001-01-28'),('12/09/1997','1997-09-12'),('30/08/2004','2004-08-30'),
    ('11/01/2005','2005-01-11'),('10/07/2005','2005-07-10'),('29/02/2000','2000-02-29'),
    ('1/2/2003','2003-02-01'),('2000-02-29','2000-02-29')]
source=phone_free_header+'\n'+''.join(f',Date,Test,dobcase{i}@example.test,Test address,{dob},dobcase{i}\n' for i,(dob,_) in enumerate(date_cases))
preview=call(admin,'POST','voters/import/preview',{'csv':source})
assert preview['invalid']==0 and preview['valid']==len(date_cases)
assert [row['data']['dob'] for row in preview['rows']]==[expected for _,expected in date_cases]
imported=call(admin,'POST','voters/import/commit',{'token':preview['token']},201)
assert len(imported['created'])==len(date_cases)
saved={v['username']:v for v in call(admin,'GET','voters')}
for i,(_,expected) in enumerate(date_cases):assert saved[f'dobcase{i}']['dobInput']==expected
call(admin,'POST','voters/import/clear',{})
for row in imported['created']:call(admin,'DELETE',f"voters/{row['id']}",{})
invalid_dates=['31/04/2000','29/02/2001','00/01/2000','15/13/2000','03/15/1999','15/03/99','01/01/2999','2999-01-01','2001-02-29','', '45365']
source=phone_free_header+'\n'+''.join(f',Invalid,Date,invaliddob{i}@example.test,Test address,{dob},invaliddob{i}\n' for i,dob in enumerate(invalid_dates))
preview=call(admin,'POST','voters/import/preview',{'csv':source})
assert preview['valid']==0 and preview['invalid']==len(invalid_dates)
assert all('dob' in row['errors'] for row in preview['rows'])
call(admin,'POST','voters/import/commit',{'token':preview['token']},422)
call(admin,'POST','voters/import/clear',{})
print('CSV DOB: 11 valid ISO/day-first dates previewed, committed and read back exactly; 11 invalid/future/unsupported dates rejected PASS',flush=True)
now=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8)))
start=(now+datetime.timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M')
end=(now+datetime.timedelta(days=1)).strftime('%Y-%m-%dT%H:%M')
election={'title':'Endpoint Test Election','description':'Synthetic fixture','startDate':start,'endDate':end,'positions':['President','Treasurer']}
e=call(admin,'POST','elections',election,201); eid=e['id']
# Dashboard selection must scope election details while keeping system totals explicit.
scoped=call(admin,'GET','dashboard',query={'election':eid})
assert scoped['selectedElection']['id']==eid and scoped['selectedElection']['status']=='Draft'
assert scoped['selectedElection']['ballotsSubmitted']==0 and scoped['selectedElection']['candidates']==0
assert scoped['recentVoters']==[] and scoped['selectedElection']['activeVotersRemaining']==scoped['totalVoters']
call(admin,'GET','dashboard',query={'election':999999},expect=404)
call(admin,'GET','dashboard',query={'election':'invalid'},expect=422)

call(admin,'PUT',f'elections/{eid}',election)
call(admin,'PATCH',f'elections/{eid}',election)
positions=call(admin,'GET','positions',query={'election':eid}); pid=positions[0]['position_id']
pid2=positions[1]['position_id']
before_positions=call(admin,'GET','positions',query={'election':eid})
before_elections=call(admin,'GET','elections')
for bad_name in ['President 2','123','Vice-President','Audit & Finance','<Chair>','   ']:
    call(admin,'POST','positions',{'electionId':eid,'name':bad_name},422)
    call(admin,'POST','elections',{**election,'positions':['President',bad_name]},422)
assert call(admin,'GET','positions',query={'election':eid})==before_positions
assert call(admin,'GET','elections')==before_elections
accented=call(admin,'POST','positions',{'electionId':eid,'name':'Tagapangulo ng Lupon'},201)
call(admin,'DELETE',f"positions/{accented['id']}",{})
print('Position validation: letters/spaces accepted; digits/symbols rejected in both save routes; rejected writes preserve data PASS',flush=True)
extra=call(admin,'POST','positions',{'electionId':eid,'name':'Temporary position'},201)
call(admin,'DELETE',f'positions/{extra["id"]}',{})
academics={'section':'A','year':'2nd Year','department':'College of Computing','course':'BS Information Technology'}
candidate={'electionId':eid,'positionId':pid,'memberId':2,'platform':'Test platform',**academics}
c=call(admin,'POST','candidates',candidate,201); cid=c['id']
def saved_candidate():
    return next(c for c in call(admin,'GET','candidates',query={'election':eid}) if c['id']==cid)
assert all(saved_candidate()[key]==value for key,value in academics.items()), 'Academic fields persist after creation'
edited={**candidate,'section':'B','year':'3rd Year','department':'Arts & Sciences','course':'BA Communication'}
call(admin,'PUT',f'candidates/{cid}',edited)
assert all(saved_candidate()[key]==edited[key] for key in academics), 'Academic fields persist after editing'
legacy={key:value for key,value in candidate.items() if key not in academics}
call(admin,'PATCH',f'candidates/{cid}',legacy)
assert all(saved_candidate()[key]==edited[key] for key in academics), 'Unspecified academic fields are preserved'
for field,limit in [('section',100),('year',32),('department',150),('course',150)]:
    for invalid in [' ', 'x'*(limit+1), ['invalid']]:
        rejected=call(admin,'PUT',f'candidates/{cid}',{**edited,field:invalid},422)
        assert field in rejected['fields']
assert all(saved_candidate()[key]==edited[key] for key in academics), 'Rejected edits do not change saved fields'
export=call(admin,'GET','candidates/export',query={'election':eid},kind='text/csv').decode('utf-8-sig')
assert 'Year & Section' in export and '3rd Year · Section B' in export
assert all(removed not in export for removed in ['Department', 'Course', 'BA Communication', 'Occupation'])
call(admin,'PUT',f'candidates/{cid}',candidate)
call(admin,'PATCH',f'candidates/{cid}',candidate)
c2=call(admin,'POST','candidates',{**candidate,'positionId':pid2,'memberId':1},201)
call(admin,'POST',f'management/elections/{eid}/submit',{})
overview=call(admin,'GET','management/overview')
review=next(e for e in overview['elections'] if e['id']==eid)['request']
call(admin,'POST',f'management/elections/{eid}/decide',{'requestId':review['request_id'],'decision':'approve'})
call(admin,'PATCH',f'elections/{eid}/publish',{})
call(admin,'PATCH',f'elections/{eid}/activate',{})
for route in ['elections','dashboard','election','candidates','ballot','results','receipts','profile']:
    call(voter,'GET','voter/'+route,query={'election':eid})
notices=call(voter,'GET','notifications')
if notices['notices']: call(voter,'POST','notifications',{'id':notices['notices'][0]['id']})
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aRZkAAAAASUVORK5CYII=')
boundary='endpoint-test-boundary'
upload=(f'--{boundary}\r\nContent-Disposition: form-data; name="photo"; filename="test.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+png+f'\r\n--{boundary}--\r\n'.encode())
call(voter,'POST','voter/profile-photo',raw=upload,headers={'Content-Type':'multipart/form-data; boundary='+boundary})
call(voter,'GET','voter/profile-photo/1',kind='image/png')
abstain={'positionId':pid,'candidateId':None,'abstain':True}
abstain2={'positionId':pid2,'candidateId':None,'abstain':True}
for choices in [
    [abstain],  # Missing a contested position.
    [abstain, abstain, abstain2],
    [abstain, {'candidateId':cid}, abstain2],
    [{**abstain,'candidateId':cid}, abstain2],
    [{**abstain,'positionId':999999}, abstain2],
    [{'candidateId':cid,'positionId':pid2}, abstain2],
    [{'candidateId':0}, abstain2],
    ['invalid', abstain2],
    [{**abstain,'abstain':'true'}, abstain2],
]:
    call(voter,'POST','voter/vote',{'electionId':eid,'votes':choices},422)
call(voter,'POST','voter/vote',{'electionId':eid,'votes':[]},400)
ballot={'electionId':eid,'votes':[{'candidateId':cid},abstain2]}
cast=call(voter,'POST','voter/vote',ballot,201)
assert cast['selections']==1 and cast['abstentions']==1 and cast['receiptCode']
call(voter,'POST','voter/vote',ballot,409)
call(voter,'GET','voter/receipts')
abstainer=client()
call(abstainer,'POST','auth/login',{'username':'testnominee','password':'EndpointTest9!'})
all_abstain={'electionId':eid,'votes':[abstain,abstain2]}
cast=call(abstainer,'POST','voter/vote',all_abstain,201)
assert cast['selections']==0 and cast['abstentions']==2 and cast['receiptCode']
call(abstainer,'POST','voter/vote',all_abstain,409)
participation=call(abstainer,'GET','voter/ballot',query={'election':eid})
assert participation['member']['hasVoted'] is True
call(abstainer,'GET','voter/receipts')
call(admin,'PATCH',f'elections/{eid}/close',{})
call(admin,'PATCH',f'elections/{eid}/release',{},409)
call(admin,'PATCH',f'elections/{eid}/release-results',{},409)
call(voter,'GET','voter/results')
call(admin,'GET','results/export',query={'election':eid},expect=409)
for route in ['dashboard','monitor','results','reports','operations','operations/overview']:
    call(admin,'GET',route,query={'election':eid})
for fmt,kind in [('json','json'),('csv','text/csv'),('html','text/html')]:
    call(admin,'GET','operations/report',query={'election':eid,'format':fmt},expect=409)
call(admin,'PATCH',f'elections/{eid}/unpublish',{})
call(admin,'PATCH',f'elections/{eid}/unrelease',{})
call(admin,'POST',f'management/elections/{eid}/submit',{'proposedEnd':end})
overview=call(admin,'GET','management/overview'); review=next(e for e in overview['elections'] if e['id']==eid)['request']
call(admin,'POST',f'management/elections/{eid}/decide',{'requestId':review['request_id'],'decision':'approve'})
call(admin,'PATCH',f'elections/{eid}/reopen',{'endDate':end})
call(admin,'DELETE',f'candidates/{cid}',{})
call(admin,'PATCH',f'elections/{eid}/close',{})
spare=call(admin,'POST','elections',{**election,'title':'Disposable election'},201)
original_dashboard=call(admin,'GET','dashboard',query={'election':eid})
spare_dashboard=call(admin,'GET','dashboard',query={'election':spare['id']})
assert original_dashboard['selectedElection']['ballotsSubmitted']==2
assert spare_dashboard['selectedElection']['ballotsSubmitted']==0
assert original_dashboard['votesCast']==spare_dashboard['votesCast']==2
assert original_dashboard['totalCandidates']==spare_dashboard['totalCandidates']
assert original_dashboard['positionVotes']==[] and original_dashboard['recentVoters']==[]
# A suspended participant remains in historical ballot totals but leaves current-active counts.
call(admin,'PATCH','voters/2/suspend',{})
suspended=call(admin,'GET','dashboard',query={'election':eid})['selectedElection']
assert suspended['ballotsSubmitted']==2 and suspended['activeVotersVoted']==1
assert suspended['activeVotersRemaining']==suspended['activeVoters']-1
call(admin,'PATCH','voters/2/activate',{})

call(admin,'PATCH',f'elections/{spare["id"]}/cancel',{'reason':'Synthetic regression cancellation'},410)
call(admin,'DELETE',f'elections/{spare["id"]}',{})
call(admin,'DELETE',f'voters/{uid}',{})
call(voter,'POST','voter/change-password',{'currentPassword':'EndpointTest9!','newPassword':'ChangedTest9!'})
for c in [admin,voter]:
    call(c,'POST','auth/logout',{})
    call(c,'GET','auth/me',expect=401)
call(voter,'POST','auth/login',{'username':'testvoter','password':'ChangedTest9!'})
call(voter,'POST','auth/logout',{})
Path(os.environ['COOPVOTE_TEST_RESULTS']).write_text(json.dumps(results,indent=2))
failed=[r for r in results if not r['pass']]
print(f'{len(results)-len(failed)}/{len(results)} HTTP assertions passed; {len(failed)} failed; {len({(r["method"],r["route"]) for r in results})} distinct method/path cases')
raise SystemExit(bool(failed))

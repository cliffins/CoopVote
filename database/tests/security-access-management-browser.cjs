'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),http=require('node:http'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE),api=process.env.COOPVOTE_TEST_URL,origin=new URL(api).origin;
assert.equal(process.env.COOPVOTE_ENV,'test');assert.equal(new URL(api).hostname,'127.0.0.1');
const fixture=mode=>execFileSync('php',[path.join(__dirname,'security-access-fixture.php'),mode],{env:process.env,encoding:'utf8'});
const jobs=()=>fs.existsSync(process.env.COOPVOTE_RECOVERY_TEST_OUTBOX)?fs.readFileSync(process.env.COOPVOTE_RECOVERY_TEST_OUTBOX,'utf8').trim().split('\n').filter(Boolean).map(JSON.parse):[];
const latestToken=()=>new URL(jobs().at(-1).url).hash.slice(7);
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  fixture('migration');console.log('PASS additive migration from old schema, rerun is idempotent, existing account data preserved.');
  const admin=await browser.newContext({reducedMotion:'reduce'}),anon=await browser.newContext(),voter=await browser.newContext();
  await admin.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  async function req(c,route,method='GET',data,status=200){const r=await c.request.fetch(api+'?r='+route,{method,data});assert.equal(r.status(),status,'Unexpected status for '+route+': '+await r.text());return r.json();}
  const login=(c,name,password='EndpointTest9!')=>req(c,'auth/login','POST',{username:name,password});
  await login(admin,'testadmin');await login(voter,'testvoter');const counts=fixture('counts');
  await req(anon,'security/block-ip','POST',{ip:'203.0.113.1'},401);await req(voter,'security/block-ip','POST',{ip:'203.0.113.1'},403);
  assert.equal((await admin.request.post(api+'?r=security/block-ip',{form:{ip:'203.0.113.1'}})).status(),415);
  for(const ip of ['127.0.0.1','::ffff:127.0.0.1'])await req(admin,'security/block-ip','POST',{ip},409);
  await req(admin,'security/block-ip','POST',{ip:'invalid'},422);
  await req(admin,'security/block-ip','POST',{ip:'2001:db8::1'},201);fixture('ipv6');
  await req(admin,'security/block-ip','DELETE',{ip:'2001:0db8:0:0:0:0:0:1'});
  const page=await admin.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/admin/security-logs.php');await page.locator('#blocked-addresses').waitFor();
  await page.locator('#blocked-addresses button').first().click();await page.fill('#block-ip-input','127.0.0.2');await page.fill('#block-reason','Disposable test address');await page.locator('#block-confirm-button').click();
  await page.locator('#blocked-addresses .blocked-address').waitFor();
  // Real loopback source binding, not an application header bypass.
  const storage=await voter.storageState(),cookie=storage.cookies.map(c=>c.name+'='+c.value).join('; ');
  function fromBlocked(target,method='GET',headers={}) {return new Promise((resolve,reject)=>{
   const r=http.request(origin+target,{method,localAddress:'127.0.0.2',headers:{Cookie:cookie,...headers}},res=>{let body='';res.on('data',c=>body+=c);res.on('end',()=>resolve({status:res.statusCode,body}));});r.on('error',reject);r.end();
  });}
  assert.equal((await fromBlocked('/api/index.php?r=auth/me','GET',{'X-Forwarded-For':'127.0.0.1'})).status,403);
  assert.equal((await fromBlocked('/voter/dashboard.php')).status,403);
  assert.equal((await fromBlocked('/api/index.php?r=auth/logout','POST')).status,200);
  for(const width of [320,375,1280]){await page.setViewportSize({width,height:900});await page.locator('#blocked-addresses').scrollIntoViewIfNeeded();assert.ok(await page.locator('#blocked-addresses').evaluate(e=>e.scrollWidth<=e.clientWidth+1));}
  await page.getByRole('button',{name:'Unblock',exact:true}).click();await page.locator('#block-confirm-button').click();await page.getByText('No blocked addresses.',{exact:true}).waitFor();
  assert.equal((await fromBlocked('/api/index.php?r=branding')).status,200);
  console.log('PASS Block IP: admin-only and JSON-only writes, invalid/self-address rejection, IPv6 normalization, UI block/unblock, API and protected-page enforcement, spoofed forwarded-header resistance, logout allowed, mobile panel fits.');
  const recover=(data,status=200)=>req(anon,'auth/password-recovery','POST',data,status);
  await req(anon,'auth/password-recovery','GET',undefined,405);
  assert.equal((await anon.request.post(api+'?r=auth/password-recovery',{form:{action:'request',email:'testvoter@example.test'}})).status(),415);
  await recover({action:'request',email:'not-an-email'},422);
  const unknown=await recover({action:'request',email:'unknown@example.test'});assert.equal(jobs().length,0);
  const known=await recover({action:'request',email:'testvoter@example.test'});assert.deepEqual(known,unknown);assert.equal(jobs().length,1);fixture('verify-storage');
  const token=latestToken();assert.ok(!JSON.stringify(known).includes(token));
  await recover({action:'request',email:'testvoter@example.test'});assert.equal(jobs().length,1);
  await recover({action:'inspect',token:'0'.repeat(64)},400);await recover({action:'inspect',token});
  await recover({action:'complete',token,password:'short',confirmPassword:'short'},422);
  await recover({action:'complete',token,password:'RecoveryTest9!',confirmPassword:'Mismatch9!'},422);
  const resetPage=await anon.newPage();resetPage.on('pageerror',e=>errors.push(e.message));
  await resetPage.goto(origin+'/password-recovery.php#token='+token);await resetPage.locator('#reset-form').waitFor();assert.ok(!resetPage.url().includes(token));
  await resetPage.fill('#password','RecoveryTest9!');await resetPage.fill('#confirm-password','RecoveryTest9!');await resetPage.locator('#toggle-password').click();assert.equal(await resetPage.locator('#password').getAttribute('type'),'text');
  for(const width of [320,375,1280]){await resetPage.setViewportSize({width,height:900});assert.ok(await resetPage.locator('main').evaluate(e=>e.scrollWidth<=e.clientWidth+1));await resetPage.screenshot({path:path.join(path.dirname(process.env.COOPVOTE_CANDIDATE_SCREENSHOT),'password-recovery-'+width+'.png')});}
  const stale=await browser.newContext();await login(stale,'testvoter');
  await resetPage.locator('#reset-submit').click();await resetPage.getByRole('heading',{name:'Password reset',exact:true}).waitFor();
  await req(stale,'auth/me','GET',undefined,401);
  await req(voter,'auth/login','POST',{username:'testvoter',password:'EndpointTest9!'},401);await login(voter,'testvoter','RecoveryTest9!');
  await recover({action:'complete',token,password:'RecoveryTest8!',confirmPassword:'RecoveryTest8!'},400);
  console.log('PASS recovery: generic known/unknown responses, JSON-only requests, cooldown, hashed tokens, invalid token/weak password/mismatch rejection, responsive reset form and show/hide, single use, new-password login and previous-session revocation.');
  fixture('limits-clear');await recover({action:'request',email:'testnominee@example.test'});const expired=latestToken();fixture('expire');await recover({action:'inspect',token:expired},400);
  fixture('limits-clear');await recover({action:'request',email:'testnominee@example.test'});const old=latestToken();fixture('cooldown-clear');await recover({action:'request',email:'testnominee@example.test'});const newest=latestToken();await recover({action:'inspect',token:old},400);await recover({action:'inspect',token:newest});
  fixture('change-password');await recover({action:'inspect',token:newest},400);
  fixture('limits-clear');await recover({action:'request',email:'testnominee@example.test'});const emailBound=latestToken();fixture('change-email');await recover({action:'inspect',token:emailBound},400);
  fixture('limits-clear');fixture('suspend');let n=jobs().length;assert.deepEqual(await recover({action:'request',email:'changed@example.test'}),unknown);assert.equal(jobs().length,n);
  fixture('activate');fixture('unverify');fixture('limits-clear');await recover({action:'request',email:'changed@example.test'});assert.equal(jobs().length,n);
  fixture('limits-clear');for(let i=0;i<10;i++)await recover({action:'request',email:'absent'+i+'@example.test'});await recover({action:'request',email:'another@example.test'},429);
  fixture('limits-clear');for(let i=0;i<4;i++){fixture('cooldown-clear');await recover({action:'request',email:'testvoter@example.test'});}assert.equal(jobs().length,n+3);
  fixture('limits-clear');await recover({action:'request',email:'testadmin@example.test'});const adminToken=latestToken();await recover({action:'complete',token:adminToken,password:'AdminRecovery9!',confirmPassword:'AdminRecovery9!'});
  await req(admin,'auth/me','GET',undefined,401);await login(admin,'testadmin','AdminRecovery9!');
  fixture('limits-clear');
  await resetPage.goto(origin+'/login.php');
  await resetPage.getByRole('link',{name:'Forgot password?',exact:true}).click();
  await resetPage.locator('#email').fill('testadmin@example.test');const outboxBefore=jobs().length;
  await resetPage.locator('#request-submit').click();
  await resetPage.waitForFunction(()=>document.getElementById('message').textContent.includes('active, verified account'));
  assert.equal(jobs().length,outboxBefore+1);assert.ok(!(await resetPage.locator('main').textContent()).includes(latestToken()));
  await resetPage.goto(origin+'/password-recovery.php#token='+adminToken);
  await resetPage.waitForFunction(()=>document.getElementById('message').textContent.includes('already used'));
  assert.equal(await resetPage.locator('#reset-form').isVisible(),false);
  console.log('PASS login Forgot Password link and email request form; generic response, no token disclosure; used-link UI hides reset form.');
  assert.equal(fixture('counts'),counts);assert.deepEqual(errors,[]);
  console.log('PASS expired/replaced links, email/password-bound invalidation, suspended/unverified account suppression, IP/account rate limits, administrator recovery and session revocation; fixture ballot/vote counts unchanged; no browser JavaScript errors. No real emails sent.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

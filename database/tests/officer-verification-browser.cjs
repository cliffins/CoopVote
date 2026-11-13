'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE);
const api=process.env.COOPVOTE_TEST_URL,origin=new URL(api).origin,out=process.env.COOPVOTE_OFFICER_EVIDENCE;
assert.equal(process.env.COOPVOTE_ENV,'test');assert.equal(new URL(api).hostname,'127.0.0.1');
function newestLink(id){return fs.readFileSync(process.env.COOPVOTE_OFFICER_TEST_OUTBOX,'utf8').trim().split('\n').map(JSON.parse).reverse().find(j=>j.id===id).url;}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 const errors=[];
 try{
  const admin=await browser.newContext(),guest=await browser.newContext();
  for(const ctx of [admin,guest]){
   await ctx.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
   ctx.on('page',p=>p.on('pageerror',()=>errors.push('Browser JavaScript error')));
  }
  async function req(ctx,route,data,method='POST',expected=200){const r=await ctx.request.fetch(api+'?r='+route,{method,data});assert.equal(r.status(),expected,'Unexpected API status for '+route);return r.json();}
  await req(admin,'auth/login',{username:'testadmin',password:'EndpointTest9!'});
  const page=await admin.newPage(),verify=await guest.newPage();
  await page.goto(origin+'/admin/officer-management.php');await page.locator('#add-officer:enabled').click();
  assert.equal(await page.locator('#officer-form input[type=password]').count(),0);
  for(const width of [320,375,768,1280]){
   await page.setViewportSize({width,height:800});
   const r=await page.locator('#officer-dialog').boundingBox();assert.ok(r.x>=0&&r.x+r.width<=width+1,'Officer dialog fits '+width);
   await page.locator('#save-officer').scrollIntoViewIfNeeded();assert.ok(await page.locator('#save-officer').isVisible());
   if(width===375||width===1280)await page.screenshot({path:path.join(out,'add-officer-'+width+'.png')});
  }
  await page.locator('#officer-form [name=name]').fill('Browser Officer');await page.locator('#officer-form [name=username]').fill('browserofficer');await page.locator('#officer-form [name=email]').fill('browserofficer@example.test');
  const created=page.waitForResponse(r=>r.url().includes('management/accounts')&&r.request().method()==='POST');
  await page.locator('#save-officer').click();const response=await created;assert.equal(response.status(),201);const {id}=await response.json();
  await page.locator('#officer-dialog').waitFor({state:'hidden'});
  const row=page.locator('#accounts tr').filter({hasText:'browserofficer'});
  await row.getByRole('button',{name:'Resend Verification'}).waitFor();assert.match(await row.innerText(),/Pending Verification/);
  await row.getByRole('button',{name:'Resend Verification'}).click();await page.waitForFunction(()=>document.getElementById('toast').textContent.includes('60 seconds'));
  console.log('PASS Add Officer dialog at 320/375/768/1280; no generated password; pending listing; resend cooldown feedback');
  const link=newestLink(id),secret=link.split('#token=')[1];let leaked=false;
  verify.on('request',r=>{if(r.url().includes(secret))leaked=true;});
  const responsePage=await verify.goto(link);
  assert.match(responsePage.headers()['cache-control'],/no-store/);assert.equal(responsePage.headers()['referrer-policy'],'no-referrer');
  await verify.locator('#verification-form').waitFor();assert.equal(new URL(verify.url()).hash,'');
  for(const width of [320,375,768,1280]){
   await verify.setViewportSize({width,height:800});
   assert.ok(await verify.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Verification fits '+width);
   await verify.locator('#password').fill('SyntheticOnly9!');
   const before=await verify.locator('#toggle-password').boundingBox();await verify.locator('#toggle-password').click();const after=await verify.locator('#toggle-password').boundingBox();assert.deepEqual(after,before,'Show/hide button must not move');
   await verify.locator('#toggle-password').click();await verify.locator('#password').fill('');
   if(width===375||width===1280)await verify.screenshot({path:path.join(out,'verify-officer-'+width+'.png')});
  }
  await verify.locator('#password').fill('weak');await verify.locator('#confirm-password').fill('weak');await verify.locator('#verify-submit').click();await verify.waitForFunction(()=>document.getElementById('message').textContent.length>0);assert.ok(await verify.locator('#verification-form').isVisible());
  await verify.locator('#password').fill('BrowserPass9!');await verify.locator('#confirm-password').fill('BrowserPass9!');await verify.locator('#verify-submit').click();await verify.locator('#verification-form').waitFor({state:'hidden'});assert.equal(await verify.locator('#heading').innerText(),'Email verified');
  await verify.screenshot({path:path.join(out,'verified-success.png')});assert.equal(leaked,false,'Token never appears in HTTP request URL');
  await page.reload();await row.getByText('Email verified',{exact:true}).waitFor();
  console.log('PASS Invitation UI, weak-password feedback, stable reveal button, fragment removed, activation and verified badge');
  await verify.goto(origin+'/login.php');await verify.locator('#username').fill('browserofficer');await verify.locator('#password').fill('BrowserPass9!');await verify.locator('#login-btn').click();await verify.waitForURL('**/admin/dashboard.php');
  console.log('PASS Officer signs in with their own chosen password');
  // Existing account starts without invitation cooldown: exercise email-change UI too.
  const self=page.locator('#accounts tr').filter({hasText:'testadmin'});await self.getByRole('button',{name:'Edit officer'}).click();
  await page.locator('#officer-form [name=email]').fill('browser-new-admin@example.test');await page.locator('#save-officer').click();await page.locator('#officer-dialog').waitFor({state:'hidden'});await self.getByText(/Pending new email:/).waitFor();
  const changeLink=newestLink(1);await verify.goto(changeLink);await verify.locator('#verification-form').waitFor();assert.equal(await verify.locator('#confirmation').isVisible(),false);assert.equal(await verify.locator('#password-label').innerText(),'Current password');
  await verify.locator('#password').fill('incorrect');await verify.locator('#verify-submit').click();await verify.waitForFunction(()=>document.getElementById('message').textContent.includes('incorrect'));
  await verify.locator('#password').fill('EndpointTest9!');await verify.locator('#verify-submit').click();await verify.locator('#verification-form').waitFor({state:'hidden'});
  await page.reload();await self.getByText('Email verified',{exact:true}).waitFor();assert.match(await self.innerText(),/browser-new-admin@example.test/);
  await page.screenshot({path:path.join(out,'officer-list-verified.png')});
  await verify.goto(changeLink);await verify.waitForFunction(()=>document.getElementById('message').textContent.includes('already used'));assert.equal(await verify.locator('#verification-form').isVisible(),false);
  await verify.goto(origin+'/officer-verification.php#token=invalid');await verify.waitForFunction(()=>document.getElementById('description').textContent.includes('newest verification link'));
  console.log('PASS Email-change UI preserves old address until verification, requires current password, rejects reused/invalid links');
  assert.equal(errors.length,0);console.log('PASS No browser JavaScript errors');
 }finally{await browser.close();}
})().catch(e=>{console.error('FAIL Browser verification: '+e.message.replace(/[a-f0-9]{64}/g,'[redacted]'));process.exitCode=1;});

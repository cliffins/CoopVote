'use strict';
const assert=require('node:assert/strict'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE);
const api=process.env.COOPVOTE_TEST_URL,origin=new URL(api).origin,out=path.dirname(process.env.COOPVOTE_CANDIDATE_SCREENSHOT);
assert.equal(process.env.COOPVOTE_ENV,'test');assert.equal(new URL(api).hostname,'127.0.0.1');
const fixture=mode=>execFileSync('php',['database/tests/overview-fixture.php',mode],{env:process.env}).toString();
(async()=>{
 fixture('seed');const before=fixture('fingerprint');
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 try{
  const ctx=await browser.newContext(),guest=await browser.newContext();
  await ctx.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  async function request(c,route,data,expected=200){const r=await c.request.fetch(api+'?r='+route,{method:data?'POST':'GET',data});assert.equal(r.status(),expected,route);return r.json();}
  await request(guest,'dashboard&view=overview',null,401);
  await request(guest,'auth/login',{username:'testvoter',password:'EndpointTest9!'});await request(guest,'dashboard&view=overview',null,403);
  await request(ctx,'auth/login',{username:'testadmin',password:'EndpointTest9!'});await request(ctx,'dashboard&view=overview',{},405);
  const data=await request(ctx,'dashboard&view=overview');assert.deepEqual(data.voters,{registered:2,active:1});assert.deepEqual(data.officers,{total:6,active:2});assert.equal(data.pendingVerification,4);
  assert.deepEqual(data.attention.map(x=>[x.kind,x.count]),[['failed',1],['expired',1],['invite',1],['email_change',1]]);
  assert.ok(data.recentActivity.some(x=>x.actor==='Demo Administrator'));assert.ok(!JSON.stringify(data).includes('Private voter activity'));assert.ok(!JSON.stringify(data).includes('Private ballot activity'));
  assert.ok(!JSON.stringify(data).includes('pending_email'));assert.ok(!Object.hasOwn(data,'elections'));
  const legacy=await request(ctx,'dashboard');assert.ok(Object.hasOwn(legacy,'selectedElection'));
  console.log('PASS API counts, mutually exclusive attention categories, actor attribution, private activity exclusion, admin-only access and legacy API compatibility');
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/admin/dashboard.php?election=999999');await page.waitForFunction(()=>document.getElementById('overview-pending').textContent==='4');
  assert.equal(await page.locator('#dashboard-election').count(),0);
  for(const width of [320,375,768,1280,1920]){
   await page.setViewportSize({width,height:900});
   await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
   const overflow=await page.locator('.overview').evaluate(el=>el.scrollWidth>el.clientWidth+1);assert.equal(overflow,false,'No overview overflow at '+width);
   for(const selector of ['.ov-stats','.ov-columns','.ov-actions']){const box=await page.locator(selector).boundingBox();assert.ok(box.x>=0&&box.x+box.width<=width+1,selector+' fits at '+width);}
   await page.locator('.ov-actions').scrollIntoViewIfNeeded();assert.equal(await page.getByRole('link',{name:'Settings Branding & display preferences'}).count(),1);
   await page.locator('.overview').evaluate(el=>el.scrollTop=0);
   if([375,1280].includes(width))await page.screenshot({path:path.join(out,'admin-overview-'+width+'.png')});
  }
  console.log('PASS Responsive layout at 320/375/768/1280/1920; election selector removed; actual account data displayed');
  await page.getByRole('link',{name:'Add Officer Send an account invitation'}).click();await page.locator('#officer-dialog[open]').waitFor();assert.ok(!page.url().includes('action=add'));
  await page.goto(origin+'/admin/dashboard.php');await page.getByRole('link',{name:'Register Voter Add a voter account'}).click();await page.locator('#voter-modal.show').waitFor();
  await page.goto(origin+'/admin/dashboard.php');await page.getByRole('link',{name:'Settings Branding & display preferences'}).click();await page.waitForURL('**/admin/branding.php');
  console.log('PASS Quick Actions open Add Officer, Add Voter and Settings');
  await page.goto(origin+'/admin/dashboard.php');await page.waitForFunction(()=>document.getElementById('overview-pending').textContent==='4');
  await page.route('**/api/index.php?r=dashboard*',r=>r.fulfill({status:503,contentType:'application/json',body:JSON.stringify({error:'Synthetic service unavailable'})}));
  await page.evaluate(()=>window.dispatchEvent(new Event('online')));await page.waitForFunction(()=>!document.getElementById('dashboard-error').hidden);
  assert.match(await page.locator('#dashboard-error').innerText(),/last loaded overview/);assert.equal(await page.locator('#overview-pending').innerText(),'4');
  await page.reload();await page.waitForFunction(()=>!document.getElementById('dashboard-error').hidden);assert.equal(await page.locator('#overview-pending').innerText(),'—');
  await page.unroute('**/api/index.php?r=dashboard*');await page.evaluate(()=>window.dispatchEvent(new Event('online')));await page.waitForFunction(()=>document.getElementById('dashboard-error').hidden);
  console.log('PASS Initial failure, stale-data warning and automatic refresh recovery');
  fixture('empty');await page.evaluate(()=>window.dispatchEvent(new Event('online')));await page.waitForFunction(()=>document.getElementById('overview-pending').textContent==='0');assert.match(await page.locator('#overview-attention').innerText(),/caught up/);assert.match(await page.locator('#overview-activity').innerText(),/No administrator activity/);
  fixture('escape');await page.evaluate(()=>window.dispatchEvent(new Event('online')));await page.waitForFunction(()=>document.getElementById('overview-activity').textContent.includes('<img'));
  assert.equal(await page.locator('#overview-activity img').count(),0);
  assert.equal(fixture('fingerprint'),before);assert.deepEqual(errors,[]);
  console.log('PASS Empty state, escaped audit text, no JavaScript errors and unchanged synthetic election records');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});

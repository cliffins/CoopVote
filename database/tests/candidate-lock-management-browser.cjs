'use strict';
const assert=require('node:assert/strict'),path=require('node:path');
const {chromium}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE||'playwright');
const api=process.env.COOPVOTE_TEST_URL,origin=new URL(api).origin;
assert.equal(process.env.COOPVOTE_ENV,'test');assert.equal(new URL(api).hostname,'127.0.0.1');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  const ctx=await browser.newContext({viewport:{width:1280,height:900},reducedMotion:'reduce'});
  await ctx.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  async function req(route,method='GET',data,status=200,client=ctx){const r=await client.request.fetch(api+'?r='+route,{method,data});assert.equal(r.status(),status,await r.text());return r.json();}
  await req('auth/login','POST',{username:'testadmin',password:'EndpointTest9!'});
  await req('candidates/display-settings','PUT',{showYearSection:true});
  const date=n=>new Date(Date.now()+n*86400000).toISOString().slice(0,16);
  const title='President for Member Development and Cooperative Community Services';
  const {id}=await req('elections','POST',{title:'Position lock test',startDate:date(1),endDate:date(2),positions:[title,'Secretary']},201);
  const positions=await req('positions&election='+id),p=positions.find(x=>x.position_name===title),other=positions.find(x=>x.position_name==='Secretary');
  const members=await req('candidates/eligible');
  const {id:cid}=await req('candidates&election='+id,'POST',{memberId:members[0].id,electionId:id,positionId:p.position_id,year:'2nd Year',section:'A'},201);
  const candidate=async()=> (await req('candidates&election='+id)).find(c=>c.id===cid);
  assert.equal((await candidate()).positionLocked,false);
  await req(`candidates/${cid}&election=${id}`,'PUT',{positionId:other.position_id,status:'Active',desc:'Draft platform'});
  assert.equal((await candidate()).positionId,other.position_id);
  await req(`candidates/${cid}&election=${id}`,'PUT',{positionId:p.position_id,status:'Active',desc:'Draft platform'});
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  async function open(){await page.goto(origin+'/admin/candidate-management.php?election='+id);await page.locator('#add-candidate-btn:enabled').waitFor();await page.evaluate(id=>openEdit(id),cid);await page.locator('#save-candidate-btn:enabled').waitFor();}
  await open();assert.equal(await page.locator('#f-position').isVisible(),true);assert.equal(await page.locator('#f-position').isEnabled(),true);
  console.log('PASS draft with no participation: position remains editable in UI and API.');
  // Keep a form open while another administrator activates the election.
  await page.selectOption('#f-position',String(other.position_id));await page.fill('#f-desc','Unsaved platform kept after conflict');
  await req(`management/elections/${id}/submit`,'POST',{});
  const review=(await req('management/overview')).elections.find(e=>e.id===id).request;
  await req(`management/elections/${id}/decide`,'POST',{requestId:review.request_id,decision:'approve'});
  await req(`elections/${id}/activate`,'PATCH',{});
  const response=page.waitForResponse(r=>r.request().method()==='PUT'&&new URL(r.url()).searchParams.get('r')===`candidates/${cid}`);
  await page.locator('#save-candidate-btn').click();assert.equal((await response).status(),409);
  await page.locator('#candidate-position-value').waitFor();
  assert.equal(await page.locator('#candidate-position-value').innerText(),title);
  assert.equal(await page.locator('#f-position').isVisible(),false);assert.equal(await page.locator('#f-position').isDisabled(),true);
  assert.equal(await page.locator('#f-desc').inputValue(),'Unsaved platform kept after conflict');
  assert.equal((await candidate()).desc,'Draft platform');
  console.log('PASS activation after opening edit form: stale position change rejected; readable position restored; unsaved platform preserved.');
  const photo=await page.evaluate(()=>{const c=document.createElement('canvas');c.width=32;c.height=32;c.getContext('2d').fillRect(0,0,32,32);return c.toDataURL('image/png');});
  await page.locator('#f-photo').setInputFiles({name:'synthetic.png',mimeType:'image/png',buffer:Buffer.from(photo.split(',')[1],'base64')});
  await page.fill('#f-year','3rd Year');await page.fill('#f-section','B');await page.selectOption('#f-status','Inactive');
  await page.locator('#save-candidate-btn:enabled').waitFor();
  for(const size of [{width:320,height:640},{width:375,height:667},{width:1280,height:900}]){
   await page.setViewportSize(size);await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
   const field=page.locator('#candidate-position-value');await field.scrollIntoViewIfNeeded();
   assert.ok(await field.evaluate(el=>el.scrollWidth<=el.clientWidth+1&&getComputedStyle(el).whiteSpace!=='nowrap'),'Long locked position must wrap');
   assert.ok(await page.locator('#cand-modal .modal').evaluate(el=>el.scrollWidth<=el.clientWidth+1));
   await page.screenshot({path:path.join(path.dirname(process.env.COOPVOTE_CANDIDATE_SCREENSHOT),'locked-position-'+size.width+'.png')});
  }
  await page.locator('#save-candidate-btn').click();await page.locator('#cand-modal.show').waitFor({state:'hidden'});
  let c=await candidate();assert.equal(c.positionId,p.position_id);assert.equal(c.positionLocked,true);assert.equal(c.status,'Inactive');assert.equal(c.year,'3rd Year');assert.equal(c.desc,'Unsaved platform kept after conflict');assert.ok(c.photoUrl);
  console.log('PASS live, before any vote: readable wrapping position at 320/375/1280px; photo, year/section, platform and status save successfully.');
  for(const method of ['PUT','PATCH']){
   const before=await candidate();
   const denied=await req(`candidates/${cid}&election=${id}`,method,{positionId:other.position_id,desc:'Must not save',status:'Active'},409);
   assert.equal(denied.code,'POSITION_LOCKED');assert.deepEqual(await candidate(),before);
  }
  const denied=await req(`candidates/${cid}&election=${id}`,'PUT',{position:'Secretary',desc:'Must not save'},409);assert.equal(denied.code,'POSITION_LOCKED');
  await req(`candidates/${cid}&election=${id}`,'PUT',{positionId:p.position_id,status:'Active',desc:'Updated live platform'});
  console.log('PASS direct PUT/PATCH and legacy position-name bypasses rejected atomically; unchanged position remains saveable.');
  const voter=await browser.newContext();await req('auth/login','POST',{username:'testvoter',password:'EndpointTest9!'},200,voter);
  await req('voter/vote','POST',{electionId:id,votes:[{candidateId:cid,positionId:p.position_id}]},201,voter);
  await voter.close();
  assert.equal((await candidate()).positionLocked,true);
  const beforeElection=(await req('elections')).find(e=>e.id===id);
  await req(`candidates/${cid}&election=${id}`,'PUT',{positionId:other.position_id,desc:'Must not move voted candidate'},409);
  await req(`candidates/${cid}&election=${id}`,'PATCH',{desc:'Platform after vote',status:'Inactive'});
  assert.equal((await candidate()).positionId,p.position_id);
  assert.equal((await req('operations/overview&election='+id)).integrity.status,'passed');
  assert.equal((await req('elections')).find(e=>e.id===id).voted,beforeElection.voted);
  console.log('PASS voted candidate: position change rejected; allowed edits succeed; ballot count preserved and HMAC integrity passes.');
  await req(`elections/${id}/close`,'PATCH',{});
  assert.equal((await candidate()).positionLocked,true);
  await req(`candidates/${cid}&election=${id}`,'PATCH',{positionId:other.position_id},409);
  await open();assert.equal(await page.locator('#candidate-position-value').innerText(),title);assert.equal(await page.locator('#f-position').isVisible(),false);
  console.log('PASS closed election remains position-locked.');
  assert.deepEqual(errors,[]);console.log('PASS no JavaScript errors. All data and votes were disposable test fixtures.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

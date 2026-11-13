'use strict';
const assert=require('node:assert/strict');
const {chromium}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE||'playwright');
const api=process.env.COOPVOTE_TEST_URL,origin=new URL(api).origin;
assert.equal(process.env.COOPVOTE_ENV,'test');assert.equal(new URL(api).hostname,'127.0.0.1');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 try{
  const ctx=await browser.newContext({viewport:{width:1280,height:1000},reducedMotion:'reduce'});
  await ctx.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  async function request(route,method='GET',data,status=200,client=ctx.request){const r=await client.fetch(api+'?r='+route,{method,data});assert.equal(r.status(),status,await r.text());return r.json();}
  await request('auth/login','POST',{username:'testadmin',password:'EndpointTest9!'});
  assert.equal((await request('candidates/display-settings')).showYearSection,true);
  await request('candidates/display-settings','PUT',{showYearSection:'false'},422);
  const voter=await browser.newContext();await request('auth/login','POST',{username:'testvoter',password:'ChangedTest9!'},200,voter.request);
  await request('candidates/display-settings','PUT',{showYearSection:false},403,voter.request);
  const anon=await browser.newContext();await request('candidates/display-settings','PUT',{showYearSection:false},401,anon.request);await anon.close();
  const date=n=>new Date(Date.now()+n*86400000).toISOString().slice(0,16);
  const {id}=await request('elections','POST',{title:'Year and Section Test',startDate:date(1),endDate:date(2),positions:['President']},201);
  const position=(await request('positions&election='+id))[0];const members=await request('candidates/eligible');assert.ok(members.length>=2);
  const candidate=await request('candidates&election='+id,'POST',{electionId:id,positionId:position.position_id,memberId:members[0].id,year:'3rd Year',section:'A',department:'Legacy department',course:'Legacy course'},201);
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/admin/candidate-management.php?election='+id);
  const ready=async()=>{await page.waitForFunction(()=>!document.querySelector('#add-candidate-btn').disabled);await page.locator('#grid-btn').click();};
  const settings=await ctx.newPage();settings.on('pageerror',e=>errors.push(e.message));
  await settings.goto(origin+'/admin/branding.php');await settings.locator('#show-year-section:enabled').waitFor();
  assert.equal(await settings.locator('#heading').innerText(),'Settings');
  assert.equal(await settings.locator('[data-cv-nav="branding"] span').innerText(),'Settings');
  async function setDisplay(visible){
   await settings.locator('#show-year-section').setChecked(visible);
   await settings.locator('#candidate-display-save').click();
   await settings.waitForFunction(()=>!document.querySelector('#show-year-section').disabled&&document.querySelector('#candidate-display-save').disabled);
   assert.equal((await request('candidates/display-settings')).showYearSection,visible);
   await page.reload();await ready();
  }
  await ready();
  assert.equal(await page.locator('#f-department, #f-course, #show-year-section, #candidate-display-form').count(),0);
  assert.ok((await page.locator('.candidate-academic-label').innerText()).includes('3rd Year · Section A'));
  if(process.env.COOPVOTE_CANDIDATE_DISPLAY_SCREENSHOT){
   await page.locator('#add-candidate-btn').click();await page.locator('#f-member:enabled').waitFor();
   await page.waitForFunction(()=>[...document.querySelectorAll('#toast-wrap .toast, #cv-toast-wrap > *')].every(el=>getComputedStyle(el).display==='none'));
   for(const width of [375,1280]){
    await page.setViewportSize({width,height:900});
    await page.locator('#cand-modal .modal').screenshot({path:process.env.COOPVOTE_CANDIDATE_DISPLAY_SCREENSHOT.replace('.png',`-add-${width}.png`)});
   }
   await page.locator('#cand-modal .modal-close').click();
  }
  await page.locator('.edit-btn').click();await page.waitForFunction(()=>!document.querySelector('#save-candidate-btn').disabled);
  assert.equal(await page.locator('#candidate-year-section-fields legend').innerText(),'Year & Section');
  assert.equal(await page.locator('#f-year').inputValue(),'3rd Year');assert.equal(await page.locator('#f-section').inputValue(),'A');
  for(const width of [320,375,1280]){
   await page.setViewportSize({width,height:1000});
   assert.ok(await page.locator('#cand-modal .modal').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Form overflow '+width);
  }
  if(process.env.COOPVOTE_CANDIDATE_DISPLAY_SCREENSHOT)await page.locator('#cand-modal .modal').screenshot({path:process.env.COOPVOTE_CANDIDATE_DISPLAY_SCREENSHOT.replace('.png','-form.png')});
  await page.locator('#cand-modal .modal-close').click();
  // Saving the display preference must not discard a draft identity change.
  const identity=await request('branding');
  await settings.fill('#branding-text','Unsaved identity draft');
  await setDisplay(false);
  assert.equal(await settings.locator('#branding-text').inputValue(),'Unsaved identity draft');
  assert.equal((await request('branding')).text,identity.text);
  await settings.fill('#branding-text',identity.text);
  let saved=(await request('candidates&election='+id)).find(c=>c.id===candidate.id);
  assert.equal(saved.year,'');assert.equal(saved.section,'');
  assert.equal(await page.locator('.candidate-academic-label').count(),0);
  await page.locator('#list-btn').click();assert.equal(await page.locator('th.candidate-academic-column').isVisible(),false);
  await page.locator('#grid-btn').click();await page.locator('.view-btn[onclick^="viewCandidate"]').click();
  assert.ok(!(await page.locator('#view-body').innerText()).includes('Year & Section'));
  await page.locator('#view-modal .modal-close').click();
  await page.locator('.edit-btn').click();await page.waitForFunction(()=>!document.querySelector('#save-candidate-btn').disabled);
  assert.equal(await page.locator('#candidate-year-section-fields').isVisible(),false);
  await page.fill('#f-desc','Edit while hidden');await page.locator('#save-candidate-btn').click();await page.locator('#cand-modal.show').waitFor({state:'hidden'});await ready();
  // Hidden fields sent by a stale client must not overwrite stored values.
  await request('candidates/'+candidate.id+'&election='+id,'PUT',{year:'Overwrite',section:'Overwrite',desc:'Preserve original academic values'});
  await page.locator('#add-candidate-btn').click();await page.waitForFunction(()=>!document.querySelector('#f-member').disabled);
  await page.selectOption('#f-member',String(members[1].id));await page.locator('#f-position:enabled').waitFor();await page.selectOption('#f-position',String(position.position_id));
  await page.locator('#save-candidate-btn').click();await page.locator('#cand-modal.show').waitFor({state:'hidden'});await ready();
  assert.equal((await request('candidates&election='+id)).length,2);
  const csv=await ctx.request.get(api+'?r=candidates/export&election='+id);const hiddenCsv=await csv.text();
  assert.ok(!hiddenCsv.includes('Year & Section')&&!hiddenCsv.includes('3rd Year')&&!hiddenCsv.includes('Department')&&!hiddenCsv.includes('Course'));
  await settings.reload();await settings.locator('#show-year-section:enabled').waitFor();assert.equal(await settings.locator('#show-year-section').isChecked(),false);
  if(process.env.COOPVOTE_CANDIDATE_DISPLAY_SCREENSHOT)await settings.locator('#candidate-display-form').screenshot({path:process.env.COOPVOTE_CANDIDATE_DISPLAY_SCREENSHOT.replace('.png','-setting.png')});
  for(const width of [320,375,1280]){
   await settings.setViewportSize({width,height:1000});
   assert.ok(await settings.locator('#candidate-display-form').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Settings overflow '+width);
  }
  await setDisplay(true);
  saved=(await request('candidates&election='+id)).find(c=>c.id===candidate.id);
  assert.equal(saved.year,'3rd Year');assert.equal(saved.section,'A');assert.equal(saved.department,'Legacy department');assert.equal(saved.course,'Legacy course');
  const restored=await (await ctx.request.get(api+'?r=candidates/export&election='+id)).text();assert.ok(restored.includes('3rd Year · Section A'));
  // Exercise voter DTO suppression against a real open election.
  await request(`management/elections/${id}/submit`,'POST',{});
  const review=(await request('management/overview')).elections.find(e=>e.id===id).request;
  await request(`management/elections/${id}/decide`,'POST',{requestId:review.request_id,decision:'approve'});await request(`elections/${id}/activate`,'PATCH',{});
  const visibleBallot=await request('voter/ballot&election='+id,'GET',undefined,200,voter.request);
  assert.ok(JSON.stringify(visibleBallot).includes('3rd Year'),'Fixture must expose the candidate when display is enabled');
  await request('candidates/display-settings','PUT',{showYearSection:false});
  const ballot=await request('voter/ballot&election='+id,'GET',undefined,200,voter.request);
  assert.ok(!JSON.stringify(ballot).includes('3rd Year'),'Hidden academic values must not appear in voter DTO');
  await request('candidates/display-settings','PUT',{showYearSection:true});
  assert.deepEqual(errors,[]);
  console.log('Candidate academic display PASS: grouped atomic fields; Department/Course removed from UI and CSV; persistent admin-only setting on renamed Settings page; hidden form/card/profile/table/API/CSV; hidden create/edit; stale writes ignored; stored values restored; legacy data preserved; voter DTO suppression; mobile/desktop layout.');
  await voter.close();await ctx.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

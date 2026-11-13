'use strict';
const assert=require('node:assert/strict');
const {chromium}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE || 'playwright');
const api=process.env.COOPVOTE_TEST_URL, origin=new URL(api).origin;
assert.equal(process.env.COOPVOTE_ENV,'test');assert.equal(new URL(api).hostname,'127.0.0.1');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 try {
  const ctx=await browser.newContext({viewport:{width:1280,height:1000},reducedMotion:'reduce'});
  await ctx.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  async function request(route,method='GET',data,status=200){const r=await ctx.request.fetch(api+'?r='+route,{method,data});assert.equal(r.status(),status,await r.text());return r.json();}
  await request('auth/login','POST',{username:'testadmin',password:'EndpointTest9!'});
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/admin/voter-management.php');await page.locator('#add-voter-btn:enabled').click();
  await page.locator('#f-memberid').pressSequentially('MEM-0123');assert.equal(await page.locator('#f-memberid').inputValue(),'MEM-0123','Member ID input restored');
  const dates=n=>new Date(Date.now()+n*86400000).toISOString().slice(0,16);
  const other=await request('elections','POST',{title:'Other election',startDate:dates(2),endDate:dates(3),positions:['Secretary']},201);
  await page.goto(origin+'/admin/election-management.php?election='+other.id);
  async function ready(id){await page.waitForFunction(id=>document.querySelector('#election-select').value===String(id)&&!document.querySelector('#election-select').disabled&&!!document.querySelector(`.ec[data-election-id="${id}"]`),id);}
  await ready(other.id);
  assert.equal(await page.locator('#active-election-select, #positions-election-select').count(),0);
  assert.equal(await page.getByRole('button',{name:'Create Election',exact:true}).count(),1);
  assert.equal(await page.getByRole('button',{name:'Add Position',exact:true}).count(),1);
  assert.ok((await page.locator('.position-reason').innerText()).includes('Keep at least one'));
  await page.getByRole('button',{name:'Create Election',exact:true}).click();
  await page.locator('#election-next').click();
  assert.equal(await page.locator('#election-positions-step').isVisible(),false);
  await page.fill('#f-title','Cooperative election <test>');
  await page.fill('#f-desc','Synthetic preview election');
  await page.fill('#f-start',dates(2));await page.fill('#f-end',dates(1));
  await page.locator('#election-next').click();
  assert.equal(await page.locator('#election-positions-step').isVisible(),false);
  await page.fill('#f-end',dates(3));await page.locator('#election-next').click();
  assert.equal(await page.locator('#election-details-step').isVisible(),false);
  const count=(await request('elections')).length;
  await page.locator('#election-save').click();
  assert.equal((await request('elections')).length,count,'No empty-position election should be created');
  await page.locator('#pos-checklist label').filter({hasText:/^President$/}).locator('input').check();
  await page.locator('#custom-position-name').pressSequentially('Board1 <Member>');
  assert.equal(await page.locator('#custom-position-name').inputValue(),'Board Member');
  await page.getByRole('button',{name:'Include',exact:true}).click();
  await page.fill('#custom-position-name','board member');await page.getByRole('button',{name:'Include',exact:true}).click();
  assert.equal(await page.locator('#pos-checklist input:checked').count(),2);
  assert.equal(await page.locator('#pos-checklist member, #election-review test').count(),0);
  assert.ok((await page.locator('#election-review').innerText()).includes('Cooperative election <test>'));
  await page.locator('#election-back').click();assert.equal(await page.locator('#f-title').inputValue(),'Cooperative election <test>');
  await page.locator('#election-next').click();
  await page.setViewportSize({width:375,height:1000});
  assert.ok(await page.locator('#election-modal .modal').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Review modal overflow');
  await page.setViewportSize({width:1280,height:1000});
  if(process.env.COOPVOTE_ELECTION_SCREENSHOT) {
   await page.waitForTimeout(3600);
   await page.locator('#election-modal .modal').screenshot({path:process.env.COOPVOTE_ELECTION_SCREENSHOT.replace('.png','-review.png')});
  }
  const created=page.waitForResponse(r=>new URL(r.url()).searchParams.get('r')==='elections'&&r.request().method()==='POST');
  await page.locator('#election-save').click();const response=await created;assert.equal(response.status(),201);const id=(await response.json()).id;
  await page.locator('#election-modal.show').waitFor({state:'hidden'});await ready(id);
  let elections=await request('elections'), e=elections.find(e=>e.id===id);
  assert.equal(e.status,'Draft');assert.equal(e.positions,2);assert.equal(elections.find(e=>e.id===other.id).positions,1);
  assert.equal(await page.locator('.ec').count(),1);assert.equal(await page.locator('.position-table tbody tr').count(),2);
  assert.equal(await page.locator('.pos-avatars, .pos-bar, .pos-pct').count(),0);
  assert.ok((await page.locator('#positions-sub').innerText()).includes(e.title));
  assert.equal(new URL(page.url()).searchParams.get('election'),String(id));
  await page.getByRole('button',{name:'Edit Details',exact:true}).click();
  assert.equal(await page.locator('#election-positions-step').isVisible(),false);
  assert.equal(await page.locator('#election-next').isVisible(),false);
  await page.fill('#f-desc','Updated details only');await page.locator('#election-save').click();
  await page.locator('#election-modal.show').waitFor({state:'hidden'});await ready(id);
  e=(await request('elections')).find(e=>e.id===id);assert.equal(e.positions,2);assert.equal(e.description,'Updated details only');
  await page.locator('#add-position-btn').click();assert.equal(await page.locator('#p-election').inputValue(),String(id));assert.equal(await page.locator('#p-election').isDisabled(),true);
  await page.locator('#p-name').pressSequentially('Auditor12!');assert.equal(await page.locator('#p-name').inputValue(),'Auditor');
  await page.locator('#p-name').evaluate(el=>{el.select();const data=new DataTransfer();data.setData('text','Board 123@Member');el.dispatchEvent(new ClipboardEvent('paste',{clipboardData:data,bubbles:true,cancelable:true}));});
  assert.equal(await page.locator('#p-name').inputValue(),'Board Member');
  await page.fill('#p-name','Auditor');await page.locator('#position-save').click();
  await page.locator('#position-modal.show').waitFor({state:'hidden'});await ready(id);
  assert.equal(await page.locator('.position-table tbody tr').count(),3);
  await page.locator('#add-position-btn').click();await page.fill('#p-name','Auditor');await page.locator('#position-save').click();
  await page.waitForFunction(()=>!document.querySelector('#position-save').disabled);
  assert.equal(await page.locator('#position-modal.show').count(),1);
  await page.locator('#position-modal .modal-close').click();
  const auditor=(await request('positions&election='+id)).find(p=>p.position_name==='Auditor');
  await page.locator(`[data-position-id="${auditor.position_id}"] button`).click();
  await page.locator('#delete-position-modal.show').waitFor();
  const confirmation=await page.locator('#delete-position-message').textContent();
  assert.ok(confirmation.includes('Cooperative election <test>'),confirmation);
  await page.locator('#delete-position-save').click();await page.locator('#delete-position-modal.show').waitFor({state:'hidden'});
  assert.equal(await page.locator('.position-table tbody tr').count(),2);
  // Candidate count and disabled deletion use real data.
  const member=(await request('candidates/eligible'))[0], position=(await request('positions&election='+id))[0];
  await request('candidates&election='+id,'POST',{electionId:id,positionId:position.position_id,memberId:member.id},201);
  await page.reload();await ready(id);
  assert.equal(await page.locator(`[data-position-id="${position.position_id}"] button`).isDisabled(),true);
  assert.ok((await page.locator(`[data-position-id="${position.position_id}"]`).innerText()).includes('1 candidate'));
  await page.waitForTimeout(5500);
  for(const width of [320,375,768,1280]){
   await page.setViewportSize({width,height:1000});
   assert.ok(await page.locator('.content').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Content overflow '+width);
   // Small screens retain a native table; horizontal scrolling is intentional.
   assert.ok(await page.locator('.position-table-scroll').evaluate(el=>{
    const box=el.getBoundingClientRect();el.scrollLeft=el.scrollWidth;
    const table=el.querySelector('table'),last=table.querySelector('tbody tr').lastElementChild.getBoundingClientRect();
    return box.left>=-1&&box.right<=innerWidth+1&&last.right<=box.right+1&&getComputedStyle(table.querySelector('tbody tr')).display==='table-row';
   }),'Position table contained and Action reachable '+width);
   if(process.env.COOPVOTE_ELECTION_SCREENSHOT){
    await page.locator('.content').evaluate(el=>el.scrollTop=0);
    await page.screenshot({path:process.env.COOPVOTE_ELECTION_SCREENSHOT.replace('.png','-'+width+'.png')});
    if(width===375){await page.locator('.position-table').scrollIntoViewIfNeeded();await page.screenshot({path:process.env.COOPVOTE_ELECTION_SCREENSHOT.replace('.png','-positions-mobile.png')});}
   }
  }
  await page.selectOption('#election-select',String(other.id));await ready(other.id);
  assert.equal(await page.locator('.position-table tbody tr').count(),1);
  assert.ok((await page.locator('#positions-sub').innerText()).includes('Other election'));
  await page.locator('#add-position-btn').click();assert.equal(await page.locator('#p-election').inputValue(),String(other.id));await page.locator('#position-modal .modal-close').click();
  // Open a synthetic election through its real approval workflow, then close in UI.
  const otherPos=(await request('positions&election='+other.id))[0];
  await request('candidates&election='+other.id,'POST',{electionId:other.id,positionId:otherPos.position_id,memberId:member.id},201);
  await request(`management/elections/${other.id}/submit`,'POST',{});
  const review=(await request('management/overview')).elections.find(e=>e.id===other.id).request;
  await request(`management/elections/${other.id}/decide`,'POST',{requestId:review.request_id,decision:'approve'});
  // An edit opened while Draft must also be rejected after activation.
  await page.getByRole('button',{name:'Edit Details',exact:true}).click();
  await page.fill('#f-title','Stale edit must not save');
  await request(`elections/${other.id}/activate`,'PATCH',{});
  const stale=page.waitForResponse(r=>new URL(r.url()).searchParams.get('r')===`elections/${other.id}`&&r.request().method()==='PUT');
  await page.locator('#election-save').click();assert.equal((await stale).status(),409);
  await page.reload();await ready(other.id);
  const before=(await request('elections')).find(e=>e.id===other.id);
  const details={title:before.title,description:before.description||'',startDate:before.startISO,endDate:before.endISO};
  for(const method of ['PUT','PATCH']){
   for(const change of [{title:'Blocked title'},{description:'Blocked description'},{startDate:dates(4),endDate:dates(5)},{endDate:dates(5)}]){
    const denied=await request(`elections/${other.id}`,method,{...details,...change},409);
    assert.ok(denied.error.includes('locked while voting is live'));
   }
  }
  assert.deepEqual((await request('elections')).find(e=>e.id===other.id),before,'Rejected live edits leave the election unchanged');

  assert.equal(await page.locator('#hero-card').isVisible(),true);assert.equal(await page.locator('#election-qr canvas').count(),1);
  assert.equal(new URL(await page.locator('#election-qr canvas').getAttribute('data-url')).searchParams.get('election'),String(other.id));
  assert.ok((await page.locator('[data-qr-download]').getAttribute('href')).startsWith('data:image/png'));
  for(const width of [375,1280]){
   await page.setViewportSize({width,height:1000});
   const layout=await page.locator('#election-overview').evaluate(el=>{
    const card=el.querySelector('#elections-grid').getBoundingClientRect(),qr=el.querySelector('#hero-card').getBoundingClientRect();
    return {overflow:el.scrollWidth>el.clientWidth+1,side:qr.left>=card.right&&Math.abs(qr.top-card.top)<2,stacked:qr.top>=card.bottom};
   });
   assert.equal(layout.overflow,false);assert.ok(width>=1100?layout.side:layout.stacked,'Live election and QR arrangement '+width);
   if(process.env.COOPVOTE_ELECTION_SCREENSHOT){await page.locator('.content').evaluate(el=>el.scrollTop=0);await page.screenshot({path:process.env.COOPVOTE_ELECTION_SCREENSHOT.replace('.png',`-live-${width}.png`)});}
  }

  assert.equal(await page.getByRole('button',{name:'Edit Details',exact:true}).isDisabled(),true);
  await page.evaluate(id=>openEditId(id),other.id);assert.equal(await page.locator('#election-modal').isVisible(),false);
  assert.ok((await page.locator('#elections-grid').innerText()).includes('Election details are locked while voting is live.'));
  await page.locator('#elections-grid').getByRole('button',{name:'Close',exact:true}).click();
  await page.locator('button[onclick="confirmCloseVoting()"]').click();
  await page.waitForFunction(()=>document.querySelector('#elections-grid .ec-status')?.textContent==='Closed');
  assert.equal(await page.getByRole('button',{name:'Edit Details',exact:true}).isDisabled(),true);
  assert.equal(await page.locator('#hero-card').isVisible(),false);
  assert.ok((await page.locator('.position-reason').innerText()).includes('closed'));
  assert.ok((await page.locator('#elections-grid').getByRole('link',{name:'Results',exact:true}).getAttribute('href')).includes('election='+other.id));
  // Failed loads do not expose stale editing controls; retry restores selection.
  await page.route('**/api/index.php*',r=>new URL(r.request().url()).searchParams.get('r')==='elections'?r.fulfill({status:503,json:{error:'Synthetic load failure'}}):r.continue());
  await page.reload();await page.locator('#election-retry:not([hidden])').waitFor();
  assert.equal(await page.locator('.ec').count(),0);assert.equal(await page.locator('#add-position-btn').isDisabled(),true);
  await page.unroute('**/api/index.php*');await page.locator('#election-retry').click();await ready(other.id);
  // Simulated empty inventory is a distinct, non-error state.
  await page.route('**/api/index.php*',r=>new URL(r.request().url()).searchParams.get('r')==='elections'?r.fulfill({json:[]}):r.continue());
  await page.reload();await page.waitForFunction(()=>document.querySelector('#election-select').textContent==='No elections available');
  assert.equal(await page.locator('#add-position-btn').isDisabled(),true);assert.equal(await page.getByRole('button',{name:'Create Election',exact:true}).isEnabled(),true);
  assert.deepEqual(errors,[]);
  console.log('Election Management Chromium PASS: one selector, scoped details/positions, two-step creation and validation, custom positions, atomic draft creation, details-only editing, add/delete/duplicate positions, deletion reasons, selection persistence, active QR, live details locked in UI and PUT/PATCH, stale edit rejected after activation, rejected writes preserve election data, close controls, failure/retry, simulated empty inventory, 320/375/768/1280px layout.');
  await ctx.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

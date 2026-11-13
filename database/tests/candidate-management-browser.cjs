'use strict';
const assert = require('node:assert/strict');
const {chromium} = require(process.env.COOPVOTE_PLAYWRIGHT_MODULE || 'playwright');
const api = process.env.COOPVOTE_TEST_URL;
assert.equal(process.env.COOPVOTE_ENV, 'test');
assert.equal(new URL(api).hostname, '127.0.0.1');
const origin = new URL(api).origin;

(async () => {
  const browser = await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
  try {
    const context = await browser.newContext({viewport:{width:1280,height:1000},reducedMotion:'reduce'});
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    async function request(route, method='GET', data, expected=200) {
      const result=await context.request.fetch(api+'?r='+route,{method,data});
      assert.equal(result.status(),expected,await result.text());
      return result.json();
    }
    await request('auth/login','POST',{username:'testadmin',password:'EndpointTest9!'});
    const now=Date.now();
    const details={startDate:new Date(now+86400000).toISOString().slice(0,16),endDate:new Date(now+172800000).toISOString().slice(0,16),positions:['President','Secretary','Treasurer']};
    const {id}=await request('elections','POST',{...details,title:'Candidate setup <test> election'},201);
    const other=await request('elections','POST',{...details,title:'Other candidate election'},201);
    const positions=await request('positions&election='+id);
    const president=positions.find(p=>p.position_name==='President');
    const secretary=positions.find(p=>p.position_name==='Secretary');
    const treasurer=positions.find(p=>p.position_name==='Treasurer');
    const members=await request('candidates/eligible');
    assert.ok(members.length>=2);
    const academic={section:'A',year:'2nd Year'};
    const first=await request('candidates&election='+id,'POST',{electionId:id,positionId:president.position_id,memberId:members[0].id,...academic},201);
    const page=await context.newPage();
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    const candidateRequests=[];
    page.on('request',r=>{const u=new URL(r.url());if(u.searchParams.get('r')==='candidates'&&r.method()==='GET') candidateRequests.push(u.searchParams.get('election'));});
    const elections=await request('elections');
    const defaultId=String((elections.find(e=>e.status==='Active')||elections[0]).id);
    await page.goto(origin+'/admin/candidate-management.php');
    await page.waitForFunction(()=>!document.querySelector('#add-candidate-btn').disabled);
    assert.equal(await page.locator('#election-filter').inputValue(),defaultId);
    assert.equal(await page.locator('#election-filter option[value=""]').count(),0);
    assert.equal(new URL(page.url()).searchParams.get('election'),defaultId);
    assert.equal(await page.locator('#cand-tbody .t-name').count(),defaultId===String(id)?1:0);
    await page.selectOption('#election-filter',String(id));
    await page.waitForFunction(()=>document.querySelector('#sc-total').textContent==='1');
    assert.equal(await page.locator('#list-view').isVisible(),true);
    assert.equal(await page.locator('#grid-view').isVisible(),false);
    assert.equal(await page.locator('#cand-tbody tr').count(),1);
    assert.equal(await page.locator('#candidate-election-title').innerText(),'Candidate setup <test> election');
    assert.equal(await page.locator('#cand-tbody test').count(),0);
    assert.equal(await page.locator('#add-candidate-btn').isDisabled(),false);
    // Editing keeps the selected election context.
    await page.locator(`#cand-tbody button[onclick="openEdit(${first.id})"]`).click();
    await page.waitForFunction(()=>!document.querySelector('#save-candidate-btn').disabled);
    assert.equal(await page.locator('#f-election').inputValue(),String(id));
    await page.fill('#f-desc','Edited from the default directory');
    await page.locator('#save-candidate-btn').click();
    await page.locator('#cand-modal.show').waitFor({state:'hidden'});
    assert.equal((await request('candidates&election='+id))[0].desc,'Edited from the default directory');
    assert.equal((await request('candidates&election='+other.id)).length,0);
    await page.selectOption('#election-filter',String(id));
    await page.locator('#grid-btn').click();
    async function ready(expected) { await page.waitForFunction(title=>document.querySelector('#candidate-election-title').textContent===title && !document.querySelector('#add-candidate-btn').disabled,expected); }
    async function summary(values) {
      assert.deepEqual(await page.locator('#sc-total, #sc-positions, #sc-active, #sc-empty').allTextContents(),values.map(String));
    }
    await ready('Candidate setup <test> election');
    assert.equal(await page.locator('#candidate-election-title test').count(),0);
    assert.equal(await page.locator('.position-group').count(),3);
    await summary([1,1,1,2]);
    assert.ok((await page.locator(`[data-position-id="${secretary.position_id}"]`).innerText()).includes('No candidates yet for Secretary'));
    await page.selectOption('#pos-filter',String(treasurer.position_id));
    assert.equal(await page.locator('.position-group').count(),1);
    assert.equal(await page.locator('.cand-card').count(),0);
    await summary([1,1,1,2]);
    await page.selectOption('#pos-filter','');

    // Only the toolbar offers Add Candidate; the form keeps the chosen position.
    assert.equal(await page.getByRole('button',{name:'Add Candidate',exact:true}).count(),1);
    assert.equal(await page.locator('.position-group button[onclick^="openAdd"]').count(),0);
    await page.locator('#add-candidate-btn').click();
    await page.locator('#f-position:enabled').waitFor();
    await page.selectOption('#f-position',String(secretary.position_id));
    await page.waitForFunction(pid=>document.querySelector('#f-position').value===String(pid),secretary.position_id);
    assert.equal(await page.locator('#f-election').inputValue(),String(id));
    await page.selectOption('#f-member',String(members[1].id));
    await page.waitForFunction(pid=>document.querySelector('#f-position').value===String(pid) && !document.querySelector('#save-candidate-btn').disabled,secretary.position_id);
    for (const [field,value] of Object.entries(academic)) await page.locator('#f-'+field).fill(value);
    await page.locator('#f-desc').fill('Synthetic candidate profile');
    await page.locator('#save-candidate-btn').click();
    await page.locator('#cand-modal.show').waitFor({state:'hidden'});
    await page.waitForFunction(()=>document.querySelector('#sc-total').textContent==='2');
    await summary([2,2,2,1]);
    const saved=(await request('candidates&election='+id)).find(c=>c.memberId===members[1].id);
    assert.equal(saved.positionId,secretary.position_id);
    assert.equal((await request('candidates&election='+other.id)).length,0);

    await page.locator(`[data-position-id="${secretary.position_id}"] .edit-btn`).click();
    await page.waitForFunction(()=>!document.querySelector('#save-candidate-btn').disabled);
    await page.selectOption('#f-status','Inactive');
    await page.locator('#save-candidate-btn').click();
    await page.locator('#cand-modal.show').waitFor({state:'hidden'});
    await page.waitForFunction(()=>document.querySelector('#sc-active').textContent==='1');
    await summary([2,2,1,1]);
    assert.ok((await page.locator(`[data-position-id="${secretary.position_id}"]`).innerText()).includes('No active candidates'));
    await page.selectOption('#status-filter','Active');
    assert.equal(await page.locator('.cand-card').count(),1);
    await page.selectOption('#status-filter','');
    await page.fill('#search-input','Treasurer');
    assert.equal(await page.locator('.position-group').count(),1);
    await page.fill('#search-input','');
    await page.locator('#list-btn').click();
    assert.equal(await page.locator('#cand-tbody tr').count(),2);
    await page.locator('#grid-btn').click();

    // Let the real success/notification toasts expire before review screenshots.
    await page.waitForTimeout(6000);
    for (const width of [320,375,768,1280]) {
      await page.setViewportSize({width,height:1000});
      assert.ok(await page.locator('.content').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Content overflows at '+width);
      assert.ok(await page.locator('.position-group').evaluateAll(els=>els.every(el=>el.scrollWidth<=el.clientWidth+1)),'Position group overflows at '+width);
      if(process.env.COOPVOTE_CANDIDATE_SCREENSHOT) {
        await page.locator('.content').evaluate(el=>el.scrollTop=0);
        await page.screenshot({path:process.env.COOPVOTE_CANDIDATE_SCREENSHOT.replace('.png','-'+width+'.png')});
        if(width===1280 || width===375) {
          await page.locator(`[data-position-id="${secretary.position_id}"]`).scrollIntoViewIfNeeded();
          await page.screenshot({path:process.env.COOPVOTE_CANDIDATE_SCREENSHOT.replace('.png','-positions-'+width+'.png')});
        }
      }
    }
    await page.locator(`[data-position-id="${president.position_id}"] .del-btn`).click();
    assert.ok((await page.locator('#candidate-remove-message').innerText()).includes('President in Candidate setup <test> election'));
    assert.ok((await page.locator('#candidate-remove-message').innerText()).includes('preserved'));
    await page.locator('#confirm-modal .btn-ghost').click();
    assert.equal((await request('candidates&election='+id)).length,2);
    await page.locator(`[data-position-id="${president.position_id}"] .del-btn`).click();
    await page.locator('#confirm-modal .btn-red').click();
    await page.waitForFunction(()=>document.querySelector('#sc-total').textContent==='1');
    assert.ok(!(await request('candidates&election='+id)).some(c=>c.id===first.id));
    await summary([1,1,0,2]);

    await page.selectOption('#election-filter',String(other.id));
    await ready('Other candidate election');
    await summary([0,0,0,3]);
    assert.equal(await page.locator('.cand-card').count(),0);
    // A delayed response from an earlier election cannot replace the new one.
    await page.route('**/api/index.php*',async route=>{
      const url=new URL(route.request().url());
      if(url.searchParams.get('r')==='candidates' && url.searchParams.get('election')===String(id)) await new Promise(r=>setTimeout(r,500));
      return route.continue();
    });
    const delayedRequest=page.waitForRequest(req=>{
      const url=new URL(req.url());
      return url.searchParams.get('r')==='candidates' && url.searchParams.get('election')===String(id);
    });
    await page.selectOption('#election-filter',String(id));
    await delayedRequest;
    await page.selectOption('#election-filter',String(other.id));
    await page.waitForTimeout(800);
    await ready('Other candidate election'); await summary([0,0,0,3]);
    await page.unroute('**/api/index.php*');
    // Failed loading shows neither stale candidates nor a misleading zero summary.
    await page.route('**/api/index.php*',route=>new URL(route.request().url()).searchParams.get('r')==='candidates'
      ? route.fulfill({status:503,json:{error:'Synthetic candidate load failure'}}) : route.continue());
    await page.selectOption('#election-filter',String(id));
    await page.locator('#candidate-retry:not([hidden])').waitFor();
    assert.equal(await page.locator('#add-candidate-btn').isDisabled(),true);
    assert.equal(await page.locator('.cand-card').count(),0);
    await summary(['—','—','—','—']);
    await page.unroute('**/api/index.php*');
    await page.locator('#candidate-retry').click(); await ready('Candidate setup <test> election');
    await summary([1,1,0,2]);
    // Simulate a legacy/empty position response: normal API protects the last position.
    const emptyPositions=(await request('elections')).map(e=>e.id===other.id ? {...e,positions:0,positionList:[]} : e);
    await page.route('**/api/index.php*',route=>new URL(route.request().url()).searchParams.get('r')==='elections'
      ? route.fulfill({json:emptyPositions}) : route.continue());
    await page.selectOption('#election-filter',String(other.id));
    await page.waitForFunction(()=>document.querySelector('#candidate-setup-hint').textContent.includes('Add positions in Election Management'));
    assert.equal(await page.locator('#add-candidate-btn').isDisabled(),true);
    assert.equal(await page.locator('.position-group').count(),0);
    await summary([0,0,0,0]);
    await page.unroute('**/api/index.php*');
    await page.selectOption('#election-filter',String(id));
    await ready('Candidate setup <test> election');
    await page.locator('#list-btn').click();
    await page.fill('#search-input','Candidate setup');
    assert.equal(await page.locator('#cand-tbody .t-name').count(),1);
    await page.fill('#search-input','Other candidate election');
    assert.equal(await page.locator('#cand-tbody .t-name').count(),0);
    await page.fill('#search-input','');
    await page.waitForTimeout(6000);
    await page.locator('#search-input').blur();
    for(const width of [320,375,768,1280]) {
      await page.setViewportSize({width,height:1000});
      assert.ok(await page.locator('.content').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Directory overflow '+width);
      if(process.env.COOPVOTE_CANDIDATE_SCREENSHOT) {
        await page.locator('.content').evaluate(el=>el.scrollTop=0);
        await page.screenshot({path:process.env.COOPVOTE_CANDIDATE_SCREENSHOT.replace('.png','-directory-'+width+'.png')});
      }
    }
    const removal=page.waitForRequest(r=>r.method()==='DELETE');
    await page.locator(`#cand-tbody button[onclick="askDelete(${saved.id})"]`).click();
    await page.locator('#confirm-modal .btn-red').click();
    assert.equal(new URL((await removal).url()).searchParams.get('election'),String(id));
    await page.waitForFunction(()=>document.querySelector('#sc-total').textContent==='0');
    assert.equal((await request('candidates&election='+id)).length,0);
    assert.equal((await request('elections')).length,2);
    assert.equal(await page.locator('#add-candidate-btn').isDisabled(),false);
    // A populated different election must never appear in the selected empty election.
    const otherPosition=(await request('positions&election='+other.id))[0];
    await request('candidates&election='+other.id,'POST',{electionId:other.id,positionId:otherPosition.position_id,memberId:members[0].id,...academic},201);
    await page.reload();await ready('Candidate setup <test> election');
    await summary([0,0,0,3]);
    assert.equal(await page.locator('#cand-tbody .t-name').count(),0);
    await page.selectOption('#election-filter',String(other.id));await ready('Other candidate election');
    await summary([1,1,1,2]);
    assert.equal(await page.locator('#cand-tbody .t-name').count(),1);
    assert.equal(await page.locator('#candidate-election-title').innerText(),'Other candidate election');
    assert.ok(candidateRequests.length>0 && candidateRequests.every(id=>id && id!=='0'),'Every candidate GET must include an election');
    // No elections means no candidate request and no combined list.
    const beforeEmpty=candidateRequests.length;
    await page.route('**/api/index.php*',route=>new URL(route.request().url()).searchParams.get('r')==='elections'?route.fulfill({json:[]}):route.continue());
    await page.reload();
    await page.waitForFunction(()=>document.querySelector('#candidate-election-title').textContent==='No elections available');
    assert.equal(await page.locator('#election-filter').isDisabled(),true);
    assert.equal(await page.locator('#add-candidate-btn').isDisabled(),true);
    assert.equal(await page.locator('#cand-tbody .t-name').count(),0);
    assert.equal(candidateRequests.length,beforeEmpty);
    assert.deepEqual(errors,[]);
    console.log('Candidate Management Chromium PASS: default single election list, no All elections option, all candidate GETs scoped, no-election empty state, populated/empty election isolation, selected election heading, restored design, scoped edit/remove, directory search, single toolbar Add Candidate, real API create/edit/remove, election isolation, grouped and empty positions, zero-position guidance, setup counts, chosen position retained, search/status/position filters, list toggle, safe confirmation, escaping, 320/375/768/1280px layout, switching, failure and retry.');
    await context.close();
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});

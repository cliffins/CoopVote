// Historical election-dashboard checks. Superseded by overview-management-browser.cjs on 2026-11-12.
'use strict';
const assert = require('node:assert/strict');
const {chromium} = require(process.env.COOPVOTE_PLAYWRIGHT_MODULE || 'playwright');
const api = process.env.COOPVOTE_TEST_URL;
assert.equal(new URL(api).hostname, '127.0.0.1', 'Disposable loopback server only');
const origin = new URL(api).origin;

(async () => {
  const browser = await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
  try {
    const context = await browser.newContext({reducedMotion:'reduce'});
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const login = await context.request.post(api+'?r=auth/login', {data:{username:'testadmin',password:'EndpointTest9!'}});
    assert.equal(login.status(),200);
    const now = Date.now();
    const created = await context.request.post(api+'?r=elections', {data:{
      title:'Dashboard <test> election', startDate:new Date(now+86400000).toISOString().slice(0,16),
      endDate:new Date(now+172800000).toISOString().slice(0,16), positions:['President']
    }});
    assert.equal(created.status(),201);
    const id = (await created.json()).id;
    const baseline = await (await context.request.get(api+'?r=dashboard&election='+id)).json();
    let other = baseline.elections.find(e=>e.id!==id);
    if (!other) {
      const response=await context.request.post(api+'?r=elections',{data:{title:'Other dashboard election',startDate:new Date(now+86400000).toISOString().slice(0,16),endDate:new Date(now+172800000).toISOString().slice(0,16),positions:['Secretary']}});
      assert.equal(response.status(),201); other={id:(await response.json()).id,title:'Other dashboard election'};
    }
    const page = await context.newPage();
    const errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    await page.goto(origin+'/admin/dashboard.php?election='+id);
    await page.waitForFunction(id=>document.querySelector('#dashboard-election').value===String(id) && document.querySelector('#election-panel-sub').textContent==='Dashboard <test> election',id);
    assert.equal(await page.locator('#participation-summary').innerText().then(t=>t.includes('Submitted ballots (including abstentions)')),true);
    async function checkParticipation(expected) {
      assert.equal(await page.locator('#participation-election').textContent(),expected.title);
      assert.deepEqual(await page.locator('#participation-summary strong').allTextContents(),
        [expected.ballotsSubmitted,expected.activeVoters,expected.activeVotersVoted,expected.activeVotersRemaining].map(n=>Number(n).toLocaleString()));
    }
    await checkParticipation(baseline.selectedElection);
    assert.equal(await page.locator('#participation-election test').count(),0);
    assert.equal(await page.locator('#voter-table').count(),0);
    assert.equal(await page.locator('.stat-bar').count(),0);
    assert.equal(await page.locator('#election-panel-sub test').count(),0);
    await page.waitForFunction(expected=>document.querySelector('#v-votes').textContent===Number(expected).toLocaleString(),baseline.votesCast);
    for (const width of [320,375,768,1280]) {
      await page.setViewportSize({width,height:900});
      const fit = await page.locator('.content').evaluate(el=>el.scrollWidth<=el.clientWidth+1);
      assert.ok(fit,'Dashboard overflows at '+width);
      const selectFit=await page.locator('#dashboard-election').evaluate(el=>el.getBoundingClientRect().right<=innerWidth+1);
      assert.ok(selectFit,'Election selector overflows at '+width);
      if (process.env.COOPVOTE_DASHBOARD_SCREENSHOT) {
        await page.screenshot({path:process.env.COOPVOTE_DASHBOARD_SCREENSHOT.replace('.png','-'+width+'.png'),fullPage:true});
      }
    }
    await page.selectOption('#dashboard-election',String(other.id));
    await page.waitForFunction(title=>document.querySelector('#election-panel-sub').textContent===title,other.title);
    const otherDashboard=await (await context.request.get(api+'?r=dashboard&election='+other.id)).json();
    await checkParticipation(otherDashboard.selectedElection);
    assert.ok((await page.locator('#dashboard-report').getAttribute('href')).endsWith('election='+other.id));
    assert.equal(new URL(page.url()).searchParams.get('election'),String(other.id));
    // A delayed old request must not replace a more recently selected election.
    await page.route('**/api/index.php*', async route=>{
      const u=new URL(route.request().url());
      if(u.searchParams.get('r')==='dashboard' && u.searchParams.get('election')===String(id)) await new Promise(r=>setTimeout(r,600));
      await route.continue();
    });
    await page.selectOption('#dashboard-election',String(id));
    await page.selectOption('#dashboard-election',String(other.id));
    await page.waitForTimeout(900);
    assert.equal(await page.locator('#election-panel-sub').textContent(),other.title);
    await checkParticipation(otherDashboard.selectedElection);
    await page.unroute('**/api/index.php*');
    // Failed switching must clear the old-election details and give actionable feedback.
    await page.route('**/api/index.php*', route=>{
      const u=new URL(route.request().url());
      if(u.searchParams.get('r')==='dashboard')return route.fulfill({status:503,json:{error:'Synthetic connection failure'}});
      return route.continue();
    });
    await page.selectOption('#dashboard-election',String(id));
    await page.locator('#dashboard-error:not([hidden])').waitFor();
    assert.equal(await page.locator('#dashboard-report').isVisible(),false);
    assert.equal(await page.locator('#participation-election').textContent(),'Select an election');
    assert.equal(await page.locator('#participation-summary strong').count(),0);
    assert.ok(!(await page.locator('#election-panel-body').innerText()).includes(other.title));
    await page.unroute('**/api/index.php*');
    await page.goto(origin+'/admin/dashboard.php?election=999999');
    await page.locator('#dashboard-election:enabled').waitFor();
    await page.locator('#dashboard-error:not([hidden])').waitFor();
    assert.equal(new URL(page.url()).searchParams.has('election'),false);
    await page.route('**/api/index.php*', route=>{
      if(new URL(route.request().url()).searchParams.get('r')==='dashboard') return route.fulfill({json:{...baseline,elections:[],selectedElection:null,positionVotes:[]}});
      return route.continue();
    });
    await page.goto(origin+'/admin/dashboard.php');
    await page.waitForFunction(()=>document.querySelector('#dashboard-election').textContent==='No elections available');
    assert.equal(await page.locator('#dashboard-election').isDisabled(),true);
    assert.equal(await page.locator('#dashboard-report').isVisible(),false);
    assert.ok((await page.locator('#participation-summary').innerText()).includes('Select an election'));
    assert.deepEqual(errors,[]);
    console.log('Dashboard Chromium PASS: real API, election scope, global totals, privacy, escaped titles, 320/375/768/1280px layout, rapid switching, failure feedback, stale-link recovery.');
    await context.close();
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});

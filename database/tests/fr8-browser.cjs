const fs = require('fs');
const path = require('path');
const {execFileSync} = require('child_process');
const {chromium, request} = require(process.env.COOPVOTE_PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.FR8_BASE, out = process.env.FR8_OUTPUT;
if (process.env.COOPVOTE_ENV !== 'test' || new URL(base).hostname !== '127.0.0.1' || !out) throw Error('Disposable test environment required');
const cases = [];
function check(name, ok, detail) {
  cases.push({name, pass:!!ok, detail});
  if (!ok) throw Error(name);
  console.log('PASS '+name);
}
(async () => {
  const browser = await chromium.launch({headless:true, args:['--no-sandbox'], env:{...process.env, ...(process.env.FR8_FONTCONFIG ? {FONTCONFIG_FILE:process.env.FR8_FONTCONFIG} : {})}});
  const errors = [];
  try {
    const ctx = await browser.newContext({viewport:{width:1440,height:1100}}), page = await ctx.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', r => new URL(r.request().url()).origin === base ? r.continue() : r.abort());
    await page.goto(base+'/login.php');
    await page.locator('#username').fill('testadmin');
    await page.locator('#password').fill('EndpointTest9!');
    await page.locator('#login-btn').click();
    await page.waitForURL('**/admin/**');
    async function api(route, method='GET', data, expected=200, client=ctx.request) {
      const r = await client.fetch(base+'/api/index.php?r='+route,{method,data,headers:{'X-Requested-With':'XMLHttpRequest'}});
      if (r.status() !== expected) throw Error(route+' expected '+expected+' got '+r.status()+' '+await r.text());
      return r.json();
    }
    async function election(id) { return (await api('elections')).find(e => e.id === id); }
    async function approval(id) {
      await api(`management/elections/${id}/submit`, 'POST', {});
      const e = (await api('management/overview')).elections.find(e => e.id === id);
      await api(`management/elections/${id}/decide`, 'POST', {requestId:e.request.request_id, decision:'approve'});
    }
    async function show(id = 800) {
      await page.goto(base+'/admin/election-management.php?election='+id);
      await page.waitForFunction(() => allElections.length >= 8);
    }
    const card = id => page.locator(`.ec[data-election-id="${id}"]`);
    const dates = days => new Date(Date.now()+days*86400000).toISOString().slice(0,16);
    const start = dates(5), end = dates(6);
    const payload = e => ({title:e.title,description:e.description,startDate:e.startISO,endDate:e.endISO});
    await approval(800);
    check('Upcoming fixture is approved before rescheduling', (await election(800)).approvalReady);
    await show();
    await card(800).getByRole('button',{name:'Reschedule',exact:true}).click();
    await page.locator('#f-start').fill(start); await page.locator('#f-end').fill(end);
    await page.locator('#f-end').blur();
    await page.locator('#election-modal .modal').screenshot({path:out+'/01-reschedule-form.png'});
    const saved = page.waitForResponse(r => r.url().includes('r=elections/800') && r.request().method()==='PUT');
    await page.locator('#election-save').click();
    check('Reschedule saved through Admin form', (await saved).status() === 200);
    await page.locator('#election-modal.show').waitFor({state:'hidden'});
    await page.waitForFunction(() => allElections.find(e=>e.id===800)?.status==='Draft');
    let e = await election(800);
    check('New schedule persists on same election with candidates and positions retained', e.startISO===start && e.endISO===end && e.candidates===1 && e.positions===1 && e.status==='Draft');
    check('Rescheduling invalidates prior approval', !e.approvalReady);
    await api('elections/800/activate','PATCH',{},409);
    await show(); await card(800).screenshot({path:out+'/02-rescheduled-draft.png'});
    await api('elections/800','PUT',{...payload(e),startDate:dates(-2),endDate:dates(-1)},422);
    await api('elections/800','PUT',{...payload(e),startDate:end,endDate:start},422);
    check('Invalid schedules rejected without changing saved dates', (await election(800)).startISO===start);
    await approval(800); await api('elections/800/activate','PATCH',{});
    check('Fresh approval still permits opening', (await election(800)).status==='Active');

    await approval(806);
    await show(806);
    check('Cancel Election button and dialog removed',await page.getByRole('button',{name:'Cancel Election',exact:true}).count()===0&&await page.locator('#cancel-election-modal').count()===0);
    for (const id of [806,807]) {
      const before=await election(id);
      const denied=await api(`elections/${id}/cancel`,'PATCH',{reason:'Old client request'},410);
      check('Retired cancellation API preserves election '+id,denied.code==='CANCELLATION_REMOVED'&&JSON.stringify(await election(id))===JSON.stringify(before));
    }
    await card(806).screenshot({path:out+'/03-election-actions.png'});
    for (const id of [801,802,900]) {
      await page.selectOption('#election-select',String(id));
      check('Delete unavailable after opening: '+id,await card(id).getByRole('button',{name:'Delete',exact:true}).count()===0);
    }
    await page.selectOption('#election-select','807');
    check('Unopened Draft retains Delete',await card(807).getByRole('button',{name:'Delete',exact:true}).isVisible());
    await page.selectOption('#election-select','801');
    await card(801).screenshot({path:out+'/04-live-protected.png'});

    for (const [id,name] of [[801,'active with no votes'],[802,'closed with no votes'],[803,'draft with recorded abstention'],[804,'previously opened but future-dated'],[900,'active fixture']]) {
      const before=await election(id);
      await api(`elections/${id}/cancel`,'PATCH',{reason:'Must be rejected'},410);
      await api(`elections/${id}`,'PUT',{...payload(before),startDate:start,endDate:end},409);
      const after=await election(id);
      check('Reject cancellation and rescheduling: '+name,after.status===before.status&&after.startISO===before.startISO&&after.endISO===before.endISO&&!after.canReschedule);
    }
    await show(801);
    // The current policy locks all details once voting starts, including entry to the form.
    check('Active election details are locked',await card(801).getByRole('button',{name:'Edit Details',exact:true}).isDisabled());
    check('Locked election edit form remains closed',!(await page.locator('#election-modal.show').isVisible()));
    const stale=await election(805);
    execFileSync('php',[path.join(__dirname,'fr8-fixture.php'),'start-race'],{env:process.env});
    await api('elections/805','PUT',{...payload(stale),startDate:start,endDate:end},409);
    check('Stale reschedule rejected after voting starts',(await election(805)).status==='Active');

    const anon=await request.newContext(), voter=await request.newContext();
    await api('elections/800/cancel','PATCH',{reason:'Unauthorized'},401,anon);
    await api('auth/login','POST',{username:'testvoter',password:'EndpointTest9!'},200,voter);
    await api('elections/800/cancel','PATCH',{reason:'Unauthorized'},403,voter);
    await api('elections/800','PUT',{title:'Unauthorized',startDate:start,endDate:end},403,voter);
    check('Administrator authorization remains enforced',true);
    await anon.dispose(); await voter.dispose();
    check('No browser JavaScript errors',errors.length===0,errors);
    fs.writeFileSync(out+'/RESULTS.json',JSON.stringify({checked_at:new Date().toISOString(),cases,all_passed:true,scope:'Rescheduling retained and cancellation removed; synthetic isolated environment only. Full FR8 cancellation requirement not met.'},null,2));
  } finally {
    await browser.close();
    if (!fs.existsSync(out+'/RESULTS.json')) fs.writeFileSync(out+'/RESULTS.json',JSON.stringify({cases,all_passed:false},null,2));
  }
})().catch(e=>{console.error(e);process.exitCode=1;});

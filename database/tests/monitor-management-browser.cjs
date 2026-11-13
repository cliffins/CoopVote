'use strict';
const assert = require('node:assert/strict'), path = require('node:path');
const {chromium} = require(process.env.COOPVOTE_PLAYWRIGHT_MODULE || 'playwright');
const api = process.env.COOPVOTE_TEST_URL, origin = new URL(api).origin;
assert.equal(process.env.COOPVOTE_ENV, 'test');
assert.equal(new URL(api).hostname, '127.0.0.1');
(async () => {
 const browser = await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 try {
  const ctx = await browser.newContext({reducedMotion:'reduce'});
  await ctx.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  async function req(route,method='GET',data,status=200,client=ctx) {
   const r=await client.request.fetch(api+'?r='+route,{method,data});
   assert.equal(r.status(),status,await r.text());return r.json();
  }
  const anon=await browser.newContext();await req('monitor','GET',undefined,401,anon);await anon.close();
  await req('auth/login','POST',{username:'testadmin',password:'EndpointTest9!'});
  assert.equal((await req('monitor')).election,null);
  const date=n=>new Date(Date.now()+n*86400000).toISOString().slice(0,16);
  const members=await req('candidates/eligible');
  async function election(title) {
   const {id}=await req('elections','POST',{title,startDate:date(1),endDate:date(2),positions:['President','Secretary','Empty position']},201);
   const positions=await req('positions&election='+id),ids=[];
   for(let i=0;i<2;i++) {
    const c=await req('candidates&election='+id,'POST',{memberId:members[i].id,electionId:id,positionId:positions[i].position_id},201);ids.push(c.id);
   }
   await req(`management/elections/${id}/submit`,'POST',{});
   const review=(await req('management/overview')).elections.find(e=>e.id===id).request;
   await req(`management/elections/${id}/decide`,'POST',{requestId:review.request_id,decision:'approve'});
   await req(`elections/${id}/activate`,'PATCH',{});
   return {id,positions,ids};
  }
  const a=await election('Monitoring Election A'),b=await election('Monitoring Election B');
  const tally=id=>req('monitor&election='+id),flat=x=>x.candidateTally.flatMap(p=>p.candidates);
  assert.deepEqual(flat(await tally(a.id)).map(c=>c.votes),[0,0]);
  const voter=await browser.newContext();await req('auth/login','POST',{username:'testvoter',password:'EndpointTest9!'},200,voter);
  await req('monitor&election='+a.id,'GET',undefined,403,voter);
  const cast=(e,client)=>req('voter/vote','POST',{electionId:e.id,votes:[{candidateId:e.ids[0],positionId:e.positions[0].position_id},{positionId:e.positions[1].position_id,abstain:true}]},201,client);
  await cast(a,voter);await cast(b,voter);
  const nominee=await browser.newContext();await req('auth/login','POST',{username:'testnominee',password:'EndpointTest9!'},200,nominee);await cast(a,nominee);
  await req(`candidates/${a.ids[0]}&election=${a.id}`,'PATCH',{status:'Inactive'});
  const before=await tally(a.id),second=await tally(b.id);
  assert.deepEqual(flat(before).map(c=>c.votes),[2,0]);assert.deepEqual(flat(second).map(c=>c.votes),[1,0]);
  assert.equal(flat(before)[0].status,'Inactive');assert.equal(before.election.votesCast,2);assert.equal(second.election.votesCast,1);
  for(const key of ['recentVotes','activityFeed','positionBreakdown'])assert.ok(!(key in before));
  for(const c of flat(before))assert.deepEqual(Object.keys(c).sort(),['id','name','status','votes']);
  assert.deepEqual((await req('voter/results&election='+a.id,'GET',undefined,200,voter)).elections,[]);
  await req('monitor&election=invalid','GET',undefined,422);await req('monitor&election=999999','GET',undefined,404);
  await req('monitor&election='+a.id,'POST',{},405);
  console.log('PASS real API: admin-only, GET-only; anonymous 401/voter 403; per-election totals 2 vs 1; zero-vote and inactive candidates included; invalid selection never falls back; voter results remain unreleased.');
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/admin/vote-monitoring.php?election='+a.id);
  await page.locator('#tally-tbody .tally-count').first().waitFor();
  assert.equal(await page.locator('#monitor-election').inputValue(),String(a.id));
  assert.deepEqual(await page.locator('.tally-count').allTextContents(),['2','0']);
  assert.equal(await page.locator('.stats-grid').count(),1);
  assert.equal(await page.locator('#voting-progress,.donut-wrap,#activity-tbody').count(),0);
  assert.ok((await page.locator('#main-content').textContent()).includes('Partial / Unofficial Results'));
  assert.ok(!(await page.locator('#main-content').textContent()).includes('Winner'));
  await page.selectOption('#monitor-election',String(b.id));
  await page.waitForFunction(()=>document.querySelector('.tally-count')?.textContent==='1');
  assert.ok(page.url().includes('election='+b.id));
  await page.selectOption('#monitor-election',String(a.id));
  await page.waitForFunction(()=>document.querySelector('.tally-count')?.textContent==='2');
  for(const [width,height] of [[320,568],[375,667],[768,1024],[1280,900]]) {
   await page.setViewportSize({width,height});
   assert.ok(await page.locator('#monitor-election').evaluate(el=>{const b=el.getBoundingClientRect();return b.left>=0&&b.right<=innerWidth;}));
   await page.locator('.cv-monitor').scrollIntoViewIfNeeded();
   assert.ok(await page.locator('.cv-monitor').evaluate(t=>{
    const w=t.parentElement,b=w.getBoundingClientRect();w.scrollLeft=w.scrollWidth;
    const last=t.querySelector('tbody tr').lastElementChild.getBoundingClientRect();
    return b.left>=0&&b.right<=innerWidth+1&&last.right<=b.right+1&&getComputedStyle(t.querySelector('tbody tr')).display==='table-row';
   }));
   await page.locator('.cv-monitor').evaluate(t=>t.parentElement.scrollLeft=0);
   await page.locator('.monitor-tally-card').evaluate(el=>el.getAnimations().forEach(a=>a.finish()));
   const panel=await page.locator('#main-content').evaluate(content=>{
    content.scrollTop=content.scrollHeight;
    const card=content.querySelector('.monitor-tally-card').getBoundingClientRect();
    return {gap:content.getBoundingClientRect().bottom-card.bottom,height:card.height};
   });
   assert.ok(panel.gap>=10&&panel.gap<=14&&panel.height>=239, 'Table available height '+width+': '+JSON.stringify(panel));
   await page.screenshot({path:path.join(path.dirname(process.env.COOPVOTE_CANDIDATE_SCREENSHOT),'monitor-tally-'+width+'.png')});
  }
  console.log('PASS real UI: election selector updates totals; one summary; no administrative panel/duplicate chart; unofficial label; native table and reachable Votes column at 320/375/768/1280px.');
  const many=structuredClone(before);
  many.candidateTally[0].candidates=Array.from({length:70},(_,i)=>({id:i+1,name:'Synthetic candidate '+(i+1),votes:0,status:'Active'}));
  await page.evaluate(data=>initMonitor(data),many);
  await page.locator('.monitor-tally-card').evaluate(el=>el.getAnimations().forEach(a=>a.finish()));
  assert.ok(await page.locator('.monitor-tally-card .table-scroll').evaluate(w=>{
   w.scrollTop=w.scrollHeight;
   const head=w.querySelector('thead').getBoundingClientRect(),box=w.getBoundingClientRect();
   return w.scrollHeight>w.clientHeight&&Math.abs(head.top-box.top)<3;
  }), 'Many candidates scroll inside the panel with sticky column headers');
  await page.evaluate(()=>loadData());
  console.log('PASS table fills remaining viewport with 12px bottom space; short screens retain usable height; long candidate lists scroll inside the panel with sticky headers.');

  // Failure and out-of-order response fixtures affect only this local browser page.
  const matcher='**/api/index.php*';
  await page.route(matcher,r=>new URL(r.request().url()).searchParams.get('r')==='monitor'?r.fulfill({status:503,json:{error:'Synthetic unavailable'}}):r.continue());
  await page.evaluate(()=>loadData());assert.equal(await page.locator('.tally-count').first().textContent(),'2');
  await page.selectOption('#monitor-election',String(b.id));
  await page.getByText('Unable to load monitoring',{exact:true}).waitFor();assert.equal(await page.locator('.tally-count').count(),0);
  await page.unroute(matcher);await page.evaluate(()=>loadData());assert.equal(await page.locator('.tally-count').first().textContent(),'1');
  await page.route(matcher,async r=>{
   const u=new URL(r.request().url());if(u.searchParams.get('r')!=='monitor')return r.continue();
   if(u.searchParams.get('election')===String(a.id))await new Promise(resolve=>setTimeout(resolve,400));
   return r.fulfill({json:u.searchParams.get('election')===String(a.id)?before:second});
  });
  await page.evaluate(async ids=>{document.getElementById('monitor-election').value=String(ids[0]);const old=selectMonitorElection();document.getElementById('monitor-election').value=String(ids[1]);await selectMonitorElection();await old;},[a.id,b.id]);
  assert.equal(await page.locator('.tally-count').first().textContent(),'1');
  await page.unroute(matcher);
  const malicious=structuredClone(second);malicious.candidateTally[0].candidates[0].name='<img src=x onerror=alert(1)>';
  await page.evaluate(data=>initMonitor(data),malicious);
  assert.equal(await page.locator('#tally-tbody img').count(),0);assert.ok((await page.locator('#tally-tbody').textContent()).includes('<img'));
  await page.evaluate(()=>loadData());
  console.log('PASS refresh failure preserves existing same-election data; failed switches clear old totals; stale requests cannot mix elections; candidate text is escaped.');
  assert.deepEqual(flat(await tally(a.id)),flat(before));
  for(const e of [a,b]) assert.equal((await req('operations/overview&election='+e.id)).integrity.status,'passed');
  await req(`elections/${b.id}/close`,'PATCH',{});
  const closed=await tally(b.id);assert.equal(closed.election,null);assert.deepEqual(closed.candidateTally,[]);assert.equal(closed.selectedElectionId,b.id);
  await page.evaluate(()=>loadData());await page.getByText('No Active Election to Monitor',{exact:true}).waitFor();
  assert.equal(await page.locator('#tally-tbody').count(),0);
  assert.deepEqual(errors,[]);
  console.log('PASS read-only monitoring preserves test ballots and HMAC integrity; closed election clears live totals without fallback; no JavaScript errors. All writes occurred only in disposable fixtures.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

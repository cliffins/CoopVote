'use strict';
const assert = require('node:assert/strict');
const path = require('node:path');
const {chromium} = require(process.env.COOPVOTE_PLAYWRIGHT_MODULE || 'playwright');
const api = process.env.COOPVOTE_TEST_URL, origin = new URL(api).origin;
assert.equal(process.env.COOPVOTE_ENV, 'test');
assert.equal(new URL(api).hostname, '127.0.0.1');
(async () => {
 const browser = await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 try {
  const ctx=await browser.newContext({reducedMotion:'reduce'});
  await ctx.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  async function request(route, method='GET', data, status=200) {
   const r=await ctx.request.fetch(api+'?r='+route,{method,data});assert.equal(r.status(),status,await r.text());return r.json();
  }
  await request('auth/login','POST',{username:'testadmin',password:'EndpointTest9!'});
  const date=n=>new Date(Date.now()+n*86400000).toISOString().slice(0,16);
  const {id}=await request('elections','POST',{title:'Responsive table test election',startDate:date(1),endDate:date(2),positions:['Board of Directors','Audit and Inventory Committee']},201);
  const positions=await request('positions&election='+id),members=await request('candidates/eligible');
  await request('candidates&election='+id,'POST',{electionId:id,positionId:positions[0].position_id,memberId:members[0].id,year:'3rd Year',section:'Section with a long label'},201);
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const cases=[['candidate-management.php?election='+id,'.cv-candidates'],['voter-management.php','.cv-voters'],['election-management.php?election='+id,'.position-table'],['officer-management.php','.officer-table']];
  for(const [file,selector] of cases){
   await page.goto(origin+'/admin/'+file);
   await page.locator(selector+' tbody tr td:not([colspan])').first().waitFor();
   if(selector!=='.officer-table') await page.waitForFunction(sel=>document.querySelector(sel)?.classList.contains('cv-responsive-table'),selector);
   if(selector==='.cv-candidates') await page.locator('.t-name').evaluate(el=>el.textContent='Synthetic Candidate With A Very Long Name');
   if(selector==='.cv-voters') await page.locator('.v-name').first().evaluate(el=>el.textContent='Synthetic Voter With A Very Long Name');
   for(const width of [320,375,768,1024,1280,1440,1920]){
    await page.setViewportSize({width,height:1000});
    const layout=await page.locator(selector).evaluate(table=>{
     const wrap=table.parentElement,box=wrap.getBoundingClientRect();
     wrap.scrollLeft=wrap.scrollWidth;
     const visible=el=>getComputedStyle(el).display!=='none'&&el.getBoundingClientRect().width>1;
     const controls=[...table.querySelectorAll('tbody tr:first-child td:last-child button')].filter(visible);
     const cells=[...table.querySelectorAll('tbody tr:first-child td')].filter(visible);
     const tableBox=table.getBoundingClientRect();
     return {scrollable:wrap.scrollWidth>wrap.clientWidth+1,
      regionFits:box.left>=-1 && box.right<=innerWidth+1,
      clipped:controls.some(el=>{const r=el.getBoundingClientRect();return r.left<box.left-1||r.right>box.right+1;}),
      cellsOutside:cells.some(el=>{const r=el.getBoundingClientRect();return r.left<tableBox.left-1||r.right>tableBox.right+1;}),
      controls:controls.length, tableDisplay:getComputedStyle(table).display,
      rowDisplay:getComputedStyle(table.querySelector('tbody tr')).display,
      headerDisplay:getComputedStyle(table.tHead).display,
      headersVisible:[...table.tHead.rows[0].cells].filter(visible).every(th=>th.getBoundingClientRect().height>5),
      keyboardScrollable:wrap.tabIndex===0, width:box.width};
    });
    assert.ok(layout.regionFits,`${file} ${width}: table region exceeds screen`);
    assert.equal(layout.tableDisplay,'table');assert.equal(layout.rowDisplay,'table-row');
    assert.equal(layout.headerDisplay,'table-header-group');assert.ok(layout.headersVisible);
    assert.ok(layout.keyboardScrollable);
    assert.equal(layout.clipped,false,`${file} ${width}: Actions not reachable at scroll end`);
    assert.equal(layout.cellsOutside,false,`${file} ${width}: cell outside table`);
    assert.ok(layout.controls>0,`${file}: no row action`);
    if(width===375)await page.screenshot({path:path.join(path.dirname(process.env.COOPVOTE_CANDIDATE_SCREENSHOT),file.split('.')[0]+'-actions-375.png')});
    await page.locator(selector).evaluate(table=>table.parentElement.scrollLeft=0);
    if(selector==='.cv-voters' && [320,375,768,1024,1280].includes(width)) {
     await page.locator('.voter-toolbar').scrollIntoViewIfNeeded();
     await page.screenshot({path:path.join(path.dirname(process.env.COOPVOTE_CANDIDATE_SCREENSHOT),'voter-toolbar-'+width+'.png')});
    }
    if(width===375||width===1280||width===1920){
     await page.locator(selector+' tbody tr').first().scrollIntoViewIfNeeded();
     await page.screenshot({path:path.join(path.dirname(process.env.COOPVOTE_CANDIDATE_SCREENSHOT),file.split('.')[0]+'-'+width+'.png')});
    }
   }
   await page.setViewportSize({width:375,height:1000});
   if(selector==='.cv-candidates'||selector==='.cv-voters'){
    await page.locator(selector+' .icon-btn.view').first().click();
    await page.locator('#view-modal.show').waitFor();
    await page.locator('#view-modal .modal-close').click();
    await page.fill('#search-input','no-such-synthetic-name');
    await page.locator(selector+' td[colspan]').waitFor();
    await page.fill('#search-input','');
    await page.waitForFunction(sel=>document.querySelector(sel+' tbody td')?.hasAttribute('data-label'),selector);
   }
   if(selector==='.cv-voters'){
    await page.locator('#check-all').check();
    assert.equal(await page.locator('#check-all').isVisible(),true);
    assert.equal(await page.locator('#voter-tbody input[type=checkbox]:checked').count(),2);
    await page.locator('#check-all').uncheck();
   }
   console.log(`PASS ${file}: 320/375/768/1024/1280/1440/1920px; native table rows and visible headers; Actions reachable by scrolling; mobile controls and dynamic rows checked.`);
  }
  // Read-only synthetic responses exercise populated report/monitor tables without casting votes.
  const election={id,title:'Responsive report fixture',startDate:'2026-11-11',endDate:'2026-11-12',totalVoters:2,votesCast:1,candidates:1,duration:'1 day'};
  const breakdown=[{name:'Board of Directors',votesCast:1,totalVoters:2}];
  await page.route('**/api/index.php*',route=>{
   const r=new URL(route.request().url()).searchParams.get('r');
   if(r==='reports') return route.fulfill({json:{elections:[election],selected:{...election,positionBreakdown:breakdown,candidateResults:[{rank:1,name:'Synthetic Report Candidate',position:'Board of Directors',votes:1,totalInPosition:1,winner:true}]}}});
   if(r==='monitor') return route.fulfill({json:{election,elections:[{id,title:election.title,status:'Active'}],selectedElectionId:id,candidateTally:[{id:1,name:'Board of Directors',candidates:[{id:1,name:'Synthetic Candidate',votes:1,status:'Active'}]}]}});
   return route.continue();
  });
  for(const [file,selector] of [['reports.php','.cv-report'],['vote-monitoring.php','.cv-monitor']]) {
   await page.goto(origin+'/admin/'+file);
   await page.locator(selector+' tbody td[data-label]').first().waitFor();
   for(const width of [320,375,768,1024,1280,1440,1920]) {
    await page.setViewportSize({width,height:1000});
    assert.ok(await page.locator(selector).evaluate(t=>{
     const wrap=t.parentElement,box=wrap.getBoundingClientRect();wrap.scrollLeft=wrap.scrollWidth;
     const last=t.querySelector('tbody tr').lastElementChild.getBoundingClientRect();
     return box.left>=-1&&box.right<=innerWidth+1&&last.right<=box.right+1&&getComputedStyle(t.querySelector('tbody tr')).display==='table-row';
    }),file+' contained table with reachable last column '+width);
    assert.ok(await page.locator(selector+' tbody tr').first().evaluate(row=>[...row.cells].every(cell=>cell.getBoundingClientRect().width>0)),file+' hidden data '+width);
   }
   await page.emulateMedia({media:'print'});
   assert.equal(await page.locator(selector+' thead').evaluate(el=>getComputedStyle(el).display),'table-header-group');
   await page.emulateMedia({media:'screen'});
   console.log('PASS '+file+': synthetic read-only data; table rows retained and last column reachable at seven widths; print headings retained.');
  }
  await request('candidates/display-settings','PUT',{showYearSection:false});
  await page.goto(origin+'/admin/candidate-management.php?election='+id);
  await page.locator('.hide-candidate-academics .cv-responsive-table').waitFor();
  await page.locator('#cand-tbody td[data-label="Actions"]').waitFor(); // Wait for the asynchronous row load too.
  assert.equal(await page.locator('#cand-tbody .candidate-academic-column').isVisible(),false);
  assert.equal(await page.locator('#cand-tbody td[data-label="Actions"]').isVisible(),true);
  for(const width of [375,1280,1920]) {
   await page.setViewportSize({width,height:1000});
   for(const empty of [false,true]) {
    await page.fill('#search-input',empty?'no-matching-profile':'');
    if(empty) await page.locator('#cand-tbody td[colspan]').waitFor();
    else await page.locator('#cand-tbody td:not([colspan])').first().waitFor();
    const dimensions=await page.locator('.cv-candidates').evaluate(table=>{
     const visible=[...table.tHead.rows[0].cells].filter(c=>getComputedStyle(c).display!=='none');
     const last=visible.at(-1).getBoundingClientRect(),box=table.getBoundingClientRect();
     return {gap:box.right-last.right,columns:table.querySelectorAll('col').length,colspan:table.querySelector('td[colspan]')?.colSpan};
    });
    assert.ok(Math.abs(dimensions.gap)<2,'No reserved blank column after Actions');
    assert.equal(dimensions.columns,6);if(empty)assert.equal(dimensions.colspan,6);
    if(width===1920)await page.screenshot({path:path.join(path.dirname(process.env.COOPVOTE_CANDIDATE_SCREENSHOT),'candidate-hidden-year-'+(empty?'empty':'populated')+'.png')});
   }
  }
  console.log('PASS hidden Year & Section uses six columns; header fills table through Actions, including empty results.');
  assert.deepEqual(errors,[]);
  console.log('PASS hidden Year & Section stays hidden on mobile; Actions retained; no JavaScript errors. Test data only.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

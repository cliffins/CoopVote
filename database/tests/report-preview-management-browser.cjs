'use strict';
const assert=require('node:assert/strict');
const {chromium}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE);
const api=process.env.COOPVOTE_TEST_URL,origin=new URL(api).origin;
assert.equal(process.env.COOPVOTE_ENV,'test');assert.equal(new URL(api).hostname,'127.0.0.1');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  const ctx=await browser.newContext();await ctx.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  async function req(route,method='GET',data,status=200){const r=await ctx.request.fetch(api+'?r='+route,{method,data});assert.equal(r.status(),status,await r.text());return r.json();}
  await req('auth/login','POST',{username:'testadmin',password:'EndpointTest9!'});
  const date=n=>new Date(Date.now()+n*86400000).toISOString().slice(0,16);
  const {id}=await req('elections','POST',{title:'Unreleased report fixture',startDate:date(1),endDate:date(2),positions:['President']},201);
  const payload=await req('reports&election='+id);assert.equal(payload.selected.canPrint,false);assert.equal(payload.selected.resultsReleased,false);
  const denied=await req('operations/report&election='+id+'&format=html','GET',undefined,409);assert.equal(denied.code,'RESULTS_PRIVATE');
  const page=await ctx.newPage(),errors=[];let popups=0,reportRequests=0;
  page.on('pageerror',e=>errors.push(e.message));page.on('popup',()=>popups++);
  page.on('request',r=>{if(new URL(r.url()).searchParams.get('r')==='operations/report')reportRequests++;});
  await page.goto(origin+'/admin/reports.php?election='+id);await page.locator('.report-print:enabled').waitFor();
  const home=page.url();await page.locator('.report-print').click();
  await page.getByText('You cannot print or save a PDF yet. The election results have not been released.',{exact:true}).waitFor();
  assert.equal(popups,0);assert.equal(reportRequests,0);assert.equal(page.url(),home);
  console.log('PASS unreleased election: real API denies print; clicking Print / PDF shows notification without opening a tab or leaving Reports.');
  await page.evaluate(()=>reportData.selected.canPrint=true);
  const stalePopup=page.waitForEvent('popup');await page.locator('.report-print').click();const failed=await stalePopup;
  await page.waitForFunction(()=>!document.querySelector('.report-print').disabled);
  assert.equal(failed.isClosed(),true);assert.equal(page.url(),home);assert.equal(reportRequests,1);
  await page.getByText('Print / PDF is not available yet. Results must be released and meet voter privacy requirements.',{exact:true}).waitFor();
  console.log('PASS stale availability: server privacy rejection closes reserved tab and shows a readable notification; raw JSON never displayed.');
  // Synthetic successful HTML proves preview rendering/printing; does not release real results.
  await page.route(/\/api\/index\.php(?:\?|$)/,r=>{
   if(new URL(r.request().url()).searchParams.get('r')==='operations/report')return r.fulfill({status:200,contentType:'text/html; charset=utf-8',body:'<!doctype html><title>Synthetic released report</title><h1>Released report fixture</h1><button onclick="window.print()">Print / Save as PDF</button>'});
   return r.continue();
  });
  const readyPopup=page.waitForEvent('popup');await page.locator('.report-print').click();const ready=await readyPopup;
  await ready.getByRole('heading',{name:'Released report fixture'}).waitFor();assert.ok(ready.url().startsWith('blob:'));
  assert.equal(await ready.evaluate(()=>window.opener),null);
  await ready.evaluate(()=>{window.print=()=>{window.didPrint=true;};});await ready.getByRole('button',{name:'Print / Save as PDF'}).click();assert.equal(await ready.evaluate(()=>window.didPrint),true);
  await ready.close();assert.equal(await page.locator('.report-print svg').count(),1);assert.equal(await page.locator('.report-print').isEnabled(),true);
  console.log('PASS successful HTML preview remains printable, has no opener, and restores the original button/icon. Success response was synthetic.');
  await page.evaluate(()=>{window.realOpen=window.open;window.open=()=>null;});
  assert.equal(await page.evaluate(()=>printReport(document.querySelector('.report-print'))),false);
  await page.getByText('Allow pop-ups to open the printable report.',{exact:true}).waitFor();
  await page.evaluate(()=>{window.open=window.realOpen;});
  assert.deepEqual(errors,[]);console.log('PASS popup-blocked notification and no JavaScript errors. Production data unchanged; test database only.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

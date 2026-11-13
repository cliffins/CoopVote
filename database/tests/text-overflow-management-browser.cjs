'use strict';
const assert=require('node:assert/strict'),path=require('node:path');
const {chromium}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE);
const api=process.env.COOPVOTE_TEST_URL,origin=new URL(api).origin;
assert.equal(process.env.COOPVOTE_ENV,'test');assert.equal(new URL(api).hostname,'127.0.0.1');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  const ctx=await browser.newContext({reducedMotion:'reduce'});
  await ctx.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  assert.equal((await ctx.request.post(api+'?r=auth/login',{data:{username:'testadmin',password:'EndpointTest9!'}})).status(),200);
  const response=await ctx.request.get(api+'?r=security');assert.equal(response.status(),200);const payload=await response.json();
  const ip='2001:db8:8325:9f00:8df7:b7e9:3b80:1234';
  payload.suspiciousIPs=[false,true].map((reviewed,i)=>({ip,id:'synthetic'+i,attempts:10,severity:'medium',reason:'10 failed sign-ins in the last 24 hours. '+ 'LongIdentifier'.repeat(8),lastSeen:'2026-11-13 10:15:30',reviewed}));
  payload.logs=[{id:1,type:'auth',event:'LongEventIdentifier'.repeat(12),actor:'Synthetic Administrator With A Long Display Name',role:'Administrator',time:'November 13, 2026, 10:15 AM',ip,userAgent:'SyntheticAgent'.repeat(20)}];
  payload.blockedAddresses=[{ip:'2001:db8:8325:9f00:8df7:b7e9:3b80:4321',reason:'LongReason'.repeat(30),blockedAt:'2026-11-13 10:15:30'}];
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/api/index.php*',r=>{
   const route=new URL(r.request().url()).searchParams.get('r');
   if(route==='security')return r.fulfill({json:payload});
   if(route==='security/review-alert'){payload.suspiciousIPs[0].reviewed=true;return r.fulfill({json:{ok:true}});}
   return r.continue();
  });
  await page.goto(origin+'/admin/security-logs.php');await page.locator('.ip-item').first().waitFor();
  for(const width of [320,375,768,1280,1920]){
   await page.setViewportSize({width,height:1000});await page.locator('.ip-item').first().scrollIntoViewIfNeeded();
   const issues=await page.locator('.ip-item').evaluateAll(items=>items.flatMap(item=>{
    const panel=item.getBoundingClientRect(),info=item.querySelector('.ip-info').getBoundingClientRect(),actions=item.querySelector('.ip-actions').getBoundingClientRect();
    const issues=[];if(info.bottom>actions.top+1)issues.push('Text overlaps actions');
    for(const el of item.querySelectorAll('.ip-addr,.ip-reason,.ip-cnt,button')){
     const range=document.createRange();range.selectNodeContents(el);
     for(const rect of range.getClientRects())if(rect.width&& (rect.left<panel.left-1||rect.right>panel.right+1||rect.bottom>panel.bottom+1))issues.push('Text or control outside card');
    }
    const buttons=[...item.querySelectorAll('button')].map(e=>e.getBoundingClientRect());
    if(buttons.length===2&&buttons[0].right>buttons[1].left&&buttons[0].bottom>buttons[1].top&&buttons[1].bottom>buttons[0].top)issues.push('Buttons overlap');
    return issues;
   }));
   assert.deepEqual(issues,[],String(width));
   assert.ok(await page.locator('#blocked-addresses').evaluate(e=>e.scrollWidth<=e.clientWidth+1));
   if([375,1280].includes(width))await page.screenshot({path:path.join(path.dirname(process.env.COOPVOTE_CANDIDATE_SCREENSHOT),'ip-wrap-'+width+'.png')});
   await page.locator('.log-row').first().click();await page.locator('#log-modal.show').waitFor();
   assert.ok(await page.locator('#log-modal .modal').evaluate(e=>e.scrollWidth<=e.clientWidth+1));
   assert.ok(await page.locator('.detail-row').evaluateAll(rows=>rows.every(row=>{
    const label=row.querySelector('.detail-label').getBoundingClientRect(),value=row.querySelector('.detail-val').getBoundingClientRect();return label.right<=value.left+1&&row.scrollWidth<=row.clientWidth+1;
   })));
   await page.evaluate(()=>closeModal('log-modal'));
  }
  await page.setViewportSize({width:1280,height:1000});
  await page.getByRole('button',{name:'Mark reviewed',exact:true}).click();await page.waitForFunction(()=>[...document.querySelectorAll('.ip-actions .btn')].every(b=>b.disabled));
  await page.locator('.ip-item .ip-block').first().click();await page.locator('#block-modal.show').waitFor();assert.equal(await page.locator('#block-ip-input').inputValue(),ip);
  assert.deepEqual(errors,[]);
  console.log('PASS long IPv6, unbroken descriptions, timestamps, blocked-address reasons and log details at 320/375/768/1280/1920px: full text stays in its container, actions do not overlap, Reviewed/Mark reviewed and block dialog remain usable. No production data changed.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

'use strict';
const assert=require('node:assert/strict'),path=require('node:path');
const {chromium}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE||'playwright');
const api=process.env.COOPVOTE_TEST_URL,origin=new URL(api).origin;
assert.equal(process.env.COOPVOTE_ENV,'test');assert.equal(new URL(api).hostname,'127.0.0.1');
const sizes=[{width:320,height:640},{width:375,height:667},{width:768,height:1024},{width:1280,height:800},{width:1440,height:900},{width:1920,height:1080},{width:844,height:390}];
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 try{
  const ctx=await browser.newContext({reducedMotion:'reduce'}),errors=[];
  await ctx.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));
  async function req(route,method='GET',data,status=200){const r=await ctx.request.fetch(api+'?r='+route,{method,data});assert.equal(r.status(),status,await r.text());return r.json();}
  async function fit(selector,label){
   const el=page.locator(selector).first();await el.waitFor();
   const g=await el.evaluate(el=>{const r=el.getBoundingClientRect();return {left:r.left,right:r.right,scroll:el.matches('.login-card')?Math.max(el.clientWidth,...[...el.children].map(c=>c.getBoundingClientRect().right-r.left)):el.scrollWidth,width:el.clientWidth};});
   assert.ok(g.left>=-1&&g.right<=page.viewportSize().width+1&&g.scroll<=g.width+1,label+' '+JSON.stringify(g));
  }
  async function screenshot(name){await page.screenshot({path:path.join(path.dirname(process.env.COOPVOTE_CANDIDATE_SCREENSHOT),name+'.png'),animations:'disabled'});}
  // Real sign-in remains usable, including short phone/landscape viewports.
  for(const size of sizes){
   await page.setViewportSize(size);await page.goto(origin+'/login.php');
   await fit('.login-card','Login '+JSON.stringify(size));
   await page.fill('#username','testvoter');await page.fill('#password','EndpointTest9!');
   await page.locator('#login-btn').scrollIntoViewIfNeeded();
   await fit('#login-btn','Sign in button');
   if(size.width===375||size.width===1280)await screenshot('login-'+size.width);
   await page.locator('#login-btn').click();await page.waitForURL('**/voter/dashboard.php');
   await req('auth/logout','POST',{});
  }
  await page.goto(origin+'/login.php');
  await page.evaluate(()=>renderVerifyStep({email:'synthetic@example.test'}));
  await page.setViewportSize({width:320,height:480});await fit('#verify-step','Email verification step');await page.locator('#lg-verify-btn').scrollIntoViewIfNeeded();await fit('#lg-verify-btn','Verify button');
  console.log('PASS login: seven viewport sizes, real sign-in, and synthetic email-verification layout.');
  await req('auth/login','POST',{username:'testadmin',password:'EndpointTest9!'});
  const date=n=>new Date(Date.now()+n*86400000).toISOString().slice(0,16);
  const {id}=await req('elections','POST',{title:'Compact module test',startDate:date(1),endDate:date(2),positions:['President']},201);
  const pos=(await req('positions&election='+id))[0],member=(await req('candidates/eligible'))[0];
  const candidate=await req('candidates&election='+id,'POST',{electionId:id,positionId:pos.position_id,memberId:member.id},201);
  const admin=['dashboard','voter-management','officer-management','candidate-management','election-management','election-approvals','vote-monitoring','results','reports','security-logs','themes','branding'];
  const voter=['dashboard','election','candidates','ballot','results','receipts','profile','themes'];
  async function modules(role,names){
   for(const name of names){
    await page.goto(origin+'/'+role+'/'+name+'.php?election='+id);
    await page.waitForFunction(()=>!!window.CoopVote?.user);
    assert.equal(await page.locator('link[href*="compact-ui.css"]').count(),1);
    const content=page.locator('.content,.module-content').first();await content.waitFor();
    for(const size of sizes){await page.setViewportSize(size);await fit('.content,.module-content',role+'/'+name+' '+JSON.stringify(size));}
    // Geometry-only inventory: open each existing dialog without submitting any action.
    const dialogs=await page.locator('.modal-backdrop > .modal, dialog:not(.candidate-photo-dialog)').all();
    for(const dialog of dialogs){
     const native=await dialog.evaluate(el=>el.tagName==='DIALOG');
     const key=await dialog.evaluate(el=>el.id||el.parentElement.id);
     if(native)await dialog.evaluate(el=>el.showModal());
     else await dialog.evaluate(el=>el.parentElement.classList.add('show'));
     for(const size of [{width:375,height:667},{width:1280,height:900},{width:1920,height:1080}]){
      await page.setViewportSize(size);await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
      const box=await dialog.boundingBox();
      const cap=key==='cand-modal'&&role==='admin'?800:key==='voter-import-dialog'?760:key==='election-modal'?660:600;
      assert.ok(box.width<=cap+1&&box.x>=-1&&box.x+box.width<=size.width+1,role+'/'+name+' '+key+' width '+JSON.stringify(box));
      assert.ok(await dialog.evaluate(el=>el.scrollWidth<=el.clientWidth+1),key+' horizontal overflow');
     }
     if(native)await dialog.evaluate(el=>el.close());
     else await dialog.evaluate(el=>el.parentElement.classList.remove('show'));
     console.log('PASS dialog width '+role+'/'+name+'/'+key+': mobile, desktop, wide desktop (geometry only).');
    }
    console.log('PASS module '+role+'/'+name+': seven sizes including short landscape; content fits.');
   }
  }
  await modules('admin',admin);
  async function modal(selector,last,label){
   for(const size of [...sizes,{width:375,height:360}]){
    await page.setViewportSize(size);await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await fit(selector,label+' '+JSON.stringify(size));
    const box=await page.locator(selector).boundingBox();assert.ok(box.width<=(selector.includes('cand-modal')?800:660)+1,label+' excessive width');assert.ok(box.y>=-1&&box.y+box.height<=size.height+1,label+' vertical fit '+JSON.stringify({size,box}));
    await page.locator(last).scrollIntoViewIfNeeded();await fit(last,label+' action');
    const hit=await page.locator(last).evaluate(el=>{const r=el.getBoundingClientRect();return el.contains(document.elementFromPoint(r.left+r.width/2,r.top+r.height/2));});
    assert.ok(hit,label+' action obscured '+JSON.stringify(size));
    if((size.width===375&&size.height===667)||size.width===1280)await screenshot(label.replaceAll(' ','-')+'-'+size.width);
   }
  }
  await page.goto(origin+'/admin/candidate-management.php?election='+id);await page.locator('#add-candidate-btn:enabled').waitFor();await page.locator('#add-candidate-btn').click();
  await page.locator('#f-member:enabled').waitFor();await modal('#cand-modal .modal','#save-candidate-btn','Add candidate');await page.locator('#cand-modal .modal-close').click();
  await page.locator(`#cand-tbody button[onclick="openEdit(${candidate.id})"]`).click();await page.locator('#save-candidate-btn:enabled').waitFor();await modal('#cand-modal .modal','#save-candidate-btn','Edit candidate');await page.locator('#cand-modal .modal-close').click();
  await page.evaluate(id=>viewCandidate(id),candidate.id);await modal('#view-modal .modal','#view-edit-btn','View candidate');await page.locator('#view-modal .modal-close').click();
  await page.goto(origin+'/admin/voter-management.php');await page.locator('#add-voter-btn:enabled').waitFor();await page.locator('#add-voter-btn').click();await modal('#voter-modal .modal','#voter-modal .modal-foot .btn-gold','Add voter');await page.locator('#voter-modal .modal-close').click();
  await page.evaluate(id=>viewVoter(id),member.id);await modal('#view-modal .modal','#view-edit-btn','View voter');await page.locator('#view-modal .modal-close').click();
  await page.evaluate(id=>openEdit(id),member.id);await modal('#voter-modal .modal','#voter-modal .modal-foot .btn-gold','Edit voter');await page.locator('#voter-modal .modal-close').click();
  await page.goto(origin+'/admin/election-management.php?election='+id);await page.locator('#election-select:enabled').waitFor();await page.getByRole('button',{name:'Create Election',exact:true}).click();await modal('#election-modal .modal','#election-next','Create election');await page.locator('#election-modal .modal-close').click();
  await page.locator('#add-position-btn:enabled').click();await modal('#position-modal .modal','#position-save','Add position');await page.locator('#position-modal .modal-close').click();
  await page.goto(origin+'/admin/officer-management.php');await page.locator('#add-officer:enabled').click();await modal('#officer-dialog','#save-officer','Add officer');await page.locator('[data-close="officer-dialog"]').click();
  assert.ok(await page.locator('#password-form').isVisible());
  for(const size of sizes){await page.setViewportSize(size);await fit('#password-form','Admin password form');await page.locator('#save-password').scrollIntoViewIfNeeded();await fit('#save-password','Password action');}
  console.log('PASS add/edit candidate, add voter, create election, add position, add officer dialogs at eight sizes; action buttons reachable; admin password form.');
  // Use the real approval path so voter modules render the active test election.
  await req(`management/elections/${id}/submit`,'POST',{});
  const review=(await req('management/overview')).elections.find(e=>e.id===id).request;
  await req(`management/elections/${id}/decide`,'POST',{requestId:review.request_id,decision:'approve'});await req(`elections/${id}/activate`,'PATCH',{});
  await req('auth/logout','POST',{});await req('auth/login','POST',{username:'testvoter',password:'EndpointTest9!'});
  await modules('voter',voter);
  await page.goto(origin+'/voter/profile.php');await page.locator('#photo-choose:enabled').waitFor();await page.locator('#password-panel summary').click();
  for(const size of sizes){await page.setViewportSize(size);await fit('#profile-form','Voter profile form');await fit('#password-form','Voter password form');}
  assert.deepEqual(errors,[]);console.log('PASS voter profile/password forms; no JavaScript errors. No ballots submitted; disposable test DB only.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

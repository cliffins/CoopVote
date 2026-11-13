'use strict';
const assert=require('node:assert/strict'),path=require('node:path');
const {chromium}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE||'playwright');
const api=process.env.COOPVOTE_TEST_URL,origin=new URL(api).origin;
assert.equal(process.env.COOPVOTE_ENV,'test');assert.equal(new URL(api).hostname,'127.0.0.1');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 try{
  const ctx=await browser.newContext({viewport:{width:1280,height:1000},reducedMotion:'reduce'});
  await ctx.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  async function req(route,method='GET',data,status=200){const r=await ctx.request.fetch(api+'?r='+route,{method,data});assert.equal(r.status(),status,await r.text());return r.json();}
  await req('auth/login','POST',{username:'testadmin',password:'EndpointTest9!'});
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const photo=await page.evaluate(()=>{const c=document.createElement('canvas');c.width=640;c.height=960;const x=c.getContext('2d');x.fillStyle='#174675';x.fillRect(0,0,640,960);x.fillStyle='#e3bf5d';x.fillRect(0,0,640,90);x.fillRect(0,870,640,90);x.fillStyle='#ffffff';x.font='32px sans-serif';x.fillText('Synthetic test photo',140,480);return c.toDataURL('image/png');});
  const date=n=>new Date(Date.now()+n*86400000).toISOString().slice(0,16);
  const {id}=await req('elections','POST',{title:'Photo preview test',startDate:date(1),endDate:date(2),positions:['President']},201);
  const position=(await req('positions&election='+id))[0],members=await req('candidates/eligible');
  const candidate=await req('candidates&election='+id,'POST',{electionId:id,positionId:position.position_id,memberId:members[0].id,photoData:photo,desc:'Committed to clear communication and responsible service.\nI will listen to members and share regular progress updates.',year:'3rd Year',section:'A'},201);
  await req('candidates&election='+id,'POST',{electionId:id,positionId:position.position_id,memberId:members[1].id},201);
  await page.goto(origin+'/admin/candidate-management.php?election='+id);
  await page.locator('#cand-tbody button[data-candidate-photo]').waitFor();
  assert.equal(await page.locator('#cand-tbody button[data-candidate-photo]').count(),1,'Only an actual photo is clickable');
  async function preview(trigger,underlying,label){
   for(const width of [320,375,1280]){
    await page.setViewportSize({width,height:900});
    await trigger.focus();await page.keyboard.press('Enter');
    const dialog=page.locator('#candidate-photo-preview');await dialog.waitFor();
    await page.waitForFunction(()=>{const i=document.querySelector('#candidate-photo-preview>img');return i.complete&&i.naturalWidth===640;});
    const fit=await dialog.evaluate(d=>{const r=d.getBoundingClientRect(),i=d.querySelector('img');return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,img:i.getBoundingClientRect().width,fit:getComputedStyle(i).objectFit,overflow:d.scrollWidth>d.clientWidth+1};});
    assert.ok(fit.left>=0&&fit.right<=width+1&&fit.top>=0&&fit.bottom<=901);assert.equal(fit.fit,'contain');assert.equal(fit.overflow,false);assert.ok(fit.img>200);
    assert.equal(await dialog.locator('button').evaluate(el=>el===document.activeElement),true);
    if(width===375||width===1280) await page.screenshot({path:path.join(path.dirname(process.env.COOPVOTE_CANDIDATE_SCREENSHOT),label+'-'+width+'.png')});
    await page.keyboard.press('Escape');await dialog.waitFor({state:'hidden'});
    assert.equal(await trigger.evaluate(el=>el===document.activeElement),true,'Focus restored');
    if(underlying)assert.equal(await page.locator(underlying).isVisible(),true,'Underlying profile stays open');
   }
   await trigger.click();await page.getByRole('button',{name:'Close photo preview',exact:true}).click();
   assert.equal(await page.locator('#candidate-photo-preview').isVisible(),false);
   await trigger.click();await page.mouse.click(2,2);assert.equal(await page.locator('#candidate-photo-preview').isVisible(),false);
  }
  await preview(page.locator('#cand-tbody button[data-candidate-photo]'),null,'admin-list-photo');
  await page.locator(`#cand-tbody button[onclick="viewCandidate(${candidate.id})"]`).click();
  assert.match(await page.locator('.candidate-profile-election').innerText(),/Photo preview test/);
  assert.match(await page.locator('.candidate-profile-about').innerText(),/clear communication/);
  assert.match(await page.locator('.candidate-profile-facts').innerText(),/Member ID/);
  for(const size of [{width:320,height:640},{width:375,height:667},{width:768,height:900},{width:1280,height:900},{width:844,height:390}]) {
   await page.setViewportSize(size);
   await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
   const fit=await page.locator('#view-modal>.modal').evaluate(d=>{const r=d.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,overflow:d.scrollWidth>d.clientWidth+1};});
   assert.ok(fit.left>=0&&fit.right<=size.width+1&&fit.top>=0&&fit.bottom<=size.height+1,JSON.stringify({size,fit}));assert.equal(fit.overflow,false);
   await page.locator('#view-edit-btn').scrollIntoViewIfNeeded();
   assert.ok(await page.locator('#view-edit-btn').evaluate(el=>{const r=el.getBoundingClientRect();return el.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2));}));
   await page.locator('#view-body').evaluate(el=>el.scrollTop=0);
   if([375,1280].includes(size.width))await page.screenshot({path:path.join(path.dirname(process.env.COOPVOTE_CANDIDATE_SCREENSHOT),'candidate-profile-'+size.width+'.png')});
  }
  await page.locator('#view-edit-btn').click();await page.locator('#cand-modal.show').waitFor();
  assert.equal(await page.locator('#edit-id').inputValue(),String(candidate.id));await page.locator('#cand-modal .modal-close').click();
  await page.locator(`#cand-tbody button[onclick="viewCandidate(${candidate.id})"]`).click();
  await preview(page.locator('#view-body button[data-candidate-photo]'),'#view-modal','admin-profile-photo');
  await page.locator('#view-modal .modal-close').click();
  // Long and markup-like descriptions must remain readable plain text in the profile.
  await page.evaluate(id=>{const c=allCandidates.find(c=>c.id===id);c.desc='<img src=x onerror=alert(1)>\n'+('Long candidate description. '.repeat(90));},candidate.id);
  await page.locator(`#cand-tbody button[onclick="viewCandidate(${candidate.id})"]`).click();
  assert.equal(await page.locator('.candidate-profile-about img').count(),0);
  assert.ok(await page.locator('#view-body').evaluate(el=>el.scrollWidth<=el.clientWidth+1));
  await page.locator('#view-edit-btn').scrollIntoViewIfNeeded();
  console.log('PASS candidate profile content, five viewport sizes, reachable footer, Edit action and escaped long description.');
  await page.locator('#view-modal .modal-close').click();
  await page.locator('#grid-btn').click();await page.locator('#grid-view button[data-candidate-photo]').click();
  await page.getByRole('button',{name:'Close photo preview',exact:true}).click();
  console.log('PASS admin list, grouped card and profile photo; no-photo fallback; 320/375/1280px fit; full image; keyboard open/Escape; close/backdrop; focus restoration; profile remains open.');
  // Activate only this synthetic election so the real voter candidate endpoint can be used.
  await req(`management/elections/${id}/submit`,'POST',{});
  const review=(await req('management/overview')).elections.find(e=>e.id===id).request;
  await req(`management/elections/${id}/decide`,'POST',{requestId:review.request_id,decision:'approve'});
  await req(`elections/${id}/activate`,'PATCH',{});
  await req('auth/logout','POST',{});
  await req('auth/login','POST',{username:'testvoter',password:'EndpointTest9!'});
  await page.goto(origin+'/voter/candidates.php?election='+id);
  await page.locator('.cand-card button[data-candidate-photo]').waitFor();
  await preview(page.locator('.cand-card button[data-candidate-photo]'),null,'voter-card-photo');
  await page.locator(`button[onclick="openModal(${candidate.id})"]`).click();
  await preview(page.locator('#modal-av button[data-candidate-photo]'),'#cand-modal','voter-profile-photo');
  // A failed image gets a recoverable message, not a blank or stuck overlay.
  await page.route('**/missing-preview-image.png',r=>r.fulfill({status:404,body:''}));
  await page.locator('#modal-av img').evaluate(el=>el.src='/missing-preview-image.png');
  await page.locator('#modal-av button[data-candidate-photo]').click();
  await page.locator('#candidate-photo-preview .photo-preview-error:not([hidden])').waitFor();
  await page.getByRole('button',{name:'Close photo preview',exact:true}).click();
  assert.equal(await page.locator('#cand-modal').isVisible(),true);
  assert.deepEqual(errors,[]);
  console.log('PASS real voter candidate card/profile previews at three sizes; image failure recovery; no JavaScript errors. Synthetic database only, no votes cast.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

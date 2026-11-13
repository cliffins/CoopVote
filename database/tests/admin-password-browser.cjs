'use strict';
const assert=require('node:assert/strict');
const {chromium}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE || 'playwright');
const api=process.env.COOPVOTE_TEST_URL,origin=new URL(api).origin;
assert.equal(process.env.COOPVOTE_ENV,'test');assert.equal(new URL(api).hostname,'127.0.0.1');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 try{
  const context=await browser.newContext({viewport:{width:1280,height:1000},reducedMotion:'reduce'});
  await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
  const login=await context.request.post(api+'?r=auth/login',{data:{username:'testadmin',password:'EndpointTest9!'}});assert.equal(login.status(),200);
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/admin/officer-management.php');
  await page.waitForFunction(()=>!!document.getElementById('password-form').onsubmit);
  const form=page.locator('#password-form');
  assert.ok(await form.isVisible(),'Password form visible immediately');
  assert.equal(await page.locator('.password-settings summary').count(),0);
  assert.equal(await form.locator('.password-visibility').count(),3);
  for(const width of [320,375,768,1280,1440]){
   await page.setViewportSize({width,height:1000});
   await form.scrollIntoViewIfNeeded();
   assert.ok(await page.locator('.password-settings').evaluate(el=>el.getBoundingClientRect().width<=520),'Compact panel width '+width);
   assert.ok(await form.evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Password form overflow '+width);
   const layout=await page.evaluate(()=>{
    const accounts=document.querySelector('.officer-directory').getBoundingClientRect();
    const password=document.querySelector('.password-settings').getBoundingClientRect();
    return {sameRow:Math.abs(accounts.top-password.top)<2,separateColumns:password.left>=accounts.right,stacked:password.top>=accounts.bottom};
   });
   assert.ok(width>=1200?layout.sameRow&&layout.separateColumns:layout.stacked,'Accounts/password arrangement '+width);
   assert.ok(await page.locator('#officers').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Officer section overflow '+width);
   assert.ok(await form.locator('.password-visibility').evaluateAll(buttons=>buttons.every(b=>{const r=b.getBoundingClientRect(),p=b.parentElement.getBoundingClientRect();return r.left>=p.left&&r.right<=p.right+1&&r.width>=40;})),'Eye controls fit at '+width);
   if(process.env.COOPVOTE_PASSWORD_SCREENSHOT)await page.locator('.password-settings').screenshot({path:process.env.COOPVOTE_PASSWORD_SCREENSHOT.replace('.png','-'+width+'.png')});
   if(process.env.COOPVOTE_PASSWORD_SCREENSHOT&&width>=1280){
    await page.locator('.module-content').evaluate(el=>el.scrollTop=0);
    await page.screenshot({path:process.env.COOPVOTE_PASSWORD_SCREENSHOT.replace('.png','-page-'+width+'.png')});
   }
  }
  // Hover must not override the eye button's centering, with either motion preference.
  for(const motion of ['no-preference','reduce']){
   await page.emulateMedia({reducedMotion:motion});
   for(const button of await form.locator('.password-visibility').all()){
    await button.scrollIntoViewIfNeeded();await page.mouse.move(0,0);
    const relative=()=>button.evaluate(b=>{const r=b.getBoundingClientRect(),p=b.parentElement.getBoundingClientRect();return [r.x-p.x,r.y-p.y,r.width,r.height];});
    const before=await relative();
    const stable=async state=>{const after=await relative();assert.ok(after.every((v,i)=>Math.abs(v-before[i])<0.5),'Eye moved during '+state+' with '+motion);};
    await button.hover();await page.waitForTimeout(250);await stable('hover');
    await page.mouse.down();await stable('press');await page.mouse.up();await page.waitForTimeout(250);await stable('toggle');
    await page.mouse.move(0,0);await button.focus();await stable('keyboard focus');
    await button.click();
   }
  }
  await page.emulateMedia({reducedMotion:'reduce'});
  await page.locator('#add-officer').click();
  const officer=page.locator('#officer-form');
  for(const [name,example] of Object.entries({name:'e.g. Maria Santos',username:'e.g. maria.santos',email:'e.g. maria.santos@example.com'})){
   assert.equal(await officer.locator(`[name="${name}"]`).getAttribute('placeholder'),example);
   assert.equal(await officer.locator(`[name="${name}"]`).inputValue(),'');
  }
  await officer.locator('[data-close="officer-dialog"]').click();
  for(const field of ['current','new','confirm']){
   const input=page.locator('#admin-'+field+'-password'),button=form.locator(`[data-password-target="admin-${field}-password"]`);
   await input.fill('SyntheticValue9!');
   await button.focus();await page.keyboard.press('Enter');
   assert.equal(await input.getAttribute('type'),'text');assert.equal(await button.getAttribute('aria-pressed'),'true');
   assert.ok((await button.getAttribute('aria-label')).startsWith('Hide '));
   await page.keyboard.press('Space');assert.equal(await input.getAttribute('type'),'password');
   assert.equal(await input.inputValue(),'SyntheticValue9!');
  }
  await form.locator('.password-visibility').first().click();
  await page.locator('#cancel-password').click();
  assert.deepEqual(await form.locator('input').evaluateAll(inputs=>inputs.map(i=>i.value)),['','','']);
  assert.ok(await form.isVisible(),'Password form stays visible after Cancel');
  assert.deepEqual(await form.locator('input').evaluateAll(inputs=>inputs.map(i=>i.type)),['password','password','password']);
  await page.fill('#admin-current-password','EndpointTest9!');
  await page.fill('#admin-new-password','weak');await page.fill('#admin-confirm-password','weak');
  assert.equal(await page.locator('#save-password').isDisabled(),true);
  await page.fill('#admin-new-password','NewSyntheticPassword9!');await page.fill('#admin-confirm-password','Mismatch9!');
  assert.equal(await page.locator('#save-password').isDisabled(),true);
  await page.fill('#admin-confirm-password','NewSyntheticPassword9!');await page.fill('#admin-current-password','IncorrectSynthetic9!');
  const denied=page.waitForResponse(r=>new URL(r.url()).searchParams.get('r')==='voter/change-password');
  await page.locator('#save-password').click();assert.ok((await denied).status()>=400);
  await page.waitForFunction(()=>!document.getElementById('save-password').disabled);
  assert.ok((await form.locator('.form-error').innerText()).length>0);
  await page.fill('#admin-current-password','EndpointTest9!');
  for(const button of await form.locator('.password-visibility').all())await button.click();
  const changed=page.waitForResponse(r=>new URL(r.url()).searchParams.get('r')==='voter/change-password');
  await page.locator('#save-password').click();assert.equal((await changed).status(),200);
  await page.waitForFunction(()=>!document.getElementById('admin-new-password').value);
  assert.deepEqual(await form.locator('input').evaluateAll(inputs=>inputs.map(i=>i.type)),['password','password','password']);
  assert.deepEqual(await form.locator('.password-visibility').evaluateAll(buttons=>buttons.map(b=>b.getAttribute('aria-pressed'))),['false','false','false']);
  assert.equal(await page.locator('#save-password').isDisabled(),true);
  // Verify the update through a fresh login, then restore the disposable fixture password.
  const fresh=await browser.newContext();
  const verified=await fresh.request.post(api+'?r=auth/login',{data:{username:'testadmin',password:'NewSyntheticPassword9!'}});assert.equal(verified.status(),200);
  const restored=await fresh.request.post(api+'?r=voter/change-password',{data:{currentPassword:'NewSyntheticPassword9!',newPassword:'EndpointTest9!'}});assert.equal(restored.status(),200);
  assert.deepEqual(errors,[]);
  console.log('Admin password Chromium PASS: eye position stable through hover/press/toggle/focus with normal and reduced motion; Add Officer examples are placeholders with empty values; three independent keyboard-accessible show/hide controls, preserved input, always-visible form, cancel/success masking, empty reset, weak/mismatch validation, wrong-current rejection, real password update and fresh login, fixture restoration, 320/375/768/1280/1440px layout; accounts and password side by side on desktop, stacked on smaller screens.');
  await fresh.close();await context.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

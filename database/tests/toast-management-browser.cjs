'use strict';
const assert=require('node:assert/strict'),path=require('node:path');
const {chromium}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE);
const api=process.env.COOPVOTE_TEST_URL,origin=new URL(api).origin,out=path.dirname(process.env.COOPVOTE_CANDIDATE_SCREENSHOT);
assert.equal(process.env.COOPVOTE_ENV,'test');assert.equal(new URL(api).hostname,'127.0.0.1');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try {
  for(const fallback of [false,true]){
   const context=await browser.newContext({reducedMotion:'reduce'}),errors=[];
   await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
   if(fallback)await context.addInitScript(()=>{HTMLElement.prototype.showPopover=undefined;});
   const login=await context.request.post(api+'?r=auth/login',{data:{username:'testadmin',password:'EndpointTest9!'}});assert.equal(login.status(),200);
   const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
   async function above(selector){
    await page.locator(selector).last().waitFor();
    const result=await page.locator(selector).last().evaluate(el=>{
     const r=el.getBoundingClientRect(),old=el.style.pointerEvents;el.style.pointerEvents='auto';
     const top=document.elementFromPoint(r.left+r.width/2,r.top+r.height/2);el.style.pointerEvents=old;
     return {visible:el.contains(top),fits:r.left>=0&&r.right<=innerWidth+1&&r.width<=280&&Math.abs(innerWidth-r.right-12)<2&&r.top>=0&&r.bottom<=innerHeight+1};
    });assert.ok(result.visible,'Notification must be above modal/backdrop');assert.ok(result.fits,'Notification must fit viewport');
   }
   for(const width of [320,375,1280]){
    await page.setViewportSize({width,height:800});await page.goto(origin+'/admin/voter-management.php');
    await page.locator('#import-voters-btn:enabled').click();await page.locator('#voter-import-dialog[open]').waitFor();
    await page.locator('#import-file').setInputFiles({name:'old-template.csv',mimeType:'text/csv',buffer:Buffer.from('memberId,first,last,email,phone,address,dob,username\n')});
    await page.locator('#import-preview').click();
    await page.waitForFunction(()=>document.getElementById('import-error').textContent.includes('current template'));
    await above('#toast-wrap .toast');
    assert.equal(await page.locator('#toast-wrap').evaluate(el=>el.parentElement.id),'voter-import-dialog');
    if(!fallback)assert.ok(await page.locator('#toast-wrap').evaluate(el=>el.matches(':popover-open')));
    await page.locator('#import-file').focus();
    await page.evaluate(()=>CoopVote.toast('A second notification stays visible while the import form is open.','info'));
    assert.equal(await page.evaluate(()=>document.activeElement.id),'import-file');await above('#toast-wrap .toast');
    assert.ok(await page.locator('#toast-wrap').evaluate(el=>{const r=el.getBoundingClientRect();return Math.abs(r.top-12)<2;}),'Stack stays at top right');
    assert.ok(await page.locator('#toast-wrap').evaluate(el=>{const rows=[...el.children].map(c=>c.getBoundingClientRect());return rows.every((r,i)=>!i||r.top>=rows[i-1].bottom+5);}),'Stacked messages do not overlap');
    if(!fallback&&[375,1280].includes(width))await page.screenshot({path:path.join(out,'import-toast-'+width+'.png')});
    // The notification overlay must never intercept the form's close button.
    await page.locator('#import-close').click();await page.locator('#voter-import-dialog').waitFor({state:'hidden'});await above('#toast-wrap .toast');
    await page.locator('#import-voters-btn:enabled').click();await above('#toast-wrap .toast');
    await page.locator('#import-close').click();
   }
   console.log('PASS '+(fallback?'fallback':'popover')+': actual CSV error above Import Voters at 320/375/1280; wrapping, stacked notifications, focus, close and reopen.');
   await page.goto(origin+'/admin/voter-management.php');await page.locator('#import-voters-btn:enabled').click();
   await page.evaluate(()=>{window.showToast=undefined;CoopVote.toast('Default notification also appears above the import dialog.','error');});
   await above('#cv-toast-wrap > div');await page.locator('#import-close').click();await above('#cv-toast-wrap > div');
   console.log('PASS '+(fallback?'fallback':'popover')+': default notification renderer with and without an open dialog.');
   await page.setViewportSize({width:320,height:800});
   await page.goto(origin+'/admin/officer-management.php');await page.locator('#add-officer:enabled').click();await page.locator('#officer-dialog[open]').waitFor();
   await page.locator('#officer-form [name=name]').focus();
   await page.evaluate(()=>{const el=document.getElementById('toast');el.textContent='A long officer form notification must wrap inside a small screen without becoming hidden behind the dialog.';CoopVote.presentToast(el,150,()=>{el.hidden=true;});});
   await above('#toast');assert.equal(await page.locator('#toast').evaluate(el=>el.parentElement.id),'officer-dialog');
   await page.evaluate(()=>{const el=document.getElementById('toast');el.textContent='Updated notification';CoopVote.presentToast(el,700,()=>{el.hidden=true;});});
   await page.waitForTimeout(250);assert.equal(await page.locator('#toast').isVisible(),true);await above('#toast');
   await page.waitForTimeout(600);assert.equal(await page.locator('#toast').isVisible(),false);
   if(!fallback)assert.equal(await page.locator('#toast').evaluate(el=>el.matches(':popover-open')),false);
   await page.locator('[data-close="officer-dialog"]').click();
   console.log('PASS '+(fallback?'fallback':'popover')+': Officer Management singleton notification, timer replacement, dismissal and form controls.');
   assert.deepEqual(errors,[]);await context.close();
  }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium,request}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE||'playwright');
const base=process.env.FR10_BASE,dir=process.env.FR10_OUTPUT;
if(process.env.COOPVOTE_ENV!=='test'||new URL(base).hostname!=='127.0.0.1'||!dir)throw Error('Disposable test environment required');
const bytes=name=>fs.readFileSync(`${dir}/${name}.png`),photo=name=>'data:image/png;base64,'+bytes(name).toString('base64');
const cases=[];
function check(name,ok){cases.push({name,pass:!!ok});assert.ok(ok,name);console.log('PASS '+name);}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox'],env:{...process.env,...(process.env.FR10_FONTCONFIG?{FONTCONFIG_FILE:process.env.FR10_FONTCONFIG}:{})}});
 try{
  const ctx=await browser.newContext({viewport:{width:1280,height:1000},reducedMotion:'reduce'});
  const owner=await browser.newContext({viewport:{width:1280,height:1000},reducedMotion:'reduce'});
  for(const c of [ctx,owner])await c.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
  async function api(route,method='GET',data,status=200,client=ctx.request){const r=await client.fetch(base+'/api/index.php?r='+route,{method,data,headers:{'X-Requested-With':'XMLHttpRequest'}});assert.equal(r.status(),status,route+': '+await r.text());return r;}
  async function json(route,method='GET',data,status=200,client=ctx.request){return (await api(route,method,data,status,client)).json();}
  async function candidate(id=9000,eid=900){return (await json('candidates&election='+eid)).find(c=>c.id===id);}
  async function imageMatches(url,name,client=ctx.request){return Buffer.compare(await (await client.get(base+url)).body(),bytes(name))===0;}
  const notices=()=>json('notifications','GET',undefined,200,owner.request);
  const ownProfile=()=>json('voter/profile','GET',undefined,200,owner.request);
  await json('auth/login','POST',{username:'testadmin',password:'EndpointTest9!'});
  await json('auth/login','POST',{username:'privacy0',password:'PrivacyTest9!'},200,owner.request);
  const initial=(await candidate()).photoUrl;
  check('Legacy photos resolve to one shared member URL',initial===(await candidate(9010,901)).photoUrl&&initial===(await ownProfile()).member.photoUrl&&await imageMatches(initial,'member'));
  const page=await ctx.newPage(),voterPage=await owner.newPage(),errors=[];
  for(const p of [page,voterPage])p.on('pageerror',e=>errors.push(e.message));
  async function open(){await page.goto(base+'/admin/candidate-management.php?election=900');await page.locator('button[onclick="openEdit(9000)"]:visible').first().click();await page.waitForFunction(()=>document.getElementById('f-position').value==='9000'&&!document.getElementById('save-candidate-btn').disabled);}
  async function save(){const pending=page.waitForResponse(r=>r.url().includes('r=candidates/9000')&&r.request().method()==='PUT');await page.locator('#save-candidate-btn').click();assert.equal((await pending).status(),200);await page.locator('#cand-modal.show').waitFor({state:'hidden'});}
  await page.goto(base+'/admin/voter-management.php');
  await page.waitForFunction(()=>typeof allVoters!=='undefined'&&allVoters.some(v=>v.id===2));
  await page.evaluate(()=>openEdit(2));
  check('Edit Voter has no phone control',await page.locator('#f-phone').count()===0);
  for(const width of [320,375,768,1280]){
   await page.setViewportSize({width,height:1000});
   const layout=await page.evaluate(()=>{
    const preview=document.getElementById('voter-photo-preview'),picker=document.querySelector('.voter-photo-picker'),hint=document.getElementById('voter-photo-hint');
    const a=preview.getBoundingClientRect(),b=picker.getBoundingClientRect(),h=hint.getBoundingClientRect();
    const text=document.createRange();text.selectNodeContents(preview);const t=text.getBoundingClientRect();
    return {empty:!preview.querySelector('img'),fits:a.left>=0&&b.right<=innerWidth&&h.right<=innerWidth,
     separate:a.right<=b.left&&h.top>=Math.max(a.bottom,b.bottom),
     centered:Math.abs((t.left+t.right-a.left-a.right)/2)<2&&Math.abs((t.top+t.bottom-a.top-a.bottom)/2)<5};
   });
   check('Empty-photo initials and controls fit without overlap at '+width,layout.empty&&layout.fits&&layout.separate&&layout.centered);
   await page.screenshot({path:dir+'/empty-photo-'+width+'.png'});
  }
  await page.locator('#voter-photo-file').setInputFiles(dir+'/original.png');
  await page.waitForFunction(()=>document.getElementById('voter-photo-hint').textContent.startsWith('New photo'));
  await page.locator('#voter-photo-file').setInputFiles([]);
  check('Clearing a selection restores initials and the upload hint',await page.locator('#voter-photo-preview img').count()===0&&(await page.locator('#voter-photo-hint').textContent()).startsWith('JPG'));
  await page.locator('#voter-modal .modal-close').click();
  await page.evaluate(()=>viewVoter(2));
  check('View Voter no longer displays Phone',!(await page.locator('#view-body').innerText()).includes('Phone'));
  await page.locator('#view-modal .modal-close').click();
  await open();check('No Age field or Use member photo control',await page.locator('#f-age,#photo-reset').count()===0);
  await page.locator('#f-desc').fill('Shared profile test');await page.locator('#f-photo').setInputFiles(dir+'/original.png');await page.waitForFunction(()=>document.getElementById('photo-hint').textContent.startsWith('New photo'));await save();
  let c=await candidate(),url=c.photoUrl;
  check('Admin candidate upload reaches voter account and every election',c.desc==='Shared profile test'&&url===(await candidate(9010,901)).photoUrl&&url===(await ownProfile()).member.photoUrl&&await imageMatches(url,'original'));
  check('Age omitted from candidate API and CSV',!Object.hasOwn(c,'age')&&!(await (await api('candidates/export&election=900')).text()).split('\n')[0].includes('Age'));
  let notice=(await notices()).notices.find(n=>n.id.startsWith('profile:'));
  check('Owner receives admin-attributed profile notification',!!notice&&notice.text.includes('testadmin')&&notice.text.includes('updated your profile')&&notice.href==='profile.php');
  check('Notification contains no audit IP, email, or description',!JSON.stringify(notice).includes('@example.test')&&!Object.hasOwn(notice,'ip_address')&&!Object.hasOwn(notice,'description'));
  const other=await request.newContext();await json('auth/login','POST',{username:'testvoter',password:'EndpointTest9!'},200,other);
  check('Other voter cannot see the profile notification',!(await json('notifications','GET',undefined,200,other)).notices.some(n=>n.id===notice.id));
  await json('notifications','POST',{id:notice.id},200,other);
  check('Another account marking a notice does not mark it for its owner',(await notices()).notices.some(n=>n.id===notice.id));
  await voterPage.goto(base+'/voter/profile.php');
  await voterPage.locator('#hero-av img').waitFor();await voterPage.locator('#sidebar-avatar img').waitFor();
  check('Voter profile and sidebar display the shared picture',(await voterPage.locator('#hero-av img').getAttribute('src'))===base+url&&(await voterPage.locator('#sidebar-avatar img').getAttribute('src'))===url);
  await voterPage.screenshot({path:dir+'/shared-voter-account.png'});
  await open();await page.locator('#f-photo').setInputFiles(dir+'/replacement.png');await page.waitForFunction(()=>document.getElementById('photo-hint').textContent.startsWith('New photo'));await page.locator('#cand-modal .modal-close').click();
  check('Cancelled candidate upload changes no profile',(await ownProfile()).member.photoUrl===url);
  const beforeNotices=(await notices()).notices.filter(n=>n.id.startsWith('profile:')).length;
  for(const [name,value] of [['invalid bytes','data:image/png;base64,'+Buffer.from('not an image').toString('base64')],['SVG','data:image/svg+xml;base64,'+Buffer.from('<svg/>').toString('base64')],['oversize','data:image/png;base64,'+Buffer.alloc(2*1024*1024+1).toString('base64')],['too wide',photo('too-wide')],['MIME mismatch',photo('original').replace('image/png','image/jpeg')],['non-string',false]]){
   await api('candidates/9000&election=900','PUT',{photoData:value,desc:'MUST NOT SAVE'},422);
   c=await candidate();check('Reject '+name+' without partial changes',c.photoUrl===url&&c.desc==='Shared profile test');
  }
  await api('candidates/9000&election=901','PUT',{photoData:photo('replacement')},422);
  check('Wrong election and invalid uploads create no notification',(await ownProfile()).member.photoUrl===url&&(await notices()).notices.filter(n=>n.id.startsWith('profile:')).length===beforeNotices);
  await api('candidates/9000&election=900','PUT',{photoData:photo('replacement')},403,other);
  const anon=await request.newContext();await api('voter/profile-photo/10000','GET',undefined,401,anon);await api('voter/candidate-photo/9000','GET',undefined,401,anon);await anon.dispose();
  check('Photo mutations require admin; image reads require login',true);
  // The voter can replace their own photo; every candidacy must follow.
  await voterPage.locator('#profile-photo-file').setInputFiles(dir+'/replacement.png');await voterPage.locator('#photo-upload').click();
  await voterPage.waitForFunction(()=>document.getElementById('photo-status').textContent==='Your profile picture is saved.');
  const voterUrl=(await ownProfile()).member.photoUrl;
  check('Voter upload updates candidate records and sidebar',voterUrl!==url&&(await candidate()).photoUrl===voterUrl&&(await candidate(9010,901)).photoUrl===voterUrl&&await imageMatches(voterUrl,'replacement')&&(await voterPage.locator('#sidebar-avatar img').getAttribute('src'))===voterUrl);
  check('Own upload does not create an admin notification',(await notices()).notices.filter(n=>n.id.startsWith('profile:')).length===beforeNotices);
  // Admin voter management uses the same profile storage and identifies the actor.
  await page.goto(base+'/admin/voter-management.php');await page.waitForFunction(()=>typeof allVoters!=='undefined'&&allVoters.some(v=>v.id===10000));
  await page.evaluate(()=>openEdit(10000));await page.locator('#voter-photo-file').setInputFiles(dir+'/original.png');
  await page.waitForFunction(()=>document.getElementById('voter-photo-hint').textContent.startsWith('New photo'));
  await page.fill('#f-dob','1990-01-01');await page.fill('#f-address','Synthetic profile address');
  await page.locator('#save-voter-btn').click();await page.locator('#voter-modal.show').waitFor({state:'hidden'});
  url=(await candidate()).photoUrl;
  check('Admin voter form saves the shared picture',await imageMatches(url,'original')&&url===(await ownProfile()).member.photoUrl);
  await page.evaluate(()=>openEdit(10000));check('Reopened voter form retains the profile photo',(await page.locator('#voter-photo-preview img').getAttribute('src'))===url);await page.locator('#voter-modal .modal-close').click();
  const account=await json('management/accounts','POST',{name:'Photo Editor',username:'photoeditor',email:'photoeditor@example.test'},201);
  // Officers now verify their address and choose a password; creation returns no password.
  assert.equal(account.mailSent,true);
  assert.equal(Object.hasOwn(account,'password'),false);
  const invitation=fs.readFileSync(process.env.COOPVOTE_OFFICER_TEST_OUTBOX,'utf8').trim().split('\n').map(JSON.parse).findLast(job=>job.id===account.id);
  const editor=await request.newContext();
  await json('auth/officer-verification','POST',{action:'complete',token:invitation.url.split('#token=')[1],password:'PhotoEditorTest9!',confirmPassword:'PhotoEditorTest9!'},200,editor);
  await json('auth/login','POST',{username:'photoeditor',password:'PhotoEditorTest9!'},200,editor);
  await json('candidates/9000&election=900','PUT',{photoData:photo('replacement')},200,editor);
  const attributed=(await notices()).notices.find(n=>n.text.includes('photoeditor'));
  check('Notification identifies the specific second admin',!!attributed&&attributed.text.includes('Photo Editor'));
  await json('notifications','POST',{id:attributed.id},200,owner.request);
  check('Reading notification persists for the owner',!(await notices()).notices.some(n=>n.id===attributed.id));
  // Approved requirement: the saved shared picture persists after refresh/reopen.
  // Automatic synchronization of an already-open tab is outside this acceptance case.
  const refreshedUrl=base+(await ownProfile()).member.photoUrl;
  await voterPage.reload();
  await voterPage.waitForFunction(expected=>document.querySelector('#hero-av img')?.src===expected,refreshedUrl);
  check('Reloaded voter profile retains the admin-updated picture',
   (await voterPage.locator('#hero-av img').getAttribute('src'))===refreshedUrl&&
   await imageMatches((await ownProfile()).member.photoUrl,'replacement',owner.request));
  await open();
  await page.waitForFunction(expected=>document.querySelector('#photo-preview img')?.src===expected,refreshedUrl);
  check('Reopened candidate form retains the admin-updated shared picture',
   (await page.locator('#photo-preview img').getAttribute('src'))===refreshedUrl.slice(base.length));
  await page.locator('#cand-modal .modal-close').click();
  for(const mime of ['image/jpeg','image/webp']){
   const data=await page.evaluate(type=>{const canvas=document.createElement('canvas');canvas.width=20;canvas.height=20;canvas.getContext('2d').fillRect(0,0,20,20);return canvas.toDataURL(type);},mime);
   await json('candidates/9000&election=900','PUT',{photoData:data});
   const response=await ctx.request.get(base+(await candidate()).photoUrl);
   check('Accept '+mime+' with matching bytes',response.headers()['content-type']===mime&&Buffer.compare(await response.body(),Buffer.from(data.split(',')[1],'base64'))===0);
  }
  await json('candidates/9000&election=900','PUT',{photoData:photo('multichunk')});url=(await candidate()).photoUrl;
  check('Multi-chunk photo round trip',bytes('multichunk').length>49152&&await imageMatches(url,'multichunk'));
  await json('candidates/9000&election=900','PUT',{desc:'Profile-only update',photoData:null});
  check('Omitted/null photo cannot clear shared picture',(await candidate()).photoUrl===url);
  const create=await json('candidates&election=900','POST',{memberId:2,electionId:900,positionId:9000,section:'A',year:'2nd Year',photoData:photo('original')},201);
  const added=(await json('candidates&election=900')).find(c=>c.id===create.id);
  check('New candidacy upload persists the shared picture',!!added&&added.memberId===2&&await imageMatches(added.photoUrl,'original'));
  await json('candidates/'+create.id+'&election=900','DELETE');
  check('Deleting candidacy preserves member profile picture',await imageMatches(added.photoUrl,'original'));await api('voter/candidate-photo/'+create.id,'GET',undefined,404);
  check('No uncaught browser errors',errors.length===0);
  fs.writeFileSync(dir+'/RESULTS.json',JSON.stringify({checked_at:new Date().toISOString(),cases,all_passed:true,scope:'Synthetic disposable database only'},null,2));
  await other.dispose();await editor.dispose();await ctx.close();await owner.close();
 }finally{await browser.close();if(!fs.existsSync(dir+'/RESULTS.json'))fs.writeFileSync(dir+'/RESULTS.json',JSON.stringify({cases,all_passed:false},null,2));}
})().catch(e=>{console.error(e);process.exitCode=1;});

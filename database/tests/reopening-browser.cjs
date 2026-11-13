const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const {chromium,request}=require(process.env.COOPVOTE_PLAYWRIGHT_MODULE||'playwright');
const base=process.env.REOPEN_BASE,out=process.env.REOPEN_OUTPUT;
if(process.env.COOPVOTE_ENV!=='test'||new URL(base).hostname!=='127.0.0.1'||!out)throw Error('Disposable test environment required');
const cases=[];
function check(name,ok){cases.push({name,pass:!!ok});if(!ok)throw Error(name);console.log('PASS '+name);}
function fixture(mode){return execFileSync('php',[path.join(__dirname,'reopening-fixture.php'),mode],{env:process.env,encoding:'utf8'});}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox'],env:{...process.env,...(process.env.REOPEN_FONTCONFIG?{FONTCONFIG_FILE:process.env.REOPEN_FONTCONFIG}:{})}});
 const clients=[];
 try{
  const ctx=await browser.newContext({viewport:{width:1440,height:1100}}),page=await ctx.newPage(),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
  await page.goto(base+'/login.php');await page.locator('#username').fill('testadmin');await page.locator('#password').fill('EndpointTest9!');await page.locator('#login-btn').click();await page.waitForURL('**/admin/**');
  async function api(route,method='GET',data,status=200,client=ctx.request){
   const r=await client.fetch(base+'/api/index.php?r='+route,{method,data,headers:{'X-Requested-With':'XMLHttpRequest'}});
   if(r.status()!==status)throw Error(route+' expected '+status+' got '+r.status()+' '+await r.text());return r.json();
  }
  async function election(){return (await api('elections')).find(e=>e.id===900);}
  const end=new Date(Date.now()+2*86400000).toISOString().slice(0,16);
  const later=new Date(Date.now()+3*86400000).toISOString().slice(0,16);
  async function approve(deadline){
   await api('management/elections/900/submit','POST',{proposedEnd:deadline});
   const e=(await api('management/overview')).elections.find(e=>e.id===900);
   await api('management/elections/900/decide','POST',{requestId:e.request.request_id,decision:'approve'});
  }
  for(let i=0;i<8;i++){
   const c=await request.newContext();clients.push(c);
   await api('auth/login','POST',{username:'privacy'+i,password:'PrivacyTest9!'},200,c);
   if(i===7)continue;
   const votes=[];
   for(let p=0;p<4;p++){
    const chosen=(p===0&&i<6)||p===1||(p===2&&i===0);
    votes.push(chosen?{positionId:9000+p,candidateId:9000+p*2+(p===0&&i>=3?1:0)}:{positionId:9000+p,candidateId:null,abstain:true});
   }
   await api('voter/vote','POST',{electionId:900,votes},201,c);
  }
  await api('elections/900/close','PATCH',{});await api('elections/900/release','PATCH',{});
  fixture('snapshot');check('Fixture contains seven recorded ballots, fourteen signed votes and released results',(await election()).published);
  const noApproval=await api('elections/900/reopen','PATCH',{endDate:end},409);
  check('Reopening still requires fresh approval',noApproval.code==='APPROVAL_REQUIRED');
  await api('elections/900/reopen','PATCH',{endDate:'2020-01-01T00:00'},422);
  await api('elections/900/reopen','PATCH',{endDate:'invalid'},422);
  check('Past and invalid deadlines rejected',true);
  await approve(end);
  const mismatch=await api('elections/900/reopen','PATCH',{endDate:later},409);
  check('Deadline must match administrator approval',mismatch.code==='APPROVAL_REQUIRED');
  await page.goto(base+'/admin/election-management.php?election=900');
  const card=page.locator('.ec[data-election-id="900"]');
  await card.getByRole('button',{name:'Reopen Election',exact:true}).click();
  await page.locator('#reopen-modal.show').waitFor();
  check('Reopen dialog uses approved deadline',await page.locator('#reopen-end').inputValue()===end);
  await page.locator('#reopen-modal .modal').screenshot({path:out+'/01-reopen-confirmation.png'});
  const response=page.waitForResponse(r=>r.url().includes('r=elections/900/reopen'));
  await page.locator('#reopen-save').click();
  check('Admin UI reopens an election with existing votes',(await response).status()===200);
  await page.locator('#reopen-modal.show').waitFor({state:'hidden'});
  await page.waitForFunction(()=>allElections.find(e=>e.id===900)?.status==='Active');
  await card.screenshot({path:out+'/02-reopened-with-votes.png'});
  fixture('check-reopened');
  check('Original ballots, votes, signatures and prior signed report preserved exactly',true);
  check('Reopened election is active with results unpublished',!(await election()).published);
  const results=(await api('results')).elections.find(e=>e.id===900);
  check('Candidate totals hidden again while voting is open',results.positions.length===0);
  await api('operations/report&election=900&format=json','GET',undefined,409);
  await api('results/export&election=900','GET',undefined,409);
  check('Reports and exports cannot disclose reopened results',true);
  const votes=[{positionId:9000,candidateId:9001},{positionId:9001,candidateId:9002},{positionId:9002,candidateId:null,abstain:true},{positionId:9003,candidateId:null,abstain:true}];
  await api('voter/vote','POST',{electionId:900,votes},409,clients[0]);
  check('Previously recorded voter cannot vote twice',true);
  await api('voter/vote','POST',{electionId:900,votes},201,clients[7]);
  await api('voter/vote','POST',{electionId:900,votes},409,clients[7]);
  check('Unvoted member can vote once during reopened period',(await election()).voted===8);
  await api('elections/900/reopen','PATCH',{endDate:end},409);
  await api('elections/902/reopen','PATCH',{endDate:end},409);
  check('Active and cancelled elections cannot be reopened',true);
  await api('elections/900/close','PATCH',{});
  await approve(later);fixture('tamper');
  const damaged=await api('elections/900/reopen','PATCH',{endDate:later},409);
  check('Integrity failure still blocks reopening',damaged.code==='INTEGRITY_CHECK_FAILED'&&(await election()).status==='Closed');
  fixture('repair');
  await api('elections/900/release','PATCH',{});
  check('Election can close and explicitly release updated results',(await election()).published);
  const anon=await request.newContext();clients.push(anon);
  await api('elections/900/reopen','PATCH',{endDate:later},401,anon);
  await api('elections/900/reopen','PATCH',{endDate:later},403,clients[7]);
  check('Anonymous users and voters cannot reopen elections',true);
  check('No browser JavaScript errors',errors.length===0);
  fs.writeFileSync(out+'/RESULTS.json',JSON.stringify({checked_at:new Date().toISOString(),cases,all_passed:true,scope:'Approved reopening with stored ballots in disposable environment only'},null,2));
 }finally{
  for(const c of clients)await c.dispose();await browser.close();
  if(!fs.existsSync(out+'/RESULTS.json'))fs.writeFileSync(out+'/RESULTS.json',JSON.stringify({cases,all_passed:false},null,2));
 }
})().catch(e=>{console.error(e);process.exitCode=1;});

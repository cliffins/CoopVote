// Verify logout at the real document transition boundary, including background 401s.
const fs = require('node:fs');
const helper = fs.readFileSync('tests/lib/browser.js', 'utf8');
const prefix = helper.slice(0, helper.indexOf('async function main()'));
const main = async function () {
  const base = 'http://127.0.0.1:8097/', port = 9356, results = [], baseline = process.argv.includes('--baseline');
  const proc = spawn(findChrome(), ['--headless=new','--disable-gpu','--no-first-run',`--remote-debugging-port=${port}`,
    `--user-data-dir=${fs.mkdtempSync(path.join(os.tmpdir(),'coopvote-logout-'))}`,'about:blank'], {stdio:'ignore'});
  let ws;
  try {
    if (!await waitForDevTools(port,15000)) throw Error('Browser did not start');
    const tab = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`,{method:'PUT'})).json();
    ws = new WebSocket(tab.webSocketDebuggerUrl); await new Promise(r=>ws.addEventListener('open',r));
    let id=0; const pending=new Map();
    ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(pending.has(m.id)){pending.get(m.id)(m);pending.delete(m.id);}});
    const send=(method,params={})=>new Promise(r=>{const n=++id;pending.set(n,r);ws.send(JSON.stringify({id:n,method,params}));});
    const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true});if(r.error||r.result?.exceptionDetails)throw Error(JSON.stringify(r.error||r.result.exceptionDetails));return r.result?.result?.value;};
    const wait=async expression=>{for(let i=0;i<100;i++){await sleep(100);try{if(await evaluate(expression))return;}catch{}}throw Error('Timed out: '+expression+' at '+await evaluate('location.href'));};
    await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
    await send('Page.addScriptToEvaluateOnNewDocument',{source:`window.logoutProbe={native:false,ready:false,errors:[]};addEventListener('error',e=>logoutProbe.errors.push(e.message));addEventListener('pagereveal',e=>{if(e.viewTransition){logoutProbe.native=true;e.viewTransition.ready.then(()=>logoutProbe.ready=true,()=>{});}});`});
    await (async function(){
 const fx=JSON.parse(fs.readFileSync('tests/reports/election-context-fixtures.json','utf8'));let countA=1;
 const check=(name,pass,detail)=>{results.push({name,pass:!!pass,detail});if(!pass)throw Error(name+': '+JSON.stringify(detail));};
 await send('Page.navigate',{url:base+'api/index.php?r=branding'});await sleep(300);
 check('Scratch admin login',await evaluate(`fetch('/api/index.php?r=auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:'t_admin',password:'Test@1234'})}).then(r=>r.status)`)===200);
 for(const width of [1440,390]){
  await send('Emulation.setDeviceMetricsOverride',{width,height:850,deviceScaleFactor:1,mobile:false});
  await send('Page.navigate',{url:base+'admin/candidate-management.html'});
  await wait('!!window.CoopVote?.user && typeof candidateFilterElections!=="undefined" && candidateFilterElections.length>0');
  check('No mixed list before choosing '+width,await evaluate('filtered.length===0 && !document.getElementById("election-filter").innerHTML.includes("All Elections")'));
  await evaluate('openAdd()');check('Add requires election '+width,await evaluate('!document.getElementById("cand-modal").classList.contains("show")'));
  for(const [e,count] of [[fx.a,countA],[fx.b,2],[fx.empty,0]]){
   await evaluate(`document.getElementById('election-filter').value='${e.id}';changeCandidateElection()`);
   await wait(`document.getElementById('sc-total').textContent==='${count}' && document.getElementById('election-filter').value==='${e.id}'`);await sleep(200);
   check('Scoped candidates '+e.title+' '+width,await evaluate(`filtered.length===${count} && filtered.every(c=>c.electionId===${e.id})`));
   check('No horizontal overflow '+e.title+' '+width,await evaluate('document.documentElement.scrollWidth<=innerWidth'));
  }
  await evaluate(`document.getElementById('election-filter').value='${fx.a.id}';changeCandidateElection()`);await wait(`filtered.length===${countA}`);
  await evaluate('openAdd()');await wait('availableMembers.length>0 && availablePositions.length===2 && !candidatePositionsLoading');
  check('Add form locked to list election '+width,await evaluate(`document.getElementById('f-election').disabled && document.getElementById('f-election').value==='${fx.a.id}' && availablePositions.every(p=>Number(p.election_id)===${fx.a.id})`));
  check('Existing nominees excluded '+width,await evaluate(`!availableMembers.some(m=>m.id===${Number(fx.members[0])})`));
  if(width===1440){
   check('Voter nominated only in B remains eligible for A',await evaluate(`availableMembers.some(m=>m.id===${Number(fx.members[1])})`));
   await evaluate(`document.getElementById('f-member').value='${fx.members[1]}';selectCandidateMember()`);await wait('!candidatePositionsLoading && availablePositions.length===2');
   await evaluate(`document.getElementById('f-position').value='${fx.a.position}';document.getElementById('f-occ').value='Member';document.getElementById('f-years').value='1';document.getElementById('f-desc').value='Only A browser';updateCandidateSaveState();`);
   check('Valid scoped candidate can save',await evaluate('!document.getElementById("save-candidate-btn").disabled'));
   await evaluate('saveCandidate()');await wait('!document.getElementById("cand-modal").classList.contains("show") && filtered.length===2');countA=2;
   check('Saving in A preserves B',await evaluate(`CoopVote.api('candidates&election=${fx.b.id}').then(rows=>rows.length===2 && rows.every(c=>c.electionId===${fx.b.id}))`));
  }else await evaluate('closeModal("cand-modal")');
  await evaluate(`openEdit(${fx.ca})`);await wait('availablePositions.length===2 && !candidatePositionsLoading && !document.getElementById("save-candidate-btn").disabled');
  check('Edit keeps original election '+width,await evaluate(`document.getElementById('f-election').value==='${fx.a.id}' && availablePositions.every(p=>Number(p.election_id)===${fx.a.id})`));await evaluate('closeModal("cand-modal")');
  const exported=await evaluate(`(async()=>{let route;const old=CoopVote.download;CoopVote.download=async r=>{route=r;return false};await exportCandidates();CoopVote.download=old;return route;})()`);
  check('Export stays in A '+width,exported==='candidates/export&election='+fx.a.id,exported);
  await send('Page.reload');await wait(`typeof filtered!=='undefined' && filtered.length===${countA}`);check('Refresh keeps A '+width,await evaluate(`document.getElementById('election-filter').value==='${fx.a.id}'`));
  await send('Page.navigate',{url:base+'admin/election-management.html'});await wait('typeof allElections!=="undefined" && allElections.length>0');
  await evaluate(`selectPositionsElection('${fx.a.id}');openAddPosition()`);check('Position form stays in selected A '+width,await evaluate(`document.getElementById('p-election').disabled && document.getElementById('p-election').value==='${fx.a.id}'`));await evaluate('closeModal("position-modal");openCreate()');
  check('New election has no inherited position selections '+width,await evaluate('document.querySelectorAll("#pos-checklist input:checked").length===0'));await evaluate('closeModal("election-modal")');
  const link=await evaluate(`[...document.querySelectorAll('.ec-row a')].find(a=>a.href.endsWith('candidate-management.html?election=${fx.b.id}'))?.href`);check('Election card links directly to its candidates '+width,!!link);
  await send('Page.navigate',{url:link});await wait(`typeof filtered!=='undefined' && filtered.length===2 && filtered.every(c=>c.electionId===${fx.b.id})`);check('Election link opens B only '+width,true);
  await sleep(750);const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tests/reports/election-context-'+width+'.png',Buffer.from(shot.result.data,'base64'));
  check('No browser errors '+width,await evaluate('logoutProbe.errors.length===0'));
 }
})();
  } finally {
    fs.writeFileSync('tests/reports/election-context-browser.json',JSON.stringify(results,null,2));
    const failures=results.filter(r=>!r.pass);console.log(JSON.stringify({checked:results.length,failed:failures.length,failures},null,2));
    if(failures.length)process.exitCode=1;
    ws?.close();proc.kill();
  }
};
new Function('require',prefix+'\n('+main.toString()+')().catch(e=>{console.error(e);process.exitCode=1;});')(require);

// Exercise the real CSV picker, preview, confirmation and responsive import dialog.
const fs = require('node:fs');
const helper = fs.readFileSync('tests/lib/browser.js', 'utf8');
const prefix = helper.slice(0, helper.indexOf('async function main()'));
const main = async function () {
  const base = 'http://127.0.0.1:8098/', port = 9358, results = [], baseline = process.argv.includes('--baseline');
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

 const check=(name,pass,detail)=>{results.push({name,pass:!!pass,detail});if(!pass)throw Error(name+': '+JSON.stringify(detail));};
 await send('Page.navigate',{url:base+'api/index.php?r=branding'});await sleep(300);
 check('Scratch login',await evaluate(`fetch('/api/index.php?r=auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:'t_admin',password:'Test@1234'})}).then(r=>r.status)`)===200);
 for(const width of [1440,390]){
  await send('Emulation.setDeviceMetricsOverride',{width,height:850,deviceScaleFactor:1,mobile:false});
  await send('Page.navigate',{url:base+'admin/voter-management.html'});
  await wait('!!window.CoopVote?.user && !document.getElementById("import-voters-btn").disabled');
  await evaluate('document.getElementById("import-voters-btn").click()');
  await wait('document.getElementById("voter-import-dialog").open');
  const routes=await evaluate(`(async()=>{const routes=[],original=CoopVote.download;CoopVote.download=async r=>{routes.push(r);return true;};await document.getElementById('import-template').onclick();await document.getElementById('import-template-csv').onclick();CoopVote.download=original;return routes;})()`);
  check('Both template buttons use the right formats '+width,JSON.stringify(routes)===JSON.stringify(['voters/import/template&format=xlsx','voters/import/template&format=csv']));
  check('Preview disabled without file '+width,await evaluate('document.getElementById("import-preview").disabled'));
  const csv='memberId,first,last,email,phone,address,dob,username\n,Elena,Santos,elena'+width+'@test.coop,09171234567,"Barangay Uno, Santiago",1990-01-02,\n,Invalid123,Name,wrong,letters, ,2999-01-01,bad username\n';
  await evaluate(`(()=>{const dt=new DataTransfer();dt.items.add(new File([${JSON.stringify(csv)}],'voters.csv',{type:'text/csv'}));const el=document.getElementById('import-file');el.files=dt.files;el.dispatchEvent(new Event('change'));document.getElementById('import-preview').click();})()`);
  await wait('document.getElementById("import-summary").textContent.includes("1 ready")');
  check('Invalid row shown '+width,await evaluate('document.getElementById("import-summary").textContent.includes("1 need correction") && document.getElementById("import-rows").innerText.includes("Numbers are not allowed")'));
  check('Dialog fits viewport '+width,await evaluate('(()=>{const r=document.getElementById("voter-import-dialog").getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.top>=0&&r.bottom<=innerHeight;})()'));
  check('Upload content does not scroll sideways '+width,await evaluate('(()=>{const el=document.querySelector(".import-body");return el.scrollWidth<=el.clientWidth;})()'));
  check('Table scroll stays in dialog '+width,await evaluate('document.documentElement.scrollWidth<=innerWidth'));
  for(const theme of ['light','dark']){
   await evaluate(`document.documentElement.setAttribute('data-cv-theme','${theme}')`);await sleep(200);
   const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tests/reports/voter-import-'+width+'-'+theme+'.png',Buffer.from(shot.result.data,'base64'));
  }
  await evaluate('document.getElementById("import-confirm").click();document.getElementById("import-confirm").click()');
  await wait('document.getElementById("import-summary").textContent.includes("1 voter accounts created")');
  check('Credentials available '+width,await evaluate('!document.getElementById("import-download").hidden && document.getElementById("import-rows").innerText.includes("Email disabled")'));
  await wait(`allVoters.filter(v=>v.email==='elena${width}@test.coop').length===1`);
  check('Created voter list refreshed '+width,true);
  await evaluate('document.getElementById("import-close").click();document.getElementById("import-voters-btn").click()');await wait('document.getElementById("voter-import-dialog").open');
  check('Reopen retains result '+width,await evaluate('document.getElementById("import-summary").textContent.includes("1 voter accounts created")'));
  await evaluate('document.getElementById("import-new").click()');await wait('document.getElementById("import-review").hidden');
  check('New import clears file '+width,await evaluate('document.getElementById("import-file").files.length===0'));
  check('No browser errors '+width,await evaluate('logoutProbe.errors.length===0'));
 }

})();
  } finally {
    fs.writeFileSync('tests/reports/voter-import-browser.json',JSON.stringify(results,null,2));
    const failures=results.filter(r=>!r.pass);console.log(JSON.stringify({checked:results.length,failed:failures.length,failures},null,2));
    if(failures.length)process.exitCode=1;
    ws?.close();proc.kill();
  }
};
new Function('require',prefix+'\n('+main.toString()+')().catch(e=>{console.error(e);process.exitCode=1;});')(require);

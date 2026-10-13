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
    let id=0; const pending=new Map(), held=[];
    ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.method==='Fetch.requestPaused')held.push(m.params);if(pending.has(m.id)){pending.get(m.id)(m);pending.delete(m.id);}});
    const send=(method,params={})=>new Promise(r=>{const n=++id;pending.set(n,r);ws.send(JSON.stringify({id:n,method,params}));});
    const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true});if(r.error||r.result?.exceptionDetails)throw Error(JSON.stringify(r.error||r.result.exceptionDetails));return r.result?.result?.value;};
    const wait=async expression=>{for(let i=0;i<100;i++){await sleep(100);try{if(await evaluate(expression))return;}catch{}}throw Error('Timed out: '+expression+' at '+await evaluate('location.href'));};
    await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
    await send('Page.addScriptToEvaluateOnNewDocument',{source:`window.logoutProbe={native:false,ready:false,errors:[]};addEventListener('error',e=>logoutProbe.errors.push(e.message));addEventListener('pagereveal',e=>{if(e.viewTransition){logoutProbe.native=true;e.viewTransition.ready.then(()=>logoutProbe.ready=true,()=>{});}});`});
    for(const role of ['admin','voter']) {
      await send('Network.clearBrowserCookies');
      await send('Page.navigate',{url:base+'api/index.php?r=branding'});await sleep(350);
      await evaluate(`fetch('/api/index.php?r=auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:'${role==='admin'?'t_admin':'t_voter10'}',password:'Test@1234'})}).then(r=>{if(!r.ok)throw Error('Login failed')})`);
      await send('Page.navigate',{url:base+role+'/dashboard.html'});
      await wait('!!window.CoopVote?.user');await sleep(300);
      await evaluate(`(()=>{const sample={click:Date.now()};const save=()=>sessionStorage.setItem('logout-perf',JSON.stringify(sample));const original=fetch;window.fetch=async(...args)=>{const response=await original(...args);if(String(args[0]).includes('auth/logout')){sample.response=Date.now();save();}return response;};addEventListener('pagehide',()=>{sample.hide=Date.now();save();},{once:true});save();CoopVote.logout();})()`);
      await wait('location.pathname.endsWith("/login.html") && document.readyState === "complete"');await sleep(200);
      const data=await evaluate(`(()=>{const sample=JSON.parse(sessionStorage.getItem('logout-perf')),n=performance.getEntriesByType('navigation')[0];return {apiMs:sample.response-sample.click,afterResponseMs:sample.hide-sample.response,domReadyMs:Math.round(performance.timeOrigin+n.domContentLoadedEventEnd-sample.click),paint:performance.getEntriesByType('paint').map(p=>({name:p.name,ms:Math.round(performance.timeOrigin+p.startTime-sample.click)})),resources:performance.getEntriesByType('resource').filter(r=>r.duration>20).map(r=>({name:r.name.split('/').pop(),duration:Math.round(r.duration)})),errors:logoutProbe.errors};})()`);
      results.push({role,...data,pass:!data.errors.length});console.log(JSON.stringify(results.at(-1)));
      await send('Network.setCacheDisabled',{cacheDisabled:true});
      await send('Fetch.enable',{patterns:[{urlPattern:'*fonts.googleapis.com/*'},{urlPattern:'*three.min.js'}]});
      await send('Page.navigate',{url:base+'login.html?bye=1'});
      await wait('typeof handleSubmit === "function" && !!window.CoopVote && document.readyState !== "loading"');await sleep(300);
      const responsive=await evaluate(`(()=>{togglePassword();const works=document.getElementById('password').type==='text';togglePassword();return {headline:document.getElementById('tw-headline').textContent,opacity:getComputedStyle(document.querySelector('.login-card')).opacity,formWorks:works,scenePending:typeof THREE==='undefined',notice:document.getElementById('notice-msg').textContent,errors:logoutProbe.errors};})()`);
      results.push({role,check:'form works while fonts and 3D are stalled',...responsive,held:held.map(r=>r.request.url),pass:responsive.headline==='Welcome Back'&&responsive.opacity==='1'&&responsive.formWorks&&responsive.scenePending&&responsive.notice.includes('signed out')&&!responsive.errors.length&&held.some(r=>r.request.url.includes('fonts.googleapis.com'))&&held.some(r=>r.request.url.includes('three.min.js'))});
      for(const request of held.splice(0))await send('Fetch.continueRequest',{requestId:request.requestId});
      await send('Fetch.disable');await send('Network.setCacheDisabled',{cacheDisabled:false});
      await wait(`typeof THREE !== 'undefined' && !!document.querySelector('script[src*="login-background.js"]')`);
    }
  } finally {
    fs.writeFileSync('tests/reports/logout-performance.json',JSON.stringify(results,null,2));
    const failures=results.filter(r=>!r.pass);console.log(JSON.stringify({checked:results.length,failed:failures.length,failures},null,2));
    if(failures.length)process.exitCode=1;
    ws?.close();proc.kill();
  }
};
new Function('require',prefix+'\n('+main.toString()+')().catch(e=>{console.error(e);process.exitCode=1;});')(require);

// Browser regression: shared navigation must collapse at the same screen width.
const fs=require('node:fs');
const helper=fs.readFileSync('tests/lib/browser.js','utf8');
const prefix=helper.slice(0,helper.indexOf('async function main()'));
const main=async function(){
  const base='http://127.0.0.1:8097/',port=9348,chrome=findChrome();
  if(!chrome)throw Error('Chrome is required for sidebar verification');
  const proc=spawn(chrome,['--headless=new','--disable-gpu','--no-first-run',`--remote-debugging-port=${port}`,`--user-data-dir=${fs.mkdtempSync(path.join(os.tmpdir(),'coopvote-sidebar-'))}`,'about:blank'],{stdio:'ignore'});
  let ws;
  const results=[];
  try{
    if(!await waitForDevTools(port,15000))throw Error('Browser did not start');
    const tab=await(await fetch(`http://127.0.0.1:${port}/json/new?about:blank`,{method:'PUT'})).json();
    ws=new WebSocket(tab.webSocketDebuggerUrl);await new Promise(r=>ws.addEventListener('open',r));
    let id=0;const pending=new Map();
    ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(pending.has(m.id)){pending.get(m.id)(m);pending.delete(m.id);}});
    const send=(method,params={})=>new Promise(r=>{const n=++id;pending.set(n,r);ws.send(JSON.stringify({id:n,method,params}));});
    const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.result?.exceptionDetails)throw Error(r.result.exceptionDetails.text);return r.result?.result?.value;};
    await send('Page.enable');await send('Runtime.enable');await send('Network.enable');await send('Network.setCacheDisabled',{cacheDisabled:true});
    for(const role of ['admin','voter']){
      await send('Network.clearBrowserCookies');
      await send('Page.navigate',{url:base+'api/index.php?r=branding'});await sleep(600);
      const username=role==='admin'?'t_admin':'t_voter1';
      const status=await evaluate(`fetch('/api/index.php?r=auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:${JSON.stringify(username)},password:'Test@1234'})}).then(r=>r.status)`);
      if(status!==200)throw Error('Scratch sign-in failed: '+status);
      const pages=fs.readdirSync(role).filter(f=>f.endsWith('.html')).map(f=>role+'/'+f);
      if(role==='admin')pages.push(...['officers','approvals','themes','branding'].map(tab=>'management.html#'+tab));
      for(const page of pages){
        await send('Page.navigate',{url:base+page});
        let ready=false;
        for(let i=0;i<60;i++){await sleep(150);ready=await evaluate("!!(window.CoopVote?.user && document.querySelector('.sidebar a'))");if(ready)break;}
        if(!ready)throw Error('Page did not load: '+page);
        for(const width of [850,390,1200]){
          await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});await sleep(350);
          const result=await evaluate(`(async()=>{
            const s=document.querySelector('.sidebar'),toggle=document.querySelector('.hamburger'),overlay=document.getElementById('overlay')||document.getElementById('sidebar-overlay');
            const mode=getComputedStyle(s).position,toggleVisible=getComputedStyle(toggle).display!=='none';
            if(innerWidth>900)return {mode,toggleVisible,pass:mode!=='fixed'&&!toggleVisible};
            if(s.classList.contains('open'))toggle.click();
            toggle.click();const opened=s.classList.contains('open');
            const link=s.querySelector('a[href]');link.href=location.pathname+location.search+'#sidebar-check';link.click();
            await new Promise(r=>setTimeout(r,350));
            const closed=!s.classList.contains('open')&&s.getBoundingClientRect().right<=1;
            const overlayClosed=overlay.hidden||getComputedStyle(overlay).pointerEvents==='none';
            toggle.click();overlay.click();await new Promise(r=>setTimeout(r,350));
            const outsideClosed=!s.classList.contains('open');
            return {mode,toggleVisible,opened,closed,overlayClosed,outsideClosed,pass:mode==='fixed'&&toggleVisible&&opened&&closed&&overlayClosed&&outsideClosed};
          })()`);
          results.push({page,width,...result});
        }
        console.log(page+': '+(results.slice(-3).every(r=>r.pass)?'PASS':'FAIL'));
      }
    }
    fs.writeFileSync('tests/reports/sidebar-responsive.json',JSON.stringify(results,null,2));
    const failures=results.filter(r=>!r.pass);console.log(JSON.stringify({checked:results.length,failed:failures.length,failures}));
    if(failures.length)process.exitCode=1;
  }finally{ws?.close();proc.kill();}
};
new Function('require',prefix+'\n('+main.toString()+')().catch(e=>{console.error(e);process.exitCode=1;});')(require);

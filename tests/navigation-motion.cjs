// Real link navigation across the isolated admin/member modules, including history.
const fs=require('node:fs');
const helper=fs.readFileSync('tests/lib/browser.js','utf8');
const prefix=helper.slice(0,helper.indexOf('async function main()'));
const main=async function(){
  const base='http://127.0.0.1:8097/', port=9349;
  const proc=spawn(findChrome(),['--headless=new','--disable-gpu','--no-first-run',`--remote-debugging-port=${port}`,`--user-data-dir=${fs.mkdtempSync(path.join(os.tmpdir(),'coopvote-motion-'))}`,'about:blank'],{stdio:'ignore'});
  let ws;const results=[];
  try {
    if(!await waitForDevTools(port,15000))throw Error('Browser did not start');
    const tab=await(await fetch(`http://127.0.0.1:${port}/json/new?about:blank`,{method:'PUT'})).json();
    ws=new WebSocket(tab.webSocketDebuggerUrl);await new Promise(r=>ws.addEventListener('open',r));
    let id=0;const pending=new Map();
    ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(pending.has(m.id)){pending.get(m.id)(m);pending.delete(m.id);}});
    const send=(method,params={})=>new Promise(r=>{const n=++id;pending.set(n,r);ws.send(JSON.stringify({id:n,method,params}));});
    const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true});if(r.error||r.result?.exceptionDetails)throw Error(JSON.stringify(r.error||r.result.exceptionDetails));return r.result?.result?.value;};
    await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
    await send('Page.addScriptToEvaluateOnNewDocument',{source:`window.motionProbe={id:Math.random(),transition:false,ready:false,finished:false,errors:[]};addEventListener('error',e=>motionProbe.errors.push(e.message));addEventListener('pagereveal',e=>{if(e.viewTransition){motionProbe.transition=true;e.viewTransition.ready.then(()=>motionProbe.ready=true,()=>{});e.viewTransition.finished.then(()=>motionProbe.finished=true);}});`});
    const waitPage=async page=>{
      for(let i=0;i<100;i++){
        await sleep(80);
        try{if(await evaluate(`location.href===${JSON.stringify(base+page)} && !!(window.CoopVote?.user && document.querySelector('[data-cv-nav="themes"]')) && (!location.pathname.endsWith('/management.html') || document.getElementById(${JSON.stringify(page.split('#')[1]||'themes')})?.hidden===false)`))return;}catch{}
      }
      throw Error('Page did not load: '+page);
    };
    for(const role of ['admin','voter'])for(const width of [1440,390]){
      await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
      await send('Network.clearBrowserCookies');await send('Page.navigate',{url:base+'api/index.php?r=branding'});await sleep(400);
      const status=await evaluate(`fetch('/api/index.php?r=auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:${JSON.stringify(role==='admin'?'t_admin':'t_voter1')},password:'Test@1234'})}).then(r=>r.status)`);
      if(status!==200)throw Error('Scratch sign-in failed '+status);
      await send('Page.navigate',{url:base+role+'/dashboard.html'});await waitPage(role+'/dashboard.html');
      const pages=fs.readdirSync(role).filter(f=>f.endsWith('.html')&&f!=='dashboard.html').map(f=>role+'/'+f);
      pages.push('management.html#themes');if(role==='admin')pages.push('management.html#branding','management.html#themes','management.html#officers','management.html#approvals');pages.push(role+'/dashboard.html');
      for(const page of pages){
        const before=await evaluate(`(()=>{const s=document.querySelector('.sidebar');s.scrollTop=s.scrollHeight;return {id:motionProbe.id,scroll:s.scrollTop,x:s.getBoundingClientRect().x,width:s.getBoundingClientRect().width};})()`);
        const clicked=await evaluate(`(()=>{const s=document.querySelector('.sidebar');if(innerWidth<=900&&!s.classList.contains('open'))document.querySelector('.hamburger').click();const a=[...s.querySelectorAll('a[href]')].find(a=>a.href===${JSON.stringify(base+page)});if(!a)return false;a.click();return true;})()`);
        if(!clicked)throw Error('Missing destination '+page);
        await waitPage(page);await sleep(300);
        const state=await evaluate(`(()=>{const s=document.querySelector('.sidebar'),r=s.getBoundingClientRect();return {...motionProbe,scroll:s.scrollTop,x:r.x,sidebarWidth:r.width,open:s.classList.contains('open'),overflow:document.documentElement.scrollWidth>innerWidth,motion:getComputedStyle(s.querySelector('a')).transitionDuration,entry:getComputedStyle(document.querySelector('.app > .main, .settings-shell > main')).animationName};})()`);
        const sameDocument=state.id===before.id;
        const pass=!state.errors.length&&!state.overflow&&(width>900?Math.abs(state.x-before.x)<1&&Math.abs(state.sidebarWidth-before.width)<1:!state.open)&&(sameDocument||state.ready&&state.finished&&state.entry==='none'||!state.transition&&state.entry==='cv-module-enter');
        results.push({role,width,page,sameDocument,pass,...state});
      }
      console.log(role+' '+width+': '+results.filter(r=>r.role===role&&r.width===width).every(r=>r.pass));
    }
    await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
    await evaluate(`document.querySelector('[data-cv-nav="themes"]').click()`);await waitPage('management.html#themes');await sleep(300);
    const reduced=await evaluate(`({transition:motionProbe.transition,duration:getComputedStyle(document.querySelector('.sidebar a')).transitionDuration})`);
    results.push({check:'reduced-motion',...reduced,pass:!reduced.transition&&parseFloat(reduced.duration)<=0.001});
    await evaluate('history.back()');await waitPage('voter/dashboard.html');
    results.push({check:'back',pass:await evaluate(`!!CoopVote.user && !document.getElementById('cv-shroud')`)});
    await evaluate('history.forward()');await waitPage('management.html#themes');
    results.push({check:'forward',pass:await evaluate(`!!CoopVote.user && !document.getElementById('cv-shroud')`)});
    const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tests/reports/navigation-motion-mobile.png',Buffer.from(shot.result.data,'base64'));
    fs.writeFileSync('tests/reports/navigation-motion.json',JSON.stringify(results,null,2));
    const failures=results.filter(r=>!r.pass);console.log(JSON.stringify({checked:results.length,failed:failures.length,failures},null,2));if(failures.length)process.exitCode=1;
  } finally {ws?.close();proc.kill();}
};
new Function('require',prefix+'\n('+main.toString()+')().catch(e=>{console.error(e);process.exitCode=1;});')(require);

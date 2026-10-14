// Real page navigation with deliberately delayed authentication/branding responses.
// Run through a coopvote_test-only PHP server on 8097.
const fs=require('node:fs');
const helper=fs.readFileSync('tests/lib/browser.js','utf8');
const prefix=helper.slice(0,helper.indexOf('async function main()'));
const main=async function(){
  const base='http://127.0.0.1:8097/',port=9358,results=[];
  fs.mkdirSync('tests/reports/sidebar-stability',{recursive:true});
  const proc=spawn(findChrome(),['--headless=new','--disable-gpu','--no-first-run',`--remote-debugging-port=${port}`,`--user-data-dir=${fs.mkdtempSync(path.join(os.tmpdir(),'coopvote-sidebar-start-'))}`,'about:blank'],{stdio:'ignore',windowsHide:true});
  let ws;
  try{
    if(!await waitForDevTools(port,15000))throw Error('Browser did not start');
    const tab=await(await fetch(`http://127.0.0.1:${port}/json/new?about:blank`,{method:'PUT'})).json();
    ws=new WebSocket(tab.webSocketDebuggerUrl);await new Promise(r=>ws.addEventListener('open',r));
    let id=0;const pending=new Map();
    ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(pending.has(m.id)){pending.get(m.id)(m);pending.delete(m.id);}});
    const send=(method,params={})=>new Promise(r=>{const n=++id;pending.set(n,r);ws.send(JSON.stringify({id:n,method,params}));});
    const ev=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true});if(r.error||r.result?.exceptionDetails)throw Error(JSON.stringify(r.error||r.result.exceptionDetails));return r.result?.result?.value;};
    await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
    await send('Page.addScriptToEvaluateOnNewDocument',{source:`
      window.sidebarDocument=Math.random();window.sidebarErrors=[];addEventListener('error',e=>sidebarErrors.push(e.message));
      window.sidebarSample=()=>{const s=document.querySelector('.sidebar');if(!s)return null;return {name:document.getElementById('sidebar-name')?.textContent,brand:document.getElementById('cv-coop-name')?.textContent,top:s.scrollTop,max:s.scrollHeight-s.clientHeight,links:[...s.querySelectorAll('a[href]')].map(a=>({href:a.href,y:Math.round(a.getBoundingClientRect().y),height:a.getBoundingClientRect().height})),verified:!!window.CoopVote?.user};};
      const nativeFetch=window.fetch;window.fetch=async function(...args){const url=String(args[0]);if(url.includes('r=auth/me')){window.sidebarBeforeAuth=sidebarSample();await new Promise(r=>setTimeout(r,1400));}if(url.includes('r=branding'))await new Promise(r=>setTimeout(r,1600));return nativeFetch.apply(this,args);};`});
    const wait=async(page)=>{for(let i=0;i<70;i++){await sleep(100);try{if(await ev(`location.href===${JSON.stringify(base+page)}&&!!window.CoopVote?.user&&!!document.getElementById('cv-coop-name')&&(!location.pathname.endsWith('/management.html')||document.getElementById(${JSON.stringify(page.split('#')[1]||'themes')})?.hidden===false)`)){await sleep(450);return}}catch{}}throw Error('Timeout '+page);};
    for(const role of ['admin','voter'])for(const width of [1440,390]){
      await send('Emulation.setDeviceMetricsOverride',{width,height:580,deviceScaleFactor:1,mobile:false});
      await send('Network.clearBrowserCookies');await send('Page.navigate',{url:base+'api/index.php?r=branding'});await sleep(300);
      const login=await ev(`fetch('/api/index.php?r=auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:${JSON.stringify(role==='admin'?'t_admin':'t_voter1')},password:'Test@1234'})}).then(r=>r.status)`);if(login!==200)throw Error('Scratch login failed '+login);
      await send('Page.navigate',{url:base+role+'/dashboard.html'});await wait(role+'/dashboard.html');await sleep(1300);
      const pages=fs.readdirSync(role).filter(f=>f.endsWith('.html')&&f!=='dashboard.html').map(f=>role+'/'+f);
      pages.push('management.html#themes');if(role==='admin')pages.push('management.html#branding','management.html#themes','management.html#officers','management.html#approvals');pages.push(role+'/dashboard.html');
      for(const page of pages){
        await ev(`document.querySelector('.sidebar').scrollTop=80`);await sleep(80);
        const before=await ev(`({id:sidebarDocument,...sidebarSample()})`);
        await ev(`(()=>{if(innerWidth<=900&&!document.querySelector('.sidebar').classList.contains('open'))document.querySelector('.hamburger').click();const a=[...document.querySelectorAll('.sidebar a[href]')].find(a=>a.href===${JSON.stringify(base+page)});if(!a)throw Error('Missing link');a.click();})()`);
        await wait(page);
        const after=await ev(`({id:sidebarDocument,early:sidebarBeforeAuth,final:sidebarSample(),errors:sidebarErrors,open:document.querySelector('.sidebar').classList.contains('open')})`);
        const sameDocument=after.id===before.id,early=after.early,final=after.final;
        const pass=!after.errors.length&&(width>900||!after.open)&&Math.abs(final.top-Math.min(80,final.max))<=1&&(sameDocument||(!early.verified&&early.name===final.name&&early.brand===final.brand&&early.links.length===final.links.length&&Math.abs(early.top-final.top)<=1&&early.links.every((l,i)=>l.href===final.links[i].href&&Math.abs(l.y-final.links[i].y)<=1)));
        results.push({role,width,page,pass,sameDocument,early,final,errors:after.errors});
      }
      console.log(role+' '+width+' delayed startup: '+results.filter(r=>r.role===role&&r.width===width).every(r=>r.pass));
    }
    await ev('CoopVote.logout()');await sleep(700);
    const cleared=await ev(`!sessionStorage.getItem('cv.sidebar.identity./')`);results.push({check:'logout clears presentation identity',pass:cleared});
    fs.writeFileSync('tests/reports/sidebar-stability/startup.json',JSON.stringify(results,null,2));
    const failures=results.filter(r=>!r.pass);console.log(JSON.stringify({checked:results.length,failed:failures.length,failures:failures.map(r=>({role:r.role,width:r.width,page:r.page,earlyTop:r.early?.top,finalTop:r.final?.top,errors:r.errors}))},null,2));if(failures.length)process.exitCode=1;
  }finally{ws?.close();proc.kill();}
};
new Function('require',prefix+'\n('+main.toString()+')().catch(e=>{console.error(e);process.exitCode=1;});')(require);

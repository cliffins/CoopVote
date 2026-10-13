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
    for(const role of ['admin','voter']) for(const width of [1440,390]) for(const kind of ['dashboard','settings','custom']) {
      const settings=kind==='settings';
      if(baseline && (role!=='admin'||width!==1440||kind!=='dashboard'))continue;
      const page=settings?'management.html#themes':kind==='custom'?(role==='admin'?'admin/election-management.html':'voter/profile.html'):role+'/dashboard.html';
      await send('Emulation.setDeviceMetricsOverride',{width,height:600,deviceScaleFactor:1,mobile:false});
      await send('Network.clearBrowserCookies');
      await send('Page.navigate',{url:base+'api/index.php?r=branding'});await sleep(350);
      const status=await evaluate(`fetch('/api/index.php?r=auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:${JSON.stringify(role==='admin'?'t_admin':'t_voter10')},password:'Test@1234'})}).then(r=>r.status)`);
      if(status!==200)throw Error('Scratch login failed '+status);
      await send('Page.navigate',{url:base+page});
      await wait('!!window.CoopVote?.user && !!document.querySelector("[data-cv-nav=themes]")');await sleep(400);
      if (!baseline) {
        const motion=await evaluate(`(async()=>{
          CoopVote.toast('Toast motion check','success');
          const t=[...document.querySelectorAll('.toast,#cv-toast-wrap>div')].find(e=>e.textContent.includes('Toast motion check'));
          if(!t)return {missing:true};
          const entrance=t.getAnimations().some(a=>a.effect.getTiming().duration===220);
          let removed=false;CoopVote.presentToast(t,40,()=>{removed=true;t.remove();});
          await new Promise(r=>setTimeout(r,75));
          const exit=t.getAnimations().some(a=>a.effect.getTiming().duration===160), visibleDuringExit=t.isConnected;
          await new Promise(r=>setTimeout(r,190));
          const reused=document.createElement('div');document.body.appendChild(reused);let oldDismissed=false,newDismissed=false;
          CoopVote.presentToast(reused,20,()=>{oldDismissed=true;});
          CoopVote.presentToast(reused,0,()=>{newDismissed=true;});
          await new Promise(r=>setTimeout(r,220));const reuseSafe=!oldDismissed&&!newDismissed&&reused.isConnected;reused.remove();
          return {entrance,exit,visibleDuringExit,removed,reuseSafe};
        })()`);
        results.push({role,width,page,check:'toast entrance exit and replacement',...motion,pass:motion.entrance&&motion.exit&&motion.visibleDuringExit&&motion.removed&&motion.reuseSafe});
        await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
        const reduced=await evaluate(`(async()=>{const t=document.createElement('div');document.body.appendChild(t);let removed=false;CoopVote.presentToast(t,30,()=>{removed=true;t.remove();});const animated=t.getAnimations().length;await new Promise(r=>setTimeout(r,70));return {animated,removed};})()`);
        results.push({role,width,page,check:'reduced motion toast',...reduced,pass:reduced.animated===0&&reduced.removed});
        await send('Emulation.setEmulatedMedia',{features:[]});
      }
      // Keep a scrolled sidebar (and an open drawer on phones) throughout the delay.
      await evaluate(`(()=>{const s=document.querySelector('.sidebar');if(innerWidth<=900)document.querySelector('.hamburger').click();s.scrollTop=130;})()`);await sleep(400);
      await evaluate(`(()=>{
        sessionStorage.removeItem('logout-test');
        const s=document.querySelector('.sidebar'),r=s.getBoundingClientRect(),before={x:r.x,width:r.width,top:s.scrollTop};
        const sample={before,drift:false,calls:0,raced:false,shroud:false};
        const save=()=>sessionStorage.setItem('logout-test',JSON.stringify(sample));
        const frame=()=>{const a=s.getBoundingClientRect();sample.drift ||= Math.abs(a.x-before.x)>1||Math.abs(a.width-before.width)>1||Math.abs(s.scrollTop-before.top)>1;sample.shroud ||= !!document.getElementById('cv-shroud');save();requestAnimationFrame(frame);};requestAnimationFrame(frame);
        const original=window.fetch;window.fetch=async function(...args){
          const isLogout=String(args[0]).includes('r=auth/logout');if(isLogout)sample.calls++;
          const response=await original.apply(this,args);
          if(isLogout) {
            // Force a late background 401 before releasing the logout response.
            sample.raced=true;save();await CoopVote.api('notifications').catch(()=>{});
            document.dispatchEvent(new Event('visibilitychange'));
            sample.responseAt=Date.now();save();
          }
          return response;
        };
        addEventListener('pagehide',()=>{sample.redirectDelay=Date.now()-sample.responseAt;save();},{once:true});
        const button=document.querySelector('#logout,[onclick="CoopVote.logout()"]');button.click();button.click();save();
      })()`);
      await wait('location.pathname.endsWith("/login.html") && !!document.getElementById("login-btn")');await sleep(300);
      const state=await evaluate(`({probe:logoutProbe,sample:JSON.parse(sessionStorage.getItem('logout-test')),search:location.search,sidebar:!!document.querySelector('.sidebar'),notice:document.getElementById('notice-msg').textContent})`);
      if(!baseline&&role==='admin'&&kind==='dashboard') {
        const shot=await send('Page.captureScreenshot',{format:'png'});
        fs.writeFileSync('tests/reports/smooth-logout-'+width+'.png',Buffer.from(shot.result.data,'base64'));
      }
      if (!baseline) {
        const arrival=await evaluate(`({name:getComputedStyle(document.querySelector('.login-card')).animationName,duration:getComputedStyle(document.querySelector('.login-card')).animationDuration,easing:getComputedStyle(document.querySelector('.login-card')).animationTimingFunction,field:getComputedStyle(document.querySelector('.field')).animationName,headline:document.getElementById('tw-headline').textContent})`);
        results.push({role,width,page,check:'logout matches dashboard entrance',...arrival,pass:arrival.name==='cv-module-enter'&&arrival.duration==='0.18s'&&arrival.easing==='ease-out'&&arrival.field==='none'&&arrival.headline==='Welcome Back'});
        await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
        results.push({role,width,page,check:'reduced motion logout entrance',pass:await evaluate(`getComputedStyle(document.querySelector('.login-card')).animationName==='none'`)});
        await send('Emulation.setEmulatedMedia',{features:[]});
      }
      // Login consumes bye=1; its notice distinguishes a deliberate logout from a raced session-expired redirect.
      results.push({role,width,page,check:'logout transition',...state,pass:!state.probe.native&&!state.probe.errors.length&&!state.sidebar&&!state.sample.drift&&!state.sample.shroud&&state.sample.calls===1&&state.sample.raced&&/signed out/i.test(state.notice)});
      const auth=await evaluate(`fetch('/api/index.php?r=auth/me').then(r=>r.status)`);
      results.push({role,width,page,check:'session ended',pass:auth===401,status:auth});
      console.log(role+' '+width+' '+page+': '+results.slice(-2).every(r=>r.pass));
    }
  } finally {
    fs.writeFileSync('tests/reports/logout-navigation'+(baseline?'-baseline':'')+'.json',JSON.stringify(results,null,2));
    const failures=results.filter(r=>!r.pass);console.log(JSON.stringify({checked:results.length,failed:failures.length,failures},null,2));
    if(failures.length)process.exitCode=1;
    ws?.close();proc.kill();
  }
};
new Function('require',prefix+'\n('+main.toString()+')().catch(e=>{console.error(e);process.exitCode=1;});')(require);

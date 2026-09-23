// Exercise real voter pages with controlled election-list responses, without changing elections.
const fs=require('node:fs');
const helper=fs.readFileSync('tests/lib/browser.js','utf8');
const prefix=helper.slice(0,helper.indexOf('async function main()'));
const main=async function(){
  const base='http://127.0.0.1:8097/',port=9351,results=[];
  const proc=spawn(findChrome(),['--headless=new','--disable-gpu','--no-first-run',`--remote-debugging-port=${port}`,`--user-data-dir=${fs.mkdtempSync(path.join(os.tmpdir(),'coopvote-badges-'))}`,'about:blank'],{stdio:'ignore'});
  let ws;
  try {
    if(!await waitForDevTools(port,15000))throw Error('Browser did not start');
    const tab=await(await fetch(`http://127.0.0.1:${port}/json/new?about:blank`,{method:'PUT'})).json();
    ws=new WebSocket(tab.webSocketDebuggerUrl);await new Promise(r=>ws.addEventListener('open',r));
    let id=0;const pending=new Map();
    ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(pending.has(m.id)){pending.get(m.id)(m);pending.delete(m.id);}});
    const send=(method,params={})=>new Promise(r=>{const n=++id;pending.set(n,r);ws.send(JSON.stringify({id:n,method,params}));});
    const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true});if(r.error||r.result?.exceptionDetails)throw Error(JSON.stringify(r.error||r.result.exceptionDetails));return r.result?.result?.value;};
    await send('Page.enable');await send('Runtime.enable');
    await send('Page.addScriptToEvaluateOnNewDocument',{source:`window.badgeErrors=[];addEventListener('error',e=>badgeErrors.push(e.message));const realFetch=window.fetch;window.fetch=function(input,options){const u=new URL(typeof input==='string'?input:input.url,location.href);const fixture=sessionStorage.getItem('test.badge-elections');if(fixture&&u.searchParams.get('r')==='voter/elections')return Promise.resolve(new Response(fixture,{headers:{'Content-Type':'application/json'}}));return realFetch.call(this,input,options);};`});
    await send('Page.navigate',{url:base+'api/index.php?r=branding'});await sleep(400);
    const status=await evaluate(`fetch('/api/index.php?r=auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:'t_voter1',password:'Test@1234'})}).then(r=>r.status)`);
    if(status!==200)throw Error('Scratch sign-in failed '+status);
    const open={id:1,title:'Open test election',status:'Active',live:true,hasVoted:false,endDateISO:new Date(Date.now()+3600000).toISOString()};
    const cases=[{name:'open',elections:[open],live:1,vote:1},{name:'voted',elections:[{...open,hasVoted:true}],live:1,vote:0},{name:'upcoming',elections:[{...open,status:'Upcoming',live:false}],live:0,vote:0},{name:'closed',elections:[],live:0,vote:0},{name:'multiple',elections:[{...open,hasVoted:true},{...open,id:2}],live:1,vote:1}];
    const pages=fs.readdirSync('voter').filter(f=>f.endsWith('.html')).map(f=>'voter/'+f).concat('management.html#themes');
    const fixture=async elections=>evaluate(`sessionStorage.setItem('test.badge-elections',${JSON.stringify(JSON.stringify({elections}))})`);
    const counts=()=>evaluate(`(()=>{const s=document.querySelector('.sidebar');return {live:s.querySelectorAll('a[href$="election.html"] .nav-badge.green').length,vote:s.querySelectorAll('a[href$="ballot.html"] .nav-badge.gold').length,errors:badgeErrors,overflow:s.scrollWidth>s.clientWidth+1};})()`);
    for(const width of [1440,390]){
      await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
      for(const c of (width===1440?cases:[cases[0]]))for(const page of pages){
        await fixture(c.elections);await send('Page.navigate',{url:base+page});
        let ready=false;
        for(let i=0;i<80;i++){await sleep(80);try{ready=await evaluate(`!!(window.CoopVote?.user && window.CoopVoteVoterNavigation && document.querySelector('[data-cv-nav="themes"]'))`);if(ready)break;}catch{}}
        if(!ready)throw Error('Page not ready: '+page);
        await sleep(350);await evaluate('CoopVoteVoterNavigation.refresh()');
        const state=await counts();results.push({page,width,scenario:c.name,...state,pass:state.live===c.live&&state.vote===c.vote&&!state.errors.length&&!state.overflow});
      }
      console.log(width+'px page/state checks finished');
    }
    await fixture([{...open,hasVoted:true},{...open,id:2}]);await evaluate('CoopVoteVoterNavigation.refresh()');
    const selected=await evaluate(`(()=>{CoopVote.setSelectedElection(1);const link=document.querySelector('.sidebar a[href$="ballot.html"]');const stop=e=>e.preventDefault();document.addEventListener('click',stop);link.click();document.removeEventListener('click',stop);return CoopVote.selectedElection();})()`);
    results.push({scenario:'vote-link-selects-unvoted-election',pass:selected==='2'});
    await evaluate('CoopVoteVoterNavigation.voted(2)');await sleep(100);
    let state=await counts();results.push({scenario:'recorded-vote-survives-stale-response',...state,pass:state.live===1&&state.vote===0});
    await fixture([]);await evaluate('CoopVoteVoterNavigation.refresh()');state=await counts();results.push({scenario:'close-while-page-open',...state,pass:state.live===0&&state.vote===0});
    await fixture([{...open,id:3,endDateISO:new Date(Date.now()+250).toISOString()}]);await evaluate('CoopVoteVoterNavigation.refresh()');await sleep(400);state=await counts();results.push({scenario:'scheduled-close-without-poll',...state,pass:state.live===0&&state.vote===0});
    await fixture([open]);await evaluate('CoopVoteVoterNavigation.refresh()');await evaluate('document.querySelector(".hamburger").click()');await sleep(350);
    const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tests/reports/voter-navigation-mobile.png',Buffer.from(shot.result.data,'base64'));
    fs.writeFileSync('tests/reports/voter-navigation.json',JSON.stringify(results,null,2));const failures=results.filter(r=>!r.pass);console.log(JSON.stringify({checked:results.length,failed:failures.length,failures},null,2));if(failures.length)process.exitCode=1;
  }finally{ws?.close();proc.kill();}
};
new Function('require',prefix+'\n('+main.toString()+')().catch(e=>{console.error(e);process.exitCode=1;});')(require);

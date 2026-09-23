const fs=require('node:fs');
const helper=fs.readFileSync('tests/lib/browser.js','utf8');
const prefix=helper.slice(0,helper.indexOf('async function main()'));
const main=async function(){
  const base='http://127.0.0.1:8097/',port=9349;
  const proc=spawn(findChrome(),['--headless=new','--disable-gpu','--no-first-run',`--remote-debugging-port=${port}`,`--user-data-dir=${fs.mkdtempSync(path.join(os.tmpdir(),'coopvote-tables-'))}`,'about:blank'],{stdio:'ignore'});
  let ws;const results=[];
  try{
    if(!await waitForDevTools(port,15000))throw Error('Browser did not start');
    const tab=await(await fetch(`http://127.0.0.1:${port}/json/new?about:blank`,{method:'PUT'})).json();
    ws=new WebSocket(tab.webSocketDebuggerUrl);await new Promise(r=>ws.addEventListener('open',r));
    let id=0;const pending=new Map();const errors=[];
    ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(pending.has(m.id)){pending.get(m.id)(m);pending.delete(m.id);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text);});
    const send=(method,params={})=>new Promise(r=>{const n=++id;pending.set(n,r);ws.send(JSON.stringify({id:n,method,params}));});
    const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.result?.exceptionDetails)throw Error(r.result.exceptionDetails.exception?.description||r.result.exceptionDetails.text);return r.result?.result?.value;};
    await send('Page.enable');await send('Runtime.enable');await send('Network.enable');await send('Network.setCacheDisabled',{cacheDisabled:true});
    await send('Page.navigate',{url:base+'api/index.php?r=branding'});await sleep(500);
    const login=await evaluate("fetch('/api/index.php?r=auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:'t_admin',password:'Test@1234'})}).then(r=>r.status)");
    if(login!==200)throw Error('Scratch sign-in failed');
    const cases=[
      {page:'admin/voter-management.html',ready:'#voter-tbody tr',panel:'.table-card',search:'#search-input',filters:['#status-filter','#vote-filter']},
      {page:'admin/candidate-management.html',ready:'#grid-view>*',panel:'#list-view',search:'#search-input',setup:"setView('list')",filters:['#pos-filter','#status-filter','#election-filter']},
      {page:'management.html#officers',ready:'#accounts tr',panel:'.officer-directory',search:'#officer-search',filters:['#officer-status']},
      {page:'admin/reports.html',ready:'.cv-report-results',panel:'.cv-report-results',report:true,filters:['#report-position-filter']},
      {page:'admin/security-logs.html',ready:'.log-card',panel:'.log-card',search:'#log-search-input'},
      {page:'admin/dashboard.html',ready:'#voter-table',panel:'.card:has(#voter-table)',rows:'#voter-table'},
      {page:'admin/vote-monitoring.html',ready:'#votes-tbody',panel:'.recent-votes-card',rows:'#votes-tbody'}
    ];
    for(const spec of cases){
      for(const width of [1440,850,390]){
        errors.length=0;
        await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
        await send('Page.navigate',{url:base+spec.page});
        let ready=false;
        for(let i=0;i<80;i++){await sleep(150);ready=await evaluate(`!!document.querySelector(${JSON.stringify(spec.ready)})&&!!window.CoopVote?.user`);if(ready)break;}
        if(!ready)throw Error('Page not ready: '+spec.page);
        if(spec.setup)await evaluate(spec.setup);
        await sleep(900);
        const check=await evaluate(`(async()=>{
          const spec=${JSON.stringify(spec)};
          const panel=()=>document.querySelector(spec.panel), content=document.querySelector('.content,.module-content');
          if(spec.report)content.scrollTop=panel().offsetTop-150;
          const tableViewport=()=>panel().querySelector('.table-scroll,.table-wrap');
          if(tableViewport())tableViewport().scrollLeft=120;
          const measure=()=>{const p=panel(),r=p.getBoundingClientRect(),footer=p.querySelector('.pagination')?.getBoundingClientRect();return {box:[r.x,r.y,r.width,r.height],heads:[...p.querySelectorAll('thead th')].map(th=>{const r=th.getBoundingClientRect();return [r.x,r.width];}),footer:footer?[footer.x,footer.y,footer.width,footer.height]:[],scrollTop:content.scrollTop,scrollLeft:tableViewport()?.scrollLeft||0};};
          const before=measure();let restore;
          if(spec.search){const input=document.querySelector(spec.search);restore=input.value;input.value='no-matching-record-zzzz';input.dispatchEvent(new Event('input',{bubbles:true}));}
          else if(spec.report)setPositionFilter('no-matching-position-zzzz');
          else{const rows=document.querySelector(spec.rows);restore=rows.innerHTML;rows.innerHTML='<tr><td colspan="'+before.heads.length+'">No records</td></tr>';}
          await new Promise(r=>setTimeout(r,450));const empty=measure();
          if(spec.search){const input=document.querySelector(spec.search);input.value=restore;input.dispatchEvent(new Event('input',{bubbles:true}));}
          else if(spec.report)setPositionFilter('');
          else document.querySelector(spec.rows).innerHTML=restore;
          await new Promise(r=>setTimeout(r,450));const restored=measure();
          const delta=(a,b)=>a.length!==b.length?999:Math.max(0,...a.map((v,i)=>Math.abs(v-b[i])));
          const compare=b=>({box:delta(before.box,b.box),columns:delta(before.heads.flat(),b.heads.flat()),footer:delta(before.footer,b.footer),scroll:Math.abs(before.scrollTop-b.scrollTop),horizontalScroll:Math.abs(before.scrollLeft-b.scrollLeft)});
          const changes=[compare(empty),compare(restored)];
          const focusChecks=[],dropdownChecks=[];
          for(const selector of spec.filters||[]){
            let control=document.querySelector(selector);const old=control.value, option=[...control.options].find(o=>o.value!==old&&!o.disabled);
            if(!option)continue;
            control.focus({preventScroll:true});control.value=option.value;control.dispatchEvent(new Event('change',{bubbles:true}));
            await new Promise(r=>setTimeout(r,450));changes.push(compare(measure()));focusChecks.push(document.activeElement.id===control.id);dropdownChecks.push(selector);
            control=document.querySelector(selector);control.value=old;control.dispatchEvent(new Event('change',{bubbles:true}));
            await new Promise(r=>setTimeout(r,450));changes.push(compare(measure()));
          }
          if(spec.report){
            document.getElementById('report-winners').focus({preventScroll:true});document.getElementById('report-winners').click();
            await new Promise(r=>setTimeout(r,450));changes.push(compare(measure()));focusChecks.push(document.activeElement.id==='report-winners');document.getElementById('report-all').click();
          }
          const pageTwo=[...panel().querySelectorAll('.pag-btns button')].find(b=>b.textContent.trim()==='2'&&!b.disabled);
          if(pageTwo){pageTwo.click();await new Promise(r=>setTimeout(r,450));changes.push(compare(measure()));panel().querySelector('.pag-btns button[aria-label="Page 1"]')?.click();}
          return {before,empty,restored,changes,dropdownChecks,focusChecks,paginationChecked:!!pageTwo,pass:changes.every(c=>Object.values(c).every(n=>n<2))&&focusChecks.every(Boolean),bodyOverflow:document.documentElement.scrollWidth>innerWidth};
        })()`);
        const shot=await send('Page.captureScreenshot',{format:'png'});
        fs.writeFileSync('tests/reports/table-'+spec.page.replace(/[^a-z0-9]/gi,'-')+'-'+width+'.png',Buffer.from(shot.result.data,'base64'));
        results.push({page:spec.page,width,...check,errors:[...errors]});
        console.log(spec.page+' '+width+': '+(check.pass&&!errors.length?'PASS':'FAIL')+' '+JSON.stringify(check.changes));
      }
    }
    fs.writeFileSync('tests/reports/table-layout.json',JSON.stringify(results,null,2));
    const failed=results.filter(r=>!r.pass||r.errors.length||r.bodyOverflow);console.log(JSON.stringify({checked:results.length,failed:failed.length}));if(failed.length)process.exitCode=1;
  }finally{ws?.close();proc.kill();}
};
new Function('require',prefix+'\n('+main.toString()+')().catch(e=>{console.error(e);process.exitCode=1;});')(require);

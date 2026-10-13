// Run through election-qr.php: isolated database, real browser, independent pixel decoder.
const fs = require('node:fs');
const helper = fs.readFileSync('tests/lib/browser.js', 'utf8');
const prefix = helper.slice(0, helper.indexOf('async function main()'));
const main = async function () {
  const base = 'http://127.0.0.1:8097/', port = 9355, results = [];
  const decoderFile = 'tests/lib/jsQR-1.4.0.js';
  if (!fs.existsSync(decoderFile)) throw Error('Download jsqr@1.4.0/dist/jsQR.js into ' + decoderFile + ' before running.');
  const decoder = fs.readFileSync(decoderFile, 'utf8');
  const proc = spawn(findChrome(), ['--headless=new', '--disable-gpu', '--no-first-run', `--remote-debugging-port=${port}`,
    `--user-data-dir=${fs.mkdtempSync(path.join(os.tmpdir(), 'coopvote-qr-'))}`, 'about:blank'], { stdio: 'ignore' });
  let ws;
  try {
    if (!await waitForDevTools(port, 15000)) throw Error('Browser did not start');
    const tab = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
    ws = new WebSocket(tab.webSocketDebuggerUrl); await new Promise(r => ws.addEventListener('open', r));
    let id = 0; const pending = new Map();
    ws.addEventListener('message', e => { const m = JSON.parse(e.data); if (pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); } });
    const send = (method, params = {}) => new Promise(r => { const n = ++id; pending.set(n, r); ws.send(JSON.stringify({ id: n, method, params })); });
    const evaluate = async expression => {
      const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true, userGesture: true });
      if (r.error || r.result?.exceptionDetails) throw Error(JSON.stringify(r.error || r.result.exceptionDetails));
      return r.result?.result?.value;
    };
    const wait = async expression => {
      for (let i = 0; i < 120; i++) {
        await sleep(100);
        try { if (await evaluate(expression)) return; } catch {}
      }
      throw Error('Timed out: ' + expression + ' at ' + await evaluate('location.href'));
    };
    const check = (name, pass, detail) => { results.push({name, pass: !!pass, detail}); if (!pass) throw Error(name + ': ' + JSON.stringify(detail)); };
    const navigate = async (page, ready) => { await send('Page.navigate', {url: base + page}); await wait(ready); await sleep(250); };
    const screenshot = async name => { const r = await send('Page.captureScreenshot', {format:'png'}); fs.writeFileSync('tests/reports/election-qr-' + name + '.png', Buffer.from(r.result.data, 'base64')); };
    await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
    await send('Page.addScriptToEvaluateOnNewDocument', {source: "window.qrErrors=[];addEventListener('error',e=>qrErrors.push(e.message));"});
    await send('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await navigate('api/index.php?r=branding', 'document.readyState==="complete"');
    check('scratch administrator login', await evaluate(`fetch('/api/index.php?r=auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:'t_admin',password:'Test@1234'})}).then(r=>r.status)`) === 200);
    await navigate('admin/election-management.html', '!!document.querySelector("#election-qr canvas")?.dataset.url');
    await evaluate(decoder);
    for (const eid of [1,4]) {
      await evaluate(`selectActiveElection('${eid}')`);
      const scan = await evaluate(`(()=>{const c=document.querySelector('#election-qr canvas'), p=c.getContext('2d').getImageData(0,0,c.width,c.height);return jsQR(p.data,p.width,p.height)?.data;})()`);
      check('QR pixels decode election ' + eid, scan === base + 'voter/election.html?election=' + eid, scan);
      const download = await evaluate(`(()=>{const a=document.querySelector('[data-qr-download]');return {href:a.href,name:a.download};})()`);
      check('downloadable QR PNG ' + eid, download.href.startsWith('data:image/png;base64,') && download.name === `coopvote-election-${eid}-qr.png`);
    }
    const smallScan = await evaluate(`(()=>{const c=document.createElement('canvas');c.width=c.height=180;const ctx=c.getContext('2d');ctx.drawImage(document.querySelector('#election-qr canvas'),0,0,180,180);const p=ctx.getImageData(0,0,180,180);return jsQR(p.data,180,180)?.data;})()`);
    check('QR decodes at displayed size', smallScan === base + 'voter/election.html?election=4', smallScan);
    const subfolderScan = await evaluate(`(()=>{const before=location.href;history.replaceState(null,'','/CoopVote/admin/election-management.html');CoopVoteElectionQR.render(4);const c=document.querySelector('#election-qr canvas'),p=c.getContext('2d').getImageData(0,0,c.width,c.height);const result=jsQR(p.data,p.width,p.height)?.data;history.replaceState(null,'',before);CoopVoteElectionQR.render(4);return result;})()`);
    check('subfolder installation link', subfolderScan === base + 'CoopVote/voter/election.html?election=4', subfolderScan);
    check('localhost phone guidance', await evaluate(`!document.querySelector('[data-qr-local]').hidden`));
    for (const width of [1440,390,320]) {
      await send('Emulation.setDeviceMetricsOverride', {width,height:1000,deviceScaleFactor:1,mobile:false}); await sleep(400);
      const layout = await evaluate(`(()=>{const c=document.querySelector('#election-qr canvas').getBoundingClientRect(),h=document.getElementById('hero-card').getBoundingClientRect();return {overflow:document.documentElement.scrollWidth>innerWidth,qrInside:c.left>=h.left&&c.right<=h.right,errors:qrErrors};})()`);
      check('layout ' + width, !layout.overflow && layout.qrInside && !layout.errors.length, layout);
      await screenshot(String(width));
    }
    await evaluate('allElections.forEach(e=>e.status="Closed");renderHero()');
    check('no QR without live election', await evaluate('!document.getElementById("election-qr")'));
    await send('Network.clearBrowserCookies');
    await navigate('voter/election.html?election=4', 'location.pathname.endsWith("/login.html") && !!document.getElementById("login-btn")');
    check('signed-out scan keeps election at login', await evaluate('location.search') === '?election=4');
    await sleep(500);
    await evaluate(`document.getElementById('username').value='t_voter10';document.getElementById('password').value='Wrong@1234';document.getElementById('login-btn').click()`);
    await wait('document.getElementById("alert-msg").textContent.length>0');
    check('failed login retains election', await evaluate('location.search') === '?election=4');
    await evaluate(`localStorage.setItem('coopvote.election','1');document.getElementById('password').value='Test@1234';document.getElementById('login-btn').click()`);
    await wait('location.pathname.endsWith("/voter/election.html") && !!window.CoopVote?.user && !!document.getElementById("cv-picker-current")');
    await sleep(500);
    check('successful login opens scanned election', await evaluate('location.search === "?election=4" && CoopVote.selectedElection() === "4"'));
    check('QR overrides stale stored selection', await evaluate('localStorage.getItem("coopvote.election")') === '4');
    check('ballot link retains election', await evaluate('[...document.querySelectorAll(".btn-vote-hero,.vs-btn")].some(a=>a.href.endsWith("ballot.html?election=4"))'));
    await evaluate(`document.querySelector('#cv-picker-menu [data-id="1"]').click()`);
    await wait(`location.search==='?election=1' && document.querySelector('#cv-picker-menu [data-id="1"]')?.getAttribute('aria-selected')==='true'`);
    check('picker can change scanned selection', await evaluate('CoopVote.selectedElection() === "1"'));
    await navigate('voter/election.html?election=4', 'window.CoopVote?.selectedElection()==="4" && !!document.querySelector(".btn-vote-hero,.vs-btn")');
    check('signed-in scan opens requested election', await evaluate('CoopVote.selectedElection()') === '4');
    for (const eid of [2,999999]) {
      await navigate('voter/election.html?election=' + eid, '!!window.CoopVote?.electionLinkUnavailable && document.body.innerText.includes("Election Unavailable")');
      check('unavailable election does not show another ballot ' + eid, await evaluate('!document.querySelector(".btn-vote-hero,.vs-btn")'));
      check('unavailable link still permits election choice ' + eid, await evaluate('!document.getElementById("cv-picker-btn").disabled && document.getElementById("cv-picker-current").textContent==="Choose another election"'));
    }
    await navigate('login.html?election=4', 'location.pathname.endsWith("/voter/election.html") && !!window.CoopVote?.user');
    check('existing voter login session keeps scan', await evaluate('location.search') === '?election=4');
    await send('Network.clearBrowserCookies');
    await navigate('login.html?election=4', '!!document.getElementById("login-btn")'); await sleep(500);
    await evaluate(`document.getElementById('username').value='t_admin';document.getElementById('password').value='Test@1234';document.getElementById('login-btn').click()`);
    await wait('location.pathname.endsWith("/admin/dashboard.html") && !!window.CoopVote?.user');
    check('administrator keeps administrator destination', true);
    const invalid = await evaluate(`(()=>{const before=location.href;history.replaceState(null,'','?election=https://example.com');const result=CoopVote.requestedElection();history.replaceState(null,'',before);return result;})()`);
    check('only numeric election IDs accepted', invalid === '');
  } finally {
    fs.writeFileSync('tests/reports/election-qr.json',JSON.stringify(results,null,2));
    console.log(JSON.stringify({checked:results.length,failed:results.filter(r=>!r.pass).length,results},null,2));
    ws?.close(); proc.kill();
  }
};
new Function('require', prefix + '\n(' + main.toString() + ')().catch(e=>{console.error(e);process.exitCode=1;});')(require);

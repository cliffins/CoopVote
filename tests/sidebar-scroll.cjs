// Exercise real document navigation and mouse-wheel scrolling without touching live data.
const fs = require('node:fs');
const helper = fs.readFileSync('tests/lib/browser.js', 'utf8');
const prefix = helper.slice(0, helper.indexOf('async function main()'));
const main = async function () {
  const base = 'http://127.0.0.1:8097/', port = 9354, results = [];
  const proc = spawn(findChrome(), ['--headless=new', '--disable-gpu', '--no-first-run', `--remote-debugging-port=${port}`,
    `--user-data-dir=${fs.mkdtempSync(path.join(os.tmpdir(), 'coopvote-scroll-'))}`, 'about:blank'], { stdio: 'ignore' });
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
    await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
    // Reproduce branding arriving after the sidebar restores its saved offset.
    await send('Page.addScriptToEvaluateOnNewDocument', { source: `window.scrollErrors=[];addEventListener('error',e=>scrollErrors.push(e.message));const originalFetch=window.fetch;window.fetch=async function(...args){if(String(args[0]).includes('r=branding'))await new Promise(r=>setTimeout(r,250));return originalFetch.apply(this,args);};` });
    const waitPage = async page => {
      for (let i = 0; i < 80; i++) {
        await sleep(100);
        try { if (await evaluate(`location.href===${JSON.stringify(base + page)} && !!window.CoopVote?.user && !!document.querySelector('[data-cv-nav="themes"]') && (!location.pathname.endsWith('/management.html') || !document.getElementById(${JSON.stringify(page.split('#')[1] || 'themes')})?.hidden)`)) { await sleep(500); return; } } catch {}
      }
      throw Error('Page did not load: ' + page);
    };
    const state = () => evaluate(`(()=>{const s=document.querySelector('.sidebar');return {top:s.scrollTop,max:s.scrollHeight-s.clientHeight,open:s.classList.contains('open'),overflow:document.documentElement.scrollWidth>innerWidth,errors:scrollErrors};})()`);
    const open = async () => { await evaluate(`(()=>{const s=document.querySelector('.sidebar');if(innerWidth<=900&&!s.classList.contains('open'))document.querySelector('.hamburger').click();})()`); await sleep(350); };
    for (const role of ['admin', 'voter']) for (const width of [1440, 390]) {
      await send('Emulation.setDeviceMetricsOverride', { width, height: 480, deviceScaleFactor: 1, mobile: false });
      await send('Network.clearBrowserCookies');
      await send('Page.navigate', { url: base + 'api/index.php?r=branding' }); await sleep(500);
      const status = await evaluate(`fetch('/api/index.php?r=auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:${JSON.stringify(role === 'admin' ? 't_admin' : 't_voter1')},password:'Test@1234'})}).then(r=>r.status)`);
      if (status !== 200) throw Error('Scratch sign-in failed: ' + status);
      await evaluate('sessionStorage.clear()');
      await send('Page.navigate', { url: base + role + '/dashboard.html' }); await waitPage(role + '/dashboard.html');
      await open();
      await send('Input.dispatchMouseEvent', { type: 'mouseWheel', x: 125, y: 230, deltaX: 0, deltaY: 140 }); await sleep(350);
      const wheel = await state();
      results.push({ role, width, check: 'mouse wheel', ...wheel, pass: wheel.top > 0 });
      const edgesOnly = process.argv.includes('--edges');
      const pages = edgesOnly ? [] : fs.readdirSync(role).filter(f => f.endsWith('.html') && f !== 'dashboard.html').map(f => role + '/' + f);
      pages.push('management.html#themes');
      if (role === 'admin' && !edgesOnly) pages.push('management.html#branding', 'management.html#officers', 'management.html#approvals', 'management.html#themes');
      pages.push(role + '/dashboard.html');
      for (const page of pages) {
        const before = await state(); await open();
        const reopened = await state();
        const clicked = await evaluate(`(()=>{const a=[...document.querySelectorAll('.sidebar a[href]')].find(a=>a.href===${JSON.stringify(base + page)});if(!a)return false;a.click();return true;})()`);
        if (!clicked) throw Error('Missing link: ' + page);
        await waitPage(page); const after = await state();
        results.push({ role, width, page, before: before.top, reopened: reopened.top, ...after,
          pass: Math.abs(reopened.top - before.top) <= 1 && Math.abs(after.top - Math.min(before.top, after.max)) <= 1 && !after.overflow && !after.errors.length && (width > 900 || !after.open) });
      }
      const saved = await state();
      await send('Page.reload'); await waitPage(role + '/dashboard.html'); const reloaded = await state();
      results.push({ role, width, check: 'reload', ...reloaded, pass: Math.abs(reloaded.top - saved.top) <= 1 });
      await evaluate('history.back()'); await waitPage('management.html#themes'); const back = await state();
      results.push({ role, width, check: 'back', ...back, pass: Math.abs(back.top - Math.min(saved.top, back.max)) <= 1 });
      await evaluate('history.forward()'); await waitPage(role + '/dashboard.html'); const forward = await state();
      results.push({ role, width, check: 'forward', ...forward, pass: Math.abs(forward.top - saved.top) <= 1 });
      await open();
      await send('Input.dispatchMouseEvent', { type: 'mouseWheel', x: 125, y: 230, deltaX: 0, deltaY: 3000 }); await sleep(350);
      const bottom = await state();
      const point = await evaluate(`(()=>{const r=document.querySelector('[data-cv-nav="themes"]').getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()`);
      await send('Input.dispatchMouseEvent', { type: 'mousePressed', ...point, button: 'left', clickCount: 1 });
      await send('Input.dispatchMouseEvent', { type: 'mouseReleased', ...point, button: 'left', clickCount: 1 });
      await waitPage('management.html#themes'); const bottomAfter = await state(); await open(); const bottomOpen = await state();
      results.push({ role, width, check: 'bottom mouse click and reopen', before: bottom.top, ...bottomOpen,
        pass: bottom.top > 0 && Math.abs(bottomAfter.top - Math.min(bottom.top, bottomAfter.max)) <= 1 && Math.abs(bottomOpen.top - bottomAfter.top) <= 1 });
      console.log(role + ' ' + width + ': ' + results.filter(r => r.role === role && r.width === width).every(r => r.pass));
    }
    fs.writeFileSync('tests/reports/sidebar-scroll' + (process.argv.includes('--edges') ? '-edges' : '') + '.json', JSON.stringify(results, null, 2));
    const failures = results.filter(r => !r.pass);
    console.log(JSON.stringify({ checked: results.length, failed: failures.length, failures }, null, 2));
    if (failures.length) process.exitCode = 1;
  } finally { ws?.close(); proc.kill(); }
};
new Function('require', prefix + '\n(' + main.toString() + ')().catch(e=>{console.error(e);process.exitCode=1;});')(require);

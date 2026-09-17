/**
 * CoopVote — headless-browser probe for the test suite
 *
 * Usage:  node tests/lib/browser.js <specFile.json>
 *
 * Reads a spec, drives a headless Chrome over the DevTools protocol, and
 * writes one JSON object of measurements to stdout. The PHP suites do the
 * asserting; this file only reports what it sees, so a failing check reads
 * as a statement about the page rather than about the probe.
 *
 * Chrome is required. When it cannot be started the script exits with a
 * message on stdout as {"unavailable": "..."} and the suites skip rather
 * than fail — a missing browser is not a defect in the application.
 */

'use strict';

const fs = require('fs');
const { spawn } = require('child_process');
const os = require('os');
const path = require('path');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const CHROME_CANDIDATES = [
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
];

function findChrome() {
  for (const c of CHROME_CANDIDATES) {
    try { if (fs.existsSync(c)) return c; } catch { /* keep looking */ }
  }
  return null;
}

async function waitForDevTools(port, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const r = await fetch(`http://127.0.0.1:${port}/json/version`);
      if (r.ok) return true;
    } catch { /* not up yet */ }
    await sleep(250);
  }
  return false;
}

/** The measurements taken on each page, run inside the page itself. */
const PAGE_PROBE = `(() => {
  const q = (s) => [...document.querySelectorAll(s)];
  const visible = (el) => {
    const closed = el.closest('details:not([open])');
    if (closed && !closed.querySelector('summary')?.contains(el)) return false;
    const r = el.getBoundingClientRect();
    const st = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && st.visibility !== 'hidden' && st.display !== 'none';
  };

  /* An input is labelled if it has a <label for>, wrapping label,
     aria-label, aria-labelledby, or a placeholder standing in for one. */
  const fields = q('input, select, textarea').filter(
    (el) => visible(el) && !['hidden', 'submit', 'button'].includes(el.type)
  );
  const unlabelled = fields.filter((el) => {
    if (el.id && document.querySelector('label[for="' + CSS.escape(el.id) + '"]')) return false;
    if (el.closest('label')) return false;
    if (el.getAttribute('aria-label')) return false;
    if (el.getAttribute('aria-labelledby')) return false;
    if (el.getAttribute('placeholder')) return false;
    if (el.getAttribute('title')) return false;
    return true;
  }).map((el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.name ? '[' + el.name + ']' : ''));

  const imgsNoAlt = q('img').filter((el) => visible(el) && !el.hasAttribute('alt')).length;

  /* A control with no text and no label is a mystery to a screen reader. */
  const namelessButtons = q('button, a[role="button"]').filter((el) => {
    if (!visible(el)) return false;
    if ((el.innerText || '').trim()) return false;
    if (el.getAttribute('aria-label') || el.getAttribute('title')) return false;
    return true;
  }).length;

  const headings = q('h1,h2,h3,h4,h5,h6').filter(visible).map((h) => h.tagName + ':' + (h.innerText || '').trim().slice(0, 40));

  return {
    title: document.title || '',
    lang: document.documentElement.getAttribute('lang') || '',
    charset: !!document.querySelector('meta[charset]'),
    viewportMeta: (document.querySelector('meta[name="viewport"]') || {}).content || '',
    favicon: !!document.querySelector('link[rel~="icon"]'),
    h1Count: q('h1').filter(visible).length,
    headings,
    text: (document.body.innerText || '').trim(),
    words: (document.body.innerText || '').trim().split(/\\s+/).filter(Boolean).length,
    fieldCount: fields.length,
    unlabelled,
    imgsNoAlt,
    namelessButtons,
    focusables: q('a[href], button, input, select, textarea, [tabindex]').filter(visible).length,
    docWidth: document.documentElement.scrollWidth,
    winWidth: window.innerWidth,
    hasServerBanner: !!document.getElementById('cv-server-banner'),
    escAvailable: typeof window.esc === 'function',
    picker: document.getElementById('cv-election-picker')?.innerText || '',
    sidebar: q('.sidebar .nav-item').map(el => ({
      text: el.textContent.trim().replace(/\\s+/g, ' '),
      top: el.getBoundingClientRect().top,
      height: el.getBoundingClientRect().height,
      font: getComputedStyle(el).fontSize,
    })),
  };
})()`;

async function main() {
  const spec = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
  const port = spec.port || 9333;

  const chrome = findChrome();
  if (!chrome) {
    process.stdout.write(JSON.stringify({ unavailable: 'no Chrome or Edge found on this computer' }));
    return;
  }

  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'coopvote-browser-'));
  const proc = spawn(chrome, [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-extensions',
    `--remote-debugging-port=${port}`,
    `--user-data-dir=${profile}`,
    'about:blank',
  ], { stdio: 'ignore', detached: false });

  const stop = () => { try { proc.kill('SIGKILL'); } catch { /* already gone */ } };

  if (!(await waitForDevTools(port, 20000))) {
    stop();
    process.stdout.write(JSON.stringify({ unavailable: 'Chrome did not open its debugging port' }));
    return;
  }

  const tab = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
  const ws = new WebSocket(tab.webSocketDebuggerUrl);
  await new Promise((r) => ws.addEventListener('open', r));

  let id = 0;
  const pending = new Map();
  let consoleErrors = [];
  let failedRequests = [];

  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return; }
    if (m.method === 'Runtime.exceptionThrown') {
      const d = m.params.exceptionDetails;
      consoleErrors.push((d.exception && d.exception.description || d.text || '').split('\n')[0]);
    }
    if (m.method === 'Network.responseReceived') {
      const r = m.params.response;
      if (r.status >= 400) failedRequests.push(r.status + ' ' + r.url.split('/').slice(-1)[0].slice(0, 60));
    }
  });

  const send = (method, params = {}) => {
    const i = ++id;
    ws.send(JSON.stringify({ id: i, method, params }));
    return new Promise((r) => pending.set(i, r));
  };
  const evaluate = async (expr) => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    return r.result && r.result.result ? r.result.result.value : undefined;
  };

  await send('Runtime.enable');
  await send('Page.enable');
  await send('Network.enable');
  await send('Network.setCacheDisabled', { cacheDisabled: true });

  const out = { pages: {}, widths: {} };

  /* ── Sign in, if the spec asks for it ── */
  if (spec.login) {
    await send('Network.clearBrowserCookies');
    await send('Page.navigate', { url: spec.base + 'login.html' });
    await sleep(spec.settle || 2200);
    await evaluate(
      `document.getElementById('username').value=${JSON.stringify(spec.login.username)};` +
      `document.getElementById('password').value=${JSON.stringify(spec.login.password)};` +
      `document.getElementById('login-btn').click()`
    );
    await sleep(spec.settle ? spec.settle + 400 : 2600);
    out.landedOn = await evaluate('location.pathname');
  }

  /* ── Measure each page ── */
  for (const page of spec.pages || []) {
    consoleErrors = [];
    failedRequests = [];
    await send('Emulation.setDeviceMetricsOverride', {
      width: 1440, height: 900, deviceScaleFactor: 1, mobile: false,
    });
    await send('Page.navigate', { url: spec.base + page });
    await sleep(spec.settle || 2200);

    const probe = await evaluate(PAGE_PROBE);
    if (page === 'admin/voter-management.html' && probe) {
      const registration = await evaluate("CoopVote.api('voters/registration-status')");
      probe.registrationAllowed = registration?.allowed;
      probe.addVoterDisabled = await evaluate("document.getElementById('add-voter-btn').disabled");
    }
    out.pages[page] = Object.assign({}, probe, {
      consoleErrors: [...new Set(consoleErrors)],
      failedRequests: [...new Set(failedRequests)],
    });
  }

  /* ── The same pages at phone and tablet widths ── */
  for (const width of spec.widths || []) {
    out.widths[width] = {};
    for (const page of spec.responsivePages || spec.pages || []) {
      await send('Emulation.setDeviceMetricsOverride', {
        width, height: 800, deviceScaleFactor: 1, mobile: width < 600,
      });
      await send('Page.navigate', { url: spec.base + page });
      await sleep(spec.settle || 2000);
      out.widths[width][page] = await evaluate(`(() => ({
        docWidth: document.documentElement.scrollWidth,
        winWidth: window.innerWidth,
        words: (document.body.innerText || '').trim().split(/\\s+/).filter(Boolean).length,
      }))()`);
    }
  }

  ws.close();
  stop();
  process.stdout.write(JSON.stringify(out));
}

main().catch((e) => {
  process.stdout.write(JSON.stringify({ unavailable: 'browser probe failed: ' + (e && e.message) }));
});

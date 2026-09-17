/* The actual download helper must never navigate the current page or open a CSV tab. */
const fs = require('fs'), vm = require('vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('assets/js/app.js', 'utf8');
const code = source.slice(source.indexOf('  const pendingDownloads'), source.indexOf('  /** Minimal toast'));
function setup() {
  const links = [], toasts = [], opened = [], timers = new Map(), revoked = [];
  let fetches = 0, request, handler = async () => new Response('header\r\nvalue\r\n', { headers: {
    'Content-Type': 'text/csv; charset=utf-8', 'Content-Disposition': 'attachment; filename="summary.csv"' } });
  const location = Object.freeze({ href: 'https://coop.test/admin/reports.html' });
  const context = { Set, Error, AbortController,
    url: route => 'https://coop.test/api/index.php?r=' + route,
    fetch: async (url, options) => { fetches++; request = { url, options }; return handler(options); },
    toast: message => toasts.push(message),
    URL: { createObjectURL: () => 'blob:summary', revokeObjectURL: value => revoked.push(value) },
    window: { location, open: (...args) => { opened.push(args); return {}; } },
    document: { body: { appendChild: link => links.push(link) }, createElement: () => ({ click() { this.clicked = true; }, remove() { this.removed = true; } }) },
    setTimeout: (fn, ms) => { const id = Symbol(); timers.set(id, { fn, ms }); return id; }, clearTimeout: id => timers.delete(id) };
  vm.createContext(context); vm.runInContext(code, context);
  const button = { textContent: 'Summary CSV', disabled: false, setAttribute(key, value) { this[key] = value; }, removeAttribute(key) { delete this[key]; } };
  return { context, button, links, toasts, opened, timers, revoked, set handler(value) { handler = value; }, get fetches() { return fetches; }, get request() { return request; } };
}
(async () => {
  const success = setup();
  assert.equal(await success.context.download('operations/report&format=csv', { button: success.button }), true);
  assert.equal(success.opened.length, 0); assert.equal(success.links.length, 1);
  assert.equal(success.links[0].download, 'summary.csv'); assert.ok(success.links[0].clicked && success.links[0].removed);
  assert.equal(success.context.window.location.href, 'https://coop.test/admin/reports.html');
  assert.equal(success.request.options.credentials, 'same-origin');
  assert.equal(success.button.textContent, 'Summary CSV'); assert.equal(success.button.disabled, false);
  for (const timer of success.timers.values()) timer.fn(); assert.deepEqual(success.revoked, ['blob:summary']);

  const error = setup();
  error.handler = async () => new Response(JSON.stringify({ error: 'Sign in again.' }), { status: 401, headers: { 'Content-Type': 'application/json' } });
  assert.equal(await error.context.download('export', { button: error.button }), false);
  assert.deepEqual(error.toasts, ['Sign in again.']); assert.equal(error.links.length, 0); assert.equal(error.opened.length, 0);
  assert.equal(error.button.disabled, false); assert.equal(error.button.textContent, 'Summary CSV');

  const html = setup(); html.handler = async () => new Response('<html>Server error</html>', { headers: { 'Content-Type': 'text/html' } });
  assert.equal(await html.context.download('export'), false); assert.equal(html.links.length, 0);

  const network = setup(); network.handler = async () => { throw Error('Network interrupted'); };
  assert.equal(await network.context.download('export', { button: network.button }), false);
  assert.equal(network.button.disabled, false); assert.equal(network.toasts.length, 1);

  const repeated = setup(); let finish;
  repeated.handler = () => new Promise(resolve => finish = resolve);
  const first = repeated.context.download('export', { button: repeated.button });
  assert.equal(repeated.button.disabled, true);
  assert.equal(await repeated.context.download('export'), false); assert.equal(repeated.fetches, 1);
  finish(new Response('data', { headers: { 'Content-Type': 'text/csv' } }));
  assert.equal(await first, true); assert.equal(repeated.button.disabled, false);

  const timeout = setup(); timeout.handler = options => new Promise((resolve, reject) => options.signal.addEventListener('abort', () => { const error = Error(); error.name = 'AbortError'; reject(error); }));
  const waiting = timeout.context.download('export', { button: timeout.button });
  [...timeout.timers.values()].find(timer => timer.ms === 30000).fn();
  assert.equal(await waiting, false); assert.equal(timeout.button.disabled, false); assert.match(timeout.toasts[0], /too long/);

  const preview = setup(); assert.equal(await preview.context.download('report&format=html', { preview: true }), true);
  assert.equal(preview.opened.length, 1); assert.equal(preview.fetches, 0); assert.equal(preview.links.length, 0);
  console.log('7 download scenarios passed: success, API error, invalid content, network failure, repeated click, timeout, print preview.');
})().catch(error => { console.error(error); process.exitCode = 1; });

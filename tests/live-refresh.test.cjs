/* Run the real polling scheduler against a deterministic clock and page lifecycle. */
const fs = require('fs'), vm = require('vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('assets/js/app.js', 'utf8');
const start = source.indexOf('  function watch(work,');
const end = source.indexOf('  async function initNotifications', start);
const timers = new Map(), events = new Map();
let nextId = 1, editing = false, modal = false, calls = 0, release, fail = false;
const doc = { hidden: false, activeElement: { matches: () => editing }, querySelector: () => modal,
  addEventListener: (event, fn) => events.set(event, fn), removeEventListener: event => events.delete(event) };
const context = { document: doc, navigator: { onLine: true }, window: doc, Date, Math, loggingOut: false,
  setTimeout: (fn, delay) => { const id = nextId++; timers.set(id, { fn, delay }); return id; },
  clearTimeout: id => timers.delete(id) };
vm.createContext(context); vm.runInContext(source.slice(start, end), context);
async function tick() { const [id, timer] = [...timers][0]; timers.delete(id); return timer.fn(); }
(async () => {
  const status = {};
  const stop = context.watch(async () => { calls++; if (fail) throw Error('offline'); await new Promise(resolve => release = resolve); }, 10000, status);
  doc.hidden = true; await tick(); assert.equal(calls, 0);
  doc.hidden = false; context.navigator.onLine = false; await tick(); assert.equal(calls, 0);
  context.navigator.onLine = true; editing = true; await tick(); assert.equal(calls, 0);
  editing = false; modal = true; await tick(); assert.equal(calls, 0);
  modal = false; const pending = tick(); assert.equal(calls, 1);
  events.get('online')(); assert.equal(calls, 1, 'Requests must not overlap');
  release(); await pending; assert.equal(timers.size, 1);
  fail = true; await tick(); assert.match(status.textContent, /retrying/);
  assert.equal([...timers.values()][0].delay, 20000, 'Failures back off');
  stop(); assert.equal(timers.size, 0); assert.equal(events.has('online'), false);
  fail = false;
  const stopLogout = context.watch(async () => { calls++; await new Promise(resolve => release = resolve); });
  const inFlight = tick();
  context.loggingOut = true;
  release(); await inFlight;
  assert.equal(timers.size, 0, 'An in-flight refresh must not schedule another poll after logout');
  const atLogout = calls;
  events.get('online')(); events.get('visibilitychange')();
  assert.equal(calls, atLogout, 'Lifecycle events must not restart requests during logout');
  stopLogout();
  console.log('Live refresh checks passed, including in-flight completion and lifecycle events during logout.');
})().catch(error => { console.error(error); process.exitCode = 1; });

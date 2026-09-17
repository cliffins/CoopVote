/* Run the real polling scheduler against a deterministic clock and page lifecycle. */
const fs = require('fs'), vm = require('vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('assets/js/app.js', 'utf8');
const start = source.indexOf('  function watch(work,');
const end = source.indexOf('  async function initNotifications', start);
const timers = new Map(), events = new Map();
let nextId = 1, editing = false, modal = false, calls = 0, release, fail = false;
const doc = { hidden: false, activeElement: { matches: () => editing }, querySelector: () => modal,
  addEventListener: (event, fn) => events.set(event, fn), removeEventListener: event => events.delete(event) };
const context = { document: doc, navigator: { onLine: true }, window: doc, Date, Math,
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
  console.log('11 live refresh checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });

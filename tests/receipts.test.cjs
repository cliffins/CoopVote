const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const handlers = {};
const element = id => ({
  innerHTML: '', textContent: '', disabled: false, scrollTop: 120,
  setAttribute() {}, contains: () => true,
  addEventListener: (type, fn) => { handlers[id + ':' + type] = fn; }
});
const elements = Object.fromEntries(['receipt-list', 'receipt-status', 'refresh-receipts', 'print-area', 'content'].map(id => [id, element(id)]));
let resolveRequest, rejectRequest, requests = 0, watcher, prints = 0;
let deadline, lastSignal, toasts = 0;
let now = Date.now(), timerId = 0;
const timers = new Map();
class TestDate extends Date { static now() { return now; } }
const advance = milliseconds => {
  now += milliseconds;
  for (const [id, timer] of timers) if (timer.at <= now) { timers.delete(id); timer.callback(); }
};
const context = vm.createContext({
  AbortController,
  Date: TestDate,
  setTimeout: (callback, delay) => {
    if (delay === 15000) deadline = callback;
    const id = ++timerId; timers.set(id, { callback, at: now + delay }); return id;
  },
  clearTimeout: id => { timers.delete(id); },
  document: {
    getElementById: id => elements[id],
    querySelector: () => elements.content
  },
  window: { print: () => { prints++; }, addEventListener: (type, fn) => { handlers[type] = fn; } },
  esc: text => String(text).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
  CoopVote: {
    guard: async role => { assert.equal(role, 'member'); },
    api: (route, options) => {
      assert.equal(route, 'voter/receipts'); requests++;
      assert.equal(options.cache, 'no-store');
      lastSignal = options.signal;
      return new Promise((resolve, reject) => { resolveRequest = resolve; rejectRequest = reject; });
    },
    watch: (fn, interval) => { assert.equal(interval, 10000); watcher = fn; },
    toast: () => { toasts++; }
  }
});
const source = fs.readFileSync(path.join(root, 'assets/js/receipts.js'), 'utf8');
vm.runInContext(source, context);
const flush = () => new Promise(resolve => setImmediate(resolve));
const first = { id: 1, electionId: 2, electionTitle: '<Election>', receiptCode: 'CV-ONE', castAt: 'September 13, 2026 · 6:30 PM', published: false };
let data = { member: { name: '<Voter>', memberId: 'MEMBER-1' }, receipts: [first, { ...first, id: 2, receiptCode: 'CV-TWO' }] };
(async () => {
  await flush();
  assert.equal(elements['refresh-receipts'].disabled, true);
  assert.equal(elements['refresh-receipts'].textContent, 'Refreshing…');
  handlers['refresh-receipts:click']();
  assert.equal(requests, 1);
  resolveRequest(data); await flush();
  assert.equal(elements['refresh-receipts'].disabled, false);
  assert.equal(elements['refresh-receipts'].textContent, 'Refresh');
  assert.match(elements['receipt-status'].textContent, /Latest votes first/);
  assert.equal(toasts, 0, 'initial loading is silent');
  handlers['refresh-receipts:click']();
  assert.equal(requests, 2);
  resolveRequest(data); await flush();
  assert.equal(toasts, 1, 'manual refresh confirms success even when data is unchanged');
  assert.equal(elements['refresh-receipts'].disabled, true);
  for (let i = 0; i < 10; i++) handlers['refresh-receipts:click']();
  assert.equal(requests, 2, 'cooldown prevents repeated requests');
  assert.equal(toasts, 1, 'cooldown prevents repeated toasts');
  advance(5000);
  assert.equal(elements['refresh-receipts'].disabled, false);
  assert.match(elements['receipt-list'].innerHTML, /Please wait for the admin/);
  assert.match(elements['receipt-list'].innerHTML, /&lt;Election&gt;/);
  assert.match(elements['receipt-list'].innerHTML, /&lt;Voter&gt;/);
  assert.equal(elements.content.scrollTop, 120);
  handlers['receipt-list:click']({ target: { closest: () => ({ dataset: { print: '1' } }) } });
  assert.equal(prints, 1);
  assert.match(elements['print-area'].innerHTML, /CV-ONE/);
  assert.doesNotMatch(elements['print-area'].innerHTML, /CV-TWO|data-print/);
  handlers.afterprint();
  assert.equal(elements['print-area'].innerHTML, '');
  assert.match(elements['receipt-list'].innerHTML, /CV-ONE/);
  handlers['receipt-list:click']({ target: { closest: () => ({ dataset: { print: '999' } }) } });
  assert.equal(prints, 1);
  let pending = watcher();
  data.receipts[0].published = true;
  resolveRequest(data); await pending;
  assert.match(elements['receipt-list'].innerHTML, /admin has published/);
  pending = watcher();
  rejectRequest(new Error('Offline'));
  await assert.rejects(pending, /Offline/);
  assert.match(elements['receipt-list'].innerHTML, /CV-ONE/);
  assert.match(elements['receipt-status'].textContent, /last loaded receipts/);
  assert.equal(toasts, 1, 'background refresh errors do not spam toasts');
  assert.equal(elements['refresh-receipts'].disabled, false);
  pending = watcher();
  const lateResponse = resolveRequest;
  deadline();
  await assert.rejects(pending, /timed out/);
  assert.equal(lastSignal.aborted, true);
  assert.equal(elements['refresh-receipts'].disabled, false);
  assert.equal(elements['refresh-receipts'].textContent, 'Refresh');
  lateResponse({ member: data.member, receipts: [] }); await flush();
  assert.match(elements['receipt-list'].innerHTML, /CV-ONE/, 'late response cannot replace data after timeout');
  pending = watcher(); resolveRequest({ member: data.member, receipts: [] }); await pending;
  assert.match(elements['receipt-list'].innerHTML, /No vote receipts yet/);
  handlers['refresh-receipts:click']();
  rejectRequest(new Error('Offline')); await flush();
  assert.equal(toasts, 2, 'manual refresh failure produces one toast');
  const failedRequests = requests;
  handlers['refresh-receipts:click']();
  assert.equal(requests, failedRequests, 'failed requests also have a cooldown');
  advance(5000);
  handlers['refresh-receipts:click']();
  resolveRequest(data); await flush();
  assert.equal(toasts, 3, 'refresh works again after the cooldown');
  for (const page of ['dashboard', 'ballot', 'election', 'candidates', 'results', 'profile', 'receipts']) {
    const html = fs.readFileSync(path.join(root, 'voter', page + '.html'), 'utf8');
    assert.match(html, /href="receipts.html"/);
    for (const script of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)) new vm.Script(script[1]);
  }
  const html = fs.readFileSync(path.join(root, 'voter/receipts.html'), 'utf8');
  assert.match(html, /@media print/);
  assert.equal((html.match(/href="profile.html"/g) || []).length, 1, 'only the standard profile link remains');
  assert.doesNotMatch(html, /class="nav-item active" href="profile.html"/, 'profile is not highlighted on receipts');
  assert.equal((html.match(/class="nav-item active"/g) || []).length, 1, 'only receipts is the active page');
  assert.match(html, /body > :not\(#print-area\)/);
  assert.doesNotMatch(source, /window\.open|location\s*=/);
  console.log('Receipt UI checks passed: access guard, navigation, escaping, refresh, retry, empty state, selected receipt printing and print cleanup.');
})().catch(error => { console.error(error); process.exitCode = 1; });

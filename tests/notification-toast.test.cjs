/* Separate page contexts share browser storage, but never share in-memory state. */
const fs = require('fs'), vm = require('vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('assets/js/app.js', 'utf8');
const start = source.indexOf('  async function initNotifications(user)');
const implementation = source.slice(start, source.indexOf('\n  /**', start));
const storage = new Map(), reads = new Map();
let notices = [{ id: 'open:1', text: 'Open' }], toasts = [];
async function page(userId) {
  const handlers = {}, children = [];
  const bell = { dataset: {}, style: {}, querySelector: () => children.find(x => x.className === 'cv-badge'),
    appendChild: item => children.push(item), setAttribute() {}, addEventListener: (name, fn) => handlers[name] = fn };
  const context = { Set, Array, JSON, Number,
    document: { querySelector: () => bell, getElementById: () => null,
      createElement: () => ({ style: {}, remove() { children.splice(children.indexOf(this), 1); } }) },
    localStorage: { getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value) },
    seenKey: () => 'coopvote.seenNotices.' + userId,
    buildNotices: async () => notices.filter(n => !(reads.get(userId) || []).includes(n.id)),
    toast: message => toasts.push(message), watch: work => { context.refresh = work; },
    api: async (route, options) => { reads.set(userId, [...(reads.get(userId) || []), options.body.id]); },
    openNoticePanel: (b, unread, onRead) => { context.unread = unread; context.clickNotice = onRead; },
    setSelectedElection() {} };
  vm.createContext(context);
  await vm.runInContext(implementation + '\ninitNotifications({role:"voter"});', context);
  return { context, open: () => handlers.click({ stopPropagation() {} }) };
}
(async () => {
  await page(1); assert.equal(toasts.length, 1);
  const second = await page(1), third = await page(1);
  assert.equal(toasts.length, 1, 'Navigation must not repeat a toast');
  third.open(); assert.equal(reads.size, 0, 'Opening the bell must not mark a notice read');
  assert.equal(third.context.unread.length, 1);
  await third.context.refresh(); assert.equal(toasts.length, 1, 'Polling must not repeat a toast');
  notices.push({ id: 'deadline:1', text: 'Closing soon' });
  await third.context.refresh(); assert.equal(toasts.length, 2, 'New deadline gets one toast');
  await second.context.refresh(); assert.equal(toasts.length, 2, 'Another tab observes saved announcements');
  await page(2); assert.equal(toasts.length, 3, 'Each account has separate announcement history');
  third.open(); await third.context.clickNotice(notices[0]);
  assert.deepEqual(reads.get(1), ['open:1'], 'Only the clicked notice is read');
  assert.equal(reads.has(2), false);
  const next = await page(1); next.open(); assert.equal(next.context.unread.length, 1);
  assert.equal(toasts.length, 3);
  console.log('12 notification toast checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });

/* Verify initial report selection and stale-response handling in the shared UI module. */
const fs = require('fs'), vm = require('vm'), assert = require('node:assert/strict');
const panels = new Map(), applied = [], requests = [], status = { isConnected: true };
const content = { scrollTop: 120, prepend: node => panels.set(node.id, node) };
let selected = null, poll, pending, defer = false;
const overview = { election: { id: 1, title: 'Election One', status: 'Closed' },
  integrity: { status: 'passed', checkedVotes: 6, baselineVotes: 0, message: 'Signatures match.' }, reportGeneratedAt: '2026-09-13' };
const cv = { esc: value => String(value), keepPagination: false,
  watch: fn => { poll = fn; }, toast() {}, download() {},
  api: async route => {
    requests.push(route);
    if (route.startsWith('operations/')) throw Error('Removed panel must not request assurance data');
    if (defer) return new Promise(resolve => pending = resolve);
    return { selected: { id: 1 } };
  } };
const doc = { activeElement: null,
  querySelector: selector => selector === '.content' ? content : selector === '.header-title p' ? status : null,
  getElementById: id => panels.get(id),
  createElement: () => ({ innerHTML: '', querySelectorAll: () => [], querySelector: () => ({ addEventListener() {} }), remove() { panels.delete(this.id); } }) };
vm.runInNewContext(fs.readFileSync('assets/js/election-operations.js', 'utf8'), { window: { CoopVote: cv }, document: doc, Number, Math, JSON });
(async () => {
  cv.startLive(() => selected ? 'reports&election=' + selected.id : 'reports', data => { selected = data.selected; applied.push(selected.id); });
  await new Promise(setImmediate);
  assert.equal(applied.length, 1);
  assert.equal(panels.size, 0, 'Reports has no injected assurance panel');
  assert.equal(content.scrollTop, 120);
  assert.equal(cv.keepPagination, false);
  selected = { id: 2 }; defer = true;
  const request = poll();
  selected = { id: 3 }; pending({ selected: { id: 2 } }); await request;
  assert.deepEqual(applied, [1], 'A stale reply must not switch back to an older selection');
  assert.equal(selected.id, 3);
  defer = false;
  for (const route of ['dashboard', 'monitor']) {
    cv.startLive(route, () => {});
    await new Promise(setImmediate);
    assert.equal(panels.size, 0, route + ' does not recreate the removed panel');
  }
  assert.ok(requests.every(route => !route.startsWith('operations/')), 'No redundant assurance requests');
  const reports = fs.readFileSync('admin/reports.html', 'utf8');
  const results = fs.readFileSync('admin/results.html', 'utf8');
  assert.equal((reports.match(/onclick="printReport\(\)"/g) || []).length, 1, 'Reports has one print action');
  assert.doesNotMatch(reports, /Summary CSV|data-report=/, 'No duplicate export controls');
  assert.match(reports, /class="btn btn-outline report-print"/, 'Reports uses the Results button style');
  assert.equal((results.match(/href="reports.html[^\"]*"/g) || []).length, 2, 'Results has its sidebar link and one export link');
  assert.match(results, /reports.html\?election=\$\{Number\(el.id\)\}/, 'Selected election travels to Reports');
  const handler = reports.match(/    function printReport\(\) \{[\s\S]*?\n    \}/)[0];
  const downloads = [], printContext = { reportData: null, Number, CoopVote: { download: (route, options) => downloads.push({ route, options }) } };
  vm.createContext(printContext); vm.runInContext(handler, printContext);
  printContext.printReport(); assert.equal(downloads.length, 0, 'No empty-election export');
  printContext.reportData = { selected: { id: 42 } }; printContext.printReport();
  assert.equal(downloads.length, 1); assert.equal(downloads[0].route, 'operations/report&election=42&format=html');
  assert.equal(downloads[0].options.preview, true, 'Print keeps the existing PDF preview behavior');
  console.log('Refresh, single export action, and selected-election checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });

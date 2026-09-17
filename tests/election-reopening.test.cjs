const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const html = fs.readFileSync(path.join(__dirname, '../admin/election-management.html'), 'utf8');
for (const script of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)) new vm.Script(script[1]);
const nodes = {};
const node = id => nodes[id] ||= { value: '', textContent: '', innerHTML: '', disabled: false, style: {}, focus() {} };
let calls = [], resolveRequest, rejectRequest, mode = 'save', reloads = 0;
const opened = [], notices = [];
const context = vm.createContext({
  allElections: [{ id: 7, title: '<Board election>', status: 'Closed', startISO: '2026-01-01T09:00', endISO: '2026-01-02T09:00',
    positionList: [{ name: 'President' }, { name: 'Vice   President' }, { name: '<Custom Seat>' }] }],
  document: {
    getElementById: node,
    querySelector: () => node('first-position'),
    querySelectorAll: selector => {
      assert.equal(selector, '#pos-checklist input:checked:not(:disabled)');
      return [...node('pos-checklist').innerHTML.matchAll(/<input([^>]*)\/><span>(.*?)<\/span>/g)]
        .filter(match => match[1].includes('checked') && !match[1].includes('disabled'))
        .map(match => ({ closest: () => ({ querySelector: () => ({ textContent: match[2] }) }) }));
    }
  },
  esc: value => String(value).replaceAll('<', '&lt;').replaceAll('>', '&gt;'),
  CoopVoteValidation: { validate: () => true },
  openModal(id) { opened.push(id); }, closeModal() {}, showToast(message) { notices.push(message); },
  loadElections: () => { reloads++; },
  CoopVote: { api: (route, options) => {
    calls.push({ route, options });
    if (mode === 'save') return Promise.resolve({ id: 7 });
    return new Promise((resolve, reject) => { resolveRequest = resolve; rejectRequest = reject; });
  } }
});
for (const name of ['renderPositionChecks', 'clearForm', 'openEditId', 'saveElection', 'askReopen', 'confirmReopen']) {
  const match = html.match(new RegExp('(?:async )?function ' + name + '\\([\\s\\S]*?\\n    }'));
  assert.ok(match, name);
  vm.runInContext(match[0], context);
}
vm.runInContext('let reopenTarget = null; let reopening = false;', context);
(async () => {
  context.openEditId(7);
  assert.equal(opened.length, 0, 'closed election never opens the edit form');
  assert.equal(notices.at(-1), 'The election must be open to edit.');
  context.allElections[0].status = 'Upcoming';
  context.openEditId(7);
  assert.equal(opened.at(-1), 'election-modal');
  let labels = [...node('pos-checklist').innerHTML.matchAll(/<label[\s\S]*?<\/label>/g)].map(m => m[0]);
  assert.equal(labels.filter(label => label.includes('disabled')).length, 3);
  assert.equal(labels.filter(label => label.includes('Vice')).length, 1);
  assert.match(labels.find(label => label.includes('&lt;Custom Seat&gt;')), /checked/);
  assert.doesNotMatch(labels.find(label => label.includes('Secretary')), /checked/);
  assert.equal(node('edit-id').value, 7);
  assert.equal(node('election-save').disabled, false);
  assert.equal(node('election-edit-note').style.display, 'none');
  await context.saveElection();
  assert.equal(calls.length, 1, 'saving existing positions makes no duplicate position requests');
  assert.equal(calls[0].options.method, 'PUT');
  context.clearForm();
  assert.doesNotMatch(node('pos-checklist').innerHTML, /Custom|disabled/);
  assert.equal((node('pos-checklist').innerHTML.match(/class="pos-check checked"/g) || []).length, 6);

  node('f-title').value = 'New Election';
  node('f-start').value = '2099-01-01T09:00';
  node('f-end').value = '2099-01-02T09:00';
  node('pos-checklist').innerHTML = node('pos-checklist').innerHTML.replaceAll('checked', '');
  calls = [];
  await context.saveElection();
  assert.equal(calls.length, 0, 'No election request is sent without selected positions');
  assert.equal(notices.at(-1), 'Select at least one position to save this election.');
  context.renderPositionChecks();
  await context.saveElection();
  assert.equal(calls.length, 1, 'Create saves election and positions in a single request');
  assert.equal(calls[0].options.method, 'POST');
  assert.equal(calls[0].options.body.positions.length, 6);
  assert.equal(calls[0].options.body.positions[0], 'President');

  calls = []; mode = 'reopen';
  context.allElections[0].status = 'Closed';
  context.askReopen(7);
  assert.equal(node('reopen-election-name').textContent, '<Board election>');
  await context.confirmReopen();
  assert.equal(calls.length, 0);
  assert.match(node('reopen-error').textContent, /future/);
  node('reopen-end').value = '2099-01-01T12:00';
  const failed = context.confirmReopen();
  await context.confirmReopen();
  assert.equal(calls.length, 1, 'double click cannot send two reopen requests');
  assert.equal(node('reopen-save').disabled, true);
  rejectRequest(new Error('Could not reopen'));
  await failed;
  assert.equal(node('reopen-save').disabled, false);
  assert.equal(node('reopen-error').textContent, 'Could not reopen');
  const success = context.confirmReopen();
  assert.equal(calls[1].route, 'elections/7/reopen');
  assert.equal(calls[1].options.body.endDate, '2099-01-01T12:00');
  resolveRequest({ ok: true }); await success;
  assert.equal(node('reopen-save').textContent, 'Reopen Election');
  assert.equal(reloads, 3);
  assert.match(html, /e\.status === 'Closed' \? `<button[^`]+askReopen/);
  console.log('Election UI checks passed: existing/custom positions, no duplicate additions, form reset, reopen validation, retries and double-click handling.');
})().catch(error => { console.error(error); process.exitCode = 1; });

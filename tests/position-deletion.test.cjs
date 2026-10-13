const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const html = fs.readFileSync(path.join(__dirname, '../admin/election-management.html'), 'utf8');
const nodes = {};
const node = id => nodes[id] ||= { value: '', textContent: '', innerHTML: '', disabled: false, classList: { remove() {} }, focus() { this.focused = true; } };
let calls = [], opened = [], notices = [], removed = '', renders = 0, resolve, reject;
const positions = [{ id: 11, name: '<Treasurer>', candidateCount: 0, topVotes: 0 }, { id: 12, name: 'Secretary', candidateCount: 0, topVotes: 0 }];
const election = { id: 1, title: '<Assembly>', status: 'Upcoming', positions: 2, positionList: positions };
const context = vm.createContext({
  allElections: [election],
  document: { getElementById: node, querySelector: selector => ({ remove() { removed = selector; } }) },
  openModal: id => opened.push(id), showToast: message => notices.push(message), initElections: () => { renders++; },
  esc: value => String(value).replaceAll('<', '&lt;').replaceAll('>', '&gt;'),
  CoopVote: { api(route, options) { calls.push({route, options}); return new Promise((yes, no) => { resolve = yes; reject = no; }); } }
});
vm.runInContext('let positionDeleteTarget = null, positionDeleting = false;', context);
for (const name of ['renderPositionChecks', 'askDeletePosition', 'confirmDeletePosition', 'closeModal']) {
  vm.runInContext(html.match(new RegExp('(?:async )?function ' + name + '\\([\\s\\S]*?\\n    }'))[0], context);
}
(async () => {
  context.renderPositionChecks(positions, 1);
  assert.match(node('pos-checklist').innerHTML, /askDeletePosition\(1, 11\)/);
  assert.match(node('pos-checklist').innerHTML, /&lt;Treasurer&gt;/);
  assert.doesNotMatch(node('pos-checklist').innerHTML, /<Treasurer>/);
  context.askDeletePosition(1, 11);
  assert.equal(opened.at(-1), 'delete-position-modal');
  assert.match(node('delete-position-message').textContent, /<Treasurer>.*<Assembly>/);
  assert.equal(node('delete-position-cancel').focused, true);
  context.closeModal('delete-position-modal');
  await context.confirmDeletePosition();
  assert.equal(calls.length, 0, 'Cancel never deletes');
  context.askDeletePosition(1, 11);
  const failed = context.confirmDeletePosition();
  await context.confirmDeletePosition();
  assert.equal(calls.length, 1, 'Double click sends one DELETE');
  assert.equal(node('delete-position-save').disabled, true);
  context.closeModal('delete-position-modal');
  reject(new Error('Position now has candidates')); await failed;
  assert.match(notices.at(-1), /now has candidates/);
  assert.equal(election.positionList.length, 2);
  assert.equal(node('delete-position-save').disabled, false, 'Failure enables retry');
  const success = context.confirmDeletePosition();
  assert.equal(calls[1].route, 'positions/11&election=1');
  assert.equal(calls[1].options.method, 'DELETE');
  resolve({ok: true}); await success;
  assert.equal(election.positions, 1);
  assert.equal(election.positionList[0].id, 12);
  assert.equal(removed, '[data-position-id="11"]');
  assert.equal(renders, 1, 'Counts and position cards refresh');
  assert.equal(notices.at(-1), 'Position deleted.');
  context.askDeletePosition(1, 12);
  assert.match(notices.at(-1), /at least one/);
  election.positionList = positions;
  positions[0].candidateCount = 1;
  context.askDeletePosition(1, 11);
  assert.match(notices.at(-1), /Candidate Management/);
  positions[0].topVotes = 1;
  context.askDeletePosition(1, 11);
  assert.match(notices.at(-1), /has votes/);
  election.status = 'Closed';
  context.askDeletePosition(1, 11);
  assert.match(notices.at(-1), /Reopen/);
  context.askDeletePosition(1, 99);
  assert.match(notices.at(-1), /no longer exists/);
  assert.equal(calls.length, 2, 'Blocked actions send no requests');
  console.log('Position deletion UI passed: escaped labels, confirmation, cancellation, duplicate clicks, retry, refresh and protected positions.');
})().catch(error => { console.error(error); process.exitCode = 1; });

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync(path.join(__dirname, '../admin/candidate-management.html'), 'utf8');
const nodes = {};
const node = id => nodes[id] ||= { value: '', innerHTML: '', textContent: '', className: '' };
let rendered = [], downloads = [], notices = [];
const elections = [{id: 1, title: '<First Assembly>'}, {id: 2, title: 'Second Assembly'}, {id: 3, title: 'No Candidates'}];
const candidates = [
  {id: 11, electionId: 1, first: 'Ana', last: 'Santos', position: 'President', status: 'Active', votes: 2},
  {id: 12, electionId: 1, first: 'Ben', last: 'Cruz', position: 'Custom Seat', status: 'Inactive', votes: 1},
  {id: 21, electionId: 2, first: 'Ana', last: 'Santos', position: 'President', status: 'Active', votes: 8},
  {id: 22, electionId: 2, first: "D'Angelo", last: 'Reyes', position: 'Secretary', status: 'Active', votes: 4}
];
const context = vm.createContext({
  document: { getElementById: node },
  esc: value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
  showToast: message => notices.push(message),
  CoopVote: { api: async route => route === 'elections' ? elections : candidates, toast: message => notices.push(message),
    download: async route => { downloads.push(route); return true; } }
});
vm.runInContext('let allCandidates = [], candidateFilterElections = [], filtered = [], currentPage = 1;', context);
context.renderView = () => { rendered = JSON.parse(vm.runInContext('JSON.stringify(filtered)', context)); };
for (const name of ['loadCandidates', 'initCandidates', 'candidatesInSelectedElection', 'changeCandidateElection', 'updateSummary', 'applyFilters', 'exportCandidates']) {
  vm.runInContext(html.match(new RegExp('(?:async )?function ' + name + '\\([\\s\\S]*?\\n    }'))[0], context);
}
(async () => {
  await context.loadCandidates();
  assert.equal(rendered.length, 4);
  assert.match(node('election-filter').innerHTML, /&lt;First Assembly&gt;/);
  assert.match(node('election-filter').innerHTML, /No Candidates/, 'Empty elections remain selectable');
  node('election-filter').value = '1';
  vm.runInContext('currentPage = 4', context);
  context.changeCandidateElection();
  assert.deepEqual(rendered.map(c => c.id), [11, 12]);
  assert.equal(vm.runInContext('currentPage', context), 1);
  assert.equal(node('sc-total').textContent, '2');
  assert.equal(node('sc-votes').textContent, '3');
  assert.doesNotMatch(node('pos-filter').innerHTML, /Secretary/);
  node('pos-filter').value = 'Custom Seat';
  context.applyFilters();
  assert.deepEqual(rendered.map(c => c.id), [12]);
  node('election-filter').value = '2';
  context.changeCandidateElection();
  assert.equal(node('pos-filter').value, '', 'Switch clears a position outside this election');
  assert.deepEqual(rendered.map(c => c.id), [21, 22]);
  node('search-input').value = "D'Angelo";
  context.applyFilters();
  assert.deepEqual(rendered.map(c => c.id), [22], 'Search uses original names, including apostrophes');
  node('status-filter').value = 'Inactive';
  context.applyFilters();
  assert.equal(rendered.length, 0);
  node('status-filter').value = '';
  await context.loadCandidates();
  assert.equal(node('election-filter').value, '2', 'Refresh preserves selected election');
  assert.deepEqual(rendered.map(c => c.id), [22], 'Refresh preserves search too');
  await context.exportCandidates();
  assert.equal(downloads.at(-1), 'candidates/export&election=2');
  node('search-input').value = '';
  node('election-filter').value = '3';
  context.changeCandidateElection();
  assert.equal(rendered.length, 0);
  assert.equal(node('sc-total').textContent, '0');
  await context.exportCandidates();
  assert.equal(downloads.length, 1, 'Empty election does not export another elections candidates');
  context.initCandidates(candidates, elections.slice(0, 2));
  assert.equal(node('election-filter').value, '', 'Deleted election falls back to All Elections');
  assert.equal(rendered.length, 4);
  context.CoopVote.api = async () => { throw new Error('Network failure'); };
  await context.loadCandidates();
  assert.equal(rendered.length, 4, 'Temporary load failure preserves existing candidates');
  assert.equal(notices.at(-1), 'Network failure');
  console.log('Candidate election filters passed: scoped lists, positions, summaries, search/status, pagination reset, refresh, empty elections and export.');
})().catch(error => { console.error(error); process.exitCode = 1; });

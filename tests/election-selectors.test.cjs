const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const html = fs.readFileSync(path.join(__dirname, '../admin/election-management.html'), 'utf8');
const app = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
const escape = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const nodes = {}, stored = new Map(), intervals = new Map();
let timer = 0;
const node = id => nodes[id] ||= { innerHTML: '', textContent: '', value: '', style: {}, disabled: false };
const now = Date.now();
const election = (id, hours) => ({
  id, title: 'Election ' + id, status: 'Active', voters: 10, voted: id, positions: 1, candidates: 2,
  startDate: 'Today', endDate: 'Tomorrow', endISO: new Date(now + hours * 3600000).toISOString(),
  positionList: [{ name: 'Seat ' + id, candidateCount: 0, maxVotes: 1, topVotes: 0 }]
});
const context = vm.createContext({
  window: {},
  allElections: [election(1, 2), election(2, 8), { ...election(3, 0), status: 'Closed' }],
  document: { getElementById: node, querySelectorAll: () => [] },
  sessionStorage: { setItem: (key, value) => stored.set(key, value), removeItem: key => stored.delete(key) },
  esc: escape, POS_COLORS: ['#c9a84c'], POS_AVATARS: [],
  setTimeout() { }, setInterval: fn => { const id = ++timer; intervals.set(id, fn); return id; },
  clearInterval: id => intervals.delete(id),
  openModal() { }, showToast() { }, renderElectionsGrid() { }
});
vm.runInContext('let activeElectionId = null, positionsElectionId = null, cdInterval = null, closeTarget = null;', context);
for (const name of ['selectedPositionsElection', 'syncPositionsElectionSelector', 'selectPositionsElection', 'selectedActiveElection', 'syncActiveElectionSelector', 'selectActiveElection', 'initElections', 'renderHero', 'renderPositions', 'startCountdown', 'askCloseVoting']) {
  vm.runInContext(html.match(new RegExp('function ' + name + '\\([\\s\\S]*?\\n    }'))[0], context);
}
context.initElections(context.allElections);
assert.equal(node('active-election-select').value, '1');
assert.doesNotMatch(node('active-election-select').innerHTML, /Election 3/);
const firstHours = Number(node('cd-h').textContent);
context.selectActiveElection('2');
assert.equal(context.selectedActiveElection().id, 2);
assert.match(node('hero-card').innerHTML, /Election 2/);
assert.match(node('hero-card').innerHTML, /askCloseVoting\(2\)/);
assert.match(node('positions-sub').textContent, /Election 2/);
assert.ok(Number(node('cd-h').textContent) > firstHours + 4);
assert.equal(intervals.size, 1, 'switching replaces the countdown interval');
context.askCloseVoting();
assert.equal(vm.runInContext('closeTarget', context), 2);
assert.equal(stored.get('cv:admin-active-election'), '2');
const draft = { ...election(4, 8), status: 'Draft', candidates: 0 };
context.initElections([...context.allElections, draft]);
context.selectPositionsElection('4');
assert.match(node('positions-sub').textContent, /Election 4/, 'a draft has its own position overview');
assert.match(node('positions-grid').innerHTML, /Seat 4/);
assert.doesNotMatch(node('positions-grid').innerHTML, /Seat 2/, 'active-election positions do not leak into a draft');
assert.equal(context.selectedActiveElection().id, 2, 'viewing draft positions does not change the live countdown');
context.initElections([...context.allElections].reverse());
assert.equal(context.selectedPositionsElection().id, 4, 'refresh preserves the chosen position election');
context.selectPositionsElection('3');
assert.equal(context.selectedPositionsElection().status, 'Closed', 'historical positions can be inspected');
context.selectPositionsElection('999');
assert.equal(context.selectedPositionsElection().id, 3, 'invalid selections cannot borrow another election');
context.initElections([...context.allElections].reverse());
assert.equal(context.selectedActiveElection().id, 2, 'refresh order does not reset the selection');
context.selectActiveElection('3');
assert.equal(context.selectedActiveElection().id, 2, 'closed elections cannot enter the active selector');
context.allElections.find(e => e.id === 2).status = 'Closed';
context.initElections(context.allElections);
assert.equal(context.selectedActiveElection().id, 1, 'closing selected election falls back to another open election');
context.allElections.forEach(e => e.status = 'Closed');
context.initElections(context.allElections);
assert.equal(node('active-election-controls').style.display, 'none');
assert.equal(intervals.size, 0);
assert.match(node('hero-card').innerHTML, /No Active Election/);
context.initElections([]);
assert.equal(context.selectedPositionsElection(), null);
assert.equal(node('positions-election-select').disabled, true);
assert.doesNotMatch(node('positions-grid').innerHTML, /Seat 3/, 'empty inventory clears old positions');

let pickerMarkup = '', css = '';
const events = {};
let selectedId = '1', reloads = 0;
const control = { addEventListener(name, handler) { events[name] = handler; }, classList: { remove() { }, add() { }, contains() { } }, setAttribute() { } };
const picker = vm.createContext({
  requestedElection: () => '',
  api: async () => ({ selectedId: 1, elections: [{ id: 1, title: '<Long election title>'.repeat(8), hasVoted: true, live: true }, { id: 2, title: 'Other election', status: 'Upcoming' }] }),
  selectedElection: () => selectedId, setSelectedElection(id) { selectedId = id; }, escapeHtml: escape,
  window: { CoopVote: {}, location: { pathname: '/CoopVote/voter/ballot.html', reload() { reloads++; } } },
  document: {
    getElementById: id => ['cv-election-picker', 'cv-picker-style'].includes(id) ? null : control,
    querySelector: () => ({ insertBefore: element => { pickerMarkup = element.innerHTML; }, firstChild: null }),
    createElement: () => ({}), head: { appendChild: element => { css = element.textContent; } }, addEventListener() { }
  }
});
for (const name of ['isElectionPickerPage', 'initElectionPicker', 'injectPickerStyles']) {
  vm.runInContext(app.match(new RegExp('(?:async )?function ' + name + '\\([\\s\\S]*?\\n  }'))[0], picker);
}
picker.initElectionPicker().then(async () => {
  assert.match(pickerMarkup, /cv-opt-title/);
  assert.match(pickerMarkup, /<span class="cv-opt-note">Voted<\/span>/);
  assert.match(pickerMarkup, /&lt;Long election title&gt;/);
  assert.match(css, /\.cv-opt-text[^}]*flex-direction: column/);
  assert.match(css, /\.cv-opt-title[^}]*overflow-wrap: anywhere/);
  assert.doesNotMatch(css, /width: max-content/);
  picker.window.location.pathname = '/CoopVote/voter/candidates.html';
  pickerMarkup = '';
  await picker.initElectionPicker();
  assert.match(pickerMarkup, /cv-election-picker|cv-picker-btn/, 'View Candidates receives the same picker');
  events.click({ target: { closest: () => ({ dataset: { id: '2' } }) } });
  assert.equal(selectedId, '2', 'Candidate picker remembers election for the ballot and API calls');
  assert.equal(reloads, 1, 'Selection reloads the candidate list');
  pickerMarkup = '';
  await picker.initElectionPicker();
  assert.match(pickerMarkup, /data-id="2" class="sel" aria-selected="true"/, 'Selected election is restored');
  events.click({ target: { closest: () => ({ dataset: { id: '2' } }) } });
  assert.equal(reloads, 1, 'Selecting the current election does not reload');
  picker.window.location.pathname = '/CoopVote/voter/election.html';
  pickerMarkup = '';
  await picker.initElectionPicker();
  assert.match(pickerMarkup, /data-id="2" class="sel"/, 'Active Election preserves the same selected election');
  picker.api = async () => ({ selectedId: 7, elections: [{ id: 7, title: 'Only election', live: true }] });
  await picker.initElectionPicker();
  assert.equal(selectedId, '7', 'Unavailable saved election falls back before page data loads');
  assert.match(pickerMarkup, /Only election/, 'Single election still has a visible list');
  picker.api = async () => ({ elections: [{ id: 9, title: 'Closed election', status: 'Closed' }] });
  await picker.initElectionPicker();
  assert.equal(selectedId, '');
  assert.match(pickerMarkup, /No available elections/);
  assert.doesNotMatch(pickerMarkup, /Closed election/);
  picker.window.location.pathname = '/CoopVote/voter/profile.html';
  pickerMarkup = '';
  await picker.initElectionPicker();
  assert.equal(pickerMarkup, '', 'Other pages do not receive extra controls');
  console.log('Election selector checks passed: two live countdowns, selection persistence, correct actions, closed fallback, one timer, long titles and separate Voted labels.');
}).catch(error => { console.error(error); process.exitCode = 1; });

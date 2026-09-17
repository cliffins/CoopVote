/* Run with Node. Exercises the real form script with sample data and no network. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

class Field {
  constructor(isSelect = false) {
    this.isSelect = isSelect;
    this.options = [''];
    this.current = '';
    this.disabled = false;
    this.classList = { add() {}, remove() {} };
  }
  set value(value) { this.current = this.isSelect && !this.options.includes(String(value)) ? '' : String(value); }
  get value() { return this.current; }
  set innerHTML(html) {
    this.html = html;
    if (this.isSelect) {
      this.options = [...html.matchAll(/<option value="([^"]*)"/g)].map(m => m[1]);
      this.current = this.options[0] || '';
    }
  }
  get innerHTML() { return this.html || ''; }
  focus() {}
}

const fields = new Map();
const field = id => {
  if (!fields.has(id)) fields.set(id, new Field(['f-election', 'f-position', 'f-member'].includes(id)));
  return fields.get(id);
};
const fixtures = {
  'candidates/eligible': [
    { id:1, name:'Sample Member', memberNo:'DEMO-001', first:'Sample', last:'Member', age:35, occ:'Cooperative Member', years:0 },
    { id:2, name:'Sample Member', memberNo:'DEMO-002', first:'Sample', last:'Member', age:40, occ:'Teacher', years:5 }
  ],
  elections: [{ id: 1, title: 'Assembly A' }, { id: 2, title: 'Assembly B' }, { id: 3, title: 'Empty Election' }],
  'positions&all=1': [
    { position_id: 11, election_id: 1, position_name: 'Chairperson' },
    { position_id: 12, election_id: 1, position_name: 'Treasurer & Auditor' },
    { position_id: 21, election_id: 2, position_name: 'Chairperson' }
  ]
};
const notices = [];
const sampleApi = async route => route.startsWith('positions&election=')
  ? fixtures['positions&all=1'].filter(p => String(p.election_id) === route.split('=')[1])
  : fixtures[route];
const context = vm.createContext({
  document: { getElementById: field }, window: { addEventListener() {} },
  CoopVoteValidation: { validate: () => true }, // Validation has its own focused suite.
  CoopVote: { api: sampleApi, toast: message => notices.push(message) },
  esc: value => String(value).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
});
const html = fs.readFileSync(path.join(__dirname, '../admin/candidate-management.html'), 'utf8');
for (const script of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
  if (!/\bsrc\s*=/.test(script[1])) vm.runInContext(script[2], context);
}
context.showToast = message => notices.push(message);
context.closeModal = context.loadCandidates = () => {};
const chooseElection = id => { field('f-election').value = id; return context.loadCandidatePositions(); };

(async () => {
  await context.loadCandidateOptions();
  assert.equal(field('f-member').value, '', 'A voter is never selected automatically');
  assert.deepEqual(field('f-member').options, ['', '1', '2']);
  field('f-member-search').value = 'DEMO-002';
  context.filterCandidateMembers();
  assert.deepEqual(field('f-member').options, ['', '2'], 'Member ID distinguishes voters with the same name');
  field('f-member-search').value = '';
  context.filterCandidateMembers();
  assert.equal(field('f-election').value, '', 'No election is selected automatically');
  assert.equal(field('f-position').disabled, true, 'Position stays disabled before selecting an election');
  assert.equal(field('save-candidate-btn').disabled, true, 'Save stays disabled before choosing a position');

  await chooseElection(1);
  assert.deepEqual(field('f-position').options, ['', '11', '12'], 'Only Assembly A positions appear');
  assert.ok(field('f-position').innerHTML.includes('Treasurer &amp; Auditor'), 'Position labels are escaped');
  field('f-position').value = 11;
  context.updateCandidateSaveState();
  assert.equal(field('save-candidate-btn').disabled, true, 'Election and position alone cannot save');
  field('f-member').value = '1';
  await context.selectCandidateMember();
  assert.equal(field('f-first').value, 'Sample');
  assert.equal(field('f-years').value, '0', 'Zero membership years is preserved');
  assert.equal(field('save-candidate-btn').disabled, false);

  await chooseElection(2);
  assert.deepEqual(field('f-position').options, ['', '21'], 'Assembly B gets its own same-named position');
  assert.equal(field('f-position').value, '', 'Switching elections clears the old position');
  assert.equal(field('save-candidate-btn').disabled, true);
  field('f-position').value = 21;
  field('f-first').value = 'Sample';
  field('f-last').value = 'Member';
  let saved;
  context.CoopVote.api = async (route, options) => { saved = { route, options }; return {ok:true}; };
  await context.saveCandidate();
  assert.equal(saved.options.body.electionId, '2', 'The selected election is sent to the API');
  assert.equal(saved.options.body.positionId, '21');
  assert.equal(saved.options.body.memberId, '1', 'The stable member ID is sent to the API');
  assert.equal('first' in saved.options.body, false, 'Nomination does not identify a person by typed names');

  context.CoopVote.api = sampleApi;
  await chooseElection(3);
  assert.equal(field('f-position').disabled, true);
  assert.equal(field('save-candidate-btn').disabled, true);
  assert.match(field('position-hint').textContent, /Add positions to this election/);
  await chooseElection('');
  assert.deepEqual(field('f-position').options, ['']);

  context.CoopVote.api = sampleApi;
  field('edit-id').value = 7;
  await context.loadCandidateOptions(2, 21, 1);
  assert.equal(field('f-member').value, '1');
  assert.equal(field('f-member').disabled, true, 'Editing locks the original member');
  assert.equal(field('f-election').value, '2', 'Editing loads the existing election');
  assert.equal(field('f-election').disabled, true, 'Editing retains the existing election');
  assert.equal(field('f-position').value, '21', 'Editing loads its existing position');
  assert.deepEqual(field('f-position').options, ['', '21']);
  context.clearForm();
  await context.loadCandidateOptions();
  assert.equal(field('f-election').disabled, false, 'Add is unlocked after editing');
  assert.equal(field('f-election').value, '');

  context.CoopVote.api = async () => { throw new Error('Sample connection failure'); };
  await context.loadCandidateOptions();
  assert.equal(field('f-election').disabled, true);
  assert.equal(field('f-position').disabled, true);
  assert.equal(field('save-candidate-btn').disabled, true);
  assert.match(field('election-hint').textContent, /try again/);

  const pending = [];
  context.CoopVote.api = route => route.startsWith('positions&election=') ? sampleApi(route) : new Promise(resolve => pending.push({ route, resolve }));
  const oldLoad = context.loadCandidateOptions(1, 11);
  const newLoad = context.loadCandidateOptions(2, 21);
  pending.slice(2).forEach(p => p.resolve(fixtures[p.route]));
  await newLoad;
  pending.slice(0, 2).forEach(p => p.resolve(fixtures[p.route]));
  await oldLoad;
  assert.equal(field('f-election').value, '2', 'An older request cannot overwrite the newer form');
  assert.equal(field('f-position').value, '21');
  context.CoopVote.api = sampleApi;
  const positionRequests = [];
  context.CoopVote.api = route => new Promise(resolve => positionRequests.push({route, resolve}));
  const firstPositions = chooseElection(1);
  const secondPositions = chooseElection(2);
  assert.equal(field('save-candidate-btn').disabled, true, 'Save is locked while positions load');
  positionRequests[1].resolve(await sampleApi(positionRequests[1].route));
  await secondPositions;
  positionRequests[0].resolve(await sampleApi(positionRequests[0].route));
  await firstPositions;
  assert.deepEqual(field('f-position').options, ['', '21'], 'Late positions cannot replace the selected election');
  context.CoopVote.api = async () => { throw new Error('Positions unavailable'); };
  await chooseElection(1);
  assert.equal(field('f-position').disabled, true);
  assert.equal(field('save-candidate-btn').disabled, true);
  context.CoopVote.api = sampleApi;
  await chooseElection(1);
  assert.deepEqual(field('f-position').options, ['', '11', '12'], 'Position loading can recover after failure');
  context.clearForm();
  await context.loadCandidateOptions(2, 21, 1);
  let resolveSave, saveCalls = 0;
  context.CoopVote.api = () => { saveCalls++; return new Promise(resolve => { resolveSave = resolve; }); };
  const nomination = context.saveCandidate();
  await context.saveCandidate();
  assert.equal(saveCalls, 1, 'Repeated clicks send only one nomination');
  assert.equal(field('save-candidate-btn').disabled, true);
  resolveSave({ok:true}); await nomination;
  assert.equal(field('save-candidate-btn').disabled, false);

  // A nomination in one election must not disable nomination in another.
  fixtures['candidates/eligible'][0].candidateElectionIds = [1];
  fixtures['candidates/eligible'][1].candidateElectionIds = [2];
  context.CoopVote.api = sampleApi;
  context.clearForm();
  await context.loadCandidateOptions();
  await chooseElection(1);
  field('f-position').value = '11';
  field('f-member').value = '1';
  await context.selectCandidateMember();
  assert.deepEqual(field('f-election').options, ['', '2', '3'], 'Existing nomination hides only that election');
  assert.equal(field('f-election').value, '', 'Changing voter clears an election that is no longer available');
  assert.equal(field('f-position').value, '');
  await chooseElection(2);
  field('f-position').value = '21';
  context.updateCandidateSaveState();
  assert.equal(field('save-candidate-btn').disabled, false, 'Existing candidate can save in a different election');
  context.CoopVote.api = async (route, options) => { saved = { route, options }; return {ok:true}; };
  await context.saveCandidate();
  assert.equal(saved.route, 'candidates');
  assert.equal(saved.options.body.memberId, '1');
  assert.equal(saved.options.body.electionId, '2');
  field('f-member').value = '2';
  await context.selectCandidateMember();
  assert.deepEqual(field('f-election').options, ['', '1', '3'], 'Member change recomputes exclusions');
  assert.equal(field('f-election').value, '');
  assert.equal(field('save-candidate-btn').disabled, true);
  fixtures['candidates/eligible'][1].candidateElectionIds = [1, 2, 3];
  await context.selectCandidateMember();
  assert.deepEqual(field('f-election').options, ['']);
  assert.equal(field('f-election').disabled, true);
  assert.match(field('election-hint').textContent, /already a candidate in every election/);
  field('f-member').value = '1';
  await context.selectCandidateMember();
  assert.equal(field('f-election').disabled, false, 'Choosing another member unlocks available elections');

  vm.runInContext('allCandidates = [{id:7, memberId:1, electionId:1, positionId:11}]', context);
  field('edit-id').value = 7;
  context.CoopVote.api = sampleApi;
  await context.loadCandidateOptions(1, 11, 1);
  assert.deepEqual(field('f-election').options, ['', '1'], 'Edit retains the original nominated election');
  assert.equal(field('save-candidate-btn').disabled, false, 'Existing candidacy remains editable');
  context.clearForm();
  await context.loadCandidateOptions(2, 21, 1);
  assert.equal(field('f-election').disabled, false, 'Add unlocks election after editing');
  assert.equal(field('save-candidate-btn').disabled, false, 'Save is enabled after Edit to Add with valid selections');

  context.CoopVote.api = async route => route === 'candidates/eligible' ? [] : sampleApi(route);
  await context.loadCandidateOptions();
  assert.equal(field('f-member').disabled, true, 'No eligible voters leaves the selector disabled');
  assert.equal(field('save-candidate-btn').disabled, true);
  assert.match(field('member-hint').textContent, /Voter Management/);
  vm.runInContext('allCandidates = [{id:7, memberId:99, memberNo:"DEMO-099", first:"Former", last:"Member", electionId:2, positionId:21}]', context);
  field('edit-id').value = 7;
  await context.loadCandidateOptions(2, 21, 99);
  assert.equal(field('f-member').value, '99', 'Existing candidacy retains an inactive member missing from the eligible list');
  assert.equal(field('f-member').disabled, true, 'Historical member selection remains locked');
  console.log('Candidate form checks passed: selection, filtering, reset, edit, submission, empty election, errors and request ordering.');
})().catch(error => { console.error(error); process.exitCode = 1; });

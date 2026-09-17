const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = page => fs.readFileSync(path.join(root, page), 'utf8');
const admin = read('admin/dashboard.html');
const voter = read('voter/dashboard.html');
for (const html of [admin, voter]) {
  for (const match of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)) new vm.Script(match[1]);
}
const trend = { hidden: true, textContent: '' };
const trendContext = vm.createContext({ document: { getElementById: () => trend } });
vm.runInContext(admin.match(/function setTrend\([\s\S]*?\n    }/)[0], trendContext);
for (const value of [undefined, 0, NaN]) {
  trendContext.setTrend('test', value);
  assert.equal(trend.hidden, true);
  assert.equal(trend.textContent, '');
}
trendContext.setTrend('test', 3);
assert.equal(trend.hidden, false);
assert.equal(trend.textContent, '+3%');
trendContext.setTrend('test', undefined);
assert.equal(trend.hidden, true);

const elements = { 'results-body': {}, 'results-sub': {} };
const content = { scrollTop: 127 };
let data = { results: [{ rank: 1, name: '<Candidate>', position: 'President', votes: 4 }], resultsElection: { id: 2, title: 'Completed election' } };
let fail = false, renders = 0, selection = '1', changeSelection = false;
const context = vm.createContext({
  document: { getElementById: id => elements[id], querySelector: () => content },
  CoopVote: {
    selectedElection: () => selection,
    api: async () => { if (fail) throw new Error('Offline'); if (changeSelection) selection = '3'; return data; }
  },
  PC: {}, esc: value => value.replaceAll('<', '&lt;').replaceAll('>', '&gt;'),
  renderDashboard: value => { renders++; context.renderResults(value.results, value.resultsElection); content.scrollTop = 0; }
});
vm.runInContext(voter.match(/function renderResults\([\s\S]*?\n    }/)[0], context);
vm.runInContext('let lastDashboard = "";\n' + voter.match(/async function loadDashboard\([\s\S]*?\n    }/)[0], context);
(async () => {
  await context.loadDashboard();
  assert.equal(elements['results-sub'].textContent, 'Completed election');
  assert.match(elements['results-body'].innerHTML, /&lt;Candidate&gt;/);
  assert.equal(content.scrollTop, 127);
  await context.loadDashboard();
  assert.equal(renders, 1);
  fail = true;
  await assert.rejects(context.loadDashboard(), /Offline/);
  assert.equal(renders, 1);
  assert.equal(elements['results-sub'].textContent, 'Completed election');
  fail = false;
  data = { results: null, resultsElection: null };
  await context.loadDashboard();
  assert.match(elements['results-body'].innerHTML, /for elections you voted in/);
  assert.doesNotMatch(elements['results-body'].innerHTML, /Candidate/);
  changeSelection = true;
  data = { results: [], resultsElection: { title: 'Stale response' } };
  await context.loadDashboard();
  assert.equal(renders, 2);
  assert.match(voter, /CoopVote\.watch\(loadDashboard, 10000\)/);
  console.log('Dashboard checks passed: script syntax, empty trends, published rendering, escaping, refresh, withdrawal, failed refresh, scroll preservation, stale selection.');
})().catch(error => { console.error(error); process.exitCode = 1; });

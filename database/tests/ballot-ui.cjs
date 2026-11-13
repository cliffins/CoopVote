// Exercise the actual inline ballot script with a minimal DOM adapter.
// Run: node database/tests/ballot-ui.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const html = fs.readFileSync(path.join(__dirname, '../../voter/ballot.php'), 'utf8');
const script = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)]
  .map(match => match[1]).find(source => source.includes('function selectCandidate'));
const elements = new Map();
function element(id) {
  if (!elements.has(id)) elements.set(id, {
    textContent: '', innerHTML: '', style: {}, disabled: true, attributes: {},
    classList: { values: new Set(), add(value) { this.values.add(value); },
      remove(value) { this.values.delete(value); }, contains(value) { return this.values.has(value); } },
    setAttribute(key, value) { this.attributes[key] = value; },
  });
  return elements.get(id);
}
let request;
const context = vm.createContext({
  document: { getElementById: element },
  window: { addEventListener() {} },
  esc: value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;'),
  CoopVote: { api(route, options) { request = { route, ...options }; return new Promise(() => {}); } },
});
vm.runInContext(script, context);
const run = source => vm.runInContext(source, context);
run(`initBallot({election:{id:7,title:'Test Election'}, member:{name:'Test Voter'}, positions:[
  {id:10,name:'President',candidates:[{id:100,first:'Ana',last:'Cruz'}]},
  {id:20,name:'Treasurer',candidates:[{id:200,first:'Ben',last:'Reyes'}]},
  {id:30,name:'Vacant',candidates:[]}
]})`);
assert.equal((element('main-content').innerHTML.match(/>Abstain</g) || []).length, 2);
run("selectCandidate('President',100,0)");
assert.equal(element('rv-0').textContent, 'Ana Cruz');
assert.equal(element('btn-submit').disabled, true);
run("selectCandidate('President',null,0)");
assert.equal(element('choice-0-100').attributes['aria-pressed'], 'false');
assert.equal(element('choice-0-null').attributes['aria-pressed'], 'true');
assert.equal(element('rv-0').textContent, 'Abstain');
run("selectCandidate('President',100,0)");
assert.equal(element('choice-0-null').classList.contains('selected'), false);
assert.equal(element('rv-0').textContent, 'Ana Cruz');
run("selectCandidate('Treasurer',null,1); openConfirm()");
assert.equal(element('btn-submit').disabled, false);
assert.match(element('confirm-list').innerHTML, /Ana Cruz/);
assert.match(element('confirm-list').innerHTML, /Abstain/);
run("selectCandidate('President',null,0); openConfirm()");
assert.equal(element('rv-pct').textContent, '100%');
assert.equal((element('confirm-list').innerHTML.match(/>Abstain</g) || []).length, 2);
assert.doesNotMatch(element('confirm-list').innerHTML, /Ana Cruz/);
run('submitVote()');
assert.equal(request.route, 'voter/vote');
assert.deepEqual(JSON.parse(JSON.stringify(request.body)), {electionId:7, votes:[
  {positionId:10,position:'President',candidateId:null,abstain:true},
  {positionId:20,position:'Treasurer',candidateId:null,abstain:true},
]});
console.log('Ballot UI: PASS (choice switching, progress, confirmation, all-abstain payload, empty-position handling)');

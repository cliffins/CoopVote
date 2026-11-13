const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const path = require('path');
const source = fs.readFileSync(process.argv[2] || path.join(__dirname, '../../voter/results.php'), 'utf8');
const script = [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]).find(s => s.includes('let resultsData'));
assert(script, 'Results script exists');
const elements = new Map();
const document = {getElementById(id) {
  if (!elements.has(id)) elements.set(id, {innerHTML:'',textContent:'',style:{}});
  return elements.get(id);
}};
const context = vm.createContext({CoopVote:{user:null}, document, window:{addEventListener(){}}, setTimeout(){},
  esc(value){return String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}});
vm.runInContext(script, context);
const election = (id, receiptCode, hasVoted=true) => ({id, title:'Election '+id, status:'Final', published:true, hasVoted, receiptCode, totalVoters:10, votesCast:5, positions:[]});
context.data = {member:{name:'Test Voter',memberId:'TEST',hasVoted:false,receiptCode:'CV-WRONG-GLOBAL'}, elections:[
 election(1,'CV-FIRST'),election(2,'CV-SECOND'),election(3,null),election(4,'CV-NOT-CAST',false),election(5,'<img src=x onerror=alert(1)>')
]};
vm.runInContext('initResults(data)', context);
const html=()=>elements.get('main-content').innerHTML;
assert(html().includes('Receipt: CV-FIRST'), 'Selected election receipt must show even if global hasVoted is false');
assert(!html().includes('CV-WRONG-GLOBAL'));
vm.runInContext('switchElection("1")',context);
assert(html().includes('Receipt: CV-SECOND') && !html().includes('CV-FIRST'));
assert.equal(elements.get('header-sub').textContent,'Election 2');
vm.runInContext('switchElection("0")',context);
assert(html().includes('Receipt: CV-FIRST') && !html().includes('CV-SECOND'));
vm.runInContext('switchElection("2")',context);
assert(!html().includes('Receipt:') && !html().includes('CV-WRONG-GLOBAL'));
vm.runInContext('switchElection("3")',context);
assert(!html().includes('my-vote-banner') && !html().includes('CV-NOT-CAST'));
vm.runInContext('switchElection("4")',context);
assert(html().includes('&lt;img') && !html().includes('<img src=x'));
console.log('Receipt rendering: 9 checks PASS (election switching, global-state mismatch, missing receipt, nonparticipant, escaping)');

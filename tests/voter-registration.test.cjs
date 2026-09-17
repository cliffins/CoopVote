const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const nodes = Object.fromEntries(['add-voter-btn','save-voter-btn','edit-id','voter-registration-status'].map(id=>[id,{value:'',disabled:false,textContent:''}]));
const notices = [];
let calls = 0, resolve;
const context = vm.createContext({
  document:{getElementById:id=>nodes[id],addEventListener(){}},
  window:{addEventListener(){}},setInterval:()=>1,clearInterval(){},
  CoopVote:{api:()=>{calls++;return new Promise(r=>resolve=r);},toast:message=>notices.push(message)}
});
vm.runInContext(fs.readFileSync('assets/js/voter-registration.js','utf8'),context);
const registration = context.window.CoopVoteVoterRegistration;
(async()=>{
  registration.sync();
  assert.equal(nodes['add-voter-btn'].disabled,true,'Add starts disabled until the server is checked');
  const initial = registration.refresh();
  assert.equal(registration.refresh(),initial,'Concurrent refreshes share one status request');
  assert.equal(calls,1);
  resolve({allowed:false,reason:'An election is active.'});await initial;
  assert.equal(nodes['add-voter-btn'].disabled,true);
  assert.equal(nodes['save-voter-btn'].disabled,true,'An open add form is locked too');
  assert.match(nodes['voter-registration-status'].textContent,/active/);
  nodes['edit-id'].value='1';registration.sync();
  assert.equal(nodes['save-voter-btn'].disabled,false,'Editing remains available');
  context.CoopVote.api=async()=>({allowed:true,reason:''});
  assert.equal(await registration.check(),true);
  assert.equal(nodes['add-voter-btn'].disabled,false,'Closing voting unlocks Add');
  assert.equal(nodes['voter-registration-status'].textContent,'');
  nodes['edit-id'].value='';
  context.CoopVote.api=async()=>({allowed:false,reason:'Voting has started.'});
  assert.equal(await registration.check(),false,'Opening and submitting recheck the server');
  assert.equal(notices.at(-1),'Voting has started.');
  assert.equal(nodes['save-voter-btn'].disabled,true);
  context.CoopVote.api=async()=>{throw Error('Offline');};await registration.refresh();
  assert.equal(nodes['add-voter-btn'].disabled,true,'Unknown state cannot enable voter creation');
  assert.match(nodes['voter-registration-status'].textContent,/Reconnect/);
  context.CoopVote.api=async()=>({allowed:true});await registration.refresh();
  assert.equal(nodes['add-voter-btn'].disabled,false,'Registration recovers after reconnecting');
  console.log('Voter registration controls passed: active, closed, edit, stale form, concurrent refresh and offline recovery.');
})().catch(error=>{console.error(error);process.exitCode=1;});

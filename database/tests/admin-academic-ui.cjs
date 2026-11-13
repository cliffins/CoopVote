'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function page(name) {
  const html = fs.readFileSync(path.join(__dirname, '../../admin/', name), 'utf8');
  const script = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]).find(s => s.includes('function clearForm()'));
  assert.ok(script);
  const elements = new Map();
  function element(id) {
    if (!elements.has(id)) {
      let value = '';
      elements.set(id, {get value(){return value;}, set value(v){value=String(v);},
        innerHTML:'', textContent:'', style:{}, disabled:false, required:false,
        classList:{add(){},remove(){},toggle(){}}, focus(){}, setAttribute(){},
      });
    }
    return elements.get(id);
  }
  const requests = [];
  const context = vm.createContext({
    document:{getElementById:element}, window:{addEventListener(){}},
    esc: value => String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;'),
    CoopVoteValidation:{validate:()=>true},
    CoopVoteVoterRegistration:{check:async()=>true,sync(){},refresh(){}},
    CoopVote:{api:async(route,options)=>{requests.push({route,...options});return {id:7};}},
  });
  const run = source => vm.runInContext(source, context);
  run(script);
  run('showToast=()=>{};');
  return {html, element, requests, run};
}

(async () => {
  const c = page('candidate-management.php');
  for (const [field,limit] of [['section',100],['year',32]]) {
    assert.match(c.html,new RegExp('id="f-'+field+'"[^>]*maxlength="'+limit+'"[^>]*required'));
  }
  assert.doesNotMatch(c.html,/f-department|f-course|f-occ|f-years|Occupation|Years as Member/);
  c.run(`loadCandidateOptions=()=>{}; updateCandidateSaveState=()=>{};
    updateCandidateElectionOptions=()=>{}; updatePositionOptions=()=>{}; loadCandidates=()=>{};
    allCandidates=[{id:9,memberId:2,electionId:1,positionId:3,first:'Test',last:'Candidate',age:21,
      section:'A',year:'2nd Year',department:'Computing',course:'BSIT',status:'Active'}];
    availableMembers=[{id:2,first:'Test',last:'Candidate',age:21}];
    availablePositions=[{position_id:3,election_id:1}];`);
  c.element('election-filter').value='1';
  c.run('openEdit(9)');
  c.element('f-member').value='2';
  c.element('f-election').value='1';
  c.element('f-position').value='3';
  c.run('selectCandidateMember()');
  assert.equal(c.element('f-year').value,'2nd Year','loading member identity must not overwrite academic details');
  c.element('f-section').value='B';
  await c.run('saveCandidate()');
  assert.equal(c.requests[0].body.section,'B');
  assert.equal(c.requests[0].body.year,'2nd Year');
  assert.ok(!('department' in c.requests[0].body) && !('course' in c.requests[0].body));
  assert.ok(!('occ' in c.requests[0].body) && !('years' in c.requests[0].body));
  c.run('viewCandidate(9)');
  assert.match(c.element('view-body').innerHTML,/Section/);
  assert.doesNotMatch(c.element('view-body').innerHTML,/Department|Course/);
  c.run('clearForm()');
  for (const field of ['section','year']) assert.equal(c.element('f-'+field).value,'');

  const v = page('voter-management.php');
  v.run('loadVoters=()=>{}; showCredentials=()=>{};');
  await v.run('openAdd()');
  assert.equal(v.element('row-voter-contact-status').style.display,'none');
  assert.equal(v.element('f-phone').disabled,true);
  assert.equal(v.element('f-phone').required,false);
  for (const [field,value] of Object.entries({first:'Test',last:'Voter',email:'voter@example.test',address:'Test address',dob:'2000-01-01'})) v.element('f-'+field).value=value;
  await v.run('saveVoter()');
  assert.equal(v.requests[0].route,'voters');
  assert.ok(!('phone' in v.requests[0].body),'Add Voter does not submit phone');
  v.run(`allVoters=[{id:7,first:'Test',last:'Voter',email:'voter@example.test',memberId:'TEST-7',
    dobInput:'2000-01-01',address:'Test address',phone:'09171234567',status:'Active'}]; openEdit(7);`);
  assert.equal(v.element('row-voter-contact-status').style.display,'');
  assert.equal(v.element('f-phone').disabled,false);
  assert.equal(v.element('f-phone').value,'09171234567');
  await v.run('saveVoter()');
  assert.equal(v.requests[1].body.phone,'09171234567','Edit preserves the existing phone');
  await v.run('openAdd()');
  assert.equal(v.element('f-phone').value,'','switching to Add clears old contact information');
  console.log('Admin form checks PASS (academic fields, edit/member selection, save payload, reset, add without phone, edit contact preservation)');
})().catch(error=>{console.error(error);process.exitCode=1;});

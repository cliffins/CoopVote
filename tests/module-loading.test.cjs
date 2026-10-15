const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('assets/js/app.js', 'utf8');
const slice = source.slice(source.indexOf('  const pendingReads ='), source.indexOf('  async function request('));
const requests = [];
let election = 1;
const context = vm.createContext({
  url: route => route + '&election=' + election,
  request: (route, options) => new Promise((resolve, reject) => requests.push({route, options, resolve, reject})),
});
vm.runInContext(slice, context);
(async () => {
  const a = context.api('voter/elections'), b = context.api('voter/elections');
  assert.equal(requests.length, 1, 'Sidebar and picker share their in-flight election list');
  requests[0].resolve({elections:[{id:1}]});
  const [first, second] = await Promise.all([a,b]);
  first.elections[0].id = 99;
  assert.equal(second.elections[0].id, 1, 'Consumers do not share mutable payloads');
  const fresh = context.api('voter/elections');
  assert.equal(requests.length, 2, 'Completed responses are never cached');
  requests[1].reject(Error('offline'));
  await assert.rejects(fresh, /offline/);
  const retry = context.api('voter/elections');
  requests[2].resolve({elections:[]}); await retry;
  const old = context.api('voter/ballot');
  election = 2;
  const next = context.api('voter/ballot');
  assert.equal(requests.length, 5, 'Different elections never share a response');
  requests[3].resolve({id:1}); requests[4].resolve({id:2}); await Promise.all([old,next]);
  const beforeWrite = context.api('voters');
  const write = context.api('voters', {method:'POST', body:{}});
  requests[6].resolve({ok:true}); await write;
  const afterWrite = context.api('voters');
  requests[5].resolve({old:true}); await beforeWrite;
  const sharedNew = context.api('voters');
  assert.equal(requests.length, 8, 'Writes invalidate in-flight reads; old cleanup cannot erase the new request');
  requests[7].resolve({fresh:true}); await Promise.all([afterWrite,sharedNew]);
  const customA = context.api('voters', {signal:{}}), customB = context.api('voters', {signal:{}});
  assert.equal(requests.length, 10, 'Abortable/custom requests remain independent');
  requests[8].resolve({}); requests[9].resolve({}); await Promise.all([customA,customB]);

  const events = [], timers = [];
  const guardContext = vm.createContext({
    guardedRole:null, loggingOut:false,
    window:{CoopVote:{}, CoopVoteAppearance:{sync:(user,prefs)=>assert.equal(prefs.theme,'forest')}, location:{}},
    api:async()=>({user:{id:1,role:'admin'},preferences:{theme:'forest'}}),
    applyIdentity(){}, applyCoopName(){}, initElectionPicker:async()=>{},
    initNotifications:()=>events.push('notifications'),
    setTimeout:fn=>timers.push(fn), redirectToLogin:()=>assert.fail('Unexpected redirect'),
  });
  vm.runInContext(source.slice(source.indexOf('  async function guard('), source.indexOf('  /** Fill the sidebar')), guardContext);
  await guardContext.guard('admin').then(()=>events.push('module'));
  timers.shift()();
  assert.deepEqual(events,['module','notifications'], 'Module request starts before background notifications');
  await guardContext.guard('admin'); guardContext.loggingOut=true; timers.shift()();
  assert.equal(events.length,2,'Logout suppresses deferred notifications');
  console.log('Module loading passed: request sharing, fresh reads, election isolation, retries, write invalidation, custom signals and foreground priority.');
})().catch(error=>{console.error(error);process.exitCode=1;});

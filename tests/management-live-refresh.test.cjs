const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('assets/js/management.js','utf8');
function extract(name){return source.match(new RegExp('(?:async )?function '+name+'\\([^]*?\\n  }'))[0];}
function setup(tab='officers'){
  const state={tab,dirty:false,editing:false,dialog:false,fail:false,reply:[],calls:[],renders:[],errors:[]};
  const main={scrollTop:180,scrollLeft:0},table={scrollTop:0,scrollLeft:45};
  const nodes={threshold:{value:'2'},'request-form':null};
  const doc={hidden:false,scrollingElement:main,activeElement:{matches:()=>state.editing},
    querySelector:()=>state.dialog,querySelectorAll:()=>[table]};
  const request=async route=>{state.calls.push(route);if(state.fail)throw Error('Connection interrupted');return state.pending?await state.pending:state.reply;};
  const render=kind=>{state.renders.push(kind);main.scrollTop=0;table.scrollLeft=0;};
  const ctx=vm.createContext({document:doc,$:id=>nodes[id],active:()=>state.tab,
    brandingHasChanges:()=>state.dirty,api:request,CoopVote:{api:request},
    CoopVoteAppearance:{remember:(user,theme)=>{state.theme=theme;}},user:{id:1},
    window:{CoopVoteBranding:{apply:data=>{state.brand=data;}}},
    renderAccounts:()=>render('officers'),renderThemes:()=>render('themes'),
    renderApprovals:()=>render('approvals'),loadBranding:()=>render('branding'),error:m=>state.errors.push(m)});
  vm.runInContext('let mutation=false,imageLoading=false,refreshId=0,pendingReads=0,accounts=[],overview=null;const renderedData=new Map();',ctx);
  for(const name of ['deferBackgroundRefresh','refresh'])vm.runInContext(extract(name),ctx);
  return {state,ctx,doc,nodes,main,table,live:()=>ctx.refresh({background:true})};
}
(async()=>{
  for(const [tab,route,reply] of [
    ['officers','accounts',[{id:1,name:'Maria'}]],
    ['approvals','overview',{requiredApprovals:2,elections:[]}],
    ['themes','preferences',{theme:'forest'}],
    ['branding','branding',{text:'CoopVote',coopName:'Test Cooperative',logo:null}]
  ]){
    const t=setup(tab);t.state.reply=reply;
    assert.equal(await t.live(),true);assert.equal(t.state.calls[0],route);
    assert.deepEqual(t.state.renders,[tab]);
    assert.equal(t.main.scrollTop,180);assert.equal(t.table.scrollLeft,45);
    await t.live();assert.equal(t.state.renders.length,1,'unchanged data keeps existing controls and focus');
    if(tab==='themes')assert.equal(t.state.theme,'forest');
    if(tab==='branding')assert.equal(t.state.brand.coopName,'Test Cooperative');
  }
  {
    const t=setup();t.state.reply=[{id:1}];await t.live();
    t.state.reply=[{id:1},{id:2}];await t.live();assert.equal(t.state.renders.length,2,'new officer data renders');
    t.state.fail=true;await assert.rejects(t.live(),/Connection interrupted/);
    assert.equal(t.state.renders.length,2,'network failures retain existing rows');
    assert.match(t.state.errors.at(-1),/Connection interrupted/);
    assert.equal(vm.runInContext('pendingReads',t.ctx),0);
    t.state.fail=false;await t.live();assert.equal(t.state.errors.at(-1),'','recovery clears the error without rebuilding unchanged rows');
  }
  for(const guard of ['editing','dialog','hidden','mutation','dirty','imageLoading','threshold','proposedEnd']){
    const t=setup(['dirty','imageLoading'].includes(guard)?'branding':['threshold','proposedEnd'].includes(guard)?'approvals':'officers');
    if(['editing','dialog','dirty'].includes(guard))t.state[guard]=true;
    else if(guard==='hidden')t.doc.hidden=true;
    else if(guard==='threshold'){vm.runInContext('overview={requiredApprovals:1}',t.ctx);}
    else if(guard==='proposedEnd')t.nodes['request-form']={elements:{proposedEnd:{value:'2026-11-01T12:00'}}};
    else vm.runInContext(guard+'=true',t.ctx);
    assert.equal(await t.live(),false,guard+' pauses polling');assert.equal(t.state.calls.length,0);
  }
  // A user can start editing, open a dialog, switch modules or save while the API is slow.
  for(const action of ['editing','dialog','dirty','tab','mutation']){
    const t=setup(action==='dirty'?'branding':'officers');
    let resolve;t.state.pending=new Promise(r=>resolve=r);
    const pending=t.live();await t.live();assert.equal(t.state.calls.length,1,'in-flight reads do not overlap');
    if(action==='tab')t.state.tab='themes';
    else if(action==='mutation')vm.runInContext('mutation=true;refreshId++',t.ctx);
    else t.state[action]=true;
    resolve([]);await pending;assert.equal(t.state.renders.length,0,action+' protects against a late response');
  }
  {
    const t=setup('branding');t.state.dirty=true;await t.live();
    t.state.dirty=false;t.state.reply={text:'Saved elsewhere',coopName:'Cooperative',logo:null};
    await t.live();assert.equal(t.state.brand.text,'Saved elsewhere','polling resumes when edits are cleared');
  }
  assert.equal((source.match(/CoopVote\.watch\(/g)||[]).length,1,'one shared lifecycle-aware scheduler per page');
  assert.match(source,/await route\(\);\s*CoopVote\.watch\(\(\)=>refresh\(\{background:true\}\),10000\)/,'polling begins after initial load and uses the shared ten-second interval');
  assert.doesNotMatch(extract('refresh'),/toast\(/,'background refresh never generates a success toast');
  console.log('Management live refresh passed: four modules, unchanged data, scroll, drafts, dialogs, stale responses, overlapping requests and recovery.');
})().catch(e=>{console.error(e);process.exitCode=1;});

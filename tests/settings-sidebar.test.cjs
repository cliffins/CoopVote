const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('assets/js/settings-sidebar.js','utf8');
const html=fs.readFileSync('management.html','utf8');
assert.doesNotMatch(html,/class="settings-nav"|id="tabs"|Back to dashboard/,'settings do not have a separate navigation menu');
assert.match(html,/class="sidebar"[^>]+data-shared-sidebar/);
assert.match(html,/aria-controls="sidebar"/);
for(const role of ['admin','member']){
  const links=[];
  const sidebar={dataset:{},innerHTML:'',querySelectorAll(){return [...this.innerHTML.matchAll(/href="([^"]+)"/g)].map(m=>{const a={getAttribute:()=>m[1],set href(v){links.push(v);}};return a;});}};
  const context=vm.createContext({URL,window:{},document:{currentScript:{src:'https://coop.test/nested/CoopVote/assets/js/settings-sidebar.js'},querySelector:()=>sidebar}});
  vm.runInContext(source,context);context.window.CoopVoteSidebar.mount({role});
  const folder=role==='admin'?'admin':'voter';
  const original=fs.readFileSync(folder+'/dashboard.html','utf8').match(/<aside\b[^>]*class="sidebar"[^>]*>([\s\S]*?)<\/aside>/)[1];
  const expected=[...original.matchAll(/href="([^"]+\.html)"/g)].map(m=>'https://coop.test/nested/CoopVote/'+folder+'/'+m[1]);
  assert.deepEqual(links,expected,role+' keeps every existing sidebar destination and supports hosted subfolders');
  assert.doesNotMatch(sidebar.innerHTML,/nav-item active/,'dashboard is not incorrectly highlighted on a settings page');
  assert.match(sidebar.innerHTML,/id="sidebar-name"/,'shared identity update reaches the footer');
  const count=links.length;context.window.CoopVoteSidebar.mount({role});assert.equal(links.length,count,'mounting again does not duplicate navigation');
}
const management=fs.readFileSync('assets/js/management.js','utf8');
assert.match(management,/CoopVoteAppearance\.navigation\(user\)/,'changing feature updates the existing sidebar');
assert.match(management,/aria-expanded/);assert.match(management,/\.inert=/,'offscreen mobile navigation does not trap keyboard focus');
const runtime=fs.readFileSync('assets/js/app.js','utf8');
const handler=runtime.match(/  function closeSidebarOnNavigation\(event\) \{[^]*?\n  \}/)[0];
for(const shared of [false,true]){
  const classes=new Set(['open']),overlays=new Set(['show']);
  const sidebar={classList:{contains:v=>classes.has(v),remove:v=>classes.delete(v)},hasAttribute:()=>shared,inert:false};
  const overlay={classList:{remove:v=>overlays.delete(v)}},settingsOverlay={hidden:false};
  const toggle={setAttribute(k,v){this[k]=v;}};
  const link={target:'',hasAttribute:()=>false,getAttribute:()=>null,closest:()=>sidebar};
  let position='fixed';
  const ctx=vm.createContext({window:{getComputedStyle:()=>({position})},document:{getElementById:id=>id==='overlay'?overlay:settingsOverlay,querySelectorAll:()=>[toggle]}});
  vm.runInContext(handler,ctx);
  const click={target:{closest:()=>link}};
  ctx.closeSidebarOnNavigation({...click,defaultPrevented:true});assert.ok(classes.has('open'),'blocked navigation leaves the drawer open');
  ctx.closeSidebarOnNavigation({...click,ctrlKey:true});assert.ok(classes.has('open'),'new-tab navigation keeps current drawer');
  position='relative';ctx.closeSidebarOnNavigation(click);assert.ok(classes.has('open'),'desktop sidebar stays in place');
  position='fixed';ctx.closeSidebarOnNavigation(click);
  assert.equal(classes.has('open'),false,'mobile link selection closes sidebar');
  assert.equal(overlays.has('show'),false,'mobile overlay closes');
  assert.equal(settingsOverlay.hidden,true);
  assert.equal(toggle['aria-expanded'],'false');
  assert.equal(sidebar.inert,shared,'only settings uses its existing inert-aware toggle');
}
for(const dir of ['admin','voter'])for(const file of fs.readdirSync(dir).filter(f=>f.endsWith('.html'))){
  assert.match(fs.readFileSync(dir+'/'+file,'utf8'),/app\.js\?v=20260919-voter-badges/,'each page loads the updated shared navigation');
}
console.log('Sidebar checks passed: shared role navigation, mobile link dismissal and overlay cleanup, desktop behavior, and blocked/new-tab navigation.');

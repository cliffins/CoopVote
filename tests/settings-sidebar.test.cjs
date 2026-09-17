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
console.log('Settings sidebar passed: existing role navigation, nested-host links, identity hooks, active state, duplicate prevention and mobile controls.');

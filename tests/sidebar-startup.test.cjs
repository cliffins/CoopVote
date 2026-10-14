const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/js/navigation.js', 'utf8');
function setup(pathname = '/nested/CoopVote/admin/dashboard.html', blocked = false) {
  const values = new Map(), calls = [];
  const labels = Object.fromEntries(['sidebar-name', 'sidebar-id', 'sidebar-avatar'].map(id => [id, {textContent: ''}]));
  const storage = {
    getItem(key) { if (blocked) throw Error('Storage unavailable'); return values.get(key) ?? null; },
    setItem(key, value) { if (blocked) throw Error('Storage unavailable'); values.set(key, value); },
    removeItem(key) { if (blocked) throw Error('Storage unavailable'); values.delete(key); }
  };
  const context = vm.createContext({URL, sessionStorage: storage, location: {pathname},
    window: {addEventListener() {}, CoopVoteSidebar: {mount(user) { calls.push(['mount', user.role]); }},
      CoopVoteAppearance: {navigation(user) { calls.push(['navigation', user.id]); }},
      CoopVoteBranding: {preview() { calls.push(['branding']); }}},
    document: {currentScript: {src: 'https://tunnel.example/nested/CoopVote/assets/js/navigation.js?v=1'},
      getElementById(id) { return labels[id]; }}
  });
  vm.runInContext(source, context);
  return {api: context.window.CoopVoteNavigation, context, values, labels, calls};
}
const user = {id: 7, role: 'admin', name: 'Maria Santos', memberId: '\u2014', roleName: 'Administrator', email: 'private@example.test', csrfToken: 'not-for-cache'};
const key = 'cv.sidebar.identity./nested/CoopVote/';
{
  const t = setup(); t.api.remember(user); t.api.bootstrap();
  assert.deepEqual(JSON.parse(t.values.get(key)), {id: 7, role: 'admin', name: 'Maria Santos', memberId: '\u2014', roleName: 'Administrator'});
  assert.equal(t.labels['sidebar-name'].textContent, 'Maria Santos');
  assert.equal(t.labels['sidebar-id'].textContent, 'Administrator');
  assert.equal(t.labels['sidebar-avatar'].textContent, 'MS');
  assert.deepEqual(t.calls, [['mount', 'admin'], ['navigation', 7], ['branding']]);
  assert.equal(t.context.window.CoopVote, undefined, 'preview never sets authenticated identity');
  t.api.clear(); assert.equal(t.values.has(key), false, 'logout removes the cached presentation');
}
{
  const t = setup('/nested/CoopVote/voter/dashboard.html');
  t.api.remember(user); t.api.bootstrap();
  assert.deepEqual(t.calls, [['branding']], 'cached admin navigation is not injected on a voter page');
  t.api.remember({...user, id: 8, role: 'member', memberId: 'COOP-008'}); t.api.bootstrap();
  assert.equal(t.labels['sidebar-id'].textContent, 'COOP-008');
}
for (const value of ['{broken', JSON.stringify({...user, id: -1}), JSON.stringify({...user, role: 'unknown'})]) {
  const t = setup(); t.values.set(key, value); t.api.bootstrap();
  assert.deepEqual(t.calls, [['branding']], 'malformed cache does not prevent the normal server guard');
}
{
  const t = setup(undefined, true);
  assert.doesNotThrow(() => {t.api.remember(user); t.api.bootstrap(); t.api.clear();});
}
console.log('Sidebar startup passed: scoped cache, literal identity, role separation, logout cleanup and unavailable storage.');

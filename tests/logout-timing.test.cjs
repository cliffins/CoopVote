const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('assets/js/app.js', 'utf8');
const logout = source.slice(source.indexOf('  let loggingOut = false;'), source.indexOf('  const pendingDownloads'));
(async () => {
  let finish, requests = 0;
  const redirects = [];
  const context = vm.createContext({
    window: { CoopVote: { user: { name: 'Test Voter' } }, location: { replace: url => redirects.push(url) } },
    ROOT: '/CoopVote/', guardedRole: 'member', unshroud() {}, toast() {},
    api: (route, options) => {
      assert.equal(route, 'auth/logout'); assert.equal(options.method, 'POST'); requests++;
      return new Promise(resolve => { finish = resolve; });
    },
    setTimeout() { assert.fail('Logout must not add an artificial redirect delay'); }
  });
  vm.runInContext(logout, context);
  const pending = context.logout();
  await context.logout();
  assert.equal(requests, 1, 'Repeated clicks must not submit multiple logout requests');
  assert.deepEqual(redirects, [], 'Wait for the server before redirecting');
  finish({ ok: true }); await pending;
  assert.deepEqual(redirects, ['/CoopVote/login.html?bye=1'], 'Redirect immediately after the response');
  console.log('Logout timing passed: awaits server, prevents double clicks, and redirects without a timer.');
})().catch(error => { console.error(error); process.exitCode = 1; });

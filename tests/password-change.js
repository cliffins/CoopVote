/* Real PHP rejection response through the shared JS API client; no HTTP or real accounts. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const php = process.env.PHP_BIN || (process.platform === 'win32' ? 'C:\\xampp\\php\\php.exe' : 'php');
const incorrect = JSON.parse(execFileSync(php, [path.join(__dirname, 'password-change.php'), '--incorrect-response'], { encoding: 'utf8' }));
const redirects = [];
let reply = incorrect;
const context = vm.createContext({
  window: { addEventListener() {}, location: {
    protocol: 'http:', pathname: '/CoopVote/voter/profile.html',
    replace: target => redirects.push(target)
  } },
  document: { currentScript: { src: 'http://example.test/CoopVote/assets/js/app.js' }, addEventListener() {} },
  FormData,
  localStorage: { getItem: () => null },
  fetch: async () => ({ ok: reply.status < 400, status: reply.status, text: async () => JSON.stringify(reply.data) })
});
vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8'), context);
(async () => {
  const api = context.window.CoopVote.api;
  await assert.rejects(api('voter/change-password', { method: 'POST', body: {
    currentPassword: 'WrongCurrent123', newPassword: 'SampleNew123'
  } }), error => {
    assert.equal(error.status, 422);
    assert.equal(error.code, 'CURRENT_PASSWORD_INCORRECT');
    assert.ok(error.fields.currentPassword);
    return true;
  });
  assert.equal(redirects.length, 0, 'Wrong current password keeps the voter on Profile');
  reply = { status: 200, data: { ok: true } };
  assert.equal((await api('voter/change-password', { method: 'POST' })).ok, true, 'A retry can succeed');
  assert.equal(redirects.length, 0);
  reply = { status: 401, data: { error: 'You are not signed in.', code: 'UNAUTHENTICATED' } };
  await assert.rejects(api('voter/profile'), error => error.status === 401);
  assert.equal(redirects.length, 1, 'An actual expired session still redirects');
  assert.equal(redirects[0], 'http://example.test/CoopVote/login.html?expired=1');
  console.log('Password API integration passed: PHP validation response stays on Profile; actual session expiry redirects.');
})().catch(error => { console.error(error); process.exitCode = 1; });

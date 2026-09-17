/* Profile interactions with simulated DOM and API. No browser, network or real accounts. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const elements = new Map();
class Element {
  constructor(id = '') {
    this.id = id; this.value = ''; this.attrs = {}; this.children = []; this.disabled = false;
    this.hidden = false; this.type = 'password'; this.className = '';
    this.classList = {
      add: name => { this.className = [...new Set([...this.className.split(' ').filter(Boolean), name])].join(' '); },
      remove: name => { this.className = this.className.split(' ').filter(value => value !== name).join(' '); }
    };
    this.parentElement = { appendChild: el => { elements.set(el.id, el); } };
  }
  setAttribute(key, value) { this.attrs[key] = value; }
  getAttribute(key) { return key === 'src' ? this.src : this.attrs[key] || null; }
  removeAttribute(key) { delete this.attrs[key]; }
  setCustomValidity(value) { this.validationMessage = value; }
  appendChild(child) { this.children.push(child); }
  remove() { elements.delete(this.id); }
  focus() { this.focused = true; }
  reportValidity() { return true; }
}
const html = fs.readFileSync(path.join(__dirname, '../voter/profile.html'), 'utf8');
for (const match of html.matchAll(/\bid="([^"]+)"/g)) elements.set(match[1], new Element(match[1]));
const el = id => elements.get(id) || null;
const summary = new Element();
const context = vm.createContext({
  TextEncoder,
  URL: class extends URL {
    static createObjectURL() { return 'blob:http://example.test/preview'; }
    static revokeObjectURL() {}
  },
  FormData: class { constructor() { this.values = new Map(); } append(key, value) { this.values.set(key, value); } },
  document: { getElementById: el, createElement: () => new Element(), querySelectorAll: () => [], querySelector: () => summary },
  window: { addEventListener() {} },
  requestAnimationFrame: callback => callback(), setTimeout() {},
  CoopVoteValidation: { validate: () => true }, // Validation has its own focused suite.
  CoopVote: { ROOT: 'http://example.test/CoopVote/', api: async () => { throw Error('Unexpected API call'); } },
  esc: value => String(value).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
});
for (const s of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
  if (!/\bsrc\s*=/.test(s[1])) vm.runInContext(s[2], context);
}
const actualToast = context.showToast;
const notices = [];
context.showToast = (message, type) => { notices.push({ message, type }); };
const data = { member: { first: 'Sample', last: 'Member', memberId: 'DEMO-001', email: 'sample@example.test',
  dob: '', phone: '', address: '', memberSince: 'January 2026', status: 'Active', hasVoted: false }, election: null };

(async () => {
  assert.equal((html.match(/id="profile-save-btn"/g) || []).length, 1);
  assert.ok(/<details[^>]+id="password-panel"[^>]*>/.test(html));
  const detailsTag = html.match(/<details\b[^>]*>/)[0].replace(/"[^"]*"/g, '""');
  assert.ok(!/\sopen(?=[\s=>])/.test(detailsTag), 'Password fields are collapsed initially');
  assert.ok(!html.includes('photo-input'), 'Preview-only upload controls are removed');
  context.initProfile(data);
  assert.equal(el('profile-save-btn').disabled, true);
  assert.equal(el('profile-discard-btn').hidden, true);
  assert.match(el('vs-inner').innerHTML, /No Active Election/);
  el('f-first').value = 'Edited';
  context.updateProfileActions();
  assert.equal(el('profile-save-btn').disabled, false);
  assert.equal(el('profile-discard-btn').hidden, false);
  context.resetForm();
  assert.equal(el('f-first').value, 'Sample');
  assert.equal(el('profile-discard-btn').hidden, true);

  el('f-email').value = 'other@example.test';
  context.CoopVote.api = async () => { throw Object.assign(new Error('Email already in use.'), { fields: { email: 'Already in use.' } }); };
  await context.saveProfile();
  assert.equal(el('f-email').value, 'other@example.test', 'A rejected save keeps the entered values');
  assert.equal(el('f-email').getAttribute('aria-invalid'), 'true');
  assert.equal(el('f-email-error').textContent, 'Already in use.');
  assert.equal(el('f-email').focused, true);
  assert.equal(el('profile-fields').disabled, false);
  context.clearFieldError(el('f-email'));
  assert.equal(el('f-email-error'), null);

  let resolveSave, calls = 0;
  context.CoopVote.api = (route, request) => {
    calls++;
    assert.equal(route, 'voter/profile');
    assert.equal(request.method, 'PUT');
    return new Promise(resolve => { resolveSave = () => resolve({ ...data, member: { ...data.member, ...request.body } }); });
  };
  const pending = context.saveProfile();
  await context.saveProfile();
  assert.equal(calls, 1, 'Saving cannot submit a second concurrent update');
  assert.equal(el('profile-fields').disabled, true);
  resolveSave(); await pending;
  assert.equal(el('f-email').value, 'other@example.test');
  assert.equal(el('profile-save-btn').disabled, true);
  assert.equal(el('profile-discard-btn').hidden, true);
  el('f-email').value = 'temporary@example.test';
  context.resetForm();
  assert.equal(el('f-email').value, 'other@example.test', 'Discard restores the latest successful save');

  for (const weak of ['', 'abcdefgh', '12345678', 'Abcdefgh', 'abc12345', 'Ab1!', 'Abc     ', 'Ab1' + 'x'.repeat(70), 'Éé1' + '界'.repeat(23)]) {
    assert.equal(context.checkStrength(weak), false, 'Meter rejects insufficient password');
    assert.equal(el('password-save-btn').disabled, true);
    el('pw-current').value = 'SampleOld123';
    el('pw-new').value = el('pw-confirm').value = weak;
    context.CoopVote.api = () => { throw new Error('Weak password reached API'); };
    await context.changePassword();
    assert.equal(el('pw-new').focused, true);
    assert.doesNotMatch(notices.at(-1).message, /reached API/);
  }
  for (const [password, label] of [['SampleNew123', 'Good'], ['sample123!', 'Good'], ['SampleNew123!', 'Strong'], ['Éé123456', 'Good'], ['Ab1' + 'x'.repeat(69), 'Good']]) {
    assert.equal(context.checkStrength(password), true);
    assert.equal(el('password-save-btn').disabled, false);
    assert.equal(el('pw-strength-text').textContent, label);
  }
  context.checkStrength('');
  assert.equal(el('pw-strength-text').textContent, 'Enter a password');
  for (const id of ['pb1', 'pb2', 'pb3', 'pb4']) assert.equal(el(id).className, 'pw-bar', 'Reset clears all strength segments');
  context.checkStrength('SampleNew123!');
  for (const id of ['pb1', 'pb2', 'pb3', 'pb4']) assert.equal(el(id).className, 'pw-bar good', 'Strong password fills all four segments');
  context.checkStrength('abcdefgh');
  assert.equal(el('pb1').className, 'pw-bar weak', 'Weak password fills only the first segment in red');
  for (const id of ['pb2', 'pb3', 'pb4']) assert.equal(el(id).className, 'pw-bar', 'Weakening password clears previously strong segments');
  el('pw-current').value = 'SampleOld123';
  el('pw-new').value = 'SampleNew123';
  el('pw-confirm').value = 'Different123';
  await context.changePassword();
  assert.match(notices.at(-1).message, /do not match/);
  assert.equal(notices.at(-1).type, 'error');
  assert.equal(el('pw-confirm').focused, true);
  el('show-passwords').checked = true;
  context.togglePasswords(true);
  assert.equal(el('pw-current').type, 'text');
  assert.equal(el('pw-new').type, 'text');
  assert.equal(el('pw-confirm').type, 'text');
  el('pw-confirm').value = 'SampleNew123';
  el('password-panel').open = true;
  const footerNote = el('password-save-status').textContent;
  context.CoopVote.api = async () => { throw Object.assign(new Error('Your current password is incorrect.'), {
    status: 422, code: 'CURRENT_PASSWORD_INCORRECT', fields: { currentPassword: 'Incorrect password.' }
  }); };
  await context.changePassword();
  assert.equal(el('password-panel').open, true, 'Wrong current password keeps the form open');
  assert.equal(el('pw-current').value, 'SampleOld123');
  assert.equal(el('pw-new').value, 'SampleNew123', 'The user can retry without re-entering the new password');
  assert.equal(el('pw-current').focused, true);
  assert.equal(el('password-fields').disabled, false);
  assert.equal(el('password-save-btn').disabled, false);
  assert.equal(el('password-save-status').textContent, footerNote, 'Error feedback does not change the footer height');
  assert.match(notices.at(-1).message, /current password is incorrect/);
  assert.equal(notices.at(-1).type, 'error');

  let resolvePassword;
  calls = 0;
  context.CoopVote.api = (route, request) => {
    assert.equal(route, 'voter/change-password');
    assert.equal(request.body.currentPassword, 'SampleOld123');
    assert.equal(request.body.newPassword, 'SampleNew123');
    calls++;
    return new Promise(resolve => { resolvePassword = resolve; });
  };
  const passwordPending = context.changePassword();
  await context.changePassword();
  assert.equal(calls, 1, 'Repeated clicks produce only one password request');
  assert.equal(el('password-fields').disabled, true);
  assert.equal(el('password-save-btn').disabled, true);
  assert.equal(el('password-form').getAttribute('aria-busy'), 'true');
  assert.equal(el('password-save-status').textContent, footerNote);
  resolvePassword({ ok: true }); await passwordPending;
  assert.equal(el('password-panel').open, true, 'Success does not collapse or move the form');
  assert.equal(el('pw-current').value, '');
  assert.equal(el('pw-new').value, '');
  assert.equal(el('pw-confirm').value, '');
  assert.equal(el('pw-new').type, 'password');
  assert.equal(el('show-passwords').checked, false);
  assert.equal(el('password-save-btn').disabled, true, 'Cleared password disables submit after success');
  assert.equal(el('password-form').getAttribute('aria-busy'), null);
  assert.equal(el('password-save-btn').textContent, 'Update password');
  assert.equal(notices.at(-1).type, 'success');

  el('pw-current').value = 'SampleOld123';
  el('pw-new').value = el('pw-confirm').value = 'SampleNew123';
  context.CoopVote.api = async () => { throw new Error('Connection interrupted. Try again.'); };
  await context.changePassword();
  assert.equal(el('password-save-btn').disabled, false, 'A network error releases the submit lock');
  assert.equal(el('password-fields').disabled, false);
  assert.equal(el('password-panel').open, true);
  assert.equal(el('pw-confirm').value, 'SampleNew123');
  assert.match(notices.at(-1).message, /Connection interrupted/);

  actualToast('<img src=x onerror=alert(1)>', 'error');
  const alert = el('toast-wrap').children.at(-1);
  assert.equal(alert.getAttribute('role'), 'alert');
  assert.ok(!alert.innerHTML.includes('<img'), 'Toast messages are escaped');
  actualToast('Password updated successfully.', 'success');
  assert.equal(el('toast-wrap').children.at(-1).getAttribute('role'), 'status');

  context.initProfile({ ...data, election: { title: 'Next Assembly', live: false } });
  assert.match(el('vs-inner').innerHTML, /Voting Has Not Opened/);
  assert.ok(!el('vs-inner').innerHTML.includes('href="ballot.html"'));
  context.initProfile({ ...data, election: { title: 'Current Assembly', live: true } });
  assert.ok(el('vs-inner').innerHTML.includes('href="ballot.html"'));
  context.initProfile(null);
  context.initProfile(data);
  context.cancelProfilePhoto();
  el('f-first').value = 'Unsaved';
  const photoInput = el('profile-photo-file');
  photoInput.files = [{ type: 'image/svg+xml', size: 20 }];
  context.chooseProfilePhoto(photoInput);
  assert.equal(el('photo-upload').hidden, true, 'Unsupported file never enables upload');
  photoInput.files = [{ type: 'image/png', size: 3 * 1024 * 1024 }];
  context.chooseProfilePhoto(photoInput);
  assert.equal(el('photo-upload').hidden, true, 'Oversized file never enables upload');
  photoInput.files = [{ type: 'image/png', size: 100 }];
  context.chooseProfilePhoto(photoInput);
  assert.equal(el('photo-upload').hidden, false);
  context.CoopVote.api = async () => { throw Error('Storage unavailable'); };
  await context.uploadProfilePhoto();
  assert.equal(el('photo-upload').disabled, false, 'Upload can be retried after failure');
  assert.equal(el('photo-upload').hidden, false, 'Failed upload keeps the selected picture');
  assert.equal(el('f-first').value, 'Unsaved');
  let finishPhoto, photoCalls = 0;
  context.CoopVote.api = (route, options) => {
    photoCalls++;
    assert.equal(route, 'voter/profile-photo');
    assert.equal(options.method, 'POST');
    assert.equal(options.body.values.get('photo'), photoInput.files[0]);
    return new Promise(resolve => { finishPhoto = resolve; });
  };
  const photoRequest = context.uploadProfilePhoto();
  await context.uploadProfilePhoto();
  assert.equal(photoCalls, 1, 'Duplicate upload is blocked');
  assert.equal(el('profile-save-btn').disabled, true, 'Details cannot race a photo update');
  finishPhoto({photoUrl:'/CoopVote/api/index.php?r=voter/profile-photo/1&v=1'});
  await photoRequest;
  assert.equal(el('photo-upload').hidden, true);
  assert.equal(el('photo-choose').disabled, false);
  assert.equal(el('f-first').value, 'Unsaved', 'Photo upload preserves unsaved personal details');
  assert.match(el('hero-av').children.at(-1).src, /CoopVote\/api\/index\.php\?r=voter\/profile-photo\/1&v=1$/);
  context.chooseProfilePhoto(photoInput);
  context.cancelProfilePhoto();
  assert.equal(el('photo-upload').hidden, true, 'Cancel restores the saved picture');
  assert.match(el('hero-av').children.at(-1).src, /profile-photo\/1&v=1$/);
  context.initProfile(null);
  assert.equal(el('profile-fields').disabled, true);
  assert.equal(el('profile-save-btn').disabled, true);
  console.log('Profile controls passed: single save, dirty/discard states, error recovery, submission lock, password controls and election status.');
})().catch(error => { console.error(error); process.exitCode = 1; });

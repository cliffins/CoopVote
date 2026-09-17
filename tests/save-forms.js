/* Actual form handlers and shared validation with simulated DOM/API. No browser or network. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const rules = require('../assets/js/validation.js');
function formContext(file) {
  const html = fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
  const fields = new Map();
  for (const match of html.matchAll(/<(input|select|textarea)\b[^>]*id="([^"]+)"[^>]*>/g)) {
    const tag = match[0];
    fields.set(match[2], {
      id: match[2], value: '', type: tag.match(/type="([^"]+)"/)?.[1] || 'text',
      required: /\brequired\b/.test(tag), disabled: /\bdisabled\b/.test(tag), readOnly: /\breadonly\b/.test(tag),
      maxLength: +(tag.match(/maxlength="(\d+)"/)?.[1] || -1), dataset: { input: tag.match(/data-input="([^"]+)"/)?.[1] || '' },
      get willValidate() { return !this.disabled && !this.readOnly && this.type !== 'hidden'; },
      setCustomValidity(error) { this.error = error; }, checkValidity() { return !this.error; },
      reportValidity() { return !this.error; }, focus() { this.focused = true; }
    });
  }
  const el = id => {
    if (!fields.has(id)) fields.set(id, { value: '', style: {}, classList: { add() {}, remove() {} } });
    return fields.get(id);
  };
  const scope = {
    querySelectorAll: () => [...fields.values()].filter(f => f.id?.startsWith('f-')),
    querySelector: selector => el(selector.match(/(?:id="|#)([\w-]+)/)?.[1])
  };
  const notices = [], requests = [], closed = [];
  const ctx = vm.createContext({
    document: { getElementById: el, querySelectorAll: () => [], querySelector: () => scope },
    window: { addEventListener() {} },
    CoopVoteValidation: { validate: () => rules.validate(scope) },
    CoopVote: { api: async (route, options) => { requests.push({ route, options }); return { ok: true, id: 1 }; } }
  });
  for (const script of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
    if (!/\bsrc\s*=/.test(script[1])) vm.runInContext(script[2], ctx);
  }
  ctx.showToast = (message, type) => notices.push({ message, type });
  ctx.openModal = () => {};
  ctx.closeModal = id => closed.push(id);
  ctx.loadVoters = ctx.loadElections = () => {};
  return { ctx, el, notices, requests, closed };
}
(async () => {
  const voter = formContext('admin/voter-management.html');
  const { ctx, el, notices, requests, closed } = voter;
  vm.runInContext('allVoters = [{id:1,first:"Ana",last:"Santos",memberId:"DEMO-001",dobInput:"1990-01-02",email:"ana@example.test",phone:"09123456789",address:"Sample address",status:"Active"}]', ctx);
  ctx.openEdit(1);
  assert.equal(el('f-memberid').required, true);
  for (const blank of ['', '   ', '\u00A0\u200B']) {
    el('f-memberid').value = blank;
    await ctx.saveVoter();
    assert.equal(requests.length, 0, 'Cleared member ID cannot send a save request');
    assert.equal(closed.length, 0, 'The form stays open');
    assert.equal(notices.length, 0, 'No false success notification');
    assert.equal(el('f-memberid').focused, true);
  }
  el('f-memberid').value = 'DEMO-NEW';
  await ctx.saveVoter();
  assert.equal(requests[0].options.body.memberId, 'DEMO-NEW');
  assert.equal(notices.at(-1).type, 'success');
  ctx.openEdit(1);
  const closeCount = closed.length;
  ctx.CoopVote.api = async () => { throw new Error('That member ID is already in use.'); };
  await ctx.saveVoter();
  assert.equal(notices.at(-1).type, 'error');
  assert.equal(closed.length, closeCount, 'A rejected save stays open');
  ctx.openAdd();
  assert.equal(el('f-memberid').required, false, 'New accounts retain automatic member ID allocation');

  const election = formContext('admin/election-management.html');
  election.el('f-title').value = 'Assembly election';
  election.el('f-start').value = '2026-10-01T09:00';
  election.el('f-end').value = '2026-10-02T09:00';
  election.el('f-year').value = '2026';
  election.ctx.document.querySelectorAll = () => [{ textContent: 'Chairperson' }];
  election.ctx.CoopVote.api = async route => {
    if (route === 'positions') throw Object.assign(new Error('Server unavailable'), { status: 500 });
    return { id: 1 };
  };
  await election.ctx.saveElection();
  assert.equal(election.notices.at(-1).type, 'error', 'Position failures cannot show election-save success');
  assert.match(election.notices.at(-1).message, /details were saved/);
  assert.equal(election.closed.length, 0);
  assert.equal(election.el('edit-id').value, 1, 'A partial save retains the saved election ID for retry');
  election.ctx.CoopVote.api = async route => {
    if (route === 'positions') throw Object.assign(new Error('Position already exists'), { status: 409 });
    assert.equal(route, 'elections/1', 'Retry updates the saved election instead of creating a duplicate');
    return { id: 1 };
  };
  await election.ctx.saveElection();
  assert.equal(election.notices.at(-1).type, 'success', 'Existing positions are safely skipped');
  console.log('Save form checks passed: blank ID blocks requests, valid IDs submit, API errors stay visible, allocation mode resets, election failures are reported.');
})().catch(error => { console.error(error); process.exitCode = 1; });

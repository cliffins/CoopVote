/* Keystroke/paste event tests and submit rules; no network or user data. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const rules = require('../assets/js/validation.js');
const vm = require('node:vm');
let checks = 0;
function same(actual, expected, label) { assert.deepEqual(actual, expected, label); checks++; }

for (const name of ['Maria', 'Dela Cruz', 'María', 'Muñoz', "O'Connor", 'O’Connor', 'Anne-Marie', 'Ma. Luisa', 'Jose\u0301', '李明']) {
  same(rules.clean('name', name), name, 'Preserve valid names');
  same(rules.message('name', name), '', 'Accept valid names');
}
for (const name of ['Maria1', '2Juan', 'Juan１２', 'John_Doe', 'John@Doe', '123', '---']) {
  same(!!rules.message('name', name), true, 'Reject invalid names');
}
for (const phone of ['09123456789', '+63 912 345 6789', '(02) 8123-4567']) same(rules.message('phone', phone), '', 'Accept common phone formats');
for (const phone of ['09abc1234567', '12+3456789', '++639123456789', '123', '1234567890123456', '------'])
  same(!!rules.message('phone', phone), true, 'Reject malformed phone numbers');

const listeners = {};
const doc = { querySelectorAll: () => [], addEventListener(type, fn) { listeners[type] = fn; } };
function fire(type, values) {
  const event = { cancelable: true, prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; }, ...values };
  listeners[type](event); return event;
}
class Field {
  constructor(type, value = '', id = '') {
    this.dataset = type ? { input: type } : {}; this.type = type === 'phone' ? 'tel' : 'text';
    this.value = value; this.id = id; this.required = false; this.maxLength = -1;
    this.willValidate = true; this.selectionStart = this.selectionEnd = value.length;
  }
  matches() { return !!this.dataset.input || this.type === 'tel'; }
  setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }
  setCustomValidity(text) { this.error = text; }
  checkValidity() { return !this.error; }
  reportValidity() { this.reported = true; return !this.error; }
  focus() { this.focused = true; }
  dispatchEvent(event) { fire(event.type, { target: this }); }
}
rules.attach(doc);
const name = new Field('name', 'Maria');
const phone = new Field('phone', '09123');
same(fire('keydown', { target: name, key: '1' }).prevented, true, 'Digits cannot be typed in names');
same(fire('keydown', { target: name, key: 'ñ' }).prevented, false, 'Accented letters can be typed');
same(fire('keydown', { target: phone, key: 'a' }).prevented, true, 'Letters cannot be typed in phones');
same(fire('keydown', { target: phone, key: '4' }).prevented, false, 'Phone digits are accepted');
same(fire('keydown', { target: phone, key: 'Backspace' }).prevented, false, 'Backspace remains usable');
same(fire('keydown', { target: phone, key: 'a', ctrlKey: true }).prevented, false, 'Select-all shortcut works');
same(fire('beforeinput', { target: phone, inputType: 'insertText', data: 'x' }).prevented, true, 'Mobile beforeinput blocks letters');
same(fire('beforeinput', { target: name, inputType: 'insertCompositionText', data: '字', isComposing: true }).prevented, false, 'Composition is not interrupted');
phone.value = '09a12b34'; phone.selectionStart = phone.selectionEnd = 5;
fire('input', { target: phone });
same(phone.value, '091234', 'Autofill/input fallback removes letters');
same(phone.selectionStart, 4, 'Caret stays at the corrected position');
name.value = 'Maria'; name.setSelectionRange(0, 5);
same(fire('paste', { target: name, clipboardData: { getData: () => 'José123' } }).prevented, true, 'Mixed paste is handled');
same(name.value, 'José', 'Pasted digits are removed from names');
phone.value = ''; phone.setSelectionRange(0, 0);
fire('paste', { target: phone, clipboardData: { getData: () => '+63 912abc3456789' } });
same(phone.value, '+63 9123456789', 'Pasted letters are removed from phones');
name.value = 'José1'; fire('compositionend', { target: name });
same(name.value, 'José', 'Composition commits only allowed characters');
for (const key of ['e', '.', '-', '+']) same(fire('keydown', { target: new Field('integer'), key }).prevented, true, 'Integer fields reject non-digit input');
const username = new Field('', 'member');
same(fire('keydown', { target: username, key: '1' }).prevented, false, 'Usernames can still contain digits');

function scope(fields) {
  return {
    querySelectorAll: () => fields,
    querySelector(selector) { const id = selector.match(/(?:id="|#)([\w-]+)/)?.[1]; return fields.find(f => f.id === id) || null; }
  };
}
const badName = new Field('name', 'Juan3', 'f-first');
same(rules.validate(scope([badName])), false, 'Programmatically assigned invalid names cannot submit');
same(badName.focused && badName.reported, true, 'Invalid field receives focus and feedback');
const fullName = [new Field('name', 'A'.repeat(60), 'f-first'), new Field('name', 'B'.repeat(45), 'f-last')];
same(rules.validate(scope(fullName)), false, 'Combined names respect the database limit');
fullName[0].value = 'Ana'; fullName[0].form = scope(fullName);
fire('input', { target: fullName[0] });
same(fullName[1].error, '', 'Editing first name clears a stale combined-name error');
same(rules.validate(scope(fullName)), true, 'Corrected names can submit');
const dates = [new Field('', '2026-01-02T10:00', 'f-start'), new Field('', '2026-01-01T10:00', 'f-end')];
same(rules.validate(scope(dates)), false, 'Election end must follow its start');
const optional = new Field('', '');
same(rules.validate(scope([optional])), true, 'Optional descriptions stay optional');
const password = new Field('', '        '); password.type = 'password'; password.required = true;
same(rules.validate(scope([password])), false, 'Whitespace-only passwords cannot submit');
password.value = '  Valid Pass123  ';
same(rules.validate(scope([password])), true, 'Passwords containing meaningful spaces are allowed');
same(password.value, '  Valid Pass123  ', 'Validation never trims the password being saved');
for (const blank of ['', '   ', '\t\n', '\u00A0\u2003\u200B']) {
  for (const type of ['text', 'password', 'email', 'tel', 'date']) {
    const required = new Field('', blank); required.type = type; required.required = true;
    same(rules.validate(scope([required])), false, 'Required ' + type + ' rejects whitespace-only values');
  }
}
same(fire('submit', { target: scope([badName]) }).stopped, true, 'Invalid submissions cannot reach the page handler');

for (const file of ['admin/voter-management.html', 'admin/candidate-management.html', 'voter/profile.html']) {
  const html = fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
  for (const id of ['f-first', 'f-last']) {
    const tag = html.match(new RegExp('<input\\b[^>]*id="'+id+'"[^>]*>'))[0];
    same(tag.includes('data-input="name"'), true, file + ' names use shared rules');
  }
  same(html.includes('CoopVoteValidation.validate('), true, file + ' checks submission');
}
// Exercise the actual modal mode switch with the shared required-field check.
const voterHtml = fs.readFileSync(path.join(__dirname, '../admin/voter-management.html'), 'utf8');
const requiredFields = new Map();
for (const name of ['dob', 'phone', 'address']) {
  requiredFields.set('f-' + name, new Field('', '', 'f-' + name));
  requiredFields.set(name + '-required', { hidden: true });
}
const modalContext = vm.createContext({ document: { getElementById: id => requiredFields.get(id) } });
vm.runInContext(voterHtml.match(/    function setVoterRequiredFields\(required\) \{[\s\S]*?\n    \}/)[0], modalContext);
modalContext.setVoterRequiredFields(true);
const dobField = requiredFields.get('f-dob');
same(dobField.required, true, 'Add Voter marks birth date required');
same(requiredFields.get('dob-required').hidden, false, 'Required date label is visible in Add Voter');
same(rules.validate(scope([dobField])), false, 'Blank birth date blocks Add Voter');
dobField.value = '1990-01-02';
same(rules.validate(scope([dobField])), true, 'A supplied birth date can pass required validation');
same(/setVoterRequiredFields\(true\)/.test(voterHtml.match(/function openEdit\(id\)[\s\S]*?function saveVoter/)[0]), true, 'Edit Voter enables all required personal fields');
modalContext.setVoterRequiredFields(true);
dobField.value = '';
same(rules.validate(scope([dobField])), false, 'Birth date cannot be cleared while editing a voter');
same(requiredFields.get('dob-required').hidden, false, 'Edit shows the required date marker');
const profileHtml = fs.readFileSync(path.join(__dirname, '../voter/profile.html'), 'utf8');
for (const id of ['f-first', 'f-last', 'f-email', 'f-phone', 'f-address', 'f-dob']) {
  const tag = profileHtml.match(new RegExp('<input\\b[^>]*id="' + id + '"[^>]*>'))[0];
  same(/\brequired\b/.test(tag), true, 'Profile requires ' + id);
}
console.log(checks + ' input validation checks passed (simulated events; no browser or network).');

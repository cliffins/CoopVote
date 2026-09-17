/* Shared form rules. Keystrokes, paste, autofill and submit use the same checks. */
(function (root, factory) {
  const validation = factory();
  if (typeof module === 'object' && module.exports) module.exports = validation;
  else { root.CoopVoteValidation = validation; validation.attach(document); }
})(typeof window !== 'undefined' ? window : globalThis, function () {
  'use strict';
  const selector = 'input[data-input], input[type="tel"]';
  const nameChars = /[^\p{L}\p{M} .'\u2019-]/gu;
  const isBlank = value => /^[\s\p{Z}\uFEFF\u200B]*$/u.test(String(value));

  function kind(field) { return field?.dataset?.input || (field?.type === 'tel' ? 'phone' : ''); }
  function clean(type, value) {
    const text = String(value);
    if (type === 'name') return text.replace(nameChars, '');
    if (type === 'phone') return text.replace(/[^0-9+ ()-]/g, '').replace(/(?!^)\+/g, '');
    if (type === 'integer' || type === 'digits') return text.replace(/[^0-9]/g, '');
    return text;
  }

  function message(type, value) {
    const text = String(value).trim();
    if (!text) return '';
    if (type === 'name' && (clean(type, text) !== text || !/\p{L}/u.test(text)))
      return 'Use letters, spaces, apostrophes, periods or hyphens. Numbers are not allowed in names.';
    if (type === 'phone') {
      if (!/^\+?[0-9 ()-]+$/.test(text)) return 'Phone numbers cannot contain letters. Use digits and an optional leading +.';
      const digits = text.replace(/[^0-9]/g, '');
      if (digits.length < 7 || digits.length > 15 || text.length > 20)
        return 'Enter a phone number with 7–15 digits (up to 20 characters including formatting).';
    }
    if ((type === 'integer' || type === 'digits') && !/^[0-9]+$/.test(text)) return 'Use whole numbers only.';
    return '';
  }

  function configure(field) {
    const type = kind(field);
    if (type === 'name') { field.inputMode = 'text'; field.autocapitalize = 'words'; }
    if (type === 'phone') field.inputMode = 'tel';
    if (type === 'integer' || type === 'digits') field.inputMode = 'numeric';
  }

  function sanitize(field) {
    const before = field.value;
    const after = clean(kind(field), before);
    if (before === after) return;
    const caret = field.selectionStart;
    field.value = after;
    if (caret !== null) {
      const position = clean(kind(field), before.slice(0, caret)).length;
      try { field.setSelectionRange(position, position); } catch (_) { /* number inputs have no text selection */ }
    }
  }

  function replacement(field, text) {
    const start = field.selectionStart ?? field.value.length;
    const end = field.selectionEnd ?? start;
    return { start, value: field.value.slice(0, start) + text + field.value.slice(end) };
  }

  function validate(scope) {
    const target = typeof scope === 'string' ? document.querySelector(scope) : scope;
    if (!target) return false;
    const fields = [...target.querySelectorAll('input, select, textarea')].filter(field => field.willValidate);
    fields.forEach(field => {
      const blank = isBlank(field.value);
      field.setCustomValidity(field.required && blank ? 'This field is required.' : message(kind(field), field.value));
      if (field.maxLength > 0 && field.value.length > field.maxLength)
        field.setCustomValidity('Use at most ' + field.maxLength + ' characters.');
    });
    const first = target.querySelector('[data-input="name"][id="f-first"]');
    const last = target.querySelector('[data-input="name"][id="f-last"]');
    if (first && last && [...(first.value.trim() + ' ' + last.value.trim())].length > 100)
      last.setCustomValidity('Use at most 100 characters for the full name.');
    const start = target.querySelector('#f-start');
    const end = target.querySelector('#f-end');
    if (start?.value && end?.value && end.value <= start.value)
      end.setCustomValidity('The end date must be after the start date.');
    const invalid = fields.find(field => !field.checkValidity());
    if (!invalid) return true;
    invalid.focus();
    invalid.reportValidity();
    return false;
  }

  function attach(doc) {
    const accepts = field => field?.matches?.(selector) && !field.disabled && !field.readOnly;
    doc.querySelectorAll(selector).forEach(configure);
    const today = new Date();
    const localDate = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    doc.querySelectorAll('input[data-max-today]').forEach(field => { field.max = localDate; });
    doc.addEventListener('focusin', event => { if (accepts(event.target)) configure(event.target); });
    doc.addEventListener('keydown', event => {
      const field = event.target;
      if (!accepts(field) || event.isComposing || event.ctrlKey || event.metaKey || event.altKey || event.key.length !== 1) return;
      const next = replacement(field, event.key).value;
      if (clean(kind(field), next) !== next) event.preventDefault();
    }, true);
    doc.addEventListener('beforeinput', event => {
      const field = event.target;
      if (!accepts(field) || event.isComposing || !event.data || !(event.inputType || '').startsWith('insert')) return;
      const next = replacement(field, event.data).value;
      if (clean(kind(field), next) !== next && event.cancelable) event.preventDefault();
    }, true);
    doc.addEventListener('paste', event => {
      const field = event.target;
      if (!accepts(field) || !event.clipboardData) return;
      const text = event.clipboardData.getData('text');
      const next = replacement(field, text);
      if (clean(kind(field), next.value) === next.value) return;
      event.preventDefault();
      let value = clean(kind(field), next.value);
      if (field.maxLength > 0) value = value.slice(0, field.maxLength);
      const position = Math.min(value.length, clean(kind(field), field.value.slice(0, next.start) + text).length);
      field.value = value;
      try { field.setSelectionRange(position, position); } catch (_) { /* numeric input */ }
      field.dispatchEvent(new Event('input', { bubbles: true }));
    }, true);
    doc.addEventListener('input', event => {
      const field = event.target;
      if (accepts(field) && !event.isComposing) sanitize(field);
      if (typeof field?.setCustomValidity === 'function') field.setCustomValidity('');
      /* Changing either name can resolve a combined full-name length error. */
      if (kind(field) === 'name' && field.form) {
        field.form.querySelectorAll('[data-input="name"]').forEach(name => name.setCustomValidity(''));
      }
    }, true);
    doc.addEventListener('compositionend', event => {
      if (!accepts(event.target)) return;
      sanitize(event.target);
      event.target.dispatchEvent(new Event('input', { bubbles: true }));
    }, true);
    doc.addEventListener('submit', event => {
      if (!validate(event.target)) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
  }
  return { clean, message, validate, attach, isBlank };
});

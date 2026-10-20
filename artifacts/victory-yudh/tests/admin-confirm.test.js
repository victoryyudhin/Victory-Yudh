const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const listeners = new Map();
let confirmCalls = 0;
let confirmResult = false;

const document = {
  addEventListener(type, listener) {
    listeners.set(type, listener);
  },
  querySelector() {
    return null;
  },
};
const window = {
  confirm() {
    confirmCalls += 1;
    return confirmResult;
  },
};

vm.runInNewContext(
  fs.readFileSync(new URL('../public/assets/app.js', `file://${__dirname}/`), 'utf8'),
  { document, window, JSON, Number }
);

const confirmationForm = { dataset: { confirm: 'Confirm this action?' } };
const inputTarget = {
  closest(selector) {
    if (selector === '[data-confirm]') return confirmationForm;
    return null;
  },
};
const clickEvent = {
  target: inputTarget,
  preventDefault() {
    this.defaultPrevented = true;
  },
};

listeners.get('click')(clickEvent);
assert.equal(confirmCalls, 0, 'Clicking an input inside a confirmation form must not open a dialog.');
assert.equal(clickEvent.defaultPrevented, undefined, 'Clicking an input must not be canceled.');

const submitEvent = {
  target: {
    closest(selector) {
      if (selector === '[data-confirm]') return confirmationForm;
      return null;
    },
  },
  preventDefault() {
    this.defaultPrevented = true;
  },
};
listeners.get('submit')(submitEvent);
assert.equal(confirmCalls, 1, 'Confirmation must run when the form is submitted.');
assert.equal(submitEvent.defaultPrevented, true, 'Declining confirmation must prevent submission.');

confirmResult = true;
const acceptedSubmit = {
  target: submitEvent.target,
  preventDefault() {
    this.defaultPrevented = true;
  },
};
listeners.get('submit')(acceptedSubmit);
assert.equal(confirmCalls, 2, 'Accepted form submission should still be confirmed.');
assert.equal(acceptedSubmit.defaultPrevented, undefined, 'Accepting confirmation must allow submission.');

console.log('PASS - confirmation prompts run on form submit, not input clicks');

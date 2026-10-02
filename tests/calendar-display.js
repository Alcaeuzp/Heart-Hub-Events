// Exercise the shipped calendar hover/focus handlers with two same-event cards.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const listeners = new Map();
const cards = [];
const document = {
  activeElement: null,
  addEventListener(type, callback) {
    if (!listeners.has(type)) listeners.set(type, []);
    listeners.get(type).push(callback);
  },
  querySelectorAll() { return []; },
  // The original global lookup always resolves the first card for a duplicated ID.
  getElementById() { return cards[0].popup; },
};
function card() {
  const classes = new Set();
  const attributes = new Map([['aria-expanded', 'false'], ['aria-controls', 'shared-event']]);
  const item = {
    popup: {hidden: true},
    button: {getAttribute: key => attributes.get(key), setAttribute: (key, value) => attributes.set(key, value)},
    classList: {add: key => classes.add(key), remove: key => classes.delete(key), contains: key => classes.has(key)},
    closest: selector => selector === '[data-popups="1"]' ? {} : null,
    contains: target => target === item.button || target === item.popup,
    querySelector: selector => selector === '[data-hherm-event-trigger]' ? item.button : selector === '.hherm-calendar__popup' ? item.popup : null,
  };
  cards.push(item);
  return item;
}
const first = card();
const second = card();
const context = vm.createContext({
  document,
  window: {location: {pathname: '/events', search: ''}, addEventListener() {}},
});
vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/calendar-display.js'), 'utf8'), context);
function dispatch(type, item) {
  const target = {closest: selector => selector === '[data-hherm-event-card]' ? item : null};
  for (const listener of listeners.get(type) || []) listener({target, relatedTarget: null});
}

dispatch('mouseover', second);
assert.equal(second.popup.hidden, false);
assert.equal(first.popup.hidden, true);
assert.equal(second.button.getAttribute('aria-expanded'), 'true');
console.log('PASS hovering the second same-event card opens only its own popup');
dispatch('mouseout', second);
assert.equal(second.popup.hidden, true);
assert.equal(first.popup.hidden, true);
assert.equal(second.button.getAttribute('aria-expanded'), 'false');
console.log('PASS leaving a card closes only its local popup');
dispatch('focusin', first);
dispatch('focusin', second);
dispatch('focusout', second);
assert.equal(first.popup.hidden, false);
assert.equal(second.popup.hidden, true);
console.log('PASS focus changes in one calendar do not close another calendar popup');

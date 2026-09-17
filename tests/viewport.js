/* Simulated keyboard, rotation and zoom events. No browser, network or accounts. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/viewport.js'), 'utf8');
function setup(viewport) {
  const properties = new Map(), attributes = new Set(), callbacks = [], events = {};
  const root = {
    style: { setProperty: (k,v) => properties.set(k,v), removeProperty: k => properties.delete(k) },
    toggleAttribute: (k,on) => on ? attributes.add(k) : attributes.delete(k),
    removeAttribute: k => attributes.delete(k)
  };
  if (viewport) viewport.addEventListener = (event, callback) => { events[event] = callback; };
  const window = {
    visualViewport: viewport,
    addEventListener: (event, callback) => { events['window:' + event] = callback; },
    requestAnimationFrame: callback => { callbacks.push(callback); return callbacks.length; }
  };
  vm.runInNewContext(source, { window, document: { documentElement: root } });
  const flush = () => { while (callbacks.length) callbacks.shift()(); };
  return { properties, attributes, callbacks, events, flush };
}
const viewport = { height: 844, offsetTop: 0, scale: 1 };
const state = setup(viewport);
assert.equal(state.properties.get('--cv-visible-height'), '844px');
assert.equal(state.attributes.has('data-cv-compact-viewport'), false);
viewport.height = 315; viewport.offsetTop = 30;
state.events.resize(); state.events.scroll(); state.events['window:resize']();
assert.equal(state.callbacks.length, 1, 'Resize and panning share one animation frame');
state.flush();
assert.equal(state.properties.get('--cv-visible-height'), '315px', 'Height follows keyboard space');
assert.equal(state.properties.get('--cv-visible-top'), '30px', 'Dialog tracks viewport panning');
assert.equal(state.attributes.has('data-cv-compact-viewport'), true, 'Short viewport enables whole-dialog scrolling');
viewport.height = 390; viewport.offsetTop = -4;
state.events.resize(); state.flush();
assert.equal(state.properties.get('--cv-visible-top'), '0px', 'Overscroll does not move the dialog above the screen');
viewport.scale = 2;
state.events.resize(); state.flush();
assert.equal(state.properties.size, 0, 'Pinch zoom uses normal browser layout');
assert.equal(state.attributes.size, 0);
viewport.scale = 1; viewport.height = 844; viewport.offsetTop = 0;
state.events['window:pageshow'](); state.flush();
assert.equal(state.properties.get('--cv-visible-height'), '844px', 'Closing the keyboard restores available height');
assert.equal(state.attributes.size, 0);
viewport.height = 0;
state.events.resize(); state.flush();
assert.equal(state.properties.get('--cv-visible-height'), '844px', 'Transient zero height cannot collapse the page');
assert.equal(setup(undefined).properties.size, 0, 'CSS fallback works without VisualViewport');
console.log('Viewport behavior passed: keyboard height, panning, rotation, event coalescing, zoom, restoration and CSS fallback.');

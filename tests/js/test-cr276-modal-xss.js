/**
 * CR276: the desktop phone modal must not turn the button text (or number) into markup.
 *
 * Usage: node tests/js/test-cr276-modal-xss.js [path/to/frontend.js]
 *
 * Runs frontend.js in a vm with a small jQuery stand-in that records every HTML
 * string handed to .append() and every value set with .text(). Clicks the button
 * as a desktop visitor whose button text decodes to '<img src=x onerror=alert(1)>'.
 * FAILS against 3.0.1 (the text is concatenated into the appended HTML).
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const file = process.argv[2] || path.join(__dirname, '..', '..', 'assets', 'js', 'frontend.js');
const src = fs.readFileSync(file, 'utf8');

const EVIL_TEXT = '<img src=x onerror=alert(1)>';
const EVIL_TEL = 'tel:555<svg onload=alert(2)>';
const appended = [];
const texts = [];
let ready = null;
const clickHandlers = [];

function stub(selector) {
  let p;
  const s = {
    length: 1,
    selector,
    on(evt, fn) { if (selector === '.fbcn-call-button' && evt === 'click') clickHandlers.push(fn); return p; },
    off() { return p; },
    ready(fn) { ready = fn; return p; },
    find(sel) { return stub(sel); },
    append(html) { if (typeof html === 'string') appended.push(html); return p; },
    remove() { return p; },
    addClass() { return p; }, removeClass() { return p; }, hasClass() { return false; },
    css() { return p; }, attr(name) { return name === 'href' ? EVIL_TEL : ''; },
    text(v) {
      if (v === undefined) return selector === '.fbcn-button-text' ? EVIL_TEXT : '';
      texts.push(v); return p;
    },
    show() { return p; }, hide() { return p; }, fadeIn() { return p; }, fadeOut() { return p; },
    each() { return p; }, data() { return undefined; }, scrollTop() { return 0; }, is() { return false; },
    width() { return 1280; }, height() { return 800; },
  };
  // Any other jQuery method the script calls just chains.
  p = new Proxy(s, { get: (t, k) => (k in t ? t[k] : () => p) });
  return p;
}
const $ = (sel) => stub(typeof sel === 'string' ? sel : 'document');

const ctx = {
  jQuery: $, window: { innerWidth: 1280, addEventListener() {}, pageYOffset: 0,
    getComputedStyle: () => ({ display: 'block', visibility: 'visible', opacity: '1', getPropertyValue: () => '' }) },
  navigator: { userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)' },
  fbcnSettings: { buttonColor: '#007cba', textColor: '#ffffff' },
  document: {}, console, setTimeout: (fn) => fn(), clearTimeout() {},
};
ctx.window.jQuery = $;
vm.createContext(ctx);
vm.runInContext(src, ctx);

let pass = 0, fail = 0;
function check(label, cond) { if (cond) pass++; else { fail++; console.log('  x ' + label); } }

check('the script registered its ready handler', typeof ready === 'function');
if (ready) ready();
check('the button click handlers were bound', clickHandlers.length > 0);
clickHandlers.forEach((fn) => fn.call({}, { preventDefault() {} }));
const html = appended.join('\n');
check('a modal was appended for a desktop click', html.indexOf('fbcn-phone-modal') !== -1);
check('CR276 the button text is not in the appended HTML', html.indexOf('<img') === -1 && html.indexOf('onerror') === -1);
check('CR276 the phone number is not in the appended HTML', html.indexOf('<svg') === -1 && html.indexOf('onload') === -1);
check('CR276 the button text is set as text instead', texts.indexOf(EVIL_TEXT) !== -1);
check('CR276 the number is set as text instead', texts.some((t) => String(t).indexOf('555') !== -1));

console.log(`modal harness: ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);

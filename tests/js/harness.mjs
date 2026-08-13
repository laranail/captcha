/**
 * Runs the widget runtime for real, with a hand-rolled DOM.
 *
 * The PHP suite asserts the script is *emitted* with the right content. That is not the same as
 * the script working: a typo inside the IIFE passes every one of those tests. This executes it.
 *
 * Deliberately no jsdom and no npm install. The runtime touches about a dozen DOM APIs, and
 * stubbing those is ~80 lines — cheaper to maintain than a JavaScript toolchain inside a PHP
 * package, and it runs anywhere Node exists, which includes every GitHub runner.
 *
 * Usage: node harness.mjs <path-to-extracted-runtime.js>
 */
import { readFileSync } from 'node:fs';

const failures = [];

function check(label, condition) {
    if (!condition) {
        failures.push(label);
    }
}

class El {
    constructor(attrs = {}, id = null) {
        this.nodeType = 1;
        this.attrs = attrs;
        this.id = id;
        this.dataset = {};
        this.value = '';
        this.listeners = {};
        this.children = [];
        this.parentForm = null;
        this.descendants = {};
    }

    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
    }

    hasAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attrs, name);
    }

    closest(selector) {
        return selector === 'form' ? this.parentForm : null;
    }

    querySelector(selector) {
        return this.descendants[selector] ?? null;
    }

    querySelectorAll() {
        return [];
    }

    addEventListener(event, handler) {
        (this.listeners[event] ||= []).push(handler);
    }

    dispatch(event, payload = {}) {
        (this.listeners[event] || []).forEach((h) => h({ preventDefault() {}, ...payload }));
    }
}

const registry = new Map();
const documentListeners = {};

globalThis.MutationObserver = class {
    constructor(callback) {
        this.callback = callback;
    }

    observe() {}
};

globalThis.document = {
    documentElement: new El(),
    getElementById: (id) => registry.get(id) ?? null,
    querySelectorAll: (selector) =>
        selector === '[data-captcha-config]'
            ? [...registry.values()].filter((el) => el.hasAttribute('data-captcha-config'))
            : [],
    addEventListener: (event, handler) => {
        (documentListeners[event] ||= []).push(handler);
    },
};

globalThis.window = globalThis;
globalThis.btoa = (s) => Buffer.from(s, 'binary').toString('base64');
globalThis.fetch = () => Promise.resolve({ ok: false });

let resetCalls = 0;
globalThis.turnstile = { reset: () => { resetCalls++; } };

// A Turnstile container with a token field, as the component renders it.
const container = new El(
    { 'data-captcha-config': JSON.stringify({
        id: 'captcha-test', field: 'captcha', provider: 'turnstile',
        reset: 'turnstile.reset', skipMorph: true, selfHosted: false,
        challengeUrl: null, execute: false, siteKey: 'sk', action: 'submit',
    }) },
    'captcha-test',
);
const tokenField = new El({}, 'captcha-test-token');
tokenField.value = 'a-solved-token';

registry.set('captcha-test', container);
registry.set('captcha-test-token', tokenField);

// The moment of truth: a syntax error or a bad reference throws here, and nothing in the PHP
// suite would have noticed.
const source = readFileSync(process.argv[2], 'utf8');

try {
    new Function(source)();
} catch (error) {
    console.error('runtime threw on load: ' + error.message);
    process.exit(1);
}

check('runtime defines the expiry callback', typeof globalThis.laranailCaptchaExpired === 'function');
check('initialisation marks the container ready', container.dataset.captchaReady === '1');

globalThis.laranailCaptchaExpired();

check('expiry clears the stale token', tokenField.value === '');
check('expiry resets the vendor widget', resetCalls === 1);

// Idempotence: a morph re-inserting the same node must not double-bind.
const before = Object.keys(container.listeners).length;
documentListeners['DOMContentLoaded']?.forEach((h) => h());
check('re-initialisation is idempotent', Object.keys(container.listeners).length === before);

// The self-hosted refresh path, which the stub previously could not reach: `querySelector` always
// returned null, so the branch that swaps in a fresh question was executed by nothing.
const question = new El({}, 'captcha-math-question');
const challengeField = new El({});
const mathContainer = new El(
    { 'data-captcha-config': JSON.stringify({
        id: 'captcha-math', field: 'captcha', provider: 'math',
        reset: null, skipMorph: false, selfHosted: true,
        challengeUrl: '/captcha/challenge', execute: false, siteKey: null, action: null,
    }) },
    'captcha-math',
);
mathContainer.descendants['input[name="captcha_challenge"]'] = challengeField;

registry.clear();
registry.set('captcha-math', mathContainer);
registry.set('captcha-math-question', question);

globalThis.fetch = () => Promise.resolve({
    ok: true,
    json: () => Promise.resolve({ id: 'new-id', question: '3 + 4', signature: 'sig', expires_at: 99 }),
});

globalThis.laranailCaptchaExpired();

await new Promise((resolve) => setImmediate(resolve));

check('an expired self-hosted question is replaced', question.textContent === '3 + 4');
check('the refreshed challenge is re-signed into the form', challengeField.value !== '');

if (failures.length > 0) {
    console.error(failures.join('\n'));
    process.exit(1);
}

console.log('ok');

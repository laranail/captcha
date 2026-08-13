/**
 * The parts of the widget runtime that only a browser can execute.
 *
 * Deliberately not a second copy of the Node harness. Everything here is something that harness
 * provably cannot check: it stubs `MutationObserver.observe()` as an empty method, it has no
 * Livewire, and it builds its DOM by hand instead of parsing what Blade emitted.
 */
import { test, expect } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const fixtures = join(dirname(fileURLToPath(import.meta.url)), '.tmp');
const page_ = (name) => 'file://' + join(fixtures, `${name}.html`);

/** Keep the vendor scripts out of it — this suite must not depend on Cloudflare or Google. */
async function open(page, name) {
    await page.route('**/*', (route) =>
        route.request().url().startsWith('file://') ? route.continue() : route.abort(),
    );
    await page.goto(page_(name));
}

test.describe('the rendered markup is what the script expects', () => {
    test('every container carries config the browser can parse', async ({ page }) => {
        for (const name of ['math', 'turnstile', 'recaptcha-v3']) {
            await open(page, name);

            const cfg = await page.evaluate(() => {
                const el = document.querySelector('[data-captcha-config]');
                return el ? JSON.parse(el.getAttribute('data-captcha-config')) : null;
            });

            // A hand-built DOM cannot tell you Blade emitted valid JSON into an attribute.
            expect(cfg, `${name} has a parseable config`).not.toBeNull();
            expect(cfg.id, `${name} config carries the instance id`).toBeTruthy();
        }
    });

    test('the math challenge is wired to its input for a screen reader', async ({ page }) => {
        await open(page, 'math');

        const wiring = await page.evaluate(() => {
            const input = document.querySelector('input[name="captcha_answer"]');
            const describedBy = input.getAttribute('aria-describedby');
            return {
                describedBy,
                // The id has to resolve, or the association silently does nothing.
                targetExists: !!document.getElementById(describedBy),
                question: document.getElementById(describedBy)?.textContent?.trim(),
                labelFor: !!document.querySelector(`label[for="${input.id}"]`),
                challenge: !!document.querySelector('input[name="captcha_challenge"]')?.value,
            };
        });

        expect(wiring.targetExists, 'aria-describedby points at a real element').toBe(true);
        // Not `/\d/`. The generator writes numbers as words as often as digits — `seven + four`
        // is a valid render — so a digit assertion fails about one run in ten. It did, which is
        // the only reason this comment exists.
        expect(wiring.question, 'the question is rendered').toMatch(/[a-z0-9]/i);
        expect(wiring.labelFor, 'the input has a label').toBe(true);
        expect(wiring.challenge, 'the signed challenge travels with the form').toBe(true);
    });

    test('the token field is named exactly what the script looks up', async ({ page }) => {
        await open(page, 'recaptcha-v3');

        const found = await page.evaluate(() => {
            const el = document.querySelector('[data-captcha-config]');
            const cfg = JSON.parse(el.getAttribute('data-captcha-config'));
            // `clearToken` and `bindExecute` both do getElementById(cfg.id + '-token').
            return !!document.getElementById(cfg.id + '-token');
        });

        expect(found, 'the hidden token field matches the id the runtime derives').toBe(true);
    });
});

test.describe('the MutationObserver', () => {
    test('initialises a container inserted after load', async ({ page }) => {
        await open(page, 'turnstile');

        const ready = await page.evaluate(async () => {
            const el = document.createElement('div');
            el.id = 'injected';
            el.setAttribute(
                'data-captcha-config',
                JSON.stringify({ id: 'injected', provider: 'turnstile', execute: false }),
            );
            document.body.appendChild(el);

            // Observer callbacks are delivered as a microtask.
            await new Promise((r) => setTimeout(r, 50));

            return document.getElementById('injected').dataset.captchaReady;
        });

        // The Node harness stubs observe() as a no-op, so this path is executed by nothing there.
        expect(ready, 'an injected container is initialised by the observer').toBe('1');
    });

    test('initialises a container nested inside an inserted subtree', async ({ page }) => {
        await open(page, 'turnstile');

        const ready = await page.evaluate(async () => {
            const wrapper = document.createElement('div');
            wrapper.innerHTML =
                '<section><div id="nested" data-captcha-config=\'{"id":"nested","execute":false}\'></div></section>';
            document.body.appendChild(wrapper);

            await new Promise((r) => setTimeout(r, 50));

            return document.getElementById('nested').dataset.captchaReady;
        });

        // A morph inserts a subtree, not a bare container — this is the `scan(node)` branch.
        expect(ready, 'a nested container is found by the subtree scan').toBe('1');
    });

    test('clears the ready flag on removal so a re-inserted node initialises again', async ({ page }) => {
        await open(page, 'turnstile');

        const result = await page.evaluate(async () => {
            const el = document.querySelector('[data-captcha-config]');
            const wasReady = el.dataset.captchaReady;

            el.remove();
            await new Promise((r) => setTimeout(r, 50));
            const afterRemoval = el.dataset.captchaReady;

            document.body.appendChild(el);
            await new Promise((r) => setTimeout(r, 50));

            return { wasReady, afterRemoval, afterReinsert: el.dataset.captchaReady };
        });

        expect(result.wasReady, 'the widget initialised on load').toBe('1');
        expect(result.afterRemoval, 'removal clears the flag').toBeUndefined();
        expect(result.afterReinsert, 're-insertion initialises again').toBe('1');
    });
});

test.describe('the Livewire morph hook', () => {
    /** A hook registry that records, standing in for Livewire. */
    const installLivewire = async (page) =>
        page.evaluate(async () => {
            window.__skips = 0;
            window.Livewire = {
                hooks: {},
                hook(name, fn) {
                    (this.hooks[name] ||= []).push(fn);
                },
            };
            document.dispatchEvent(new Event('livewire:init'));
            await new Promise((r) => setTimeout(r, 10));

            return (window.Livewire.hooks['morph.updating'] || []).length;
        });

    test('skips a hosted widget, so a re-render cannot discard a solved iframe', async ({ page }) => {
        await open(page, 'turnstile');

        const registered = await installLivewire(page);
        expect(registered, 'the morph hook is registered').toBe(1);

        const skipped = await page.evaluate(() => {
            let skipped = false;
            const el = document.querySelector('[data-captcha-config]');
            window.Livewire.hooks['morph.updating'][0]({ el, skip: () => { skipped = true; } });
            return skipped;
        });

        expect(skipped, 'a live vendor widget is skipped by the morph').toBe(true);
    });

    test('does not skip a self-hosted challenge, which must be allowed to re-render', async ({ page }) => {
        await open(page, 'math');

        await installLivewire(page);

        const skipped = await page.evaluate(() => {
            let skipped = false;
            const el = document.querySelector('[data-captcha-config]');
            window.Livewire.hooks['morph.updating'][0]({ el, skip: () => { skipped = true; } });
            return skipped;
        });

        // Skipping here would freeze the question at whatever the first render issued, and a
        // component re-rendering for ten minutes would show a challenge the server has expired.
        expect(skipped, 'a server-rendered question is not frozen by the skip').toBe(false);
    });

    test('ignores elements that are not captcha containers', async ({ page }) => {
        await open(page, 'turnstile');
        await installLivewire(page);

        const skipped = await page.evaluate(() => {
            let skipped = false;
            window.Livewire.hooks['morph.updating'][0]({
                el: document.createElement('div'),
                skip: () => { skipped = true; },
            });
            return skipped;
        });

        expect(skipped, 'unrelated elements morph normally').toBe(false);
    });
});

test.describe('execute-on-submit', () => {
    test('mints a token and submits exactly once', async ({ page }) => {
        await open(page, 'recaptcha-v3');

        const result = await page.evaluate(async () => {
            let submits = 0;
            // Intercept rather than navigate, and count — a double submit is the failure mode
            // the `minted` flag exists to prevent.
            HTMLFormElement.prototype.requestSubmit = function () { submits++; };

            window.grecaptcha = {
                ready: (fn) => fn(),
                execute: () => Promise.resolve('a-minted-token'),
            };

            document.querySelector('button[type="submit"]').click();
            await new Promise((r) => setTimeout(r, 50));

            const cfg = JSON.parse(
                document.querySelector('[data-captcha-config]').getAttribute('data-captcha-config'),
            );

            return { submits, token: document.getElementById(cfg.id + '-token').value };
        });

        expect(result.token, 'the token is written into the form').toBe('a-minted-token');
        expect(result.submits, 'the form is submitted once').toBe(1);
    });

    test('still submits when the vendor script is blocked', async ({ page }) => {
        await open(page, 'recaptcha-v3');

        const submits = await page.evaluate(async () => {
            let submits = 0;
            HTMLFormElement.prototype.requestSubmit = function () { submits++; };

            // grecaptcha deliberately absent: an ad blocker, a CSP, an outage.
            document.querySelector('button[type="submit"]').click();
            await new Promise((r) => setTimeout(r, 50));

            return submits;
        });

        // Fails server-side, which is correct. A form that can never be submitted is not.
        expect(submits, 'a blocked vendor script does not trap the visitor').toBe(1);
    });
});

test('expiry clears the stale token and resets the vendor widget', async ({ page }) => {
    await open(page, 'recaptcha-v3');

    const result = await page.evaluate(async () => {
        let resets = 0;
        window.grecaptcha = { reset: () => { resets++; } };

        const cfg = JSON.parse(
            document.querySelector('[data-captcha-config]').getAttribute('data-captcha-config'),
        );
        const field = document.getElementById(cfg.id + '-token');
        field.value = 'a-token-that-has-expired';

        window.laranailCaptchaExpired();
        await new Promise((r) => setTimeout(r, 20));

        return { value: field.value, resets };
    });

    expect(result.value, 'the expired token is cleared').toBe('');
    expect(result.resets, 'the vendor widget is reset').toBe(1);
});

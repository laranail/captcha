# Testing

How to test an application that uses this package, and how the package tests itself.

## In your application

```php
use Simtabi\Laranail\Captcha\Facades\Captcha;

Captcha::fake();                 // every submission verifies
Captcha::fake(verifies: false);  // none do
Captcha::forgetAdapter();        // back to the configured provider
```

`fake()` swaps in the null adapter for the rest of the test. It is guarded the same way as
everything else — the production check runs on the swapped adapter too, so a `fake()` left in a
deployed code path fails closed rather than silently disabling protection.

```php
it('rejects a registration without a captcha', function () {
    Captcha::fake();

    $this->post('/register', ['email' => 'a@b.test'])
        ->assertSessionHasErrors('captcha');
});
```

That test passes because the rule is implicit. If it ever starts failing, the bypass is back.

## Asserting what was verified

```php
Captcha::fake();
Captcha::fakeScore(0.4);                      // lands in the review band
Captcha::fakeSequence([$failed, $passed]);    // one result per call

Captcha::assertVerified();
Captcha::assertVerified(fn ($attempt) => $attempt->token === 'expected');
Captcha::assertFailed();
Captcha::assertNothingVerified();
Captcha::assertVerifiedCount(2);
```

`assertVerified()` takes a matcher because counting calls proves a captcha ran, not that the right
submission was checked — a test that only counts passes just as happily when the wrong form is
protected.

`fake()` still returns the service, so existing chains are unaffected. The fake reports itself as
the null provider on purpose: that is what keeps the production guard refusing it, so a `fake()`
left in deployed code fails closed rather than accepting everything.

## Against a real provider

The vendors publish always-pass keys, and this package uses them automatically in `local` and
`testing` when a provider has no credentials — so a fresh checkout works before anyone has signed
up for anything.

Turnstile also publishes keys that always *fail* (`2x00000000000000000000AB` with
`2x0000000000000000000000000000000AA`) and one that reports an already-spent token
(`3x0000000000000000000000000000000AA`), which is the only easy way to exercise the replay path
against the real API.

Test keys are refused in production regardless of configuration.

## Running the package's own suite

```bash
composer test
vendor/bin/pest --group=live   # hits real provider endpoints, excluded by default
```

Three suites, and only one gets an application:

| Suite | Booted app | For |
|---|---|---|
| `tests/Arch` | no | layering and dependency rules |
| `tests/Unit` | no | value objects, mappers, pure logic |
| `tests/Feature` | yes | container, HTTP, views, database |

The split is what keeps the domain honest: a unit test that quietly leans on the container fails
instead of passing.

## The adapter contract suite

`tests/Feature/AdapterContractTest.php` runs over the `Provider` enum rather than over a hand-kept
list, so **a new adapter is covered the moment it is added** and cannot ship without failing closed
on a connection error, a 500, a 403, an HTML body, and valid JSON with no `success` field.

That last case is the dangerous one: `$body['success'] ?? true` would pass it, and that is a
one-character mistake away in every adapter.

If you add an adapter and the suite fails, the adapter is wrong — the suite encodes the contract.

## The documented-claims guard

`tests/Feature/DocumentedClaimsTest.php` asserts that everything the repo *names* exists: publish
tags, Artisan commands, provider keys, config keys and test groups, checked against the code in both
directions where both directions matter.

It exists because four claims shipped in v0.1.0 that were simply untrue — a `suggest` entry for a
runtime requirement that did not exist, a docblock citing tests nobody had written, a CI flag
excluding a group no test carried, and a documented group flag that ran nothing. Each fails only for
a reader: `vendor:publish` with an unknown tag exits successfully and publishes nothing, and Pest
given a group no test carries exits successfully having run nothing.

Add a claim to the docs and this test tells you if it is false — including, as it happens, the first
draft of this very section, which named a made-up group as an example and was rejected for it. It
cannot check prose ("asserted by", "the suite covers"), so those remain a matter of care.

## The runtime is executed, not just emitted

Most client-side tests here assert the script *contains* the right things. That is not the same as
the script working — a typo inside the IIFE, a missing brace, a reference to a variable that does
not exist all pass a string assertion and fail only in a visitor's browser, where nothing reports
it.

`tests/Feature/RuntimeScriptTest.php` closes that. It extracts the emitted runtime, runs
`node --check` over it, then executes it against a hand-rolled DOM in `tests/js/harness.mjs` and
asserts the behaviour: it initialises, an expired token is cleared, the vendor widget is reset,
re-initialisation is idempotent, and an expired self-hosted question is refetched and swapped in.

No jsdom and no npm install. The runtime touches about a dozen DOM APIs, and stubbing those is
cheaper to keep than a JavaScript toolchain inside a PHP package. Where Node is absent the tests
skip *loudly* — a green run that never executed the script is the false confidence this exists to
remove.

Verified by breaking it on purpose: a syntax error, a behavioural change, an undefined reference
and a disabled refresh path each fail these tests.

What it does not cover is structural rather than incidental. The stub is faithful to the APIs the
runtime calls, not to a browser, and two of those stubs are empty: `MutationObserver.observe()` does
nothing, and there is no Livewire at all. So the re-initialisation path and the morph skip — between
them, everything that makes this work under Livewire, `wire:navigate` and Alpine — are executed by
nothing in that harness. The browser suite below covers exactly that.

## The browser suite

`vendor/bin/pest --group=browser` renders the component to real HTML, then drives it in Chromium
via Playwright. Excluded by default and run as its own CI job, because it needs a browser download.

```bash
npm --prefix tests/Browser install
npx --prefix tests/Browser playwright install chromium
vendor/bin/pest --group=browser
```

Twelve tests, and each is something the Node harness structurally cannot check:

| Area | What it proves |
|---|---|
| The rendered markup | `data-captcha-config` parses in a real parser; the token field really is `{id}-token`; `aria-describedby` resolves to an element that exists |
| The `MutationObserver` | A container inserted after load is initialised; so is one nested in an inserted subtree; removal clears the ready flag so a re-inserted node initialises again |
| The Livewire morph hook | A hosted widget is skipped, so a re-render cannot discard a solved iframe; a self-hosted challenge is *not* skipped, so its question is never frozen past its server-side expiry; unrelated elements morph normally |
| Execute-on-submit | A token is minted and the form submits exactly once; a blocked vendor script still lets the visitor submit |
| Expiry | The stale token is cleared and the vendor widget is reset |

Verified by breaking the runtime four ways — detaching the observer, unregistering the Livewire
hook, applying the morph skip to self-hosted providers, and pointing `aria-describedby` at an id
that does not exist. **The Node harness reported all four green. This suite failed all four.** That
comparison is the reason the job exists; without it, the observer could be deleted outright and
every other test in the repository would still pass.

## The install job

Everything above runs against the working tree, where every file exists by definition. A consumer
gets the Composer dist — the working tree with `.gitattributes export-ignore` applied — so an ignore
rule that strips `resources/` or `config/` produces a package that is broken for every consumer
while the entire suite stays green. Nothing else here can see that.

`.github/workflows/install.yml` builds the dist with `git archive` (exactly how Composer builds one
from a tag), asserts both directions — that nothing the runtime needs was stripped, and that
`tests/`, `docs/` and `.github/` did not leak into it — then installs the result into a real Laravel
application, runs `laranail::captcha.doctor`, renders `<x-captcha />` and publishes every advertised
tag, checking the files landed. `vendor:publish` exits zero for a tag that does not exist, so each
tag is verified by its output rather than its exit code.

Verified by breaking it both ways: adding `/resources export-ignore` and removing the `tests/` one
each fail the job.

It earned its place on the first run, by finding that `doctor` exited non-zero on a default
install — the command the docs tell you to run to check an install, failing the install it was
checking. The suite had a test named *reports a healthy default install as having no problems*
that set `allowed_hostnames` before running, so it passed while the real default did not. A test
that configures its way around a defect is indistinguishable from one that covers it, right up
until someone installs the package.

### Two traps this suite had to close in itself

**Fixtures go stale silently.** Only Blade can render the pages, so they are written to
`tests/Browser/.tmp` and read from disk — which means they outlive the template that produced them.
Running `npx playwright test` directly after editing the component tests the *previous* render and
passes. That is how the four breaks above were first reported green. `global-setup.mjs` now hashes
the template and the component class into a manifest and refuses to run on a mismatch, naming the
file that changed. Run the Pest group, which regenerates before driving.

**A skipped suite exits zero.** These tests skip when Playwright is absent, so a broken install step
would leave a CI job that is green and has opened no browser. The workflow greps its own output for
a skip and fails on it.

The remaining gap is honest and small: no live vendor script is ever loaded. The suite blocks every
non-`file://` request deliberately, so it proves our runtime's behaviour, not Cloudflare's or
Google's. Live vendor endpoints are the `live` group's job.

## Two traps worth knowing

**Write fixtures through the model.** `encrypt()` serialises; the `encrypted` cast reads with
`decryptString`, which does not unserialise. A raw builder insert with `encrypt()` stores
`s:11:"db-site-key";` and reads back as that. It failed loudly here; in a seeder it would not.

**Rebuild the service after changing config.** The verification policy is read into a value object
when `CaptchaService` is constructed — which is what keeps `config()` out of the verification path
— so a test that changes captcha config must `app()->forgetInstance(CaptchaService::class)` first.

---

[← Docs index](../../README.md#documentation)

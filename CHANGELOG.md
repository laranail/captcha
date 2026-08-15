# Changelog

All notable changes to `laranail/captcha` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-15

### Added

- Initial laranail release. Adopted from `rahul900day/laravel-captcha` 5.x and substantially
  rewritten: renamed to `laranail/captcha` under `Simtabi\Laranail\Captcha\`, rebuilt on
  `laranail/package-tools`, `laranail/console`, `laranail/enumerator` and `laranail/db-tools`.
- Nine captcha adapters behind one contract, one active at a time: Turnstile, hCaptcha, reCAPTCHA
  v2, v2 invisible, v3 and Enterprise, Friendly Captcha, Arkose Labs, and self-hosted ALTCHA.
- A bot-management axis — `BotManagementAdapter`, route middleware and a DataDome adapter — for the
  per-request edge tier that form-level captcha does not cover.
- Environment-scoped credentials: per-environment config blocks resolved from `app()->environment()`,
  falling back to each provider's published test keys outside production.
- A database-backed credential store that resolves before config and degrades to it when the table
  is missing or the database is unreachable, with secrets encrypted at rest.
- `VerificationResult`, replacing the boolean return, so score, action, hostname and challenge
  timestamp are available to the caller rather than discarded.
- Artisan commands `laranail::captcha.doctor`, `.keys`, `.install` and `.cache-clear`.
- Expiry recovery: vendor widgets reset on an expired, timed-out or errored token, and the
  self-hosted providers re-fetch a fresh challenge, so a form left open no longer submits a dead
  token with no way to recover but a page reload.
- Livewire, Volt, Inertia and SPA support: one MutationObserver initialises widgets however they
  arrive, and a `morph.updating` skip stops a re-render discarding a live — possibly already
  solved — vendor widget.
- Octane support: request-scoped state is cleared at both boundaries, so a credential rotated in
  the database is picked up by a warm worker instead of waiting for a restart.
- Opt-in outcome logging with score and duration, so a reCAPTCHA v3 threshold can be chosen from
  real traffic. Off by default — captcha failures are ordinary bot traffic at volume.
- Test helpers: `Captcha::fakeScore()`, `fakeSequence()`, `assertVerified()`, `assertFailed()`,
  `assertNothingVerified()` and `assertVerifiedCount()`, recording which token was verified rather
  than only that something was.
- A conformance suite cross-checking the ALTCHA adapter against `altcha-org/altcha` in both
  directions, and a live suite hitting the real provider endpoints with their published test keys.
- The emitted widget runtime is executed in tests, not merely asserted to contain the right
  strings: `node --check` plus a dependency-free DOM harness covering init, expiry reset and
  idempotence. Verified by breaking it three ways on purpose.
- A browser suite (`--group=browser`, Playwright/Chromium, its own CI job) covering what that
  harness structurally cannot: it stubs `MutationObserver.observe()` as an empty method and has no
  Livewire, so the re-initialisation path and the morph skip were executed by nothing. Twelve tests
  drive the real rendered HTML — observer init on inserted and nested nodes, flag clearing on
  removal, the morph skip applying to hosted providers but not self-hosted ones, execute-on-submit,
  and expiry. Detaching the observer, unregistering the Livewire hook, applying the skip to
  self-hosted providers and dangling `aria-describedby` were each tried: the Node harness reported
  all four green and this suite failed all four.
- An install job that builds the Composer dist with `git archive`, installs it into a real Laravel
  application, boots it, runs the doctor, renders `<x-captcha />` and publishes every advertised
  tag. Every other job runs against the working tree, where every file exists by definition — an
  `export-ignore` stripping `resources/` would ship a package broken for everyone while the whole
  suite stayed green. Verified by stripping `resources/` and by un-ignoring `tests/`; it catches
  both directions. It found the doctor defect above immediately.
- A staleness guard on those fixtures. They are rendered by Blade to disk, so they outlive the
  template — a standalone Playwright run passed against a previous render, which is how the four
  breaks above were first reported green. The suite now hashes the template into a manifest and
  refuses to run on a mismatch.
- `providers.*.reset_function`, so applications using Friendly Captcha or Arkose can wire expiry
  recovery. Neither exposes a global reset — theirs live on a widget handle and an enforcement
  instance the application holds — so the package offers the seam rather than guessing a name.
- `DocumentedClaimsTest`, asserting that every publish tag, Artisan command, provider key, config
  key and test group the repo names actually exists.

### Fixed

- `laranail::captcha.doctor` failed a default install. The hostname check fired for self-hosted
  providers, which have no vendor-returned hostname to compare, so the zero-config path the README
  promises exited non-zero from the command the docs tell you to run to verify the install — and
  advised setting a key that would not have changed the outcome. The check now applies only to
  hosted providers, where a public site key genuinely does let someone host your form. Its comment
  also claimed the finding was "not fatal" while returning `FAILURE`; the check is fatal, because
  enforcement that compares nothing is a setting promising a guarantee it does not provide.
  Missed because the test named "reports a healthy default install as having no problems" set
  `allowed_hostnames` before running — it encoded the workaround rather than the default. Found by
  the install job below, on its first run.
- ALTCHA challenges omitted the trailing `&` the reference implementation appends to every salt.
  Because the salt is hashed, every challenge and signature differed from the wider ecosystem's, so
  no ALTCHA-based server could verify anything this package issued. Found by a new conformance
  suite that cross-checks against `altcha-org/altcha` in both directions — the package's own round
  trip was self-consistent and entirely wrong, which nothing else could have caught.
- reCAPTCHA v3 and v2-invisible mint their token from `grecaptcha.execute()`, so `<x-captcha />`
  now wires that execution. Previously they rendered an empty container and the form submitted with
  no token — listed as supported, and not working.
- Turnstile verification sends an idempotency key, so the one retry it is allowed is recognised as
  the same attempt rather than a second redemption. The key was previously read from a context
  field nothing ever populated, leaving the retry unreachable.

Twenty-four hardening items carried from the audit of the original package. The ones that were
exploitable:

- The `captcha` rule is now implicit, so a request that omits the captcha response fails validation.
  Previously the rule was skipped entirely for a missing field and the request passed untouched.
- A captcha response that is not a non-empty string fails validation instead of raising a
  `TypeError`.
- Widget markup is no longer rendered through Blade string compilation. `Component::render()`
  returning a string is compiled as a Blade template, and the locale was interpolated into it
  unescaped — which allowed HTML injection into the script tag, Blade injection, and an unbounded
  compiled-view write.
- Response `hostname`, `action` and `challenge_ts` are verified, closing cross-origin and
  cross-form token replay.
- Verified tokens are single-use, guarded by the cache.
- The reCAPTCHA v3 score is enforced against a threshold rather than fetched and ignored.
- hCaptcha verification uses the documented `api.hcaptcha.com` host and binds the token to the site
  key.

See [`docs/security.md`](docs/security.md) for the full list.

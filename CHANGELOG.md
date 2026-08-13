# Changelog

All notable changes to `laranail/captcha` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

### Fixed

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

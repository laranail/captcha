# Security model

What this package guarantees, how, and what it deliberately does not promise.

## The rule everything else follows

**Verification fails closed.** A transport error, a non-2xx response, a body that does not parse,
an unconfigured provider — every one of them produces a failed result. No path returns "verified"
on an error, and no adapter throws: an exception escaping verification would surface as a 500 on a
login form, which is both a worse experience and a fingerprintable oracle.

`tests/Feature/AdapterContractTest.php` asserts this for every adapter in the `Provider` enum, so a
new provider cannot ship without meeting it.

Bot management is the one exception and inverts deliberately — see [the two axes](#the-one-exception).

## A genuine token is not the same as a legitimate submission

The provider answering `success: true` means somebody, somewhere, solved a challenge. It does not
mean it was this visitor, on your site, for this form, just now. Each of the following is a real,
vendor-approved token that is still the wrong token, and each is checked:

| Check | What it stops | Default |
|---|---|---|
| Hostname | A token minted on an attacker's copy of your form. Your site key is public, so anyone can host the widget and collect real tokens. | on, but only compares when `allowed_hostnames` is set |
| Action | A token from your newsletter signup, replayed on login. | on when the caller names an action |
| Freshness | A challenge solved an hour ago and kept. | 300s |
| Replay | The same token submitted twice, including in parallel. | on |
| Score | A visitor reCAPTCHA v3 scored 0.1. `success` is true for them. | allow ≥ 0.5, review ≥ 0.3 |

Run `php artisan laranail::captcha.doctor` — it fails the build when hostname enforcement is on
with an empty allow-list, because that setting reads as protection and compares nothing.

## Credentials

- **Secrets never reach a log, an exception message, a rendered page or a dumped variable.**
  `Credentials::__debugInfo()` redacts, so `dd()`, a Whoops frame and a `failed_jobs` payload all
  print `[redacted]`. Adapters swallow transport exceptions rather than reporting them, because a
  Guzzle `RequestException` stringifies the request body — and that body carries the secret.
- **Stored secrets are encrypted at rest**, and a value written under a different `APP_KEY`
  degrades to the config store instead of raising. A key rotation must not turn every login into a
  500, because the fix for that is a migration you cannot run through an application that will not
  boot.
- **Caching credentials is off by default.** Turning it on writes a decrypted secret into whatever
  backs the cache — usually a shared Redis with weaker access control than the database it came
  from, and one whose contents appear in `MONITOR`.

## Two ways an application accepts everything while looking healthy

Both are refused in production, and both are refused loudly in the log rather than on the page:

- **The `null` provider**, which verifies everything. Allowed only with
  `providers.null.allow_in_production`, so it is a decision in writing rather than an accident.
- **The published test keys**, which also verify everything.

Production is decided by a configurable list, not by trusting `app()->environment()` alone.
`APP_ENV` is a deployment name and some products ship it as a feature flag — Worksuite reports
`codecanyon` on live installations — so a name in neither list would sail past a naive check.

## Injection

- Widget components return **views, never strings**. A Blade component whose `render()` returns a
  string has that string written to disk and compiled as a template, so an unescaped value in it is
  template injection, not just HTML injection. The package this replaced returned its script tag as
  a string with the locale interpolated into it.
- Locales are validated against a BCP-47 shape and dropped otherwise, so a caller passing user
  input straight through cannot produce anything but a language tag.
- Widget instance ids are generated, never taken from the caller, because they end up inside a CSS
  selector and a JavaScript identifier.
- The provider name resolves through a frozen enum allow-list. It arrives from a config file an
  operator edits and, in a multi-tenant install, from a database row; interpolating either into a
  class name turns a settings mistake into arbitrary instantiation.

## Self-hosted providers

ALTCHA and the math provider mint their own challenges, so they get no vendor bookkeeping for free:

- Challenges are **HMAC-signed** with a key derived from `APP_KEY` (never `APP_KEY` itself), and
  compared with `hash_equals`. Without the signature a client invents its own trivial challenge.
- **Expiry is covered by the signature.** An expiry the client can edit is no expiry.
- **One guess per challenge.** The math provider *takes* the answer from the cache on the first
  attempt, right or wrong. The answer space is a couple of hundred integers; without this, a client
  walks the range and always wins. This is the single property that separates a usable math captcha
  from a decorative one.
- The answer never leaves the server — not as a hidden field, and not as a hash, because any hash
  of a two-digit number *is* the number.
- The challenge endpoint is rate-limited. It is unauthenticated by nature and minting is the
  expensive half.

## <a name="the-one-exception"></a>The one exception: bot management fails open

Captcha guards one form, so failing closed costs one submission. Bot management runs in front of
every request, so failing closed on a provider outage takes the whole site down to stop traffic
that was probably legitimate — a self-inflicted outage in response to someone else's. DataDome's
own integration guidance says the same.

A 403 arriving *without* the `X-DataDomeResponse` header is treated as unavailable rather than as a
block: that shape is a proxy error page or a captive portal, and reading it as a verdict would
block real users during an unrelated network problem.

## What is not promised

- **The math and ALTCHA providers stop casual automation, not a targeted attacker.** An OCR
  pipeline or a human-solver farm defeats any arithmetic question, and no self-hosted scheme
  changes that. Use them where a third-party dependency is unacceptable; use a risk-scoring
  provider where the stakes are higher.
- **No captcha proves humanity.** It raises cost. Rate limiting, account lockout and anomaly
  detection are still yours to do.
- **`remoteip` is only as trustworthy as your proxy configuration.** The framework's resolved IP is
  forwarded; if `TrustProxies` is wrong, so is that value.
- **A provider's own accuracy is theirs.** This package makes the checks around their answer
  correct; it cannot make their answer better.

## Reporting

Privately to `opensource@simtabi.com`. See [SECURITY.md](../SECURITY.md) for scope.

---

[← Docs index](../README.md#documentation)

# Octane

What this package clears between requests, and what it deliberately keeps.

Nothing to configure. The listeners register themselves, and without Octane installed they are
never dispatched.

## What gets cleared

`CaptchaService` memoises its adapter and the credential chain is a singleton, so on a warm worker
a key rotated in the database stays invisible until the worker restarts — which can be hours. The
admin UI saves, nothing changes, and nothing anywhere says why.

Forgotten at each request boundary:

- `CaptchaService`
- `CredentialStore`
- `ResolveCredentials`

## What is kept

`CaptchaConfig` — the typed config reader. A memo of config reads is exactly what should stay warm
in a long-running process, and flushing it would cost every request for no correctness gain.

## Three details that matter

**Listened to by event name, not by class.** `'Laravel\Octane\Events\RequestReceived'` as a string,
so there is no `class_exists` probe and no dependency on Octane. Without it the names are simply
never dispatched.

**Both boundaries, not just termination.** A request that dies hard never reaches
`RequestTerminated`, and the next one would inherit its state.

**Nothing is resolved in order to be reset.** The listener checks `resolved()` first. Building a
singleton to clear it would construct the whole credential chain — including a database probe — at
every request boundary, on every request that never touched a captcha.

The listener also swallows `Throwable`: a reset that throws kills the worker between requests,
taking every subsequent request with it. Failing to clear one binding is recoverable; that is not.

## Verifying it

```bash
php artisan laranail::captcha.doctor
```

---

[← Docs index](../../README.md#documentation)

# Migration

From `rahul900day/laravel-captcha`, and from the `laranail/toolkit` captcha module.

## From `rahul900day/laravel-captcha` 5.x

```diff
-   "rahul900day/laravel-captcha": "^5.0"
+   "laranail/captcha": "^0.1"
```

`Rahul900day\Captcha\` becomes `Simtabi\Laranail\Captcha\`. The `Captcha` facade alias and the
`<x-captcha-js />` / `<x-captcha-container />` tags are unchanged, so your Blade needs no edits.

### Read these before deploying

**The rule is now implicit.** A request that omitted the captcha field used to skip the rule
entirely and pass validation — the reported symptom was "able to login without even using captcha".
It now fails. If you relied on that to make the captcha optional on some route, make it conditional
in the rule set instead.

**`verify()` returns a value object.** Call `->passes()` where you used the boolean.

**Hostname, action and freshness are enforced.** Tokens minted on another origin, for another form,
or older than the window are rejected. If a legitimate flow breaks, widen `allowed_hostnames`
rather than disabling the check — an empty list already disables the comparison, and the doctor
command will tell you it has.

**reCAPTCHA v3 scores are enforced** against a threshold. They used to be fetched and ignored, so a
visitor scoring 0.1 passed.

**`CaptchaRule::$message` is gone.** It was a mutable static shared by every rule instance: a test
that customised it changed the message for every later test, and an application that customised it
in a service provider changed it globally. Messages now come from `resources/lang/en/validation.php`
keyed by error code — publish and edit them.

**PHP 8.4.1 and Laravel 13 only.** Stay on `rahul900day/laravel-captcha` if you need the wider
range.

### Field names

Forms can keep posting the vendor's field name (`cf-turnstile-response` and friends) — it is still
accepted. New forms should bind to `captcha`, which is what makes switching provider a config
change rather than an edit to every form.

## From the `laranail/toolkit` captcha module

The module was relocated here; toolkit no longer registers a `Captcha` alias, so the two no longer
collide. Both packages must move together — an install where both register the alias is a hard
failure.

```bash
composer require laranail/captcha
```

- **`Toolkit::captcha()` is removed.** Use the `Captcha` facade.
- **`config('laranail.toolkit.captcha.*')` becomes `config('laranail.captcha.*')`**, with
  per-provider credentials under an environment block. Run
  `php artisan laranail::captcha.install` and port your keys.
- **`CaptchaVerificationResult` becomes `VerificationResult`.** The fail-closed contract it
  documented is unchanged and is now asserted for every adapter.
- **Five providers became eleven**, and `null` is refused in production unless you say otherwise in
  config.

## Verifying the move

```bash
php artisan laranail::captcha.doctor
```

Confirms the active provider, where its credentials resolve from, and flags anything that would
quietly accept every submission.

---

[← Docs index](../README.md#documentation)

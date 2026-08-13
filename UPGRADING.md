# Upgrade guide

## Upgrading to `laranail/captcha` 0.1 from `rahul900day/laravel-captcha` 5.x

This is a rename plus a rewrite. Read [`docs/migration.md`](docs/migration.md) for the mechanical
steps; this page covers what changes behaviourally.

### Minimum versions

- PHP `^8.4.1 || ^8.5` (was `^8.2`)
- Laravel `^13.0` (was `^10.0 || ^11.0 || ^12.0 || ^13.0`)

### Composer

```diff
-   "rahul900day/laravel-captcha": "^5.0"
+   "laranail/captcha": "^0.1"
```

Add the laranail VCS repositories — this family does not resolve through Packagist. See
[`docs/installation.md`](docs/installation.md).

### Namespace

`Rahul900day\Captcha\` becomes `Simtabi\Laranail\Captcha\`. The `Captcha` facade alias is unchanged.

### Behavioural changes you must review

- **The `captcha` rule is now implicit.** A request that omits the captcha response used to pass
  validation untouched. It now fails. If you relied on the old behaviour to make captcha optional,
  make it conditional in your rule set instead.
- **Verification returns a value object, not a `bool`.** `Captcha::verify()` returns a
  `VerificationResult`. Call `->passed()` where you previously used the boolean directly.
- **Hostname, action and challenge freshness are enforced by default.** Tokens minted on another
  origin, for another form, or older than the freshness window are now rejected. If a legitimate
  flow breaks, widen the allow-list in config rather than disabling the check.
- **reCAPTCHA v3 score is enforced.** It used to be fetched and ignored.
- **`CaptchaRule::$message` is gone.** Messages come from the translation file, keyed by error code.
- **Credentials are environment-scoped.** A single flat `CAPTCHA_SITE_KEY` still works through the
  `default` environment block.

## Upgrading from the `laranail/toolkit` captcha module

The module has been relocated here. Remove nothing from your app but the config key: the
`Captcha` facade keeps working and `toolkit` no longer registers its own.

`config('laranail.toolkit.captcha.*')` becomes `config('laranail.captcha.*')`, with per-provider
credentials moving under an environment block. Run `php artisan laranail::captcha.install` to
generate the new file, then port your keys.

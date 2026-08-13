# Credentials

Three sources, one order, and what happens when the first one is unreachable.

## The chain

Database → config → published test keys. First complete answer wins.

The order is the point: an operator changing a key in an admin UI has to beat the `.env` the
application booted with, or the admin UI is decorative.

Narrow it with `credentials.source` (`chain`, `database`, `config`) when you want one source only.

## Per environment

Each environment block is layered over `default`, so an application with one key pair writes it
once and never thinks about environments again:

```php
'environments' => [
    'default'    => ['turnstile' => ['site_key' => env('CAPTCHA_SITE_KEY'), 'secret' => env('CAPTCHA_SECRET_KEY')]],
    'production' => ['turnstile' => ['site_key' => env('CAPTCHA_PROD_SITE_KEY'), 'secret' => env('CAPTCHA_PROD_SECRET')]],
],
```

Names match exactly first, then as wildcards — `production*` covers `production`, `production-eu`
and `production-us` without listing them.

## From the database

```php
'credentials' => ['database' => ['enabled' => true, 'model' => \App\Models\Setting::class]],
```

Any model implementing `ProvidesCaptchaSettings` works — one method, and a settings model does not
need to know what a captcha provider is:

```php
public function captchaSetting(string $provider, string $key, string $environment): ?string;
```

Leave `model` null to use the shipped `CaptchaSetting` and its publishable migration. Most
applications already have somewhere to keep settings; a second settings table is how a package ends
up ignored.

### It degrades rather than throwing

Reads go through `laranail/db-tools`' availability guard, so three situations that would otherwise
break every request are answered with "nothing here" and no log line: a fresh clone before
`migrate`, a CI container with no database, and the `migrate` run itself — where the table being
read does not exist yet and the boot running the migration is the boot that would query it.

A value written under a different `APP_KEY` also degrades to config rather than raising. A key
rotation must not turn every login into a 500, because the fix for that is a migration you cannot
run through an application that will not boot.

### When a row is missing

`row_absent_means` decides:

- `fall_through` (default) — treat it as unset and continue to config.
- `disabled` — treat it as deliberate and stop. Use this when the table is the source of truth, so
  an operator deleting a row to turn a provider off does not find it still working, quietly served
  by a stale `.env` secret.

### Caching is off by default

Turning it on writes a decrypted secret into whatever backs the cache — usually a shared Redis with
weaker access control than the database it came from, and one whose contents appear in `MONITOR`. A
settings lookup is one indexed query. If you do enable it, `laranail::captcha.cache-clear` makes a
database change apply immediately, and a cache outage falls through to the database rather than
failing.

## Test keys

Each vendor's published always-pass keys, used in `local` and `testing` when a provider has no
credentials, so a fresh checkout works before anyone has signed up for anything.

They verify **everything**, so they are refused in production regardless of configuration — and
production is decided by a configurable list rather than by trusting `app()->environment()` alone.

## Seeing what resolved

```bash
php artisan laranail::captcha.keys
php artisan laranail::captcha.doctor
```

`keys` answers the question a config file cannot: not "is a key set", but "which of the three
sources is serving it right now". Secrets are never printed and site keys are truncated.

---

[← Docs index](../README.md#documentation)

# Installation

Requirements, how this family resolves, and what publishing actually gets you.

## Requirements

- PHP `^8.4.1 || ^8.5`
- Laravel `^13.0`

The `8.4.1` floor is inherited from `laranail/console`, which this package uses for its Artisan
command base.

## Install

```bash
composer require laranail/captcha
```

You are now protected. The default provider is self-hosted arithmetic — no account, no keys, no
third-party request — so there is no step between installing and working.

## Why it does not come from Packagist

The laranail family resolves inter-package dependencies through git VCS repositories. The entries
are already declared in this package's `composer.json`, so a plain `composer require` works.

If your root `composer.json` needs them explicitly — Composer ignores a dependency's own
`repositories` — declare the full transitive closure:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/laranail/console" },
    { "type": "vcs", "url": "https://github.com/laranail/db-tools" },
    { "type": "vcs", "url": "https://github.com/laranail/enumerator" },
    { "type": "vcs", "url": "https://github.com/laranail/package-tools" },
    { "type": "vcs", "url": "https://github.com/laranail/captcha" }
]
```

## Publishing

Optional, and worth understanding before you run it.

```bash
php artisan laranail::captcha.install
```

Publishes `config/laranail/captcha.php`. Do this when you want to change something — pick a
different provider, set hostnames, adjust a score threshold. An unpublished install uses the
package defaults, which are complete.

```bash
php artisan laranail::captcha.install --migrations
```

Also publishes the `captcha_settings` migration. Only needed if you want credentials in the
database *and* have nowhere to put them. Most applications already have a settings table: implement
`ProvidesCaptchaSettings` on that model and point
`laranail.captcha.credentials.database.model` at it instead. A second settings table is how a
package ends up ignored.

## Optional dependency

```bash
composer require --dev altcha-org/altcha
```

Not required to use the ALTCHA provider — the proof-of-work is implemented directly, so there is no
runtime dependency. The library is a dev dependency used to cross-check wire compatibility against
the reference implementation, which is a stronger guarantee than depending on it would be.

## If Composer serves you a stale version

Pre-1.0 this family keeps one `v0.1.0` tag per repository and *moves* it, so `^0.1` consumers pick
up changes on their next `composer update`. Composer caches VCS clones by ref, so a moved tag can
resolve to what you fetched last time:

```bash
composer clear-cache
composer update laranail/captcha
```

Worth knowing rather than discovering: the symptom is a package that does not contain a change you
can see in the repository.

## Verifying the install

```bash
php artisan laranail::captcha.doctor
```

Reports the active provider, where each credential resolves from, and anything that would quietly
accept every submission.

## Upgrading

From `rahul900day/laravel-captcha` or the `laranail/toolkit` captcha module, see
[Migration](migration.md) and the root [UPGRADING.md](../UPGRADING.md). The behavioural change to
review first: the validation rule is now implicit, so a request that omits the captcha fails
instead of passing.

---

[← Docs index](../README.md#documentation)

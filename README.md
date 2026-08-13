# laranail/captcha

[![Latest version on Packagist](https://img.shields.io/packagist/v/laranail/captcha.svg)](https://packagist.org/packages/laranail/captcha)
[![Tests](https://img.shields.io/github/actions/workflow/status/laranail/captcha/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/laranail/captcha/actions/workflows/tests.yml)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/laranail/captcha/static-analysis.yml?branch=main&label=static%20analysis&style=flat-square)](https://github.com/laranail/captcha/actions/workflows/static-analysis.yml)
[![License MIT](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

> One captcha contract for Laravel across eleven providers — from Cloudflare Turnstile to
> self-hosted arithmetic — with environment-scoped credentials, a database-backed settings store,
> and the token checks most integrations skip.

Requires PHP `^8.4.1` and Laravel `^13.0`.

## Install

```bash
composer require laranail/captcha
```

That is the whole setup. The default provider is self-hosted: no account, no keys, no third-party
request, no JavaScript. Publish the config only when you want to change something —
`php artisan laranail::captcha.install`.

This family resolves through git VCS repositories rather than Packagist — deliberately, and the
version badge above points at the git tag for that reason. The repository entries are already in
`composer.json`. See [Installation](docs/installation.md).

## Quick start

```blade
<form method="post" action="/register">
    @csrf

    <x-captcha />

    <button type="submit">Create account</button>
</form>
```

```php
$request->validate([
    'email' => ['required', 'email'],
    'captcha' => ['captcha'],
]);
```

Switching to Turnstile later is one config line. The markup and the rule do not change.

## The two axes

Captcha and bot management solve different problems and fail in opposite directions.

| | Captcha | Bot management |
|---|---|---|
| Runs | on one form, at submit | on every request, at the edge |
| Asks | did a human solve a challenge | does this connection look automated |
| Providers | Turnstile, hCaptcha, reCAPTCHA v2/v2-invisible/v3/Enterprise, Friendly Captcha, Arkose, ALTCHA, math | DataDome |
| On provider outage | **fails closed** — letting a submission through defeats the point | **fails open** — blocking every request to stop some traffic is a self-inflicted outage |

One captcha provider is active at a time, chosen by config and resolved through a frozen enum
allow-list. Bot management is separate middleware you opt into.

## <a name="documentation"></a>Documentation

The hosted copy at
[opensource.simtabi.com](https://opensource.simtabi.com/documentation/laranail/captcha/) is the
canonical home; every link below also renders on GitHub.

### Guides

- [Installation](docs/installation.md) — requirements, VCS repositories, what publishing gets you
- [Getting started](docs/getting-started.md) — protected form to verified submission in one page
- [Configuration](docs/configuration.md) — every block, and what each one changes
- [Providers](docs/providers.md) — the eleven, what each is good at, and what each costs you
- [Credentials](docs/credentials.md) — database, config and test keys, and the order they resolve in
- [Architecture](docs/architecture.md) — ports, adapters, actions, and why the layering is enforced
- [Security model](docs/security.md) — what is guaranteed, how, and what is not promised
- [Migration](docs/migration.md) — from `rahul900day/laravel-captcha` and from `laranail/toolkit`
- [Release](docs/release.md) — versioning and what CI gates

### Reference

- [Turnstile](docs/tools/turnstile.md) · [hCaptcha](docs/tools/hcaptcha.md) · [reCAPTCHA](docs/tools/recaptcha.md) — the hosted big three
- [Friendly Captcha](docs/tools/friendly-captcha.md) · [Arkose Labs](docs/tools/arkose.md) — EU-hosted, and enterprise
- [ALTCHA](docs/tools/altcha.md) · [Math](docs/tools/math.md) — self-hosted, no account, no third party
- [Blade components](docs/tools/blade-components.md) — the one tag, the two tags, and CSP nonces
- [Validation rule](docs/tools/validation-rules.md) — why it is implicit, and which field it reads
- [Commands](docs/tools/commands.md) — `doctor`, `keys`, `install`, `cache-clear`
- [Bot management](docs/tools/bot-management.md) — the edge tier, and why it fails open
- [Testing](docs/tools/testing.md) — faking it in your app, and the adapter contract suite
- [Octane](docs/tools/octane.md) — what is cleared between requests, and what is kept

### Recipes

- [Use your own settings model](docs/recipes/use-your-own-settings-model.md)
- [Ask for a second factor instead of rejecting](docs/recipes/step-up-on-a-middling-score.md)
- [Protect a Livewire form](docs/recipes/protect-a-livewire-form.md)
- [Add a custom provider](docs/recipes/add-a-custom-provider.md)

### Project

- [Changelog](CHANGELOG.md)
- [Upgrade guide](UPGRADING.md)
- [Contributing](CONTRIBUTING.md)
- [Security policy](SECURITY.md)

## Stability

Pre-1.0. The public surface — the `Captcha` facade, the `captcha` rule, the Blade components and
the `CaptchaAdapter` port — is settled and covered by tests. Internals may still move.

## Local development

```bash
composer install
composer test     # pest
composer lint     # pint, phpstan (level max), rector
```

The `live` group hits real provider endpoints with their published test keys and is excluded by
default: `vendor/bin/pest --group=live`.

## Sister packages

Part of the [laranail](https://github.com/laranail) family of Laravel package tools. The captcha
module that used to live in `laranail/toolkit` was relocated here.

## Community

Questions and ideas belong in
[Discussions](https://github.com/laranail/captcha/discussions); bugs in
[Issues](https://github.com/laranail/captcha/issues).

## Contributing & security

See [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities privately to
`opensource@simtabi.com` — see [SECURITY.md](SECURITY.md), and never in a public issue.

## License

MIT — see [LICENSE](LICENSE). Copyright (c) 2026 Simtabi LLC. Originally
`rahul900day/laravel-captcha`, copyright (c) Rahul Dey.

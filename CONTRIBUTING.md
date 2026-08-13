# Contributing

Thanks for helping. This package sits on the anti-abuse path, so the bar for changes is a little
higher than usual — read the security section before opening a pull request.

## Getting set up

```bash
composer install
vendor/bin/pest
```

The laranail family resolves through git VCS repositories rather than Packagist; they are already
declared in `composer.json`, so a plain `composer install` works.

## Before you push

```bash
composer lint   # pint --test, phpstan, rector --dry-run
composer test
```

All three gate CI. `composer pint-fix` and `composer rector-fix` apply the mechanical fixes.

## Conventions

- PHP `^8.4.1`, Laravel `^13`. `declare(strict_types=1)` in every file.
- Artisan commands are named `laranail::captcha.<command>` and extend the laranail console base.
- **Constructor injection everywhere.** `Actions/`, `Services/`, `Adapters/` and `Support/` may not
  call `config()`, `env()`, `request()`, `app()`, `Cache::` or any `Illuminate\Support\Facades\*`.
  `tests/Arch` enforces this and will fail the build. The package this one replaces read
  `config('captcha.sitekey')` inline inside a view component, which is how its credential layer
  became impossible to test or override.
- `env()` appears only in `config/captcha.php`. Anywhere else it returns null under `config:cache`.
- New provider support is an adapter under `src/Adapters/<Vendor>/` plus an entry in the `Provider`
  enum's allow-list. Adapters are never resolved by string interpolation.

## Tests

Pest, in three suites:

| Suite | Booted application? | For |
|---|---|---|
| `tests/Arch` | no | layering and dependency rules |
| `tests/Unit` | no | value objects, mappers, pure logic |
| `tests/Feature` | yes | anything touching the container, HTTP, views or the database |

Only `Feature` gets an application. That split is what keeps the domain honest: a unit test that
quietly leans on the container fails instead of passing.

Every adapter must pass the shared contract suite in `tests/Feature/AdapterContractTest.php`. If you
add an adapter and the suite fails, the adapter is wrong — the suite encodes the fail-closed rule.

The `live` group hits real provider endpoints with their published test keys and is excluded by
default: `vendor/bin/pest --group=live`.

## Security-relevant changes

A change is security-relevant if it touches verification, credential resolution, replay protection,
the widget markup, or the challenge endpoint. For those:

1. Write the failing test first. Every hardening item in `docs/security.md` has one.
2. Never let a failure path return "verified". Transport errors, non-2xx responses and malformed
   bodies all mean *not verified*.
3. Never interpolate a secret into an exception message, a log line, or rendered output.

Report vulnerabilities privately to `opensource@simtabi.com` — see [SECURITY.md](SECURITY.md), never
in a public issue or pull request.

# Release

Versioning, what CI gates, and the steps to cut a tag.

## Versioning

Semantic versioning. Pre-1.0 the public surface — the `Captcha` facade, the `captcha` rule, the
Blade components, the `CaptchaAdapter` port and the `Provider` enum — is settled and covered by
tests; internals may still move.

Following the laranail pre-stable convention, one `v0.1.0` tag is kept and moved as the package
changes, and consumers on `^0.1` pick it up on their next `composer update`.

## What CI gates

Every one of these must be green before a tag:

| Workflow | Job | Gate |
|---|---|---|
| `tests.yml` | `test` | Pest on PHP 8.4 and 8.5, Laravel 13, prefer-stable and prefer-lowest |
| | `test-without-optional` | The suite with `altcha-org/altcha` removed, proving no hard dependency crept out of the ALTCHA adapter |
| | `boot-health` | Every binding registered and the validation rule implicit after a normal boot |
| `static-analysis.yml` | `phpstan` | Level max, `src` only |
| | `style` | Pint |
| | `rector` | No pending refactorings |
| | `architecture` | `tests/Arch` — no facades, `config()`, `env()`, `request()` or `app()` below the framework layer |
| | `composer-validate` | `composer validate --strict --no-check-lock` |
| `security.yml` | `audit` | `composer audit`, also weekly |

The boot-health job exists because a captcha package that silently fails to register its validation
rule produces an application that starts cleanly, logs nothing and accepts every submission — no
functional test catches that, because every test that would notice is testing something else.

Locally: `composer test` and `composer lint`.

## Cutting a release

1. Update `CHANGELOG.md` — the release body is extracted from that version's section, so a release
   without one ships with an empty description.
2. Confirm the full gate is green.
3. Tag `vX.Y.Z` and push. `release.yml` builds a CycloneDX SBOM, extracts the CHANGELOG section and
   publishes the GitHub release.

## Coordinated releases

The captcha module was relocated out of `laranail/toolkit`. Both packages register a `Captcha`
alias in their pre-relocation versions, so **they must be released together** — an application that
installs a new captcha alongside an old toolkit gets two packages fighting over one alias, which is
a hard failure rather than a degraded one.

## Not tracked

`composer.lock` is gitignored. This is a library: a lock records a resolution consumers never use
and goes stale invisibly, because CI resolves fresh. That is why `composer validate` runs with
`--no-check-lock`.

---

[← Docs index](../README.md#documentation)

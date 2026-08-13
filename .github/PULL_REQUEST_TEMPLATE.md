## What this changes

<!-- One or two sentences. Link the issue if there is one. -->

## Why

<!-- The problem this solves. -->

## Checklist

- [ ] `composer lint` passes (pint, phpstan, rector)
- [ ] `composer test` passes
- [ ] New behaviour has tests; a bug fix has a test that fails without the fix
- [ ] `Actions/`, `Services/`, `Adapters/` and `Support/` take their dependencies by injection — no
      `config()`, `env()`, `request()`, `app()`, `Cache::` or facades
- [ ] A new adapter passes `tests/Feature/AdapterContractTest.php` and is registered in the
      `Provider` enum allow-list
- [ ] New Artisan commands follow `laranail::captcha.<command>`
- [ ] CHANGELOG.md updated
- [ ] Docs updated if behaviour or configuration changed

## Security

- [ ] No failure path can return a verified result
- [ ] No secret reaches a log line, exception message, cache entry, or rendered page

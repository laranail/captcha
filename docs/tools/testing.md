# Testing

How to test an application that uses this package, and how the package tests itself.

## In your application

```php
use Simtabi\Laranail\Captcha\Facades\Captcha;

Captcha::fake();                 // every submission verifies
Captcha::fake(verifies: false);  // none do
Captcha::forgetAdapter();        // back to the configured provider
```

`fake()` swaps in the null adapter for the rest of the test. It is guarded the same way as
everything else — the production check runs on the swapped adapter too, so a `fake()` left in a
deployed code path fails closed rather than silently disabling protection.

```php
it('rejects a registration without a captcha', function () {
    Captcha::fake();

    $this->post('/register', ['email' => 'a@b.test'])
        ->assertSessionHasErrors('captcha');
});
```

That test passes because the rule is implicit. If it ever starts failing, the bypass is back.

## Against a real provider

The vendors publish always-pass keys, and this package uses them automatically in `local` and
`testing` when a provider has no credentials — so a fresh checkout works before anyone has signed
up for anything.

Turnstile also publishes keys that always *fail* (`2x00000000000000000000AB` with
`2x0000000000000000000000000000000AA`) and one that reports an already-spent token
(`3x0000000000000000000000000000000AA`), which is the only easy way to exercise the replay path
against the real API.

Test keys are refused in production regardless of configuration.

## Running the package's own suite

```bash
composer test
vendor/bin/pest --group=live   # hits real provider endpoints, excluded by default
```

Three suites, and only one gets an application:

| Suite | Booted app | For |
|---|---|---|
| `tests/Arch` | no | layering and dependency rules |
| `tests/Unit` | no | value objects, mappers, pure logic |
| `tests/Feature` | yes | container, HTTP, views, database |

The split is what keeps the domain honest: a unit test that quietly leans on the container fails
instead of passing.

## The adapter contract suite

`tests/Feature/AdapterContractTest.php` runs over the `Provider` enum rather than over a hand-kept
list, so **a new adapter is covered the moment it is added** and cannot ship without failing closed
on a connection error, a 500, a 403, an HTML body, and valid JSON with no `success` field.

That last case is the dangerous one: `$body['success'] ?? true` would pass it, and that is a
one-character mistake away in every adapter.

If you add an adapter and the suite fails, the adapter is wrong — the suite encodes the contract.

## Two traps worth knowing

**Write fixtures through the model.** `encrypt()` serialises; the `encrypted` cast reads with
`decryptString`, which does not unserialise. A raw builder insert with `encrypt()` stores
`s:11:"db-site-key";` and reads back as that. It failed loudly here; in a seeder it would not.

**Rebuild the service after changing config.** The verification policy is read into a value object
when `CaptchaService` is constructed — which is what keeps `config()` out of the verification path
— so a test that changes captcha config must `app()->forgetInstance(CaptchaService::class)` first.

---

[← Docs index](../../README.md#documentation)

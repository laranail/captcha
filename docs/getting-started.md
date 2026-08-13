# Getting started

A protected form and a verified submission, in one page.

## The short version

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

That works on a fresh install with nothing configured. The default provider is self-hosted
arithmetic: no account, no keys, no third-party request, no JavaScript.

## Why the rule needs no `required`

The `captcha` rule is **implicit**, so it runs even when the field is missing from the request.
That matters more than it sounds: a non-implicit rule is skipped entirely for an absent field, and
omitting the field is exactly what an attacker does. The package this replaced was non-implicit,
and the resulting bypass was reported as "able to login without even using captcha".

Pairing it with `required` is harmless — Laravel stops validating an attribute once an implicit
rule on it has failed, so you get one message rather than two.

## Binding a token to a form

Give the rule an action and the provider's echo of it is checked, so a token minted for your
newsletter signup cannot be replayed on login:

```php
use Simtabi\Laranail\Captcha\Rules\Captcha;

$request->validate([
    'captcha' => [Captcha::for('login')],
]);
```

## Verifying outside a form

```php
use Simtabi\Laranail\Captcha\Facades\Captcha;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;

$result = Captcha::verify($token, new VerificationContext(
    action: 'login',
    remoteIp: $request->ip(),
));

if (! $result->passes()) {
    return back()->withErrors(['captcha' => __($result->firstError()?->translationKey())]);
}
```

`verify()` returns a `VerificationResult`, not a boolean — because a boolean cannot carry a score,
the hostname a challenge was solved on, or the action it was minted for, and an integration that
cannot see those cannot check them.

## Using the score instead of only the verdict

Score-based providers answer in three bands. Blocking the middle one rejects visitors who are
probably real; that band is where a second factor belongs.

```php
use Simtabi\Laranail\Captcha\Enums\Outcome;

match ($result->outcome) {
    Outcome::Allow  => $this->logIn($user),
    Outcome::Review => $this->requireSecondFactor($user),
    Outcome::Block  => back()->withErrors(['captcha' => 'Please try again.']),
};
```

## Switching provider

One line, and nothing in your forms changes:

```dotenv
CAPTCHA_PROVIDER=turnstile
CAPTCHA_SITE_KEY=0x4AAA...
CAPTCHA_SECRET_KEY=0x4AAA...
```

Forms bind to the canonical `captcha` field, and the widget writes it whichever provider is active.
The vendor's own field name is still accepted, so markup written against another integration keeps
working.

In `local` and `testing`, a provider with no keys falls back to that vendor's published test keys,
so a fresh checkout works before anyone has signed up for anything. Those keys are refused in
production.

## Testing your own application

```php
use Simtabi\Laranail\Captcha\Facades\Captcha;

Captcha::fake();               // every submission verifies
Captcha::fake(verifies: false); // none do
```

## Checking it is right

```bash
php artisan laranail::captcha.doctor
```

It reports the active provider, which of the three sources each credential resolves from, and exits
non-zero on anything that would quietly accept every submission — which makes it usable as a deploy
gate.

---

[← Docs index](../README.md#documentation)

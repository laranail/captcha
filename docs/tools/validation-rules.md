# Validation rule

One rule, two forms, and one property that matters more than the rest.

```php
$request->validate(['captcha' => ['captcha']]);
```

```php
use Simtabi\Laranail\Captcha\Rules\Captcha;

$request->validate(['captcha' => [new Captcha]]);
$request->validate(['captcha' => [Captcha::for('login')]]);
```

## It is implicit

A non-implicit rule is **skipped entirely when the field is absent from the request**. Omitting the
field is exactly what an attacker does, and the package this replaces was non-implicit — the
resulting bypass was reported as "able to login without even using captcha" and closed as user
error.

Both forms are implicit. `tests/Feature/Security/ValidationBypassTest.php` asserts it for each,
because an application can reach the rule either way.

## Pairing with `required`

Harmless. Laravel stops validating an attribute once an implicit rule on it has failed, so
`['required', 'captcha']` on a missing field produces one message rather than two.

## Binding to an action

```php
Captcha::for('login')
```

The provider's echo of the action is checked, so a token minted for your newsletter signup cannot
be replayed on login. Only meaningful for providers that carry actions.

## Which field

The rule reads the attribute it is attached to. If that is empty it looks for the vendor's own
field name, and then for the two fields a server-rendered challenge posts — so a form written
against another integration, or one with no JavaScript, is still protected rather than silently
rejected.

Bind new forms to `captcha`. That is what makes switching provider a config change rather than an
edit to every form in the application.

## Messages

From `resources/lang/en/validation.php`, keyed by error code. Publish and edit:

```bash
php artisan vendor:publish --tag=laranail::captcha-translations
```

The wording is deliberately uninformative about *why*. "Solved on the wrong host" and "replayed"
are precise, useful in a log, and a free oracle for someone probing the protection — so those
distinctions stay in the result object and the event, not on the page.

There is no mutable static message. The original had one shared by every rule instance: a test that
customised it changed the message for every later test in the run, and an application that
customised it in a service provider changed it globally.

## Non-string values

Anything that is not a non-empty string fails without a round trip to the provider. That case used
to raise a `TypeError` — the value arrived as null, went into a `string` parameter under strict
types, and surfaced as a 500 on the login form rather than a validation failure.

---

[← Docs index](../../README.md#documentation)

# Blade components

Three components. Most forms need only the first.

| Tag | Renders |
|---|---|
| `<x-laranail-captcha::captcha />` | Everything — script and widget, or a server-rendered question |
| `<x-laranail-captcha::js />` | The active provider's script tag alone |
| `<x-laranail-captcha::container />` | The widget mount point alone |

## `<x-laranail-captcha::captcha />`

```blade
<form method="post" action="/register">
    @csrf
    <x-laranail-captcha::captcha />
    <button type="submit">Create account</button>
</form>
```

Attributes: `theme`, `size`, `lang`, `nonce`, `label`. Anything else is merged onto the container.

For a self-hosted provider this renders a question, an answer box and a signed hidden field — no
script, working with JavaScript disabled. For a hosted provider it renders the vendor's script tag
and widget div.

The split exists because asking someone to place two tags correctly is the difference between a
package that gets used and one that gets copied wrong from Stack Overflow.

## `<x-laranail-captcha::js />` and `<x-laranail-captcha::container />`

For layouts that want the script in `<head>` and the widget further down:

```blade
<head>
    <x-laranail-captcha::js lang="fr" nonce="{{ $nonce }}" />
</head>
<body>
    <form method="post">
        @csrf
        <x-laranail-captcha::container theme="dark" size="compact" />
    </form>
</body>
```

## Deprecated bare tags

`<x-captcha />`, `<x-captcha-js />` and `<x-captcha-container />` are the tags the package
documented before 0.1, and they still render the same components. They are deprecated aliases,
removed no earlier than the next minor after 0.1: Blade's component aliases are one flat,
host-owned map, so a bare `captcha` tag is one sibling package away from being silently replaced.
Each raises one `E_USER_DEPRECATED` notice, when a template using it compiles. Replace them with
the `laranail-captcha::` tags above.

## Providers with nothing to click

reCAPTCHA v3 and v2-invisible mint their token from `grecaptcha.execute()` rather than from a
checkbox. `<x-laranail-captcha::captcha />` handles that: it intercepts the enclosing form's submit once, mints the
token into a hidden `captcha` field and replays the submit.

That is why the all-in-one tag is worth preferring. `<x-laranail-captcha::container />` alone renders an empty
div for those two providers, and the form submits with no token — the failure looks like the captcha
simply not working, with nothing in the logs.

If the vendor script is blocked, the submit is released without a token rather than trapping the
visitor in a form that can never submit. Verification then fails server-side, which is the correct
outcome.

## Two widgets on one page

Each instance generates its own id, so two forms on one page work. The original implementation had
no ids and its callback reached for `document.querySelector('.cf-turnstile')`, which finds the first
widget regardless of which form is being submitted.

Ids are generated rather than accepted from the caller, because they end up inside a CSS selector
and a JavaScript identifier.

## Content Security Policy

```blade
<x-laranail-captcha::captcha :nonce="$nonce" />
```

Emitted on the script tag, so a strict CSP does not need `unsafe-inline`.

## Why these return views

A Blade component whose `render()` returns a *string* has that string written to disk and compiled
as a template. The original returned its script tag as a string with the locale interpolated into
it unescaped, which made `<x-captcha-js :lang="$request->input('lang')" />` an HTML injection into
the script tag, a Blade injection through `{{ }}` and `@php`, and an unbounded compiled-view write —
one file per distinct input.

These return views. Locales are also validated against a BCP-47 shape and dropped otherwise, so a
caller passing user input straight through cannot produce anything but a language tag.

---

[← Docs index](../../README.md#documentation)

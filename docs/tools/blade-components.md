# Blade components

Three components. Most forms need only the first.

| Tag | Renders |
|---|---|
| `<x-captcha />` | Everything — script and widget, or a server-rendered question |
| `<x-captcha-js />` | The active provider's script tag alone |
| `<x-captcha-container />` | The widget mount point alone |

## `<x-captcha />`

```blade
<form method="post" action="/register">
    @csrf
    <x-captcha />
    <button type="submit">Create account</button>
</form>
```

Attributes: `theme`, `size`, `lang`, `nonce`, `label`. Anything else is merged onto the container.

For a self-hosted provider this renders a question, an answer box and a signed hidden field — no
script, working with JavaScript disabled. For a hosted provider it renders the vendor's script tag
and widget div.

The split exists because asking someone to place two tags correctly is the difference between a
package that gets used and one that gets copied wrong from Stack Overflow.

## `<x-captcha-js />` and `<x-captcha-container />`

For layouts that want the script in `<head>` and the widget further down:

```blade
<head>
    <x-captcha-js lang="fr" nonce="{{ $nonce }}" />
</head>
<body>
    <form method="post">
        @csrf
        <x-captcha-container theme="dark" size="compact" />
    </form>
</body>
```

These are the tags the package has always documented, so markup written against the original
integration keeps working — the migration is a namespace change rather than a sweep through every
Blade file.

## Two widgets on one page

Each instance generates its own id, so two forms on one page work. The original implementation had
no ids and its callback reached for `document.querySelector('.cf-turnstile')`, which finds the first
widget regardless of which form is being submitted.

Ids are generated rather than accepted from the caller, because they end up inside a CSS selector
and a JavaScript identifier.

## Content Security Policy

```blade
<x-captcha :nonce="$nonce" />
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

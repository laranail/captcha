# Friendly Captcha

Invisible proof-of-work, EU-hosted, cookie-free. The one to pick when data residency is the
deciding factor.

| | |
|---|---|
| Key | `friendly-captcha` |
| Verify endpoint | `https://eu.frcapi.com/api/v2/captcha/siteverify` (default) or `https://global.frcapi.com/...` |
| Credentials | `site_key`, `secret` (sent as `X-API-Key`) |

Defaults to the EU endpoint, because routing through `global.` by default would give away the
property the provider was chosen for. Set `providers.friendly-captcha.endpoint` to `global` to
change it.

## It departs from the siteverify family

Three ways: the secret travels in an `X-API-Key` header rather than the body, the request is JSON
rather than form-encoded, and failures come back as a single structured `error` object rather than
an `error-codes` array. `error_code` values map onto `ErrorCode` — `response_timeout` and
`response_duplicate` to `ExpiredOrDuplicate`, `auth_invalid` to `InvalidSecret`.

## Expiry recovery

This provider's reset is not a global function, so the package cannot call it for you.

```js
// Wherever you create the widget, expose a reset the package can name.
const widget = sdk.createWidget({ element: document.querySelector('.frc-captcha'), sitekey });

window.resetFriendlyCaptcha = () => widget.reset();
```

```php
'providers' => ['friendly-captcha' => ['reset_function' => 'resetFriendlyCaptcha']],
```

Without it, an expired token means a page reload. The package ships no default here on purpose:
inventing a global name would resolve to nothing and produce a callback that silently does not
reset, which is worse than no reset because it looks handled.

---

[← Docs index](../../README.md#documentation)

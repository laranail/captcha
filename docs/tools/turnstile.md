# Turnstile

Cloudflare's captcha. Free at any volume, invisible for most visitors, and the best default when a
third-party dependency is acceptable.

| | |
|---|---|
| Key | `turnstile` |
| Verify endpoint | `https://challenges.cloudflare.com/turnstile/v0/siteverify` |
| Script | `https://challenges.cloudflare.com/turnstile/v0/api.js` |
| Credentials | `site_key`, `secret` |
| Token lifetime | 300 seconds |

## Widget attributes

`data-theme`, `data-size`, `data-action`, and `data-language` — the last of which the package this
replaced documented and never emitted, so the locale setting did nothing for its default provider.

## The one retry in the package

Turnstile is the only adapter that retries a failed verification, because it is the only one where
a retry is safe: an `idempotency_key` tells Cloudflare a repeat is the same attempt rather than a
second redemption. The key is derived from the token hash, so two processes racing the same
submission collapse to one redemption instead of one succeeding and one getting
`timeout-or-duplicate`.

Every other adapter sends a token that is single-use with no way to say "this is the same attempt",
where a retry converts a recovered network blip into a rejected visitor.

## Error codes

`timeout-or-duplicate` maps to `ExpiredOrDuplicate`; `invalid-input-secret` and
`missing-input-secret` to `InvalidSecret`. The mapping is checked against the live API by the
`live` test group, using Cloudflare's always-fail and already-spent test secrets.

---

[← Docs index](../../README.md#documentation)

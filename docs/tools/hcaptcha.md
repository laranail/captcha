# hCaptcha

Higher catch rate than Turnstile, with paid enterprise tiers.

| | |
|---|---|
| Key | `hcaptcha` |
| Verify endpoint | `https://api.hcaptcha.com/siteverify` |
| Script | `https://js.hcaptcha.com/1/api.js` |
| Credentials | `site_key`, `secret` |

## Two details that matter

**The endpoint is `api.hcaptcha.com`, not `hcaptcha.com`.** The package this replaced posted to the
latter, which answers by redirect — and a POST body does not necessarily survive one.

**The site key is sent with the secret.** hCaptcha accepts it and uses it to bind the token.
Without it, a token minted against any site key on the same account verifies here, so a low-value
public form on another property becomes a token mint for this one.

## Widget attributes

`data-theme`, `data-size`, and the locale via `?hl=` on the script URL.

---

[← Docs index](../../README.md#documentation)

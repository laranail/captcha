# Providers

The eleven, what each is good at, and what each costs you. One is active at a time.

| Key | Hosted by | Needs an account | Notes |
|---|---|---|---|
| `math` | you | no | Self-hosted arithmetic. The default. No JavaScript, no third party. |
| `altcha` | you | no | Self-hosted proof-of-work. No cookies, no penalty for Tor, VPN or Brave users. |
| `turnstile` | Cloudflare | yes, free | Invisible for most visitors, free at any volume. |
| `hcaptcha` | hCaptcha | yes | Higher catch rate than Turnstile; paid enterprise tiers. |
| `recaptcha-v2` | Google | yes | The classic checkbox. Widest adoption; sends visitor data to Google. |
| `recaptcha-v2-invisible` | Google | yes | v2 without the checkbox; executed on submit. |
| `recaptcha-v3` | Google | yes | Score only, never interrupts. Needs a threshold and an action. |
| `recaptcha-enterprise` | Google | yes, billed | Assessment API with richer signals; billed per assessment. |
| `friendly-captcha` | Friendly Captcha | yes | Invisible proof-of-work, EU-hosted, cookie-free, GDPR-friendly. |
| `arkose` | Arkose Labs | yes, enterprise | Strongest against sophisticated bot farms. |
| `null` | — | no | Test double. Refused in production. |

```dotenv
CAPTCHA_PROVIDER=turnstile
CAPTCHA_SITE_KEY=...
CAPTCHA_SECRET_KEY=...
```

## Choosing

- **No third party allowed** — air-gapped, strict privacy, or a form that cannot justify an
  external dependency: `math` or `altcha`. Both stop casual automation; neither stops a targeted
  attacker, and no self-hosted scheme does.
- **Data residency matters**: `friendly-captcha`, which defaults to its EU endpoint here.
- **Best free protection**: `turnstile`.
- **Highest catch rate**: `hcaptcha`.
- **You want a risk score rather than a verdict**: `recaptcha-v3`, `recaptcha-enterprise` or
  `arkose`. Read [Getting started](getting-started.md#using-the-score-instead-of-only-the-verdict)
  before you collapse the score to pass/fail.
- **Organised fraud, budget available**: `arkose`.

## Provider-specific requirements

- **reCAPTCHA v3 / Enterprise** need an action name (`providers.recaptcha.action`), and Enterprise
  needs a `project_id` alongside an API key as the secret.
- **Arkose** needs a `client` extra — its verify endpoint is per-customer
  (`{client}-verify.arkoselabs.com`).
- **Friendly Captcha** takes `endpoint` as `eu` (default) or `global`.
- **hCaptcha** binds the token to your site key automatically; without that, a token minted against
  any site key on the same account would verify here.

## Adding your own

Implement `CaptchaAdapter`, register it with `AdapterFactory::extend()`, and add a case to the
`Provider` enum. The shared contract suite runs against every case, so a new adapter is covered the
moment it is added — and cannot ship without failing closed on transport errors, non-2xx responses
and unparseable bodies.

---

[← Docs index](../README.md#documentation)

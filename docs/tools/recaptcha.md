# reCAPTCHA

Four providers share one page because they share one endpoint and differ only in how the token is
minted and what comes back.

| Key | Widget | Returns |
|---|---|---|
| `recaptcha-v2` | checkbox | pass/fail |
| `recaptcha-v2-invisible` | none — executed on submit | pass/fail |
| `recaptcha-v3` | none — executed on submit | score |
| `recaptcha-enterprise` | none — executed on submit | score, richer signals |

v2, v2-invisible and v3 verify at `https://www.google.com/recaptcha/api/siteverify`. Enterprise
uses the Assessment API instead: `POST recaptchaenterprise.googleapis.com/v1/projects/{id}/assessments`,
reading `tokenProperties.valid` and `riskAnalysis.score`.

## v3 and v2-invisible have nothing to click

Their token only exists once `grecaptcha.execute()` has run. `<x-captcha />` wires that: it
intercepts the enclosing form's submit once, mints the token and replays the submit. Place
`<x-captcha-container />` by hand instead and the form submits with no token at all — a failure
that looks like the captcha simply not working, with nothing in any log.

## The score is enforced, not reported

`success: true` is returned for a visitor scored 0.1. Discarding the score — which the package this
replaced did — reduces v3 to checking that a token is well-formed. See
[Configuration](../configuration.md) for the bands, and
[Ask for a second factor](../recipes/step-up-on-a-middling-score.md) for the middle one.

## Credentials

`site_key` and `secret` for v2 and v3. Enterprise takes an API key as the `secret` plus a
`project_id`; both are checked before a request, because a missing project id yields a URL with an
empty path segment and a 404 that reads like an outage.

An action name (`providers.recaptcha.action`) is required by v3 and Enterprise and is checked
against the provider's echo of it.

## The test keys verify anything

Google's published test keys return `success: true` for any string — the `live` suite demonstrates
it with the literal `not-a-real-token`. That is why they are refused in production.

---

[← Docs index](../../README.md#documentation)

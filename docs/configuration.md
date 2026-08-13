# Configuration

Every block in `config/laranail/captcha.php`, and what changing it does.

Publish with `php artisan laranail::captcha.install`. An unpublished install uses these defaults,
which are complete.

`env()` is called in this file and nowhere else in the package — anywhere else it returns null once
`config:cache` has run, and an arch test enforces that.

## `provider`

Which of the eleven is active, as a `Provider` enum value. Defaults to `math`: self-hosted, no
account, no keys. See [Providers](providers.md).

An unknown name falls back to the `null` provider rather than throwing at boot — a typo should not
take the whole application down — and `null` is refused in production, so the mistake still
surfaces as blocked submissions and a logged error.

## `credentials` and `environments`

Covered in full in [Credentials](credentials.md).

## `verification`

The checks applied after a provider answers. Each closes a way a genuine, vendor-approved token can
still be the wrong token for this request.

| Key | Default | What it does |
|---|---|---|
| `timeout` / `connect_timeout` | 5 / 2 | A hung provider must not pin a worker. There is no retry: these tokens are single-use, so retrying gets `timeout-or-duplicate`. |
| `verify_tls` | true | Leave it. |
| `enforce_hostname` | true | Rejects a token solved on a host you do not serve. **Does nothing until `allowed_hostnames` is set** — the doctor command reports that. |
| `allowed_hostnames` | `[]` | The hosts your widget legitimately runs on. |
| `enforce_action` | true | Rejects a token minted for a different form, when the caller names an action. |
| `max_age` | 300 | Seconds a solved challenge stays usable. |
| `replay_guard.enabled` | true | Makes a verified token single-use within this application. |
| `replay_guard.fail_open` | false | Whether a cache outage lets submissions through. False rejects real visitors while the cache is down; true reopens the replay window. Neither is free. |
| `score.allow` / `score.review` | 0.5 / 0.3 | Above `allow` proceeds; below `review` blocks; between them the result carries `Outcome::Review` so you can ask for a second factor. |

## `production_environments`

Which deployment names count as production, defaulting to `production` and `prod`. A list rather
than trusting `app()->environment()` alone: `APP_ENV` is a deployment name and some products ship
it as a feature flag — Worksuite reports `codecanyon` on live installations — so a name in neither
list would sail past a naive check.

## `logging`

Off by default. Records every outcome with its score and duration, so a v3 threshold can be chosen
from your own traffic rather than from an example number.

```php
'logging' => ['enabled' => true, 'failure_level' => 'debug'],
```

Captcha failures are ordinary bot traffic at volume, which is why this is opt-in — a line per
rejection turns a flood into a disk-space incident. Misconfiguration and provider outages are always
logged at `error` regardless of `failure_level`: those are an operator's problem, not a visitor's.

The token never appears in a log line. It is a live credential until it is spent, and a log
aggregator is a far softer target than the session it protects.

## `challenge`

The endpoint the self-hosted providers mint from. Rate-limited (`60,1` by default) because it is
unauthenticated by nature and minting is the expensive half. It answers 404 when the active
provider does not issue challenges.

## `providers`

Per-provider options, layered over `widget`. The ones that matter:

- `recaptcha.action` — required by v3 and Enterprise, and checked against the provider's echo.
- `friendly-captcha.endpoint` — `eu` (default) or `global`.
- `altcha.max_number` — proof-of-work difficulty. Higher is slower on low-end phones.
- `math.difficulty` — 1 (two terms), 2 (three terms with precedence), 3 (parenthesised).
- `null.allow_in_production` — the only way the accept-everything provider runs in production, so
  the decision is in writing rather than an accident.

Leave `hmac_key` null on the self-hosted providers and a key is derived from `APP_KEY` — never
`APP_KEY` itself, so rotating one does not silently change the meaning of the other.

## `widget`

`theme`, `size`, `language` and `nonce`, applied to whichever provider is active. Set `nonce` and
pass one to `<x-captcha :nonce="$nonce" />` to keep a strict CSP without `unsafe-inline`.

## `bot_management`

Off by default and a different axis — see [Security model](security.md#the-one-exception) for why
it fails open where everything else fails closed.

```php
'bot_management' => ['enabled' => true, 'adapter' => 'datadome', 'datadome' => ['server_key' => env('DATADOME_SERVER_KEY')]],
```

Then add `ProtectAgainstBots` to a route group. It is safe to register globally even when disabled:
the null adapter is bound in that case, and a middleware you add and remove as config changes is
one that eventually gets left out.

---

[← Docs index](../README.md#documentation)

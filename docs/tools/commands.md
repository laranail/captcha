# Commands

Four Artisan commands, all named `laranail::captcha.<command>` with a plain-colon alias for
environments that reject `::`.

| Command | Alias | Does |
|---|---|---|
| `laranail::captcha.doctor` | `captcha:doctor` | Reports the active setup and exits non-zero on anything unsafe |
| `laranail::captcha.keys` | `captcha:keys` | Shows which source each provider's credentials resolve from |
| `laranail::captcha.install` | `captcha:install` | Publishes the config, and optionally the settings migration |
| `laranail::captcha.cache-clear` | `captcha:cache-clear` | Forgets cached credentials |

## `doctor`

```bash
php artisan laranail::captcha.doctor
```

Prints the active provider, the resolved environment, whether it is configured, which of the three
sources its credentials came from, and a redacted site key. Then it checks four things and **exits
non-zero if any fail**, which is what makes it usable as a deploy gate — the only way a check like
this gets run at the moment it matters.

- **No usable credentials** for the active provider in this environment.
- **Production resolving from a source that accepts everything** — the published test keys, or the
  `null` provider. This is the headline check: an application in that state looks perfectly
  configured from every other angle.
- **Hostname enforcement on with an empty allow-list.** The setting reads as protection and
  compares nothing, so a token minted on someone else's copy of your form verifies here.
- **A score threshold of zero** on a score-based provider, which disables the only check that kind
  of provider offers.

## `keys`

```bash
php artisan laranail::captcha.keys
```

A table of every provider: source, state (`complete`, `incomplete`, `not set`) and a truncated site
key. The self-hosted and null providers report `n/a`, because an empty row for them would read as a
misconfiguration rather than as "no credentials needed".

Answers the question a config file cannot: not "is a key set", but "which of the three sources is
serving it right now". Secrets are never printed, and site keys are truncated because terminal
output gets pasted into issues.

## `install`

```bash
php artisan laranail::captcha.install
php artisan laranail::captcha.install --migrations
```

Publishes `config/laranail/captcha.php`, and with `--migrations` the optional `captcha_settings`
table.

**You do not have to run this.** The package ships working defaults, so `install` exists for
applications that want to change something — not as a step between installing and being protected.

## `cache-clear`

```bash
php artisan laranail::captcha.cache-clear
php artisan laranail::captcha.cache-clear --environment=staging
```

Forgets cached credentials so a key changed in the database takes effect immediately. No-ops with a
message when credential caching is disabled, which it is by default.

Two things it deliberately does not do:

- **It does not call `cache:clear`.** That would fix a captcha key by stampeding your whole cache.
- **It does not touch the replay guard.** Those entries are what stop a solved token being used
  twice; clearing them re-opens every outstanding token, and no operational problem is worth that.

---

[← Docs index](../../README.md#documentation)

# ALTCHA

Self-hosted proof-of-work. No vendor, no third-party request, no cookies, and no penalty for Tor,
VPN or Brave users.

| | |
|---|---|
| Key | `altcha` |
| Challenge endpoint | `/captcha/challenge` (rate-limited, `60,1`) |
| Credentials | none — signed with a key derived from `APP_KEY` |

## The scheme

The server picks a secret number, publishes `SHA-256(salt + number)` with an HMAC over it, and the
browser finds the number by counting. Implemented directly rather than as a runtime dependency:
`altcha-org/altcha` is a dev dependency, and `AltchaConformanceTest` cross-checks against it in
both directions.

That suite earned its place immediately. It caught a salt missing the trailing `&` the reference
appends — and because the salt is hashed, that changed every challenge and every signature, so no
ALTCHA-based server could verify anything this package issued. The package's own round trip was
self-consistent and entirely wrong, which nothing else could have found.

## What carries the security

- **The signature.** Without it a client invents its own trivial challenge and solves that.
  Compared with `hash_equals`.
- **Expiry, inside the salt**, so the HMAC covers it. An expiry the client can edit is no expiry.
- **Single use.** The maths still checks out on the tenth replay, so redemption is recorded.

One deliberate divergence from the reference: a challenge carrying no expiry is refused. The
reference treats expiry as optional; this adapter issues and verifies its own challenges, so
requiring one costs nothing and closes the window.

## Tuning

`providers.altcha.max_number` sets the work. Higher is slower on low-end phones, and the cost falls
on the visitor rather than the attacker — who has better hardware.

---

[← Docs index](../../README.md#documentation)

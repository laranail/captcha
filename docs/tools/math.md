# Math

Self-hosted arithmetic, and the package default. No account, no keys, no third-party request, and
no JavaScript required.

| | |
|---|---|
| Key | `math` |
| Challenge endpoint | `/captcha/challenge` (rate-limited, `60,1`) |
| Credentials | none — signed with a key derived from `APP_KEY` |

## Why this one is worth deploying

Most math captchas are not, and they fail the same three ways. This avoids all three:

**The answer never leaves the server.** Not in a hidden field, and not as a hash — the answer space
is a couple of hundred integers, so any hash of it *is* the answer. It lives in the cache under a
random id.

**One guess per challenge.** The cache entry is *pulled* on the first verification attempt, right
or wrong. A scheme that lets a client retry against the same question has a 200-value keyspace and
no limit, which is no scheme at all. Getting it wrong means fetching a new question.

**The id is signed and the expiry is covered by the signature**, so an attacker cannot invent ids
to probe the cache with, nor extend the window by editing the payload.

## The questions

`providers.math.difficulty`: 1 is two terms, 2 adds a third with precedence, 3 parenthesises.
Numbers appear as digits or words interchangeably (`seven × 4 + 3`) and operators vary in form.
That is not the security — it defeats the four-line regex that solves every other math captcha.

Answers are never negative: a minus sign is one more thing to fumble on a phone keyboard and buys
nothing against a bot.

## What it does not claim

It stops casual automation and drive-by spam. It does not stop a targeted attacker with an OCR
pipeline or a human-solver farm, and no arithmetic question would. Use it where a third-party
dependency is unacceptable; use a risk-scoring provider where the stakes are higher.

---

[← Docs index](../../README.md#documentation)

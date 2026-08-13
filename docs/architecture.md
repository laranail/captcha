# Architecture

Ports and adapters, and why the layering is a test rather than a convention.

## The layers

```
Contracts/     ports — the only thing other layers type-hint
Actions/       one job each, injected dependencies, no orchestration
Services/      thin orchestrators; the only layer that composes actions
Adapters/      one directory per vendor
Credentials/   the three stores and the chain that orders them
Providers/ Rules/ View/ Http/   the framework layer
```

`Actions`, `Services`, `Adapters`, `Credentials`, `Support` and `ValueObjects` may not call
`config()`, `env()`, `request()`, `app()`, `Cache::` or any facade. `tests/Arch` fails the build if
they do, and CI runs it as its own job.

**Why enforce it.** The package this replaced read `config('captcha.sitekey')` inline inside two
view components and called `request()` inside every driver. That is why its credentials could only
ever come from config, why its drivers could not be unit-tested, and why verification broke inside
a queued job. None of it was hard to fix — it was just never visible.

## Verification returns a value object

`verify()` answers with a `VerificationResult` carrying `passed`, `score`, `action`, `hostname`,
`challengeAt`, `errors` and the raw body. The old `bool` is precisely why score, action and
hostname were fetched from the provider and thrown away, and why the checks that depend on them
were never written.

## Where the checks live

An adapter's job ends at "the vendor says this token is genuine". Everything after that —
replay, freshness, hostname, action, score — lives in `Actions\VerifyCaptcha`, so it applies
identically whether verification is reached through the validation rule, the facade, or a queued
job. An adapter that had to remember them would eventually forget one.

## Why not `Illuminate\Support\Manager`

A `Manager` resolves by interpolating the driver name into a method call, and one manager in this
org goes further and accepts a class name as a driver. That is fine for a value the application
author writes and wrong for one arriving from a config file an operator edits — or, in a
multi-tenant install, from a database row.

`AdapterFactory` resolves through the `Provider` enum instead. The enum *is* the allow-list: a name
that is not a case never resolves to anything. Custom adapters go through `extend()`, which takes a
closure rather than a class name, so registering one is a deliberate act in application code.

Because exactly one provider is active, the container binds one adapter. There is no `driver('x')`
API to reach past the configured choice.

## Optional capabilities are separate ports

`IssuesChallenges` is implemented only by the self-hosted providers. Folding it into
`CaptchaAdapter` would mean nine implementors stubbing a method, and a port most implementors must
stub is a worse port. The challenge endpoint checks `instanceof` and answers 404 otherwise.

`BotManagementAdapter` is a different axis entirely — no site key, no widget, no token — which is
why it is not modelled as a provider.

## Fail-closed, structurally

`SiteVerifyAdapter::verify()` is `final` and wraps everything in a `Throwable` catch, so there is
no path through an adapter that throws or returns success on an unparsed body. The contract is
enforced by the shape of the base class rather than by asking implementors to remember it, and
`tests/Feature/AdapterContractTest.php` proves it for every adapter in the enum.

## Configuration is read once, typed

`CaptchaConfig` wraps the config repository with typed getters, and the verification policy is read
into a value object when the service is constructed. Casting at the call site is worse than it
looks: `(int) $config->get('…timeout')` turns a mistyped `'five'` into `0`, and a zero timeout is a
very different setting from a missing one.

One consequence to know: changing captcha config at runtime needs the service forgotten from the
container, because the policy was already read.

---

[← Docs index](../README.md#documentation)

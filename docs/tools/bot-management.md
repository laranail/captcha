# Bot management

The edge tier — a different axis from form-level captcha, and one that fails the opposite way.

## What it is

Captcha runs on one form at submit and asks "did a human solve a challenge". Bot management runs on
every request as it arrives and asks "does this connection look automated". There is no site key,
no widget and no token, which is why it is not modelled as a captcha provider: forcing it into that
port would mean stubbing `widget()` and inventing a token.

## Enabling it

```php
'bot_management' => [
    'enabled' => true,
    'adapter' => 'datadome',
    'datadome' => ['server_key' => env('DATADOME_SERVER_KEY')],
],
```

```php
use Simtabi\Laranail\Captcha\Http\Middleware\ProtectAgainstBots;

Route::middleware(ProtectAgainstBots::class)->group(function () {
    // ...
});
```

Safe to register globally even when disabled: the null adapter is bound in that case and the
middleware becomes a pass-through. A middleware you add and remove as configuration changes is one
that eventually gets left out of the group that needed it.

## It fails open

Everything else in this package fails closed. This does not, deliberately.

Captcha guards one form, so failing closed costs one submission. Bot management sits in front of
*everything*, so failing closed on a provider outage takes the whole site down to stop traffic that
was probably legitimate — a self-inflicted outage in response to someone else's. DataDome's own
integration guidance says the same, and the timeout is one second for the same reason: a
per-request dependency on a third party is only acceptable if it cannot hold a request open.

The subtle case: a **403 arriving without the `X-DataDomeResponse` header** is treated as
unavailable rather than as a block. That shape is a proxy error page, a captive portal or an edge
cache — not a verdict — and reading it as "bot" would block real users during an unrelated network
problem.

## What happens on a refusal

A flat `403`, and a `BotRequestBlocked` event carrying the adapter name, the decision and the
request.

Challenge and redirect verdicts also become a 403 rather than proxying the provider's interstitial
or following its redirect. Rendering a third party's markup into your response is a decision an
application should make deliberately, so it listens for the event and does that itself.

```php
Event::listen(function (BotRequestBlocked $event) {
    Log::warning('Bot request blocked', [
        'adapter' => $event->adapter,
        'decision' => $event->decision->value,
        'path' => $event->request->path(),
    ]);
});
```

A run of `Challenge` decisions says the same thing as a run of `Block` decisions, which is why the
event fires for both.

## Payload limits

Request fields are truncated to DataDome's documented limits. An over-long header is a routine
thing for a hostile client to send, and an oversized payload is rejected wholesale — which would
turn "this request looks suspicious" into "bot management is unavailable" for exactly the requests
that matter.

## HUMAN and Kasada

Documented extension points on `BotManagementAdapter`, not shipped adapters. Both are sold with
dedicated integration support and cannot be tested without a paid account, and shipping two
unverifiable integrations would be claiming coverage that cannot be stood behind.

```php
final class KasadaBotManager implements BotManagementAdapter
{
    public function decide(Request $request): BotDecision { /* ... */ }
    public function isConfigured(): bool { /* ... */ }
    public function name(): string { return 'kasada'; }
}
```

Bind it to `BotManagementAdapter::class` in a service provider. Return
`BotDecision::whenUnavailable()` when your provider cannot be reached — that contract is the
package's, not the vendor's.

---

[← Docs index](../../README.md#documentation)

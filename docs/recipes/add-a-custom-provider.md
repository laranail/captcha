# Add a custom provider

Implement the port, register a closure, add an enum case.

```php
use Simtabi\Laranail\Captcha\Contracts\CaptchaAdapter;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\ValueObjects\{VerificationContext, VerificationResult, Widget};

final class AcmeAdapter implements CaptchaAdapter
{
    public function verify(string $token, VerificationContext $context): VerificationResult
    {
        try {
            $response = $this->http->request()->asForm()->post(self::URL, [...]);
        } catch (\Throwable) {
            return VerificationResult::failed(ErrorCode::TransportError);
        }

        if (! $response->successful() || ! is_array($body = $response->json())) {
            return VerificationResult::failed(ErrorCode::MalformedResponse);
        }

        return ($body['ok'] ?? false) === true
            ? VerificationResult::passed(hostname: $body['host'] ?? null, raw: $body)
            : VerificationResult::failed(ErrorCode::InvalidResponse, raw: $body);
    }

    // widget(), isConfigured(), provider() …
}
```

```php
$this->app->make(AdapterFactory::class)->extend('acme', fn () => new AcmeAdapter(/* … */));
```

`extend()` takes a closure rather than a class name on purpose: a provider name arrives from a
config file an operator edits, and in a multi-tenant install from a database row. Registering an
adapter has to be a deliberate act in application code, not a string somebody can set.

Three rules the contract enforces, and the shared suite checks:

- **Never throw.** A transport error is a failed result, not an exception — an exception here is a
  500 on a login form.
- **Never pass on a body you could not read.** `$body['ok'] ?? true` is the shape to avoid.
- **Return the fields you got.** Hostname, action, score and timestamp are what the post-verification
  checks compare; discarding them silently disables those checks for your provider.

Extending the `Provider` enum means the shared contract suite covers your adapter automatically —
and will fail it if any of the above is wrong.

See [Architecture](../architecture.md) and [Testing](../tools/testing.md).

---

[← Docs index](../../README.md#documentation)

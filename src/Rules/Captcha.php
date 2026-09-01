<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\Support\ResponseField;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;

/**
 * The `captcha` validation rule.
 *
 * **Implicit, and that is the whole point.** A non-implicit rule is skipped when the field is
 * absent from the request, so the package this replaces let a request that simply omitted the
 * captcha response through validation untouched — the reported symptom was "able to login without
 * even using captcha", and it was closed as user error. Omitting the field is exactly what an
 * attacker does. `$implicit = true` is what makes the rule run anyway.
 *
 * This class sits in the framework layer, so unlike `Actions/`, `Services/` and `Adapters/` it is
 * allowed to reach the container: rules are constructed with `new` in application code and cannot
 * be given dependencies any other way. Both are still injectable for tests.
 */
final class Captcha implements ValidationRule
{
    /**
     * Read by `InvokableValidationRule::make()`, which wraps an implicit rule in a subclass
     * implementing `ImplicitRule` so the validator stops skipping missing fields.
     */
    public bool $implicit = true;

    public function __construct(
        private readonly ?string $action = null,
        private ?CaptchaService $captcha = null,
        private readonly ?Request $request = null,
    ) {}

    /** Bind this rule to the action the token was minted for. */
    public static function for(string $action): self
    {
        return new self($action);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Anything that is not a non-empty string can never be a valid response, and is answered
        // without a round trip. It is also the case that used to raise a TypeError: the value
        // arrived as null and went straight into a `string` parameter under strict types, which
        // surfaced as a 500 rather than a validation failure.
        if (! is_string($value) || trim($value) === '') {
            // Before giving up, look for a token the widget posted under a different field —
            // the vendor's own name, or the two inputs a server-rendered challenge uses. The
            // rule is normally attached to `captcha`, and a form that has not been migrated to
            // that name should still be protected rather than silently rejected.
            $value = $this->fromRequest();
        }

        if (! is_string($value) || trim($value) === '') {
            $fail(ErrorCode::MissingResponse->translationKey())->translate();

            return;
        }

        $result = $this->captcha()->verify($value, new VerificationContext(
            action: $this->action,
            // Resolved by the framework rather than read from a header, and only when there is a
            // request at all — this rule runs inside queued jobs and console commands too.
            remoteIp: $this->request()?->ip(),
        ));

        if ($result->passes()) {
            return;
        }

        $fail(($result->firstError() ?? ErrorCode::InvalidResponse)->translationKey())->translate();
    }

    private function fromRequest(): ?string
    {
        $request = $this->request();

        return $request instanceof Request
            ? ResponseField::fromRequest($request, $this->captcha()->provider())
            : null;
    }

    private function captcha(): CaptchaService
    {
        return $this->captcha ??= app(CaptchaService::class);
    }

    private function request(): ?Request
    {
        if ($this->request instanceof Request) {
            return $this->request;
        }

        return app()->bound('request') ? app('request') : null;
    }
}

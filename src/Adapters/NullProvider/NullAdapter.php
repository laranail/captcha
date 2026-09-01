<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\NullProvider;

use Simtabi\Laranail\Captcha\Actions\GuardProductionSafety;
use Simtabi\Laranail\Captcha\Contracts\CaptchaAdapter;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;

/**
 * The test double: verifies everything, or nothing, without leaving the process.
 *
 * Useful in a test suite and in local development where a real provider is noise. Dangerous
 * anywhere else, because an application configured with it looks completely healthy — the config
 * is valid, verification returns success, no error is logged — while accepting every submission.
 *
 * So it is refused in production by {@see GuardProductionSafety},
 * unless an operator sets `allow_in_production` and thereby writes the decision down. That escape
 * hatch exists because there are legitimate uses, such as a staging environment sharing the
 * production `APP_ENV`; the point is that it cannot happen by accident.
 */
final readonly class NullAdapter implements CaptchaAdapter
{
    public function __construct(private bool $verifies = true) {}

    public function verify(string $token, VerificationContext $context): VerificationResult
    {
        return $this->verifies
            ? VerificationResult::passed(score: 1.0, action: $context->action)
            : VerificationResult::failed(ErrorCode::InvalidResponse);
    }

    public function widget(string $instanceId): Widget
    {
        return new Widget(
            provider: Provider::NullProvider,
            instanceId: $instanceId,
            containerClass: 'laranail-null-captcha',
            attributes: ['data-verifies' => $this->verifies ? 'true' : 'false'],
        );
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function provider(): Provider
    {
        return Provider::NullProvider;
    }
}

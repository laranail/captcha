<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Events;

use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;

/**
 * A submission passed every check.
 *
 * Carries the score so a host can record the distribution — which is the only way to pick a
 * reCAPTCHA v3 threshold that fits its own traffic rather than guessing at Google's example
 * number.
 */
final readonly class CaptchaVerified
{
    public function __construct(
        public Provider $provider,
        public VerificationResult $result,
        public VerificationContext $context,
    ) {}
}

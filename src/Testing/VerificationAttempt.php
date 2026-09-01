<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Testing;

use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;

/**
 * One call to `verify()`, as the fake saw it.
 *
 * Keeps the token so an assertion can check *which* submission was verified — a test that only
 * counts calls passes just as happily when the wrong form is protected.
 */
final readonly class VerificationAttempt
{
    public function __construct(
        public string $token,
        public VerificationContext $context,
        public VerificationResult $result,
    ) {}

    public function passed(): bool
    {
        return $this->result->passes();
    }
}

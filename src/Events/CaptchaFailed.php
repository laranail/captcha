<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Events;

use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;

/**
 * A submission was rejected, for a reason the result names.
 *
 * Worth listening to on two counts. A run of `HostnameMismatch` or `Replayed` is an attack in
 * progress; a run of `NotConfigured`, `InvalidSecret` or `TransportError` is an outage, and the
 * result's `isOperatorFault()` tells the two apart so an alert rule does not page someone about
 * ordinary bot traffic.
 */
final readonly class CaptchaFailed
{
    public function __construct(
        public Provider $provider,
        public VerificationResult $result,
        public VerificationContext $context,
    ) {}
}

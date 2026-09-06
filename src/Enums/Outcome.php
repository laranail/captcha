<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * What the application should do with a verified interaction.
 *
 * Three-way rather than boolean because that is what score-based providers actually return.
 * Google's own guidance for reCAPTCHA v3 is to allow a high score, block a low one, and step up to
 * a second factor in between — collapsing that into pass/fail throws away the middle band, which is
 * where the interesting traffic is. The validation rule still reduces this to pass/fail, but the
 * host can read the outcome and trigger 2FA instead.
 */
enum Outcome: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Allow')]
    #[Description('Verified, and scored above the allow threshold.')]
    case Allow = 'allow';

    #[Label('Review')]
    #[Description('Verified, but scored in the band where a second factor is warranted.')]
    case Review = 'review';

    #[Label('Block')]
    #[Description('Not verified, or scored below the block threshold.')]
    case Block = 'block';

    /** Whether validation should treat this as a pass. */
    public function passesValidation(): bool
    {
        return $this !== self::Block;
    }
}

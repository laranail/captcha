<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * What an edge bot-management provider decided about a request.
 *
 * A different axis from {@see Outcome}: bot management runs per request, before any form exists,
 * and its verdict is about the connection rather than a solved challenge.
 */
enum BotDecision: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Allow')]
    #[Description('Pass the request through untouched.')]
    case Allow = 'allow';

    #[Label('Block')]
    #[Description('Refuse the request outright.')]
    case Block = 'block';

    #[Label('Challenge')]
    #[Description('Serve the provider’s interstitial challenge before continuing.')]
    case Challenge = 'challenge';

    #[Label('Redirect')]
    #[Description('Send the visitor to the provider’s hosted decision page.')]
    case Redirect = 'redirect';

    /**
     * The decision taken when the provider cannot be reached.
     *
     * Fail open, deliberately, and unlike captcha verification. Bot management sits in front of
     * every request including the ones that have nothing to do with abuse; failing closed on a
     * provider outage takes the whole site down to stop traffic that was probably legitimate.
     * DataDome's own integration guidance says the same. Captcha verification makes the opposite
     * call for the opposite reason: it guards one form, and letting it through defeats the point.
     */
    public static function whenUnavailable(): self
    {
        return self::Allow;
    }
}

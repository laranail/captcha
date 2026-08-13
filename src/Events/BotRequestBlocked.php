<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Events;

use Illuminate\Http\Request;
use Simtabi\Laranail\Captcha\Enums\BotDecision;

/**
 * The edge tier refused a request.
 *
 * Dispatched for every non-allow decision, not just outright blocks, because the interesting
 * signal during an incident is the shape of the traffic rather than the individual verdict — a
 * sudden run of challenges says the same thing as a run of blocks.
 */
final readonly class BotRequestBlocked
{
    public function __construct(
        public string $adapter,
        public BotDecision $decision,
        public Request $request,
    ) {}
}

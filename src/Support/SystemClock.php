<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The wall clock, behind an interface so the freshness check can be tested.
 *
 * Verifying that a challenge older than the window is rejected means controlling what "now" is.
 * With `time()` called inline the only way to test it is to fabricate a timestamp far in the past,
 * which proves the comparison runs but not that the boundary is where it should be.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Support;

/**
 * Raises each deprecation notice once per process, so a rule run on every request or a tag in every
 * layout does not write a log line per use.
 */
final class DeprecationNotices
{
    /** @var array<string, true> */
    private static array $raised = [];

    /** Raise the notice under the given key, unless that key has already been raised. */
    public static function once(string $key, string $message): void
    {
        if (isset(self::$raised[$key])) {
            return;
        }

        self::$raised[$key] = true;

        trigger_error($message, E_USER_DEPRECATED);
    }

    /** Forget which notices were raised. For tests. */
    public static function forget(): void
    {
        self::$raised = [];
    }
}

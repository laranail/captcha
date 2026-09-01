<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\ValueObjects;

use Simtabi\Laranail\Captcha\Support\CaptchaConfig;

/**
 * The checks applied to a response after the provider has answered.
 *
 * Read from config once and passed around as a value, so the layers that enforce it never touch
 * `config()` and can be tested by constructing a policy directly.
 */
final readonly class VerificationPolicy
{
    /**
     * @param  list<string>  $allowedHostnames
     */
    public function __construct(
        public bool $enforceHostname = true,
        public array $allowedHostnames = [],
        public bool $enforceAction = true,
        public int $maxAgeSeconds = 300,
        public bool $replayGuardEnabled = true,
        public int $replayTtlSeconds = 600,
        public float $allowScore = 0.5,
        public float $reviewScore = 0.3,
    ) {}

    public static function fromConfig(CaptchaConfig $config): self
    {
        return new self(
            enforceHostname: $config->bool('verification.enforce_hostname', true),
            allowedHostnames: $config->strings('verification.allowed_hostnames'),
            enforceAction: $config->bool('verification.enforce_action', true),
            maxAgeSeconds: $config->int('verification.max_age', 300),
            replayGuardEnabled: $config->bool('verification.replay_guard.enabled', true),
            replayTtlSeconds: $config->int('verification.replay_guard.ttl', 600),
            allowScore: $config->float('verification.score.allow', 0.5),
            reviewScore: $config->float('verification.score.review', 0.3),
        );
    }

    /**
     * Hostnames a challenge may legitimately have been solved on.
     *
     * The context wins when it names any, so a route serving a second domain can widen the set
     * without loosening it globally.
     *
     * @return list<string>
     */
    public function hostnamesFor(VerificationContext $context): array
    {
        return array_values($context->allowedHostnames !== []
            ? $context->allowedHostnames
            : $this->allowedHostnames);
    }
}

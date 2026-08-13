<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\Captcha\Actions\GuardProductionSafety;
use Simtabi\Laranail\Captcha\Actions\ResolveCredentials;
use Simtabi\Laranail\Captcha\Actions\VerifyCaptcha;
use Simtabi\Laranail\Captcha\AdapterFactory;
use Simtabi\Laranail\Captcha\Adapters\NullProvider\NullAdapter;
use Simtabi\Laranail\Captcha\Contracts\CaptchaAdapter;
use Simtabi\Laranail\Captcha\Contracts\ChallengePayload;
use Simtabi\Laranail\Captcha\Contracts\IssuesChallenges;
use Simtabi\Laranail\Captcha\Contracts\ReplayGuard;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Exceptions\UnsafeCaptchaConfiguration;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationPolicy;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;

/**
 * The package's public surface, and what the `Captcha` facade proxies to.
 *
 * Orchestration only: it decides *which* adapter is active, refuses configurations that would
 * accept everything, and hands the work to the actions. The checks themselves live in
 * {@see VerifyCaptcha}, so they apply identically whether verification is reached through the
 * validation rule, the facade, or a queued job.
 */
final class CaptchaService
{
    private ?CaptchaAdapter $adapter = null;

    public function __construct(
        private readonly AdapterFactory $factory,
        private readonly Provider $provider,
        private readonly ResolveCredentials $resolveCredentials,
        private readonly GuardProductionSafety $guard,
        private readonly ReplayGuard $replayGuard,
        private readonly ClockInterface $clock,
        private readonly VerificationPolicy $policy,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function verify(?string $token, ?VerificationContext $context = null): VerificationResult
    {
        try {
            $this->assertSafe();
        } catch (UnsafeCaptchaConfiguration $exception) {
            // Fails closed, and loudly in the log rather than on the page. Letting this reach the
            // handler would turn a misconfiguration into a 500 on the login form, and the message
            // names the credentials involved — not something to render to a visitor.
            $this->logger->error('[laranail/captcha] ' . $exception->getMessage());

            return VerificationResult::failed(ErrorCode::NotConfigured);
        }

        $verify = new VerifyCaptcha(
            adapter: $this->adapter(),
            replayGuard: $this->replayGuard,
            clock: $this->clock,
            policy: $this->policy,
            events: $this->events,
        );

        return $verify($token, $context);
    }

    public function widget(?string $instanceId = null): Widget
    {
        return $this->adapter()->widget($instanceId ?? Widget::generateId());
    }

    /**
     * Mint a challenge, for the self-hosted providers that issue their own.
     *
     * Returns null for everything else, which is what the challenge endpoint answers 404 on — so
     * an application using Turnstile has no live public route for a code path it never uses.
     */
    public function issueChallenge(): ?ChallengePayload
    {
        $adapter = $this->adapter();

        return $adapter instanceof IssuesChallenges ? $adapter->issue() : null;
    }

    public function adapter(): CaptchaAdapter
    {
        return $this->adapter ??= $this->factory->make($this->provider);
    }

    public function provider(): Provider
    {
        return $this->adapter?->provider() ?? $this->provider;
    }

    public function isConfigured(): bool
    {
        return $this->adapter()->isConfigured();
    }

    /**
     * Swap in the null adapter for the rest of the test.
     *
     * Kept from the package this replaces, where it was the one piece of the public API that made
     * host-application tests bearable. Guarded the same way as everything else: the production
     * check runs on the swapped adapter too, so `fake()` left in a deployed code path fails closed
     * rather than silently disabling protection.
     */
    public function fake(bool $verifies = true): self
    {
        $this->adapter = new NullAdapter($verifies);

        return $this;
    }

    /** Undo a {@see self::fake()}, restoring the configured adapter. */
    public function forgetAdapter(): void
    {
        $this->adapter = null;
    }

    /**
     * @throws UnsafeCaptchaConfiguration
     */
    private function assertSafe(): void
    {
        ($this->guard)(
            $this->provider(),
            ($this->resolveCredentials)($this->provider()),
            $this->resolveCredentials->environment(),
        );
    }
}

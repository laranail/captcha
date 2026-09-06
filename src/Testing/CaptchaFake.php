<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Testing;

use PHPUnit\Framework\Assert;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;
use Simtabi\Laranail\Captcha\Contracts\CaptchaAdapter;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;

/**
 * The adapter a host application's tests run against.
 *
 * Implements the production contract rather than being a partial double, so a test cannot pass
 * against a shape the real thing never produces.
 *
 * It reports itself as {@see Provider::NullProvider} on purpose. That is what keeps
 * `GuardProductionSafety` refusing it, so a `Captcha::fake()` left in a deployed code path fails
 * closed rather than quietly accepting every submission — a promise the service's docblock has
 * always made, and which a fake reporting a real provider would have made false.
 */
final class CaptchaFake implements CaptchaAdapter
{
    /** @var list<VerificationAttempt> */
    public array $attempts = [];

    /** @var list<VerificationResult> */
    private array $queue = [];

    public function __construct(
        private readonly bool $verifies = true,
        private readonly ?float $score = null,
    ) {}

    /**
     * Queue results consumed one per call, for a flow that verifies more than once.
     *
     * @param list<VerificationResult> $results
     */
    public function queue(array $results): self
    {
        $this->queue = [...$this->queue, ...$results];

        return $this;
    }

    public function verify(string $token, VerificationContext $context): VerificationResult
    {
        $result = array_shift($this->queue) ?? $this->default();

        $this->attempts[] = new VerificationAttempt($token, $context, $result);

        return $result;
    }

    public function widget(string $instanceId): Widget
    {
        return new Widget(
            provider: Provider::NullProvider,
            instanceId: $instanceId,
            containerClass: 'laranail-captcha-fake',
        );
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function provider(): Provider
    {
        return Provider::NullProvider;
    }

    /**
     * @param null|callable(VerificationAttempt): bool $matching
     */
    public function assertVerified(?callable $matching = null): void
    {
        Assert::assertNotEmpty(
            $this->matching(true, $matching),
            'Expected a captcha to have been verified.',
        );
    }

    /**
     * @param null|callable(VerificationAttempt): bool $matching
     */
    public function assertFailed(?callable $matching = null): void
    {
        Assert::assertNotEmpty(
            $this->matching(false, $matching),
            'Expected a captcha verification to have failed.',
        );
    }

    public function assertNothingVerified(): void
    {
        Assert::assertSame([], $this->attempts, 'Expected no captcha to have been verified.');
    }

    public function assertVerifiedCount(int $expected): void
    {
        Assert::assertCount($expected, $this->attempts, "Expected {$expected} captcha verification(s).");
    }

    /**
     * @param null|callable(VerificationAttempt): bool $matching
     *
     * @return list<VerificationAttempt>
     */
    private function matching(bool $passed, ?callable $matching): array
    {
        return array_values(array_filter(
            $this->attempts,
            static fn (VerificationAttempt $a): bool => $a->passed() === $passed
                && ($matching === null || $matching($a)),
        ));
    }

    private function default(): VerificationResult
    {
        if (! $this->verifies) {
            return VerificationResult::failed(ErrorCode::InvalidResponse);
        }

        return VerificationResult::passed(score: $this->score ?? 1.0);
    }
}

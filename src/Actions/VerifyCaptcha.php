<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Actions;

use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Clock\ClockInterface;
use Simtabi\Laranail\Captcha\Contracts\CaptchaAdapter;
use Simtabi\Laranail\Captcha\Contracts\ReplayGuard;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Enums\Outcome;
use Simtabi\Laranail\Captcha\Events\CaptchaFailed;
use Simtabi\Laranail\Captcha\Events\CaptchaVerified;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationPolicy;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;

/**
 * Verify one token and apply every check that is the same for every provider.
 *
 * The adapter's job ends at "the vendor says this token is genuine". That sentence is weaker than
 * it looks, and the gap between it and "this submission is legitimate" is where the package this
 * replaces let everything through:
 *
 * - a genuine token minted on **another origin** using the same public site key;
 * - a genuine token minted for **another form** — the newsletter signup, replayed on login;
 * - a genuine token minted **an hour ago** and kept;
 * - a genuine token **used twice** in parallel;
 * - a genuine token from a visitor reCAPTCHA v3 **scored 0.1**.
 *
 * Every one of those returns `success: true` from the vendor. The checks below are what turn that
 * into an answer about this request.
 */
final readonly class VerifyCaptcha
{
    public function __construct(
        private CaptchaAdapter $adapter,
        private ReplayGuard $replayGuard,
        private ClockInterface $clock,
        private VerificationPolicy $policy,
        private Dispatcher $events,
    ) {}

    public function __invoke(?string $token, ?VerificationContext $context = null): VerificationResult
    {
        $context ??= VerificationContext::none();

        // An absent or blank token never reaches the provider. It is also the case the old
        // package crashed on: the value arrived as null, went straight into a `string` parameter
        // under strict types, and raised a TypeError that surfaced as a 500 on the login form.
        if ($token === null || trim($token) === '') {
            return $this->fail(VerificationResult::failed(ErrorCode::MissingResponse), $context);
        }

        $startedAt = hrtime(true);
        $result = $this->adapter->verify($token, $context)
            ->withDuration((int) ((hrtime(true) - $startedAt) / 1_000_000));

        if (! $result->verified) {
            return $this->fail($result, $context);
        }

        // Claimed as soon as the vendor confirms it, before the remaining checks. The token is
        // spent at the vendor from this moment whatever we decide next, and claiming later would
        // leave a window in which two concurrent submissions both pass.
        if ($this->policy->replayGuardEnabled
            && ! $this->replayGuard->claim($token, $this->policy->replayTtlSeconds)) {
            return $this->fail($result->withError(ErrorCode::Replayed), $context);
        }

        foreach ([
            $this->checkHostname($result, $context),
            $this->checkAction($result, $context),
            $this->checkFreshness($result),
        ] as $failure) {
            if ($failure instanceof ErrorCode) {
                return $this->fail($result->withError($failure), $context);
            }
        }

        $result = $this->applyScore($result);

        if (! $result->passes()) {
            return $this->fail($result, $context);
        }

        $this->events->dispatch(new CaptchaVerified($this->adapter->provider(), $result, $context));

        return $result;
    }

    /**
     * Reject a token solved on a host this application does not serve.
     *
     * The site key is public, so anyone can put the widget on their own page and collect genuine
     * tokens. The hostname is the only field that says where it was solved.
     */
    private function checkHostname(VerificationResult $result, VerificationContext $context): ?ErrorCode
    {
        if (! $this->policy->enforceHostname) {
            return null;
        }

        $allowed = $this->policy->hostnamesFor($context);

        // Nothing configured means nothing to compare against. Failing every request here would
        // be worse than not checking, and would make the safe default unusable; the doctor
        // command reports the gap instead.
        if ($allowed === [] || $result->hostname === null) {
            return null;
        }

        return in_array($result->hostname, $allowed, true) ? null : ErrorCode::HostnameMismatch;
    }

    /**
     * Reject a token minted for a different action.
     *
     * Only meaningful when the caller named one and the provider echoed it back, which rules out
     * the providers that do not carry actions at all.
     */
    private function checkAction(VerificationResult $result, VerificationContext $context): ?ErrorCode
    {
        if (! $this->policy->enforceAction || $context->action === null || $result->action === null) {
            return null;
        }

        return hash_equals($context->action, $result->action) ? null : ErrorCode::ActionMismatch;
    }

    /** Reject a challenge solved longer ago than the freshness window allows. */
    private function checkFreshness(VerificationResult $result): ?ErrorCode
    {
        if ($this->policy->maxAgeSeconds <= 0 || ! $result->challengeAt instanceof DateTimeImmutable) {
            return null;
        }

        $age = $this->clock->now()->getTimestamp() - $result->challengeAt->getTimestamp();

        return $age > $this->policy->maxAgeSeconds ? ErrorCode::Stale : null;
    }

    /**
     * Turn a risk score into an outcome.
     *
     * Three bands rather than two, because that is what the score is for: above `allow` proceed,
     * below `review` block, and in between hand the host an outcome it can answer with a second
     * factor rather than a rejection.
     */
    private function applyScore(VerificationResult $result): VerificationResult
    {
        // Gated on the score being present rather than on the provider claiming to be
        // score-based. A result that carries a score should have the thresholds applied to it
        // whatever the enum thinks — and the enum was wrong for the test fake, which reports the
        // null provider so the production guard can refuse it and was therefore silently exempt
        // from the very band it existed to exercise.
        if ($result->score === null) {
            return $result;
        }

        if ($result->score >= $this->policy->allowScore) {
            return $result;
        }

        return $result->score >= $this->policy->reviewScore
            ? $result->withOutcome(Outcome::Review)
            : $result->withError(ErrorCode::LowScore);
    }

    private function fail(VerificationResult $result, VerificationContext $context): VerificationResult
    {
        $this->events->dispatch(new CaptchaFailed($this->adapter->provider(), $result, $context));

        return $result;
    }
}

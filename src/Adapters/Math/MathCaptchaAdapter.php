<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\Math;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Simtabi\Laranail\Captcha\Contracts\CaptchaAdapter;
use Simtabi\Laranail\Captcha\Contracts\ChallengePayload;
use Simtabi\Laranail\Captcha\Contracts\IssuesChallenges;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;
use Throwable;

/**
 * A math captcha that is actually worth deploying.
 *
 * Most are not, and they fail in the same three ways every time. This one is built around avoiding
 * them, and the design is small enough to state in full:
 *
 * 1. **The answer never leaves the server.** Not in a hidden field, not as a hash the client could
 *    grind offline — the answer space is a couple of hundred integers, so any hash of it is the
 *    answer. It lives in the cache under a random id and is never serialised into a response.
 *
 * 2. **One guess per challenge, enforced by taking the answer rather than reading it.** The
 *    cache entry is pulled on the first verification attempt, right or wrong. A scheme that lets a
 *    client retry against the same question is a scheme with a 200-guess keyspace and no limit,
 *    which is no scheme at all. Getting it wrong means fetching a new question, which is the
 *    correct cost.
 *
 * 3. **The id is signed**, so an attacker cannot invent ids to probe the cache with, and expiry is
 *    covered by the signature so it cannot be extended by editing the payload.
 *
 * What it deliberately does not claim: this stops casual automation and drive-by spam. It does not
 * stop a targeted attacker with an OCR pipeline or a human-solver farm, and no arithmetic question
 * would. Use it where a third-party captcha is unacceptable — air-gapped deployments, strict
 * privacy requirements, a form that cannot justify a Cloudflare dependency — and use a risk-scoring
 * provider where the stakes are higher.
 */
final readonly class MathCaptchaAdapter implements CaptchaAdapter, IssuesChallenges
{
    public function __construct(
        #[SensitiveParameter]
        private string $hmacKey,
        private Repository $cache,
        private ClockInterface $clock,
        private ProblemGenerator $problems,
        private int $expiresAfterSeconds = 300,
        private string $challengeUrl = '/captcha/challenge',
        private string $prefix = 'laranail:captcha:math:',
    ) {}

    public function issue(): ChallengePayload
    {
        ['question' => $question, 'answer' => $answer] = $this->problems->generate();

        $id = bin2hex(random_bytes(16));
        $expiresAt = $this->clock->now()->modify("+{$this->expiresAfterSeconds} seconds");

        // The answer is the only copy, and it is server-side and short-lived. The TTL is the
        // expiry plus a little, so a challenge cannot outlive the entry that validates it.
        $this->cache->put($this->prefix.$id, $answer, $this->expiresAfterSeconds + 30);

        return new MathProblem(
            id: $id,
            question: $question,
            signature: $this->sign($id, $expiresAt->getTimestamp()),
            expiresAt: $expiresAt,
        );
    }

    public function verify(string $token, VerificationContext $context): VerificationResult
    {
        if (! $this->isConfigured()) {
            return VerificationResult::failed(ErrorCode::NotConfigured);
        }

        $payload = $this->decode($token);

        if ($payload === null) {
            return VerificationResult::failed(ErrorCode::InvalidResponse);
        }

        ['id' => $id, 'answer' => $answer, 'expires' => $expires, 'signature' => $signature] = $payload;

        // Checked before anything touches the cache, so a forged payload cannot be used to probe
        // for or consume entries it did not earn.
        if (! hash_equals($this->sign($id, $expires), $signature)) {
            return VerificationResult::failed(ErrorCode::InvalidResponse);
        }

        if ((new DateTimeImmutable('@'.$expires)) < $this->clock->now()) {
            return VerificationResult::failed(ErrorCode::Stale);
        }

        // Pulled, not read. This single line is what makes the small answer space safe: the
        // question is spent on this attempt whether or not the answer is right.
        $expected = $this->cache->pull($this->prefix.$id);

        if (! is_int($expected)) {
            // Either already answered, or expired out of the cache. Both mean the same thing to
            // the visitor — fetch a new question — and telling them apart would leak which ids
            // are live.
            return VerificationResult::failed(ErrorCode::Replayed);
        }

        return hash_equals((string) $expected, $answer)
            ? VerificationResult::passed(challengeAt: $this->clock->now())
            : VerificationResult::failed(ErrorCode::InvalidResponse);
    }

    public function widget(string $instanceId): Widget
    {
        return new Widget(
            provider: Provider::Math,
            instanceId: $instanceId,
            containerClass: 'laranail-math-captcha',
            attributes: ['data-challenge-url' => $this->challengeUrl],
        );
    }

    public function isConfigured(): bool
    {
        return $this->hmacKey !== '';
    }

    public function provider(): Provider
    {
        return Provider::Math;
    }

    /**
     * @return array{id: string, answer: string, expires: int, signature: string}|null
     */
    private function decode(string $token): ?array
    {
        $json = base64_decode($token, true);

        if ($json === false) {
            return null;
        }

        try {
            /** @var mixed $payload */
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        foreach (['id', 'signature'] as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key]) || $payload[$key] === '') {
                return null;
            }
        }

        if (! isset($payload['expires']) || ! is_int($payload['expires'])) {
            return null;
        }

        // Accepted as a string and compared as one. Casting to int would make `007`, ` 7 ` and
        // `7abc` all equal seven, and the last of those is a parser difference an attacker can
        // probe for.
        $answer = $payload['answer'] ?? null;

        if (! is_string($answer) && ! is_int($answer)) {
            return null;
        }

        return [
            'id' => $payload['id'],
            'answer' => trim((string) $answer),
            'expires' => $payload['expires'],
            'signature' => $payload['signature'],
        ];
    }

    private function sign(string $id, int $expires): string
    {
        return hash_hmac('sha256', $id.'|'.$expires, $this->hmacKey);
    }
}

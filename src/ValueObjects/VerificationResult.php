<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\ValueObjects;

use DateTimeImmutable;
use Simtabi\Laranail\Captcha\Enums\Outcome;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;

/**
 * The result of asking a provider about one token.
 *
 * This exists because the package it replaces returned `bool`. A boolean cannot carry a reCAPTCHA
 * v3 score, the hostname the challenge was solved on, or the action it was minted for — so those
 * fields were fetched from the provider and thrown away, and the checks that depend on them were
 * never written. Everything the provider tells us is kept here, and the decisions are made against
 * it explicitly.
 */
final readonly class VerificationResult
{
    /**
     * @param list<ErrorCode> $errors
     * @param array<string, mixed> $raw the provider's response body, never the request
     */
    private function __construct(
        public bool $verified,
        public Outcome $outcome,
        public ?float $score = null,
        public ?string $action = null,
        public ?string $hostname = null,
        public ?DateTimeImmutable $challengeAt = null,
        public array $errors = [],
        public array $raw = [],
        public ?int $durationMs = null,
    ) {}

    /**
     * @param array<string, mixed> $raw
     */
    public static function passed(
        Outcome $outcome = Outcome::Allow,
        ?float $score = null,
        ?string $action = null,
        ?string $hostname = null,
        ?DateTimeImmutable $challengeAt = null,
        array $raw = [],
    ): self {
        return new self(
            verified: true,
            outcome: $outcome,
            score: $score,
            action: $action,
            hostname: $hostname,
            challengeAt: $challengeAt,
            raw: $raw,
        );
    }

    /**
     * The only way to express a failure, and it is always terminal.
     *
     * @param ErrorCode|list<ErrorCode> $errors
     *
     * Adapters must route every failure through here — a transport error, a non-2xx status, a body
     * that did not parse. The contract is that no failure path anywhere produces a verified result
     * and no failure path throws: an exception escaping an adapter would surface as a 500 on a
     * login form, which is both a worse experience and a fingerprintable oracle.
     * @param array<string, mixed> $raw
     */
    public static function failed(
        ErrorCode|array $errors,
        ?float $score = null,
        ?string $action = null,
        ?string $hostname = null,
        ?DateTimeImmutable $challengeAt = null,
        array $raw = [],
    ): self {
        return new self(
            verified: false,
            outcome: Outcome::Block,
            score: $score,
            action: $action,
            hostname: $hostname,
            challengeAt: $challengeAt,
            errors: $errors instanceof ErrorCode ? [$errors] : array_values($errors),
            raw: $raw,
        );
    }

    /** Whether the validation rule should let this through. */
    public function passes(): bool
    {
        return $this->verified && $this->outcome->passesValidation();
    }

    public function failedBecause(ErrorCode $code): bool
    {
        return in_array($code, $this->errors, true);
    }

    public function firstError(): ?ErrorCode
    {
        return $this->errors[0] ?? null;
    }

    /** Whether any recorded failure is a misconfiguration rather than a suspicious visitor. */
    public function isOperatorFault(): bool
    {
        return array_any($this->errors, fn (ErrorCode $error): bool => $error->isOperatorFault());
    }

    /**
     * A copy stamped with how long verification took.
     *
     * Separate because the result is constructed inside the adapter while the clock runs around
     * it in the action — there is no moment where both facts are in the same scope.
     */
    public function withDuration(int $durationMs): self
    {
        return new self(
            verified: $this->verified,
            outcome: $this->outcome,
            score: $this->score,
            action: $this->action,
            hostname: $this->hostname,
            challengeAt: $this->challengeAt,
            errors: $this->errors,
            raw: $this->raw,
            durationMs: $durationMs,
        );
    }

    /** A copy carrying an additional failure, used by the post-verification checks. */
    public function withError(ErrorCode $code): self
    {
        return new self(
            verified: false,
            outcome: Outcome::Block,
            score: $this->score,
            action: $this->action,
            hostname: $this->hostname,
            challengeAt: $this->challengeAt,
            errors: [...$this->errors, $code],
            raw: $this->raw,
            durationMs: $this->durationMs,
        );
    }

    /** A copy at a different outcome, used when a score lands in the review band. */
    public function withOutcome(Outcome $outcome): self
    {
        return new self(
            verified: $this->verified,
            outcome: $outcome,
            score: $this->score,
            action: $this->action,
            hostname: $this->hostname,
            challengeAt: $this->challengeAt,
            errors: $this->errors,
            raw: $this->raw,
            durationMs: $this->durationMs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verified'     => $this->verified,
            'outcome'      => $this->outcome->value,
            'score'        => $this->score,
            'action'       => $this->action,
            'hostname'     => $this->hostname,
            'challenge_at' => $this->challengeAt?->format(DATE_ATOM),
            'errors'       => array_map(static fn (ErrorCode $e): string => $e->value, $this->errors),
            'duration_ms'  => $this->durationMs,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\ValueObjects;

/**
 * Everything about the request being protected that verification needs.
 *
 * Passed in rather than read from globals, so that verification works identically inside a queued
 * job, an Artisan command and a console test — `request()` is null in all three, and the package
 * this replaces called it unconditionally inside every driver.
 *
 * It is also what makes the replay checks possible: an action name and an expected hostname have
 * to come from the caller, because only the caller knows which form this is.
 */
final readonly class VerificationContext
{
    public function __construct(
        /** The action this token was minted for; checked against the provider's echo of it. */
        public ?string $action = null,
        /** The visitor's address, forwarded to the provider as `remoteip` where supported. */
        public ?string $remoteIp = null,
        /**
         * Hostnames the challenge may legitimately have been solved on.
         *
         * @var list<string>
         */
        public array $allowedHostnames = [],
        /** Lets one verification be retried safely where the provider supports it. */
        public ?string $idempotencyKey = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function withAction(?string $action): self
    {
        return new self($action, $this->remoteIp, $this->allowedHostnames, $this->idempotencyKey);
    }

    /**
     * @param array<array-key, mixed> $hostnames
     */
    public function withAllowedHostnames(array $hostnames): self
    {
        // Filtered rather than trusted: this is a public setter, and a non-string slipping into
        // the allow-list would be compared against a hostname with `in_array`'s strict flag and
        // silently never match — a hostname check that quietly always fails.
        return new self(
            $this->action,
            $this->remoteIp,
            array_values(array_filter($hostnames, is_string(...))),
            $this->idempotencyKey,
        );
    }
}

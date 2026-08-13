<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\Concerns;

use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Simtabi\Laranail\Captcha\Contracts\CaptchaAdapter;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Support\CaptchaHttp;
use Simtabi\Laranail\Captcha\ValueObjects\Credentials;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;
use Throwable;

/**
 * The shared shape of a "POST the token to a siteverify endpoint" provider.
 *
 * Turnstile, hCaptcha and every reCAPTCHA version answer the same handful of fields — `success`,
 * `challenge_ts`, `hostname`, `error-codes`, and for the scored ones `score` and `action` — so the
 * transport, the failure handling and the response mapping live here once. Subclasses supply the
 * URL, the payload and any vendor-specific reading of the body.
 *
 * The fail-closed contract is enforced structurally rather than by asking implementors to
 * remember it: {@see self::verify()} is `final` and wraps everything in a `Throwable` catch, so
 * there is no path through an adapter that throws or that returns success on an unparsed body.
 */
abstract class SiteVerifyAdapter implements CaptchaAdapter
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        protected readonly Credentials $credentials,
        protected readonly CaptchaHttp $http,
        protected readonly array $options = [],
    ) {}

    final public function verify(string $token, VerificationContext $context): VerificationResult
    {
        if (! $this->isConfigured()) {
            return VerificationResult::failed(ErrorCode::NotConfigured);
        }

        try {
            $response = $this->send($token, $context);
        } catch (Throwable) {
            // Deliberately swallowed rather than reported with the exception message. A Guzzle
            // RequestException stringifies the request it failed on, and that request body
            // contains the secret key — reporting it here is how the secret ends up in the log.
            // The caller records a transport failure with the provider name and nothing else.
            return VerificationResult::failed(ErrorCode::TransportError);
        }

        if (! $response->successful()) {
            return VerificationResult::failed(
                $response->status() === 401 || $response->status() === 403
                    ? ErrorCode::InvalidSecret
                    : ErrorCode::TransportError,
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            return VerificationResult::failed(ErrorCode::MalformedResponse);
        }

        return $this->mapResponse(self::keyed($body));
    }

    public function isConfigured(): bool
    {
        return $this->credentials->isComplete();
    }

    /** The vendor's server-side verification endpoint. */
    abstract protected function verifyUrl(): string;

    /**
     * @return array<string, mixed>
     */
    protected function payload(string $token, VerificationContext $context): array
    {
        $payload = [
            'secret' => $this->credentials->secret,
            'response' => $token,
        ];

        // Only sent when the caller supplied one. Reading it from `request()` here would break
        // verification inside a queued job, and would forward whatever the proxy headers claimed
        // if the application's trusted-proxy configuration is wrong.
        if ($context->remoteIp !== null) {
            $payload['remoteip'] = $context->remoteIp;
        }

        return $payload;
    }

    protected function send(string $token, VerificationContext $context): Response
    {
        return $this->client()->asForm()->post($this->verifyUrl(), $this->payload($token, $context));
    }

    protected function client(): PendingRequest
    {
        return $this->http->request();
    }

    /**
     * Normalise a successful HTTP response into a result.
     *
     * @param array<string, mixed> $body
     */
    protected function mapResponse(array $body): VerificationResult
    {
        $errors = $this->mapErrorCodes($body);

        if (($body['success'] ?? false) !== true) {
            return VerificationResult::failed(
                $errors === [] ? [ErrorCode::InvalidResponse] : $errors,
                action: $this->stringOrNull($body, 'action'),
                hostname: $this->stringOrNull($body, 'hostname'),
                challengeAt: $this->challengeTimestamp($body),
                raw: $body,
            );
        }

        return VerificationResult::passed(
            score: isset($body['score']) && is_numeric($body['score']) ? (float) $body['score'] : null,
            action: $this->stringOrNull($body, 'action'),
            hostname: $this->stringOrNull($body, 'hostname'),
            challengeAt: $this->challengeTimestamp($body),
            raw: $body,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return list<ErrorCode>
     */
    protected function mapErrorCodes(array $body): array
    {
        $codes = $body['error-codes'] ?? $body['error_codes'] ?? [];

        if (! is_array($codes)) {
            return [];
        }

        $mapped = [];

        foreach ($codes as $code) {
            if (! is_string($code)) {
                continue;
            }

            $mapped[] = match ($code) {
                'missing-input-response' => ErrorCode::MissingResponse,
                'invalid-input-response',
                'bad-request',
                'invalid-or-already-seen-response' => ErrorCode::InvalidResponse,
                'timeout-or-duplicate' => ErrorCode::ExpiredOrDuplicate,
                'missing-input-secret',
                'invalid-input-secret',
                'invalid-keys' => ErrorCode::InvalidSecret,
                default => ErrorCode::ProviderError,
            };
        }

        return array_values(array_unique($mapped, SORT_REGULAR));
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function challengeTimestamp(array $body): ?DateTimeImmutable
    {
        $timestamp = $body['challenge_ts'] ?? null;

        if (! is_string($timestamp) || $timestamp === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($timestamp);
        } catch (Throwable) {
            // A timestamp we cannot parse is treated as absent rather than as a failure. The
            // freshness check then has nothing to work with and says so, which is better than
            // rejecting every visitor because a vendor changed its date format.
            return null;
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function stringOrNull(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Keep only string keys.
     *
     * A JSON body decodes to `array<mixed, mixed>` as far as the analyser is concerned, because
     * `{"0": …}` is legal JSON and yields an integer key. Every field these APIs document is named,
     * so anything numeric is not part of the contract and is dropped rather than reasoned about.
     *
     * @param array<mixed, mixed> $body
     * @return array<string, mixed>
     */
    protected static function keyed(array $body): array
    {
        /** @var array<string, mixed> $keyed */
        $keyed = array_filter($body, is_string(...), ARRAY_FILTER_USE_KEY);

        return $keyed;
    }

    protected function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}

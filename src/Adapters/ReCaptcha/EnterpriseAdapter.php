<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\ReCaptcha;

use DateTimeImmutable;
use Illuminate\Http\Client\Response;
use Simtabi\Laranail\Captcha\Adapters\Concerns\SiteVerifyAdapter;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Support\Locale;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;
use Throwable;

/**
 * reCAPTCHA Enterprise — the Assessment API, not siteverify.
 *
 * Different endpoint, different auth, different response shape, and billed per assessment. It does
 * not extend {@see V2Adapter} for that reason: sharing the base would mean overriding every method
 * on it, which reads as inheritance where there is none.
 *
 * Authenticated with an API key on the query string. A service-account OAuth token is the other
 * supported path and is stronger, but it needs `google/auth` and a credentials file; the API key
 * covers the common case with no extra dependency, and `docs/tools/recaptcha.md` explains how to
 * restrict it.
 */
final class EnterpriseAdapter extends SiteVerifyAdapter
{
    public const string ENDPOINT = 'https://recaptchaenterprise.googleapis.com/v1/projects/%s/assessments';

    public function provider(): Provider
    {
        return Provider::ReCaptchaEnterprise;
    }

    public function widget(string $instanceId): Widget
    {
        return new Widget(
            provider: Provider::ReCaptchaEnterprise,
            instanceId: $instanceId,
            containerClass: 'g-recaptcha',
            attributes: [
                'data-sitekey' => $this->credentials->siteKey,
                'data-action' => is_string($this->option('action')) ? $this->option('action') : null,
            ],
            scriptUrl: V2Adapter::SCRIPT_URL.'?render='.rawurlencode($this->credentials->siteKey)
                .'&hl='.rawurlencode(
                    Locale::sanitise(is_string($this->option('language')) ? $this->option('language') : null) ?? 'en',
                ),
        );
    }

    /**
     * The secret here is the API key, and the project id is a separate required value.
     *
     * Both are checked, because a missing project id produces a URL with an empty path segment and
     * a 404 that reads like an outage rather than a configuration error.
     */
    public function isConfigured(): bool
    {
        return $this->credentials->siteKey !== ''
            && $this->credentials->secret !== ''
            && $this->credentials->extra('project_id') !== null;
    }

    protected function verifyUrl(): string
    {
        return sprintf(self::ENDPOINT, rawurlencode((string) $this->credentials->extra('project_id')));
    }

    protected function send(string $token, VerificationContext $context): Response
    {
        $event = [
            'token' => $token,
            'siteKey' => $this->credentials->siteKey,
        ];

        if ($context->action !== null) {
            $event['expectedAction'] = $context->action;
        }

        if ($context->remoteIp !== null) {
            $event['userIpAddress'] = $context->remoteIp;
        }

        return $this->http
            ->request()
            ->post($this->verifyUrl().'?key='.rawurlencode($this->credentials->secret), [
                'event' => $event,
            ]);
    }

    protected function mapResponse(array $body): VerificationResult
    {
        $properties = $body['tokenProperties'] ?? null;

        if (! is_array($properties)) {
            return VerificationResult::failed(ErrorCode::MalformedResponse, raw: $body);
        }

        $properties = self::keyed($properties);

        $analysis = $body['riskAnalysis'] ?? null;
        $score = is_array($analysis) ? ($analysis['score'] ?? null) : null;
        $action = is_string($properties['action'] ?? null) ? $properties['action'] : null;
        $hostname = is_string($properties['hostname'] ?? null) ? $properties['hostname'] : null;
        $createdAt = $this->createTime($properties);

        if (($properties['valid'] ?? false) !== true) {
            return VerificationResult::failed(
                $this->mapInvalidReason(is_string($properties['invalidReason'] ?? null) ? $properties['invalidReason'] : null),
                action: $action,
                hostname: $hostname,
                challengeAt: $createdAt,
                raw: $body,
            );
        }

        return VerificationResult::passed(
            score: is_numeric($score) ? (float) $score : null,
            action: $action,
            hostname: $hostname,
            challengeAt: $createdAt,
            raw: $body,
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function createTime(array $properties): ?DateTimeImmutable
    {
        $value = $properties['createTime'] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function mapInvalidReason(?string $reason): ErrorCode
    {
        return match ($reason) {
            'EXPIRED', 'DUPE' => ErrorCode::ExpiredOrDuplicate,
            'MALFORMED', 'INVALID_REASON_UNSPECIFIED' => ErrorCode::InvalidResponse,
            'SITE_MISMATCH' => ErrorCode::HostnameMismatch,
            'MISSING' => ErrorCode::MissingResponse,
            'BROWSER_ERROR' => ErrorCode::ProviderError,
            default => ErrorCode::InvalidResponse,
        };
    }
}

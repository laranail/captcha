<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\FriendlyCaptcha;

use Illuminate\Http\Client\Response;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\Adapters\Concerns\SiteVerifyAdapter;

/**
 * Friendly Captcha v2 — invisible proof-of-work, EU-hosted, cookie-free.
 *
 * Defaults to the EU endpoint rather than the global one, because data residency is the reason
 * most people choose this provider; an integration that quietly routes through `global.` gives
 * away the property it was picked for.
 *
 * The v2 API departs from the siteverify family in three ways: the secret travels in an
 * `X-API-Key` header rather than the body, the request is JSON rather than form-encoded, and
 * failures come back as a single structured `error` object instead of an `error-codes` array.
 */
final class FriendlyCaptchaAdapter extends SiteVerifyAdapter
{
    public const string EU_URL = 'https://eu.frcapi.com/api/v2/captcha/siteverify';

    public const string GLOBAL_URL = 'https://global.frcapi.com/api/v2/captcha/siteverify';

    public const string SCRIPT_URL = 'https://cdn.jsdelivr.net/npm/@friendlycaptcha/sdk@0.1/site.min.js';

    public function provider(): Provider
    {
        return Provider::FriendlyCaptcha;
    }

    public function widget(string $instanceId): Widget
    {
        return new Widget(
            provider: Provider::FriendlyCaptcha,
            instanceId: $instanceId,
            containerClass: 'frc-captcha',
            attributes: [
                'data-sitekey' => $this->credentials->siteKey,
                'data-start'   => is_string($this->option('start')) ? $this->option('start') : 'focus',
            ],
            scriptUrl: self::SCRIPT_URL,
        );
    }

    protected function verifyUrl(): string
    {
        return $this->option('endpoint') === 'global' ? self::GLOBAL_URL : self::EU_URL;
    }

    protected function send(string $token, VerificationContext $context): Response
    {
        return $this->http
            ->request(['X-API-Key' => $this->credentials->secret])
            ->post($this->verifyUrl(), [
                'response' => $token,
                'sitekey'  => $this->credentials->siteKey,
            ]);
    }

    protected function mapResponse(array $body): VerificationResult
    {
        if (($body['success'] ?? false) === true) {
            return VerificationResult::passed(raw: $body);
        }

        $error = $body['error'] ?? null;
        $code = is_array($error) ? ($error['error_code'] ?? null) : null;

        return VerificationResult::failed(match ($code) {
            'auth_required', 'auth_invalid'          => ErrorCode::InvalidSecret,
            'sitekey_invalid'                        => ErrorCode::InvalidSecret,
            'response_missing'                       => ErrorCode::MissingResponse,
            'response_invalid'                       => ErrorCode::InvalidResponse,
            'response_timeout', 'response_duplicate' => ErrorCode::ExpiredOrDuplicate,
            default                                  => ErrorCode::ProviderError,
        }, raw: $body);
    }
}

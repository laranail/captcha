<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\Arkose;

use Simtabi\Laranail\Captcha\Adapters\Concerns\SiteVerifyAdapter;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;

/**
 * Arkose Labs (FunCaptcha) Verify v4.
 *
 * The endpoint is per-customer — `https://{client}-verify.arkoselabs.com` — so the subdomain is a
 * credential extra rather than a constant. Without it the adapter reports itself unconfigured
 * instead of posting to a host that does not exist.
 *
 * The field that decides the outcome is `session_details.solved`, nested two levels down and
 * absent entirely on some error shapes. Reading it defensively matters more here than elsewhere:
 * `$body['session_details']['solved'] ?? true` would be a catastrophic default, so the check is
 * written as an explicit identity comparison against `true`.
 */
final class ArkoseAdapter extends SiteVerifyAdapter
{
    public const string SCRIPT_HOST = 'https://client-api.arkoselabs.com';

    public function provider(): Provider
    {
        return Provider::Arkose;
    }

    public function widget(string $instanceId): Widget
    {
        return new Widget(
            provider: Provider::Arkose,
            instanceId: $instanceId,
            containerClass: 'arkose-captcha',
            attributes: ['data-sitekey' => $this->credentials->siteKey],
            scriptUrl: self::SCRIPT_HOST.'/v2/'.rawurlencode($this->credentials->siteKey).'/api.js',
        );
    }

    public function isConfigured(): bool
    {
        return parent::isConfigured() && $this->credentials->extra('client') !== null;
    }

    protected function verifyUrl(): string
    {
        return sprintf(
            'https://%s-verify.arkoselabs.com/api/v4/verify/',
            (string) $this->credentials->extra('client'),
        );
    }

    protected function payload(string $token, VerificationContext $context): array
    {
        return [
            'private_key' => $this->credentials->secret,
            'session_token' => $token,
        ];
    }

    protected function mapResponse(array $body): VerificationResult
    {
        $details = $body['session_details'] ?? null;

        if (! is_array($details)) {
            return VerificationResult::failed(ErrorCode::MalformedResponse, raw: $body);
        }

        if (($details['solved'] ?? null) !== true) {
            return VerificationResult::failed(ErrorCode::InvalidResponse, raw: $body);
        }

        // `suppressed` means Arkose let the session through without presenting a challenge
        // because it judged the traffic safe. That is a pass, and it is the normal case for most
        // real visitors — treating it as a failure would block almost everyone.
        return VerificationResult::passed(
            score: isset($details['score']) && is_numeric($details['score'])
                ? (float) $details['score']
                : null,
            raw: $body,
        );
    }
}

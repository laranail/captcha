<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;

/**
 * Why a verification failed, normalised across every provider.
 *
 * Each vendor names the same handful of failures differently — Cloudflare's
 * `timeout-or-duplicate`, Google's `timeout-or-duplicate`, hCaptcha's `invalid-or-already-seen
 * -response`, Friendly Captcha's structured `error_code`. Mapping them onto one set is what lets
 * the validation message, the log line and the doctor output be written once, and what lets a host
 * application branch on a reason without knowing which provider is configured.
 */
enum ErrorCode: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Missing response')]
    #[Description('The request carried no captcha response at all.')]
    case MissingResponse = 'missing-response';

    #[Label('Invalid response')]
    #[Description('The provider rejected the token as malformed or unknown.')]
    case InvalidResponse = 'invalid-response';

    #[Label('Expired or already used')]
    #[Description('The token was already redeemed, or aged out before it was verified.')]
    case ExpiredOrDuplicate = 'expired-or-duplicate';

    #[Label('Replayed')]
    #[Description('This exact token was verified before by this application.')]
    case Replayed = 'replayed';

    #[Label('Stale challenge')]
    #[Description('The challenge was solved longer ago than the freshness window allows.')]
    case Stale = 'stale';

    #[Label('Hostname mismatch')]
    #[Description('The challenge was solved on a host this application does not serve.')]
    case HostnameMismatch = 'hostname-mismatch';

    #[Label('Action mismatch')]
    #[Description('The token was minted for a different action than the one being protected.')]
    case ActionMismatch = 'action-mismatch';

    #[Label('Score below threshold')]
    #[Description('The provider scored the interaction below the configured threshold.')]
    case LowScore = 'low-score';

    #[Label('Not configured')]
    #[Description('The provider has no usable credentials in this environment.')]
    case NotConfigured = 'not-configured';

    #[Label('Invalid secret')]
    #[Description('The provider rejected the secret key. A configuration fault, not a bot.')]
    case InvalidSecret = 'invalid-secret';

    #[Label('Transport error')]
    #[Description('The provider could not be reached, or timed out.')]
    case TransportError = 'transport-error';

    #[Label('Malformed provider response')]
    #[Description('The provider answered with something that was not the documented shape.')]
    case MalformedResponse = 'malformed-response';

    #[Label('Provider error')]
    #[Description('The provider reported a failure that maps to none of the above.')]
    case ProviderError = 'provider-error';

    /**
     * Whether this failure is the application's fault rather than the visitor's.
     *
     * Operator faults deserve a loud log line and a doctor warning; visitor faults are ordinary
     * traffic and must not be logged at error level, or a bot flood becomes a disk-space
     * incident.
     */
    public function isOperatorFault(): bool
    {
        return in_array($this, [
            self::NotConfigured,
            self::InvalidSecret,
            self::TransportError,
            self::MalformedResponse,
        ], true);
    }

    /** The translation key carrying the visitor-facing message for this failure. */
    public function translationKey(): string
    {
        return 'laranail-captcha::validation.'.$this->value;
    }
}

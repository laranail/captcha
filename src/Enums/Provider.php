<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Captcha\Support\ResponseField;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Captcha\Contracts\CaptchaAdapter;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Captcha\Adapters\ReCaptcha\V2Adapter;
use Simtabi\Laranail\Captcha\Adapters\ReCaptcha\V3Adapter;
use Simtabi\Laranail\Captcha\Adapters\Altcha\AltchaAdapter;
use Simtabi\Laranail\Captcha\Adapters\Arkose\ArkoseAdapter;
use Simtabi\Laranail\Captcha\Adapters\Math\MathCaptchaAdapter;
use Simtabi\Laranail\Captcha\Adapters\HCaptcha\HCaptchaAdapter;
use Simtabi\Laranail\Captcha\Adapters\NullProvider\NullAdapter;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;
use Simtabi\Laranail\Captcha\Adapters\Turnstile\TurnstileAdapter;
use Simtabi\Laranail\Captcha\Adapters\ReCaptcha\EnterpriseAdapter;
use Simtabi\Laranail\Captcha\Adapters\ReCaptcha\V2InvisibleAdapter;
use Simtabi\Laranail\Captcha\Adapters\FriendlyCaptcha\FriendlyCaptchaAdapter;

/**
 * The providers this package can be configured to use, one at a time.
 *
 * This enum *is* the allow-list. Resolution goes through {@see self::adapter()} and nothing
 * else — never `'create' . Str::studly($name) . 'Driver'`, and never a class-string read from
 * configuration. The provider name reaches us from a config file an operator edits, and in a
 * multi-tenant install from a database row; interpolating either into a class name turns a
 * settings mistake into arbitrary instantiation.
 */
enum Provider: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Cloudflare Turnstile')]
    #[Description('Invisible for most visitors, free at any volume, Cloudflare-hosted.')]
    case Turnstile = 'turnstile';

    #[Label('hCaptcha')]
    #[Description('Higher catch rate than Turnstile with enterprise tiers; privacy-oriented.')]
    case HCaptcha = 'hcaptcha';

    #[Label('reCAPTCHA v2')]
    #[Description('The classic checkbox. Widest adoption, sends visitor data to Google.')]
    case ReCaptchaV2 = 'recaptcha-v2';

    #[Label('reCAPTCHA v2 invisible')]
    #[Description('v2 scoring without the checkbox; executed on submit, may still show a puzzle.')]
    case ReCaptchaV2Invisible = 'recaptcha-v2-invisible';

    #[Label('reCAPTCHA v3')]
    #[Description('Score-only, never interrupts, requires a threshold and an action name.')]
    case ReCaptchaV3 = 'recaptcha-v3';

    #[Label('reCAPTCHA Enterprise')]
    #[Description('Assessment API with richer risk signals; billed per assessment.')]
    case ReCaptchaEnterprise = 'recaptcha-enterprise';

    #[Label('Friendly Captcha')]
    #[Description('Invisible proof-of-work, EU-hosted, cookie-free, GDPR-compliant by design.')]
    case FriendlyCaptcha = 'friendly-captcha';

    #[Label('Arkose Labs')]
    #[Description('Enterprise-grade defence against sophisticated bot farms. Paid, account-gated.')]
    case Arkose = 'arkose';

    #[Label('ALTCHA')]
    #[Description('Self-hosted proof-of-work. No third-party round trip, no cookies, no penalty for Tor, VPN or Brave users.')]
    case Altcha = 'altcha';

    #[Label('Math')]
    #[Description('Self-hosted arithmetic. No third party, no JavaScript required, one guess per question.')]
    case Math = 'math';

    #[Label('Null')]
    #[Description('Test double. Verifies everything or nothing, and is refused in production.')]
    case NullProvider = 'null';

    /**
     * The adapter that implements this provider.
     *
     * @return class-string<CaptchaAdapter>
     */
    public function adapter(): string
    {
        return match ($this) {
            self::Turnstile            => TurnstileAdapter::class,
            self::HCaptcha             => HCaptchaAdapter::class,
            self::ReCaptchaV2          => V2Adapter::class,
            self::ReCaptchaV2Invisible => V2InvisibleAdapter::class,
            self::ReCaptchaV3          => V3Adapter::class,
            self::ReCaptchaEnterprise  => EnterpriseAdapter::class,
            self::FriendlyCaptcha      => FriendlyCaptchaAdapter::class,
            self::Arkose               => ArkoseAdapter::class,
            self::Altcha               => AltchaAdapter::class,
            self::Math                 => MathCaptchaAdapter::class,
            self::NullProvider         => NullAdapter::class,
        };
    }

    /**
     * The field name the provider's own widget writes its token into.
     *
     * Forms should bind to {@see ResponseField::CANONICAL}
     * instead; this is what the vendor script produces and what the request is normalised from,
     * so that switching provider does not rewrite every form and validation rule in the host
     * application.
     */
    public function vendorResponseField(): string
    {
        return match ($this) {
            self::Turnstile => 'cf-turnstile-response',
            self::HCaptcha  => 'h-captcha-response',
            self::ReCaptchaV2,
            self::ReCaptchaV2Invisible,
            self::ReCaptchaV3,
            self::ReCaptchaEnterprise => 'g-recaptcha-response',
            self::FriendlyCaptcha     => 'frc-captcha-response',
            self::Arkose              => 'arkose-token',
            self::Altcha              => 'altcha',
            self::Math                => 'captcha-answer',
            self::NullProvider        => 'null-captcha-response',
        };
    }

    /** Whether the challenge is minted by this application rather than a vendor. */
    public function isSelfHosted(): bool
    {
        return $this === self::Altcha || $this === self::Math;
    }

    /**
     * Whether a verified response carries a risk score rather than a pass/fail.
     *
     * Score-bearing providers are the ones where discarding the response body loses the actual
     * signal, which is what the package this replaced did with reCAPTCHA v3.
     */
    public function isScoreBased(): bool
    {
        return in_array($this, [self::ReCaptchaV3, self::ReCaptchaEnterprise, self::Arkose], true);
    }

    /**
     * Whether the widget must be executed programmatically on submit.
     *
     * These have no checkbox to click, so a container component alone renders nothing usable —
     * the form needs the explicit-render path.
     */
    public function requiresExplicitExecution(): bool
    {
        return in_array($this, [self::ReCaptchaV2Invisible, self::ReCaptchaV3], true);
    }

    /**
     * The global function that resets this provider's widget, if it has one.
     *
     * Called when a token expires, times out or errors. Tokens are short-lived — 300 seconds for
     * Turnstile, 120 for reCAPTCHA v2 — so a form left open while someone finds their card details
     * submits a dead token and fails for a reason the visitor cannot act on. Resetting mints a
     * fresh one instead.
     *
     * Null for the self-hosted providers: there is no vendor widget to reset, and recovery means
     * re-fetching a challenge from this application.
     */
    public function resetFunction(): ?string
    {
        return match ($this) {
            self::Turnstile => 'turnstile.reset',
            self::HCaptcha  => 'hcaptcha.reset',
            self::ReCaptchaV2,
            self::ReCaptchaV2Invisible,
            self::ReCaptchaV3,
            self::ReCaptchaEnterprise => 'grecaptcha.reset',
            default                   => null,
        };
    }

    /**
     * Whether the rendered widget owns state the server cannot reconstruct.
     *
     * A vendor widget is a live iframe holding a session with the provider. Letting a Livewire
     * morph replace that node throws the session away — including an already-solved one — and the
     * visitor gets no indication beyond a form that stops working. Those containers are skipped
     * during a morph.
     *
     * The self-hosted providers are the opposite case: their markup is server-rendered and *should*
     * be replaced, because a re-render is how a fresh challenge arrives. Skipping them would pin an
     * expired question on screen.
     */
    public function hasLiveVendorState(): bool
    {
        return ! $this->isSelfHosted() && $this !== self::NullProvider;
    }

    /** The config sub-key holding this provider's non-credential options. */
    public function optionsKey(): string
    {
        return match ($this) {
            self::ReCaptchaV2,
            self::ReCaptchaV2Invisible,
            self::ReCaptchaV3,
            self::ReCaptchaEnterprise => 'recaptcha',
            default                   => $this->value,
        };
    }
}

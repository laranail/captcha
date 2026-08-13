<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Contracts;

use Simtabi\Laranail\Captcha\Actions\VerifyCaptcha;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;

/**
 * The port every captcha vendor is adapted to.
 *
 * Implementations MUST fail closed. Any transport error, non-2xx response, or malformed body has
 * to yield a failed {@see VerificationResult} rather than throwing or reporting success. An
 * adapter that throws turns a provider outage into a 500 on the login form; an adapter that
 * returns success on a parse failure turns one into an open door. `tests/Feature/AdapterContract
 * Test.php` asserts both for every adapter in the enum, and a new adapter is not finished until it
 * passes that suite.
 *
 * Implementations take their credentials, HTTP client, clock and options by constructor injection.
 * They may not call `config()`, `request()`, `app()` or any facade — `tests/Arch` fails the build
 * if they do. The package this replaces read `config('captcha.sitekey')` inline, which is why its
 * credentials could never be sourced from anywhere else.
 */
interface CaptchaAdapter
{
    /**
     * Ask the provider whether this token represents a solved challenge.
     *
     * Post-verification checks that are the same for every vendor — replay, freshness, hostname,
     * action, score — belong in {@see VerifyCaptcha}, not here.
     * An adapter's job is to talk to its vendor and normalise the answer.
     */
    public function verify(string $token, VerificationContext $context): VerificationResult;

    /** The markup contract for this provider's widget, for one instance on one page. */
    public function widget(string $instanceId): Widget;

    /**
     * Whether this adapter has what it needs to run.
     *
     * Checked before verification so a missing key produces
     * {@see ErrorCode::NotConfigured} and a doctor warning, rather
     * than a round trip that fails for a reason nobody can diagnose.
     */
    public function isConfigured(): bool;

    public function provider(): Provider;
}

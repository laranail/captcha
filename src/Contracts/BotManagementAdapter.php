<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Contracts;

use Illuminate\Http\Request;
use Simtabi\Laranail\Captcha\Enums\BotDecision;

/**
 * The port for the edge bot-management tier — DataDome, HUMAN, Kasada.
 *
 * A separate axis from {@see CaptchaAdapter}, and deliberately not modelled as a provider. These
 * products have no site key, no widget and no token to verify: they inspect every request as it
 * arrives and answer with a verdict. Forcing them into the captcha port would mean stubbing
 * `widget()` and inventing a token, which would misrepresent what they do to anyone reading the
 * code.
 *
 * Only DataDome ships as a concrete adapter. HUMAN and Kasada are sold with dedicated integration
 * support and cannot be tested without a paid account, and shipping two unverifiable integrations
 * would be claiming coverage we cannot stand behind — so they are documented extension points on
 * this interface instead.
 */
interface BotManagementAdapter
{
    /**
     * Decide what to do with an incoming request.
     *
     * Must fail open: when the provider cannot be reached, return
     * {@see BotDecision::whenUnavailable()}. This runs in front of every request, so failing
     * closed on an outage takes the site down to stop traffic that was probably legitimate — the
     * opposite of the call captcha verification makes, for the opposite reason.
     */
    public function decide(Request $request): BotDecision;

    public function isConfigured(): bool;

    public function name(): string;
}

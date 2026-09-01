<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\BotManagement;

use Illuminate\Http\Request;
use Simtabi\Laranail\Captcha\Contracts\BotManagementAdapter;
use Simtabi\Laranail\Captcha\Enums\BotDecision;

/**
 * Bot management, switched off.
 *
 * Bound whenever the feature is disabled or no adapter is configured, so the middleware can be
 * registered unconditionally and still be a no-op. That matters more than it sounds: a middleware
 * an application adds and removes as it changes providers is a middleware someone eventually
 * forgets to add back.
 *
 * Unlike the null *captcha* adapter, this needs no production guard. Allowing every request is what
 * an application without bot management already does — it removes no protection that was there.
 */
final readonly class NullBotManager implements BotManagementAdapter
{
    public function decide(Request $request): BotDecision
    {
        return BotDecision::Allow;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'null';
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Contracts\Events\Dispatcher;
use Symfony\Component\HttpFoundation\Response;
use Simtabi\Laranail\Captcha\Enums\BotDecision;
use Simtabi\Laranail\Captcha\Events\BotRequestBlocked;
use Simtabi\Laranail\Captcha\Contracts\BotManagementAdapter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Runs the edge bot-management verdict in front of a route.
 *
 * Register it where you want it — a route group, or globally. It is safe to add globally even with
 * bot management switched off, because the null adapter is bound in that case and this becomes a
 * pass-through; a middleware that has to be added and removed as configuration changes is one that
 * eventually gets left out.
 *
 * Note the asymmetry with captcha verification, which fails closed. This sits in front of every
 * request, so a provider outage failing closed would take the site down to stop traffic that was
 * probably fine. The adapter returns an allow verdict when it cannot reach its provider, and that
 * decision belongs there rather than here — this class does not know what "unavailable" means.
 */
final readonly class ProtectAgainstBots
{
    public function __construct(
        private BotManagementAdapter $adapter,
        private Dispatcher $events,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $decision = $this->adapter->decide($request);

        if ($decision === BotDecision::Allow) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $this->events->dispatch(new BotRequestBlocked($this->adapter->name(), $decision, $request));

        // A flat 403 for every non-allow verdict. Rendering the provider's own interstitial or
        // following its redirect means proxying a third party's markup into your response, which
        // is a decision an application should make deliberately — so it listens for the event and
        // does that itself rather than having it imposed here.
        throw new AccessDeniedHttpException;
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Listeners;

use Illuminate\Contracts\Foundation\Application;
use Simtabi\Laranail\Captcha\Actions\ResolveCredentials;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Throwable;

/**
 * Clears the state that must not survive a request under Octane.
 *
 * `CaptchaService` memoises its adapter and the credential chain is a singleton, so on a warm
 * worker a key rotated in the database stays invisible until the worker restarts — which can be
 * hours. The admin UI appears to save and nothing changes, and nothing anywhere says why.
 *
 * `CaptchaConfig` is deliberately left alone. A memo of config reads is exactly what should stay
 * warm in a long-running process; flushing it would cost every request for no correctness gain.
 */
final readonly class ResetCaptchaState
{
    /**
     * The singletons whose contents are request-scoped in effect, if not in name.
     *
     * @var list<class-string>
     */
    private const array FORGETS = [
        CaptchaService::class,
        CredentialStore::class,
        ResolveCredentials::class,
    ];

    public function __construct(private Application $app) {}

    public function handle(): void
    {
        foreach (self::FORGETS as $abstract) {
            try {
                // `resolved()` first. Calling `forgetInstance()` is harmless, but any variant that
                // builds the binding in order to reset it would construct the whole credential
                // chain — including a database probe — at every request boundary, on every request
                // that never touched a captcha.
                if ($this->app->resolved($abstract)) {
                    $this->app->forgetInstance($abstract);
                }
            } catch (Throwable) {
                // A reset that throws kills the worker between requests, taking every subsequent
                // request with it. Failing to clear one binding is recoverable; that is not.
                continue;
            }
        }
    }
}

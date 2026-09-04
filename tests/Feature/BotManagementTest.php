<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Client\ConnectionException;
use Simtabi\Laranail\Captcha\Enums\BotDecision;
use Simtabi\Laranail\Captcha\Events\BotRequestBlocked;
use Simtabi\Laranail\Captcha\BotManagement\NullBotManager;
use Simtabi\Laranail\Captcha\Contracts\BotManagementAdapter;
use Simtabi\Laranail\Captcha\Http\Middleware\ProtectAgainstBots;

/**
 * The edge tier, which is a different axis from form-level captcha and fails the opposite way.
 *
 * Captcha verification fails closed because it guards one form. This runs in front of every
 * request, so failing closed on a provider outage would take the site down to stop traffic that
 * was probably legitimate — a self-inflicted outage in response to someone else's.
 */
beforeEach(function (): void {
    config()->set('laranail.captcha.bot_management.enabled', true);
    config()->set('laranail.captcha.bot_management.adapter', 'datadome');
    config()->set('laranail.captcha.bot_management.datadome.server_key', 'a-server-key');

    app()->forgetInstance(BotManagementAdapter::class);

    Route::middleware(ProtectAgainstBots::class)->get('/guarded', fn (): string => 'through');
});

function dataDomeAnswers(int $status): void
{
    Http::fake(fn () => Http::response('', $status, ['X-DataDomeResponse' => (string) $status]));
}

it('lets an allowed request through', function (): void {
    dataDomeAnswers(200);

    $this->get('/guarded')->assertOk()->assertSee('through');
});

it('refuses a blocked request', function (): void {
    dataDomeAnswers(403);

    $this->get('/guarded')->assertForbidden();
});

it('treats a challenge verdict as a refusal rather than proxying the provider page', function (): void {
    dataDomeAnswers(401);

    // Rendering someone else's interstitial into your own response is a decision an application
    // should make deliberately, so the event carries the verdict and the default is a flat 403.
    $this->get('/guarded')->assertForbidden();
});

it('fails open when the provider cannot be reached', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $this->get('/guarded')->assertOk();
});

it('fails open when the answer did not come from the protection engine', function (): void {
    // A proxy error page, a captive portal, an edge cache. Without the documented header this is
    // not a verdict, and reading a 403 from a misbehaving proxy as "bot" would block real users.
    Http::fake(fn () => Http::response('<html>proxy error</html>', 403));

    $this->get('/guarded')->assertOk();
});

it('fails open when the server key is missing', function (): void {
    config()->set('laranail.captcha.bot_management.datadome.server_key', '');
    app()->forgetInstance(BotManagementAdapter::class);

    Http::fake();

    $this->get('/guarded')->assertOk();

    Http::assertNothingSent();
});

it('announces a refusal so an application can respond to it', function (): void {
    Event::fake([BotRequestBlocked::class]);
    dataDomeAnswers(403);

    $this->get('/guarded');

    Event::assertDispatched(
        BotRequestBlocked::class,
        fn (BotRequestBlocked $event): bool => $event->decision === BotDecision::Block
            && $event->adapter === 'datadome',
    );
});

it('passes requests through untouched when bot management is off', function (): void {
    config()->set('laranail.captcha.bot_management.enabled', false);
    app()->forgetInstance(BotManagementAdapter::class);

    // The null adapter is bound when the feature is off, so the middleware is safe to register
    // globally — one that has to be added and removed as config changes eventually gets left out.
    expect(app(BotManagementAdapter::class))->toBeInstanceOf(NullBotManager::class);

    Http::fake();

    $this->get('/guarded')->assertOk();

    Http::assertNothingSent();
});

it('never lets a hostile header grow the payload past the API limits', function (): void {
    dataDomeAnswers(200);

    $this->get('/guarded', ['User-Agent' => str_repeat('A', 5_000)]);

    // An oversized payload is rejected wholesale, which would turn "this request looks
    // suspicious" into "bot management is unavailable" for exactly the requests that matter.
    Http::assertSent(fn ($request): bool => mb_strlen((string) $request['UserAgent']) === 768);
});

it('describes the request rather than forwarding it', function (): void {
    dataDomeAnswers(200);

    $this->get('/guarded');

    Http::assertSent(fn ($request): bool => $request['Key'] === 'a-server-key'
        && $request['Method'] === 'GET'
        && $request['Request'] === '/guarded');
});

it('reports an allow decision for an unconfigured null adapter', function (): void {
    expect((new NullBotManager)->decide(Request::create('/')))->toBe(BotDecision::Allow);
});

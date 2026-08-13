<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Simtabi\Laranail\Captcha\AdapterFactory;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;

/**
 * The contract every adapter must satisfy, applied to all of them at once.
 *
 * The rule is that an adapter fails closed: any transport error, non-2xx response or malformed
 * body yields a failed result rather than throwing or reporting success. Both halves matter, and
 * they fail in opposite directions — an adapter that throws turns a provider outage into a 500 on
 * the login form, and an adapter that returns success on a body it could not parse turns one into
 * an open door.
 *
 * Written as a dataset over the enum rather than per adapter, so a new provider is covered the
 * moment it is added and cannot ship without meeting the contract.
 */
beforeEach(function (): void {
    // Every HTTP-backed adapter, credentialed, so nothing here passes merely because the adapter
    // reported itself unconfigured and never made a request.
    $credentials = [];

    foreach (httpProviders() as $provider) {
        $credentials[$provider->value] = [
            'site_key' => 'site-key',
            'secret' => 'secret-key',
            'project_id' => 'demo-project',
            'client' => 'demo',
        ];
    }

    config()->set('laranail.captcha.environments.default', $credentials);
    app()->forgetInstance(CredentialStore::class);
});

/** @return list<Provider> */
function httpProviders(): array
{
    // Everything that talks to a vendor. The self-hosted providers and the test double answer
    // in-process, so the transport contract does not apply to them — they have their own suites.
    return array_values(array_filter(
        Provider::cases(),
        static fn (Provider $p): bool => ! $p->isSelfHosted() && $p !== Provider::NullProvider,
    ));
}

dataset('http adapters', fn (): array => array_map(
    static fn (Provider $p): array => [$p],
    httpProviders(),
));

it('fails closed when the provider cannot be reached', function (Provider $provider): void {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    $result = app(AdapterFactory::class)->make($provider)->verify('a-token', VerificationContext::none());

    expect($result->verified)->toBeFalse()
        ->and($result->failedBecause(ErrorCode::TransportError))->toBeTrue();
})->with('http adapters');

it('fails closed on a server error', function (Provider $provider): void {
    Http::fake(fn () => Http::response('upstream exploded', 500));

    $result = app(AdapterFactory::class)->make($provider)->verify('a-token', VerificationContext::none());

    expect($result->verified)->toBeFalse();
})->with('http adapters');

it('reports a rejected secret as an operator fault rather than a bad visitor', function (Provider $provider): void {
    Http::fake(fn () => Http::response('forbidden', 403));

    $result = app(AdapterFactory::class)->make($provider)->verify('a-token', VerificationContext::none());

    // The distinction decides whether a run of failures pages someone or is ordinary bot traffic.
    expect($result->verified)->toBeFalse()
        ->and($result->isOperatorFault())->toBeTrue();
})->with('http adapters');

it('fails closed on a body that is not the documented shape', function (Provider $provider): void {
    Http::fake(fn () => Http::response('<html>a captive portal</html>', 200));

    $result = app(AdapterFactory::class)->make($provider)->verify('a-token', VerificationContext::none());

    expect($result->verified)->toBeFalse();
})->with('http adapters');

it('fails closed on a body that parses but says nothing', function (Provider $provider): void {
    Http::fake(fn () => Http::response([], 200));

    // The dangerous shape: valid JSON with no success field. `$body['success'] ?? true` would
    // pass here, and that is a one-character mistake away in every adapter.
    $result = app(AdapterFactory::class)->make($provider)->verify('a-token', VerificationContext::none());

    expect($result->verified)->toBeFalse();
})->with('http adapters');

it('never reaches the provider when it is unconfigured', function (Provider $provider): void {
    config()->set('laranail.captcha.environments.default', []);
    app()->forgetInstance(CredentialStore::class);

    Http::fake();

    $result = app(AdapterFactory::class)->make($provider)->verify('a-token', VerificationContext::none());

    expect($result->failedBecause(ErrorCode::NotConfigured))->toBeTrue();

    Http::assertNothingSent();
})->with('http adapters');

it('never leaks the secret into the failure it returns', function (Provider $provider): void {
    Http::fake(fn () => throw new ConnectionException('POST failed: secret=secret-key'));

    $result = app(AdapterFactory::class)->make($provider)->verify('a-token', VerificationContext::none());

    // A Guzzle RequestException stringifies the request it failed on, and that request body
    // carries the secret. Reporting the exception verbatim is how a secret reaches the log.
    expect(json_encode($result->toArray()))->not->toContain('secret-key');
})->with('http adapters');

it('passes a genuine success through', function (Provider $provider, array $body): void {
    Http::fake(fn () => Http::response($body, 200));

    $result = app(AdapterFactory::class)->make($provider)->verify('a-token', VerificationContext::none());

    expect($result->verified)->toBeTrue();
})->with([
    'turnstile' => [Provider::Turnstile, ['success' => true, 'hostname' => 'example.com']],
    'hcaptcha' => [Provider::HCaptcha, ['success' => true]],
    'recaptcha v2' => [Provider::ReCaptchaV2, ['success' => true]],
    'recaptcha v3' => [Provider::ReCaptchaV3, ['success' => true, 'score' => 0.9, 'action' => 'login']],
    'friendly captcha' => [Provider::FriendlyCaptcha, ['success' => true]],
    'arkose' => [Provider::Arkose, ['session_details' => ['solved' => true]]],
    'enterprise' => [Provider::ReCaptchaEnterprise, [
        'tokenProperties' => ['valid' => true, 'action' => 'login', 'hostname' => 'example.com'],
        'riskAnalysis' => ['score' => 0.8],
    ]],
]);

it('refuses an arkose session it did not solve', function (): void {
    Http::fake(fn () => Http::response(['session_details' => ['solved' => false]], 200));

    $result = app(AdapterFactory::class)->make(Provider::Arkose)->verify('a-token', VerificationContext::none());

    expect($result->verified)->toBeFalse();
});

it('refuses an enterprise assessment whose token is not valid', function (): void {
    Http::fake(fn () => Http::response([
        'tokenProperties' => ['valid' => false, 'invalidReason' => 'DUPE'],
    ], 200));

    $result = app(AdapterFactory::class)->make(Provider::ReCaptchaEnterprise)
        ->verify('a-token', VerificationContext::none());

    expect($result->failedBecause(ErrorCode::ExpiredOrDuplicate))->toBeTrue();
});

it('binds the hcaptcha token to the site key', function (): void {
    Http::fake(fn () => Http::response(['success' => true], 200));

    app(AdapterFactory::class)->make(Provider::HCaptcha)->verify('a-token', VerificationContext::none());

    // Without the site key, a token minted against any site key on the same account verifies
    // here — a low-value public form on another property becomes a token mint for this one.
    Http::assertSent(fn ($request): bool => $request['sitekey'] === 'site-key');
});

it('posts hcaptcha verification to the documented host', function (): void {
    Http::fake(fn () => Http::response(['success' => true], 200));

    app(AdapterFactory::class)->make(Provider::HCaptcha)->verify('a-token', VerificationContext::none());

    // The package this replaces posted to hcaptcha.com, which is not the documented endpoint. It
    // answers by redirect, and a POST body does not necessarily survive one.
    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.hcaptcha.com/siteverify');
});

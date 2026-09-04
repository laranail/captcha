<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;

/**
 * The outbound HTTP client every adapter verifies through.
 *
 * Takes the `Factory` by injection rather than reaching for the `Http` facade, so an adapter can
 * be unit-tested with a client of the test's choosing and `tests/Arch` can forbid facades outright
 * below the Laravel layer.
 *
 * Two decisions here are deliberate and worth not undoing:
 *
 * **There is a connect timeout, and it is short.** The package this replaces set no timeout at
 * all. A provider that accepts the connection and then hangs pins the PHP worker for the default
 * socket timeout — on a login form, under load, that is the whole pool.
 *
 * **There is no retry.** The org convention is `retry(2, 200, throw: false)`, and it is wrong
 * here: these tokens are single-use, so a retry after a response that was sent but not received
 * gets `timeout-or-duplicate` and turns a recovered blip into a rejected visitor. Turnstile's
 * `idempotency_key` is the exception — the adapter that sends one opts in through
 * {@see self::retryable()}.
 */
final readonly class CaptchaHttp
{
    public function __construct(
        private Factory $http,
        private int $timeout = 5,
        private int $connectTimeout = 2,
        private bool $verifyTls = true,
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public function request(array $headers = []): PendingRequest
    {
        $request = $this->http
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->acceptJson()
            ->withHeaders($headers);

        return $this->verifyTls ? $request : $request->withoutVerifying();
    }

    /**
     * A client that will retry once, for providers whose verify call is idempotent.
     *
     * Only safe when the request carries something that lets the provider recognise the retry as
     * the same attempt. Never widen this to providers that do not.
     *
     * @param array<string, string> $headers
     */
    public function retryable(array $headers = []): PendingRequest
    {
        return $this->request($headers)->retry(2, 200, throw: false);
    }
}

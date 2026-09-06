<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\BotManagement;

use Throwable;
use SensitiveParameter;
use Illuminate\Http\Request;
use Illuminate\Http\Client\Factory;
use Simtabi\Laranail\Captcha\Enums\BotDecision;
use Simtabi\Laranail\Captcha\Contracts\BotManagementAdapter;

/**
 * DataDome's Protection API, as the reference adapter for the edge tier.
 *
 * A different shape from every captcha adapter in this package, which is why bot management is a
 * separate axis: there is no site key, no widget and no token. Every request is described to
 * DataDome as it arrives and comes back with a verdict about the connection.
 *
 * **It fails open, and that is deliberate.** The rest of this package fails closed, because captcha
 * guards one form and letting a submission through defeats the point. This runs in front of
 * *everything*, so failing closed on a provider outage takes the whole site down to stop traffic
 * that was probably legitimate — which is a self-inflicted outage in response to someone else's.
 * DataDome's own integration guidance says the same, and the timeout is short for the same reason:
 * a per-request dependency on a third party is only acceptable if it cannot hold a request open.
 */
final readonly class DataDomeBotManager implements BotManagementAdapter
{
    public function __construct(
        private Factory $http,
        #[SensitiveParameter]
        private string $serverKey,
        private string $endpoint = 'https://api.datadome.co/validate-request/',
        private int $timeoutSeconds = 1,
    ) {}

    public function decide(Request $request): BotDecision
    {
        if (! $this->isConfigured()) {
            return BotDecision::whenUnavailable();
        }

        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->timeoutSeconds)
                ->asForm()
                ->post($this->endpoint, $this->describe($request));
        } catch (Throwable) {
            return BotDecision::whenUnavailable();
        }

        // The documented fail-open signal: without this header the response did not come from the
        // protection engine — a proxy error page, a captive portal, an edge cache — and must not
        // be read as a verdict.
        //
        // Read through `header()` rather than `hasHeader()`. The latter is not a method on the
        // client Response at all; it reaches the PSR-7 response through magic forwarding, so it
        // works until someone changes what that class forwards, and nothing would catch it.
        if ($response->header('X-DataDomeResponse') === '') {
            return BotDecision::whenUnavailable();
        }

        return match ($response->status()) {
            403      => BotDecision::Block,
            401      => BotDecision::Challenge,
            301, 302 => BotDecision::Redirect,
            default  => BotDecision::Allow,
        };
    }

    public function isConfigured(): bool
    {
        return $this->serverKey !== '';
    }

    public function name(): string
    {
        return 'datadome';
    }

    /**
     * The request, described the way the Protection API expects.
     *
     * Values are truncated to the documented field limits. An over-long header is a routine thing
     * for a hostile client to send, and an oversized payload is rejected wholesale — which would
     * turn "this request looks suspicious" into "bot management is unavailable", exactly for the
     * requests where it matters.
     *
     * @return array<string, string>
     */
    private function describe(Request $request): array
    {
        return array_filter([
            'Key'               => $this->serverKey,
            'RequestModuleName' => 'laranail/captcha',
            'ModuleVersion'     => '0.1',
            'ServerName'        => $this->truncate($request->server('SERVER_NAME'), 512),
            'IP'                => $this->truncate($request->ip(), 128),
            'Port'              => $this->truncate($request->server('REMOTE_PORT'), 8),
            'TimeRequest'       => (string) (int) (microtime(true) * 1_000_000),
            'Protocol'          => $request->isSecure() ? 'https' : 'http',
            'Method'            => $request->method(),
            'Request'           => $this->truncate($request->getRequestUri(), 2048),
            'HeadersList'       => $this->truncate(implode(',', array_keys($request->headers->all())), 512),
            'Host'              => $this->truncate($request->getHost(), 512),
            'UserAgent'         => $this->truncate($request->userAgent(), 768),
            'Referer'           => $this->truncate($request->header('referer'), 1024),
            'Accept'            => $this->truncate($request->header('accept'), 512),
            'AcceptEncoding'    => $this->truncate($request->header('accept-encoding'), 128),
            'AcceptLanguage'    => $this->truncate($request->header('accept-language'), 256),
            'ClientID'          => $this->truncate($request->cookie('datadome'), 128),
        ], static fn (mixed $value): bool => $value !== '');
    }

    /**
     * Everything on a request is `mixed` to the analyser, and several of these genuinely can be
     * arrays — a repeated header arrives as a list. Anything that is not a scalar is dropped
     * rather than stringified, because `Array` in a payload field is worse than the field being
     * absent.
     */
    private function truncate(mixed $value, int $length): string
    {
        return is_scalar($value) ? mb_substr((string) $value, 0, $length) : '';
    }
}

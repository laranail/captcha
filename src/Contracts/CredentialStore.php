<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Contracts;

use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\Credentials;
use Simtabi\Laranail\Captcha\Credentials\ChainCredentialStore;

/**
 * One place credentials might come from.
 *
 * Three implement it — the database, the environment-scoped config blocks, and the providers'
 * published test keys — and {@see ChainCredentialStore}
 * composes them in that order.
 */
interface CredentialStore
{
    /**
     * Look up this provider's keys for this environment.
     *
     * Returns null when this store has nothing to say, so the chain can move on. Returning empty
     * credentials instead would be indistinguishable from "found, but blank", and the chain would
     * stop at the first store every time.
     *
     * A store MUST NOT throw. A missing table, an unreachable database or a cache outage all mean
     * "nothing to say" — an exception here fails a login form for a reason that has nothing to do
     * with the visitor.
     */
    public function get(Provider $provider, string $environment): ?Credentials;
}

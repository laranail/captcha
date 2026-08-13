<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Credentials;

use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Enums\CredentialSource;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\Credentials;
use Throwable;

/**
 * Tries each store in order and takes the first complete answer.
 *
 * Database, then config, then test keys. The order is the point: an operator changing a key in the
 * admin UI must win over the `.env` the application booted with, or the UI is decorative.
 *
 * A store that throws is treated as having nothing to say. The stores are contractually forbidden
 * from throwing, but this is a credential lookup on the login path — the cost of being wrong about
 * that is every login failing, so the guarantee is enforced here rather than assumed.
 */
final readonly class ChainCredentialStore implements CredentialStore
{
    /**
     * @param list<CredentialStore> $stores
     */
    public function __construct(private array $stores) {}

    public function get(Provider $provider, string $environment): ?Credentials
    {
        $partial = null;

        foreach ($this->stores as $store) {
            try {
                $credentials = $store->get($provider, $environment);
            } catch (Throwable) {
                continue;
            }

            if (! $credentials instanceof Credentials) {
                continue;
            }

            if ($credentials->isComplete()) {
                return $credentials;
            }

            // An explicit "nothing, and I mean it", which only a store configured to treat an
            // absent row as deliberate returns. Distinct from `null`, which means "I have nothing
            // to say, ask the next one" — without the distinction, a store trying to disable a
            // provider would be overruled by the config behind it, which is the opposite of what
            // the operator asked for.
            if ($credentials->source === CredentialSource::None) {
                return $credentials;
            }

            // Half an answer — a site key with no secret, usually a partly-filled `.env`. Kept in
            // case nothing better turns up, so the doctor command can show what was found and say
            // which half is missing, instead of reporting a bare "not configured".
            $partial ??= $credentials;
        }

        return $partial;
    }
}

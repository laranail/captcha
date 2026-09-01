<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Credentials;

use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Contracts\ProvidesCaptchaSettings;
use Simtabi\Laranail\Captcha\Enums\CredentialSource;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\Credentials;
use Simtabi\Laranail\DbTools\Guard\Contracts\DatabaseAvailabilityInterface;
use Throwable;

/**
 * Credentials from a settings table, resolved before config.
 *
 * The order is the point: an operator changing a key in an admin UI has to win over the `.env` the
 * application booted with, or the UI is decorative.
 *
 * Every read goes through db-tools' availability guard rather than a bare query wrapped in
 * try/catch. The distinction matters at three moments a package like this is otherwise guaranteed
 * to break: a fresh clone before `migrate` has run, a CI container with no database at all, and
 * `migrate` itself — where the table this reads does not exist yet and the boot that runs the
 * migration is the boot that would query it. The guard answers "no" without throwing and without
 * writing a line to the log on every request.
 */
final readonly class DatabaseCredentialStore implements CredentialStore
{
    /** Values other than site_key and secret are carried through as provider extras. */
    private const array KEYS = ['site_key', 'secret', 'project_id', 'client'];

    public function __construct(
        private ProvidesCaptchaSettings $settings,
        private DatabaseAvailabilityInterface $database,
        private string $table,
        private bool $absentRowDisables = false,
        private ?string $connection = null,
    ) {}

    public function get(Provider $provider, string $environment): ?Credentials
    {
        /** @var array<string, string>|null $values */
        $values = $this->database->whenTable(
            $this->table,
            fn (): ?array => $this->read($provider, $environment),
            null,
            $this->connection,
        );

        if ($values === null || $values === []) {
            // A reachable database with no row for this provider.
            //
            // `fall_through` is the default and the obvious behaviour. `disabled` exists because
            // the obvious behaviour has a sharp edge: an operator who deletes a row to turn a
            // provider off would find it still working, quietly served by whatever secret is
            // still sitting in `.env`. Which of those is correct depends on whether the table is
            // the source of truth or a convenience, and only the operator knows that.
            return $this->absentRowDisables && $this->database->hasTable($this->table, $this->connection)
                ? Credentials::missing()
                : null;
        }

        $siteKey = $values['site_key'] ?? '';
        $secret = $values['secret'] ?? '';

        unset($values['site_key'], $values['secret']);

        return new Credentials(
            siteKey: $siteKey,
            secret: $secret,
            source: CredentialSource::Database,
            extra: $values,
        );
    }

    /**
     * @return array<string, string>|null
     */
    private function read(Provider $provider, string $environment): ?array
    {
        $values = [];

        foreach (self::KEYS as $key) {
            try {
                $value = $this->settings->captchaSetting($provider->value, $key, $environment);
            } catch (Throwable) {
                // A host-supplied model. Its implementation is not ours, so it is treated as
                // capable of throwing even though the contract forbids it — a credential lookup
                // that raises on the login path fails every login, which is a worse outcome than
                // falling through to config.
                return null;
            }

            if (is_string($value) && $value !== '') {
                $values[$key] = $value;
            }
        }

        return $values === [] ? null : $values;
    }
}

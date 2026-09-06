<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Models;

use Throwable;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\Captcha\Contracts\ProvidesCaptchaSettings;

/**
 * The shipped settings model, for applications with nowhere else to put captcha credentials.
 *
 * Entirely optional. Point `laranail.captcha.credentials.database.model` at your own model
 * implementing {@see ProvidesCaptchaSettings} and neither this nor its migration is ever used —
 * most applications that want database-backed credentials already have a settings table, and
 * making them adopt a second one is how a package ends up ignored.
 *
 * @property string $provider
 * @property string $environment
 * @property string $key
 * @property string|null $value
 */
class CaptchaSetting extends Model implements ProvidesCaptchaSettings
{
    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('laranail.captcha.credentials.database.table', 'captcha_settings');

        return is_string($table) ? $table : 'captcha_settings';
    }

    public function getConnectionName(): ?string
    {
        $connection = config('laranail.captcha.credentials.database.connection');

        return is_string($connection) ? $connection : null;
    }

    public function captchaSetting(string $provider, string $key, string $environment): ?string
    {
        $row = static::query()
            ->where('provider', $provider)
            ->where('environment', $environment)
            ->where('key', $key)
            ->first();

        if (! $row instanceof self) {
            return null;
        }

        return $this->decrypted($row);
    }

    /**
     * Encrypted at rest, because half of what lands here is a secret key.
     *
     * The site key is public and gains nothing from this, but splitting the column by sensitivity
     * would mean two code paths and a schema that has to be reasoned about before every write.
     * Encrypting the value column uniformly costs a few bytes and removes the question.
     */
    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    /**
     * Read the value, surviving a key rotation rather than fataling on it.
     *
     * `encrypted` throws `DecryptException` when the payload was written under a different
     * `APP_KEY`. Left alone that turns a key rotation into every login failing with a 500 — and the
     * fix (re-encrypting the rows) is impossible to run through an application that will not boot.
     * Returning null degrades to the config store instead, which is recoverable.
     */
    private function decrypted(self $row): ?string
    {
        try {
            $value = $row->value;
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}

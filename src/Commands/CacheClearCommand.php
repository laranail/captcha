<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Commands;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Support\CaptchaConfig;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Drops cached credentials so a key changed in the database takes effect now.
 *
 * Only the credential cache, and only the keys this package wrote. `cache:clear` would do the job
 * and take everything else with it — on a busy application that is a thundering herd against the
 * database to fix a captcha key, which is a poor trade.
 *
 * Deliberately does not touch the replay guard. Those entries are what stop a solved token being
 * used twice; clearing them re-opens every outstanding token, and no operational problem is worth
 * that.
 */
final class CacheClearCommand extends Command
{
    use SupportsNamespacedNames;

    protected $name = 'laranail::captcha.cache-clear';

    /** @var list<string> */
    protected array $commandAliases = ['captcha:cache-clear'];

    protected $signature = 'laranail::captcha.cache-clear {--environment= : Clear another environment’s entries}';

    protected $description = 'Forget cached captcha credentials so database changes apply immediately.';

    public function handle(CacheFactory $cache, CaptchaConfig $config): int
    {
        if (! $config->bool('credentials.database.cache.enabled')) {
            $this->services->display()->info('Credential caching is disabled; there is nothing to clear.');

            return self::SUCCESS;
        }

        $store = $cache->store($config->stringOrNull('credentials.database.cache.store'));
        $environment = $this->stringOption('environment') ?: (string) app()->environment();

        $cleared = 0;

        foreach (Provider::cases() as $provider) {
            if ($store->forget('laranail:captcha:credentials:' . $provider->value . ':' . $environment)) {
                $cleared++;
            }
        }

        $this->services->display()->success(
            sprintf('Cleared %d cached credential %s for [%s].', $cleared, $cleared === 1 ? 'entry' : 'entries', $environment),
        );

        return self::SUCCESS;
    }

    private function stringOption(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }
}

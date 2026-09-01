<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Simtabi\Laranail\Captcha\Providers\CaptchaServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [CaptchaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');

        // Generated rather than committed. A literal `base64:` key in phpunit.xml is a valid
        // AES-256 key by construction, so secret scanners flag it — correctly, by their rules.
        // Generating it here removes the detector surface and nothing is lost: no test asserts
        // a fixed key. The encrypted settings cast needs a real key to round-trip.
        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Tests are explicit about the environment they exercise. Leaving this at `testing`
        // would let the test-key store answer for credentials that a test meant to source
        // from config or the database, and the assertion would prove nothing.
        $config->set('laranail.captcha.credentials.test_keys.enabled', false);
    }

    /**
     * Run the package's own migrations.
     *
     * Loaded from the publishable stub rather than a duplicate copy under tests/, so the suite
     * exercises the exact schema consumers get. A second, test-only schema is how a migration
     * bug ships green.
     */
    protected function defineDatabaseMigrations(): void
    {
        $migration = require dirname(__DIR__).'/database/migrations/create_captcha_settings_table.php.stub';

        $migration->up();
    }
}

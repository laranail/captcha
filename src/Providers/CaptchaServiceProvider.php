<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Providers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\Captcha\Actions\GuardProductionSafety;
use Simtabi\Laranail\Captcha\Actions\ResolveCredentials;
use Simtabi\Laranail\Captcha\AdapterFactory;
use Simtabi\Laranail\Captcha\BotManagement\DataDomeBotManager;
use Simtabi\Laranail\Captcha\BotManagement\NullBotManager;
use Simtabi\Laranail\Captcha\Commands\CacheClearCommand;
use Simtabi\Laranail\Captcha\Commands\DoctorCommand;
use Simtabi\Laranail\Captcha\Commands\InstallCommand;
use Simtabi\Laranail\Captcha\Commands\KeysCommand;
use Simtabi\Laranail\Captcha\Contracts\BotManagementAdapter;
use Simtabi\Laranail\Captcha\Contracts\ChallengeStore;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Contracts\ProvidesCaptchaSettings;
use Simtabi\Laranail\Captcha\Contracts\ReplayGuard;
use Simtabi\Laranail\Captcha\Credentials\CachedCredentialStore;
use Simtabi\Laranail\Captcha\Credentials\ChainCredentialStore;
use Simtabi\Laranail\Captcha\Credentials\ConfigCredentialStore;
use Simtabi\Laranail\Captcha\Credentials\DatabaseCredentialStore;
use Simtabi\Laranail\Captcha\Credentials\TestKeyCredentialStore;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Events\CaptchaFailed;
use Simtabi\Laranail\Captcha\Events\CaptchaVerified;
use Simtabi\Laranail\Captcha\Http\Controllers\ChallengeController;
use Simtabi\Laranail\Captcha\Listeners\LogCaptchaOutcome;
use Simtabi\Laranail\Captcha\Listeners\ResetCaptchaState;
use Simtabi\Laranail\Captcha\Models\CaptchaSetting;
use Simtabi\Laranail\Captcha\Rules\Captcha as CaptchaRule;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\Support\CacheReplayGuard;
use Simtabi\Laranail\Captcha\Support\CaptchaConfig;
use Simtabi\Laranail\Captcha\Support\CaptchaHttp;
use Simtabi\Laranail\Captcha\Support\SystemClock;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationPolicy;
use Simtabi\Laranail\Captcha\View\Components\Captcha as CaptchaComponent;
use Simtabi\Laranail\Captcha\View\Components\Container;
use Simtabi\Laranail\Captcha\View\Components\Js;
use Simtabi\Laranail\DbTools\Guard\DatabaseGuard;
use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Package\Tools\Providers\PackageServiceProvider;

final class CaptchaServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laranail/captcha')
            ->setPublishTagId('captcha')
            ->hasConfigFile('captcha')
            ->hasViews('captcha')
            ->hasTranslations('captcha')
            // Explicit aliases, not a component namespace. A namespace resolves as
            // `<x-captcha::js />`; the tags this package has always documented, and the ones every
            // existing application's markup already contains, are `<x-captcha-js />` and
            // `<x-captcha-container />`. Keeping them is what makes the migration a namespace
            // change rather than a sweep through every blade file.
            ->hasBladeComponentAliases([
                'captcha' => CaptchaComponent::class,
                'captcha-js' => Js::class,
                'captcha-container' => Container::class,
            ])
            ->hasCommands([
                DoctorCommand::class,
                KeysCommand::class,
                InstallCommand::class,
                CacheClearCommand::class,
            ])
            ->discoversMigrations();
    }

    #[Override]
    public function packageRegistered(): void
    {
        $config = $this->app->make(Repository::class);

        // Resolved once, here, rather than on every lookup. It is also the value the production
        // guard is measured against, so it must be the same string throughout a request.
        $environment = (string) $this->app->environment();

        $this->app->singleton(ClockInterface::class, static fn (): ClockInterface => new SystemClock);

        $this->app->singleton(CaptchaHttp::class, static function (Application $app): CaptchaHttp {
            $settings = new CaptchaConfig($app->make(Repository::class));

            return new CaptchaHttp(
                http: $app->make(HttpFactory::class),
                timeout: $settings->int('verification.timeout', 5),
                connectTimeout: $settings->int('verification.connect_timeout', 2),
                verifyTls: $settings->bool('verification.verify_tls', true),
            );
        });

        $this->app->singleton(CacheReplayGuard::class, static function (Application $app): CacheReplayGuard {
            $config = new CaptchaConfig($app->make(Repository::class));

            return new CacheReplayGuard(
                cache: $app->make(CacheFactory::class)->store(
                    $config->stringOrNull('verification.replay_guard.store'),
                ),
                failOpen: $config->bool('verification.replay_guard.fail_open', false),
            );
        });

        $this->app->alias(CacheReplayGuard::class, ReplayGuard::class);
        $this->app->alias(CacheReplayGuard::class, ChallengeStore::class);

        $this->app->singleton(CredentialStore::class, static function (Application $app): CredentialStore {
            $config = new CaptchaConfig($app->make(Repository::class));
            $source = $config->string('credentials.source', 'chain');

            $stores = [];

            // First, so an operator changing a key in an admin UI beats the .env the application
            // booted with. Anything else makes the UI decorative.
            if ($source !== 'config' && $config->bool('credentials.database.enabled')) {
                $database = self::makeDatabaseStore($app, $config);

                if ($database instanceof CredentialStore) {
                    $stores[] = $database;
                }
            }

            if ($source !== 'database') {
                $stores[] = new ConfigCredentialStore($config->repository());
            }

            if ($source === 'chain') {
                $stores[] = new TestKeyCredentialStore(
                    enabled: $config->bool('credentials.test_keys.enabled', true),
                    allowedEnvironments: $config->strings('credentials.test_keys.environments')
                        ?: ['local', 'testing'],
                );
            }

            return new ChainCredentialStore($stores);
        });

        $this->app->singleton(
            ResolveCredentials::class,
            static fn (Application $app): ResolveCredentials => new ResolveCredentials(
                store: $app->make(CredentialStore::class),
                environment: (string) $app->environment(),
            ),
        );

        $this->app->singleton(GuardProductionSafety::class, static function (Application $app): GuardProductionSafety {
            $config = new CaptchaConfig($app->make(Repository::class));

            return new GuardProductionSafety(
                productionEnvironments: $config->strings('production_environments')
                    ?: ['production', 'prod'],
                allowNullInProduction: $config->bool('providers.null.allow_in_production'),
            );
        });

        $this->app->singleton(AdapterFactory::class, static fn (Application $app): AdapterFactory => new AdapterFactory(
            resolveCredentials: $app->make(ResolveCredentials::class),
            http: $app->make(CaptchaHttp::class),
            config: $app->make(Repository::class),
            settings: new CaptchaConfig($app->make(Repository::class)),
            challenges: $app->make(ChallengeStore::class),
            clock: $app->make(ClockInterface::class),
            cache: $app->make(CacheFactory::class),
        ));

        $this->app->singleton(CaptchaService::class, static function (Application $app): CaptchaService {
            $config = new CaptchaConfig($app->make(Repository::class));

            return new CaptchaService(
                factory: $app->make(AdapterFactory::class),
                provider: self::resolveProvider($config),
                resolveCredentials: $app->make(ResolveCredentials::class),
                guard: $app->make(GuardProductionSafety::class),
                replayGuard: $app->make(ReplayGuard::class),
                clock: $app->make(ClockInterface::class),
                policy: VerificationPolicy::fromConfig($config),
                events: $app->make(Dispatcher::class),
                logger: $app->make(LoggerInterface::class),
            );
        });

        $this->app->alias(CaptchaService::class, 'captcha');

        $this->app->singleton(
            CaptchaConfig::class,
            static fn (Application $app): CaptchaConfig => new CaptchaConfig($app->make(Repository::class)),
        );

        $this->app->singleton(BotManagementAdapter::class, static function (Application $app): BotManagementAdapter {
            $config = new CaptchaConfig($app->make(Repository::class));

            // The null adapter whenever the feature is off, so the middleware can be registered
            // unconditionally and simply pass requests through. A middleware that has to be added
            // and removed as configuration changes is one that eventually gets left out.
            if (! $config->bool('bot_management.enabled')) {
                return new NullBotManager;
            }

            return match ($config->string('bot_management.adapter')) {
                'datadome' => new DataDomeBotManager(
                    http: $app->make(HttpFactory::class),
                    serverKey: $config->string('bot_management.datadome.server_key'),
                    endpoint: $config->string(
                        'bot_management.datadome.endpoint',
                        'https://api.datadome.co/validate-request/',
                    ),
                    timeoutSeconds: $config->int('bot_management.datadome.timeout', 1),
                ),
                default => new NullBotManager,
            };
        });

        unset($config, $environment);
    }

    #[Override]
    public function packageBooted(): void
    {
        $this->registerValidationRule();
        $this->registerChallengeRoute();
        $this->registerOctaneReset();
        $this->registerLogging();
    }

    /**
     * Clear request-scoped state at both Octane boundaries.
     *
     * Listened to by event *name* rather than by importing the class: without Octane installed
     * those names are simply never dispatched, so there is no `class_exists` probe and no
     * dependency. Both boundaries, because a request that dies hard never reaches termination.
     */
    private function registerOctaneReset(): void
    {
        $events = $this->app->make(Dispatcher::class);

        foreach ([
            'Laravel\Octane\Events\RequestReceived',
            'Laravel\Octane\Events\RequestTerminated',
        ] as $event) {
            $events->listen($event, [ResetCaptchaState::class, 'handle']);
        }
    }

    /**
     * Outcome logging, off unless asked for.
     *
     * Captcha failures are ordinary bot traffic at volume; logging every one by default turns a
     * flood into a disk-space incident.
     */
    private function registerLogging(): void
    {
        $config = new CaptchaConfig($this->app->make(Repository::class));

        if (! $config->bool('logging.enabled')) {
            return;
        }

        $this->app->singleton(
            LogCaptchaOutcome::class,
            static fn (Application $app): LogCaptchaOutcome => new LogCaptchaOutcome(
                logger: $app->make(LoggerInterface::class),
                failureLevel: new CaptchaConfig($app->make(Repository::class))
                    ->string('logging.failure_level', 'debug'),
            ),
        );

        $events = $this->app->make(Dispatcher::class);
        $events->listen(CaptchaVerified::class, [LogCaptchaOutcome::class, 'verified']);
        $events->listen(CaptchaFailed::class, [LogCaptchaOutcome::class, 'failed']);
    }

    /**
     * Expose the challenge endpoint used by the self-hosted providers.
     *
     * Registered unconditionally, with the controller answering 404 when the active provider does
     * not mint challenges. Deciding at registration time would be marginally tighter, but it binds
     * the route table to whatever the config said at boot — so a provider switched at runtime, or
     * in a test, leaves the endpoint wrong in both directions. The externally visible behaviour is
     * identical either way, and this version cannot go stale.
     *
     * Rate-limited because minting is the expensive half: the endpoint is unauthenticated by
     * nature, an attacker can ask as fast as they can connect, and each request costs a random
     * read, a hash and a cache write.
     */
    private function registerChallengeRoute(): void
    {
        $config = new CaptchaConfig($this->app->make(Repository::class));

        $path = $config->stringOrNull('challenge.route');
        $throttle = $config->stringOrNull('challenge.rate_limit');

        Route::middleware(is_string($throttle) && $throttle !== '' ? ['throttle:' . $throttle] : [])
            ->get(
                is_string($path) && $path !== '' ? $path : '/captcha/challenge',
                ChallengeController::class,
            )
            ->name('laranail.captcha.challenge');
    }

    /**
     * Register the `captcha` string rule as **implicit**.
     *
     * Deliberately not `$package->hasValidationRule()`. That helper registers through
     * `Validator::extend`, and a non-implicit rule is skipped entirely when the field is missing
     * from the request — which is the bypass this package exists to close: omitting the field is
     * exactly what an attacker does, and the old package let those submissions through.
     *
     * `extendImplicit` also gets the pairing with `required` right. Laravel stops validating an
     * attribute once an implicit rule on it has failed, so `['required', 'captcha']` on an absent
     * field reports one message rather than two.
     */
    private function registerValidationRule(): void
    {
        Validator::extendImplicit(
            'captcha',

            // Run through Laravel's own validator so the ValidationRule contract, and the
            // rule's message resolution, are honoured natively rather than reimplemented.
            static fn (string $attribute, mixed $value): bool => validator([$attribute => $value], [$attribute => [new CaptchaRule]])->passes(),
        );
    }

    /**
     * Build the database store, or return null when it cannot be built.
     *
     * A misconfigured `model` — a class that does not exist, or one that does not implement the
     * contract — degrades to the config store rather than throwing. Throwing here happens during
     * registration, which means the application does not boot at all; for a settings-source
     * misconfiguration that is a wildly disproportionate failure, and the doctor command reports
     * the gap where someone will actually see it.
     */
    private static function makeDatabaseStore(Application $app, CaptchaConfig $config): ?CredentialStore
    {
        $class = $config->stringOrNull('credentials.database.model') ?? CaptchaSetting::class;

        if (! class_exists($class) || ! is_subclass_of($class, ProvidesCaptchaSettings::class)) {
            return null;
        }

        /** @var ProvidesCaptchaSettings $settings */
        $settings = $app->make($class);

        $store = new DatabaseCredentialStore(
            settings: $settings,
            // Resolved statically because db-tools documents this entry point as safe before its
            // own provider has registered — which is exactly the window a credential lookup can
            // land in during `migrate` or a console boot.
            database: DatabaseGuard::resolve(),
            table: $config->string('credentials.database.table', 'captcha_settings'),
            absentRowDisables: $config->string('credentials.database.row_absent_means') === 'disabled',
            connection: $config->stringOrNull('credentials.database.connection'),
        );

        if (! $config->bool('credentials.database.cache.enabled')) {
            return $store;
        }

        return new CachedCredentialStore(
            inner: $store,
            cache: $app->make(CacheFactory::class)->store(
                $config->stringOrNull('credentials.database.cache.store'),
            ),
            ttlSeconds: $config->int('credentials.database.cache.ttl', 300),
        );
    }

    private static function resolveProvider(CaptchaConfig $config): Provider
    {
        $configured = $config->stringOrNull('provider');

        // An unknown name falls back to the null provider rather than throwing at boot. Throwing
        // here takes the whole application down for a typo in a config file; the null provider is
        // refused in production anyway, so the failure still surfaces — as blocked submissions and
        // a logged error, not a white screen.
        return ($configured !== null ? Provider::tryFrom($configured) : null) ?? Provider::NullProvider;
    }
}

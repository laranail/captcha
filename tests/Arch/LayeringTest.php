<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Validator;
use Simtabi\Laranail\Captcha\Facades\Captcha;

/**
 * The dependency rule, enforced rather than documented.
 *
 * The package this replaces read `config('captcha.sitekey')` inline inside two view components and
 * called `request()` inside every driver. That is why its credentials could not be sourced from
 * anywhere but config, why its drivers could not be unit-tested, and why verification broke inside
 * a queued job. None of it was a hard problem to fix — it was just never visible.
 *
 * So the boundary is a test, and CI runs it as its own job. The domain layers take what they need
 * through their constructors; the framework layers (Providers, Rules, View, Http) are where the
 * container is allowed to be touched, because that is what they exist for.
 */
$globals = ['config', 'env', 'request', 'app', 'resolve', 'session', 'cache', 'logger', 'report'];

$facades = [
    Cache::class,
    Config::class,
    Http::class,
    Log::class,
    Request::class,
    Validator::class,
    DB::class,
    Captcha::class,
];

arch('actions take their dependencies by injection')
    ->expect('Simtabi\Laranail\Captcha\Actions')
    ->not->toUse([...$globals, ...$facades]);

arch('services take their dependencies by injection')
    ->expect('Simtabi\Laranail\Captcha\Services')
    ->not->toUse([...$globals, ...$facades]);

arch('adapters take their dependencies by injection')
    ->expect('Simtabi\Laranail\Captcha\Adapters')
    ->not->toUse([...$globals, ...$facades]);

arch('credential stores take their dependencies by injection')
    ->expect('Simtabi\Laranail\Captcha\Credentials')
    ->not->toUse([...$globals, ...$facades]);

arch('support classes take their dependencies by injection')
    ->expect('Simtabi\Laranail\Captcha\Support')
    ->not->toUse([...$globals, ...$facades]);

arch('value objects stay free of framework services')
    ->expect('Simtabi\Laranail\Captcha\ValueObjects')
    ->not->toUse([...$globals, ...$facades]);

/**
 * `env()` outside a config file returns null once `config:cache` has run.
 *
 * The environment-keyed credential blocks are exactly the kind of thing that would be written this
 * way by accident, and the symptom — every key resolving empty — appears only in production.
 */
arch('env is never read outside the config file')
    ->expect('Simtabi\Laranail\Captcha')
    ->not->toUse('env');

arch('adapters do not reach into the credential resolution layer')
    ->expect('Simtabi\Laranail\Captcha\Adapters')
    ->not->toUse('Simtabi\Laranail\Captcha\Credentials');

arch('contracts stay dependency-free')
    ->expect('Simtabi\Laranail\Captcha\Contracts')
    ->toBeInterfaces();

arch('enums are backed so they can round-trip through config and the database')
    ->expect('Simtabi\Laranail\Captcha\Enums')
    ->toBeStringBackedEnums();

arch('nothing is left debugging')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('strict types everywhere')
    ->expect('Simtabi\Laranail\Captcha')
    ->toUseStrictTypes();

/**
 * PHPUnit is a dev dependency. A production class importing `Assert` makes the package unusable
 * without it — an error a consumer only meets at runtime, in the one code path they were relying
 * on. The test helpers are the exception: `src/Testing` exists to be used from a test suite.
 */
arch('phpunit stays out of production code')
    ->expect('Simtabi\\Laranail\\Captcha')
    ->not->toUse(Assert::class)
    ->ignoring('Simtabi\\Laranail\\Captcha\\Testing');

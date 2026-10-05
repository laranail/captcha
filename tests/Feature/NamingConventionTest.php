<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Validator;
use Simtabi\Laranail\Captcha\View\Components\Js;
use Illuminate\View\Compilers\ComponentTagCompiler;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\View\Components\Container;
use Simtabi\Laranail\Package\Tools\Testing\NamingScope;
use Simtabi\Laranail\Captcha\Support\DeprecationNotices;
use Simtabi\Laranail\Captcha\Providers\CaptchaServiceProvider;
use Simtabi\Laranail\Package\Tools\Testing\AssertsRegisteredNames;
use Simtabi\Laranail\Captcha\View\Components\Captcha as CaptchaComponent;

uses(AssertsRegisteredNames::class);

/**
 * Blade component aliases, container aliases, validation rules and view namespaces each live in a
 * flat, host-owned map, so every name this package registers carries the vendor. The bare names it
 * shipped before 0.1 stay working as deprecated aliases. Read from the live registries.
 */
function captchaScope(string $under = 'src'): NamingScope
{
    // basePath is narrowed: package-tools v0.1.3 defaults ownership to the package root, which also
    // claims vendor/ and tests/ registrations (fixed in v0.1.4).
    return NamingScope::for('laranail/captcha', 'Simtabi\\Laranail\\Captcha\\', basePath: dirname(__DIR__, 2) . '/' . $under);
}

/** @return list<string> */
function captureCaptchaDeprecations(callable $callback): array
{
    $notices = [];

    set_error_handler(static function (int $level, string $message) use (&$notices): bool {
        $notices[] = $message;

        return true;
    }, E_USER_DEPRECATED);

    try {
        $callback();
    } finally {
        restore_error_handler();
    }

    return $notices;
}

beforeEach(function (): void {
    DeprecationNotices::forget();
});

it('registers its components under the package prefix, with the bare tags listed as deprecated', function (): void {
    expect($this->assertBladeComponentsScoped(captchaScope(), deprecated: ['captcha', 'captcha-js', 'captcha-container']))
        ->toContain('laranail-captcha');
});

it('resolves the scoped tags to the same classes as the deprecated ones', function (string $scoped, string $bare, string $class): void {
    $compiler = new ComponentTagCompiler(
        Blade::getClassComponentAliases(),
        Blade::getClassComponentNamespaces(),
        Blade::getFacadeRoot(),
    );

    expect($compiler->componentClass("laranail-captcha::{$scoped}"))->toBe($class)
        ->and($compiler->componentClass($bare))->toBe($class);
})->with([
    ['captcha', 'captcha', CaptchaComponent::class],
    ['js', 'captcha-js', Js::class],
    ['container', 'captcha-container', Container::class],
]);

it('renders the scoped all-in-one tag', function (): void {
    expect(Blade::render('<form><x-laranail-captcha::captcha /></form>'))->toContain('data-captcha-config=');
});

it('announces a bare tag once, when a template using it compiles', function (): void {
    $notices = captureCaptchaDeprecations(static function (): void {
        Blade::compileString('<form><x-captcha /><x-captcha /></form>');
        Blade::compileString('<form><x-captcha /></form>');
        Blade::compileString('<form><x-laranail-captcha::captcha /><x-laranail-captcha::js /></form>');
    });

    expect($notices)->toHaveCount(1)
        ->and($notices[0])->toContain('<x-laranail-captcha::captcha />');
});

it('scopes its container alias, with the bare one listed as deprecated', function (): void {
    expect($this->assertContainerAliasesScoped(captchaScope(), deprecated: ['captcha']))
        ->toContain('laranail.captcha')
        ->and(app('captcha'))->toBe(app('laranail.captcha'))
        ->and(app('laranail.captcha'))->toBe(app(CaptchaService::class));
});

it('registers the scoped and the deprecated validation rules as implicit', function (): void {
    $implicit = new ReflectionProperty(Validator::getFacadeRoot(), 'implicitExtensions')
        ->getValue(Validator::getFacadeRoot());

    expect($implicit)->toHaveKeys([CaptchaServiceProvider::VALIDATION_RULE, 'captcha'])
        ->and(CaptchaServiceProvider::VALIDATION_RULE)->toBe('laranail_captcha');
});

it('fails an absent field under the scoped rule with the rule\'s own message', function (): void {
    $validator = Validator::make([], ['captcha' => ['laranail_captcha']]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('captcha'))->toBe(__('laranail-captcha::validation.missing-response'));
});

it('still runs the bare rule, and announces it once', function (): void {
    $notices = captureCaptchaDeprecations(static function (): void {
        expect(Validator::make([], ['captcha' => ['captcha']])->fails())->toBeTrue()
            ->and(Validator::make([], ['captcha' => ['captcha']])->fails())->toBeTrue();
    });

    expect($notices)->toHaveCount(1)
        ->and($notices[0])->toContain('laranail_captcha');
});

it('registers both view namespace forms over the same paths', function (): void {
    expect($this->assertViewNamespacesScoped(captchaScope('resources'), atLeast: 2))
        ->toContain('laranail/captcha', 'laranail-captcha');

    $hints = View::getFinder()->getHints();

    expect($hints['laranail/captcha'])->toBe($hints['laranail-captcha']);
});

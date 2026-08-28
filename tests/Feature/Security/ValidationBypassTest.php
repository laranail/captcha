<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Simtabi\Laranail\Captcha\Facades\Captcha;
use Simtabi\Laranail\Captcha\Rules\Captcha as CaptchaRule;

/**
 * The bypass this package was rewritten to close.
 *
 * In `rahul900day/laravel-captcha` the rule was not implicit, so a request that omitted the
 * captcha field skipped it entirely and passed validation. It was reported as "able to login
 * without even using captcha" and closed as user error. Both forms of the rule are covered here,
 * because an application can reach it either way.
 */
beforeEach(function (): void {
    // Verifies everything, so nothing here passes because verification happened to fail — each
    // assertion is about the rule running at all.
    Captcha::fake();
});

it('fails when the captcha response is missing entirely', function (mixed $rule): void {
    $validator = Validator::make([], ['captcha' => [$rule]]);

    expect($validator->fails())->toBeTrue();
})->with([
    'string alias' => fn (): string => 'captcha',
    'rule object'  => fn (): CaptchaRule => new CaptchaRule,
]);

/**
 * The 500 that used to accompany the bypass.
 *
 * A null or non-string value went straight into a `string` parameter under strict types and raised
 * a TypeError, which surfaced as a 500 on the login form rather than a validation failure.
 */
it('fails without raising a TypeError on a value that cannot be a token', function (mixed $value): void {
    $validator = Validator::make(['captcha' => $value], ['captcha' => [new CaptchaRule]]);

    expect($validator->fails())->toBeTrue();
})->with([
    'null'         => null,
    'empty string' => '',
    'whitespace'   => '   ',
    'array'        => [[['a', 'b']]],
    'integer'      => 1234,
    'boolean'      => true,
]);

it('reports a missing response once when paired with required', function (): void {
    $validator = Validator::make([], ['captcha' => ['required', 'captcha']]);

    // Laravel stops validating an attribute once an implicit rule on it has failed, so the
    // pairing most applications already write does not produce two messages for one problem.
    expect($validator->fails())->toBeTrue()
        ->and($validator->messages()->get('captcha'))->toHaveCount(1);
});

it('passes a solved captcha', function (): void {
    $validator = Validator::make(['captcha' => 'a-solved-token'], ['captcha' => [new CaptchaRule]]);

    expect($validator->fails())->toBeFalse();
});

it('fails an unsolved captcha', function (): void {
    Captcha::fake(verifies: false);

    $validator = Validator::make(['captcha' => 'an-unsolved-token'], ['captcha' => [new CaptchaRule]]);

    expect($validator->fails())->toBeTrue();
});

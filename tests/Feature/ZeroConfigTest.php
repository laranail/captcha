<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Validator;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Services\CaptchaService;

/**
 * The promise: install the package, drop one tag in a form, add one validation rule, and you are
 * protected — on any project, with no account, no keys and no JavaScript.
 *
 * These tests are that promise written down, because it is the kind of claim that quietly stops
 * being true the first time a default changes.
 */
it('defaults to a provider that needs no account and no keys', function (): void {
    expect(config('laranail.captcha.provider'))->toBe(Provider::Math->value)
        ->and(app(CaptchaService::class)->isConfigured())->toBeTrue();
});

it('renders a complete, working captcha from a single tag', function (): void {
    $rendered = Blade::render('<form method="post"><x-captcha /></form>');

    expect($rendered)
        // The question, for a person to read.
        ->toContain('laranail-math-captcha')
        // The answer box, labelled and required.
        ->toContain('name="captcha_answer"')
        // The signed challenge that says which question was asked.
        ->toContain('name="captcha_challenge"')
        // No vendor script to load — the runtime that recovers an expired question is emitted,
        // but the markup submits fine without it.
        ->not->toContain('<script src');
});

it('protects a form end to end without a single line of javascript', function (): void {
    $rendered = Blade::render('<x-captcha />');

    preg_match('/name="captcha_challenge" value="([^"]+)"/', $rendered, $challenge);
    preg_match('/laranail-captcha-question"[^>]*>([^<]+)</', $rendered, $question);

    $answer = solveRenderedQuestion(trim($question[1]));

    // Exactly what a browser posts: two ordinary form fields, recombined server-side.
    $request = request()->merge([
        'captcha_challenge' => html_entity_decode($challenge[1]),
        'captcha_answer' => (string) $answer,
    ]);

    app()->instance('request', $request);

    expect(Validator::make($request->all(), ['captcha' => ['captcha']])->fails())->toBeFalse();
});

it('still rejects a form submitted with the wrong answer', function (): void {
    $rendered = Blade::render('<x-captcha />');

    preg_match('/name="captcha_challenge" value="([^"]+)"/', $rendered, $challenge);

    $request = request()->merge([
        'captcha_challenge' => html_entity_decode($challenge[1]),
        'captcha_answer' => '999999',
    ]);

    app()->instance('request', $request);

    expect(Validator::make($request->all(), ['captcha' => ['captcha']])->fails())->toBeTrue();
});

it('rejects a form that skipped the captcha entirely', function (): void {
    $request = request()->merge([]);
    app()->instance('request', $request);

    // The bypass this whole package exists to close, reached through the zero-config path.
    expect(Validator::make([], ['captcha' => ['captcha']])->fails())->toBeTrue();
});

function solveRenderedQuestion(string $question): int
{
    $words = [
        'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5',
        'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'ten' => '10',
        'eleven' => '11', 'twelve' => '12', 'thirteen' => '13', 'fourteen' => '14',
        'fifteen' => '15', 'sixteen' => '16', 'seventeen' => '17', 'eighteen' => '18',
        'nineteen' => '19', 'twenty' => '20',
    ];

    $expression = str_replace(
        [...array_keys($words), 'times', 'plus', 'minus', '×', 'x', '−'],
        [...array_values($words), '*', '+', '-', '*', '*', '-'],
        html_entity_decode($question),
    );

    return (int) eval("return {$expression};");
}

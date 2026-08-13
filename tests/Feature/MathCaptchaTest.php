<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Captcha\Adapters\Math\MathProblem;
use Simtabi\Laranail\Captcha\Adapters\Math\ProblemGenerator;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Services\CaptchaService;

/**
 * The self-hosted arithmetic provider.
 *
 * Math captchas have a poor reputation, earned: most put the answer in a hidden field, or let a
 * client guess against the same question until it lands. The answer space is two hundred integers,
 * so unlimited retries make the whole thing decorative. These tests exist to prove this one does
 * neither.
 */
beforeEach(function (): void {
    config()->set('laranail.captcha.provider', 'math');
    config()->set('laranail.captcha.providers.math.hmac_key', 'a-server-held-key');

    app()->forgetInstance(CaptchaService::class);
    app()->forgetInstance(CredentialStore::class);
});

function answer(MathProblem $problem, int|string $value): string
{
    return base64_encode((string) json_encode([
        'id' => $problem->id,
        'answer' => $value,
        'expires' => $problem->expiresAt->getTimestamp(),
        'signature' => $problem->signature,
    ]));
}

/** Work out what the question asks, the way a person would. */
function solveQuestion(string $question): int
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
        $question,
    );

    // Test-only, and the input is a string this package generated moments ago.
    return (int) eval("return {$expression};");
}

it('never puts the answer anywhere the client can see it', function (): void {
    $problem = app(CaptchaService::class)->issueChallenge();

    $serialised = json_encode($problem->toArray());
    $expected = (string) solveQuestion($problem->question);

    // The failure mode of nearly every math captcha on Packagist: the answer, or something the
    // answer can be derived from, travelling to the browser.
    expect($serialised)->not->toContain('"' . $expected . '"')
        ->and(array_keys($problem->toArray()))->toBe(['id', 'question', 'signature', 'expires_at']);
});

it('accepts the right answer', function (): void {
    $captcha = app(CaptchaService::class);
    $problem = $captcha->issueChallenge();

    expect($captcha->verify(answer($problem, solveQuestion($problem->question)))->passes())->toBeTrue();
});

it('gives exactly one guess per question', function (): void {
    $captcha = app(CaptchaService::class);
    $problem = $captcha->issueChallenge();

    $correct = solveQuestion($problem->question);

    // A wrong answer spends the question. This single property is what makes a two-hundred-value
    // answer space safe: without it, a client walks the range and always wins.
    expect($captcha->verify(answer($problem, $correct + 1))->passes())->toBeFalse()
        ->and($captcha->verify(answer($problem, $correct))->failedBecause(ErrorCode::Replayed))->toBeTrue();
});

it('does not spend a question on a payload it never signed', function (): void {
    $captcha = app(CaptchaService::class);
    $problem = $captcha->issueChallenge();

    $forged = base64_encode((string) json_encode([
        'id' => $problem->id,
        'answer' => 1,
        'expires' => $problem->expiresAt->getTimestamp(),
        'signature' => hash_hmac('sha256', 'anything', 'the-wrong-key'),
    ]));

    // Otherwise the endpoint becomes a way to burn other visitors' outstanding questions.
    expect($captcha->verify($forged)->passes())->toBeFalse()
        ->and($captcha->verify(answer($problem, solveQuestion($problem->question)))->passes())->toBeTrue();
});

it('rejects an answer whose expiry was edited', function (): void {
    $captcha = app(CaptchaService::class);
    $problem = $captcha->issueChallenge();

    $extended = base64_encode((string) json_encode([
        'id' => $problem->id,
        'answer' => solveQuestion($problem->question),
        // Expiry is covered by the signature, so moving it invalidates the payload rather than
        // extending the window.
        'expires' => $problem->expiresAt->getTimestamp() + 86_400,
        'signature' => $problem->signature,
    ]));

    expect($captcha->verify($extended)->passes())->toBeFalse();
});

it('rejects an answer for a question that has aged out of the cache', function (): void {
    $captcha = app(CaptchaService::class);
    $problem = $captcha->issueChallenge();

    Cache::flush();

    expect($captcha->verify(answer($problem, solveQuestion($problem->question)))
        ->failedBecause(ErrorCode::Replayed))->toBeTrue();
});

it('rejects answers that only look numeric', function (string $value): void {
    $captcha = app(CaptchaService::class);
    $problem = $captcha->issueChallenge();

    $correct = solveQuestion($problem->question);

    // Compared as strings on purpose. An int cast would make `007`, ` 7 ` and `7abc` all equal
    // seven, and the last is a parser difference worth probing for.
    expect($captcha->verify(answer($problem, str_replace('7', (string) $correct, $value)))->passes())
        ->toBeFalse();
})->with(['007', '7abc', '7.0', '+7', '0x7']);

it('serves a question over http without the answer', function (): void {
    $response = $this->getJson('/captcha/challenge');

    $response->assertOk()->assertJsonStructure(['id', 'question', 'signature', 'expires_at']);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('generates arithmetic a person can read at every difficulty', function (int $difficulty): void {
    $generator = new ProblemGenerator($difficulty);

    for ($i = 0; $i < 25; $i++) {
        ['question' => $question, 'answer' => $answer] = $generator->generate();

        // Never negative: a minus sign is one more thing to fumble on a phone keyboard and buys
        // nothing against a bot.
        expect($answer)->toBeGreaterThanOrEqual(0)
            ->and(solveQuestion($question))->toBe($answer);
    }
})->with([1, 2, 3]);

it('works on a fresh install with nothing configured', function (): void {
    config()->set('laranail.captcha.providers.math.hmac_key');
    app()->forgetInstance(CaptchaService::class);

    // The signing key falls back to one derived from APP_KEY, so the self-hosted providers need
    // no setup at all — which is the entire reason to offer them.
    $captcha = app(CaptchaService::class);
    $problem = $captcha->issueChallenge();

    expect($captcha->verify(answer($problem, solveQuestion($problem->question)))->passes())->toBeTrue();
});

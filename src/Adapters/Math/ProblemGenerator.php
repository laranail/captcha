<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\Math;

/**
 * Builds the arithmetic, and makes it awkward to parse without being awkward to read.
 *
 * A plain `3 + 4 = ?` is solved by a four-line regex, which is why math captchas have the
 * reputation they do. Three things are done about that here, and none of them make the question
 * harder for a person:
 *
 * 1. **Numbers are written as words as often as digits.** `seven + 4` defeats a scraper looking
 *    for `\d+\s*([+\-*])\s*\d+`, and reads identically to a human.
 * 2. **Operators vary in form.** `×`, `x` and `times` all mean the same thing to a reader and are
 *    three different patterns to a matcher.
 * 3. **Shape varies with difficulty**, including precedence and parentheses, so there is no single
 *    template to target.
 *
 * None of this is the security. The security is that there is one guess per challenge and the
 * answer never leaves the server — this only raises the cost of the trivial automated pass.
 */
final readonly class ProblemGenerator
{
    private const array WORDS = [
        0  => 'zero', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
        6  => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
        11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen',
        16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen', 20 => 'twenty',
    ];

    public function __construct(private int $difficulty = 2) {}

    /**
     * @return array{question: string, answer: int}
     */
    public function generate(): array
    {
        return match (max(1, min(3, $this->difficulty))) {
            1       => $this->twoTerms(),
            2       => $this->threeTerms(),
            default => $this->parenthesised(),
        };
    }

    /**
     * @return array{question: string, answer: int}
     */
    private function twoTerms(): array
    {
        $a = random_int(2, 12);
        $b = random_int(2, 12);

        // Subtraction is ordered so the answer is never negative. A minus sign in the input is one
        // more thing to get wrong on a phone keyboard, for no gain in difficulty for a bot.
        if (random_int(0, 1) === 1) {
            [$a, $b] = [max($a, $b), min($a, $b)];

            return ['question' => $this->render($a, '-', $b), 'answer' => $a - $b];
        }

        return ['question' => $this->render($a, '+', $b), 'answer' => $a + $b];
    }

    /**
     * @return array{question: string, answer: int}
     */
    private function threeTerms(): array
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $c = random_int(1, 9);

        // Multiplication first, then addition — the answer depends on the reader knowing
        // precedence, which is exactly the sort of thing a naive left-to-right evaluator gets
        // wrong.
        return [
            'question' => sprintf(
                '%s %s %s + %s',
                $this->number($a),
                $this->times(),
                $this->number($b),
                $this->number($c),
            ),
            'answer' => ($a * $b) + $c,
        ];
    }

    /**
     * @return array{question: string, answer: int}
     */
    private function parenthesised(): array
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $c = random_int(2, 6);

        return [
            'question' => sprintf(
                '(%s + %s) %s %s',
                $this->number($a),
                $this->number($b),
                $this->times(),
                $this->number($c),
            ),
            'answer' => ($a + $b) * $c,
        ];
    }

    private function render(int $a, string $operator, int $b): string
    {
        $spelled = match ($operator) {
            '+'     => random_int(0, 2) === 0 ? 'plus' : '+',
            default => random_int(0, 2) === 0 ? 'minus' : '−',
        };

        return sprintf('%s %s %s', $this->number($a), $spelled, $this->number($b));
    }

    private function times(): string
    {
        return ['×', 'x', 'times'][random_int(0, 2)];
    }

    /** Digits or words, chosen per term so one question mixes both. */
    private function number(int $value): string
    {
        return random_int(0, 1) === 1 && isset(self::WORDS[$value])
            ? self::WORDS[$value]
            : (string) $value;
    }
}

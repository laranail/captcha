<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\ValueObjects;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Simtabi\Laranail\Captcha\Enums\Provider;

/**
 * The markup contract for one widget instance on one page.
 *
 * Every value here is data, never markup. The blade views do the escaping, and the components
 * return a `View` rather than a string — because a component whose `render()` returns a string has
 * that string written to disk and compiled as a Blade template, which is how the package this
 * replaces turned an unescaped locale into template injection.
 *
 * The instance id is what makes two widgets on one page work. The old implementation reached for
 * `document.querySelector('.cf-turnstile')`, which finds the first widget regardless of which form
 * is being submitted.
 */
final readonly class Widget
{
    /**
     * @param array<string, string|null> $attributes rendered as `data-*` on the container
     */
    public function __construct(
        public Provider $provider,
        public string $instanceId,
        public string $containerClass,
        public array $attributes = [],
        public ?string $scriptUrl = null,
        public ?string $callbackName = null,
    ) {}

    /**
     * A DOM-safe id for a widget instance.
     *
     * Constrained to an alphanumeric suffix rather than accepting caller input, because this ends
     * up inside a CSS selector and a JavaScript identifier. The old button component interpolated
     * a caller-supplied form id straight into `document.querySelector('#…')`.
     */
    public static function generateId(): string
    {
        return 'captcha-' . Str::lower(Str::random(12));
    }

    public function responseField(): string
    {
        return $this->provider->vendorResponseField();
    }

    /** Attributes with the nulls dropped, ready for the blade view to escape. */
    public function attributes(): array
    {
        return array_filter(
            $this->attributes,
            static fn (string|Htmlable|null $value): bool => $value !== null && $value !== '',
        );
    }
}

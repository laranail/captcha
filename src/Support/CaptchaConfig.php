<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed reads of this package's configuration.
 *
 * `Repository::get()` returns `mixed`, so every call site either casts or lies to the analyser.
 * Casting at the call site is worse than it looks: `(int) $config->get('…timeout')` turns a
 * mistyped `'five'` into `0`, and a zero timeout is a very different setting from a missing one.
 * Reading through here means a wrong type falls back to the documented default instead of
 * silently becoming a plausible-looking wrong value.
 *
 * Keys are relative to `laranail.captcha.`, because every key in this package is.
 */
final readonly class CaptchaConfig
{
    private const string PREFIX = 'laranail.captcha.';

    public function __construct(private Repository $config) {}

    public function string(string $key, string $default = ''): string
    {
        $value = $this->raw($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** Distinguishes "not set" from "set to empty", which several settings depend on. */
    public function stringOrNull(string $key): ?string
    {
        $value = $this->string($key);

        return $value === '' ? null : $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->raw($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->raw($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->raw($key);

        return is_bool($value) ? $value : (is_scalar($value) ? (bool) $value : $default);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->raw($key);

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function map(string $key): array
    {
        $value = $this->array($key);

        /** @var array<string, mixed> $filtered */
        $filtered = array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY);

        return $filtered;
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        return array_values(array_filter($this->array($key), is_string(...)));
    }

    public function repository(): Repository
    {
        return $this->config;
    }

    private function raw(string $key): mixed
    {
        return $this->config->get(self::PREFIX . $key);
    }
}

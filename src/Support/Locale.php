<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Support;

/**
 * Validates a language tag before it is put anywhere near a URL or an attribute.
 *
 * This is the fix for the sharpest bug in the package this replaces. Its `Js` view component
 * returned the provider's script tag as a *string*, and a Blade component that returns a string
 * has that string written to disk and compiled as a Blade template. The locale was interpolated
 * into it unescaped, so `<x-captcha-js :lang="$request->input('lang')" />` gave an attacker HTML
 * injection into the script tag, Blade injection through `{{ }}` and `@php`, and an unbounded
 * write of compiled view files — one per distinct string.
 *
 * The components now return views, which removes the compilation path entirely. This is the second
 * layer: a locale is a BCP-47-shaped tag or it is nothing, so even a caller passing user input
 * straight through cannot produce anything but a language tag.
 */
final class Locale
{
    /** `en`, `en-GB`, `zh-Hant-TW` — letters, digits and single hyphens, and nothing else. */
    private const string PATTERN = '/^[A-Za-z]{2,8}(-[A-Za-z0-9]{2,8}){0,3}$/';

    public static function sanitise(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        $locale = trim($locale);

        return preg_match(self::PATTERN, $locale) === 1 ? $locale : null;
    }

    public static function isValid(?string $locale): bool
    {
        return self::sanitise($locale) !== null;
    }
}

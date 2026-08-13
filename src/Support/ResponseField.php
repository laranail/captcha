<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Support;

use Illuminate\Http\Request;
use Simtabi\Laranail\Captcha\Enums\Provider;

/**
 * Finds the captcha token on a request, whichever field the widget wrote it to.
 *
 * Every vendor names its field differently, and the package this replaces exposed that directly:
 * forms bound to `cf-turnstile-response` and validation rules keyed off it, so switching from
 * Turnstile to hCaptcha meant editing every form and every rule in the application. The provider
 * being swappable in config is worth very little if swapping it is a rewrite.
 *
 * So there is one canonical field name. The widget writes it, forms bind to it, and the vendor's
 * own name is still accepted — both because a hand-written form may use it and because an
 * application migrating from the old package should not have to change its markup on day one.
 */
final class ResponseField
{
    /** The field name this package's own widgets write, and the one forms should bind to. */
    public const string CANONICAL = 'captcha';

    /**
     * The two fields a plain HTML self-hosted challenge posts.
     *
     * The math provider needs no JavaScript at all — the question is rendered server-side and the
     * visitor types an answer — which means it arrives as two ordinary form inputs rather than one
     * token a script assembled. They are recombined here so everything downstream still sees a
     * single opaque response, and the rest of the package does not have to know which providers
     * need a browser.
     */
    public const string CHALLENGE = 'captcha_challenge';

    public const string ANSWER = 'captcha_answer';

    /**
     * Pull the token out of a request.
     *
     * Returns null rather than an empty string when nothing usable is present, so the caller can
     * distinguish "absent" from "present but blank" — a distinction the implicit validation rule
     * depends on to report the right failure.
     */
    public static function fromRequest(Request $request, Provider $provider): ?string
    {
        foreach ([self::CANONICAL, $provider->vendorResponseField()] as $field) {
            $value = $request->input($field);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return self::assembleChallengeAnswer($request);
    }

    /**
     * Fold a server-rendered challenge and its typed answer into one token.
     *
     * Deliberately tolerant about the answer — it is whatever the visitor typed, including blank —
     * and strict about the challenge, which this application signed. A blank answer still produces
     * a token so that verification consumes the challenge: dropping it here would hand a client
     * unlimited attempts against the same question by simply submitting nothing first.
     */
    private static function assembleChallengeAnswer(Request $request): ?string
    {
        $challenge = $request->input(self::CHALLENGE);

        if (! is_string($challenge) || $challenge === '') {
            return null;
        }

        $decoded = base64_decode($challenge, true);

        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);

        if (! is_array($payload)) {
            return null;
        }

        $answer = $request->input(self::ANSWER);

        $payload['answer'] = is_string($answer) || is_int($answer) ? (string) $answer : '';

        return base64_encode((string) json_encode($payload));
    }

    /**
     * Every field name a token might arrive under, for the current provider.
     *
     * @return list<string>
     */
    public static function candidates(Provider $provider): array
    {
        return array_values(array_unique([self::CANONICAL, $provider->vendorResponseField()]));
    }
}

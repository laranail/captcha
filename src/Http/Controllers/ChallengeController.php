<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\Contracts\ChallengePayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Mints a proof-of-work challenge for the self-hosted provider.
 *
 * Unauthenticated by nature — the visitor has not proved anything yet, which is what the challenge
 * is for. Two consequences are handled where the route is registered rather than here: it is
 * rate-limited, because minting is the expensive half and an attacker can ask as fast as they can
 * connect; and it is only registered when a self-hosted provider is actually configured, so an
 * application on Turnstile does not carry an extra public endpoint for a code path it never uses.
 *
 * Responses are explicitly uncacheable. A cached challenge is a challenge handed to more than one
 * visitor, and the second one gets a solution someone else already computed.
 */
final readonly class ChallengeController
{
    public function __invoke(CaptchaService $captcha): JsonResponse
    {
        $challenge = $captcha->issueChallenge();

        // Belt and braces: the route is not registered unless the provider issues challenges, so
        // reaching this means the configuration changed under a cached route table.
        if (! $challenge instanceof ChallengePayload) {
            throw new NotFoundHttpException;
        }

        return new JsonResponse($challenge->toArray(), headers: [
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}

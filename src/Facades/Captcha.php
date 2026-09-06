<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Facades;

use Illuminate\Support\Facades\Facade;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\Contracts\CaptchaAdapter;
use Simtabi\Laranail\Captcha\Contracts\ChallengePayload;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;

/**
 * @method static VerificationResult verify(?string $token, ?VerificationContext $context = null)
 * @method static Widget widget(?string $instanceId = null)
 * @method static ChallengePayload|null issueChallenge()
 * @method static CaptchaAdapter adapter()
 * @method static Provider provider()
 * @method static bool isConfigured()
 * @method static CaptchaService fake(bool $verifies = true)
 * @method static CaptchaService fakeScore(float $score)
 * @method static CaptchaService fakeSequence(array $results)
 * @method static CaptchaService assertVerified(?callable $matching = null)
 * @method static CaptchaService assertFailed(?callable $matching = null)
 * @method static CaptchaService assertNothingVerified()
 * @method static CaptchaService assertVerifiedCount(int $expected)
 * @method static void forgetAdapter()
 *
 * @see CaptchaService
 */
final class Captcha extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CaptchaService::class;
    }
}

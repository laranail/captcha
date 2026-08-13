<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Listeners;

use Psr\Log\LoggerInterface;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Events\CaptchaFailed;
use Simtabi\Laranail\Captcha\Events\CaptchaVerified;

/**
 * Records outcomes so a score threshold can be chosen from real traffic rather than guessed.
 *
 * Off unless `laranail.captcha.logging.enabled` is set, and off by default. Captcha failures are
 * ordinary bot traffic at volume, so logging every one turns a flood into a disk-space incident —
 * the failure mode being logged causes a second one.
 *
 * Two levels, because the two kinds of failure need different attention. A misconfiguration or a
 * provider outage is an operator's problem and belongs at `error`; a visitor failing a challenge is
 * the system working, and belongs wherever the operator says.
 *
 * The token never appears. It is a live credential until it is spent, and a log aggregator is a
 * far softer target than the session it protects. Neither does the secret — nothing here has one.
 */
final readonly class LogCaptchaOutcome
{
    public function __construct(
        private LoggerInterface $logger,
        private string $failureLevel = 'debug',
    ) {}

    public function verified(CaptchaVerified $event): void
    {
        $this->logger->debug('[laranail/captcha] verified', [
            'provider' => $event->provider->value,
            'outcome' => $event->result->outcome->value,
            'score' => $event->result->score,
            'duration_ms' => $event->result->durationMs,
            'action' => $event->context->action,
        ]);
    }

    public function failed(CaptchaFailed $event): void
    {
        $level = $event->result->isOperatorFault() ? 'error' : $this->failureLevel;

        $this->logger->log($level, '[laranail/captcha] rejected', [
            'provider' => $event->provider->value,
            'reasons' => array_map(static fn (ErrorCode $e): string => $e->value, $event->result->errors),
            'score' => $event->result->score,
            'duration_ms' => $event->result->durationMs,
            'action' => $event->context->action,
        ]);
    }
}

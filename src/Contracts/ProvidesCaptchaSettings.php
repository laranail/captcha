<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Contracts;

use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Models\CaptchaSetting;

/**
 * Implemented by the host application's own settings model.
 *
 * Most applications that want database-backed credentials already have a settings table, and
 * making them adopt a second one is how a package ends up ignored. Implement this on whatever
 * model you already have and point `laranail.captcha.credentials.database.model` at it; the
 * shipped {@see CaptchaSetting} is the fallback for applications
 * that have nowhere to put these yet.
 *
 * The contract is deliberately one method. A settings model does not need to know what a captcha
 * provider is, only how to answer a lookup.
 */
interface ProvidesCaptchaSettings
{
    /**
     * Return the stored value, or null when there is no row.
     *
     * Null and empty-string mean different things: null lets the chain fall through to config,
     * while an empty string is an answer — an operator having explicitly blanked the key. Which of
     * those disables captcha and which falls back is governed by the
     * `credentials.database.row_absent_means` setting, and it matters: falling back to a stale
     * `.env` secret after an operator deleted the row would defeat the deletion.
     *
     * @param  string  $provider  the {@see Provider} value
     * @param  string  $key  `site_key`, `secret`, or a provider-specific extra
     * @param  string  $environment  the resolved deployment environment
     */
    public function captchaSetting(string $provider, string $key, string $environment): ?string;
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Captcha\ValueObjects\Credentials;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * Where a resolved credential actually came from.
 *
 * Carried on {@see Credentials} so `laranail::captcha.keys`
 * and the doctor check can answer the question that matters during an incident: not "is a key
 * set", but "which of the three places is this key coming from right now". An application whose
 * production keys are silently being served by the test-key store looks perfectly configured until
 * you ask this.
 */
enum CredentialSource: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Database')]
    #[Description('A row in the settings table, decrypted at read time.')]
    case Database = 'database';

    #[Label('Configuration')]
    #[Description('An environment block in config/captcha.php, usually fed by .env.')]
    case Config = 'config';

    #[Label('Provider test keys')]
    #[Description('The published always-pass keys. Never valid outside local and testing.')]
    case TestKeys = 'test-keys';

    #[Label('Unresolved')]
    #[Description('No source produced a credential. The provider is not configured.')]
    case None = 'none';

    /** Whether credentials from this source may be used in production. */
    public function isProductionSafe(): bool
    {
        return $this === self::Database || $this === self::Config;
    }
}

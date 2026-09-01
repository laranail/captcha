<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| laranail/captcha
|--------------------------------------------------------------------------
|
| Published to config/laranail/captcha.php and read as config('laranail.captcha.*').
|
| env() is called here and nowhere else in the package. Anywhere outside a config file it
| returns null once config:cache has run, which is the kind of bug that only appears in
| production — and the environment blocks below would be the first thing to break.
|
*/

use Simtabi\Laranail\Captcha\Enums\Provider;

return [

    /*
    |--------------------------------------------------------------------------
    | Active provider
    |--------------------------------------------------------------------------
    |
    | One at a time. Must be a case of the Provider enum, which is the allow-list — a value
    | that is not a case never resolves to a class.
    |
    | turnstile, hcaptcha, recaptcha-v2, recaptcha-v2-invisible, recaptcha-v3,
    | recaptcha-enterprise, friendly-captcha, arkose, altcha, math, null
    |
    | The default is `math` because it is the only one that works everywhere with nothing
    | configured: self-hosted, no account, no keys, no third-party request, and no JavaScript.
    | A fresh install is protected from the moment it is installed, which a default requiring a
    | Cloudflare signup would not be. Switch to a risk-scoring provider when the stakes justify
    | the setup — that is one line here and no change to your forms.
    |
    */
    'provider' => env('CAPTCHA_PROVIDER', Provider::Math->value),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Resolved database → config → test keys, first complete answer winning. The order is
    | what makes a key changed in an admin UI beat the .env the application booted with.
    |
    */
    'credentials' => [

        // chain | database | config — narrow this to pin resolution to one source.
        'source' => env('CAPTCHA_CREDENTIAL_SOURCE', 'chain'),

        'database' => [
            'enabled' => env('CAPTCHA_CREDENTIALS_FROM_DATABASE', false),

            // Any model implementing ProvidesCaptchaSettings. Null uses the shipped
            // CaptchaSetting model, whose migration you publish with the install command.
            'model' => null,

            'table' => 'captcha_settings',
            'connection' => null,

            // What a reachable database with no matching row means.
            //
            // fall_through — treat it as unset and continue to config. The usual case.
            // disabled     — treat it as deliberate and stop, so captcha reports itself
            //                unconfigured rather than quietly reverting to a stale .env secret
            //                after an operator deleted the row.
            'row_absent_means' => 'fall_through',

            // Off by default, and deliberately so: caching writes the decrypted secret into
            // whatever backs the cache, which is usually a shared Redis with weaker access
            // control than the database it came from and whose contents appear in MONITOR
            // output. A settings lookup is one indexed query. Turn this on only if you have
            // measured that it matters and the cache store is as protected as the database.
            'cache' => [
                'enabled' => false,
                'store' => null,
                'ttl' => 300,
            ],
        ],

        'test_keys' => [
            // The providers' published always-pass keys, so a fresh checkout works with no
            // setup. Refused in production regardless of this setting.
            'enabled' => env('CAPTCHA_TEST_KEYS', true),
            'environments' => ['local', 'testing'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials per environment
    |--------------------------------------------------------------------------
    |
    | Each block is layered over `default`, so a single-key application writes it once under
    | `default` and never thinks about environments. Keys are matched exactly first, then as
    | wildcard patterns — `production*` covers a family without enumerating it.
    |
    */
    'environments' => [

        'default' => [
            'turnstile' => [
                'site_key' => env('CAPTCHA_SITE_KEY'),
                'secret' => env('CAPTCHA_SECRET_KEY'),
            ],
            'hcaptcha' => [
                'site_key' => env('CAPTCHA_SITE_KEY'),
                'secret' => env('CAPTCHA_SECRET_KEY'),
            ],
            'recaptcha-v2' => [
                'site_key' => env('CAPTCHA_SITE_KEY'),
                'secret' => env('CAPTCHA_SECRET_KEY'),
            ],
            'recaptcha-v2-invisible' => [
                'site_key' => env('CAPTCHA_SITE_KEY'),
                'secret' => env('CAPTCHA_SECRET_KEY'),
            ],
            'recaptcha-v3' => [
                'site_key' => env('CAPTCHA_SITE_KEY'),
                'secret' => env('CAPTCHA_SECRET_KEY'),
            ],
            'recaptcha-enterprise' => [
                'site_key' => env('CAPTCHA_SITE_KEY'),
                'secret' => env('CAPTCHA_ENTERPRISE_API_KEY'),
                'project_id' => env('CAPTCHA_ENTERPRISE_PROJECT_ID'),
            ],
            'friendly-captcha' => [
                'site_key' => env('CAPTCHA_SITE_KEY'),
                'secret' => env('CAPTCHA_SECRET_KEY'),
            ],
            'arkose' => [
                'site_key' => env('CAPTCHA_SITE_KEY'),
                'secret' => env('CAPTCHA_SECRET_KEY'),
                // The per-customer verify subdomain: {client}-verify.arkoselabs.com
                'client' => env('CAPTCHA_ARKOSE_CLIENT'),
            ],
        ],

        // 'production' => [ 'turnstile' => ['site_key' => env('CAPTCHA_PROD_SITE_KEY'), ...] ],
        // 'staging'    => [ 'turnstile' => ['site_key' => env('CAPTCHA_STAGING_SITE_KEY'), ...] ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    |
    | The checks applied after the provider answers. Every one of these closes a way a
    | genuine, provider-approved token can still be the wrong token for this request.
    |
    */
    'verification' => [

        'timeout' => 5,
        'connect_timeout' => 2,
        'verify_tls' => true,

        // Reject a challenge solved on a host this application does not serve. The site key is
        // public, so anyone can host the widget and collect real tokens; the hostname is the
        // only field that says where one was solved. An empty list disables the comparison.
        'enforce_hostname' => true,
        'allowed_hostnames' => [],

        // Reject a token minted for a different action — the newsletter form, replayed on login.
        'enforce_action' => true,

        // How long after solving a token stays usable, in seconds. Cloudflare expires its own
        // at 300 and Google at 120; this is the check against the timestamp they return.
        'max_age' => 300,

        'replay_guard' => [
            'enabled' => true,
            'ttl' => 600,
            'store' => null,

            // Whether a cache outage should let submissions through. False rejects real
            // visitors while the cache is down; true reopens the replay window. Neither is
            // free, so it is a decision rather than a default.
            'fail_open' => false,
        ],

        // Score bands for the providers that return one. Above `allow` proceeds; below
        // `review` is blocked; between them the result carries Outcome::Review so the host can
        // ask for a second factor instead of rejecting.
        'score' => [
            'allow' => 0.5,
            'review' => 0.3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Production safety
    |--------------------------------------------------------------------------
    |
    | Environment names treated as production. A deny-list rather than trusting
    | app()->environment() alone: APP_ENV is a deployment name, and some products ship it as a
    | feature flag — Worksuite reports `codecanyon` on live installations.
    |
    */
    'production_environments' => ['production', 'prod'],

    /*
    |--------------------------------------------------------------------------
    | Provider options
    |--------------------------------------------------------------------------
    */
    'providers' => [

        'recaptcha' => [
            // Required by v3 and Enterprise, and checked against what the provider echoes back.
            'action' => 'submit',
        ],

        'friendly-captcha' => [
            // eu | global. EU is the default because data residency is the reason to choose
            // this provider at all.
            'endpoint' => env('CAPTCHA_FRIENDLY_ENDPOINT', 'eu'),
            'start' => 'focus',

            // Friendly Captcha's reset is a method on the WidgetHandle that sdk.createWidget()
            // returns — there is no global to call. Expose your own function on window and name
            // it here to enable expiry recovery. See docs/tools/friendly-captcha.md.
            'reset_function' => null,
        ],

        'arkose' => [
            // Arkose's reset is a method on the enforcement instance handed to your own
            // setupEnforcement callback — there is no global to call. Expose your own function
            // on window and name it here. See docs/tools/arkose.md.
            'reset_function' => null,
        ],

        'altcha' => [
            // Signs every challenge. Left null it derives a key from APP_KEY — never APP_KEY
            // itself — so this works on a fresh install and rotating one key does not silently
            // change the meaning of the other.
            'hmac_key' => env('CAPTCHA_CHALLENGE_KEY'),
            'max_number' => 100_000,
            'expires_after' => 300,
        ],

        'math' => [
            'hmac_key' => env('CAPTCHA_CHALLENGE_KEY'),

            // 1 = two terms; 2 = three terms with precedence; 3 = parenthesised.
            'difficulty' => env('CAPTCHA_MATH_DIFFICULTY', 2),

            'expires_after' => 300,
        ],

        'null' => [
            'verifies' => true,

            // The null provider accepts every submission. Refused in production unless this is
            // set, so it cannot happen by accident — only on purpose, in writing.
            'allow_in_production' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Records every outcome with its score and duration, so a score threshold can be chosen
    | from your own traffic rather than from an example number. Off by default: captcha
    | failures are ordinary bot traffic at volume, and a line per rejection turns a flood
    | into a disk-space incident.
    |
    | Misconfiguration and provider outages are always logged at error level when this is on,
    | regardless of `failure_level` — those are an operator's problem, not a visitor's.
    |
    */
    'logging' => [
        'enabled' => env('CAPTCHA_LOGGING', false),
        'failure_level' => env('CAPTCHA_LOG_LEVEL', 'debug'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Challenge endpoint
    |--------------------------------------------------------------------------
    |
    | Where the self-hosted providers (altcha, math) mint challenges. Unauthenticated by
    | nature — the visitor has not proved anything yet — so it is rate-limited: minting is the
    | expensive half and an attacker can ask as fast as they can connect.
    |
    */
    'challenge' => [
        'route' => '/captcha/challenge',
        'rate_limit' => '60,1',
        'store' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Widget
    |--------------------------------------------------------------------------
    |
    | Defaults for whichever provider is active; a provider block overrides them.
    |
    */
    'widget' => [
        'theme' => env('CAPTCHA_THEME', 'auto'),
        'size' => env('CAPTCHA_SIZE', 'normal'),
        'language' => env('CAPTCHA_LOCALE'),

        // Emit a nonce on injected script tags so a strict CSP does not need unsafe-inline.
        'nonce' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bot management
    |--------------------------------------------------------------------------
    |
    | The per-request edge tier, a different axis from form-level captcha. Off by default;
    | fails open, because it sits in front of every request and failing closed on a provider
    | outage takes the site down to stop traffic that was probably legitimate.
    |
    */
    'bot_management' => [
        'enabled' => false,
        'adapter' => 'null',
        'fail_open' => true,

        'datadome' => [
            'server_key' => env('DATADOME_SERVER_KEY'),
            'endpoint' => env('DATADOME_ENDPOINT', 'https://api.datadome.co/validate-request/'),
            'timeout' => 1,
        ],
    ],
];

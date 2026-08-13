# Ask for a second factor instead of rejecting

Score-based providers answer in three bands. Blocking the middle one rejects visitors who are
probably real; that band is where a second factor belongs.

```php
use Simtabi\Laranail\Captcha\Enums\Outcome;
use Simtabi\Laranail\Captcha\Facades\Captcha;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;

$result = Captcha::verify($token, new VerificationContext(
    action: 'login',
    remoteIp: $request->ip(),
));

return match ($result->outcome) {
    Outcome::Allow  => $this->logIn($user),
    Outcome::Review => $this->sendOneTimeCode($user),
    Outcome::Block  => back()->withErrors(['captcha' => __('captcha::validation.low-score')]),
};
```

Tune the bands to your own traffic rather than to an example number:

```php
'verification' => ['score' => ['allow' => 0.7, 'review' => 0.4]],
```

Listen for `CaptchaVerified` and record `$event->result->score` for a while first. A threshold
picked without seeing your own score distribution is a guess, and the cost of guessing high is real
users turned away.

The validation rule collapses this to pass/fail — `Review` passes. Use the facade directly when you
want the middle band.

See [Configuration](../configuration.md) and [Providers](../providers.md).

---

[← Docs index](../../README.md#documentation)

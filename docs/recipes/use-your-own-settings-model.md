# Use your own settings model

Most applications that want database-backed credentials already have a settings table. Point the
package at it instead of adopting a second one.

Implement one method on the model you already have:

```php
use Simtabi\Laranail\Captcha\Contracts\ProvidesCaptchaSettings;

class Setting extends Model implements ProvidesCaptchaSettings
{
    public function captchaSetting(string $provider, string $key, string $environment): ?string
    {
        return static::query()
            ->where('key', "captcha.{$environment}.{$provider}.{$key}")
            ->value('value');
    }
}
```

```php
'credentials' => [
    'database' => [
        'enabled' => true,
        'model' => \App\Models\Setting::class,
    ],
],
```

Return `null` when there is no row — that is what lets the chain fall through to config. An empty
string is an answer, not an absence, and `row_absent_means` decides what an absence means.

Your method must not throw. It is called on the login path, so an exception there fails every
login; the package guards against it anyway, but a store that raises is a store that silently stops
being consulted.

See [Credentials](../credentials.md).

---

[← Docs index](../../README.md#documentation)

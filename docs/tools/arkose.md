# Arkose Labs

Enterprise-grade, and the strongest option here against organised bot farms. Paid and
account-gated.

| | |
|---|---|
| Key | `arkose` |
| Verify endpoint | `https://{client}-verify.arkoselabs.com/api/v4/verify/` |
| Credentials | `site_key`, `secret` (the private key), `client` |

The endpoint is per-customer, so `client` is a required credential extra rather than a constant.
Without it the adapter reports itself unconfigured instead of posting to a host that does not exist.

## Reading the verdict

The field that decides the outcome is `session_details.solved`, nested two levels down and absent
entirely on some error shapes. It is compared with an explicit identity check against `true` —
`$body['session_details']['solved'] ?? true` would be a catastrophic default, and it is one
character away.

A `suppressed` session — where Arkose judged the traffic safe and presented no challenge — is a
pass. That is the normal case for most real visitors, and treating it as a failure would block
almost everyone.

## Expiry recovery

Arkose's reset lives on the enforcement instance handed to your own setup callback, so the package
cannot call it for you.

```js
function setupEnforcement(myEnforcement) {
    myEnforcement.setConfig({ selector: '#enforcement-trigger', onCompleted: (r) => { /* … */ } });

    window.resetArkose = () => {
        myEnforcement.reset();
        // Arkose asks for a pause before re-running after a reset.
        setTimeout(() => myEnforcement.run(), 500);
    };
}
```

```php
'providers' => ['arkose' => ['reset_function' => 'resetArkose']],
```

Without it, an expired token means a page reload. No default is shipped: a guessed global name
would resolve to nothing and produce a callback that silently does not reset.

---

[← Docs index](../../README.md#documentation)

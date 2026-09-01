# Security policy

## Reporting a vulnerability

Report vulnerabilities privately to `security@simtabi.com`. Never open a public issue for a
security problem.

Include the package version, the provider in use, and a minimal reproduction if you have one. You
will get an acknowledgement within a few working days.

> **Prefer GitHub private vulnerability reporting** when you can: open it from this
> repository's Security tab. The report arrives attached to the repo with a draft advisory
> and a CVE request path already in place. Email is the fallback for anyone who would
> rather not use GitHub.

## Scope

This package sits on the authentication and anti-abuse path, so the following are always in scope:

- Any way to pass captcha validation without solving a challenge.
- Any way to replay a solved token — across requests, forms, or origins.
- Leakage of a provider secret into logs, exception messages, cached values, or rendered output.
- Injection through a widget attribute, a locale value, or a challenge payload.
- A credential-resolution path that silently falls back to test keys in production.

## Supported versions

Pre-1.0, only the latest tag receives fixes.

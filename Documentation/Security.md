# Security

## Failure Policy

| State | Behavior |
|---|---|
| Explicitly disabled with valid configuration | Proceeds without CAPTCHA |
| Enabled with missing/invalid credentials | Rejects the request |
| Provider timeout, transport/SDK error, or rejected solution | Rejects the request |
| Missing, blank, non-string, or oversized solution | Rejects before contacting the provider |

Malformed persisted settings may fail before a disabled flag is evaluated. Audit protected forms and login surfaces after configuration changes.

Verification uses one attempt, a five-second timeout, and no synchronous retry. Provider outages therefore block protected requests until recovery or explicit disablement. Visitors receive only localized generic errors.

Provider success is determined by the SDK's decoded `VerifyOutput::isOK()` result. The extension bounds solutions but does not impose another syntax.

## Credentials and Request Data

- Stored API keys are never rendered in settings forms; blank input retains the existing key.
- `PRIVATE_CAPTCHA_SITE_API_KEYS` and `PRIVATE_CAPTCHA_BACKEND_API_KEY` override persisted keys and are not written back.
- Extension diagnostics exclude API keys, solutions, submitted content, usernames, and passwords.

Use environment-backed secrets and rotate any exposed key. Restrict site/extension configuration, process environments, deployment data, and backups.

Settings and CAPTCHA values still enter HTTP request bodies. Redact or disable body capture in proxies, WAFs, APM, middleware, and exception tooling. Frontend and backend authentication do not scrub the parsed request body after reading it.

Form Framework and Powermail remove solutions from integration data before normal output and persistence. Multi-step flows use short-lived, bound, single-use proofs in `tx_privatecaptcha_formproof`.

## CSP and Endpoints

Effective frontend integrations extend TYPO3-managed `script-src`, `frame-src`, `style-src`, and `connect-src`. Backend CSP is extended only when its login widget is collected.

| Mode | API | Widget CDN | Added CSP sources |
|---|---|---|---|
| Default | `api.privatecaptcha.com` | `cdn.privatecaptcha.com` | `https://privatecaptcha.com`, `https://*.privatecaptcha.com` |
| EU | `api.eu.privatecaptcha.com` | `cdn.privatecaptcha.com` | Same as default |
| Custom | `api.<root>` | `cdn.<root>` | Only those two hosts |

Custom roots override EU mode. Frontend CSP is extended site-wide whenever Form Framework, Powermail, or frontend-login protection is effective.

The extension augments TYPO3-managed policy only. Configure independent proxy/CDN headers separately with the narrowest provider-verified sources, then inspect browser CSP and network errors.

## Custom Domains

A custom root delegates trust to `cdn.<root>`, whose JavaScript runs in visitors' browsers, and `api.<root>`, which receives API keys and solutions. Verify ownership, DNS, TLS, availability, and data handling.

Validation accepts only canonical public DNS roots and rejects URLs, ports, paths, IPs, private/single-label names, reserved suffixes, and control characters. This validates syntax, not ownership or trust.

Changing the root requires a submitted API key and is blocked while an environment key override is active. A successful connection test checks the API endpoint, not CDN content.

## Debug and Styles

Debug mode enables widget diagnostics only. Custom styles are bounded, escaped, and passed through `data-styles`; their CSS semantics remain operator-controlled.

## Backend Login

Only TYPO3's native username/password provider has a supported widget. Other providers may be rejected without rendering a usable widget. Establish CLI and environment recovery before enabling backend protection; see [Recovery](Recovery.md).

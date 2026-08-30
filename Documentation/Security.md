# Security

## Runtime Failure Policy

Private Captcha distinguishes an explicitly disabled integration from a requested integration that cannot operate safely.

| State | Behavior |
|---|---|
| Integration explicitly disabled in otherwise valid configuration | No widget is rendered and the affected form or login proceeds without CAPTCHA protection. |
| Integration requested but credentials are missing or invalid | The request is rejected rather than silently proceeding. |
| Provider timeout, transport error, SDK parse/type exception, or non-OK result | The request is rejected. |
| Missing, blank, non-string, or oversized solution | The request is rejected before a provider call. |

An explicitly disabled integration is unprotected by design when the containing configuration can be resolved. Malformed persisted settings can still cause a request to fail before the disabled flag is evaluated. Audit forms and login surfaces after configuration changes so that an unprotected state is not mistaken for active protection.

Runtime verification uses one provider attempt, a five-second timeout, and no synchronous retry. Provider outages therefore reject submissions and protected logins until verification succeeds or an administrator explicitly disables the integration.

Visitors receive a localized generic failure. Provider diagnostics and server exceptions are not rendered to the visitor.

The provider SDK's decoded `VerifyOutput::isOK()` result is the success decision. Arbitrary bounded nonblank solution strings are sent to the provider; the extension does not impose a separate solution syntax. A successfully decoded provider response is accepted only when the SDK reports it as OK.

## Credential Handling

- Existing API keys are never rendered back into the settings form.
- The API-key input is treated as a sensitive parameter and blank input retains the current persisted key.
- Site API keys can be supplied through `PRIVATE_CAPTCHA_SITE_API_KEYS`.
- The backend API key can be supplied through `PRIVATE_CAPTCHA_BACKEND_API_KEY`.
- Environment-provided keys override persisted keys and are not written back by the settings module.
- API keys and CAPTCHA solutions are excluded from normal diagnostic serialization and logging.

Use secret-manager-backed environment keys in production. Never commit API keys in TYPO3 site or extension configuration. If a key reaches version control, an unauthorized log, or another unauthorized store, revoke and rotate it.

Restrict access to TYPO3 site configuration, extension configuration, process environments, deployment configuration, and backups according to the sensitivity of the stored API keys.

The logging guarantee applies to diagnostics emitted by this extension. API keys enter settings POST bodies, and solutions enter form/login POST bodies before integration handling. Disable request-body capture or redact sensitive settings routes and form fields in reverse proxies, WAFs, APM agents, request-debug middleware, and exception tooling. Restrict access and retention for any system that can observe request bodies.

## CAPTCHA Data

The submitted solution is read only from the owning form or login request and bounded before provider use. Form Framework and Powermail remove it from integration data as early as possible. Frontend and backend authentication read it from the parsed request body but do not scrub that body for the remainder of the request, so ingress and request logging require explicit redaction.

Form Framework and Powermail use short-lived, bound, single-use proofs where a multi-step flow must continue after verification. Raw solutions are not intended for summaries, email output, Powermail persistence, or application logs. Apply TYPO3's database schema update so the proof table is available.

## Content Security Policy

When a frontend integration is effective, the extension extends TYPO3-managed frontend CSP directives `script-src`, `frame-src`, `style-src`, and `connect-src`. Backend CSP is extended only when the protected backend-login widget is collected.

Global and EU configurations add:

```text
https://privatecaptcha.com
https://*.privatecaptcha.com
```

Custom deployments add only their derived hosts:

```text
https://api.<custom-root-domain>
https://cdn.<custom-root-domain>
```

The extension extends existing TYPO3 policy; it does not replace it. The wildcard list above describes the extension's TYPO3-managed mutation, not a recommendation to copy a broad wildcard into every independent policy. For CSP headers managed by a reverse proxy, web server, CDN, or security middleware, use provider-verified directive-specific hosts and the narrowest sources that permit the configured widget and API endpoints. Inspect browser CSP and network errors after enabling an integration.

Frontend policy is extended for the site whenever Form Framework, Powermail, or frontend-login protection is effective, not only on pages that currently contain a widget.

## Endpoint Selection

| Configuration | API endpoint | Widget CDN |
|---|---|---|
| Default | `api.privatecaptcha.com` | `https://cdn.privatecaptcha.com` |
| EU isolation | `api.eu.privatecaptcha.com` | `https://cdn.privatecaptcha.com` |
| Custom root | `api.<custom-root-domain>` | `https://cdn.<custom-root-domain>` |

A custom root domain takes precedence over EU isolation.

## Custom Domains

A custom root domain delegates trust to a separate Private Captcha deployment:

- JavaScript from `cdn.<custom-root-domain>` executes in visitors' browsers.
- `api.<custom-root-domain>` receives the API key and CAPTCHA solutions.
- The operator is responsible for DNS, TLS certificates, service ownership, availability, and data handling at both hosts.

The extension accepts only canonical public DNS roots. It rejects URLs, ports, paths, IP literals, single-label/private names, reserved suffixes, control characters, and roots without a recognized delegated TLD. This validation prevents malformed destinations; it does not prove ownership or trustworthiness.

Changing the custom root requires an explicitly submitted API key. The settings module refuses the change while an environment API-key override is active, preventing a backend administrator from redirecting an operator-managed key to a new host.

Review the custom deployment before configuring the root. A successful connection test checks the selected API endpoint but does not validate the custom CDN or its JavaScript.

## Debug and Custom Styles

Debug mode enables the provider widget's documented diagnostics. It does not enable server exception output or disclosure of API responses, keys, or solutions.

Custom styles are passed as the widget's bounded `data-styles` value after text validation and HTML-attribute escaping. They are not inserted into a page-level style element. CSS semantics are operator-controlled and should be reviewed like other presentation configuration.

## Backend Login Safety

Only TYPO3's native username/password provider has a supported widget integration. SSO, LDAP, and custom providers are incompatible unless they render and submit the same Private Captcha field. The backend authentication service can otherwise reject their login POST without presenting a usable widget.

Create and test out-of-band command-line and environment access before enabling backend protection. Invalid runtime configuration fails closed and can prevent normal backend login. The required recovery procedure is documented in [Backend Recovery](Recovery.md).

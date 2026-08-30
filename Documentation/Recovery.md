# Recovery and Troubleshooting

## Prepare Backend Recovery

Before enabling backend protection, verify out-of-band access to TYPO3 CLI commands and the PHP/web process environment. Test the status command. Do not rely on SSO, LDAP, or custom login providers as recovery paths; only the native provider has a supported widget. CLI commands and existing sessions are not gated.

## Commands

```bash
vendor/bin/typo3 private-captcha:backend:status
vendor/bin/typo3 private-captcha:backend:disable
```

`status` reports requested, effective, and persisted protection; runtime overrides; emergency-disable state; credential state; and the last connection test. It never prints credentials or contacts Private Captcha. Its exit status fails only when runtime backend configuration cannot be resolved.

`disable` sets persisted `backendLoginEnabled=false` while preserving valid settings. A malformed backend scope is replaced with the disabled flag. Runtime configuration may still force protection on; check `status` and remove that override.

## Emergency Disable

Set this for both web and CLI processes, then reload them:

```text
PRIVATE_CAPTCHA_DISABLE_BACKEND_LOGIN=true
```

Any non-empty value enables it except case-insensitive `0`, `false`, `no`, or `off`. Use it only for recovery.

## Lockout Procedure

1. Set the emergency variable and reload web and CLI runtimes.
2. Run `status`; confirm emergency disable is active and effective protection is disabled.
3. Run `private-captcha:backend:disable` and confirm persisted protection is disabled.
4. Remove any runtime override forcing `backendLoginEnabled=true`, then reload.
5. While emergency disable remains active, confirm requested and persisted protection are disabled.
6. Remove the emergency variable, reload again, and confirm all protection states remain disabled.
7. Repair credentials, endpoint, CSP, or network access before re-enabling through **Save**.

The emergency switch also overrides malformed persisted configuration; keep it active while repairing or resetting the backend scope.

## Troubleshooting

### Connection test fails

- Check credentials, DNS, TLS, proxies, firewalls, and the selected API endpoint.
- Custom deployments must provide compatible services at `api.<root-domain>`.
- Check TYPO3 logs for reason/provider codes, exception class, attempts, and duration.
- **Save** disables the scope's integrations after failure; **Test connection** does not change enablement.

### Widget is absent

- Check effective integration configuration and resolved credentials.
- Check required felogin or Powermail packages.
- Check browser network/CSP errors and TYPO3 configuration warnings.
- Custom felogin templates must render `{privateCaptchaMarkup -> f:format.raw()}`.

### Submit remains disabled

- Check that the provider script and extension JavaScript loaded without CSP or browser errors.
- Complete every widget in the form. Resets, errors, failed submissions, and back/forward-cache restoration clear solutions.

### Protected request always fails

- Check effective credentials and that the extension's widget field is submitted.
- Check provider connectivity and safe reason codes in TYPO3 logs.
- Do not reuse solutions; they are single-use.

### Proof-table error

Run TYPO3's database schema update and confirm `tx_privatecaptcha_formproof` exists.

### Powermail field is unavailable

Require TYPO3 13.4 and active Powermail `>=13.2,<14.0`, then successfully save effective Powermail site settings before reopening the field selector.

## Logging

The extension uses TYPO3's PSR-3 configuration and no dedicated log file. Search configured destinations for `Private Captcha`.

Diagnostic context may include integration, site identifier, reason/provider codes, exception class, attempt count, duration, and a hashed provider trace ID. It excludes API keys, CAPTCHA solutions, form content, usernames, and passwords. Independently disable or redact request-body capture in proxies, WAFs, APM, middleware, and exception tooling.

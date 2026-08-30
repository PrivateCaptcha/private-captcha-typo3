# Backend Recovery and Troubleshooting

## Prepare Before Enabling Backend Protection

Before enabling installation-wide backend-login protection:

- confirm that an operator can run TYPO3 console commands without a backend session;
- confirm that the PHP/web runtime environment can be changed and reloaded out of band;
- record where deployment configuration can override TYPO3 extension configuration;
- test the status command below;
- keep an authenticated recovery session only as a convenience, not as the recovery mechanism.

Only TYPO3's native username/password provider has a supported backend widget integration. SSO, LDAP, and custom providers can be blocked unless they render and submit the same Private Captcha field, so they are not fallback recovery paths. Backend protection does not gate TYPO3 CLI commands or existing authenticated sessions.

## Status Command

Run the status command from the TYPO3 project root:

```bash
vendor/bin/typo3 private-captcha:backend:status
```

The command reports these fields without printing credentials:

- `Protection requested`: the runtime configuration flag;
- `Protection effective`: whether complete resolved configuration currently protects login;
- `Persisted protection`: the locally persisted flag;
- `Configuration override`: whether runtime configuration overrides the persisted flag;
- `Emergency disable`: whether the emergency environment switch is active;
- `API key` and `Sitekey`: configured, missing, or invalid state only;
- `Last connection test`: persisted success/failure and UTC time, if available.

The command does not contact Private Captcha. It returns failure only when runtime backend configuration cannot be resolved; disabled or incomplete protection can still produce a successful command exit.

## Persistent Disable Command

Disable the persisted backend-login integration without a backend session:

```bash
vendor/bin/typo3 private-captcha:backend:disable
```

For a valid backend configuration array, this command sets only `backendLoginEnabled=false` and preserves credentials, widget settings, connection-test metadata, and other extension configuration. If the backend scope is malformed rather than an array, recovery replaces that malformed scope with the disabled flag and cannot preserve its invalid values. The command is idempotent and returns failure if the configuration lock or persistence operation fails.

Runtime configuration can override the persisted value. If status still reports protection requested after this command, remove the deployment/runtime override that forces `backendLoginEnabled` on.

## Emergency Disable

`PRIVATE_CAPTCHA_DISABLE_BACKEND_LOGIN` is evaluated before persisted backend configuration. Configure it in the environment of the PHP/web process and the TYPO3 CLI process, then reload or restart those runtimes.

Any non-empty value enables the emergency disable except case-insensitive `0`, `false`, `no`, or `off`:

```text
PRIVATE_CAPTCHA_DISABLE_BACKEND_LOGIN=true
```

Do not leave the switch enabled as normal operating configuration. It is a temporary lockout-recovery control.

## Lockout Recovery Procedure

1. Set `PRIVATE_CAPTCHA_DISABLE_BACKEND_LOGIN=true` through the deployment or process-manager environment.
2. Reload or restart the PHP/web runtime so backend requests receive the new value.
3. Run `vendor/bin/typo3 private-captcha:backend:status` in a CLI process with the same environment.
4. Confirm `Emergency disable: active` and `Protection effective: disabled`.
5. Run `vendor/bin/typo3 private-captcha:backend:disable`, then run status and confirm `Persisted protection: disabled`. `Protection requested` can remain enabled while a runtime override is present.
6. Remove any deployment configuration that forces `backendLoginEnabled=true`, then reload both web and CLI runtimes.
7. Run status again under the emergency switch and confirm requested and persisted protection remain disabled. Override evaluation is intentionally unavailable while the switch is active.
8. Remove the emergency environment variable and reload both web and CLI runtimes again.
9. Run status without the emergency switch and confirm `Emergency disable: inactive`, `Configuration override: inactive`, and requested, persisted, and effective protection all remain disabled.
10. Repair credentials, endpoints, CSP, or network access before re-enabling protection with **Save**.

If persisted configuration is malformed, the emergency switch still takes precedence. Keep it active while repairing or resetting the backend scope.

## Troubleshooting

### Connection test fails

- Confirm that API key and sitekey are both present.
- Confirm DNS, TLS, proxy, firewall, and outbound access to the selected API endpoint.
- For a custom deployment, confirm that `api.<root-domain>` implements the required puzzle and verification services.
- Inspect the saved reason, provider code, exception class, attempt count, and duration in TYPO3 logs.
- Remember that success does not validate the production sitekey, origin authorization, or widget CDN.

**Save** disables all integrations in the selected scope after failure. **Test connection** leaves current enablement unchanged.

### Widget is absent

- For settings-module configuration, confirm that the integration is enabled through a successful save-time test. For direct configuration, inspect the enabled flag and resolved credentials because test metadata is not enforced at runtime.
- Confirm that both credentials resolve for the current site or backend scope.
- Confirm that felogin or a compatible Powermail package is active where required.
- Check browser network and CSP errors for the selected CDN.
- Check custom felogin templates for `{privateCaptchaMarkup -> f:format.raw()}`.
- Review TYPO3 logs for invalid custom-domain or configuration warnings.

### Submit control remains disabled

- Confirm that the provider widget script and the extension JavaScript module loaded.
- Check browser JavaScript and CSP errors.
- Complete every Private Captcha widget in the same form.
- A widget reset, widget error, failed submission, or browser back/forward-cache restoration intentionally clears the solution and disables submission again.

### Protected request always fails

- Confirm that the integration is not merely requested with missing credentials.
- Confirm that the page submits the widget field generated by this extension.
- Confirm that a custom frontend-login template renders the provided markup.
- Check provider connectivity and safe verification reason codes in TYPO3 logs.
- Do not retry a previously submitted solution; solutions are single-use.

### Form or Powermail reports a proof-table error

Apply TYPO3's database schema update and confirm that `tx_privatecaptcha_formproof` exists. See TYPO3's [database compare documentation](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Database/DatabaseUpgrade/Index.html).

### Powermail field type is unavailable

- Confirm TYPO3 13.4.
- Confirm active Powermail `>=13.2,<14.0`.
- Confirm that site credentials are valid and Powermail protection is effective.
- Save and test the site settings before reopening the Powermail field selector.

## Logging

The extension uses TYPO3's configured PSR-3 logging system and does not define a dedicated output file. Inspect the logging destinations configured for the installation.

Relevant messages include:

```text
Private Captcha verification failed.
Private Captcha connection test failed.
Private Captcha settings connection test completed.
Private Captcha settings reset.
Private Captcha CSP was not extended because configuration is invalid.
Private Captcha was not rendered in the frontend login because configuration is invalid.
```

Safe diagnostic context emitted by this extension can include integration, site identifier, reason code, provider code, exception class, attempt count, duration, and a hash of the provider trace ID. The extension does not add API keys, CAPTCHA solutions, submitted form content, usernames, or passwords to its own diagnostic context. Other infrastructure can still capture request bodies; disable or redact request-body logging at reverse proxies, WAFs, APM agents, middleware, and exception tooling.

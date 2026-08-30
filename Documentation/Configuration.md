# Configuration

## Settings Module

Private Captcha settings are available to TYPO3 administrators under **Site > Private Captcha**.

The module separates configuration into these scopes:

| Scope | Stored configuration | Available integrations |
|---|---|---|
| Site | Independent settings for each TYPO3 site | Form Framework, Powermail, frontend login |
| Backend | One installation-wide configuration | Native TYPO3 backend login |

Site settings are stored in that site's configuration under `privateCaptcha`. Backend settings are stored in TYPO3 extension configuration under `private_captcha.backend`.

## Settings Reference

| Setting | Default | Notes |
|---|---|---|
| API key | Empty | Secret used for provider authorization. Maximum 4096 bytes. An existing value is never rendered back into the form. |
| Sitekey | Empty | Public site identifier used by the widget and verification request. |
| Theme | `light` | `light` or `dark`. |
| Language | `auto` | `auto`, `en`, `de`, `es`, `fr`, `it`, `nl`, `sv`, `no`, `pl`, `fi`, `et`, `uk`, or `tr`. |
| Start mode | `auto` | `auto` or `click`. |
| EU isolation | Off | Uses the EU API endpoint. The official widget still loads from the global Private Captcha CDN. |
| Custom root domain | Empty | Trusted custom deployment root. Overrides EU isolation. See [Custom Domains](Security.md#custom-domains). |
| Debug mode | Off | Enables widget diagnostics. It does not expose server exceptions, API responses, credentials, or solutions. |
| Custom widget styles | Empty | Passed as the widget `data-styles` value. Maximum 2048 bytes; CSS syntax is not validated. |
| Integrations | Off | Each integration is opt-in and scope-specific. |

Leaving the API-key field empty retains an existing persisted key. Entering a value replaces it. Use **Reset** to clear the complete selected scope.

## Save, Test, and Reset

### Save

The save action normalizes the submitted settings, performs a bounded connection test, and then persists the result.

- On success, the requested integrations are enabled.
- On failure, credentials and presentation settings are saved for correction, but every integration in that scope is disabled.
- Empty credentials do not trigger a provider request and leave integrations disabled.

This save-time disabling is deliberate. It prevents an unverified configuration from becoming active.

### Test connection

The test-only action checks the submitted candidate settings and updates the last-test metadata. It does not save other candidate values and does not change current integration enablement.

The connection test uses Private Captcha's test property with one attempt and five-second connection/request timeouts. A successful result proves that the API key is authorized and that the selected API endpoint is reachable. It does not prove:

- that the configured production sitekey belongs to the account;
- that the production origin is authorized;
- that the widget CDN is reachable or correctly configured.

### Reset

Reset clears the persisted settings for the selected scope, including credentials, presentation values, connection metadata, and integration flags. Environment-provided API keys remain effective until removed from the runtime environment.

## Environment API Keys

API keys can be supplied by the deployment environment instead of persisted TYPO3 configuration.

Use secret-manager-backed environment values for production. Site configuration and extension configuration can be included in source control, deployment artifacts, exports, and backups; never commit an API key in either location. If a key reaches version control or another unauthorized store, revoke and rotate it.

`PRIVATE_CAPTCHA_SITE_API_KEYS` is a JSON object that maps exact TYPO3 site identifiers to API keys:

```text
PRIVATE_CAPTCHA_SITE_API_KEYS={"site-identifier":"replace-with-site-api-key"}
```

The JSON value is limited to 65,536 bytes and 1,024 entries. A mapping affects only the matching site identifier.

`PRIVATE_CAPTCHA_BACKEND_API_KEY` overrides the persisted installation-wide backend API key:

```text
PRIVATE_CAPTCHA_BACKEND_API_KEY=replace-with-backend-api-key
```

Environment keys are not written into site or extension configuration. The settings module still requires a sitekey and the remaining settings for each scope.

Changing a custom root domain requires an explicitly submitted API key and is rejected while an environment API-key override is active. Configure and review the trusted custom domain before deploying an environment key.

## Form Framework

1. Configure the target site and enable **TYPO3 Form Framework** through a successful save.
2. Open the TYPO3 Form Editor.
3. Add the **Private Captcha** element to every form that must be protected.

The extension adds its validator and guard finisher automatically. Do not add either manually. The CAPTCHA value is excluded from summary pages and normal email-finisher output.

Form definitions can be shared between sites. Protection follows the current site's settings, so the same definition can be protected on one site and explicitly unprotected on another.

## Frontend Login

Install felogin if the project does not already provide it:

```bash
composer require typo3/cms-felogin
```

Enable **Frontend login** in the relevant site scope through a successful save. The extension protects TYPO3's native felogin username/password form and authentication service. Password recovery views and existing authenticated sessions are not challenged.

If the project overrides the felogin login template, render the provided markup before the submit control:

```html
{privateCaptchaMarkup -> f:format.raw()}
```

Authentication remains enforced when the markup is missing, so an incompatible custom template can lock users out of frontend login. Custom frontend authentication implementations are outside the supported rendering integration.

## Backend Login

Backend login uses the installation-wide settings section. Only TYPO3's native username/password provider has a supported widget integration. SSO, LDAP, and custom providers are incompatible unless they render and submit the same Private Captcha field: the authentication service can reject their login POST while protection is enabled even though no widget was rendered.

Both CAPTCHA verification and TYPO3 password verification must succeed. Existing backend sessions, TYPO3 CLI commands, password reset, logout, and sudo-mode password checks are not gated by CAPTCHA.

Set up and test the [recovery commands](Recovery.md) before enabling backend protection.

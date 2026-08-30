# Configuration

Open **Site > Private Captcha** as a TYPO3 administrator.

## Scopes

| Scope | Storage | Integrations |
|---|---|---|
| Site | Site configuration under `privateCaptcha` | Form Framework, Powermail, frontend login |
| Backend | Extension configuration under `private_captcha.backend` | Backend login |

## Settings

| Setting | Default | Notes |
|---|---|---|
| API key | Empty | Secret, maximum 4096 bytes; never rendered back |
| Sitekey | Empty | Public widget and verification identifier |
| Theme | `light` | `light` or `dark` |
| Language | `auto` | `auto`, `en`, `de`, `es`, `fr`, `it`, `nl`, `sv`, `no`, `pl`, `fi`, `et`, `uk`, or `tr` |
| Start mode | `auto` | `auto` or `click` |
| EU isolation | Off | Uses the EU API; the widget still uses the global CDN |
| Custom root domain | Empty | Overrides EU isolation; see [Custom Domains](Security.md#custom-domains) |
| Debug mode | Off | Enables widget diagnostics only |
| Custom widget styles | Empty | `data-styles`, maximum 2048 bytes; CSS is not validated |
| Integrations | Off | Opt-in per scope |

A blank API-key field retains the stored key. **Reset** clears the scope.

## Save and Test

| Action | Behavior |
|---|---|
| **Save** | Normalizes and tests settings. Success enables requested integrations. Failure saves settings but disables every integration in the scope. Empty credentials remain disabled. |
| **Test connection** | Tests candidate settings and stores test metadata without saving other values or changing enablement. |
| **Reset** | Clears settings, credentials, test metadata, and integration flags. Environment keys remain effective. |

Tests use the provider's test property, one attempt, and five-second timeouts. Success verifies the API key and endpoint, not the production sitekey, origin authorization, or widget CDN.

Direct configuration changes bypass the save-time activation test.

## Environment API Keys

Use environment-backed secrets instead of committed site or extension configuration:

```text
PRIVATE_CAPTCHA_SITE_API_KEYS={"site-identifier":"site-api-key"}
PRIVATE_CAPTCHA_BACKEND_API_KEY=backend-api-key
```

`PRIVATE_CAPTCHA_SITE_API_KEYS` accepts an exact site-identifier map, up to 65,536 bytes and 1,024 entries. Environment keys override persisted keys, are not written back, and do not replace the required sitekey or other settings.

Changing a custom root domain requires a submitted API key and is blocked while an environment API-key override is active.

## Form Framework

Enable **TYPO3 Form Framework** for the site, then add **Private Captcha** to each protected form. The validator and guard finisher are automatic; do not add them manually. CAPTCHA values are omitted from summaries and normal email output.

Shared form definitions use the current site's settings and may be protected on one site but not another.

## Frontend Login

```bash
composer require typo3/cms-felogin
```

Enable **Frontend login** for the site. Password recovery and existing authenticated sessions are not challenged.

Custom felogin templates must render this before the submit control:

```html
{privateCaptchaMarkup -> f:format.raw()}
```

Authentication still requires CAPTCHA when markup is missing, which can lock users out. Custom authentication implementations are unsupported.

## Backend Login

Backend protection supports only TYPO3's native username/password provider. SSO, LDAP, and custom providers may be rejected unless they submit the same Private Captcha field.

CAPTCHA and password verification must both pass. Existing sessions, CLI commands, password reset, logout, and sudo-mode checks are excluded. Prepare the [recovery procedure](Recovery.md) first.

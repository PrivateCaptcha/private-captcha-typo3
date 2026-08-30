# Private Captcha for TYPO3

Composer extension for adding [Private Captcha](https://privatecaptcha.com/) to TYPO3 Form Framework forms, Powermail forms, frontend login, and the native backend login.

Runtime verification fails closed when an enabled integration cannot verify a CAPTCHA. Integrations are disabled by default. The settings module enables requested integrations only after a successful save-time connection test; direct configuration changes bypass that activation guard.

## Requirements

| Component | Supported versions |
|---|---|
| PHP | `^8.2` with `ext-intl` |
| TYPO3 | `^13.4 || ^14.0` |
| TYPO3 Form Framework | `^13.4 || ^14.0`, installed as a direct dependency |
| TYPO3 felogin | `^13.4 || ^14.0`, optional and required for frontend-login protection |
| Powermail | `>=13.2,<14.0`, optional and supported only on TYPO3 13.4 |

Powermail is not available on TYPO3 14 until a compatible Powermail release exists.

## Installation

Install the extension from Packagist in the root of a Composer-based TYPO3 project:

```bash
composer require private-captcha/typo3
```

Apply TYPO3's database schema update after installation. The extension adds the `tx_privatecaptcha_formproof` table used for short-lived, single-use Form Framework and Powermail proofs. Use the database analyzer in **Admin Tools > Maintenance** or the equivalent schema-update step in your deployment workflow. See TYPO3's [database compare documentation](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Database/DatabaseUpgrade/Index.html).

## Initial Configuration

1. Sign in as a TYPO3 administrator and open **Site > Private Captcha**.
2. Select a site, enter its API key and sitekey, choose integrations, and use **Save**.
3. Add the **Private Captcha** element to each Form Framework form that must be protected.
4. Install `typo3/cms-felogin` before enabling frontend-login protection.
5. Follow the separate [Powermail guide](Documentation/Powermail.md) before enabling Powermail.

Backend-login protection has a separate installation-wide configuration. Establish command-line and environment recovery access before enabling it; see [Backend Recovery](Documentation/Recovery.md).

## Documentation

- [Configuration](Documentation/Configuration.md): settings, connection tests, environment overrides, and integrations.
- [Security](Documentation/Security.md): fail-closed behavior, CSP, credential handling, and custom-domain trust.
- [Backend Recovery and Troubleshooting](Documentation/Recovery.md): status and disable commands, lockout recovery, and diagnostics.
- [Powermail](Documentation/Powermail.md): supported versions, setup, confirmation handling, and limitations.

## License

MIT

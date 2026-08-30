# Private Captcha for TYPO3

[Private Captcha](https://privatecaptcha.com/) integration for TYPO3 Form Framework, Powermail, frontend login, and backend login.

Integrations are opt-in and fail closed when enabled but unable to verify. The settings module enables integrations only after a successful save-time connection test; direct configuration bypasses this guard.

## Requirements

| Component | Version |
|---|---|
| PHP | `^8.2` with `ext-intl` |
| TYPO3 | `^13.4 || ^14.0` |
| felogin | `^13.4 || ^14.0`, optional |
| Powermail | `>=13.2,<14.0`, optional and TYPO3 13 only |

## Install

```bash
composer require private-captcha/typo3
```

Run TYPO3's database schema update. It creates `tx_privatecaptcha_formproof` for short-lived Form Framework and Powermail proofs.

## Configure

1. Open **Site > Private Captcha**, select a site, configure credentials and integrations, then save.
2. Add the **Private Captcha** element to protected Form Framework forms.
3. For frontend login, install `typo3/cms-felogin` first.
4. For Powermail, follow the [Powermail guide](Documentation/Powermail.md).

Backend login uses separate installation-wide settings. Prepare [out-of-band recovery](Documentation/Recovery.md) before enabling it.

## Documentation

- [Configuration](Documentation/Configuration.md)
- [Security](Documentation/Security.md)
- [Recovery and troubleshooting](Documentation/Recovery.md)
- [Powermail](Documentation/Powermail.md)

## License

MIT

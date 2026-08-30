# Private Captcha for TYPO3

[![CI](https://github.com/PrivateCaptcha/private-captcha-typo3/actions/workflows/ci.yml/badge.svg)](https://github.com/PrivateCaptcha/private-captcha-typo3/actions)
[![Packagist Version](https://img.shields.io/packagist/v/private-captcha/typo3)](https://packagist.org/packages/private-captcha/typo3)

Composer extension for adding [Private Captcha](https://privatecaptcha.com/) to TYPO3 Form Framework forms, Powermail forms, frontend login, and the native backend login.

<mark>Please check the [official documentation](https://docs.privatecaptcha.com/docs/integrations/typo3/) for the in-depth and up-to-date information.</mark>

## Quick Start

1. Install the extension from Packagist:
    ```bash
    composer require private-captcha/typo3
    ```
2. Sign in as a TYPO3 administrator and in **Site > Private Captcha** configure API key and sitekey
3. Choose required integrations and **Save**

## Requirements

- PHP (`^8.2` with `ext-intl`)
- TYPO3 (`^13.4`)
- TYPO3 Form Framework
- TYPO3 felogin
- Powermail (`>=13.2,<14.0`, optional and supported only on TYPO3 13.4)

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Support

For issues with this TYPO3 extension, please open an issue on GitHub.

For Private Captcha service questions, visit [privatecaptcha.com](https://privatecaptcha.com).

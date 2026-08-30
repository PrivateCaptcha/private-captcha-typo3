<?php

declare(strict_types=1);

use PrivateCaptcha\Typo3\Compatibility\PowermailCompatibility;
use PrivateCaptcha\Typo3\Powermail\PowermailRegistration;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

defined('TYPO3') or die();

$packageManager = GeneralUtility::makeInstance(PackageManager::class);
if (!PowermailCompatibility::isAvailable(new Typo3Version(), $packageManager)) {
    return;
}

$table = 'tx_powermail_domain_model_field';
$fieldTca = $GLOBALS['TCA'][$table] ?? null;
$captchaType = is_array($fieldTca) ? ($fieldTca['types']['captcha'] ?? null) : null;
if (!is_array($captchaType)) {
    return;
}

$captchaType['showitem'] = 'private_captcha_warning, ' . (string)($captchaType['showitem'] ?? '');
$GLOBALS['TCA'][$table]['types']['privateCaptcha'] = $captchaType;
$GLOBALS['TCA'][$table]['columns']['private_captcha_warning'] = [
    'label' => 'LLL:EXT:private_captcha/Resources/Private/Language/locallang.xlf:powermail.warning.label',
    'description' => 'LLL:EXT:private_captcha/Resources/Private/Language/locallang.xlf:powermail.warning.description',
    'displayCond' => 'USER:' . PowermailRegistration::class . '->shouldDisplayUnprotectedWarning',
    'config' => [
        'type' => 'none',
    ],
];

<?php

declare(strict_types=1);

use PrivateCaptcha\Typo3\Authentication\BackendCaptchaAuthenticationService;
use PrivateCaptcha\Typo3\Authentication\FrontendCaptchaAuthenticationService;
use PrivateCaptcha\Typo3\Compatibility\PowermailCompatibility;
use PrivateCaptcha\Typo3\Form\FormRuntimeAdapter;
use PrivateCaptcha\Typo3\LoginProvider\PrivateCaptchaLoginProvider;
use TYPO3\CMS\Backend\LoginProvider\UsernamePasswordLoginProvider;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

defined('TYPO3') or die();

(static function (): void {
    $typo3Version = new Typo3Version();
    $version = $typo3Version->getVersion();

    $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
    if (!is_array($typo3Configuration)) {
        $typo3Configuration = [];
    }
    $backendSystemConfiguration = $typo3Configuration['BE'] ?? [];
    if (!is_array($backendSystemConfiguration)) {
        $backendSystemConfiguration = [];
    }
    $backendSystemConfiguration['showRefreshLoginPopup'] = true;
    $typo3Configuration['BE'] = $backendSystemConfiguration;
    $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;

    $extensionConfiguration = $typo3Configuration['EXTCONF'] ?? [];
    if (!is_array($extensionConfiguration)) {
        $extensionConfiguration = [];
    }
    $backendConfiguration = $extensionConfiguration['backend'] ?? [];
    if (!is_array($backendConfiguration)) {
        $backendConfiguration = [];
    }
    $loginProviders = $backendConfiguration['loginProviders'] ?? [];
    if (!is_array($loginProviders)) {
        $loginProviders = [];
    }
    $nativeProvider = $loginProviders[1433416747] ?? null;
    if (is_array($nativeProvider)
        && ($nativeProvider['provider'] ?? null) === UsernamePasswordLoginProvider::class
    ) {
        $nativeProvider['provider'] = PrivateCaptchaLoginProvider::class;
        $loginProviders[1433416747] = $nativeProvider;
        $backendConfiguration['loginProviders'] = $loginProviders;
        $extensionConfiguration['backend'] = $backendConfiguration;
        $typo3Configuration['EXTCONF'] = $extensionConfiguration;
        $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;
    }

    ExtensionManagementUtility::addService(
        'private_captcha',
        'auth',
        BackendCaptchaAuthenticationService::class,
        [
            'title' => 'Private Captcha backend login verification',
            'description' => 'Requires CAPTCHA verification before backend password authentication.',
            'subtype' => 'authUserBE',
            'available' => true,
            'priority' => 100,
            'quality' => 100,
            'os' => '',
            'exec' => '',
            'className' => BackendCaptchaAuthenticationService::class,
        ],
    );

    ExtensionManagementUtility::addService(
        'private_captcha',
        'auth',
        FrontendCaptchaAuthenticationService::class,
        [
            'title' => 'Private Captcha frontend login verification',
            'description' => 'Requires CAPTCHA verification before frontend password authentication.',
            'subtype' => 'authUserFE',
            'available' => true,
            'priority' => 100,
            'quality' => 100,
            'os' => '',
            'exec' => '',
            'className' => FrontendCaptchaAuthenticationService::class,
        ],
    );

    if (version_compare($version, '14.2.0', '<')) {
        ExtensionManagementUtility::addTypoScriptSetup('
            plugin.tx_form.settings.yamlConfigurations {
                1774639825 = EXT:private_captcha/Configuration/Form/PrivateCaptcha/config.yaml
                1774639826 = EXT:private_captcha/Configuration/Form/PrivateCaptcha/LegacyEditor.yaml
            }
            module.tx_form.settings.yamlConfigurations {
                1774639825 = EXT:private_captcha/Configuration/Form/PrivateCaptcha/config.yaml
                1774639826 = EXT:private_captcha/Configuration/Form/PrivateCaptcha/LegacyEditor.yaml
            }
        ');
    }

    ExtensionManagementUtility::addTypoScriptSetup(
        'plugin.tx_felogin_login.view.templateRootPaths.20 = EXT:private_captcha/Resources/Private/Templates/FrontendLogin/',
    );

    if (PowermailCompatibility::isAvailable(
        $typo3Version,
        GeneralUtility::makeInstance(PackageManager::class),
    )) {
        ExtensionManagementUtility::addTypoScriptSetup(
            "@import 'EXT:private_captcha/Configuration/TypoScript/Powermail/setup.typoscript'",
        );
    }

    if (version_compare($version, '14.0.0', '<')) {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3Configuration)) {
            $typo3Configuration = [];
        }
        $scOptions = $typo3Configuration['SC_OPTIONS'] ?? [];
        if (!is_array($scOptions)) {
            $scOptions = [];
        }
        $formOptions = $scOptions['ext/form'] ?? [];
        if (!is_array($formOptions)) {
            $formOptions = [];
        }
        foreach (['afterBuildingFinished', 'afterSubmit', 'beforeRendering', 'afterInitializeCurrentPage'] as $hook) {
            $hookConfiguration = $formOptions[$hook] ?? [];
            if (!is_array($hookConfiguration)) {
                $hookConfiguration = [];
            }
            $hookConfiguration['private-captcha'] = FormRuntimeAdapter::class;
            $formOptions[$hook] = $hookConfiguration;
        }
        $scOptions['ext/form'] = $formOptions;
        $typo3Configuration['SC_OPTIONS'] = $scOptions;
        $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;
    }
})();

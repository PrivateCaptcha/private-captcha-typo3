<?php

declare(strict_types=1);

use PrivateCaptcha\Typo3\Controller\SettingsController;

return [
    'private_captcha_settings' => [
        'parent' => 'site',
        'access' => 'admin',
        'path' => '/module/site/private-captcha',
        'iconIdentifier' => 'module-security',
        'labels' => 'LLL:EXT:private_captcha/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => SettingsController::class . '::indexAction',
            ],
            'site' => [
                'target' => SettingsController::class . '::siteAction',
            ],
            'backend' => [
                'target' => SettingsController::class . '::backendAction',
            ],
        ],
    ],
];

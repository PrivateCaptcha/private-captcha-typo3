<?php

declare(strict_types=1);

use PrivateCaptcha\Typo3\Compatibility\PowermailCompatibility;
use PrivateCaptcha\Typo3\Middleware\SettingsTraceRedactionMiddleware;
use PrivateCaptcha\Typo3\Powermail\PowermailSubmissionSanitizerMiddleware;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

$middlewares = [
    'backend' => [
        'private-captcha/settings-trace-redaction' => [
            'target' => SettingsTraceRedactionMiddleware::class,
            'after' => [
                'typo3/cms-backend/backend-routing',
            ],
            'before' => [
                'typo3/cms-core/request-token-middleware',
                'typo3/cms-backend/authentication',
            ],
        ],
    ],
];

if (PowermailCompatibility::isComposerPackageAvailable(new Typo3Version())
    && ExtensionManagementUtility::isLoaded('powermail')
) {
    $middlewares['frontend']['private-captcha/powermail-solution-sanitizer'] = [
        'target' => PowermailSubmissionSanitizerMiddleware::class,
        'after' => [
            'typo3/cms-frontend/site',
        ],
        'before' => [
            'typo3/cms-core/request-token-middleware',
            'typo3/cms-frontend/authentication',
        ],
    ];
}

return $middlewares;

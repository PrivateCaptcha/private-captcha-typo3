<?php

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 'Private Captcha',
    'description' => 'Private Captcha integration for TYPO3 forms and login',
    'category' => 'services',
    'author' => 'PrivateCaptcha',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'backend' => '13.4.0-14.99.99',
            'form' => '13.4.0-14.99.99',
            'frontend' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'felogin' => '13.4.0-14.99.99',
            'powermail' => '13.2.0-13.99.99',
        ],
    ],
];

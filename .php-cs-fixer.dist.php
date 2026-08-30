<?php

declare(strict_types=1);

$config = TYPO3\CodingStandards\CsFixerConfig::create();
$config->setCacheFile(__DIR__ . '/.Build/php-cs-fixer.cache');
$config->getFinder()
    ->exclude('.Build')
    ->exclude('var')
    ->in(__DIR__);

return $config;

<?php

declare(strict_types=1);

use TYPO3\TestingFramework\Core\Testbase;

(static function (): void {
    $testbase = new Testbase();
    $testbase->defineOriginalRootPath();
    $originalRoot = constant('ORIGINAL_ROOT');
    if (!is_string($originalRoot)) {
        throw new RuntimeException('TYPO3 Testing Framework did not define a valid original root path.');
    }
    $testbase->createDirectory($originalRoot . 'typo3temp/var/tests');
    $testbase->createDirectory($originalRoot . 'typo3temp/var/transient');
})();

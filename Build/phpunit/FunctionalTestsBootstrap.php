<?php

declare(strict_types=1);

/*
 * Functional test bootstrap, adapted from typo3/testing-framework's boilerplate.
 * Referenced by Build/phpunit/FunctionalTests.xml; run phpunit from the project root.
 */

use TYPO3\TestingFramework\Core\Testbase;

(static function (): void {
    $testbase = new Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
})();

<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Compatibility;

use Composer\InstalledVersions;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * @internal
 */
final class PowermailCompatibility
{
    public static function isAvailable(Typo3Version $typo3Version, PackageManager $packageManager): bool
    {
        return self::isComposerPackageAvailable($typo3Version)
            && $packageManager->isPackageActive('powermail');
    }

    public static function isComposerPackageAvailable(Typo3Version $typo3Version): bool
    {
        if ($typo3Version->getMajorVersion() !== 13 || !InstalledVersions::isInstalled('in2code/powermail')) {
            return false;
        }
        $version = InstalledVersions::getVersion('in2code/powermail');

        return is_string($version)
            && version_compare($version, '13.2.0', '>=')
            && version_compare($version, '14.0.0', '<');
    }
}

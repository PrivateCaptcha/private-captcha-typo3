<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Service;

use PrivateCaptcha\Typo3\ValueObject\EndpointConfiguration;

/**
 * @internal
 */
interface TestPuzzleClientInterface
{
    public function fetch(
        EndpointConfiguration $endpoints,
        string $sitekey,
    ): string;
}

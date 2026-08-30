<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Service;

use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;

/**
 * @internal
 */
interface PrivateCaptchaClientFactoryInterface
{
    public function create(
        #[\SensitiveParameter]
        ResolvedCaptchaConfiguration $configuration,
    ): Client;
}

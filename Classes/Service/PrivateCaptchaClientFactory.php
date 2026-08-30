<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Service;

use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;

/**
 * @internal
 */
final readonly class PrivateCaptchaClientFactory implements PrivateCaptchaClientFactoryInterface
{
    private const TIMEOUT_SECONDS = 5;

    public function create(
        #[\SensitiveParameter]
        ResolvedCaptchaConfiguration $configuration,
    ): Client {
        return new Client(
            apiKey: $configuration->apiKey(),
            domain: $configuration->endpoints->apiDomainOverride,
            timeout: self::TIMEOUT_SECONDS,
        );
    }
}

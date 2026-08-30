<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\ValueObject;

/**
 * @internal
 */
final readonly class EndpointConfiguration
{
    public function __construct(
        public ?string $apiDomainOverride,
        public ?string $puzzleEndpointOverride,
        public string $cdnBaseUrl,
        public bool $euIsolation,
    ) {}
}

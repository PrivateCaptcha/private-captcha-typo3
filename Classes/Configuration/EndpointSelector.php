<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Configuration;

use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\ValueObject\EndpointConfiguration;

/**
 * @internal
 */
final readonly class EndpointSelector
{
    private const OFFICIAL_CDN_BASE_URL = 'https://cdn.privatecaptcha.com';

    public function __construct(
        private CustomDomainValidator $customDomainValidator,
    ) {}

    public function select(bool $euIsolation, string $customRootDomain): EndpointConfiguration
    {
        $customRootDomain = $this->customDomainValidator->validate($customRootDomain);
        if ($customRootDomain !== '') {
            $apiDomain = 'api.' . $customRootDomain;

            return new EndpointConfiguration(
                apiDomainOverride: $apiDomain,
                puzzleEndpointOverride: 'https://' . $apiDomain . '/puzzle',
                cdnBaseUrl: 'https://cdn.' . $customRootDomain,
                euIsolation: false,
            );
        }

        return new EndpointConfiguration(
            apiDomainOverride: $euIsolation ? Client::EU_DOMAIN : null,
            puzzleEndpointOverride: null,
            cdnBaseUrl: self::OFFICIAL_CDN_BASE_URL,
            euIsolation: $euIsolation,
        );
    }
}

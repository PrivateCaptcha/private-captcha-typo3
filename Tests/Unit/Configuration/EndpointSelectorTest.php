<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\Configuration\ConfigurationNormalizer;
use PrivateCaptcha\Typo3\Configuration\CustomDomainValidator;
use PrivateCaptcha\Typo3\Configuration\EndpointSelector;

final class EndpointSelectorTest extends TestCase
{
    #[Test]
    public function selectsOfficialGlobalEndpointDefaults(): void
    {
        $endpoints = $this->selector()->select(euIsolation: false, customRootDomain: '');

        self::assertNull($endpoints->apiDomainOverride);
        self::assertNull($endpoints->puzzleEndpointOverride);
        self::assertSame('https://cdn.privatecaptcha.com', $endpoints->cdnBaseUrl);
        self::assertFalse($endpoints->euIsolation);
    }

    #[Test]
    public function selectsOfficialEuEndpointsWithoutReplacingTheGlobalCdn(): void
    {
        $endpoints = $this->selector()->select(euIsolation: true, customRootDomain: '');

        self::assertSame(Client::EU_DOMAIN, $endpoints->apiDomainOverride);
        self::assertNull($endpoints->puzzleEndpointOverride);
        self::assertSame('https://cdn.privatecaptcha.com', $endpoints->cdnBaseUrl);
        self::assertTrue($endpoints->euIsolation);
    }

    #[Test]
    public function selectsValidatedCustomEndpointsAheadOfEuIsolation(): void
    {
        $endpoints = $this->selector()->select(
            euIsolation: true,
            customRootDomain: '  Custom.PrivateCaptcha.COM  ',
        );

        self::assertSame('api.custom.privatecaptcha.com', $endpoints->apiDomainOverride);
        self::assertSame('https://api.custom.privatecaptcha.com/puzzle', $endpoints->puzzleEndpointOverride);
        self::assertSame('https://cdn.custom.privatecaptcha.com', $endpoints->cdnBaseUrl);
        self::assertFalse($endpoints->euIsolation);
    }

    #[Test]
    public function derivesMaximumLengthCustomEndpointHosts(): void
    {
        $root = implode('.', [
            str_repeat('a', 63),
            str_repeat('b', 63),
            str_repeat('c', 63),
            str_repeat('d', 53),
            'com',
        ]);

        $endpoints = $this->selector()->select(euIsolation: false, customRootDomain: $root);

        self::assertSame(253, strlen((string)$endpoints->apiDomainOverride));
        self::assertSame(253, strlen((string)parse_url($endpoints->cdnBaseUrl, PHP_URL_HOST)));
    }

    #[Test]
    public function rejectsEndpointControlsAfterFormNormalization(): void
    {
        $configuration = (new ConfigurationNormalizer())->normalize([
            'customRootDomain' => "captcha.privatecaptcha.com\n",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->selector()->select(
            euIsolation: $configuration->euIsolation,
            customRootDomain: $configuration->customRootDomain,
        );
    }

    private function selector(): EndpointSelector
    {
        return new EndpointSelector(new CustomDomainValidator());
    }
}

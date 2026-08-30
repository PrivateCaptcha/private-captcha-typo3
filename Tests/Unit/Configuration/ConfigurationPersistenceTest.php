<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\Typo3\Configuration\BackendConfigurationRepository;
use PrivateCaptcha\Typo3\Configuration\SiteConfigurationRepository;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Site\Entity\Site;

final class ConfigurationPersistenceTest extends TestCase
{
    #[Test]
    public function siteWriterFailuresDoNotExposeApiKeysInExceptionTraces(): void
    {
        $apiKey = bin2hex(random_bytes(16));
        $siteConfiguration = $this->createMock(SiteConfiguration::class);
        $siteConfiguration->method('load')->willReturn([
            'rootPageId' => 1,
            'base' => 'https://site-a.test/',
        ]);
        $siteWriter = $this->createMock(SiteWriter::class);
        $siteWriter->method('write')->willReturnCallback(
            static function (string $identifier, array $configuration, bool $protectPlaceholders): never {
                throw new \RuntimeException('unsafe site writer failure');
            },
        );
        $repository = new SiteConfigurationRepository($siteConfiguration, $siteWriter);

        $this->assertSanitizedPersistenceFailure(
            static fn() => $repository->save(
                new Site('site-a', 1, ['base' => 'https://site-a.test/']),
                ['apiKey' => $apiKey],
            ),
            $apiKey,
        );
    }

    #[Test]
    public function backendWriterFailuresDoNotExposeApiKeysInExceptionTraces(): void
    {
        $apiKey = bin2hex(random_bytes(16));
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->method('getLocalConfiguration')->willReturn([]);
        $configurationManager->method('setLocalConfigurationValueByPath')->willReturnCallback(
            static function (string $path, mixed $configuration): never {
                throw new \RuntimeException('unsafe backend writer failure');
            },
        );
        $repository = new BackendConfigurationRepository($extensionConfiguration, $configurationManager);

        $this->assertSanitizedPersistenceFailure(
            static fn() => $repository->save(['apiKey' => $apiKey]),
            $apiKey,
        );
    }

    #[Test]
    public function backendWriterFalseResultIsReportedAsPersistenceFailure(): void
    {
        $apiKey = bin2hex(random_bytes(16));
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->method('getLocalConfiguration')->willReturn([]);
        $configurationManager->method('setLocalConfigurationValueByPath')->willReturn(false);
        $repository = new BackendConfigurationRepository($extensionConfiguration, $configurationManager);

        $this->assertSanitizedPersistenceFailure(
            static fn() => $repository->save(['apiKey' => $apiKey]),
            $apiKey,
        );
    }

    private function assertSanitizedPersistenceFailure(
        #[\SensitiveParameter]
        \Closure $operation,
        #[\SensitiveParameter]
        string $apiKey,
    ): void {
        $previousIgnoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            $operation();
            self::fail('Persistence failures must be reported.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unable to persist Private Captcha configuration.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            $applicationTrace = array_filter(
                $exception->getTrace(),
                static fn(array $frame): bool => str_contains((string)($frame['file'] ?? ''), '/Classes/'),
            );
            self::assertStringNotContainsString($apiKey, print_r($applicationTrace, true));
        } finally {
            if (is_string($previousIgnoreArgs)) {
                ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
            }
        }
    }
}

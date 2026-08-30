<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ConfigurationResolverTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'form',
    ];

    protected array $testExtensionsToLoad = [
        'private-captcha/typo3',
    ];

    protected bool $initializeDatabase = false;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    private bool $hadExtensionConfiguration = false;

    private mixed $originalExtensionConfiguration = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->environmentVariableNames() as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
        }

        $extensionConfigurations = $this->extensionConfigurations();
        $this->hadExtensionConfiguration = array_key_exists('private_captcha', $extensionConfigurations);
        $this->originalExtensionConfiguration = $extensionConfigurations['private_captcha'] ?? null;
        $this->removePrivateCaptchaExtensionConfiguration();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }

        if ($this->hadExtensionConfiguration) {
            $this->setPrivateCaptchaExtensionConfiguration($this->originalExtensionConfiguration);
        } else {
            $this->removePrivateCaptchaExtensionConfiguration();
        }

        parent::tearDown();
    }

    #[Test]
    public function resolvesIndependentTypedConfigurationForEachSite(): void
    {
        $siteAKey = bin2hex(random_bytes(16));
        $siteBKey = bin2hex(random_bytes(16));
        $siteA = $this->site('site-a', [
            'apiKey' => $siteAKey,
            'sitekey' => 'site-a-property',
            'theme' => 'dark',
            'language' => 'de',
            'startMode' => 'click',
            'debug' => '1',
            'customStyles' => '--border-radius: 1rem;',
            'formFrameworkEnabled' => '1',
            'powermailEnabled' => '1',
            'frontendLoginEnabled' => '1',
            'backendLoginEnabled' => '1',
            'euIsolation' => '1',
        ]);
        $siteB = $this->site('site-b', [
            'apiKey' => $siteBKey,
            'sitekey' => 'site-b-property',
            'customRootDomain' => 'custom.privatecaptcha.com',
            'formFrameworkEnabled' => '1',
        ]);

        $resolver = $this->resolver();
        $configurationA = $resolver->resolveSite($siteA);
        $configurationB = $resolver->resolveSite($siteB);

        self::assertSame($siteAKey, $configurationA->apiKey());
        self::assertSame('site-a-property', $configurationA->sitekey);
        self::assertSame('dark', $configurationA->widget->theme);
        self::assertTrue($configurationA->integrations->formFramework);
        self::assertTrue($configurationA->integrations->powermail);
        self::assertTrue($configurationA->integrations->frontendLogin);
        self::assertFalse($configurationA->integrations->backendLogin);
        self::assertSame(Client::EU_DOMAIN, $configurationA->endpoints->apiDomainOverride);
        self::assertSame($siteBKey, $configurationB->apiKey());
        self::assertSame('site-b-property', $configurationB->sitekey);
        self::assertTrue($configurationB->integrations->formFramework);
        self::assertFalse($configurationB->integrations->powermail);
        self::assertSame('api.custom.privatecaptcha.com', $configurationB->endpoints->apiDomainOverride);
    }

    #[Test]
    public function resolvesBackendConfigurationWithoutSiteContextOrSiteIntegrations(): void
    {
        $backendKey = bin2hex(random_bytes(16));
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => [
            'apiKey' => $backendKey,
            'sitekey' => 'backend-property',
            'formFrameworkEnabled' => '1',
            'powermailEnabled' => '1',
            'frontendLoginEnabled' => '1',
            'backendLoginEnabled' => '1',
        ]]);

        $configuration = $this->resolver()->resolveBackend();

        self::assertSame($backendKey, $configuration->apiKey());
        self::assertSame('backend-property', $configuration->sitekey);
        self::assertFalse($configuration->integrations->formFramework);
        self::assertFalse($configuration->integrations->powermail);
        self::assertFalse($configuration->integrations->frontendLogin);
        self::assertTrue($configuration->integrations->backendLogin);
    }

    #[Test]
    public function resolvesAbsentBackendConfigurationAsDisabledDefaults(): void
    {
        $configuration = $this->resolver()->resolveBackend();

        self::assertSame('', $configuration->apiKey());
        self::assertFalse($configuration->integrations->formFramework);
        self::assertFalse($configuration->integrations->powermail);
        self::assertFalse($configuration->integrations->frontendLogin);
        self::assertFalse($configuration->integrations->backendLogin);
    }

    #[Test]
    public function environmentApiKeysOverridePersistenceWithoutAppearingInSerializedConfiguration(): void
    {
        $persistedSiteKey = bin2hex(random_bytes(16));
        $environmentSiteKey = bin2hex(random_bytes(16));
        $persistedBackendKey = bin2hex(random_bytes(16));
        $environmentBackendKey = bin2hex(random_bytes(16));
        putenv(ConfigurationResolver::SITE_API_KEYS_ENV . '=' . json_encode([
            'site-a' => $environmentSiteKey,
        ], JSON_THROW_ON_ERROR));
        putenv(ConfigurationResolver::BACKEND_API_KEY_ENV . '=' . $environmentBackendKey);
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => [
            'apiKey' => $persistedBackendKey,
        ]]);

        $siteConfiguration = $this->resolver()->resolveSite($this->site('site-a', [
            'apiKey' => $persistedSiteKey,
        ]));
        $siteBKey = bin2hex(random_bytes(16));
        $siteBConfiguration = $this->resolver()->resolveSite($this->site('site-b', [
            'apiKey' => $siteBKey,
        ]));
        $backendConfiguration = $this->resolver()->resolveBackend();

        self::assertSame($environmentSiteKey, $siteConfiguration->apiKey());
        self::assertSame($siteBKey, $siteBConfiguration->apiKey());
        self::assertSame($environmentBackendKey, $backendConfiguration->apiKey());
        $configurations = [$siteConfiguration, $siteBConfiguration, $backendConfiguration];
        $json = json_encode($configurations, JSON_THROW_ON_ERROR);
        $debugOutput = print_r($configurations, true);
        $exported = var_export($configurations, true);
        foreach ([$persistedSiteKey, $environmentSiteKey, $siteBKey, $persistedBackendKey, $environmentBackendKey] as $key) {
            self::assertStringNotContainsString($key, $json);
            self::assertStringNotContainsString($key, $debugOutput);
            self::assertStringNotContainsString($key, $exported);
        }
        foreach ($configurations as $configuration) {
            try {
                serialize($configuration);
                self::fail('Resolved configuration must not support native serialization.');
            } catch (\Throwable $exception) {
                self::assertStringNotContainsString($configuration->apiKey(), $exception->getMessage());
            }
        }
    }

    #[Test]
    #[DataProvider('emergencyDisableValueProvider')]
    public function emergencyEnvironmentSwitchOverridesEnabledBackendConfiguration(string $emergencyValue): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => [
            'apiKey' => [],
            'backendLoginEnabled' => '1',
            'customRootDomain' => 'https://invalid.example',
        ]]);
        putenv(ConfigurationResolver::BACKEND_API_KEY_ENV . "=invalid\nkey");
        putenv(ConfigurationResolver::DISABLE_BACKEND_LOGIN_ENV . '=' . $emergencyValue);

        $configuration = $this->resolver()->resolveBackend();

        self::assertFalse($configuration->integrations->backendLogin);
    }

    #[Test]
    public function emergencyEnvironmentSwitchIsEvaluatedBeforePersistedBackendConfiguration(): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => 'malformed']);
        putenv(ConfigurationResolver::DISABLE_BACKEND_LOGIN_ENV . '=true');

        $configuration = $this->resolver()->resolveBackend();

        self::assertFalse($configuration->requestedIntegrations->backendLogin);
        self::assertFalse($configuration->integrations->backendLogin);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emergencyDisableValueProvider(): iterable
    {
        yield 'documented value' => ['true'];
        yield 'unknown nonempty value fails safe' => ['treu'];
    }

    #[Test]
    #[DataProvider('emergencyEnabledValueProvider')]
    public function recognizedFalseEmergencyValuesKeepConfiguredBackendProtection(string $emergencyValue): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => [
            'apiKey' => bin2hex(random_bytes(16)),
            'sitekey' => 'backend-property',
            'backendLoginEnabled' => true,
        ]]);
        putenv(ConfigurationResolver::DISABLE_BACKEND_LOGIN_ENV . '=' . $emergencyValue);

        self::assertTrue($this->resolver()->resolveBackend()->integrations->backendLogin);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emergencyEnabledValueProvider(): iterable
    {
        foreach (['0', 'false', 'no', 'off'] as $value) {
            yield $value => [$value];
        }
    }

    #[Test]
    #[DataProvider('invalidEnvironmentMapProvider')]
    public function rejectsInvalidSiteEnvironmentMaps(string $encodedOverrides): void
    {
        putenv(ConfigurationResolver::SITE_API_KEYS_ENV . '=' . $encodedOverrides);

        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->resolveSite($this->site('site-a', []));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEnvironmentMapProvider(): iterable
    {
        yield 'JSON list' => ['["value"]'];
        yield 'JSON scalar' => ['true'];
    }

    #[Test]
    #[DataProvider('invalidSelectedEnvironmentOverrideProvider')]
    public function rejectsInvalidSelectedSiteEnvironmentOverride(mixed $override): void
    {
        putenv(ConfigurationResolver::SITE_API_KEYS_ENV . '=' . json_encode([
            'site-a' => $override,
        ], JSON_THROW_ON_ERROR));

        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->resolveSite($this->site('site-a', []));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidSelectedEnvironmentOverrideProvider(): iterable
    {
        yield 'non-string' => [123];
        yield 'control character' => ["invalid\nvalue"];
        yield 'oversized API key' => [str_repeat('a', 4097)];
    }

    #[Test]
    public function malformedUnselectedEnvironmentOverrideDoesNotBreakAnotherSite(): void
    {
        $siteAKey = bin2hex(random_bytes(16));
        putenv(ConfigurationResolver::SITE_API_KEYS_ENV . '=' . json_encode([
            'site-b' => 123,
        ], JSON_THROW_ON_ERROR));

        $configuration = $this->resolver()->resolveSite($this->site('site-a', [
            'apiKey' => $siteAKey,
        ]));

        self::assertSame($siteAKey, $configuration->apiKey());
    }

    #[Test]
    public function rejectsOversizedSiteEnvironmentMap(): void
    {
        putenv(ConfigurationResolver::SITE_API_KEYS_ENV . '=' . str_repeat('a', 65537));

        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->resolveSite($this->site('site-a', []));
    }

    #[Test]
    public function rejectsSiteEnvironmentMapWithTooManyEntries(): void
    {
        $overrides = [];
        for ($index = 0; $index <= 1024; ++$index) {
            $overrides['site-' . $index] = '';
        }
        putenv(ConfigurationResolver::SITE_API_KEYS_ENV . '=' . json_encode($overrides, JSON_THROW_ON_ERROR));

        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->resolveSite($this->site('site-a', []));
    }

    #[Test]
    public function rejectsMalformedSiteEnvironmentMapWithoutChainingSecretInput(): void
    {
        putenv(ConfigurationResolver::SITE_API_KEYS_ENV . '={');

        try {
            $this->resolver()->resolveSite($this->site('site-a', []));
            self::fail('Malformed environment JSON must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertNull($exception->getPrevious());
        }
    }

    #[Test]
    #[DataProvider('malformedScopeProvider')]
    public function rejectsMalformedPersistedSiteScope(mixed $scope): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->resolveSite($this->site('site-a', $scope));
    }

    #[Test]
    #[DataProvider('malformedScopeProvider')]
    public function rejectsMalformedPersistedBackendScope(mixed $scope): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => $scope]);
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->resolveBackend();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedScopeProvider(): iterable
    {
        yield 'string' => ['broken'];
        yield 'null' => [null];
    }

    #[Test]
    public function rejectsMalformedPersistedIntegrationValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->resolveSite($this->site('site-a', [
            'formFrameworkEnabled' => 'definitely',
        ]));
    }

    #[Test]
    public function rejectsMalformedSiteValuesEvenWhenIntegrationIsOutOfScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->resolveSite($this->site('site-a', [
            'backendLoginEnabled' => [],
        ]));
    }

    #[Test]
    public function rejectsMalformedBackendValuesEvenWhenIntegrationIsOutOfScope(): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => [
            'formFrameworkEnabled' => [],
        ]]);
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->resolveBackend();
    }

    #[Test]
    public function disablesSiteIntegrationsWhenEitherCredentialIsMissing(): void
    {
        $configuration = $this->resolver()->resolveSite($this->site('site-a', [
            'apiKey' => bin2hex(random_bytes(16)),
            'formFrameworkEnabled' => true,
        ]));

        self::assertFalse($configuration->integrations->formFramework);
        self::assertFalse($configuration->integrations->powermail);
        self::assertFalse($configuration->integrations->frontendLogin);
        self::assertFalse($configuration->integrations->backendLogin);
        self::assertTrue($configuration->requestedIntegrations->formFramework);
    }

    #[Test]
    public function disablesBackendIntegrationWhenEitherCredentialIsMissing(): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => [
            'sitekey' => 'backend-property',
            'backendLoginEnabled' => true,
        ]]);

        $configuration = $this->resolver()->resolveBackend();

        self::assertFalse($configuration->integrations->backendLogin);
    }

    #[Test]
    public function redactsApiKeyFromResolutionExceptionTraces(): void
    {
        $apiKey = bin2hex(random_bytes(16));
        $previousIgnoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            $this->resolver()->resolveSite($this->site('site-a', [
                'apiKey' => $apiKey,
                'customRootDomain' => 'https://invalid.example',
            ]));
            self::fail('Invalid custom endpoint must fail resolution.');
        } catch (\InvalidArgumentException $exception) {
            $trace = $exception->getTrace();
            array_walk_recursive(
                $trace,
                static function (mixed $value) use ($apiKey): void {
                    if (is_string($value)) {
                        self::assertStringNotContainsString($apiKey, $value);
                    }
                },
            );
        } finally {
            if (is_string($previousIgnoreArgs)) {
                ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
            }
        }
    }

    private function resolver(): ConfigurationResolver
    {
        return $this->get(ConfigurationResolver::class);
    }

    private function site(string $identifier, mixed $privateCaptchaConfiguration): Site
    {
        return new Site($identifier, 1, [
            'base' => 'https://' . $identifier . '.test/',
            'privateCaptcha' => $privateCaptchaConfiguration,
        ]);
    }

    /**
     * @return list<string>
     */
    private function environmentVariableNames(): array
    {
        return [
            ConfigurationResolver::SITE_API_KEYS_ENV,
            ConfigurationResolver::BACKEND_API_KEY_ENV,
            ConfigurationResolver::DISABLE_BACKEND_LOGIN_ENV,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extensionConfigurations(): array
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3Configuration)) {
            return [];
        }
        $extensionConfigurations = $typo3Configuration['EXTENSIONS'] ?? [];
        if (!is_array($extensionConfigurations)) {
            return [];
        }

        /** @var array<string, mixed> $extensionConfigurations */
        return $extensionConfigurations;
    }

    private function setPrivateCaptchaExtensionConfiguration(mixed $configuration): void
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3Configuration)) {
            $typo3Configuration = [];
        }
        $extensionConfigurations = $this->extensionConfigurations();
        $extensionConfigurations['private_captcha'] = $configuration;
        $typo3Configuration['EXTENSIONS'] = $extensionConfigurations;
        $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;
    }

    private function removePrivateCaptchaExtensionConfiguration(): void
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3Configuration)) {
            return;
        }
        $extensionConfigurations = $this->extensionConfigurations();
        unset($extensionConfigurations['private_captcha']);
        $typo3Configuration['EXTENSIONS'] = $extensionConfigurations;
        $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;
    }
}

<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Client;
use PrivateCaptcha\Enums\VerifyCode;
use PrivateCaptcha\Models\VerifyOutput;
use PrivateCaptcha\Typo3\Configuration\BackendConfigurationRepository;
use PrivateCaptcha\Typo3\Configuration\ConfigurationNormalizer;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Configuration\CustomDomainValidator;
use PrivateCaptcha\Typo3\Configuration\SiteConfigurationRepository;
use PrivateCaptcha\Typo3\Service\ConnectionTester;
use PrivateCaptcha\Typo3\Service\PrivateCaptchaClientFactoryInterface;
use PrivateCaptcha\Typo3\Service\SettingsActivationService;
use PrivateCaptcha\Typo3\Service\TestPuzzleClientInterface;
use PrivateCaptcha\Typo3\ValueObject\EndpointConfiguration;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\SettingsSubmission;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SettingsActivationServiceTest extends FunctionalTestCase
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

        foreach ([
            ConfigurationResolver::SITE_API_KEYS_ENV,
            ConfigurationResolver::BACKEND_API_KEY_ENV,
            'ACTIVATION_API_KEY',
            'ACTIVATION_API_KEY_NEW',
        ] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
        }
        $extensionConfigurations = $this->extensionConfigurations();
        $this->hadExtensionConfiguration = array_key_exists('private_captcha', $extensionConfigurations);
        $this->originalExtensionConfiguration = $extensionConfigurations['private_captcha'] ?? null;
        $this->setPrivateCaptchaExtensionConfiguration([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        if ($this->hadExtensionConfiguration) {
            $this->setPrivateCaptchaExtensionConfiguration($this->originalExtensionConfiguration);
        } else {
            $extensionConfigurations = $this->extensionConfigurations();
            unset($extensionConfigurations['private_captcha']);
            $this->setExtensionConfigurations($extensionConfigurations);
        }

        parent::tearDown();
    }

    #[Test]
    public function successfulSiteSavePersistsNormalizedValuesAndRequestedSiteIntegrations(): void
    {
        $this->persistPrivateCaptchaExtensionConfiguration([
            'backend' => ['backendLoginEnabled' => true],
        ]);
        $site = $this->site('activation-site', [
            'apiKey' => 'old-persisted-key',
            'sitekey' => 'old-sitekey',
            'customRootDomain' => 'custom.privatecaptcha.com',
        ]);
        $environmentKey = bin2hex(random_bytes(16));
        putenv(ConfigurationResolver::SITE_API_KEYS_ENV . '=' . json_encode([
            $site->getIdentifier() => $environmentKey,
        ], JSON_THROW_ON_ERROR));
        [$service, $factory] = $this->service(
            new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR),
        );

        $result = $service->save(SettingsSubmission::forSite($site, $this->input([
            'apiKey' => ' new-persisted-key ',
            'sitekey' => ' new-sitekey ',
            'theme' => 'dark',
            'language' => 'de',
            'startMode' => 'click',
            'debug' => '1',
            'customStyles' => '--border-radius: 1rem;',
            'euIsolation' => '1',
            'customRootDomain' => ' CuStOm.PrivateCaptcha.COM ',
            'formFrameworkEnabled' => '1',
            'powermailEnabled' => '1',
            'frontendLoginEnabled' => '1',
            'backendLoginEnabled' => '1',
        ])));

        self::assertTrue($result->successful);
        self::assertSame($environmentKey, $factory->effectiveApiKey);
        $persisted = $this->privateCaptchaSiteConfiguration($site);
        $metadata = $persisted['lastConnectionTest'] ?? null;
        unset($persisted['lastConnectionTest']);
        self::assertSame([
            'apiKey' => 'new-persisted-key',
            'sitekey' => 'new-sitekey',
            'customRootDomain' => 'custom.privatecaptcha.com',
            'theme' => 'dark',
            'language' => 'de',
            'startMode' => 'click',
            'debug' => true,
            'customStyles' => '--border-radius: 1rem;',
            'euIsolation' => false,
            'formFrameworkEnabled' => true,
            'powermailEnabled' => true,
            'frontendLoginEnabled' => true,
        ], $persisted);
        self::assertIsArray($metadata);
        self::assertTrue($metadata['successful']);
        self::assertSame('2026-08-22T12:34:56+00:00', $metadata['testedAt']);
        self::assertSame('preserved', $this->siteConfiguration($site)['unrelatedSiteOption']);
        self::assertSame(
            ['backendLoginEnabled' => true],
            $this->privateCaptchaExtensionConfiguration()['backend'],
        );
    }

    #[Test]
    public function failedSiteSavePreservesEditableValuesAndDisablesPreviouslyActiveIntegrations(): void
    {
        $persistedKey = bin2hex(random_bytes(16));
        $site = $this->site('activation-site', [
            'apiKey' => $persistedKey,
            'sitekey' => 'old-sitekey',
            'formFrameworkEnabled' => true,
            'powermailEnabled' => true,
            'frontendLoginEnabled' => true,
        ]);
        [$service, $factory] = $this->service(new VerifyOutput(false, VerifyCode::INVALID_PROPERTY_ERROR));

        $result = $service->save(SettingsSubmission::forSite($site, $this->input([
            'apiKey' => ConfigurationNormalizer::UNCHANGED_API_KEY,
            'sitekey' => 'corrected-sitekey',
            'theme' => 'dark',
            'formFrameworkEnabled' => '1',
            'powermailEnabled' => '1',
            'frontendLoginEnabled' => '1',
        ])));

        $persisted = $this->privateCaptchaSiteConfiguration($site);
        self::assertFalse($result->successful);
        self::assertSame('provider-rejected', $result->reason);
        self::assertSame($persistedKey, $factory->effectiveApiKey);
        self::assertSame($persistedKey, $persisted['apiKey']);
        self::assertSame('corrected-sitekey', $persisted['sitekey']);
        self::assertSame('dark', $persisted['theme']);
        self::assertFalse($persisted['formFrameworkEnabled']);
        self::assertFalse($persisted['powermailEnabled']);
        self::assertFalse($persisted['frontendLoginEnabled']);
    }

    #[Test]
    public function incompleteSiteSaveMakesNoRemoteRequestAndExplicitlyClearsAndDisablesScope(): void
    {
        $site = $this->site('activation-site', [
            'apiKey' => 'old-key',
            'sitekey' => 'old-sitekey',
            'formFrameworkEnabled' => true,
        ]);
        [$service] = $this->service(
            new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR),
        );

        $result = $service->save(SettingsSubmission::forSite($site, $this->input([
            'apiKey' => '',
            'sitekey' => 'corrected-sitekey',
            'formFrameworkEnabled' => '1',
        ])));

        $persisted = $this->privateCaptchaSiteConfiguration($site);
        self::assertFalse($result->successful);
        self::assertSame('missing-configuration', $result->reason);
        self::assertSame('', $persisted['apiKey']);
        self::assertSame('corrected-sitekey', $persisted['sitekey']);
        self::assertFalse($persisted['formFrameworkEnabled']);
        self::assertFalse($persisted['powermailEnabled']);
        self::assertFalse($persisted['frontendLoginEnabled']);
    }

    #[Test]
    public function successfulBackendSavePersistsOnlyBackendIntegrationAndPreservesExtensionSiblings(): void
    {
        $site = $this->site('activation-site', [
            'apiKey' => 'site-key',
            'sitekey' => 'site-sitekey',
            'formFrameworkEnabled' => true,
        ]);
        $this->persistPrivateCaptchaExtensionConfiguration([
            'unrelatedExtensionOption' => 'preserved',
            'backend' => [
                'apiKey' => 'old-key',
                'sitekey' => 'old-sitekey',
                'backendLoginEnabled' => false,
            ],
        ]);
        [$service] = $this->service(new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR));

        $result = $service->save(SettingsSubmission::forBackend($this->input([
            'apiKey' => 'backend-key',
            'sitekey' => 'backend-sitekey',
            'formFrameworkEnabled' => '1',
            'powermailEnabled' => '1',
            'frontendLoginEnabled' => '1',
            'backendLoginEnabled' => '1',
        ])));

        $extensionConfiguration = $this->privateCaptchaExtensionConfiguration();
        self::assertTrue($result->successful);
        self::assertSame('preserved', $extensionConfiguration['unrelatedExtensionOption']);
        $backend = $extensionConfiguration['backend'];
        self::assertIsArray($backend);
        $metadata = $backend['lastConnectionTest'] ?? null;
        unset($backend['lastConnectionTest']);
        self::assertSame([
            'apiKey' => 'backend-key',
            'sitekey' => 'backend-sitekey',
            'theme' => 'light',
            'language' => 'auto',
            'startMode' => 'auto',
            'debug' => false,
            'customStyles' => '',
            'euIsolation' => false,
            'customRootDomain' => '',
            'backendLoginEnabled' => true,
        ], $backend);
        self::assertIsArray($metadata);
        self::assertTrue($metadata['successful']);
        self::assertSame([
            'apiKey' => 'site-key',
            'sitekey' => 'site-sitekey',
            'formFrameworkEnabled' => true,
        ], $this->privateCaptchaSiteConfiguration($site));
    }

    #[Test]
    public function failedBackendSaveDisablesPreviouslyActiveBackendLogin(): void
    {
        $this->persistPrivateCaptchaExtensionConfiguration([
            'backend' => [
                'apiKey' => 'old-key',
                'sitekey' => 'old-sitekey',
                'backendLoginEnabled' => true,
            ],
        ]);
        [$service] = $this->service(new VerifyOutput(false, VerifyCode::MAINTENANCE_MODE_ERROR));

        $result = $service->save(SettingsSubmission::forBackend($this->input([
            'apiKey' => ConfigurationNormalizer::UNCHANGED_API_KEY,
            'sitekey' => 'corrected-sitekey',
            'backendLoginEnabled' => '1',
        ])));

        $persisted = $this->privateCaptchaExtensionConfiguration()['backend'];
        self::assertIsArray($persisted);
        self::assertFalse($result->successful);
        self::assertSame('old-key', $persisted['apiKey']);
        self::assertSame('corrected-sitekey', $persisted['sitekey']);
        self::assertFalse($persisted['backendLoginEnabled']);
    }

    #[Test]
    public function connectionTestActionReturnsDiagnosticsWithoutChangingPersistence(): void
    {
        $persisted = [
            'apiKey' => 'persisted-key',
            'sitekey' => 'persisted-sitekey',
            'theme' => 'light',
            'formFrameworkEnabled' => true,
        ];
        $site = $this->site('activation-site', $persisted);
        [$service, $factory] = $this->service(new VerifyOutput(false, VerifyCode::MAINTENANCE_MODE_ERROR));

        $result = $service->test(SettingsSubmission::forSite($site, $this->input([
            'apiKey' => 'candidate-key',
            'sitekey' => 'candidate-sitekey',
            'theme' => 'dark',
            'formFrameworkEnabled' => false,
        ])));

        self::assertFalse($result->successful);
        self::assertSame('provider-rejected', $result->reason);
        self::assertSame('candidate-key', $factory->effectiveApiKey);
        $stored = $this->privateCaptchaSiteConfiguration($site);
        $metadata = $stored['lastConnectionTest'] ?? null;
        unset($stored['lastConnectionTest']);
        self::assertSame($persisted, $stored);
        self::assertIsArray($metadata);
        self::assertFalse($metadata['successful']);
        self::assertSame('2026-08-22T12:34:56+00:00', $metadata['testedAt']);
    }

    #[Test]
    public function invalidEndpointSubmissionIsRejectedBeforeTestingOrPersistence(): void
    {
        $persisted = [
            'apiKey' => 'persisted-key',
            'sitekey' => 'persisted-sitekey',
            'formFrameworkEnabled' => true,
        ];
        $site = $this->site('activation-site', $persisted);
        [$service] = $this->service(
            new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR),
        );

        try {
            $service->save(SettingsSubmission::forSite($site, $this->input([
                'customRootDomain' => 'https://invalid.example',
            ])));
            self::fail('Unsafe custom endpoints must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame($persisted, $this->privateCaptchaSiteConfiguration($site));
        }
    }

    #[Test]
    public function customEndpointChangeRejectsAnUnchangedPersistedApiKeyBeforeConnection(): void
    {
        $persisted = [
            'apiKey' => 'persisted-key',
            'sitekey' => 'persisted-sitekey',
            'formFrameworkEnabled' => true,
        ];
        $site = $this->site('activation-site', $persisted);
        [$service] = $this->service(
            new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR),
        );

        try {
            $service->test(SettingsSubmission::forSite($site, $this->input([
                'apiKey' => ConfigurationNormalizer::UNCHANGED_API_KEY,
                'customRootDomain' => 'attacker.com',
            ])));
            self::fail('A hidden persisted API key must not be sent to a submitted endpoint.');
        } catch (\InvalidArgumentException) {
            self::assertSame($persisted, $this->privateCaptchaSiteConfiguration($site));
        }
    }

    #[Test]
    public function customEndpointChangeRejectsAnEnvironmentApiKeyEvenWithAReplacementKey(): void
    {
        $environmentKey = bin2hex(random_bytes(16));
        $persisted = [
            'apiKey' => 'persisted-key',
            'sitekey' => 'persisted-sitekey',
            'formFrameworkEnabled' => true,
        ];
        $site = $this->site('activation-site', $persisted);
        putenv(ConfigurationResolver::SITE_API_KEYS_ENV . '=' . json_encode([
            $site->getIdentifier() => $environmentKey,
        ], JSON_THROW_ON_ERROR));
        [$service] = $this->service(
            new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR),
        );

        try {
            $service->save(SettingsSubmission::forSite($site, $this->input([
                'apiKey' => 'replacement-key',
                'customRootDomain' => 'attacker.com',
            ])));
            self::fail('An environment API key must not be sent to a submitted endpoint.');
        } catch (\InvalidArgumentException) {
            self::assertSame($persisted, $this->privateCaptchaSiteConfiguration($site));
        }
    }

    #[Test]
    #[DataProvider('siteConfigurationControlValueProvider')]
    public function rejectsSiteConfigurationControlValuesBeforeTestingOrPersistence(
        string $field,
        string $value,
    ): void {
        $persisted = [
            'apiKey' => 'persisted-key',
            'sitekey' => 'persisted-sitekey',
            'formFrameworkEnabled' => true,
        ];
        $site = $this->site('activation-site', $persisted);
        [$service] = $this->service(
            new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR),
        );

        try {
            $service->save(SettingsSubmission::forSite($site, $this->input([$field => $value])));
            self::fail('TYPO3 site configuration control values must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame($persisted, $this->privateCaptchaSiteConfiguration($site));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function siteConfigurationControlValueProvider(): iterable
    {
        yield 'unset token' => ['sitekey', '__UNSET'];
        yield 'environment placeholder API key' => ['apiKey', '%env(NEW_PRIVATE_CAPTCHA_API_KEY)%'];
        yield 'embedded placeholder' => ['customStyles', 'color: %env(CAPTCHA_COLOR)%;'];
    }

    #[Test]
    public function unchangedExistingSitePlaceholderUsesExpandedKeyWithoutPersistingIt(): void
    {
        $effectiveKey = bin2hex(random_bytes(16));
        putenv('ACTIVATION_API_KEY=' . $effectiveKey);
        $site = $this->site('activation-site', [
            'apiKey' => '%env(ACTIVATION_API_KEY)%',
            'sitekey' => 'persisted-sitekey',
            'formFrameworkEnabled' => false,
        ]);
        [$service, $factory] = $this->service(new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR));

        $result = $service->save(SettingsSubmission::forSite($site, $this->input([
            'apiKey' => ConfigurationNormalizer::UNCHANGED_API_KEY,
            'sitekey' => 'persisted-sitekey',
            'formFrameworkEnabled' => true,
        ])));

        self::assertTrue($result->successful);
        self::assertSame($effectiveKey, $factory->effectiveApiKey);
        self::assertSame(
            '%env(ACTIVATION_API_KEY)%',
            $this->privateCaptchaSiteConfiguration($site)['apiKey'],
        );
    }

    #[Test]
    public function submissionDebugAndExportOutputDoNotExposeSubmittedOrPersistedApiKeys(): void
    {
        $persistedKey = bin2hex(random_bytes(16));
        $submittedKey = bin2hex(random_bytes(16));
        $site = $this->site('activation-site', [
            'apiKey' => $persistedKey,
            'sitekey' => 'persisted-sitekey',
        ]);
        $submission = SettingsSubmission::forSite($site, $this->input([
            'apiKey' => $submittedKey,
        ]));

        $output = implode("\n", [
            print_r($submission, true),
            var_export($submission, true),
        ]);

        self::assertStringNotContainsString($persistedKey, $output);
        self::assertStringNotContainsString($submittedKey, $output);
        $this->expectException(\Throwable::class);

        serialize($submission);
    }

    #[Test]
    public function validationFailuresDoNotExposeReplacementApiKeysInExceptionTraces(): void
    {
        $replacementKey = bin2hex(random_bytes(16));
        $site = $this->site('activation-site', [
            'apiKey' => 'persisted-key',
            'sitekey' => 'persisted-sitekey',
        ]);
        [$service] = $this->service(new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR));
        $previousIgnoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            $service->save(SettingsSubmission::forSite($site, $this->input([
                'apiKey' => $replacementKey,
                'sitekey' => '__UNSET',
            ])));
            self::fail('TYPO3 control values must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $applicationTrace = array_filter(
                $exception->getTrace(),
                static fn(array $frame): bool => str_contains((string)($frame['file'] ?? ''), '/Classes/'),
            );
            self::assertStringNotContainsString($replacementKey, print_r($applicationTrace, true));
        } finally {
            if (is_string($previousIgnoreArgs)) {
                ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
            }
        }
    }

    #[Test]
    public function backendUnchangedSecretUsesFreshDurableConfigurationAfterLockAcquisition(): void
    {
        $durableKey = bin2hex(random_bytes(16));
        $staleRequestKey = bin2hex(random_bytes(16));
        $configurationManager = $this->get(ConfigurationManager::class);
        $this->persistPrivateCaptchaExtensionConfiguration([
            'backend' => [
                'apiKey' => $staleRequestKey,
                'sitekey' => 'backend-sitekey',
                'backendLoginEnabled' => false,
            ],
        ]);
        $lockFactory = new ActivationCallbackLockFactory(
            static function () use ($configurationManager, $durableKey): void {
                self::assertTrue($configurationManager->setLocalConfigurationValueByPath(
                    'EXTENSIONS/private_captcha',
                    ['backend' => [
                        'apiKey' => $durableKey,
                        'sitekey' => 'backend-sitekey',
                        'backendLoginEnabled' => false,
                    ]],
                ));
            },
        );
        [$service, $factory] = $this->service(
            new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR),
            $lockFactory,
        );

        $result = $service->save(SettingsSubmission::forBackend($this->input([
            'apiKey' => ConfigurationNormalizer::UNCHANGED_API_KEY,
            'sitekey' => 'backend-sitekey',
            'backendLoginEnabled' => true,
        ])));

        $persisted = $configurationManager->getLocalConfigurationValueByPath('EXTENSIONS/private_captcha/backend');
        self::assertIsArray($persisted);
        self::assertTrue($result->successful);
        self::assertSame($durableKey, $factory->effectiveApiKey);
        self::assertSame($durableKey, $persisted['apiKey']);
        self::assertTrue($persisted['backendLoginEnabled']);
    }

    #[Test]
    public function sitePlaceholderUsesFreshResolvedConfigurationAfterLockAcquisition(): void
    {
        $staleEffectiveKey = bin2hex(random_bytes(16));
        $durableEffectiveKey = bin2hex(random_bytes(16));
        putenv('ACTIVATION_API_KEY=' . $staleEffectiveKey);
        putenv('ACTIVATION_API_KEY_NEW=' . $durableEffectiveKey);
        $site = $this->site('activation-site', [
            'apiKey' => '%env(ACTIVATION_API_KEY)%',
            'sitekey' => 'persisted-sitekey',
            'formFrameworkEnabled' => false,
        ]);
        $siteConfiguration = $this->get(SiteConfiguration::class);
        $siteWriter = $this->get(SiteWriter::class);
        $lockFactory = new ActivationCallbackLockFactory(
            static function () use ($site, $siteConfiguration, $siteWriter): void {
                $configuration = $siteConfiguration->load($site->getIdentifier());
                $privateCaptcha = $configuration['privateCaptcha'] ?? null;
                self::assertIsArray($privateCaptcha);
                $privateCaptcha['apiKey'] = '%env(ACTIVATION_API_KEY_NEW)%';
                $configuration['privateCaptcha'] = $privateCaptcha;
                $siteWriter->write($site->getIdentifier(), $configuration);
            },
        );
        [$service, $factory] = $this->service(
            new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR),
            $lockFactory,
        );

        $result = $service->save(SettingsSubmission::forSite($site, $this->input([
            'apiKey' => ConfigurationNormalizer::UNCHANGED_API_KEY,
            'sitekey' => 'persisted-sitekey',
            'formFrameworkEnabled' => true,
        ])));

        self::assertTrue($result->successful);
        self::assertSame($durableEffectiveKey, $factory->effectiveApiKey);
        $persisted = $this->privateCaptchaSiteConfiguration($site);
        self::assertSame('%env(ACTIVATION_API_KEY_NEW)%', $persisted['apiKey']);
        self::assertTrue($persisted['formFrameworkEnabled']);
    }

    #[Test]
    public function connectionStatusAndLogsContainOnlySafeDiagnostics(): void
    {
        $persistedKey = bin2hex(random_bytes(16));
        $submittedKey = bin2hex(random_bytes(16));
        $providerMessage = 'provider leaked ' . $persistedKey . ' ' . $submittedKey;
        $site = $this->site('activation-site', [
            'apiKey' => $persistedKey,
            'sitekey' => 'persisted-sitekey',
            'formFrameworkEnabled' => true,
        ]);
        [$service, , , $logger] = $this->service(new \RuntimeException($providerMessage));

        $result = $service->test(SettingsSubmission::forSite($site, $this->input([
            'apiKey' => $submittedKey,
            'sitekey' => 'candidate-sitekey',
        ])));

        self::assertFalse($result->successful);
        $stored = $this->privateCaptchaSiteConfiguration($site);
        self::assertSame($persistedKey, $stored['apiKey']);
        self::assertSame('persisted-sitekey', $stored['sitekey']);
        self::assertTrue($stored['formFrameworkEnabled']);
        self::assertSame([
            'testedAt' => '2026-08-22T12:34:56+00:00',
            'successful' => false,
            'reason' => 'connection-error',
            'providerCode' => null,
            'exceptionClass' => \RuntimeException::class,
            'attemptCount' => 1,
            'durationMilliseconds' => 0,
            'productionSitekeyOwnershipProven' => false,
            'productionOriginProven' => false,
            'limitation' => 'production-sitekey-ownership-and-origin-not-proven',
        ], $stored['lastConnectionTest']);
        $diagnostics = json_encode([$stored['lastConnectionTest'], $logger->records], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($persistedKey, $diagnostics);
        self::assertStringNotContainsString($submittedKey, $diagnostics);
        self::assertStringNotContainsString($providerMessage, $diagnostics);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'apiKey' => 'api-key',
            'sitekey' => 'sitekey',
            'theme' => 'light',
            'language' => 'auto',
            'startMode' => 'auto',
            'debug' => false,
            'customStyles' => '',
            'euIsolation' => false,
            'customRootDomain' => '',
            'formFrameworkEnabled' => false,
            'powermailEnabled' => false,
            'frontendLoginEnabled' => false,
            'backendLoginEnabled' => false,
        ], $overrides);
    }

    /**
     * @return array{SettingsActivationService, ActivationRecordingFactory, ActivationRecordingPuzzleClient, ActivationRecordingLogger}
     */
    private function service(
        VerifyOutput|\Throwable $outcome,
        ?LockFactory $lockFactory = null,
    ): array {
        $factory = new ActivationRecordingFactory(new ActivationRecordingClient($outcome));
        $puzzleClient = new ActivationRecordingPuzzleClient();
        $logger = new ActivationRecordingLogger();
        $connectionTester = new ConnectionTester($factory, $puzzleClient, $logger);

        return [
            new SettingsActivationService(
                configurationNormalizer: $this->get(ConfigurationNormalizer::class),
                customDomainValidator: $this->get(CustomDomainValidator::class),
                configurationResolver: $this->get(ConfigurationResolver::class),
                connectionTester: $connectionTester,
                siteConfigurationRepository: $this->get(SiteConfigurationRepository::class),
                backendConfigurationRepository: $this->get(BackendConfigurationRepository::class),
                lockFactory: $lockFactory ?? $this->get(LockFactory::class),
                clock: new ActivationClock(),
                logger: $logger,
            ),
            $factory,
            $puzzleClient,
            $logger,
        ];
    }

    /**
     * @param array<string, mixed> $privateCaptchaConfiguration
     */
    private function site(string $identifier, array $privateCaptchaConfiguration): Site
    {
        $this->get(SiteWriter::class)->write($identifier, [
            'rootPageId' => 1,
            'base' => 'https://' . $identifier . '.test/',
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ],
            ],
            'unrelatedSiteOption' => 'preserved',
            'privateCaptcha' => $privateCaptchaConfiguration,
        ]);
        $sites = $this->get(SiteConfiguration::class)->resolveAllExistingSites(false);

        return $sites[$identifier];
    }

    /**
     * @return array<string, mixed>
     */
    private function siteConfiguration(Site $site): array
    {
        $configuration = $this->get(SiteConfiguration::class)->load($site->getIdentifier());
        /** @var array<string, mixed> $configuration */

        return $configuration;
    }

    /**
     * @return array<string, mixed>
     */
    private function privateCaptchaSiteConfiguration(Site $site): array
    {
        $configuration = $this->siteConfiguration($site)['privateCaptcha'] ?? null;
        self::assertIsArray($configuration);

        /** @var array<string, mixed> $configuration */
        return $configuration;
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

    /**
     * @param array<string, mixed> $extensionConfigurations
     */
    private function setExtensionConfigurations(array $extensionConfigurations): void
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3Configuration)) {
            $typo3Configuration = [];
        }
        $typo3Configuration['EXTENSIONS'] = $extensionConfigurations;
        $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;
    }

    private function setPrivateCaptchaExtensionConfiguration(mixed $configuration): void
    {
        $extensionConfigurations = $this->extensionConfigurations();
        $extensionConfigurations['private_captcha'] = $configuration;
        $this->setExtensionConfigurations($extensionConfigurations);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function persistPrivateCaptchaExtensionConfiguration(array $configuration): void
    {
        self::assertTrue($this->get(ConfigurationManager::class)->setLocalConfigurationValueByPath(
            'EXTENSIONS/private_captcha',
            $configuration,
        ));
        $this->setPrivateCaptchaExtensionConfiguration($configuration);
    }

    /**
     * @return array<string, mixed>
     */
    private function privateCaptchaExtensionConfiguration(): array
    {
        $configuration = $this->extensionConfigurations()['private_captcha'] ?? null;
        self::assertIsArray($configuration);

        /** @var array<string, mixed> $configuration */
        return $configuration;
    }
}

final class ActivationRecordingFactory implements PrivateCaptchaClientFactoryInterface
{
    public ?string $effectiveApiKey = null;

    public function __construct(
        private readonly Client $client,
    ) {}

    public function create(ResolvedCaptchaConfiguration $configuration): Client
    {
        $this->effectiveApiKey = $configuration->apiKey();

        return $this->client;
    }
}

final class ActivationRecordingPuzzleClient implements TestPuzzleClientInterface
{
    public function fetch(
        EndpointConfiguration $endpoints,
        string $sitekey,
    ): string {
        return base64_encode(random_bytes(128));
    }
}

final class ActivationRecordingClient extends Client
{
    public function __construct(
        private readonly VerifyOutput|\Throwable $outcome,
    ) {}

    public function verify(
        string $solution,
        int $maxBackoffSeconds = 20,
        int $attempts = 5,
        ?string $sitekey = null,
    ): VerifyOutput {
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

final class ActivationClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-22T12:34:56+00:00');
    }
}

final class ActivationRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }
}

final class ActivationCallbackLockFactory extends LockFactory
{
    public function __construct(
        private readonly \Closure $onAcquire,
    ) {}

    public function createLocker(
        string $id,
        int $capabilities = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE,
    ): LockingStrategyInterface {
        return new ActivationCallbackLock($id, $this->onAcquire);
    }
}

final class ActivationCallbackLock implements LockingStrategyInterface
{
    private bool $acquired = false;

    /**
     * @param string $subject
     */
    public function __construct($subject, private readonly ?\Closure $onAcquire = null) {}

    public static function getCapabilities(): int
    {
        return self::LOCK_CAPABILITY_EXCLUSIVE;
    }

    public static function getPriority(): int
    {
        return 100;
    }

    public function acquire($mode = self::LOCK_CAPABILITY_EXCLUSIVE): bool
    {
        $this->onAcquire?->__invoke();
        $this->acquired = true;

        return true;
    }

    public function release(): bool
    {
        $this->acquired = false;

        return true;
    }

    public function destroy(): void
    {
        $this->acquired = false;
    }

    public function isAcquired(): bool
    {
        return $this->acquired;
    }
}

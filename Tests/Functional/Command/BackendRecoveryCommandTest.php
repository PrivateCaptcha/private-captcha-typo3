<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Typo3\Configuration\BackendConfigurationRepository;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BackendRecoveryCommandTest extends FunctionalTestCase
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

    private bool $hadBackendUser = false;

    private mixed $originalBackendUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->environmentVariableNames() as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
        }
        $this->hadBackendUser = array_key_exists('BE_USER', $GLOBALS);
        $this->originalBackendUser = $GLOBALS['BE_USER'] ?? null;
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        if ($this->hadBackendUser) {
            $GLOBALS['BE_USER'] = $this->originalBackendUser;
        } else {
            unset($GLOBALS['BE_USER']);
        }

        parent::tearDown();
    }

    #[Test]
    public function statusReportsEnabledProtectionAndSafeConnectionStateWithoutCredentials(): void
    {
        $apiKey = 'persisted-api-key-' . bin2hex(random_bytes(8));
        $sitekey = 'persisted-sitekey-' . bin2hex(random_bytes(8));
        $this->persistPrivateCaptchaConfiguration([
            'backend' => $this->enabledBackendConfiguration($apiKey, $sitekey, [
                'successful' => true,
                'testedAt' => '2026-08-23T12:34:56+00:00',
                'errorCode' => null,
            ]),
        ]);
        unset($GLOBALS['BE_USER']);

        [$exitCode, $output] = $this->execute('private-captcha:backend:status');

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Protection requested: enabled', $output);
        self::assertStringContainsString('Protection effective: enabled', $output);
        self::assertStringContainsString('Persisted protection: enabled', $output);
        self::assertStringContainsString('Configuration override: inactive', $output);
        self::assertStringContainsString('Emergency disable: inactive', $output);
        self::assertStringContainsString('API key: configured (runtime configuration)', $output);
        self::assertStringContainsString('Sitekey: configured', $output);
        self::assertStringContainsString(
            'Last connection test: successful at 2026-08-23 12:34:56 UTC',
            $output,
        );
        self::assertStringNotContainsString($apiKey, $output);
        self::assertStringNotContainsString($sitekey, $output);
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
    }

    #[Test]
    public function emergencyDisablePrecedesMalformedPersistenceInStatus(): void
    {
        $persistedSecret = 'persisted-secret-' . bin2hex(random_bytes(8));
        $environmentSecret = 'environment-secret-' . bin2hex(random_bytes(8));
        $this->persistPrivateCaptchaConfiguration(['backend' => 'malformed-' . $persistedSecret]);
        putenv(ConfigurationResolver::BACKEND_API_KEY_ENV . '=' . $environmentSecret);
        putenv(ConfigurationResolver::DISABLE_BACKEND_LOGIN_ENV . '=true');

        [$exitCode, $output] = $this->execute('private-captcha:backend:status');

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Protection requested: invalid', $output);
        self::assertStringContainsString('Protection effective: disabled', $output);
        self::assertStringContainsString('Persisted protection: invalid', $output);
        self::assertStringContainsString('Configuration override: not evaluated', $output);
        self::assertStringContainsString('Emergency disable: active', $output);
        self::assertStringContainsString('API key: configured (environment)', $output);
        self::assertStringContainsString('Sitekey: invalid', $output);
        self::assertStringContainsString('Last connection test: not tested', $output);
        self::assertStringNotContainsString($persistedSecret, $output);
        self::assertStringNotContainsString($environmentSecret, $output);

        putenv(ConfigurationResolver::BACKEND_API_KEY_ENV . "=invalid\n" . $environmentSecret);
        [$invalidOverrideExitCode, $invalidOverrideOutput] = $this->execute('private-captcha:backend:status');
        self::assertSame(Command::SUCCESS, $invalidOverrideExitCode);
        self::assertStringContainsString('API key: invalid (environment)', $invalidOverrideOutput);
        self::assertStringNotContainsString($environmentSecret, $invalidOverrideOutput);

        putenv(ConfigurationResolver::BACKEND_API_KEY_ENV . '=' . $environmentSecret);
        $this->persistPrivateCaptchaConfiguration([
            'backend' => $this->enabledBackendConfiguration($persistedSecret, 'backend-sitekey', []),
        ]);
        [$enabledExitCode, $enabledOutput] = $this->execute('private-captcha:backend:status');
        self::assertSame(Command::SUCCESS, $enabledExitCode);
        self::assertStringContainsString('Protection requested: enabled', $enabledOutput);
        self::assertStringContainsString('Protection effective: disabled', $enabledOutput);
        self::assertStringContainsString('Persisted protection: enabled', $enabledOutput);
        self::assertStringContainsString('Configuration override: not evaluated', $enabledOutput);
    }

    #[Test]
    public function statusDistinguishesRequestedProtectionFromMissingCredentials(): void
    {
        $apiKey = 'persisted-api-key-' . bin2hex(random_bytes(8));
        $this->persistPrivateCaptchaConfiguration([
            'backend' => $this->enabledBackendConfiguration($apiKey, '   ', [
                'successful' => false,
                'testedAt' => '2026-08-23T13:45:01+00:00',
                'errorCode' => 'transport',
            ]),
        ]);

        [$exitCode, $output] = $this->execute('private-captcha:backend:status');

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Protection requested: enabled', $output);
        self::assertStringContainsString('Protection effective: disabled', $output);
        self::assertStringContainsString('API key: configured (runtime configuration)', $output);
        self::assertStringContainsString('Sitekey: not configured', $output);
        self::assertStringContainsString(
            'Last connection test: failed at 2026-08-23 13:45:01 UTC',
            $output,
        );
        self::assertStringNotContainsString($apiKey, $output);
        self::assertStringNotContainsString('transport', $output);
    }

    #[Test]
    public function statusReportsRuntimeConfigurationOverridesSeparatelyFromPersistence(): void
    {
        $runtimeApiKey = 'runtime-api-key-' . bin2hex(random_bytes(8));
        $runtimeSitekey = 'runtime-sitekey-' . bin2hex(random_bytes(8));
        $this->persistPrivateCaptchaConfiguration([
            'backend' => [
                'apiKey' => '',
                'sitekey' => '',
                'backendLoginEnabled' => false,
            ],
        ]);
        $this->setRuntimePrivateCaptchaConfiguration([
            'backend' => $this->enabledBackendConfiguration($runtimeApiKey, $runtimeSitekey, []),
        ]);

        [$exitCode, $output] = $this->execute('private-captcha:backend:status');

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Protection requested: enabled', $output);
        self::assertStringContainsString('Protection effective: enabled', $output);
        self::assertStringContainsString('Persisted protection: disabled', $output);
        self::assertStringContainsString('Configuration override: active', $output);
        self::assertStringContainsString('API key: configured (runtime configuration)', $output);
        self::assertStringContainsString('Sitekey: configured', $output);
        self::assertStringNotContainsString($runtimeApiKey, $output);
        self::assertStringNotContainsString($runtimeSitekey, $output);
    }

    #[Test]
    public function statusTreatsInvalidConnectionMetadataAsNotTested(): void
    {
        $this->persistPrivateCaptchaConfiguration([
            'backend' => $this->enabledBackendConfiguration('api-key', 'sitekey', [
                'successful' => true,
                'testedAt' => "not-a-date\0must-not-be-rendered",
                'diagnostic' => 'must-not-be-rendered',
            ]),
        ]);

        [$exitCode, $output] = $this->execute('private-captcha:backend:status');

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Last connection test: not tested', $output);
        self::assertStringNotContainsString('not-a-date', $output);
        self::assertStringNotContainsString('must-not-be-rendered', $output);
    }

    #[Test]
    public function disableWorksWithoutBackendUserAndPreservesAllOtherConfiguration(): void
    {
        $apiKey = 'persisted-api-key-' . bin2hex(random_bytes(8));
        $backendConfiguration = $this->enabledBackendConfiguration($apiKey, 'backend-sitekey', [
            'successful' => true,
            'testedAt' => '2026-08-23T14:00:00+00:00',
        ]);
        $backendConfiguration['customRootDomain'] = 'custom.privatecaptcha.com';
        $this->persistPrivateCaptchaConfiguration([
            'unrelatedExtensionSetting' => 'preserved',
            'backend' => $backendConfiguration,
        ]);
        putenv(ConfigurationResolver::DISABLE_BACKEND_LOGIN_ENV . '=true');
        unset($GLOBALS['BE_USER']);

        $locker = $this->get(LockFactory::class)->createLocker(
            BackendConfigurationRepository::LOCK_IDENTIFIER,
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE
            | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK,
        );
        self::assertTrue($locker->acquire());
        try {
            [$lockedExitCode] = $this->execute('private-captcha:backend:disable');
            self::assertSame(Command::FAILURE, $lockedExitCode);
            self::assertTrue($this->get(BackendConfigurationRepository::class)->getForEditing()['backendLoginEnabled'] ?? false);
        } finally {
            $locker->release();
        }

        [$firstExitCode, $firstOutput] = $this->execute('private-captcha:backend:disable');
        [$secondExitCode] = $this->execute('private-captcha:backend:disable');

        self::assertSame(Command::SUCCESS, $firstExitCode);
        self::assertSame(Command::SUCCESS, $secondExitCode);
        self::assertStringContainsString('Persisted backend login protection is disabled.', $firstOutput);
        $stored = $this->privateCaptchaConfiguration();
        self::assertSame('preserved', $stored['unrelatedExtensionSetting'] ?? null);
        $expectedBackendConfiguration = $backendConfiguration;
        $expectedBackendConfiguration['backendLoginEnabled'] = false;
        self::assertEquals($expectedBackendConfiguration, $stored['backend'] ?? null);
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);

        putenv(ConfigurationResolver::DISABLE_BACKEND_LOGIN_ENV);
        $this->persistPrivateCaptchaConfiguration([
            'unrelatedExtensionSetting' => 'preserved-with-malformed-fields',
            'backend' => [
                'apiKey' => [$apiKey],
                'sitekey' => 'backend-sitekey',
                'backendLoginEnabled' => true,
                'theme' => 'invalid-theme',
            ],
        ]);
        [$malformedFieldsExitCode] = $this->execute('private-captcha:backend:disable');
        self::assertSame(Command::SUCCESS, $malformedFieldsExitCode);
        $stored = $this->privateCaptchaConfiguration();
        $storedBackend = $stored['backend'] ?? null;
        self::assertIsArray($storedBackend);
        self::assertSame([$apiKey], $storedBackend['apiKey'] ?? null);
        self::assertSame('invalid-theme', $storedBackend['theme'] ?? null);
        self::assertFalse($storedBackend['backendLoginEnabled'] ?? true);
        $this->setRuntimePrivateCaptchaConfiguration($stored);
        $resolved = $this->get(ConfigurationResolver::class)->resolveBackend();
        self::assertFalse($resolved->requestedIntegrations->backendLogin);
        self::assertFalse($resolved->integrations->backendLogin);

        $this->persistPrivateCaptchaConfiguration([
            'unrelatedExtensionSetting' => 'preserved-after-malformed-recovery',
            'backend' => 'malformed-backend-' . $apiKey,
        ]);
        [$malformedExitCode, $malformedOutput] = $this->execute('private-captcha:backend:disable');
        self::assertSame(Command::SUCCESS, $malformedExitCode);
        self::assertStringContainsString('Persisted backend login protection is disabled.', $malformedOutput);
        $stored = $this->privateCaptchaConfiguration();
        self::assertSame('preserved-after-malformed-recovery', $stored['unrelatedExtensionSetting'] ?? null);
        self::assertSame(['backendLoginEnabled' => false], $stored['backend'] ?? null);

        $this->setRuntimePrivateCaptchaConfiguration($stored);
        $resolved = $this->get(ConfigurationResolver::class)->resolveBackend();
        self::assertFalse($resolved->requestedIntegrations->backendLogin);
        self::assertFalse($resolved->integrations->backendLogin);
    }

    /**
     * @param array<string, mixed> $lastConnectionTest
     * @return array<string, mixed>
     */
    private function enabledBackendConfiguration(
        string $apiKey,
        string $sitekey,
        array $lastConnectionTest,
    ): array {
        return [
            'apiKey' => $apiKey,
            'sitekey' => $sitekey,
            'backendLoginEnabled' => true,
            'theme' => 'dark',
            'language' => 'de',
            'startMode' => 'click',
            'debug' => true,
            'customStyles' => '--border-radius: 1rem;',
            'euIsolation' => true,
            'customRootDomain' => '',
            'lastConnectionTest' => $lastConnectionTest,
        ];
    }

    /**
     * @return array{int, string}
     */
    private function execute(string $commandName): array
    {
        $command = $this->get(CommandRegistry::class)->get($commandName);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([], ['interactive' => false]);

        return [$exitCode, $tester->getDisplay()];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function persistPrivateCaptchaConfiguration(array $configuration): void
    {
        self::assertTrue($this->get(ConfigurationManager::class)->setLocalConfigurationValueByPath(
            'EXTENSIONS/private_captcha',
            $configuration,
        ));
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        self::assertIsArray($typo3Configuration);
        $extensionConfigurations = $typo3Configuration['EXTENSIONS'] ?? [];
        self::assertIsArray($extensionConfigurations);
        $extensionConfigurations['private_captcha'] = $configuration;
        $typo3Configuration['EXTENSIONS'] = $extensionConfigurations;
        $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function setRuntimePrivateCaptchaConfiguration(array $configuration): void
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        self::assertIsArray($typo3Configuration);
        $extensionConfigurations = $typo3Configuration['EXTENSIONS'] ?? [];
        self::assertIsArray($extensionConfigurations);
        $extensionConfigurations['private_captcha'] = $configuration;
        $typo3Configuration['EXTENSIONS'] = $extensionConfigurations;
        $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;
    }

    /**
     * @return array<string, mixed>
     */
    private function privateCaptchaConfiguration(): array
    {
        $localConfiguration = $this->get(ConfigurationManager::class)->getLocalConfiguration();
        $extensionConfigurations = $localConfiguration['EXTENSIONS'] ?? null;
        self::assertIsArray($extensionConfigurations);
        $configuration = $extensionConfigurations['private_captcha'] ?? null;
        self::assertIsArray($configuration);

        /** @var array<string, mixed> $configuration */
        return $configuration;
    }

    /**
     * @return list<string>
     */
    private function environmentVariableNames(): array
    {
        return [
            ConfigurationResolver::BACKEND_API_KEY_ENV,
            ConfigurationResolver::DISABLE_BACKEND_LOGIN_ENV,
        ];
    }
}

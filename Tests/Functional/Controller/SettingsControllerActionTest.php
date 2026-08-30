<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Controller;

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
use PrivateCaptcha\Typo3\Controller\SettingsController;
use PrivateCaptcha\Typo3\Service\ConnectionTester;
use PrivateCaptcha\Typo3\Service\PrivateCaptchaClientFactoryInterface;
use PrivateCaptcha\Typo3\Service\SettingsActivationService;
use PrivateCaptcha\Typo3\Service\TestPuzzleClientInterface;
use PrivateCaptcha\Typo3\ValueObject\EndpointConfiguration;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Route as SymfonyRoute;
use TYPO3\CMS\Backend\Module\Module;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SettingsControllerActionTest extends FunctionalTestCase
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

    private mixed $previousLanguageService = null;

    private mixed $previousBackendUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([ConfigurationResolver::SITE_API_KEYS_ENV, ConfigurationResolver::BACKEND_API_KEY_ENV] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
        }
        $this->previousLanguageService = $GLOBALS['LANG'] ?? null;
        $this->previousBackendUser = $GLOBALS['BE_USER'] ?? null;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences(null);
        $GLOBALS['BE_USER'] = $this->backendUser();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        if ($this->previousLanguageService === null) {
            unset($GLOBALS['LANG']);
        } else {
            $GLOBALS['LANG'] = $this->previousLanguageService;
        }
        if ($this->previousBackendUser === null) {
            unset($GLOBALS['BE_USER']);
        } else {
            $GLOBALS['BE_USER'] = $this->previousBackendUser;
        }

        parent::tearDown();
    }

    #[Test]
    public function successfulSiteSavePreservesBlankApiKeyAndEnablesProtection(): void
    {
        $apiKey = bin2hex(random_bytes(16));
        $environmentApiKey = bin2hex(random_bytes(16));
        $customStyles = '--label: "</textarea><script>unsafe</script>";';
        putenv(ConfigurationResolver::SITE_API_KEYS_ENV . '=' . json_encode([
            'action-site' => $environmentApiKey,
        ], JSON_THROW_ON_ERROR));
        $this->writeSite('action-site', [
            'apiKey' => $apiKey,
            'sitekey' => 'old-sitekey',
            'formFrameworkEnabled' => false,
        ]);
        $controller = $this->controller(
            new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR),
        );
        [$redirect, $notice] = $this->submitSiteAction($controller, 'action-site', [
            'settingsAction' => 'save',
            'settings' => $this->settingsInput([
                'apiKey' => '',
                'sitekey' => ' updated-sitekey ',
                'theme' => 'dark',
                'customStyles' => $customStyles,
                'formFrameworkEnabled' => '1',
            ]),
        ]);

        self::assertSame(303, $redirect->getStatusCode());
        self::assertSame(ContextualFeedbackSeverity::OK, $notice->getSeverity());
        self::assertSame('Settings saved.', $notice->getMessage());
        self::assertStringNotContainsString($apiKey, $notice->getMessage());
        self::assertStringNotContainsString($environmentApiKey, $notice->getMessage());
        $persisted = $this->privateCaptchaSiteConfiguration('action-site');
        self::assertSame($apiKey, $persisted['apiKey']);
        self::assertSame('updated-sitekey', $persisted['sitekey']);
        self::assertSame('dark', $persisted['theme']);
        self::assertSame($customStyles, $persisted['customStyles']);
        self::assertTrue($persisted['formFrameworkEnabled']);
        self::assertSame([
            'testedAt' => '2026-08-22T12:34:56+00:00',
            'successful' => true,
            'reason' => 'connection-ok',
            'providerCode' => VerifyCode::TEST_PROPERTY_ERROR->value,
            'exceptionClass' => null,
            'attemptCount' => 1,
            'durationMilliseconds' => 0,
            'productionSitekeyOwnershipProven' => false,
            'productionOriginProven' => false,
            'limitation' => 'production-sitekey-ownership-and-origin-not-proven',
        ], $persisted['lastConnectionTest']);

    }

    #[Test]
    public function failedSiteSavePersistsCorrectionsAndExplicitlyDisablesProtection(): void
    {
        $apiKey = bin2hex(random_bytes(16));
        $this->writeSite('failed-save-site', [
            'apiKey' => $apiKey,
            'sitekey' => 'old-sitekey',
            'formFrameworkEnabled' => true,
            'frontendLoginEnabled' => true,
        ]);

        $controller = $this->controller(
            new VerifyOutput(false, VerifyCode::INVALID_PROPERTY_ERROR),
        );
        [, $notice] = $this->submitSiteAction($controller, 'failed-save-site', [
            'settingsAction' => 'save',
            'settings' => $this->settingsInput([
                'apiKey' => '',
                'sitekey' => 'corrected-sitekey',
                'formFrameworkEnabled' => '1',
                'frontendLoginEnabled' => '1',
            ]),
        ]);

        self::assertSame(ContextualFeedbackSeverity::ERROR, $notice->getSeverity());
        self::assertSame('Settings saved. Connection test failed. Integrations were disabled.', $notice->getMessage());
        self::assertStringNotContainsString($apiKey, $notice->getMessage());
        $persisted = $this->privateCaptchaSiteConfiguration('failed-save-site');
        self::assertSame($apiKey, $persisted['apiKey']);
        self::assertSame('corrected-sitekey', $persisted['sitekey']);
        self::assertFalse($persisted['formFrameworkEnabled']);
        self::assertFalse($persisted['powermailEnabled']);
        self::assertFalse($persisted['frontendLoginEnabled']);
        $metadata = $persisted['lastConnectionTest'] ?? null;
        self::assertIsArray($metadata);
        self::assertFalse($metadata['successful']);
        self::assertSame('provider-rejected', $metadata['reason']);

    }

    #[Test]
    public function testOnlyUpdatesSafeStatusWithoutChangingSettingsOrEnablement(): void
    {
        $apiKey = bin2hex(random_bytes(16));
        $candidateApiKey = bin2hex(random_bytes(16));
        $existing = [
            'apiKey' => $apiKey,
            'sitekey' => 'persisted-sitekey',
            'theme' => 'light',
            'formFrameworkEnabled' => true,
        ];
        $this->writeSite('test-only-site', $existing);

        $controller = $this->controller(
            new VerifyOutput(false, VerifyCode::MAINTENANCE_MODE_ERROR),
        );
        [, $notice] = $this->submitSiteAction($controller, 'test-only-site', [
            'settingsAction' => 'test',
            'settings' => $this->settingsInput([
                'apiKey' => $candidateApiKey,
                'sitekey' => 'candidate-sitekey',
                'theme' => 'dark',
                'formFrameworkEnabled' => '0',
            ]),
        ]);

        self::assertSame(ContextualFeedbackSeverity::ERROR, $notice->getSeverity());
        self::assertSame('Connection test failed.', $notice->getMessage());
        $persisted = $this->privateCaptchaSiteConfiguration('test-only-site');
        $metadata = $persisted['lastConnectionTest'] ?? null;
        unset($persisted['lastConnectionTest']);
        self::assertSame($existing, $persisted);
        self::assertIsArray($metadata);
        self::assertFalse($metadata['successful']);
        self::assertSame('provider-rejected', $metadata['reason']);
        self::assertSame('2026-08-22T12:34:56+00:00', $metadata['testedAt']);

    }

    #[Test]
    public function resetClearsOnlyTheSelectedSiteScopeAndReturnsToUntestedDefaults(): void
    {
        $this->writeSite('reset-site', [
            'apiKey' => 'reset-secret',
            'sitekey' => 'reset-sitekey',
            'theme' => 'dark',
            'formFrameworkEnabled' => true,
            'lastConnectionTest' => [
                'testedAt' => '2026-08-21T12:00:00+00:00',
                'successful' => true,
            ],
        ]);
        $otherConfiguration = [
            'apiKey' => 'other-secret',
            'sitekey' => 'other-sitekey',
            'formFrameworkEnabled' => true,
        ];
        $this->writeSite('other-site', $otherConfiguration);

        $controller = $this->controller(
            new \RuntimeException('The reset action must not connect.'),
        );
        [, $notice] = $this->submitSiteAction($controller, 'reset-site', [
            'settingsAction' => 'reset',
            'settings' => ['apiKey' => 'ignored-secret'],
        ]);

        self::assertSame(ContextualFeedbackSeverity::OK, $notice->getSeverity());
        self::assertSame('Settings reset.', $notice->getMessage());
        self::assertSame([], $this->privateCaptchaSiteConfiguration('reset-site'));
        self::assertSame($otherConfiguration, $this->privateCaptchaSiteConfiguration('other-site'));
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('malformedSubmissionProvider')]
    public function malformedSubmissionReturnsLocalizedFailureWithoutMutation(array $body): void
    {
        $configuration = [
            'apiKey' => 'persisted-secret',
            'sitekey' => 'persisted-sitekey',
            'theme' => 'dark',
            'formFrameworkEnabled' => true,
        ];
        $this->writeSite('malformed-site', $configuration);
        $controller = $this->controller(
            new \RuntimeException('Malformed input must not start a connection test.'),
        );

        [, $notice] = $this->submitSiteAction($controller, 'malformed-site', $body);

        self::assertSame(ContextualFeedbackSeverity::ERROR, $notice->getSeverity());
        self::assertSame('Invalid settings. Nothing was changed.', $notice->getMessage());
        self::assertSame($configuration, $this->privateCaptchaSiteConfiguration('malformed-site'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedSubmissionProvider(): iterable
    {
        yield 'unknown action' => [[
            'settingsAction' => 'delete',
            'settings' => [],
        ]];
        yield 'missing settings' => [['settingsAction' => 'save']];
        yield 'empty settings' => [[
            'settingsAction' => 'test',
            'settings' => [],
        ]];
        yield 'incomplete settings' => [[
            'settingsAction' => 'save',
            'settings' => [
                'apiKey' => 'submitted-secret',
                'sitekey' => 'candidate-sitekey',
            ],
        ]];
    }

    #[Test]
    public function omittedRenderedIntegrationReturnsFailureWithoutDisablingProtection(): void
    {
        $configuration = [
            'apiKey' => 'persisted-secret',
            'sitekey' => 'persisted-sitekey',
            'formFrameworkEnabled' => true,
            'frontendLoginEnabled' => true,
        ];
        $this->writeSite('omitted-integration-site', $configuration);
        $settings = $this->settingsInput([
            'apiKey' => '',
            'formFrameworkEnabled' => '1',
        ]);
        unset($settings['frontendLoginEnabled']);
        $controller = $this->controller(
            new \RuntimeException('Incomplete input must not start a connection test.'),
        );

        [, $notice] = $this->submitSiteAction($controller, 'omitted-integration-site', [
            'settingsAction' => 'save',
            'settings' => $settings,
        ]);

        self::assertSame(ContextualFeedbackSeverity::ERROR, $notice->getSeverity());
        self::assertSame('Invalid settings. Nothing was changed.', $notice->getMessage());
        self::assertSame($configuration, $this->privateCaptchaSiteConfiguration('omitted-integration-site'));
    }

    private function controller(
        VerifyOutput|\Throwable $outcome,
        ?LoggerInterface $activationLogger = null,
    ): SettingsController {
        $connectionTester = new ConnectionTester(
            new SettingsActionClientFactory(new SettingsActionClient($outcome)),
            new SettingsActionPuzzleClient(),
            new NullLogger(),
        );
        $activationService = new SettingsActivationService(
            configurationNormalizer: $this->get(ConfigurationNormalizer::class),
            customDomainValidator: $this->get(CustomDomainValidator::class),
            configurationResolver: $this->get(ConfigurationResolver::class),
            connectionTester: $connectionTester,
            siteConfigurationRepository: $this->get(SiteConfigurationRepository::class),
            backendConfigurationRepository: $this->get(BackendConfigurationRepository::class),
            lockFactory: $this->get(LockFactory::class),
            clock: new SettingsActionClock(),
            logger: $activationLogger ?? new NullLogger(),
        );

        return new SettingsController(
            siteFinder: $this->get(SiteFinder::class),
            moduleTemplateFactory: $this->get(ModuleTemplateFactory::class),
            siteConfigurationRepository: $this->get(SiteConfigurationRepository::class),
            backendConfigurationRepository: $this->get(BackendConfigurationRepository::class),
            configurationNormalizer: $this->get(ConfigurationNormalizer::class),
            configurationResolver: $this->get(ConfigurationResolver::class),
            packageManager: $this->get(PackageManager::class),
            typo3Version: $this->get(Typo3Version::class),
            settingsActivationService: $activationService,
            uriBuilder: $this->get(UriBuilder::class),
            flashMessageService: $this->get(FlashMessageService::class),
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array{ResponseInterface, FlashMessage}
     */
    private function submitSiteAction(
        SettingsController $controller,
        string $siteIdentifier,
        array $body,
    ): array {
        $redirect = $controller->siteAction(
            $this->moduleRequest('site', ['site' => $siteIdentifier], $body),
        );
        self::assertSame(303, $redirect->getStatusCode());
        self::assertStringContainsString('token=', $redirect->getHeaderLine('location'));
        self::assertStringContainsString('site=' . $siteIdentifier, $redirect->getHeaderLine('location'));
        $messages = $this->get(FlashMessageService::class)
            ->getMessageQueueByIdentifier()
            ->getAllMessagesAndFlush();
        self::assertCount(1, $messages);

        return [$redirect, $messages[0]];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function settingsInput(array $overrides = []): array
    {
        return array_replace([
            'apiKey' => '',
            'sitekey' => 'sitekey',
            'theme' => 'light',
            'language' => 'auto',
            'startMode' => 'auto',
            'debug' => '0',
            'customStyles' => '',
            'euIsolation' => '0',
            'customRootDomain' => '',
            'formFrameworkEnabled' => '0',
            'powermailEnabled' => '0',
            'frontendLoginEnabled' => '0',
            'backendLoginEnabled' => '0',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $privateCaptchaConfiguration
     */
    private function writeSite(string $identifier, array $privateCaptchaConfiguration): void
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
            'privateCaptcha' => $privateCaptchaConfiguration,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function privateCaptchaSiteConfiguration(string $identifier): array
    {
        $configuration = $this->get(SiteConfiguration::class)->load($identifier)['privateCaptcha'] ?? null;
        self::assertIsArray($configuration);

        /** @var array<string, mixed> $configuration */
        return $configuration;
    }

    private function backendUser(): BackendUserAuthentication
    {
        /** @var array<string, mixed> $sessionData */
        $sessionData = [];
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'admin' => 1];
        $backendUser->method('isAdmin')->willReturn(true);
        $backendUser->method('getModuleData')->willReturn([]);
        $backendUser->method('getSessionData')->willReturnCallback(
            static function (string $key) use (&$sessionData): mixed {
                return $sessionData[$key] ?? null;
            },
        );
        $backendUser->method('setAndSaveSessionData')->willReturnCallback(
            static function (string $key, mixed $value) use (&$sessionData): void {
                $sessionData[$key] = $value;
            },
        );

        return $backendUser;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $body
     */
    private function moduleRequest(
        string $routeIdentifier,
        array $query,
        array $body,
        string $method = 'POST',
    ): ServerRequest {
        $identifier = 'private_captcha_settings.' . $routeIdentifier;
        $registeredRoute = $this->get(Router::class)->getRoute($identifier);
        self::assertInstanceOf(SymfonyRoute::class, $registeredRoute);
        $options = $registeredRoute->getOptions();
        $options['_identifier'] = $identifier;
        $route = new Route($registeredRoute->getPath(), $options);
        $route->setMethods($registeredRoute->getMethods());
        $module = $route->getOption('module');
        self::assertInstanceOf(Module::class, $module);

        $request = new ServerRequest(
            'https://typo3-testing.local/typo3/module/site/private-captcha/' . $routeIdentifier,
            $method,
            serverParams: [
                'DOCUMENT_ROOT' => $this->instancePath,
                'HTTP_HOST' => 'typo3-testing.local',
                'HTTPS' => 'on',
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => '/typo3/module/site/private-captcha/' . $routeIdentifier,
                'SCRIPT_FILENAME' => $this->instancePath . '/typo3/index.php',
                'SCRIPT_NAME' => '/typo3/index.php',
                'SERVER_PORT' => '443',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
            ],
        );

        return $request
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request))
            ->withAttribute('route', $route)
            ->withAttribute('module', $module)
            ->withQueryParams($query)
            ->withParsedBody($body);
    }
}

final class SettingsActionClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-22T12:34:56+00:00');
    }
}

final class SettingsActionClientFactory implements PrivateCaptchaClientFactoryInterface
{
    public function __construct(
        private readonly Client $client,
    ) {}

    public function create(ResolvedCaptchaConfiguration $configuration): Client
    {
        return $this->client;
    }
}

final class SettingsActionPuzzleClient implements TestPuzzleClientInterface
{
    public function fetch(
        EndpointConfiguration $endpoints,
        string $sitekey,
    ): string {
        return base64_encode(str_repeat('p', 128));
    }
}

final class SettingsActionClient extends Client
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

<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Typo3\Middleware\SettingsTraceRedactionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Routing\Route as SymfonyRoute;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Module\Module;
use TYPO3\CMS\Backend\Routing\Exception\InvalidRequestTokenException;
use TYPO3\CMS\Backend\Routing\Exception\MissingRequestTokenException;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SettingsCsrfTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'form',
    ];

    protected array $testExtensionsToLoad = [
        'private-captcha/typo3',
    ];

    protected bool $initializeDatabase = false;

    private mixed $previousLanguageService = null;

    private mixed $previousBackendUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousLanguageService = $GLOBALS['LANG'] ?? null;
        $this->previousBackendUser = $GLOBALS['BE_USER'] ?? null;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences(null);
        $GLOBALS['BE_USER'] = $this->backendUser();
        $this->get(SiteWriter::class)->write('csrf-site', [
            'rootPageId' => 1,
            'base' => 'https://csrf-site.test/',
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
            'privateCaptcha' => $this->originalConfiguration(),
        ]);
    }

    protected function tearDown(): void
    {
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
    public function settingsActionsRejectMissingInvalidAndWrongRouteTokensBeforeMutation(): void
    {
        $dispatcher = $this->get(RouteDispatcher::class);
        $request = $this->moduleRequest();
        $route = $this->get(Router::class)->getRoute('private_captcha_settings.site');
        self::assertInstanceOf(SymfonyRoute::class, $route);
        self::assertSame('admin', $route->getOption('access'));
        $wrongRouteToken = $this->get(FormProtectionFactory::class)
            ->createFromRequest($request)
            ->generateToken('route', 'private_captcha_settings.backend');
        $previousIgnoreArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            foreach (['save', 'test', 'reset'] as $action) {
                try {
                    $this->dispatch($dispatcher, $request->withParsedBody([
                        'settingsAction' => $action,
                        'settings' => ['apiKey' => 'submitted-secret'],
                    ]));
                    self::fail('A missing route token must reject the settings action.');
                } catch (MissingRequestTokenException $exception) {
                    self::assertSame($this->originalConfiguration(), $this->persistedConfiguration());
                    self::assertStringNotContainsString('submitted-secret', print_r($exception->getTrace(), true));
                    self::assertSame('0', ini_get('zend.exception_ignore_args'));
                }

                foreach (['invalid-token', $wrongRouteToken] as $invalidToken) {
                    try {
                        $this->dispatch($dispatcher, $request->withParsedBody([
                            'token' => $invalidToken,
                            'settingsAction' => $action,
                            'settings' => ['apiKey' => 'submitted-secret'],
                        ]));
                        self::fail('An invalid route token must reject the settings action.');
                    } catch (InvalidRequestTokenException $exception) {
                        self::assertSame($this->originalConfiguration(), $this->persistedConfiguration());
                        self::assertStringNotContainsString('submitted-secret', print_r($exception->getTrace(), true));
                        self::assertSame('0', ini_get('zend.exception_ignore_args'));
                    }
                }
            }
        } finally {
            if ($previousIgnoreArguments !== false) {
                ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
            }
        }
    }

    #[Test]
    public function validRouteTokenAllowsAuthorizedResetForTheSelectedScope(): void
    {
        $request = $this->moduleRequest();
        $token = $this->get(FormProtectionFactory::class)
            ->createFromRequest($request)
            ->generateToken('route', 'private_captcha_settings.site');
        $previousIgnoreArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            $response = $this->dispatch($this->get(RouteDispatcher::class), $request->withParsedBody([
                'token' => $token,
                'settingsAction' => 'reset',
                'settings' => ['apiKey' => 'ignored-secret'],
            ]));
            self::assertSame('0', ini_get('zend.exception_ignore_args'));
        } finally {
            if ($previousIgnoreArguments !== false) {
                ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
            }
        }

        self::assertSame(303, $response->getStatusCode());
        self::assertSame([], $this->persistedConfiguration());
        self::assertStringContainsString('token=', $response->getHeaderLine('location'));
        self::assertStringContainsString('site=csrf-site', $response->getHeaderLine('location'));
        self::assertStringNotContainsString('ignored-secret', $response->getHeaderLine('location'));
    }

    private function dispatch(RouteDispatcher $dispatcher, ServerRequestInterface $request): ResponseInterface
    {
        return $this->get(SettingsTraceRedactionMiddleware::class)->process(
            $request,
            new SettingsCsrfRouteHandler($dispatcher),
        );
    }

    private function moduleRequest(): ServerRequest
    {
        $identifier = 'private_captcha_settings.site';
        $registeredRoute = $this->get(Router::class)->getRoute($identifier);
        self::assertInstanceOf(SymfonyRoute::class, $registeredRoute);
        $options = $registeredRoute->getOptions();
        $options['_identifier'] = $identifier;
        $route = new Route($registeredRoute->getPath(), $options);
        $route->setMethods($registeredRoute->getMethods());
        $module = $route->getOption('module');
        self::assertInstanceOf(Module::class, $module);
        $request = new ServerRequest(
            'https://typo3-testing.local/typo3/module/site/private-captcha/site',
            'POST',
            serverParams: [
                'DOCUMENT_ROOT' => $this->instancePath,
                'HTTP_HOST' => 'typo3-testing.local',
                'HTTPS' => 'on',
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/typo3/module/site/private-captcha/site',
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
            ->withQueryParams(['site' => 'csrf-site']);
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
     * @return array<string, mixed>
     */
    private function originalConfiguration(): array
    {
        return [
            'apiKey' => 'persisted-secret',
            'sitekey' => 'persisted-sitekey',
            'formFrameworkEnabled' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedConfiguration(): array
    {
        $configuration = $this->get(SiteConfiguration::class)->load('csrf-site')['privateCaptcha'] ?? null;
        self::assertIsArray($configuration);

        /** @var array<string, mixed> $configuration */
        return $configuration;
    }
}

final readonly class SettingsCsrfRouteHandler implements RequestHandlerInterface
{
    public function __construct(
        private RouteDispatcher $dispatcher,
    ) {}

    public function handle(#[\SensitiveParameter] ServerRequestInterface $request): ResponseInterface
    {
        return $this->dispatcher->dispatch($request);
    }
}

<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Typo3\Controller\SettingsController;
use Symfony\Component\Routing\Route as SymfonyRoute;
use TYPO3\CMS\Backend\Module\Module;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SettingsModuleAccessTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'form',
    ];

    protected array $testExtensionsToLoad = [
        'private-captcha/typo3',
    ];

    protected bool $initializeDatabase = false;

    #[Test]
    public function settingsModuleRestrictsAccessAndSelectsOnlyConfiguredScopes(): void
    {
        $moduleProvider = $this->get(ModuleProvider::class);
        $administrator = $this->createMock(BackendUserAuthentication::class);
        $administrator->method('isAdmin')->willReturn(true);
        $editor = $this->createMock(BackendUserAuthentication::class);
        $editor->method('isAdmin')->willReturn(false);

        self::assertTrue($moduleProvider->accessGranted('private_captcha_settings', $administrator, false));
        self::assertFalse($moduleProvider->accessGranted('private_captcha_settings', $editor, false));
        $controller = $this->get(SettingsController::class);
        try {
            $controller->siteAction(
                (new ServerRequest('https://typo3-testing.local/typo3/module/site/private-captcha/site'))
                    ->withQueryParams(['site' => '../config/system/settings.php']),
            );
            self::fail('Unknown site identifiers must not select a settings scope.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $this->get(SiteWriter::class)->write('site-a', [
            'rootPageId' => 1,
            'base' => 'https://site-a.test/',
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
        ]);
        $this->get(SiteWriter::class)->write('0', [
            'rootPageId' => 2,
            'base' => 'https://site-zero.test/',
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
        ]);
        $previousLanguageService = $GLOBALS['LANG'] ?? null;
        $previousBackendUser = $GLOBALS['BE_USER'] ?? null;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences(null);
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);
        $backendUser->method('getModuleData')->willReturn([]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $overview = $controller->indexAction($this->moduleRequest('_default'));
            $site = $controller->siteAction($this->moduleRequest('site', ['site' => 'site-a']));
            $numericSite = $controller->siteAction($this->moduleRequest('site', ['site' => '0']));
            $backend = $controller->backendAction($this->moduleRequest('backend'));
        } finally {
            if ($previousLanguageService === null) {
                unset($GLOBALS['LANG']);
            } else {
                $GLOBALS['LANG'] = $previousLanguageService;
            }
            if ($previousBackendUser === null) {
                unset($GLOBALS['BE_USER']);
            } else {
                $GLOBALS['BE_USER'] = $previousBackendUser;
            }
        }

        self::assertSame(200, $overview->getStatusCode());
        self::assertSame(200, $site->getStatusCode());
        self::assertSame(200, $numericSite->getStatusCode());
        self::assertSame(200, $backend->getStatusCode());
    }

    /**
     * @param array<string, string> $query
     */
    private function moduleRequest(string $routeIdentifier, array $query = []): ServerRequest
    {
        $identifier = $routeIdentifier === '_default'
            ? 'private_captcha_settings'
            : 'private_captcha_settings.' . $routeIdentifier;
        $registeredRoute = $this->get(Router::class)->getRoute($identifier);
        self::assertInstanceOf(SymfonyRoute::class, $registeredRoute);
        $options = $registeredRoute->getOptions();
        $options['_identifier'] = $identifier;
        $route = new Route($registeredRoute->getPath(), $options);
        $route->setMethods($registeredRoute->getMethods());
        $module = $route->getOption('module');
        self::assertInstanceOf(Module::class, $module);

        $request = new ServerRequest(
            'https://typo3-testing.local/typo3/module/site/private-captcha',
            'GET',
            serverParams: [
                'DOCUMENT_ROOT' => $this->instancePath,
                'HTTP_HOST' => 'typo3-testing.local',
                'HTTPS' => 'on',
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/typo3/module/site/private-captcha',
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
            ->withQueryParams($query);
    }
}

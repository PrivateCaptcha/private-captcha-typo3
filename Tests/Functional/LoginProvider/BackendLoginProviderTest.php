<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\LoginProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\LoginProvider\PrivateCaptchaLoginProvider;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BackendLoginProviderTest extends FunctionalTestCase
{
    private const NATIVE_PROVIDER_IDENTIFIER = 1433416747;

    protected array $coreExtensionsToLoad = [
        'form',
    ];

    protected array $testExtensionsToLoad = [
        'private-captcha/typo3',
    ];

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    private bool $hadExtensionConfiguration = false;

    private mixed $originalExtensionConfiguration = null;

    private mixed $originalLanguageService = null;

    /** @var array<array-key, mixed> */
    private array $assetState = [];

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
        $this->originalLanguageService = $GLOBALS['LANG'] ?? null;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences(null);
        $assetCollector = $this->get(AssetCollector::class);
        $this->assetState = $assetCollector->getState();
        $assetCollector->updateState(array_map(
            static fn(): array => [],
            $this->assetState,
        ));
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
        if ($this->originalLanguageService === null) {
            unset($GLOBALS['LANG']);
        } else {
            $GLOBALS['LANG'] = $this->originalLanguageService;
        }
        $this->get(AssetCollector::class)->updateState($this->assetState);

        parent::tearDown();
    }

    #[Test]
    public function nativeUsernamePasswordProviderIsReplaced(): void
    {
        $provider = $this->nativeProviderEntry();

        self::assertSame(PrivateCaptchaLoginProvider::class, $provider['provider'] ?? null);
    }

    /**
     * @param array<string, mixed> $backendConfiguration
     */
    #[Test]
    #[DataProvider('inactiveConfigurationProvider')]
    public function inactiveConfigurationUsesNativeProviderWithoutCaptchaAssets(array $backendConfiguration): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => $backendConfiguration]);
        $view = new RecordingBackendLoginView();

        $template = $this->provider()->modifyView($this->backendRequest(), $view);

        self::assertSame('Login/UserPassLoginForm', $template);
        self::assertArrayHasKey('enablePasswordReset', $view->variables);
        self::assertArrayNotHasKey('privateCaptchaMarkup', $view->variables);
        self::assertSame([], $this->get(AssetCollector::class)->getJavaScripts());
        self::assertSame([], $this->get(AssetCollector::class)->getJavaScriptModules());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function inactiveConfigurationProvider(): iterable
    {
        yield 'explicitly disabled' => [[
            'apiKey' => bin2hex(random_bytes(16)),
            'sitekey' => 'backend-property',
            'backendLoginEnabled' => '0',
        ]];
        yield 'missing API key' => [[
            'apiKey' => '',
            'sitekey' => 'backend-property',
            'backendLoginEnabled' => '1',
        ]];
        yield 'missing sitekey' => [[
            'apiKey' => bin2hex(random_bytes(16)),
            'sitekey' => '',
            'backendLoginEnabled' => '1',
        ]];
    }

    #[Test]
    public function emergencyDisableUsesNativeProviderBeforeReadingMalformedPersistence(): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => 'malformed']);
        putenv(ConfigurationResolver::DISABLE_BACKEND_LOGIN_ENV . '=true');
        $view = new RecordingBackendLoginView();

        $template = $this->provider()->modifyView($this->backendRequest(), $view);

        self::assertSame('Login/UserPassLoginForm', $template);
        self::assertArrayNotHasKey('privateCaptchaMarkup', $view->variables);
        self::assertSame([], $this->get(AssetCollector::class)->getJavaScripts());
    }

    #[Test]
    public function invalidConfigurationFallsBackToNativeProvider(): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => $this->configuration([
            'customRootDomain' => 'https://invalid.example',
        ])]);
        $view = new RecordingBackendLoginView();

        $template = $this->provider()->modifyView($this->backendRequest(), $view);

        self::assertSame('Login/UserPassLoginForm', $template);
        self::assertArrayNotHasKey('privateCaptchaMarkup', $view->variables);
        self::assertSame([], $this->get(AssetCollector::class)->getJavaScripts());
    }

    #[Test]
    public function enabledConfigurationRendersWidgetInsideNativeLoginForm(): void
    {
        $apiKey = bin2hex(random_bytes(16));
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => $this->configuration([
            'apiKey' => $apiKey,
            'sitekey' => 'backend-property',
            'theme' => 'dark',
            'language' => 'de',
            'startMode' => 'click',
            'backendLoginEnabled' => '1',
        ])]);
        $request = $this->backendRequest();
        $view = $this->get(BackendViewFactory::class)->create($request, ['typo3/cms-backend']);
        self::assertInstanceOf(FluidViewAdapter::class, $view);
        $view->assignMultiple($this->loginViewVariables());

        $template = $this->provider()->modifyView($request, $view);
        $html = $view->render($template);
        $login = $this->xpath($html);

        self::assertSame('BackendLogin/Login', $template);
        self::assertSame(1.0, $login->evaluate('count(//*[@id="typo3-login-form"])'));
        self::assertSame(1.0, $login->evaluate('count(//*[@id="t3-username"][@name="username"])'));
        self::assertSame(1.0, $login->evaluate('count(//*[@id="t3-password"][@name="p_field"])'));
        self::assertSame(1.0, $login->evaluate(
            'count(//*[@id="typo3-login-form"][@action="/typo3/login"]//input[@name="login_status"][@value="login"])',
        ));
        self::assertSame(1.0, $login->evaluate(
            'count(//*[@id="typo3-login-form"]//input[@name="userident"][@id="t3-field-userident"])',
        ));
        self::assertSame(1.0, $login->evaluate(
            'count(//*[@id="typo3-login-form"]//input[@name="__RequestToken"][@value="request-token"])',
        ));
        self::assertSame(1.0, $login->evaluate('count(//*[@id="typo3-login-form"]//*[@data-private-captcha-widget="true"])'));
        self::assertStringContainsString('data-sitekey="backend-property"', $html);
        self::assertStringContainsString('data-theme="dark"', $html);
        self::assertStringContainsString('data-lang="de"', $html);
        self::assertStringContainsString('data-start-mode="click"', $html);
        self::assertStringContainsString('data-solution-field="' . Client::DEFAULT_FORM_FIELD . '"', $html);
        self::assertStringNotContainsString($apiKey, $html);
        self::assertArrayHasKey('private-captcha/widget', $this->get(AssetCollector::class)->getJavaScripts());
        self::assertContains(
            '@private-captcha/typo3/private-captcha.js',
            $this->get(AssetCollector::class)->getJavaScriptModules(),
        );
    }

    #[Test]
    public function enabledConfigurationDoesNotCollectWidgetForLogoutView(): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => $this->configuration()]);
        $request = $this->backendRequest();
        $view = $this->get(BackendViewFactory::class)->create($request, ['typo3/cms-backend']);
        self::assertInstanceOf(FluidViewAdapter::class, $view);
        $view->assignMultiple(array_replace($this->loginViewVariables(), [
            'action' => 'logout',
            'backendUser' => ['username' => 'backend-user'],
        ]));

        $template = $this->provider()->modifyView($request, $view);
        $html = $view->render($template);

        self::assertSame('Login/UserPassLoginForm', $template);
        self::assertStringNotContainsString('data-private-captcha-widget="true"', $html);
        self::assertSame([], $this->get(AssetCollector::class)->getJavaScripts());
        self::assertNotContains(
            '@private-captcha/typo3/private-captcha.js',
            $this->get(AssetCollector::class)->getJavaScriptModules(),
        );
    }

    private function provider(): PrivateCaptchaLoginProvider
    {
        return $this->get(PrivateCaptchaLoginProvider::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function nativeProviderEntry(): array
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        self::assertIsArray($typo3Configuration);
        $extensionConfiguration = $typo3Configuration['EXTCONF'] ?? null;
        self::assertIsArray($extensionConfiguration);
        $backendConfiguration = $extensionConfiguration['backend'] ?? null;
        self::assertIsArray($backendConfiguration);
        $providers = $backendConfiguration['loginProviders'] ?? null;
        self::assertIsArray($providers);
        $provider = $providers[self::NATIVE_PROVIDER_IDENTIFIER] ?? null;
        self::assertIsArray($provider);

        /** @var array<string, mixed> $provider */
        return $provider;
    }

    private function backendRequest(bool $https = true): ServerRequestInterface
    {
        $scheme = $https ? 'https' : 'http';

        return (new ServerRequest($scheme . '://localhost/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', $this->normalizedParams($https));
    }

    private function normalizedParams(bool $https): NormalizedParams
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        self::assertIsArray($typo3Configuration);
        $systemConfiguration = $typo3Configuration['SYS'] ?? null;
        self::assertIsArray($systemConfiguration);
        $serverParams = [
            'REQUEST_URI' => '/typo3/',
            'HTTP_HOST' => 'localhost',
            'DOCUMENT_ROOT' => Environment::getPublicPath(),
            'SCRIPT_FILENAME' => Environment::getPublicPath() . '/index.php',
            'SCRIPT_NAME' => '/index.php',
        ];
        if ($https) {
            $serverParams['HTTPS'] = 'on';
        }

        return new NormalizedParams(
            $serverParams,
            $systemConfiguration,
            Environment::getPublicPath() . '/index.php',
            Environment::getPublicPath(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loginViewVariables(): array
    {
        return [
            'copyright' => '',
            'loginFootnote' => '',
            'referrerCheckEnabled' => false,
            'loginUrl' => 'https://localhost/typo3/',
            'loginProviderIdentifier' => self::NATIVE_PROVIDER_IDENTIFIER,
            'backendUser' => [],
            'hasLoginError' => false,
            'action' => 'login',
            'formActionUrl' => '/typo3/login',
            'requestTokenName' => '__RequestToken',
            'requestTokenValue' => 'request-token',
            'forgetPasswordUrl' => '/typo3/password-forget',
            'loginRefresh' => false,
            'loginProviders' => [],
            'loginNewsItems' => [],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function configuration(array $overrides = []): array
    {
        return array_replace([
            'apiKey' => bin2hex(random_bytes(16)),
            'sitekey' => 'backend-property',
            'theme' => 'light',
            'language' => 'auto',
            'startMode' => 'auto',
            'debug' => '0',
            'customStyles' => '',
            'formFrameworkEnabled' => '0',
            'powermailEnabled' => '0',
            'frontendLoginEnabled' => '0',
            'backendLoginEnabled' => '1',
            'euIsolation' => '0',
            'customRootDomain' => '',
        ], $overrides);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING));

        return new \DOMXPath($document);
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

final class RecordingBackendLoginView implements ViewInterface
{
    /** @var array<string, mixed> */
    public array $variables = [];

    public function assign(string $key, mixed $value): self
    {
        $this->variables[$key] = $value;

        return $this;
    }

    public function assignMultiple(array $values): self
    {
        $this->variables = array_replace($this->variables, $values);

        return $this;
    }

    public function render(string $templateFileName = ''): string
    {
        return '';
    }
}

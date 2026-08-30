<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\EventListener;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Service\WidgetAssetService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Policy;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ContentSecurityPolicyListenerTest extends FunctionalTestCase
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
    #[DataProvider('activeFrontendConfigurationProvider')]
    public function activeFrontendConfigurationExtendsRequiredDirectives(
        string $integration,
        bool $euIsolation,
    ): void {
        $existingSource = new UriValue('https://existing.example.org');
        $existingImage = new UriValue('https://images.example.org');
        $currentPolicy = new Policy(SourceKeyword::self);
        foreach ($this->captchaDirectives() as $directive) {
            $currentPolicy = $currentPolicy->set($directive, SourceKeyword::self, $existingSource);
        }
        $currentPolicy = $currentPolicy->set(Directive::ImgSrc, SourceKeyword::self, $existingImage);
        $request = $this->frontendRequest($this->configuration([
            $integration => '1',
            'euIsolation' => $euIsolation ? '1' : '0',
        ]));

        $event = $this->dispatch(Scope::frontend(), $request, $currentPolicy);

        $this->assertCaptchaSources(
            $event->getCurrentPolicy(),
            new UriValue('https://privatecaptcha.com'),
            new UriValue('https://*.privatecaptcha.com'),
        );
        self::assertTrue($event->getCurrentPolicy()->containsDirective(Directive::DefaultSrc, SourceKeyword::self));
        self::assertTrue($event->getCurrentPolicy()->containsDirective(Directive::ImgSrc, $existingImage));
        foreach ($this->captchaDirectives() as $directive) {
            self::assertTrue($event->getCurrentPolicy()->containsDirective($directive, $existingSource));
            $sources = $event->getCurrentPolicy()->get($directive);
            self::assertInstanceOf(SourceCollection::class, $sources);
            self::assertCount(4, $sources->sources);
        }
        self::assertFalse($event->isPropagationStopped());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function activeFrontendConfigurationProvider(): iterable
    {
        yield 'Form Framework' => ['formFrameworkEnabled', false];
        yield 'Powermail' => ['powermailEnabled', false];
        yield 'frontend login' => ['frontendLoginEnabled', false];
        yield 'EU isolation' => ['formFrameworkEnabled', true];
    }

    #[Test]
    public function customFrontendConfigurationAddsOnlyDerivedOrigins(): void
    {
        $request = $this->frontendRequest($this->configuration([
            'formFrameworkEnabled' => '1',
            'customRootDomain' => 'custom.privatecaptcha.com',
        ]));

        $policy = $this->dispatch(Scope::frontend(), $request)->getCurrentPolicy();

        $this->assertCaptchaSources(
            $policy,
            new UriValue('https://api.custom.privatecaptcha.com'),
            new UriValue('https://cdn.custom.privatecaptcha.com'),
        );
        foreach ($this->captchaDirectives() as $directive) {
            $sources = $policy->get($directive);
            self::assertInstanceOf(SourceCollection::class, $sources);
            self::assertCount(3, $sources->sources);
            self::assertFalse($policy->containsDirective($directive, new UriValue('https://privatecaptcha.com')));
            self::assertFalse($policy->containsDirective($directive, new UriValue('https://*.privatecaptcha.com')));
            self::assertFalse($policy->containsDirective($directive, new UriValue('https://custom.privatecaptcha.com')));
        }
    }

    /**
     * @param array<string, string> $configuration
     */
    #[Test]
    #[DataProvider('inactiveFrontendConfigurationProvider')]
    public function inactiveFrontendConfigurationDoesNotBroadenPolicy(array $configuration): void
    {
        $currentPolicy = new Policy(SourceKeyword::self);
        $request = $this->frontendRequest($this->configuration($configuration));

        $event = $this->dispatch(Scope::frontend(), $request, $currentPolicy);

        self::assertSame($currentPolicy, $event->getCurrentPolicy());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function inactiveFrontendConfigurationProvider(): iterable
    {
        yield 'all integrations disabled' => [[]];
        yield 'enabled integration without API key' => [[
            'apiKey' => '',
            'formFrameworkEnabled' => '1',
        ]];
        yield 'enabled integration without sitekey' => [[
            'sitekey' => '',
            'formFrameworkEnabled' => '1',
        ]];
        yield 'backend integration in frontend scope' => [[
            'backendLoginEnabled' => '1',
        ]];
    }

    #[Test]
    public function malformedInactiveFrontendConfigurationDoesNotBreakPolicy(): void
    {
        $currentPolicy = new Policy(SourceKeyword::self);
        $request = $this->frontendRequest($this->configuration([
            'customRootDomain' => 'https://invalid.example',
        ]));

        $event = $this->dispatch(Scope::frontend(), $request, $currentPolicy);

        self::assertSame($currentPolicy, $event->getCurrentPolicy());
    }

    #[Test]
    public function frontendScopeWithoutSiteDoesNotBroadenPolicy(): void
    {
        $currentPolicy = new Policy(SourceKeyword::self);

        $event = $this->dispatch(Scope::frontend(), new ServerRequest('https://site.test/'), $currentPolicy);

        self::assertSame($currentPolicy, $event->getCurrentPolicy());
    }

    #[Test]
    #[DataProvider('activeBackendConfigurationProvider')]
    public function activeBackendConfigurationExtendsRequiredDirectives(
        string $customRootDomain,
        string $expectedApiOrigin,
        string $expectedCdnOrigin,
    ): void {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => $this->configuration([
            'backendLoginEnabled' => '1',
            'customRootDomain' => $customRootDomain,
        ])]);
        $this->collectBackendWidgetAssets();

        $policy = $this->dispatch(Scope::backend(), null)->getCurrentPolicy();

        $this->assertCaptchaSources(
            $policy,
            new UriValue($expectedApiOrigin),
            new UriValue($expectedCdnOrigin),
        );
    }

    #[Test]
    public function activeBackendConfigurationWithoutWidgetDoesNotBroadenPolicy(): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => $this->configuration([
            'backendLoginEnabled' => '1',
        ])]);
        $currentPolicy = new Policy(SourceKeyword::self);

        $event = $this->dispatch(Scope::backend(), null, $currentPolicy);

        self::assertSame($currentPolicy, $event->getCurrentPolicy());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function activeBackendConfigurationProvider(): iterable
    {
        yield 'official deployment' => [
            '',
            'https://privatecaptcha.com',
            'https://*.privatecaptcha.com',
        ];
        yield 'custom deployment' => [
            'custom.privatecaptcha.com',
            'https://api.custom.privatecaptcha.com',
            'https://cdn.custom.privatecaptcha.com',
        ];
    }

    /**
     * @param array<string, string> $configuration
     */
    #[Test]
    #[DataProvider('inactiveBackendConfigurationProvider')]
    public function inactiveBackendConfigurationDoesNotBroadenPolicy(array $configuration): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => $this->configuration($configuration)]);
        $currentPolicy = new Policy(SourceKeyword::self);

        $event = $this->dispatch(Scope::backend(), null, $currentPolicy);

        self::assertSame($currentPolicy, $event->getCurrentPolicy());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function inactiveBackendConfigurationProvider(): iterable
    {
        yield 'backend integration disabled' => [[]];
        yield 'frontend integration in backend scope' => [[
            'formFrameworkEnabled' => '1',
        ]];
        yield 'backend integration without credentials' => [[
            'apiKey' => '',
            'backendLoginEnabled' => '1',
        ]];
    }

    #[Test]
    public function emergencyDisabledBackendConfigurationDoesNotBroadenPolicy(): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => $this->configuration([
            'backendLoginEnabled' => '1',
        ])]);
        putenv(ConfigurationResolver::DISABLE_BACKEND_LOGIN_ENV . '=true');
        $currentPolicy = new Policy(SourceKeyword::self);

        $event = $this->dispatch(Scope::backend(), null, $currentPolicy);

        self::assertSame($currentPolicy, $event->getCurrentPolicy());
    }

    private function dispatch(
        Scope $scope,
        ?ServerRequestInterface $request,
        ?Policy $currentPolicy = null,
    ): PolicyMutatedEvent {
        $defaultPolicy = new Policy(SourceKeyword::self);
        $event = new PolicyMutatedEvent(
            $scope,
            $request,
            $defaultPolicy,
            $currentPolicy ?? $defaultPolicy,
        );
        $this->get(EventDispatcherInterface::class)->dispatch($event);

        return $event;
    }

    private function collectBackendWidgetAssets(): void
    {
        $configuration = $this->get(ConfigurationResolver::class)->resolveBackend();
        $this->get(WidgetAssetService::class)->collect($configuration->endpoints);
    }

    private function assertCaptchaSources(Policy $policy, UriValue ...$sources): void
    {
        foreach ($this->captchaDirectives() as $directive) {
            self::assertTrue(
                $policy->containsDirective($directive, ...$sources),
                sprintf('Expected %s to contain all Private Captcha sources.', $directive->value),
            );
        }
    }

    /**
     * @return list<Directive>
     */
    private function captchaDirectives(): array
    {
        return [
            Directive::ScriptSrc,
            Directive::FrameSrc,
            Directive::StyleSrc,
            Directive::ConnectSrc,
        ];
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function configuration(array $overrides = []): array
    {
        return array_replace([
            'apiKey' => str_repeat('a', 32),
            'sitekey' => 'captcha-property',
            'formFrameworkEnabled' => '0',
            'powermailEnabled' => '0',
            'frontendLoginEnabled' => '0',
            'backendLoginEnabled' => '0',
            'euIsolation' => '0',
            'customRootDomain' => '',
        ], $overrides);
    }

    /**
     * @param array<string, string> $configuration
     */
    private function frontendRequest(array $configuration): ServerRequestInterface
    {
        $site = new Site('site-a', 1, [
            'base' => 'https://site-a.test/',
            'privateCaptcha' => $configuration,
        ]);

        return (new ServerRequest('https://site-a.test/'))->withAttribute('site', $site);
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

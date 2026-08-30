<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Authentication;

use GuzzleHttp\Cookie\SetCookie;
use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\Authentication\FrontendCaptchaAuthenticationService;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Service\CaptchaVerifierInterface;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\VerificationResult;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\FrontendLogin\Event\ModifyLoginFormViewEvent;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FrontendLoginRenderingTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'form',
        'felogin',
    ];

    protected array $testExtensionsToLoad = [
        'private-captcha/typo3',
    ];

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    /** @var array<array-key, mixed> */
    private array $assetState = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->environmentVariableNames() as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
        }
        $assetCollector = $this->get(AssetCollector::class);
        $this->assetState = $assetCollector->getState();
        $assetCollector->updateState(array_map(
            static fn(): array => [],
            $this->assetState,
        ));
        $this->importCSVDataSet(__DIR__ . '/Fixtures/FrontendLoginPages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/FrontendUsers.csv');
        $this->setUpFrontendRootPage(1, [
            'EXT:private_captcha/Tests/Functional/Authentication/Fixtures/FrontendLogin.typoscript',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $this->get(AssetCollector::class)->updateState($this->assetState);

        parent::tearDown();
    }

    #[Test]
    public function listenerUsesOnlyTheCurrentRequestSite(): void
    {
        $this->dispatch($this->site([
            'apiKey' => bin2hex(random_bytes(16)),
            'sitekey' => 'enabled-property',
            'frontendLoginEnabled' => '1',
        ], 'enabled-site'));
        $this->get(AssetCollector::class)->updateState(array_map(
            static fn(): array => [],
            $this->get(AssetCollector::class)->getState(),
        ));

        $disabledView = $this->dispatch($this->site([
            'apiKey' => bin2hex(random_bytes(16)),
            'sitekey' => 'disabled-property',
            'frontendLoginEnabled' => '0',
        ], 'disabled-site'));

        self::assertSame('', $disabledView->variables['privateCaptchaMarkup'] ?? null);
        self::assertSame([], $this->get(AssetCollector::class)->getJavaScripts());
    }

    #[Test]
    public function missingSiteOrInvalidConfigurationRendersNoWidget(): void
    {
        $missingSiteView = $this->dispatch(null);
        $unconfiguredSiteView = $this->dispatch(new Site('unconfigured-site', 1, [
            'base' => 'https://unconfigured-site.test/',
        ]));
        $malformedSiteView = $this->dispatch(new Site('malformed-site', 1, [
            'base' => 'https://malformed-site.test/',
            'privateCaptcha' => 'invalid',
        ]));
        $invalidView = $this->dispatch($this->site([
            'apiKey' => bin2hex(random_bytes(16)),
            'sitekey' => 'frontend-login-property',
            'customRootDomain' => 'https://invalid.example',
            'frontendLoginEnabled' => '1',
        ]));

        self::assertSame('', $missingSiteView->variables['privateCaptchaMarkup'] ?? null);
        self::assertSame('', $unconfiguredSiteView->variables['privateCaptchaMarkup'] ?? null);
        self::assertSame('', $malformedSiteView->variables['privateCaptchaMarkup'] ?? null);
        self::assertSame('', $invalidView->variables['privateCaptchaMarkup'] ?? null);
        self::assertSame([], $this->get(AssetCollector::class)->getJavaScripts());
    }

    #[Test]
    public function enabledSiteRendersWidgetInsideNativeLoginButNotRecovery(): void
    {
        $apiKey = bin2hex(random_bytes(16));
        $this->writeFrontendSite(true, $apiKey);

        $loginResponse = $this->executeFrontendSubRequest((new InternalRequest())->withPageId(1));
        $loginHtml = (string)$loginResponse->getBody();
        $login = $this->xpath($loginHtml);

        self::assertSame(200, $loginResponse->getStatusCode());
        self::assertSame(1.0, $login->evaluate('count(//form[.//*[@id="tx-felogin-input-username"]]//*[@data-private-captcha-widget="true"])'));
        self::assertSame(1.0, $login->evaluate('count(//*[@id="tx-felogin-input-password"])'));
        self::assertSame(1.0, $login->evaluate('count(//input[@name="logintype"][@value="login"])'));
        self::assertStringContainsString('data-sitekey="frontend-login-property"', $loginHtml);
        self::assertStringContainsString('https://cdn.privatecaptcha.com/widget/js/privatecaptcha.js', $loginHtml);
        self::assertStringContainsString('@private-captcha/typo3/private-captcha.js', $loginHtml);
        self::assertStringNotContainsString($apiKey, $loginHtml);

        $recoveryLinks = $login->query('//a');
        self::assertInstanceOf(\DOMNodeList::class, $recoveryLinks);
        self::assertCount(1, $recoveryLinks);
        $recoveryLink = $recoveryLinks->item(0);
        self::assertInstanceOf(\DOMElement::class, $recoveryLink);
        $recoveryHref = $recoveryLink->getAttribute('href');
        self::assertNotSame('', $recoveryHref);

        $recoveryResponse = $this->executeFrontendSubRequest(new InternalRequest('http://localhost' . $recoveryHref));
        $recoveryHtml = (string)$recoveryResponse->getBody();
        $recovery = $this->xpath($recoveryHtml);

        self::assertSame(200, $recoveryResponse->getStatusCode());
        self::assertSame(1.0, $recovery->evaluate('count(//*[@id="tx-felogin-input-data"])'));
        self::assertSame(0.0, $recovery->evaluate('count(//*[@data-private-captcha-widget="true"])'));
        self::assertStringNotContainsString('cdn.privatecaptcha.com', $recoveryHtml);
        self::assertStringNotContainsString('@private-captcha/typo3/private-captcha.js', $recoveryHtml);
    }

    #[Test]
    public function nativeLoginSubmissionAuthenticatesAfterAcceptedCaptcha(): void
    {
        $this->writeFrontendSite(true, bin2hex(random_bytes(16)));
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with(
                'accepted-solution',
                self::callback(static fn(ResolvedCaptchaConfiguration $configuration): bool => $configuration->sitekey === 'frontend-login-property'),
            )
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        GeneralUtility::addInstance(
            FrontendCaptchaAuthenticationService::class,
            new FrontendCaptchaAuthenticationService(
                $this->get(ConfigurationResolver::class),
                $verifier,
            ),
        );

        $formResponse = $this->executeFrontendSubRequest((new InternalRequest())->withPageId(1));
        $form = $this->xpath((string)$formResponse->getBody());
        $formElements = $form->query('//form[.//*[@id="tx-felogin-input-username"]]');
        self::assertInstanceOf(\DOMNodeList::class, $formElements);
        $formElement = $formElements->item(0);
        self::assertInstanceOf(\DOMElement::class, $formElement);
        $body = [];
        foreach ($form->query('.//input[@name]', $formElement) ?: [] as $input) {
            self::assertInstanceOf(\DOMElement::class, $input);
            $body[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $body['user'] = 'testuser';
        $body['pass'] = 'test';
        $body[Client::DEFAULT_FORM_FIELD] = 'accepted-solution';
        $nonce = $this->responseCookie($formResponse, 'typo3nonce_');
        self::assertNotNull($nonce);
        self::assertIsString($nonce->getValue());

        $response = $this->executeFrontendSubRequest(
            (new InternalRequest('http://localhost' . $formElement->getAttribute('action')))
                ->withPageId(1)
                ->withMethod('POST')
                ->withParsedBody($body)
                ->withCookieParams([$nonce->getName() => $nonce->getValue()]),
        );

        self::assertNotNull($this->responseCookie($response, 'fe_typo_user'));
    }

    private function dispatch(?Site $site): RecordingView
    {
        $view = new RecordingView();
        $request = (new ServerRequest('https://site.test/login'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        if ($site !== null) {
            $request = $request->withAttribute('site', $site);
        }
        $event = new ModifyLoginFormViewEvent($view, $request);

        $this->get(EventDispatcherInterface::class)->dispatch($event);

        return $view;
    }

    /**
     * @param array<string, string> $configuration
     */
    private function site(array $configuration, string $identifier = 'site'): Site
    {
        return new Site($identifier, 1, [
            'base' => 'https://' . $identifier . '.test/',
            'privateCaptcha' => $configuration,
        ]);
    }

    private function writeFrontendSite(bool $enabled, string $apiKey): void
    {
        $this->get(SiteWriter::class)->write('frontend-login', [
            'rootPageId' => 1,
            'base' => 'http://localhost/',
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
            'privateCaptcha' => [
                'apiKey' => $apiKey,
                'sitekey' => 'frontend-login-property',
                'frontendLoginEnabled' => $enabled ? '1' : '0',
            ],
        ]);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING));

        return new \DOMXPath($document);
    }

    private function responseCookie(ResponseInterface $response, string $namePrefix): ?SetCookie
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, $namePrefix)) {
                return SetCookie::fromString($header);
            }
        }

        return null;
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

}

final class RecordingView implements ViewInterface
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

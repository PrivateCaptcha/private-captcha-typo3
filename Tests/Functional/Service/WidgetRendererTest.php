<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Typo3\Service\WidgetAssetService;
use PrivateCaptcha\Typo3\Service\WidgetRenderer;
use PrivateCaptcha\Typo3\ValueObject\EndpointConfiguration;
use PrivateCaptcha\Typo3\ValueObject\IntegrationConfiguration;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\WidgetConfiguration;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class WidgetRendererTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'form',
    ];

    protected array $testExtensionsToLoad = [
        'private-captcha/typo3',
    ];

    protected bool $initializeDatabase = false;

    /** @var array<array-key, mixed> */
    private array $assetState = [];

    protected function setUp(): void
    {
        parent::setUp();

        $assetCollector = $this->get(AssetCollector::class);
        $this->assetState = $assetCollector->getState();
        $assetCollector->updateState(array_map(
            static fn(): array => [],
            $this->assetState,
        ));
    }

    protected function tearDown(): void
    {
        $this->get(AssetCollector::class)->updateState($this->assetState);

        parent::tearDown();
    }

    #[Test]
    public function activeWidgetsKeepUntrustedAttributesIsolatedAndLoadAssetsOnce(): void
    {
        $apiKey = 'secret-' . bin2hex(random_bytes(16));
        $sitekey = 'sitekey&quot onmouseover="alert(1)<script>bad()</script>';
        $styles = '--label: "captcha"; --image: <script>bad()</script>;';
        $renderer = $this->renderer();

        $firstMarkup = $renderer->render(
            $this->configuration($apiKey, $sitekey, true, $styles),
            'tx_form_formframework[contact][captcha]',
            'contact-captcha',
        );
        $secondMarkup = $renderer->render(
            $this->configuration($apiKey, $sitekey, true, $styles),
            'tx_form_formframework[newsletter][captcha]',
            'newsletter-captcha',
        );
        $first = $this->widget($firstMarkup);
        $second = $this->widget($secondMarkup);

        self::assertSame($sitekey, $first->getAttribute('data-sitekey'));
        self::assertSame($styles, $first->getAttribute('data-styles'));
        self::assertSame('true', $first->getAttribute('data-debug'));
        self::assertNotSame(
            $first->getAttribute('data-store-variable'),
            $second->getAttribute('data-store-variable'),
        );
        self::assertStringNotContainsString($apiKey, $firstMarkup . $secondMarkup);
        self::assertSame(
            'https://cdn.privatecaptcha.com/widget/js/privatecaptcha.js',
            $this->widgetScriptSource(),
        );
        self::assertSame(
            ['@private-captcha/typo3/private-captcha.js'],
            $this->get(AssetCollector::class)->getJavaScriptModules(),
        );
    }

    #[Test]
    public function widgetUsesEuOrCustomEndpointsFromResolvedConfiguration(): void
    {
        $renderer = $this->renderer();
        $euWidget = $this->widget($renderer->render(
            $this->configuration(endpoints: new EndpointConfiguration(
                apiDomainOverride: 'api.eu.privatecaptcha.com',
                puzzleEndpointOverride: null,
                cdnBaseUrl: 'https://cdn.privatecaptcha.com',
                euIsolation: true,
            )),
            'private-captcha-solution',
            'eu-captcha',
        ));
        self::assertSame('true', $euWidget->getAttribute('data-eu'));
        self::assertFalse($euWidget->hasAttribute('data-puzzle-endpoint'));

        $this->get(AssetCollector::class)->updateState(array_map(
            static fn(): array => [],
            $this->get(AssetCollector::class)->getState(),
        ));
        $customWidget = $this->widget($renderer->render(
            $this->configuration(endpoints: new EndpointConfiguration(
                apiDomainOverride: 'api.captcha.example.com',
                puzzleEndpointOverride: 'https://api.captcha.example.com/puzzle',
                cdnBaseUrl: 'https://cdn.captcha.example.com',
                euIsolation: false,
            )),
            'private-captcha-solution',
            'custom-captcha',
        ));
        self::assertSame(
            'https://api.captcha.example.com/puzzle',
            $customWidget->getAttribute('data-puzzle-endpoint'),
        );
        self::assertSame(
            'https://cdn.captcha.example.com/widget/js/privatecaptcha.js',
            $this->widgetScriptSource(),
        );
    }

    #[Test]
    public function invalidSolutionFieldIsRejectedBeforeRenderingOrAssetCollection(): void
    {
        try {
            $this->renderer()->render(
                $this->configuration(),
                'field"><script>bad()</script>',
                'captcha',
            );
            self::fail('Unsafe solution field names must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->get(AssetCollector::class)->getJavaScripts());
            self::assertSame([], $this->get(AssetCollector::class)->getJavaScriptModules());
        }
    }

    private function configuration(
        string $apiKey = 'api-key',
        string $sitekey = 'sitekey',
        bool $debug = false,
        string $customStyles = '',
        ?EndpointConfiguration $endpoints = null,
    ): ResolvedCaptchaConfiguration {
        return new ResolvedCaptchaConfiguration(
            apiKey: $apiKey,
            sitekey: $sitekey,
            widget: new WidgetConfiguration(
                theme: 'dark',
                language: 'de',
                startMode: 'click',
                debug: $debug,
                customStyles: $customStyles,
            ),
            integrations: new IntegrationConfiguration(
                formFramework: true,
                powermail: false,
                frontendLogin: false,
                backendLogin: false,
            ),
            requestedIntegrations: new IntegrationConfiguration(
                formFramework: true,
                powermail: false,
                frontendLogin: false,
                backendLogin: false,
            ),
            endpoints: $endpoints ?? new EndpointConfiguration(
                apiDomainOverride: null,
                puzzleEndpointOverride: null,
                cdnBaseUrl: 'https://cdn.privatecaptcha.com',
                euIsolation: false,
            ),
        );
    }

    private function renderer(): WidgetRenderer
    {
        return new WidgetRenderer(
            $this->get(ViewFactoryInterface::class),
            new WidgetAssetService($this->get(AssetCollector::class)),
        );
    }

    private function widget(string $markup): \DOMElement
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML(
            '<!doctype html><html><body>' . $markup . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        ));
        $nodes = (new \DOMXPath($document))->query('//div[@data-private-captcha-widget="true"]');
        self::assertInstanceOf(\DOMNodeList::class, $nodes);
        $widget = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $widget);

        return $widget;
    }

    private function widgetScriptSource(): string
    {
        $asset = $this->get(AssetCollector::class)->getJavaScripts()['private-captcha/widget'] ?? null;
        self::assertIsArray($asset);
        $source = $asset['source'] ?? null;
        self::assertIsString($source);

        return $source;
    }

}

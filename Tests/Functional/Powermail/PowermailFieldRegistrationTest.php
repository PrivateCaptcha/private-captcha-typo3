<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Powermail;

use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Typo3\Powermail\PowermailRegistration;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Event\ModifyLoadedPageTsConfigEvent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PowermailFieldRegistrationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'form',
    ];

    protected array $testExtensionsToLoad = [
        'private-captcha/typo3',
    ];

    protected bool $initializeDatabase = false;

    private bool $compatiblePowermailInstalled = false;

    protected function setUp(): void
    {
        $this->compatiblePowermailInstalled = (new Typo3Version())->getMajorVersion() === 13
            && class_exists('In2code\\Powermail\\Domain\\Model\\Field');
        if ($this->compatiblePowermailInstalled) {
            array_unshift($this->testExtensionsToLoad, 'in2code/powermail');
        }

        parent::setUp();
    }

    #[Test]
    public function registersDistinctFieldWithoutReplacingBuiltInCaptcha(): void
    {
        $tca = $GLOBALS['TCA'] ?? null;
        self::assertIsArray($tca);
        $fieldTca = $tca['tx_powermail_domain_model_field'] ?? null;
        if (!$this->compatiblePowermailInstalled) {
            if (is_array($fieldTca)) {
                $types = $fieldTca['types'] ?? [];
                self::assertIsArray($types);
                self::assertArrayNotHasKey('privateCaptcha', $types);
            } else {
                self::assertNull($fieldTca);
            }
            return;
        }

        self::assertIsArray($fieldTca);
        $types = $fieldTca['types'] ?? null;
        self::assertIsArray($types);
        self::assertArrayHasKey('captcha', $types);
        self::assertArrayHasKey('privateCaptcha', $types);
        $privateCaptchaType = $types['privateCaptcha'];
        $builtInCaptchaType = $types['captcha'];
        self::assertIsArray($privateCaptchaType);
        self::assertIsArray($builtInCaptchaType);
        $privateCaptchaShowitem = $privateCaptchaType['showitem'] ?? null;
        $builtInCaptchaShowitem = $builtInCaptchaType['showitem'] ?? null;
        self::assertIsString($privateCaptchaShowitem);
        self::assertIsString($builtInCaptchaShowitem);
        self::assertStringContainsString('private_captcha_warning', $privateCaptchaShowitem);
        self::assertStringNotContainsString('private_captcha_warning', $builtInCaptchaShowitem);

        $columns = $fieldTca['columns'] ?? null;
        self::assertIsArray($columns);
        $warning = $columns['private_captcha_warning'] ?? null;
        self::assertIsArray($warning);
        $warningConfig = $warning['config'] ?? null;
        self::assertIsArray($warningConfig);
        self::assertSame('none', $warningConfig['type'] ?? null);
        self::assertSame(
            'USER:' . PowermailRegistration::class . '->shouldDisplayUnprotectedWarning',
            $warning['displayCond'] ?? null,
        );

        $typeColumn = $columns['type'] ?? null;
        self::assertIsArray($typeColumn);
        $typeConfig = $typeColumn['config'] ?? null;
        self::assertIsArray($typeConfig);
        $typeItems = $typeConfig['items'] ?? null;
        self::assertIsArray($typeItems);
        self::assertContains('captcha', array_column($typeItems, 'value'));
        self::assertNotContains('privateCaptcha', array_column($typeItems, 'value'));
        self::assertStringContainsString(
            'Configuration/TypoScript/Powermail/setup.typoscript',
            $this->defaultTypoScriptSetup(),
        );
    }

    #[Test]
    public function exposesFieldSelectorOnlyForProtectedSite(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(GeneralUtility::getContainer()->has(PowermailRegistration::class));
            return;
        }

        $this->site('powermail-active', 81, true);
        $this->site('powermail-disabled', 82, false);
        $this->site('powermail-nested-outer-active', 85, true);
        $this->site('powermail-nested-inner-disabled', 86, false);
        $this->site('powermail-nested-outer-disabled', 87, false);
        $this->site('powermail-nested-inner-active', 88, true);
        $registration = $this->registration();
        $activeEvent = new ModifyLoadedPageTsConfigEvent([], [
            0 => ['uid' => 0],
            1 => ['uid' => 81],
        ]);
        $disabledEvent = new ModifyLoadedPageTsConfigEvent([], [
            0 => ['uid' => 0],
            1 => ['uid' => 82],
        ]);
        $nestedDisabledEvent = new ModifyLoadedPageTsConfigEvent([], [
            0 => ['uid' => 0],
            1 => ['uid' => 85],
            2 => ['uid' => 86],
        ]);
        $nestedActiveEvent = new ModifyLoadedPageTsConfigEvent([], [
            0 => ['uid' => 0],
            1 => ['uid' => 87],
            2 => ['uid' => 88],
        ]);

        $registration->addFieldType($activeEvent);
        $registration->addFieldType($disabledEvent);
        $registration->addFieldType($nestedDisabledEvent);
        $registration->addFieldType($nestedActiveEvent);

        self::assertStringContainsString(
            'tx_powermail.flexForm.type.addFieldOptions.privateCaptcha',
            implode("\n", array_filter($activeEvent->getTsConfig(), 'is_string')),
        );
        self::assertSame([], $disabledEvent->getTsConfig());
        self::assertSame([], $nestedDisabledEvent->getTsConfig());
        self::assertStringContainsString(
            'tx_powermail.flexForm.type.addFieldOptions.privateCaptcha',
            implode("\n", array_filter($nestedActiveEvent->getTsConfig(), 'is_string')),
        );
    }

    #[Test]
    public function rendersWidgetAndEditorWarningAccordingToEffectiveSiteProtection(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(GeneralUtility::getContainer()->has(PowermailRegistration::class));
            return;
        }

        $activeSite = $this->site('powermail-render-active', 83, true);
        $disabledSite = $this->site('powermail-render-disabled', 84, false);
        $registration = $this->registration();
        $contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $contentObjectRenderer->start([
            'uid' => 42,
            'marker' => 'private_captcha',
        ]);
        $userConfiguration = [
            'userFunc' => PowermailRegistration::class . '->renderWidget',
        ];
        $contentObjectRenderer->setRequest($this->request($activeSite));
        $activeMarkup = $contentObjectRenderer->cObjGetSingle('USER', $userConfiguration);
        $contentObjectRenderer->setRequest($this->request($disabledSite));
        $disabledMarkup = $contentObjectRenderer->cObjGetSingle('USER', $userConfiguration);

        self::assertStringContainsString('data-private-captcha-widget="true"', $activeMarkup);
        self::assertStringContainsString(
            'data-solution-field="tx_powermail_pi1[field][private_captcha]"',
            $activeMarkup,
        );
        self::assertSame('', $disabledMarkup);
        self::assertFalse($registration->shouldDisplayUnprotectedWarning([
            'record' => ['pid' => 83],
        ]));
        self::assertTrue($registration->shouldDisplayUnprotectedWarning([
            'record' => ['pid' => 84],
        ]));
    }

    private function registration(): PowermailRegistration
    {
        return $this->get(PowermailRegistration::class);
    }

    private function request(Site $site): ServerRequestInterface
    {
        return (new ServerRequest('https://' . $site->getIdentifier() . '.test/'))
            ->withAttribute('site', $site)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
    }

    private function site(string $identifier, int $rootPageId, bool $powermailEnabled): Site
    {
        $this->get(SiteWriter::class)->write($identifier, [
            'rootPageId' => $rootPageId,
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
            'privateCaptcha' => [
                'apiKey' => 'api-key',
                'sitekey' => 'sitekey',
                'powermailEnabled' => $powermailEnabled,
            ],
        ]);
        $sites = $this->get(SiteConfiguration::class)->resolveAllExistingSites(false);
        $this->get(SiteFinder::class)->siteConfigurationChanged();

        return $sites[$identifier];
    }

    private function defaultTypoScriptSetup(): string
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($typo3Configuration)) {
            return '';
        }
        $frontendConfiguration = $typo3Configuration['FE'] ?? null;
        if (!is_array($frontendConfiguration)) {
            return '';
        }
        $setup = $frontendConfiguration['defaultTypoScript_setup'] ?? '';

        return is_string($setup) ? $setup : '';
    }
}

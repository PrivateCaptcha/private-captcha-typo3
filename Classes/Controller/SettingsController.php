<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Controller;

use PrivateCaptcha\Typo3\Compatibility\PowermailCompatibility;
use PrivateCaptcha\Typo3\Configuration\BackendConfigurationRepository;
use PrivateCaptcha\Typo3\Configuration\ConfigurationNormalizer;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Configuration\SiteConfigurationRepository;
use PrivateCaptcha\Typo3\Service\SettingsActivationService;
use PrivateCaptcha\Typo3\ValueObject\CaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\ConnectionTestResult;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\SettingsSubmission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * @internal
 */
#[AsController]
final readonly class SettingsController
{
    private const LABELS = 'LLL:EXT:private_captcha/Resources/Private/Language/locallang_mod.xlf:';

    private const REQUIRED_SETTINGS = [
        'apiKey',
        'sitekey',
        'theme',
        'language',
        'startMode',
        'debug',
        'customStyles',
        'euIsolation',
        'customRootDomain',
    ];

    public function __construct(
        private SiteFinder $siteFinder,
        private ModuleTemplateFactory $moduleTemplateFactory,
        private SiteConfigurationRepository $siteConfigurationRepository,
        private BackendConfigurationRepository $backendConfigurationRepository,
        private ConfigurationNormalizer $configurationNormalizer,
        private ConfigurationResolver $configurationResolver,
        private PackageManager $packageManager,
        private Typo3Version $typo3Version,
        private SettingsActivationService $settingsActivationService,
        private UriBuilder $uriBuilder,
        private FlashMessageService $flashMessageService,
    ) {}

    public function indexAction(#[\SensitiveParameter] ServerRequestInterface $request): ResponseInterface
    {
        return $this->render($request, $this->siteIdentifiers());
    }

    public function siteAction(#[\SensitiveParameter] ServerRequestInterface $request): ResponseInterface
    {
        $siteIdentifiers = $this->siteIdentifiers();
        $siteIdentifier = $request->getQueryParams()['site'] ?? null;
        if (!is_string($siteIdentifier) || !in_array($siteIdentifier, $siteIdentifiers, true)) {
            throw new \InvalidArgumentException('Requested Private Captcha site scope does not exist.');
        }
        $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        $powermailAvailable = $this->powermailAvailable();
        $frontendLoginAvailable = $this->packageManager->isPackageActive('felogin');
        if (strtoupper($request->getMethod()) === 'POST') {
            try {
                $notice = $this->handleAction(
                    $request,
                    site: $site,
                    powermailAvailable: $powermailAvailable,
                    frontendLoginAvailable: $frontendLoginAvailable,
                );
            } catch (\InvalidArgumentException) {
                $notice = $this->invalidSubmissionNotice();
            }

            return $this->redirectAfterAction($request, $notice, ['site' => $siteIdentifier]);
        }

        return $this->render(
            $request,
            $siteIdentifiers,
            $siteIdentifier,
            siteSelected: true,
            settings: $this->siteSettings(
                $site,
                $powermailAvailable,
                $frontendLoginAvailable,
            ),
            powermailAvailable: $powermailAvailable,
        );
    }

    public function backendAction(#[\SensitiveParameter] ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'POST') {
            try {
                $notice = $this->handleAction($request);
            } catch (\InvalidArgumentException) {
                $notice = $this->invalidSubmissionNotice();
            }

            return $this->redirectAfterAction($request, $notice);
        }

        return $this->render(
            $request,
            $this->siteIdentifiers(),
            backendSelected: true,
            settings: $this->backendSettings(),
        );
    }

    /**
     * @return list<string>
     */
    private function siteIdentifiers(): array
    {
        $siteIdentifiers = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $siteIdentifiers[] = $site->getIdentifier();
        }
        sort($siteIdentifiers, SORT_NATURAL | SORT_FLAG_CASE);

        return $siteIdentifiers;
    }

    /**
     * @param list<string> $siteIdentifiers
     * @param array<string, mixed>|null $settings
     */
    private function render(
        #[\SensitiveParameter]
        ServerRequestInterface $request,
        array $siteIdentifiers,
        ?string $selectedSiteIdentifier = null,
        bool $siteSelected = false,
        bool $backendSelected = false,
        ?array $settings = null,
        bool $powermailAvailable = false,
    ): ResponseInterface {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->getLanguageService()->sL(self::LABELS . 'mlang_tabs_tab'));
        $view->assignMultiple([
            'siteIdentifiers' => $siteIdentifiers,
            'selectedSiteIdentifier' => $selectedSiteIdentifier,
            'siteSelected' => $siteSelected,
            'backendSelected' => $backendSelected,
            'scopeSelected' => $siteSelected || $backendSelected,
            'settings' => $settings,
            'powermailAvailable' => $siteSelected && $powermailAvailable,
            'settingsActionUri' => $siteSelected || $backendSelected
                ? (string)$this->uriBuilder->buildUriFromRequest(
                    $request,
                    $siteSelected ? ['site' => $selectedSiteIdentifier] : [],
                )
                : '',
        ]);

        return $view->renderResponse('Settings/Index');
    }

    /**
     * @return array<string, mixed>
     */
    private function siteSettings(
        #[\SensitiveParameter]
        Site $site,
        bool $powermailAvailable,
        bool $frontendLoginAvailable,
    ): array {
        $persisted = $this->siteConfigurationRepository->getForEditing($site);

        return $this->formSettings(
            $this->configurationNormalizer->normalizePersisted($persisted),
            $this->configurationResolver->resolveSiteCandidate($site, $persisted),
            $persisted,
            $powermailAvailable,
            $frontendLoginAvailable,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function backendSettings(): array
    {
        $persisted = $this->backendConfigurationRepository->getForEditing();

        return $this->formSettings(
            $this->configurationNormalizer->normalizePersisted($persisted),
            $this->configurationResolver->resolveBackendCandidate($persisted),
            $persisted,
        );
    }

    /**
     * @param array<string, mixed> $persisted
     * @return array<string, mixed>
     */
    private function formSettings(
        #[\SensitiveParameter]
        CaptchaConfiguration $configuration,
        #[\SensitiveParameter]
        ResolvedCaptchaConfiguration $resolvedConfiguration,
        #[\SensitiveParameter]
        array $persisted,
        bool $powermailAvailable = false,
        bool $frontendLoginAvailable = false,
    ): array {
        $status = $this->status($persisted);

        return [
            'apiKeyConfigured' => ($configuration->apiKeyReplacement() ?? '') !== ''
                || $resolvedConfiguration->apiKey() !== '',
            'sitekey' => $configuration->sitekey,
            'theme' => $configuration->widget->theme,
            'language' => $configuration->widget->language,
            'startMode' => $configuration->widget->startMode,
            'debug' => $configuration->widget->debug,
            'customStyles' => $configuration->widget->customStyles,
            'euIsolation' => $configuration->euIsolation,
            'customRootDomain' => $configuration->customRootDomain,
            'formFrameworkEnabled' => $configuration->integrations->formFramework,
            'powermailEnabled' => $configuration->integrations->powermail,
            'frontendLoginEnabled' => $configuration->integrations->frontendLogin,
            'backendLoginEnabled' => $configuration->integrations->backendLogin,
            'protectionEnabled' => $resolvedConfiguration->integrations->formFramework
                || ($powermailAvailable && $resolvedConfiguration->integrations->powermail)
                || ($frontendLoginAvailable && $resolvedConfiguration->integrations->frontendLogin)
                || $resolvedConfiguration->integrations->backendLogin,
            ...$status,
        ];
    }

    /**
     * @return array{message: string, severity: ContextualFeedbackSeverity}
     */
    private function handleAction(
        #[\SensitiveParameter]
        ServerRequestInterface $request,
        ?Site $site = null,
        bool $powermailAvailable = false,
        bool $frontendLoginAvailable = false,
    ): array {
        $parsedBody = $request->getParsedBody();
        if (!is_array($parsedBody)) {
            throw new \InvalidArgumentException('Private Captcha settings submission must be an array.');
        }
        $action = $parsedBody['settingsAction'] ?? null;
        if (!is_string($action) || !in_array($action, ['save', 'test', 'reset'], true)) {
            throw new \InvalidArgumentException('Unknown Private Captcha settings action.');
        }
        if ($action === 'reset') {
            $submission = $site === null
                ? SettingsSubmission::forBackend([])
                : SettingsSubmission::forSite($site, []);
            $this->settingsActivationService->reset($submission);

            return [
                'message' => $this->label('settings.notice.reset'),
                'severity' => ContextualFeedbackSeverity::OK,
            ];
        }
        $input = $parsedBody['settings'] ?? [];
        if (!is_array($input)) {
            throw new \InvalidArgumentException('Private Captcha settings must be an array.');
        }
        $input = array_filter(
            $input,
            static fn(mixed $key): bool => is_string($key),
            ARRAY_FILTER_USE_KEY,
        );
        /** @var array<string, mixed> $input */
        $requiredSettings = [...self::REQUIRED_SETTINGS, 'backendLoginEnabled'];
        if ($site !== null) {
            $requiredSettings = [...self::REQUIRED_SETTINGS, 'formFrameworkEnabled', 'frontendLoginEnabled'];
            if ($powermailAvailable) {
                $requiredSettings[] = 'powermailEnabled';
            }
        }
        if (array_diff($requiredSettings, array_keys($input)) !== []) {
            throw new \InvalidArgumentException('Private Captcha settings submission is incomplete.');
        }
        if (is_string($input['apiKey'] ?? null) && trim($input['apiKey']) === '') {
            unset($input['apiKey']);
        }
        if ($site !== null) {
            if (!$powermailAvailable) {
                $input['powermailEnabled'] = false;
            }
            if (!$frontendLoginAvailable) {
                $input['frontendLoginEnabled'] = false;
            }
        }
        $submission = $site === null
            ? SettingsSubmission::forBackend($input)
            : SettingsSubmission::forSite($site, $input);

        if ($action === 'save') {
            $connectionTest = $this->settingsActivationService->save($submission);

            return $this->connectionNotice('save', $connectionTest);
        }

        $connectionTest = $this->settingsActivationService->test($submission);

        return $this->connectionNotice('test', $connectionTest);
    }

    /**
     * @return array{message: string, severity: ContextualFeedbackSeverity}
     */
    private function invalidSubmissionNotice(): array
    {
        return [
            'message' => $this->label('settings.notice.invalid'),
            'severity' => ContextualFeedbackSeverity::ERROR,
        ];
    }

    /**
     * @param array{message: string, severity: ContextualFeedbackSeverity} $notice
     * @param array<string, string> $parameters
     */
    private function redirectAfterAction(
        #[\SensitiveParameter]
        ServerRequestInterface $request,
        array $notice,
        array $parameters = [],
    ): ResponseInterface {
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(
            new FlashMessage($notice['message'], severity: $notice['severity'], storeInSession: true),
        );

        return new RedirectResponse(
            $this->uriBuilder->buildUriFromRequest($request, $parameters),
            303,
        );
    }

    /**
     * @return array{message: string, severity: ContextualFeedbackSeverity}
     */
    private function connectionNotice(
        string $action,
        ConnectionTestResult $connectionTest,
    ): array {
        $result = $connectionTest->successful ? 'Success' : 'Failure';

        return [
            'message' => $this->label('settings.notice.' . $action . $result),
            'severity' => $connectionTest->successful
                ? ContextualFeedbackSeverity::OK
                : ContextualFeedbackSeverity::ERROR,
        ];
    }

    /**
     * @param array<string, mixed> $persisted
     * @return array{connectionTested: bool, connectionSuccessful: bool, lastTestAt: string}
     */
    private function status(#[\SensitiveParameter] array $persisted): array
    {
        $metadata = $persisted['lastConnectionTest'] ?? null;
        if (!is_array($metadata)
            || !is_bool($metadata['successful'] ?? null)
            || !is_string($metadata['testedAt'] ?? null)
        ) {
            return [
                'connectionTested' => false,
                'connectionSuccessful' => false,
                'lastTestAt' => '',
            ];
        }
        $testedAt = \DateTimeImmutable::createFromFormat(DATE_ATOM, $metadata['testedAt']);
        if (!$testedAt instanceof \DateTimeImmutable || $testedAt->format(DATE_ATOM) !== $metadata['testedAt']) {
            return [
                'connectionTested' => false,
                'connectionSuccessful' => false,
                'lastTestAt' => '',
            ];
        }

        return [
            'connectionTested' => true,
            'connectionSuccessful' => $metadata['successful'],
            'lastTestAt' => $testedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s \\U\\T\\C'),
        ];
    }

    private function label(string $key): string
    {
        return $this->getLanguageService()->sL(self::LABELS . $key);
    }

    private function powermailAvailable(): bool
    {
        return PowermailCompatibility::isAvailable($this->typo3Version, $this->packageManager);
    }

    private function getLanguageService(): LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            throw new \RuntimeException('Backend language service is not available.');
        }

        return $languageService;
    }
}

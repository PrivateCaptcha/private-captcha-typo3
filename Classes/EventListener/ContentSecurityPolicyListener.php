<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\EventListener;

use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Service\WidgetAssetService;
use PrivateCaptcha\Typo3\ValueObject\EndpointConfiguration;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * @internal
 */
final readonly class ContentSecurityPolicyListener
{
    private const OFFICIAL_ORIGIN = 'https://privatecaptcha.com';

    private const OFFICIAL_WILDCARD_ORIGIN = 'https://*.privatecaptcha.com';

    public function __construct(
        private ConfigurationResolver $configurationResolver,
        private WidgetAssetService $widgetAssetService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PolicyMutatedEvent $event): void
    {
        try {
            $configuration = $this->activeConfiguration($event);
        } catch (\InvalidArgumentException) {
            $this->logger->warning(
                'Private Captcha CSP was not extended because configuration is invalid.',
                ['scope' => (string)$event->scope],
            );

            return;
        }
        if ($configuration === null) {
            return;
        }
        $sources = $this->sources($configuration->endpoints);
        if ($sources === []) {
            return;
        }

        $mutations = [];
        foreach ([Directive::ScriptSrc, Directive::FrameSrc, Directive::StyleSrc, Directive::ConnectSrc] as $directive) {
            $mutations[] = new Mutation(MutationMode::Extend, $directive, ...$sources);
        }
        $event->setCurrentPolicy(
            $event->getCurrentPolicy()->mutate(new MutationCollection(...$mutations)),
        );
    }

    private function activeConfiguration(PolicyMutatedEvent $event): ?ResolvedCaptchaConfiguration
    {
        if ($event->scope->type->isFrontend()) {
            $site = $event->request?->getAttribute('site');
            if (!$site instanceof Site) {
                return null;
            }
            $configuration = $this->configurationResolver->resolveSite($site);
            $integrations = $configuration->integrations;

            return $integrations->formFramework || $integrations->powermail || $integrations->frontendLogin
                ? $configuration
                : null;
        }
        if ($event->scope->type->isBackend()) {
            if (!$this->widgetAssetService->isCollected()) {
                return null;
            }
            $configuration = $this->configurationResolver->resolveBackend();

            return $configuration->integrations->backendLogin ? $configuration : null;
        }

        return null;
    }

    /**
     * @return list<UriValue>
     */
    private function sources(EndpointConfiguration $endpoints): array
    {
        if ($endpoints->puzzleEndpointOverride === null) {
            return [
                new UriValue(self::OFFICIAL_ORIGIN),
                new UriValue(self::OFFICIAL_WILDCARD_ORIGIN),
            ];
        }
        if ($endpoints->apiDomainOverride === null) {
            return [];
        }

        return [
            new UriValue('https://' . $endpoints->apiDomainOverride),
            new UriValue($endpoints->cdnBaseUrl),
        ];
    }
}

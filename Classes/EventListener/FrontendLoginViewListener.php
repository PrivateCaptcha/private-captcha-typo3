<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\EventListener;

use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Service\WidgetRenderer;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\FrontendLogin\Event\ModifyLoginFormViewEvent;

/**
 * @internal
 */
final readonly class FrontendLoginViewListener
{
    private const BINDING_IDENTIFIER = 'frontend-login';

    public function __construct(
        private ConfigurationResolver $configurationResolver,
        private WidgetRenderer $widgetRenderer,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ModifyLoginFormViewEvent $event): void
    {
        $markup = '';
        $request = $event->getRequest();
        $site = $request->getAttribute('site');
        if ($site instanceof Site) {
            try {
                $configuration = $this->configurationResolver->resolveSite($site);
                if ($configuration->integrations->frontendLogin) {
                    $markup = $this->widgetRenderer->render(
                        configuration: $configuration,
                        solutionFieldName: Client::DEFAULT_FORM_FIELD,
                        bindingIdentifier: self::BINDING_IDENTIFIER,
                        request: $request,
                    );
                }
            } catch (\InvalidArgumentException) {
                $this->logger->warning(
                    'Private Captcha was not rendered in the frontend login because configuration is invalid.',
                    ['site' => $site->getIdentifier()],
                );
            }
        }

        $event->getView()->assign('privateCaptchaMarkup', $markup);
    }
}

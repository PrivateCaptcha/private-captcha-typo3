<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\LoginProvider;

use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Service\WidgetRenderer;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderInterface;
use TYPO3\CMS\Backend\LoginProvider\UsernamePasswordLoginProvider;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;

/**
 * @internal
 */
final readonly class PrivateCaptchaLoginProvider implements LoginProviderInterface
{
    private const TEMPLATE_ROOT_PATH = 'EXT:private_captcha/Resources/Private/Templates';

    public function __construct(
        private UsernamePasswordLoginProvider $nativeProvider,
        private ConfigurationResolver $configurationResolver,
        private WidgetRenderer $widgetRenderer,
    ) {}

    /**
     * @deprecated Legacy TYPO3 13 interface method; modifyView() is used by TYPO3 13 and 14.
     */
    public function render(mixed $view, mixed $pageRenderer, mixed $loginController): void
    {
        throw new \RuntimeException('Legacy login provider rendering must not be called.', 1787478001);
    }

    public function modifyView(ServerRequestInterface $request, ViewInterface $view): string
    {
        $nativeTemplate = $this->nativeProvider->modifyView($request, $view);
        if (!$view instanceof FluidViewAdapter) {
            return $nativeTemplate;
        }

        try {
            if ($view->getRenderingContext()->getVariableProvider()->get('action') !== 'login') {
                return $nativeTemplate;
            }
            $configuration = $this->configurationResolver->resolveBackend();
            if (!$configuration->integrations->backendLogin) {
                return $nativeTemplate;
            }
            $markup = $this->widgetRenderer->render(
                configuration: $configuration,
                solutionFieldName: Client::DEFAULT_FORM_FIELD,
                bindingIdentifier: 'backend-login',
                request: $request,
            );
            if ($markup === '') {
                return $nativeTemplate;
            }

            $templatePaths = $view->getRenderingContext()->getTemplatePaths();
            $templateRootPaths = $templatePaths->getTemplateRootPaths();
            $templateRootPaths[] = self::TEMPLATE_ROOT_PATH;
            $templatePaths->setTemplateRootPaths($templateRootPaths);
            $view->assign('privateCaptchaMarkup', $markup);
        } catch (\Throwable) {
            return $nativeTemplate;
        }

        return 'BackendLogin/Login';
    }
}

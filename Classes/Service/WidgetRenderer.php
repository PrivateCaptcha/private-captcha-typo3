<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Service;

use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\TagBuilder;

/**
 * @internal
 */
final readonly class WidgetRenderer
{
    private const MAX_IDENTIFIER_LENGTH = 512;

    public function __construct(
        private ViewFactoryInterface $viewFactory,
        private WidgetAssetService $widgetAssetService,
    ) {}

    public function render(
        #[\SensitiveParameter]
        ResolvedCaptchaConfiguration $configuration,
        string $solutionFieldName,
        string $bindingIdentifier,
        ?ServerRequestInterface $request = null,
    ): string {
        if ($configuration->sitekey === '') {
            return '';
        }
        $this->assertSolutionFieldName($solutionFieldName);
        $this->assertBindingIdentifier($bindingIdentifier);

        $widget = new TagBuilder('div');
        $widget->forceClosingTag(true);
        $widget->addAttributes([
            'class' => 'private-captcha',
            'data-private-captcha-widget' => 'true',
            'data-sitekey' => $configuration->sitekey,
            'data-theme' => $configuration->widget->theme,
            'data-lang' => $configuration->widget->language,
            'data-start-mode' => $configuration->widget->startMode,
            'data-solution-field' => $solutionFieldName,
            'data-store-variable' => '_privateCaptcha_' . substr(hash('sha256', $bindingIdentifier), 0, 16),
        ]);
        if ($configuration->widget->debug) {
            $widget->addAttribute('data-debug', 'true');
        }
        if ($configuration->endpoints->puzzleEndpointOverride !== null) {
            $widget->addAttribute('data-puzzle-endpoint', $configuration->endpoints->puzzleEndpointOverride);
        } elseif ($configuration->endpoints->euIsolation) {
            $widget->addAttribute('data-eu', 'true');
        }
        if ($configuration->widget->customStyles !== '') {
            $widget->addAttribute('data-styles', $configuration->widget->customStyles);
        }

        $view = $this->viewFactory->create(new ViewFactoryData(
            templatePathAndFilename: 'EXT:private_captcha/Resources/Private/Partials/Widget.html',
            request: $request,
        ));
        $markup = trim($view->assign('widgetMarkup', $widget->render())->render());
        if ($markup !== '') {
            $this->widgetAssetService->collect($configuration->endpoints);
        }

        return $markup;
    }

    private function assertSolutionFieldName(string $solutionFieldName): void
    {
        if (strlen($solutionFieldName) > self::MAX_IDENTIFIER_LENGTH
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_.:-]*(?:\[[A-Za-z0-9_.:-]+\])*\z/D', $solutionFieldName) !== 1
        ) {
            throw new \InvalidArgumentException('Private Captcha solution field name is invalid.');
        }
    }

    private function assertBindingIdentifier(string $bindingIdentifier): void
    {
        if (strlen($bindingIdentifier) > self::MAX_IDENTIFIER_LENGTH
            || preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $bindingIdentifier) !== 1
        ) {
            throw new \InvalidArgumentException('Private Captcha widget binding identifier is invalid.');
        }
    }
}

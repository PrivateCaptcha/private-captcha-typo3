<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Powermail;

use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Service\WidgetRenderer;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Event\ModifyLoadedPageTsConfigEvent;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * @internal
 */
final class PowermailRegistration
{
    private ?ContentObjectRenderer $contentObjectRenderer = null;

    public function __construct(
        private readonly ConfigurationResolver $configurationResolver,
        private readonly SiteFinder $siteFinder,
        private readonly WidgetRenderer $widgetRenderer,
    ) {}

    public function addFieldType(ModifyLoadedPageTsConfigEvent $event): void
    {
        $rootLine = $event->getRootLine();
        /** @var array<int, array<string, mixed>> $rootLine */
        $site = $this->siteForRootLine($rootLine);
        if (!$site instanceof Site || !$this->isProtected($site)) {
            return;
        }

        $event->addTsConfig(
            'tx_powermail.flexForm.type.addFieldOptions.privateCaptcha = '
            . 'LLL:EXT:private_captcha/Resources/Private/Language/locallang.xlf:powermail.field.label',
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function shouldDisplayUnprotectedWarning(array $parameters): bool
    {
        $record = $parameters['record'] ?? null;
        if (!is_array($record)) {
            return true;
        }
        $pageId = $this->integerValue($record['pid'] ?? null);
        if ($pageId < 1) {
            $pageId = $this->integerValue($record['_ORIG_pid'] ?? null);
        }
        if ($pageId < 1) {
            return true;
        }

        try {
            $site = $this->siteFinder->getSiteByRootPageId($pageId);
        } catch (SiteNotFoundException) {
            try {
                $site = $this->siteFinder->getSiteByPageId($pageId);
            } catch (SiteNotFoundException) {
                return true;
            }
        }

        return !$this->isProtected($site);
    }

    public function setContentObjectRenderer(ContentObjectRenderer $contentObjectRenderer): void
    {
        $this->contentObjectRenderer = $contentObjectRenderer;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function renderWidget(
        string $content,
        array $configuration,
        ServerRequestInterface $request,
    ): string {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return '';
        }
        try {
            $resolvedConfiguration = $this->configurationResolver->resolveSite($site);
        } catch (\InvalidArgumentException|\LogicException) {
            return '';
        }
        if (!$resolvedConfiguration->integrations->powermail) {
            return '';
        }
        $field = $this->contentObjectRenderer?->data;
        if (!is_array($field)) {
            return '';
        }
        $marker = $field['marker'] ?? null;
        $fieldUid = $this->integerValue($field['uid'] ?? null);
        if (!is_string($marker)
            || preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $marker) !== 1
            || $fieldUid < 1
        ) {
            return '';
        }

        return $this->widgetRenderer->render(
            configuration: $resolvedConfiguration,
            solutionFieldName: 'tx_powermail_pi1[field][' . $marker . ']',
            bindingIdentifier: 'powermail-' . $fieldUid,
            request: $request,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rootLine
     */
    private function siteForRootLine(array $rootLine): ?Site
    {
        $rootLine = array_values(array_reverse($rootLine));
        foreach ($rootLine as $page) {
            $pageId = $this->integerValue($page['uid'] ?? null);
            if ($pageId < 1) {
                continue;
            }
            try {
                return $this->siteFinder->getSiteByPageId($pageId, $rootLine);
            } catch (SiteNotFoundException) {
                continue;
            }
        }

        return null;
    }

    private function isProtected(Site $site): bool
    {
        try {
            return $this->configurationResolver->resolveSite($site)->integrations->powermail;
        } catch (\InvalidArgumentException|\LogicException) {
            return false;
        }
    }

    private function integerValue(mixed $value): int
    {
        return is_int($value) || is_string($value) && ctype_digit($value) ? (int)$value : 0;
    }
}

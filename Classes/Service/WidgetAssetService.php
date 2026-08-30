<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Service;

use PrivateCaptcha\Typo3\ValueObject\EndpointConfiguration;
use TYPO3\CMS\Core\Page\AssetCollector;

/**
 * @internal
 */
final readonly class WidgetAssetService
{
    private const WIDGET_ASSET = 'private-captcha/widget';

    private const LIFECYCLE_MODULE = '@private-captcha/typo3/private-captcha.js';

    public function __construct(
        private AssetCollector $assetCollector,
    ) {}

    public function collect(EndpointConfiguration $endpoints): void
    {
        $source = rtrim($endpoints->cdnBaseUrl, '/') . '/widget/js/privatecaptcha.js';
        $existingAsset = $this->assetCollector->getJavaScripts()[self::WIDGET_ASSET] ?? null;
        if ($existingAsset !== null) {
            $existingSource = is_array($existingAsset) ? ($existingAsset['source'] ?? null) : null;
            if ($existingSource !== $source) {
                throw new \LogicException('A page cannot render Private Captcha widgets from different CDN origins.');
            }
        }

        $this->assetCollector
            ->addJavaScript(self::WIDGET_ASSET, $source, ['defer' => 'defer'])
            ->addJavaScriptModule(self::LIFECYCLE_MODULE);
    }

    public function isCollected(): bool
    {
        return array_key_exists(self::WIDGET_ASSET, $this->assetCollector->getJavaScripts());
    }
}

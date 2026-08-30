<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Configuration;

use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * @internal
 */
final readonly class SiteConfigurationRepository
{
    public function __construct(
        private SiteConfiguration $siteConfiguration,
        private SiteWriter $siteWriter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(#[\SensitiveParameter] Site $site): array
    {
        $siteConfiguration = $site->getConfiguration();
        /** @var array<string, mixed> $siteConfiguration */

        return $this->captchaConfiguration($siteConfiguration);
    }

    /**
     * @return array<string, mixed>
     */
    public function getForEditing(#[\SensitiveParameter] Site $site): array
    {
        $siteConfiguration = $this->siteConfiguration->load($site->getIdentifier());
        /** @var array<string, mixed> $siteConfiguration */

        return $this->captchaConfiguration($siteConfiguration);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFresh(#[\SensitiveParameter] Site $site): array
    {
        $sites = $this->siteConfiguration->resolveAllExistingSites(false);
        $freshSite = $sites[$site->getIdentifier()] ?? null;
        if (!$freshSite instanceof Site) {
            throw new \InvalidArgumentException('Persisted CAPTCHA site configuration no longer exists.');
        }

        return $this->get($freshSite);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function save(
        #[\SensitiveParameter]
        Site $site,
        #[\SensitiveParameter]
        array $configuration,
    ): void {
        $siteConfiguration = $this->siteConfiguration->load($site->getIdentifier());
        $siteConfiguration['privateCaptcha'] = $configuration;
        try {
            $this->siteWriter->write($site->getIdentifier(), $siteConfiguration, protectPlaceholders: true);
        } catch (\Throwable) {
            throw new \RuntimeException('Unable to persist Private Captcha configuration.');
        }
    }

    /**
     * @param array<string, mixed> $siteConfiguration
     * @return array<string, mixed>
     */
    private function captchaConfiguration(#[\SensitiveParameter] array $siteConfiguration): array
    {
        if (!array_key_exists('privateCaptcha', $siteConfiguration)) {
            return [];
        }

        $configuration = $siteConfiguration['privateCaptcha'];
        if (!is_array($configuration)) {
            throw new \InvalidArgumentException('Persisted site CAPTCHA configuration must be an array.');
        }

        /** @var array<string, mixed> $configuration */
        return $configuration;
    }
}

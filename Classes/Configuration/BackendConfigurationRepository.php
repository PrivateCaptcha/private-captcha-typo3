<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Configuration;

use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * @internal
 */
final readonly class BackendConfigurationRepository
{
    public const LOCK_IDENTIFIER = 'private-captcha-settings-backend';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private ConfigurationManager $configurationManager,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->backendConfiguration($this->runtimeRootConfiguration());
    }

    /**
     * @return array<string, mixed>
     */
    public function getForEditing(): array
    {
        return $this->backendConfiguration($this->localRootConfiguration());
    }

    /**
     * @param array<string, mixed> $privateCaptchaConfiguration
     * @return array<string, mixed>
     */
    private function backendConfiguration(#[\SensitiveParameter] array $privateCaptchaConfiguration): array
    {
        if (!array_key_exists('backend', $privateCaptchaConfiguration)) {
            return [];
        }

        $configuration = $privateCaptchaConfiguration['backend'];
        if (!is_array($configuration)) {
            throw new \InvalidArgumentException('Persisted backend CAPTCHA configuration must be an array.');
        }

        /** @var array<string, mixed> $configuration */
        return $configuration;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function save(#[\SensitiveParameter] array $configuration): void
    {
        try {
            $localConfiguration = $this->localRootConfiguration();
            $localConfiguration['backend'] = $configuration;
            if (!$this->configurationManager->setLocalConfigurationValueByPath(
                'EXTENSIONS/private_captcha',
                $localConfiguration,
            )) {
                throw new \RuntimeException('Private Captcha configuration was not written.');
            }
            $runtimeConfiguration = $this->runtimeRootConfiguration();
            $runtimeConfiguration['backend'] = $configuration;
            $this->setRuntimeRootConfiguration($runtimeConfiguration);
        } catch (\Throwable) {
            throw new \RuntimeException('Unable to persist Private Captcha configuration.');
        }
    }

    public function disableLoginProtection(): void
    {
        try {
            if (!$this->configurationManager->setLocalConfigurationValueByPath(
                'EXTENSIONS/private_captcha/backend/backendLoginEnabled',
                false,
            )) {
                throw new \RuntimeException('Private Captcha configuration was not written.');
            }

            $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
            if (!is_array($typo3Configuration)) {
                $typo3Configuration = [];
            }
            $extensions = $typo3Configuration['EXTENSIONS'] ?? [];
            if (!is_array($extensions)) {
                $extensions = [];
            }
            $privateCaptchaConfiguration = $extensions['private_captcha'] ?? [];
            if (!is_array($privateCaptchaConfiguration)) {
                $privateCaptchaConfiguration = [];
            }
            /** @var array<string, mixed> $privateCaptchaConfiguration */
            $backendConfiguration = $privateCaptchaConfiguration['backend'] ?? [];
            if (!is_array($backendConfiguration)) {
                $backendConfiguration = [];
            }
            $backendConfiguration['backendLoginEnabled'] = false;
            $privateCaptchaConfiguration['backend'] = $backendConfiguration;
            $this->setRuntimeRootConfiguration($privateCaptchaConfiguration);
        } catch (\Throwable) {
            throw new \RuntimeException('Unable to persist Private Captcha configuration.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeRootConfiguration(): array
    {
        try {
            $privateCaptchaConfiguration = $this->extensionConfiguration->get('private_captcha');
        } catch (ExtensionConfigurationExtensionNotConfiguredException) {
            return [];
        }
        if (!is_array($privateCaptchaConfiguration)) {
            throw new \InvalidArgumentException('Persisted CAPTCHA extension configuration must be an array.');
        }

        /** @var array<string, mixed> $privateCaptchaConfiguration */
        return $privateCaptchaConfiguration;
    }

    /**
     * @return array<string, mixed>
     */
    private function localRootConfiguration(): array
    {
        $localConfiguration = $this->configurationManager->getLocalConfiguration();
        $extensions = $localConfiguration['EXTENSIONS'] ?? [];
        if (!is_array($extensions)) {
            throw new \InvalidArgumentException('Persisted extension configuration must be an array.');
        }
        $privateCaptchaConfiguration = $extensions['private_captcha'] ?? [];
        if (!is_array($privateCaptchaConfiguration)) {
            throw new \InvalidArgumentException('Persisted CAPTCHA extension configuration must be an array.');
        }

        /** @var array<string, mixed> $privateCaptchaConfiguration */
        return $privateCaptchaConfiguration;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function setRuntimeRootConfiguration(#[\SensitiveParameter] array $configuration): void
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3Configuration)) {
            $typo3Configuration = [];
        }
        $extensions = $typo3Configuration['EXTENSIONS'] ?? [];
        if (!is_array($extensions)) {
            $extensions = [];
        }
        $extensions['private_captcha'] = $configuration;
        $typo3Configuration['EXTENSIONS'] = $extensions;
        $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;
    }
}

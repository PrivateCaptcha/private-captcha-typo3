<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Configuration;

use PrivateCaptcha\Typo3\ValueObject\IntegrationConfiguration;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * @internal
 */
final readonly class ConfigurationResolver
{
    public const SITE_API_KEYS_ENV = 'PRIVATE_CAPTCHA_SITE_API_KEYS';

    public const BACKEND_API_KEY_ENV = 'PRIVATE_CAPTCHA_BACKEND_API_KEY';

    public const DISABLE_BACKEND_LOGIN_ENV = 'PRIVATE_CAPTCHA_DISABLE_BACKEND_LOGIN';

    private const MAX_SITE_API_KEYS_ENV_BYTES = 65536;

    private const MAX_SITE_API_KEY_OVERRIDES = 1024;

    public function __construct(
        private SiteConfigurationRepository $siteConfigurationRepository,
        private BackendConfigurationRepository $backendConfigurationRepository,
        private ConfigurationNormalizer $configurationNormalizer,
        private EndpointSelector $endpointSelector,
    ) {}

    public function resolveSite(#[\SensitiveParameter] Site $site): ResolvedCaptchaConfiguration
    {
        return $this->resolveSiteCandidate($site, $this->siteConfigurationRepository->get($site));
    }

    /**
     * @param array<string, mixed> $input
     */
    public function resolveSiteCandidate(
        #[\SensitiveParameter]
        Site $site,
        #[\SensitiveParameter]
        array $input,
    ): ResolvedCaptchaConfiguration {
        $input = $this->applyApiKey($input, $this->siteApiKeyOverride($site->getIdentifier()));

        return $this->resolve($input, backendLoginOnly: false);
    }

    public function resolveBackend(): ResolvedCaptchaConfiguration
    {
        if ($this->backendLoginEmergencyDisabled()) {
            return $this->resolve(['apiKey' => ''], backendLoginOnly: true);
        }

        return $this->resolveBackendCandidate($this->backendConfigurationRepository->get());
    }

    /**
     * @param array<string, mixed> $input
     */
    public function resolveBackendCandidate(#[\SensitiveParameter] array $input): ResolvedCaptchaConfiguration
    {
        if ($this->backendLoginEmergencyDisabled()) {
            return $this->resolve(['apiKey' => ''], backendLoginOnly: true);
        }
        if ($this->backendLoginExplicitlyDisabled($input)) {
            return $this->resolve(['apiKey' => ''], backendLoginOnly: true);
        }

        $input = $this->applyApiKey($input, $this->environmentValue(self::BACKEND_API_KEY_ENV));

        return $this->resolve($input, backendLoginOnly: true);
    }

    public function hasSiteApiKeyOverride(#[\SensitiveParameter] Site $site): bool
    {
        return $this->siteApiKeyOverride($site->getIdentifier()) !== null;
    }

    public function hasBackendApiKeyOverride(): bool
    {
        return $this->environmentValue(self::BACKEND_API_KEY_ENV) !== null;
    }

    /**
     * Null means no override is present; false means the present override is unusable.
     */
    public function backendApiKeyOverrideIsUsable(): ?bool
    {
        $value = $this->environmentValue(self::BACKEND_API_KEY_ENV);
        if ($value === null) {
            return null;
        }

        try {
            return $this->configurationNormalizer->normalizeApiKey($value) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function resolve(
        #[\SensitiveParameter]
        array $input,
        bool $backendLoginOnly,
    ): ResolvedCaptchaConfiguration {
        $configuration = $this->configurationNormalizer->normalizePersisted($input);
        $apiKey = $configuration->apiKeyReplacement();
        if ($apiKey === null) {
            throw new \LogicException('Resolved configuration must contain an API key value.');
        }
        $requestedIntegrations = $configuration->integrations->forScope($backendLoginOnly);
        $integrations = $apiKey === '' || $configuration->sitekey === ''
            ? IntegrationConfiguration::disabled()
            : $requestedIntegrations;

        return new ResolvedCaptchaConfiguration(
            apiKey: $apiKey,
            sitekey: $configuration->sitekey,
            widget: $configuration->widget,
            integrations: $integrations,
            requestedIntegrations: $requestedIntegrations,
            endpoints: $this->endpointSelector->select(
                euIsolation: $configuration->euIsolation,
                customRootDomain: $configuration->customRootDomain,
            ),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function applyApiKey(
        #[\SensitiveParameter]
        array $input,
        #[\SensitiveParameter]
        ?string $override,
    ): array {
        if ($override !== null) {
            $input['apiKey'] = $override;
        } elseif (!array_key_exists('apiKey', $input)) {
            $input['apiKey'] = '';
        }

        return $input;
    }

    private function siteApiKeyOverride(string $siteIdentifier): ?string
    {
        $encodedOverrides = $this->environmentValue(self::SITE_API_KEYS_ENV);
        if ($encodedOverrides === null) {
            return null;
        }
        if (strlen($encodedOverrides) > self::MAX_SITE_API_KEYS_ENV_BYTES) {
            throw new \InvalidArgumentException('Site API key overrides must not exceed 65536 bytes.');
        }

        try {
            $decodedOverrides = json_decode($encodedOverrides, depth: 2, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Site API key overrides must be a valid JSON object.');
        }
        if (!$decodedOverrides instanceof \stdClass) {
            throw new \InvalidArgumentException('Site API key overrides must be a valid JSON object.');
        }

        $overrides = get_object_vars($decodedOverrides);
        if (count($overrides) > self::MAX_SITE_API_KEY_OVERRIDES) {
            throw new \InvalidArgumentException('Site API key overrides must not contain more than 1024 entries.');
        }
        if (!array_key_exists($siteIdentifier, $overrides)) {
            return null;
        }

        return $this->configurationNormalizer->normalizeApiKey($overrides[$siteIdentifier]);
    }

    public function backendLoginEmergencyDisabled(): bool
    {
        $value = $this->environmentValue(self::DISABLE_BACKEND_LOGIN_ENV);

        return $value !== null && !in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function backendLoginExplicitlyDisabled(array $input): bool
    {
        return array_key_exists('backendLoginEnabled', $input)
            && in_array($input['backendLoginEnabled'], [false, 0, '0', 'false', 'off', ''], true);
    }

    private function environmentValue(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

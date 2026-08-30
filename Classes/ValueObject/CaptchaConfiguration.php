<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\ValueObject;

/**
 * @internal
 */
final readonly class CaptchaConfiguration
{
    private \SensitiveParameterValue $apiKeyReplacement;

    public function __construct(
        // Null represents the unchanged-secret placeholder; an empty string explicitly clears the key.
        #[\SensitiveParameter]
        ?string $apiKeyReplacement,
        public string $sitekey,
        public WidgetConfiguration $widget,
        public IntegrationConfiguration $integrations,
        public bool $euIsolation,
        public string $customRootDomain,
    ) {
        $this->apiKeyReplacement = new \SensitiveParameterValue($apiKeyReplacement);
    }

    public function apiKeyReplacement(): ?string
    {
        $apiKeyReplacement = $this->apiKeyReplacement->getValue();
        if ($apiKeyReplacement !== null && !is_string($apiKeyReplacement)) {
            throw new \LogicException('API key replacement must be a string or null.');
        }

        return $apiKeyReplacement;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'sitekey' => $this->sitekey,
            'widget' => $this->widget,
            'integrations' => $this->integrations,
            'euIsolation' => $this->euIsolation,
            'customRootDomain' => $this->customRootDomain,
        ];
    }
}

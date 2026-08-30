<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\ValueObject;

/**
 * @internal
 */
final readonly class ResolvedCaptchaConfiguration implements \JsonSerializable
{
    private \SensitiveParameterValue $apiKey;

    public function __construct(
        #[\SensitiveParameter]
        string $apiKey,
        public string $sitekey,
        public WidgetConfiguration $widget,
        public IntegrationConfiguration $integrations,
        public IntegrationConfiguration $requestedIntegrations,
        public EndpointConfiguration $endpoints,
    ) {
        $this->apiKey = new \SensitiveParameterValue($apiKey);
    }

    public function apiKey(): string
    {
        $apiKey = $this->apiKey->getValue();
        if (!is_string($apiKey)) {
            throw new \LogicException('Resolved API key must be a string.');
        }

        return $apiKey;
    }

    public function withSitekey(string $sitekey): self
    {
        return new self(
            apiKey: $this->apiKey(),
            sitekey: $sitekey,
            widget: $this->widget,
            integrations: $this->integrations,
            requestedIntegrations: $this->requestedIntegrations,
            endpoints: $this->endpoints,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->safeValues();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->safeValues();
    }

    /**
     * @return array<string, mixed>
     */
    private function safeValues(): array
    {
        return [
            'sitekey' => $this->sitekey,
            'widget' => $this->widget,
            'integrations' => $this->integrations,
            'requestedIntegrations' => $this->requestedIntegrations,
            'endpoints' => $this->endpoints,
        ];
    }
}

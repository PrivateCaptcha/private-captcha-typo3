<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Configuration;

use PrivateCaptcha\Typo3\ValueObject\CaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\IntegrationConfiguration;
use PrivateCaptcha\Typo3\ValueObject\WidgetConfiguration;

/**
 * @internal
 */
final class ConfigurationNormalizer
{
    public const UNCHANGED_API_KEY = '__private_captcha_api_key_unchanged__';

    private const MAX_CUSTOM_STYLES_BYTES = 2048;

    private const MAX_API_KEY_BYTES = 4096;

    private const THEMES = ['light', 'dark'];

    private const LANGUAGES = ['auto', 'en', 'de', 'es', 'fr', 'it', 'nl', 'sv', 'no', 'pl', 'fi', 'et', 'uk', 'tr'];

    private const START_MODES = ['auto', 'click'];

    private const BOOLEAN_INPUT_VALUES = [true, false, 1, 0, '1', '0', 'true', 'false', 'on', 'off', ''];

    /**
     * @param array<string, mixed> $input
     */
    public function normalize(#[\SensitiveParameter] array $input): CaptchaConfiguration
    {
        $apiKeyInput = array_key_exists('apiKey', $input) ? $input['apiKey'] : self::UNCHANGED_API_KEY;
        $apiKey = $this->normalizeApiKey($apiKeyInput);
        $apiKeyReplacement = $apiKey === self::UNCHANGED_API_KEY ? null : $apiKey;
        $sitekeyInput = array_key_exists('sitekey', $input) ? $input['sitekey'] : '';

        return new CaptchaConfiguration(
            apiKeyReplacement: $apiKeyReplacement,
            sitekey: $this->credentialValue($sitekeyInput, 'Sitekey'),
            widget: new WidgetConfiguration(
                theme: $this->allowlistedValue($input['theme'] ?? null, self::THEMES, 'light'),
                language: $this->allowlistedValue($input['language'] ?? null, self::LANGUAGES, 'auto'),
                startMode: $this->allowlistedValue($input['startMode'] ?? null, self::START_MODES, 'auto'),
                debug: $this->booleanValue($input['debug'] ?? false),
                customStyles: $this->customStyles($input['customStyles'] ?? ''),
            ),
            integrations: new IntegrationConfiguration(
                formFramework: $this->booleanValue($input['formFrameworkEnabled'] ?? false),
                powermail: $this->booleanValue($input['powermailEnabled'] ?? false),
                frontendLogin: $this->booleanValue($input['frontendLoginEnabled'] ?? false),
                backendLogin: $this->booleanValue($input['backendLoginEnabled'] ?? false),
            ),
            euIsolation: $this->booleanValue($input['euIsolation'] ?? false),
            customRootDomain: $this->stringValue($input['customRootDomain'] ?? ''),
        );
    }

    public function normalizeApiKey(#[\SensitiveParameter] mixed $value): string
    {
        $apiKey = $this->credentialValue($value, 'API key');
        if (strlen($apiKey) > self::MAX_API_KEY_BYTES) {
            throw new \InvalidArgumentException('API key must not exceed 4096 bytes.');
        }

        return $apiKey;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function normalizePersisted(#[\SensitiveParameter] array $input): CaptchaConfiguration
    {
        foreach (['apiKey', 'sitekey', 'customStyles', 'customRootDomain'] as $field) {
            if (array_key_exists($field, $input) && !is_string($input[$field])) {
                throw new \InvalidArgumentException(sprintf('Persisted %s configuration must be a string.', $field));
            }
        }
        if (($input['apiKey'] ?? null) === self::UNCHANGED_API_KEY) {
            throw new \InvalidArgumentException('Persisted API key configuration uses a reserved value.');
        }

        $allowlists = [
            'theme' => self::THEMES,
            'language' => self::LANGUAGES,
            'startMode' => self::START_MODES,
        ];
        foreach ($allowlists as $field => $allowedValues) {
            if (array_key_exists($field, $input)
                && (!is_string($input[$field]) || !in_array($input[$field], $allowedValues, true))
            ) {
                throw new \InvalidArgumentException(sprintf('Persisted %s configuration is invalid.', $field));
            }
        }

        foreach (['debug', 'formFrameworkEnabled', 'powermailEnabled', 'frontendLoginEnabled', 'backendLoginEnabled', 'euIsolation'] as $field) {
            if (array_key_exists($field, $input) && !in_array($input[$field], self::BOOLEAN_INPUT_VALUES, true)) {
                throw new \InvalidArgumentException(sprintf('Persisted %s configuration is invalid.', $field));
            }
        }

        return $this->normalize($input);
    }

    /**
     * @param list<string> $allowedValues
     */
    private function allowlistedValue(mixed $value, array $allowedValues, string $default): string
    {
        return is_string($value) && in_array($value, $allowedValues, true) ? $value : $default;
    }

    private function booleanValue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }

    private function customStyles(mixed $value): string
    {
        $styles = $this->stringValue($value);
        if (strlen($styles) > self::MAX_CUSTOM_STYLES_BYTES) {
            throw new \InvalidArgumentException('Custom widget styles must not exceed 2048 bytes.');
        }
        $this->assertValidText($styles, 'Custom widget styles');

        return $styles;
    }

    private function credentialValue(#[\SensitiveParameter] mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a string.', $label));
        }
        $this->assertValidText($value, $label);

        return trim($value);
    }

    private function assertValidText(#[\SensitiveParameter] string $value, string $label): void
    {
        $controlCharacterMatch = preg_match('/\p{Cc}/u', $value);
        if ($controlCharacterMatch === false) {
            throw new \InvalidArgumentException(sprintf('%s must be valid UTF-8.', $label));
        }
        if ($controlCharacterMatch === 1) {
            throw new \InvalidArgumentException(sprintf('%s must not contain control characters.', $label));
        }
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}

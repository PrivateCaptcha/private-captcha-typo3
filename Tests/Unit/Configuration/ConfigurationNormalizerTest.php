<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\Typo3\Configuration\ConfigurationNormalizer;

final class ConfigurationNormalizerTest extends TestCase
{
    #[Test]
    public function defaultsMatchApprovedCaptchaConfiguration(): void
    {
        $configuration = (new ConfigurationNormalizer())->normalize([]);

        self::assertNull($configuration->apiKeyReplacement());
        self::assertSame('', $configuration->sitekey);
        self::assertSame('light', $configuration->widget->theme);
        self::assertSame('auto', $configuration->widget->language);
        self::assertSame('auto', $configuration->widget->startMode);
        self::assertFalse($configuration->widget->debug);
        self::assertSame('', $configuration->widget->customStyles);
        self::assertFalse($configuration->euIsolation);
        self::assertSame('', $configuration->customRootDomain);
        self::assertFalse($configuration->integrations->formFramework);
        self::assertFalse($configuration->integrations->powermail);
        self::assertFalse($configuration->integrations->frontendLogin);
        self::assertFalse($configuration->integrations->backendLogin);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('validWidgetValueProvider')]
    public function allowlistsValidCaptchaConfigurationWidgetValues(array $input, string $property, string $expected): void
    {
        $configuration = (new ConfigurationNormalizer())->normalize($input);

        self::assertSame($expected, $configuration->widget->{$property});
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function validWidgetValueProvider(): iterable
    {
        foreach (['light', 'dark'] as $theme) {
            yield 'theme ' . $theme => [['theme' => $theme], 'theme', $theme];
        }

        foreach (['auto', 'en', 'de', 'es', 'fr', 'it', 'nl', 'sv', 'no', 'pl', 'fi', 'et', 'uk', 'tr'] as $language) {
            yield 'language ' . $language => [['language' => $language], 'language', $language];
        }

        foreach (['auto', 'click'] as $startMode) {
            yield 'start mode ' . $startMode => [['startMode' => $startMode], 'startMode', $startMode];
        }
    }

    #[Test]
    public function invalidCaptchaConfigurationValuesFallBackToSafeDefaults(): void
    {
        $configuration = (new ConfigurationNormalizer())->normalize([
            'theme' => 'system',
            'language' => 'invalid',
            'startMode' => 'hidden',
            'euIsolation' => 'yes',
            'debug' => [],
            'formFrameworkEnabled' => 'false',
            'powermailEnabled' => 2,
            'frontendLoginEnabled' => 'off',
            'backendLoginEnabled' => null,
        ]);

        self::assertSame('light', $configuration->widget->theme);
        self::assertSame('auto', $configuration->widget->language);
        self::assertSame('auto', $configuration->widget->startMode);
        self::assertFalse($configuration->widget->debug);
        self::assertFalse($configuration->euIsolation);
        self::assertFalse($configuration->integrations->formFramework);
        self::assertFalse($configuration->integrations->powermail);
        self::assertFalse($configuration->integrations->frontendLogin);
        self::assertFalse($configuration->integrations->backendLogin);
    }

    #[Test]
    public function normalizesEnabledCaptchaConfigurationFlagsFromFormValues(): void
    {
        $configuration = (new ConfigurationNormalizer())->normalize([
            'euIsolation' => true,
            'debug' => 1,
            'formFrameworkEnabled' => '1',
            'powermailEnabled' => 'true',
            'frontendLoginEnabled' => 'on',
            'backendLoginEnabled' => true,
        ]);

        self::assertTrue($configuration->euIsolation);
        self::assertTrue($configuration->widget->debug);
        self::assertTrue($configuration->integrations->formFramework);
        self::assertTrue($configuration->integrations->powermail);
        self::assertTrue($configuration->integrations->frontendLogin);
        self::assertTrue($configuration->integrations->backendLogin);
    }

    #[Test]
    public function distinguishesCaptchaConfigurationApiKeyReplacementFromUnchangedPlaceholder(): void
    {
        $normalizer = new ConfigurationNormalizer();

        $unchanged = $normalizer->normalize(['apiKey' => ConfigurationNormalizer::UNCHANGED_API_KEY]);
        $replacement = $normalizer->normalize(['apiKey' => '  replacement-key  ']);
        $cleared = $normalizer->normalize(['apiKey' => '']);

        self::assertNull($unchanged->apiKeyReplacement());
        self::assertSame('replacement-key', $replacement->apiKeyReplacement());
        self::assertSame('', $cleared->apiKeyReplacement());
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('malformedCredentialProvider')]
    public function rejectsMalformedCaptchaConfigurationCredentials(array $input): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ConfigurationNormalizer())->normalize($input);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedCredentialProvider(): iterable
    {
        yield 'API key array' => [['apiKey' => []]];
        yield 'API key null' => [['apiKey' => null]];
        yield 'sitekey array' => [['sitekey' => []]];
        yield 'sitekey null' => [['sitekey' => null]];
    }

    #[Test]
    #[DataProvider('credentialControlCharacterProvider')]
    public function rejectsControlCharactersInCaptchaConfigurationCredentials(string $field): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ConfigurationNormalizer())->normalize([$field => "value\r\nX-Injected: yes"]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function credentialControlCharacterProvider(): iterable
    {
        yield 'API key' => ['apiKey'];
        yield 'sitekey' => ['sitekey'];
    }

    #[Test]
    public function preservesValidCaptchaConfigurationCustomStyles(): void
    {
        $styles = ' --border-radius: .75rem; --label-spacing: 1.25rem; ';

        $configuration = (new ConfigurationNormalizer())->normalize(['customStyles' => $styles]);

        self::assertSame($styles, $configuration->widget->customStyles);
    }

    #[Test]
    #[DataProvider('controlCharacterProvider')]
    public function rejectsControlCharactersInCaptchaConfigurationCustomStyles(string $controlCharacter): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ConfigurationNormalizer())->normalize([
            'customStyles' => '--border-radius: 1rem;' . $controlCharacter,
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function controlCharacterProvider(): iterable
    {
        yield 'null byte' => ["\0"];
        yield 'tab' => ["\t"];
        yield 'line feed' => ["\n"];
        yield 'carriage return' => ["\r"];
        yield 'delete' => ["\x7f"];
        yield 'next line' => ["\u{0085}"];
        yield 'application program command' => ["\u{009F}"];
    }

    #[Test]
    public function rejectsMalformedUtf8InCaptchaConfigurationCustomStyles(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ConfigurationNormalizer())->normalize(['customStyles' => "--x:\xC0\xAF;"]);
    }

    #[Test]
    public function acceptsCaptchaConfigurationCustomStylesAtTwoKibibyteLimit(): void
    {
        $styles = str_repeat("\u{00E4}", 1024);

        $configuration = (new ConfigurationNormalizer())->normalize(['customStyles' => $styles]);

        self::assertSame($styles, $configuration->widget->customStyles);
    }

    #[Test]
    public function rejectsCaptchaConfigurationCustomStylesOverTwoKibibytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ConfigurationNormalizer())->normalize([
            'customStyles' => str_repeat("\u{00E4}", 1024) . 'a',
        ]);
    }
}

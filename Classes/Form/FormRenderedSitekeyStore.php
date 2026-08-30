<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Form;

use Psr\Clock\ClockInterface;
use TYPO3\CMS\Form\Domain\Runtime\FormState;

/**
 * @internal
 */
final readonly class FormRenderedSitekeyStore
{
    private const FORM_STATE_PREFIX = '__privateCaptchaRenderedSitekeys';

    private const LIFETIME_SECONDS = 1800;

    public function __construct(
        private ?ClockInterface $clock = null,
    ) {}

    public function remember(
        FormState $formState,
        string $siteIdentifier,
        string $formPersistenceIdentifier,
        string $formIdentifier,
        string $elementIdentifier,
        string $sitekey,
    ): void {
        $formState->setFormValue($this->propertyPath($formIdentifier, $elementIdentifier), [
            'siteIdentifier' => $siteIdentifier,
            'formPersistenceIdentifier' => $formPersistenceIdentifier,
            'sitekey' => $sitekey,
            'renderedAt' => $this->now(),
        ]);
    }

    public function recall(
        ?FormState $formState,
        string $siteIdentifier,
        string $formPersistenceIdentifier,
        string $formIdentifier,
        string $elementIdentifier,
    ): ?string {
        $metadata = $formState?->getFormValue($this->propertyPath($formIdentifier, $elementIdentifier));
        if (!is_array($metadata)
            || $siteIdentifier === ''
            || $formPersistenceIdentifier === ''
            || !is_string($metadata['siteIdentifier'] ?? null)
            || !hash_equals($siteIdentifier, $metadata['siteIdentifier'])
            || !is_string($metadata['formPersistenceIdentifier'] ?? null)
            || !hash_equals($formPersistenceIdentifier, $metadata['formPersistenceIdentifier'])
            || !is_string($metadata['sitekey'] ?? null)
            || $metadata['sitekey'] === ''
            || !is_int($metadata['renderedAt'] ?? null)
        ) {
            return null;
        }
        $now = $this->now();
        if ($metadata['renderedAt'] > $now || $metadata['renderedAt'] < $now - self::LIFETIME_SECONDS) {
            return null;
        }

        return $metadata['sitekey'];
    }

    public function forget(
        ?FormState $formState,
        string $formIdentifier,
        string $elementIdentifier,
    ): void {
        $formState?->setFormValue($this->propertyPath($formIdentifier, $elementIdentifier), null);
    }

    private function propertyPath(string $formIdentifier, string $elementIdentifier): string
    {
        return self::FORM_STATE_PREFIX . '.' . hash('sha256', $formIdentifier . "\0" . $elementIdentifier);
    }

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }
}

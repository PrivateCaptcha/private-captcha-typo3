<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Form;

/**
 * @internal
 */
final readonly class FormProofBinding
{
    public function __construct(
        private string $formSessionIdentifier,
        private string $siteIdentifier,
        private string $formIdentifier,
        private string $elementIdentifier,
        private string $sitekey,
        private string $formPersistenceIdentifier,
    ) {}

    public function hash(): string
    {
        return hash('sha256', implode("\0", [
            $this->formSessionIdentifier,
            $this->siteIdentifier,
            $this->formIdentifier,
            $this->elementIdentifier,
            $this->sitekey,
            $this->formPersistenceIdentifier,
        ]));
    }

    public function isFor(
        string $formIdentifier,
        string $elementIdentifier,
        string $formPersistenceIdentifier,
    ): bool {
        return hash_equals($this->formIdentifier, $formIdentifier)
            && hash_equals($this->elementIdentifier, $elementIdentifier)
            && hash_equals($this->formPersistenceIdentifier, $formPersistenceIdentifier);
    }

    public function sitekey(): string
    {
        return $this->sitekey;
    }
}

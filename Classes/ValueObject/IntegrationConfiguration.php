<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\ValueObject;

/**
 * @internal
 */
final readonly class IntegrationConfiguration
{
    public function __construct(
        public bool $formFramework,
        public bool $powermail,
        public bool $frontendLogin,
        public bool $backendLogin,
    ) {}

    public function forScope(bool $backend): self
    {
        return new self(
            !$backend && $this->formFramework,
            !$backend && $this->powermail,
            !$backend && $this->frontendLogin,
            $backend && $this->backendLogin,
        );
    }

    public static function disabled(): self
    {
        return new self(false, false, false, false);
    }
}

<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\ValueObject;

/**
 * @internal
 */
final readonly class WidgetConfiguration
{
    public function __construct(
        public string $theme,
        public string $language,
        public string $startMode,
        public bool $debug,
        public string $customStyles,
    ) {}
}

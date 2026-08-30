<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\ValueObject;

/**
 * @internal
 */
final readonly class ConnectionTestResult implements \JsonSerializable
{
    public const LIMITATION = 'production-sitekey-ownership-and-origin-not-proven';

    private function __construct(
        public bool $successful,
        public string $reason,
        public ?int $providerCode,
        public ?string $exceptionClass,
        public int $attemptCount,
        public int $durationMilliseconds,
    ) {}

    public static function succeeded(
        int $providerCode,
        int $attemptCount,
        int $durationMilliseconds,
    ): self {
        return new self(
            successful: true,
            reason: 'connection-ok',
            providerCode: $providerCode,
            exceptionClass: null,
            attemptCount: $attemptCount,
            durationMilliseconds: $durationMilliseconds,
        );
    }

    public static function failed(
        string $reason,
        ?int $providerCode = null,
        ?string $exceptionClass = null,
        int $attemptCount = 0,
        int $durationMilliseconds = 0,
    ): self {
        return new self(
            successful: false,
            reason: $reason,
            providerCode: $providerCode,
            exceptionClass: $exceptionClass,
            attemptCount: $attemptCount,
            durationMilliseconds: $durationMilliseconds,
        );
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function diagnostics(): array
    {
        return [
            'successful' => $this->successful,
            'reason' => $this->reason,
            'providerCode' => $this->providerCode,
            'exceptionClass' => $this->exceptionClass,
            'attemptCount' => $this->attemptCount,
            'durationMilliseconds' => $this->durationMilliseconds,
            'productionSitekeyOwnershipProven' => false,
            'productionOriginProven' => false,
            'limitation' => self::LIMITATION,
        ];
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function jsonSerialize(): array
    {
        return $this->diagnostics();
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function __debugInfo(): array
    {
        return $this->diagnostics();
    }
}

<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\ValueObject;

/**
 * @internal
 */
final readonly class VerificationResult implements \JsonSerializable
{
    private function __construct(
        public bool $accepted,
        public string $reason,
        public ?int $providerCode,
        public ?string $traceIdHash,
        public ?string $exceptionClass,
        public int $attemptCount,
        public int $durationMilliseconds,
    ) {}

    public static function accepted(
        int $providerCode,
        ?string $traceIdHash,
        int $attemptCount,
        int $durationMilliseconds,
    ): self {
        return new self(
            accepted: true,
            reason: 'accepted',
            providerCode: $providerCode,
            traceIdHash: $traceIdHash,
            exceptionClass: null,
            attemptCount: $attemptCount,
            durationMilliseconds: $durationMilliseconds,
        );
    }

    public static function rejected(
        string $reason,
        ?int $providerCode = null,
        ?string $traceIdHash = null,
        ?string $exceptionClass = null,
        int $attemptCount = 0,
        int $durationMilliseconds = 0,
    ): self {
        return new self(
            accepted: false,
            reason: $reason,
            providerCode: $providerCode,
            traceIdHash: $traceIdHash,
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
            'accepted' => $this->accepted,
            'reason' => $this->reason,
            'providerCode' => $this->providerCode,
            'traceIdHash' => $this->traceIdHash,
            'exceptionClass' => $this->exceptionClass,
            'attemptCount' => $this->attemptCount,
            'durationMilliseconds' => $this->durationMilliseconds,
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

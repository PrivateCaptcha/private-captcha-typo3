<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Service;

/**
 * @internal
 */
final class SolutionVault
{
    /** @var array<string, array{solution: mixed, context: mixed}> */
    private array $submissions = [];

    public function capture(#[\SensitiveParameter] mixed $solution, mixed $context): string
    {
        $nonce = bin2hex(random_bytes(32));
        $this->submissions[$nonce] = [
            'solution' => $solution,
            'context' => $context,
        ];

        return $nonce;
    }

    /**
     * @return array{solution: mixed, context: mixed}|null
     */
    public function consume(string $nonce): ?array
    {
        $submission = $this->submissions[$nonce] ?? null;
        unset($this->submissions[$nonce]);

        return $submission;
    }

    public function discard(string $nonce): void
    {
        unset($this->submissions[$nonce]);
    }
}

<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Service;

use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\VerificationResult;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
final readonly class CaptchaVerifier implements CaptchaVerifierInterface
{
    private const ATTEMPTS = 1;

    private const MAX_BACKOFF_SECONDS = 1;

    private const MAX_SOLUTION_BYTES = 16384;

    public function __construct(
        private PrivateCaptchaClientFactoryInterface $clientFactory,
        private LoggerInterface $logger,
    ) {}

    public function verify(
        #[\SensitiveParameter]
        mixed $solution,
        #[\SensitiveParameter]
        ResolvedCaptchaConfiguration $configuration,
    ): VerificationResult {
        $startedAt = microtime(true);
        $apiKey = $configuration->apiKey();
        if ($apiKey === '' || $configuration->sitekey === '') {
            return VerificationResult::rejected('missing-configuration');
        }
        if ($solution === null || $solution === '' || (is_string($solution) && trim($solution) === '')) {
            return VerificationResult::rejected('missing-solution');
        }
        if (!is_string($solution)) {
            return VerificationResult::rejected('invalid-solution');
        }
        if (strlen($solution) > self::MAX_SOLUTION_BYTES) {
            return VerificationResult::rejected('oversized-solution');
        }

        $attemptCount = 0;
        try {
            $client = $this->clientFactory->create($configuration);
            $attemptCount = self::ATTEMPTS;
            $output = $client->verify(
                solution: $solution,
                maxBackoffSeconds: self::MAX_BACKOFF_SECONDS,
                attempts: self::ATTEMPTS,
                sitekey: $configuration->sitekey,
            );
            $traceIdHash = $this->traceIdHash($output->getRequestId());
            $durationMilliseconds = $this->durationMilliseconds($startedAt);
            $result = $output->isOK()
                ? VerificationResult::accepted(
                    providerCode: $output->code->value,
                    traceIdHash: $traceIdHash,
                    attemptCount: $attemptCount,
                    durationMilliseconds: $durationMilliseconds,
                )
                : VerificationResult::rejected(
                    reason: 'provider-rejected',
                    providerCode: $output->code->value,
                    traceIdHash: $traceIdHash,
                    attemptCount: $attemptCount,
                    durationMilliseconds: $durationMilliseconds,
                );
        } catch (\Throwable $exception) {
            $result = VerificationResult::rejected(
                reason: 'verification-error',
                exceptionClass: $exception::class,
                attemptCount: $attemptCount,
                durationMilliseconds: $this->durationMilliseconds($startedAt),
            );
        }

        if (!$result->accepted) {
            $this->logger->warning('Private Captcha verification failed.', $result->diagnostics());
        }

        return $result;
    }

    private function traceIdHash(?string $traceId): ?string
    {
        if ($traceId === null || $traceId === '' || strlen($traceId) > 1024) {
            return null;
        }

        return 'sha256:' . hash('sha256', $traceId);
    }

    private function durationMilliseconds(float $startedAt): int
    {
        return max(0, (int)round((microtime(true) - $startedAt) * 1000));
    }
}

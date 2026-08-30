<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Service;

use PrivateCaptcha\Enums\VerifyCode;
use PrivateCaptcha\Typo3\ValueObject\ConnectionTestResult;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
final readonly class ConnectionTester
{
    private const TEST_SITEKEY = 'aaaaaaaabbbbccccddddeeeeeeeeeeee';

    private const ATTEMPTS = 1;

    private const MAX_BACKOFF_SECONDS = 1;

    private const SOLUTIONS_COUNT = 16;

    private const SOLUTION_LENGTH = 8;

    public function __construct(
        private PrivateCaptchaClientFactoryInterface $clientFactory,
        private TestPuzzleClientInterface $testPuzzleClient,
        private LoggerInterface $logger,
    ) {}

    public function test(
        #[\SensitiveParameter]
        ResolvedCaptchaConfiguration $configuration,
    ): ConnectionTestResult {
        $startedAt = microtime(true);
        if ($configuration->apiKey() === '' || $configuration->sitekey === '') {
            return ConnectionTestResult::failed('missing-configuration');
        }

        $attemptCount = 0;
        try {
            $client = $this->clientFactory->create($configuration);
            $puzzle = $this->testPuzzleClient->fetch(
                $configuration->endpoints,
                self::TEST_SITEKEY,
            );
            $solution = base64_encode(str_repeat("\0", self::SOLUTIONS_COUNT * self::SOLUTION_LENGTH)) . '.' . $puzzle;
            $attemptCount = self::ATTEMPTS;
            $output = $client->verify(
                solution: $solution,
                maxBackoffSeconds: self::MAX_BACKOFF_SECONDS,
                attempts: self::ATTEMPTS,
                sitekey: self::TEST_SITEKEY,
            );
            $durationMilliseconds = $this->durationMilliseconds($startedAt);
            $result = $output->success && $output->code === VerifyCode::TEST_PROPERTY_ERROR
                ? ConnectionTestResult::succeeded(
                    providerCode: $output->code->value,
                    attemptCount: $attemptCount,
                    durationMilliseconds: $durationMilliseconds,
                )
                : ConnectionTestResult::failed(
                    reason: 'provider-rejected',
                    providerCode: $output->code->value,
                    attemptCount: $attemptCount,
                    durationMilliseconds: $durationMilliseconds,
                );
        } catch (\Throwable $exception) {
            $result = ConnectionTestResult::failed(
                reason: 'connection-error',
                exceptionClass: $exception::class,
                attemptCount: $attemptCount,
                durationMilliseconds: $this->durationMilliseconds($startedAt),
            );
        }

        if (!$result->successful) {
            $this->logger->warning('Private Captcha connection test failed.', $result->diagnostics());
        }

        return $result;
    }

    private function durationMilliseconds(float $startedAt): int
    {
        return max(0, (int)round((microtime(true) - $startedAt) * 1000));
    }
}

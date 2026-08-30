<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\Client;
use PrivateCaptcha\Enums\VerifyCode;
use PrivateCaptcha\Exceptions\HttpException;
use PrivateCaptcha\Exceptions\VerificationFailedException;
use PrivateCaptcha\Models\VerifyOutput;
use PrivateCaptcha\Typo3\Service\CaptchaVerifier;
use PrivateCaptcha\Typo3\Service\PrivateCaptchaClientFactoryInterface;
use PrivateCaptcha\Typo3\ValueObject\EndpointConfiguration;
use PrivateCaptcha\Typo3\ValueObject\IntegrationConfiguration;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\WidgetConfiguration;
use Psr\Log\AbstractLogger;

final class CaptchaVerifierTest extends TestCase
{
    #[Test]
    public function verifiesThroughOfficialClientWithBoundedSingleAttemptAndExpectedSitekey(): void
    {
        $solution = bin2hex(random_bytes(32));
        $configuration = $this->configuration(
            apiKey: bin2hex(random_bytes(16)),
            sitekey: 'rendered-sitekey',
            apiDomainOverride: Client::EU_DOMAIN,
        );
        $client = new RecordingClient(new VerifyOutput(
            success: true,
            code: VerifyCode::NO_ERROR,
            requestId: 'trace-123',
            attempt: 1,
        ));
        $factory = new RecordingClientFactory($client);
        $logger = new CapturingLogger();

        $result = (new CaptchaVerifier($factory, $logger))->verify($solution, $configuration);

        self::assertTrue($result->accepted);
        self::assertSame('accepted', $result->reason);
        self::assertSame(VerifyCode::NO_ERROR->value, $result->providerCode);
        self::assertSame('sha256:' . hash('sha256', 'trace-123'), $result->traceIdHash);
        self::assertSame(1, $result->attemptCount);
        self::assertSame(hash('sha256', $solution), $client->solutionHash);
        self::assertSame(1, $client->attempts);
        self::assertSame(1, $client->maxBackoffSeconds);
        self::assertSame('rendered-sitekey', $client->sitekey);
        self::assertSame([], $logger->records);
    }

    #[Test]
    #[DataProvider('invalidSolutionProvider')]
    public function rejectsInvalidSolutionsBeforeCreatingClient(mixed $solution, string $expectedReason): void
    {
        $factory = new RecordingClientFactory(new \LogicException('Invalid solutions must not create a client.'));

        $result = (new CaptchaVerifier($factory, new CapturingLogger()))->verify(
            $solution,
            $this->configuration(bin2hex(random_bytes(16)), 'rendered-sitekey'),
        );

        self::assertFalse($result->accepted);
        self::assertSame($expectedReason, $result->reason);
        self::assertSame(0, $result->attemptCount);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidSolutionProvider(): iterable
    {
        yield 'missing' => [null, 'missing-solution'];
        yield 'empty' => ['', 'missing-solution'];
        yield 'whitespace' => ['   ', 'missing-solution'];
        yield 'array' => [[], 'invalid-solution'];
        yield 'integer' => [123, 'invalid-solution'];
        yield 'oversized' => [str_repeat('a', 16_385), 'oversized-solution'];
    }

    #[Test]
    public function rejectsMissingCredentialsBeforeCreatingClient(): void
    {
        $factory = new RecordingClientFactory(new \LogicException('Missing credentials must not create a client.'));
        $verifier = new CaptchaVerifier($factory, new CapturingLogger());

        $missingApiKey = $verifier->verify(
            bin2hex(random_bytes(32)),
            $this->configuration('', 'rendered-sitekey'),
        );
        $missingSitekey = $verifier->verify(
            bin2hex(random_bytes(32)),
            $this->configuration(bin2hex(random_bytes(16)), ''),
        );

        self::assertFalse($missingApiKey->accepted);
        self::assertSame('missing-configuration', $missingApiKey->reason);
        self::assertFalse($missingSitekey->accepted);
        self::assertSame('missing-configuration', $missingSitekey->reason);
    }

    #[Test]
    #[DataProvider('providerRejectionProvider')]
    public function rejectsEveryNonOkProviderCode(VerifyCode $code): void
    {
        $factory = new RecordingClientFactory(new RecordingClient(new VerifyOutput(true, $code)));
        $logger = new CapturingLogger();

        $result = (new CaptchaVerifier($factory, $logger))->verify(
            bin2hex(random_bytes(32)),
            $this->configuration(bin2hex(random_bytes(16)), 'rendered-sitekey'),
        );

        self::assertFalse($result->accepted);
        self::assertSame('provider-rejected', $result->reason);
        self::assertSame($code->value, $result->providerCode);
        self::assertSame(1, $result->attemptCount);
        self::assertCount(1, $logger->records);
        self::assertSame($result->diagnostics(), $logger->records[0]['context']);
    }

    /**
     * @return iterable<string, array{VerifyCode}>
     */
    public static function providerRejectionProvider(): iterable
    {
        foreach (VerifyCode::cases() as $code) {
            if ($code !== VerifyCode::NO_ERROR) {
                yield $code->name => [$code];
            }
        }
    }

    #[Test]
    public function rejectsUnsuccessfulResponseEvenWithNoErrorCode(): void
    {
        $factory = new RecordingClientFactory(new RecordingClient(new VerifyOutput(false, VerifyCode::NO_ERROR)));

        $result = (new CaptchaVerifier($factory, new CapturingLogger()))->verify(
            bin2hex(random_bytes(32)),
            $this->configuration(bin2hex(random_bytes(16)), 'rendered-sitekey'),
        );

        self::assertFalse($result->accepted);
        self::assertSame('provider-rejected', $result->reason);
        self::assertSame(VerifyCode::NO_ERROR->value, $result->providerCode);
    }

    #[Test]
    #[DataProvider('sdkFailureProvider')]
    public function mapsSdkFailuresToFailClosedResults(\Throwable $failure): void
    {
        $factory = new RecordingClientFactory(new RecordingClient($failure));
        $logger = new CapturingLogger();

        $result = (new CaptchaVerifier($factory, $logger))->verify(
            bin2hex(random_bytes(32)),
            $this->configuration(bin2hex(random_bytes(16)), 'rendered-sitekey'),
        );

        self::assertFalse($result->accepted);
        self::assertSame('verification-error', $result->reason);
        self::assertSame($failure::class, $result->exceptionClass);
        self::assertSame(1, $result->attemptCount);
        self::assertCount(1, $logger->records);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function sdkFailureProvider(): iterable
    {
        yield 'timeout' => [new VerificationFailedException('timeout', 1)];
        yield 'transport' => [new VerificationFailedException('transport', 1)];
        yield 'HTTP 429' => [new VerificationFailedException('rate limited', 1, 'trace-429')];
        yield 'HTTP 5xx' => [new VerificationFailedException('provider unavailable', 1, 'trace-500')];
        yield 'HTTP rejection' => [new HttpException(400, 'trace-400')];
        yield 'malformed response' => [new \TypeError('malformed response')];
    }

    #[Test]
    public function mapsClientConstructionFailureWithoutClaimingAnAttempt(): void
    {
        $factory = new RecordingClientFactory(new \RuntimeException('client construction failed'));

        $result = (new CaptchaVerifier($factory, new CapturingLogger()))->verify(
            bin2hex(random_bytes(32)),
            $this->configuration(bin2hex(random_bytes(16)), 'rendered-sitekey'),
        );

        self::assertFalse($result->accepted);
        self::assertSame('verification-error', $result->reason);
        self::assertSame(0, $result->attemptCount);
    }

    #[Test]
    public function diagnosticsAndLogsDoNotRetainSecretsOrExceptionMessages(): void
    {
        $apiKey = bin2hex(random_bytes(16));
        $solution = bin2hex(random_bytes(32));
        $password = bin2hex(random_bytes(16));
        $formContent = bin2hex(random_bytes(24));
        $failure = new VerificationFailedException(
            implode(':', [$apiKey, $solution, $password, $formContent]),
            1,
            $solution,
        );
        $logger = new CapturingLogger();

        $result = (new CaptchaVerifier(
            new RecordingClientFactory(new RecordingClient($failure)),
            $logger,
        ))->verify($solution, $this->configuration($apiKey, 'rendered-sitekey'));

        self::assertNull($result->traceIdHash);
        self::assertSame(VerificationFailedException::class, $result->exceptionClass);
        $diagnosticOutput = implode("\n", [
            json_encode($result, JSON_THROW_ON_ERROR),
            serialize($result),
            print_r([$result, $logger->records], true),
            var_export([$result, $logger->records], true),
        ]);
        foreach ([$apiKey, $solution, $password, $formContent] as $secret) {
            self::assertStringNotContainsString($secret, $diagnosticOutput);
        }
    }

    #[Test]
    public function hashesProviderTraceIdBeforeDiagnostics(): void
    {
        $apiKey = bin2hex(random_bytes(16));
        $solution = bin2hex(random_bytes(32));
        $encodedApiKey = bin2hex($apiKey);
        $logger = new CapturingLogger();
        $output = new VerifyOutput(
            success: false,
            code: VerifyCode::PUZZLE_EXPIRED_ERROR,
            requestId: $encodedApiKey,
            attempt: 1,
        );

        $result = (new CaptchaVerifier(
            new RecordingClientFactory(new RecordingClient($output)),
            $logger,
        ))->verify(
            $solution,
            $this->configuration($apiKey, 'rendered-sitekey'),
        );

        self::assertSame('sha256:' . hash('sha256', $encodedApiKey), $result->traceIdHash);
        $diagnostics = json_encode([$result, $logger->records], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($apiKey, $diagnostics);
        self::assertStringNotContainsString($solution, $diagnostics);
        self::assertStringNotContainsString($encodedApiKey, $diagnostics);
    }

    private function configuration(
        string $apiKey,
        string $sitekey,
        ?string $apiDomainOverride = null,
    ): ResolvedCaptchaConfiguration {
        return new ResolvedCaptchaConfiguration(
            apiKey: $apiKey,
            sitekey: $sitekey,
            widget: new WidgetConfiguration('light', 'auto', 'auto', false, ''),
            integrations: new IntegrationConfiguration(true, false, false, false),
            requestedIntegrations: new IntegrationConfiguration(true, false, false, false),
            endpoints: new EndpointConfiguration(
                apiDomainOverride: $apiDomainOverride,
                puzzleEndpointOverride: null,
                cdnBaseUrl: 'https://cdn.privatecaptcha.com',
                euIsolation: $apiDomainOverride === Client::EU_DOMAIN,
            ),
        );
    }
}

final class RecordingClientFactory implements PrivateCaptchaClientFactoryInterface
{
    public function __construct(
        private readonly Client|\Throwable $outcome,
    ) {}

    public function create(
        ResolvedCaptchaConfiguration $configuration,
    ): Client {
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

final class RecordingClient extends Client
{
    public ?string $solutionHash = null;

    public ?int $maxBackoffSeconds = null;

    public ?int $attempts = null;

    public ?string $sitekey = null;

    public function __construct(
        private readonly VerifyOutput|\Throwable $outcome,
    ) {}

    public function verify(
        string $solution,
        int $maxBackoffSeconds = 20,
        int $attempts = 5,
        ?string $sitekey = null,
    ): VerifyOutput {
        $this->solutionHash = hash('sha256', $solution);
        $this->maxBackoffSeconds = $maxBackoffSeconds;
        $this->attempts = $attempts;
        $this->sitekey = $sitekey;

        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

final class CapturingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }
}

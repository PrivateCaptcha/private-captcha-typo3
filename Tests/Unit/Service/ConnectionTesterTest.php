<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\Client;
use PrivateCaptcha\Enums\VerifyCode;
use PrivateCaptcha\Models\VerifyOutput;
use PrivateCaptcha\Typo3\Service\ConnectionTester;
use PrivateCaptcha\Typo3\Service\PrivateCaptchaClientFactoryInterface;
use PrivateCaptcha\Typo3\Service\TestPuzzleClientInterface;
use PrivateCaptcha\Typo3\ValueObject\EndpointConfiguration;
use PrivateCaptcha\Typo3\ValueObject\IntegrationConfiguration;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\WidgetConfiguration;
use Psr\Log\AbstractLogger;

final class ConnectionTesterTest extends TestCase
{
    private const TEST_SITEKEY = 'aaaaaaaabbbbccccddddeeeeeeeeeeee';

    #[Test]
    public function provesApiAuthorizationAndConnectivityWithoutClaimingProductionPropertyProof(): void
    {
        $puzzle = base64_encode(random_bytes(128));
        $configuration = $this->configuration(
            apiKey: bin2hex(random_bytes(16)),
            sitekey: 'production-sitekey',
            apiDomainOverride: Client::EU_DOMAIN,
        );
        $client = new ConnectionRecordingClient(new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR));
        $factory = new ConnectionRecordingFactory($client);
        $puzzleClient = new ConnectionRecordingPuzzleClient($puzzle);
        $logger = new ConnectionCapturingLogger();

        $result = (new ConnectionTester($factory, $puzzleClient, $logger))->test($configuration);

        $expectedPayload = base64_encode(str_repeat("\0", 16 * 8)) . '.' . $puzzle;
        self::assertTrue($result->successful);
        self::assertSame('connection-ok', $result->reason);
        self::assertSame(VerifyCode::TEST_PROPERTY_ERROR->value, $result->providerCode);
        self::assertSame(1, $result->attemptCount);
        self::assertSame($configuration->endpoints, $puzzleClient->endpoints);
        self::assertSame(self::TEST_SITEKEY, $puzzleClient->sitekey);
        self::assertSame(hash('sha256', $expectedPayload), $client->solutionHash);
        self::assertSame(self::TEST_SITEKEY, $client->sitekey);
        self::assertSame(1, $client->attempts);
        self::assertSame(1, $client->maxBackoffSeconds);
        self::assertSame([], $logger->records);
    }

    #[Test]
    public function rejectsMissingConfiguredCredentialsWithoutNetworkActivity(): void
    {
        $factory = new ConnectionRecordingFactory(new \LogicException('Missing credentials must not create a client.'));
        $puzzleClient = new ConnectionRecordingPuzzleClient(new \LogicException('Missing credentials must not fetch a puzzle.'));
        $tester = new ConnectionTester($factory, $puzzleClient, new ConnectionCapturingLogger());

        $missingApiKey = $tester->test($this->configuration('', 'production-sitekey'));
        $missingSitekey = $tester->test($this->configuration(bin2hex(random_bytes(16)), ''));

        self::assertFalse($missingApiKey->successful);
        self::assertSame('missing-configuration', $missingApiKey->reason);
        self::assertFalse($missingSitekey->successful);
        self::assertSame('missing-configuration', $missingSitekey->reason);
    }

    #[Test]
    #[DataProvider('nonTestSuccessProvider')]
    public function rejectsEveryOutcomeExceptSuccessfulTestProperty(bool $success, VerifyCode $code): void
    {
        $factory = new ConnectionRecordingFactory(new ConnectionRecordingClient(new VerifyOutput($success, $code)));
        $logger = new ConnectionCapturingLogger();

        $result = (new ConnectionTester(
            $factory,
            new ConnectionRecordingPuzzleClient(base64_encode(random_bytes(128))),
            $logger,
        ))->test($this->configuration(bin2hex(random_bytes(16)), 'production-sitekey'));

        self::assertFalse($result->successful);
        self::assertSame('provider-rejected', $result->reason);
        self::assertSame($code->value, $result->providerCode);
        self::assertCount(1, $logger->records);
        self::assertSame($result->diagnostics(), $logger->records[0]['context']);
    }

    /**
     * @return iterable<string, array{bool, VerifyCode}>
     */
    public static function nonTestSuccessProvider(): iterable
    {
        yield 'unsuccessful test property' => [false, VerifyCode::TEST_PROPERTY_ERROR];
        foreach (VerifyCode::cases() as $code) {
            if ($code !== VerifyCode::TEST_PROPERTY_ERROR) {
                yield $code->name => [true, $code];
            }
        }
    }

    #[Test]
    #[DataProvider('failureStageProvider')]
    public function mapsFailuresWithoutClaimingUnmadeVerificationAttempts(string $stage, int $expectedAttempts): void
    {
        $failure = new \RuntimeException('connection test failed');
        $factoryOutcome = $stage === 'factory'
            ? $failure
            : new ConnectionRecordingClient($stage === 'verification'
                ? $failure
                : new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR));
        $puzzleOutcome = $stage === 'puzzle' ? $failure : base64_encode(random_bytes(128));
        $logger = new ConnectionCapturingLogger();

        $result = (new ConnectionTester(
            new ConnectionRecordingFactory($factoryOutcome),
            new ConnectionRecordingPuzzleClient($puzzleOutcome),
            $logger,
        ))->test($this->configuration(bin2hex(random_bytes(16)), 'production-sitekey'));

        self::assertFalse($result->successful);
        self::assertSame('connection-error', $result->reason);
        self::assertSame(\RuntimeException::class, $result->exceptionClass);
        self::assertSame($expectedAttempts, $result->attemptCount);
        self::assertCount(1, $logger->records);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function failureStageProvider(): iterable
    {
        yield 'client construction' => ['factory', 0];
        yield 'puzzle request' => ['puzzle', 0];
        yield 'verification request' => ['verification', 1];
    }

    #[Test]
    public function diagnosticsDoNotRetainCredentialsPuzzlePayloadOrExceptionMessage(): void
    {
        $apiKey = bin2hex(random_bytes(16));
        $productionSitekey = bin2hex(random_bytes(16));
        $puzzle = base64_encode(random_bytes(128));
        $password = bin2hex(random_bytes(16));
        $username = bin2hex(random_bytes(16));
        $formContent = bin2hex(random_bytes(24));
        $failure = new \RuntimeException(implode(':', [
            $apiKey,
            $productionSitekey,
            $puzzle,
            $password,
            $username,
            $formContent,
        ]));
        $logger = new ConnectionCapturingLogger();

        $result = (new ConnectionTester(
            new ConnectionRecordingFactory(new ConnectionRecordingClient($failure)),
            new ConnectionRecordingPuzzleClient($puzzle),
            $logger,
        ))->test($this->configuration($apiKey, $productionSitekey));

        $diagnosticOutput = implode("\n", [
            json_encode($result, JSON_THROW_ON_ERROR),
            serialize($result),
            print_r([$result, $logger->records], true),
            var_export([$result, $logger->records], true),
        ]);
        foreach ([$apiKey, $productionSitekey, $puzzle, $password, $username, $formContent] as $secret) {
            self::assertStringNotContainsString($secret, $diagnosticOutput);
        }
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

final class ConnectionRecordingFactory implements PrivateCaptchaClientFactoryInterface
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

final class ConnectionRecordingPuzzleClient implements TestPuzzleClientInterface
{
    public ?EndpointConfiguration $endpoints = null;

    public ?string $sitekey = null;

    public function __construct(
        private readonly string|\Throwable $outcome,
    ) {}

    public function fetch(
        EndpointConfiguration $endpoints,
        string $sitekey,
    ): string {
        $this->endpoints = $endpoints;
        $this->sitekey = $sitekey;

        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

final class ConnectionRecordingClient extends Client
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

final class ConnectionCapturingLogger extends AbstractLogger
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

<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\Client;
use PrivateCaptcha\Enums\VerifyCode;
use PrivateCaptcha\Models\VerifyOutput;
use PrivateCaptcha\Typo3\Service\CaptchaVerifier;
use PrivateCaptcha\Typo3\Service\ConnectionTester;
use PrivateCaptcha\Typo3\Service\PrivateCaptchaClientFactoryInterface;
use PrivateCaptcha\Typo3\Service\TestPuzzleClientInterface;
use PrivateCaptcha\Typo3\ValueObject\EndpointConfiguration;
use PrivateCaptcha\Typo3\ValueObject\IntegrationConfiguration;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\WidgetConfiguration;
use Psr\Log\NullLogger;

final class TestPropertyIsolationTest extends TestCase
{
    #[Test]
    public function acceptsTestPropertyOnlyForConnectionTesting(): void
    {
        $output = new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR);
        $configuration = $this->configuration();

        $connectionResult = (new ConnectionTester(
            new IsolationClientFactory(new IsolationClient($output)),
            new IsolationPuzzleClient(),
            new NullLogger(),
        ))->test($configuration);
        $productionResult = (new CaptchaVerifier(
            new IsolationClientFactory(new IsolationClient($output)),
            new NullLogger(),
        ))->verify(bin2hex(random_bytes(32)), $configuration);

        self::assertTrue($connectionResult->successful);
        self::assertSame(VerifyCode::TEST_PROPERTY_ERROR->value, $connectionResult->providerCode);
        self::assertFalse($output->isOK());
        self::assertFalse($productionResult->accepted);
        self::assertSame('provider-rejected', $productionResult->reason);
        self::assertSame(VerifyCode::TEST_PROPERTY_ERROR->value, $productionResult->providerCode);
    }

    private function configuration(): ResolvedCaptchaConfiguration
    {
        return new ResolvedCaptchaConfiguration(
            apiKey: bin2hex(random_bytes(16)),
            sitekey: 'production-sitekey',
            widget: new WidgetConfiguration('light', 'auto', 'auto', false, ''),
            integrations: new IntegrationConfiguration(true, false, false, false),
            requestedIntegrations: new IntegrationConfiguration(true, false, false, false),
            endpoints: new EndpointConfiguration(
                apiDomainOverride: null,
                puzzleEndpointOverride: null,
                cdnBaseUrl: 'https://cdn.privatecaptcha.com',
                euIsolation: false,
            ),
        );
    }
}

final readonly class IsolationClientFactory implements PrivateCaptchaClientFactoryInterface
{
    public function __construct(
        private Client $client,
    ) {}

    public function create(ResolvedCaptchaConfiguration $configuration): Client
    {
        return $this->client;
    }
}

final readonly class IsolationPuzzleClient implements TestPuzzleClientInterface
{
    public function fetch(
        EndpointConfiguration $endpoints,
        string $sitekey,
    ): string {
        return base64_encode(random_bytes(128));
    }
}

final class IsolationClient extends Client
{
    public function __construct(
        private readonly VerifyOutput $output,
    ) {}

    public function verify(
        string $solution,
        int $maxBackoffSeconds = 20,
        int $attempts = 5,
        ?string $sitekey = null,
    ): VerifyOutput {
        return $this->output;
    }
}

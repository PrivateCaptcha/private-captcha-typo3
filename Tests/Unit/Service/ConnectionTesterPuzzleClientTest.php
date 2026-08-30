<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Unit\Service;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\Service\TestPuzzleClient;
use PrivateCaptcha\Typo3\ValueObject\EndpointConfiguration;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;

final class ConnectionTesterPuzzleClientTest extends TestCase
{
    private const TEST_SITEKEY = 'aaaaaaaabbbbccccddddeeeeeeeeeeee';

    private mixed $originalHttpConfiguration;

    private bool $hadHttpConfiguration;

    protected function setUp(): void
    {
        parent::setUp();

        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $this->hadHttpConfiguration = is_array($typo3Configuration) && array_key_exists('HTTP', $typo3Configuration);
        $this->originalHttpConfiguration = is_array($typo3Configuration) ? ($typo3Configuration['HTTP'] ?? null) : null;
    }

    protected function tearDown(): void
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3Configuration)) {
            $typo3Configuration = [];
        }
        if ($this->hadHttpConfiguration) {
            $typo3Configuration['HTTP'] = $this->originalHttpConfiguration;
        } else {
            unset($typo3Configuration['HTTP']);
        }
        $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;

        parent::tearDown();
    }

    #[Test]
    #[DataProvider('endpointProvider')]
    public function fetchesBoundedPuzzleFromResolvedEndpoint(
        EndpointConfiguration $endpoints,
        string $expectedUrl,
    ): void {
        $puzzle = ' ' . base64_encode(random_bytes(96)) . "\n";
        $history = [];
        $client = $this->puzzleClient([new Response(200, [], $puzzle)], $history);

        $result = $client->fetch($endpoints, self::TEST_SITEKEY);

        self::assertSame($puzzle, $result);
        self::assertCount(1, $history);
        $transaction = $history[0];
        self::assertSame('GET', $transaction['request']->getMethod());
        self::assertSame($expectedUrl . '?sitekey=' . self::TEST_SITEKEY, (string)$transaction['request']->getUri());
        self::assertSame('not.empty', $transaction['request']->getHeaderLine('Origin'));
        self::assertSame('text/plain', $transaction['request']->getHeaderLine('Accept'));
        self::assertSame('identity', $transaction['request']->getHeaderLine('Accept-Encoding'));
        self::assertSame(5, $transaction['options']['timeout']);
        self::assertSame(5, $transaction['options']['connect_timeout']);
        self::assertFalse($transaction['options']['allow_redirects']);
        self::assertFalse($transaction['options']['http_errors']);
        self::assertFalse($transaction['options']['stream']);
        self::assertInstanceOf(StreamInterface::class, $transaction['options']['sink']);
        self::assertIsCallable($transaction['options']['on_headers']);
        self::assertFalse($transaction['options']['decode_content']);
        self::assertFalse($transaction['options']['cookies']);
    }

    /**
     * @return iterable<string, array{EndpointConfiguration, string}>
     */
    public static function endpointProvider(): iterable
    {
        yield 'global' => [
            self::endpoints(null, null),
            'https://' . Client::GLOBAL_DOMAIN . '/puzzle',
        ];
        yield 'EU' => [
            self::endpoints(Client::EU_DOMAIN, null),
            'https://' . Client::EU_DOMAIN . '/puzzle',
        ];
        yield 'custom' => [
            self::endpoints('api.custom.privatecaptcha.com', 'https://api.custom.privatecaptcha.com/puzzle'),
            'https://api.custom.privatecaptcha.com/puzzle',
        ];
    }

    #[Test]
    #[DataProvider('rejectedResponseProvider')]
    public function rejectsInvalidPuzzleResponses(Response $response): void
    {
        $history = [];
        $client = $this->puzzleClient([$response], $history);

        $this->expectException(\Throwable::class);

        $client->fetch(self::endpoints(null, null), self::TEST_SITEKEY);
    }

    /**
     * @return iterable<string, array{Response}>
     */
    public static function rejectedResponseProvider(): iterable
    {
        foreach ([204, 301, 302, 307, 308, 400, 401, 403, 404, 429, 500, 502, 503, 504] as $status) {
            yield 'HTTP ' . $status => [new Response($status, [], 'puzzle')];
        }
        yield 'empty body' => [new Response(200, [], '')];
        yield 'oversized declared body' => [new Response(200, ['Content-Length' => '16385'], 'puzzle')];
        yield 'malformed declared length' => [new Response(200, ['Content-Length' => 'not-a-number'], 'puzzle')];
        yield 'oversized streamed body' => [new Response(200, [], str_repeat('a', 16385))];
    }

    /**
     * @param list<Response|\Throwable> $queue
     * @param array<int, array{request: RequestInterface, options: array<string, mixed>}> $history
     */
    private function puzzleClient(array $queue, array &$history): TestPuzzleClient
    {
        $handlerStack = HandlerStack::create(new MockHandler($queue));
        $handlerStack->push(Middleware::tap(
            static function (RequestInterface $request, array $options) use (&$history): void {
                $history[] = [
                    'request' => $request,
                    'options' => $options,
                ];
            },
        ));
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3Configuration)) {
            $typo3Configuration = [];
        }
        $typo3Configuration['HTTP'] = [
            'handler' => $handlerStack,
            'verify' => true,
        ];
        $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;

        return new TestPuzzleClient(new RequestFactory(new GuzzleClientFactory()));
    }

    private static function endpoints(?string $apiDomain, ?string $puzzleEndpoint): EndpointConfiguration
    {
        return new EndpointConfiguration(
            apiDomainOverride: $apiDomain,
            puzzleEndpointOverride: $puzzleEndpoint,
            cdnBaseUrl: 'https://cdn.privatecaptcha.com',
            euIsolation: $apiDomain === Client::EU_DOMAIN,
        );
    }
}

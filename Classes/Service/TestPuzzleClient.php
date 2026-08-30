<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Service;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\ValueObject\EndpointConfiguration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * @internal
 */
final readonly class TestPuzzleClient implements TestPuzzleClientInterface
{
    private const TIMEOUT_SECONDS = 5;

    private const MAX_PUZZLE_BYTES = 16384;

    public function __construct(
        private RequestFactory $requestFactory,
    ) {}

    public function fetch(
        EndpointConfiguration $endpoints,
        string $sitekey,
    ): string {
        $sink = $this->boundedSink();

        try {
            $this->requestFactory->request(
                $this->puzzleEndpoint($endpoints),
                'GET',
                [
                    RequestOptions::TIMEOUT => self::TIMEOUT_SECONDS,
                    RequestOptions::CONNECT_TIMEOUT => self::TIMEOUT_SECONDS,
                    RequestOptions::ALLOW_REDIRECTS => false,
                    RequestOptions::HTTP_ERRORS => false,
                    RequestOptions::STREAM => false,
                    RequestOptions::SINK => $sink,
                    RequestOptions::ON_HEADERS => $this->assertPuzzleResponseHeaders(...),
                    RequestOptions::DECODE_CONTENT => false,
                    RequestOptions::COOKIES => false,
                    RequestOptions::HEADERS => [
                        'Origin' => 'not.empty',
                        'Accept' => 'text/plain',
                        'Accept-Encoding' => 'identity',
                    ],
                    RequestOptions::QUERY => [
                        'sitekey' => $sitekey,
                    ],
                ],
            );
            $sink->rewind();
            $puzzle = $sink->getContents();

            if ($puzzle === '') {
                throw new \UnexpectedValueException('Test puzzle response body is invalid.');
            }

            return $puzzle;
        } finally {
            $sink->close();
        }
    }

    private function boundedSink(): StreamInterface
    {
        $buffer = Utils::streamFor('');

        return FnStream::decorate($buffer, [
            'write' => static function (string $data) use ($buffer): int {
                $size = $buffer->getSize();
                if ($size === null || $size + strlen($data) > self::MAX_PUZZLE_BYTES) {
                    throw new \UnexpectedValueException('Test puzzle response is too large.');
                }

                return $buffer->write($data);
            },
        ]);
    }

    private function assertPuzzleResponseHeaders(ResponseInterface $response): void
    {
        if ($response->getStatusCode() !== 200) {
            throw new \UnexpectedValueException('Test puzzle endpoint returned an unexpected status.');
        }
        $contentLength = $response->getHeaderLine('Content-Length');
        if ($contentLength !== ''
            && (!ctype_digit($contentLength) || (int)$contentLength > self::MAX_PUZZLE_BYTES)
        ) {
            throw new \UnexpectedValueException('Test puzzle response length is invalid.');
        }
    }

    private function puzzleEndpoint(EndpointConfiguration $endpoints): string
    {
        if ($endpoints->puzzleEndpointOverride !== null) {
            return $endpoints->puzzleEndpointOverride;
        }

        return sprintf(
            'https://%s/puzzle',
            $endpoints->apiDomainOverride ?? Client::GLOBAL_DOMAIN,
        );
    }
}

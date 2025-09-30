<?php

declare(strict_types=1);

namespace Orryv\XStream\Tests;

use Orryv\XStream\Exception\StreamClosedException;
use Orryv\XStream\Exception\StreamReadException;
use Orryv\XStream\HttpStream;
use Orryv\XStream\XStream;
use PHPUnit\Framework\TestCase;

final class HttpStreamTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!in_array('mockhttp', stream_get_wrappers(), true)) {
            stream_wrapper_register('mockhttp', MockHttpStreamWrapper::class);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (in_array('mockhttp', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('mockhttp');
        }
    }

    protected function setUp(): void
    {
        MockHttpStreamWrapper::reset();
    }

    public function testHttpStreamReadsBody(): void
    {
        $url = 'mockhttp://example.com/success';
        MockHttpStreamWrapper::queueResponse($url, [
            'status' => 200,
            'body' => 'hello world',
            'headers' => [
                'Content-Length: 11',
                'Content-Type: text/plain',
            ],
        ]);

        $stream = new HttpStream($url, [
            'headers' => ['User-Agent' => 'XStream/1.0'],
            'timeout' => 2,
            'read_timeout' => ['seconds' => 1, 'microseconds' => 500000],
        ]);

        $this->assertSame('hello', $stream->read(5));
        $this->assertSame(' world', $stream->read(6));
        $this->assertTrue($stream->eof());
        $this->assertSame(11, $stream->tell());
        $this->assertSame(11, $stream->getSize());

        $metadata = $stream->getMetadata();
        $this->assertSame($url, $metadata['uri']);
        $stream->close();

        $requests = MockHttpStreamWrapper::requestsFor($url);
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertContains('User-Agent: XStream/1.0', $requests[0]['headers']);
    }

    public function testHttpStreamThrowsOnErrorResponses(): void
    {
        $url = 'mockhttp://example.com/error';
        MockHttpStreamWrapper::queueResponse($url, [
            'status' => 503,
            'body' => 'Service unavailable',
        ]);

        $this->expectException(StreamReadException::class);
        $this->expectExceptionMessage('503');
        new HttpStream($url);
    }

    public function testReopenUsesRangeHeaderWhenSupported(): void
    {
        $url = 'mockhttp://example.com/range';
        MockHttpStreamWrapper::queueResponse($url, [
            'status' => 200,
            'body' => 'abcdef',
            'headers' => [
                'Content-Length: 6',
                'Accept-Ranges: bytes',
            ],
        ]);
        MockHttpStreamWrapper::queueResponse($url, [
            'status' => 206,
            'body' => 'def',
            'headers' => [
                'Content-Length: 3',
                'Content-Range: bytes 3-5/6',
            ],
        ]);

        $stream = new HttpStream($url);
        $this->assertSame('abc', $stream->read(3));
        $stream->reopen();
        $this->assertSame('def', $stream->read(3));

        $requests = MockHttpStreamWrapper::requestsFor($url);
        $this->assertCount(2, $requests);
        $this->assertContains('Range: bytes=3-', $requests[1]['headers']);
    }

    public function testReopenSkipsBytesWhenRangeNotHonored(): void
    {
        $url = 'mockhttp://example.com/fallback';
        MockHttpStreamWrapper::queueResponse($url, [
            'status' => 200,
            'body' => 'abcdef',
            'headers' => [
                'Content-Length: 6',
            ],
        ]);
        MockHttpStreamWrapper::queueResponse($url, [
            'status' => 200,
            'body' => 'abcdef',
            'headers' => [
                'Content-Length: 6',
            ],
        ]);

        $stream = new HttpStream($url);
        $this->assertSame('abc', $stream->read(3));
        $stream->reopen();
        $this->assertSame('def', $stream->read(3));

        $requests = MockHttpStreamWrapper::requestsFor($url);
        $this->assertCount(2, $requests);
        $this->assertContains('Range: bytes=3-', $requests[1]['headers']);
    }

    public function testReopenDoesNotSendRangeWhenResumeDisallowed(): void
    {
        $url = 'mockhttp://example.com/no-resume';
        MockHttpStreamWrapper::queueResponse($url, [
            'status' => 200,
            'body' => 'abcdef',
            'headers' => [
                'Content-Length: 6',
                'Accept-Ranges: bytes',
            ],
        ]);
        MockHttpStreamWrapper::queueResponse($url, [
            'status' => 200,
            'body' => 'abcdef',
            'headers' => [
                'Content-Length: 6',
            ],
        ]);

        $stream = new HttpStream($url, ['allow_resume' => false]);
        $this->assertSame('abc', $stream->read(3));
        $stream->reopen();
        $this->assertSame('abc', $stream->read(3));
        $this->assertSame('def', $stream->read(3));

        $requests = MockHttpStreamWrapper::requestsFor($url);
        $this->assertCount(2, $requests);
        $this->assertArrayNotHasKey('range', $requests[1]['headerMap']);
        $this->assertNotContains('Range: bytes=3-', $requests[1]['headers']);
    }

    public function testCloseAndDetachPreventFurtherAccess(): void
    {
        $url = 'mockhttp://example.com/lifecycle';
        MockHttpStreamWrapper::queueResponse($url, [
            'status' => 200,
            'body' => 'payload',
            'headers' => ['Content-Length: 7'],
        ]);

        $stream = new HttpStream($url);
        $stream->close();

        try {
            $stream->read(1);
            $this->fail('Expected read() on a closed stream to throw.');
        } catch (StreamClosedException $exception) {
            $this->assertSame('HTTP stream is closed', $exception->getMessage());
        }

        try {
            $stream->reopen();
            $this->fail('Expected reopen() on a closed stream to throw.');
        } catch (StreamClosedException $exception) {
            $this->assertSame('Cannot reopen a closed HTTP stream', $exception->getMessage());
        }

        MockHttpStreamWrapper::queueResponse($url, [
            'status' => 200,
            'body' => 'payload',
            'headers' => ['Content-Length: 7'],
        ]);

        $detachedStream = new HttpStream($url);
        $detachedStream->detach();

        try {
            $detachedStream->read(1);
            $this->fail('Expected read() on a detached stream to throw.');
        } catch (StreamClosedException $exception) {
            $this->assertSame('HTTP stream has been detached', $exception->getMessage());
        }

        try {
            $detachedStream->reopen();
            $this->fail('Expected reopen() on a detached stream to throw.');
        } catch (StreamClosedException $exception) {
            $this->assertSame('Cannot reopen a detached HTTP stream', $exception->getMessage());
        }
    }

    public function testFactoryAppliesRetryAndBuffering(): void
    {
        $url = 'mockhttp://example.com/factory';
        MockHttpStreamWrapper::queueResponse($url, [
            'status' => 200,
            'body' => 'payload',
            'headers' => ['Content-Length: 7'],
        ]);

        $stream = XStream::http($url, [
            'buffered' => true,
            'retry' => true,
        ]);

        $this->assertSame('payload', $stream->read(7));
        $stream->close();
    }
}

final class MockHttpStreamWrapper
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $responses = [];
    /**
     * @var array<string, array<int, array{
     *     method:string,
     *     headers:string[],
     *     headerMap:array<string, array<int, string>>
     * }>>
     */
    private static array $requests = [];

    /** @var resource|null */
    public $context;

    private string $path = '';
    private string $body = '';
    private int $position = 0;
    /** @var string[] */
    private array $responseHeaders = [];

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        if ($option === STREAM_OPTION_READ_TIMEOUT) {
            return true;
        }

        return false;
    }

    public static function reset(): void
    {
        self::$responses = [];
        self::$requests = [];
    }

    /**
     * @param array{status?:int,body?:string,headers?:array<int,string>|string} $response
     */
    public static function queueResponse(string $url, array $response): void
    {
        self::$responses[$url][] = $response;
    }

    /**
     * @return array<int, array{method:string,headers:string[],headerMap:array<string, array<int, string>>}>
     */
    public static function requestsFor(string $url): array
    {
        return self::$requests[$url] ?? [];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->path = $path;
        $opened_path = $path;
        $request = $this->extractRequest();
        self::$requests[$path][] = $request;

        $response = array_shift(self::$responses[$path]) ?? [];
        $status = $response['status'] ?? 200;
        $body = (string)($response['body'] ?? '');
        $headers = $response['headers'] ?? [];
        if (!is_array($headers)) {
            $headers = [$headers];
        }

        $statusLine = sprintf('HTTP/1.1 %d %s', $status, self::statusText($status));
        if (!in_array($statusLine, $headers, true)) {
            array_unshift($headers, $statusLine);
        }
        if (!self::hasHeader($headers, 'Content-Length')) {
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        $this->body = $body;
        $this->position = 0;
        $this->responseHeaders = array_values($headers);

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->body, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->body);
    }

    public function stream_get_meta_data(): array
    {
        return [
            'wrapper_data' => $this->responseHeaders,
            'uri' => $this->path,
        ];
    }

    /**
     * @return string[]
     */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function stream_stat(): array
    {
        return [];
    }

    private function extractRequest(): array
    {
        $options = [];
        if (is_resource($this->context)) {
            $options = stream_context_get_options($this->context);
        }
        $http = $options['http'] ?? [];
        $method = strtoupper((string)($http['method'] ?? 'GET'));
        $headersRaw = $http['header'] ?? [];
        $headers = [];
        if (is_string($headersRaw)) {
            $headers = preg_split('/\r\n|\r|\n/', $headersRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($headersRaw)) {
            foreach ($headersRaw as $line) {
                $headers[] = (string)$line;
            }
        }

        $headerMap = [];
        foreach ($headers as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) < 2) {
                continue;
            }
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if ($name === '') {
                continue;
            }
            $headerMap[$name][] = $value;
        }

        return [
            'method' => $method,
            'headers' => $headers,
            'headerMap' => $headerMap,
        ];
    }

    private static function statusText(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            206 => 'Partial Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            409 => 'Conflict',
            412 => 'Precondition Failed',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Status',
        };
    }

    private static function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return true;
            }
        }
        return false;
    }
}

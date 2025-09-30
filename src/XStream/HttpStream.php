<?php

namespace Orryv\XStream;

use Orryv\XStream\Exception\StreamClosedException;
use Orryv\XStream\Exception\StreamReadException;
use Orryv\XStream\Interfaces\ReadableStreamInterface;
use Orryv\XStream\Interfaces\ReopenableStreamInterface;

final class HttpStream implements ReadableStreamInterface, ReopenableStreamInterface
{
    private string $url;
    private string $method;
    /** @var string[] */
    private array $headers;
    private ?string $body;
    private ?float $timeout;
    /** @var array{sec:int,usec:int}|null */
    private ?array $readTimeout;
    /** @var array<string,mixed> */
    private array $contextOptions;
    /** @var array<string,mixed> */
    private array $contextParams;
    private bool $allowResume;
    /** @var resource|null */
    private $handle = null;
    private bool $closed = false;
    private bool $detached = false;
    private int $position = 0;
    private ?int $size = null;
    private int $pendingSkip = 0;
    private ?int $lastStatusCode = null;

    /**
     * @param array{
     *     method?:string,
     *     headers?:array<int,string>|array<string,string|array<int,string>>|string,
     *     body?:string,
     *     timeout?:float|int,
     *     read_timeout?:array{seconds?:int,microseconds?:int}|array<int,int>|float|int,
     *     context_options?:array<string,mixed>,
     *     context_params?:array<string,mixed>,
     *     allow_resume?:bool
     * } $options
     */
    public function __construct(string $url, array $options = [])
    {
        $this->url = $url;

        $contextOptions = $options['context_options'] ?? [];
        $contextHttp = $contextOptions['http'] ?? [];

        $contextHeaders = [];
        if (isset($contextHttp['header'])) {
            $contextHeaders = $this->normalizeHeaders($contextHttp['header']);
            unset($contextHttp['header']);
        }

        $contextMethod = isset($contextHttp['method']) ? strtoupper((string)$contextHttp['method']) : 'GET';
        unset($contextHttp['method']);

        $contextContent = null;
        if (array_key_exists('content', $contextHttp)) {
            $contextContent = (string)$contextHttp['content'];
            unset($contextHttp['content']);
        }

        $contextTimeout = null;
        if (array_key_exists('timeout', $contextHttp)) {
            $contextTimeout = (float)$contextHttp['timeout'];
            unset($contextHttp['timeout']);
        }

        $this->contextOptions = $contextOptions;
        $this->contextOptions['http'] = $contextHttp;
        $this->contextParams = $options['context_params'] ?? [];

        $this->method = strtoupper((string)($options['method'] ?? $contextMethod));
        $this->headers = array_values(array_merge(
            $contextHeaders,
            $this->normalizeHeaders($options['headers'] ?? [])
        ));

        if (array_key_exists('body', $options)) {
            $this->body = (string)$options['body'];
        } elseif ($contextContent !== null) {
            $this->body = $contextContent;
        } else {
            $this->body = null;
        }

        if (array_key_exists('timeout', $options)) {
            $this->timeout = (float)$options['timeout'];
        } elseif ($contextTimeout !== null) {
            $this->timeout = $contextTimeout;
        } else {
            $this->timeout = null;
        }

        $this->readTimeout = $this->normalizeReadTimeout($options['read_timeout'] ?? null);
        $this->allowResume = ($options['allow_resume'] ?? true) && $this->method === 'GET' && $this->body === null;

        $this->openInternal(null);
        $this->position = 0;
    }

    public function __destruct()
    {
        if (!$this->detached && !$this->closed) {
            try {
                $this->close();
            } catch (\Throwable) {
                // Destructors must not throw.
            }
        }
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new StreamReadException('Length must be >= 0');
        }
        if ($length === 0) {
            return '';
        }
        $this->ensureActive();
        $handle = $this->handle;
        $this->discardPending($handle);

        $result = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = fread($handle, $remaining);
            if ($chunk === false) {
                throw new StreamReadException('Failed to read from HTTP stream');
            }
            $chunkLength = strlen($chunk);
            if ($chunkLength === 0) {
                break;
            }
            $result .= $chunk;
            $remaining -= $chunkLength;
            if (feof($handle)) {
                break;
            }
        }

        $this->position += strlen($result);
        return $result;
    }

    public function eof(): bool
    {
        $this->ensureActive();
        if ($this->pendingSkip > 0) {
            $this->discardPending($this->handle);
        }
        return feof($this->handle);
    }

    public function getMetadata(?string $key = null): mixed
    {
        $this->ensureActive();
        $meta = stream_get_meta_data($this->handle);
        if ($key === null) {
            return $meta;
        }
        return $meta[$key] ?? null;
    }

    public function tell(): int
    {
        $this->ensureActive();
        return $this->position;
    }

    public function getSize(): ?int
    {
        $this->ensureActive();
        return $this->size;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->closeHandle();
    }

    public function detach(): mixed
    {
        $handle = $this->handle;
        $this->handle = null;
        $this->closed = true;
        $this->detached = true;
        return $handle;
    }

    public function reopen(): void
    {
        if ($this->detached) {
            throw new StreamClosedException('Cannot reopen a detached HTTP stream');
        }
        if ($this->closed) {
            throw new StreamClosedException('Cannot reopen a closed HTTP stream');
        }
        $resumeFrom = $this->position;
        $this->closeHandle();
        $this->openInternal($resumeFrom);
    }

    private function openInternal(?int $resumeFrom): void
    {
        $rangeStart = $resumeFrom !== null && $resumeFrom > 0 && $this->allowResume ? $resumeFrom : null;
        $context = stream_context_create(
            $this->buildContextOptions($rangeStart),
            $this->contextParams
        );

        $handle = $this->createHandle($context);
        $this->handle = $handle;
        $this->closed = false;
        $this->detached = false;
        $this->pendingSkip = 0;

        $meta = stream_get_meta_data($handle);
        $this->lastStatusCode = $this->extractStatusCode($meta);

        if ($this->lastStatusCode !== null && ($this->lastStatusCode < 200 || $this->lastStatusCode >= 300)) {
            $body = stream_get_contents($handle);
            $this->closeHandle();
            $this->closed = true;
            $message = sprintf('HTTP request failed with status %d', $this->lastStatusCode);
            if ($body !== false && $body !== '') {
                $message .= sprintf(' (%s)', $this->summarizeBody($body));
            }
            throw new StreamReadException($message);
        }

        $this->size = $this->determineSize($meta, $rangeStart);
        if ($rangeStart !== null && $this->lastStatusCode !== 206) {
            $this->pendingSkip = $rangeStart;
        }

        if ($this->readTimeout !== null) {
            stream_set_timeout($handle, $this->readTimeout['sec'], $this->readTimeout['usec']);
        }
    }

    private function buildContextOptions(?int $rangeStart): array
    {
        $options = $this->contextOptions;
        $http = $options['http'] ?? [];

        $http['method'] = $this->method;
        if ($this->body !== null) {
            $http['content'] = $this->body;
        }
        if ($this->timeout !== null) {
            $http['timeout'] = $this->timeout;
        }
        if (!array_key_exists('ignore_errors', $http)) {
            $http['ignore_errors'] = true;
        }

        $headers = $this->headers;
        if ($rangeStart !== null && !$this->hasHeader($headers, 'Range')) {
            $headers[] = sprintf('Range: bytes=%d-', $rangeStart);
        }
        if (!empty($headers)) {
            $http['header'] = $this->formatHeaders($headers);
        }

        $options['http'] = $http;
        return $options;
    }

    private function createHandle($context)
    {
        $handler = function (int $severity, string $message): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new StreamReadException($message);
        };

        set_error_handler($handler);
        try {
            $handle = fopen($this->url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($handle)) {
            throw new StreamReadException(sprintf('Failed to open HTTP stream for "%s"', $this->url));
        }

        return $handle;
    }

    private function ensureActive(): void
    {
        if ($this->detached) {
            throw new StreamClosedException('HTTP stream has been detached');
        }
        if ($this->closed || !is_resource($this->handle)) {
            throw new StreamClosedException('HTTP stream is closed');
        }
    }

    private function closeHandle(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    private function discardPending($handle): void
    {
        while ($this->pendingSkip > 0) {
            $chunk = fread($handle, min($this->pendingSkip, 65536));
            if ($chunk === false) {
                throw new StreamReadException('Failed to discard bytes while resuming HTTP stream');
            }
            $length = strlen($chunk);
            if ($length === 0) {
                throw new StreamReadException('Unable to make progress while discarding resume bytes');
            }
            $this->pendingSkip -= $length;
        }
    }

    /**
     * @param mixed $headers
     * @return string[]
     */
    private function normalizeHeaders($headers): array
    {
        if ($headers === null) {
            return [];
        }
        if (is_string($headers)) {
            $headers = preg_split('/\r\n|\r|\n/', $headers, -1, PREG_SPLIT_NO_EMPTY);
        }
        $result = [];
        if (!is_array($headers)) {
            return $result;
        }
        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                if (is_array($value)) {
                    foreach ($value as $line) {
                        $line = trim((string)$line);
                        if ($line !== '') {
                            $result[] = $line;
                        }
                    }
                    continue;
                }
                $line = trim((string)$value);
                if ($line !== '') {
                    $result[] = $line;
                }
            } else {
                if (is_array($value)) {
                    foreach ($value as $item) {
                        $result[] = sprintf('%s: %s', $key, $item);
                    }
                    continue;
                }
                $result[] = sprintf('%s: %s', $key, $value);
            }
        }
        return $result;
    }

    private function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $line) {
            if (stripos($line, $name . ':') === 0) {
                return true;
            }
        }
        return false;
    }

    private function formatHeaders(array $headers): string
    {
        return implode("\r\n", $headers);
    }

    /**
     * @param array{seconds?:int,microseconds?:int}|array<int,int>|float|int|null $value
     * @return array{sec:int,usec:int}|null
     */
    private function normalizeReadTimeout($value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            $seconds = $value['seconds'] ?? $value[0] ?? 0;
            $microseconds = $value['microseconds'] ?? $value[1] ?? 0;
            return [
                'sec' => max(0, (int)$seconds),
                'usec' => max(0, (int)$microseconds),
            ];
        }
        $seconds = (int)$value;
        $microseconds = (int)round(($value - $seconds) * 1_000_000);
        return [
            'sec' => max(0, $seconds),
            'usec' => max(0, $microseconds),
        ];
    }

    private function extractStatusCode(array $meta): ?int
    {
        foreach ($this->extractResponseHeaders($meta) as $line) {
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d{3})/', $line, $matches)) {
                return (int)$matches[1];
            }
        }
        return null;
    }

    private function determineSize(array $meta, ?int $rangeStart): ?int
    {
        $size = null;
        foreach ($this->extractResponseHeaders($meta) as $line) {
            if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $matches)) {
                $size = (int)$matches[1];
            } elseif (preg_match('/^Content-Range:\s*bytes\s*(\d+)-(\d+)\/(\d+|\*)/i', $line, $matches)) {
                if ($matches[3] !== '*') {
                    $size = (int)$matches[3];
                } elseif ($rangeStart !== null) {
                    $size = ((int)$matches[2]) + 1;
                }
            }
        }
        return $size;
    }

    private function extractResponseHeaders(array $meta): array
    {
        $wrapperData = $meta['wrapper_data'] ?? [];
        if (is_array($wrapperData)) {
            $flattened = [];
            foreach ($wrapperData as $item) {
                if (is_array($item)) {
                    foreach ($item as $line) {
                        $flattened[] = (string)$line;
                    }
                } else {
                    $flattened[] = (string)$item;
                }
            }
            return $flattened;
        }
        if (is_object($wrapperData)) {
            if (method_exists($wrapperData, 'getResponseHeaders')) {
                $headers = $wrapperData->getResponseHeaders();
                if (is_array($headers)) {
                    return array_map(static fn ($value): string => (string)$value, $headers);
                }
            }
            if ($wrapperData instanceof \Traversable) {
                $headers = [];
                foreach ($wrapperData as $value) {
                    $headers[] = (string)$value;
                }
                return $headers;
            }
        }
        if (is_string($wrapperData)) {
            return [$wrapperData];
        }
        return [];
    }

    private function summarizeBody(string $body): string
    {
        $body = preg_replace('/\s+/', ' ', $body);
        $body = trim((string)$body);
        if (strlen($body) > 256) {
            $body = substr($body, 0, 253) . '...';
        }
        return $body;
    }
}

<?php

namespace Orryv\XStream;

use Orryv\XStream\Bridge\FromPsrStream;
use Orryv\XStream\Bridge\PsrStreamAdapter;
use Orryv\XStream\Interfaces\ReadableStreamInterface;
use Psr\Http\Message\StreamInterface as PsrStreamInterface;

final class XStream
{
    public static function file(string $path, string $mode = 'rb', array $options = []): StreamInterface
    {
        $context = $options['context'] ?? null;
        $stream = new FileStream($path, $mode, $context);
        if (isset($options['read_buffer'])) {
            $stream->setReadBuffer((int)$options['read_buffer']);
        }
        if (isset($options['write_buffer'])) {
            $stream->setWriteBuffer((int)$options['write_buffer']);
        }
        if (isset($options['chunk_size'])) {
            $stream->setChunkSize((int)$options['chunk_size']);
        }
        if (array_key_exists('blocking', $options)) {
            $stream->setBlocking($options['blocking'] === null ? null : (bool)$options['blocking']);
        }
        if (isset($options['timeout'])) {
            $timeout = $options['timeout'];
            $seconds = is_array($timeout) ? ($timeout['seconds'] ?? $timeout[0] ?? 0) : (int)$timeout;
            $microseconds = is_array($timeout) ? ($timeout['microseconds'] ?? $timeout[1] ?? 0) : 0;
            $stream->setTimeout($seconds, $microseconds);
        }

        $retry = $options['retry'] ?? true;
        if ($retry) {
            $retries = $options['retries'] ?? 3;
            $delayMs = $options['retry_delay_ms'] ?? 2;
            $restore = $options['restore_position'] ?? true;
            $stream = new RetryStream($stream, (int)$retries, (int)$delayMs, (bool)$restore);
        }

        if (!empty($options['buffered'])) {
            $readSize = $options['buffer_read_size'] ?? 65536;
            $writeSize = $options['buffer_write_size'] ?? 65536;
            $stream = new BufferedStream($stream, (int)$readSize, (int)$writeSize);
        }

        return $stream;
    }

    /**
     * @param resource $resource
     */
    public static function fromResource($resource, array $options = []): StreamInterface
    {
        $stream = new ResourceStream($resource);
        if (isset($options['read_buffer'])) {
            $stream->setReadBuffer((int)$options['read_buffer']);
        }
        if (isset($options['write_buffer'])) {
            $stream->setWriteBuffer((int)$options['write_buffer']);
        }
        if (isset($options['chunk_size'])) {
            $stream->setChunkSize((int)$options['chunk_size']);
        }
        if (array_key_exists('blocking', $options)) {
            $stream->setBlocking($options['blocking'] === null ? null : (bool)$options['blocking']);
        }
        if (isset($options['timeout'])) {
            $timeout = $options['timeout'];
            $seconds = is_array($timeout) ? ($timeout['seconds'] ?? $timeout[0] ?? 0) : (int)$timeout;
            $microseconds = is_array($timeout) ? ($timeout['microseconds'] ?? $timeout[1] ?? 0) : 0;
            $stream->setTimeout($seconds, $microseconds);
        }

        $retry = $options['retry'] ?? true;
        if ($retry) {
            $retries = $options['retries'] ?? 3;
            $delayMs = $options['retry_delay_ms'] ?? 2;
            $restore = $options['restore_position'] ?? true;
            $stream = new RetryStream($stream, (int)$retries, (int)$delayMs, (bool)$restore);
        }

        if (!empty($options['buffered'])) {
            $readSize = $options['buffer_read_size'] ?? 65536;
            $writeSize = $options['buffer_write_size'] ?? 65536;
            $stream = new BufferedStream($stream, (int)$readSize, (int)$writeSize);
        }

        return $stream;
    }

    public static function memory(string $initial = ''): MemoryStream
    {
        return new MemoryStream($initial);
    }

    public static function http(string $url, array $options = []): StreamInterface
    {
        $stream = new HttpStream($url, $options);

        $retry = $options['retry'] ?? true;
        if ($retry) {
            $retries = $options['retries'] ?? 3;
            $delayMs = $options['retry_delay_ms'] ?? 2;
            $restore = $options['restore_position'] ?? true;
            $stream = new RetryStream($stream, (int)$retries, (int)$delayMs, (bool)$restore);
        }

        if (!empty($options['buffered'])) {
            $readSize = $options['buffer_read_size'] ?? 65536;
            $writeSize = $options['buffer_write_size'] ?? 65536;
            $stream = new BufferedStream($stream, (int)$readSize, (int)$writeSize);
        }

        return $stream;
    }

    public static function temp(int $limitBytes = 2_000_000): TempStream
    {
        return new TempStream($limitBytes);
    }

    public static function null(): NullStream
    {
        return new NullStream();
    }

    public static function buffered(
        StreamInterface $inner,
        int $readBufferSize = 65536,
        int $writeBufferSize = 65536,
        bool $closeInner = true
    ): BufferedStream {
        return new BufferedStream($inner, $readBufferSize, $writeBufferSize, $closeInner);
    }

    public static function retry(
        StreamInterface $inner,
        int $retries = 3,
        int $delayMs = 2,
        bool $restorePosition = true
    ): RetryStream {
        return new RetryStream($inner, $retries, $delayMs, $restorePosition);
    }

    /**
     * @param WritableStreamInterface[] $sinks
     */
    public static function teeWriter(array $sinks, int $chunkSize = 262144, string $policy = 'fail_fast', bool $closeSinks = true, ?callable $onEvent = null): TeeWriter
    {
        return new TeeWriter($sinks, $chunkSize, $policy, $closeSinks, $onEvent);
    }

    /**
     * @param WritableStreamInterface[] $sinks
     */
    public static function teeReader(
        ReadableStreamInterface $source,
        array $sinks,
        string $policy = 'best_effort',
        bool $closeSource = true,
        bool $closeSinks = false,
        ?callable $onEvent = null
    ): TeeReader {
        return new TeeReader($source, $sinks, $policy, $closeSource, $closeSinks, $onEvent);
    }

    public static function asPsrStream(StreamInterface $stream): PsrStreamInterface
    {
        return new PsrStreamAdapter($stream);
    }

    public static function fromPsrStream(PsrStreamInterface $stream): FromPsrStream
    {
        return new FromPsrStream($stream);
    }
}

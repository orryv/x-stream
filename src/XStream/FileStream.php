<?php

namespace Orryv\XStream;

use Orryv\XStream\Exception\StreamClosedException;
use Orryv\XStream\Exception\StreamOperationException;
use Orryv\XStream\Exception\StreamReadException;
use Orryv\XStream\Exception\StreamSeekException;
use Orryv\XStream\Exception\StreamWriteException;
use Orryv\XStream\Interfaces\DuplexStreamInterface;
use Orryv\XStream\Interfaces\ModeAwareStreamInterface;
use Orryv\XStream\Interfaces\ReopenableStreamInterface;
use Orryv\XStream\Interfaces\SeekableStreamInterface;

class FileStream implements DuplexStreamInterface, SeekableStreamInterface, ReopenableStreamInterface, ModeAwareStreamInterface
{
    private const READ_BUFFER = 'read_buffer';
    private const WRITE_BUFFER = 'write_buffer';
    private const CHUNK_SIZE = 'chunk_size';
    private const BLOCKING = 'blocking';
    private const TIMEOUT = 'timeout';

    private string $path;
    private string $mode;
    private $context;
    private $handle = null;
    private bool $closed = false;
    private bool $detached = false;
    private int $position = 0;

    private bool $readable;
    private bool $writable;
    private bool $seekable;
    private bool $append;

    /** @var array{read_buffer:?int,write_buffer:?int,chunk_size:?int,blocking:?bool,timeout:?array{sec:int,usec:int}} */
    private array $options = [
        self::READ_BUFFER => null,
        self::WRITE_BUFFER => null,
        self::CHUNK_SIZE => null,
        self::BLOCKING => null,
        self::TIMEOUT => null,
    ];

    public function __construct(string $path, string $mode = 'rb', $context = null)
    {
        $this->path = $path;
        $this->mode = $mode;
        $this->context = $context;
        [$this->readable, $this->writable, $this->seekable, $this->append] = $this->parseMode($mode);
        $this->open();
    }

    public function __destruct()
    {
        if (!$this->closed) {
            try {
                $this->close();
            } catch (\Throwable $e) {
                // Destructors must not throw.
            }
        }
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function isAppendMode(): bool
    {
        return $this->append;
    }

    public function reopen(): void
    {
        if ($this->detached) {
            throw new StreamClosedException('Cannot reopen a detached stream');
        }
        $position = $this->position;
        $this->closeInternal();
        $this->open();
        if ($this->seekable && !$this->append && $position > 0) {
            $this->restorePosition($position);
        }
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new StreamReadException('Length must be >= 0');
        }
        $this->ensureReadable();
        if ($length === 0) {
            return '';
        }

        $handle = $this->handle();
        $result = '';

        $this->withErrorHandler(function () use ($handle, $length, &$result): void {
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, $remaining);
                if ($chunk === false) {
                    throw new StreamReadException('Failed to read from file stream');
                }
                if ($chunk === '') {
                    break;
                }
                $result .= $chunk;
                $remaining -= strlen($chunk);
                if ($remaining <= 0) {
                    break;
                }
            }
        }, true);

        return $result;
    }

    public function write(string $data): int
    {
        $this->ensureWritable();
        if ($data === '') {
            return 0;
        }
        $handle = $this->handle();
        $written = 0;

        $this->withErrorHandler(function () use ($handle, $data, &$written): void {
            $length = strlen($data);
            while ($written < $length) {
                $chunk = substr($data, $written);
                $bytes = fwrite($handle, $chunk);
                if ($bytes === false) {
                    throw new StreamWriteException('Failed to write to file stream');
                }
                if ($bytes === 0) {
                    throw new StreamWriteException('Failed to make progress while writing');
                }
                $written += $bytes;
            }
        }, true);

        return $written;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->ensureSeekable();
        $handle = $this->handle();
        $this->withErrorHandler(function () use ($handle, $offset, $whence): void {
            $result = fseek($handle, $offset, $whence);
            if ($result !== 0) {
                throw new StreamSeekException('Unable to seek to requested position');
            }
        }, false);
        $this->position = $this->tell();
    }

    public function tell(): int
    {
        $handle = $this->handle();
        $this->withErrorHandler(function () use ($handle): void {
            $pos = ftell($handle);
            if ($pos === false) {
                throw new StreamSeekException('Unable to determine stream position');
            }
        }, false);
        $pos = ftell($handle);
        if ($pos === false) {
            throw new StreamSeekException('Unable to determine stream position');
        }
        $this->position = $pos;
        return $pos;
    }

    public function eof(): bool
    {
        $handle = $this->handle();
        return feof($handle);
    }

    public function getSize(): ?int
    {
        $handle = $this->handle();
        $stats = fstat($handle);
        if ($stats === false) {
            return null;
        }
        return $stats['size'] ?? null;
    }

    public function flush(): void
    {
        $this->ensureWritable();
        $handle = $this->handle();
        $this->withErrorHandler(function () use ($handle): void {
            if (!fflush($handle)) {
                throw new StreamWriteException('Unable to flush file stream');
            }
        }, false);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->closeInternal();
    }

    public function detach(): mixed
    {
        $handle = $this->handle;
        $this->handle = null;
        $this->closed = true;
        $this->detached = true;
        return $handle;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $handle = $this->handle();
        $meta = stream_get_meta_data($handle);
        if ($key === null) {
            return $meta;
        }
        return $meta[$key] ?? null;
    }

    public function setReadBuffer(?int $bytes): void
    {
        $this->options[self::READ_BUFFER] = $bytes;
        if ($bytes !== null && is_resource($this->handle)) {
            stream_set_read_buffer($this->handle, $bytes);
        }
    }

    public function setWriteBuffer(?int $bytes): void
    {
        $this->options[self::WRITE_BUFFER] = $bytes;
        if ($bytes !== null && is_resource($this->handle)) {
            stream_set_write_buffer($this->handle, $bytes);
        }
    }

    public function setChunkSize(?int $bytes): void
    {
        $this->options[self::CHUNK_SIZE] = $bytes;
        if ($bytes !== null && is_resource($this->handle)) {
            stream_set_chunk_size($this->handle, $bytes);
        }
    }

    public function setBlocking(?bool $blocking): void
    {
        $this->options[self::BLOCKING] = $blocking;
        if ($blocking !== null && is_resource($this->handle)) {
            stream_set_blocking($this->handle, $blocking ? 1 : 0);
        }
    }

    public function setTimeout(?int $seconds, int $microseconds = 0): void
    {
        if ($seconds === null) {
            $this->options[self::TIMEOUT] = null;
            return;
        }
        $this->options[self::TIMEOUT] = ['sec' => $seconds, 'usec' => $microseconds];
        if (is_resource($this->handle)) {
            stream_set_timeout($this->handle, $seconds, $microseconds);
        }
    }

    private function open(): void
    {
        $this->closed = false;
        $this->handle = $this->createHandle();
        $this->applyOptions();
        $this->position = 0;
    }

    private function createHandle()
    {
        $handler = function (int $severity, string $message): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new StreamOperationException($message, 0, $severity);
        };

        set_error_handler($handler);
        try {
            if ($this->context !== null) {
                $handle = fopen($this->path, $this->mode, false, $this->context);
            } else {
                $handle = fopen($this->path, $this->mode);
            }
        } finally {
            restore_error_handler();
        }

        if (!is_resource($handle)) {
            throw new StreamOperationException(sprintf('Failed to open stream for path "%s"', $this->path));
        }

        return $handle;
    }

    private function applyOptions(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        if ($this->options[self::READ_BUFFER] !== null) {
            stream_set_read_buffer($this->handle, $this->options[self::READ_BUFFER]);
        }
        if ($this->options[self::WRITE_BUFFER] !== null) {
            stream_set_write_buffer($this->handle, $this->options[self::WRITE_BUFFER]);
        }
        if ($this->options[self::CHUNK_SIZE] !== null) {
            stream_set_chunk_size($this->handle, $this->options[self::CHUNK_SIZE]);
        }
        if ($this->options[self::BLOCKING] !== null) {
            stream_set_blocking($this->handle, $this->options[self::BLOCKING] ? 1 : 0);
        }
        if ($this->options[self::TIMEOUT] !== null) {
            $timeout = $this->options[self::TIMEOUT];
            stream_set_timeout($this->handle, $timeout['sec'], $timeout['usec']);
        }
    }

    private function handle()
    {
        $this->ensureNotClosed();
        if (!is_resource($this->handle)) {
            throw new StreamClosedException('Stream handle is not available');
        }
        return $this->handle;
    }

    private function ensureReadable(): void
    {
        if (!$this->readable) {
            throw new StreamReadException(sprintf('Stream opened with mode "%s" is not readable', $this->mode));
        }
    }

    private function ensureWritable(): void
    {
        if (!$this->writable) {
            throw new StreamWriteException(sprintf('Stream opened with mode "%s" is not writable', $this->mode));
        }
    }

    private function ensureSeekable(): void
    {
        if (!$this->seekable) {
            throw new StreamSeekException(sprintf('Stream opened with mode "%s" is not seekable', $this->mode));
        }
    }

    private function ensureNotClosed(): void
    {
        if ($this->closed) {
            throw new StreamClosedException('Stream has been closed');
        }
        if ($this->detached) {
            throw new StreamClosedException('Stream has been detached');
        }
    }

    private function closeInternal(): void
    {
        if (is_resource($this->handle)) {
            $handle = $this->handle;
            $this->handle = null;
            $this->withErrorHandler(function () use ($handle): void {
                fclose($handle);
            }, false);
        }
    }

    private function restorePosition(int $position): void
    {
        $size = $this->getSize();
        if ($size !== null) {
            $position = min($position, $size);
        }
        if ($position <= 0) {
            return;
        }
        try {
            $this->seek($position, SEEK_SET);
        } catch (StreamSeekException $exception) {
            $this->seek(0, SEEK_END);
        }
    }

    private function withErrorHandler(callable $operation, bool $updatePosition): void
    {
        $handler = function (int $severity, string $message): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new StreamOperationException($message, 0, $severity);
        };
        $previous = set_error_handler($handler);
        try {
            $operation();
            if ($updatePosition && is_resource($this->handle)) {
                $pos = ftell($this->handle);
                if ($pos !== false) {
                    $this->position = $pos;
                }
            }
        } finally {
            if ($previous) {
                set_error_handler($previous);
            } else {
                restore_error_handler();
            }
        }
    }

    private function parseMode(string $mode): array
    {
        $readable = str_contains($mode, 'r') || str_contains($mode, '+');
        $writable = strpbrk($mode, 'waxc+') !== false;
        $seekable = true; // php streams default; adjust for 'a' writes as partial
        $append = str_contains($mode, 'a');
        if ($append) {
            $seekable = true; // allow seeking for reads; writes forced to end but we guard elsewhere
        }
        return [$readable, $writable, $seekable, $append];
    }
}

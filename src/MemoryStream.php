<?php

namespace Orryv\XStream;

use Orryv\XStream\Exception\StreamClosedException;
use Orryv\XStream\Exception\StreamReadException;
use Orryv\XStream\Exception\StreamSeekException;

final class MemoryStream implements DuplexStreamInterface, SeekableStreamInterface
{
    private string $buffer;
    private int $position = 0;
    private bool $closed = false;

    public function __construct(string $initial = '')
    {
        $this->buffer = $initial;
    }

    public function read(int $length): string
    {
        $this->ensureNotClosed();
        if ($length < 0) {
            throw new StreamReadException('Length must be >= 0');
        }
        if ($length === 0) {
            return '';
        }
        $chunk = substr($this->buffer, $this->position, $length);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function write(string $data): int
    {
        $this->ensureNotClosed();
        if ($data === '') {
            return 0;
        }
        $before = substr($this->buffer, 0, $this->position);
        $after = substr($this->buffer, $this->position + strlen($data));
        $this->buffer = $before . $data . $after;
        $this->position += strlen($data);
        return strlen($data);
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->ensureNotClosed();
        $size = strlen($this->buffer);
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $size + $offset,
            default => throw new StreamSeekException('Invalid whence provided'),
        };
        if ($target < 0) {
            throw new StreamSeekException('Cannot seek before start of stream');
        }
        $this->position = min($target, $size);
    }

    public function tell(): int
    {
        $this->ensureNotClosed();
        return $this->position;
    }

    public function eof(): bool
    {
        $this->ensureNotClosed();
        return $this->position >= strlen($this->buffer);
    }

    public function getSize(): ?int
    {
        $this->ensureNotClosed();
        return strlen($this->buffer);
    }

    public function flush(): void
    {
        $this->ensureNotClosed();
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach(): mixed
    {
        $this->closed = true;
        return null;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $this->ensureNotClosed();
        $metadata = [
            'stream_type' => 'memory',
            'seekable' => true,
            'size' => strlen($this->buffer),
        ];
        if ($key === null) {
            return $metadata;
        }
        return $metadata[$key] ?? null;
    }

    public function getContents(): string
    {
        $this->ensureNotClosed();
        return $this->buffer;
    }

    private function ensureNotClosed(): void
    {
        if ($this->closed) {
            throw new StreamClosedException('Memory stream has been closed');
        }
    }
}

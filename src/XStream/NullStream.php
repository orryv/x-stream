<?php

namespace Orryv\XStream;

use Orryv\XStream\Exception\StreamClosedException;

final class NullStream implements DuplexStreamInterface, SeekableStreamInterface
{
    private bool $closed = false;

    public function read(int $length): string
    {
        $this->ensureNotClosed();
        return '';
    }

    public function write(string $data): int
    {
        $this->ensureNotClosed();
        return strlen($data);
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->ensureNotClosed();
    }

    public function tell(): int
    {
        $this->ensureNotClosed();
        return 0;
    }

    public function eof(): bool
    {
        $this->ensureNotClosed();
        return true;
    }

    public function getSize(): ?int
    {
        $this->ensureNotClosed();
        return 0;
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
        $meta = [
            'stream_type' => 'null',
            'seekable' => true,
        ];
        if ($key === null) {
            return $meta;
        }
        return $meta[$key] ?? null;
    }

    private function ensureNotClosed(): void
    {
        if ($this->closed) {
            throw new StreamClosedException('Null stream closed');
        }
    }
}

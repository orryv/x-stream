<?php

namespace Orryv\XStream\Bridge;

use Orryv\XStream\Exception\StreamClosedException;
use Orryv\XStream\Exception\StreamReadException;
use Orryv\XStream\Exception\StreamSeekException;
use Orryv\XStream\Exception\StreamWriteException;
use Orryv\XStream\Interfaces\DuplexStreamInterface;
use Orryv\XStream\Interfaces\SeekableStreamInterface;
use Psr\Http\Message\StreamInterface as PsrStreamInterface;

final class FromPsrStream implements DuplexStreamInterface, SeekableStreamInterface
{
    private ?PsrStreamInterface $stream;
    private bool $detached = false;

    public function __construct(PsrStreamInterface $stream)
    {
        $this->stream = $stream;
    }

    public function read(int $length): string
    {
        $stream = $this->requireStream();
        if (!$stream->isReadable()) {
            throw new StreamReadException('Underlying PSR stream is not readable');
        }

        return $stream->read($length);
    }

    public function write(string $data): int
    {
        $stream = $this->requireStream();
        if (!$stream->isWritable()) {
            throw new StreamWriteException('Underlying PSR stream is not writable');
        }

        return $stream->write($data);
    }

    public function flush(): void
    {
        $stream = $this->requireStream();
        if (!$stream->isWritable()) {
            throw new StreamWriteException('Underlying PSR stream is not writable');
        }

        if (method_exists($stream, 'flush')) {
            $result = $stream->flush();
            if ($result === false) {
                throw new StreamWriteException('Failed to flush PSR stream');
            }
            return;
        }

        // No flush method available. Trigger a no-op write to validate writability.
        $stream->write('');
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $stream = $this->requireStream();
        if (!$stream->isSeekable()) {
            throw new StreamSeekException('Underlying PSR stream is not seekable');
        }

        $stream->seek($offset, $whence);
    }

    public function tell(): int
    {
        return $this->requireStream()->tell();
    }

    public function eof(): bool
    {
        return $this->requireStream()->eof();
    }

    public function getSize(): ?int
    {
        return $this->requireStream()->getSize();
    }

    public function close(): void
    {
        if ($this->stream === null) {
            return;
        }

        $this->stream->close();
        $this->stream = null;
    }

    public function detach(): mixed
    {
        if ($this->stream === null) {
            return null;
        }

        $detached = $this->stream->detach();
        $this->stream = null;
        $this->detached = true;

        return $detached;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $stream = $this->requireStream();

        return $stream->getMetadata($key);
    }

    private function requireStream(): PsrStreamInterface
    {
        if ($this->stream === null) {
            throw new StreamClosedException(
                $this->detached
                    ? 'PSR stream has been detached'
                    : 'PSR stream has been closed'
            );
        }

        return $this->stream;
    }
}

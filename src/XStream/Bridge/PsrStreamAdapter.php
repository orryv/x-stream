<?php

namespace Orryv\XStream\Bridge;

use Orryv\XStream\Interfaces\ReadableStreamInterface;
use Orryv\XStream\Interfaces\SeekableStreamInterface;
use Orryv\XStream\Interfaces\StreamInterface;
use Orryv\XStream\Interfaces\WritableStreamInterface;
use Psr\Http\Message\StreamInterface as PsrStreamInterface;
use RuntimeException;

final class PsrStreamAdapter implements PsrStreamInterface
{
    private ?StreamInterface $stream;

    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    public function __toString(): string
    {
        if (!$this->stream instanceof ReadableStreamInterface) {
            return '';
        }

        try {
            $contents = '';
            $stream = $this->stream;
            $position = null;
            if ($stream instanceof SeekableStreamInterface) {
                $position = $stream->tell();
                $stream->seek(0);
            }

            while (!$stream->eof()) {
                $chunk = $stream->read(65536);
                if ($chunk === '') {
                    break;
                }
                $contents .= $chunk;
            }

            if ($position !== null) {
                $stream->seek($position);
            }

            return $contents;
        } catch (\Throwable) {
            return '';
        }
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

        return $detached;
    }

    public function getSize(): ?int
    {
        $stream = $this->requireStream();

        return $stream->getSize();
    }

    public function tell(): int
    {
        $stream = $this->requireStream();

        return $stream->tell();
    }

    public function eof(): bool
    {
        $stream = $this->requireStream();

        return $stream->eof();
    }

    public function isSeekable(): bool
    {
        return $this->stream instanceof SeekableStreamInterface;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $stream = $this->requireStream();
        if (!$stream instanceof SeekableStreamInterface) {
            throw new RuntimeException('Underlying stream is not seekable');
        }

        $stream->seek((int)$offset, (int)$whence);
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->stream instanceof WritableStreamInterface;
    }

    public function write($string): int
    {
        $stream = $this->requireStream();
        if (!$stream instanceof WritableStreamInterface) {
            throw new RuntimeException('Underlying stream is not writable');
        }

        return $stream->write((string)$string);
    }

    public function isReadable(): bool
    {
        return $this->stream instanceof ReadableStreamInterface;
    }

    public function read($length): string
    {
        $stream = $this->requireStream();
        if (!$stream instanceof ReadableStreamInterface) {
            throw new RuntimeException('Underlying stream is not readable');
        }

        return $stream->read((int)$length);
    }

    public function getContents(): string
    {
        $stream = $this->requireReadable();
        $buffer = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(65536);
            if ($chunk === '') {
                break;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    public function getMetadata($key = null): mixed
    {
        if ($this->stream === null) {
            return $key === null ? [] : null;
        }

        return $this->stream->getMetadata($key);
    }

    private function requireStream(): StreamInterface
    {
        if ($this->stream === null) {
            throw new RuntimeException('Stream has been detached or closed');
        }

        return $this->stream;
    }

    private function requireReadable(): ReadableStreamInterface
    {
        $stream = $this->requireStream();
        if (!$stream instanceof ReadableStreamInterface) {
            throw new RuntimeException('Underlying stream is not readable');
        }

        return $stream;
    }
}

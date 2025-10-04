<?php

namespace Orryv\XStream;

use Orryv\XStream\Exception\StreamOperationException;
use Orryv\XStream\Exception\StreamReadException;
use Orryv\XStream\Exception\StreamSeekException;
use Orryv\XStream\Exception\StreamWriteException;
use Orryv\XStream\Interfaces\DuplexStreamInterface;
use Orryv\XStream\Interfaces\ReadableStreamInterface;
use Orryv\XStream\Interfaces\SeekableStreamInterface;
use Orryv\XStream\Interfaces\WritableStreamInterface;
use Orryv\XStream\Interfaces\StreamInterface;

final class BufferedStream implements DuplexStreamInterface, SeekableStreamInterface
{
    private StreamInterface $inner;
    private ?ReadableStreamInterface $readable;
    private ?WritableStreamInterface $writable;
    private ?SeekableStreamInterface $seekable;
    private int $readBufferSize;
    private int $writeBufferSize;
    private bool $closeInner;

    private string $readBuffer = '';
    private string $writeBuffer = '';

    public function __construct(
        StreamInterface $inner,
        int $readBufferSize = 65536,
        int $writeBufferSize = 65536,
        bool $closeInner = true
    ) {
        $this->inner = $inner;
        $this->readable = $inner instanceof ReadableStreamInterface ? $inner : null;
        $this->writable = $inner instanceof WritableStreamInterface ? $inner : null;
        $this->seekable = $inner instanceof SeekableStreamInterface ? $inner : null;
        $this->readBufferSize = max(1, $readBufferSize);
        $this->writeBufferSize = max(1, $writeBufferSize);
        $this->closeInner = $closeInner;

        if (!$this->readable && !$this->writable) {
            throw new StreamOperationException('BufferedStream requires readable or writable underlying stream');
        }
    }

    public function read(int $length): string
    {
        if ($this->readable === null) {
            throw new StreamReadException('Underlying stream is not readable');
        }
        if ($length < 0) {
            throw new StreamReadException('Length must be >= 0');
        }
        if ($length === 0) {
            return '';
        }

        $this->fillReadBuffer($length);
        $chunk = substr($this->readBuffer, 0, $length);
        $this->readBuffer = (string)substr($this->readBuffer, strlen($chunk));
        return $chunk;
    }

    public function peek(int $length): string
    {
        if ($this->readable === null) {
            throw new StreamReadException('Underlying stream is not readable');
        }
        if ($length <= 0) {
            return '';
        }
        $this->fillReadBuffer($length);
        return substr($this->readBuffer, 0, $length);
    }

    public function readLine(?int $maxLength = null): string
    {
        if ($this->readable === null) {
            throw new StreamReadException('Underlying stream is not readable');
        }
        $line = '';
        while (true) {
            $newlinePosition = strpos($this->readBuffer, "\n");
            if ($newlinePosition !== false) {
                $segmentLength = $newlinePosition + 1;
                if ($maxLength !== null) {
                    $segmentLength = min($segmentLength, max(0, $maxLength - strlen($line)));
                }
                $line .= substr($this->readBuffer, 0, $segmentLength);
                $this->readBuffer = (string)substr($this->readBuffer, $segmentLength);
                break;
            }

            if ($this->readable->eof()) {
                $line .= $this->readBuffer;
                $this->readBuffer = '';
                break;
            }

            $this->fillReadBuffer($maxLength !== null ? max(1, $maxLength - strlen($line)) : $this->readBufferSize);
            if ($this->readBuffer === '') {
                break;
            }
            if ($maxLength !== null && strlen($line) >= $maxLength) {
                break;
            }
        }

        if ($maxLength !== null && strlen($line) > $maxLength) {
            $line = substr($line, 0, $maxLength);
        }

        return $line;
    }

    public function write(string $data): int
    {
        if ($this->writable === null) {
            throw new StreamWriteException('Underlying stream is not writable');
        }
        if ($data === '') {
            return 0;
        }
        $this->writeBuffer .= $data;
        if (strlen($this->writeBuffer) >= $this->writeBufferSize) {
            $this->flushWriteBuffer();
        }
        return strlen($data);
    }

    public function flush(): void
    {
        if ($this->writable === null) {
            return;
        }
        $this->flushWriteBuffer();
        $this->writable->flush();
    }

    public function close(): void
    {
        try {
            $this->flushWriteBuffer();
        } finally {
            if ($this->closeInner) {
                $this->inner->close();
            }
        }
    }

    public function detach(): mixed
    {
        $this->flushWriteBuffer();
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        $position = $this->inner->tell();
        $position -= strlen($this->readBuffer);
        $position += strlen($this->writeBuffer);
        return $position;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($this->seekable === null) {
            throw new StreamSeekException('Underlying stream is not seekable');
        }
        $this->flushWriteBuffer();
        $this->readBuffer = '';
        $this->seekable->seek($offset, $whence);
    }

    public function eof(): bool
    {
        if ($this->readable === null) {
            return true;
        }
        return $this->readBuffer === '' && $this->readable->eof();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }

    private function fillReadBuffer(int $length): void
    {
        if ($this->readable === null) {
            return;
        }
        while (strlen($this->readBuffer) < $length && !$this->readable->eof()) {
            $chunkSize = max($length - strlen($this->readBuffer), $this->readBufferSize);
            $chunk = $this->readable->read($chunkSize);
            if ($chunk === '') {
                break;
            }
            $this->readBuffer .= $chunk;
        }
    }

    private function flushWriteBuffer(): void
    {
        if ($this->writable === null || $this->writeBuffer === '') {
            return;
        }
        $buffer = $this->writeBuffer;
        $this->writeBuffer = '';
        $written = 0;
        $length = strlen($buffer);
        while ($written < $length) {
            $bytes = $this->writable->write(substr($buffer, $written));
            if ($bytes === 0) {
                throw new StreamWriteException('Underlying stream failed to accept buffered write');
            }
            $written += $bytes;
        }
    }
}

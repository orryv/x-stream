<?php

namespace Orryv\XStream;

use Orryv\XStream\Exception\StreamReadException;
use Orryv\XStream\Exception\StreamSeekException;
use Orryv\XStream\Exception\StreamWriteException;
use Orryv\XStream\Interfaces\DuplexStreamInterface;
use Orryv\XStream\Interfaces\ModeAwareStreamInterface;
use Orryv\XStream\Interfaces\ReopenableStreamInterface;
use Orryv\XStream\Interfaces\ReadableStreamInterface;
use Orryv\XStream\Interfaces\SeekableStreamInterface;
use Orryv\XStream\Interfaces\WritableStreamInterface;
use Orryv\XStream\Interfaces\StreamInterface;

final class RetryStream implements DuplexStreamInterface, SeekableStreamInterface
{
    public function __construct(
        private StreamInterface $inner,
        private int $retries = 3,
        private int $delayMs = 2,
        private bool $restorePosition = true
    ) {
    }

    public function read(int $length): string
    {
        return $this->runWithRetry(fn () => $this->readInner($length), true);
    }

    public function write(string $data): int
    {
        return $this->runWithRetry(fn () => $this->writeInner($data), true);
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->runWithRetry(function () use ($offset, $whence): int {
            $this->seekInner($offset, $whence);
            return 0;
        }, false);
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function flush(): void
    {
        $this->runWithRetry(function (): int {
            if ($this->inner instanceof WritableStreamInterface) {
                $this->inner->flush();
            }
            return 0;
        }, false);
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach(): mixed
    {
        return $this->inner->detach();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }

    private function readInner(int $length): string
    {
        if (!($this->inner instanceof ReadableStreamInterface)) {
            throw new StreamReadException('Inner stream is not readable');
        }
        return $this->inner->read($length);
    }

    private function writeInner(string $data): int
    {
        if (!($this->inner instanceof WritableStreamInterface)) {
            throw new StreamWriteException('Inner stream is not writable');
        }
        return $this->inner->write($data);
    }

    private function seekInner(int $offset, int $whence): void
    {
        if (!($this->inner instanceof SeekableStreamInterface)) {
            throw new StreamSeekException('Inner stream is not seekable');
        }
        $this->inner->seek($offset, $whence);
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function runWithRetry(callable $operation, bool $restorePosition)
    {
        $attempt = 0;
        $position = null;
        $canRestore = $restorePosition && $this->restorePosition && $this->inner instanceof SeekableStreamInterface;
        $appendMode = $this->inner instanceof ModeAwareStreamInterface && str_contains($this->inner->getMode(), 'a');

        if ($canRestore && !$appendMode) {
            try {
                $position = $this->inner->tell();
            } catch (\Throwable) {
                $canRestore = false;
            }
        }

        do {
            try {
                $result = $operation();
                return $result;
            } catch (\Throwable $error) {
                if (!$this->shouldRetry($attempt, $error)) {
                    throw $error;
                }
                $attempt++;
                $this->performReopen($canRestore && !$appendMode ? $position : null);
            }
        } while (true);
    }

    private function shouldRetry(int $attempt, \Throwable $error): bool
    {
        if (!($this->inner instanceof ReopenableStreamInterface)) {
            return false;
        }
        if ($attempt >= $this->retries) {
            return false;
        }
        return true;
    }

    private function performReopen(?int $position): void
    {
        if (!($this->inner instanceof ReopenableStreamInterface)) {
            return;
        }
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }
        $this->inner->reopen();
        if ($position !== null && $this->inner instanceof SeekableStreamInterface) {
            try {
                $size = $this->inner->getSize();
                if ($size !== null) {
                    $position = min($position, $size);
                }
                $this->inner->seek($position, SEEK_SET);
            } catch (\Throwable) {
                // swallow restore errors and continue
            }
        }
    }
}

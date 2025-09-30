<?php

declare(strict_types=1);

namespace Orryv\XStream\Tests;

use Orryv\XStream\Interfaces\DuplexStreamInterface;
use Orryv\XStream\Interfaces\ModeAwareStreamInterface;
use Orryv\XStream\Interfaces\ReopenableStreamInterface;
use Orryv\XStream\Interfaces\SeekableStreamInterface;
use Orryv\XStream\MemoryStream;
use Orryv\XStream\RetryStream;
use PHPUnit\Framework\TestCase;

final class RetryStreamTest extends TestCase
{
    public function testReadRetriesAndRestoresPosition(): void
    {
        $inner = $this->createFlakyStream('abcdef', readFailures: 1);
        $retry = new RetryStream($inner, retries: 2, delayMs: 0);

        $this->assertSame('abc', $retry->read(3));
        $this->assertSame(1, $inner->reopenCount);
        $this->assertSame([[0, SEEK_SET]], $inner->seekHistory);
    }

    public function testWriteRetriesUntilSuccess(): void
    {
        $inner = $this->createFlakyStream('seed', readFailures: 0, writeFailures: 1);
        $retry = new RetryStream($inner, retries: 3, delayMs: 0);

        $retry->write('++');
        $retry->write('end');

        $this->assertSame(1, $inner->reopenCount);
        $retry->seek(0);
        $this->assertSame('++end', $retry->read(5));
    }

    public function testFailureOnNonReopenableStreamPropagates(): void
    {
        $stream = new MemoryStream('fail');
        $stream->close();

        $retry = new RetryStream($stream, retries: 3, delayMs: 0);

        $this->expectException(\Orryv\XStream\Exception\StreamClosedException::class);
        $retry->read(1);
    }

    public function testDisabledPositionRestorationSkipsSeeking(): void
    {
        $inner = $this->createFlakyStream('abcdef', readFailures: 1, writeFailures: 0, mode: 'a+b');
        $retry = new RetryStream($inner, retries: 1, delayMs: 0, restorePosition: false);

        $retry->read(3);

        $this->assertSame([], $inner->seekHistory);
    }

    /**
     * @return object&DuplexStreamInterface&SeekableStreamInterface&ReopenableStreamInterface&ModeAwareStreamInterface
     */
    private function createFlakyStream(string $buffer, int $readFailures = 0, int $writeFailures = 0, string $mode = 'c+b'): object
    {
        return new class($buffer, $readFailures, $writeFailures, $mode) implements DuplexStreamInterface, SeekableStreamInterface, ReopenableStreamInterface, ModeAwareStreamInterface {
            public int $position = 0;
            public int $reopenCount = 0;
            public array $seekHistory = [];
            public bool $closed = false;

            public function __construct(private string $buffer, private int $readFailures, private int $writeFailures, private string $mode)
            {
            }

            public function read(int $length): string
            {
                if ($this->closed) {
                    throw new \RuntimeException('closed');
                }
                if ($this->readFailures > 0) {
                    $this->readFailures--;
                    throw new \RuntimeException('read-fail');
                }
                $chunk = substr($this->buffer, $this->position, $length);
                $this->position += strlen($chunk);
                return $chunk;
            }

            public function write(string $data): int
            {
                if ($this->closed) {
                    throw new \RuntimeException('closed');
                }
                if ($this->writeFailures > 0) {
                    $this->writeFailures--;
                    throw new \RuntimeException('write-fail');
                }
                $before = substr($this->buffer, 0, $this->position);
                $after = substr($this->buffer, $this->position + strlen($data));
                $this->buffer = $before . $data . $after;
                $this->position += strlen($data);
                return strlen($data);
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                $this->seekHistory[] = [$offset, $whence];
                $target = match ($whence) {
                    SEEK_SET => $offset,
                    SEEK_CUR => $this->position + $offset,
                    SEEK_END => strlen($this->buffer) + $offset,
                };
                $this->position = max(0, $target);
            }

            public function tell(): int
            {
                return $this->position;
            }

            public function eof(): bool
            {
                return $this->position >= strlen($this->buffer);
            }

            public function getSize(): ?int
            {
                return strlen($this->buffer);
            }

            public function flush(): void
            {
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
                return $key === null ? ['mode' => $this->mode] : ($key === 'mode' ? $this->mode : null);
            }

            public function reopen(): void
            {
                $this->closed = false;
                $this->reopenCount++;
            }

            public function getMode(): string
            {
                return $this->mode;
            }
        };
    }
}

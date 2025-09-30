<?php

declare(strict_types=1);

namespace Orryv\XStream\Tests;

use Orryv\XStream\Exception\StreamSeekException;
use Orryv\XStream\Exception\StreamWriteException;
use Orryv\XStream\MemoryStream;
use Orryv\XStream\SeekableStreamInterface;
use Orryv\XStream\TeeWriter;
use Orryv\XStream\WritableStreamInterface;
use PHPUnit\Framework\TestCase;

final class TeeWriterTest extends TestCase
{
    public function testBroadcastsChunksToAllSinks(): void
    {
        $sinkA = new MemoryStream();
        $sinkB = new MemoryStream();
        $writer = new TeeWriter([$sinkA, $sinkB], chunkSize: 3, closeSinks: false);

        $writer->write('abcdef');
        $writer->flush();

        $sinkA->seek(0);
        $sinkB->seek(0);
        $this->assertSame('abcdef', $sinkA->read(1024));
        $this->assertSame('abcdef', $sinkB->read(1024));
        $this->assertSame(2, $writer->getMetadata('sinks'));
    }

    public function testBestEffortCollectsErrors(): void
    {
        $sink = new MemoryStream();
        $failing = new class implements WritableStreamInterface {
            public function write(string $data): int
            {
                throw new \RuntimeException('write fail');
            }

            public function flush(): void
            {
            }

            public function close(): void
            {
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return 0;
            }

            public function eof(): bool
            {
                return true;
            }

            public function getMetadata(?string $key = null): mixed
            {
                return null;
            }

            public function detach(): mixed
            {
                return null;
            }
        };

        $writer = new TeeWriter([$sink, $failing], policy: 'best_effort', closeSinks: false);

        $this->expectException(StreamWriteException::class);
        $writer->write('payload');

        $sink->seek(0);
        $this->assertSame('payload', $sink->read(1024));
    }

    public function testFlushAndCloseHonourFlags(): void
    {
        $sink = new class implements WritableStreamInterface, SeekableStreamInterface {
            public bool $flushed = false;
            public bool $closed = false;
            private MemoryStream $inner;

            public function __construct()
            {
                $this->inner = new MemoryStream();
            }

            public function write(string $data): int
            {
                return $this->inner->write($data);
            }

            public function flush(): void
            {
                $this->flushed = true;
                $this->inner->flush();
            }

            public function close(): void
            {
                $this->closed = true;
                $this->inner->close();
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                $this->inner->seek($offset, $whence);
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

            public function detach(): mixed
            {
                return $this->inner->detach();
            }

            public function getMetadata(?string $key = null): mixed
            {
                return $this->inner->getMetadata($key);
            }
        };

        $writer = new TeeWriter([$sink], closeSinks: true);
        $writer->write('ok');
        $writer->close();

        $this->assertTrue($sink->flushed);
        $this->assertTrue($sink->closed);
    }

    public function testTellThrowsSeekException(): void
    {
        $writer = new TeeWriter([new MemoryStream()], closeSinks: false);
        $this->expectException(StreamSeekException::class);
        $writer->tell();
    }
}

<?php

declare(strict_types=1);

namespace Orryv\XStream\Tests;

use Orryv\XStream\Exception\StreamReadException;
use Orryv\XStream\Interfaces\ReadableStreamInterface;
use Orryv\XStream\Interfaces\SeekableStreamInterface;
use Orryv\XStream\Interfaces\WritableStreamInterface;
use Orryv\XStream\MemoryStream;
use Orryv\XStream\TeeReader;
use PHPUnit\Framework\TestCase;

final class TeeReaderTest extends TestCase
{
    public function testReadMirrorsDataToAllSinks(): void
    {
        $source = new MemoryStream('hello world');
        $sinkA = new MemoryStream();
        $sinkB = new MemoryStream();
        $reader = new TeeReader($source, [$sinkA, $sinkB], policy: 'fail_fast', closeSource: false, closeSinks: false);

        $this->assertSame('hello', $reader->read(5));
        $this->assertSame(' world', $reader->read(6));
        $this->assertTrue($reader->eof());

        $sinkA->seek(0);
        $sinkB->seek(0);
        $this->assertSame('hello world', $sinkA->read(1024));
        $this->assertSame('hello world', $sinkB->read(1024));
    }

    public function testBestEffortPolicyAggregatesErrors(): void
    {
        $source = new MemoryStream('payload');
        $audit = new MemoryStream();
        $failing = new class implements WritableStreamInterface {
            public function write(string $data): int
            {
                throw new \RuntimeException('sink failure');
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

        $reader = new TeeReader($source, [$audit, $failing], policy: 'best_effort', closeSource: false, closeSinks: false);

        $this->expectException(StreamReadException::class);
        $reader->read(7);

        $audit->seek(0);
        $this->assertSame('payload', $audit->read(1024));
    }

    public function testSeekPropagatesToSeekableSinks(): void
    {
        $source = new MemoryStream('abcdef');
        $sink = new MemoryStream();
        $reader = new TeeReader($source, [$sink], policy: 'fail_fast', closeSource: false, closeSinks: false);

        $reader->read(3);
        $reader->seek(1);
        $this->assertSame('bc', $reader->read(2));
    }

    public function testCloseHonoursFlags(): void
    {
        $source = new class implements ReadableStreamInterface, SeekableStreamInterface {
            public bool $closed = false;
            private MemoryStream $inner;

            public function __construct()
            {
                $this->inner = new MemoryStream();
            }

            public function read(int $length): string
            {
                return $this->inner->read($length);
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

            public function flush(): void
            {
                $this->inner->flush();
            }

            public function close(): void
            {
                $this->closed = true;
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
        };

        $sink = new class implements WritableStreamInterface, SeekableStreamInterface {
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

        $reader = new TeeReader($source, [$sink], closeSource: false, closeSinks: true);
        $reader->close();

        $this->assertFalse($source->closed);
        $this->assertTrue($sink->closed);
    }
}

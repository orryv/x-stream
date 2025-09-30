<?php

declare(strict_types=1);

namespace Orryv\XStream\Tests;

use Orryv\XStream\BufferedStream;
use Orryv\XStream\DuplexStreamInterface;
use Orryv\XStream\MemoryStream;
use Orryv\XStream\SeekableStreamInterface;
use PHPUnit\Framework\TestCase;

final class BufferedStreamTest extends TestCase
{
    public function testBufferedReadPeekAndLineOperations(): void
    {
        $inner = new MemoryStream("alpha\nbravo\n");
        $buffered = new BufferedStream($inner, 3, 4, closeInner: false);

        $this->assertSame('alp', $buffered->read(3));
        $this->assertSame(3, $buffered->tell());
        $this->assertSame("ha\n", $buffered->readLine());
        $this->assertSame('br', $buffered->peek(2));
        $this->assertSame('bravo', $buffered->read(5));
        $this->assertSame("\n", $buffered->read(1));
        $this->assertTrue($buffered->eof());
    }

    public function testBufferedWriteFlushAndCloseBehaviour(): void
    {
        $inner = new class implements DuplexStreamInterface, SeekableStreamInterface {
            private MemoryStream $inner;
            public int $flushCalls = 0;
            public bool $closed = false;

            public function __construct()
            {
                $this->inner = new MemoryStream();
            }

            public function read(int $length): string
            {
                return $this->inner->read($length);
            }

            public function write(string $data): int
            {
                return $this->inner->write($data);
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
                $this->flushCalls++;
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

            public function contents(): string
            {
                return $this->inner->getContents();
            }
        };

        $buffered = new BufferedStream($inner, 2, 4, closeInner: false);

        $buffered->write('ab');
        $this->assertSame('', $inner->contents());

        $buffered->write('cd');
        $buffered->flush();

        $inner->seek(0);
        $this->assertSame('abcd', $inner->read(4));
        $this->assertSame(1, $inner->flushCalls);

        $buffered->close();
        $this->assertFalse($inner->closed);
        $inner->seek(0);
        $this->assertSame('abcd', $inner->read(4));
    }

    public function testSeekClearsReadBuffer(): void
    {
        $inner = new MemoryStream('abcdef');
        $buffered = new BufferedStream($inner, 2, 2, closeInner: false);

        $this->assertSame('ab', $buffered->read(2));
        $buffered->seek(1, SEEK_SET);
        $this->assertSame('bc', $buffered->read(2));
    }
}

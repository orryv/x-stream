<?php

declare(strict_types=1);

namespace Orryv\XStream\Tests;

use Orryv\XStream\BufferedStream;
use Orryv\XStream\FileStream;
use Orryv\XStream\MemoryStream;
use Orryv\XStream\NullStream;
use Orryv\XStream\RetryStream;
use Orryv\XStream\TempStream;
use Orryv\XStream;
use PHPUnit\Framework\TestCase;

final class XStreamFactoryTest extends TestCase
{
    public function testMemoryFactoryInitialisesBuffer(): void
    {
        $stream = XStream::memory('seed');
        $this->assertInstanceOf(MemoryStream::class, $stream);
        $this->assertSame('seed', $stream->getContents());
    }

    public function testNullFactory(): void
    {
        $stream = XStream::null();
        $this->assertInstanceOf(NullStream::class, $stream);
        $this->assertSame('', $stream->read(10));
    }

    public function testTempFactory(): void
    {
        $stream = XStream::temp(2048);
        $this->assertInstanceOf(TempStream::class, $stream);
        $stream->write('temp');
        $stream->seek(0);
        $this->assertSame('temp', $stream->read(4));
    }

    public function testBufferedHelperWrapsInnerStream(): void
    {
        $inner = XStream::memory('abc');
        $buffered = XStream::buffered($inner, readBufferSize: 2, writeBufferSize: 2, closeInner: false);
        $this->assertInstanceOf(BufferedStream::class, $buffered);
        $this->assertSame('ab', $buffered->read(2));
    }

    public function testRetryHelperWrapsStream(): void
    {
        $inner = XStream::memory('data');
        $retry = XStream::retry($inner, retries: 1, delayMs: 0, restorePosition: false);
        $this->assertInstanceOf(RetryStream::class, $retry);
    }

    public function testFileFactoryRespectsOptions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xstream');
        self::assertIsString($path);

        try {
            $stream = XStream::file($path, 'c+b', [
                'retry' => false,
            ]);
            $this->assertInstanceOf(FileStream::class, $stream);
        } finally {
            @unlink($path);
        }
    }
}

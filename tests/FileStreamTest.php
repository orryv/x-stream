<?php

declare(strict_types=1);

namespace Orryv\XStream\Tests;

use Orryv\XStream\BufferedStream;
use Orryv\XStream\Exception\StreamClosedException;
use Orryv\XStream\FileStream;
use Orryv\XStream\MemoryStream;
use Orryv\XStream\RetryStream;
use Orryv\XStream\XStream;
use PHPUnit\Framework\TestCase;

final class FileStreamTest extends TestCase
{
    public function testFileStreamReadWriteAndSeek(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xstream');
        self::assertIsString($path);

        try {
            $stream = new FileStream($path, 'c+b');
            $stream->write('hello world');
            $this->assertSame(11, $stream->tell());
            $stream->seek(0);
            $this->assertSame('hello', $stream->read(5));
            $this->assertSame(11, $stream->getSize());
            $stream->close();
        } finally {
            @unlink($path);
        }
    }

    public function testReopenRestoresPosition(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xstream');
        self::assertIsString($path);

        try {
            $stream = new FileStream($path, 'c+b');
            $stream->write('abcdef');
            $stream->seek(3);
            $stream->reopen();
            $this->assertSame(3, $stream->tell());
            $this->assertSame('def', $stream->read(3));
            $stream->close();
        } finally {
            @unlink($path);
        }
    }

    public function testAppendModeReopenPreservesEndPosition(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xstream');
        self::assertIsString($path);

        try {
            $stream = new FileStream($path, 'ab+');
            $stream->write('foo');
            $stream->seek(0);
            $stream->write('bar');
            $stream->seek(0);
            $this->assertSame('foobar', $stream->read(6));
            $this->assertTrue($stream->isAppendMode());

            $stream->seek(0, SEEK_END);
            $endPosition = $stream->tell();

            $stream->reopen();

            $this->assertTrue($stream->isAppendMode());
            $this->assertSame($endPosition, $stream->tell());

            $stream->close();
        } finally {
            @unlink($path);
        }
    }

    public function testRetryStreamReopensClosedFileHandle(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xstream');
        self::assertIsString($path);

        try {
            $file = new FileStream($path, 'c+b');
            $file->write('abcdef');
            $file->seek(0);

            $retry = new RetryStream($file, retries: 2, delayMs: 0);
            $this->assertSame('abc', $retry->read(3));

            $file->close();

            $this->assertSame('def', $retry->read(3));
        } finally {
            @unlink($path);
        }
    }

    public function testBufferedStreamProvidesPeekAndLineReading(): void
    {
        $memory = new MemoryStream("first\nsecond\n");
        $buffered = new BufferedStream($memory, 4, 4, closeInner: false);

        $this->assertSame('fir', $buffered->read(3));
        $this->assertSame("st\n", $buffered->readLine());
        $this->assertSame('se', $buffered->peek(2));
        $this->assertSame('second', $buffered->read(6));
        $this->assertSame("\n", $buffered->read(1));
        $this->assertTrue($buffered->eof());
        $buffered->close();
    }

    public function testXStreamFileFactory(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xstream');
        self::assertIsString($path);

        try {
            $stream = XStream::file($path, 'c+b', [
                'read_buffer' => 128,
                'write_buffer' => 128,
                'buffered' => true,
            ]);
            $this->assertInstanceOf(BufferedStream::class, $stream);
            $stream->write('payload');
            $stream->seek(0);
            $this->assertSame('payload', $stream->read(7));
            $stream->close();
        } finally {
            @unlink($path);
        }
    }

    public function testFileStreamMetadataAndDetach(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xstream');
        self::assertIsString($path);

        try {
            $stream = new FileStream($path, 'c+b');
            $stream->setReadBuffer(32);
            $stream->setWriteBuffer(16);
            $stream->setChunkSize(8);
            $stream->setBlocking(true);
            $stream->setTimeout(1, 500);

            $metadata = $stream->getMetadata();
            $this->assertSame($path, $metadata['uri']);

            $handle = $stream->detach();
            $this->assertIsResource($handle);
            fclose($handle);

            $this->expectException(StreamClosedException::class);
            $stream->read(1);
        } finally {
            @unlink($path);
        }
    }
}

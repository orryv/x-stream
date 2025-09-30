<?php

declare(strict_types=1);

namespace Orryv\XStream\Tests;

use Orryv\XStream\Exception\StreamClosedException;
use Orryv\XStream\Exception\StreamReadException;
use Orryv\XStream\Exception\StreamSeekException;
use Orryv\XStream\MemoryStream;
use PHPUnit\Framework\TestCase;

final class MemoryStreamTest extends TestCase
{
    public function testReadWriteSeekAndMetadata(): void
    {
        $stream = new MemoryStream('seed');

        $this->assertSame('se', $stream->read(2));
        $this->assertFalse($stream->eof());
        $stream->seek(0, SEEK_END);
        $stream->write('-tail');
        $this->assertSame(9, $stream->tell());
        $stream->seek(0, SEEK_SET);
        $this->assertSame('seed-tail', $stream->read(9));
        $this->assertTrue($stream->eof());
        $this->assertSame(9, $stream->getSize());
        $meta = $stream->getMetadata();
        $this->assertSame('memory', $meta['stream_type']);
    }

    public function testSeekRelativeAndEndBounds(): void
    {
        $stream = new MemoryStream('abcdef');
        $stream->seek(2, SEEK_CUR);
        $this->assertSame('cd', $stream->read(2));
        $stream->seek(-2, SEEK_END);
        $this->assertSame('ef', $stream->read(2));
        $this->assertTrue($stream->eof());
    }

    public function testDisallowsNegativeRead(): void
    {
        $stream = new MemoryStream('abc');
        $this->expectException(StreamReadException::class);
        $stream->read(-1);
    }

    public function testThrowsWhenClosed(): void
    {
        $stream = new MemoryStream('abc');
        $stream->close();

        $this->expectException(StreamClosedException::class);
        $stream->read(1);
    }

    public function testSeekBeforeStartThrows(): void
    {
        $stream = new MemoryStream('abc');
        $this->expectException(StreamSeekException::class);
        $stream->seek(-10, SEEK_SET);
    }
}

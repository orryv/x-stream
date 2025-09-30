<?php

declare(strict_types=1);

namespace Orryv\XStream\Tests;

use Orryv\XStream\BufferedStream;
use Orryv\XStream\Exception\StreamClosedException;
use Orryv\XStream\ResourceStream;
use Orryv\XStream\XStream;
use PHPUnit\Framework\TestCase;

final class ResourceStreamTest extends TestCase
{
    public function testResourceStreamReadWriteAndSeek(): void
    {
        $resource = fopen('php://temp', 'c+b');
        self::assertIsResource($resource);

        $stream = new ResourceStream($resource);
        $this->assertSame(0, $stream->tell());
        $stream->write('hello world');
        $this->assertSame(11, $stream->tell());
        $stream->seek(0);
        $this->assertSame('hello', $stream->read(5));
        $this->assertSame(11, $stream->getSize());
        $this->assertFalse($stream->eof());
        $stream->seek(0, SEEK_END);
        $this->assertSame('', $stream->read(1));
        $this->assertTrue($stream->eof());
        $stream->close();
    }

    public function testDetachAndMetadata(): void
    {
        $resource = fopen('php://temp', 'c+b');
        self::assertIsResource($resource);
        fwrite($resource, 'data');
        rewind($resource);

        $stream = new ResourceStream($resource);
        $metadata = $stream->getMetadata();
        $this->assertSame('php://temp', $metadata['uri']);

        $detached = $stream->detach();
        $this->assertIsResource($detached);
        $this->expectException(StreamClosedException::class);
        $stream->read(1);

        fclose($detached);
    }

    public function testDetectsExternallyClosedResource(): void
    {
        $resource = fopen('php://temp', 'c+b');
        self::assertIsResource($resource);

        $stream = new ResourceStream($resource);
        fclose($resource);

        $this->expectException(StreamClosedException::class);
        $stream->read(1);
    }

    public function testXStreamFromResourceFactoryAppliesDecorators(): void
    {
        $resource = fopen('php://temp', 'c+b');
        self::assertIsResource($resource);

        $stream = XStream::fromResource($resource, [
            'buffered' => true,
            'retry' => true,
        ]);

        $this->assertInstanceOf(BufferedStream::class, $stream);
        $stream->write('payload');
        $stream->seek(0);
        $this->assertSame('payload', $stream->read(7));
        $stream->close();
    }
}

<?php

declare(strict_types=1);

namespace Orryv\XStream\Tests;

use Orryv\XStream\Exception\StreamClosedException;
use Orryv\XStream\NullStream;
use PHPUnit\Framework\TestCase;

final class NullStreamTest extends TestCase
{
    public function testReadWriteAndMetadata(): void
    {
        $stream = new NullStream();
        $this->assertSame('', $stream->read(100));
        $this->assertSame(4, $stream->write('test'));
        $this->assertSame(0, $stream->tell());
        $this->assertTrue($stream->eof());
        $this->assertSame(0, $stream->getSize());
        $this->assertSame('null', $stream->getMetadata()['stream_type']);
    }

    public function testClosePreventsFurtherOperations(): void
    {
        $stream = new NullStream();
        $stream->close();

        $this->expectException(StreamClosedException::class);
        $stream->read(1);
    }
}

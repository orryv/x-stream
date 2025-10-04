<?php

namespace Orryv\XStream\Tests;

use Orryv\XStream\Bridge\FromPsrStream;
use Orryv\XStream\Bridge\PsrStreamAdapter;
use Orryv\XStream\Exception\StreamClosedException;
use Orryv\XStream\MemoryStream;
use Orryv\XStream;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PsrInteropTest extends TestCase
{
    public function testRoundTripReadAndWrite(): void
    {
        $memory = XStream::memory('abcdef');
        $psr = XStream::asPsrStream($memory);

        self::assertSame('abc', $psr->read(3));
        self::assertSame('def', $psr->read(3));
        self::assertSame(3, $psr->write('ghi'));

        $bridge = XStream::fromPsrStream($psr);
        $bridge->seek(0);

        self::assertSame('abcdefghi', $bridge->read(1024));
    }

    public function testClosePropagates(): void
    {
        $memory = new MemoryStream('1234');
        $psr = new PsrStreamAdapter($memory);

        $psr->close();
        try {
            $psr->read(1);
            self::fail('Expected RuntimeException after close');
        } catch (RuntimeException $exception) {
            self::assertSame('Stream has been detached or closed', $exception->getMessage());
        }

        try {
            $memory->tell();
            self::fail('Expected StreamClosedException after close');
        } catch (StreamClosedException) {
            self::assertTrue(true);
        }
    }

    public function testDetachPropagatesAndDisablesOperations(): void
    {
        $memory = new MemoryStream('foo');
        $psr = new PsrStreamAdapter($memory);
        self::assertNull($psr->detach());

        try {
            $psr->read(1);
            self::fail('Expected RuntimeException after detach');
        } catch (RuntimeException $exception) {
            self::assertSame('Stream has been detached or closed', $exception->getMessage());
        }

        try {
            $memory->tell();
            self::fail('Expected StreamClosedException after detach');
        } catch (StreamClosedException) {
            self::assertTrue(true);
        }
    }

    public function testFromPsrStreamCloseAndDetach(): void
    {
        $memory = new MemoryStream('initial');
        $psr = new PsrStreamAdapter($memory);
        $bridge = new FromPsrStream($psr);

        $bridge->close();
        try {
            $memory->tell();
            self::fail('Expected StreamClosedException after close');
        } catch (StreamClosedException) {
            self::assertTrue(true);
        }

        $memory2 = new MemoryStream('payload');
        $psr2 = new PsrStreamAdapter($memory2);
        $bridge2 = new FromPsrStream($psr2);

        self::assertNull($bridge2->detach());

        try {
            $bridge2->read(1);
            self::fail('Expected StreamClosedException after detach');
        } catch (StreamClosedException) {
            self::assertTrue(true);
        }

        try {
            $psr2->read(1);
            self::fail('Expected RuntimeException after detach');
        } catch (RuntimeException $exception) {
            self::assertSame('Stream has been detached or closed', $exception->getMessage());
        }
    }

    public function testPartialReadWriteThroughAdapters(): void
    {
        $memory = new MemoryStream('abcdefgh');
        $psr = new PsrStreamAdapter($memory);

        self::assertSame('abc', $psr->read(3));
        self::assertSame('de', $psr->read(2));

        $bridge = new FromPsrStream($psr);
        self::assertSame(3, $bridge->write('XYZ'));
        $bridge->seek(0);

        self::assertSame('abcdeXYZ', $bridge->read(1024));
    }
}

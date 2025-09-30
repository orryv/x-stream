<?php

declare(strict_types=1);

namespace Orryv\XStream\Tests;

use Orryv\XStream\TempStream;
use PHPUnit\Framework\TestCase;

final class TempStreamTest extends TestCase
{
    public function testTempStreamPersistsInMemory(): void
    {
        $stream = new TempStream(1024);
        $stream->write('temporary');
        $stream->seek(0);

        $this->assertSame('temporary', $stream->read(9));
        $meta = $stream->getMetadata();
        $this->assertStringContainsString('php://temp', $meta['uri']);
        $stream->close();
    }
}

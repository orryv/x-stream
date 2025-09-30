<?php

namespace Orryv\XStream;

final class TempStream extends FileStream
{
    public function __construct(int $limitBytes = 2_000_000)
    {
        $uri = sprintf('php://temp/maxmemory:%d', $limitBytes);
        parent::__construct($uri, 'c+b');
    }
}

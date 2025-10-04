<?php

namespace Orryv\XStream\Interfaces;

use Orryv\XStream\Interfaces\StreamInterface;

interface SeekableStreamInterface extends StreamInterface
{
    public function seek(int $offset, int $whence = SEEK_SET): void;
}

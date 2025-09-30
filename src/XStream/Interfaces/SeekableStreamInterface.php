<?php

namespace Orryv\XStream;

interface SeekableStreamInterface extends StreamInterface
{
    public function seek(int $offset, int $whence = SEEK_SET): void;
}

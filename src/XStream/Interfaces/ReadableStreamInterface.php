<?php

namespace Orryv\XStream\Interfaces;

use Orryv\XStream\Interfaces\StreamInterface;

interface ReadableStreamInterface extends StreamInterface
{
    public function read(int $length): string;
}

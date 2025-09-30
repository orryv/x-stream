<?php

namespace Orryv\XStream;

interface ReadableStreamInterface extends StreamInterface
{
    public function read(int $length): string;
}

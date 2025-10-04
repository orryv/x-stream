<?php

namespace Orryv\XStream\Interfaces;

use Orryv\XStream\Interfaces\StreamInterface;

interface WritableStreamInterface extends StreamInterface
{
    public function write(string $data): int;

    public function flush(): void;
}

<?php

namespace Orryv\XStream;

interface WritableStreamInterface extends StreamInterface
{
    public function write(string $data): int;

    public function flush(): void;
}

<?php

namespace Orryv\XStream\Interfaces;

interface StreamInterface
{
    public function close(): void;

    public function getSize(): ?int;

    public function tell(): int;

    public function eof(): bool;

    public function getMetadata(?string $key = null): mixed;

    public function detach(): mixed;
}

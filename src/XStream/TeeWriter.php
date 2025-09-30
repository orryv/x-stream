<?php

namespace Orryv\XStream;

use Orryv\XStream\Exception\StreamSeekException;
use Orryv\XStream\Exception\StreamWriteException;
use Orryv\XStream\Interfaces\WritableStreamInterface;

final class TeeWriter implements WritableStreamInterface
{
    /** @var WritableStreamInterface[] */
    private array $sinks;
    private int $chunkSize;
    private bool $closeSinks;
    private string $policy;
    private $onEvent;

    /**
     * @param WritableStreamInterface[] $sinks
     */
    public function __construct(array $sinks, int $chunkSize = 262144, string $policy = 'fail_fast', bool $closeSinks = true, ?callable $onEvent = null)
    {
        if ($sinks === []) {
            throw new StreamWriteException('TeeWriter requires at least one sink');
        }
        $this->sinks = array_values($sinks);
        $this->chunkSize = max(1, $chunkSize);
        $this->policy = $policy;
        $this->closeSinks = $closeSinks;
        $this->onEvent = $onEvent;
    }

    public function write(string $data): int
    {
        if ($data === '') {
            return 0;
        }
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $chunk = substr($data, $offset, $this->chunkSize);
            $this->broadcast($chunk);
            $offset += strlen($chunk);
        }
        return $length;
    }

    public function flush(): void
    {
        $errors = [];
        foreach ($this->sinks as $index => $sink) {
            try {
                $sink->flush();
                $this->emit('flush_ok', ['index' => $index]);
            } catch (\Throwable $e) {
                $errors[] = $e;
                $this->emit('flush_err', ['index' => $index, 'error' => $e->getMessage()]);
                if ($this->policy === 'fail_fast') {
                    throw $e;
                }
            }
        }
        if ($errors !== [] && $this->policy !== 'fail_fast') {
            throw new StreamWriteException($errors[0]->getMessage(), (int)$errors[0]->getCode(), $errors[0]);
        }
    }

    public function close(): void
    {
        try {
            $this->flush();
        } finally {
            if ($this->closeSinks) {
                foreach ($this->sinks as $index => $sink) {
                    try {
                        $sink->close();
                        $this->emit('close_ok', ['index' => $index]);
                    } catch (\Throwable $e) {
                        $this->emit('close_err', ['index' => $index, 'error' => $e->getMessage()]);
                    }
                }
            }
        }
    }

    public function detach(): mixed
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        throw new StreamSeekException('TeeWriter has no cursor');
    }

    public function eof(): bool
    {
        return false;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $meta = [
            'sinks' => count($this->sinks),
            'policy' => $this->policy,
            'chunkSize' => $this->chunkSize,
        ];
        if ($key === null) {
            return $meta;
        }
        return $meta[$key] ?? null;
    }

    private function broadcast(string $chunk): void
    {
        $errors = [];
        foreach ($this->sinks as $index => $sink) {
            $remaining = $chunk;
            while ($remaining !== '') {
                try {
                    $written = $sink->write($remaining);
                    if ($written <= 0) {
                        throw new StreamWriteException('Sink failed to write chunk');
                    }
                    $this->emit('write_ok', ['index' => $index, 'bytes' => $written]);
                    $remaining = substr($remaining, $written);
                } catch (\Throwable $e) {
                    $errors[] = $e;
                    $this->emit('write_err', ['index' => $index, 'error' => $e->getMessage()]);
                    if ($this->policy === 'fail_fast') {
                        throw $e;
                    }
                    break;
                }
            }
        }
        if ($errors !== [] && $this->policy !== 'fail_fast') {
            throw new StreamWriteException($errors[0]->getMessage(), (int)$errors[0]->getCode(), $errors[0]);
        }
    }

    private function emit(string $event, array $context): void
    {
        if ($this->onEvent) {
            ($this->onEvent)($event, $context);
        }
    }
}

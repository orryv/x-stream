<?php

namespace Orryv\XStream;

use Orryv\XStream\Exception\StreamReadException;
use Orryv\XStream\Exception\StreamSeekException;
use Orryv\XStream\Interfaces\ReadableStreamInterface;
use Orryv\XStream\Interfaces\SeekableStreamInterface;
use Orryv\XStream\Interfaces\WritableStreamInterface;

final class TeeReader implements ReadableStreamInterface, SeekableStreamInterface
{
    private ReadableStreamInterface $source;
    /** @var WritableStreamInterface[] */
    private array $sinks;
    private bool $closeSource;
    private bool $closeSinks;
    private string $policy;
    /** @var callable|null */
    private $onEvent;

    /**
     * @param WritableStreamInterface[] $sinks
     */
    public function __construct(
        ReadableStreamInterface $source,
        array $sinks,
        string $policy = 'best_effort',
        bool $closeSource = true,
        bool $closeSinks = false,
        ?callable $onEvent = null
    ) {
        if ($sinks === []) {
            throw new StreamReadException('TeeReader requires at least one sink');
        }
        $this->source = $source;
        $this->sinks = array_values($sinks);
        $this->policy = $policy;
        $this->closeSource = $closeSource;
        $this->closeSinks = $closeSinks;
        $this->onEvent = $onEvent;
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new StreamReadException('Length must be >= 0');
        }
        if ($length === 0) {
            return '';
        }
        $data = $this->source->read($length);
        if ($data === '') {
            return $data;
        }
        $this->fanout($data);
        return $data;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!($this->source instanceof SeekableStreamInterface)) {
            throw new StreamSeekException('Source stream is not seekable');
        }
        $this->source->seek($offset, $whence);
        foreach ($this->sinks as $sink) {
            if ($sink instanceof SeekableStreamInterface) {
                $sink->seek($offset, $whence);
            }
        }
    }

    public function tell(): int
    {
        return $this->source->tell();
    }

    public function eof(): bool
    {
        return $this->source->eof();
    }

    public function getSize(): ?int
    {
        return $this->source->getSize();
    }

    public function flush(): void
    {
        foreach ($this->sinks as $sink) {
            $sink->flush();
        }
    }

    public function close(): void
    {
        if ($this->closeSource) {
            $this->source->close();
        }
        if ($this->closeSinks) {
            foreach ($this->sinks as $sink) {
                $sink->close();
            }
        }
    }

    public function detach(): mixed
    {
        return $this->source->detach();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->source->getMetadata($key);
    }

    private function fanout(string $data): void
    {
        $errors = [];
        foreach ($this->sinks as $index => $sink) {
            $remaining = $data;
            while ($remaining !== '') {
                try {
                    $written = $sink->write($remaining);
                    if ($written <= 0) {
                        throw new StreamReadException('Sink failed to mirror data');
                    }
                    $this->emit('mirror_ok', ['index' => $index, 'bytes' => $written]);
                    $remaining = substr($remaining, $written);
                } catch (\Throwable $e) {
                    $errors[] = $e;
                    $this->emit('mirror_err', ['index' => $index, 'error' => $e->getMessage()]);
                    if ($this->policy === 'fail_fast') {
                        throw $e;
                    }
                    break;
                }
            }
        }
        if ($errors !== [] && $this->policy !== 'fail_fast') {
            throw new StreamReadException($errors[0]->getMessage(), (int)$errors[0]->getCode(), $errors[0]);
        }
    }

    private function emit(string $event, array $context): void
    {
        if ($this->onEvent) {
            ($this->onEvent)($event, $context);
        }
    }
}

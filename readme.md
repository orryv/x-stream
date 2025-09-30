# XStream

This repository implements composable stream primitives for PHP, including file, memory, temp, retry, buffered, tee, and now HTTP-backed streams. It also ships with PSR-7 interoperability helpers so any `StreamInterface` can be bridged to or from third-party HTTP clients in one line. You can even wrap existing PHP resources through `XStream::fromResource()` to keep using buffering or retry decorators. For practical recipes—such as downloading large files with retry + resume support via `XStream::http()`—see the [usage guide](docs/USAGE.md).

---

The following is a discussion with chatGPT, I need you to implement what it says. I decided to make a seperate project called "XStream", so this is the project. Now I need you to create the code, tests and docs:




about streams, how should I implement them?

<?php

namespace Orryv\Storage\Drivers;

use Orryv\Storage\Interfaces\StorageDriverInterface;
use Orryv\Storage\Objects\Capabilities;

class Local implements StorageDriverInterface
{
    private Capabilities $capabilities;


    public function __construct(string $base_path, string $public_base_path = null)
    {
        $this->capabilities = new Capabilities(
            can_read: true,
            can_write: true,
            can_delete: true,
            can_list: true,
            can_move: true,
            can_copy: true,
            can_stream: true,
            can_partial_read: true,
            can_partial_write: true,
            supports_resume: true,
        );
    }
}

a getWriteStream? getReadStream? or just getStream?
Or maybe it's a good idea to make a dedicated stream class, which can for example seek, get file pointer, continue from a certain point, handle errors when file handle is gone etc. ?


Great question. Streams are worth designing cleanly up-front so you can support partial reads/writes, resume, and consistent error handling across drivers.

Here’s a practical approach that scales:

# 1) Use a dedicated Stream abstraction

Either:

* adopt **PSR-7 `Psr\Http\Message\StreamInterface`** (nice because lots of libs already work with it), or
* define your own minimal `StorageStreamInterface` tailored to storage use.

### Minimal custom interface (if you don’t want PSR-7)

```php
namespace Orryv\Storage\Streams;

interface StorageStreamInterface
{
    public function read(int $length): string;       // throws on error
    public function write(string $data): int;        // returns bytes written
    public function seek(int $offset, int $whence = SEEK_SET): void;
    public function tell(): int;
    public function eof(): bool;
    public function getSize(): ?int;
    public function flush(): void;
    public function close(): void;

    /** Optional helpers */
    public function getMetadata(?string $key = null): mixed;
    public function detach();                        // returns underlying resource and detaches
}
```

Then each driver (Local, S3, etc.) returns an instance that implements this interface. Internally you can wrap an `SplFileObject` or a plain PHP stream resource.

# 2) Driver API: separate read/write (and a convenience open)

I’d expose **both** read and write openers so intent and capabilities are clear, plus a convenience `openStream()` when you need full control:

```php
interface StorageDriverInterface
{
    public function openReadStream(string $path, array $options = []): StorageStreamInterface;

    /**
     * Options: 'append' => bool, 'offset' => int, 'exclusive' => bool (locking),
     *          'atomic' => bool (temp file + rename), 'mode' => string, etc.
     */
    public function openWriteStream(string $path, array $options = []): StorageStreamInterface;

    /** Optional convenience if you prefer fopen-like modes ('r', 'rb', 'c+b', etc.) */
    public function openStream(string $path, string $mode, array $options = []): StorageStreamInterface;
}
```

Why not only `getStream()`? Because:

* Read and write permissions/capabilities differ per backend.
* You can enforce safe defaults (`rb` for reads, `c+b`/`wb` for writes) and validation per method.

# 3) Local driver: sensible defaults + resume

A straightforward Local implementation:

```php
namespace Orryv\Storage\Drivers;

use Orryv\Storage\Streams\StorageStreamInterface;

final class LocalStream implements StorageStreamInterface
{
    /** @var resource|null */
    private $handle;
    private ?int $sizeCache = null;

    public function __construct($handle)
    {
        if (!is_resource($handle)) {
            throw new \InvalidArgumentException('Invalid stream resource');
        }
        $this->handle = $handle;
    }

    public function read(int $length): string
    {
        $this->ensureOpen();
        $data = fread($this->handle, $length);
        if ($data === false) {
            throw new \RuntimeException('Failed to read from stream');
        }
        return $data;
    }

    public function write(string $data): int
    {
        $this->ensureOpen();
        $bytes = fwrite($this->handle, $data);
        if ($bytes === false) {
            throw new \RuntimeException('Failed to write to stream');
        }
        $this->sizeCache = null; // size changed
        return $bytes;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->ensureOpen();
        if (fseek($this->handle, $offset, $whence) !== 0) {
            throw new \RuntimeException('Failed to seek');
        }
    }

    public function tell(): int
    {
        $this->ensureOpen();
        $pos = ftell($this->handle);
        if ($pos === false) {
            throw new \RuntimeException('Failed to tell position');
        }
        return $pos;
    }

    public function eof(): bool   { $this->ensureOpen(); return feof($this->handle); }
    public function getSize(): ?int
    {
        if ($this->sizeCache !== null) return $this->sizeCache;
        $meta = $this->getMetadata();
        if (!isset($meta['uri'])) return null;
        $stat = @stat($meta['uri']);
        return $this->sizeCache = ($stat !== false ? $stat['size'] : null);
    }
    public function flush(): void  { $this->ensureOpen(); if (!fflush($this->handle)) throw new \RuntimeException('Failed to flush'); }
    public function close(): void  { if ($this->handle) { fclose($this->handle); $this->handle = null; } }
    public function getMetadata(?string $key = null): mixed
    { $this->ensureOpen(); $meta = stream_get_meta_data($this->handle); return $key ? ($meta[$key] ?? null) : $meta; }

    public function detach()
    { $h = $this->handle; $this->handle = null; return $h; }

    private function ensureOpen(): void
    { if (!$this->handle) throw new \RuntimeException('Stream is closed'); }
}
```

`Local` driver opening methods:

```php
final class Local implements StorageDriverInterface
{
    public function openReadStream(string $path, array $options = []): StorageStreamInterface
    {
        $mode = $options['mode'] ?? 'rb';
        $h = @fopen($this->abs($path), $mode);
        if (!$h) throw new \RuntimeException("Unable to open for read: {$path}");
        if (!empty($options['offset'])) {
            if (fseek($h, (int)$options['offset']) !== 0) {
                fclose($h);
                throw new \RuntimeException("Failed to seek to offset");
            }
        }
        if (!empty($options['shared_lock'])) {
            // non-blocking lock if requested
            @flock($h, LOCK_SH | LOCK_NB);
        }
        return new LocalStream($h);
    }

    public function openWriteStream(string $path, array $options = []): StorageStreamInterface
    {
        $append  = (bool)($options['append'] ?? false);
        $mode    = $options['mode'] ?? ($append ? 'ab' : 'c+b'); // 'c+' = create if not exist
        $tmp     = !empty($options['atomic']);                   // temp write then rename
        $target  = $this->abs($path);
        $opened  = $target;

        if ($tmp) {
            $dir = dirname($target);
            $opened = tempnam($dir, '.orrwv_tmp_');
        }

        $h = @fopen($opened, $mode);
        if (!$h) throw new \RuntimeException("Unable to open for write: {$path}");

        if (!empty($options['exclusive'])) {
            // exclusive lock for writers
            if (!@flock($h, LOCK_EX)) {
                fclose($h);
                throw new \RuntimeException("Unable to acquire lock for write: {$path}");
            }
        }

        if (isset($options['offset'])) {
            $offset = (int)$options['offset'];
            if (fseek($h, $offset) !== 0) {
                fclose($h);
                throw new \RuntimeException("Failed to seek to offset: {$offset}");
            }
        } elseif (!$append && ($options['truncate'] ?? false)) {
            ftruncate($h, 0);
        }

        $stream = new LocalStream($h);

        // If atomic, wrap the stream so close() renames the file
        if ($tmp) {
            $stream = new class($stream, $opened, $target) extends LocalStream {
                private string $tmp; private string $target; private bool $closed = false;
                public function __construct(LocalStream $inner, string $tmp, string $target) {
                    $this->tmp = $tmp; $this->target = $target;
                    parent::__construct($inner->detach());
                }
                public function close(): void {
                    if ($this->closed) return;
                    parent::flush();
                    parent::close();
                    if (!@rename($this->tmp, $this->target)) {
                        @unlink($this->tmp);
                        throw new \RuntimeException("Atomic rename failed");
                    }
                    $this->closed = true;
                }
            };
        }

        return $stream;
    }

    private function abs(string $path): string
    {
        // map logical storage path to absolute filesystem path
        // enforce base path, validate traversal, etc.
        return $path; // placeholder
    }
}
```

# 4) How this covers your capability flags

* `can_stream`: `openReadStream`/`openWriteStream`
* `can_partial_read`: `openReadStream(... ['offset' => N])` + `seek()`
* `can_partial_write`: `openWriteStream(... ['offset' => N])` + `seek()`
* `supports_resume`: caller can reopen a write stream with `offset` at last known byte and continue

# 5) Error handling & robustness tips

* **Fail fast with exceptions** on open/read/write/seek errors.
* **Locking**: use `flock()` for exclusive writes and optional shared locks for readers.
* **Atomic writes**: temp file + `rename()` to avoid readers seeing partial files.
* **Validate paths** against your `$base_path`; prevent `..` traversal.
* **Normalize modes** (`rb`, `wb`, `c+b`, `ab`) and enforce binary (important on Windows).
* **Close/detach semantics**: make sure `close()` is idempotent, and allow `detach()` if callers need raw `resource`.
* **Metadata**: expose URI/stream meta for debugging (useful across drivers).
* **Time-outs**: for networked drivers (S3, FTP), respect context options (`stream_context_create`) and surface timeouts clearly.

# 6) Naming: what should you call them?

* Prefer **`openReadStream()` / `openWriteStream()`** (clear intent, maps to capabilities).
* Optionally add **`openStream($path, $mode)`** as a low-level escape hatch.
* Avoid a single ambiguous `getStream()`—it hides intent and leads to mode mistakes.

# 7) If you’d rather not invent your own stream type

Use **PSR-7**:

* Return `Psr\Http\Message\StreamInterface` (e.g., from `GuzzleHttp\Psr7\Utils::streamFor($resource)`).
* You still keep the same driver method signatures; only the return type changes.
* Bonus: easy interop with HTTP upload/download code.

---

If you want, I can adapt the above to your exact `StorageDriverInterface` and wire in your `$base_path` normalization + tests.



I think I created something in the past which could come in handy. If I remember correctly it also tries to reload the filepointer when it's gone?

<?php

namespace Orryv\Utils;

/**
 * @TODO: option to turn off auto reopen file pointer if file handler is closed (by OS or other reasons)
 */
class FilePointerX
{
    private string $filename;
    private string $mode;
    private bool $open_on_use;
    private int $retries;
    private int $retry_delay_ms;
    private bool $use_include_path;
    private $context;
    private $file_pointer;
    private bool $is_manually_closed = false;
    private int $current_position = 0;

    public function __construct(
        string $filename, 
        string $mode, 
        bool $open_on_use = true, 
        int $retries = 3,
        int $retry_delay_ms = 1,
        bool $use_include_path = false, 
        $context = null
    ){
        $this->filename = $filename;
        $this->mode = $mode;
        $this->open_on_use = $open_on_use;
        $this->retries = $retries;
        $this->retry_delay_ms = $retry_delay_ms;
        $this->use_include_path = $use_include_path;
        $this->context = $context;

        if($this->open_on_use === false){
            $this->open();
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function open(): self
    {
        if($this->file_pointer !== null && $this->file_pointer !== false){
            throw new \Exception('File pointer is already opened');
        }

        $this->is_manually_closed = false;

        $retries = 0;
        do{
            $this->file_pointer = fopen($this->filename, $this->mode, $this->use_include_path, $this->context);
            if($retries < $this->retries && $this->file_pointer === false){
                usleep($this->retry_delay_ms * 1000);
            }
            $retries++;
        } while($this->file_pointer === false && $retries < $this->retries);

        if($this->file_pointer === false){
            throw new \Exception('Could not open file: ' . $this->filename);
        }

        return $this;
    }

    public function reopen(): self
    {
        $this->close();
        $this->open();

        // Restore position after reopening
        if ($this->current_position > 0) {
            fseek($this->file_pointer, $this->current_position);
        }

        return $this;
    }

    public function close(): self
    {
        if($this->is_manually_closed === false && ($this->file_pointer !== null && $this->file_pointer !== false)){
            fclose($this->file_pointer);
        }

        $this->is_manually_closed = true;
        $this->file_pointer = null;

        return $this;
    }

    public function read(int $length): string
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed by fclose() or __destruct()');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(fn() => fread($this->file_pointer, $length));
    }

    public function readAll(): string
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(fn() => stream_get_contents($this->file_pointer));
    }

    public function readLines(): \Generator
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        while (!$this->executeWithErrorHandling(fn() => feof($this->file_pointer))) {
            $line = $this->executeWithErrorHandling(fn() => fgets($this->file_pointer));
            if ($line !== false) {
                yield $line;
            }
        }
    }

    public function readChunks(int $chunkSize): \Generator
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        while (!$this->executeWithErrorHandling(fn() => feof($this->file_pointer))) {
            $chunk = $this->executeWithErrorHandling(fn() => fread($this->file_pointer, $chunkSize));
            if ($chunk !== '') {
                yield $chunk;
            }
        }
    }

    public function readUntil(string $ending, int $length = null): string|false
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(fn() => stream_get_line($this->file_pointer, $length ?? PHP_INT_MAX, $ending));
    }

    public function write(string $string): int
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed by fclose() or __destruct()');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(fn() => fwrite($this->file_pointer, $string));
    }

    public function seek(int $offset, int $whence = SEEK_SET): self
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed by fclose() or __destruct()');
        }

        if($this->open_on_use){
            $this->open();
        }

        $this->executeWithErrorHandling(fn() => fseek($this->file_pointer, $offset, $whence));

        // Update current_position based on seek type
        switch ($whence) {
            case SEEK_SET:
                $this->current_position = $offset;
                break;
            case SEEK_CUR:
                $this->current_position += $offset;
                break;
            case SEEK_END:
                $stats = fstat($this->file_pointer);
                $this->current_position = $stats['size'] + $offset;
                break;
        }

        return $this;
    }

    public function tell(): int
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed by fclose() or __destruct()');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(fn() => ftell($this->file_pointer));
    }

    public function eof(): bool
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed by fclose() or __destruct()');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(fn() => feof($this->file_pointer));
    }

    public function flush(): self
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed by fclose() or __destruct()');
        }

        if($this->open_on_use){
            $this->open();
        }

        $this->executeWithErrorHandling(fn() => fflush($this->file_pointer));

        return $this;
    }

    public function lock(int $operation): bool
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed by fclose() or __destruct()');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(fn() => flock($this->file_pointer, $operation));
    }

    public function passthru(): int
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed by fclose() or __destruct()');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(fn() => fpassthru($this->file_pointer));
    }

    public function rewind(): self
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        $this->executeWithErrorHandling(fn() => rewind($this->file_pointer));
        $this->current_position = 0;
        
        return $this;
    }

    public function readLine(int $length = null): string|false
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(
            fn() => $length === null ? fgets($this->file_pointer) : fgets($this->file_pointer, $length)
        );
    }

    public function readCsv(int $length = 0, string $delimiter = ',', string $enclosure = '"', string $escape = '\\'): array|false
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(
            fn() => fgetcsv($this->file_pointer, $length, $delimiter, $enclosure, $escape)
        );
    }

    public function writeCsv(array $fields, string $delimiter = ',', string $enclosure = '"', string $escape = '\\'): int|false
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(
            fn() => fputcsv($this->file_pointer, $fields, $delimiter, $enclosure, $escape)
        );
    }

    public function stat(): array|false
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandlingNoPosition(fn() => fstat($this->file_pointer));
    }

    public function setReadBuffer(int $buffer): int
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandlingNoPosition(
            fn() => stream_set_read_buffer($this->file_pointer, $buffer)
        );
    }

    public function setWriteBuffer(int $buffer): int
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandlingNoPosition(
            fn() => stream_set_write_buffer($this->file_pointer, $buffer)
        );
    }

    public function setChunkSize(int $size): int
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandlingNoPosition(
            fn() => stream_set_chunk_size($this->file_pointer, $size)
        );
    }

    public function setBlocking(bool $enable): bool
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandlingNoPosition(
            fn() => stream_set_blocking($this->file_pointer, $enable)
        );
    }

    public function setTimeout(int $seconds): bool
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandlingNoPosition(
            fn() => stream_set_timeout($this->file_pointer, $seconds)
        );
    }

    public function readChar(): string|false
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(fn() => fgetc($this->file_pointer));
    }

    public function truncate(int $size): bool
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(fn() => ftruncate($this->file_pointer, $size));
    }

    public function isOpen(): bool
    {
        return $this->file_pointer !== null && $this->file_pointer !== false && !$this->is_manually_closed;
    }

    public function getMetadata(?string $key = null): mixed
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        $meta = $this->executeWithErrorHandlingNoPosition(fn() => stream_get_meta_data($this->file_pointer));
        return $key === null ? $meta : ($meta[$key] ?? null);
    }

    public function copyTo(FilePointerX $destination, ?int $maxLength = null, ?int $offset = 0): int|false
    {
        if($this->is_manually_closed){
            throw new \Exception('File pointer is already closed');
        }

        if($this->open_on_use){
            $this->open();
        }

        return $this->executeWithErrorHandling(
            fn() => stream_copy_to_stream($this->file_pointer, $destination->file_pointer, $maxLength, $offset)
        );
    }

    private function executeWithErrorHandling(callable $operation)
    {
        $previousHandler = set_error_handler(function($severity, $message) {
            throw new \ErrorException($message);
        });

        try {
            $result = $operation();
            // Update position after successful operation
            $this->current_position = ftell($this->file_pointer);
            return $result;
        } catch (\ErrorException $e) {
            $this->reopen();
            return $operation();
        } finally {
            if ($previousHandler) {
                set_error_handler($previousHandler);
            } else {
                restore_error_handler();
            }
        }
    }

    private function executeWithErrorHandlingNoPosition(callable $operation)
    {
        $previousHandler = set_error_handler(function($severity, $message) {
            throw new \ErrorException($message);
        });

        try {
            $result = $operation();
            return $result;
        } catch (\ErrorException $e) {
            $this->reopen();
            return $operation();
        } finally {
            if ($previousHandler) {
                set_error_handler($previousHandler);
            } else {
                restore_error_handler();
            }
        }
    }
}



Ohhh this is nice—you basically wrote a resilient stream wrapper with auto-reopen + position restore. That maps perfectly to your storage driver needs. A few tweaks will make it rock-solid and easy to plug in.

Here’s what I’d do:

# Make it your stream type

Have `FilePointerX` implement your storage stream interface (or PSR-7’s `StreamInterface`). You already have almost all the methods.

```php
final class FilePointerX implements \Orryv\Storage\Streams\StorageStreamInterface
{
    // ...
}
```

# Key improvements I strongly recommend

1. **Reapply stream options after reopen**
   Right now `setBlocking/setTimeout/...` won’t persist across a reopen. Cache them and reapply:

```php
private array $opts = [
    'read_buffer' => null,
    'write_buffer' => null,
    'chunk_size' => null,
    'blocking' => null,
    'timeout' => null, // seconds
];

private function applyOptions(): void {
    if ($this->opts['read_buffer'] !== null) { stream_set_read_buffer($this->file_pointer, $this->opts['read_buffer']); }
    if ($this->opts['write_buffer'] !== null) { stream_set_write_buffer($this->file_pointer, $this->opts['write_buffer']); }
    if ($this->opts['chunk_size'] !== null) { stream_set_chunk_size($this->file_pointer, $this->opts['chunk_size']); }
    if ($this->opts['blocking'] !== null) { stream_set_blocking($this->file_pointer, $this->opts['blocking']); }
    if ($this->opts['timeout'] !== null) { stream_set_timeout($this->file_pointer, $this->opts['timeout']); }
}

public function open(): self {
    // ... your code ...
    if ($this->file_pointer === false) { throw new \RuntimeException("Could not open file: {$this->filename}"); }
    $this->applyOptions();
    return $this;
}

// override setters to cache:
public function setBlocking(bool $enable): bool {
    $this->opts['blocking'] = $enable;
    // ... call parent logic ...
}
```

2. **Option to disable auto-reopen**
   You noted this in the TODO. Add a flag (e.g., `$auto_reopen = true`) and only retry when enabled. Drivers for local FS can enable it; remote drivers may not want transparent retries.

3. **Safer error handler**
   Your `set_error_handler` converts *all* notices/warnings. That’s fine, but scope it to respect `error_reporting()`:

```php
$previousHandler = set_error_handler(function(int $severity, string $message) {
    if (!(error_reporting() & $severity)) return false; // let PHP handle it
    throw new \ErrorException($message, 0, $severity);
});
```

4. **Position restore edge cases**

* If the file **shrunk** before reopen, seeking to the old position will fail. Clamp to EOF.
* In **append** modes (`a`/`a+`), POSIX semantics ignore `fseek` for writes; don’t attempt to restore write position in that case.

```php
private function restorePosition(): void {
    // append modes: always at end
    if (str_contains($this->mode, 'a')) return;
    $stats = @fstat($this->file_pointer);
    $size  = $stats !== false ? ($stats['size'] ?? null) : null;
    $target = $this->current_position;
    if ($size !== null) { $target = min($target, $size); }
    if (fseek($this->file_pointer, $target) !== 0) {
        // best effort: go to end if seek fails
        @fseek($this->file_pointer, 0, SEEK_END);
        $this->current_position = ftell($this->file_pointer) ?: 0;
    }
}

public function reopen(): self {
    $this->close();
    $this->open();
    $this->restorePosition();
    return $this;
}
```

5. **Ensure destination is open in `copyTo`**
   `stream_copy_to_stream` needs a valid destination handle. If the destination was created with `open_on_use=true`, it may still be closed.

```php
public function copyTo(FilePointerX $destination, ?int $maxLength = null, ?int $offset = 0): int|false
{
    if ($this->open_on_use) $this->open();
    if ($destination->open_on_use) $destination->open();
    // ...
}
```

6. **Return/throw types**
   Use more specific exceptions (`LogicException` when opening an already open handle; `RuntimeException` for IO). Also consider `#[\ReturnTypeWillChange]` is unnecessary; you’re on PHP 8+.

7. **Idempotent open/close**
   `open()` currently throws if already open; making it idempotent simplifies “open on use” call chains:

```php
public function open(): self
{
    if ($this->isOpen()) return $this;
    // ... proceed to open ...
}
```

8. **Binary mode by default**
   When you surface this from the driver, default to `'rb'` / `'wb'` to avoid CRLF translation on Windows.

9. **Expose underlying resource (detach)**
   Handy for interoperability:

```php
/** @return resource|null */
public function detach()
{
    $h = $this->file_pointer;
    $this->file_pointer = null;
    $this->is_manually_closed = true;
    return $h;
}
```

# Wiring it into your driver

Expose explicit openers and return `FilePointerX`:

```php
interface StorageDriverInterface
{
    public function openReadStream(string $path, array $options = []): FilePointerX;
    public function openWriteStream(string $path, array $options = []): FilePointerX;
}

final class Local implements StorageDriverInterface
{
    public function openReadStream(string $path, array $options = []): FilePointerX
    {
        $abs = $this->abs($path);
        $ctx = $options['context'] ?? null;
        $fp  = new \Orryv\Utils\FilePointerX($abs, $options['mode'] ?? 'rb', open_on_use: false, retries: 3, retry_delay_ms: 2, context: $ctx);
        if (isset($options['offset'])) { $fp->seek((int)$options['offset']); }
        if (!empty($options['shared_lock'])) { $fp->lock(LOCK_SH); }
        return $fp;
    }

    public function openWriteStream(string $path, array $options = []): FilePointerX
    {
        $abs = $this->abs($path);
        $append = (bool)($options['append'] ?? false);
        $mode   = $options['mode'] ?? ($append ? 'ab' : 'c+b');
        $fp = new \Orryv\Utils\FilePointerX($abs, $mode, open_on_use: false, retries: 3, retry_delay_ms: 2, context: $options['context'] ?? null);
        if (!empty($options['exclusive'])) { $fp->lock(LOCK_EX); }
        if (isset($options['offset']) && !$append) { $fp->seek((int)$options['offset']); }
        return $fp;
    }

    private function abs(string $path): string { /* sanitize + join with base path */ }
}
```

# Small correctness nits in your current code

* `executeWithErrorHandling`: after reopen, you **don’t** reapply previously set stream options; fixed by (1).
* `readLines/readChunks`: check for `false` from `fgets/fread` and stop; you yield empty strings correctly, but for `false` break to avoid an infinite loop.
* `setTimeout(int $seconds)`: consider exposing microseconds as optional param (PHP has a 2nd param).
* `stat()`/`getMetadata()` paths can throw if stream got closed between handler swap and call—your reopen covers it, but keep it in mind.

---

**Bottom line:** This is a great base. Make it your canonical stream class, add option persistence + safer position restore, and expose it via `openReadStream/openWriteStream`. You’ll get resilient partial reads/writes and resume semantics across all drivers with a single implementation.



Maybe I have a better idea. I already have an x-array for array operations, an x-string for string operations. So I could make an x-stream for this?
But what do I include? 
XStream + XFilePointer + ?
But a filepointer is a stream so that might be confusing?



Yep—make an `XStream`. The trick is to separate **capabilities** (read/write/seek) from **implementations** (file/memory/network) and from **behaviors** (buffering, retry, hashing…). That way nothing is confusing, and “file pointer” is just one *kind* of stream.

Here’s a clean, extensible layout:

# Interfaces (tiny, composable)

```php
namespace Orryv\XStream;

interface StreamInterface
{
    public function close(): void;
    public function getSize(): ?int;
    public function tell(): int;
    public function eof(): bool;
    public function getMetadata(?string $key = null): mixed;
}

interface ReadableStreamInterface extends StreamInterface
{
    public function read(int $length): string;  // throws on error
}

interface WritableStreamInterface extends StreamInterface
{
    public function write(string $data): int;   // bytes
    public function flush(): void;
}

interface SeekableStreamInterface extends StreamInterface
{
    public function seek(int $offset, int $whence = SEEK_SET): void;
}
```

Now you can type against `ReadableStreamInterface|WritableStreamInterface|SeekableStreamInterface` depending on what you need.

# Concrete implementations (“sources/sinks”)

* `FileStream` (wraps PHP resource; your `FilePointerX` logic goes here)
* `MemoryStream` (string buffer; useful for tests/compose)
* `TempStream` (php://temp)
* `NullStream` (accepts writes, discards; reads EOF)
* (later) `NetworkStream` / `HttpStream` if needed

Name them by what they *are*, not how PHP labels them. Avoid “pointer”; use **`FileStream`** instead of `XFilePointer`.

```php
final class FileStream implements ReadableStreamInterface, WritableStreamInterface, SeekableStreamInterface
{
    // adapt your FilePointerX here (open/reopen, options cache, etc.)
}
```

# Behaviors as decorators (optional wrappers)

Keep cross-cutting features out of `FileStream` and add them by wrapping:

* `RetryStream` (auto-reopen/position-restore) ← your resilience logic lives here
* `BufferedStream` (bigger userland buffer over small reads)
* `HashingStream` (updates hash on read/write)
* `RateLimitedStream` (throttle)
* `TeeStream` (mirror reads/writes to another stream)
* `CountingStream` (expose bytes transferred)

All implement the same interfaces and hold an inner stream:

```php
final class RetryStream implements ReadableStreamInterface, WritableStreamInterface, SeekableStreamInterface
{
    public function __construct(
        private StreamInterface $inner,
        private int $retries = 3,
        private int $delayMs = 2,
        private bool $restorePosition = true
    ) {}

    // delegate read/write/seek and on failure reopen+retry if $inner is a FileStream
}
```

# Factory façade (what you call “XStream”)

Provide ergonomic constructors so call sites stay tiny:

```php
final class XStream
{
    public static function file(
        string $path, string $mode = 'rb',
        bool $retry = true, int $retries = 3, int $delayMs = 2
    ): StreamInterface {
        $base = new FileStream($path, $mode /*, context, include_path etc. */);
        return $retry ? new RetryStream($base, $retries, $delayMs) : $base;
    }

    public static function memory(string $initial = ''): StreamInterface
    {
        return new MemoryStream($initial);
    }

    public static function temp(int $limitBytes = 2_000_000): StreamInterface
    {
        return new TempStream($limitBytes);
    }
}
```

# How this maps to your storage drivers

Expose intent-clear methods and return the appropriate interfaces:

```php
interface StorageDriverInterface
{
    public function openReadStream(string $path, array $options = []): ReadableStreamInterface;
    public function openWriteStream(string $path, array $options = []): WritableStreamInterface;
    public function openStream(string $path, string $mode, array $options = []): StreamInterface;
}

final class Local implements StorageDriverInterface
{
    public function openReadStream(string $path, array $options = []): ReadableStreamInterface
    {
        $s = XStream::file($this->abs($path), $options['mode'] ?? 'rb', retry:true);
        if (isset($options['offset']) && $s instanceof SeekableStreamInterface) {
            $s->seek((int)$options['offset']);
        }
        return $s;
    }

    public function openWriteStream(string $path, array $options = []): WritableStreamInterface
    {
        $mode = $options['append'] ?? false ? 'ab' : ($options['mode'] ?? 'c+b');
        $s = XStream::file($this->abs($path), $mode, retry:true);
        if (isset($options['offset']) && $s instanceof SeekableStreamInterface && $mode[0] !== 'a') {
            $s->seek((int)$options['offset']);
        }
        return $s;
    }
}
```

# Naming summary (to avoid confusion)

* **XStream** → the *factory/facade* (not the stream itself).
* **FileStream / MemoryStream / TempStream** → concrete stream *types*.
* **RetryStream / BufferedStream / TeeStream** → *decorators* that add behavior.
* Avoid “FilePointer” in names; PHP internals use it, but your API should talk in streams.

# Bonus

If you want interop, you can also provide adapters to/from **PSR-7 `StreamInterface`**:

* `Psr7StreamAdapter` (wraps your stream for PSR-7 consumers)
* `FromPsr7Stream` (wraps PSR-7 in your interfaces)

This gives you clean naming, minimal surface area, and tons of flexibility. Want me to turn your `FilePointerX` into a `FileStream` + `RetryStream` pair with the option-persistence and safer seek logic baked in?




Show me how we would use the code (not the classes etc. itself, but the code that calls the classes.

Examples for XStream / FileStream / MemoryStream / TempStream / RetryStream / BufferedStream / TeeStream

Also we should have better error handling in the FilePointerX I provided, I need to refactor it, give me some ideas.



awesome — here are *only* usage-side snippets to show how you’d call the streams, plus a checklist of refactor ideas for your `FilePointerX` error handling.

---

# Using the streams (caller-side)

## 1) XStream factory (ergonomic)

```php
use Orryv\XStream\XStream;
use Orryv\XStream\ReadableStreamInterface;
use Orryv\XStream\WritableStreamInterface;

try {
    // Read whole file
    $in = XStream::file('/var/data/report.csv', 'rb');     // returns a StreamInterface (Seekable+Readable)
    $content = '';
    while (!$in->eof()) {
        $content .= $in->read(8192);
    }
    $in->close();

    // Write new file (atomic write via temp then rename… if your FileStream implements it)
    $out = XStream::file('/var/data/output.bin', 'c+b');
    $out->write($content);
    $out->flush();
    $out->close();

} catch (\Throwable $e) {
    // central error reporting
    error_log("Stream failure: " . $e->getMessage());
}
```

## 2) FileStream (direct)

```php
use Orryv\XStream\FileStream;

$fs = new FileStream('/var/log/app.log', 'rb');
$fs->seek(-1024, SEEK_END);        // last KB
echo $fs->read(1024);
$fs->close();
```

## 3) MemoryStream

```php
use Orryv\XStream\MemoryStream;

// Build a payload in memory, then read it back
$mem = new MemoryStream();
$mem->write("Hello ");
$mem->write("world!");
$mem->seek(0);
echo $mem->read(5);        // "Hello"
$mem->close();
```

## 4) TempStream (php://temp style)

```php
use Orryv\XStream\TempStream;

// Holds up to N bytes in memory, then spills to temp file
$tmp = new TempStream(limitBytes: 2_000_000);
for ($i = 0; $i < 100000; $i++) {
    $tmp->write(random_bytes(16));
}
$tmp->seek(0);
$firstChunk = $tmp->read(4096);
// … process …
$tmp->close(); // removes the underlying temp file
```

## 5) RetryStream (decorator)

```php
use Orryv\XStream\FileStream;
use Orryv\XStream\RetryStream;

// Auto-reopen on transient failures and restore position
$base  = new FileStream('/mnt/nfs/video.mp4', 'rb'); // network FS might drop handles
$retry = new RetryStream($base, retries: 5, delayMs: 10);

$retry->seek(10_000_000);     // resume at byte 10 MB
$buf = $retry->read(1_048_576); // read 1 MB, auto-retry if needed
$retry->close();
```

## 6) BufferedStream (decorator)

```php
use Orryv\XStream\FileStream;
use Orryv\XStream\BufferedStream;

// Wrap a small-chunk reader with a larger userland buffer (e.g., for many small reads)
$base = new FileStream('/var/data/huge.ndjson', 'rb');
$buf  = new BufferedStream($base, bufferSize: 256 * 1024);

while (!$buf->eof()) {
    $line = '';
    // naive read-until-newline using small reads, served from big buffer
    while (!$buf->eof()) {
        $chunk = $buf->read(128);
        $line .= $chunk;
        if (str_contains($chunk, "\n")) break;
    }
    // … handle $line …
}
$buf->close();
```

## 7) TeeStream (decorator)

```php
use Orryv\XStream\{FileStream, TeeStream};

// Mirror writes to two destinations (file + log), or mirror reads to a sink
$destA = new FileStream('/var/data/upload.bin', 'c+b');
$destB = new FileStream('/var/data/upload.mirror.bin', 'c+b');
$tee   = new TeeStream($destA, $destB);   // write once → goes to both

$source = new FileStream('/tmp/source.bin', 'rb');
while (!$source->eof()) {
    $tee->write($source->read(8192));
}
$tee->flush();
$tee->close();
$source->close();
```

## 8) Combining them (File → Buffered → Retry)

```php
use Orryv\XStream\{FileStream, BufferedStream, RetryStream};

$stream = new RetryStream(
    new BufferedStream(
        new FileStream('/mnt/nfs/bigfile.dat', 'rb'),
        bufferSize: 512 * 1024
    ),
    retries: 4,
    delayMs: 25
);

// Consume
$total = 0;
while (!$stream->eof()) {
    $total += strlen($stream->read(1_048_576));
}
$stream->close();
```

## 9) Driver usage (Local)

```php
use Orryv\Storage\Drivers\Local;

// read
$local = new Local(base_path: '/srv/storage');
$in = $local->openReadStream('reports/2025-09-30.csv', ['offset' => 1024]);
$data = $in->read(4096);
$in->close();

// write with resume
$out = $local->openWriteStream('uploads/video.mp4', ['mode' => 'c+b']);
$out->seek(filesize('/srv/storage/uploads/video.mp4')); // resume
foreach ($chunks as $chunk) {
    $out->write($chunk);
}
$out->flush();
$out->close();
```

## 10) Copy file-to-file, safely

```php
use Orryv\XStream\{XStream};

$src = XStream::file('/srv/a/big.bin', 'rb');
$dst = XStream::file('/srv/b/big.bin.part', 'c+b');
// manual 8MB copy loop (gives you progress & cancel)
$copied = 0;
while (!$src->eof()) {
    $chunk = $src->read(8 * 1024 * 1024);
    if ($chunk === '') break;
    $dst->write($chunk);
    $copied += strlen($chunk);
    // … report progress $copied …
}
$dst->flush();
$dst->close();
// optionally rename .part → final
$src->close();
```

---

# Refactor ideas for `FilePointerX` (better error handling & resilience)

Here’s a punchy checklist. You don’t need to do all of it at once—start with the exception types + retry policy.

## A. Exception model

Create a tiny hierarchy so callers can react precisely:

```php
namespace Orryv\XStream\Exception;

class StreamException extends \RuntimeException {}
class AlreadyOpen extends StreamException {}
class AlreadyClosed extends StreamException {}
class OpenFailed extends StreamException {}
class ReadFailed extends StreamException {}
class WriteFailed extends StreamException {}
class SeekFailed extends StreamException {}
class FlushFailed extends StreamException {}
class LockFailed extends StreamException {}
class RetryExhausted extends StreamException {}
```

Use them instead of `\Exception`. E.g., throw `OpenFailed` when `fopen` fails, `RetryExhausted` when all retries are used.

## B. Retry policy (smarter & safer)

* **Exponential backoff with jitter** to avoid thundering herds:

  * delay = `min(base * 2^attempt + rand(0, jitter), maxDelay)`
* **Retry only on transient errors**: maintain a whitelist of error messages/errno (e.g., “Resource temporarily unavailable”, “Connection reset by peer”, EMFILE? probably *don’t* retry).
* **Cap total time** (deadline). If exceeded → `RetryExhausted`.
* **Max bytes to reattempt:** for idempotency on writes, only auto-retry if you can seek back safely (not in append mode).

## C. Error handler scoping

Your `set_error_handler` is good, but:

* Respect `error_reporting()` (ignore silenced severities).
* Always restore previous handler even if an exception bubbles.
* Wrap only the *single* risky call, not a wide scope, to reduce side-effects.

```php
private function withErrorToException(callable $fn) {
    $prev = set_error_handler(function($severity, $message) {
        if (!(error_reporting() & $severity)) return false;
        throw new \ErrorException($message, 0, $severity);
    });
    try { return $fn(); }
    finally {
        // restore exactly previous handler
        set_error_handler($prev);
    }
}
```

*(NB: `restore_error_handler()` pops one level, but passing the previous handler back to `set_error_handler()` is explicit and avoids nesting mistakes.)*

## D. Idempotent lifecycle

* `open()` returns early if already open (no exception).
* `close()` is idempotent (ok to call many times).
* `reopen()` is `close(); open(); restorePosition(); reapplyOptions();`

## E. Persist & reapply options on reopen

Cache values set by `setBlocking`, `setTimeout`, `set_read/write_buffer`, `chunk_size`, and reapply after every open/reopen.

## F. Position restore: robust

* **Append mode:** don’t restore (POSIX: writes go to end regardless).
* **Shrinkage-aware:** clamp to `min(current_position, filesize)`.
* If `fseek` fails, try `SEEK_END`; if that fails, throw `SeekFailed`.

## G. Safer writes & partials

* After `fwrite`, if returned bytes `< strlen($data)`, continue writing the remainder (loop) unless non-blocking and caller opted out.
* On failure mid-write:

  * if stream is seekable and not append → seek back to the start of the *unwritten* portion after reopen, then continue.
  * if not seekable → surface `WriteFailed` (don’t silently corrupt).

## H. Copy implementation

Prefer manual copy loop over `stream_copy_to_stream` so you can:

* expose progress,
* handle partial writes,
* respect cancellation/deadlines,
* apply retry logic per read/write chunk.

## I. Locking ergonomics

Provide a small RAII helper so locks are always released:

```php
final class LockGuard {
    public function __construct(private $fp, private int $op) {
        if (!flock($fp, $op)) throw new LockFailed('…');
    }
    public function __destruct() { @flock($this->fp, LOCK_UN); }
}
// usage:
$lock = new LockGuard($this->file_pointer, LOCK_EX);
// … write …
```

## J. Context & mode parsing

* Parse `$mode` into flags (`readable`, `writable`, `append`, `truncate`, `create`) once; reject illegal combos early.
* Validate context type; allow `stream_context_create(array)` pass-through.

## K. Close-on-destruct policy

* Keep `__destruct()` conservative: catch and log errors; *don’t throw* from destructors.
* Consider a `closeOnDestruct` flag if you ever want to detach without closing.

## L. Diagnostics & observability

* Add `__debugInfo()` to print filename, mode, position, blocking, timeout.
* Optional logger callback hook: `onEvent($event, $data)` for open/close/retry/seek failures.

## M. Immutability for config

* Store constructor options as read-only, and add `withXyz()` clone methods to produce modified instances (safer for concurrency).

## N. API polish

* `readLines()` / `readChunks()` generators should use `try { … } finally { /* optional close */ }` if you want them to auto-close at end.
* Add `detach()` to hand the raw resource to third-party code (also mark stream closed).

---

If you want, I can draft a lean `FileStream` + `RetryStream` that directly refactors your `FilePointerX` into this pattern (with the smarter retry/position/option persistence baked in) and keep method names close so it’s a low-effort drop-in.




tell me more about teeStream?
can't we mirror streams to multiple (more than 2) locations at once?



totally—you can (and usually should) tee to **N** outputs, not just two. Think of “tee” as a **write-fanout** (and sometimes a read-tap) decorator:

* **Write-tee**: you write once → it writes the same bytes to many sinks.
* **Read-tee**: you read from a source → it *also* copies the bytes you just read into one or more sinks (e.g., logging/archiving).

Below is a practical, usage-first guide plus an implementation sketch you can drop in.

---

# When to use a Tee

* **Audit/logging**: save the upload to disk *and* append to an audit log.
* **Redundant storage**: write to two local disks + a remote target.
* **Live metrics**: count or hash data on the fly while passing it through.

---

# Recommended shape

Have **two separate decorators**:

* `TeeWriter` → implements `WritableStreamInterface`, takes **N sinks** (Writable).
* `TeeReader` → implements `ReadableStreamInterface` (wraps a Readable) and mirrors reads into **N sinks**.

Both accept policies so you control behavior when one sink is slow or fails.

### Failure / consistency policies (pick one)

* `fail_fast` (all-or-nothing): if any sink fails/short-writes → throw, nothing is silently lost.
* `best_effort`: write as much as possible to each sink; collect/report errors (don’t throw by default).
* `quorum(k)`: require ≥ k sinks to succeed; otherwise throw.

### Other knobs

* `chunkSize` (default 64–256 KB): split large writes so partial-write retries are manageable.
* `flush_on_write` (bool): call `flush()` after each write (safer but slower).
* `close_policy`: `close_all` (default) or `leave_open` (caller owns sinks).
* `onEvent(callable)` hook for logging (`open`, `write_ok`, `write_err`, `flush_err`, etc.).

---

# Usage examples

### 1) Write once → 3 destinations

```php
use Orryv\XStream\{FileStream, RetryStream, TeeWriter};

// Make resilient sinks
$primary = new RetryStream(new FileStream('/data/out/main.bin', 'c+b'));
$mirror1 = new RetryStream(new FileStream('/data/out/mirror1.bin', 'c+b'));
$mirror2 = new RetryStream(new FileStream('/backup/out/mirror2.bin', 'c+b'));

// Fan out writes to all three
$tee = new TeeWriter([$primary, $mirror1, $mirror2], policy: 'fail_fast');

$src = new FileStream('/tmp/in.bin', 'rb');
while (!$src->eof()) {
    $tee->write($src->read(1024 * 1024)); // 1MB chunks
}
$tee->flush();
$tee->close();   // closes all sinks (close_policy default)
$src->close();
```

### 2) Read from one → tee reads into a side log

```php
use Orryv\XStream\{FileStream, TeeReader, FileStream as SinkFile};

$src = new FileStream('/var/log/app.log', 'rb');
// log file reads to an audit trail
$audit = new SinkFile('/var/log/app.log.read.audit', 'ab');

$tap = new TeeReader($src, [$audit]); // data you read also goes to $audit

while (!$tap->eof()) {
    $chunk = $tap->read(8192);
    // ... parse the log chunk ...
}
$tap->close();   // closes $src; leaves audit open by default
$audit->close();
```

### 3) Best-effort tee (don’t fail the request if a mirror goes down)

```php
$tee = new TeeWriter([$primary, $mirror1, $mirror2], policy: 'best_effort', onEvent: function($ev, $ctx) {
    if ($ev === 'write_err') {
        error_log("Tee sink failed: {$ctx['index']} {$ctx['error']}");
    }
});
```

### 4) Quorum tee (require at least 2/3 sinks succeed)

```php
$tee = new TeeWriter([$primary, $mirror1, $mirror2], policy: ['quorum' => 2]);
```

### 5) Buffering to avoid back-pressure from a slow sink

If one sink is slow, the tee will be as slow as the **slowest** sink. Two strategies:

* **Userland buffering**: wrap slow sinks in `BufferedStream` with a large buffer.
* **Stage to temp, then fan-out**: write once to a `TempStream`, then copy to each sink in separate steps (most predictable in PHP’s synchronous model).

```php
$staged = new TempStream(limitBytes: 50_000_000); // 50MB in RAM then spill to disk

// stage write
while (!$src->eof()) {
    $staged->write($src->read(1<<20));
}
$staged->seek(0);

// then copy staged → each sink with RetryStream, independently
foreach ([$primary, $mirror1, $mirror2] as $sink) {
    $staged->seek(0);
    while (!$staged->eof()) {
        $sink->write($staged->read(1<<20));
    }
    $sink->flush();
}
$staged->close();
```

---

# Implementation sketch (supports **N** sinks)

Below is a compact version that handles:

* partial writes per sink,
* `fail_fast` / `best_effort` / `quorum(k)`,
* flushing/closing policy.

```php
namespace Orryv\XStream;

final class TeeWriter implements WritableStreamInterface
{
    /** @var WritableStreamInterface[] */
    private array $sinks;
    private string $policyType;   // 'fail_fast' | 'best_effort' | 'quorum'
    private int $quorumK = 0;
    private int $chunkSize;
    private bool $flushOnWrite;
    private string $closePolicy;  // 'close_all' | 'leave_open'
    private $onEvent;

    /**
     * @param WritableStreamInterface[] $sinks
     * @param 'fail_fast'|array{quorum:int}|'best_effort' $policy
     */
    public function __construct(
        array $sinks,
        $policy = 'fail_fast',
        int $chunkSize = 256_000,
        bool $flushOnWrite = false,
        string $closePolicy = 'close_all',
        ?callable $onEvent = null
    ) {
        if (empty($sinks)) throw new \InvalidArgumentException('Tee needs at least one sink');
        $this->sinks = array_values($sinks);
        $this->chunkSize = $chunkSize;
        $this->flushOnWrite = $flushOnWrite;
        $this->closePolicy = $closePolicy;
        $this->onEvent = $onEvent;

        if (is_array($policy) && isset($policy['quorum'])) {
            $this->policyType = 'quorum';
            $this->quorumK = (int)$policy['quorum'];
            if ($this->quorumK < 1 || $this->quorumK > count($sinks)) {
                throw new \InvalidArgumentException('Invalid quorum size');
            }
        } elseif ($policy === 'best_effort') {
            $this->policyType = 'best_effort';
        } else {
            $this->policyType = 'fail_fast';
        }
    }

    public function write(string $data): int
    {
        $len = strlen($data);
        if ($len === 0) return 0;

        $offset = 0;
        while ($offset < $len) {
            $chunk = substr($data, $offset, $this->chunkSize);
            $need = strlen($chunk);

            $ok = 0;
            $errors = [];

            foreach ($this->sinks as $i => $sink) {
                $written = 0;
                try {
                    // loop until this sink writes the whole chunk
                    while ($written < $need) {
                        $w = $sink->write(substr($chunk, $written));
                        if ($w === 0) { // avoid infinite loop
                            throw new \RuntimeException('Short write (0 bytes)');
                        }
                        $written += $w;
                    }
                    if ($this->flushOnWrite) $sink->flush();
                    $ok++;
                    $this->emit('write_ok', ['index' => $i, 'bytes' => $need]);
                } catch (\Throwable $e) {
                    $errors[] = ['index' => $i, 'error' => $e];
                    $this->emit('write_err', ['index' => $i, 'error' => $e->getMessage()]);
                    if ($this->policyType === 'fail_fast') {
                        throw $e;
                    }
                }
            }

            if ($this->policyType === 'quorum' && $ok < $this->quorumK) {
                $first = $errors[0]['error'] ?? new \RuntimeException('Quorum not met');
                throw $first;
            }

            $offset += $need;
        }

        return $len;
    }

    public function flush(): void
    {
        $errs = [];
        foreach ($this->sinks as $i => $s) {
            try { $s->flush(); $this->emit('flush_ok', ['index' => $i]); }
            catch (\Throwable $e) { $errs[] = $e; $this->emit('flush_err', ['index' => $i, 'error' => $e->getMessage()]); }
        }
        if ($this->policyType === 'fail_fast' && $errs) throw $errs[0];
        if ($this->policyType === 'quorum' && count($this->sinks) - count($errs) < $this->quorumK) throw $errs[0] ?? new \RuntimeException('Quorum not met on flush');
        // best_effort swallows (but you logged via onEvent)
    }

    public function close(): void
    {
        if ($this->closePolicy !== 'close_all') return;
        foreach ($this->sinks as $i => $s) {
            try { $s->close(); $this->emit('close_ok', ['index' => $i]); }
            catch (\Throwable $e) { $this->emit('close_err', ['index' => $i, 'error' => $e->getMessage()]); }
        }
    }

    public function getSize(): ?int { return null; }
    public function tell(): int { throw new \LogicException('TeeWriter has no position'); }
    public function eof(): bool { return false; }
    public function getMetadata(?string $key = null): mixed { return null; }

    private function emit(string $event, array $ctx): void
    {
        if ($this->onEvent) { ($this->onEvent)($event, $ctx); }
    }
}
```

> Notes:
>
> * This version is **serial** (writes to sinks one by one). In PHP land that’s the norm; “parallel” would need processes or extensions.
> * For performance, keep `chunkSize` moderately large (128–512 KB).
> * With `fail_fast`, all sinks are kept consistent or you fail immediately. With `best_effort`, you accept divergence (log it!).

---

# TeeReader sketch (tap reads into sinks)

```php
final class TeeReader implements ReadableStreamInterface, SeekableStreamInterface
{
    public function __construct(
        private ReadableStreamInterface $source,
        /** @var WritableStreamInterface[] */
        private array $sinks,
        private int $chunkSize = 256_000,
        private string $policy = 'best_effort',
        private ?callable $onEvent = null
    ) {}

    public function read(int $length): string
    {
        $data = $this->source->read($length);
        if ($data === '') return $data;

        $errs = [];
        foreach ($this->sinks as $i => $sink) {
            try { $sink->write($data); $this->emit('mirror_ok', ['index' => $i, 'bytes' => strlen($data)]); }
            catch (\Throwable $e) {
                $errs[] = $e; $this->emit('mirror_err', ['index' => $i, 'error' => $e->getMessage()]);
                if ($this->policy === 'fail_fast') throw $e;
            }
        }
        return $data;
    }

    // delegate seek/eof/tell/close to $source
    public function seek(int $o, int $w = SEEK_SET): void { if ($this->source instanceof SeekableStreamInterface) $this->source->seek($o, $w); else throw new \LogicException('Source not seekable'); }
    public function eof(): bool { return $this->source->eof(); }
    public function tell(): int { return $this->source->tell(); }
    public function close(): void { $this->source->close(); /* leave sinks open */ }
    public function getSize(): ?int { return $this->source->getSize(); }
    public function getMetadata(?string $k = null): mixed { return $this->source->getMetadata($k); }
    private function emit(string $e, array $c): void { if ($this->onEvent) ($this->onEvent)($e, $c); }
}
```

---

## Gotchas & guidance

* **Back-pressure**: the tee is only as fast as the slowest sink. For production, combine with `BufferedStream` or the **stage-then-fan-out** pattern.
* **Partial writes**: always loop until each sink writes the full chunk, or handle/throw based on policy.
* **Atomicity**: the tee can’t make multi-sink writes atomic. If you need atomic **finalization**, write each sink to a temp and then `rename()` them as a final step (2-phase commit-ish).
* **Closing**: decide who owns the sinks. Defaulting to `close_all` is convenient; `leave_open` is safer for shared sinks.
* **Telemetry**: wire `onEvent` into your logger; it’s invaluable for diagnosing drift in best-effort mode.

---

If you want, I can tailor `TeeWriter/TeeReader` to your exact interfaces (and add a small `XStream::tee([...])` factory helper) so it plugs straight into your driver code.





tell me about buffered streams




Buffered streams are a **decorator pattern** around your base stream.
They add a userland buffer so that lots of small reads/writes don’t hammer the underlying stream one sys-call at a time. Think of it like wrapping `fread`/`fwrite` with a bigger, smarter cache in PHP code.

---

## Why bother?

* **Performance**
  Reading 1 byte at a time from disk or a network socket is *slow*. A `BufferedStream` might fetch 8 KB at once, then serve you 1-byte reads out of that buffer.

* **Convenience**
  You can implement helpers like `readLine()`, `readUntil()`, or `peek()` more easily with a buffer.

* **Consistency across backends**
  Some drivers (S3, HTTP) only support range requests. A buffered wrapper lets you normalize them to normal stream reads.

---

## How it works

### Buffered read

* On `read($len)`:

  * If the buffer has enough bytes → serve from buffer, adjust pointers.
  * If not → pull a larger chunk (e.g. 8 KB) from the underlying stream, stash it, then serve the request.
* Expose helpers: `peek($len)` (look at buffer without consuming), `readLine()` (scan buffer for `\n`).

### Buffered write

* On `write($data)`:

  * Append to a write buffer.
  * If buffer exceeds threshold (e.g. 8 KB), flush it to the underlying stream in one `fwrite`.
* On `flush()`, push any pending bytes.
* On `close()`, flush before closing.

---

## Usage examples

### Buffered reads

```php
use Orryv\XStream\{FileStream, BufferedStream};

// Underlying file
$raw = new FileStream('/var/log/huge.log', 'rb');

// Wrap with 64 KB read buffer
$buf = new BufferedStream($raw, readBufferSize: 64 * 1024);

// Caller does tiny reads, but they are served from the big buffer
while (!$buf->eof()) {
    $char = $buf->read(1);  // doesn't hit the file each time
    // ... parse character ...
}
$buf->close();
```

### Buffered writes

```php
use Orryv\XStream\{FileStream, BufferedStream};

// File opened for write
$raw = new FileStream('/tmp/output.csv', 'c+b');
$buf = new BufferedStream($raw, writeBufferSize: 16 * 1024);

foreach ($rows as $row) {
    $line = implode(',', $row) . "\n";
    $buf->write($line);   // goes into buffer
}
$buf->flush();            // push to disk
$buf->close();
```

### Peek + readLine

```php
// Suppose BufferedStream implements helper methods
$line = $buf->readLine();    // read until "\n"
$peek = $buf->peek(5);       // look ahead without consuming
```

---

## Design tips

* **Buffer sizes**: pick sensible defaults (`8 KB` or `64 KB`); allow constructor args to override.
* **Dual buffer**: keep separate read/write buffers, so they don’t interfere.
* **Seek invalidates buffer**: if you `seek()`, clear any buffered state (you can’t guarantee coherence).
* **EOF detection**: careful—EOF only true when underlying stream EOF **and** read buffer is empty.
* **Flush on close**: always flush write buffer before closing underlying stream.
* **Error handling**: if underlying `read`/`write` throws, clear buffer and rethrow.
* **Composition**: works great combined with `RetryStream` or `TeeStream`.

---

## When to use BufferedStream

* **Parsing text protocols** (CSV, JSONL, log files): lots of small reads.
* **Network streams**: minimize roundtrips by batching.
* **File writes with small appends**: fewer syscalls.
* **Drivers with expensive underlying ops**: e.g., S3 range requests, HTTP chunked transfer.

---

Would you like me to sketch a `BufferedStream` implementation for your `StreamInterface` (read/write/seek) so you can drop it in alongside `RetryStream` and `TeeStream`?

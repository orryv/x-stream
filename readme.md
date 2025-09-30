# XStream

This repository implements composable stream primitives for PHP, including file, memory, temp, retry, buffered, tee, and HTTP-backed streams. It also ships with PSR-7 interoperability helpers so any `StreamInterface` can be bridged to or from third-party HTTP clients in one line. You can even wrap existing PHP resources through `XStream::fromResource()` to keep using buffering or retry decorators. For practical recipes—such as downloading large files with retry + resume support via `XStream::http()`—see the [usage guide](docs/USAGE.md).

## Installation

```bash
composer require orryv/x-stream
```

## Quick start

Read a local file with transparent retry and buffering:

```php
use Orryv\XStream\XStream;

$stream = XStream::file('/var/data/report.csv', 'rb', [
    'retry' => true,
    'retries' => 5,
    'retry_delay_ms' => 10,
    'buffered' => true,
    'buffer_read_size' => 128 * 1024,
]);

while (!$stream->eof()) {
    echo $stream->read(8192);
}
$stream->close();
```

Download over HTTP with resume support and save to disk:

```php
$source = XStream::http('https://example.com/archive.zip', [
    'headers' => [
        'Authorization' => 'Bearer ' . $token,
    ],
    'retry' => true,
    'buffered' => true,
    'buffer_read_size' => 256 * 1024,
]);
$target = XStream::file('/tmp/archive.zip', 'wb');

while (!$source->eof()) {
    $chunk = $source->read(256 * 1024);
    if ($chunk === '') {
        break;
    }
    $target->write($chunk);
}

$source->close();
$target->close();
```

## Key recipes

* Wrap existing PHP resources so you can keep using buffering or retry decorators.
* Bridge to PSR-7 `StreamInterface` instances when interoperating with HTTP clients.
* Fan-out writes or mirror reads using tee helpers for auditing and redundancy.

See [docs/USAGE.md](docs/USAGE.md) for the full cookbook of streaming patterns, including memory streams, temporary storage, retries, buffering strategies, and tee pipelines.

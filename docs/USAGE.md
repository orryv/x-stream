# XStream usage guide

The `Orryv\\XStream` package provides a collection of composable stream implementations that make file and memory IO predictable across storage drivers. This guide shows real-world scenarios for each building block.

## Factory helpers (`XStream`)

Need to observe how data flows across tees or audit sinks? The factory helpers expose the same [tee event callbacks](#tee-event-callbacks) that the concrete classes provide, so you can wire monitoring straight into your pipelines.

```php
use Orryv\XStream;

// Read a local file with transparent retry + buffering
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

### Interoperability with PSR-7 streams

```php
use Orryv\XStream;

$xStream = XStream::file('/tmp/data.bin', 'c+b');
$psrStream = XStream::asPsrStream($xStream);      // expose as Psr\Http\Message\StreamInterface

// work with any PSR-7 compatible HTTP client or middleware
$psrStream->write('payload');

// wrap the PSR stream back into an Orryv\XStream instance when you need advanced helpers again
$bridge = XStream::fromPsrStream($psrStream);
$bridge->seek(0);
echo $bridge->read(7); // outputs 'payload'
```

### Wrap existing PHP resources

```php
$handle = fopen('php://temp', 'c+b');

// Keep using decorators like buffering + retry even when you start with a bare resource
$stream = XStream::fromResource($handle, [
    'buffered' => true,
]);

$stream->write('payload');
$stream->seek(0);
echo $stream->read(7);
$stream->close();
```

### Download over HTTP with resume support

```php
$source = XStream::http('https://example.com/archive.zip', [
    'headers' => [
        'Authorization' => 'Bearer ' . $token,
    ],
    'retry' => true,           // automatic reopen + Range headers when possible
    'buffered' => true,        // smoother large transfers
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

## FileStream

```php
use Orryv\XStream\FileStream;

$log = new FileStream('/var/log/app.log', 'rb');
$log->seek(-2048, SEEK_END); // tail the last 2 KB
$tail = $log->read(2048);
$log->close();
```

Looking for the simplest create/write/close flow? The [quick-start snippet](../readme.md#quick-start) shows the same pattern via
`XStream::file()`:

```php
use Orryv\XStream;

$stream = XStream::file('/path/output.txt', 'wb');
$stream->write("Hello from XStream!\n");
$stream->close();
```

### Atomic uploads

```php
$upload = new FileStream('/srv/uploads/new.zip', 'c+b');
$upload->setWriteBuffer(64 * 1024);
$upload->write($binaryPayload);
$upload->flush();
$upload->close();
```

## MemoryStream

```php
$buffer = new MemoryStream();
$buffer->write("header,1\n");
$buffer->write("detail,2\n");
$buffer->seek(0);
foreach (explode("\n", $buffer->getContents()) as $line) {
    // ...
}
```

## TempStream

```php
$tmp = new TempStream(limitBytes: 2_000_000);
$tmp->write($incomingChunk);
$tmp->seek(0);
process($tmp->read(8192));
$tmp->close();
```

## RetryStream

```php
$base = new FileStream('/mnt/nfs/report.bin', 'rb');
$stream = new RetryStream($base, retries: 5, delayMs: 25);
$chunk = $stream->read(1024 * 1024); // automatically reopens on stale handles
```

## BufferedStream helpers

```php
$raw = new FileStream('/var/data/events.ndjson', 'rb');
$buffered = new BufferedStream($raw, readBufferSize: 128 * 1024);

while (!$buffered->eof()) {
    $line = $buffered->readLine();
    if ($line === '') {
        break;
    }
    handle(json_decode($line, true));
}
$buffered->close();
```

## TeeWriter: fan-out writes

Tee streams also support lightweight event hooks—see [Tee event callbacks](#tee-event-callbacks) for details.

```php
$primary = new FileStream('/var/backups/primary.log', 'ab');
$secondary = new FileStream('/var/backups/secondary.log', 'ab');
$auditor = new MemoryStream();

$tee = new TeeWriter([$primary, $secondary, $auditor], policy: 'fail_fast');
$tee->write($payload);
$tee->close();
```

## TeeReader: mirror reads

Looking for callback hooks while mirroring? Jump to [Tee event callbacks](#tee-event-callbacks).

```php
$source = new FileStream('/var/uploads/incoming.bin', 'rb');
$audit = new FileStream('/var/audit/incoming.bin', 'c+b');

$reader = new TeeReader($source, [$audit], policy: 'fail_fast');
while (!$reader->eof()) {
    $chunk = $reader->read(256 * 1024);
    // stream $chunk to a client
}
$reader->close();
```

### Tee event callbacks

Both `TeeWriter` and `TeeReader` accept an optional `onEvent` callable. The closure is invoked with the event name and a context payload whenever work is attempted for a sink.

#### TeeWriter events

| Event        | Context keys                        | Description |
|--------------|--------------------------------------|-------------|
| `write_ok`   | `index`, `bytes`                     | Bytes were successfully written to sink `index`.
| `write_err`  | `index`, `error`                     | A write failed with the provided error message.
| `flush_ok`   | `index`                              | `flush()` succeeded for the sink.
| `flush_err`  | `index`, `error`                     | `flush()` threw an exception.
| `close_ok`   | `index`                              | `close()` succeeded when the tee is configured to close sinks.
| `close_err`  | `index`, `error`                     | `close()` threw an exception while closing a sink.

#### TeeReader events

| Event         | Context keys            | Description |
|---------------|-------------------------|-------------|
| `mirror_ok`   | `index`, `bytes`        | Bytes were mirrored to sink `index`.
| `mirror_err`  | `index`, `error`        | Mirroring to the sink failed with an error.

#### Example: aggregate tee metrics

```php
use Orryv\XStream;

$primary = XStream::file('/var/backups/primary.log', 'ab');
$secondary = XStream::file('/var/backups/secondary.log', 'ab');
$audit = XStream::file('/var/audit/incoming.bin', 'c+b');

$events = [];

$teeWriter = XStream::teeWriter([
    $primary,
    $secondary,
], onEvent: function (string $event, array $context) use (&$events) {
    $events[] = [$event, $context['index'], $context['bytes'] ?? null];
});

$teeReader = XStream::teeReader(
    XStream::file('/var/uploads/incoming.bin', 'rb'),
    [$audit],
    onEvent: function (string $event, array $context) use (&$events) {
        $events[] = [$event, $context['index'], $context['bytes'] ?? null];
    }
);

// ... use $teeWriter / $teeReader ...

foreach ($events as [$event, $index, $bytes]) {
    error_log(sprintf('tee[%d] %s %s', $index, $event, $bytes ?? ''));
}
```

## NullStream sink

```php
$null = new NullStream();
$tee = new TeeWriter([$null]);
$tee->write($noisyMetricsPayload); // safely discarded
```

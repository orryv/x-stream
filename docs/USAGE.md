# XStream usage guide

The `Orryv\\X\\Stream` package provides a collection of composable stream implementations that make file and memory IO predictable across storage drivers. This guide shows real-world scenarios for each building block.

## Factory helpers (`XStream`)

```php
use Orryv\XStream\XStream;

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
use Orryv\XStream\XStream;

$xStream = XStream::file('/tmp/data.bin', 'c+b');
$psrStream = XStream::asPsrStream($xStream);      // expose as Psr\Http\Message\StreamInterface

// work with any PSR-7 compatible HTTP client or middleware
$psrStream->write('payload');

// wrap the PSR stream back into an Orryv\XStream instance when you need advanced helpers again
$bridge = XStream::fromPsrStream($psrStream);
$bridge->seek(0);
echo $bridge->read(7); // outputs 'payload'
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

```php
$primary = new FileStream('/var/backups/primary.log', 'ab');
$secondary = new FileStream('/var/backups/secondary.log', 'ab');
$auditor = new MemoryStream();

$tee = new TeeWriter([$primary, $secondary, $auditor], policy: 'fail_fast');
$tee->write($payload);
$tee->close();
```

## TeeReader: mirror reads

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

## NullStream sink

```php
$null = new NullStream();
$tee = new TeeWriter([$null]);
$tee->write($noisyMetricsPayload); // safely discarded
```

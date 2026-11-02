<?php

// Fixed-study historical acquisition. No email, order, subscription or configuration mutation.
$arguments = getopt('', ['help', 'probe', 'warmup', 'batch:']);
if (isset($arguments['help'])) {
    echo "Usage: php scripts/research/fetch_scalper_history.php --probe | --warmup | --batch=0..5\n";
    echo "Uses the existing Twelve Data key and configured trusted TLS/proxy settings. Honor the account quota between batches.\n";
    exit(0);
}
if (count(array_intersect(['probe', 'warmup', 'batch'], array_keys($arguments))) !== 1) {
    fwrite(STDERR, "Choose exactly one explicit acquisition action; use --help. No request was made.\n");
    exit(1);
}
$workspace = dirname(__DIR__, 2);
require $workspace.'/vendor/autoload.php';
$app = require $workspace.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$key = (string) config('services.twelvedata.key');
$root = $workspace.'/storage/app/private/signal-research/external-validation';
if (! is_dir($root)) {
    mkdir($root, 0777, true);
}
try {
    if ($key === '') {
        throw new RuntimeException('Existing Twelve Data key is unavailable.');
    }
    $probe = array_key_exists('probe', $arguments);
    $warmup = array_key_exists('warmup', $arguments);
    $batch = 0;
    if (isset($arguments['batch'])) {
        if (! is_string($arguments['batch']) || ! preg_match('/^[0-5]$/D', $arguments['batch'])) {
            throw new RuntimeException('Batch must be exactly 0 through 5.');
        }
        $batch = (int) $arguments['batch'];
    }
    if ($batch < 0 || $batch > 5) {
        throw new RuntimeException('Batch must be 0 through 5 for this fixed period.');
    }
    $start = new DateTimeImmutable($warmup ? '2026-05-29T00:00:00+00:00' : '2026-06-01T00:00:00+00:00');
    $end = new DateTimeImmutable($warmup ? '2026-06-01T00:00:00+00:00' : '2026-09-01T00:00:00+00:00');
    $done = [];
    for ($n = ($probe || $warmup) ? 0 : $batch * 6; $n < (($probe || $warmup) ? 1 : min(31, $batch * 6 + 6)); $n++) {
        $from = $start->modify('+'.($n * 3).' days');
        $until = $probe ? $from->modify('+10 minutes') : min($from->modify('+3 days'), $end);
        $to = $until->modify('-1 minute');
        $target = $root.'/twelvedata-'.($probe ? 'probe' : ($warmup ? 'warmup' : sprintf('chunk-%02d', $n))).'.json';
        if (! $probe && is_file($target)) {
            $done[] = ['chunk' => $n, 'cached' => true];

            continue;
        }
        $transport = [];
        $certificate = config('services.twelvedata.ca_bundle');
        if ($certificate !== null && $certificate !== '') {
            if (! is_string($certificate) || ! is_file($certificate) || ! is_readable($certificate)) {
                throw new RuntimeException('Configured trusted CA bundle is not readable; TLS verification stays enabled.');
            }
            $transport['verify'] = $certificate;
        }
        $proxy = config('services.twelvedata.proxy');
        if (is_string($proxy)) {
            $transport['proxy'] = $proxy;
        }
        $response = Illuminate\Support\Facades\Http::withOptions($transport)
            ->acceptJson()->timeout(15)->get('https://api.twelvedata.com/time_series', [
                'symbol' => 'XAU/USD', 'interval' => '1min', 'timezone' => 'UTC', 'order' => 'asc', 'outputsize' => 5000,
                'start_date' => $from->format('Y-m-d H:i:s'), 'end_date' => $to->format('Y-m-d H:i:s'), 'apikey' => $key,
            ]);
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ($data['status'] ?? '') === 'error') {
            $message = is_array($data) ? (string) ($data['message'] ?? 'Unavailable') : 'Invalid JSON';
            throw new RuntimeException('Historical provider HTTP '.$response->status().': '.str_replace($key, '[redacted]', $message));
        }
        if (($data['meta']['symbol'] ?? null) !== 'XAU/USD' || ($data['meta']['interval'] ?? null) !== '1min' || ! is_array($data['values'] ?? null) || ! $data['values']) {
            throw new RuntimeException('Historical response identity, interval or series is missing.');
        }
        foreach ($data['values'] as $bar) {
            if (! is_array($bar) || ! is_string($bar['datetime'] ?? null)) {
                throw new RuntimeException('Historical timestamp missing.');
            }
            $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $bar['datetime'], new DateTimeZone('UTC'));
            if (! $at || $at->format('Y-m-d H:i:s') !== $bar['datetime'] || $at < $from || $at >= $until) {
                throw new RuntimeException('Historical timestamps do not match the requested fixed UTC period.');
            }
            foreach (['open', 'high', 'low', 'close'] as $field) {
                if (! isset($bar[$field]) || ! is_numeric($bar[$field]) || ! is_finite((float) $bar[$field]) || $bar[$field] <= 0) {
                    throw new RuntimeException('Invalid historical OHLC.');
                }
            }
            if ($bar['high'] < max($bar['open'], $bar['low'], $bar['close']) || $bar['low'] > min($bar['open'], $bar['close'])) {
                throw new RuntimeException('Historical OHLC bounds disagree.');
            }
        }
        $packet = ['source' => 'Twelve Data historical API', 'request' => ['symbol' => 'XAU/USD', 'interval' => '1min',
            'timezone' => 'UTC', 'from_inclusive' => $from->format(DATE_ATOM), 'to_exclusive' => $until->format(DATE_ATOM)],
            'retrieved_at_utc' => gmdate(DATE_ATOM), 'meta' => $data['meta'], 'values' => $data['values']];
        $json = json_encode($packet, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        file_put_contents($target, $json);
        $done[] = ['chunk' => $n, 'rows' => count($data['values']), 'first_utc' => $data['values'][0]['datetime'],
            'last_utc' => $data['values'][array_key_last($data['values'])]['datetime'], 'sha256' => hash('sha256', $json)];
    }
    echo json_encode(['ok' => true, 'probe' => $probe, 'batch' => $batch, 'windows' => $done, 'prices_printed' => false, 'emails_sent' => 0, 'orders_sent' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $message = str_replace($key === '' ? '\0' : $key, '[redacted]', $error->getMessage());
    $message = preg_replace('~https?://[^\s)]+~', '[provider URL]', $message);
    echo json_encode(['ok' => false, 'error' => $message, 'emails_sent' => 0, 'orders_sent' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    exit(1);
}

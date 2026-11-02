<?php

use App\Services\MarketDataGuard;
use Illuminate\Support\Carbon;

function guardFreshSignal(string $pair = 'XAUUSD'): array
{
    return [
        'action' => 'BUY', 'symbol_code' => $pair, 'timestamp' => '2026-11-02T12:10:00Z',
        'expires_at' => '2026-11-02T12:18:00Z',
        'freshness' => [
            'verified' => true, 'source' => 'twelvedata', 'interval_seconds' => 300,
            'data_as_of' => '2026-11-02T12:15:00Z', 'observed_at' => '2026-11-02T12:15:20Z',
            'generated_at' => '2026-11-02T12:15:20Z', 'market_open' => true,
        ],
    ];
}

function guardBars(): array
{
    return array_map(fn ($minute) => [
        'timestamp' => sprintf('2026-11-02T12:%02d:00Z', $minute),
        'open' => 2000, 'high' => 2002, 'low' => 1999, 'close' => 2001,
    ], [0, 5, 10, 15, 20]);
}

test('market guard uses New York daylight saving rules for gold and FX weekly reopening', function (string $at, string $pair, bool $open) {
    expect((new MarketDataGuard)->marketStatus($pair, Carbon::parse($at))['open'])->toBe($open);
})->with([
    'Sunday Philippines noon' => ['2026-11-01T12:00:00+08:00', 'XAUUSD', false],
    'winter gold before reopen' => ['2026-11-01T22:59:59Z', 'XAUUSD', false],
    'winter gold reopened' => ['2026-11-01T23:00:00Z', 'XAUUSD', true],
    'winter FX before reopen' => ['2026-11-01T21:59:59Z', 'EURUSD', false],
    'winter FX reopened' => ['2026-11-01T22:00:00Z', 'EURUSD', true],
    'spring gold before reopen' => ['2026-03-08T21:59:59Z', 'XAUUSD', false],
    'spring gold reopened' => ['2026-03-08T22:00:00Z', 'XAUUSD', true],
    'spring FX before reopen' => ['2026-03-08T20:59:59Z', 'EURUSD', false],
    'spring FX reopened' => ['2026-03-08T21:00:00Z', 'EURUSD', true],
    'pre-spring winter gold' => ['2026-03-01T22:30:00Z', 'XAUUSD', false],
    'pre-spring winter gold opened' => ['2026-03-01T23:00:00Z', 'XAUUSD', true],
    'summer Friday before close' => ['2026-07-10T20:59:59Z', 'XAUUSD', true],
    'summer Friday closed' => ['2026-07-10T21:00:00Z', 'XAUUSD', false],
    'winter Friday closed' => ['2026-11-06T22:00:00Z', 'EURUSD', false],
    'Saturday' => ['2026-11-07T12:00:00Z', 'EURUSD', false],
    'weekday Philippines noon' => ['2026-11-02T12:00:00+08:00', 'XAUUSD', true],
]);

test('market guard blocks gold daily maintenance without blocking FX at the same time', function (string $at, bool $goldOpen) {
    $guard = new MarketDataGuard;
    expect($guard->marketStatus('XAUUSD', Carbon::parse($at))['open'])->toBe($goldOpen)
        ->and($guard->marketStatus('GBPJPY', Carbon::parse($at))['open'])->toBeTrue();
})->with([
    ['2026-07-06T20:59:59Z', true],
    ['2026-07-06T21:00:00Z', false],
    ['2026-07-06T21:59:59Z', false],
    ['2026-07-06T22:00:00Z', true],
    ['2026-11-02T21:59:59Z', true],
    ['2026-11-02T22:00:00Z', false],
    ['2026-11-02T22:59:59Z', false],
    ['2026-11-02T23:00:00Z', true],
]);

test('market guard fails closed for unconfigured instruments and leaves the supplied clock unchanged', function () {
    $guard = new MarketDataGuard;
    $asOf = Carbon::parse('2026-11-02T12:15:20+08:00');
    $before = $asOf->toIso8601String();
    expect($guard->marketStatus('BTCUSD', $asOf)['open'])->toBeFalse()
        ->and($guard->marketStatus('USDUSD', $asOf)['open'])->toBeFalse()
        ->and($guard->marketStatus('XAU/USD', $asOf)['open'])->toBeTrue()
        ->and($asOf->toIso8601String())->toBe($before);
});

test('market guard validates completed candles and excludes forming and future bars', function () {
    $guard = new MarketDataGuard;
    $closed = $guard->validateCandles(guardBars(), 300, Carbon::parse('2026-11-02T12:15:20Z'));

    expect($closed)->toHaveCount(3)
        ->and($closed[2]['timestamp'])->toBe('2026-11-02T12:10:00+00:00')
        ->and($closed[2]['close'])->toBe(2001.0);
});

test('market guard permits only a short publication delay for the preceding completed candle', function () {
    $guard = new MarketDataGuard;
    $prior = array_slice(guardBars(), 0, 2);
    expect($guard->validateCandles($prior, 300, Carbon::parse('2026-11-02T12:16:30Z')))->toHaveCount(2);
    expect(fn () => $guard->validateCandles($prior, 300, Carbon::parse('2026-11-02T12:16:31Z')))->toThrow(InvalidArgumentException::class, 'stale');
    expect($guard->validateCandles($prior, 300, Carbon::parse('2026-11-02T12:30:00Z'), false))->toHaveCount(2);
});

test('market guard rejects invalid calendar times and candle alignment', function (string $timestamp) {
    $bars = guardBars();
    $bars[0]['timestamp'] = $timestamp;
    expect(fn () => (new MarketDataGuard)->validateCandles($bars, 300, Carbon::parse('2026-11-02T12:15:20Z')))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '2026-02-30T12:00:00Z', '2026-11-02T24:00:00Z', 'tomorrow 12:00',
    '2026-11-02', '2026-11-02T12:00:00.001Z', '2026-11-02T12:01:00Z',
]);

test('market guard rejects duplicate unordered and invalid OHLC candles', function (string $fault) {
    $bars = guardBars();
    if ($fault === 'duplicate') {
        $bars[1]['timestamp'] = $bars[0]['timestamp'];
    } elseif ($fault === 'unordered') {
        [$bars[0], $bars[1]] = [$bars[1], $bars[0]];
    } elseif ($fault === 'nonfinite') {
        $bars[0]['close'] = INF;
    } elseif ($fault === 'negative') {
        $bars[0]['low'] = -1;
    } else {
        $bars[0]['high'] = 1990;
    }
    expect(fn () => (new MarketDataGuard)->validateCandles($bars, 300, Carbon::parse('2026-11-02T12:15:20Z')))
        ->toThrow(InvalidArgumentException::class);
})->with(['duplicate', 'unordered', 'nonfinite', 'negative', 'bounds']);

test('market guard rejects intervals that cannot identify whole-minute UTC candles', function (int $interval) {
    expect(fn () => (new MarketDataGuard)->validateCandles(guardBars(), $interval, Carbon::parse('2026-11-02T12:15:20Z')))
        ->toThrow(InvalidArgumentException::class);
})->with([0, 30, 128, 301, 172800]);

test('market guard accepts fresh open-market provenance and both opening or closing signal timestamps', function (string $timestamp, string $source) {
    $signal = guardFreshSignal();
    $signal['timestamp'] = $timestamp;
    $signal['freshness']['source'] = $source;
    expect((new MarketDataGuard)->validateSignal($signal, Carbon::parse('2026-11-02T12:15:20Z')))->toBeNull();
})->with([
    ['2026-11-02T12:10:00Z', 'twelvedata'],
    ['2026-11-02T12:15:00Z', 'mt5'],
]);

test('market guard refuses stale saved signals even if they are regenerated or forced', function () {
    $signal = guardFreshSignal();
    $signal['forced'] = true;
    $signal['freshness']['generated_at'] = '2026-11-02T12:19:00Z';
    expect((new MarketDataGuard)->validateSignal($signal, Carbon::parse('2026-11-02T12:19:00Z')))->not->toBeNull();
});

test('market guard fails closed when any required provenance is absent or contradictory', function (string $fault) {
    $signal = guardFreshSignal();
    match ($fault) {
        'missing' => $signal['freshness'] = [],
        'unverified' => $signal['freshness']['verified'] = 'true',
        'unsupported provider' => $signal['freshness']['source'] = 'yahoo',
        'holiday closed' => $signal['freshness']['market_open'] = false,
        'missing symbol' => $signal['symbol_code'] = null,
        'missing expiry' => $signal['expires_at'] = null,
        'wrong interval' => $signal['freshness']['interval_seconds'] = 301,
        'interval string' => $signal['freshness']['interval_seconds'] = '300',
        'future quote' => $signal['freshness']['observed_at'] = '2026-11-02T12:15:21Z',
        'future generation' => $signal['freshness']['generated_at'] = '2026-11-02T12:15:21Z',
        'future candle' => $signal['freshness']['data_as_of'] = '2026-11-02T12:20:00Z',
        'stale quote' => $signal['freshness']['observed_at'] = '2026-11-02T12:13:49Z',
        'quote before close' => $signal['freshness']['observed_at'] = '2026-11-02T12:14:59Z',
        'generation before quote' => $signal['freshness']['generated_at'] = '2026-11-02T12:15:19Z',
        'extended expiry' => $signal['expires_at'] = '2026-11-02T12:18:01Z',
        'expired' => $signal['expires_at'] = '2026-11-02T12:15:20Z',
        'old signal relabeled' => $signal['timestamp'] = '2026-11-02T11:10:00Z',
        'malformed date' => $signal['freshness']['generated_at'] = '2026-02-30T12:15:20Z',
    };
    expect((new MarketDataGuard)->validateSignal($signal, Carbon::parse('2026-11-02T12:15:20Z')))->not->toBeNull();
})->with([
    'missing', 'unverified', 'unsupported provider', 'holiday closed', 'missing symbol',
    'missing expiry', 'wrong interval', 'interval string', 'future quote', 'future generation',
    'future candle', 'stale quote', 'quote before close', 'generation before quote',
    'extended expiry', 'expired', 'old signal relabeled', 'malformed date',
]);

test('market guard refuses entry snapshots during Sunday and daily gold closure regardless of metadata', function (string $at) {
    $signal = guardFreshSignal();
    $signal['forced'] = true;
    expect((new MarketDataGuard)->validateSignal($signal, Carbon::parse($at)))->toContain('closed');
})->with(['2026-11-01T04:00:00Z', '2026-11-02T22:15:00Z']);

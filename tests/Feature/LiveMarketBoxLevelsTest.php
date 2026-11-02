<?php

use App\Services\LiveMarketDataService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02T14:00:10Z'));
    Cache::flush();
    Http::preventStrayRequests();
    Mail::fake();
    config(['services.twelvedata.key' => 'box-level-test-secret']);
});

function liveBoxPair(string $code = 'XAUUSD'): array
{
    return ['code' => $code, 'twelvedata_symbol' => substr($code, 0, 3).'/'.substr($code, 3, 3)];
}

function liveBoxHours(string $code = 'XAUUSD'): array
{
    $values = [];
    $start = now()->utc()->startOfDay()->subDays(7);
    $end = now()->utc()->startOfHour();
    for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addHour()) {
        $values[] = [
            'datetime' => $cursor->format('Y-m-d H:i:s'),
            'open' => '2000', 'high' => '2001', 'low' => '1999', 'close' => '2000',
        ];
    }

    return [
        'status' => 'ok',
        // Instrument metadata stays Sydney even when requested intraday output is UTC.
        'meta' => ['symbol' => liveBoxPair($code)['twelvedata_symbol'], 'interval' => '1h', 'exchange_timezone' => 'Australia/Sydney'],
        'values' => $values,
    ];
}

function changeLiveBoxHour(array $data, string $at, array $changes): array
{
    foreach ($data['values'] as &$bar) {
        if ($bar['datetime'] === $at) {
            $bar = array_replace($bar, $changes);
        }
    }
    unset($bar);

    return $data;
}

function fakeLiveBoxHours(array $data): void
{
    Http::fake(['api.twelvedata.com/time_series*' => Http::response($data)]);
}

test('Monday box aggregates the previous UTC Friday H1 spot session across the DST weekend', function () {
    $data = liveBoxHours();
    $data = changeLiveBoxHour($data, '2026-10-30 00:00:00', ['high' => '2010', 'low' => '1988']);
    $data = changeLiveBoxHour($data, '2026-10-30 20:00:00', ['high' => '2025', 'low' => '1985']);
    // Friday closed at 21:00 UTC before the DST change; these are indicative only.
    $data = changeLiveBoxHour($data, '2026-10-30 21:00:00', ['high' => '9000', 'low' => '1']);
    $data = changeLiveBoxHour($data, '2026-11-01 12:00:00', ['high' => '9999', 'low' => '0.5']);
    $data = changeLiveBoxHour($data, '2026-11-02 01:00:00', ['high' => '8000', 'low' => '2']);
    fakeLiveBoxHours($data);

    $levels = app(LiveMarketDataService::class)->dailyLevels(liveBoxPair());

    expect($levels)->toMatchArray([
        'date' => '2026-10-30', 'high' => 2025.0, 'low' => 1985.0,
        'source' => 'twelvedata_xauusd', 'error' => null,
    ]);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/time_series')
        && $request['interval'] === '1h' && $request['timezone'] === 'UTC'
        && $request['symbol'] === 'XAU/USD' && $request['order'] === 'asc');
    Mail::assertNothingSent();
});

test('box levels never request daily provider bars whose native timezone would change the box date', function () {
    $data = liveBoxHours();
    $data = changeLiveBoxHour($data, '2026-10-30 02:00:00', ['high' => '2030', 'low' => '1980']);
    fakeLiveBoxHours($data);

    $levels = app(LiveMarketDataService::class)->dailyLevels(liveBoxPair());

    expect($levels['date'])->toBe('2026-10-30')
        ->and($levels['high'])->toBe(2030.0)
        ->and($levels['low'])->toBe(1980.0);
    Http::assertNotSent(fn ($request) => in_array($request['interval'], ['1day', '1d'], true));
    Http::assertSentCount(1);
});

test('one missing previous trading-day hour rejects the box without selecting an older or partial day', function () {
    $data = liveBoxHours();
    $data['values'] = array_values(array_filter($data['values'], fn ($bar) => $bar['datetime'] !== '2026-10-30 12:00:00'));
    fakeLiveBoxHours($data);

    expect(fn () => app(LiveMarketDataService::class)->dailyLevels(liveBoxPair()))
        ->toThrow(RuntimeException::class, 'incomplete');
    Http::assertSentCount(1);
    Mail::assertNothingSent();
});

test('historical indicative weekend and gold maintenance candles are excluded before returning H1 history', function () {
    fakeLiveBoxHours(liveBoxHours());
    $bars = app(LiveMarketDataService::class)->candles(liveBoxPair(), '60min', 300)['candles'];
    $times = array_column($bars, 'timestamp');

    expect($times)->not->toContain('2026-10-31T12:00:00+00:00')
        ->not->toContain('2026-11-01T12:00:00+00:00')
        ->not->toContain('2026-10-30T21:00:00+00:00')
        ->not->toContain('2026-10-29T21:00:00+00:00')
        ->and($times)->toContain('2026-11-01T23:00:00+00:00')
        ->and(end($times))->toBe('2026-11-02T13:00:00+00:00');
});

test('UTC gold box includes both sides of winter maintenance and ignores its missing closed hour', function () {
    $this->travelTo(Carbon::parse('2026-11-03T14:00:10Z'));
    $data = liveBoxHours();
    $data = changeLiveBoxHour($data, '2026-11-02 21:00:00', ['high' => '2025', 'low' => '1988']);
    $data = changeLiveBoxHour($data, '2026-11-02 23:00:00', ['high' => '2010', 'low' => '1985']);
    $data['values'] = array_values(array_filter($data['values'], fn ($bar) => $bar['datetime'] !== '2026-11-02 22:00:00'));
    fakeLiveBoxHours($data);

    $levels = app(LiveMarketDataService::class)->dailyLevels(liveBoxPair());

    expect($levels['date'])->toBe('2026-11-02')
        ->and($levels['high'])->toBe(2025.0)
        ->and($levels['low'])->toBe(1985.0);
});

test('FX UTC box includes the New York gold maintenance hour because FX remains open', function () {
    $this->travelTo(Carbon::parse('2026-11-03T14:00:10Z'));
    $data = liveBoxHours('EURUSD');
    $data = changeLiveBoxHour($data, '2026-11-02 22:00:00', ['high' => '2025', 'low' => '1985']);
    fakeLiveBoxHours($data);

    $levels = app(LiveMarketDataService::class)->dailyLevels(liveBoxPair('EURUSD'));

    expect($levels['date'])->toBe('2026-11-02')
        ->and($levels['high'])->toBe(2025.0)
        ->and($levels['low'])->toBe(1985.0)
        ->and($levels['source'])->toBe('twelvedata_eurusd');
});

test('missing FX hour cannot be excused as gold maintenance', function () {
    $this->travelTo(Carbon::parse('2026-11-03T14:00:10Z'));
    $data = liveBoxHours('EURUSD');
    $data['values'] = array_values(array_filter($data['values'], fn ($bar) => $bar['datetime'] !== '2026-11-02 22:00:00'));
    fakeLiveBoxHours($data);

    expect(fn () => app(LiveMarketDataService::class)->dailyLevels(liveBoxPair('EURUSD')))
        ->toThrow(RuntimeException::class, 'incomplete');
});

test('box source identity mismatch is rejected without switching to futures or a second provider', function () {
    $data = liveBoxHours();
    $data['meta']['symbol'] = 'GC=F';
    fakeLiveBoxHours($data);

    expect(fn () => app(LiveMarketDataService::class)->dailyLevels(liveBoxPair()))
        ->toThrow(RuntimeException::class, 'identity');
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'yahoo') || str_contains($request->url(), 'alphavantage'));
});

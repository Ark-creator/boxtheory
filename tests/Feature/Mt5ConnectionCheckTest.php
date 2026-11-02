<?php

use App\Services\MarketDataGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02T12:15:20Z'));
    Mail::fake();
    Http::preventStrayRequests();
    config(['trading.auto_trader.bridge_url' => 'http://127.0.0.1:8710/trade',
        'trading.auto_trader.bridge_token' => 'private-test-token-that-is-never-printed',
        'trading.auto_trader.symbols.cent' => 'XAUUSD-STD', 'scalper.gold.source' => 'mt5']);
});

function connectionCheckSnapshot(): array
{
    $guard = new MarketDataGuard;
    $bars = [];
    $at = now()->utc()->startOfMinute()->subMinutes(5);
    while (count($bars) < 203) {
        if ($guard->marketStatus('XAUUSD', $at)['open'] && $guard->marketStatus('XAUUSD', $at->copy()->addSeconds(299))['open']) {
            $bars[] = ['timestamp' => $at->toIso8601String(), 'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000];
        }
        $at->subMinutes(5);
    }

    return ['status' => 'ok', 'source' => 'mt5', 'account_key' => 'private-account-fingerprint',
        'observed_at' => now()->utc()->toIso8601String(), 'symbol' => 'XAUUSD-STD',
        'capabilities' => ['m5_candles', 'quote_timestamp'], 'account' => ['demo' => true, 'balance' => 12345],
        'quote' => ['bid' => 2000, 'ask' => 2000.2, 'timestamp' => now()->utc()->subSeconds(2)->toIso8601String()],
        'm5' => array_reverse($bars)];
}

test('MT5 diagnostic verifies source data without orders emails or printing private configuration', function () {
    Http::fake(['http://127.0.0.1:8710/snapshot' => Http::response(connectionCheckSnapshot())]);
    expect(Artisan::call('mt5:check'))->toBe(0);
    $output = Artisan::output();
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    expect($result['connection'])->toBe('connected')->and($result['data_ready'])->toBeTrue()
        ->and($result['quote']['age_seconds'])->toBe(2)->and($result['active_closed_m5_bars'])->toBe(203)
        ->and($result['latest_m5_close_utc'])->toBe('2026-11-02T12:15:00+00:00')
        ->and($result['orders_sent'])->toBe(0)->and($result['emails_sent'])->toBe(0)
        ->and($output)->not->toContain('private-test-token')->not->toContain('private-account-fingerprint')->not->toContain('12345');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/snapshot'));
    Mail::assertNothingSent();
});

test('MT5 diagnostic distinguishes broker connection from unusable current data', function (string $fault) {
    $snapshot = connectionCheckSnapshot();
    if ($fault === 'stale') {
        $snapshot['quote']['timestamp'] = now()->utc()->subSeconds(91)->toIso8601String();
    } elseif ($fault === 'future') {
        $snapshot['quote']['timestamp'] = now()->utc()->addSecond()->toIso8601String();
    } elseif ($fault === 'history') {
        $snapshot['m5'] = array_slice($snapshot['m5'], -202);
    } else {
        $snapshot['quote']['ask'] = 1999;
    }
    Http::fake(['http://127.0.0.1:8710/snapshot' => Http::response($snapshot)]);
    expect(Artisan::call('mt5:check'))->toBe(1);
    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($result['connection'])->toBe('connected')->and($result['data_ready'])->toBeFalse();
    Http::assertSentCount(1);
    Mail::assertNothingSent();
})->with(['stale', 'future', 'history', 'crossed quote']);

test('MT5 diagnostic reports disconnection without another provider request', function () {
    Http::fake(['http://127.0.0.1:8710/snapshot' => Http::failedConnection()]);
    expect(Artisan::call('mt5:check'))->toBe(1);
    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($result['connection'])->toBe('unavailable')->and($result['data_ready'])->toBeFalse();
    Mail::assertNothingSent();
});

test('MT5 diagnostic reports a connected closed market without calling stale data current', function () {
    $snapshot = connectionCheckSnapshot();
    $this->travelTo(Carbon::parse('2026-11-08T12:15:20Z'));
    $snapshot['observed_at'] = now()->utc()->toIso8601String();
    Http::fake(['http://127.0.0.1:8710/snapshot' => Http::response($snapshot)]);
    expect(Artisan::call('mt5:check'))->toBe(0);
    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($result['connection'])->toBe('connected')->and($result['market']['open'])->toBeFalse()
        ->and($result['data_ready'])->toBeFalse()->and($result)->not->toHaveKey('quote');
    Mail::assertNothingSent();
});

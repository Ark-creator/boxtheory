<?php

use App\Services\GoldBreakoutStrategy;
use App\Services\GoldStrategyBacktester;
use Illuminate\Support\Carbon;

function goldExtensionFixture(string $side = 'buy', float $close = 2001.5, float $high = 2002): array
{
    $at = Carbon::parse('2026-09-14T10:00:00Z');
    $hours = [];
    $quarters = [];
    for ($i = 0; $i < 300; $i++) {
        $hourClose = 1970 + $i * 0.1;
        $hours[] = ['timestamp' => $at->copy()->subHours(300 - $i)->toIso8601String(), 'open' => $hourClose - 0.1, 'high' => $hourClose + 1, 'low' => $hourClose - 1, 'close' => $hourClose];
        if ($i >= 250) {
            $quarters[] = ['timestamp' => $at->copy()->subMinutes((300 - $i) * 15)->toIso8601String(), 'open' => 1998.9, 'high' => 2000, 'low' => 1998, 'close' => 1999];
        }
    }
    $quarters[49] = array_replace($quarters[49], ['open' => 1999, 'high' => $high, 'low' => 1998.8, 'close' => $close]);
    if ($side === 'sell') {
        $mirror = fn (array $bars) => array_map(fn (array $bar) => [
            'timestamp' => $bar['timestamp'], 'open' => 4000 - $bar['open'],
            'high' => 4000 - $bar['low'], 'low' => 4000 - $bar['high'], 'close' => 4000 - $bar['close'],
        ], $bars);
        $quarters = $mirror($quarters);
        $hours = $mirror($hours);
    }

    return [$quarters, $hours, $at];
}

test('entry extension accepts the exact boundary and preserves the original protective prices', function (string $side, float $entry, float $stop, float $target) {
    [$m15, $h1, $at] = goldExtensionFixture($side);
    $signal = (new GoldBreakoutStrategy)->evaluate($m15, $h1, $at);

    expect($signal['action'])->toBe(strtoupper($side));
    expect($signal['indicators']['atr_prior'])->toEqual(2.0);
    expect($signal['indicators']['max_entry_extension_atr'])->toBe(0.75);
    expect($signal['trade_plan']['entry_limit_price'])->toBe($entry);
    expect($signal['trade_plan']['entry_price'])->toBe($entry);
    // Regression values from the original full-candle ATR and structural-stop formula.
    expect($signal['trade_plan']['stop_loss'])->toBe($stop);
    expect($signal['trade_plan']['take_profit_1'])->toBe($target);
    expect($signal['trade_plan']['risk_per_unit'])->toBe(3.91714);
    expect($signal['trade_plan']['rr_tp1'])->toBe(2.0);
})->with([
    'long boundary' => ['buy', 2001.5, 1997.58286, 2009.33429],
    'short boundary' => ['sell', 1998.5, 2002.41714, 1990.66571],
]);

test('entry extension rejects a close just beyond the boundary', function (string $side) {
    [$m15, $h1, $at] = goldExtensionFixture($side, 2001.51);
    $signal = (new GoldBreakoutStrategy)->evaluate($m15, $h1, $at);

    expect($signal['action'])->toBe('WAIT');
    expect(strtolower($signal['message']))->toContain('extension');
})->with(['buy', 'sell']);

test('a larger signal candle cannot inflate its own entry extension allowance', function (string $side) {
    [$m15, $h1, $at] = goldExtensionFixture($side, 2001.6, 2003.6);
    $signal = (new GoldBreakoutStrategy)->evaluate($m15, $h1, $at);

    // Including this candle would raise ATR enough to admit the 1.6 price-unit extension.
    expect($signal['indicators']['atr'])->toBeGreaterThan(1.6 / 0.75);
    expect($signal['indicators']['atr_prior'])->toEqual(2.0);
    expect($signal['action'])->toBe('WAIT');
    expect(strtolower($signal['message']))->toContain('extension');
})->with(['buy', 'sell']);

test('entry extension does not interrupt management of an existing position', function (string $side, string $position) {
    [$m15, $h1, $at] = goldExtensionFixture($side, 2001.6);

    expect((new GoldBreakoutStrategy)->evaluate($m15, $h1, $at, $position)['action'])->toBe('HOLD');
})->with([
    'existing long' => ['buy', 'long'],
    'existing short' => ['sell', 'short'],
]);

test('the configured entry extension changes admission without changing accepted protective prices', function (string $side, float $limit) {
    [$m15, $h1, $at] = goldExtensionFixture($side);
    $default = (new GoldBreakoutStrategy)->evaluate($m15, $h1, $at);
    $stricter = (new GoldBreakoutStrategy(0.5))->evaluate($m15, $h1, $at);
    $looser = (new GoldBreakoutStrategy(1.0))->evaluate($m15, $h1, $at);

    expect($stricter['action'])->toBe('WAIT');
    expect($looser['action'])->toBe(strtoupper($side));
    expect($looser['indicators']['max_entry_extension_atr'])->toBe(1.0);
    expect($looser['trade_plan']['entry_limit_price'])->toBe($limit);
    foreach (['entry_price', 'stop_loss', 'take_profit_1', 'risk_per_unit'] as $field) {
        expect($looser['trade_plan'][$field])->toBe($default['trade_plan'][$field]);
    }
})->with([
    'long configuration' => ['buy', 2002.0],
    'short configuration' => ['sell', 1998.0],
]);

test('entry extension refuses invalid configuration', function (float $limit) {
    expect(fn () => new GoldBreakoutStrategy($limit))->toThrow(InvalidArgumentException::class);
})->with([
    'zero' => [0.0],
    'negative' => [-0.75],
    'positive infinity' => [INF],
    'negative infinity' => [-INF],
    'not a number' => [NAN],
]);

test('backtesting checks entry extension on the executable price including entry costs', function (string $side, float $open, float $spread, float $slippage, bool $rejected) {
    [$m15, $h1, $at] = goldExtensionFixture($side);
    $m15[] = ['timestamp' => $at->toIso8601String(), 'open' => $open, 'high' => $open + 0.05, 'low' => $open - 0.05, 'close' => $open];
    $report = (new GoldStrategyBacktester)->run(['source' => 'mt5', 'm15' => $m15, 'h1' => $h1], $spread, $slippage, 0);

    expect($report['signals'])->toBe(1);
    expect($report['closed_trades'])->toBe(0);
    expect($report['parameters']['max_entry_extension_atr'])->toBe(0.75);
    expect($report['entry_extension_rejections'])->toBe($rejected ? 1 : 0);
    if ($rejected) {
        expect($report['open_position_at_end'])->toBeNull();
    } else {
        expect($report['open_position_at_end'])->not->toBeNull();
        expect($report['open_position_at_end']['entry'])->toBe($side === 'buy' ? 2001.5 : 1998.5);
    }
})->with([
    'long opening gap over limit' => ['buy', 2001.6, 0.0, 0.0, true],
    'short opening gap over limit' => ['sell', 1998.4, 0.0, 0.0, true],
    'long ask spread over limit' => ['buy', 2001.5, 0.1, 0.0, true],
    'short entry remains bid despite spread' => ['sell', 1998.5, 0.1, 0.0, false],
    'long slippage over limit' => ['buy', 2001.5, 0.0, 0.1, true],
    'short slippage over limit' => ['sell', 1998.5, 0.0, 0.1, true],
    'long exact limit' => ['buy', 2001.5, 0.0, 0.0, false],
    'short exact limit' => ['sell', 1998.5, 0.0, 0.0, false],
    'long ask at exact limit' => ['buy', 2001.3, 0.2, 0.0, false],
    'long slippage at exact limit' => ['buy', 2001.4, 0.0, 0.1, false],
    'short slippage at exact limit' => ['sell', 1998.6, 0.0, 0.1, false],
]);

test('backtesting applies and reports the selected strategy parameter', function () {
    [$m15, $h1, $at] = goldExtensionFixture();
    $m15[] = ['timestamp' => $at->toIso8601String(), 'open' => 2001.6, 'high' => 2001.7, 'low' => 2001.5, 'close' => 2001.6];
    $report = (new GoldStrategyBacktester(new GoldBreakoutStrategy(1.0)))->run(['source' => 'mt5', 'm15' => $m15, 'h1' => $h1], 0, 0, 0);

    expect($report['parameters']['max_entry_extension_atr'])->toBe(1.0);
    expect($report['entry_extension_rejections'])->toBe(0);
    expect($report['open_position_at_end']['entry'])->toBe(2001.6);
});

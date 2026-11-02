<?php

use App\Services\GoldTrendRetestStrategy;
use Illuminate\Support\Carbon;

function retestResearchFixture(): array
{
    $at = Carbon::parse('2026-09-14T10:00:00Z');
    $m15 = [];
    $h1 = [];
    for ($i = 0; $i < 300; $i++) {
        $m15[] = [
            'timestamp' => $at->copy()->subMinutes((300 - $i) * 15)->toIso8601String(),
            'open' => 1999, 'high' => 2000, 'low' => 1998, 'close' => 1999,
        ];
        $close = 1970 + $i * 0.1;
        $h1[] = [
            'timestamp' => $at->copy()->subHours(300 - $i)->toIso8601String(),
            'open' => $close - 0.1, 'high' => $close + 1, 'low' => $close - 1, 'close' => $close,
        ];
    }
    $m15[298] = array_replace($m15[298], ['open' => 1999.8, 'high' => 2000.8, 'low' => 1999.7, 'close' => 2000.5]);
    $m15[299] = array_replace($m15[299], ['open' => 2000.0, 'high' => 2000.8, 'low' => 1999.8, 'close' => 2000.4]);

    return [$m15, $h1, $at];
}

function mirrorRetestResearchBars(array $bars): array
{
    return array_map(fn ($bar) => array_replace($bar, [
        'open' => 4000 - $bar['open'], 'high' => 4000 - $bar['low'],
        'low' => 4000 - $bar['high'], 'close' => 4000 - $bar['close'],
    ]), $bars);
}

test('research breakout alone waits and the first retest produces a bounded 2R plan', function () {
    [$m15, $h1, $at] = retestResearchFixture();
    $engine = new GoldTrendRetestStrategy;
    $breakout = $engine->evaluate(array_slice($m15, 0, -1), $h1, $at->copy()->subMinutes(15));
    $signal = $engine->evaluate($m15, $h1, $at);

    expect($breakout['action'])->toBe('WAIT')
        ->and($signal['action'])->toBe('BUY')
        ->and($signal['setup_id'])->toBe($breakout['setup_id'])
        ->and($signal['timestamp'])->toBe('2026-09-14T10:00:00+00:00')
        ->and($signal['expires_at'])->toBe('2026-09-14T10:03:00+00:00');
    $plan = $signal['trade_plan'];
    expect($plan['stop_loss'])->toBeLessThan($plan['entry_min_price'])
        ->and($plan['entry_min_price'])->toBeLessThanOrEqual($plan['entry_price'])
        ->and($plan['entry_max_price'])->toBeGreaterThanOrEqual($plan['entry_price'])
        ->and($plan['entry_max_price'])->toBeLessThanOrEqual(2001.0)
        ->and(round(($plan['take_profit_1'] - $plan['entry_price']) / ($plan['entry_price'] - $plan['stop_loss']), 5))->toBe(2.0)
        ->and($signal['indicators']['breakout_atr'])->toBe(2.0);
});

test('research shorts mirror entry bounds and stop geometry', function () {
    [$m15, $h1, $at] = retestResearchFixture();
    $signal = (new GoldTrendRetestStrategy)->evaluate(mirrorRetestResearchBars($m15), mirrorRetestResearchBars($h1), $at);
    $plan = $signal['trade_plan'];
    expect($signal['action'])->toBe('SELL')
        ->and($plan['stop_loss'])->toBeGreaterThan($plan['entry_max_price'])
        ->and($plan['take_profit_1'])->toBeLessThan($plan['entry_price'])
        ->and($plan['entry_min_price'])->toBeGreaterThanOrEqual(1999.0)
        ->and($plan['entry_max_price'])->toBeLessThanOrEqual(2000.0);
});

test('research future M15 and H1 bars cannot alter a closed retest signal', function () {
    [$m15, $h1, $at] = retestResearchFixture();
    $engine = new GoldTrendRetestStrategy;
    $before = $engine->evaluate($m15, $h1, $at);
    $future = ['timestamp' => $at->toIso8601String(), 'open' => 2000, 'high' => 9000, 'low' => 1, 'close' => 8000];
    $m15[] = $future;
    $h1[] = $future;
    expect($engine->evaluate($m15, $h1, $at))->toBe($before);
});

test('research history and prefix evaluation agree without future H1 authorization', function () {
    [$m15, $h1, $at] = retestResearchFixture();
    $engine = new GoldTrendRetestStrategy;
    $history = $engine->evaluateHistory($m15, $h1);
    expect($history)->toHaveCount(1)
        ->and($history[$at->toIso8601String()])->toBe($engine->evaluate($m15, $h1, $at));

    // H1 only becomes bullish at 10:00, after the 09:45 breakout close.
    foreach ($h1 as &$bar) {
        $bar = array_replace($bar, ['open' => 1999, 'high' => 2000, 'low' => 1998, 'close' => 1999]);
    }
    unset($bar);
    $h1[299] = array_replace($h1[299], ['open' => 1999, 'high' => 2003, 'low' => 1998, 'close' => 2002]);
    expect($engine->evaluateHistory($m15, $h1))->toBe([])
        ->and($engine->evaluate($m15, $h1, $at)['action'])->toBe('WAIT');
});

test('research first retest is consumed and later repeated retests cannot retrigger it', function () {
    [$m15, $h1, $at] = retestResearchFixture();
    $engine = new GoldTrendRetestStrategy;
    $first = $engine->evaluate($m15, $h1, $at);
    expect($engine->evaluate($m15, $h1, $at->copy()->addSeconds(30))['setup_id'])->toBe($first['setup_id']);
    for ($i = 0; $i < 5; $i++) {
        $m15[] = [
            'timestamp' => $at->copy()->addMinutes($i * 15)->toIso8601String(),
            'open' => 2000.1, 'high' => 2001 + $i * 0.2, 'low' => 1999.9, 'close' => 2000.5 + $i * 0.1,
        ];
    }
    $h1[] = ['timestamp' => $at->toIso8601String(), 'open' => 2000, 'high' => 2002, 'low' => 1999, 'close' => 2001];
    $later = $engine->evaluate($m15, $h1, $at->copy()->addMinutes(75));
    expect($later['action'])->toBe('WAIT')
        ->and($later['setup_id'])->toBe($first['setup_id'])
        ->and($engine->evaluateHistory($m15, $h1))->toHaveCount(1);
});

test('research oversized structural stop rejects the first retest without truncation or replacement', function () {
    [$m15, $h1, $at] = retestResearchFixture();
    $m15[297] = array_replace($m15[298], ['timestamp' => $m15[297]['timestamp']]);
    $m15[298] = array_replace($m15[298], ['open' => 1999.8, 'high' => 2000.4, 'low' => 1993, 'close' => 1999.8]);
    $engine = new GoldTrendRetestStrategy;
    $signal = $engine->evaluate($m15, $h1, $at);
    expect($signal['action'])->toBe('WAIT')
        ->and($signal['message'])->toContain('structural stop')
        ->and($signal['trade_plan'])->toBe([]);
    $m15[] = ['timestamp' => $at->toIso8601String(), 'open' => 2000, 'high' => 2000.5, 'low' => 1999.9, 'close' => 2000.3];
    expect($engine->evaluate($m15, $h1, $at->copy()->addMinutes(15))['action'])->toBe('WAIT')
        ->and($engine->evaluateHistory($m15, $h1))->toBe([]);
});

test('research stale data existing exposure invalid OHLC and misaligned bars are rejected', function () {
    [$m15, $h1, $at] = retestResearchFixture();
    $engine = new GoldTrendRetestStrategy;
    expect($engine->evaluate($m15, $h1, $at->copy()->addMinutes(3))['action'])->toBe('NO_DATA')
        ->and($engine->evaluate($m15, $h1, $at, 'long')['action'])->toBe('HOLD');
    $misaligned = $m15;
    $misaligned[299]['timestamp'] = $at->copy()->subMinutes(14)->toIso8601String();
    expect($engine->evaluate($misaligned, $h1, $at)['action'])->toBe('NO_DATA');
    $m15[298]['high'] = 1990;
    expect($engine->evaluate($m15, $h1, $at)['action'])->toBe('NO_DATA');
});

test('research elapsed expiry and missing M15 bars cannot be bridged by a late retest', function () {
    [$m15, $h1, $at] = retestResearchFixture();
    // A missing bar separates breakout and retest, even though both remain fresh at evaluation.
    $m15[299]['timestamp'] = $at->toIso8601String();
    $signal = (new GoldTrendRetestStrategy)->evaluate($m15, $h1, $at->copy()->addMinutes(15));
    expect($signal['action'])->toBe('WAIT');

    [$m15, $h1, $at] = retestResearchFixture();
    $m15[299]['timestamp'] = $at->copy()->addMinutes(60)->toIso8601String();
    $h1[] = ['timestamp' => $at->toIso8601String(), 'open' => 2000, 'high' => 2002, 'low' => 1999, 'close' => 2001];
    expect((new GoldTrendRetestStrategy)->evaluate($m15, $h1, $at->copy()->addMinutes(75))['action'])->toBe('WAIT');
});

test('research retest expiry accepts the fourth consecutive bar and rejects the fifth', function (int $breakoutIndex, string $expected) {
    [$m15, $h1, $at] = retestResearchFixture();
    $breakout = $m15[298];
    for ($i = $breakoutIndex; $i < 299; $i++) {
        $m15[$i] = array_replace($m15[$i], ['open' => 2000.2, 'high' => 2000.8, 'low' => 2000.1, 'close' => 2000.2]);
    }
    $m15[$breakoutIndex] = array_replace($breakout, ['timestamp' => $m15[$breakoutIndex]['timestamp']]);

    expect((new GoldTrendRetestStrategy)->evaluate($m15, $h1, $at)['action'])->toBe($expected);
})->with([[295, 'BUY'], [294, 'WAIT']]);

test('research consumed breakout rearms only after an inside close and then permits a new setup', function () {
    [$m15, $h1, $at] = retestResearchFixture();
    $engine = new GoldTrendRetestStrategy;
    $first = $engine->evaluate($m15, $h1, $at);
    $m15[] = ['timestamp' => $at->toIso8601String(), 'open' => 2000.2, 'high' => 2000.3, 'low' => 1999.5, 'close' => 1999.7];
    $m15[] = ['timestamp' => $at->copy()->addMinutes(15)->toIso8601String(), 'open' => 2000.5, 'high' => 2001.3, 'low' => 2000.4, 'close' => 2001.1];
    $m15[] = ['timestamp' => $at->copy()->addMinutes(30)->toIso8601String(), 'open' => 2000.8, 'high' => 2001.2, 'low' => 2000.7, 'close' => 2001.0];
    $second = $engine->evaluate($m15, $h1, $at->copy()->addMinutes(45));

    expect($second['action'])->toBe('BUY')
        ->and($second['setup_id'])->not->toBe($first['setup_id'])
        ->and($engine->evaluateHistory($m15, $h1))->toHaveCount(2);
});

test('research timestamp validation cannot be overridden by an extra caller-supplied epoch', function () {
    [$m15, $h1, $at] = retestResearchFixture();
    $engine = new GoldTrendRetestStrategy;
    $before = $engine->evaluate($m15, $h1, $at);
    $m15[299]['time'] = $at->copy()->addYears(10)->getTimestamp();
    $h1[299]['time'] = $at->copy()->addYears(10)->getTimestamp();

    expect($engine->evaluate($m15, $h1, $at))->toBe($before);
});

test('research rejects malformed calendar timestamps instead of silently normalizing them', function (string $timestamp) {
    [$m15, $h1, $at] = retestResearchFixture();
    $m15[299]['timestamp'] = $timestamp;
    $engine = new GoldTrendRetestStrategy;

    expect($engine->evaluate($m15, $h1, $at)['action'])->toBe('NO_DATA');
    expect(fn () => $engine->evaluateHistory($m15, $h1))->toThrow(InvalidArgumentException::class);
})->with([
    'impossible day' => '2026-02-30T09:45:00Z',
    'invalid hour' => '2026-09-13T24:00:00Z',
    'relative date' => 'tomorrow 09:45',
    'date only' => '2026-09-14',
    'fractional second' => '2026-09-14T09:45:00.500Z',
    'invalid offset' => '2026-09-14T09:45:00+24:00',
    'invalid offset minute' => '2026-09-14T09:45:00+08:99',
]);

test('research accepts explicit offsets and UTC-default opening timestamps without changing signals', function (string $format, string $zone) {
    [$m15, $h1, $at] = retestResearchFixture();
    $engine = new GoldTrendRetestStrategy;
    $before = $engine->evaluate($m15, $h1, $at);
    $convert = fn ($bars) => array_map(fn ($bar) => array_replace($bar, [
        'timestamp' => Carbon::parse($bar['timestamp'])->setTimezone($zone)->format($format),
    ]), $bars);

    expect($engine->evaluate($convert($m15), $convert($h1), $at))->toBe($before);
})->with([
    'UTC default' => ['Y-m-d H:i:s', 'UTC'],
    'Z suffix' => ['Y-m-d\TH:i:s\Z', 'UTC'],
    'explicit offset' => ['Y-m-d\TH:i:sP', 'Asia/Manila'],
]);

test('research weekend breakouts and retests never produce an entry', function () {
    [$m15, $h1, $at] = retestResearchFixture();
    $shift = 5 * 86400;
    foreach ([$m15, $h1] as $index => $frame) {
        $frame = array_map(fn ($bar) => array_replace($bar, [
            'timestamp' => Carbon::parse($bar['timestamp'])->addSeconds($shift)->toIso8601String(),
        ]), $frame);
        if ($index === 0) {
            $m15 = $frame;
        } else {
            $h1 = $frame;
        }
    }
    expect((new GoldTrendRetestStrategy)->evaluate($m15, $h1, $at->copy()->addDays(5))['action'])->toBe('WAIT')
        ->and((new GoldTrendRetestStrategy)->evaluateHistory($m15, $h1))->toBe([]);
});

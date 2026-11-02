<?php

use App\Services\GoldTrendRetestStrategy;
use App\Services\GoldTrendRetestV2Strategy;
use Illuminate\Support\Carbon;

function retestV2Fixture(int $count = 400): array
{
    $at = Carbon::parse('2026-09-25T10:00:00Z');
    $m15 = [];
    $h1 = [];
    for ($i = 0; $i < $count; $i++) {
        $m15[] = ['timestamp' => $at->copy()->subMinutes(($count - $i) * 15)->toIso8601String(),
            'open' => 1999, 'high' => 2000, 'low' => 1998, 'close' => 1999];
    }
    for ($i = 0; $i < 650; $i++) {
        $close = 1970 + $i * 0.1;
        $h1[] = ['timestamp' => $at->copy()->subHours(650 - $i)->toIso8601String(),
            'open' => $close - 0.1, 'high' => $close + 1, 'low' => $close - 1, 'close' => $close];
    }
    $m15[$count - 2] = array_replace($m15[$count - 2], ['open' => 1999.8, 'high' => 2000.8, 'low' => 1999.7, 'close' => 2000.5]);
    $m15[$count - 1] = array_replace($m15[$count - 1], ['open' => 2000.0, 'high' => 2000.8, 'low' => 1999.8, 'close' => 2000.4]);

    return [$m15, $h1, $at];
}

function mirrorRetestV2Bars(array $bars): array
{
    return array_map(fn ($bar) => array_replace($bar, ['open' => 4000 - $bar['open'], 'high' => 4000 - $bar['low'],
        'low' => 4000 - $bar['high'], 'close' => 4000 - $bar['close']]), $bars);
}

test('V2 identifies itself and preserves structural stops a 2R target and strict entry bounds', function (bool $short) {
    [$m15, $h1, $at] = retestV2Fixture();
    if ($short) {
        $m15 = mirrorRetestV2Bars($m15);
        $h1 = mirrorRetestV2Bars($h1);
    }
    $signal = (new GoldTrendRetestV2Strategy)->evaluate($m15, $h1, $at);
    $plan = $signal['trade_plan'];
    $side = $short ? -1 : 1;

    expect($signal['strategy'])->toBe('gold-trend-retest-v2')
        ->and($signal['action'])->toBe($short ? 'SELL' : 'BUY')
        ->and($plan['setup_id'])->toBe($signal['setup_id'])
        ->and(($plan['entry_price'] - $plan['stop_loss']) * $side)->toBeGreaterThan(0)
        ->and(round(($plan['take_profit_1'] - $plan['entry_price']) / ($plan['entry_price'] - $plan['stop_loss']), 5))->toBe(2.0)
        ->and($plan['entry_min_price'])->toBeLessThanOrEqual($plan['entry_price'])
        ->and($plan['entry_max_price'])->toBeGreaterThanOrEqual($plan['entry_price']);
    $old = (new GoldTrendRetestStrategy)->evaluate($m15, $h1, $at);
    expect($signal['setup_id'])->not->toBe($old['setup_id']);
})->with([false, true]);

test('V2 full history prefix and rolling 320-bar inputs produce exactly the same snapshot', function () {
    [$m15, $h1, $at] = retestV2Fixture();
    // Nonconstant prices make differing ATR/EMA starting points observable.
    foreach ($m15 as $i => &$bar) {
        if ($i < 398) {
            $bar['low'] = 1998 - ($i % 7) * 0.03;
        }
    }
    unset($bar);
    $engine = new GoldTrendRetestV2Strategy;
    $full = $engine->evaluate($m15, $h1, $at);
    $rolling = $engine->evaluate(array_slice($m15, -320), array_slice($h1, -320), $at);
    $history = $engine->evaluateHistory($m15, $h1);

    expect($full['action'])->toBe('BUY')
        ->and($rolling)->toBe($full)
        ->and($history[$at->toIso8601String()])->toBe($full);

    $future = ['timestamp' => $at->toIso8601String(), 'open' => 2000, 'high' => 9000, 'low' => 1, 'close' => 8000];
    $m15[] = $future;
    $h1[] = $future;
    expect($engine->evaluate($m15, $h1, $at))->toBe($full)
        ->and($engine->evaluateHistory($m15, $h1)[$at->toIso8601String()])->toBe($full);
});

test('V2 does not retain a days-old consumed anchor and agrees across rolling history boundaries', function () {
    [$m15, $h1, $at] = retestV2Fixture(620);
    $m15[250] = array_replace($m15[250], ['open' => 1999.8, 'high' => 2000.8, 'low' => 1999.7, 'close' => 2000.5]);
    $m15[251] = array_replace($m15[251], ['open' => 2000, 'high' => 2000.8, 'low' => 1999.8, 'close' => 2000.4]);
    for ($i = 252; $i < 618; $i++) {
        $m15[$i] = array_replace($m15[$i], ['open' => 2000.3, 'high' => 2001.2, 'low' => 1999.9, 'close' => 2000.3]);
    }
    $m15[618] = array_replace($m15[618], ['open' => 2000.7, 'high' => 2001.7, 'low' => 2000.6, 'close' => 2001.5]);
    $m15[619] = array_replace($m15[619], ['open' => 2001, 'high' => 2001.7, 'low' => 2001, 'close' => 2001.4]);
    $v1 = new GoldTrendRetestStrategy;
    $v2 = new GoldTrendRetestV2Strategy;

    expect($v1->evaluate($m15, $h1, $at)['action'])->toBe('WAIT')
        ->and($v1->evaluate(array_slice($m15, -320), array_slice($h1, -320), $at)['action'])->toBe('BUY');
    $full = $v2->evaluate($m15, $h1, $at);
    expect($full['action'])->toBe('BUY')
        ->and($v2->evaluate(array_slice($m15, -320), array_slice($h1, -320), $at))->toBe($full);
});

test('V2 breakout alone waits and an already-qualified breakout cannot issue a later retest', function () {
    [$m15, $h1, $at] = retestV2Fixture();
    $engine = new GoldTrendRetestV2Strategy;
    expect($engine->evaluate(array_slice($m15, 0, -1), $h1, $at->copy()->subMinutes(15))['action'])->toBe('WAIT');
    $first = $engine->evaluate($m15, $h1, $at);
    $m15[] = ['timestamp' => $at->toIso8601String(), 'open' => 2000, 'high' => 2000.7, 'low' => 1999.9, 'close' => 2000.3];
    expect($engine->evaluate($m15, $h1, $at->copy()->addMinutes(15))['action'])->toBe('WAIT')
        ->and($engine->evaluateHistory($m15, $h1))->toHaveCount(1)
        ->and($engine->evaluateHistory($m15, $h1)[$at->toIso8601String()]['setup_id'])->toBe($first['setup_id']);
});

test('V2 honors the fourth retest bar and rejects a fifth-bar retest', function (int $index, string $action) {
    [$m15, $h1, $at] = retestV2Fixture();
    $breakout = $m15[398];
    for ($i = $index; $i < 399; $i++) {
        $m15[$i] = array_replace($m15[$i], ['open' => 2000.2, 'high' => 2000.8, 'low' => 2000.1, 'close' => 2000.2]);
    }
    $m15[$index] = array_replace($breakout, ['timestamp' => $m15[$index]['timestamp']]);
    expect((new GoldTrendRetestV2Strategy)->evaluate($m15, $h1, $at)['action'])->toBe($action);
})->with([[395, 'BUY'], [394, 'WAIT']]);

test('V2 does not authorize an earlier breakout using a later H1 trend', function () {
    [$m15, $h1, $at] = retestV2Fixture();
    foreach ($h1 as &$bar) {
        $bar = array_replace($bar, ['open' => 1999, 'high' => 2000, 'low' => 1998, 'close' => 1999]);
    }
    unset($bar);
    $h1[649] = array_replace($h1[649], ['high' => 2003, 'close' => 2002]);
    expect((new GoldTrendRetestV2Strategy)->evaluate($m15, $h1, $at)['action'])->toBe('WAIT');
});

test('V2 rejects invalidation and gaps between breakout and retest', function (string $fault) {
    [$m15, $h1, $at] = retestV2Fixture();
    $m15[396] = array_replace($m15[398], ['timestamp' => $m15[396]['timestamp']]);
    $m15[397] = array_replace($m15[397], ['open' => 2000.1, 'high' => 2000.7, 'low' => 1999.9, 'close' => 2000.1]);
    $m15[398] = array_replace($m15[398], ['open' => 2000.1, 'high' => 2000.7, 'low' => 1999.9, 'close' => 2000.1]);
    if ($fault === 'invalidation') {
        $m15[398] = array_replace($m15[398], ['open' => 1999.4, 'low' => 1999.3, 'close' => 1999.4]);
    } else {
        array_splice($m15, 398, 1);
    }
    expect((new GoldTrendRetestV2Strategy)->evaluate($m15, $h1, $at)['action'])->toBe('WAIT');
})->with(['invalidation', 'missing bar']);

test('V2 rejects the oldest qualifying retest without chasing a younger candidate on the same bar', function () {
    [$m15, $h1, $at] = retestV2Fixture();
    $baseline = $m15[395];
    $m15[395] = array_replace($m15[395], ['open' => 1999.8, 'high' => 2000.8, 'low' => 1996, 'close' => 2000.5]);
    $m15[396] = array_replace($m15[396], ['open' => 2000.6, 'high' => 2000.8, 'low' => 2000.3, 'close' => 2000.6]);
    $m15[397] = array_replace($m15[397], ['open' => 2000.8, 'high' => 2001.4, 'low' => 2000.5, 'close' => 2001.2]);
    $m15[398] = array_replace($m15[398], ['open' => 2001.1, 'high' => 2001.4, 'low' => 2000.4, 'close' => 2001.1]);
    $m15[399] = array_replace($m15[399], ['open' => 2000.8, 'high' => 2001.4, 'low' => 2000.1, 'close' => 2001.1]);
    $engine = new GoldTrendRetestV2Strategy;
    $rejected = $engine->evaluate($m15, $h1, $at);
    expect($rejected['action'])->toBe('WAIT')
        ->and($rejected['message'])->toContain('First retest rejected')
        ->and($rejected['indicators']['breakout_at'])->toBe('2026-09-25T09:00:00+00:00');
    $m15[395] = $baseline;
    expect($engine->evaluate($m15, $h1, $at)['action'])->toBe('BUY');
});

test('V2 fails closed on insufficient reproducible warmup invalid candles or expired signal evaluation', function () {
    [$m15, $h1, $at] = retestV2Fixture();
    $engine = new GoldTrendRetestV2Strategy;
    expect($engine->evaluate(array_slice($m15, -319), $h1, $at)['action'])->toBe('NO_DATA')
        ->and($engine->evaluate($m15, array_slice($h1, -319), $at)['action'])->toBe('NO_DATA')
        ->and($engine->evaluate($m15, $h1, $at->copy()->addMinutes(3))['action'])->toBe('NO_DATA')
        ->and($engine->evaluate($m15, $h1, $at, 'long')['action'])->toBe('HOLD');
    $m15[398]['timestamp'] = '2026-02-30T09:30:00Z';
    expect($engine->evaluate($m15, $h1, $at)['action'])->toBe('NO_DATA');
});

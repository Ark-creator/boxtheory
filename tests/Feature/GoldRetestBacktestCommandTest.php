<?php

use App\Services\GoldRetestBacktester;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->exportFile = tempnam(sys_get_temp_dir(), 'gold-retest-');
    file_put_contents($this->exportFile, '{}');
});

afterEach(function () {
    if (is_file($this->exportFile)) {
        unlink($this->exportFile);
    }
});

test('retest command rejects invalid evaluation dates instead of shifting the test period', function (string $date) {
    $this->artisan('gold:retest-backtest', ['file' => $this->exportFile,
        '--spread' => '0', '--slippage' => '0', '--fee-per-unit' => '0', '--from' => $date])
        ->assertExitCode(1);
})->with(['2026-02-30', '2026-11-01T24:00:00Z', '2026-11-01T00:00:00+99:00', '2026-11-01T00:00:00+01:99', 'tomorrow', '2026-11-01T10:00:00']);

test('retest command requires all execution costs to be deliberately specified', function () {
    $this->artisan('gold:retest-backtest', ['file' => $this->exportFile])
        ->expectsOutputToContain('Explicit --spread, --slippage and --fee-per-unit')
        ->assertExitCode(1);
});

test('retest command passes an explicit UTC holdout and costs to offline simulation', function () {
    $tester = Mockery::mock(GoldRetestBacktester::class);
    $tester->shouldReceive('run')->once()->withArgs(function ($data, $spread, $slippage, $fee, $from, $to) {
        return $data === [] && $spread === 0.3 && $slippage === 0.1 && $fee === 0.07
            && $from instanceof Carbon && $from->toIso8601String() === '2026-09-14T00:00:00+00:00'
            && $to instanceof Carbon && $to->toIso8601String() === '2026-11-01T00:00:00+00:00';
    })->andReturn(['strategy' => 'gold-trend-retest', 'simulation' => 'test double']);
    $this->app->instance(GoldRetestBacktester::class, $tester);
    $this->artisan('gold:retest-backtest', ['file' => $this->exportFile,
        '--spread' => '0.3', '--slippage' => '0.1', '--fee-per-unit' => '0.07',
        '--from' => '2026-09-14', '--to' => '2026-11-01T08:00:00+08:00'])
        ->expectsOutputToContain('gold-trend-retest')->assertSuccessful();
});

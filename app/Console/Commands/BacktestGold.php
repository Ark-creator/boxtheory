<?php

namespace App\Console\Commands;

use App\Services\GoldStrategyBacktester;
use Illuminate\Console\Command;

class BacktestGold extends Command
{
    protected $signature = 'gold:backtest {file : Path to MT5 JSON candle export} {--spread=0.30} {--slippage=0.10} {--fee-per-unit=0.07}';

    protected $description = 'Run a historical signal diagnostic with costs; no orders or emails are sent';

    public function handle(GoldStrategyBacktester $tester): int
    {
        try {
            $file = (string) $this->argument('file');
            if (! is_file($file) || ! is_readable($file)) {
                throw new \InvalidArgumentException('Candle export is not readable.');
            }
            foreach (['spread', 'slippage', 'fee-per-unit'] as $option) {
                if (! is_numeric($this->option($option))) {
                    throw new \InvalidArgumentException('Costs must be numeric.');
                }
            }
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $result = $tester->run($data, (float) $this->option('spread'), (float) $this->option('slippage'), (float) $this->option('fee-per-unit'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}

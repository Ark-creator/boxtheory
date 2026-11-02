<?php

namespace App\Console\Commands;

use App\Services\GoldRetestBacktester;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BacktestGoldRetest extends Command
{
    protected $signature = 'gold:retest-backtest {file : Read-only MT5 gold JSON export} {--spread= : USD/oz bid-ask spread} {--slippage= : USD/oz adverse slippage on each fill} {--fee-per-unit= : USD/oz round-trip commission} {--from= : Inclusive UTC evaluation start; retains earlier warmup} {--to= : Exclusive UTC evaluation end}';

    protected $description = 'Test Gold Trend Retest offline with explicit costs; emits JSON and never sends trades or alerts';

    public function handle(GoldRetestBacktester $tester): int
    {
        try {
            $file = (string) $this->argument('file');
            if (! is_file($file) || ! is_readable($file)) {
                throw new \InvalidArgumentException('MT5 candle export is not readable.');
            }
            foreach (['spread', 'slippage', 'fee-per-unit'] as $option) {
                if (! is_numeric($this->option($option))) {
                    throw new \InvalidArgumentException('Explicit --spread, --slippage and --fee-per-unit values are required; use 0 only deliberately.');
                }
            }
            $dates = [];
            foreach (['from', 'to'] as $option) {
                $value = $this->option($option);
                if ($value !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2}))?$/', $value)) {
                    throw new \InvalidArgumentException('Evaluation dates must be YYYY-MM-DD (UTC) or ISO8601 with an explicit timezone.');
                }
                $dates[$option] = null;
                if ($value !== null) {
                    if (preg_match('/[+-](\d{2}):(\d{2})$/', $value, $offset)
                        && ((int) $offset[1] > 14 || (int) $offset[2] > 59 || ((int) $offset[1] === 14 && (int) $offset[2] !== 0))) {
                        throw new \InvalidArgumentException('Evaluation timezone offsets must be valid UTC offsets within 14 hours.');
                    }
                    $format = strlen($value) === 10 ? '!Y-m-d' : '!Y-m-d\TH:i:sP';
                    $parsed = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
                    $errors = \DateTimeImmutable::getLastErrors();
                    if (! $parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                        throw new \InvalidArgumentException('Evaluation dates must be valid calendar dates and times.');
                    }
                    $dates[$option] = Carbon::instance($parsed)->utc();
                }
            }
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new \InvalidArgumentException('The candle export must be a JSON object.');
            }
            $result = $tester->run($data, (float) $this->option('spread'), (float) $this->option('slippage'), (float) $this->option('fee-per-unit'), $dates['from'], $dates['to']);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}

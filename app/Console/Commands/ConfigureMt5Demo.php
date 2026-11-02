<?php

namespace App\Console\Commands;

use App\Models\AutoTradeOrder;
use App\Models\AutoTraderSetting;
use App\Services\MarketDataGuard;
use App\Services\RsiGoldScalperV2Strategy;
use App\Services\TradingService;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Local demo bootstrap only. This command never runs the trader or sends orders. */
class ConfigureMt5Demo extends Command
{
    protected $signature = 'mt5:configure-demo {--stop : Disable the current RSI bridge trader only} {--bridge-env= : Local bridge environment file; defaults to mt5_bridge/.env}';

    protected $description = 'Verify the pinned local demo bridge before configuring RSI V2 automatic demo entries';

    private string $rejection = '';

    public function handle(TradingService $trading, MarketDataGuard $guard): int
    {
        $this->rejection = '';
        if ($this->option('stop')) {
            try {
                return $this->stopRsi();
            } catch (\Throwable) {
                return $this->result('rejected', 'The RSI setting could not be disabled; check the application database connection.', self::FAILURE);
            }
        }
        try {
            $lock = Cache::lock('auto-trader:execution', 120);
            if (! $lock->get()) {
                return $this->result('rejected', 'The auto trader is busy; retry configuration after its current run finishes.', self::FAILURE);
            }
        } catch (\Throwable) {
            return $this->result('rejected', 'The application execution lock is unavailable; configuration was not changed.', self::FAILURE);
        }
        $original = null;
        $envChanged = false;
        $appPath = $this->laravel->environmentFilePath();
        try {
            $bridgePath = (string) ($this->option('bridge-env') ?: base_path('mt5_bridge/.env'));
            $bridge = $this->parseEnvironment($this->readFile($bridgePath, 'A readable local bridge environment file is required.'));
            $token = (string) ($bridge['MT5_BRIDGE_TOKEN'] ?? '');
            $login = (string) ($bridge['MT5_LOGIN'] ?? '');
            $server = (string) ($bridge['MT5_SERVER'] ?? '');
            $symbol = (string) ($bridge['MT5_SYMBOL'] ?? '');
            $port = (string) ($bridge['MT5_BRIDGE_PORT'] ?? '');
            $magic = (string) ($bridge['MT5_MAGIC'] ?? '2601103');
            $this->insist((bool) preg_match('/^[\x21-\x7e]{32,512}$/D', $token), 'Bridge token must contain at least32 non-whitespace ASCII characters.');
            $this->insist((bool) preg_match('/^[1-9][0-9]*$/D', $login), 'A positive numeric pinned MT5 login is required.');
            $this->insist(trim($server) !== '' && ! preg_match('/[\r\n\x00]/', $server), 'A pinned MT5 server is required.');
            $this->insist(strlen($symbol) <= 40 && (bool) preg_match('/^XAU[\/_-]?USD(?:[._#-]?[A-Za-z0-9]+)?$/iD', $symbol), 'An exact broker XAUUSD symbol is required.');
            $this->insist(($bridge['MT5_BRIDGE_HOST'] ?? null) === '127.0.0.1', 'Demo bootstrap accepts only the127.0.0.1 loopback bridge.');
            $this->insist(ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535, 'A valid local bridge port is required.');
            $this->insist(ctype_digit($magic) && (float) $magic >= 1 && (float) $magic <= 2147483647, 'A positive supported MT5 magic number is required.');
            $this->insist($this->flag($bridge, 'MT5_ENABLE_EXECUTION') === 'true', 'The local bridge must explicitly enable execution before demo bootstrap.');
            $this->insist($this->flag($bridge, 'MT5_ALLOW_LIVE') === 'false', 'MT5_ALLOW_LIVE must explicitly be false.');
            $this->insist($this->flag($bridge, 'MT5_DEMO_ONLY') === 'true', 'MT5_DEMO_ONLY must explicitly be true.');
            $this->insist(! $this->unresolvedOrders(), 'Unresolved bridge orders require reconciliation before enabling demo entries.');
            $base = 'http://127.0.0.1:'.(int) $port;
            $response = Http::withToken($token)->acceptJson()->timeout(10)->withoutRedirecting()->get($base.'/snapshot');
            $this->insist($response->successful(), 'The local bridge did not return a successful verified snapshot.');
            $snapshot = $response->json();
            $this->insist(is_array($snapshot) && ($snapshot['status'] ?? null) === 'ok' && ($snapshot['source'] ?? null) === 'mt5', 'A valid native MT5 snapshot is required.');
            $this->insist(($snapshot['symbol'] ?? null) === $symbol, 'The snapshot symbol differs from the pinned local configuration.');
            $this->insist(($snapshot['account']['demo'] ?? null) === true, 'Only an explicitly verified DEMO account can be configured.');
            $key = hash('sha256', $login.':'.$server);
            $this->insist(is_string($snapshot['account_key'] ?? null) && hash_equals($key, $snapshot['account_key']), 'The snapshot account does not match the pinned login and server.');
            $capabilities = is_array($snapshot['capabilities'] ?? null) ? $snapshot['capabilities'] : [];
            foreach (['m5_candles', 'quote_timestamp', 'rsi_scalper_v2_demo_execution'] as $capability) {
                $this->insist(in_array($capability, $capabilities, true), 'Update the bridge: an RSI demo capability is missing.');
            }
            $observed = $this->snapshotTime($snapshot['observed_at'] ?? null);
            $this->insist($observed >= now()->utc()->subSeconds(30) && $observed <= now()->utc(), 'The MT5 snapshot is stale or future-dated.');
            $this->insist(is_array($snapshot['positions'] ?? null) && is_array($snapshot['pending_orders'] ?? null), 'Verified broker positions and pending orders are required.');
            $this->insist($snapshot['positions'] === [] && $snapshot['pending_orders'] === [], 'Existing broker positions or pending orders block automatic demo startup.');
            $this->insist($guard->marketStatus('XAUUSD', now()->utc())['open'], 'Gold is closed; configure when current market data can be verified.');
            $signal = $trading->evaluateRsiMt5Snapshot($snapshot, null, 'trend-session-v2');
            $this->insist(($signal['action'] ?? 'NO_DATA') !== 'NO_DATA' && ($signal['strategy'] ?? null) === RsiGoldScalperV2Strategy::SLUG
                && ($signal['strategy_version'] ?? null) === 2 && ($signal['freshness']['source'] ?? null) === 'mt5'
                && $guard->validateSignal($signal, now()->utc()) === null, 'The bridge needs current valid M5 history and a fresh source quote before enabling demo entries.');

            $original = $this->readFile($appPath, 'The application environment file must exist and be readable.');
            $parsed = $this->parseEnvironment($original);
            $this->insist(is_string($parsed['APP_KEY'] ?? null) && $parsed['APP_KEY'] !== '' && $parsed['APP_KEY'] === config('app.key'), 'APP_KEY must remain unchanged and match the active application encryption key.');
            $updates = ['RSI_GOLD_SCALPER_SOURCE' => 'mt5', 'RSI_GOLD_SCALPER_VERSION' => 'trend-session-v2',
                'AUTOTRADER_EXECUTION_MODE' => 'bridge', 'AUTOTRADER_STRATEGY_SLUG' => RsiGoldScalperV2Strategy::SLUG,
                'AUTOTRADER_BRIDGE_URL' => $base.'/trade', 'AUTOTRADER_BRIDGE_TOKEN' => $token,
                'AUTOTRADER_MT5_SYMBOL_CENT' => $symbol, 'AUTOTRADER_MT5_SYMBOL_DOLLAR' => $symbol, 'AUTOTRADER_MAGIC' => (string) (int) $magic];
            $updated = $this->patchEnvironment($original, $updates);
            $updatedValues = $this->parseEnvironment($updated);
            $this->insist(($updatedValues['APP_KEY'] ?? null) === $parsed['APP_KEY'], 'Application encryption key preservation failed.');
            foreach ($updates as $key => $value) {
                $this->insist(($updatedValues[$key] ?? null) === $value, 'Environment serialization could not preserve an updated setting.');
            }
            DB::transaction(function () use ($appPath, $original, $updated, &$envChanged, $base, $token, $symbol, $guard, $signal): void {
                $settings = AutoTraderSetting::query()->lockForUpdate()->find(1);
                $this->insist(! $this->unresolvedOrders(), 'Unresolved bridge orders appeared during configuration.');
                $this->backup($original, $settings);
                $this->insist($guard->validateSignal($signal, now()->utc()) === null, 'Broker data expired during configuration; retry with a current snapshot.');
                $this->atomicWrite($appPath, $updated);
                $envChanged = true;
                $accountType = in_array($settings?->account_type, ['cent', 'dollar'], true) ? $settings->account_type : 'dollar';
                $settings ??= new AutoTraderSetting;
                $settings->forceFill(['id' => 1, 'enabled' => true, 'execution_mode' => 'bridge', 'strategy_slug' => RsiGoldScalperV2Strategy::SLUG,
                    'pair_code' => 'XAUUSD', 'mt5_symbol' => $symbol, 'account_type' => $accountType,
                    'lot_size' => (float) ($settings->lot_size ?: 0.01), 'risk_percent_per_trade' => 0.25,
                    'max_trades_per_day' => 3, 'max_open_trades' => 1, 'cooldown_minutes' => 30,
                    'allow_buy' => true, 'allow_sell' => true, 'news_filter_enabled' => false,
                    'bridge_url' => $base.'/trade', 'bridge_token' => $token])->save();
                $this->insist($this->callSilent('config:clear') === self::SUCCESS, 'Configuration cache could not be cleared.');
            });
            config(['scalper.gold.source' => 'mt5', 'scalper.gold.version' => 'trend-session-v2',
                'trading.auto_trader.bridge_url' => $base.'/trade', 'trading.auto_trader.bridge_token' => $token,
                'trading.auto_trader.symbols.cent' => $symbol, 'trading.auto_trader.symbols.dollar' => $symbol,
                'trading.auto_trader.magic' => (int) $magic]);

            return $this->result('configured', 'Verified local DEMO RSI V2 entries are enabled. This command placed no orders.', self::SUCCESS, ['backup_created' => true]);
        } catch (\Throwable) {
            $restored = true;
            if ($envChanged && is_string($original)) {
                try {
                    $this->atomicWrite($appPath, $original);
                } catch (\Throwable) {
                    $restored = false;
                }
            }
            $message = $this->rejection ?: 'Demo configuration could not be verified or saved; existing configuration was retained.';
            if (! $restored) {
                $message = 'Configuration failed and automatic environment restoration failed. Restore the private bootstrap backup before enabling trading.';
            }

            return $this->result('rejected', $message, self::FAILURE, ['manual_restore_required' => ! $restored]);
        } finally {
            $lock->release();
        }
    }

    private function stopRsi(): int
    {
        $stopped = DB::transaction(function (): bool {
            $settings = AutoTraderSetting::query()->lockForUpdate()->find(1);
            $aliases = ['rsi-moving-average', 'rsi-moving-averages', 'rsi-ma', 'rsi-ma-method', 'rsi-scalper', 'rsi-gold-scalper', RsiGoldScalperV2Strategy::SLUG];
            if ($settings === null || $settings->execution_mode !== 'bridge' || ! $settings->enabled
                || ! in_array(strtolower(trim((string) $settings->strategy_slug)), $aliases, true)) {
                return false;
            }
            $settings->update(['enabled' => false]);

            return true;
        });

        return $this->result($stopped ? 'stopped' : 'unchanged', $stopped
            ? 'New RSI bridge entries are disabled. Existing broker SL/TP orders remain unchanged.'
            : 'No enabled RSI bridge trader needed stopping; other strategies were left unchanged.', self::SUCCESS);
    }

    private function unresolvedOrders(): bool
    {
        return AutoTradeOrder::query()->where('execution_mode', 'bridge')->whereIn('status', ['pending', 'unknown', 'sent', 'accepted', 'partial'])->exists();
    }

    private function flag(array $values, string $key): string
    {
        return strtolower(trim((string) ($values[$key] ?? '')));
    }

    private function insist(bool $condition, string $message): void
    {
        if (! $condition) {
            $this->rejection = $message;
            throw new \RuntimeException($message);
        }
    }

    private function readFile(string $path, string $message): string
    {
        $this->insist(is_file($path) && is_readable($path), $message);
        $bytes = file_get_contents($path);
        $this->insist($bytes !== false, $message);

        return $bytes;
    }

    private function parseEnvironment(string $content): array
    {
        try {
            return Dotenv::parse($content);
        } catch (\Throwable) {
            $this->insist(false, 'An environment file is malformed; no private file content is printed.');
        }
    }

    private function snapshotTime(mixed $value): \DateTimeImmutable
    {
        $this->insist(is_string($value) && (bool) preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|\+00:00)$/D', $value, $match), 'The snapshot needs an explicit valid UTC timestamp.');
        $canonical = $match[1].'.'.str_pad($match[2] ?? '', 6, '0').'+00:00';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $canonical, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        $this->insist($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d\TH:i:s.uP') === $canonical, 'The snapshot UTC calendar timestamp is invalid.');

        return $date;
    }

    private function patchEnvironment(string $original, array $updates): string
    {
        $newline = str_contains($original, "\r\n") ? "\r\n" : (str_contains($original, "\r") ? "\r" : "\n");
        $trailing = (bool) preg_match('/[\r\n]$/D', $original);
        preg_match_all('/[^\r\n]+(?:\r\n|\r|\n|$)|(?:\r\n|\r|\n)/', $original, $lines);
        $result = '';
        $seen = [];
        foreach ($lines[0] as $line) {
            if (preg_match('/^([ \t]*)(?:export[ \t]+)?([A-Z_][A-Z0-9_]*)[ \t]*=/', $line, $key) && array_key_exists($key[2], $updates)) {
                $ending = substr($line, strlen(rtrim($line, "\r\n")));
                $line = $key[1].$key[2].'='.$this->envValue($updates[$key[2]]).$ending;
                $seen[$key[2]] = true;
            }
            $result .= $line;
        }
        $append = [];
        foreach ($updates as $key => $value) {
            if (! isset($seen[$key])) {
                $append[] = $key.'='.$this->envValue($value);
            }
        }
        if ($append !== []) {
            if ($result !== '' && ! preg_match('/[\r\n]$/D', $result)) {
                $result .= $newline;
            }
            $result .= implode($newline, $append).($trailing ? $newline : '');
        }

        return $result;
    }

    private function envValue(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_\/.:\-]+$/D', $value)) {
            return $value;
        }

        return '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']).'"';
    }

    private function backup(string $environment, ?AutoTraderSetting $settings): void
    {
        $path = storage_path('app/private/mt5-demo-bootstrap/'.now()->utc()->format('Ymd\THis-u').'-'.bin2hex(random_bytes(4)));
        $this->insist(mkdir($path, 0700, true), 'Could not create a private bootstrap backup.');
        $this->atomicWrite($path.'/app-env.before', $environment);
        $this->atomicWrite($path.'/settings.before.json', json_encode(['exists' => $settings !== null,
            'raw_attributes' => $settings?->getRawOriginal()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }

    private function atomicWrite(string $path, string $content): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        try {
            $handle = fopen($temporary, 'xb');
            if ($handle === false) {
                throw new \RuntimeException('Could not create a staged private file.');
            }
            try {
                chmod($temporary, is_file($path) ? fileperms($path) & 0777 : 0600);
                $written = 0;
                while ($written < strlen($content)) {
                    $count = fwrite($handle, substr($content, $written));
                    if ($count === false || $count === 0) {
                        throw new \RuntimeException('Could not complete a staged file.');
                    }
                    $written += $count;
                }
                if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                    throw new \RuntimeException('Could not flush a staged file.');
                }
            } finally {
                fclose($handle);
            }
            if (! rename($temporary, $path)) {
                throw new \RuntimeException('Could not replace the environment atomically.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function result(string $status, string $message, int $exit, array $extra = []): int
    {
        $this->line(json_encode(['status' => $status, 'message' => $message, 'orders_sent' => 0, 'emails_sent' => 0] + $extra, JSON_THROW_ON_ERROR));

        return $exit;
    }
}

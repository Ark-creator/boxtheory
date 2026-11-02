<?php

use App\Models\AutoTradeOrder;
use App\Models\AutoTraderSetting;
use App\Services\MarketDataGuard;
use Dotenv\Dotenv;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02T12:15:20Z'));
    Cache::flush();
    Http::preventStrayRequests();
    Mail::fake();
    $this->demoRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'goldlogic-configure-demo-'.Str::uuid();
    mkdir($this->demoRoot.'/bootstrap/cache', 0700, true);
    mkdir($this->demoRoot.'/storage', 0700, true);
    $this->app->useEnvironmentPath($this->demoRoot)->loadEnvironmentFrom('.env.app');
    $this->app->useStoragePath($this->demoRoot.'/storage');
    $this->app->useBootstrapPath($this->demoRoot.'/bootstrap');
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'scalper.gold.source' => 'twelvedata', 'scalper.gold.version' => 'legacy']);
    $this->app->forgetInstance('encrypter');
    $this->originalEnv = '# Existing local credentials'."\r\n".'APP_KEY='.config('app.key')."\r\n"
        .'DB_PASSWORD="unchanged-db#token"'."\r\n".'MAIL_PASSWORD=\'unchanged-mail$token\''."\r\n"
        .'RSI_GOLD_SCALPER_SOURCE=twelvedata'."\r\n".'AUTOTRADER_BRIDGE_URL=http://old.test/trade'."\r\n";
    file_put_contents($this->app->environmentFilePath(), $this->originalEnv);
    $this->bridgePath = $this->demoRoot.'/.env.bridge';
    file_put_contents($this->bridgePath, configureDemoBridgeBytes());
    $this->settings = AutoTraderSetting::query()->firstOrNew(['id' => 1]);
    $this->settings->forceFill(['id' => 1, 'enabled' => false, 'execution_mode' => 'dry_run', 'strategy_slug' => 'gold-risk-breakout',
        'pair_code' => 'XAUUSD', 'mt5_symbol' => 'OLDXAUUSD', 'account_type' => 'cent', 'lot_size' => 0.01,
        'risk_percent_per_trade' => 0.1, 'bridge_url' => 'http://old.test/trade', 'bridge_token' => 'prior-private-test-token'])->save();
    $this->settings = $this->settings->fresh();
    $this->originalSettings = $this->settings->getRawOriginal();
});

afterEach(function () {
    $resolved = realpath($this->demoRoot);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved !== false && $temporaryRoot !== false && dirname($resolved) === $temporaryRoot
        && str_starts_with(basename($resolved), 'goldlogic-configure-demo-')) {
        File::deleteDirectory($resolved);
    }
});

function configureDemoBridgeBytes(array $changes = []): string
{
    $values = array_replace(['MT5_BRIDGE_TOKEN' => str_repeat('s', 40), 'MT5_LOGIN' => '123456', 'MT5_SERVER' => 'UnitTest-Demo',
        'MT5_SYMBOL' => 'XAUUSD-STD', 'MT5_BRIDGE_HOST' => '127.0.0.1', 'MT5_BRIDGE_PORT' => '8710',
        'MT5_ENABLE_EXECUTION' => 'true', 'MT5_ALLOW_LIVE' => 'false', 'MT5_DEMO_ONLY' => 'true', 'MT5_MAGIC' => '2601103'], $changes);

    return implode("\n", array_map(fn ($key, $value) => $key.'='.$value, array_keys($values), array_values($values)))."\n";
}

function configureDemoSnapshot(): array
{
    $guard = new MarketDataGuard;
    $cursor = Carbon::createFromTimestampUTC(intdiv(now()->timestamp, 300) * 300)->subMinutes(5);
    $bars = [];
    while (count($bars) < 203) {
        if ($guard->marketStatus('XAUUSD', $cursor)['open'] && $guard->marketStatus('XAUUSD', $cursor->copy()->addSeconds(299))['open']) {
            $bars[] = ['timestamp' => $cursor->toIso8601String(), 'open' => 2000.0, 'high' => 2001.0, 'low' => 1999.0, 'close' => 2000.0];
        }
        $cursor->subMinutes(5);
    }

    return ['status' => 'ok', 'source' => 'mt5', 'symbol' => 'XAUUSD-STD', 'account_key' => hash('sha256', '123456:UnitTest-Demo'),
        'account' => ['demo' => true], 'observed_at' => now()->toIso8601String(), 'positions' => [], 'pending_orders' => [],
        'capabilities' => ['m5_candles', 'quote_timestamp', 'rsi_scalper_v2_demo_execution'],
        'm5' => array_reverse($bars), 'quote' => ['bid' => 2000.0, 'ask' => 2000.1, 'timestamp' => now()->toIso8601String()]];
}

function configureDemoRun(string $bridgePath, bool $stop = false): array
{
    $exit = Artisan::call('mt5:configure-demo', ['--bridge-env' => $bridgePath, '--stop' => $stop]);
    $output = Artisan::output();

    return [$exit, json_decode($output, true, 512, JSON_THROW_ON_ERROR), $output];
}

test('verified demo bootstrap preserves credentials and line endings and saves guarded canonical settings', function () {
    file_put_contents($this->demoRoot.'/bootstrap/cache/config.php', '<?php return [];');
    Http::fake(['http://127.0.0.1:8710/snapshot' => Http::response(configureDemoSnapshot())]);
    [$exit, $result, $output] = configureDemoRun($this->bridgePath);
    expect($exit)->toBe(0)->and($result['status'])->toBe('configured')->and($result['backup_created'])->toBeTrue()
        ->and($result['orders_sent'])->toBe(0)->and($result['emails_sent'])->toBe(0);
    $bytes = file_get_contents($this->app->environmentFilePath());
    $values = Dotenv::parse($bytes);
    expect($values['APP_KEY'])->toBe(config('app.key'))->and($values['DB_PASSWORD'])->toBe('unchanged-db#token')
        ->and($values['MAIL_PASSWORD'])->toBe('unchanged-mail$token')->and(preg_match('/(?<!\r)\n/', $bytes))->toBe(0)
        ->and($values['RSI_GOLD_SCALPER_SOURCE'])->toBe('mt5')->and($values['RSI_GOLD_SCALPER_VERSION'])->toBe('trend-session-v2')
        ->and($values['AUTOTRADER_MAGIC'])->toBe('2601103')->and($values['AUTOTRADER_MT5_SYMBOL_CENT'])->toBe('XAUUSD-STD')
        ->and($values['AUTOTRADER_MT5_SYMBOL_DOLLAR'])->toBe('XAUUSD-STD')->and($values['AUTOTRADER_BRIDGE_TOKEN'])->toBe(str_repeat('s', 40));
    $settings = $this->settings->fresh();
    expect($settings->enabled)->toBeTrue()->and($settings->execution_mode)->toBe('bridge')->and($settings->strategy_slug)->toBe('rsi-gold-scalper-v2')
        ->and($settings->account_type)->toBe('cent')->and($settings->mt5_symbol)->toBe('XAUUSD-STD')
        ->and((float) $settings->risk_percent_per_trade)->toBe(0.25)->and($settings->max_trades_per_day)->toBe(3)
        ->and($settings->max_open_trades)->toBe(1)->and($settings->cooldown_minutes)->toBe(30)
        ->and($settings->allow_buy)->toBeTrue()->and($settings->allow_sell)->toBeTrue()->and($settings->news_filter_enabled)->toBeFalse()
        ->and($settings->bridge_token)->toBe(str_repeat('s', 40))->and(is_file($this->demoRoot.'/bootstrap/cache/config.php'))->toBeFalse();
    $backups = glob($this->demoRoot.'/storage/app/private/mt5-demo-bootstrap/*/app-env.before');
    expect($backups)->toHaveCount(1)->and(file_get_contents($backups[0]))->toBe($this->originalEnv);
    $prior = json_decode(file_get_contents(dirname($backups[0]).'/settings.before.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($prior['raw_attributes'])->toBe($this->originalSettings)->and($output)->not->toContain(str_repeat('s', 40))
        ->not->toContain('123456')->not->toContain('UnitTest-Demo')->not->toContain('unchanged-db');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer '.str_repeat('s', 40)));
    Mail::assertNothingSent();
    expect(AutoTradeOrder::count())->toBe(0);
});

test('ineligible demo bootstrap rejects before environment settings or backup writes', function (string $fault) {
    $snapshot = configureDemoSnapshot();
    $bridge = [];
    switch ($fault) {
        case 'token': $bridge['MT5_BRIDGE_TOKEN'] = 'short-private-token';
            break;
        case 'login': $bridge['MT5_LOGIN'] = 'invalid';
            break;
        case 'server': $bridge['MT5_SERVER'] = '';
            break;
        case 'symbol': $bridge['MT5_SYMBOL'] = 'EURUSD';
            break;
        case 'host': $bridge['MT5_BRIDGE_HOST'] = 'example.com';
            break;
        case 'port': $bridge['MT5_BRIDGE_PORT'] = '65536';
            break;
        case 'execution disabled': $bridge['MT5_ENABLE_EXECUTION'] = 'false';
            break;
        case 'live allowed': $bridge['MT5_ALLOW_LIVE'] = 'true';
            break;
        case 'demo only disabled': $bridge['MT5_DEMO_ONLY'] = 'false';
            break;
        case 'live account': $snapshot['account']['demo'] = false;
            break;
        case 'account mismatch': $snapshot['account_key'] = 'different-account';
            break;
        case 'wrong source': $snapshot['source'] = 'twelvedata';
            break;
        case 'wrong symbol': $snapshot['symbol'] = 'XAUUSD';
            break;
        case 'old bridge': $snapshot['capabilities'] = ['m5_candles', 'quote_timestamp'];
            break;
        case 'stale snapshot': $snapshot['observed_at'] = now()->subSeconds(31)->toIso8601String();
            break;
        case 'future snapshot': $snapshot['observed_at'] = now()->addSecond()->toIso8601String();
            break;
        case 'invalid calendar': $snapshot['observed_at'] = '2026-02-30T12:15:20+00:00';
            break;
        case 'stale quote': $snapshot['quote']['timestamp'] = now()->subSeconds(91)->toIso8601String();
            break;
        case 'future quote': $snapshot['quote']['timestamp'] = now()->addSecond()->toIso8601String();
            break;
        case 'short history': $snapshot['m5'] = array_slice($snapshot['m5'], -202);
            break;
        case 'position': $snapshot['positions'] = [['symbol' => 'XAUUSD-STD']];
            break;
        case 'pending order': $snapshot['pending_orders'] = [['symbol' => 'EURUSD']];
            break;
        case 'disconnected': break;
        case 'redirect': break;
        default: throw new LogicException('Unexpected fixture fault.');
    }
    file_put_contents($this->bridgePath, configureDemoBridgeBytes($bridge));
    Http::fake(['http://127.0.0.1:8710/snapshot' => Http::response($snapshot, $fault === 'disconnected' ? 503 : ($fault === 'redirect' ? 302 : 200),
        $fault === 'redirect' ? ['Location' => 'https://untrusted.example.test'] : [])]);
    [$exit, $result, $output] = configureDemoRun($this->bridgePath);
    expect($exit)->toBe(1)->and($result['status'])->toBe('rejected')
        ->and(file_get_contents($this->app->environmentFilePath()))->toBe($this->originalEnv)
        ->and($this->settings->fresh()->getRawOriginal())->toBe($this->originalSettings)
        ->and(glob($this->demoRoot.'/storage/app/private/mt5-demo-bootstrap/*'))->toBe([])
        ->and($output)->not->toContain(str_repeat('s', 40))->not->toContain('short-private-token');
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET' || $request->url() !== 'http://127.0.0.1:8710/snapshot');
    Mail::assertNothingSent();
})->with(['token', 'login', 'server', 'symbol', 'host', 'port', 'execution disabled', 'live allowed', 'demo only disabled',
    'live account', 'account mismatch', 'wrong source', 'wrong symbol', 'old bridge', 'stale snapshot', 'future snapshot',
    'invalid calendar', 'stale quote', 'future quote', 'short history', 'position', 'pending order', 'disconnected', 'redirect']);

test('unresolved bridge orders block bootstrap before any provider request', function () {
    AutoTradeOrder::create(['uuid' => (string) Str::uuid(), 'signal_signature' => str_repeat('a', 64), 'strategy_slug' => 'rsi-gold-scalper-v2',
        'pair_code' => 'XAUUSD', 'mt5_symbol' => 'XAUUSD-STD', 'execution_mode' => 'bridge', 'action' => 'BUY', 'status' => 'unknown']);
    [$exit] = configureDemoRun($this->bridgePath);
    expect($exit)->toBe(1)->and(file_get_contents($this->app->environmentFilePath()))->toBe($this->originalEnv)
        ->and($this->settings->fresh()->getRawOriginal())->toBe($this->originalSettings);
    Http::assertNothingSent();
});

test('failed settings save restores environment bytes and rolls back the settings transaction', function () {
    Http::fake(['http://127.0.0.1:8710/snapshot' => Http::response(configureDemoSnapshot())]);
    Event::listen('eloquent.updating: '.AutoTraderSetting::class, function () {
        throw new RuntimeException('private-save-error-'.str_repeat('s', 40));
    });
    [$exit, $result, $output] = configureDemoRun($this->bridgePath);
    expect($exit)->toBe(1)->and($result['manual_restore_required'])->toBeFalse()
        ->and(file_get_contents($this->app->environmentFilePath()))->toBe($this->originalEnv)
        ->and($this->settings->fresh()->getRawOriginal())->toBe($this->originalSettings)
        ->and(glob($this->demoRoot.'/storage/app/private/mt5-demo-bootstrap/*/app-env.before'))->toHaveCount(1)
        ->and($output)->not->toContain('private-save-error')->not->toContain(str_repeat('s', 40));
    Http::assertSentCount(1);
    Mail::assertNothingSent();
});

test('stop disables only current RSI bridge entries without reading bridge credentials', function (string $strategy, string $mode, bool $changed) {
    $this->settings->update(['enabled' => true, 'execution_mode' => $mode, 'strategy_slug' => $strategy]);
    [$exit, $result] = configureDemoRun($this->demoRoot.'/does-not-exist.env', true);
    expect($exit)->toBe(0)->and($result['status'])->toBe($changed ? 'stopped' : 'unchanged')
        ->and($this->settings->fresh()->enabled)->toBe(! $changed)
        ->and(file_get_contents($this->app->environmentFilePath()))->toBe($this->originalEnv)
        ->and(AutoTradeOrder::count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with([['rsi-gold-scalper-v2', 'bridge', true], ['rsi-scalper', 'bridge', true], ['gold-risk-breakout', 'bridge', false], ['rsi-scalper', 'dry_run', false]]);

test('a current HOLD outside the entry window can configure demo for the next eligible session', function () {
    $this->travelTo(Carbon::parse('2026-11-02T17:15:20Z'));
    $snapshot = configureDemoSnapshot();
    $snapshot['observed_at'] = '2026-11-02T17:15:20.000000+00:00';
    Http::fake(['http://127.0.0.1:8710/snapshot' => Http::response($snapshot)]);
    [$exit, $result] = configureDemoRun($this->bridgePath);
    expect($exit)->toBe(0)->and($result['status'])->toBe('configured')->and(AutoTradeOrder::count())->toBe(0);
    Mail::assertNothingSent();
});

test('malformed private environment content never appears in command output', function () {
    $secret = 'private-malformed-secret-'.str_repeat('s', 40);
    file_put_contents($this->bridgePath, 'MT5_BRIDGE_TOKEN="'.$secret);
    [$exit, , $output] = configureDemoRun($this->bridgePath);
    expect($exit)->toBe(1)->and($output)->not->toContain($secret)
        ->and(file_get_contents($this->app->environmentFilePath()))->toBe($this->originalEnv)
        ->and($this->settings->fresh()->getRawOriginal())->toBe($this->originalSettings);
    Http::assertNothingSent();
});

test('bootstrap creates settings id1 without inferring account denomination and uses the requested magic default', function () {
    $this->settings->delete();
    $bridge = preg_replace('/^MT5_MAGIC=.*\n/m', '', configureDemoBridgeBytes());
    file_put_contents($this->bridgePath, $bridge);
    Http::fake(['http://127.0.0.1:8710/snapshot' => Http::response(configureDemoSnapshot())]);
    [$exit] = configureDemoRun($this->bridgePath);
    $settings = AutoTraderSetting::query()->find(1);
    expect($exit)->toBe(0)->and($settings)->not->toBeNull()->and($settings->account_type)->toBe('dollar')
        ->and($settings->enabled)->toBeTrue()->and(config('trading.auto_trader.magic'))->toBe(2601103)
        ->and(AutoTraderSetting::count())->toBe(1);
});

test('bootstrap refuses an application key inconsistent with the active encryption configuration', function () {
    $bytes = str_replace(config('app.key'), 'base64:'.base64_encode(str_repeat('x', 32)), $this->originalEnv);
    file_put_contents($this->app->environmentFilePath(), $bytes);
    Http::fake(['http://127.0.0.1:8710/snapshot' => Http::response(configureDemoSnapshot())]);
    [$exit, $result] = configureDemoRun($this->bridgePath);
    expect($exit)->toBe(1)->and($result['message'])->toContain('APP_KEY')
        ->and(file_get_contents($this->app->environmentFilePath()))->toBe($bytes)
        ->and($this->settings->fresh()->getRawOriginal())->toBe($this->originalSettings)
        ->and(glob($this->demoRoot.'/storage/app/private/mt5-demo-bootstrap/*'))->toBe([]);
});

test('duplicate source definitions are consistently updated without converting LF line endings', function () {
    $bytes = str_replace("\r\n", "\n", $this->originalEnv)."export RSI_GOLD_SCALPER_SOURCE=twelvedata\n";
    file_put_contents($this->app->environmentFilePath(), $bytes);
    Http::fake(['http://127.0.0.1:8710/snapshot' => Http::response(configureDemoSnapshot())]);
    [$exit] = configureDemoRun($this->bridgePath);
    $actual = file_get_contents($this->app->environmentFilePath());
    expect($exit)->toBe(0)->and(substr_count($actual, 'RSI_GOLD_SCALPER_SOURCE=mt5'))->toBe(2)
        ->and($actual)->not->toContain("\r")->and(Dotenv::parse($actual)['RSI_GOLD_SCALPER_SOURCE'])->toBe('mt5');
});

test('an active auto trader lock prevents bootstrap mutation', function () {
    $lock = Cache::lock('auto-trader:execution', 120);
    $lock->get();
    try {
        [$exit, $result] = configureDemoRun($this->bridgePath);
        expect($exit)->toBe(1)->and($result['message'])->toContain('busy')
            ->and(file_get_contents($this->app->environmentFilePath()))->toBe($this->originalEnv)
            ->and($this->settings->fresh()->getRawOriginal())->toBe($this->originalSettings);
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

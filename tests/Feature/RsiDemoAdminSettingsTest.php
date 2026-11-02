<?php

use App\Models\AutoTradeOrder;
use App\Models\AutoTraderSetting;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Mt5BridgeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

test('admin can save the built-in demo scalper without a catalogue record or placing an order', function () {
    Http::preventStrayRequests();
    Mail::fake();
    Strategy::where('slug', 'rsi-gold-scalper-v2')->delete();
    $admin = User::factory()->create(['role' => 'admin']);
    $settings = ['enabled' => false, 'execution_mode' => 'bridge', 'account_type' => 'dollar',
        'pair_code' => 'XAUUSD', 'mt5_symbol' => 'XAUUSD-STD', 'strategy_slug' => 'rsi-gold-scalper-v2',
        'lot_size' => 0.01, 'risk_percent_per_trade' => 0.25, 'max_trades_per_day' => 3,
        'max_open_trades' => 1, 'cooldown_minutes' => 30, 'allow_buy' => true, 'allow_sell' => true,
        'bridge_url' => 'http://127.0.0.1:8710/trade', 'bridge_token' => str_repeat('t', 48)];
    $this->actingAs($admin)->put(route('admin.auto-trader.update'), $settings)
        ->assertSessionHasNoErrors()->assertRedirect(route('admin.auto-trader'));
    expect(AutoTraderSetting::findOrFail(1)->strategy_slug)->toBe('rsi-gold-scalper-v2')
        ->and(AutoTradeOrder::count())->toBe(0)->and(Strategy::where('slug', 'rsi-gold-scalper-v2')->exists())->toBeFalse();
    $this->mock(Mt5BridgeClient::class)->shouldReceive('snapshot')->once()->andThrow(new RuntimeException('Test bridge is offline'));
    $this->get(route('admin.auto-trader'))->assertOk()->assertSee('value="rsi-gold-scalper-v2"', false);
    $settings['strategy_slug'] = 'nonexistent-demo-strategy';
    $this->actingAs($admin)->put(route('admin.auto-trader.update'), $settings)->assertSessionHasErrors('strategy_slug');
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

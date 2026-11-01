<?php

use App\Models\SignalDelivery;
use App\Models\User;
use App\Services\TradingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Http::preventStrayRequests();
    Mail::fake();
    $trading = Mockery::mock(TradingService::class);
    $trading->shouldNotReceive('getSignalForStrategy');
    $this->app->instance(TradingService::class, $trading);
});

function signalHistoryDelivery(array $attributes = []): SignalDelivery
{
    return SignalDelivery::create(array_replace([
        'strategy_id' => null,
        'strategy_name' => 'Original strategy name',
        'strategy_slug' => 'original-strategy',
        'pair_code' => 'XAUUSD',
        'action' => 'BUY',
        'recipient' => 'admin@example.com',
        'signature' => str_repeat('a', 64),
        'signal_payload' => [
            'action' => 'BUY',
            'price' => 2350.125,
            'timestamp' => '2026-11-01T00:00:00Z',
            'expires_at' => '2026-11-01T00:03:00Z',
            'message' => 'Original confirmed setup',
            'trade_plan' => [
                'entry_price' => 2350.125,
                'stop_loss' => 2340.25,
                'take_profit_1' => 2370.5,
                'take_profit_2' => 2390.75,
            ],
        ],
        'status' => 'sent',
        'mailer' => 'array',
        'forced' => false,
        'attempted_at' => '2026-11-01 00:00:00',
        'sent_at' => '2026-11-01 00:00:01',
        'error_message' => null,
    ], $attributes));
}

test('signal history and CSV require an authenticated admin', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 'customer']))
        ->get(route($route))->assertForbidden();
})->with(['admin.signals.history', 'admin.signals.history.export']);

test('history shows the stored signal and failure safely without regenerating signals', function () {
    $delivery = signalHistoryDelivery([
        'status' => 'failed',
        'sent_at' => null,
        'forced' => true,
        'error_message' => 'SMTP refused <recipient>',
    ]);
    $payload = $delivery->signal_payload;
    $payload['message'] = '<script>alert("saved")</script>';
    $delivery->update(['signal_payload' => $payload]);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('admin.signals.history'))
        ->assertOk()
        ->assertSee('Original strategy name')
        ->assertSee('2026-11-01 08:00:00')
        ->assertSee('2,350.1250')
        ->assertSee('2,340.2500')
        ->assertSee('2,370.5000')
        ->assertSee('2,390.7500')
        ->assertSee('2026-11-01T00:03:00Z')
        ->assertSee('SMTP refused <recipient>')
        ->assertSee('<script>alert("saved")</script>')
        ->assertDontSee('<script>alert("saved")</script>', false)
        ->assertSee('Forced run')
        ->assertSee('does not confirm inbox delivery');

    expect(SignalDelivery::count())->toBe(1);
    Mail::assertNothingSent();
});

test('history combines snapshot filters with inclusive Taipei date boundaries', function () {
    $first = signalHistoryDelivery(['strategy_name' => 'Start boundary', 'attempted_at' => '2026-10-31 16:00:00']);
    $last = signalHistoryDelivery(['strategy_name' => 'End boundary', 'attempted_at' => '2026-11-01 15:59:59']);
    signalHistoryDelivery(['strategy_name' => 'Too early', 'attempted_at' => '2026-10-31 15:59:59']);
    signalHistoryDelivery(['strategy_name' => 'Too late', 'attempted_at' => '2026-11-01 16:00:00']);
    foreach ([
        ['strategy_slug' => 'another-strategy'],
        ['action' => 'SELL'],
        ['status' => 'failed'],
        ['pair_code' => 'EURUSD'],
        ['recipient' => 'another@elsewhere.com'],
    ] as $mismatch) {
        signalHistoryDelivery($mismatch);
    }

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('admin.signals.history', [
            'strategy_slug' => 'original-strategy',
            'pair_code' => 'XAUUSD',
            'action' => 'BUY',
            'status' => 'sent',
            'recipient' => '@example.com',
            'from' => '2026-11-01',
            'to' => '2026-11-01',
        ]))
        ->assertOk()
        ->assertViewHas('deliveries', fn ($rows) => $rows->pluck('id')->all() === [$last->id, $first->id])
        ->assertSee('2026-11-01 00:00:00')
        ->assertSee('2026-11-01 23:59:59');
});

test('history paginates newest attempts and preserves filters', function () {
    foreach (range(1, 26) as $minute) {
        signalHistoryDelivery([
            'strategy_name' => 'Saved setup '.$minute,
            'attempted_at' => sprintf('2026-11-01 00:%02d:00', $minute),
        ]);
    }

    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->get(route('admin.signals.history', ['status' => 'sent']))
        ->assertOk()
        ->assertViewHas('deliveries', fn ($rows) => $rows->count() === 25 && $rows->total() === 26 && $rows->first()->strategy_name === 'Saved setup 26')
        ->assertSee('status=sent&amp;page=2', false);

    $this->get(route('admin.signals.history', ['status' => 'sent', 'page' => 2]))
        ->assertOk()
        ->assertViewHas('deliveries', fn ($rows) => $rows->count() === 1 && $rows->first()->strategy_name === 'Saved setup 1');
});

test('CSV exports every matching attempt and protects spreadsheet formula cells', function () {
    foreach (range(1, 27) as $number) {
        signalHistoryDelivery(['strategy_name' => 'Export setup '.$number]);
    }
    $payload = SignalDelivery::first()->signal_payload;
    $payload['message'] = '=2+3';
    signalHistoryDelivery([
        'strategy_name' => '  @SUM(1+1)',
        'recipient' => '+formula@example.com',
        'signal_payload' => $payload,
    ]);
    signalHistoryDelivery(['status' => 'failed', 'strategy_name' => 'Filtered failed setup']);
    signalHistoryDelivery(['attempted_at' => '2026-11-01 16:00:00', 'strategy_name' => 'Filtered next day']);

    $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('admin.signals.history.export', ['status' => 'sent', 'from' => '2026-11-01', 'to' => '2026-11-01', 'page' => 2]))
        ->assertOk()
        ->assertDownload();

    $csv = $response->streamedContent();
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);
    $header = fgetcsv($stream, null, ',', '"', '');
    $rows = [];
    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = array_combine($header, $row);
    }
    fclose($stream);

    expect($rows)->toHaveCount(28)
        ->and($rows[0]['Strategy name'])->toBe("'  @SUM(1+1)")
        ->and($rows[0]['Recipient'])->toBe("'+formula@example.com")
        ->and($rows[0]['Message'])->toBe("'=2+3")
        ->and($rows[0]['Attempted at (Asia/Taipei)'])->toBe('2026-11-01 08:00:00 +08:00')
        ->and($rows[0]['Entry'])->toBe('2350.125')
        ->and(json_decode($rows[0]['Signal payload JSON'], true)['message'])->toBe('=2+3')
        ->and($csv)->not->toContain('Filtered failed setup', 'Filtered next day');

    expect(SignalDelivery::count())->toBe(30);
    Mail::assertNothingSent();
});

test('invalid date ranges and statuses are rejected for history and export', function (string $route) {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->getJson(route($route, ['from' => '2026-11-02', 'to' => '2026-11-01']))
        ->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson(route($route, ['status' => 'delivered']))
        ->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->getJson(route($route, ['from' => '2026-02-30']))
        ->assertUnprocessable()->assertJsonValidationErrors('from');
})->with(['admin.signals.history', 'admin.signals.history.export']);

test('empty history explains recording and shows no attempts', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('admin.signals.history'))
        ->assertOk()
        ->assertSee('History starts when recording was enabled.')
        ->assertSee('No saved signal attempts match these filters.');
});

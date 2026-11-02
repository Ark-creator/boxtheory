<?php

use App\Services\LiveMarketDataService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02T14:00:10Z'));
    Cache::flush();
    Http::preventStrayRequests();
    config(['services.twelvedata.key' => 'private-test-key', 'services.twelvedata.ca_bundle' => null, 'services.twelvedata.proxy' => null]);
});

test('a bad CA configuration fails before making a request and never disables TLS', function () {
    config(['services.twelvedata.ca_bundle' => base_path('does-not-exist-ca.pem')]);
    expect(fn () => app(LiveMarketDataService::class)->quote(['code' => 'XAUUSD', 'twelvedata_symbol' => 'XAU/USD']))
        ->toThrow(RuntimeException::class, 'must name a readable trusted CA certificate file');
    Http::assertNothingSent();
});

test('trusted CA and explicit direct transport reach the request without disabling verification', function () {
    $certificate = tempnam(sys_get_temp_dir(), 'gold-ca-');
    file_put_contents($certificate, 'test fixture; HTTP is faked');
    try {
        config(['services.twelvedata.ca_bundle' => $certificate, 'services.twelvedata.proxy' => '']);
        Http::fake(function ($request, $options) use ($certificate) {
            expect($options['verify'])->toBe($certificate)->and($options['proxy'])->toBe('');

            return Http::response(['symbol' => 'XAU/USD', 'close' => '2000', 'is_market_open' => true,
                'last_quote_at' => now()->copy()->startOfMinute()->timestamp]);
        });
        expect(app(LiveMarketDataService::class)->quote(['code' => 'XAUUSD', 'twelvedata_symbol' => 'XAU/USD'])['price'])->toBe(2000.0);
        Http::assertSentCount(1);
    } finally {
        unlink($certificate);
    }
});

test('transport exceptions give useful sanitized errors without leaking the provider key', function (string $transport, string $expected) {
    Http::fake(fn () => throw new ConnectionException($transport.' https://api.twelvedata.com/quote?apikey=private-test-key'));
    try {
        app(LiveMarketDataService::class)->quote(['code' => 'XAUUSD', 'twelvedata_symbol' => 'XAU/USD']);
        $this->fail('A connection failure must not become a quote.');
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toContain($expected)->not->toContain('private-test-key')->not->toContain('https://');
    }
})->with([
    ['cURL error 60: unable to get local issuer certificate', 'Configure TWELVEDATA_CA_BUNDLE'],
    ['cURL error 7: failed to connect', 'Live spot provider request failed'],
]);

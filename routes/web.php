<?php

use App\Models\Strategy;
use App\Services\TradingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TradingController;
use App\Http\Controllers\WatchlistController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\SubscriptionController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    if (auth()->user()->isAdmin()) {
        return redirect()->route('admin.dashboard');
    }

    return redirect()->route('strategies.index');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->group(function () {
    
    // Strategy Marketplace (Selection Page)
    Route::get('/strategies', function (Request $request, TradingService $service) {
        $pair = $service->normalizePairCode($request->query('pair'));

        return view('strategies.index', [
            'strategies' => Strategy::all(),
            'pair' => $pair,
            'pairs' => $service->getAvailablePairs(),
        ]);
    })->name('strategies.index');

    // Personal signal watchlist
    Route::get('/watchlist', [WatchlistController::class, 'index'])->name('watchlist.index');
    Route::put('/watchlist', [WatchlistController::class, 'update'])->name('watchlist.update');

    // Submit Receipt / Subscribe to Strategy
    Route::post('/subscriptions', [SubscriptionController::class, 'store'])
        ->name('subscriptions.store');

    // Online checkout payments (PayMongo)
    Route::post('/payments/checkout', [PaymentController::class, 'checkout'])
        ->name('payments.checkout');

    // Live Signals for subscribed users
    Route::get('/signals/{strategy:slug}', [TradingController::class, 'show'])
        ->name('signals.show');
    Route::get('/signals/{strategy:slug}/latest', [TradingController::class, 'latest'])
        ->name('signals.latest');

Route::get('/xauusd-price', function (Request $request, TradingService $service) {
    $pair = $service->normalizePairCode($request->query('pair', 'XAUUSD'));
    $data = $service->getMarketData($pair);

    return response()->json([
        'price' => $data['price'] ?? 0,
        'timestamp' => $data['timestamp'] ?? null,
        'source' => $data['source'] ?? null,
        'symbol' => $data['symbol'] ?? null,
        'symbol_code' => $data['symbol_code'] ?? $pair,
        'error' => $data['error'] ?? null,
    ]);
})->name('xauusd.price');

Route::middleware(['auth', 'can:admin-access'])->prefix('admin')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('admin.dashboard');
    Route::get('/strategies', [AdminController::class, 'strategies'])->name('admin.strategies');
    Route::get('/signals', [AdminController::class, 'signals'])->name('admin.signals');
    Route::get('/signals/all-pairs', [AdminController::class, 'signalsAllPairs'])->name('admin.signals.all-pairs');
    Route::post('/signals/send-now', [AdminController::class, 'sendSignalsNow'])->name('admin.signals.send-now');
    Route::get('/subscribers', [AdminController::class, 'subscribers'])->name('admin.subscribers');
    Route::get('/users', [AdminController::class, 'users'])->name('admin.users');

    Route::get('/approvals', [SubscriptionController::class, 'pendingApprovals'])->name('admin.approvals');
    
    Route::get('/receipts/{filename}', [SubscriptionController::class, 'viewReceipt'])->name('admin.receipt.view');
    
    Route::post('/approve/{id}', [SubscriptionController::class, 'approve'])->name('admin.approve');
    Route::post('/reject/{id}', [SubscriptionController::class, 'reject'])->name('admin.reject');
});
});

Route::get('/payments/success', [PaymentController::class, 'success'])
    ->name('payments.success');
Route::get('/payments/cancel', [PaymentController::class, 'cancel'])
    ->name('payments.cancel');

// PayMongo webhook endpoint (no auth, no CSRF)
Route::post('/webhooks/paymongo', [PaymentController::class, 'webhook'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('payments.webhook.paymongo');

require __DIR__.'/auth.php';

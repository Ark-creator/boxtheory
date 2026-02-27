<?php

use App\Models\Strategy;
use App\Services\TradingService;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TradingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubscriptionController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->group(function () {
    
    // Strategy Marketplace (Selection Page)
    Route::get('/strategies', function () {
        return view('strategies.index', [
            'strategies' => Strategy::all()
        ]);
    })->name('strategies.index');

    // Submit Receipt / Subscribe to Strategy
    Route::post('/subscriptions', [SubscriptionController::class, 'store'])
        ->name('subscriptions.store');

    // Live Signals for subscribed users
    Route::get('/signals/{strategy:slug}', [TradingController::class, 'show'])
        ->name('signals.show');
    Route::get('/signals/{strategy:slug}/latest', [TradingController::class, 'latest'])
        ->name('signals.latest');

    Route::get('/admin/approvals', [SubscriptionController::class, 'pendingApprovals'])
    ->middleware(['auth', 'can:be-admin']) // Use the Gate we discussed
    ->name('admin.approvals');

Route::get('/xauusd-price', function (TradingService $service) {
    $data = $service->getXauUsdData();
    return response()->json([
        'price' => $data['price'] ?? 0,
        'timestamp' => $data['timestamp'] ?? null,
        'error' => $data['error'] ?? null,
    ]);
});

Route::middleware(['auth', 'can:admin-access'])->prefix('admin')->group(function () {
    // List all pending receipts
    Route::get('/approvals', [SubscriptionController::class, 'pendingApprovals'])->name('admin.approvals');
    
    // View a private receipt image
    Route::get('/receipts/{filename}', [SubscriptionController::class, 'viewReceipt'])->name('admin.receipt.view');
    
    // The Approve Button logic
    Route::post('/approve/{id}', [SubscriptionController::class, 'approve'])->name('admin.approve');
});
});

require __DIR__.'/auth.php';

<?php

namespace App\Http\Controllers;

use App\Models\Strategy;
use App\Models\StrategyPayment;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    private const PAYMONGO_BASE_URL = 'https://api.paymongo.com/v1';

    public function checkout(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'strategy_id' => 'required|exists:strategies,id',
        ]);

        $secretKey = (string) config('services.paymongo.secret_key');
        if ($secretKey === '') {
            return back()->with('error', 'PayMongo is not configured yet. Please set PAYMONGO_SECRET_KEY in .env.');
        }

        $user = $request->user();
        $strategy = Strategy::findOrFail($validated['strategy_id']);
        $amountInCentavos = (int) round(((float) $strategy->price) * 100);

        $payload = [
            'data' => [
                'attributes' => [
                    'description' => sprintf('30-day subscription for %s', $strategy->name),
                    'line_items' => [[
                        'currency' => 'PHP',
                        'amount' => $amountInCentavos,
                        'name' => $strategy->name,
                        'quantity' => 1,
                        'description' => sprintf('%s monthly access', $strategy->name),
                    ]],
                    'payment_method_types' => $this->resolvePaymentMethodTypes(),
                    'success_url' => route('payments.success', ['strategy' => $strategy->slug]),
                    'cancel_url' => route('payments.cancel', ['strategy' => $strategy->slug]),
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'strategy_id' => (string) $strategy->id,
                        'strategy_slug' => $strategy->slug,
                        'strategy_name' => $strategy->name,
                    ],
                    'customer_email' => $user->email,
                    'send_email_receipt' => false,
                    'show_line_items' => true,
                    'show_description' => true,
                ],
            ],
        ];

        try {
            $response = Http::acceptJson()
                ->withBasicAuth($secretKey, '')
                ->post(self::PAYMONGO_BASE_URL . '/checkout_sessions', $payload);
        } catch (\Throwable $exception) {
            return back()->with('error', 'Checkout request failed: ' . $exception->getMessage());
        }

        if (!$response->successful()) {
            return back()->with('error', $this->extractPaymongoError($response->json()));
        }

        $checkoutData = $response->json('data');
        $checkoutId = data_get($checkoutData, 'id');
        $checkoutUrl = data_get($checkoutData, 'attributes.checkout_url');

        if (!is_string($checkoutId) || $checkoutId === '' || !is_string($checkoutUrl) || $checkoutUrl === '') {
            return back()->with('error', 'PayMongo did not return a valid checkout URL.');
        }

        StrategyPayment::create([
            'user_id' => $user->id,
            'strategy_id' => $strategy->id,
            'provider' => 'paymongo',
            'checkout_session_id' => $checkoutId,
            'amount' => (float) $strategy->price,
            'currency' => 'PHP',
            'status' => 'pending',
            'checkout_url' => $checkoutUrl,
            'raw_payload' => $response->json(),
        ]);

        return redirect()->away($checkoutUrl);
    }

    public function success(Request $request): RedirectResponse
    {
        $strategySlug = (string) $request->query('strategy', '');

        return redirect()
            ->route('strategies.index')
            ->with('success', sprintf(
                'Payment completed%s. Access will be activated automatically after webhook confirmation.',
                $strategySlug !== '' ? " for {$strategySlug}" : ''
            ));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $strategySlug = (string) $request->query('strategy', '');

        return redirect()
            ->route('strategies.index')
            ->with('error', sprintf(
                'Payment was cancelled%s.',
                $strategySlug !== '' ? " for {$strategySlug}" : ''
            ));
    }

    public function webhook(Request $request): Response
    {
        $payload = $request->getContent();

        if (!$this->verifyWebhookSignature($request, $payload)) {
            return response('Invalid PayMongo signature.', 400);
        }

        $json = $request->json()->all();
        $eventType = (string) data_get($json, 'data.attributes.type', '');
        $resource = data_get($json, 'data.attributes.data', []);
        $checkoutId = data_get($resource, 'id');
        $resourceAttributes = data_get($resource, 'attributes', []);

        if (!is_string($checkoutId) || $checkoutId === '') {
            return response('Missing checkout session id.', 202);
        }

        if ($eventType === 'checkout_session.payment.paid') {
            $this->markPaymentAsPaid($checkoutId, $resourceAttributes, $json);
        } elseif (
            in_array($eventType, ['checkout_session.payment.failed', 'checkout_session.expired'], true)
        ) {
            $this->markPaymentAsFailed($checkoutId, $json);
        }

        return response('OK', 200);
    }

    private function markPaymentAsPaid(string $checkoutId, array $resourceAttributes, array $eventPayload): void
    {
        $metadata = is_array($resourceAttributes['metadata'] ?? null) ? $resourceAttributes['metadata'] : [];
        $paymentId = data_get($resourceAttributes, 'payments.0.id')
            ?? data_get($resourceAttributes, 'payment_intent.id');
        $paymentRecord = StrategyPayment::where('checkout_session_id', $checkoutId)->first();

        $userId = $paymentRecord?->user_id ?? (int) ($metadata['user_id'] ?? 0);
        $strategyId = $paymentRecord?->strategy_id ?? (int) ($metadata['strategy_id'] ?? 0);

        if ($userId <= 0 || $strategyId <= 0) {
            Log::warning('PayMongo paid event missing user/strategy metadata', [
                'checkout_id' => $checkoutId,
                'metadata' => $metadata,
            ]);
            return;
        }

        if ($paymentRecord === null) {
            $lineAmount = (int) data_get($resourceAttributes, 'line_items.0.amount', 0);
            $paymentRecord = StrategyPayment::create([
                'user_id' => $userId,
                'strategy_id' => $strategyId,
                'provider' => 'paymongo',
                'checkout_session_id' => $checkoutId,
                'payment_id' => is_string($paymentId) ? $paymentId : null,
                'amount' => $lineAmount > 0 ? ($lineAmount / 100) : 0,
                'currency' => (string) data_get($resourceAttributes, 'line_items.0.currency', 'PHP'),
                'status' => 'paid',
                'checkout_url' => null,
                'raw_payload' => $eventPayload,
                'paid_at' => now(),
            ]);
        } else {
            $paymentRecord->update([
                'payment_id' => is_string($paymentId) ? $paymentId : $paymentRecord->payment_id,
                'status' => 'paid',
                'raw_payload' => $eventPayload,
                'paid_at' => now(),
            ]);
        }

        $this->activateOrExtendSubscription($userId, $strategyId);
    }

    private function markPaymentAsFailed(string $checkoutId, array $eventPayload): void
    {
        $paymentRecord = StrategyPayment::where('checkout_session_id', $checkoutId)->first();
        if ($paymentRecord === null) {
            return;
        }

        $paymentRecord->update([
            'status' => 'failed',
            'raw_payload' => $eventPayload,
        ]);
    }

    private function activateOrExtendSubscription(int $userId, int $strategyId): void
    {
        $subscription = DB::table('strategy_user')
            ->where('user_id', $userId)
            ->where('strategy_id', $strategyId)
            ->orderByDesc('id')
            ->first();

        $base = now();
        if ($subscription !== null && $subscription->expires_at !== null) {
            $expiry = Carbon::parse($subscription->expires_at);
            if ($expiry->greaterThan($base)) {
                $base = $expiry;
            }
        }

        $newExpiry = $base->copy()->addDays(30);

        if ($subscription === null) {
            DB::table('strategy_user')->insert([
                'user_id' => $userId,
                'strategy_id' => $strategyId,
                'receipt_path' => null,
                'status' => 'active',
                'expires_at' => $newExpiry,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('strategy_user')
                ->where('id', $subscription->id)
                ->update([
                    'status' => 'active',
                    'expires_at' => $newExpiry,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * PayMongo uses `te` for test mode and `li` for live mode signatures.
     */
    private function verifyWebhookSignature(Request $request, string $payload): bool
    {
        $secret = (string) config('services.paymongo.webhook_secret');
        if ($secret === '') {
            // Allow unsigned webhooks in local/dev only.
            return app()->environment(['local', 'development', 'testing']);
        }

        $header = (string) $request->header('Paymongo-Signature', '');
        if ($header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $chunk) {
            [$key, $value] = array_pad(explode('=', trim($chunk), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[trim($key)] = trim($value);
            }
        }

        $timestamp = $parts['t'] ?? null;
        if (!is_string($timestamp) || $timestamp === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $livemode = (bool) data_get($request->json()->all(), 'data.attributes.livemode', false);

        $provided = $livemode ? ($parts['li'] ?? null) : ($parts['te'] ?? null);
        if (!is_string($provided) || $provided === '') {
            $provided = $parts['te'] ?? $parts['li'] ?? null;
        }

        return is_string($provided) && hash_equals($expected, $provided);
    }

    /**
     * @return array<int,string>
     */
    private function resolvePaymentMethodTypes(): array
    {
        $raw = (string) config('services.paymongo.payment_method_types', 'gcash,paymaya,dob,qrph');
        $types = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $raw)
        )));

        if ($types === []) {
            return ['gcash', 'paymaya', 'dob', 'qrph'];
        }

        return array_values(array_unique($types));
    }

    private function extractPaymongoError(array $payload): string
    {
        $detail = data_get($payload, 'errors.0.detail');
        $code = data_get($payload, 'errors.0.code');
        $source = data_get($payload, 'errors.0.source.pointer');

        $parts = array_filter([
            is_string($detail) ? $detail : null,
            is_string($code) ? "code: {$code}" : null,
            is_string($source) ? "field: {$source}" : null,
        ]);

        if ($parts === []) {
            return 'PayMongo checkout failed. Please check your API keys and enabled payment methods.';
        }

        return 'PayMongo error: ' . implode(' | ', $parts);
    }
}


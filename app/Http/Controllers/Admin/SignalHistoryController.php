<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SignalDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignalHistoryController extends Controller
{
    private const TIMEZONE = 'Asia/Taipei';

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('admin.signal-history', [
            'deliveries' => $this->filteredQuery($filters)
                ->orderByDesc('attempted_at')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'filters' => $filters,
            'strategySlugs' => SignalDelivery::query()->distinct()->orderBy('strategy_slug')->pluck('strategy_slug'),
            'pairCodes' => SignalDelivery::query()->distinct()->orderBy('pair_code')->pluck('pair_code'),
            'actions' => SignalDelivery::query()->distinct()->orderBy('action')->pluck('action'),
            'timezone' => self::TIMEZONE,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filteredQuery($this->filters($request));
        // Keep newly created attempts out of an export already in progress.
        $lastId = (clone $query)->max('id') ?? 0;

        return response()->streamDownload(function () use ($query, $lastId): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'ID', 'Attempted at (Asia/Taipei)', 'Sent at (Asia/Taipei)',
                'Strategy name', 'Strategy slug', 'Pair', 'Action', 'Recipient',
                'Status', 'Mailer', 'Forced', 'Price', 'Entry', 'Stop loss',
                'Take profit 1', 'Take profit 2', 'Signal timestamp', 'Signal expiry',
                'Message', 'Error', 'Signature', 'Signal payload JSON',
            ], ',', '"', '');

            foreach ($query->where('id', '<=', $lastId)->lazyByIdDesc(500) as $delivery) {
                $signal = $delivery->signal_payload ?? [];
                $row = [
                    $delivery->id,
                    $delivery->attempted_at?->copy()->setTimezone(self::TIMEZONE)->format('Y-m-d H:i:s P'),
                    $delivery->sent_at?->copy()->setTimezone(self::TIMEZONE)->format('Y-m-d H:i:s P'),
                    $delivery->strategy_name, $delivery->strategy_slug, $delivery->pair_code,
                    $delivery->action, $delivery->recipient, $delivery->status,
                    $delivery->mailer, $delivery->forced ? 'yes' : 'no',
                    data_get($signal, 'price'), data_get($signal, 'trade_plan.entry_price'),
                    data_get($signal, 'trade_plan.stop_loss'), data_get($signal, 'trade_plan.take_profit_1'),
                    data_get($signal, 'trade_plan.take_profit_2'), data_get($signal, 'timestamp'),
                    data_get($signal, 'expires_at'), data_get($signal, 'message'),
                    $delivery->error_message, $delivery->signature,
                    json_encode($signal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ];

                fputcsv($output, array_map($this->csvCell(...), $row), ',', '"', '');
            }

            fclose($output);
        }, 'signal-history-'.CarbonImmutable::now(self::TIMEZONE)->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'strategy_slug' => ['nullable', 'string', 'max:255'],
            'pair_code' => ['nullable', 'string', 'max:20'],
            'action' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', Rule::in(['pending', 'sent', 'failed'])],
            'recipient' => ['nullable', 'string', 'max:254'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('from') ? ['after_or_equal:from'] : []),
        ]);
    }

    private function filteredQuery(array $filters): Builder
    {
        $query = SignalDelivery::query();

        foreach (['strategy_slug', 'pair_code', 'action', 'status'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['recipient'])) {
            $query->where('recipient', 'like', '%'.$filters['recipient'].'%');
        }

        if (! empty($filters['from'])) {
            $query->where('attempted_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], self::TIMEZONE)->utc());
        }

        if (! empty($filters['to'])) {
            $query->where('attempted_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], self::TIMEZONE)->addDay()->utc());
        }

        return $query;
    }

    private function csvCell(mixed $value): string
    {
        $text = is_array($value) || is_object($value)
            ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            : (string) $value;

        // Quoting a CSV field alone does not prevent spreadsheet formula execution.
        if (preg_match('/^(?:[=+\-@\t\r\n]|[\x00-\x20\x7F]*[=+\-@])/', $text)) {
            return "'".$text;
        }

        return $text;
    }
}

<x-admin-layout title="Signal History">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-6">
        <div>
            <p class="text-sm text-slate-300">Saved email attempts, with the original signal and trade plan for each recipient.</p>
            <p class="text-xs text-slate-400 mt-2">Sent means the configured mailer accepted the email; it does not confirm inbox delivery. Pending attempts have no recorded result yet. Dates use {{ $timezone }}.</p>
            <p class="text-xs text-slate-400 mt-1">History starts when recording was enabled. Older emails are not included automatically.</p>
        </div>
        <a href="{{ route('admin.signals.history.export', array_filter($filters, fn ($value) => $value !== null && $value !== '')) }}" class="shrink-0 px-4 py-2.5 rounded-xl border border-white/20 text-xs font-bold uppercase tracking-[0.12em] hover:bg-white/5">Export filtered CSV</a>
    </div>

    @if($errors->any())
        <div class="mb-6 rounded-xl border border-rose-300/30 bg-rose-500/10 px-4 py-3 text-rose-200 text-sm" role="alert">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="GET" action="{{ route('admin.signals.history') }}" class="rounded-2xl border border-white/10 bg-white/5 p-5 mb-6 grid sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div>
            <label for="strategy_slug" class="block text-xs text-slate-400 mb-2">Strategy</label>
            <select id="strategy_slug" name="strategy_slug" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                <option value="">All strategies</option>
                @foreach($strategySlugs as $slug)
                    <option value="{{ $slug }}" @selected(($filters['strategy_slug'] ?? '') === $slug)>{{ $slug }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="pair_code" class="block text-xs text-slate-400 mb-2">Pair</label>
            <select id="pair_code" name="pair_code" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                <option value="">All pairs</option>
                @foreach($pairCodes as $code)
                    <option value="{{ $code }}" @selected(($filters['pair_code'] ?? '') === $code)>{{ $code }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="action" class="block text-xs text-slate-400 mb-2">Action</label>
            <select id="action" name="action" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                <option value="">All actions</option>
                @foreach($actions as $action)
                    <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status" class="block text-xs text-slate-400 mb-2">Status</label>
            <select id="status" name="status" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                <option value="">All statuses</option>
                @foreach(['pending' => 'Pending', 'sent' => 'Sent', 'failed' => 'Failed'] as $status => $label)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="from" class="block text-xs text-slate-400 mb-2">From date ({{ $timezone }})</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
        </div>
        <div>
            <label for="to" class="block text-xs text-slate-400 mb-2">To date (inclusive)</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
        </div>
        <div>
            <label for="recipient" class="block text-xs text-slate-400 mb-2">Recipient contains</label>
            <input id="recipient" name="recipient" type="text" maxlength="254" value="{{ $filters['recipient'] ?? '' }}" placeholder="Email address" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
        </div>
        <div class="flex items-end gap-3">
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-amber-400 text-slate-950 text-xs font-bold uppercase tracking-[0.12em]">Apply filters</button>
            <a href="{{ route('admin.signals.history') }}" class="px-3 py-2.5 text-xs text-slate-300 hover:text-white">Reset</a>
        </div>
    </form>

    <p class="text-sm text-slate-400 mb-3">{{ number_format($deliveries->total()) }} {{ $deliveries->total() === 1 ? 'attempt' : 'attempts' }} found. Newest first.</p>

    <div class="rounded-2xl border border-white/10 bg-white/5 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-[11px] uppercase tracking-[0.12em] text-slate-400">
                    <tr>
                        <th class="px-5 py-3">Attempt / recipient</th>
                        <th class="px-5 py-3">Strategy / pair</th>
                        <th class="px-5 py-3">Action</th>
                        <th class="px-5 py-3">Entry / SL / targets</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Saved details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @forelse($deliveries as $delivery)
                        @php
                            $signal = $delivery->signal_payload ?? [];
                            $price = fn ($value) => is_numeric($value) ? number_format((float) $value, 4) : 'N/A';
                            $text = fn ($value) => is_scalar($value) ? (string) $value : ($value === null ? 'N/A' : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                            $statusClass = match ($delivery->status) {
                                'sent' => 'bg-emerald-500/20 text-emerald-200',
                                'failed' => 'bg-rose-500/20 text-rose-200',
                                default => 'bg-amber-500/20 text-amber-200',
                            };
                        @endphp
                        <tr class="align-top">
                            <td class="px-5 py-4">
                                <p class="whitespace-nowrap">{{ $delivery->attempted_at?->copy()->setTimezone($timezone)->format('Y-m-d H:i:s') ?? 'N/A' }}</p>
                                <p class="text-xs text-slate-400 mt-1 break-all">{{ $delivery->recipient }}</p>
                                <p class="text-xs text-slate-500 mt-1">Attempt #{{ $delivery->id }}{{ $delivery->forced ? ' · Forced run' : '' }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="font-semibold">{{ $delivery->strategy_name }}</p>
                                <p class="text-xs text-slate-400 mt-1">{{ $delivery->strategy_slug }}</p>
                                <p class="text-xs text-amber-300 mt-1">{{ $delivery->pair_code }}</p>
                            </td>
                            <td class="px-5 py-4 font-semibold">{{ $delivery->action }}</td>
                            <td class="px-5 py-4 whitespace-nowrap text-xs text-slate-300 space-y-1">
                                <p>Entry: {{ $price(data_get($signal, 'trade_plan.entry_price')) }}</p>
                                <p>SL: {{ $price(data_get($signal, 'trade_plan.stop_loss')) }}</p>
                                <p>TP1: {{ $price(data_get($signal, 'trade_plan.take_profit_1')) }}</p>
                                <p>TP2: {{ $price(data_get($signal, 'trade_plan.take_profit_2')) }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-block px-2 py-1 rounded-lg text-[11px] uppercase font-semibold {{ $statusClass }}">{{ $delivery->status }}</span>
                                @if($delivery->sent_at)
                                    <p class="text-xs text-slate-400 mt-2 whitespace-nowrap">{{ $delivery->sent_at->copy()->setTimezone($timezone)->format('Y-m-d H:i:s') }}</p>
                                @endif
                                @if($delivery->error_message)
                                    <p class="text-xs text-rose-200 mt-2 max-w-sm break-words">{{ $delivery->error_message }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-4 min-w-64 max-w-xl">
                                <details>
                                    <summary class="cursor-pointer text-amber-300 hover:text-amber-200 text-xs font-semibold">View original signal</summary>
                                    <dl class="mt-3 text-xs text-slate-300 space-y-2">
                                        <div><dt class="text-slate-400">Signal price</dt><dd>{{ $price(data_get($signal, 'price')) }}</dd></div>
                                        <div><dt class="text-slate-400">Original timestamp</dt><dd>{{ $text(data_get($signal, 'timestamp')) }}</dd></div>
                                        <div><dt class="text-slate-400">Original expiry</dt><dd>{{ $text(data_get($signal, 'expires_at')) }}</dd></div>
                                        <div><dt class="text-slate-400">Message</dt><dd class="whitespace-pre-wrap break-words">{{ $text(data_get($signal, 'message')) }}</dd></div>
                                        <div><dt class="text-slate-400">Mailer</dt><dd>{{ $delivery->mailer }}</dd></div>
                                        <div><dt class="text-slate-400">Signature</dt><dd class="break-all">{{ $delivery->signature }}</dd></div>
                                    </dl>
                                    <pre class="mt-3 max-h-80 overflow-auto rounded-lg bg-slate-950 p-3 text-xs text-slate-300 whitespace-pre-wrap break-all">{{ json_encode($signal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-8 text-center text-slate-400">No saved signal attempts match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $deliveries->links() }}</div>
</x-admin-layout>

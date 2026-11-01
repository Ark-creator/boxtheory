<x-admin-layout title="Auto Trader">
    @php
        $signalAction = strtoupper((string) ($latest_signal['action'] ?? 'NO_DATA'));
        $signalPlan = $latest_signal['trade_plan'] ?? [];
        $positionLabel = ! ($position_known ?? true) ? 'UNKNOWN' : ($position ? strtoupper($position) : 'FLAT');
        if ($settings->execution_mode === 'dry_run' && $settings->strategy_slug === 'gold-risk-breakout') { $positionLabel = 'INTENTS ONLY'; }
        $modeClass = $settings->execution_mode === 'bridge'
            ? 'bg-emerald-500/20 text-emerald-200'
            : 'bg-sky-500/20 text-sky-200';
        $actionClass = match ($signalAction) {
            'BUY' => 'bg-emerald-500/20 text-emerald-200',
            'SELL' => 'bg-rose-500/20 text-rose-200',
            'CLOSE' => 'bg-amber-500/20 text-amber-200',
            'HOLD' => 'bg-sky-500/20 text-sky-200',
            default => 'bg-zinc-500/20 text-zinc-200',
        };
    @endphp

    @if($errors->any())
        <div class="mb-6 rounded-xl border border-rose-300/30 bg-rose-500/10 px-4 py-3 text-rose-200 text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-4 mb-6">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <p class="text-xs uppercase tracking-[0.15em] text-slate-400">Status</p>
            <p class="text-3xl font-black mt-2">{{ $settings->enabled ? 'On' : 'Off' }}</p>
            <p class="text-xs text-slate-500 mt-1">{{ $settings->last_run_at ? $settings->last_run_at->format('M d, H:i:s') : 'No runs yet' }}</p>
        </div>
        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <p class="text-xs uppercase tracking-[0.15em] text-slate-400">Execution</p>
            <p class="mt-3">
                <span class="px-3 py-1 rounded-lg text-xs uppercase font-bold {{ $modeClass }}">{{ str_replace('_', ' ', $settings->execution_mode) }}</span>
            </p>
            <p class="text-xs text-slate-500 mt-3">{{ $settings->mt5_symbol }}</p>
        </div>
        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <p class="text-xs uppercase tracking-[0.15em] text-slate-400">Position Context</p>
            <p class="text-3xl font-black mt-2">{{ $positionLabel }}</p>
            <p class="text-xs text-slate-500 mt-1">{{ number_format((int) $today_count) }} handled today</p>
        </div>
        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <p class="text-xs uppercase tracking-[0.15em] text-slate-400">Latest Signal</p>
            <p class="mt-3">
                <span class="px-3 py-1 rounded-lg text-xs uppercase font-bold {{ $actionClass }}">{{ $signalAction }}</span>
            </p>
            <p class="text-xs text-slate-500 mt-3">{{ $latest_signal['timestamp'] ?? 'No timestamp' }}</p>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
        <form method="POST" action="{{ route('admin.auto-trader.update') }}" class="rounded-2xl border border-white/10 bg-white/5 p-5">
            @csrf
            @method('PUT')

            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between mb-6">
                <div>
                    <h2 class="text-sm uppercase tracking-[0.18em] text-slate-300 font-semibold">Trading Controls</h2>
                    <p class="text-sm text-slate-400 mt-2">VT Markets gold automation. Gold Risk Breakout is a research candidate with unverified profitability. Start with dry run and demo. MT5 enforces risk sizing, one position, 1% daily loss, 3% weekly loss and 5% drawdown limits. A current news calendar is required. Confirm your exact Market Watch symbol. Dry run logs signals without simulating fills.</p>
                </div>
                <label class="inline-flex items-center gap-3 rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-sm font-semibold">
                    <input type="checkbox" name="enabled" value="1" @checked($settings->enabled) class="rounded border-white/20 bg-slate-900 text-amber-400 focus:ring-amber-400">
                    Enabled
                </label>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="execution_mode" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Execution Mode</label>
                    <select id="execution_mode" name="execution_mode" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                        <option value="dry_run" @selected($settings->execution_mode === 'dry_run')>Dry run</option>
                        <option value="bridge" @selected($settings->execution_mode === 'bridge')>MT5 bridge</option>
                    </select>
                </div>

                <div>
                    <label for="account_type" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">VT Account</label>
                    <select id="account_type" name="account_type" data-symbol-select class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                        <option value="cent" data-symbol="{{ $symbols['cent'] }}" @selected($settings->account_type === 'cent')>Dollar cents</option>
                        <option value="dollar" data-symbol="{{ $symbols['dollar'] }}" @selected($settings->account_type === 'dollar')>Dollar</option>
                    </select>
                </div>

                <div>
                    <label for="mt5_symbol" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">MT5 Symbol</label>
                    <input id="mt5_symbol" name="mt5_symbol" data-symbol-input value="{{ old('mt5_symbol', $settings->mt5_symbol) }}" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                </div>

                <div>
                    <label for="pair_code" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Signal Pair</label>
                    <select id="pair_code" name="pair_code" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                        @foreach(($pairs ?? []) as $code => $pairItem)
                            <option value="{{ $code }}" @selected($settings->pair_code === $code)>{{ $pairItem['display'] }} - {{ $pairItem['name'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="strategy_slug" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Strategy</label>
                    <select id="strategy_slug" name="strategy_slug" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                        @foreach($strategies as $strategy)
                            <option value="{{ $strategy->slug }}" @selected($settings->strategy_slug === $strategy->slug)>{{ $strategy->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="lot_size" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Reference lots (MT5 uses risk sizing)</label>
                    <input id="lot_size" name="lot_size" type="number" step="0.01" min="0.01" value="{{ old('lot_size', $settings->lot_size) }}" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                </div>

                <div>
                    <label for="max_trades_per_day" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Max Trades / Day</label>
                    <input id="max_trades_per_day" name="max_trades_per_day" type="number" min="1" value="{{ old('max_trades_per_day', $settings->max_trades_per_day) }}" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                </div>

                <div>
                    <label for="max_open_trades" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Max Open Trades</label>
                    <input id="max_open_trades" name="max_open_trades" type="number" min="1" value="{{ old('max_open_trades', $settings->max_open_trades) }}" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                </div>

                <div>
                    <label for="cooldown_minutes" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Cooldown Minutes</label>
                    <input id="cooldown_minutes" name="cooldown_minutes" type="number" min="0" value="{{ old('cooldown_minutes', $settings->cooldown_minutes) }}" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                </div>

                <div>
                    <label for="risk_percent_per_trade" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Equity risk per trade (0.01?0.5%)</label>
                    <input id="risk_percent_per_trade" name="risk_percent_per_trade" type="number" step="0.01" min="0.01" max="0.5" required value="{{ old('risk_percent_per_trade', $settings->risk_percent_per_trade ?? 0.25) }}" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm" placeholder="Optional">
                </div>

                <div>
                    <label for="max_daily_loss" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Additional daily loss cap (account currency)</label>
                    <input id="max_daily_loss" name="max_daily_loss" type="number" step="0.01" min="0" value="{{ old('max_daily_loss', $settings->max_daily_loss) }}" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm" placeholder="Optional">
                </div>

                <div>
                    <label for="bridge_url" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Bridge URL</label>
                    <input id="bridge_url" name="bridge_url" value="{{ old('bridge_url', $settings->bridge_url) }}" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm" placeholder="http://127.0.0.1:8710/trade">
                </div>

                <div>
                    <label for="bridge_token" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Bridge Token</label>
                    <input id="bridge_token" name="bridge_token" type="password" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm" placeholder="{{ $settings->bridge_token ? 'Configured' : 'Optional' }}">
                </div>
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-2">
                <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-sm font-semibold">
                    <input type="checkbox" name="allow_buy" value="1" @checked($settings->allow_buy) class="rounded border-white/20 bg-slate-900 text-emerald-400 focus:ring-emerald-400">
                    Allow buy
                </label>
                <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-sm font-semibold">
                    <input type="checkbox" name="allow_sell" value="1" @checked($settings->allow_sell) class="rounded border-white/20 bg-slate-900 text-rose-400 focus:ring-rose-400">
                    Allow sell
                </label>
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
                <button type="submit" class="px-5 py-3 rounded-xl bg-amber-400 text-slate-950 text-xs font-black uppercase tracking-[0.14em]">
                    Save Settings
                </button>
            </div>
        </form>

        <div class="space-y-6">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-sm uppercase tracking-[0.18em] text-slate-300 font-semibold">Signal Preview</h2>
                        <p class="text-sm text-slate-400 mt-2">{{ $latest_signal['message'] ?? 'No signal message.' }}</p>
                    </div>
                    <span class="px-3 py-1 rounded-lg text-xs uppercase font-bold {{ $actionClass }}">{{ $signalAction }}</span>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-xl bg-slate-950/50 p-3">
                        <dt class="text-xs uppercase tracking-[0.12em] text-slate-500">Price</dt>
                        <dd class="font-bold mt-1">${{ number_format((float) ($latest_signal['price'] ?? 0), 4) }}</dd>
                    </div>
                    <div class="rounded-xl bg-slate-950/50 p-3">
                        <dt class="text-xs uppercase tracking-[0.12em] text-slate-500">Entry</dt>
                        <dd class="font-bold mt-1">{{ isset($signalPlan['entry_price']) && $signalPlan['entry_price'] !== null ? '$'.number_format((float) $signalPlan['entry_price'], 4) : 'N/A' }}</dd>
                    </div>
                    <div class="rounded-xl bg-slate-950/50 p-3">
                        <dt class="text-xs uppercase tracking-[0.12em] text-slate-500">Stop</dt>
                        <dd class="font-bold mt-1">{{ isset($signalPlan['stop_loss']) && $signalPlan['stop_loss'] !== null ? '$'.number_format((float) $signalPlan['stop_loss'], 4) : 'N/A' }}</dd>
                    </div>
                    <div class="rounded-xl bg-slate-950/50 p-3">
                        <dt class="text-xs uppercase tracking-[0.12em] text-slate-500">TP1</dt>
                        <dd class="font-bold mt-1">{{ isset($signalPlan['take_profit_1']) && $signalPlan['take_profit_1'] !== null ? '$'.number_format((float) $signalPlan['take_profit_1'], 4) : 'N/A' }}</dd>
                    </div>
                </dl>

                <form method="POST" action="{{ route('admin.auto-trader.run-now') }}" class="mt-5">
                    @csrf
                    <button type="submit" class="w-full px-5 py-3 rounded-xl bg-emerald-500 text-white text-xs font-black uppercase tracking-[0.14em]">
                        Run Auto Trader Now
                    </button>
                </form>
            </div>

            <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <h2 class="text-sm uppercase tracking-[0.18em] text-slate-300 font-semibold">VT Symbols</h2>
                <div class="mt-4 space-y-3 text-sm">
                    <div class="flex items-center justify-between rounded-xl bg-slate-950/50 px-4 py-3">
                        <span class="text-slate-400">Dollar cents</span>
                        <span class="font-bold text-amber-200">{{ $symbols['cent'] }}</span>
                    </div>
                    <div class="flex items-center justify-between rounded-xl bg-slate-950/50 px-4 py-3">
                        <span class="text-slate-400">Dollar</span>
                        <span class="font-bold text-amber-200">{{ $symbols['dollar'] }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-6 rounded-2xl border border-white/10 bg-white/5 overflow-hidden">
        <div class="px-5 py-4 border-b border-white/10 flex items-center justify-between">
            <h2 class="text-sm uppercase tracking-[0.18em] text-slate-300 font-semibold">Trade Audit Log</h2>
            <span class="text-xs text-slate-500">Latest 25</span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-[11px] uppercase tracking-[0.12em] text-slate-400">
                    <tr>
                        <th class="px-5 py-3">Time</th>
                        <th class="px-5 py-3">Symbol</th>
                        <th class="px-5 py-3">Action</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Volume</th>
                        <th class="px-5 py-3">Entry</th>
                        <th class="px-5 py-3">SL</th>
                        <th class="px-5 py-3">TP1</th>
                        <th class="px-5 py-3">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @forelse($orders as $order)
                        @php
                            $orderAction = strtoupper($order->action);
                            $orderActionClass = match ($orderAction) {
                                'BUY' => 'bg-emerald-500/20 text-emerald-200',
                                'SELL' => 'bg-rose-500/20 text-rose-200',
                                'CLOSE' => 'bg-amber-500/20 text-amber-200',
                                default => 'bg-zinc-500/20 text-zinc-200',
                            };
                            $statusClass = match ($order->status) {
                                'executed', 'accepted', 'sent', 'dry_run' => 'bg-emerald-500/20 text-emerald-200',
                                'rejected' => 'bg-amber-500/20 text-amber-200',
                                'failed' => 'bg-rose-500/20 text-rose-200',
                                default => 'bg-zinc-500/20 text-zinc-200',
                            };
                        @endphp
                        <tr>
                            <td class="px-5 py-3 text-slate-300">{{ $order->created_at?->format('M d, H:i:s') }}</td>
                            <td class="px-5 py-3">
                                <p class="font-semibold">{{ $order->mt5_symbol }}</p>
                                <p class="text-xs text-slate-500">{{ $order->strategy_slug }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-1 rounded-lg text-[11px] uppercase font-semibold {{ $orderActionClass }}">{{ $orderAction }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-1 rounded-lg text-[11px] uppercase font-semibold {{ $statusClass }}">{{ str_replace('_', ' ', $order->status) }}</span>
                            </td>
                            <td class="px-5 py-3 text-slate-300">{{ number_format((float) $order->volume, 2) }}</td>
                            <td class="px-5 py-3 text-slate-300">{{ $order->entry_price !== null ? '$'.number_format((float) $order->entry_price, 4) : 'N/A' }}</td>
                            <td class="px-5 py-3 text-slate-300">{{ $order->stop_loss !== null ? '$'.number_format((float) $order->stop_loss, 4) : 'N/A' }}</td>
                            <td class="px-5 py-3 text-slate-300">{{ $order->take_profit_1 !== null ? '$'.number_format((float) $order->take_profit_1, 4) : 'N/A' }}</td>
                            <td class="px-5 py-3 text-slate-300 max-w-md">{{ $order->error_message ?? ($order->bridge_response['message'] ?? 'Logged') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-5 py-6 text-center text-slate-400">No auto-trader orders yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const accountSelect = document.querySelector('[data-symbol-select]');
        const symbolInput = document.querySelector('[data-symbol-input]');

        accountSelect?.addEventListener('change', () => {
            const option = accountSelect.options[accountSelect.selectedIndex];
            const symbol = option?.dataset?.symbol;

            if (symbol && symbolInput) {
                symbolInput.value = symbol;
            }
        });
    </script>
</x-admin-layout>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="60">
    <title>{{ $strategy->name }} Signals | GoldLogic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #050913; }
    </style>
</head>
<body class="text-slate-100 min-h-screen">
    @php
        $action = strtoupper($signal['action'] ?? 'NO_DATA');
        $badgeClass = match ($action) {
            'BUY' => 'bg-emerald-500/15 text-emerald-300 border border-emerald-400/40',
            'SELL' => 'bg-rose-500/15 text-rose-300 border border-rose-400/40',
            'CLOSE' => 'bg-amber-500/15 text-amber-300 border border-amber-400/40',
            'HOLD' => 'bg-sky-500/15 text-sky-300 border border-sky-400/40',
            default => 'bg-zinc-500/15 text-zinc-300 border border-zinc-400/30',
        };

        $positionValue = request('position', $position ?? '');
        $plan = $signal['trade_plan'] ?? [];
        $indicators = $signal['indicators'] ?? [];
        $trendValue = strtoupper((string) ($signal['trend'] ?? 'unknown'));
        $dataSource = (string) ($indicators['data_source'] ?? 'n/a');
        $pairs = is_array($pairs ?? null) ? $pairs : [];
        $selectedPairCode = $pair ?? ($signal['symbol_code'] ?? 'XAUUSD');
        $selectedPair = $pairs[$selectedPairCode] ?? null;
        $selectedPairDisplay = $signal['symbol'] ?? ($selectedPair['display'] ?? 'XAU/USD');
        $selectedPairName = $selectedPair['name'] ?? ($signal['pair_name'] ?? 'Market Pair');
    @endphp

    <div class="max-w-5xl mx-auto px-6 py-10">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-10">
            <div>
                <p class="text-xs uppercase tracking-[0.25em] text-amber-400 font-bold">{{ $selectedPairDisplay }} Strategy Maker</p>
                <h1 class="text-3xl md:text-4xl font-black mt-2">{{ $strategy->name }}</h1>
                <p class="text-sm text-slate-400 mt-2">Live market: {{ $selectedPairName }}. Page refreshes every 60 seconds.</p>
            </div>
            <div class="flex flex-col sm:flex-row gap-2">
                <form method="GET" action="{{ route('signals.show', $strategy->slug) }}" class="flex items-center gap-2">
                    <input type="hidden" name="position" value="{{ $positionValue }}">
                    <select id="pair" name="pair" class="bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm min-w-44">
                        @foreach($pairs as $code => $pairItem)
                            <option value="{{ $code }}" @selected($selectedPairCode === $code)>{{ $pairItem['display'] }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-amber-400 text-slate-950 text-xs font-black uppercase tracking-[0.14em]">
                        Switch Pair
                    </button>
                </form>
                <a href="{{ route('watchlist.index', ['position' => $positionValue]) }}" class="inline-flex items-center justify-center px-5 py-3 rounded-xl border border-white/15 text-xs uppercase tracking-[0.2em] font-bold hover:bg-white/5">
                    My Watchlist
                </a>
                <a href="{{ route('strategies.index', ['pair' => $selectedPairCode]) }}" class="inline-flex items-center justify-center px-5 py-3 rounded-xl border border-white/15 text-xs uppercase tracking-[0.2em] font-bold hover:bg-white/5">
                    Back to Strategies
                </a>
            </div>
        </div>

        <div class="grid lg:grid-cols-3 gap-6 mb-6">
            <div class="lg:col-span-2 rounded-2xl border border-white/10 bg-white/5 p-6">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-400 font-bold">Live Action</p>
                    <span class="px-4 py-2 rounded-full text-xs font-black tracking-[0.2em] uppercase {{ $badgeClass }}">{{ $action }}</span>
                </div>

                <div class="grid sm:grid-cols-3 gap-3 mb-5">
                    <div class="rounded-xl bg-slate-950/60 border border-white/10 p-3">
                        <p class="text-[10px] uppercase tracking-[0.12em] text-slate-500 font-bold">Price</p>
                        <p class="text-2xl font-black mt-1">${{ number_format((float) ($signal['price'] ?? 0), 4) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-950/60 border border-white/10 p-3">
                        <p class="text-[10px] uppercase tracking-[0.12em] text-slate-500 font-bold">Candle Timestamp</p>
                        <p class="text-sm font-semibold mt-1">{{ $signal['timestamp'] ?? 'N/A' }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-950/60 border border-white/10 p-3">
                        <p class="text-[10px] uppercase tracking-[0.12em] text-slate-500 font-bold">Trend / Source</p>
                        <p class="text-sm font-semibold mt-1">{{ $trendValue }}</p>
                        <p class="text-xs text-slate-400 mt-1">{{ $dataSource }}</p>
                    </div>
                </div>

                <p class="text-sm text-slate-200 leading-relaxed">{{ $signal['message'] ?? 'No message available.' }}</p>
            </div>

            <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-400 font-bold mb-4">Position Context</p>
                <form method="GET" action="{{ route('signals.show', $strategy->slug) }}" class="space-y-4">
                    <input type="hidden" name="pair" value="{{ $selectedPairCode }}">
                    <label class="text-xs uppercase tracking-[0.12em] text-slate-400 font-bold block">Current Open Position</label>
                    <select id="position" name="position" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                        <option value="" @selected($positionValue === '')>No open position</option>
                        <option value="long" @selected($positionValue === 'long')>Long / Buy</option>
                        <option value="short" @selected($positionValue === 'short')>Short / Sell</option>
                    </select>
                    <button type="submit" class="w-full px-4 py-3 rounded-xl bg-amber-400 text-slate-950 text-xs font-black uppercase tracking-[0.2em]">
                        Refresh Signal
                    </button>
                </form>
                <p class="text-xs text-slate-500 mt-4">Set this so the engine can emit accurate `CLOSE` signals.</p>
            </div>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/5 p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-400 font-bold mb-4">Trade Plan</p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="rounded-xl bg-slate-950/60 border border-white/10 p-3">
                    <p class="text-[10px] uppercase tracking-[0.12em] text-slate-500 font-bold">Entry</p>
                    <p class="text-sm font-semibold mt-1">{{ isset($plan['entry_price']) && $plan['entry_price'] !== null ? '$'.number_format((float)$plan['entry_price'], 4) : 'N/A' }}</p>
                </div>
                <div class="rounded-xl bg-slate-950/60 border border-white/10 p-3">
                    <p class="text-[10px] uppercase tracking-[0.12em] text-slate-500 font-bold">Stop Loss</p>
                    <p class="text-sm font-semibold mt-1">{{ isset($plan['stop_loss']) && $plan['stop_loss'] !== null ? '$'.number_format((float)$plan['stop_loss'], 4) : 'N/A' }}</p>
                </div>
                <div class="rounded-xl bg-slate-950/60 border border-white/10 p-3">
                    <p class="text-[10px] uppercase tracking-[0.12em] text-slate-500 font-bold">Take Profit 1</p>
                    <p class="text-sm font-semibold mt-1">{{ isset($plan['take_profit_1']) && $plan['take_profit_1'] !== null ? '$'.number_format((float)$plan['take_profit_1'], 4) : 'N/A' }}</p>
                </div>
                <div class="rounded-xl bg-slate-950/60 border border-white/10 p-3">
                    <p class="text-[10px] uppercase tracking-[0.12em] text-slate-500 font-bold">Take Profit 2</p>
                    <p class="text-sm font-semibold mt-1">{{ isset($plan['take_profit_2']) && $plan['take_profit_2'] !== null ? '$'.number_format((float)$plan['take_profit_2'], 4) : 'N/A' }}</p>
                </div>
            </div>
            <p class="text-xs text-slate-500 mt-4">{{ $plan['notes'] ?? 'No plan notes available.' }}</p>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/5 p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-400 font-bold mb-4">Indicators</p>

            @if(!empty($indicators))
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach($indicators as $label => $value)
                        <div class="rounded-xl bg-slate-950/60 border border-white/10 p-3">
                            <p class="text-[10px] uppercase tracking-[0.12em] text-slate-500 font-bold">{{ str_replace('_', ' ', $label) }}</p>
                            <p class="text-sm font-semibold mt-1">
                                @if(is_bool($value))
                                    {{ $value ? 'Yes' : 'No' }}
                                @else
                                    {{ $value }}
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-slate-400">No indicators available for this signal.</p>
            @endif
        </div>

        @if(!empty($signal['error']))
            <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 p-5">
                <p class="text-xs uppercase tracking-[0.15em] font-bold text-rose-300 mb-2">API / Strategy Error</p>
                <p class="text-sm text-rose-100">{{ $signal['error'] }}</p>
            </div>
        @endif

        <div class="mt-8 text-xs text-slate-500">
            JSON endpoint:
            <code class="text-slate-300">{{ route('signals.latest', ['strategy' => $strategy->slug, 'pair' => $selectedPairCode]) }}</code>
        </div>
    </div>
</body>
</html>

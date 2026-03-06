<x-admin-layout title="Signals Matrix">
    <!-- Header Section -->
    <div class="mb-8">
        <div class="flex flex-col xl:flex-row xl:items-end xl:justify-between gap-6">
            
            <!-- Title & Filter -->
            <div class="flex-1">
                <h1 class="text-2xl font-bold text-white mb-2 tracking-tight">Market Signals Matrix</h1>
                <p class="text-slate-400 text-sm mb-6 max-w-2xl">
                    Real-time technical analysis across multiple strategies and asset pairs. 
                    Monitor buy/sell signals, trend direction, and price action in a consolidated view.
                </p>

                <form method="GET" action="{{ route('admin.signals.all-pairs') }}" class="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
                    <div class="relative group w-full sm:w-64">
                        <div class="absolute -inset-0.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 rounded-xl opacity-20 group-hover:opacity-40 transition duration-500 blur"></div>
                        <select id="position" name="position" onchange="this.form.submit()" class="relative w-full bg-slate-950 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-slate-200 focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 outline-none appearance-none cursor-pointer shadow-xl">
                            <option value="" @selected(($position ?? '') === '')>All Positions</option>
                            <option value="long" @selected(($position ?? '') === 'long')>Long / Buy Opportunities</option>
                            <option value="short" @selected(($position ?? '') === 'short')>Short / Sell Opportunities</option>
                        </select>
                        <div class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-slate-500">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </div>
                    </div>
                    
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 border border-white/10 text-slate-300 hover:text-white transition-colors shadow-lg flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                        <span class="text-xs font-bold uppercase tracking-wider">Refresh</span>
                    </button>
                </form>
            </div>

            <!-- Actions & Stats -->
            <div class="flex flex-col items-start xl:items-end gap-3">
                <a href="{{ route('admin.signals', ['position' => ($position ?? null)]) }}" class="group relative inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-900 border border-white/10 overflow-hidden transition-all hover:border-indigo-500/50">
                    <div class="absolute inset-0 bg-white/5 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                    <span class="relative text-xs font-bold uppercase tracking-widest text-slate-300 group-hover:text-white">Single Pair View</span>
                    <svg class="relative w-4 h-4 text-slate-500 group-hover:text-indigo-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path></svg>
                </a>

                <div class="flex flex-col xl:items-end gap-1">
                    <div class="flex items-center gap-2 text-[11px] text-slate-500 uppercase tracking-wider font-medium">
                        <span>Generated {{ $generatedAt->diffForHumans() }}</span>
                        <span class="w-1 h-1 rounded-full bg-slate-700"></span>
                        <span>Cache: {{ (int) round($matrixCacheSeconds / 60) }}m</span>
                    </div>
                    <div class="text-[10px] text-slate-600">
                        Showing {{ $matrixPairCount }} of {{ $allPairCount }} pairs • Refreshed: {{ $refreshedPairs }} (Limit: {{ $pairRefreshBudget }})
                    </div>
                </div>
            </div>
        </div>

        @if(($pendingPairs ?? 0) > 0)
            <div class="mt-6 relative overflow-hidden rounded-xl bg-amber-500/10 border border-amber-500/20 p-4">
                <div class="flex items-center gap-3">
                    <div class="relative flex h-3 w-3">
                      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                      <span class="relative inline-flex rounded-full h-3 w-3 bg-amber-500"></span>
                    </div>
                    <p class="text-xs font-medium text-amber-200">
                        <span class="font-bold">System Warming Up:</span> {{ $pendingPairs }} pairs are currently pending analysis to respect API rate limits. 
                        Data may be partial. Please reload in a minute.
                    </p>
                </div>
            </div>
        @endif
    </div>

    <!-- Matrix Container -->
    <div class="rounded-3xl border border-white/5 bg-slate-900/40 backdrop-blur-xl shadow-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-20 bg-slate-950/95 backdrop-blur border-b border-r border-white/10 p-5 min-w-[220px]">
                            <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Asset Pair</span>
                        </th>
                        @foreach($strategies as $strategy)
                            <th class="min-w-[300px] p-4 border-b border-white/5 bg-slate-950/50">
                                <div class="flex items-center gap-3">
                                    <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 font-bold text-xs">
                                        {{ substr($strategy->name, 0, 2) }}
                                    </div>
                                    <div>
                                        <div class="text-sm font-bold text-slate-200">{{ $strategy->name }}</div>
                                        <div class="text-[10px] font-mono text-slate-500 uppercase tracking-wider">{{ $strategy->slug }}</div>
                                    </div>
                                </div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @foreach($pairs as $pairCode => $pairItem)
                        <tr class="group hover:bg-white/[0.02] transition-colors">
                            <!-- Sticky Pair Info -->
                            <td class="sticky left-0 z-10 bg-slate-950/95 group-hover:bg-slate-900/95 border-r border-white/10 p-5 align-top transition-colors">
                                <div class="flex flex-col h-full gap-1">
                                    <div class="flex items-center justify-between">
                                        <span class="text-lg font-bold text-slate-100 tracking-tight">{{ $pairItem['display'] }}</span>
                                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 border border-slate-700">
                                            {{ $pairItem['asset_class'] ?? 'MKT' }}
                                        </span>
                                    </div>
                                    <div class="text-xs text-slate-500 font-medium truncate" title="{{ $pairItem['name'] }}">
                                        {{ $pairItem['name'] }}
                                    </div>
                                </div>
                            </td>

                            <!-- Strategy Columns -->
                            @foreach($strategies as $strategy)
                                @php
                                    $signal = $matrix[$pairCode][$strategy->id] ?? null;
                                    $action = strtoupper((string) ($signal['action'] ?? 'NO_DATA'));
                                    $trend = strtoupper((string) ($signal['trend'] ?? 'UNKNOWN'));
                                    
                                    // Visual Theme Logic
                                    $theme = match ($action) {
                                        'BUY' => [
                                            'card' => 'bg-emerald-500/5 border-emerald-500/20 hover:border-emerald-500/40',
                                            'text_main' => 'text-emerald-400',
                                            'badge' => 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20',
                                            'trend_icon' => 'text-emerald-500'
                                        ],
                                        'SELL' => [
                                            'card' => 'bg-rose-500/5 border-rose-500/20 hover:border-rose-500/40',
                                            'text_main' => 'text-rose-400',
                                            'badge' => 'bg-rose-500/10 text-rose-300 border-rose-500/20',
                                            'trend_icon' => 'text-rose-500'
                                        ],
                                        'HOLD' => [
                                            'card' => 'bg-blue-500/5 border-blue-500/10 hover:border-blue-500/30',
                                            'text_main' => 'text-blue-400',
                                            'badge' => 'bg-blue-500/10 text-blue-300 border-blue-500/20',
                                            'trend_icon' => 'text-blue-500'
                                        ],
                                        'CLOSE' => [
                                            'card' => 'bg-amber-500/5 border-amber-500/20 hover:border-amber-500/40',
                                            'text_main' => 'text-amber-400',
                                            'badge' => 'bg-amber-500/10 text-amber-300 border-amber-500/20',
                                            'trend_icon' => 'text-amber-500'
                                        ],
                                        default => [
                                            'card' => 'bg-slate-800/20 border-white/5 hover:border-white/10',
                                            'text_main' => 'text-slate-500',
                                            'badge' => 'bg-slate-700/30 text-slate-400 border-transparent',
                                            'trend_icon' => 'text-slate-600'
                                        ],
                                    };
                                    
                                    $hasSignal = $action !== 'NO_DATA';
                                @endphp
                                <td class="p-3 align-top h-full">
                                    <div class="h-full flex flex-col justify-between rounded-xl border {{ $theme['card'] }} p-4 transition-all duration-200 hover:shadow-lg hover:shadow-black/20 group/card">
                                        
                                        <!-- Top Row: Action & Trend -->
                                        <div class="flex items-start justify-between mb-3">
                                            <div>
                                                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-0.5">Action</div>
                                                <div class="text-lg font-black {{ $theme['text_main'] }} tracking-tight">{{ $action }}</div>
                                            </div>
                                            
                                            @if($hasSignal)
                                                <div class="flex flex-col items-end">
                                                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-0.5">Trend</div>
                                                    <div class="flex items-center gap-1.5 px-2 py-1 rounded-md border {{ $theme['badge'] }}">
                                                        @if(str_contains($trend, 'BULL') || str_contains($trend, 'UP'))
                                                            <svg class="w-3 h-3 {{ $theme['trend_icon'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                                                        @elseif(str_contains($trend, 'BEAR') || str_contains($trend, 'DOWN'))
                                                            <svg class="w-3 h-3 {{ $theme['trend_icon'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path></svg>
                                                        @else
                                                            <svg class="w-3 h-3 {{ $theme['trend_icon'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 12h14"></path></svg>
                                                        @endif
                                                        <span class="text-[10px] font-bold">{{ $trend }}</span>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>

                                        @if($hasSignal)
                                            <!-- Middle: Price & Time -->
                                            <div class="space-y-2 mb-4">
                                                <div class="flex justify-between items-baseline border-b border-white/5 pb-2">
                                                    <span class="text-xs text-slate-500 font-medium">Price</span>
                                                    <span class="font-mono text-sm font-bold text-slate-200">${{ number_format((float) ($signal['price'] ?? 0), 4) }}</span>
                                                </div>
                                                <div class="flex justify-between items-baseline">
                                                    <span class="text-xs text-slate-500 font-medium">Time</span>
                                                    <span class="text-xs text-slate-400 font-mono">{{ \Illuminate\Support\Carbon::parse($signal['timestamp'])->format('H:i:s') }}</span>
                                                </div>
                                            </div>

                                            <!-- Message -->
                                            <div class="relative mb-4">
                                                <p class="text-[11px] text-slate-400 leading-relaxed line-clamp-2 min-h-[2.5em]">
                                                    {{ $signal['message'] ?? '' }}
                                                </p>
                                            </div>

                                            <!-- Footer: Buttons -->
                                            <div class="mt-auto pt-3 border-t border-white/5 flex gap-2 opacity-60 group-hover/card:opacity-100 transition-opacity">
                                                <a href="{{ route('signals.show', ['strategy' => $strategy->slug, 'pair' => $pairCode, 'position' => ($position ?? null)]) }}" class="flex-1 flex items-center justify-center gap-1.5 py-1.5 rounded-lg bg-white/5 hover:bg-indigo-500/20 hover:text-indigo-300 text-[10px] font-bold uppercase tracking-wider text-slate-300 transition-all">
                                                    <span>Analyze</span>
                                                </a>
                                                <a href="{{ route('signals.latest', ['strategy' => $strategy->slug, 'pair' => $pairCode, 'position' => ($position ?? null)]) }}" class="px-2.5 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white transition-colors" title="Raw JSON">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>
                                                </a>
                                            </div>
                                        @else
                                            <div class="flex-1 flex flex-col items-center justify-center opacity-20 gap-2">
                                                <div class="w-8 h-1 bg-slate-500 rounded-full"></div>
                                                <span class="text-[10px] font-mono text-slate-500">NO SIGNAL</span>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-admin-layout>

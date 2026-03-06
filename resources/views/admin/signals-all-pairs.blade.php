<x-admin-layout title="Signals Matrix">
    <div class="rounded-2xl border border-white/10 bg-white/5 p-5 mb-6">
        <div class="flex flex-col xl:flex-row xl:items-end xl:justify-between gap-4">
            <form method="GET" action="{{ route('admin.signals.all-pairs') }}" class="flex flex-col sm:flex-row gap-3 sm:items-end">
                <div class="w-full sm:w-72">
                    <label for="position" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Open Position Context</label>
                    <select id="position" name="position" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                        <option value="" @selected(($position ?? '') === '')>No open position</option>
                        <option value="long" @selected(($position ?? '') === 'long')>Long / Buy</option>
                        <option value="short" @selected(($position ?? '') === 'short')>Short / Sell</option>
                    </select>
                </div>
                <button type="submit" class="px-4 py-2.5 rounded-xl bg-amber-400 text-slate-950 text-xs font-bold uppercase tracking-[0.14em]">
                    Refresh All Pairs
                </button>
            </form>

            <div class="flex items-center gap-3">
                <a href="{{ route('admin.signals', ['position' => ($position ?? null)]) }}" class="px-4 py-2.5 rounded-xl border border-white/20 text-xs font-bold uppercase tracking-[0.14em] text-slate-200 hover:bg-white/5">
                    Single Pair View
                </a>
                <p class="text-xs text-slate-400">Generated {{ $generatedAt->format('Y-m-d H:i:s') }}</p>
            </div>
        </div>
        <p class="text-xs text-slate-500 mt-4">
            Showing {{ $matrixPairCount }} of {{ $allPairCount }} configured pairs.
            Refreshed this request: {{ $refreshedPairs }} pair(s) (limit {{ $pairRefreshBudget }}).
            Snapshot cache: {{ (int) round($matrixCacheSeconds / 60) }} min.
        </p>
        @if(($pendingPairs ?? 0) > 0)
            <p class="text-xs text-amber-300 mt-2">
                {{ $pendingPairs }} pair(s) are still warming cache to stay under API quota. Reload after a minute.
            </p>
        @endif
    </div>

    <div class="rounded-2xl border border-white/10 bg-white/5 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-[1200px] w-full text-sm align-top">
                <thead class="text-left text-[11px] uppercase tracking-[0.12em] text-slate-400 bg-slate-950/50">
                    <tr>
                        <th class="px-4 py-3 sticky left-0 bg-slate-950/90 backdrop-blur border-r border-white/10 z-10">Pair</th>
                        @foreach($strategies as $strategy)
                            <th class="px-4 py-3 min-w-60">
                                <p class="font-semibold text-slate-200">{{ $strategy->name }}</p>
                                <p class="text-[10px] text-slate-500 normal-case">{{ $strategy->slug }}</p>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @foreach($pairs as $pairCode => $pairItem)
                        <tr>
                            <td class="px-4 py-4 sticky left-0 bg-slate-950/85 backdrop-blur border-r border-white/10 align-top">
                                <p class="font-semibold text-slate-100">{{ $pairItem['display'] }}</p>
                                <p class="text-xs text-slate-400">{{ $pairItem['name'] }}</p>
                                <p class="text-[10px] text-slate-500 mt-1 uppercase tracking-[0.12em]">{{ $pairItem['asset_class'] ?? 'Market' }}</p>
                            </td>
                            @foreach($strategies as $strategy)
                                @php
                                    $signal = $matrix[$pairCode][$strategy->id] ?? null;
                                    $action = strtoupper((string) ($signal['action'] ?? 'NO_DATA'));
                                    $actionClass = match ($action) {
                                        'BUY' => 'bg-emerald-500/20 text-emerald-200 border-emerald-400/30',
                                        'SELL' => 'bg-rose-500/20 text-rose-200 border-rose-400/30',
                                        'CLOSE' => 'bg-amber-500/20 text-amber-200 border-amber-400/30',
                                        'HOLD' => 'bg-sky-500/20 text-sky-200 border-sky-400/30',
                                        default => 'bg-zinc-500/20 text-zinc-200 border-zinc-400/30',
                                    };
                                @endphp
                                <td class="px-4 py-4 align-top">
                                    <div class="rounded-xl border border-white/10 bg-slate-950/40 p-3">
                                        <div class="flex items-center justify-between gap-2 mb-2">
                                            <span class="px-2 py-1 rounded-lg border text-[11px] uppercase font-semibold {{ $actionClass }}">{{ $action }}</span>
                                            <span class="text-[10px] text-slate-500 uppercase tracking-[0.12em]">{{ strtoupper((string) ($signal['trend'] ?? 'unknown')) }}</span>
                                        </div>

                                        <p class="text-base font-bold text-slate-100">
                                            ${{ number_format((float) ($signal['price'] ?? 0), 4) }}
                                        </p>
                                        <p class="text-[11px] text-slate-500 mt-1">{{ $signal['timestamp'] ?? 'N/A' }}</p>
                                        <p class="text-xs text-slate-300 mt-2 leading-relaxed">
                                            {{ \Illuminate\Support\Str::limit((string) ($signal['message'] ?? 'N/A'), 120) }}
                                        </p>

                                        <div class="mt-3 pt-3 border-t border-white/10 flex items-center gap-3">
                                            <a href="{{ route('signals.show', ['strategy' => $strategy->slug, 'pair' => $pairCode, 'position' => ($position ?? null)]) }}" class="text-[10px] text-amber-300 hover:text-amber-200 uppercase tracking-[0.12em] font-semibold">
                                                Open
                                            </a>
                                            <a href="{{ route('signals.latest', ['strategy' => $strategy->slug, 'pair' => $pairCode, 'position' => ($position ?? null)]) }}" class="text-[10px] text-slate-300 hover:text-white uppercase tracking-[0.12em] font-semibold">
                                                JSON
                                            </a>
                                        </div>
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

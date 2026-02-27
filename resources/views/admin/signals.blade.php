<x-admin-layout title="Signals">
    <div class="rounded-2xl border border-white/10 bg-white/5 p-5 mb-6">
        <form method="GET" action="{{ route('admin.signals') }}" class="flex flex-col sm:flex-row gap-3 sm:items-end">
            <div class="w-full sm:w-72">
                <label for="position" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Open Position Context</label>
                <select id="position" name="position" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                    <option value="" @selected(($position ?? '') === '')>No open position</option>
                    <option value="long" @selected(($position ?? '') === 'long')>Long / Buy</option>
                    <option value="short" @selected(($position ?? '') === 'short')>Short / Sell</option>
                </select>
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-amber-400 text-slate-950 text-xs font-bold uppercase tracking-[0.14em]">
                Refresh
            </button>
        </form>
    </div>

    <div class="rounded-2xl border border-white/10 bg-white/5 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-[11px] uppercase tracking-[0.12em] text-slate-400">
                    <tr>
                        <th class="px-5 py-3">Strategy</th>
                        <th class="px-5 py-3">Action</th>
                        <th class="px-5 py-3">Price</th>
                        <th class="px-5 py-3">Timestamp</th>
                        <th class="px-5 py-3">Message</th>
                        <th class="px-5 py-3">Links</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @forelse($strategies as $strategy)
                        @php
                            $signal = $signals[$strategy->id] ?? null;
                            $action = strtoupper($signal['action'] ?? 'NO_DATA');
                            $actionClass = match ($action) {
                                'BUY' => 'bg-emerald-500/20 text-emerald-200',
                                'SELL' => 'bg-rose-500/20 text-rose-200',
                                'CLOSE' => 'bg-amber-500/20 text-amber-200',
                                'HOLD' => 'bg-sky-500/20 text-sky-200',
                                default => 'bg-zinc-500/20 text-zinc-200',
                            };
                        @endphp
                        <tr>
                            <td class="px-5 py-3">
                                <p class="font-semibold">{{ $strategy->name }}</p>
                                <p class="text-xs text-slate-400">{{ $strategy->slug }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-1 rounded-lg text-[11px] uppercase font-semibold {{ $actionClass }}">{{ $action }}</span>
                            </td>
                            <td class="px-5 py-3">
                                ${{ number_format((float) ($signal['price'] ?? 0), 4) }}
                            </td>
                            <td class="px-5 py-3 text-slate-300">{{ $signal['timestamp'] ?? 'N/A' }}</td>
                            <td class="px-5 py-3 text-slate-300 max-w-sm">{{ $signal['message'] ?? 'N/A' }}</td>
                            <td class="px-5 py-3">
                                <a href="{{ route('signals.show', $strategy->slug) }}" class="text-xs text-amber-300 hover:text-amber-200 uppercase tracking-[0.12em] font-semibold mr-3">
                                    Open
                                </a>
                                <a href="{{ route('signals.latest', $strategy->slug) }}?position={{ $position }}" class="text-xs text-slate-300 hover:text-white uppercase tracking-[0.12em] font-semibold">
                                    JSON
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-6 text-center text-slate-400">No strategies found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin-layout>


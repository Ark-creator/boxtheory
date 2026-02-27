<x-admin-layout title="Strategies">
    <div class="rounded-2xl border border-white/10 bg-white/5 overflow-hidden">
        <div class="px-5 py-4 border-b border-white/10 flex items-center justify-between">
            <h2 class="text-sm uppercase tracking-[0.18em] text-slate-300 font-semibold">Strategy Catalog</h2>
            <span class="text-xs text-slate-400">{{ $strategies->count() }} total</span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-[11px] uppercase tracking-[0.12em] text-slate-400">
                    <tr>
                        <th class="px-5 py-3">Strategy</th>
                        <th class="px-5 py-3">Slug</th>
                        <th class="px-5 py-3">Price</th>
                        <th class="px-5 py-3">Subscribers</th>
                        <th class="px-5 py-3">Active</th>
                        <th class="px-5 py-3">Pending</th>
                        <th class="px-5 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @forelse($strategies as $strategy)
                        <tr>
                            <td class="px-5 py-3">
                                <p class="font-semibold">{{ $strategy->name }}</p>
                                <p class="text-xs text-slate-400 mt-1">{{ \Illuminate\Support\Str::limit($strategy->description, 70) }}</p>
                            </td>
                            <td class="px-5 py-3 text-slate-300">{{ $strategy->slug }}</td>
                            <td class="px-5 py-3 font-semibold">${{ number_format((float) $strategy->price, 2) }}</td>
                            <td class="px-5 py-3">{{ number_format($strategy->users_count) }}</td>
                            <td class="px-5 py-3 text-emerald-200">{{ number_format($strategy->active_subscribers_count) }}</td>
                            <td class="px-5 py-3 text-amber-200">{{ number_format($strategy->pending_subscribers_count) }}</td>
                            <td class="px-5 py-3">
                                <a href="{{ route('signals.show', $strategy->slug) }}" class="text-xs text-amber-300 hover:text-amber-200 uppercase tracking-[0.12em] font-semibold">
                                    Open Signals
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-6 text-center text-slate-400">No strategies found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin-layout>


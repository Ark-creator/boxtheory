<x-admin-layout title="Dashboard">
    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <p class="text-xs uppercase tracking-[0.15em] text-slate-400">Users</p>
            <p class="text-3xl font-black mt-2">{{ number_format($summary['total_users']) }}</p>
            <p class="text-xs text-slate-500 mt-1">Admins: {{ number_format($summary['total_admins']) }}</p>
        </div>
        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <p class="text-xs uppercase tracking-[0.15em] text-slate-400">Strategies</p>
            <p class="text-3xl font-black mt-2">{{ number_format($summary['total_strategies']) }}</p>
            <p class="text-xs text-slate-500 mt-1">Tradable setups in catalog</p>
        </div>
        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <p class="text-xs uppercase tracking-[0.15em] text-slate-400">Subscribers</p>
            <p class="text-3xl font-black mt-2">{{ number_format($summary['total_subscribers']) }}</p>
            <p class="text-xs text-slate-500 mt-1">Active: {{ number_format($summary['active_subscribers']) }}</p>
        </div>
        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <p class="text-xs uppercase tracking-[0.15em] text-slate-400">Queue</p>
            <p class="text-3xl font-black mt-2">{{ number_format($summary['pending_subscribers']) }}</p>
            <p class="text-xs text-slate-500 mt-1">Expired: {{ number_format($summary['expired_subscribers']) }}</p>
        </div>
    </div>

    <div class="rounded-2xl border border-white/10 bg-white/5 overflow-hidden">
        <div class="px-5 py-4 border-b border-white/10 flex items-center justify-between">
            <h2 class="text-sm uppercase tracking-[0.18em] text-slate-300 font-semibold">Recent Subscriber Activity</h2>
            <a href="{{ route('admin.subscribers') }}" class="text-xs text-amber-300 hover:text-amber-200 uppercase tracking-[0.12em] font-semibold">Open Full List</a>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-[11px] uppercase tracking-[0.12em] text-slate-400">
                    <tr>
                        <th class="px-5 py-3">User</th>
                        <th class="px-5 py-3">Email</th>
                        <th class="px-5 py-3">Strategy</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Subscribed</th>
                        <th class="px-5 py-3">Expires</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @forelse($recentSubscribers as $item)
                        <tr>
                            <td class="px-5 py-3 font-semibold">{{ $item->user_name }}</td>
                            <td class="px-5 py-3 text-slate-300">{{ $item->user_email }}</td>
                            <td class="px-5 py-3">{{ $item->strategy_name }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-1 rounded-lg text-[11px] uppercase font-semibold
                                    @if($item->status === 'active') bg-emerald-500/20 text-emerald-200
                                    @elseif($item->status === 'pending') bg-amber-500/20 text-amber-200
                                    @else bg-rose-500/20 text-rose-200 @endif">
                                    {{ $item->status }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-slate-300">{{ \Illuminate\Support\Carbon::parse($item->created_at)->format('M d, Y H:i') }}</td>
                            <td class="px-5 py-3 text-slate-300">
                                {{ $item->expires_at ? \Illuminate\Support\Carbon::parse($item->expires_at)->format('M d, Y H:i') : 'N/A' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-6 text-center text-slate-400">No subscription activity yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin-layout>


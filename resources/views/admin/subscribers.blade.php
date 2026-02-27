<x-admin-layout title="Subscribers">
    <div class="rounded-2xl border border-white/10 bg-white/5 p-5 mb-6">
        <form method="GET" action="{{ route('admin.subscribers') }}" class="flex flex-col sm:flex-row gap-3 sm:items-end">
            <div class="w-full sm:w-72">
                <label for="status" class="block text-xs uppercase tracking-[0.12em] text-slate-400 mb-2">Status Filter</label>
                <select id="status" name="status" class="w-full bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                    <option value="" @selected(($status ?? '') === '')>All</option>
                    <option value="pending" @selected(($status ?? '') === 'pending')>Pending</option>
                    <option value="active" @selected(($status ?? '') === 'active')>Active</option>
                    <option value="rejected" @selected(($status ?? '') === 'rejected')>Rejected</option>
                </select>
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-amber-400 text-slate-950 text-xs font-bold uppercase tracking-[0.14em]">
                Apply
            </button>
        </form>
    </div>

    <div class="rounded-2xl border border-white/10 bg-white/5 overflow-hidden">
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
                        <th class="px-5 py-3">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @forelse($subscribers as $item)
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
                            <td class="px-5 py-3">
                                @if($item->status === 'pending')
                                    <div class="flex items-center gap-2">
                                        <form method="POST" action="{{ route('admin.approve', $item->id) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-500/90 text-white text-[11px] uppercase tracking-[0.1em] font-semibold">Approve</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.reject', $item->id) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-rose-500/90 text-white text-[11px] uppercase tracking-[0.1em] font-semibold">Reject</button>
                                        </form>
                                    </div>
                                @else
                                    <span class="text-xs text-slate-500">No action</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-6 text-center text-slate-400">No subscribers found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">
        {{ $subscribers->links() }}
    </div>
</x-admin-layout>


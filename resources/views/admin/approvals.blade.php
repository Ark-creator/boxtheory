<x-admin-layout title="Approvals">
    <div class="rounded-2xl border border-white/10 bg-white/5 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-[11px] uppercase tracking-[0.12em] text-slate-400">
                    <tr>
                        <th class="px-5 py-3">User</th>
                        <th class="px-5 py-3">Email</th>
                        <th class="px-5 py-3">Strategy</th>
                        <th class="px-5 py-3">Receipt</th>
                        <th class="px-5 py-3">Submitted</th>
                        <th class="px-5 py-3">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @forelse($pending as $item)
                        <tr>
                            <td class="px-5 py-3 font-semibold">{{ $item->user_name }}</td>
                            <td class="px-5 py-3 text-slate-300">{{ $item->user_email }}</td>
                            <td class="px-5 py-3">{{ $item->strategy_name }}</td>
                            <td class="px-5 py-3">
                                <a target="_blank" href="{{ route('admin.receipt.view', basename($item->receipt_path)) }}" class="text-xs text-blue-300 hover:text-blue-200 uppercase tracking-[0.12em] font-semibold">
                                    View Receipt
                                </a>
                            </td>
                            <td class="px-5 py-3 text-slate-300">{{ \Illuminate\Support\Carbon::parse($item->created_at)->format('M d, Y H:i') }}</td>
                            <td class="px-5 py-3">
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
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-6 text-center text-slate-400">No pending subscriptions.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">
        {{ $pending->links() }}
    </div>
</x-admin-layout>

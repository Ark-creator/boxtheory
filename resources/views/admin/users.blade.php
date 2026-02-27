<x-admin-layout title="Users">
    <div class="rounded-2xl border border-white/10 bg-white/5 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-[11px] uppercase tracking-[0.12em] text-slate-400">
                    <tr>
                        <th class="px-5 py-3">Name</th>
                        <th class="px-5 py-3">Email</th>
                        <th class="px-5 py-3">Role</th>
                        <th class="px-5 py-3">Approved</th>
                        <th class="px-5 py-3">Strategies</th>
                        <th class="px-5 py-3">Joined</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @forelse($users as $user)
                        <tr>
                            <td class="px-5 py-3 font-semibold">{{ $user->name }}</td>
                            <td class="px-5 py-3 text-slate-300">{{ $user->email }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-1 rounded-lg text-[11px] uppercase font-semibold {{ $user->role === 'admin' ? 'bg-amber-500/20 text-amber-200' : 'bg-slate-500/20 text-slate-200' }}">
                                    {{ $user->role }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-1 rounded-lg text-[11px] uppercase font-semibold {{ $user->is_approved ? 'bg-emerald-500/20 text-emerald-200' : 'bg-zinc-500/20 text-zinc-200' }}">
                                    {{ $user->is_approved ? 'yes' : 'no' }}
                                </span>
                            </td>
                            <td class="px-5 py-3">{{ number_format($user->strategies_count) }}</td>
                            <td class="px-5 py-3 text-slate-300">{{ $user->created_at->format('M d, Y') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-6 text-center text-slate-400">No users found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">
        {{ $users->links() }}
    </div>
</x-admin-layout>


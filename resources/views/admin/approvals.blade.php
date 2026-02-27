<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel | GoldLogic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0B1120; color: white; }
        .gold-text { background: linear-gradient(135deg, #BF953F, #FCF6BA, #AA771C); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .glass { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.1); }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body x-data="{ showModal: false, imgSource: '' }">
    <div class="max-w-7xl mx-auto px-6 py-12">
        <header class="flex justify-between items-center mb-12">
            <h1 class="text-4xl font-black italic gold-text uppercase">Pending Approvals</h1>
            <a href="/" class="text-xs font-bold text-gray-500 hover:text-white uppercase tracking-widest">Back to Site</a>
        </header>

        <div class="glass rounded-[2.5rem] overflow-hidden">
            <table class="w-full text-left">
                <thead class="bg-white/5 text-[10px] uppercase tracking-widest font-black text-gray-500">
                    <tr>
                        <th class="px-8 py-5">User</th>
                        <th class="px-8 py-5">Strategy</th>
                        <th class="px-8 py-5">Proof of Payment</th>
                        <th class="px-8 py-5">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @forelse($pending as $item)
                    <tr class="hover:bg-white/5 transition">
                        <td class="px-8 py-6 font-bold">{{ $item->user_name }}</td>
                        <td class="px-8 py-6 text-amber-500 font-mono">{{ $item->strategy_name }}</td>
                        <td class="px-8 py-6">
                            <button @click="imgSource = '{{ route('admin.receipt.view', basename($item->receipt_path)) }}'; showModal = true" 
                                    class="bg-blue-500/10 text-blue-400 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest border border-blue-500/20 hover:bg-blue-500 hover:text-white transition">
                                View Photo
                            </button>
                        </td>
                        <td class="px-8 py-6">
                            <form action="{{ route('admin.approve', $item->id) }}" method="POST">
                                @csrf
                                <button type="submit" class="bg-green-500 text-black px-6 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest hover:scale-105 transition transform shadow-lg shadow-green-500/20">
                                    Approve Access
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-8 py-20 text-center text-gray-500 italic uppercase tracking-widest text-xs">No pending subscriptions.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/95 backdrop-blur-md p-6">
        <div class="glass max-w-2xl w-full p-4 rounded-[2rem] relative" @click.away="showModal = false">
            <button @click="showModal = false" class="absolute -top-12 right-0 text-white text-4xl">&times;</button>
            <img :src="imgSource" class="w-full h-auto rounded-2xl border border-white/10 shadow-2xl">
            <div class="mt-4 text-center">
                <p class="text-xs text-gray-400 font-bold uppercase tracking-widest">Verify bank reference before approving</p>
            </div>
        </div>
    </div>
</body>
</html>
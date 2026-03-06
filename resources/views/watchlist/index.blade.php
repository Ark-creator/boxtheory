<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="90">
    <title>My Watchlist | GoldLogic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0B1120; }
        .gold-gradient { background: linear-gradient(135deg, #BF953F, #FCF6BA, #B38728, #FBF5B7, #AA771C); }
        .gold-text { background: linear-gradient(135deg, #BF953F, #FCF6BA, #AA771C); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .glass { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.1); }
    </style>
</head>
<body class="text-white min-h-screen">
    <div class="fixed inset-0 z-0 pointer-events-none overflow-hidden">
        <div class="absolute top-0 -left-20 w-96 h-96 bg-amber-500/5 rounded-full blur-[120px]"></div>
        <div class="absolute bottom-0 -right-20 w-96 h-96 bg-blue-500/5 rounded-full blur-[120px]"></div>
    </div>

    <nav class="relative z-10 border-b border-white/10 py-6">
        <div class="max-w-7xl mx-auto px-6 flex justify-between items-center">
            <a href="/" class="flex items-center gap-2">
                <div class="w-8 h-8 gold-gradient rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-[#0B1120]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                </div>
                <span class="text-lg font-extrabold tracking-tighter uppercase">GOLD<span class="text-amber-400">LOGIC</span></span>
            </a>
            <div class="flex items-center gap-2 sm:gap-4">
                <a href="{{ route('strategies.index') }}" class="px-3 py-2 rounded-lg border border-white/15 text-[10px] sm:text-xs font-black uppercase tracking-[0.12em] text-slate-300 hover:bg-white/5">
                    Strategies
                </a>
                <a href="{{ route('watchlist.index') }}" class="px-3 py-2 rounded-lg border border-amber-400/60 bg-amber-400/10 text-[10px] sm:text-xs font-black uppercase tracking-[0.12em] text-amber-200">
                    Watchlist
                </a>
                <span class="text-[10px] sm:text-xs text-gray-400 font-bold uppercase tracking-widest hidden sm:inline">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-[10px] sm:text-xs text-gray-500 hover:text-white transition uppercase font-bold tracking-widest">Logout</button>
                </form>
            </div>
        </div>
    </nav>

    <main class="relative z-10 py-12">
        <div class="max-w-7xl mx-auto px-6">
            <header class="mb-8">
                <h1 class="text-4xl md:text-5xl font-black italic mb-3 gold-text">MY SIGNAL WATCHLIST</h1>
                <p class="text-gray-400 max-w-3xl">
                    Save your favorite market pairs and monitor all accessible strategy signals in one compact board.
                    This page auto-refreshes every 90 seconds.
                </p>
            </header>

            @if(session('success'))
                <div class="mb-6 p-4 bg-green-500/10 border border-green-500/20 text-green-400 rounded-2xl text-sm font-bold">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="mb-6 p-4 bg-rose-500/10 border border-rose-500/20 text-rose-300 rounded-2xl text-sm font-bold">
                    {{ session('error') }}
                </div>
            @endif

            <section class="glass rounded-3xl p-6 mb-6" x-data="{ selected: {{ json_encode($selectedPairCodes) }} }">
                <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-4">
                    <div>
                        <p class="text-[10px] font-black text-amber-500 uppercase tracking-[0.2em]">Configuration</p>
                        <h2 class="text-2xl font-extrabold mt-2">Favorite Pairs</h2>
                        <p class="text-sm text-slate-400 mt-1">Pick up to {{ $maxPairs }} pairs for your personal board.</p>
                    </div>
                    <form method="GET" action="{{ route('watchlist.index') }}" class="flex items-end gap-2">
                        <div>
                            <label for="position" class="block text-[10px] uppercase tracking-[0.12em] text-slate-400 mb-2">Open Position Context</label>
                            <select id="position" name="position" class="bg-slate-900 border border-white/15 rounded-xl px-3 py-2 text-sm">
                                <option value="" @selected(($position ?? '') === '')>No open position</option>
                                <option value="long" @selected(($position ?? '') === 'long')>Long / Buy</option>
                                <option value="short" @selected(($position ?? '') === 'short')>Short / Sell</option>
                            </select>
                        </div>
                        <button type="submit" class="px-4 py-2 rounded-xl bg-amber-400 text-slate-950 text-xs font-black uppercase tracking-[0.14em]">
                            Refresh
                        </button>
                    </form>
                </div>

                <form method="POST" action="{{ route('watchlist.update') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="position" value="{{ $position }}">

                    <div class="flex flex-wrap gap-2">
                        @foreach($pairs as $code => $pairItem)
                            @php $isChecked = in_array($code, $selectedPairCodes, true); @endphp
                            <label class="inline-flex items-center">
                                <input
                                    type="checkbox"
                                    name="pairs[]"
                                    value="{{ $code }}"
                                    class="sr-only"
                                    @checked($isChecked)
                                    x-model="selected"
                                >
                                <span class="px-3 py-2 rounded-full text-[10px] uppercase tracking-[0.12em] font-bold border transition"
                                      :class="selected.includes('{{ $code }}')
                                        ? 'border-amber-400 bg-amber-400/15 text-amber-200'
                                        : 'border-white/15 text-slate-300 hover:border-white/30'">
                                    {{ $pairItem['display'] }}
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mt-4">
                        <p class="text-xs text-slate-400">
                            Selected: <span class="text-amber-200 font-semibold" x-text="selected.length"></span> / {{ $maxPairs }} (extra selections are trimmed on save)
                        </p>
                        <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-500 text-white text-xs font-black uppercase tracking-[0.14em]">
                            Save Watchlist
                        </button>
                    </div>
                </form>
            </section>

            @if($strategies->isEmpty())
                <section class="glass rounded-3xl p-8 text-center">
                    <p class="text-lg font-bold text-slate-200">No active strategy access yet.</p>
                    <p class="text-sm text-slate-400 mt-2">Subscribe to a strategy first, then your watchlist board will populate automatically.</p>
                    <a href="{{ route('strategies.index') }}" class="inline-flex mt-5 px-5 py-3 rounded-xl border border-white/15 text-xs uppercase tracking-[0.14em] font-black text-slate-200 hover:bg-white/5">
                        Go To Strategies
                    </a>
                </section>
            @else
                <div class="space-y-5">
                    @foreach($selectedPairCodes as $pairCode)
                        @php
                            $pairItem = $pairs[$pairCode] ?? ['display' => $pairCode, 'name' => $pairCode];
                        @endphp
                        <section class="glass rounded-3xl p-6">
                            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
                                <div>
                                    <p class="text-[10px] uppercase tracking-[0.16em] text-amber-400 font-black">Watch Pair</p>
                                    <h3 class="text-2xl font-black">{{ $pairItem['display'] }}</h3>
                                    <p class="text-xs text-slate-400 mt-1">{{ $pairItem['name'] }}</p>
                                </div>
                            </div>

                            <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
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
                                    <article class="rounded-2xl bg-slate-950/55 border border-white/10 p-4">
                                        <div class="flex items-center justify-between gap-3 mb-3">
                                            <p class="text-sm font-black text-slate-100">{{ $strategy->name }}</p>
                                            <span class="px-2 py-1 rounded-lg border text-[10px] uppercase tracking-[0.1em] font-bold {{ $actionClass }}">{{ $action }}</span>
                                        </div>

                                        <p class="text-2xl font-black text-slate-100">${{ number_format((float) ($signal['price'] ?? 0), 4) }}</p>
                                        <p class="text-[11px] text-slate-500 mt-1">{{ $signal['timestamp'] ?? 'N/A' }}</p>

                                        <p class="text-xs text-slate-300 mt-3 leading-relaxed">
                                            {{ \Illuminate\Support\Str::limit((string) ($signal['message'] ?? 'No message available.'), 110) }}
                                        </p>

                                        <div class="mt-4 pt-3 border-t border-white/10 flex items-center gap-3">
                                            <a href="{{ route('signals.show', ['strategy' => $strategy->slug, 'pair' => $pairCode, 'position' => ($position ?? null)]) }}" class="text-[10px] text-amber-300 hover:text-amber-200 uppercase tracking-[0.12em] font-black">
                                                Open
                                            </a>
                                            <a href="{{ route('signals.latest', ['strategy' => $strategy->slug, 'pair' => $pairCode, 'position' => ($position ?? null)]) }}" class="text-[10px] text-slate-300 hover:text-white uppercase tracking-[0.12em] font-black">
                                                JSON
                                            </a>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            @endif
        </div>
    </main>
</body>
</html>

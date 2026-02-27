@props(['title' => 'Admin Panel'])

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} | GoldLogic Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: radial-gradient(circle at top right, #2B1A08 0%, #090E1D 45%, #060B16 100%); }
    </style>
</head>
<body class="text-slate-100 min-h-screen">
    <div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">
        <aside class="border-b lg:border-b-0 lg:border-r border-white/10 bg-slate-950/70 backdrop-blur-xl">
            <div class="p-6 lg:p-8">
                <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-xl bg-amber-400 text-slate-950 font-black flex items-center justify-center">GL</div>
                    <div>
                        <p class="text-xs uppercase tracking-[0.18em] text-amber-300 font-semibold">GoldLogic</p>
                        <p class="font-black text-lg">Admin Panel</p>
                    </div>
                </a>
            </div>

            <nav class="px-4 pb-8">
                <a href="{{ route('admin.dashboard') }}" class="block px-4 py-3 rounded-xl text-sm font-semibold mb-2 {{ request()->routeIs('admin.dashboard') ? 'bg-amber-400 text-slate-950' : 'text-slate-300 hover:bg-white/5' }}">
                    Dashboard
                </a>
                <a href="{{ route('admin.strategies') }}" class="block px-4 py-3 rounded-xl text-sm font-semibold mb-2 {{ request()->routeIs('admin.strategies') ? 'bg-amber-400 text-slate-950' : 'text-slate-300 hover:bg-white/5' }}">
                    Strategies
                </a>
                <a href="{{ route('admin.signals') }}" class="block px-4 py-3 rounded-xl text-sm font-semibold mb-2 {{ request()->routeIs('admin.signals') ? 'bg-amber-400 text-slate-950' : 'text-slate-300 hover:bg-white/5' }}">
                    Signals
                </a>
                <a href="{{ route('admin.approvals') }}" class="block px-4 py-3 rounded-xl text-sm font-semibold mb-2 {{ request()->routeIs('admin.approvals') ? 'bg-amber-400 text-slate-950' : 'text-slate-300 hover:bg-white/5' }}">
                    Approvals
                </a>
                <a href="{{ route('admin.subscribers') }}" class="block px-4 py-3 rounded-xl text-sm font-semibold mb-2 {{ request()->routeIs('admin.subscribers') ? 'bg-amber-400 text-slate-950' : 'text-slate-300 hover:bg-white/5' }}">
                    Subscribers
                </a>
                <a href="{{ route('admin.users') }}" class="block px-4 py-3 rounded-xl text-sm font-semibold {{ request()->routeIs('admin.users') ? 'bg-amber-400 text-slate-950' : 'text-slate-300 hover:bg-white/5' }}">
                    Users
                </a>
            </nav>
        </aside>

        <div class="flex flex-col min-h-screen">
            <header class="px-5 lg:px-8 py-5 border-b border-white/10 bg-slate-950/40 backdrop-blur-xl">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-xs uppercase tracking-[0.2em] text-slate-400 font-semibold">Admin Session</p>
                        <h1 class="text-xl lg:text-2xl font-black">{{ $title }}</h1>
                    </div>

                    <div class="flex items-center gap-2 lg:gap-3">
                        <a href="{{ route('strategies.index') }}" class="px-3 py-2 rounded-lg border border-white/15 text-xs font-semibold uppercase tracking-[0.12em] hover:bg-white/5">
                            User View
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="px-3 py-2 rounded-lg bg-rose-500/80 text-white text-xs font-semibold uppercase tracking-[0.12em] hover:bg-rose-500">
                                Logout
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <main class="px-5 lg:px-8 py-6 lg:py-8">
                @if(session('success'))
                    <div class="mb-6 rounded-xl border border-emerald-300/30 bg-emerald-500/10 px-4 py-3 text-emerald-200 text-sm">
                        {{ session('success') }}
                    </div>
                @endif

                @if(session('error'))
                    <div class="mb-6 rounded-xl border border-rose-300/30 bg-rose-500/10 px-4 py-3 text-rose-200 text-sm">
                        {{ session('error') }}
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>


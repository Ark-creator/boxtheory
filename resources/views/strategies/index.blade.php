<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Strategy | GoldLogic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0B1120; }
        .gold-gradient { background: linear-gradient(135deg, #BF953F, #FCF6BA, #B38728, #FBF5B7, #AA771C); }
        .gold-text { background: linear-gradient(135deg, #BF953F, #FCF6BA, #AA771C); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .glass { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.1); }
        [x-cloak] { display: none !important; }
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
            <div class="flex items-center gap-4">
                <span class="text-xs text-gray-400 font-bold uppercase tracking-widest">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-xs text-gray-500 hover:text-white transition uppercase font-bold tracking-widest">Logout</button>
                </form>
            </div>
        </div>
    </nav>

    <main class="relative z-10 py-16" x-data="{ showUpload: false, selectedStrategy: null, selectedName: '' }">
        <div class="max-w-7xl mx-auto px-6">
            
            <header class="mb-12">
                <h2 class="text-4xl md:text-5xl font-black italic mb-4 gold-text">SELECT YOUR STRATEGY</h2>
                <p class="text-gray-400 max-w-2xl">Unlock precision signals. Choose a strategy below and upload your proof of payment for immediate administrative review.</p>
            </header>

            @if(session('success'))
                <div class="mb-8 p-4 bg-green-500/10 border border-green-500/20 text-green-400 rounded-2xl text-sm font-bold">
                    {{ session('success') }}
                </div>
            @endif

            <div class="grid md:grid-cols-3 gap-8">
                @foreach($strategies as $strategy)
                <div class="glass p-8 rounded-[2.5rem] flex flex-col hover:border-amber-500/30 transition-all duration-300">
                    <h3 class="text-2xl font-black mb-2 uppercase">{{ $strategy->name }}</h3>
                    <div class="text-[10px] font-black text-amber-500 uppercase tracking-[0.2em] mb-6">XAU/USD Market</div>
                    
                    <p class="text-gray-400 text-sm mb-8 flex-grow leading-relaxed">
                        {{ $strategy->description }}
                    </p>
                    
                    <div class="mb-8 flex items-end gap-1">
                        <span class="text-4xl font-black">${{ number_format($strategy->price, 0) }}</span>
                        <span class="text-gray-600 text-[10px] font-bold uppercase pb-1 tracking-widest">Per 30 days</span>
                    </div>

                    @php
                        $sub = auth()->user()->strategies->where('id', $strategy->id)->first();
                    @endphp

                    @if(auth()->user()->isAdmin())
                        <a href="{{ route('signals.show', $strategy->slug) }}" 
                           class="w-full border-2 border-green-500/50 text-green-400 text-center font-black py-4 rounded-2xl uppercase text-xs tracking-[0.2em] hover:bg-green-500 hover:text-white transition">
                            View Live Signals
                        </a>
                    @elseif(!$sub)
                        <button @click="selectedStrategy = {{ $strategy->id }}; selectedName = '{{ $strategy->name }}'; showUpload = true" 
                                class="w-full gold-gradient text-black font-black py-4 rounded-2xl uppercase text-xs tracking-[0.2em] shadow-lg shadow-amber-500/10 hover:scale-[1.02] transition transform">
                            Unlock Strategy
                        </button>
                    @elseif($sub->pivot->status === 'pending')
                        <button disabled class="w-full bg-white/5 border border-white/10 text-gray-500 font-black py-4 rounded-2xl uppercase text-xs tracking-[0.2em]">
                            Verification Pending
                        </button>
                    @else
                        <a href="{{ route('signals.show', $strategy->slug) }}" 
                           class="w-full border-2 border-green-500/50 text-green-400 text-center font-black py-4 rounded-2xl uppercase text-xs tracking-[0.2em] hover:bg-green-500 hover:text-white transition">
                            View Live Signals
                        </a>
                    @endif
                </div>
                @endforeach
            </div>
        </div>

       <div x-show="showUpload" 
     x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center bg-black/90 backdrop-blur-md p-4">
    
    <div class="glass max-w-md w-full p-10 rounded-[3rem] relative shadow-2xl" @click.away="showUpload = false">
        <button @click="showUpload = false" class="absolute top-6 right-8 text-gray-500 hover:text-white text-2xl">&times;</button>
        
        <h3 class="text-2xl font-black mb-2 italic uppercase gold-text">Payment Details</h3>
        <p class="text-gray-400 text-[10px] mb-6 uppercase tracking-widest font-bold">Transfer the amount for <span class="text-white" x-text="selectedName"></span></p>
        
        <div class="bg-white/5 border border-amber-500/20 rounded-2xl p-6 mb-8">
            <div class="mb-4">
                <p class="text-[10px] text-gray-500 uppercase font-black tracking-widest">Bank Name</p>
                <p class="text-lg font-bold">YOUR BANK NAME HERE</p>
            </div>
            <div class="mb-4">
                <p class="text-[10px] text-gray-500 uppercase font-black tracking-widest">Account Number</p>
                <p class="text-xl font-black tracking-tighter text-amber-400">1234 5678 9012</p>
            </div>
            <div>
                <p class="text-[10px] text-gray-500 uppercase font-black tracking-widest">Account Name</p>
                <p class="text-lg font-bold">YOUR FULL NAME</p>
            </div>
        </div>

        <p class="text-gray-400 text-[10px] mb-4 uppercase tracking-widest font-bold text-center">After paying, upload your receipt below:</p>

        <form action="{{ route('subscriptions.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="strategy_id" :value="selectedStrategy">
            
            <div class="mb-8" x-data="{ preview: null }">
                <label class="flex flex-col items-center justify-center w-full h-40 border-2 border-dashed border-white/10 rounded-[2rem] cursor-pointer hover:bg-white/5 relative overflow-hidden transition-all group">
                    <template x-if="!preview">
                        <div class="text-center">
                            <svg class="mx-auto h-8 w-8 text-gray-600 group-hover:text-amber-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest mt-2">Upload Receipt</p>
                        </div>
                    </template>
                    <template x-if="preview">
                        <img :src="preview" class="absolute inset-0 w-full h-full object-cover">
                    </template>
                    <input type="file" name="receipt" class="hidden" required @change="const file = $event.target.files[0]; if (file) { preview = URL.createObjectURL(file) }">
                </label>
            </div>

            <button type="submit" class="w-full gold-gradient text-black font-black py-4 rounded-2xl uppercase text-xs tracking-[0.2em] shadow-xl shadow-amber-500/20">
                Submit for Verification
            </button>
        </form>
    </div>
</div>    </main>

    <footer class="relative z-10 py-12 border-t border-white/5 text-center">
        <p class="text-[10px] text-gray-600 font-black uppercase tracking-[0.4em]">&copy; 2026 GoldLogic. Trade with Precision.</p>
    </footer>

</body>
</html>

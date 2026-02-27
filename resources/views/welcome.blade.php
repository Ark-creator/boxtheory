<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoldLogic | Institutional XAU/USD Trading Signals</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0B1120; }
        .gold-gradient { background: linear-gradient(135deg, #BF953F, #FCF6BA, #B38728, #FBF5B7, #AA771C); }
        .gold-text { background: linear-gradient(135deg, #BF953F, #FCF6BA, #AA771C); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .glass { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.1); }
        [x-cloak] { display: none !important; }
        html, body { max-width: 100%; overflow-x: hidden; }
    </style>
</head>
<body class="text-white" x-data="{ scrolled: false, activeTab: 'box' }" @scroll.window="scrolled = (window.pageYOffset > 50)">

    <nav class="fixed w-full z-50 transition-all duration-300" :class="scrolled ? 'bg-[#0B1120]/90 border-b border-white/10 py-4' : 'bg-transparent py-7'">
        <div class="max-w-7xl mx-auto px-6 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <div class="w-10 h-10 gold-gradient rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-[#0B1120]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                </div>
                <span class="text-xl font-extrabold tracking-tighter uppercase">GOLD<span class="text-amber-400">LOGIC</span></span>
            </div>
            <div class="hidden md:flex items-center gap-8 text-xs font-bold uppercase tracking-widest text-gray-400">
                <a href="#how-it-works" class="hover:text-amber-400 transition">How it Works</a>
                <a href="#strategies" class="hover:text-amber-400 transition">Strategies</a>
                @auth
                    <a href="{{ url('/dashboard') }}" class="gold-gradient text-black px-6 py-2 rounded-full">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="hover:text-white transition">Sign In</a>
                    <a href="{{ route('register') }}" class="border border-amber-500/50 text-amber-500 px-6 py-2 rounded-full hover:bg-amber-500 hover:text-black transition">Join Now</a>
                @endauth
            </div>
        </div>
    </nav>

    <section class="relative min-h-screen flex items-center pt-20 overflow-hidden">
        <div class="absolute inset-0 z-0 pointer-events-none">
            <div class="absolute top-1/4 -left-20 w-96 h-96 bg-amber-500/10 rounded-full blur-[120px]"></div>
            <div class="absolute bottom-1/4 -right-20 w-96 h-96 bg-blue-500/10 rounded-full blur-[120px]"></div>
        </div>

        <div class="max-w-7xl mx-auto px-6 grid lg:grid-cols-2 gap-16 items-center relative z-10">
            <div>
                <span class="inline-block px-4 py-1 rounded-full border border-amber-500/30 bg-amber-500/5 text-amber-500 text-[10px] font-bold uppercase tracking-[0.2em] mb-6">
                    Institutional Grade XAU/USD Signals
                </span>
                <h1 class="text-6xl lg:text-8xl font-black mb-8 leading-[0.9] tracking-tighter">
                    TRADE GOLD <br> <span class="gold-text">WITH LOGIC.</span>
                </h1>
                <p class="text-gray-400 text-lg mb-10 max-w-lg leading-relaxed">
                    Based on "The Box Theory". We identify the strongest sell-side and buy-side forces to give you signals that actually work. No diddling in the middle.
                </p>
                <div class="flex flex-wrap gap-4">
                    <a href="#strategies" class="gold-gradient text-black px-10 py-5 rounded-2xl font-black uppercase tracking-wider hover:scale-105 transition">Start Trading</a>
                    <a href="#how-it-works" class="glass px-10 py-5 rounded-2xl font-bold hover:bg-white/10 transition">The Strategy</a>
                </div>
            </div>
            <div class="relative hidden lg:block">
                <div class="glass p-2 rounded-[2.5rem] rotate-3 hover:rotate-0 transition-all duration-700">
                    <div class="bg-[#0f172a] rounded-[2rem] p-8 border border-white/5">
                        <div class="flex justify-between items-center mb-8">
                            <div>
                                <h4 class="text-gray-500 text-xs font-bold uppercase">XAU/USD Analysis</h4>
                                <p class="text-2xl font-black">$2,042.50</p>
                            </div>
                            <span class="bg-green-500/20 text-green-400 px-3 py-1 rounded-full text-xs font-bold">+1.24%</span>
                        </div>
                        <div class="h-48 flex items-end gap-2">
                            <div class="flex-1 bg-amber-500/20 rounded-t h-[40%]"></div>
                            <div class="flex-1 bg-amber-500/40 rounded-t h-[60%]"></div>
                            <div class="flex-1 bg-amber-500/20 rounded-t h-[30%]"></div>
                            <div class="flex-1 bg-amber-500/60 rounded-t h-[80%]"></div>
                            <div class="flex-1 bg-amber-400 rounded-t h-[95%] shadow-[0_0_20px_rgba(251,191,36,0.4)]"></div>
                        </div>
                    </div>
                </div>
                <div class="absolute -bottom-10 -left-10 glass p-6 rounded-3xl shadow-2xl animate-bounce">
                    <p class="text-[10px] text-gray-500 font-black uppercase tracking-widest mb-1">Current Action</p>
                    <p class="text-2xl font-black text-green-400 italic">STRONG BUY</p>
                </div>
            </div>
        </div>
    </section>

    <section id="how-it-works" class="py-32 bg-[#080d1a] border-y border-white/5">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center max-w-3xl mx-auto mb-20">
                <h2 class="text-4xl md:text-5xl font-black mb-6">The Box Theory <br> <span class="text-gray-500">Stupid Simple Effectiveness.</span></h2>
                <p class="text-gray-400">We connect the previous day's high and low to create a "Box". This identifies where the most powerful buyers and sellers are waiting.</p>
            </div>

            <div class="grid md:grid-cols-3 gap-12 text-center">
                <div class="p-8">
                    <div class="w-16 h-16 rounded-2xl glass flex items-center justify-center mx-auto mb-8 text-amber-500">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 11l7-7 7 7M5 19l7-7 7 7"></path></svg>
                    </div>
                    <h3 class="text-xl font-bold mb-4 uppercase italic">Top of the Box</h3>
                    <p class="text-gray-400 text-sm leading-relaxed">Represents the strongest sell-side force. When price reaches here, we look to sell with the big players.</p>
                </div>
                <div class="p-8 border-x border-white/5">
                    <div class="w-16 h-16 rounded-2xl glass flex items-center justify-center mx-auto mb-8 text-amber-500 font-black text-xl italic">
                        VS
                    </div>
                    <h3 class="text-xl font-bold mb-4 uppercase italic">The Middle</h3>
                    <p class="text-gray-400 text-sm leading-relaxed">"Do not diddle in the middle." This is just noise and confusion. We stay out until the price hits an edge.</p>
                </div>
                <div class="p-8">
                    <div class="w-16 h-16 rounded-2xl glass flex items-center justify-center mx-auto mb-8 text-green-500">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 13l-7 7-7-7m14-8l-7 7-7-7"></path></svg>
                    </div>
                    <h3 class="text-xl font-bold mb-4 uppercase italic">Bottom of the Box</h3>
                    <p class="text-gray-400 text-sm leading-relaxed">Represents the strongest buy-side force. This is the "Lion's Den" where we join the well-funded buyers.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="strategies" class="py-32">
        <div class="max-w-7xl mx-auto px-6">
            <div class="flex flex-col md:flex-row justify-between items-end mb-16 gap-6">
                <div class="max-w-xl">
                    <h2 class="text-5xl font-black mb-4 italic">UNBEATABLE <br> STRATEGIES.</h2>
                    <p class="text-gray-400 italic">Select the algorithm that fits your trading capital. Approval is required for access.</p>
                </div>
                <div class="flex glass p-1 rounded-xl">
                    <button class="px-6 py-2 rounded-lg text-xs font-bold uppercase tracking-widest transition" :class="activeTab === 'box' ? 'gold-gradient text-black' : 'text-gray-400'" @click="activeTab = 'box'">Box Theory</button>
                    <button class="px-6 py-2 rounded-lg text-xs font-bold uppercase tracking-widest transition" :class="activeTab === 'rsi' ? 'gold-gradient text-black' : 'text-gray-400'" @click="activeTab = 'rsi'">RSI Scalper</button>
                </div>
            </div>

            <div class="grid md:grid-cols-3 gap-8">
                @foreach(\App\Models\Strategy::all() as $strategy)
                <div class="glass p-1 rounded-[2.5rem] group hover:border-amber-500/50 transition-all duration-500">
                    <div class="bg-[#0b1120] rounded-[2.2rem] p-10 h-full flex flex-col">
                        <h3 class="text-2xl font-black mb-2 uppercase">{{ $strategy->name }}</h3>
                        <p class="text-gray-500 text-xs mb-8 uppercase tracking-widest font-bold">XAU/USD Exclusive</p>
                        
                        <p class="text-gray-400 text-sm mb-10 flex-grow">
                            {{ $strategy->description }}
                        </p>

                        <div class="flex items-center justify-between mt-auto pt-8 border-t border-white/5">
                            <div>
                                <p class="text-[10px] text-gray-500 uppercase font-black">Lifetime Access</p>
                                <p class="text-3xl font-black">${{ number_format($strategy->price, 0) }}</p>
                            </div>
                            <a href="{{ route('register', ['strategy' => $strategy->id]) }}" class="w-14 h-14 rounded-full border border-white/10 flex items-center justify-center hover:gold-gradient hover:text-black transition-all">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                            </a>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="py-32 bg-amber-500/5 overflow-hidden relative">
        <div class="max-w-7xl mx-auto px-6 relative z-10">
            <h2 class="text-center text-4xl font-black mb-20 italic">TRADER FEEDBACK</h2>
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="glass p-8 rounded-3xl italic text-gray-300">
                    "Nothing but simple, stupid, and effective. The Box Theory changed my 2024 results instantly."
                    <div class="mt-6 flex items-center gap-3">
                        <div class="w-8 h-1 bg-amber-500"></div>
                        <span class="text-xs font-bold uppercase text-white">Verified Student</span>
                    </div>
                </div>
                <div class="glass p-8 rounded-3xl italic text-gray-300">
                    "I was a monkey on a bicycle until I started buying at the bottom and selling at the top. Pure probability."
                    <div class="mt-6 flex items-center gap-3">
                        <div class="w-8 h-1 bg-amber-500"></div>
                        <span class="text-xs font-bold uppercase text-white">Full-time Trader</span>
                    </div>
                </div>
                <div class="glass p-8 rounded-3xl italic text-gray-300">
                    "The live alerts are a game changer. I don't have to watch the charts 24/7 anymore."
                    <div class="mt-6 flex items-center gap-3">
                        <div class="w-8 h-1 bg-amber-500"></div>
                        <span class="text-xs font-bold uppercase text-white">Pro Subscriber</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-32 max-w-4xl mx-auto px-6">
        <h2 class="text-3xl font-black mb-12 text-center">COMMON QUESTIONS</h2>
        <div class="space-y-4" x-data="{ open: null }">
            <div class="glass rounded-2xl overflow-hidden">
                <button @click="open = (open === 1 ? null : 1)" class="w-full p-6 text-left flex justify-between items-center">
                    <span class="font-bold">How long does approval take?</span>
                    <span x-text="open === 1 ? '-' : '+'"></span>
                </button>
                <div x-show="open === 1" x-cloak class="p-6 pt-0 text-sm text-gray-400">
                    Once you upload your receipt, the admin manually verifies it. This usually takes 1-6 hours.
                </div>
            </div>
            <div class="glass rounded-2xl overflow-hidden">
                <button @click="open = (open === 2 ? null : 2)" class="w-full p-6 text-left flex justify-between items-center">
                    <span class="font-bold">Does it work on mobile?</span>
                    <span x-text="open === 2 ? '-' : '+'"></span>
                </button>
                <div x-show="open === 2" x-cloak class="p-6 pt-0 text-sm text-gray-400">
                    Yes! Our signal dashboard is built with Alpine.js and Tailwind, making it perfectly responsive for MT4/MT5 mobile trading.
                </div>
            </div>
        </div>
    </section>

    <footer class="py-20 border-t border-white/5 bg-[#080d1a]">
        <div class="max-w-7xl mx-auto px-6 grid md:grid-cols-4 gap-12">
            <div class="md:col-span-2">
                <div class="text-2xl font-black mb-6">GOLDLOGIC.</div>
                <p class="text-gray-500 text-sm max-w-sm leading-relaxed">
                    A professional algorithm-based signal service. We prioritize trading probabilities over perfection.
                </p>
            </div>
            <div>
                <h5 class="text-xs font-black uppercase mb-6 text-amber-500">Resources</h5>
                <ul class="text-sm text-gray-400 space-y-4">
                    <li><a href="#" class="hover:text-white transition">Tutorials</a></li>
                    <li><a href="#" class="hover:text-white transition">Risk Disclaimer</a></li>
                </ul>
            </div>
            <div>
                <h5 class="text-xs font-black uppercase mb-6 text-amber-500">Contact</h5>
                <ul class="text-sm text-gray-400 space-y-4">
                    <li><a href="#" class="hover:text-white transition">Admin Support</a></li>
                    <li><a href="#" class="hover:text-white transition">Telegram Group</a></li>
                </ul>
            </div>
        </div>
    </footer>

</body>
</html>